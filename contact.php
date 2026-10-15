<?php
// Prejemnik sporočil iz obrazca na strani. Naslov mora obstajati na domeni uzelac.si.
$to = 'info@uzelac.si';

header('Content-Type: application/json; charset=utf-8');

function fail($code) {
    http_response_code($code);
    echo json_encode(['ok' => false]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fail(405);
}

// Skrito polje: ljudje ga ne vidijo, roboti ga izpolnijo. Take zahteve tiho zavrnemo.
if (!empty($_POST['web'])) {
    echo json_encode(['ok' => true]);
    exit;
}

// Največ eno sporočilo na 30 sekund z istega naslova IP.
$ip = $_SERVER['REMOTE_ADDR'] ?? 'x';
$lock = sys_get_temp_dir() . '/uz_form_' . md5($ip);
if (is_file($lock) && (time() - filemtime($lock)) < 30) {
    fail(429);
}

function enovrstica($k) {
    return trim(preg_replace('/[\r\n\t]+/', ' ', (string)($_POST[$k] ?? '')));
}

$ime = enovrstica('ime');
$priimek = enovrstica('priimek');
$tel = enovrstica('tel');
$rok = enovrstica('rok');
$soglasje = (string)($_POST['soglasje'] ?? '');
$mail = trim((string)($_POST['mail'] ?? ''));
$msg = trim((string)($_POST['msg'] ?? ''));
$msg = preg_replace('/[^\P{C}\n]+/u', '', str_replace("\r", '', $msg));

if ($ime === '' || $priimek === '' || $tel === '' || $msg === '' || $soglasje !== '1' || !filter_var($mail, FILTER_VALIDATE_EMAIL)) {
    fail(422);
}
if (mb_strlen($ime) > 80 || mb_strlen($priimek) > 80 || mb_strlen($tel) > 40 || mb_strlen($rok) > 160
    || mb_strlen($mail) > 160 || mb_strlen($msg) > 4000) {
    fail(422);
}
if (preg_match('/[\r\n]/', $mail)) {
    fail(422);
}

$subject = mb_encode_mimeheader('Povpraševanje prek spletne strani – ' . $ime . ' ' . $priimek, 'UTF-8', 'B');
$body = "Ime in priimek: $ime $priimek\n"
    . "Telefon: $tel\n"
    . "E-pošta: $mail\n"
    . 'Rok / narok: ' . ($rok !== '' ? $rok : '—') . "\n\n"
    . "Opis zadeve:\n$msg\n";

$headers = [
    'From: Spletna stran <' . $to . '>',
    'Reply-To: ' . $mail,
    'MIME-Version: 1.0',
    'Content-Type: text/plain; charset=UTF-8',
    'Content-Transfer-Encoding: 8bit',
];

$sent = @mail($to, $subject, $body, implode("\r\n", $headers), '-f' . $to);
if (!$sent) {
    $sent = @mail($to, $subject, $body, implode("\r\n", $headers));
}

if (!$sent) {
    fail(500);
}

@touch($lock);
echo json_encode(['ok' => true]);
