<?php
/**
 * Minimal SMTP client (STARTTLS on 587, SSL on 465, or plain) with AUTH LOGIN/PLAIN.
 * Works with Microsoft 365, the Saltway mail server, cPanel mail or any SMTP provider.
 */
declare(strict_types=1);

/** Returns null on success or an error message. */
function smtp_send(string $to, string $subject, string $html, array $attachments = []): ?string {
    $c = portal_settings()['smtp'];
    if ($c['host'] === '' || $c['from'] === '') return 'Email is not set up yet (Portal settings > Email)';
    $secure = $c['secure'];
    $remote = ($secure === 'ssl' ? 'ssl://' : 'tcp://') . $c['host'] . ':' . (int)$c['port'];
    $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true, 'peer_name' => $c['host']]]);
    $fp = @stream_socket_client($remote, $errno, $errstr, 20, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) return "Can't connect to {$c['host']}:{$c['port']} ($errstr)";
    stream_set_timeout($fp, 30);
    $read = function () use ($fp): array {
        $lines = ''; $code = 0;
        while (($line = fgets($fp, 2048)) !== false) {
            $lines .= $line; $code = (int)substr($line, 0, 3);
            if (strlen($line) < 4 || $line[3] === ' ') break;
        }
        return [$code, trim($lines)];
    };
    $cmd = function (string $s, array $ok) use ($fp, $read) {
        fwrite($fp, $s . "\r\n");
        [$code, $msg] = $read();
        if (!in_array($code, $ok, true)) throw new RuntimeException((strpos($s, 'AUTH') === 0 || strlen($s) > 60 ? explode(' ', $s)[0] : $s) . ' → ' . $msg);
        return $msg;
    };
    try {
        [$code, $msg] = $read();
        if ($code !== 220) throw new RuntimeException('Server said: ' . $msg);
        $me = preg_replace('/[^a-z0-9.-]/i', '', $_SERVER['SERVER_NAME'] ?? 'localhost') ?: 'localhost';
        $ehlo = $cmd("EHLO $me", [250]);
        if ($secure === 'tls') {
            $cmd('STARTTLS', [220]);
            if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT') ? STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT : 0)))
                throw new RuntimeException('TLS handshake failed');
            $ehlo = $cmd("EHLO $me", [250]);
        }
        if ($c['user'] !== '') {
            if (stripos($ehlo, 'AUTH') !== false && preg_match('/AUTH[ =][^\n]*LOGIN/i', $ehlo)) {
                $cmd('AUTH LOGIN', [334]);
                $cmd(base64_encode($c['user']), [334]);
                $cmd(base64_encode($c['pass']), [235]);
            } else {
                $cmd('AUTH PLAIN ' . base64_encode("\0" . $c['user'] . "\0" . $c['pass']), [235]);
            }
        }
        $cmd('MAIL FROM:<' . $c['from'] . '>', [250]);
        $cmd('RCPT TO:<' . $to . '>', [250, 251]);
        $cmd('DATA', [354]);
        $msg = smtp_build_message($c, $to, $subject, $html, $attachments);
        $msg = preg_replace('/^\./m', '..', $msg);
        fwrite($fp, $msg . "\r\n.\r\n");
        [$code, $m] = $read();
        if ($code !== 250) throw new RuntimeException('Message rejected: ' . $m);
        @fwrite($fp, "QUIT\r\n");
        fclose($fp);
        return null;
    } catch (Throwable $e) {
        @fclose($fp);
        return $e->getMessage();
    }
}

function smtp_header_encode(string $s): string {
    return preg_match('/[^\x20-\x7e]/', $s) ? '=?UTF-8?B?' . base64_encode($s) . '?=' : $s;
}

function smtp_build_message(array $c, string $to, string $subject, string $html, array $attachments): string {
    $alt = 'alt_' . bin2hex(random_bytes(8));
    $mix = 'mix_' . bin2hex(random_bytes(8));
    $domain = substr(strrchr($c['from'], '@'), 1) ?: 'localhost';
    $text = trim(html_entity_decode(strip_tags(preg_replace(['/<br\s*\/?>/i', '/<\/p>/i', '/<a [^>]*href="([^"]+)"[^>]*>(.*?)<\/a>/i'], ["\n", "\n\n", '$2 ($1)'], $html)), ENT_QUOTES, 'UTF-8'));
    $text = preg_replace("/\n{3,}/", "\n\n", preg_replace('/[ \t]+/', ' ', $text));
    $h = [];
    $h[] = 'Date: ' . date('r');
    $h[] = 'From: ' . smtp_header_encode($c['fromName']) . ' <' . $c['from'] . '>';
    $h[] = 'To: <' . $to . '>';
    if ($c['replyTo'] !== '') $h[] = 'Reply-To: <' . $c['replyTo'] . '>';
    $h[] = 'Subject: ' . smtp_header_encode($subject);
    $h[] = 'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $domain . '>';
    $h[] = 'MIME-Version: 1.0';
    $altPart = "--$alt\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($text))
        . "--$alt\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($html)) . "--$alt--\r\n";
    if (!$attachments) {
        $h[] = "Content-Type: multipart/alternative; boundary=\"$alt\"";
        return implode("\r\n", $h) . "\r\n\r\n" . $altPart;
    }
    $h[] = "Content-Type: multipart/mixed; boundary=\"$mix\"";
    $body = "--$mix\r\nContent-Type: multipart/alternative; boundary=\"$alt\"\r\n\r\n" . $altPart;
    foreach ($attachments as $a) {
        $n = str_replace('"', '', $a['name']);
        $body .= "--$mix\r\nContent-Type: {$a['type']}; name=\"$n\"\r\nContent-Transfer-Encoding: base64\r\nContent-Disposition: attachment; filename=\"$n\"\r\n\r\n" . chunk_split(base64_encode($a['data']));
    }
    $body .= "--$mix--\r\n";
    return implode("\r\n", $h) . "\r\n\r\n" . $body;
}
