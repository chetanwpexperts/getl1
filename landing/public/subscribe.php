<?php
/**
 * GetL1 waitlist endpoint for the coming-soon page.
 * Stores signups in ../data/waitlist.csv (outside the web root).
 * Later: import into the Laravel app with `php artisan getl1:import-waitlist` (to be built).
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

function reply(int $code, array $body): never
{
    http_response_code($code);
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    reply(405, ['ok' => false, 'error' => 'Method not allowed.']);
}

// Same-origin only
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowed = ['https://getl1.com', 'https://www.getl1.com', 'http://localhost:8000'];
if ($origin !== '' && !in_array($origin, $allowed, true)) {
    reply(403, ['ok' => false, 'error' => 'Forbidden.']);
}

$raw = file_get_contents('php://input', false, null, 0, 4096) ?: '';
$in = json_decode($raw, true);
if (!is_array($in)) {
    reply(400, ['ok' => false, 'error' => 'Invalid request.']);
}

// Honeypot: bots fill hidden fields. Pretend success.
if (!empty($in['website'])) {
    reply(200, ['ok' => true]);
}

$clean = static fn ($v, int $max) => mb_substr(trim(preg_replace('/[\x00-\x1F\x7F]/u', '', (string) ($v ?? ''))), 0, $max);

$email = strtolower($clean($in['email'] ?? '', 190));
$role = in_array($in['role'] ?? '', ['buyer', 'supplier'], true) ? $in['role'] : 'buyer';
$company = $clean($in['company'] ?? '', 150);
$city = $clean($in['city'] ?? '', 100);
$source = $clean($in['source'] ?? '', 200);

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    reply(422, ['ok' => false, 'error' => 'Please enter a valid email.']);
}

$dataDir = getenv('GETL1_DATA_DIR') ?: dirname(__DIR__) . '/data';
if (!is_dir($dataDir) && !mkdir($dataDir, 0750, true) && !is_dir($dataDir)) {
    reply(500, ['ok' => false, 'error' => 'Server error. Please email hello@getl1.com.']);
}

// Rate limit: 5 signups per IP per hour
$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$rlFile = $dataDir . '/ratelimit.json';
$rl = is_file($rlFile) ? (json_decode((string) file_get_contents($rlFile), true) ?: []) : [];
$now = time();
$rl = array_filter($rl, static fn ($t) => $t > $now - 3600); // keep last hour, keyed "ip|ts"
$hits = count(array_filter(array_keys($rl), static fn ($k) => str_starts_with($k, $ip . '|')));
if ($hits >= 5) {
    reply(429, ['ok' => false, 'error' => 'Too many attempts. Please try again later.']);
}
$rl[$ip . '|' . $now . '|' . mt_rand()] = $now;
file_put_contents($rlFile, json_encode($rl), LOCK_EX);

// Append (dedupe by email)
$file = $dataDir . '/waitlist.csv';
$fh = fopen($file, 'c+');
if (!$fh || !flock($fh, LOCK_EX)) {
    reply(500, ['ok' => false, 'error' => 'Server error. Please email hello@getl1.com.']);
}

$exists = false;
$isNew = fstat($fh)['size'] === 0;
if (!$isNew) {
    rewind($fh);
    while (($row = fgetcsv($fh, 0, ',', '"', '\\')) !== false) {
        if (isset($row[1]) && strtolower($row[1]) === $email) {
            $exists = true;
            break;
        }
    }
}

if (!$exists) {
    fseek($fh, 0, SEEK_END);
    if ($isNew) {
        fputcsv($fh, ['created_at', 'email', 'role', 'company', 'city', 'source', 'ip'], ',', '"', '\\');
    }
    // Prefix formula-like values so the CSV is safe to open in Excel.
    $safe = static fn (string $v) => preg_match('/^[=+\-@]/', $v) ? "'" . $v : $v;
    fputcsv($fh, [gmdate('c'), $email, $role, $safe($company), $safe($city), $safe($source), $ip], ',', '"', '\\');
}

flock($fh, LOCK_UN);
fclose($fh);

reply(200, ['ok' => true, 'duplicate' => $exists]);
