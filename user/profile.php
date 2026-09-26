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

// Additional stats
$total_deposits = 0; $total_withdrawals = 0; $total_hives = 0; $total_bees = 0;
try { $s = $pdo->prepare("SELECT COUNT(*) FROM deposits WHERE user_id=? AND status='approved'"); $s->execute([$user['id']]); $total_deposits = (int)$s->fetchColumn(); } catch (\Throwable) {}
try { $s = $pdo->prepare("SELECT COUNT(*) FROM withdrawals WHERE user_id=? AND status='approved'"); $s->execute([$user['id']]); $total_withdrawals = (int)$s->fetchColumn(); } catch (\Throwable) {}
try { $s = $pdo->prepare("SELECT COUNT(*) FROM user_bee_hives WHERE user_id=?"); $s->execute([$user['id']]); $total_hives = (int)$s->fetchColumn(); } catch (\Throwable) {}
try { $s = $pdo->prepare("SELECT COUNT(*) FROM user_bees WHERE user_id=?"); $s->execute([$user['id']]); $total_bees = (int)$s->fetchColumn(); } catch (\Throwable) {}

// Active investments
$active_investments = 0;
try { $s = $pdo->prepare("SELECT COUNT(*) FROM user_investments WHERE user_id=? AND status='active'"); $s->execute([$user['id']]); $active_investments = (int)$s->fetchColumn(); } catch (\Throwable) {}

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

// Member since
$member_since = date('d M Y', strtotime($user['created_at']));
$days_member = max(1, (int)((time() - strtotime($user['created_at'])) / 86400));

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
   PROFILE — AMBER HONEY THEME (RICH & DECORATED)
   ══════════════════════════════════════════════ */
body {
  background-color: #fef8ee !important;
  background-image: radial-gradient(rgba(217, 119, 6, 0.08) 1.5px, transparent 1.5px) !important;
  background-size: 16px 16px !important;
  color: #78350f;
}

/* ── HERO ── */
.prof-hero {
  position: relative;
  background: linear-gradient(135deg, #f59e0b 0%, #d97706 50%, #b45309 100%);
  padding: 22px 14px 56px;
  border-bottom: 3.5px solid #78350f;
  box-shadow: 0 4px 0 #78350f;
  overflow: hidden;
}
.prof-hero::before {
  content: '';
  position: absolute; inset: 0;
  background-image: radial-gradient(rgba(255,255,255,0.15) 1.5px, transparent 1.5px);
  background-size: 16px 16px;
  pointer-events: none;
}
.prof-hero::after {
  content: '';
  position: absolute;
  bottom: -1px; left: 0; right: 0; height: 24px;
  background: #fef8ee;
  clip-path: ellipse(55% 100% at 50% 100%);
}

/* Honeycomb decoration */
.hex-deco {
  position: absolute;
  background: rgba(255,255,255,0.08);
  clip-path: polygon(50% 0%, 100% 25%, 100% 75%, 50% 100%, 0% 75%, 0% 25%);
  pointer-events: none;
}
.hex-deco:nth-child(1) { top: 8px; right: 16px; width: 32px; height: 32px; animation: hexBob 5s ease-in-out infinite; }
.hex-deco:nth-child(2) { top: 44px; right: 52px; width: 20px; height: 20px; animation: hexBob 7s ease-in-out infinite 1s; }
.hex-deco:nth-child(3) { bottom: 32px; left: 12px; width: 26px; height: 26px; animation: hexBob 6s ease-in-out infinite 0.5s; }
.hex-deco:nth-child(4) { top: 18px; left: 40px; width: 16px; height: 16px; animation: hexBob 8s ease-in-out infinite 2s; opacity: 0.6; }
.hex-deco:nth-child(5) { bottom: 36px; right: 30px; width: 14px; height: 14px; animation: hexBob 6.5s ease-in-out infinite 1.5s; opacity: 0.5; }
@keyframes hexBob {
  0%,100% { transform: translateY(0) rotate(0deg); }
  50% { transform: translateY(-6px) rotate(15deg); }
}

/* Floating bee decorations */
.fly-bee {
  position: absolute; font-size: 14px; pointer-events: none;
  animation: beeFly 12s ease-in-out infinite;
}
.fly-bee:nth-child(6) { top: 20%; left: -10%; animation-delay: 0s; }
.fly-bee:nth-child(7) { top: 50%; left: -10%; animation-delay: 4s; font-size: 11px; }
.fly-bee:nth-child(8) { top: 70%; left: -10%; animation-delay: 8s; font-size: 16px; }
@keyframes beeFly {
  0% { transform: translateX(-20px); opacity: 0; }
  10% { opacity: 1; }
  90% { opacity: 1; }
  100% { transform: translateX(calc(100vw + 40px)) translateY(-20px); opacity: 0; }
}

/* Avatar */
.prof-ava-wrap {
  position: relative; z-index: 2;
  display: flex; flex-direction: column; align-items: center;
  margin-bottom: 10px;
}
.prof-ava-ring {
  width: 76px; height: 76px;
  border-radius: 50%;
  background: conic-gradient(from 0deg, #fde68a, #78350f, #fbbf24, #fde68a);
  padding: 3.5px;
  animation: ringPulse 3s ease-in-out infinite;
  box-shadow: 0 4px 0 #78350f, 0 0 16px rgba(251,191,36,0.3);
}
@keyframes ringPulse {
  0%,100% { box-shadow: 0 4px 0 #78350f, 0 0 16px rgba(251,191,36,0.2); }
  50% { box-shadow: 0 4px 0 #78350f, 0 0 24px rgba(251,191,36,0.5); }
}
.prof-ava {
  width: 100%; height: 100%;
  border-radius: 50%;
  background: linear-gradient(135deg, #fde68a, #fbbf24);
  border: 2.5px solid #78350f;
  display: flex; align-items: center; justify-content: center;
  font-size: 28px; font-weight: 900; color: #78350f;
  text-shadow: 0 1px 0 rgba(255,255,255,0.4);
}
.prof-name {
  font-size: 18px; font-weight: 900; color: #fff;
  text-shadow: 0 2px 0 #78350f;
  text-align: center; margin-bottom: 2px;
  position: relative; z-index: 2;
}
.prof-email {
  font-size: 10.5px; font-weight: 700; color: #fef3c7;
  text-align: center; margin-bottom: 8px;
  position: relative; z-index: 2;
}
.prof-tier {
  display: inline-flex; align-items: center; gap: 4px;
  background: #78350f; color: #fde68a;
  border: 1.5px solid #fff; border-radius: 8px;
  padding: 3px 10px; font-size: 9.5px; font-weight: 900;
  text-transform: uppercase; box-shadow: 0 2px 0 rgba(0,0,0,0.2);
  position: relative; z-index: 2;
}
.prof-tier.prem { background: linear-gradient(135deg, #78350f, #92400e); border-color: #fde68a; box-shadow: 0 2px 0 rgba(0,0,0,0.3), 0 0 10px rgba(251,191,36,0.3); }

.prof-member-since {
  text-align: center; font-size: 9px; font-weight: 800;
  color: rgba(255,255,255,0.6); margin-top: 6px;
  position: relative; z-index: 2;
}

/* ── BODY ── */
.prof-body { padding: 0 14px 120px; margin-top: -16px; position: relative; z-index: 5; }

/* ── FLASH ── */
.prof-flash {
  display: flex; align-items: center; gap: 8px;
  padding: 10px 12px; border-radius: 12px;
  font-size: 11.5px; font-weight: 800; margin-bottom: 12px;
  border: 2px solid; animation: flashPop 0.3s ease-out;
}
@keyframes flashPop { from { opacity:0; transform: translateY(-6px); } to { opacity:1; transform: translateY(0); } }
.prof-flash--success { background: #ecfdf5; border-color: #10b981; color: #065f46; }
.prof-flash--error { background: #fef2f2; border-color: #ef4444; color: #b91c1c; }

/* ── BALANCE CARDS ── */
.prof-balance-row {
  display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-bottom: 12px;
}
.prof-bal-card {
  background: #fff; border: 2.5px solid #78350f; border-radius: 14px;
  padding: 12px 10px; text-align: center; box-shadow: 0 4px 0 #78350f;
  position: relative; overflow: hidden;
}
.prof-bal-card::before {
  content: ''; position: absolute; top: -8px; right: -8px;
  width: 32px; height: 32px; opacity: 0.06;
  background: #f59e0b;
  clip-path: polygon(50% 0%, 100% 25%, 100% 75%, 50% 100%, 0% 75%, 0% 25%);
}
.prof-bal-card.gold { background: linear-gradient(135deg, #fffbeb, #fef3c7); }
.prof-bal-card.honey { background: linear-gradient(135deg, #fef3c7, #fde68a); }
.prof-bal-icon { font-size: 18px; margin-bottom: 4px; }
.prof-bal-val { font-size: 13px; font-weight: 900; color: #78350f; line-height: 1.2; }
.prof-bal-lbl { font-size: 8.5px; font-weight: 900; color: #92400e; text-transform: uppercase; margin-top: 2px; }

/* ── STATS GRID ── */
.prof-stats {
  display: grid; grid-template-columns: repeat(3, 1fr); gap: 6px; margin-bottom: 14px;
}
.prof-stat {
  background: #fff; border: 2px solid #fde68a; border-radius: 12px;
  padding: 10px 4px; text-align: center; transition: transform 0.15s;
}
.prof-stat:hover { transform: translateY(-2px); }
.prof-stat-emoji { font-size: 16px; margin-bottom: 2px; }
.prof-stat-val { font-size: 13px; font-weight: 900; color: #d97706; }
.prof-stat-lbl { font-size: 8px; font-weight: 900; color: #92400e; text-transform: uppercase; }

/* ── REFERRAL ── */
.prof-ref {
  display: flex; align-items: center; justify-content: space-between;
  background: linear-gradient(135deg, #fffbeb, #fef3c7);
  border: 2.5px solid #78350f; border-radius: 14px;
  padding: 10px 12px; box-shadow: 0 4px 0 #78350f; margin-bottom: 14px;
}
.prof-ref-lbl { font-size: 9px; font-weight: 900; color: #92400e; text-transform: uppercase; margin-bottom: 1px; }
.prof-ref-code { font-size: 15px; font-weight: 900; color: #78350f; letter-spacing: 0.5px; }
.prof-ref-btn {
  background: #f59e0b; border: 2px solid #78350f; border-radius: 10px;
  font-size: 10.5px; font-weight: 900; color: #78350f;
  padding: 8px 12px; box-shadow: 0 3px 0 #78350f; cursor: pointer; flex-shrink: 0;
  display: flex; align-items: center; gap: 4px; font-family: inherit;
  transition: transform 0.1s;
}
.prof-ref-btn:active { transform: translateY(2px); box-shadow: 0 1px 0 #78350f; }

/* ── NAV GRID ── */
.prof-nav-title {
  font-size: 10px; font-weight: 900; color: #92400e; text-transform: uppercase;
  margin-bottom: 8px; display: flex; align-items: center; gap: 6px;
}
.prof-nav-title::after { content: ''; flex: 1; height: 2px; background: #fde68a; border-radius: 2px; }
.prof-nav { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; margin-bottom: 14px; }
.prof-nav-item {
  display: flex; flex-direction: column; align-items: center; gap: 4px;
  padding: 12px 6px; border-radius: 14px; font-size: 10px; font-weight: 900;
  text-decoration: none; border: 2.5px solid #78350f;
  box-shadow: 0 4px 0 #78350f; transition: transform 0.1s;
}
.prof-nav-item:active { transform: translateY(3px); box-shadow: 0 1px 0 #78350f; }
.prof-nav-item i { font-size: 20px; }
.pn-rek { background: #a7f3d0; color: #065f46; border-color: #065f46; box-shadow: 0 4px 0 #065f46; }
.pn-upg { background: #fde68a; color: #78350f; }
.pn-riw { background: #fed7aa; color: #78350f; }
.pn-pan { background: #fef08a; color: #78350f; }
.pn-farm { background: #bbf7d0; color: #166534; border-color: #166534; box-shadow: 0 4px 0 #166534; }
.pn-vid { background: #e0e7ff; color: #3730a3; border-color: #3730a3; box-shadow: 0 4px 0 #3730a3; }
.pn-misi { background: #fce7f3; color: #9d174d; border-color: #9d174d; box-shadow: 0 4px 0 #9d174d; }
.pn-plinko { background: #e0f2fe; color: #0369a1; border-color: #0369a1; box-shadow: 0 4px 0 #0369a1; }
.pn-inv { background: #fef3c7; color: #92400e; border-color: #92400e; box-shadow: 0 4px 0 #92400e; }

/* ── ACCORDION ── */
.prof-group {
  background: #fff; border: 2.5px solid #78350f; border-radius: 16px;
  box-shadow: 0 4px 0 #78350f; overflow: hidden; margin-bottom: 14px;
}
.prof-acc-hdr {
  display: flex; align-items: center; gap: 8px;
  padding: 12px; background: #fffbeb; cursor: pointer; user-select: none;
  border-bottom: 2px solid transparent; transition: background 0.15s;
}
.prof-acc-hdr.open { border-bottom-color: #fde68a; background: #fef3c7; }
.prof-acc-hdr .icon { font-size: 16px; color: #d97706; width: 24px; text-align: center; }
.prof-acc-hdr .title { flex: 1; font-size: 11px; font-weight: 900; color: #78350f; text-transform: uppercase; }
.prof-acc-hdr .caret { font-size: 11px; color: #d97706; transition: transform 0.25s; }
.prof-acc-hdr.open .caret { transform: rotate(180deg); }
.prof-acc-body {
  max-height: 0; overflow: hidden; background: #fff;
  transition: max-height 0.3s cubic-bezier(0.4,0,0.2,1), padding 0.3s;
  padding: 0 12px;
}
.prof-acc-body.open { max-height: 400px; padding: 12px; }

/* Info row inside accordion */
.prof-info-row {
  display: flex; align-items: center; gap: 8px;
  padding: 8px 0; border-bottom: 1.5px dashed #fde68a;
}
.prof-info-row:last-child { border-bottom: none; }
.prof-info-row .ir-icon { font-size: 14px; color: #d97706; width: 22px; text-align: center; flex-shrink: 0; }
.prof-info-row .ir-lbl { flex: 1; font-size: 10px; font-weight: 800; color: #92400e; }
.prof-info-row .ir-val { font-size: 11px; font-weight: 900; color: #78350f; text-align: right; }

/* Forms */
.prof-lbl { font-size: 10px; font-weight: 900; color: #78350f; margin-bottom: 4px; display: block; }
.prof-input {
  width: 100%; background: #fff; border: 2px solid #fde68a; border-radius: 10px;
  padding: 9px 10px; font-size: 12px; font-weight: 800; color: #78350f;
  margin-bottom: 8px; outline: none; box-sizing: border-box; font-family: inherit;
  transition: border-color 0.2s, box-shadow 0.2s;
}
.prof-input:focus { border-color: #d97706; box-shadow: 0 0 0 3px rgba(217,119,6,0.15); }
.prof-input:disabled { background: #f8fafc; color: #94a3b8; border-color: #e2e8f0; cursor: not-allowed; }
.prof-btn {
  width: 100%; background: #f59e0b; border: 2px solid #78350f; border-radius: 10px;
  padding: 10px; font-size: 12px; font-weight: 900; color: #78350f;
  box-shadow: 0 3px 0 #78350f; cursor: pointer; transition: transform 0.1s; font-family: inherit;
  display: flex; align-items: center; justify-content: center; gap: 6px;
}
.prof-btn:active { transform: translateY(2px); box-shadow: 0 1px 0 #78350f; }

/* ── CONTACT ── */
.prof-contact { display: flex; gap: 8px; justify-content: center; margin-bottom: 14px; flex-wrap: wrap; }
.prof-contact-btn {
  flex-shrink: 0; width: 44px; height: 44px; border-radius: 12px;
  display: flex; align-items: center; justify-content: center;
  border: 2.5px solid #78350f; box-shadow: 0 3px 0 #78350f;
  transition: transform 0.1s; text-decoration: none; color: #78350f; background: #fff;
}
.prof-contact-btn:active { transform: translateY(2px); box-shadow: 0 1px 0 #78350f; }

/* ── LOGOUT ── */
.prof-logout {
  display: flex; align-items: center; justify-content: center; gap: 6px;
  background: #fee2e2; border: 2.5px solid #dc2626; border-radius: 14px;
  padding: 12px; font-size: 13px; font-weight: 900; color: #dc2626;
  text-decoration: none; box-shadow: 0 4px 0 #dc2626; transition: transform 0.1s;
}
.prof-logout:active { transform: translateY(3px); box-shadow: 0 1px 0 #dc2626; }

/* ── HONEYCOMB BORDER DECO ── */
.honey-divider {
  display: flex; align-items: center; gap: 4px; justify-content: center;
  margin: 14px 0; opacity: 0.3;
}
.honey-divider span {
  width: 12px; height: 12px; background: #f59e0b;
  clip-path: polygon(50% 0%, 100% 25%, 100% 75%, 50% 100%, 0% 75%, 0% 25%);
}
</style>

<!-- HERO -->
<div class="prof-hero">
  <!-- Hex decorations -->
  <div class="hex-deco"></div><div class="hex-deco"></div><div class="hex-deco"></div><div class="hex-deco"></div><div class="hex-deco"></div>
  <!-- Flying bees -->
  <div class="fly-bee">🐝</div><div class="fly-bee">🐝</div><div class="fly-bee">🐝</div>

  <div class="prof-ava-wrap">
    <div class="prof-ava-ring">
      <div class="prof-ava"><?= strtoupper(substr($user['username'], 0, 1)) ?></div>
    </div>
  </div>
  <div class="prof-name"><?= htmlspecialchars($user['username']) ?></div>
  <div class="prof-email"><?= htmlspecialchars($user['email']) ?></div>
  <div style="text-align:center">
    <span class="prof-tier <?= $is_premium ? 'prem' : '' ?>">
      <?= $is_premium ? '★ '.$membership_name : '🐝 '.$membership_name ?>
      <?= $user['membership_expires_at'] ? ' • '.date('d/m/y', strtotime($user['membership_expires_at'])) : '' ?>
    </span>
  </div>
  <div class="prof-member-since">📅 Bergabung <?= $member_since ?> · <?= number_format($days_member) ?> hari</div>
</div>

<div class="prof-body">
  <?php if ($flash): ?>
  <div class="prof-flash prof-flash--<?= $flashType === 'error' ? 'error' : 'success' ?>">
    <i class="ph-bold ph-<?= $flashType === 'error' ? 'warning-circle' : 'check-circle' ?>" style="font-size:15px;"></i>
    <?= htmlspecialchars($flash) ?>
  </div>
  <?php endif; ?>

  <!-- BALANCE CARDS -->
  <div class="prof-balance-row">
    <div class="prof-bal-card gold">
      <div class="prof-bal-icon">💰</div>
      <div class="prof-bal-val"><?= format_rp((float)$user['balance_wd']) ?></div>
      <div class="prof-bal-lbl">Saldo Tarik</div>
    </div>
    <div class="prof-bal-card">
      <div class="prof-bal-icon">💎</div>
      <div class="prof-bal-val"><?= format_rp((float)$user['balance_dep']) ?></div>
      <div class="prof-bal-lbl">Saldo Beli</div>
    </div>
  </div>
  <div class="prof-balance-row">
    <div class="prof-bal-card honey">
      <div class="prof-bal-icon">🍯</div>
      <div class="prof-bal-val"><?= number_format((float)$user['honey_stock'], 1) ?> ml</div>
      <div class="prof-bal-lbl">Stok Madu</div>
    </div>
    <div class="prof-bal-card">
      <div class="prof-bal-icon">🪙</div>
      <div class="prof-bal-val"><?= number_format((int)$user['plinko_coins']) ?></div>
      <div class="prof-bal-lbl">Koin Plinko</div>
    </div>
  </div>

  <!-- STATS -->
  <div class="prof-stats">
    <div class="prof-stat">
      <div class="prof-stat-emoji">🏆</div>
      <div class="prof-stat-val"><?= format_rp((float)$user['total_earned']) ?></div>
      <div class="prof-stat-lbl">Total Earned</div>
    </div>
    <div class="prof-stat">
      <div class="prof-stat-emoji">👥</div>
      <div class="prof-stat-val"><?= $refs ?></div>
      <div class="prof-stat-lbl">Referral</div>
    </div>
    <div class="prof-stat">
      <div class="prof-stat-emoji">📺</div>
      <div class="prof-stat-val"><?= number_format($total_watches) ?></div>
      <div class="prof-stat-lbl">Ditonton</div>
    </div>
  </div>

  <div class="prof-stats">
    <div class="prof-stat">
      <div class="prof-stat-emoji">📥</div>
      <div class="prof-stat-val"><?= $total_deposits ?></div>
      <div class="prof-stat-lbl">Deposit</div>
    </div>
    <div class="prof-stat">
      <div class="prof-stat-emoji">📤</div>
      <div class="prof-stat-val"><?= $total_withdrawals ?></div>
      <div class="prof-stat-lbl">Withdraw</div>
    </div>
    <div class="prof-stat">
      <div class="prof-stat-emoji">📈</div>
      <div class="prof-stat-val"><?= $active_investments ?></div>
      <div class="prof-stat-lbl">Investasi</div>
    </div>
  </div>

  <div class="prof-stats" style="grid-template-columns: 1fr 1fr;">
    <div class="prof-stat">
      <div class="prof-stat-emoji">🏠</div>
      <div class="prof-stat-val"><?= $total_hives ?></div>
      <div class="prof-stat-lbl">Sarang</div>
    </div>
    <div class="prof-stat">
      <div class="prof-stat-emoji">🐝</div>
      <div class="prof-stat-val"><?= $total_bees ?></div>
      <div class="prof-stat-lbl">Lebah</div>
    </div>
  </div>

  <!-- HONEYCOMB DIVIDER -->
  <div class="honey-divider"><span></span><span></span><span></span><span></span><span></span><span></span><span></span></div>

  <!-- REFERRAL -->
  <div class="prof-ref">
    <div>
      <div class="prof-ref-lbl">Kode Referral</div>
      <div class="prof-ref-code" id="ref-code"><?= htmlspecialchars($user['referral_code']) ?></div>
    </div>
    <button class="prof-ref-btn" onclick="copyRef()"><i class="ph-bold ph-copy"></i> Salin</button>
  </div>

  <!-- NAV GRID -->
  <div class="prof-nav-title">🧭 Menu Cepat</div>
  <div class="prof-nav">
    <a href="/edit-rekening" class="prof-nav-item pn-rek"><i class="ph-bold ph-bank"></i> Rekening</a>
    <a href="/upgrade" class="prof-nav-item pn-upg"><i class="ph-bold ph-rocket-launch"></i> Upgrade</a>
    <a href="/history" class="prof-nav-item pn-riw"><i class="ph-bold ph-receipt"></i> Riwayat</a>
    <a href="/panduan" class="prof-nav-item pn-pan"><i class="ph-bold ph-book-open"></i> Panduan</a>
    <a href="/farm" class="prof-nav-item pn-farm"><i class="ph-bold ph-tree"></i> Farm</a>
    <a href="/videos" class="prof-nav-item pn-vid"><i class="ph-bold ph-play-circle"></i> Video</a>
    <a href="/missions" class="prof-nav-item pn-misi"><i class="ph-bold ph-target"></i> Misi</a>
    <a href="/plinko" class="prof-nav-item pn-plinko"><i class="ph-bold ph-game-controller"></i> Plinko</a>
    <a href="/invest" class="prof-nav-item pn-inv"><i class="ph-bold ph-chart-line-up"></i> Investasi</a>
  </div>

  <!-- HONEYCOMB DIVIDER -->
  <div class="honey-divider"><span></span><span></span><span></span><span></span><span></span></div>

  <!-- SETTINGS ACCORDION -->
  <div class="prof-group">
    <div class="prof-acc-hdr" onclick="toggleAcc('info')" id="h-info">
      <i class="icon ph-bold ph-identification-card"></i>
      <span class="title">Info Akun</span>
      <i class="caret ph-bold ph-caret-down"></i>
    </div>
    <div class="prof-acc-body" id="b-info">
      <div class="prof-info-row">
        <i class="ir-icon ph-bold ph-user"></i>
        <span class="ir-lbl">Username</span>
        <span class="ir-val"><?= htmlspecialchars($user['username']) ?></span>
      </div>
      <div class="prof-info-row">
        <i class="ir-icon ph-bold ph-envelope"></i>
        <span class="ir-lbl">Email</span>
        <span class="ir-val"><?= htmlspecialchars($user['email']) ?></span>
      </div>
      <div class="prof-info-row">
        <i class="ir-icon ph-bold ph-whatsapp-logo"></i>
        <span class="ir-lbl">WhatsApp</span>
        <span class="ir-val"><?= htmlspecialchars(mask_account($user['whatsapp'] ?? '')) ?></span>
      </div>
      <div class="prof-info-row">
        <i class="ir-icon ph-bold ph-bank"></i>
        <span class="ir-lbl">Bank</span>
        <span class="ir-val"><?= $user['bank_name'] ? htmlspecialchars($user['bank_name'] . ' - ' . mask_account($user['account_number'] ?? '')) : 'Belum Ada' ?></span>
      </div>
      <div class="prof-info-row">
        <i class="ir-icon ph-bold ph-calendar"></i>
        <span class="ir-lbl">Terdaftar</span>
        <span class="ir-val"><?= $member_since ?></span>
      </div>
      <div class="prof-info-row">
        <i class="ir-icon ph-bold ph-star"></i>
        <span class="ir-lbl">Paket</span>
        <span class="ir-val"><?= $membership_name ?></span>
      </div>
      <?php if ((int)$user['spin_tickets'] > 0): ?>
      <div class="prof-info-row">
        <i class="ir-icon ph-bold ph-ticket"></i>
        <span class="ir-lbl">Tiket Spin</span>
        <span class="ir-val"><?= (int)$user['spin_tickets'] ?></span>
      </div>
      <?php endif; ?>
    </div>

    <div class="prof-acc-hdr <?= $active_section === 'edit' ? 'open' : '' ?>" onclick="toggleAcc('edit')" id="h-edit">
      <i class="icon ph-bold ph-pencil-simple"></i>
      <span class="title">Ubah Username</span>
      <i class="caret ph-bold ph-caret-down"></i>
    </div>
    <div class="prof-acc-body <?= $active_section === 'edit' ? 'open' : '' ?>" id="b-edit">
      <form method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="update_profile">
        <label class="prof-lbl">Username Baru (Huruf/Angka/_)</label>
        <input class="prof-input" type="text" name="username" value="<?= htmlspecialchars($user['username']) ?>" required minlength="3">
        <button class="prof-btn"><i class="ph-bold ph-floppy-disk"></i> Simpan Username</button>
      </form>
    </div>

    <div class="prof-acc-hdr <?= $active_section === 'password' ? 'open' : '' ?>" onclick="toggleAcc('password')" id="h-password">
      <i class="icon ph-bold ph-lock-key"></i>
      <span class="title">Ganti Password</span>
      <i class="caret ph-bold ph-caret-down"></i>
    </div>
    <div class="prof-acc-body <?= $active_section === 'password' ? 'open' : '' ?>" id="b-password">
      <form method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="change_password">
        <label class="prof-lbl">Password Lama</label>
        <input class="prof-input" type="password" name="old_password" required minlength="6">
        <label class="prof-lbl">Password Baru</label>
        <input class="prof-input" type="password" name="new_password" required minlength="6">
        <button class="prof-btn"><i class="ph-bold ph-key"></i> Update Password</button>
      </form>
    </div>
  </div>

  <!-- CONTACT -->
  <div class="prof-nav-title">📞 Hubungi Kami</div>
  <div class="prof-contact">
    <?php foreach ($_contact_btns as $cb): ?>
      <?php
        $t = strtolower($cb['icon_value']);
        $svg = $_psvg[$t] ?? $_psvg['cs'];
        $c = match($t) {
            'wa' => 'background:linear-gradient(135deg, #4ade80, #16a34a);border-color:#14532d;box-shadow:0 3px 0 #14532d;color:#fff;',
            'tele' => 'background:linear-gradient(135deg, #60a5fa, #2563eb);border-color:#1e3a8a;box-shadow:0 3px 0 #1e3a8a;color:#fff;',
            'ig' => 'background:linear-gradient(135deg, #f43f5e, #be123c);border-color:#881337;box-shadow:0 3px 0 #881337;color:#fff;',
            'fb' => 'background:linear-gradient(135deg, #3b82f6, #1d4ed8);border-color:#1e3a8a;box-shadow:0 3px 0 #1e3a8a;color:#fff;',
            default => 'background:linear-gradient(135deg, #94a3b8, #475569);border-color:#1e293b;box-shadow:0 3px 0 #1e293b;color:#fff;'
        };
      ?>
      <a href="<?= htmlspecialchars($cb['url']) ?>" class="prof-contact-btn" target="_blank" style="<?= $c ?>">
        <?= $svg ?>
      </a>
    <?php endforeach; ?>
  </div>

  <!-- HONEYCOMB DIVIDER -->
  <div class="honey-divider"><span></span><span></span><span></span><span></span><span></span><span></span><span></span></div>

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
