<?php
/**
 * device.php — Level 1 authentication: is this a company laptop?
 *
 * Apache has already done the cryptography. By the time PHP runs, the
 * TLS handshake either presented a certificate our private CA signed or
 * it did not. This file's job is to read Apache's verdict, translate the
 * certificate into a row in company_devices, and decide whether that
 * device is still allowed in.
 *
 * ─────────────────────────────────────────────────────────────────
 * WHERE THE TRUST ACTUALLY LIVES
 *
 * The security of this whole layer rests on ONE line in the Apache
 * config — `SSLVerifyClient require` with `SSLCACertificateFile` set to
 * our CA. If that is missing, every function here still runs and still
 * returns answers, but they mean nothing, because anyone could have
 * connected.
 *
 * Two rules follow, and breaking either silently destroys the layer:
 *
 *   1. NEVER read these values from an HTTP header. Apache exposes them
 *      as SSL_* environment variables. A client can send a header named
 *      "SSL-Client-Verify: SUCCESS" — PHP would see that as
 *      HTTP_SSL_CLIENT_VERIFY, a different key, which is why the reads
 *      below are exact and never loop over $_SERVER looking for
 *      something that resembles a certificate.
 *
 *   2. If the app ever sits behind a reverse proxy or CDN that
 *      terminates TLS, this layer is GONE — the proxy holds the
 *      certificate, not Apache. In that case the proxy must be the one
 *      enforcing mTLS and forwarding a signed assertion, and this file
 *      must be rewritten to verify that signature.
 * ─────────────────────────────────────────────────────────────────
 */

/**
 * Reads an Apache SSL variable.
 *
 * Under CGI/FastCGI, and after any internal redirect (which .htaccess
 * rewrites cause), Apache re-exposes variables with a REDIRECT_ prefix —
 * sometimes stacked more than once. Both spellings are checked.
 *
 * Requires this in the vhost, or every one of these comes back empty:
 *   SSLOptions +StdEnvVars +ExportCertData
 */
function sslVar(string $name): string
{
    foreach ([$name, 'REDIRECT_' . $name, 'REDIRECT_REDIRECT_' . $name] as $key) {
        if (isset($_SERVER[$key]) && $_SERVER[$key] !== '') {
            return (string) $_SERVER[$key];
        }
    }
    return '';
}

/**
 * Normalises a certificate serial to one canonical form.
 *
 * Apache reports SSL_CLIENT_M_SERIAL as uppercase hex, but different
 * CAs and tools write serials as "8A:91:F2:3B", "0x8a91f23b", or with
 * leading zeros. Storing one form and comparing another is the classic
 * way this integration fails: every device is rejected, the certificate
 * looks perfect, and the cause is a colon.
 *
 * This function is the single definition of that format. Change it and
 * every row already in company_devices.certificate_serial must be
 * rewritten to match.
 */
function normaliseSerial(string $serial): string
{
    $s = strtoupper(trim($serial));
    $s = preg_replace('/^0X/', '', $s);
    $s = preg_replace('/[^0-9A-F]/', '', $s);   // strip colons, spaces
    $s = ltrim($s, '0');
    return $s === '' ? '0' : $s;
}

/**
 * Pulls one RDN out of a DN string, e.g. cn from "/C=IN/O=Movenetics/CN=LAPTOP-001".
 */
function dnPart(string $dn, string $key): string
{
    if (preg_match('#/' . preg_quote($key, '#') . '=([^/]+)#i', $dn, $m)) {
        return trim($m[1]);
    }
    // Some builds emit comma-separated DNs instead of slash-separated.
    if (preg_match('#\b' . preg_quote($key, '#') . '=([^,]+)#i', $dn, $m)) {
        return trim($m[1]);
    }
    return '';
}

/**
 * Everything Apache told us about the client certificate.
 */
function clientCertificate(): array
{
    $subject = sslVar('SSL_CLIENT_S_DN');
    $issuer  = sslVar('SSL_CLIENT_I_DN');

    return [
        'verify'      => sslVar('SSL_CLIENT_VERIFY'),      // SUCCESS | NONE | FAILED:<reason>
        'serial'      => sslVar('SSL_CLIENT_M_SERIAL'),
        'subject'     => $subject,
        'issuer'      => $issuer,
        'cn'          => sslVar('SSL_CLIENT_S_DN_CN') ?: dnPart($subject, 'CN'),
        'issuer_cn'   => sslVar('SSL_CLIENT_I_DN_CN') ?: dnPart($issuer, 'CN'),
        'not_before'  => sslVar('SSL_CLIENT_V_START'),
        'not_after'   => sslVar('SSL_CLIENT_V_END'),
        'fingerprint' => sslVar('SSL_CLIENT_CERT_FP') ?: '',
        'present'     => sslVar('SSL_CLIENT_VERIFY') !== '' && sslVar('SSL_CLIENT_VERIFY') !== 'NONE',
    ];
}

/**
 * Writes the attempt to device_auth_log. Called on EVERY request that
 * goes through the gate, allowed or denied — a log that only records
 * failures cannot answer "which laptop was this opened from".
 */
function logDeviceAuth(PDO $pdo, array $cert, string $outcome, string $reason, ?array $device = null, ?int $tenantId = null): void
{
    try {
        $stmt = $pdo->prepare(
            "INSERT INTO device_auth_log
             (tenant_id, device_id, device_code, certificate_serial, certificate_subject,
              verify_result, outcome, reason, ip, user_agent, path)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)"
        );
        $stmt->execute([
            $tenantId ?: ($device['tenant_id'] ?? null),
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
        // A logging failure must not take the site down, but it must not
        // pass unnoticed either.
        error_log('[datafort] device_auth_log write failed: ' . $e->getMessage());
    }
}

/**
 * The gate. Returns:
 *   ['ok' => true,  'device' => [...], 'mode' => ...]
 *   ['ok' => false, 'reason' => '...', 'message' => '...', 'mode' => ...]
 *
 * Enforcement mode comes from tenants.device_enforcement so the rollout
 * in section 21 of the spec can be staged without a code deploy:
 *
 *   off      — no device check at all. Use only before the CA exists.
 *   log      — check and record, but never block. This is the mode to
 *              run during enrolment: it tells you exactly which laptops
 *              would have been locked out, before they actually are.
 *   enforce  — deny anything that is not a known, active device.
 *
 * Going straight from `off` to `enforce` on a Monday morning locks out
 * every employee whose enrolment silently failed. Sit in `log` until
 * device_auth_log shows zero denials for a full working week.
 */
function verifyDevice(PDO $pdo, int $tenantId, string $mode): array
{
    $cert = clientCertificate();

    if ($mode === 'off') {
        return ['ok' => true, 'device' => null, 'mode' => 'off', 'cert' => $cert];
    }

    $fail = function (string $reason, string $message) use ($pdo, $cert, $mode, $tenantId) {
        logDeviceAuth($pdo, $cert, $mode === 'enforce' ? 'denied' : 'allowed', $reason, null, $tenantId);
        return [
            'ok'      => $mode !== 'enforce',   // in log mode we observe, we do not block
            'reason'  => $reason,
            'message' => $message,
            'device'  => null,
            'mode'    => $mode,
            'cert'    => $cert,
        ];
    };

    // ── No certificate at all ──
    if (!$cert['present']) {
        return $fail('no_certificate',
            'This device is not recognised as a company laptop. No client certificate was presented.');
    }

    // ── Apache rejected it: expired, wrong CA, broken chain ──
    if ($cert['verify'] !== 'SUCCESS') {
        // SSL_CLIENT_VERIFY carries the reason after a colon on failure.
        $why = strtolower($cert['verify']);
        $reason = strpos($why, 'expired') !== false ? 'certificate_expired' : 'certificate_invalid';
        return $fail($reason,
            'The certificate on this device was rejected: ' . $cert['verify'] .
            '. Contact IT to have it reissued.');
    }

    // ── Known device? ──
    $serial = normaliseSerial($cert['serial']);

    $stmt = $pdo->prepare(
        "SELECT * FROM company_devices WHERE certificate_serial = ? LIMIT 1"
    );
    $stmt->execute([$serial]);
    $device = $stmt->fetch();

    if (!$device) {
        return $fail('unknown_serial',
            'This certificate is valid but the device is not registered. ' .
            'Ask an administrator to add it. Serial: ' . $serial);
    }

    /* Cross-tenant certificate use. The certificate is genuine and the
     * device is real, but it belongs to a different customer. Refuse
     * loudly — this is either a serious misconfiguration or an attempt. */
    if ((int) $device['tenant_id'] !== $tenantId) {
        logDeviceAuth($pdo, $cert, 'denied', 'wrong_tenant', $device, $tenantId);
        return [
            'ok' => false, 'reason' => 'wrong_tenant',
            'message' => 'This device is registered to a different organisation.',
            'device' => null, 'mode' => $mode, 'cert' => $cert,
        ];
    }

    /* Belt and braces on the CN. Apache already proved our CA signed this
     * serial, so a mismatch here means the device row was edited or a
     * certificate was reissued with the same serial — both worth
     * refusing rather than shrugging at. */
    if ($cert['cn'] !== '' && $device['device_code'] !== '' &&
        strcasecmp($cert['cn'], $device['device_code']) !== 0) {
        return $fail('cn_mismatch',
            'The certificate identity does not match the registered device. Contact IT.');
    }

    if ($device['status'] === 'revoked') {
        return $fail('device_revoked',
            'This device has been revoked and can no longer access Datafort.');
    }
    if ($device['status'] === 'disabled') {
        return $fail('device_disabled',
            'This device is temporarily disabled. Contact your administrator.');
    }
    if ($device['status'] === 'pending') {
        return $fail('device_pending',
            'This device is registered but not yet activated. Contact your administrator.');
    }

    /* Expiry is checked here as well as by Apache. Apache uses the
     * certificate's own notAfter; this uses our record of it. They should
     * agree, and if they do not, the safe answer is no. */
    if (!empty($device['expires_at']) && strtotime($device['expires_at']) < time()) {
        return $fail('device_expired',
            'The certificate for this device has expired. Contact IT for a new one.');
    }

    // ── Allowed ──
    try {
        $pdo->prepare("UPDATE company_devices SET last_seen_at = NOW(), last_seen_ip = ? WHERE id = ?")
            ->execute([$_SERVER['REMOTE_ADDR'] ?? null, $device['id']]);
    } catch (Throwable $e) {
        error_log('[datafort] device last_seen update failed: ' . $e->getMessage());
    }

    /* Successful device auth is logged once per session rather than once
     * per request — otherwise a single page load writes a dozen rows and
     * the table becomes useless for reading. auth-login.php sets this. */
    if (!isset($_SERVER['HTTP_X_DATAFORT_QUIET'])) {
        logDeviceAuth($pdo, $cert, 'allowed', 'ok', $device, $tenantId);
    }

    return ['ok' => true, 'device' => $device, 'mode' => $mode, 'cert' => $cert];
}
