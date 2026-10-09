<?php
/*
 * Small sync store for the private desk page (/desk/).
 * Keeps one JSON file of tick/counter states outside the public folder and
 * merges what each device sends (newest change per item wins).
 * Needs 'sync_code' in ../form-config/mail.php on the server; without it this
 * answers 503 and the page simply keeps saving on the device.
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex');

function out($code, $ok, $extra = array()) {
    http_response_code($code);
    echo json_encode(array_merge(array('ok' => $ok), $extra));
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { out(405, false); }

$origin = $_SERVER['HTTP_ORIGIN'] ?? ($_SERVER['HTTP_REFERER'] ?? '');
if ($origin !== '') {
    $host = strtolower((string) parse_url($origin, PHP_URL_HOST));
    if ($host !== 'gauravbhattnagar.com' && $host !== 'www.gauravbhattnagar.com' && $host !== 'localhost' && $host !== '127.0.0.1') { out(403, false); }
}

$base = rtrim(getenv('GB_BASE') ?: dirname($_SERVER['DOCUMENT_ROOT']), '/');
$cfgFile = $base . '/form-config/mail.php';
$dataDir = $base . '/form-data';
$cfg = is_file($cfgFile) ? include $cfgFile : null;
$secret = (is_array($cfg) && !empty($cfg['sync_code']) && is_string($cfg['sync_code'])) ? $cfg['sync_code'] : '';
if ($secret === '' || strlen($secret) < 8) { out(503, false, array('error' => 'not_configured')); }
if (!is_dir($dataDir) && !@mkdir($dataDir, 0700, true)) { out(500, false, array('error' => 'storage')); }

/* Slow down guessing: 8 wrong codes per 10 minutes per visitor */
$ip = $_SERVER['REMOTE_ADDR'] ?? 'x';
$bf = $dataDir . '/syncfail-' . substr(sha1($ip), 0, 16) . '.json';
$now = time();
$fails = is_file($bf) ? (json_decode(@file_get_contents($bf), true) ?: array()) : array();
$fails = array_values(array_filter($fails, function ($t) use ($now) { return $t > $now - 600; }));
if (count($fails) >= 8) { out(429, false, array('error' => 'slow_down')); }

$given = (string) ($_SERVER['HTTP_X_SYNC_CODE'] ?? '');
if (!hash_equals($secret, $given)) {
    $fails[] = $now;
    @file_put_contents($bf, json_encode($fails), LOCK_EX);
    out(403, false, array('error' => 'code'));
}

$raw = file_get_contents('php://input', false, null, 0, 40000);
$in = json_decode($raw, true);
$sent = (is_array($in) && isset($in['state']) && is_array($in['state'])) ? $in['state'] : array();

$file = $dataDir . '/runway.json';
$fh = @fopen($file, 'c+');
if (!$fh) { out(500, false, array('error' => 'storage')); }
flock($fh, LOCK_EX);
$cur = json_decode((string) stream_get_contents($fh), true);
if (!is_array($cur)) { $cur = array(); }
if (!isset($cur['m']) || !is_array($cur['m'])) { $cur['m'] = array(); }
if (!isset($cur['c']) || !is_array($cur['c'])) { $cur['c'] = array(); }

$idOk = function ($k) { return is_string($k) && preg_match('/^[A-Za-z0-9_-]{1,40}$/', $k); };
$nowMs = $now * 1000 + 86400000; /* ignore timestamps more than a day ahead */
$n = 0;
foreach ((isset($sent['m']) && is_array($sent['m']) ? $sent['m'] : array()) as $id => $r) {
    if (!$idOk($id) || !is_array($r) || !isset($r['t']) || !is_numeric($r['t']) || $r['t'] > $nowMs) { continue; }
    if (++$n > 300) { break; }
    $t = (int) $r['t'];
    if (!isset($cur['m'][$id]) || $t > $cur['m'][$id]['t']) { $cur['m'][$id] = array('d' => !empty($r['d']), 't' => $t); }
}
$n = 0;
foreach ((isset($sent['c']) && is_array($sent['c']) ? $sent['c'] : array()) as $id => $r) {
    if (!$idOk($id) || !is_array($r) || !isset($r['t'], $r['n'], $r['g']) || !is_numeric($r['t']) || $r['t'] > $nowMs) { continue; }
    if (++$n > 100) { break; }
    $t = (int) $r['t'];
    if (!isset($cur['c'][$id]) || $t > $cur['c'][$id]['t']) {
        $cur['c'][$id] = array('n' => max(0, min(99999, (int) $r['n'])), 'g' => max(1, min(999, (int) $r['g'])), 't' => $t);
    }
}

ftruncate($fh, 0);
rewind($fh);
fwrite($fh, json_encode($cur));
fflush($fh);
flock($fh, LOCK_UN);
fclose($fh);

out(200, true, array('state' => $cur));
