<?php
/**
 * tenants-save.php — create a tenant registry row, edit it, connect
 * its database, provision its schema, seed its first admin, suspend
 * or reactivate.
 *
 * ── THE ONE DELIBERATE EXCEPTION TO "NEVER TOUCH A TENANT'S DATABASE" ──
 *
 * Every other file under api/platform/ never opens a connection to a
 * tenant's own database — that boundary is real and is what lets this
 * product be sold on "the platform operator cannot see your data."
 *
 * set_database / provision_schema / seed_admin below are the one
 * necessary exception: onboarding a tenant with no shell access means
 * SOMETHING has to be able to create their tables and their first
 * admin account, and on this hosting the only thing that can is PHP
 * running as the platform admin, using credentials THIS SAME ADMIN
 * just typed into the form. It connects only to run schema DDL and
 * seed one row — never to read leads, users, or anything the tenant
 * has put in that database afterwards. Every use is written to
 * platform_audit_log with exactly what ran.
 */

declare(strict_types=1);

require_once __DIR__ . '/_boot.php';
require_once __DIR__ . '/crypto.php';
require_once __DIR__ . '/../tenant-resolver.php';   // for tenantPublicHost()

requireMethod('POST');

$ctx   = platformRequireAuth($pdo, $CONFIG);
$admin = $ctx['admin'];

$in     = body();
$action = (string) ($in['action'] ?? '');

/** Same canonical schema file as scripts/provision-tenant.php — see its own header. */
const TENANT_SCHEMA_FILE = '008_consolidated_schema.sql';

function openTenantConnection(array $row, string $secretKey): PDO
{
    $pass = platformDecrypt($row['db_pass_enc'], $secretKey);
    return new PDO(
        "mysql:host={$row['db_host']};port={$row['db_port']};dbname={$row['db_name']};charset=utf8mb4",
        $row['db_user'], $pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
         PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_TIMEOUT => 8]
    );
}


/* ══ Create ════════════════════════════════════════════════════════ */

if ($action === 'create') {
    $name  = trim((string) ($in['name'] ?? ''));
    $slug  = strtolower(trim((string) ($in['slug'] ?? '')));
    $plan  = trim((string) ($in['plan'] ?? '')) ?: null;
    $cName = trim((string) ($in['contactName'] ?? '')) ?: null;
    $cEmail = strtolower(trim((string) ($in['contactEmail'] ?? '')));

    if ($name === '') {
        respond(['error' => 'A name is required.'], 400);
    }
    if (!preg_match('/^[a-z][a-z0-9-]{1,20}$/', $slug)) {
        respond(['error' => 'Slug must be lowercase letters, digits and hyphens, 2-21 characters, starting with a letter.'], 400);
    }
    if ($cEmail !== '' && !filter_var($cEmail, FILTER_VALIDATE_EMAIL)) {
        respond(['error' => 'Contact email is not valid.'], 400);
    }

    $dupe = $pdo->prepare("SELECT id FROM platform_tenants WHERE subdomain_slug = ?");
    $dupe->execute([$slug]);
    if ($dupe->fetch()) {
        respond(['error' => 'That subdomain is already taken.'], 409);
    }

    // db_name/db_user/db_pass_enc start empty — filled in by
    // 'set_database' below, from the tenant's own detail page.
    $pdo->prepare(
        "INSERT INTO platform_tenants
           (name, subdomain_slug, status, plan, contact_name, contact_email, db_name, db_user, db_pass_enc)
         VALUES (?, ?, 'pending', ?, ?, ?, '', '', '')"
    )->execute([$name, $slug, $plan, $cName, $cEmail]);

    $newId = (int) $pdo->lastInsertId();
    platformAudit($pdo, $admin, 'tenant_create', $slug, "Registry row created for \"$name\"", $newId);

    respond(['ok' => true, 'id' => $newId]);
}


/* ══ Existing tenant ═══════════════════════════════════════════════ */

$targetId = (int) ($in['id'] ?? 0);
if ($targetId <= 0) {
    respond(['error' => 'Tenant id required'], 400);
}

$stmt = $pdo->prepare("SELECT * FROM platform_tenants WHERE id = ? LIMIT 1");
$stmt->execute([$targetId]);
$target = $stmt->fetch();

if (!$target) {
    respond(['error' => 'Tenant not found'], 404);
}

switch ($action) {

    case 'update':
        $name  = trim((string) ($in['name'] ?? $target['name']));
        // planId links to the real catalog (platform_plans); plan is
        // the free-text fallback for a custom deal with no catalog
        // entry. A planId of 0/'' clears the link back to free-text-only.
        $planId = !empty($in['planId']) ? (int) $in['planId'] : null;
        $plan  = trim((string) ($in['plan'] ?? '')) ?: null;
        $cName = trim((string) ($in['contactName'] ?? '')) ?: null;
        $cEmail = strtolower(trim((string) ($in['contactEmail'] ?? '')));

        if ($name === '') {
            respond(['error' => 'Name cannot be empty.'], 400);
        }
        if ($cEmail !== '' && !filter_var($cEmail, FILTER_VALIDATE_EMAIL)) {
            respond(['error' => 'Contact email is not valid.'], 400);
        }
        if ($planId !== null) {
            $planCheck = $pdo->prepare("SELECT id FROM platform_plans WHERE id = ?");
            $planCheck->execute([$planId]);
            if (!$planCheck->fetch()) {
                respond(['error' => 'That plan no longer exists.'], 400);
            }
        }

        $pdo->prepare(
            "UPDATE platform_tenants SET name=?, plan=?, plan_id=?, contact_name=?, contact_email=? WHERE id=?"
        )->execute([$name, $plan, $planId, $cName, $cEmail, $targetId]);

        platformAudit($pdo, $admin, 'tenant_update', $target['subdomain_slug'], 'Registry details edited', $targetId);
        break;


    /* ══ Database connection — create the DB/user yourself in cPanel's
     * MySQL Databases first, then paste the credentials here. Tests
     * the connection before saving anything, so a typo is caught
     * immediately rather than silently stored. ══════════════════════ */

    case 'set_database':
        $host = trim((string) ($in['dbHost'] ?? 'localhost'));
        $port = trim((string) ($in['dbPort'] ?? '3306'));
        $name = trim((string) ($in['dbName'] ?? ''));
        $user = trim((string) ($in['dbUser'] ?? ''));
        $newPass = (string) ($in['dbPass'] ?? '');

        if ($host === '' || $name === '' || $user === '') {
            respond(['error' => 'Host, database name and user are all required.'], 400);
        }

        // Blank password on an edit means "keep the one already stored" —
        // required the first time, since there is nothing to keep yet.
        if ($newPass === '') {
            if ($target['db_pass_enc'] === '') {
                respond(['error' => 'A password is required the first time you connect this database.'], 400);
            }
            $pass = platformDecrypt($target['db_pass_enc'], $CONFIG['multi_tenant']['tenant_secret_key']);
        } else {
            $pass = $newPass;
        }

        try {
            $testPdo = new PDO(
                "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4", $user, $pass,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 8]
            );
            $testPdo = null;
        } catch (PDOException $e) {
            respond(['error' => 'Could not connect with those details: ' . $e->getMessage()], 400);
        }

        $encPass = platformEncrypt($pass, $CONFIG['multi_tenant']['tenant_secret_key']);
        $pdo->prepare(
            "UPDATE platform_tenants
               SET db_host=?, db_port=?, db_name=?, db_user=?, db_pass_enc=?,
                   status = IF(status='pending','provisioning',status)
             WHERE id=?"
        )->execute([$host, $port, $name, $user, $encPass, $targetId]);

        platformAudit($pdo, $admin, 'tenant_database_set', $target['subdomain_slug'],
            "Database connection saved and verified ($host/$name)", $targetId);

        respond(['ok' => true]);


    /* ══ Schema — connects with the credentials just saved and applies
     * 008_consolidated_schema.sql, same file scripts/provision-tenant.php
     * uses. That file has no baked-in seed data — see its own header —
     * so nothing here needs to guard against inheriting anyone else's
     * tenant row or admin account. ═══════════════════════════════════ */

    case 'provision_schema':
        if ($target['db_name'] === '' || $target['db_pass_enc'] === '') {
            respond(['error' => 'Save a database connection first.'], 400);
        }

        try {
            $tenantPdo = openTenantConnection($target, $CONFIG['multi_tenant']['tenant_secret_key']);
        } catch (Throwable $e) {
            respond(['error' => 'Could not connect to the tenant database: ' . $e->getMessage()], 400);
        }

        $path = __DIR__ . '/../migrations/' . TENANT_SCHEMA_FILE;
        if (!is_file($path)) {
            respond(['error' => 'Schema file missing on this server: ' . TENANT_SCHEMA_FILE], 500);
        }

        $statements = array_filter(array_map('trim', explode(';', file_get_contents($path))));
        $applied = 0;
        try {
            foreach ($statements as $statement) {
                $tenantPdo->exec($statement);
                $applied++;
            }
        } catch (Throwable $e) {
            respond(['error' => "Schema failed after $applied of " . count($statements) . " statements: " . $e->getMessage()], 500);
        }

        $pdo->prepare("UPDATE platform_tenants SET db_provisioned_at = NOW() WHERE id = ?")->execute([$targetId]);
        platformAudit($pdo, $admin, 'tenant_schema_provisioned', $target['subdomain_slug'],
            TENANT_SCHEMA_FILE . ' applied (' . $applied . ' statements)', $targetId);

        respond(['ok' => true]);


    /* ══ First admin — same "unusable password + one-time invite link"
     * rule as scripts/provision-tenant.php and every other account this
     * product creates. Safe to click again: reuses the existing row and
     * issues a fresh link instead of creating a duplicate. ═══════════ */

    case 'seed_admin':
        if (empty($target['db_provisioned_at'])) {
            respond(['error' => 'Provision the schema first.'], 400);
        }
        if (!filter_var($target['contact_email'], FILTER_VALIDATE_EMAIL)) {
            respond(['error' => 'Set a valid contact email on this tenant first (Save above).'], 400);
        }

        try {
            $tenantPdo = openTenantConnection($target, $CONFIG['multi_tenant']['tenant_secret_key']);
        } catch (Throwable $e) {
            respond(['error' => 'Could not connect to the tenant database: ' . $e->getMessage()], 400);
        }

        $tRow = $tenantPdo->query("SELECT id FROM tenants LIMIT 1")->fetch();
        if (!$tRow) {
            $tenantPdo->prepare(
                "INSERT INTO tenants (name, slug, default_quota, device_enforcement) VALUES (?, ?, 25, 'off')"
            )->execute([$target['name'], $target['subdomain_slug']]);
            $localTenantId = (int) $tenantPdo->lastInsertId();
        } else {
            $localTenantId = (int) $tRow['id'];
        }

        $uRow = $tenantPdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
        $uRow->execute([$target['contact_email']]);
        $existingAdmin = $uRow->fetch();

        if ($existingAdmin) {
            $adminId = (int) $existingAdmin['id'];
        } else {
            $unusable = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
            $tenantPdo->prepare(
                "INSERT INTO users (tenant_id, name, email, password_hash, role, daily_quota, status)
                 VALUES (?, 'Administrator', ?, ?, 'admin', 0, 'active')"
            )->execute([$localTenantId, $target['contact_email'], $unusable]);
            $adminId = (int) $tenantPdo->lastInsertId();
        }

        $token = bin2hex(random_bytes(32));
        $tenantPdo->prepare(
            "INSERT INTO password_resets (token_hash, user_id, expires_at)
             VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 7 DAY))"
        )->execute([hash('sha256', $token), $adminId]);

        $inviteLink = 'https://' . tenantPublicHost($target['subdomain_slug'], $CONFIG['multi_tenant']) . '/reset.html?token=' . $token;

        $pdo->prepare(
            "UPDATE platform_tenants SET admin_seeded_at = NOW(), status = IF(status='provisioning','active',status) WHERE id = ?"
        )->execute([$targetId]);

        platformAudit($pdo, $admin, 'tenant_admin_seeded', $target['subdomain_slug'],
            ($existingAdmin ? 'Invite link reissued for ' : 'First admin created: ') . $target['contact_email'], $targetId);

        respond(['ok' => true, 'inviteLink' => $inviteLink]);


    /* ══ CA and vhost — these two stay manual on purpose. Generating a
     * CA key from a web request would mean the key touches the web
     * server at all, which is the one thing CERTIFICATES.md treats as
     * non-negotiable; creating an Apache vhost is outside what PHP can
     * reach on shared hosting regardless. These actions only record
     * that a human did the step, honestly, rather than pretend to
     * automate what cannot safely be automated here. ══════════════════ */

    case 'mark_ca_ready':
        $pdo->prepare("UPDATE platform_tenants SET ca_scaffolded_at = NOW() WHERE id = ?")->execute([$targetId]);
        platformAudit($pdo, $admin, 'tenant_ca_marked', $target['subdomain_slug'], 'Confirmed CA generated and placed manually', $targetId);
        respond(['ok' => true]);

    case 'mark_vhost_live':
        $pdo->prepare("UPDATE platform_tenants SET vhost_live_at = NOW() WHERE id = ?")->execute([$targetId]);
        platformAudit($pdo, $admin, 'tenant_vhost_marked', $target['subdomain_slug'], 'Confirmed Apache vhost created manually', $targetId);
        respond(['ok' => true]);


    case 'suspend':
    case 'reactivate':
        if ($target['status'] === 'pending' || $target['status'] === 'provisioning') {
            respond(['error' => 'Cannot change status of a tenant that has not finished provisioning.'], 400);
        }
        $status = $action === 'suspend' ? 'suspended' : 'active';
        $pdo->prepare("UPDATE platform_tenants SET status = ? WHERE id = ?")->execute([$status, $targetId]);

        platformAudit($pdo, $admin, 'tenant_' . $action, $target['subdomain_slug'],
            $action === 'suspend' ? 'Suspended — tenant database untouched' : 'Reactivated', $targetId);
        break;

    default:
        respond(['error' => 'Unknown action'], 400);
}

respond(['ok' => true]);
