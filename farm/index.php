<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

$user = require_auth($pdo);

if (is_maintenance($pdo) && !auth_admin()) {
    $maintenance_msg = setting($pdo, 'maintenance_message', 'Sistem sedang dalam perbaikan.');
    require dirname(__DIR__) . '/user/maintenance.php';
    exit;
}

track_pageview($pdo, parse_url($_SERVER['REQUEST_URI'] ?? '/farm', PHP_URL_PATH));

// Fetch fresh user data
$uStmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$uStmt->execute([$user['id']]);
$user = $uStmt->fetch(PDO::FETCH_ASSOC);

// Fetch user's active bee hives with full details
$hivesStmt = $pdo->prepare("
    SELECT h.*, m.name as master_name, m.image as master_image, m.max_slots, m.bonus_speed_pct, m.duration_days,
           (SELECT COUNT(*) FROM user_bees WHERE hive_id = h.id AND is_active = 1 AND (expires_at IS NULL OR expires_at > NOW())) as bee_count
    FROM user_bee_hives h
    JOIN bee_hives_master m ON m.id = h.hive_master_id
    WHERE h.user_id = ? AND h.is_active = 1 AND (h.expires_at IS NULL OR h.expires_at > NOW())
    ORDER BY h.id ASC
");
$hivesStmt->execute([$user['id']]);
$user_hives = $hivesStmt->fetchAll(PDO::FETCH_ASSOC);

$total_active_bees = 0;
$total_hourly_rate = 0.0;
$total_unharvested_honey = 0.0;

foreach ($user_hives as &$h) {
    $hDetail = BeeFarm::getHiveDetails($pdo, (int)$h['id'], (int)$user['id']);
    $h['details'] = $hDetail;
    $total_active_bees += (int)($hDetail['bee_count'] ?? 0);
    $total_hourly_rate += (float)($hDetail['hourly_production'] ?? 0.0);
    $total_unharvested_honey += (float)($hDetail['total_honey'] ?? 0.0);
}
unset($h);

// Active stall info
$active_stall = BeeFarm::getUserActiveStall($pdo, (int)$user['id']);

// Default active hive for initial hexagon display
$firstHive = !empty($user_hives) ? $user_hives[0] : null;

$pageTitle = 'Kebun Sarang Lebah Cuan — Tycoon Simulator';
$activePage = 'farm';
$farmSubPage = 'meadow';
require dirname(__DIR__) . '/partials/header.php';
?>

<style>
/* ══════════════════════════════════════════════════════════
   LIVING TYCOON MEADOW & 3D WOODEN BEEHIVES LANDSCAPE
   ══════════════════════════════════════════════════════════ */
body {
  background: #22c55e !important;
  font-family: 'Nunito', sans-serif;
  overflow-x: hidden;
}

/* ── SKY & ROLLING HILLS MEADOW ── */
.tycoon-landscape {
  position: relative;
  min-height: 90vh;
  background: linear-gradient(180deg, 
    #38bdf8 0%, 
    #7dd3fc 22%, 
    #86efac 36%, 
    #4ade80 50%, 
    #22c55e 75%, 
    #16a34a 100%
  );
  padding: 16px 14px 140px;
  overflow: hidden;
  border-bottom: 5px solid #14532d;
}

/* Drifting clouds */
.sky-cloud {
  position: absolute; background: #ffffff; border-radius: 50px;
  opacity: 0.88; pointer-events: none; z-index: 1;
  filter: drop-shadow(0 4px 6px rgba(0,0,0,0.06));
}
.sky-cloud::before {
  content: ''; position: absolute; background: #fff; border-radius: 50%;
  width: 55%; height: 160%; top: -60%; left: 20%;
}
.sky-cloud--1 { width: 95px; height: 30px; top: 18px; left: -110px; animation: cloudDrift 26s linear infinite; }
.sky-cloud--2 { width: 135px; height: 38px; top: 68px; left: -150px; animation: cloudDrift 38s linear infinite 12s; }
@keyframes cloudDrift {
  from { transform: translateX(0); }
  to   { transform: translateX(calc(100vw + 220px)); }
}

/* Free floating flying bees */
.field-bee {
  position: absolute; width: 34px; height: 34px; z-index: 6;
  pointer-events: none; filter: drop-shadow(0 5px 6px rgba(0,0,0,0.3));
}
.field-bee img {
  width: 100%; height: 100%; object-fit: contain;
  animation: wingFlutter 0.14s ease-in-out infinite alternate;
}
@keyframes wingFlutter {
  from { transform: scaleY(0.88) rotate(-4deg); }
  to   { transform: scaleY(1.12) rotate(6deg); }
}
.field-bee--1 { top: 90px; left: 12%; animation: beePath1 9s ease-in-out infinite; }
.field-bee--2 { top: 170px; right: 14%; animation: beePath2 12s ease-in-out infinite 2s; }
@keyframes beePath1 {
  0%, 100% { transform: translate(0, 0) rotate(0deg); }
  50%      { transform: translate(75px, -30px) rotate(18deg); }
}
@keyframes beePath2 {
  0%, 100% { transform: translate(0, 0) scaleX(-1) rotate(0deg); }
  50%      { transform: translate(-80px, 35px) scaleX(-1) rotate(-18deg); }
}

/* ══════════════════════════════════════════════════════════
   PROMINENT HEXAGONAL HONEYCOMB MATRIX DISPLAY
   (FITUR DISPLAY HEXAGONAL MADU UTAMA DI ATAS KEBERADAAN KEBUN)
   ══════════════════════════════════════════════════════════ */
.honeycomb-showcase-box {
  background: #ffffff;
  border: 4px solid #78350f;
  border-radius: 26px;
  box-shadow: 0 8px 0 #78350f, 0 16px 30px rgba(120,53,15,0.25);
  padding: 16px 14px 18px;
  margin-bottom: 24px;
  position: relative;
  z-index: 10;
}
.honeycomb-header-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 10px;
}
.honeycomb-title-tag {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  background: #fef3c7;
  border: 2px solid #d97706;
  border-radius: 12px;
  padding: 4px 10px;
  font-size: 13px; font-weight: 900; color: #78350f;
}
.honeycomb-hive-selector {
  background: #fff;
  border: 2px solid #78350f;
  border-radius: 10px;
  padding: 3px 8px;
  font-size: 11px; font-weight: 900; color: #78350f;
  outline: none; font-family: 'Nunito', sans-serif;
}

/* The Authentic Wooden Honeycomb Frame */
.honeycomb-frame {
  background: #78350f;
  background-image: repeating-linear-gradient(45deg, #78350f 0, #78350f 10px, #92400e 10px, #92400e 20px);
  border: 4px solid #451a03;
  border-radius: 20px;
  padding: 14px 8px;
  box-shadow: inset 0 5px 10px rgba(0,0,0,0.6), 0 4px 0 #451a03;
  position: relative;
  overflow: hidden;
  margin-bottom: 12px;
}

/* Crawling Worker Bees inside Honeycomb Frame */
.hive-crawling-bee {
  position: absolute; width: 34px; height: 34px;
  z-index: 8; pointer-events: none;
  filter: drop-shadow(0 4px 5px rgba(0,0,0,0.6));
}
.hive-crawling-bee img {
  width: 100%; height: 100%; object-fit: contain;
  animation: beeWingFlap 0.08s linear infinite alternate;
}
@keyframes beeWingFlap {
  from { transform: scaleX(1); }
  to   { transform: scaleX(0.85) scaleY(1.05); }
}
.bee-crawler-1 { top: 20%; left: 18%; animation: crawl1 7s ease-in-out infinite; }
.bee-crawler-2 { top: 55%; right: 22%; animation: crawl2 8s ease-in-out infinite; }
@keyframes crawl1 {
  0%, 100% { transform: translate(0, 0) rotate(15deg); }
  50%      { transform: translate(45px, 20px) rotate(-25deg); }
}
@keyframes crawl2 {
  0%, 100% { transform: translate(0, 0) rotate(-40deg); }
  50%      { transform: translate(-35px, -20px) rotate(20deg); }
}

/* 3D Hexagon Matrix Grid (24 Cells) */
.hex-grid {
  display: flex; flex-direction: column; align-items: center; gap: 4px;
  position: relative; z-index: 2;
}
.hex-row {
  display: flex; gap: 6px; justify-content: center;
}
.hex-row--offset { margin-left: 18px; }

.hex-cell {
  width: 36px; height: 40px;
  position: relative;
  clip-path: polygon(50% 0%, 100% 25%, 100% 75%, 50% 100%, 0% 75%, 0% 25%);
  transition: all 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
  display: flex; align-items: center; justify-content: center;
}
/* Empty Wax Cell */
.hex-cell--empty {
  background: #451a03;
  border: 1px solid #78350f;
  box-shadow: inset 0 2px 4px rgba(0,0,0,0.7);
}
.hex-cell--empty::after {
  content: ''; position: absolute; inset: 3px;
  clip-path: polygon(50% 0%, 100% 25%, 100% 75%, 50% 100%, 0% 75%, 0% 25%);
  background: #271202; opacity: 0.92;
}
/* Honey-filled Golden Cell */
.hex-cell--honey {
  background: linear-gradient(180deg, #fde047 0%, #eab308 45%, #b45309 100%);
  filter: drop-shadow(0 0 6px #f59e0b);
  animation: honeyGlow 2.5s ease-in-out infinite alternate;
}
.hex-cell--honey::after {
  content: ''; position: absolute; top: 4px; left: 8px; width: 14px; height: 10px;
  background: rgba(255, 255, 255, 0.7); border-radius: 50%;
  transform: rotate(-25deg); pointer-events: none;
}
@keyframes honeyGlow {
  0%   { filter: drop-shadow(0 0 3px #f59e0b); }
  100% { filter: drop-shadow(0 0 10px #fde047); }
}

/* Honey Counter & Harvest Row inside Showcase */
.showcase-harvest-bar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
}
.showcase-honey-info {
  display: flex;
  align-items: center;
  gap: 8px;
}
.showcase-drop-icon {
  width: 40px; height: 40px;
  background: #fef3c7; border: 2.5px solid #d97706; border-radius: 12px;
  display: flex; align-items: center; justify-content: center;
  font-size: 22px; color: #b45309; flex-shrink: 0;
}
.showcase-honey-val { font-size: 19px; font-weight: 900; color: #b45309; line-height: 1; }
.showcase-honey-sub { font-size: 10px; font-weight: 800; color: #64748b; }

.btn-showcase-harvest {
  background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
  border: 2.5px solid #78350f;
  border-radius: 14px;
  box-shadow: 0 4px 0 #78350f;
  padding: 10px 16px;
  color: #fff;
  font-size: 13px; font-weight: 900;
  display: inline-flex; align-items: center; gap: 6px;
  cursor: pointer; font-family: 'Nunito', sans-serif;
  transition: transform 0.1s;
}
.btn-showcase-harvest:active { transform: translateY(2px); box-shadow: 0 2px 0 #78350f; }
.btn-showcase-harvest:disabled {
  background: #cbd5e1; border-color: #94a3b8; color: #64748b;
  box-shadow: none; cursor: not-allowed;
}

/* ══════════════════════════════════════════════════════════
   3D LIVING BEEHIVE OBJECTS IN NATURAL ENVIRONMENT
   (BENTUK KANDANG/SARANG 3D BENARAN DI LINGKUNGAN ALAM — BUKAN CARD BOX)
   ══════════════════════════════════════════════════════════ */
.meadow-trail-title {
  display: flex; align-items: center; justify-content: space-between;
  margin-bottom: 16px; position: relative; z-index: 5;
}
.trail-title-pill {
  background: #ffffff; border: 2.5px solid #166534; border-radius: 14px;
  padding: 4px 12px; font-size: 13px; font-weight: 900; color: #166534;
  box-shadow: 0 3px 0 #166534; display: inline-flex; align-items: center; gap: 6px;
}

/* The Winding Meadow Terrain */
.pasture-terrain {
  position: relative;
  z-index: 5;
  display: flex;
  flex-direction: column;
  gap: 36px;
  margin-top: 10px;
}

/* Staggered Spots */
.pasture-hill-tier {
  position: relative;
  display: flex;
  width: 100%;
}
.pasture-hill-tier--left   { justify-content: flex-start; padding-left: 20px; }
.pasture-hill-tier--right  { justify-content: flex-end; padding-right: 20px; }
.pasture-hill-tier--center { justify-content: center; }

/* ── REAL 3D WOODEN BEEHIVE OBJECT ── */
.beehive-3d-unit {
  position: relative;
  display: flex;
  flex-direction: column;
  align-items: center;
  cursor: pointer;
  -webkit-tap-highlight-color: transparent;
  transition: transform 0.2s cubic-bezier(0.34, 1.56, 0.64, 1);
}
.beehive-3d-unit:hover {
  transform: translateY(-5px) scale(1.03);
}
.beehive-3d-unit:active {
  transform: translateY(2px) scale(0.97);
}

/* Ground Pedestal Shadow under the 3D Hive */
.beehive-ground-shadow {
  position: absolute;
  bottom: -10px;
  width: 110px;
  height: 28px;
  background: radial-gradient(ellipse at center, rgba(20,83,45,0.7) 0%, rgba(20,83,45,0.3) 60%, transparent 80%);
  border-radius: 50%;
  z-index: 1;
}

/* Wildflowers growing at hive base */
.hive-base-flower {
  position: absolute;
  bottom: -4px;
  font-size: 22px;
  z-index: 4;
  filter: drop-shadow(0 2px 2px rgba(0,0,0,0.2));
}
.hive-base-flower--left  { left: -14px; }
.hive-base-flower--right { right: -14px; }

/* 3D Beehive Sprite Container */
.beehive-sprite-wrapper {
  position: relative;
  z-index: 3;
  width: 130px;
  height: 130px;
  display: flex;
  align-items: center;
  justify-content: center;
  filter: drop-shadow(0 10px 14px rgba(0,0,0,0.35));
}
.beehive-sprite-wrapper img {
  width: 100%;
  height: 100%;
  object-fit: contain;
  transition: transform 0.2s;
}
.beehive-3d-unit:hover .beehive-sprite-wrapper img {
  animation: hiveIdleShake 0.4s ease-in-out infinite alternate;
}
@keyframes hiveIdleShake {
  0%   { transform: rotate(-2deg); }
  100% { transform: rotate(2deg); }
}

/* Worker Bees Swarming entrance slot */
.hive-swarming-bee {
  position: absolute;
  width: 26px; height: 26px;
  z-index: 5;
  pointer-events: none;
  filter: drop-shadow(0 3px 3px rgba(0,0,0,0.3));
}
.hive-swarming-bee img {
  width: 100%; height: 100%; object-fit: contain;
  animation: wingFlutter 0.1s infinite alternate;
}
.swarm-bee-1 {
  bottom: 25px; right: 10px;
  animation: swarmPath1 4s ease-in-out infinite;
}
.swarm-bee-2 {
  top: 20px; left: 15px;
  animation: swarmPath2 5s ease-in-out infinite 1s;
}
@keyframes swarmPath1 {
  0%, 100% { transform: translate(0, 0) scale(0.9); }
  50%      { transform: translate(-18px, -15px) scale(1.05); }
}
@keyframes swarmPath2 {
  0%, 100% { transform: translate(0, 0) scale(1); }
  50%      { transform: translate(22px, 12px) scale(0.9); }
}

/* Floating Honey Gauge Bubble Above 3D Hive */
.hive-floating-bubble {
  position: absolute;
  top: -24px;
  background: linear-gradient(135deg, #fbbf24 0%, #f59e0b 50%, #d97706 100%);
  border: 2.5px solid #78350f;
  border-radius: 18px;
  padding: 4px 10px;
  box-shadow: 0 4px 0 #78350f, 0 6px 12px rgba(120,53,15,0.3);
  display: flex;
  align-items: center;
  gap: 5px;
  font-size: 11px; font-weight: 900; color: #fff;
  z-index: 10;
  white-space: nowrap;
  animation: floatBob 2.2s ease-in-out infinite;
}
@keyframes floatBob {
  0%, 100% { transform: translateY(0); }
  50%      { transform: translateY(-7px); }
}

/* Rustic Wooden Plank Label in Ground beneath Hive */
.hive-ground-plank {
  position: relative;
  z-index: 3;
  margin-top: 4px;
  background: #78350f;
  border: 2px solid #451a03;
  border-radius: 10px;
  padding: 3px 10px;
  box-shadow: 0 3px 0 #451a03, 0 4px 8px rgba(0,0,0,0.2);
  text-align: center;
}
.hive-plank-name {
  font-size: 11px; font-weight: 900; color: #fef3c7;
  text-shadow: 0 1px 1px rgba(0,0,0,0.6);
  line-height: 1.1;
}
.hive-plank-bees {
  font-size: 9.5px; font-weight: 800; color: #fde047;
}

/* Rustic Wooden Signpost for Buying New Hive */
.pasture-signpost-unit {
  position: relative;
  display: flex;
  flex-direction: column;
  align-items: center;
  text-decoration: none;
  transition: transform 0.2s;
  cursor: pointer;
}
.pasture-signpost-unit:hover {
  transform: translateY(-4px);
}
.signpost-board {
  background: #92400e;
  border: 3px solid #451a03;
  border-radius: 14px;
  padding: 10px 14px;
  box-shadow: 0 4px 0 #451a03, 0 8px 16px rgba(0,0,0,0.25);
  text-align: center;
  position: relative;
  z-index: 3;
}
.signpost-title { font-size: 13px; font-weight: 900; color: #fff; }
.signpost-sub   { font-size: 10px; font-weight: 800; color: #fde047; }
.signpost-pole {
  width: 14px; height: 40px;
  background: #78350f; border: 2px solid #451a03;
  margin-top: -3px; z-index: 2;
  box-shadow: 0 3px 0 #451a03;
}

/* ── STICKY BOTTOM HARVEST ALL ── */
.meadow-sticky-bar {
  position: fixed;
  bottom: 84px;
  left: 50%;
  transform: translateX(-50%);
  width: calc(100% - 24px);
  max-width: 456px;
  z-index: 50;
}
.btn-harvest-all {
  width: 100%;
  background: linear-gradient(135deg, #fbbf24 0%, #f59e0b 45%, #d97706 100%);
  border: 3.5px solid #78350f;
  border-radius: 20px;
  box-shadow: 0 6px 0 #78350f, 0 10px 25px rgba(180,83,9,0.4);
  padding: 13px 18px;
  color: #fff;
  font-size: 15px; font-weight: 900;
  display: flex; align-items: center; justify-content: space-between;
  cursor: pointer; font-family: 'Nunito', sans-serif;
  text-shadow: 0 2px 0 #78350f;
  transition: transform 0.1s;
}
.btn-harvest-all:active { transform: translateY(3px); box-shadow: 0 3px 0 #78350f; }
.btn-harvest-all:disabled {
  background: #cbd5e1; border-color: #64748b; color: #475569;
  text-shadow: none; box-shadow: none; cursor: not-allowed;
}

/* Floating Honey Particle */
.floating-honey-fly {
  position: fixed; z-index: 9999;
  font-size: 16px; font-weight: 900; color: #b45309;
  background: #fef3c7; border: 2.5px solid #b45309; border-radius: 12px;
  padding: 4px 10px; box-shadow: 0 4px 0 #b45309;
  pointer-events: none; animation: honeyFloatUp 1.2s forwards ease-out;
}
@keyframes honeyFloatUp {
  0%   { opacity: 1; transform: translateY(0) scale(0.9); }
  60%  { opacity: 1; transform: translateY(-55px) scale(1.15); }
  100% { opacity: 0; transform: translateY(-90px) scale(1); }
}
</style>

<?php require __DIR__ . '/partials/farm_header.php'; ?>

<!-- ══════════════════════════════════════════════════════════
     THE LIVING TYCOON MEADOW CANVAS
     ══════════════════════════════════════════════════════════ -->
<div class="tycoon-landscape">
  <!-- Drifting Clouds -->
  <div class="sky-cloud sky-cloud--1"></div>
  <div class="sky-cloud sky-cloud--2"></div>

  <!-- Flying Dynamic Bees in Sky -->
  <div class="field-bee field-bee--1" onclick="FarmAudio.playBee(1.0)">
    <img src="/assets/game/bee_worker.png" alt="Bee">
  </div>
  <div class="field-bee field-bee--2" onclick="FarmAudio.playBee(1.0)">
    <img src="/assets/game/bee_queen.png" onerror="this.src='/assets/game/bee_worker.png'" alt="Bee">
  </div>

  <!-- ══════════════════════════════════════════════════════════
       1. DISPLAY HEXAGONAL MADU (HONEYCOMB FRAME DISPLAY)
       ══════════════════════════════════════════════════════════ -->
  <div class="honeycomb-showcase-box">
    <div class="honeycomb-header-row">
      <div class="honeycomb-title-tag">
        <i class="ph-fill ph-hexagon" style="color:#d97706;"></i>
        <span id="showcaseHiveTitle">
          <?= $firstHive ? htmlspecialchars($firstHive['master_name']) : 'Sarang Lebah' ?>
        </span>
      </div>

      <?php if (!empty($user_hives)): ?>
        <select class="honeycomb-hive-selector" id="showcaseHiveSelect" onchange="switchShowcaseHive(this.value)">
          <?php foreach ($user_hives as $uh): ?>
            <option value="<?= $uh['id'] ?>">
              <?= htmlspecialchars($uh['master_name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      <?php endif; ?>
    </div>

    <!-- Wooden Honeycomb Frame with Hexagon Cells Matrix -->
    <div class="honeycomb-frame">
      <!-- Crawling Bees over the honeycomb -->
      <div class="hive-crawling-bee bee-crawler-1">
        <img src="/assets/game/bee_worker.png" alt="Worker">
      </div>
      <div class="hive-crawling-bee bee-crawler-2">
        <img src="/assets/game/bee_golden.png" onerror="this.src='/assets/game/bee_worker.png'" alt="Worker">
      </div>

      <!-- 24 Hexagonal Cells Grid Matrix -->
      <div class="hex-grid" id="showcaseHexGrid">
        <!-- Will be dynamically populated or initialized below -->
      </div>
    </div>

    <!-- Showcase Honey Counter & Harvest Row -->
    <div class="showcase-harvest-bar">
      <div class="showcase-honey-info">
        <div class="showcase-drop-icon">
          <i class="ph-fill ph-drop"></i>
        </div>
        <div>
          <div class="showcase-honey-val" id="showcaseHoneyAmt">0.00 ml</div>
          <div class="showcase-honey-sub" id="showcaseHoneyCap">Kapasitas: 0 / 150 ml (0%)</div>
        </div>
      </div>

      <button type="button" class="btn-showcase-harvest" id="btnShowcaseHarvest" onclick="harvestCurrentShowcaseHive()">
        <i class="ph-fill ph-drop"></i>
        <span>PANEN MADU</span>
      </button>
    </div>
  </div>

  <!-- ══════════════════════════════════════════════════════════
       2. BENTUK KANDANG/SARANG 3D BENARAN DI LINGKUNGAN ALAM
       (3D TYCOON BEEHIVE STRUCTURES STANDING IN GREEN MEADOW)
       ══════════════════════════════════════════════════════════ -->
  <div class="meadow-trail-title">
    <div class="trail-title-pill">
      <i class="ph-fill ph-plant"></i> Kebun Sarang 3D (<?= count($user_hives) ?> Kandang)
    </div>
    <span style="font-size:11px;font-weight:800;color:#14532d;">Tap sarang untuk inspeksi</span>
  </div>

  <div class="pasture-terrain">
    <?php if (empty($user_hives)): ?>
      <div class="pasture-hill-tier pasture-hill-tier--center">
        <a href="/farm/shop" class="pasture-signpost-unit">
          <div class="signpost-board">
            <div class="signpost-title">Mulai Peternakan 3D Kamu!</div>
            <div class="signpost-sub">Beli sarang dan bibit lebah di Toko &rarr;</div>
          </div>
          <div class="signpost-pole"></div>
        </a>
      </div>
    <?php else: 
      $hillTiers = ['pasture-hill-tier--left', 'pasture-hill-tier--right', 'pasture-hill-tier--center'];
      $flowers = ['🌸', '🌼', '🌺', '🌻'];
      foreach ($user_hives as $idx => $uh):
        $tierClass = $hillTiers[$idx % count($hillTiers)];
        $flowerL = $flowers[$idx % count($flowers)];
        $flowerR = $flowers[($idx + 1) % count($flowers)];
        $det = $uh['details'];
        $unharvested = (float)($det['total_honey'] ?? 0);
        $prodRate = (float)($det['hourly_production'] ?? 0);
        $sprite = !empty($uh['master_image']) ? $uh['master_image'] : '/assets/game/beehive_wooden.png';
      ?>
        <div class="pasture-hill-tier <?= $tierClass ?>">
          <!-- 3D Beehive Structure Unit -->
          <div class="beehive-3d-unit" id="unitHive_<?= $uh['id'] ?>" 
               onclick="selectHiveOnLandscape(<?= $uh['id'] ?>)">
            
            <!-- Floating Honey Indicator Bubble Above 3D Hive -->
            <div class="hive-floating-bubble" id="bubbleHive_<?= $uh['id'] ?>">
              <i class="ph-fill ph-drop"></i>
              <span id="bubbleVal_<?= $uh['id'] ?>"><?= number_format($unharvested, 1) ?> ml</span>
            </div>

            <!-- 3D Beehive Sprite with Swarming Bees -->
            <div class="beehive-sprite-wrapper">
              <img src="<?= htmlspecialchars($sprite) ?>" alt="<?= htmlspecialchars($uh['master_name']) ?>">
              
              <!-- Worker Bees buzzing around the 3D hive -->
              <div class="hive-swarming-bee swarm-bee-1">
                <img src="/assets/game/bee_worker.png" alt="Bee">
              </div>
              <div class="hive-swarming-bee swarm-bee-2">
                <img src="/assets/game/bee_worker.png" alt="Bee">
              </div>
            </div>

            <!-- Ground Shadow on Grass Base -->
            <div class="beehive-ground-shadow"></div>
            <div class="hive-base-flower hive-base-flower--left"><?= $flowerL ?></div>
            <div class="hive-base-flower hive-base-flower--right"><?= $flowerR ?></div>

            <!-- Rustic Wooden Signpost Name Plank on Grass -->
            <div class="hive-ground-plank">
              <div class="hive-plank-name"><?= htmlspecialchars($uh['master_name']) ?></div>
              <div class="hive-plank-bees">
                <?= (int)$det['bee_count'] ?>/<?= (int)$uh['max_slots'] ?> Lebah • +<?= number_format($prodRate, 1) ?> ml/jam
              </div>
            </div>

          </div>
        </div>
      <?php endforeach; ?>

      <!-- Expansion Signpost: Add New Hive Spot in Meadow -->
      <div class="pasture-hill-tier pasture-hill-tier--center" style="margin-top: 10px;">
        <a href="/farm/shop" class="pasture-signpost-unit">
          <div class="signpost-board" style="background:#047857;border-color:#064e3b;">
            <div class="signpost-title">+ Beli Sarang Baru</div>
            <div class="signpost-sub">Perluas kapasitas kebun lebahmu &rarr;</div>
          </div>
          <div class="signpost-pole" style="background:#064e3b;border-color:#022c22;"></div>
        </a>
      </div>

    <?php endif; ?>
  </div>

  <!-- ── STICKY BOTTOM HARVEST ALL ACTION ── -->
  <?php if (!empty($user_hives)): ?>
  <div class="meadow-sticky-bar">
    <button type="button" class="btn-harvest-all" id="btnHarvestAll" onclick="harvestAllHives()" <?= $total_unharvested_honey < 0.1 ? 'disabled' : '' ?>>
      <div style="display:flex;align-items:center;gap:8px;">
        <i class="ph-fill ph-drop" style="font-size:22px;"></i>
        <span>PANEN SEMUA SARANG</span>
      </div>
      <div style="background:rgba(0,0,0,0.25);padding:4px 12px;border-radius:12px;font-size:13.5px;font-weight:900;" id="totalUnharvestedBadge">
        <?= number_format($total_unharvested_honey, 1) ?> ml
      </div>
    </button>
  </div>
  <?php endif; ?>

</div>

<!-- CSRF Hidden Token -->
<?= csrf_field() ?>

<script>
// JSON cache of user hives loaded on page
const USER_HIVES_MAP = <?= json_encode(array_column($user_hives, null, 'id')) ?>;
let currentSelectedHiveId = <?= $firstHive ? (int)$firstHive['id'] : 0 ?>;

// Render the 24 3D Hexagonal Cells
function renderHexagonCells(fillPercentage) {
  const totalCells = 24;
  const honeyCellsCount = Math.min(totalCells, Math.round(totalCells * (fillPercentage / 100)));
  const grid = document.getElementById('showcaseHexGrid');
  if (!grid) return;
  grid.innerHTML = '';

  const rows = [6, 5, 6, 5, 2];
  let cellIndex = 0;

  rows.forEach((colCount, rIdx) => {
    const rowDiv = document.createElement('div');
    rowDiv.className = 'hex-row' + (rIdx % 2 === 1 ? ' hex-row--offset' : '');
    for (let c = 0; c < colCount; c++) {
      if (cellIndex < totalCells) {
        const cell = document.createElement('div');
        const isHoney = cellIndex < honeyCellsCount;
        cell.className = 'hex-cell ' + (isHoney ? 'hex-cell--honey' : 'hex-cell--empty');
        rowDiv.appendChild(cell);
        cellIndex++;
      }
    }
    grid.appendChild(rowDiv);
  });
}

// Update Showcase Display for a specific Hive
function updateShowcaseHiveUI(hiveData) {
  if (!hiveData) return;
  const titleEl = document.getElementById('showcaseHiveTitle');
  if (titleEl) titleEl.innerText = hiveData.master_name || hiveData.custom_name;

  const det = hiveData.details || {};
  const totalHoney = parseFloat(det.total_honey) || 0;
  const maxCap = parseFloat(det.max_capacity) || 100;
  const pct = parseFloat(det.fill_percentage) || 0;

  document.getElementById('showcaseHoneyAmt').innerText = totalHoney.toFixed(2) + ' ml';
  document.getElementById('showcaseHoneyCap').innerText = 
    'Kapasitas: ' + totalHoney.toFixed(1) + ' / ' + maxCap.toFixed(0) + ' ml (' + pct + '%)';

  const btn = document.getElementById('btnShowcaseHarvest');
  if (btn) {
    btn.disabled = totalHoney < 0.1;
  }

  // Render 3D Hexagon Matrix Cells
  renderHexagonCells(pct);
}

// When user taps a 3D hive on the meadow landscape
function selectHiveOnLandscape(hiveId) {
  currentSelectedHiveId = hiveId;
  FarmAudio.playPop();
  FarmAudio.playBee(0.5);

  const sel = document.getElementById('showcaseHiveSelect');
  if (sel) sel.value = hiveId;

  const hiveData = USER_HIVES_MAP[hiveId];
  if (hiveData) {
    updateShowcaseHiveUI(hiveData);
  }

  // Smooth scroll up to showcase display if needed
  const showcase = document.querySelector('.honeycomb-showcase-box');
  if (showcase) {
    showcase.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  }
}

function switchShowcaseHive(hiveId) {
  selectHiveOnLandscape(parseInt(hiveId));
}

// Spawn floating honey badge helper
function spawnHoneyFly(text, originElement) {
  const rect = originElement ? originElement.getBoundingClientRect() : { top: window.innerHeight / 2, left: window.innerWidth / 2 };
  const badge = document.createElement('div');
  badge.className = 'floating-honey-fly';
  badge.innerText = '+ ' + text + ' ml';
  badge.style.top = (rect.top + 10) + 'px';
  badge.style.left = (rect.left + 20) + 'px';
  document.body.appendChild(badge);
  setTimeout(() => badge.remove(), 1200);
}

// Harvest currently selected hive in showcase
function harvestCurrentShowcaseHive() {
  if (!currentSelectedHiveId) return;
  const btn = document.getElementById('btnShowcaseHarvest');
  if (!btn || btn.disabled) return;

  btn.disabled = true;
  const originalText = btn.innerHTML;
  btn.innerHTML = '<i class="ph-bold ph-spinner ph-spin"></i> Memanen...';

  FarmAudio.playHarvest();

  const csrfToken = document.querySelector('input[name="_csrf"]')?.value || '';
  const fd = new FormData();
  fd.append('action', 'harvest_hive');
  fd.append('hive_id', currentSelectedHiveId);
  fd.append('_csrf', csrfToken);

  fetch('/api/farm_action', { method: 'POST', body: fd })
  .then(res => res.json())
  .then(data => {
    if (data.ok) {
      spawnHoneyFly(data.harvested_ml, btn);
      // Update top HUD honey stock
      const hudStock = document.getElementById('hudHoneyStock');
      if (hudStock && data.new_honey_stock !== undefined) {
        hudStock.innerText = Number(data.new_honey_stock).toLocaleString('id-ID', {minimumFractionDigits: 1}) + ' ml';
      }

      // Reset honeycomb display
      document.getElementById('showcaseHoneyAmt').innerText = '0.00 ml';
      renderHexagonCells(0);

      // Reset bubble on landscape
      const bubble = document.getElementById('bubbleVal_' + currentSelectedHiveId);
      if (bubble) bubble.innerText = '0.0 ml';

      if (USER_HIVES_MAP[currentSelectedHiveId] && USER_HIVES_MAP[currentSelectedHiveId].details) {
        USER_HIVES_MAP[currentSelectedHiveId].details.total_honey = 0;
        USER_HIVES_MAP[currentSelectedHiveId].details.fill_percentage = 0;
      }

      btn.innerHTML = '<i class="ph-bold ph-check"></i> Selesai Dipanen!';
      setTimeout(() => {
        btn.innerHTML = originalText;
        btn.disabled = true;
      }, 1500);
    } else {
      alert(data.msg || 'Gagal memanen sarang.');
      btn.disabled = false;
      btn.innerHTML = originalText;
    }
  })
  .catch(() => {
    btn.disabled = false;
    btn.innerHTML = originalText;
    alert('Terjadi kesalahan jaringan.');
  });
}

// Harvest All Hives
function harvestAllHives() {
  const btn = document.getElementById('btnHarvestAll');
  if (!btn || btn.disabled) return;

  btn.disabled = true;
  const originalText = btn.innerHTML;
  btn.innerHTML = '<i class="ph-bold ph-spinner ph-spin"></i> Memanen Semua Sarang...';

  FarmAudio.playHarvest();

  const csrfToken = document.querySelector('input[name="_csrf"]')?.value || '';
  const fd = new FormData();
  fd.append('action', 'harvest_all');
  fd.append('_csrf', csrfToken);

  fetch('/api/farm_action', { method: 'POST', body: fd })
  .then(res => res.json())
  .then(data => {
    if (data.ok) {
      spawnHoneyFly(data.harvested_ml, btn);
      const hudStock = document.getElementById('hudHoneyStock');
      if (hudStock && data.new_honey_stock !== undefined) {
        hudStock.innerText = Number(data.new_honey_stock).toLocaleString('id-ID', {minimumFractionDigits: 1}) + ' ml';
      }

      // Reset showcase
      document.getElementById('showcaseHoneyAmt').innerText = '0.00 ml';
      renderHexagonCells(0);
      document.getElementById('btnShowcaseHarvest').disabled = true;

      // Reset all floating bubbles on 3D hives
      document.querySelectorAll('[id^="bubbleVal_"]').forEach(el => el.innerText = '0.0 ml');

      const totalBadge = document.getElementById('totalUnharvestedBadge');
      if (totalBadge) totalBadge.innerText = '0.0 ml';

      btn.innerHTML = '<i class="ph-bold ph-check"></i> ' + (data.msg || 'Panen Berhasil!');
      setTimeout(() => {
        btn.innerHTML = originalText;
        btn.disabled = true;
      }, 2000);
    } else {
      alert(data.msg || 'Gagal memanen semua madu.');
      btn.disabled = false;
      btn.innerHTML = originalText;
    }
  })
  .catch(() => {
    btn.disabled = false;
    btn.innerHTML = originalText;
    alert('Terjadi kendala jaringan.');
  });
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
  if (currentSelectedHiveId && USER_HIVES_MAP[currentSelectedHiveId]) {
    updateShowcaseHiveUI(USER_HIVES_MAP[currentSelectedHiveId]);
  } else {
    renderHexagonCells(0);
  }
});
</script>

<?php require dirname(__DIR__) . '/partials/footer.php'; ?>
