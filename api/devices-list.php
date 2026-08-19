<?php
/**
 * devices-list.php — the company laptop register.
 * Admin only: this is the list of every machine allowed near the data.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

$ctx = requireAuth($pdo, $CONFIG, 'admin');
$tid = $ctx['user']['tenant_id'];

$stmt = $pdo->prepare(
    "SELECT d.*, u.name AS employee_name, u.email AS employee_email
     FROM company_devices d
     LEFT JOIN users u ON u.id = d.employee_id
     WHERE d.tenant_id = ?
     ORDER BY FIELD(d.status,'pending','active','disabled','revoked'), d.device_code"
);
$stmt->execute([$tid]);
$devices = $stmt->fetchAll();

/* Recent denials. This is the list an admin actually needs after
 * switching enforcement on: which machines just got locked out, and
 * whether that was intentional. */
$denials = $pdo->prepare(
    "SELECT device_code, certificate_serial, reason, ip, at
     FROM device_auth_log
     WHERE outcome = 'denied' AND at > DATE_SUB(NOW(), INTERVAL 7 DAY)
     ORDER BY at DESC LIMIT 50"
);
$denials->execute();

respond([
    'devices' => array_map(function (array $d): array {
        return [
            'id'         => (int) $d['id'],
            'code'       => $d['device_code'],
            'employee'   => $d['employee_name'],
            'employeeId' => $d['employee_id'] ? (int) $d['employee_id'] : null,
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
    'mode'       => $ctx['tenant']['device_enforcement'],
    'thisDevice' => $ctx['device']['certificate_serial'] ?? null,
]);
