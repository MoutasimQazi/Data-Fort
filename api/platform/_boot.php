<?php
/**
 * _boot.php — bootstrap required by every api/platform/*.php endpoint.
 *
 * The platform equivalent of api/db.php, but simpler: a platform
 * endpoint's "tenant" is never a subdomain, so there is no Host-header
 * resolution step here — it connects directly to the ONE platform
 * database named in config.php.
 *
 * Leading underscore keeps this out of any "list every file in
 * api/platform/ as an endpoint" assumption someone might make later;
 * it is a bootstrap, not a route.
 */

declare(strict_types=1);

require_once __DIR__ . '/../http.php';

$configPath = __DIR__ . '/../config.php';
if (!is_file($configPath)) {
    http_response_code(500);
    echo json_encode(['error' => 'Server not configured.']);
    exit;
}
$CONFIG = require $configPath;

$mt = $CONFIG['multi_tenant'] ?? null;
if (empty($mt['enabled']) || empty($mt['platform_db'])) {
    // Fails closed rather than falling back to $CONFIG['db'] — that
    // would be a tenant's own database, and a platform endpoint must
    // never be able to connect to one.
    http_response_code(500);
    echo json_encode(['error' => 'Platform panel not configured on this vhost.']);
    exit;
}

try {
    $pdo = new PDO(
        "mysql:host={$mt['platform_db']['host']};port={$mt['platform_db']['port']};" .
        "dbname={$mt['platform_db']['name']};charset=utf8mb4",
        $mt['platform_db']['user'],
        $mt['platform_db']['pass'],
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_TIMEOUT            => 10,
        ]
    );
} catch (PDOException $e) {
    error_log('[datafort-platform] db connect failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'error' => empty($CONFIG['debug'])
            ? 'Database connection failed'
            : 'Database connection failed: ' . $e->getMessage(),
    ]);
    exit;
}

require_once __DIR__ . '/auth.php';
