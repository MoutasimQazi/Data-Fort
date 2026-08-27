<?php
/**
 * register-existing-tenant.php — adds the CURRENT single-tenant
 * deployment to the platform registry as an already-provisioned tenant.
 *
 *   php scripts/register-existing-tenant.php --slug=movenetics --name="Movenetics Digital"
 *
 * This is Phase 1 step 4 of the multi-tenant plan: it inserts one row
 * into platform_tenants pointing at THIS config.php's existing 'db'
 * block — the database this deployment has always used — with every
 * provisioning checkpoint backfilled as already-complete.
 *
 * IT DOES NOT MODIFY THIS VHOST. multi_tenant.enabled in config.php
 * stays whatever it already is. api/db.php keeps connecting exactly
 * as it does today until enabled is deliberately flipped to true —
 * which the plan calls out as a separate, later, tested step, not
 * something this script should do as a side effect. Run this to make
 * the tenant visible in the platform panel; the live site is
 * unaffected either way.
 *
 * Never prints the database password — reads it from config.php,
 * encrypts it, and that's the only place it appears.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script is CLI-only.\n");
}

require_once __DIR__ . '/../api/platform/crypto.php';

function out(string $line): void { fwrite(STDOUT, $line . "\n"); }
function fail(string $line): never { fwrite(STDERR, "ERROR: $line\n"); exit(1); }

$opts = getopt('', ['slug:', 'name:', 'contact-email::']);
$slug = (string) ($opts['slug'] ?? '');
$name = (string) ($opts['name'] ?? '');
if ($slug === '' || $name === '') {
    fail("Usage: php scripts/register-existing-tenant.php --slug=movenetics --name=\"Movenetics Digital\" [--contact-email=admin@moveneticsdigital.com]");
}

$configPath = __DIR__ . '/../api/config.php';
if (!is_file($configPath)) {
    fail("api/config.php not found.");
}
$CONFIG = require $configPath;
$mt = $CONFIG['multi_tenant'] ?? null;
if (!$mt || !isset($mt['platform_db'], $mt['tenant_secret_key'])) {
    fail("config.php has no multi_tenant.platform_db / tenant_secret_key configured yet. " .
         "Add the block from config.sample.php, and run scripts/init-platform-db.php first.");
}
if (empty($CONFIG['db']['name'])) {
    fail("config.php has no 'db' block to register — nothing to point at.");
}

$platformPdo = new PDO(
    "mysql:host={$mt['platform_db']['host']};port={$mt['platform_db']['port']};" .
    "dbname={$mt['platform_db']['name']};charset=utf8mb4",
    $mt['platform_db']['user'], $mt['platform_db']['pass'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$exists = $platformPdo->prepare("SELECT id FROM platform_tenants WHERE subdomain_slug = ?");
$exists->execute([$slug]);
if ($exists->fetch()) {
    fail("A platform_tenants row for '$slug' already exists. This script does not overwrite one.");
}

$encPass = platformEncrypt($CONFIG['db']['pass'], $mt['tenant_secret_key']);

$platformPdo->prepare(
    "INSERT INTO platform_tenants
       (name, subdomain_slug, status, contact_email,
        db_host, db_port, db_name, db_user, db_pass_enc,
        db_provisioned_at, admin_seeded_at, ca_scaffolded_at, vhost_live_at)
     VALUES (?, ?, 'active', ?, ?, ?, ?, ?, ?, NOW(), NOW(), NOW(), NOW())"
)->execute([
    $name, $slug, (string) ($opts['contact-email'] ?? ''),
    $CONFIG['db']['host'], $CONFIG['db']['port'], $CONFIG['db']['name'], $CONFIG['db']['user'], $encPass,
]);

$platformPdo->prepare(
    "INSERT INTO platform_audit_log (tenant_id, action, subject, detail)
     VALUES (LAST_INSERT_ID(), 'tenant_registered_existing', ?, 'Registered pre-existing single-tenant deployment; vhost NOT modified')"
)->execute([$slug]);

out("Registered '$slug' in the platform registry, pointing at the existing database.");
out("This vhost's own behaviour is UNCHANGED — multi_tenant.enabled in its config.php was not touched.");
out("Cutover to routed mode is a separate, later, deliberately-tested step (see the plan).");
