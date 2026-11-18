<?php
/**
 * Google Gemini API: meeting transcription and AI drafts (summaries, decisions, actions, minutes).
 * Uses the Files API for audio, then generateContent with JSON output.
 * The API key stays on the server. Audio is deleted from Google when the job finishes.
 */
declare(strict_types=1);

define('GEMINI_BASE', getenv('DUC_GEMINI_BASE') ?: 'https://generativelanguage.googleapis.com');

function gemini_key(): string {
    $k = (string)portal_settings()['gemini']['key'];
    if ($k === '') throw new RuntimeException('Gemini is not set up yet (Portal settings > Gemini)');
    return $k;
}

function gemini_http(string $method, string $url, $body = null, array $headers = [], int $timeout = 600, ?array &$respHeaders = null): array {
    $ch = curl_init($url);
    $respHeaders = [];
    $h = array_merge(['x-goog-api-key: ' . gemini_key()], $headers);
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_HTTPHEADER => $h, CURLOPT_HEADERFUNCTION => function ($c, $line) use (&$respHeaders) {
            $p = strpos($line, ':'); if ($p) $respHeaders[strtolower(trim(substr($line, 0, $p)))] = trim(substr($line, $p + 1)); return strlen($line);
        }]);
    if (is_resource($body)) {
        curl_setopt($ch, CURLOPT_UPLOAD, true);
        curl_setopt($ch, CURLOPT_INFILE, $body);
        curl_setopt($ch, CURLOPT_INFILESIZE, (int)fstat($body)['size']);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    } elseif ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, is_string($body) ? $body : json_encode($body, JSON_UNESCAPED_UNICODE));
    }
    $res = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($res === false) throw new RuntimeException('Could not reach Gemini: ' . $err);
    $json = json_decode((string)$res, true);
    if ($code >= 400) throw new RuntimeException('Gemini error ' . $code . ': ' . ($json['error']['message'] ?? substr((string)$res, 0, 300)));
    return is_array($json) ? $json : [];
}

/** Upload a local file with the resumable protocol. Returns the file resource (name, uri, mimeType). */
function gemini_upload(string $path, string $mime, string $display): array {
    $size = filesize($path);
    $hdrs = [];
    gemini_http('POST', GEMINI_BASE . '/upload/v1beta/files', ['file' => ['display_name' => $display]], [
        'X-Goog-Upload-Protocol: resumable', 'X-Goog-Upload-Command: start',
        'X-Goog-Upload-Header-Content-Length: ' . $size, 'X-Goog-Upload-Header-Content-Type: ' . $mime, 'Content-Type: application/json'], 60, $hdrs);
    $url = $hdrs['x-goog-upload-url'] ?? '';
    if ($url === '') throw new RuntimeException('Gemini did not return an upload address');
    $fh = fopen($path, 'rb');
    $res = gemini_http('POST', $url, $fh, ['X-Goog-Upload-Offset: 0', 'X-Goog-Upload-Command: upload, finalize', 'Content-Length: ' . $size], 1800);
    fclose($fh);
    $file = $res['file'] ?? null;
    if (!$file) throw new RuntimeException('Gemini upload failed');
    for ($i = 0; $i < 60 && ($file['state'] ?? 'ACTIVE') === 'PROCESSING'; $i++) {
        sleep(5);
        $file = gemini_http('GET', GEMINI_BASE . '/v1beta/' . $file['name'], null, [], 30);
    }
    if (($file['state'] ?? 'ACTIVE') !== 'ACTIVE') throw new RuntimeException('Gemini could not read the audio file (' . ($file['state'] ?? '?') . ')');
    return $file;
}
function gemini_delete_file(string $name): void {
    try { gemini_http('DELETE', GEMINI_BASE . '/v1beta/' . $name, null, [], 30); } catch (Throwable $e) { /* it expires after 48h anyway */ }
}

/** generateContent with JSON output. Returns [decodedJson, finishReason, usage]. */
function gemini_json(string $model, array $parts, array $schema, string $system = '', float $temp = 0.2): array {
    $body = ['contents' => [['role' => 'user', 'parts' => $parts]],
        'generationConfig' => ['responseMimeType' => 'application/json', 'responseSchema' => $schema, 'temperature' => $temp, 'maxOutputTokens' => 65536]];
    if ($system !== '') $body['systemInstruction'] = ['parts' => [['text' => $system]]];
    $res = gemini_http('POST', GEMINI_BASE . '/v1beta/models/' . rawurlencode($model) . ':generateContent', $body, ['Content-Type: application/json'], 900);
    $cand = $res['candidates'][0] ?? [];
    $text = '';
    foreach ($cand['content']['parts'] ?? [] as $p) $text .= $p['text'] ?? '';
    $finish = $cand['finishReason'] ?? '';
    $json = json_decode($text, true);
    if (!is_array($json) && $finish === 'MAX_TOKENS') {
        // salvage a cut-off segments array
        $cut = strrpos($text, '},');
        $json = $cut ? json_decode(substr($text, 0, $cut + 1) . ']}', true) : null;
    }
    if (!is_array($json)) throw new RuntimeException('Gemini returned an unreadable answer' . ($finish ? " ($finish)" : ''));
    return [$json, $finish, $res['usageMetadata'] ?? []];
}
function gemini_text(string $model, string $prompt, string $system = ''): string {
    $body = ['contents' => [['role' => 'user', 'parts' => [['text' => $prompt]]]], 'generationConfig' => ['temperature' => 0.2]];
    if ($system !== '') $body['systemInstruction'] = ['parts' => [['text' => $system]]];
    $res = gemini_http('POST', GEMINI_BASE . '/v1beta/models/' . rawurlencode($model) . ':generateContent', $body, ['Content-Type: application/json'], 300);
    $t = '';
    foreach ($res['candidates'][0]['content']['parts'] ?? [] as $p) $t .= $p['text'] ?? '';
    return trim($t);
}

function hms(int $s): string { return sprintf('%02d:%02d:%02d', intdiv($s, 3600), intdiv($s % 3600, 60), $s % 60); }
function hms_to_s(string $t): int {
    $p = array_map('intval', explode(':', trim($t)));
    if (count($p) === 3) return $p[0] * 3600 + $p[1] * 60 + $p[2];
    if (count($p) === 2) return $p[0] * 60 + $p[1];
    return (int)($p[0] ?? 0);
}

const AI_SYSTEM = "You help the Clerk of Droitwich United Charities (D.U.C.), a small local grant-giving charity in Droitwich Spa run by volunteer trustees. Write in plain, warm British English. Never invent facts. Refer to people asking for help only by their reference number (for example DUC-R-2026-0042) or as 'the applicant' – never write their names, addresses or other personal details.";

/** Full pipeline for one meeting. Saves progress to meetings.json as it goes. */
function process_meeting_recording(string $meetingId): void {
    @set_time_limit(0);
    @ignore_user_abort(true);
    $m = find_meeting($meetingId);
    if (!$m || empty($m['recording']['file'])) throw new RuntimeException('No recording for this meeting');
    $g = portal_settings()['gemini'];
    $rec = $m['recording'];
    $path = files_dir('recordings') . '/' . $rec['file'];
    $fileName = null;
    $usage = ['in' => 0, 'out' => 0];
    $addUsage = function ($u) use (&$usage) { $usage['in'] += (int)($u['promptTokenCount'] ?? 0); $usage['out'] += (int)($u['candidatesTokenCount'] ?? 0); };
    $setRec = function (array $patch) use ($meetingId) { update_meeting($meetingId, function (&$m) use ($patch) { $m['recording'] = array_merge($m['recording'], $patch); }); };
    try {
        $setRec(['status' => 'processing', 'step' => 'Uploading audio to Gemini', 'error' => '', 'startedAt' => now_iso()]);
        $file = gemini_upload($path, $rec['mime'], 'DUC meeting ' . $m['id']);
        $fileName = $file['name'];
        $people = [];
        foreach ($m['attendance'] ?? [] as $uid => $st) if ($st === 'attending') $people[] = user_name($uid);
        $agenda = implode("\n", array_map(function ($a, $i) { return ($i + 1) . '. ' . $a['title'] . (!empty($a['confidential']) ? ' (confidential – not recorded)' : ''); }, $m['agenda'], array_keys($m['agenda'])));
        $markers = implode("\n", array_map(function ($mk) use ($m) {
            $t = ''; foreach ($m['agenda'] as $a) if ($a['id'] === $mk['itemId']) $t = $a['title'];
            return hms((int)$mk['t']) . ' – ' . ($mk['type'] ?? 'item') . ': ' . $t;
        }, $rec['markers'] ?? []));

        // 1) transcript, in windows so long meetings fit the output limit
        $segSchema = ['type' => 'OBJECT', 'properties' => ['segments' => ['type' => 'ARRAY', 'items' => ['type' => 'OBJECT',
            'properties' => ['start' => ['type' => 'STRING', 'description' => 'HH:MM:SS from the start of the audio'], 'speaker' => ['type' => 'STRING'], 'text' => ['type' => 'STRING']],
            'required' => ['start', 'speaker', 'text']]]], 'required' => ['segments']];
        $segments = [];
        $dur = (int)($rec['duration'] ?? 0);
        $from = 0; $win = 2400;
        for ($pass = 0; $pass < 16; $pass++) {
            $to = $dur > 0 ? min($dur, $from + $win) : 0;
            $setRec(['step' => 'Transcribing' . ($dur ? ' ' . hms($from) . '–' . hms($to) . ' of ' . hms($dur) : '')]);
            $prompt = "Transcribe this meeting recording of D.U.C. trustees word for word in British English.\n"
                . "Label speakers consistently as 'Speaker 1', 'Speaker 2' and so on across the whole recording" . ($segments ? ' (keep the same labels as earlier: ' . implode(', ', array_unique(array_column($segments, 'speaker'))) . ')' : '') . ".\n"
                . "If a speaker clearly says their own name or is addressed by name, still use the Speaker N label.\n"
                . "Start a new segment at every change of speaker, and at least every 30 seconds.\n"
                . 'Only transcribe from ' . hms($from) . ($to ? ' to ' . hms($to) : ' to the end') . ". Timestamps are measured from the very start of the audio.\n"
                . ($people ? "\nPeople expected: " . implode(', ', $people) : '') . "\nAgenda:\n" . $agenda;
            [$j, $finish, $u] = gemini_json($g['fastModel'], [['file_data' => ['mime_type' => $rec['mime'], 'file_uri' => $file['uri']]], ['text' => $prompt]], $segSchema, AI_SYSTEM, 0.1);
            $addUsage($u);
            $got = array_values(array_filter($j['segments'] ?? [], function ($s) use ($from) { return hms_to_s((string)$s['start']) >= $from - 2 && trim((string)$s['text']) !== ''; }));
            foreach ($got as $s) $segments[] = ['t' => hms_to_s((string)$s['start']), 'speaker' => clip($s['speaker'], 40), 'text' => clip($s['text'], 4000)];
            $last = $got ? hms_to_s((string)end($got)['start']) : $from;
            if ($finish === 'MAX_TOKENS' && $last > $from) { $from = $last + 1; continue; }
            if ($dur > 0 && $to < $dur) { $from = $to; continue; }
            break;
        }
        if (!$segments) throw new RuntimeException('Gemini found no speech in the recording');
        usort($segments, function ($a, $b) { return $a['t'] <=> $b['t']; });
        update_meeting($meetingId, function (&$m) use ($segments) {
            $old = $m['transcript']['speakers'] ?? [];
            $m['transcript'] = ['segments' => $segments, 'speakers' => $old, 'at' => now_iso()];
        });

        // 2) drafts: per-item summaries, decisions, actions, questions, minutes
        $setRec(['step' => 'Writing summary, decisions and actions']);
        $m = find_meeting($meetingId);
        $map = $m['transcript']['speakers'] ?? [];
        $tx = implode("\n", array_map(function ($s) use ($map) { $who = isset($map[$s['speaker']]) ? user_name($map[$s['speaker']]) : $s['speaker']; return '[' . hms($s['t']) . '] ' . $who . ': ' . $s['text']; }, $segments));
        $items = implode("\n", array_map(function ($a) { return '- id ' . $a['id'] . ': ' . $a['title'] . (!empty($a['confidential']) ? ' (confidential, not recorded)' : ''); }, $m['agenda']));
        $trustees = implode(', ', array_map(function ($u) { return $u['name'] . ' (id ' . $u['id'] . ')'; }, users_with_roles(BOARD_ROLES)));
        $draftSchema = ['type' => 'OBJECT', 'properties' => [
            'items' => ['type' => 'ARRAY', 'items' => ['type' => 'OBJECT', 'properties' => ['itemId' => ['type' => 'STRING'], 'summary' => ['type' => 'STRING']], 'required' => ['itemId', 'summary']]],
            'decisions' => ['type' => 'ARRAY', 'items' => ['type' => 'OBJECT', 'properties' => ['itemId' => ['type' => 'STRING'], 'text' => ['type' => 'STRING'], 'start' => ['type' => 'STRING']], 'required' => ['text']]],
            'actions' => ['type' => 'ARRAY', 'items' => ['type' => 'OBJECT', 'properties' => [
                'text' => ['type' => 'STRING'], 'ownerId' => ['type' => 'STRING', 'description' => 'id of the trustee responsible, or empty'],
                'ownerName' => ['type' => 'STRING'], 'due' => ['type' => 'STRING', 'description' => 'YYYY-MM-DD if a date was agreed, else empty'],
                'itemId' => ['type' => 'STRING'], 'start' => ['type' => 'STRING', 'description' => 'HH:MM:SS when it was agreed']], 'required' => ['text']]],
            'questions' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']],
            'minutes' => ['type' => 'STRING', 'description' => 'Draft formal minutes in plain text. Use "## " headings for each agenda item, short paragraphs, and "- " bullets for decisions.']],
            'required' => ['items', 'decisions', 'actions', 'questions', 'minutes']];
        $prompt = "Here is the transcript of a D.U.C. trustee meeting.\n\nMeeting: {$m['title']}, " . uk_date($m['date']) . ($m['venue'] ? ", {$m['venue']}" : '')
            . "\nPresent: " . (implode(', ', $people) ?: 'not recorded')
            . "\n\nAgenda items (use these ids):\n$items\n\nTrustees and officers (use these ids for action owners):\n$trustees"
            . ($markers ? "\n\nThe Clerk marked these moments in the recording:\n$markers" : '')
            . "\n\nToday's date is " . date('Y-m-d') . ". Turn relative dates like 'by next meeting' into YYYY-MM-DD only if the date is clear; otherwise leave due empty."
            . "\n\nWrite: a short summary for each agenda item; every decision made; every action agreed (what, who, by when, and when it was agreed); any open questions; and draft formal minutes."
            . "\n\nTRANSCRIPT:\n$tx";
        [$d, , $u] = gemini_json($g['proModel'], [['text' => $prompt]], $draftSchema, AI_SYSTEM, 0.2);
        $addUsage($u);
        $validUsers = array_column(users_with_roles(BOARD_ROLES), 'id');
        $drafts = [
            'items' => array_map(function ($i) { return ['itemId' => (string)($i['itemId'] ?? ''), 'summary' => clip($i['summary'] ?? '', 4000)]; }, $d['items'] ?? []),
            'decisions' => array_map(function ($i) { return ['itemId' => (string)($i['itemId'] ?? ''), 'text' => clip($i['text'] ?? '', 2000), 't' => hms_to_s((string)($i['start'] ?? '0'))]; }, $d['decisions'] ?? []),
            'actions' => array_map(function ($a) use ($validUsers) {
                $due = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($a['due'] ?? '')) ? $a['due'] : '';
                return ['id' => new_id(6), 'text' => clip($a['text'] ?? '', 1000), 'owner' => in_array($a['ownerId'] ?? '', $validUsers, true) ? $a['ownerId'] : '',
                    'ownerName' => clip($a['ownerName'] ?? '', 120), 'due' => $due, 'itemId' => (string)($a['itemId'] ?? ''), 't' => hms_to_s((string)($a['start'] ?? '0'))];
            }, $d['actions'] ?? []),
            'questions' => array_map(function ($q) { return clip($q, 1000); }, $d['questions'] ?? []),
            'minutes' => clip($d['minutes'] ?? '', 60000),
            'at' => now_iso(), 'model' => $g['proModel'],
        ];
        update_meeting($meetingId, function (&$m) use ($drafts, $usage) {
            $m['drafts'] = $drafts;
            $m['recording']['status'] = 'ready'; $m['recording']['step'] = ''; $m['recording']['tokens'] = $usage; $m['recording']['doneAt'] = now_iso();
        });
        audit('(system)', 'meeting.ai_ready', "Transcript and drafts ready for {$m['title']} (" . number_format($usage['in']) . ' tokens in, ' . number_format($usage['out']) . ' out)');
        foreach (users_with_roles(['clerk']) as $c) if (user_email($c)) send_email(user_email($c), 'Meeting notes ready to review – ' . $m['title'],
            '<p>The transcript, summary and actions for <b>' . h($m['title']) . '</b> are ready for you to review.</p>' . email_button(site_url() . '/portal#meeting/' . $m['id'], 'Review now'));
    } catch (Throwable $e) {
        $setRec(['status' => 'error', 'step' => '', 'error' => $e->getMessage(), 'tries' => ($rec['tries'] ?? 0) + 1, 'nextTry' => time() + 3600]);
        audit('(system)', 'meeting.ai_failed', $m['title'] . ': ' . $e->getMessage());
        if ($fileName) gemini_delete_file($fileName);
        throw $e;
    }
    if ($fileName) gemini_delete_file($fileName);
}
