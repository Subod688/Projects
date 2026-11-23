<?php
// ===== Online shop list for "All Nepal Enterprises Bills" =====
//
// For web hosting with PHP (cPanel, Hostinger and most paid hosting). No database needed.
//   1. Upload this file into the SAME folder as wholesale_billing.html.
//   2. That's it. The app already points to 'shops.php'.
//
// It keeps only shop name, address and phone, in a small file next to it (shops-data.php).
// About 70 bytes per shop: 1,000 shops is roughly 70 KB.
// The data file cannot be opened in a browser; to look at it, use your hosting's File Manager.
// To start over, delete shops-data.php.

const SYNC_KEY  = 'ane-Vj4mbN6iHEkQamzB';                  // must match SHOP_SYNC_KEY in wholesale_billing.html
const DATA_FILE = __DIR__ . '/shops-data.php';
const GUARD     = "<?php http_response_code(404); exit; ?>\n"; // first line of the data file: blocks opening it in a browser
const MAX_SHOPS = 20000;
const MAX_BODY  = 1000000;                                  // largest request accepted, in bytes

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit;

function reply($data, $code = 200) {
  http_response_code($code);
  echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
  exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') reply(['ok' => false, 'error' => 'This address saves shop details for the billing app.'], 405);

$in = json_decode((string) file_get_contents('php://input', false, null, 0, MAX_BODY), true);
if (!is_array($in) || !hash_equals(SYNC_KEY, (string) ($in['key'] ?? ''))) reply(['ok' => false, 'error' => 'key'], 403);

// Same rules as the app: same shop when the letters and numbers match, whatever the spaces, dots or capitals
function lower($s) { return function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s); }
function cut($s, $n) { return function_exists('mb_substr') ? mb_substr($s, 0, $n, 'UTF-8') : substr($s, 0, $n); }
function shop_key($s) { return (string) preg_replace('/[^\p{L}\p{M}\p{N}]+/u', '', lower((string) $s)); }
function clean($s, $n) { return cut(trim((string) preg_replace('/\s+/u', ' ', (string) $s)), $n); }

// One request at a time, so two phones saving together never lose a shop
$lock = fopen(__DIR__ . '/shops-data.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX)) reply(['ok' => false, 'error' => 'busy'], 503);

$txt  = is_file(DATA_FILE) ? (string) file_get_contents(DATA_FILE) : '';
$rows = json_decode(substr($txt, strlen(GUARD)) ?: '[]', true);
$map  = [];
foreach (is_array($rows) ? $rows : [] as $r) {
  if (is_array($r) && ($k = shop_key($r[0] ?? '')) !== '') $map[$k] = [(string) $r[0], (string) ($r[1] ?? ''), (string) ($r[2] ?? ''), (int) ($r[3] ?? 0)];
}

// Add or update shops: newer details win; an empty box never wipes a known address or phone
$changed = false;
foreach ((is_array($in['shops'] ?? null) ? $in['shops'] : []) as $r) {
  if (!is_array($r)) continue;
  $n = clean($r[0] ?? '', 80);
  $k = shop_key($n);
  if ($k === '') continue;
  $a = clean($r[1] ?? '', 120);
  $p = clean($r[2] ?? '', 30);
  $t = (int) ($r[3] ?? 0);
  $old = $map[$k] ?? null;
  if (!$old) {
    if (count($map) >= MAX_SHOPS) continue;
    $new = [$n, $a, $p, $t];
  } elseif ($t >= $old[3]) {
    $new = [$n, $a !== '' ? $a : $old[1], $p !== '' ? $p : $old[2], $t];
  } else {
    $new = [$old[0], $old[1] !== '' ? $old[1] : $a, $old[2] !== '' ? $old[2] : $p, $old[3]];
  }
  if ($old && $new[0] === $old[0] && $new[1] === $old[1] && $new[2] === $old[2]) continue;
  $map[$k] = $new;
  $changed = true;
}

$list = array_values($map);
usort($list, function ($x, $y) { return strcasecmp($x[0], $y[0]); });
$json = json_encode($list, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
if ($json === false) { flock($lock, LOCK_UN); reply(['ok' => false, 'error' => 'data'], 500); }

if ($changed) {                                             // write a new file, then swap it in: never half-written
  $tmp = __DIR__ . '/shops-data.tmp.php';
  if (file_put_contents($tmp, GUARD . $json) === false || !rename($tmp, DATA_FILE)) {
    flock($lock, LOCK_UN);
    reply(['ok' => false, 'error' => 'This folder is not writable. Allow PHP to write files here.'], 500);
  }
}
flock($lock, LOCK_UN);

// The full list goes back only when the phone's copy is out of date
$v = substr(md5($json), 0, 12);
reply(($in['v'] ?? '') === $v ? ['ok' => true, 'v' => $v] : ['ok' => true, 'v' => $v, 'shops' => $list]);
