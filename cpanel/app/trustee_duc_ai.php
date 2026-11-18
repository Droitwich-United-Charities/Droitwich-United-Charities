<?php
/**
 * AI providers for meeting notes – choose in Portal settings > AI:
 *   Transcription (audio -> text):   Gemini, or Whisper (Groq's free API, or your own Whisper server)
 *   Notes, actions and minutes:      Gemini, or Saltway AI (Ollama on the Saltway VPS)
 * Both are OpenAI/Ollama-standard HTTP APIs called from this server with curl.
 */
declare(strict_types=1);

function default_ai_settings(): array {
    return [
        'stt' => 'gemini',     // gemini | whisper
        'notes' => 'gemini',   // gemini | ollama
        'whisper' => ['base' => 'https://api.groq.com/openai/v1', 'key' => '', 'model' => 'whisper-large-v3-turbo'],
        'ollama' => ['base' => 'https://ai.saltwaydigital.co.uk/ollama', 'key' => '', 'model' => 'llama3.2:1b', 'ctx' => 32768, 'mode' => 'auto'],
    ];
}
function ai_settings(): array {
    $a = portal_settings()['ai'] ?? [];
    $d = default_ai_settings();
    $a = array_merge($d, is_array($a) ? $a : []);
    $a['whisper'] = array_merge($d['whisper'], (array)$a['whisper']);
    $a['ollama'] = array_merge($d['ollama'], (array)$a['ollama']);
    return $a;
}
/** Is everything needed to process a recording set up? */
function ai_ready(): bool {
    $a = ai_settings(); $g = portal_settings()['gemini']['key'] !== '';
    $stt = $a['stt'] === 'whisper' ? ($a['whisper']['base'] !== '' && $a['whisper']['model'] !== '') : $g;
    $notes = $a['notes'] === 'ollama' ? ($a['ollama']['base'] !== '' && $a['ollama']['model'] !== '') : $g;
    return $stt && $notes;
}
function ai_label(): string {
    $a = ai_settings();
    return ($a['stt'] === 'whisper' ? 'Whisper' : 'Gemini') . ' + ' . ($a['notes'] === 'ollama' ? 'Saltway AI' : 'Gemini');
}

function ai_http(string $url, $body, array $headers, int $timeout): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_HTTPHEADER => $headers, CURLOPT_POSTFIELDS => $body, CURLOPT_HEADER => true]);
    $res = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hsize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($res === false) throw new RuntimeException('Could not reach ' . parse_url($url, PHP_URL_HOST) . ': ' . $err);
    $hdr = substr((string)$res, 0, $hsize); $raw = substr((string)$res, $hsize);
    $retry = preg_match('/^retry-after:\s*(\d+)/mi', $hdr, $m) ? (int)$m[1] : 0;
    return [$code, $raw, $retry];
}

// ---------- Saltway AI (Ollama) ----------
/** Gemini-style schema (OBJECT/STRING…) to JSON Schema (object/string…) for Ollama. */
function schema_lower(array $s): array {
    foreach ($s as $k => $v) {
        if ($k === 'type' && is_string($v)) $s[$k] = strtolower($v);
        elseif (is_array($v)) $s[$k] = schema_lower($v);
    }
    return $s;
}
function ollama_chat(string $prompt, ?array $schema, string $system = '', float $temp = 0.2, int $timeout = 3600, int $ctx = 0): array {
    $o = ai_settings()['ollama'];
    if ($ctx) $o['ctx'] = $ctx;
    $body = ['model' => $o['model'], 'stream' => false, 'keep_alive' => '10m',
        'messages' => array_merge($system !== '' ? [['role' => 'system', 'content' => $system]] : [], [['role' => 'user', 'content' => $prompt]]),
        'options' => ['temperature' => $temp, 'num_ctx' => max(4096, (int)$o['ctx'])]];
    if ($schema) $body['format'] = schema_lower($schema);
    $h = ['Content-Type: application/json'];
    if ($o['key'] !== '') $h[] = 'Authorization: Bearer ' . $o['key'];
    [$code, $raw] = ai_http(rtrim($o['base'], '/') . '/api/chat', json_encode($body, JSON_UNESCAPED_UNICODE), $h, $timeout);
    $j = json_decode($raw, true);
    if ($code === 401 || $code === 403) throw new RuntimeException('Saltway AI refused the API key (' . $code . ')');
    if ($code === 404 && stripos($raw, 'model') !== false) throw new RuntimeException('Saltway AI does not have the model "' . $o['model'] . '" – run: ollama pull ' . $o['model']);
    if ($code >= 400 || !is_array($j)) throw new RuntimeException('Saltway AI error ' . $code . ': ' . mb_substr(trim(strip_tags($raw)), 0, 200));
    return [(string)($j['message']['content'] ?? ''), ['in' => (int)($j['prompt_eval_count'] ?? 0), 'out' => (int)($j['eval_count'] ?? 0)]];
}
function ollama_json(string $prompt, array $schema, string $system = '', int $ctx = 0): array {
    [$txt, $usage] = ollama_chat($prompt . "\n\nReply with JSON only, matching the requested structure.", $schema, $system, 0.2, 3600, $ctx);
    $j = json_decode($txt, true);
    if (!is_array($j) && preg_match('/\{.*\}/s', $txt, $m)) $j = json_decode($m[0], true);
    if (!is_array($j)) throw new RuntimeException('Saltway AI returned an unreadable answer');
    return [$j, $usage];
}

// ---------- Whisper (Groq or your own server) ----------
/** Split a WAV file into pieces of at most $secs seconds. Returns list of [tmpPath, startSeconds]. */
function wav_split(string $path, int $secs): array {
    $fh = fopen($path, 'rb');
    $head = fread($fh, 12);
    if (substr($head, 0, 4) !== 'RIFF' || substr($head, 8, 4) !== 'WAVE') { fclose($fh); return [[$path, 0, false]]; }
    $fmt = null; $dataOff = 0; $dataLen = 0;
    while (!feof($fh)) {
        $ch = fread($fh, 8); if (strlen($ch) < 8) break;
        $id = substr($ch, 0, 4); $len = unpack('V', substr($ch, 4))[1];
        if ($id === 'fmt ') { $fmt = fread($fh, $len); }
        elseif ($id === 'data') { $dataOff = ftell($fh); $dataLen = $len; break; }
        else fseek($fh, $len + ($len % 2), SEEK_CUR);
    }
    if (!$fmt || !$dataOff) { fclose($fh); return [[$path, 0, false]]; }
    $f = unpack('vfmt/vch/Vrate/Vbps/valign/vbits', $fmt);
    $bps = max(1, (int)$f['bps']);
    $dataLen = min($dataLen ?: PHP_INT_MAX, filesize($path) - $dataOff);
    $chunk = $secs * $bps; $chunk -= $chunk % max(1, (int)$f['align']);
    if ($dataLen <= $chunk) { fclose($fh); return [[$path, 0, false]]; }
    $out = [];
    for ($off = 0; $off < $dataLen; $off += $chunk) {
        $n = (int)min($chunk, $dataLen - $off);
        fseek($fh, $dataOff + $off);
        $tmp = tempnam(sys_get_temp_dir(), 'ducw') . '.wav';
        $o = fopen($tmp, 'wb');
        fwrite($o, 'RIFF' . pack('V', 36 + $n) . 'WAVE' . 'fmt ' . pack('V', 16) . substr($fmt, 0, 16) . 'data' . pack('V', $n));
        $left = $n; while ($left > 0) { $buf = fread($fh, (int)min(1048576, $left)); if ($buf === '' || $buf === false) break; fwrite($o, $buf); $left -= strlen($buf); }
        fclose($o);
        $out[] = [$tmp, (int)floor($off / $bps), true];
    }
    fclose($fh);
    return $out;
}
function whisper_transcribe(string $path, string $mime, string $hint, callable $setRec, int $dur): array {
    $w = ai_settings()['whisper'];
    $pieces = wav_split($path, 600); // 10-minute pieces keep each upload under 25 MB
    if (count($pieces) === 1 && filesize($path) > 24 * 1048576) throw new RuntimeException('This recording is too big for Whisper (25 MB limit). Use the room recorder, or upload a smaller MP3.');
    $segments = [];
    foreach ($pieces as $i => [$file, $start, $tmp]) {
        $setRec(['step' => 'Transcribing with Whisper' . (count($pieces) > 1 ? ' – part ' . ($i + 1) . ' of ' . count($pieces) : '') . ($dur ? ' (' . hms($dur) . ' of audio)' : '')]);
        $fields = ['file' => new CURLFile($file, $tmp ? 'audio/wav' : $mime, 'meeting-part-' . ($i + 1) . ($tmp ? '.wav' : '.' . pathinfo($path, PATHINFO_EXTENSION))),
            'model' => $w['model'], 'response_format' => 'verbose_json', 'language' => 'en', 'temperature' => '0', 'prompt' => mb_substr($hint, 0, 600)];
        $h = $w['key'] !== '' ? ['Authorization: Bearer ' . $w['key']] : [];
        for ($try = 0; ; $try++) {
            [$code, $raw, $retry] = ai_http(rtrim($w['base'], '/') . '/audio/transcriptions', $fields, $h, 1800);
            if ($code === 429 && $try < 12) { $setRec(['step' => 'Waiting for the free Whisper allowance (part ' . ($i + 1) . ')']); sleep(max(10, min(300, $retry ?: 60))); continue; }
            break;
        }
        if ($tmp) @unlink($file);
        $j = json_decode($raw, true);
        if ($code === 401) throw new RuntimeException('Whisper refused the API key');
        if ($code >= 400 || !is_array($j)) throw new RuntimeException('Whisper error ' . $code . ': ' . mb_substr(trim(strip_tags($raw)), 0, 200));
        // merge Whisper's short segments into blocks of about 30 seconds
        $block = null;
        foreach ($j['segments'] ?? [['start' => 0, 'text' => $j['text'] ?? '']] as $s) {
            $t = (int)floor($start + (float)($s['start'] ?? 0));
            $txt = trim((string)($s['text'] ?? ''));
            if ($txt === '') continue;
            if ($block && $t - $block['t'] < 30) $block['text'] .= ' ' . $txt;
            else { if ($block) $segments[] = $block; $block = ['t' => $t, 'speaker' => 'Speaker', 'text' => $txt]; }
        }
        if ($block) $segments[] = $block;
    }
    foreach ($segments as &$s) $s['text'] = clip($s['text'], 4000);
    return $segments;
}

/** Short free-text answer from whichever notes provider is chosen (used by "Ask" and tests). */
function ai_text(string $prompt, string $system = ''): string {
    if (ai_settings()['notes'] === 'ollama') return trim(ollama_chat($prompt, null, $system, 0.2, 600)[0]);
    return gemini_text(portal_settings()['gemini']['fastModel'], $prompt, $system);
}

/** Test the chosen providers. Returns list of [what, ok, message]. */
function ai_test(): array {
    $a = ai_settings(); $out = [];
    try {
        $t = ai_text('Reply with exactly: AI is working for D.U.C.');
        $out[] = ['Notes (' . ($a['notes'] === 'ollama' ? 'Saltway AI – ' . $a['ollama']['model'] : 'Gemini') . ')', true, $t];
    } catch (Throwable $e) { $out[] = ['Notes (' . ($a['notes'] === 'ollama' ? 'Saltway AI' : 'Gemini') . ')', false, $e->getMessage()]; }
    if ($a['stt'] === 'whisper') {
        try {
            $tmp = tempnam(sys_get_temp_dir(), 'duct') . '.wav'; $n = 16000; // one second of silence
            file_put_contents($tmp, 'RIFF' . pack('V', 36 + $n * 2) . 'WAVE' . 'fmt ' . pack('VvvVVvv', 16, 1, 1, 16000, 32000, 2, 16) . 'data' . pack('V', $n * 2) . str_repeat("\0", $n * 2));
            $w = $a['whisper'];
            [$code, $raw] = ai_http(rtrim($w['base'], '/') . '/audio/transcriptions', ['file' => new CURLFile($tmp, 'audio/wav', 'test.wav'), 'model' => $w['model'], 'response_format' => 'json'],
                $w['key'] !== '' ? ['Authorization: Bearer ' . $w['key']] : [], 120);
            @unlink($tmp);
            $out[] = ['Transcription (Whisper – ' . $w['model'] . ')', $code < 400, $code < 400 ? 'Connected' : 'Error ' . $code . ': ' . mb_substr(trim(strip_tags($raw)), 0, 160)];
        } catch (Throwable $e) { $out[] = ['Transcription (Whisper)', false, $e->getMessage()]; }
    } elseif ($a['notes'] === 'ollama') {
        try { gemini_text(portal_settings()['gemini']['fastModel'], 'Reply with: ok'); $out[] = ['Transcription (Gemini)', true, 'Connected']; }
        catch (Throwable $e) { $out[] = ['Transcription (Gemini)', false, $e->getMessage()]; }
    }
    return $out;
}

// ---------- Small models: work through the meeting a section at a time ----------
/** Small models (1B–4B) lose track of long transcripts, so give them one short section at a time. */
function ollama_use_sections(string $tx): bool {
    $o = ai_settings()['ollama'];
    if ($o['mode'] === 'sections') return true;
    if ($o['mode'] === 'whole') return false;
    if (preg_match('/[:\-_](0\.5|1|1\.5|1\.8|2|3|3\.8|4)b\b/i', $o['model'])) return true;
    return mb_strlen($tx) / 3.5 > $o['ctx'] * 0.6; // too long for the context window
}
/** Split transcript lines into sections: one per agenda item when the Clerk marked items, else ~5,000 characters. */
function transcript_sections(array $lines, array $agenda, array $markers): array {
    $bounds = [];
    foreach ($markers as $mk) if (($mk['type'] ?? 'item') === 'item' && !empty($mk['itemId'])) $bounds[] = [(int)$mk['t'], $mk['itemId']];
    usort($bounds, function ($a, $b) { return $a[0] <=> $b[0]; });
    $titles = []; foreach ($agenda as $a) $titles[$a['id']] = $a['title'];
    $out = []; $cur = null;
    foreach ($lines as [$t, $line]) {
        $item = '';
        foreach ($bounds as [$bt, $bid]) if ($t >= $bt) $item = $bid;
        if (!$cur || $cur['itemId'] !== $item || mb_strlen($cur['text']) > 5000) {
            if ($cur) $out[] = $cur;
            $cur = ['itemId' => $item, 'title' => $titles[$item] ?? '', 'from' => $t, 'text' => ''];
        }
        $cur['text'] .= $line . "\n"; $cur['to'] = $t;
    }
    if ($cur) $out[] = $cur;
    return $out;
}
/** Find a trustee from a name the model wrote ("Janet", "Mrs Smith"). */
function match_trustee(string $name): string {
    $name = strtolower(trim($name)); if ($name === '') return '';
    foreach (users_with_roles(BOARD_ROLES) as $u) {
        $full = strtolower($u['name']);
        if ($full === $name) return $u['id'];
        foreach (preg_split('/\s+/', $full) as $part) if (strlen($part) > 2 && preg_match('/\b' . preg_quote($part, '/') . '\b/', $name)) return $u['id'];
    }
    return '';
}
/** Build the same drafts structure as the one-shot method, section by section. */
function ollama_sections_drafts(array $m, array $lines, array $markers, string $context, callable $setRec, array &$usage): array {
    $secs = transcript_sections($lines, $m['agenda'], $markers);
    $schema = ['type' => 'OBJECT', 'properties' => [
        'summary' => ['type' => 'STRING'],
        'decisions' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']],
        'actions' => ['type' => 'ARRAY', 'items' => ['type' => 'OBJECT', 'properties' => ['task' => ['type' => 'STRING'], 'who' => ['type' => 'STRING'], 'due' => ['type' => 'STRING']], 'required' => ['task']]],
        'questions' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']]], 'required' => ['summary', 'decisions', 'actions', 'questions']];
    $d = ['items' => [], 'decisions' => [], 'actions' => [], 'questions' => [], 'minutes' => ''];
    $byItem = [];
    foreach ($secs as $i => $sec) {
        $setRec(['step' => 'Saltway AI is reading part ' . ($i + 1) . ' of ' . count($secs)]);
        $p = $context . "\n\nThis is one part of the meeting (" . hms($sec['from']) . '–' . hms($sec['to']) . ')' . ($sec['title'] ? ', about the agenda item "' . $sec['title'] . '"' : '') . ".\n\n"
            . "PART OF TRANSCRIPT:\n" . $sec['text'] . "\n\nFrom this part only, write:\n"
            . "- summary: 2 to 4 plain sentences of what was discussed\n- decisions: things that were clearly agreed or voted on (empty list if none)\n"
            . "- actions: tasks someone agreed to do, with who (their name, or empty) and due (YYYY-MM-DD only if a date was said, else empty)\n"
            . "- questions: things left unanswered (empty list if none)\nDo not make anything up. If nothing important happened, keep the summary short.";
        try { [$j, $u] = ollama_json($p, $schema, AI_SYSTEM, 8192); }
        catch (RuntimeException $e) { if (strpos($e->getMessage(), 'unreadable') === false) throw $e; [$j, $u] = ollama_json($p, $schema, AI_SYSTEM, 8192); } // small models sometimes need a second go
        $usage['in'] += $u['in']; $usage['out'] += $u['out'];
        $sum = trim((string)($j['summary'] ?? ''));
        if ($sum !== '') $byItem[$sec['itemId']][] = $sum;
        foreach ((array)($j['decisions'] ?? []) as $x) if (is_string($x) && trim($x) !== '') $d['decisions'][] = ['itemId' => $sec['itemId'], 'text' => trim($x), 'start' => hms($sec['from'])];
        foreach ((array)($j['actions'] ?? []) as $x) if (is_array($x) && trim((string)($x['task'] ?? '')) !== '')
            $d['actions'][] = ['text' => trim($x['task']), 'ownerId' => match_trustee((string)($x['who'] ?? '')), 'ownerName' => trim((string)($x['who'] ?? '')), 'due' => (string)($x['due'] ?? ''), 'itemId' => $sec['itemId'], 'start' => hms($sec['from'])];
        foreach ((array)($j['questions'] ?? []) as $x) if (is_string($x) && trim($x) !== '') $d['questions'][] = trim($x);
    }
    // put it together: one summary per agenda item, and minutes in agenda order
    $titles = []; foreach ($m['agenda'] as $a) $titles[$a['id']] = $a['title'];
    $min = [];
    foreach (array_merge(array_keys($titles), ['']) as $id) {
        if (!empty($titles[$id]) && !empty($m['agenda'][array_search($id, array_column($m['agenda'], 'id'))]['confidential'])) { $min[] = '## ' . $titles[$id] . "\n\nConfidential item – discussed but not recorded."; continue; }
        if (empty($byItem[$id])) continue;
        $text = implode(' ', $byItem[$id]);
        if ($id !== '') $d['items'][] = ['itemId' => $id, 'summary' => $text];
        $sec = '## ' . ($id !== '' ? $titles[$id] : (count($byItem) > 1 ? 'Other discussion' : 'Discussion')) . "\n\n" . $text;
        foreach ($d['decisions'] as $x) if ($x['itemId'] === $id) $sec .= "\n- Decision: " . $x['text'];
        foreach ($d['actions'] as $x) if ($x['itemId'] === $id) $sec .= "\n- Action: " . $x['text'] . ($x['ownerName'] ? ' (' . $x['ownerName'] . ')' : '') . ($x['due'] ? ' by ' . $x['due'] : '');
        $min[] = $sec;
    }
    $d['minutes'] = implode("\n\n", $min);
    $d['questions'] = array_values(array_unique($d['questions']));
    return $d;
}
