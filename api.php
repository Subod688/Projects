<?php
// ===== Online data for "Billing System": people, orders and shop list =====
//
// For web hosting with PHP (InfinityFree, Hostinger, cPanel...). No database needed.
//   Upload this file into the SAME folder as index.html. That's it.
//
// WHO CAN USE THE APP
//   Super admin (SUPER_ADMIN below): everything, and adds or removes admins.
//   Admins: the order screen and the office; add or remove marketers; approve new devices.
//   Marketers (names on the marketer list): the order screen only.
//   Anyone else is told they are not part of this system.
//
// REGISTERING
//   Each person registers once on each phone or computer by typing their name.
//   The first device for a name is accepted at once. Another device for the same name waits
//   until an admin approves it in Office > Settings > Devices, so someone who only knows a name can't get in.
//   >>> Bikash: register first, right after uploading, so nobody else can take your name. <<<
//
// LOST ACCESS? (for example all of Bikash's devices are lost)
//   In your hosting's File Manager, create an empty file named  reset-bikash.txt  next to this file,
//   then open the website and register as Bikash again. The file deletes itself.
//
// FILES IT CREATES NEXT TO IT (they cannot be opened in a browser)
//   orders-data.php : bills waiting to be printed, the next bill number, marketers, admins, registered devices
//   shops-data.php  : shop name, address and phone

const SYNC_KEY      = 'ane-Vj4mbN6iHEkQamzB';                 // must match SYNC_KEY in index.html
const SUPER_ADMIN   = 'Bikash Dulal';
const NEW_DEVICES_NEED_APPROVAL = true;                        // false: any device can register with a listed name
const ORDERS_FILE   = __DIR__ . '/orders-data.php';
const SHOPS_FILE    = __DIR__ . '/shops-data.php';
const RESET_FILE    = __DIR__ . '/reset-bikash.txt';
const GUARD         = "<?php http_response_code(404); exit; ?>\n"; // first line of each data file: blocks opening it in a browser
const MAX_SHOPS     = 20000;
const MAX_ORDERS    = 5000;
const MAX_DEVICES   = 500;
const MAX_BODY      = 3000000;                                 // largest request accepted, in bytes
const KEEP_GONE_DAYS = 60;                                     // remember deleted bills this long, so a late resend can't bring one back

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
if ($_SERVER['REQUEST_METHOD'] !== 'POST') reply(['ok' => false, 'error' => 'This address saves orders for the billing app.'], 405);

$in = json_decode((string) file_get_contents('php://input', false, null, 0, MAX_BODY), true);
if (!is_array($in) || !hash_equals(SYNC_KEY, (string) ($in['key'] ?? ''))) reply(['ok' => false, 'error' => 'key'], 403);

// ---- helpers ----
function lower($s) { return function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s); }
function cut($s, $n) { return function_exists('mb_substr') ? mb_substr($s, 0, $n, 'UTF-8') : substr($s, 0, $n); }
function key_of($s) { return (string) preg_replace('/[^\p{L}\p{M}\p{N}]+/u', '', lower((string) $s)); }   // same name whatever the spaces, dots or capitals
function clean($s, $n) { return is_scalar($s) ? cut(trim((string) preg_replace('/\s+/u', ' ', (string) $s)), $n) : ''; }
function num($v) { return is_numeric($v) ? round((float) $v, 4) : 0; }
function enc($v) { return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE); }
function read_data($file) {
  $t = is_file($file) ? (string) file_get_contents($file) : '';
  return json_decode(substr($t, strlen(GUARD)) ?: 'null', true);
}
function write_data($file, $json) {                            // write a new file, then swap it in: never half-written
  $tmp = substr($file, 0, -4) . '.tmp.php';
  return $json !== false && file_put_contents($tmp, GUARD . $json) !== false && rename($tmp, $file);
}
function names_of($v, $skip = '') {                            // a clean list of names, no repeats
  if (!is_array($v)) return null;
  $out = []; $seen = [];
  foreach (array_slice($v, 0, 100) as $n) {
    $n = clean($n, 40); $k = key_of($n);
    if ($k === '' || isset($seen[$k]) || ($skip !== '' && $k === key_of($skip))) continue;
    $seen[$k] = true; $out[] = $n;
  }
  return $out;
}
// An order as the app saves it, with every field checked and trimmed
function clean_order($o) {
  if (!is_array($o) || !is_int($o['id'] ?? null) || $o['id'] <= 0) return null;
  $items = [];
  foreach (array_slice(is_array($o['items'] ?? null) ? $o['items'] : [], 0, 300) as $i) {
    if (!is_array($i) || ($n = clean($i['n'] ?? '', 120)) === '') continue;
    $items[] = ['n' => $n, 'q' => num($i['q'] ?? 0), 'r' => num($i['r'] ?? 0), 'u' => clean($i['u'] ?? '', 10)];
  }
  if (!$items) return null;
  $out = ['id' => $o['id'], 'ts' => (int) ($o['ts'] ?? 0), 'staff' => clean($o['staff'] ?? '', 60), 'shop' => clean($o['shop'] ?? '', 80),
          'addr' => clean($o['addr'] ?? '', 120), 'phone' => clean($o['phone'] ?? '', 30), 'items' => $items];
  if (($d = num($o['disc'] ?? 0)) > 0) $out['disc'] = min(100, $d);
  if (!empty($o['edited'])) $out['edited'] = true;
  if (!empty($o['editedTs'])) $out['editedTs'] = (int) $o['editedTs'];
  return $out;
}

// One request at a time, so two phones saving together never lose anything
$lock = fopen(__DIR__ . '/data.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX)) reply(['ok' => false, 'error' => 'busy'], 503);
function fail($msg) { global $lock; flock($lock, LOCK_UN); reply(['ok' => false, 'error' => $msg], 500); }
$notWritable = 'This folder is not writable. Allow PHP to write files here.';

// ===== People and devices =====
$od = read_data(ORDERS_FILE);
$od = is_array($od) ? $od : [];
$staff  = names_of($od['staff'] ?? null);                      // marketers (null: not set up yet)
$admins = names_of($od['admins'] ?? null, SUPER_ADMIN) ?? [];
$devs   = is_array($od['dev'] ?? null) ? $od['dev'] : [];      // device code => [name, 'ok' | 'wait', time, label]
$changed = false;                                              // orders-data.php needs saving

// Name => [name as on the list, role], or null if the name is on no list
function find_person($name) {
  global $staff, $admins;
  $k = key_of($name);
  if ($k === '') return null;
  if ($k === key_of(SUPER_ADMIN)) return [SUPER_ADMIN, 'super'];
  foreach ($admins as $a) if (key_of($a) === $k) return [$a, 'admin'];
  foreach ($staff ?? [] as $s) if (key_of($s) === $k) return [$s, 'marketer'];
  return null;
}

if (is_file(RESET_FILE)) {                                     // Bikash lost access: forget his devices once
  foreach ($devs as $t => $d) if (key_of($d[0] ?? '') === key_of(SUPER_ADMIN)) unset($devs[$t]);
  @unlink(RESET_FILE); $changed = true;
}

$token = strtolower((string) ($in['dev'] ?? ''));
if (!preg_match('/^[a-f0-9]{24,64}$/', $token)) $token = '';
$regFail = false;
if (isset($in['reg'])) {                                       // someone typed their name on this device
  $p = find_person($in['reg']);
  if (!$p || $token === '') $regFail = true;
  else {
    $k = key_of($p[0]);
    if (!isset($devs[$token]) || key_of($devs[$token][0]) !== $k) {
      $taken = false;
      foreach ($devs as $t => $d) if ($t !== $token && key_of($d[0]) === $k && $d[1] === 'ok') { $taken = true; break; }
      if (!isset($devs[$token]) && count($devs) >= MAX_DEVICES) $regFail = true;
      else {
        $devs[$token] = [$p[0], ($taken && NEW_DEVICES_NEED_APPROVAL) ? 'wait' : 'ok', time(), clean($in['label'] ?? '', 40)];
        $changed = true;
      }
    }
  }
}

// Who is asking
$me = null; $member = false; $role = '';
if ($token !== '' && isset($devs[$token])) {
  $p = find_person($devs[$token][0]);
  if ($p) {
    $role = $p[1]; $member = $devs[$token][1] === 'ok';
    $me = ['name' => $p[0], 'role' => $role, 'status' => $devs[$token][1]];
    if ($devs[$token][0] !== $p[0]) { $devs[$token][0] = $p[0]; $changed = true; }
  } else $me = ['status' => 'notmember'];
}
if ($regFail) { $me = ['status' => 'notmember']; $member = false; $role = ''; }
$isAdmin = $member && ($role === 'admin' || $role === 'super');
$isSuper = $member && $role === 'super';
$skipped = 0;                                                  // changes this device is not allowed to make

// ===== Shop list (members only): newer details win; an empty box never wipes a known address or phone =====
$rows = read_data(SHOPS_FILE);
$map = [];
foreach (is_array($rows) ? $rows : [] as $r) {
  if (is_array($r) && ($k = key_of($r[0] ?? '')) !== '') $map[$k] = [(string) $r[0], (string) ($r[1] ?? ''), (string) ($r[2] ?? ''), (int) ($r[3] ?? 0)];
}
$shopsChanged = false;
$inShops = is_array($in['shops'] ?? null) ? $in['shops'] : [];
if (!$member && $inShops) $skipped += count($inShops);
foreach ($member ? $inShops : [] as $r) {
  if (!is_array($r)) continue;
  $n = clean($r[0] ?? '', 80);
  if (($k = key_of($n)) === '') continue;
  $a = clean($r[1] ?? '', 120); $p = clean($r[2] ?? '', 30); $t = (int) ($r[3] ?? 0);
  $old = $map[$k] ?? null;
  if (!$old) { if (count($map) >= MAX_SHOPS) continue; $new = [$n, $a, $p, $t]; }
  elseif ($t >= $old[3]) $new = [$n, $a !== '' ? $a : $old[1], $p !== '' ? $p : $old[2], $t];
  else $new = [$old[0], $old[1] !== '' ? $old[1] : $a, $old[2] !== '' ? $old[2] : $p, $old[3]];
  if ($old && $new[0] === $old[0] && $new[1] === $old[1] && $new[2] === $old[2]) continue;
  $map[$k] = $new; $shopsChanged = true;
}
$shopList = array_values($map);
usort($shopList, function ($x, $y) { return strcasecmp($x[0], $y[0]); });
$shopJson = enc($shopList);
if ($shopJson === false) fail('data');
if ($shopsChanged && !write_data(SHOPS_FILE, $shopJson)) fail($notWritable);

// ===== Orders =====
$next = max(1, (int) ($od['n'] ?? 1));                         // next bill number: never reused
$orders = [];
foreach (is_array($od['o'] ?? null) ? $od['o'] : [] as $o) if (is_array($o) && isset($o['id'])) $orders[(string) $o['id']] = $o;
$gone = is_array($od['d'] ?? null) ? $od['d'] : [];            // deleted bills: id => time
$added = [];
foreach (array_slice(is_array($in['ops'] ?? null) ? $in['ops'] : [], 0, 3000) as $op) {
  if (!is_array($op)) continue;
  $kind = $op['op'] ?? '';
  if ($kind === 'add') {                                       // new order: any registered person
    if (!$member) { $skipped++; continue; }
    if (!($o = clean_order($op['o'] ?? null))) continue;
    if ($role === 'marketer') $o['staff'] = $me['name'];       // a marketer's orders always carry their own name
    $k = (string) $o['id'];
    if (isset($orders[$k])) { $added[$k] = $orders[$k]['billNo']; continue; }   // sent twice: same bill
    if (isset($gone[$k]) || count($orders) >= MAX_ORDERS) continue;             // already printed and deleted
    $o['billNo'] = $next++;
    $orders[$k] = $o; $added[$k] = $o['billNo']; $changed = true;
  } elseif ($kind === 'put') {                                 // office changed a bill
    if (!$isAdmin) { $skipped++; continue; }
    if (!($o = clean_order($op['o'] ?? null))) continue;
    $k = (string) $o['id'];
    if (!isset($orders[$k])) continue;                         // deleted meanwhile: stays deleted
    $o['billNo'] = $orders[$k]['billNo']; $o['ts'] = $orders[$k]['ts'];
    if ($o != $orders[$k]) { $orders[$k] = $o; $changed = true; }
  } elseif ($kind === 'del') {                                 // office deleted a bill (or it was printed)
    if (!$isAdmin) { $skipped++; continue; }
    $k = (string) (int) ($op['id'] ?? 0);
    if (!isset($orders[$k])) continue;
    $ver = (int) ($orders[$k]['editedTs'] ?? 0) ?: (int) ($orders[$k]['ts'] ?? 0);
    if (isset($op['v']) && (int) $op['v'] !== $ver) continue;  // changed after it was printed: keep it
    unset($orders[$k]); $gone[$k] = time(); $changed = true;
  }
}

// ===== Changes to people (office only) =====
$staffNone = $staff === null;
if (isset($in['setStaff'])) {
  if (!$isAdmin) $skipped++;
  elseif (($names = names_of($in['setStaff'])) && $names !== $staff) { $staff = $names; $staffNone = false; $changed = true; }
}
if (isset($in['setAdmins'])) {
  if (!$isSuper) $skipped++;
  elseif (($names = names_of($in['setAdmins'], SUPER_ADMIN)) !== null && $names !== $admins) { $admins = $names; $changed = true; }
}
foreach (is_array($in['devAct'] ?? null) ? $in['devAct'] : [] as $a) {   // approve or remove a device
  if (!$isAdmin) { $skipped++; continue; }
  $id = (string) ($a['id'] ?? ''); $act = $a['act'] ?? '';
  foreach ($devs as $t => $d) {
    if (substr(sha1($t), 0, 10) !== $id) continue;
    $p = find_person($d[0]);
    if ($p && $p[1] === 'super' && !$isSuper) continue;        // only Bikash looks after his own devices
    if ($act === 'ok') { $devs[$t][1] = 'ok'; $changed = true; }
    elseif ($act === 'remove') { unset($devs[$t]); $changed = true; }
  }
}
foreach ($devs as $t => $d) if (!find_person($d[0] ?? '')) { unset($devs[$t]); $changed = true; }   // people taken off the lists

// ===== Save =====
$orderList = array_values($orders);
usort($orderList, function ($x, $y) { return $x['billNo'] - $y['billNo']; });
$orderJson = enc($orderList);
if ($orderJson === false) fail('data');
if ($changed) {
  $cutoff = time() - KEEP_GONE_DAYS * 86400;
  $gone = array_filter($gone, function ($t) use ($cutoff) { return $t >= $cutoff; });
  $save = ['n' => $next, 'o' => $orderList, 'd' => (object) $gone, 'admins' => $admins, 'dev' => (object) $devs];
  if ($staff !== null) $save['staff'] = $staff;
  if (!write_data(ORDERS_FILE, enc($save))) fail($notWritable);
}
flock($lock, LOCK_UN);
if ($regFail) usleep(500000);                                  // slows down anyone guessing names

// ===== Reply: lists go back only when the device's copy is out of date =====
$out = ['ok' => true, 'me' => $me, 'added' => (object) $added];
if ($skipped) $out['skipped'] = $skipped;
if ($member) {
  $v = substr(md5($shopJson), 0, 12);
  $out['v'] = $v;
  if (($in['v'] ?? '') !== $v) $out['shops'] = $shopList;
}
if ($isAdmin) {
  $list = [];
  foreach ($devs as $t => $d) {
    $p = find_person($d[0]);
    $list[] = ['id' => substr(sha1($t), 0, 10), 'name' => $d[0], 'role' => $p ? $p[1] : '', 'status' => $d[1],
               't' => (int) $d[2], 'label' => (string) ($d[3] ?? ''), 'mine' => $t === $token];
  }
  usort($list, function ($x, $y) { return [$x['status'] === 'ok' ? 1 : 0, lower($x['name'])] <=> [$y['status'] === 'ok' ? 1 : 0, lower($y['name'])]; });   // waiting first
  $people = ['staff' => $staff ?? [], 'admins' => $admins, 'super' => SUPER_ADMIN, 'devices' => $list];
  $pv = substr(md5(enc($people)), 0, 12);
  $out['pv'] = $pv;
  if (($in['pv'] ?? '') !== $pv) $out['people'] = $people;
  if ($staffNone) $out['staffNone'] = true;
  if (!empty($in['want'])) {                                   // the bills
    $ov = substr(md5($orderJson), 0, 12);
    $out['ov'] = $ov;
    if (($in['ov'] ?? '') !== $ov) $out['orders'] = $orderList;
  }
}
reply($out);
