<?php
// auth/guard.php — include this in every user page
// Usage: require_once __DIR__ . '/../auth/guard.php';
declare(strict_types=1);
require_once dirname(__DIR__) . '/bootstrap.php';
$user = require_auth($pdo);
csrf_enforce();

// Maintenance mode check — block users but not admins
if (is_maintenance($pdo) && !auth_admin()) {
    $maintenance_msg = setting($pdo, 'maintenance_message', 'Sistem sedang dalam perbaikan.');
    require dirname(__DIR__) . '/user/maintenance.php';
    exit;
}

// Track pageview (analytics)
track_pageview($pdo, parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));

// Update last_seen user aktif (di-throttle per 60 detik)
if (!empty($user['id'])) {
    $now = time();
    $last_update = (int)($_SESSION['last_seen_synced'] ?? 0);
    if ($now - $last_update >= 60) {
        $_SESSION['last_seen_synced'] = $now;
        try {
            $pdo->prepare("UPDATE users SET last_seen = NOW() WHERE id = ?")->execute([(int)$user['id']]);
        } catch (\Throwable) {}
    }
}

