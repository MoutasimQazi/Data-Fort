<?php
/**
 * config.sample.php — copy to config.php and fill in.
 *
 * config.php is NOT in version control and is blocked from direct web
 * access by api/.htaccess. Do not commit real credentials: the existing
 * pm-backend-php repo has live database credentials in a public repo,
 * and that is exactly the mistake worth not repeating on the product
 * whose whole pitch is that data does not leak.
 */

return [
    // ── Database ──
    'db' => [
        'host' => 'localhost',
        'port' => '3306',
        'name' => 'datafort',
        'user' => 'datafort_app',
        'pass' => 'CHANGE_ME',
    ],

    /**
     * The tenant this deployment serves.
     *
     * Datafort is designed multi-tenant, but a single-domain cPanel
     * install serves one customer, and mTLS is per-vhost anyway — the
     * client certificate is presented before any tenant routing could
     * happen. Multi-tenant hosting means one vhost and one CA per
     * customer, not one vhost with many.
     */
    'tenant_slug' => 'movenetics',

    // ── Sessions ──
    'session' => [
        'cookie'      => 'df_session',
        'lifetime'    => 60 * 60 * 8,        // 8 hours — one working day
        'trusted_days' => 14,                // "keep me signed in on this device"
        'secure'      => true,               // HTTPS only. Never false in production.
    ],

    // ── Login throttling ──
    'throttle' => [
        'max_attempts' => 6,
        'window'       => 15 * 60,           // per 15 minutes, per email AND per IP
        'lockout'      => 30 * 60,
    ],

    /**
     * Device authentication.
     *
     * The live value comes from tenants.device_enforcement in the
     * database so it can be changed without a deploy. This is only the
     * fallback used if that row cannot be read.
     *
     * off | log | enforce  — see api/device.php.
     */
    'device_enforcement_fallback' => 'log',

    /**
     * Certificate authority.
     * Informational — Apache does the verification. Used by the device
     * admin page to show which CA issued a certificate.
     */
    'ca' => [
        'name'      => 'Movenetics Digital Device CA',
        'cert_path' => '/etc/ssl/datafort/company-ca.crt',
    ],

    // ── Email relay (requirements 7.1) ──
    'mail' => [
        'from_name'  => 'Movenetics Digital',
        'from_email' => 'noreply@moveneticsdigital.com',
        // Reply-To points at a relay inbox, not the rep's own address —
        // otherwise the rep's mailbox becomes an unlogged side channel.
        'reply_to'   => 'leads@moveneticsdigital.com',
    ],

    // ── Watermark on revealed values (requirements 7.3) ──
    'watermark' => [
        'enabled'  => true,
        'opacity'  => 0.34,
        'font_size' => 13,
    ],
];
