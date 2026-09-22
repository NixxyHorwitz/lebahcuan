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

$pageTitle = 'Kebun Sarang Lebah Cuan — 3D Tycoon';
$activePage = 'farm';
$farmSubPage = 'meadow';
require dirname(__DIR__) . '/partials/header.php';
?>

<style>
body { background: #071a0c !important; font-family: 'Nunito', sans-serif; overflow-x: hidden; }

#farm3dCanvas {
  position: relative; width: 100%; height: 70vh; min-height: 420px;
  border-bottom: 4px solid #1a5c2e; overflow: hidden;
}
#farm3dCanvas canvas { display: block; width: 100% !important; height: 100% !important; }

/* Loading */
.farm3d-loading {
  position: absolute; top: 0; left: 0; right: 0; bottom: 0;
  background: linear-gradient(180deg, #1a3d1f 0%, #071a0c 100%);
  display: flex; flex-direction: column; align-items: center; justify-content: center;
  z-index: 20; transition: opacity 0.8s;
}
.farm3d-loading.hidden { opacity: 0; pointer-events: none; }
.farm3d-loading-spinner { width: 52px; height: 52px; border: 4px solid rgba(251,191,36,0.15); border-top: 4px solid #fbbf24; border-radius: 50%; animation: spin3d 0.8s linear infinite; }
@keyframes spin3d { to { transform: rotate(360deg); } }
.farm3d-loading-text { margin-top: 14px; font-size: 14px; font-weight: 800; color: #fbbf24; }

/* Cinematic Nav */
.cinema-nav {
  position: absolute; bottom: 16px; left: 50%; transform: translateX(-50%);
  display: flex; align-items: center; gap: 10px; z-index: 18;
}
.cinema-btn {
  width: 44px; height: 44px; border-radius: 50%;
  background: rgba(0,0,0,0.55); backdrop-filter: blur(8px);
  border: 2px solid rgba(255,255,255,0.15);
  color: #fbbf24; font-size: 20px; cursor: pointer;
  display: flex; align-items: center; justify-content: center;
  transition: all 0.2s;
}
.cinema-btn:hover { background: rgba(251,191,36,0.2); border-color: #fbbf24; transform: scale(1.1); }
.cinema-btn:active { transform: scale(0.95); }
.cinema-btn:disabled { opacity: 0.3; cursor: not-allowed; }
.cinema-indicator {
  background: rgba(0,0,0,0.55); backdrop-filter: blur(8px);
  border: 1.5px solid rgba(255,255,255,0.1); border-radius: 14px;
  padding: 6px 16px; text-align: center; min-width: 120px;
}
.cinema-indicator-name { font-size: 11px; font-weight: 900; color: #fbbf24; }
.cinema-indicator-sub { font-size: 9px; font-weight: 700; color: rgba(255,255,255,0.5); }

/* Zoom buttons */
.cinema-zoom {
  position: absolute; bottom: 16px; right: 14px; z-index: 18;
  display: flex; flex-direction: column; gap: 6px;
}
.cinema-zoom-btn {
  width: 36px; height: 36px; border-radius: 10px;
  background: rgba(0,0,0,0.5); backdrop-filter: blur(6px);
  border: 1.5px solid rgba(255,255,255,0.1);
  color: rgba(255,255,255,0.7); font-size: 18px; font-weight: 900;
  cursor: pointer; display: flex; align-items: center; justify-content: center;
  transition: all 0.2s;
}
.cinema-zoom-btn:hover { background: rgba(255,255,255,0.1); color: #fff; }

/* Ambient toggle */
.ambient-sound-toggle {
  position: absolute; top: 12px; right: 12px; z-index: 18;
  background: rgba(0,0,0,0.5); backdrop-filter: blur(6px);
  border: 1.5px solid rgba(255,255,255,0.1); border-radius: 12px;
  padding: 6px 12px; font-size: 11px; font-weight: 800; color: #fbbf24;
  cursor: pointer; display: flex; align-items: center; gap: 5px;
}

/* Labels */
#hiveLabelsContainer { position: absolute; top: 0; left: 0; width: 100%; height: 100%; pointer-events: none; z-index: 14; }
.hive-3d-label {
  position: absolute; pointer-events: auto; cursor: pointer;
  transform: translate(-50%, -100%); transition: transform 0.15s;
}
.hive-3d-label:hover { transform: translate(-50%, -100%) scale(1.1); }
.hive-label-bubble {
  background: linear-gradient(135deg, #fbbf24, #e68a00);
  border: 2.5px solid #78350f; border-radius: 14px;
  padding: 5px 12px; box-shadow: 0 3px 0 #78350f, 0 5px 14px rgba(0,0,0,0.4);
  text-align: center; white-space: nowrap; position: relative;
}
.hive-label-name { font-size: 10.5px; font-weight: 900; color: #fff; text-shadow: 0 1px 2px rgba(0,0,0,0.5); }
.hive-label-honey { font-size: 9px; font-weight: 800; color: #fef3c7; }
.hive-label-bubble::after {
  content: ''; position: absolute; bottom: -8px; left: 50%; transform: translateX(-50%);
  border-left: 7px solid transparent; border-right: 7px solid transparent; border-top: 8px solid #78350f;
}

/* Ground UI */
.farm-ground-ui { background: linear-gradient(180deg, #0f2a16 0%, #071a0c 100%); padding: 16px 14px 150px; }
.ground-section-title { display: flex; align-items: center; gap: 8px; margin-bottom: 14px; }
.ground-section-title .pill { background: rgba(255,255,255,0.06); border: 1.5px solid rgba(251,191,36,0.25); border-radius: 12px; padding: 5px 14px; font-size: 12px; font-weight: 800; color: #fbbf24; }
.hive-quick-list { display: flex; flex-direction: column; gap: 10px; }
.hive-quick-card { background: rgba(255,255,255,0.035); border: 1.5px solid rgba(255,255,255,0.07); border-radius: 16px; padding: 12px 14px; display: flex; align-items: center; gap: 12px; cursor: pointer; transition: all 0.2s; }
.hive-quick-card:hover { background: rgba(251,191,36,0.08); border-color: rgba(251,191,36,0.3); transform: translateX(4px); }
.hive-quick-card img { width: 50px; height: 50px; object-fit: contain; border-radius: 12px; background: rgba(255,255,255,0.05); padding: 4px; flex-shrink: 0; }
.hive-quick-info { flex: 1; min-width: 0; }
.hive-quick-name { font-size: 13px; font-weight: 800; color: #f8fafc; }
.hive-quick-meta { font-size: 10.5px; font-weight: 700; color: #94a3b8; margin-top: 1px; }
.hive-quick-honey { font-size: 14px; font-weight: 900; color: #fbbf24; text-align: right; flex-shrink: 0; }
.hive-quick-honey small { font-size: 10px; color: #94a3b8; display: block; font-weight: 700; }
.farm-empty-cta { text-align: center; padding: 30px 20px; background: rgba(255,255,255,0.03); border: 2px dashed rgba(251,191,36,0.2); border-radius: 20px; }
.farm-empty-cta .emoji { font-size: 48px; margin-bottom: 10px; }
.farm-empty-cta .title { font-size: 16px; font-weight: 900; color: #f8fafc; }
.farm-empty-cta .sub { font-size: 12px; color: #94a3b8; margin-top: 4px; }
.farm-empty-cta .btn-cta { margin-top: 14px; display: inline-flex; align-items: center; gap: 6px; background: linear-gradient(135deg, #f59e0b, #d97706); border: 2px solid #78350f; border-radius: 14px; padding: 10px 20px; color: #fff; font-size: 13px; font-weight: 900; text-decoration: none; box-shadow: 0 4px 0 #78350f; }
.meadow-sticky-bar { position: fixed; bottom: 84px; left: 50%; transform: translateX(-50%); width: calc(100% - 24px); max-width: 456px; z-index: 50; }
.btn-harvest-all { width: 100%; background: linear-gradient(135deg, #fbbf24 0%, #f59e0b 45%, #d97706 100%); border: 3.5px solid #78350f; border-radius: 20px; box-shadow: 0 6px 0 #78350f, 0 10px 25px rgba(180,83,9,0.4); padding: 13px 18px; color: #fff; font-size: 15px; font-weight: 900; display: flex; align-items: center; justify-content: space-between; cursor: pointer; font-family: 'Nunito', sans-serif; text-shadow: 0 2px 0 #78350f; transition: transform 0.1s; }
.btn-harvest-all:active { transform: translateY(3px); box-shadow: 0 3px 0 #78350f; }
.btn-harvest-all:disabled { background: #cbd5e1; border-color: #64748b; color: #475569; text-shadow: none; box-shadow: none; cursor: not-allowed; }

/* Inspection Modal */
.hive-inspection-overlay { position: fixed; top: 0; left: 0; right: 0; bottom: 0; z-index: 9999; display: flex; align-items: center; justify-content: center; opacity: 0; visibility: hidden; transition: opacity 0.4s, visibility 0.4s; }
.hive-inspection-overlay.active { opacity: 1; visibility: visible; }
.hive-inspection-vignette { position: absolute; top: 0; left: 0; right: 0; bottom: 0; z-index: 1; background: radial-gradient(circle at center, rgba(45,20,5,0.82) 0%, rgba(30,12,3,0.92) 35%, rgba(15,6,1,0.97) 65%, rgba(5,2,0,0.99) 100%); backdrop-filter: blur(8px); }
.hive-inspection-close { position: absolute; top: 18px; right: 18px; width: 44px; height: 44px; background: rgba(255,255,255,0.15); border: 2px solid rgba(255,255,255,0.3); border-radius: 50%; color: #fef3c7; font-size: 22px; display: flex; align-items: center; justify-content: center; cursor: pointer; z-index: 100; }
.hive-inspection-title { position: absolute; top: 22px; left: 0; right: 0; text-align: center; z-index: 50; }
.hive-inspection-title span { display: inline-flex; align-items: center; gap: 8px; background: rgba(120,53,15,0.6); border: 2px solid rgba(251,191,36,0.4); border-radius: 16px; padding: 6px 16px; font-size: 14px; font-weight: 900; color: #fde68a; backdrop-filter: blur(4px); }
.hive-inspection-frame { position: relative; z-index: 50; width: 290px; max-width: 85vw; animation: frameSlideIn 0.5s cubic-bezier(0.34,1.56,0.64,1) forwards; }
@keyframes frameSlideIn { 0% { transform: scale(0.7) translateY(30px); opacity: 0; } 100% { transform: scale(1) translateY(0); opacity: 1; } }
.honeycomb-organic-frame { background-image: repeating-linear-gradient(45deg, rgba(120,53,15,0.3) 0px, rgba(120,53,15,0.3) 2px, transparent 2px, transparent 6px), linear-gradient(180deg, #92400e 0%, #78350f 35%, #5c2d0e 100%); border: 4px solid #451a03; border-radius: 24px; padding: 18px 14px; box-shadow: inset 0 4px 8px rgba(0,0,0,0.5), 0 0 40px rgba(251,191,36,0.2); position: relative; overflow: hidden; }
.honeycomb-organic-frame::before { content: ''; position: absolute; top: 0; left: 0; right: 0; bottom: 0; background: repeating-linear-gradient(90deg, transparent 0px, transparent 8px, rgba(69,26,3,0.15) 8px, rgba(69,26,3,0.15) 9px); pointer-events: none; }
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
.hex-cell { width: 36px; height: 40px; position: relative; clip-path: polygon(50% 0%,100% 25%,100% 75%,50% 100%,0% 75%,0% 25%); display: flex; align-items: center; justify-content: center; }
.hex-cell--empty { background: #451a03; }
.hex-cell--empty::after { content: ''; position: absolute; inset: 3px; clip-path: polygon(50% 0%,100% 25%,100% 75%,50% 100%,0% 75%,0% 25%); background: #271202; opacity: 0.92; }
.hex-cell--honey { background: linear-gradient(180deg, #fde047 0%, #eab308 45%, #b45309 100%); filter: drop-shadow(0 0 6px #f59e0b); animation: honeyGlow 2.5s ease-in-out infinite alternate; }
.hex-cell--honey::after { content: ''; position: absolute; top: 4px; left: 8px; width: 14px; height: 10px; background: rgba(255,255,255,0.65); border-radius: 50%; transform: rotate(-25deg); pointer-events: none; }
@keyframes honeyGlow { 0% { filter: drop-shadow(0 0 3px #f59e0b); } 100% { filter: drop-shadow(0 0 12px #fde047); } }
.hive-inspection-info { position: relative; z-index: 5; margin-top: 16px; text-align: center; }
.inspection-honey-amount { font-size: 28px; font-weight: 900; color: #fbbf24; display: flex; align-items: center; justify-content: center; gap: 8px; }
.inspection-honey-sub { font-size: 11px; font-weight: 800; color: rgba(254,243,199,0.7); margin-top: 2px; }
.inspection-bee-info { font-size: 11px; font-weight: 800; color: rgba(254,243,199,0.5); margin-top: 6px; display: flex; align-items: center; justify-content: center; gap: 5px; }
.btn-inspection-harvest { margin-top: 14px; background: linear-gradient(135deg, #f59e0b 0%, #d97706 60%, #b45309 100%); border: 3px solid #78350f; border-radius: 18px; box-shadow: 0 5px 0 #78350f; padding: 12px 24px; color: #fff; font-size: 14px; font-weight: 900; display: inline-flex; align-items: center; gap: 8px; cursor: pointer; font-family: 'Nunito', sans-serif; transition: transform 0.1s; }
.btn-inspection-harvest:active { transform: translateY(3px); box-shadow: 0 2px 0 #78350f; }
.btn-inspection-harvest:disabled { background: #4b5563; border-color: #374151; color: #9ca3af; box-shadow: 0 3px 0 #374151; cursor: not-allowed; }
.floating-honey-fly { position: fixed; z-index: 99999; font-size: 16px; font-weight: 900; color: #b45309; background: #fef3c7; border: 2.5px solid #b45309; border-radius: 12px; padding: 4px 10px; box-shadow: 0 4px 0 #b45309; pointer-events: none; animation: honeyFloatUp 1.2s forwards ease-out; }
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

  <!-- Cinematic Camera Nav -->
  <?php if (count($user_hives) > 0): ?>
  <div class="cinema-nav">
    <button class="cinema-btn" id="btnCinePrev" onclick="cinemaPrev()"><i class="ph-bold ph-caret-left"></i></button>
    <div class="cinema-indicator">
      <div class="cinema-indicator-name" id="cineName">Overview</div>
      <div class="cinema-indicator-sub" id="cineSub">Lihat semua sarang</div>
    </div>
    <button class="cinema-btn" id="btnCineNext" onclick="cineNext()"><i class="ph-bold ph-caret-right"></i></button>
  </div>
  <div class="cinema-zoom">
    <button class="cinema-zoom-btn" onclick="cineZoomIn()">+</button>
    <button class="cinema-zoom-btn" onclick="cineZoomOut()">−</button>
  </div>
  <?php endif; ?>

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
      <a href="/farm/shop" class="btn-cta"><i class="ph-fill ph-storefront"></i> Buka Toko</a>
    </div>
  <?php else: ?>
    <div class="hive-quick-list">
      <?php foreach ($user_hives as $uh):
        $det = $uh['details']; $un = (float)($det['total_honey'] ?? 0); $pr = (float)($det['hourly_production'] ?? 0);
        $spr = !empty($uh['master_image']) ? $uh['master_image'] : '/assets/game/beehive_wooden.png';
      ?>
        <div class="hive-quick-card" onclick="openHiveInspection(<?= $uh['id'] ?>)">
          <img src="<?= htmlspecialchars($spr) ?>" alt="">
          <div class="hive-quick-info">
            <div class="hive-quick-name"><?= htmlspecialchars($uh['master_name']) ?></div>
            <div class="hive-quick-meta"><?= (int)$det['bee_count'] ?>/<?= (int)$uh['max_slots'] ?> Lebah • +<?= number_format($pr, 1) ?> ml/jam</div>
          </div>
          <div class="hive-quick-honey"><?= number_format($un, 1) ?> <small>ml madu</small></div>
        </div>
      <?php endforeach; ?>
    </div>
    <a href="/farm/shop" style="display:block;text-align:center;margin-top:14px;text-decoration:none;"><span style="font-size:12px;font-weight:800;color:#fbbf24;">+ Beli Sarang Baru →</span></a>
  <?php endif; ?>

  <?php if (!empty($user_hives)): ?>
  <div class="meadow-sticky-bar">
    <button type="button" class="btn-harvest-all" id="btnHarvestAll" onclick="harvestAllHives()" <?= $total_unharvested_honey < 0.1 ? 'disabled' : '' ?>>
      <div style="display:flex;align-items:center;gap:8px;"><i class="ph-fill ph-drop" style="font-size:22px;"></i><span>PANEN SEMUA</span></div>
      <div style="background:rgba(0,0,0,0.25);padding:4px 12px;border-radius:12px;font-size:13.5px;font-weight:900;" id="totalUnharvestedBadge"><?= number_format($total_unharvested_honey, 1) ?> ml</div>
    </button>
  </div>
  <?php endif; ?>
</div>

<!-- Inspection Modal -->
<div class="hive-inspection-overlay" id="hiveInspectionOverlay">
  <div class="hive-inspection-vignette" onclick="closeHiveInspection()"></div>
  <button class="hive-inspection-close" onclick="closeHiveInspection()"><i class="ph-bold ph-x"></i></button>
  <div class="hive-inspection-title"><span id="inspectionHiveName"><i class="ph-fill ph-hexagon" style="color:#fbbf24;"></i> Sarang</span></div>
  <div class="hive-inspection-frame">
    <div class="honeycomb-organic-frame">
      <div class="hive-crawling-bee bee-crawler-1"><img src="/assets/game/bee_worker.png" alt=""></div>
      <div class="hive-crawling-bee bee-crawler-2"><img src="/assets/game/bee_golden.png" onerror="this.src='/assets/game/bee_worker.png'" alt=""></div>
      <div class="hive-crawling-bee bee-crawler-3"><img src="/assets/game/bee_worker.png" alt=""></div>
      <div class="hex-grid" id="inspectionHexGrid"></div>
    </div>
    <div class="hive-inspection-info">
      <div class="inspection-honey-amount"><i class="ph-fill ph-drop"></i><span id="inspectionHoneyAmt">0.00 ml</span></div>
      <div class="inspection-honey-sub" id="inspectionHoneyCap"></div>
      <div class="inspection-bee-info" id="inspectionBeeInfo"></div>
      <button type="button" class="btn-inspection-harvest" id="btnInspectionHarvest" onclick="harvestInspectedHive()"><i class="ph-fill ph-drop"></i><span>PANEN SARANG INI</span></button>
    </div>
  </div>
</div>

<?= csrf_field() ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/three.js/r128/three.min.js"></script>
<script>
const USER_HIVES_MAP = <?= json_encode(array_column($user_hives, null, 'id')) ?>;
const HIVES_ARRAY = <?= json_encode(array_values($user_hives)) ?>;
let currentInspectedHiveId = 0;

// ── AMBIENT SOUND ENGINE ──
const AmbientEngine = (function(){
  let ctx=null, mg=null, playing=false, nodes=[];
  function gc(){ if(!ctx){const A=window.AudioContext||window.webkitAudioContext;if(A){ctx=new A();mg=ctx.createGain();mg.gain.value=0.3;mg.connect(ctx.destination);}} if(ctx&&ctx.state==='suspended')ctx.resume(); return ctx; }
  function beeLoop(){ const c=gc();if(!c)return; const o1=c.createOscillator(),o2=c.createOscillator(),lfo=c.createOscillator(),lg=c.createGain(),bp=c.createBiquadFilter(),g=c.createGain(); o1.type='sawtooth';o1.frequency.value=155; o2.type='triangle';o2.frequency.value=162; lfo.type='sine';lfo.frequency.value=8;lg.gain.value=12; lfo.connect(lg);lg.connect(o1.frequency);lg.connect(o2.frequency); bp.type='bandpass';bp.frequency.value=260;bp.Q.value=3; g.gain.value=0.05; o1.connect(bp);o2.connect(bp);bp.connect(g);g.connect(mg); o1.start();o2.start();lfo.start(); nodes.push(o1,o2,lfo); }
  function windLoop(){ const c=gc();if(!c)return; const bs=c.sampleRate*2,bf=c.createBuffer(1,bs,c.sampleRate),d=bf.getChannelData(0); for(let i=0;i<bs;i++)d[i]=Math.random()*2-1; const s=c.createBufferSource();s.buffer=bf;s.loop=true; const lp=c.createBiquadFilter();lp.type='lowpass';lp.frequency.value=350; const g=c.createGain();g.gain.value=0.035; const lfo=c.createOscillator();lfo.type='sine';lfo.frequency.value=0.12; const lg=c.createGain();lg.gain.value=0.015; lfo.connect(lg);lg.connect(g.gain); s.connect(lp);lp.connect(g);g.connect(mg); s.start();lfo.start(); nodes.push(s,lfo); }
  function birds(){ const c=gc();if(!c)return; function chirp(){if(!playing)return; const n=c.currentTime,o=c.createOscillator(),g=c.createGain(),f=1200+Math.random()*1800; o.type='sine';o.frequency.setValueAtTime(f,n);o.frequency.exponentialRampToValueAtTime(f*(0.7+Math.random()*0.6),n+0.08);o.frequency.exponentialRampToValueAtTime(f*1.1,n+0.14); g.gain.setValueAtTime(0.025,n);g.gain.exponentialRampToValueAtTime(0.001,n+0.18); o.connect(g);g.connect(mg);o.start(n);o.stop(n+0.2); setTimeout(chirp,2500+Math.random()*5500);} setTimeout(chirp,1000+Math.random()*3000); }
  function crickets(){ const c=gc();if(!c)return; function tick(){if(!playing)return; const n=c.currentTime; for(let i=0;i<3;i++){const o=c.createOscillator(),g=c.createGain(),t=n+i*0.03; o.type='square';o.frequency.setValueAtTime(4200+Math.random()*800,t); g.gain.setValueAtTime(0.006,t);g.gain.exponentialRampToValueAtTime(0.001,t+0.025); o.connect(g);g.connect(mg);o.start(t);o.stop(t+0.03);} setTimeout(tick,3000+Math.random()*5000);} setTimeout(tick,2000); }
  return { start(){if(playing)return;playing=true;beeLoop();windLoop();birds();crickets();}, stop(){playing=false;nodes.forEach(n=>{try{n.stop();}catch(e){}});nodes=[];if(ctx){ctx.close();ctx=null;}}, isOn(){return playing;} };
})();
let ambientOn=false;
function toggleAmbientSound(){ const b=document.getElementById('btnAmbientSound'); if(ambientOn){AmbientEngine.stop();ambientOn=false;b.innerHTML='<i class="ph-fill ph-speaker-slash"></i> Muted';}else{AmbientEngine.start();ambientOn=true;b.innerHTML='<i class="ph-fill ph-speaker-high"></i> Ambient';} }

// ══════════════════════════════════════════════════════════
// THREE.JS — CINEMATIC CAM + SMOOTH MOUNTAINS + VIBRANT
// ══════════════════════════════════════════════════════════
(function() {
  const container = document.getElementById('farm3dCanvas');
  const loadingEl = document.getElementById('farm3dLoading');
  const labelsC = document.getElementById('hiveLabelsContainer');

  const scene = new THREE.Scene();
  scene.fog = new THREE.Fog(0x8ec8e8, 35, 90);

  const camera = new THREE.PerspectiveCamera(50, container.clientWidth / container.clientHeight, 0.1, 300);
  const renderer = new THREE.WebGLRenderer({ antialias: true });
  renderer.setSize(container.clientWidth, container.clientHeight);
  renderer.setPixelRatio(Math.min(window.devicePixelRatio, 2));
  renderer.shadowMap.enabled = true;
  renderer.shadowMap.type = THREE.PCFSoftShadowMap;
  renderer.toneMapping = THREE.ACESFilmicToneMapping;
  renderer.toneMappingExposure = 1.3;
  container.insertBefore(renderer.domElement, container.firstChild);

  // Sky
  const skyC = document.createElement('canvas'); skyC.width = 2; skyC.height = 512;
  const skyX = skyC.getContext('2d'), skyG = skyX.createLinearGradient(0, 0, 0, 512);
  skyG.addColorStop(0, '#1e3a8a'); skyG.addColorStop(0.2, '#3b82f6'); skyG.addColorStop(0.45, '#60a5fa');
  skyG.addColorStop(0.65, '#93c5fd'); skyG.addColorStop(0.8, '#dbeafe'); skyG.addColorStop(0.92, '#fef3c7');
  skyG.addColorStop(1, '#fbbf24');
  skyX.fillStyle = skyG; skyX.fillRect(0, 0, 2, 512);
  scene.background = new THREE.CanvasTexture(skyC);

  // Lights — warmer, more vibrant
  scene.add(new THREE.AmbientLight(0xfff5e0, 0.5));
  scene.add(new THREE.HemisphereLight(0x87ceeb, 0x4ade80, 0.4));
  const sun = new THREE.DirectionalLight(0xfff8e8, 1.4);
  sun.position.set(14, 20, 12); sun.castShadow = true;
  sun.shadow.mapSize.set(2048, 2048);
  sun.shadow.camera.near = 0.5; sun.shadow.camera.far = 60;
  sun.shadow.camera.left = -20; sun.shadow.camera.right = 20;
  sun.shadow.camera.top = 20; sun.shadow.camera.bottom = -20;
  sun.shadow.bias = -0.0005;
  scene.add(sun);
  scene.add(new THREE.DirectionalLight(0x88bbff, 0.3).translateTo ? sun : (() => { const l = new THREE.DirectionalLight(0x88ccff, 0.3); l.position.set(-8, 12, -8); return l; })());

  // Visual sun + glow
  const sunV = new THREE.Mesh(new THREE.SphereGeometry(1.5, 16, 16), new THREE.MeshBasicMaterial({ color: 0xfffbe0 }));
  sunV.position.set(24, 26, -18); scene.add(sunV);
  const glowV = new THREE.Mesh(new THREE.SphereGeometry(4, 16, 16), new THREE.MeshBasicMaterial({ color: 0xfff8dc, transparent: true, opacity: 0.12 }));
  glowV.position.copy(sunV.position); scene.add(glowV);

  // ── GROUND — vibrant green ──
  const gGeo = new THREE.PlaneGeometry(100, 100, 80, 80);
  const gPos = gGeo.getAttribute('position');
  for (let i = 0; i < gPos.count; i++) {
    const x = gPos.getX(i), y = gPos.getY(i);
    gPos.setZ(i, Math.sin(x * 0.15) * 0.3 + Math.cos(y * 0.2) * 0.25 + Math.sin(x * 0.5 + y * 0.4) * 0.1);
  }
  gGeo.computeVertexNormals();
  const ground = new THREE.Mesh(gGeo, new THREE.MeshStandardMaterial({ color: 0x38b764, roughness: 0.88 }));
  ground.rotation.x = -Math.PI / 2; ground.receiveShadow = true; scene.add(ground);

  // ── SMOOTH ORGANIC MOUNTAINS ──
  function createSmoothMountain(x, z, height, baseW, color) {
    // Use LatheGeometry with a smooth curve profile
    const pts = [];
    const segments = 12;
    for (let i = 0; i <= segments; i++) {
      const t = i / segments;
      // Smooth bell curve profile
      const r = baseW * (1 - Math.pow(t, 1.3));
      const y = t * height;
      // Add organic wobble
      const wobble = Math.sin(t * 5 + x * 0.3 + z * 0.2) * baseW * 0.08;
      pts.push(new THREE.Vector2(Math.max(0.01, r + wobble), y));
    }
    const geo = new THREE.LatheGeometry(pts, 8 + Math.floor(Math.random() * 4));
    const mat = new THREE.MeshStandardMaterial({ color: color, roughness: 0.9, flatShading: false });
    const m = new THREE.Mesh(geo, mat);
    m.position.set(x, -0.3, z);
    m.rotation.y = Math.random() * Math.PI;
    scene.add(m);

    // Snow on tall mountains
    if (height > 7) {
      const snowPts = [];
      for (let i = 0; i <= 6; i++) {
        const t = i / 6;
        const r2 = baseW * 0.35 * (1 - Math.pow(t, 1.2));
        snowPts.push(new THREE.Vector2(Math.max(0.01, r2), t * height * 0.22));
      }
      const sg = new THREE.LatheGeometry(snowPts, 6);
      const sm = new THREE.Mesh(sg, new THREE.MeshStandardMaterial({ color: 0xf0f8ff, roughness: 0.6 }));
      sm.position.set(x, height * 0.78 - 0.3, z);
      sm.rotation.y = m.rotation.y;
      scene.add(sm);
    }
    return m;
  }

  // Mountains ring — placed OUTSIDE the farm area, well away from hives
  const mtColors = [0x3a7d44, 0x4a8a54, 0x2d6b38, 0x558b5e, 0x1f5c2a];
  const mtFarColors = [0x2d5a36, 0x1a4d28, 0x3a6b42];

  // Near ring (r=28-35)
  for (let i = 0; i < 20; i++) {
    const angle = (i / 20) * Math.PI * 2;
    const dist = 28 + Math.random() * 7;
    const h = 3 + Math.random() * 5;
    const w = 3 + Math.random() * 3;
    createSmoothMountain(Math.sin(angle) * dist, Math.cos(angle) * dist, h, w, mtColors[i % mtColors.length]);
  }
  // Far ring (r=38-50)
  for (let i = 0; i < 14; i++) {
    const angle = (i / 14) * Math.PI * 2 + 0.15;
    const dist = 38 + Math.random() * 12;
    const h = 6 + Math.random() * 8;
    const w = 4 + Math.random() * 5;
    createSmoothMountain(Math.sin(angle) * dist, Math.cos(angle) * dist, h, w, mtFarColors[i % mtFarColors.length]);
  }

  // ── TREES — vibrant, placed outside r=7 from center ──
  function mkTree(x, z, s) {
    const g = new THREE.Group();
    const trunk = new THREE.Mesh(new THREE.CylinderGeometry(0.1*s, 0.16*s, 1.3*s, 6), new THREE.MeshStandardMaterial({ color: 0x7a4a25, roughness: 0.85 }));
    trunk.position.y = 0.65*s; trunk.castShadow = true; g.add(trunk);
    const greens = [0x3ec95c, 0x2db84a, 0x45d468, 0x28a745];
    [[0.65,1.3],[0.5,1.8],[0.3,2.2]].forEach(([r,h], i) => {
      const f = new THREE.Mesh(new THREE.SphereGeometry(r*s, 7, 5), new THREE.MeshStandardMaterial({ color: greens[i % greens.length], roughness: 0.78 }));
      f.position.y = h*s; f.castShadow = true; g.add(f);
    });
    g.position.set(x, 0, z); scene.add(g); return g;
  }
  function mkPine(x, z, s) {
    const g = new THREE.Group();
    g.add(Object.assign(new THREE.Mesh(new THREE.CylinderGeometry(0.06*s,0.1*s,0.8*s,5), new THREE.MeshStandardMaterial({ color: 0x6b4a30 })), { position: new THREE.Vector3(0, 0.4*s, 0) }));
    for (let i = 0; i < 4; i++) {
      const c = new THREE.Mesh(new THREE.ConeGeometry((0.55-i*0.1)*s, 0.55*s, 6), new THREE.MeshStandardMaterial({ color: 0x1a8a3a, roughness: 0.82 }));
      c.position.y = (0.8+i*0.42)*s; c.castShadow = true; g.add(c);
    }
    g.position.set(x, 0, z); scene.add(g);
  }

  // Trees placed at dist > 7 so they don't block hives
  const treeSpots = [];
  for (let i = 0; i < 30; i++) {
    const angle = Math.random() * Math.PI * 2;
    const dist = 8 + Math.random() * 12;
    const x = Math.sin(angle) * dist, z = Math.cos(angle) * dist;
    treeSpots.push([x, z, 0.6 + Math.random() * 0.7]);
  }
  treeSpots.forEach(([x,z,s]) => Math.random() > 0.3 ? mkTree(x,z,s) : mkPine(x,z,s));

  // ── ROCKS — outside r=5 ──
  for (let i = 0; i < 10; i++) {
    const angle = Math.random() * Math.PI * 2;
    const dist = 5 + Math.random() * 10;
    const x = Math.sin(angle) * dist, z = Math.cos(angle) * dist;
    const s = 0.5 + Math.random() * 0.8;
    const r = new THREE.Mesh(new THREE.DodecahedronGeometry(0.3*s, 1), new THREE.MeshStandardMaterial({ color: 0x8a8a8a, roughness: 0.92, flatShading: true }));
    r.position.set(x, 0.08*s, z); r.rotation.set(Math.random()*0.5, Math.random()*Math.PI, 0); r.scale.y = 0.55;
    r.castShadow = true; scene.add(r);
  }

  // ── FLOWERS — vibrant, outside r=4 ──
  const fColors = [0xff5ca8, 0xffd500, 0xff4444, 0xaa66ff, 0xff8c00, 0x00ccff, 0xff1493];
  for (let f = 0; f < 15; f++) {
    const angle = Math.random() * Math.PI * 2;
    const dist = 4 + Math.random() * 10;
    const fx = Math.sin(angle) * dist, fz = Math.cos(angle) * dist;
    const g = new THREE.Group();
    for (let i = 0; i < 6; i++) {
      const a = (i/6)*Math.PI*2, rad = 0.1+Math.random()*0.1;
      const stem = new THREE.Mesh(new THREE.CylinderGeometry(0.01,0.01,0.2,3), new THREE.MeshStandardMaterial({ color: 0x38b764 }));
      stem.position.set(Math.cos(a)*rad, 0.1, Math.sin(a)*rad); g.add(stem);
      const pet = new THREE.Mesh(new THREE.SphereGeometry(0.04+Math.random()*0.02,5,3), new THREE.MeshStandardMaterial({ color: fColors[i%fColors.length] }));
      pet.position.set(Math.cos(a)*rad, 0.24, Math.sin(a)*rad); g.add(pet);
    }
    g.position.set(fx, 0, fz); scene.add(g);
  }

  // ── GRASS TUFTS outside r=3 ──
  const grassMat = new THREE.MeshStandardMaterial({ color: 0x4ade80, side: THREE.DoubleSide, transparent: true, opacity: 0.75 });
  for (let i = 0; i < 100; i++) {
    const angle = Math.random() * Math.PI * 2, dist = 3 + Math.random() * 15;
    const blade = new THREE.Mesh(new THREE.PlaneGeometry(0.06+Math.random()*0.05, 0.12+Math.random()*0.12), grassMat);
    blade.position.set(Math.sin(angle)*dist, 0.06, Math.cos(angle)*dist);
    blade.rotation.y = Math.random()*Math.PI; scene.add(blade);
  }

  // ── POND ──
  const pond = new THREE.Mesh(new THREE.CircleGeometry(1.6, 24), new THREE.MeshStandardMaterial({ color: 0x38bdf8, roughness: 0.12, metalness: 0.3, transparent: true, opacity: 0.82 }));
  pond.rotation.x = -Math.PI/2; pond.position.set(7, 0.06, 5); scene.add(pond);
  for (let i = 0; i < 8; i++) {
    const a = (i/8)*Math.PI*2;
    const sr = new THREE.Mesh(new THREE.DodecahedronGeometry(0.12+Math.random()*0.08, 0), new THREE.MeshStandardMaterial({ color: 0x94a3b8, flatShading: true }));
    sr.position.set(7+Math.cos(a)*1.7, 0.06, 5+Math.sin(a)*1.7); sr.scale.y = 0.5; scene.add(sr);
  }

  // ── BEEHIVES (center area, well spaced) ──
  const hive3D = [], hiveWP = [];

  function mkBeehive() {
    const g = new THREE.Group();
    // Legs
    [[-0.38,-0.28],[0.38,-0.28],[-0.38,0.28],[0.38,0.28]].forEach(([lx,lz]) => {
      const leg = new THREE.Mesh(new THREE.CylinderGeometry(0.04,0.04,0.35,4), new THREE.MeshStandardMaterial({ color: 0x6b4a30 }));
      leg.position.set(lx, -0.12, lz); g.add(leg);
    });
    // Base
    const base = new THREE.Mesh(new THREE.BoxGeometry(1.1,0.1,0.9), new THREE.MeshStandardMaterial({ color: 0x9b6b35, roughness: 0.82 }));
    base.position.y = 0.06; base.castShadow = true; base.receiveShadow = true; g.add(base);
    // 3 brood boxes — vibrant warm wood
    const bCols = [0xd4a040, 0xe8b84a, 0xf0c860];
    for (let i = 0; i < 3; i++) {
      const box = new THREE.Mesh(new THREE.BoxGeometry(1.0,0.35,0.8), new THREE.MeshStandardMaterial({ color: bCols[i], roughness: 0.72, metalness: 0.03 }));
      box.position.y = 0.3+i*0.37; box.castShadow = true; g.add(box);
      const trim = new THREE.Mesh(new THREE.BoxGeometry(1.04,0.025,0.84), new THREE.MeshStandardMaterial({ color: 0x7a4a20 }));
      trim.position.y = 0.12+i*0.37+0.18; g.add(trim);
    }
    // Roof
    const roof = new THREE.Mesh(new THREE.ConeGeometry(0.72,0.38,4), new THREE.MeshStandardMaterial({ color: 0x8b5e3c, roughness: 0.8 }));
    roof.position.y = 1.55; roof.rotation.y = Math.PI/4; roof.castShadow = true; g.add(roof);
    const cap = new THREE.Mesh(new THREE.BoxGeometry(1.12,0.05,0.92), new THREE.MeshStandardMaterial({ color: 0x7a4a20 }));
    cap.position.y = 1.35; g.add(cap);
    // Entrance
    const ent = new THREE.Mesh(new THREE.CircleGeometry(0.09,8), new THREE.MeshStandardMaterial({ color: 0x0a0500 }));
    ent.position.set(0, 0.38, 0.41); g.add(ent);
    // Landing board
    const lb = new THREE.Mesh(new THREE.BoxGeometry(0.24,0.02,0.16), new THREE.MeshStandardMaterial({ color: 0x9b6b35 }));
    lb.position.set(0, 0.28, 0.48); lb.rotation.x = -0.15; g.add(lb);
    return g;
  }

  function hivePos(idx, total) {
    const spacing = 3.2, maxRow = 3;
    const row = Math.floor(idx/maxRow), col = idx%maxRow;
    const rowCount = Math.min(total - row*maxRow, maxRow);
    const ox = -(rowCount-1)*spacing/2;
    return { x: ox + col*spacing + (row%2===1 ? spacing*0.4 : 0), z: -1 + row*3.0 };
  }

  HIVES_ARRAY.forEach((hive, idx) => {
    const h = mkBeehive();
    const p = hivePos(idx, HIVES_ARRAY.length);
    h.position.set(p.x, 0, p.z);
    h.userData.hiveId = hive.id;
    scene.add(h);
    hive3D.push(h);
    hiveWP.push(new THREE.Vector3(p.x, 2.0, p.z));
  });

  // ── BEES (only near hives that have bees) ──
  const bees = [];
  HIVES_ARRAY.forEach((hive, idx) => {
    const bc = parseInt(hive.bee_count) || 0;
    if (bc <= 0) return;
    const p = hivePos(idx, HIVES_ARRAY.length);
    const count = Math.min(bc, 4);
    for (let i = 0; i < count; i++) {
      const g = new THREE.Group();
      const body = new THREE.Mesh(new THREE.SphereGeometry(0.06,8,6), new THREE.MeshStandardMaterial({ color: 0xf5a623 }));
      body.scale.set(1,0.7,1.3); g.add(body);
      for (let s = 0; s < 2; s++) {
        const st = new THREE.Mesh(new THREE.TorusGeometry(0.055,0.012,4,8), new THREE.MeshStandardMaterial({ color: 0x1a1a1a }));
        st.position.z = -0.02+s*0.04; st.rotation.x = Math.PI/2; g.add(st);
      }
      const wm = new THREE.MeshStandardMaterial({ color: 0xffffff, transparent: true, opacity: 0.45, side: THREE.DoubleSide });
      const wL = new THREE.Mesh(new THREE.PlaneGeometry(0.1,0.06), wm); wL.position.set(-0.06,0.04,0); wL.rotation.z = 0.3; g.add(wL);
      const wR = new THREE.Mesh(new THREE.PlaneGeometry(0.1,0.06), wm); wR.position.set(0.06,0.04,0); wR.rotation.z = -0.3; g.add(wR);
      g.userData = { wL, wR, phase: Math.random()*Math.PI*2, speed: 0.6+Math.random()*1.2, radius: 0.8+Math.random()*1.5, h: 1.5+Math.random()*1.5, cx: p.x, cz: p.z };
      scene.add(g); bees.push(g);
    }
  });

  // Extra ambient bees
  for (let i = 0; i < 4; i++) {
    const g = new THREE.Group();
    const body = new THREE.Mesh(new THREE.SphereGeometry(0.05,6,4), new THREE.MeshStandardMaterial({ color: 0xf5a623 }));
    body.scale.set(1,0.7,1.2); g.add(body);
    const wm = new THREE.MeshStandardMaterial({ color: 0xffffff, transparent: true, opacity: 0.4, side: THREE.DoubleSide });
    const wL = new THREE.Mesh(new THREE.PlaneGeometry(0.08,0.05), wm); wL.position.set(-0.05,0.03,0); wL.rotation.z = 0.3; g.add(wL);
    const wR = new THREE.Mesh(new THREE.PlaneGeometry(0.08,0.05), wm); wR.position.set(0.05,0.03,0); wR.rotation.z = -0.3; g.add(wR);
    g.userData = { wL, wR, phase: Math.random()*Math.PI*2, speed: 0.3+Math.random()*0.6, radius: 4+Math.random()*6, h: 2+Math.random()*3, cx: (Math.random()-0.5)*8, cz: (Math.random()-0.5)*8 };
    scene.add(g); bees.push(g);
  }

  // ── BUTTERFLIES ──
  const bflies = [];
  for (let i = 0; i < 5; i++) {
    const g = new THREE.Group();
    const bc = [0xff69b4, 0xffa500, 0x87ceeb, 0xffd700, 0xaa66ff][i];
    const wm = new THREE.MeshStandardMaterial({ color: bc, side: THREE.DoubleSide, transparent: true, opacity: 0.8 });
    const wL = new THREE.Mesh(new THREE.PlaneGeometry(0.12,0.08), wm); wL.position.x = -0.06; g.add(wL);
    const wR = new THREE.Mesh(new THREE.PlaneGeometry(0.12,0.08), wm); wR.position.x = 0.06; g.add(wR);
    g.userData = { wL, wR, phase: Math.random()*Math.PI*2, speed: 0.3+Math.random()*0.5, radius: 3+Math.random()*5, h: 1+Math.random()*2.5, cx: (Math.random()-0.5)*12, cz: (Math.random()-0.5)*12 };
    scene.add(g); bflies.push(g);
  }

  // ── CLOUDS ──
  const clouds = [];
  function mkCloud(x,y,z,s) {
    const g = new THREE.Group();
    const m = new THREE.MeshStandardMaterial({ color: 0xffffff, roughness: 1, transparent: true, opacity: 0.78 });
    [[0,0,0,0.8],[-0.6,0.1,0.1,0.6],[0.5,0.15,-0.1,0.7],[-0.2,-0.1,0.2,0.5],[0.7,0.05,0.15,0.45]].forEach(([ox,oy,oz,r]) => {
      g.add(Object.assign(new THREE.Mesh(new THREE.SphereGeometry(r*s,6,5), m), { position: new THREE.Vector3(ox*s,oy*s,oz*s) }));
    });
    g.position.set(x,y,z); g.userData.speed = 0.012+Math.random()*0.02;
    scene.add(g); return g;
  }
  [[-12,14,-22,1.4],[8,15,-28,1.6],[18,13,-20,1.2],[-6,16,-30,1.8],[22,12,-16,1.0],[-18,14,-25,1.3],[0,17,-35,2.0]].forEach(([x,y,z,s]) => clouds.push(mkCloud(x,y,z,s)));

  // ── POLLEN ──
  const pGeo = new THREE.BufferGeometry(), pCnt = 70, pPos = new Float32Array(pCnt*3);
  for (let i = 0; i < pCnt; i++) { pPos[i*3]=(Math.random()-0.5)*20; pPos[i*3+1]=0.3+Math.random()*5; pPos[i*3+2]=(Math.random()-0.5)*20; }
  pGeo.setAttribute('position', new THREE.BufferAttribute(pPos, 3));
  scene.add(new THREE.Points(pGeo, new THREE.PointsMaterial({ color: 0xfde047, size: 0.06, transparent: true, opacity: 0.6 })));

  // ══════════════════════════════════════════════════════════
  // CINEMATIC CAMERA SYSTEM
  // ══════════════════════════════════════════════════════════
  let cineIdx = -1; // -1 = overview
  let cineZoom = 0; // 0=normal, negative=zoomed in

  // Camera target & current (for smooth lerp)
  const camTarget = { x: 0, y: 10, z: 18, lx: 0, ly: 1, lz: 0 };
  const camCurrent = { x: 0, y: 10, z: 18, lx: 0, ly: 1, lz: 0 };

  function setCineOverview() {
    cineIdx = -1;
    camTarget.x = 0; camTarget.y = 10 + cineZoom; camTarget.z = 18 + cineZoom;
    camTarget.lx = 0; camTarget.ly = 1; camTarget.lz = 0;
    updateCineUI();
  }

  function setCineHive(idx) {
    if (idx < 0 || idx >= HIVES_ARRAY.length) return;
    cineIdx = idx;
    const p = hivePos(idx, HIVES_ARRAY.length);
    // Camera positioned behind & above the hive, looking at it
    const angle = Math.PI * 0.15; // slight side angle
    const dist = 4.5 + cineZoom * 0.3;
    camTarget.x = p.x + Math.sin(angle) * dist;
    camTarget.y = 3.5 + cineZoom * 0.2;
    camTarget.z = p.z + Math.cos(angle) * dist;
    camTarget.lx = p.x;
    camTarget.ly = 0.8;
    camTarget.lz = p.z;
    updateCineUI();
  }

  function updateCineUI() {
    const nameEl = document.getElementById('cineName');
    const subEl = document.getElementById('cineSub');
    if (!nameEl) return;
    if (cineIdx === -1) {
      nameEl.textContent = '🌿 Overview';
      subEl.textContent = HIVES_ARRAY.length + ' sarang';
    } else {
      const h = HIVES_ARRAY[cineIdx];
      const det = h?.details || {};
      nameEl.textContent = h?.master_name || 'Sarang';
      const honey = (parseFloat(det.total_honey) || 0).toFixed(1);
      subEl.textContent = '🍯 ' + honey + ' ml • 🐝 ' + (det.bee_count || 0);
    }
  }

  window.cineNext = function() {
    if (HIVES_ARRAY.length === 0) return;
    if (cineIdx === -1) setCineHive(0);
    else if (cineIdx < HIVES_ARRAY.length - 1) setCineHive(cineIdx + 1);
    else setCineOverview();
  };
  window.cinePrev = function() {
    if (HIVES_ARRAY.length === 0) return;
    if (cineIdx === -1) setCineHive(HIVES_ARRAY.length - 1);
    else if (cineIdx > 0) setCineHive(cineIdx - 1);
    else setCineOverview();
  };
  window.cineZoomIn = function() { cineZoom = Math.max(-5, cineZoom - 1.5); if (cineIdx === -1) setCineOverview(); else setCineHive(cineIdx); };
  window.cineZoomOut = function() { cineZoom = Math.min(5, cineZoom + 1.5); if (cineIdx === -1) setCineOverview(); else setCineHive(cineIdx); };

  // Init camera
  setCineOverview();
  camCurrent.x = camTarget.x; camCurrent.y = camTarget.y; camCurrent.z = camTarget.z;
  camCurrent.lx = camTarget.lx; camCurrent.ly = camTarget.ly; camCurrent.lz = camTarget.lz;

  // Click on 3D hive
  const ray = new THREE.Raycaster(), mP = new THREE.Vector2();
  renderer.domElement.addEventListener('click', e => {
    const r = renderer.domElement.getBoundingClientRect();
    mP.x = ((e.clientX-r.left)/r.width)*2-1; mP.y = -((e.clientY-r.top)/r.height)*2+1;
    ray.setFromCamera(mP, camera);
    for (let i = 0; i < hive3D.length; i++) {
      if (ray.intersectObjects(hive3D[i].children, true).length > 0) {
        openHiveInspection(hive3D[i].userData.hiveId);
        hive3D[i].userData.bounceT = 0;
        // Also focus cam on this hive
        setCineHive(i);
        break;
      }
    }
  });

  // ── LABELS ──
  function updateLabels() {
    labelsC.innerHTML = '';
    hive3D.forEach((obj, i) => {
      const hive = HIVES_ARRAY[i]; if (!hive) return;
      const wp = hiveWP[i].clone(); wp.project(camera);
      if (wp.z > 1) return;
      const sx = (wp.x*container.clientWidth/2)+container.clientWidth/2;
      const sy = -(wp.y*container.clientHeight/2)+container.clientHeight/2;
      const det = hive.details || {};
      const honey = parseFloat(det.total_honey) || 0;
      const lbl = document.createElement('div');
      lbl.className = 'hive-3d-label'; lbl.style.left = sx+'px'; lbl.style.top = sy+'px';
      lbl.onclick = () => { openHiveInspection(hive.id); setCineHive(i); };
      lbl.innerHTML = '<div class="hive-label-bubble"><div class="hive-label-name">'+hive.master_name+'</div><div class="hive-label-honey"><i class="ph-fill ph-drop"></i> '+honey.toFixed(1)+' ml</div></div>';
      labelsC.appendChild(lbl);
    });
  }

  // ── ANIMATE ──
  const clock = new THREE.Clock();
  const lookTarget = new THREE.Vector3();

  function animate() {
    requestAnimationFrame(animate);
    const t = clock.getElapsedTime();

    // Smooth camera lerp
    const lerpSpeed = 0.03;
    camCurrent.x += (camTarget.x - camCurrent.x) * lerpSpeed;
    camCurrent.y += (camTarget.y - camCurrent.y) * lerpSpeed;
    camCurrent.z += (camTarget.z - camCurrent.z) * lerpSpeed;
    camCurrent.lx += (camTarget.lx - camCurrent.lx) * lerpSpeed;
    camCurrent.ly += (camTarget.ly - camCurrent.ly) * lerpSpeed;
    camCurrent.lz += (camTarget.lz - camCurrent.lz) * lerpSpeed;

    // Gentle cinematic sway
    const swayX = Math.sin(t * 0.2) * 0.3;
    const swayY = Math.cos(t * 0.15) * 0.15;

    camera.position.set(camCurrent.x + swayX, camCurrent.y + swayY, camCurrent.z);
    lookTarget.set(camCurrent.lx, camCurrent.ly, camCurrent.lz);
    camera.lookAt(lookTarget);

    // Bees
    bees.forEach(b => {
      const d = b.userData, tt = t*d.speed+d.phase;
      b.position.set(d.cx+Math.sin(tt)*d.radius, d.h+Math.sin(tt*2.5)*0.3, d.cz+Math.cos(tt)*d.radius);
      b.rotation.y = tt+Math.PI/2;
      d.wL.rotation.z = 0.3+Math.sin(t*35)*0.35; d.wR.rotation.z = -0.3-Math.sin(t*35)*0.35;
    });

    // Butterflies
    bflies.forEach(bf => {
      const d = bf.userData, tt = t*d.speed+d.phase;
      bf.position.set(d.cx+Math.sin(tt)*d.radius, d.h+Math.sin(tt*1.5)*0.4, d.cz+Math.cos(tt*0.8)*d.radius);
      bf.rotation.y = tt; d.wL.rotation.z = Math.sin(t*8)*0.6; d.wR.rotation.z = -Math.sin(t*8)*0.6;
    });

    // Clouds
    clouds.forEach(c => { c.position.x += c.userData.speed; if (c.position.x > 30) c.position.x = -30; });

    // Pollen
    const pp = pGeo.getAttribute('position');
    for (let i = 0; i < pCnt; i++) { let y = pp.getY(i)+0.004; if (y > 6) { y = 0.2; pp.setX(i, (Math.random()-0.5)*20); } pp.setY(i, y); }
    pp.needsUpdate = true;

    // Pond shimmer
    pond.material.opacity = 0.78 + Math.sin(t*1.5)*0.04;

    // Hive bounce + idle
    hive3D.forEach((obj, i) => {
      if (obj.userData.bounceT !== undefined) {
        obj.userData.bounceT += 0.08;
        if (obj.userData.bounceT < Math.PI) obj.position.y = Math.sin(obj.userData.bounceT)*0.25;
        else { obj.position.y = 0; delete obj.userData.bounceT; }
      } else { obj.rotation.y = Math.sin(t*0.4+i)*0.015; }
    });

    renderer.render(scene, camera);
    updateLabels();
  }

  animate();
  setTimeout(() => loadingEl.classList.add('hidden'), 700);
  window.addEventListener('resize', () => { camera.aspect = container.clientWidth/container.clientHeight; camera.updateProjectionMatrix(); renderer.setSize(container.clientWidth, container.clientHeight); });
})();

// ── INTERACTION LOGIC ──
function renderHexagonCells(fp, gid) {
  const tc=24, hc=Math.min(tc, Math.round(tc*(fp/100))), g=document.getElementById(gid||'inspectionHexGrid');
  if(!g) return; g.innerHTML=''; let ci=0;
  [6,5,6,5,2].forEach((cc,ri) => { const r=document.createElement('div'); r.className='hex-row'+(ri%2===1?' hex-row--offset':''); for(let c=0;c<cc;c++){const cl=document.createElement('div'); cl.className='hex-cell '+(ci<hc?'hex-cell--honey':'hex-cell--empty'); r.appendChild(cl); ci++;} g.appendChild(r); });
}
function openHiveInspection(hiveId) {
  currentInspectedHiveId = hiveId; const h = USER_HIVES_MAP[hiveId]; if(!h) return;
  FarmAudio.playPop(); FarmAudio.playBee(0.5);
  const d=h.details||{}, th=parseFloat(d.total_honey)||0, mc=parseFloat(d.max_capacity)||100, pct=parseFloat(d.fill_percentage)||0;
  document.getElementById('inspectionHiveName').innerHTML='<i class="ph-fill ph-hexagon" style="color:#fbbf24;"></i> '+(h.master_name||'Sarang');
  document.getElementById('inspectionHoneyAmt').innerText=th.toFixed(2)+' ml';
  document.getElementById('inspectionHoneyCap').innerText='Kapasitas: '+th.toFixed(1)+' / '+mc.toFixed(0)+' ml ('+pct+'%)';
  document.getElementById('inspectionBeeInfo').innerHTML='<i class="ph-fill ph-bug-beetle"></i><span>'+(parseInt(d.bee_count)||0)+'/'+(parseInt(h.max_slots)||0)+' Lebah • +'+(parseFloat(d.hourly_production)||0).toFixed(1)+' ml/jam</span>';
  document.getElementById('btnInspectionHarvest').disabled = th<0.1;
  renderHexagonCells(pct,'inspectionHexGrid');
  document.getElementById('hiveInspectionOverlay').classList.add('active');
  document.body.style.overflow='hidden';
}
function closeHiveInspection() { document.getElementById('hiveInspectionOverlay').classList.remove('active'); document.body.style.overflow=''; currentInspectedHiveId=0; }
document.addEventListener('keydown', e => { if(e.key==='Escape') closeHiveInspection(); });
function spawnHoneyFly(t,el) { const r=el?el.getBoundingClientRect():{top:innerHeight/2,left:innerWidth/2}; const b=document.createElement('div'); b.className='floating-honey-fly'; b.innerText='+ '+t+' ml'; b.style.top=(r.top+10)+'px'; b.style.left=(r.left+20)+'px'; document.body.appendChild(b); setTimeout(()=>b.remove(),1200); }
function harvestInspectedHive() {
  if(!currentInspectedHiveId) return; const btn=document.getElementById('btnInspectionHarvest'); if(!btn||btn.disabled) return;
  btn.disabled=true; const ot=btn.innerHTML; btn.innerHTML='<i class="ph-bold ph-spinner ph-spin"></i> Memanen...'; FarmAudio.playHarvest();
  const fd=new FormData(); fd.append('action','harvest_hive'); fd.append('hive_id',currentInspectedHiveId); fd.append('_csrf',document.querySelector('input[name="_csrf"]')?.value||'');
  fetch('/api/farm_action',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{
    if(d.ok){ spawnHoneyFly(d.harvested_ml,btn); const hs=document.getElementById('hudHoneyStock'); if(hs&&d.new_honey_stock!==undefined)hs.innerText=Number(d.new_honey_stock).toLocaleString('id-ID',{minimumFractionDigits:1})+' ml'; document.getElementById('inspectionHoneyAmt').innerText='0.00 ml'; renderHexagonCells(0,'inspectionHexGrid'); if(USER_HIVES_MAP[currentInspectedHiveId]?.details){USER_HIVES_MAP[currentInspectedHiveId].details.total_honey=0;USER_HIVES_MAP[currentInspectedHiveId].details.fill_percentage=0;} btn.innerHTML='<i class="ph-bold ph-check"></i> Dipanen!'; setTimeout(()=>{btn.innerHTML=ot;btn.disabled=true;},1500); }
    else{ alert(d.msg||'Gagal'); btn.disabled=false; btn.innerHTML=ot; }
  }).catch(()=>{btn.disabled=false;btn.innerHTML=ot;alert('Error jaringan.');});
}
function harvestAllHives() {
  const btn=document.getElementById('btnHarvestAll'); if(!btn||btn.disabled) return;
  btn.disabled=true; const ot=btn.innerHTML; btn.innerHTML='<i class="ph-bold ph-spinner ph-spin"></i> Memanen...'; FarmAudio.playHarvest();
  const fd=new FormData(); fd.append('action','harvest_all'); fd.append('_csrf',document.querySelector('input[name="_csrf"]')?.value||'');
  fetch('/api/farm_action',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{
    if(d.ok){ spawnHoneyFly(d.harvested_ml,btn); const hs=document.getElementById('hudHoneyStock'); if(hs&&d.new_honey_stock!==undefined)hs.innerText=Number(d.new_honey_stock).toLocaleString('id-ID',{minimumFractionDigits:1})+' ml'; const tb=document.getElementById('totalUnharvestedBadge'); if(tb)tb.innerText='0.0 ml'; btn.innerHTML='<i class="ph-bold ph-check"></i> '+(d.msg||'Berhasil!'); setTimeout(()=>{btn.innerHTML=ot;btn.disabled=true;},2000); }
    else{ alert(d.msg||'Gagal'); btn.disabled=false; btn.innerHTML=ot; }
  }).catch(()=>{btn.disabled=false;btn.innerHTML=ot;alert('Error jaringan.');});
}
</script>
<?php require dirname(__DIR__) . '/partials/footer.php'; ?>
