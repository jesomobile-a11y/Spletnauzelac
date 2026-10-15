<?php
// Samodejno nalaganje: GitHub webhook -> git pull -> kopiranje v public_html.
// Skrivnost je v datoteki ~/deploy-secret.txt (izven javne mape, ni v repozitoriju).
$home = dirname($_SERVER['DOCUMENT_ROOT']);
$repo = $home . '/repositories/spletna-stran';
$web  = $_SERVER['DOCUMENT_ROOT'];
$log  = $home . '/deploy-log.txt';
$files = ['index.html', 'contact.php', 'licence-pisav.txt', 'deploy-hook.php'];

function out($code, $msg) { http_response_code($code); header('Content-Type: text/plain'); echo $msg; exit; }
function logit($f, $m) { @file_put_contents($f, date('c') . ' ' . $m . "\n", FILE_APPEND); }

$body = file_get_contents('php://input');
$sig  = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
$secret = trim((string)@file_get_contents($home . '/deploy-secret.txt'));
if ($secret === '' || !hash_equals('sha256=' . hash_hmac('sha256', $body, $secret), $sig)) out(403, 'Forbidden');

$event = $_SERVER['HTTP_X_GITHUB_EVENT'] ?? '';
if ($event === 'ping') out(200, 'pong');
$data = json_decode($body, true);
if ($event !== 'push' || ($data['ref'] ?? '') !== 'refs/heads/main') out(200, 'Ignored');

if (!function_exists('exec')) { logit($log, 'exec onemogocen'); out(500, 'exec disabled'); }
$cmd = 'HOME=' . escapeshellarg($home) . ' git -C ' . escapeshellarg($repo) . ' pull --ff-only 2>&1';
exec($cmd, $lines, $rc);
logit($log, 'git pull rc=' . $rc . ' ' . implode(' | ', $lines));
if ($rc !== 0) out(500, 'git pull failed');

foreach ($files as $f) {
  if (is_file("$repo/$f") && !copy("$repo/$f", "$web/$f")) logit($log, "kopiranje ni uspelo: $f");
}
logit($log, 'deploy OK');
out(200, 'OK');
