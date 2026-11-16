<?php
/**
 * Droitwich United Charities – website (cPanel / PHP edition)
 * Password-protected site, Global Admin user management, maintenance mode and audit log.
 * Built by Saltway Digital. Requires PHP 7.4 or newer.
 */
declare(strict_types=1);

const APP_VERSION = '1.0.0-php';
const APP_DIR = __DIR__ . '/app';

// ---------- data folder (outside public_html when possible) ----------
function data_dir(): string {
    static $dir = null;
    if ($dir !== null) return $dir;
    $outside = dirname(__DIR__) . '/duc-data';
    $inside  = __DIR__ . '/duc-data';
    if (is_dir($outside) || (is_writable(dirname(__DIR__)) && @mkdir($outside, 0700, true))) {
        $dir = $outside;
    } else {
        if (!is_dir($inside)) @mkdir($inside, 0700, true);
        $dir = $inside;
    }
    // belt and braces: block web access if the folder ever ends up inside public_html
    $ht = $dir . '/.htaccess';
    if (!file_exists($ht)) @file_put_contents($ht, "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
    if (!file_exists($dir . '/index.html')) @file_put_contents($dir . '/index.html', '');
    return $dir;
}
function dpath(string $n): string { return data_dir() . '/' . $n; }

function read_json(string $name, $default) {
    $p = dpath($name);
    if (!is_file($p)) return $default;
    $fh = fopen($p, 'rb');
    if (!$fh) return $default;
    flock($fh, LOCK_SH);
    $s = stream_get_contents($fh);
    flock($fh, LOCK_UN); fclose($fh);
    $v = json_decode((string)$s, true);
    return $v === null ? $default : $v;
}
function write_json(string $name, $value): bool {
    $p = dpath($name);
    $tmp = $p . '.' . bin2hex(random_bytes(4)) . '.tmp';
    $json = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false || file_put_contents($tmp, $json, LOCK_EX) === false) return false;
    @chmod($tmp, 0600);
    return rename($tmp, $p);
}
/** Run $fn on a JSON file under an exclusive lock; $fn receives the value by reference. */
function with_lock(string $name, $default, callable $fn) {
    $lock = fopen(dpath($name . '.lock'), 'c');
    flock($lock, LOCK_EX);
    $v = read_json($name, $default);
    $result = $fn($v);
    write_json($name, $v);
    flock($lock, LOCK_UN); fclose($lock);
    return $result;
}

// ---------- settings / users ----------
function default_settings(): array {
    return ['requireLogin' => true, 'maintenance' => false, 'maintTitle' => "We'll be back soon",
        'maintMessage' => 'Our website is currently undergoing scheduled maintenance. Thank you for your patience.',
        'maintUntil' => '', 'sessionHours' => 12];
}
function settings(): array { return array_merge(default_settings(), read_json('settings.json', [])); }
function users(): array { return read_json('users.json', []); }
function find_user(array $users, string $key, string $val): ?array {
    foreach ($users as $u) if (($u[$key] ?? null) === $val) return $u;
    return null;
}
function public_user(array $u): array {
    return ['id' => $u['id'], 'username' => $u['username'], 'name' => $u['name'], 'role' => $u['role'],
        'disabled' => !empty($u['disabled']), 'mustChange' => !empty($u['mustChange']),
        'created' => $u['created'] ?? '', 'lastLogin' => $u['lastLogin'] ?? ''];
}
function role_label(string $r): string { return $r === 'admin' ? 'Global Admin' : ($r === 'editor' ? 'Editor' : 'Viewer'); }
function valid_role($r): bool { return in_array($r, ['admin', 'editor', 'viewer'], true); }
function valid_username(string $u): bool { return (bool)preg_match('/^[a-z0-9._@-]{3,64}$/', $u); }
function password_problem(string $pw): ?string {
    if (mb_strlen($pw) < 10) return 'Password must be at least 10 characters';
    if (strlen($pw) > 200) return 'Password is too long';
    return null;
}
function now_iso(): string { return gmdate('Y-m-d\TH:i:s\Z'); }
function active_admins(array $users): int {
    $c = 0; foreach ($users as $u) if ($u['role'] === 'admin' && empty($u['disabled'])) $c++; return $c;
}
function clip($s, int $n): string { $s = trim((string)$s); return mb_substr($s, 0, $n); }

// ---------- audit log ----------
function audit(string $user, string $action, string $details = ''): void {
    $e = ['time' => now_iso(), 'user' => $user, 'ip' => client_ip(), 'action' => $action, 'details' => $details];
    @file_put_contents(dpath('audit.log'), json_encode($e, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
}
function read_audit(): array {
    $p = dpath('audit.log'); $out = [];
    if (!is_file($p)) return $out;
    $fh = fopen($p, 'rb'); flock($fh, LOCK_SH);
    while (($line = fgets($fh)) !== false) { $e = json_decode($line, true); if (is_array($e)) $out[] = $e; }
    flock($fh, LOCK_UN); fclose($fh);
    return $out;
}

// ---------- request helpers ----------
function client_ip(): string { return (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'); }
function is_https(): bool {
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') == 443)
        || strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
}
function redirect(string $to): void { header('Location: ' . $to, true, 303); exit; }
function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function safe_next($n): string {
    $n = (string)$n;
    if ($n === '' || $n[0] !== '/' || substr($n, 0, 2) === '//' || strpos($n, '\\') !== false) return '/';
    return $n;
}
function json_out(int $status, $v): void {
    http_response_code($status);
    header('Content-Type: application/json'); header('Cache-Control: no-store');
    echo json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); exit;
}
function api_err(int $status, string $msg): void { json_out($status, ['error' => $msg]); }
function body_json(): array {
    $raw = file_get_contents('php://input');
    if ($raw !== false && strlen($raw) > 8 * 1024 * 1024) api_err(413, 'Content too large (max 8 MB)');
    $v = json_decode((string)$raw, true);
    return is_array($v) ? $v : [];
}

// ---------- sessions ----------
const COOKIE = 'duc_session';
function current_user(): ?array {
    static $cached = false;
    if ($cached !== false) return $cached;
    $cached = null;
    $tok = $_COOKIE[COOKIE] ?? '';
    if (!is_string($tok) || !preg_match('/^[a-f0-9]{64}$/', $tok)) return null;
    $set = settings();
    $uid = with_lock('sessions.json', [], function (&$ss) use ($tok, $set) {
        $now = time();
        foreach ($ss as $k => $s) if (($s['expires'] ?? 0) < $now) unset($ss[$k]);
        $key = hash('sha256', $tok);
        if (!isset($ss[$key])) return null;
        $ss[$key]['expires'] = $now + (int)$set['sessionHours'] * 3600;
        return $ss[$key]['userId'];
    });
    if (!$uid) return null;
    $u = find_user(users(), 'id', $uid);
    if (!$u || !empty($u['disabled'])) { revoke_sessions($uid); return null; }
    $u['_tok'] = $tok;
    return $cached = $u;
}
function start_session(array $u): void {
    $tok = bin2hex(random_bytes(32));
    $hrs = (int)settings()['sessionHours'];
    with_lock('sessions.json', [], function (&$ss) use ($tok, $u, $hrs) {
        $ss[hash('sha256', $tok)] = ['userId' => $u['id'], 'created' => now_iso(), 'expires' => time() + $hrs * 3600];
    });
    setcookie(COOKIE, $tok, ['expires' => time() + $hrs * 3600, 'path' => '/', 'secure' => is_https(), 'httponly' => true, 'samesite' => 'Lax']);
}
function revoke_sessions(string $uid, ?string $keepTok = null): void {
    $keep = $keepTok ? hash('sha256', $keepTok) : null;
    with_lock('sessions.json', [], function (&$ss) use ($uid, $keep) {
        foreach ($ss as $k => $s) if ($s['userId'] === $uid && $k !== $keep) unset($ss[$k]);
    });
}
function end_session(?string $tok): void {
    if ($tok) with_lock('sessions.json', [], function (&$ss) use ($tok) { unset($ss[hash('sha256', $tok)]); });
    setcookie(COOKIE, '', ['expires' => time() - 3600, 'path' => '/', 'secure' => is_https(), 'httponly' => true, 'samesite' => 'Lax']);
}

// ---------- pages ----------
function render_page(int $status, string $title, string $body, bool $wide = false): void {
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8'); header('Cache-Control: no-store');
    $css = file_get_contents(APP_DIR . '/page.css');
    echo '<!DOCTYPE html><html lang="en-GB"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'
        . h($title) . ' – Droitwich United Charities</title><link rel="icon" href="/assets/duc-logo.webp">'
        . '<link href="https://fonts.googleapis.com/css2?family=Libre+Baskerville:wght@700&family=Source+Sans+3:wght@400;600;700&display=swap" rel="stylesheet">'
        . '<style>' . $css . '</style></head><body><main class="card' . ($wide ? ' wide' : '') . '"><img class="logo" src="/assets/duc-logo.webp" alt="Droitwich United Charities">'
        . $body . '</main></body></html>';
    exit;
}

function login_page(string $msg = '', string $next = '/', int $status = 200): void {
    $b = '<h1>Members sign in</h1><p>This website is private. Please sign in with the account your administrator gave you.</p>';
    if ($msg) $b .= '<div class="err">' . h($msg) . '</div>';
    if (($_GET['out'] ?? '') === '1') $b .= '<div class="ok">You have been signed out.</div>';
    $b .= '<form method="post" action="/login"><input type="hidden" name="next" value="' . h(safe_next($next)) . '">'
        . '<label for="u">Username</label><input id="u" name="username" autocomplete="username" required autofocus>'
        . '<label for="p">Password</label><input id="p" name="password" type="password" autocomplete="current-password" required>'
        . '<button type="submit">Sign in</button></form><p class="small">Forgotten your password? Ask your Global Admin to reset it.</p>';
    render_page($status, 'Sign in', $b);
}

function maintenance_page(?array $u): void {
    $s = settings();
    $b = '<h1>' . h($s['maintTitle']) . '</h1><p>' . nl2br(h($s['maintMessage'])) . '</p>';
    if ($s['maintUntil'] !== '') $b .= '<span class="until">Expected back: ' . h($s['maintUntil']) . '</span>';
    $b .= '<p>Droitwich United Charities · Registered charity 205536<br>01905 775226</p><div class="bar"></div>';
    $b .= $u ? '<p class="small">Signed in as ' . h($u['name']) . ' · <a href="/logout">Sign out</a></p>' : '<p class="small"><a href="/login">Staff sign in</a></p>';
    header('Retry-After: 3600');
    render_page(503, 'Under maintenance', $b, true);
}

function setup_code(): string {
    $p = dpath('setup-code.txt');
    if (!is_file($p)) {
        $code = strtoupper(substr(bin2hex(random_bytes(6)), 0, 4) . '-' . substr(bin2hex(random_bytes(6)), 0, 4) . '-' . substr(bin2hex(random_bytes(6)), 0, 4));
        file_put_contents($p, "DUC website setup code (delete this file once setup is done – it's removed automatically):\n\n" . $code . "\n");
        @chmod($p, 0600);
    }
    preg_match('/([A-F0-9]{4}-[A-F0-9]{4}-[A-F0-9]{4})/', (string)file_get_contents($p), $m);
    return $m[1] ?? '';
}

function handle_setup(): void {
    if (count(users()) > 0) redirect('/');
    $code = setup_code();
    $where = (strpos(data_dir(), dirname(__DIR__) . '/duc-data') === 0) ? 'duc-data/setup-code.txt (next to public_html)' : 'public_html/duc-data/setup-code.txt';
    $form = function (string $msg = '') use ($where) {
        $b = '<h1>Welcome – first-time setup</h1><p>Create the <b>Global Admin</b> account. This account can add users, turn on maintenance mode and see the audit log.</p>'
            . '<p style="text-align:left;background:#e8eef8;border-radius:10px;padding:10px 12px;font-size:14px">To prove you own this website, open cPanel <b>File Manager</b> and copy the code from <b>' . h($where) . '</b>.</p>';
        if ($msg) $b .= '<div class="err">' . h($msg) . '</div>';
        $b .= '<form method="post" action="/setup">'
            . '<label for="c">Setup code</label><input id="c" name="code" required autocomplete="off" placeholder="XXXX-XXXX-XXXX" value="' . h($_POST['code'] ?? '') . '">'
            . '<label for="n">Your name</label><input id="n" name="name" required value="' . h($_POST['name'] ?? '') . '">'
            . '<label for="u">Username</label><input id="u" name="username" autocomplete="username" required value="' . h($_POST['username'] ?? '') . '">'
            . '<label for="p">Password (at least 10 characters)</label><input id="p" name="password" type="password" autocomplete="new-password" required>'
            . '<label for="p2">Confirm password</label><input id="p2" name="password2" type="password" autocomplete="new-password" required>'
            . '<button type="submit">Create Global Admin</button></form>';
        render_page(200, 'Setup', $b);
    };
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') $form();
    if (lockout_check()) $form('Too many wrong attempts. Try again in 15 minutes.');
    if (!hash_equals($code, strtoupper(trim((string)($_POST['code'] ?? ''))))) {
        record_fail('(setup)', 'wrong setup code');
        $form('That setup code is not right.');
    }
    $name = clip($_POST['name'] ?? '', 120);
    $un = strtolower(trim((string)($_POST['username'] ?? '')));
    $pw = (string)($_POST['password'] ?? '');
    if ($name === '' || !valid_username($un)) $form('Enter your name and a username of 3–64 characters (letters, numbers, . _ - @).');
    if ($e = password_problem($pw)) $form($e);
    if ($pw !== ($_POST['password2'] ?? '')) $form("The two passwords don't match.");
    $u = ['id' => bin2hex(random_bytes(8)), 'username' => $un, 'name' => $name, 'role' => 'admin',
        'hash' => password_hash($pw, PASSWORD_DEFAULT), 'disabled' => false, 'mustChange' => false, 'created' => now_iso(), 'lastLogin' => now_iso()];
    $created = with_lock('users.json', [], function (&$list) use ($u) {
        if (count($list) > 0) return false;
        $list[] = $u; return true;
    });
    if (!$created) redirect('/');
    @unlink(dpath('setup-code.txt'));
    audit($un, 'setup.admin_created', 'Global Admin account created during first-time setup');
    start_session($u);
    redirect('/');
}

// ---------- brute-force protection ----------
function lockout_check(): bool {
    $f = read_json('fails.json', []);
    $ip = client_ip();
    return isset($f[$ip]['until']) && $f[$ip]['until'] > time();
}
function record_fail(string $user, string $reason): void {
    $ip = client_ip();
    $locked = with_lock('fails.json', [], function (&$f) use ($ip) {
        $now = time();
        foreach ($f as $k => $v) if (($v['last'] ?? 0) < $now - 3600 && ($v['until'] ?? 0) < $now) unset($f[$k]);
        if (!isset($f[$ip]) || ($f[$ip]['last'] ?? 0) < $now - 1800) $f[$ip] = ['count' => 0, 'until' => 0, 'last' => $now];
        $f[$ip]['count']++; $f[$ip]['last'] = $now;
        if ($f[$ip]['count'] >= 5) { $f[$ip]['until'] = $now + 900; $f[$ip]['count'] = 0; return true; }
        return false;
    });
    audit($user, 'login.failed', $reason);
    if ($locked) audit($user, 'login.locked', '5 failed attempts – IP locked for 15 minutes');
    usleep(400000);
}
function clear_fails(): void { $ip = client_ip(); with_lock('fails.json', [], function (&$f) use ($ip) { unset($f[$ip]); }); }

function handle_login(): void {
    if (count(users()) === 0) redirect('/setup');
    $next = (string)($_POST['next'] ?? $_GET['next'] ?? '/');
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        if (current_user()) redirect(safe_next($next));
        login_page('', $next);
    }
    $un = strtolower(trim((string)($_POST['username'] ?? '')));
    if (lockout_check()) {
        audit($un, 'login.blocked', 'Sign-in attempt while locked out');
        login_page('Too many failed attempts. Try again in 15 minutes.', $next, 429);
    }
    $pw = (string)($_POST['password'] ?? '');
    $u = find_user(users(), 'username', $un);
    $ok = $u ? password_verify($pw, $u['hash']) : password_verify($pw, '$2y$10$EEN6ofROu5tg2obXB9ICuuZd4ejiwyRvPne.eAUBdkPkZGZuNeZvi');
    if (!$ok || !empty($u['disabled'])) {
        record_fail($un, ($ok && !empty($u['disabled'])) ? 'account is disabled' : 'wrong username or password');
        login_page("That username and password didn't match an active account.", $next, 401);
    }
    clear_fails();
    $newHash = null;
    if (password_needs_rehash($u['hash'], PASSWORD_DEFAULT)) $newHash = password_hash($pw, PASSWORD_DEFAULT);
    with_lock('users.json', [], function (&$list) use ($u, &$newHash) {
        foreach ($list as &$x) if ($x['id'] === $u['id']) { $x['lastLogin'] = now_iso(); if (!empty($newHash)) $x['hash'] = $newHash; }
    });
    audit($un, 'login.success', role_label($u['role']));
    start_session($u);
    redirect(!empty($u['mustChange']) ? '/change-password' : safe_next($next));
}

function handle_logout(): void {
    $u = current_user();
    end_session($u['_tok'] ?? ($_COOKIE[COOKIE] ?? null));
    if ($u) audit($u['username'], 'logout');
    redirect('/login?out=1');
}

function change_password(array $u, string $cur, string $pw, string $pw2): ?string {
    if (!password_verify($cur, $u['hash'])) { audit($u['username'], 'password.change_failed', 'current password incorrect'); return 'Your current password is incorrect.'; }
    if ($e = password_problem($pw)) return $e;
    if ($pw !== $pw2) return "The two new passwords don't match.";
    if ($pw === $cur) return 'Choose a password different from your current one.';
    with_lock('users.json', [], function (&$list) use ($u, $pw) {
        foreach ($list as &$x) if ($x['id'] === $u['id']) { $x['hash'] = password_hash($pw, PASSWORD_DEFAULT); $x['mustChange'] = false; }
    });
    revoke_sessions($u['id'], $u['_tok'] ?? null);
    audit($u['username'], 'password.changed', 'User changed their own password (other sessions signed out)');
    return null;
}

function handle_change_password(): void {
    $u = current_user();
    if (!$u) redirect('/login');
    $form = function (string $msg = '') use ($u) {
        $b = '<h1>Choose a new password</h1>';
        if (!empty($u['mustChange'])) $b .= '<p>Hi ' . h($u['name']) . ', please replace the temporary password you were given.</p>';
        if ($msg) $b .= '<div class="err">' . h($msg) . '</div>';
        $b .= '<form method="post" action="/change-password">'
            . '<label for="c">Current password</label><input id="c" name="current" type="password" autocomplete="current-password" required>'
            . '<label for="p">New password (at least 10 characters)</label><input id="p" name="password" type="password" autocomplete="new-password" required>'
            . '<label for="p2">Confirm new password</label><input id="p2" name="password2" type="password" autocomplete="new-password" required>'
            . '<button type="submit">Save password</button></form><p class="small"><a href="/logout">Sign out</a></p>';
        render_page(200, 'Change password', $b);
    };
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') $form();
    $err = change_password($u, (string)($_POST['current'] ?? ''), (string)($_POST['password'] ?? ''), (string)($_POST['password2'] ?? ''));
    if ($err) $form($err);
    redirect('/');
}

function handle_root(): void {
    if (count(users()) === 0) redirect('/setup');
    $u = current_user();
    if ($u && !empty($u['mustChange'])) redirect('/change-password');
    $s = settings();
    $staff = $u && in_array($u['role'], ['admin', 'editor'], true);
    if ($s['maintenance'] && !$staff) maintenance_page($u);
    if ($s['requireLogin'] && !$u) redirect('/login');
    header('Content-Type: text/html; charset=utf-8'); header('Cache-Control: no-store');
    readfile(APP_DIR . '/site.html');
    exit;
}

// ---------- API ----------
/** level: none | site | self | staff | admin */
function api_guard(string $level): ?array {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET' && ($_SERVER['HTTP_X_DUC'] ?? '') !== '1') api_err(403, 'Missing request header');
    $u = current_user();
    if ($u && !empty($u['mustChange']) && $level !== 'none') api_err(403, 'Password change required');
    $staff = $u && in_array($u['role'], ['admin', 'editor'], true);
    if ($level === 'site') {
        $s = settings();
        if (($s['requireLogin'] && !$u) || ($s['maintenance'] && !$staff)) api_err(401, 'Sign in required');
    } elseif ($level === 'self' && !$u) api_err(401, 'Sign in required');
    elseif ($level === 'staff' && !$staff) api_err(403, 'Editors and admins only');
    elseif ($level === 'admin' && (!$u || $u['role'] !== 'admin')) api_err(403, 'Global Admin only');
    return $u;
}

function api_me(): void {
    $u = api_guard('none'); $s = settings();
    json_out(200, ['user' => $u ? public_user($u) : null, 'settings' => ['maintenance' => $s['maintenance'], 'requireLogin' => $s['requireLogin']], 'version' => APP_VERSION]);
}

function api_content_get(): void {
    api_guard('site');
    $p = dpath('content.json');
    header('Content-Type: application/json'); header('Cache-Control: no-store');
    echo is_file($p) ? file_get_contents($p) : 'null'; exit;
}

function api_content_put(): void {
    $u = api_guard('staff');
    $new = body_json();
    unset($new['applications'], $new['settings']);
    $old = read_json('content.json', []);
    $changed = [];
    foreach ($new as $k => $v) if (!array_key_exists($k, $old) || json_encode($old[$k]) !== json_encode($v)) $changed[] = $k;
    foreach ($old as $k => $v) if (!array_key_exists($k, $new)) $changed[] = $k;
    sort($changed);
    if (!write_json('content.json', (object)$new)) api_err(500, "Couldn't save content");
    audit($u['username'], 'content.published', $changed ? 'Sections changed: ' . implode(', ', $changed) : 'no changes');
    json_out(200, ['ok' => true, 'changed' => $changed]);
}

function api_enquiry_post(): void {
    $u = api_guard('site');
    $b = body_json();
    $e = ['id' => bin2hex(random_bytes(6)), 'date' => now_iso(), 'name' => clip($b['name'] ?? '', 120), 'contact' => clip($b['contact'] ?? '', 200),
        'type' => clip($b['type'] ?? '', 60), 'postcode' => clip($b['postcode'] ?? '', 12), 'purpose' => clip($b['purpose'] ?? '', 4000),
        'amount' => clip($b['amount'] ?? '', 20), 'status' => 'new', 'by' => $u['username'] ?? ''];
    if ($e['name'] === '' || $e['contact'] === '' || $e['purpose'] === '') api_err(400, 'Please fill in your name, contact details and what the grant is for');
    $ip = client_ip();
    $ok = with_lock('enquiries.json', [], function (&$list) use ($e, $ip) {
        $recent = 0; $cut = gmdate('Y-m-d\TH:i:s\Z', time() - 3600);
        foreach ($list as $x) if (($x['date'] ?? '') > $cut && (($x['_ip'] ?? '') === $ip)) $recent++;
        if ($recent >= 10) return false;
        $e['_ip'] = $ip;
        array_unshift($list, $e); return true;
    });
    if (!$ok) api_err(429, 'Too many enquiries – please try later');
    audit($e['by'] ?: '(visitor)', 'enquiry.received', 'Enquiry ' . $e['id'] . ' from ' . $e['name']);
    json_out(200, ['ok' => true]);
}

function api_enquiries(): void {
    api_guard('staff');
    $list = read_json('enquiries.json', []);
    foreach ($list as &$x) unset($x['_ip']);
    json_out(200, array_values($list));
}

function api_enquiry_item(string $id): void {
    $u = api_guard('staff');
    $method = $_SERVER['REQUEST_METHOD'];
    $status = null;
    if ($method === 'PUT') {
        $status = (string)(body_json()['status'] ?? '');
        if (!in_array($status, ['new', 'review', 'approved', 'declined'], true)) api_err(400, 'Invalid status');
    }
    $r = with_lock('enquiries.json', [], function (&$list) use ($id, $method, $status) {
        foreach ($list as $i => &$x) if ($x['id'] === $id) {
            if ($method === 'DELETE') { $n = $x['name']; array_splice($list, $i, 1); return ['deleted', $n]; }
            $old = $x['status']; $x['status'] = $status; return ['status', $x['name'], $old];
        }
        return null;
    });
    if (!$r) api_err(404, 'Not found');
    if ($r[0] === 'deleted') audit($u['username'], 'enquiry.deleted', "Enquiry $id from {$r[1]}");
    else audit($u['username'], 'enquiry.status', "Enquiry $id ({$r[1]}): {$r[2]} → $status");
    json_out(200, ['ok' => true]);
}

function api_settings(): void {
    $u = api_guard('admin');
    if ($_SERVER['REQUEST_METHOD'] === 'GET') json_out(200, settings());
    $b = body_json(); $o = settings();
    $n = ['requireLogin' => !empty($b['requireLogin']), 'maintenance' => !empty($b['maintenance']),
        'maintTitle' => clip($b['maintTitle'] ?? '', 120) ?: "We'll be back soon", 'maintMessage' => clip($b['maintMessage'] ?? '', 2000),
        'maintUntil' => clip($b['maintUntil'] ?? '', 80), 'sessionHours' => (int)($b['sessionHours'] ?? 12)];
    if ($n['sessionHours'] < 1 || $n['sessionHours'] > 720) $n['sessionHours'] = 12;
    if (!write_json('settings.json', $n)) api_err(500, "Couldn't save settings");
    $onoff = function ($x) { return $x ? 'ON' : 'OFF'; };
    if ($o['maintenance'] !== $n['maintenance']) audit($u['username'], 'maintenance.' . strtolower($onoff($n['maintenance'])), 'Maintenance mode turned ' . $onoff($n['maintenance']));
    if ($o['requireLogin'] !== $n['requireLogin']) audit($u['username'], 'access.require_login', 'Password protection for the whole site turned ' . $onoff($n['requireLogin']));
    $ch = [];
    if ($o['maintTitle'] !== $n['maintTitle'] || $o['maintMessage'] !== $n['maintMessage'] || $o['maintUntil'] !== $n['maintUntil']) $ch[] = 'maintenance page text';
    if ((int)$o['sessionHours'] !== $n['sessionHours']) $ch[] = "session length {$o['sessionHours']}h → {$n['sessionHours']}h";
    if ($ch) audit($u['username'], 'settings.changed', implode('; ', $ch));
    json_out(200, ['ok' => true]);
}

function api_users(): void {
    $me = api_guard('admin');
    if ($_SERVER['REQUEST_METHOD'] === 'GET') json_out(200, array_map('public_user', users()));
    $b = body_json();
    $un = strtolower(trim((string)($b['username'] ?? ''))); $name = clip($b['name'] ?? '', 120);
    $role = (string)($b['role'] ?? ''); $pw = (string)($b['password'] ?? '');
    if ($name === '' || !valid_username($un)) api_err(400, 'Enter a name and a username of 3–64 characters (letters, numbers, . _ - @)');
    if (!valid_role($role)) api_err(400, 'Invalid role');
    if ($e = password_problem($pw)) api_err(400, 'Temporary password: ' . $e);
    $u = ['id' => bin2hex(random_bytes(8)), 'username' => $un, 'name' => $name, 'role' => $role,
        'hash' => password_hash($pw, PASSWORD_DEFAULT), 'disabled' => false, 'mustChange' => true, 'created' => now_iso(), 'lastLogin' => ''];
    $ok = with_lock('users.json', [], function (&$list) use ($u) {
        if (find_user($list, 'username', $u['username'])) return false;
        $list[] = $u; return true;
    });
    if (!$ok) api_err(409, 'That username is already taken');
    audit($me['username'], 'user.created', "Created $un ($name) as " . role_label($role));
    json_out(200, public_user($u));
}

function api_user_item(string $id): void {
    $me = api_guard('admin');
    $method = $_SERVER['REQUEST_METHOD'];
    $b = $method === 'PUT' ? body_json() : [];
    $res = with_lock('users.json', [], function (&$list) use ($id, $me, $method, $b) {
        $idx = null; foreach ($list as $i => $x) if ($x['id'] === $id) $idx = $i;
        if ($idx === null) return [404, 'User not found'];
        $u = &$list[$idx];
        if ($method === 'DELETE') {
            if ($u['id'] === $me['id']) return [400, "You can't delete your own account"];
            if ($u['role'] === 'admin' && empty($u['disabled']) && active_admins($list) <= 1) return [400, "You can't delete the last Global Admin"];
            $gone = $u; array_splice($list, $idx, 1);
            return ['deleted', $gone];
        }
        $notes = []; $action = 'user.updated'; $revoke = false;
        if (isset($b['role']) && $b['role'] !== $u['role']) {
            if (!valid_role($b['role'])) return [400, 'Invalid role'];
            if ($u['role'] === 'admin' && empty($u['disabled']) && active_admins($list) <= 1) return [400, 'There must always be at least one active Global Admin'];
            $notes[] = 'role ' . role_label($u['role']) . ' → ' . role_label($b['role']);
            $u['role'] = $b['role']; $revoke = true;
        }
        if (isset($b['disabled']) && (bool)$b['disabled'] !== !empty($u['disabled'])) {
            if ($b['disabled']) {
                if ($u['id'] === $me['id']) return [400, "You can't disable your own account"];
                if ($u['role'] === 'admin' && active_admins($list) <= 1) return [400, "You can't disable the last Global Admin"];
                $notes[] = 'account disabled'; $revoke = true;
            } else $notes[] = 'account enabled';
            $u['disabled'] = (bool)$b['disabled'];
        }
        if (isset($b['name']) && clip($b['name'], 120) !== '' && clip($b['name'], 120) !== $u['name']) {
            $notes[] = "name '{$u['name']}' → '" . clip($b['name'], 120) . "'"; $u['name'] = clip($b['name'], 120);
        }
        if (isset($b['password'])) {
            if ($e = password_problem((string)$b['password'])) return [400, 'Temporary password: ' . $e];
            $u['hash'] = password_hash((string)$b['password'], PASSWORD_DEFAULT); $u['mustChange'] = true;
            $action = 'user.password_reset'; $notes[] = 'password reset (must change at next sign-in)'; $revoke = true;
        }
        return ['updated', $u, $notes, $action, $revoke];
    });
    if (is_int($res[0])) api_err($res[0], $res[1]);
    if ($res[0] === 'deleted') {
        revoke_sessions($id);
        audit($me['username'], 'user.deleted', "Deleted {$res[1]['username']} ({$res[1]['name']})");
        json_out(200, ['ok' => true]);
    }
    [, $u, $notes, $action, $revoke] = $res;
    if ($revoke) revoke_sessions($id);
    if ($notes) audit($me['username'], $action, $u['username'] . ': ' . implode('; ', $notes));
    json_out(200, public_user($u));
}

function api_sessions(): void {
    api_guard('admin');
    $ss = read_json('sessions.json', []); $users = users(); $out = [];
    foreach ($ss as $s) {
        if (($s['expires'] ?? 0) < time()) continue;
        $u = find_user($users, 'id', $s['userId']);
        if ($u) $out[] = ['username' => $u['username'], 'name' => $u['name'], 'role' => $u['role'], 'since' => $s['created']];
    }
    usort($out, function ($a, $b) { return strcmp($b['since'], $a['since']); });
    json_out(200, $out);
}

function api_my_password(): void {
    $u = api_guard('self');
    $b = body_json();
    $err = change_password($u, (string)($b['current'] ?? ''), (string)($b['password'] ?? ''), (string)($b['password2'] ?? ''));
    if ($err) api_err(400, $err);
    json_out(200, ['ok' => true]);
}

function filter_audit(array $all): array {
    $q = strtolower(trim((string)($_GET['q'] ?? ''))); $act = (string)($_GET['action'] ?? ''); $out = [];
    for ($i = count($all) - 1; $i >= 0; $i--) {
        $e = $all[$i];
        if ($act !== '' && strpos($e['action'], $act) !== 0) continue;
        if ($q !== '' && strpos(strtolower($e['user'] . ' ' . $e['ip'] . ' ' . $e['action'] . ' ' . $e['details']), $q) === false) continue;
        $out[] = $e;
    }
    return $out;
}
function api_audit(): void {
    api_guard('admin');
    $list = filter_audit(read_audit());
    $limit = (int)($_GET['limit'] ?? 300); if ($limit <= 0 || $limit > 2000) $limit = 300;
    json_out(200, ['total' => count($list), 'entries' => array_slice($list, 0, $limit)]);
}
function api_audit_csv(): void {
    $u = api_guard('admin');
    $list = filter_audit(read_audit());
    audit($u['username'], 'audit.exported', count($list) . ' entries exported to CSV');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="duc-audit-log-' . gmdate('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Time (UTC)', 'User', 'IP address', 'Action', 'Details'], ',', '"', '\\');
    foreach ($list as $e) {
        $d = (string)$e['details'];
        if ($d !== '' && strpos('=+-@', $d[0]) !== false) $d = "'" . $d;
        fputcsv($out, [str_replace(['T', 'Z'], [' ', ''], $e['time']), $e['user'], $e['ip'], $e['action'], $d], ',', '"', '\\');
    }
    exit;
}

// ---------- router ----------
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src https://fonts.gstatic.com; img-src 'self' data: blob:; connect-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
if (is_https()) header('Strict-Transport-Security: max-age=31536000');

if (version_compare(PHP_VERSION, '7.4.0', '<')) { http_response_code(500); exit('This website needs PHP 7.4 or newer. Change it in cPanel > Select PHP Version.'); }
if (!is_writable(data_dir())) { http_response_code(500); exit('The website cannot write to its data folder: ' . h(data_dir())); }

$path = parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
$path = '/' . ltrim(rawurldecode($path), '/');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

switch (true) {
    case $path === '/' || $path === '/index.php' || $path === '/index.html': handle_root(); break;
    case $path === '/setup': handle_setup(); break;
    case $path === '/login': handle_login(); break;
    case $path === '/logout': handle_logout(); break;
    case $path === '/change-password': handle_change_password(); break;
    case $path === '/api/me' && $method === 'GET': api_me(); break;
    case $path === '/api/content' && $method === 'GET': api_content_get(); break;
    case $path === '/api/content' && $method === 'PUT': api_content_put(); break;
    case $path === '/api/enquiries' && $method === 'POST': api_enquiry_post(); break;
    case $path === '/api/enquiries' && $method === 'GET': api_enquiries(); break;
    case (bool)preg_match('#^/api/enquiries/([a-f0-9]+)$#', $path, $m) && in_array($method, ['PUT', 'DELETE'], true): api_enquiry_item($m[1]); break;
    case $path === '/api/settings' && in_array($method, ['GET', 'PUT'], true): api_settings(); break;
    case $path === '/api/users' && in_array($method, ['GET', 'POST'], true): api_users(); break;
    case (bool)preg_match('#^/api/users/([a-f0-9]+)$#', $path, $m) && in_array($method, ['PUT', 'DELETE'], true): api_user_item($m[1]); break;
    case $path === '/api/sessions' && $method === 'GET': api_sessions(); break;
    case $path === '/api/account/password' && $method === 'POST': api_my_password(); break;
    case $path === '/api/audit' && $method === 'GET': api_audit(); break;
    case $path === '/api/audit.csv' && $method === 'GET': api_audit_csv(); break;
    default: http_response_code(404); header('Content-Type: text/plain'); echo '404 page not found';
}
