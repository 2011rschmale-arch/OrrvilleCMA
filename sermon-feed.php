<?php
declare(strict_types=1);

/*
 * GoDaddy cPanel — server-side YouTube sermon feed (hardened).
 *
 * Fetches the church's YouTube channel RSS feed (server-to-server), validates
 * and trims it, and returns the newest videos as JSON for sermon-feed.js.
 * Cached for CACHE_TTL seconds so visitors never hit YouTube directly.
 *
 * SECURITY DESIGN
 *  - Takes NO visitor input. Only the constant FEED_URL is ever fetched,
 *    over HTTPS, host-restricted, redirects checked manually.
 *  - Size/time capped; XML with a DOCTYPE/ENTITY is rejected outright (no XXE,
 *    no entity-expansion attacks); LIBXML_NONET, no NOENT.
 *  - Every field is validated before it is returned: video IDs must be exactly
 *    11 URL-safe characters, titles are length-limited and stripped of control
 *    characters, dates are re-formatted.
 *  - Errors are logged, never displayed. Only GET/HEAD. Atomic 0600 cache file.
 */

@ini_set('display_errors', '0');
@ini_set('log_errors', '1');
error_reporting(E_ALL);
header_remove('X-Powered-By');
header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");
header('Cross-Origin-Resource-Policy: same-origin');
header('Referrer-Policy: no-referrer');

const CHANNEL_ID  = 'UCNTdYwuEPUNamImHjL-fEgg';
const FEED_HOST   = 'www.youtube.com';
const FEED_URL    = 'https://www.youtube.com/feeds/videos.xml?channel_id=' . CHANNEL_ID;
const MAX_VIDEOS  = 10;
const MAX_BYTES   = 1048576;     // 1 MB upstream cap
const CACHE_TTL   = 1800;        // 30 minutes
const CACHE_STALE = 604800;      // keep serving the last good copy for up to 7 days if YouTube is unreachable
const CACHE_FILE  = __DIR__ . '/.sermon-feed-cache.json';   // dotfile: blocked from the web by .htaccess

function send(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

/** @return array{videos:array, fetched:int}|null */
function readCache(): ?array
{
    if (!is_file(CACHE_FILE)) {
        return null;
    }
    $raw = @file_get_contents(CACHE_FILE, false, null, 0, 65536);
    $d = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($d) || !isset($d['videos'], $d['fetched']) || !is_array($d['videos']) || !is_int($d['fetched'])) {
        return null;
    }
    $clean = [];
    foreach ($d['videos'] as $v) {          // re-validate on the way out, in case the file was tampered with
        $c = is_array($v) ? cleanVideo((string) ($v['id'] ?? ''), (string) ($v['title'] ?? ''), isset($v['date']) ? (string) $v['date'] : '') : null;
        if ($c) {
            $clean[] = $c;
        }
    }
    return $clean ? ['videos' => $clean, 'fetched' => $d['fetched']] : null;
}

function writeCache(array $videos): void
{
    $tmp  = CACHE_FILE . '.' . bin2hex(random_bytes(6)) . '.tmp';
    $json = json_encode(['videos' => $videos, 'fetched' => time()], JSON_UNESCAPED_UNICODE);
    if ($json !== false && @file_put_contents($tmp, $json, LOCK_EX) !== false) {
        @chmod($tmp, 0600);
        if (!@rename($tmp, CACHE_FILE)) {
            @unlink($tmp);
        }
    }
}

function fetchFeedXml(): ?string
{
    if (!function_exists('curl_init')) {
        error_log('sermon-feed: cURL is not available');
        return null;
    }
    $url = FEED_URL;
    for ($hop = 0; $hop < 4; $hop++) {
        $p = parse_url($url);
        if (!is_array($p) || ($p['scheme'] ?? '') !== 'https' || strtolower($p['host'] ?? '') !== FEED_HOST
            || isset($p['user']) || isset($p['pass']) || (isset($p['port']) && (int) $p['port'] !== 443)) {
            error_log('sermon-feed: refused URL');
            return null;
        }
        $body = '';
        $location = null;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER  => false,
            CURLOPT_FOLLOWLOCATION  => false,
            CURLOPT_PROTOCOLS       => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER  => true,
            CURLOPT_SSL_VERIFYHOST  => 2,
            CURLOPT_CONNECTTIMEOUT  => 4,
            CURLOPT_TIMEOUT         => 8,
            CURLOPT_ENCODING        => '',
            CURLOPT_USERAGENT       => 'OrrvilleCMA-SermonFeed/2.0',
            CURLOPT_HEADERFUNCTION  => static function ($c, string $line) use (&$location): int {
                if (stripos($line, 'location:') === 0) {
                    $location = trim(substr($line, 9));
                }
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION   => static function ($c, string $chunk) use (&$body): int {
                if (strlen($body) + strlen($chunk) > MAX_BYTES) {
                    return -1;
                }
                $body .= $chunk;
                return strlen($chunk);
            },
        ]);
        $ok     = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($ok === false) {
            error_log('sermon-feed: fetch failed');
            return null;
        }
        if ($status >= 300 && $status < 400 && $location) {
            $url = (strpos($location, 'http') === 0) ? $location : 'https://' . FEED_HOST . '/' . ltrim($location, '/');
            continue;
        }
        return ($status === 200 && $body !== '') ? $body : null;
    }
    return null;
}

/** Validate one video's fields; returns null if the ID is not a genuine YouTube ID. */
function cleanVideo(string $id, string $title, string $date): ?array
{
    if (!preg_match('~^[A-Za-z0-9_-]{11}$~', $id)) {
        return null;
    }
    $title = preg_replace('~[\x00-\x1F\x7F]+~u', ' ', $title) ?? '';
    $title = trim(mb_substr($title, 0, 200));
    $ts = $date !== '' ? strtotime($date) : false;
    return [
        'id'    => $id,
        'title' => $title !== '' ? $title : 'Sunday Sermon',
        'date'  => $ts ? gmdate('c', $ts) : null,
    ];
}

function parseFeed(string $xmlText): array
{
    // Real YouTube feeds never contain a DOCTYPE or entity declarations.
    if (stripos($xmlText, '<!DOCTYPE') !== false || stripos($xmlText, '<!ENTITY') !== false) {
        error_log('sermon-feed: rejected feed containing DOCTYPE/ENTITY');
        return [];
    }
    $prev = libxml_use_internal_errors(true);
    $xml  = simplexml_load_string($xmlText, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    if ($xml === false) {
        return [];
    }
    $xml->registerXPathNamespace('atom', 'http://www.w3.org/2005/Atom');
    $videos = [];
    foreach ($xml->xpath('//atom:entry') ?: [] as $entry) {
        $entry->registerXPathNamespace('yt', 'http://www.youtube.com/xml/schemas/2015');
        $idNodes = $entry->xpath('yt:videoId');
        $v = cleanVideo($idNodes ? (string) $idNodes[0] : '', (string) $entry->title, (string) $entry->published);
        if ($v) {
            $videos[] = $v;
        }
        if (count($videos) >= MAX_VIDEOS) {
            break;
        }
    }
    return $videos;
}

/* ---- main ---- */
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'GET' && $method !== 'HEAD') {
    header('Allow: GET, HEAD');
    send(405, ['ok' => false, 'videos' => [], 'message' => 'Method not allowed.']);
}
header('Cache-Control: public, max-age=300');

$cache = readCache();
$age   = $cache ? time() - $cache['fetched'] : PHP_INT_MAX;
if ($cache && $age < CACHE_TTL) {
    send(200, ['ok' => true, 'videos' => $cache['videos'], 'cached' => true]);
}

$xmlText = fetchFeedXml();
$videos  = $xmlText !== null ? parseFeed($xmlText) : [];
if ($videos) {
    writeCache($videos);
    send(200, ['ok' => true, 'videos' => $videos, 'cached' => false]);
}
if ($cache && $age < CACHE_STALE) {
    send(200, ['ok' => true, 'videos' => $cache['videos'], 'cached' => true]);
}
header('Cache-Control: no-store');
send(502, ['ok' => false, 'videos' => [], 'message' => 'Could not load sermons right now.']);
