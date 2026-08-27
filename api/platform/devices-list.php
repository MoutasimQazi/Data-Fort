<?php
/**
 * devices-list.php — laptops allowed into the platform panel itself.
 * Mirrors api/devices-list.php, minus the employee-assignment concept
 * (there's one team here, and admin_id just records who enrolled it).
 */

declare(strict_types=1);

require_once __DIR__ . '/_boot.php';

$ctx = platformRequireAuth($pdo, $CONFIG);

$stmt = $pdo->prepare(
    "SELECT d.*, a.name AS admin_name
     FROM platform_devices d
     LEFT JOIN platform_admins a ON a.id = d.admin_id
     ORDER BY FIELD(d.status,'pending','active','disabled','revoked'), d.device_code"
);
$stmt->execute();
$devices = $stmt->fetchAll();

$denials = $pdo->prepare(
    "SELECT device_code, certificate_serial, reason, ip, at
     FROM platform_device_auth_log
     WHERE outcome = 'denied' AND at > DATE_SUB(NOW(), INTERVAL 7 DAY)
     ORDER BY at DESC LIMIT 50"
);
$denials->execute();

respond([
    'devices' => array_map(function (array $d): array {
        return [
            'id'         => (int) $d['id'],
            'code'       => $d['device_code'],
            'admin'      => $d['admin_name'],
            'serial'     => $d['certificate_serial'],
            'subject'    => $d['certificate_subject'],
            'issuer'     => $d['certificate_issuer'],
            'status'     => $d['status'],
            'issuedAt'   => $d['issued_at'],
            'expiresAt'  => $d['expires_at'],
            'revokedAt'  => $d['revoked_at'],
            'lastSeenAt' => $d['last_seen_at'],
            'lastSeenIp' => $d['last_seen_ip'],
            'note'       => $d['note'],
        ];
    }, $devices),
    'denials'    => $denials->fetchAll(),
    'mode'       => $CONFIG['multi_tenant']['platform_device_enforcement'] ?? 'log',
    'thisDevice' => $ctx['device']['certificate_serial'] ?? null,
]);
