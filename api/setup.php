<?php
/**
 * setup.php — one-time bootstrap and repair tool.
 *
 * ⚠ DELETE THIS FILE FROM THE SERVER WHEN YOU ARE DONE.
 *
 * It does three things:
 *   1. Health check — connection, schema, tenant, GD, HTTPS, mTLS.
 *   2. Creates the first administrator, if none exists.
 *   3. Sets any user's password.
 *
 * (3) exists because the password hashes in the migrations were
 * generated outside PHP. bcrypt $2y$ and $2b$ are byte-identical for
 * ASCII passwords under 255 characters, so they SHOULD verify — but
 * "should" is not "does", and a login you cannot get past is a bad
 * place to discover the difference. This writes the hash with PHP's own
 * password_hash(), so whatever it sets is guaranteed to pass
 * password_verify() on this exact server.
 *
 * Anyone who can reach this file can take over any account. That is
 * exactly why it must not stay on a live server.
 */

declare(strict_types=1);

$configPath = __DIR__ . '/config.php';
$error = null;
$notice = null;
$checks = [];
$pdo = null;
$tenant = null;
$adminCount = 0;
$users = [];

if (!is_file($configPath)) {
    $error = 'api/config.php is missing. Copy api/config.sample.php to api/config.php first. '
           . 'Note it is gitignored, so it does NOT arrive with a git push — upload it manually.';
} else {
    $CONFIG = require $configPath;

    try {
        $pdo = new PDO(
            "mysql:host={$CONFIG['db']['host']};port={$CONFIG['db']['port']};dbname={$CONFIG['db']['name']};charset=utf8mb4",
            $CONFIG['db']['user'],
            $CONFIG['db']['pass'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
             PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
             PDO::ATTR_EMULATE_PREPARES => false]
        );
        $checks['Database'] = 'ok — ' . $CONFIG['db']['name'] . ' on ' . $CONFIG['db']['host'];
    } catch (PDOException $e) {
        $error = 'Cannot connect to the database: ' . $e->getMessage();
    }

    if ($pdo) {
        $need = ['tenants','users','company_devices','device_auth_log','sessions',
                 'login_attempts','password_resets','lead_sources','leads',
                 'lead_reveals','audit_log','security_events'];
        $have = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
        $missing = array_values(array_diff($need, $have));

        if ($missing) {
            $error = 'Schema not loaded. Missing: ' . implode(', ', $missing)
                   . '. Run api/migrations/001_schema.sql.';
        } else {
            $checks['Schema'] = 'ok — all ' . count($need) . ' tables present';

            $t = $pdo->prepare("SELECT * FROM tenants WHERE slug = ? LIMIT 1");
            $t->execute([$CONFIG['tenant_slug']]);
            $tenant = $t->fetch();

            if (!$tenant) {
                $error = 'No tenant row for slug "' . $CONFIG['tenant_slug']
                       . '". Re-run 001_schema.sql.';
            } else {
                $checks['Tenant'] = 'ok — ' . $tenant['name']
                                  . ' · device enforcement: ' . $tenant['device_enforcement'];

                $users = $pdo->prepare(
                    "SELECT id, name, email, role, status, LENGTH(password_hash) AS hlen,
                            LEFT(password_hash, 4) AS hpre
                     FROM users WHERE tenant_id = ? ORDER BY role, email"
                );
                $users->execute([(int) $tenant['id']]);
                $users = $users->fetchAll();

                $adminCount = count(array_filter($users, function ($u) {
                    return $u['role'] === 'admin';
                }));

                $reps = count($users) - $adminCount;
                $checks['Accounts'] = $adminCount . ' admin, ' . $reps . ' rep'
                    . ($reps === 1 ? '' : 's')
                    . ($reps === 0 ? ' — 002_test_data.sql has not been run' : '');

                $checks['Leads'] = (string) $pdo->query(
                    "SELECT COUNT(*) FROM leads WHERE tenant_id = " . (int) $tenant['id']
                )->fetchColumn();
            }
        }
    }
}


/* ══ Actions ═══════════════════════════════════════════════════════ */

$action = $_POST['action'] ?? '';

if ($pdo && $tenant && $action === 'create_admin' && $adminCount === 0) {
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
            "INSERT INTO users (tenant_id, name, email, password_hash, role, daily_quota, status)
             VALUES (?,?,?,?, 'admin', 0, 'active')"
        )->execute([(int) $tenant['id'], $name, $email, password_hash($pw, PASSWORD_DEFAULT)]);

        $notice = 'Administrator created. Delete api/setup.php, then sign in.';
        $adminCount = 1;
    }
}

if ($pdo && $tenant && $action === 'test_password') {
    /* Answers one question and changes nothing: does the hash currently
     * in the database verify against this password, according to THIS
     * server's password_verify()?
     *
     * It matters because the hashes in the migrations were generated
     * with Python's bcrypt and the $2b$ prefix rewritten to $2y$. Those
     * are the same algorithm for ASCII passwords under 255 bytes, so
     * they SHOULD verify — but "should" is not "does", and a login you
     * cannot get past is a bad place to find out. This settles it
     * without touching any data. */
    $uid = (int) ($_POST['user_id'] ?? 0);
    $try = (string) ($_POST['test_value'] ?? '');

    $u = $pdo->prepare("SELECT email, password_hash FROM users WHERE id = ? AND tenant_id = ? LIMIT 1");
    $u->execute([$uid, (int) $tenant['id']]);
    $row = $u->fetch();

    if (!$row) {
        $error = 'That account does not exist.';
    } elseif ($try === '') {
        $error = 'Enter a password to test.';
    } else {
        $stored = (string) $row['password_hash'];
        $match  = password_verify($try, $stored);

        // Would the SAME password hashed here verify? If this says yes
        // while the stored hash says no, the stored hash is the problem.
        $fresh  = password_verify($try, password_hash($try, PASSWORD_DEFAULT));

        $notice = 'TEST for ' . $row['email'] . ' — stored hash '
                . ($match ? 'MATCHES' : 'DOES NOT MATCH')
                . ' this password. Stored prefix ' . substr($stored, 0, 4)
                . ', length ' . strlen($stored) . '. '
                . 'A hash generated on this server right now '
                . ($fresh ? 'verifies correctly' : 'FAILS — PHP bcrypt is broken here')
                . '. '
                . ($match
                    ? 'So the password is right and the hash is fine — the problem is elsewhere.'
                    : 'So either the password is wrong, or this hash was not made for it. Use "Set a password" below.');
    }
}

if ($pdo && $tenant && $action === 'set_password') {
    $uid = (int) ($_POST['user_id'] ?? 0);
    $pw  = (string) ($_POST['new_password'] ?? '');

    if ($uid <= 0 || $pw === '') {
        $error = 'Choose an account and enter a password.';
    } else {
        $u = $pdo->prepare("SELECT email FROM users WHERE id = ? AND tenant_id = ? LIMIT 1");
        $u->execute([$uid, (int) $tenant['id']]);
        $row = $u->fetch();

        if (!$row) {
            $error = 'That account does not exist.';
        } else {
            /* PHP's own hasher. Whatever this writes is guaranteed to
             * pass password_verify() on this server — which is the
             * entire reason this form exists. No policy check here on
             * purpose: this is a repair tool, and refusing to set the
             * weak test password you asked for would defeat its use. */
            $hash = password_hash($pw, PASSWORD_DEFAULT);

            $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?")
                ->execute([$hash, $uid]);

            // Any session opened with the old password dies.
            $pdo->prepare("UPDATE sessions SET revoked_at = NOW() WHERE user_id = ? AND revoked_at IS NULL")
                ->execute([$uid]);

            // Clear the throttle so the next attempt is not refused for
            // the earlier failures.
            $pdo->prepare("DELETE FROM login_attempts WHERE email = ?")->execute([$row['email']]);

            $verified = password_verify($pw, $hash) ? 'and verified' : 'BUT VERIFICATION FAILED';

            $notice = 'Password set for ' . $row['email'] . ' ' . $verified
                    . '. Other sessions revoked, login throttle cleared.';

            $pdo->prepare(
                "INSERT INTO audit_log (tenant_id, actor_name, action, subject, detail, ip)
                 VALUES (?,?,?,?,?,?)"
            )->execute([(int) $tenant['id'], 'setup.php', 'user', $row['email'],
                        'Password set via setup.php', $_SERVER['REMOTE_ADDR'] ?? null]);

            // Refresh the list so hash_len/prefix show the new value.
            $q = $pdo->prepare(
                "SELECT id, name, email, role, status, LENGTH(password_hash) AS hlen,
                        LEFT(password_hash, 4) AS hpre
                 FROM users WHERE tenant_id = ? ORDER BY role, email"
            );
            $q->execute([(int) $tenant['id']]);
            $users = $q->fetchAll();
        }
    }
}


/* ══ Environment checks ════════════════════════════════════════════ */

$checks['GD image library'] = function_exists('imagecreatetruecolor')
    ? 'ok — watermarked reveals will render'
    : 'MISSING — reveals fall back to plain selectable text';

$checks['HTTPS'] = (($_SERVER['HTTPS'] ?? '') === 'on')
    ? 'ok'
    : 'NOT ON — session cookies are set Secure and will not be stored over http://';

$sslVerify = $_SERVER['SSL_CLIENT_VERIFY'] ?? $_SERVER['REDIRECT_SSL_CLIENT_VERIFY'] ?? '';
$checks['Client certificate (mTLS)'] = $sslVerify !== ''
    ? 'Apache reports SSL_CLIENT_VERIFY = ' . $sslVerify
    : 'not configured — expected until the private CA exists';

$checks['PHP'] = PHP_VERSION;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Datafort · Setup</title>
<link rel="icon" href="../brand/favicon.ico" sizes="any">
<link rel="icon" href="../brand/favicon.png" type="image/png">
<meta name="robots" content="noindex, nofollow">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@500;600;700&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../style.css">
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
  table { width:100%; border-collapse:collapse; font-size:12.5px; }
  th { text-align:left; font-size:11px; text-transform:uppercase; letter-spacing:.5px;
       color:var(--text-faint); padding:7px 8px; border-bottom:1px solid var(--border); }
  td { padding:8px; border-bottom:1px solid var(--border-soft); }
  td.mono { font-family:ui-monospace,monospace; }
  .foot { padding:16px 30px; background:color-mix(in srgb, var(--red) 8%, transparent);
          border-top:1px solid var(--border-soft); font-size:12.5px; color:var(--red); }
</style>
</head>
<body>
<div class="box">
  <div class="box__in">

    <h1>Datafort setup</h1>

    <div class="checks">
      <?php foreach ($checks as $label => $result):
        $cls = '';
        if (stripos($result, 'MISSING') !== false || stripos($result, 'NOT ON') !== false
            || stripos($result, 'not been run') !== false) $cls = 'warn';
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


    <?php if ($pdo && $tenant && $adminCount === 0): ?>
      <h2>Create the first administrator</h2>
      <form method="post" style="display:grid;gap:12px">
        <input type="hidden" name="action" value="create_admin">
        <div class="field">
          <label class="field__label" for="name">Full name</label>
          <input class="input" type="text" id="name" name="name" required>
        </div>
        <div class="field">
          <label class="field__label" for="email">Work email</label>
          <input class="input" type="email" id="email" name="email" required>
        </div>
        <div class="field">
          <label class="field__label" for="password">Password</label>
          <input class="input" type="password" id="password" name="password" required>
          <span style="font-size:12px;color:var(--text-faint)">
            12+ characters, upper and lower case, a number and a symbol.
          </span>
        </div>
        <button class="btn btn--primary btn--block" type="submit">Create administrator</button>
      </form>
    <?php endif; ?>


    <?php if ($pdo && $tenant && $users): ?>
      <h2>Accounts</h2>

      <p style="margin:0;font-size:12.5px;color:var(--text-muted);line-height:1.6">
        <strong>Hash</strong> must read <code>$2y$</code> and <strong>60</strong>.
        Anything else means the value was mangled on its way into the
        database — set the password below to repair it.
      </p>

      <table>
        <thead><tr><th>Email</th><th>Role</th><th>State</th><th>Hash</th></tr></thead>
        <tbody>
        <?php foreach ($users as $u):
          $bad = ((int) $u['hlen'] !== 60) || ($u['hpre'] !== '$2y$' && $u['hpre'] !== '$2b$'); ?>
          <tr>
            <td><?= htmlspecialchars($u['email']) ?></td>
            <td><?= htmlspecialchars($u['role']) ?></td>
            <td<?= $u['status'] !== 'active' ? ' class="warn"' : '' ?>><?= htmlspecialchars($u['status']) ?></td>
            <td class="mono <?= $bad ? 'bad' : 'good' ?>">
              <?= htmlspecialchars((string) $u['hpre']) ?> · <?= (int) $u['hlen'] ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>

      <h2>Test a password</h2>
      <p style="margin:0;font-size:12.5px;color:var(--text-muted);line-height:1.6">
        Checks a password against the hash already in the database, using this
        server's <code>password_verify()</code>. Changes nothing. Use it to find
        out whether a failed login is a wrong password or a bad hash.
      </p>

      <form method="post" style="display:grid;gap:12px">
        <input type="hidden" name="action" value="test_password">
        <div class="field">
          <label class="field__label" for="test_user">Account</label>
          <select class="select" id="test_user" name="user_id" style="width:100%">
            <?php foreach ($users as $u): ?>
              <option value="<?= (int) $u['id'] ?>"><?= htmlspecialchars($u['email']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label class="field__label" for="test_value">Password to test</label>
          <input class="input" type="text" id="test_value" name="test_value" value="test@123">
        </div>
        <button class="btn btn--ghost btn--block" type="submit">Test</button>
      </form>

      <h2>Set a password</h2>
      <p style="margin:0;font-size:12.5px;color:var(--text-muted);line-height:1.6">
        Writes the hash with this server's own <code>password_hash()</code>, so it
        is guaranteed to pass <code>password_verify()</code> here. Revokes that
        user's other sessions and clears their login throttle.
      </p>

      <form method="post" style="display:grid;gap:12px">
        <input type="hidden" name="action" value="set_password">
        <div class="field">
          <label class="field__label" for="user_id">Account</label>
          <select class="select" id="user_id" name="user_id" style="width:100%">
            <?php foreach ($users as $u): ?>
              <option value="<?= (int) $u['id'] ?>">
                <?= htmlspecialchars($u['email']) ?> (<?= htmlspecialchars($u['role']) ?>)
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label class="field__label" for="new_password">New password</label>
          <input class="input" type="text" id="new_password" name="new_password"
                 value="test@123" required>
          <span style="font-size:12px;color:var(--text-faint)">
            Shown in plain text on purpose — this is a repair tool, and the
            point is to know exactly what you set.
          </span>
        </div>
        <button class="btn btn--primary btn--block" type="submit">Set password</button>
      </form>
    <?php endif; ?>

  </div>

  <div class="foot">
    <strong>Delete api/setup.php from the server when you are finished.</strong>
    Anyone who can reach this page can set any account's password, including
    an administrator's.
  </div>
</div>
</body>
</html>
