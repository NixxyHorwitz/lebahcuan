<?php
declare(strict_types=1);
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/auth.php';
staff_require('livechat');
$pageTitle  = 'Live Chat';
$activePage = 'livechat';

// ── Helper: Admit single queue entry to active session ──────
function lc_admit_queue_entry(PDO $pdo, int $queueId): bool {
    $qStmt = $pdo->prepare("SELECT * FROM chat_queue WHERE id=? AND status='waiting'");
    $qStmt->execute([$queueId]);
    $q = $qStmt->fetch();
    if (!$q) return false;

    $user = null;
    if (!empty($q['user_id'])) {
        $uStmt = $pdo->prepare("SELECT * FROM users WHERE id=?");
        $uStmt->execute([$q['user_id']]);
        $user = $uStmt->fetch() ?: null;
    }

    $userName  = $q['user_name'] ?: ($user ? $user['username'] : 'Guest');
    $userEmail = $q['user_email'] ?: ($user ? $user['email'] : null);
    $userId    = $user ? (int)$user['id'] : (!empty($q['user_id']) ? (int)$q['user_id'] : null);
    $initMode  = in_array($q['mode'] ?? '', ['ai','admin'], true) ? $q['mode'] : 'ai';
    $newKey    = bin2hex(random_bytes(16));

    $pdo->prepare("INSERT INTO chat_sessions (session_key,user_id,user_name,user_email,mode,status,created_at,last_message_at) VALUES (?,?,?,?,?,'open',NOW(),NOW())")
        ->execute([$newKey, $userId, $userName, $userEmail, $initMode]);
    $sessId = (int)$pdo->lastInsertId();

    $welcome = setting($pdo, 'chat_welcome_msg', 'Halo! Ada yang bisa kami bantu?');
    $pdo->prepare("INSERT INTO chat_messages (session_id,sender,message) VALUES (?,'system',?)")
        ->execute([$sessId, $welcome]);

    // TG Forum topic creation if enabled
    $token   = setting($pdo, 'lc_tg_token', '');
    $chatId  = setting($pdo, 'lc_tg_chat_id', '');
    $siteUrl = rtrim(setting($pdo, 'lc_site_url', ''), '/');
    if ($chatId && $token) {
        $threadTitle = "{$userName} #{$sessId}";
        $intro = "💬 Sesi Chat Baru (Di-admit oleh Admin)\n"
               . "👤 User: {$userName}\n"
               . "🔑 Session: #{$sessId}\n"
               . "🤖 Mode: " . ($initMode === 'admin' ? 'Admin' : 'AI');

        $consoleLink = $siteUrl ? "{$siteUrl}/console/livechat.php?view={$sessId}" : null;
        $inlineKbd = ['inline_keyboard' => []];
        if ($consoleLink) {
            $inlineKbd['inline_keyboard'][] = [['text' => "🖥️ Buka Console", 'url' => $consoleLink]];
        }
        $inlineKbd['inline_keyboard'][] = [
            ['text' => "📌 Keep", 'callback_data' => "keep_sess:{$sessId}"],
            ['text' => "🔒 Tutup", 'callback_data' => "close_sess:{$sessId}"],
            ['text' => "🗑️ Hapus Sesi", 'callback_data' => "del_thread:{$sessId}"]
        ];

        $ch = curl_init("https://api.telegram.org/bot{$token}/createForumTopic");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode(['chat_id' => $chatId, 'name' => mb_substr($threadTitle, 0, 128)]),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_TIMEOUT => 6
        ]);
        $res = json_decode(curl_exec($ch) ?: '{}', true);
        curl_close($ch);

        if (!empty($res['ok'])) {
            $tgThreadId = $res['result']['message_thread_id'] ?? null;
            $pdo->prepare("UPDATE chat_sessions SET tg_thread_id=? WHERE id=?")->execute([$tgThreadId, $sessId]);
            $ch = curl_init("https://api.telegram.org/bot{$token}/sendMessage");
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode([
                    'chat_id' => $chatId, 'message_thread_id' => $tgThreadId,
                    'text' => $intro, 'reply_markup' => $inlineKbd
                ]),
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_TIMEOUT => 6
            ]);
            curl_exec($ch); curl_close($ch);
        }
    }

    $pdo->prepare("UPDATE chat_queue SET status='ready', assigned_session_key=? WHERE id=?")
        ->execute([$newKey, $queueId]);
    return true;
}

// ── Handle form saves ────────────────────────────────────────
$saved = false; $err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tab = $_POST['tab'] ?? 'settings';

    // Admit single queue
    if ($tab === 'admit_queue') {
        $qid = (int)($_POST['queue_id'] ?? 0);
        if ($qid && lc_admit_queue_entry($pdo, $qid)) {
            $_SESSION['flash_msg'] = '✅ User antrean berhasil dimasukkan ke sesi aktif.';
        } else {
            $_SESSION['flash_err'] = '❌ Gagal memasukkan antrean (mungkin sudah tidak aktif).';
        }
        header('Location: /console/livechat.php?t=manage'); exit;
    }

    // Admit all queue
    if ($tab === 'admit_all_queue') {
        $waiting = $pdo->query("SELECT id FROM chat_queue WHERE status='waiting' ORDER BY id ASC")->fetchAll();
        $cnt = 0;
        foreach ($waiting as $w) {
            if (lc_admit_queue_entry($pdo, (int)$w['id'])) $cnt++;
        }
        $_SESSION['flash_msg'] = "✅ Sebanyak {$cnt} user antrean berhasil dimasukkan ke sesi aktif.";
        header('Location: /console/livechat.php?t=manage'); exit;
    }

    // Remove single queue
    if ($tab === 'remove_queue') {
        $qid = (int)($_POST['queue_id'] ?? 0);
        if ($qid) {
            $pdo->prepare("UPDATE chat_queue SET status='cancelled' WHERE id=?")->execute([$qid]);
            $_SESSION['flash_msg'] = "✅ Antrean #{$qid} berhasil dihapus/dibatalkan.";
        }
        header('Location: /console/livechat.php?t=manage'); exit;
    }

    // Clear all queue
    if ($tab === 'clear_all_queue') {
        $pdo->exec("UPDATE chat_queue SET status='cancelled' WHERE status='waiting'");
        $_SESSION['flash_msg'] = '✅ Seluruh antrean yang menunggu berhasil dibersihkan.';
        header('Location: /console/livechat.php?t=manage'); exit;
    }

    // Delete single active session
    if ($tab === 'delete_active_session') {
        $sid = (int)($_POST['session_id'] ?? 0);
        if ($sid) {
            $chatId = setting($pdo, 'lc_tg_chat_id', '');
            $token  = setting($pdo, 'lc_tg_token', '');
            $sRow   = $pdo->prepare("SELECT tg_thread_id FROM chat_sessions WHERE id=?");
            $sRow->execute([$sid]);
            $tInfo  = $sRow->fetch();
            if ($token && $chatId && !empty($tInfo['tg_thread_id'])) {
                $ch = curl_init("https://api.telegram.org/bot{$token}/deleteForumTopic");
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => json_encode(['chat_id' => $chatId, 'message_thread_id' => (int)$tInfo['tg_thread_id']]),
                    CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_TIMEOUT => 5
                ]);
                curl_exec($ch); curl_close($ch);
            }
            $pdo->prepare("DELETE FROM chat_messages WHERE session_id=?")->execute([$sid]);
            $pdo->prepare("DELETE FROM chat_sessions WHERE id=?")->execute([$sid]);
            $_SESSION['flash_msg'] = "✅ Sesi chat #{$sid} berhasil dihapus permanen.";
        }
        header('Location: /console/livechat.php?t=manage'); exit;
    }

    // Close all active sessions
    if ($tab === 'close_all_active') {
        $chatId = setting($pdo, 'lc_tg_chat_id', '');
        $token  = setting($pdo, 'lc_tg_token', '');
        $opens  = $pdo->query("SELECT id, tg_thread_id FROM chat_sessions WHERE status='open'")->fetchAll();
        $closeMsg = 'Sesi ditutup massal oleh Admin.';
        foreach ($opens as $op) {
            $pdo->prepare("UPDATE chat_sessions SET status='closed' WHERE id=?")->execute([$op['id']]);
            $pdo->prepare("INSERT INTO chat_messages (session_id,sender,message) VALUES (?, 'system', ?)")->execute([$op['id'], $closeMsg]);
            if ($token && $chatId && !empty($op['tg_thread_id'])) {
                $ch = curl_init("https://api.telegram.org/bot{$token}/closeForumTopic");
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => json_encode(['chat_id' => $chatId, 'message_thread_id' => (int)$op['tg_thread_id']]),
                    CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_TIMEOUT => 4
                ]);
                curl_exec($ch); curl_close($ch);
            }
        }
        $_SESSION['flash_msg'] = '✅ Semua sesi aktif berhasil ditutup.';
        header('Location: /console/livechat.php?t=manage'); exit;
    }

    // Switch session mode
    if ($tab === 'switch_mode') {
        $sid = (int)($_POST['session_id'] ?? 0);
        $newMode = in_array($_POST['mode'] ?? '', ['ai','admin']) ? $_POST['mode'] : 'admin';
        if ($sid) {
            $pdo->prepare("UPDATE chat_sessions SET mode=? WHERE id=?")->execute([$newMode, $sid]);
            $modeLabel = $newMode === 'admin' ? 'Admin' : 'AI Assistant';
            $pdo->prepare("INSERT INTO chat_messages (session_id,sender,message) VALUES (?, 'system', ?)")
                ->execute([$sid, "Mode chat dialihkan ke: {$modeLabel}"]);
            $_SESSION['flash_msg'] = "✅ Mode sesi #{$sid} diubah ke {$modeLabel}.";
        }
        header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? '/console/livechat.php?t=manage')); exit;
    }

    // Close session & admit next queue
    if ($tab === 'close_and_admit_next') {
        $sid = (int)($_POST['session_id'] ?? 0);
        if ($sid) {
            $pdo->prepare("UPDATE chat_sessions SET status='closed' WHERE id=?")->execute([$sid]);
            $pdo->prepare("INSERT INTO chat_messages (session_id,sender,message) VALUES (?, 'system', 'Sesi ditutup oleh Admin.')")->execute([$sid]);
        }
        $nextQ = $pdo->query("SELECT id FROM chat_queue WHERE status='waiting' ORDER BY id ASC LIMIT 1")->fetch();
        if ($nextQ && lc_admit_queue_entry($pdo, (int)$nextQ['id'])) {
            $_SESSION['flash_msg'] = "✅ Sesi #{$sid} ditutup dan antrean berikutnya berhasil dimasukkan ke sesi aktif.";
        } else {
            $_SESSION['flash_msg'] = "✅ Sesi #{$sid} ditutup (tidak ada antrean yang menunggu).";
        }
        header('Location: /console/livechat.php?t=manage'); exit;
    }

    // Quick toggle livechat
    if ($tab === 'quick_toggle_livechat') {
        $curr = setting($pdo, 'livechat_enabled', '1');
        $newVal = $curr === '1' ? '0' : '1';
        $pdo->prepare("INSERT INTO settings (`key`,`value`) VALUES ('livechat_enabled',?) ON DUPLICATE KEY UPDATE `value`=?")
            ->execute([$newVal, $newVal]);
        $_SESSION['flash_msg'] = $newVal === '1' ? '🟢 Livechat berhasil DIBUKA (Online).' : '🔴 Livechat berhasil DITUTUP (Offline).';
        header('Location: /console/livechat.php?t=manage'); exit;
    }

    // Quick set max sessions
    if ($tab === 'quick_set_max_sessions') {
        $max = max(0, (int)($_POST['lc_max_active_sessions'] ?? 0));
        $pdo->prepare("INSERT INTO settings (`key`,`value`) VALUES ('lc_max_active_sessions',?) ON DUPLICATE KEY UPDATE `value`=?")
            ->execute([$max, $max]);
        $_SESSION['flash_msg'] = "✅ Batas sesi aktif berhasil diatur ke: " . ($max > 0 ? "{$max} Sesi" : "Unlimited");
        header('Location: /console/livechat.php?t=manage'); exit;
    }

    // Quick set idle timeout
    if ($tab === 'quick_set_idle_timeout') {
        $idle = max(0, (int)($_POST['lc_max_idle_minutes'] ?? 30));
        $pdo->prepare("INSERT INTO settings (`key`,`value`) VALUES ('lc_max_idle_minutes',?) ON DUPLICATE KEY UPDATE `value`=?")
            ->execute([$idle, $idle]);
        $_SESSION['flash_msg'] = "✅ Batas idle timeout sesi berhasil diatur ke: " . ($idle > 0 ? "{$idle} Menit" : "Nonaktif (Tanpa Auto-Close)");
        header('Location: /console/livechat.php?t=manage'); exit;
    }

    if ($tab === 'settings') {
        $keys = [
            'lc_tg_token','lc_tg_chat_id','lc_tg_forum',
            'openai_api_key','openai_model','ai_system_prompt',
            'chat_welcome_msg','chat_ai_enabled','chat_admin_enabled','chat_admin_name','livechat_enabled','lc_site_url',
            'lc_debug_panel','lc_attachment_enabled','lc_offline_msg','lc_max_active_sessions','lc_max_idle_minutes',
        ];
        if (!isset($_POST['lc_attachment_enabled'])) $_POST['lc_attachment_enabled'] = '0';
        if (!isset($_POST['livechat_enabled'])) $_POST['livechat_enabled'] = '0';
        if (!isset($_POST['chat_ai_enabled'])) $_POST['chat_ai_enabled'] = '0';
        if (!isset($_POST['chat_admin_enabled'])) $_POST['chat_admin_enabled'] = '0';
        if (!isset($_POST['lc_debug_panel'])) $_POST['lc_debug_panel'] = '0';

        foreach ($keys as $k) {
            $v = trim($_POST[$k] ?? '');
            $pdo->prepare("INSERT INTO settings (`key`,`value`) VALUES (?,?) ON DUPLICATE KEY UPDATE `value`=?")
                ->execute([$k, $v, $v]);
        }
        $saved = true;
    }

    // Sync Webhook via CURL dari server
    if ($tab === 'sync_webhook') {
        $token   = setting($pdo, 'lc_tg_token', '');
        $siteUrl = rtrim(setting($pdo, 'lc_site_url', ''), '/');
        if (!$siteUrl) {
            // Fallback ke HTTP_HOST
            $scheme  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $siteUrl = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? '');
        }
        $webhookTarget = $siteUrl . '/chat_action?action=tg_webhook';
        
        if (!$token) {
            $_SESSION['wh_result'] = ['ok' => false, 'error' => 'Bot Token belum diisi di Pengaturan.'];
        } else {
            $ch = curl_init("https://api.telegram.org/bot{$token}/setWebhook");
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => json_encode(['url' => $webhookTarget, 'allowed_updates' => ['message', 'callback_query']]),
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
                CURLOPT_TIMEOUT        => 15,
            ]);
            $res = curl_exec($ch);
            $err = curl_error($ch);
            curl_close($ch);
            if ($err) {
                $_SESSION['wh_result'] = ['ok' => false, 'error' => 'CURL Error: ' . $err];
            } else {
                $data = json_decode($res ?: '{}', true);
                $_SESSION['wh_result'] = array_merge($data, ['webhook_url' => $webhookTarget]);
            }
        }
        header('Location: /console/livechat.php?t=webhook'); exit;
    }

    // Check Webhook Info
    if ($tab === 'check_webhook') {
        $token = setting($pdo, 'lc_tg_token', '');
        if (!$token) {
            $_SESSION['wh_info'] = ['ok' => false, 'error' => 'Bot Token belum diisi.'];
        } else {
            $ch = curl_init("https://api.telegram.org/bot{$token}/getWebhookInfo");
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
            $res = curl_exec($ch);
            $err = curl_error($ch);
            curl_close($ch);
            $_SESSION['wh_info'] = $err ? ['ok' => false, 'error' => $err] : (json_decode($res ?: '{}', true) ?: []);
        }
        header('Location: /console/livechat.php?t=webhook'); exit;
    }

    // Close a session
    if ($tab === 'close_session') {
        $sid = (int)($_POST['session_id'] ?? 0);
        if ($sid) {
            $reason = trim($_POST['close_reason'] ?? '');
            $closeMsg = $reason ? "Sesi ditutup oleh Admin. Alasan: {$reason}" : "Sesi ditutup oleh Admin.";
            $pdo->prepare("UPDATE chat_sessions SET status='closed' WHERE id=?")->execute([$sid]);
            $pdo->prepare("INSERT INTO chat_messages (session_id,sender,message) VALUES (?, 'system', ?)")->execute([$sid, $closeMsg]);
        }
    }

    // Reset ALL chat sessions
    if ($tab === 'reset_all_sessions') {
        $chatId = setting($pdo, 'lc_tg_chat_id', '');
        $token  = setting($pdo, 'lc_tg_token', '');
        // Delete all Telegram topics created by bot
        if ($chatId && $token) {
            $threads = $pdo->query("SELECT tg_thread_id FROM chat_sessions WHERE tg_thread_id IS NOT NULL")->fetchAll();
            foreach ($threads as $t) {
                if ((int)$t['tg_thread_id'] > 0) {
                    $ch = curl_init("https://api.telegram.org/bot{$token}/deleteForumTopic");
                    curl_setopt_array($ch, [
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_POST           => true,
                        CURLOPT_POSTFIELDS     => json_encode(['chat_id' => $chatId, 'message_thread_id' => (int)$t['tg_thread_id']]),
                        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
                        CURLOPT_TIMEOUT        => 5,
                    ]);
                    curl_exec($ch); curl_close($ch);
                }
            }
        }
        $pdo->exec("DELETE FROM chat_messages");
        $pdo->exec("DELETE FROM chat_sessions");
        $_SESSION['wh_result'] = ['ok' => true, 'webhook_url' => '(reset) Semua sesi dan topics berhasil dihapus.'];
        header('Location: /console/livechat.php?t=webhook'); exit;
    }

    // Delete all Telegram topics only (keep DB sessions)
    if ($tab === 'delete_tg_topics') {
        $chatId = setting($pdo, 'lc_tg_chat_id', '');
        $token  = setting($pdo, 'lc_tg_token', '');
        $deleted = 0; $failed = 0;
        if ($chatId && $token) {
            $threads = $pdo->query("SELECT id, tg_thread_id FROM chat_sessions WHERE tg_thread_id IS NOT NULL")->fetchAll();
            foreach ($threads as $t) {
                if ((int)$t['tg_thread_id'] <= 0) continue;
                $ch = curl_init("https://api.telegram.org/bot{$token}/deleteForumTopic");
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POST           => true,
                    CURLOPT_POSTFIELDS     => json_encode(['chat_id' => $chatId, 'message_thread_id' => (int)$t['tg_thread_id']]),
                    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
                    CURLOPT_TIMEOUT        => 5,
                ]);
                $res  = curl_exec($ch); curl_close($ch);
                $data = json_decode($res ?: '{}', true);
                if (!empty($data['ok'])) {
                    $pdo->prepare("UPDATE chat_sessions SET tg_thread_id=NULL WHERE id=?")->execute([$t['id']]);
                    $deleted++;
                } else {
                    $failed++;
                }
            }
        }
        $_SESSION['wh_result'] = ['ok' => true, 'webhook_url' => "Topics dihapus: {$deleted}, gagal: {$failed}. (Sesi DB tetap ada)"];
        header('Location: /console/livechat.php?t=webhook'); exit;
    }

    // Nuclear: delete ALL topics (brute force range) — General is protected by Telegram
    if ($tab === 'nuclear_clear_topics') {
        header('Content-Type: application/json');
        $chatId = setting($pdo, 'lc_tg_chat_id', '');
        $token  = setting($pdo, 'lc_tg_token', '');
        if (!$chatId || !$token) {
            echo json_encode(['ok' => false, 'error' => 'Token/ChatID belum diset']);
            exit;
        }
        // Determine range: max tg_thread_id in DB + buffer, min 500
        $maxDb = (int)$pdo->query("SELECT COALESCE(MAX(tg_thread_id),0) FROM chat_sessions WHERE tg_thread_id IS NOT NULL")->fetchColumn();
        $maxRange = max($maxDb + 200, 500);
        $deleted = 0; $failed = 0;
        set_time_limit(120);
        for ($tid = 2; $tid <= $maxRange; $tid++) {
            $ch = curl_init("https://api.telegram.org/bot{$token}/deleteForumTopic");
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => json_encode(['chat_id' => $chatId, 'message_thread_id' => $tid]),
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
                CURLOPT_TIMEOUT        => 4,
            ]);
            $res  = curl_exec($ch); curl_close($ch);
            $data = json_decode($res ?: '{}', true);
            if (!empty($data['ok'])) { $deleted++; } else { $failed++; }
            // Telegram rate limit: max ~30 req/s — small sleep every 10
            if ($tid % 10 === 0) usleep(300000); // 300ms per 10 requests
        }
        // Clear tg_thread_id in DB for all sessions
        $pdo->exec("UPDATE chat_sessions SET tg_thread_id=NULL");
        echo json_encode(['ok' => true, 'deleted' => $deleted, 'failed' => $failed, 'range' => "2–{$maxRange}"]);
        exit;
    }

    // Bulk delete sessions
    if ($tab === 'bulk_delete') {
        $ids = array_filter(array_map('intval', (array)($_POST['session_ids'] ?? [])));
        if ($ids) {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $pdo->prepare("DELETE FROM chat_messages WHERE session_id IN ({$ph})")->execute($ids);
            $pdo->prepare("DELETE FROM chat_sessions WHERE id IN ({$ph})")->execute($ids);
        }
        header('Location: /console/livechat.php'); exit;
    }
}

// ── Load settings ─────────────────────────────────────────────
$cfg = [];
foreach ([
    'lc_tg_token','lc_tg_chat_id','lc_tg_forum',
    'openai_api_key','openai_model','ai_system_prompt',
    'chat_welcome_msg','chat_ai_enabled','chat_admin_enabled','chat_admin_name','livechat_enabled','lc_site_url',
    'lc_debug_panel','lc_attachment_enabled','lc_offline_msg','lc_max_active_sessions','lc_max_idle_minutes',
] as $k) { $cfg[$k] = setting($pdo, $k, ''); }
if (empty($cfg['chat_admin_name'])) $cfg['chat_admin_name'] = 'Admin';
if ($cfg['livechat_enabled'] === '') $cfg['livechat_enabled'] = '1';
if (!isset($cfg['lc_max_idle_minutes']) || $cfg['lc_max_idle_minutes'] === '') $cfg['lc_max_idle_minutes'] = '30';
if (!isset($cfg['lc_attachment_enabled']) || $cfg['lc_attachment_enabled'] === '') $cfg['lc_attachment_enabled'] = '1';
$activeSessCount = (int)$pdo->query("SELECT COUNT(*) FROM chat_sessions WHERE status='open'")->fetchColumn();
$waitingQueueCount = (int)$pdo->query("SELECT COUNT(*) FROM chat_queue WHERE status='waiting'")->fetchColumn();

// ── Load active sessions specifically for manage tab ──────────
$activeSessions = $pdo->query(
    "SELECT s.*, 
        (SELECT COUNT(*) FROM chat_messages m WHERE m.session_id=s.id) as msg_count,
        (SELECT message FROM chat_messages m WHERE m.session_id=s.id ORDER BY m.id DESC LIMIT 1) as last_msg,
        (SELECT created_at FROM chat_messages m WHERE m.session_id=s.id ORDER BY m.id DESC LIMIT 1) as last_msg_time,
        TIMESTAMPDIFF(MINUTE, COALESCE(s.last_message_at, s.created_at), NOW()) as idle_mins
     FROM chat_sessions s 
     WHERE s.status='open' 
     ORDER BY s.last_message_at DESC"
)->fetchAll();

// ── Load waiting queue list specifically for manage tab ───────
$waitingQueueList = $pdo->query(
    "SELECT q.*, 
        TIMESTAMPDIFF(SECOND, q.last_ping_at, NOW()) as ping_ago_sec,
        TIMESTAMPDIFF(MINUTE, q.created_at, NOW()) as wait_mins
     FROM chat_queue q 
     WHERE q.status='waiting' 
     ORDER BY q.id ASC"
)->fetchAll();

$flashMsg = $_SESSION['flash_msg'] ?? null;
$flashErr = $_SESSION['flash_err'] ?? null;
unset($_SESSION['flash_msg'], $_SESSION['flash_err']);

// ── Load sessions ─────────────────────────────────────────────
$sessions = $pdo->query(
    "SELECT s.*, 
        (SELECT COUNT(*) FROM chat_messages m WHERE m.session_id=s.id) as msg_count,
        (SELECT message FROM chat_messages m WHERE m.session_id=s.id ORDER BY m.id DESC LIMIT 1) as last_msg
     FROM chat_sessions s ORDER BY s.last_message_at DESC LIMIT 60"
)->fetchAll();

// ── Active session detail ──────────────────────────────────────
$viewId = (int)($_GET['view'] ?? 0);
$viewMsgs = [];
$viewSess = null;
if ($viewId) {
    $vs = $pdo->prepare("SELECT * FROM chat_sessions WHERE id=?");
    $vs->execute([$viewId]);
    $viewSess = $vs->fetch() ?: null;
    if ($viewSess) {
        $vm = $pdo->prepare("SELECT * FROM chat_messages WHERE session_id=? ORDER BY id ASC");
        $vm->execute([$viewId]);
        $viewMsgs = $vm->fetchAll();
    }
}

// ── Admin reply via AJAX (dipanggil dari JS) ──────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['tab'] ?? '') === 'reply') {
    header('Content-Type: application/json');
    $sid      = (int)($_POST['session_id'] ?? 0);
    $msg      = trim($_POST['reply_msg'] ?? '');
    $adminName = setting($pdo, 'chat_admin_name', 'Admin') ?: 'Admin';
    
    $attachmentPath = null;
    if (setting($pdo, 'lc_attachment_enabled', '1') === '1' && !empty($_FILES['attachment']['tmp_name'])) {
        $f = $_FILES['attachment'];
        if ($f['error'] === UPLOAD_ERR_OK && $f['size'] <= 5*1024*1024) {
            $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg','jpeg','png','gif','pdf','zip','rar'])) {
                $dir = __DIR__ . '/../uploads/chat/' . date('Y/m');
                if (!is_dir($dir)) mkdir($dir, 0777, true);
                $filename = uniqid('att_') . '.' . $ext;
                if (move_uploaded_file($f['tmp_name'], $dir . '/' . $filename)) {
                    $attachmentPath = 'uploads/chat/' . date('Y/m') . '/' . $filename;
                }
            }
        }
    }

    if (!$sid || (!$msg && !$attachmentPath)) { echo json_encode(['ok'=>false,'error'=>'Invalid']); exit; }
    
    $fullMsg = $msg ? "[{$adminName}] {$msg}" : "[{$adminName}] Mengirim lampiran";
    $pdo->prepare("INSERT INTO chat_messages (session_id,sender,message,attachment) VALUES (?,'admin',?,?)")
        ->execute([$sid, $fullMsg, $attachmentPath]);
    $newId = (int)$pdo->lastInsertId();
    $pdo->prepare("UPDATE chat_sessions SET last_message_at=NOW() WHERE id=?")->execute([$sid]);
    
    // Kirim ke Telegram
    $sess = $pdo->prepare("SELECT * FROM chat_sessions WHERE id=?");
    $sess->execute([$sid]); $sessRow = $sess->fetch();
    $token = setting($pdo, 'lc_tg_token', '');
    $chatId = setting($pdo, 'lc_tg_chat_id', '');
    if ($token && $chatId && $sessRow) {
        $params = ['chat_id' => $chatId];
        if ($sessRow['tg_thread_id']) $params['message_thread_id'] = (int)$sessRow['tg_thread_id'];
        
        if ($attachmentPath) {
            $ext = strtolower(pathinfo($attachmentPath, PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg','jpeg','png','gif'])) {
                $method = 'sendPhoto';
                $params['photo'] = new CURLFile(__DIR__ . '/../' . $attachmentPath);
                $params['caption'] = "🖥️ {$adminName}: {$msg}";
            } else {
                $method = 'sendDocument';
                $params['document'] = new CURLFile(__DIR__ . '/../' . $attachmentPath);
                $params['caption'] = "🖥️ {$adminName}: {$msg}";
            }
            $ch = curl_init("https://api.telegram.org/bot{$token}/{$method}");
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>$params]);
            curl_exec($ch); curl_close($ch);
        } else {
            $params['text'] = "🖥️ {$adminName}: {$msg}";
            $ch = curl_init("https://api.telegram.org/bot{$token}/sendMessage");
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,
                CURLOPT_POSTFIELDS=>json_encode($params),CURLOPT_HTTPHEADER=>['Content-Type: application/json']]);
            curl_exec($ch); curl_close($ch);
        }
    }
    echo json_encode(['ok'=>true,'id'=>$newId,'message'=>$fullMsg,'attachment'=>$attachmentPath,'created_at'=>date('Y-m-d H:i:s')]);
    exit;
}

// ── Console poll new messages ──────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'console_poll') {
    header('Content-Type: application/json');
    $sid     = (int)($_GET['session_id'] ?? 0);
    $afterId = (int)($_GET['after_id'] ?? 0);
    if (!$sid) { echo json_encode(['ok'=>false]); exit; }
    $rows = $pdo->prepare("SELECT id,sender,message,attachment,created_at FROM chat_messages WHERE session_id=? AND id>? ORDER BY id ASC LIMIT 50");
    $rows->execute([$sid, $afterId]);
    echo json_encode(['ok'=>true,'messages'=>$rows->fetchAll(PDO::FETCH_ASSOC)]);
    exit;
}

require_once __DIR__ . '/partials/header.php';
?>

<style>
/* ── Livechat Console Theme Styling ── */
.lc-nav-wrapper {
  background: var(--card-bg);
  border: 1px solid var(--border-color);
  border-radius: 14px;
  padding: 6px;
  margin-bottom: 22px;
  box-shadow: 0 4px 20px rgba(0,0,0,0.2);
}
.lc-tabs {
  display: flex;
  gap: 6px;
  overflow-x: auto;
  scrollbar-width: none;
}
.lc-tabs::-webkit-scrollbar { display: none; }
.lc-tab {
  padding: 10px 18px;
  font-size: 13px;
  font-weight: 700;
  color: #94a3b8;
  cursor: pointer;
  border-radius: 10px;
  text-decoration: none;
  display: inline-flex;
  align-items: center;
  gap: 8px;
  white-space: nowrap;
  transition: all .2s cubic-bezier(0.4, 0, 0.2, 1);
  border: 1px solid transparent;
}
.lc-tab:hover {
  color: #f8fafc;
  background: rgba(255,255,255,0.04);
}
.lc-tab.active {
  color: #fff;
  background: linear-gradient(135deg, #f59e0b, #d97706);
  border-color: rgba(245,158,11,0.5);
  box-shadow: 0 4px 14px rgba(245,158,11,0.3);
}

/* Stat Widgets */
.lc-stat-box {
  background: var(--card-bg);
  border: 1px solid var(--border-color);
  border-radius: 14px;
  padding: 14px 18px;
  display: flex;
  align-items: center;
  gap: 14px;
  box-shadow: 0 4px 16px rgba(0,0,0,0.2);
  transition: transform .2s ease, border-color .2s ease;
}
.lc-stat-box:hover {
  transform: translateY(-2px);
  border-color: rgba(245,158,11,0.35);
}
.lc-stat-icon {
  width: 44px;
  height: 44px;
  border-radius: 12px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 20px;
  flex-shrink: 0;
}

/* Session rows */
.sess-row {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 12px 14px;
  background: rgba(255,255,255,0.015);
  border: 1px solid #1c2237;
  border-radius: 12px;
  margin-bottom: 8px;
  transition: all .2s ease;
}
.sess-row:hover {
  background: rgba(255,255,255,0.035);
  border-color: rgba(245,158,11,0.3);
  transform: translateX(2px);
}
.sess-row.selected {
  background: rgba(245,158,11,0.08);
  border-color: rgba(245,158,11,0.4);
}
.sess-cb {
  accent-color: #f59e0b;
  width: 16px;
  height: 16px;
  cursor: pointer;
  flex-shrink: 0;
}
.sess-avatar {
  width: 38px;
  height: 38px;
  border-radius: 12px;
  background: linear-gradient(135deg, rgba(245,158,11,0.25), rgba(217,119,6,0.15));
  border: 1.5px solid rgba(245,158,11,0.35);
  display: flex;
  align-items: center;
  justify-content: center;
  font-weight: 800;
  font-size: 14px;
  color: #fbbf24;
  flex-shrink: 0;
  box-shadow: 0 2px 8px rgba(0,0,0,0.2);
}
.sess-body { flex: 1; min-width: 0; }
.sess-name {
  font-size: 13.5px;
  font-weight: 700;
  color: #f8fafc;
  display: flex;
  align-items: center;
  gap: 6px;
  flex-wrap: wrap;
}
.sess-last {
  font-size: 12px;
  color: #94a3b8;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  margin-top: 3px;
}
.sess-right { text-align: right; flex-shrink: 0; }
.sess-badge {
  display: inline-block;
  font-size: 10.5px;
  font-weight: 700;
  padding: 2.5px 8px;
  border-radius: 6px;
  text-transform: uppercase;
  letter-spacing: 0.5px;
}
.sess-badge.open {
  background: rgba(16, 185, 129, 0.15);
  color: #34d399;
  border: 1px solid rgba(16, 185, 129, 0.3);
}
.sess-badge.closed {
  background: rgba(148, 163, 184, 0.1);
  color: #94a3b8;
  border: 1px solid rgba(148, 163, 184, 0.2);
}
.sess-mode { font-size: 11px; color: #64748b; margin-top: 3px; }

/* Bulk Action Bar */
.bulk-bar {
  display: none;
  align-items: center;
  gap: 12px;
  padding: 10px 16px;
  background: rgba(245,158,11,0.08);
  border: 1px solid rgba(245,158,11,0.25);
  border-radius: 12px;
  margin-bottom: 12px;
  font-size: 12.5px;
  color: #fcd34d;
}
.bulk-bar.visible { display: flex; }

/* Detail Messenger Panel */
.detail-panel {
  background: var(--card-bg);
  border: 1px solid var(--border-color);
  border-radius: 16px;
  overflow: hidden;
  display: flex;
  flex-direction: column;
  box-shadow: 0 8px 30px rgba(0,0,0,0.35);
}
.detail-header {
  padding: 16px 20px;
  border-bottom: 1px solid var(--border-color);
  background: rgba(255,255,255,0.015);
  display: flex;
  align-items: center;
  gap: 12px;
}
.detail-msgs {
  padding: 20px;
  max-height: 480px;
  overflow-y: auto;
  display: flex;
  flex-direction: column;
  gap: 12px;
  background: #090b14;
}
.detail-msgs::-webkit-scrollbar { width: 5px; }
.detail-msgs::-webkit-scrollbar-thumb { background: #1e243d; border-radius: 4px; }
.detail-msgs::-webkit-scrollbar-thumb:hover { background: #2a3356; }

/* Bubbles */
.msg-row { display: flex; }
.msg-row.msg-user { justify-content: flex-end; }
.msg-row.msg-system { justify-content: center; }
.msg-bubble {
  min-width: 60px;
  max-width: 78%;
  padding: 10px 14px;
  font-size: 13px;
  line-height: 1.5;
  word-break: break-word;
  white-space: pre-wrap;
  box-shadow: 0 2px 10px rgba(0,0,0,0.2);
}
.msg-user .msg-bubble {
  background: #1c2237;
  border: 1px solid #2d385b;
  color: #f8fafc;
  border-radius: 14px 14px 4px 14px;
}
.msg-admin .msg-bubble {
  background: linear-gradient(135deg, rgba(245,158,11,0.22), rgba(217,119,6,0.14));
  border: 1px solid rgba(245,158,11,0.35);
  color: #fef3c7;
  border-radius: 14px 14px 14px 4px;
}
.msg-ai .msg-bubble {
  background: rgba(139,92,246,0.15);
  border: 1px solid rgba(139,92,246,0.3);
  color: #ddd6fe;
  border-radius: 14px 14px 14px 4px;
}
.msg-system .msg-bubble {
  background: rgba(255,255,255,0.03);
  border: 1px solid rgba(255,255,255,0.08);
  color: #94a3b8;
  font-size: 11px;
  font-style: italic;
  text-align: center;
  border-radius: 20px;
  padding: 4px 14px;
  box-shadow: none;
}
.msg-time {
  font-size: 10.5px;
  color: #64748b;
  margin-top: 4px;
  padding: 0 2px;
}
.msg-row.msg-user .msg-time { text-align: right; }

.detail-reply {
  padding: 16px 20px;
  border-top: 1px solid var(--border-color);
  background: rgba(255,255,255,0.015);
}

.webhook-url {
  background: #090b14;
  border: 1px solid #1e243d;
  border-radius: 10px;
  padding: 12px 16px;
  font-size: 12px;
  font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
  color: #38bdf8;
  word-break: break-all;
}
</style>

<div class="c-content">

  <!-- Header Title Bar -->
  <div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
    <div>
      <div class="d-flex align-items-center gap-2 mb-1">
        <div style="width:36px;height:36px;border-radius:10px;background:rgba(245,158,11,0.15);border:1px solid rgba(245,158,11,0.3);color:var(--brand);display:flex;align-items:center;justify-content:center;font-size:18px;">
          <i class="ph-bold ph-chats-circle"></i>
        </div>
        <h4 class="mb-0 fw-bold text-white" style="letter-spacing:-0.3px;">Live Chat Support Console</h4>
        <span class="badge bg-dark border border-secondary text-muted px-2 py-1" style="font-size:11px;">Dual Mode AI &amp; Admin</span>
      </div>
      <p class="text-secondary mb-0" style="font-size:13px;">Kelola obrolan langsung pengunjung, manajemen antrean kuota sesi, integrasi Telegram bot forum, dan pengaturan bot AI.</p>
    </div>

    <div class="d-flex align-items-center gap-2">
      <a href="/console/livechat.php?t=<?= urlencode($_GET['t'] ?? ($viewId ? 'manage' : 'sessions')) ?>" class="btn btn-sm btn-outline-secondary d-flex align-items-center gap-1" style="border-radius:10px;padding:8px 14px;font-weight:600;">
        <i class="ph-bold ph-arrow-counter-clockwise"></i> Refresh Status
      </a>
      <a href="/user/livechat.php" target="_blank" class="btn btn-sm btn-outline-warning d-flex align-items-center gap-1" style="border-radius:10px;padding:8px 14px;font-weight:600;">
        <i class="ph-bold ph-arrow-square-out"></i> Tes Widget User
      </a>
    </div>
  </div>

  <!-- Flash Alerts -->
  <?php if ($saved): ?>
  <div class="alert alert-success d-flex align-items-center gap-2 mb-3" style="background:rgba(16,185,129,0.15);border:1px solid rgba(16,185,129,0.3);color:#34d399;padding:12px 18px;border-radius:12px;font-size:13px;">
    <i class="ph-bold ph-check-circle" style="font-size:18px;"></i>
    <span>Pengaturan live chat berhasil diperbarui.</span>
  </div>
  <?php endif; ?>
  <?php if ($flashMsg): ?>
  <div class="alert alert-success d-flex align-items-center gap-2 mb-3" style="background:rgba(16,185,129,0.15);border:1px solid rgba(16,185,129,0.3);color:#34d399;padding:12px 18px;border-radius:12px;font-size:13px;">
    <i class="ph-bold ph-check-circle" style="font-size:18px;"></i>
    <span><?= htmlspecialchars($flashMsg) ?></span>
  </div>
  <?php endif; ?>
  <?php if ($flashErr): ?>
  <div class="alert alert-danger d-flex align-items-center gap-2 mb-3" style="background:rgba(239,68,68,0.15);border:1px solid rgba(239,68,68,0.3);color:#f87171;padding:12px 18px;border-radius:12px;font-size:13px;">
    <i class="ph-bold ph-warning-circle" style="font-size:18px;"></i>
    <span><?= htmlspecialchars($flashErr) ?></span>
  </div>
  <?php endif; ?>
  <?php if (!empty($_GET['replied'])): ?>
  <div class="alert alert-success d-flex align-items-center gap-2 mb-3" style="background:rgba(16,185,129,0.15);border:1px solid rgba(16,185,129,0.3);color:#34d399;padding:12px 18px;border-radius:12px;font-size:13px;">
    <i class="ph-bold ph-paper-plane-tilt" style="font-size:18px;"></i>
    <span>Balasan admin berhasil terkirim.</span>
  </div>
  <?php endif; ?>

  <!-- Navigation Pill Tabs -->
  <div class="lc-nav-wrapper">
    <div class="lc-tabs">
      <a href="/console/livechat.php" class="lc-tab <?= !$viewId && ($_GET['t']??'sessions')==='sessions' ? 'active':'' ?>">
        <i class="ph-bold ph-chats-circle"></i>
        <span>Semua Sesi</span>
        <span class="badge bg-dark border border-secondary text-secondary ms-1" style="font-size:10.5px;"><?= count($sessions) ?></span>
      </a>

      <a href="/console/livechat.php?t=manage" class="lc-tab <?= ($_GET['t']??'')==='manage' ? 'active':'' ?>">
        <i class="ph-bold ph-lightning"></i>
        <span>Manage Antrean &amp; Sesi Aktif</span>
        <?php if ($waitingQueueCount > 0): ?>
          <span class="badge bg-warning text-dark ms-1" style="font-size:10px;font-weight:800;"><?= $waitingQueueCount ?> Antre</span>
        <?php endif; ?>
        <?php if ($activeSessCount > 0): ?>
          <span class="badge bg-success ms-1" style="font-size:10px;font-weight:800;"><?= $activeSessCount ?> Aktif</span>
        <?php endif; ?>
      </a>

      <a href="/console/livechat.php?t=settings" class="lc-tab <?= ($_GET['t']??'')==='settings' ? 'active':'' ?>">
        <i class="ph-bold ph-gear"></i>
        <span>Pengaturan Layanan</span>
      </a>

      <a href="/console/livechat.php?t=webhook" class="lc-tab <?= ($_GET['t']??'')==='webhook' ? 'active':'' ?>">
        <i class="ph-bold ph-link"></i>
        <span>Webhook Telegram</span>
      </a>
    </div>
  </div>

  <?php $activeTab = $_GET['t'] ?? ($viewId ? 'view' : 'sessions'); ?>

  <!-- ═════════════════════════════════════════════════════════ -->
  <!-- ═══ TAB 1: SESSIONS & CHAT VIEW ═════════════════════════ -->
  <!-- ═════════════════════════════════════════════════════════ -->
  <?php if ($activeTab === 'sessions' || $viewId): ?>
  
  <!-- Livechat Quick Stats Row -->
  <div class="row g-3 mb-4">
    <!-- Stat 1: Status Layanan -->
    <div class="col-md-4 col-sm-6">
      <div class="lc-stat-box">
        <div class="lc-stat-icon" style="background:<?= $cfg['livechat_enabled']==='1' ? 'rgba(16,185,129,0.15)' : 'rgba(239,68,68,0.15)' ?>;color:<?= $cfg['livechat_enabled']==='1' ? '#10b981' : '#ef4444' ?>;border:1px solid <?= $cfg['livechat_enabled']==='1' ? 'rgba(16,185,129,0.3)' : 'rgba(239,68,68,0.3)' ?>;">
          <i class="ph-bold <?= $cfg['livechat_enabled']==='1' ? 'ph-broadcast' : 'ph-power' ?>"></i>
        </div>
        <div>
          <div class="text-secondary text-uppercase fw-bold" style="font-size:11px;letter-spacing:0.5px;">Status Layanan</div>
          <div class="fw-bold" style="font-size:15px;color:<?= $cfg['livechat_enabled']==='1' ? '#34d399' : '#f87171' ?>;margin-top:2px;">
            <?= $cfg['livechat_enabled']==='1' ? '🟢 Online (Terbuka)' : '🔴 Offline (Tertutup)' ?>
          </div>
        </div>
      </div>
    </div>

    <!-- Stat 2: Sesi Aktif vs Quota -->
    <div class="col-md-4 col-sm-6">
      <div class="lc-stat-box">
        <div class="lc-stat-icon" style="background:rgba(245,158,11,0.15);color:#f59e0b;border:1px solid rgba(245,158,11,0.3);">
          <i class="ph-bold ph-chats-teardrop"></i>
        </div>
        <div>
          <div class="text-secondary text-uppercase fw-bold" style="font-size:11px;letter-spacing:0.5px;">Sesi Aktif / Batas Kuota</div>
          <div class="text-white fw-bold" style="font-size:15px;margin-top:2px;">
            <?= $activeSessCount ?> <span style="font-size:12px;color:#94a3b8;font-weight:normal;">/ <?= (int)($cfg['lc_max_active_sessions'] ?? 0) > 0 ? (int)$cfg['lc_max_active_sessions'] . ' Sesi' : 'Unlimited' ?></span>
          </div>
        </div>
      </div>
    </div>

    <!-- Stat 3: User Menunggu Antrean -->
    <div class="col-md-4 col-sm-12">
      <div class="lc-stat-box">
        <div class="lc-stat-icon" style="background:<?= $waitingQueueCount > 0 ? 'rgba(245,158,11,0.15)' : 'rgba(16,185,129,0.15)' ?>;color:<?= $waitingQueueCount > 0 ? '#f59e0b' : '#10b981' ?>;border:1px solid <?= $waitingQueueCount > 0 ? 'rgba(245,158,11,0.3)' : 'rgba(16,185,129,0.3)' ?>;">
          <i class="ph-bold ph-hourglass-high"></i>
        </div>
        <div>
          <div class="text-secondary text-uppercase fw-bold" style="font-size:11px;letter-spacing:0.5px;">User Menunggu Antrean</div>
          <div class="fw-bold" style="font-size:15px;color:<?= $waitingQueueCount > 0 ? '#fbbf24' : '#34d399' ?>;margin-top:2px;">
            <?= $waitingQueueCount ?> User <?= $waitingQueueCount > 0 ? 'Menunggu' : '(Antrean Kosong)' ?>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="row g-3">
    <!-- Session List Column -->
    <div class="<?= $viewId ? 'col-lg-4' : 'col-12' ?>">
      <div class="c-card">
        <div class="c-card-header d-flex align-items-center justify-content-between">
          <div class="d-flex align-items-center gap-2">
            <i class="ph-bold ph-list-dashes text-warning"></i>
            <span class="c-card-title mb-0">Daftar Sesi Obrolan</span>
          </div>
          <span class="badge bg-dark border border-secondary text-secondary" style="font-size:11px;font-weight:600;">
            <?= count($sessions) ?> sesi terbaru
          </span>
        </div>

        <div class="c-card-body p-3">
          <!-- Bulk action toolbar -->
          <form method="post" id="bulk-form">
            <input type="hidden" name="tab" value="bulk_delete">

            <div class="bulk-bar" id="bulk-bar">
              <input type="checkbox" id="cb-all" class="sess-cb" onchange="toggleAll(this)" title="Pilih semua">
              <span id="bulk-count" class="fw-bold">0 dipilih</span>
              <button type="submit" onclick="return confirmDelete()"
                class="btn btn-sm btn-danger ms-auto d-flex align-items-center gap-1"
                style="padding:5px 12px;border-radius:8px;font-size:11.5px;font-weight:700;">
                <i class="ph-bold ph-trash"></i> Hapus Terpilih
              </button>
            </div>

            <?php if (empty($sessions)): ?>
              <div class="text-center py-5">
                <div style="font-size:32px;color:#64748b;margin-bottom:8px;"><i class="ph-bold ph-chat-teardrop-slash"></i></div>
                <div class="text-white fw-bold" style="font-size:13.5px;">Belum Ada Sesi Chat</div>
                <div class="text-secondary" style="font-size:12px;">Sesi baru akan muncul otomatis saat user mengirim pesan.</div>
              </div>
            <?php else: ?>
              <div class="d-flex flex-column">
                <?php foreach ($sessions as $s): ?>
                <div class="sess-row <?= ($viewId === (int)$s['id']) ? 'selected' : '' ?>" id="sess-row-<?= $s['id'] ?>">
                  <input type="checkbox" name="session_ids[]" value="<?= $s['id'] ?>" class="sess-cb sess-check" onchange="onCheckChange()">
                  
                  <div class="sess-avatar">
                    <?= strtoupper(substr((string)$s['user_name'], 0, 1)) ?>
                  </div>

                  <div class="sess-body">
                    <div class="sess-name">
                      <span><?= htmlspecialchars((string)$s['user_name']) ?></span>
                      <?php if ($s['user_email']): ?>
                        <span style="font-size:11px;color:#64748b;font-weight:normal;">(<?= htmlspecialchars((string)$s['user_email']) ?>)</span>
                      <?php endif; ?>
                    </div>
                    <div class="sess-last">
                      <?= htmlspecialchars(mb_substr($s['last_msg'] ?? '(Belum ada pesan)', 0, 60)) ?>
                    </div>
                  </div>

                  <div class="sess-right">
                    <span class="sess-badge <?= $s['status'] ?>"><?= $s['status'] ?></span>
                    <div class="sess-mode">
                      <?= $s['mode'] === 'admin' ? '👨‍💼 Admin' : '🤖 AI' ?> &middot; <?= $s['msg_count'] ?> pesan
                    </div>
                    <div style="margin-top:6px;display:flex;gap:4px;justify-content:flex-end;">
                      <a href="/console/livechat.php?view=<?= $s['id'] ?>" class="btn btn-sm d-flex align-items-center gap-1"
                         style="background:#1a2035;border:1px solid #283252;color:#f8fafc;padding:3px 10px;font-size:11px;border-radius:6px;text-decoration:none;font-weight:600;">
                        <i class="ph-bold ph-chat-centered-text"></i> Detail
                      </a>
                      <?php if ($s['status'] === 'open'): ?>
                      <form method="post" style="margin:0;" onsubmit="return promptCloseSession(this);">
                        <input type="hidden" name="tab" value="close_session">
                        <input type="hidden" name="session_id" value="<?= $s['id'] ?>">
                        <input type="hidden" name="close_reason" value="">
                        <button type="submit"
                          class="btn btn-sm btn-outline-danger"
                          style="padding:3px 8px;font-size:11px;border-radius:6px;font-weight:700;">
                          Tutup
                        </button>
                      </form>
                      <?php endif; ?>
                    </div>
                  </div>
                </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </form>
        </div>
      </div>
    </div>

    <!-- Multi-select & prompt close script -->
    <script>
    function onCheckChange() {
      const checks = document.querySelectorAll('.sess-check');
      const checked = document.querySelectorAll('.sess-check:checked');
      const bar = document.getElementById('bulk-bar');
      const cbAll = document.getElementById('cb-all');
      document.getElementById('bulk-count').textContent = checked.length + ' dipilih';
      bar.classList.toggle('visible', checked.length > 0);
      cbAll.indeterminate = checked.length > 0 && checked.length < checks.length;
      cbAll.checked = checked.length === checks.length && checks.length > 0;
      checks.forEach(c => c.closest('.sess-row')?.classList.toggle('selected', c.checked));
    }
    function toggleAll(cb) {
      document.querySelectorAll('.sess-check').forEach(c => {
        c.checked = cb.checked;
        c.closest('.sess-row')?.classList.toggle('selected', cb.checked);
      });
      onCheckChange();
    }
    function confirmDelete() {
      const n = document.querySelectorAll('.sess-check:checked').length;
      return n > 0 && confirm('Hapus ' + n + ' sesi beserta seluruh pesannya? Tindakan ini tidak bisa dibatalkan.');
    }
    document.addEventListener('DOMContentLoaded', () => {
      if (document.querySelectorAll('.sess-check').length > 0) {
        document.getElementById('bulk-bar').style.display = 'flex';
        document.getElementById('bulk-bar').classList.remove('visible');
        document.getElementById('bulk-bar').style.display = '';
      }
    });
    function promptCloseSession(form) {
      const reason = prompt("Masukkan alasan penutupan sesi chat (opsional/bisa dikosongkan):", "");
      if (reason === null) return false;
      form.querySelector('input[name="close_reason"]').value = reason.trim();
      return true;
    }
    </script>

    <!-- Detail Chat Panel Column -->
    <?php if ($viewId && $viewSess): ?>
    <div class="col-lg-8">
      <div class="detail-panel" id="detail-panel">
        <!-- Header -->
        <div class="detail-header">
          <div class="sess-avatar">
            <?= strtoupper(substr((string)$viewSess['user_name'], 0, 1)) ?>
          </div>
          <div style="flex:1;min-width:0;">
            <div class="d-flex align-items-center gap-2 flex-wrap">
              <span style="font-weight:700;font-size:14.5px;color:#f8fafc;"><?= htmlspecialchars((string)$viewSess['user_name']) ?></span>
              <span class="badge bg-dark border border-secondary text-secondary" style="font-size:10.5px;">Session #<?= $viewId ?></span>
            </div>
            <div style="font-size:11.5px;color:#94a3b8;margin-top:2px;">
              <?= htmlspecialchars((string)($viewSess['user_email'] ?? '-')) ?> &nbsp;&middot;&nbsp;
              Mode: <strong style="color:#a78bfa;" id="dp-mode"><?= strtoupper($viewSess['mode']) ?></strong> &nbsp;&middot;&nbsp;
              Status: <strong style="color:<?= $viewSess['status']==='open'?'#34d399':'#94a3b8' ?>;" id="dp-status"><?= strtoupper($viewSess['status']) ?></strong>
            </div>
          </div>
          <a href="/console/livechat.php" class="btn btn-sm btn-outline-secondary d-flex align-items-center justify-content-center" style="width:34px;height:34px;border-radius:10px;" title="Tutup Detail">
            <i class="ph-bold ph-x"></i>
          </a>
        </div>

        <!-- Messages stream -->
        <div class="detail-msgs" id="detail-msgs">
          <?php foreach ($viewMsgs as $m): ?>
          <div class="msg-row msg-<?= $m['sender'] ?>" data-id="<?= $m['id'] ?>">
            <div>
              <div class="msg-bubble">
                <?php if ($m['attachment']): ?>
                  <?php $ext = strtolower(pathinfo((string)$m['attachment'], PATHINFO_EXTENSION)); ?>
                  <?php if (in_array($ext, ['jpg','jpeg','png','gif'])): ?>
                    <div style="margin-bottom:8px;">
                      <a href="/<?= $m['attachment'] ?>" target="_blank">
                        <img src="/<?= $m['attachment'] ?>" style="max-width:100%;max-height:220px;border-radius:10px;border:1px solid rgba(255,255,255,0.12);object-fit:cover;">
                      </a>
                    </div>
                  <?php else: ?>
                    <div style="margin-bottom:8px;">
                      <a href="/<?= $m['attachment'] ?>" target="_blank" style="display:inline-flex;align-items:center;gap:6px;padding:6px 12px;background:rgba(255,255,255,.08);border-radius:8px;text-decoration:none;color:inherit;border:1px solid rgba(255,255,255,.15);font-size:12px;font-weight:700;">
                        <i class="ph-bold ph-paperclip"></i> Unduh Lampiran (<?= strtoupper($ext) ?>)
                      </a>
                    </div>
                  <?php endif; ?>
                <?php endif; ?>
                <?= nl2br(htmlspecialchars((string)$m['message'])) ?>
              </div>
              <div class="msg-time"><?= date('H:i', strtotime($m['created_at'])) ?></div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>

        <!-- Reply footer -->
        <?php if ($viewSess['status'] === 'open'): ?>
        <div class="detail-reply" id="detail-reply">
          <div class="d-flex gap-2 align-items-end">
            <div style="flex:1;display:flex;flex-direction:column;gap:6px;position:relative;">
              <div id="console-attachment-preview" style="display:none;font-size:11.5px;background:#1a2035;color:#93c5fd;border-radius:8px;padding:6px 10px;border:1px solid #283252;">
                <i class="ph-bold ph-file me-1"></i>
                <span id="console-att-preview-name" style="font-weight:600;"></span>
                <span style="color:#f87171;cursor:pointer;float:right;padding:0 4px;" onclick="clearConsoleAttachment()">&times; Hapus</span>
              </div>
              <textarea id="console-reply-input" class="c-form-control" rows="2"
                placeholder="Ketik balasan sebagai <?= htmlspecialchars($cfg['chat_admin_name']) ?>..."
                style="background:#090b14;border-color:#1e243d;border-radius:10px;width:100%;resize:none;"
                onkeydown="if(event.key==='Enter'&&!event.shiftKey){event.preventDefault();sendConsoleReply();}"
              ></textarea>
            </div>

            <?php if (setting($pdo, 'lc_attachment_enabled', '1') === '1'): ?>
            <button type="button" onclick="document.getElementById('console-attachment-input').click()"
              class="btn btn-outline-secondary d-flex align-items-center justify-content-center"
              style="height:44px;width:44px;border-radius:10px;border-color:#1e243d;color:#94a3b8;flex-shrink:0;" title="Lampirkan File/Gambar">
              <i class="ph-bold ph-paperclip" style="font-size:18px;"></i>
            </button>
            <input type="file" id="console-attachment-input" style="display:none;" accept="image/jpeg,image/png,image/gif,application/pdf,.zip,.rar" onchange="previewConsoleAttachment(this)">
            <?php endif; ?>

            <button type="button" onclick="sendConsoleReply()" id="console-reply-btn"
              class="btn d-flex align-items-center justify-content-center gap-1"
              style="background:linear-gradient(135deg,#f59e0b,#d97706);color:#fff;font-weight:700;font-size:13px;height:44px;padding:0 18px;border-radius:10px;border:none;flex-shrink:0;box-shadow:0 4px 12px rgba(245,158,11,0.25);">
              <i class="ph-bold ph-paper-plane-tilt"></i> Kirim
            </button>
          </div>
          <div class="d-flex align-items-center justify-content-between mt-2 flex-wrap gap-1" style="font-size:11px;color:#64748b;">
            <span>Membalas sebagai <strong class="text-white"><?= htmlspecialchars($cfg['chat_admin_name']) ?></strong> &mdash; otomatis sinkron ke thread Telegram.</span>
            <span>Tekan <kbd style="background:#1a2035;color:#94a3b8;border:1px solid #283252;padding:1px 4px;border-radius:4px;">Enter</kbd> untuk kirim</span>
          </div>
        </div>
        <?php else: ?>
        <div style="padding:14px 20px;color:#94a3b8;font-size:12.5px;text-align:center;background:rgba(255,255,255,0.01);border-top:1px solid var(--border-color);">
          <i class="ph-bold ph-lock me-1"></i> Sesi obrolan ini sudah ditutup.
        </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Active Chat Poller and AJAX script -->
    <script>
    const CONSOLE_SESSION_ID = <?= $viewId ?>;
    const dm = document.getElementById('detail-msgs');
    if (dm) dm.scrollTop = dm.scrollHeight;
    let consolePollTimer = null;
    let consoleLastId    = <?= !empty($viewMsgs) ? (int)end($viewMsgs)['id'] : 0 ?>;

    function appendConsoleBubble(sender, message, time, id, attachment = null) {
      const row = document.createElement('div');
      row.className = `msg-row msg-${sender}`;
      row.dataset.id = id;
      const t = time ? new Date(time).toLocaleTimeString('id-ID',{hour:'2-digit',minute:'2-digit'}) : '';
      
      let attHtml = '';
      if (attachment) {
          const ext = attachment.split('.').pop().toLowerCase();
          if (['jpg','jpeg','png','gif'].includes(ext)) {
              attHtml = `<div style="margin-bottom:8px;"><a href="/${attachment}" target="_blank"><img src="/${attachment}" style="max-width:100%;max-height:220px;border-radius:10px;border:1px solid rgba(255,255,255,0.12);object-fit:cover;"></a></div>`;
          } else {
              attHtml = `<div style="margin-bottom:8px;"><a href="/${attachment}" target="_blank" style="display:inline-flex;align-items:center;gap:6px;padding:6px 12px;background:rgba(255,255,255,.08);border-radius:8px;text-decoration:none;color:inherit;border:1px solid rgba(255,255,255,.15);font-size:12px;font-weight:700;"><i class="ph-bold ph-paperclip"></i> Unduh Lampiran (${ext.toUpperCase()})</a></div>`;
          }
      }
      
      row.innerHTML = `<div><div class="msg-bubble">${attHtml}${nl2html(message)}</div><div class="msg-time">${t}</div></div>`;
      dm.appendChild(row);
      dm.scrollTop = dm.scrollHeight;
    }

    function nl2html(s) {
      return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/\n/g,'<br>');
    }

    function previewConsoleAttachment(input) {
      const file = input.files[0];
      if (file) {
          if (file.size > 5 * 1024 * 1024) {
              alert('Maksimal ukuran file lampiran adalah 5MB.');
              clearConsoleAttachment();
              return;
          }
          document.getElementById('console-attachment-preview').style.display = 'block';
          document.getElementById('console-att-preview-name').textContent = file.name;
      }
    }

    function clearConsoleAttachment() {
      const input = document.getElementById('console-attachment-input');
      if (input) input.value = '';
      document.getElementById('console-attachment-preview').style.display = 'none';
    }

    async function sendConsoleReply() {
      const input = document.getElementById('console-reply-input');
      const attInput = document.getElementById('console-attachment-input');
      const btn   = document.getElementById('console-reply-btn');
      const msg   = input.value.trim();
      const file  = attInput && attInput.files[0] ? attInput.files[0] : null;

      if (!msg && !file) return;

      input.value = ''; btn.disabled = true; btn.innerHTML = '<i class="ph-bold ph-spinner ph-spin"></i>';
      if (file) {
          document.getElementById('console-attachment-preview').style.display = 'none';
          if (attInput) attInput.value = '';
      }

      try {
        const fd = new FormData();
        fd.append('tab', 'reply');
        fd.append('session_id', CONSOLE_SESSION_ID);
        fd.append('reply_msg', msg);
        if (file) fd.append('attachment', file);
        
        const res  = await fetch('/console/livechat.php', {method:'POST', body:fd});
        const data = await res.json();
        if (data.ok) {
          appendConsoleBubble('admin', data.message, data.created_at, data.id, data.attachment);
          if (data.id > consoleLastId) consoleLastId = data.id;
        }
      } catch(e) { alert('Gagal mengirim balasan: ' + e.message); }
      btn.disabled = false; btn.innerHTML = '<i class="ph-bold ph-paper-plane-tilt"></i> Kirim';
      input.focus();
    }

    async function consolePoll() {
      try {
        const res  = await fetch(`/console/livechat.php?action=console_poll&session_id=${CONSOLE_SESSION_ID}&after_id=${consoleLastId}`);
        const data = await res.json();
        if (!data.ok) return;
        (data.messages||[]).forEach(m => {
          if (parseInt(m.id) > consoleLastId) {
            consoleLastId = parseInt(m.id);
            appendConsoleBubble(m.sender, m.message, m.created_at, m.id, m.attachment);
          }
        });
      } catch {}
    }
    consolePollTimer = setInterval(consolePoll, 3000);
    </script>
    <?php endif; ?>
  </div>
  <?php endif; ?>


  <!-- ═════════════════════════════════════════════════════════ -->
  <!-- ═══ TAB 2: MANAGE SESSIONS & QUEUE ══════════════════════ -->
  <!-- ═════════════════════════════════════════════════════════ -->
  <?php if ($activeTab === 'manage'): ?>

  <!-- Top Quick Control Bar -->
  <div class="c-card mb-4" style="background:var(--card-bg);border:1px solid var(--border-color);">
    <div class="c-card-body p-3">
      <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
        
        <!-- Left: Status Service Toggle -->
        <div class="d-flex align-items-center gap-3">
          <div style="width:42px;height:42px;border-radius:12px;background:<?= $cfg['livechat_enabled']==='1' ? 'rgba(16,185,129,0.15)' : 'rgba(239,68,68,0.15)' ?>;border:1px solid <?= $cfg['livechat_enabled']==='1' ? 'rgba(16,185,129,0.3)' : 'rgba(239,68,68,0.3)' ?>;color:<?= $cfg['livechat_enabled']==='1' ? '#10b981' : '#ef4444' ?>;display:flex;align-items:center;justify-content:center;font-size:20px;">
            <i class="ph-bold <?= $cfg['livechat_enabled']==='1' ? 'ph-broadcast' : 'ph-power' ?>"></i>
          </div>
          <div>
            <div style="font-size:11px;color:#94a3b8;text-transform:uppercase;font-weight:700;letter-spacing:0.5px;">Status Layanan LiveChat</div>
            <div style="font-size:14.5px;font-weight:800;color:#fff;">
              <?= $cfg['livechat_enabled']==='1' ? '🟢 BUKA (Online)' : '🔴 TUTUP (Offline)' ?>
            </div>
          </div>
          <form method="POST" class="ms-2 mb-0">
            <input type="hidden" name="tab" value="quick_toggle_livechat">
            <button type="submit" class="btn btn-sm <?= $cfg['livechat_enabled']==='1' ? 'btn-outline-danger' : 'btn-outline-success' ?>" style="font-weight:700;font-size:12px;border-radius:8px;padding:6px 14px;">
              <?= $cfg['livechat_enabled']==='1' ? '🔴 Tutup LiveChat' : '🟢 Buka LiveChat' ?>
            </button>
          </form>
        </div>

        <!-- Right: Quota Setting, Idle Timeout & Actions -->
        <div class="d-flex align-items-center gap-2 flex-wrap">
          <div class="text-end d-none d-lg-block me-2">
            <div style="font-size:10.5px;color:#94a3b8;font-weight:700;">KUOTA AKTIF</div>
            <div style="font-size:13px;font-weight:800;color:#34d399;">
              <?= $activeSessCount ?> Terpakai <span style="font-size:11px;color:#64748b;font-weight:normal;">/ <?= (int)($cfg['lc_max_active_sessions'] ?? 0) > 0 ? (int)$cfg['lc_max_active_sessions'] . ' Maks' : 'Unlimited' ?></span>
            </div>
          </div>

          <!-- Quick Max Sessions Form -->
          <form method="POST" class="d-flex align-items-center gap-1 mb-0" style="background:#090b14;padding:4px 8px;border-radius:10px;border:1px solid #1e243d;">
            <input type="hidden" name="tab" value="quick_set_max_sessions">
            <label style="font-size:11.5px;color:#94a3b8;font-weight:600;white-space:nowrap;margin:0;padding-left:4px;">Batas Sesi:</label>
            <input type="number" name="lc_max_active_sessions" value="<?= (int)($cfg['lc_max_active_sessions'] ?? 0) ?>" min="0" step="1" 
                   class="form-control form-control-sm bg-dark text-white border-secondary" style="width:58px;text-align:center;font-weight:bold;height:30px;" placeholder="0">
            <button type="submit" class="btn btn-sm btn-primary" style="font-size:11.5px;padding:3px 10px;height:30px;font-weight:700;">Set</button>
          </form>

          <!-- Quick Idle Timeout Form -->
          <form method="POST" class="d-flex align-items-center gap-1 mb-0" style="background:#090b14;padding:4px 8px;border-radius:10px;border:1px solid #1e243d;">
            <input type="hidden" name="tab" value="quick_set_idle_timeout">
            <label style="font-size:11.5px;color:#94a3b8;font-weight:600;white-space:nowrap;margin:0;padding-left:4px;">Idle Timeout:</label>
            <input type="number" name="lc_max_idle_minutes" value="<?= (int)($cfg['lc_max_idle_minutes'] ?? 30) ?>" min="0" step="1" 
                   class="form-control form-control-sm bg-dark text-white border-secondary" style="width:58px;text-align:center;font-weight:bold;height:30px;" placeholder="30">
            <span style="font-size:11px;color:#64748b;">mnt</span>
            <button type="submit" class="btn btn-sm btn-primary" style="font-size:11.5px;padding:3px 10px;height:30px;font-weight:700;">Set</button>
          </form>

          <a href="/console/livechat.php?t=manage" class="btn btn-sm btn-outline-secondary d-flex align-items-center justify-content-center" style="height:32px;width:32px;border-radius:8px;" title="Refresh Data">
            <i class="ph-bold ph-arrow-counter-clockwise"></i>
          </a>
        </div>

      </div>
    </div>
  </div>

  <div class="row g-3">
    
    <!-- 🟢 KOLOM KIRI: SESI CHAT AKTIF -->
    <div class="col-lg-6">
      <div class="c-card h-100" style="border-top:3px solid #10b981;">
        <div class="c-card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
          <div>
            <div class="d-flex align-items-center gap-2">
              <i class="ph-bold ph-chats-circle text-success" style="font-size:16px;"></i>
              <span class="c-card-title mb-0" style="color:#34d399;">Sesi Chat Aktif (<?= count($activeSessions) ?>)</span>
            </div>
            <div style="font-size:11.5px;color:#94a3b8;margin-top:2px;">User yang sedang terhubung dan berkomunikasi langsung.</div>
          </div>
          <?php if (!empty($activeSessions)): ?>
          <form method="POST" onsubmit="return confirm('TUTUP SEMUA SESI AKTIF? Semua sesi chat yang sedang berjalan akan ditutup.');" class="mb-0">
            <input type="hidden" name="tab" value="close_all_active">
            <button type="submit" class="btn btn-sm btn-outline-danger d-flex align-items-center gap-1" style="font-size:11px;padding:4px 10px;border-radius:8px;font-weight:700;">
              <i class="ph-bold ph-lock"></i> Tutup Semua Sesi
            </button>
          </form>
          <?php endif; ?>
        </div>

        <div class="c-card-body p-3">
          <?php if (empty($activeSessions)): ?>
            <div class="text-center py-5">
              <div style="width:54px;height:54px;border-radius:14px;background:rgba(255,255,255,0.03);border:1px solid rgba(255,255,255,0.08);margin:0 auto 10px;display:flex;align-items:center;justify-content:center;font-size:24px;color:#64748b;">
                <i class="ph-bold ph-chats"></i>
              </div>
              <div class="fw-bold text-white mb-1" style="font-size:13.5px;">Tidak Ada Sesi Chat Aktif</div>
              <div class="text-secondary" style="font-size:11.5px;">User baru atau user dari antrean akan muncul di sini saat mulai chat.</div>
            </div>
          <?php else: ?>
            <div class="d-flex flex-column gap-2">
              <?php foreach ($activeSessions as $as): ?>
              <?php 
                $idleLimit = (int)($cfg['lc_max_idle_minutes'] ?? 30);
                $isNearTimeout = ($idleLimit > 0 && (int)$as['idle_mins'] >= max(1, $idleLimit - 5));
              ?>
              <div style="background:#0c0e18;border:1px solid #1c2237;border-radius:12px;padding:14px;position:relative;">
                <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                  <div class="d-flex align-items-center gap-2">
                    <div class="sess-avatar" style="width:34px;height:34px;font-size:12px;">
                      <?= strtoupper(substr((string)$as['user_name'], 0, 1)) ?>
                    </div>
                    <div>
                      <div style="font-size:13px;font-weight:bold;color:#f8fafc;">
                        <?= htmlspecialchars((string)$as['user_name']) ?>
                        <?php if ($as['user_id']): ?>
                          <a href="/console/user_detail.php?id=<?= $as['user_id'] ?>" class="badge bg-dark border border-secondary text-secondary text-decoration-none ms-1" style="font-size:10px;" target="_blank">#UID: <?= $as['user_id'] ?></a>
                        <?php else: ?>
                          <span class="badge bg-dark border text-muted ms-1" style="font-size:10px;">Guest</span>
                        <?php endif; ?>
                      </div>
                      <?php if ($as['user_email']): ?>
                        <div style="font-size:11px;color:#64748b;"><?= htmlspecialchars((string)$as['user_email']) ?></div>
                      <?php endif; ?>
                    </div>
                  </div>

                  <div class="text-end">
                    <span class="badge <?= $as['mode']==='admin'?'bg-info text-dark':'bg-primary' ?>" style="font-size:10px;font-weight:700;">
                      <?= $as['mode']==='admin' ? '👨‍💼 Admin' : '🤖 AI' ?>
                    </span>
                    <?php if ($isNearTimeout): ?>
                      <div class="badge bg-danger mt-1 d-block" style="font-size:9.5px;">⚠️ Idle <?= (int)$as['idle_mins'] ?>/<?= $idleLimit ?> mnt (Auto-Close)</div>
                    <?php elseif ($idleLimit > 0): ?>
                      <div style="font-size:10px;color:#64748b;margin-top:2px;">Idle: <?= (int)$as['idle_mins'] ?>/<?= $idleLimit ?> mnt</div>
                    <?php else: ?>
                      <div style="font-size:10px;color:#64748b;margin-top:2px;">Idle: <?= (int)$as['idle_mins'] ?> mnt (No Limit)</div>
                    <?php endif; ?>
                  </div>
                </div>

                <!-- Last Message -->
                <div style="background:#131726;border-radius:8px;padding:8px 12px;font-size:12px;color:#94a3b8;margin-bottom:12px;border-left:3px solid var(--brand);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                  <strong style="color:#e2e8f0;">Pesan:</strong> <?= htmlspecialchars((string)($as['last_msg'] ?: '(Belum ada pesan baru)')) ?>
                </div>

                <!-- Action Buttons -->
                <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 pt-2" style="border-top:1px dashed #1e243d;">
                  <div class="d-flex gap-1">
                    <a href="/console/livechat.php?view=<?= $as['id'] ?>" class="btn btn-sm btn-primary d-flex align-items-center gap-1" style="font-size:11px;padding:4px 10px;border-radius:6px;font-weight:600;">
                      <i class="ph-bold ph-eye"></i> Buka Chat
                    </a>
                    <form method="POST" class="d-inline mb-0">
                      <input type="hidden" name="tab" value="switch_mode">
                      <input type="hidden" name="session_id" value="<?= $as['id'] ?>">
                      <input type="hidden" name="mode" value="<?= $as['mode']==='admin' ? 'ai' : 'admin' ?>">
                      <button type="submit" class="btn btn-sm btn-outline-secondary d-flex align-items-center gap-1" style="font-size:11px;padding:4px 8px;border-radius:6px;" title="Ganti mode obrolan AI / Admin">
                        <?= $as['mode']==='admin' ? '🤖 Switch AI' : '👨‍💼 Switch Admin' ?>
                      </button>
                    </form>
                  </div>

                  <div class="d-flex gap-1">
                    <?php if (!empty($waitingQueueList)): ?>
                    <form method="POST" class="d-inline mb-0" onsubmit="return confirm('Tutup sesi ini dan langsung masukkan 1 antrean terdepan ke sesi aktif?');">
                      <input type="hidden" name="tab" value="close_and_admit_next">
                      <input type="hidden" name="session_id" value="<?= $as['id'] ?>">
                      <button type="submit" class="btn btn-sm btn-outline-warning d-flex align-items-center gap-1" style="font-size:11px;padding:4px 8px;border-radius:6px;" title="Tutup sesi ini & masukkan antrean berikutnya">
                        <i class="ph-bold ph-fast-forward"></i> Tutup &amp; Next
                      </button>
                    </form>
                    <?php endif; ?>

                    <form method="POST" class="d-inline mb-0" onsubmit="return promptCloseSession(this);">
                      <input type="hidden" name="tab" value="close_session">
                      <input type="hidden" name="session_id" value="<?= $as['id'] ?>">
                      <input type="hidden" name="close_reason" value="">
                      <button type="submit" class="btn btn-sm btn-outline-warning d-flex align-items-center gap-1" style="font-size:11px;padding:4px 8px;border-radius:6px;" title="Tutup sesi chat ini">
                        <i class="ph-bold ph-lock"></i> Tutup
                      </button>
                    </form>

                    <form method="POST" class="d-inline mb-0" onsubmit="return confirm('HAPUS PERMANEN sesi #<?= $as['id'] ?> (<?= htmlspecialchars($as['user_name']) ?>)? Sesi dan topik Telegram akan dihapus total.');">
                      <input type="hidden" name="tab" value="delete_active_session">
                      <input type="hidden" name="session_id" value="<?= $as['id'] ?>">
                      <button type="submit" class="btn btn-sm btn-danger d-flex align-items-center gap-1" style="font-size:11px;padding:4px 8px;border-radius:6px;" title="Hapus sesi secara permanen">
                        <i class="ph-bold ph-trash"></i> Hapus
                      </button>
                    </form>
                  </div>
                </div>

              </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- ⏳ KOLOM KANAN: SESI ANTREAN -->
    <div class="col-lg-6">
      <div class="c-card h-100" style="border-top:3px solid #f59e0b;">
        <div class="c-card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
          <div>
            <div class="d-flex align-items-center gap-2">
              <i class="ph-bold ph-hourglass-high text-warning" style="font-size:16px;"></i>
              <span class="c-card-title mb-0" style="color:#fbbf24;">Antrean Menunggu (<?= count($waitingQueueList) ?>)</span>
            </div>
            <div style="font-size:11.5px;color:#94a3b8;margin-top:2px;">User yang mengantre menunggu kuota sesi aktif tersedia.</div>
          </div>
          <?php if (!empty($waitingQueueList)): ?>
          <div class="d-flex gap-1">
            <form method="POST" onsubmit="return confirm('MASUKKAN SEMUA ANTREAN KE SESI AKTIF SEKARANG?');" class="mb-0">
              <input type="hidden" name="tab" value="admit_all_queue">
              <button type="submit" class="btn btn-sm btn-success d-flex align-items-center gap-1" style="font-size:11px;padding:4px 10px;border-radius:8px;font-weight:700;">
                <i class="ph-bold ph-play"></i> Admit Semua
              </button>
            </form>
            <form method="POST" onsubmit="return confirm('BERSIHKAN SEMUA ANTREAN? Semua user dalam antrean akan dibatalkan.');" class="mb-0">
              <input type="hidden" name="tab" value="clear_all_queue">
              <button type="submit" class="btn btn-sm btn-outline-danger d-flex align-items-center gap-1" style="font-size:11px;padding:4px 8px;border-radius:8px;">
                <i class="ph-bold ph-broom"></i> Bersihkan
              </button>
            </form>
          </div>
          <?php endif; ?>
        </div>

        <div class="c-card-body p-3">
          <?php if (empty($waitingQueueList)): ?>
            <div class="text-center py-5">
              <div style="width:54px;height:54px;border-radius:14px;background:rgba(255,255,255,0.03);border:1px solid rgba(255,255,255,0.08);margin:0 auto 10px;display:flex;align-items:center;justify-content:center;font-size:24px;color:#64748b;">
                <i class="ph-bold ph-hourglass-simple"></i>
              </div>
              <div class="fw-bold text-white mb-1" style="font-size:13.5px;">Antrean Bersih</div>
              <div class="text-secondary" style="font-size:11.5px;">Jika sesi aktif penuh sesuai batas kuota, user baru akan mengantre di sini secara berurutan.</div>
            </div>
          <?php else: ?>
            <div class="d-flex flex-column gap-2">
              <?php foreach ($waitingQueueList as $idx => $wq): ?>
              <div style="background:#0c0e18;border:1px solid #1c2237;border-radius:12px;padding:14px;">
                <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                  <div class="d-flex align-items-center gap-2">
                    <div style="background:#f59e0b;color:#000;font-weight:900;font-size:12px;border-radius:8px;padding:4px 8px;">
                      #<?= $idx + 1 ?>
                    </div>
                    <div>
                      <div style="font-size:13px;font-weight:bold;color:#f8fafc;">
                        <?= htmlspecialchars((string)$wq['user_name']) ?>
                        <?php if ($wq['user_id']): ?>
                          <span class="badge bg-dark border border-secondary text-secondary ms-1" style="font-size:10px;">#UID: <?= $wq['user_id'] ?></span>
                        <?php else: ?>
                          <span class="badge bg-dark border text-muted ms-1" style="font-size:10px;">Guest</span>
                        <?php endif; ?>
                      </div>
                      <?php if ($wq['user_email']): ?>
                        <div style="font-size:11px;color:#64748b;"><?= htmlspecialchars((string)$wq['user_email']) ?></div>
                      <?php endif; ?>
                    </div>
                  </div>

                  <div class="text-end">
                    <span class="badge bg-dark border border-secondary text-secondary" style="font-size:10px;">
                      Req: <?= $wq['mode']==='admin' ? '👨‍💼 Admin' : '🤖 AI' ?>
                    </span>
                    <?php if ((int)$wq['ping_ago_sec'] <= 25): ?>
                      <div style="font-size:10px;color:#34d399;margin-top:2px;">🟢 Online (Ping <?= (int)$wq['ping_ago_sec'] ?>s)</div>
                    <?php else: ?>
                      <div style="font-size:10px;color:#fbbf24;margin-top:2px;">🟡 Idle (Ping <?= (int)$wq['ping_ago_sec'] ?>s)</div>
                    <?php endif; ?>
                  </div>
                </div>

                <!-- Queue Info -->
                <div class="d-flex justify-content-between align-items-center mb-2 px-3 py-2" style="background:#131726;border-radius:8px;font-size:11.5px;color:#94a3b8;">
                  <span>Menunggu sejak: <strong class="text-white"><?= date('H:i:s', strtotime($wq['created_at'])) ?></strong> (<?= (int)$wq['wait_mins'] ?> mnt)</span>
                  <span>Token: <code style="color:#38bdf8;"><?= substr((string)$wq['queue_token'], 0, 8) ?>...</code></span>
                </div>

                <!-- Action Buttons -->
                <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 pt-2" style="border-top:1px dashed #1e243d;">
                  <form method="POST" class="mb-0">
                    <input type="hidden" name="tab" value="admit_queue">
                    <input type="hidden" name="queue_id" value="<?= $wq['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-success d-flex align-items-center gap-1" style="font-size:11.5px;padding:5px 14px;border-radius:8px;font-weight:700;">
                      <i class="ph-bold ph-user-check"></i> Masukkan ke Sesi Aktif (Admit)
                    </button>
                  </form>

                  <form method="POST" class="mb-0" onsubmit="return confirm('Keluarkan <?= htmlspecialchars($wq['user_name']) ?> dari antrean?');">
                    <input type="hidden" name="tab" value="remove_queue">
                    <input type="hidden" name="queue_id" value="<?= $wq['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-outline-danger d-flex align-items-center gap-1" style="font-size:11px;padding:4px 10px;border-radius:8px;">
                      <i class="ph-bold ph-trash"></i> Hapus
                    </button>
                  </form>
                </div>

              </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

  </div>
  <?php endif; ?>


  <!-- ═════════════════════════════════════════════════════════ -->
  <!-- ═══ TAB 3: SETTINGS ═════════════════════════════════════ -->
  <!-- ═════════════════════════════════════════════════════════ -->
  <?php if ($activeTab === 'settings'): ?>
  <form method="post">
    <input type="hidden" name="tab" value="settings">
    <div class="row g-3">

      <!-- Telegram Bot Config -->
      <div class="col-md-6">
        <div class="c-card h-100">
          <div class="c-card-header d-flex align-items-center gap-2">
            <i class="ph-bold ph-telegram-logo text-info" style="font-size:18px;"></i>
            <span class="c-card-title mb-0">Bot Livechat (Telegram Forum)</span>
          </div>
          <div style="background:rgba(245,158,11,0.08);border-bottom:1px solid rgba(245,158,11,0.2);padding:10px 18px;font-size:11.5px;color:#fbbf24;font-weight:600;">
            <i class="ph-bold ph-info me-1"></i> Bot ini TERPISAH dari bot notifikasi Depo/WD agar pesan obrolan pelanggan tidak tercampur.
          </div>
          <div class="c-card-body p-4">
            <div class="c-form-group mb-3">
              <label class="c-label">Nama Admin (Tampil di Chat Pengguna)</label>
              <input type="text" name="chat_admin_name" class="c-form-control" value="<?= htmlspecialchars($cfg['chat_admin_name']) ?>" placeholder="Admin Support">
            </div>
            <div class="c-form-group mb-3">
              <label class="c-label">Bot Token Telegram <span class="text-secondary">(Khusus Livechat)</span></label>
              <input type="text" name="lc_tg_token" class="c-form-control" value="<?= htmlspecialchars($cfg['lc_tg_token']) ?>" placeholder="1234567890:AAH...">
              <small class="text-secondary" style="font-size:11px;">Dapatkan token via @BotFather. Disarankan menggunakan bot terpisah.</small>
            </div>
            <div class="c-form-group mb-3">
              <label class="c-label">Supergroup / Chat ID <span class="text-secondary">(Dengan Fitur Topics Aktif)</span></label>
              <input type="text" name="lc_tg_chat_id" class="c-form-control" value="<?= htmlspecialchars($cfg['lc_tg_chat_id']) ?>" placeholder="-100123456789">
              <small class="text-secondary" style="font-size:11px;">ID Supergroup Telegram yang sudah diaktifkan fitur Topics / Forum.</small>
            </div>
            <div class="c-form-group mb-0">
              <label class="c-label">Pesan Sambutan Otomatis (Welcome Message)</label>
              <textarea name="chat_welcome_msg" class="c-form-control" rows="3"><?= htmlspecialchars($cfg['chat_welcome_msg']) ?></textarea>
              <small class="text-secondary" style="font-size:11px;">Pesan pertama yang langsung dikirim oleh sistem saat sesi chat dimulai.</small>
            </div>
          </div>
        </div>
      </div>

      <!-- OpenAI & Livechat Operations -->
      <div class="col-md-6">
        <div class="c-card h-100">
          <div class="c-card-header d-flex align-items-center gap-2">
            <i class="ph-bold ph-sparkle text-warning" style="font-size:18px;"></i>
            <span class="c-card-title mb-0">OpenAI &amp; Operasional LiveChat</span>
          </div>
          <div class="c-card-body p-4">
            <div class="c-form-group mb-3">
              <label class="c-label">OpenAI API Key (Mode AI)</label>
              <input type="password" name="openai_api_key" class="c-form-control" value="<?= htmlspecialchars($cfg['openai_api_key']) ?>" placeholder="sk-...">
            </div>
            <div class="c-form-group mb-3">
              <label class="c-label">Pilihan Model OpenAI</label>
              <select name="openai_model" class="c-form-control">
                <?php foreach (['gpt-4o-mini','gpt-4o','gpt-3.5-turbo'] as $m): ?>
                <option value="<?= $m ?>" <?= $cfg['openai_model']===$m?'selected':'' ?>><?= $m ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="c-form-group mb-3">
              <label class="c-label">System Prompt AI</label>
              <textarea name="ai_system_prompt" class="c-form-control" rows="3"><?= htmlspecialchars($cfg['ai_system_prompt']) ?></textarea>
              <small class="text-secondary" style="font-size:11px;">Instruksi dasar kepribadian dan cara bot menjawab pertanyaan pelanggan.</small>
            </div>

            <div class="row g-2 mb-3">
              <div class="col-sm-6">
                <div class="c-form-group mb-0">
                  <label class="c-label">Batas Maks Sesi Aktif</label>
                  <input type="number" name="lc_max_active_sessions" class="c-form-control" min="0" value="<?= htmlspecialchars((string)($cfg['lc_max_active_sessions'] ?? '0')) ?>" placeholder="0 (Unlimited)">
                  <small class="text-secondary" style="font-size:10.5px;">Batas bersamaan. 0 = Tanpa batas.</small>
                </div>
              </div>
              <div class="col-sm-6">
                <div class="c-form-group mb-0">
                  <label class="c-label">Batas Idle Timeout (Menit)</label>
                  <input type="number" name="lc_max_idle_minutes" class="c-form-control" min="0" value="<?= htmlspecialchars((string)($cfg['lc_max_idle_minutes'] ?? '30')) ?>" placeholder="30">
                  <small class="text-secondary" style="font-size:10.5px;">Auto-close jika idle. 0 = Nonaktif.</small>
                </div>
              </div>
            </div>

            <div class="c-form-group mb-3">
              <label class="c-label">Pesan Livechat Ditutup (Offline)</label>
              <textarea name="lc_offline_msg" class="c-form-control" rows="2" placeholder="Layanan live chat saat ini tidak tersedia. Silakan coba lagi nanti pada jam operasional."><?= htmlspecialchars($cfg['lc_offline_msg'] ?? 'Layanan live chat saat ini tidak tersedia. Silakan coba lagi nanti pada jam operasional.') ?></textarea>
            </div>

            <!-- Toggles container -->
            <div class="p-3 rounded-3 mb-3" style="background:#090b14;border:1px solid #1e243d;">
              <div class="row g-2">
                <div class="col-sm-6">
                  <label class="d-flex align-items-center gap-2 text-white" style="font-size:12.5px;cursor:pointer;">
                    <input type="checkbox" name="livechat_enabled" value="1" <?= $cfg['livechat_enabled']==='1'?'checked':'' ?> style="accent-color:var(--brand);width:16px;height:16px;">
                    <strong>Livechat Aktif</strong>
                  </label>
                </div>
                <div class="col-sm-6">
                  <label class="d-flex align-items-center gap-2 text-secondary" style="font-size:12.5px;cursor:pointer;">
                    <input type="checkbox" name="chat_ai_enabled" value="1" <?= $cfg['chat_ai_enabled']==='1'?'checked':'' ?> style="accent-color:var(--brand);width:16px;height:16px;">
                    Aktifkan Mode AI
                  </label>
                </div>
                <div class="col-sm-6">
                  <label class="d-flex align-items-center gap-2 text-secondary" style="font-size:12.5px;cursor:pointer;">
                    <input type="checkbox" name="chat_admin_enabled" value="1" <?= $cfg['chat_admin_enabled']==='1'?'checked':'' ?> style="accent-color:var(--brand);width:16px;height:16px;">
                    Aktifkan Mode Admin
                  </label>
                </div>
                <div class="col-sm-6">
                  <label class="d-flex align-items-center gap-2 text-secondary" style="font-size:12.5px;cursor:pointer;">
                    <input type="checkbox" name="lc_attachment_enabled" value="1" <?= $cfg['lc_attachment_enabled']==='1'?'checked':'' ?> style="accent-color:var(--brand);width:16px;height:16px;">
                    Fitur Lampiran Gambar/File
                  </label>
                </div>
              </div>
            </div>

            <!-- Debug Panel Toggle -->
            <div class="p-3 rounded-3" style="background:rgba(245,158,11,0.06);border:1px solid rgba(245,158,11,0.2);">
              <label class="d-flex align-items-center gap-2 mb-0" style="font-size:12.5px;cursor:pointer;">
                <input type="checkbox" name="lc_debug_panel" value="1" <?= $cfg['lc_debug_panel']==='1'?'checked':'' ?> style="accent-color:var(--brand);width:16px;height:16px;">
                <span>🐛 <strong class="text-warning">Debug Panel</strong> <span class="text-secondary" style="font-size:11px;">(tampilkan floating status debug di widget livechat user)</span></span>
              </label>
            </div>
          </div>
        </div>
      </div>

      <div class="col-12 text-end mt-3">
        <button type="submit" class="btn d-inline-flex align-items-center gap-2" style="background:linear-gradient(135deg,#f59e0b,#d97706);color:#fff;border:none;padding:12px 32px;border-radius:10px;font-weight:700;font-size:13.5px;box-shadow:0 4px 16px rgba(245,158,11,0.3);">
          <i class="ph-bold ph-floppy-disk"></i> Simpan Seluruh Pengaturan
        </button>
      </div>
    </div>
  </form>
  <?php endif; ?>


  <!-- ═════════════════════════════════════════════════════════ -->
  <!-- ═══ TAB 4: WEBHOOK INFO ═════════════════════════════════ -->
  <!-- ═════════════════════════════════════════════════════════ -->
  <?php if ($activeTab === 'webhook'): ?>
  <?php
    $scheme     = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off') ? 'https' : 'http';
    $host       = $_SERVER['HTTP_HOST'] ?? 'yourdomain.com';
    $webhookUrl = $scheme . '://' . $host . '/chat_action?action=tg_webhook';
    $botToken   = $cfg['lc_tg_token'];
    $setWebhookUrl = $botToken
      ? "https://api.telegram.org/bot{$botToken}/setWebhook?url=" . urlencode($webhookUrl)
      : '';
  ?>
  <div class="row g-3">
    <!-- Webhook Setup -->
    <div class="col-md-7">
      <div class="c-card h-100">
        <div class="c-card-header d-flex align-items-center gap-2">
          <i class="ph-bold ph-plugs-connected text-warning" style="font-size:18px;"></i>
          <span class="c-card-title mb-0">Setup Telegram Webhook</span>
        </div>
        <div class="c-card-body p-4">
          <p class="text-secondary mb-3" style="font-size:13px;line-height:1.6;">
            Agar pesan balasan admin yang dikirim melalui aplikasi Telegram otomatis masuk ke obrolan pengguna di web, webhook bot Telegram harus diarahkan ke endpoint di bawah ini.
          </p>

          <div class="c-form-group mb-3">
            <label class="c-label">URL Webhook Server Kamu</label>
            <div class="d-flex align-items-center gap-2">
              <div class="webhook-url flex-grow-1" id="wh-url-text"><?= htmlspecialchars($webhookUrl) ?></div>
              <button type="button" class="btn btn-outline-secondary d-flex align-items-center justify-content-center" style="height:44px;width:44px;border-radius:10px;flex-shrink:0;" onclick="copyWebhookUrl()" title="Salin URL">
                <i class="ph-bold ph-copy" style="font-size:16px;"></i>
              </button>
            </div>
          </div>

          <?php if ($setWebhookUrl): ?>
          <?php
            $wh_result = $_SESSION['wh_result'] ?? null;
            $wh_info   = $_SESSION['wh_info']   ?? null;
            unset($_SESSION['wh_result'], $_SESSION['wh_info']);
          ?>
          <?php if ($wh_result): ?>
          <div class="mb-3 p-3 rounded-3" style="font-size:12.5px;font-family:ui-monospace,SFMono-Regular,monospace;background:<?= !empty($wh_result['ok']) ? 'rgba(16,185,129,0.12)' : 'rgba(239,68,68,0.12)' ?>;border:1px solid <?= !empty($wh_result['ok']) ? 'rgba(16,185,129,0.3)' : 'rgba(239,68,68,0.3)' ?>;color:<?= !empty($wh_result['ok']) ? '#34d399' : '#f87171' ?>;">
            <div class="fw-bold mb-1"><?= !empty($wh_result['ok']) ? '✅ Webhook Berhasil Dikonfigurasi' : '❌ Gagal Mengatur Webhook' ?></div>
            <?php if (!empty($wh_result['ok'])): ?>
              Webhook berhasil diset ke: <strong><?= htmlspecialchars((string)($wh_result['webhook_url'] ?? '')) ?></strong>
            <?php else: ?>
              Error: <?= htmlspecialchars((string)($wh_result['description'] ?? $wh_result['error'] ?? 'Unknown error')) ?>
            <?php endif; ?>
          </div>
          <?php endif; ?>

          <?php if ($wh_info): ?>
          <div class="mb-3 p-3 rounded-3" style="font-size:12px;font-family:ui-monospace,SFMono-Regular,monospace;background:rgba(56,189,248,0.08);border:1px solid rgba(56,189,248,0.25);color:#7dd3fc;word-break:break-all;">
            <strong><i class="ph-bold ph-info me-1"></i> Telegram Webhook Status:</strong><br>
            URL: <?= htmlspecialchars((string)($wh_info['result']['url'] ?? '(kosong/belum diset)')) ?><br>
            Pending Updates: <?= (int)($wh_info['result']['pending_update_count'] ?? 0) ?><br>
            Last Error: <?= htmlspecialchars((string)($wh_info['result']['last_error_message'] ?? '–')) ?>
          </div>
          <?php endif; ?>

          <div class="d-flex gap-2 flex-wrap mt-3">
            <form method="POST" class="mb-0">
              <input type="hidden" name="tab" value="sync_webhook">
              <button type="submit" class="btn d-inline-flex align-items-center gap-2" style="background:linear-gradient(135deg,#f59e0b,#d97706);border:none;color:#fff;padding:10px 22px;border-radius:10px;font-weight:700;font-size:13px;box-shadow:0 4px 14px rgba(245,158,11,0.25);">
                <i class="ph-bold ph-rocket-launch"></i> Sync Webhook via Server
              </button>
            </form>
            <form method="POST" class="mb-0">
              <input type="hidden" name="tab" value="check_webhook">
              <button type="submit" class="btn btn-outline-secondary d-inline-flex align-items-center gap-2" style="padding:10px 20px;border-radius:10px;font-weight:700;font-size:13px;">
                <i class="ph-bold ph-magnifying-glass"></i> Cek Status Webhook
              </button>
            </form>
          </div>
          <?php else: ?>
          <div class="p-3 rounded-3" style="background:rgba(245,158,11,0.08);border:1px solid rgba(245,158,11,0.25);color:#fbbf24;font-size:12.5px;">
            <i class="ph-bold ph-warning me-1"></i> Harap isi Bot Token Telegram terlebih dahulu di tab <strong>Pengaturan Layanan</strong>.
          </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- How It Works Guide -->
    <div class="col-md-5">
      <div class="c-card h-100">
        <div class="c-card-header d-flex align-items-center gap-2">
          <i class="ph-bold ph-book-open text-warning" style="font-size:18px;"></i>
          <span class="c-card-title mb-0">Cara Kerja Bot Forum</span>
        </div>
        <div class="c-card-body p-4">
          <ol class="text-secondary mb-0 ps-3" style="font-size:13px;line-height:2.1;">
            <li>Buat bot baru melalui <strong class="text-white">@BotFather</strong> di Telegram.</li>
            <li>Buat Supergroup Telegram &amp; aktifkan fitur <strong class="text-white">Topics / Forum</strong>.</li>
            <li>Tambahkan bot ke grup dan beri izin sebagai <strong class="text-white">Administrator</strong>.</li>
            <li>Isi <strong class="text-white">Bot Token</strong> &amp; <strong class="text-white">Chat ID</strong> di tab Pengaturan.</li>
            <li>Klik tombol <strong class="text-warning">Sync Webhook via Server</strong> di sebelah kiri.</li>
            <li>Setiap user yang chat akan otomatis dibuatkan 1 <strong class="text-white">Thread Topic Baru</strong>.</li>
            <li>Balas chat di thread Telegram tersebut, maka balasan otomatis masuk ke layar obrolan user.</li>
          </ol>
        </div>
      </div>
    </div>
  </div>

  <!-- Danger Zone Card -->
  <div class="row g-3 mt-2">
    <div class="col-12">
      <div class="c-card" style="border-color:rgba(239,68,68,0.35);">
        <div class="c-card-header d-flex align-items-center justify-content-between" style="background:rgba(239,68,68,0.06);border-bottom-color:rgba(239,68,68,0.2);">
          <div class="d-flex align-items-center gap-2">
            <i class="ph-bold ph-warning-octagon text-danger" style="font-size:18px;"></i>
            <span class="c-card-title text-danger mb-0">Danger Zone</span>
          </div>
          <span class="badge bg-danger text-white px-2 py-1" style="font-size:10.5px;font-weight:700;">Tindakan Permanen</span>
        </div>
        <div class="c-card-body p-4">
          <div class="d-flex gap-2 flex-wrap align-items-center">

            <!-- Reset all sessions + delete topics -->
            <form method="POST" onsubmit="return confirm('RESET SEMUA SESI? Tindakan ini akan menghapus seluruh rekaman chat di database dan seluruh topics di Telegram. Tidak bisa dibatalkan!');" class="mb-0">
              <input type="hidden" name="tab" value="reset_all_sessions">
              <button type="submit" class="btn btn-outline-danger d-inline-flex align-items-center gap-2" style="padding:10px 18px;border-radius:10px;font-weight:700;font-size:13px;">
                <i class="ph-bold ph-trash"></i> Reset Sesi &amp; Topics
              </button>
            </form>

            <!-- Delete Telegram topics only -->
            <form method="POST" onsubmit="return confirm('Hapus semua Topics Telegram yang dibuat bot? Data sesi di database TETAP tersimpan.');" class="mb-0">
              <input type="hidden" name="tab" value="delete_tg_topics">
              <button type="submit" class="btn btn-outline-warning d-inline-flex align-items-center gap-2" style="padding:10px 18px;border-radius:10px;font-weight:700;font-size:13px;">
                <i class="ph-bold ph-broom"></i> Hapus Topics Telegram Saja
              </button>
            </form>

            <!-- Nuclear: delete ALL topics -->
            <button type="button" id="btn-nuclear-topics" onclick="nuclearClearTopics()"
              class="btn btn-danger d-inline-flex align-items-center gap-2"
              style="padding:10px 18px;border-radius:10px;font-weight:700;font-size:13px;background:#dc2626;border-color:#b91c1c;">
              <i class="ph-bold ph-radioactive"></i> Hapus SEMUA Topics (Nuclear)
            </button>

          </div>

          <p class="text-secondary mt-3 mb-0" style="font-size:11.5px;">
            ⚠️ Hanya topics yang dibuat oleh bot (memiliki <code>tg_thread_id</code> di database) yang akan dihapus.
            Topic <strong>General</strong> dan topic yang dibuat secara manual tidak akan terhapus oleh bot.
          </p>

          <div id="nuclear-result" style="display:none;margin-top:14px;padding:12px 16px;border-radius:10px;font-size:12px;font-family:ui-monospace,SFMono-Regular,monospace;"></div>
        </div>
      </div>
    </div>
  </div>

  <script>
  function copyWebhookUrl() {
    const text = document.getElementById('wh-url-text').innerText.trim();
    navigator.clipboard.writeText(text).then(() => {
      alert('✅ URL Webhook berhasil disalin ke clipboard!');
    }).catch(err => {
      prompt('Salin URL webhook:', text);
    });
  }

  async function nuclearClearTopics() {
    if (!confirm('HAPUS SEMUA TOPICS TELEGRAM? Bot akan mencoba menghapus semua topic ID mulai dari 2 hingga batas terdeteksi. General topic akan dilewati otomatis oleh Telegram. TIDAK BISA DIBATALKAN!')) return;

    const btn = document.getElementById('btn-nuclear-topics');
    const result = document.getElementById('nuclear-result');
    btn.disabled = true;
    btn.innerHTML = '<i class="ph-bold ph-spinner ph-spin"></i> Menghapus Topics... (mohon tunggu)';
    result.style.display = 'block';
    result.style.background = 'rgba(56,189,248,0.08)';
    result.style.border = '1px solid rgba(56,189,248,0.25)';
    result.style.color = '#7dd3fc';
    result.textContent = '⏳ Sedang memproses... bot mencoba menghapus semua topic. Jangan tutup halaman ini.';

    try {
      const fd = new FormData();
      fd.append('tab', 'nuclear_clear_topics');
      const res  = await fetch('/console/livechat.php', { method: 'POST', body: fd });
      const data = await res.json();
      if (data.ok) {
        result.style.background = 'rgba(16,185,129,0.12)';
        result.style.border = '1px solid rgba(16,185,129,0.3)';
        result.style.color = '#34d399';
        result.textContent = `✅ Selesai! Topics dihapus: ${data.deleted} | Gagal/tidak ada: ${data.failed} | Range dicek: ${data.range}`;
      } else {
        result.style.background = 'rgba(239,68,68,0.12)';
        result.style.border = '1px solid rgba(239,68,68,0.3)';
        result.style.color = '#f87171';
        result.textContent = '❌ Error: ' + (data.error || 'Unknown error');
      }
    } catch(e) {
      result.style.background = 'rgba(239,68,68,0.12)';
      result.style.border = '1px solid rgba(239,68,68,0.3)';
      result.style.color = '#f87171';
      result.textContent = '❌ Gagal: ' + e.message;
    }
    btn.disabled = false;
    btn.innerHTML = '<i class="ph-bold ph-radioactive"></i> Hapus SEMUA Topics (Nuclear)';
  }
  </script>
  <?php endif; ?>

</div>

<?php require_once __DIR__ . '/partials/footer.php'; ?>
