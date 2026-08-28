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

function sendAppMail(array $config, string $to, string $subject, string $body, array $extraHeaders = []): bool {
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
        'MIME-Version: 1.0', 'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
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
        $normalized = str_replace(["\r\n", "\r"], "\n", $body);
        $payload = implode("\r\n", $headers) . "\r\n\r\n" . str_replace("\n", "\r\n", preg_replace('/(?m)^\./', '..', $normalized));
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
