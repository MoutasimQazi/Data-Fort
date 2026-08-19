<?php
/**
 * devices-save.php — register, assign, activate, disable, revoke.
 *
 * Every action here is written to the audit log with the acting
 * administrator named. "Who re-activated the laptop that leaked the
 * list" has to be an answerable question.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/device.php';

requireMethod('POST');

$ctx  = requireAuth($pdo, $CONFIG, 'admin');
$user = $ctx['user'];
$tid  = $user['tenant_id'];

$in     = body();
$action = (string) ($in['action'] ?? '');


/* ══ Register a new laptop ═════════════════════════════════════════ */

if ($action === 'create') {
    $code   = strtoupper(trim((string) ($in['code'] ?? '')));
    $serial = normaliseSerial((string) ($in['serial'] ?? ''));

    if ($code === '' || $serial === '' || $serial === '0') {
        respond(['error' => 'Device code and certificate serial are both required.'], 400);
    }

    /* The serial is globally unique, not per-tenant. The certificate is
     * presented during the TLS handshake, before anything knows which
     * customer is being addressed, so two tenants cannot both claim one
     * serial and have the lookup stay unambiguous. */
    $dupe = $pdo->prepare("SELECT tenant_id FROM company_devices WHERE certificate_serial = ? LIMIT 1");
    $dupe->execute([$serial]);
    if ($existing = $dupe->fetch()) {
        respond([
            'error' => ((int) $existing['tenant_id'] === $tid)
                ? 'That certificate serial is already registered.'
                : 'That certificate serial belongs to another organisation.',
        ], 409);
    }

    $stmt = $pdo->prepare(
        "INSERT INTO company_devices
         (tenant_id, device_code, employee_id, certificate_serial, certificate_subject,
          certificate_issuer, status, issued_at, expires_at, note)
         VALUES (?,?,?,?,?,?,?,?,?,?)"
    );
    $stmt->execute([
        $tid,
        $code,
        !empty($in['employeeId']) ? (int) $in['employeeId'] : null,
        $serial,
        (string) ($in['subject'] ?? ('CN=' . $code)),
        (string) ($in['issuer'] ?? ($CONFIG['ca']['name'] ?? '')),
        // New devices land as 'pending' deliberately. Registering a laptop
        // and authorising it are two separate decisions, and they should
        // be made separately rather than as one careless paste.
        'pending',
        !empty($in['issuedAt'])  ? $in['issuedAt']  : null,
        !empty($in['expiresAt']) ? $in['expiresAt'] : null,
        isset($in['note']) ? substr((string) $in['note'], 0, 255) : null,
    ]);

    audit($pdo, $tid, $user, 'device', $code, 'Registered device, serial ' . $serial, $ctx['device']);
    respond(['ok' => true, 'id' => (int) $pdo->lastInsertId()]);
}


/* ══ Everything else acts on an existing row ═══════════════════════ */

$id = (int) ($in['id'] ?? 0);
if ($id <= 0) {
    respond(['error' => 'Device id required'], 400);
}

$stmt = $pdo->prepare("SELECT * FROM company_devices WHERE id = ? AND tenant_id = ? LIMIT 1");
$stmt->execute([$id, $tid]);
$device = $stmt->fetch();

if (!$device) {
    respond(['error' => 'Device not found'], 404);
}

switch ($action) {

    case 'assign':
        $employeeId = !empty($in['employeeId']) ? (int) $in['employeeId'] : null;

        if ($employeeId !== null) {
            $chk = $pdo->prepare("SELECT id FROM users WHERE id = ? AND tenant_id = ? LIMIT 1");
            $chk->execute([$employeeId, $tid]);
            if (!$chk->fetch()) {
                respond(['error' => 'Employee not found'], 404);
            }
        }

        $pdo->prepare("UPDATE company_devices SET employee_id = ? WHERE id = ?")
            ->execute([$employeeId, $id]);

        audit($pdo, $tid, $user, 'device', $device['device_code'],
            $employeeId ? ('Assigned to employee ' . $employeeId) : 'Unassigned', $ctx['device']);
        break;

    case 'activate':
        if ($device['status'] === 'revoked') {
            /* Revocation is final by design. Un-revoking would mean a
             * certificate previously declared compromised becomes trusted
             * again on one administrator's say-so. Issue a new
             * certificate and register that instead. */
            respond(['error' =>
                'Revoked devices cannot be reactivated. Issue a new certificate and register it.'], 409);
        }
        $pdo->prepare("UPDATE company_devices SET status = 'active' WHERE id = ?")->execute([$id]);
        audit($pdo, $tid, $user, 'device', $device['device_code'], 'Activated', $ctx['device']);
        break;

    case 'disable':
        $pdo->prepare("UPDATE company_devices SET status = 'disabled' WHERE id = ?")->execute([$id]);

        // Live sessions die immediately. Disabling a laptop that is
        // currently signed in should take effect now, not in eight hours.
        $pdo->prepare("UPDATE sessions SET revoked_at = NOW() WHERE device_id = ? AND revoked_at IS NULL")
            ->execute([$id]);

        audit($pdo, $tid, $user, 'device', $device['device_code'],
            'Disabled — active sessions killed', $ctx['device']);
        break;

    case 'revoke':
        $reason = substr((string) ($in['reason'] ?? 'Not stated'), 0, 255);

        $pdo->prepare(
            "UPDATE company_devices
             SET status = 'revoked', revoked_at = NOW(), revoked_reason = ?
             WHERE id = ?"
        )->execute([$reason, $id]);

        $pdo->prepare("UPDATE sessions SET revoked_at = NOW() WHERE device_id = ? AND revoked_at IS NULL")
            ->execute([$id]);

        audit($pdo, $tid, $user, 'device', $device['device_code'], 'Revoked — ' . $reason, $ctx['device']);

        /* IMPORTANT — this revokes the device INSIDE Datafort only.
         * The certificate itself is still cryptographically valid, and
         * Apache will still complete the TLS handshake with it. Revoke it
         * at the CA as well and publish the CRL, or a stolen laptop can
         * still reach the login page — it just cannot get past it. */
        respond([
            'ok'      => true,
            'warning' => 'Device revoked in Datafort. Now revoke the certificate at the CA as well ' .
                         '(step ca revoke --serial ' . $device['certificate_serial'] . ') and publish the CRL, ' .
                         'so Apache rejects it during the handshake rather than after.',
        ]);

    default:
        respond(['error' => 'Unknown action'], 400);
}

respond(['ok' => true]);
