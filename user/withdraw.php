<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/auth/guard.php';

$flash = $flashType = '';
$wd_lock_notice = setting($pdo, 'wd_lock_notice', 'Penarikan hanya bisa dilakukan pada jam tertentu.');
$wd_lock_start  = setting($pdo, 'wd_lock_start', '');
$wd_lock_end    = setting($pdo, 'wd_lock_end', '');

// Fetch membership min/max WD — only if membership is still active (not expired)
$user_mem = null;
$membership_active = $user['membership_id']
    && $user['membership_expires_at']
    && strtotime((string)$user['membership_expires_at']) > time();

if ($membership_active) {
    $stmt = $pdo->prepare("SELECT name, min_wd, max_wd, wd_hold, allow_edit_bank FROM memberships WHERE id = ? AND is_active = 1");
    $stmt->execute([$user['membership_id']]);
    $user_mem = $stmt->fetch() ?: null;
}

// Fallback ke paket gratis jika tidak ada membership aktif
if (!$user_mem) {
    $stmt = $pdo->prepare("SELECT name, min_wd, max_wd, wd_hold, allow_edit_bank FROM memberships WHERE price = 0 AND is_active = 1 ORDER BY sort_order ASC LIMIT 1");
    $stmt->execute();
    $user_mem = $stmt->fetch() ?: null;
}

$min_withdraw  = $user_mem ? (float)$user_mem['min_wd'] : 0;
$max_withdraw  = $user_mem ? (float)$user_mem['max_wd'] : 0;
$max_available = min((float)$user['balance_wd'], $max_withdraw > 0 ? $max_withdraw : (float)$user['balance_wd']);

$predefined_amounts = [10000,  50000, 100000, 150000, 250000, 500000, 1000000, 2500000, 5000000];

if ($min_withdraw > 0 && !in_array((int)$min_withdraw, $predefined_amounts, true)) {
    $predefined_amounts[] = (int)$min_withdraw;
}
if ($max_withdraw > 0 && !in_array((int)$max_withdraw, $predefined_amounts, true)) {
    $predefined_amounts[] = (int)$max_withdraw;
}

sort($predefined_amounts);

$has_bank = !empty($user['bank_name']) && !empty($user['account_number']) && !empty($user['account_name']);

$wd_locked = is_wd_locked($pdo);
$wd_global_enabled = setting($pdo, 'wd_global_enabled', '1') === '1';

// Level block — only enforced if admin enables the toggle
$wd_require_level = setting($pdo, 'wd_require_level', '0') === '1';
$wd_min_level  = (int) setting($pdo, 'wd_min_level', '0');
$user_level    = user_membership_level($pdo, $user);
$level_blocked = $wd_require_level && $wd_min_level > 0 && $user_level < $wd_min_level;

$available_amounts = [];
foreach ($predefined_amounts as $amt) {
    if ($level_blocked) {
        // Jika terblokir karena butuh upgrade, pamerkan opsi 50rb s/d 500rb
        if ($amt >= 50000 && $amt <= 500000) {
            $available_amounts[] = $amt;
        }
    } else {
        if ($amt >= $min_withdraw && ($max_withdraw == 0 || $amt <= $max_withdraw)) {
            $available_amounts[] = $amt;
        }
    }
}

$min_level_name = '';
if ($wd_require_level && $wd_min_level > 0) {
    $lv = $pdo->prepare("SELECT name FROM memberships WHERE sort_order=? AND is_active=1 LIMIT 1");
    $lv->execute([$wd_min_level]);
    $min_level_name = $lv->fetchColumn() ?: "Level {$wd_min_level}";
}

// Cek apakah ada WD pending
$pending_wd = $pdo->prepare("SELECT id FROM withdrawals WHERE user_id=? AND status IN ('pending', 'hold') LIMIT 1");
$pending_wd->execute([$user['id']]);
$has_pending_wd = (bool)$pending_wd->fetchColumn();

$is_free_level = !$membership_active;
$free_wd_limit_reached = false;
$free_wrong_bank = false;
$free_age_blocked = false;

if ($is_free_level) {
    $wd_free_only_dana = setting($pdo, 'wd_free_only_dana', '1') === '1';
    $wd_free_limit_1x  = setting($pdo, 'wd_free_limit_1x', '1') === '1';
    $wd_free_require_1day = setting($pdo, 'wd_free_require_1day', '1') === '1';
    
    if ($wd_free_require_1day && strtotime($user['created_at']) > strtotime('-1 day')) {
        $free_age_blocked = true;
    }
    
    if ($wd_free_only_dana && $has_bank && strtolower(trim($user['bank_name'])) !== 'dana') {
        $free_wrong_bank = true;
    }
    
    if ($wd_free_limit_1x) {
        $wd_cnt = $pdo->prepare("SELECT COUNT(*) FROM withdrawals WHERE user_id=? AND status='approved'");
        $wd_cnt->execute([$user['id']]);
        if ($wd_cnt->fetchColumn() >= 1) {
            $free_wd_limit_reached = true;
        }
    }
}

// ── Double-submit prevention ──────────────────────────────────────────────
$_ftk_wd = 'wd_form_token';
if (empty($_SESSION[$_ftk_wd])) $_SESSION[$_ftk_wd] = bin2hex(random_bytes(16));
$_form_token_wd = $_SESSION[$_ftk_wd];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submitted_ftk_wd = $_POST['form_token'] ?? '';
    if (!hash_equals($_SESSION[$_ftk_wd] ?? '', $submitted_ftk_wd)) {
        $flash = '⚠️ Request kamu gagal diproses atau gak valid. Coba refresh halaman dulu ya!';
        $flashType = 'error';
    } elseif (!$user['can_withdraw']) {
        $flash = '❌ Akses withdraw kamu dibatasi nih. Hubungi admin yuk buat info lebih lanjut!'; $flashType = 'error';
    } elseif (!$wd_global_enabled) {
        $flash = '🔴 Fitur penarikan saat ini sedang dinonaktifkan secara global (Maintenance).'; $flashType = 'error';
    } elseif ($wd_locked) {
        $flash = '⏰ ' . $wd_lock_notice; $flashType = 'error';
    } elseif ($free_wd_limit_reached) {
        $flash = '❌ Level ' . $user_mem['name'] . ' maksimal WD 1 kali. Yuk upgrade level buat tarik dana sepuasnya!'; $flashType = 'error';
    } elseif ($free_wrong_bank) {
        $flash = '❌ Level ' . $user_mem['name'] . ' hanya bisa menarik ke e-wallet DANA. Silakan ganti rekening Anda!'; $flashType = 'error';
    } elseif ($has_pending_wd) {
        $flash = '⏳ Kamu masih punya request WD yang lagi diproses nih. Tunggu kelar dulu ya!'; $flashType = 'error';
    } elseif (!empty($user_mem['is_wd_disabled'])) {
        $flash = '🔴 Penarikan untuk level Anda saat ini sedang ditutup (Maintenance). Silakan upgrade level Anda!'; $flashType = 'error';
    } elseif ($level_blocked) {
        if ((float)$user['balance_wd'] < 50000) {
            $flash = 'Minimal withdraw Rp 50.000 ya.'; $flashType = 'error';
        } else {
            $flash = "Upgrade ke {$min_level_name} dulu yuk biar bisa tarik saldo!"; $flashType = 'error';
        }
    } elseif ($free_age_blocked) {
        $flash = 'Akun harus berumur min. 1 hari untuk WD (Level ' . $user_mem['name'] . ').'; $flashType = 'error';
    } else {
        $amount  = (float) preg_replace('/\D/', '', $_POST['amount'] ?? '0');
        
        $bank    = $has_bank ? $user['bank_name'] : trim($_POST['bank_name'] ?? '');
        $accnum  = $has_bank ? $user['account_number'] : trim($_POST['account_number'] ?? '');
        $accname = $has_bank ? $user['account_name'] : trim($_POST['account_name'] ?? '');

        if (!in_array((int)$amount, $available_amounts, true)) {
            $flash = 'Nominal penarikan gak valid nih. Harus pilih dari daftar ya!'; $flashType = 'error';
        } elseif ($is_free_level && setting($pdo, 'wd_free_only_dana', '1') === '1' && strtolower($bank) !== 'dana') {
            $flash = 'Level ' . $user_mem['name'] . ' hanya bisa menggunakan e-wallet DANA.'; $flashType = 'error';
        } elseif ($amount < $min_withdraw) {
            $flash = 'Minimal withdraw ' . format_rp($min_withdraw) . ' ya.'; $flashType = 'error';
        } elseif ($max_withdraw > 0 && $amount > $max_withdraw) {
            $flash = 'Maksimal withdraw ' . format_rp($max_withdraw) . ' ya.'; $flashType = 'error';
        } elseif ($amount > (float)$user['balance_wd']) {
            $flash = 'Saldo penarikan kamu gak cukup nih.'; $flashType = 'error';
        } elseif (!$bank || !$accnum || !$accname) {
            $flash = 'Lengkapi dulu data rekeningmu ya.'; $flashType = 'error';
        } else {
            $pdo->beginTransaction();
            if (!$has_bank) {
                $pdo->prepare("UPDATE users SET bank_name=?, account_number=?, account_name=? WHERE id=?")->execute([$bank, $accnum, $accname, $user['id']]);
                $has_bank = true;
                $user['bank_name'] = $bank;
                $user['account_number'] = $accnum;
                $user['account_name'] = $accname;
            }
            $is_auto_hold = (isset($user_mem['wd_hold']) && $user_mem['wd_hold'] == 1);
            $wd_status = $is_auto_hold ? 'hold' : 'pending';
            $admin_note = null;
            
            $pdo->prepare("UPDATE users SET balance_wd=balance_wd-? WHERE id=?")->execute([$amount, $user['id']]);
            $pdo->prepare("INSERT INTO withdrawals (user_id,amount,bank_name,account_number,account_name,status,admin_note) VALUES (?,?,?,?,?,?,?)")
                ->execute([$user['id'], $amount, $bank, $accnum, $accname, $wd_status, $admin_note]);
            $wd_id = $pdo->lastInsertId();
            $pdo->commit();
            $us = $pdo->prepare("SELECT * FROM users WHERE id=?"); $us->execute([$user['id']]); $user = $us->fetch();
            
            $levelInfo = $user_mem ? ($user_mem['name'] ?? 'Free') : 'Free';
            $wdHoldNote = $is_auto_hold ? ' ⏳ (Auto Hold Scheduled)' : '';
            $msg = "<b>💸 WITHDRAW BARU</b>\n👤 User: {$user['username']}\n🏅 Level: {$levelInfo}{$wdHoldNote}\n💰 Amount: " . format_rp((float)$amount) . "\n🏦 Bank: {$bank} - {$accnum}\n👨‍💼 a/n: {$accname}\n📋 Status: " . ucfirst($wd_status);
            $kb = [
                [['text'=>'✅ Approve', 'callback_data'=>'wd_approve_'.$wd_id], ['text'=>'❌ Reject', 'callback_data'=>'wd_reject_'.$wd_id]],
                [['text'=>'⏸ Hold (Selesai non-refund)', 'callback_data'=>'wd_hold_'.$wd_id]],
                [['text'=>'🔄 Refresh Status', 'callback_data'=>'refresh_wd_'.$wd_id]]
            ];
            send_telegram_notif($pdo, $msg, $kb, 'wd');
            
            // Regenerate token
            $_SESSION[$_ftk_wd] = bin2hex(random_bytes(16));
            $flash = '✅ Request withdraw berhasil dikirim! Proses 1-10 menit ya.';
            $flashType = 'success';
        }
    }
    
    if (isset($_POST['ajax'])) {
        header('Content-Type: application/json');
        if ($flashType === 'error' || $flash === '') {
            echo json_encode(['error' => $flash ?: 'Terjadi kesalahan.']);
        } else {
            echo json_encode(['ok' => true, 'message' => $flash]);
        }
        exit;
    }
}
end_wd:

$wds = $pdo->prepare("SELECT * FROM withdrawals WHERE user_id=? ORDER BY created_at DESC LIMIT 6");
$wds->execute([$user['id']]);
$wds = $wds->fetchAll();

$channels = $pdo->query("SELECT name, logo FROM payment_channels WHERE logo IS NOT NULL AND logo != ''")->fetchAll();
$channel_logos = [];
foreach ($channels as $c) {
    $channel_logos[strtolower($c['name'])] = $c['logo'];
}

$stmtPendingBank = $pdo->prepare("SELECT id FROM admin_requests WHERE user_id=? AND type='change_bank' AND status='pending'");
$stmtPendingBank->execute([$user['id']]);
$has_pending_bank = (bool)$stmtPendingBank->fetchColumn();

$wd_estimation = '';
if (!$wd_global_enabled) {
    $wd_estimation = "🔴 Penarikan saat ini <strong>DINONAKTIFKAN</strong> (Maintenance).";
} elseif ($wd_lock_start && $wd_lock_end) {
    $now_ts = time();
    $s_ts = strtotime(date('Y-m-d ') . $wd_lock_start);
    $e_ts = strtotime(date('Y-m-d ') . $wd_lock_end);
    
    if ($wd_locked) {
        if ($e_ts <= $now_ts) $e_ts += 86400;
        $diff = $e_ts - $now_ts;
        $h = floor($diff / 3600);
        $m = floor(($diff % 3600) / 60);
        $wd_estimation = "Penarikan saat ini <strong>DITUTUP</strong>. Akan dibuka dalam <strong>{$h} jam {$m} menit</strong>.";
    } else {
        $wd_estimation = "✅ Penarikan Buka (Tutup Jam " . date('H:i', strtotime($wd_lock_start)) . ")";
    }
}

$pageTitle  = 'Withdraw  ';
$activePage = 'withdraw';
require dirname(__DIR__) . '/partials/header.php';
?>

<style>
/* ══════════════════════════════════════════════
   WITHDRAW PAGE — CASUAL GAME STYLE (ORANGE)
   ══════════════════════════════════════════════ */
html body { 
  background-color: #fef8ee !important; 
  background-image: radial-gradient(rgba(217, 119, 6, 0.08) 1.5px, transparent 1.5px) !important;
  background-size: 16px 16px !important;
  margin: 0; padding: 0; 
  font-family: 'Nunito', sans-serif; 
}

.wd-container {
  display: flex;
  flex-direction: column;
  min-height: 100vh;
  max-width: 480px;
  margin: 0 auto;
  background: transparent;
  box-shadow: none;
}

/* ── COHESIVE TOP BANNER ── */
.wd-top {
  background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
  position: relative;
  padding: 16px 14px 28px;
  border-bottom: 3.5px solid #78350f;
  box-shadow: 0 4px 0 #78350f;
}

.wd-top-bar {
  display: flex;
  align-items: flex-start;
  gap: 10px;
}

.wd-back-btn {
  width: 36px; height: 36px;
  background: #fde68a;
  border: 2.5px solid #78350f;
  border-radius: 12px;
  display: flex; align-items: center; justify-content: center;
  color: #78350f; font-size: 16px;
  box-shadow: 0 3px 0 #78350f;
  text-decoration: none;
  flex-shrink: 0;
  transition: transform 0.1s;
}
.wd-back-btn:active { transform: translateY(2px); box-shadow: 0 1px 0 #78350f; }

.wd-notice-pill {
  flex: 1;
  background: #fffbeb;
  border: 2.5px solid #78350f;
  border-radius: 16px;
  padding: 8px 12px;
  display: flex;
  align-items: center;
  gap: 8px;
  box-shadow: 0 3px 0 #78350f;
}
.wd-notice-icon {
  font-size: 20px;
  flex-shrink: 0;
}
.wd-notice-txt {
  font-size: 11px;
  font-weight: 800;
  color: #78350f;
  line-height: 1.25;
}

.wd-dog-mascot {
  display: none;
}

/* ── AMBER BODY SECTION ── */
.wd-body {
  flex: 1;
  background: transparent;
  padding: 16px 14px 100px;
  position: relative;
}
.wd-body::before {
  content: '';
  position: absolute;
  inset: 0;
  background-image: radial-gradient(rgba(217, 119, 6, 0.05) 1.5px, transparent 1.5px);
  background-size: 16px 16px;
  pointer-events: none;
}

/* ── SALDO ROW ── */
.wd-saldo-row {
  background: #fef08a;
  border: 3px solid #78350f;
  border-radius: 20px;
  padding: 14px 16px;
  box-shadow: 0 6px 0 #78350f;
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 24px;
  position: relative;
  z-index: 2;
}
.wd-saldo-lbl {
  font-size: 13px;
  font-weight: 900;
  color: #854d0e;
  text-transform: uppercase;
  letter-spacing: 0.5px;
}
.wd-saldo-val {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 24px;
  font-weight: 900;
  color: #78350f;
  text-shadow: none;
  font-style: normal;
}
.wd-saldo-icon {
  font-size: 22px;
  font-style: normal;
  filter: none;
}

/* ── AMOUNT SELECTION ── */
.wd-section-hdr {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 12px;
  position: relative;
  z-index: 2;
}
.wd-sh-title {
  font-size: 15px;
  font-weight: 900;
  color: #78350f;
}
.wd-sh-badge {
  background: #fef08a;
  color: #c2410c;
  font-size: 10px;
  font-weight: 900;
  padding: 4px 10px;
  border-radius: 12px;
  border: 1.5px solid #78350f;
  box-shadow: 0 2px 0 #78350f;
}

.wd-amt-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 12px;
  margin-bottom: 24px;
  position: relative;
  z-index: 2;
}
.wd-amt-btn {
  background: #fffbeb;
  border: 2px solid #78350f;
  border-radius: 14px;
  padding: 16px 8px;
  text-align: center;
  font-size: 16px;
  font-weight: 900;
  color: #78350f;
  box-shadow: 0 4px 0 #78350f, 0 8px 10px rgba(0,0,0,0.05);
  cursor: pointer;
  transition: transform 0.1s, box-shadow 0.1s;
  outline: none;
}
.wd-amt-btn:active {
  transform: translateY(4px);
  box-shadow: 0 0px 0 #78350f;
}
.wd-amt-btn.active {
  background: #4ade80;
  border-color: #78350f;
  color: #fff;
  box-shadow: 0 4px 0 #78350f;
  text-shadow: 0 1px 2px rgba(0,0,0,0.15);
}
.wd-amt-btn.active:active { box-shadow: 0 0 0 #78350f; transform: translateY(4px); }

.wd-amt-disabled {
  display: none;
}

/* ── WALLET / BANK ROW ── */
.wd-wallet-row {
  background: #fffbeb;
  border: 2.5px solid #78350f;
  border-radius: 20px;
  padding: 12px 14px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 24px;
  position: relative;
  z-index: 2;
  box-shadow: 0 4px 0 #78350f;
}
.wd-w-left {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 13px;
  font-weight: 900;
  color: #78350f;
}
.wd-w-logo {
  width: 20px;
  height: 20px;
  object-fit: contain;
}
.wd-w-right {
  font-size: 12px;
  font-weight: 800;
  color: #9a3412;
  display: flex;
  align-items: center;
  gap: 4px;
}

/* In case user hasn't set bank, we render inputs inside this row aesthetic */
.wd-input-grp { margin-bottom: 12px; position:relative; z-index:2; }
.wd-input-grp label { display:block; font-size:11px; font-weight:900; color:#fff; margin-bottom:4px; text-shadow:0 1px 2px rgba(0,0,0,0.2); }
.wd-input-grp input { width:100%; padding:12px; border-radius:14px; border:2.5px solid #78350f; background:#fffbeb; font-weight:800; font-size:12px; color:#78350f; box-shadow:0 4px 0 #78350f; outline:none; }
.wd-input-grp input:focus { border-color:#d97706; }

/* ── SUBMIT BUTTON ── */
.wd-submit-btn {
  width: 100%;
  background: linear-gradient(135deg, #4ade80 0%, #22c55e 100%);
  border: 3px solid #78350f;
  border-radius: 20px;
  padding: 14px;
  font-size: 16px;
  font-weight: 900;
  color: #fff;
  text-shadow: 0 1px 2px rgba(0,0,0,0.15);
  box-shadow: 0 5px 0 #78350f;
  cursor: pointer;
  transition: transform 0.1s, box-shadow 0.1s;
  position: relative;
  z-index: 2;
}
.wd-submit-btn::before { display: none; }
.wd-submit-btn:active {
  transform: translateY(4px);
  box-shadow: 0 1px 0 #78350f;
}
.wd-submit-btn:disabled {
  background: #cbd5e1; border-color:#78350f; color:#64748b; box-shadow:none; transform:none;
}
.wd-upgrade-cta {
  display: flex; align-items: center; justify-content: center; gap: 7px;
  margin-top: 10px;
  width: 100%;
  background: linear-gradient(135deg, #fde047 0%, #f59e0b 100%);
  border: 3px solid #78350f;
  border-radius: 20px;
  padding: 13px;
  font-size: 14px;
  font-weight: 900;
  color: #78350f;
  text-decoration: none;
  box-shadow: 0 5px 0 #78350f;
  transition: transform 0.1s, box-shadow 0.1s;
  font-family: 'Nunito', sans-serif;
}
.wd-upgrade-cta:active { transform: translateY(4px); box-shadow: 0 1px 0 #78350f; }
.wd-upgrade-cta i { font-size: 18px; }

/* Modals */
.cg-modal { display:none; position:fixed; inset:0; background:rgba(0,0,0,0.6); z-index:99999; align-items:center; justify-content:center; padding:16px; backdrop-filter:blur(3px); }
.cg-modal-box { background:#fffbeb; width:100%; max-width:320px; border-radius:24px; border:3px solid #78350f; box-shadow:0 8px 0 #78350f; overflow:hidden; animation:popIn 0.3s cubic-bezier(0.175,0.885,0.32,1.275); }
.cg-modal-hdr { background:linear-gradient(135deg, #fde047, #f59e0b); padding:14px; text-align:center; color:#78350f; font-weight:900; font-size:14px; border-bottom:2.5px solid #78350f; }
.cg-modal-bd { padding:20px; text-align:center; color:#78350f; font-weight:800; font-size:13px; }
.cg-modal-actions { display:flex; gap:10px; padding:0 20px 20px; }
.cg-btn-cancel { flex:1; padding:12px; background:#f1f5f9; border:2.5px solid #78350f; border-radius:12px; font-weight:900; color:#64748b; font-size:12px; box-shadow:0 4px 0 #78350f; cursor:pointer; }
.cg-btn-confirm { flex:1.5; padding:12px; background:#4ade80; border:2.5px solid #78350f; border-radius:12px; font-weight:900; color:#fff; box-shadow:0 4px 0 #78350f; font-size:12px; cursor:pointer; }
.cg-btn-confirm:active { transform:translateY(3px); box-shadow:0 1px 0 #78350f; }
.cg-btn-cancel:active { transform:translateY(3px); box-shadow:0 1px 0 #78350f; }
@keyframes popIn { from{transform:scale(0.8);opacity:0;} to{transform:scale(1);opacity:1;} }
</style>

<div class="wd-container">
  <!-- TOP BANNER -->
  <div class="wd-top">
    <div class="wd-top-bar">
      <a href="/home" class="wd-back-btn"><i class="ph-bold ph-arrow-left"></i></a>
      
      <?php if ($wd_estimation && $wd_locked): ?>
        <div class="wd-notice-pill" style="background:#fef2f2; border-color:#dc2626; box-shadow:0 4px 0 #991b1b;">
          <div class="wd-notice-icon">🔒</div>
          <div class="wd-notice-txt" style="color:#7f1d1d"><?= strip_tags($wd_estimation) ?></div>
        </div>
      <?php elseif ($has_pending_wd): ?>
        <div class="wd-notice-pill">
          <div class="wd-notice-icon">🔔</div>
          <div class="wd-notice-txt">Penarikan sedang diproses, diperkirakan dalam 24 jam.</div>
        </div>
      <?php elseif ($flash): ?>
        <div class="wd-notice-pill" style="<?= $flashType==='error' ? 'background:#fef2f2; border-color:#dc2626; box-shadow:0 4px 0 #991b1b;' : 'background:#f0fdf4; border-color:#16a34a; box-shadow:0 4px 0 #14532d;' ?>">
          <div class="wd-notice-icon"><?= $flashType==='error' ? '❌' : '✨' ?></div>
          <div class="wd-notice-txt" style="<?= $flashType==='error' ? 'color:#7f1d1d' : 'color:#14532d' ?>"><?= htmlspecialchars($flash) ?></div>
        </div>
      <?php else: ?>
        <div class="wd-notice-pill">
          <img src="/assets/game/bee_worker.png" alt="Buzzy" style="width:24px;height:24px;object-fit:contain;flex-shrink:0;">
          <div class="wd-notice-txt">Pilih nominal dan tarik cuan tontonan & hasil madu ke rekeningmu!</div>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- BODY -->
  <div class="wd-body">
    <!-- SALDO ROW -->
    <div class="wd-saldo-row">
      <div class="wd-saldo-lbl">Saldo</div>
      <div class="wd-saldo-val">
        <span class="wd-saldo-icon">💵</span>
        <?= format_rp((float)$user['balance_wd']) ?>
      </div>
    </div>

    <!-- FORM -->
    <form method="POST" id="wd-form">
      <?= csrf_field() ?>
      <input type="hidden" name="form_token" value="<?= htmlspecialchars($_form_token_wd) ?>">
      <input type="hidden" name="amount" id="selected-amount" value="" required>

      <div class="wd-section-hdr">
        <div class="wd-sh-title">Pilih jumlah</div>
        <div class="wd-sh-badge">Dapatkan uang tunai ></div>
      </div>

      <div class="wd-amt-grid">
        <?php 
        // We render available amounts as active buttons
        // and add a couple of fake/disabled ones to match the screenshot vibe
        // e.g. Rp 300, Rp 10K if they are not in available
        
        $rendered = 0;
        foreach ($available_amounts as $amt): 
        ?>
          <button type="button" class="wd-amt-btn" data-value="<?= $amt ?>" onclick="selectWdAmount(this, <?= $amt ?>)">
            <?= format_rp($amt) ?>
          </button>
        <?php $rendered++; endforeach; ?>

        <?php if ($rendered == 0): ?>
          <div style="grid-column: 1/-1; background:rgba(255,255,255,0.9); border-radius:14px; padding:16px; text-align:center; font-weight:800; color:#c2410c;">
            Belum ada opsi penarikan yang tersedia untuk saat ini.
          </div>
        <?php endif; ?>


      </div>

      <!-- WALLET SELECTOR -->
      <?php if ($has_bank): ?>
        <div class="wd-wallet-row" onclick="window.location.href='/edit-rekening'" style="cursor:pointer;">
          <div class="wd-w-left">
            <?php 
            $user_wl = $channel_logos[strtolower($user['bank_name'] ?? '')] ?? null; 
            if ($user_wl): ?>
              <img src="/assets/banks/<?= htmlspecialchars($user_wl) ?>" class="wd-w-logo">
            <?php else: ?>
              <i class="ph-bold ph-bank" style="font-size:18px; color:#c2410c"></i>
            <?php endif; ?>
            <?= htmlspecialchars($user['bank_name']) ?>
          </div>
          <div class="wd-w-right">
            <?= htmlspecialchars(mask_account($user['account_number'] ?? '')) ?> >
          </div>
        </div>
      <?php else: ?>
        <div class="wd-input-grp">
          <label>Bank / E-Wallet</label>
          <input type="text" name="bank_name" value="<?= $is_free_level ? 'DANA' : '' ?>" <?= $is_free_level ? 'readonly' : 'placeholder="BCA, Dana, OVO..." required' ?>>
        </div>
        <div class="wd-input-grp">
          <label>Nomor Rekening / No HP</label>
          <input type="text" name="account_number" placeholder="08xxxxxxxx" required>
        </div>
        <div class="wd-input-grp" style="margin-bottom:24px;">
          <label>Nama Pemilik (Sesuai Rekening)</label>
          <input type="text" name="account_name" placeholder="Nama Lengkap" required>
        </div>
      <?php endif; ?>

      <!-- SUBMIT BUTTON -->
      <?php if ($free_wd_limit_reached): ?>
        <button type="button" class="wd-submit-btn" disabled>Tarik (Limit Habis)</button>
      <?php elseif ($free_wrong_bank): ?>
        <button type="button" class="wd-submit-btn" disabled>Tarik (Hanya DANA Untuk Level <?= htmlspecialchars($user_mem['name']) ?>)</button>
      <?php elseif ($wd_locked): ?>
        <button type="button" class="wd-submit-btn" disabled>Tarik (Terkunci)</button>
      <?php elseif ($level_blocked): ?>
        <?php if ((float)$user['balance_wd'] < 50000): ?>
          <button type="button" class="wd-submit-btn" disabled>Tarik (Minimal Rp 50.000)</button>
        <?php else: ?>
          <button type="button" class="wd-submit-btn" disabled>Tarik (Butuh Upgrade)</button>
          <a href="/upgrade" class="wd-upgrade-cta">
            <i class="ph-fill ph-crown"></i> Mau Upgrade? Klik di sini →
          </a>
        <?php endif; ?>
      <?php elseif ($free_age_blocked): ?>
        <button type="button" class="wd-submit-btn" disabled>Tarik (Akun Baru belum bisa narik)</button>
      <?php elseif (!$user['can_withdraw']): ?>
        <button type="button" class="wd-submit-btn" disabled>Tarik (Akses Dibatasi)</button>
      <?php elseif ($has_pending_bank): ?>
        <button type="button" class="wd-submit-btn" disabled>Tarik (Verifikasi Bank)</button>
      <?php elseif ($has_pending_wd): ?>
        <button type="button" class="wd-submit-btn" disabled>Ada Penarikan Pending</button>
      <?php elseif ((float)$user['balance_wd'] < $min_withdraw): ?>
        <button type="button" class="wd-submit-btn" disabled>Saldo Kurang</button>
      <?php else: ?>
        <button type="submit" id="wd-submit-btn" class="wd-submit-btn">Tarik</button>
      <?php endif; ?>

    </form>
  </div>
</div>

<!-- CONFIRM MODAL -->
<div id="cg-modal" class="cg-modal">
  <div class="cg-modal-box">
    <div class="cg-modal-hdr">Konfirmasi Penarikan</div>
    <div class="cg-modal-bd">
      <div style="font-size:12px;font-weight:800;color:#9a3412;margin-bottom:8px">Total Tarik Dana</div>
      <div id="cg-modal-amt" style="font-size:28px;font-weight:900;color:#7c2d12;margin-bottom:12px;letter-spacing:-1px"></div>
      <?php if (!$has_bank): ?>
      <div style="font-size:10px;font-weight:800;color:#ef4444;background:#fef2f2;padding:8px;border-radius:10px;border:1px solid #fca5a5;">Pastikan data rekening sudah benar!</div>
      <?php endif; ?>
    </div>
    <div class="cg-modal-actions">
      <button type="button" class="cg-btn-cancel" onclick="document.getElementById('cg-modal').style.display='none'">Batal</button>
      <button type="button" class="cg-btn-confirm" onclick="confirmCGWd()">Gas Tarik!</button>
    </div>
  </div>
</div>

<script>
function selectWdAmount(btn, val) {
  document.querySelectorAll('.wd-amt-btn').forEach(el => el.classList.remove('active'));
  btn.classList.add('active');
  document.getElementById('selected-amount').value = val;
}

(function(){
  const form  = document.getElementById('wd-form');
  const btn   = document.getElementById('wd-submit-btn');
  const minWd = <?= (int)$min_withdraw ?>;
  const maxWd = <?= (float)$max_available ?>;

  if (!form) return;

  form.addEventListener('submit', function(e) {
    const amtInput = document.querySelector('[name=amount]');
    const amt = amtInput ? parseFloat(amtInput.value) : 0;
    
    if (!amt || isNaN(amt)) { e.preventDefault(); if(typeof nToast !== 'undefined') nToast('Pilih nominal dulu bosku!', 'warn'); return; }
    if (amt < minWd) { e.preventDefault(); if(typeof nToast !== 'undefined') nToast('Minimal tarik Rp ' + minWd.toLocaleString('id-ID'), 'error'); return; }
    if (amt > maxWd) { e.preventDefault(); if(typeof nToast !== 'undefined') nToast('Maksimal tarik Rp ' + maxWd.toLocaleString('id-ID'), 'error'); return; }

    if (!form.dataset.confirmed) {
      e.preventDefault();
      document.getElementById('cg-modal-amt').innerText = 'Rp ' + amt.toLocaleString('id-ID');
      document.getElementById('cg-modal').style.display = 'flex';
    }
  });

  window.confirmCGWd = function() {
    document.getElementById('cg-modal').style.display = 'none';
    if (btn) { btn.disabled = true; btn.innerText = 'Memproses...'; }
    
    const fd = new FormData(form);
    fd.append('ajax', '1');
    fetch('', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(res => {
      if (res.error) {
        if (typeof nToast !== 'undefined') nToast(res.error, 'error'); else alert(res.error);
        if (btn) { btn.disabled = false; btn.innerText = 'Tarik'; }
      } else {
        if (typeof nToast !== 'undefined') nToast(res.message, 'success'); else alert(res.message);
        setTimeout(() => window.location.href = '/history?tab=withdraw', 1500);
      }
    })
    .catch(() => {
      if (typeof nToast !== 'undefined') nToast('Koneksi terputus.', 'error'); else alert('Koneksi.');
      if (btn) { btn.disabled = false; btn.innerText = 'Tarik'; }
    });
  };
})();
</script>

<?php require dirname(__DIR__) . '/partials/footer.php'; ?>