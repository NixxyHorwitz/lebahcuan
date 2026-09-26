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
   PROFILE PAGE — DARK FOREST PREMIUM REDESIGN
   ══════════════════════════════════════════════ */
body {
  background: #071a0c !important;
  color: #e2e8f0;
}

/* ── HERO BANNER ── */
.prof-hero {
  position: relative;
  background: linear-gradient(160deg, #0c2e15 0%, #132d14 40%, #1a3a1d 100%);
  padding: 28px 20px 60px;
  overflow: hidden;
}
.prof-hero::before {
  content: '';
  position: absolute;
  inset: 0;
  background:
    radial-gradient(circle at 20% 80%, rgba(251,191,36,0.08) 0%, transparent 50%),
    radial-gradient(circle at 80% 20%, rgba(34,197,94,0.06) 0%, transparent 50%);
  pointer-events: none;
}
.prof-hero::after {
  content: '';
  position: absolute;
  bottom: -1px;
  left: 0;
  right: 0;
  height: 30px;
  background: #071a0c;
  clip-path: ellipse(55% 100% at 50% 100%);
}

/* Floating hexagons */
.prof-hex-deco {
  position: absolute;
  background: rgba(251,191,36,0.06);
  clip-path: polygon(50% 0%, 100% 25%, 100% 75%, 50% 100%, 0% 75%, 0% 25%);
  pointer-events: none;
}
.prof-hex-deco:nth-child(1) { top: 12px; right: 20px; width: 28px; height: 28px; animation: hexFloat 6s ease-in-out infinite; }
.prof-hex-deco:nth-child(2) { top: 50px; right: 55px; width: 18px; height: 18px; animation: hexFloat 8s ease-in-out infinite 1s; opacity: 0.6; }
.prof-hex-deco:nth-child(3) { bottom: 40px; left: 15px; width: 22px; height: 22px; animation: hexFloat 7s ease-in-out infinite 2s; opacity: 0.5; }
@keyframes hexFloat {
  0%, 100% { transform: translateY(0) rotate(0deg); }
  50% { transform: translateY(-8px) rotate(12deg); }
}

/* Avatar */
.prof-ava-wrap {
  position: relative; z-index: 2;
  display: flex; flex-direction: column; align-items: center;
  margin-bottom: 14px;
}
.prof-ava-ring {
  width: 82px; height: 82px;
  border-radius: 50%;
  background: conic-gradient(from 0deg, #f59e0b, #22c55e, #f59e0b);
  padding: 3px;
  animation: avaRingSpin 6s linear infinite;
  box-shadow: 0 0 20px rgba(251,191,36,0.2);
}
@keyframes avaRingSpin {
  0% { filter: hue-rotate(0deg); }
  100% { filter: hue-rotate(360deg); }
}
.prof-ava {
  width: 100%; height: 100%;
  border-radius: 50%;
  background: linear-gradient(135deg, #1a3a1d, #0f2a16);
  display: flex; align-items: center; justify-content: center;
  font-size: 30px; font-weight: 900; color: #fbbf24;
  text-shadow: 0 0 12px rgba(251,191,36,0.4);
}
.prof-name {
  font-size: 20px; font-weight: 900; color: #fef3c7;
  text-align: center; text-shadow: 0 1px 6px rgba(0,0,0,0.4);
  margin-bottom: 3px; position: relative; z-index: 2;
}
.prof-email {
  font-size: 11px; font-weight: 700; color: rgba(254,243,199,0.5);
  text-align: center; margin-bottom: 10px; position: relative; z-index: 2;
}
.prof-tier-badge {
  display: inline-flex; align-items: center; gap: 5px;
  background: rgba(251,191,36,0.12);
  border: 1.5px solid rgba(251,191,36,0.3);
  backdrop-filter: blur(8px); border-radius: 20px;
  padding: 5px 14px; font-size: 10px; font-weight: 900; color: #fbbf24;
  text-transform: uppercase; letter-spacing: 0.5px; position: relative; z-index: 2;
}
.prof-tier-badge.premium {
  background: linear-gradient(135deg, rgba(251,191,36,0.25), rgba(217,119,6,0.2));
  border-color: rgba(251,191,36,0.5);
  box-shadow: 0 0 12px rgba(251,191,36,0.15);
}

/* ── BODY ── */
.prof-body {
  padding: 0 14px 120px; margin-top: -24px;
  position: relative; z-index: 5;
}

/* ── STATS ── */
.prof-stats {
  display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px;
  margin-bottom: 16px;
}
.prof-stat-card {
  background: rgba(255,255,255,0.04);
  border: 1.5px solid rgba(255,255,255,0.08);
  border-radius: 14px; padding: 12px 8px; text-align: center;
  backdrop-filter: blur(6px);
  transition: transform 0.2s, border-color 0.2s;
}
.prof-stat-card:hover { transform: translateY(-2px); border-color: rgba(251,191,36,0.2); }
.prof-stat-val { font-size: 14px; font-weight: 900; color: #fbbf24; line-height: 1.2; margin-bottom: 3px; }
.prof-stat-val.green { color: #4ade80; }
.prof-stat-lbl { font-size: 9px; font-weight: 800; color: rgba(255,255,255,0.4); text-transform: uppercase; letter-spacing: 0.3px; }

/* ── REFERRAL ── */
.prof-ref-strip {
  display: flex; align-items: center; justify-content: space-between;
  background: rgba(251,191,36,0.06);
  border: 1.5px solid rgba(251,191,36,0.15);
  border-radius: 14px; padding: 12px 14px; margin-bottom: 16px;
  backdrop-filter: blur(6px);
}
.prof-ref-label { font-size: 9px; font-weight: 800; color: rgba(255,255,255,0.4); text-transform: uppercase; margin-bottom: 2px; }
.prof-ref-code { font-size: 15px; font-weight: 900; color: #fbbf24; letter-spacing: 1px; font-family: 'JetBrains Mono', monospace; }
.prof-ref-btn {
  background: linear-gradient(135deg, #f59e0b, #d97706);
  border: 1.5px solid rgba(120,53,15,0.5);
  border-radius: 10px; padding: 8px 14px;
  font-size: 11px; font-weight: 900; color: #fff;
  cursor: pointer; flex-shrink: 0;
  display: flex; align-items: center; gap: 4px;
  transition: transform 0.1s, box-shadow 0.1s;
  box-shadow: 0 3px 0 rgba(120,53,15,0.5);
  font-family: inherit;
}
.prof-ref-btn:active { transform: translateY(2px); box-shadow: none; }

/* ── NAV GRID ── */
.prof-nav-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-bottom: 16px; }
.prof-nav-item {
  display: flex; align-items: center; justify-content: center; gap: 8px;
  padding: 14px 10px; border-radius: 14px;
  font-size: 12px; font-weight: 800; text-decoration: none;
  border: 1.5px solid; transition: transform 0.15s, box-shadow 0.15s;
  backdrop-filter: blur(4px);
}
.prof-nav-item:active { transform: translateY(2px); box-shadow: none !important; }
.prof-nav-item i { font-size: 18px; }
.prof-nav-item.n-rek { background: rgba(34,197,94,0.1); border-color: rgba(34,197,94,0.25); color: #4ade80; box-shadow: 0 3px 0 rgba(34,197,94,0.2); }
.prof-nav-item.n-upg { background: rgba(251,191,36,0.1); border-color: rgba(251,191,36,0.25); color: #fbbf24; box-shadow: 0 3px 0 rgba(251,191,36,0.2); }
.prof-nav-item.n-riw { background: rgba(251,146,60,0.1); border-color: rgba(251,146,60,0.25); color: #fb923c; box-shadow: 0 3px 0 rgba(251,146,60,0.2); }
.prof-nav-item.n-pan { background: rgba(96,165,250,0.1); border-color: rgba(96,165,250,0.25); color: #60a5fa; box-shadow: 0 3px 0 rgba(96,165,250,0.2); }

/* ── ACCORDION ── */
.prof-settings {
  background: rgba(255,255,255,0.03);
  border: 1.5px solid rgba(255,255,255,0.06);
  border-radius: 16px; overflow: hidden; margin-bottom: 16px;
  backdrop-filter: blur(6px);
}
.prof-acc-hdr {
  display: flex; align-items: center; gap: 10px;
  padding: 14px; cursor: pointer; user-select: none;
  border-bottom: 1px solid rgba(255,255,255,0.04);
  transition: background 0.2s;
}
.prof-acc-hdr:hover { background: rgba(255,255,255,0.02); }
.prof-acc-hdr.open { background: rgba(251,191,36,0.05); border-bottom-color: rgba(251,191,36,0.1); }
.prof-acc-hdr .acc-icon {
  width: 32px; height: 32px; border-radius: 10px;
  display: flex; align-items: center; justify-content: center;
  font-size: 15px; flex-shrink: 0;
}
.prof-acc-hdr .acc-icon.icon-info { background: rgba(96,165,250,0.12); color: #60a5fa; }
.prof-acc-hdr .acc-icon.icon-edit { background: rgba(251,191,36,0.12); color: #fbbf24; }
.prof-acc-hdr .acc-icon.icon-lock { background: rgba(248,113,113,0.12); color: #f87171; }
.prof-acc-hdr .acc-title { flex: 1; font-size: 12px; font-weight: 800; color: #e2e8f0; }
.prof-acc-hdr .acc-caret { font-size: 12px; color: rgba(255,255,255,0.3); transition: transform 0.3s; }
.prof-acc-hdr.open .acc-caret { transform: rotate(180deg); color: #fbbf24; }

.prof-acc-body {
  max-height: 0; overflow: hidden;
  transition: max-height 0.35s cubic-bezier(0.4, 0, 0.2, 1), padding 0.35s;
  padding: 0 14px; background: rgba(0,0,0,0.15);
}
.prof-acc-body.open { max-height: 320px; padding: 14px; }

/* Forms */
.prof-lbl { font-size: 10px; font-weight: 800; color: rgba(255,255,255,0.4); text-transform: uppercase; margin-bottom: 5px; display: block; letter-spacing: 0.3px; }
.prof-input {
  width: 100%; background: rgba(255,255,255,0.06);
  border: 1.5px solid rgba(255,255,255,0.1);
  border-radius: 10px; padding: 10px 12px;
  font-size: 13px; font-weight: 700; color: #e2e8f0;
  margin-bottom: 10px; outline: none; box-sizing: border-box;
  transition: border-color 0.2s, box-shadow 0.2s; font-family: inherit;
}
.prof-input:focus { border-color: rgba(251,191,36,0.4); box-shadow: 0 0 0 3px rgba(251,191,36,0.1); }
.prof-input:disabled { background: rgba(255,255,255,0.02); color: rgba(255,255,255,0.35); border-color: rgba(255,255,255,0.05); cursor: not-allowed; }
.prof-submit {
  width: 100%; background: linear-gradient(135deg, #f59e0b, #d97706);
  border: none; border-radius: 10px; padding: 11px;
  font-size: 12px; font-weight: 900; color: #fff; cursor: pointer;
  transition: transform 0.1s, box-shadow 0.1s;
  box-shadow: 0 3px 0 rgba(120,53,15,0.5);
  display: flex; align-items: center; justify-content: center; gap: 6px; font-family: inherit;
}
.prof-submit:active { transform: translateY(2px); box-shadow: none; }

/* ── CONTACT ── */
.prof-contact-row { display: flex; gap: 8px; justify-content: center; margin-bottom: 16px; flex-wrap: wrap; }
.prof-contact-btn {
  width: 44px; height: 44px; border-radius: 12px;
  display: flex; align-items: center; justify-content: center;
  border: 1.5px solid; transition: transform 0.15s;
  text-decoration: none; color: #fff; backdrop-filter: blur(4px);
}
.prof-contact-btn:active { transform: scale(0.92); }

/* ── LOGOUT ── */
.prof-logout {
  display: flex; align-items: center; justify-content: center; gap: 8px;
  background: rgba(239,68,68,0.08);
  border: 1.5px solid rgba(239,68,68,0.2);
  border-radius: 14px; padding: 13px;
  font-size: 13px; font-weight: 800; color: #f87171;
  text-decoration: none; transition: background 0.2s, transform 0.1s; margin-top: 8px;
}
.prof-logout:active { transform: translateY(2px); }
.prof-logout:hover { background: rgba(239,68,68,0.12); }

/* ── FLASH ── */
.prof-flash {
  display: flex; align-items: center; gap: 8px;
  padding: 10px 14px; border-radius: 12px;
  font-size: 12px; font-weight: 800; margin-bottom: 14px;
  animation: flashSlide 0.3s ease-out;
}
@keyframes flashSlide {
  from { opacity: 0; transform: translateY(-8px); }
  to { opacity: 1; transform: translateY(0); }
}
.prof-flash--success { background: rgba(34,197,94,0.1); border: 1.5px solid rgba(34,197,94,0.2); color: #4ade80; }
.prof-flash--error { background: rgba(239,68,68,0.1); border: 1.5px solid rgba(239,68,68,0.2); color: #f87171; }
</style>

<!-- HERO BANNER -->
<div class="prof-hero">
  <div class="prof-hex-deco"></div>
  <div class="prof-hex-deco"></div>
  <div class="prof-hex-deco"></div>
  
  <div class="prof-ava-wrap">
    <div class="prof-ava-ring">
      <div class="prof-ava"><?= strtoupper(substr($user['username'], 0, 1)) ?></div>
    </div>
  </div>
  
  <div class="prof-name"><?= htmlspecialchars($user['username']) ?></div>
  <div class="prof-email"><?= htmlspecialchars($user['email']) ?></div>
  <div style="text-align:center;position:relative;z-index:2">
    <span class="prof-tier-badge <?= $is_premium ? 'premium' : '' ?>">
      <?= $is_premium ? '★ '.$membership_name : $membership_name ?>
      <?= $user['membership_expires_at'] ? ' • '.date('d/m/y', strtotime($user['membership_expires_at'])) : '' ?>
    </span>
  </div>
</div>

<div class="prof-body">
  <?php if ($flash): ?>
  <div class="prof-flash prof-flash--<?= $flashType === 'error' ? 'error' : 'success' ?>">
    <i class="ph-bold ph-<?= $flashType === 'error' ? 'warning-circle' : 'check-circle' ?>" style="font-size:16px;"></i>
    <?= htmlspecialchars($flash) ?>
  </div>
  <?php endif; ?>

  <!-- STATS -->
  <div class="prof-stats">
    <div class="prof-stat-card">
      <div class="prof-stat-val green"><?= format_rp((float)$user['total_earned']) ?></div>
      <div class="prof-stat-lbl">Total Earned</div>
    </div>
    <div class="prof-stat-card">
      <div class="prof-stat-val"><?= number_format($total_watches) ?></div>
      <div class="prof-stat-lbl">Ditonton</div>
    </div>
    <div class="prof-stat-card">
      <div class="prof-stat-val"><?= $refs ?></div>
      <div class="prof-stat-lbl">Referral</div>
    </div>
  </div>

  <!-- REFERRAL -->
  <div class="prof-ref-strip">
    <div>
      <div class="prof-ref-label">Kode Referral</div>
      <div class="prof-ref-code" id="ref-code"><?= htmlspecialchars($user['referral_code']) ?></div>
    </div>
    <button class="prof-ref-btn" onclick="copyRef()"><i class="ph-bold ph-copy"></i> Salin</button>
  </div>

  <!-- NAV GRID -->
  <div class="prof-nav-grid">
    <a href="/edit-rekening" class="prof-nav-item n-rek">
      <i class="ph-bold ph-bank"></i> <span>Rekening</span>
    </a>
    <a href="/upgrade" class="prof-nav-item n-upg">
      <i class="ph-bold ph-rocket-launch"></i> <span>Upgrade</span>
    </a>
    <a href="/history" class="prof-nav-item n-riw">
      <i class="ph-bold ph-receipt"></i> <span>Riwayat</span>
    </a>
    <a href="/panduan" class="prof-nav-item n-pan">
      <i class="ph-bold ph-book-open"></i> <span>Panduan</span>
    </a>
  </div>

  <!-- SETTINGS -->
  <div class="prof-settings">
    <div class="prof-acc-hdr" onclick="toggleAcc('info')" id="h-info">
      <div class="acc-icon icon-info"><i class="ph-bold ph-identification-card"></i></div>
      <span class="acc-title">Info Akun</span>
      <i class="acc-caret ph-bold ph-caret-down" id="c-info"></i>
    </div>
    <div class="prof-acc-body" id="b-info">
      <label class="prof-lbl">WhatsApp</label>
      <input class="prof-input" value="<?= htmlspecialchars(mask_account($user['whatsapp'] ?? '')) ?>" disabled>
      <label class="prof-lbl">Bank Terdaftar</label>
      <input class="prof-input" value="<?= $user['bank_name'] ? htmlspecialchars($user['bank_name'] . ' - ' . mask_account($user['account_number'] ?? '')) : 'Belum Ada' ?>" disabled>
    </div>

    <div class="prof-acc-hdr <?= $active_section === 'edit' ? 'open' : '' ?>" onclick="toggleAcc('edit')" id="h-edit">
      <div class="acc-icon icon-edit"><i class="ph-bold ph-pencil-simple"></i></div>
      <span class="acc-title">Ubah Username</span>
      <i class="acc-caret ph-bold ph-caret-down" id="c-edit"></i>
    </div>
    <div class="prof-acc-body <?= $active_section === 'edit' ? 'open' : '' ?>" id="b-edit">
      <form method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="update_profile">
        <label class="prof-lbl">Username Baru (Huruf/Angka/_)</label>
        <input class="prof-input" type="text" name="username" value="<?= htmlspecialchars($user['username']) ?>" required minlength="3">
        <button class="prof-submit"><i class="ph-bold ph-floppy-disk"></i> Simpan Username</button>
      </form>
    </div>

    <div class="prof-acc-hdr <?= $active_section === 'password' ? 'open' : '' ?>" onclick="toggleAcc('password')" id="h-password">
      <div class="acc-icon icon-lock"><i class="ph-bold ph-lock-key"></i></div>
      <span class="acc-title">Ganti Password</span>
      <i class="acc-caret ph-bold ph-caret-down" id="c-password"></i>
    </div>
    <div class="prof-acc-body <?= $active_section === 'password' ? 'open' : '' ?>" id="b-password">
      <form method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="change_password">
        <label class="prof-lbl">Password Lama</label>
        <input class="prof-input" type="password" name="old_password" required minlength="6">
        <label class="prof-lbl">Password Baru</label>
        <input class="prof-input" type="password" name="new_password" required minlength="6">
        <button class="prof-submit"><i class="ph-bold ph-key"></i> Update Password</button>
      </form>
    </div>
  </div>

  <!-- CONTACT -->
  <div class="prof-contact-row">
    <?php foreach ($_contact_btns as $cb): ?>
      <?php
        $t = strtolower($cb['icon_value']);
        $svg = $_psvg[$t] ?? $_psvg['cs'];
        $c = match($t) {
            'wa' => 'background:rgba(34,197,94,0.15);border-color:rgba(34,197,94,0.3);',
            'tele' => 'background:rgba(96,165,250,0.15);border-color:rgba(96,165,250,0.3);',
            'ig' => 'background:rgba(244,63,94,0.15);border-color:rgba(244,63,94,0.3);',
            'fb' => 'background:rgba(59,130,246,0.15);border-color:rgba(59,130,246,0.3);',
            default => 'background:rgba(148,163,184,0.15);border-color:rgba(148,163,184,0.3);'
        };
      ?>
      <a href="<?= htmlspecialchars($cb['url']) ?>" class="prof-contact-btn" target="_blank" style="<?= $c ?>">
        <?= $svg ?>
      </a>
    <?php endforeach; ?>
  </div>

  <!-- LOGOUT -->
  <a href="/logout" class="prof-logout">
    <i class="ph-bold ph-sign-out"></i> Keluar
  </a>
</div>

<script>
function toggleAcc(id) {
  const b = document.getElementById('b-' + id);
  const h = document.getElementById('h-' + id);
  const isOpen = b.classList.contains('open');

  // Close all
  document.querySelectorAll('.prof-acc-body').forEach(el => el.classList.remove('open'));
  document.querySelectorAll('.prof-acc-hdr').forEach(el => el.classList.remove('open'));

  if (!isOpen) {
    b.classList.add('open');
    h.classList.add('open');
  }
}

function copyRef() {
  const txt = document.getElementById('ref-code').innerText.trim();
  if (typeof nToast !== 'undefined' && nToast.copy) {
    nToast.copy(txt, 'Kode Referral');
  } else {
    navigator.clipboard.writeText(txt).then(() => {
      if (typeof nToast === 'function') nToast('🐝 Kode Referral disalin: ' + txt, 'success');
    });
  }
}
</script>
<?php require dirname(__DIR__) . '/partials/footer.php'; ?>
