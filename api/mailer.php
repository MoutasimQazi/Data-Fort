<?php
/** Authenticated SMTP transport. Credentials live only in config.php. */
declare(strict_types=1);

function smtpRead($socket): array {
    $text = ''; $code = 0;
    while (($line = fgets($socket, 4096)) !== false) {
        $text .= $line;
        if (preg_match('/^(\d{3})([ -])/', $line, $m)) {
            $code = (int) $m[1];
            if ($m[2] === ' ') break;
        }
    }
    return [$code, trim($text)];
}

function smtpCommand($socket, string $command, array $expected): void {
    if (fwrite($socket, $command . "\r\n") === false) throw new RuntimeException('SMTP write failed');
    [$code, $message] = smtpRead($socket);
    if (!in_array($code, $expected, true)) throw new RuntimeException("SMTP rejected command ($code): $message");
}

function mailHeaderValue(string $value): string {
    return trim(str_replace(["\r", "\n"], '', $value));
}

function mailHtml(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Email-safe Datafort shell. Content must already be escaped HTML. */
function datafortEmailHtml(string $title, string $content, string $eyebrow = 'SECURE WORKSPACE'): string {
    $safeTitle = mailHtml($title);
    $safeEyebrow = mailHtml($eyebrow);
    return '<!doctype html><html><body style="margin:0;padding:0;background:#F5F5F6;color:#16181B;font-family:Inter,Segoe UI,Arial,sans-serif">'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#F5F5F6"><tr><td align="center" style="padding:36px 16px">'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:600px;background:#FFFFFF;border:1px solid #E4E4E7;border-radius:18px;overflow:hidden">'
        . '<tr><td style="background:#16161A;padding:24px 32px"><table role="presentation" cellspacing="0" cellpadding="0"><tr>'
        . '<td style="width:28px"><img src="https://datafort.folksfirstlabs.com/brand/logo-mark-light.png" width="26" height="30" alt="" style="display:block;width:26px;height:30px;border:0"></td>'
        . '<td style="padding-left:11px;color:#FFFFFF;font-family:Poppins,Segoe UI,Arial,sans-serif;font-size:20px;font-weight:700;letter-spacing:1.8px">DATAFORT</td>'
        . '</tr></table></td></tr><tr><td style="padding:36px 32px 32px">'
        . '<div style="color:#B91917;font-size:11px;font-weight:700;letter-spacing:1.5px;margin-bottom:10px">' . $safeEyebrow . '</div>'
        . '<h1 style="margin:0 0 18px;color:#0B0B0C;font-family:Poppins,Segoe UI,Arial,sans-serif;font-size:27px;line-height:1.25">' . $safeTitle . '</h1>'
        . $content
        . '</td></tr><tr><td style="padding:20px 32px;background:#FAFAFB;border-top:1px solid #EEEEF0;color:#6B6B75;font-size:12px;line-height:1.6">'
        . 'Sent securely by Datafort &middot; FolksFirst Labs<br>Please do not forward account-access links.'</n+        . '</td></tr></table></td></tr></table></body></html>';
}

function datafortActionEmail(string $title, string $message, string $buttonText, string $url, string $note = ''): string {
    $content = '<p style="margin:0 0 24px;color:#44444D;font-size:15px;line-height:1.7">' . mailHtml($message) . '</p>'
        . '<table role="presentation" cellspacing="0" cellpadding="0" style="margin:0 0 24px"><tr><td style="background:#B91917;border-radius:9px">'
        . '<a href="' . mailHtml($url) . '" style="display:inline-block;padding:13px 22px;color:#FFFFFF;text-decoration:none;font-size:14px;font-weight:700">' . mailHtml($buttonText) . '</a>'
        . '</td></tr></table>';
    if ($note !== '') $content .= '<div style="padding:14px 16px;background:#FDECEB;border-left:3px solid #B91917;border-radius:7px;color:#6B2524;font-size:13px;line-height:1.55">' . mailHtml($note) . '</div>';
    $content .= '<p style="margin:24px 0 0;color:#8A8A95;font-size:11px;line-height:1.55;word-break:break-all">If the button does not work, copy this link:<br>' . mailHtml($url) . '</p>';
    return datafortEmailHtml($title, $content);
}

function sendAppMail(array $config, string $to, string $subject, string $body, array $extraHeaders = [], ?string $htmlBody = null): bool {
    $mail = $config['mail'] ?? [];
    $host = (string) ($mail['smtp_host'] ?? '');
    $port = (int) ($mail['smtp_port'] ?? 465);
    $user = (string) ($mail['smtp_username'] ?? '');
    $pass = (string) ($mail['smtp_password'] ?? '');
    $from = (string) ($mail['from_email'] ?? $user);
    if ($host === '' || $user === '' || $pass === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        error_log('[datafort] SMTP is incomplete or recipient is invalid');
        return false;
    }
    $headers = [
        'Date: ' . date(DATE_RFC2822),
        'From: ' . mailHeaderValue((string) ($mail['from_name'] ?? 'Datafort')) . " <$from>",
        "To: <$to>", 'Subject: ' . mailHeaderValue($subject),
        'Message-ID: <' . bin2hex(random_bytes(16)) . '@' . preg_replace('/^mail\./i', '', $host) . '>',
        'MIME-Version: 1.0',
    ];
    foreach ($extraHeaders as $name => $value) {
        if (preg_match('/^[A-Za-z0-9-]+$/', (string) $name)) $headers[] = $name . ': ' . mailHeaderValue((string) $value);
    }
    $socket = null;
    try {
        $context = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $host, 'SNI_enabled' => true]]);
        $socket = stream_socket_client("ssl://{$host}:{$port}", $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $context);
        if (!$socket) throw new RuntimeException("SMTP connection failed ($errno): $errstr");
        stream_set_timeout($socket, 15);
        [$code, $greeting] = smtpRead($socket);
        if ($code !== 220) throw new RuntimeException("SMTP greeting failed ($code): $greeting");
        smtpCommand($socket, 'EHLO ' . ($_SERVER['SERVER_NAME'] ?? 'localhost'), [250]);
        smtpCommand($socket, 'AUTH LOGIN', [334]);
        smtpCommand($socket, base64_encode($user), [334]);
        smtpCommand($socket, base64_encode($pass), [235]);
        smtpCommand($socket, "MAIL FROM:<$from>", [250]);
        smtpCommand($socket, "RCPT TO:<$to>", [250, 251]);
        smtpCommand($socket, 'DATA', [354]);
        if ($htmlBody !== null) {
            $boundary = 'df_' . bin2hex(random_bytes(12));
            $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';
            $messageBody = '--' . $boundary . "\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
                . chunk_split(base64_encode($body), 76, "\r\n")
                . '--' . $boundary . "\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
                . chunk_split(base64_encode($htmlBody), 76, "\r\n") . '--' . $boundary . '--';
        } else {
            $headers[] = 'Content-Type: text/plain; charset=UTF-8';
            $headers[] = 'Content-Transfer-Encoding: 8bit';
            $messageBody = str_replace(["\r\n", "\r"], "\n", $body);
            $messageBody = str_replace("\n", "\r\n", $messageBody);
        }
        $payload = implode("\r\n", $headers) . "\r\n\r\n" . preg_replace('/(?m)^\./', '..', $messageBody);
        smtpCommand($socket, $payload . "\r\n.", [250]);
        smtpCommand($socket, 'QUIT', [221]);
        fclose($socket);
        return true;
    } catch (Throwable $e) {
        if (is_resource($socket)) fclose($socket);
        error_log('[datafort] SMTP send failed: ' . $e->getMessage());
        return false;
    }
}
