<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/bootstrap.php';
$user = require_auth($pdo);

$dep_id = (int)($_GET['id'] ?? 0);
if (!$dep_id) redirect('/deposit');

$dep = $pdo->prepare("SELECT * FROM deposits WHERE id=? AND user_id=?");
$dep->execute([$dep_id, $user['id']]);
$dep = $dep->fetch();
if (!$dep || $dep['method'] !== 'qris') redirect('/deposit');

// ── AJAX: check_status — HARUS sebelum redirect confirmed ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    pdo_reconnect($pdo);
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'check_status') {
    header('Content-Type: application/json; charset=utf-8');
    $st = $pdo->prepare("SELECT status FROM deposits WHERE id=? AND user_id=?");
    $st->execute([$dep_id, $user['id']]);
    $row = $st->fetch();
    echo json_encode(['confirmed' => ($row && $row['status'] === 'confirmed')]);
    exit;
}

// ── PHP Proxy: download QR image (avoid exposing external URL to browser) ──
if (($_GET['action'] ?? '') === 'dl_qr') {
    $qris_raw_dl = $_ENV['QRIS_RAW'] ?? '';
    $qris_str_dl = !empty($qris_raw_dl) ? qris_with_amount($qris_raw_dl, (int)(float)$dep['amount']) : '';
    if (!$qris_str_dl) { http_response_code(404); exit('QR not available'); }
    $remote = 'https://api.qrserver.com/v1/create-qr-code/?size=400x400&data=' . urlencode($qris_str_dl);
    $img    = @file_get_contents($remote);
    if (!$img) { http_response_code(502); exit('Failed to generate QR'); }
    header('Content-Type: image/png');
    header('Content-Disposition: attachment; filename="QRIS-LebahCuan-dep' . $dep_id . '.png"');
    header('Content-Length: ' . strlen($img));
    header('Cache-Control: no-store');
    echo $img;
    exit;
}

if ($dep['status'] === 'confirmed') redirect('/history');

$qris_raw     = $_ENV['QRIS_RAW'] ?? '';
$confirm_mode = setting($pdo, 'deposit_confirm_mode', 'manual');
$amount       = (float)$dep['amount'];
$qris_str     = !empty($qris_raw) ? qris_with_amount($qris_raw, (int)$amount) : '';
$_favicon     = setting($pdo, 'favicon_path', '');
$fav_url      = $_favicon ? '/' . ltrim($_favicon, '/') : '';

// Upload bukti
$flash = $flashType = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload_proof') {
    if (empty($_FILES['proof']['tmp_name'])) {
        $flash = 'Pilih file bukti pembayaran.'; $flashType = 'error';
    } else {
        $ext = strtolower(pathinfo($_FILES['proof']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg','jpeg','png','webp'])) {
            $flash = 'Format harus JPG/PNG/WEBP.'; $flashType = 'error';
        } else {
            $dir = dirname(__DIR__) . '/uploads/deposits/';
            if (!is_dir($dir)) mkdir($dir, 0755, true);
            $fname = 'dep_' . $user['id'] . '_' . time() . '.' . $ext;
            move_uploaded_file($_FILES['proof']['tmp_name'], $dir . $fname);
            $pdo->prepare("UPDATE deposits SET proof_image=? WHERE id=?")->execute(['deposits/' . $fname, $dep_id]);
            $flash = 'Bukti pembayaran berhasil diunggah! Admin akan segera memverifikasi.';
            $flashType = 'success';
            
            // Telegram Notif
            $scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'] ?? 'lebahcuan.id';
            $proofUrl = $scheme . '://' . $host . '/uploads/deposits/' . $fname;
            
            $msg = "<b>BUKTI DEPOSIT DIUPLOAD</b>\n";
            $msg .= "━━━━━━━━━━━━━━━━━━━━━━\n";
            $msg .= "User: <code>" . htmlspecialchars($user['username']) . "</code>\n";
            $msg .= "Amount: <code>" . format_rp((float)$dep['amount']) . "</code>\n";
            $msg .= "Bukti: <a href=\"{$proofUrl}\">Klik untuk lihat struk</a>\n";
            $msg .= "━━━━━━━━━━━━━━━━━━━━━━\n";
            $msg .= "<i>Silakan periksa gambar bukti di atas sebelum melakukan tindakan.</i>";
            
            $kb = [
                [['text'=>'Approve', 'callback_data'=>'depo_approve_'.$dep_id], ['text'=>'Reject', 'callback_data'=>'depo_reject_'.$dep_id]],
                [['text'=>'Acc Expired', 'callback_data'=>'depo_accexp_'.$dep_id], ['text'=>'Refresh Status', 'callback_data'=>'refresh_depo_'.$dep_id]]
            ];
            
            $tg_msg_id = send_telegram_notif($pdo, $msg, $kb, 'depo');
            if ($tg_msg_id) {
                $pdo->prepare("UPDATE deposits SET tg_msg_id = ? WHERE id = ?")->execute([$tg_msg_id, $dep_id]);
            }
        }
    }
    $dep2 = $pdo->prepare("SELECT * FROM deposits WHERE id=?"); $dep2->execute([$dep_id]); $dep = $dep2->fetch();
}

// Cancel
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'cancel_deposit') {
    if (time() - strtotime($dep['created_at']) >= 60) {
        $pdo->prepare("UPDATE deposits SET status='rejected', admin_note='Dibatalkan oleh Pengguna' WHERE id=? AND user_id=? AND status='pending'")
            ->execute([$dep_id, $user['id']]);
        redirect('/deposit');
    } else {
        $flash = 'Harap tunggu 1 menit sejak deposit dibuat sebelum membatalkan.'; $flashType = 'error';
    }
}

// Countdown: 1 jam dari created_at, tidak reset saat refresh
$created_ts       = strtotime($dep['created_at']);
$expire_secs      = max(0, 3600 - (time() - $created_ts));   // sisa waktu 1 jam
$cancel_secs_left = max(0, 60   - (time() - $created_ts));   // sisa cooldown batal

$qr_url      = !empty($qris_str)
    ? 'https://api.qrserver.com/v1/create-qr-code/?size=400x400&data=' . urlencode($qris_str)
    : '';
$qr_dl_url   = '?id=' . $dep_id . '&action=dl_qr';

$pageTitle  = 'Kasir Pembayaran QRIS';
$activePage = 'deposit';
require dirname(__DIR__) . '/partials/header.php';
?>

<style>
/* ══════════════════════════════════════════════
   PAY PAGE — LUXURIOUS AMBER HONEY THEME
   ══════════════════════════════════════════════ */
:root {
  --honey-50:  #fffbeb;
  --honey-100: #fef3c7;
  --honey-200: #fde68a;
  --honey-500: #f59e0b;
  --honey-600: #d97706;
  --honey-700: #b45309;
  --honey-800: #92400e;
  --honey-900: #78350f;
  --honey-ink: #451a03;
}

body {
  background-color: #fef8ee !important;
  background-image: radial-gradient(rgba(217, 119, 6, 0.08) 1.5px, transparent 1.5px) !important;
  background-size: 18px 18px !important;
  color: var(--honey-900);
  font-family: 'Nunito', sans-serif;
}

.pay-page {
  max-width: 480px;
  margin: 0 auto;
  padding-bottom: 90px;
}

/* ── TOP HERO HEADER ── */
.pay-hero {
  background: linear-gradient(135deg, #78350f 0%, #92400e 35%, #b45309 70%, #d97706 100%);
  padding: 16px 14px 32px;
  border-bottom: 4px solid var(--honey-900);
  box-shadow: 0 6px 20px rgba(120, 53, 15, 0.25);
  position: relative;
  overflow: hidden;
}

.pay-hero::before {
  content: '';
  position: absolute;
  inset: 0;
  background-image: radial-gradient(rgba(255, 255, 255, 0.16) 1.5px, transparent 1.5px);
  background-size: 16px 16px;
  pointer-events: none;
}

.pay-hero-nav {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 14px;
  position: relative;
  z-index: 2;
}

.pay-back-btn {
  background: #ffffff;
  border: 2.5px solid var(--honey-900);
  border-radius: 12px;
  width: 38px;
  height: 38px;
  display: flex;
  align-items: center;
  justify-content: center;
  color: var(--honey-900);
  font-size: 18px;
  text-decoration: none;
  box-shadow: 0 3px 0 var(--honey-900);
  transition: transform 0.1s;
}
.pay-back-btn:active {
  transform: translateY(2px);
  box-shadow: 0 1px 0 var(--honey-900);
}

.pay-order-pill {
  background: #ffffff;
  border: 2px solid var(--honey-900);
  border-radius: 14px;
  padding: 6px 14px;
  font-size: 12px;
  font-weight: 900;
  color: var(--honey-900);
  box-shadow: 0 2.5px 0 var(--honey-900);
  display: inline-flex;
  align-items: center;
  gap: 5px;
}

.pay-timer-capsule {
  background: #fee2e2;
  border: 2px solid #ef4444;
  border-radius: 12px;
  padding: 5px 12px;
  font-size: 12px;
  font-weight: 900;
  color: #dc2626;
  display: inline-flex;
  align-items: center;
  gap: 6px;
  box-shadow: 0 2.5px 0 #b91c1c;
}

/* Mascot Helper Banner */
.pay-helper-banner {
  display: flex;
  align-items: center;
  gap: 12px;
  background: rgba(255, 255, 255, 0.96);
  border: 2.5px solid var(--honey-900);
  border-radius: 16px;
  padding: 10px 14px;
  box-shadow: 0 3.5px 0 var(--honey-900);
  position: relative;
  z-index: 2;
}

.pay-mascot-img {
  width: 44px;
  height: 44px;
  flex-shrink: 0;
  animation: payBeeHover 3s ease-in-out infinite;
}
@keyframes payBeeHover {
  0%, 100% { transform: translateY(0); }
  50% { transform: translateY(-3px); }
}

.pay-helper-txt {
  font-size: 11px;
  font-weight: 800;
  color: var(--honey-900);
  line-height: 1.35;
}

/* ── BODY WRAPPER ── */
.pay-body {
  padding: 0 14px;
  margin-top: -16px;
  position: relative;
  z-index: 5;
}

/* Alert / Flash */
.pay-alert {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 10px 14px;
  border-radius: 12px;
  font-size: 12px;
  font-weight: 900;
  margin-bottom: 14px;
  border: 2px solid;
}
.pay-alert--success {
  background: #ecfdf5;
  border-color: #10b981;
  color: #065f46;
}
.pay-alert--error {
  background: #fef2f2;
  border-color: #ef4444;
  color: #b91c1c;
}
.pay-alert--warn {
  background: #fffbeb;
  border-color: #f59e0b;
  color: #b45309;
}

/* ── MAIN PAYMENT VAULT CARD ── */
.pay-vault-card {
  background: #ffffff;
  border: 3.5px solid var(--honey-900);
  border-radius: 24px;
  box-shadow: 0 6px 0 var(--honey-900), 0 16px 28px -6px rgba(120, 53, 15, 0.2);
  overflow: hidden;
  margin-bottom: 16px;
}

.pay-vault-hdr {
  background: linear-gradient(135deg, #fffbeb, #fef3c7);
  border-bottom: 2.5px solid var(--honey-900);
  padding: 12px 16px;
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.pay-vault-badge {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  font-size: 11px;
  font-weight: 900;
  color: var(--honey-900);
  text-transform: uppercase;
}
.pay-vault-badge i {
  font-size: 16px;
  color: var(--honey-600);
}

.pay-vault-date {
  font-size: 10px;
  font-weight: 800;
  color: #92400e;
}

.pay-vault-content {
  padding: 20px 16px;
  text-align: center;
}

/* QR Frame Box */
.qr-frame-box {
  width: 230px;
  height: 230px;
  margin: 0 auto 16px;
  background: #ffffff;
  border: 3.5px solid var(--honey-900);
  border-radius: 20px;
  padding: 10px;
  box-shadow: 0 4px 0 var(--honey-900);
  position: relative;
  display: flex;
  align-items: center;
  justify-content: center;
}

.qr-frame-box img {
  width: 100%;
  height: 100%;
  object-fit: contain;
  display: block;
}

.qr-scan-corners {
  position: absolute;
  inset: 4px;
  pointer-events: none;
  border: 2px dashed rgba(217, 119, 6, 0.35);
  border-radius: 14px;
}

/* Partner badges */
.pay-partner-strip {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 5px;
  flex-wrap: wrap;
  margin-bottom: 16px;
}
.partner-pill {
  background: #fffbeb;
  border: 1.5px solid #fde68a;
  border-radius: 8px;
  padding: 3px 7px;
  font-size: 9px;
  font-weight: 900;
  color: var(--honey-800);
}

/* Amount Container */
.pay-amount-box {
  background: linear-gradient(135deg, #fffbeb, #fef3c7);
  border: 2.5px solid var(--honey-900);
  border-radius: 16px;
  padding: 12px 14px;
  margin-bottom: 16px;
}

.pay-amount-lbl {
  font-size: 10px;
  font-weight: 900;
  color: var(--honey-800);
  text-transform: uppercase;
  letter-spacing: 0.8px;
  margin-bottom: 2px;
}

.pay-amount-val {
  font-size: 26px;
  font-weight: 900;
  color: #b45309;
  letter-spacing: -0.5px;
  line-height: 1.15;
  margin-bottom: 8px;
}

.copy-amt-btn {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  background: #ffffff;
  border: 1.5px solid var(--honey-900);
  border-radius: 9px;
  padding: 5px 12px;
  font-size: 10.5px;
  font-weight: 900;
  color: var(--honey-900);
  cursor: pointer;
  box-shadow: 0 2px 0 var(--honey-900);
  transition: transform 0.1s;
  font-family: inherit;
}
.copy-amt-btn:active {
  transform: translateY(1.5px);
  box-shadow: 0 0.5px 0 var(--honey-900);
}

.pay-unique-hint {
  font-size: 9.5px;
  font-weight: 800;
  color: #92400e;
  line-height: 1.35;
  margin-top: 6px;
}

/* Action Buttons */
.pay-btn-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 10px;
  margin-bottom: 4px;
}

.btn-pay-action {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  padding: 11px 10px;
  border-radius: 12px;
  font-size: 11.5px;
  font-weight: 900;
  text-decoration: none;
  border: 2px solid var(--honey-900);
  box-shadow: 0 3.5px 0 var(--honey-900);
  transition: transform 0.1s;
  font-family: inherit;
  cursor: pointer;
}
.btn-pay-action:active:not(:disabled) {
  transform: translateY(2px);
  box-shadow: 0 1.5px 0 var(--honey-900);
}

.btn-pay-action.gold {
  background: linear-gradient(135deg, #fde047, #f59e0b);
  color: var(--honey-900);
}
.btn-pay-action.amber {
  background: linear-gradient(135deg, #f59e0b, #d97706);
  color: #ffffff;
}

/* ── STATUS MONITOR & PULSE ── */
.pay-monitor-card {
  background: #ffffff;
  border: 2px solid var(--honey-900);
  border-radius: 16px;
  padding: 12px 14px;
  margin-bottom: 14px;
  box-shadow: 0 4px 0 var(--honey-900);
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.monitor-pulse-row {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 11px;
  font-weight: 800;
  color: var(--honey-800);
}

.monitor-pulse-dot {
  width: 10px;
  height: 10px;
  border-radius: 50%;
  background: #10b981;
  box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7);
  animation: pulseDot 1.8s infinite;
  flex-shrink: 0;
}
@keyframes pulseDot {
  0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7); }
  70% { transform: scale(1.15); box-shadow: 0 0 0 8px rgba(16, 185, 129, 0); }
  100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0); }
}

.btn-check-live {
  width: 100%;
  background: linear-gradient(135deg, #10b981, #059669);
  color: #ffffff;
  border: 2px solid #064e3b;
  border-radius: 12px;
  padding: 12px;
  font-size: 12.5px;
  font-weight: 900;
  cursor: pointer;
  box-shadow: 0 3.5px 0 #064e3b;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  font-family: inherit;
  transition: transform 0.1s;
}
.btn-check-live:active:not(:disabled) {
  transform: translateY(2px);
  box-shadow: 0 1.5px 0 #064e3b;
}

/* ── STEPS ACCORDION CARD ── */
.pay-steps-card {
  background: #ffffff;
  border: 2px solid var(--honey-900);
  border-radius: 16px;
  padding: 14px;
  margin-bottom: 14px;
  box-shadow: 0 4px 0 var(--honey-900);
}

.pay-steps-title {
  font-size: 11px;
  font-weight: 900;
  color: var(--honey-800);
  text-transform: uppercase;
  margin-bottom: 12px;
  display: flex;
  align-items: center;
  gap: 6px;
}

.pay-step-item {
  display: flex;
  align-items: flex-start;
  gap: 10px;
  margin-bottom: 10px;
  font-size: 11px;
  font-weight: 800;
  color: var(--honey-900);
  line-height: 1.4;
}
.pay-step-item:last-child {
  margin-bottom: 0;
}

.pay-step-num {
  width: 22px;
  height: 22px;
  border-radius: 7px;
  background: #fef08a;
  border: 1.5px solid var(--honey-900);
  color: var(--honey-900);
  font-size: 11px;
  font-weight: 900;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}

/* ── UPLOAD PROOF CARD ── */
.upload-card {
  background: #ffffff;
  border: 2px solid var(--honey-900);
  border-radius: 16px;
  padding: 14px;
  margin-bottom: 14px;
  box-shadow: 0 4px 0 var(--honey-900);
}

.upload-card-title {
  font-size: 11.5px;
  font-weight: 900;
  color: var(--honey-900);
  margin-bottom: 8px;
  display: flex;
  align-items: center;
  gap: 6px;
}

.dep-file-ctrl {
  width: 100%;
  background: #fffbeb;
  border: 2px dashed var(--honey-600);
  border-radius: 12px;
  padding: 10px;
  font-size: 11px;
  font-weight: 800;
  color: var(--honey-900);
  cursor: pointer;
  box-sizing: border-box;
  text-align: center;
  margin-bottom: 10px;
  font-family: inherit;
}
.dep-file-ctrl::file-selector-button {
  background: #fde047;
  border: 1.5px solid var(--honey-900);
  border-radius: 8px;
  padding: 5px 10px;
  margin-right: 8px;
  font-weight: 900;
  color: var(--honey-900);
  cursor: pointer;
  font-family: inherit;
}

.btn-upload-submit {
  width: 100%;
  background: linear-gradient(135deg, #f59e0b, #d97706);
  border: 2px solid var(--honey-900);
  border-radius: 10px;
  padding: 10px;
  font-size: 12px;
  font-weight: 900;
  color: #ffffff;
  box-shadow: 0 3px 0 var(--honey-900);
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  font-family: inherit;
  transition: transform 0.1s;
}
.btn-upload-submit:active {
  transform: translateY(2px);
  box-shadow: 0 1px 0 var(--honey-900);
}

/* Cancel Button */
.btn-cancel-link {
  background: none;
  border: none;
  font-size: 12px;
  font-weight: 900;
  color: #b91c1c;
  cursor: pointer;
  padding: 8px;
  width: 100%;
  text-align: center;
  font-family: inherit;
  text-decoration: underline;
}
.btn-cancel-link:disabled {
  color: #94a3b8;
  cursor: not-allowed;
  text-decoration: none;
}

/* ── CONFIRMED & PENDING CELEBRATION STATES ── */
.pay-result-card {
  background: #ffffff;
  border: 3.5px solid var(--honey-900);
  border-radius: 24px;
  box-shadow: 0 6px 0 var(--honey-900);
  padding: 36px 20px;
  text-align: center;
}
.pay-result-icon {
  font-size: 56px;
  margin-bottom: 12px;
}
.pay-result-title {
  font-size: 20px;
  font-weight: 900;
  color: var(--honey-900);
  margin-bottom: 6px;
}
.pay-result-desc {
  font-size: 12px;
  font-weight: 700;
  color: #92400e;
  line-height: 1.4;
  margin-bottom: 24px;
}
</style>

<div class="pay-page">
  <!-- TOP HERO HEADER -->
  <div class="pay-hero">
    <div class="pay-hero-nav">
      <a href="/deposit" class="pay-back-btn" title="Kembali ke Deposit">
        <i class="ph-bold ph-caret-left"></i>
      </a>
      <div class="pay-order-pill">
        <i class="ph-bold ph-receipt"></i>
        <span>Invoice #<?= $dep_id ?></span>
      </div>
      <div class="pay-timer-capsule" id="exp-capsule">
        <i class="ph-bold ph-hourglass-medium"></i>
        <span id="exp-timer">--:--</span>
      </div>
    </div>

    <!-- Mascot Helper Dialogue -->
    <div class="pay-helper-banner">
      <div class="pay-mascot-img">
        <img src="/assets/game/bee_worker.png" alt="Mascot Lebah" style="width:100%;height:100%;object-fit:contain;">
      </div>
      <div class="pay-helper-txt">
        Scan kode QRIS di bawah menggunakan m-Banking atau E-Wallet apa saja. Saldo akan otomatis masuk seketika!
      </div>
    </div>
  </div>

  <div class="pay-body">
    <?php if ($flash): ?>
    <div class="pay-alert pay-alert--<?= $flashType === 'error' ? 'error' : 'success' ?>">
      <i class="ph-bold ph-<?= $flashType === 'error' ? 'warning-circle' : 'check-circle' ?>" style="font-size:16px;"></i>
      <span><?= htmlspecialchars($flash) ?></span>
    </div>
    <?php endif; ?>

    <!-- 1. STATE SUDAH TERKONFIRMASI -->
    <?php if ($dep['status'] === 'confirmed'): ?>
    <div class="pay-result-card">
      <div class="pay-result-icon" style="color: #10b981;">
        <i class="ph-fill ph-check-circle"></i>
      </div>
      <div class="pay-result-title">Pembayaran Berhasil!</div>
      <div class="pay-result-desc">
        Saldo beli sebesar <strong><?= format_rp((float)$amount) ?></strong> telah berhasil ditambahkan ke akunmu.
      </div>
      <a href="/home" class="btn-pay-action amber" style="width:100%;">
        <i class="ph-bold ph-house"></i> Masuk ke Beranda
      </a>
    </div>

    <!-- 2. STATE BUKTI TERUPLOAD MENUNGGU VERIFIKASI -->
    <?php elseif ($dep['proof_image']): ?>
    <div class="pay-result-card">
      <div class="pay-result-icon" style="color: #f59e0b;">
        <i class="ph-fill ph-clock-countdown"></i>
      </div>
      <div class="pay-result-title">Bukti Pembayaran Diterima</div>
      <div class="pay-result-desc">
        Struk transfer sedang diverifikasi oleh admin. Saldo akan segera aktif dalam 1-15 menit.
      </div>
      <a href="/history" class="btn-pay-action amber" style="width:100%;">
        <i class="ph-bold ph-receipt"></i> Cek Riwayat Transaksi
      </a>
    </div>

    <!-- 3. STATE MENUNGGU SCAN PEMBAYARAN QRIS -->
    <?php else: ?>

    <div class="pay-vault-card">
      <div class="pay-vault-hdr">
        <div class="pay-vault-badge">
          <i class="ph-fill ph-qr-code"></i>
          <span>QRIS Standar Nasional</span>
        </div>
        <div class="pay-vault-date">
          <?= date('d M Y, H:i', strtotime($dep['created_at'])) ?>
        </div>
      </div>

      <div class="pay-vault-content">
        <?php if ($qr_url): ?>
        <div class="qr-frame-box">
          <img id="qr-img" src="<?= htmlspecialchars($qr_url) ?>" alt="QRIS Code">
          <div class="qr-scan-corners"></div>
        </div>

        <!-- Acceptance Pills -->
        <div class="pay-partner-strip">
          <span class="partner-pill">BCA</span>
          <span class="partner-pill">Mandiri</span>
          <span class="partner-pill">BRI</span>
          <span class="partner-pill">BNI</span>
          <span class="partner-pill">DANA</span>
          <span class="partner-pill">GoPay</span>
          <span class="partner-pill">OVO</span>
          <span class="partner-pill">ShopeePay</span>
        </div>

        <!-- Total Amount -->
        <div class="pay-amount-box">
          <div class="pay-amount-lbl">Total Pembayaran Pas</div>
          <div class="pay-amount-val" id="raw-amount-text"><?= format_rp((float)$amount) ?></div>
          <button type="button" class="copy-amt-btn" onclick="copyAmount()">
            <i class="ph-bold ph-copy"></i>
            <span>Salin Nominal</span>
          </button>
          <div class="pay-unique-hint">
            Pastikan nominal transfer tepat hingga digit terakhir agar sistem mendeteksi secara otomatis.
          </div>
        </div>

        <!-- QR Quick Actions -->
        <div class="pay-btn-grid">
          <a href="<?= htmlspecialchars($qr_dl_url) ?>" class="btn-pay-action gold">
            <i class="ph-bold ph-download-simple"></i>
            <span>Unduh QR</span>
          </a>
          <a href="<?= htmlspecialchars($qr_url) ?>" target="_blank" class="btn-pay-action amber">
            <i class="ph-bold ph-arrow-square-out"></i>
            <span>Perbesar QR</span>
          </a>
        </div>

        <?php else: ?>
        <div class="pay-alert pay-alert--error" style="margin-bottom:0;">
          <i class="ph-bold ph-warning-circle"></i>
          <span>QRIS belum dikonfigurasi. Hubungi Admin.</span>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Live Status Monitor -->
    <div class="pay-monitor-card">
      <div class="monitor-pulse-row">
        <div class="monitor-pulse-dot"></div>
        <span>Sistem mengecek transaksi otomatis setiap 5 detik...</span>
      </div>
      <button id="btn-check-status" onclick="manualCheckStatus()" class="btn-check-live">
        <i class="ph-bold ph-arrows-clockwise"></i>
        <span>Cek Status Pembayaran</span>
      </button>
    </div>

    <!-- Mini Steps Card -->
    <div class="pay-steps-card">
      <div class="pay-steps-title">
        <i class="ph-bold ph-list-numbers"></i>
        <span>3 Langkah Pembayaran Praktis</span>
      </div>
      <div class="pay-step-item">
        <div class="pay-step-num">1</div>
        <div>Buka aplikasi m-Banking (BCA, Mandiri, BRI, BNI) atau E-Wallet (DANA, GoPay, OVO, ShopeePay).</div>
      </div>
      <div class="pay-step-item">
        <div class="pay-step-num">2</div>
        <div>Pilih fitur <strong>Scan / Bayar QRIS</strong>, lalu arahkan kamera ke kode QR di atas (atau pilih dari galeri foto).</div>
      </div>
      <div class="pay-step-item">
        <div class="pay-step-num">3</div>
        <div>Periksa nominal agar sesuai, konfirmasi PIN Anda. Saldo beli akan masuk otomatis tanpa perlu konfirmasi manual!</div>
      </div>
    </div>

    <!-- Upload Proof (Fallback) -->
    <?php 
    $pending_secs = time() - $created_ts;
    $show_upload = ($confirm_mode !== 'auto' || $pending_secs >= 300);
    ?>
    <div id="upload-proof-card" class="upload-card" style="display: <?= $show_upload ? 'block' : 'none' ?>;">
      <form method="POST" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="upload_proof">
        <div class="upload-card-title">
          <i class="ph-fill ph-file-arrow-up"></i>
          <span>Unggah Struk Pembayaran (Opsional)</span>
        </div>
        <input class="dep-file-ctrl" type="file" name="proof" accept="image/*" required>
        <button type="submit" class="btn-upload-submit">
          <i class="ph-bold ph-paper-plane-tilt"></i>
          <span>Kirim Bukti Pembayaran</span>
        </button>
      </form>
    </div>

    <!-- Cancel Deposit -->
    <form method="POST" style="margin: 6px 0 14px;">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="cancel_deposit">
      <button id="btn-cancel-dep" type="submit" class="btn-cancel-link">Batalkan Transaksi Deposit</button>
    </form>

    <script>
    const DEP_ID      = <?= $dep_id ?>;
    const CSRF_TOK    = '<?= csrf_token() ?>';
    const EXPIRE_SECS = <?= $expire_secs ?>;
    const RAW_AMOUNT  = <?= (int)$amount ?>;
    let isChecking    = false;

    function copyAmount() {
      const textToCopy = RAW_AMOUNT.toString();
      if (typeof nToast !== 'undefined' && nToast.copy) {
        nToast.copy(textToCopy, 'Nominal Transfer');
      } else {
        navigator.clipboard.writeText(textToCopy).then(() => {
          if (typeof nToast === 'function') {
            nToast('Nominal Rp ' + Number(textToCopy).toLocaleString('id-ID') + ' disalin!', 'success');
          }
        });
      }
    }

    let expSecs = EXPIRE_SECS;
    const timerEl = document.getElementById('exp-timer');
    const capsuleEl = document.getElementById('exp-capsule');

    function updateExpTimer() {
      if (expSecs <= 0) {
        if (timerEl) timerEl.textContent = 'Kedaluwarsa';
        return;
      }
      const m = Math.floor(expSecs / 60), s = expSecs % 60;
      if (timerEl) timerEl.textContent = String(m).padStart(2,'0') + ':' + String(s).padStart(2,'0');
    }
    updateExpTimer();
    const expTimer = setInterval(() => {
      expSecs--;
      updateExpTimer();
      
      const elapsed = 3600 - expSecs;
      if (elapsed >= 300) {
        const upCard = document.getElementById('upload-proof-card');
        if (upCard && upCard.style.display === 'none') {
            upCard.style.display = 'block';
        }
      }

      if (expSecs <= 0) clearInterval(expTimer);
    }, 1000);

    const pollStatus = () => {
      if (isChecking) return;
      fetch('?id=' + DEP_ID, {
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:'_csrf=' + CSRF_TOK + '&action=check_status'
      }).then(r=>r.json()).then(d=>{
        if(d.confirmed) confirmAndRedirect();
      }).catch(()=>{});
    };
    const pollTimer = setInterval(pollStatus, 5000);

    function confirmAndRedirect() {
      clearInterval(pollTimer);
      clearInterval(expTimer);
      if (timerEl) timerEl.textContent = 'Sukses';
      if (typeof nToast === 'function') nToast('Pembayaran berhasil dikonfirmasi!', 'success');
      setTimeout(() => location.href = '/history?tab=deposit', 1200);
    }

    const manualCheckStatus = () => {
      if (isChecking) return;
      isChecking = true;
      const btn = document.getElementById('btn-check-status');
      const orig = btn.innerHTML;
      btn.disabled = true;
      btn.innerHTML = '<i class="ph-bold ph-spinner ph-spin"></i> Mengecek Transaksi...';

      fetch('?id=' + DEP_ID, {
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:'_csrf=' + CSRF_TOK + '&action=check_status'
      }).then(r=>r.json()).then(d=>{
        isChecking = false;
        btn.disabled = false;
        btn.innerHTML = orig;
        if (d.confirmed) {
          confirmAndRedirect();
        } else {
          if (typeof nToast === 'function') {
            nToast('Pembayaran belum terdeteksi. Silakan coba sesaat lagi.', 'warn');
          }
        }
      }).catch(()=>{
        isChecking = false;
        btn.disabled = false;
        btn.innerHTML = orig;
        if (typeof nToast === 'function') {
          nToast('Gagal menghubungi server.', 'error');
        }
      });
    };

    let cancelSecs = <?= $cancel_secs_left ?>;
    const cancelBtn = document.getElementById('btn-cancel-dep');
    if (cancelBtn && cancelSecs > 0) {
      cancelBtn.disabled = true;
      cancelBtn.textContent = 'Tunggu ' + cancelSecs + 's untuk membatalkan';
      const ci = setInterval(() => {
        cancelSecs--;
        cancelBtn.textContent = cancelSecs > 0 ? 'Tunggu ' + cancelSecs + 's untuk membatalkan' : 'Batalkan Transaksi Deposit';
        if (cancelSecs <= 0) {
          clearInterval(ci);
          cancelBtn.disabled = false;
        }
      }, 1000);
    }
    </script>
    <?php endif; ?>
  </div>
</div>

<?php require dirname(__DIR__) . '/partials/footer.php'; ?>
