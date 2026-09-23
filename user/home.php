<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/bootstrap.php';

$user = auth_user($pdo);
$is_guest = false;
if (!$user) {
    $is_guest = true;
    $user = [
        'id' => 0, 'username' => 'Sobat Lebah', 'balance_wd' => 0, 'balance_dep' => 0, 'honey_stock' => 0,
        'membership_id' => null, 'membership_expires_at' => null, 'referral_code' => '-', 'is_promotor' => 0,
    ];
}

if (is_maintenance($pdo) && !auth_admin()) {
    $maintenance_msg = setting($pdo, 'maintenance_message', 'Sistem sedang dalam perbaikan.');
    require dirname(__DIR__) . '/user/maintenance.php';
    exit;
}

track_pageview($pdo, parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));

// Ambil data terbaru user
if (!$is_guest) {
    $uStmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $uStmt->execute([$user['id']]);
    $user = $uStmt->fetch(PDO::FETCH_ASSOC);
}

// ── TUGAS UTAMA: DATA NONTON VIDEO ──
$watch_limit = user_watch_limit($pdo, $user);
$watch_today = user_watch_today($pdo, $user);
$watch_pct   = $watch_limit > 0 ? min(100, (int)round(($watch_today / $watch_limit) * 100)) : 0;
$watch_remaining = max(0, $watch_limit - $watch_today);

// Cuan dari Nonton Video Hari Ini
$today_watch_earned = 0.0;
if (!$is_guest) {
    try {
        $twStmt = $pdo->prepare("SELECT COALESCE(SUM(reward_given), 0) FROM watch_history WHERE user_id = ? AND DATE(watched_at) = CURDATE()");
        $twStmt->execute([$user['id']]);
        $today_watch_earned = (float)$twStmt->fetchColumn();
    } catch (\Throwable) {}
}

// Data Referral Pengguna untuk Flex / Promosi
$ref_code = $user['referral_code'] ?? '-';
$ref_url = function_exists('base_url') ? base_url('register/' . ($ref_code !== '-' ? $ref_code : '')) : '/register?ref=' . urlencode($ref_code);

// Video Populer & Cuan Hari Ini
$featured_videos = [];
try {
    $vStmt = $pdo->prepare("
        SELECT v.*,
               (SELECT COUNT(*) FROM watch_history wh WHERE wh.user_id = ? AND wh.video_id = v.id AND DATE(wh.watched_at) = CURDATE()) AS watched_today
        FROM videos v
        WHERE v.is_active = 1
        ORDER BY v.sort_order ASC, v.id DESC
        LIMIT 4
    ");
    $vStmt->execute([$user['id']]);
    $featured_videos = $vStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable) {}

// ── SIDEJOB: DATA PETERNAKAN LEBAH 3D ──
$user_hives = [];
$total_active_bees = 0;
$total_hourly_rate = 0.0;
$total_unharvested_honey = 0.0;

if (!$is_guest) {
    try {
        $hivesStmt = $pdo->prepare("
            SELECT h.*, m.name as master_name, m.image as master_image, m.max_slots, m.bonus_speed_pct
            FROM user_bee_hives h
            JOIN bee_hives_master m ON m.id = h.hive_master_id
            WHERE h.user_id = ? AND h.is_active = 1 AND (h.expires_at IS NULL OR h.expires_at > NOW())
            ORDER BY h.id ASC
        ");
        $hivesStmt->execute([$user['id']]);
        $user_hives = $hivesStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($user_hives as &$uh) {
            $det = BeeFarm::getHiveDetails($pdo, (int)$uh['id'], (int)$user['id']);
            $uh['details'] = $det;
            $total_active_bees += (int)($det['bee_count'] ?? 0);
            $total_hourly_rate += (float)($det['hourly_production'] ?? 0.0);
            $total_unharvested_honey += (float)($det['total_honey'] ?? 0.0);
        }
        unset($uh);
    } catch (\Throwable) {}
}

// Lapak Madu Aktif
$active_stall = null;
if (!$is_guest) {
    try {
        $active_stall = BeeFarm::getUserActiveStall($pdo, (int)$user['id']);
    } catch (\Throwable) {}
}

// Notifikasi Preview
$notif_preview = [];
if (!$is_guest) {
    try {
        $uid = $user['id'];
        $np = $pdo->prepare("
            SELECT n.* FROM notifications n LEFT JOIN notification_reads nr ON nr.notification_id=n.id AND nr.user_id=?
            WHERE nr.id IS NULL AND (n.target_type='all' OR (n.target_user_ids IS NOT NULL AND JSON_CONTAINS(n.target_user_ids, JSON_QUOTE(?))))
              AND (n.expires_at IS NULL OR n.expires_at > NOW()) ORDER BY n.created_at DESC LIMIT 2
        ");
        $np->execute([$uid, (string)$uid]);
        $notif_preview = $np->fetchAll();
    } catch (\Throwable) {}
}

// Level Membership
$membership_name = '';
if ($user['membership_id'] && $user['membership_expires_at'] && strtotime((string)$user['membership_expires_at']) > time()) {
    $ms = $pdo->prepare("SELECT name FROM memberships WHERE id=?");
    $ms->execute([$user['membership_id']]);
    $membership_name = $ms->fetchColumn();
}
if (!$membership_name) {
    $membership_name = $pdo->query("SELECT name FROM memberships WHERE price=0 AND is_active=1 ORDER BY sort_order ASC LIMIT 1")->fetchColumn();
    if (!$membership_name) $membership_name = 'Member Gratis';
}

$pageTitle = 'Nonton Video & Sidejob Ternak Lebah 3D';
$activePage = 'home';
require dirname(__DIR__) . '/partials/header.php';
?>

<style>
/* ══════════════════════════════════════════════════════════
   LEBAHCUAN — AMBER HONEY THEME + BEE MASCOT & 3D SIDEJOB
   ══════════════════════════════════════════════════════════ */
body {
  background: #fef8ee !important;
  font-family: 'Nunito', sans-serif;
  overflow-x: hidden;
}

/* ── HERO SECTION: NONTON VIDEO & DISPLAY SALDO VIP ── */
.amber-hero-wrap {
  background: linear-gradient(180deg, #78350f 0%, #92400e 30%, #b45309 65%, #d97706 100%);
  padding: 14px 14px 20px;
  position: relative;
  overflow: hidden;
  border-bottom: 4px solid #78350f;
  box-shadow: 0 8px 24px rgba(120,53,15,0.32);
}
/* Subtle honeycomb hexagon background pattern */
.amber-hero-wrap::before {
  content: ''; position: absolute; inset: 0;
  background-image: radial-gradient(rgba(255,255,255,0.18) 15%, transparent 16%), radial-gradient(rgba(255,255,255,0.18) 15%, transparent 16%);
  background-size: 22px 22px;
  background-position: 0 0, 11px 11px;
  opacity: 0.16; pointer-events: none;
}
/* Soft radial amber sheen */
.amber-hero-wrap::after {
  content: ''; position: absolute; top: -40px; right: -40px;
  width: 180px; height: 180px; border-radius: 50%;
  background: radial-gradient(circle, rgba(254,240,138,0.25) 0%, transparent 70%);
  pointer-events: none;
}

/* User Profile Row */
.hero-user-bar {
  display: flex; align-items: center; justify-content: space-between;
  margin-bottom: 10px; position: relative; z-index: 2;
}
.hero-user-left {
  display: flex; align-items: center; gap: 10px;
}
.hero-avatar-box {
  width: 48px; height: 48px; background: #ffffff;
  border: 2.5px solid #78350f; border-radius: 16px;
  display: flex; align-items: center; justify-content: center;
  box-shadow: 0 3px 0 #78350f; position: relative; flex-shrink: 0;
  animation: mascotBob 4s ease-in-out infinite;
}
.hero-avatar-box img { width: 34px; height: 34px; object-fit: contain; }
@keyframes mascotBob {
  0%, 100% { transform: translateY(0); }
  50% { transform: translateY(-3px); }
}
.hero-avatar-badge {
  position: absolute; bottom: -3px; right: -3px;
  background: #10b981; color: #fff; border-radius: 50%;
  width: 14px; height: 14px; border: 2px solid #fff;
  display: flex; align-items: center; justify-content: center;
  font-size: 8px;
}
.hero-user-meta { line-height: 1.15; }
.hero-user-name {
  font-size: 15px; font-weight: 900; color: #ffffff;
  text-shadow: 0 2px 0 #78350f; display: flex; align-items: center; gap: 5px;
}
.hero-tier-pill {
  display: inline-flex; align-items: center; gap: 4px;
  background: rgba(120,53,15,0.65); border: 1.5px solid rgba(254,240,138,0.7);
  border-radius: 10px; padding: 2px 8px; font-size: 9.5px; font-weight: 900;
  color: #fef08a; margin-top: 3px;
}
.hero-profile-btn {
  background: #ffffff; border: 2.5px solid #78350f; border-radius: 12px;
  padding: 6px 12px; font-size: 11px; font-weight: 900; color: #78350f;
  text-decoration: none; box-shadow: 0 3px 0 #78350f; display: flex; align-items: center; gap: 4px;
  transition: transform 0.1s;
}
.hero-profile-btn:active { transform: translateY(2px); box-shadow: 0 1px 0 #78350f; }

/* ── MASCOT SPEECH BUBBLE ── */
.mascot-dialogue {
  background: rgba(255,255,255,0.96);
  border: 2.5px solid #78350f;
  border-radius: 16px;
  padding: 9px 12px;
  margin-bottom: 12px;
  position: relative;
  z-index: 2;
  box-shadow: 0 3px 0 #78350f;
  display: flex;
  align-items: center;
  gap: 8px;
}
.mascot-dialogue__icon { font-size: 20px; flex-shrink: 0; animation: beePulse 2s infinite; }
@keyframes beePulse { 0%, 100% { transform: scale(1); } 50% { transform: scale(1.15); } }
.mascot-dialogue__text { font-size: 11px; font-weight: 800; color: #78350f; line-height: 1.35; }
.mascot-dialogue__text strong { color: #b45309; }

/* ══════════════════════════════════════════════════════════
   THE VIP EARNINGS CARD (SCREENSHOT TARGET FOR PROMOTIONS)
   ══════════════════════════════════════════════════════════ */
.cuan-flex-card {
  background: linear-gradient(145deg, #ffffff 0%, #fffbeb 40%, #fef3c7 100%);
  border: 3.5px solid #78350f;
  border-radius: 22px;
  box-shadow: 0 8px 0 #78350f, 0 16px 28px rgba(120,53,15,0.22);
  padding: 16px 14px 14px;
  position: relative;
  z-index: 2;
  overflow: hidden;
}
/* Watermark corner badge */
.cuan-card-watermark-tag {
  position: absolute; top: 10px; right: 12px;
  font-size: 8.5px; font-weight: 900; letter-spacing: 0.8px;
  color: #b45309; opacity: 0.65; text-transform: uppercase;
}
/* Watermark bee illustration background */
.cuan-card-bee-bg {
  position: absolute; right: -12px; bottom: -12px;
  width: 105px; height: 105px; opacity: 0.08; pointer-events: none;
  background: url('/assets/game/bee_golden.png') no-repeat center center / contain;
}

/* Card Brand Header */
.cuan-card-header {
  display: flex; align-items: center; justify-content: space-between;
  margin-bottom: 8px; padding-bottom: 6px;
  border-bottom: 1.5px dashed rgba(180,83,9,0.3);
}
.cuan-card-brand {
  display: flex; align-items: center; gap: 6px;
  font-size: 11px; font-weight: 900; color: #78350f;
}
.cuan-card-brand-badge {
  background: #f59e0b; color: #78350f; font-size: 9px; font-weight: 900;
  padding: 2px 7px; border-radius: 8px; border: 1.5px solid #78350f;
  box-shadow: 0 1.5px 0 #78350f;
}
.cuan-card-status-verified {
  display: inline-flex; align-items: center; gap: 5px;
  font-size: 10px; font-weight: 900; color: #059669;
  background: #ecfdf5; border: 1.5px solid #10b981;
  padding: 2px 7px; border-radius: 10px;
}
.cuan-card-status-verified .pulse-dot {
  width: 6px; height: 6px; background: #10b981; border-radius: 50%;
  animation: pulseGreen 1.5s infinite;
}
@keyframes pulseGreen {
  0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16,185,129,0.7); }
  70% { transform: scale(1.1); box-shadow: 0 0 0 5px rgba(16,185,129,0); }
  100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16,185,129,0); }
}

/* Balance Hero Display */
.cuan-card-balance-box {
  margin: 6px 0 10px;
}
.cuan-balance-sub {
  font-size: 9.5px; font-weight: 900; color: #92400e; text-transform: uppercase;
  letter-spacing: 0.6px; display: flex; align-items: center; gap: 4px;
}
.cuan-balance-main {
  font-size: 29px; font-weight: 900; color: #78350f;
  line-height: 1.1; margin: 4px 0 6px;
  text-shadow: 0 1px 0 rgba(255,255,255,0.9);
  font-family: 'Nunito', sans-serif;
  letter-spacing: -0.5px;
  display: flex; align-items: baseline; gap: 2px;
}
.cuan-balance-main .curr {
  font-size: 18px; font-weight: 900; color: #d97706; margin-right: 2px;
}

/* Dual Cuan Stat Pills (Cuan Hari Ini & Total Dihasilkan) */
.cuan-stats-pills {
  display: grid; grid-template-columns: 1fr 1fr; gap: 6px;
  margin-bottom: 12px;
}
.cuan-pill {
  background: #ffffff; border: 2px solid #78350f; border-radius: 12px;
  padding: 6px 8px; box-shadow: 0 2.5px 0 #78350f;
  display: flex; flex-direction: column;
}
.cuan-pill-lbl {
  font-size: 8.5px; font-weight: 900; color: #92400e; text-transform: uppercase;
  display: flex; align-items: center; gap: 3px;
}
.cuan-pill-val {
  font-size: 13.5px; font-weight: 900; line-height: 1.2; margin-top: 1px;
}
.cuan-pill-val--today { color: #059669; }
.cuan-pill-val--total { color: #b45309; }

/* Referral Promo Strip on Card (For Screenshots!) */
.cuan-ref-banner {
  background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
  border: 2px solid #78350f;
  border-radius: 14px;
  padding: 7px 10px;
  display: flex; align-items: center; justify-content: space-between;
  margin-bottom: 12px;
  box-shadow: 0 2.5px 0 #78350f;
  cursor: pointer;
  transition: transform 0.1s;
}
.cuan-ref-banner:active { transform: scale(0.98); }
.cuan-ref-left {
  display: flex; align-items: center; gap: 6px;
}
.cuan-ref-tag {
  background: #78350f; color: #fef08a; font-size: 8.5px; font-weight: 900;
  padding: 2px 6px; border-radius: 6px; text-transform: uppercase;
}
.cuan-ref-code {
  font-family: monospace; font-size: 13.5px; font-weight: 900; color: #78350f;
  letter-spacing: 1px;
}
.cuan-ref-copy-btn {
  background: #ffffff; border: 1.5px solid #78350f; border-radius: 8px;
  padding: 3px 8px; font-size: 9.5px; font-weight: 900; color: #78350f;
  display: flex; align-items: center; gap: 3px; box-shadow: 0 1.5px 0 #78350f;
}

/* ── INTEGRATED VIDEO TASK MISSION ── */
.cuan-video-mission {
  background: #ffffff;
  border: 2px solid #78350f;
  border-radius: 16px;
  padding: 10px 12px;
  box-shadow: 0 3px 0 #78350f;
  margin-bottom: 12px;
}
.cvm-head {
  display: flex; align-items: center; justify-content: space-between;
  margin-bottom: 6px;
}
.cvm-badge {
  display: inline-flex; align-items: center; gap: 4px;
  background: #ea580c; color: #fff; font-size: 9px; font-weight: 900;
  padding: 2px 7px; border-radius: 8px; border: 1.5px solid #78350f;
  text-transform: uppercase;
}
.cvm-quota {
  font-size: 11.5px; font-weight: 900; color: #78350f;
  background: #fef3c7; border: 1.5px solid #78350f;
  padding: 2px 7px; border-radius: 8px;
}
.cvm-bar-wrap {
  height: 14px; background: #fed7aa; border: 1.5px solid #78350f;
  border-radius: 10px; overflow: hidden; position: relative; margin-bottom: 6px;
}
.cvm-bar-fill {
  height: 100%;
  background: linear-gradient(90deg, #fbbf24 0%, #f59e0b 50%, #d97706 100%);
  border-radius: 8px;
  transition: width 0.6s cubic-bezier(0.34, 1.56, 0.64, 1);
  position: relative;
  overflow: hidden;
}
.cvm-bar-fill::after {
  content: ''; position: absolute; inset: 0;
  background: linear-gradient(90deg, transparent, rgba(255,255,255,0.45), transparent);
  animation: barShimmer 2s infinite;
}
@keyframes barShimmer {
  0% { transform: translateX(-100%); }
  100% { transform: translateX(100%); }
}
.cvm-foot {
  display: flex; align-items: center; justify-content: space-between;
  font-size: 10px; font-weight: 800; color: #78350f;
}

/* Primary CTA Button: Tonton Video Sekarang */
.btn-cuan-watch-now {
  width: 100%; padding: 13px 14px; border-radius: 16px;
  background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
  border: 3px solid #78350f; box-shadow: 0 5px 0 #78350f;
  color: #fff; font-size: 14px; font-weight: 900;
  text-decoration: none; display: flex; align-items: center; justify-content: center; gap: 8px;
  text-shadow: 0 1.5px 0 #78350f; transition: transform 0.1s;
  font-family: 'Nunito', sans-serif;
  margin-bottom: 10px;
  animation: ctaGlow 2.5s ease-in-out infinite;
}
.btn-cuan-watch-now:active { transform: translateY(3px); box-shadow: 0 2px 0 #78350f; }
@keyframes ctaGlow {
  0%, 100% { box-shadow: 0 5px 0 #78350f, 0 0 0 rgba(245,158,11,0); }
  50% { box-shadow: 0 5px 0 #78350f, 0 0 18px rgba(245,158,11,0.6); }
}

/* Quick Action Buttons (WD, Top Up, Share) */
.cuan-card-actions {
  display: grid; grid-template-columns: 1.2fr 1fr 1fr; gap: 6px;
}
.cuan-btn-action {
  display: flex; align-items: center; justify-content: center; gap: 4px;
  padding: 8px 4px; border-radius: 12px; font-size: 11px; font-weight: 900;
  text-decoration: none; border: 2px solid #78350f; font-family: 'Nunito', sans-serif;
  transition: transform 0.1s;
}
.cuan-btn-action:active { transform: translateY(2px); box-shadow: none !important; }
.cuan-btn-action--wd {
  background: #10b981; color: #ffffff; box-shadow: 0 3px 0 #065f46;
  border-color: #065f46; text-shadow: 0 1px 0 #064e3b;
}
.cuan-btn-action--dep {
  background: #38bdf8; color: #0c4a6e; box-shadow: 0 3px 0 #78350f;
}
.cuan-btn-action--share {
  background: #fde047; color: #78350f; box-shadow: 0 3px 0 #78350f;
}

/* ── CUAN TOAST NOTIFICATION ── */
#cuan-toast {
  position: fixed;
  bottom: 84px;
  left: 50%;
  transform: translateX(-50%) translateY(20px);
  background: #78350f;
  color: #fef08a;
  border: 2px solid #fef08a;
  box-shadow: 0 8px 24px rgba(120,53,15,0.4);
  padding: 10px 18px;
  border-radius: 16px;
  font-size: 12px;
  font-weight: 900;
  z-index: 999999;
  display: flex;
  align-items: center;
  gap: 8px;
  opacity: 0;
  visibility: hidden;
  transition: all 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
  pointer-events: none;
  white-space: nowrap;
}
#cuan-toast.show {
  opacity: 1;
  visibility: visible;
  transform: translateX(-50%) translateY(0);
}

/* ── CONTAINER ── */
.home-feed { padding: 16px 14px 110px; max-width: 480px; margin: 0 auto; }

/* ── SECTION CARD ── */
.amber-section-card {
  background: #ffffff;
  border: 3px solid #78350f;
  border-radius: 24px;
  box-shadow: 0 6px 0 #78350f;
  padding: 16px;
  margin-bottom: 18px;
  position: relative;
  overflow: hidden;
}


/* ── FEATURED VIDEO GRID (2x2) ── */
.v-grid-mini {
  display: grid; grid-template-columns: repeat(2, 1fr); gap: 10px;
  margin-top: 12px;
}
.v-card-mini {
  background: #fff; border: 2.5px solid #78350f; border-radius: 18px;
  box-shadow: 0 4px 0 #78350f; overflow: hidden; text-decoration: none;
  display: flex; flex-direction: column; transition: transform 0.1s;
}
.v-card-mini:active { transform: translateY(2px); box-shadow: 0 2px 0 #78350f; }
.v-thumb-wrap {
  position: relative; aspect-ratio: 16/9; background: #000; overflow: hidden;
}
.v-thumb-wrap img { width: 100%; height: 100%; object-fit: cover; }
.v-badge-reward {
  position: absolute; top: 4px; right: 4px;
  background: #f59e0b; color: #78350f; font-size: 9px; font-weight: 900;
  padding: 2px 6px; border-radius: 8px; border: 1.5px solid #78350f;
  box-shadow: 0 2px 0 #78350f;
}
.v-badge-duration {
  position: absolute; bottom: 4px; left: 4px;
  background: rgba(0,0,0,0.7); color: #fff; font-size: 8.5px; font-weight: 800;
  padding: 1px 5px; border-radius: 6px;
}
.v-play-overlay {
  position: absolute; inset: 0; display: flex; align-items: center; justify-content: center;
  background: rgba(0,0,0,0.25);
}
.v-play-btn-circle {
  width: 28px; height: 28px; border-radius: 50%; background: #fbbf24;
  border: 2px solid #78350f; display: flex; align-items: center; justify-content: center;
  color: #78350f; font-size: 14px; box-shadow: 0 2px 0 #78350f;
}
.v-info-mini {
  padding: 8px; display: flex; flex-direction: column; justify-content: space-between; flex: 1;
}
.v-title-mini {
  font-size: 11px; font-weight: 800; color: #1e293b; line-height: 1.25;
  display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;
}
.v-status-mini {
  margin-top: 6px; font-size: 9.5px; font-weight: 900; display: flex; align-items: center; gap: 4px;
}
.v-status--done { color: #059669; }
.v-status--ready { color: #d97706; }

/* ── SIDEJOB: PETERNAKAN LEBAH 3D SHOWCASE ── */
.sidejob-3d-card {
  background: linear-gradient(145deg, #15803d 0%, #166534 60%, #14532d 100%);
  border: 3.5px solid #78350f;
  border-radius: 24px;
  box-shadow: 0 6px 0 #78350f, 0 10px 25px rgba(21,128,61,0.3);
  padding: 18px 16px;
  margin-bottom: 18px;
  position: relative;
  overflow: hidden;
  color: #fff;
}
.sidejob-3d-card::before {
  content: ''; position: absolute; top: -30px; right: -30px;
  width: 140px; height: 140px; border-radius: 50%;
  background: radial-gradient(circle, rgba(251,191,36,0.3) 0%, transparent 70%);
  pointer-events: none;
}
.sidejob-badge-tag {
  display: inline-flex; align-items: center; gap: 5px;
  background: #fbbf24; color: #78350f; font-size: 9.5px; font-weight: 900;
  padding: 3px 9px; border-radius: 12px; border: 2px solid #78350f;
  box-shadow: 0 2px 0 #78350f; text-transform: uppercase; margin-bottom: 8px;
}
.sidejob-title-row {
  display: flex; align-items: center; justify-content: space-between;
  margin-bottom: 8px;
}
.sidejob-title { font-size: 16px; font-weight: 900; color: #fff; text-shadow: 0 2px 0 #14532d; }
.sidejob-desc { font-size: 11px; font-weight: 700; color: #dcfce7; line-height: 1.4; margin-bottom: 14px; }

/* 3D Farm Live Metrics */
.sidejob-metrics-grid {
  display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px;
  margin-bottom: 14px;
}
.sidejob-metric-pill {
  background: rgba(255,255,255,0.92); border: 2px solid #78350f; border-radius: 14px;
  padding: 8px 4px; text-align: center; box-shadow: 0 3px 0 #78350f;
}
.sidejob-metric-val { font-size: 13px; font-weight: 900; color: #b45309; }
.sidejob-metric-lbl { font-size: 8.5px; font-weight: 900; color: #64748b; text-transform: uppercase; margin-top: 1px; }

.btn-open-3d-farm {
  width: 100%; padding: 13px; border-radius: 18px;
  background: linear-gradient(135deg, #fbbf24 0%, #f59e0b 60%, #d97706 100%);
  border: 3px solid #78350f; box-shadow: 0 5px 0 #78350f;
  color: #fff; font-size: 14px; font-weight: 900;
  text-decoration: none; display: flex; align-items: center; justify-content: center; gap: 8px;
  text-shadow: 0 1px 2px #78350f; transition: transform 0.1s;
  font-family: 'Nunito', sans-serif;
}
.btn-open-3d-farm:active { transform: translateY(3px); box-shadow: 0 2px 0 #78350f; }

.sidejob-sub-links {
  display: flex; justify-content: center; gap: 14px; margin-top: 10px;
}
.sidejob-sub-link {
  font-size: 11px; font-weight: 900; color: #fef08a; text-decoration: underline;
}

/* ── BENTO QUICK ACCESS MENU ── */
.bento-menu-grid {
  display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px;
  margin-top: 10px; margin-bottom: 20px;
}
.b-tile {
  background: #fff; border: 2.5px solid #78350f; border-radius: 18px;
  box-shadow: 0 4px 0 #78350f; padding: 10px 4px; text-decoration: none;
  display: flex; flex-direction: column; align-items: center; justify-content: center;
  gap: 5px; transition: transform 0.1s;
}
.b-tile:active { transform: translateY(3px); box-shadow: 0 1px 0 #78350f; }
.b-tile__icon {
  width: 36px; height: 36px; border-radius: 12px;
  display: flex; align-items: center; justify-content: center;
  font-size: 20px; color: #fff; border: 2px solid #78350f; box-shadow: 0 2px 0 #78350f;
}
.b-tile__lbl { font-size: 9.5px; font-weight: 900; color: #78350f; text-align: center; }

/* ── TIPS & ANNOUNCEMENTS ── */
.amber-tips-box {
  background: #fff; border: 2.5px solid #78350f; border-radius: 18px;
  box-shadow: 0 4px 0 #78350f; padding: 12px 14px; display: flex; gap: 10px; align-items: center;
  margin-bottom: 16px;
}
.amber-tips-icon {
  width: 38px; height: 38px; border-radius: 12px; background: #fef3c7;
  border: 2px solid #d97706; display: flex; align-items: center; justify-content: center;
  font-size: 20px; flex-shrink: 0;
}
</style>

<!-- ══════════════════════════════════════════════════════════
     HERO SECTION: NONTON VIDEO & DISPLAY SALDO VIP (FLEX READY)
     ══════════════════════════════════════════════════════════ -->
<div class="amber-hero-wrap">
  <!-- User Profile Row -->
  <div class="hero-user-bar">
    <div class="hero-user-left">
      <div class="hero-avatar-box">
        <img src="/assets/game/bee_worker.png" alt="Buzzy Lebah">
        <span class="hero-avatar-badge"><i class="ph-bold ph-check"></i></span>
      </div>
      <div class="hero-user-meta">
        <div class="hero-user-name">
          <span><?= htmlspecialchars($user['username']) ?></span>
          <img src="/assets/game/crown_3d.png" alt="VIP" style="width:16px;height:16px;object-fit:contain;">
        </div>
        <div class="hero-tier-pill">
          <i class="ph-fill ph-crown"></i>
          <span><?= htmlspecialchars($membership_name) ?></span>
        </div>
      </div>
    </div>
    <?php if ($is_guest): ?>
      <a href="/login" class="hero-profile-btn">
        <i class="ph-bold ph-sign-in"></i> Masuk
      </a>
    <?php else: ?>
      <a href="/profile" class="hero-profile-btn">
        <i class="ph-bold ph-gear"></i> Profil
      </a>
    <?php endif; ?>
  </div>

  <!-- Mascot Speech Bubble -->
  <div class="mascot-dialogue">
    <div class="mascot-dialogue__icon">🐝</div>
    <div class="mascot-dialogue__text">
      Halo <strong><?= htmlspecialchars($user['username']) ?></strong>! Nonton video hari ini & kumpulkan saldo Rupiah. Bagikan screenshot bukti cuanmu ke teman untuk raih komisi!
    </div>
  </div>

  <!-- ── THE VIP FLEX CARD (SCREENSHOT TARGET) ── -->
  <div class="cuan-flex-card" id="cuan-ss-target">
    <div class="cuan-card-watermark-tag">LEBAHCUAN VIP CASH</div>
    <div class="cuan-card-bee-bg"></div>

    <!-- Header Card -->
    <div class="cuan-card-header">
      <div class="cuan-card-brand">
        <span class="cuan-card-brand-badge">⚡ RESMI</span>
        <span>LEBAHCUAN WATCH-TO-EARN</span>
      </div>
      <div class="cuan-card-status-verified">
        <span class="pulse-dot"></span>
        <span>Siap Cair 24 Jam</span>
      </div>
    </div>

    <!-- Main Balance Display -->
    <div class="cuan-card-balance-box">
      <div class="cuan-balance-sub">
        <i class="ph-fill ph-shield-check" style="color:#059669;font-size:13px;"></i>
        <span>Saldo Siap Tarik (WD)</span>
      </div>
      <div class="cuan-balance-main">
        <span class="curr">Rp</span>
        <span><?= number_format((float)$user['balance_wd'], 0, ',', '.') ?></span>
      </div>
    </div>

    <!-- Dual Stat Pills: Cuan Hari Ini & Total Dihasilkan -->
    <div class="cuan-stats-pills">
      <div class="cuan-pill">
        <div class="cuan-pill-lbl">
          <i class="ph-fill ph-sparkle" style="color:#10b981;"></i> Cuan Hari Ini
        </div>
        <div class="cuan-pill-val cuan-pill-val--today">
          +Rp <?= number_format($today_watch_earned, 0, ',', '.') ?>
        </div>
      </div>
      <div class="cuan-pill">
        <div class="cuan-pill-lbl">
          <i class="ph-fill ph-trophy" style="color:#f59e0b;"></i> Total Dihasilkan
        </div>
        <div class="cuan-pill-val cuan-pill-val--total">
          Rp <?= number_format((float)$user['total_earned'], 0, ',', '.') ?>
        </div>
      </div>
    </div>

    <!-- Referral Code Strip on Card (High Visibility for Screenshots!) -->
    <div class="cuan-ref-banner" onclick="copyRefCode('<?= htmlspecialchars($ref_code) ?>')" title="Klik untuk menyalin kode referral">
      <div class="cuan-ref-left">
        <span class="cuan-ref-tag"><i class="ph-bold ph-ticket"></i> KODE REFF</span>
        <span class="cuan-ref-code"><?= htmlspecialchars($ref_code) ?></span>
      </div>
      <button type="button" class="cuan-ref-copy-btn">
        <i class="ph-bold ph-copy"></i>
        <span>Salin</span>
      </button>
    </div>

    <!-- Integrated Video Task Mission Bar -->
    <div class="cuan-video-mission">
      <div class="cvm-head">
        <span class="cvm-badge"><i class="ph-fill ph-film-strip"></i> Misi Video Hari Ini</span>
        <span class="cvm-quota">Kuota: <?= $watch_today ?> / <?= $watch_limit ?></span>
      </div>

      <div class="cvm-bar-wrap">
        <div class="cvm-bar-fill" style="width: <?= $watch_pct ?>%;"></div>
      </div>

      <div class="cvm-foot">
        <span>Progres: <strong><?= $watch_pct ?>% Selesai</strong></span>
        <span><?= $watch_remaining > 0 ? "Sisa {$watch_remaining} video lagi" : "✓ Kuota Penuh!" ?></span>
      </div>
    </div>

    <!-- Primary CTA: Mulai Nonton Video Sekarang -->
    <a href="/videos" class="btn-cuan-watch-now">
      <i class="ph-fill ph-play-circle" style="font-size:20px;"></i>
      <span><?= $watch_remaining > 0 ? 'Mulai Tonton Video Sekarang' : 'Buka Galeri Video & Cuan' ?></span>
      <i class="ph-bold ph-arrow-right"></i>
    </a>

    <!-- Quick Action Buttons -->
    <div class="cuan-card-actions">
      <a href="/withdraw" class="cuan-btn-action cuan-btn-action--wd">
        <i class="ph-bold ph-arrow-up-right"></i> Tarik Saldo
      </a>
      <a href="/deposit" class="cuan-btn-action cuan-btn-action--dep">
        <i class="ph-bold ph-wallet"></i> Top Up
      </a>
      <button type="button" onclick="shareRefLink('<?= htmlspecialchars($ref_url) ?>', '<?= htmlspecialchars($ref_code) ?>')" class="cuan-btn-action cuan-btn-action--share" style="cursor:pointer;">
        <i class="ph-bold ph-share-network"></i> Bagikan
      </button>
    </div>
  </div>
</div>

<div class="home-feed">

  <!-- ══════════════════════════════════════════════════════════
       1. TUGAS VIDEO PILIHAN HARI INI (FEATURED VIDEOS)
       ══════════════════════════════════════════════════════════ -->
  <?php if (!empty($featured_videos)): ?>
    <div class="amber-section-card" style="padding:14px;">
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:4px;">
        <div style="font-size:14px;font-weight:900;color:#78350f;display:flex;align-items:center;gap:6px;">
          <i class="ph-fill ph-sparkle" style="color:#f59e0b;font-size:18px;"></i>
          <span>Video Pilihan Berhadiah</span>
        </div>
        <a href="/videos" style="font-size:11px;font-weight:900;color:#d97706;text-decoration:none;display:flex;align-items:center;gap:2px;">
          Lihat Semua &rarr;
        </a>
      </div>
      <div style="font-size:10.5px;font-weight:700;color:#94a3b8;margin-bottom:12px;">
        Tonton video pilihan berdurasi singkat dan saldo cuan langsung bertambah otomatis!
      </div>

      <div class="v-grid-mini">
        <?php foreach ($featured_videos as $fv):
          $done = (bool)$fv['watched_today'];
          $thumbUrl = yt_thumb($fv['youtube_id']);
        ?>
          <a href="<?= $done ? '/videos' : '/watch?id='.$fv['id'] ?>" class="v-card-mini">
            <div class="v-thumb-wrap">
              <img src="<?= htmlspecialchars($thumbUrl) ?>" alt="<?= htmlspecialchars($fv['title']) ?>" loading="lazy" onerror="this.src='https://img.youtube.com/vi/<?= $fv['youtube_id'] ?>/hqdefault.jpg'">
              <div class="v-badge-reward">+Rp <?= number_format((float)$fv['reward_amount'], 0, ',', '.') ?></div>
              <div class="v-badge-duration"><i class="ph-bold ph-clock"></i> <?= $fv['watch_duration'] ?>s</div>
              <div class="v-play-overlay">
                <?php if ($done): ?>
                  <i class="ph-fill ph-check-circle" style="color:#10b981;font-size:26px;"></i>
                <?php else: ?>
                  <div class="v-play-btn-circle"><i class="ph-fill ph-play"></i></div>
                <?php endif; ?>
              </div>
            </div>
            <div class="v-info-mini">
              <div class="v-title-mini"><?= htmlspecialchars($fv['title']) ?></div>
              <div class="v-status-mini <?= $done ? 'v-status--done' : 'v-status--ready' ?>">
                <?= $done ? '<i class="ph-bold ph-check"></i> Selesai' : '<i class="ph-fill ph-drop" style="color:#f59e0b;"></i> Tonton Cuan' ?>
              </div>
            </div>
          </a>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>


  <!-- ══════════════════════════════════════════════════════════
       2. SIDEJOB: PETERNAKAN LEBAH 3D REALISTIS (IDLE TYCOON)
       ══════════════════════════════════════════════════════════ -->
  <div class="sidejob-3d-card">
    <div class="sidejob-badge-tag"><i class="ph-fill ph-hexagon"></i> Sidejob Cuan Pasif</div>
    
    <div class="sidejob-title-row">
      <div class="sidejob-title">Peternakan Lebah 3D Realistis 🌿</div>
      <img src="/assets/game/beehive_wooden.png" alt="Sarang 3D" style="width:42px;height:42px;object-fit:contain;filter:drop-shadow(0 4px 6px rgba(0,0,0,0.3));">
    </div>

    <div class="sidejob-desc">
      Gunakan cuan hasil nonton videomu untuk beternak lebah 3D interaktif! Lebah akan bekerja otomatis memproduksi madu murni yang siap kamu panen dan cairkan ke rekening.
    </div>

    <!-- Live Farm Stats -->
    <div class="sidejob-metrics-grid">
      <div class="sidejob-metric-pill">
        <div class="sidejob-metric-val"><?= count($user_hives) ?> Kandang</div>
        <div class="sidejob-metric-lbl">Sarang Aktif</div>
      </div>
      <div class="sidejob-metric-pill">
        <div class="sidejob-metric-val"><?= $total_active_bees ?> Ekor</div>
        <div class="sidejob-metric-lbl">Lebah Bekerja</div>
      </div>
      <div class="sidejob-metric-pill">
        <div class="sidejob-metric-val">+<?= number_format($total_hourly_rate, 1) ?> ml</div>
        <div class="sidejob-metric-lbl">Produksi/Jam</div>
      </div>
    </div>

    <!-- Ready to Harvest Alert if available -->
    <?php if ($total_unharvested_honey >= 0.1): ?>
      <div style="background:rgba(254,243,199,0.95);border:2px solid #78350f;border-radius:14px;padding:8px 12px;display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;color:#78350f;box-shadow:0 3px 0 #78350f;">
        <span style="font-size:11px;font-weight:900;"><i class="ph-fill ph-drop" style="color:#d97706;"></i> Madu Siap Dipanen:</span>
        <span style="font-size:12px;font-weight:900;color:#b45309;"><?= number_format($total_unharvested_honey, 1) ?> ml</span>
      </div>
    <?php endif; ?>

    <!-- CTA Button directly to 3D World -->
    <a href="/farm" class="btn-open-3d-farm">
      <i class="ph-fill ph-binoculars" style="font-size:18px;"></i>
      <span>Buka Kebun Lebah 3D & Panen</span>
      <i class="ph-bold ph-arrow-right"></i>
    </a>

    <!-- Sub links for Shop & Stall -->
    <div class="sidejob-sub-links">
      <a href="/farm/shop" class="sidejob-sub-link"><i class="ph-bold ph-storefront"></i> Toko Bibit & Kandang</a>
      <a href="/farm/stall" class="sidejob-sub-link"><i class="ph-bold ph-coins"></i> Lapak Jual Madu</a>
    </div>
  </div>

  <!-- ══════════════════════════════════════════════════════════
       3. BENTO QUICK ACCESS MENU
       ══════════════════════════════════════════════════════════ -->
  <div style="font-size:13px;font-weight:900;color:#78350f;display:flex;align-items:center;gap:6px;">
    <i class="ph-fill ph-squares-four"></i> Menu Cepat & Fitur Cuan
  </div>

  <div class="bento-menu-grid">
    <!-- Tile 1: Nonton Video -->
    <a href="/videos" class="b-tile">
      <div class="b-tile__icon" style="background:linear-gradient(135deg,#f97316,#ea580c);">
        <i class="ph-fill ph-film-strip"></i>
      </div>
      <span class="b-tile__lbl">Tonton</span>
    </a>

    <!-- Tile 2: Sidejob 3D -->
    <a href="/farm" class="b-tile">
      <div class="b-tile__icon" style="background:linear-gradient(135deg,#16a34a,#15803d);">
        <i class="ph-fill ph-drop"></i>
      </div>
      <span class="b-tile__lbl">Ternak 3D</span>
    </a>

    <!-- Tile 3: Lapak Jual Madu -->
    <a href="/farm/stall" class="b-tile">
      <div class="b-tile__icon" style="background:linear-gradient(135deg,#eab308,#ca8a04);">
        <i class="ph-fill ph-storefront"></i>
      </div>
      <span class="b-tile__lbl">Lapak Madu</span>
    </a>

    <!-- Tile 4: Toko Sarang & Lebah -->
    <a href="/farm/shop" class="b-tile">
      <div class="b-tile__icon" style="background:linear-gradient(135deg,#0284c7,#0369a1);border-color:#075985;">
        <i class="ph-fill ph-shopping-bag"></i>
      </div>
      <span class="b-tile__lbl">Toko Bibit</span>
    </a>

    <!-- Tile 5: Absen Harian -->
    <a href="/checkin" class="b-tile">
      <div class="b-tile__icon" style="background:linear-gradient(135deg,#ec4899,#db2777);border-color:#9d174d;">
        <i class="ph-fill ph-calendar-check"></i>
      </div>
      <span class="b-tile__lbl">Absen</span>
    </a>

    <!-- Tile 6: Misi Harian -->
    <a href="/missions" class="b-tile">
      <div class="b-tile__icon" style="background:linear-gradient(135deg,#8b5cf6,#6d28d9);border-color:#4c1d95;">
        <i class="ph-fill ph-target"></i>
      </div>
      <span class="b-tile__lbl">Misi</span>
    </a>

    <!-- Tile 7: Squad Afiliasi -->
    <a href="/referral" class="b-tile">
      <div class="b-tile__icon" style="background:linear-gradient(135deg,#10b981,#047857);border-color:#064e3b;">
        <i class="ph-fill ph-users-three"></i>
      </div>
      <span class="b-tile__lbl">Squad</span>
    </a>

    <!-- Tile 8: Upgrade VIP -->
    <a href="/upgrade" class="b-tile">
      <div class="b-tile__icon" style="background:linear-gradient(135deg,#f59e0b,#b45309);">
        <i class="ph-fill ph-crown"></i>
      </div>
      <span class="b-tile__lbl">VIP</span>
    </a>
  </div>

  <!-- ── TIPS MASCOT BUZZY ── -->
  <div class="amber-tips-box">
    <div class="amber-tips-icon">💡</div>
    <div>
      <div style="font-size:12px;font-weight:900;color:#78350f;margin-bottom:2px;">Tips Cuan dari Buzzy</div>
      <div style="font-size:10.5px;font-weight:700;color:#78350f;line-height:1.35;">
        Tonton video sampai kuota harianmu penuh, lalu investasikan bonusnya untuk sewa sarang di <strong>Sidejob 3D</strong> agar madu terus terisi saat kamu tidur!
      </div>
    </div>
  </div>

  <!-- ── NOTIFIKASI INBOX ── -->
  <?php if (!empty($notif_preview)): ?>
    <div style="background:#fff;border:2.5px solid #78350f;border-radius:18px;padding:12px 14px;box-shadow:0 3px 0 #78350f;margin-bottom:16px;">
      <div style="font-size:12px;font-weight:900;color:#78350f;margin-bottom:6px;display:flex;align-items:center;gap:6px;">
        <i class="ph-fill ph-bell-ringing" style="color:#e11d48;"></i> Informasi & Pengumuman
      </div>
      <?php foreach ($notif_preview as $nt): ?>
        <div style="padding:4px 0;border-bottom:1px solid #fef3c7;">
          <div style="font-size:11.5px;font-weight:900;color:#1e293b;"><?= htmlspecialchars($nt['title']) ?></div>
          <div style="font-size:10px;font-weight:700;color:#64748b;"><?= htmlspecialchars(mb_substr($nt['message'], 0, 75)) ?>...</div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

</div>

<!-- Toast Container for Copy / Share feedback -->
<div id="cuan-toast"></div>

<script>
function copyRefCode(code) {
  if (!code || code === '-') {
    showCuanToast('Silakan masuk akun untuk melihat kode referral!');
    return;
  }
  if (navigator.clipboard && window.isSecureContext) {
    navigator.clipboard.writeText(code).then(() => {
      showCuanToast('🐝 Kode Referral disalin: ' + code);
    }).catch(() => fallbackCopy(code));
  } else {
    fallbackCopy(code);
  }
}

function fallbackCopy(text) {
  const ta = document.createElement('textarea');
  ta.value = text;
  ta.style.position = 'fixed';
  ta.style.opacity = '0';
  document.body.appendChild(ta);
  ta.focus();
  ta.select();
  try {
    document.execCommand('copy');
    showCuanToast('🐝 Kode Referral disalin: ' + text);
  } catch (err) {
    showCuanToast('Gagal menyalin kode referral.');
  }
  document.body.removeChild(ta);
}

function shareRefLink(url, code) {
  if (!code || code === '-') {
    window.location.href = '/login';
    return;
  }
  if (navigator.share) {
    navigator.share({
      title: 'LebahCuan - Nonton Video Dapat Cuan!',
      text: 'Yuk raih jutaan rupiah dari nonton video dan beternak lebah 3D! Gunakan kode referralku: ' + code,
      url: url
    }).catch(() => {});
  } else {
    copyRefCode(code);
  }
}

let toastTimer = null;
function showCuanToast(msg) {
  const t = document.getElementById('cuan-toast');
  if (!t) return;
  t.innerHTML = msg;
  t.classList.add('show');
  if (toastTimer) clearTimeout(toastTimer);
  toastTimer = setTimeout(() => {
    t.classList.remove('show');
  }, 2600);
}
</script>

<?php require dirname(__DIR__) . '/partials/footer.php'; ?>
