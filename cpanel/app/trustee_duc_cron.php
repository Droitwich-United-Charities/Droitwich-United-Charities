<?php
/**
 * Scheduled jobs. Set up ONE cron job in cPanel (Cron Jobs, every 15 minutes):
 *     /usr/local/bin/php /home/USER/public_html/index.php cron
 * or call https://your-site/cron?key=CRONKEY. As a fallback, light jobs also run
 * after ordinary page visits (at most every 15 minutes).
 */
declare(strict_types=1);

function run_cron(bool $heavy): array {
    $log = [];
    $state = read_json('cron.json', []);
    $lock = fopen(dpath('cron.lock'), 'c');
    if (!flock($lock, LOCK_EX | LOCK_NB)) return ['Another run is in progress'];
    try {
        // 1) email retries
        flush_outbox();
        $log[] = 'Email queue: ' . count(read_json('outbox.json', [])) . ' waiting';

        // 2) daily jobs (after 7am UK time)
        $today = date('Y-m-d');
        if (($state['daily'] ?? '') !== $today && (int)date('G') >= 7) {
            $state['daily'] = $today;
            write_json('cron.json', $state);
            $log[] = 'Action reminders: ' . action_reminders();
            $done = run_retention();
            $state['retention'] = now_iso();
            $log[] = 'Retention: ' . count($done) . ' records deleted or anonymised';
            $log[] = 'Retention warnings: ' . retention_warnings();
            foreach (glob(files_dir('tmp') . '/*') ?: [] as $f) if (filemtime($f) < time() - 3600 * (int)portal_settings()['retention']['upload_tmp_hours']) @unlink($f);
            foreach (glob(files_dir('sar') . '/*.zip') ?: [] as $f) if (filemtime($f) < time() - 30 * 86400) @unlink($f);
        }

        // 3) Gemini jobs (only from a real cron run – they can take several minutes)
        if ($heavy && ai_ready()) {
            foreach (read_json('meetings.json', []) as $m) {
                $r = $m['recording'] ?? [];
                $st = $r['status'] ?? '';
                $stuck = $st === 'processing' && strtotime($r['startedAt'] ?? '') < time() - 7200;
                $retry = $st === 'error' && ($r['tries'] ?? 0) < 4 && ($r['nextTry'] ?? 0) <= time();
                if (($st === 'uploaded' || $stuck || $retry) && !empty($r['file'])) {
                    try { process_meeting_recording($m['id']); $log[] = 'Transcribed: ' . $m['title']; }
                    catch (Throwable $e) { $log[] = 'Transcription failed for ' . $m['title'] . ': ' . $e->getMessage(); }
                    break; // one per run
                }
            }
        }
        $state = read_json('cron.json', []) + $state;
        $state['lastRun'] = time();
        if ($heavy) $state['lastRealCron'] = time();
        write_json('cron.json', $state);
    } finally {
        flock($lock, LOCK_UN); fclose($lock);
    }
    return $log;
}

function action_reminders(): int {
    $n = 0;
    $soon = date('Y-m-d', strtotime('+3 days'));
    $today = date('Y-m-d');
    $sendList = [];
    with_lock('actions.json', [], function (&$l) use ($soon, $today, &$sendList) {
        foreach ($l as &$a) {
            if (!$a['owner'] || !$a['due'] || $a['status'] === 'done') continue;
            if (empty($a['reminded']) && $a['due'] <= $soon && $a['due'] >= $today) { $a['reminded'] = true; $sendList[] = ['action_reminder', $a]; }
            elseif (empty($a['overdueSent']) && $a['due'] < $today) { $a['overdueSent'] = true; $a['reminded'] = true; $sendList[] = ['action_overdue', $a]; }
        }
    });
    $tpls = letter_templates();
    foreach ($sendList as [$key, $a]) {
        $p = user_by_id($a['owner']);
        if (!$p || !user_email($p) || !empty($p['disabled'])) continue;
        $tpl = $tpls[$key];
        $f = ['due_date' => uk_date($a['due']), 'action_text' => $a['text'], 'portal_link' => site_url() . '/portal#actions'];
        $html = '<p>Dear ' . h($p['name']) . ',</p>' . str_replace(h($f['portal_link']), email_button($f['portal_link'], 'Update my action'), text_to_html(merge_fields($tpl['body'], $f))) . signoff_html($tpl['sign']);
        send_email(user_email($p), merge_fields($tpl['subject'], $f), $html, [], $key);
        $n++;
    }
    return $n;
}

/** Email the Data Protection Lead about anything due for deletion in the next 14 days (once per item). */
function retention_warnings(): int {
    $due = array_filter(retention_due(time() + 14 * 86400), function ($d) { return $d['due'] > date('Y-m-d'); });
    $seen = read_json('retention-notified.json', []);
    $new = array_filter($due, function ($d) use ($seen) { return !isset($seen[$d['type'] . ':' . $d['id'] . ':' . $d['action']]); });
    if (!$new) return 0;
    $to = users_with_roles(['dplead']) ?: users_with_roles(['admin']);
    $rows = implode('', array_map(function ($d) { return '<li>' . h($d['due']) . ' – ' . h($d['label']) . ' (' . h(str_replace('_', ' ', $d['action'])) . ')' . ($d['hold'] ? ' <b>– on legal hold, will be kept</b>' : '') . '</li>'; }, $new));
    foreach ($to as $p) if (user_email($p))
        send_email(user_email($p), 'Records due for deletion in the next 14 days', '<p>Dear ' . h($p['name']) . ',</p><p>These records will be deleted or anonymised automatically under the D.U.C. retention schedule:</p><ul>' . $rows . '</ul><p>If any must be kept (for example because of a complaint or legal claim), put it on a legal hold in the portal.</p>' . email_button(site_url() . '/portal#gdpr/retention', 'Open the retention schedule'), [], 'retention warning');
    foreach ($new as $d) $seen[$d['type'] . ':' . $d['id'] . ':' . $d['action']] = now_iso();
    write_json('retention-notified.json', $seen);
    return count($new);
}

function handle_cron_web(): void {
    $key = (string)($_GET['key'] ?? '');
    if ($key === '' || !hash_equals(portal_settings()['cronKey'], $key)) { http_response_code(403); exit('Forbidden'); }
    @set_time_limit(0); @ignore_user_abort(true);
    header('Content-Type: text/plain; charset=utf-8');
    echo implode("\n", run_cron(true)) . "\n"; exit;
}

/** Light jobs after a normal request has been answered. */
function pseudo_cron(): void {
    $st = read_json('cron.json', []);
    if ((int)($st['lastRun'] ?? 0) > time() - 900) return;
    register_shutdown_function(function () {
        if (function_exists('fastcgi_finish_request')) @fastcgi_finish_request();
        elseif (function_exists('litespeed_finish_request')) @litespeed_finish_request();
        try { run_cron(false); } catch (Throwable $e) { }
    });
}
