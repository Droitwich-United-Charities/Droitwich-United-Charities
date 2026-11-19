<?php
/**
 * Referral partners: organisations, their logins, referrals, evidence, messages.
 * Personal details of people being referred are encrypted at rest (enc/dec in lib.php).
 */
declare(strict_types=1);

const ORG_TYPES = ['school' => 'School or college', 'health' => 'GP surgery or health service', 'social' => 'Social services', 'housing' => 'Housing provider',
    'foodbank' => 'Food bank or pantry', 'charity' => 'Charity or voluntary group', 'church' => 'Church or faith group', 'council' => 'Council service', 'other' => 'Other'];
const REF_CATEGORIES = ['household' => 'Household items or furniture', 'utilities' => 'Gas, electricity or water', 'food' => 'Food', 'clothing' => 'Clothing or school uniform',
    'education' => 'Education or training', 'health' => 'Health, mobility or disability equipment', 'travel' => 'Travel or transport', 'other' => 'Something else'];
const REF_STATUSES = ['draft' => 'Draft', 'submitted' => 'Submitted', 'more_info' => 'More information needed', 'with_trustees' => 'With trustees',
    'approved' => 'Approved', 'declined' => 'Declined', 'paid' => 'Paid / closed', 'withdrawn' => 'Withdrawn'];

// ---------- organisations ----------
function find_org(string $id): ?array { foreach (read_json('orgs.json', []) as $o) if ($o['id'] === $id) return $o; return null; }
function public_org(array $o): array {
    return ['id' => $o['id'], 'name' => $o['name'], 'type' => $o['type'], 'typeLabel' => ORG_TYPES[$o['type']] ?? $o['type'], 'status' => $o['status'],
        'address' => $o['address'], 'regNo' => $o['regNo'], 'contactName' => $o['contactName'], 'contactEmail' => $o['contactEmail'], 'contactPhone' => $o['contactPhone'],
        'dpContact' => $o['dpContact'], 'agreement' => $o['agreement'] ?? null, 'created' => $o['created'], 'notes' => $o['notes'] ?? '', 'serves' => $o['serves'] ?? ''];
}

function handle_partner_apply(): void {
    if (settings()['maintenance']) maintenance_page(current_user());
    $form = function (string $msg = '', bool $done = false) {
        if ($done) render_page(200, 'Thank you', '<h1>Thank you</h1><p>We have received your application to become a referral partner. Our Clerk will check the details and email you, usually within a week.</p><p><a href="/">Back to the website</a></p>', true);
        $v = function ($k) { return h($_POST[$k] ?? ''); };
        $opts = '';
        foreach (ORG_TYPES as $k => $l) $opts .= '<option value="' . $k . '"' . (($_POST['type'] ?? '') === $k ? ' selected' : '') . '>' . h($l) . '</option>';
        $b = '<h1>Become a referral partner</h1><p>Local organisations that support people in Droitwich Spa can refer them to Droitwich United Charities (D.U.C.) for small grants, using their own secure login.</p>';
        if ($msg) $b .= '<div class="err">' . h($msg) . '</div>';
        $b .= '<form method="post" action="/partners/apply"><div style="position:absolute;left:-9999px"><label>Leave blank<input name="website" tabindex="-1" autocomplete="off"></label></div>'
            . '<label>Organisation name</label><input name="name" required value="' . $v('name') . '">'
            . '<label>Type of organisation</label><select name="type" required style="width:100%;padding:11px;border:1px solid #cfd3dc;border-radius:10px;font:inherit">' . $opts . '</select>'
            . '<label>Address</label><input name="address" required value="' . $v('address') . '">'
            . '<label>Charity or company number (if you have one)</label><input name="regNo" value="' . $v('regNo') . '">'
            . '<label>How do you support people in Droitwich Spa?</label><textarea name="serves" required rows="3" style="width:100%;padding:11px;border:1px solid #cfd3dc;border-radius:10px;font:inherit">' . $v('serves') . '</textarea>'
            . '<label>Main contact – name</label><input name="contactName" required value="' . $v('contactName') . '">'
            . '<label>Main contact – work email</label><input name="contactEmail" type="email" required value="' . $v('contactEmail') . '">'
            . '<label>Main contact – phone</label><input name="contactPhone" value="' . $v('contactPhone') . '">'
            . '<label>Who handles data protection at your organisation? (name and email)</label><input name="dpContact" required value="' . $v('dpContact') . '">'
            . '<label style="display:flex;gap:8px;font-weight:400;align-items:flex-start"><input type="checkbox" name="ok" value="1" required style="width:auto;margin-top:4px"> I confirm I can apply on behalf of this organisation, and that D.U.C. may keep these details to consider our application.</label>'
            . '<button type="submit">Send application</button></form><p class="small"><a href="/privacy-notice">How we look after your information</a></p>';
        render_page(200, 'Become a referral partner', $b, true);
    };
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') $form();
    if (!empty($_POST['website'])) $form('', true);
    $o = ['id' => new_id(), 'name' => clip($_POST['name'] ?? '', 160), 'type' => isset(ORG_TYPES[$_POST['type'] ?? '']) ? $_POST['type'] : 'other', 'address' => clip($_POST['address'] ?? '', 300),
        'regNo' => clip($_POST['regNo'] ?? '', 40), 'serves' => clip($_POST['serves'] ?? '', 2000), 'contactName' => clip($_POST['contactName'] ?? '', 120),
        'contactEmail' => strtolower(clip($_POST['contactEmail'] ?? '', 200)), 'contactPhone' => clip($_POST['contactPhone'] ?? '', 40), 'dpContact' => clip($_POST['dpContact'] ?? '', 300),
        'status' => 'pending', 'created' => now_iso(), 'agreement' => null, 'notes' => '', '_ip' => client_ip()];
    if ($o['name'] === '' || $o['address'] === '' || $o['contactName'] === '' || $o['serves'] === '' || $o['dpContact'] === '') $form('Please fill in all the required boxes.');
    if (!filter_var($o['contactEmail'], FILTER_VALIDATE_EMAIL)) $form('Please enter a valid email address.');
    if (empty($_POST['ok'])) $form('Please tick the box to confirm.');
    $ok = with_lock('orgs.json', [], function (&$l) use ($o) {
        $n = 0; foreach ($l as $x) if (($x['_ip'] ?? '') === $o['_ip'] && strtotime($x['created']) > time() - 86400) $n++;
        if ($n >= 3) return false;
        $l[] = $o; return true;
    });
    if (!$ok) $form('Too many applications from this connection today. Please contact the Clerk on 01905 775226.');
    audit('(visitor)', 'partner.applied', $o['name'] . ' (' . ORG_TYPES[$o['type']] . ')');
    foreach (users_with_roles(['clerk']) as $c) if (user_email($c))
        send_email(user_email($c), 'New referral partner application: ' . $o['name'], '<p><b>' . h($o['name']) . '</b> (' . h(ORG_TYPES[$o['type']]) . ') has applied to become a referral partner.</p>' . email_button(site_url() . '/portal#partners', 'Review the application'), [], 'partner application');
    $form('', true);
}

function api_p_orgs(): void {
    pguard('clerk');
    $l = read_json('orgs.json', []);
    usort($l, function ($a, $b) { return [$a['status'] !== 'pending', $a['name']] <=> [$b['status'] !== 'pending', $b['name']]; });
    $refs = read_json('referrals.json', []);
    $users = users();
    json_out(200, array_map(function ($o) use ($refs, $users) {
        $p = public_org($o);
        $p['referrals'] = count(array_filter($refs, function ($r) use ($o) { return $r['orgId'] === $o['id'] && $r['status'] !== 'draft'; }));
        $p['users'] = array_values(array_map(function ($u) { return ['id' => $u['id'], 'name' => $u['name'], 'email' => user_email($u), 'orgAdmin' => !empty($u['orgAdmin']), 'disabled' => !empty($u['disabled']), 'lastLogin' => $u['lastLogin']]; },
            array_filter($users, function ($u) use ($o) { return ($u['orgId'] ?? '') === $o['id']; })));
        return $p;
    }, $l));
}
function api_p_org(string $id): void {
    $u = pguard('clerk');
    $b = body_json();
    $o = find_org($id);
    if (!$o) api_err(404, 'Organisation not found');
    $status = (string)($b['status'] ?? $o['status']);
    if (!in_array($status, ['pending', 'approved', 'suspended', 'rejected'], true)) api_err(400, 'Invalid status');
    with_lock('orgs.json', [], function (&$l) use ($id, $status, $b) {
        foreach ($l as &$x) if ($x['id'] === $id) { $x['status'] = $status; if (isset($b['notes'])) $x['notes'] = clip($b['notes'], 2000); $x['reviewedAt'] = now_iso(); }
    });
    $extra = [];
    if ($status === 'approved' && $o['status'] !== 'approved') {
        $existing = find_user(users(), 'username', $o['contactEmail']);
        if (!$existing) {
            [$nu, $link] = create_invited_user($u, $o['contactName'], $o['contactEmail'], 'partner', ['orgId' => $id, 'orgAdmin' => true]);
            $extra['emailed'] = send_partner_welcome($o, $nu, $link, $u);
            if (!$extra['emailed']) $extra['link'] = $link;
        } else {
            with_lock('users.json', [], function (&$l) use ($existing) { foreach ($l as &$x) if ($x['id'] === $existing['id']) $x['disabled'] = false; });
        }
    }
    if (in_array($status, ['suspended', 'rejected'], true)) {
        $ids = [];
        with_lock('users.json', [], function (&$l) use ($id, &$ids) { foreach ($l as &$x) if (($x['orgId'] ?? '') === $id) { $x['disabled'] = true; $ids[] = $x['id']; } });
        foreach ($ids as $uid) revoke_sessions($uid);
    }
    audit($u['username'], 'partner.' . $status, $o['name']);
    json_out(200, ['ok' => true] + $extra);
}
function send_partner_welcome(array $o, array $nu, string $link, array $by): bool {
    $tpl = letter_templates()['partner_welcome'];
    $sig = signatory($tpl['sign']);
    $f = ['recipient_name' => $o['contactName'], 'organisation' => $o['name'], 'address_1' => $o['address'], 'salutation' => $o['contactName'], 'portal_link' => $link, 'date' => uk_date()];
    $docx = build_letter_docx($f, merge_fields($tpl['subject'], $f), merge_fields(str_replace('{portal_link}', 'Please use the link in the email we sent you to set your password.', $tpl['body']), $f), $sig);
    $lid = new_id(); file_put_contents(files_dir('letters') . "/$lid.docx", $docx);
    with_lock('letters.json', [], function (&$l) use ($lid, $tpl, $o, $by) {
        $l[] = ['id' => $lid, 'template' => 'partner_welcome', 'templateName' => $tpl['name'], 'refType' => 'org', 'refId' => $o['id'], 'enc' => enc(['fields' => ['recipient_name' => $o['contactName'], 'organisation' => $o['name'], 'email' => $o['contactEmail']]]),
            'subject' => merge_fields($tpl['subject'], []), 'status' => 'sent', 'sentVia' => 'email', 'sentTo' => $o['contactEmail'], 'sentAt' => now_iso(), 'by' => $by['id'], 'at' => now_iso(), 'file' => "$lid.docx"];
    });
    $html = '<p>Dear ' . h($o['contactName']) . ',</p>' . str_replace(h($link), email_button($link, 'Set my password'), text_to_html(merge_fields($tpl['body'], $f))) . signoff_html($tpl['sign']);
    return send_email($o['contactEmail'], merge_fields($tpl['subject'], $f), $html, [['name' => 'Welcome letter - Droitwich United Charities.docx', 'type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'data' => $docx]], 'partner welcome');
}

/** Partner main contacts (orgAdmin) manage their own colleagues; the Clerk can manage any organisation. */
function api_p_org_users(string $uid = ''): void {
    $u = pguard('any');
    if (is_partner($u)) { require_agreed($u); if (empty($u['orgAdmin'])) api_err(403, 'Only your main contact can manage logins'); $orgId = $u['orgId']; }
    elseif (is_clerkish($u)) $orgId = (string)($_GET['org'] ?? body_json()['orgId'] ?? '');
    else api_err(403, 'No access');
    $o = find_org($orgId);
    if (!$o) api_err(404, 'Organisation not found');
    $method = $_SERVER['REQUEST_METHOD'];
    if ($method === 'GET') {
        json_out(200, array_values(array_map(function ($x) { return ['id' => $x['id'], 'name' => $x['name'], 'email' => user_email($x), 'orgAdmin' => !empty($x['orgAdmin']), 'lastLogin' => $x['lastLogin'], 'disabled' => !empty($x['disabled'])]; },
            array_filter(users(), function ($x) use ($orgId) { return ($x['orgId'] ?? '') === $orgId; }))));
    }
    if ($method === 'DELETE') {
        $t = user_by_id($uid);
        if (!$t || ($t['orgId'] ?? '') !== $orgId) api_err(404, 'User not found');
        if ($t['id'] === $u['id']) api_err(400, "You can't remove yourself");
        with_lock('users.json', [], function (&$l) use ($uid) { foreach ($l as $i => $x) if ($x['id'] === $uid) array_splice($l, $i, 1); });
        revoke_sessions($uid);
        audit($u['username'], 'partner.user_removed', $t['username'] . ' from ' . $o['name']);
        json_out(200, ['ok' => true]);
    }
    $b = body_json();
    [$nu, $link] = create_invited_user($u, (string)($b['name'] ?? ''), (string)($b['email'] ?? ''), 'partner', ['orgId' => $orgId, 'orgAdmin' => !empty($b['orgAdmin']) && is_clerkish($u), 'privacyVersion' => 0]);
    $tpl = letter_templates()['partner_invite'];
    $f = ['invited_by' => $u['name'], 'organisation_name' => $o['name'], 'portal_link' => $link];
    $html = '<p>Dear ' . h($nu['name']) . ',</p>' . str_replace(h($link), email_button($link, 'Set my password'), text_to_html(merge_fields($tpl['body'], $f))) . signoff_html($tpl['sign']);
    $sent = send_email($nu['email'], merge_fields($tpl['subject'], $f), $html, [], 'partner invite');
    json_out(200, ['ok' => true, 'emailed' => $sent, 'link' => $sent ? '' : $link]);
}
function api_p_partner_agree(): void {
    $u = pguard('partner');
    $b = body_json();
    $n = partner_needs($u);
    $org = find_org($u['orgId']);
    if ($n['agreement']) {
        if (empty($b['agreement'])) api_err(400, 'Please tick to accept the data sharing agreement');
        if (empty($u['orgAdmin'])) api_err(403, 'Your main contact needs to accept the updated agreement first');
        $a = agreement();
        with_lock('orgs.json', [], function (&$l) use ($u, $a) { foreach ($l as &$o) if ($o['id'] === $u['orgId']) $o['agreement'] = ['version' => $a['version'], 'by' => $u['id'], 'name' => $u['name'], 'at' => now_iso()]; });
        audit($u['username'], 'partner.agreement_accepted', $org['name'] . ' – version ' . $a['version']);
        $tpl = letter_templates()['agreement_confirm'];
        $f = ['agreed_by' => $u['name'], 'agreement_version' => (string)$a['version'], 'organisation_name' => $org['name'], 'date' => uk_date()];
        if (user_email($u)) send_email(user_email($u), merge_fields($tpl['subject'], $f), '<p>Dear ' . h($u['name']) . ',</p>' . text_to_html(merge_fields($tpl['body'], $f)) . signoff_html($tpl['sign']), [], 'agreement confirmation');
    }
    if ($n['privacy']) {
        if (empty($b['privacy'])) api_err(400, 'Please tick to confirm you have read the privacy notice');
        $v = current_notice()['version'];
        with_lock('users.json', [], function (&$l) use ($u, $v) { foreach ($l as &$x) if ($x['id'] === $u['id']) $x['privacyVersion'] = $v; });
        record_consent(['subject' => $u['name'] . ' (' . $org['name'] . ')', 'subjectType' => 'partner_user', 'subjectId' => $u['id'], 'purpose' => 'Read privacy notice', 'method' => 'Portal sign-in', 'noticeVersion' => $v, 'by' => $u['id']]);
        audit($u['username'], 'privacy.accepted', 'Privacy notice version ' . $v);
    }
    json_out(200, ['ok' => true]);
}

// ---------- referrals ----------
function find_referral(string $id): ?array { foreach (read_json('referrals.json', []) as $r) if ($r['id'] === $id) return $r; return null; }
function working_days_since(string $iso): int {
    $t = strtotime($iso); $n = 0;
    for ($d = strtotime('tomorrow', $t); $d <= time(); $d += 86400) if ((int)date('N', $d) < 6) $n++;
    return $n;
}
function referral_is_late(array $r): bool { return in_array($r['status'], ['submitted'], true) && !empty($r['submitted']) && working_days_since($r['submitted']) > 10; }
function referral_out(array $r, array $u, bool $full): array {
    $p = dec($r['enc'], []);
    $o = find_org($r['orgId']);
    $out = ['id' => $r['id'], 'ref' => $r['ref'], 'status' => $r['status'], 'statusLabel' => REF_STATUSES[$r['status']] ?? $r['status'], 'orgId' => $r['orgId'], 'org' => $o['name'] ?? '',
        'by' => user_name($r['by']), 'created' => $r['created'], 'updated' => $r['updated'], 'submitted' => $r['submitted'] ?? '', 'category' => $r['category'],
        'categoryLabel' => REF_CATEGORIES[$r['category']] ?? '', 'amount' => $r['amount'], 'late' => referral_is_late($r), 'dup' => !empty($r['dup']),
        'name' => $full ? ($p['name'] ?? '') : initials($p['name'] ?? ''), 'postcode' => $full ? ($p['postcode'] ?? '') : '', 'legalHold' => $r['legalHold'] ?? null,
        'unread' => 0, 'decision' => $r['decision'] ?? null, 'inArea' => postcode_in_area($p['postcode'] ?? '')];
    $side = is_partner($u) ? 'partner' : 'duc';
    foreach ($r['messages'] as $m) if ($m['side'] !== $side && empty($m['read'][$side])) $out['unread']++;
    if ($full) {
        $out['person'] = $p;
        $out['consent'] = $r['consent'];
        $out['files'] = array_map(function ($f) { return ['id' => $f['id'], 'name' => $f['name'], 'size' => $f['size'], 'type' => $f['type'], 'at' => $f['at']]; }, $r['files']);
        $out['messages'] = array_map(function ($m) { return ['id' => $m['id'], 'side' => $m['side'], 'from' => $m['fromName'], 'text' => dec($m['enc'], ''), 'at' => $m['at']]; }, $r['messages']);
        $out['history'] = $r['history'];
        if (is_partner($u) && isset($out['decision'])) $out['decision'] = array_intersect_key($out['decision'], array_flip(['outcome', 'amount', 'payment', 'at']));
    }
    return $out;
}
function postcode_in_area(string $pc): bool {
    $pc = strtoupper(preg_replace('/\s+/', ' ', trim($pc)));
    if ($pc === '') return false;
    if (!preg_match('/^([A-Z]{1,2}\d[A-Z\d]?) ?(\d)[A-Z]{2}$/', $pc, $m)) return false;
    $sector = $m[1] . ' ' . $m[2];
    foreach (portal_settings()['postcodes'] as $p) if (strpos($sector, strtoupper(trim($p))) === 0) return true;
    return false;
}
/** Can $u see/edit referral $r? */
function referral_access(array $u, array $r): bool {
    if (is_partner($u)) return $r['orgId'] === ($u['orgId'] ?? '');
    return is_clerkish($u) || has_role($u, ['dplead']);
}
function api_p_referrals(): void {
    $u = pguard('any');
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        if (is_partner($u)) require_agreed($u);
        elseif (!is_clerkish($u) && !has_role($u, ['dplead'])) api_err(403, 'Trustees see requests under Decisions');
        $l = array_filter(read_json('referrals.json', []), function ($r) use ($u) { return referral_access($u, $r) && (is_partner($u) || $r['status'] !== 'draft'); });
        usort($l, function ($a, $b) { return strcmp($b['updated'], $a['updated']); });
        $out = array_values(array_map(function ($r) use ($u) { return referral_out($r, $u, true); }, $l));
        foreach ($out as &$o) { unset($o['person'], $o['messages'], $o['history'], $o['files']); $o['fileCount'] = count(find_referral($o['id'])['files']); }
        $stats = null;
        if (is_partner($u)) {
            $year = date('Y'); $mine = array_filter($l, function ($r) use ($year) { return substr($r['submitted'] ?? '', 0, 4) === $year; });
            $stats = ['made' => count($mine), 'approved' => count(array_filter($mine, function ($r) { return in_array($r['status'], ['approved', 'paid'], true); })),
                'total' => array_sum(array_map(function ($r) { return in_array($r['status'], ['approved', 'paid'], true) ? (float)($r['decision']['amount'] ?? 0) : 0; }, $mine))];
        }
        json_out(200, ['referrals' => $out, 'stats' => $stats, 'categories' => REF_CATEGORIES, 'statuses' => REF_STATUSES]);
    }
    if (!is_partner($u)) api_err(403, 'Referrals are made by referral partners');
    require_agreed($u);
    $now = now_iso();
    $r = ['id' => new_id(), 'ref' => '', 'orgId' => $u['orgId'], 'by' => $u['id'], 'status' => 'draft', 'created' => $now, 'updated' => $now, 'category' => 'other', 'amount' => '',
        'enc' => enc([]), 'consent' => null, 'files' => [], 'messages' => [], 'history' => [['at' => $now, 'by' => $u['name'], 'what' => 'Draft started']], 'dupKey' => ''];
    with_lock('referrals.json', [], function (&$l) use ($r) { $l[] = $r; });
    referral_save($u, $r['id'], body_json());
    json_out(200, referral_out(find_referral($r['id']), $u, true));
}
const PERSON_FIELDS = ['name' => 120, 'dob' => 10, 'address1' => 200, 'address2' => 200, 'town' => 80, 'postcode' => 10, 'phone' => 40, 'email' => 200,
    'household' => 2000, 'need' => 4000, 'why' => 3000, 'tried' => 3000, 'contactPref' => 10, 'payee' => 300];
function referral_save(array $u, string $id, array $b): void {
    with_lock('referrals.json', [], function (&$l) use ($id, $b, $u) {
        foreach ($l as &$r) if ($r['id'] === $id) {
            $p = dec($r['enc'], []);
            foreach (PERSON_FIELDS as $k => $n) if (isset($b[$k])) $p[$k] = clip($b[$k], $n);
            if (isset($p['postcode'])) $p['postcode'] = strtoupper(preg_replace('/\s+/', ' ', $p['postcode']));
            if (isset($p['contactPref']) && !in_array($p['contactPref'], ['post', 'email', 'none'], true)) $p['contactPref'] = 'none';
            $r['enc'] = enc($p);
            if (isset($b['category']) && isset(REF_CATEGORIES[$b['category']])) $r['category'] = $b['category'];
            if (isset($b['amount'])) $r['amount'] = preg_replace('/[^0-9.]/', '', (string)$b['amount']);
            if (isset($b['consent']) && is_array($b['consent'])) {
                $c = $b['consent'];
                $r['consent'] = ['given' => !empty($c['given']), 'method' => in_array($c['method'] ?? '', ['in_person', 'phone', 'written', 'parent'], true) ? $c['method'] : 'in_person',
                    'date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($c['date'] ?? '')) ? $c['date'] : date('Y-m-d'), 'health' => !empty($c['health']),
                    'noticeGiven' => in_array($c['noticeGiven'] ?? '', ['told', 'email', 'post', 'paper'], true) ? $c['noticeGiven'] : 'told', 'by' => $u['id'], 'noticeVersion' => current_notice()['version']];
            }
            $r['updated'] = now_iso();
            $r['dupKey'] = person_key($p['name'] ?? '', $p['postcode'] ?? '');
        }
    });
}
function api_p_referral(string $id): void {
    $u = pguard('any');
    if (is_partner($u)) require_agreed($u);
    $r = find_referral($id);
    if (!$r || !referral_access($u, $r)) api_err(404, 'Referral not found');
    $method = $_SERVER['REQUEST_METHOD'];
    if ($method === 'GET') {
        if (!is_partner($u)) audit($u['username'], 'referral.viewed', ($r['ref'] ?: 'draft') . ' – personal details shown');
        $side = is_partner($u) ? 'partner' : 'duc';
        with_lock('referrals.json', [], function (&$l) use ($id, $side) { foreach ($l as &$x) if ($x['id'] === $id) foreach ($x['messages'] as &$m) if ($m['side'] !== $side) $m['read'][$side] = true; });
        json_out(200, referral_out($r, $u, true));
    }
    if ($method === 'DELETE') {
        if (!is_partner($u) || $r['status'] !== 'draft') api_err(400, 'Only drafts can be deleted');
        with_lock('referrals.json', [], function (&$l) use ($id) { $l = array_values(array_filter($l, function ($x) use ($id) { return $x['id'] !== $id; })); });
        foreach ($r['files'] as $f) @unlink(files_dir('evidence') . '/' . $f['path']);
        audit($u['username'], 'referral.draft_deleted', 'Draft deleted');
        json_out(200, ['ok' => true]);
    }
    $editable = is_partner($u) ? in_array($r['status'], ['draft', 'more_info'], true) : is_clerkish($u);
    if (!$editable) api_err(400, 'This referral can no longer be changed. Send a message to the Clerk instead.');
    referral_save($u, $id, body_json());
    if ($r['status'] !== 'draft') {
        with_lock('referrals.json', [], function (&$l) use ($id, $u) { foreach ($l as &$x) if ($x['id'] === $id) $x['history'][] = ['at' => now_iso(), 'by' => $u['name'], 'what' => 'Details updated']; });
        audit($u['username'], 'referral.updated', $r['ref']);
    }
    json_out(200, referral_out(find_referral($id), $u, true));
}
function api_p_referral_submit(string $id): void {
    $u = pguard('partner');
    require_agreed($u);
    $r = find_referral($id);
    if (!$r || !referral_access($u, $r)) api_err(404, 'Referral not found');
    if (!in_array($r['status'], ['draft', 'more_info'], true)) api_err(400, 'Already submitted');
    $p = dec($r['enc'], []);
    $missing = [];
    foreach (['name' => 'name', 'address1' => 'address', 'postcode' => 'postcode', 'need' => 'what help is needed', 'why' => 'why D.U.C. is the right place'] as $k => $l) if (trim((string)($p[$k] ?? '')) === '') $missing[] = $l;
    if ($missing) api_err(400, 'Please add: ' . implode(', ', $missing));
    if (empty($r['consent']['given'])) api_err(400, 'Please confirm the person has agreed to this referral');
    $resub = $r['status'] === 'more_info';
    $ref = $r['ref'] ?: next_ref('R');
    $dup = false;
    foreach (read_json('referrals.json', []) as $x) if ($x['id'] !== $id && $x['dupKey'] === $r['dupKey'] && $x['status'] !== 'draft' && strtotime($x['created']) > time() - 365 * 86400) $dup = true;
    with_lock('referrals.json', [], function (&$l) use ($id, $ref, $u, $dup, $resub) {
        foreach ($l as &$x) if ($x['id'] === $id) {
            $x['ref'] = $ref; $x['status'] = 'submitted'; $x['submitted'] = $x['submitted'] ?? now_iso(); $x['updated'] = now_iso(); $x['dup'] = $dup;
            $x['history'][] = ['at' => now_iso(), 'by' => $u['name'], 'what' => $resub ? 'More information sent' : 'Submitted'];
        }
    });
    $org = find_org($r['orgId']);
    record_consent(['subject' => $ref, 'subjectType' => 'referral', 'subjectId' => $id, 'purpose' => 'Referral to D.U.C. and D.U.C. holding their details' . (!empty($r['consent']['health']) ? ' (including health information)' : ''),
        'method' => $r['consent']['method'] . ' via ' . ($org['name'] ?? 'partner'), 'noticeVersion' => $r['consent']['noticeVersion'], 'by' => $u['id'], 'date' => $r['consent']['date']]);
    audit($u['username'], $resub ? 'referral.resubmitted' : 'referral.submitted', $ref . ' from ' . ($org['name'] ?? '') . ($dup ? ' – possible duplicate' : ''));
    foreach (users_with_roles(['clerk']) as $c) if (user_email($c))
        send_email(user_email($c), ($resub ? 'More information received: ' : 'New referral: ') . $ref, '<p>' . h($org['name'] ?? 'A partner') . ($resub ? ' has sent more information for' : ' has made a new referral,') . ' <b>' . h($ref) . '</b>.' . ($dup ? ' It may be a duplicate of a recent referral.' : '') . '</p>' . email_button(site_url() . '/portal#referral/' . $id, 'Open the referral'), [], 'new referral');
    if (!$resub && in_array($p['contactPref'] ?? 'none', ['post', 'email'], true)) create_letter_for_referral(null, 'referral_received', $id);
    notify_partner_status(find_referral($id));
    json_out(200, ['ok' => true, 'ref' => $ref]);
}
function set_referral_status(string $id, string $status, ?array $u, string $note = ''): void {
    with_lock('referrals.json', [], function (&$l) use ($id, $status, $u, $note) {
        foreach ($l as &$r) if ($r['id'] === $id) {
            $r['status'] = $status; $r['updated'] = now_iso();
            if (in_array($status, ['paid', 'declined', 'withdrawn'], true)) $r['closedAt'] = now_iso();
            $r['history'][] = ['at' => now_iso(), 'by' => $u['name'] ?? 'D.U.C.', 'what' => (REF_STATUSES[$status] ?? $status) . ($note ? ' – ' . $note : '')];
        }
    });
    $r = find_referral($id);
    if ($r) notify_partner_status($r);
}
function notify_partner_status(array $r): void {
    $by = user_by_id($r['by']);
    $tpl = letter_templates()['referral_status'];
    $f = ['reference' => $r['ref'], 'status' => REF_STATUSES[$r['status']] ?? $r['status'], 'portal_link' => site_url() . '/portal#referral/' . $r['id']];
    if ($by && user_email($by) && empty($by['disabled']))
        send_email(user_email($by), merge_fields($tpl['subject'], $f), '<p>Dear ' . h($by['name']) . ',</p>' . str_replace(h($f['portal_link']), email_button($f['portal_link'], 'Sign in to see the referral'), text_to_html(merge_fields($tpl['body'], $f))) . signoff_html($tpl['sign']), [], 'referral status');
}
function api_p_referral_status(string $id): void {
    $u = pguard('clerk');
    $b = body_json();
    $st = (string)($b['status'] ?? '');
    if (!isset(REF_STATUSES[$st]) || $st === 'draft') api_err(400, 'Invalid status');
    $r = find_referral($id);
    if (!$r) api_err(404, 'Referral not found');
    if ($st === 'paid') with_lock('referrals.json', [], function (&$l) use ($id, $b) { foreach ($l as &$x) if ($x['id'] === $id) { $x['decision']['paidAt'] = now_iso(); $x['decision']['paidNote'] = clip($b['note'] ?? '', 300); } });
    set_referral_status($id, $st, $u, clip($b['note'] ?? '', 300));
    if ($st === 'more_info' && !empty($b['note'])) add_referral_message($u, $id, 'More information needed: ' . clip($b['note'], 2000));
    audit($u['username'], 'referral.status', $r['ref'] . ': ' . $r['status'] . ' → ' . $st);
    json_out(200, ['ok' => true]);
}
function add_referral_message(array $u, string $id, string $text): void {
    $side = is_partner($u) ? 'partner' : 'duc';
    $msg = ['id' => new_id(6), 'side' => $side, 'from' => $u['id'], 'fromName' => $u['name'] . ($side === 'duc' ? ' (D.U.C.)' : ''), 'enc' => enc($text), 'at' => now_iso(), 'read' => []];
    with_lock('referrals.json', [], function (&$l) use ($id, $msg) { foreach ($l as &$r) if ($r['id'] === $id) { $r['messages'][] = $msg; $r['updated'] = now_iso(); } });
    $r = find_referral($id);
    $tpl = letter_templates()['partner_message'];
    $f = ['reference' => $r['ref'] ?: 'draft', 'portal_link' => site_url() . '/portal#referral/' . $id];
    $to = $side === 'duc' ? array_filter([user_by_id($r['by'])]) : users_with_roles(['clerk']);
    foreach ($to as $p) if (user_email($p) && empty($p['disabled']))
        send_email(user_email($p), merge_fields($tpl['subject'], $f), '<p>Dear ' . h($p['name']) . ',</p>' . str_replace(h($f['portal_link']), email_button($f['portal_link'], 'Read the message'), text_to_html(merge_fields($tpl['body'], $f))), [], 'referral message');
}
function api_p_referral_message(string $id): void {
    $u = pguard('any');
    if (is_partner($u)) require_agreed($u);
    $r = find_referral($id);
    if (!$r || !referral_access($u, $r)) api_err(404, 'Referral not found');
    $t = clip(body_json()['text'] ?? '', 4000);
    if ($t === '') api_err(400, 'Write a message');
    add_referral_message($u, $id, $t);
    audit($u['username'], 'referral.message', ($r['ref'] ?: 'draft'));
    json_out(200, ['ok' => true]);
}
function api_p_referral_files(string $id, string $fid = ''): void {
    $u = pguard('any');
    if (is_partner($u)) require_agreed($u);
    $r = find_referral($id);
    if (!$r || !referral_access($u, $r)) api_err(404, 'Referral not found');
    $method = $_SERVER['REQUEST_METHOD'];
    if ($method === 'GET') {
        foreach ($r['files'] as $f) if ($f['id'] === $fid) {
            audit($u['username'], 'referral.file_downloaded', ($r['ref'] ?: 'draft') . ': ' . $f['name']);
            send_file(files_dir('evidence') . '/' . $f['path'], $f['name'], $f['type'], in_array($f['type'], ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'], true));
        }
        api_err(404, 'File not found');
    }
    $editable = is_partner($u) ? in_array($r['status'], ['draft', 'more_info'], true) : is_clerkish($u);
    if (!$editable) api_err(400, 'Evidence can no longer be changed');
    if ($method === 'DELETE') {
        $gone = null;
        with_lock('referrals.json', [], function (&$l) use ($id, $fid, &$gone) { foreach ($l as &$x) if ($x['id'] === $id) foreach ($x['files'] as $i => $f) if ($f['id'] === $fid) { $gone = $f; array_splice($x['files'], $i, 1); } });
        if (!$gone) api_err(404, 'File not found');
        @unlink(files_dir('evidence') . '/' . $gone['path']);
        audit($u['username'], 'referral.file_removed', ($r['ref'] ?: 'draft') . ': ' . $gone['name']);
        json_out(200, ['ok' => true]);
    }
    if (count($r['files']) >= 15) api_err(400, 'Up to 15 files per referral');
    $f = claim_upload($u, (string)(body_json()['uploadId'] ?? ''), 'evidence');
    if ($f['size'] > 10 * 1024 * 1024) { @unlink(files_dir('evidence') . '/' . $f['path']); api_err(400, 'Evidence files must be under 10 MB'); }
    with_lock('referrals.json', [], function (&$l) use ($id, $f) { foreach ($l as &$x) if ($x['id'] === $id) { $x['files'][] = $f; $x['updated'] = now_iso(); } });
    audit($u['username'], 'referral.file_added', ($r['ref'] ?: 'draft') . ': ' . $f['name']);
    json_out(200, ['id' => $f['id'], 'name' => $f['name'], 'size' => $f['size'], 'type' => $f['type'], 'at' => $f['at']]);
}

function handle_privacy_page(): void {
    $n = current_notice();
    $html = text_to_html($n['text']);
    render_page(200, 'Privacy notice', '<div style="text-align:left">' . $html . '<p class="small">Version ' . (int)$n['version'] . ' · ' . h(uk_date($n['at'])) . '</p></div>', true);
}

function partner_routes(string $id): array {
    $fid = '([a-f0-9]{20})';
    return [
        ['GET', '/api/p/orgs', 'api_p_orgs'], ['PUT', "/api/p/orgs/$id", 'api_p_org'],
        ['GET', '/api/p/org-users', 'api_p_org_users'], ['POST', '/api/p/org-users', 'api_p_org_users'], ['DELETE', "/api/p/org-users/$id", 'api_p_org_users'],
        ['POST', '/api/p/partner/agree', 'api_p_partner_agree'],
        ['*', '/api/p/referrals', 'api_p_referrals'], ['*', "/api/p/referrals/$id", 'api_p_referral'], ['POST', "/api/p/referrals/$id/submit", 'api_p_referral_submit'],
        ['PUT', "/api/p/referrals/$id/status", 'api_p_referral_status'], ['POST', "/api/p/referrals/$id/messages", 'api_p_referral_message'],
        ['POST', "/api/p/referrals/$id/files", 'api_p_referral_files'], ['*', "/api/p/referrals/$id/files/$fid", 'api_p_referral_files'],
    ];
}
