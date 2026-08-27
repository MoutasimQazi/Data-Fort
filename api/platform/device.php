<?php
/**
 * platform/device.php — Level 1 authentication for the PLATFORM panel.
 *
 * Same shape as api/device.php, scoped to the platform's own CA and
 * platform_devices/platform_device_auth_log instead of any tenant's.
 * There is no tenant dimension here — a platform admin's laptop either
 * holds a certificate this CA signed, or it doesn't.
 *
 * sslVar()/normaliseSerial()/dnPart()/clientCertificate() are read-only
 * cert-parsing helpers that don't know what a tenant OR a platform is —
 * reused as-is from api/device.php rather than copied, so the one place
 * that reads Apache's SSL_* variables stays one place.
 */

declare(strict_types=1);

require_once __DIR__ . '/../device.php';

function logPlatformDeviceAuth(PDO $pdo, array $cert, string $outcome, string $reason, ?array $device = null): void
{
    try {
        if ($outcome === 'denied') {
            $recent = $pdo->prepare(
                "SELECT 1 FROM platform_device_auth_log
                 WHERE outcome = 'denied' AND reason = ? AND ip <=> ?
                   AND certificate_serial <=> ?
                   AND at > DATE_SUB(NOW(), INTERVAL 10 MINUTE)
                 LIMIT 1"
            );
            $recent->execute([
                $reason,
                $_SERVER['REMOTE_ADDR'] ?? null,
                $cert['serial'] !== '' ? normaliseSerial($cert['serial']) : null,
            ]);
            if ($recent->fetchColumn()) return;
        }

        $stmt = $pdo->prepare(
            "INSERT INTO platform_device_auth_log
             (device_id, device_code, certificate_serial, certificate_subject,
              verify_result, outcome, reason, ip, user_agent, path)
             VALUES (?,?,?,?,?,?,?,?,?,?)"
        );
        $stmt->execute([
            $device['id'] ?? null,
            $device['device_code'] ?? ($cert['cn'] ?: null),
            $cert['serial'] !== '' ? normaliseSerial($cert['serial']) : null,
            $cert['subject'] !== '' ? substr($cert['subject'], 0, 255) : null,
            substr($cert['verify'] ?: 'NONE', 0, 64),
            $outcome,
            $reason,
            $_SERVER['REMOTE_ADDR'] ?? null,
            substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
            substr($_SERVER['REQUEST_URI'] ?? '', 0, 190),
        ]);
    } catch (Throwable $e) {
        error_log('[datafort-platform] device_auth_log write failed: ' . $e->getMessage());
    }
}

/**
 * Same off/log/enforce staging as verifyDevice() in api/device.php —
 * see that function's comment for the full reasoning. This launches in
 * 'log' by default (see platformRequireAuth() in platform/auth.php)
 * until the platform owner's own laptop is confirmed enrolled.
 */
function verifyPlatformDevice(PDO $pdo, string $mode): array
{
    $cert = clientCertificate();

    if ($mode === 'off') {
        return ['ok' => true, 'device' => null, 'mode' => 'off', 'cert' => $cert];
    }

    $fail = function (string $reason, string $message) use ($pdo, $cert, $mode) {
        logPlatformDeviceAuth($pdo, $cert, $mode === 'enforce' ? 'denied' : 'allowed', $reason);
        return [
            'ok'      => $mode !== 'enforce',
            'reason'  => $reason,
            'message' => $message,
            'device'  => null,
            'mode'    => $mode,
            'cert'    => $cert,
        ];
    };

    if (!$cert['present']) {
        return $fail('no_certificate',
            'This device is not recognised. No client certificate was presented.');
    }

    if ($cert['verify'] !== 'SUCCESS') {
        $why = strtolower($cert['verify']);
        $reason = strpos($why, 'expired') !== false ? 'certificate_expired' : 'certificate_invalid';
        return $fail($reason,
            'The certificate on this device was rejected: ' . $cert['verify'] . '.');
    }

    $serial = normaliseSerial($cert['serial']);
    $stmt = $pdo->prepare("SELECT * FROM platform_devices WHERE certificate_serial = ? LIMIT 1");
    $stmt->execute([$serial]);
    $device = $stmt->fetch();

    if (!$device) {
        return $fail('unknown_serial',
            'This certificate is valid but not registered as a platform device. Serial: ' . $serial);
    }

    if ($cert['cn'] !== '' && $device['device_code'] !== '' &&
        strcasecmp($cert['cn'], $device['device_code']) !== 0) {
        return $fail('cn_mismatch', 'The certificate identity does not match the registered device.');
    }

    if ($device['status'] === 'revoked') {
        return $fail('device_revoked', 'This device has been revoked.');
    }
    if ($device['status'] === 'disabled') {
        return $fail('device_disabled', 'This device is disabled.');
    }
    if ($device['status'] === 'pending') {
        return $fail('device_pending', 'This device is registered but not yet activated.');
    }
    if (!empty($device['expires_at']) && strtotime($device['expires_at']) < time()) {
        return $fail('device_expired', 'The certificate for this device has expired.');
    }

    try {
        $pdo->prepare("UPDATE platform_devices SET last_seen_at = NOW(), last_seen_ip = ? WHERE id = ?")
            ->execute([$_SERVER['REMOTE_ADDR'] ?? null, $device['id']]);
    } catch (Throwable $e) {
        error_log('[datafort-platform] device last_seen update failed: ' . $e->getMessage());
    }

    if (!isset($_SERVER['HTTP_X_DATAFORT_QUIET'])) {
        logPlatformDeviceAuth($pdo, $cert, 'allowed', 'ok', $device);
    }

    return ['ok' => true, 'device' => $device, 'mode' => $mode, 'cert' => $cert];
}
