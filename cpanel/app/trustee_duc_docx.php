<?php
/**
 * Letters and documents built on the D.U.C. letterhead (app/templates/letterhead.docx).
 * The header (logo + charity line) and footer (Clerk details) come from the template;
 * only the body is replaced. Body text: Arial 11pt navy; subject: Georgia bold navy.
 * Also: .ics calendar invitations and merge-field filling.
 */
declare(strict_types=1);

const NAVY = '0B1C3D';
const GREEN = '2F5E37';

function x(string $s): string { return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8'); }

/** One paragraph. $runs: list of [text, ['b'=>bool,'i'=>bool,'font'=>..,'color'=>..,'size'=>half-points]] or a plain string. */
function w_para($runs, array $p = []): string {
    if (is_string($runs)) $runs = [[$runs, []]];
    $after = $p['after'] ?? 160; $before = $p['before'] ?? 0;
    $ppr = '<w:pPr>' . (!empty($p['ind']) ? '<w:ind w:left="' . (int)$p['ind'] . '" w:hanging="' . (int)($p['hang'] ?? 0) . '"/>' : '')
        . '<w:spacing w:after="' . $after . '" w:before="' . $before . '" w:line="288"/>'
        . (!empty($p['border']) ? '<w:pBdr><w:bottom w:val="single" w:sz="12" w:space="4" w:color="' . GREEN . '"/></w:pBdr>' : '')
        . (!empty($p['keep']) ? '<w:keepNext/>' : '') . '</w:pPr>';
    $out = '';
    foreach ($runs as [$t, $o]) {
        $font = $o['font'] ?? 'Arial';
        $rpr = '<w:rPr><w:rFonts w:ascii="' . $font . '" w:cs="' . $font . '" w:eastAsia="' . $font . '" w:hAnsi="' . $font . '"/>'
            . (!empty($o['b']) ? '<w:b/><w:bCs/>' : '') . (!empty($o['i']) ? '<w:i/><w:iCs/>' : '')
            . '<w:color w:val="' . ($o['color'] ?? NAVY) . '"/><w:sz w:val="' . ($o['size'] ?? 22) . '"/><w:szCs w:val="' . ($o['size'] ?? 22) . '"/></w:rPr>';
        $lines = explode("\n", (string)$t);
        foreach ($lines as $i => $line) {
            $out .= '<w:r>' . $rpr . ($i > 0 ? '<w:br/>' : '') . '<w:t xml:space="preserve">' . x($line) . '</w:t></w:r>';
        }
    }
    return '<w:p>' . $ppr . $out . '</w:p>';
}

/** Turn simple text (blank line = new paragraph, "- " = bullet, **bold**) into paragraphs. */
function w_text_blocks(string $text): string {
    $out = '';
    $blocks = preg_split("/\n\s*\n/", str_replace("\r", '', trim($text)));
    foreach ($blocks as $b) {
        if ($b === '') continue;
        $lines = explode("\n", $b);
        $isList = count(array_filter($lines, function ($l) { return preg_match('/^\s*[-•]\s+/', $l); })) === count($lines);
        if ($isList) {
            foreach ($lines as $i => $l) $out .= w_para(w_inline('•  ' . preg_replace('/^\s*[-•]\s+/', '', $l)), ['ind' => 400, 'hang' => 240, 'after' => $i === count($lines) - 1 ? 160 : 40]);
        } elseif (preg_match('/^#{1,3}\s+(.*)$/s', $b, $m)) {
            $out .= w_para([[trim($m[1]), ['font' => 'Georgia', 'b' => true, 'size' => 26]]], ['before' => 200, 'after' => 100, 'keep' => true]);
        } else {
            $out .= w_para(w_inline($b));
        }
    }
    return $out;
}
function w_inline(string $s): array {
    $parts = preg_split('/(\*\*[^*]+\*\*)/', $s, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
    return array_map(function ($p) { return preg_match('/^\*\*(.+)\*\*$/s', $p, $m) ? [$m[1], ['b' => true]] : [$p, []]; }, $parts ?: ['']);
}

/** Build a .docx from the letterhead with the given body XML. Returns bytes. */
function docx_from_body(string $bodyXml, string $title = 'Letter'): string {
    $tpl = APP_DIR . '/templates/letterhead.docx';
    $tmp = tempnam(sys_get_temp_dir(), 'duc');
    copy($tpl, $tmp);
    $z = new ZipArchive();
    if ($z->open($tmp) !== true) throw new RuntimeException('Letterhead template missing');
    $doc = $z->getFromName('word/document.xml');
    $start = strpos($doc, '<w:body>') + strlen('<w:body>');
    $sect = strpos($doc, '<w:sectPr');
    $doc = substr($doc, 0, $start) . $bodyXml . substr($doc, $sect);
    $z->addFromString('word/document.xml', $doc);
    $core = $z->getFromName('docProps/core.xml');
    if ($core !== false) {
        $core = preg_replace('#<dc:title>.*?</dc:title>#s', '<dc:title>' . x($title) . '</dc:title>', $core);
        $z->addFromString('docProps/core.xml', $core);
    }
    $z->close();
    $bytes = (string)file_get_contents($tmp);
    @unlink($tmp);
    return $bytes;
}

/** A letter: address block, date, salutation, subject, body, sign-off. */
function build_letter_docx(array $f, string $subject, string $body, array $sign): string {
    $x = '';
    $addr = array_values(array_filter([$f['recipient_name'] ?? '', $f['organisation'] ?? '', $f['address_1'] ?? '', $f['town_postcode'] ?? ''], 'strlen'));
    foreach ($addr as $i => $l) $x .= w_para($l, ['after' => $i === count($addr) - 1 ? 280 : 0]);
    $x .= w_para($f['date'] ?? uk_date(), ['after' => 280]);
    $x .= w_para('Dear ' . ($f['salutation'] ?? 'Sir or Madam') . ',');
    $x .= w_para([[$subject, ['font' => 'Georgia', 'b' => true, 'size' => 24]]], ['after' => 200]);
    $x .= w_text_blocks($body);
    $x .= w_para($sign['closing'] ?? 'Yours sincerely,', ['before' => 120, 'after' => 720]);
    $x .= w_para([[$sign['name'], ['b' => true]]], ['after' => 0]);
    $x .= w_para($sign['role'], ['after' => 0]);
    if (!empty($sign['contact'])) $x .= w_para([[$sign['contact'], ['color' => '5A6475']]], ['after' => 0]);
    if (!empty($f['enclosures'])) $x .= w_para('Enc. ' . $f['enclosures'], ['before' => 280]);
    return docx_from_body($x, $subject);
}

/** A document (e.g. minutes): title, subtitle, then text blocks. */
function build_document_docx(string $title, string $subtitle, string $text): string {
    $x = w_para([[$title, ['font' => 'Georgia', 'b' => true, 'size' => 36]]], ['after' => 60]);
    if ($subtitle !== '') $x .= w_para([[$subtitle, ['font' => 'Georgia', 'i' => true, 'color' => GREEN, 'size' => 24]]], ['after' => 240, 'border' => true]);
    $x .= w_text_blocks($text);
    return docx_from_body($x, $title);
}

function uk_date(?string $iso = null): string {
    $t = $iso ? strtotime($iso) : time();
    return date('j F Y', $t ?: time());
}

/** Replace {fields}. Unknown fields are left visible as [field] so the Clerk notices them. */
function merge_fields(string $s, array $f): string {
    return preg_replace_callback('/\{([a-z0-9_]+)\}/', function ($m) use ($f) {
        return array_key_exists($m[1], $f) && $f[$m[1]] !== '' ? (string)$f[$m[1]] : '[' . str_replace('_', ' ', $m[1]) . ']';
    }, $s);
}

/** Text body to simple HTML for emails. */
function text_to_html(string $text): string {
    $out = '';
    foreach (preg_split("/\n\s*\n/", str_replace("\r", '', trim($text))) as $b) {
        $lines = explode("\n", $b);
        $isList = count(array_filter($lines, function ($l) { return preg_match('/^\s*[-•]\s+/', $l); })) === count($lines);
        $fmt = function ($s) { return preg_replace('/\*\*(.+?)\*\*/', '<b>$1</b>', h($s)); };
        if ($isList) $out .= '<ul>' . implode('', array_map(function ($l) use ($fmt) { return '<li>' . $fmt(preg_replace('/^\s*[-•]\s+/', '', $l)) . '</li>'; }, $lines)) . '</ul>';
        elseif (preg_match('/^#{1,3}\s+(.*)$/s', $b, $m)) $out .= '<h3 style="font-family:Georgia,serif;color:#0B1C3D">' . h($m[1]) . '</h3>';
        else $out .= '<p>' . implode('<br>', array_map($fmt, $lines)) . '</p>';
    }
    return $out;
}

// ---------- calendar ----------
function ics_escape(string $s): string { return addcslashes(str_replace(["\r\n", "\n"], '\\n', $s), ',;\\'); }
function ics_event(array $m, string $method = 'PUBLISH'): string {
    $start = strtotime($m['date']);
    $end = $start + 60 * (int)($m['durationMins'] ?? 120);
    $fmt = function ($t) { return gmdate('Ymd\THis\Z', $t); };
    $lines = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Droitwich United Charities//Trustee Portal//EN', 'METHOD:' . $method,
        'BEGIN:VEVENT', 'UID:' . $m['id'] . '@duc-portal', 'DTSTAMP:' . $fmt(time()), 'DTSTART:' . $fmt($start), 'DTEND:' . $fmt($end),
        'SUMMARY:' . ics_escape($m['title']), 'LOCATION:' . ics_escape($m['venue'] ?? ''),
        'DESCRIPTION:' . ics_escape('Papers: ' . site_url() . '/portal#meeting/' . $m['id']), 'END:VEVENT', 'END:VCALENDAR'];
    return implode("\r\n", $lines) . "\r\n";
}
function ics_feed(array $events): string {
    $fmt = function ($t) { return gmdate('Ymd\THis\Z', $t); };
    $out = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Droitwich United Charities//Trustee Portal//EN', 'X-WR-CALNAME:D.U.C. Trustees'];
    foreach ($events as $e) {
        $s = strtotime($e['start']);
        $out[] = 'BEGIN:VEVENT';
        $out[] = 'UID:' . $e['uid'] . '@duc-portal';
        $out[] = 'DTSTAMP:' . $fmt(time());
        if (!empty($e['allDay'])) { $out[] = 'DTSTART;VALUE=DATE:' . date('Ymd', $s); $out[] = 'DTEND;VALUE=DATE:' . date('Ymd', $s + 86400); }
        else { $out[] = 'DTSTART:' . $fmt($s); $out[] = 'DTEND:' . $fmt($s + 60 * ($e['mins'] ?? 120)); }
        $out[] = 'SUMMARY:' . ics_escape($e['title']);
        if (!empty($e['where'])) $out[] = 'LOCATION:' . ics_escape($e['where']);
        $out[] = 'END:VEVENT';
    }
    $out[] = 'END:VCALENDAR';
    return implode("\r\n", $out) . "\r\n";
}
