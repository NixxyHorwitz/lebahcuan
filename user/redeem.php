<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/auth/guard.php';

$flash = $flashType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'claim';
    
    // -- AJAX Check Endpoint --
    if ($action === 'check') {
        header('Content-Type: application/json');
        $code_input = strtoupper(trim($_POST['code'] ?? ''));
        if (!$code_input) { echo json_encode(['error' => 'Masukkan kode redeem kamu dulu ya!']); exit; }
        
        $stmt = $pdo->prepare("SELECT * FROM redeem_codes WHERE code = ?");
        $stmt->execute([$code_input]);
        $codeData = $stmt->fetch();
        
        if (!$codeData) { echo json_encode(['error' => 'Kode redeem-nya gak ketemu atau udah gak valid nih.']); exit; }
        if ($codeData['expires_at'] && strtotime($codeData['expires_at']) < time()) { echo json_encode(['error' => 'Kode redeem ini udah kedaluwarsa ya.']); exit; }
        if ($codeData['max_claims'] > 0 && $codeData['claims_count'] >= $codeData['max_claims']) { echo json_encode(['error' => 'Kuota klaim buat kode redeem ini udah abis nih.']); exit; }
        if (!empty($codeData['target_users'])) {
            $allowed = array_map('trim', explode(',', $codeData['target_users']));
            if (!in_array($user['username'], $allowed, true) && !in_array($user['email'], $allowed, true)) {
                echo json_encode(['error' => 'Kode redeem ini khusus buat user tertentu aja ya.']);
                exit;
            }
        }
        
        $chk = $pdo->prepare("SELECT id FROM user_redeems WHERE user_id = ? AND code_id = ?");
        $chk->execute([$user['id'], $codeData['id']]);
        if ($chk->fetch()) { echo json_encode(['error' => 'Kamu udah pernah klaim kode ini sebelumnya.']); exit; }
        
        $msg_parts = [];
        if ($codeData['reward_wd'] > 0) $msg_parts[] = 'Saldo Penarikan: ' . format_rp((float)$codeData['reward_wd']);
        if ($codeData['reward_dep'] > 0) $msg_parts[] = 'Saldo Beli: ' . format_rp((float)$codeData['reward_dep']);
        if ($codeData['reward_level_id']) {
            $ls = $pdo->prepare("SELECT name FROM memberships WHERE id = ?");
            $ls->execute([$codeData['reward_level_id']]);
            if ($lname = $ls->fetchColumn()) $msg_parts[] = 'Level: ' . $lname;
        }
        
        echo json_encode(['ok' => true, 'details' => $msg_parts]);
        exit;
    }
    // -- End AJAX Check --

    $code_input = strtoupper(trim($_POST['code'] ?? ''));
    if (!$code_input) {
        $flash = 'Masukkan kode redeem kamu dulu ya!';
        $flashType = 'error';
    } else {
        $pdo->beginTransaction();
        
        // Cek kode exist dan valid
        $stmt = $pdo->prepare("SELECT * FROM redeem_codes WHERE code = ? FOR UPDATE");
        $stmt->execute([$code_input]);
        $codeData = $stmt->fetch();
        
        if (!$codeData) {
            $flash = 'Kode redeem-nya gak ketemu atau udah gak valid nih.';
            $flashType = 'error';
        } else {
            if ($codeData['expires_at'] && strtotime($codeData['expires_at']) < time()) {
                $flash = 'Kode redeem ini udah kedaluwarsa ya.';
                $flashType = 'error';
            } elseif ($codeData['max_claims'] > 0 && $codeData['claims_count'] >= $codeData['max_claims']) {
                $flash = 'Kuota klaim buat kode redeem ini udah abis nih.';
                $flashType = 'error';
            } elseif (!empty($codeData['target_users']) && 
                      !in_array($user['username'], array_map('trim', explode(',', $codeData['target_users'])), true) && 
                      !in_array($user['email'], array_map('trim', explode(',', $codeData['target_users'])), true)) {
                $flash = 'Kode redeem ini khusus buat user tertentu aja ya.';
                $flashType = 'error';
            } else {
                // Cek apakah user sudah pernah klaim
                $chk = $pdo->prepare("SELECT id FROM user_redeems WHERE user_id = ? AND code_id = ?");
                $chk->execute([$user['id'], $codeData['id']]);
                if ($chk->fetch()) {
                    $flash = 'Kamu udah pernah klaim kode ini sebelumnya.';
                    $flashType = 'error';
                } else {
                    // Record klaim
                    $pdo->prepare("INSERT INTO user_redeems (user_id, code_id) VALUES (?, ?)")
                        ->execute([$user['id'], $codeData['id']]);
                        
                    $pdo->prepare("UPDATE redeem_codes SET claims_count = claims_count + 1 WHERE id = ?")
                        ->execute([$codeData['id']]);
                        
                    // Berikan reward ke user
                    $r_wd  = (float)$codeData['reward_wd'];
                    $r_dep = (float)$codeData['reward_dep'];
                    $r_lvl = $codeData['reward_level_id'];
                    
                    $updateSql = "UPDATE users SET balance_wd = balance_wd + ?, balance_dep = balance_dep + ?, total_earned = total_earned + ?";
                    $updateParams = [$r_wd, $r_dep, $r_wd]; // hanya WD yang dihitung total earned (opsional, tergantung logic bisnis)
 
                    $level_name = '';
                    if ($r_lvl) {
                        // Get level duration
                        $ls = $pdo->prepare("SELECT name, duration_days FROM memberships WHERE id = ?");
                        $ls->execute([$r_lvl]);
                        $levelData = $ls->fetch();
                        if ($levelData) {
                            $level_name = $levelData['name'];
                            $days = (int)$levelData['duration_days'];
                            $updateSql .= ", membership_id = ?, membership_expires_at = DATE_ADD(NOW(), INTERVAL ? DAY)";
                            $updateParams[] = $r_lvl;
                            $updateParams[] = $days;
                        }
                    }
 
                    $updateSql .= " WHERE id = ?";
                    $updateParams[] = $user['id'];
                    
                    $pdo->prepare($updateSql)->execute($updateParams);
                        
                    // Re-fetch user to reflect changes
                    $usrStmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
                    $usrStmt->execute([$user['id']]);
                    $user = $usrStmt->fetch();
                    
                    $pdo->commit();
                    
                    $msg_parts = [];
                    if ($r_wd > 0) $msg_parts[] = 'Saldo Penarikan ' . format_rp($r_wd);
                    if ($r_dep > 0) $msg_parts[] = 'Saldo Beli ' . format_rp($r_dep);
                    if ($level_name) $msg_parts[] = 'Level ' . $level_name;
                    
                    $flash = '🎉 Hore! Kamu dapet ' . implode(', ', $msg_parts) . '. Selamat ya!';
                    $flashType = 'success';
                    goto done_redeem;
                }
            }
        }
        $pdo->rollBack();
    }
}
done_redeem:

$pageTitle  = 'Redeem Code  ';
$activePage = 'redeem';
require dirname(__DIR__) . '/partials/header.php';
?>
<style>
/* ══════════════════════════════════════════════
   REDEEM PAGE — CASUAL GAME STYLE (SETEMA WD)
   ══════════════════════════════════════════════ */
html body { background: #f97316 !important; background-image: none !important; margin: 0; padding: 0; font-family: 'Nunito', sans-serif; }

.rdm-container {
  display: flex;
  flex-direction: column;
  min-height: 100vh;
}

/* ── BLUE TOP BANNER (sama seperti WD) ── */
.wd-top {
  background: #38bdf8;
  background-image:
    linear-gradient(rgba(255,255,255,0.1) 1px, transparent 1px),
    linear-gradient(90deg, rgba(255,255,255,0.1) 1px, transparent 1px);
  background-size: 40px 20px;
  background-position: 0 0, 20px 10px;
  position: relative;
  padding: 16px 14px 48px;
  border-bottom: 3px solid #0284c7;
  overflow: hidden;
}

.wd-top-bar {
  display: flex;
  align-items: flex-start;
  gap: 10px;
}

.wd-back-btn {
  width: 32px; height: 32px;
  background: #fde047;
  border: none;
  border-radius: 10px;
  display: flex; align-items: center; justify-content: center;
  color: #ca8a04; font-size: 16px;
  box-shadow: 0 3px 0 #a16207;
  text-decoration: none;
  flex-shrink: 0;
  transition: transform 0.1s;
}
.wd-back-btn:active { transform: translateY(3px); box-shadow: 0 0 0 #a16207; }

.wd-notice-pill {
  flex: 1;
  background: #fef08a;
  border: 2.5px solid #ca8a04;
  border-radius: 20px;
  padding: 8px 12px 8px 8px;
  display: flex;
  align-items: center;
  gap: 8px;
  box-shadow: 0 4px 0 #a16207;
}
.wd-notice-icon { font-size: 20px; flex-shrink: 0; }
.wd-notice-txt { font-size: 11px; font-weight: 800; color: #854d0e; line-height: 1.2; }

/* GIF mascot floating dari hero ke body */
.rdm-gif-mascot {
  position: absolute;
  bottom: -28px;
  right: 14px;
  z-index: 10;
}
.rdm-gif-mascot img {
  width: 100px;
  height: auto;
  filter: drop-shadow(0 4px 8px rgba(0,0,0,0.3));
  display: block;
}

/* ── ORANGE BODY (sama seperti WD) ── */
.wd-body {
  flex: 1;
  background: #f97316;
  padding: 20px 14px 100px;
  position: relative;
}
.wd-body::before {
  content: '';
  position: absolute; inset: 0;
  background: radial-gradient(circle, rgba(255,255,255,0.08) 10%, transparent 10%),
              radial-gradient(circle, rgba(255,255,255,0.08) 10%, transparent 10%);
  background-size: 50px 50px;
  background-position: 0 0, 25px 25px;
  pointer-events: none;
}

/* ── SECTION HEADER ── */
.wd-section-hdr {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 12px;
  position: relative; z-index: 2;
}
.wd-sh-title { font-size: 15px; font-weight: 900; color: #7c2d12; }

/* ── INPUT CODE (style bento seperti wallet row) ── */
.rdm-code-box {
  background: #fffbeb;
  border: 2.5px solid #b45309;
  border-radius: 20px;
  padding: 14px;
  margin-bottom: 20px;
  position: relative; z-index: 2;
  box-shadow: 0 4px 0 #b45309;
}
.rdm-code-input {
  width: 100%;
  background: #fff;
  border: 2px solid #fde68a;
  border-radius: 14px;
  padding: 14px;
  font-size: 20px; font-weight: 900;
  color: #7c2d12;
  font-family: 'Nunito', sans-serif;
  text-transform: uppercase;
  letter-spacing: 4px;
  text-align: center;
  outline: none;
  transition: border-color 0.2s;
  box-shadow: inset 0 2px 6px rgba(0,0,0,0.05);
  margin-bottom: 12px;
}
.rdm-code-input:focus { border-color: #f97316; }
.rdm-code-input::placeholder { color: #fcd34d; font-weight: 800; letter-spacing: 2px; }

/* ── SUBMIT BUTTON (persis sama kayak wd-submit-btn) ── */
.wd-submit-btn {
  width: 100%;
  background: linear-gradient(180deg, #f8fafc, #e2e8f0);
  border: 3px solid #cbd5e1;
  border-radius: 30px;
  padding: 16px;
  font-size: 18px;
  font-weight: 900;
  color: #15803d;
  text-shadow: 0 1px 0 #fff;
  box-shadow: 0 6px 0 #94a3b8, inset 0 2px 4px rgba(255,255,255,1);
  cursor: pointer;
  transition: transform 0.1s;
  position: relative;
  z-index: 2;
}
.wd-submit-btn::before {
  content:''; position:absolute; top:4px; left:50%; transform:translateX(-50%);
  width: 90%; height: 8px; background: rgba(255,255,255,0.8); border-radius:10px;
}
.wd-submit-btn:active { transform: translateY(4px); box-shadow: 0 2px 0 #94a3b8; }
.wd-submit-btn:disabled { background: #cbd5e1; border-color:#94a3b8; color:#334155; box-shadow:none; transform:none; }

/* ── INFO BOX (bento krem) ── */
.rdm-info-box {
  background: #fffbeb;
  border: 2.5px solid #b45309;
  border-radius: 20px;
  padding: 14px;
  position: relative; z-index: 2;
  box-shadow: 0 4px 0 #b45309;
}
.rdm-info-title {
  font-size: 13px; font-weight: 900;
  color: #7c2d12;
  margin-bottom: 10px;
  display: flex; align-items: center; gap: 6px;
}
.rdm-info-title i { font-size: 18px; color: #b45309; }
.rdm-info-list { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 6px; }
.rdm-info-list li {
  display: flex; align-items: flex-start; gap: 8px;
  font-size: 11px; font-weight: 800;
  color: #78350f;
  line-height: 1.5;
  background: rgba(253,230,138,0.4);
  border-radius: 10px;
  padding: 7px 10px;
  border: 1.5px solid rgba(202,138,4,0.25);
}
.rdm-info-list li i { color: #d97706; font-size: 14px; flex-shrink: 0; margin-top: 1px; }

/* ── FLASH NOTICE ── */
.wd-notice-pill.flash-pill {
  margin-bottom: 0;
  flex: 1;
}

/* ── MODAL (persis seperti WD cg-modal) ── */
.cg-modal { display:none; position:fixed; inset:0; background:rgba(0,0,0,0.6); z-index:99999; align-items:center; justify-content:center; padding:16px; backdrop-filter:blur(3px); }
.cg-modal-box { background:#fffbeb; width:100%; max-width:320px; border-radius:24px; border:3px solid #b45309; box-shadow:0 8px 0 #7c2d12; overflow:hidden; animation:popIn 0.3s cubic-bezier(0.175,0.885,0.32,1.275); }
.cg-modal-hdr { background:linear-gradient(135deg, #fde047, #f59e0b); padding:14px; text-align:center; color:#7c2d12; font-weight:900; font-size:14px; border-bottom:2.5px solid #d97706; }
.cg-modal-bd { padding:20px; }
.cg-modal-list { list-style:none; margin:0 0 14px; padding:0; display:flex; flex-direction:column; gap:6px; }
.cg-modal-list li { background:#fef08a; border:1.5px solid #d97706; border-radius:10px; padding:8px 12px; font-size:13px; font-weight:900; color:#7c2d12; }
.cg-modal-actions { display:flex; gap:10px; }
.cg-btn-cancel { flex:1; padding:12px; background:#f1f5f9; border:2.5px solid #cbd5e1; border-radius:12px; font-weight:900; color:#64748b; font-size:12px; box-shadow:0 4px 0 #94a3b8; cursor:pointer; }
.cg-btn-confirm { flex:1.5; padding:12px; background:#4ade80; border:2.5px solid #166534; border-radius:12px; font-weight:900; color:#fff; box-shadow:0 4px 0 #166534; font-size:12px; cursor:pointer; }
.cg-btn-confirm:active { transform:translateY(3px); box-shadow:0 1px 0 #166534; }
.cg-btn-cancel:active { transform:translateY(3px); box-shadow:0 1px 0 #94a3b8; }
@keyframes popIn { from{transform:scale(0.8);opacity:0;} to{transform:scale(1);opacity:1;} }
</style>

<div class="rdm-container">
  <!-- TOP BANNER (persis seperti WD) -->
  <div class="wd-top">
    <div class="wd-top-bar">
      <a href="/home" class="wd-back-btn"><i class="ph-bold ph-arrow-left"></i></a>

      <?php if ($flash): ?>
        <div class="wd-notice-pill" style="<?= $flashType==='error' ? 'background:#fef2f2; border-color:#dc2626; box-shadow:0 4px 0 #991b1b;' : 'background:#f0fdf4; border-color:#16a34a; box-shadow:0 4px 0 #14532d;' ?>">
          <div class="wd-notice-icon"><?= $flashType==='error' ? '❌' : '🎉' ?></div>
          <div class="wd-notice-txt" style="<?= $flashType==='error' ? 'color:#7f1d1d' : 'color:#14532d' ?>"><?= htmlspecialchars($flash) ?></div>
        </div>
      <?php else: ?>
        <div class="wd-notice-pill">
          <div class="wd-notice-icon">🎁</div>
          <div class="wd-notice-txt">Tukarkan kode hadiah dan dapatkan reward melimpah!</div>
        </div>
      <?php endif; ?>
    </div>

    <!-- GIF Mascot floating di pojok -->
    <div class="rdm-gif-mascot">
      <img src="/assets/ccg.gif" alt="Buaya Kado">
    </div>
  </div>

  <!-- ORANGE BODY -->
  <div class="wd-body">
    <!-- SECTION HEADER -->
    <div class="wd-section-hdr">
      <div class="wd-sh-title">Masukkan Kode</div>
    </div>

    <!-- INPUT CODE BOX -->
    <div class="rdm-code-box">
      <form id="form-claim" method="POST" onsubmit="checkRedeem(event)">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="claim">
        <input class="rdm-code-input" type="text" name="code" placeholder="KODE HADIAH" autocomplete="off" required>
        <button type="submit" id="btn-check" class="wd-submit-btn">Cek &amp; Klaim</button>
      </form>
    </div>

    <!-- INFO BOX -->
    <div class="rdm-info-box">
      <div class="rdm-info-title"><i class="ph-fill ph-lightbulb"></i> Info Kode Redeem</div>
      <ul class="rdm-info-list">
        <li><i class="ph-bold ph-check-circle"></i> Kode tidak case-sensitive, huruf besar/kecil otomatis disesuaikan.</li>
        <li><i class="ph-bold ph-check-circle"></i> Satu akun hanya bisa klaim setiap kode sebanyak <strong>1x</strong> saja.</li>
        <li><i class="ph-bold ph-check-circle"></i> Reward bisa berupa <strong>Saldo WD, Saldo Beli, atau Level Membership</strong>.</li>
      </ul>
    </div>
  </div>
</div>

<!-- MODAL KONFIRMASI (sama persis seperti WD) -->
<div class="cg-modal" id="brutal-confirm">
  <div class="cg-modal-box">
    <div class="cg-modal-hdr">🎉 Konfirmasi Klaim Hadiah</div>
    <div class="cg-modal-bd">
      <div style="font-size:12px;font-weight:800;color:#7c2d12;margin-bottom:10px;text-align:center;">Kode ini berisi reward berikut:</div>
      <ul class="cg-modal-list" id="brutal-confirm-list"></ul>
      <div style="font-size:11px;font-weight:800;color:#9a3412;text-align:center;margin-bottom:14px;">Yakin ingin klaim sekarang?</div>
      <div class="cg-modal-actions">
        <div onclick="document.getElementById('brutal-confirm').style.display='none'" class="cg-btn-cancel">Batal</div>
        <div onclick="confirmBrutalClaim()" class="cg-btn-confirm">Klaim Sekarang!</div>
      </div>
    </div>
  </div>
</div>

<script>
let tempForm = null;

function confirmBrutalClaim() {
  if (tempForm) { tempForm.onsubmit = null; tempForm.submit(); }
}

function checkRedeem(e) {
  e.preventDefault();
  const form = document.getElementById('form-claim');
  const code = form.querySelector('input[name="code"]').value;
  const btn  = document.getElementById('btn-check');
  btn.disabled = true;
  btn.textContent = 'Mengecek...';

  fetch('', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: 'action=check&code=' + encodeURIComponent(code) + '&_csrf=' + encodeURIComponent(form.querySelector('input[name="_csrf"]')?.value || '')
  })
  .then(r => r.json())
  .then(res => {
    btn.disabled = false;
    btn.textContent = 'Cek & Klaim';
    if (res.error) {
      if (typeof nToast !== 'undefined') nToast(res.error, 'error');
    } else {
      const list = document.getElementById('brutal-confirm-list');
      list.innerHTML = res.details.map(d => `<li>${d}</li>`).join('');
      tempForm = form;
      document.getElementById('brutal-confirm').style.display = 'flex';
    }
  })
  .catch(() => {
    btn.disabled = false;
    btn.textContent = 'Cek & Klaim';
    if (typeof nToast !== 'undefined') nToast('Terjadi kesalahan jaringan.', 'error');
  });
}
</script>

<?php require dirname(__DIR__) . '/partials/footer.php'; ?>