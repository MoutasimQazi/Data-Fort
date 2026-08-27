<?php
/**
 * create-platform-admin.php — the ONLY way a platform_admins row
 * should ever be created.
 *
 * There is deliberately no seed row for this table in
 * api/migrations/platform/000_platform_schema.sql — see that file's
 * closing comment. This project has already shipped one credential
 * into a committed migration once (003_repair_seed_passwords.sql's
 * admin@123, still live in production as of this writing); the single
 * account that can see the registry of every customer does not get a
 * second chance at that mistake.
 *
 *   php scripts/create-platform-admin.php --email=you@yourcompany.com
 *
 * Prompts for a password interactively, with terminal echo turned off
 * where the shell supports it (stty -echo). The password is never
 * written to a file, a log, or an argv the process list can see.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script is CLI-only.\n");
}

function out(string $line): void { fwrite(STDOUT, $line . "\n"); }
function fail(string $line): never { fwrite(STDERR, "ERROR: $line\n"); exit(1); }

function readPasswordHidden(string $prompt): string
{
    fwrite(STDOUT, $prompt);
    $isWindows = strncasecmp(PHP_OS, 'WIN', 3) === 0;

    if (!$isWindows && shell_exec('command -v stty') !== null) {
        shell_exec('stty -echo');
        $password = rtrim((string) fgets(STDIN), "\r\n");
        shell_exec('stty echo');
        fwrite(STDOUT, "\n");
        return $password;
    }

    // No stty available (Windows, or a minimal shell): fall back to a
    // visible prompt rather than silently failing. Real deployment is
    // over SSH on the Linux VPS, where the branch above applies.
    fwrite(STDOUT, "\n(terminal echo cannot be suppressed here — input will be visible)\n");
    fwrite(STDOUT, $prompt);
    return rtrim((string) fgets(STDIN), "\r\n");
}

$opts  = getopt('', ['email:']);
$email = strtolower(trim((string) ($opts['email'] ?? '')));
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fail("--email is required and must be a valid address.");
}

// readline() needs the optional PHP readline extension, not guaranteed
// present (rarely on Windows) — fall back to a plain fgets prompt.
fwrite(STDOUT, "Name: ");
$name = trim(function_exists('readline') ? (string) readline() : (string) fgets(STDIN));
if ($name === '') {
    fail("Name is required.");
}

$password  = readPasswordHidden("Password (12+ chars, upper+lower+digit+symbol): ");
$password2 = readPasswordHidden("Confirm password: ");
if ($password !== $password2) {
    fail("Passwords did not match.");
}
// Same policy api/auth-reset.php enforces for every tenant password —
// no reason the platform account should be held to a lower bar.
if (strlen($password) < 12
    || !preg_match('/[a-z]/', $password) || !preg_match('/[A-Z]/', $password)
    || !preg_match('/\d/', $password)    || !preg_match('/[^\w\s]/', $password)) {
    fail("Password must be at least 12 characters with upper and lower case, a number and a symbol.");
}

$configPath = __DIR__ . '/../api/config.php';
if (!is_file($configPath)) {
    fail("api/config.php not found.");
}
$CONFIG = require $configPath;
$mt = $CONFIG['multi_tenant'] ?? null;
if (empty($mt['enabled'])) {
    fail("multi_tenant.enabled is false in config.php.");
}

$pdo = new PDO(
    "mysql:host={$mt['platform_db']['host']};port={$mt['platform_db']['port']};" .
    "dbname={$mt['platform_db']['name']};charset=utf8mb4",
    $mt['platform_db']['user'], $mt['platform_db']['pass'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$exists = $pdo->prepare("SELECT id FROM platform_admins WHERE email = ?");
$exists->execute([$email]);
if ($exists->fetch()) {
    fail("A platform admin with that email already exists.");
}

$pdo->prepare(
    "INSERT INTO platform_admins (name, email, password_hash, status) VALUES (?, ?, ?, 'active')"
)->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT)]);

$pdo->prepare(
    "INSERT INTO platform_audit_log (actor_name, action, subject, detail)
     VALUES (?, 'platform_admin_created', ?, 'Created via scripts/create-platform-admin.php')"
)->execute([$name, $email]);

out("Platform admin created: $email");
out("Sign in at the platform vhost's /platform/login.html");
