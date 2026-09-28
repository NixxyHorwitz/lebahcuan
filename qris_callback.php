<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

// Accept only POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(405);
    echo json_encode(['error' => 'Method Not Allowed']);
    exit;
}

// Read raw request body
$rawBody = file_get_contents('php://input');
$data = json_decode($rawBody, true);

if (!$data) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON Payload']);
    exit;
}

// Log incoming payload to payment_gateway_logs first
try {
    $pdo->prepare("INSERT INTO payment_gateway_logs (payload, status) VALUES (?, 'unmatched')")
        ->execute([$rawBody]);
    $log_id = (int)$pdo->lastInsertId();
} catch (\Throwable $e) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);
    echo json_encode(['error' => 'Failed to initialize log record', 'details' => $e->getMessage()]);
    exit;
}

// Parse amount from gateway text field (e.g. "Rp5.000 berhasil diterima" or "Rp50.123 berhasil diterima.")
$text = $data['text'] ?? '';
$amount = 0.0;
if (preg_match('/Rp\s*([0-9.]+)/i', $text, $matches)) {
    // Remove dots as thousand separators (e.g., "5.000" -> 5000)
    $amount = (float)str_replace('.', '', $matches[1]);
}

// If no valid amount could be parsed, terminate as failed log
if ($amount <= 0.0) {
    $pdo->prepare("UPDATE payment_gateway_logs SET status = 'failed', extracted_amount = 0.00 WHERE id = ?")
        ->execute([$log_id]);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => 'ignored', 'message' => 'No valid amount found in text.']);
    exit;
}

// Update the extracted amount in log record
$pdo->prepare("UPDATE payment_gateway_logs SET extracted_amount = ? WHERE id = ?")
    ->execute([$amount, $log_id]);

// Check if unique code option is active in settings
$uniqueEnabled = setting($pdo, 'depo_unique_code_enabled', '0') === '1';

if (!$uniqueEnabled) {
    $pdo->prepare("UPDATE payment_gateway_logs SET status = 'disabled' WHERE id = ?")
        ->execute([$log_id]);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => 'ignored', 'message' => 'Auto deposit disabled (unique code setting is off).']);
    exit;
}

// Find oldest pending deposit with the exact matching amount (which includes the unique code!)
$stmt = $pdo->prepare("SELECT * FROM deposits WHERE amount = ? AND status = 'pending' ORDER BY created_at ASC LIMIT 1");
$stmt->execute([$amount]);
$dep = $stmt->fetch();

if (!$dep) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => 'unmatched', 'message' => 'No matching pending deposit found for amount ' . $amount]);
    exit;
}

// Matching deposit found! Let's process the confirmation transaction
$pdo->beginTransaction();
try {
    // 1. Credit deposit balance to user
    $pdo->prepare("UPDATE users SET balance_dep = balance_dep + ? WHERE id = ?")
        ->execute([$dep['amount'], $dep['user_id']]);

    // 2. Mark deposit as confirmed
    $pdo->prepare("UPDATE deposits SET status = 'confirmed', confirmed_at = NOW() WHERE id = ?")
        ->execute([$dep['id']]);


    // 3. Process referral commission
    $comm = credit_deposit_referral_commission($pdo, (int)$dep['user_id'], (float)$dep['amount']);

    // 4. Update the payment gateway log to matched and link the deposit
    $pdo->prepare("UPDATE payment_gateway_logs SET deposit_id = ?, status = 'matched' WHERE id = ?")
        ->execute([$dep['id'], $log_id]);

    $pdo->commit();

    // Get username and tg_msg_id for notification
    $dep_info = $pdo->prepare("SELECT d.tg_msg_id, u.username FROM deposits d JOIN users u ON u.id = d.user_id WHERE d.id = ?");
    $dep_info->execute([$dep['id']]);
    $dep_data = $dep_info->fetch();
    $username = $dep_data['username'] ?? 'User';
    $tg_msg_id = $dep_data['tg_msg_id'] ?? null;

    $msg = "✅ <b>DEPOSIT QRIS BERHASIL (CONFIRMED)</b>\n";
    $msg .= "━━━━━━━━━━━━━━━━━━━━━━\n";
    $msg .= "👤 <b>User:</b> <code>" . htmlspecialchars($username) . "</code>\n";
    $msg .= "💵 <b>Amount:</b> <code>" . format_rp((float)$dep['amount']) . "</code>\n";
    if ($comm > 0) {
        $msg .= "🎁 <b>Komisi Referral:</b> <code>" . format_rp($comm) . "</code> (dikirim ke upline)\n";
    }
    $msg .= "🕒 <b>Time:</b> <code>" . date('d-m-Y H:i:s') . " WIB</code>\n";
    $msg .= "💳 <b>Method:</b> <code>QRIS Otomatis</code>\n";
    $msg .= "✅ <b>Status:</b> <code>Sukses via Callback</code>\n";
    $msg .= "━━━━━━━━━━━━━━━━━━━━━━\n";
    $msg .= "<i>Pembayaran terverifikasi otomatis oleh sistem. Saldo telah ditambahkan ke akun pengguna.</i>";

    if ($tg_msg_id) {
        edit_telegram_notif($pdo, (int)$tg_msg_id, $msg, []);
    } else {
        send_telegram_notif($pdo, $msg, [], 'depo');
    }

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'status' => 'matched',
        'message' => 'Deposit confirmed successfully.',
        'deposit_id' => $dep['id'],
        'user_id' => $dep['user_id'],
        'amount' => $dep['amount']
    ]);
} catch (\Throwable $e) {
    $pdo->rollBack();
    
    // Log the failed status
    $pdo->prepare("UPDATE payment_gateway_logs SET status = 'failed' WHERE id = ?")
        ->execute([$log_id]);

    header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);
    echo json_encode(['error' => 'Database Transaction Failed', 'details' => $e->getMessage()]);
}
