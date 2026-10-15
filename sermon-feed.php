<?php
declare(strict_types=1);

/*
 * GoDaddy cPanel — server-side YouTube sermon feed.
 * Fetches the church's YouTube channel RSS feed directly (server-to-server,
 * so there's no CORS/browser dependency and no reliance on third-party
 * "CORS unblocker" proxies), parses it, and returns the newest videos as
 * JSON for sermon-feed.js to render.
 *
 * Response is cached to a local file for CACHE_TTL seconds so repeat page
 * loads don't re-fetch YouTube on every visitor — keeps this fast and stays
 * well clear of any rate limiting.
 */

header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
// Same-origin only; this endpoint is only meant to be called by this site's
// own front-end JS, not embedded elsewhere.
header('Cache-Control: public, max-age=300');

const CHANNEL_ID = 'UCNTdYwuEPUNamImHjL-fEgg';
const FEED_URL = 'https://www.youtube.com/feeds/videos.xml?channel_id=' . CHANNEL_ID;
const MAX_VIDEOS = 10;
const CACHE_TTL = 1800; // 30 minutes
const CACHE_FILE = __DIR__ . '/.sermon-feed-cache.json';

function send(int $status, array $payload): void {
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

function readCache(): ?array {
    if (!is_file(CACHE_FILE)) {
        return null;
    }
    $age = time() - (int) filemtime(CACHE_FILE);
    if ($age > CACHE_TTL) {
        return null;
    }
    $raw = @file_get_contents(CACHE_FILE);
    if ($raw === false) {
        return null;
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : null;
}

function writeCache(array $videos): void {
    // Best-effort only — if the directory isn't writable, we simply skip
    // caching rather than failing the request.
    @file_put_contents(CACHE_FILE, json_encode($videos));
}

function fetchFeedXml(): ?string {
    if (function_exists('curl_init')) {
        $ch = curl_init(FEED_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_USERAGENT => 'OrrvilleCMA-SermonFeed/1.0',
        ]);
        $body = curl_exec($ch);
        $ok = $body !== false && curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200;
        curl_close($ch);
        if ($ok) {
            return $body;
        }
    }
    // Fallback if curl isn't available on the host.
    $context = stream_context_create(['http' => ['timeout' => 8, 'header' => "User-Agent: OrrvilleCMA-SermonFeed/1.0\r\n"]]);
    $body = @file_get_contents(FEED_URL, false, $context);
    return $body !== false ? $body : null;
}

function parseFeed(string $xmlText): array {
    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($xmlText);
    if ($xml === false) {
        return [];
    }
    $xml->registerXPathNamespace('yt', 'http://www.youtube.com/xml/schemas/2015');
    $xml->registerXPathNamespace('atom', 'http://www.w3.org/2005/Atom');
    $entries = $xml->xpath('//atom:entry') ?: [];
    $videos = [];
    foreach ($entries as $entry) {
        $entry->registerXPathNamespace('yt', 'http://www.youtube.com/xml/schemas/2015');
        $idNodes = $entry->xpath('yt:videoId');
        $id = $idNodes ? (string) $idNodes[0] : '';
        if ($id === '') {
            continue;
        }
        $title = (string) $entry->title;
        $published = (string) $entry->published;
        $videos[] = [
            'id' => $id,
            'title' => $title !== '' ? $title : 'Sunday Sermon',
            'date' => $published !== '' ? $published : null,
        ];
        if (count($videos) >= MAX_VIDEOS) {
            break;
        }
    }
    return $videos;
}

$cached = readCache();
if ($cached !== null) {
    send(200, ['ok' => true, 'videos' => $cached, 'cached' => true]);
}

$xmlText = fetchFeedXml();
if ($xmlText === null) {
    send(502, ['ok' => false, 'videos' => [], 'message' => 'Could not reach YouTube.']);
}

$videos = parseFeed($xmlText);
if (empty($videos)) {
    send(502, ['ok' => false, 'videos' => [], 'message' => 'No videos found in feed.']);
}

writeCache($videos);
send(200, ['ok' => true, 'videos' => $videos, 'cached' => false]);
