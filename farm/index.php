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

$uStmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$uStmt->execute([$user['id']]);
$user = $uStmt->fetch(PDO::FETCH_ASSOC);

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

$active_stall = BeeFarm::getUserActiveStall($pdo, (int)$user['id']);

$pageTitle = 'Kebun Sarang Lebah Cuan — 3D Tycoon Simulator';
$activePage = 'farm';
$farmSubPage = 'meadow';
require dirname(__DIR__) . '/partials/header.php';
?>

<style>
body { background: #0a1a0f !important; font-family: 'Nunito', sans-serif; overflow-x: hidden; }

#farm3dCanvas {
  position: relative; width: 100%; height: 70vh; min-height: 420px;
  border-bottom: 4px solid #14532d; touch-action: pan-y; cursor: grab; overflow: hidden;
}
#farm3dCanvas:active { cursor: grabbing; }
#farm3dCanvas canvas { display: block; width: 100% !important; height: 100% !important; }

.farm3d-loading {
  position: absolute; top: 0; left: 0; right: 0; bottom: 0;
  background: linear-gradient(180deg, #1a3d1f 0%, #0a1a0f 100%);
  display: flex; flex-direction: column; align-items: center; justify-content: center;
  z-index: 20; transition: opacity 0.8s;
}
.farm3d-loading.hidden { opacity: 0; pointer-events: none; }
.farm3d-loading-spinner {
  width: 52px; height: 52px;
  border: 4px solid rgba(251,191,36,0.15); border-top: 4px solid #fbbf24;
  border-radius: 50%; animation: spin3d 0.8s linear infinite;
}
@keyframes spin3d { to { transform: rotate(360deg); } }
.farm3d-loading-text { margin-top: 14px; font-size: 14px; font-weight: 800; color: #fbbf24; }

.scene-controls-hint {
  position: absolute; bottom: 14px; left: 50%; transform: translateX(-50%);
  background: rgba(0,0,0,0.55); backdrop-filter: blur(8px);
  border: 1px solid rgba(255,255,255,0.08); border-radius: 14px;
  padding: 6px 16px; font-size: 10.5px; font-weight: 700; color: rgba(255,255,255,0.55);
  z-index: 15; pointer-events: none;
}

/* Ambient sound toggle floating */
.ambient-sound-toggle {
  position: absolute; top: 12px; right: 12px; z-index: 16;
  background: rgba(0,0,0,0.5); backdrop-filter: blur(6px);
  border: 1.5px solid rgba(255,255,255,0.12); border-radius: 12px;
  padding: 6px 12px; font-size: 11px; font-weight: 800; color: #fbbf24;
  cursor: pointer; display: flex; align-items: center; gap: 5px;
  transition: background 0.2s;
}
.ambient-sound-toggle:hover { background: rgba(0,0,0,0.7); }

/* 3D Label Overlays */
#hiveLabelsContainer { position: absolute; top: 0; left: 0; width: 100%; height: 100%; pointer-events: none; z-index: 14; }
.hive-3d-label {
  position: absolute; pointer-events: auto; cursor: pointer;
  transform: translate(-50%, -100%); transition: transform 0.15s;
}
.hive-3d-label:hover { transform: translate(-50%, -100%) scale(1.1); }
.hive-label-bubble {
  background: linear-gradient(135deg, #fbbf24, #d97706);
  border: 2px solid #78350f; border-radius: 14px;
  padding: 5px 11px; box-shadow: 0 3px 0 #78350f, 0 4px 12px rgba(0,0,0,0.35);
  text-align: center; white-space: nowrap; position: relative;
}
.hive-label-name { font-size: 10.5px; font-weight: 900; color: #fff; text-shadow: 0 1px 1px rgba(0,0,0,0.4); }
.hive-label-honey { font-size: 9px; font-weight: 800; color: #fef3c7; }
.hive-label-bubble::after {
  content: ''; position: absolute; bottom: -7px; left: 50%; transform: translateX(-50%);
  border-left: 6px solid transparent; border-right: 6px solid transparent; border-top: 7px solid #78350f;
}

/* Ground UI */
.farm-ground-ui {
  background: linear-gradient(180deg, #0f2a16 0%, #0a1a0f 100%);
  padding: 16px 14px 150px;
}
.ground-section-title { display: flex; align-items: center; gap: 8px; margin-bottom: 14px; }
.ground-section-title .pill {
  background: rgba(255,255,255,0.06); border: 1.5px solid rgba(251,191,36,0.25);
  border-radius: 12px; padding: 5px 14px; font-size: 12px; font-weight: 800; color: #fbbf24;
}

.hive-quick-list { display: flex; flex-direction: column; gap: 10px; }
.hive-quick-card {
  background: rgba(255,255,255,0.035); border: 1.5px solid rgba(255,255,255,0.07);
  border-radius: 16px; padding: 12px 14px; display: flex; align-items: center; gap: 12px;
  cursor: pointer; transition: all 0.2s;
}
.hive-quick-card:hover { background: rgba(251,191,36,0.08); border-color: rgba(251,191,36,0.3); transform: translateX(4px); }
.hive-quick-card img { width: 50px; height: 50px; object-fit: contain; border-radius: 12px; background: rgba(255,255,255,0.05); padding: 4px; flex-shrink: 0; }
.hive-quick-info { flex: 1; min-width: 0; }
.hive-quick-name { font-size: 13px; font-weight: 800; color: #f8fafc; }
.hive-quick-meta { font-size: 10.5px; font-weight: 700; color: #94a3b8; margin-top: 1px; }
.hive-quick-honey { font-size: 14px; font-weight: 900; color: #fbbf24; text-align: right; flex-shrink: 0; }
.hive-quick-honey small { font-size: 10px; color: #94a3b8; display: block; font-weight: 700; }

.farm-empty-cta {
  text-align: center; padding: 30px 20px;
  background: rgba(255,255,255,0.03); border: 2px dashed rgba(251,191,36,0.2); border-radius: 20px;
}
.farm-empty-cta .emoji { font-size: 48px; margin-bottom: 10px; }
.farm-empty-cta .title { font-size: 16px; font-weight: 900; color: #f8fafc; }
.farm-empty-cta .sub { font-size: 12px; color: #94a3b8; margin-top: 4px; }
.farm-empty-cta .btn-cta {
  margin-top: 14px; display: inline-flex; align-items: center; gap: 6px;
  background: linear-gradient(135deg, #f59e0b, #d97706); border: 2px solid #78350f; border-radius: 14px;
  padding: 10px 20px; color: #fff; font-size: 13px; font-weight: 900; text-decoration: none;
  box-shadow: 0 4px 0 #78350f; transition: transform 0.1s;
}

.meadow-sticky-bar { position: fixed; bottom: 84px; left: 50%; transform: translateX(-50%); width: calc(100% - 24px); max-width: 456px; z-index: 50; }
.btn-harvest-all {
  width: 100%; background: linear-gradient(135deg, #fbbf24 0%, #f59e0b 45%, #d97706 100%);
  border: 3.5px solid #78350f; border-radius: 20px;
  box-shadow: 0 6px 0 #78350f, 0 10px 25px rgba(180,83,9,0.4);
  padding: 13px 18px; color: #fff; font-size: 15px; font-weight: 900;
  display: flex; align-items: center; justify-content: space-between;
  cursor: pointer; font-family: 'Nunito', sans-serif; text-shadow: 0 2px 0 #78350f; transition: transform 0.1s;
}
.btn-harvest-all:active { transform: translateY(3px); box-shadow: 0 3px 0 #78350f; }
.btn-harvest-all:disabled { background: #cbd5e1; border-color: #64748b; color: #475569; text-shadow: none; box-shadow: none; cursor: not-allowed; }

/* Inspection Modal */
.hive-inspection-overlay {
  position: fixed; top: 0; left: 0; right: 0; bottom: 0; z-index: 9999;
  display: flex; align-items: center; justify-content: center;
  opacity: 0; visibility: hidden; transition: opacity 0.4s, visibility 0.4s;
}
.hive-inspection-overlay.active { opacity: 1; visibility: visible; }
.hive-inspection-vignette {
  position: absolute; top: 0; left: 0; right: 0; bottom: 0; z-index: 1;
  background: radial-gradient(circle at center, rgba(45,20,5,0.82) 0%, rgba(30,12,3,0.92) 35%, rgba(15,6,1,0.97) 65%, rgba(5,2,0,0.99) 100%);
  backdrop-filter: blur(8px);
}
.hive-inspection-close {
  position: absolute; top: 18px; right: 18px; width: 44px; height: 44px;
  background: rgba(255,255,255,0.15); border: 2px solid rgba(255,255,255,0.3);
  border-radius: 50%; color: #fef3c7; font-size: 22px;
  display: flex; align-items: center; justify-content: center;
  cursor: pointer; z-index: 100; transition: background 0.2s, transform 0.2s;
}
.hive-inspection-close:hover { background: rgba(255,255,255,0.25); transform: scale(1.1); }
.hive-inspection-title { position: absolute; top: 22px; left: 0; right: 0; text-align: center; z-index: 50; }
.hive-inspection-title span {
  display: inline-flex; align-items: center; gap: 8px;
  background: rgba(120,53,15,0.6); border: 2px solid rgba(251,191,36,0.4);
  border-radius: 16px; padding: 6px 16px; font-size: 14px; font-weight: 900; color: #fde68a;
  text-shadow: 0 2px 4px rgba(0,0,0,0.5); backdrop-filter: blur(4px);
}
.hive-inspection-frame { position: relative; z-index: 50; width: 290px; max-width: 85vw; animation: frameSlideIn 0.5s cubic-bezier(0.34,1.56,0.64,1) forwards; }
@keyframes frameSlideIn { 0% { transform: scale(0.7) translateY(30px); opacity: 0; } 100% { transform: scale(1) translateY(0); opacity: 1; } }
.honeycomb-organic-frame {
  background-image: repeating-linear-gradient(45deg, rgba(120,53,15,0.3) 0px, rgba(120,53,15,0.3) 2px, transparent 2px, transparent 6px), linear-gradient(180deg, #92400e 0%, #78350f 35%, #5c2d0e 100%);
  border: 4px solid #451a03; border-radius: 24px; padding: 18px 14px;
  box-shadow: inset 0 4px 8px rgba(0,0,0,0.5), 0 0 40px rgba(251,191,36,0.2), 0 8px 24px rgba(0,0,0,0.5);
  position: relative; overflow: hidden;
}
.honeycomb-organic-frame::before { content: ''; position: absolute; top: 0; left: 0; right: 0; bottom: 0; background: repeating-linear-gradient(90deg, transparent 0px, transparent 8px, rgba(69,26,3,0.15) 8px, rgba(69,26,3,0.15) 9px); pointer-events: none; }
.honeycomb-organic-frame::after { content: ''; position: absolute; top: 0; left: 0; right: 0; bottom: 0; background: radial-gradient(ellipse at center, rgba(251,191,36,0.08) 0%, transparent 70%); pointer-events: none; }
.hive-crawling-bee { position: absolute; width: 32px; height: 32px; z-index: 8; pointer-events: none; filter: drop-shadow(0 3px 4px rgba(0,0,0,0.6)); }
.hive-crawling-bee img { width: 100%; height: 100%; object-fit: contain; animation: beeWingFlap 0.08s linear infinite alternate; }
@keyframes beeWingFlap { from { transform: scaleX(1); } to { transform: scaleX(0.85) scaleY(1.05); } }
.bee-crawler-1 { top: 18%; left: 16%; animation: crawl1 7s ease-in-out infinite; }
.bee-crawler-2 { top: 55%; right: 18%; animation: crawl2 8s ease-in-out infinite; }
.bee-crawler-3 { bottom: 15%; left: 40%; animation: crawl3 9s ease-in-out infinite 3s; }
@keyframes crawl1 { 0%,100% { transform: translate(0,0) rotate(15deg); } 50% { transform: translate(45px,20px) rotate(-25deg); } }
@keyframes crawl2 { 0%,100% { transform: translate(0,0) rotate(-40deg); } 50% { transform: translate(-35px,-20px) rotate(20deg); } }
@keyframes crawl3 { 0%,100% { transform: translate(0,0) rotate(10deg); } 50% { transform: translate(25px,-15px) rotate(-30deg); } }
.hex-grid { display: flex; flex-direction: column; align-items: center; gap: 4px; position: relative; z-index: 2; }
.hex-row { display: flex; gap: 6px; justify-content: center; }
.hex-row--offset { margin-left: 18px; }
.hex-cell { width: 36px; height: 40px; position: relative; clip-path: polygon(50% 0%,100% 25%,100% 75%,50% 100%,0% 75%,0% 25%); transition: all 0.3s cubic-bezier(0.34,1.56,0.64,1); display: flex; align-items: center; justify-content: center; }
.hex-cell--empty { background: #451a03; }
.hex-cell--empty::after { content: ''; position: absolute; inset: 3px; clip-path: polygon(50% 0%,100% 25%,100% 75%,50% 100%,0% 75%,0% 25%); background: #271202; opacity: 0.92; }
.hex-cell--honey { background: linear-gradient(180deg, #fde047 0%, #eab308 45%, #b45309 100%); filter: drop-shadow(0 0 6px #f59e0b); animation: honeyGlow 2.5s ease-in-out infinite alternate; }
.hex-cell--honey::after { content: ''; position: absolute; top: 4px; left: 8px; width: 14px; height: 10px; background: rgba(255,255,255,0.65); border-radius: 50%; transform: rotate(-25deg); pointer-events: none; }
@keyframes honeyGlow { 0% { filter: drop-shadow(0 0 3px #f59e0b); } 100% { filter: drop-shadow(0 0 12px #fde047); } }
.hive-inspection-info { position: relative; z-index: 5; margin-top: 16px; text-align: center; }
.inspection-honey-amount { font-size: 28px; font-weight: 900; color: #fbbf24; text-shadow: 0 2px 8px rgba(251,191,36,0.4); display: flex; align-items: center; justify-content: center; gap: 8px; }
.inspection-honey-amount i { font-size: 24px; color: #f59e0b; }
.inspection-honey-sub { font-size: 11px; font-weight: 800; color: rgba(254,243,199,0.7); margin-top: 2px; }
.inspection-bee-info { font-size: 11px; font-weight: 800; color: rgba(254,243,199,0.5); margin-top: 6px; display: flex; align-items: center; justify-content: center; gap: 5px; }
.btn-inspection-harvest {
  margin-top: 14px; background: linear-gradient(135deg, #f59e0b 0%, #d97706 60%, #b45309 100%);
  border: 3px solid #78350f; border-radius: 18px; box-shadow: 0 5px 0 #78350f;
  padding: 12px 24px; color: #fff; font-size: 14px; font-weight: 900;
  display: inline-flex; align-items: center; gap: 8px;
  cursor: pointer; font-family: 'Nunito', sans-serif; transition: transform 0.1s;
}
.btn-inspection-harvest:active { transform: translateY(3px); box-shadow: 0 2px 0 #78350f; }
.btn-inspection-harvest:disabled { background: #4b5563; border-color: #374151; color: #9ca3af; box-shadow: 0 3px 0 #374151; cursor: not-allowed; }
.floating-honey-fly {
  position: fixed; z-index: 99999; font-size: 16px; font-weight: 900; color: #b45309;
  background: #fef3c7; border: 2.5px solid #b45309; border-radius: 12px;
  padding: 4px 10px; box-shadow: 0 4px 0 #b45309;
  pointer-events: none; animation: honeyFloatUp 1.2s forwards ease-out;
}
@keyframes honeyFloatUp { 0% { opacity: 1; transform: translateY(0) scale(0.9); } 60% { opacity: 1; transform: translateY(-55px) scale(1.15); } 100% { opacity: 0; transform: translateY(-90px) scale(1); } }
</style>

<?php require __DIR__ . '/partials/farm_header.php'; ?>

<div id="farm3dCanvas">
  <div class="farm3d-loading" id="farm3dLoading">
    <div class="farm3d-loading-spinner"></div>
    <div class="farm3d-loading-text">Memuat Kebun 3D...</div>
  </div>
  <button class="ambient-sound-toggle" id="btnAmbientSound" onclick="toggleAmbientSound()">
    <i class="ph-fill ph-speaker-high"></i> Ambient
  </button>
  <div class="scene-controls-hint">
    <i class="ph-bold ph-hand-grabbing"></i> Geser untuk putar • Pinch untuk zoom
  </div>
  <div id="hiveLabelsContainer"></div>
</div>

<div class="farm-ground-ui">
  <div class="ground-section-title">
    <div class="pill"><i class="ph-fill ph-hexagon"></i> Sarang Lebahmu (<?= count($user_hives) ?>)</div>
  </div>
  <?php if (empty($user_hives)): ?>
    <div class="farm-empty-cta">
      <div class="emoji">🐝</div>
      <div class="title">Kebun Masih Kosong!</div>
      <div class="sub">Beli sarang pertamamu dan mulai beternak lebah 3D</div>
      <a href="/farm/shop" class="btn-cta"><i class="ph-fill ph-storefront"></i> Buka Toko Peternakan</a>
    </div>
  <?php else: ?>
    <div class="hive-quick-list">
      <?php foreach ($user_hives as $uh):
        $det = $uh['details'];
        $unharvested = (float)($det['total_honey'] ?? 0);
        $prodRate = (float)($det['hourly_production'] ?? 0);
        $sprite = !empty($uh['master_image']) ? $uh['master_image'] : '/assets/game/beehive_wooden.png';
      ?>
        <div class="hive-quick-card" onclick="openHiveInspection(<?= $uh['id'] ?>)">
          <img src="<?= htmlspecialchars($sprite) ?>" alt="<?= htmlspecialchars($uh['master_name']) ?>">
          <div class="hive-quick-info">
            <div class="hive-quick-name"><?= htmlspecialchars($uh['master_name']) ?></div>
            <div class="hive-quick-meta"><?= (int)$det['bee_count'] ?>/<?= (int)$uh['max_slots'] ?> Lebah • +<?= number_format($prodRate, 1) ?> ml/jam</div>
          </div>
          <div class="hive-quick-honey"><?= number_format($unharvested, 1) ?> <small>ml madu</small></div>
        </div>
      <?php endforeach; ?>
    </div>
    <a href="/farm/shop" style="display:block;text-align:center;margin-top:14px;text-decoration:none;">
      <span style="font-size:12px;font-weight:800;color:#fbbf24;">+ Beli Sarang Baru di Toko →</span>
    </a>
  <?php endif; ?>

  <?php if (!empty($user_hives)): ?>
  <div class="meadow-sticky-bar">
    <button type="button" class="btn-harvest-all" id="btnHarvestAll" onclick="harvestAllHives()" <?= $total_unharvested_honey < 0.1 ? 'disabled' : '' ?>>
      <div style="display:flex;align-items:center;gap:8px;"><i class="ph-fill ph-drop" style="font-size:22px;"></i><span>PANEN SEMUA SARANG</span></div>
      <div style="background:rgba(0,0,0,0.25);padding:4px 12px;border-radius:12px;font-size:13.5px;font-weight:900;" id="totalUnharvestedBadge"><?= number_format($total_unharvested_honey, 1) ?> ml</div>
    </button>
  </div>
  <?php endif; ?>
</div>

<!-- Inspection Modal -->
<div class="hive-inspection-overlay" id="hiveInspectionOverlay">
  <div class="hive-inspection-vignette" onclick="closeHiveInspection()"></div>
  <button class="hive-inspection-close" onclick="closeHiveInspection()"><i class="ph-bold ph-x"></i></button>
  <div class="hive-inspection-title"><span id="inspectionHiveName"><i class="ph-fill ph-hexagon" style="color:#fbbf24;"></i> Sarang Lebah</span></div>
  <div class="hive-inspection-frame">
    <div class="honeycomb-organic-frame">
      <div class="hive-crawling-bee bee-crawler-1"><img src="/assets/game/bee_worker.png" alt="W"></div>
      <div class="hive-crawling-bee bee-crawler-2"><img src="/assets/game/bee_golden.png" onerror="this.src='/assets/game/bee_worker.png'" alt="W"></div>
      <div class="hive-crawling-bee bee-crawler-3"><img src="/assets/game/bee_worker.png" alt="W"></div>
      <div class="hex-grid" id="inspectionHexGrid"></div>
    </div>
    <div class="hive-inspection-info">
      <div class="inspection-honey-amount"><i class="ph-fill ph-drop"></i><span id="inspectionHoneyAmt">0.00 ml</span></div>
      <div class="inspection-honey-sub" id="inspectionHoneyCap">Kapasitas: 0 / 0 ml (0%)</div>
      <div class="inspection-bee-info" id="inspectionBeeInfo"><i class="ph-fill ph-bug-beetle"></i><span>0 Lebah</span></div>
      <button type="button" class="btn-inspection-harvest" id="btnInspectionHarvest" onclick="harvestInspectedHive()">
        <i class="ph-fill ph-drop"></i><span>PANEN MADU SARANG INI</span>
      </button>
    </div>
  </div>
</div>

<?= csrf_field() ?>

<script src="https://cdnjs.cloudflare.com/ajax/libs/three.js/r128/three.min.js"></script>
<script>
const USER_HIVES_MAP = <?= json_encode(array_column($user_hives, null, 'id')) ?>;
const HIVES_ARRAY = <?= json_encode(array_values($user_hives)) ?>;
let currentInspectedHiveId = 0;

// ══════════════════════════════════════════════════════════
// AMBIENT SOUND ENGINE (Web Audio — continuous nature loop)
// ══════════════════════════════════════════════════════════
const AmbientEngine = (function(){
  let ctx = null, masterGain = null, isPlaying = false;
  let nodes = [];

  function getCtx() {
    if (!ctx) {
      const AC = window.AudioContext || window.webkitAudioContext;
      if (AC) { ctx = new AC(); masterGain = ctx.createGain(); masterGain.gain.value = 0.35; masterGain.connect(ctx.destination); }
    }
    if (ctx && ctx.state === 'suspended') ctx.resume();
    return ctx;
  }

  function createBeeLoop() {
    const c = getCtx(); if (!c) return;
    // Low buzz drone
    const osc1 = c.createOscillator(); osc1.type = 'sawtooth'; osc1.frequency.value = 160;
    const osc2 = c.createOscillator(); osc2.type = 'triangle'; osc2.frequency.value = 165;
    // Modulation LFO for flutter
    const lfo = c.createOscillator(); lfo.type = 'sine'; lfo.frequency.value = 8;
    const lfoGain = c.createGain(); lfoGain.gain.value = 15;
    lfo.connect(lfoGain); lfoGain.connect(osc1.frequency); lfoGain.connect(osc2.frequency);
    // Filter
    const bp = c.createBiquadFilter(); bp.type = 'bandpass'; bp.frequency.value = 280; bp.Q.value = 3;
    const gain = c.createGain(); gain.gain.value = 0.06;
    osc1.connect(bp); osc2.connect(bp); bp.connect(gain); gain.connect(masterGain);
    osc1.start(); osc2.start(); lfo.start();
    nodes.push(osc1, osc2, lfo);
  }

  function createWindLoop() {
    const c = getCtx(); if (!c) return;
    // Wind = filtered white noise
    const bufferSize = c.sampleRate * 2;
    const buffer = c.createBuffer(1, bufferSize, c.sampleRate);
    const data = buffer.getChannelData(0);
    for (let i = 0; i < bufferSize; i++) data[i] = Math.random() * 2 - 1;
    const src = c.createBufferSource(); src.buffer = buffer; src.loop = true;
    const lp = c.createBiquadFilter(); lp.type = 'lowpass'; lp.frequency.value = 400; lp.Q.value = 0.5;
    const gain = c.createGain(); gain.gain.value = 0.04;
    // Slow volume modulation
    const lfo = c.createOscillator(); lfo.type = 'sine'; lfo.frequency.value = 0.15;
    const lfoGain = c.createGain(); lfoGain.gain.value = 0.02;
    lfo.connect(lfoGain); lfoGain.connect(gain.gain);
    src.connect(lp); lp.connect(gain); gain.connect(masterGain);
    src.start(); lfo.start();
    nodes.push(src, lfo);
  }

  function createBirdChirps() {
    const c = getCtx(); if (!c) return;
    function chirp() {
      if (!isPlaying) return;
      const now = c.currentTime;
      const osc = c.createOscillator(); osc.type = 'sine';
      const g = c.createGain();
      // Random bird note
      const baseFreq = 1200 + Math.random() * 1800;
      osc.frequency.setValueAtTime(baseFreq, now);
      osc.frequency.exponentialRampToValueAtTime(baseFreq * (0.7 + Math.random() * 0.6), now + 0.08);
      osc.frequency.exponentialRampToValueAtTime(baseFreq * 1.1, now + 0.14);
      g.gain.setValueAtTime(0.03, now);
      g.gain.exponentialRampToValueAtTime(0.001, now + 0.18);
      osc.connect(g); g.connect(masterGain);
      osc.start(now); osc.stop(now + 0.2);
      // Schedule next chirp
      setTimeout(chirp, 2000 + Math.random() * 6000);
    }
    setTimeout(chirp, 1000 + Math.random() * 3000);
  }

  function createCrickets() {
    const c = getCtx(); if (!c) return;
    function tick() {
      if (!isPlaying) return;
      const now = c.currentTime;
      for (let i = 0; i < 3; i++) {
        const osc = c.createOscillator(); osc.type = 'square';
        const g = c.createGain();
        const t = now + i * 0.03;
        osc.frequency.setValueAtTime(4200 + Math.random() * 800, t);
        g.gain.setValueAtTime(0.008, t);
        g.gain.exponentialRampToValueAtTime(0.001, t + 0.025);
        osc.connect(g); g.connect(masterGain);
        osc.start(t); osc.stop(t + 0.03);
      }
      setTimeout(tick, 3000 + Math.random() * 5000);
    }
    setTimeout(tick, 2000);
  }

  return {
    start() {
      if (isPlaying) return;
      isPlaying = true;
      createBeeLoop();
      createWindLoop();
      createBirdChirps();
      createCrickets();
    },
    stop() {
      isPlaying = false;
      nodes.forEach(n => { try { n.stop(); } catch(e) {} });
      nodes = [];
      if (ctx) { ctx.close(); ctx = null; }
    },
    isPlaying() { return isPlaying; }
  };
})();

let ambientOn = false;
function toggleAmbientSound() {
  const btn = document.getElementById('btnAmbientSound');
  if (ambientOn) {
    AmbientEngine.stop();
    ambientOn = false;
    btn.innerHTML = '<i class="ph-fill ph-speaker-slash"></i> Muted';
  } else {
    AmbientEngine.start();
    ambientOn = true;
    btn.innerHTML = '<i class="ph-fill ph-speaker-high"></i> Ambient';
  }
}

// ══════════════════════════════════════════════════════════
// THREE.JS 3D — ULTRA REALISTIC FARM SCENE
// ══════════════════════════════════════════════════════════
(function() {
  const container = document.getElementById('farm3dCanvas');
  const loadingEl = document.getElementById('farm3dLoading');
  const labelsContainer = document.getElementById('hiveLabelsContainer');

  const scene = new THREE.Scene();
  scene.fog = new THREE.Fog(0x9ec5e0, 30, 80);

  const camera = new THREE.PerspectiveCamera(50, container.clientWidth / container.clientHeight, 0.1, 300);
  camera.position.set(0, 9, 16);
  camera.lookAt(0, 0, 0);

  const renderer = new THREE.WebGLRenderer({ antialias: true, alpha: false });
  renderer.setSize(container.clientWidth, container.clientHeight);
  renderer.setPixelRatio(Math.min(window.devicePixelRatio, 2));
  renderer.shadowMap.enabled = true;
  renderer.shadowMap.type = THREE.PCFSoftShadowMap;
  renderer.toneMapping = THREE.ACESFilmicToneMapping;
  renderer.toneMappingExposure = 1.2;
  container.insertBefore(renderer.domElement, container.firstChild);

  // ── SKY DOME ──
  const skyC = document.createElement('canvas'); skyC.width = 2; skyC.height = 512;
  const skyX = skyC.getContext('2d');
  const skyG = skyX.createLinearGradient(0, 0, 0, 512);
  skyG.addColorStop(0, '#0f1b4d'); skyG.addColorStop(0.15, '#1e3a8a'); skyG.addColorStop(0.35, '#3b82f6');
  skyG.addColorStop(0.55, '#87ceeb'); skyG.addColorStop(0.75, '#d4f0ff'); skyG.addColorStop(0.9, '#fef3c7');
  skyG.addColorStop(1, '#fbbf24');
  skyX.fillStyle = skyG; skyX.fillRect(0, 0, 2, 512);
  scene.background = new THREE.CanvasTexture(skyC);

  // ── LIGHTS ──
  scene.add(new THREE.AmbientLight(0xffeedd, 0.45));
  scene.add(new THREE.HemisphereLight(0x87ceeb, 0x2d8a4e, 0.35));

  const sun = new THREE.DirectionalLight(0xfff4e0, 1.3);
  sun.position.set(12, 18, 10);
  sun.castShadow = true;
  sun.shadow.mapSize.set(2048, 2048);
  sun.shadow.camera.near = 0.5; sun.shadow.camera.far = 60;
  sun.shadow.camera.left = -20; sun.shadow.camera.right = 20;
  sun.shadow.camera.top = 20; sun.shadow.camera.bottom = -20;
  sun.shadow.bias = -0.0005;
  scene.add(sun);

  const fillL = new THREE.DirectionalLight(0x88bbff, 0.25);
  fillL.position.set(-8, 10, -6);
  scene.add(fillL);

  // Visual sun
  const sunV = new THREE.Mesh(new THREE.SphereGeometry(1.2, 16, 16), new THREE.MeshBasicMaterial({ color: 0xfffbe0 }));
  sunV.position.set(22, 24, -15); scene.add(sunV);
  // Sun glow
  const glowV = new THREE.Mesh(new THREE.SphereGeometry(3, 16, 16), new THREE.MeshBasicMaterial({ color: 0xfff8dc, transparent: true, opacity: 0.15 }));
  glowV.position.copy(sunV.position); scene.add(glowV);

  // ── GROUND ──
  const gGeo = new THREE.PlaneGeometry(80, 80, 64, 64);
  const gPos = gGeo.getAttribute('position');
  for (let i = 0; i < gPos.count; i++) {
    const x = gPos.getX(i), y = gPos.getY(i);
    gPos.setZ(i, Math.sin(x*0.2)*0.25 + Math.cos(y*0.25)*0.2 + Math.sin(x*0.6+y*0.5)*0.08);
  }
  gGeo.computeVertexNormals();
  const ground = new THREE.Mesh(gGeo, new THREE.MeshStandardMaterial({ color: 0x2d8a4e, roughness: 0.92, metalness: 0 }));
  ground.rotation.x = -Math.PI/2; ground.receiveShadow = true; scene.add(ground);

  // ── MOUNTAIN RANGES (surrounding the scene edges) ──
  function createMountainRange(centerX, centerZ, count, baseScale, color) {
    for (let i = 0; i < count; i++) {
      const angle = (i / count) * Math.PI * 0.6 - Math.PI * 0.3;
      const dist = 28 + Math.random() * 10;
      const x = centerX + Math.cos(angle) * dist;
      const z = centerZ + Math.sin(angle) * dist;
      const h = (2 + Math.random() * 5) * baseScale;
      const w = (3 + Math.random() * 4) * baseScale;
      const geo = new THREE.ConeGeometry(w, h, 5 + Math.floor(Math.random()*3), 1);
      const mat = new THREE.MeshStandardMaterial({ color: color, roughness: 0.95, flatShading: true });
      const m = new THREE.Mesh(geo, mat);
      m.position.set(x, h/2 - 0.5, z);
      m.rotation.y = Math.random() * Math.PI;
      m.castShadow = false;
      scene.add(m);

      // Snow cap on tall mountains
      if (h > 5) {
        const capGeo = new THREE.ConeGeometry(w * 0.35, h * 0.2, 5, 1);
        const capMat = new THREE.MeshStandardMaterial({ color: 0xf0f8ff, roughness: 0.7 });
        const cap = new THREE.Mesh(capGeo, capMat);
        cap.position.set(x, h - 0.5, z);
        cap.rotation.y = m.rotation.y;
        scene.add(cap);
      }
    }
  }

  // Mountains all around
  createMountainRange(0, -35, 12, 1.3, 0x3d6b42);   // Back
  createMountainRange(-35, 0, 8, 1.1, 0x4b7c4f);    // Left
  createMountainRange(35, 0, 8, 1.1, 0x4b7c4f);     // Right
  createMountainRange(-25, -25, 6, 1.0, 0x5a8c5e);   // Back-left
  createMountainRange(25, -25, 6, 1.0, 0x5a8c5e);    // Back-right
  // Far background larger mountains
  createMountainRange(0, -50, 8, 2.0, 0x2d5a36);
  createMountainRange(-40, -40, 5, 1.8, 0x2d5a36);
  createMountainRange(40, -40, 5, 1.8, 0x2d5a36);

  // ── TREES ──
  function mkTree(x, z, s, foliageColor) {
    const g = new THREE.Group();
    const trunk = new THREE.Mesh(
      new THREE.CylinderGeometry(0.1*s, 0.16*s, 1.3*s, 6),
      new THREE.MeshStandardMaterial({ color: 0x5c3d1e, roughness: 0.9 })
    );
    trunk.position.y = 0.65*s; trunk.castShadow = true; g.add(trunk);

    const fc = foliageColor || 0x2d7a3a;
    [[0.65,1.3],[0.5,1.8],[0.3,2.2]].forEach(([r,h]) => {
      const f = new THREE.Mesh(new THREE.SphereGeometry(r*s, 7, 5), new THREE.MeshStandardMaterial({ color: fc, roughness: 0.82 }));
      f.position.y = h*s; f.castShadow = true; g.add(f);
    });
    g.position.set(x, 0, z); scene.add(g); return g;
  }

  // Dense forest border
  const treeData = [
    [-9,-7,1.3],[-11,-3,1.0],[-10,2,1.2],[-8,6,0.9],[-12,5,0.7],
    [9,-6,1.1],[11,-1,0.9],[10,3,1.2],[8,7,0.8],[12,4,0.7],
    [-6,-10,0.8],[0,-11,1.0],[5,-9,0.9],[3,-11,0.7],[-3,-10,0.6],
    [-7,9,0.7],[5,10,0.8],[0,10,0.6],[-4,10,0.5],
    // More depth
    [-14,-5,0.8],[-13,0,0.7],[14,-4,0.6],[13,1,0.7],
    [-6,-12,0.5],[6,-12,0.5],
  ];
  treeData.forEach(([x,z,s]) => mkTree(x, z, s, [0x2d7a3a, 0x3a8c4a, 0x1f6b2e, 0x4a9a5a][Math.floor(Math.random()*4)]));

  // Pine trees (conical) for variety
  function mkPine(x, z, s) {
    const g = new THREE.Group();
    const trunk = new THREE.Mesh(new THREE.CylinderGeometry(0.06*s, 0.1*s, 0.8*s, 5), new THREE.MeshStandardMaterial({ color: 0x4a3520 }));
    trunk.position.y = 0.4*s; trunk.castShadow = true; g.add(trunk);
    for (let i = 0; i < 4; i++) {
      const r = (0.6 - i*0.12)*s;
      const h = (0.8 + i*0.45)*s;
      const cone = new THREE.Mesh(new THREE.ConeGeometry(r, 0.6*s, 6), new THREE.MeshStandardMaterial({ color: 0x1a5c2e, roughness: 0.85 }));
      cone.position.y = h; cone.castShadow = true; g.add(cone);
    }
    g.position.set(x, 0, z); scene.add(g);
  }
  [[-7,-8,1.0],[7,-7,0.9],[-9,5,0.8],[9,6,0.7],[-11,-6,0.6],[11,-5,0.5]].forEach(([x,z,s]) => mkPine(x,z,s));

  // ── ROCKS ──
  [[-3,-4,1.3],[4,-3,0.9],[-5,2,1.0],[6,1,0.7],[1,5,1.0],[-2,6,0.8],[3,4,0.5],[-4,-2,0.6]].forEach(([x,z,s]) => {
    const r = new THREE.Mesh(
      new THREE.DodecahedronGeometry(0.3*s, 1),
      new THREE.MeshStandardMaterial({ color: 0x6b7280, roughness: 0.95, flatShading: true })
    );
    r.position.set(x, 0.1*s, z); r.rotation.set(Math.random()*0.5, Math.random()*Math.PI, 0);
    r.scale.y = 0.55; r.castShadow = true; r.receiveShadow = true; scene.add(r);
  });

  // ── FLOWERS ──
  function mkFlowers(x, z) {
    const g = new THREE.Group();
    const cols = [0xff69b4, 0xffd700, 0xff6347, 0x9370db, 0xffa500, 0xff1493, 0x00bfff];
    for (let i = 0; i < 7; i++) {
      const a = (i/7)*Math.PI*2, rad = 0.12+Math.random()*0.12;
      const stem = new THREE.Mesh(new THREE.CylinderGeometry(0.01,0.01,0.2+Math.random()*0.1,3), new THREE.MeshStandardMaterial({ color: 0x2d8a4e }));
      stem.position.set(Math.cos(a)*rad, 0.12, Math.sin(a)*rad); g.add(stem);
      const petal = new THREE.Mesh(new THREE.SphereGeometry(0.04+Math.random()*0.02,5,3), new THREE.MeshStandardMaterial({ color: cols[i] }));
      petal.position.set(Math.cos(a)*rad, 0.25+Math.random()*0.05, Math.sin(a)*rad); g.add(petal);
    }
    g.position.set(x,0,z); scene.add(g);
  }
  [[-2,-2],[3,-1],[-1,3],[5,3],[-4,-1],[2,-5],[-3,5],[1,7],[4,-5],[-5,4],[0,-3],[6,-2]].forEach(([x,z]) => mkFlowers(x,z));

  // ── GRASS TUFTS (billboard quads) ──
  const grassMat = new THREE.MeshStandardMaterial({ color: 0x3a9c52, side: THREE.DoubleSide, transparent: true, opacity: 0.8 });
  for (let i = 0; i < 120; i++) {
    const blade = new THREE.Mesh(new THREE.PlaneGeometry(0.08+Math.random()*0.06, 0.15+Math.random()*0.15), grassMat);
    blade.position.set((Math.random()-0.5)*20, 0.07+Math.random()*0.05, (Math.random()-0.5)*20);
    blade.rotation.y = Math.random()*Math.PI;
    blade.rotation.x = -0.1;
    scene.add(blade);
  }

  // ── POND / SMALL WATER BODY ──
  const pondGeo = new THREE.CircleGeometry(1.8, 24);
  const pondMat = new THREE.MeshStandardMaterial({ color: 0x3b82f6, roughness: 0.15, metalness: 0.3, transparent: true, opacity: 0.8 });
  const pond = new THREE.Mesh(pondGeo, pondMat);
  pond.rotation.x = -Math.PI/2; pond.position.set(-5, 0.05, 4); scene.add(pond);
  // Pond border stones
  for (let i = 0; i < 10; i++) {
    const a = (i/10)*Math.PI*2;
    const sr = new THREE.Mesh(
      new THREE.DodecahedronGeometry(0.15+Math.random()*0.1, 0),
      new THREE.MeshStandardMaterial({ color: 0x7f8c8d, roughness: 0.9, flatShading: true })
    );
    sr.position.set(-5 + Math.cos(a)*1.9, 0.08, 4 + Math.sin(a)*1.9);
    sr.scale.y = 0.5; scene.add(sr);
  }

  // ── FENCE (wooden posts around meadow center) ──
  function mkFencePost(x, z) {
    const post = new THREE.Mesh(new THREE.CylinderGeometry(0.04, 0.05, 0.7, 5), new THREE.MeshStandardMaterial({ color: 0x6b4423 }));
    post.position.set(x, 0.35, z); post.castShadow = true; scene.add(post);
  }
  for (let i = -3; i <= 3; i++) { mkFencePost(i*1.5, -6); mkFencePost(i*1.5, 6); }
  for (let i = -3; i <= 3; i++) { mkFencePost(-6, i*1.5); mkFencePost(6, i*1.5); }
  // Fence rails
  function mkFenceRail(x1,z1,x2,z2) {
    const dx=x2-x1, dz=z2-z1, len=Math.sqrt(dx*dx+dz*dz);
    const rail = new THREE.Mesh(new THREE.CylinderGeometry(0.02,0.02,len,4), new THREE.MeshStandardMaterial({ color: 0x8b6914 }));
    rail.position.set((x1+x2)/2, 0.5, (z1+z2)/2);
    rail.rotation.z = Math.PI/2; rail.rotation.y = Math.atan2(dz,dx);
    scene.add(rail);
  }
  for (let i = -3; i < 3; i++) {
    mkFenceRail(i*1.5, -6, (i+1)*1.5, -6); mkFenceRail(i*1.5, 6, (i+1)*1.5, 6);
  }

  // ── BUTTERFLIES ──
  const butterflies = [];
  for (let i = 0; i < 6; i++) {
    const g = new THREE.Group();
    const bColor = [0xff69b4, 0xffa500, 0x87ceeb, 0xffd700, 0x9370db, 0xff6347][i];
    const wMat = new THREE.MeshStandardMaterial({ color: bColor, side: THREE.DoubleSide, transparent: true, opacity: 0.8 });
    const wL = new THREE.Mesh(new THREE.PlaneGeometry(0.12, 0.08), wMat);
    wL.position.x = -0.06; g.add(wL);
    const wR = new THREE.Mesh(new THREE.PlaneGeometry(0.12, 0.08), wMat);
    wR.position.x = 0.06; g.add(wR);
    g.userData = { wL, wR, phase: Math.random()*Math.PI*2, speed: 0.4+Math.random()*0.6, radius: 2+Math.random()*4, h: 1+Math.random()*2.5, cx: (Math.random()-0.5)*10, cz: (Math.random()-0.5)*10 };
    scene.add(g); butterflies.push(g);
  }

  // ── 3D BEEHIVES ──
  const hive3DObjects = [];
  const hiveWorldPositions = [];

  function mkBeehive(idx) {
    const g = new THREE.Group();
    const cols = [0xb8860b, 0xcd853f, 0xdaa520];
    // Base
    const base = new THREE.Mesh(new THREE.BoxGeometry(1.1,0.12,0.9), new THREE.MeshStandardMaterial({ color: 0x8b4513, roughness: 0.85 }));
    base.position.y = 0.06; base.castShadow = true; base.receiveShadow = true; g.add(base);
    // Legs
    [[-0.4, -0.3],[0.4,-0.3],[-0.4,0.3],[0.4,0.3]].forEach(([lx,lz]) => {
      const leg = new THREE.Mesh(new THREE.CylinderGeometry(0.04,0.04,0.3,4), new THREE.MeshStandardMaterial({ color: 0x5c3d1e }));
      leg.position.set(lx, -0.09, lz); g.add(leg);
    });
    // 3 brood boxes
    for (let i = 0; i < 3; i++) {
      const box = new THREE.Mesh(new THREE.BoxGeometry(1.0,0.35,0.8), new THREE.MeshStandardMaterial({ color: cols[i], roughness: 0.78, metalness: 0.02 }));
      box.position.y = 0.3 + i*0.37; box.castShadow = true; g.add(box);
      // Edge trim
      const trim = new THREE.Mesh(new THREE.BoxGeometry(1.04,0.03,0.84), new THREE.MeshStandardMaterial({ color: 0x4a2c0f }));
      trim.position.y = 0.12 + i*0.37 + 0.18; g.add(trim);
    }
    // Roof (gabled)
    const roof = new THREE.Mesh(new THREE.ConeGeometry(0.72,0.35,4), new THREE.MeshStandardMaterial({ color: 0x654321, roughness: 0.85 }));
    roof.position.y = 1.52; roof.rotation.y = Math.PI/4; roof.castShadow = true; g.add(roof);
    // Roof cap
    const cap = new THREE.Mesh(new THREE.BoxGeometry(1.1,0.05,0.9), new THREE.MeshStandardMaterial({ color: 0x5c3d1e }));
    cap.position.y = 1.33; g.add(cap);
    // Entrance
    const ent = new THREE.Mesh(new THREE.CircleGeometry(0.09,8), new THREE.MeshStandardMaterial({ color: 0x0a0500 }));
    ent.position.set(0, 0.35, 0.41); g.add(ent);
    // Landing board
    const lb = new THREE.Mesh(new THREE.BoxGeometry(0.22,0.02,0.14), new THREE.MeshStandardMaterial({ color: 0x8b4513 }));
    lb.position.set(0, 0.26, 0.47); lb.rotation.x = -0.2; g.add(lb);
    return g;
  }

  function hivePos(idx, total) {
    const spacing = 2.8, maxRow = 4;
    const row = Math.floor(idx/maxRow), col = idx%maxRow;
    const rowCount = Math.min(total - row*maxRow, maxRow);
    const ox = -(rowCount-1)*spacing/2;
    return { x: ox + col*spacing + (row%2===1 ? spacing*0.5 : 0), z: -1 + row*2.5 };
  }

  HIVES_ARRAY.forEach((hive, idx) => {
    const h3d = mkBeehive(idx);
    const p = hivePos(idx, HIVES_ARRAY.length);
    h3d.position.set(p.x, 0, p.z);
    h3d.userData.hiveId = hive.id;
    scene.add(h3d);
    hive3DObjects.push(h3d);
    hiveWorldPositions.push(new THREE.Vector3(p.x, 1.9, p.z));
  });

  // ── BEES ──
  const bees = [];
  const beeCount = Math.min(HIVES_ARRAY.length * 4 + 3, 25);
  function mkBee() {
    const g = new THREE.Group();
    const body = new THREE.Mesh(new THREE.SphereGeometry(0.06,8,6), new THREE.MeshStandardMaterial({ color: 0xf5a623 }));
    body.scale.set(1, 0.7, 1.3); g.add(body);
    // Stripes
    for (let i = 0; i < 2; i++) {
      const s = new THREE.Mesh(new THREE.TorusGeometry(0.055,0.012,4,8), new THREE.MeshStandardMaterial({ color: 0x1a1a1a }));
      s.position.z = -0.02+i*0.04; s.rotation.x = Math.PI/2; g.add(s);
    }
    // Wings
    const wm = new THREE.MeshStandardMaterial({ color: 0xffffff, transparent: true, opacity: 0.45, side: THREE.DoubleSide });
    const wL = new THREE.Mesh(new THREE.PlaneGeometry(0.1,0.06), wm); wL.position.set(-0.06,0.04,0); wL.rotation.z = 0.3; g.add(wL);
    const wR = new THREE.Mesh(new THREE.PlaneGeometry(0.1,0.06), wm); wR.position.set(0.06,0.04,0); wR.rotation.z = -0.3; g.add(wR);
    g.userData = { wL, wR, phase: Math.random()*Math.PI*2, speed: 0.6+Math.random()*1.4, radius: 1.5+Math.random()*4, h: 1.2+Math.random()*2.5, cx: (Math.random()-0.5)*10, cz: (Math.random()-0.5)*8 };
    scene.add(g); return g;
  }
  for (let i = 0; i < beeCount; i++) bees.push(mkBee());

  // ── CLOUDS ──
  const clouds = [];
  function mkCloud(x,y,z,s) {
    const g = new THREE.Group();
    const m = new THREE.MeshStandardMaterial({ color: 0xffffff, roughness: 1, transparent: true, opacity: 0.82 });
    [[0,0,0,0.8],[-0.6,0.1,0.1,0.6],[0.5,0.15,-0.1,0.7],[-0.2,-0.1,0.2,0.5],[0.7,0.05,0.15,0.45]].forEach(([ox,oy,oz,r]) => {
      const sp = new THREE.Mesh(new THREE.SphereGeometry(r*s,7,5), m);
      sp.position.set(ox*s,oy*s,oz*s); g.add(sp);
    });
    g.position.set(x,y,z); g.userData.speed = 0.015+Math.random()*0.025;
    scene.add(g); return g;
  }
  [[-10,12,-20,1.3],[6,13,-25,1.5],[15,11,-18,1.1],[-5,14,-28,1.7],[20,10,-15,0.9],[-15,13,-22,1.2]].forEach(([x,y,z,s]) => clouds.push(mkCloud(x,y,z,s)));

  // ── POLLEN PARTICLES ──
  const pGeo = new THREE.BufferGeometry();
  const pCount = 80;
  const pPos = new Float32Array(pCount*3);
  for (let i = 0; i < pCount; i++) { pPos[i*3]=(Math.random()-0.5)*24; pPos[i*3+1]=0.3+Math.random()*5; pPos[i*3+2]=(Math.random()-0.5)*24; }
  pGeo.setAttribute('position', new THREE.BufferAttribute(pPos, 3));
  scene.add(new THREE.Points(pGeo, new THREE.PointsMaterial({ color: 0xfde047, size: 0.05, transparent: true, opacity: 0.65 })));

  // ── FIREFLIES (evening sparkle) ──
  const ffGeo = new THREE.BufferGeometry();
  const ffCount = 30;
  const ffPos = new Float32Array(ffCount*3);
  for (let i = 0; i < ffCount; i++) { ffPos[i*3]=(Math.random()-0.5)*16; ffPos[i*3+1]=0.5+Math.random()*3; ffPos[i*3+2]=(Math.random()-0.5)*16; }
  ffGeo.setAttribute('position', new THREE.BufferAttribute(ffPos, 3));
  const fireflies = new THREE.Points(ffGeo, new THREE.PointsMaterial({ color: 0x90ee90, size: 0.08, transparent: true, opacity: 0.5 }));
  scene.add(fireflies);

  // ── ORBIT CONTROLS ──
  let isPD = false, pX = 0, pY = 0, cTheta = 0, cPhi = 0.55, cR = 16;
  const cTarget = new THREE.Vector3(0, 1, 0);

  function updateCam() {
    const x = cR*Math.sin(cPhi)*Math.sin(cTheta), y = cR*Math.cos(cPhi), z = cR*Math.sin(cPhi)*Math.cos(cTheta);
    camera.position.set(x+cTarget.x, y+cTarget.y, z+cTarget.z);
    camera.lookAt(cTarget);
  }

  const cv = renderer.domElement;
  cv.addEventListener('pointerdown', e => { isPD = true; pX = e.clientX; pY = e.clientY; });
  cv.addEventListener('pointermove', e => { if (!isPD) return; cTheta += (e.clientX-pX)*0.005; cPhi = Math.max(0.25,Math.min(1.35, cPhi+(e.clientY-pY)*0.005)); pX = e.clientX; pY = e.clientY; });
  cv.addEventListener('pointerup', () => isPD = false);
  cv.addEventListener('pointerleave', () => isPD = false);
  cv.addEventListener('wheel', e => { cR = Math.max(6,Math.min(30, cR+e.deltaY*0.012)); }, { passive: true });
  let lastTD = 0;
  cv.addEventListener('touchstart', e => { if (e.touches.length===2) { const dx=e.touches[0].clientX-e.touches[1].clientX, dy=e.touches[0].clientY-e.touches[1].clientY; lastTD=Math.sqrt(dx*dx+dy*dy); } }, { passive: true });
  cv.addEventListener('touchmove', e => { if (e.touches.length===2) { const dx=e.touches[0].clientX-e.touches[1].clientX, dy=e.touches[0].clientY-e.touches[1].clientY; const d=Math.sqrt(dx*dx+dy*dy); cR=Math.max(6,Math.min(30, cR+(lastTD-d)*0.03)); lastTD=d; } }, { passive: true });

  // ── CLICK ON HIVE ──
  const ray = new THREE.Raycaster();
  const mPos = new THREE.Vector2();
  cv.addEventListener('click', e => {
    const r = cv.getBoundingClientRect();
    mPos.x = ((e.clientX-r.left)/r.width)*2-1; mPos.y = -((e.clientY-r.top)/r.height)*2+1;
    ray.setFromCamera(mPos, camera);
    for (let i = 0; i < hive3DObjects.length; i++) {
      if (ray.intersectObjects(hive3DObjects[i].children, true).length > 0) {
        openHiveInspection(hive3DObjects[i].userData.hiveId);
        hive3DObjects[i].userData.bounceT = 0;
        break;
      }
    }
  });

  // ── LABELS ──
  function updateLabels() {
    labelsContainer.innerHTML = '';
    hive3DObjects.forEach((obj, i) => {
      const hive = HIVES_ARRAY[i]; if (!hive) return;
      const wp = hiveWorldPositions[i].clone(); wp.project(camera);
      if (wp.z > 1) return;
      const sx = (wp.x*container.clientWidth/2)+container.clientWidth/2;
      const sy = -(wp.y*container.clientHeight/2)+container.clientHeight/2;
      const det = hive.details || {};
      const honey = parseFloat(det.total_honey) || 0;
      const lbl = document.createElement('div');
      lbl.className = 'hive-3d-label'; lbl.style.left = sx+'px'; lbl.style.top = sy+'px';
      lbl.onclick = () => openHiveInspection(hive.id);
      lbl.innerHTML = '<div class="hive-label-bubble"><div class="hive-label-name">'+hive.master_name+'</div><div class="hive-label-honey"><i class="ph-fill ph-drop"></i> '+honey.toFixed(1)+' ml</div></div>';
      labelsContainer.appendChild(lbl);
    });
  }

  // ── ANIMATION ──
  const clock = new THREE.Clock();
  function animate() {
    requestAnimationFrame(animate);
    const t = clock.getElapsedTime();
    updateCam();

    // Bees
    bees.forEach(b => {
      const d = b.userData, tt = t*d.speed+d.phase;
      b.position.set(d.cx+Math.sin(tt)*d.radius, d.h+Math.sin(tt*2.5)*0.35, d.cz+Math.cos(tt)*d.radius);
      b.rotation.y = tt+Math.PI/2;
      d.wL.rotation.z = 0.3+Math.sin(t*35)*0.35;
      d.wR.rotation.z = -0.3-Math.sin(t*35)*0.35;
    });

    // Butterflies
    butterflies.forEach(bf => {
      const d = bf.userData, tt = t*d.speed+d.phase;
      bf.position.set(d.cx+Math.sin(tt)*d.radius, d.h+Math.sin(tt*1.5)*0.5, d.cz+Math.cos(tt*0.8)*d.radius);
      bf.rotation.y = tt;
      d.wL.rotation.z = Math.sin(t*8)*0.6;
      d.wR.rotation.z = -Math.sin(t*8)*0.6;
    });

    // Clouds
    clouds.forEach(c => { c.position.x += c.userData.speed; if (c.position.x > 30) c.position.x = -30; });

    // Pollen
    const pp = pGeo.getAttribute('position');
    for (let i = 0; i < pCount; i++) {
      let y = pp.getY(i)+0.004; let x = pp.getX(i)+Math.sin(t+i)*0.002;
      if (y > 6) { y = 0.2; x = (Math.random()-0.5)*24; }
      pp.setY(i, y); pp.setX(i, x);
    }
    pp.needsUpdate = true;

    // Fireflies pulse
    fireflies.material.opacity = 0.3 + Math.sin(t*2)*0.2;
    const fp = ffGeo.getAttribute('position');
    for (let i = 0; i < ffCount; i++) {
      fp.setY(i, fp.getY(i) + Math.sin(t*0.5+i*0.7)*0.003);
      fp.setX(i, fp.getX(i) + Math.cos(t*0.3+i*1.1)*0.002);
    }
    fp.needsUpdate = true;

    // Pond shimmer
    pond.material.opacity = 0.75 + Math.sin(t*1.5)*0.05;

    // Hive bounce + idle
    hive3DObjects.forEach((obj, i) => {
      if (obj.userData.bounceT !== undefined) {
        obj.userData.bounceT += 0.08;
        if (obj.userData.bounceT < Math.PI) { obj.position.y = Math.sin(obj.userData.bounceT)*0.3; }
        else { obj.position.y = 0; delete obj.userData.bounceT; }
      } else {
        obj.rotation.y = Math.sin(t*0.5+i)*0.02;
      }
    });

    renderer.render(scene, camera);
    updateLabels();
  }

  updateCam();
  animate();
  setTimeout(() => loadingEl.classList.add('hidden'), 600);

  window.addEventListener('resize', () => {
    camera.aspect = container.clientWidth/container.clientHeight;
    camera.updateProjectionMatrix();
    renderer.setSize(container.clientWidth, container.clientHeight);
  });
})();

// ── INTERACTION LOGIC ──
function renderHexagonCells(fp, gid) {
  const tc=24, hc=Math.min(tc, Math.round(tc*(fp/100))), g=document.getElementById(gid||'inspectionHexGrid');
  if(!g) return; g.innerHTML='';
  [6,5,6,5,2].forEach((cc,ri) => {
    const r=document.createElement('div'); r.className='hex-row'+(ri%2===1?' hex-row--offset':'');
    for(let c=0;c<cc;c++){const cl=document.createElement('div'); cl.className='hex-cell '+(c+[0,6,11,17,22][ri]<hc?'hex-cell--honey':'hex-cell--empty'); r.appendChild(cl);}
    g.appendChild(r);
  });
}

function openHiveInspection(hiveId) {
  currentInspectedHiveId = hiveId;
  const h = USER_HIVES_MAP[hiveId]; if(!h) return;
  FarmAudio.playPop(); FarmAudio.playBee(0.5);
  const d=h.details||{}, th=parseFloat(d.total_honey)||0, mc=parseFloat(d.max_capacity)||100, pct=parseFloat(d.fill_percentage)||0;
  const bc=parseInt(d.bee_count)||0, ms=parseInt(h.max_slots)||0, hp=parseFloat(d.hourly_production)||0;
  document.getElementById('inspectionHiveName').innerHTML='<i class="ph-fill ph-hexagon" style="color:#fbbf24;"></i> '+(h.master_name||'Sarang');
  document.getElementById('inspectionHoneyAmt').innerText=th.toFixed(2)+' ml';
  document.getElementById('inspectionHoneyCap').innerText='Kapasitas: '+th.toFixed(1)+' / '+mc.toFixed(0)+' ml ('+pct+'%)';
  document.getElementById('inspectionBeeInfo').innerHTML='<i class="ph-fill ph-bug-beetle"></i><span>'+bc+'/'+ms+' Lebah • +'+hp.toFixed(1)+' ml/jam</span>';
  const btn=document.getElementById('btnInspectionHarvest'); if(btn) btn.disabled = th<0.1;
  renderHexagonCells(pct,'inspectionHexGrid');
  document.getElementById('hiveInspectionOverlay').classList.add('active');
  document.body.style.overflow='hidden';
}
function closeHiveInspection() {
  document.getElementById('hiveInspectionOverlay').classList.remove('active');
  document.body.style.overflow=''; currentInspectedHiveId=0;
}
document.addEventListener('keydown', e => { if(e.key==='Escape') closeHiveInspection(); });
function spawnHoneyFly(t,el) {
  const r=el?el.getBoundingClientRect():{top:innerHeight/2,left:innerWidth/2};
  const b=document.createElement('div'); b.className='floating-honey-fly'; b.innerText='+ '+t+' ml';
  b.style.top=(r.top+10)+'px'; b.style.left=(r.left+20)+'px';
  document.body.appendChild(b); setTimeout(()=>b.remove(),1200);
}
function harvestInspectedHive() {
  if(!currentInspectedHiveId) return;
  const btn=document.getElementById('btnInspectionHarvest'); if(!btn||btn.disabled) return;
  btn.disabled=true; const ot=btn.innerHTML; btn.innerHTML='<i class="ph-bold ph-spinner ph-spin"></i> Memanen...';
  FarmAudio.playHarvest();
  const csrf=document.querySelector('input[name="_csrf"]')?.value||'';
  const fd=new FormData(); fd.append('action','harvest_hive'); fd.append('hive_id',currentInspectedHiveId); fd.append('_csrf',csrf);
  fetch('/api/farm_action',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{
    if(d.ok){
      spawnHoneyFly(d.harvested_ml,btn);
      const hs=document.getElementById('hudHoneyStock');
      if(hs&&d.new_honey_stock!==undefined) hs.innerText=Number(d.new_honey_stock).toLocaleString('id-ID',{minimumFractionDigits:1})+' ml';
      document.getElementById('inspectionHoneyAmt').innerText='0.00 ml';
      renderHexagonCells(0,'inspectionHexGrid');
      if(USER_HIVES_MAP[currentInspectedHiveId]?.details){USER_HIVES_MAP[currentInspectedHiveId].details.total_honey=0;USER_HIVES_MAP[currentInspectedHiveId].details.fill_percentage=0;}
      btn.innerHTML='<i class="ph-bold ph-check"></i> Dipanen!'; setTimeout(()=>{btn.innerHTML=ot;btn.disabled=true;},1500);
    } else { alert(d.msg||'Gagal'); btn.disabled=false; btn.innerHTML=ot; }
  }).catch(()=>{btn.disabled=false;btn.innerHTML=ot;alert('Error jaringan.');});
}
function harvestAllHives() {
  const btn=document.getElementById('btnHarvestAll'); if(!btn||btn.disabled) return;
  btn.disabled=true; const ot=btn.innerHTML; btn.innerHTML='<i class="ph-bold ph-spinner ph-spin"></i> Memanen...';
  FarmAudio.playHarvest();
  const csrf=document.querySelector('input[name="_csrf"]')?.value||'';
  const fd=new FormData(); fd.append('action','harvest_all'); fd.append('_csrf',csrf);
  fetch('/api/farm_action',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{
    if(d.ok){
      spawnHoneyFly(d.harvested_ml,btn);
      const hs=document.getElementById('hudHoneyStock');
      if(hs&&d.new_honey_stock!==undefined) hs.innerText=Number(d.new_honey_stock).toLocaleString('id-ID',{minimumFractionDigits:1})+' ml';
      const tb=document.getElementById('totalUnharvestedBadge'); if(tb) tb.innerText='0.0 ml';
      btn.innerHTML='<i class="ph-bold ph-check"></i> '+(d.msg||'Berhasil!'); setTimeout(()=>{btn.innerHTML=ot;btn.disabled=true;},2000);
    } else { alert(d.msg||'Gagal'); btn.disabled=false; btn.innerHTML=ot; }
  }).catch(()=>{btn.disabled=false;btn.innerHTML=ot;alert('Error jaringan.');});
}
</script>

<?php require dirname(__DIR__) . '/partials/footer.php'; ?>
