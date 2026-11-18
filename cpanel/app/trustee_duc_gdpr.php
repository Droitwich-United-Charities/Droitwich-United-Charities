<?php
/**
 * GDPR tools: retention schedule + legal holds, subject access requests, erasure,
 * consent register, privacy notice versions, breach log, ROPA and the yearly report.
 */
declare(strict_types=1);

// ---------- privacy notice versions ----------
function current_notice(): array {
    $l = read_json('notices.json', []);
    return $l ? end($l) : ['version' => 1, 'text' => default_privacy_notice(), 'at' => '2026-11-18T00:00:00Z', 'by' => ''];
}
function api_p_notices(): void {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') { pguard('gdpr'); $l = read_json('notices.json', []); json_out(200, $l ?: [current_notice()]); }
    $u = pguard('gdpr');
    $t = clip(body_json()['text'] ?? '', 30000);
    if (trim($t) === '') api_err(400, 'The notice is empty');
    $cur = current_notice();
    if ($t === $cur['text']) api_err(400, 'Nothing has changed');
    $n = ['version' => $cur['version'] + 1, 'text' => $t, 'at' => now_iso(), 'by' => $u['id']];
    with_lock('notices.json', [], function (&$l) use ($n, $cur) { if (!$l) $l[] = $cur; $l[] = $n; });
    audit($u['username'], 'privacy.notice_updated', 'Privacy notice version ' . $n['version'] . ' published – partners asked to accept at next sign-in');
    $tpl = letter_templates()['privacy_update'];
    foreach (array_filter(users(), function ($x) { return is_partner($x) && empty($x['disabled']); }) as $p) if (user_email($p))
        send_email(user_email($p), $tpl['subject'], '<p>Dear ' . h($p['name']) . ',</p>' . text_to_html(merge_fields($tpl['body'], standard_fields())) . signoff_html($tpl['sign']), [], 'privacy update');
    json_out(200, $n);
}

// ---------- consent register ----------
function record_consent(array $c): void {
    $c = array_merge(['id' => new_id(), 'at' => now_iso(), 'date' => date('Y-m-d'), 'withdrawnAt' => '', 'withdrawnBy' => ''], $c);
    with_lock('consents.json', [], function (&$l) use ($c) { $l[] = $c; });
}
function api_p_consents(string $id = ''): void {
    $u = pguard('gdpr');
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $l = read_json('consents.json', []);
        usort($l, function ($a, $b) { return strcmp($b['at'], $a['at']); });
        json_out(200, array_map(function ($c) { $c['byName'] = user_name($c['by']); return $c; }, $l));
    }
    $r = with_lock('consents.json', [], function (&$l) use ($id, $u) { foreach ($l as &$c) if ($c['id'] === $id && !$c['withdrawnAt']) { $c['withdrawnAt'] = now_iso(); $c['withdrawnBy'] = $u['id']; return $c; } return null; });
    if (!$r) api_err(404, 'Consent not found or already withdrawn');
    audit($u['username'], 'consent.withdrawn', $r['subject'] . ': ' . $r['purpose']);
    if ($r['subjectType'] === 'referral') {
        with_lock('referrals.json', [], function (&$l) use ($r) { foreach ($l as &$x) if ($x['id'] === $r['subjectId']) { $x['restricted'] = true; $x['history'][] = ['at' => now_iso(), 'by' => 'D.U.C.', 'what' => 'Consent withdrawn – record restricted']; } });
    }
    json_out(200, ['ok' => true]);
}

// ---------- legal holds ----------
function holds(): array { return read_json('holds.json', []); }
function on_hold(string $type, string $id): bool { return isset(holds()["$type:$id"]); }
function api_p_holds(): void {
    $u = pguard('gdpr');
    if ($_SERVER['REQUEST_METHOD'] === 'GET') json_out(200, array_values(array_map(function ($k, $h) { [$t, $i] = explode(':', $k); return $h + ['type' => $t, 'id' => $i, 'label' => hold_label($t, $i), 'byName' => user_name($h['by'])]; }, array_keys(holds()), holds())));
    $b = body_json();
    $type = (string)($b['type'] ?? ''); $id = clip($b['id'] ?? '', 20);
    if (!in_array($type, ['referral', 'meeting', 'user', 'enquiry'], true)) api_err(400, 'Invalid record type');
    $key = "$type:$id";
    if (!empty($b['remove'])) {
        with_lock('holds.json', [], function (&$h) use ($key) { unset($h[$key]); });
        audit($u['username'], 'gdpr.hold_removed', hold_label($type, $id));
        json_out(200, ['ok' => true]);
    }
    $reason = clip($b['reason'] ?? '', 500);
    if ($reason === '') api_err(400, 'Give a reason for the legal hold');
    with_lock('holds.json', [], function (&$h) use ($key, $reason, $u) { $h[$key] = ['reason' => $reason, 'by' => $u['id'], 'at' => now_iso()]; });
    audit($u['username'], 'gdpr.hold_added', hold_label($type, $id) . ': ' . $reason);
    json_out(200, ['ok' => true]);
}
function hold_label(string $t, string $id): string {
    if ($t === 'referral') { $r = find_referral($id); return 'Referral ' . ($r['ref'] ?? $id); }
    if ($t === 'meeting') { $m = find_meeting($id); return 'Meeting ' . ($m ? $m['title'] . ', ' . uk_date($m['date']) : $id); }
    if ($t === 'user') return 'Account ' . (user_by_id($id)['username'] ?? $id);
    return ucfirst($t) . ' ' . $id;
}

// ---------- retention ----------
function retention_rules(): array {
    $r = portal_settings()['retention'];
    return [
        ['key' => 'referral_closed_days', 'record' => 'Paid / closed grant and referral files', 'days' => $r['referral_closed_days'], 'end' => 'Personal details deleted; reference, category and amount kept for reports'],
        ['key' => 'referral_declined_days', 'record' => 'Declined or withdrawn referrals, and old website enquiries', 'days' => $r['referral_declined_days'], 'end' => 'Deleted'],
        ['key' => 'referral_draft_days', 'record' => 'Draft referrals never submitted', 'days' => $r['referral_draft_days'], 'end' => 'Deleted'],
        ['key' => 'recording_days', 'record' => 'Meeting audio and raw transcripts (after minutes approved)', 'days' => $r['recording_days'], 'end' => 'Deleted; approved minutes kept'],
        ['key' => 'partner_inactive_days', 'record' => 'Partner logins not used', 'days' => $r['partner_inactive_days'], 'end' => 'Disabled, then deleted 30 days later'],
        ['key' => 'audit_days', 'record' => 'Audit log entries', 'days' => $r['audit_days'], 'end' => 'Deleted'],
    ];
}
/** Work out everything due for deletion by $by (unix time). */
function retention_due(int $by): array {
    $r = portal_settings()['retention'];
    $due = [];
    $add = function ($type, $id, $label, $rule, $when, $action) use (&$due) {
        $due[] = ['type' => $type, 'id' => $id, 'label' => $label, 'rule' => $rule, 'due' => gmdate('Y-m-d', $when), 'action' => $action, 'hold' => on_hold($type, $id)];
    };
    foreach (read_json('referrals.json', []) as $x) {
        if (!empty($x['erased'])) continue;
        if ($x['status'] === 'draft') { $t = strtotime($x['updated']) + $r['referral_draft_days'] * 86400; if ($t <= $by) $add('referral', $x['id'], 'Draft referral from ' . (find_org($x['orgId'])['name'] ?? '?'), 'referral_draft_days', $t, 'delete'); }
        elseif (in_array($x['status'], ['declined', 'withdrawn'], true) && !empty($x['closedAt'])) { $t = strtotime($x['closedAt']) + $r['referral_declined_days'] * 86400; if ($t <= $by) $add('referral', $x['id'], 'Referral ' . $x['ref'] . ' (' . $x['status'] . ')', 'referral_declined_days', $t, 'delete'); }
        elseif ($x['status'] === 'paid' && !empty($x['closedAt']) && empty($x['anonymised'])) { $t = strtotime($x['closedAt']) + $r['referral_closed_days'] * 86400; if ($t <= $by) $add('referral', $x['id'], 'Referral ' . $x['ref'] . ' (paid)', 'referral_closed_days', $t, 'anonymise'); }
    }
    foreach (read_json('enquiries.json', []) as $e) { $t = strtotime($e['date']) + $r['referral_declined_days'] * 86400; if ($t <= $by) $add('enquiry', $e['id'], 'Website enquiry from ' . initials($e['name']), 'referral_declined_days', $t, 'delete'); }
    foreach (read_json('meetings.json', []) as $m) {
        if (($m['status'] ?? '') !== 'minutes_approved' || empty($m['minutes']['approvedAt'])) continue;
        if (empty($m['recording']['file']) && empty($m['transcript'])) continue;
        $t = strtotime($m['minutes']['approvedAt']) + $r['recording_days'] * 86400;
        if ($t <= $by) $add('meeting', $m['id'], 'Recording and transcript: ' . $m['title'] . ', ' . uk_date($m['date']), 'recording_days', $t, 'delete_audio');
    }
    foreach (users() as $u) {
        if (!is_partner($u)) continue;
        $last = strtotime($u['lastLogin'] ?: $u['created']);
        $t = $last + $r['partner_inactive_days'] * 86400;
        if (empty($u['disabled']) && $t <= $by) $add('user', $u['id'], 'Partner login ' . $u['username'], 'partner_inactive_days', $t, 'disable');
        if (!empty($u['disabled']) && !empty($u['retentionDisabledAt'])) { $t2 = strtotime($u['retentionDisabledAt']) + 30 * 86400; if ($t2 <= $by) $add('user', $u['id'], 'Partner login ' . $u['username'] . ' (disabled)', 'partner_inactive_days', $t2, 'delete'); }
    }
    usort($due, function ($a, $b) { return strcmp($a['due'], $b['due']); });
    return $due;
}
function erase_referral(string $id, bool $keepTotals, string $by): void {
    $r = find_referral($id);
    if (!$r) return;
    foreach ($r['files'] as $f) @unlink(files_dir('evidence') . '/' . $f['path']);
    foreach (read_json('letters.json', []) as $l) if ($l['refType'] === 'referral' && $l['refId'] === $id) @unlink(files_dir('letters') . '/' . $l['file']);
    with_lock('letters.json', [], function (&$l) use ($id) { $l = array_values(array_filter($l, function ($x) use ($id) { return !($x['refType'] === 'referral' && $x['refId'] === $id); })); });
    with_lock('referrals.json', [], function (&$l) use ($id, $keepTotals, $by) {
        foreach ($l as $i => &$x) if ($x['id'] === $id) {
            if (!$keepTotals) { array_splice($l, $i, 1); return; }
            $x['enc'] = enc(['name' => '', 'postcode' => '']); $x['files'] = []; $x['messages'] = []; $x['dupKey'] = ''; $x['consent'] = null; $x['anonymised'] = now_iso();
            $x['history'] = [['at' => now_iso(), 'by' => $by, 'what' => 'Personal details deleted (retention / erasure)']];
            if (isset($x['decision'])) $x['decision'] = array_intersect_key($x['decision'], array_flip(['outcome', 'amount', 'at', 'paidAt']));
        }
    });
    with_lock('cases.json', [], function (&$l) use ($id) { foreach ($l as &$c) if ($c['referralId'] === $id) { foreach ($c['votes'] as &$v) $v['comment'] = ''; $c['question'] = ''; } });
    with_lock('consents.json', [], function (&$l) use ($id) { foreach ($l as &$c) if ($c['subjectType'] === 'referral' && $c['subjectId'] === $id) $c['subject'] = '[erased] ' . $c['subject']; });
}
function run_retention(): array {
    $done = [];
    foreach (retention_due(time()) as $d) {
        if ($d['hold']) continue;
        switch ($d['type'] . ':' . $d['action']) {
            case 'referral:delete': erase_referral($d['id'], false, '(retention)'); break;
            case 'referral:anonymise': erase_referral($d['id'], true, '(retention)'); break;
            case 'enquiry:delete': with_lock('enquiries.json', [], function (&$l) use ($d) { $l = array_values(array_filter($l, function ($e) use ($d) { return $e['id'] !== $d['id']; })); }); break;
            case 'meeting:delete_audio':
                foreach (glob(files_dir('recordings') . '/' . $d['id'] . '.*') ?: [] as $f) @unlink($f);
                update_meeting($d['id'], function (&$m) { $m['transcript'] = null; if ($m['drafts']) { unset($m['drafts']['items'], $m['drafts']['questions']); } $m['recording'] = ['status' => 'deleted', 'deletedAt' => now_iso(), 'consentAt' => $m['recording']['consentAt'] ?? '', 'duration' => $m['recording']['duration'] ?? 0]; });
                break;
            case 'user:disable': with_lock('users.json', [], function (&$l) use ($d) { foreach ($l as &$u) if ($u['id'] === $d['id']) { $u['disabled'] = true; $u['retentionDisabledAt'] = now_iso(); } }); revoke_sessions($d['id']); break;
            case 'user:delete': with_lock('users.json', [], function (&$l) use ($d) { $l = array_values(array_filter($l, function ($u) use ($d) { return $u['id'] !== $d['id']; })); }); break;
        }
        audit('(retention)', 'gdpr.deleted', $d['label'] . ' – ' . $d['action'] . ' (rule: ' . $d['rule'] . ')');
        $done[] = $d;
    }
    // audit log trim
    $days = (int)portal_settings()['retention']['audit_days'];
    $cut = gmdate('Y-m-d\TH:i:s\Z', time() - $days * 86400);
    $p = dpath('audit.log');
    if (is_file($p)) {
        $fh = fopen($p, 'c+'); flock($fh, LOCK_EX);
        $keep = []; $dropped = 0;
        while (($line = fgets($fh)) !== false) { $e = json_decode($line, true); if (is_array($e) && $e['time'] < $cut) $dropped++; else $keep[] = $line; }
        if ($dropped) { ftruncate($fh, 0); rewind($fh); fwrite($fh, implode('', $keep)); }
        flock($fh, LOCK_UN); fclose($fh);
        if ($dropped) audit('(retention)', 'gdpr.deleted', "$dropped audit log entries older than $days days");
    }
    return $done;
}
function api_p_retention(): void {
    $u = pguard('gdpr');
    if ($_SERVER['REQUEST_METHOD'] === 'GET') json_out(200, ['rules' => retention_rules(), 'due' => retention_due(time() + 14 * 86400), 'lastRun' => read_json('cron.json', [])['retention'] ?? '']);
    if (!has_role($u, ['admin', 'dplead'])) api_err(403, 'Only the Data Protection Lead or Global Admin can change retention periods');
    $b = body_json();
    $s = portal_settings();
    $ch = [];
    foreach (retention_rules() as $rule) if (isset($b[$rule['key']])) {
        $v = max(1, min(36500, (int)$b[$rule['key']]));
        if ($v !== (int)$s['retention'][$rule['key']]) { $ch[] = $rule['record'] . ': ' . $s['retention'][$rule['key']] . ' → ' . $v . ' days'; $s['retention'][$rule['key']] = $v; }
    }
    write_json('portal-settings.json', $s);
    if ($ch) audit($u['username'], 'gdpr.retention_changed', implode('; ', $ch));
    json_out(200, ['ok' => true]);
}

// ---------- subject access requests ----------
function find_sar(string $id): ?array { foreach (read_json('sars.json', []) as $s) if ($s['id'] === $id) return $s; return null; }
function add_months(string $date, int $n): string { $d = new DateTime($date); $day = (int)$d->format('j'); $d->modify('first day of +' . $n . ' month'); $d->setDate((int)$d->format('Y'), (int)$d->format('n'), min($day, (int)$d->format('t'))); return $d->format('Y-m-d'); }
function sar_due(array $s): string {
    $start = $s['idConfirmed'] ?: $s['received'];
    return add_months($start, 1 + (!empty($s['extension']) ? 2 : 0));
}
function sar_out(array $s): array {
    $p = dec($s['enc'], []);
    $due = sar_due($s);
    $left = (int)floor((strtotime($due) - strtotime(date('Y-m-d'))) / 86400);
    return ['id' => $s['id'], 'ref' => $s['ref'], 'person' => $p, 'received' => $s['received'], 'channel' => $s['channel'], 'status' => $s['status'], 'idConfirmed' => $s['idConfirmed'],
        'paused' => !$s['idConfirmed'], 'due' => $due, 'daysLeft' => $left, 'extension' => $s['extension'] ?? null, 'hits' => $s['hits'] ?? [], 'exportedAt' => $s['exportedAt'] ?? '',
        'closedAt' => $s['closedAt'] ?? '', 'notes' => $s['notes'] ?? '', 'type' => $s['type'] ?? 'access', 'by' => user_name($s['by']), 'erasure' => $s['erasure'] ?? null];
}
function sar_fields(array $s): array {
    $p = dec($s['enc'], []);
    $lines = array_values(array_filter(array_map('trim', explode("\n", (string)($p['address'] ?? '')))));
    return ['recipient_name' => $p['name'] ?? '', 'address_1' => implode("\n", array_slice($lines, 0, max(0, count($lines) - 1))), 'town_postcode' => end($lines) ?: '',
        'salutation' => $p['name'] ?? '', 'sar_received' => uk_date($s['received']), 'sar_due' => uk_date(sar_due($s)), 'extension_reason' => $s['extension']['reason'] ?? '', 'reference' => $s['ref'],
        'kept_reason' => $s['erasure']['keptText'] ?? 'We have not needed to keep anything.'];
}
function api_p_sars(): void {
    $u = pguard('gdpr');
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $l = read_json('sars.json', []);
        usort($l, function ($a, $b) { return [$a['status'] === 'closed', $a['received']] <=> [$b['status'] === 'closed', $b['received']]; });
        json_out(200, array_map('sar_out', $l));
    }
    $b = body_json();
    $p = ['name' => clip($b['name'] ?? '', 120), 'email' => clip($b['email'] ?? '', 200), 'phone' => clip($b['phone'] ?? '', 40), 'address' => clip($b['address'] ?? '', 400), 'details' => clip($b['details'] ?? '', 2000)];
    if ($p['name'] === '') api_err(400, 'Enter the name of the person asking');
    $s = ['id' => new_id(), 'ref' => next_ref('SAR'), 'enc' => enc($p), 'type' => in_array($b['type'] ?? '', ['access', 'erasure', 'rectification', 'restriction'], true) ? $b['type'] : 'access',
        'received' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($b['received'] ?? '')) ? $b['received'] : date('Y-m-d'),
        'channel' => in_array($b['channel'] ?? '', ['letter', 'email', 'phone', 'website', 'in_person'], true) ? $b['channel'] : 'letter',
        'status' => 'id_check', 'idConfirmed' => '', 'hits' => [], 'by' => $u['id'], 'at' => now_iso(), 'notes' => ''];
    with_lock('sars.json', [], function (&$l) use ($s) { $l[] = $s; });
    audit($u['username'], 'sar.logged', $s['ref'] . ' (' . $s['type'] . ', received ' . $s['received'] . ')');
    $letter = save_letter($u, 'sar_ack', sar_fields($s), 'sar', $s['id']);
    json_out(200, sar_out($s) + ['letterId' => $letter['id']]);
}
function api_p_sar(string $id): void {
    $u = pguard('gdpr');
    $s = find_sar($id);
    if (!$s) api_err(404, 'Request not found');
    $b = body_json();
    $act = (string)($b['action'] ?? '');
    $note = '';
    switch ($act) {
        case 'confirm_id':
            with_lock('sars.json', [], function (&$l) use ($id, $b) { foreach ($l as &$x) if ($x['id'] === $id) { $x['idConfirmed'] = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($b['date'] ?? '')) ? $b['date'] : date('Y-m-d'); $x['status'] = 'in_progress'; $x['idHow'] = clip($b['how'] ?? '', 200); } });
            $note = 'identity confirmed'; break;
        case 'extend':
            $reason = clip($b['reason'] ?? '', 500);
            if ($reason === '') api_err(400, 'Give the reason for the extension');
            with_lock('sars.json', [], function (&$l) use ($id, $reason, $u) { foreach ($l as &$x) if ($x['id'] === $id) $x['extension'] = ['reason' => $reason, 'at' => now_iso(), 'by' => $u['id']]; });
            save_letter($u, 'sar_extension', sar_fields(find_sar($id)), 'sar', $id);
            $note = 'extended by 2 months: ' . $reason; break;
        case 'search':
            $hits = sar_search(dec($s['enc'], []), (array)($b['extra'] ?? []));
            with_lock('sars.json', [], function (&$l) use ($id, $hits) { foreach ($l as &$x) if ($x['id'] === $id) $x['hits'] = $hits; });
            audit($u['username'], 'sar.searched', $s['ref'] . ': ' . count($hits) . ' records found');
            json_out(200, sar_out(find_sar($id)));
        case 'select':
            $sel = array_flip(array_map('strval', (array)($b['keys'] ?? [])));
            with_lock('sars.json', [], function (&$l) use ($id, $sel) { foreach ($l as &$x) if ($x['id'] === $id) foreach ($x['hits'] as &$h) $h['include'] = isset($sel[$h['key']]); });
            json_out(200, ['ok' => true]);
        case 'notes':
            with_lock('sars.json', [], function (&$l) use ($id, $b) { foreach ($l as &$x) if ($x['id'] === $id) $x['notes'] = clip($b['notes'] ?? '', 4000); });
            json_out(200, ['ok' => true]);
        case 'close':
            with_lock('sars.json', [], function (&$l) use ($id) { foreach ($l as &$x) if ($x['id'] === $id) { $x['status'] = 'closed'; $x['closedAt'] = now_iso(); } });
            $note = 'closed' . (strtotime(date('Y-m-d')) > strtotime(sar_due($s)) ? ' – LATE' : ' on time'); break;
        default: api_err(400, 'Unknown action');
    }
    audit($u['username'], 'sar.' . $act, $s['ref'] . ': ' . $note);
    json_out(200, sar_out(find_sar($id)));
}
/** Look for a person across every store. Returns hits with a stable key. */
function sar_search(array $p, array $extra): array {
    $terms = array_values(array_filter(array_map(function ($t) { return mb_strtolower(trim((string)$t)); }, array_merge([$p['name'] ?? '', $p['email'] ?? '', $p['phone'] ?? ''], $extra)), function ($t) { return mb_strlen($t) >= 3; }));
    $pc = '';
    if (preg_match('/([A-Z]{1,2}\d[A-Z\d]? ?\d[A-Z]{2})/i', (string)($p['address'] ?? ''), $m)) $pc = mb_strtolower(preg_replace('/\s+/', '', $m[1]));
    $match = function (string $hay) use ($terms) { $h = mb_strtolower($hay); foreach ($terms as $t) if (mb_strpos($h, $t) !== false) return true; return false; };
    $hits = [];
    foreach (read_json('referrals.json', []) as $r) {
        $q = dec($r['enc'], []);
        $txt = implode(' ', array_map('strval', $q)) . ' ' . $r['ref'];
        foreach ($r['messages'] as $msg) $txt .= ' ' . dec($msg['enc'], '');
        $pcHit = $pc !== '' && mb_strtolower(preg_replace('/\s+/', '', (string)($q['postcode'] ?? ''))) === $pc && $match((string)($q['name'] ?? ''));
        if ($match($txt) || $pcHit) $hits[] = ['key' => 'referral:' . $r['id'], 'type' => 'referral', 'id' => $r['id'], 'label' => 'Referral ' . ($r['ref'] ?: '(draft)') . ' – ' . (REF_STATUSES[$r['status']] ?? ''), 'summary' => ($q['name'] ?? '') . ', ' . ($q['postcode'] ?? '') . ', ' . count($r['files']) . ' files', 'include' => true];
    }
    foreach (read_json('enquiries.json', []) as $e) if ($match($e['name'] . ' ' . $e['contact'] . ' ' . $e['purpose'])) $hits[] = ['key' => 'enquiry:' . $e['id'], 'type' => 'enquiry', 'id' => $e['id'], 'label' => 'Website grant enquiry ' . substr($e['date'], 0, 10), 'summary' => $e['name'] . ', ' . $e['contact'], 'include' => true];
    foreach (read_json('letters.json', []) as $l) { $e = dec($l['enc'] ?? '', []); if ($match(json_encode($e['fields'] ?? [], JSON_UNESCAPED_UNICODE))) $hits[] = ['key' => 'letter:' . $l['id'], 'type' => 'letter', 'id' => $l['id'], 'label' => 'Letter: ' . $l['templateName'], 'summary' => ($e['fields']['recipient_name'] ?? '') . ' – ' . substr($l['at'], 0, 10), 'include' => true]; }
    foreach (users() as $u) if ($match($u['name'] . ' ' . $u['username'] . ' ' . ($u['email'] ?? ''))) $hits[] = ['key' => 'user:' . $u['id'], 'type' => 'user', 'id' => $u['id'], 'label' => 'Website account ' . $u['username'], 'summary' => $u['name'] . ' – ' . ROLE_LABELS[$u['role']], 'include' => true];
    foreach (read_json('orgs.json', []) as $o) if ($match($o['contactName'] . ' ' . $o['contactEmail'] . ' ' . $o['dpContact'])) $hits[] = ['key' => 'org:' . $o['id'], 'type' => 'org', 'id' => $o['id'], 'label' => 'Partner organisation contact: ' . $o['name'], 'summary' => $o['contactName'], 'include' => true];
    foreach (read_json('meetings.json', []) as $m) {
        $n = 0;
        foreach ($m['transcript']['segments'] ?? [] as $s) if ($match($s['text'])) $n++;
        $inMin = $match($m['minutes']['text'] ?? '') || $match($m['drafts']['minutes'] ?? '');
        if ($n || $inMin) $hits[] = ['key' => 'meeting:' . $m['id'], 'type' => 'meeting', 'id' => $m['id'], 'label' => 'Meeting: ' . $m['title'] . ', ' . uk_date($m['date']), 'summary' => ($inMin ? 'Named in minutes. ' : '') . ($n ? "$n transcript lines" : ''), 'include' => true];
    }
    $n = 0;
    foreach (read_audit() as $e) if ($match($e['user'] . ' ' . $e['details'])) $n++;
    if ($n) $hits[] = ['key' => 'audit:all', 'type' => 'audit', 'id' => 'all', 'label' => 'Audit log entries', 'summary' => "$n entries", 'include' => true];
    $n = 0;
    foreach (read_json('consents.json', []) as $c) if ($match($c['subject'])) $n++;
    if ($n) $hits[] = ['key' => 'consent:all', 'type' => 'consent', 'id' => 'all', 'label' => 'Consent records', 'summary' => "$n records", 'include' => true];
    return $hits;
}
/** Collect the data for one hit (for export). Returns [title, data, files[path=>name]] */
function sar_collect(array $h, array $p): array {
    $terms = array_values(array_filter(array_map(function ($t) { return mb_strtolower(trim((string)$t)); }, [$p['name'] ?? '', $p['email'] ?? '', $p['phone'] ?? '']), function ($t) { return mb_strlen($t) >= 3; }));
    $match = function (string $hay) use ($terms) { $x = mb_strtolower($hay); foreach ($terms as $t) if (mb_strpos($x, $t) !== false) return true; return false; };
    switch ($h['type']) {
        case 'referral':
            $r = find_referral($h['id']); if (!$r) return [$h['label'], null, []];
            $files = []; foreach ($r['files'] as $f) $files[files_dir('evidence') . '/' . $f['path']] = ($r['ref'] ?: 'draft') . ' - ' . $f['name'];
            return [$h['label'], ['reference' => $r['ref'], 'status' => REF_STATUSES[$r['status']] ?? $r['status'], 'referred_by' => find_org($r['orgId'])['name'] ?? '', 'created' => $r['created'], 'submitted' => $r['submitted'] ?? '',
                'category' => REF_CATEGORIES[$r['category']] ?? '', 'amount_requested' => $r['amount'], 'your_details' => dec($r['enc'], []), 'consent' => $r['consent'], 'decision' => $r['decision'] ?? null,
                'messages' => array_map(function ($m) { return ['from' => $m['fromName'], 'at' => $m['at'], 'text' => dec($m['enc'], '')]; }, $r['messages']), 'history' => $r['history'],
                'documents' => array_column($r['files'], 'name')], $files];
        case 'enquiry': foreach (read_json('enquiries.json', []) as $e) if ($e['id'] === $h['id']) { unset($e['_ip']); return [$h['label'], $e, []]; } break;
        case 'letter':
            $l = find_letter($h['id']); if (!$l) break;
            return [$h['label'], ['letter' => $l['templateName'], 'date' => $l['at'], 'sent' => ($l['sentVia'] ?? '') . ' ' . ($l['sentAt'] ?? '')], [files_dir('letters') . '/' . $l['file'] => 'Letter - ' . safe_name($l['templateName']) . ' ' . substr($l['at'], 0, 10) . '.docx']];
        case 'user': $u = user_by_id($h['id']); if ($u) return [$h['label'], ['name' => $u['name'], 'username' => $u['username'], 'email' => user_email($u), 'role' => ROLE_LABELS[$u['role']], 'created' => $u['created'], 'last_sign_in' => $u['lastLogin']], []]; break;
        case 'org': $o = find_org($h['id']); if ($o) return [$h['label'], ['organisation' => $o['name'], 'contact_name' => $o['contactName'], 'contact_email' => $o['contactEmail'], 'phone' => $o['contactPhone'], 'data_protection_contact' => $o['dpContact']], []]; break;
        case 'meeting':
            $m = find_meeting($h['id']); if (!$m) break;
            $lines = [];
            foreach ($m['transcript']['segments'] ?? [] as $s) if ($match($s['text'])) $lines[] = hms($s['t']) . ' ' . $s['text'];
            $min = array_values(array_filter(preg_split("/\n+/", $m['minutes']['text'] ?? ''), $match));
            return [$h['label'], ['minutes_mentions' => $min, 'transcript_lines' => $lines], []];
        case 'audit': return [$h['label'], array_values(array_filter(read_audit(), function ($e) use ($match) { return $match($e['user'] . ' ' . $e['details']); })), []];
        case 'consent': return [$h['label'], array_values(array_filter(read_json('consents.json', []), function ($c) use ($match) { return $match($c['subject']); })), []];
    }
    return [$h['label'], null, []];
}
function api_p_sar_export(string $id): void {
    $u = pguard('gdpr');
    $s = find_sar($id);
    if (!$s) api_err(404, 'Request not found');
    if (!$s['idConfirmed']) api_err(400, "Confirm the person's identity first");
    $p = dec($s['enc'], []);
    $redact = array_values(array_filter(array_map('trim', explode("\n", (string)(body_json()['redact'] ?? '')))));
    $data = []; $files = [];
    foreach ($s['hits'] as $h) {
        if (empty($h['include'])) continue;
        [$title, $d, $f] = sar_collect($h, $p);
        if ($d === null) continue;
        $data[] = ['record' => $title, 'data' => $d];
        $files += $f;
    }
    $json = json_encode(['request' => $s['ref'], 'person' => $p['name'], 'prepared' => now_iso(), 'records' => $data], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    foreach ($redact as $r) if (mb_strlen($r) >= 2) $json = str_ireplace($r, '[redacted]', $json);
    $report = sar_report_html($s, $p, json_decode($json, true)['records']);
    $pw = strtoupper(substr(bin2hex(random_bytes(6)), 0, 4) . '-' . substr(bin2hex(random_bytes(6)), 0, 4) . '-' . substr(bin2hex(random_bytes(6)), 0, 4));
    $zipPath = files_dir('sar') . '/' . $id . '.zip';
    @unlink($zipPath);
    $z = new ZipArchive();
    if ($z->open($zipPath, ZipArchive::CREATE) !== true) api_err(500, "Couldn't create the export");
    $enc = method_exists($z, 'setEncryptionName');
    if ($enc) $z->setPassword($pw);
    $add = function (string $name, ?string $data, ?string $path = null) use ($z, $enc) {
        $path ? $z->addFile($path, $name) : $z->addFromString($name, (string)$data);
        if ($enc) $z->setEncryptionName($name, ZipArchive::EM_AES_256);
    };
    $add('Your information - report.html', $report);
    $add('Your information - data.json', $json);
    $cover = save_letter($u, 'sar_response', sar_fields($s), 'sar', $id);
    $add('Cover letter.docx', null, files_dir('letters') . '/' . $cover['file']);
    foreach ($files as $path => $name) if (is_file($path)) $add('Documents/' . safe_name($name), null, $path);
    $z->close();
    with_lock('sars.json', [], function (&$l) use ($id) { foreach ($l as &$x) if ($x['id'] === $id) { $x['exportedAt'] = now_iso(); $x['status'] = 'ready'; } });
    audit($u['username'], 'sar.exported', $s['ref'] . ': ' . count($data) . ' records, ' . count($files) . ' documents' . ($enc ? ', AES-256 protected' : ' – WARNING: this server cannot password-protect zips'));
    json_out(200, ['ok' => true, 'password' => $enc ? $pw : '', 'protected' => $enc, 'download' => '/api/p/sars/' . $id . '/export.zip']);
}
function api_p_sar_zip(string $id): void {
    $u = pguard('gdpr');
    $s = find_sar($id);
    if (!$s) api_err(404, 'Request not found');
    audit($u['username'], 'sar.downloaded', $s['ref']);
    send_file(files_dir('sar') . "/$id.zip", 'DUC ' . $s['ref'] . '.zip', 'application/zip');
}
function sar_report_html(array $s, array $p, array $records): string {
    $rows = '';
    $render = function ($v) use (&$render) {
        if (is_array($v)) {
            if (!$v) return '<i>none</i>';
            $assoc = array_keys($v) !== range(0, count($v) - 1);
            $o = $assoc ? '<table>' : '<ul>';
            foreach ($v as $k => $x) $o .= $assoc ? '<tr><th>' . h(ucfirst(str_replace('_', ' ', (string)$k))) . '</th><td>' . $render($x) . '</td></tr>' : '<li>' . $render($x) . '</li>';
            return $o . ($assoc ? '</table>' : '</ul>');
        }
        return $v === null || $v === '' ? '<i>—</i>' : nl2br(h((string)(is_bool($v) ? ($v ? 'Yes' : 'No') : $v)));
    };
    foreach ($records as $r) $rows .= '<h2>' . h($r['record']) . '</h2>' . $render($r['data']);
    $ropa = '';
    foreach (read_json('ropa.json', default_ropa()) as $x) $ropa .= '<tr><td>' . h($x['activity']) . '</td><td>' . h($x['purpose']) . '</td><td>' . h($x['basis']) . '</td><td>' . h($x['recipients']) . '</td><td>' . h($x['retention']) . '</td></tr>';
    return '<!DOCTYPE html><html lang="en-GB"><head><meta charset="utf-8"><title>Your information – Droitwich United Charities</title><style>body{font-family:Arial,sans-serif;color:#0B1C3D;max-width:820px;margin:30px auto;padding:0 20px;font-size:15px;line-height:1.5}h1,h2{font-family:Georgia,serif}h1{border-bottom:3px solid #2F5E37;padding-bottom:8px}h2{margin-top:32px;font-size:19px}table{border-collapse:collapse;width:100%;margin:8px 0}th,td{border:1px solid #d5dde6;padding:6px 8px;text-align:left;vertical-align:top}th{background:#EAF1F6;width:28%}.small{color:#5A6475;font-size:13px}</style></head><body>'
        . '<h1>Your information held by Droitwich United Charities</h1><p>Prepared for <b>' . h($p['name']) . '</b> in reply to your request received on ' . h(uk_date($s['received'])) . ' (our reference ' . h($s['ref']) . ').</p>'
        . '<p>This report shows the personal information Droitwich United Charities (D.U.C.), registered charity no. 205536, holds about you. The same information is in the file "data.json" for use on a computer. Some details about other people may have been removed to protect their privacy.</p>'
        . $rows . '<h2>Why we hold information, who sees it and how long we keep it</h2><table><tr><th>Activity</th><th>Why</th><th>Legal basis</th><th>Who sees it</th><th>How long</th></tr>' . $ropa . '</table>'
        . '<h2>Your rights</h2><p>You can ask us to correct anything that is wrong, to delete your information, or to stop using it. Contact our Clerk, Mrs Janet Clarke, 26 Blackfriars Avenue, Droitwich Spa WR9 8RQ, 01905 775226. You can complain to the Information Commissioner\'s Office at ico.org.uk or on 0303 123 1113.</p>'
        . '<p class="small">Prepared ' . h(uk_date()) . '. Droitwich United Charities • Helping local people in need.</p></body></html>';
}

// ---------- erasure / rectification / restriction ----------
function api_p_erase(): void {
    $u = pguard('gdpr');
    if (!has_role($u, ['admin', 'dplead', 'clerk'])) api_err(403, 'No access');
    $b = body_json();
    $s = find_sar((string)($b['sarId'] ?? ''));
    if (!$s) api_err(400, 'Erasure is done from a logged request, so there is a record of it');
    if (!$s['idConfirmed']) api_err(400, "Confirm the person's identity first");
    $keys = array_flip(array_map('strval', (array)($b['keys'] ?? [])));
    $done = []; $kept = [];
    foreach ($s['hits'] as $h) {
        if (!isset($keys[$h['key']])) continue;
        if (in_array($h['type'], ['referral', 'meeting', 'user', 'enquiry'], true) && on_hold($h['type'], $h['id'])) { $kept[] = $h['label'] . ': on legal hold'; continue; }
        switch ($h['type']) {
            case 'referral':
                $r = find_referral($h['id']); if (!$r) break;
                $money = in_array($r['status'], ['approved', 'paid'], true);
                erase_referral($h['id'], $money, $u['name']);
                $done[] = $h['label'];
                if ($money) $kept[] = $h['label'] . ': the reference, date and grant amount are kept for 6 years because charity accounting rules require it (your name and contact details have been deleted)';
                break;
            case 'enquiry': with_lock('enquiries.json', [], function (&$l) use ($h) { $l = array_values(array_filter($l, function ($e) use ($h) { return $e['id'] !== $h['id']; })); }); $done[] = $h['label']; break;
            case 'letter': $l = find_letter($h['id']); if ($l && $l['refType'] === 'sar') { $kept[] = $h['label'] . ': kept as the record of how we handled your request'; break; } if ($l) { @unlink(files_dir('letters') . '/' . $l['file']); with_lock('letters.json', [], function (&$x) use ($h) { $x = array_values(array_filter($x, function ($l) use ($h) { return $l['id'] !== $h['id']; })); }); $done[] = $h['label']; } break;
            case 'user':
                $t = user_by_id($h['id']);
                if ($t && $t['role'] === 'admin' && active_admins(users()) <= 1) { $kept[] = $h['label'] . ': it is the only Global Admin account'; break; }
                with_lock('users.json', [], function (&$l) use ($h) { $l = array_values(array_filter($l, function ($x) use ($h) { return $x['id'] !== $h['id']; })); });
                revoke_sessions($h['id']); $done[] = $h['label']; break;
            case 'org':
                with_lock('orgs.json', [], function (&$l) use ($h) { foreach ($l as &$o) if ($o['id'] === $h['id']) { $o['contactName'] = '[erased]'; $o['contactEmail'] = ''; $o['contactPhone'] = ''; } });
                $done[] = $h['label']; $kept[] = $h['label'] . ': the organisation record stays, without your name'; break;
            case 'meeting':
                $kept[] = $h['label'] . ': approved minutes are a permanent record of trustee decisions, so mentions in them are kept';
                update_meeting($h['id'], function (&$m) use ($s) { $p = dec($s['enc'], []); if ($m['transcript']) foreach ($m['transcript']['segments'] as &$seg) if (($p['name'] ?? '') !== '') $seg['text'] = str_ireplace($p['name'], '[erased]', $seg['text']); });
                break;
            case 'audit': $kept[] = 'Audit log: kept for security and accountability for 6 years'; break;
            case 'consent': $kept[] = 'Consent records: kept as proof of what was agreed'; break;
        }
    }
    $keptText = $kept ? "We have had to keep some information:\n\n" . implode("\n", array_map(function ($k) { return '- ' . $k; }, $kept)) : 'We have not needed to keep anything.';
    with_lock('sars.json', [], function (&$l) use ($s, $done, $kept, $keptText, $u) { foreach ($l as &$x) if ($x['id'] === $s['id']) { $x['erasure'] = ['done' => $done, 'kept' => $kept, 'keptText' => $keptText, 'at' => now_iso(), 'by' => $u['id']]; $x['status'] = 'ready'; } });
    $letter = save_letter($u, 'erasure_confirm', sar_fields(find_sar($s['id'])), 'sar', $s['id']);
    audit($u['username'], 'gdpr.erased', $s['ref'] . ': ' . count($done) . ' records erased, ' . count($kept) . ' kept with reasons');
    json_out(200, ['ok' => true, 'done' => $done, 'kept' => $kept, 'letterId' => $letter['id']]);
}
function api_p_rectify(): void {
    $u = pguard('gdpr');
    $b = body_json();
    $r = find_referral((string)($b['referralId'] ?? ''));
    if (!$r) api_err(404, 'Referral not found');
    $old = dec($r['enc'], []);
    referral_save($u, $r['id'], (array)($b['fields'] ?? []));
    $new = dec(find_referral($r['id'])['enc'], []);
    $changed = array_keys(array_filter(PERSON_FIELDS, function ($n, $k) use ($old, $new) { return ($old[$k] ?? '') !== ($new[$k] ?? ''); }, ARRAY_FILTER_USE_BOTH));
    with_lock('referrals.json', [], function (&$l) use ($r, $u, $changed) { foreach ($l as &$x) if ($x['id'] === $r['id']) { $x['history'][] = ['at' => now_iso(), 'by' => $u['name'], 'what' => 'Corrected (rectification): ' . implode(', ', $changed)]; } });
    if (isset($b['restrict'])) with_lock('referrals.json', [], function (&$l) use ($r, $b) { foreach ($l as &$x) if ($x['id'] === $r['id']) $x['restricted'] = (bool)$b['restrict']; });
    audit($u['username'], 'gdpr.rectified', $r['ref'] . ': ' . (implode(', ', $changed) ?: 'no changes') . (isset($b['restrict']) ? '; restricted ' . ($b['restrict'] ? 'ON' : 'OFF') : ''));
    json_out(200, ['ok' => true, 'changed' => $changed]);
}

// ---------- breach log ----------
function breach_out(array $x): array {
    $deadline = strtotime($x['aware']) + 72 * 3600;
    $x['deadline'] = gmdate('Y-m-d\TH:i:s\Z', $deadline);
    $x['hoursLeft'] = round(($deadline - time()) / 3600, 1);
    $x['byName'] = user_name($x['by']);
    return $x;
}
function api_p_breaches(string $id = ''): void {
    $u = pguard('gdpr');
    $method = $_SERVER['REQUEST_METHOD'];
    if ($method === 'GET') { $l = read_json('breaches.json', []); usort($l, function ($a, $b) { return strcmp($b['aware'], $a['aware']); }); json_out(200, array_map('breach_out', $l)); }
    $b = body_json();
    $f = function ($k, $n) use ($b) { return clip($b[$k] ?? '', $n); };
    if ($method === 'POST') {
        $aware = (string)($b['aware'] ?? '');
        $t = $aware !== '' ? strtotime($aware) : time();
        $x = ['id' => new_id(), 'ref' => next_ref('BR'), 'aware' => gmdate('Y-m-d\TH:i:s\Z', $t ?: time()), 'summary' => $f('summary', 4000), 'data' => $f('data', 2000), 'people' => (int)($b['people'] ?? 0),
            'risk' => in_array($b['risk'] ?? '', ['low', 'medium', 'high'], true) ? $b['risk'] : 'medium', 'containment' => '', 'assessment' => '', 'ico' => '', 'icoReason' => '', 'icoRef' => '', 'icoAt' => '',
            'told' => '', 'toldAt' => '', 'status' => 'open', 'by' => $u['id'], 'at' => now_iso(), 'checklist' => ['contained' => false, 'assessed' => false, 'decided' => false, 'told' => false]];
        if ($x['summary'] === '') api_err(400, 'Describe what happened');
        with_lock('breaches.json', [], function (&$l) use ($x) { $l[] = $x; });
        audit($u['username'], 'breach.logged', $x['ref'] . ' (' . $x['risk'] . ' risk, ' . $x['people'] . ' people)');
        foreach (array_merge(users_with_roles(['dplead', 'chairman']), users_with_roles(['admin'])) as $p) if (user_email($p) && $p['id'] !== $u['id'])
            send_email(user_email($p), 'Data breach logged: ' . $x['ref'], '<p>A possible data breach has been logged (' . h($x['risk']) . ' risk). If it must be reported, the ICO needs to know within 72 hours, by <b>' . h(date('l j F, g.ia', $t ?: time()) . ' + 72h') . '</b>.</p>' . email_button(site_url() . '/portal#gdpr/breaches', 'Open the breach log'), [], 'breach alert');
        json_out(200, breach_out($x));
    }
    $r = with_lock('breaches.json', [], function (&$l) use ($id, $b, $f) {
        foreach ($l as &$x) if ($x['id'] === $id) {
            foreach (['summary' => 4000, 'data' => 2000, 'containment' => 4000, 'assessment' => 4000, 'icoReason' => 2000, 'icoRef' => 60, 'told' => 2000] as $k => $n) if (isset($b[$k])) $x[$k] = $f($k, $n);
            if (isset($b['people'])) $x['people'] = (int)$b['people'];
            if (isset($b['risk']) && in_array($b['risk'], ['low', 'medium', 'high'], true)) $x['risk'] = $b['risk'];
            if (isset($b['ico']) && in_array($b['ico'], ['', 'report', 'no_report'], true)) $x['ico'] = $b['ico'];
            if (!empty($b['icoReported'])) $x['icoAt'] = now_iso();
            if (!empty($b['peopleTold'])) $x['toldAt'] = now_iso();
            if (isset($b['checklist']) && is_array($b['checklist'])) foreach ($x['checklist'] as $k => $v) if (isset($b['checklist'][$k])) $x['checklist'][$k] = (bool)$b['checklist'][$k];
            if (isset($b['status']) && in_array($b['status'], ['open', 'closed'], true)) $x['status'] = $b['status'];
            return $x;
        }
        return null;
    });
    if (!$r) api_err(404, 'Breach not found');
    if ($r['status'] === 'closed' && $r['ico'] === 'no_report' && $r['icoReason'] === '') api_err(400, "Record why it wasn't reported to the ICO before closing");
    audit($u['username'], 'breach.updated', $r['ref'] . ($r['ico'] ? ' – ICO: ' . $r['ico'] : '') . ($r['status'] === 'closed' ? ' – closed' : ''));
    json_out(200, breach_out($r));
}

// ---------- ROPA ----------
function api_p_ropa(): void {
    $u = pguard('gdpr');
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $rows = read_json('ropa.json', default_ropa());
        if (($_GET['format'] ?? '') === 'csv') {
            audit($u['username'], 'ropa.exported', 'CSV');
            header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="DUC record of processing ' . date('Y-m-d') . '.csv"');
            $o = fopen('php://output', 'w'); fwrite($o, "\xEF\xBB\xBF");
            fputcsv($o, ['Activity', 'Purpose', 'People', 'Personal data', 'Lawful basis', 'Who sees it', 'Retention', 'Security'], ',', '"', '\\');
            foreach ($rows as $r) fputcsv($o, [$r['activity'], $r['purpose'], $r['people'], $r['data'], $r['basis'], $r['recipients'], $r['retention'], $r['security']], ',', '"', '\\');
            exit;
        }
        json_out(200, $rows);
    }
    $rows = array_map(function ($r) { $o = []; foreach (['activity', 'purpose', 'people', 'data', 'basis', 'recipients', 'retention', 'security'] as $k) $o[$k] = clip($r[$k] ?? '', 1000); return $o; }, array_slice((array)body_json()['rows'], 0, 50));
    write_json('ropa.json', $rows);
    audit($u['username'], 'ropa.updated', count($rows) . ' activities');
    json_out(200, ['ok' => true]);
}

// ---------- yearly report ----------
function api_p_gdpr_report(): void {
    pguard('gdpr');
    $year = (int)($_GET['year'] ?? date('Y'));
    $in = function ($iso) use ($year) { return substr((string)$iso, 0, 4) === (string)$year; };
    $sars = array_filter(read_json('sars.json', []), function ($s) use ($in) { return $in($s['received']); });
    $closed = array_filter($sars, function ($s) { return !empty($s['closedAt']); });
    $onTime = array_filter($closed, function ($s) { return substr($s['closedAt'], 0, 10) <= sar_due($s); });
    $days = array_map(function ($s) { return (strtotime(substr($s['closedAt'], 0, 10)) - strtotime($s['received'])) / 86400; }, $closed);
    $del = 0; $erased = 0;
    foreach (read_audit() as $e) if ($in($e['time'])) { if ($e['action'] === 'gdpr.deleted') $del++; if ($e['action'] === 'gdpr.erased') $erased++; }
    $br = array_filter(read_json('breaches.json', []), function ($b) use ($in) { return $in($b['aware']); });
    $cons = array_filter(read_json('consents.json', []), function ($c) use ($in) { return $in($c['at']); });
    json_out(200, ['year' => $year, 'sars' => count($sars), 'sarsClosed' => count($closed), 'sarsOnTime' => count($onTime), 'sarAvgDays' => $days ? round(array_sum($days) / count($days), 1) : null,
        'retentionDeletions' => $del, 'erasureRequests' => $erased, 'breaches' => count($br), 'breachesReported' => count(array_filter($br, function ($b) { return $b['ico'] === 'report'; })),
        'consents' => count($cons), 'consentsWithdrawn' => count(array_filter($cons, function ($c) { return !empty($c['withdrawnAt']); })),
        'holds' => count(holds()), 'noticeVersion' => current_notice()['version']]);
}

function gdpr_routes(string $id): array {
    return [
        ['*', '/api/p/gdpr/notices', 'api_p_notices'], ['GET', '/api/p/gdpr/consents', 'api_p_consents'], ['POST', "/api/p/gdpr/consents/$id/withdraw", 'api_p_consents'],
        ['*', '/api/p/gdpr/holds', 'api_p_holds'], ['*', '/api/p/gdpr/retention', 'api_p_retention'],
        ['*', '/api/p/gdpr/sars', 'api_p_sars'], ['PUT', "/api/p/gdpr/sars/$id", 'api_p_sar'], ['POST', "/api/p/gdpr/sars/$id/export", 'api_p_sar_export'], ['GET', "/api/p/sars/$id/export.zip", 'api_p_sar_zip'],
        ['POST', '/api/p/gdpr/erase', 'api_p_erase'], ['POST', '/api/p/gdpr/rectify', 'api_p_rectify'],
        ['*', '/api/p/gdpr/breaches', 'api_p_breaches'], ['PUT', "/api/p/gdpr/breaches/$id", 'api_p_breaches'],
        ['*', '/api/p/gdpr/ropa', 'api_p_ropa'], ['GET', '/api/p/gdpr/report', 'api_p_gdpr_report'],
    ];
}
