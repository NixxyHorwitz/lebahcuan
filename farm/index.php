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

// Fetch user's active bee hives
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

// Fetch active stall for quick info
$active_stall = BeeFarm::getUserActiveStall($pdo, (int)$user['id']);

$pageTitle = 'Kebun Sarang Lebah Cuan';
$activePage = 'farm';
$farmSubPage = 'meadow';
require dirname(__DIR__) . '/partials/header.php';
?>

<style>
/* ══════════════════════════════════════════════════════════
   LIVING APIARY MEADOW — NATURAL ORGANIC SCENERY
   ══════════════════════════════════════════════════════════ */
body {
  background: #bbf7d0 !important;
  font-family: 'Nunito', sans-serif;
  overflow-x: hidden;
}

/* ── SKY & MEADOW CANVAS ── */
.meadow-canvas {
  position: relative;
  min-height: 82vh;
  background: linear-gradient(180deg, #38bdf8 0%, #7dd3fc 24%, #bbf7d0 42%, #4ade80 75%, #22c55e 100%);
  padding: 16px 14px 130px;
  overflow: hidden;
  border-bottom: 5px solid #166534;
}

/* Drifting clouds */
.sky-cloud {
  position: absolute;
  background: #ffffff;
  border-radius: 50px;
  opacity: 0.88;
  pointer-events: none;
  z-index: 1;
  filter: drop-shadow(0 4px 6px rgba(0,0,0,0.08));
}
.sky-cloud::before {
  content: ''; position: absolute; background: #fff; border-radius: 50%;
  width: 55%; height: 160%; top: -60%; left: 20%;
}
.sky-cloud--1 { width: 90px; height: 28px; top: 18px; left: -100px; animation: cloudDrift 28s linear infinite; }
.sky-cloud--2 { width: 130px; height: 36px; top: 65px; left: -150px; animation: cloudDrift 36s linear infinite 14s; }
@keyframes cloudDrift {
  from { transform: translateX(0); }
  to   { transform: translateX(calc(100vw + 200px)); }
}

/* Flying dynamic bees */
.meadow-bee {
  position: absolute;
  width: 36px; height: 36px;
  z-index: 5;
  pointer-events: none;
  filter: drop-shadow(0 4px 5px rgba(0,0,0,0.25));
  will-change: transform;
}
.meadow-bee img {
  width: 100%; height: 100%; object-fit: contain;
  animation: beeWingFlutter 0.18s ease-in-out infinite alternate;
}
@keyframes beeWingFlutter {
  0% { transform: scaleY(0.9) rotate(-3deg); }
  100% { transform: scaleY(1.08) rotate(5deg); }
}

.meadow-bee--1 {
  top: 90px; left: 15%;
  animation: beeFlightPath1 9s ease-in-out infinite;
}
.meadow-bee--2 {
  top: 180px; right: 18%;
  animation: beeFlightPath2 12s ease-in-out infinite 3s;
}
.meadow-bee--3 {
  top: 290px; left: 30%;
  animation: beeFlightPath3 10s ease-in-out infinite 1.5s;
}

@keyframes beeFlightPath1 {
  0%   { transform: translate(0, 0) rotate(0deg); }
  30%  { transform: translate(60px, -25px) rotate(18deg); }
  65%  { transform: translate(120px, 30px) rotate(-10deg); }
  100% { transform: translate(0, 0) rotate(0deg); }
}
@keyframes beeFlightPath2 {
  0%   { transform: translate(0, 0) rotate(0deg) scaleX(-1); }
  40%  { transform: translate(-80px, -35px) rotate(-15deg) scaleX(-1); }
  75%  { transform: translate(-140px, 20px) rotate(12deg) scaleX(-1); }
  100% { transform: translate(0, 0) rotate(0deg) scaleX(-1); }
}
@keyframes beeFlightPath3 {
  0%   { transform: translate(0, 0) rotate(0deg); }
  50%  { transform: translate(80px, -45px) rotate(20deg); }
  100% { transform: translate(0, 0) rotate(0deg); }
}

/* ── QUICK OVERVIEW HUD IN MEADOW ── */
.meadow-hud-summary {
  position: relative;
  z-index: 10;
  background: rgba(255, 255, 255, 0.95);
  border: 3px solid #15803d;
  border-radius: 20px;
  box-shadow: 0 5px 0 #15803d, 0 8px 20px rgba(21,128,61,0.2);
  padding: 10px 14px;
  margin-bottom: 24px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
}
.hud-sum-item {
  display: flex;
  align-items: center;
  gap: 8px;
}
.hud-sum-icon {
  width: 36px; height: 36px;
  border-radius: 12px;
  background: #fef08a;
  border: 2px solid #b45309;
  display: flex; align-items: center; justify-content: center;
  font-size: 18px; color: #b45309; flex-shrink: 0;
}
.hud-sum-label { font-size: 10px; font-weight: 800; color: #64748b; line-height: 1; }
.hud-sum-val   { font-size: 14px; font-weight: 900; color: #15803d; line-height: 1.2; }

/* ── NATURAL STAGGERED MEADOW LANDSCAPE (NON-GRID) ── */
.pasture-trail {
  position: relative;
  z-index: 8;
  display: flex;
  flex-direction: column;
  gap: 24px;
}

/* Grass Hill Tier Shelf */
.pasture-spot {
  position: relative;
  display: flex;
  align-items: center;
  width: 100%;
}
/* Staggering positions naturally */
.pasture-spot--left   { justify-content: flex-start; padding-left: 6px; }
.pasture-spot--right  { justify-content: flex-end; padding-right: 6px; }
.pasture-spot--center { justify-content: center; }

/* Scenic Wooden Hive Stand */
.hive-stand-card {
  position: relative;
  width: 86%;
  max-width: 360px;
  background: #ffffff;
  border: 3.5px solid #78350f;
  border-radius: 24px;
  box-shadow: 0 6px 0 #78350f, 0 10px 24px rgba(120,53,15,0.25);
  padding: 14px;
  transition: transform 0.15s ease;
}
.hive-stand-card:hover {
  transform: translateY(-3px);
}

/* Green terrain base under stand */
.hive-stand-card::before {
  content: '';
  position: absolute;
  bottom: -16px; left: 10%; right: 10%;
  height: 20px;
  background: #15803d;
  border: 3px solid #14532d;
  border-radius: 50%;
  z-index: -1;
  box-shadow: 0 4px 0 #14532d;
}

/* Decorative Wildflower on side */
.pasture-flower {
  position: absolute;
  font-size: 20px;
  filter: drop-shadow(0 3px 0 rgba(0,0,0,0.15));
  pointer-events: none;
}
.pasture-spot--left .pasture-flower  { right: 12px; bottom: 8px; }
.pasture-spot--right .pasture-flower { left: 12px; bottom: 8px; }

/* Hive Card Layout */
.hive-head-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 10px;
}
.hive-title-badge {
  font-size: 13px; font-weight: 900; color: #78350f;
  display: flex; align-items: center; gap: 5px;
}
.hive-speed-badge {
  background: #fef3c7;
  border: 2px solid #d97706;
  border-radius: 12px;
  padding: 2px 8px;
  font-size: 10px; font-weight: 900;
  color: #b45309;
}

/* Middle body: Hive illustration + Honey Gauge */
.hive-body-row {
  display: flex;
  align-items: center;
  gap: 12px;
  margin-bottom: 12px;
}
.hive-sprite-box {
  width: 76px; height: 76px;
  background: radial-gradient(circle, #fef08a 0%, #fde047 70%, #ca8a04 100%);
  border: 3px solid #78350f;
  border-radius: 18px;
  box-shadow: 0 4px 0 #78350f;
  display: flex; align-items: center; justify-content: center;
  flex-shrink: 0;
  position: relative;
  cursor: pointer;
}
.hive-sprite-box img {
  width: 58px; height: 58px; object-fit: contain;
  transition: transform 0.2s cubic-bezier(0.34, 1.56, 0.64, 1);
}
.hive-sprite-box:active img {
  transform: scale(0.9) rotate(-5deg);
}
.hive-bee-count-pill {
  position: absolute;
  bottom: -6px;
  background: #1e293b;
  border: 2px solid #fff;
  border-radius: 10px;
  padding: 1px 6px;
  font-size: 9px; font-weight: 900;
  color: #fff;
  white-space: nowrap;
}

/* Honey Level Gauge */
.hive-gauge-box {
  flex: 1;
}
.hive-gauge-lbl-row {
  display: flex;
  justify-content: space-between;
  font-size: 11px; font-weight: 900;
  color: #475569;
  margin-bottom: 4px;
}
.hive-gauge-val {
  color: #d97706;
  font-size: 13px;
}
.hive-progress-track {
  height: 14px;
  background: #f1f5f9;
  border: 2px solid #78350f;
  border-radius: 10px;
  overflow: hidden;
  position: relative;
  box-shadow: inset 0 2px 4px rgba(0,0,0,0.1);
}
.hive-progress-fill {
  height: 100%;
  background: linear-gradient(90deg, #fbbf24 0%, #f59e0b 60%, #d97706 100%);
  border-radius: 8px;
  transition: width 0.4s ease;
}

/* Action button for single hive */
.btn-harvest-single {
  width: 100%;
  background: linear-gradient(135deg, #f59e0b, #d97706);
  border: 2.5px solid #78350f;
  border-radius: 14px;
  padding: 9px;
  font-size: 12px; font-weight: 900;
  color: #fff;
  box-shadow: 0 4px 0 #78350f;
  display: flex; align-items: center; justify-content: center; gap: 6px;
  cursor: pointer;
  font-family: 'Nunito', sans-serif;
  transition: transform 0.1s;
}
.btn-harvest-single:active {
  transform: translateY(3px);
  box-shadow: 0 1px 0 #78350f;
}
.btn-harvest-single:disabled {
  background: #cbd5e1;
  border-color: #94a3b8;
  color: #64748b;
  box-shadow: none;
  cursor: not-allowed;
  transform: none;
}

/* Wooden Signpost: Add New Hive Spot */
.empty-plot-card {
  width: 84%;
  max-width: 350px;
  background: rgba(255,255,255,0.7);
  border: 3px dashed #15803d;
  border-radius: 24px;
  padding: 16px;
  text-align: center;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 8px;
  text-decoration: none;
  box-shadow: 0 4px 0 rgba(21,128,61,0.2);
  transition: all 0.2s ease;
}
.empty-plot-card:hover {
  background: #ffffff;
  border-color: #78350f;
  transform: translateY(-2px);
}
.empty-plot-icon {
  width: 52px; height: 52px;
  background: #dcfce7;
  border: 2.5px solid #16a34a;
  border-radius: 16px;
  display: flex; align-items: center; justify-content: center;
  font-size: 26px; color: #16a34a;
}
.empty-plot-title { font-size: 14px; font-weight: 900; color: #15803d; }
.empty-plot-sub   { font-size: 11px; font-weight: 700; color: #64748b; }

/* ── STICKY BOTTOM QUICK ACTION: HARVEST ALL ── */
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
  cursor: pointer;
  font-family: 'Nunito', sans-serif;
  text-shadow: 0 2px 0 #78350f;
  transition: transform 0.1s;
}
.btn-harvest-all:active {
  transform: translateY(3px);
  box-shadow: 0 3px 0 #78350f;
}
.btn-harvest-all:disabled {
  background: #cbd5e1;
  border-color: #64748b;
  color: #475569;
  text-shadow: none;
  box-shadow: none;
  cursor: not-allowed;
  transform: none;
}

/* Floating Honey Particle Animation */
.floating-honey-fly {
  position: fixed;
  z-index: 9999;
  font-size: 16px; font-weight: 900; color: #b45309;
  background: #fef3c7; border: 2px solid #b45309; border-radius: 12px;
  padding: 3px 8px; box-shadow: 0 3px 0 #b45309;
  pointer-events: none;
  animation: honeyFloatUp 1.2s forwards ease-out;
}
@keyframes honeyFloatUp {
  0%   { opacity: 1; transform: translateY(0) scale(0.9); }
  60%  { opacity: 1; transform: translateY(-55px) scale(1.15); }
  100% { opacity: 0; transform: translateY(-90px) scale(1); }
}
</style>

<?php require __DIR__ . '/partials/farm_header.php'; ?>

<!-- ══════════════════════════════════════════════════════════
     THE LIVING APIARY MEADOW CANVAS (TAMAN PADANG RUMPUT HIDUP)
     ══════════════════════════════════════════════════════════ -->
<div class="meadow-canvas">
  <!-- Drifting Sky Clouds -->
  <div class="sky-cloud sky-cloud--1"></div>
  <div class="sky-cloud sky-cloud--2"></div>

  <!-- Flying Dynamic Bees in Landscape -->
  <div class="meadow-bee meadow-bee--1" onclick="FarmAudio.playBee(1.0)">
    <img src="/assets/game/bee_worker.png" alt="Bee">
  </div>
  <div class="meadow-bee meadow-bee--2" onclick="FarmAudio.playBee(1.0)">
    <img src="/assets/game/bee_honey.png" onerror="this.src='/assets/game/bee_worker.png'" alt="Bee">
  </div>
  <div class="meadow-bee meadow-bee--3" onclick="FarmAudio.playBee(1.0)">
    <img src="/assets/game/bee_royal.png" onerror="this.src='/assets/game/bee_worker.png'" alt="Bee">
  </div>

  <!-- HUD Summary: Laju Produksi & Lebah Bekerja -->
  <div class="meadow-hud-summary">
    <div class="hud-sum-item">
      <div class="hud-sum-icon"><i class="ph-fill ph-drop"></i></div>
      <div>
        <div class="hud-sum-label">Laju Produksi</div>
        <div class="hud-sum-val">+<?= number_format($total_hourly_rate, 1) ?> ml/jam</div>
      </div>
    </div>
    <div class="hud-sum-item">
      <div class="hud-sum-icon" style="background:#dcfce7;border-color:#15803d;color:#15803d;"><i class="ph-fill ph-flower-lotus"></i></div>
      <div>
        <div class="hud-sum-label">Lebah Bekerja</div>
        <div class="hud-sum-val" style="color:#15803d;"><?= $total_active_bees ?> Ekor</div>
      </div>
    </div>
    <div class="hud-sum-item">
      <div class="hud-sum-icon" style="background:#fee2e2;border-color:#b91c1c;color:#b91c1c;"><i class="ph-fill ph-house-line"></i></div>
      <div>
        <div class="hud-sum-label">Sarang Aktif</div>
        <div class="hud-sum-val" style="color:#b91c1c;"><?= count($user_hives) ?> Unit</div>
      </div>
    </div>
  </div>

  <!-- ── NATURAL STAGGERED HIVE SPOTS (BUKAN GRID/CARD KAKU) ── -->
  <div class="pasture-trail">
    <?php if (empty($user_hives)): ?>
      <!-- Belum Punya Sarang: Tampilkan Welcome Plot -->
      <div class="pasture-spot pasture-spot--center">
        <a href="/farm/shop" class="empty-plot-card">
          <div class="empty-plot-icon">
            <i class="ph-fill ph-plus-circle"></i>
          </div>
          <div class="empty-plot-title">Mulai Peternakan Pertama Kamu!</div>
          <div class="empty-plot-sub">Beli sarang dan bibit lebah di Toko untuk mulai memproduksi madu rupiah.</div>
          <span style="font-size:12px;font-weight:900;color:#15803d;background:#dcfce7;border:2px solid #16a34a;padding:5px 12px;border-radius:12px;margin-top:4px;">
            Buka Toko Bibit &rarr;
          </span>
        </a>
      </div>
    <?php else: ?>
      <?php 
      $alignments = ['pasture-spot--left', 'pasture-spot--right', 'pasture-spot--center'];
      $flowers = ['🌸', '🌼', '🌺', '🌻', '🌷'];
      foreach ($user_hives as $idx => $uh): 
        $align = $alignments[$idx % count($alignments)];
        $flower = $flowers[$idx % count($flowers)];
        $det = $uh['details'];
        $unharvested = (float)($det['total_honey'] ?? 0);
        $maxCap = (float)($det['max_capacity'] ?? 100);
        $pct = (float)($det['fill_percentage'] ?? 0);
        $prodRate = (float)($det['hourly_production'] ?? 0);
        $canHarvest = $unharvested >= 0.1;
      ?>
        <div class="pasture-spot <?= $align ?>">
          <div class="pasture-flower"><?= $flower ?></div>
          <div class="hive-stand-card" id="hiveCard_<?= $uh['id'] ?>">
            
            <div class="hive-head-row">
              <div class="hive-title-badge">
                <i class="ph-fill ph-hexagon" style="color:#d97706;"></i>
                <span><?= htmlspecialchars($uh['master_name']) ?></span>
              </div>
              <div class="hive-speed-badge">
                +<?= number_format($prodRate, 1) ?> ml/jam
              </div>
            </div>

            <div class="hive-body-row">
              <!-- Hive Sprite & Animation -->
              <div class="hive-sprite-box" onclick="FarmAudio.playBee(0.6)">
                <img src="<?= htmlspecialchars($uh['master_image']) ?>" alt="Hive">
                <div class="hive-bee-count-pill">
                  <?= (int)$det['bee_count'] ?> / <?= (int)$uh['max_slots'] ?> Lebah
                </div>
              </div>

              <!-- Honey Progress Gauge -->
              <div class="hive-gauge-box">
                <div class="hive-gauge-lbl-row">
                  <span>Madu Siap Panen</span>
                  <span class="hive-gauge-val" id="honeyVal_<?= $uh['id'] ?>"><?= number_format($unharvested, 1) ?> ml</span>
                </div>
                <div class="hive-progress-track">
                  <div class="hive-progress-fill" id="fillBar_<?= $uh['id'] ?>" style="width: <?= $pct ?>%;"></div>
                </div>
                <div style="display:flex;justify-content:space-between;font-size:9.5px;font-weight:800;color:#94a3b8;margin-top:3px;">
                  <span>Kapasitas: <?= $maxCap ?> ml</span>
                  <span><?= $pct ?>%</span>
                </div>
              </div>
            </div>

            <!-- Single Harvest Button -->
            <button type="button" class="btn-harvest-single" id="btnHarvest_<?= $uh['id'] ?>" 
                    onclick="harvestSingleHive(<?= $uh['id'] ?>)" <?= !$canHarvest ? 'disabled' : '' ?>>
              <i class="ph-fill ph-drop"></i>
              <span>Panen Sarang Ini (<?= number_format($unharvested, 1) ?> ml)</span>
            </button>

          </div>
        </div>
      <?php endforeach; ?>

      <!-- Extra Expansion Plot Spot (Add More Hives) -->
      <div class="pasture-spot pasture-spot--center" style="margin-top: 10px;">
        <a href="/farm/shop" class="empty-plot-card">
          <div class="empty-plot-icon">
            <i class="ph-fill ph-plus-circle"></i>
          </div>
          <div class="empty-plot-title">+ Tambah Sarang Baru</div>
          <div class="empty-plot-sub">Tingkatkan kapasitas produksi madu untuk penghasilan harian lebih besar!</div>
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
      <div style="background:rgba(0,0,0,0.2);padding:4px 10px;border-radius:12px;font-size:13px;font-weight:900;" id="totalUnharvestedBadge">
        <?= number_format($total_unharvested_honey, 1) ?> ml
      </div>
    </button>
  </div>
  <?php endif; ?>

</div>

<!-- CSRF Token Hidden Form -->
<?= csrf_field() ?>

<script>
// Background Ambient Bee Sound (Periodic gentle buzzing if unmuted)
setInterval(function() {
  if (Math.random() < 0.35 && typeof FarmAudio !== 'undefined') {
    FarmAudio.playBee(0.7);
  }
}, 7000);

// Helper function to spawn floating honey badge
function spawnHoneyFly(text, originElement) {
  const rect = originElement ? originElement.getBoundingClientRect() : { top: window.innerHeight / 2, left: window.innerWidth / 2 };
  const badge = document.createElement('div');
  badge.className = 'floating-honey-fly';
  badge.innerText = '+ ' + text + ' ml';
  badge.style.top = (rect.top + 10) + 'px';
  badge.style.left = (rect.left + 30) + 'px';
  document.body.appendChild(badge);
  setTimeout(() => badge.remove(), 1300);
}

// 1. Single Hive Harvest
function harvestSingleHive(hiveId) {
  const btn = document.getElementById('btnHarvest_' + hiveId);
  if (!btn || btn.disabled) return;

  btn.disabled = true;
  const originalText = btn.innerHTML;
  btn.innerHTML = '<i class="ph-bold ph-spinner ph-spin"></i> Memanen...';

  // Play harvest sound
  FarmAudio.playHarvest();

  const csrfToken = document.querySelector('input[name="_csrf"]')?.value || '';

  const fd = new FormData();
  fd.append('action', 'harvest_hive');
  fd.append('hive_id', hiveId);
  fd.append('_csrf', csrfToken);

  fetch('/api/farm_action', {
    method: 'POST',
    body: fd
  })
  .then(res => res.json())
  .then(data => {
    if (data.ok) {
      spawnHoneyFly(data.harvested_ml, btn);
      // Update top HUD honey stock
      const hudStock = document.getElementById('hudHoneyStock');
      if (hudStock && data.new_honey_stock !== undefined) {
        hudStock.innerText = Number(data.new_honey_stock).toLocaleString('id-ID', {minimumFractionDigits: 1}) + ' ml';
      }
      // Reset this hive UI
      const valEl = document.getElementById('honeyVal_' + hiveId);
      const fillEl = document.getElementById('fillBar_' + hiveId);
      if (valEl) valEl.innerText = '0.0 ml';
      if (fillEl) fillEl.style.width = '0%';
      btn.innerHTML = '<i class="ph-bold ph-check"></i> Selesai Dipanen!';
      setTimeout(() => {
        btn.innerHTML = '<i class="ph-fill ph-drop"></i> <span>Panen Sarang Ini (0.0 ml)</span>';
      }, 1500);
    } else {
      alert(data.msg || 'Gagal memanen sarang.');
      btn.disabled = false;
      btn.innerHTML = originalText;
    }
  })
  .catch(err => {
    btn.disabled = false;
    btn.innerHTML = originalText;
    alert('Terjadi kesalahan jaringan.');
  });
}

// 2. Harvest All Hives
function harvestAllHives() {
  const btn = document.getElementById('btnHarvestAll');
  if (!btn || btn.disabled) return;

  btn.disabled = true;
  const originalText = btn.innerHTML;
  btn.innerHTML = '<i class="ph-bold ph-spinner ph-spin"></i> Memanen Semua Sarang...';

  // Play harvest sound
  FarmAudio.playHarvest();

  const csrfToken = document.querySelector('input[name="_csrf"]')?.value || '';

  const fd = new FormData();
  fd.append('action', 'harvest_all');
  fd.append('_csrf', csrfToken);

  fetch('/api/farm_action', {
    method: 'POST',
    body: fd
  })
  .then(res => res.json())
  .then(data => {
    if (data.ok) {
      spawnHoneyFly(data.harvested_ml, btn);
      const hudStock = document.getElementById('hudHoneyStock');
      if (hudStock && data.new_honey_stock !== undefined) {
        hudStock.innerText = Number(data.new_honey_stock).toLocaleString('id-ID', {minimumFractionDigits: 1}) + ' ml';
      }
      // Reset all hive progress gauges
      document.querySelectorAll('[id^="honeyVal_"]').forEach(el => el.innerText = '0.0 ml');
      document.querySelectorAll('[id^="fillBar_"]').forEach(el => el.style.width = '0%');
      document.querySelectorAll('[id^="btnHarvest_"]').forEach(el => {
        el.disabled = true;
        el.innerHTML = '<i class="ph-fill ph-drop"></i> <span>Panen (0.0 ml)</span>';
      });

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
  .catch(err => {
    btn.disabled = false;
    btn.innerHTML = originalText;
    alert('Terjadi kendala saat memproses.');
  });
}
</script>

<?php require dirname(__DIR__) . '/partials/footer.php'; ?>
