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

$pageTitle = 'Kebun Sarang Lebah Cuan — 3D Tycoon Simulator';
$activePage = 'farm';
$farmSubPage = 'meadow';
require dirname(__DIR__) . '/partials/header.php';
?>

<style>
/* ══════════════════════════════════════════════════════════
   THREE.JS 3D FARM — FULLSCREEN CANVAS + UI OVERLAY
   ══════════════════════════════════════════════════════════ */
body { background: #0a1a0f !important; font-family: 'Nunito', sans-serif; overflow-x: hidden; }

/* 3D Canvas Container */
#farm3dCanvas {
  position: relative;
  width: 100%;
  height: 65vh;
  min-height: 380px;
  border-bottom: 4px solid #14532d;
  touch-action: pan-y;
  cursor: grab;
  overflow: hidden;
}
#farm3dCanvas:active { cursor: grabbing; }
#farm3dCanvas canvas { display: block; width: 100% !important; height: 100% !important; }

/* Loading overlay */
.farm3d-loading {
  position: absolute; top: 0; left: 0; right: 0; bottom: 0;
  background: linear-gradient(180deg, #1a3d1f 0%, #0a1a0f 100%);
  display: flex; flex-direction: column;
  align-items: center; justify-content: center;
  z-index: 20; transition: opacity 0.6s;
}
.farm3d-loading.hidden { opacity: 0; pointer-events: none; }
.farm3d-loading-spinner {
  width: 48px; height: 48px;
  border: 4px solid rgba(251,191,36,0.2);
  border-top: 4px solid #fbbf24;
  border-radius: 50%;
  animation: spin3d 0.8s linear infinite;
}
@keyframes spin3d { to { transform: rotate(360deg); } }
.farm3d-loading-text {
  margin-top: 12px; font-size: 13px; font-weight: 800; color: #fbbf24;
  text-shadow: 0 2px 4px rgba(0,0,0,0.5);
}

/* 3D Scene Control Hint */
.scene-controls-hint {
  position: absolute; bottom: 12px; left: 50%; transform: translateX(-50%);
  background: rgba(0,0,0,0.5); backdrop-filter: blur(6px);
  border: 1px solid rgba(255,255,255,0.1); border-radius: 12px;
  padding: 5px 14px; font-size: 10px; font-weight: 700; color: rgba(255,255,255,0.6);
  z-index: 15; pointer-events: none;
  display: flex; align-items: center; gap: 6px;
}

/* Hive Name Label Floating (HTML overlay for each 3D hive) */
.hive-3d-label {
  position: absolute; z-index: 14; pointer-events: auto; cursor: pointer;
  transform: translate(-50%, -100%);
  transition: transform 0.15s, opacity 0.15s;
}
.hive-3d-label:hover { transform: translate(-50%, -100%) scale(1.08); }
.hive-label-bubble {
  background: linear-gradient(135deg, #fbbf24, #d97706);
  border: 2px solid #78350f;
  border-radius: 14px;
  padding: 4px 10px;
  box-shadow: 0 3px 0 #78350f, 0 4px 10px rgba(0,0,0,0.3);
  text-align: center;
  white-space: nowrap;
}
.hive-label-name {
  font-size: 10px; font-weight: 900; color: #fff;
  text-shadow: 0 1px 1px rgba(0,0,0,0.4);
}
.hive-label-honey {
  font-size: 9px; font-weight: 800; color: #fef3c7;
}
/* Arrow pointer */
.hive-label-bubble::after {
  content: '';
  position: absolute; bottom: -7px; left: 50%; transform: translateX(-50%);
  width: 0; height: 0;
  border-left: 6px solid transparent;
  border-right: 6px solid transparent;
  border-top: 7px solid #78350f;
}

/* ══════════════════════════════════════════════════════════
   GROUND-LEVEL UI — BELOW 3D CANVAS
   ══════════════════════════════════════════════════════════ */
.farm-ground-ui {
  background: linear-gradient(180deg, #0f2a16 0%, #0a1a0f 100%);
  padding: 16px 14px 140px;
  position: relative;
}

.ground-section-title {
  display: flex; align-items: center; gap: 8px;
  margin-bottom: 14px;
}
.ground-section-title .pill {
  background: rgba(255,255,255,0.08);
  border: 1.5px solid rgba(251,191,36,0.3);
  border-radius: 12px;
  padding: 4px 12px;
  font-size: 12px; font-weight: 800; color: #fbbf24;
}

/* Hive List Cards (quick access below 3D) */
.hive-quick-list {
  display: flex; flex-direction: column; gap: 10px;
}
.hive-quick-card {
  background: rgba(255,255,255,0.04);
  border: 1.5px solid rgba(255,255,255,0.08);
  border-radius: 16px;
  padding: 12px 14px;
  display: flex; align-items: center; gap: 12px;
  cursor: pointer;
  transition: all 0.2s;
}
.hive-quick-card:hover, .hive-quick-card:active {
  background: rgba(251,191,36,0.08);
  border-color: rgba(251,191,36,0.3);
  transform: translateX(4px);
}
.hive-quick-card img {
  width: 50px; height: 50px; object-fit: contain;
  border-radius: 12px;
  background: rgba(255,255,255,0.05);
  padding: 4px;
  flex-shrink: 0;
}
.hive-quick-info { flex: 1; min-width: 0; }
.hive-quick-name { font-size: 13px; font-weight: 800; color: #f8fafc; }
.hive-quick-meta { font-size: 10.5px; font-weight: 700; color: #94a3b8; margin-top: 1px; }
.hive-quick-honey {
  font-size: 14px; font-weight: 900; color: #fbbf24;
  text-align: right;
  flex-shrink: 0;
}
.hive-quick-honey small { font-size: 10px; color: #94a3b8; display: block; font-weight: 700; }

/* Empty state */
.farm-empty-cta {
  text-align: center; padding: 30px 20px;
  background: rgba(255,255,255,0.03);
  border: 2px dashed rgba(251,191,36,0.2);
  border-radius: 20px;
}
.farm-empty-cta .emoji { font-size: 48px; margin-bottom: 10px; }
.farm-empty-cta .title { font-size: 16px; font-weight: 900; color: #f8fafc; }
.farm-empty-cta .sub { font-size: 12px; color: #94a3b8; margin-top: 4px; }
.farm-empty-cta .btn-cta {
  margin-top: 14px; display: inline-flex; align-items: center; gap: 6px;
  background: linear-gradient(135deg, #f59e0b, #d97706);
  border: 2px solid #78350f; border-radius: 14px;
  padding: 10px 20px;
  color: #fff; font-size: 13px; font-weight: 900;
  text-decoration: none;
  box-shadow: 0 4px 0 #78350f;
  transition: transform 0.1s;
}
.farm-empty-cta .btn-cta:active { transform: translateY(2px); box-shadow: 0 2px 0 #78350f; }

/* ── STICKY BOTTOM HARVEST ALL ── */
.meadow-sticky-bar {
  position: fixed; bottom: 84px; left: 50%; transform: translateX(-50%);
  width: calc(100% - 24px); max-width: 456px; z-index: 50;
}
.btn-harvest-all {
  width: 100%;
  background: linear-gradient(135deg, #fbbf24 0%, #f59e0b 45%, #d97706 100%);
  border: 3.5px solid #78350f; border-radius: 20px;
  box-shadow: 0 6px 0 #78350f, 0 10px 25px rgba(180,83,9,0.4);
  padding: 13px 18px; color: #fff;
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

/* ══════════════════════════════════════════════════════════
   FULLSCREEN IMMERSIVE HIVE INSPECTION MODAL
   ══════════════════════════════════════════════════════════ */
.hive-inspection-overlay {
  position: fixed; top: 0; left: 0; right: 0; bottom: 0;
  z-index: 9999;
  display: flex; align-items: center; justify-content: center;
  opacity: 0; visibility: hidden; transition: opacity 0.4s, visibility 0.4s;
}
.hive-inspection-overlay.active { opacity: 1; visibility: visible; }

.hive-inspection-vignette {
  position: absolute; top: 0; left: 0; right: 0; bottom: 0; z-index: 1;
  background: radial-gradient(circle at center,
    rgba(45,20,5,0.82) 0%, rgba(30,12,3,0.92) 35%,
    rgba(15,6,1,0.97) 65%, rgba(5,2,0,0.99) 100%
  );
  backdrop-filter: blur(8px);
}

.hive-inspection-close {
  position: absolute; top: 18px; right: 18px;
  width: 44px; height: 44px;
  background: rgba(255,255,255,0.15); border: 2px solid rgba(255,255,255,0.3);
  border-radius: 50%; color: #fef3c7; font-size: 22px;
  display: flex; align-items: center; justify-content: center;
  cursor: pointer; z-index: 100;
  transition: background 0.2s, transform 0.2s;
}
.hive-inspection-close:hover { background: rgba(255,255,255,0.25); transform: scale(1.1); }

.hive-inspection-title {
  position: absolute; top: 22px; left: 0; right: 0; text-align: center; z-index: 50;
}
.hive-inspection-title span {
  display: inline-flex; align-items: center; gap: 8px;
  background: rgba(120,53,15,0.6); border: 2px solid rgba(251,191,36,0.4);
  border-radius: 16px; padding: 6px 16px;
  font-size: 14px; font-weight: 900; color: #fde68a;
  text-shadow: 0 2px 4px rgba(0,0,0,0.5);
  backdrop-filter: blur(4px);
}

.hive-inspection-frame {
  position: relative; z-index: 50; width: 290px; max-width: 85vw;
  animation: frameSlideIn 0.5s cubic-bezier(0.34, 1.56, 0.64, 1) forwards;
}
@keyframes frameSlideIn {
  0%   { transform: scale(0.7) translateY(30px); opacity: 0; }
  100% { transform: scale(1) translateY(0); opacity: 1; }
}

.honeycomb-organic-frame {
  background-image:
    repeating-linear-gradient(45deg, rgba(120,53,15,0.3) 0px, rgba(120,53,15,0.3) 2px, transparent 2px, transparent 6px),
    linear-gradient(180deg, #92400e 0%, #78350f 35%, #5c2d0e 100%);
  border: 4px solid #451a03; border-radius: 24px;
  padding: 18px 14px;
  box-shadow: inset 0 4px 8px rgba(0,0,0,0.5), 0 0 40px rgba(251,191,36,0.2), 0 8px 24px rgba(0,0,0,0.5);
  position: relative; overflow: hidden;
}
.honeycomb-organic-frame::before {
  content: ''; position: absolute; top: 0; left: 0; right: 0; bottom: 0;
  background: repeating-linear-gradient(90deg, transparent 0px, transparent 8px, rgba(69,26,3,0.15) 8px, rgba(69,26,3,0.15) 9px);
  pointer-events: none;
}
.honeycomb-organic-frame::after {
  content: ''; position: absolute; top: 0; left: 0; right: 0; bottom: 0;
  background: radial-gradient(ellipse at center, rgba(251,191,36,0.08) 0%, transparent 70%);
  pointer-events: none;
}

/* Crawling Bees */
.hive-crawling-bee {
  position: absolute; width: 32px; height: 32px; z-index: 8; pointer-events: none;
  filter: drop-shadow(0 3px 4px rgba(0,0,0,0.6));
}
.hive-crawling-bee img { width: 100%; height: 100%; object-fit: contain; animation: beeWingFlap 0.08s linear infinite alternate; }
@keyframes beeWingFlap { from { transform: scaleX(1); } to { transform: scaleX(0.85) scaleY(1.05); } }
.bee-crawler-1 { top: 18%; left: 16%; animation: crawl1 7s ease-in-out infinite; }
.bee-crawler-2 { top: 55%; right: 18%; animation: crawl2 8s ease-in-out infinite; }
.bee-crawler-3 { bottom: 15%; left: 40%; animation: crawl3 9s ease-in-out infinite 3s; }
@keyframes crawl1 { 0%, 100% { transform: translate(0, 0) rotate(15deg); } 50% { transform: translate(45px, 20px) rotate(-25deg); } }
@keyframes crawl2 { 0%, 100% { transform: translate(0, 0) rotate(-40deg); } 50% { transform: translate(-35px, -20px) rotate(20deg); } }
@keyframes crawl3 { 0%, 100% { transform: translate(0, 0) rotate(10deg); } 50% { transform: translate(25px, -15px) rotate(-30deg); } }

/* Hex Grid */
.hex-grid { display: flex; flex-direction: column; align-items: center; gap: 4px; position: relative; z-index: 2; }
.hex-row { display: flex; gap: 6px; justify-content: center; }
.hex-row--offset { margin-left: 18px; }
.hex-cell {
  width: 36px; height: 40px; position: relative;
  clip-path: polygon(50% 0%, 100% 25%, 100% 75%, 50% 100%, 0% 75%, 0% 25%);
  transition: all 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
  display: flex; align-items: center; justify-content: center;
}
.hex-cell--empty { background: #451a03; box-shadow: inset 0 2px 4px rgba(0,0,0,0.7); }
.hex-cell--empty::after {
  content: ''; position: absolute; inset: 3px;
  clip-path: polygon(50% 0%, 100% 25%, 100% 75%, 50% 100%, 0% 75%, 0% 25%);
  background: #271202; opacity: 0.92;
}
.hex-cell--honey {
  background: linear-gradient(180deg, #fde047 0%, #eab308 45%, #b45309 100%);
  filter: drop-shadow(0 0 6px #f59e0b);
  animation: honeyGlow 2.5s ease-in-out infinite alternate;
}
.hex-cell--honey::after {
  content: ''; position: absolute; top: 4px; left: 8px; width: 14px; height: 10px;
  background: rgba(255,255,255,0.65); border-radius: 50%;
  transform: rotate(-25deg); pointer-events: none;
}
@keyframes honeyGlow { 0% { filter: drop-shadow(0 0 3px #f59e0b); } 100% { filter: drop-shadow(0 0 12px #fde047); } }

/* Inspection Info */
.hive-inspection-info { position: relative; z-index: 5; margin-top: 16px; text-align: center; }
.inspection-honey-amount {
  font-size: 28px; font-weight: 900; color: #fbbf24;
  text-shadow: 0 2px 8px rgba(251,191,36,0.4), 0 1px 2px rgba(0,0,0,0.5);
  display: flex; align-items: center; justify-content: center; gap: 8px;
}
.inspection-honey-amount i { font-size: 24px; color: #f59e0b; }
.inspection-honey-sub { font-size: 11px; font-weight: 800; color: rgba(254,243,199,0.7); margin-top: 2px; }
.inspection-bee-info { font-size: 11px; font-weight: 800; color: rgba(254,243,199,0.5); margin-top: 6px; display: flex; align-items: center; justify-content: center; gap: 5px; }

.btn-inspection-harvest {
  margin-top: 14px;
  background: linear-gradient(135deg, #f59e0b 0%, #d97706 60%, #b45309 100%);
  border: 3px solid #78350f; border-radius: 18px;
  box-shadow: 0 5px 0 #78350f, 0 8px 20px rgba(180,83,9,0.4);
  padding: 12px 24px; color: #fff;
  font-size: 14px; font-weight: 900;
  display: inline-flex; align-items: center; gap: 8px;
  cursor: pointer; font-family: 'Nunito', sans-serif;
  text-shadow: 0 2px 0 rgba(0,0,0,0.3); transition: transform 0.1s;
}
.btn-inspection-harvest:active { transform: translateY(3px); box-shadow: 0 2px 0 #78350f; }
.btn-inspection-harvest:disabled {
  background: #4b5563; border-color: #374151; color: #9ca3af;
  text-shadow: none; box-shadow: 0 3px 0 #374151; cursor: not-allowed;
}

/* Floating Honey */
.floating-honey-fly {
  position: fixed; z-index: 99999;
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
     THREE.JS 3D INTERACTIVE FARM LANDSCAPE
     ══════════════════════════════════════════════════════════ -->
<div id="farm3dCanvas">
  <div class="farm3d-loading" id="farm3dLoading">
    <div class="farm3d-loading-spinner"></div>
    <div class="farm3d-loading-text">Memuat Kebun 3D...</div>
  </div>
  <div class="scene-controls-hint">
    <i class="ph-bold ph-hand-grabbing"></i> Geser untuk memutar • Pinch untuk zoom
  </div>
  <!-- HTML Labels will be injected here by JS -->
  <div id="hiveLabelsContainer"></div>
</div>

<!-- ═══ GROUND-LEVEL UI — HIVE LIST + ACTIONS ═══ -->
<div class="farm-ground-ui">
  <div class="ground-section-title">
    <div class="pill"><i class="ph-fill ph-hexagon"></i> Sarang Lebahmu (<?= count($user_hives) ?>)</div>
  </div>

  <?php if (empty($user_hives)): ?>
    <div class="farm-empty-cta">
      <div class="emoji">🐝</div>
      <div class="title">Kebun Masih Kosong!</div>
      <div class="sub">Beli sarang pertamamu dan mulai beternak lebah 3D</div>
      <a href="/farm/shop" class="btn-cta">
        <i class="ph-fill ph-storefront"></i> Buka Toko Peternakan
      </a>
    </div>
  <?php else: ?>
    <div class="hive-quick-list">
      <?php foreach ($user_hives as $idx => $uh):
        $det = $uh['details'];
        $unharvested = (float)($det['total_honey'] ?? 0);
        $prodRate = (float)($det['hourly_production'] ?? 0);
        $sprite = !empty($uh['master_image']) ? $uh['master_image'] : '/assets/game/beehive_wooden.png';
      ?>
        <div class="hive-quick-card" onclick="openHiveInspection(<?= $uh['id'] ?>)">
          <img src="<?= htmlspecialchars($sprite) ?>" alt="<?= htmlspecialchars($uh['master_name']) ?>">
          <div class="hive-quick-info">
            <div class="hive-quick-name"><?= htmlspecialchars($uh['master_name']) ?></div>
            <div class="hive-quick-meta">
              <?= (int)$det['bee_count'] ?>/<?= (int)$uh['max_slots'] ?> Lebah • +<?= number_format($prodRate, 1) ?> ml/jam
            </div>
          </div>
          <div class="hive-quick-honey">
            <?= number_format($unharvested, 1) ?> <small>ml madu</small>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <!-- Expansion CTA -->
    <a href="/farm/shop" style="display:block;text-align:center;margin-top:14px;text-decoration:none;">
      <span style="font-size:12px;font-weight:800;color:#fbbf24;">+ Beli Sarang Baru di Toko →</span>
    </a>
  <?php endif; ?>

  <!-- STICKY BOTTOM HARVEST ALL -->
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

<!-- ══════════════════════════════════════════════════════════
     FULLSCREEN IMMERSIVE HIVE INSPECTION MODAL
     ══════════════════════════════════════════════════════════ -->
<div class="hive-inspection-overlay" id="hiveInspectionOverlay">
  <div class="hive-inspection-vignette" onclick="closeHiveInspection()"></div>
  <button class="hive-inspection-close" onclick="closeHiveInspection()">
    <i class="ph-bold ph-x"></i>
  </button>
  <div class="hive-inspection-title">
    <span id="inspectionHiveName">
      <i class="ph-fill ph-hexagon" style="color:#fbbf24;"></i> Sarang Lebah
    </span>
  </div>
  <div class="hive-inspection-frame">
    <div class="honeycomb-organic-frame">
      <div class="hive-crawling-bee bee-crawler-1"><img src="/assets/game/bee_worker.png" alt="Worker"></div>
      <div class="hive-crawling-bee bee-crawler-2"><img src="/assets/game/bee_golden.png" onerror="this.src='/assets/game/bee_worker.png'" alt="Worker"></div>
      <div class="hive-crawling-bee bee-crawler-3"><img src="/assets/game/bee_worker.png" alt="Worker"></div>
      <div class="hex-grid" id="inspectionHexGrid"></div>
    </div>
    <div class="hive-inspection-info">
      <div class="inspection-honey-amount">
        <i class="ph-fill ph-drop"></i>
        <span id="inspectionHoneyAmt">0.00 ml</span>
      </div>
      <div class="inspection-honey-sub" id="inspectionHoneyCap">Kapasitas: 0 / 0 ml (0%)</div>
      <div class="inspection-bee-info" id="inspectionBeeInfo">
        <i class="ph-fill ph-bug-beetle"></i><span>0 Lebah Aktif</span>
      </div>
      <button type="button" class="btn-inspection-harvest" id="btnInspectionHarvest" onclick="harvestInspectedHive()">
        <i class="ph-fill ph-drop"></i><span>PANEN MADU SARANG INI</span>
      </button>
    </div>
  </div>
</div>

<?= csrf_field() ?>

<!-- THREE.JS CDN -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/three.js/r128/three.min.js"></script>
<script>
// ══════════════════════════════════════════════════════════
// DATA FROM PHP
// ══════════════════════════════════════════════════════════
const USER_HIVES_MAP = <?= json_encode(array_column($user_hives, null, 'id')) ?>;
const HIVES_ARRAY = <?= json_encode(array_values($user_hives)) ?>;
let currentInspectedHiveId = 0;

// ══════════════════════════════════════════════════════════
// THREE.JS 3D SCENE BUILDER
// ══════════════════════════════════════════════════════════
(function() {
  const container = document.getElementById('farm3dCanvas');
  const loadingEl = document.getElementById('farm3dLoading');
  const labelsContainer = document.getElementById('hiveLabelsContainer');

  // Scene setup
  const scene = new THREE.Scene();
  scene.fog = new THREE.FogExp2(0x87ceeb, 0.012);

  // Camera
  const camera = new THREE.PerspectiveCamera(55, container.clientWidth / container.clientHeight, 0.1, 200);
  camera.position.set(0, 8, 14);
  camera.lookAt(0, 0, 0);

  // Renderer
  const renderer = new THREE.WebGLRenderer({ antialias: true, alpha: false });
  renderer.setSize(container.clientWidth, container.clientHeight);
  renderer.setPixelRatio(Math.min(window.devicePixelRatio, 2));
  renderer.shadowMap.enabled = true;
  renderer.shadowMap.type = THREE.PCFSoftShadowMap;
  renderer.outputEncoding = THREE.sRGBEncoding;
  renderer.toneMapping = THREE.ACESFilmicToneMapping;
  renderer.toneMappingExposure = 1.1;
  container.insertBefore(renderer.domElement, container.firstChild);

  // Sky gradient background
  const skyCanvas = document.createElement('canvas');
  skyCanvas.width = 2; skyCanvas.height = 256;
  const skyCtx = skyCanvas.getContext('2d');
  const skyGrad = skyCtx.createLinearGradient(0, 0, 0, 256);
  skyGrad.addColorStop(0, '#1e40af');
  skyGrad.addColorStop(0.25, '#3b82f6');
  skyGrad.addColorStop(0.5, '#87ceeb');
  skyGrad.addColorStop(0.75, '#fef3c7');
  skyGrad.addColorStop(1, '#fbbf24');
  skyCtx.fillStyle = skyGrad;
  skyCtx.fillRect(0, 0, 2, 256);
  const skyTexture = new THREE.CanvasTexture(skyCanvas);
  scene.background = skyTexture;

  // ── LIGHTS ──
  const ambientLight = new THREE.AmbientLight(0xffeedd, 0.5);
  scene.add(ambientLight);

  const sunLight = new THREE.DirectionalLight(0xfff4e0, 1.2);
  sunLight.position.set(10, 15, 8);
  sunLight.castShadow = true;
  sunLight.shadow.mapSize.width = 1024;
  sunLight.shadow.mapSize.height = 1024;
  sunLight.shadow.camera.near = 0.5;
  sunLight.shadow.camera.far = 50;
  sunLight.shadow.camera.left = -15;
  sunLight.shadow.camera.right = 15;
  sunLight.shadow.camera.top = 15;
  sunLight.shadow.camera.bottom = -15;
  sunLight.shadow.bias = -0.001;
  scene.add(sunLight);

  const fillLight = new THREE.DirectionalLight(0x88bbff, 0.3);
  fillLight.position.set(-5, 8, -5);
  scene.add(fillLight);

  // Sun sphere (visual)
  const sunGeo = new THREE.SphereGeometry(0.8, 16, 16);
  const sunMat = new THREE.MeshBasicMaterial({ color: 0xfffbe0 });
  const sunMesh = new THREE.Mesh(sunGeo, sunMat);
  sunMesh.position.set(18, 20, -10);
  scene.add(sunMesh);

  // ── GROUND TERRAIN ──
  const groundGeo = new THREE.PlaneGeometry(60, 60, 32, 32);
  // Undulate the ground slightly for natural look
  const posAttr = groundGeo.getAttribute('position');
  for (let i = 0; i < posAttr.count; i++) {
    const x = posAttr.getX(i);
    const y = posAttr.getY(i);
    const z = Math.sin(x * 0.3) * 0.15 + Math.cos(y * 0.4) * 0.12 + Math.sin(x * 0.8 + y * 0.6) * 0.06;
    posAttr.setZ(i, z);
  }
  groundGeo.computeVertexNormals();

  const groundMat = new THREE.MeshStandardMaterial({
    color: 0x2d8a4e,
    roughness: 0.95,
    metalness: 0.0,
    flatShading: false,
  });
  const ground = new THREE.Mesh(groundGeo, groundMat);
  ground.rotation.x = -Math.PI / 2;
  ground.receiveShadow = true;
  scene.add(ground);

  // Darker ground ring (edge fade)
  const groundEdge = new THREE.Mesh(
    new THREE.RingGeometry(20, 30, 32),
    new THREE.MeshStandardMaterial({ color: 0x1a5c30, roughness: 1, transparent: true, opacity: 0.5 })
  );
  groundEdge.rotation.x = -Math.PI / 2;
  groundEdge.position.y = 0.01;
  scene.add(groundEdge);

  // ── PROCEDURAL TREES ──
  function createTree(x, z, scale) {
    const group = new THREE.Group();

    // Trunk
    const trunkGeo = new THREE.CylinderGeometry(0.12 * scale, 0.18 * scale, 1.2 * scale, 6);
    const trunkMat = new THREE.MeshStandardMaterial({ color: 0x5c3d1e, roughness: 0.9 });
    const trunk = new THREE.Mesh(trunkGeo, trunkMat);
    trunk.position.y = 0.6 * scale;
    trunk.castShadow = true;
    group.add(trunk);

    // Foliage layers (3 spheres stacked)
    const foliageMat = new THREE.MeshStandardMaterial({ color: 0x2d7a3a, roughness: 0.8 });
    const sizes = [0.7, 0.55, 0.35];
    const heights = [1.4, 1.9, 2.3];
    sizes.forEach((s, i) => {
      const fg = new THREE.Mesh(new THREE.SphereGeometry(s * scale, 8, 6), foliageMat);
      fg.position.y = heights[i] * scale;
      fg.castShadow = true;
      group.add(fg);
    });

    group.position.set(x, 0, z);
    scene.add(group);
    return group;
  }

  // Place trees around the perimeter
  const treePositions = [
    [-8, -6, 1.2], [-10, -2, 0.9], [-9, 3, 1.1], [-7, 7, 0.8],
    [8, -5, 1.0], [10, -1, 0.85], [9, 4, 1.1], [7, 8, 0.7],
    [-5, -9, 0.7], [0, -10, 0.9], [5, -8, 0.8],
    [-6, 9, 0.6], [4, 9, 0.75],
  ];
  treePositions.forEach(([x, z, s]) => createTree(x, z, s));

  // ── PROCEDURAL ROCKS ──
  function createRock(x, z, scale) {
    const geo = new THREE.DodecahedronGeometry(0.3 * scale, 0);
    const mat = new THREE.MeshStandardMaterial({ color: 0x6b7280, roughness: 0.95, flatShading: true });
    const rock = new THREE.Mesh(geo, mat);
    rock.position.set(x, 0.12 * scale, z);
    rock.rotation.set(Math.random() * 0.5, Math.random() * Math.PI, 0);
    rock.scale.y = 0.6;
    rock.castShadow = true;
    rock.receiveShadow = true;
    scene.add(rock);
  }

  [[-3, -4, 1.2], [4, -3, 0.8], [-5, 2, 1.0], [6, 1, 0.6], [1, 5, 0.9], [-2, 6, 0.7]].forEach(([x, z, s]) => createRock(x, z, s));

  // ── WILDFLOWER CLUSTERS ──
  function createFlowerCluster(x, z) {
    const group = new THREE.Group();
    const colors = [0xff69b4, 0xffd700, 0xff6347, 0x9370db, 0xffa500];
    for (let i = 0; i < 5; i++) {
      const stemGeo = new THREE.CylinderGeometry(0.015, 0.015, 0.25, 4);
      const stemMat = new THREE.MeshStandardMaterial({ color: 0x2d8a4e });
      const stem = new THREE.Mesh(stemGeo, stemMat);
      const angle = (i / 5) * Math.PI * 2;
      const r = 0.15 + Math.random() * 0.1;
      stem.position.set(Math.cos(angle) * r, 0.12, Math.sin(angle) * r);
      group.add(stem);

      const petalGeo = new THREE.SphereGeometry(0.06, 6, 4);
      const petalMat = new THREE.MeshStandardMaterial({ color: colors[i % colors.length] });
      const petal = new THREE.Mesh(petalGeo, petalMat);
      petal.position.set(Math.cos(angle) * r, 0.28, Math.sin(angle) * r);
      group.add(petal);
    }
    group.position.set(x, 0, z);
    scene.add(group);
  }

  [[-2, -2], [3, -1], [-1, 3], [5, 3], [-4, -1], [2, -5], [-3, 5], [1, 7]].forEach(([x, z]) => createFlowerCluster(x, z));

  // ── 3D BEEHIVE STRUCTURES ──
  const hive3DObjects = [];
  const hiveWorldPositions = [];

  function createBeehive3D(index) {
    const group = new THREE.Group();
    group.userData = { hiveIndex: index };

    // Wooden stacked box beehive
    const boxColors = [0xb8860b, 0xcd853f, 0xdaa520];

    // Base platform
    const baseMat = new THREE.MeshStandardMaterial({ color: 0x8b4513, roughness: 0.85 });
    const base = new THREE.Mesh(new THREE.BoxGeometry(1.1, 0.12, 0.9), baseMat);
    base.position.y = 0.06;
    base.castShadow = true;
    base.receiveShadow = true;
    group.add(base);

    // 3 stacked hive boxes (brood boxes)
    for (let i = 0; i < 3; i++) {
      const mat = new THREE.MeshStandardMaterial({
        color: boxColors[i % boxColors.length],
        roughness: 0.8, metalness: 0.05,
      });
      const box = new THREE.Mesh(new THREE.BoxGeometry(1.0, 0.35, 0.8), mat);
      box.position.y = 0.3 + i * 0.37;
      box.castShadow = true;
      group.add(box);

      // Subtle line separators
      const lineGeo = new THREE.BoxGeometry(1.02, 0.02, 0.82);
      const lineMat = new THREE.MeshStandardMaterial({ color: 0x5c3d1e });
      const line = new THREE.Mesh(lineGeo, lineMat);
      line.position.y = 0.12 + i * 0.37 + 0.18;
      group.add(line);
    }

    // Roof (pitched)
    const roofGeo = new THREE.ConeGeometry(0.7, 0.35, 4);
    const roofMat = new THREE.MeshStandardMaterial({ color: 0x654321, roughness: 0.9 });
    const roof = new THREE.Mesh(roofGeo, roofMat);
    roof.position.y = 1.5;
    roof.rotation.y = Math.PI / 4;
    roof.castShadow = true;
    group.add(roof);

    // Entrance hole
    const entranceGeo = new THREE.CircleGeometry(0.08, 8);
    const entranceMat = new THREE.MeshStandardMaterial({ color: 0x1a0a00 });
    const entrance = new THREE.Mesh(entranceGeo, entranceMat);
    entrance.position.set(0, 0.35, 0.41);
    group.add(entrance);

    // Landing board
    const landGeo = new THREE.BoxGeometry(0.2, 0.02, 0.12);
    const landMat = new THREE.MeshStandardMaterial({ color: 0x8b4513 });
    const landBoard = new THREE.Mesh(landGeo, landMat);
    landBoard.position.set(0, 0.25, 0.46);
    landBoard.rotation.x = -0.2;
    group.add(landBoard);

    return group;
  }

  // Place hives in a natural curved layout
  function getHivePosition(index, total) {
    if (total <= 0) return { x: 0, z: 0 };
    // Arc layout
    const spacing = 2.8;
    const maxPerRow = 4;
    const row = Math.floor(index / maxPerRow);
    const col = index % maxPerRow;
    const rowCount = Math.min(total - row * maxPerRow, maxPerRow);
    const offsetX = -(rowCount - 1) * spacing / 2;
    return {
      x: offsetX + col * spacing + (row % 2 === 1 ? spacing * 0.5 : 0),
      z: -1 + row * 2.5
    };
  }

  HIVES_ARRAY.forEach((hive, idx) => {
    const hive3D = createBeehive3D(idx);
    const pos = getHivePosition(idx, HIVES_ARRAY.length);
    hive3D.position.set(pos.x, 0, pos.z);
    hive3D.userData.hiveId = hive.id;
    scene.add(hive3D);
    hive3DObjects.push(hive3D);
    hiveWorldPositions.push(new THREE.Vector3(pos.x, 1.8, pos.z));
  });

  // Add signpost if no hives
  if (HIVES_ARRAY.length === 0) {
    const postGeo = new THREE.CylinderGeometry(0.06, 0.06, 2, 6);
    const postMat = new THREE.MeshStandardMaterial({ color: 0x5c3d1e });
    const post = new THREE.Mesh(postGeo, postMat);
    post.position.set(0, 1, 0);
    post.castShadow = true;
    scene.add(post);

    const signGeo = new THREE.BoxGeometry(1.5, 0.6, 0.08);
    const signMat = new THREE.MeshStandardMaterial({ color: 0x8b4513 });
    const sign = new THREE.Mesh(signGeo, signMat);
    sign.position.set(0, 1.8, 0);
    sign.castShadow = true;
    scene.add(sign);
  }

  // ── ANIMATED BEES (3D particles) ──
  const bees3D = [];
  const beeCount = Math.min(HIVES_ARRAY.length * 3 + 2, 20);

  function createBee3D() {
    const group = new THREE.Group();

    // Body (elongated sphere)
    const bodyGeo = new THREE.SphereGeometry(0.06, 8, 6);
    bodyGeo.scale(1, 0.7, 1.3);
    const bodyMat = new THREE.MeshStandardMaterial({ color: 0xf5a623 });
    const body = new THREE.Mesh(bodyGeo, bodyMat);
    group.add(body);

    // Stripes
    const stripeMat = new THREE.MeshStandardMaterial({ color: 0x1a1a1a });
    for (let i = 0; i < 2; i++) {
      const stripe = new THREE.Mesh(new THREE.TorusGeometry(0.055, 0.012, 4, 8), stripeMat);
      stripe.position.z = -0.02 + i * 0.04;
      stripe.rotation.x = Math.PI / 2;
      group.add(stripe);
    }

    // Wings
    const wingGeo = new THREE.PlaneGeometry(0.1, 0.06);
    const wingMat = new THREE.MeshStandardMaterial({ color: 0xffffff, transparent: true, opacity: 0.5, side: THREE.DoubleSide });
    const wingL = new THREE.Mesh(wingGeo, wingMat);
    wingL.position.set(-0.06, 0.04, 0);
    wingL.rotation.z = 0.3;
    group.add(wingL);
    const wingR = new THREE.Mesh(wingGeo, wingMat);
    wingR.position.set(0.06, 0.04, 0);
    wingR.rotation.z = -0.3;
    group.add(wingR);

    group.userData = {
      wingL, wingR,
      phase: Math.random() * Math.PI * 2,
      speed: 0.8 + Math.random() * 1.2,
      radius: 1.5 + Math.random() * 3,
      height: 1.5 + Math.random() * 2,
      centerX: (Math.random() - 0.5) * 8,
      centerZ: (Math.random() - 0.5) * 6,
    };

    scene.add(group);
    return group;
  }

  for (let i = 0; i < beeCount; i++) {
    bees3D.push(createBee3D());
  }

  // ── CLOUDS (3D) ──
  function createCloud(x, y, z) {
    const group = new THREE.Group();
    const cloudMat = new THREE.MeshStandardMaterial({ color: 0xffffff, roughness: 1, transparent: true, opacity: 0.85 });
    const radii = [0.8, 0.6, 0.5, 0.7];
    const offsets = [[-0.5, 0, 0], [0.4, 0.15, 0.1], [-0.1, 0.2, -0.2], [0.6, -0.1, 0.15]];
    radii.forEach((r, i) => {
      const s = new THREE.Mesh(new THREE.SphereGeometry(r, 8, 6), cloudMat);
      s.position.set(offsets[i][0], offsets[i][1], offsets[i][2]);
      group.add(s);
    });
    group.position.set(x, y, z);
    group.userData.speed = 0.02 + Math.random() * 0.03;
    scene.add(group);
    return group;
  }

  const clouds = [
    createCloud(-8, 10, -15),
    createCloud(5, 11, -18),
    createCloud(12, 9, -12),
    createCloud(-3, 12, -20),
  ];

  // ── POLLEN PARTICLES ──
  const pollenGeo = new THREE.BufferGeometry();
  const pollenCount = 60;
  const pollenPositions = new Float32Array(pollenCount * 3);
  for (let i = 0; i < pollenCount; i++) {
    pollenPositions[i * 3] = (Math.random() - 0.5) * 20;
    pollenPositions[i * 3 + 1] = 0.5 + Math.random() * 4;
    pollenPositions[i * 3 + 2] = (Math.random() - 0.5) * 20;
  }
  pollenGeo.setAttribute('position', new THREE.BufferAttribute(pollenPositions, 3));
  const pollenMat = new THREE.PointsMaterial({ color: 0xfde047, size: 0.04, transparent: true, opacity: 0.7 });
  const pollenSystem = new THREE.Points(pollenGeo, pollenMat);
  scene.add(pollenSystem);

  // ── ORBIT CONTROLS (manual — lightweight) ──
  let isPointerDown = false;
  let pointerX = 0, pointerY = 0;
  let cameraTheta = 0; // horizontal angle
  let cameraPhi = 0.6; // vertical angle (0.3 to 1.2)
  let cameraRadius = 14;
  const targetCenter = new THREE.Vector3(0, 1, 0);

  function updateCameraFromOrbit() {
    const x = cameraRadius * Math.sin(cameraPhi) * Math.sin(cameraTheta);
    const y = cameraRadius * Math.cos(cameraPhi);
    const z = cameraRadius * Math.sin(cameraPhi) * Math.cos(cameraTheta);
    camera.position.set(x + targetCenter.x, y + targetCenter.y, z + targetCenter.z);
    camera.lookAt(targetCenter);
  }

  const canvas = renderer.domElement;
  canvas.addEventListener('pointerdown', (e) => {
    isPointerDown = true;
    pointerX = e.clientX;
    pointerY = e.clientY;
  });
  canvas.addEventListener('pointermove', (e) => {
    if (!isPointerDown) return;
    const dx = e.clientX - pointerX;
    const dy = e.clientY - pointerY;
    cameraTheta += dx * 0.005;
    cameraPhi = Math.max(0.3, Math.min(1.3, cameraPhi + dy * 0.005));
    pointerX = e.clientX;
    pointerY = e.clientY;
  });
  canvas.addEventListener('pointerup', () => { isPointerDown = false; });
  canvas.addEventListener('pointerleave', () => { isPointerDown = false; });
  canvas.addEventListener('wheel', (e) => {
    cameraRadius = Math.max(6, Math.min(25, cameraRadius + e.deltaY * 0.01));
  }, { passive: true });

  // Touch zoom
  let lastTouchDist = 0;
  canvas.addEventListener('touchstart', (e) => {
    if (e.touches.length === 2) {
      const dx = e.touches[0].clientX - e.touches[1].clientX;
      const dy = e.touches[0].clientY - e.touches[1].clientY;
      lastTouchDist = Math.sqrt(dx * dx + dy * dy);
    }
  }, { passive: true });
  canvas.addEventListener('touchmove', (e) => {
    if (e.touches.length === 2) {
      const dx = e.touches[0].clientX - e.touches[1].clientX;
      const dy = e.touches[0].clientY - e.touches[1].clientY;
      const dist = Math.sqrt(dx * dx + dy * dy);
      const delta = lastTouchDist - dist;
      cameraRadius = Math.max(6, Math.min(25, cameraRadius + delta * 0.03));
      lastTouchDist = dist;
    }
  }, { passive: true });

  // ── CLICK DETECTION ON 3D HIVES ──
  const raycaster = new THREE.Raycaster();
  const mouse = new THREE.Vector2();

  canvas.addEventListener('click', (e) => {
    const rect = canvas.getBoundingClientRect();
    mouse.x = ((e.clientX - rect.left) / rect.width) * 2 - 1;
    mouse.y = -((e.clientY - rect.top) / rect.height) * 2 + 1;
    raycaster.setFromCamera(mouse, camera);

    // Check intersection with hive groups
    for (let i = 0; i < hive3DObjects.length; i++) {
      const intersects = raycaster.intersectObjects(hive3DObjects[i].children, true);
      if (intersects.length > 0) {
        const hiveId = hive3DObjects[i].userData.hiveId;
        openHiveInspection(hiveId);
        // Bounce animation
        const obj = hive3DObjects[i];
        obj.userData.bounceTime = 0;
        break;
      }
    }
  });

  // ── PROJECT 3D HIVE LABELS TO 2D SCREEN ──
  function updateHiveLabels() {
    labelsContainer.innerHTML = '';
    hive3DObjects.forEach((obj, i) => {
      const hive = HIVES_ARRAY[i];
      if (!hive) return;

      const worldPos = hiveWorldPositions[i].clone();
      worldPos.project(camera);

      const halfW = container.clientWidth / 2;
      const halfH = container.clientHeight / 2;
      const sx = (worldPos.x * halfW) + halfW;
      const sy = -(worldPos.y * halfH) + halfH;

      // Only show if in front of camera
      if (worldPos.z > 1) return;

      const det = hive.details || {};
      const honey = parseFloat(det.total_honey) || 0;

      const label = document.createElement('div');
      label.className = 'hive-3d-label';
      label.style.left = sx + 'px';
      label.style.top = sy + 'px';
      label.onclick = () => openHiveInspection(hive.id);
      label.innerHTML = `
        <div class="hive-label-bubble">
          <div class="hive-label-name">${hive.master_name}</div>
          <div class="hive-label-honey"><i class="ph-fill ph-drop"></i> ${honey.toFixed(1)} ml</div>
        </div>
      `;
      labelsContainer.appendChild(label);
    });
  }

  // ── ANIMATION LOOP ──
  const clock = new THREE.Clock();

  function animate() {
    requestAnimationFrame(animate);
    const elapsed = clock.getElapsedTime();
    const delta = clock.getDelta();

    // Update orbit camera
    updateCameraFromOrbit();

    // Animate bees
    bees3D.forEach(bee => {
      const d = bee.userData;
      const t = elapsed * d.speed + d.phase;
      bee.position.x = d.centerX + Math.sin(t) * d.radius;
      bee.position.y = d.height + Math.sin(t * 2.5) * 0.3;
      bee.position.z = d.centerZ + Math.cos(t) * d.radius;
      bee.rotation.y = t + Math.PI / 2;

      // Wing flap
      d.wingL.rotation.z = 0.3 + Math.sin(elapsed * 30) * 0.3;
      d.wingR.rotation.z = -0.3 - Math.sin(elapsed * 30) * 0.3;
    });

    // Animate clouds drifting
    clouds.forEach(c => {
      c.position.x += c.userData.speed;
      if (c.position.x > 20) c.position.x = -20;
    });

    // Animate pollen floating
    const pollenPos = pollenSystem.geometry.getAttribute('position');
    for (let i = 0; i < pollenCount; i++) {
      let y = pollenPos.getY(i) + 0.003;
      let x = pollenPos.getX(i) + Math.sin(elapsed + i) * 0.002;
      if (y > 5) { y = 0.3; x = (Math.random() - 0.5) * 20; }
      pollenPos.setY(i, y);
      pollenPos.setX(i, x);
    }
    pollenPos.needsUpdate = true;

    // Hive bounce animation
    hive3DObjects.forEach(obj => {
      if (obj.userData.bounceTime !== undefined) {
        obj.userData.bounceTime += 0.08;
        const t = obj.userData.bounceTime;
        if (t < Math.PI) {
          obj.position.y = Math.sin(t) * 0.3;
        } else {
          obj.position.y = 0;
          delete obj.userData.bounceTime;
        }
      }
    });

    // Subtle hive idle sway
    hive3DObjects.forEach((obj, i) => {
      if (obj.userData.bounceTime === undefined) {
        obj.rotation.y = Math.sin(elapsed * 0.5 + i) * 0.02;
      }
    });

    renderer.render(scene, camera);

    // Update 2D labels overlay
    updateHiveLabels();
  }

  // Start
  updateCameraFromOrbit();
  animate();

  // Hide loading
  setTimeout(() => loadingEl.classList.add('hidden'), 500);

  // Resize handler
  window.addEventListener('resize', () => {
    camera.aspect = container.clientWidth / container.clientHeight;
    camera.updateProjectionMatrix();
    renderer.setSize(container.clientWidth, container.clientHeight);
  });

})();

// ══════════════════════════════════════════════════════════
// INTERACTION LOGIC (Inspection Modal, Harvest, etc.)
// ══════════════════════════════════════════════════════════

function renderHexagonCells(fillPercentage, gridId) {
  const totalCells = 24;
  const honeyCellsCount = Math.min(totalCells, Math.round(totalCells * (fillPercentage / 100)));
  const grid = document.getElementById(gridId || 'inspectionHexGrid');
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
        cell.className = 'hex-cell ' + (cellIndex < honeyCellsCount ? 'hex-cell--honey' : 'hex-cell--empty');
        rowDiv.appendChild(cell);
        cellIndex++;
      }
    }
    grid.appendChild(rowDiv);
  });
}

function openHiveInspection(hiveId) {
  currentInspectedHiveId = hiveId;
  const hiveData = USER_HIVES_MAP[hiveId];
  if (!hiveData) return;

  FarmAudio.playPop();
  FarmAudio.playBee(0.5);

  const det = hiveData.details || {};
  const totalHoney = parseFloat(det.total_honey) || 0;
  const maxCap = parseFloat(det.max_capacity) || 100;
  const pct = parseFloat(det.fill_percentage) || 0;
  const beeCount = parseInt(det.bee_count) || 0;
  const maxSlots = parseInt(hiveData.max_slots) || 0;
  const hourlyProd = parseFloat(det.hourly_production) || 0;

  document.getElementById('inspectionHiveName').innerHTML =
    '<i class="ph-fill ph-hexagon" style="color:#fbbf24;"></i> ' + (hiveData.master_name || 'Sarang Lebah');
  document.getElementById('inspectionHoneyAmt').innerText = totalHoney.toFixed(2) + ' ml';
  document.getElementById('inspectionHoneyCap').innerText =
    'Kapasitas: ' + totalHoney.toFixed(1) + ' / ' + maxCap.toFixed(0) + ' ml (' + pct + '%)';
  document.getElementById('inspectionBeeInfo').innerHTML =
    '<i class="ph-fill ph-bug-beetle"></i> <span>' + beeCount + '/' + maxSlots + ' Lebah Aktif • +' + hourlyProd.toFixed(1) + ' ml/jam</span>';

  const btn = document.getElementById('btnInspectionHarvest');
  if (btn) btn.disabled = totalHoney < 0.1;

  renderHexagonCells(pct, 'inspectionHexGrid');
  document.getElementById('hiveInspectionOverlay').classList.add('active');
  document.body.style.overflow = 'hidden';
}

function closeHiveInspection() {
  document.getElementById('hiveInspectionOverlay').classList.remove('active');
  document.body.style.overflow = '';
  currentInspectedHiveId = 0;
}

document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeHiveInspection(); });

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

function harvestInspectedHive() {
  if (!currentInspectedHiveId) return;
  const btn = document.getElementById('btnInspectionHarvest');
  if (!btn || btn.disabled) return;
  btn.disabled = true;
  const originalText = btn.innerHTML;
  btn.innerHTML = '<i class="ph-bold ph-spinner ph-spin"></i> Memanen...';
  FarmAudio.playHarvest();

  const csrfToken = document.querySelector('input[name="_csrf"]')?.value || '';
  const fd = new FormData();
  fd.append('action', 'harvest_hive');
  fd.append('hive_id', currentInspectedHiveId);
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
      document.getElementById('inspectionHoneyAmt').innerText = '0.00 ml';
      renderHexagonCells(0, 'inspectionHexGrid');
      if (USER_HIVES_MAP[currentInspectedHiveId]?.details) {
        USER_HIVES_MAP[currentInspectedHiveId].details.total_honey = 0;
        USER_HIVES_MAP[currentInspectedHiveId].details.fill_percentage = 0;
      }
      btn.innerHTML = '<i class="ph-bold ph-check"></i> Selesai Dipanen!';
      setTimeout(() => { btn.innerHTML = originalText; btn.disabled = true; }, 1500);
    } else {
      alert(data.msg || 'Gagal memanen sarang.');
      btn.disabled = false; btn.innerHTML = originalText;
    }
  })
  .catch(() => { btn.disabled = false; btn.innerHTML = originalText; alert('Terjadi kesalahan jaringan.'); });
}

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
      const totalBadge = document.getElementById('totalUnharvestedBadge');
      if (totalBadge) totalBadge.innerText = '0.0 ml';
      btn.innerHTML = '<i class="ph-bold ph-check"></i> ' + (data.msg || 'Panen Berhasil!');
      setTimeout(() => { btn.innerHTML = originalText; btn.disabled = true; }, 2000);
    } else {
      alert(data.msg || 'Gagal memanen semua madu.');
      btn.disabled = false; btn.innerHTML = originalText;
    }
  })
  .catch(() => { btn.disabled = false; btn.innerHTML = originalText; alert('Terjadi kendala jaringan.'); });
}
</script>

<?php require dirname(__DIR__) . '/partials/footer.php'; ?>
