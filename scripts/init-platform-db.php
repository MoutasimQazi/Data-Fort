<?php
/**
 * init-platform-db.php — one-time setup of the platform database itself.
 *
 * Run once, before provisioning any tenant:
 *
 *   MYSQL_ADMIN_USER=root MYSQL_ADMIN_PASS='...' php scripts/init-platform-db.php
 *
 * Creates the platform database and its own least-privilege app user
 * (the one config.php's multi_tenant.platform_db should then point
 * at), applies api/migrations/platform/000_platform_schema.sql, and
 * stops there — it does NOT create a platform_admins row. That is
 * scripts/create-platform-admin.php's job, deliberately separate so a
 * password never has to pass through this script's environment too.
 *
 * Elevated MySQL credentials come from MYSQL_ADMIN_USER/PASS only, same
 * reasoning as provision-tenant.php: the app's own platform_db user
 * must never itself hold CREATE DATABASE/CREATE USER privileges.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script is CLI-only.\n");
}

function out(string $line): void { fwrite(STDOUT, $line . "\n"); }
function fail(string $line): never { fwrite(STDERR, "ERROR: $line\n"); exit(1); }

$adminUser = getenv('MYSQL_ADMIN_USER');
$adminPass = getenv('MYSQL_ADMIN_PASS');
if (!$adminUser || $adminPass === false) {
    fail("Set MYSQL_ADMIN_USER and MYSQL_ADMIN_PASS before running this.");
}

$configPath = __DIR__ . '/../api/config.php';
if (!is_file($configPath)) {
    fail("api/config.php not found. Copy api/config.sample.php first and fill in multi_tenant (enabled=true, platform_db, tenant_secret_key).");
}
$CONFIG = require $configPath;
$mt = $CONFIG['multi_tenant'] ?? null;
if (empty($mt['enabled'])) {
    fail("multi_tenant.enabled is false in config.php. Turn it on first — see api/config.sample.php's comment.");
}
$pdb = $mt['platform_db'];

$adminPdo = new PDO(
    "mysql:host={$pdb['host']};port={$pdb['port']};charset=utf8mb4",
    $adminUser, $adminPass,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

if (!preg_match('/^[a-z][a-z0-9_]{1,63}$/', $pdb['name']) || !preg_match('/^[a-z][a-z0-9_]{1,31}$/', $pdb['user'])) {
    fail("platform_db.name/user in config.php must be lowercase identifiers — got '{$pdb['name']}' / '{$pdb['user']}'.");
}

$adminPdo->exec("CREATE DATABASE IF NOT EXISTS `{$pdb['name']}` CHARACTER SET utf8mb4");
$adminPdo->exec("CREATE USER IF NOT EXISTS '{$pdb['user']}'@'%' IDENTIFIED BY " . $adminPdo->quote($pdb['pass']));

// Same append-only reasoning as scripts/provision-tenant.php's tenant
// grants — see api/migrations/platform/000_platform_schema.sql's GRANT
// note on platform_audit_log.
$adminPdo->exec("GRANT SELECT, INSERT ON `{$pdb['name']}`.* TO '{$pdb['user']}'@'%'");
foreach ([
    'platform_tenants', 'platform_admins', 'platform_admin_sessions',
    'platform_login_attempts', 'platform_password_resets',
    'platform_devices',
] as $table) {
    $adminPdo->exec("GRANT UPDATE, DELETE ON `{$pdb['name']}`.`$table` TO '{$pdb['user']}'@'%'");
}
$adminPdo->exec("FLUSH PRIVILEGES");
out("[1/2] database '{$pdb['name']}' and user '{$pdb['user']}' ready");

$platformPdo = new PDO(
    "mysql:host={$pdb['host']};port={$pdb['port']};dbname={$pdb['name']};charset=utf8mb4",
    $adminUser, $adminPass,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$sql = file_get_contents(__DIR__ . '/../api/migrations/platform/000_platform_schema.sql');
foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
    $platformPdo->exec($statement);
}
out("[2/2] schema applied");

out("");
out("Next: php scripts/create-platform-admin.php --email=you@yourcompany.com");
