<?php
declare(strict_types=1);

require_once __DIR__ . '/_boot.php';

requireMethod('POST');

// Same reasoning as api/auth-logout.php: no platformRequireAuth() call
// here, so an already-expired session can still be cleared.
$pc  = $CONFIG['multi_tenant']['platform_session'] ?? ['cookie' => 'df_platform_session'];
$sid = $_COOKIE[$pc['cookie']] ?? '';

if ($sid !== '') {
    $stmt = $pdo->prepare(
        "SELECT s.admin_id, a.name FROM platform_admin_sessions s
         JOIN platform_admins a ON a.id = s.admin_id WHERE s.id = ? LIMIT 1"
    );
    $stmt->execute([$sid]);
    if ($row = $stmt->fetch()) {
        platformAudit($pdo, ['id' => $row['admin_id'], 'name' => $row['name']], 'login', null, 'Signed out');
    }
}

endPlatformSession($pdo, $CONFIG);
respond(['ok' => true]);
