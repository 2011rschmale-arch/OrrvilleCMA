<?php
declare(strict_types=1);

/*
 * Orrville C&MA — contact form handler (receives the form on visit.html).
 *
 * Accepts a POST from the site's own contact form, validates it, and emails it
 * to the church office. Replies are JSON in the shape visit.html expects:
 *   { "ok": true|false, "message": "..." }
 *
 * SECURITY DESIGN
 *  - POST only; same-origin only (Origin header must match this site).
 *  - Hidden honeypot field + minimum/maximum form age to turn away bots.
 *  - Per-visitor rate limit (3 messages / 10 minutes) kept in the temp folder.
 *  - Every field is trimmed, control characters removed and length-capped;
 *    the topic must be one of the known options; the email must validate.
 *    Nothing from the visitor is ever placed in a header except the validated
 *    email (Reply-To) and a sanitized single-line subject, so header
 *    injection is not possible.
 *  - Message text is never logged. Errors are logged, never displayed.
 *
 * Needs PHP 7.2+ and PHP's mail() (standard on GoDaddy shared hosting).
 * Set TO_ADDRESS below if the office email ever changes.
 */

const TO_ADDRESS   = 'orrcma@zoominternet.net';
const OFFICE_PHONE = '(330) 682-7666';
const MIN_SECONDS  = 3;       // a human cannot fill the form faster than this
const MAX_AGE      = 7200;    // form page must have been loaded within 2 hours
const RATE_WINDOW  = 600;     // seconds
const RATE_MAX     = 3;       // messages per visitor per window

const SUBJECTS = [
    'General Information', 'Planning a Visit', "Children's Ministry", 'Youth Ministry',
    'ESL Classes', 'Missions', 'Prayer Request', 'Other',
];

function cf_respond(int $status, bool $ok, string $message): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode(['ok' => $ok, 'message' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

function cf_clean(?string $value, int $max, bool $multiline = false): string
{
    $value = (string) $value;
    if (function_exists('mb_check_encoding') && !mb_check_encoding($value, 'UTF-8')) {
        $value = '';
    }
    $pattern = $multiline ? '/[^\P{C}\n]+/u' : '/\p{C}+/u';   // strip control characters
    $value = (string) preg_replace($pattern, ' ', str_replace("\r\n", "\n", $value));
    $value = trim($value);
    return function_exists('mb_substr') ? mb_substr($value, 0, $max, 'UTF-8') : substr($value, 0, $max);
}

function cf_rate_limited(): bool
{
    $ip   = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $file = rtrim(sys_get_temp_dir(), '/\\') . '/cma_contact_' . hash('sha256', $ip . '|cma') . '.json';
    $now  = time();
    $hits = [];
    $fh   = @fopen($file, 'c+');
    if ($fh === false) { return false; }                      // fail open; other defenses still apply
    if (flock($fh, LOCK_EX)) {
        $raw = stream_get_contents($fh);
        $old = is_string($raw) ? json_decode($raw, true) : [];
        if (is_array($old)) {
            foreach ($old as $t) { if (is_int($t) && $t > $now - RATE_WINDOW) { $hits[] = $t; } }
        }
        $limited = count($hits) >= RATE_MAX;
        if (!$limited) { $hits[] = $now; }
        ftruncate($fh, 0); rewind($fh); fwrite($fh, json_encode($hits)); fflush($fh);
        flock($fh, LOCK_UN);
        fclose($fh);
        @chmod($file, 0600);
        return $limited;
    }
    fclose($fh);
    return false;
}

/* ---------------------------------------------------------------- request checks */

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    cf_respond(405, false, 'Please use the contact form on our website.');
}

$origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
if ($origin !== '') {
    $originHost = (string) parse_url($origin, PHP_URL_HOST);
    $thisHost   = strtolower((string) preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? '')));
    if (strtolower($originHost) !== $thisHost) {
        cf_respond(403, false, 'This request could not be accepted.');
    }
}

// Honeypot: real visitors never see or fill this field. Pretend success so bots move on.
if (cf_clean($_POST['website'] ?? '', 200) !== '') {
    cf_respond(200, true, 'Thank you.');
}

$loadedMs = $_POST['form_loaded_at'] ?? '';
if (!is_string($loadedMs) || !ctype_digit($loadedMs)) {
    cf_respond(400, false, 'Please reload the page and try again.');
}
$age = time() - (int) floor(((float) $loadedMs) / 1000);
if ($age < MIN_SECONDS) {
    cf_respond(400, false, 'Please take a moment and try sending again.');
}
if ($age > MAX_AGE) {
    cf_respond(400, false, 'This form has expired. Please reload the page and try again.');
}

/* ---------------------------------------------------------------- fields */

$first   = cf_clean($_POST['first_name'] ?? '', 80);
$last    = cf_clean($_POST['last_name'] ?? '', 80);
$email   = cf_clean($_POST['email'] ?? '', 254);
$phone   = preg_replace('/[^0-9+().\-\sx]/i', '', cf_clean($_POST['phone'] ?? '', 40));
$subject = cf_clean($_POST['subject'] ?? '', 80);
$message = cf_clean($_POST['message'] ?? '', 5000, true);

if ($first === '' || $last === '' || $email === '' || $message === '') {
    cf_respond(400, false, 'Please fill in your name, email address, and message.');
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    cf_respond(400, false, 'Please enter a valid email address.');
}
if (!in_array($subject, SUBJECTS, true)) {
    $subject = 'Other';
}

if (cf_rate_limited()) {
    cf_respond(429, false, 'You have sent several messages recently. Please wait a few minutes, or call the office at ' . OFFICE_PHONE . '.');
}

/* ---------------------------------------------------------------- send */

$host = strtolower((string) preg_replace('/^www\./i', '', (string) ($_SERVER['SERVER_NAME'] ?? '')));
if (!preg_match('/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?\.[a-z]{2,}$/', $host)) {
    $host = 'orrvillecma.org';
}
$from = 'noreply@' . $host;

$name    = $first . ' ' . $last;
$subjectLine = 'Website message: ' . $subject . ' - ' . $name;
$subjectLine = preg_replace('/\s+/', ' ', $subjectLine);
$encodedSubject = '=?UTF-8?B?' . base64_encode((string) $subjectLine) . '?=';

$body  = "A message was sent through the Orrville C&MA website contact form.\n\n";
$body .= "Name:    {$name}\n";
$body .= "Email:   {$email}\n";
$body .= 'Phone:   ' . ($phone !== '' ? $phone : '(not given)') . "\n";
$body .= "Topic:   {$subject}\n\n";
$body .= "Message:\n{$message}\n";

$headers = implode("\r\n", [
    'From: Orrville C&MA Website <' . $from . '>',
    'Reply-To: ' . $email,
    'MIME-Version: 1.0',
    'Content-Type: text/plain; charset=UTF-8',
    'Content-Transfer-Encoding: 8bit',
    'X-Mailer: orrvillecma-contact-form',
]);

if (!@mail(TO_ADDRESS, $encodedSubject, $body, $headers)) {
    error_log('contact.php: mail() failed');
    cf_respond(500, false, 'We could not send your message right now. Please call the office at ' . OFFICE_PHONE . '.');
}

cf_respond(200, true, 'Thank you! Your message has been sent.');
