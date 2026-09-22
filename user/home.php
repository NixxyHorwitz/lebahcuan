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

// Data Peternakan Lebah User
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

// Katalog Spesies Lebah & Kandang Unggulan
$featured_bees = [];
$featured_hives = [];
try {
    $featured_bees = $pdo->query("SELECT * FROM bee_types_master WHERE is_active = 1 ORDER BY sort_order ASC, id ASC LIMIT 4")->fetchAll(PDO::FETCH_ASSOC);
    $featured_hives = $pdo->query("SELECT * FROM bee_hives_master WHERE is_active = 1 ORDER BY sort_order ASC, id ASC LIMIT 3")->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable) {}

// Notifikasi Preview
$notif_preview = [];
$notif_unread = 0;
if (!$is_guest) {
    try {
        $uid = $user['id'];
        $np = $pdo->prepare("
            SELECT n.* FROM notifications n LEFT JOIN notification_reads nr ON nr.notification_id=n.id AND nr.user_id=?
            WHERE nr.id IS NULL AND (n.target_type='all' OR (n.target_user_ids IS NOT NULL AND JSON_CONTAINS(n.target_user_ids, JSON_QUOTE(?))))
              AND (n.expires_at IS NULL OR n.expires_at > NOW()) ORDER BY n.created_at DESC LIMIT 3
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
    if (!$membership_name) $membership_name = 'Peternak Pemula';
}

$pageTitle = 'Peternakan Lebah Cuan';
$activePage = 'home';
require dirname(__DIR__) . '/partials/header.php';
?>

<style>
/* ══════════════════════════════════════════════════════════
   LEBAHCUAN — CASUAL BEE FARM LIVING UI
   ══════════════════════════════════════════════════════════ */
body { background: #fef3c7 !important; font-family: 'Nunito', sans-serif; overflow-x: hidden; }

/* ── HERO SECTION ── */
.farm-hero {
  background: linear-gradient(135deg, #f59e0b 0%, #d97706 60%, #b45309 100%);
  padding: 16px 14px 18px;
  position: relative; overflow: hidden;
  border-bottom: 4px solid #78350f;
  box-shadow: 0 6px 18px rgba(180,83,9,0.25);
}
.farm-hero::before {
  content: ''; position: absolute; inset: 0;
  background: radial-gradient(circle, rgba(255,255,255,0.15) 10%, transparent 10%);
  background-size: 26px 26px; pointer-events: none;
}
.hero-greet-row {
  display: flex; align-items: center; justify-content: space-between;
  margin-bottom: 14px; position: relative; z-index: 2;
}
.hero-farmer-info { display: flex; align-items: center; gap: 10px; }
.hero-avatar-box {
  width: 48px; height: 48px; background: #fff;
  border: 3px solid #78350f; border-radius: 16px;
  display: flex; align-items: center; justify-content: center;
  box-shadow: 0 4px 0 #78350f; flex-shrink: 0; position: relative;
}
.hero-avatar-box img { width: 34px; height: 34px; object-fit: contain; }
.hero-farmer-name { font-size: 16px; font-weight: 900; color: #fff; text-shadow: 0 2px 0 #78350f; line-height: 1.1; }
.hero-badge-tier {
  display: inline-flex; align-items: center; gap: 4px;
  background: rgba(255,255,255,0.25); border: 1.5px solid rgba(255,255,255,0.45);
  border-radius: 14px; padding: 2px 8px; font-size: 10px; font-weight: 900; color: #fff;
  margin-top: 3px;
}
.hero-login-link {
  background: #fff; border: 2.5px solid #78350f; border-radius: 12px;
  padding: 6px 12px; font-size: 11px; font-weight: 900; color: #78350f;
  text-decoration: none; box-shadow: 0 3px 0 #78350f;
}

/* 3-Column Balance & Honey Display */
.hero-stats-3col {
  display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px;
  margin-bottom: 12px; position: relative; z-index: 2;
}
.hero-stat-card {
  background: #ffffff; border: 3px solid #78350f; border-radius: 16px;
  padding: 8px 6px; text-align: center; box-shadow: 0 4px 0 #78350f;
  display: flex; flex-direction: column; align-items: center; justify-content: center;
}
.hero-stat-card__lbl { font-size: 9px; font-weight: 900; color: #64748b; text-transform: uppercase; margin-bottom: 2px; }
.hero-stat-card__val { font-size: 13px; font-weight: 900; line-height: 1.2; }
.hero-stat-card__val--dep { color: #1d4ed8; }
.hero-stat-card__val--wd  { color: #059669; }
.hero-stat-card__val--honey { color: #d97706; }

/* Action Buttons Row */
.hero-quick-actions {
  display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px;
  position: relative; z-index: 2;
}
.hero-btn-act {
  display: flex; align-items: center; justify-content: center; gap: 4px;
  padding: 9px 6px; border-radius: 12px; font-size: 11px; font-weight: 900;
  text-decoration: none; border: 2.5px solid #78350f; font-family: 'Nunito', sans-serif;
  transition: transform 0.1s;
}
.hero-btn-act:active { transform: translateY(3px); box-shadow: none !important; }
.hero-btn-act--dep { background: #38bdf8; color: #0c4a6e; box-shadow: 0 3px 0 #78350f; }
.hero-btn-act--wd  { background: #4ade80; color: #064e3b; box-shadow: 0 3px 0 #78350f; }
.hero-btn-act--shop{ background: #fde047; color: #78350f; box-shadow: 0 3px 0 #78350f; }

/* ── LIVING APIARY SHOWCASE CARD (TAMAN LEBAH HIDUP DI HOME) ── */
.home-apiary-showcase {
  margin: 14px 14px 16px;
  background: linear-gradient(180deg, #38bdf8 0%, #7dd3fc 40%, #a7f3d0 60%, #86efac 100%);
  border: 3.5px solid #15803d; border-radius: 24px;
  box-shadow: 0 6px 0 #15803d, 0 10px 24px rgba(21,128,61,0.25);
  padding: 16px 14px; position: relative; overflow: hidden;
}
/* Awan & Partikel */
.home-cloud {
  position: absolute; background: #fff; border-radius: 50px; opacity: 0.85;
  animation: homeCloudMove 18s linear infinite; pointer-events: none;
}
.home-cloud::before { content: ''; position: absolute; background: #fff; border-radius: 50%; width: 30px; height: 30px; top: -14px; left: 10px; }
@keyframes homeCloudMove { from { transform: translateX(-120px); } to { transform: translateX(450px); } }

.home-flying-bee {
  position: absolute; width: 32px; height: 32px; z-index: 3;
  pointer-events: none; filter: drop-shadow(0 4px 4px rgba(0,0,0,0.2));
  animation: homeBeeFlight 7s ease-in-out infinite;
}
.home-flying-bee img { width: 100%; height: 100%; object-fit: contain; }
@keyframes homeBeeFlight {
  0%, 100% { transform: translate(0, 0) rotate(0deg); }
  50%      { transform: translate(45px, -18px) rotate(15deg); }
}

.apiary-showcase-head {
  display: flex; align-items: center; justify-content: space-between;
  position: relative; z-index: 2; margin-bottom: 12px;
}
.apiary-showcase-title {
  font-size: 15px; font-weight: 900; color: #1e3a8a;
  display: inline-flex; align-items: center; gap: 6px;
  background: #fff; border: 2.5px solid #1e3a8a; border-radius: 12px;
  padding: 4px 10px; box-shadow: 0 3px 0 #1e3a8a;
}
.apiary-showcase-sub { font-size: 11px; font-weight: 800; color: #047857; text-shadow: 0 1px 1px rgba(255,255,255,0.8); }

.apiary-metrics-row {
  display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px;
  position: relative; z-index: 2; margin-bottom: 14px;
}
.apiary-metric-box {
  background: rgba(255,255,255,0.95); border: 2.5px solid #78350f; border-radius: 14px;
  padding: 8px 4px; text-align: center; box-shadow: 0 3px 0 #78350f;
}
.apiary-metric-box__val { font-size: 14px; font-weight: 900; color: #b45309; }
.apiary-metric-box__lbl { font-size: 8.5px; font-weight: 900; color: #64748b; text-transform: uppercase; }

.btn-enter-farm {
  width: 100%; padding: 12px; border-radius: 16px;
  font-size: 14px; font-weight: 900; color: #fff;
  background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
  border: 3px solid #78350f; box-shadow: 0 5px 0 #78350f;
  text-decoration: none; display: flex; align-items: center; justify-content: center; gap: 8px;
  position: relative; z-index: 2; font-family: 'Nunito', sans-serif;
  transition: transform 0.1s;
}
.btn-enter-farm:active { transform: translateY(3px); box-shadow: 0 2px 0 #78350f; }

/* ── BENTO QUICK ACTIONS ── */
.home-container { padding: 0 14px 100px; }
.section-title-bar {
  font-size: 14px; font-weight: 900; color: #78350f;
  margin-bottom: 10px; display: flex; align-items: center; gap: 6px;
}
.bento-farm-grid {
  display: grid; grid-template-columns: repeat(4, 1fr);
  gap: 8px; margin-bottom: 18px;
}
.b-card {
  border: 3px solid #78350f; border-radius: 18px;
  box-shadow: 0 4px 0 #78350f; text-decoration: none;
  display: flex; flex-direction: column; position: relative;
  overflow: hidden; transition: transform 0.1s; -webkit-tap-highlight-color: transparent;
}
.b-card:active { transform: translateY(3px); box-shadow: 0 1px 0 #78350f; }

/* BIG CARD: 2 cols x 2 rows */
.b-card--big {
  grid-column: span 2; grid-row: span 2;
  background: linear-gradient(145deg, #fbbf24 0%, #f59e0b 60%, #d97706 100%);
  padding: 14px; justify-content: space-between; min-height: 155px;
}
.b-card--big__img {
  width: 72px; height: 72px; object-fit: contain; align-self: flex-end;
  filter: drop-shadow(0 6px 6px rgba(0,0,0,0.25)); margin-top: -6px;
}
.b-card--big__title { font-size: 16px; font-weight: 900; color: #fff; text-shadow: 0 2px 0 #78350f; line-height: 1.1; margin-bottom: 2px; }
.b-card--big__sub { font-size: 10px; font-weight: 800; color: #fef3c7; }

/* WIDE CARD: 2 cols x 1 row */
.b-card--wide {
  grid-column: span 2;
  background: linear-gradient(135deg, #34d399, #059669);
  padding: 10px 12px; flex-direction: row; align-items: center; justify-content: space-between;
  border-color: #064e3b; box-shadow: 0 4px 0 #064e3b;
}
.b-card--wide__title { font-size: 13px; font-weight: 900; color: #fff; line-height: 1.1; margin-bottom: 2px; }
.b-card--wide__sub { font-size: 9px; font-weight: 800; color: #d1fae5; }
.b-card--wide__icon { font-size: 28px; color: #fff; line-height: 1; }

/* SM CARDS: 1 col x 1 row */
.b-card--sm {
  height: 74px; align-items: center; justify-content: center; gap: 4px; padding: 4px;
}
.b-card--sm i { font-size: 24px; color: #fff; }
.b-card--sm__lbl { font-size: 9.5px; font-weight: 900; color: #fff; text-align: center; }

/* ── FEATURED BEES & STALLS SHOWCASE ── */
.featured-bees-scroll {
  display: flex; gap: 10px; overflow-x: auto; padding-bottom: 6px; margin-bottom: 18px;
  -webkit-overflow-scrolling: touch;
}
.featured-bees-scroll::-webkit-scrollbar { display: none; }
.bee-feat-card {
  flex: 0 0 135px; background: #fff; border: 3px solid #78350f; border-radius: 18px;
  box-shadow: 0 4px 0 #78350f; padding: 12px 10px; text-align: center; text-decoration: none;
  display: flex; flex-direction: column; align-items: center; justify-content: space-between;
}
.bee-feat-card__img { width: 54px; height: 54px; object-fit: contain; margin-bottom: 6px; }
.bee-feat-card__name { font-size: 11px; font-weight: 900; color: #1e293b; line-height: 1.2; margin-bottom: 2px; }
.bee-feat-card__prod { font-size: 9px; font-weight: 800; color: #059669; }
.bee-feat-card__price { font-size: 11px; font-weight: 900; color: #b45309; margin-top: 4px; }

/* ── INFO BANNER TIPS ── */
.farm-tips-card {
  background: #fff; border: 3px solid #78350f; border-radius: 20px;
  box-shadow: 0 5px 0 #78350f; padding: 14px 16px; margin-bottom: 16px;
  display: flex; gap: 12px; align-items: center;
}
.farm-tips-icon {
  width: 44px; height: 44px; background: #fef3c7; border: 2.5px solid #d97706;
  border-radius: 14px; display: flex; align-items: center; justify-content: center;
  font-size: 22px; flex-shrink: 0;
}
</style>

<!-- ══════════════════════════════════════════════════════════
     HERO SECTION — PROFIL PETERNAK & SALDO CUAN
     ══════════════════════════════════════════════════════════ -->
<div class="farm-hero">
  <div class="hero-greet-row">
    <div class="hero-farmer-info">
      <div class="hero-avatar-box">
        <img src="/assets/game/bee_worker.png" alt="Bee">
      </div>
      <div>
        <div class="hero-farmer-name"><?= htmlspecialchars($user['username']) ?> 🐝</div>
        <div class="hero-badge-tier">
          <i class="ph-fill ph-crown"></i>
          <span><?= htmlspecialchars($membership_name) ?></span>
        </div>
      </div>
    </div>
    <?php if ($is_guest): ?>
      <a href="/login" class="hero-login-link">Login Peternak</a>
    <?php else: ?>
      <a href="/profile" class="hero-login-link"><i class="ph-bold ph-gear"></i> Akun</a>
    <?php endif; ?>
  </div>

  <!-- 3-Column Balances: Saldo Deposit, Saldo Penarikan, Stok Madu -->
  <div class="hero-stats-3col">
    <div class="hero-stat-card">
      <div class="hero-stat-card__lbl">Saldo Deposit</div>
      <div class="hero-stat-card__val hero-stat-card__val--dep">
        Rp <?= number_format((float)$user['balance_dep'], 0, ',', '.') ?>
      </div>
    </div>
    <div class="hero-stat-card">
      <div class="hero-stat-card__lbl">Saldo Tarik</div>
      <div class="hero-stat-card__val hero-stat-card__val--wd">
        Rp <?= number_format((float)$user['balance_wd'], 0, ',', '.') ?>
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
    <a href="/farm" class="hero-btn-act hero-btn-act--shop">
      <i class="ph-bold ph-storefront"></i> Jual Madu
    </a>
  </div>
</div>

<!-- ══════════════════════════════════════════════════════════
     LIVING MINI APIARY SHOWCASE (TAMAN LEBAH HIDUP DI HOME)
     ══════════════════════════════════════════════════════════ -->
<div class="home-apiary-showcase">
  <div class="home-cloud" style="width:70px;height:24px;top:10px;"></div>
  <div class="home-flying-bee" style="top:25px;right:20px;">
    <img src="/assets/game/bee_worker.png" alt="Bee">
  </div>

  <div class="apiary-showcase-head">
    <div class="apiary-showcase-title">
      <i class="ph-fill ph-flower"></i> Kebun Sarang Saya
    </div>
    <div class="apiary-showcase-sub">
      <?= count($user_hives) ?> Kandang Aktif
    </div>
  </div>

  <!-- Status Metrik Peternakan -->
  <div class="apiary-metrics-row">
    <div class="apiary-metric-box">
      <div class="apiary-metric-box__val"><?= $total_active_bees ?> Ekor</div>
      <div class="apiary-metric-box__lbl">Lebah Bekerja</div>
    </div>
    <div class="apiary-metric-box">
      <div class="apiary-metric-box__val">+<?= number_format($total_hourly_rate, 1) ?> ml</div>
      <div class="apiary-metric-box__lbl">Produksi/Jam</div>
    </div>
    <div class="apiary-metric-box">
      <div class="apiary-metric-box__val" style="color:#b45309;">
        <?= number_format($total_unharvested_honey, 1) ?> ml
      </div>
      <div class="apiary-metric-box__lbl">Siap Panen</div>
    </div>
  </div>

  <!-- Tombol Utama Masuk Peternakan -->
  <a href="/farm" class="btn-enter-farm">
    <i class="ph-fill ph-drop" style="font-size:18px;"></i>
    <span>Masuk ke Peternakan & Panen Madu</span>
    <i class="ph-bold ph-arrow-right"></i>
  </a>
</div>

<!-- ══════════════════════════════════════════════════════════
     BENTO QUICK ACTIONS — SISTEM PETERNAKAN LEBAH
     ══════════════════════════════════════════════════════════ -->
<div class="home-container">
  <div class="section-title-bar">
    <i class="ph-fill ph-squares-four"></i> Menu Cepat Peternak
  </div>

  <div class="bento-farm-grid">
    <!-- BIG CARD: Peternakan Lebah -->
    <a href="/farm" class="b-card b-card--big">
      <img src="/assets/game/beehive_royal.png" class="b-card--big__img" alt="Farm">
      <div>
        <div class="b-card--big__title">Peternakan Lebah 🍯</div>
        <div class="b-card--big__sub">Inspeksi sarang hexagon & panen madu murni!</div>
      </div>
    </a>

    <!-- WIDE CARD: Pasar & Lapak Madu -->
    <a href="/farm" class="b-card b-card--wide">
      <div>
        <div class="b-card--wide__title">Lapak Jual Madu 🏪</div>
        <div class="b-card--wide__sub">Cairkan madu langsung jadi Rupiah ke Saldo Tarik</div>
      </div>
      <i class="ph-fill ph-coins b-card--wide__icon"></i>
    </a>

    <!-- SM: Beli Lebah -->
    <a href="/farm" class="b-card b-card--sm" style="background:linear-gradient(135deg,#f59e0b,#d97706);">
      <i class="ph-fill ph-flower-lotus"></i>
      <span class="b-card--sm__lbl">Beli Lebah</span>
    </a>

    <!-- SM: Beli Kandang -->
    <a href="/farm" class="b-card b-card--sm" style="background:linear-gradient(135deg,#0284c7,#0369a1);border-color:#075985;box-shadow:0 4px 0 #075985;">
      <i class="ph-fill ph-house-line"></i>
      <span class="b-card--sm__lbl">Beli Kandang</span>
    </a>

    <!-- SM: Misi Peternak -->
    <a href="/missions" class="b-card b-card--sm" style="background:linear-gradient(135deg,#ea580c,#c2410c);">
      <i class="ph-fill ph-target"></i>
      <span class="b-card--sm__lbl">Misi Harian</span>
    </a>

    <!-- SM: Absen Hadir -->
    <a href="/checkin" class="b-card b-card--sm" style="background:linear-gradient(135deg,#db2777,#9d174d);border-color:#831843;box-shadow:0 4px 0 #831843;">
      <i class="ph-fill ph-calendar-check"></i>
      <span class="b-card--sm__lbl">Absen</span>
    </a>

    <!-- SM: Squad Peternak -->
    <a href="/referral" class="b-card b-card--sm" style="background:linear-gradient(135deg,#10b981,#047857);border-color:#064e3b;box-shadow:0 4px 0 #064e3b;">
      <i class="ph-fill ph-users-three"></i>
      <span class="b-card--sm__lbl">Squad</span>
    </a>

    <!-- SM: Tukar Kode Hadiah -->
    <a href="/redeem" class="b-card b-card--sm" style="background:linear-gradient(135deg,#6366f1,#4338ca);border-color:#312e81;box-shadow:0 4px 0 #312e81;">
      <i class="ph-fill ph-gift"></i>
      <span class="b-card--sm__lbl">Tukar Kode</span>
    </a>

    <!-- SM: Chicky Game -->
    <a href="/chicky" class="b-card b-card--sm" style="background:linear-gradient(135deg,#eab308,#ca8a04);">
      <i class="ph-fill ph-game-controller"></i>
      <span class="b-card--sm__lbl">Chicky</span>
    </a>

    <?php if (setting($pdo, 'investment_enabled', '1') === '1'): ?>
    <!-- SM: Investasi -->
    <a href="/invest" class="b-card b-card--sm" style="background:linear-gradient(135deg,#d97706,#b45309);">
      <i class="ph-fill ph-trend-up"></i>
      <span class="b-card--sm__lbl">Invest</span>
    </a>
    <?php endif; ?>
  </div>

  <!-- ══════════════════════════════════════════════════════════
       SPESIES LEBAH & KANDANG UNGGULAN
       ══════════════════════════════════════════════════════════ -->
  <div class="section-title-bar" style="justify-content:space-between;">
    <div style="display:flex;align-items:center;gap:6px;">
      <i class="ph-fill ph-sparkle"></i> Spesies Lebah Unggulan
    </div>
    <a href="/farm" style="font-size:11px;font-weight:900;color:#b45309;text-decoration:none;">Lihat Semua &rarr;</a>
  </div>

  <div class="featured-bees-scroll">
    <?php foreach ($featured_bees as $fb): ?>
    <a href="/farm" class="bee-feat-card">
      <img src="<?= htmlspecialchars($fb['image']) ?>" class="bee-feat-card__img" alt="<?= htmlspecialchars($fb['name']) ?>">
      <div>
        <div class="bee-feat-card__name"><?= htmlspecialchars($fb['name']) ?></div>
        <div class="bee-feat-card__prod">+<?= number_format((float)$fb['honey_per_hour'], 0) ?> ml/jam</div>
      </div>
      <div class="bee-feat-card__price">Rp <?= number_format((float)$fb['price'], 0, ',', '.') ?></div>
    </a>
    <?php endforeach; ?>
  </div>

  <!-- ── TIPS PETERNAK LEBAH ── -->
  <div class="farm-tips-card">
    <div class="farm-tips-icon">💡</div>
    <div>
      <div style="font-size:13px;font-weight:900;color:#78350f;line-height:1.2;margin-bottom:2px;">Tips Sukses Ternak Lebah</div>
      <div style="font-size:11px;font-weight:700;color:#64748b;line-height:1.3;">
        Tingkatkan tier Lapak Madu kamu agar harga jual madu per mililiter semakin tinggi dan kuota harian bertambah!
      </div>
    </div>
  </div>

  <!-- ── NOTIFIKASI INBOX ── -->
  <?php if (!empty($notif_preview)): ?>
  <div style="background:#fff;border:3px solid #78350f;border-radius:20px;padding:14px;box-shadow:0 4px 0 #78350f;margin-bottom:16px;">
    <div style="font-size:13px;font-weight:900;color:#78350f;margin-bottom:8px;display:flex;align-items:center;gap:6px;">
      <i class="ph-fill ph-bell-ringing" style="color:#e11d48;"></i> Informasi & Pengumuman
    </div>
    <?php foreach ($notif_preview as $nt): ?>
      <div style="padding:6px 0;border-bottom:1px solid #f1f5f9;">
        <div style="font-size:12px;font-weight:900;color:#1e293b;"><?= htmlspecialchars($nt['title']) ?></div>
        <div style="font-size:10px;font-weight:700;color:#64748b;"><?= htmlspecialchars(mb_substr($nt['message'], 0, 80)) ?>...</div>
      </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

</div>

<?php require dirname(__DIR__) . '/partials/footer.php'; ?>
