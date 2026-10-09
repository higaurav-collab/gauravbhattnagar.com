<?php
/*
 * Form handler for gauravbhattnagar.com
 * Receives contact enquiries, quiz results and newsletter signups as JSON,
 * stores them outside the public web folder, emails Gaurav, and sends the
 * visitor a short confirmation.
 *
 * Secrets (the mail password) live in ../form-config/mail.php on the server,
 * outside this repository. See forms/mail-config.example.php.
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function out($code, $ok, $extra = array()) {
    http_response_code($code);
    echo json_encode(array_merge(array('ok' => $ok), $extra));
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { out(405, false); }

/* Only accept posts that come from this website */
$origin = $_SERVER['HTTP_ORIGIN'] ?? ($_SERVER['HTTP_REFERER'] ?? '');
if ($origin !== '') {
    $host = strtolower((string) parse_url($origin, PHP_URL_HOST));
    if ($host !== 'gauravbhattnagar.com' && $host !== 'www.gauravbhattnagar.com' && $host !== 'localhost' && $host !== '127.0.0.1') {
        out(403, false);
    }
}

/* Paths: everything private sits one level above the public folder */
$base = rtrim(getenv('GB_BASE') ?: dirname($_SERVER['DOCUMENT_ROOT']), '/');
$cfgFile = $base . '/form-config/mail.php';
$dataDir = $base . '/form-data';
$cfg = is_file($cfgFile) ? include $cfgFile : null;
if (!is_array($cfg) || empty($cfg['smtp_host']) || empty($cfg['smtp_user']) || empty($cfg['smtp_pass'])) {
    /* Not set up yet: tell the page to use its backup route */
    out(503, false, array('error' => 'not_configured'));
}
if (!is_dir($dataDir) && !@mkdir($dataDir, 0700, true)) { out(500, false, array('error' => 'storage')); }

/* Read the submission */
$raw = file_get_contents('php://input', false, null, 0, 30000);
$d = json_decode($raw, true);
if (!is_array($d)) { $d = $_POST; }

function clean_line($s, $max) {
    $s = is_string($s) ? $s : '';
    $s = preg_replace('/[\r\n\t]+/', ' ', $s);
    $s = trim(preg_replace('/[^\P{C}]+/u', '', $s));
    return mb_substr($s, 0, $max);
}
function clean_text($s, $max) {
    $s = is_string($s) ? $s : '';
    $s = str_replace("\r\n", "\n", $s);
    $s = preg_replace('/[^\P{C}\n]+/u', '', $s);
    return mb_substr(trim($s), 0, $max);
}

/* Spam trap: bots fill the hidden field. Pretend it worked. */
if (!empty($d['_gotcha'])) { out(200, true); }

$name    = clean_line($d['name'] ?? '', 120);
$email   = clean_line($d['email'] ?? '', 254);
$subject = clean_line($d['subject'] ?? 'Website enquiry', 150);
$message = clean_text($d['message'] ?? '', 6000);
$company = clean_line($d['company'] ?? '', 150);
$heard   = clean_line($d['heard_about_via'] ?? '', 150);
$page    = clean_line($d['page'] ?? '', 200);

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { out(422, false, array('error' => 'email')); }
$isNewsletter = (stripos($subject, 'newsletter') === 0);
if (!$isNewsletter && $message === '') { out(422, false, array('error' => 'message')); }

/* Simple rate limits: 6 posts per 10 minutes per visitor, 1 confirmation per address per hour */
$ip = $_SERVER['REMOTE_ADDR'] ?? 'x';
$rl = $dataDir . '/rate-' . substr(sha1($ip), 0, 16) . '.json';
$now = time();
$hits = is_file($rl) ? (json_decode(@file_get_contents($rl), true) ?: array()) : array();
$hits = array_values(array_filter($hits, function ($t) use ($now) { return $t > $now - 600; }));
if (count($hits) >= 6) { out(429, false, array('error' => 'slow_down')); }
$hits[] = $now;
@file_put_contents($rl, json_encode($hits), LOCK_EX);
/* tidy old rate files now and then */
if (mt_rand(1, 40) === 1) {
    foreach (glob($dataDir . '/rate-*.json') ?: array() as $f) { if (@filemtime($f) < $now - 3600) { @unlink($f); } }
}
$ackFile = $dataDir . '/ack-' . substr(sha1(strtolower($email)), 0, 20);
$sendAck = !is_file($ackFile) || @filemtime($ackFile) < $now - 3600;

/* Store first, so nothing is lost if email trips */
$record = array(
    'received_utc' => gmdate('c'),
    'type' => $isNewsletter ? 'newsletter' : 'enquiry',
    'name' => $name, 'email' => $email, 'subject' => $subject,
    'company' => $company, 'heard_about_via' => $heard,
    'message' => $message, 'page' => $page,
);
if (@file_put_contents($dataDir . '/submissions.jsonl', json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX) === false) {
    out(500, false, array('error' => 'storage'));
}
if ($isNewsletter) {
    $csv = $dataDir . '/subscribers.csv';
    $new = !is_file($csv);
    $fh = @fopen($csv, 'a');
    if ($fh) {
        if ($new) { fputcsv($fh, array('subscribed_utc', 'email', 'name', 'signup_page')); }
        fputcsv($fh, array(gmdate('c'), $email, $name, $page));
        fclose($fh);
    }
}

/* ---- Email ---- */
function b64line($s) { return '=?UTF-8?B?' . base64_encode($s) . '?='; }

function smtp_send($cfg, $to, $subject, $body, $replyTo = null) {
    $secure = $cfg['smtp_secure'] ?? 'ssl';
    $port = (int) ($cfg['smtp_port'] ?? 465);
    $remote = ($secure === 'ssl' ? 'ssl://' : 'tcp://') . $cfg['smtp_host'] . ':' . $port;
    $fp = @stream_socket_client($remote, $en, $es, 12);
    if (!$fp) { $GLOBALS['gb_err'] = 'connect: ' . trim((string) $es) . ' (' . $en . ')'; return false; }
    stream_set_timeout($fp, 12);
    $read = function () use ($fp) {
        $all = '';
        while (($line = fgets($fp, 1024)) !== false) {
            $all .= $line;
            if (strlen($line) < 4 || $line[3] === ' ') { break; }
        }
        return $all;
    };
    $cmd = function ($c, $expect) use ($fp, $read) {
        if ($c !== null) { fwrite($fp, $c . "\r\n"); }
        $r = $read();
        $good = strpos($r, (string) $expect) === 0;
        if (!$good) { $GLOBALS['gb_err'] = 'expected ' . $expect . ' got: ' . substr(trim(preg_replace('/\s+/', ' ', $r)), 0, 140); }
        return $good;
    };
    $ok = $cmd(null, '220') && $cmd('EHLO gauravbhattnagar.com', '250');
    if ($ok && $secure === 'none') { /* local testing only */ }
    if ($ok && $secure !== 'none') {
        $ok = $cmd('AUTH LOGIN', '334') && $cmd(base64_encode($cfg['smtp_user']), '334') && $cmd(base64_encode($cfg['smtp_pass']), '235');
    }
    $fromAddr = $cfg['smtp_user'];
    $fromName = $cfg['from_name'] ?? 'Gaurav Bhatnagar';
    $ok = $ok && $cmd('MAIL FROM:<' . $fromAddr . '>', '250') && $cmd('RCPT TO:<' . $to . '>', '250') && $cmd('DATA', '354');
    if ($ok) {
        $h  = 'Date: ' . date('r') . "\r\n";
        $h .= 'From: ' . b64line($fromName) . ' <' . $fromAddr . ">\r\n";
        $h .= 'To: <' . $to . ">\r\n";
        if ($replyTo) { $h .= 'Reply-To: <' . $replyTo . ">\r\n"; }
        $h .= 'Subject: ' . b64line($subject) . "\r\n";
        $h .= 'Message-ID: <' . bin2hex(random_bytes(8)) . '@gauravbhattnagar.com>' . "\r\n";
        $h .= "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n";
        $h .= chunk_split(base64_encode($body), 76, "\r\n");
        fwrite($fp, $h . "\r\n.\r\n");
        $ok = strpos($read(), '250') === 0;
    }
    @fwrite($fp, "QUIT\r\n");
    @fclose($fp);
    return $ok;
}

$notifyTo = $cfg['notify_to'] ?? $cfg['smtp_user'];
$lines = array(
    ($isNewsletter ? 'New newsletter subscriber' : 'New website enquiry'),
    '',
    'Name: ' . ($name ?: '(not given)'),
    'Email: ' . $email,
    'Subject: ' . $subject,
);
if ($company) { $lines[] = 'Company: ' . $company; }
if ($heard)   { $lines[] = 'Heard about me via: ' . $heard; }
if ($page)    { $lines[] = 'Sent from: ' . $page; }
if ($message !== '') { $lines[] = ''; $lines[] = $message; }
$lines[] = '';
$lines[] = 'Reply to this email to answer them directly.';
$notified = smtp_send($cfg, $notifyTo, '[Website] ' . $subject . ($name ? ' - ' . $name : ''), implode("\n", $lines), $email);
if (!$notified) {
    /* Stored, but Gaurav was not told: let the page fall back so the lead also reaches the backup inbox */
    out(502, false, array('error' => 'notify', 'detail' => $GLOBALS['gb_err'] ?? 'unknown'));
}

/* Confirmation to the visitor (best effort) */
if ($sendAck) {
    $first = $name !== '' ? preg_split('/\s+/', $name)[0] : '';
    $hi = $first !== '' ? 'Hi ' . $first . ',' : 'Hi,';
    if ($isNewsletter) {
        $ackSubject = "You're subscribed";
        $ackBody = $hi . "\n\nThanks for subscribing. You'll get the next issue in your inbox the day it comes out.\n\n"
            . "While you wait, the earlier issues are here: https://gauravbhattnagar.com/newsletter.html\n\n"
            . "If you ever want to stop, just reply with the word unsubscribe and I'll remove you.\n\nGaurav";
    } else {
        $ackSubject = 'Got your message';
        $ackBody = $hi . "\n\nThanks for writing. I've received your message and I'll reply personally within two working days.\n\n"
            . "If you'd rather talk it through, you can pick a time for a 30-minute call here: https://calendar.app.google/iNEcTzeAnPFheV6w7\n\n"
            . "Gaurav Bhatnagar\ngauravbhattnagar.com";
    }
    if (smtp_send($cfg, $email, $ackSubject, $ackBody, $cfg['smtp_user'])) { @touch($ackFile); }
}

out(200, true);
