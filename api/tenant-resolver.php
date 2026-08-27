<?php
/**
 * tenant-resolver.php — decides which database api/db.php connects to.
 *
 * Single-tenant deployments (multi_tenant.enabled = false, the default
 * for every deployment that predates this file) are untouched: this
 * returns $config['db'] unchanged, so nothing about how Datafort has
 * always worked changes unless someone deliberately opts a vhost in.
 *
 * Multi-tenant deployments (one shared codebase, one Apache vhost per
 * customer, each vhost's Host header naming that customer's subdomain)
 * use the Host header to look up which tenant this request is for in
 * the PLATFORM database, then hand back that tenant's own connection
 * info so db.php opens a connection to THAT tenant's database — never
 * a connection this file holds onto itself. Nothing here ever queries
 * a tenant's leads/users/devices; it only ever reads the registry row
 * needed to find the door to that tenant's own database.
 *
 * This file must never be reachable from api/platform/* endpoints —
 * a platform request is never "for" a tenant subdomain, and calling
 * this from there would be a sign the two request paths have been
 * mixed up. See api/platform/_boot.php, which connects to the
 * platform database directly instead.
 */

declare(strict_types=1);

require_once __DIR__ . '/platform/crypto.php';

/**
 * Returns a $config['db']-shaped array: ['host','port','name','user','pass'].
 *
 * Fails closed: an unrecognised or inactive subdomain gets a 404, not
 * a fallback to any other tenant's database.
 */
function resolveTenantDatabase(array $config): array
{
    $mt = $config['multi_tenant'] ?? ['enabled' => false];

    if (empty($mt['enabled'])) {
        return $config['db'];
    }

    $slug = tenantSlugFromRequest($mt);
    if ($slug === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Could not determine which account this request is for.']);
        exit;
    }

    $platformPdo = openPlatformConnection($mt['platform_db']);

    $stmt = $platformPdo->prepare(
        "SELECT db_host, db_port, db_name, db_user, db_pass_enc
           FROM platform_tenants
          WHERE subdomain_slug = ? AND status = 'active'
          LIMIT 1"
    );
    $stmt->execute([$slug]);
    $row = $stmt->fetch();

    if (!$row) {
        http_response_code(404);
        echo json_encode(['error' => 'Unknown or inactive account.']);
        exit;
    }

    try {
        $pass = platformDecrypt($row['db_pass_enc'], $mt['tenant_secret_key']);
    } catch (Throwable $e) {
        // A wrong or rotated key must fail the request, not fall back to
        // some other password — this is a credential, not a cache.
        error_log('[datafort] tenant credential decrypt failed for ' . $slug . ': ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Account temporarily unavailable.']);
        exit;
    }

    return [
        'host' => $row['db_host'],
        'port' => $row['db_port'],
        'name' => $row['db_name'],
        'user' => $row['db_user'],
        'pass' => $pass,
    ];
}

/**
 * The subdomain this request is for, or '' if it cannot be determined.
 *
 * Checked in order:
 *   1. multi_tenant.host_overrides — an exact Host -> slug map. Exists
 *      for a domain that predates the multi-tenant subdomain scheme,
 *      like "datafort.{base_domain}" itself: stripping base_domain off
 *      that host naively yields the slug "datafort" (the PRODUCT name),
 *      not whichever real tenant happens to live there. Without this,
 *      the first tenant's own production domain resolves to a slug
 *      that was never registered, and every request 404s — this is not
 *      a hypothetical, it is exactly what happened the first time
 *      multi_tenant.enabled was turned on here. Every future tenant
 *      gets a clean {slug}.{base_domain} host and never needs an entry.
 *   2. Generic subdomain stripping — {slug}.{base_domain}.
 *   3. multi_tenant.dev_tenant_slug — local testing against a host that
 *      is not a real *.{base_domain} at all (localhost, an IP, ngrok).
 */
function tenantSlugFromRequest(array $mt): string
{
    $host = strtolower(explode(':', $_SERVER['HTTP_HOST'] ?? '')[0]);

    $overrides = $mt['host_overrides'] ?? [];
    if (isset($overrides[$host]) && $overrides[$host] !== '') {
        return (string) $overrides[$host];
    }

    $base = '.' . strtolower($mt['base_domain'] ?? '');

    if ($base !== '.' && str_ends_with($host, $base)) {
        $slug = substr($host, 0, -strlen($base));
        // A bare base domain (no subdomain) or "www" is not a tenant.
        if ($slug !== '' && $slug !== 'www' && strpos($slug, '.') === false) {
            return $slug;
        }
    }

    return (string) ($mt['dev_tenant_slug'] ?? '');
}

/**
 * A short-lived PDO connection to the platform database, used only to
 * resolve one tenant row. Not reused across requests — PHP's
 * shared-nothing model means there is nothing to reuse it into.
 */
function openPlatformConnection(array $platformDbConfig): PDO
{
    return new PDO(
        "mysql:host={$platformDbConfig['host']};port={$platformDbConfig['port']};" .
        "dbname={$platformDbConfig['name']};charset=utf8mb4",
        $platformDbConfig['user'],
        $platformDbConfig['pass'],
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_TIMEOUT            => 10,
        ]
    );
}
