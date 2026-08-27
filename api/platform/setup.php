<?php
/**
 * platform/setup.php — one-time bootstrap for the platform panel.
 * The web-based equivalent of scripts/init-platform-db.php +
 * scripts/create-platform-admin.php + scripts/register-existing-tenant.php,
 * for hosting with no shell access — same role as ../setup.php plays for
 * the tenant side, same safety rule: it refuses to create an admin once
 * one already exists.
 *
 * ⚠ DELETE THIS FILE FROM THE SERVER WHEN YOU ARE DONE.
 *
 * What this does NOT do, and what you still need first:
 *   - Create the platform database and its MySQL user. Do that in
 *     cPanel → MySQL Databases, same way the tenant database was made.
 *   - Load api/migrations/platform/000_platform_schema.sql into it.
 *     Do that in phpMyAdmin: select the database → Import → choose the
 *     file → Go. No shell needed for either step.
 *   - Fill in api/config.php's multi_tenant block with that database's
 *     real name/user/password and a tenant_secret_key
 *     (openssl rand -base64 32, or ask whoever set this up for you —
 *     it must be exactly 32 raw bytes, base64-encoded).
 *
 * Once those three are done, this page creates your platform login and
 * registers this deployment as tenant #1 — both from the browser.
 *
 * Anyone who can reach this file can create a platform admin, which can
 * see the registry of every customer. That is exactly why it must not
 * stay on a live server.
 */

declare(strict_types=1);

$configPath = __DIR__ . '/../config.php';
$error = null;
$notice = null;
$checks = [];
$pdo = null;
$mt = null;
$adminCount = 0;
$tenantCount = 0;

if (!is_file($configPath)) {
    $error = 'api/config.php is missing. Copy api/config.sample.php to api/config.php first.';
} else {
    $CONFIG = require $configPath;
    $mt = $CONFIG['multi_tenant'] ?? null;

    if (empty($mt['enabled'])) {
        $error = 'multi_tenant.enabled is false (or the multi_tenant block is missing) in api/config.php. '
               . 'Add it from api/config.sample.php and set enabled to true first.';
    } elseif (empty($mt['platform_db']['name'])) {
        $error = 'multi_tenant.platform_db is not filled in. It needs the database name, user and '
               . 'password you created in cPanel → MySQL Databases.';
    } else {
        try {
            $pdo = new PDO(
                "mysql:host={$mt['platform_db']['host']};port={$mt['platform_db']['port']};" .
                "dbname={$mt['platform_db']['name']};charset=utf8mb4",
                $mt['platform_db']['user'], $mt['platform_db']['pass'],
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                 PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                 PDO::ATTR_EMULATE_PREPARES => false]
            );
            $checks['Platform database'] = 'ok — ' . $mt['platform_db']['name'] . ' on ' . $mt['platform_db']['host'];
        } catch (PDOException $e) {
            $error = 'Cannot connect to the platform database: ' . $e->getMessage()
                   . '. Check multi_tenant.platform_db in api/config.php matches what cPanel created.';
        }

        if ($pdo) {
            $need = ['platform_tenants', 'platform_admins', 'platform_admin_sessions',
                     'platform_login_attempts', 'platform_password_resets', 'platform_devices',
                     'platform_device_auth_log', 'platform_audit_log'];
            $have = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
            $missing = array_values(array_diff($need, $have));

            if ($missing) {
                $error = 'Platform schema not loaded. Missing: ' . implode(', ', $missing)
                       . '. In phpMyAdmin, select this database, open Import, choose '
                       . 'api/migrations/platform/000_platform_schema.sql, and run it.';
            } else {
                $checks['Platform schema'] = 'ok — all ' . count($need) . ' tables present';

                try {
                    $key = base64_decode((string) ($mt['tenant_secret_key'] ?? ''), true);
                    $checks['tenant_secret_key'] = ($key !== false && strlen($key) === 32)
                        ? 'ok — 32 bytes'
                        : 'INVALID — must be base64 for exactly 32 raw bytes (openssl rand -base64 32)';
                } catch (Throwable $e) {
                    $checks['tenant_secret_key'] = 'INVALID';
                }

                $adminCount = (int) $pdo->query("SELECT COUNT(*) FROM platform_admins")->fetchColumn();
                $checks['Platform admins'] = $adminCount . ' account' . ($adminCount === 1 ? '' : 's');

                $tenantCount = (int) $pdo->query("SELECT COUNT(*) FROM platform_tenants")->fetchColumn();
                $checks['Registered tenants'] = (string) $tenantCount;
            }
        }
    }
}


/* ══ Actions ═══════════════════════════════════════════════════════ */

$action = $_POST['action'] ?? '';

if ($pdo && $action === 'create_admin' && $adminCount === 0) {
    $name  = trim((string) ($_POST['name'] ?? ''));
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $pw    = (string) ($_POST['password'] ?? '');

    if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Enter a name and a valid email address.';
    } elseif (strlen($pw) < 12 || !preg_match('/[a-z]/', $pw) || !preg_match('/[A-Z]/', $pw)
              || !preg_match('/\d/', $pw) || !preg_match('/[^\w\s]/', $pw)) {
        $error = 'Password must be at least 12 characters with upper and lower case, a number and a symbol.';
    } else {
        $pdo->prepare(
            "INSERT INTO platform_admins (name, email, password_hash, status) VALUES (?,?,?, 'active')"
        )->execute([$name, $email, password_hash($pw, PASSWORD_DEFAULT)]);

        $pdo->prepare(
            "INSERT INTO platform_audit_log (actor_name, action, subject, detail)
             VALUES ('platform/setup.php', 'platform_admin_created', ?, 'Created via the setup wizard')"
        )->execute([$email]);

        $notice = 'Platform admin created for ' . $email . '. Sign in at /platform/login.html, then delete this file.';
        $adminCount = 1;
    }
}

if ($pdo && $action === 'register_tenant') {
    require_once __DIR__ . '/crypto.php';

    $slug = strtolower(trim((string) ($_POST['slug'] ?? '')));
    $name = trim((string) ($_POST['name'] ?? ''));
    $contactEmail = strtolower(trim((string) ($_POST['contact_email'] ?? '')));

    if (!preg_match('/^[a-z][a-z0-9-]{1,20}$/', $slug) || $name === '') {
        $error = 'Enter a valid subdomain slug and a name.';
    } elseif (empty($CONFIG['db']['name'])) {
        $error = 'This config.php has no "db" block to register.';
    } else {
        $dupe = $pdo->prepare("SELECT id FROM platform_tenants WHERE subdomain_slug = ?");
        $dupe->execute([$slug]);
        if ($dupe->fetch()) {
            $error = "A tenant with slug '$slug' is already registered.";
        } else {
            $encPass = platformEncrypt($CONFIG['db']['pass'], $mt['tenant_secret_key']);

            $pdo->prepare(
                "INSERT INTO platform_tenants
                   (name, subdomain_slug, status, contact_email,
                    db_host, db_port, db_name, db_user, db_pass_enc,
                    db_provisioned_at, admin_seeded_at, ca_scaffolded_at, vhost_live_at)
                 VALUES (?, ?, 'active', ?, ?, ?, ?, ?, ?, NOW(), NOW(), NOW(), NOW())"
            )->execute([
                $name, $slug, $contactEmail,
                $CONFIG['db']['host'], $CONFIG['db']['port'], $CONFIG['db']['name'], $CONFIG['db']['user'], $encPass,
            ]);

            $pdo->prepare(
                "INSERT INTO platform_audit_log (tenant_id, actor_name, action, subject, detail)
                 VALUES (LAST_INSERT_ID(), 'platform/setup.php', 'tenant_registered_existing', ?, 'Registered via the setup wizard; vhost NOT modified')"
            )->execute([$slug]);

            $notice = "Registered '$slug' pointing at this deployment's own database. This vhost's behaviour is unchanged.";
            $tenantCount++;
        }
    }
}

$checks['PHP'] = PHP_VERSION;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Datafort Platform · Setup</title>
<link rel="icon" href="../../brand/favicon.ico" sizes="any">
<link rel="icon" href="../../brand/favicon.png" type="image/png">
<meta name="robots" content="noindex, nofollow">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@500;600;700&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../../style.css">
<style>
  body { display:grid; place-items:start center; min-height:100dvh; padding:28px 20px;
         background:linear-gradient(155deg,#101012,#0B0B0C 45%,#1E1214); }
  .box { width:100%; max-width:640px; background:var(--surface);
         border-radius:var(--r-lg); box-shadow:var(--shadow-lg); overflow:hidden; }
  .box__in { padding:26px 30px; display:grid; gap:18px; }
  h1 { font-size:21px; }
  h2 { font-size:15px; margin-top:4px; }
  .checks { display:grid; gap:6px; font-size:12.5px; }
  .checks div { display:flex; gap:14px; justify-content:space-between;
                padding:8px 11px; background:var(--surface-alt);
                border:1px solid var(--border-soft); border-radius:var(--r-sm); }
  .checks span:last-child { text-align:right; font-family:ui-monospace,monospace; }
  .warn { color:#B45309; } .bad { color:var(--red); } .good { color:var(--green); }
  .foot { padding:16px 30px; background:color-mix(in srgb, var(--red) 8%, transparent);
          border-top:1px solid var(--border-soft); font-size:12.5px; color:var(--red); }
</style>
</head>
<body>
<div class="box">
  <div class="box__in">

    <h1>Datafort Platform setup</h1>

    <div class="checks">
      <?php foreach ($checks as $label => $result):
        $cls = '';
        if (stripos($result, 'MISSING') !== false || stripos($result, 'INVALID') !== false) $cls = 'bad';
        if (stripos($result, 'ok') === 0) $cls = 'good'; ?>
        <div><span><?= htmlspecialchars((string) $label) ?></span>
             <span class="<?= $cls ?>"><?= htmlspecialchars((string) $result) ?></span></div>
      <?php endforeach; ?>
    </div>

    <?php if ($notice): ?>
      <div class="alert alert--info"><?= htmlspecialchars($notice) ?></div>
    <?php endif; ?>

    <?php if ($error): ?>
      <div class="alert alert--error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>


    <?php if ($pdo && $adminCount === 0): ?>
      <h2>1. Create your platform login</h2>
      <p style="margin:0;font-size:12.5px;color:var(--text-muted);line-height:1.6">
        This is the ONLY place a real password is typed for this account —
        every teammate invited afterwards gets a one-time link instead
        (see the Team page once you're signed in).
      </p>
      <form method="post" style="display:grid;gap:12px">
        <input type="hidden" name="action" value="create_admin">
        <div class="field">
          <label class="field__label" for="name">Full name</label>
          <input class="input" type="text" id="name" name="name" required>
        </div>
        <div class="field">
          <label class="field__label" for="email">Email</label>
          <input class="input" type="email" id="email" name="email" required>
        </div>
        <div class="field">
          <label class="field__label" for="password">Password</label>
          <input class="input" type="password" id="password" name="password" required>
          <span style="font-size:12px;color:var(--text-faint)">
            12+ characters, upper and lower case, a number and a symbol.
          </span>
        </div>
        <button class="btn btn--primary btn--block" type="submit">Create platform admin</button>
      </form>
    <?php elseif ($pdo): ?>
      <div class="alert alert--info">
        A platform admin already exists. This form is disabled — that's
        the safety rule, not a bug. Sign in at
        <code>/platform/login.html</code> instead.
      </div>
    <?php endif; ?>


    <?php if ($pdo && $tenantCount === 0): ?>
      <h2>2. Register this deployment as a tenant</h2>
      <p style="margin:0;font-size:12.5px;color:var(--text-muted);line-height:1.6">
        Points the registry at THIS config.php's existing database.
        Does not touch this vhost's own behaviour — <code>multi_tenant.enabled</code>
        here stays whatever it already is.
      </p>
      <form method="post" style="display:grid;gap:12px">
        <input type="hidden" name="action" value="register_tenant">
        <div class="field">
          <label class="field__label" for="t_name">Company name</label>
          <input class="input" type="text" id="t_name" name="name"
                 value="<?= htmlspecialchars($CONFIG['mail']['from_name'] ?? '') ?>" required>
        </div>
        <div class="field">
          <label class="field__label" for="t_slug">Subdomain slug</label>
          <input class="input" type="text" id="t_slug" name="slug"
                 value="<?= htmlspecialchars($CONFIG['tenant_slug'] ?? '') ?>"
                 style="font-family:ui-monospace,monospace" required>
        </div>
        <div class="field">
          <label class="field__label" for="t_email">Contact email</label>
          <input class="input" type="email" id="t_email" name="contact_email"
                 value="<?= htmlspecialchars($CONFIG['mail']['from_email'] ?? '') ?>">
        </div>
        <button class="btn btn--ghost btn--block" type="submit">Register</button>
      </form>
    <?php elseif ($pdo): ?>
      <div class="alert alert--info"><?= (int) $tenantCount ?> tenant(s) already registered.</div>
    <?php endif; ?>

  </div>

  <div class="foot">
    <strong>Delete api/platform/setup.php from the server when you are finished.</strong>
    Anyone who can reach this page can create a platform admin, which can
    see the registry of every customer.
  </div>
</div>
</body>
</html>
