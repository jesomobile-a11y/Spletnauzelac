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
$termin = enovrstica('termin');
$soglasje = (string)($_POST['soglasje'] ?? '');
$mail = trim((string)($_POST['mail'] ?? ''));
$msg = trim((string)($_POST['msg'] ?? ''));
$msg = preg_replace('/[^\P{C}\n]+/u', '', str_replace("\r", '', $msg));

if ($ime === '' || $priimek === '' || $tel === '' || $msg === '' || $soglasje !== '1' || !filter_var($mail, FILTER_VALIDATE_EMAIL)) {
    fail(422);
}
if (mb_strlen($ime) > 80 || mb_strlen($priimek) > 80 || mb_strlen($tel) > 40 || mb_strlen($rok) > 160 || mb_strlen($termin) > 160
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
    . 'Rok / narok: ' . ($rok !== '' ? $rok : '—') . "\n"
    . 'Želeni termin: ' . ($termin !== '' ? $termin : '—') . "\n\n"
    . "Opis zadeve:\n$msg\n";

$headers = [
    'From: Spletna stran <' . $to . '>',
    'Reply-To: ' . $mail,
    'MIME-Version: 1.0',
    'Content-Type: text/plain; charset=UTF-8',
    'Content-Transfer-Encoding: 8bit',
];

$home = dirname($_SERVER['DOCUMENT_ROOT']);
$log = $home . '/form-log.txt';
function zapis($f, $m) { @file_put_contents($f, date('c') . ' ' . $m . "\n", FILE_APPEND); }

// Pošiljanje prek SMTP s predalom na tem strežniku. Geslo je v datoteki ~/smtp-pass.txt (izven javne mape).
function smtp_posli($to, $subject, $body, $replyTo, $pass, &$napaka) {
    $ctx = stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
    $fp = @stream_socket_client('ssl://localhost:465', $en, $es, 10, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) { $napaka = "povezava: $es"; return false; }
    stream_set_timeout($fp, 10);
    $ukaz = function ($c, $ok) use ($fp, &$napaka) {
        if ($c !== null) fwrite($fp, $c . "\r\n");
        $r = '';
        while (($l = fgets($fp, 512)) !== false) { $r .= $l; if (strlen($l) < 4 || $l[3] === ' ') break; }
        if (strpos($r, (string)$ok) !== 0) { $napaka = trim($r); return false; }
        return true;
    };
    $from = $to;
    $dostavi = $ukaz(null, 220)
        && $ukaz('EHLO uzelac.si', 250)
        && $ukaz('AUTH LOGIN', 334)
        && $ukaz(base64_encode($from), 334)
        && $ukaz(base64_encode($pass), 235)
        && $ukaz("MAIL FROM:<$from>", 250)
        && $ukaz("RCPT TO:<$to>", 250)
        && $ukaz('DATA', 354);
    if ($dostavi) {
        $msg = "From: Spletna stran <$from>\r\nTo: <$to>\r\nReply-To: $replyTo\r\nSubject: $subject\r\n"
            . "Date: " . date('r') . "\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: 8bit\r\n\r\n"
            . preg_replace('/^\./m', '..', str_replace("\n", "\r\n", $body)) . "\r\n.";
        $dostavi = $ukaz($msg, 250);
    }
    @fwrite($fp, "QUIT\r\n");
    @fclose($fp);
    return $dostavi;
}

$sent = false;
$pass = trim((string)@file_get_contents($home . '/smtp-pass.txt'));
if ($pass !== '') {
    $napaka = '';
    $sent = smtp_posli($to, $subject, $body, $mail, $pass, $napaka);
    if (!$sent) zapis($log, 'SMTP: ' . $napaka);
}
if (!$sent) {
    $sent = @mail($to, $subject, $body, implode("\r\n", $headers), '-f' . $to);
    if (!$sent) $sent = @mail($to, $subject, $body, implode("\r\n", $headers));
    if (!$sent) zapis($log, 'mail() ni uspel');
}

if (!$sent) {
    fail(500);
}

@touch($lock);
echo json_encode(['ok' => true]);
