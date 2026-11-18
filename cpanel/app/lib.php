<?php
/**
 * DUC portal – shared helpers: roles, portal settings, encryption, files, uploads, email queue.
 * Loaded by index.php. Everything is stored in the duc-data folder (outside public_html).
 */
declare(strict_types=1);

// Meeting times, due dates and letters are in UK time.
date_default_timezone_set('Europe/London');

// ---------- roles ----------
const BOARD_ROLES = ['admin', 'chairman', 'clerk', 'trustee', 'dplead'];
const ALL_ROLES = ['admin', 'editor', 'viewer', 'chairman', 'clerk', 'trustee', 'dplead', 'partner'];
const ROLE_LABELS = ['admin' => 'Global Admin', 'editor' => 'Editor', 'viewer' => 'Viewer', 'chairman' => 'Chairman',
    'clerk' => 'Clerk', 'trustee' => 'Trustee', 'dplead' => 'Data Protection Lead', 'partner' => 'Referral Partner'];

function has_role(?array $u, array $roles): bool { return $u !== null && in_array($u['role'], $roles, true); }
function is_board(?array $u): bool { return has_role($u, BOARD_ROLES); }
function is_partner(?array $u): bool { return has_role($u, ['partner']); }
/** Clerk-level case work (referrals inbox, letters, meetings admin). */
function is_clerkish(?array $u): bool { return has_role($u, ['admin', 'clerk', 'chairman']); }
function is_gdpr(?array $u): bool { return has_role($u, ['admin', 'dplead', 'clerk']); }

/** True when the site is reached on a trustees./portal. subdomain – the home page then opens the portal. */
function is_portal_host(): bool {
    $h = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    return strpos($h, 'trustees.') === 0 || strpos($h, 'portal.') === 0;
}

// ---------- portal settings ----------
function default_portal_settings(): array {
    return [
        'siteUrl' => '',
        'smtp' => ['host' => '', 'port' => 587, 'secure' => 'tls', 'user' => '', 'pass' => '', 'from' => '', 'fromName' => 'Droitwich United Charities', 'replyTo' => ''],
        'gemini' => ['key' => '', 'fastModel' => 'gemini-flash-latest', 'proModel' => 'gemini-pro-latest'],
        'votes' => ['quorum' => 4, 'majority' => 'simple'],
        'postcodes' => ['WR9 7', 'WR9 8', 'WR9 9'],
        'retention' => [
            'referral_closed_days' => 2192,   // 6 years
            'referral_declined_days' => 730,  // 2 years
            'referral_draft_days' => 30,
            'recording_days' => 90,
            'partner_inactive_days' => 365,
            'audit_days' => 2192,
            'upload_tmp_hours' => 24,
        ],
        'signatories' => [
            'chairman' => ['name' => 'Richard Morris', 'role' => 'Chairman, Droitwich United Charities', 'contact' => '07860 733460  •  richmoz@hotmail.com'],
            'clerk' => ['name' => 'Mrs Janet Clarke', 'role' => 'Clerk to the Charity', 'contact' => '01905 775226  •  26 Blackfriars Avenue, Droitwich Spa WR9 8RQ'],
        ],
        'agreementVersion' => 1,
        'cronKey' => '',
    ];
}
function portal_settings(): array {
    $s = read_json('portal-settings.json', []);
    $d = default_portal_settings();
    foreach ($d as $k => $v) {
        if (!isset($s[$k])) $s[$k] = $v;
        elseif (is_array($v) && is_array($s[$k]) && array_keys($v) !== range(0, count($v) - 1)) $s[$k] = array_merge($v, $s[$k]);
    }
    if ($s['cronKey'] === '') { $s['cronKey'] = bin2hex(random_bytes(16)); write_json('portal-settings.json', $s); }
    return $s;
}
function site_url(): string {
    $u = rtrim((string)portal_settings()['siteUrl'], '/');
    if ($u !== '') return $u;
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return (is_https() ? 'https://' : 'http://') . $host;
}

// ---------- ids & refs ----------
function new_id(int $bytes = 8): string { return bin2hex(random_bytes($bytes)); }
function next_ref(string $prefix): string {
    $year = gmdate('Y');
    return with_lock('counters.json', [], function (&$c) use ($prefix, $year) {
        $k = $prefix . '-' . $year;
        $c[$k] = ($c[$k] ?? 0) + 1;
        return sprintf('DUC-%s-%s-%04d', $prefix, $year, $c[$k]);
    });
}

// ---------- encryption for personal details (AES-256-GCM) ----------
function secret_key(): string {
    $p = dpath('secret.key');
    if (!is_file($p)) { file_put_contents($p, base64_encode(random_bytes(32))); @chmod($p, 0600); }
    return base64_decode(trim((string)file_get_contents($p)));
}
function enc($value): string {
    $iv = random_bytes(12); $tag = '';
    $ct = openssl_encrypt(json_encode($value, JSON_UNESCAPED_UNICODE), 'aes-256-gcm', secret_key(), OPENSSL_RAW_DATA, $iv, $tag);
    return 'v1:' . base64_encode($iv . $tag . $ct);
}
function dec($blob, $default = null) {
    if (!is_string($blob) || strpos($blob, 'v1:') !== 0) return $default;
    $raw = base64_decode(substr($blob, 3));
    if (strlen($raw) < 29) return $default;
    $pt = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', secret_key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
    if ($pt === false) return $default;
    return json_decode($pt, true);
}
/** Keyed hash for duplicate checks without storing names in clear. */
function person_key(string $name, string $postcode): string {
    $n = preg_replace('/[^a-z]/', '', strtolower($name));
    $p = preg_replace('/\s+/', '', strtoupper($postcode));
    return hash_hmac('sha256', $n . '|' . $p, secret_key());
}
function initials(string $name): string {
    $out = '';
    foreach (preg_split('/\s+/', trim($name)) as $w) if ($w !== '') $out .= mb_strtoupper(mb_substr($w, 0, 1)) . '.';
    return $out ?: '—';
}

// ---------- files ----------
function files_dir(string $kind): string {
    $d = data_dir() . '/files/' . $kind;
    if (!is_dir($d)) @mkdir($d, 0700, true);
    return $d;
}
function safe_name(string $n): string {
    $n = preg_replace('/[^A-Za-z0-9._ -]+/', '_', $n);
    return mb_substr(trim($n, ' .') ?: 'file', 0, 120);
}
function send_file(string $path, string $name, string $mime, bool $inline = false): void {
    if (!is_file($path)) { http_response_code(404); exit('File not found'); }
    $size = filesize($path);
    header('Content-Type: ' . $mime);
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . str_replace('"', '', $name) . '"');
    header('Accept-Ranges: bytes');
    $start = 0; $end = $size - 1;
    if (isset($_SERVER['HTTP_RANGE']) && preg_match('/bytes=(\d*)-(\d*)/', $_SERVER['HTTP_RANGE'], $m)) {
        if ($m[1] !== '') $start = (int)$m[1];
        if ($m[2] !== '') $end = min((int)$m[2], $size - 1);
        if ($m[1] === '' && $m[2] !== '') { $start = max(0, $size - (int)$m[2]); $end = $size - 1; }
        if ($start > $end || $start >= $size) { http_response_code(416); header("Content-Range: bytes */$size"); exit; }
        http_response_code(206);
        header("Content-Range: bytes $start-$end/$size");
    }
    header('Content-Length: ' . ($end - $start + 1));
    $fh = fopen($path, 'rb'); fseek($fh, $start);
    $left = $end - $start + 1;
    while ($left > 0 && !feof($fh)) { $chunk = fread($fh, (int)min(65536, $left)); echo $chunk; $left -= strlen($chunk); flush(); }
    fclose($fh); exit;
}

// ---------- chunked uploads (works within small cPanel upload limits) ----------
const UPLOAD_MAX = 25 * 1024 * 1024;
const UPLOAD_TYPES = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/heic' => 'heic',
    'application/msword' => 'doc', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
    'application/vnd.ms-excel' => 'xls', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
    'text/plain' => 'txt', 'text/csv' => 'csv'];

/** POST /api/p/upload?id=&seq=&name=&type=&size=  (raw body, ≤1 MB per chunk). Returns the upload id. */
function api_upload_chunk(array $u): void {
    $seq = (int)($_GET['seq'] ?? -1);
    $id = (string)($_GET['id'] ?? '');
    $dir = files_dir('tmp');
    if ($seq === 0) {
        $type = (string)($_GET['type'] ?? '');
        $size = (int)($_GET['size'] ?? 0);
        if (!isset(UPLOAD_TYPES[$type])) api_err(400, 'That file type is not allowed. Use PDF, a photo, Word, Excel or text.');
        if ($size <= 0 || $size > UPLOAD_MAX) api_err(400, 'Files must be under 25 MB');
        $id = new_id(12);
        write_json('files/tmp/' . $id . '.json', ['owner' => $u['id'], 'name' => safe_name((string)($_GET['name'] ?? 'file')), 'type' => $type, 'size' => $size, 'next' => 0, 'at' => time()]);
    }
    if (!preg_match('/^[a-f0-9]{24}$/', $id)) api_err(400, 'Bad upload id');
    $meta = read_json('files/tmp/' . $id . '.json', null);
    if (!$meta || $meta['owner'] !== $u['id']) api_err(404, 'Upload not found');
    $data = file_get_contents('php://input');
    if ($data === false || strlen($data) > 1100000) api_err(413, 'Chunk too large');
    if ($seq < $meta['next']) json_out(200, ['id' => $id, 'next' => $meta['next']]);
    if ($seq > $meta['next']) api_err(409, 'Expected chunk ' . $meta['next']);
    $part = $dir . '/' . $id . '.part';
    if (filesize_safe($part) + strlen($data) > $meta['size']) api_err(400, 'More data than expected');
    file_put_contents($part, $data, FILE_APPEND | LOCK_EX);
    $meta['next'] = $seq + 1;
    write_json('files/tmp/' . $id . '.json', $meta);
    json_out(200, ['id' => $id, 'next' => $meta['next'], 'done' => filesize_safe($part) >= $meta['size']]);
}
function filesize_safe(string $p): int { clearstatcache(true, $p); return is_file($p) ? (int)filesize($p) : 0; }

/** Move a finished upload into a files/<kind> folder. Returns file record. */
function claim_upload(array $u, string $id, string $kind): array {
    if (!preg_match('/^[a-f0-9]{24}$/', $id)) api_err(400, 'Bad upload id');
    $meta = read_json('files/tmp/' . $id . '.json', null);
    $part = files_dir('tmp') . '/' . $id . '.part';
    if (!$meta || $meta['owner'] !== $u['id'] || filesize_safe($part) !== (int)$meta['size']) api_err(400, 'Upload is not complete');
    $fid = new_id(10);
    $dest = files_dir($kind) . '/' . $fid . '.' . UPLOAD_TYPES[$meta['type']];
    rename($part, $dest);
    @unlink(dpath('files/tmp/' . $id . '.json'));
    return ['id' => $fid, 'name' => $meta['name'], 'type' => $meta['type'], 'size' => (int)$meta['size'], 'path' => basename($dest), 'by' => $u['id'], 'at' => now_iso()];
}

// ---------- user lookups ----------
function user_by_id(string $id): ?array { return find_user(users(), 'id', $id); }
function user_name(?string $id): string { if (!$id) return ''; $u = user_by_id($id); return $u ? $u['name'] : '(removed user)'; }
function users_with_roles(array $roles): array {
    return array_values(array_filter(users(), function ($u) use ($roles) { return in_array($u['role'], $roles, true) && empty($u['disabled']); }));
}
function user_email(array $u): string {
    if (!empty($u['email']) && filter_var($u['email'], FILTER_VALIDATE_EMAIL)) return $u['email'];
    return filter_var($u['username'], FILTER_VALIDATE_EMAIL) ? $u['username'] : '';
}

// ---------- set-password links (invites) ----------
function make_setpw_token(string $userId, int $days = 7): string {
    $tok = bin2hex(random_bytes(24));
    with_lock('setpw.json', [], function (&$t) use ($tok, $userId, $days) {
        foreach ($t as $k => $v) if ($v['exp'] < time() || $v['user'] === $userId) unset($t[$k]);
        $t[hash('sha256', $tok)] = ['user' => $userId, 'exp' => time() + $days * 86400];
    });
    return site_url() . '/set-password?t=' . $tok;
}
function handle_set_password(): void {
    $tok = (string)($_GET['t'] ?? $_POST['t'] ?? '');
    $rec = preg_match('/^[a-f0-9]{48}$/', $tok) ? (read_json('setpw.json', [])[hash('sha256', $tok)] ?? null) : null;
    if (!$rec || $rec['exp'] < time()) render_page(400, 'Link expired', '<h1>This link has expired</h1><p>Please ask the Clerk or your organisation\'s main contact to send you a new invitation.</p>');
    $u = user_by_id($rec['user']);
    if (!$u || !empty($u['disabled'])) render_page(400, 'Link expired', '<h1>This account is not active</h1>');
    $form = function (string $msg = '') use ($tok, $u) {
        $b = '<h1>Set your password</h1><p>Hello ' . h($u['name']) . '. Your username is <b>' . h($u['username']) . '</b>.</p>';
        if ($msg) $b .= '<div class="err">' . h($msg) . '</div>';
        $b .= '<form method="post" action="/set-password"><input type="hidden" name="t" value="' . h($tok) . '">'
            . '<label for="p">New password (at least 10 characters)</label><input id="p" name="password" type="password" autocomplete="new-password" required>'
            . '<label for="p2">Confirm password</label><input id="p2" name="password2" type="password" autocomplete="new-password" required>'
            . '<button type="submit">Save and sign in</button></form>';
        render_page(200, 'Set password', $b);
    };
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') $form();
    $pw = (string)($_POST['password'] ?? '');
    if ($e = password_problem($pw)) $form($e);
    if ($pw !== ($_POST['password2'] ?? '')) $form("The two passwords don't match.");
    with_lock('users.json', [], function (&$list) use ($u, $pw) {
        foreach ($list as &$x) if ($x['id'] === $u['id']) { $x['hash'] = password_hash($pw, PASSWORD_DEFAULT); $x['mustChange'] = false; $x['lastLogin'] = now_iso(); }
    });
    with_lock('setpw.json', [], function (&$t) use ($tok) { unset($t[hash('sha256', $tok)]); });
    revoke_sessions($u['id']);
    audit($u['username'], 'password.set_from_invite', 'Password set using an invitation link');
    $u = user_by_id($u['id']);
    start_session($u);
    redirect('/portal');
}

/** Create a user (used for partner and trustee invites). Returns [user, setPasswordLink]. */
function create_invited_user(array $by, string $name, string $email, string $role, array $extra = []): array {
    $email = strtolower(trim($email));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) api_err(400, 'Enter a valid email address');
    if (!in_array($role, ALL_ROLES, true)) api_err(400, 'Invalid role');
    $u = array_merge(['id' => new_id(), 'username' => $email, 'email' => $email, 'name' => clip($name, 120), 'role' => $role,
        'hash' => password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT), 'disabled' => false, 'mustChange' => false,
        'created' => now_iso(), 'lastLogin' => ''], $extra);
    if ($u['name'] === '') api_err(400, 'Enter a name');
    $ok = with_lock('users.json', [], function (&$list) use ($u) {
        if (find_user($list, 'username', $u['username'])) return false;
        $list[] = $u; return true;
    });
    if (!$ok) api_err(409, 'Someone with that email already has an account');
    audit($by['username'], 'user.invited', "Invited {$u['username']} ({$u['name']}) as " . ROLE_LABELS[$role]);
    return [$u, make_setpw_token($u['id'])];
}

// ---------- email (SMTP with a retry queue) ----------
/**
 * Queue an email and try to send it straight away.
 * $att: list of ['name'=>, 'type'=>, 'data'=>bytes]
 */
function send_email(string $to, string $subject, string $html, array $att = [], string $log = ''): bool {
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return false;
    $id = new_id();
    $item = ['id' => $id, 'to' => $to, 'subject' => $subject, 'html' => $html, 'att' => array_map(function ($a) { $a['data'] = base64_encode($a['data']); return $a; }, $att),
        'tries' => 0, 'next' => time(), 'created' => now_iso(), 'log' => $log, 'error' => ''];
    with_lock('outbox.json', [], function (&$q) use ($item) { $q[] = $item; });
    return flush_outbox($id);
}
/** Try queued emails (one id, or all due). Returns true if the requested one was sent. */
function flush_outbox(?string $only = null): bool {
    require_once __DIR__ . '/smtp.php';
    $sent = false;
    $q = read_json('outbox.json', []);
    foreach ($q as $item) {
        if ($only !== null && $item['id'] !== $only) continue;
        if ($only === null && $item['next'] > time()) continue;
        $att = array_map(function ($a) { $a['data'] = base64_decode($a['data']); return $a; }, $item['att']);
        $err = smtp_send($item['to'], $item['subject'], email_wrap($item['html']), $att);
        with_lock('outbox.json', [], function (&$list) use ($item, $err) {
            foreach ($list as $i => &$x) if ($x['id'] === $item['id']) {
                if ($err === null) { array_splice($list, $i, 1); return; }
                $x['tries']++; $x['error'] = $err; $x['next'] = time() + min(86400, 300 * (2 ** $x['tries']));
                if ($x['tries'] >= 8) { array_splice($list, $i, 1); }
                return;
            }
        });
        if ($err === null) { audit('(system)', 'email.sent', 'To ' . $item['to'] . ': ' . $item['subject'] . ($item['log'] ? ' – ' . $item['log'] : '')); if ($only !== null) $sent = true; }
        else audit('(system)', 'email.failed', 'To ' . $item['to'] . ': ' . $item['subject'] . ' – ' . $err . ($item['tries'] + 1 >= 8 ? ' (gave up)' : ' (will retry)'));
    }
    return $sent;
}
function email_wrap(string $inner): string {
    $logo = site_url() . '/assets/duc-logo.png';
    return '<!DOCTYPE html><html><body style="margin:0;background:#EAF1F6;padding:24px 12px;font-family:Arial,Helvetica,sans-serif;color:#0B1C3D;font-size:15px;line-height:1.55">'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0"><tr><td align="center"><table role="presentation" width="600" cellspacing="0" cellpadding="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:10px">'
        . '<tr><td style="padding:24px 28px 8px"><img src="' . h($logo) . '" width="110" alt="Droitwich United Charities" style="display:block;width:110px;height:auto"></td></tr>'
        . '<tr><td style="padding:8px 28px 24px">' . $inner . '</td></tr>'
        . '<tr><td style="padding:16px 28px;border-top:3px solid #2F5E37;font-size:12px;color:#5A6475">Droitwich United Charities (D.U.C.) &bull; Registered charity no. 205536<br><i style="color:#2F5E37">Helping local people in need</i></td></tr>'
        . '</table></td></tr></table></body></html>';
}
function email_button(string $url, string $label): string {
    return '<p style="margin:22px 0"><a href="' . h($url) . '" style="background:#2F5E37;color:#ffffff;text-decoration:none;padding:12px 22px;border-radius:999px;font-weight:bold;display:inline-block">' . h($label) . '</a></p>';
}
