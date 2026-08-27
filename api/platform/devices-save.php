<?php
/**
 * devices-save.php — register, activate, disable, revoke a laptop
 * allowed into the platform panel. Mirrors api/devices-save.php minus
 * the tenant/employee dimensions (serial uniqueness is simply global
 * here, since there's only one CA involved).
 */

declare(strict_types=1);

require_once __DIR__ . '/_boot.php';

requireMethod('POST');

$ctx   = platformRequireAuth($pdo, $CONFIG);
$admin = $ctx['admin'];

$in     = body();
$action = (string) ($in['action'] ?? '');


/* ══ Register a new laptop ═════════════════════════════════════════ */

if ($action === 'create') {
    $code   = strtoupper(trim((string) ($in['code'] ?? '')));
    $serial = normaliseSerial((string) ($in['serial'] ?? ''));

    if ($code === '' || $serial === '' || $serial === '0') {
        respond(['error' => 'Device code and certificate serial are both required.'], 400);
    }

    $dupe = $pdo->prepare("SELECT id FROM platform_devices WHERE certificate_serial = ? LIMIT 1");
    $dupe->execute([$serial]);
    if ($dupe->fetch()) {
        respond(['error' => 'That certificate serial is already registered.'], 409);
    }

    $stmt = $pdo->prepare(
        "INSERT INTO platform_devices
         (device_code, admin_id, certificate_serial, certificate_subject, certificate_issuer,
          status, issued_at, expires_at, note)
         VALUES (?,?,?,?,?,?,?,?,?)"
    );
    $stmt->execute([
        $code,
        (int) $admin['id'],
        $serial,
        (string) ($in['subject'] ?? ('CN=' . $code)),
        (string) ($in['issuer'] ?? ''),
        'pending',
        !empty($in['issuedAt'])  ? $in['issuedAt']  : null,
        !empty($in['expiresAt']) ? $in['expiresAt'] : null,
        isset($in['note']) ? substr((string) $in['note'], 0, 255) : null,
    ]);

    platformAudit($pdo, $admin, 'device', $code, 'Registered device, serial ' . $serial);
    respond(['ok' => true, 'id' => (int) $pdo->lastInsertId()]);
}


/* ══ Everything else acts on an existing row ═══════════════════════ */

$id = (int) ($in['id'] ?? 0);
if ($id <= 0) {
    respond(['error' => 'Device id required'], 400);
}

$stmt = $pdo->prepare("SELECT * FROM platform_devices WHERE id = ? LIMIT 1");
$stmt->execute([$id]);
$device = $stmt->fetch();

if (!$device) {
    respond(['error' => 'Device not found'], 404);
}

switch ($action) {

    case 'activate':
        if ($device['status'] === 'revoked') {
            respond(['error' => 'Revoked devices cannot be reactivated. Issue a new certificate and register it.'], 409);
        }
        $pdo->prepare("UPDATE platform_devices SET status = 'active' WHERE id = ?")->execute([$id]);
        platformAudit($pdo, $admin, 'device', $device['device_code'], 'Activated');
        break;

    case 'disable':
        $pdo->prepare("UPDATE platform_devices SET status = 'disabled' WHERE id = ?")->execute([$id]);
        $pdo->prepare("UPDATE platform_admin_sessions SET revoked_at = NOW() WHERE device_id = ? AND revoked_at IS NULL")
            ->execute([$id]);
        platformAudit($pdo, $admin, 'device', $device['device_code'], 'Disabled — active sessions killed');
        break;

    case 'revoke':
        $reason = substr((string) ($in['reason'] ?? 'Not stated'), 0, 255);
        $pdo->prepare(
            "UPDATE platform_devices SET status='revoked', revoked_at=NOW(), revoked_reason=? WHERE id=?"
        )->execute([$reason, $id]);
        $pdo->prepare("UPDATE platform_admin_sessions SET revoked_at = NOW() WHERE device_id = ? AND revoked_at IS NULL")
            ->execute([$id]);
        platformAudit($pdo, $admin, 'device', $device['device_code'], 'Revoked — ' . $reason);

        respond([
            'ok'      => true,
            'warning' => 'Device revoked here. Now revoke the certificate at the platform CA as well and publish the CRL.',
        ]);

    default:
        respond(['error' => 'Unknown action'], 400);
}

respond(['ok' => true]);
