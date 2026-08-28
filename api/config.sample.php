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

    // Surfaces the raw MySQL error to the browser on a failed
    // connection. Invaluable during setup, an information leak
    // afterwards. Ships false.
    'debug' => false,

    // ── Database ──
    'db' => [
        'host' => 'localhost',
        'port' => '3306',
        'name' => 'datafort',
        'user' => 'datafort_app',
        'pass' => 'CHANGE_ME',
    ],

    /**
     * The tenant this deployment serves when multi_tenant.enabled is
     * false (below) — i.e. every deployment that predates it, including
     * a single-customer install where this is the only config that
     * exists. Ignored once multi-tenant routing is turned on.
     */
    'tenant_slug' => 'movenetics',

    /**
     * Multi-tenant routing — see api/tenant-resolver.php.
     *
     * Off by default, which is exactly today's single-database
     * behaviour: api/db.php connects to 'db' above and nothing else
     * changes. Turn this on only on a vhost that is deliberately part
     * of the shared multi-tenant codebase (one vhost per customer
     * subdomain, one CA per vhost — mTLS is still per-vhost, so that
     * part of the original design note above still holds; what changes
     * is that every vhost now shares one codebase and one platform
     * registry instead of each being a disconnected install).
     */
    'multi_tenant' => [
        'enabled' => false,

        // {slug}.{base_domain} is how a request's tenant is identified.
        'base_domain' => 'datafort.io',

        // Exact Host -> slug overrides, checked BEFORE the generic
        // {slug}.{base_domain} stripping above. Needed for any domain
        // that predates the multi-tenant scheme — most importantly the
        // very first tenant's own existing production domain, whose
        // hostname is the PRODUCT name (e.g. datafort.folksfirstlabs.com)
        // and not that tenant's slug (movenetics). Stripping base_domain
        // off that host naively yields "datafort", which is not a
        // registered tenant, and every request 404s — this is not
        // hypothetical, it is exactly what happens without an entry
        // here. New tenants get a clean {slug}.{base_domain} host and
        // never need one.
        'host_overrides' => [
            // 'datafort.folksfirstlabs.com' => 'movenetics',
        ],

        // Explicit override for local testing against a hostname that
        // is not a real *.{base_domain} (localhost, an IP, ngrok...).
        // Never consulted ahead of a real matching subdomain.
        'dev_tenant_slug' => null,

        // The ONE database every vhost connects to first, to look up
        // which tenant database to use for the rest of the request.
        // See api/migrations/platform/000_platform_schema.sql.
        'platform_db' => [
            'host' => 'localhost',
            'port' => '3306',
            'name' => 'datafort_platform',
            'user' => 'datafort_platform_app',
            'pass' => 'CHANGE_ME',
        ],

        // Decrypts platform_tenants.db_pass_enc. Base64, 32 bytes.
        // Generate with: openssl rand -base64 32
        // Lives ONLY here — never in the platform database, never in
        // version control. Losing it means every tenant's stored DB
        // password becomes unrecoverable ciphertext; back it up like
        // you would a private key, not like an ordinary config value.
        'tenant_secret_key' => 'CHANGE_ME',

        // ── The platform panel's own session/device settings ──
        // Only read by api/platform/*.php, never by tenant endpoints.
        'platform_session' => [
            'cookie'       => 'df_platform_session',
            'lifetime'     => 60 * 60 * 8,
            'trusted_days' => 14,
            'secure'       => true,
        ],

        // off | log | enforce — same meaning as api/device.php's
        // per-tenant device_enforcement. Ships 'log': the schema and
        // plumbing exist from day one, but the platform panel is not
        // gated on the owner's own laptop cert being enrolled first.
        // Flip to 'enforce' once that is verified working, the same
        // staged rollout every tenant already follows.
        'platform_device_enforcement' => 'log',
    ],

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
        'from_name'  => 'Datafort',
        'from_email' => 'noreply@example.com',
        // Reply-To points at a relay inbox, not the rep's own address —
        // otherwise the rep's mailbox becomes an unlogged side channel.
        // Also where stripe-webhook.php sends the "new paid order" notice.
        'reply_to'      => 'noreply@example.com',
        'smtp_host'     => 'mail.example.com',
        'smtp_port'     => 465,
        'smtp_username' => 'noreply@example.com',
        'smtp_password' => 'CHANGE_ME',
    ],

    // ── Stripe (Checkout + webhook) ──
    // Fails closed: both checkout-start.php and stripe-webhook.php 503
    // while enabled is false or secret_key is still 'CHANGE_ME'.
    'stripe' => [
        'enabled'        => false,

        'secret_key'     => 'CHANGE_ME',   // sk_test_... or sk_live_...

        // From the Stripe Dashboard once the webhook endpoint below is
        // registered there — Developers -> Webhooks -> your endpoint ->
        // "Signing secret". NOT the same thing as the secret key above.
        'webhook_secret' => 'CHANGE_ME',   // whsec_...

        // Must match which kind of secret_key is set above. Guards
        // against a live event ever being accepted while test keys are
        // configured, or vice versa, if the two are ever mismatched.
        'live'           => false,
    ],

    // ── Watermark on revealed values (requirements 7.3) ──
    'watermark' => [
        'enabled'  => true,
        'opacity'  => 0.34,
        'font_size' => 13,
    ],
];
