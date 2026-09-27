<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

$user = require_auth($pdo);
header('Content-Type: application/json');

$action = $_REQUEST['action'] ?? '';

// ── Helper: JSON_CONTAINS compat (MariaDB uses JSON_QUOTE, MySQL uses CAST AS JSON)
function json_uid_condition(): string {
    return "(n.target_type='all' OR (n.target_user_ids IS NOT NULL AND JSON_CONTAINS(n.target_user_ids, JSON_QUOTE(?))))";
}

// ── Helper: get unread count ─────────────────────────────────────────────────
function notif_unread_count(PDO $pdo, int $uid): int {
    try {
        $s = $pdo->prepare(
            "SELECT COUNT(*) FROM notifications n
             LEFT JOIN notification_reads nr ON nr.notification_id=n.id AND nr.user_id=?
             WHERE nr.id IS NULL
               AND " . json_uid_condition() . "
               AND (n.expires_at IS NULL OR n.expires_at > NOW())"
        );
        $s->execute([$uid, (string)$uid]);
        return (int)$s->fetchColumn();
    } catch (\Throwable) {
        // Fallback: hanya 'all' targets
        try {
            $s = $pdo->prepare(
                "SELECT COUNT(*) FROM notifications n
                 LEFT JOIN notification_reads nr ON nr.notification_id=n.id AND nr.user_id=?
                 WHERE nr.id IS NULL AND n.target_type='all'
                   AND (n.expires_at IS NULL OR n.expires_at > NOW())"
            );
            $s->execute([$uid]);
            return (int)$s->fetchColumn();
        } catch (\Throwable) { return 0; }
    }
}

// ── Count unread ──────────────────────────────────────────────────────────────
if ($action === 'count') {
    echo json_encode(['count' => notif_unread_count($pdo, (int)$user['id'])]);
    exit;
}

// ── Mark single as read ───────────────────────────────────────────────────────
if ($action === 'mark_read' && !empty($_POST['id'])) {
    $id = (int)$_POST['id'];
    $pdo->prepare("INSERT IGNORE INTO notification_reads (notification_id, user_id) VALUES (?,?)")
        ->execute([$id, (int)$user['id']]);
    echo json_encode(['ok' => true, 'count' => notif_unread_count($pdo, (int)$user['id'])]);
    exit;
}

// ── Mark all as read ──────────────────────────────────────────────────────────
if ($action === 'mark_all' || $action === 'mark_all_read') {
    try {
        $notifs = $pdo->prepare(
            "SELECT n.id FROM notifications n
             LEFT JOIN notification_reads nr ON nr.notification_id=n.id AND nr.user_id=?
             WHERE nr.id IS NULL
               AND " . json_uid_condition() . "
               AND (n.expires_at IS NULL OR n.expires_at > NOW())"
        );
        $notifs->execute([(int)$user['id'], (string)$user['id']]);
    } catch (\Throwable) {
        // Fallback
        $notifs = $pdo->prepare(
            "SELECT n.id FROM notifications n
             LEFT JOIN notification_reads nr ON nr.notification_id=n.id AND nr.user_id=?
             WHERE nr.id IS NULL AND n.target_type='all'
               AND (n.expires_at IS NULL OR n.expires_at > NOW())"
        );
        $notifs->execute([(int)$user['id']]);
    }
    $stmt = $pdo->prepare("INSERT IGNORE INTO notification_reads (notification_id, user_id) VALUES (?,?)");
    foreach ($notifs->fetchAll() as $n) {
        $stmt->execute([$n['id'], (int)$user['id']]);
    }
    echo json_encode(['ok' => true]);
    exit;
}

echo json_encode(['error' => 'unknown action']);
