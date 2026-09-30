<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/auth/guard.php';

$campaign_enabled = setting($pdo, 'threads_campaign_enabled', '1') === '1';
if (!$campaign_enabled) {
    $_SESSION['flash_home_err'] = 'Kampanye Threads saat ini sedang tidak aktif.';
    redirect('/home');
}

$reward_amount  = (float)setting($pdo, 'threads_campaign_reward', '25000');
$reward_amount2 = (float)setting($pdo, 'threads_campaign_reward_step2', '50000');

$instructions = setting($pdo, 'threads_campaign_instructions', "Promosikan LebahCuan di Threads dan dapatkan cuan tambahan Rp 25.000! \n\nKriteria Postingan Langkah 1:\n1. Postingan harus menyertakan gambar (screenshot/bukti bayar/foto aplikasi).\n2. Di dalam gambar screenshot, HARUS tertera jelas Nama/Username Threads kalian.\n3. Teks postingan berupa kalimat ajakan atau cerita pengalaman positif kamu mendapatkan cuan di LebahCuan.\n4. Berikan komentar atau caption positif tentang LebahCuan.\n\nCara Klaim:\n1. Buat postingan sesuai kriteria di atas pada akun Threads kamu.\n2. Ambil screenshot postingan tersebut (harus terlihat username kalian).\n3. Upload screenshot di form bawah ini.");
$instructions2 = setting($pdo, 'threads_campaign_instructions_step2', "Promosikan LebahCuan di Threads - Langkah 2 (Dapatkan Rp 50.000 + Akses Langsung Admin & Jadi Promotor Khusus!)\n\nKriteria Postingan Langkah 2:\n1. Kamu telah mengundang minimal 10 referral bergabung di LebahCuan.\n2. Postingan Threads kamu viral / ramai dengan minimal 5.000 (5K) views / tayangan.\n3. Berikan screenshot (bukti SS) bahwa postingan Threads kamu tembus minimal 5.000 views (bisa kirim hingga 3 screenshot bukti statistik & interaksi).");

$admin_contact = trim((string)setting($pdo, 'threads_admin_contact', ''));
if (empty($admin_contact)) {
    $admin_contact = '/livechat';
}

// Referral stats
$stmtRefCount = $pdo->prepare("SELECT COUNT(*) FROM users WHERE referred_by=?");
$stmtRefCount->execute([$user['referral_code']]);
$user_referral_count = (int)$stmtRefCount->fetchColumn();

// Step 1 Requests
$stmtPending1 = $pdo->prepare("SELECT * FROM admin_requests WHERE user_id=? AND type='threads_campaign' AND status='pending' LIMIT 1");
$stmtPending1->execute([$user['id']]);
$pendingRequest = $stmtPending1->fetch();

$stmtApproved1 = $pdo->prepare("SELECT * FROM admin_requests WHERE user_id=? AND type='threads_campaign' AND status='approved' LIMIT 1");
$stmtApproved1->execute([$user['id']]);
$approvedRequest = $stmtApproved1->fetch();

$stmtRejected1 = $pdo->prepare("SELECT * FROM admin_requests WHERE user_id=? AND type='threads_campaign' AND status='rejected' ORDER BY id DESC LIMIT 1");
$stmtRejected1->execute([$user['id']]);
$rejectedRequest = $stmtRejected1->fetch();

// Step 2 Requests
$stmtPending2 = $pdo->prepare("SELECT * FROM admin_requests WHERE user_id=? AND type='threads_campaign_step2' AND status='pending' LIMIT 1");
$stmtPending2->execute([$user['id']]);
$pendingRequest2 = $stmtPending2->fetch();

$stmtApproved2 = $pdo->prepare("SELECT * FROM admin_requests WHERE user_id=? AND type='threads_campaign_step2' AND status='approved' LIMIT 1");
$stmtApproved2->execute([$user['id']]);
$approvedRequest2 = $stmtApproved2->fetch();

$stmtRejected2 = $pdo->prepare("SELECT * FROM admin_requests WHERE user_id=? AND type='threads_campaign_step2' AND status='rejected' ORDER BY id DESC LIMIT 1");
$stmtRejected2->execute([$user['id']]);
$rejectedRequest2 = $stmtRejected2->fetch();

$is_promotor_vip = !empty($approvedRequest2);

$flash = $flashType = '';
if ($_SESSION['flash_threads_msg'] ?? null) {
    $flash = $_SESSION['flash_threads_msg'];
    $flashType = $_SESSION['flash_threads_type'] ?? 'success';
    unset($_SESSION['flash_threads_msg'], $_SESSION['flash_threads_type']);
}

// POST Handling
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_enforce();
    $step = $_POST['step'] ?? 'step1';
    
    $checkType = $step === 'step2' ? 'threads_campaign_step2' : 'threads_campaign';
    $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM admin_requests WHERE user_id=? AND type=? AND status='pending'");
    $stmtCheck->execute([$user['id'], $checkType]);
    
    if ((int)$stmtCheck->fetchColumn() > 0) {
        $flash = 'Kamu masih memiliki klaim pending untuk langkah ini.';
        $flashType = 'error';
    } elseif ($step === 'step2' && $user_referral_count < 10) {
        $flash = 'Kamu belum memenuhi syarat 10 referral untuk mengajukan Langkah 2.';
        $flashType = 'error';
    } else {
        $uploadDir = dirname(__DIR__) . '/uploads/threads/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        if ($step === 'step1') {
            // STEP 1: Single screenshot
            if (empty($_FILES['proof']['tmp_name'])) {
                $flash = 'Silakan pilih file bukti screenshot postingan kamu!';
                $flashType = 'error';
            } else {
                $file = $_FILES['proof'];
                $ext = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
                if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) {
                    $flash = 'Format file tidak didukung! Harus JPG, PNG, atau WEBP.';
                    $flashType = 'error';
                } elseif ($file['size'] > 5 * 1024 * 1024) {
                    $flash = 'Ukuran file terlalu besar! Maksimal 5MB.';
                    $flashType = 'error';
                } else {
                    $filename = 'threads_step1_' . $user['id'] . '_' . time() . '.' . $ext;
                    if (move_uploaded_file($file['tmp_name'], $uploadDir . $filename)) {
                        $proof_path = 'uploads/threads/' . $filename;
                        
                        $payload = json_encode([
                            'proof_images' => [$proof_path],
                            'proof_image'  => $proof_path,
                            'reward_amount'=> $reward_amount,
                            'submitted_at' => date('Y-m-d H:i:s')
                        ]);
                        
                        $pdo->prepare("INSERT INTO admin_requests (user_id, type, status, payload, created_at, updated_at) VALUES (?, 'threads_campaign', 'pending', ?, NOW(), NOW())")
                            ->execute([$user['id'], $payload]);
                        $request_id = (int)$pdo->lastInsertId();
                        
                        // Telegram Notification
                        $tgMsg = "🧵 <b>KLAIM PROMOSI THREADS - LANGKAH 1</b>\n"
                            . "━━━━━━━━━━━━━━━━━━━━━━\n"
                            . "👤 <b>User:</b> <code>{$user['username']}</code> (ID: {$user['id']})\n"
                            . "💵 <b>Reward Saldo Tarik:</b> <code>" . format_rp($reward_amount) . "</code>\n"
                            . "📅 <b>Tanggal:</b> " . date('d M Y H:i') . "\n"
                            . "━━━━━━━━━━━━━━━━━━━━━━\n"
                            . "<i>Silakan verifikasi screenshot postingan Threads di atas.</i>";
                        
                        $kb = [
                            [
                                ['text' => '✅ Setujui Reward', 'callback_data' => 'req_approve_' . $request_id],
                                ['text' => '❌ Tolak Klaim', 'callback_data' => 'req_reject_' . $request_id]
                            ]
                        ];
                        
                        send_telegram_photo($pdo, $uploadDir . $filename, $tgMsg, $kb, 'threads');
                        
                        $_SESSION['flash_threads_msg'] = 'Bukti postingan Langkah 1 berhasil di-upload! Admin akan segera memverifikasi klaim kamu.';
                        $_SESSION['flash_threads_type'] = 'success';
                        redirect('/threads');
                    } else {
                        $flash = 'Gagal menyimpan file upload. Coba lagi.';
                        $flashType = 'error';
                    }
                }
            }
        } elseif ($step === 'step2') {
            // STEP 2: Up to 3 screenshots (minimal 5k views)
            $uploaded_files = [];
            $input_names = ['proof_1', 'proof_2', 'proof_3'];
            
            // Support both multiple array input or separate slots
            if (!empty($_FILES['proofs']['name'][0])) {
                $total_files = min(3, count($_FILES['proofs']['name']));
                for ($i = 0; $i < $total_files; $i++) {
                    if (!empty($_FILES['proofs']['tmp_name'][$i]) && is_uploaded_file($_FILES['proofs']['tmp_name'][$i])) {
                        $ext = strtolower(pathinfo((string)$_FILES['proofs']['name'][$i], PATHINFO_EXTENSION));
                        if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp']) && $_FILES['proofs']['size'][$i] <= 5 * 1024 * 1024) {
                            $filename = 'threads_step2_' . $user['id'] . '_' . ($i + 1) . '_' . time() . '.' . $ext;
                            if (move_uploaded_file($_FILES['proofs']['tmp_name'][$i], $uploadDir . $filename)) {
                                $uploaded_files[] = 'uploads/threads/' . $filename;
                            }
                        }
                    }
                }
            } else {
                foreach ($input_names as $idx => $in_name) {
                    if (!empty($_FILES[$in_name]['tmp_name']) && is_uploaded_file($_FILES[$in_name]['tmp_name'])) {
                        $ext = strtolower(pathinfo((string)$_FILES[$in_name]['name'], PATHINFO_EXTENSION));
                        if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp']) && $_FILES[$in_name]['size'] <= 5 * 1024 * 1024) {
                            $filename = 'threads_step2_' . $user['id'] . '_' . ($idx + 1) . '_' . time() . '.' . $ext;
                            if (move_uploaded_file($_FILES[$in_name]['tmp_name'], $uploadDir . $filename)) {
                                $uploaded_files[] = 'uploads/threads/' . $filename;
                            }
                        }
                    }
                }
            }
            
            if (empty($uploaded_files)) {
                $flash = 'Wajib upload minimal 1 screenshot (bisa hingga 3 screenshot) bukti postingan ramai minimal 5.000 views!';
                $flashType = 'error';
            } else {
                $payload = json_encode([
                    'proof_images'   => $uploaded_files,
                    'proof_image'    => $uploaded_files[0],
                    'reward_amount'  => $reward_amount2,
                    'views_rule'     => 'min_5k_views',
                    'referral_count' => $user_referral_count,
                    'submitted_at'   => date('Y-m-d H:i:s')
                ]);
                
                $pdo->prepare("INSERT INTO admin_requests (user_id, type, status, payload, created_at, updated_at) VALUES (?, 'threads_campaign_step2', 'pending', ?, NOW(), NOW())")
                    ->execute([$user['id'], $payload]);
                $request_id = (int)$pdo->lastInsertId();
                
                // Dispatch to Telegram Topic: 'threads'
                $fileCount = count($uploaded_files);
                $tgMsg = "👑 <b>PENGAJUAN PROMOTOR KHUSUS THREADS (LEVEL 2)</b>\n"
                    . "━━━━━━━━━━━━━━━━━━━━━━\n"
                    . "👤 <b>User:</b> <code>{$user['username']}</code> (ID: {$user['id']})\n"
                    . "🎯 <b>Target:</b> Promotor Khusus & Akses Direct Admin\n"
                    . "👁️ <b>Syarat Rule:</b> Minimal 5.000 (5K) Views\n"
                    . "📸 <b>Jumlah Screenshot:</b> {$fileCount} Foto dilampirkan\n"
                    . "👥 <b>Total Referral:</b> <code>{$user_referral_count} orang</code> (Lolos Syarat)\n"
                    . "💵 <b>Reward Saldo:</b> <code>" . format_rp($reward_amount2) . "</code>\n"
                    . "📅 <b>Tanggal:</b> " . date('d M Y H:i') . "\n"
                    . "━━━━━━━━━━━━━━━━━━━━━━\n"
                    . "<i>Periksa bukti screenshot statistik views di atas. Jika valid $\ge$ 5k views, setujui untuk meloloskan user menjadi Promotor Khusus.</i>";
                
                $kb = [
                    [
                        ['text' => '👑 Setujui Promotor Khusus', 'callback_data' => 'req_approve_' . $request_id],
                        ['text' => '❌ Tolak Pengajuan', 'callback_data' => 'req_reject_' . $request_id]
                    ]
                ];
                
                // Send primary photo
                send_telegram_photo($pdo, dirname(__DIR__) . '/' . $uploaded_files[0], $tgMsg, $kb, 'threads');
                
                // Send additional photos if user uploaded 2 or 3 screenshots
                for ($k = 1; $k < $fileCount; $k++) {
                    $extraCap = "📸 <b>Bukti Tambahan #" . ($k + 1) . " (Statistik 5K Views)</b>\n"
                        . "User: <code>{$user['username']}</code> | Pengajuan Level 2";
                    send_telegram_photo($pdo, dirname(__DIR__) . '/' . $uploaded_files[$k], $extraCap, [], 'threads');
                }
                
                $_SESSION['flash_threads_msg'] = "Bukti postingan Level 2 ({$fileCount} screenshot) berhasil dikirim! Tim Admin akan segera memverifikasi bukti 5.000 views kamu.";
                $_SESSION['flash_threads_type'] = 'success';
                redirect('/threads?step=2');
            }
        }
    }
}

$pageTitle = 'Event Cuan Threads';
$activePage = 'home';
require dirname(__DIR__) . '/partials/header.php';
?>

<style>
/* ══════════════════════════════════════════════════════════
   LEBAHCUAN — THREADS CAMPAIGN (HONEY & AMBER THEMED)
   Clean, Modern Mobile First UI with 3D Tactile Buttons
   ══════════════════════════════════════════════════════════ */
.th-page {
  max-width: 480px;
  margin: 0 auto;
  padding-bottom: calc(var(--nav-h, 64px) + 28px);
}

/* HERO CANOPY BANNER */
.th-hero {
  background: linear-gradient(160deg, #18181b 0%, #27272a 40%, #78350f 100%);
  border-bottom: 3.5px solid #78350f;
  padding: 16px 16px 20px;
  position: relative;
  overflow: hidden;
  box-shadow: 0 4px 12px rgba(0,0,0,0.15);
}
.th-hero::before {
  content: '';
  position: absolute;
  top: -40px; right: -40px;
  width: 140px; height: 140px;
  background: radial-gradient(circle, rgba(245, 158, 11, 0.25) 0%, transparent 70%);
  pointer-events: none;
}
.th-hero-top {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 12px;
  position: relative;
  z-index: 2;
}
.th-back-btn {
  width: 36px; height: 36px;
  background: #ffffff;
  border: 2px solid #78350f;
  border-radius: 12px;
  display: flex; align-items: center; justify-content: center;
  color: #78350f;
  font-size: 18px; font-weight: 900;
  text-decoration: none;
  box-shadow: 0 3px 0 #78350f;
  transition: transform 0.1s;
}
.th-back-btn:active { transform: translateY(2px); box-shadow: 0 1px 0 #78350f; }

.th-hero-badge {
  display: inline-flex; align-items: center; gap: 5px;
  background: #fef3c7; color: #78350f;
  border: 2px solid #78350f;
  border-radius: 20px;
  padding: 3.5px 12px;
  font-size: 11px; font-weight: 900;
  box-shadow: 0 2.5px 0 #78350f;
}

.th-mascot-row {
  display: flex;
  align-items: center;
  gap: 12px;
  position: relative;
  z-index: 2;
}
.th-mascot-img {
  width: 62px; height: 62px;
  object-fit: contain;
  filter: drop-shadow(0 4px 6px rgba(0,0,0,0.35));
  animation: thBeeFloat 3s ease-in-out infinite;
  flex-shrink: 0;
}
@keyframes thBeeFloat {
  0%, 100% { transform: translateY(0); }
  50% { transform: translateY(-5px); }
}
.th-bubble {
  background: #ffffff;
  border: 2.5px solid #78350f;
  border-radius: 16px;
  padding: 10px 12px;
  box-shadow: 0 3.5px 0 #78350f;
  flex: 1;
  position: relative;
}
.th-bubble::before {
  content: '';
  position: absolute; left: -8px; top: 50%;
  transform: translateY(-50%);
  border-width: 5px 8px 5px 0;
  border-style: solid;
  border-color: transparent #78350f transparent transparent;
}
.th-bubble-title {
  font-size: 13px; font-weight: 900; color: #78350f;
  display: flex; align-items: center; gap: 5px; margin-bottom: 2px;
}
.th-bubble-sub {
  font-size: 11px; font-weight: 700; color: #92400e; line-height: 1.35;
}

/* TRUST STRIP */
.th-trust-strip {
  background: #fffbeb;
  border-bottom: 2px solid #fde68a;
  padding: 8px 12px;
  display: flex;
  align-items: center;
  justify-content: space-around;
  gap: 6px;
  font-size: 10px;
  font-weight: 800;
  color: #78350f;
}
.th-trust-item {
  display: flex; align-items: center; gap: 4px;
}

/* PAGE BODY CONTAINER */
.th-body {
  padding: 14px 12px;
}

/* TABS SWITCHER */
.th-tabs-wrap {
  display: grid;
  grid-template-columns: 1fr 1fr;
  background: #f1f5f9;
  padding: 4px;
  border-radius: 16px;
  border: 2.5px solid #78350f;
  box-shadow: 0 3px 0 #78350f;
  margin-bottom: 16px;
  gap: 4px;
}
.th-tab-btn {
  border: none;
  background: transparent;
  padding: 9px 6px;
  border-radius: 12px;
  font-size: 11.5px;
  font-weight: 900;
  color: #64748b;
  cursor: pointer;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 2px;
  transition: all 0.15s ease;
  font-family: inherit;
  position: relative;
}
.th-tab-btn.active {
  background: linear-gradient(135deg, #f59e0b, #d97706);
  color: #ffffff;
  box-shadow: 0 2px 4px rgba(120,53,15,0.25);
}
.th-tab-badge {
  font-size: 8.5px;
  padding: 1px 6px;
  border-radius: 6px;
  background: #ffffff;
  color: #78350f;
  font-weight: 900;
}
.th-tab-btn.active .th-tab-badge {
  background: #fef3c7;
  color: #78350f;
}

/* MAIN CARD CONTAINER */
.th-card {
  background: #ffffff;
  border: 2.5px solid #78350f;
  border-radius: 20px;
  padding: 16px 14px;
  box-shadow: 0 4.5px 0 #78350f;
  margin-bottom: 16px;
  position: relative;
}

/* REWARD STRIP */
.th-reward-strip {
  background: linear-gradient(135deg, #ecfdf5 0%, #d1fae5 100%);
  border: 2px solid #10b981;
  border-radius: 14px;
  padding: 10px 14px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 14px;
  box-shadow: 0 2.5px 0 #059669;
}
.th-reward-strip-lbl {
  font-size: 11px;
  font-weight: 900;
  color: #065f46;
  display: flex;
  align-items: center;
  gap: 4px;
}
.th-reward-strip-val {
  font-size: 18px;
  font-weight: 900;
  color: #047857;
  letter-spacing: -0.3px;
}

/* VIP PROMOTOR BANNER */
.th-vip-badge-card {
  background: linear-gradient(135deg, #18181b 0%, #27272a 100%);
  border: 2px solid #f59e0b;
  border-radius: 16px;
  padding: 12px 14px;
  margin-bottom: 14px;
  color: #ffffff;
  box-shadow: 0 3.5px 0 #78350f, 0 0 14px rgba(245,158,11,0.25);
}
.th-vip-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 8px;
}
.th-vip-tag {
  background: linear-gradient(135deg, #f59e0b, #d97706);
  color: #78350f;
  font-size: 9.5px;
  font-weight: 900;
  padding: 2px 7px;
  border-radius: 8px;
  display: inline-flex;
  align-items: center;
  gap: 4px;
}
.th-vip-title {
  font-size: 13.5px;
  font-weight: 900;
  color: #fde68a;
  margin-bottom: 8px;
}
.th-benefits-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 6px;
}
.th-benefit-item {
  background: rgba(255,255,255,0.08);
  border: 1px solid rgba(245,158,11,0.3);
  border-radius: 10px;
  padding: 6px 8px;
  font-size: 10.5px;
  font-weight: 800;
  color: #f8fafc;
  display: flex;
  align-items: center;
  gap: 5px;
}

/* REFERRAL PROGRESS BAR */
.th-ref-progress {
  background: #f8fafc;
  border: 2px solid #e2e8f0;
  border-radius: 14px;
  padding: 10px 12px;
  margin-bottom: 14px;
}
.th-ref-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  font-size: 11px;
  font-weight: 800;
  color: #475569;
  margin-bottom: 6px;
}
.th-ref-bar {
  height: 8px;
  background: #e2e8f0;
  border-radius: 6px;
  overflow: hidden;
  margin-bottom: 6px;
}
.th-ref-fill {
  height: 100%;
  background: linear-gradient(90deg, #f59e0b, #10b981);
  border-radius: 6px;
  transition: width 0.3s ease;
}

/* INSTRUCTIONS ACCORDION / BOX */
.th-inst-box {
  background: #fffbeb;
  border: 1.5px solid #fde68a;
  border-radius: 14px;
  padding: 12px;
  font-size: 11.5px;
  color: #78350f;
  line-height: 1.45;
  font-weight: 700;
  white-space: pre-line;
  margin-bottom: 14px;
}

/* COPY REFF BOX */
.th-copy-reff {
  background: #ffffff;
  border: 1.5px dashed #f59e0b;
  border-radius: 12px;
  padding: 8px 10px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 14px;
  gap: 6px;
}
.th-copy-btn {
  background: #f59e0b;
  color: #78350f;
  border: none;
  border-radius: 8px;
  padding: 5px 10px;
  font-size: 10.5px;
  font-weight: 900;
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  gap: 3px;
  font-family: inherit;
}

/* UPLOAD SLOTS & MULTI-PREVIEW */
.th-slot-grid {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 8px;
  margin-bottom: 14px;
}
.th-slot-box {
  border: 2px dashed #cbd5e1;
  background: #f8fafc;
  border-radius: 14px;
  padding: 10px 6px;
  text-align: center;
  cursor: pointer;
  position: relative;
  overflow: hidden;
  transition: all 0.15s ease;
  min-height: 105px;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
}
.th-slot-box:hover {
  border-color: #f59e0b;
  background: #fffbeb;
}
.th-slot-box input[type="file"] {
  position: absolute;
  top: 0; left: 0; width: 100%; height: 100%;
  opacity: 0;
  cursor: pointer;
}
.th-slot-icon {
  font-size: 24px;
  color: #94a3b8;
  margin-bottom: 4px;
}
.th-slot-lbl {
  font-size: 9px;
  font-weight: 800;
  color: #64748b;
  line-height: 1.2;
}
.th-slot-tag {
  font-size: 7.5px;
  font-weight: 900;
  padding: 1px 4px;
  border-radius: 4px;
  margin-top: 3px;
}
.th-slot-tag--req { background: #fef3c7; color: #b45309; }
.th-slot-tag--opt { background: #e2e8f0; color: #475569; }

.th-slot-preview {
  position: absolute;
  inset: 0;
  width: 100%; height: 100%;
  object-fit: cover;
  display: none;
}

/* SUBMIT CTA BUTTON */
.btn-th-cta {
  width: 100%;
  background: linear-gradient(135deg, #f59e0b, #d97706);
  border: 2.5px solid #78350f;
  border-radius: 16px;
  padding: 13px;
  color: #ffffff;
  font-size: 13.5px;
  font-weight: 900;
  font-family: inherit;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  cursor: pointer;
  box-shadow: 0 4px 0 #78350f;
  transition: transform 0.1s, box-shadow 0.1s;
}
.btn-th-cta:active {
  transform: translateY(2px);
  box-shadow: 0 2px 0 #78350f;
}

/* STATUS BOXES */
.th-status-box {
  background: #f8fafc;
  border: 2px solid #cbd5e1;
  border-radius: 16px;
  padding: 14px;
  text-align: center;
  margin-bottom: 14px;
}
.th-status-icon {
  font-size: 38px;
  margin-bottom: 6px;
}
.th-status-title {
  font-size: 14.5px;
  font-weight: 900;
  color: #1e293b;
  margin-bottom: 4px;
}
.th-status-desc {
  font-size: 11.5px;
  font-weight: 700;
  color: #64748b;
  line-height: 1.4;
}

/* VIP APPROVED PROMOTOR CARD */
.th-promotor-active-card {
  background: linear-gradient(135deg, #18181b 0%, #27272a 100%);
  border: 2.5px solid #f59e0b;
  border-radius: 18px;
  padding: 16px;
  text-align: center;
  box-shadow: 0 4px 0 #78350f, 0 0 16px rgba(245,158,11,0.4);
  color: #ffffff;
  margin-bottom: 14px;
}
.btn-vip-admin-direct {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  width: 100%;
  background: linear-gradient(135deg, #22c55e, #16a34a);
  border: 2px solid #ffffff;
  border-radius: 14px;
  padding: 12px;
  color: #ffffff;
  font-size: 13px;
  font-weight: 900;
  text-decoration: none;
  box-shadow: 0 3px 0 #15803d;
  margin-top: 12px;
}
.btn-vip-admin-direct:active {
  transform: translateY(2px);
  box-shadow: 0 1px 0 #15803d;
}

/* GALLERY PREVIEWS */
.th-gallery-preview {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 6px;
  margin-top: 10px;
}
.th-gallery-thumb {
  width: 100%;
  height: 75px;
  border-radius: 8px;
  border: 1.5px solid #cbd5e1;
  object-fit: cover;
}
</style>

<div class="th-page">

  <!-- HERO CANOPY BANNER -->
  <div class="th-hero">
    <div class="th-hero-top">
      <a href="/home" class="th-back-btn" title="Kembali ke Beranda">
        <i class="ph-bold ph-caret-left"></i>
      </a>
      <div class="th-hero-badge">
        <i class="ph-bold ph-threads-logo"></i> Event Promosi Threads
      </div>
      <div style="width:36px;"></div>
    </div>

    <div class="th-mascot-row">
      <img src="/assets/game/bee_golden.png" class="th-mascot-img" alt="Lebah Cuan">
      <div class="th-bubble">
        <div class="th-bubble-title">
          <i class="ph-fill ph-sparkle" style="color:#f59e0b;"></i> Cuan Viral Threads!
        </div>
        <div class="th-bubble-sub">
          Bagikan pengalaman cuanmu, kumpulkan reward hingga <strong>Rp 75.000</strong>, dan raih <strong>Jalur Khusus Direct Admin</strong>!
        </div>
      </div>
    </div>
  </div>

  <!-- TRUST STRIP -->
  <div class="th-trust-strip">
    <div class="th-trust-item"><i class="ph-fill ph-check-circle" style="color:#10b981;"></i> Saldo Masuk Saldo Tarik</div>
    <div class="th-trust-item"><i class="ph-fill ph-users-three" style="color:#f59e0b;"></i> Promotor Khusus</div>
    <div class="th-trust-item"><i class="ph-fill ph-lightning" style="color:#ea580c;"></i> Prioritas VIP</div>
  </div>

  <div class="th-body">

    <?php if ($flash): ?>
      <div style="padding:10px 14px;border-radius:14px;font-size:12px;font-weight:800;margin-bottom:14px;border:2px solid;<?= $flashType==='error'?'background:#fee2e2;border-color:#ef4444;color:#991b1b;':'background:#dcfce7;border-color:#16a34a;color:#166534;' ?>">
        <i class="ph-bold <?= $flashType==='error'?'ph-warning-circle':'ph-check-circle' ?>"></i>
        <?= htmlspecialchars($flash) ?>
      </div>
    <?php endif; ?>

    <!-- TABS SWITCHER -->
    <div class="th-tabs-wrap">
      <button type="button" class="th-tab-btn active" id="btn-tab-step1" onclick="switchThTab('step1')">
        <span>Langkah 1</span>
        <span class="th-tab-badge">Reward Rp 25.000</span>
      </button>
      <button type="button" class="th-tab-btn" id="btn-tab-step2" onclick="switchThTab('step2')">
        <span>Langkah 2</span>
        <span class="th-tab-badge">👑 VIP · 5K VIEWS</span>
      </button>
    </div>

    <!-- ══════════════════════════════════════════════
         TAB 1: LANGKAH 1 (PROMO THREADS - RP 25.000)
         ══════════════════════════════════════════════ -->
    <div id="tab-pane-step1" class="th-tab-pane">
      <div class="th-card">
        
        <div class="th-reward-strip">
          <div class="th-reward-strip-lbl">
            <i class="ph-bold ph-coins" style="font-size:16px;"></i>
            <span>REWARD LANGKAH 1</span>
          </div>
          <div class="th-reward-strip-val"><?= format_rp($reward_amount) ?></div>
        </div>

        <!-- Quick Copy Reff Link -->
        <div class="th-copy-reff">
          <div style="min-width:0;flex:1;">
            <div style="font-size:9.5px;font-weight:800;color:#92400e;">KODE REFERRAL KAMU:</div>
            <div style="font-size:13px;font-weight:900;color:#78350f;"><?= htmlspecialchars($user['referral_code']) ?></div>
          </div>
          <button type="button" class="th-copy-btn" onclick="copyReffText('<?= htmlspecialchars($user['referral_code']) ?>')">
            <i class="ph-bold ph-copy"></i> Salin Kode
          </button>
        </div>

        <?php if ($pendingRequest): ?>
          <?php $pData = json_decode($pendingRequest['payload'] ?? '{}', true) ?: []; ?>
          <div class="th-status-box" style="border-color:#f59e0b;background:#fffbeb;">
            <div class="th-status-icon">⏳</div>
            <div class="th-status-title" style="color:#b45309;">Klaim Langkah 1 Sedang Diproses</div>
            <div class="th-status-desc">Screenshot postingan Threads kamu sedang diperiksa admin. Reward <strong><?= format_rp($reward_amount) ?></strong> akan masuk otomatis ke Saldo Tarik setelah disetujui.</div>
            <?php if (!empty($pData['proof_image'])): ?>
              <div style="margin-top:10px;">
                <img src="/<?= htmlspecialchars($pData['proof_image']) ?>" alt="Bukti" style="max-height:160px;border-radius:10px;border:1.5px solid #d97706;object-fit:contain;">
              </div>
            <?php endif; ?>
          </div>
        <?php elseif ($approvedRequest): ?>
          <div class="th-status-box" style="border-color:#10b981;background:#f0fdf4;">
            <div class="th-status-icon">🎉</div>
            <div class="th-status-title" style="color:#047857;">Klaim Langkah 1 Disetujui!</div>
            <div class="th-status-desc">Cuan <strong><?= format_rp($reward_amount) ?></strong> telah ditambahkan ke Saldo Tarik kamu. Ayo lanjutkan ke <strong>Langkah 2</strong> untuk raih Rp 50.000 & Akses Direct Admin!</div>
            <button type="button" onclick="switchThTab('step2')" class="btn-th-cta" style="margin-top:12px;">
              <span>Lanjut ke Langkah 2 (VIP Promotor)</span>
              <i class="ph-bold ph-arrow-right"></i>
            </button>
          </div>
        <?php else: ?>
          <?php if (!empty($rejectedRequest)): ?>
            <div style="padding:10px 12px;border-radius:12px;background:#fef2f2;border:2px solid #ef4444;color:#991b1b;font-size:11px;font-weight:800;margin-bottom:12px;">
              <i class="ph-bold ph-warning-circle"></i> Klaim Sebelumnya Ditolak:
              <div style="font-weight:700;margin-top:2px;"><?= htmlspecialchars($rejectedRequest['admin_note'] ?: 'Bukti postingan belum memenuhi syarat.') ?></div>
            </div>
          <?php endif; ?>

          <div class="th-inst-box">
            <?= htmlspecialchars($instructions) ?>
          </div>

          <form method="POST" enctype="multipart/form-data" id="form-step1" onsubmit="return handleFormSubmit('btn-sub-step1')">
            <?= csrf_field() ?>
            <input type="hidden" name="step" value="step1">

            <div style="margin-bottom:14px;">
              <label style="font-size:11.5px;font-weight:900;color:#78350f;margin-bottom:6px;display:block;">
                📸 Upload Screenshot Postingan Threads (Terlihat Username)
              </label>
              <div class="th-slot-box" id="zone-step1" style="min-height:120px;">
                <input type="file" name="proof" id="file-step1" accept="image/*" required onchange="previewSingleFile(this, 'zone-step1', 'icon-step1', 'lbl-step1')">
                <i class="ph-bold ph-image th-slot-icon" id="icon-step1" style="font-size:32px;"></i>
                <div class="th-slot-lbl" id="lbl-step1" style="font-size:11px;font-weight:800;">
                  Pilih atau seret screenshot postingan kamu<br><small style="color:#94a3b8;">Format: JPG, PNG, WEBP (Maks: 5MB)</small>
                </div>
              </div>
            </div>

            <button type="submit" id="btn-sub-step1" class="btn-th-cta">
              <i class="ph-bold ph-paper-plane-tilt"></i>
              <span>Kirim Bukti Langkah 1</span>
            </button>
          </form>
        <?php endif; ?>

      </div>
    </div>

    <!-- ══════════════════════════════════════════════
         TAB 2: LANGKAH 2 (PROMOTOR KHUSUS & 5K VIEWS)
         ══════════════════════════════════════════════ -->
    <div id="tab-pane-step2" class="th-tab-pane" style="display:none;">
      <div class="th-card">

        <!-- VIP PROMOTOR BENEFIT CARD -->
        <div class="th-vip-badge-card">
          <div class="th-vip-header">
            <div class="th-vip-tag">
              <i class="ph-fill ph-crown"></i> PROMOTOR KHUSUS
            </div>
            <span style="font-size:11px;font-weight:900;color:#fde68a;">LEVEL 2 VIP</span>
          </div>
          <div class="th-vip-title">
            Dapatkan Rp 50.000 &amp; Akses Langsung ke Admin!
          </div>
          <div class="th-benefits-grid">
            <div class="th-benefit-item">
              <i class="ph-fill ph-money" style="color:#34d399;"></i> Rp 50.000 Saldo Tarik
            </div>
            <div class="th-benefit-item">
              <i class="ph-fill ph-chats-circle" style="color:#60a5fa;"></i> Direct Admin WhatsApp
            </div>
            <div class="th-benefit-item">
              <i class="ph-fill ph-seal-check" style="color:#fbbf24;"></i> Gelar Promotor Resmi
            </div>
            <div class="th-benefit-item">
              <i class="ph-fill ph-lightning" style="color:#f472b6;"></i> Prioritas Approval WD
            </div>
          </div>
        </div>

        <!-- REFERRAL PROGRESS MONITOR -->
        <div class="th-ref-progress">
          <div class="th-ref-head">
            <span>Syarat 1: Minimal 10 Referral</span>
            <strong><?= $user_referral_count ?> / 10 Orang</strong>
          </div>
          <div class="th-ref-bar">
            <?php $refPct = min(100, (int)round(($user_referral_count / 10) * 100)); ?>
            <div class="th-ref-fill" style="width: <?= $refPct ?>%;"></div>
          </div>
          <div style="font-size:10px;font-weight:800;display:flex;justify-content:space-between;color:<?= $user_referral_count >= 10 ? '#059669' : '#d97706' ?>;">
            <span><?= $user_referral_count >= 10 ? '✓ Syarat Referral Terpenuhi' : 'Kurang ' . (10 - $user_referral_count) . ' referral lagi' ?></span>
            <span><?= $refPct ?>%</span>
          </div>
        </div>

        <?php if ($user_referral_count < 10): ?>
          <!-- LOCKED STATUS FOR STEP 2 -->
          <div class="th-status-box" style="border-color:#ef4444;background:#fef2f2;border-style:dashed;">
            <div class="th-status-icon">🔒</div>
            <div class="th-status-title" style="color:#b91c1c;">Langkah 2 Masih Terkunci</div>
            <div class="th-status-desc" style="color:#b91c1c;">
              Kamu butuh mengundang <strong><?= 10 - $user_referral_count ?> referral lagi</strong> agar dapat mengajukan Langkah 2 dan menjadi Promotor Khusus.
            </div>
            <a href="/referral" class="btn-th-cta" style="margin-top:12px;background:linear-gradient(135deg,#10b981,#059669);border-color:#064e3b;box-shadow:0 3px 0 #064e3b;text-decoration:none;">
              <i class="ph-bold ph-share-network"></i>
              <span>Bagikan Link Referral Sekarang</span>
            </a>
          </div>

        <?php elseif ($approvedRequest2): ?>
          <!-- APPROVED VIP PROMOTOR STATUS -->
          <div class="th-promotor-active-card">
            <div style="font-size:36px;margin-bottom:6px;">👑</div>
            <div style="font-size:16px;font-weight:900;color:#fde68a;margin-bottom:4px;">
              SELAMAT! KAMU PROMOTOR KHUSUS RESMI
            </div>
            <div style="font-size:11.5px;color:#f8fafc;font-weight:700;line-height:1.4;margin-bottom:12px;">
              Klaim Langkah 2 kamu telah disetujui admin. Reward <strong><?= format_rp($reward_amount2) ?></strong> telah ditambahkan ke Saldo Tarik kamu. Kamu berhak atas akses langsung ke Admin!
            </div>

            <!-- VIP DIRECT ADMIN ACCESS BUTTON -->
            <a href="<?= htmlspecialchars($admin_contact) ?>" target="_blank" class="btn-vip-admin-direct">
              <i class="ph-bold ph-whatsapp-logo" style="font-size:18px;"></i>
              <span>Hubungi Admin Langsung (VIP Promotor)</span>
            </a>
          </div>

        <?php elseif ($pendingRequest2): ?>
          <!-- PENDING STATUS STEP 2 -->
          <?php $pData2 = json_decode($pendingRequest2['payload'] ?? '{}', true) ?: []; ?>
          <div class="th-status-box" style="border-color:#f59e0b;background:#fffbeb;">
            <div class="th-status-icon">⏳</div>
            <div class="th-status-title" style="color:#b45309;">Pengajuan Promotor Level 2 Sedang Diverifikasi</div>
            <div class="th-status-desc">
              Tim admin sedang memeriksa bukti statistik tayangan (views) minimal 5.000 views kamu. Setelah diverifikasi, status <strong>Promotor Khusus</strong> dan reward <strong><?= format_rp($reward_amount2) ?></strong> akan aktif!
            </div>

            <?php 
            $proofImages = $pData2['proof_images'] ?? (!empty($pData2['proof_image']) ? [$pData2['proof_image']] : []);
            if (!empty($proofImages)): ?>
              <div style="margin-top:12px;font-size:10.5px;font-weight:900;color:#78350f;">
                Bukti Screenshot yang Dikirim (<?= count($proofImages) ?> Foto):
              </div>
              <div class="th-gallery-preview">
                <?php foreach ($proofImages as $img): ?>
                  <a href="/<?= htmlspecialchars($img) ?>" target="_blank">
                    <img src="/<?= htmlspecialchars($img) ?>" class="th-gallery-thumb" alt="Bukti SS">
                  </a>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>

        <?php else: ?>
          <!-- REJECTED NOTICE IF ANY -->
          <?php if (!empty($rejectedRequest2)): ?>
            <div style="padding:10px 12px;border-radius:12px;background:#fef2f2;border:2px solid #ef4444;color:#991b1b;font-size:11px;font-weight:800;margin-bottom:12px;">
              <i class="ph-bold ph-warning-circle"></i> Pengajuan Langkah 2 Ditolak:
              <div style="font-weight:700;margin-top:2px;"><?= htmlspecialchars($rejectedRequest2['admin_note'] ?: 'Bukti postingan belum mencapai minimal 5.000 views atau kriteria belum lengkap.') ?></div>
            </div>
          <?php endif; ?>

          <!-- INSTRUCTIONS LEVEL 2 -->
          <div class="th-inst-box">
            <?= htmlspecialchars($instructions2) ?>
          </div>

          <!-- UPLOAD FORM FOR LEVEL 2 (UP TO 3 SCREENSHOTS) -->
          <form method="POST" enctype="multipart/form-data" id="form-step2" onsubmit="return handleFormSubmit('btn-sub-step2')">
            <?= csrf_field() ?>
            <input type="hidden" name="step" value="step2">

            <div style="margin-bottom:14px;">
              <div style="font-size:11.5px;font-weight:900;color:#78350f;margin-bottom:4px;display:flex;align-items:center;justify-content:space-between;">
                <span>📸 Bukti Screenshot Postingan Ramai (Min 5K Views)</span>
                <span style="font-size:9.5px;color:#d97706;font-weight:800;">Bisa s/d 3 Screenshot</span>
              </div>
              <div style="font-size:10.5px;color:#64748b;font-weight:700;margin-bottom:8px;">
                Lampirkan bukti insight/statistik tayangan postingan Threads kamu (minimal 5.000 views):
              </div>

              <!-- 3 INTERACTIVE UPLOAD SLOTS -->
              <div class="th-slot-grid">
                <!-- Slot 1 (Wajib) -->
                <div class="th-slot-box" id="slot-box-1">
                  <input type="file" name="proof_1" id="file-slot-1" accept="image/*" required onchange="previewSlot(this, 1)">
                  <img id="preview-slot-1" class="th-slot-preview" alt="Preview 1">
                  <i class="ph-bold ph-chart-line-up th-slot-icon" id="icon-slot-1"></i>
                  <div class="th-slot-lbl" id="lbl-slot-1">Bukti 1 (Views)</div>
                  <span class="th-slot-tag th-slot-tag--req" id="tag-slot-1">Wajib (5K+)</span>
                </div>

                <!-- Slot 2 (Opsional) -->
                <div class="th-slot-box" id="slot-box-2">
                  <input type="file" name="proof_2" id="file-slot-2" accept="image/*" onchange="previewSlot(this, 2)">
                  <img id="preview-slot-2" class="th-slot-preview" alt="Preview 2">
                  <i class="ph-bold ph-plus-circle th-slot-icon" id="icon-slot-2"></i>
                  <div class="th-slot-lbl" id="lbl-slot-2">Bukti 2 (Post)</div>
                  <span class="th-slot-tag th-slot-tag--opt" id="tag-slot-2">Opsional</span>
                </div>

                <!-- Slot 3 (Opsional) -->
                <div class="th-slot-box" id="slot-box-3">
                  <input type="file" name="proof_3" id="file-slot-3" accept="image/*" onchange="previewSlot(this, 3)">
                  <img id="preview-slot-3" class="th-slot-preview" alt="Preview 3">
                  <i class="ph-bold ph-plus-circle th-slot-icon" id="icon-slot-3"></i>
                  <div class="th-slot-lbl" id="lbl-slot-3">Bukti 3 (Interaksi)</div>
                  <span class="th-slot-tag th-slot-tag--opt" id="tag-slot-3">Opsional</span>
                </div>
              </div>
            </div>

            <button type="submit" id="btn-sub-step2" class="btn-th-cta" style="background:linear-gradient(135deg,#7c3aed,#4f46e5);border-color:#3730a3;box-shadow:0 4px 0 #312e81;">
              <i class="ph-bold ph-crown"></i>
              <span>Kirim Bukti Level 2 &amp; Jadi Promotor Khusus</span>
            </button>
          </form>

        <?php endif; ?>

      </div>
    </div>

  </div>

</div>

<script>
function switchThTab(step) {
  const btn1 = document.getElementById('btn-tab-step1');
  const btn2 = document.getElementById('btn-tab-step2');
  const pane1 = document.getElementById('tab-pane-step1');
  const pane2 = document.getElementById('tab-pane-step2');

  if (step === 'step1') {
    btn1.classList.add('active');
    btn2.classList.remove('active');
    pane1.style.display = 'block';
    pane2.style.display = 'none';
  } else {
    btn2.classList.add('active');
    btn1.classList.remove('active');
    pane2.style.display = 'block';
    pane1.style.display = 'none';
  }
}

// Check url query param ?step=2
document.addEventListener('DOMContentLoaded', () => {
  const urlParams = new URLSearchParams(window.location.search);
  if (urlParams.get('step') === '2') {
    switchThTab('step2');
  }
});

function copyReffText(code) {
  navigator.clipboard.writeText(code).then(() => {
    alert('Kode referral berhasil disalin: ' + code);
  }).catch(() => {
    alert('Kode: ' + code);
  });
}

function previewSingleFile(input, zoneId, iconId, lblId) {
  if (input.files && input.files[0]) {
    const file = input.files[0];
    const lbl = document.getElementById(lblId);
    const icon = document.getElementById(iconId);
    const zone = document.getElementById(zoneId);
    
    lbl.innerHTML = `✓ Dipilih: <strong>${file.name}</strong> (${(file.size/1024/1024).toFixed(2)} MB)`;
    lbl.style.color = '#059669';
    icon.style.color = '#10b981';
    zone.style.borderColor = '#10b981';
    zone.style.background = '#f0fdf4';
  }
}

function previewSlot(input, slotNum) {
  if (input.files && input.files[0]) {
    const file = input.files[0];
    const imgEl = document.getElementById('preview-slot-' + slotNum);
    const iconEl = document.getElementById('icon-slot-' + slotNum);
    const lblEl = document.getElementById('lbl-slot-' + slotNum);
    const tagEl = document.getElementById('tag-slot-' + slotNum);
    const boxEl = document.getElementById('slot-box-' + slotNum);

    const reader = new FileReader();
    reader.onload = function(e) {
      imgEl.src = e.target.result;
      imgEl.style.display = 'block';
      iconEl.style.display = 'none';
      lblEl.style.display = 'none';
      tagEl.style.position = 'absolute';
      tagEl.style.bottom = '4px';
      tagEl.style.zIndex = '3';
      tagEl.innerHTML = '✓ Terpilih';
      tagEl.className = 'th-slot-tag th-slot-tag--req';
      boxEl.style.borderColor = '#10b981';
    };
    reader.readAsDataURL(file);
  }
}

function handleFormSubmit(btnId) {
  const btn = document.getElementById(btnId);
  if (btn) {
    btn.disabled = true;
    btn.style.opacity = '0.7';
    btn.style.cursor = 'not-allowed';
    btn.innerHTML = '<i class="ph-bold ph-spinner" style="animation:spin 1s linear infinite;"></i> Mengirim Bukti...';
  }
  return true;
}
</script>

<?php require dirname(__DIR__) . '/partials/footer.php'; ?>
