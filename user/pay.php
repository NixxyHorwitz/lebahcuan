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
    header('Content-Disposition: attachment; filename="QRIS-TontonCuan-dep' . $dep_id . '.png"');
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
            $flash = '✅ Bukti berhasil diupload! Admin akan memverifikasi segera.';
            
            // Telegram Notif
            $scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'] ?? 'TontonCuan.online';
            $proofUrl = $scheme . '://' . $host . '/uploads/deposits/' . $fname;
            
            $msg = "📢 <b>BUKTI DEPOSIT DIUPLOAD</b>\n";
            $msg .= "━━━━━━━━━━━━━━━━━━━━━━\n";
            $msg .= "👤 <b>User:</b> <code>" . htmlspecialchars($user['username']) . "</code>\n";
            $msg .= "💵 <b>Amount:</b> <code>" . format_rp((float)$dep['amount']) . "</code>\n";
            $msg .= "🖼️ <b>Bukti:</b> <a href=\"{$proofUrl}\">Klik untuk lihat gambar</a>\n";
            $msg .= "━━━━━━━━━━━━━━━━━━━━━━\n";
            $msg .= "<i>Silakan cek gambar bukti di atas sebelum melakukan Approve.</i>";
            
            $kb = [
                [['text'=>'✅ Approve', 'callback_data'=>'depo_approve_'.$dep_id], ['text'=>'❌ Reject', 'callback_data'=>'depo_reject_'.$dep_id]],
                [['text'=>'⚡ Acc Expired', 'callback_data'=>'depo_accexp_'.$dep_id], ['text'=>'🔄 Refresh Status', 'callback_data'=>'refresh_depo_'.$dep_id]]
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
?>
<?php
$pageTitle  = 'Bayar QRIS  ';
$activePage = 'deposit';
require dirname(__DIR__) . '/partials/header.php';
?>
<style>
/* ══════════════════════════════════════════════
   PAY PAGE — CASUAL GAME STYLE (HIGH CONTRAST)
   ══════════════════════════════════════════════ */
body { background: #f97316 !important; color: #0f172a; }
.wd-page { padding: 0 0 20px; }

/* ── TOP BANNER ── */
.wd-top {
  position: relative;
  background: linear-gradient(180deg, #3b82f6, #1d4ed8);
  padding: 16px 14px 40px;
  border-bottom: 4px solid #1e3a8a;
  z-index: 10;
}
.wd-top::before {
  content: ''; position: absolute; inset: 0;
  background-image: linear-gradient(rgba(255, 255, 255, 0.1) 2px, transparent 2px), linear-gradient(90deg, rgba(255, 255, 255, 0.1) 2px, transparent 2px);
  background-size: 30px 20px; pointer-events: none;
}
.wd-top-flex { position: relative; display: flex; justify-content: space-between; align-items: flex-start; z-index: 2; }
.wd-back {
  background: rgba(255,255,255,0.2); border: 2px solid rgba(255,255,255,0.4);
  color: #fff; width: 36px; height: 36px; border-radius: 12px;
  display: flex; align-items: center; justify-content: center; font-size: 18px; text-decoration: none;
}
.wd-notice {
  background: #fef08a; border: 2px solid #ca8a04; border-radius: 12px;
  padding: 6px 12px; box-shadow: 0 4px 0 #ca8a04;
  display: flex; gap: 6px; align-items: center; position: relative; margin-top: 4px;
}
.wd-notice::after {
  content: ''; position: absolute; right: -8px; top: 12px;
  border-width: 6px; border-style: solid; border-color: transparent transparent transparent #fef08a;
}
.wd-notice-txt { font-size: 11px; font-weight: 900; color: #854d0e; }

/* ── BODY ── */
.wd-body { flex: 1; background: #f97316; padding: 16px 14px 100px; position: relative; }
.wd-body::before {
  content: ''; position: absolute; inset: 0;
  background: radial-gradient(circle, rgba(255,255,255,0.08) 10%, transparent 10%), radial-gradient(circle, rgba(255,255,255,0.08) 10%, transparent 10%);
  background-size: 50px 50px; background-position: 0 0, 25px 25px; pointer-events: none;
}

/* ── TICKET CARD ── */
.pay-ticket { background: #ffffff; border: 3px solid #c2410c; border-radius: 20px; padding: 0; margin-bottom: 20px; box-shadow: 0 5px 0 #9a3412; position: relative; z-index: 2; overflow: hidden; }
.pay-ticket__head { background: #fef08a; padding: 14px 16px; display: flex; align-items: center; justify-content: space-between; border-bottom: 3px solid #c2410c; }
.pay-ticket__id { font-size: 14px; font-weight: 900; color: #9a3412; }
.pay-ticket__timer { background: #fee2e2; color: #dc2626; border: 2px solid #fca5a5; padding: 4px 10px; border-radius: 8px; font-weight: 900; font-size: 14px; display: flex; align-items: center; gap: 6px; box-shadow: 0 2px 0 #fca5a5; }

.pay-ticket__body { padding: 24px 16px; text-align: center; }
.qr-min { width: 220px; height: 220px; margin: 0 auto 20px; border: 4px solid #3b82f6; border-radius: 20px; padding: 12px; background: #fff; display: block; box-shadow: 0 4px 0 #1d4ed8; }
.qr-lbl { font-size: 12px; font-weight: 900; color: #9a3412; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 6px; }
.qr-val { font-size: 36px; font-weight: 900; color: #ea580c; letter-spacing: -1.5px; line-height: 1; margin-bottom: 24px; text-shadow: 0 2px 0 #ffedd5; }

/* ── BUTTONS ── */
.wd-submit-btn {
  width: 100%; background: linear-gradient(180deg, #4ade80, #16a34a); border: none; border-radius: 16px; padding: 16px;
  font-size: 16px; font-weight: 900; color: #fff; text-shadow: 0 2px 2px rgba(0,0,0,0.3);
  box-shadow: 0 6px 0 #14532d, inset 0 2px 4px rgba(255,255,255,0.5); cursor: pointer; transition: transform 0.1s, box-shadow 0.1s; position: relative; z-index: 2;
  text-decoration: none; display: flex; align-items: center; justify-content: center; gap: 8px;
}
.wd-submit-btn:active:not(:disabled) { transform: translateY(6px); box-shadow: 0 0 0 #14532d, inset 0 2px 4px rgba(255,255,255,0.5); }

.wd-btn-blue { background: linear-gradient(180deg, #3b82f6, #1d4ed8); box-shadow: 0 6px 0 #1e3a8a, inset 0 2px 4px rgba(255,255,255,0.5); }
.wd-btn-blue:active:not(:disabled) { box-shadow: 0 0 0 #1e3a8a, inset 0 2px 4px rgba(255,255,255,0.5); }

.wd-btn-yellow { background: linear-gradient(180deg, #fde047, #eab308); color: #7c2d12; text-shadow: none; box-shadow: 0 6px 0 #a16207, inset 0 2px 4px rgba(255,255,255,0.5); }
.wd-btn-yellow:active:not(:disabled) { box-shadow: 0 0 0 #a16207, inset 0 2px 4px rgba(255,255,255,0.5); }

.wd-btn-cancel { background: none; border: none; font-size: 13px; font-weight: 900; color: #fff; cursor: pointer; padding: 12px; width: 100%; text-align: center; font-family: inherit; position: relative; z-index: 2; text-decoration: underline; text-shadow: 0 2px 2px rgba(0,0,0,0.3); }
.wd-btn-cancel:disabled { color: rgba(255,255,255,0.5); cursor: not-allowed; text-decoration: none; }

.btn-grid { display: flex; gap: 12px; margin-bottom: 24px; }
.btn-grid .wd-submit-btn { padding: 14px; font-size: 14px; }

/* ── ALERTS / STEPS ── */
.wd-alert { background: #1e3a8a; color: #fff; border: 2px solid #1e40af; padding: 12px; border-radius: 14px; font-size: 12px; font-weight: 800; display: flex; gap: 8px; align-items: center; margin-bottom: 16px; box-shadow: 0 4px 0 #1e40af; position: relative; z-index: 2; }
.wd-alert-icon { font-size: 20px; flex-shrink: 0; }

.mini-steps { background: #ffffff; border: 3px solid #c2410c; border-radius: 16px; padding: 16px; margin-bottom: 20px; box-shadow: 0 5px 0 #9a3412; position: relative; z-index: 2; }
.mini-step { display: flex; align-items: flex-start; gap: 12px; margin-bottom: 12px; font-size: 12px; font-weight: 800; color: #7c2d12; line-height: 1.4; }
.mini-step:last-child { margin-bottom: 0; }
.mini-step span { width: 24px; height: 24px; background: #fef08a; border: 2px solid #ca8a04; color: #9a3412; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 900; flex-shrink: 0; box-shadow: 0 2px 0 #ca8a04;}

/* ── FILE UPLOAD ── */
.upload-card { background: #ffffff; border: 3px solid #c2410c; border-radius: 16px; padding: 16px; margin-bottom: 20px; box-shadow: 0 5px 0 #9a3412; position: relative; z-index: 2; }
.dep-file { width: 100%; background: #fffbeb; border: 3px dashed #c2410c; border-radius: 14px; padding: 12px; font-size: 11px; font-weight: 800; color: #c2410c; cursor: pointer; box-sizing: border-box; text-align: center; margin-bottom: 16px; }
.dep-file::file-selector-button { background: #fde047; border: 2px solid #ca8a04; border-radius: 8px; padding: 6px 10px; margin-right: 10px; font-weight: 900; color: #9a3412; cursor: pointer; transition: background 0.2s; box-shadow: 0 2px 0 #ca8a04; }

/* ── TOAST OVERRIDE ── */
#toast-container { position:fixed; bottom:20px; left:50%; transform:translateX(-50%); z-index:9999; display:flex; flex-direction:column; gap:8px; pointer-events:none; width:calc(100% - 32px); max-width:380px; }
.nb-toast { display:flex; align-items:center; gap:10px; padding:12px 16px; border:3px solid #1e3a8a; border-radius:14px; box-shadow:0 5px 0 #1e3a8a; font-size:13px; font-weight:900; color:#1e3a8a; pointer-events:auto; width:100%; animation:toastIn .22s cubic-bezier(.2,.8,.4,1.2) both; background: #fff; }
.nb-toast.out { animation:toastOut .18s ease forwards; }
.nb-toast--success { background:#d1fae5; border-color:#065f46; box-shadow:0 5px 0 #065f46; color:#065f46; }
.nb-toast--error   { background:#fee2e2; border-color:#991b1b; box-shadow:0 5px 0 #991b1b; color:#991b1b; }
.nb-toast--warn    { background:#fef3c7; border-color:#b45309; box-shadow:0 5px 0 #b45309; color:#b45309; }
@keyframes toastIn  { from{opacity:0;transform:translateY(12px) scale(0.9)} to{opacity:1;transform:none scale(1)} }
@keyframes toastOut { from{opacity:1} to{opacity:0;transform:translateY(6px) scale(0.95)} }
</style>

<div class="wd-page">
  <!-- TOP BANNER -->
  <div class="wd-top">
    <div class="wd-top-flex">
      <a href="/deposit" class="wd-back"><i class="ph-bold ph-caret-left"></i></a>
      <div class="wd-notice">
        <div class="wd-notice-txt">Menunggu Pembayaran</div>
      </div>
    </div>
  </div>

  <div class="wd-body">
    
    <?php if ($flash): ?>
    <div class="wd-alert" style="background:<?= $flashType==='error'?'#fee2e2':'#d1fae5' ?>; color:<?= $flashType==='error'?'#991b1b':'#065f46' ?>; border-color:<?= $flashType==='error'?'#fca5a5':'#86efac' ?>; box-shadow:0 4px 0 <?= $flashType==='error'?'#fca5a5':'#86efac' ?>;">
      <div class="wd-alert-icon"><?= $flashType==='error'?'❌':'✅' ?></div>
      <div style="flex:1"><?= htmlspecialchars($flash) ?></div>
    </div>
    <?php endif; ?>

    <?php if ($dep['status'] === 'confirmed'): ?>
    <div class="pay-ticket" style="text-align:center; padding:40px 16px;">
      <div style="font-size:72px; margin-bottom:16px;">🎉</div>
      <div style="font-size:24px; font-weight:900; color:#ea580c; margin-bottom:8px;">Pembayaran Sukses</div>
      <div style="font-size:13px; color:#7c2d12; font-weight:800; margin-bottom:32px;">Saldo belimu sudah otomatis ditambahkan.</div>
      <a href="/home" class="wd-submit-btn"><i class="ph-bold ph-house"></i> Ke Beranda</a>
    </div>

    <?php elseif ($dep['proof_image']): ?>
    <div class="pay-ticket" style="text-align:center; padding:40px 16px;">
      <div style="font-size:72px; margin-bottom:16px;">⏳</div>
      <div style="font-size:24px; font-weight:900; color:#ea580c; margin-bottom:8px;">Bukti Diterima</div>
      <div style="font-size:13px; color:#7c2d12; font-weight:800; margin-bottom:32px;">Tim kami sedang mengecek pembayaranmu.</div>
      <a href="/history" class="wd-submit-btn"><i class="ph-bold ph-clock-counter-clockwise"></i> Lihat Riwayat</a>
    </div>

    <?php else: ?>

    <!-- TICKET -->
    <div class="pay-ticket">
      <div class="pay-ticket__head" id="exp-strip">
        <span class="pay-ticket__id">#<?= $dep_id ?></span>
        <div class="pay-ticket__timer">
          <i class="ph-bold ph-stopwatch"></i> <span id="exp-timer">--:--</span>
        </div>
      </div>
      
      <div class="pay-ticket__body">
        <?php if ($qr_url): ?>
        <img id="qr-img" src="<?= htmlspecialchars($qr_url) ?>" alt="QRIS" class="qr-min">
        <div class="qr-lbl">Total Bayar</div>
        <div class="qr-val"><?= format_rp((float)$amount) ?></div>
        
        <div class="btn-grid">
          <a href="<?= htmlspecialchars($qr_dl_url) ?>" class="wd-submit-btn wd-btn-yellow"><i class="ph-bold ph-download-simple"></i> Unduh QR</a>
          <a href="<?= htmlspecialchars($qr_url) ?>" target="_blank" class="wd-submit-btn wd-btn-blue"><i class="ph-bold ph-arrow-square-out"></i> Buka QR</a>
        </div>
        <?php else: ?>
        <div class="wd-alert" style="margin-bottom:0;"><i class="ph-bold ph-warning"></i> QRIS belum dikonfigurasi. Hubungi admin.</div>
        <?php endif; ?>
      </div>
    </div>

    <div class="wd-alert" style="background:#1e3a8a; border-color:#1e40af; cursor:pointer;" onclick="if(typeof nToast==='function')nToast('Silakan chat Admin jika nominal unik bermasalah.','info')">
      <div class="wd-alert-icon">ℹ️</div>
      <div style="flex:1;">Keberatan nominal unik? Hubungi Admin.</div>
    </div>

    <!-- STEPS -->
    <div class="mini-steps">
      <div style="font-size:14px; font-weight:900; color:#9a3412; margin-bottom:16px;"><i class="ph-fill ph-clipboard-text"></i> Cara Bayar Praktis</div>
      <div class="mini-step"><span>1</span> <div>Buka aplikasi E-Wallet (OVO, Dana) atau m-Banking.</div></div>
      <div class="mini-step"><span>2</span> <div>Scan QR di atas. Nominal otomatis, mohon jangan diubah.</div></div>
      <div class="mini-step"><span>3</span> <div><?= $confirm_mode === 'manual' ? 'Selesaikan pembayaran, lalu upload bukti.' : 'Selesaikan pembayaran, saldo masuk seketika.' ?></div></div>
    </div>

    <!-- UPLOAD PROOF -->
    <?php 
    $pending_secs = time() - $created_ts;
    $show_upload = ($confirm_mode !== 'auto' || $pending_secs >= 300);
    ?>
    <div id="upload-proof-card" class="upload-card" style="display: <?= $show_upload ? 'block' : 'none' ?>;">
      <form method="POST" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="upload_proof">
        <div style="font-size:14px; font-weight:900; color:#9a3412; margin-bottom:12px;"><i class="ph-fill ph-camera"></i> Upload Struk Bayar</div>
        <input class="dep-file" type="file" name="proof" accept="image/*" required>
        <button type="submit" class="wd-submit-btn wd-btn-blue"><i class="ph-bold ph-paper-plane-tilt"></i> Kirim Bukti</button>
      </form>
    </div>

    <!-- ACTIONS -->
    <div style="display:flex; flex-direction:column; gap:8px;">
      <button id="btn-check-status" onclick="manualCheckStatus()" class="wd-submit-btn">
        <i class="ph-bold ph-arrows-clockwise"></i> Cek Status Pembayaran
      </button>
      
      <form method="POST" style="margin:0; margin-top:8px;">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="cancel_deposit">
        <button id="btn-cancel-dep" type="submit" class="wd-btn-cancel">Batalkan Deposit</button>
      </form>
    </div>

    <script>
    const DEP_ID      = <?= $dep_id ?>;
    const CSRF_TOK    = '<?= csrf_token() ?>';
    const EXPIRE_SECS = <?= $expire_secs ?>;
    let isChecking    = false;

    function toast(msg, type = 'success', duration = 3200) {
      const icons = { success:'✅', error:'❌', warn:'⚠️' };
      const c  = document.getElementById('toast-container');
      const el = document.createElement('div');
      el.className = 'nb-toast nb-toast--' + type;
      el.innerHTML = '<span class="nb-toast__icon">' + icons[type] + '</span><span class="nb-toast__msg">' + msg + '</span>';
      c.appendChild(el);
      const dismiss = () => { el.classList.add('out'); setTimeout(() => el.remove(), 200); };
      el.addEventListener('click', dismiss);
      setTimeout(dismiss, duration);
    }

    let expSecs = EXPIRE_SECS;
    const timerEl = document.getElementById('exp-timer');
    const stripEl = document.getElementById('exp-strip');

    function updateExpTimer() {
      if (expSecs <= 0) {
        if (timerEl) { timerEl.textContent = '00:00'; timerEl.parentNode.style.background = '#fef2f2'; }
        if (stripEl) { stripEl.style.background = '#fef08a'; }
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
            upCard.style.animation = 'toastIn 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275) forwards';
        }
      }

      if (expSecs <= 0) clearInterval(expTimer);
    }, 1000);

    const pollStatus = () => {
      if (isChecking) return;
      fetch('?id=' + DEP_ID, {
        method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:'_csrf=' + CSRF_TOK + '&action=check_status'
      }).then(r=>r.json()).then(d=>{ if(d.confirmed) confirmAndRedirect(); }).catch(()=>{});
    };
    const pollTimer = setInterval(pollStatus, 5000);

    function confirmAndRedirect() {
      clearInterval(pollTimer); clearInterval(expTimer);
      if (timerEl) { timerEl.textContent = 'Sukses'; timerEl.parentNode.style.background = '#d1fae5'; timerEl.parentNode.style.color = '#065f46'; timerEl.parentNode.style.borderColor = '#86efac'; timerEl.parentNode.style.boxShadow = '0 2px 0 #86efac'; }
      if (stripEl) stripEl.style.background = '#bbf7d0';
      setTimeout(()=>location.href='/history?tab=deposit', 1500);
    }

    const manualCheckStatus = () => {
      if (isChecking) return;
      isChecking = true;
      const btn  = document.getElementById('btn-check-status');
      const orig = btn.innerHTML;
      btn.disabled = true; btn.innerHTML = '<i class="ph-bold ph-spinner ph-spin"></i> Mengecek...';
      fetch('?id=' + DEP_ID, {
        method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:'_csrf=' + CSRF_TOK + '&action=check_status'
      }).then(r=>r.json()).then(d=>{
        isChecking=false; btn.disabled=false; btn.innerHTML=orig;
        if (d.confirmed) { confirmAndRedirect(); toast('Pembayaran Sukses 🎉','success'); }
        else             { toast('Pembayaran belum diterima','error'); }
      }).catch(()=>{
        isChecking=false; btn.disabled=false; btn.innerHTML=orig;
        toast('Gagal menghubungi server','warn');
      });
    };

    let cancelSecs = <?= $cancel_secs_left ?>;
    const cancelBtn = document.getElementById('btn-cancel-dep');
    if (cancelBtn && cancelSecs > 0) {
      cancelBtn.disabled=true;
      cancelBtn.textContent='Tunggu '+cancelSecs+'s untuk membatalkan';
      const ci = setInterval(()=>{
        cancelSecs--;
        cancelBtn.textContent = cancelSecs>0 ? 'Tunggu '+cancelSecs+'s untuk membatalkan' : 'Batalkan Deposit';
        if(cancelSecs<=0){ clearInterval(ci); cancelBtn.disabled=false; cancelBtn.style.textDecoration='underline'; cancelBtn.style.color='#fff'; }
      },1000);
    }
    </script>
    <?php endif; ?>
  </div>
</div>
<div id="toast-container"></div>
<?php require dirname(__DIR__) . '/partials/footer.php'; ?>
