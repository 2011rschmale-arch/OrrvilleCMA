<?php
declare(strict_types=1);

/*
 * Orrville C&MA — server-side weekly bulletin.
 *
 * Fetches the church's *published* Google Doc (server-to-server), converts it
 * into a small, strictly whitelisted fragment of HTML, caches it, and returns
 * it as JSON for bulletin-loader.js to place on bulletin.html. The result is
 * real page text instead of an iframe, so it fills the full width of a phone.
 *
 * SECURITY DESIGN
 *  - Takes NO input from the visitor (no query string, no POST). The only URL
 *    ever fetched is the constant DOC_URL below, over HTTPS, host-restricted.
 *  - Redirects are followed manually and each hop must stay on docs.google.com.
 *  - Response size, time and node counts are capped.
 *  - Output is REBUILT from a whitelist of tags/attributes; nothing from the
 *    document is copied through as markup. Text is always escaped, link
 *    schemes are limited to http/https/mailto/tel, images to Google hosts.
 *  - No XML/DTD entity expansion (LIBXML_NONET, no NOENT, DOCTYPE ignored).
 *  - Errors are logged, never displayed. Only GET/HEAD are served.
 *  - Cache file is written atomically with 0600 permissions.
 */

const DOC_URL        = 'https://docs.google.com/document/d/e/2PACX-1vSprM6yNbkz77HDUZJaTKzEDI9eztbjU7ShVpOdT6EKfPcmZLnTeIazF_4HoTwp7TZpZ_7pFMtfnfPm/pub?embedded=true';
const ALLOWED_HOST   = 'docs.google.com';
const CACHE_TTL      = 300;        // 5 minutes: matches how often Google refreshes the published copy
const CACHE_MAX_AGE  = 604800;     // serve a stale copy for up to 7 days if Google is unreachable
const MAX_BYTES      = 2097152;    // 2 MB upstream cap
const MAX_NODES      = 20000;      // DOM nodes processed
const MAX_DEPTH      = 40;
const MAX_OUTPUT     = 400000;     // bytes of sanitized HTML kept

/* ------------------------------------------------------------------ */
/* Fetch                                                               */
/* ------------------------------------------------------------------ */

function bf_fetch(string $url): ?string
{
    if (!function_exists('curl_init')) {
        error_log('bulletin-feed: cURL is not available');
        return null;
    }
    for ($hop = 0; $hop < 4; $hop++) {
        $parts = parse_url($url);
        if (!is_array($parts)
            || ($parts['scheme'] ?? '') !== 'https'
            || strtolower($parts['host'] ?? '') !== ALLOWED_HOST
            || isset($parts['user']) || isset($parts['pass'])
            || (isset($parts['port']) && (int) $parts['port'] !== 443)) {
            error_log('bulletin-feed: refused URL');
            return null;
        }

        $body = '';
        $location = null;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,           // redirects handled (and checked) manually
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_ENCODING       => '',
            CURLOPT_USERAGENT      => 'OrrvilleCMA-BulletinFeed/2.0',
            CURLOPT_HTTPHEADER     => ['Accept: text/html'],
            CURLOPT_HEADERFUNCTION => static function ($c, string $line) use (&$location): int {
                if (stripos($line, 'location:') === 0) {
                    $location = trim(substr($line, 9));
                }
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION  => static function ($c, string $chunk) use (&$body): int {
                if (strlen($body) + strlen($chunk) > MAX_BYTES) {
                    return -1;                          // abort: too large
                }
                $body .= $chunk;
                return strlen($chunk);
            },
        ]);
        $ok     = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($ok === false) {
            error_log('bulletin-feed: fetch failed');
            return null;
        }
        if ($status >= 300 && $status < 400 && $location) {
            // Resolve relative redirects against the current URL.
            $url = (strpos($location, 'http') === 0) ? $location : 'https://' . ALLOWED_HOST . '/' . ltrim($location, '/');
            continue;
        }
        if ($status !== 200 || $body === '') {
            error_log('bulletin-feed: upstream status ' . $status);
            return null;
        }
        return $body;
    }
    return null;
}

/* ------------------------------------------------------------------ */
/* Style extraction (Google puts all formatting in class rules)        */
/* ------------------------------------------------------------------ */

/** @return array<string, array{order:int, decl:array<string,string>}> */
function bf_parse_css(string $css): array
{
    $css = preg_replace('~/\*.*?\*/~s', '', $css) ?? '';
    $css = preg_replace('~@import[^;]*;~i', '', $css) ?? '';
    $rules = [];
    $order = 0;
    if (preg_match_all('~\.([A-Za-z0-9_-]{1,60})\s*\{([^{}]*)\}~', $css, $m, PREG_SET_ORDER)) {
        foreach ($m as $r) {
            $decl = [];
            foreach (explode(';', $r[2]) as $d) {
                $p = strpos($d, ':');
                if ($p === false) {
                    continue;
                }
                $decl[strtolower(trim(substr($d, 0, $p)))] = strtolower(trim(substr($d, $p + 1)));
            }
            $name = $r[1];
            $rules[$name] = ['order' => $order++, 'decl' => array_merge($rules[$name]['decl'] ?? [], $decl)];
        }
    }
    return $rules;
}

/** Merge declarations of all classes on an element, in stylesheet order. */
function bf_style_for(string $classAttr, array $rules): array
{
    $hits = [];
    foreach (preg_split('~\s+~', trim($classAttr)) ?: [] as $c) {
        if ($c !== '' && isset($rules[$c])) {
            $hits[] = $rules[$c];
        }
    }
    usort($hits, static fn ($a, $b) => $a['order'] <=> $b['order']);
    $out = [];
    foreach ($hits as $h) {
        $out = array_merge($out, $h['decl']);
    }
    return $out;
}

/* ------------------------------------------------------------------ */
/* Sanitizing renderer                                                 */
/* ------------------------------------------------------------------ */

final class BF_Renderer
{
    private int $nodes = 0;
    private bool $inLink = false;
    private array $rules;

    private const DROP = ['script','style','iframe','object','embed','form','input','button','select','textarea',
                          'link','meta','svg','math','template','noscript','head','title','base','audio','video',
                          'canvas','applet','frame','frameset','dialog'];

    public function __construct(array $rules)
    {
        $this->rules = $rules;
    }

    private static function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function safeUrl(string $href): ?string
    {
        $href = trim(preg_replace('~[\x00-\x20\x7F]+~', '', $href) ?? '');
        if ($href === '' || strlen($href) > 2000) {
            return null;
        }
        // Google wraps outbound links: https://www.google.com/url?q=REAL&sa=...
        $p = parse_url($href);
        if (is_array($p) && isset($p['host'], $p['path'], $p['query'])
            && strtolower($p['host']) === 'www.google.com' && $p['path'] === '/url') {
            parse_str($p['query'], $q);
            if (isset($q['q']) && is_string($q['q'])) {
                $href = trim(preg_replace('~[\x00-\x20\x7F]+~', '', $q['q']) ?? '');
            }
        }
        if (!preg_match('~^(https?://|mailto:|tel:)~i', $href)) {
            return null;                                   // blocks javascript:, data:, vbscript:, relative & #anchors
        }
        return $href;
    }

    private static function safeImage(string $src): ?string
    {
        $src = trim($src);
        $p = parse_url($src);
        if (!is_array($p) || ($p['scheme'] ?? '') !== 'https' || empty($p['host']) || strlen($src) > 2000) {
            return null;
        }
        $h = strtolower($p['host']);
        if (preg_match('~(^|\.)(googleusercontent\.com|ggpht\.com)$~', $h) || $h === 'docs.google.com') {
            return $src;
        }
        return null;
    }

    private static function textOnly(string $html): string
    {
        return trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'), " \t\n\r\0\x0B\xC2\xA0");
    }

    private function children(DOMNode $n, int $depth): string
    {
        $out = '';
        foreach ($n->childNodes as $c) {
            $out .= $this->node($c, $depth + 1);
        }
        return $out;
    }

    private function node(DOMNode $n, int $depth): string
    {
        if (++$this->nodes > MAX_NODES || $depth > MAX_DEPTH) {
            return '';
        }
        if ($n instanceof DOMText) {
            return self::esc($n->nodeValue ?? '');
        }
        if (!($n instanceof DOMElement)) {
            return '';                                      // comments, PIs, CDATA etc. are discarded
        }

        $tag   = strtolower($n->tagName);
        $id    = $n->getAttribute('id');
        if (in_array($tag, self::DROP, true) || $id === 'header' || $id === 'footer') {
            return '';
        }
        $style = bf_style_for($n->getAttribute('class'), $this->rules);

        switch ($tag) {
            case 'br':
                return '<br>';

            case 'p':
            case 'h1': case 'h2': case 'h3': case 'h4': case 'h5': case 'h6':
                $inner = $this->children($n, $depth);
                $isHeading = $tag[0] === 'h';
                $out = $isHeading ? ['h1'=>'h3','h2'=>'h3','h3'=>'h4','h4'=>'h5','h5'=>'h5','h6'=>'h5'][$tag] : 'p';
                $hasImg = strpos($inner, '<img ') !== false;
                if (self::textOnly($inner) === '' && !$hasImg) {
                    return $isHeading ? '' : '<p class="bl-gap"></p>';
                }
                $cls = [];
                $al = $style['text-align'] ?? '';
                if (in_array($al, ['center', 'right', 'justify'], true)) {
                    $cls[] = 'bl-' . $al;
                }
                $attr = $cls ? ' class="' . implode(' ', $cls) . '"' : '';
                return "<$out$attr>$inner</$out>";

            case 'span':
            case 'strong': case 'b': case 'em': case 'i': case 'u': case 'sup': case 'sub': case 's': case 'font': case 'small': case 'mark': case 'code':
                $inner = $this->children($n, $depth);
                if (self::textOnly($inner) === '' && strpos($inner, '<img ') === false && strpos($inner, '<br>') === false) {
                    return $inner;
                }
                $css = [];
                if (!$this->inLink && !empty($style['color']) && preg_match('~^#([0-9a-f]{6}|[0-9a-f]{3})$~', $style['color'])
                    && !in_array($style['color'], ['#000000', '#000', '#ffffff', '#fff'], true)) {
                    $css[] = 'color:' . $style['color'];
                }
                if (!empty($style['font-size']) && preg_match('~^(\d+(?:\.\d+)?)pt$~', $style['font-size'], $m)) {
                    $em = round(max(0.7, min(2.5, (float) $m[1] / 11)), 2);
                    if (abs($em - 1.0) > 0.04) {
                        $css[] = 'font-size:' . $em . 'em';
                    }
                }
                if ($css) {
                    $inner = '<span style="' . implode(';', $css) . '">' . $inner . '</span>';
                }
                $fw = $style['font-weight'] ?? '';
                if (in_array($tag, ['strong', 'b'], true) || $fw === 'bold' || (ctype_digit($fw) && (int) $fw >= 600)) {
                    $inner = "<strong>$inner</strong>";
                }
                if (in_array($tag, ['em', 'i'], true) || ($style['font-style'] ?? '') === 'italic') {
                    $inner = "<em>$inner</em>";
                }
                if ($tag === 'u' || (!$this->inLink && ($style['text-decoration'] ?? '') === 'underline')) {
                    $inner = "<u>$inner</u>";
                }
                $va = $style['vertical-align'] ?? '';
                if ($tag === 'sup' || $va === 'super') {
                    $inner = "<sup>$inner</sup>";
                } elseif ($tag === 'sub' || $va === 'sub') {
                    $inner = "<sub>$inner</sub>";
                }
                return $inner;

            case 'a':
                $was = $this->inLink;
                $this->inLink = true;                       // links are styled by the site CSS, not Google's colours
                $inner = $this->children($n, $depth);
                $this->inLink = $was;
                $href  = self::safeUrl($n->getAttribute('href'));
                if ($href === null || self::textOnly($inner) === '') {
                    return $inner;
                }
                // Strip the underline/colour <u>/<span> wrappers Google adds inside links: CSS styles links already.
                return '<a href="' . self::esc($href) . '" target="_blank" rel="noopener noreferrer">' . $inner . '</a>';

            case 'ul':
            case 'ol':
                $lvl = 0;
                if (preg_match('~lst-kix_[A-Za-z0-9_]+-(\d)\b~', $n->getAttribute('class'), $m)) {
                    $lvl = min(8, (int) $m[1]);
                }
                $attr = ' class="bl-l' . $lvl . '"';
                if ($tag === 'ol') {
                    $st = $n->getAttribute('start');
                    if ($st !== '' && ctype_digit($st) && (int) $st >= 1 && (int) $st <= 9999) {
                        $attr .= ' start="' . (int) $st . '"';
                    }
                }
                $items = '';
                foreach ($n->childNodes as $c) {
                    if ($c instanceof DOMElement && strtolower($c->tagName) === 'li') {
                        $items .= $this->node($c, $depth + 1);
                    }
                }
                return $items === '' ? '' : "<$tag$attr>$items</$tag>";

            case 'li':
                $inner = $this->children($n, $depth);
                // <p> inside <li> is Google's habit; unwrap to keep list spacing tight.
                $inner = preg_replace('~</?p(?: class="[^"]*")?>~', '', $inner) ?? $inner;
                return self::textOnly($inner) === '' ? '' : "<li>$inner</li>";

            case 'table':
                $inner = '';
                foreach ($n->getElementsByTagName('tr') as $tr) {
                    $row = '';
                    foreach ($tr->childNodes as $cell) {
                        if (!($cell instanceof DOMElement)) {
                            continue;
                        }
                        $ct = strtolower($cell->tagName);
                        if ($ct !== 'td' && $ct !== 'th') {
                            continue;
                        }
                        $a = '';
                        foreach (['colspan', 'rowspan'] as $sp) {
                            $v = $cell->getAttribute($sp);
                            if ($v !== '' && ctype_digit($v) && (int) $v >= 1 && (int) $v <= 20) {
                                $a .= " $sp=\"" . (int) $v . '"';
                            }
                        }
                        $row .= "<$ct$a>" . $this->children($cell, $depth + 2) . "</$ct>";
                    }
                    if ($row !== '') {
                        $inner .= "<tr>$row</tr>";
                    }
                }
                return $inner === '' ? '' : '<div class="bl-table-wrap"><table><tbody>' . $inner . '</tbody></table></div>';

            case 'img':
                $src = self::safeImage($n->getAttribute('src'));
                if ($src === null) {
                    return '';
                }
                $alt = mb_substr($n->getAttribute('alt'), 0, 200);
                return '<img src="' . self::esc($src) . '" alt="' . self::esc($alt) . '" loading="lazy" decoding="async">';

            case 'hr':
                return '';

            default:
                // div, section, body, unknown wrappers: keep the text/children, discard the tag.
                return $this->children($n, $depth);
        }
    }

    public function render(DOMNode $root): string
    {
        $html = $this->children($root, 0);
        // Collapse runs of empty spacer paragraphs and trim leading/trailing ones.
        $html = preg_replace('~(?:<p class="bl-gap"></p>\s*){2,}~', '<p class="bl-gap"></p>', $html) ?? $html;
        $html = preg_replace('~^(?:\s*<p class="bl-gap"></p>)+|(?:<p class="bl-gap"></p>\s*)+$~', '', $html) ?? $html;
        return strlen($html) > MAX_OUTPUT ? substr($html, 0, 0) : $html;
    }
}

/** Convert a published-Google-Doc HTML page into safe bulletin HTML, or null. */
function bf_convert(string $raw): ?string
{
    if ($raw === '' || strlen($raw) > MAX_BYTES) {
        return null;
    }
    // Style rules come from every <style> block in the original page.
    $css = '';
    if (preg_match_all('~<style\b[^>]*>(.*?)</style>~is', $raw, $sm)) {
        $css = implode("\n", $sm[1]);
    }
    $rules = bf_parse_css($css);

    $prev = libxml_use_internal_errors(true);
    $doc  = new DOMDocument();
    $loaded = $doc->loadHTML('<?xml encoding="UTF-8">' . $raw, LIBXML_NONET | LIBXML_COMPACT | LIBXML_NOWARNING | LIBXML_NOERROR);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    if (!$loaded) {
        return null;
    }

    $xp = new DOMXPath($doc);
    $root = $xp->query('//*[@id="contents"]')->item(0) ?? $xp->query('//body')->item(0);
    if (!$root) {
        return null;
    }
    $html = (new BF_Renderer($rules))->render($root);
    return strlen(trim(strip_tags($html))) >= 10 ? $html : null;
}

/* ------------------------------------------------------------------ */
/* Cache                                                               */
/* ------------------------------------------------------------------ */

function bf_cache_path(): string
{
    $tmp = sys_get_temp_dir();
    if ($tmp && is_dir($tmp) && is_writable($tmp)) {
        return rtrim($tmp, '/\\') . '/orrvillecma-bulletin-' . substr(hash('sha256', __DIR__), 0, 16) . '.json';
    }
    return __DIR__ . '/.bulletin-cache.json';      // dotfile: blocked from the web by .htaccess
}

/** @return array{html:string, fetched:int}|null */
function bf_cache_read(string $path): ?array
{
    if (!is_file($path)) {
        return null;
    }
    $raw = @file_get_contents($path, false, null, 0, MAX_OUTPUT + 1024);
    $d = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($d) || !isset($d['html'], $d['fetched']) || !is_string($d['html']) || !is_int($d['fetched'])) {
        return null;
    }
    return ['html' => $d['html'], 'fetched' => $d['fetched']];
}

function bf_cache_write(string $path, string $html): void
{
    $tmp = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
    $json = json_encode(['html' => $html, 'fetched' => time()], JSON_UNESCAPED_UNICODE);
    if ($json !== false && @file_put_contents($tmp, $json, LOCK_EX) !== false) {
        @chmod($tmp, 0600);
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
        }
    }
}

/* ------------------------------------------------------------------ */
/* Response                                                            */
/* ------------------------------------------------------------------ */

function bf_send(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function bf_main(): void
{
    @ini_set('display_errors', '0');
    @ini_set('log_errors', '1');
    error_reporting(E_ALL);
    header_remove('X-Powered-By');
    header('Content-Type: application/json; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");
    header('Cross-Origin-Resource-Policy: same-origin');
    header('Referrer-Policy: no-referrer');

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($method !== 'GET' && $method !== 'HEAD') {
        header('Allow: GET, HEAD');
        bf_send(405, ['ok' => false, 'message' => 'Method not allowed.']);
    }
    header('Cache-Control: public, max-age=60');

    $path  = bf_cache_path();
    $cache = bf_cache_read($path);
    $age   = $cache ? time() - $cache['fetched'] : PHP_INT_MAX;

    if ($cache && $age < CACHE_TTL) {
        bf_send(200, ['ok' => true, 'html' => $cache['html'], 'updated' => gmdate('c', $cache['fetched']), 'stale' => false]);
    }

    // Only one visitor at a time refreshes the cache; everyone else gets the existing copy.
    $lock = @fopen($path . '.lock', 'c');
    if ($lock) { @chmod($path . '.lock', 0600); }
    $have = $lock && flock($lock, LOCK_EX | LOCK_NB);
    if (!$have && $cache) {
        bf_send(200, ['ok' => true, 'html' => $cache['html'], 'updated' => gmdate('c', $cache['fetched']), 'stale' => true]);
    }

    $raw  = bf_fetch(DOC_URL);
    $html = $raw !== null ? bf_convert($raw) : null;
    if ($html !== null) {
        bf_cache_write($path, $html);
        if ($lock) { flock($lock, LOCK_UN); fclose($lock); }
        bf_send(200, ['ok' => true, 'html' => $html, 'updated' => gmdate('c'), 'stale' => false]);
    }
    if ($lock) { flock($lock, LOCK_UN); fclose($lock); }

    if ($cache && $age < CACHE_MAX_AGE) {            // Google unreachable or doc unreadable: keep showing the last good copy
        bf_send(200, ['ok' => true, 'html' => $cache['html'], 'updated' => gmdate('c', $cache['fetched']), 'stale' => true]);
    }
    header('Cache-Control: no-store');
    bf_send(502, ['ok' => false, 'message' => 'The bulletin is temporarily unavailable.']);
}

if (!defined('BULLETIN_FEED_TEST')) {
    bf_main();
}
