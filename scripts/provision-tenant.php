<?php
/**
 * provision-tenant.php — brings a `pending` platform_tenants row to life.
 *
 * Run over SSH, never from the web (see the PHP_SAPI check below):
 *
 *   MYSQL_ADMIN_USER=root MYSQL_ADMIN_PASS='...' \
 *     php scripts/provision-tenant.php --slug=acme
 *
 * Expects a row already sitting in platform_tenants with status='pending'
 * — created through the platform panel's "New tenant" form (name,
 * subdomain slug, contact). This script does everything after that:
 *
 *   1. Creates the tenant's own MySQL database and a dedicated,
 *      least-privilege app user for it.
 *   2. Applies api/migrations/008_consolidated_schema.sql — one exact
 *      file, never a directory glob, so a future dev-only migration
 *      (there are dev/repair-only files in api/migrations/ that must
 *      never reach a paying customer's database — see their own
 *      headers) can't silently reach a new tenant.
 *   3. Seeds that database's local `tenants` row and its first admin
 *      user — with a RANDOM, UNUSABLE password and a reset-link email,
 *      never a plaintext password. This project has already shipped
 *      one seeded credential into production once
 *      (003_repair_seed_passwords.sql's admin@123, still live); this
 *      script exists in part to make sure that never happens again.
 *   4. Scaffolds private-ca/<slug>/ with instructions — it does NOT
 *      generate the CA key itself. CA creation stays human-run and
 *      offline, exactly as CERTIFICATES.md already insists for every
 *      other tenant's CA.
 *   5. Marks the tenant active, logs to platform_audit_log, and prints
 *      the one step that is still genuinely manual: the Apache vhost.
 *
 * Elevated MySQL credentials (CREATE DATABASE / CREATE USER / GRANT)
 * are read from the MYSQL_ADMIN_USER / MYSQL_ADMIN_PASS environment
 * variables ONLY — never from config.php. The running application's
 * platform_db credentials are deliberately least-privilege (see
 * api/migrations/platform/000_platform_schema.sql's GRANT note) and
 * must never be able to create a database on their own.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script is CLI-only and must never be reachable over HTTP.\n");
}

require_once __DIR__ . '/../api/platform/crypto.php';

function out(string $line): void { fwrite(STDOUT, $line . "\n"); }
function fail(string $line): never { fwrite(STDERR, "ERROR: $line\n"); exit(1); }

// ── Arguments ──
$opts = getopt('', ['slug:']);
$slug = (string) ($opts['slug'] ?? '');
if (!preg_match('/^[a-z][a-z0-9-]{1,20}$/', $slug)) {
    fail("--slug is required: lowercase letters, digits, hyphens, 2-21 chars, starting with a letter.\n" .
         "       This becomes the subdomain ({$slug}.{base_domain}), a MySQL database name, and a MySQL username — kept short so all three stay valid.");
}
$dbSlug = str_replace('-', '_', $slug);   // MySQL identifiers don't take hyphens

$adminUser = getenv('MYSQL_ADMIN_USER');
$adminPass = getenv('MYSQL_ADMIN_PASS');
if (!$adminUser || $adminPass === false) {
    fail("Set MYSQL_ADMIN_USER and MYSQL_ADMIN_PASS (elevated MySQL credentials, not the app's) before running this.");
}

// ── Config / platform connection ──
$configPath = __DIR__ . '/../api/config.php';
if (!is_file($configPath)) {
    fail("api/config.php not found. This script reads multi_tenant.platform_db and tenant_secret_key from it.");
}
$CONFIG = require $configPath;
$mt = $CONFIG['multi_tenant'] ?? null;
if (empty($mt['enabled'])) {
    fail("multi_tenant.enabled is false in config.php. Turn it on before provisioning tenants.");
}

$platformPdo = new PDO(
    "mysql:host={$mt['platform_db']['host']};port={$mt['platform_db']['port']};" .
    "dbname={$mt['platform_db']['name']};charset=utf8mb4",
    $mt['platform_db']['user'], $mt['platform_db']['pass'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

$adminPdo = new PDO(
    "mysql:host={$mt['platform_db']['host']};port={$mt['platform_db']['port']};charset=utf8mb4",
    $adminUser, $adminPass,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

// ── Load the pending tenant row ──
$stmt = $platformPdo->prepare("SELECT * FROM platform_tenants WHERE subdomain_slug = ? LIMIT 1");
$stmt->execute([$slug]);
$tenant = $stmt->fetch();

if (!$tenant) {
    fail("No platform_tenants row for slug '$slug'. Create it through the platform panel first.");
}
if ($tenant['status'] === 'active') {
    fail("Tenant '$slug' is already active. This script is not a re-provisioning tool.");
}
// tenants-save.php's 'create' action allows an empty contact email —
// fine for a registry row sitting at 'pending', not fine here: without
// it the seeded admin account has no address to receive the invite
// link, and email is NOT NULL on users.email. Fail before anything is
// created rather than leave a database behind nobody can sign into.
if (!filter_var($tenant['contact_email'], FILTER_VALIDATE_EMAIL)) {
    fail("Tenant '$slug' has no valid contact email on file. " .
         "Set one via the platform panel (tenant detail -> Save) before provisioning.");
}

out("Provisioning '$slug' ({$tenant['name']})...");

// ── Step 1: database + dedicated app user ──
function createTenantDatabase(PDO $adminPdo, string $dbSlug): array
{
    $dbName = "datafort_tenant_{$dbSlug}";
    $dbUser = "dftenant_{$dbSlug}";
    $dbPass = base64_encode(random_bytes(24));   // 32 chars, no shell-hostile characters

    // Identifiers can't be bound params; both are validated against
    // ^[a-z][a-z0-9_]+$ by the --slug check above before reaching here.
    $adminPdo->exec("CREATE DATABASE IF NOT EXISTS `$dbName` CHARACTER SET utf8mb4");
    $adminPdo->exec("CREATE USER IF NOT EXISTS '$dbUser'@'%' IDENTIFIED BY " . $adminPdo->quote($dbPass));

    // audit_log and device_auth_log are append-only by design
    // (008_consolidated_schema.sql's own header: "Without that grant,
    // 'append-only' is a promise rather than a control"). MySQL
    // privileges are additive across scopes, so a table-level REVOKE
    // cannot narrow a broader db.*-level GRANT — the only way to
    // actually withhold UPDATE/DELETE on those two tables is to never
    // grant it at the db.* level and give every other table its own
    // grant instead. Table list is hardcoded, same reasoning as
    // TENANT_SCHEMA_FILE being one exact file rather than a directory
    // glob: a future table needs this list updated deliberately, not
    // silently inherited.
    $adminPdo->exec("GRANT SELECT, INSERT ON `$dbName`.* TO '$dbUser'@'%'");
    foreach ([
        'tenants', 'users', 'company_devices', 'sessions', 'login_attempts',
        'password_resets', 'lead_sources', 'leads', 'lead_reveals', 'security_events',
    ] as $table) {
        $adminPdo->exec("GRANT UPDATE, DELETE ON `$dbName`.`$table` TO '$dbUser'@'%'");
    }
    $adminPdo->exec("FLUSH PRIVILEGES");

    return ['db_name' => $dbName, 'db_user' => $dbUser, 'db_pass' => $dbPass];
}
$db = createTenantDatabase($adminPdo, $dbSlug);
$platformPdo->prepare(
    "UPDATE platform_tenants SET db_name=?, db_user=?, db_pass_enc=?, db_provisioned_at=NOW() WHERE id=?"
)->execute([$db['db_name'], $db['db_user'], platformEncrypt($db['db_pass'], $mt['tenant_secret_key']), $tenant['id']]);
out("  [1/5] database created: {$db['db_name']}");

$tenantPdo = new PDO(
    "mysql:host={$mt['platform_db']['host']};port={$mt['platform_db']['port']};" .
    "dbname={$db['db_name']};charset=utf8mb4",
    $db['db_user'], $db['db_pass'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

// ── Step 2: apply the tenant schema ──
//
// api/migrations/008_consolidated_schema.sql is the single canonical
// schema for a fresh tenant database — see its own header for why it
// replaced the old four-file sequence (001/005/006/007) and, more
// importantly, why that file has no seed block to strip: seeding this
// tenant's row and its first admin is this script's job (below), not
// the schema's.
const TENANT_SCHEMA_FILE = '008_consolidated_schema.sql';

function applyMigrations(PDO $tenantPdo, string $migrationsDir): void
{
    $path = $migrationsDir . '/' . TENANT_SCHEMA_FILE;
    if (!is_file($path)) {
        fail("Schema file missing: " . TENANT_SCHEMA_FILE . " — provisioning stopped so the tenant DB is never silently incomplete.");
    }

    $sql = file_get_contents($path);
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
        $tenantPdo->exec($statement);
    }
}
applyMigrations($tenantPdo, __DIR__ . '/../api/migrations');
out("  [2/5] schema applied (" . TENANT_SCHEMA_FILE . ")");

// ── Step 3: seed the tenant row + first admin, no plaintext password ──
function seedTenantAndAdmin(PDO $tenantPdo, string $slug, string $name, string $adminEmail): string
{
    // The seed block in 001_schema.sql only fires for slug='movenetics';
    // every other tenant gets its row here instead.
    $tenantPdo->prepare(
        "INSERT INTO tenants (name, slug, default_quota, device_enforcement) VALUES (?, ?, 25, 'off')"
    )->execute([$name, $slug]);
    $tenantId = (int) $tenantPdo->lastInsertId();

    // Unusable hash: a random 32-byte value can never be typed in as a
    // password, so this account cannot be signed into until the reset
    // link below is used. Same principle as auth-reset.php's own flow —
    // just entered from the provisioning side instead of "forgot password".
    $unusableHash = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
    $tenantPdo->prepare(
        "INSERT INTO users (tenant_id, name, email, password_hash, role, daily_quota, status)
         VALUES (?, 'Administrator', ?, ?, 'admin', 0, 'active')"
    )->execute([$tenantId, $adminEmail, $unusableHash]);
    $adminId = (int) $tenantPdo->lastInsertId();

    $token = bin2hex(random_bytes(32));
    $tenantPdo->prepare(
        "INSERT INTO password_resets (token_hash, user_id, expires_at)
         VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 7 DAY))"
    )->execute([hash('sha256', $token), $adminId]);

    return $token;
}
$resetToken = seedTenantAndAdmin($tenantPdo, $slug, $tenant['name'], $tenant['contact_email']);
$inviteLink = "https://{$slug}." . ($mt['base_domain'] ?? 'datafort.io') . "/reset.html?token={$resetToken}";
$platformPdo->prepare("UPDATE platform_tenants SET admin_seeded_at=NOW() WHERE id=?")->execute([$tenant['id']]);
out("  [3/5] first admin seeded: {$tenant['contact_email']}");
// Printed, not emailed: SERVER-REQUIREMENTS.md section 5 (SPF for the
// sending domain) is still an open item for the FIRST tenant, so this
// script never assumes mail delivery works. Send the link by whatever
// channel you already know reaches this contact.
out("        invite link (send manually):");
out("        $inviteLink");

// ── Step 4: scaffold the CA folder — key generation stays manual ──
function scaffoldCaFolder(string $privateCaRoot, string $slug, string $tenantName): void
{
    $dir = "$privateCaRoot/$slug";
    if (!is_dir($dir) && !mkdir($dir, 0700, true)) {
        fail("Could not create $dir");
    }
    file_put_contents("$dir/README.md", <<<MD
        # {$tenantName} — device certificate authority

        Not generated by provision-tenant.php on purpose — see CERTIFICATES.md
        for why CA key creation stays offline and human-run.

        Run this on an admin machine that will hold the CA key, never on
        the web server:

        ```
        step certificate create "{$tenantName} Device CA" \\
             company-ca.crt company-ca.key \\
             --profile root-ca --not-after 87600h
        ```

        Then copy ONLY company-ca.crt to the server, at whatever path this
        tenant's Apache vhost's SSLCACertificateFile points to. The .key
        never leaves the machine you created it on.

        See CERTIFICATES.md for the full walkthrough, and
        SERVER-REQUIREMENTS.md for the four Apache directives this
        tenant's vhost needs.
        MD
    );
}
scaffoldCaFolder(__DIR__ . '/../private-ca', $slug, $tenant['name']);
$platformPdo->prepare("UPDATE platform_tenants SET ca_scaffolded_at=NOW() WHERE id=?")->execute([$tenant['id']]);
out("  [4/5] private-ca/{$slug}/ scaffolded (instructions only — CA key is a manual step)");

// ── Step 5: finalize ──
$platformPdo->prepare("UPDATE platform_tenants SET status='active' WHERE id=?")->execute([$tenant['id']]);
$platformPdo->prepare(
    "INSERT INTO platform_audit_log (tenant_id, action, subject, detail) VALUES (?, 'tenant_provisioned', ?, ?)"
)->execute([$tenant['id'], $slug, "Database, schema, first admin and CA folder provisioned"]);
out("  [5/5] platform_tenants marked active");

out("");
out("Still manual — one Apache vhost:");
out("  ServerName    {$slug}." . ($mt['base_domain'] ?? 'datafort.io'));
out("  DocumentRoot  (same shared codebase every tenant vhost uses)");
out("  SSLCACertificateFile   private-ca/{$slug}/company-ca.crt   (once you've generated it — see step 4)");
out("  Then reload Apache, and confirm with: curl -I https://{$slug}." . ($mt['base_domain'] ?? 'datafort.io') . "/login.html");
out("  Once confirmed live: UPDATE platform_tenants SET vhost_live_at = NOW() WHERE subdomain_slug = '{$slug}';");
