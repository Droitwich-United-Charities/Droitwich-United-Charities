<?php
/**
 * Letters: generated on the D.U.C. letterhead from editable templates.
 * Each letter is saved to the case file and logged. Recipient details are encrypted.
 */
declare(strict_types=1);

function signatory(string $key): array {
    $s = portal_settings()['signatories'];
    if ($key === 'system') return ['name' => 'Droitwich United Charities', 'role' => '', 'contact' => '', 'closing' => 'With best wishes,'];
    $x = $s[$key] ?? $s['clerk'];
    return $x + ['closing' => 'Yours sincerely,'];
}
function signoff_html(string $key): string {
    $s = signatory($key);
    if ($key === 'system') return '<p>With best wishes,<br><b>Droitwich United Charities</b></p>';
    return '<p>' . h($s['closing']) . '<br><b>' . h($s['name']) . '</b><br>' . h($s['role']) . ($s['contact'] ? '<br><span style="color:#5A6475">' . h($s['contact']) . '</span>' : '') . '</p>';
}

/** Fields that can be filled in automatically from a referral. */
function referral_fields(array $r): array {
    $p = dec($r['enc'], []);
    $o = find_org($r['orgId']);
    $d = $r['decision'] ?? [];
    return ['recipient_name' => $p['name'] ?? '', 'address_1' => trim(($p['address1'] ?? '') . (empty($p['address2']) ? '' : "\n" . $p['address2'])),
        'town_postcode' => trim(($p['town'] ?? 'Droitwich Spa') . ', ' . ($p['postcode'] ?? ''), ', '), 'salutation' => $p['name'] ?? '', 'reference' => $r['ref'],
        'organisation_name' => $o['name'] ?? '', 'grant_amount' => !empty($d['amount']) ? '£' . number_format((float)$d['amount'], 2) : ($r['amount'] !== '' ? '£' . number_format((float)$r['amount'], 2) : ''),
        'grant_purpose' => $d['purpose'] ?? (REF_CATEGORIES[$r['category']] ?? ''), 'payment_method' => $d['payment'] ?? '', 'decline_reason' => $d['reason'] ?? '',
        'info_requested' => $d['info'] ?? '', 'respond_by' => uk_date(date('Y-m-d', strtotime('+14 days'))), 'email' => $p['email'] ?? '', 'privacy_link' => site_url() . '/privacy-notice'];
}
function standard_fields(): array { return ['date' => uk_date(), 'privacy_link' => site_url() . '/privacy-notice', 'portal_link' => site_url() . '/portal']; }

function render_letter(string $tplKey, array $fields, ?string $subject = null, ?string $body = null, ?string $sign = null): array {
    $tpls = letter_templates();
    if (!isset($tpls[$tplKey])) api_err(400, 'Unknown letter template');
    $t = $tpls[$tplKey];
    $f = array_merge(standard_fields(), $fields);
    $sigKey = in_array($sign, ['chairman', 'clerk', 'system'], true) ? $sign : $t['sign'];
    $sig = signatory($sigKey);
    $f['signatory_name'] = $sig['name']; $f['signatory_role'] = $sig['role']; $f['signatory_contact'] = $sig['contact'];
    $subj = merge_fields($subject ?? $t['subject'], $f);
    $text = merge_fields($body ?? $t['body'], $f);
    return ['subject' => $subj, 'body' => $text, 'sign' => $sigKey, 'fields' => $f, 'missing' => array_values(array_unique(array_merge(missing_fields($subject ?? $t['subject'], $f), missing_fields($body ?? $t['body'], $f))))];
}
function missing_fields(string $s, array $f): array {
    preg_match_all('/\{([a-z0-9_]+)\}/', $s, $m);
    return array_values(array_filter($m[1], function ($k) use ($f) { return !isset($f[$k]) || $f[$k] === ''; }));
}

function save_letter(?array $u, string $tplKey, array $fields, string $refType, string $refId, ?string $subject = null, ?string $body = null, ?string $sign = null, ?string $existingId = null): array {
    $rl = render_letter($tplKey, $fields, $subject, $body, $sign);
    $docx = build_letter_docx($rl['fields'], $rl['subject'], $rl['body'], signatory($rl['sign']));
    $id = $existingId ?: new_id();
    file_put_contents(files_dir('letters') . "/$id.docx", $docx);
    $rec = ['id' => $id, 'template' => $tplKey, 'templateName' => letter_templates()[$tplKey]['name'], 'refType' => $refType, 'refId' => $refId,
        'enc' => enc(['fields' => $fields, 'subject' => $subject, 'body' => $body]), 'sign' => $rl['sign'], 'subject' => $refType === 'referral' || $refType === 'sar' ? letter_safe_subject($rl['subject']) : $rl['subject'],
        'status' => 'draft', 'by' => $u['id'] ?? '', 'at' => now_iso(), 'file' => "$id.docx", 'missing' => $rl['missing']];
    with_lock('letters.json', [], function (&$l) use ($rec, $existingId) {
        if ($existingId) { foreach ($l as &$x) if ($x['id'] === $existingId) { $x = array_merge($x, $rec, ['status' => $x['status']]); return; } }
        $l[] = $rec;
    });
    audit($u['username'] ?? '(system)', $existingId ? 'letter.updated' : 'letter.created', $rec['templateName'] . ($refType ? " for $refType " . ref_label($refType, $refId) : ''));
    return $rec;
}
function letter_safe_subject(string $s): string { return $s; }
function ref_label(string $type, string $id): string {
    if ($type === 'referral') { $r = find_referral($id); return $r['ref'] ?? $id; }
    if ($type === 'sar') { $s = find_sar($id); return $s['ref'] ?? $id; }
    if ($type === 'org') { $o = find_org($id); return $o['name'] ?? $id; }
    return $id;
}
function create_letter_for_referral(?array $u, string $tplKey, string $refId): ?array {
    $r = find_referral($refId);
    if (!$r) return null;
    return save_letter($u, $tplKey, referral_fields($r), 'referral', $refId);
}
function find_letter(string $id): ?array { foreach (read_json('letters.json', []) as $l) if ($l['id'] === $id) return $l; return null; }
function letter_out(array $l): array {
    $e = dec($l['enc'] ?? '', []);
    return ['id' => $l['id'], 'template' => $l['template'], 'templateName' => $l['templateName'], 'refType' => $l['refType'], 'refId' => $l['refId'], 'refLabel' => $l['refId'] ? ref_label($l['refType'], $l['refId']) : '',
        'recipient' => $e['fields']['recipient_name'] ?? '', 'subject' => $l['subject'], 'status' => $l['status'], 'sign' => $l['sign'] ?? '', 'by' => user_name($l['by']), 'at' => $l['at'],
        'sentVia' => $l['sentVia'] ?? '', 'sentTo' => $l['sentTo'] ?? '', 'sentAt' => $l['sentAt'] ?? '', 'missing' => $l['missing'] ?? [],
        'fields' => $e['fields'] ?? [], 'customSubject' => $e['subject'] ?? null, 'customBody' => $e['body'] ?? null];
}

function api_p_letter_templates(string $key = ''): void {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') { pguard('clerk'); json_out(200, letter_templates()); }
    $u = pguard('admin');
    $all = letter_templates();
    if (!isset($all[$key])) api_err(404, 'Template not found');
    $b = body_json();
    if (!empty($b['reset'])) {
        with_lock('letter-templates.json', [], function (&$o) use ($key) { unset($o[$key]); });
        audit($u['username'], 'letter_template.reset', $all[$key]['name']);
        json_out(200, ['ok' => true]);
    }
    $v = ['subject' => clip($b['subject'] ?? '', 300), 'body' => clip($b['body'] ?? '', 20000), 'sign' => in_array($b['sign'] ?? '', ['chairman', 'clerk', 'system'], true) ? $b['sign'] : $all[$key]['sign'], 'updated' => now_iso(), 'by' => $u['name']];
    if ($v['subject'] === '' || $v['body'] === '') api_err(400, 'Subject and wording are both needed');
    with_lock('letter-templates.json', [], function (&$o) use ($key, $v) {
        $hist = $o[$key]['history'] ?? [];
        if (isset($o[$key])) $hist[] = array_intersect_key($o[$key], array_flip(['subject', 'body', 'sign', 'updated', 'by']));
        $o[$key] = $v + ['history' => array_slice($hist, -20)];
    });
    audit($u['username'], 'letter_template.updated', $all[$key]['name']);
    json_out(200, ['ok' => true]);
}
function api_p_letters(): void {
    $u = pguard('board');
    if (!is_clerkish($u) && !is_gdpr($u)) api_err(403, 'No access');
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $l = read_json('letters.json', []);
        if (!empty($_GET['refType'])) $l = array_filter($l, function ($x) { return $x['refType'] === $_GET['refType'] && $x['refId'] === ($_GET['refId'] ?? ''); });
        if (!is_clerkish($u)) $l = array_filter($l, function ($x) { return $x['refType'] === 'sar' || ($x['template'] && letter_templates()[$x['template']]['group'] === 'GDPR'); });
        usort($l, function ($a, $b) { return [$a['status'] !== 'draft', $b['at']] <=> [$b['status'] !== 'draft', $a['at']]; });
        json_out(200, array_values(array_map('letter_out', $l)));
    }
    $b = body_json();
    $tpl = (string)($b['template'] ?? '');
    $refType = in_array($b['refType'] ?? '', ['referral', 'sar', 'org', 'breach', ''], true) ? (string)($b['refType'] ?? '') : '';
    $refId = clip($b['refId'] ?? '', 20);
    $fields = [];
    if ($refType === 'referral') { $r = find_referral($refId); if (!$r) api_err(404, 'Referral not found'); $fields = referral_fields($r); }
    if ($refType === 'sar') { $s = find_sar($refId); if (!$s) api_err(404, 'Request not found'); $fields = sar_fields($s); }
    foreach ((array)($b['fields'] ?? []) as $k => $v) if (preg_match('/^[a-z0-9_]{1,40}$/', (string)$k)) $fields[$k] = clip($v, 4000);
    if (!empty($b['preview'])) {
        $rl = render_letter($tpl, $fields, isset($b['subject']) ? (string)$b['subject'] : null, isset($b['body']) ? (string)$b['body'] : null, $b['sign'] ?? null);
        json_out(200, ['subject' => $rl['subject'], 'html' => text_to_html($rl['body']) . signoff_html($rl['sign']), 'missing' => $rl['missing'], 'fields' => $rl['fields']]);
    }
    $rec = save_letter($u, $tpl, $fields, $refType, $refId, isset($b['subject']) ? (string)$b['subject'] : null, isset($b['body']) ? (string)$b['body'] : null, $b['sign'] ?? null);
    json_out(200, letter_out($rec));
}
function api_p_letter(string $id): void {
    $u = pguard('board');
    if (!is_clerkish($u) && !is_gdpr($u)) api_err(403, 'No access');
    $l = find_letter($id);
    if (!$l) api_err(404, 'Letter not found');
    $method = $_SERVER['REQUEST_METHOD'];
    if ($method === 'GET') {
        audit($u['username'], 'letter.downloaded', $l['templateName'] . ($l['refId'] ? ' – ' . ref_label($l['refType'], $l['refId']) : ''));
        send_file(files_dir('letters') . '/' . $l['file'], safe_name($l['templateName'] . ($l['refId'] ? ' ' . ref_label($l['refType'], $l['refId']) : '')) . '.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    }
    if ($method === 'DELETE') {
        if ($l['status'] !== 'draft' && !has_role($u, ['admin'])) api_err(400, 'Only drafts can be deleted');
        with_lock('letters.json', [], function (&$x) use ($id) { $x = array_values(array_filter($x, function ($l) use ($id) { return $l['id'] !== $id; })); });
        @unlink(files_dir('letters') . '/' . $l['file']);
        audit($u['username'], 'letter.deleted', $l['templateName']);
        json_out(200, ['ok' => true]);
    }
    $b = body_json();
    $act = (string)($b['action'] ?? 'update');
    if ($act === 'update') {
        if ($l['status'] !== 'draft') api_err(400, 'This letter has been sent and cannot be changed. Create a new one instead.');
        $e = dec($l['enc'], []);
        $fields = $e['fields'] ?? [];
        foreach ((array)($b['fields'] ?? []) as $k => $v) if (preg_match('/^[a-z0-9_]{1,40}$/', (string)$k)) $fields[$k] = clip($v, 4000);
        $rec = save_letter($u, $l['template'], $fields, $l['refType'], $l['refId'], array_key_exists('subject', $b) ? $b['subject'] : ($e['subject'] ?? null),
            array_key_exists('body', $b) ? $b['body'] : ($e['body'] ?? null), $b['sign'] ?? $l['sign'], $id);
        json_out(200, letter_out(find_letter($id)));
    }
    if ($act === 'posted') {
        with_lock('letters.json', [], function (&$x) use ($id, $u) { foreach ($x as &$l) if ($l['id'] === $id) { $l['status'] = 'sent'; $l['sentVia'] = 'post'; $l['sentAt'] = now_iso(); $l['sentBy'] = $u['id']; } });
        audit($u['username'], 'letter.posted', $l['templateName'] . ($l['refId'] ? ' – ' . ref_label($l['refType'], $l['refId']) : ''));
        json_out(200, ['ok' => true]);
    }
    if ($act === 'email') {
        $to = strtolower(clip($b['to'] ?? '', 200));
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) api_err(400, 'Enter an email address');
        $e = dec($l['enc'], []);
        $rl = render_letter($l['template'], $e['fields'] ?? [], $e['subject'] ?? null, $e['body'] ?? null, $l['sign']);
        $docx = (string)file_get_contents(files_dir('letters') . '/' . $l['file']);
        $html = '<p>Dear ' . h($rl['fields']['salutation'] ?? 'Sir or Madam') . ',</p>' . text_to_html($rl['body']) . signoff_html($rl['sign']);
        $ok = send_email($to, $rl['subject'], $html, [['name' => safe_name($l['templateName']) . '.docx', 'type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'data' => $docx]], 'letter: ' . $l['templateName']);
        with_lock('letters.json', [], function (&$x) use ($id, $u, $to, $ok) { foreach ($x as &$l) if ($l['id'] === $id) { $l['status'] = $ok ? 'sent' : 'queued'; $l['sentVia'] = 'email'; $l['sentTo'] = $to; $l['sentAt'] = now_iso(); $l['sentBy'] = $u['id']; } });
        audit($u['username'], 'letter.emailed', $l['templateName'] . ' to ' . $to . ($ok ? '' : ' (queued – will retry)'));
        json_out(200, ['ok' => true, 'sent' => $ok]);
    }
    api_err(400, 'Unknown action');
}
function letter_routes(string $id): array {
    return [
        ['GET', '/api/p/letter-templates', 'api_p_letter_templates'], ['PUT', '/api/p/letter-templates/([a-z_]+)', 'api_p_letter_templates'],
        ['*', '/api/p/letters', 'api_p_letters'], ['*', "/api/p/letters/$id", 'api_p_letter'],
    ];
}
