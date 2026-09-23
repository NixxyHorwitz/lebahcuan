<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/auth/guard.php';
$flash = $flashType = '';
$active_section = 'main'; // default

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'update_profile') {
        $username = trim($_POST['username'] ?? '');
        if (strlen($username) < 3) { $flash = 'Username minimal 3 karakter.'; $flashType = 'error'; }
        elseif (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) { $flash = 'Username hanya boleh huruf, angka, underscore.'; $flashType = 'error'; }
        else {
            $ex = $pdo->prepare("SELECT id FROM users WHERE username=? AND id!=?");
            $ex->execute([$username, $user['id']]);
            if ($ex->fetch()) { $flash = 'Username sudah digunakan.'; $flashType = 'error'; }
            else {
                $pdo->prepare("UPDATE users SET username=? WHERE id=?")->execute([$username, $user['id']]);
                $flash = '✅ Username berhasil diperbarui!';
            }
        }
        $active_section = 'edit';
    }
    if ($action === 'change_password') {
        $old = $_POST['old_password'] ?? '';
        $new = $_POST['new_password'] ?? '';
        if (!password_verify($old, $user['password_hash'])) { $flash = 'Password lama salah.'; $flashType = 'error'; }
        elseif (strlen($new) < 6) { $flash = 'Password baru minimal 6 karakter.'; $flashType = 'error'; }
        else {
            $pdo->prepare("UPDATE users SET password_hash=? WHERE id=?")->execute([password_hash($new, PASSWORD_BCRYPT), $user['id']]);
            $flash = '✅ Password berhasil diubah!';
        }
        $active_section = 'password';
    }
    $ru = $pdo->prepare("SELECT * FROM users WHERE id=?"); $ru->execute([$user['id']]); $user = $ru->fetch();
}

// Stats
$st = $pdo->prepare("SELECT COUNT(*) FROM watch_history WHERE user_id=?"); $st->execute([$user['id']]); $total_watches = (int)$st->fetchColumn();
$refs = $pdo->prepare("SELECT COUNT(*) FROM users WHERE referred_by=?"); $refs->execute([$user['referral_code']]); $refs = (int)$refs->fetchColumn();

// Membership
$membership_name = '';
$membership_allow_edit_bank = false;
$is_premium = false;

if ($user['membership_id'] && $user['membership_expires_at'] && strtotime((string)$user['membership_expires_at']) > time()) {
    $ms = $pdo->prepare("SELECT name, allow_edit_bank FROM memberships WHERE id=?"); $ms->execute([$user['membership_id']]);
    $ms = $ms->fetch();
    $membership_name = $ms['name'] ?? '';
    $membership_allow_edit_bank = (bool)($ms['allow_edit_bank'] ?? false);
    $is_premium = true;
}

if (!$membership_name) {
    $ms_free = $pdo->query("SELECT name, allow_edit_bank FROM memberships WHERE price=0 AND is_active=1 ORDER BY sort_order ASC LIMIT 1")->fetch();
    $membership_name = $ms_free['name'] ?? 'Free';
    $membership_allow_edit_bank = (bool)($ms_free['allow_edit_bank'] ?? false);
    $is_premium = false;
}

$edit_bank_min_dep = (int)($user['edit_bank_deposit_min'] ?? 50000);
$dep_ok_for_edit   = (float)$user['balance_dep'] >= $edit_bank_min_dep;
$is_promotor_prof  = ((int)($user['is_promotor'] ?? 0) === 1);
$show_edit_rek_btn = $membership_allow_edit_bank || $is_promotor_prof;

// Contact buttons
try {
    $_contact_btns = $pdo->query("SELECT * FROM contact_buttons WHERE is_active=1 ORDER BY sort_order ASC, id ASC")->fetchAll();
} catch (\Throwable) { $_contact_btns = []; }

$pageTitle  = 'Profil';
$activePage = 'profile';
require dirname(__DIR__) . '/partials/header.php';

$_psvg = [
  'wa'   => '<svg viewBox="0 0 24 24" fill="currentColor" width="18" height="18"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347z"/><path d="M12 0C5.373 0 0 5.373 0 12c0 2.123.554 4.118 1.528 5.847L.057 23.883a.5.5 0 00.61.61l6.037-1.472A11.944 11.944 0 0012 24c6.627 0 12-5.373 12-12S18.627 0 12 0zm0 22c-1.89 0-3.655-.518-5.17-1.42l-.37-.22-3.823.933.954-3.722-.242-.383A9.958 9.958 0 012 12C2 6.477 6.477 2 12 2s10 4.477 10 10-4.477 10-10 10z"/></svg>',
  'tele' => '<svg viewBox="0 0 24 24" fill="currentColor" width="18" height="18"><path d="M11.944 0A12 12 0 000 12a12 12 0 0012 12 12 12 0 0012-12A12 12 0 0012 0a12 12 0 00-.056 0zm4.962 7.224c.1-.002.321.023.465.14a.506.506 0 01.171.325c.016.093.036.306.02.472-.18 1.898-.962 6.502-1.36 8.627-.168.9-.499 1.201-.82 1.23-.696.065-1.225-.46-1.9-.902-1.056-.693-1.653-1.124-2.678-1.8-1.185-.78-.417-1.21.258-1.91.177-.184 3.247-2.977 3.307-3.23.007-.032.014-.15-.056-.212s-.174-.041-.249-.024c-.106.024-1.793 1.14-5.061 3.345-.48.33-.913.49-1.302.48-.428-.008-1.252-.241-1.865-.44-.752-.245-1.349-.374-1.297-.789.027-.216.325-.437.893-.663 3.498-1.524 5.83-2.529 6.998-3.014 3.332-1.386 4.025-1.627 4.476-1.635z"/></svg>',
  'cs'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg>',
  'ig'   => '<svg viewBox="0 0 24 24" fill="currentColor" width="18" height="18"><path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zm0-2.163c-3.259 0-3.667.014-4.947.072-4.358.2-6.78 2.618-6.98 6.98-.059 1.281-.073 1.689-.073 4.948 0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98 1.281.058 1.689.072 4.948.072 3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98-1.281-.059-1.69-.073-4.949-.073zm0 5.838a6.162 6.162 0 100 12.324 6.162 6.162 0 000-12.324zM12 16a4 4 0 110-8 4 4 0 010 8zm6.406-11.845a1.44 1.44 0 100 2.881 1.44 1.44 0 000-2.881z"/></svg>',
  'fb'   => '<svg viewBox="0 0 24 24" fill="currentColor" width="18" height="18"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>',
];
?>

<style>
/* ══════════════════════════════════════════════
   PROFILE PAGE — AMBER HONEY THEME
   ══════════════════════════════════════════════ */
body { 
  background-color: #fef8ee !important; 
  background-image: radial-gradient(rgba(217, 119, 6, 0.08) 1.5px, transparent 1.5px) !important;
  background-size: 16px 16px !important;
  color: #78350f; 
}

/* ── TOP BANNER (HERO) ── */
.wd-top { 
  position: relative; 
  background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); 
  padding: 18px 14px 24px; 
  border-bottom: 3.5px solid #78350f; 
  z-index: 10; 
  display: flex; 
  align-items: center; 
  gap: 14px; 
  box-shadow: 0 4px 0 #78350f;
}
.wd-top::before { 
  content: ''; 
  position: absolute; 
  inset: 0; 
  background-image: radial-gradient(rgba(255, 255, 255, 0.15) 1.5px, transparent 1.5px); 
  background-size: 16px 16px; 
  pointer-events: none; 
}
.prof-ava { 
  width: 54px; height: 54px; 
  background: #fde68a; 
  border: 3px solid #78350f; 
  border-radius: 16px; 
  display: flex; align-items: center; justify-content: center; 
  font-size: 24px; font-weight: 900; color: #78350f; 
  box-shadow: 0 4px 0 #78350f; 
  position: relative; z-index: 2; flex-shrink: 0; 
}
.prof-info { flex: 1; min-width: 0; position: relative; z-index: 2; }
.prof-name { font-size: 17px; font-weight: 900; color: #fff; text-shadow: 0 2px 0 #78350f; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; margin-bottom: 2px; }
.prof-email { font-size: 11px; font-weight: 800; color: #fef3c7; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; margin-bottom: 6px; }
.prof-tier { display: inline-block; font-size: 10px; font-weight: 900; padding: 3px 8px; background: #78350f; color: #fde68a; border-radius: 8px; border: 1.5px solid #fff; box-shadow: 0 2px 0 rgba(0,0,0,0.2); text-transform: uppercase; }

/* ── BODY ── */
.wd-body { flex: 1; background: transparent; padding: 20px 14px 100px; position: relative; z-index: 2; }

/* ── STATS ROW ── */
.stat-row { display: flex; gap: 8px; margin-bottom: 16px; position: relative; z-index: 5; }
.stat-box { flex: 1; background: #ffffff; border: 2.5px solid #78350f; border-radius: 14px; padding: 10px 4px; text-align: center; box-shadow: 0 4px 0 #78350f; }
.stat-val { font-size: 13px; font-weight: 900; line-height: 1.2; color: #78350f; }
.stat-val.blue { color: #d97706; }
.stat-lbl { font-size: 9.5px; font-weight: 900; color: #92400e; margin-top: 3px; text-transform: uppercase; }

/* ── SHARE STRIP ── */
.ref-strip { display: flex; align-items: center; justify-content: space-between; background: linear-gradient(135deg, #fffbeb, #fef3c7); border: 2.5px solid #78350f; border-radius: 14px; padding: 10px 12px; box-shadow: 0 4px 0 #78350f; margin-bottom: 16px; }
.ref-lbl { font-size: 9.5px; font-weight: 900; color: #92400e; text-transform: uppercase; margin-bottom: 2px; }
.ref-code { font-size: 14px; font-weight: 900; color: #78350f; letter-spacing: 0.5px; }
.ref-btn { background: #f59e0b; border: 2px solid #78350f; border-radius: 10px; font-size: 11px; font-weight: 900; color: #78350f; padding: 8px 14px; box-shadow: 0 3px 0 #78350f; cursor: pointer; flex-shrink: 0; }
.ref-btn:active { transform: translateY(2px); box-shadow: 0 1px 0 #78350f; }

/* ── GRID NAV ── */
.p-nav-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-bottom: 16px; }
.p-nav-item { display: flex; align-items: center; justify-content: center; gap: 6px; padding: 12px 10px; border-radius: 14px; font-size: 12px; font-weight: 900; color: #78350f; text-decoration: none; border: 2.5px solid #78350f; box-shadow: 0 4px 0 #78350f; transition: transform 0.1s; }
.p-nav-item:active { transform: translateY(3px); box-shadow: 0 1px 0 #78350f; }
.p-nav-item i { font-size: 18px; }
.p-nav-item.n-rek { background: #a7f3d0; border-color: #065f46; box-shadow: 0 4px 0 #065f46; color: #065f46; }
.p-nav-item.n-upg { background: #fde68a; border-color: #78350f; box-shadow: 0 4px 0 #78350f; color: #78350f; }
.p-nav-item.n-riw { background: #fed7aa; border-color: #78350f; box-shadow: 0 4px 0 #78350f; color: #78350f; }
.p-nav-item.n-pan { background: #fef08a; border-color: #78350f; box-shadow: 0 4px 0 #78350f; color: #78350f; }

/* ── COMPACT ACCORDIONS ── */
.c-group { background: #ffffff; border: 2.5px solid #78350f; border-radius: 16px; box-shadow: 0 4px 0 #78350f; overflow: hidden; margin-bottom: 16px; }
.c-hdr { display: flex; align-items: center; gap: 8px; padding: 12px; background: #fffbeb; cursor: pointer; border-bottom: 2px solid transparent; user-select: none; }
.c-hdr.open { border-bottom-color: #fde68a; background: #fef3c7; }
.c-hdr i.icon { font-size: 16px; color: #d97706; width: 24px; text-align: center; }
.c-hdr span { flex: 1; font-size: 11px; font-weight: 900; color: #78350f; text-transform: uppercase; }
.c-hdr i.caret { font-size: 12px; color: #d97706; transition: transform 0.2s; }
.c-hdr.open i.caret { transform: rotate(180deg); }
.c-body { display: none; padding: 12px; background: #fff; }
.c-body.open { display: block; }

/* Forms inside compact */
.c-lbl { font-size: 10px; font-weight: 900; color: #78350f; margin-bottom: 4px; display: block; }
.c-input { width: 100%; background: #ffffff; border: 2px solid #fde68a; border-radius: 10px; padding: 8px 10px; font-size: 12px; font-weight: 800; color: #78350f; margin-bottom: 8px; outline: none; box-sizing: border-box; }
.c-input:focus { border-color: #d97706; box-shadow: 0 0 0 3px rgba(217, 119, 6, 0.2); }
.c-input:disabled { background: #f8fafc; color: #64748b; border-color: #e2e8f0; cursor: not-allowed; }
.c-btn { width: 100%; background: #f59e0b; border: 2px solid #78350f; border-radius: 10px; padding: 10px; font-size: 12px; font-weight: 900; color: #78350f; box-shadow: 0 3px 0 #78350f; cursor: pointer; transition: transform 0.1s; }
.c-btn:active { transform: translateY(2px); box-shadow: 0 1px 0 #78350f; }

/* ── CONTACT ROW ── */
.contact-row { display: flex; gap: 8px; overflow-x: auto; padding-bottom: 4px; margin-bottom: 16px; justify-content: center; }
.contact-row::-webkit-scrollbar { display: none; }
.contact-btn { flex-shrink: 0; width: 44px; height: 44px; border-radius: 12px; display: flex; align-items: center; justify-content: center; border: 2.5px solid #78350f; box-shadow: 0 3px 0 #78350f; transition: transform 0.1s; text-decoration: none; color: #78350f; background: #fff; }
.contact-btn:active { transform: translateY(2px); box-shadow: 0 1px 0 #78350f; }

/* ── LOGOUT ── */
.logout-btn { display: flex; align-items: center; justify-content: center; gap: 6px; background: #fee2e2; border: 2.5px solid #dc2626; border-radius: 14px; padding: 12px; font-size: 13px; font-weight: 900; color: #dc2626; text-decoration: none; box-shadow: 0 4px 0 #dc2626; transition: transform 0.1s; margin-top: 10px; }
.logout-btn:active { transform: translateY(3px); box-shadow: 0 1px 0 #dc2626; }

#toast { position: fixed; bottom: 20px; left: 50%; transform: translateX(-50%); background: #10b981; color: #fff; padding: 8px 16px; border-radius: 10px; font-size: 11px; font-weight: 900; border: 2px solid #065f46; box-shadow: 0 3px 0 #065f46; display: none; z-index: 100; }
</style>

<!-- TOP BANNER (HERO) -->
<div class="wd-top">
  <div class="prof-ava"><?= strtoupper(substr($user['username'], 0, 1)) ?></div>
  <div class="prof-info">
    <div class="prof-name"><?= htmlspecialchars($user['username']) ?></div>
    <div class="prof-email"><?= htmlspecialchars($user['email']) ?></div>
    <div class="prof-tier">
      <?= $is_premium ? '★ '.$membership_name : $membership_name ?>
      <?= $user['membership_expires_at'] ? ' • '.date('d/m/y', strtotime($user['membership_expires_at'])) : '' ?>
    </div>
  </div>
</div>

<div class="wd-body">
  <?php if ($flash): ?>
  <div class="prof-flash prof-flash--<?= $flashType === 'error' ? 'error' : 'success' ?>">
    <i class="ph-bold ph-<?= $flashType === 'error' ? 'warning-circle' : 'check-circle' ?>" style="font-size:16px;"></i>
    <?= htmlspecialchars($flash) ?>
  </div>
  <?php endif; ?>

  <!-- STATS ROW -->
  <div class="stat-row">
    <div class="stat-box">
      <div class="stat-val blue"><?= format_rp((float)$user['total_earned']) ?></div>
      <div class="stat-lbl">Earned</div>
    </div>
    <div class="stat-box">
      <div class="stat-val"><?= number_format($total_watches) ?></div>
      <div class="stat-lbl">Ditonton</div>
    </div>
    <div class="stat-box">
      <div class="stat-val"><?= $refs ?></div>
      <div class="stat-lbl">Referral</div>
    </div>
  </div>

  <!-- SHARE STRIP -->
  <div class="ref-strip">
    <div>
      <div class="ref-lbl">Kode Referral</div>
      <div class="ref-code" id="ref-code"><?= htmlspecialchars($user['referral_code']) ?></div>
    </div>
    <button class="ref-btn" onclick="copyRef()"><i class="ph-bold ph-copy"></i> Salin</button>
  </div>

  <!-- GRID NAV -->
  <div class="p-nav-grid">
    <a href="/edit-rekening" class="p-nav-item n-rek">
      <i class="ph-bold ph-bank"></i> <span>Rekening</span>
    </a>
    <a href="/upgrade" class="p-nav-item n-upg">
      <i class="ph-bold ph-rocket-launch"></i> <span>Upgrade</span>
    </a>
    <a href="/history" class="p-nav-item n-riw">
      <i class="ph-bold ph-receipt"></i> <span>Riwayat</span>
    </a>
    <a href="/panduan" class="p-nav-item n-pan">
      <i class="ph-bold ph-book-open"></i> <span>Panduan</span>
    </a>
  </div>

  <!-- SETTINGS ACCORDION -->
  <div class="c-group">
    <div class="c-hdr" onclick="t('info')" id="h-info">
      <i class="icon ph-bold ph-identification-card"></i> <span>Info Akun</span> <i class="caret ph-bold ph-caret-down" id="c-info"></i>
    </div>
    <div class="c-body" id="b-info">
      <div class="c-lbl">WhatsApp</div>
      <input class="c-input" value="<?= htmlspecialchars(mask_account($user['whatsapp'] ?? '')) ?>" disabled>
      <div class="c-lbl">Bank Terdaftar</div>
      <input class="c-input" value="<?= $user['bank_name'] ? htmlspecialchars($user['bank_name'] . ' - ' . mask_account($user['account_number'] ?? '')) : 'Belum Ada' ?>" disabled>
    </div>

    <div class="c-hdr <?= $active_section === 'edit' ? 'open' : '' ?>" onclick="t('edit')" id="h-edit">
      <i class="icon ph-bold ph-pencil-simple"></i> <span>Ubah Username</span> <i class="caret ph-bold ph-caret-down <?= $active_section === 'edit' ? 'open' : '' ?>" id="c-edit"></i>
    </div>
    <div class="c-body <?= $active_section === 'edit' ? 'open' : '' ?>" id="b-edit">
      <form method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="update_profile">
        <div class="c-lbl">Username Baru (Huruf/Angka/_)</div>
        <input class="c-input" type="text" name="username" value="<?= htmlspecialchars($user['username']) ?>" required minlength="3">
        <button class="c-btn"><i class="ph-bold ph-floppy-disk"></i> Simpan Username</button>
      </form>
    </div>

    <div class="c-hdr <?= $active_section === 'password' ? 'open' : '' ?>" onclick="t('password')" id="h-password">
      <i class="icon ph-bold ph-lock-key"></i> <span>Ganti Password</span> <i class="caret ph-bold ph-caret-down <?= $active_section === 'password' ? 'open' : '' ?>" id="c-password"></i>
    </div>
    <div class="c-body <?= $active_section === 'password' ? 'open' : '' ?>" id="b-password">
      <form method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="change_password">
        <div class="c-lbl">Password Lama</div>
        <input class="c-input" type="password" name="old_password" required minlength="6">
        <div class="c-lbl">Password Baru</div>
        <input class="c-input" type="password" name="new_password" required minlength="6">
        <button class="c-btn"><i class="ph-bold ph-key"></i> Update Password</button>
      </form>
    </div>
  </div>

  <!-- CONTACT ROW -->
  <div class="contact-row">
    <?php foreach ($_contact_btns as $cb): ?>
      <?php
        $t = strtolower($cb['icon_value']);
        $svg = $_psvg[$t] ?? $_psvg['cs'];
        $c = match($t) {
            'wa' => 'background:linear-gradient(135deg, #4ade80, #16a34a);border-color:#14532d;box-shadow:0 3px 0 #14532d;',
            'tele' => 'background:linear-gradient(135deg, #60a5fa, #2563eb);border-color:#1e3a8a;box-shadow:0 3px 0 #1e3a8a;',
            'ig' => 'background:linear-gradient(135deg, #f43f5e, #be123c);border-color:#881337;box-shadow:0 3px 0 #881337;',
            'fb' => 'background:linear-gradient(135deg, #3b82f6, #1d4ed8);border-color:#1e3a8a;box-shadow:0 3px 0 #1e3a8a;',
            default => 'background:linear-gradient(135deg, #94a3b8, #475569);border-color:#1e293b;box-shadow:0 3px 0 #1e293b;'
        };
      ?>
      <a href="<?= htmlspecialchars($cb['url']) ?>" class="contact-btn" target="_blank" style="<?= $c ?>">
        <?= $svg ?>
      </a>
    <?php endforeach; ?>
  </div>

  <!-- LOGOUT -->
  <a href="/logout" class="logout-btn">
    <i class="ph-bold ph-sign-out"></i> Keluar
  </a>

</div>

<div id="toast">✅ Tersalin!</div>
<script src="/assets/js/toast.js"></script>
<script>
function t(id) {
  let b = document.getElementById('b-'+id);
  let h = document.getElementById('h-'+id);
  let c = document.getElementById('c-'+id);
  let o = b.classList.contains('open');
  // close all
  document.querySelectorAll('.c-body, .c-hdr, .caret').forEach(e => e.classList.remove('open'));
  if(!o) {
    b.classList.add('open');
    h.classList.add('open');
    c.classList.add('open');
  }
}
function copyRef() {
  let txt = document.getElementById('ref-code').innerText;
  navigator.clipboard.writeText(txt).then(()=>{
    let t = document.getElementById('toast');
    t.style.display='block';
    setTimeout(()=>t.style.display='none', 2000);
  });
}
</script>
<?php require dirname(__DIR__) . '/partials/footer.php'; ?>
