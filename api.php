<?php
/**
 * Divotraily – jednoduché API pre Websupport hosting
 *  - check-in brigádnikov (ukladá do data/checkins.json)
 *  - prihláška za člena (odošle e-mail + záloha do data/prihlasky.json)
 *  - redakčný systém: obsah webu v data/content.json, nahrané súbory v uploads/
 *
 * NASTAVENIA – uprav podľa potreby:
 */
const PRIJEMCA    = 'divo@divozdruzenie.sk';  // OFICIÁLNY e-mail – sem chodia prihlášky a upozornenia na tričká
const ODOSIELATEL = 'web@divotraily.sk';      // adresa na doméne, kde web beží (Websupport inak e-mail nepustí)
const NAZOV_WEBU  = 'Divotraily';
const TRICKO_ZA   = 5;                        // počet brigád na tričko

// TAJNÉ ÚDAJE (heslá rolí, kľúč na podpis prihlásenia, SMTP heslo) sú v súbore config.php,
// ktorý NIE JE v gite. Vytvor ho podľa config.sample.php. Bez neho prihlásenie nefunguje.
$CFG = is_file(__DIR__ . '/config.php') ? (require __DIR__ . '/config.php') : [];
if (!is_array($CFG)) $CFG = [];
//  brigada  – všetko okolo brigád (termíny, info, zápis účasti)
//  admin    – texty, obrázky a informácie na webe, registrácie členov
//  designer – úplne všetko (aj obťahovanie trailov, RZP body, zálohy)
define('UCTY', array_filter((array)($CFG['ucty'] ?? []), fn($h) => is_string($h) && strlen($h) >= 8 && strpos($h, 'SEM-DAJ') !== 0));
define('TAJNY_KLUC', strpos((string)($CFG['tajny_kluc'] ?? ''), 'SEM-DAJ') === 0 ? '' : (string)($CFG['tajny_kluc'] ?? ''));   // na podpis prihlásenia (aspoň 32 znakov)

// SMTP (ODPORÚČANÉ – najspoľahlivejšie doručenie). Vytvor na Websupporte schránku
// napr. web@divotraily.sk a vyplň jej heslo. Ak necháš heslo prázdne, použije sa PHP mail().
const SMTP_HOST = 'smtp.m1.websupport.sk';
const SMTP_PORT = 465;
define('SMTP_USER', (string)($CFG['smtp_user'] ?? 'web@divotraily.sk'));
define('SMTP_PASS', (string)($CFG['smtp_pass'] ?? ''));

// Záloha: ak zlyhá SMTP aj mail(), prihláška sa pošle cez službu FormSubmit.co
// (pri prvom použití príde na PRIJEMCA e-mail s tlačidlom „Activate“ – treba ho raz potvrdiť).
const ZALOHA_FORMSUBMIT = true;

// Dopyt na merch: ide na PRIJEMCA; sem dopíš ďalšie interné adresy, ktoré dostanú kópiu (napr. kto rieši merch).
// Zákazník tieto adresy nevidí – každá dostane samostatný e-mail.
const MERCH_KOPIA = [];

date_default_timezone_set('Europe/Bratislava');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$DATA = __DIR__ . '/data';
if (!is_dir($DATA)) { @mkdir($DATA, 0755, true); }
if (!file_exists("$DATA/.htaccess")) { @file_put_contents("$DATA/.htaccess", "Require all denied\nDeny from all\n"); }

function out($arr, $code = 200) { http_response_code($code); echo json_encode($arr, JSON_UNESCAPED_UNICODE); exit; }
function body() { $j = json_decode(file_get_contents('php://input'), true); return is_array($j) ? $j : []; }
function clean($s, $max = 200) { $s = trim(preg_replace('/\s+/u', ' ', strip_tags((string)$s))); return mb_substr($s, 0, $max); }

function name_key($s) {
    $map = ['á'=>'a','ä'=>'a','č'=>'c','ď'=>'d','é'=>'e','ě'=>'e','í'=>'i','ĺ'=>'l','ľ'=>'l','ň'=>'n','ó'=>'o','ô'=>'o','ö'=>'o','ŕ'=>'r','ř'=>'r','š'=>'s','ť'=>'t','ú'=>'u','ů'=>'u','ü'=>'u','ý'=>'y','ž'=>'z'];
    $s = mb_strtolower($s, 'UTF-8');
    $s = strtr($s, $map);
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    return trim($s, '-');
}

// predposledná nedeľa v mesiaci
function brigada_datum($y, $m) {
    $last = new DateTime(sprintf('%04d-%02d-01', $y, $m));
    $last->modify('last day of this month');
    $back = (int)$last->format('w');       // 0 = nedeľa
    $last->modify("-$back days");          // posledná nedeľa
    $last->modify('-7 days');              // predposledná
    return $last->format('Y-m-d');
}

function read_json($file) {
    if (!file_exists($file)) return [];
    $j = json_decode(file_get_contents($file), true);
    return is_array($j) ? $j : [];
}
function with_lock($file, callable $fn) {
    $fh = fopen($file . '.lock', 'c');
    flock($fh, LOCK_EX);
    $data = read_json($file);
    $res = $fn($data);
    file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    flock($fh, LOCK_UN); fclose($fh);
    return $res;
}
// hra – rebríček: poradie podľa skóre, pri rovnosti vyhráva skorší výsledok
const GAME_MAX_SCORE = 500000; const GAME_MAX_PLAYERS = 2000;
function game_name($s) { $s = trim(preg_replace('/[\x00-\x1F\x7F<>]+/u', '', preg_replace('/\s+/u', ' ', (string)$s))); return mb_strlen($s) >= 1 && mb_strlen($s) <= 20 ? $s : ''; }
function game_sorted($d, $keepKeys = false) {
    $rows = []; foreach ($d as $k => $r) { if (!is_array($r) || !isset($r['score'])) continue; $r['key'] = $k; $rows[$k] = $r; }
    uasort($rows, function ($a, $b) { return ($b['score'] <=> $a['score']) ?: (strcmp($a['at'] ?? '', $b['at'] ?? '') ?: strcmp($a['name'] ?? '', $b['name'] ?? '')); });
    if ($keepKeys) { foreach ($rows as $k => &$r) unset($r['key']); return $rows; }
    return array_values(array_map(fn($r) => ['name' => $r['name'], 'score' => (int)$r['score'], 'trails' => (int)($r['trails'] ?? 0), 'win' => !empty($r['win']), 'at' => $r['at'] ?? '', 'key' => $r['key']], $rows));
}
function rate_limit($bucket, $max, $seconds) {
    global $DATA;
    $ip = hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . $bucket);
    $f = "$DATA/rate.json";
    return with_lock($f, function (&$d) use ($ip, $max, $seconds) {
        $now = time();
        foreach ($d as $k => $list) { $d[$k] = array_values(array_filter($list, fn($t) => $t > $now - 3600)); if (!$d[$k]) unset($d[$k]); }
        $recent = array_filter($d[$ip] ?? [], fn($t) => $t > $now - $seconds);
        if (count($recent) >= $max) return false;
        $d[$ip][] = $now;
        return true;
    });
}
// Zostaví telo e-mailu: čistý text, prípadne aj HTML verzia (multipart/alternative)
function mime_body($text, $html) {
    if (!$html) return ["Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n", chunk_split(base64_encode($text))];
    $bd = 'divo_' . bin2hex(random_bytes(8));
    $body = "--$bd\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($text))
          . "--$bd\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($html))
          . "--$bd--\r\n";
    return ["Content-Type: multipart/alternative; boundary=\"$bd\"\r\n", $body];
}
function mail_headers($replyTo, $ctype) {
    $h  = "From: =?UTF-8?B?" . base64_encode(NAZOV_WEBU) . "?= <" . ODOSIELATEL . ">\r\n";
    if ($replyTo) $h .= "Reply-To: $replyTo\r\n";
    $h .= "Date: " . date('r') . "\r\n";
    $h .= "Message-ID: <" . bin2hex(random_bytes(12)) . "@" . substr(strrchr(ODOSIELATEL, '@'), 1) . ">\r\n";
    $h .= "MIME-Version: 1.0\r\n" . $ctype;
    return $h;
}
function smtp_send($to, $subject, $text, $replyTo, $html = null) {
    if (SMTP_PASS === '') return false;
    $fp = @stream_socket_client('ssl://' . SMTP_HOST . ':' . SMTP_PORT, $errno, $errstr, 15);
    if (!$fp) return false;
    stream_set_timeout($fp, 15);
    $read = function () use ($fp) { $d = ''; while (($l = fgets($fp, 515)) !== false) { $d .= $l; if (strlen($l) < 4 || $l[3] === ' ') break; } return $d; };
    $cmd = function ($c, $ok) use ($fp, $read) { if ($c !== null) fwrite($fp, $c . "\r\n"); $r = $read(); return substr($r, 0, 3) === (string)$ok; };
    [$ctype, $body] = mime_body($text, $html);
    $msg = "To: $to\r\nSubject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n" . mail_headers($replyTo, $ctype) . "\r\n" . $body;
    $ok = $cmd(null, 220) && $cmd('EHLO ' . (gethostname() ?: 'divotraily'), 250)
        && $cmd('AUTH LOGIN', 334) && $cmd(base64_encode(SMTP_USER), 334) && $cmd(base64_encode(SMTP_PASS), 235)
        && $cmd('MAIL FROM:<' . ODOSIELATEL . '>', 250) && $cmd("RCPT TO:<$to>", 250)
        && $cmd('DATA', 354) && $cmd(str_replace("\r\n.", "\r\n..", $msg) . "\r\n.", 250);
    @fwrite($fp, "QUIT\r\n"); fclose($fp);
    return $ok;
}
function send_mail($to, $subject, $text, $replyTo = null, $html = null) {
    if (smtp_send($to, $subject, $text, $replyTo, $html)) return 'smtp';
    [$ctype, $body] = mime_body($text, $html);
    if (@mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, mail_headers($replyTo, $ctype), '-f' . ODOSIELATEL)) return 'mail';
    return false;
}
// Vyplnená prihláška ako HTML dokument (vyzerá ako papierový formulár)
function prihlaska_html($cislo, $rows, $fee, $datum) {
    $e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    $tr = '';
    foreach ($rows as $sekcia => $polia) {
        $tr .= '<tr><td colspan="2" style="padding:18px 0 6px;font:700 13px Arial,sans-serif;letter-spacing:.04em;text-transform:uppercase;color:#287e6b;border-bottom:2px solid #24ebba">' . $e($sekcia) . '</td></tr>';
        foreach ($polia as $k => $v) $tr .= '<tr><td style="padding:9px 12px 9px 0;width:42%;font:13px Arial,sans-serif;color:#666;border-bottom:1px solid #eee;vertical-align:top">' . $e($k) . '</td><td style="padding:9px 0;font:bold 15px Arial,sans-serif;color:#111;border-bottom:1px solid #eee">' . nl2br($e($v === '' ? '–' : $v)) . '</td></tr>';
    }
    return '<!doctype html><html><body style="margin:0;background:#f3f3f1;padding:24px 12px">'
      . '<table role="presentation" width="100%" style="max-width:640px;margin:0 auto;background:#fff;border-radius:14px;overflow:hidden" cellpadding="0" cellspacing="0">'
      . '<tr><td style="background:#000;padding:22px 28px"><div style="font:700 22px Arial,sans-serif;color:#24ebba">PRIHLÁŠKA ZA ČLENA</div>'
      . '<div style="font:13px Arial,sans-serif;color:#bbb;margin-top:4px">DIVO občasné združenie o.z. · Hodžova 19, 911 01 Trenčín</div></td></tr>'
      . '<tr><td style="padding:10px 28px 4px;font:13px Arial,sans-serif;color:#666">Číslo prihlášky: <b style="color:#111">' . $e($cislo) . '</b> · Prijatá: <b style="color:#111">' . $e($datum) . '</b> · Zdroj: web Divotraily</td></tr>'
      . '<tr><td style="padding:0 28px 8px"><table role="presentation" width="100%" cellpadding="0" cellspacing="0">' . $tr . '</table></td></tr>'
      . '<tr><td style="padding:16px 28px 26px"><div style="background:#e9fbf5;border-radius:10px;padding:14px 16px;font:14px Arial,sans-serif;color:#1d4a3f">'
      . '<b>Členský príspevok: ' . $e($fee) . '</b> na účet SK35 7500 0000 0040 2816 3715.<br>Členstvo platí po pripísaní platby. Na túto správu môžeš priamo odpovedať – odpoveď pôjde žiadateľovi.</div></td></tr>'
      . '</table></body></html>';
}
function prihlaska_text($cislo, $rows, $fee, $datum) {
    $t = "PRIHLÁŠKA ZA ČLENA – DIVO občasné združenie o.z.\nČíslo prihlášky: $cislo · Prijatá: $datum\n";
    foreach ($rows as $sekcia => $polia) { $t .= "\n== " . mb_strtoupper($sekcia) . " ==\n"; foreach ($polia as $k => $v) $t .= "$k: " . ($v === '' ? '–' : $v) . "\n"; }
    return $t . "\nČlenský príspevok: $fee (IBAN SK35 7500 0000 0040 2816 3715)\n";
}
function formsubmit_send($subject, $fields, $replyTo) {
    if (!ZALOHA_FORMSUBMIT || !function_exists('curl_init')) return false;
    $ch = curl_init('https://formsubmit.co/ajax/' . PRIJEMCA);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_POSTFIELDS => json_encode(['_subject' => $subject, '_replyto' => $replyTo, '_template' => 'table', '_captcha' => 'false'] + $fields, JSON_UNESCAPED_UNICODE)]);
    $r = json_decode((string)curl_exec($ch), true); curl_close($ch);
    return is_array($r) && ($r['success'] ?? '') == 'true';
}
// Zápis prihlášky priamo do Google formulára „Prihláška za člena“ (odpovede sa zjavia v Google Forms)
const GFORM_URL = 'https://docs.google.com/forms/d/e/1FAIpQLSec7ekdmBqBopnngUdgTCb_cuiajCVSpAXOKTUfi-JDeZ3i8g/formResponse';
const GFORM = ['meno' => 1750963002, 'priezvisko' => 1992126474, 'narodenie' => 1402416248, 'zastupca' => 912824335,
               'adresa' => 914932472, 'telefon' => 1673261561, 'email' => 360065772, 'suhlas' => 1650038748];
function gform_send($f, $age) {
    if (!function_exists('curl_init')) return false;
    [$y, $m, $d] = explode('-', $f['narodenie']);
    $p = [
        'emailAddress' => $f['email'],
        'entry.' . GFORM['meno'] => $f['meno'],
        'entry.' . GFORM['priezvisko'] => $f['priezvisko'],
        'entry.' . GFORM['narodenie'] . '_year' => (int)$y,
        'entry.' . GFORM['narodenie'] . '_month' => (int)$m,
        'entry.' . GFORM['narodenie'] . '_day' => (int)$d,
        'entry.' . GFORM['adresa'] => $f['adresa'],
        'entry.' . GFORM['telefon'] => $f['telefon'],
        'entry.' . GFORM['email'] => $f['email'],
        'entry.' . GFORM['suhlas'] => 'súhlasím',
    ];
    if ($age < 18) $p['entry.' . GFORM['zastupca']] = $f['zastupca'] . ', ' . $f['zastupca_email'];
    $ch = curl_init(GFORM_URL);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15,
        CURLOPT_POSTFIELDS => http_build_query($p), CURLOPT_FOLLOWLOCATION => false]);
    curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return $code >= 200 && $code < 400;
}
// token = expirácia.rola.podpis   (rola: brigada | admin | designer)
function auth_ready() { return strlen(TAJNY_KLUC) >= 32 && count(UCTY) > 0; }
function make_token($role = 'brigada') { $exp = time() + 12 * 3600; $p = $exp . '.' . $role; return $p . '.' . hash_hmac('sha256', $p, TAJNY_KLUC . implode('|', UCTY)); }
function token_role($t) {
    if (!auth_ready()) return false;   // bez config.php sa nedá prihlásiť ani podvrhnúť token
    if (!is_string($t) || !preg_match('/^(\d+)\.(brigada|admin|designer)\.([a-f0-9]{64})$/', $t, $m)) return false;
    if ((int)$m[1] <= time() || !hash_equals(hash_hmac('sha256', $m[1] . '.' . $m[2], TAJNY_KLUC . implode('|', UCTY)), $m[3])) return false;
    return $m[2];
}
/* záloha celého obsahu pred každou zmenou (posledných 40) */
function backup_content() {
    global $DATA;
    $src = "$DATA/content.json";
    if (!is_file($src)) return;
    $dir = "$DATA/backup";
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    @copy($src, "$dir/content-" . date('Ymd-His') . '-' . substr(bin2hex(random_bytes(2)), 0, 3) . '.json');
    $all = glob("$dir/content-*.json") ?: [];
    sort($all);
    while (count($all) > 40) @unlink(array_shift($all));
}

/* ---------- súkromná evidencia brigádnikov: data/brigadnici.json = {pid: {name, key, nick, ts, shirts: {n: {...}}}} ----------
   Verejne sa na webe zobrazuje len nick; celé meno a tričká vidí iba prihlásený vedúci/správca. */
function nick_of($name) {
    $p = preg_split('/\s+/u', trim((string)$name));
    if (count($p) > 1) return $p[0] . ' ' . mb_strtoupper(mb_substr(end($p), 0, 1)) . '.';
    return $p[0] !== '' ? $p[0] : '?';
}
function new_pid() { return 'p' . base_convert((string)time(), 10, 36) . bin2hex(random_bytes(3)); }
/* Doplní pid a nick do zápisov účasti a založí chýbajúce osoby v evidencii (starší zápisy mali len meno a key). */
function sync_brigadnici(&$checkins, &$dir, $nicks = []) {
    $byKey = [];
    foreach ($dir as $pid => $p) if (!empty($p['key'])) $byKey[$p['key']] = $pid;
    $changed = false;
    foreach ($checkins as &$r) {
        if (!is_array($r) || empty($r['key'])) continue;
        $k = $r['key'];
        if (!isset($byKey[$k])) {
            $pid = !empty($r['pid']) && !isset($dir[$r['pid']]) ? $r['pid'] : new_pid();
            $dir[$pid] = ['name' => $r['name'] ?? $k, 'key' => $k, 'nick' => $nicks[$k] ?? nick_of($r['name'] ?? $k), 'ts' => date('c')];
            $byKey[$k] = $pid;
        } elseif (isset($nicks[$k]) && $nicks[$k] !== ($dir[$byKey[$k]]['nick'] ?? '')) {
            $dir[$byKey[$k]]['nick'] = $nicks[$k];
        }
        $pid = $byKey[$k];
        if (($r['pid'] ?? '') !== $pid || ($r['nick'] ?? '') !== $dir[$pid]['nick']) { $r['pid'] = $pid; $r['nick'] = $dir[$pid]['nick']; $changed = true; }
    }
    unset($r);
    return $changed;
}
function clean_shirt($s) {
    $s = is_array($s) ? $s : [];
    $size = clean($s['size'] ?? '', 6); $color = clean($s['color'] ?? '', 20); $date = (string)($s['date'] ?? '');
    return ['size' => $size, 'color' => $color, 'given' => !empty($s['given']),
            'date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : '', 'ts' => date('c')];
}

function check_token($t, $roles = ['brigada', 'designer']) { $r = token_role($t); return $r && in_array($r, $roles, true) ? $r : false; }
// dátumy brigád: pravidlo (predposledná nedeľa) + termíny upravené v redakcii
function brigada_ok($date) {
    global $DATA;
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m)) return false;
    if (brigada_datum((int)$m[1], (int)$m[2]) === $date) return true;
    $c = read_json("$DATA/content.json");
    foreach (($c['brig']['items'] ?? []) as $it) if (($it['date'] ?? '') === $date && empty($it['cancelled'])) return true;
    return false;
}

$action = $_GET['action'] ?? '';

switch ($action) {

case 'ping':
    out(['ok' => true]);

case 'ics':
    $date = (string)($_GET['date'] ?? '');
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m) || brigada_datum((int)$m[1], (int)$m[2]) !== $date) { http_response_code(400); exit; }
    $plan = mb_substr(trim(strip_tags((string)($_GET['plan'] ?? ''))), 0, 200);
    $esc = fn($t) => str_replace(["\\", ';', ',', "\n"], ["\\\\", '\;', '\,', '\n'], $t);
    $d1 = str_replace('-', '', $date); $d2 = date('Ymd', strtotime("$date +1 day"));
    $desc = ($plan ? "Plán: $plan\n" : '') . "Presný čas zrazu zverejníme pár dní vopred na Facebooku a Instagrame Divo traily.\nZa 5 brigád tričko Divotraily!";
    $ics = implode("\r\n", ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Divotraily//Brigady//SK', 'CALSCALE:GREGORIAN', 'METHOD:PUBLISH', 'BEGIN:VEVENT',
        "UID:brigada-$d1@divotraily", 'DTSTAMP:' . gmdate('Ymd\THis\Z'), "DTSTART;VALUE=DATE:$d1", "DTEND;VALUE=DATE:$d2",
        'SUMMARY:' . $esc('Brigáda Divotraily'), 'LOCATION:' . $esc('Informačná tabuľa DIVO traily, koniec Kukučínovej ulice, Lesopark Brezina, Trenčín'),
        'DESCRIPTION:' . $esc($desc), 'BEGIN:VALARM', 'TRIGGER:-P1D', 'ACTION:DISPLAY', 'DESCRIPTION:' . $esc('Zajtra je brigáda Divotraily'), 'END:VALARM',
        'END:VEVENT', 'END:VCALENDAR']) . "\r\n";
    header('Content-Type: text/calendar; charset=utf-8');
    header('Content-Disposition: inline; filename="brigada-divotraily-' . $date . '.ics"');
    echo $ics; exit;

case 'pocasie':
    $cache = "$DATA/pocasie.json";
    $c = read_json($cache);
    if (!$c || ($c['at'] ?? 0) < time() - 900) {
        $url = 'https://api.open-meteo.com/v1/forecast?latitude=48.8865&longitude=18.0575&elevation=300&current=temperature_2m,weather_code&timezone=Europe%2FBratislava';
        $raw = false;
        if (function_exists('curl_init')) { $ch = curl_init($url); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8]); $raw = curl_exec($ch); curl_close($ch); }
        else { $raw = @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => 8]])); }
        $j = json_decode((string)$raw, true);
        if (isset($j['current']['temperature_2m'])) {
            $c = ['t' => $j['current']['temperature_2m'], 'code' => $j['current']['weather_code'], 'cas' => $j['current']['time'], 'at' => time()];
            @file_put_contents($cache, json_encode($c));
        }
    }
    if (!$c) out(['ok' => false]);
    out(['ok' => true, 't' => $c['t'], 'code' => $c['code'], 'cas' => $c['cas']]);

case 'checkins':
    $items = read_json("$DATA/checkins.json");
    out(['ok' => true, 'items' => array_values(array_map(fn($r) => ['name' => $r['name'], 'key' => $r['key'], 'date' => $r['date']]
        + (!empty($r['pid']) ? ['pid' => $r['pid']] : []) + (!empty($r['nick']) ? ['nick' => $r['nick']] : []), $items))]);

case 'login':
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') out(['ok' => false, 'error' => 'Nesprávna požiadavka.'], 405);
    $b = body();
    if (!rate_limit('login', 8, 600)) out(['ok' => false, 'error' => 'Priveľa pokusov, skús to o 10 minút.']);
    if (!auth_ready()) out(['ok' => false, 'error' => 'Prihlásenie ešte nie je nastavené (chýba config.php na serveri).']);
    $u = name_key($b['user'] ?? ''); $p = (string)($b['pass'] ?? '');
    foreach (UCTY as $meno => $heslo) {
        if ($u === name_key($meno) && hash_equals($heslo, $p)) out(['ok' => true, 'role' => $meno, 'token' => make_token($meno)]);
    }
    out(['ok' => false, 'error' => 'Nesprávne meno alebo heslo.']);

case 'checkin':
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') out(['ok' => false, 'error' => 'Nesprávna požiadavka.'], 405);
    $b = body();
    if (!check_token($b['token'] ?? '')) out(['ok' => false, 'auth' => false, 'error' => 'Zápis môže robiť len prihlásený vedúci brigády.']);
    $date = (string)($b['date'] ?? '');
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m)) out(['ok' => false, 'error' => 'Neplatný dátum.']);
    if (!brigada_ok($date) || $date > date('Y-m-d')) out(['ok' => false, 'error' => 'V tento deň brigáda nebola.']);
    $names = [];
    foreach ((array)($b['names'] ?? []) as $n) { $n = clean($n, 60); if (mb_strlen($n) >= 3 && !isset($names[name_key($n)])) $names[name_key($n)] = $n; }
    if (!$names) out(['ok' => false, 'error' => 'Napíš aspoň jedno meno.']);
    $nicks = [];
    foreach ((array)($b['people'] ?? []) as $pp) { if (!is_array($pp)) continue; $k = name_key(clean($pp['name'] ?? '', 60)); $nk = clean($pp['nick'] ?? '', 30); if ($k !== '' && $nk !== '' && isset($names[$k])) $nicks[$k] = $nk; }
    $res = with_lock("$DATA/checkins.json", function (&$d) use ($names, $date, $nicks, $DATA) {
        $added = []; $dups = []; $shirts = [];
        foreach ($names as $key => $name) {
            $exists = false;
            foreach ($d as $r) if ($r['key'] === $key && $r['date'] === $date) { $exists = true; break; }
            if ($exists) { $dups[] = $name; continue; }
            $d[] = ['name' => $name, 'key' => $key, 'date' => $date, 'ts' => date('c')];
            $added[] = $name;
            $count = count(array_filter($d, fn($r) => $r['key'] === $key));
            if ($count % TRICKO_ZA === 0) $shirts[] = "$name ($count. brigáda)";
        }
        with_lock("$DATA/brigadnici.json", function (&$dir) use (&$d, $nicks) { sync_brigadnici($d, $dir, $nicks); return true; });
        return ['added' => $added, 'dups' => $dups, 'shirts' => $shirts];
    });
    if ($res['shirts']) {
        send_mail(PRIJEMCA, 'Divotraily: nárok na tričko', "Po brigáde $date majú nárok na tričko Divotraily:\n\n- " . implode("\n- ", $res['shirts']) . "\n\n– web Divotraily");
    }
    out(['ok' => true] + $res);

case 'uncheck':
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') out(['ok' => false, 'error' => 'Nesprávna požiadavka.'], 405);
    $b = body();
    if (!check_token($b['token'] ?? '')) out(['ok' => false, 'auth' => false]);
    $key = (string)($b['key'] ?? ''); $date = (string)($b['date'] ?? '');
    with_lock("$DATA/checkins.json", function (&$d) use ($key, $date) { $d = array_values(array_filter($d, fn($r) => !((($r['key'] ?? '') === $key || ($r['pid'] ?? '') === $key) && $r['date'] === $date))); return true; });
    out(['ok' => true]);

case 'brigadnici':
    // súkromná evidencia brigádnikov (celé mená, tričká) – len pre prihláseného vedúceho/správcu
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') out(['ok' => false, 'error' => 'Nesprávna požiadavka.'], 405);
    $b = body();
    if (!check_token($b['token'] ?? '')) out(['ok' => false, 'auth' => false, 'error' => 'Evidenciu vidí len prihlásený vedúci brigády.']);
    $items = with_lock("$DATA/checkins.json", function (&$d) use ($DATA) {
        return with_lock("$DATA/brigadnici.json", function (&$dir) use (&$d) { sync_brigadnici($d, $dir); return $dir; });
    });
    out(['ok' => true, 'items' => (object)$items]);

case 'brigadnik_shirt':
    // tričko za každú 5. brigádu: brigadnici/{pid}.shirts[n] = {size, color, given, date, ts}
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') out(['ok' => false, 'error' => 'Nesprávna požiadavka.'], 405);
    $b = body();
    if (!check_token($b['token'] ?? '')) out(['ok' => false, 'auth' => false, 'error' => 'Tričká môže zapisovať len prihlásený vedúci brigády.']);
    $pid = (string)($b['pid'] ?? ''); $n = (int)($b['n'] ?? 0);
    if (!preg_match('/^[A-Za-z0-9_-]{2,40}$/', $pid) || $n < 1 || $n > 50) out(['ok' => false, 'error' => 'Neplatný záznam.']);
    $shirt = clean_shirt($b['shirt'] ?? []);
    $ok = with_lock("$DATA/brigadnici.json", function (&$dir) use ($pid, $n, $shirt) {
        if (!isset($dir[$pid])) return false;
        $s = $dir[$pid]['shirts'] ?? [];
        $s[(string)$n] = $shirt;
        $dir[$pid]['shirts'] = $s;
        return true;
    });
    if (!$ok) out(['ok' => false, 'error' => 'Brigádnik sa nenašiel.']);
    out(['ok' => true]);

case 'merch_dopyt':
    // dopyt na merch od návštevníka: e-mail na PRIJEMCA (+ MERCH_KOPIA), odpovedať sa dá priamo zákazníkovi
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') out(['ok' => false, 'error' => 'Nesprávna požiadavka.'], 405);
    $b = body();
    if (!empty($b['website'])) out(['ok' => true]);
    $f = [
        'id' => preg_replace('/[^a-z0-9]/i', '', (string)($b['id'] ?? '')), 'produkt' => clean($b['produkt'] ?? '', 120), 'cena' => clean($b['cena'] ?? '', 30),
        'meno' => clean($b['meno'] ?? '', 80), 'email' => clean($b['email'] ?? '', 120), 'telefon' => clean($b['telefon'] ?? '', 30),
        'velkost' => clean($b['velkost'] ?? '', 30), 'pocet' => max(1, min(50, (int)($b['pocet'] ?? 1))), 'sprava' => trim(mb_substr(strip_tags((string)($b['sprava'] ?? '')), 0, 1500)),
    ];
    if (mb_strlen($f['meno']) < 2) out(['ok' => false, 'error' => 'Napíš svoje meno.']);
    if (!filter_var($f['email'], FILTER_VALIDATE_EMAIL)) out(['ok' => false, 'error' => 'Skontroluj e-mail.']);
    $dup = $f['id'] !== '' && in_array($f['id'], array_column(array_filter(read_json("$DATA/merch_dopyty.json"), fn($r) => !empty($r['email_odoslany'])), 'id'), true);
    if ($dup) out(['ok' => true, 'dup' => true]);
    if (!rate_limit('merch', 5, 600)) out(['ok' => false, 'error' => 'Priveľa dopytov naraz, skús to o chvíľu.']);
    $rows = ['Produkt' => $f['produkt'] . ($f['cena'] !== '' ? " ({$f['cena']})" : ''), 'Veľkosť' => $f['velkost'], 'Počet kusov' => $f['pocet'],
             'Meno' => $f['meno'], 'E-mail' => $f['email'], 'Telefón' => $f['telefon'], 'Správa' => $f['sprava']];
    $txt = "DOPYT NA MERCH – web Divotraily (" . date('j. n. Y H:i') . ")\n\n";
    foreach ($rows as $k => $v) $txt .= "$k: " . ($v === '' ? '–' : $v) . "\n";
    $txt .= "\nNa túto správu môžeš priamo odpovedať – odpoveď pôjde zákazníkovi.\n";
    $subject = "Dopyt na merch: {$f['produkt']} – {$f['meno']}";
    $sent = send_mail(PRIJEMCA, $subject, $txt, $f['email']);
    if (!$sent && formsubmit_send($subject, array_map(fn($v) => $v === '' ? '–' : $v, $rows), $f['email'])) $sent = 'formsubmit';
    foreach (MERCH_KOPIA as $kopia) if (filter_var($kopia, FILTER_VALIDATE_EMAIL)) send_mail($kopia, $subject, $txt, $f['email']);
    with_lock("$DATA/merch_dopyty.json", function (&$d) use ($f, $sent) { $d[] = $f + ['cas' => date('c'), 'email_odoslany' => $sent]; return true; });
    if (!$sent) out(['ok' => false, 'mailto' => true, 'error' => 'Dopyt sa nepodarilo odoslať automaticky.']);
    if ($sent) send_mail($f['email'], 'Dopyt na merch Divotraily – ' . $f['produkt'],
        "Ahoj {$f['meno']},\n\nďakujeme za dopyt na merch Divotraily ({$f['produkt']}, {$f['pocet']} ks" . ($f['velkost'] !== '' ? ", {$f['velkost']}" : '') . ").\nOzveme sa ti čo najskôr.\n\nDivo\n", PRIJEMCA);
    out(['ok' => true, 'via' => $sent]);

case 'clen':
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') out(['ok' => false, 'error' => 'Nesprávna požiadavka.'], 405);
    $b = body();
    if (!empty($b['website'])) out(['ok' => true]);
    $f = [
        'meno' => clean($b['meno'] ?? '', 60), 'priezvisko' => clean($b['priezvisko'] ?? '', 60),
        'email' => clean($b['email'] ?? '', 120), 'telefon' => clean($b['telefon'] ?? '', 30),
        'narodenie' => clean($b['narodenie'] ?? '', 10), 'adresa' => clean($b['adresa'] ?? '', 160),
        'zastupca' => clean($b['zastupca'] ?? '', 120), 'zastupca_email' => clean($b['zastupca_email'] ?? '', 120),
    ];
    foreach (['meno', 'priezvisko', 'email', 'narodenie', 'adresa'] as $k) if ($f[$k] === '') out(['ok' => false, 'error' => 'Vyplň všetky povinné polia.']);
    if (!filter_var($f['email'], FILTER_VALIDATE_EMAIL)) out(['ok' => false, 'error' => 'Skontroluj e-mail.']);
    if (empty($b['suhlas'])) out(['ok' => false, 'error' => 'Chýba súhlas so spracovaním údajov.']);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $f['narodenie'])) out(['ok' => false, 'error' => 'Skontroluj dátum narodenia.']);
    $age = (new DateTime($f['narodenie']))->diff(new DateTime('today'))->y;
    if ($age < 18 && ($f['zastupca'] === '' || !filter_var($f['zastupca_email'], FILTER_VALIDATE_EMAIL))) out(['ok' => false, 'error' => 'Doplň údaje zákonného zástupcu.']);
    if (!rate_limit('clen', 3, 600)) out(['ok' => false, 'error' => 'Priveľa prihlášok naraz, skús to o chvíľu.']);
    $fee = $age < 13 ? '5 € (do 13 rokov)' : '15 € (od 13 rokov)';
    $nar = implode('. ', array_map('intval', array_reverse(explode('-', $f['narodenie']))));
    $datum = date('j. n. Y H:i');

    // poradové číslo prihlášky v roku
    $cislo = with_lock("$DATA/prihlasky.json", function (&$d) {
        $y = date('Y'); $n = count(array_filter($d, fn($r) => substr($r['cas'] ?? '', 0, 4) === $y)) + 1;
        return sprintf('%s-%03d', $y, $n);
    });

    $rows = [
        'Prihláška za člena ' . date('Y') => [
            'Email' => $f['email'],
            'Meno žiadateľa' => $f['meno'],
            'Priezvisko žiadateľa' => $f['priezvisko'],
            'Dátum narodenia' => "$nar (vek $age r.)",
            'Zákonný zástupca (ak má žiadateľ menej ako 18 rokov)' => $age < 18 ? "{$f['zastupca']}, {$f['zastupca_email']}" : '',
            'Adresa žiadateľa' => $f['adresa'],
            'Telefónne číslo žiadateľa' => $f['telefon'],
            'E-mail žiadateľa' => $f['email'],
        ],
        'Súhlas' => [
            'Vyplnením formulára vyjadrujem svoj záujem na členstve v združení a potvrdzujem, že súhlasím so spracovaním a evidenciou osobných údajov pre potreby občianskeho združenia „DIVO občasné združenie o.z.“ v súlade so zákonom č. 18/2018 Z. z. o ochrane osobných údajov a o zmene a doplnení niektorých zákonov. Vyplnením osobných údajov na tomto formulári udeľujem ako dotknutá osoba súhlas so spracovaním svojich osobných údajov v zmysle ustanovení § 14 ods. 1 zákona č. 18/2018 Z. z.' => "súhlasím ($datum)",
        ],
    ];

    $gform = gform_send($f, $age);

    $subject = "Prihláška za člena DIVO: {$f['meno']} {$f['priezvisko']} (č. $cislo)";
    $txt = prihlaska_text($cislo, $rows, $fee, $datum);
    $sent = send_mail(PRIJEMCA, $subject, $txt, $f['email'], prihlaska_html($cislo, $rows, $fee, $datum));
    if (!$sent) {
        $flat = ['Číslo prihlášky' => $cislo];
        foreach ($rows as $polia) foreach ($polia as $k => $v) $flat[$k] = $v === '' ? '–' : $v;
        $flat['Členský príspevok'] = $fee;
        if (formsubmit_send($subject, $flat, $f['email'])) $sent = 'formsubmit';
    }

    with_lock("$DATA/prihlasky.json", function (&$d) use ($f, $sent, $cislo, $gform) { $d[] = $f + ['cislo' => $cislo, 'cas' => date('c'), 'email_odoslany' => $sent, 'google_form' => $gform]; return true; });

    send_mail($f['email'], 'Prihláška do DIVO občasného združenia – č. ' . $cislo,
        "Ahoj {$f['meno']},\n\nďakujeme za prihlášku do DIVO občasného združenia o.z. (č. $cislo).\n"
        . "Členom sa stávaš po zaplatení členského príspevku $fee na účet:\n\n"
        . "IBAN: SK35 7500 0000 0040 2816 3715\nPoznámka: Clensky prispevok {$f['meno']} {$f['priezvisko']}\n\n"
        . "Vidíme sa na trailoch!\nDivo\n", PRIJEMCA);

    if (!$sent && !$gform) out(['ok' => false, 'mailto' => true, 'error' => 'Prihlášku sa nepodarilo odoslať automaticky.']);
    out(['ok' => true, 'via' => $sent, 'google_form' => $gform, 'cislo' => $cislo]);

/* ---------------- REDAKČNÝ SYSTÉM ---------------- */
case 'content':
    $c = read_json("$DATA/content.json");
    out(['ok' => true, 'content' => $c ?: null]);

case 'content_backups':
    if (!check_token(body()['token'] ?? '', ['designer'])) out(['ok' => false, 'auth' => false, 'error' => 'Zálohy spravuje len designer.']);
    $items = [];
    foreach (glob("$DATA/backup/content-*.json") ?: [] as $f) {
        $n = basename($f);
        if (preg_match('/^content-(\d{4})(\d{2})(\d{2})-(\d{2})(\d{2})(\d{2})/', $n, $m))
            $items[] = ['file' => $n, 'time' => "$m[3]. $m[2]. $m[1] $m[4]:$m[5]:$m[6]", 'size' => filesize($f)];
    }
    usort($items, function ($a, $b) { return strcmp($b['file'], $a['file']); });
    out(['ok' => true, 'items' => $items]);

case 'content_restore':
    $b = body();
    if (!check_token($b['token'] ?? '', ['designer'])) out(['ok' => false, 'auth' => false, 'error' => 'Obnovenie zálohy môže urobiť len designer.']);
    $n = basename((string)($b['file'] ?? ''));
    if (!preg_match('/^content-[0-9a-f-]+\.json$/', $n) || !is_file("$DATA/backup/$n")) out(['ok' => false, 'error' => 'Záloha sa nenašla.']);
    $old = json_decode(file_get_contents("$DATA/backup/$n"), true);
    if (!is_array($old)) out(['ok' => false, 'error' => 'Záloha je poškodená.']);
    backup_content();
    with_lock("$DATA/content.json", function (&$d) use ($old) { $d = $old; $d['updated'] = date('c'); return true; });
    out(['ok' => true]);

case 'content_save':
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') out(['ok' => false, 'error' => 'Nesprávna požiadavka.'], 405);
    $b = body();
    $part = (string)($b['part'] ?? '');
    $need = $part === 'brig' ? ['brigada', 'admin', 'designer'] : ($part === 'paths' ? ['designer'] : ['admin', 'designer']);
    if (!in_array($part, ['site', 'brig', 'paths'], true)) out(['ok' => false, 'error' => 'Nesprávna požiadavka.']);
    if (!check_token($b['token'] ?? '', $need)) out(['ok' => false, 'auth' => false, 'error' => 'Na túto úpravu nemáš oprávnenie.']);
    $data = $b['data'] ?? null;
    if (!is_array($data)) out(['ok' => false, 'error' => 'Nesprávna požiadavka.']);
    if (strlen(json_encode($data)) > 3000000) out(['ok' => false, 'error' => 'Obsah je príliš veľký.']);
    backup_content();
    with_lock("$DATA/content.json", function (&$d) use ($part, $data) {
        if ($part === 'paths') { $d['paths'] = array_merge($d['paths'] ?? [], $data); foreach ($d['paths'] as $k => $v) if ($v === null) unset($d['paths'][$k]); }
        else $d[$part] = $data;
        $d['updated'] = date('c');
        return true;
    });
    out(['ok' => true]);

case 'upload':
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') out(['ok' => false, 'error' => 'Nesprávna požiadavka.'], 405);
    if (!check_token($_POST['token'] ?? '', ['admin', 'designer'])) out(['ok' => false, 'auth' => false, 'error' => 'Na nahrávanie nemáš oprávnenie.']);
    $f = $_FILES['file'] ?? null;
    if (!$f || $f['error'] !== UPLOAD_ERR_OK) out(['ok' => false, 'error' => 'Súbor sa nepodarilo nahrať (max. 20 MB).']);
    if ($f['size'] > 20 * 1024 * 1024) out(['ok' => false, 'error' => 'Súbor je väčší ako 20 MB.']);
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
    $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif', 'application/pdf' => 'pdf'][$mime] ?? null;
    if (!$ext) out(['ok' => false, 'error' => 'Povolené sú obrázky JPG, PNG, WebP a PDF.']);
    $dir = __DIR__ . '/uploads';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $name = date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], "$dir/$name")) out(['ok' => false, 'error' => 'Súbor sa nepodarilo uložiť.']);
    out(['ok' => true, 'url' => 'uploads/' . $name, 'size' => $f['size'], 'type' => $mime]);

case 'clenovia':
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') out(['ok' => false, 'error' => 'Nesprávna požiadavka.'], 405);
    $b = body();
    if (!check_token($b['token'] ?? '', ['admin', 'designer'])) out(['ok' => false, 'auth' => false, 'error' => 'Prehľad registrácií vidí len admin alebo designer.']);
    out(['ok' => true, 'items' => array_values(read_json("$DATA/prihlasky.json"))]);

case 'game_scores':
    // rebríček hry DIVO Divočiak – najlepší výsledok každého hráča (podľa mena)
    $all = game_sorted(read_json("$DATA/scores.json"));
    out(['ok' => true, 'items' => array_slice($all, 0, 10), 'total' => count($all)]);

case 'game_score':
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') out(['ok' => false, 'error' => 'Nesprávna požiadavka.'], 405);
    $b = body();
    $name = game_name($b['name'] ?? '');
    if ($name === '') out(['ok' => false, 'error' => 'Zadaj meno (1–20 znakov).']);
    $score = (int)($b['score'] ?? -1);
    if ($score < 0 || $score > GAME_MAX_SCORE) out(['ok' => false, 'error' => 'Neplatné skóre.']);
    if (!rate_limit('game', 20, 600)) out(['ok' => false, 'error' => 'Priveľa zápisov naraz, skús to o chvíľu.']);
    $key = name_key($name);
    if ($key === '') $key = 'h-' . substr(hash('sha256', $name), 0, 12);
    $rec = ['name' => $name, 'score' => $score, 'coins' => max(0, min(9999, (int)($b['coins'] ?? 0))), 'trails' => max(0, min(99, (int)($b['trails'] ?? 0))), 'win' => !empty($b['win']), 'at' => (new DateTime('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z')];
    $res = with_lock("$DATA/scores.json", function (&$d) use ($key, $rec) {
        $old = $d[$key] ?? null; $plays = (int)($old['plays'] ?? 0) + 1; $prev = $old ? (int)$old['score'] : null;
        if (!$old || $rec['score'] > (int)$old['score']) $d[$key] = $rec + ['plays' => $plays];
        else { $d[$key]['plays'] = $plays; $d[$key]['name'] = $rec['name']; }
        if (count($d) > GAME_MAX_PLAYERS) { $s = game_sorted($d, true); $d = array_slice($s, 0, GAME_MAX_PLAYERS, true); }
        return ['prev' => $prev, 'improved' => !$old || $rec['score'] > (int)$old['score']];
    });
    $all = game_sorted(read_json("$DATA/scores.json"));
    $rank = 0; foreach ($all as $i => $r) if (name_key($r['name']) === $key || ($r['key'] ?? '') === $key) { $rank = $i + 1; break; }
    out(['ok' => true, 'rank' => $rank, 'total' => count($all), 'best' => $rank === 1, 'prev' => $res['prev'], 'improved' => $res['improved'], 'items' => array_slice($all, 0, 10)]);

default:
    out(['ok' => false, 'error' => 'Neznáma akcia.'], 404);
}
