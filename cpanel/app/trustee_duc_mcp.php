<?php
/**
 * Claude connector for the DUC Trustee Portal.
 *
 * Remote MCP server (Streamable HTTP, JSON responses) at /mcp, protected by OAuth 2.1:
 *   /.well-known/oauth-protected-resource   (RFC 9728)
 *   /.well-known/oauth-authorization-server (RFC 8414)
 *   /oauth/register  dynamic client registration (RFC 7591)
 *   /oauth/authorize sign in with your portal account + consent (PKCE S256 required)
 *   /oauth/token     authorization_code and refresh_token (refresh tokens rotate)
 *
 * Each person signs in as themselves, so tools only show what their portal role allows.
 * Personal details of people referred for help are NEVER sent to Claude – only reference
 * numbers and initials. Every tool call is written to the audit log.
 */
declare(strict_types=1);

const MCP_PROTOCOLS = ['2025-11-25', '2025-06-18', '2025-03-26', '2024-11-05'];
const MCP_ACCESS_TTL = 3600;          // 1 hour
const MCP_REFRESH_TTL = 30 * 86400;   // 30 days
const MCP_CODE_TTL = 300;             // 5 minutes

function mcp_enabled(): bool { return !empty(portal_settings()['claude']['enabled']); }
/** The address Claude actually reached us on (so a wrong "Website address" setting can't break sign-in). */
function oauth_base(): string {
    $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    if ($host === '' || !preg_match('/^[a-z0-9.-]+(:\d+)?$/', $host)) return site_url();
    return (is_https() ? 'https://' : 'http://') . $host;
}
function mcp_resource(): string { return oauth_base() . '/mcp'; }

function oauth_json(int $status, array $v, array $headers = []): void {
    http_response_code($status);
    header('Content-Type: application/json'); header('Cache-Control: no-store'); header('Pragma: no-cache');
    header('Access-Control-Allow-Origin: *');
    foreach ($headers as $h) header($h);
    echo json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}
function oauth_err(int $status, string $code, string $desc = ''): void {
    oauth_json($status, ['error' => $code] + ($desc !== '' ? ['error_description' => $desc] : []));
}

// ---------- discovery ----------
function handle_oauth_protected_resource(): void {
    if (!mcp_enabled()) oauth_err(404, 'not_found', 'The Claude connector is switched off');
    oauth_json(200, ['resource' => mcp_resource(), 'authorization_servers' => [oauth_base()], 'scopes_supported' => ['portal'],
        'bearer_methods_supported' => ['header'], 'resource_name' => 'Droitwich United Charities – Trustee Portal']);
}
function handle_oauth_as_metadata(): void {
    if (!mcp_enabled()) oauth_err(404, 'not_found', 'The Claude connector is switched off');
    $b = oauth_base();
    oauth_json(200, ['issuer' => $b, 'authorization_endpoint' => "$b/oauth/authorize", 'token_endpoint' => "$b/oauth/token",
        'registration_endpoint' => "$b/oauth/register", 'revocation_endpoint' => "$b/oauth/revoke",
        'response_types_supported' => ['code'], 'grant_types_supported' => ['authorization_code', 'refresh_token'],
        'code_challenge_methods_supported' => ['S256'], 'token_endpoint_auth_methods_supported' => ['none', 'client_secret_post', 'client_secret_basic'],
        'scopes_supported' => ['portal', 'offline_access'], 'service_documentation' => "$b/portal"]);
}

// ---------- clients (dynamic registration) ----------
/** Claude's hosted callback, or a loopback address (Claude Code) on any port. */
function oauth_redirect_allowed(string $uri): bool {
    $p = parse_url($uri);
    if (!$p || !empty($p['user']) || !empty($p['fragment'])) return false;
    $host = strtolower($p['host'] ?? '');
    // Claude's hosted callbacks (claude.ai / claude.com / anthropic.com and their subdomains)
    if (($p['scheme'] ?? '') === 'https' && preg_match('/(^|\.)(claude\.ai|claude\.com|anthropic\.com)$/', $host)) return true;
    // Claude Code: loopback on any port
    return ($p['scheme'] ?? '') === 'http' && in_array($host, ['localhost', '127.0.0.1'], true);
}
const CLAUDE_CALLBACKS = ['https://claude.ai/api/mcp/auth_callback', 'https://claude.com/api/mcp/auth_callback'];
/** Record why Claude couldn't register, so the Global Admin can see it in the audit log. */
function oauth_reg_fail(int $status, string $code, string $desc, $body = null): void {
    audit('(Claude)', 'claude.register_failed', $desc . ($body !== null ? ' | sent: ' . mb_substr(is_string($body) ? $body : json_encode($body), 0, 300) : '') . ' | from ' . client_ip());
    oauth_err($status, $code, $desc);
}
function oauth_redirect_matches(string $given, array $registered): bool {
    if (in_array($given, $registered, true)) return true;
    $g = parse_url($given);
    if (!$g || !in_array($g['host'] ?? '', ['localhost', '127.0.0.1'], true)) return false;
    foreach ($registered as $r) {
        $p = parse_url($r);
        if ($p && ($p['scheme'] ?? '') === ($g['scheme'] ?? '') && ($p['host'] ?? '') === ($g['host'] ?? '') && ($p['path'] ?? '/') === ($g['path'] ?? '/')) return true;
    }
    return false;
}
function handle_oauth_register(): void {
    $raw = (string)file_get_contents('php://input');
    if (!mcp_enabled()) oauth_reg_fail(404, 'not_found', 'The Claude connector is switched off (Portal > Claude connector)');
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') oauth_err(405, 'invalid_request', 'Use POST');
    $b = json_decode($raw, true);
    if (!is_array($b)) { parse_str($raw, $b); }
    if (!is_array($b) || !$b) oauth_reg_fail(400, 'invalid_client_metadata', 'Registration was not JSON', $raw);
    $uris = array_values(array_filter((array)($b['redirect_uris'] ?? []), 'is_string'));
    if (!$uris) $uris = CLAUDE_CALLBACKS;
    foreach ($uris as $u) if (!oauth_redirect_allowed($u)) oauth_reg_fail(400, 'invalid_redirect_uri', 'Redirect address not allowed: ' . $u, $b);
    $ip = client_ip();
    $id = 'duc_' . bin2hex(random_bytes(16));
    $c = ['client_id' => $id, 'client_name' => clip($b['client_name'] ?? 'Claude', 100), 'redirect_uris' => array_slice($uris, 0, 10), 'created' => time(), 'ip' => $ip];
    $ok = with_lock('oauth.json', [], function (&$o) use ($c, $ip) {
        $o['clients'] = $o['clients'] ?? [];
        $recent = 0;
        foreach ($o['clients'] as $k => $x) {
            if (($x['ip'] ?? '') === $ip && $x['created'] > time() - 3600) $recent++;
            if (empty($x['used']) && $x['created'] < time() - 86400) unset($o['clients'][$k]); // tidy unused registrations
        }
        if ($recent >= 30) return false;
        $o['clients'][$c['client_id']] = $c;
        return true;
    });
    if (!$ok) oauth_err(429, 'temporarily_unavailable', 'Too many registrations');
    oauth_json(201, ['client_id' => $id, 'client_id_issued_at' => $c['created'], 'client_name' => $c['client_name'], 'redirect_uris' => $c['redirect_uris'],
        'grant_types' => ['authorization_code', 'refresh_token'], 'response_types' => ['code'], 'token_endpoint_auth_method' => 'none']);
}
function oauth_client(string $id): ?array { return read_json('oauth.json', [])['clients'][$id] ?? null; }

// ---------- authorize (sign-in + consent) ----------
function oauth_consent_sig(array $u, array $q): string {
    return hash_hmac('sha256', $u['id'] . '|' . json_encode($q), secret_key());
}
function handle_oauth_authorize(): void {
    if (!mcp_enabled()) render_page(404, 'Not available', '<h1>The Claude connector is switched off</h1><p>The Global Admin can switch it on in Portal settings.</p>');
    $src = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;
    $q = [];
    foreach (['response_type', 'client_id', 'redirect_uri', 'state', 'scope', 'code_challenge', 'code_challenge_method', 'resource'] as $k) $q[$k] = is_string($src[$k] ?? null) ? $src[$k] : '';
    $client = oauth_client($q['client_id']);
    // problems with the client or redirect are shown, never redirected (RFC 6749 4.1.2.1)
    if (!$client) render_page(400, 'Connection failed', '<h1>Unknown connection</h1><p>Please remove the connector in Claude and add it again.</p>');
    if (!oauth_redirect_matches($q['redirect_uri'], $client['redirect_uris'])) render_page(400, 'Connection failed', '<h1>Redirect address not allowed</h1>');
    $back = function (array $params) use ($q) {
        $params = array_merge($params, ['state' => $q['state'], 'iss' => oauth_base()]);
        if ($params['state'] === '') unset($params['state']);
        header('Location: ' . $q['redirect_uri'] . (strpos($q['redirect_uri'], '?') === false ? '?' : '&') . http_build_query($params), true, 302); exit;
    };
    if ($q['response_type'] !== 'code') $back(['error' => 'unsupported_response_type']);
    if ($q['code_challenge_method'] !== 'S256' || !preg_match('/^[A-Za-z0-9_-]{43,128}$/', $q['code_challenge'])) $back(['error' => 'invalid_request', 'error_description' => 'PKCE S256 is required']);
    if ($q['resource'] !== '' && rtrim($q['resource'], '/') !== mcp_resource()) $back(['error' => 'invalid_target', 'error_description' => 'Unknown resource']);

    // The Allow/Cancel form redirects back to Claude, so this page's security policy must allow that address
    $rp = parse_url($q['redirect_uri']);
    $dest = $rp['scheme'] . '://' . $rp['host'] . (isset($rp['port']) ? ':' . $rp['port'] : (in_array($rp['host'], ['localhost', '127.0.0.1'], true) ? ':*' : ''));
    header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src https://fonts.gstatic.com; img-src 'self' data:; connect-src 'self'; form-action 'self' $dest; frame-ancestors 'none'; base-uri 'none'", true);
    $u = current_user();
    if (!$u) redirect('/login?next=' . rawurlencode('/oauth/authorize?' . http_build_query($q)));
    if (!empty($u['mustChange'])) redirect('/change-password');
    $host = parse_url($q['redirect_uri'], PHP_URL_HOST);
    if (!is_board($u)) render_page(403, 'Not allowed', '<h1>Not available for your account</h1><p>The Claude connector is for trustees and officers of Droitwich United Charities.</p><p><a href="/portal">Back to the portal</a></p>');

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!hash_equals(oauth_consent_sig($u, $q), (string)($_POST['sig'] ?? ''))) render_page(400, 'Expired', '<h1>Please try again</h1><p>This page expired. Go back to Claude and press Connect again.</p>');
        if (($_POST['decision'] ?? '') !== 'allow') { audit($u['username'], 'claude.denied', 'Declined to connect Claude'); $back(['error' => 'access_denied']); }
        $code = bin2hex(random_bytes(24));
        with_lock('oauth.json', [], function (&$o) use ($code, $q, $u) {
            $o['codes'] = array_filter($o['codes'] ?? [], function ($c) { return $c['exp'] > time(); });
            $o['codes'][hash('sha256', $code)] = ['client_id' => $q['client_id'], 'user' => $u['id'], 'redirect_uri' => $q['redirect_uri'], 'challenge' => $q['code_challenge'],
                'scope' => 'portal', 'exp' => time() + MCP_CODE_TTL];
            if (isset($o['clients'][$q['client_id']])) $o['clients'][$q['client_id']]['used'] = true;
        });
        audit($u['username'], 'claude.connected', 'Allowed ' . ($host ?: 'Claude') . ' to use the Trustee Portal as ' . ROLE_LABELS[$u['role']]);
        $back(['code' => $code]);
    }
    $can = ['See the dashboard, meetings, agendas, approved minutes and your actions', 'Search the minutes and see the calendar and board paper titles', 'See requests waiting for a decision, by reference number only',
        'Update your own actions, mark your attendance'];
    if (is_clerkish($u)) { $can[] = 'See a summary of referrals and partners (reference numbers and initials only)'; $can[] = 'Add actions and post notices'; }
    if (is_gdpr($u)) $can[] = 'See GDPR deadlines (requests, breaches, retention) without personal details';
    $loop = in_array($host, ['localhost', '127.0.0.1'], true);
    $hidden = '';
    foreach ($q as $k => $v) $hidden .= '<input type="hidden" name="' . h($k) . '" value="' . h($v) . '">';
    $body = '<h1>Connect Claude to the Trustee Portal?</h1>'
        . '<p><b>' . h($client['client_name']) . '</b> at <b>' . h((string)$host) . '</b> wants to use the D.U.C. Trustee Portal as <b>' . h($u['name']) . '</b> (' . h(ROLE_LABELS[$u['role']]) . ').</p>'
        . ($loop ? '<div class="err">This connection goes to a program on your own computer (Claude Code). Only allow it if you started it yourself.</div>' : '')
        . '<p>Claude will be able to:</p><ul style="text-align:left">' . implode('', array_map(function ($c) { return '<li>' . h($c) . '</li>'; }, $can)) . '</ul>'
        . '<p class="small">Names, addresses and other personal details of people referred for help are never shared with Claude. Claude can\'t vote, approve minutes or send letters. Everything it does is recorded in the audit log, and you can disconnect at any time in Claude or in the portal.</p>'
        . '<form method="post" action="/oauth/authorize">' . $hidden . '<input type="hidden" name="sig" value="' . h(oauth_consent_sig($u, $q)) . '">'
        . '<button type="submit" name="decision" value="allow">Allow</button>'
        . '<button type="submit" name="decision" value="deny" style="background:#eaf1f6;color:#14254a;margin-top:8px">Cancel</button></form>';
    render_page(200, 'Connect Claude', $body);
}

// ---------- token ----------
function handle_oauth_token(): void {
    if (!mcp_enabled()) oauth_err(400, 'invalid_grant', 'The Claude connector is switched off');
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') oauth_err(405, 'invalid_request', 'Use POST');
    $p = $_POST;
    if (!$p && stripos((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'json') !== false) $p = json_decode((string)file_get_contents('php://input'), true) ?: [];
    $grant = (string)($p['grant_type'] ?? '');
    $clientId = (string)($p['client_id'] ?? '');
    if ($clientId === '' && !empty($_SERVER['PHP_AUTH_USER'])) $clientId = (string)$_SERVER['PHP_AUTH_USER'];
    $auth = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if ($clientId === '' && stripos($auth, 'Basic ') === 0) { $pair = explode(':', (string)base64_decode(substr($auth, 6)), 2); $clientId = urldecode($pair[0]); }
    $client = oauth_client($clientId);
    if (!$client) { audit('(Claude)', 'claude.token_failed', 'Unknown client ' . mb_substr($clientId, 0, 60) . ' | from ' . client_ip()); oauth_err(401, 'invalid_client', 'Unknown client'); }

    if ($grant === 'authorization_code') {
        $code = (string)($p['code'] ?? '');
        $res = with_lock('oauth.json', [], function (&$o) use ($code, $p, $clientId) {
            $k = hash('sha256', $code);
            $c = $o['codes'][$k] ?? null;
            unset($o['codes'][$k]); // single use
            if (!$c || $c['exp'] < time() || $c['client_id'] !== $clientId) return [400, 'invalid_grant', 'The code is invalid or expired'];
            if ((string)($p['redirect_uri'] ?? '') !== $c['redirect_uri']) return [400, 'invalid_grant', 'redirect_uri does not match'];
            $v = (string)($p['code_verifier'] ?? '');
            $calc = rtrim(strtr(base64_encode(hash('sha256', $v, true)), '+/', '-_'), '=');
            if ($v === '' || !hash_equals($c['challenge'], $calc)) return [400, 'invalid_grant', 'PKCE check failed'];
            $access = 'duca_' . bin2hex(random_bytes(32)); $refresh = 'ducr_' . bin2hex(random_bytes(32));
            $o['tokens'][hash('sha256', $access)] = ['type' => 'access', 'user' => $c['user'], 'client' => $clientId, 'exp' => time() + MCP_ACCESS_TTL];
            $o['tokens'][hash('sha256', $refresh)] = ['type' => 'refresh', 'user' => $c['user'], 'client' => $clientId, 'exp' => time() + MCP_REFRESH_TTL, 'created' => time()];
            return [200, $access, $refresh];
        });
    } elseif ($grant === 'refresh_token') {
        $rt = (string)($p['refresh_token'] ?? '');
        $res = with_lock('oauth.json', [], function (&$o) use ($rt, $clientId) {
            $k = hash('sha256', $rt);
            $t = $o['tokens'][$k] ?? null;
            if (!$t || $t['type'] !== 'refresh' || $t['exp'] < time() || $t['client'] !== $clientId) return [400, 'invalid_grant', 'The refresh token is invalid or expired'];
            unset($o['tokens'][$k]); // rotate
            $access = 'duca_' . bin2hex(random_bytes(32)); $refresh = 'ducr_' . bin2hex(random_bytes(32));
            $o['tokens'][hash('sha256', $access)] = ['type' => 'access', 'user' => $t['user'], 'client' => $clientId, 'exp' => time() + MCP_ACCESS_TTL];
            $o['tokens'][hash('sha256', $refresh)] = ['type' => 'refresh', 'user' => $t['user'], 'client' => $clientId, 'exp' => time() + MCP_REFRESH_TTL, 'created' => $t['created'] ?? time()];
            foreach ($o['tokens'] as $kk => $x) if ($x['exp'] < time()) unset($o['tokens'][$kk]);
            return [200, $access, $refresh];
        });
    } else {
        oauth_err(400, 'unsupported_grant_type');
    }
    if ($res[0] !== 200) oauth_err($res[0], $res[1], $res[2]);
    oauth_json(200, ['access_token' => $res[1], 'token_type' => 'Bearer', 'expires_in' => MCP_ACCESS_TTL, 'refresh_token' => $res[2], 'scope' => 'portal']);
}
function handle_oauth_revoke(): void {
    $tok = (string)($_POST['token'] ?? '');
    with_lock('oauth.json', [], function (&$o) use ($tok) { unset($o['tokens'][hash('sha256', $tok)]); });
    http_response_code(200); exit;
}

/** Which user does this bearer token belong to? */
function mcp_bearer_user(): ?array {
    $h = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if ($h === '' && function_exists('getallheaders')) foreach (getallheaders() as $k => $v) if (strtolower($k) === 'authorization') $h = $v;
    if (!preg_match('/^Bearer\s+(duca_[a-f0-9]{64})$/i', trim($h), $m)) return null;
    $t = read_json('oauth.json', [])['tokens'][hash('sha256', $m[1])] ?? null;
    if (!$t || $t['type'] !== 'access' || $t['exp'] < time()) return null;
    $u = user_by_id($t['user']);
    if (!$u || !empty($u['disabled']) || !is_board($u)) return null;
    return $u;
}
function mcp_unauthorized(string $why = ''): void {
    oauth_json(401, ['error' => 'invalid_token', 'error_description' => $why ?: 'Sign in to the Trustee Portal'],
        ['WWW-Authenticate: Bearer resource_metadata="' . oauth_base() . '/.well-known/oauth-protected-resource", scope="portal"' . ($why ? ', error="invalid_token"' : '')]);
}

/** Connected Claude sessions (for Portal settings) and revoking them. */
function mcp_connections(?string $userId = null): array {
    $o = read_json('oauth.json', []);
    $out = [];
    foreach ($o['tokens'] ?? [] as $k => $t) {
        if ($t['type'] !== 'refresh' || $t['exp'] < time() || ($userId && $t['user'] !== $userId)) continue;
        $c = $o['clients'][$t['client']] ?? [];
        $key = $t['user'] . '|' . $t['client'];
        $out[$key] = ['user' => user_name($t['user']), 'userId' => $t['user'], 'client' => $t['client'], 'app' => ($c['client_name'] ?? 'Claude') . ' (' . (parse_url($c['redirect_uris'][0] ?? '', PHP_URL_HOST) ?: '?') . ')',
            'since' => gmdate('Y-m-d\TH:i:s\Z', $t['created'] ?? time())];
    }
    return array_values($out);
}
function mcp_disconnect(string $userId, string $clientId = ''): int {
    return with_lock('oauth.json', [], function (&$o) use ($userId, $clientId) {
        $n = 0;
        foreach ($o['tokens'] ?? [] as $k => $t) if ($t['user'] === $userId && ($clientId === '' || $t['client'] === $clientId)) { unset($o['tokens'][$k]); $n++; }
        return $n;
    });
}
function api_p_claude(): void {
    $u = pguard('board');
    $b = $_SERVER['REQUEST_METHOD'] === 'POST' ? body_json() : [];
    $admin = has_role($u, ['admin']);
    if (isset($b['disconnect'])) {
        $who = (string)($b['userId'] ?? $u['id']);
        if ($who !== $u['id'] && !$admin) api_err(403, 'You can only disconnect yourself');
        $n = mcp_disconnect($who, (string)$b['disconnect']);
        audit($u['username'], 'claude.disconnected', 'Claude disconnected for ' . user_name($who) . " ($n tokens)");
    }
    if (isset($b['enabled'])) {
        if (!$admin) api_err(403, 'Only the Global Admin can switch the connector on or off');
        $s = portal_settings(); $s['claude']['enabled'] = (bool)$b['enabled']; write_json('portal-settings.json', $s);
        if (!$b['enabled']) with_lock('oauth.json', [], function (&$o) { $o['tokens'] = []; $o['codes'] = []; });
        audit($u['username'], 'claude.' . ($b['enabled'] ? 'enabled' : 'disabled'), 'Claude connector switched ' . ($b['enabled'] ? 'on' : 'off'));
    }
    if (!empty($b['createClient'])) {
        if (!$admin) api_err(403, 'Only the Global Admin can create a Client ID');
        $id = 'duc_claude_' . bin2hex(random_bytes(8));
        with_lock('oauth.json', [], function (&$o) use ($id) { $o['clients'][$id] = ['client_id' => $id, 'client_name' => 'Claude', 'redirect_uris' => CLAUDE_CALLBACKS, 'created' => time(), 'ip' => 'manual', 'used' => true, 'manual' => true]; });
        audit($u['username'], 'claude.client_created', 'Client ID ' . $id . ' created for Claude');
    }
    if (!empty($b['deleteClient']) && $admin) {
        $cid = (string)$b['deleteClient'];
        with_lock('oauth.json', [], function (&$o) use ($cid) { unset($o['clients'][$cid]); foreach ($o['tokens'] ?? [] as $k => $t) if ($t['client'] === $cid) unset($o['tokens'][$k]); });
        audit($u['username'], 'claude.client_deleted', 'Client ID ' . $cid . ' deleted');
    }
    $out = ['enabled' => mcp_enabled(), 'url' => mcp_resource(), 'connections' => mcp_connections($admin ? null : $u['id'])];
    if ($admin) {
        $out['clients'] = array_values(array_map(function ($c) { return ['id' => $c['client_id'], 'created' => gmdate('Y-m-d\TH:i:s\Z', $c['created'])]; },
            array_filter(read_json('oauth.json', [])['clients'] ?? [], function ($c) { return !empty($c['manual']); })));
        $out['failures'] = array_slice(array_values(array_filter(read_audit(), function ($e) { return $e['action'] === 'claude.register_failed'; })), 0, 5);
        if (!empty($b['selfCheck'])) $out['check'] = mcp_self_check();
    }
    json_out(200, $out);
}

/** Ask our own public address for the discovery and registration endpoints, like Claude does. */
function mcp_self_check(): array {
    $res = [];
    $get = function (string $method, string $url, ?string $body = null, array $h = []) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => array_merge(['User-Agent: Claude-User (DUC self-check)'], $h)]);
        if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        $out = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
        return [$code, $out === false ? 'Error: ' . $err : (string)$out];
    };
    $b = oauth_base();
    $set = rtrim((string)portal_settings()['siteUrl'], '/');
    if ($set !== '' && strtolower($set) !== strtolower($b))
        $res[] = ['step' => 'Website address in Portal settings matches this site', 'url' => $set, 'status' => 0, 'ok' => false, 'body' => "Portal settings say $set but this site is $b – change the Website address to $b"];
    [$c, $t] = $get('POST', "$b/mcp", '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{}}', ['Content-Type: application/json']);
    $res[] = ['step' => 'MCP endpoint asks Claude to sign in (expect 401)', 'url' => "$b/mcp", 'status' => $c, 'ok' => $c === 401, 'body' => $t];
    [$c, $t] = $get('GET', "$b/.well-known/oauth-protected-resource");
    $res[] = ['step' => 'Protected resource details (expect 200)', 'url' => "$b/.well-known/oauth-protected-resource", 'status' => $c, 'ok' => $c === 200 && strpos($t, '"resource"') !== false, 'body' => $t];
    [$c, $t] = $get('GET', "$b/.well-known/oauth-authorization-server");
    $res[] = ['step' => 'Sign-in service details (expect 200)', 'url' => "$b/.well-known/oauth-authorization-server", 'status' => $c, 'ok' => $c === 200 && strpos($t, 'registration_endpoint') !== false, 'body' => $t];
    [$c, $t] = $get('POST', "$b/oauth/register", json_encode(['client_name' => 'DUC self-check', 'redirect_uris' => CLAUDE_CALLBACKS, 'grant_types' => ['authorization_code', 'refresh_token'], 'token_endpoint_auth_method' => 'none']), ['Content-Type: application/json']);
    $res[] = ['step' => 'Claude registers itself (expect 201)', 'url' => "$b/oauth/register", 'status' => $c, 'ok' => $c === 201, 'body' => $t];
    if ($c === 201 && preg_match('/"client_id":"([^"]+)"/', $t, $m)) with_lock('oauth.json', [], function (&$o) use ($m) { unset($o['clients'][$m[1]]); });
    foreach ($res as &$r) $r['body'] = mb_substr((string)$r['body'], 0, 200); // keep the page short
    return $res;
}

// ---------- MCP endpoint ----------
function handle_mcp(): void {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Expose-Headers: WWW-Authenticate, Mcp-Session-Id');
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { header('Access-Control-Allow-Headers: Authorization, Content-Type, Mcp-Session-Id, Mcp-Protocol-Version'); header('Access-Control-Allow-Methods: POST, GET, DELETE, OPTIONS'); http_response_code(204); exit; }
    if (!mcp_enabled()) oauth_err(404, 'not_found', 'The Claude connector is switched off');
    $u = mcp_bearer_user();
    if (!$u) mcp_unauthorized(trim((string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '')) !== '' ? 'Token expired or revoked' : '');
    if ($_SERVER['REQUEST_METHOD'] === 'GET') { http_response_code(405); header('Allow: POST, DELETE'); exit; }
    if ($_SERVER['REQUEST_METHOD'] === 'DELETE') { http_response_code(200); exit; }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
    $raw = (string)file_get_contents('php://input');
    $msg = json_decode($raw, true);
    if (!is_array($msg)) oauth_json(400, ['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32700, 'message' => 'Parse error']]);
    $batch = array_keys($msg) === range(0, count($msg) - 1);
    $out = [];
    foreach ($batch ? $msg : [$msg] as $m) { $r = mcp_dispatch($u, is_array($m) ? $m : []); if ($r !== null) $out[] = $r; }
    if (!$out) { http_response_code(202); exit; }
    oauth_json(200, $batch ? $out : $out[0], isset($msg['method']) && $msg['method'] === 'initialize' ? ['Mcp-Session-Id: ' . bin2hex(random_bytes(16))] : []);
}
function mcp_dispatch(array $u, array $m): ?array {
    $id = $m['id'] ?? null;
    $method = (string)($m['method'] ?? '');
    $params = is_array($m['params'] ?? null) ? $m['params'] : [];
    if (!array_key_exists('id', $m)) return null; // notification
    $ok = function ($result) use ($id) { return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result]; };
    $err = function (int $code, string $msg) use ($id) { return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $msg]]; };
    switch ($method) {
        case 'initialize':
            $want = (string)($params['protocolVersion'] ?? '');
            return $ok(['protocolVersion' => in_array($want, MCP_PROTOCOLS, true) ? $want : MCP_PROTOCOLS[0],
                'capabilities' => ['tools' => ['listChanged' => false]],
                'serverInfo' => ['name' => 'duc-trustee-portal', 'title' => 'D.U.C. Trustee Portal', 'version' => APP_VERSION],
                'instructions' => "Droitwich United Charities (D.U.C.) Trustee Portal for {$u['name']} (" . ROLE_LABELS[$u['role']] . "). D.U.C. is a small grant-giving charity in Droitwich Spa run by volunteer trustees. "
                    . 'People asking for help are identified only by reference numbers like DUC-R-2026-0001 and initials; never guess or ask for their personal details. Dates are UK dates. Write in plain British English.']);
        case 'ping': return $ok(new stdClass());
        case 'tools/list': return $ok(['tools' => array_values(array_map(function ($t) { unset($t['run'], $t['who']); return $t; }, mcp_tools_for($u)))]);
        case 'tools/call':
            $name = (string)($params['name'] ?? '');
            $args = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];
            $tools = mcp_tools_for($u);
            if (!isset($tools[$name])) return $err(-32602, 'Unknown tool: ' . $name);
            try {
                $res = ($tools[$name]['run'])($u, $args);
                audit($u['username'], 'claude.tool', $name . (($tools[$name]['annotations']['readOnlyHint'] ?? false) ? '' : ' ' . mb_substr(json_encode($args, JSON_UNESCAPED_UNICODE), 0, 200)));
                return $ok(['content' => [['type' => 'text', 'text' => json_encode($res, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]], 'structuredContent' => is_array($res) && array_keys($res) !== range(0, count($res) - 1) ? $res : ['items' => $res], 'isError' => false]);
            } catch (Throwable $e) {
                return $ok(['content' => [['type' => 'text', 'text' => $e->getMessage()]], 'isError' => true]);
            }
        default: return $err(-32601, 'Method not found: ' . $method);
    }
}

// ---------- tools ----------
function mcp_fail(string $m): void { throw new RuntimeException($m); }
function mcp_meeting_view(array $m, array $u): array {
    $att = [];
    foreach ($m['attendance'] ?? [] as $uid => $st) $att[$st][] = user_name($uid);
    $sum = [];
    foreach ($m['drafts']['items'] ?? [] as $i) if (trim($i['summary']) !== '') $sum[$i['itemId']] = $i['summary'];
    $approved = $m['status'] === 'minutes_approved';
    $acts = array_values(array_map(function ($a) { return ['id' => $a['id'], 'text' => $a['text'], 'owner' => user_name($a['owner']) ?: 'unassigned', 'due' => $a['due'], 'status' => $a['status']]; },
        array_filter(read_json('actions.json', []), function ($a) use ($m) { return $a['meetingId'] === $m['id']; })));
    $v = ['id' => $m['id'], 'title' => $m['title'], 'type' => $m['type'], 'date' => $m['date'], 'venue' => $m['venue'], 'status' => $m['status'], 'link' => site_url() . '/portal#meeting/' . $m['id'],
        'attending' => $att['attending'] ?? [], 'apologies' => $att['apologies'] ?? [],
        'agenda' => array_map(function ($a) use ($sum, $approved, $u) {
            return array_filter(['title' => $a['title'], 'lead' => user_name($a['owner']), 'minutes' => $a['mins'], 'confidential' => $a['confidential'],
                'summary' => ($approved || is_clerkish($u)) && !$a['confidential'] ? ($sum[$a['id']] ?? '') : ''], function ($x) { return $x !== '' && $x !== false; });
        }, $m['agenda']),
        'actions' => $acts];
    if ($approved) $v['minutes'] = ['approved_by' => user_name($m['minutes']['approvedBy']), 'approved_on' => substr($m['minutes']['approvedAt'], 0, 10), 'text' => $m['minutes']['text']];
    elseif (is_clerkish($u) && !empty($m['drafts']['minutes'])) $v['draft_minutes'] = ['note' => 'DRAFT – not yet approved by the Chairman', 'text' => $m['drafts']['minutes'], 'decisions' => array_column($m['drafts']['decisions'] ?? [], 'text')];
    $v['recording'] = $m['recording']['status'] ?? 'none';
    return $v;
}
function mcp_find_user_by_name(string $n): ?array {
    $n = strtolower(trim($n));
    if ($n === '') return null;
    $hits = array_filter(users_with_roles(BOARD_ROLES), function ($u) use ($n) { return $u['id'] === $n || strtolower($u['name']) === $n || strpos(strtolower($u['name']), $n) !== false; });
    if (count($hits) > 1) mcp_fail('More than one person matches "' . $n . '": ' . implode(', ', array_column($hits, 'name')));
    return $hits ? array_values($hits)[0] : null;
}
function mcp_case_view(array $c, array $u): array {
    $o = case_out($c, $u);
    $r = $o['referral'] ?? [];
    return ['id' => $c['id'], 'title' => $c['title'], 'reference' => $c['ref'], 'status' => $c['status'], 'opened' => substr($c['opened'], 0, 10), 'vote_by' => $c['closeBy'],
        'amount_gbp' => $c['amount'], 'help_type' => REF_CATEGORIES[$r['category'] ?? ''] ?? '', 'referred_by' => $r['org'] ?? '', 'person' => $r['initials'] ?? '',
        'question_for_trustees' => $c['question'], 'votes' => $o['tally'], 'quorum' => $o['quorum'], 'quorate' => $o['quorate'],
        'your_vote' => $o['mine']['vote'] ?? ($o['myConflict'] ? 'conflict declared' : 'not voted'), 'link' => site_url() . '/portal#decisions',
        'note' => 'Trustees vote in the portal, not through Claude. The full request is only shown in the portal.'];
}
function mcp_tools_for(array $u): array {
    $str = function ($d, $extra = []) { return ['type' => 'string', 'description' => $d] + $extra; };
    $ro = ['readOnlyHint' => true, 'destructiveHint' => false, 'openWorldHint' => false];
    $rw = ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false];
    $t = [];
    $t['get_dashboard'] = ['title' => 'Dashboard', 'description' => "The signed-in trustee's overview: next meeting, their open actions, requests waiting for a vote, notices and papers to read.",
        'inputSchema' => ['type' => 'object', 'properties' => new stdClass()], 'annotations' => $ro, 'run' => function ($u) {
            $now = date('Y-m-d\TH:i'); $next = null;
            foreach (read_json('meetings.json', []) as $m) if ($m['date'] >= $now && ($m['status'] !== 'draft' || is_clerkish($u)) && (!$next || $m['date'] < $next['date'])) $next = $m;
            $acts = array_values(array_filter(read_json('actions.json', []), function ($a) use ($u) { return $a['owner'] === $u['id'] && $a['status'] !== 'done'; }));
            $docs = array_values(array_filter(read_json('docs.json', []), function ($d) use ($u) { return !empty($d['requireConfirm']) && !isset($d['confirms'][$u['id']][(string)count($d['versions'])]); }));
            return ['you' => $u['name'] . ' (' . ROLE_LABELS[$u['role']] . ')', 'today' => date('Y-m-d'),
                'next_meeting' => $next ? ['id' => $next['id'], 'title' => $next['title'], 'date' => $next['date'], 'venue' => $next['venue'], 'your_attendance' => $next['attendance'][$u['id']] ?? 'not said'] : null,
                'your_open_actions' => array_map(function ($a) { $x = action_out($a); return ['id' => $a['id'], 'text' => $a['text'], 'due' => $a['due'], 'status' => $a['status'], 'overdue' => $x['overdue'], 'from' => $x['meetingTitle']]; }, $acts),
                'waiting_for_decision' => array_values(array_map(function ($c) use ($u) { return ['title' => $c['title'], 'reference' => $c['ref'], 'you_have_voted' => isset($c['votes'][$u['id']]) || isset($c['conflicts'][$u['id']]), 'vote_by' => $c['closeBy']]; },
                    array_filter(read_json('cases.json', []), function ($c) { return $c['status'] === 'open'; }))),
                'papers_to_read_and_confirm' => array_column($docs, 'title'),
                'notices' => array_map(function ($n) { return ['text' => $n['text'], 'by' => $n['by'], 'date' => substr($n['at'], 0, 10)]; }, array_slice(read_json('announcements.json', []), 0, 5))];
        }];
    $t['list_meetings'] = ['title' => 'List meetings', 'description' => 'Meetings, newest first, with date, venue and status (draft, published, held, minutes_approved).',
        'inputSchema' => ['type' => 'object', 'properties' => ['limit' => ['type' => 'integer', 'description' => 'How many (default 20)', 'minimum' => 1, 'maximum' => 100]]], 'annotations' => $ro,
        'run' => function ($u, $a) {
            $l = array_filter(read_json('meetings.json', []), function ($m) use ($u) { return $m['status'] !== 'draft' || is_clerkish($u); });
            usort($l, function ($a, $b) { return strcmp($b['date'], $a['date']); });
            return array_map(function ($m) use ($u) { return ['id' => $m['id'], 'title' => $m['title'], 'date' => $m['date'], 'venue' => $m['venue'], 'status' => $m['status'], 'your_attendance' => $m['attendance'][$u['id']] ?? '']; },
                array_slice(array_values($l), 0, max(1, min(100, (int)($a['limit'] ?? 20)))));
        }];
    $t['get_meeting'] = ['title' => 'Get a meeting', 'description' => 'One meeting: agenda (with item summaries), who attended, actions agreed, and the approved minutes. Use list_meetings to find the id.',
        'inputSchema' => ['type' => 'object', 'properties' => ['meeting_id' => $str('Meeting id from list_meetings')], 'required' => ['meeting_id']], 'annotations' => $ro,
        'run' => function ($u, $a) {
            $m = find_meeting((string)($a['meeting_id'] ?? ''));
            if (!$m || ($m['status'] === 'draft' && !is_clerkish($u))) mcp_fail('Meeting not found');
            return mcp_meeting_view($m, $u);
        }];
    $t['search_minutes'] = ['title' => 'Search minutes', 'description' => 'Find words in approved minutes (and draft minutes for the Clerk). Returns matching lines with the meeting they come from.',
        'inputSchema' => ['type' => 'object', 'properties' => ['query' => $str('Words to find, e.g. "Christmas hampers"')], 'required' => ['query']], 'annotations' => $ro,
        'run' => function ($u, $a) {
            $q = mb_strtolower(trim((string)($a['query'] ?? '')));
            if (mb_strlen($q) < 2) mcp_fail('Give at least 2 characters');
            $hits = [];
            foreach (read_json('meetings.json', []) as $m) {
                $approved = $m['status'] === 'minutes_approved';
                if (!$approved && !is_clerkish($u)) continue;
                $txt = $approved ? $m['minutes']['text'] : ($m['drafts']['minutes'] ?? '');
                foreach (preg_split("/\n+/", $txt) as $line) if (mb_strpos(mb_strtolower($line), $q) !== false)
                    $hits[] = ['meeting' => $m['title'], 'date' => substr($m['date'], 0, 10), 'meeting_id' => $m['id'], 'approved' => $approved, 'text' => trim($line)];
            }
            return array_slice($hits, 0, 60);
        }];
    $t['list_actions'] = ['title' => 'List actions', 'description' => 'Actions agreed at meetings. scope: "mine" (default), "open" (everyone\'s unfinished) or "all".',
        'inputSchema' => ['type' => 'object', 'properties' => ['scope' => $str('mine, open or all', ['enum' => ['mine', 'open', 'all']])]], 'annotations' => $ro,
        'run' => function ($u, $a) {
            $s = (string)($a['scope'] ?? 'mine');
            $l = array_filter(read_json('actions.json', []), function ($x) use ($u, $s) { return $s === 'all' || ($s === 'open' && $x['status'] !== 'done') || ($s === 'mine' && $x['owner'] === $u['id']); });
            return array_values(array_map(function ($x) { $o = action_out($x); return ['id' => $x['id'], 'text' => $x['text'], 'owner' => $o['ownerName'] ?: 'unassigned', 'due' => $x['due'], 'status' => $x['status'], 'overdue' => $o['overdue'], 'from' => $o['meetingTitle'],
                'latest_note' => end($x['history'])['what'] ?? '']; }, $l));
        }];
    $t['update_action'] = ['title' => 'Update an action', 'description' => 'Change the status of an action and/or add a progress note. Trustees can update their own actions; the Clerk can update any. Status: not_started, in_progress, done, blocked, carried.',
        'inputSchema' => ['type' => 'object', 'properties' => ['action_id' => $str('Action id from list_actions'), 'status' => $str('New status', ['enum' => ACTION_STATUSES]), 'note' => $str('Short progress note')], 'required' => ['action_id']], 'annotations' => $rw,
        'run' => function ($u, $a) {
            $id = (string)($a['action_id'] ?? '');
            $res = with_lock('actions.json', [], function (&$l) use ($id, $a, $u) {
                foreach ($l as &$x) if ($x['id'] === $id) {
                    if (!is_clerkish($u) && $x['owner'] !== $u['id']) return 'You can only update your own actions';
                    $n = [];
                    if (isset($a['status']) && in_array($a['status'], ACTION_STATUSES, true) && $a['status'] !== $x['status']) { $x['status'] = $a['status']; $n[] = 'status → ' . $a['status']; if ($a['status'] === 'done') $x['doneAt'] = now_iso(); }
                    if (!empty($a['note'])) $n[] = 'note: ' . clip($a['note'], 500);
                    if (!$n) return 'Nothing to change';
                    $x['history'][] = ['at' => now_iso(), 'by' => $u['id'], 'what' => implode('; ', $n) . ' (via Claude)'];
                    return $x;
                }
                return 'Action not found';
            });
            if (is_string($res)) mcp_fail($res);
            return ['updated' => true, 'action' => $res['text'], 'status' => $res['status']];
        }];
    $t['set_my_attendance'] = ['title' => 'Say if you can attend', 'description' => 'Mark the signed-in trustee as attending or sending apologies for a meeting.',
        'inputSchema' => ['type' => 'object', 'properties' => ['meeting_id' => $str('Meeting id'), 'attendance' => $str('attending or apologies', ['enum' => ['attending', 'apologies']])], 'required' => ['meeting_id', 'attendance']], 'annotations' => $rw,
        'run' => function ($u, $a) {
            $m = find_meeting((string)($a['meeting_id'] ?? ''));
            if (!$m || $m['status'] === 'draft') mcp_fail('Meeting not found');
            $st = (string)($a['attendance'] ?? '');
            if (!in_array($st, ['attending', 'apologies'], true)) mcp_fail('Use attending or apologies');
            update_meeting($m['id'], function (&$x) use ($u, $st) { $x['attendance'][$u['id']] = $st; });
            return ['meeting' => $m['title'], 'date' => $m['date'], 'you' => $st];
        }];
    $t['list_decisions'] = ['title' => 'Requests for a decision', 'description' => 'Grant requests put to trustees: reference, amount, type of help, vote tally and whether you have voted. No personal details. status: open (default) or all.',
        'inputSchema' => ['type' => 'object', 'properties' => ['status' => $str('open or all', ['enum' => ['open', 'all']])]], 'annotations' => $ro,
        'run' => function ($u, $a) {
            $all = ($a['status'] ?? 'open') === 'all';
            return array_values(array_map(function ($c) use ($u) { return mcp_case_view($c, $u); }, array_filter(read_json('cases.json', []), function ($c) use ($all) { return $all || $c['status'] === 'open'; })));
        }];
    $t['get_calendar'] = ['title' => 'Calendar', 'description' => 'Upcoming meetings, action due dates, policy reviews and vote deadlines.',
        'inputSchema' => ['type' => 'object', 'properties' => ['days' => ['type' => 'integer', 'description' => 'How many days ahead (default 60)', 'minimum' => 1, 'maximum' => 365]]], 'annotations' => $ro,
        'run' => function ($u, $a) {
            $to = date('Y-m-d', strtotime('+' . max(1, min(365, (int)($a['days'] ?? 60))) . ' days'));
            return array_values(array_map(function ($e) { return ['date' => $e['start'], 'what' => $e['title'], 'type' => $e['kind'], 'where' => $e['where'] ?? '']; },
                array_filter(calendar_events($u), function ($e) use ($to) { return substr($e['start'], 0, 10) >= date('Y-m-d') && substr($e['start'], 0, 10) <= $to; })));
        }];
    $t['list_board_papers'] = ['title' => 'Board papers', 'description' => 'Board papers and policies: title, folder, version, date and whether you still need to read and confirm them. Open them in the portal.',
        'inputSchema' => ['type' => 'object', 'properties' => ['folder' => $str('Optional folder', ['enum' => array_keys(DOC_FOLDERS)])]], 'annotations' => $ro,
        'run' => function ($u, $a) {
            $f = (string)($a['folder'] ?? '');
            return array_values(array_map(function ($d) use ($u) { $o = doc_out($d, $u); return ['title' => $o['title'], 'folder' => $o['folderName'], 'version' => $o['version'], 'updated' => substr($o['at'], 0, 10),
                'review_by' => $o['review'], 'you_need_to_confirm' => $o['requireConfirm'] && !$o['confirmed'], 'link' => site_url() . '/portal#papers']; },
                array_filter(read_json('docs.json', []), function ($d) use ($f) { return $f === '' || $d['folder'] === $f; })));
        }];
    if (is_clerkish($u)) {
        $t['referrals_summary'] = ['title' => 'Referrals summary', 'description' => 'Clerk view of referrals by reference number: status, partner, type of help, amount, days waiting. Initials only – no names or addresses.',
            'inputSchema' => ['type' => 'object', 'properties' => ['status' => $str('Optional status filter', ['enum' => array_keys(REF_STATUSES)])]], 'annotations' => $ro,
            'run' => function ($u, $a) {
                $f = (string)($a['status'] ?? '');
                $l = array_filter(read_json('referrals.json', []), function ($r) use ($f) { return $r['status'] !== 'draft' && ($f === '' || $r['status'] === $f); });
                $counts = [];
                foreach ($l as $r) $counts[$r['status']] = ($counts[$r['status']] ?? 0) + 1;
                return ['counts' => $counts, 'over_10_working_days' => count(array_filter($l, 'referral_is_late')),
                    'referrals' => array_values(array_map(function ($r) { $p = dec($r['enc'], []); return ['reference' => $r['ref'], 'person' => initials($p['name'] ?? ''), 'status' => REF_STATUSES[$r['status']] ?? $r['status'],
                        'partner' => find_org($r['orgId'])['name'] ?? '', 'help_type' => REF_CATEGORIES[$r['category']] ?? '', 'amount_gbp' => $r['amount'], 'submitted' => substr($r['submitted'] ?? '', 0, 10),
                        'working_days_waiting' => !empty($r['submitted']) ? working_days_since($r['submitted']) : 0, 'possible_duplicate' => !empty($r['dup'])]; }, $l)),
                    'pending_partner_applications' => array_values(array_column(array_filter(read_json('orgs.json', []), function ($o) { return $o['status'] === 'pending'; }), 'name'))];
            }];
        $t['add_action'] = ['title' => 'Add an action', 'description' => 'Clerk only: add an action and assign it to a trustee by name, with an optional due date (YYYY-MM-DD).',
            'inputSchema' => ['type' => 'object', 'properties' => ['text' => $str('What needs doing'), 'owner' => $str('Trustee name, e.g. "Susan"'), 'due' => $str('Due date YYYY-MM-DD'), 'meeting_id' => $str('Optional meeting id')], 'required' => ['text']], 'annotations' => $rw + ['idempotentHint' => false],
            'run' => function ($u, $a) {
                $text = clip($a['text'] ?? '', 1000);
                if ($text === '') mcp_fail('Describe the action');
                $owner = !empty($a['owner']) ? mcp_find_user_by_name((string)$a['owner']) : null;
                if (!empty($a['owner']) && !$owner) mcp_fail('No trustee called "' . $a['owner'] . '"');
                $due = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($a['due'] ?? '')) ? $a['due'] : '';
                $x = ['id' => new_id(), 'meetingId' => clip($a['meeting_id'] ?? '', 20), 'itemId' => '', 'text' => $text, 'owner' => $owner['id'] ?? '', 'due' => $due, 'status' => 'not_started', 't' => 0,
                    'created' => now_iso(), 'by' => $u['id'], 'history' => [['at' => now_iso(), 'by' => $u['id'], 'what' => 'Added via Claude']], 'reminded' => false, 'overdueSent' => false];
                with_lock('actions.json', [], function (&$l) use ($x) { $l[] = $x; });
                return ['added' => true, 'id' => $x['id'], 'text' => $text, 'owner' => $owner['name'] ?? 'unassigned', 'due' => $due];
            }];
        $t['post_notice'] = ['title' => 'Post a notice', 'description' => 'Clerk only: post a short notice on every trustee\'s dashboard.',
            'inputSchema' => ['type' => 'object', 'properties' => ['text' => $str('The notice')], 'required' => ['text']], 'annotations' => $rw + ['idempotentHint' => false],
            'run' => function ($u, $a) {
                $n = ['id' => new_id(6), 'text' => clip($a['text'] ?? '', 2000), 'by' => $u['name'], 'at' => now_iso()];
                if ($n['text'] === '') mcp_fail('Write something');
                with_lock('announcements.json', [], function (&$l) use ($n) { array_unshift($l, $n); $l = array_slice($l, 0, 50); });
                return ['posted' => true];
            }];
    }
    if (is_gdpr($u)) {
        $t['gdpr_overview'] = ['title' => 'GDPR deadlines', 'description' => 'Data protection deadlines without personal details: open subject access requests (due dates), open breaches (hours left to tell the ICO), and records due for deletion in 14 days.',
            'inputSchema' => ['type' => 'object', 'properties' => new stdClass()], 'annotations' => $ro,
            'run' => function ($u) {
                return ['requests' => array_values(array_map(function ($s) { $o = sar_out($s); return ['reference' => $s['ref'], 'type' => $s['type'], 'received' => $s['received'], 'status' => $s['status'],
                        'due' => $o['paused'] ? 'paused – identity not confirmed' : $o['due'], 'days_left' => $o['paused'] ? null : $o['daysLeft']]; }, array_filter(read_json('sars.json', []), function ($s) { return $s['status'] !== 'closed'; }))),
                    'breaches' => array_values(array_map(function ($b) { $o = breach_out($b); return ['reference' => $b['ref'], 'risk' => $b['risk'], 'ico_decision' => $b['ico'] ?: 'not decided', 'reported' => (bool)$b['icoAt'], 'hours_left_to_report' => $o['hoursLeft']]; },
                        array_filter(read_json('breaches.json', []), function ($b) { return $b['status'] !== 'closed'; }))),
                    'due_for_deletion_14_days' => array_map(function ($d) { return ['due' => $d['due'], 'what' => $d['type'] === 'referral' ? 'Referral record' : $d['label'], 'action' => $d['action'], 'on_hold' => $d['hold']]; }, retention_due(time() + 14 * 86400)),
                    'link' => site_url() . '/portal#gdpr'];
            }];
    }
    foreach ($t as $k => &$x) $x = ['name' => $k] + $x;
    return $t;
}

function mcp_routes(string $path): bool {
    switch ($path) {
        case '/mcp': case '/mcp/': handle_mcp(); return true;
        case '/.well-known/oauth-protected-resource': case '/.well-known/oauth-protected-resource/mcp': handle_oauth_protected_resource(); return true;
        case '/.well-known/oauth-authorization-server': case '/.well-known/openid-configuration': handle_oauth_as_metadata(); return true;
        case '/oauth/register': handle_oauth_register(); return true;
        case '/oauth/authorize': handle_oauth_authorize(); return true;
        case '/oauth/token': handle_oauth_token(); return true;
        case '/oauth/revoke': handle_oauth_revoke(); return true;
    }
    return false;
}
