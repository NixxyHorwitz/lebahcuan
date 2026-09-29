<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/auth/guard.php';

$campaign_enabled = setting($pdo, 'threads_campaign_enabled', '1') === '1';
if (!$campaign_enabled) {
    $_SESSION['flash_home_err'] = 'Kampanye Threads saat ini sedang tidak aktif.';
    redirect('/home');
}

$reward_amount = (float)setting($pdo, 'threads_campaign_reward', '25000');
$reward_amount2 = (float)setting($pdo, 'threads_campaign_reward_step2', '50000');

$instructions = setting($pdo, 'threads_campaign_instructions', "Promosikan LebahCuan di Threads dan dapatkan cuan tambahan Rp 25.000! \n\nKriteria Postingan Langkah 1:\n1. Postingan harus menyertakan gambar (screenshot/bukti bayar/foto aplikasi).\n2. Di dalam gambar screenshot, HARUS tertera jelas Nama/Username Threads kalian.\n3. Teks postingan berupa kalimat ajakan atau cerita pengalaman positif kamu mendapatkan cuan di LebahCuan.\n4. Berikan komentar atau caption positif tentang LebahCuan.\n\nCara Klaim:\n1. Buat postingan sesuai kriteria di atas pada akun Threads kamu.\n2. Ambil screenshot postingan tersebut (harus terlihat username kalian).\n3. Upload screenshot di form bawah ini.");
$instructions2 = setting($pdo, 'threads_campaign_instructions_step2', "Promosikan LebahCuan di Threads - Langkah 2 (Dapatkan Rp 50.000 Tambahan!)\n\nKriteria Postingan Langkah 2:\n1. Kamu telah mengundang minimal 10 referral bergabung di LebahCuan.\n2. Berikan screenshot (bukti SS) bahwa postingan Threads kamu ramai (memiliki banyak interaksi like/komen/share/tayangan).");

// Referral stats
$stmtRefCount = $pdo->prepare("SELECT COUNT(*) FROM users WHERE referred_by=?");
$stmtRefCount->execute([$user['referral_code']]);
$user_referral_count = (int)$stmtRefCount->fetchColumn();

// Step 1:
$stmtPending1 = $pdo->prepare("SELECT * FROM admin_requests WHERE user_id=? AND type='threads_campaign' AND status='pending' LIMIT 1");
$stmtPending1->execute([$user['id']]);
$pendingRequest = $stmtPending1->fetch(); // keep name to prevent template breaks

$stmtApproved1 = $pdo->prepare("SELECT * FROM admin_requests WHERE user_id=? AND type='threads_campaign' AND status='approved' LIMIT 1");
$stmtApproved1->execute([$user['id']]);
$approvedRequest = $stmtApproved1->fetch();

$stmtRejected1 = $pdo->prepare("SELECT * FROM admin_requests WHERE user_id=? AND type='threads_campaign' AND status='rejected' ORDER BY id DESC LIMIT 1");
$stmtRejected1->execute([$user['id']]);
$rejectedRequest = $stmtRejected1->fetch();

// Step 2:
$stmtPending2 = $pdo->prepare("SELECT * FROM admin_requests WHERE user_id=? AND type='threads_campaign_step2' AND status='pending' LIMIT 1");
$stmtPending2->execute([$user['id']]);
$pendingRequest2 = $stmtPending2->fetch();

$stmtApproved2 = $pdo->prepare("SELECT * FROM admin_requests WHERE user_id=? AND type='threads_campaign_step2' AND status='approved' LIMIT 1");
$stmtApproved2->execute([$user['id']]);
$approvedRequest2 = $stmtApproved2->fetch();

$stmtRejected2 = $pdo->prepare("SELECT * FROM admin_requests WHERE user_id=? AND type='threads_campaign_step2' AND status='rejected' ORDER BY id DESC LIMIT 1");
$stmtRejected2->execute([$user['id']]);
$rejectedRequest2 = $stmtRejected2->fetch();

$flash = $flashType = '';
if ($_SESSION['flash_threads_msg'] ?? null) {
    $flash = $_SESSION['flash_threads_msg'];
    $flashType = $_SESSION['flash_threads_type'] ?? 'success';
    unset($_SESSION['flash_threads_msg'], $_SESSION['flash_threads_type']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_enforce();
    $step = $_POST['step'] ?? 'step1';
    
    // Check if user already has a pending request for this step
    $checkType = $step === 'step2' ? 'threads_campaign_step2' : 'threads_campaign';
    $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM admin_requests WHERE user_id=? AND type=? AND status='pending'");
    $stmtCheck->execute([$user['id'], $checkType]);
    
    if ((int)$stmtCheck->fetchColumn() > 0) {
        $flash = 'Kamu masih memiliki klaim pending untuk langkah ini.'; $flashType = 'error';
    } elseif ($step === 'step2' && $user_referral_count < 10) {
        $flash = 'Kamu belum memenuhi syarat 10 referral untuk mengajukan Langkah 2.'; $flashType = 'error';
    } elseif (empty($_FILES['proof']['tmp_name'])) {
        $flash = 'Silakan pilih file bukti screenshot postingan kamu!'; $flashType = 'error';
    } else {
        $file = $_FILES['proof'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) {
            $flash = 'Format file tidak didukung! Harus JPG, PNG, atau WEBP.'; $flashType = 'error';
        } elseif ($file['size'] > 5 * 1024 * 1024) {
            $flash = 'Ukuran file terlalu besar! Maksimal 5MB.'; $flashType = 'error';
        } else {
            // Upload directory
            $dir = dirname(__DIR__) . '/uploads/threads/';
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            
            $filename = 'threads_' . $step . '_' . $user['id'] . '_' . time() . '.' . $ext;
            if (move_uploaded_file($file['tmp_name'], $dir . $filename)) {
                $proof_path = 'uploads/threads/' . $filename;
                
                $reward = $step === 'step2' ? $reward_amount2 : $reward_amount;
                // Save to admin_requests
                $payload = json_encode([
                    'proof_image' => $proof_path,
                    'reward_amount' => $reward
                ]);
                
                $pdo->prepare("INSERT INTO admin_requests (user_id, type, status, payload, created_at, updated_at) VALUES (?, ?, 'pending', ?, NOW(), NOW())")
                    ->execute([$user['id'], $checkType, $payload]);
                $request_id = $pdo->lastInsertId();
                
                // Dispatch to Telegram with approve/reject inline keyboard buttons
                $stepLabel = $step === 'step2' ? 'LANGKAH 2 (VIRAL & 10 REF)' : 'LANGKAH 1 (PROMO THREADS)';
                $tgMsg = "🌀 <b>KLAIM PROMOSI THREADS - {$stepLabel}</b>\n";
                $tgMsg .= "━━━━━━━━━━━━━━━━━━━━━━\n";
                $tgMsg .= "👤 <b>User:</b> <code>{$user['username']}</code> (ID: {$user['id']})\n";
                $tgMsg .= "💵 <b>Reward:</b> <code>" . format_rp($reward) . "</code>\n";
                if ($step === 'step2') {
                    $tgMsg .= "👥 <b>Total Referral:</b> <code>{$user_referral_count} orang</code>\n";
                }
                $tgMsg .= "📅 <b>Tanggal:</b> " . date('d M Y H:i') . "\n";
                $tgMsg .= "━━━━━━━━━━━━━━━━━━━━━━\n";
                $tgMsg .= "<i>Silakan verifikasi bukti postingan Threads di atas.</i>";
                
                $kb = [
                    [
                        ['text' => '✅ Setujui', 'callback_data' => 'req_approve_' . $request_id],
                        ['text' => '❌ Tolak', 'callback_data' => 'req_reject_' . $request_id]
                    ]
                ];
                
                // Send photo directly to Telegram
                send_telegram_photo($pdo, $dir . $filename, $tgMsg, $kb, 'permintaan');
                
                $_SESSION['flash_threads_msg'] = 'Bukti postingan berhasil di-upload! Admin akan segera memverifikasi klaim kamu.';
                $_SESSION['flash_threads_type'] = 'success';
                redirect('/threads');
            } else {
                $flash = 'Gagal menyimpan file upload. Coba lagi.'; $flashType = 'error';
            }
        }
    }
}

$pageTitle = 'Event Promosi Threads';
$activePage = 'home';
require dirname(__DIR__) . '/partials/header.php';
?>

<style>
/* THREADS THEMED BRIGHT PAGE DESIGN */
html body { background: #f97316 !important; background-image: none !important; color: #0f172a !important; }
.app-shell { background: #f97316 !important; }
.page-content { background: #f97316 !important; }

/* Pattern like withdraw page */
.threads-container {
  padding: 20px 14px calc(var(--nav-h) + 24px);
  max-width: 480px;
  margin: 0 auto;
  font-family: 'Nunito', sans-serif;
  position: relative;
  z-index: 2;
}

.threads-card {
  background: #ffffff;
  border: 2.5px solid #1e3a8a;
  border-radius: 22px;
  box-shadow: 0 5px 0 #1e3a8a;
  padding: 20px;
  margin-bottom: 20px;
  position: relative;
  overflow: hidden;
}
.threads-card::before {
  content: '🌀';
  position: absolute;
  top: -20px;
  right: -20px;
  font-size: 110px;
  opacity: 0.05;
  pointer-events: none;
  transform: rotate(20deg);
}

.threads-title {
  font-size: 20px;
  font-weight: 900;
  color: #1e3a8a;
  margin-bottom: 6px;
  display: flex;
  align-items: center;
  gap: 8px;
}
.threads-subtitle {
  font-size: 11px;
  color: #475569;
  font-weight: 700;
  margin-bottom: 16px;
}

.threads-reward-badge {
  background: rgba(34, 197, 94, 0.1);
  border: 2px solid #22c55e;
  border-radius: 16px;
  padding: 10px 14px;
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 20px;
  box-shadow: 0 3px 0 #22c55e;
}
.threads-reward-lbl {
  font-size: 11px;
  font-weight: 800;
  color: #15803d;
}
.threads-reward-val {
  font-size: 18px;
  font-weight: 900;
  color: #16a34a;
}

.threads-instructions {
  background: #f8fafc;
  border: 2px solid #cbd5e1;
  border-radius: 16px;
  padding: 14px;
  font-size: 12px;
  line-height: 1.5;
  color: #334155;
  font-weight: 700;
  white-space: pre-line;
  margin-bottom: 20px;
}

.form-group {
  margin-bottom: 16px;
}
.form-label {
  font-size: 12px;
  font-weight: 800;
  color: #1e293b;
  margin-bottom: 6px;
  display: block;
}

.threads-file-upload {
  border: 2.5px dashed #cbd5e1;
  border-radius: 16px;
  padding: 24px 16px;
  text-align: center;
  cursor: pointer;
  background: #f8fafc;
  transition: border-color 0.2s, background 0.2s;
  position: relative;
}
.threads-file-upload:hover {
  border-color: #64748b;
  background: #f1f5f9;
}
.threads-file-upload input[type="file"] {
  position: absolute;
  top: 0; left: 0; width: 100%; height: 100%;
  opacity: 0;
  cursor: pointer;
}
.threads-file-icon {
  font-size: 32px;
  color: #64748b;
  margin-bottom: 8px;
}
.threads-file-text {
  font-size: 11px;
  font-weight: 800;
  color: #64748b;
}

.btn-threads-submit {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  width: 100%;
  background: linear-gradient(135deg, #db2777, #7c3aed);
  color: #ffffff;
  border: 2.5px solid #701a75;
  border-radius: 16px;
  padding: 14px;
  font-size: 14px;
  font-weight: 900;
  font-family: 'Nunito', sans-serif;
  cursor: pointer;
  box-shadow: 0 4px 0 #701a75;
  transition: transform 0.1s, box-shadow 0.1s;
}
.btn-threads-submit:active {
  transform: translateY(3px);
  box-shadow: none;
}

.threads-status-box {
  background: #f8fafc;
  border: 2px solid #cbd5e1;
  border-radius: 20px;
  padding: 16px;
  text-align: center;
  margin-bottom: 20px;
}
.threads-status-icon {
  font-size: 40px;
  margin-bottom: 10px;
}
.threads-status-title {
  font-size: 15px;
  font-weight: 900;
  color: #0f172a;
  margin-bottom: 4px;
}
.threads-status-desc {
  font-size: 11px;
  color: #475569;
  font-weight: 700;
  line-height: 1.4;
}

.threads-preview-img {
  max-width: 100%;
  max-height: 250px;
  border-radius: 12px;
  border: 2px solid #cbd5e1;
  margin-top: 12px;
  object-fit: contain;
}

.btn-back-home {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  text-decoration: none;
  font-size: 12px;
  font-weight: 900;
  color: #ffffff;
  margin-bottom: 16px;
  text-shadow: 0 2px 4px rgba(0,0,0,0.15);
  transition: opacity 0.2s;
}
.btn-back-home:hover {
  opacity: 0.9;
}

.flash-alert {
  margin-bottom: 16px;
  padding: 12px 14px;
  border-radius: 14px;
  font-size: 12px;
  font-weight: 800;
  display: flex;
  align-items: center;
  gap: 8px;
  border: 2.5px solid;
}
.flash-alert--success {
  background: #d1fae5;
  color: #065f46;
  border-color: #6ee7b7;
}
.flash-alert--err {
  background: #fef2f2;
  color: #991b1b;
  border-color: #fca5a5;
}

/* Loader Styles */
@keyframes rotate {
  100% { transform: rotate(360deg); }
}
@keyframes dash {
  0% { stroke-dasharray: 1, 150; stroke-dashoffset: 0; }
  50% { stroke-dasharray: 90, 150; stroke-dashoffset: -35; }
  100% { stroke-dasharray: 90, 150; stroke-dashoffset: -124; }
}
.spinner-svg {
  animation: rotate 2s linear infinite;
  width: 18px;
  height: 18px;
}
.spinner-svg .path {
  stroke: #ffffff;
  stroke-linecap: round;
  animation: dash 1.5s ease-in-out infinite;
}
</style>

<div class="threads-container">
  
  <a href="/home" class="btn-back-home">
    <i class="ph-bold ph-arrow-left"></i> Kembali ke Beranda
  </a>

  <?php if ($flash): ?>
  <div class="flash-alert flash-alert--<?= $flashType==='error'?'err':'success' ?>">
    <i class="ph-bold <?= $flashType==='error'?'ph-warning-circle':'ph-check-circle' ?>"></i>
    <?= htmlspecialchars($flash) ?>
  </div>
  <?php endif; ?>

  <div class="threads-card">
    <div class="threads-title">
      🌀 Promosi Threads
    </div>
    <div class="threads-subtitle" style="margin-bottom: 20px;">Bagikan link referensimu dan raih keuntungan berlipat!</div>

    <!-- TABS CONTAINER -->
    <div class="threads-tabs" style="display: flex; background: #f1f5f9; padding: 6px; border-radius: 16px; border: 2.5px solid #1e3a8a; margin-bottom: 20px; gap: 6px;">
      <button type="button" class="tab-btn active" onclick="switchTab('step1')" style="flex: 1; border: none; padding: 10px 6px; border-radius: 12px; font-size: 12px; font-weight: 900; cursor: pointer; transition: all 0.2s; font-family: 'Nunito', sans-serif;">Langkah 1 (Rp 25.000)</button>
      <button type="button" class="tab-btn" onclick="switchTab('step2')" style="flex: 1; border: none; padding: 10px 6px; border-radius: 12px; font-size: 12px; font-weight: 900; cursor: pointer; transition: all 0.2s; font-family: 'Nunito', sans-serif;">Langkah 2 (Rp 50.000)</button>
    </div>

    <!-- STEP 1 CONTENT -->
    <div id="content-step1" class="step-content">
      <div class="threads-reward-badge">
        <span class="threads-reward-lbl">💰 REWARD SALDO TARIK</span>
        <span class="threads-reward-val"><?= format_rp($reward_amount) ?></span>
      </div>

      <!-- Status logic for Step 1 -->
      <?php if ($pendingRequest): ?>
        <?php $pData = json_decode($pendingRequest['payload'], true) ?: []; ?>
        <div class="threads-status-box">
          <div class="threads-status-icon">⏳</div>
          <div class="threads-status-title">Klaim Langkah 1 Diproses</div>
          <div class="threads-status-desc">Postingan kamu sedang diverifikasi oleh admin. Reward akan otomatis masuk jika bukti terbukti valid.</div>
          
          <?php if (!empty($pData['proof_image'])): ?>
            <img src="/<?= htmlspecialchars($pData['proof_image']) ?>" class="threads-preview-img" alt="Bukti Screenshot">
          <?php endif; ?>
        </div>
      <?php elseif ($approvedRequest): ?>
        <div class="threads-status-box" style="border-color: #22c55e; background: #f0fdf4;">
          <div class="threads-status-icon">🎉</div>
          <div class="threads-status-title" style="color: #22c55e;">Klaim Langkah 1 Disetujui!</div>
          <div class="threads-status-desc">Terima kasih telah mempromosikan LebahCuan di Threads! Reward <?= format_rp($reward_amount) ?> sudah masuk ke saldo tarik kamu.</div>
        </div>
      <?php else: ?>
        <?php if (!empty($rejectedRequest)): ?>
          <div class="flash-alert flash-alert--err" style="margin-bottom: 16px; display: block;">
            <i class="ph-bold ph-warning-circle" style="display:inline-block; vertical-align:middle; margin-right:4px;"></i>
            <span style="vertical-align:middle; font-weight:900;">Klaim Sebelumnya Ditolak:</span>
            <div style="font-size:11px; margin-top:4px; font-weight:700; color:#991b1b; white-space:pre-wrap;"><?= htmlspecialchars($rejectedRequest['admin_note'] ?: 'Bukti screenshot tidak valid atau tidak memenuhi syarat.') ?></div>
          </div>
        <?php endif; ?>

        <div class="threads-instructions">
          <?= htmlspecialchars($instructions) ?>
        </div>

        <form method="POST" enctype="multipart/form-data" class="threads-form-step1">
          <?= csrf_field() ?>
          <input type="hidden" name="step" value="step1">
          
          <div class="form-group">
            <label class="form-label">Upload Bukti Screenshot</label>
            <div class="threads-file-upload" id="upload-zone">
              <i class="ph-bold ph-image threads-file-icon" id="upload-icon"></i>
              <div class="threads-file-text" id="upload-text">Klik atau seret file gambar ke sini (Format: JPG/PNG/WEBP, Maks: 5MB)</div>
              <input type="file" name="proof" id="file-input" accept="image/*" required>
            </div>
          </div>

          <button type="submit" class="btn-threads-submit btn-submit-step1">
            <i class="ph-bold ph-paper-plane-tilt" style="font-size: 16px;"></i> Kirim Bukti Langkah 1
          </button>
        </form>
      <?php endif; ?>
    </div>

    <!-- STEP 2 CONTENT -->
    <div id="content-step2" class="step-content" style="display: none;">
      <div class="threads-reward-badge">
        <span class="threads-reward-lbl">💰 REWARD SALDO TARIK</span>
        <span class="threads-reward-val"><?= format_rp($reward_amount2) ?></span>
      </div>

      <!-- Referral Counter Panel -->
      <div style="background: #eff6ff; border: 2px solid #3b82f6; border-radius: 16px; padding: 12px 14px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; font-size: 12px; font-weight: 800; color: #1e3a8a;">
        <span>👥 TOTAL REFERRAL KAMU:</span>
        <span style="font-size: 14px; color: #2563eb; font-weight: 900;"><?= $user_referral_count ?> / 10 Orang</span>
      </div>

      <!-- Status logic for Step 2 -->
      <?php if ($user_referral_count < 10): ?>
        <div class="threads-status-box" style="border-color: #ef4444; background: #fef2f2; border-style: dashed;">
          <div class="threads-status-icon">🔒</div>
          <div class="threads-status-title" style="color: #b91c1c;">Langkah 2 Terkunci</div>
          <div class="threads-status-desc" style="color: #ef4444;">Undang minimal 10 orang bergabung ke LebahCuan untuk membuka Langkah 2. Sisa mengundang: <strong><?= 10 - $user_referral_count ?></strong> orang lagi.</div>
        </div>
      <?php elseif ($pendingRequest2): ?>
        <?php $pData2 = json_decode($pendingRequest2['payload'], true) ?: []; ?>
        <div class="threads-status-box">
          <div class="threads-status-icon">⏳</div>
          <div class="threads-status-title">Klaim Langkah 2 Diproses</div>
          <div class="threads-status-desc">Bukti postingan viral sedang diverifikasi oleh admin.</div>
          
          <?php if (!empty($pData2['proof_image'])): ?>
            <img src="/<?= htmlspecialchars($pData2['proof_image']) ?>" class="threads-preview-img" alt="Bukti Screenshot">
          <?php endif; ?>
        </div>
      <?php elseif ($approvedRequest2): ?>
        <div class="threads-status-box" style="border-color: #22c55e; background: #f0fdf4;">
          <div class="threads-status-icon">🎉</div>
          <div class="threads-status-title" style="color: #22c55e;">Klaim Langkah 2 Disetujui!</div>
          <div class="threads-status-desc">Luar biasa! Klaim Langkah 2 Anda telah disetujui. Reward <?= format_rp($reward_amount2) ?> sudah masuk ke saldo tarik kamu.</div>
        </div>
      <?php else: ?>
        <?php if (!empty($rejectedRequest2)): ?>
          <div class="flash-alert flash-alert--err" style="margin-bottom: 16px; display: block;">
            <i class="ph-bold ph-warning-circle" style="display:inline-block; vertical-align:middle; margin-right:4px;"></i>
            <span style="vertical-align:middle; font-weight:900;">Klaim Langkah 2 Ditolak:</span>
            <div style="font-size:11px; margin-top:4px; font-weight:700; color:#991b1b; white-space:pre-wrap;"><?= htmlspecialchars($rejectedRequest2['admin_note'] ?: 'Bukti screenshot tidak valid atau tidak memenuhi syarat.') ?></div>
          </div>
        <?php endif; ?>

        <div class="threads-instructions">
          <?= htmlspecialchars($instructions2) ?>
        </div>

        <form method="POST" enctype="multipart/form-data" class="threads-form-step2">
          <?= csrf_field() ?>
          <input type="hidden" name="step" value="step2">
          
          <div class="form-group">
            <label class="form-label">Upload Bukti Postingan Ramai</label>
            <div class="threads-file-upload upload-zone-step2">
              <i class="ph-bold ph-image threads-file-icon file-icon-step2"></i>
              <div class="threads-file-text file-text-step2">Klik atau seret file gambar ke sini (Format: JPG/PNG/WEBP, Maks: 5MB)</div>
              <input type="file" name="proof" class="file-input-step2" accept="image/*" required>
            </div>
          </div>

          <button type="submit" class="btn-threads-submit btn-submit-step2">
            <i class="ph-bold ph-paper-plane-tilt" style="font-size: 16px;"></i> Kirim Bukti Langkah 2
          </button>
        </form>
      <?php endif; ?>
    </div>

  </div>

</div>

<!-- Tab buttons styles -->
<style>
.tab-btn {
  background: transparent;
  color: #4b5563;
}
.tab-btn.active {
  background: linear-gradient(135deg, #db2777, #7c3aed) !important;
  color: #ffffff !important;
  box-shadow: 0 4px 10px rgba(124, 58, 237, 0.25);
}
</style>

<script>
// Switch Tab Function
function switchTab(step) {
  document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
  document.querySelectorAll('.step-content').forEach(c => c.style.display = 'none');
  
  if (step === 'step1') {
    document.querySelectorAll('.tab-btn')[0].classList.add('active');
    document.getElementById('content-step1').style.display = 'block';
  } else {
    document.querySelectorAll('.tab-btn')[1].classList.add('active');
    document.getElementById('content-step2').style.display = 'block';
  }
}

// Upload zone handling helper
function initUploadZone(inputEl, zoneEl, textEl, iconEl) {
  if (inputEl && zoneEl && textEl && iconEl) {
    inputEl.addEventListener('change', function(e) {
      if (this.files && this.files[0]) {
        const file = this.files[0];
        textEl.textContent = `Selected: ${file.name} (${(file.size / 1024 / 1024).toFixed(2)} MB)`;
        textEl.style.color = '#22c55e';
        iconEl.style.color = '#22c55e';
        zoneEl.style.borderColor = '#22c55e';
      }
    });
  }
}

// Form submit handling helper
function initFormSubmit(formEl, submitEl, inputEl, zoneEl) {
  if (formEl && submitEl && inputEl) {
    formEl.addEventListener('submit', function(e) {
      if (!inputEl.files || inputEl.files.length === 0) {
        return; // HTML5 validation
      }
      submitEl.disabled = true;
      submitEl.style.opacity = '0.7';
      submitEl.style.cursor = 'not-allowed';
      submitEl.innerHTML = `
        <svg class="spinner-svg" viewBox="0 0 50 50" style="margin-right: 8px;">
          <circle class="path" cx="25" cy="25" r="20" fill="none" stroke-width="5" style="stroke-dasharray: 1, 150; stroke-dashoffset: 0;"></circle>
        </svg>
        Mengirim Bukti...
      `;
      if (zoneEl) {
        zoneEl.style.pointerEvents = 'none';
        zoneEl.style.opacity = '0.5';
      }
    });
  }
}

// Initialize step 1
const fileInput1 = document.getElementById('file-input');
const uploadZone1 = document.getElementById('upload-zone');
const uploadText1 = document.getElementById('upload-text');
const uploadIcon1 = document.getElementById('upload-icon');
const form1 = document.querySelector('.threads-form-step1');
const submitBtn1 = document.querySelector('.btn-submit-step1');

initUploadZone(fileInput1, uploadZone1, uploadText1, uploadIcon1);
initFormSubmit(form1, submitBtn1, fileInput1, uploadZone1);

// Initialize step 2
const fileInput2 = document.querySelector('.file-input-step2');
const uploadZone2 = document.querySelector('.upload-zone-step2');
const uploadText2 = document.querySelector('.file-text-step2');
const uploadIcon2 = document.querySelector('.file-icon-step2');
const form2 = document.querySelector('.threads-form-step2');
const submitBtn2 = document.querySelector('.btn-submit-step2');

initUploadZone(fileInput2, uploadZone2, uploadText2, uploadIcon2);
initFormSubmit(form2, submitBtn2, fileInput2, uploadZone2);
</script>

<?php require dirname(__DIR__) . '/partials/footer.php'; ?>
