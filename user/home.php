<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/bootstrap.php';

$user = auth_user($pdo);
$is_guest = false;
if (!$user) {
    $is_guest = true;
    $user = [
        'id' => 0, 'username' => 'Peternak Tamu', 'balance_wd' => 0, 'balance_dep' => 0, 'honey_stock' => 0,
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

// ── JOBDESK 1: VIDEO WATCHING DATA ──────────────────────────────
$watch_limit = user_watch_limit($pdo, $user);
$watch_today = !$is_guest ? user_watch_today($pdo, $user) : 0;
$watch_remaining = max(0, $watch_limit - $watch_today);
$watch_pct = $watch_limit > 0 ? min(100.0, round(($watch_today / $watch_limit) * 100)) : 0;

// Daftar Video Unggulan Hari Ini
$featured_videos = [];
try {
    $uid = (int)$user['id'];
    $vStmt = $pdo->prepare("
        SELECT v.*,
               (SELECT COUNT(*) FROM watch_history wh WHERE wh.user_id = ? AND wh.video_id = v.id AND DATE(wh.watched_at) = CURDATE()) as watched_today
        FROM videos v
        WHERE v.is_active = 1
        ORDER BY watched_today ASC, v.sort_order ASC, v.id DESC
        LIMIT 6
    ");
    $vStmt->execute([$uid]);
    $featured_videos = $vStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable) {}

// Estimasi potensi cuan video yang belum ditonton
$est_video_cuan = 0;
foreach ($featured_videos as $fv) {
    if (empty($fv['watched_today'])) {
        $est_video_cuan += (float)$fv['reward_amount'];
    }
}

// ── JOBDESK 2: PETERNAKAN LEBAH (TYCOON) DATA ───────────────────
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
    if (!$membership_name) $membership_name = 'Member Reguler';
}

$pageTitle = 'Beranda — Tonton Video & Simulator Lebah Cuan';
$activePage = 'home';
require dirname(__DIR__) . '/partials/header.php';
?>

<style>
/* ══════════════════════════════════════════════════════════
   LEBAHCUAN — VIDEO FIRST & LIVING TYCOON HOME UI
   ══════════════════════════════════════════════════════════ */
body { background: #fff8f0 !important; font-family: 'Nunito', sans-serif; overflow-x: hidden; }

/* ── HERO TOP PROFILE & BALANCE HUD ── */
.home-top-hero {
  background: linear-gradient(135deg, #f59e0b 0%, #d97706 60%, #b45309 100%);
  padding: 16px 14px 18px;
  position: relative; overflow: hidden;
  border-bottom: 4px solid #78350f;
  box-shadow: 0 6px 18px rgba(180,83,9,0.25);
}
.hero-user-row {
  display: flex; align-items: center; justify-content: space-between;
  margin-bottom: 12px; position: relative; z-index: 2;
}
.hero-user-info { display: flex; align-items: center; gap: 10px; }
.hero-avatar {
  width: 46px; height: 46px; background: #fff;
  border: 2.5px solid #78350f; border-radius: 14px;
  display: flex; align-items: center; justify-content: center;
  box-shadow: 0 3px 0 #78350f; flex-shrink: 0;
}
.hero-avatar img { width: 32px; height: 32px; object-fit: contain; }
.hero-username { font-size: 16px; font-weight: 900; color: #fff; text-shadow: 0 2px 0 #78350f; line-height: 1.1; }
.hero-tier-tag {
  display: inline-flex; align-items: center; gap: 4px;
  background: rgba(255,255,255,0.25); border: 1.5px solid rgba(255,255,255,0.45);
  border-radius: 12px; padding: 1px 8px; font-size: 10px; font-weight: 900; color: #fff; margin-top: 3px;
}

/* 3-Pillar Balances */
.hero-balances-grid {
  display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px;
  margin-bottom: 12px; position: relative; z-index: 2;
}
.bal-card {
  background: #ffffff; border: 2.5px solid #78350f; border-radius: 14px;
  padding: 8px 6px; text-align: center; box-shadow: 0 3px 0 #78350f;
}
.bal-card__lbl { font-size: 8.5px; font-weight: 900; color: #64748b; text-transform: uppercase; margin-bottom: 2px; }
.bal-card__val { font-size: 12.5px; font-weight: 900; line-height: 1.2; }
.bal-card__val--dep { color: #1d4ed8; }
.bal-card__val--wd  { color: #059669; }
.bal-card__val--honey { color: #d97706; }

.hero-actions-row {
  display: grid; grid-template-columns: 1fr 1fr; gap: 8px;
  position: relative; z-index: 2;
}
.btn-hero-act {
  display: flex; align-items: center; justify-content: center; gap: 6px;
  padding: 9px 8px; border-radius: 12px; font-size: 11.5px; font-weight: 900;
  text-decoration: none; border: 2.5px solid #78350f; font-family: 'Nunito', sans-serif;
  transition: transform 0.1s;
}
.btn-hero-act:active { transform: translateY(2px); box-shadow: none !important; }
.btn-hero-act--dep { background: #38bdf8; color: #0c4a6e; box-shadow: 0 3px 0 #78350f; }
.btn-hero-act--wd  { background: #4ade80; color: #064e3b; box-shadow: 0 3px 0 #78350f; }

/* ── JOBDESK 1: VIDEO CUAN SHOWCASE (PRIMARY ACTIVE TASK) ── */
.jobdesk-section {
  padding: 18px 14px 0;
}
.jobdesk-header {
  display: flex; align-items: center; justify-content: space-between;
  margin-bottom: 12px;
}
.jobdesk-badge-pill {
  display: inline-flex; align-items: center; gap: 6px;
  background: #f43f5e; color: #fff;
  border: 2px solid #9f1239; border-radius: 10px;
  padding: 4px 10px; font-size: 11px; font-weight: 900;
  box-shadow: 0 3px 0 #9f1239;
}
.jobdesk-link-all {
  font-size: 12px; font-weight: 900; color: #f43f5e; text-decoration: none;
}

/* Video Quest Hero Banner */
.video-quest-card {
  background: #ffffff;
  border: 3.5px solid #0f172a;
  border-radius: 22px;
  box-shadow: 0 6px 0 #0f172a;
  padding: 16px;
  margin-bottom: 16px;
  position: relative;
  overflow: hidden;
}
.video-quest-top {
  display: flex; align-items: center; justify-content: space-between;
  margin-bottom: 8px;
}
.video-quest-title { font-size: 15px; font-weight: 900; color: #0f172a; }
.video-quest-count { font-size: 14px; font-weight: 900; color: #2563eb; }

.video-progress-track {
  height: 14px; background: #e2e8f0; border: 2px solid #0f172a; border-radius: 10px;
  overflow: hidden; margin-bottom: 10px;
}
.video-progress-fill {
  height: 100%;
  background: linear-gradient(90deg, #3b82f6, #2563eb);
  border-radius: 8px;
  transition: width 0.4s ease;
}

.video-quest-meta {
  display: flex; align-items: center; justify-content: space-between;
  font-size: 11px; font-weight: 800; color: #64748b;
}

/* Video Carousel / Grid in Home */
.home-video-grid {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 10px;
  margin-bottom: 20px;
}
.home-vcard {
  background: #ffffff;
  border: 3px solid #0f172a;
  border-radius: 18px;
  box-shadow: 0 4px 0 #0f172a;
  overflow: hidden;
  text-decoration: none;
  display: flex; flex-direction: column;
  transition: transform 0.1s;
}
.home-vcard:active { transform: translateY(2px); box-shadow: 0 1px 0 #0f172a; }
.home-vcard__thumb {
  width: 100%; height: 95px; position: relative; background: #000;
  overflow: hidden;
}
.home-vcard__thumb img {
  width: 100%; height: 100%; object-fit: cover;
}
.home-vcard__play-badge {
  position: absolute; inset: 0;
  display: flex; align-items: center; justify-content: center;
  background: rgba(0,0,0,0.25);
}
.home-vcard__play-btn {
  width: 32px; height: 32px; border-radius: 50%;
  background: rgba(255,255,255,0.9);
  border: 2px solid #0f172a;
  display: flex; align-items: center; justify-content: center;
  color: #0f172a; font-size: 16px;
}
.home-vcard__reward-tag {
  position: absolute; top: 6px; right: 6px;
  background: #10b981; color: #fff;
  border: 1.5px solid #064e3b; border-radius: 8px;
  padding: 2px 6px; font-size: 9.5px; font-weight: 900;
}
.home-vcard__duration-tag {
  position: absolute; bottom: 6px; left: 6px;
  background: rgba(15,23,42,0.85); color: #fff;
  border-radius: 6px; padding: 1px 5px; font-size: 9px; font-weight: 800;
}
.home-vcard__body {
  padding: 10px 8px; flex: 1; display: flex; flex-direction: column; justify-content: space-between;
}
.home-vcard__title {
  font-size: 11.5px; font-weight: 900; color: #0f172a; line-height: 1.3;
  display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;
  margin-bottom: 6px;
}
.home-vcard__btn {
  width: 100%; background: #fef08a; border: 2px solid #78350f;
  border-radius: 10px; padding: 5px; text-align: center;
  font-size: 10.5px; font-weight: 900; color: #78350f;
  box-shadow: 0 2px 0 #78350f;
}
.home-vcard--done { opacity: 0.65; pointer-events: none; }

/* ── JOBDESK 2: PETERNAKAN LEBAH (IDLE TYCOON SHOWCASE) ── */
.tycoon-hero-banner {
  background: linear-gradient(180deg, #38bdf8 0%, #7dd3fc 35%, #a7f3d0 65%, #86efac 100%);
  border: 3.5px solid #15803d;
  border-radius: 24px;
  box-shadow: 0 6px 0 #15803d, 0 10px 24px rgba(21,128,61,0.25);
  padding: 16px 14px;
  margin-bottom: 22px;
  position: relative;
  overflow: hidden;
}
.tycoon-banner-head {
  display: flex; align-items: center; justify-content: space-between;
  margin-bottom: 12px; position: relative; z-index: 2;
}
.tycoon-title-pill {
  display: inline-flex; align-items: center; gap: 6px;
  background: #ffffff; border: 2.5px solid #15803d; border-radius: 12px;
  padding: 4px 10px; font-size: 13px; font-weight: 900; color: #15803d;
  box-shadow: 0 3px 0 #15803d;
}
.tycoon-sub-label { font-size: 11px; font-weight: 800; color: #047857; }

/* Tycoon mini stats row */
.tycoon-mini-metrics {
  display: grid; grid-template-columns: repeat(3, 1fr); gap: 6px;
  margin-bottom: 14px; position: relative; z-index: 2;
}
.tycoon-metric-card {
  background: rgba(255,255,255,0.95); border: 2px solid #78350f;
  border-radius: 12px; padding: 6px 2px; text-align: center;
  box-shadow: 0 3px 0 #78350f;
}
.tycoon-metric-card__val { font-size: 13px; font-weight: 900; color: #b45309; }
.tycoon-metric-card__lbl { font-size: 8.5px; font-weight: 900; color: #64748b; text-transform: uppercase; }

/* Big Tycoon Enter Button */
.btn-enter-tycoon {
  width: 100%;
  background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
  border: 3px solid #78350f;
  border-radius: 16px;
  box-shadow: 0 5px 0 #78350f;
  padding: 12px;
  color: #fff; font-size: 14px; font-weight: 900;
  display: flex; align-items: center; justify-content: center; gap: 8px;
  text-decoration: none; font-family: 'Nunito', sans-serif;
  text-shadow: 0 1px 0 #78350f;
  transition: transform 0.1s;
}
.btn-enter-tycoon:active { transform: translateY(3px); box-shadow: 0 2px 0 #78350f; }

/* Tycoon Sub-Shortcuts */
.tycoon-shortcuts-row {
  display: grid; grid-template-columns: 1fr 1fr; gap: 8px;
  margin-top: 8px; position: relative; z-index: 2;
}
.btn-tycoon-sub {
  background: #ffffff; border: 2px solid #78350f; border-radius: 12px;
  padding: 8px; font-size: 11px; font-weight: 900; color: #78350f;
  text-decoration: none; text-align: center; display: flex; align-items: center; justify-content: center; gap: 4px;
  box-shadow: 0 3px 0 #78350f;
}

/* ── BENTO QUICK ACCESS MENU ── */
.home-bento-grid {
  display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px;
  margin-bottom: 24px;
}
.bento-card {
  border: 2.5px solid #0f172a; border-radius: 16px;
  box-shadow: 0 4px 0 #0f172a; text-decoration: none;
  display: flex; flex-direction: column; align-items: center; justify-content: center;
  height: 76px; padding: 6px; text-align: center;
  transition: transform 0.1s;
}
.bento-card:active { transform: translateY(2px); box-shadow: 0 1px 0 #0f172a; }
.bento-card i { font-size: 24px; color: #fff; margin-bottom: 2px; }
.bento-card span { font-size: 10px; font-weight: 900; color: #fff; line-height: 1.1; }
</style>

<!-- ══════════════════════════════════════════════════════════
     TOP HERO — FARMER PROFILE & REAL FINANCIAL BALANCES
     ══════════════════════════════════════════════════════════ -->
<div class="home-top-hero">
  <div class="hero-user-row">
    <div class="hero-user-info">
      <div class="hero-avatar">
        <img src="/assets/game/bee_queen.png" onerror="this.src='/assets/game/bee_worker.png'" alt="User">
      </div>
      <div>
        <div class="hero-username"><?= htmlspecialchars($user['username']) ?> 👑</div>
        <div class="hero-tier-tag">
          <i class="ph-fill ph-crown"></i>
          <span><?= htmlspecialchars($membership_name) ?></span>
        </div>
      </div>
    </div>
    <?php if ($is_guest): ?>
      <a href="/login" class="btn-hero-act" style="background:#fff;color:#78350f;">Login</a>
    <?php else: ?>
      <a href="/profile" class="btn-hero-act" style="background:#fff;color:#78350f;">
        <i class="ph-bold ph-gear"></i> Akun
      </a>
    <?php endif; ?>
  </div>

  <!-- 3-Pillar Real Balances Card -->
  <div class="hero-balances-grid">
    <div class="bal-card">
      <div class="bal-card__lbl">Saldo Beli</div>
      <div class="bal-card__val bal-card__val--dep">
        Rp <?= number_format((float)$user['balance_dep'], 0, ',', '.') ?>
      </div>
    </div>
    <div class="bal-card">
      <div class="bal-card__lbl">Saldo Tarik</div>
      <div class="bal-card__val bal-card__val--wd">
        Rp <?= number_format((float)$user['balance_wd'], 0, ',', '.') ?>
      </div>
    </div>
    <div class="bal-card">
      <div class="bal-card__lbl">Stok Madu</div>
      <div class="bal-card__val bal-card__val--honey">
        <?= number_format((float)$user['honey_stock'], 1, ',', '.') ?> ml
      </div>
    </div>
  </div>

  <!-- Quick Action Buttons -->
  <div class="hero-actions-row">
    <a href="/deposit" class="btn-hero-act btn-hero-act--dep">
      <i class="ph-bold ph-wallet"></i> Top Up Saldo
    </a>
    <a href="/withdraw" class="btn-hero-act btn-hero-act--wd">
      <i class="ph-bold ph-arrow-up-right"></i> Tarik Dana (WD)
    </a>
  </div>
</div>

<div class="jobdesk-section">

  <!-- ══════════════════════════════════════════════════════════
       JOBDESK 1: NONTON VIDEO BERHADIAH CUAN (PRIMARY JOB)
       ══════════════════════════════════════════════════════════ -->
  <div class="jobdesk-header">
    <div class="jobdesk-badge-pill">
      <i class="ph-fill ph-film-strip"></i> JOBDESK 1: NONTON VIDEO
    </div>
    <a href="/videos" class="jobdesk-link-all">Lihat Semua (<?= count($featured_videos) ?>) &rarr;</a>
  </div>

  <!-- Quest Progress Card -->
  <div class="video-quest-card">
    <div class="video-quest-top">
      <div class="video-quest-title">
        <i class="ph-fill ph-target text-danger"></i> Target Tontonan Hari Ini
      </div>
      <div class="video-quest-count">
        <?= $watch_today ?> / <?= $watch_limit ?> Selesai
      </div>
    </div>

    <!-- Progress Track -->
    <div class="video-progress-track">
      <div class="video-progress-fill" style="width: <?= $watch_pct ?>%;"></div>
    </div>

    <div class="video-quest-meta">
      <span>Sisa Kuota: <strong><?= $watch_remaining ?> Video</strong></span>
      <span style="color:#059669;">
        Estimasi Reward: <strong>+Rp <?= number_format($est_video_cuan, 0, ',', '.') ?></strong>
      </span>
    </div>
  </div>

  <!-- Video Grid: Langsung Nonton dari Beranda -->
  <div class="home-video-grid">
    <?php if (empty($featured_videos)): ?>
      <div style="grid-column:span 2;text-align:center;padding:20px;background:#fff;border:2px dashed #cbd5e1;border-radius:16px;">
        <div style="font-size:12px;font-weight:700;color:#64748b;">Belum ada video aktif hari ini.</div>
      </div>
    <?php else: ?>
      <?php foreach ($featured_videos as $fv): 
        $done = (bool)$fv['watched_today'];
        $blocked = !$done && ($watch_today >= $watch_limit);
        $href = ($done || $blocked) ? 'javascript:void(0)' : '/watch?id=' . $fv['id'];
      ?>
        <a href="<?= $href ?>" class="home-vcard <?= $done ? 'home-vcard--done' : '' ?>">
          <div class="home-vcard__thumb">
            <img src="<?= yt_thumb($fv['youtube_id']) ?>" alt="<?= htmlspecialchars($fv['title']) ?>" loading="lazy" onerror="this.src='https://img.youtube.com/vi/<?= $fv['youtube_id'] ?>/hqdefault.jpg'">
            <div class="home-vcard__play-badge">
              <?php if ($done): ?>
                <i class="ph-fill ph-check-circle" style="color:#10b981;font-size:32px;"></i>
              <?php else: ?>
                <div class="home-vcard__play-btn"><i class="ph-fill ph-play"></i></div>
              <?php endif; ?>
            </div>
            <div class="home-vcard__reward-tag">
              <?= $done ? 'Selesai' : '+'.format_rp((float)$fv['reward_amount']) ?>
            </div>
            <div class="home-vcard__duration-tag">
              <i class="ph-bold ph-clock"></i> <?= $fv['watch_duration'] ?>s
            </div>
          </div>
          <div class="home-vcard__body">
            <div class="home-vcard__title"><?= htmlspecialchars($fv['title']) ?></div>
            <div class="home-vcard__btn" style="<?= $done ? 'background:#e2e8f0;border-color:#94a3b8;color:#64748b;' : '' ?>">
              <?= $done ? '✓ Sudah Ditonton' : ($blocked ? 'Kuota Penuh' : 'Tonton Sekarang') ?>
            </div>
          </div>
        </a>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <!-- ══════════════════════════════════════════════════════════
       JOBDESK 2: SIMULATOR PETERNAKAN LEBAH (IDLE TYCOON)
       ══════════════════════════════════════════════════════════ -->
  <div class="jobdesk-header">
    <div class="jobdesk-badge-pill" style="background:#d97706;border-color:#78350f;">
      <i class="ph-fill ph-drop"></i> JOBDESK 2: PETERNAKAN LEBAH
    </div>
    <span style="font-size:11px;font-weight:800;color:#b45309;">Simulator Pasif</span>
  </div>

  <div class="tycoon-hero-banner">
    <div class="tycoon-banner-head">
      <div class="tycoon-title-pill">
        <i class="ph-fill ph-plant"></i> Kebun Sarang Madu
      </div>
      <div class="tycoon-sub-label">
        <?= count($user_hives) ?> Sarang Aktif
      </div>
    </div>

    <!-- Mini Metrics -->
    <div class="tycoon-mini-metrics">
      <div class="tycoon-metric-card">
        <div class="tycoon-metric-card__val"><?= $total_active_bees ?> Ekor</div>
        <div class="tycoon-metric-card__lbl">Lebah Bekerja</div>
      </div>
      <div class="tycoon-metric-card">
        <div class="tycoon-metric-card__val">+<?= number_format($total_hourly_rate, 1) ?> ml</div>
        <div class="tycoon-metric-card__lbl">Produksi/Jam</div>
      </div>
      <div class="tycoon-metric-card">
        <div class="tycoon-metric-card__val" style="color:#d97706;">
          <?= number_format($total_unharvested_honey, 1) ?> ml
        </div>
        <div class="tycoon-metric-card__lbl">Siap Panen</div>
      </div>
    </div>

    <!-- Enter Simulator Button -->
    <a href="/farm" class="btn-enter-tycoon">
      <i class="ph-fill ph-drop" style="font-size:20px;"></i>
      <span>MASUK KE SIMULATOR PETERNAKAN LEBAH</span>
      <i class="ph-bold ph-arrow-right"></i>
    </a>

    <!-- Tycoon Shortcuts -->
    <div class="tycoon-shortcuts-row">
      <a href="/farm/stall" class="btn-tycoon-sub">
        <i class="ph-fill ph-storefront" style="color:#059669;"></i> Lapak Jual Madu
      </a>
      <a href="/farm/shop" class="btn-tycoon-sub">
        <i class="ph-fill ph-shopping-bag" style="color:#d97706;"></i> Toko Bibit &amp; Sarang
      </a>
    </div>
  </div>

  <!-- ══════════════════════════════════════════════════════════
       BENTO QUICK ACCESS MENU
       ══════════════════════════════════════════════════════════ -->
  <div style="font-size:13px;font-weight:900;color:#0f172a;margin-bottom:10px;display:flex;align-items:center;gap:6px;">
    <i class="ph-fill ph-squares-four"></i> Menu Cepat Lainnya
  </div>

  <div class="home-bento-grid">
    <a href="/missions" class="bento-card" style="background:linear-gradient(135deg,#f43f5e,#e11d48);">
      <i class="ph-fill ph-target"></i>
      <span>Misi Cuan</span>
    </a>
    <a href="/checkin" class="bento-card" style="background:linear-gradient(135deg,#8b5cf6,#7c3aed);">
      <i class="ph-fill ph-calendar-check"></i>
      <span>Absen</span>
    </a>
    <a href="/referral" class="bento-card" style="background:linear-gradient(135deg,#10b981,#059669);">
      <i class="ph-fill ph-users-three"></i>
      <span>Squad</span>
    </a>
    <a href="/redeem" class="bento-card" style="background:linear-gradient(135deg,#06b6d4,#0891b2);">
      <i class="ph-fill ph-gift"></i>
      <span>Redeem</span>
    </a>
    <a href="/upgrade" class="bento-card" style="background:linear-gradient(135deg,#f59e0b,#d97706);">
      <i class="ph-fill ph-crown"></i>
      <span>VIP Level</span>
    </a>
    <a href="/chicky" class="bento-card" style="background:linear-gradient(135deg,#eab308,#ca8a04);">
      <i class="ph-fill ph-game-controller"></i>
      <span>Chicky</span>
    </a>
    <a href="/luckycard" class="bento-card" style="background:linear-gradient(135deg,#ec4899,#db2777);">
      <i class="ph-fill ph-cards"></i>
      <span>Lucky Card</span>
    </a>
    <?php if (setting($pdo, 'investment_enabled', '1') === '1'): ?>
      <a href="/invest" class="bento-card" style="background:linear-gradient(135deg,#64748b,#475569);">
        <i class="ph-fill ph-trend-up"></i>
        <span>Invest</span>
      </a>
    <?php else: ?>
      <a href="/history" class="bento-card" style="background:linear-gradient(135deg,#64748b,#475569);">
        <i class="ph-fill ph-clock-counter-clockwise"></i>
        <span>Riwayat</span>
      </a>
    <?php endif; ?>
  </div>

  <!-- Notifikasi / Pengumuman -->
  <?php if (!empty($notif_preview)): ?>
    <div style="background:#fff;border:3px solid #0f172a;border-radius:20px;padding:14px;box-shadow:0 4px 0 #0f172a;margin-bottom:18px;">
      <div style="font-size:12.5px;font-weight:900;color:#0f172a;margin-bottom:8px;display:flex;align-items:center;gap:6px;">
        <i class="ph-fill ph-bell-ringing" style="color:#e11d48;"></i> Pengumuman Terbaru
      </div>
      <?php foreach ($notif_preview as $npv): ?>
        <div style="padding:6px 0;border-bottom:1px solid #f1f5f9;">
          <div style="font-size:12px;font-weight:900;color:#1e293b;"><?= htmlspecialchars($npv['title']) ?></div>
          <div style="font-size:10.5px;font-weight:700;color:#64748b;"><?= htmlspecialchars(mb_substr($npv['message'], 0, 90)) ?>...</div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

</div>

<?php require dirname(__DIR__) . '/partials/footer.php'; ?>
