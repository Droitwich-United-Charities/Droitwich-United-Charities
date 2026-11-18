<?php
/**
 * DUC Trustee Portal – API and pages.
 * Included by index.php. portal_route() returns true when it handled the request.
 */
declare(strict_types=1);

require_once __DIR__ . '/trustee_duc_lib.php';
require_once __DIR__ . '/trustee_duc_docx.php';
require_once __DIR__ . '/trustee_duc_templates.php';
require_once __DIR__ . '/trustee_duc_gemini.php';
require_once __DIR__ . '/trustee_duc_partners.php';
require_once __DIR__ . '/trustee_duc_letters.php';
require_once __DIR__ . '/trustee_duc_gdpr.php';
require_once __DIR__ . '/trustee_duc_cron.php';

// ---------- guards ----------
/** need: any (board or partner) | board | clerk | chair | gdpr | admin | partner */
function pguard(string $need): array {
    $u = api_guard('self');
    $ok = false;
    switch ($need) {
        case 'any': $ok = is_board($u) || is_partner($u); break;
        case 'board': $ok = is_board($u); break;
        case 'clerk': $ok = is_clerkish($u); break;
        case 'chair': $ok = has_role($u, ['admin', 'chairman']); break;
        case 'gdpr': $ok = is_gdpr($u); break;
        case 'admin': $ok = has_role($u, ['admin']); break;
        case 'partner': $ok = is_partner($u); break;
    }
    if (!$ok) api_err(403, "You don't have access to this");
    if (is_partner($u)) {
        $org = find_org((string)($u['orgId'] ?? ''));
        if (!$org || $org['status'] !== 'approved') api_err(403, 'Your organisation is not active. Please contact the D.U.C. Clerk.');
    }
    return $u;
}
function partner_needs(array $u): array {
    if (!is_partner($u)) return ['agreement' => false, 'privacy' => false];
    $org = find_org((string)($u['orgId'] ?? ''));
    return ['agreement' => (int)($org['agreement']['version'] ?? 0) < agreement()['version'],
        'privacy' => (int)($u['privacyVersion'] ?? 0) < current_notice()['version']];
}
function require_agreed(array $u): void {
    $n = partner_needs($u);
    if ($n['agreement'] || $n['privacy']) api_err(403, 'Please accept the data sharing agreement and privacy notice first');
}
function agreement(): array { $a = read_json('agreement.json', null); return $a ?: ['version' => 1, 'text' => default_agreement(), 'at' => now_iso()]; }

/** Send the JSON reply now, then keep working (for Gemini jobs). */
function respond_then(int $status, $v, callable $work): void {
    @ignore_user_abort(true); @set_time_limit(0);
    $body = json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    http_response_code($status);
    header('Content-Type: application/json'); header('Cache-Control: no-store'); header('Content-Length: ' . strlen($body)); header('Connection: close');
    echo $body;
    if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
    elseif (function_exists('litespeed_finish_request')) litespeed_finish_request();
    else { @ob_end_flush(); flush(); }
    try { $work(); } catch (Throwable $e) { /* recorded by the job itself */ }
    exit;
}

// ---------- me & dashboard ----------
function api_p_me(): void {
    $u = pguard('any');
    $org = is_partner($u) ? find_org((string)$u['orgId']) : null;
    $n = partner_needs($u);
    json_out(200, [
        'user' => ['id' => $u['id'], 'name' => $u['name'], 'username' => $u['username'], 'email' => user_email($u), 'role' => $u['role'], 'roleLabel' => ROLE_LABELS[$u['role']], 'orgAdmin' => !empty($u['orgAdmin'])],
        'perms' => ['board' => is_board($u), 'clerk' => is_clerkish($u), 'chair' => has_role($u, ['admin', 'chairman']), 'gdpr' => is_gdpr($u), 'admin' => has_role($u, ['admin']), 'partner' => is_partner($u)],
        'org' => $org ? public_org($org) : null,
        'needs' => $n,
        'agreement' => $n['agreement'] ? agreement() : null,
        'privacy' => $n['privacy'] ? current_notice() : null,
        'roles' => ROLE_LABELS,
        'setup' => is_board($u) ? setup_status() : null,
    ]);
}
function setup_status(): array {
    $s = portal_settings();
    return ['email' => $s['smtp']['host'] !== '' && $s['smtp']['from'] !== '', 'gemini' => $s['gemini']['key'] !== '', 'siteUrl' => $s['siteUrl'] !== '',
        'cron' => (time() - (int)(read_json('cron.json', [])['lastRun'] ?? 0)) < 2 * 86400];
}

function api_p_dashboard(): void {
    $u = pguard('board');
    $meetings = read_json('meetings.json', []);
    $now = date('Y-m-d\TH:i');
    $next = null;
    foreach ($meetings as $m) if ($m['date'] >= $now && $m['status'] !== 'draft' && (!$next || $m['date'] < $next['date'])) $next = $m;
    if (!$next && is_clerkish($u)) foreach ($meetings as $m) if ($m['date'] >= $now && (!$next || $m['date'] < $next['date'])) $next = $m;
    $mine = array_values(array_filter(read_json('actions.json', []), function ($a) use ($u) { return $a['owner'] === $u['id'] && !in_array($a['status'], ['done'], true); }));
    usort($mine, function ($a, $b) { return strcmp($a['due'] ?: '9999', $b['due'] ?: '9999'); });
    $cases = array_values(array_filter(read_json('cases.json', []), function ($c) { return $c['status'] === 'open'; }));
    $waiting = array_map(function ($c) use ($u) { return ['id' => $c['id'], 'ref' => $c['ref'], 'title' => $c['title'], 'voted' => isset($c['votes'][$u['id']]) || isset($c['conflicts'][$u['id']]), 'votes' => count($c['votes'])]; }, $cases);
    $docs = read_json('docs.json', []);
    usort($docs, function ($a, $b) { return strcmp(end($b['versions'])['at'], end($a['versions'])['at']); });
    $toConfirm = array_values(array_filter($docs, function ($d) use ($u) { return !empty($d['requireConfirm']) && !isset($d['confirms'][$u['id']][(string)count($d['versions'])]); }));
    $out = [
        'next' => $next ? meeting_summary($next, $u) : null,
        'actions' => array_map('action_out', $mine),
        'cases' => $waiting,
        'docs' => array_map(function ($d) { return doc_out($d, null); }, array_slice($docs, 0, 6)),
        'confirm' => array_map(function ($d) { return ['id' => $d['id'], 'title' => $d['title']]; }, $toConfirm),
        'news' => array_slice(read_json('announcements.json', []), 0, 5),
    ];
    if (is_clerkish($u)) {
        $refs = read_json('referrals.json', []);
        $out['referrals'] = ['new' => count(array_filter($refs, function ($r) { return $r['status'] === 'submitted'; })), 'late' => count(array_filter($refs, 'referral_is_late'))];
        $out['orgsPending'] = count(array_filter(read_json('orgs.json', []), function ($o) { return $o['status'] === 'pending'; }));
        $out['review'] = array_values(array_map(function ($m) { return ['id' => $m['id'], 'title' => $m['title'], 'date' => $m['date']]; },
            array_filter($meetings, function ($m) { return ($m['recording']['status'] ?? '') === 'ready' && empty($m['drafts']['actionsPublished']); })));
        $out['letters'] = count(array_filter(read_json('letters.json', []), function ($l) { return $l['status'] === 'draft'; }));
    }
    if (is_gdpr($u)) $out['sars'] = count(array_filter(read_json('sars.json', []), function ($s) { return $s['status'] !== 'closed'; }));
    json_out(200, $out);
}
function api_p_announcements(): void {
    $u = pguard($_SERVER['REQUEST_METHOD'] === 'GET' ? 'board' : 'clerk');
    if ($_SERVER['REQUEST_METHOD'] === 'GET') json_out(200, read_json('announcements.json', []));
    $b = body_json();
    if (!empty($b['delete'])) {
        with_lock('announcements.json', [], function (&$l) use ($b) { $l = array_values(array_filter($l, function ($a) use ($b) { return $a['id'] !== $b['delete']; })); });
        json_out(200, ['ok' => true]);
    }
    $a = ['id' => new_id(6), 'text' => clip($b['text'] ?? '', 2000), 'by' => $u['name'], 'at' => now_iso()];
    if ($a['text'] === '') api_err(400, 'Write something first');
    with_lock('announcements.json', [], function (&$l) use ($a) { array_unshift($l, $a); $l = array_slice($l, 0, 50); });
    audit($u['username'], 'announcement.posted', mb_substr($a['text'], 0, 80));
    json_out(200, $a);
}

// ---------- meetings ----------
function find_meeting(string $id): ?array { foreach (read_json('meetings.json', []) as $m) if ($m['id'] === $id) return $m; return null; }
function update_meeting(string $id, callable $fn) {
    return with_lock('meetings.json', [], function (&$list) use ($id, $fn) {
        foreach ($list as &$m) if ($m['id'] === $id) return $fn($m);
        return null;
    });
}
function meeting_or_404(string $id): array { $m = find_meeting($id); if (!$m) api_err(404, 'Meeting not found'); return $m; }
function meeting_summary(array $m, array $u): array {
    return ['id' => $m['id'], 'title' => $m['title'], 'type' => $m['type'], 'date' => $m['date'], 'venue' => $m['venue'], 'status' => $m['status'],
        'my' => $m['attendance'][$u['id']] ?? '', 'rec' => $m['recording']['status'] ?? 'none', 'items' => count($m['agenda'])];
}
function default_agenda(): array {
    $t = ['Welcome and apologies', 'Declarations of interest', 'Minutes of the last meeting', 'Matters arising', 'Grant requests', 'Finance report', 'Any other business', 'Date of next meeting'];
    return array_map(function ($x) {
        return ['id' => new_id(4), 'title' => $x, 'owner' => '', 'mins' => 5, 'confidential' => $x === 'Grant requests', 'papers' => [], 'kind' => $x === 'Matters arising' ? 'matters' : ''];
    }, $t);
}
function api_p_meetings(): void {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $u = pguard('board');
        $list = read_json('meetings.json', []);
        if (!is_clerkish($u)) $list = array_filter($list, function ($m) { return $m['status'] !== 'draft'; });
        usort($list, function ($a, $b) { return strcmp($b['date'], $a['date']); });
        json_out(200, array_values(array_map(function ($m) use ($u) { return meeting_summary($m, $u); }, $list)));
    }
    $u = pguard('clerk');
    $b = body_json();
    $m = ['id' => new_id(), 'title' => clip($b['title'] ?? '', 160) ?: 'Board meeting', 'type' => in_array($b['type'] ?? '', ['board', 'agm', 'committee'], true) ? $b['type'] : 'board',
        'date' => clip($b['date'] ?? '', 16), 'venue' => clip($b['venue'] ?? '', 200), 'durationMins' => max(15, min(480, (int)($b['durationMins'] ?? 120))),
        'status' => 'draft', 'agenda' => default_agenda(), 'attendance' => [], 'recording' => ['status' => 'none'], 'transcript' => null, 'drafts' => null, 'minutes' => null,
        'created' => now_iso(), 'by' => $u['id']];
    if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $m['date'])) api_err(400, 'Choose a date and time');
    with_lock('meetings.json', [], function (&$l) use ($m) { $l[] = $m; });
    audit($u['username'], 'meeting.created', $m['title'] . ' on ' . $m['date']);
    json_out(200, $m);
}
function api_p_meeting(string $id): void {
    $method = $_SERVER['REQUEST_METHOD'];
    if ($method === 'GET') {
        $u = pguard('board');
        $m = meeting_or_404($id);
        if ($m['status'] === 'draft' && !is_clerkish($u)) api_err(404, 'Meeting not found');
        $actions = read_json('actions.json', []);
        $m['actions'] = array_values(array_map('action_out', array_filter($actions, function ($a) use ($id) { return $a['meetingId'] === $id; })));
        $m['openActions'] = array_values(array_map('action_out', array_filter($actions, function ($a) use ($id) { return $a['meetingId'] !== $id && $a['status'] !== 'done'; })));
        $docs = read_json('docs.json', []);
        $m['paperDocs'] = [];
        foreach ($docs as $d) $m['paperDocs'][$d['id']] = ['id' => $d['id'], 'title' => $d['title']];
        $m['people'] = array_map(function ($x) { return ['id' => $x['id'], 'name' => $x['name'], 'role' => ROLE_LABELS[$x['role']]]; }, users_with_roles(BOARD_ROLES));
        if (!is_clerkish($u)) { unset($m['drafts']); if (($m['status'] ?? '') !== 'minutes_approved') $m['transcript'] = null; }
        $m['hasAudio'] = !empty($m['recording']['file']) && is_file(files_dir('recordings') . '/' . $m['recording']['file']);
        json_out(200, $m);
    }
    $u = pguard('clerk');
    if ($method === 'DELETE') {
        $m = meeting_or_404($id);
        if (!in_array($m['status'], ['draft', 'published'], true) && !has_role($u, ['admin'])) api_err(400, 'Only the Global Admin can delete a meeting that has been held');
        with_lock('meetings.json', [], function (&$l) use ($id) { $l = array_values(array_filter($l, function ($m) use ($id) { return $m['id'] !== $id; })); });
        foreach (glob(files_dir('recordings') . '/' . $id . '.*') ?: [] as $f) @unlink($f);
        audit($u['username'], 'meeting.deleted', $m['title']);
        json_out(200, ['ok' => true]);
    }
    $b = body_json();
    $res = update_meeting($id, function (&$m) use ($b) {
        if ($m['status'] === 'minutes_approved') return 'locked';
        foreach (['title' => 160, 'venue' => 200] as $k => $n) if (isset($b[$k])) $m[$k] = clip($b[$k], $n);
        if (isset($b['date']) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', (string)$b['date'])) $m['date'] = $b['date'];
        if (isset($b['type']) && in_array($b['type'], ['board', 'agm', 'committee'], true)) $m['type'] = $b['type'];
        if (isset($b['durationMins'])) $m['durationMins'] = max(15, min(480, (int)$b['durationMins']));
        if (isset($b['agenda']) && is_array($b['agenda'])) {
            $m['agenda'] = array_values(array_map(function ($a) {
                return ['id' => preg_match('/^[a-f0-9]{8}$/', (string)($a['id'] ?? '')) ? $a['id'] : new_id(4), 'title' => clip($a['title'] ?? '', 200) ?: 'Item',
                    'owner' => clip($a['owner'] ?? '', 20), 'mins' => max(0, min(240, (int)($a['mins'] ?? 5))), 'confidential' => !empty($a['confidential']),
                    'papers' => array_values(array_filter(array_map('strval', (array)($a['papers'] ?? [])), function ($p) { return (bool)preg_match('/^[a-f0-9]{16}$/', $p); })),
                    'kind' => ($a['kind'] ?? '') === 'matters' ? 'matters' : ''];
            }, array_slice($b['agenda'], 0, 60)));
        }
        return 'ok';
    });
    if ($res === null) api_err(404, 'Meeting not found');
    if ($res === 'locked') api_err(400, 'These minutes are approved and locked');
    audit($u['username'], 'meeting.updated', meeting_or_404($id)['title']);
    json_out(200, ['ok' => true]);
}
function api_p_meeting_publish(string $id): void {
    $u = pguard('clerk');
    $m = meeting_or_404($id);
    update_meeting($id, function (&$x) { if ($x['status'] === 'draft') $x['status'] = 'published'; $x['publishedAt'] = now_iso(); });
    $m = meeting_or_404($id);
    $sent = 0;
    $tpl = letter_templates()['meeting_invite'];
    $fields = ['meeting_title' => $m['title'], 'meeting_date' => date('l j F Y, g.ia', strtotime($m['date'])), 'meeting_venue' => $m['venue'], 'portal_link' => site_url() . '/portal#meeting/' . $id];
    $ics = ics_event($m, 'REQUEST');
    foreach (users_with_roles(BOARD_ROLES) as $p) {
        if (!user_email($p)) continue;
        $f = $fields + ['salutation' => $p['name']];
        $html = '<p>Dear ' . h($p['name']) . ',</p>' . text_to_html(merge_fields($tpl['body'], $f)) . signoff_html($tpl['sign']);
        $html = str_replace(h($fields['portal_link']), email_button($fields['portal_link'], 'Open the meeting papers'), $html);
        send_email(user_email($p), merge_fields($tpl['subject'], $f), $html, [['name' => 'meeting.ics', 'type' => 'text/calendar; method=REQUEST; charset=UTF-8', 'data' => $ics]], 'meeting invitation');
        $sent++;
    }
    audit($u['username'], 'meeting.published', $m['title'] . " – invitations sent to $sent people");
    json_out(200, ['ok' => true, 'sent' => $sent]);
}
function api_p_meeting_rsvp(string $id): void {
    $u = pguard('board');
    $b = body_json();
    $st = (string)($b['status'] ?? '');
    $who = $u['id'];
    if (!empty($b['userId']) && is_clerkish($u)) $who = (string)$b['userId'];
    if (!in_array($st, ['attending', 'apologies', 'absent', ''], true)) api_err(400, 'Invalid choice');
    $r = update_meeting($id, function (&$m) use ($who, $st) { if ($st === '') unset($m['attendance'][$who]); else $m['attendance'][$who] = $st; return true; });
    if (!$r) api_err(404, 'Meeting not found');
    audit($u['username'], 'meeting.attendance', meeting_or_404($id)['title'] . ': ' . user_name($who) . ' – ' . ($st ?: 'cleared'));
    json_out(200, ['ok' => true]);
}
function api_p_meeting_ics(string $id): void {
    pguard('board');
    $m = meeting_or_404($id);
    header('Content-Type: text/calendar; charset=utf-8');
    header('Content-Disposition: attachment; filename="duc-meeting.ics"');
    echo ics_event($m); exit;
}

// ---------- recording ----------
const AUDIO_TYPES = ['audio/wav' => 'wav', 'audio/x-wav' => 'wav', 'audio/mpeg' => 'mp3', 'audio/mp3' => 'mp3', 'audio/ogg' => 'ogg', 'audio/flac' => 'flac', 'audio/aac' => 'aac', 'audio/x-m4a' => 'm4a', 'audio/mp4' => 'm4a', 'video/mp4' => 'mp4'];
function api_p_rec_start(string $id): void {
    $u = pguard('clerk');
    $b = body_json();
    $method = ($b['method'] ?? 'room') === 'upload' ? 'upload' : 'room';
    if ($method === 'room' && empty($b['consent'])) api_err(400, 'The Chairman must confirm everyone has agreed to the recording');
    $mime = $method === 'room' ? 'audio/wav' : (string)($b['mime'] ?? '');
    if (!isset(AUDIO_TYPES[$mime])) api_err(400, 'Use an MP3, WAV, M4A, OGG, FLAC or MP4 file');
    $m = meeting_or_404($id);
    if ($m['status'] === 'minutes_approved') api_err(400, 'These minutes are approved and locked');
    foreach (glob(files_dir('recordings') . '/' . $id . '.*') ?: [] as $f) @unlink($f);
    update_meeting($id, function (&$m) use ($u, $method, $mime, $b) {
        $m['recording'] = ['status' => 'recording', 'method' => $method, 'mime' => $mime, 'next' => 0, 'markers' => [], 'consentBy' => $u['id'], 'consentAt' => now_iso(),
            'consentNote' => $method === 'room' ? 'Chairman confirmed everyone present was told and agreed' : 'Uploaded recording', 'sampleRate' => (int)($b['sampleRate'] ?? 16000),
            'size' => (int)($b['size'] ?? 0), 'name' => clip($b['name'] ?? '', 120)];
        $m['transcript'] = null; $m['drafts'] = null;
        if ($m['status'] === 'published' || $m['status'] === 'draft') $m['status'] = 'held';
    });
    audit($u['username'], $method === 'room' ? 'recording.started' : 'recording.upload_started', $m['title'] . ($method === 'room' ? ' – consent confirmed' : ''));
    json_out(200, ['ok' => true]);
}
function api_p_rec_chunk(string $id): void {
    pguard('clerk');
    $seq = (int)($_GET['seq'] ?? -1);
    $m = meeting_or_404($id);
    $r = $m['recording'];
    if (($r['status'] ?? '') !== 'recording') api_err(409, 'Recording is not running');
    $data = file_get_contents('php://input');
    if ($data === false || strlen($data) > 2200000) api_err(413, 'Chunk too large');
    if ($seq < $r['next']) json_out(200, ['next' => $r['next']]);
    if ($seq > $r['next']) json_out(409, ['error' => 'Out of order', 'next' => $r['next']]);
    $part = files_dir('recordings') . '/' . $id . '.part';
    if (filesize_safe($part) + strlen($data) > 1900 * 1024 * 1024) api_err(413, 'Recording is too long');
    file_put_contents($part, $data, FILE_APPEND | LOCK_EX);
    update_meeting($id, function (&$m) use ($seq) { $m['recording']['next'] = $seq + 1; });
    json_out(200, ['next' => $seq + 1]);
}
function api_p_rec_marker(string $id): void {
    $u = pguard('clerk');
    $b = body_json();
    $mk = ['t' => max(0, (int)($b['t'] ?? 0)), 'itemId' => clip($b['itemId'] ?? '', 20), 'type' => in_array($b['type'] ?? '', ['item', 'pause', 'resume'], true) ? $b['type'] : 'item'];
    update_meeting($id, function (&$m) use ($mk) { $m['recording']['markers'][] = $mk; });
    if ($mk['type'] !== 'item') audit($u['username'], 'recording.' . $mk['type'], meeting_or_404($id)['title'] . ' at ' . hms($mk['t']));
    json_out(200, ['ok' => true]);
}
function api_p_rec_finish(string $id): void {
    $u = pguard('clerk');
    $b = body_json();
    $m = meeting_or_404($id);
    $r = $m['recording'];
    if (($r['status'] ?? '') !== 'recording') api_err(409, 'Recording is not running');
    $dir = files_dir('recordings');
    $part = "$dir/$id.part";
    $size = filesize_safe($part);
    if ($size < 1000) api_err(400, 'Nothing was recorded');
    if ($r['method'] === 'room') {
        $rate = max(8000, min(48000, (int)($b['sampleRate'] ?? $r['sampleRate'])));
        $out = "$dir/$id.wav";
        $hdr = 'RIFF' . pack('V', 36 + $size) . 'WAVE' . 'fmt ' . pack('VvvVVvv', 16, 1, 1, $rate, $rate * 2, 2, 16) . 'data' . pack('V', $size);
        $in = fopen($part, 'rb'); $o = fopen($out, 'wb');
        fwrite($o, $hdr); stream_copy_to_stream($in, $o); fclose($in); fclose($o);
        @unlink($part);
        $file = "$id.wav"; $duration = (int)round($size / ($rate * 2));
    } else {
        if ($r['size'] && $size !== $r['size']) api_err(400, 'The upload is incomplete – please try again');
        $file = $id . '.' . AUDIO_TYPES[$r['mime']];
        rename($part, "$dir/$file");
        $duration = max(0, (int)($b['duration'] ?? 0));
    }
    update_meeting($id, function (&$m) use ($file, $duration, $size) {
        $m['recording']['status'] = 'uploaded'; $m['recording']['file'] = $file; $m['recording']['duration'] = $duration; $m['recording']['bytes'] = $size; $m['recording']['finishedAt'] = now_iso();
    });
    audit($u['username'], 'recording.saved', $m['title'] . ' – ' . hms($duration) . ', ' . round($size / 1048576, 1) . ' MB');
    if (portal_settings()['gemini']['key'] === '') json_out(200, ['ok' => true, 'processing' => false, 'message' => 'Saved. Add a Gemini API key in Portal settings to create the transcript.']);
    respond_then(200, ['ok' => true, 'processing' => true], function () use ($id) { process_meeting_recording($id); });
}
function api_p_rec_process(string $id): void {
    $u = pguard('clerk');
    $m = meeting_or_404($id);
    if (empty($m['recording']['file'])) api_err(400, 'There is no recording for this meeting');
    if (($m['recording']['status'] ?? '') === 'processing' && strtotime($m['recording']['startedAt'] ?? '') > time() - 1800) api_err(409, 'Already working on it');
    audit($u['username'], 'meeting.ai_requested', $m['title']);
    respond_then(200, ['ok' => true], function () use ($id) { process_meeting_recording($id); });
}
function api_p_rec_audio(string $id): void {
    $u = pguard('board');
    $m = meeting_or_404($id);
    if (!is_clerkish($u) && $m['status'] !== 'minutes_approved') api_err(403, 'Not available yet');
    $f = $m['recording']['file'] ?? '';
    if (!isset($_SERVER['HTTP_RANGE']) || preg_match('/bytes=0-/', (string)$_SERVER['HTTP_RANGE'])) audit($u['username'], 'recording.played', $m['title']);
    send_file(files_dir('recordings') . '/' . $f, 'duc-meeting.' . pathinfo($f, PATHINFO_EXTENSION), $m['recording']['mime'] ?? 'audio/wav', true);
}
function api_p_review(string $id): void {
    $u = pguard('clerk');
    $b = body_json();
    $validUsers = array_column(users_with_roles(BOARD_ROLES), 'id');
    $r = update_meeting($id, function (&$m) use ($b, $validUsers) {
        if ($m['status'] === 'minutes_approved') return 'locked';
        if (isset($b['speakers']) && is_array($b['speakers']) && $m['transcript']) {
            $sp = [];
            foreach ($b['speakers'] as $label => $uid) if (in_array($uid, $validUsers, true)) $sp[clip($label, 40)] = $uid;
            $m['transcript']['speakers'] = $sp;
        }
        if (isset($b['segments']) && is_array($b['segments']) && $m['transcript']) {
            foreach ($b['segments'] as $i => $txt) if (isset($m['transcript']['segments'][(int)$i])) $m['transcript']['segments'][(int)$i]['text'] = clip($txt, 4000);
        }
        if (isset($b['drafts']) && is_array($b['drafts'])) {
            $d = $b['drafts']; $old = $m['drafts'] ?: [];
            $m['drafts'] = array_merge($old, [
                'items' => array_map(function ($i) { return ['itemId' => clip($i['itemId'] ?? '', 20), 'summary' => clip($i['summary'] ?? '', 4000)]; }, (array)($d['items'] ?? $old['items'] ?? [])),
                'decisions' => array_map(function ($i) { return ['itemId' => clip($i['itemId'] ?? '', 20), 'text' => clip($i['text'] ?? '', 2000), 't' => (int)($i['t'] ?? 0)]; }, (array)($d['decisions'] ?? $old['decisions'] ?? [])),
                'actions' => array_map(function ($a) use ($validUsers) {
                    return ['id' => preg_match('/^[a-f0-9]{12}$/', (string)($a['id'] ?? '')) ? $a['id'] : new_id(6), 'text' => clip($a['text'] ?? '', 1000),
                        'owner' => in_array($a['owner'] ?? '', $validUsers, true) ? $a['owner'] : '', 'ownerName' => clip($a['ownerName'] ?? '', 120),
                        'due' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($a['due'] ?? '')) ? $a['due'] : '', 'itemId' => clip($a['itemId'] ?? '', 20), 't' => (int)($a['t'] ?? 0)];
                }, (array)($d['actions'] ?? $old['actions'] ?? [])),
                'questions' => array_map(function ($q) { return clip($q, 1000); }, (array)($d['questions'] ?? $old['questions'] ?? [])),
                'minutes' => clip($d['minutes'] ?? ($old['minutes'] ?? ''), 60000),
                'editedBy' => '', 'editedAt' => now_iso(),
            ]);
        }
        return 'ok';
    });
    if ($r === null) api_err(404, 'Meeting not found');
    if ($r === 'locked') api_err(400, 'These minutes are approved and locked');
    update_meeting($id, function (&$m) use ($u) { if ($m['drafts']) $m['drafts']['editedBy'] = $u['id']; });
    audit($u['username'], 'meeting.reviewed', meeting_or_404($id)['title']);
    json_out(200, ['ok' => true]);
}
/** Clerk + Chairman: turn the reviewed actions into real actions and email owners. */
function api_p_publish_actions(string $id): void {
    $u = pguard('clerk');
    $m = meeting_or_404($id);
    if (!$m['drafts']) api_err(400, 'There are no drafts to publish yet');
    if (!empty($m['drafts']['actionsPublished'])) api_err(400, 'Actions for this meeting have already been published');
    $new = [];
    foreach ($m['drafts']['actions'] as $a) {
        if (trim($a['text']) === '') continue;
        $new[] = ['id' => new_id(), 'meetingId' => $id, 'itemId' => $a['itemId'], 'text' => $a['text'], 'owner' => $a['owner'], 'due' => $a['due'], 'status' => 'not_started', 't' => (int)$a['t'],
            'created' => now_iso(), 'by' => $u['id'], 'history' => [['at' => now_iso(), 'by' => $u['id'], 'what' => 'Agreed at ' . $m['title']]], 'reminded' => false, 'overdueSent' => false];
    }
    with_lock('actions.json', [], function (&$l) use ($new) { foreach ($new as $a) $l[] = $a; });
    update_meeting($id, function (&$m) use ($u) { $m['drafts']['actionsPublished'] = true; $m['drafts']['publishedBy'] = $u['id']; $m['drafts']['publishedAt'] = now_iso(); });
    $tpl = letter_templates()['actions'];
    $byOwner = [];
    foreach ($new as $a) if ($a['owner']) $byOwner[$a['owner']][] = $a;
    foreach ($byOwner as $uid => $list) {
        $p = user_by_id($uid);
        if (!$p || !user_email($p)) continue;
        $f = ['meeting_title' => $m['title'], 'meeting_date' => uk_date($m['date']), 'portal_link' => site_url() . '/portal#actions',
            'action_list' => implode("\n", array_map(function ($a) { return '- ' . $a['text'] . ($a['due'] ? ' (by ' . uk_date($a['due']) . ')' : ''); }, $list))];
        $html = '<p>Dear ' . h($p['name']) . ',</p>' . text_to_html(merge_fields($tpl['body'], $f)) . signoff_html($tpl['sign']);
        $html = str_replace(h($f['portal_link']), email_button($f['portal_link'], 'See my actions'), $html);
        send_email(user_email($p), merge_fields($tpl['subject'], $f), $html, [], 'actions');
    }
    audit($u['username'], 'actions.published', count($new) . ' actions from ' . $m['title']);
    json_out(200, ['ok' => true, 'created' => count($new)]);
}
function api_p_approve_minutes(string $id): void {
    $u = pguard('chair');
    $m = meeting_or_404($id);
    $b = body_json();
    $text = clip($b['minutes'] ?? ($m['drafts']['minutes'] ?? ''), 60000);
    if (trim($text) === '') api_err(400, 'There are no minutes to approve');
    update_meeting($id, function (&$m) use ($u, $text) {
        $m['minutes'] = ['text' => $text, 'approvedBy' => $u['id'], 'approvedAt' => now_iso()];
        $m['status'] = 'minutes_approved';
    });
    $m = meeting_or_404($id);
    $docx = minutes_docx($m);
    // keep a locked copy in Board papers > Minutes
    $fid = new_id(10);
    file_put_contents(files_dir('docs') . "/$fid.docx", $docx);
    $doc = ['id' => new_id(), 'folder' => 'minutes', 'title' => 'Minutes – ' . $m['title'] . ', ' . uk_date($m['date']), 'requireConfirm' => false, 'confirms' => [], 'meetingId' => $id, 'locked' => true,
        'versions' => [['v' => 1, 'file' => "$fid.docx", 'name' => 'DUC minutes ' . date('Y-m-d', strtotime($m['date'])) . '.docx', 'type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'size' => strlen($docx), 'by' => $u['id'], 'at' => now_iso()]]];
    with_lock('docs.json', [], function (&$l) use ($doc) { $l[] = $doc; });
    $tpl = letter_templates()['minutes_approved'];
    $f = ['meeting_title' => $m['title'], 'meeting_date' => uk_date($m['date']), 'portal_link' => site_url() . '/portal#meeting/' . $id];
    foreach (users_with_roles(BOARD_ROLES) as $p) {
        if (!user_email($p)) continue;
        $html = '<p>Dear ' . h($p['name']) . ',</p>' . text_to_html(merge_fields($tpl['body'], $f)) . signoff_html($tpl['sign']);
        $html = str_replace(h($f['portal_link']), email_button($f['portal_link'], 'Open in the portal'), $html);
        send_email(user_email($p), merge_fields($tpl['subject'], $f), $html, [['name' => $doc['versions'][0]['name'], 'type' => $doc['versions'][0]['type'], 'data' => $docx]], 'approved minutes');
    }
    audit($u['username'], 'minutes.approved', $m['title'] . ' – minutes locked; audio will be deleted after ' . portal_settings()['retention']['recording_days'] . ' days');
    json_out(200, ['ok' => true]);
}
function minutes_docx(array $m): string {
    $present = []; $apol = [];
    foreach ($m['attendance'] ?? [] as $uid => $st) { if ($st === 'attending') $present[] = user_name($uid); if ($st === 'apologies') $apol[] = user_name($uid); }
    $text = ($m['venue'] ? '**Venue:** ' . $m['venue'] . "\n" : '') . '**Present:** ' . (implode(', ', $present) ?: '—') . "\n**Apologies:** " . (implode(', ', $apol) ?: 'None') . "\n\n";
    $text .= $m['minutes']['text'] ?? ($m['drafts']['minutes'] ?? '');
    $acts = array_filter(read_json('actions.json', []), function ($a) use ($m) { return $a['meetingId'] === $m['id']; });
    if ($acts) $text .= "\n\n## Actions\n\n" . implode("\n", array_map(function ($a) { return '- ' . $a['text'] . ' – ' . (user_name($a['owner']) ?: 'unassigned') . ($a['due'] ? ', by ' . uk_date($a['due']) : ''); }, $acts));
    if (!empty($m['minutes']['approvedAt'])) $text .= "\n\nApproved by " . user_name($m['minutes']['approvedBy']) . ' on ' . uk_date($m['minutes']['approvedAt']) . '.';
    $sub = uk_date($m['date']) . (empty($m['minutes']['approvedAt']) ? '  •  DRAFT' : '');
    return build_document_docx('Minutes: ' . $m['title'], $sub, $text);
}
function api_p_minutes_docx(string $id): void {
    $u = pguard('board');
    $m = meeting_or_404($id);
    if ($m['status'] !== 'minutes_approved' && !is_clerkish($u)) api_err(403, 'Not approved yet');
    audit($u['username'], 'minutes.downloaded', $m['title']);
    header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    header('Content-Disposition: attachment; filename="DUC minutes ' . date('Y-m-d', strtotime($m['date'])) . '.docx"');
    echo minutes_docx($m); exit;
}

// ---------- actions ----------
const ACTION_STATUSES = ['not_started', 'in_progress', 'done', 'blocked', 'carried'];
function action_out(array $a): array {
    $a['ownerName'] = user_name($a['owner']);
    $m = $a['meetingId'] ? find_meeting($a['meetingId']) : null;
    $a['meetingTitle'] = $m ? $m['title'] . ' (' . uk_date($m['date']) . ')' : '';
    $a['overdue'] = $a['due'] && $a['due'] < date('Y-m-d') && $a['status'] !== 'done';
    return $a;
}
function api_p_actions(): void {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $u = pguard('board');
        $list = read_json('actions.json', []);
        if (!empty($_GET['mine'])) $list = array_filter($list, function ($a) use ($u) { return $a['owner'] === $u['id']; });
        $list = array_values(array_map('action_out', $list));
        usort($list, function ($a, $b) { return [$a['status'] === 'done', $b['overdue'], $a['due'] ?: '9999'] <=> [$b['status'] === 'done', $a['overdue'], $b['due'] ?: '9999']; });
        json_out(200, $list);
    }
    $u = pguard('clerk');
    $b = body_json();
    $a = ['id' => new_id(), 'meetingId' => clip($b['meetingId'] ?? '', 20), 'itemId' => '', 'text' => clip($b['text'] ?? '', 1000), 'owner' => clip($b['owner'] ?? '', 20),
        'due' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($b['due'] ?? '')) ? $b['due'] : '', 'status' => 'not_started', 't' => 0, 'created' => now_iso(), 'by' => $u['id'],
        'history' => [['at' => now_iso(), 'by' => $u['id'], 'what' => 'Added by hand']], 'reminded' => false, 'overdueSent' => false];
    if ($a['text'] === '') api_err(400, 'Describe the action');
    with_lock('actions.json', [], function (&$l) use ($a) { $l[] = $a; });
    audit($u['username'], 'action.created', $a['text'] . ' → ' . (user_name($a['owner']) ?: 'unassigned'));
    json_out(200, action_out($a));
}
function api_p_action(string $id): void {
    $u = pguard('board');
    $method = $_SERVER['REQUEST_METHOD'];
    if ($method === 'DELETE') {
        if (!is_clerkish($u)) api_err(403, 'Only the Clerk can remove actions');
        $gone = null;
        with_lock('actions.json', [], function (&$l) use ($id, &$gone) { foreach ($l as $i => $a) if ($a['id'] === $id) { $gone = $a; array_splice($l, $i, 1); return; } });
        if (!$gone) api_err(404, 'Action not found');
        audit($u['username'], 'action.deleted', $gone['text']);
        json_out(200, ['ok' => true]);
    }
    $b = body_json();
    $clerk = is_clerkish($u);
    $res = with_lock('actions.json', [], function (&$l) use ($id, $b, $u, $clerk) {
        foreach ($l as &$a) if ($a['id'] === $id) {
            if (!$clerk && $a['owner'] !== $u['id']) return [403, 'You can only update your own actions'];
            $notes = [];
            if (isset($b['status']) && in_array($b['status'], ACTION_STATUSES, true) && $b['status'] !== $a['status']) { $notes[] = 'status → ' . $b['status']; $a['status'] = $b['status']; if ($b['status'] === 'done') $a['doneAt'] = now_iso(); }
            if ($clerk) {
                if (isset($b['text']) && clip($b['text'], 1000) !== '' && $b['text'] !== $a['text']) { $notes[] = 'text edited'; $a['text'] = clip($b['text'], 1000); }
                if (isset($b['owner']) && $b['owner'] !== $a['owner']) { $notes[] = 'reassigned to ' . (user_name($b['owner']) ?: 'nobody'); $a['owner'] = clip($b['owner'], 20); $a['reminded'] = false; }
                if (isset($b['due']) && $b['due'] !== $a['due'] && ($b['due'] === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$b['due']))) { $notes[] = 'due ' . ($b['due'] ?: 'none'); $a['due'] = $b['due']; $a['reminded'] = false; $a['overdueSent'] = false; }
            }
            if (!empty($b['note'])) $notes[] = 'note: ' . clip($b['note'], 500);
            if ($notes) $a['history'][] = ['at' => now_iso(), 'by' => $u['id'], 'what' => implode('; ', $notes)];
            return [200, $a, $notes];
        }
        return [404, 'Action not found'];
    });
    if ($res[0] !== 200) api_err($res[0], $res[1]);
    if ($res[2]) audit($u['username'], 'action.updated', $res[1]['text'] . ': ' . implode('; ', $res[2]));
    json_out(200, action_out($res[1]));
}

// ---------- documents (board papers) ----------
const DOC_FOLDERS = ['papers' => 'Meeting papers', 'minutes' => 'Minutes', 'governing' => 'Governing documents', 'policies' => 'Policies', 'finance' => 'Financial reports', 'conflicts' => 'Conflicts of interest register', 'induction' => 'Trustee induction'];
function doc_out(array $d, ?array $u): array {
    $v = end($d['versions']);
    $o = ['id' => $d['id'], 'folder' => $d['folder'], 'folderName' => DOC_FOLDERS[$d['folder']] ?? $d['folder'], 'title' => $d['title'], 'version' => count($d['versions']),
        'name' => $v['name'], 'size' => $v['size'], 'at' => $v['at'], 'by' => user_name($v['by']), 'requireConfirm' => !empty($d['requireConfirm']), 'locked' => !empty($d['locked']),
        'review' => $d['review'] ?? '', 'history' => array_map(function ($x) { return ['v' => $x['v'], 'at' => $x['at'], 'by' => user_name($x['by']), 'name' => $x['name']]; }, $d['versions'])];
    if ($u) {
        $o['confirmed'] = isset($d['confirms'][$u['id']][(string)count($d['versions'])]);
        if (is_clerkish($u) && !empty($d['requireConfirm'])) {
            $o['notConfirmed'] = array_values(array_map(function ($p) { return $p['name']; }, array_filter(users_with_roles(BOARD_ROLES), function ($p) use ($d) { return !isset($d['confirms'][$p['id']][(string)count($d['versions'])]); })));
        }
    }
    return $o;
}
function api_p_docs(): void {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $u = pguard('board');
        $l = read_json('docs.json', []);
        usort($l, function ($a, $b) { return strcmp($a['title'], $b['title']); });
        json_out(200, ['folders' => DOC_FOLDERS, 'docs' => array_map(function ($d) use ($u) { return doc_out($d, $u); }, $l)]);
    }
    $u = pguard('clerk');
    $b = body_json();
    $f = claim_upload($u, (string)($b['uploadId'] ?? ''), 'docs');
    $ver = ['v' => 1, 'file' => $f['path'], 'name' => $f['name'], 'type' => $f['type'], 'size' => $f['size'], 'by' => $u['id'], 'at' => now_iso()];
    if (!empty($b['docId'])) {
        $r = with_lock('docs.json', [], function (&$l) use ($b, $ver) {
            foreach ($l as &$d) if ($d['id'] === $b['docId']) { if (!empty($d['locked'])) return 'locked'; $ver['v'] = count($d['versions']) + 1; $d['versions'][] = $ver; return $d; }
            return null;
        });
        if ($r === 'locked') api_err(400, 'Approved minutes are locked');
        if (!$r) api_err(404, 'Document not found');
        audit($u['username'], 'doc.new_version', $r['title'] . ' – version ' . count($r['versions']));
        json_out(200, doc_out($r, $u));
    }
    $folder = isset(DOC_FOLDERS[$b['folder'] ?? '']) ? $b['folder'] : 'papers';
    $d = ['id' => new_id(), 'folder' => $folder, 'title' => clip($b['title'] ?? '', 200) ?: $f['name'], 'requireConfirm' => !empty($b['requireConfirm']), 'confirms' => [],
        'review' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($b['review'] ?? '')) ? $b['review'] : '', 'versions' => [$ver]];
    with_lock('docs.json', [], function (&$l) use ($d) { $l[] = $d; });
    if (!empty($b['meetingId']) && !empty($b['itemId'])) {
        update_meeting((string)$b['meetingId'], function (&$m) use ($b, $d) { foreach ($m['agenda'] as &$a) if ($a['id'] === $b['itemId']) $a['papers'][] = $d['id']; });
    }
    audit($u['username'], 'doc.uploaded', $d['title'] . ' in ' . DOC_FOLDERS[$folder]);
    json_out(200, doc_out($d, $u));
}
function find_doc(string $id): ?array { foreach (read_json('docs.json', []) as $d) if ($d['id'] === $id) return $d; return null; }
function api_p_doc_file(string $id): void {
    $u = pguard('board');
    $d = find_doc($id);
    if (!$d) api_err(404, 'Document not found');
    $v = (int)($_GET['v'] ?? count($d['versions']));
    $ver = $d['versions'][max(0, min(count($d['versions']), $v) - 1)];
    audit($u['username'], 'doc.downloaded', $d['title'] . ' v' . $ver['v']);
    send_file(files_dir('docs') . '/' . $ver['file'], $ver['name'], $ver['type'], $ver['type'] === 'application/pdf');
}
function api_p_doc_confirm(string $id): void {
    $u = pguard('board');
    $r = with_lock('docs.json', [], function (&$l) use ($id, $u) { foreach ($l as &$d) if ($d['id'] === $id) { $d['confirms'][$u['id']][(string)count($d['versions'])] = now_iso(); return $d; } return null; });
    if (!$r) api_err(404, 'Document not found');
    audit($u['username'], 'doc.read_confirmed', $r['title'] . ' v' . count($r['versions']));
    json_out(200, ['ok' => true]);
}
function api_p_doc(string $id): void {
    $u = pguard('clerk');
    $method = $_SERVER['REQUEST_METHOD'];
    $d = find_doc($id);
    if (!$d) api_err(404, 'Document not found');
    if ($method === 'DELETE') {
        if (!empty($d['locked']) && !has_role($u, ['admin'])) api_err(400, 'Approved minutes can only be removed by the Global Admin');
        with_lock('docs.json', [], function (&$l) use ($id) { $l = array_values(array_filter($l, function ($d) use ($id) { return $d['id'] !== $id; })); });
        foreach ($d['versions'] as $v) @unlink(files_dir('docs') . '/' . $v['file']);
        audit($u['username'], 'doc.deleted', $d['title']);
        json_out(200, ['ok' => true]);
    }
    $b = body_json();
    with_lock('docs.json', [], function (&$l) use ($id, $b) {
        foreach ($l as &$d) if ($d['id'] === $id) {
            if (isset($b['title']) && clip($b['title'], 200) !== '') $d['title'] = clip($b['title'], 200);
            if (isset($b['folder']) && isset(DOC_FOLDERS[$b['folder']]) && empty($d['locked'])) $d['folder'] = $b['folder'];
            if (isset($b['requireConfirm'])) $d['requireConfirm'] = (bool)$b['requireConfirm'];
            if (isset($b['review'])) $d['review'] = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$b['review']) ? $b['review'] : '';
        }
    });
    audit($u['username'], 'doc.updated', $d['title']);
    json_out(200, ['ok' => true]);
}

// ---------- decisions & voting ----------
function find_case(string $id): ?array { foreach (read_json('cases.json', []) as $c) if ($c['id'] === $id) return $c; return null; }
function case_out(array $c, array $u): array {
    $s = portal_settings()['votes'];
    $tally = ['approve' => 0, 'decline' => 0, 'abstain' => 0, 'more_info' => 0];
    foreach ($c['votes'] as $v) $tally[$v['vote']] = ($tally[$v['vote']] ?? 0) + 1;
    $cast = array_sum($tally);
    $c['tally'] = $tally;
    $c['quorum'] = (int)$s['quorum'];
    $c['quorate'] = $cast >= (int)$s['quorum'];
    $c['suggested'] = !$c['quorate'] ? '' : ($tally['approve'] > $tally['decline'] + $tally['more_info'] ? 'approved' : ($tally['more_info'] >= $tally['decline'] && $tally['more_info'] > 0 ? 'more_info' : 'declined'));
    $c['mine'] = $c['votes'][$u['id']] ?? null;
    $c['myConflict'] = $c['conflicts'][$u['id']] ?? null;
    $c['voters'] = [];
    foreach ($c['votes'] as $uid => $v) $c['voters'][] = ['name' => user_name($uid), 'vote' => $v['vote'], 'comment' => $v['comment'], 'at' => $v['at']];
    $c['conflicted'] = [];
    foreach ($c['conflicts'] as $uid => $x) $c['conflicted'][] = ['name' => user_name($uid), 'reason' => $x['reason'], 'at' => $x['at']];
    unset($c['votes'], $c['conflicts']);
    $r = find_referral($c['referralId']);
    if ($r) {
        $p = dec($r['enc'], []);
        $c['referral'] = ['ref' => $r['ref'], 'org' => find_org($r['orgId'])['name'] ?? '', 'initials' => initials($p['name'] ?? ''), 'category' => $r['category'], 'amount' => $r['amount'],
            'need' => $p['need'] ?? '', 'why' => $p['why'] ?? '', 'tried' => $p['tried'] ?? '', 'household' => $p['household'] ?? '', 'files' => count($r['files'])];
    }
    return $c;
}
function api_p_cases(): void {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $u = pguard('board');
        $l = read_json('cases.json', []);
        usort($l, function ($a, $b) { return [$a['status'] !== 'open', $b['opened']] <=> [$b['status'] !== 'open', $a['opened']]; });
        json_out(200, array_map(function ($c) use ($u) { return case_out($c, $u); }, $l));
    }
    $u = pguard('clerk');
    $b = body_json();
    $r = find_referral((string)($b['referralId'] ?? ''));
    if (!$r || in_array($r['status'], ['draft', 'withdrawn'], true)) api_err(400, 'Choose a submitted referral');
    foreach (read_json('cases.json', []) as $c) if ($c['referralId'] === $r['id'] && $c['status'] === 'open') api_err(409, 'This referral is already with trustees');
    $c = ['id' => new_id(), 'referralId' => $r['id'], 'ref' => $r['ref'], 'title' => clip($b['title'] ?? '', 200) ?: ('Request ' . $r['ref']),
        'question' => clip($b['question'] ?? '', 2000), 'amount' => clip((string)($b['amount'] ?? $r['amount']), 20), 'opened' => now_iso(), 'by' => $u['id'],
        'status' => 'open', 'votes' => [], 'conflicts' => [], 'closeBy' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($b['closeBy'] ?? '')) ? $b['closeBy'] : ''];
    with_lock('cases.json', [], function (&$l) use ($c) { $l[] = $c; });
    set_referral_status($r['id'], 'with_trustees', $u, 'Put to trustees for a decision');
    foreach (users_with_roles(['trustee', 'chairman']) as $p) if (user_email($p))
        send_email(user_email($p), 'A request is waiting for your decision (' . $r['ref'] . ')', '<p>Dear ' . h($p['name']) . ',</p><p>Request <b>' . h($r['ref']) . '</b> has been put to trustees for a decision' . ($c['closeBy'] ? ' by ' . h(uk_date($c['closeBy'])) : '') . '.</p><p>For privacy, the details are only in the portal.</p>' . email_button(site_url() . '/portal#decisions', 'Read and vote'), [], 'vote request');
    audit($u['username'], 'case.opened', $c['title']);
    json_out(200, case_out($c, $u));
}
function api_p_case_vote(string $id): void {
    $u = pguard('board');
    if (!has_role($u, ['trustee', 'chairman'])) api_err(403, 'Only trustees can vote');
    $b = body_json();
    $res = with_lock('cases.json', [], function (&$l) use ($id, $u, $b) {
        foreach ($l as &$c) if ($c['id'] === $id) {
            if ($c['status'] !== 'open') return [400, 'Voting has closed'];
            if (!empty($b['conflict'])) {
                $reason = clip($b['reason'] ?? '', 500);
                if ($reason === '') return [400, 'Say briefly what the conflict is'];
                unset($c['votes'][$u['id']]);
                $c['conflicts'][$u['id']] = ['reason' => $reason, 'at' => now_iso()];
                return [200, 'conflict', $c['title']];
            }
            if (empty($b['noConflict'])) return [400, 'Please confirm you have no conflict of interest'];
            if (isset($c['conflicts'][$u['id']])) return [400, 'You declared a conflict, so you cannot vote on this'];
            $v = (string)($b['vote'] ?? '');
            if (!in_array($v, ['approve', 'decline', 'abstain', 'more_info'], true)) return [400, 'Choose how you vote'];
            $c['votes'][$u['id']] = ['vote' => $v, 'comment' => clip($b['comment'] ?? '', 1000), 'at' => now_iso()];
            return [200, $v, $c['title']];
        }
        return [404, 'Not found'];
    });
    if ($res[0] !== 200) api_err($res[0], $res[1]);
    audit($u['username'], $res[1] === 'conflict' ? 'case.conflict_declared' : 'case.voted', $res[2] . ($res[1] === 'conflict' ? '' : ': ' . $res[1]));
    json_out(200, case_out(find_case($id), $u));
}
function api_p_case_close(string $id): void {
    $u = pguard('clerk');
    $b = body_json();
    $outcome = (string)($b['outcome'] ?? '');
    if (!in_array($outcome, ['approved', 'declined', 'more_info'], true)) api_err(400, 'Choose the outcome');
    $c = find_case($id);
    if (!$c || $c['status'] !== 'open') api_err(400, 'This decision is not open');
    with_lock('cases.json', [], function (&$l) use ($id, $outcome, $u, $b) {
        foreach ($l as &$c) if ($c['id'] === $id) { $c['status'] = $outcome; $c['closedAt'] = now_iso(); $c['closedBy'] = $u['id']; $c['note'] = clip($b['note'] ?? '', 1000); }
    });
    $decision = ['outcome' => $outcome, 'amount' => clip((string)($b['amount'] ?? $c['amount']), 20), 'payment' => clip($b['payment'] ?? '', 200), 'purpose' => clip($b['purpose'] ?? '', 300),
        'reason' => clip($b['reason'] ?? '', 1000), 'info' => clip($b['info'] ?? '', 2000), 'at' => now_iso(), 'by' => $u['id'], 'caseId' => $id];
    with_lock('referrals.json', [], function (&$l) use ($c, $decision) { foreach ($l as &$r) if ($r['id'] === $c['referralId']) $r['decision'] = $decision; });
    set_referral_status($c['referralId'], $outcome, $u, 'Trustee decision: ' . $outcome);
    $tplKey = $outcome === 'approved' ? 'grant_award' : ($outcome === 'declined' ? 'grant_decline' : 'more_info');
    $letter = create_letter_for_referral($u, $tplKey, $c['referralId']);
    audit($u['username'], 'case.closed', $c['title'] . ': ' . $outcome . ' – draft ' . letter_templates()[$tplKey]['name'] . ' letter created');
    json_out(200, ['ok' => true, 'letterId' => $letter['id'] ?? null]);
}

// ---------- calendar ----------
function calendar_events(array $u): array {
    $ev = [];
    foreach (read_json('meetings.json', []) as $m) if ($m['status'] !== 'draft' || is_clerkish($u))
        $ev[] = ['uid' => 'm' . $m['id'], 'start' => $m['date'], 'mins' => $m['durationMins'] ?? 120, 'title' => $m['title'], 'where' => $m['venue'], 'kind' => 'meeting', 'id' => $m['id']];
    foreach (read_json('actions.json', []) as $a) if ($a['due'] && $a['status'] !== 'done' && ($a['owner'] === $u['id'] || is_clerkish($u)))
        $ev[] = ['uid' => 'a' . $a['id'], 'start' => $a['due'] . 'T09:00', 'allDay' => true, 'title' => 'Action due: ' . mb_substr($a['text'], 0, 80) . ($a['owner'] !== $u['id'] ? ' (' . user_name($a['owner']) . ')' : ''), 'kind' => 'action', 'id' => $a['id']];
    foreach (read_json('docs.json', []) as $d) if (!empty($d['review']))
        $ev[] = ['uid' => 'd' . $d['id'], 'start' => $d['review'] . 'T09:00', 'allDay' => true, 'title' => 'Policy review: ' . $d['title'], 'kind' => 'review', 'id' => $d['id']];
    foreach (read_json('cases.json', []) as $c) if ($c['status'] === 'open' && $c['closeBy'])
        $ev[] = ['uid' => 'c' . $c['id'], 'start' => $c['closeBy'] . 'T09:00', 'allDay' => true, 'title' => 'Vote closes: ' . $c['title'], 'kind' => 'vote', 'id' => $c['id']];
    usort($ev, function ($a, $b) { return strcmp($a['start'], $b['start']); });
    return $ev;
}
function api_p_calendar(): void {
    $u = pguard('board');
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $tok = bin2hex(random_bytes(20));
        with_lock('users.json', [], function (&$l) use ($u, $tok) { foreach ($l as &$x) if ($x['id'] === $u['id']) $x['calToken'] = $tok; });
        audit($u['username'], 'calendar.link_created', 'New private calendar link (old link stops working)');
        json_out(200, ['url' => site_url() . '/calendar/' . $tok . '.ics']);
    }
    $me = user_by_id($u['id']);
    json_out(200, ['events' => calendar_events($u), 'feed' => !empty($me['calToken']) ? site_url() . '/calendar/' . $me['calToken'] . '.ics' : '']);
}
function handle_calendar_feed(string $tok): void {
    $u = null;
    foreach (users() as $x) if (!empty($x['calToken']) && hash_equals($x['calToken'], $tok) && empty($x['disabled']) && is_board($x)) $u = $x;
    if (!$u) { http_response_code(404); exit('Not found'); }
    header('Content-Type: text/calendar; charset=utf-8');
    echo ics_feed(calendar_events($u)); exit;
}

// ---------- search & ask ----------
function api_p_search(): void {
    $u = pguard('board');
    $q = trim((string)($_GET['q'] ?? ''));
    if (mb_strlen($q) < 2) json_out(200, []);
    $hits = [];
    $ql = mb_strtolower($q);
    foreach (read_json('meetings.json', []) as $m) {
        if ($m['status'] === 'draft' && !is_clerkish($u)) continue;
        $approved = $m['status'] === 'minutes_approved';
        if ($approved || is_clerkish($u)) {
            $txt = $m['minutes']['text'] ?? ($m['drafts']['minutes'] ?? '');
            foreach (preg_split("/\n+/", $txt) as $line) if (mb_strpos(mb_strtolower($line), $ql) !== false)
                $hits[] = ['meetingId' => $m['id'], 'meeting' => $m['title'] . ' – ' . uk_date($m['date']), 'where' => $approved ? 'Minutes' : 'Draft minutes', 'text' => mb_substr($line, 0, 300)];
            $map = $m['transcript']['speakers'] ?? [];
            foreach ($m['transcript']['segments'] ?? [] as $s) if (mb_strpos(mb_strtolower($s['text']), $ql) !== false)
                $hits[] = ['meetingId' => $m['id'], 'meeting' => $m['title'] . ' – ' . uk_date($m['date']), 'where' => 'Transcript ' . hms($s['t']), 't' => $s['t'],
                    'text' => (isset($map[$s['speaker']]) ? user_name($map[$s['speaker']]) : $s['speaker']) . ': ' . mb_substr($s['text'], 0, 300)];
        }
    }
    json_out(200, array_slice($hits, 0, 100));
}
function api_p_ask(): void {
    $u = pguard('board');
    $q = clip(body_json()['q'] ?? '', 500);
    if ($q === '') api_err(400, 'Ask a question');
    $ctx = '';
    $ms = array_filter(read_json('meetings.json', []), function ($m) { return $m['status'] === 'minutes_approved'; });
    usort($ms, function ($a, $b) { return strcmp($b['date'], $a['date']); });
    foreach (array_slice($ms, 0, 40) as $m) $ctx .= "\n\n=== Minutes of {$m['title']}, " . uk_date($m['date']) . " ===\n" . $m['minutes']['text'];
    if ($ctx === '') api_err(400, 'There are no approved minutes to search yet');
    try {
        $a = gemini_text(portal_settings()['gemini']['fastModel'], "Using only the approved minutes below, answer this question from a trustee. Say which meeting each fact comes from. If the minutes don't say, say so.\n\nQuestion: $q$ctx", AI_SYSTEM);
    } catch (Throwable $e) { api_err(502, $e->getMessage()); }
    audit($u['username'], 'minutes.asked', mb_substr($q, 0, 120));
    json_out(200, ['answer' => $a]);
}

// ---------- settings ----------
function api_p_settings(): void {
    $u = pguard('admin');
    $s = portal_settings();
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $s['smtp']['pass'] = $s['smtp']['pass'] !== '' ? '••••••••' : '';
        $s['gemini']['key'] = $s['gemini']['key'] !== '' ? '••••' . substr($s['gemini']['key'], -4) : '';
        $s['agreement'] = agreement();
        $s['cronCommand'] = '/usr/local/bin/php ' . dirname(__DIR__) . '/index.php cron';
        $s['cronUrl'] = site_url() . '/cron?key=' . $s['cronKey'];
        $s['status'] = setup_status();
        $s['outbox'] = array_map(function ($x) { return ['to' => $x['to'], 'subject' => $x['subject'], 'tries' => $x['tries'], 'error' => $x['error'], 'created' => $x['created']]; }, read_json('outbox.json', []));
        json_out(200, $s);
    }
    $b = body_json();
    $ch = [];
    if (isset($b['siteUrl'])) { $v = rtrim(clip($b['siteUrl'], 200), '/'); if ($v !== '' && !preg_match('#^https?://[^/\s]+$#', $v)) api_err(400, 'Website address should look like https://droitwichunitedcharities.org.uk'); if ($v !== $s['siteUrl']) $ch[] = 'website address'; $s['siteUrl'] = $v; }
    if (isset($b['smtp']) && is_array($b['smtp'])) {
        $m = $b['smtp']; $o = $s['smtp'];
        $s['smtp'] = ['host' => clip($m['host'] ?? '', 200), 'port' => max(1, min(65535, (int)($m['port'] ?? 587))), 'secure' => in_array($m['secure'] ?? '', ['tls', 'ssl', 'none'], true) ? $m['secure'] : 'tls',
            'user' => clip($m['user'] ?? '', 200), 'pass' => (($m['pass'] ?? '') === '' || strpos((string)$m['pass'], '••') === 0) ? $o['pass'] : (string)$m['pass'],
            'from' => clip($m['from'] ?? '', 200), 'fromName' => clip($m['fromName'] ?? '', 120) ?: 'Droitwich United Charities', 'replyTo' => clip($m['replyTo'] ?? '', 200)];
        if ($s['smtp']['from'] !== '' && !filter_var($s['smtp']['from'], FILTER_VALIDATE_EMAIL)) api_err(400, 'The From address is not a valid email');
        if (json_encode($o) !== json_encode($s['smtp'])) $ch[] = 'email (SMTP) settings';
    }
    if (isset($b['gemini']) && is_array($b['gemini'])) {
        $g = $b['gemini']; $o = $s['gemini'];
        $key = (string)($g['key'] ?? '');
        $s['gemini'] = ['key' => ($key === '' || strpos($key, '••') === 0) ? $o['key'] : trim($key), 'fastModel' => clip($g['fastModel'] ?? '', 80) ?: 'gemini-flash-latest', 'proModel' => clip($g['proModel'] ?? '', 80) ?: 'gemini-pro-latest'];
        if (!empty($g['clearKey'])) $s['gemini']['key'] = '';
        if (json_encode($o) !== json_encode($s['gemini'])) $ch[] = 'Gemini settings';
    }
    if (isset($b['votes'])) { $s['votes'] = ['quorum' => max(1, min(30, (int)($b['votes']['quorum'] ?? 4))), 'majority' => 'simple']; $ch[] = 'quorum ' . $s['votes']['quorum']; }
    if (isset($b['postcodes'])) { $s['postcodes'] = array_values(array_filter(array_map(function ($p) { return strtoupper(clip($p, 8)); }, (array)$b['postcodes']))); $ch[] = 'postcode areas'; }
    if (isset($b['signatories']) && is_array($b['signatories'])) foreach (['chairman', 'clerk'] as $k) if (isset($b['signatories'][$k])) {
        $s['signatories'][$k] = ['name' => clip($b['signatories'][$k]['name'] ?? '', 120), 'role' => clip($b['signatories'][$k]['role'] ?? '', 160), 'contact' => clip($b['signatories'][$k]['contact'] ?? '', 200)]; $ch[] = "$k signature";
    }
    if (isset($b['agreementText'])) {
        $a = agreement(); $t = clip($b['agreementText'], 30000);
        if ($t !== $a['text']) { write_json('agreement.json', ['version' => $a['version'] + 1, 'text' => $t, 'at' => now_iso(), 'by' => $u['id']]); $ch[] = 'data sharing agreement (new version ' . ($a['version'] + 1) . ' – partners must accept again)'; }
    }
    write_json('portal-settings.json', $s);
    if ($ch) audit($u['username'], 'portal.settings', implode('; ', array_unique($ch)));
    json_out(200, ['ok' => true]);
}
function api_p_test_email(): void {
    $u = pguard('admin');
    $to = clip(body_json()['to'] ?? '', 200);
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) api_err(400, 'Enter an email address');
    require_once __DIR__ . '/trustee_duc_smtp.php';
    $err = smtp_send($to, 'Test email from the D.U.C. portal', email_wrap('<p>This is a test email from the Droitwich United Charities portal. If you can read this, email is working.</p>'));
    audit($u['username'], 'email.test', $to . ': ' . ($err ?? 'sent'));
    if ($err) api_err(502, $err);
    json_out(200, ['ok' => true]);
}
function api_p_test_gemini(): void {
    $u = pguard('admin');
    try { $t = gemini_text(portal_settings()['gemini']['fastModel'], 'Reply with exactly: Gemini is working for D.U.C.'); }
    catch (Throwable $e) { audit($u['username'], 'gemini.test', 'failed: ' . $e->getMessage()); api_err(502, $e->getMessage()); }
    audit($u['username'], 'gemini.test', 'ok');
    json_out(200, ['ok' => true, 'reply' => $t]);
}
function api_p_board_users(): void {
    $u = pguard('board');
    if ($_SERVER['REQUEST_METHOD'] === 'GET') json_out(200, array_map(function ($x) { return ['id' => $x['id'], 'name' => $x['name'], 'role' => $x['role'], 'roleLabel' => ROLE_LABELS[$x['role']], 'email' => user_email($x)]; }, users_with_roles(BOARD_ROLES)));
    if (!has_role($u, ['admin', 'clerk'])) api_err(403, 'Only the Clerk or Global Admin can invite people');
    $b = body_json();
    $role = (string)($b['role'] ?? 'trustee');
    if (!in_array($role, ['trustee', 'chairman', 'clerk', 'dplead'], true) || ($role !== 'trustee' && !has_role($u, ['admin']))) api_err(403, 'The Clerk can invite trustees; only the Global Admin can give other roles');
    [$nu, $link] = create_invited_user($u, (string)($b['name'] ?? ''), (string)($b['email'] ?? ''), $role);
    $tpl = letter_templates()['trustee_invite'];
    $html = '<p>Dear ' . h($nu['name']) . ',</p>' . str_replace(h('{portal_link}'), email_button($link, 'Set my password'), text_to_html($tpl['body'])) . signoff_html($tpl['sign']);
    $sent = send_email($nu['email'], $tpl['subject'], $html, [], 'trustee invite');
    json_out(200, ['ok' => true, 'emailed' => $sent, 'link' => $sent ? '' : $link]);
}

// ---------- pages ----------
function handle_portal_page(): void {
    $u = current_user();
    if (!$u) redirect('/login?next=' . rawurlencode('/portal'));
    if (!empty($u['mustChange'])) redirect('/change-password');
    if (!is_board($u) && !is_partner($u)) render_page(403, 'No access', '<h1>No portal access</h1><p>Your account can see the website but not the Trustee or Partner portal. Ask the Clerk if you need access.</p><p><a href="/">Back to the website</a></p>');
    if (settings()['maintenance'] && !has_role($u, ['admin', 'editor', 'clerk'])) maintenance_page($u);
    header('Content-Type: text/html; charset=utf-8'); header('Cache-Control: no-store');
    readfile(APP_DIR . '/trustee_duc_portal.html');
    exit;
}

// ---------- router ----------
function portal_route(string $path, string $method): bool {
    $m = [];
    $r = function ($re) use ($path, &$m) { return (bool)preg_match('#^' . $re . '$#', $path, $m); };
    if (strpos($path, '/api/p/') !== 0) {
        if ($path === '/portal') { handle_portal_page(); return true; }
        if ($path === '/partners/apply') { handle_partner_apply(); return true; }
        if ($path === '/privacy-notice') { handle_privacy_page(); return true; }
        if ($path === '/set-password') { handle_set_password(); return true; }
        if ($path === '/cron') { handle_cron_web(); return true; }
        if ($r('/calendar/([a-f0-9]{40})\.ics')) { handle_calendar_feed($m[1]); return true; }
        return false;
    }
    $id = '([a-f0-9]{16})';
    $routes = [
        ['GET', '/api/p/me', 'api_p_me'], ['GET', '/api/p/dashboard', 'api_p_dashboard'], ['*', '/api/p/announcements', 'api_p_announcements'],
        ['POST', '/api/p/upload', function () { api_upload_chunk(pguard('any')); }],
        ['*', '/api/p/meetings', 'api_p_meetings'], ['*', "/api/p/meetings/$id", 'api_p_meeting'],
        ['POST', "/api/p/meetings/$id/publish", 'api_p_meeting_publish'], ['POST', "/api/p/meetings/$id/rsvp", 'api_p_meeting_rsvp'], ['GET', "/api/p/meetings/$id/invite.ics", 'api_p_meeting_ics'],
        ['POST', "/api/p/meetings/$id/rec/start", 'api_p_rec_start'], ['POST', "/api/p/meetings/$id/rec/chunk", 'api_p_rec_chunk'], ['POST', "/api/p/meetings/$id/rec/marker", 'api_p_rec_marker'],
        ['POST', "/api/p/meetings/$id/rec/finish", 'api_p_rec_finish'], ['POST', "/api/p/meetings/$id/process", 'api_p_rec_process'], ['GET', "/api/p/meetings/$id/audio", 'api_p_rec_audio'],
        ['PUT', "/api/p/meetings/$id/review", 'api_p_review'], ['POST', "/api/p/meetings/$id/publish-actions", 'api_p_publish_actions'], ['POST', "/api/p/meetings/$id/approve-minutes", 'api_p_approve_minutes'],
        ['GET', "/api/p/meetings/$id/minutes.docx", 'api_p_minutes_docx'],
        ['*', '/api/p/actions', 'api_p_actions'], ['*', "/api/p/actions/$id", 'api_p_action'],
        ['*', '/api/p/docs', 'api_p_docs'], ['*', "/api/p/docs/$id", 'api_p_doc'], ['GET', "/api/p/docs/$id/file", 'api_p_doc_file'], ['POST', "/api/p/docs/$id/confirm", 'api_p_doc_confirm'],
        ['*', '/api/p/cases', 'api_p_cases'], ['POST', "/api/p/cases/$id/vote", 'api_p_case_vote'], ['POST', "/api/p/cases/$id/close", 'api_p_case_close'],
        ['*', '/api/p/calendar', 'api_p_calendar'], ['GET', '/api/p/search', 'api_p_search'], ['POST', '/api/p/ask', 'api_p_ask'],
        ['*', '/api/p/settings', 'api_p_settings'], ['POST', '/api/p/settings/test-email', 'api_p_test_email'], ['POST', '/api/p/settings/test-gemini', 'api_p_test_gemini'],
        ['*', '/api/p/board-users', 'api_p_board_users'],
    ];
    $routes = array_merge($routes, partner_routes($id), letter_routes($id), gdpr_routes($id));
    foreach ($routes as [$verb, $re, $fn]) {
        if (($verb === '*' || $verb === $method) && $r($re)) {
            $args = array_slice($m, 1);
            $fn(...$args);
            return true;
        }
    }
    api_err(404, 'Unknown portal request');
    return true;
}
