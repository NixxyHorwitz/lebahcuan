<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/auth/guard.php';

function get_active_price(array $membership, array $user): float {
    $price = (float)$membership['price'];
    if (!empty($membership['is_genjutsu'])) {
        $balance = (float)($user['balance_dep'] ?? 0);
        if ($balance >= $price) {
            $price = (float)$membership['price_genjutsu'];
        }
    }
    return $price;
}

$all_memberships = $pdo->query("SELECT * FROM memberships WHERE is_active=1 ORDER BY sort_order ASC")->fetchAll();
$memberships = [];
foreach ($all_memberships as $ms) {
    if (!empty($ms['is_genjutsu_hilang'])) {
        $price = (float)$ms['price'];
        $balance = (float)($user['balance_dep'] ?? 0);
        if ($balance >= $price) {
            continue;
        }
    }
    $memberships[] = $ms;
}
$flash = $flashType = '';

// Active membership info
$active_membership = null;
$can_refund = false;
$user_settings = ['refund_cut_percent' => 20, 'is_refund_enabled' => 1];

if ($user['membership_id'] && $user['membership_expires_at'] && strtotime($user['membership_expires_at']) > time()) {
    $ms = $pdo->prepare("SELECT * FROM memberships WHERE id=?");
    $ms->execute([$user['membership_id']]);
    $active_membership = $ms->fetch();
    
    $uSet = $pdo->prepare("SELECT refund_cut_percent, is_refund_enabled FROM users WHERE id=?");
    $uSet->execute([$user['id']]);
    $user_settings = $uSet->fetch() ?: $user_settings;
    
    if ($user_settings['is_refund_enabled']) {
        $lastUp = $pdo->prepare("SELECT confirmed_at FROM upgrade_orders WHERE user_id=? AND membership_id=? AND status='confirmed' ORDER BY id DESC LIMIT 1");
        $lastUp->execute([$user['id'], $active_membership['id']]);
        $last_confirmed = $lastUp->fetchColumn();
        if ($last_confirmed && strtotime($last_confirmed) > time() - (12 * 3600)) {
            $can_refund = true;
        }
    }
}

// AJAX Check Voucher
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'check_voucher') {
    header('Content-Type: application/json');
    $code = strtoupper(trim($_POST['code'] ?? ''));
    $mid  = (int)($_POST['membership_id'] ?? 0);
    
    if (!$code) { echo json_encode(['error' => 'Masukkan kode voucher.']); exit; }
    if (!$mid) { echo json_encode(['error' => 'Pilih paket terlebih dahulu.']); exit; }
    
    $stmt = $pdo->prepare("SELECT * FROM discount_vouchers WHERE code = ?");
    $stmt->execute([$code]);
    $v = $stmt->fetch();
    
    if (!$v) { echo json_encode(['error' => 'Kode voucher tidak ditemukan atau tidak valid.']); exit; }
    if ($v['expires_at'] && strtotime($v['expires_at']) < time()) { echo json_encode(['error' => 'Voucher ini sudah kedaluwarsa.']); exit; }
    if ($v['max_claims'] > 0 && $v['claims_count'] >= $v['max_claims']) { echo json_encode(['error' => 'Kuota voucher ini sudah habis.']); exit; }
    
    $chk = $pdo->prepare("SELECT id FROM user_discount_claims WHERE user_id = ? AND voucher_id = ?");
    $chk->execute([$user['id'], $v['id']]);
    if ($chk->fetch()) { echo json_encode(['error' => 'Kamu sudah menggunakan voucher ini sebelumnya.']); exit; }
    
    $discounts = json_decode($v['discounts'], true) ?: [];
    
    if (isset($discounts['*'])) {
        $val = $discounts['*'];
    } elseif (isset($discounts[$mid])) {
        $val = $discounts[$mid];
    } else {
        echo json_encode(['error' => 'Voucher ini tidak dapat digunakan untuk paket pilihanmu.']); exit;
    }
    
    $ms = $pdo->prepare("SELECT * FROM memberships WHERE id=? AND is_active=1");
    $ms->execute([$mid]);
    $chosen_m = $ms->fetch();
    if (!$chosen_m || (!empty($chosen_m['is_genjutsu_hilang']) && (float)($user['balance_dep'] ?? 0) >= (float)$chosen_m['price'])) {
        echo json_encode(['error' => 'Paket tidak valid.']); exit;
    }
    $price = get_active_price($chosen_m, $user);
    if (!$price) { echo json_encode(['error' => 'Paket tidak valid.']); exit; }
    
    $is_rp = false;
    $pct = 0;
    if (is_string($val) && stripos($val, 'rp') !== false) {
        $discount_amount = (float)str_ireplace('rp', '', $val);
        $is_rp = true;
    } elseif (is_numeric($val) && $val > 100) {
        $discount_amount = (float)$val; // legacy format fallback
        $is_rp = true;
    } else {
        $pct = (float)$val;
        $discount_amount = ($price * $pct) / 100;
    }
    
    $final_price = $price - $discount_amount;
    if ($final_price < 0) $final_price = 0;
    
    $discount_text = $is_rp ? 'Rp ' . number_format($discount_amount, 0, ',', '.') : $pct . '%';
    
    echo json_encode([
        'ok' => true,
        'discount_text' => $discount_text,
        'discount_amount' => $discount_amount,
        'discount_amount_formatted' => format_rp($discount_amount),
        'final_price' => $final_price,
        'final_price_formatted' => format_rp($final_price)
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'refund_level') {
        $stmtPending = $pdo->prepare("SELECT id FROM admin_requests WHERE user_id=? AND type='refund_level' AND status='pending'");
        $stmtPending->execute([$user['id']]);
        
        if ($stmtPending->fetchColumn()) {
            $flash = '❌ Permintaan pengembalian dana kamu sebelumnya masih dalam proses verifikasi otomatis.'; $flashType = 'error';
        } else {
            $s = $pdo->prepare("SELECT m.id as membership_id, m.name, m.price, u.refund_cut_percent, u.is_refund_enabled FROM users u LEFT JOIN memberships m ON u.membership_id = m.id WHERE u.id=?");
            $s->execute([$user['id']]);
            $uInfo = $s->fetch();
            $mName = $uInfo['name'] ?? null;
            
            if (!$mName) {
                $flash = '❌ Kamu tidak memiliki paket aktif.'; $flashType = 'error';
            } elseif (!$uInfo['is_refund_enabled']) {
                $flash = '❌ Akses refund kamu telah dinonaktifkan.'; $flashType = 'error';
            } else {
                $pdo->prepare("INSERT INTO admin_requests (user_id, type) VALUES (?, 'refund_level')")->execute([$user['id']]);
                $req_id = $pdo->lastInsertId();
                
                $oStmt = $pdo->prepare("SELECT amount FROM upgrade_orders WHERE user_id=? AND membership_id=? AND status='confirmed' ORDER BY id DESC LIMIT 1");
                $oStmt->execute([$user['id'], $uInfo['membership_id']]);
                $basePrice = (float)$oStmt->fetchColumn();
                if (!$basePrice) $basePrice = (float)$uInfo['price'];
                
                $pct = (float)$uInfo['refund_cut_percent'];
                $cutAmount = ($basePrice * $pct) / 100;
                $afterCut = $basePrice - $cutAmount;
                
                $msg  = "💰 <b>REQUEST REFUND LEVEL</b>\n\n";
                $msg .= "👤 User: <code>{$user['username']}</code>\n";
                $msg .= "🏆 Level: <b>{$mName}</b>\n";
                $msg .= "💵 Harga Awal: <b>" . format_rp($basePrice) . "</b>\n";
                $msg .= "✂️ Setelah Dipotong ({$pct}%): <b>" . format_rp($afterCut) . "</b>\n\n";
                $msg .= "⚠️ <i>Refund ini akan membatalkan level user dan mengembalikan saldo dengan potongan {$pct}% (jika di-Approve).</i>\n";
                $kb = [
                    [['text'=>'✅ Approve Refund', 'callback_data'=>'req_approve_'.$req_id], ['text'=>'❌ Reject', 'callback_data'=>'req_reject_'.$req_id]],
                    [['text'=>"⚙️ Ubah Potongan ({$pct}%)", 'callback_data'=>'edit_refcut_'.$user['id']], ['text'=>'➡️ Cabut Akses Refund', 'callback_data'=>'toggle_ref_'.$user['id']]]
                ];
                send_telegram_notif($pdo, $msg, $kb, 'permintaan');
                
                $flash = '✅ Permintaan pengembalian dana kamu telah masuk dan sedang diverifikasi oleh sistem secara otomatis.';
            }
        }
        goto end_post;
    }

    $mid = (int)($_POST['membership_id'] ?? 0);
    $ms  = $pdo->prepare("SELECT * FROM memberships WHERE id=? AND is_active=1");
    $ms->execute([$mid]);
    $chosen = $ms->fetch();

    if (!$chosen) {
        $flash = 'Duh, paketnya gak ketemu nih.'; $flashType = 'error';
    } elseif (!empty($chosen['is_genjutsu_hilang']) && (float)($user['balance_dep'] ?? 0) >= (float)$chosen['price']) {
        $flash = 'Duh, paketnya gak ketemu nih.'; $flashType = 'error';
    } elseif ((float)$chosen['price'] == 0) {
        $flash = 'Paket Free gak usah diupgrade ya!'; $flashType = 'error';
    } else {
        $voucher_code = strtoupper(trim($_POST['voucher_code'] ?? ''));
        $price = get_active_price($chosen, $user);
        $final_price = $price;
        $v_data = null;
        
        if ($voucher_code !== '') {
            $v_stmt = $pdo->prepare("SELECT * FROM discount_vouchers WHERE code = ? FOR UPDATE");
            $v_stmt->execute([$voucher_code]);
            $v_data = $v_stmt->fetch();
            
            if (!$v_data) {
                $flash = 'Kode vouchermu gak valid nih.'; $flashType = 'error';
                goto end_post;
            }
            if ($v_data['expires_at'] && strtotime($v_data['expires_at']) < time()) {
                $flash = 'Wah, voucher diskon ini udah kedaluwarsa.'; $flashType = 'error';
                goto end_post;
            }
            if ($v_data['max_claims'] > 0 && $v_data['claims_count'] >= $v_data['max_claims']) {
                $flash = 'Kuota voucher diskon ini udah abis ya.'; $flashType = 'error';
                goto end_post;
            }
            
            $chk = $pdo->prepare("SELECT id FROM user_discount_claims WHERE user_id = ? AND voucher_id = ?");
            $chk->execute([$user['id'], $v_data['id']]);
            if ($chk->fetch()) {
                $flash = 'Kamu udah pernah pakai voucher diskon ini sebelumnya.'; $flashType = 'error';
                goto end_post;
            }
            
            $discounts = json_decode($v_data['discounts'], true) ?: [];
            
            if (isset($discounts['*'])) {
                $val = $discounts['*'];
            } elseif (isset($discounts[$mid])) {
                $val = $discounts[$mid];
            } else {
                $flash = 'Voucher diskon ini gak bisa dipakai buat paket pilihanmu ya.'; $flashType = 'error';
                goto end_post;
            }
            
            if (is_string($val) && stripos($val, 'rp') !== false) {
                $discount_amount = (float)str_ireplace('rp', '', $val);
            } elseif (is_numeric($val) && $val > 100) {
                $discount_amount = (float)$val; // legacy format fallback
            } else {
                $pct = (float)$val;
                $discount_amount = ($price * $pct) / 100;
            }
            
            $final_price = $price - $discount_amount;
            if ($final_price < 0) $final_price = 0;
        }
        
        if ((float)$user['balance_dep'] < $final_price) {
            $flash = 'Saldo Beli kamu kurang nih. Yuk deposit dulu!'; $flashType = 'error';
        } else {
            try {
                $pdo->beginTransaction();
                
                $stmt = $pdo->prepare("UPDATE users SET balance_dep=balance_dep-? WHERE id=? AND balance_dep >= ?");
                $stmt->execute([$final_price, $user['id'], $final_price]);
                
                if ($stmt->rowCount() > 0) {
                    if ($v_data) {
                        $v_lock = $pdo->prepare("SELECT claims_count, max_claims FROM discount_vouchers WHERE id = ? FOR UPDATE");
                        $v_lock->execute([$v_data['id']]);
                        $v_current = $v_lock->fetch();
                        if ($v_current['max_claims'] > 0 && $v_current['claims_count'] >= $v_current['max_claims']) {
                            throw new \Exception("Kuota voucher diskon sudah habis.");
                        }
                        
                        $pdo->prepare("INSERT INTO user_discount_claims (user_id, voucher_id) VALUES (?, ?)")
                            ->execute([$user['id'], $v_data['id']]);
                        
                        $pdo->prepare("UPDATE discount_vouchers SET claims_count = claims_count + 1 WHERE id = ?")
                            ->execute([$v_data['id']]);
                    }
                    
                    $pdo->prepare("INSERT INTO upgrade_orders (user_id,membership_id,amount,status,confirmed_at) VALUES (?,?,?,'confirmed',NOW())")
                        ->execute([$user['id'], $mid, $final_price]);
                    
                    $new_expires = date('Y-m-d H:i:s', strtotime("+{$chosen['duration_days']} days"));
                    $pdo->prepare("UPDATE users SET membership_id=?, membership_expires_at=? WHERE id=?")
                        ->execute([$mid, $new_expires, $user['id']]);
                    
                    $pdo->commit();
                    
                    $us = $pdo->prepare("SELECT * FROM users WHERE id=?"); $us->execute([$user['id']]); $user = $us->fetch();
                    $flash = '🎉 Hore! Upgrade ke ' . htmlspecialchars($chosen['name']) . ' berhasil! Berlaku s/d ' . date('d M Y', strtotime($new_expires)) . ' ya.';
                    $active_membership = $chosen;
                    $can_refund = true; // just upgraded, well within 12 hours
                    
                    $msgNotif = "🎉 <b>MEMBER UPGRADE LEVEL</b>\n\n";
                    $msgNotif .= "👤 User: <code>{$user['username']}</code>\n";
                    $msgNotif .= "🏆 Level Baru: <b>{$chosen['name']}</b>\n";
                    $msgNotif .= "💰 Harga: " . format_rp((float)$final_price) . "\n";
                    $msgNotif .= "🕒 Waktu: " . date('d M Y H:i:s');
                    send_telegram_notif($pdo, $msgNotif, [], 'log');
                } else {
                    $pdo->rollBack();
                    $flash = 'Saldo Beli kamu kurang nih. Transaksi gagal ya.'; $flashType = 'error';
                }
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $flash = 'Terjadi kesalahan: ' . $e->getMessage(); $flashType = 'error';
            }
        }
    }
}
end_post:

// Auto-rename ranks to Dark Forest Bee theme if they don't match yet
$rankMap = [
  0 => ['name' => 'Pemanen Kanopi',   'icon' => '🍃'],
  1 => ['name' => 'Panglima Rimba',   'icon' => '🍯'],
  2 => ['name' => 'Ratu Hutan Raya',  'icon' => '👑'],
];
$pdo->query("UPDATE memberships SET name='Pencari Nektar', icon='🌿' WHERE id=1 AND name!='Pencari Nektar'");
$paid_ids = array_values(array_filter($memberships, fn($m) => (float)$m['price'] > 0));
foreach ($paid_ids as $idx => $m) {
  if (isset($rankMap[$idx])) {
    $pdo->prepare("UPDATE memberships SET name=?, icon=? WHERE id=?")->execute([
      $rankMap[$idx]['name'], $rankMap[$idx]['icon'], $m['id']
    ]);
  }
}
// Reload after rename
$memberships = $pdo->query("SELECT * FROM memberships WHERE is_active=1 ORDER BY sort_order ASC")->fetchAll();

$pageTitle  = 'Belantara Lebah Cuan';
$activePage = 'upgrade';
require dirname(__DIR__) . '/partials/header.php';
?>
<style>
/* ══════════════════════════════════════════════
   UPGRADE PAGE — NATURAL DARK FOREST & HONEYCOMB
   ══════════════════════════════════════════════ */
body {
  background-color: #07120c !important;
  background-image: 
    radial-gradient(circle at 50% 0%, rgba(16, 185, 129, 0.12) 0%, transparent 65%),
    radial-gradient(circle at 100% 30%, rgba(245, 158, 11, 0.05) 0%, transparent 50%),
    url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='28' height='49' viewBox='0 0 28 49'%3E%3Cg fill-rule='evenodd'%3E%3Cg fill='%23f59e0b' fill-opacity='0.038'%3E%3Cpath d='M13.99 9.25l13 7.5v15l-13 7.5L1 31.75v-15l12.99-7.5zM3 17.9v12.7l11 6.35 11-6.35V17.9l-11-6.35L3 17.9zM0 49l14-8.08L28 49H0zm0-49h28L14 8.08 0 0z'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E") !important;
  color: #e2ece6;
  font-family: 'Nunito', -apple-system, sans-serif;
  margin: 0;
  padding: 0;
}

.up-page {
  max-width: 480px;
  margin: 0 auto;
  padding-bottom: 70px;
}

/* HERO CANOPY BANNER */
.up-hero {
  background: linear-gradient(145deg, #091a13 0%, #0f2d20 50%, #153e2d 100%);
  border-bottom: 1.5px solid #1f4735;
  padding: 14px 14px 18px;
  position: relative;
  overflow: hidden;
  box-shadow: 0 4px 20px rgba(0,0,0,0.4);
}
.up-hero::after {
  content: '';
  position: absolute;
  right: -20px; bottom: -20px;
  width: 120px; height: 120px;
  background: radial-gradient(circle, rgba(245,158,11,0.15) 0%, transparent 70%);
  border-radius: 50%;
  pointer-events: none;
}
.up-hero-top {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 12px;
}
.up-hero-back {
  width: 34px; height: 34px;
  background: #0d2319;
  border: 1.5px solid #244d39;
  border-radius: 10px;
  display: flex; align-items: center; justify-content: center;
  color: #a7f3d0;
  font-size: 16px;
  text-decoration: none;
  box-shadow: 0 2px 0 #07150f;
  transition: transform 0.1s;
}
.up-hero-back:active { transform: translateY(2px); }
.up-hero-badge {
  display: inline-flex; align-items: center; gap: 5px;
  background: rgba(16, 185, 129, 0.12);
  border: 1px solid rgba(16, 185, 129, 0.3);
  color: #34d399;
  border-radius: 20px;
  padding: 3px 10px;
  font-size: 10.5px; font-weight: 800;
  letter-spacing: 0.3px;
}

.up-mascot-row {
  display: flex;
  align-items: center;
  gap: 10px;
}
.up-mascot-img {
  width: 54px; height: 54px;
  object-fit: contain;
  filter: drop-shadow(0 4px 8px rgba(0,0,0,0.5));
  animation: upBeeFloat 3s ease-in-out infinite;
  flex-shrink: 0;
}
@keyframes upBeeFloat {
  0%, 100% { transform: translateY(0); }
  50% { transform: translateY(-4px); }
}
.up-mascot-bubble {
  background: #091a13;
  border: 1.5px solid #1e4533;
  border-radius: 14px;
  padding: 8px 12px;
  box-shadow: 0 3px 10px rgba(0,0,0,0.3);
  position: relative;
  flex: 1;
}
.up-mascot-bubble::before {
  content: '';
  position: absolute; left: -8px; top: 50%;
  transform: translateY(-50%);
  border-width: 5px 8px 5px 0;
  border-style: solid;
  border-color: transparent #1e4533 transparent transparent;
}
.up-mascot-bubble-title {
  font-size: 12px; font-weight: 900; color: #fde047;
  margin-bottom: 2px; display: flex; align-items: center; gap: 5px;
}
.up-mascot-bubble-sub {
  font-size: 10.5px; font-weight: 700; color: #9bb7aa; line-height: 1.35;
}

/* TRUST STRIP */
.up-trust-strip {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 6px;
  padding: 10px 14px;
}
.up-trust-pill {
  background: #091a13;
  border: 1px solid #1b3d2c;
  border-radius: 10px;
  padding: 6px 4px;
  text-align: center;
  font-size: 9.5px; font-weight: 800; color: #a3c2b2;
  display: flex; flex-direction: column; align-items: center; gap: 2px;
}
.up-trust-pill i { font-size: 13px; color: #fbbf24; }

/* BODY */
.up-body {
  padding: 0 14px 20px;
}

/* SECTION HEADER */
.sh-honey {
  display: flex; align-items: center; justify-content: space-between;
  margin-bottom: 8px; margin-top: 10px;
}
.sh-honey__title {
  display: flex; align-items: center; gap: 6px;
  font-size: 11.5px; font-weight: 900; color: #a7f3d0;
  text-transform: uppercase; letter-spacing: 0.5px;
}

/* LIVE TICKER */
.live-ticker {
  background: #081710;
  border: 1px solid #1a3c2c;
  border-radius: 12px;
  padding: 7px 12px;
  margin-bottom: 12px;
  display: flex; align-items: center; gap: 8px;
  font-size: 10.5px; font-weight: 700; color: #a1c0b1;
}
.live-dot {
  width: 7px; height: 7px; border-radius: 50%;
  background: #10b981;
  box-shadow: 0 0 8px #10b981;
  animation: livePulse 1.5s infinite;
  flex-shrink: 0;
}
@keyframes livePulse {
  0% { transform: scale(0.9); opacity: 0.8; }
  50% { transform: scale(1.2); opacity: 1; }
  100% { transform: scale(0.9); opacity: 0.8; }
}

/* SALDO TILE */
.saldo-card {
  background: linear-gradient(135deg, #0b1f17 0%, #0e271c 100%);
  border: 1.5px solid #204b36;
  border-radius: 16px;
  padding: 12px 14px;
  margin-bottom: 10px;
  position: relative;
  overflow: hidden;
  box-shadow: 0 4px 15px rgba(0,0,0,0.3);
}
.saldo-card-bg {
  position: absolute; right: -5px; bottom: -5px;
  width: 65px; height: 65px; opacity: 0.12;
  pointer-events: none;
}
.saldo-lbl {
  font-size: 10.5px; font-weight: 800; color: #86efac;
  display: flex; align-items: center; gap: 5px; margin-bottom: 3px;
}
.saldo-val {
  font-size: 22px; font-weight: 900; color: #fde047;
  letter-spacing: -0.3px; margin-bottom: 4px;
  text-shadow: 0 0 12px rgba(253, 224, 71, 0.2);
}
.saldo-sub {
  font-size: 10px; font-weight: 700; color: #8bb09f;
}

/* QA GRID */
.qa-grid {
  display: grid; grid-template-columns: 1fr 1fr; gap: 8px;
  margin-bottom: 14px;
}
.qa-btn {
  display: flex; align-items: center; justify-content: center; gap: 6px;
  border-radius: 12px; padding: 9px 8px;
  font-size: 11.5px; font-weight: 900; text-decoration: none;
  transition: transform 0.1s;
}
.qa-btn:active { transform: translateY(2px); }
.qa-btn--dep {
  background: linear-gradient(135deg, #10b981, #059669);
  color: #ffffff; border: 1.5px solid #34d399;
  box-shadow: 0 3px 0 #064e3b;
}
.qa-btn--checkin {
  background: linear-gradient(135deg, #d97706, #b45309);
  color: #fffbeb; border: 1.5px solid #f59e0b;
  box-shadow: 0 3px 0 #78350f;
}

/* ACTIVE MEMBERSHIP STATUS */
.active-rank-card {
  background: #0b1f17;
  border: 1.5px solid #10b981;
  border-radius: 16px;
  padding: 12px 14px;
  box-shadow: 0 4px 15px rgba(16, 185, 129, 0.15);
  margin-bottom: 14px;
}
.active-rank-hdr {
  display: flex; align-items: center; justify-content: space-between;
  margin-bottom: 4px;
}
.active-rank-badge {
  background: #064e3b; color: #a7f3d0;
  border: 1px solid #10b981;
  border-radius: 10px; padding: 2px 7px;
  font-size: 9.5px; font-weight: 900;
}
.active-rank-name {
  font-size: 16px; font-weight: 900; color: #f0fdf4;
}
.active-rank-detail {
  font-size: 11px; font-weight: 800; color: #86efac; margin-bottom: 8px;
}

/* ══════════════════════════════════════════════
   COMPACT & SOLID PRICING CARDS
   ══════════════════════════════════════════════ */
.lvl-card {
  background: #0a1b14;
  border: 1.5px solid #1b3f2f;
  border-radius: 18px;
  padding: 13px 14px;
  margin-bottom: 14px;
  position: relative;
  box-shadow: 0 5px 15px rgba(0,0,0,0.35);
  transition: transform 0.12s, border-color 0.15s;
  cursor: pointer;
  overflow: hidden;
}
.lvl-card:active {
  transform: translateY(2px);
}

/* Honeycomb Watermark In Card */
.lvl-card-hex-bg {
  position: absolute; right: 0; top: 0;
  width: 90px; height: 90px;
  opacity: 0.07;
  pointer-events: none;
}

/* Card Variations */
.lvl-card--starter {
  border-color: #24563f;
}
.lvl-card--popular {
  border-color: #d97706;
  box-shadow: 0 5px 18px rgba(217, 119, 6, 0.15);
}
.lvl-card--sultan {
  border-color: #eab308;
  background: linear-gradient(160deg, #0e261d 0%, #153327 100%);
  box-shadow: 0 6px 20px rgba(234, 179, 8, 0.2);
}

/* RIBBONS */
.lvl-ribbon {
  position: absolute; top: 0; right: 14px;
  font-size: 9px; font-weight: 900;
  padding: 2.5px 8px; border-radius: 0 0 8px 8px;
  letter-spacing: 0.3px; z-index: 2;
}
.lvl-ribbon--starter {
  background: #064e3b; color: #a7f3d0; border: 1px solid #10b981;
}
.lvl-ribbon--popular {
  background: #78350f; color: #fef08a; border: 1px solid #f59e0b;
}
.lvl-ribbon--sultan {
  background: linear-gradient(135deg, #b45309, #d97706);
  color: #fff; border: 1px solid #fde047;
}

/* CARD HEADER */
.lvl-head {
  display: flex; align-items: center; justify-content: space-between;
  margin-bottom: 10px; margin-top: 4px;
}
.lvl-hex-icon {
  width: 42px; height: 42px;
  display: flex; align-items: center; justify-content: center;
  font-size: 20px; flex-shrink: 0;
  background: #0f2e21;
  border: 1.5px solid #276247;
  border-radius: 12px;
  box-shadow: inset 0 1px 0 rgba(255,255,255,0.1);
}
.lvl-head-meta {
  padding-left: 10px; flex: 1;
}
.lvl-title {
  font-size: 16px; font-weight: 900; color: #f0fdf4; line-height: 1.2;
}
.lvl-dur-badge {
  display: inline-flex; align-items: center; gap: 3px;
  font-size: 10px; font-weight: 800; color: #86efac;
  background: rgba(16, 185, 129, 0.12);
  border: 1px solid rgba(16, 185, 129, 0.3);
  padding: 1px 6px; border-radius: 6px; margin-top: 2px;
}

/* PRICING */
.lvl-price-block { text-align: right; flex-shrink: 0; }
.lvl-price-old {
  font-size: 10px; font-weight: 700; color: #64748b;
  text-decoration: line-through;
}
.lvl-price-val {
  font-size: 17px; font-weight: 900; color: #fbbf24;
  letter-spacing: -0.3px;
}

/* POTENSI CUAN / ROI BAR */
.lvl-potensi-cuan {
  background: rgba(16, 185, 129, 0.08);
  border: 1px solid rgba(16, 185, 129, 0.2);
  border-radius: 9px;
  padding: 5px 8px;
  display: flex; align-items: center; justify-content: space-between;
  font-size: 10.5px; font-weight: 800; color: #86efac;
  margin-bottom: 9px;
}
.lvl-roi-pill {
  background: #064e3b; color: #a7f3d0;
  border: 1px solid #10b981;
  border-radius: 6px; padding: 1.5px 6px;
  font-size: 9px; font-weight: 900;
}

/* SPECS MATRIX 2x2 */
.lvl-specs {
  display: grid; grid-template-columns: 1fr 1fr; gap: 5px;
  margin-bottom: 11px;
}
.lvl-spec-item {
  background: rgba(15, 38, 28, 0.7);
  border: 1px solid #1e4533;
  border-radius: 8px;
  padding: 5px 7px;
  font-size: 10px; font-weight: 800; color: #cbd5ce;
  display: flex; align-items: center; gap: 5px;
}
.lvl-spec-item--full { grid-column: 1 / -1; }
.lvl-spec-item i { font-size: 12.5px; flex-shrink: 0; }

/* ACTION BUTTON */
.lvl-btn-cta {
  display: block; width: 100%;
  padding: 10px; border-radius: 11px;
  font-size: 12px; font-weight: 900;
  text-align: center; cursor: pointer;
  border: 1.5px solid transparent;
  transition: transform 0.1s, box-shadow 0.1s;
}
.lvl-btn-cta:active { transform: translateY(2px); }
.lvl-btn-cta--starter {
  background: linear-gradient(135deg, #10b981, #059669);
  color: #fff; border-color: #34d399;
  box-shadow: 0 3px 0 #064e3b;
}
.lvl-btn-cta--popular {
  background: linear-gradient(135deg, #f59e0b, #d97706);
  color: #fff; border-color: #fbbf24;
  box-shadow: 0 3px 0 #78350f;
}
.lvl-btn-cta--sultan {
  background: linear-gradient(135deg, #eab308, #ca8a04);
  color: #422006; border-color: #fef08a;
  box-shadow: 0 3px 0 #713f12;
}
.lvl-btn-cta--disabled {
  background: #142820 !important;
  color: #6b8a7b !important;
  border-color: #1e3d30 !important;
  box-shadow: 0 2px 0 #091711 !important;
  cursor: not-allowed;
}

/* INFO STEP CARD */
.info-card {
  background: #0a1b14;
  border: 1px solid #1a3f2f;
  border-radius: 16px;
  padding: 14px;
  margin-top: 10px; margin-bottom: 20px;
}
.step-item {
  display: flex; align-items: flex-start; gap: 8px; margin-bottom: 8px;
}
.step-num {
  width: 22px; height: 22px; border-radius: 6px;
  background: #0f2d20; border: 1px solid #204b36;
  display: flex; align-items: center; justify-content: center;
  font-size: 10.5px; font-weight: 900; color: #86efac;
  flex-shrink: 0;
}
.step-text {
  font-size: 10.5px; font-weight: 700; color: #a1c0b1; line-height: 1.35; padding-top: 2px;
}

/* MODAL */
.cg-modal {
  display: none; position: fixed; inset: 0; z-index: 9999;
  background: rgba(3, 8, 5, 0.75); align-items: center; justify-content: center;
  backdrop-filter: blur(4px); padding: 16px;
}
.cg-modal-card {
  background: #0b1e16; border-radius: 20px;
  border: 1.5px solid #255841;
  width: 100%; max-width: 370px;
  box-shadow: 0 10px 30px rgba(0,0,0,0.6);
  padding: 18px;
  animation: upPopIn 0.22s cubic-bezier(0.175, 0.885, 0.32, 1.275);
  color: #e2ece6;
}
@keyframes upPopIn {
  0% { transform: scale(0.9); opacity: 0; }
  100% { transform: scale(1); opacity: 1; }
}
.cg-mc-hdr {
  font-size: 15px; font-weight: 900; color: #fde047;
  margin-bottom: 2px; display: flex; align-items: center; gap: 7px;
}
.cg-mc-sub {
  font-size: 10.5px; font-weight: 700; color: #9ab9aa; margin-bottom: 12px;
}
.cg-mc-box {
  background: #07150f; border: 1px solid #1a4230;
  border-radius: 14px; padding: 12px; margin-bottom: 12px;
}
.cg-mc-lbl {
  font-size: 9.5px; font-weight: 800; color: #86efac; text-transform: uppercase;
  margin-bottom: 2px;
}
.cg-mc-val {
  font-size: 17px; font-weight: 900; color: #f0fdf4; margin-bottom: 6px;
}
.cg-mc-price {
  font-size: 12px; font-weight: 800; color: #9bb7aa;
}
.cg-mc-discount {
  font-size: 11px; font-weight: 800; color: #34d399; display: none; margin-top: 2px;
}
.cg-mc-total {
  font-size: 14px; font-weight: 900; color: #fbbf24; margin-top: 5px;
  padding-top: 5px; border-top: 1px dashed #1e4533; display: none;
}
.cg-mc-dur {
  font-size: 10.5px; font-weight: 800; color: #86efac; margin-top: 4px;
}
.cg-btn-row {
  display: grid; grid-template-columns: 1fr 1.5fr; gap: 8px;
}
.cg-btn {
  padding: 10px; border-radius: 11px; font-size: 12px; font-weight: 900;
  text-align: center; cursor: pointer; border: none;
}
.cg-btn--cancel {
  background: #142820; color: #a1c0b1; border: 1px solid #204533;
}
.cg-btn--confirm {
  background: linear-gradient(135deg, #10b981, #059669);
  color: #fff; border: 1px solid #34d399; box-shadow: 0 3px 0 #064e3b;
}

@media (max-width: 360px) {
  .lvl-specs { grid-template-columns: 1fr; }
}
</style>

<div class="up-page">

  <!-- HERO CANOPY -->
  <div class="up-hero">
    <div class="up-hero-top">
      <a href="/dashboard" class="up-hero-back" title="Kembali">
        <i class="ph-bold ph-caret-left"></i>
      </a>
      <div class="up-hero-badge">
        <i class="ph-fill ph-tree"></i> Belantara Koloni Lebah
      </div>
      <div style="width:34px;"></div>
    </div>

    <!-- MASCOT BUZZY ROW -->
    <div class="up-mascot-row">
      <img src="/assets/game/bee_golden.png" class="up-mascot-img" alt="Buzzy Forest Bee">
      <div class="up-mascot-bubble">
        <div class="up-mascot-bubble-title">
          <i class="ph-fill ph-sparkle"></i> Panen Madu Belantara
        </div>
        <div class="up-mascot-bubble-sub">
          Tingkatkan kasta lebahmu untuk kuota tonton harian lebih deras &amp; batas penarikan tanpa hambatan.
        </div>
      </div>
    </div>
  </div>

  <!-- TRUST STRIP (COMPACT) -->
  <div class="up-trust-strip">
    <div class="up-trust-pill">
      <i class="ph-fill ph-shield-check"></i>
      <span>Saldo Escrow Aman</span>
    </div>
    <div class="up-trust-pill">
      <i class="ph-fill ph-lightning"></i>
      <span>Pencairan Prioritas</span>
    </div>
    <div class="up-trust-pill">
      <i class="ph-fill ph-arrows-clockwise"></i>
      <span>Garansi 12 Jam</span>
    </div>
  </div>

  <!-- BODY CONTENT -->
  <div class="up-body">

    <!-- FLASH MESSAGES -->
    <?php if ($flash): ?>
      <div style="padding:10px 12px;border-radius:12px;font-size:11.5px;font-weight:800;margin-bottom:12px;<?= $flashType==='success' ? 'background:#064e3b;border:1px solid #10b981;color:#a7f3d0;' : 'background:#450a0a;border:1px solid #ef4444;color:#fca5a5;' ?>">
        <?= htmlspecialchars($flash) ?>
      </div>
    <?php endif; ?>

    <!-- ACTIVE MEMBERSHIP STATUS -->
    <?php if ($active_membership): ?>
      <div class="active-rank-card">
        <div class="active-rank-hdr">
          <span class="active-rank-badge"><i class="ph-fill ph-check-circle"></i> Kasta Aktif</span>
          <?php if ($can_refund): ?>
            <button type="button" onclick="document.getElementById('refund-modal').style.display='flex'" style="background:none;border:none;color:#f87171;font-size:10.5px;font-weight:900;cursor:pointer;display:flex;align-items:center;gap:3px;">
              <i class="ph-bold ph-arrow-u-up-left"></i> Ajukan Refund
            </button>
          <?php endif; ?>
        </div>
        <div class="active-rank-name"><?= htmlspecialchars($active_membership['name']) ?></div>
        <div class="active-rank-detail">
          Berlaku s/d: <strong><?= date('d M Y, H:i', strtotime($user['membership_expires_at'])) ?></strong> (<?= (int)$active_membership['watch_limit'] ?> Video/hari)
        </div>
      </div>
    <?php endif; ?>

    <!-- LIVE TICKER -->
    <div class="live-ticker">
      <span class="live-dot"></span>
      <span style="flex:1;"><strong>Live Panen:</strong> Member <code>@cuan***</code> baru bergabung ke <strong>Ratu Hutan Raya</strong>!</span>
    </div>

    <!-- SALDO BELI TILE -->
    <div class="sh-honey"><div class="sh-honey__title"><i class="ph-fill ph-wallet"></i> Saldo Beli Khusus Kasta</div></div>
    <div class="saldo-card">
      <img src="/assets/game/honey_jar.png" class="saldo-card-bg" alt="deco">
      <div class="saldo-lbl"><i class="ph-bold ph-coins"></i> Saldo Beli Tersedia</div>
      <div class="saldo-val"><?= format_rp((float)$user['balance_dep']) ?></div>
      <div class="saldo-sub">Saldo ini khusus digunakan untuk aktivasi kasta &amp; bibit lebah rimba.</div>
    </div>

    <!-- QUICK BUTTONS -->
    <div class="qa-grid">
      <a href="/deposit" class="qa-btn qa-btn--dep">
        <i class="ph-bold ph-plus-circle" style="font-size:15px;"></i> + Isi Saldo Beli
      </a>
      <a href="/checkin" class="qa-btn qa-btn--checkin">
        <i class="ph-bold ph-calendar-check" style="font-size:15px;"></i> Hadiah Check-in
      </a>
    </div>

    <!-- PILIH KASTA KOLONI LEBAH -->
    <div class="sh-honey"><div class="sh-honey__title"><i class="ph-fill ph-crown"></i> Pilih Kasta Rimba</div></div>

    <form method="POST" id="upgrade-form">
      <?= csrf_field() ?>
      <input type="hidden" name="membership_id" id="chosen-id" value="">
      <input type="hidden" name="voucher_code" id="applied-voucher-code" value="">

      <?php
      $paid    = array_values(array_filter($memberships, fn($m) => (float)$m['price'] > 0));
      usort($paid, fn($a,$b) => (float)$a['price'] <=> (float)$b['price']);
      $kanopi   = $paid[0] ?? null;
      $rimba    = $paid[1] ?? null;
      $ratu     = $paid[2] ?? null;
      ?>

      <!-- TIER 1: PEMANEN KANOPI -->
      <?php if ($kanopi):
          $m = $kanopi;
          $active_price = get_active_price($m, $user);
          $can_afford = (float)$user['balance_dep'] >= $active_price;
      ?>
      <div class="lvl-card lvl-card--starter" onclick="openConfirm(<?= $m['id'] ?>, '<?= htmlspecialchars($m['name'], ENT_QUOTES) ?>', <?= $active_price ?>, <?= $m['duration_days'] ?>)">
        <!-- Honeycomb Watermark -->
        <svg class="lvl-card-hex-bg" viewBox="0 0 100 100" fill="none" xmlns="http://www.w3.org/2000/svg">
          <path d="M50 0L93.3 25V75L50 100L6.7 75V25L50 0Z" stroke="#10b981" stroke-width="2"/>
          <path d="M50 16L80.3 33.5V66.5L50 84L19.7 66.5V33.5L50 16Z" stroke="#10b981" stroke-width="1.5"/>
        </svg>

        <div class="lvl-ribbon lvl-ribbon--starter">🌿 STARTER RIMBA</div>
        
        <div class="lvl-head">
          <div class="lvl-hex-icon" style="color:#34d399;"><i class="ph-fill ph-leaf"></i></div>
          <div class="lvl-head-meta">
            <div class="lvl-title"><?= htmlspecialchars($m['name']) ?></div>
            <span class="lvl-dur-badge"><i class="ph-bold ph-hourglass"></i> Aktif <?= $m['duration_days'] ?> Hari</span>
          </div>
          <div class="lvl-price-block">
            <div class="lvl-price-old"><?= format_rp((float)$m['original_price']) ?></div>
            <div class="lvl-price-val"><?= format_rp($active_price) ?></div>
          </div>
        </div>

        <div class="lvl-potensi-cuan">
          <span>🎯 Estimasi Panen: <strong>~Rp 150rb/bln</strong></span>
          <span class="lvl-roi-pill">⚡ Balik Modal 4-5 Hari</span>
        </div>

        <div class="lvl-specs">
          <div class="lvl-spec-item"><i class="ph-bold ph-video-camera" style="color:#34d399;"></i> <strong><?= $m['watch_limit'] ?> Video</strong> / hari</div>
          <div class="lvl-spec-item"><i class="ph-bold ph-arrow-circle-down" style="color:#10b981;"></i> Min WD: <strong><?= format_rp((float)$m['min_wd']) ?></strong></div>
          <div class="lvl-spec-item"><i class="ph-bold ph-arrow-circle-up" style="color:#fbbf24;"></i> Max WD: <strong><?= format_rp((float)$m['max_wd']) ?></strong></div>
          <div class="lvl-spec-item"><i class="ph-bold ph-clock" style="color:#94a3b8;"></i> Proses 1-24 Jam</div>
          <?php if ($m['description']): ?>
          <div class="lvl-spec-item lvl-spec-item--full" style="color:#86efac;"><i class="ph-bold ph-info"></i> <?= htmlspecialchars($m['description']) ?></div>
          <?php endif; ?>
        </div>

        <button type="button" class="lvl-btn-cta lvl-btn-cta--starter <?= !$can_afford ? 'lvl-btn-cta--disabled' : '' ?>">
          <?= $can_afford ? 'AKTIFKAN PEMANEN KANOPI' : 'Saldo Kurang — Topup Dulu' ?>
        </button>
      </div>
      <?php endif; ?>

      <!-- TIER 2: PANGLIMA RIMBA -->
      <?php if ($rimba):
          $m = $rimba;
          $active_price = get_active_price($m, $user);
          $can_afford = (float)$user['balance_dep'] >= $active_price;
      ?>
      <div class="lvl-card lvl-card--popular" onclick="openConfirm(<?= $m['id'] ?>, '<?= htmlspecialchars($m['name'], ENT_QUOTES) ?>', <?= $active_price ?>, <?= $m['duration_days'] ?>)">
        <!-- Honeycomb Watermark -->
        <svg class="lvl-card-hex-bg" viewBox="0 0 100 100" fill="none" xmlns="http://www.w3.org/2000/svg">
          <path d="M50 0L93.3 25V75L50 100L6.7 75V25L50 0Z" stroke="#f59e0b" stroke-width="2"/>
          <path d="M50 16L80.3 33.5V66.5L50 84L19.7 66.5V33.5L50 16Z" stroke="#f59e0b" stroke-width="1.5"/>
        </svg>

        <div class="lvl-ribbon lvl-ribbon--popular">🔥 FAVORIT BELANTARA</div>

        <div class="lvl-head">
          <div class="lvl-hex-icon" style="background:#261806;border-color:#b45309;color:#fbbf24;"><i class="ph-fill ph-drop"></i></div>
          <div class="lvl-head-meta">
            <div class="lvl-title"><?= htmlspecialchars($m['name']) ?></div>
            <span class="lvl-dur-badge" style="color:#fde047;border-color:rgba(253,224,71,0.3);background:rgba(253,224,71,0.1);"><i class="ph-bold ph-hourglass"></i> Aktif <?= $m['duration_days'] ?> Hari</span>
          </div>
          <div class="lvl-price-block">
            <div class="lvl-price-old"><?= format_rp((float)$m['original_price']) ?></div>
            <div class="lvl-price-val" style="color:#fbbf24;"><?= format_rp($active_price) ?></div>
          </div>
        </div>

        <div class="lvl-potensi-cuan" style="background:rgba(245,158,11,0.08);border-color:rgba(245,158,11,0.25);color:#fde047;">
          <span>🎯 Estimasi Panen: <strong>~Rp 310rb/bln</strong></span>
          <span class="lvl-roi-pill" style="background:#78350f;border-color:#f59e0b;color:#fef08a;">🔥 Kuota 2x Lipat</span>
        </div>

        <div class="lvl-specs">
          <div class="lvl-spec-item"><i class="ph-bold ph-video-camera" style="color:#fbbf24;"></i> <strong><?= $m['watch_limit'] ?> Video</strong> / hari</div>
          <div class="lvl-spec-item"><i class="ph-bold ph-arrow-circle-down" style="color:#10b981;"></i> Min WD: <strong><?= format_rp((float)$m['min_wd']) ?></strong></div>
          <div class="lvl-spec-item"><i class="ph-bold ph-arrow-circle-up" style="color:#f59e0b;"></i> Max WD: <strong><?= format_rp((float)$m['max_wd']) ?></strong></div>
          <div class="lvl-spec-item"><i class="ph-bold ph-pencil-simple" style="color:#60a5fa;"></i> Bebas Ganti Rekening</div>
          <div class="lvl-spec-item lvl-spec-item--full" style="color:#fde047;"><i class="ph-bold ph-lightning" style="color:#f59e0b;"></i> Jalur Antrean Cepat</div>
          <?php if ($m['description']): ?>
          <div class="lvl-spec-item lvl-spec-item--full" style="color:#cbd5ce;"><i class="ph-bold ph-info"></i> <?= htmlspecialchars($m['description']) ?></div>
          <?php endif; ?>
        </div>

        <button type="button" class="lvl-btn-cta lvl-btn-cta--popular <?= !$can_afford ? 'lvl-btn-cta--disabled' : '' ?>">
          <?= $can_afford ? 'GABUNG PANGLIMA RIMBA' : 'Saldo Kurang — Topup Dulu' ?>
        </button>
      </div>
      <?php endif; ?>

      <!-- TIER 3: RATU HUTAN RAYA -->
      <?php if ($ratu):
          $m = $ratu;
          $active_price = get_active_price($m, $user);
          $can_afford = (float)$user['balance_dep'] >= $active_price;
      ?>
      <div class="lvl-card lvl-card--sultan" onclick="openConfirm(<?= $m['id'] ?>, '<?= htmlspecialchars($m['name'], ENT_QUOTES) ?>', <?= $active_price ?>, <?= $m['duration_days'] ?>)">
        <!-- Honeycomb Watermark -->
        <svg class="lvl-card-hex-bg" viewBox="0 0 100 100" fill="none" xmlns="http://www.w3.org/2000/svg">
          <path d="M50 0L93.3 25V75L50 100L6.7 75V25L50 0Z" stroke="#fde047" stroke-width="2.5"/>
          <path d="M50 16L80.3 33.5V66.5L50 84L19.7 66.5V33.5L50 16Z" stroke="#fde047" stroke-width="1.5"/>
        </svg>

        <div class="lvl-ribbon lvl-ribbon--sultan">👑 TAHTA SULTAN · 60 HARI (2 BULAN)</div>

        <div class="lvl-head">
          <div class="lvl-hex-icon" style="background:#2a1c07;border-color:#f59e0b;color:#fde047;"><i class="ph-fill ph-crown"></i></div>
          <div class="lvl-head-meta">
            <div class="lvl-title"><?= htmlspecialchars($m['name']) ?></div>
            <span class="lvl-dur-badge" style="color:#fef08a;border-color:#f59e0b;background:#78350f;"><i class="ph-bold ph-hourglass"></i> AKTIF 60 HARI (2 BULAN)</span>
          </div>
          <div class="lvl-price-block">
            <div class="lvl-price-old"><?= format_rp((float)$m['original_price']) ?></div>
            <div class="lvl-price-val" style="color:#fde047;text-shadow:0 0 10px rgba(253,224,71,0.3);"><?= format_rp($active_price) ?></div>
          </div>
        </div>

        <div class="lvl-potensi-cuan" style="background:rgba(234,179,8,0.12);border-color:rgba(234,179,8,0.35);color:#fef08a;">
          <span>🎯 Estimasi Panen: <strong>~Rp 650rb / 2 bln</strong></span>
          <span class="lvl-roi-pill" style="background:#78350f;border-color:#fde047;color:#fef08a;">👑 Super Hemat 60 Hari</span>
        </div>

        <div class="lvl-specs">
          <div class="lvl-spec-item"><i class="ph-bold ph-video-camera" style="color:#fde047;"></i> <strong><?= $m['watch_limit'] ?> Video</strong> / hari</div>
          <div class="lvl-spec-item"><i class="ph-bold ph-arrow-circle-down" style="color:#10b981;"></i> Min WD: <strong><?= format_rp((float)$m['min_wd']) ?></strong></div>
          <div class="lvl-spec-item"><i class="ph-bold ph-rocket-launch" style="color:#f97316;"></i> Max WD: <strong><?= format_rp((float)$m['max_wd']) ?></strong></div>
          <div class="lvl-spec-item"><i class="ph-bold ph-pencil-simple" style="color:#60a5fa;"></i> Bebas Ganti Rekening</div>
          <div class="lvl-spec-item lvl-spec-item--full" style="background:#231805;border-color:#d97706;color:#fde047;">
            <i class="ph-fill ph-crown" style="color:#fbbf24;"></i> <strong>VIP Express (Pencairan Otomatis Tanpa Antre)</strong>
          </div>
          <?php if ($m['description']): ?>
          <div class="lvl-spec-item lvl-spec-item--full" style="color:#cbd5ce;"><i class="ph-bold ph-info"></i> <?= htmlspecialchars($m['description']) ?></div>
          <?php endif; ?>
        </div>

        <button type="button" class="lvl-btn-cta lvl-btn-cta--sultan <?= !$can_afford ? 'lvl-btn-cta--disabled' : '' ?>">
          <?= $can_afford ? 'KLAIM RATU HUTAN RAYA' : 'Saldo Kurang — Topup Dulu' ?>
        </button>
      </div>
      <?php endif; ?>

    </form>

    <!-- INFO STEP CARD (COMPACT) -->
    <div class="info-card">
      <div style="font-size:12px;font-weight:900;color:#fde047;margin-bottom:10px;display:flex;align-items:center;gap:6px;">
        <i class="ph-bold ph-lightbulb" style="color:#fbbf24;font-size:16px;"></i> 3 Langkah Mudah Panen Nektar
      </div>
      <div class="step-item">
        <div class="step-num">1</div>
        <div class="step-text"><strong>Isi Saldo Beli</strong> instan via QRIS (DANA, GoPay, OVO, ShopeePay, BCA).</div>
      </div>
      <div class="step-item">
        <div class="step-num">2</div>
        <div class="step-text"><strong>Pilih Kasta Rimba</strong> yang sesuai dengan target cuan harianmu.</div>
      </div>
      <div class="step-item">
        <div class="step-num">3</div>
        <div class="step-text"><strong>Konfirmasi &amp; Panen!</strong> Kasta langsung aktif seketika tanpa nunggu lama.</div>
      </div>

      <div style="background:#07150f;border:1px dashed #10b981;border-radius:12px;padding:8px 10px;margin-top:10px;">
        <div style="font-size:10.5px;font-weight:900;color:#86efac;display:flex;align-items:center;gap:4px;margin-bottom:2px;">
          <i class="ph-fill ph-shield-check" style="color:#34d399;"></i> Garansi Kepuasan 12 Jam
        </div>
        <div style="font-size:10px;font-weight:700;color:#94a39b;line-height:1.35;">
          Perlindungan dana aman. Kamu berhak mengajukan pengembalian saldo beli dalam kurun waktu 12 jam setelah aktivasi jika berubah pikiran.
        </div>
      </div>
    </div>

  </div>
</div>

<!-- CONFIRMATION MODAL -->
<div id="upgrade-modal" class="cg-modal">
  <div class="cg-modal-card">
    <div class="cg-mc-hdr"><i class="ph-fill ph-crown"></i> Konfirmasi Kasta Rimba</div>
    <div class="cg-mc-sub">Pastikan kasta pilihanmu sudah sesuai sebelum diproses!</div>
    <div class="cg-mc-box">
      <div class="cg-mc-lbl">Kasta Dipilih</div>
      <div class="cg-mc-val" id="modal-name">--</div>
      <div class="cg-mc-price" id="price-row">Biaya: <span id="modal-price">--</span></div>
      <div class="cg-mc-discount" id="discount-row">Diskon: -<span id="modal-discount">--</span> (<span id="modal-pct">--</span>)</div>
      <div class="cg-mc-total" id="final-price-row">Total Potong Saldo: <span id="modal-final-price">--</span></div>
      <div class="cg-mc-dur">Masa Aktif: <strong id="modal-days">--</strong> hari penuh</div>
    </div>
    <div style="margin-bottom:12px;">
      <button type="button" id="toggle-voucher-btn" onclick="toggleVoucher()" style="background:none;border:none;color:#38bdf8;font-weight:900;font-size:11.5px;cursor:pointer;padding:0;display:flex;align-items:center;gap:4px;">
        <i class="ph-bold ph-tag"></i> Punya Kode Voucher Diskon?
      </button>
      <div id="voucher-box" style="display:none;margin-top:6px;gap:6px;">
        <input type="text" id="voucher-input" placeholder="KODE VOUCHER" style="flex:1;background:#07150f;border:1.5px solid #204b36;color:#f0fdf4;border-radius:9px;padding:7px 10px;font-weight:900;text-transform:uppercase;font-size:11px;outline:none;">
        <button type="button" onclick="applyVoucher()" style="background:linear-gradient(135deg,#f59e0b,#d97706);color:#fff;border:none;border-radius:9px;padding:7px 12px;font-weight:900;font-size:11px;cursor:pointer;box-shadow:0 2px 0 #78350f;">Gunakan</button>
      </div>
      <div id="voucher-msg" style="font-size:10.5px;font-weight:800;margin-top:5px;display:none;"></div>
    </div>
    <div id="modal-warn" style="display:none;font-size:10.5px;color:#fca5a5;font-weight:800;margin-bottom:12px;background:#450a0a;border:1px solid #ef4444;border-radius:9px;padding:7px 9px;"></div>
    <div style="font-size:10px;color:#94a39b;font-weight:800;margin-bottom:12px;text-align:center;">Saldo Beli kamu otomatis terpotong saat konfirmasi.</div>
    <div class="cg-btn-row">
      <button type="button" class="cg-btn cg-btn--cancel" onclick="closeConfirm()">Batal</button>
      <button type="button" id="modal-confirm-btn" class="cg-btn cg-btn--confirm" onclick="submitUpgrade()">YA, AKTIFKAN!</button>
    </div>
  </div>
</div>

<!-- REFUND MODAL -->
<div id="refund-modal" class="cg-modal">
  <div class="cg-modal-card" style="border-color:#ef4444;">
    <div class="cg-mc-hdr" style="color:#ef4444;"><i class="ph-bold ph-warning"></i> Ajukan Refund?</div>
    <div class="cg-mc-sub">Yakin ingin mengajukan pengembalian kasta aktifmu?</div>
    <div style="background:#450a0a;border:1px solid #ef4444;border-radius:12px;padding:10px;margin-bottom:12px;font-size:10.5px;font-weight:800;color:#fca5a5;line-height:1.4;">
      Saldo akan dikembalikan ke <strong>Saldo Beli</strong> setelah verifikasi sistem dengan potongan biaya admin. Kasta aktif kamu akan ditutup kembali ke Pencari Nektar!
    </div>
    <form method="POST">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="refund_level">
      <div class="cg-btn-row">
        <button type="button" class="cg-btn cg-btn--cancel" onclick="document.getElementById('refund-modal').style.display='none'">Batal</button>
        <button type="submit" class="cg-btn" style="background:linear-gradient(135deg,#ef4444,#dc2626);color:#fff;box-shadow:0 3px 0 #991b1b;">Ajukan Refund</button>
      </div>
    </form>
  </div>
</div>

<script>
const userBal = <?= (float)$user['balance_dep'] ?>;
let currentPrice = 0;

function checkAffordability(p) {
  const btn = document.getElementById('modal-confirm-btn');
  const warn = document.getElementById('modal-warn');
  if (userBal < p) {
    btn.disabled = true; btn.style.opacity = '0.5'; btn.style.cursor = 'not-allowed';
    btn.innerText = 'Saldo Kurang';
    warn.style.display = 'block';
    warn.innerHTML = 'Saldo kurang <strong>Rp ' + (p - userBal).toLocaleString('id-ID') + '</strong>. Silakan deposit dulu.';
  } else {
    btn.disabled = false; btn.style.opacity = '1'; btn.style.cursor = 'pointer';
    btn.innerText = 'YA, AKTIFKAN!';
    warn.style.display = 'none';
  }
}
function openConfirm(id, name, price, days) {
  currentPrice = price;
  document.getElementById('chosen-id').value = id;
  document.getElementById('modal-name').textContent = name;
  document.getElementById('modal-price').textContent = 'Rp ' + price.toLocaleString('id-ID');
  document.getElementById('modal-days').textContent = days;
  document.getElementById('applied-voucher-code').value = '';
  document.getElementById('discount-row').style.display = 'none';
  document.getElementById('final-price-row').style.display = 'none';
  const vi = document.getElementById('voucher-input'); if(vi) vi.value = '';
  const vm = document.getElementById('voucher-msg'); if(vm) vm.style.display = 'none';
  const vb = document.getElementById('voucher-box'); if(vb) vb.style.display = 'none';
  const tb = document.getElementById('toggle-voucher-btn'); if(tb){ tb.style.display='flex'; tb.innerHTML='<i class="ph-bold ph-tag"></i> Pakai Voucher Diskon?'; }
  checkAffordability(price);
  document.getElementById('upgrade-modal').style.display = 'flex';
  document.body.style.overflow = 'hidden';
}
function closeConfirm() {
  document.getElementById('upgrade-modal').style.display = 'none';
  document.body.style.overflow = '';
}
function toggleVoucher() {
  const vb = document.getElementById('voucher-box');
  const tb = document.getElementById('toggle-voucher-btn');
  if(vb.style.display === 'none') { vb.style.display='flex'; tb.innerHTML='<i class="ph-bold ph-x"></i> Tutup Voucher'; }
  else { vb.style.display='none'; tb.innerHTML='<i class="ph-bold ph-tag"></i> Pakai Voucher Diskon?'; }
}
function applyVoucher() {
  const code = document.getElementById('voucher-input').value.toUpperCase().trim();
  const mid = document.getElementById('chosen-id').value;
  const msg = document.getElementById('voucher-msg');
  if(!code){ msg.style.color='#ef4444'; msg.innerText='Masukkan kode voucher.'; msg.style.display='block'; return; }
  msg.style.color='#94a3b8'; msg.innerText='Mengecek...'; msg.style.display='block';
  fetch('', {
    method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'},
    body:'action=check_voucher&code='+encodeURIComponent(code)+'&membership_id='+encodeURIComponent(mid)+'&_csrf='+encodeURIComponent(document.querySelector('input[name="_csrf"]')?.value||'')
  })
  .then(r=>r.json())
  .then(res=>{
    if(res.error){ msg.style.color='#ef4444'; msg.innerText=res.error; msg.style.display='block';
      document.getElementById('applied-voucher-code').value='';
      document.getElementById('discount-row').style.display='none';
      document.getElementById('final-price-row').style.display='none';
      checkAffordability(currentPrice);
    } else {
      msg.style.color='#10b981'; msg.innerText='Diskon '+res.discount_text+' aktif!'; msg.style.display='block';
      document.getElementById('applied-voucher-code').value=code;
      document.getElementById('modal-discount').textContent=res.discount_amount_formatted;
      document.getElementById('modal-pct').textContent=res.discount_text;
      document.getElementById('modal-final-price').textContent=res.final_price_formatted;
      document.getElementById('discount-row').style.display='block';
      document.getElementById('final-price-row').style.display='block';
      document.getElementById('voucher-box').style.display='none';
      document.getElementById('toggle-voucher-btn').style.display='none';
      checkAffordability(res.final_price);
    }
  })
  .catch(()=>{ msg.style.color='#ef4444'; msg.innerText='Gagal cek voucher.'; msg.style.display='block'; });
}
function submitUpgrade() {
  const btn = document.getElementById('modal-confirm-btn');
  btn.disabled = true; btn.textContent = 'Memproses...';
  document.getElementById('upgrade-form').submit();
}
document.getElementById('upgrade-modal').addEventListener('click', function(e){ if(e.target===this) closeConfirm(); });
document.getElementById('refund-modal').addEventListener('click', function(e){ if(e.target===this) e.target.style.display='none'; });
</script>

<?php require dirname(__DIR__) . '/partials/footer.php'; ?>