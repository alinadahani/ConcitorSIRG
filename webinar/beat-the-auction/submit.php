<?php
/*
 * Form handler for /webinar/beat-the-auction/ (runs on Hostinger PHP hosting).
 * Handles two forms:
 *   type=recording  – gate form to watch the recording
 *   type=session    – request for a 30-minute strategy session
 * Each submission is (1) emailed to NOTIFY_TO and (2) appended to a CSV in ./data/
 * (the data folder is blocked from public access by data/.htaccess).
 */

// ===== SETTINGS =====
const NOTIFY_TO   = 'ckwon@concitor.com';        // who receives each submission
const NOTIFY_FROM = 'no-reply@concitor.org';    // must be an address on a domain hosted at Hostinger
const DATA_DIR    = __DIR__ . '/data';
// Recording link: only sent to the browser after a valid recording sign-up,
// so it never appears in the page source.
const RECORDING_URL = 'https://us06web.zoom.us/rec/share/YpBkITNnfGwLKpTQWphkz6M1eBJvzOCOfHgtsjnaVTOrztOEV5qreFm4hrL_tPLP.ciqhg5eJMBngyi5J?startTime=1794936617000';
// ====================

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

function respond(int $code, array $body): void {
    http_response_code($code);
    echo json_encode($body);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    respond(405, ['ok' => false, 'error' => 'Method not allowed']);
}

// Accept JSON or regular form posts
$raw = file_get_contents('php://input');
$in = json_decode($raw ?: '', true);
if (!is_array($in)) { $in = $_POST; }

// Honeypot: real visitors never fill this hidden field
if (!empty($in['website'])) { respond(200, ['ok' => true]); }

function clean($v, int $max = 200): string {
    $v = is_string($v) ? $v : '';
    $v = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $v);   // strip control chars / newlines
    return mb_substr(trim($v), 0, $max);
}

$type = clean($in['type'] ?? 'recording', 20);
$forms = [
    'recording' => ['first_name' => 'First name', 'last_name' => 'Last name', 'email' => 'Work email', 'firm' => 'Firm', 'title' => 'Title'],
    'session'   => ['name' => 'Name', 'email' => 'Work email', 'firm' => 'Firm', 'times' => 'Preferred times'],
];
if (!isset($forms[$type])) { respond(400, ['ok' => false, 'error' => 'Unknown form']); }

$fields = $forms[$type];
$data = [];
foreach ($fields as $key => $label) {
    $data[$key] = clean($in[$key] ?? '', $key === 'times' ? 500 : 200);
    if ($data[$key] === '') { respond(422, ['ok' => false, 'error' => "Missing $label"]); }
}
if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
    respond(422, ['ok' => false, 'error' => 'Invalid email']);
}
$consent = !empty($in['consent']) && $in['consent'] !== 'false' ? 'yes' : 'no';
$when = gmdate('Y-m-d H:i:s') . ' UTC';

// ---- 1. Save to CSV ----
$saved = false;
if (is_dir(DATA_DIR) || @mkdir(DATA_DIR, 0750, true)) {
    $file = DATA_DIR . "/$type-submissions.csv";
    $isNew = !file_exists($file);
    $fh = @fopen($file, 'a');
    if ($fh) {
        flock($fh, LOCK_EX);
        $safe = fn($v) => preg_match('/^[=+\-@\t]/', $v) ? "'" . $v : $v;  // block spreadsheet formula injection
        if ($isNew) { fputcsv($fh, array_merge(['Submitted'], array_values($fields), $type === 'recording' ? ['Consent'] : [])); }
        $row = array_merge([$when], array_map($safe, array_values($data)), $type === 'recording' ? [$consent] : []);
        $saved = fputcsv($fh, $row) !== false;
        flock($fh, LOCK_UN);
        fclose($fh);
    }
}

// ---- 2. Email notification ----
$subject = $type === 'recording'
    ? 'Beat the Auction — recording sign-up: ' . $data['firm']
    : 'Beat the Auction — strategy session request: ' . $data['firm'];
$lines = [];
foreach ($fields as $key => $label) { $lines[] = str_pad($label . ':', 18) . $data[$key]; }
if ($type === 'recording') { $lines[] = str_pad('Consent:', 18) . $consent; }
$lines[] = str_pad('Submitted:', 18) . $when;
$lines[] = '';
$lines[] = 'Source: concitor.org/webinar/beat-the-auction/';

$headers = [
    'From: Concitor Webinar <' . NOTIFY_FROM . '>',
    'Reply-To: ' . $data['email'],
    'Content-Type: text/plain; charset=UTF-8',
];
$mailed = @mail(NOTIFY_TO, '=?UTF-8?B?' . base64_encode($subject) . '?=', implode("\n", $lines), implode("\r\n", $headers));

if (!$saved && !$mailed) {
    respond(500, ['ok' => false, 'error' => 'Could not record submission']);
}
$out = ['ok' => true];
if ($type === 'recording' && RECORDING_URL !== '') { $out['recording_url'] = RECORDING_URL; }
respond(200, $out);
