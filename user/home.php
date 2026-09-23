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

/* ── HERO BANNER WITH BEE MASCOT ── */
.amber-hero {
  background: linear-gradient(135deg, #b45309 0%, #d97706 35%, #f59e0b 75%, #fbbf24 100%);
  padding: 16px 14px 18px;
  position: relative; overflow: hidden;
  border-bottom: 4px solid #78350f;
  box-shadow: 0 6px 20px rgba(180,83,9,0.28);
}
/* Subtle honeycomb hexagon background pattern */
.amber-hero::before {
  content: ''; position: absolute; inset: 0;
  background-image: radial-gradient(#fff 15%, transparent 16%), radial-gradient(#fff 15%, transparent 16%);
  background-size: 24px 24px;
  background-position: 0 0, 12px 12px;
  opacity: 0.12; pointer-events: none;
}

.hero-profile-row {
  display: flex; align-items: center; justify-content: space-between;
  margin-bottom: 12px; position: relative; z-index: 2;
}
.hero-mascot-user {
  display: flex; align-items: center; gap: 10px;
}
.hero-mascot-avatar {
  width: 52px; height: 52px; background: #fff;
  border: 3px solid #78350f; border-radius: 18px;
  display: flex; align-items: center; justify-content: center;
  box-shadow: 0 4px 0 #78350f; flex-shrink: 0; position: relative;
  animation: mascotBob 4s ease-in-out infinite;
}
.hero-mascot-avatar img { width: 38px; height: 38px; object-fit: contain; }
@keyframes mascotBob {
  0%, 100% { transform: translateY(0); }
  50% { transform: translateY(-4px); }
}

.hero-user-details { line-height: 1.15; }
.hero-user-name { font-size: 16px; font-weight: 900; color: #fff; text-shadow: 0 2px 0 #78350f; }
.hero-user-tier {
  display: inline-flex; align-items: center; gap: 4px;
  background: rgba(120,53,15,0.45); border: 1.5px solid rgba(254,243,199,0.5);
  border-radius: 12px; padding: 2px 8px; font-size: 10px; font-weight: 900; color: #fef08a;
  margin-top: 3px;
}
.hero-action-link {
  background: #fff; border: 2.5px solid #78350f; border-radius: 12px;
  padding: 6px 12px; font-size: 11px; font-weight: 900; color: #78350f;
  text-decoration: none; box-shadow: 0 3px 0 #78350f;
}

/* ── MASCOT SPEECH BUBBLE ── */
.mascot-dialogue {
  background: rgba(255,255,255,0.96);
  border: 2.5px solid #78350f;
  border-radius: 16px;
  padding: 10px 12px;
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

/* 3-Column Balance & Honey Display */
.hero-stats-3col {
  display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px;
  margin-bottom: 12px; position: relative; z-index: 2;
}
.hero-stat-card {
  background: #ffffff; border: 2.5px solid #78350f; border-radius: 16px;
  padding: 8px 6px; text-align: center; box-shadow: 0 4px 0 #78350f;
  display: flex; flex-direction: column; align-items: center; justify-content: center;
}
.hero-stat-card__lbl { font-size: 8.5px; font-weight: 900; color: #78350f; text-transform: uppercase; margin-bottom: 2px; }
.hero-stat-card__val { font-size: 13px; font-weight: 900; line-height: 1.2; }
.hero-stat-card__val--wd  { color: #059669; }
.hero-stat-card__val--dep { color: #0284c7; }
.hero-stat-card__val--honey { color: #d97706; }

/* Quick Buttons Row */
.hero-quick-actions {
  display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px;
  position: relative; z-index: 2;
}
.hero-btn-act {
  display: flex; align-items: center; justify-content: center; gap: 4px;
  padding: 8px 4px; border-radius: 12px; font-size: 11px; font-weight: 900;
  text-decoration: none; border: 2.5px solid #78350f; font-family: 'Nunito', sans-serif;
  transition: transform 0.1s;
}
.hero-btn-act:active { transform: translateY(3px); box-shadow: none !important; }
.hero-btn-act--wd  { background: #4ade80; color: #064e3b; box-shadow: 0 3px 0 #78350f; }
.hero-btn-act--dep { background: #38bdf8; color: #0c4a6e; box-shadow: 0 3px 0 #78350f; }
.hero-btn-act--shop{ background: #fde047; color: #78350f; box-shadow: 0 3px 0 #78350f; }

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

/* ── MAIN TASK: NONTON VIDEO CARD ── */
.video-task-card {
  background: linear-gradient(180deg, #fffbeb 0%, #ffffff 100%);
  border: 3.5px solid #78350f;
  border-radius: 24px;
  box-shadow: 0 6px 0 #78350f;
  padding: 16px;
  margin-bottom: 18px;
  position: relative;
}
.video-task-head {
  display: flex; align-items: center; justify-content: space-between;
  margin-bottom: 10px;
}
.badge-main-job {
  display: inline-flex; align-items: center; gap: 5px;
  background: linear-gradient(135deg, #ea580c, #c2410c);
  color: #fff; font-size: 10px; font-weight: 900;
  padding: 3px 10px; border-radius: 12px; border: 2px solid #78350f;
  box-shadow: 0 2px 0 #78350f; text-transform: uppercase;
}
.quota-counter {
  font-size: 12px; font-weight: 900; color: #78350f;
  background: #fde68a; border: 2px solid #78350f;
  padding: 3px 9px; border-radius: 12px;
}

/* Progress bar */
.honey-progress-track {
  background: #fed7aa;
  border: 2px solid #78350f;
  border-radius: 14px;
  height: 16px;
  overflow: hidden;
  margin: 10px 0 8px;
  position: relative;
}
.honey-progress-fill {
  background: linear-gradient(90deg, #fbbf24 0%, #f59e0b 50%, #d97706 100%);
  height: 100%;
  border-radius: 12px;
  transition: width 0.6s cubic-bezier(0.34, 1.56, 0.64, 1);
  box-shadow: inset 0 -2px 0 rgba(0,0,0,0.2);
}
.video-task-sub {
  font-size: 11px; font-weight: 800; color: #78350f;
  display: flex; align-items: center; justify-content: space-between;
  margin-bottom: 14px;
}

.btn-start-watch {
  width: 100%; padding: 12px; border-radius: 18px;
  background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
  border: 3px solid #78350f; box-shadow: 0 5px 0 #78350f;
  color: #fff; font-size: 14px; font-weight: 900;
  text-decoration: none; display: flex; align-items: center; justify-content: center; gap: 8px;
  text-shadow: 0 1px 2px #78350f; transition: transform 0.1s;
  font-family: 'Nunito', sans-serif;
}
.btn-start-watch:active { transform: translateY(3px); box-shadow: 0 2px 0 #78350f; }

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
     HERO SECTION — PROFIL, MASKOT LEBAH & SALDO
     ══════════════════════════════════════════════════════════ -->
<div class="amber-hero">
  <div class="hero-profile-row">
    <div class="hero-mascot-user">
      <div class="hero-mascot-avatar">
        <img src="/assets/game/bee_worker.png" alt="Buzzy Lebah">
      </div>
      <div class="hero-user-details">
        <div class="hero-user-name"><?= htmlspecialchars($user['username']) ?> 🐝</div>
        <div class="hero-user-tier">
          <i class="ph-fill ph-crown"></i>
          <span><?= htmlspecialchars($membership_name) ?></span>
        </div>
      </div>
    </div>
    <?php if ($is_guest): ?>
      <a href="/login" class="hero-action-link">Masuk Akun</a>
    <?php else: ?>
      <a href="/profile" class="hero-action-link"><i class="ph-bold ph-gear"></i> Profil</a>
    <?php endif; ?>
  </div>

  <!-- Mascot Speech Bubble -->
  <div class="mascot-dialogue">
    <div class="mascot-dialogue__icon">🐝</div>
    <div class="mascot-dialogue__text">
      Hai! Buzzy siap temani kamu nonton video & raih cuan hari ini! Selesaikan kuota video lalu kembangkan <strong>Sidejob Ternak Lebah 3D</strong> kamu!
    </div>
  </div>

  <!-- 3-Column Wallet: Saldo Tarik, Saldo Depo, Stok Madu -->
  <div class="hero-stats-3col">
    <div class="hero-stat-card">
      <div class="hero-stat-card__lbl">Saldo Tarik</div>
      <div class="hero-stat-card__val hero-stat-card__val--wd">
        Rp <?= number_format((float)$user['balance_wd'], 0, ',', '.') ?>
      </div>
    </div>
    <div class="hero-stat-card">
      <div class="hero-stat-card__lbl">Saldo Depo</div>
      <div class="hero-stat-card__val hero-stat-card__val--dep">
        Rp <?= number_format((float)$user['balance_dep'], 0, ',', '.') ?>
      </div>
    </div>
    <div class="hero-stat-card">
      <div class="hero-stat-card__lbl">Stok Madu</div>
      <div class="hero-stat-card__val hero-stat-card__val--honey">
        <?= number_format((float)$user['honey_stock'], 1, ',', '.') ?> ml
      </div>
    </div>
  </div>

  <!-- Quick Action Buttons -->
  <div class="hero-quick-actions">
    <a href="/deposit" class="hero-btn-act hero-btn-act--dep">
      <i class="ph-bold ph-wallet"></i> Top Up
    </a>
    <a href="/withdraw" class="hero-btn-act hero-btn-act--wd">
      <i class="ph-bold ph-arrow-up-right"></i> Tarik Dana
    </a>
    <a href="/farm/stall" class="hero-btn-act hero-btn-act--shop">
      <i class="ph-bold ph-storefront"></i> Jual Madu
    </a>
  </div>
</div>

<div class="home-feed">

  <!-- ══════════════════════════════════════════════════════════
       1. TUGAS UTAMA: NONTON VIDEO BERHADIAH HARI INI
       ══════════════════════════════════════════════════════════ -->
  <div class="video-task-card">
    <div class="video-task-head">
      <span class="badge-main-job"><i class="ph-fill ph-film-strip"></i> Pekerjaan Utama</span>
      <span class="quota-counter">Kuota: <?= $watch_today ?>/<?= $watch_limit ?></span>
    </div>

    <div style="font-size:15px;font-weight:900;color:#78350f;margin-bottom:2px;">
      Tonton Video & Dapatkan Cuan 🎬
    </div>
    <div style="font-size:11px;font-weight:700;color:#94a3b8;margin-bottom:8px;">
      Selesaikan tugas nonton video harian untuk mengumpulkan reward Rupiah instan!
    </div>

    <!-- Progress bar -->
    <div class="honey-progress-track">
      <div class="honey-progress-fill" style="width: <?= $watch_pct ?>%;"></div>
    </div>

    <div class="video-task-sub">
      <span>Progres Hari Ini: <strong><?= $watch_pct ?>%</strong></span>
      <span><?= $watch_remaining > 0 ? "Sisa {$watch_remaining} video lagi" : "✓ Kuota Penuh!" ?></span>
    </div>

    <a href="/videos" class="btn-start-watch">
      <i class="ph-fill ph-play-circle" style="font-size:20px;"></i>
      <span><?= $watch_remaining > 0 ? 'Mulai Tonton Video Sekarang' : 'Buka Halaman Video' ?></span>
      <i class="ph-bold ph-arrow-right"></i>
    </a>

    <!-- Featured Videos Grid (Tugas Video Pilihan) -->
    <?php if (!empty($featured_videos)): ?>
      <div style="margin-top:16px;display:flex;align-items:center;justify-content:space-between;">
        <span style="font-size:12px;font-weight:900;color:#78350f;"><i class="ph-fill ph-sparkle" style="color:#f59e0b;"></i> Video Pilihan Hari Ini</span>
        <a href="/videos" style="font-size:10.5px;font-weight:900;color:#d97706;text-decoration:none;">Semua Video &rarr;</a>
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
    <?php endif; ?>
  </div>

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

<?php require dirname(__DIR__) . '/partials/footer.php'; ?>
