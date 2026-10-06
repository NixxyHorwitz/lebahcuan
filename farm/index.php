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

$pageTitle = 'Kebun Sarang Lebah Cuan';
$activePage = 'farm';
$farmSubPage = 'meadow';
require dirname(__DIR__) . '/partials/header.php';
?>

<style>
body { background: #071a0c !important; font-family: 'Nunito', sans-serif; overflow-x: hidden; }

#farm3dCanvas {
  position: relative; width: 100%; 
  height: 32vh; min-height: 220px; max-height: 280px;
  border-bottom: 3px solid #1a5c2e; overflow: hidden;
  touch-action: pan-y;
  -webkit-tap-highlight-color: transparent;
}
@media (min-width: 640px) {
  #farm3dCanvas {
    height: 42vh; min-height: 280px; max-height: 380px;
  }
}
#farm3dCanvas canvas { 
  display: block; width: 100% !important; height: 100% !important; 
  touch-action: pan-y; 
  -webkit-tap-highlight-color: transparent;
}

/* Loading */
.farm3d-loading {
  position: absolute; top: 0; left: 0; right: 0; bottom: 0;
  background: linear-gradient(180deg, #1a3d1f 0%, #071a0c 100%);
  display: flex; flex-direction: column; align-items: center; justify-content: center;
  z-index: 20; transition: opacity 0.4s;
  pointer-events: none;
}
.farm3d-loading.hidden { opacity: 0; pointer-events: none !important; display: none !important; }
.farm3d-loading-spinner { width: 38px; height: 38px; border: 3px solid rgba(251,191,36,0.15); border-top: 3px solid #fbbf24; border-radius: 50%; animation: spin3d 0.8s linear infinite; }
@keyframes spin3d { to { transform: rotate(360deg); } }
.farm3d-loading-text { margin-top: 8px; font-size: 11px; font-weight: 800; color: #fbbf24; }

/* Cinematic Nav */
.cinema-nav {
  position: absolute; bottom: 8px; left: 50%; transform: translateX(-50%);
  display: flex; align-items: center; gap: 5px; z-index: 18;
}
.cinema-btn {
  width: 26px; height: 26px; border-radius: 50%;
  background: rgba(0,0,0,0.65); backdrop-filter: blur(8px); -webkit-backdrop-filter: blur(8px);
  border: 1.5px solid rgba(255,255,255,0.25);
  color: #fbbf24; font-size: 12px; cursor: pointer;
  display: flex; align-items: center; justify-content: center;
  touch-action: manipulation; -webkit-tap-highlight-color: transparent;
  transition: transform 0.1s;
}
.cinema-btn:hover { background: rgba(251,191,36,0.25); border-color: #fbbf24; }
.cinema-btn:active { transform: scale(0.92); }
.cinema-btn:disabled { opacity: 0.3; cursor: not-allowed; }
.cinema-indicator {
  background: rgba(0,0,0,0.65); backdrop-filter: blur(8px); -webkit-backdrop-filter: blur(8px);
  border: 1.5px solid rgba(255,255,255,0.2); border-radius: 8px;
  padding: 2px 7px; text-align: center; min-width: 70px;
}
.cinema-indicator-name { font-size: 8.5px; font-weight: 900; color: #fbbf24; }
.cinema-indicator-sub { font-size: 7.5px; font-weight: 700; color: rgba(255,255,255,0.7); }

/* Zoom buttons */
.cinema-zoom {
  position: absolute; bottom: 8px; right: 6px; z-index: 18;
  display: flex; flex-direction: column; gap: 3px;
}
.cinema-zoom-btn {
  width: 24px; height: 24px; border-radius: 6px;
  background: rgba(0,0,0,0.6); backdrop-filter: blur(6px); -webkit-backdrop-filter: blur(6px);
  border: 1.5px solid rgba(255,255,255,0.2);
  color: rgba(255,255,255,0.9); font-size: 12px; font-weight: 900;
  cursor: pointer; display: flex; align-items: center; justify-content: center;
  touch-action: manipulation; -webkit-tap-highlight-color: transparent;
}
.cinema-zoom-btn:active { transform: scale(0.92); }

/* Ambient toggle */
.ambient-sound-toggle {
  position: absolute; top: 6px; right: 6px; z-index: 18;
  background: rgba(0,0,0,0.6); backdrop-filter: blur(6px); -webkit-backdrop-filter: blur(6px);
  border: 1.5px solid rgba(255,255,255,0.2); border-radius: 8px;
  padding: 2.5px 6px; font-size: 8.5px; font-weight: 800; color: #fbbf24;
  cursor: pointer; display: flex; align-items: center; gap: 3px;
  touch-action: manipulation; -webkit-tap-highlight-color: transparent;
}

/* Labels */
#hiveLabelsContainer { position: absolute; top: 0; left: 0; width: 100%; height: 100%; pointer-events: none; z-index: 14; }
.hive-3d-label {
  position: absolute; pointer-events: auto; cursor: pointer;
  top: 0; left: 0; will-change: transform;
  touch-action: manipulation; -webkit-tap-highlight-color: transparent;
}
.hive-label-bubble {
  transform: translate(-50%, -100%);
  background: linear-gradient(135deg, rgba(251,191,36,0.92), rgba(217,119,6,0.92));
  border: 1.5px solid #78350f; border-radius: 9px;
  padding: 2.5px 7px; box-shadow: 0 2px 0 #78350f, 0 3px 8px rgba(0,0,0,0.35);
  text-align: center; white-space: nowrap; position: relative;
}
.hive-label-name { font-size: 8px; font-weight: 800; color: #fff; text-shadow: 0 1px 1px rgba(0,0,0,0.5); }
.hive-label-honey { font-size: 7px; font-weight: 700; color: #fef3c7; }
.hive-label-bubble::after {
  content: ''; position: absolute; bottom: -7px; left: 50%; transform: translateX(-50%);
  border-left: 6px solid transparent; border-right: 6px solid transparent; border-top: 7px solid #78350f;
}

/* Ground UI - Ultra Compact & Ergonomic */
.farm-ground-ui { background: linear-gradient(180deg, #0f2a16 0%, #071a0c 100%); padding: 8px 8px 105px; }
.ground-section-title { display: flex; align-items: center; gap: 5px; margin-bottom: 6px; }
.ground-section-title .pill { background: rgba(255,255,255,0.06); border: 1.2px solid rgba(251,191,36,0.25); border-radius: 8px; padding: 2px 7px; font-size: 9.5px; font-weight: 800; color: #fbbf24; }
.hive-quick-list { display: flex; flex-direction: column; gap: 5px; }
.hive-quick-card { 
  background: rgba(255,255,255,0.035); 
  border: 1.2px solid rgba(255,255,255,0.07); 
  border-radius: 10px; padding: 5px 8px; 
  display: flex; align-items: center; gap: 8px; 
  cursor: pointer; 
  touch-action: manipulation; -webkit-tap-highlight-color: transparent;
  transition: all 0.15s; 
}
.hive-quick-card:hover { background: rgba(251,191,36,0.08); border-color: rgba(251,191,36,0.3); transform: translateX(2px); }
.hive-quick-card img { width: 32px; height: 32px; object-fit: contain; border-radius: 7px; background: rgba(255,255,255,0.05); padding: 2px; flex-shrink: 0; }
.hive-quick-info { flex: 1; min-width: 0; }
.hive-quick-name { font-size: 11px; font-weight: 800; color: #f8fafc; }
.hive-quick-meta { font-size: 8.5px; font-weight: 700; color: #94a3b8; margin-top: 1px; }
.hive-quick-honey { font-size: 11.5px; font-weight: 900; color: #fbbf24; text-align: right; flex-shrink: 0; }
.hive-quick-honey small { font-size: 8px; color: #94a3b8; display: block; font-weight: 700; }

/* Mini Bee Chips in Hive Quick Card */
.hive-bees-chips {
  display: flex;
  flex-wrap: wrap;
  gap: 3px;
  margin-top: 3px;
}
.mini-bee-chip {
  display: inline-flex;
  align-items: center;
  gap: 3px;
  background: rgba(251, 191, 36, 0.12);
  border: 1px solid rgba(251, 191, 36, 0.3);
  border-radius: 6px;
  padding: 1px 5px;
  font-size: 8px;
  font-weight: 800;
  color: #fde68a;
  line-height: 1.2;
}
.mini-bee-chip img {
  width: 12px !important;
  height: 12px !important;
  padding: 0 !important;
  background: transparent !important;
  border-radius: 0 !important;
  object-fit: contain !important;
}
.mini-bee-empty-tag {
  display: inline-flex;
  font-size: 7.5px;
  font-weight: 800;
  color: #f87171;
  background: rgba(239, 68, 68, 0.12);
  border: 1px solid rgba(239, 68, 68, 0.25);
  border-radius: 5px;
  padding: 1px 4px;
}
.farm-empty-cta { text-align: center; padding: 16px 12px; background: rgba(255,255,255,0.03); border: 1.5px dashed rgba(251,191,36,0.2); border-radius: 12px; }
.farm-empty-cta .emoji { font-size: 28px; margin-bottom: 4px; }
.farm-empty-cta .title { font-size: 12.5px; font-weight: 900; color: #f8fafc; }
.farm-empty-cta .sub { font-size: 9.5px; color: #94a3b8; margin-top: 2px; }
.farm-empty-cta .btn-cta { margin-top: 6px; display: inline-flex; align-items: center; gap: 4px; background: linear-gradient(135deg, #f59e0b, #d97706); border: 1.5px solid #78350f; border-radius: 8px; padding: 5px 12px; color: #fff; font-size: 10.5px; font-weight: 900; text-decoration: none; box-shadow: 0 2px 0 #78350f; touch-action: manipulation; }

.meadow-sticky-bar {
  position: fixed;
  bottom: 74px;
  left: 50%;
  transform: translateX(-50%);
  width: calc(100% - 16px);
  max-width: 440px;
  z-index: 50;
  pointer-events: none;
}
.btn-harvest-all {
  pointer-events: auto;
  width: 100%;
  background: linear-gradient(135deg, #fbbf24 0%, #f59e0b 45%, #d97706 100%);
  border: 1.5px solid #78350f;
  border-radius: 12px;
  box-shadow: 0 2.5px 0 #78350f, 0 4px 12px rgba(180,83,9,0.3);
  padding: 7px 12px;
  color: #fff;
  font-size: 12px;
  font-weight: 900;
  display: flex;
  align-items: center;
  justify-content: space-between;
  cursor: pointer;
  touch-action: manipulation; -webkit-tap-highlight-color: transparent;
  font-family: 'Nunito', sans-serif;
  text-shadow: 0 1px 0 #78350f;
  transition: transform 0.1s;
}
.btn-harvest-all:active {
  transform: translateY(1.5px);
  box-shadow: 0 1px 0 #78350f;
}
.btn-harvest-all:disabled {
  background: #cbd5e1;
  border-color: #64748b;
  color: #475569;
  text-shadow: none;
  box-shadow: none;
  cursor: not-allowed;
}

/* Floating contact button on Farm Meadow page - positioned cleanly above harvest bar */
body .float-contact-wrap {
  bottom: 130px !important;
}

/* Inspection Modal - CRITICAL FIX: display:none & pointer-events:none when inactive */
.hive-inspection-overlay { 
  position: fixed; top: 0; left: 0; right: 0; bottom: 0; z-index: 9999; 
  display: none !important; 
  pointer-events: none !important;
  align-items: center; justify-content: center; 
  opacity: 0; visibility: hidden; 
  transition: opacity 0.3s ease; 
}
.hive-inspection-overlay.active { 
  display: flex !important; 
  pointer-events: auto !important;
  opacity: 1; visibility: visible; 
}
.hive-inspection-vignette { 
  position: absolute; top: 0; left: 0; right: 0; bottom: 0; z-index: 1; 
  background: radial-gradient(circle at center, rgba(45,20,5,0.82) 0%, rgba(30,12,3,0.92) 35%, rgba(15,6,1,0.97) 65%, rgba(5,2,0,0.99) 100%); 
  backdrop-filter: blur(8px); -webkit-backdrop-filter: blur(8px);
}
.hive-inspection-close { position: absolute; top: 18px; right: 18px; width: 44px; height: 44px; background: rgba(255,255,255,0.15); border: 2px solid rgba(255,255,255,0.3); border-radius: 50%; color: #fef3c7; font-size: 22px; display: flex; align-items: center; justify-content: center; cursor: pointer; z-index: 100; }
.hive-inspection-title { position: absolute; top: 22px; left: 0; right: 0; text-align: center; z-index: 50; }
.hive-inspection-title span { display: inline-flex; align-items: center; gap: 8px; background: rgba(120,53,15,0.6); border: 2px solid rgba(251,191,36,0.4); border-radius: 16px; padding: 6px 16px; font-size: 14px; font-weight: 900; color: #fde68a; backdrop-filter: blur(4px); }
.hive-inspection-frame { 
  position: relative; 
  z-index: 50; 
  width: 325px; 
  max-width: 90vw; 
  max-height: 86vh;
  overflow-y: auto;
  overflow-x: hidden;
  padding-bottom: 15px;
  animation: frameSlideIn 0.4s cubic-bezier(0.34,1.56,0.64,1) forwards; 
}
.hive-inspection-frame::-webkit-scrollbar {
  width: 4px;
}
.hive-inspection-frame::-webkit-scrollbar-thumb {
  background: rgba(251,191,36,0.3);
  border-radius: 4px;
}

/* Inspection Modal Bee Roster Panel */
.inspection-bees-panel {
  margin-top: 14px;
  background: rgba(15, 6, 2, 0.75);
  border: 1.5px solid rgba(251, 191, 36, 0.35);
  border-radius: 16px;
  padding: 10px;
  text-align: left;
  box-shadow: inset 0 2px 6px rgba(0,0,0,0.5);
}
.inspection-bees-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 8px;
  padding-bottom: 6px;
  border-bottom: 1px solid rgba(255, 255, 255, 0.1);
}
.ibh-title {
  font-size: 11.5px;
  font-weight: 900;
  color: #fde68a;
  display: flex;
  align-items: center;
  gap: 5px;
}
.ibh-speed {
  font-size: 9px;
  font-weight: 900;
  color: #34d399;
  background: rgba(16, 185, 129, 0.15);
  border: 1px solid rgba(16, 185, 129, 0.3);
  border-radius: 6px;
  padding: 1.5px 6px;
}
.inspection-bees-list {
  display: flex;
  flex-direction: column;
  gap: 6px;
  max-height: 200px;
  overflow-y: auto;
  padding-right: 2px;
}
.inspection-bees-list::-webkit-scrollbar {
  width: 4px;
}
.inspection-bees-list::-webkit-scrollbar-thumb {
  background: rgba(251, 191, 36, 0.35);
  border-radius: 4px;
}

/* Bee Resident Card */
.bee-resident-card {
  background: rgba(255, 255, 255, 0.05);
  border: 1px solid rgba(251, 191, 36, 0.22);
  border-radius: 10px;
  padding: 6px 8px;
  display: flex;
  align-items: center;
  gap: 8px;
  transition: background 0.15s;
}
.bee-resident-card:hover {
  background: rgba(251, 191, 36, 0.08);
}
.brc-avatar-wrap {
  width: 36px;
  height: 36px;
  background: rgba(0, 0, 0, 0.4);
  border: 1px solid rgba(251, 191, 36, 0.35);
  border-radius: 8px;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}
.brc-avatar {
  width: 26px;
  height: 26px;
  object-fit: contain;
  filter: drop-shadow(0 2px 4px rgba(0,0,0,0.5));
}
.brc-details {
  flex: 1;
  min-width: 0;
}
.brc-name-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 4px;
  margin-bottom: 2px;
}
.brc-name {
  font-size: 11px;
  font-weight: 800;
  color: #ffffff;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.brc-rate {
  font-size: 9.5px;
  font-weight: 900;
  color: #34d399;
  flex-shrink: 0;
}
.brc-meta-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  font-size: 9px;
  color: #94a3b8;
  font-weight: 700;
}

/* Empty Bee Slot Card */
.bee-slot-empty-card {
  background: rgba(255, 255, 255, 0.02);
  border: 1px dashed rgba(255, 255, 255, 0.2);
  border-radius: 10px;
  padding: 6px 8px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 6px;
}
.bsec-left {
  display: flex;
  align-items: center;
  gap: 6px;
}
.bsec-icon {
  width: 26px;
  height: 26px;
  border-radius: 6px;
  background: rgba(255, 255, 255, 0.05);
  display: flex;
  align-items: center;
  justify-content: center;
  color: #64748b;
  font-size: 14px;
  flex-shrink: 0;
}
.bsec-title {
  font-size: 9.5px;
  font-weight: 800;
  color: #94a3b8;
}
.bsec-desc {
  font-size: 8px;
  color: #64748b;
}
.bsec-btn {
  background: linear-gradient(135deg, #f59e0b, #d97706);
  border: 1px solid #78350f;
  border-radius: 7px;
  padding: 3px 8px;
  font-size: 9px;
  font-weight: 900;
  color: #ffffff;
  text-decoration: none;
  display: inline-flex;
  align-items: center;
  gap: 3px;
  box-shadow: 0 1.5px 0 #78350f;
  flex-shrink: 0;
}
.bee-empty-all-card {
  padding: 12px 10px;
  text-align: center;
  display: flex;
  flex-direction: column;
  align-items: center;
}
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

/* Beekeeper Harvest Celebration Banner */
.harvest-celebration-banner {
  background: linear-gradient(135deg, rgba(251,191,36,0.2) 0%, rgba(217,119,6,0.32) 100%);
  border: 2px solid #fbbf24;
  border-radius: 16px;
  padding: 10px 14px;
  margin-top: 14px;
  display: flex;
  align-items: center;
  gap: 12px;
  animation: bannerPop 0.4s cubic-bezier(0.34, 1.56, 0.64, 1);
  text-align: left;
}
@keyframes bannerPop {
  0% { transform: scale(0.85); opacity: 0; }
  100% { transform: scale(1); opacity: 1; }
}
.harvest-celebration-banner img {
  width: 42px; height: 42px; object-fit: contain;
  filter: drop-shadow(0 2px 6px rgba(0,0,0,0.4));
  animation: keeperBounce 0.6s infinite alternate ease-in-out;
}
@keyframes keeperBounce {
  from { transform: translateY(0); }
  to { transform: translateY(-4px); }
}
.harvest-celebration-text .h-title {
  font-size: 13px; font-weight: 900; color: #fef08a; line-height: 1.2;
}
.harvest-celebration-text .h-sub {
  font-size: 10.5px; font-weight: 700; color: #fde68a; margin-top: 2px;
}

/* 3D In-World Harvest Progress Bar */
.harvest-progress-3d {
  position: absolute;
  pointer-events: none;
  z-index: 60;
  display: none;
  flex-direction: column;
  align-items: center;
  transform: translate(-50%, -100%);
}
.harvest-progress-3d.active { display: flex; }
.harvest-progress-label {
  font-size: 10px;
  font-weight: 900;
  color: #fef3c7;
  text-shadow: 0 1px 4px rgba(0,0,0,0.8);
  margin-bottom: 3px;
  letter-spacing: 0.5px;
}
.harvest-progress-bar-outer {
  width: 90px;
  height: 10px;
  background: rgba(69,26,3,0.8);
  border: 2px solid rgba(251,191,36,0.5);
  border-radius: 6px;
  overflow: hidden;
  box-shadow: 0 0 8px rgba(251,191,36,0.3);
}
.harvest-progress-bar-inner {
  height: 100%;
  width: 0%;
  background: linear-gradient(90deg, #f59e0b, #fbbf24, #fde047);
  border-radius: 4px;
  transition: width 0.08s linear;
  box-shadow: inset 0 1px 2px rgba(255,255,255,0.4);
}
.harvest-progress-pct {
  font-size: 9px;
  font-weight: 900;
  color: #fbbf24;
  text-shadow: 0 1px 3px rgba(0,0,0,0.9);
  margin-top: 2px;
}
</style>

<?php require __DIR__ . '/partials/farm_header.php'; ?>

<div id="farm3dCanvas">
  <div class="farm3d-loading" id="farm3dLoading">
    <div class="farm3d-loading-spinner"></div>
    <div class="farm3d-loading-text">Memuat Kebun Lebah...</div>
  </div>
  <button class="ambient-sound-toggle" id="btnAmbientSound" onclick="toggleAmbientSound()">
    <i class="ph-fill ph-speaker-high"></i> Ambient
  </button>

  <!-- Cinematic Camera Nav -->
  <?php if (count($user_hives) > 0): ?>
  <div class="cinema-nav">
    <button class="cinema-btn" id="btnCinePrev" onclick="cinePrev()"><i class="ph-bold ph-caret-left"></i></button>
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
      <div class="sub">Beli sarang pertamamu dan mulai beternak lebah</div>
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
            
            <!-- DAFTAR MINI SPESIES LEBAH DI KANDANG INI -->
            <div class="hive-bees-chips">
              <?php if (!empty($det['bees'])): ?>
                <?php foreach ($det['bees'] as $b): ?>
                  <span class="mini-bee-chip" title="<?= htmlspecialchars($b['type_name']) ?> (+<?= number_format((float)($b['effective_rate'] ?? $b['honey_per_hour']), 1) ?> ml/jam)">
                    <img src="<?= htmlspecialchars(!empty($b['type_image']) ? $b['type_image'] : '/assets/game/bee_worker.png') ?>" alt="">
                    <span><?= htmlspecialchars($b['type_name']) ?></span>
                  </span>
                <?php endforeach; ?>
              <?php else: ?>
                <span class="mini-bee-empty-tag">⚠️ Belum ada lebah pekerja</span>
              <?php endif; ?>
            </div>
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

    <!-- DAFTAR PENGHUNI LEBAH DI KANDANG INI -->
    <div class="inspection-bees-panel">
      <div class="inspection-bees-head">
        <span class="ibh-title"><i class="ph-fill ph-bug-beetle" style="color:#fbbf24;"></i> Penghuni Sarang (<span id="inspectionBeeCountBadge">0/0</span>)</span>
        <span class="ibh-speed" id="inspectionSpeedBonusBadge">+0% Speed</span>
      </div>
      <div class="inspection-bees-list" id="inspectionBeesList">
        <!-- Rendered dynamically by renderInspectionBees() -->
      </div>
    </div>
  </div>
</div>

<?= csrf_field() ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/three.js/r128/three.min.js"></script>
<script>
const USER_HIVES_MAP = <?= json_encode(array_column($user_hives, null, 'id')) ?>;
const HIVES_ARRAY = <?= json_encode(array_values($user_hives)) ?>;
let currentInspectedHiveId = 0;

// ── INTERACTION LOGIC (DEFINED EARLY & GLOBALLY FOR INSTANT TOUCH/CLICK RESPONSE) ──
function renderHexagonCells(fp, gid) {
  const tc=24, hc=Math.min(tc, Math.round(tc*(fp/100))), g=document.getElementById(gid||'inspectionHexGrid');
  if(!g) return; g.innerHTML=''; let ci=0;
  [6,5,6,5,2].forEach((cc,ri) => { 
    const r=document.createElement('div'); 
    r.className='hex-row'+(ri%2===1?' hex-row--offset':''); 
    for(let c=0;c<cc;c++){
      const cl=document.createElement('div'); 
      cl.className='hex-cell '+(ci<hc?'hex-cell--honey':'hex-cell--empty'); 
      r.appendChild(cl); 
      ci++;
    } 
    g.appendChild(r); 
  });
}
function getDaysRemaining(expiresAtStr) {
  if (!expiresAtStr) return 'Permanen';
  const exp = new Date(expiresAtStr.replace(/-/g, '/')).getTime();
  const diff = exp - Date.now();
  if (diff <= 0) return 'Kedaluwarsa';
  const days = Math.ceil(diff / (1000 * 60 * 60 * 24));
  return 'Sisa ' + days + ' hari';
}

function renderInspectionBees(h, d) {
  const beesListEl = document.getElementById('inspectionBeesList');
  const countBadge = document.getElementById('inspectionBeeCountBadge');
  const speedBadge = document.getElementById('inspectionSpeedBonusBadge');
  if (!beesListEl) return;

  const bees = d.bees || [];
  const maxSlots = parseInt(h.max_slots) || 2;
  const speedBonus = parseInt(h.bonus_speed_pct) || 0;

  if (countBadge) countBadge.innerText = bees.length + '/' + maxSlots;
  if (speedBadge) {
    if (speedBonus > 0) {
      speedBadge.innerText = '+' + speedBonus + '% Speed Sarang';
      speedBadge.style.display = 'inline-block';
    } else {
      speedBadge.style.display = 'none';
    }
  }

  let html = '';
  if (bees.length === 0) {
    html += `
      <div class="bee-empty-all-card">
        <div style="font-size:24px;margin-bottom:2px;">🐝</div>
        <div style="font-weight:900;color:#fde68a;font-size:11.5px;">Sarang Ini Belum Memiliki Lebah!</div>
        <div style="font-size:9.5px;color:#cbd5e1;margin-bottom:8px;">Beli lebah pekerja di toko untuk mulai memproduksi madu di sarang ini.</div>
        <a href="/farm/shop?tab=bees" class="bsec-btn"><i class="ph-bold ph-storefront"></i> Beli Lebah ke Toko</a>
      </div>
    `;
  } else {
    bees.forEach((b) => {
      const img = b.type_image || '/assets/game/bee_worker.png';
      const rate = parseFloat(b.effective_rate || b.honey_per_hour || 0).toFixed(1);
      const accHoney = parseFloat(b.accumulated_honey || 0).toFixed(2);
      const remDays = getDaysRemaining(b.expires_at);
      const isExp = remDays.includes('Kedaluwarsa');

      html += `
        <div class="bee-resident-card">
          <div class="brc-avatar-wrap">
            <img src="${img}" class="brc-avatar" alt="${b.type_name || 'Lebah'}">
          </div>
          <div class="brc-details">
            <div class="brc-name-row">
              <span class="brc-name" title="${b.type_name || 'Lebah'}">${b.type_name || 'Lebah'}</span>
              <span class="brc-rate">+${rate} ml/j</span>
            </div>
            <div class="brc-meta-row">
              <span><i class="ph-fill ph-drop" style="color:#fbbf24;"></i> Hasil: <strong>${accHoney} ml</strong></span>
              <span style="color:${isExp ? '#ef4444' : '#94a3b8'};">${remDays}</span>
            </div>
          </div>
        </div>
      `;
    });

    // Render empty slots if any
    const emptySlots = maxSlots - bees.length;
    for (let i = 0; i < emptySlots; i++) {
      html += `
        <div class="bee-slot-empty-card">
          <div class="bsec-left">
            <div class="bsec-icon"><i class="ph-bold ph-plus"></i></div>
            <div class="bsec-info">
              <div class="bsec-title">Slot Kosong (${bees.length + i + 1}/${maxSlots})</div>
              <div class="bsec-desc">Kandang siap menampung 1 lebah lagi</div>
            </div>
          </div>
          <a href="/farm/shop?tab=bees" class="bsec-btn"><i class="ph-bold ph-plus"></i> Beli</a>
        </div>
      `;
    }
  }

  beesListEl.innerHTML = html;
}

function openHiveInspection(hiveId) {
  currentInspectedHiveId = hiveId; 
  const h = USER_HIVES_MAP[hiveId]; 
  if(!h) return;
  if (typeof FarmAudio !== 'undefined') {
    try { FarmAudio.playPop(); FarmAudio.playBee(0.5); } catch(e) {}
  }
  const d=h.details||{}, th=parseFloat(d.total_honey)||0, mc=parseFloat(d.max_capacity)||100, pct=parseFloat(d.fill_percentage)||0;
  const nameEl = document.getElementById('inspectionHiveName');
  if (nameEl) nameEl.innerHTML='<i class="ph-fill ph-hexagon" style="color:#fbbf24;"></i> '+(h.master_name||'Sarang');
  const amtEl = document.getElementById('inspectionHoneyAmt');
  if (amtEl) amtEl.innerText=th.toFixed(2)+' ml';
  const capEl = document.getElementById('inspectionHoneyCap');
  if (capEl) capEl.innerText='Kapasitas: '+th.toFixed(1)+' / '+mc.toFixed(0)+' ml ('+pct+'%)';
  const beeEl = document.getElementById('inspectionBeeInfo');
  if (beeEl) beeEl.innerHTML='<i class="ph-fill ph-bug-beetle"></i><span>'+(parseInt(d.bee_count)||0)+'/'+(parseInt(h.max_slots)||0)+' Lebah • +'+(parseFloat(d.hourly_production)||0).toFixed(1)+' ml/jam</span>';
  const btnH = document.getElementById('btnInspectionHarvest');
  if (btnH) btnH.disabled = th<0.1;
  renderHexagonCells(pct,'inspectionHexGrid');
  renderInspectionBees(h, d);
  const overlay = document.getElementById('hiveInspectionOverlay');
  if (overlay) overlay.classList.add('active');
  document.body.style.overflow='hidden';
}
function closeHiveInspection() { 
  const overlay = document.getElementById('hiveInspectionOverlay');
  if (overlay) overlay.classList.remove('active'); 
  document.body.style.overflow=''; 
  currentInspectedHiveId=0; 
}
document.addEventListener('keydown', e => { if(e.key==='Escape') closeHiveInspection(); });

function spawnHoneyFly(t,el) { 
  const r=el?el.getBoundingClientRect():{top:innerHeight/2,left:innerWidth/2}; 
  const b=document.createElement('div'); 
  b.className='floating-honey-fly'; 
  b.innerText='+ '+t+' ml'; 
  b.style.top=(r.top+10)+'px'; 
  b.style.left=(r.left+20)+'px'; 
  document.body.appendChild(b); 
  setTimeout(()=>b.remove(),1200); 
}

function harvestInspectedHive() {
  if(!currentInspectedHiveId) return; 
  const btn=document.getElementById('btnInspectionHarvest'); 
  if(!btn||btn.disabled) return;
  btn.disabled=true;
  const harvestingHiveId = currentInspectedHiveId;
  const targetIdx = HIVES_ARRAY.findIndex(h => h.id == harvestingHiveId);
  closeHiveInspection();
  if (typeof triggerBeekeeperHarvest === 'function') {
    try { triggerBeekeeperHarvest(targetIdx >= 0 ? targetIdx : 0, 10); } catch(e) {}
  }
  const fd=new FormData(); 
  fd.append('action','harvest_hive'); 
  fd.append('hive_id',harvestingHiveId); 
  fd.append('_csrf',document.querySelector('input[name="_csrf"]')?.value||'');
  fetch('/api/farm_action',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{
    if(d.ok){
      const hs=document.getElementById('hudHoneyStock');
      if(hs&&d.new_honey_stock!==undefined)hs.innerText=Number(d.new_honey_stock).toLocaleString('id-ID',{minimumFractionDigits:1})+' ml';
      if(USER_HIVES_MAP[harvestingHiveId]?.details){
        USER_HIVES_MAP[harvestingHiveId].details.total_honey=0;
        USER_HIVES_MAP[harvestingHiveId].details.fill_percentage=0;
      }
      const labelHoney = document.getElementById('hiveLabelHoney_' + harvestingHiveId);
      if (labelHoney) labelHoney.innerHTML = '<i class="ph-fill ph-drop"></i> 0.0 ml';
      spawnHoneyFly(d.harvested_ml, document.getElementById('btnHarvestAll') || document.body);
    } else { 
      if(typeof nToast==='function') nToast(d.msg||'Gagal memanen','error'); 
    }
  }).catch(()=>{if(typeof nToast==='function') nToast('Error jaringan, coba lagi.','error');});
}

function harvestAllHives() {
  const btn=document.getElementById('btnHarvestAll'); 
  if(!btn||btn.disabled) return;
  btn.disabled=true; 
  const ot=btn.innerHTML; 
  btn.innerHTML='<i class="ph-bold ph-spinner ph-spin"></i> Memanen...';
  if (typeof triggerBeekeeperHarvest === 'function') {
    try { triggerBeekeeperHarvest(0, 50); } catch(e) {}
  }
  const fd=new FormData(); 
  fd.append('action','harvest_all'); 
  fd.append('_csrf',document.querySelector('input[name="_csrf"]')?.value||'');
  fetch('/api/farm_action',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{
    if(d.ok){
      spawnHoneyFly(d.harvested_ml,btn);
      const hs=document.getElementById('hudHoneyStock');
      if(hs&&d.new_honey_stock!==undefined)hs.innerText=Number(d.new_honey_stock).toLocaleString('id-ID',{minimumFractionDigits:1})+' ml';
      const tb=document.getElementById('totalUnharvestedBadge');
      if(tb)tb.innerText='0.0 ml';
      HIVES_ARRAY.forEach(h => {
        if (h.details) {
          h.details.total_honey = 0;
          h.details.fill_percentage = 0;
        }
        if (USER_HIVES_MAP[h.id]?.details) {
          USER_HIVES_MAP[h.id].details.total_honey = 0;
          USER_HIVES_MAP[h.id].details.fill_percentage = 0;
        }
        const labelHoney = document.getElementById('hiveLabelHoney_' + h.id);
        if (labelHoney) labelHoney.innerHTML = '<i class="ph-fill ph-drop"></i> 0.0 ml';
      });
      btn.innerHTML='<i class="ph-bold ph-check"></i> '+(d.msg||'Berhasil!');
      setTimeout(()=>{btn.innerHTML=ot;btn.disabled=true;},2000);
    } else { 
      if(typeof nToast==='function') nToast(d.msg||'Gagal memanen','error'); 
      btn.disabled=false; 
      btn.innerHTML=ot; 
    }
  }).catch(()=>{
    btn.disabled=false;
    btn.innerHTML=ot;
    if(typeof nToast==='function') nToast('Error jaringan, coba lagi.','error');
  });
}

// ── AMBIENT SOUND ENGINE (LOUDER, REALISTIC MULTI-LAYER BEE SWARMS & NATURE) ──
const AmbientEngine = (function(){
  let ctx = null, mg = null, playing = false, nodes = [], timers = [];

  function gc() {
    if (!ctx) {
      const A = window.AudioContext || window.webkitAudioContext;
      if (A) {
        ctx = new A();
        mg = ctx.createGain();
        mg.gain.value = 0.18; // Subtle, natural ambiance
        mg.connect(ctx.destination);
      }
    }
    if (ctx && ctx.state === 'suspended') ctx.resume();
    return ctx;
  }

  // 1. Soft Apiary Hum (gentle background, not continuous drone)
  function hiveDrone() {
    const c = gc(); if (!c) return;
    try {
      // Single gentle hive hum (much softer)
      const o1 = c.createOscillator();
      const lfo = c.createOscillator(), lfoGain = c.createGain();
      const filter = c.createBiquadFilter(), gain = c.createGain();

      o1.type = 'triangle'; o1.frequency.value = 145;
      lfo.type = 'sine'; lfo.frequency.value = 6; // slow modulation
      lfoGain.gain.value = 5;
      lfo.connect(lfoGain);
      lfoGain.connect(o1.frequency);

      filter.type = 'bandpass'; filter.frequency.value = 200; filter.Q.value = 4.0;
      gain.gain.value = 0.06; // Very subtle background

      o1.connect(filter);
      filter.connect(gain); gain.connect(mg);
      o1.start(); lfo.start();
      nodes.push(o1, lfo);
    } catch(e) {}
  }

  // 2. Realistic Periodic Bee Flybys ("bzzbzbzzbzzbz" melintas dekat telinga)
  function startBeeFlybys() {
    if (!playing) return;
    function triggerSingleFlyby() {
      if (!playing) return;
      const c = gc();
      if (c) {
        try {
          const now = c.currentTime;
          const dur = 0.85 + Math.random() * 0.75;
          const baseFreq = 220 + Math.random() * 85;

          const osc1 = c.createOscillator();
          const osc2 = c.createOscillator();
          const lfo = c.createOscillator();
          const lfoG = c.createGain();
          const filter = c.createBiquadFilter();
          const panner = c.createStereoPanner ? c.createStereoPanner() : null;
          const gain = c.createGain();

          osc1.type = 'sawtooth';
          osc2.type = 'triangle';
          lfo.type = 'sawtooth';
          lfo.frequency.setValueAtTime(32 + Math.random() * 12, now);
          lfoG.gain.setValueAtTime(38, now);
          lfo.connect(lfoG);
          lfoG.connect(osc1.frequency);
          lfoG.connect(osc2.frequency);

          // Doppler pitch modulation: ascends on approach, descends flying away
          osc1.frequency.setValueAtTime(baseFreq * 0.85, now);
          osc1.frequency.exponentialRampToValueAtTime(baseFreq * 1.36, now + dur * 0.45);
          osc1.frequency.exponentialRampToValueAtTime(baseFreq * 0.76, now + dur);

          osc2.frequency.setValueAtTime(baseFreq * 0.88, now);
          osc2.frequency.exponentialRampToValueAtTime(baseFreq * 1.40, now + dur * 0.45);
          osc2.frequency.exponentialRampToValueAtTime(baseFreq * 0.78, now + dur);

          filter.type = 'bandpass';
          filter.frequency.setValueAtTime(baseFreq * 2.2, now);
          filter.Q.setValueAtTime(3.8, now);

          // Volume swell as bee zooms past
          gain.gain.setValueAtTime(0.01, now);
          gain.gain.linearRampToValueAtTime(0.12, now + dur * 0.45);
          gain.gain.exponentialRampToValueAtTime(0.001, now + dur);

          // Stereo panning across ears
          const startPan = Math.random() > 0.5 ? -0.85 : 0.85;
          if (panner) {
            panner.pan.setValueAtTime(startPan, now);
            panner.pan.linearRampToValueAtTime(-startPan, now + dur);
          }

          osc1.connect(filter); osc2.connect(filter);
          filter.connect(gain);
          if (panner) { gain.connect(panner); panner.connect(mg); }
          else { gain.connect(mg); }

          osc1.start(now); osc2.start(now); lfo.start(now);
          osc1.stop(now + dur); osc2.stop(now + dur); lfo.stop(now + dur);
        } catch(e) {}
      }
      const nextDelay = 4000 + Math.random() * 6000;
      const tid = setTimeout(triggerSingleFlyby, nextDelay);
      timers.push(tid);
    }
    triggerSingleFlyby();
  }

  // 3. Meadow Wind Breeze
  function windLoop() {
    const c = gc(); if (!c) return;
    try {
      const bs = c.sampleRate * 2, bf = c.createBuffer(1, bs, c.sampleRate), d = bf.getChannelData(0);
      for (let i = 0; i < bs; i++) d[i] = Math.random() * 2 - 1;
      const s = c.createBufferSource(); s.buffer = bf; s.loop = true;
      const lp = c.createBiquadFilter(); lp.type = 'lowpass'; lp.frequency.value = 420;
      const g = c.createGain(); g.gain.value = 0.045;
      const lfo = c.createOscillator(); lfo.type = 'sine'; lfo.frequency.value = 0.15;
      const lg = c.createGain(); lg.gain.value = 0.025;
      lfo.connect(lg); lg.connect(g.gain);
      s.connect(lp); lp.connect(g); g.connect(mg);
      s.start(); lfo.start();
      nodes.push(s, lfo);
    } catch(e) {}
  }

  // 4. Wild Meadow Songbirds
  function birds() {
    const c = gc(); if (!c) return;
    function chirp() {
      if (!playing) return;
      try {
        const n = c.currentTime, o = c.createOscillator(), g = c.createGain(), f = 1400 + Math.random() * 1600;
        o.type = 'sine'; o.frequency.setValueAtTime(f, n);
        o.frequency.exponentialRampToValueAtTime(f * (0.75 + Math.random() * 0.5), n + 0.07);
        o.frequency.exponentialRampToValueAtTime(f * 1.15, n + 0.13);
        g.gain.setValueAtTime(0.04, n); g.gain.exponentialRampToValueAtTime(0.001, n + 0.16);
        o.connect(g); g.connect(mg); o.start(n); o.stop(n + 0.18);
      } catch(e) {}
      const tid = setTimeout(chirp, 2200 + Math.random() * 4200);
      timers.push(tid);
    }
    const tid = setTimeout(chirp, 1000);
    timers.push(tid);
  }

  // 5. Nature Crickets
  function crickets() {
    const c = gc(); if (!c) return;
    function tick() {
      if (!playing) return;
      try {
        const n = c.currentTime;
        for (let i = 0; i < 3; i++) {
          const o = c.createOscillator(), g = c.createGain(), t = n + i * 0.035;
          o.type = 'square'; o.frequency.setValueAtTime(4400 + Math.random() * 600, t);
          g.gain.setValueAtTime(0.012, t); g.gain.exponentialRampToValueAtTime(0.001, t + 0.025);
          o.connect(g); g.connect(mg); o.start(t); o.stop(t + 0.03);
        }
      } catch(e) {}
      const tid = setTimeout(tick, 2500 + Math.random() * 4500);
      timers.push(tid);
    }
    const tid = setTimeout(tick, 1500);
    timers.push(tid);
  }

  return {
    start() {
      if (playing) return;
      playing = true;
      hiveDrone();
      startBeeFlybys();
      windLoop();
      birds();
      crickets();
    },
    stop() {
      playing = false;
      nodes.forEach(n => { try { n.stop(); } catch(e) {} });
      nodes = [];
      timers.forEach(t => clearTimeout(t));
      timers = [];
      if (ctx) { try { ctx.close(); } catch(e) {} ctx = null; }
    },
    isOn() { return playing; }
  };
})();
let ambientOn = false;
function toggleAmbientSound() {
  const b = document.getElementById('btnAmbientSound');
  if (ambientOn) {
    AmbientEngine.stop();
    ambientOn = false;
    b.innerHTML = '<i class="ph-fill ph-speaker-slash"></i> Muted';
  } else {
    AmbientEngine.start();
    ambientOn = true;
    b.innerHTML = '<i class="ph-fill ph-speaker-high"></i> Ambient';
  }
}

// Fallback stubs for camera controls
window.cineNext = function() {};
window.cinePrev = function() {};
window.cineZoomIn = function() {};
window.cineZoomOut = function() {};

// ══════════════════════════════════════════════════════════
// THREE.JS — CINEMATIC CAM + KEBUN ORGANIK (ANGIN, AIR, AWAN)
// ══════════════════════════════════════════════════════════
(function() {
  const container = document.getElementById('farm3dCanvas');
  const loadingEl = document.getElementById('farm3dLoading');
  const labelsC = document.getElementById('hiveLabelsContainer');

  if (!container) return;

  const isMobile = window.innerWidth <= 768 || /Mobi|Android|iPhone|iPad/i.test(navigator.userAgent);
  let renderer;
  try {
    renderer = new THREE.WebGLRenderer({ 
      antialias: !isMobile, 
      powerPreference: 'high-performance' 
    });
  } catch(e) {
    console.warn("WebGL initialization failed:", e);
    if (loadingEl) loadingEl.classList.add('hidden');
    return;
  }

  const scene = new THREE.Scene();

  const camera = new THREE.PerspectiveCamera(50, (container.clientWidth || 360) / (container.clientHeight || 260), 0.1, 300);
  renderer.setSize(container.clientWidth, container.clientHeight);
  renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, isMobile ? 1.5 : 2));
  if (!isMobile) {
    renderer.shadowMap.enabled = true;
    renderer.shadowMap.type = THREE.PCFSoftShadowMap;
  } else {
    renderer.shadowMap.enabled = false;
  }
  renderer.toneMapping = THREE.ACESFilmicToneMapping;
  renderer.toneMappingExposure = 1.3;
  renderer.domElement.style.touchAction = 'pan-y';
  container.insertBefore(renderer.domElement, container.firstChild);

  // ══════════════════════════════════════════════════════════
  // KEBUN 3D v2 — bentuk organik, bertekstur, dan hidup (angin, awan, air)
  // ══════════════════════════════════════════════════════════
  const U = { uTime: { value: 0 } };          // jam bersama untuk semua shader angin
  const swayers = [];                          // tajuk pohon / bunga yang digoyang angin (CPU)
  const leafSources = [];                      // titik asal daun gugur
  const flowerPatches = [];                    // tujuan lebah & kupu-kupu mencari nektar

  // ── Util: RNG ber-seed (tata letak kebun sama tiap reload) + noise ──
  let _sd = 20240607;
  function rnd() { _sd = (_sd * 16807) % 2147483647; return (_sd - 1) / 2147483646; }
  function rr(a, b) { return a + rnd() * (b - a); }
  function sstep(a, b, x) { const t = Math.min(1, Math.max(0, (x - a) / (b - a))); return t * t * (3 - 2 * t); }
  function h2(x, y) { const s = Math.sin(x * 127.1 + y * 311.7) * 43758.5453; return s - Math.floor(s); }
  function vnoise(x, y) {
    const xi = Math.floor(x), yi = Math.floor(y), xf = x - xi, yf = y - yi;
    const u = xf * xf * (3 - 2 * xf), v = yf * yf * (3 - 2 * yf);
    const a = h2(xi, yi), b = h2(xi + 1, yi), c = h2(xi, yi + 1), d = h2(xi + 1, yi + 1);
    return a + (b - a) * u + (c - a) * v + (a - b - c + d) * u * v;
  }
  function fbm(x, y, oct) {
    let a = 0.5, f = 1, s = 0, n = 0;
    for (let i = 0; i < (oct || 3); i++) { s += a * vnoise(x * f, y * f); n += a; a *= 0.5; f *= 2.03; }
    return s / n;
  }
  // Angin global: hembusan (gust) pelan + riak cepat. Dipakai pohon, bunga, daun, asap.
  function windAt(t, x, z) {
    const gust = 0.5 + 0.5 * Math.sin(t * 0.35 + x * 0.09 + z * 0.06);
    return (Math.sin(t * 1.1 + x * 0.5 + z * 0.35) + 0.4 * Math.sin(t * 2.3 + x * 1.4 - z * 1.1)) * (0.25 + 0.75 * gust);
  }

  // ── Util: geometri gabungan ber-warna-vertex (hemat draw call) ──
  function M(p, r, s) {
    const sc = (s === undefined) ? [1, 1, 1] : (typeof s === 'number' ? [s, s, s] : s);
    return new THREE.Matrix4().compose(
      new THREE.Vector3(p[0], p[1], p[2]),
      new THREE.Quaternion().setFromEuler(new THREE.Euler(r ? r[0] : 0, r ? r[1] : 0, r ? r[2] : 0)),
      new THREE.Vector3(sc[0], sc[1], sc[2])
    );
  }
  // Matriks batang dari titik a ke b (untuk geometri setinggi 1 di sumbu Y, berpusat di tengah)
  function Mseg(a, b, thick) {
    const dir = new THREE.Vector3().subVectors(b, a), len = dir.length();
    const q = new THREE.Quaternion().setFromUnitVectors(new THREE.Vector3(0, 1, 0), dir.normalize());
    return new THREE.Matrix4().compose(new THREE.Vector3().addVectors(a, b).multiplyScalar(0.5), q, new THREE.Vector3(thick, len, thick));
  }
  const _pc = new THREE.Color();
  function part(geo, mat4, color) {
    const g = geo.index ? geo.toNonIndexed() : geo.clone();
    if (mat4) g.applyMatrix4(mat4);
    const p = g.attributes.position, n = p.count, c = new Float32Array(n * 3);
    const isFn = typeof color === 'function';
    if (!isFn) _pc.set(color);
    for (let i = 0; i < n; i++) {
      if (isFn) color(p.getX(i), p.getY(i), p.getZ(i), _pc);
      c[i * 3] = _pc.r; c[i * 3 + 1] = _pc.g; c[i * 3 + 2] = _pc.b;
    }
    g.setAttribute('color', new THREE.BufferAttribute(c, 3));
    if (!g.attributes.uv) g.setAttribute('uv', new THREE.BufferAttribute(new Float32Array(n * 2), 2));
    if (!g.attributes.normal) g.computeVertexNormals();
    return g;
  }
  function merge(parts) {
    let total = 0; parts.forEach(p => total += p.attributes.position.count);
    const pos = new Float32Array(total * 3), nor = new Float32Array(total * 3), col = new Float32Array(total * 3), uv = new Float32Array(total * 2);
    let o = 0;
    parts.forEach(p => {
      const n = p.attributes.position.count;
      pos.set(p.attributes.position.array.subarray(0, n * 3), o * 3);
      nor.set(p.attributes.normal.array.subarray(0, n * 3), o * 3);
      col.set(p.attributes.color.array.subarray(0, n * 3), o * 3);
      uv.set(p.attributes.uv.array.subarray(0, n * 2), o * 2);
      o += n; p.dispose();
    });
    const g = new THREE.BufferGeometry();
    g.setAttribute('position', new THREE.BufferAttribute(pos, 3));
    g.setAttribute('normal', new THREE.BufferAttribute(nor, 3));
    g.setAttribute('color', new THREE.BufferAttribute(col, 3));
    g.setAttribute('uv', new THREE.BufferAttribute(uv, 2));
    return g;
  }
  // Bola yang "dipenyok" noise → gumpalan daun, batu, awan (tidak bulat sempurna)
  function blob(wSeg, hSeg, amt, freq, seed) {
    const g = new THREE.SphereGeometry(1, wSeg, hSeg), p = g.attributes.position, v = new THREE.Vector3();
    for (let i = 0; i < p.count; i++) {
      v.fromBufferAttribute(p, i);
      const n = fbm(v.x * freq + v.z * freq * 0.7 + seed, v.y * freq - v.z * freq * 0.5 + seed * 1.7, 3);
      v.multiplyScalar(1 + (n - 0.5) * 2 * amt);
      p.setXYZ(i, v.x, v.y, v.z);
    }
    g.computeVertexNormals();
    return g;
  }
  function roundedBox(w, h, d, r) {
    const bev = 0.012, hw = w / 2 - bev, hd = d / 2 - bev, s = new THREE.Shape();
    s.moveTo(-hw + r, -hd); s.lineTo(hw - r, -hd); s.quadraticCurveTo(hw, -hd, hw, -hd + r);
    s.lineTo(hw, hd - r); s.quadraticCurveTo(hw, hd, hw - r, hd);
    s.lineTo(-hw + r, hd); s.quadraticCurveTo(-hw, hd, -hw, hd - r);
    s.lineTo(-hw, -hd + r); s.quadraticCurveTo(-hw, -hd, -hw + r, -hd);
    const g = new THREE.ExtrudeGeometry(s, { depth: h - bev * 2, bevelEnabled: true, bevelThickness: bev, bevelSize: bev, bevelSegments: 2, curveSegments: 3, steps: 1 });
    g.rotateX(-Math.PI / 2); g.center();
    return g;
  }
  function prismGeo(halfW, height, length) {
    const s = new THREE.Shape(); s.moveTo(-halfW, 0); s.lineTo(halfW, 0); s.lineTo(0, height); s.lineTo(-halfW, 0);
    const g = new THREE.ExtrudeGeometry(s, { depth: length, bevelEnabled: false, steps: 1 });
    g.translate(0, 0, -length / 2);
    return g;
  }

  // ── Util: tekstur prosedural (canvas) ──
  function canvasTex(w, h, draw, repX, repY) {
    const c = document.createElement('canvas'); c.width = w; c.height = h;
    draw(c.getContext('2d'), w, h);
    const t = new THREE.CanvasTexture(c);
    t.wrapS = t.wrapT = THREE.RepeatWrapping;
    if (repX) t.repeat.set(repX, repY || repX);
    t.anisotropy = isMobile ? 1 : 4;
    return t;
  }
  function drawWood(vertical) {
    return function (x, w, h) {
      x.fillStyle = '#ece6dc'; x.fillRect(0, 0, w, h);
      for (let i = 0; i < 260; i++) {              // serat kayu
        const a = Math.random() * h, len = 30 + Math.random() * 120, s = Math.random() * w;
        x.strokeStyle = 'rgba(70,40,15,' + (0.04 + Math.random() * 0.12) + ')';
        x.lineWidth = 0.6 + Math.random() * 1.4;
        x.beginPath();
        if (vertical) { x.moveTo(a, s); x.bezierCurveTo(a + 2, s + len * 0.3, a - 2, s + len * 0.6, a + 1, s + len); }
        else { x.moveTo(s, a); x.bezierCurveTo(s + len * 0.3, a + 2, s + len * 0.6, a - 2, s + len, a + 1); }
        x.stroke();
      }
      for (let i = 0; i < 4; i++) {                // sambungan papan
        x.fillStyle = 'rgba(40,22,8,0.45)';
        if (vertical) x.fillRect(i * w / 4, 0, 2, h); else x.fillRect(0, i * h / 4, w, 2);
        x.fillStyle = 'rgba(255,255,255,0.18)';
        if (vertical) x.fillRect(i * w / 4 + 2, 0, 1, h); else x.fillRect(0, i * h / 4 + 2, w, 1);
      }
      for (let i = 0; i < 5; i++) {                // mata kayu
        const kx = Math.random() * w, ky = Math.random() * h;
        x.strokeStyle = 'rgba(60,32,10,0.35)'; x.lineWidth = 1.2;
        for (let r = 2; r < 8; r += 2.5) { x.beginPath(); x.ellipse(kx, ky, vertical ? r * 0.6 : r, vertical ? r : r * 0.6, 0, 0, 6.3); x.stroke(); }
      }
      for (let i = 0; i < 900; i++) {              // bercak lapuk
        x.fillStyle = Math.random() > 0.5 ? 'rgba(255,255,255,0.06)' : 'rgba(40,25,10,0.07)';
        x.fillRect(Math.random() * w, Math.random() * h, 2, 2);
      }
    };
  }
  const woodTex = canvasTex(256, 256, drawWood(false));
  const barkTex = canvasTex(128, 128, drawWood(true), 2, 1);
  const logTex = canvasTex(256, 256, drawWood(false), 1, 2);
  const roofTex = canvasTex(128, 128, drawWood(true), 1.5, 1);
  const groundTex = canvasTex(256, 256, function (x, w, h) {
    x.fillStyle = '#cfcfcf'; x.fillRect(0, 0, w, h);
    for (let i = 0; i < 2600; i++) {
      const px = Math.random() * w, py = Math.random() * h, l = 3 + Math.random() * 7;
      x.strokeStyle = Math.random() > 0.5 ? 'rgba(255,255,235,0.22)' : 'rgba(30,60,20,0.2)';
      x.lineWidth = 1; x.beginPath(); x.moveTo(px, py); x.lineTo(px + (Math.random() - 0.5) * 3, py - l); x.stroke();
    }
    for (let i = 0; i < 40; i++) {
      const px = Math.random() * w, py = Math.random() * h, r = 8 + Math.random() * 22;
      const g = x.createRadialGradient(px, py, 0, px, py, r);
      g.addColorStop(0, Math.random() > 0.5 ? 'rgba(255,255,220,0.16)' : 'rgba(20,50,20,0.16)'); g.addColorStop(1, 'rgba(0,0,0,0)');
      x.fillStyle = g; x.fillRect(px - r, py - r, r * 2, r * 2);
    }
  }, 30);
  function radialTex(stops) {
    const t = canvasTex(64, 64, function (x) {
      const g = x.createRadialGradient(32, 32, 0, 32, 32, 32);
      stops.forEach(s => g.addColorStop(s[0], s[1]));
      x.fillStyle = g; x.fillRect(0, 0, 64, 64);
    });
    t.wrapS = t.wrapT = THREE.ClampToEdgeWrapping;
    return t;
  }
  const softDotTex = radialTex([[0, 'rgba(255,255,255,1)'], [0.4, 'rgba(255,255,255,0.6)'], [1, 'rgba(255,255,255,0)']]);
  const sunTex = radialTex([[0, 'rgba(255,255,245,1)'], [0.12, 'rgba(255,250,215,0.95)'], [0.3, 'rgba(255,236,170,0.35)'], [1, 'rgba(255,225,150,0)']]);
  const blobShadowTex = radialTex([[0, 'rgba(10,30,10,0.5)'], [0.6, 'rgba(10,30,10,0.22)'], [1, 'rgba(10,30,10,0)']]);
  const blobShadowMat = new THREE.MeshBasicMaterial({ map: blobShadowTex, transparent: true, depthWrite: false });
  const blobShadowGeo = new THREE.PlaneGeometry(1, 1); blobShadowGeo.rotateX(-Math.PI / 2);
  // Bayangan palsu (hanya mobile, karena shadow map dimatikan di sana)
  function fakeShadow(parent, size, y) {
    if (!isMobile) return;
    const m = new THREE.Mesh(blobShadowGeo, blobShadowMat);
    m.scale.set(size, 1, size); m.position.y = y || 0.025; m.renderOrder = 1; parent.add(m);
  }
  function shadow(m, receive) { if (!isMobile) { m.castShadow = true; if (receive) m.receiveShadow = true; } return m; }

  // Material ber-angin: batang/bilah melentur dari pangkal, kepala bunga ikut ujung batang
  function windify(mat, kind) {
    mat.onBeforeCompile = function (sh) {
      sh.uniforms.uTime = U.uTime;
      sh.vertexShader = 'uniform float uTime;\n' + (kind === 'head' ? 'attribute float aH;\n' : '') + sh.vertexShader.replace('#include <project_vertex>', [
        'vec4 mvPosition = vec4( transformed, 1.0 );',
        'vec2 wRoot = vec2(0.0); float wAmt = 0.0;',
        '#ifdef USE_INSTANCING',
        '  mvPosition = instanceMatrix * mvPosition;',
        '  wRoot = instanceMatrix[3].xz;',
        (kind === 'head' ? '  wAmt = aH;' : '  wAmt = uv.y * uv.y * length(instanceMatrix[1].xyz);'),
        '#endif',
        'float wGust = 0.5 + 0.5 * sin(uTime * 0.35 + wRoot.x * 0.09 + wRoot.y * 0.06);',
        'float wW = sin(uTime * 1.7 + wRoot.x * 0.5 + wRoot.y * 0.35) + 0.4 * sin(uTime * 3.3 + wRoot.x * 1.4 - wRoot.y * 1.1);',
        'wAmt *= (0.25 + 0.75 * wGust);',
        'mvPosition.x += wW * wAmt * 0.30 + wAmt * 0.14;',
        'mvPosition.z += wW * wAmt * 0.14;',
        'mvPosition.y -= abs(wW) * wAmt * 0.05;',
        'mvPosition = modelViewMatrix * mvPosition;',
        'gl_Position = projectionMatrix * mvPosition;'
      ].join('\n'));
    };
    return mat;
  }

  // ── LANGIT: kubah gradasi + matahari berpendar ──
  const SUN_DIR = new THREE.Vector3(24, 26, -18).normalize();
  const HORIZON = new THREE.Color(0xa8d6f2);
  scene.background = HORIZON;
  scene.fog = new THREE.Fog(HORIZON.getHex(), 42, 125);
  (function () {
    const g = new THREE.SphereGeometry(160, 28, 16), p = g.attributes.position, c = new Float32Array(p.count * 3);
    const col = new THREE.Color(), v = new THREE.Vector3();
    const cM = new THREE.Color(0x5fa8ea), cZ = new THREE.Color(0x1f5fc8), cW = new THREE.Color(0xfff0c8);
    for (let i = 0; i < p.count; i++) {
      v.fromBufferAttribute(p, i).normalize();
      const e = Math.max(0, v.y);
      col.copy(HORIZON).lerp(cM, sstep(0.0, 0.2, e)).lerp(cZ, sstep(0.12, 0.8, e));
      col.lerp(cW, Math.pow(Math.max(0, v.dot(SUN_DIR)), 5) * 0.55);
      c[i * 3] = col.r; c[i * 3 + 1] = col.g; c[i * 3 + 2] = col.b;
    }
    g.setAttribute('color', new THREE.BufferAttribute(c, 3));
    const dome = new THREE.Mesh(g, new THREE.MeshBasicMaterial({ vertexColors: true, side: THREE.BackSide, fog: false, depthWrite: false }));
    dome.renderOrder = -10; scene.add(dome);
  })();
  const sunSprite = new THREE.Sprite(new THREE.SpriteMaterial({ map: sunTex, transparent: true, depthWrite: false, fog: false, blending: THREE.AdditiveBlending }));
  sunSprite.position.copy(SUN_DIR).multiplyScalar(110); sunSprite.scale.set(46, 46, 1); scene.add(sunSprite);

  // ── CAHAYA: matahari hangat dari samping + langit biru mengisi bayangan ──
  scene.add(new THREE.AmbientLight(0xfff5e0, 0.2));
  scene.add(new THREE.HemisphereLight(0xa9d2ff, 0x4f7a35, 0.42));
  const sun = new THREE.DirectionalLight(0xffedc8, 1.25);
  sun.position.set(17, 22, 5);
  if (!isMobile) {
    sun.castShadow = true;
    sun.shadow.mapSize.set(2048, 2048);
    sun.shadow.camera.near = 0.5; sun.shadow.camera.far = 70;
    sun.shadow.camera.left = -17; sun.shadow.camera.right = 17;
    sun.shadow.camera.top = 17; sun.shadow.camera.bottom = -17;
    sun.shadow.bias = -0.0004; sun.shadow.normalBias = 0.03;
    sun.shadow.radius = 3;
  } else {
    sun.castShadow = false;
  }
  scene.add(sun);
  const fillL = new THREE.DirectionalLight(0x9fd0ff, 0.2);
  fillL.position.set(-10, 10, 12);
  scene.add(fillL);

  // ── TATA LETAK (dipakai tanah, rumput, jalan, kolam) ──
  const POND = { x: 7, z: 5 };
  const PATH_PTS = [[-2.8, -0.7], [-3.3, 0.1], [-3.0, 0.9], [-2.4, 1.5], [-1.5, 2.2], [-0.6, 2.6], [0.4, 2.8], [1.4, 2.5], [2.4, 2.1], [3.4, 1.8], [4.4, 2.4], [5.2, 3.0]];
  function pondR(a) { return 1.55 * (1 + 0.16 * Math.sin(2 * a + 1.0) + 0.09 * Math.sin(3 * a + 0.5) + 0.05 * Math.sin(5 * a + 2.0)); }
  function distToPath(x, z) {
    let best = 1e9;
    for (let i = 0; i < PATH_PTS.length - 1; i++) {
      const ax = PATH_PTS[i][0], az = PATH_PTS[i][1], bx = PATH_PTS[i + 1][0], bz = PATH_PTS[i + 1][1];
      const dx = bx - ax, dz = bz - az, t = Math.max(0, Math.min(1, ((x - ax) * dx + (z - az) * dz) / (dx * dx + dz * dz)));
      const d = Math.hypot(x - ax - dx * t, z - az - dz * t);
      if (d < best) best = d;
    }
    return best;
  }

  // Organic / natural grid with slight random offsets for realism
  function seededRandom(seed) {
    let x = Math.sin(seed * 127.1 + 311.7) * 43758.5453;
    return x - Math.floor(x);
  }

  function hivePos(idx, total) {
    const maxCols = Math.min(Math.max(total, 1), 3);
    const totalRows = Math.ceil(total / maxCols);
    const row = Math.floor(idx / maxCols);
    const col = idx % maxCols;
    const itemsInThisRow = (row === totalRows - 1) ? (total - row * maxCols) : maxCols;

    const spacingX = 2.8;
    const spacingZ = 2.6;

    const ox = -(itemsInThisRow - 1) * spacingX / 2;
    const oz = -(totalRows - 1) * spacingZ / 2;

    // Very subtle organic offset (max ±0.15) so it looks natural but not messy
    const rx = (seededRandom(idx * 3 + 1) - 0.5) * 0.3;
    const rz = (seededRandom(idx * 3 + 2) - 0.5) * 0.25;

    return {
      x: ox + col * spacingX + 1.8 + rx,  // Shifted right to clear the cabin
      z: oz + row * spacingZ - 0.3 + rz
    };
  }
  const HPOS = HIVES_ARRAY.map((_, i) => hivePos(i, HIVES_ARRAY.length));

  // Seberapa "gundul" suatu titik (0 = rumput lebat, 1 = tanah/bangunan/air)
  function bareAt(x, z) {
    let b = 1 - sstep(0.3, 0.75, distToPath(x, z));
    for (let i = 0; i < HPOS.length; i++) b = Math.max(b, 1 - sstep(0.6, 1.05, Math.hypot(x - HPOS[i].x, z - HPOS[i].z)));
    b = Math.max(b, 1 - sstep(2.2, 2.9, Math.hypot(x + 3.8, z + 2.4)));
    const pa = Math.atan2(z - POND.z, x - POND.x);
    b = Math.max(b, 1 - sstep(pondR(pa) * 1.02, pondR(pa) * 1.3, Math.hypot(x - POND.x, z - POND.z)));
    return b;
  }

  // ── GROUND ELEVATION HELPER ──
  // Padang datar di tengah (r <= 6.5), lalu bergelombang lembut dan naik ke kaki gunung
  function getGroundElevation(worldX, worldZ) {
    const dist = Math.hypot(worldX, worldZ);
    const blend = sstep(6.5, 12, dist);
    let h = Math.sin(worldX * 0.15) * 0.35 + Math.cos(worldZ * 0.2) * 0.25 + Math.sin(worldX * 0.5 - worldZ * 0.4) * 0.12;
    h += (fbm(worldX * 0.11 + 20, worldZ * 0.11 + 7, 3) - 0.5) * 1.5;
    h *= blend;
    h += sstep(15, 34, dist) * (0.4 + 2.2 * fbm(worldX * 0.07 + 3, worldZ * 0.07 + 11, 3));
    // tepi kolam dibuat rata agar air tidak melayang / tenggelam
    h *= sstep(2.4, 4.2, Math.hypot(worldX - POND.x, worldZ - POND.z));
    return h;
  }

  // ── TANAH: warna bervariasi + tekstur rumput + bayangan awan berjalan ──
  const gSegs = isMobile ? 48 : 110;
  const gGeo = new THREE.PlaneGeometry(100, 100, gSegs, gSegs);
  const gPos = gGeo.getAttribute('position');
  (function () {
    const col = new Float32Array(gPos.count * 3), c = new THREE.Color();
    const cDark = new THREE.Color(0x23803f), cLight = new THREE.Color(0x44b257), cDry = new THREE.Color(0x93b548), cMown = new THREE.Color(0x4fb863), cFar = new THREE.Color(0x236b3d);
    for (let i = 0; i < gPos.count; i++) {
      const x = gPos.getX(i), y = gPos.getY(i), z = -y;
      gPos.setZ(i, getGroundElevation(x, z));
      const d = Math.hypot(x, z);
      c.copy(cDark).lerp(cLight, fbm(x * 0.09, z * 0.09, 4));
      c.lerp(cDry, sstep(0.55, 0.8, fbm(x * 0.33 + 9, z * 0.33, 3)) * 0.45);
      c.lerp(cMown, (1 - sstep(4, 8, d)) * 0.3);
      c.lerp(cFar, sstep(18, 42, d) * 0.6);
      col[i * 3] = c.r; col[i * 3 + 1] = c.g; col[i * 3 + 2] = c.b;
    }
    gGeo.setAttribute('color', new THREE.BufferAttribute(col, 3));
  })();
  gGeo.computeVertexNormals();
  const groundMat = new THREE.MeshStandardMaterial({ vertexColors: true, map: groundTex, roughness: 0.94 });
  groundMat.onBeforeCompile = function (sh) {
    sh.uniforms.uTime = U.uTime;
    sh.vertexShader = 'varying vec3 vWP;\n' + sh.vertexShader.replace('#include <begin_vertex>', '#include <begin_vertex>\n vWP = (modelMatrix * vec4(transformed, 1.0)).xyz;');
    sh.fragmentShader = [
      'uniform float uTime; varying vec3 vWP;',
      'float cH(vec2 p){ return fract(sin(dot(p, vec2(127.1, 311.7))) * 43758.5453); }',
      'float cN(vec2 p){ vec2 i = floor(p), f = fract(p); f = f*f*(3.0-2.0*f);',
      '  return mix(mix(cH(i), cH(i+vec2(1.,0.)), f.x), mix(cH(i+vec2(0.,1.)), cH(i+vec2(1.,1.)), f.x), f.y); }'
    ].join('\n') + '\n' + sh.fragmentShader.replace('#include <color_fragment>', [
      '#include <color_fragment>',
      'vec2 cp = vWP.xz * 0.055 + uTime * vec2(0.022, 0.009);',
      'float cl = cN(cp) * 0.65 + cN(cp * 2.1 + 7.0) * 0.35;',
      'diffuseColor.rgb *= mix(0.74, 1.04, smoothstep(0.36, 0.62, cl));'
    ].join('\n'));
  };
  const ground = new THREE.Mesh(gGeo, groundMat);
  ground.rotation.x = -Math.PI / 2; ground.receiveShadow = !isMobile; scene.add(ground);

  // Decal tanah: jalan setapak terinjak, tanah gundul di bawah sarang, tepi kolam
  (function () {
    const SIZE = 20, PX = isMobile ? 512 : 1024, k = PX / SIZE;
    const tex = canvasTex(PX, PX, function (x) {
      function dab(wx, wz, r, rgb, a) {
        const cx = (wx + SIZE / 2) * k, cy = (wz + SIZE / 2) * k, rp = r * k;
        const g = x.createRadialGradient(cx, cy, 0, cx, cy, rp);
        g.addColorStop(0, 'rgba(' + rgb + ',' + a + ')'); g.addColorStop(0.55, 'rgba(' + rgb + ',' + (a * 0.55) + ')'); g.addColorStop(1, 'rgba(' + rgb + ',0)');
        x.fillStyle = g; x.fillRect(cx - rp, cy - rp, rp * 2, rp * 2);
      }
      const DIRT = '150,112,70', DIRT2 = '122,90,55', SAND = '196,172,120';
      for (let i = 0; i < PATH_PTS.length - 1; i++) {
        const a = PATH_PTS[i], b = PATH_PTS[i + 1], n = Math.ceil(Math.hypot(b[0] - a[0], b[1] - a[1]) / 0.12);
        for (let j = 0; j < n; j++) {
          const t = j / n;
          dab(a[0] + (b[0] - a[0]) * t + (Math.random() - 0.5) * 0.18, a[1] + (b[1] - a[1]) * t + (Math.random() - 0.5) * 0.18, 0.4 + Math.random() * 0.22, Math.random() > 0.4 ? DIRT : DIRT2, 0.34);
        }
      }
      HPOS.forEach(p => { for (let j = 0; j < 9; j++) dab(p.x + (Math.random() - 0.5) * 0.7, p.z + 0.25 + (Math.random() - 0.5) * 0.8, 0.6 + Math.random() * 0.45, Math.random() > 0.5 ? DIRT : DIRT2, 0.3); });
      for (let j = 0; j < 14; j++) dab(-2.9 + (Math.random() - 0.5) * 1.6, -0.7 + (Math.random() - 0.5) * 1.0, 0.6 + Math.random() * 0.4, DIRT, 0.2);
      for (let a = 0; a < 6.283; a += 0.07) {
        const r = pondR(a);
        dab(POND.x + Math.cos(a) * r * 1.02, POND.z + Math.sin(a) * r * 1.02, 0.42 + Math.random() * 0.2, SAND, 0.3);
        dab(POND.x + Math.cos(a) * r * 0.6, POND.z + Math.sin(a) * r * 0.6, 0.6, DIRT2, 0.5);
      }
      for (let i = 0; i < 260; i++) {            // kerikil & gumpalan tanah kecil
        const wx = (Math.random() - 0.5) * 14, wz = (Math.random() - 0.5) * 12;
        if (bareAt(wx, wz) > 0.4) dab(wx, wz, 0.04 + Math.random() * 0.07, Math.random() > 0.5 ? '90,66,40' : '200,185,150', 0.55);
      }
    });
    tex.wrapS = tex.wrapT = THREE.ClampToEdgeWrapping;
    const decal = new THREE.Mesh(new THREE.PlaneGeometry(SIZE, SIZE), new THREE.MeshStandardMaterial({ map: tex, transparent: true, depthWrite: false, roughness: 1, polygonOffset: true, polygonOffsetFactor: -2, polygonOffsetUnits: -2 }));
    decal.rotation.x = -Math.PI / 2; decal.position.y = 0.012; decal.receiveShadow = !isMobile; decal.renderOrder = 0;
    scene.add(decal);
  })();

  // ── PEGUNUNGAN: lereng beralur, hutan di kaki, batu di tengah, salju di puncak ──
  const mountainMat = new THREE.MeshStandardMaterial({ vertexColors: true, roughness: 0.96 });
  const ROCK_C = new THREE.Color(0x7d8577), SNOW_C = new THREE.Color(0xf4f9ff);
  function createSmoothMountain(x, z, height, baseW, color) {
    const rs = isMobile ? 12 : 22, hs = isMobile ? 6 : 10;
    const g = new THREE.ConeGeometry(baseW, height, rs, hs, true);
    g.translate(0, height / 2, 0);
    const p = g.attributes.position, col = new Float32Array(p.count * 3), c = new THREE.Color(), tint = new THREE.Color(color);
    const sx = x * 0.37 + z * 0.11, snow = height > 7;
    for (let i = 0; i < p.count; i++) {
      const py = p.getY(i), t = Math.min(1, Math.max(0, py / height)), a = Math.atan2(p.getZ(i), p.getX(i)), ca = Math.cos(a), sa = Math.sin(a);
      const prof = Math.pow(1 - t, 1.6) * 0.65 + (1 - t) * 0.35;
      const ridge = fbm(ca * 1.6 + sx, sa * 1.6 + t * 2.2 + sx * 0.5, 4);
      const r = baseW * 1.25 * prof * (0.7 + ridge * 0.65);
      const y = py + (fbm(ca * 2.5 + sx + 5, sa * 2.5 + 3, 2) - 0.5) * height * 0.14 * Math.sin(Math.min(1, t) * Math.PI);
      p.setXYZ(i, ca * r, y, sa * r);
      c.copy(tint).multiplyScalar(0.8 + ridge * 0.45);
      c.lerp(ROCK_C, sstep(0.38, 0.72, t + (ridge - 0.5) * 0.5) * 0.8);
      if (snow) c.lerp(SNOW_C, sstep(0.66, 0.8, t + (ridge - 0.5) * 0.35));
      col[i * 3] = c.r; col[i * 3 + 1] = c.g; col[i * 3 + 2] = c.b;
    }
    g.setAttribute('color', new THREE.BufferAttribute(col, 3));
    g.computeVertexNormals();
    const m = new THREE.Mesh(g, mountainMat);
    m.position.set(x, -0.6, z);
    scene.add(m);
    return m;
  }

  // Mountains ring — placed OUTSIDE the farm area
  const mtColors = [0x3a7d44, 0x4a8a54, 0x2d6b38, 0x558b5e, 0x1f5c2a];
  const mtFarColors = [0x2d5a36, 0x1a4d28, 0x3a6b42];
  const mtCountInner = isMobile ? 8 : 20;
  for (let i = 0; i < mtCountInner; i++) {
    const angle = (i / mtCountInner) * Math.PI * 2 + rr(-0.08, 0.08);
    const dist = 29 + rnd() * 7;
    createSmoothMountain(Math.sin(angle) * dist, Math.cos(angle) * dist, 3 + rnd() * 5, 3.5 + rnd() * 3.5, mtColors[i % mtColors.length]);
  }
  const mtCountOuter = isMobile ? 5 : 14;
  for (let i = 0; i < mtCountOuter; i++) {
    const angle = (i / mtCountOuter) * Math.PI * 2 + 0.15;
    const dist = 39 + rnd() * 11;
    createSmoothMountain(Math.sin(angle) * dist, Math.cos(angle) * dist, 7 + rnd() * 8, 5 + rnd() * 5, mtFarColors[i % mtFarColors.length]);
  }

  // ── PEPOHONAN: batang melengkung, tajuk bergumpal, bergoyang ditiup angin ──
  const foliageMat = new THREE.MeshStandardMaterial({ vertexColors: true, roughness: 0.86 });
  const barkMat = new THREE.MeshStandardMaterial({ color: 0x8f6a46, map: barkTex, roughness: 0.93 });
  const blobVars = [0, 1, 2].map(i => blob(isMobile ? 6 : 9, isMobile ? 4 : 6, 0.24, 1.3, i * 17.3 + 3));
  const GREENS = [[0x237f3a, 0x4fbf5f], [0x1f7335, 0x3fa84e], [0x318c42, 0x86c94e], [0x1b6a30, 0x379a4f]].map(p => p.map(h => new THREE.Color(h)));
  function trunkGeo(h, r0, r1, bx, bz) {
    const g = new THREE.CylinderGeometry(r1, r0, h, 7, 5); g.translate(0, h / 2, 0);
    const p = g.attributes.position;
    for (let i = 0; i < p.count; i++) {
      const t = p.getY(i) / h, flare = 1 + Math.pow(1 - t, 6) * 0.7;
      p.setX(i, p.getX(i) * flare + bx * t * t); p.setZ(i, p.getZ(i) * flare + bz * t * t);
    }
    g.computeVertexNormals();
    return g;
  }
  function foliageColor(pal, y0, y1, seed) {
    return function (x, y, z, c) {
      c.copy(pal[0]).lerp(pal[1], vnoise(x * 1.6 + seed, z * 1.6 + y * 1.1));
      c.multiplyScalar(0.5 + 0.6 * sstep(y0, y1, y));       // bawah teduh, atas kena matahari
    };
  }
  function mkTree(x, z, s, tall) {
    const g = new THREE.Group();
    const h = (tall ? 1.9 : 1.25) * s, bx = rr(-0.2, 0.2) * s, bz = rr(-0.2, 0.2) * s;
    g.add(shadow(new THREE.Mesh(trunkGeo(h, 0.15 * s, 0.07 * s, bx, bz), barkMat)));
    const crown = new THREE.Group();
    crown.position.set(bx * 0.67, h * 0.82, bz * 0.67);
    const cw = (tall ? 0.55 : 0.88) * s, ch = (tall ? 1.5 : 0.95) * s, n = isMobile ? 4 : (tall ? 5 : 6);
    const list = [[0, ch * 0.42, 0, cw * 0.78]];
    for (let i = 0; i < n; i++) {
      const a = (i / n) * Math.PI * 2 + rr(-0.4, 0.4), rad = cw * rr(0.38, 0.62);
      list.push([Math.cos(a) * rad, ch * rr(0.12, 0.72), Math.sin(a) * rad, cw * rr(0.42, 0.66)]);
    }
    list.push([rr(-0.1, 0.1) * s, ch * 0.92, rr(-0.1, 0.1) * s, cw * 0.5]);
    const colFn = foliageColor(GREENS[Math.floor(rnd() * GREENS.length)], -cw * 0.3, ch + cw * 0.4, rnd() * 50);
    const cm = shadow(new THREE.Mesh(merge(list.map(b => part(blobVars[Math.floor(rnd() * 3)], M([b[0], b[1], b[2]], [rr(0, 3), rr(0, 3), rr(0, 3)], [b[3], b[3] * rr(0.78, 0.95), b[3]]), colFn))), foliageMat));
    crown.add(cm); g.add(crown);
    const gy = getGroundElevation(x, z) - 0.05;
    g.position.set(x, gy, z); g.rotation.y = rr(0, 6.28);
    fakeShadow(g, cw * 3.2, 0.09);
    scene.add(g);
    swayers.push({ o: crown, x: x, z: z, amp: tall ? 0.055 : 0.038, ph: rnd() * 6.28 });
    leafSources.push(new THREE.Vector3(x, gy + h * 0.82 + ch * 0.5, z));
    return g;
  }
  const coneVars = [0, 1].map(seed => {
    const g = new THREE.ConeGeometry(1, 1, isMobile ? 7 : 10, 1), p = g.attributes.position;
    for (let i = 0; i < p.count; i++) {
      const px = p.getX(i), pz = p.getZ(i);
      if (p.getY(i) < -0.49 && Math.hypot(px, pz) > 0.5) {
        const key = Math.round(Math.atan2(pz, px) * 8), k = 0.82 + 0.36 * h2(key, seed + 1);
        p.setXYZ(i, px * k, -0.5 + (h2(key, seed + 9) - 0.65) * 0.2, pz * k);
      }
    }
    g.computeVertexNormals();
    return g;
  });
  const PINE_A = new THREE.Color(0x1c6b36), PINE_B = new THREE.Color(0x3a9a58);
  function pineParts(s, tiers, y0) {
    const parts = [];
    for (let i = 0; i < tiers; i++) {
      const r = (0.64 - i * (0.5 / tiers)) * s, hh = 0.64 * s, yc = y0 + (0.3 + i * 0.36) * s;
      parts.push(part(coneVars[i % 2], M([0, yc, 0], [0, rr(0, 6.28), rr(-0.04, 0.04)], [r, hh, r]), function (x, y, z, c) {
        c.copy(PINE_A).lerp(PINE_B, sstep(yc - hh / 2, yc + hh / 2, y)).multiplyScalar(0.72 + 0.1 * i);
      }));
    }
    return parts;
  }
  function mkPine(x, z, s) {
    const g = new THREE.Group();
    const trunk = shadow(new THREE.Mesh(new THREE.CylinderGeometry(0.05 * s, 0.1 * s, 0.9 * s, 6), barkMat));
    trunk.position.y = 0.45 * s; g.add(trunk);
    const crown = new THREE.Group(); crown.position.y = 0.5 * s;
    crown.add(shadow(new THREE.Mesh(merge(pineParts(s, isMobile ? 4 : 5, 0)), foliageMat)));
    g.add(crown);
    const gy = getGroundElevation(x, z) - 0.05;
    g.position.set(x, gy, z);
    fakeShadow(g, s * 1.9, 0.09);
    scene.add(g);
    swayers.push({ o: crown, x: x, z: z, amp: 0.03, ph: rnd() * 6.28 });
    return g;
  }
  function spotFree(x, z, pad) {
    if (z > 6.5 && Math.abs(x) < 5.5) return false;                       // jangan menutupi kamera
    if (Math.hypot(x - POND.x, z - POND.z) < 3.2 + pad) return false;
    if (Math.hypot(x + 3.8, z + 2.5) < 3.4 + pad) return false;
    return true;
  }
  const treeCount = isMobile ? 13 : 34;
  for (let i = 0, tries = 0; i < treeCount && tries < 400; tries++) {
    const angle = rnd() * Math.PI * 2, dist = 8 + rnd() * 13;
    const x = Math.sin(angle) * dist, z = Math.cos(angle) * dist;
    if (!spotFree(x, z, 0.5)) continue;
    const s = 0.75 + rnd() * 0.85, k = rnd();
    if (k > 0.42) mkTree(x, z, s, false); else if (k > 0.22) mkTree(x, z, s, true); else mkPine(x, z, s * 1.15);
    i++;
  }
  // Hutan pinus jauh di kaki gunung (1 draw call, instanced)
  (function () {
    const n = isMobile ? 50 : 170;
    const geo = merge([part(new THREE.CylinderGeometry(0.05, 0.09, 0.5, 5), M([0, 0.25, 0]), 0x6b4a30)].concat(pineParts(1, 3, 0.35)));
    const im = new THREE.InstancedMesh(geo, new THREE.MeshLambertMaterial({ vertexColors: true }), n);
    const d = new THREE.Object3D(), c = new THREE.Color();
    for (let i = 0; i < n; i++) {
      const a = rnd() * 6.283, r = 21 + rnd() * 9, x = Math.sin(a) * r, z = Math.cos(a) * r, s = rr(0.9, 1.9);
      d.position.set(x, getGroundElevation(x, z) - 0.05, z); d.rotation.y = rnd() * 6.28; d.scale.set(s, s * rr(0.9, 1.25), s); d.updateMatrix();
      im.setMatrixAt(i, d.matrix);
      const k = rr(0.75, 1.15); im.setColorAt(i, c.setRGB(k, k * rr(0.95, 1.1), k));
    }
    scene.add(im);
  })();
  // Semak rendah di sekitar pagar & tepi padang
  (function () {
    const n = isMobile ? 6 : 16, parts = [];
    for (let i = 0; i < n; i++) {
      const a = rnd() * 6.283, r = 6.6 + rnd() * 6, x = Math.sin(a) * r, z = Math.cos(a) * r;
      if (!spotFree(x, z, 0)) continue;
      const gy = getGroundElevation(x, z), s = rr(0.35, 0.6), colFn = foliageColor(GREENS[i % GREENS.length], gy - s * 0.2, gy + s * 2.2, i * 3.1);
      for (let j = 0; j < 3; j++) parts.push(part(blobVars[j], M([x + rr(-0.35, 0.35), gy + s * rr(0.3, 0.5), z + rr(-0.35, 0.35)], [0, rr(0, 6), 0], [s * rr(0.8, 1.2), s * rr(0.7, 0.9), s * rr(0.8, 1.2)]), colFn));
    }
    if (parts.length) scene.add(shadow(new THREE.Mesh(merge(parts), foliageMat)));
  })();

  // ── BATU: bongkah tak beraturan, berlumut di sisi atas ──
  const rockVars = [0, 1].map(i => blob(7, 5, 0.3, 1.1, i * 31.7 + 11));
  const ROCK_A = new THREE.Color(0x8b8d86), ROCK_B = new THREE.Color(0x6f7a52);
  function rockPart(x, y, z, sx, sy, sz, seed) {
    return part(rockVars[seed % 2], M([x, y, z], [rr(-0.3, 0.3), rr(0, 6.28), rr(-0.3, 0.3)], [sx, sy, sz]), function (px, py, pz, c) {
      c.copy(ROCK_A).multiplyScalar(0.75 + 0.4 * vnoise(px * 5 + seed, pz * 5 + py * 4));
      c.lerp(ROCK_B, sstep(y, y + sy * 0.9, py) * 0.6 * vnoise(px * 2 + seed, pz * 2));
    });
  }
  (function () {
    const parts = [], rockCount = isMobile ? 6 : 14;
    for (let i = 0; i < rockCount; i++) {
      const angle = rnd() * Math.PI * 2, dist = 5.2 + rnd() * 10;
      const x = Math.sin(angle) * dist, z = Math.cos(angle) * dist;
      if (!spotFree(x, z, -0.8) || bareAt(x, z) > 0.3) continue;
      const s = 0.2 + rnd() * 0.32, gy = getGroundElevation(x, z);
      parts.push(rockPart(x, gy + s * 0.25, z, s * rr(0.9, 1.4), s * rr(0.55, 0.8), s * rr(0.8, 1.2), i));
      if (rnd() > 0.5) parts.push(rockPart(x + s * 1.1, gy + s * 0.1, z + rr(-0.2, 0.2), s * 0.5, s * 0.35, s * 0.45, i + 1));
    }
    for (let i = 0; i < 9; i++) {       // batu tepi kolam
      const a = (i / 9) * 6.283 + rr(-0.2, 0.2), r = pondR(a) * rr(1.0, 1.12), s = rr(0.12, 0.26);
      parts.push(rockPart(POND.x + Math.cos(a) * r, 0.03 + s * 0.2, POND.z + Math.sin(a) * r, s * rr(0.9, 1.3), s * 0.6, s, i + 20));
    }
    scene.add(shadow(new THREE.Mesh(merge(parts), new THREE.MeshStandardMaterial({ vertexColors: true, roughness: 0.95 })), true));
  })();

  // ── BUNGA LIAR: bergerombol, tiap rumpun satu warna, bergoyang (instanced) ──
  (function () {
    const patchCount = isMobile ? 9 : 24, perPatch = isMobile ? 9 : 13, max = patchCount * perPatch;
    const fColors = [0xff6fb5, 0xffd83a, 0xff5a4f, 0xb07bff, 0xff9a2e, 0xffffff, 0x7cc8ff, 0xff3fa0];
    const stemGeo = new THREE.CylinderGeometry(0.007, 0.011, 1, 4, 3); stemGeo.translate(0, 0.5, 0);
    const petal = new THREE.SphereGeometry(1, 5, 3), headParts = [];
    for (let i = 0; i < 6; i++) {
      const a = (i / 6) * 6.283;
      headParts.push(part(petal, M([Math.cos(a) * 0.62, 0.05, Math.sin(a) * 0.62], [0, -a, 0.25], [0.5, 0.13, 0.3]), 0xffffff));
    }
    headParts.push(part(petal, M([0, 0.1, 0], null, [0.34, 0.22, 0.34]), 0xffe27a));
    const headGeo = merge(headParts);
    const aH = new Float32Array(max);
    const stems = new THREE.InstancedMesh(stemGeo, windify(new THREE.MeshLambertMaterial({ color: 0x3f9a4a }), 'blade'), max);
    const heads = new THREE.InstancedMesh(headGeo, windify(new THREE.MeshLambertMaterial({ vertexColors: true }), 'head'), max);
    const d = new THREE.Object3D(), c = new THREE.Color();
    let n = 0;
    for (let pI = 0, tries = 0; pI < patchCount && tries < 300; tries++) {
      const a = rnd() * 6.283, r = 3.6 + rnd() * 11, cx = Math.sin(a) * r, cz = Math.cos(a) * r;
      if (bareAt(cx, cz) > 0.2 || !spotFree(cx, cz, -1.2)) continue;
      const col = fColors[Math.floor(rnd() * fColors.length)], spread = rr(0.5, 1.1);
      flowerPatches.push(new THREE.Vector3(cx, getGroundElevation(cx, cz) + 0.42, cz));
      for (let j = 0; j < perPatch; j++) {
        const fa = rnd() * 6.283, fr = Math.sqrt(rnd()) * spread, x = cx + Math.cos(fa) * fr, z = cz + Math.sin(fa) * fr;
        if (bareAt(x, z) > 0.5) continue;
        const gy = getGroundElevation(x, z), h = rr(0.24, 0.48), hs = rr(0.06, 0.1);
        d.position.set(x, gy, z); d.rotation.set(0, rnd() * 6.28, 0); d.scale.set(1, h, 1); d.updateMatrix(); stems.setMatrixAt(n, d.matrix);
        d.position.set(x, gy + h, z); d.rotation.set(rr(-0.35, 0.35), rnd() * 6.28, rr(-0.35, 0.35)); d.scale.set(hs, hs, hs); d.updateMatrix(); heads.setMatrixAt(n, d.matrix);
        const k = rr(0.85, 1.1); heads.setColorAt(n, c.set(col).multiplyScalar(k));
        aH[n] = h; n++;
      }
      pI++;
    }
    headGeo.setAttribute('aH', new THREE.InstancedBufferAttribute(aH, 1));
    stems.count = heads.count = n;
    stems.frustumCulled = heads.frustumCulled = false;
    scene.add(stems); scene.add(heads);
  })();

  // ── RUMPUT: ribuan rumpun melambai mengikuti hembusan angin (instanced + shader) ──
  (function () {
    const pos = [], uv = [], col = [], nor = [], idx = [], blades = 3, segs = 3;
    for (let b = 0; b < blades; b++) {
      const a = (b / blades) * Math.PI + 0.3, dx = Math.cos(a), dz = Math.sin(a), nx = -dz, nz = dx;
      const lean = (b - 1) * 0.28 + 0.12, ox = nx * (b - 1) * 0.05, oz = nz * (b - 1) * 0.05, hgt = 1 - b * 0.14, base = pos.length / 3;
      for (let s = 0; s <= segs; s++) {
        const t = s / segs, hw = 0.045 * (1 - t * 0.9), cx = ox + nx * lean * t * t, cz = oz + nz * lean * t * t, k = 0.72 + 0.5 * t;
        pos.push(cx - dx * hw, t * hgt, cz - dz * hw, cx + dx * hw, t * hgt, cz + dz * hw);
        uv.push(0, t, 1, t); col.push(k, k, k, k, k, k); nor.push(0, 1, 0, 0, 1, 0);
        if (s < segs) { const i0 = base + s * 2; idx.push(i0, i0 + 1, i0 + 2, i0 + 1, i0 + 3, i0 + 2); }
      }
    }
    const geo = new THREE.BufferGeometry();
    geo.setAttribute('position', new THREE.Float32BufferAttribute(pos, 3));
    geo.setAttribute('normal', new THREE.Float32BufferAttribute(nor, 3));
    geo.setAttribute('uv', new THREE.Float32BufferAttribute(uv, 2));
    geo.setAttribute('color', new THREE.Float32BufferAttribute(col, 3));
    geo.setIndex(idx);
    const max = isMobile ? 2600 : 14000;
    const grass = new THREE.InstancedMesh(geo, windify(new THREE.MeshLambertMaterial({ vertexColors: true, side: THREE.DoubleSide }), 'blade'), max);
    const d = new THREE.Object3D(), c = new THREE.Color();
    const gA = new THREE.Color(0x3aa653), gB = new THREE.Color(0x6bd070), gC = new THREE.Color(0xc0d860), gReed = new THREE.Color(0x3f8a3f);
    let n = 0;
    function put(x, z, h, w, color) {
      d.position.set(x, getGroundElevation(x, z), z); d.rotation.y = rnd() * 6.28; d.scale.set(w, h, w); d.updateMatrix();
      grass.setMatrixAt(n, d.matrix); grass.setColorAt(n, color); n++;
    }
    for (let tries = 0; n < max - 90 && tries < max * 3; tries++) {
      const a = rnd() * 6.283, r = Math.sqrt(rnd()) * 21, x = Math.sin(a) * r, z = Math.cos(a) * r;
      const bare = bareAt(x, z);
      if (bare > 0.55 || rnd() < bare) continue;
      if (fbm(x * 0.5 + 40, z * 0.5, 2) < 0.3) continue;                 // rumput tumbuh berkelompok
      const tallness = 0.45 + 0.55 * sstep(3.5, 9, r);                    // tengah kebun lebih pendek (sering diinjak)
      const h = rr(0.11, 0.3) * tallness * (0.7 + 0.6 * fbm(x * 0.3, z * 0.3 + 5, 2));
      c.copy(gA).lerp(gB, fbm(x * 0.2 + 3, z * 0.2, 2)).lerp(gC, rnd() < 0.12 ? 0.6 : 0);
      c.multiplyScalar(rr(0.85, 1.12));
      put(x, z, h, rr(0.55, 0.95), c);
    }
    for (let i = 0; i < 90 && n < max; i++) {                             // alang-alang tepi kolam
      const a = rnd() * 6.283; if (Math.sin(a * 2 + 1) < -0.2) continue;
      const r = pondR(a) * rr(0.95, 1.22);
      c.copy(gReed).multiplyScalar(rr(0.8, 1.15));
      put(POND.x + Math.cos(a) * r, POND.z + Math.sin(a) * r, rr(0.55, 1.05), rr(0.9, 1.3), c);
    }
    grass.count = n; grass.frustumCulled = false;
    scene.add(grass);
  })();

  // ── KOLAM: tepi tak beraturan, air bergradasi, riak, teratai ──
  const pondRipples = [], lilyPads = [];
  const pond = (function () {
    const rings = 4, seg = 40, pos = [], col = [], idx = [], c = new THREE.Color();
    const deep = new THREE.Color(0x1f7fb5), shallow = new THREE.Color(0x6fd3ea);
    pos.push(0, 0, 0); col.push(deep.r, deep.g, deep.b);
    for (let r = 1; r <= rings; r++) for (let s = 0; s < seg; s++) {
      const a = (s / seg) * 6.283, k = r / rings, R = pondR(a) * k;
      pos.push(Math.cos(a) * R, 0, Math.sin(a) * R);
      c.copy(deep).lerp(shallow, k * k); col.push(c.r, c.g, c.b);
    }
    for (let s = 0; s < seg; s++) idx.push(0, 1 + (s + 1) % seg, 1 + s);
    for (let r = 1; r < rings; r++) for (let s = 0; s < seg; s++) {
      const a = 1 + (r - 1) * seg + s, b = 1 + (r - 1) * seg + (s + 1) % seg, e = a + seg, f = b + seg;
      idx.push(a, b, e, b, f, e);
    }
    const g = new THREE.BufferGeometry();
    g.setAttribute('position', new THREE.Float32BufferAttribute(pos, 3));
    g.setAttribute('color', new THREE.Float32BufferAttribute(col, 3));
    g.setIndex(idx); g.computeVertexNormals();
    const m = new THREE.Mesh(g, new THREE.MeshStandardMaterial({ vertexColors: true, roughness: 0.08, metalness: 0.35, transparent: true, opacity: 0.86 }));
    m.position.set(POND.x, 0.035, POND.z); m.receiveShadow = !isMobile; m.renderOrder = 2;
    scene.add(m);
    const ringGeo = new THREE.RingGeometry(0.9, 1, 28); ringGeo.rotateX(-Math.PI / 2);
    for (let i = 0; i < 3; i++) {
      const rp = new THREE.Mesh(ringGeo, new THREE.MeshBasicMaterial({ color: 0xffffff, transparent: true, opacity: 0, depthWrite: false }));
      rp.userData = { t: i * 1.3, dur: 3.6 }; rp.renderOrder = 3; rp.position.y = 0.045;
      scene.add(rp); pondRipples.push(rp);
    }
    const padGeo = new THREE.CircleGeometry(1, 12, 0.35, 5.7); padGeo.rotateX(-Math.PI / 2);
    const padMat = new THREE.MeshStandardMaterial({ color: 0x2f9b4e, roughness: 0.6, side: THREE.DoubleSide });
    [[-0.5, 0.3, 0.2], [0.35, -0.45, 0.16], [0.6, 0.5, 0.13], [-0.2, -0.65, 0.12], [-0.75, -0.2, 0.1]].forEach((p, i) => {
      const pad = new THREE.Mesh(padGeo, padMat);
      pad.position.set(POND.x + p[0], 0.05, POND.z + p[1]); pad.scale.set(p[2], 1, p[2]); pad.rotation.y = i * 1.7;
      pad.userData = { ph: i * 1.3, ry: i * 1.7 }; pad.renderOrder = 4;
      scene.add(pad); lilyPads.push(pad);
      if (i < 2) {
        const fl = new THREE.Mesh(new THREE.SphereGeometry(0.3, 6, 4), new THREE.MeshStandardMaterial({ color: i ? 0xffffff : 0xff8fc4, roughness: 0.5 }));
        fl.scale.y = 0.6; fl.position.set(0.15, 0.2, 0.1); pad.add(fl);
      }
    });
    return m;
  })();

  // ── SARANG LEBAH: kotak Langstroth kayu, sudut membulat, atap pelana ──
  const hive3D = [], hiveWP = [];
  const hiveMat = new THREE.MeshStandardMaterial({ vertexColors: true, map: woodTex, roughness: 0.74, metalness: 0.02 });
  const hiveBoxGeo = roundedBox(1.02, 0.32, 0.82, 0.04);
  const hiveRoofGeo = prismGeo(0.6, 0.3, 0.98);
  const unitBox = new THREE.BoxGeometry(1, 1, 1);
  const unitCyl = new THREE.CylinderGeometry(0.9, 1, 1, 6);

  function mkBeehive(idx) {
    const g = new THREE.Group(), parts = [], S = function (k) { return seededRandom(idx * 13 + k); };
    function box(x, y, z, w, h, d, color, rot) { parts.push(part(unitBox, M([x, y, z], rot || null, [w, h, d]), color)); }
    const tone = 0.9 + S(1) * 0.2;                               // tiap sarang sedikit beda warna
    const weather = function (hex, seed) {
      const base = new THREE.Color(hex).multiplyScalar(tone);
      return function (x, y, z, c) { c.copy(base).multiplyScalar(0.86 + 0.22 * vnoise(x * 7 + seed, y * 9 + z * 7)); };
    };
    const DARK = 0x6b3410, DARKER = 0x4a240b;

    // 1. Batu pijakan pipih (tak beraturan)
    parts.push(part(rockVars[idx % 2], M([0, 0.012, 0], [0, S(2) * 6, 0], [0.78, 0.03, 0.66]), function (x, y, z, c) { c.set(0x9a9080).multiplyScalar(0.8 + 0.3 * vnoise(x * 6, z * 6)); }));

    // 2. Kaki kayu sedikit mengangkang + palang
    [[-0.42, -0.32], [0.42, -0.32], [-0.42, 0.32], [0.42, 0.32]].forEach(([lx, lz]) => {
      parts.push(part(unitCyl, M([lx * 1.04, 0.2, lz * 1.04], [lz > 0 ? 0.07 : -0.07, 0, lx > 0 ? -0.07 : 0.07], [0.05, 0.3, 0.05]), DARKER));
    });
    box(0, 0.3, -0.32, 0.98, 0.05, 0.06, DARKER); box(0, 0.3, 0.32, 0.98, 0.05, 0.06, DARKER);

    // 3. Papan dasar + papan hinggap miring
    box(0, 0.38, 0, 1.14, 0.06, 0.94, weather(0x8b5a2b, 1));
    box(0, 0.4, 0.55, 0.5, 0.022, 0.26, weather(0xa16207, 2), [0.2, 0, 0]);

    // 4. Tiga kotak sarang — ditumpuk tidak persis lurus, seperti buatan tangan
    const bCols = [0xd97706, 0xf59e0b, 0xfbbf24];
    const boxH = 0.34, boxStartY = 0.42;
    for (let i = 0; i < 3; i++) {
      const curY = boxStartY + (i * boxH) + (boxH / 2);
      const jx = (S(10 + i) - 0.5) * 0.03, jz = (S(20 + i) - 0.5) * 0.03, jr = (S(30 + i) - 0.5) * 0.05;
      parts.push(part(hiveBoxGeo, M([jx, curY, jz], [0, jr, 0]), weather(bCols[i], 3 + i)));
      box(jx, curY + boxH / 2 - 0.008, jz, 1.035, 0.016, 0.835, DARK, [0, jr, 0]);           // celah antar kotak
      box(jx, curY + 0.03, jz + 0.411, 0.22, 0.05, 0.014, DARKER, [0, jr, 0]);                // cekungan pegangan
      box(jx, curY + 0.03, jz - 0.411, 0.22, 0.05, 0.014, DARKER, [0, jr, 0]);
      box(jx + 0.511, curY + 0.03, jz, 0.014, 0.05, 0.2, DARKER, [0, jr, 0]);
      box(jx - 0.511, curY + 0.03, jz, 0.014, 0.05, 0.2, DARKER, [0, jr, 0]);
    }

    // 5. Lubang masuk + bilah pengecil
    box(0, 0.452, 0.413, 0.4, 0.04, 0.02, 0x070504);
    box(-0.3, 0.452, 0.416, 0.18, 0.04, 0.02, DARK); box(0.3, 0.452, 0.416, 0.18, 0.04, 0.02, DARK);

    // 6. Tutup teleskopik + atap pelana dengan teritis
    box(0, 1.475, 0, 1.12, 0.07, 0.92, weather(0x8b4a14, 7));
    parts.push(part(hiveRoofGeo, M([0, 1.51, 0]), weather(0xfbbf24, 8)));
    const ra = Math.atan2(0.3, 0.6), roofCol = weather(0x92400e, 9);
    box(0.345, 1.51 + 0.145, 0, 0.8, 0.032, 1.14, roofCol, [0, 0, -ra]);
    box(-0.345, 1.51 + 0.145, 0, 0.8, 0.032, 1.14, roofCol, [0, 0, ra]);
    box(0, 1.51 + 0.335, 0, 0.09, 0.04, 1.17, DARKER);

    const m = new THREE.Mesh(merge(parts), hiveMat);
    m.castShadow = true; m.receiveShadow = true;
    g.add(m);
    fakeShadow(g, 2.3, 0.03);
    return g;
  }

  // Collect all obstacle positions for collision avoidance
  const obstaclePositions = [];

  HIVES_ARRAY.forEach((hive, idx) => {
    const h = mkBeehive(idx);
    const p = HPOS[idx];
    // Add slight random rotation for organic feel
    const rRot = (seededRandom(idx * 7 + 5) - 0.5) * 0.3;
    h.position.set(p.x, 0, p.z);
    h.rotation.y = rRot;
    h.userData.hiveId = hive.id;
    scene.add(h);
    hive3D.push(h);
    hiveWP.push(new THREE.Vector3(p.x, 2.25, p.z));
    // Register as obstacle (radius ~0.7 for the hive body)
    obstaclePositions.push({ x: p.x, z: p.z, r: 0.85 });
  });
  // Cabin obstacle
  obstaclePositions.push({ x: -3.8, z: -2.5, r: 1.6 });

  // ── LEBAH: terbang bebas — berkerumun di sarang, pergi ke bunga, pulang lagi ──
  const bees = [];
  const beeBodyGeo = new THREE.SphereGeometry(0.06, 8, 6); beeBodyGeo.rotateX(Math.PI / 2); beeBodyGeo.scale(0.82, 0.78, 1.35);
  const beeTex = canvasTex(8, 64, function (x) {
    ['#2a1a08', '#2a1a08', '#f5a623', '#1a1208', '#f7b63a', '#1a1208', '#f5a623', '#1a1208'].forEach((c, i) => { x.fillStyle = c; x.fillRect(0, i * 8, 8, 8); });
  });
  const beeBodyMat = new THREE.MeshStandardMaterial({ map: beeTex, roughness: 0.6 });
  const wingGeo = new THREE.PlaneGeometry(0.12, 0.06); wingGeo.rotateX(-Math.PI / 2); wingGeo.translate(0.06, 0, -0.01);
  const wingMat = new THREE.MeshBasicMaterial({ color: 0xffffff, transparent: true, opacity: 0.5, side: THREE.DoubleSide, depthWrite: false });
  function mkBee(home, startX, startZ) {
    const g = new THREE.Group();
    g.add(new THREE.Mesh(beeBodyGeo, beeBodyMat));
    const wR = new THREE.Mesh(wingGeo, wingMat); wR.position.set(0.02, 0.04, 0); g.add(wR);
    const wL = new THREE.Mesh(wingGeo, wingMat); wL.position.set(-0.02, 0.04, 0); wL.scale.x = -1; g.add(wL);
    g.scale.setScalar(1.2);
    g.userData = {
      wL: wL, wR: wR, home: home, ph: rnd() * 6.28, st: 0, n: 2 + Math.floor(rnd() * 8), timer: 0, maxSpd: 1.3,
      pos: new THREE.Vector3(startX, 0.9 + rnd(), startZ), vel: new THREE.Vector3(), target: new THREE.Vector3(startX, 1.2, startZ), flower: null
    };
    g.position.copy(g.userData.pos);
    scene.add(g); bees.push(g);
  }
  HIVES_ARRAY.forEach((hive, idx) => {
    const bc = parseInt(hive.bee_count) || 0;
    if (bc <= 0) return;
    const p = HPOS[idx], count = Math.min(bc, isMobile ? 4 : 6);
    for (let i = 0; i < count; i++) mkBee({ x: p.x, z: p.z }, p.x + rr(-0.8, 0.8), p.z + rr(-0.3, 1.2));
  });
  for (let i = 0; i < (isMobile ? 3 : 6); i++) mkBee(null, rr(-6, 6), rr(-4, 5));     // lebah liar

  function pickFlyTarget(d) {
    const anyFlower = flowerPatches.length ? flowerPatches[Math.floor(Math.random() * flowerPatches.length)] : null;
    if (!d.home) {                                   // pengembara: dari rumpun bunga ke rumpun bunga
      if (anyFlower && Math.random() < 0.7) d.target.set(anyFlower.x + (Math.random() - 0.5), anyFlower.y + Math.random() * 0.4, anyFlower.z + (Math.random() - 0.5));
      else d.target.set((Math.random() - 0.5) * 16, 0.6 + Math.random() * 2.4, (Math.random() - 0.5) * 12);
      d.timer = 1.5 + Math.random() * 4; d.maxSpd = 1.2 + Math.random() * 1.4;
      return;
    }
    if (d.st === 0) {                                // berkerumun di depan sarang
      if (--d.n <= 0 && anyFlower) { d.st = 1; d.flower = anyFlower; d.target.copy(anyFlower); d.timer = 14; d.maxSpd = 2.7; }
      else { d.target.set(d.home.x + (Math.random() - 0.5) * 1.8, 0.5 + Math.random() * 1.4, d.home.z + 0.3 + (Math.random() - 0.5) * 1.7); d.timer = 0.35 + Math.random() * 0.9; d.maxSpd = 1.4; }
    } else if (d.st === 1) {                         // tiba di bunga
      d.st = 2; d.n = 3 + Math.floor(Math.random() * 5); pickFlyTarget(d);
    } else if (d.st === 2) {                         // mengisap nektar, pindah-pindah bunga
      if (--d.n <= 0) { d.st = 3; d.target.set(d.home.x, 0.52, d.home.z + 0.55); d.timer = 14; d.maxSpd = 2.7; }
      else { d.target.set(d.flower.x + (Math.random() - 0.5) * 1.2, d.flower.y + Math.random() * 0.2 - 0.05, d.flower.z + (Math.random() - 0.5) * 1.2); d.timer = 0.6 + Math.random() * 1.2; d.maxSpd = 0.7; }
    } else {                                         // sampai di sarang
      d.st = 0; d.n = 4 + Math.floor(Math.random() * 8); pickFlyTarget(d);
    }
  }
  const _fv = new THREE.Vector3();
  function updFlyer(o, t, dt, flutter) {
    const d = o.userData;
    d.timer -= dt;
    if (d.timer <= 0 || d.pos.distanceToSquared(d.target) < 0.03) pickFlyTarget(d);
    _fv.copy(d.target).sub(d.pos);
    const dist = _fv.length() || 0.001;
    _fv.multiplyScalar(d.maxSpd * Math.min(1, dist / 0.5 + 0.25) / dist);
    d.vel.lerp(_fv, Math.min(1, dt * (flutter ? 1.6 : 4.5)));
    d.pos.addScaledVector(d.vel, dt);
    if (d.pos.y < 0.15) d.pos.y = 0.15;
    o.position.copy(d.pos);
    if (flutter) {                                   // kupu-kupu: naik-turun tak menentu
      o.position.y += Math.sin(t * 6.5 + d.ph) * 0.09 + Math.sin(t * 2.3 + d.ph * 2) * 0.12;
      o.position.x += Math.sin(t * 1.7 + d.ph) * 0.1;
    } else {                                         // lebah: getar halus
      o.position.x += Math.sin(t * 11 + d.ph) * 0.02; o.position.y += Math.sin(t * 13.7 + d.ph * 2) * 0.025; o.position.z += Math.cos(t * 9.3 + d.ph) * 0.02;
    }
    if (d.vel.x * d.vel.x + d.vel.z * d.vel.z > 0.004) {
      let dy = Math.atan2(d.vel.x, d.vel.z) - o.rotation.y;
      while (dy > Math.PI) dy -= Math.PI * 2; while (dy < -Math.PI) dy += Math.PI * 2;
      o.rotation.y += dy * Math.min(1, dt * 8);
      o.rotation.z = -dy * 0.5;
    }
    o.rotation.x = -d.vel.y * 0.25;
  }

  // ── KUPU-KUPU ──
  const bflies = [];
  (function () {
    const ws = new THREE.Shape();
    ws.moveTo(0, 0.02); ws.bezierCurveTo(0.03, 0.1, 0.14, 0.12, 0.15, 0.05); ws.bezierCurveTo(0.15, 0.0, 0.06, -0.01, 0.04, -0.02);
    ws.bezierCurveTo(0.1, -0.04, 0.1, -0.11, 0.05, -0.1); ws.bezierCurveTo(0.02, -0.09, 0, -0.05, 0, 0.02);
    const g2 = new THREE.ShapeGeometry(ws, 5); g2.rotateX(-Math.PI / 2);
    const bodyGeo = new THREE.CylinderGeometry(0.008, 0.006, 0.13, 4); bodyGeo.rotateX(Math.PI / 2);
    const bodyMat = new THREE.MeshBasicMaterial({ color: 0x2a1c12 });
    const cols = [0xff69b4, 0xffa500, 0x7ec8ff, 0xffd700, 0xb07bff, 0xffffff, 0xff7a3d];
    for (let i = 0; i < (isMobile ? 4 : 7); i++) {
      const g = new THREE.Group();
      const wm = new THREE.MeshLambertMaterial({ color: cols[i % cols.length], side: THREE.DoubleSide, emissive: cols[i % cols.length], emissiveIntensity: 0.15 });
      const wR = new THREE.Mesh(g2, wm); g.add(wR);
      const wL = new THREE.Mesh(g2, wm); wL.scale.x = -1; g.add(wL);
      g.add(new THREE.Mesh(bodyGeo, bodyMat));
      const sx = rr(-7, 7), sz = rr(-5, 6);
      g.userData = { wL: wL, wR: wR, home: null, ph: rnd() * 6.28, st: 0, n: 0, timer: 0, maxSpd: 0.8, pos: new THREE.Vector3(sx, 1, sz), vel: new THREE.Vector3(), target: new THREE.Vector3(sx, 1, sz), flower: null, slow: rr(0.4, 0.75) };
      g.scale.setScalar(rr(1.0, 1.5));
      scene.add(g); bflies.push(g);
    }
  })();

  // ── BURUNG melintas ──
  const birds = [];
  (function () {
    const wg = new THREE.BufferGeometry();
    wg.setAttribute('position', new THREE.Float32BufferAttribute([0, 0, 0.1, 0, 0, -0.08, 0.5, 0, -0.03], 3));
    const bm = new THREE.MeshBasicMaterial({ color: 0x2b3440, side: THREE.DoubleSide });
    const bodyGeo = new THREE.ConeGeometry(0.05, 0.34, 4); bodyGeo.rotateX(Math.PI / 2);
    for (let i = 0; i < (isMobile ? 3 : 5); i++) {
      const g = new THREE.Group();
      const wR = new THREE.Mesh(wg, bm); g.add(wR);
      const wL = new THREE.Mesh(wg, bm); wL.scale.x = -1; g.add(wL);
      g.add(new THREE.Mesh(bodyGeo, bm));
      g.userData = { wL: wL, wR: wR, a: i * 0.22 + (i > 2 ? 3 : 0), r: 15 + i * 1.3, h: 7.5 + (i % 3) * 0.9, spd: 0.13 + (i > 2 ? 0.03 : 0), ph: i * 1.9 };
      scene.add(g); birds.push(g);
    }
  })();

  // ── DAUN GUGUR terbawa angin ──
  const leaves = [];
  (function () {
    if (!leafSources.length) return;
    const lg = new THREE.PlaneGeometry(0.09, 0.055);
    const mats = [0xb5d65a, 0xe2c044, 0xd98a2b, 0x7fc85c].map(c => new THREE.MeshLambertMaterial({ color: c, side: THREE.DoubleSide }));
    for (let i = 0; i < (isMobile ? 6 : 16); i++) {
      const m = new THREE.Mesh(lg, mats[i % mats.length]);
      m.userData = { ph: rnd() * 6.28, wait: rnd() * 9, fall: rr(0.35, 0.6) };
      m.visible = false; scene.add(m); leaves.push(m);
    }
  })();

  // ── AWAN: gumpalan lembut, putih di atas, kebiruan di bawah ──
  const clouds = [];
  const cloudMat = new THREE.MeshLambertMaterial({ vertexColors: true, emissive: 0x9fb4cc, emissiveIntensity: 0.45, transparent: true, opacity: 0.93 });
  function mkCloud(x, y, z, s) {
    const parts = [], n = isMobile ? 5 : 8, cTop = new THREE.Color(0xffffff), cBot = new THREE.Color(0xb9c9de);
    const colFn = function (px, py, pz, c) { c.copy(cBot).lerp(cTop, sstep(-0.35 * s, 0.35 * s, py)); };
    for (let i = 0; i < n; i++) {
      const t = i / (n - 1) - 0.5, r = (0.5 + 0.45 * (1 - Math.abs(t) * 1.6) + rr(-0.08, 0.12)) * s;
      parts.push(part(blobVars[i % 3], M([t * 2.6 * s + rr(-0.15, 0.15) * s, rr(-0.05, 0.22) * s, rr(-0.45, 0.45) * s], [rr(0, 3), rr(0, 3), 0], [r * 1.15, r * 0.72, r]), colFn));
    }
    const m = new THREE.Mesh(merge(parts), cloudMat);
    m.position.set(x, y, z); m.userData = { speed: 0.5 + rnd() * 0.6, y: y, ph: rnd() * 6.28 };
    scene.add(m); return m;
  }
  [[-12,14,-22,1.5],[8,15,-28,1.7],[18,13,-20,1.3],[-6,17,-32,2.0],[24,12,-16,1.1],[-20,14,-25,1.4],[0,18,-38,2.2],[-30,15,-12,1.6],[32,16,-30,1.9],[-36,13,-30,1.5]]
    .slice(0, isMobile ? 6 : 10).forEach(([x,y,z,s]) => clouds.push(mkCloud(x,y,z,s)));

  // ── SERBUK SARI melayang ──
  const pGeo = new THREE.BufferGeometry(), pCnt = isMobile ? 45 : 90, pPos = new Float32Array(pCnt*3);
  for (let i = 0; i < pCnt; i++) { pPos[i*3]=(Math.random()-0.5)*22; pPos[i*3+1]=0.3+Math.random()*5; pPos[i*3+2]=(Math.random()-0.5)*20; }
  pGeo.setAttribute('position', new THREE.BufferAttribute(pPos, 3));
  scene.add(new THREE.Points(pGeo, new THREE.PointsMaterial({ color: 0xfff0a0, map: softDotTex, size: 0.11, transparent: true, opacity: 0.7, depthWrite: false })));

  // ══════════════════════════════════════════════════════════
  // GUBUK KAYU PETERNAK (RUSTIC TIMBER CABIN & DETAILED SCENE)
  // ══════════════════════════════════════════════════════════
  const chimneySmoke = [];
  let cabinLanternLight = null;
  function createRusticCabin() {
    const cabin = new THREE.Group();

    const stoneMat = new THREE.MeshStandardMaterial({ color: 0x57534e, roughness: 0.95 });
    const woodDarkMat = new THREE.MeshStandardMaterial({ color: 0x5c2d0e, roughness: 0.85 });
    const woodPlankMat = new THREE.MeshStandardMaterial({ color: 0x9a6634, map: woodTex, roughness: 0.8 });
    const woodLogMat = new THREE.MeshStandardMaterial({ color: 0xb4721a, map: logTex, roughness: 0.78 });
    const roofShingleMat = new THREE.MeshStandardMaterial({ color: 0x8a4f1c, map: roofTex, roughness: 0.8 });

    // 6 Foundation Pillars
    [[-1.6, -1.2], [0, -1.2], [1.6, -1.2], [-1.6, 1.2], [0, 1.2], [1.6, 1.2]].forEach(([px, pz]) => {
      const pier = new THREE.Mesh(new THREE.BoxGeometry(0.42, 0.32, 0.42), stoneMat);
      pier.position.set(px, 0.16, pz);
      pier.castShadow = true; pier.receiveShadow = true;
      cabin.add(pier);
    });

    // Timber Foundation Beams
    const fBeam1 = new THREE.Mesh(new THREE.BoxGeometry(3.8, 0.16, 0.22), woodDarkMat);
    fBeam1.position.set(0, 0.38, -1.2); cabin.add(fBeam1);
    const fBeam2 = new THREE.Mesh(new THREE.BoxGeometry(3.8, 0.16, 0.22), woodDarkMat);
    fBeam2.position.set(0, 0.38, 1.2); cabin.add(fBeam2);

    // Floor Deck
    const deck = new THREE.Mesh(new THREE.BoxGeometry(4.2, 0.14, 3.6), woodPlankMat);
    deck.position.set(0, 0.52, 0.2);
    deck.castShadow = true; deck.receiveShadow = true;
    cabin.add(deck);

    // Main Log Walls
    const wallBody = new THREE.Mesh(new THREE.BoxGeometry(3.4, 2.0, 2.4), woodLogMat);
    wallBody.position.set(0, 1.55, -0.2);
    wallBody.castShadow = true; wallBody.receiveShadow = true;
    cabin.add(wallBody);

    // Horizontal Log Seams
    for (let i = 0; i < 6; i++) {
      const gY = 0.75 + i * 0.32;
      const rimF = new THREE.Mesh(new THREE.BoxGeometry(3.46, 0.04, 2.46), woodDarkMat);
      rimF.position.set(0, gY, -0.2);
      cabin.add(rimF);
    }

    // Doorway & Rustic Door
    const doorFrame = new THREE.Mesh(new THREE.BoxGeometry(0.9, 1.62, 0.1), woodDarkMat);
    doorFrame.position.set(0.35, 1.36, 1.02);
    cabin.add(doorFrame);

    const door = new THREE.Mesh(new THREE.BoxGeometry(0.78, 1.52, 0.05), new THREE.MeshStandardMaterial({ color: 0x451a03, roughness: 0.8 }));
    door.position.set(0.35, 1.34, 1.04);
    cabin.add(door);

    // Brass door handle
    const handle = new THREE.Mesh(new THREE.SphereGeometry(0.035, 6, 6), new THREE.MeshStandardMaterial({ color: 0xfbbf24, metalness: 0.8, roughness: 0.2 }));
    handle.position.set(0.65, 1.32, 1.08);
    cabin.add(handle);

    // Horseshoe good luck above door
    const shoe = new THREE.Mesh(new THREE.TorusGeometry(0.08, 0.02, 4, 8, Math.PI), new THREE.MeshStandardMaterial({ color: 0x1e293b, metalness: 0.9 }));
    shoe.position.set(0.35, 2.22, 1.05); shoe.rotation.z = Math.PI;
    cabin.add(shoe);

    // Warm Glowing Window
    const winFrame = new THREE.Mesh(new THREE.BoxGeometry(0.75, 0.75, 0.1), woodDarkMat);
    winFrame.position.set(-0.95, 1.55, 1.02);
    cabin.add(winFrame);

    const winGlass = new THREE.Mesh(new THREE.BoxGeometry(0.65, 0.65, 0.04), new THREE.MeshStandardMaterial({ color: 0xfef08a, emissive: 0xf59e0b, emissiveIntensity: 0.7 }));
    winGlass.position.set(-0.95, 1.55, 1.04);
    cabin.add(winGlass);

    // Window cross muntins
    const winCrossH = new THREE.Mesh(new THREE.BoxGeometry(0.65, 0.03, 0.05), woodDarkMat);
    winCrossH.position.set(-0.95, 1.55, 1.06); cabin.add(winCrossH);
    const winCrossV = new THREE.Mesh(new THREE.BoxGeometry(0.03, 0.65, 0.05), woodDarkMat);
    winCrossV.position.set(-0.95, 1.55, 1.06); cabin.add(winCrossV);

    // Front Porch Pillars
    [-1.4, 0, 1.4].forEach(px => {
      const post = new THREE.Mesh(new THREE.CylinderGeometry(0.065, 0.075, 2.0, 6), woodDarkMat);
      post.position.set(px, 1.58, 1.6);
      post.castShadow = true;
      cabin.add(post);
    });

    // Porch Railings
    const railTopL = new THREE.Mesh(new THREE.BoxGeometry(1.3, 0.06, 0.06), woodDarkMat);
    railTopL.position.set(-0.7, 1.15, 1.6); cabin.add(railTopL);
    for (let b = 0; b < 4; b++) {
      const bal = new THREE.Mesh(new THREE.CylinderGeometry(0.02, 0.02, 0.5, 4), woodDarkMat);
      bal.position.set(-1.25 + b * 0.36, 0.9, 1.6); cabin.add(bal);
    }

    // Wooden Steps
    for (let s = 0; s < 2; s++) {
      const step = new THREE.Mesh(new THREE.BoxGeometry(1.0, 0.12, 0.34), woodPlankMat);
      step.position.set(0.35, 0.38 - s * 0.18, 1.95 + s * 0.3);
      step.castShadow = true; step.receiveShadow = true;
      cabin.add(step);
    }

    // Porch Hanging Lantern
    const lanternG = new THREE.Group();
    const lBody = new THREE.Mesh(new THREE.CylinderGeometry(0.06, 0.08, 0.18, 6), new THREE.MeshStandardMaterial({ color: 0x1f2937, metalness: 0.8 }));
    const lGlow = new THREE.Mesh(new THREE.SphereGeometry(0.05, 8, 8), new THREE.MeshStandardMaterial({ color: 0xfffbeb, emissive: 0xfbbf24, emissiveIntensity: 1.0 }));
    lanternG.add(lBody); lanternG.add(lGlow);
    const lLight = new THREE.PointLight(0xf59e0b, 0.85, 5);
    lanternG.add(lLight);
    cabinLanternLight = lLight;
    lanternG.position.set(0.35, 2.38, 1.6);
    cabin.add(lanternG);

    // Porch Signboard
    const sign = new THREE.Mesh(new THREE.BoxGeometry(1.1, 0.22, 0.04), woodDarkMat);
    sign.position.set(0.35, 2.45, 1.65);
    cabin.add(sign);

    // Pitched Roof
    // Atap pelana (gable) dengan teritis menaungi teras
    const roofGeo = prismGeo(2.05, 1.25, 4.5); roofGeo.rotateY(Math.PI / 2);
    const roofMain = new THREE.Mesh(roofGeo, roofShingleMat);
    roofMain.position.set(0, 2.55, 0.2);
    roofMain.castShadow = true;
    const roofRidge = new THREE.Mesh(new THREE.BoxGeometry(4.6, 0.07, 0.16), woodDarkMat);
    roofRidge.position.set(0, 3.8, 0.2); cabin.add(roofRidge);
    cabin.add(roofMain);

    // Stone Chimney
    const chimney = new THREE.Mesh(new THREE.BoxGeometry(0.65, 3.6, 0.65), stoneMat);
    chimney.position.set(1.5, 2.2, -0.6);
    chimney.castShadow = true;
    cabin.add(chimney);

    const chimneyCap = new THREE.Mesh(new THREE.BoxGeometry(0.78, 0.12, 0.78), woodDarkMat);
    chimneyCap.position.set(1.5, 4.05, -0.6);
    cabin.add(chimneyCap);

    // Chimney Smoke Puffs
    for (let sm = 0; sm < 8; sm++) {
      const sp = new THREE.Mesh(
        new THREE.SphereGeometry(0.12 + sm * 0.03, 6, 5),
        new THREE.MeshStandardMaterial({ color: 0xe2e8f0, transparent: true, opacity: 0.45 })
      );
      sp.position.set(1.5, 4.2 + sm * 0.29, -0.6);
      sp.userData = { initialY: 4.2, speed: 0.015 + Math.random() * 0.01, seed: sm };
      cabin.add(sp);
      chimneySmoke.push(sp);
    }

    // Firewood Stack (Tumpukan Kayu Bakar)
    const logMat = new THREE.MeshStandardMaterial({ color: 0x78350f, roughness: 0.85 });
    for (let r = 0; r < 3; r++) {
      for (let c = 0; c < 4 - r; c++) {
        const log = new THREE.Mesh(new THREE.CylinderGeometry(0.09, 0.09, 0.95, 6), logMat);
        log.rotation.z = Math.PI / 2;
        log.position.set(1.5, 0.65 + r * 0.16, -1.35 + (c + r * 0.5) * 0.2);
        log.castShadow = true;
        cabin.add(log);
      }
    }

    // Beekeeper Workbench & Honey Jars on Porch
    const tableTop = new THREE.Mesh(new THREE.BoxGeometry(0.65, 0.06, 0.4), woodDarkMat);
    tableTop.position.set(-1.1, 0.9, 1.2); cabin.add(tableTop);
    [[-1.35, 1.05], [-0.85, 1.05], [-1.35, 1.35], [-0.85, 1.35]].forEach(([tx, tz]) => {
      const leg = new THREE.Mesh(new THREE.CylinderGeometry(0.02, 0.02, 0.35, 4), woodDarkMat);
      leg.position.set(tx, 0.72, tz); cabin.add(leg);
    });
    const jarMat = new THREE.MeshStandardMaterial({ color: 0xf59e0b, roughness: 0.15, transparent: true, opacity: 0.88 });
    [-0.2, 0, 0.2].forEach((jx, idx) => {
      const jar = new THREE.Mesh(new THREE.CylinderGeometry(0.045, 0.05, 0.11, 6), jarMat);
      jar.position.set(-1.1 + jx * 0.4, 0.98, 1.2);
      cabin.add(jar);
    });

    // 2 Wooden Rain Barrels (Tong Air Kayu)
    [-0.5, 0.35].forEach((bx, bi) => {
      const barrel = new THREE.Mesh(new THREE.CylinderGeometry(0.28, 0.25, 0.7, 8), woodPlankMat);
      barrel.position.set(-1.95, 0.35, -0.6 + bx);
      barrel.castShadow = true; cabin.add(barrel);
      [-0.18, 0.18].forEach(hy => {
        const hoop = new THREE.Mesh(new THREE.TorusGeometry(0.28, 0.015, 4, 8), new THREE.MeshStandardMaterial({ color: 0x1f2937, metalness: 0.8 }));
        hoop.rotation.x = Math.PI / 2;
        hoop.position.set(-1.95, 0.35 + hy, -0.6 + bx);
        cabin.add(hoop);
      });
    });

    cabin.position.set(-3.8, 0, -2.5);
    cabin.rotation.y = Math.PI * 0.12;
    cabin.scale.set(0.88, 0.88, 0.88);
    scene.add(cabin);
    return cabin;
  }
  createRusticCabin();

  // ── JALAN SETAPAK: batu pijakan tak beraturan di atas tanah yang terinjak ──
  function createMeadowPath() {
    const parts = [];
    PATH_PTS.forEach(([px, pz], i) => {
      const s = rr(0.26, 0.38), tone = rr(0.8, 1.1);
      parts.push(part(rockVars[i % 2], M([px + rr(-0.1, 0.1), 0.012, pz + rr(-0.1, 0.1)], [0, rr(0, 6.28), 0], [s * rr(0.9, 1.25), 0.05, s * rr(0.8, 1.1)]), function (x, y, z, c) {
        c.set(0xa3a8a6).multiplyScalar(tone * (0.8 + 0.3 * vnoise(x * 8, z * 8)));
      }));
      if (i % 3 === 1) parts.push(part(rockVars[0], M([px + rr(0.3, 0.45), 0.01, pz + rr(-0.3, 0.3)], [0, rr(0, 6), 0], [0.12, 0.035, 0.1]), 0x8f948f));
    });
    const m = new THREE.Mesh(merge(parts), new THREE.MeshStandardMaterial({ vertexColors: true, roughness: 0.95 }));
    m.receiveShadow = !isMobile; m.castShadow = !isMobile;
    scene.add(m);
  }
  createMeadowPath();

  // ── PAGAR KAYU PEDESAAN: tiang agak miring, palang tidak persis sejajar ──
  function createFences() {
    const fencePosts = [
      [-12.2, 0.8], [-11.6, -2.2], [-10, -5], [-7.5, -5.5], [-5, -6], [-2.5, -6.2], [0, -6.5], [2.5, -6.2], [5, -6], [7.5, -5.5], [10, -5], [11.6, -2.2], [12.2, 0.8]
    ];
    const parts = [], tops = [], postGeo = new THREE.CylinderGeometry(0.8, 1, 1, 6);
    const tint = function (hex, seed) { const b = new THREE.Color(hex); return function (x, y, z, c) { c.copy(b).multiplyScalar(0.78 + 0.4 * vnoise(x * 3 + seed, y * 6 + z * 3)); }; };
    fencePosts.forEach(([fx, fz], i) => {
      const gy = getGroundElevation(fx, fz), hgt = rr(1.0, 1.2), tx = rr(-0.07, 0.07), tz = rr(-0.07, 0.07);
      parts.push(part(postGeo, M([fx, gy + hgt / 2 - 0.08, fz], [tx, rr(0, 3), tz], [0.085, hgt, 0.085]), tint(0x8a5a2c, i)));
      tops.push(new THREE.Vector3(fx, gy, fz));
    });
    for (let i = 0; i < tops.length - 1; i++) {
      [0.38, 0.76].forEach((ry, k) => {
        const a = tops[i].clone(), b = tops[i + 1].clone();
        a.y += ry + rr(-0.05, 0.05); b.y += ry + rr(-0.05, 0.05);
        const dir = b.clone().sub(a).normalize(); a.addScaledVector(dir, -0.12); b.addScaledVector(dir, 0.12);
        parts.push(part(unitBox, Mseg(a, b, 0.065), tint(k ? 0xa2672f : 0x93571f, i * 2 + k)));
      });
    }
    const m = new THREE.Mesh(merge(parts), new THREE.MeshStandardMaterial({ vertexColors: true, map: barkTex, roughness: 0.9 }));
    m.castShadow = !isMobile; m.receiveShadow = !isMobile;
    scene.add(m);
  }
  createFences();

  // ── BUNGA MATAHARI: batang melengkung, kepala menghadap matahari, mengangguk ditiup angin ──
  function createSunflowers() {
    const allSpots = [
      [-9.2, -3.8, 2.1], [-8.6, -4.5, 1.8], [-7.7, -3.9, 1.6], [-6.2, -4.7, 2.3], [-5.3, -5.2, 1.9], [-1.4, -5.4, 2.0], [-0.4, -5.7, 1.7],
      [3.2, -5.0, 1.9], [4.3, -5.3, 2.3], [5.5, -4.8, 2.2], [6.3, -4.9, 1.7], [7.0, -4.2, 2.4], [8.4, -3.5, 1.8], [9.3, -3.9, 2.1]
    ];
    const spots = isMobile ? allSpots.filter((_, i) => i % 3 === 0) : allSpots;
    const mat = new THREE.MeshStandardMaterial({ vertexColors: true, roughness: 0.7, side: THREE.DoubleSide });
    const sph = new THREE.SphereGeometry(1, 6, 4), petalCount = isMobile ? 10 : 15;
    const STEM = new THREE.Color(0x2f9a45), LEAF = new THREE.Color(0x1f8a3c), PET_A = new THREE.Color(0xffc21a), PET_B = new THREE.Color(0xff9d0a);
    const sunYaw = Math.atan2(sun.position.x, sun.position.z);

    spots.forEach(([sx, sz, sh], si) => {
      const g = new THREE.Group(), bend = rr(0.1, 0.28);
      // batang + daun (1 mesh)
      const sg = new THREE.CylinderGeometry(0.03, 0.05, sh, 5, 5); sg.translate(0, sh / 2, 0);
      const sp = sg.attributes.position;
      for (let i = 0; i < sp.count; i++) { const t = sp.getY(i) / sh; sp.setZ(i, sp.getZ(i) + bend * t * t); }
      sg.computeVertexNormals();
      const parts = [part(sg, null, function (x, y, z, c) { c.copy(STEM).multiplyScalar(0.8 + 0.3 * y / sh); })];
      const leafCount = isMobile ? 3 : 5;
      for (let l = 0; l < leafCount; l++) {
        const t = 0.22 + l * (0.6 / leafCount), ly = t * sh, la = l * 2.4 + si, lr = 0.2 + (1 - t) * 0.12;
        parts.push(part(sph, M([Math.cos(la) * lr, ly, bend * t * t + Math.sin(la) * lr], [0.25, -la, -0.35], [lr * 1.25, 0.02, lr * 0.75]), function (x, y, z, c) { c.copy(LEAF).multiplyScalar(0.8 + 0.5 * vnoise(x * 6, z * 6)); }));
      }
      g.add(shadow(new THREE.Mesh(merge(parts), mat)));

      // kepala bunga (1 mesh): dua lingkar kelopak + cakram biji + kelopak hijau belakang
      const hp = [];
      for (let ring = 0; ring < 2; ring++) for (let p = 0; p < petalCount; p++) {
        const a = (p / petalCount) * 6.283 + ring * (Math.PI / petalCount), rad = 0.3 - ring * 0.04;
        hp.push(part(sph, M([Math.cos(a) * rad, Math.sin(a) * rad, 0.01 + ring * 0.015], [0, 0, a], [0.15, 0.045, 0.012]), function (x, y, z, c) { c.copy(PET_A).lerp(PET_B, sstep(0.36, 0.16, Math.hypot(x, y)) + ring * 0.15); }));
      }
      hp.push(part(sph, M([0, 0, 0.02], null, [0.19, 0.19, 0.05]), function (x, y, z, c) { const r = Math.hypot(x, y); c.set(0x3a1d06).lerp(new THREE.Color(0x8a5a14), sstep(0.06, 0.18, r) * (0.5 + 0.5 * vnoise(x * 60, y * 60))); }));
      hp.push(part(sph, M([0, 0, -0.03], null, [0.24, 0.24, 0.04]), 0x2c8a3c));
      const head = new THREE.Group();
      head.position.set(0, sh, bend);
      const hm = shadow(new THREE.Mesh(merge(hp), mat)); head.add(hm);
      g.add(head);

      g.position.set(sx, getGroundElevation(sx, sz) - 0.03, sz);
      g.rotation.y = sunYaw + rr(-0.35, 0.35);           // semua menghadap matahari
      head.rotation.x = -0.25;
      const sc = rr(0.9, 1.1); g.scale.setScalar(sc);
      scene.add(g);
      swayers.push({ o: g, x: sx, z: sz, amp: 0.045, ph: rnd() * 6.28 });
      swayers.push({ o: head, x: sx + 3, z: sz, amp: 0.09, ph: rnd() * 6.28, baseX: -0.25 });
      flowerPatches.push(new THREE.Vector3(sx, sh * sc + 0.1, sz + 0.3));
    });
  }
  createSunflowers();

  // ── 3D PETERNAK LEBAH (BEEKEEPER CHARACTER) ──
  let beekeeperMesh = null;
  const smokerPuffParticles = [];
  const honeySparkles = [];

  // Beekeeper state machine
  const BK_STATE = { IDLE: 0, WALKING: 1, PAUSING: 2, HARVESTING: 3 };
  let bkState = BK_STATE.IDLE;
  let bkWalkTarget = new THREE.Vector3(-1.5, 0, 0.5);
  let bkWalkCycle = 0;
  let bkPauseTimer = 0;
  let bkIdleAction = 0; // 0=breathe, 1=lookAround, 2=checkSmoker
  let bkHomePos = new THREE.Vector3(-1.5, 0, 0.5);

  // Wandering waypoints (along the stone path, around but NOT through hives/cabin)
  const bkWaypoints = [
    new THREE.Vector3(-1.5, 0, 2.4),
    new THREE.Vector3(0.4, 0, 2.8),
    new THREE.Vector3(1.4, 0, 2.5),
    new THREE.Vector3(2.4, 0, 2.1),
    new THREE.Vector3(-2.4, 0, 1.5),
    new THREE.Vector3(-0.6, 0, 2.6),
    new THREE.Vector3(3.4, 0, 1.8),
    new THREE.Vector3(-3.2, 0, 0.6),
    new THREE.Vector3(-4.0, 0, -0.4),
    new THREE.Vector3(0.0, 0, 3.5)
  ];

  // Harvest sequence state
  let harvestPhase = 0; // 0=walkToHive, 1=smoking, 2=extracting, 3=collecting, 4=walkBack
  let harvestProgress = 0;
  let harvestTargetPos = new THREE.Vector3();
  let harvestReturnPos = new THREE.Vector3();
  let harvestHoneyAmount = 0;
  let harvestProgressDiv = null;

  function createBeekeeper() {
    const bk = new THREE.Group();

    const suitMat = new THREE.MeshStandardMaterial({ color: 0xf8fafc, roughness: 0.7 });
    const bootMat = new THREE.MeshStandardMaterial({ color: 0x451a03, roughness: 0.8 });
    const gloveMat = new THREE.MeshStandardMaterial({ color: 0xf59e0b, roughness: 0.6 });
    const veilMat = new THREE.MeshStandardMaterial({ color: 0x334155, transparent: true, opacity: 0.5, side: THREE.DoubleSide });
    const metalMat = new THREE.MeshStandardMaterial({ color: 0xcbd5e1, metalness: 0.85, roughness: 0.25 });
    const woodMat = new THREE.MeshStandardMaterial({ color: 0x78350f, roughness: 0.8 });

    // Boots (pivoted for walking)
    const legLGroup = new THREE.Group();
    legLGroup.position.set(-0.14, 0.16, 0);
    const bootL = new THREE.Mesh(new THREE.BoxGeometry(0.13, 0.16, 0.22), bootMat);
    bootL.position.set(0, -0.08, 0.03);
    bootL.castShadow = true;
    const legL = new THREE.Mesh(new THREE.CylinderGeometry(0.085, 0.075, 0.55, 6), suitMat);
    legL.position.set(0, 0.26, 0);
    legL.castShadow = true;
    legLGroup.add(bootL); legLGroup.add(legL);
    bk.add(legLGroup);

    const legRGroup = new THREE.Group();
    legRGroup.position.set(0.14, 0.16, 0);
    const bootR = new THREE.Mesh(new THREE.BoxGeometry(0.13, 0.16, 0.22), bootMat);
    bootR.position.set(0, -0.08, 0.03);
    bootR.castShadow = true;
    const legR = new THREE.Mesh(new THREE.CylinderGeometry(0.085, 0.075, 0.55, 6), suitMat);
    legR.position.set(0, 0.26, 0);
    legR.castShadow = true;
    legRGroup.add(bootR); legRGroup.add(legR);
    bk.add(legRGroup);

    // Torso / White Apiary Suit
    const torso = new THREE.Mesh(new THREE.CylinderGeometry(0.24, 0.22, 0.65, 8), suitMat);
    torso.position.y = 0.95;
    torso.castShadow = true; bk.add(torso);

    // Leather Tool Belt
    const belt = new THREE.Mesh(new THREE.CylinderGeometry(0.25, 0.25, 0.08, 8), woodMat);
    belt.position.y = 0.72;
    bk.add(belt);

    // Head
    const head = new THREE.Mesh(new THREE.SphereGeometry(0.16, 8, 8), new THREE.MeshStandardMaterial({ color: 0xfde047 }));
    head.position.y = 1.42;
    bk.add(head);

    // Beekeeper Hat
    const hatBrim = new THREE.Mesh(new THREE.CylinderGeometry(0.42, 0.42, 0.03, 16), new THREE.MeshStandardMaterial({ color: 0xfef3c7 }));
    hatBrim.position.y = 1.55;
    bk.add(hatBrim);

    const hatTop = new THREE.Mesh(new THREE.CylinderGeometry(0.24, 0.26, 0.24, 12), new THREE.MeshStandardMaterial({ color: 0xfef3c7 }));
    hatTop.position.y = 1.68;
    bk.add(hatTop);

    // Protective Face Mesh / Veil Netting
    const veil = new THREE.Mesh(new THREE.CylinderGeometry(0.28, 0.32, 0.44, 12), veilMat);
    veil.position.y = 1.35;
    bk.add(veil);

    // Left Arm (pivoted for swing)
    const armLGroup = new THREE.Group();
    armLGroup.position.set(-0.32, 1.15, 0);
    const armL = new THREE.Mesh(new THREE.CylinderGeometry(0.065, 0.055, 0.52, 6), suitMat);
    armL.position.set(0, -0.2, 0);
    armLGroup.add(armL);
    const gloveL = new THREE.Mesh(new THREE.SphereGeometry(0.075, 6, 6), gloveMat);
    gloveL.position.set(0, -0.47, 0);
    armLGroup.add(gloveL);
    armLGroup.rotation.z = 0.18;
    bk.add(armLGroup);

    // Right Arm (Holding Smoker tool)
    const armRGroup = new THREE.Group();
    armRGroup.position.set(0.32, 1.15, 0);

    const armR = new THREE.Mesh(new THREE.CylinderGeometry(0.065, 0.055, 0.48, 6), suitMat);
    armR.position.set(0.06, -0.22, 0.12);
    armR.rotation.x = -0.45;
    armRGroup.add(armR);

    const gloveR = new THREE.Mesh(new THREE.SphereGeometry(0.075, 6, 6), gloveMat);
    gloveR.position.set(0.06, -0.42, 0.25);
    armRGroup.add(gloveR);

    // Smoker Tool (Alat Pengasap Stainless Steel)
    const smoker = new THREE.Group();
    smoker.position.set(0.08, -0.44, 0.35);

    const canister = new THREE.Mesh(new THREE.CylinderGeometry(0.07, 0.07, 0.22, 8), metalMat);
    smoker.add(canister);

    const snout = new THREE.Mesh(new THREE.ConeGeometry(0.065, 0.14, 8), metalMat);
    snout.rotation.x = -0.55;
    snout.position.set(0, 0.16, 0.06);
    smoker.add(snout);

    const bellows = new THREE.Mesh(new THREE.BoxGeometry(0.06, 0.16, 0.1), woodMat);
    bellows.position.set(-0.08, 0, -0.04);
    smoker.add(bellows);

    armRGroup.add(smoker);
    bk.add(armRGroup);

    // Store refs for animation
    bk.userData.armRGroup = armRGroup;
    bk.userData.armLGroup = armLGroup;
    bk.userData.legLGroup = legLGroup;
    bk.userData.legRGroup = legRGroup;
    bk.userData.head = head;

    // Wooden Honey Bucket beside Beekeeper
    const bucket = new THREE.Mesh(new THREE.CylinderGeometry(0.24, 0.19, 0.38, 8), woodMat);
    bucket.position.set(-0.48, 0.19, 0.22);
    bucket.castShadow = true; bk.add(bucket);

    const honeyLiquid = new THREE.Mesh(new THREE.CircleGeometry(0.21, 12), new THREE.MeshStandardMaterial({ color: 0xf59e0b, roughness: 0.1, metalness: 0.2 }));
    honeyLiquid.rotation.x = -Math.PI / 2;
    honeyLiquid.position.set(-0.48, 0.34, 0.22);
    bk.add(honeyLiquid);

    bk.position.set(-1.5, 0, 2.4);
    bk.rotation.y = Math.PI * 0.25;
    scene.add(bk);
    beekeeperMesh = bk;

    // Start wandering after a brief pause
    bkState = BK_STATE.PAUSING;
    bkPauseTimer = 2.0;
    return bk;
  }
  createBeekeeper();

  // Create in-world harvest progress bar element
  (function() {
    const el = document.createElement('div');
    el.className = 'harvest-progress-3d';
    el.innerHTML = '<div class="harvest-progress-label">⛏️ Memanen...</div>' +
      '<div class="harvest-progress-bar-outer"><div class="harvest-progress-bar-inner" id="harvestBarInner"></div></div>' +
      '<div class="harvest-progress-pct" id="harvestBarPct">0%</div>';
    document.getElementById('farm3dCanvas').appendChild(el);
    harvestProgressDiv = el;
  })();

  // Pick next random waypoint (not too close to current)
  function pickNextWaypoint() {
    let best = bkWaypoints[0];
    let tries = 0;
    do {
      best = bkWaypoints[Math.floor(Math.random() * bkWaypoints.length)];
      tries++;
    } while (tries < 10 && best.distanceTo(beekeeperMesh.position) < 1.2);
    return best.clone();
  }

  // Particle Pools (Smoker Smoke + Honey Extraction Sparkles)
  const smokerPuffCount = isMobile ? 8 : 20;
  for (let i = 0; i < smokerPuffCount; i++) {
    const p = new THREE.Mesh(
      new THREE.SphereGeometry(0.09, 4, 4),
      new THREE.MeshStandardMaterial({ color: 0xf1f5f9, transparent: true, opacity: 0 })
    );
    scene.add(p);
    smokerPuffParticles.push(p);
  }
  const sparkleCount = isMobile ? 12 : 30;
  for (let i = 0; i < sparkleCount; i++) {
    const spk = new THREE.Mesh(
      new THREE.SphereGeometry(0.055, 4, 3),
      new THREE.MeshStandardMaterial({ color: 0xfbbf24, emissive: 0xf59e0b, emissiveIntensity: 0.8, transparent: true, opacity: 0 })
    );
    scene.add(spk);
    honeySparkles.push(spk);
  }

  window.triggerBeekeeperHarvest = function(targetHiveIdx, harvestedMl) {
    if (!beekeeperMesh) return;
    harvestHoneyAmount = harvestedMl || 10;
    const p = hivePos(targetHiveIdx >= 0 ? targetHiveIdx : 0, HIVES_ARRAY.length);
    // Target is slightly in front of the hive (not inside it)
    harvestTargetPos.set(p.x + 0.6, 0, p.z + 0.8);
    harvestReturnPos.copy(beekeeperMesh.position);
    harvestPhase = 0; // walk to hive
    harvestProgress = 0;
    bkState = BK_STATE.HARVESTING;
    bkWalkCycle = 0;

    // Show progress bar
    if (harvestProgressDiv) {
      harvestProgressDiv.classList.add('active');
      document.getElementById('harvestBarInner').style.width = '0%';
      document.getElementById('harvestBarPct').textContent = '0%';
    }
  };

  // ══════════════════════════════════════════════════════════
  // CINEMATIC CAMERA SYSTEM — EYE LEVEL, VOLUMETRIC 3D
  // ══════════════════════════════════════════════════════════
  let cineIdx = -1; // -1 = overview
  let cineZoom = 0; // 0=normal, negative=zoomed in

  // Camera target & current (for smooth lerp)
  const camTarget = { x: 0, y: 9.0, z: 16.0, lx: 0, ly: 1.0, lz: 0 };
  const camCurrent = { x: 0, y: 9.0, z: 16.0, lx: 0, ly: 1.0, lz: 0 };

  function setCineOverview() {
    cineIdx = -1;
    camTarget.x = 0;
    camTarget.y = 9.0 + cineZoom * 0.8;
    camTarget.z = 16.0 + cineZoom * 0.8;
    camTarget.lx = 0;
    camTarget.ly = 1.0;
    camTarget.lz = 0;
    updateCineUI();
  }

  function setCineHive(idx) {
    if (idx < 0 || idx >= HIVES_ARRAY.length) return;
    cineIdx = idx;
    const p = hivePos(idx, HIVES_ARRAY.length);
    // Camera positioned at 3/4 chest-height cinematic angle, looking across (not top-down)
    const angle = Math.PI * 0.12; // ~22 degrees to the side
    const dist = 3.4 + cineZoom * 0.35;
    camTarget.x = p.x + Math.sin(angle) * dist;
    camTarget.y = 1.35 + cineZoom * 0.15; // chest-height, frames hive with sky and mountains behind
    camTarget.z = p.z + Math.cos(angle) * dist;
    camTarget.lx = p.x;
    camTarget.ly = 1.05; // looks right at hive center
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
  window.cineZoomIn = function() { cineZoom = Math.max(-4, cineZoom - 1.2); if (cineIdx === -1) setCineOverview(); else setCineHive(cineIdx); };
  window.cineZoomOut = function() { cineZoom = Math.min(4, cineZoom + 1.2); if (cineIdx === -1) setCineOverview(); else setCineHive(cineIdx); };

  // Init camera
  setCineOverview();
  camCurrent.x = camTarget.x; camCurrent.y = camTarget.y; camCurrent.z = camTarget.z;
  camCurrent.lx = camTarget.lx; camCurrent.ly = camTarget.ly; camCurrent.lz = camTarget.lz;

  // Pointer & Tap Handling on 3D Canvas (Supports Mouse Click & Mobile Touch Tap)
  const ray = new THREE.Raycaster(), mP = new THREE.Vector2();
  let touchStartX = 0, touchStartY = 0, touchStartTime = 0;

  function handleCanvasTap(clientX, clientY) {
    if (!renderer || !camera || hive3D.length === 0) return;
    const r = renderer.domElement.getBoundingClientRect();
    mP.x = ((clientX - r.left) / r.width) * 2 - 1;
    mP.y = -((clientY - r.top) / r.height) * 2 + 1;
    ray.setFromCamera(mP, camera);
    for (let i = 0; i < hive3D.length; i++) {
      if (ray.intersectObjects(hive3D[i].children, true).length > 0) {
        openHiveInspection(hive3D[i].userData.hiveId);
        hive3D[i].userData.bounceT = 0;
        setCineHive(i);
        break;
      }
    }
  }

  renderer.domElement.addEventListener('click', e => {
    handleCanvasTap(e.clientX, e.clientY);
  });

  renderer.domElement.addEventListener('touchstart', e => {
    if (e.touches && e.touches.length === 1) {
      touchStartX = e.touches[0].clientX;
      touchStartY = e.touches[0].clientY;
      touchStartTime = Date.now();
    }
  }, { passive: true });

  renderer.domElement.addEventListener('touchend', e => {
    if (e.changedTouches && e.changedTouches.length === 1) {
      const dx = Math.abs(e.changedTouches[0].clientX - touchStartX);
      const dy = Math.abs(e.changedTouches[0].clientY - touchStartY);
      const dt = Date.now() - touchStartTime;
      // Quick tap under 350ms and minimal movement (< 12px)
      if (dt < 350 && dx < 12 && dy < 12) {
        handleCanvasTap(e.changedTouches[0].clientX, e.changedTouches[0].clientY);
      }
    }
  }, { passive: true });

  // ── PRE-INSTANTIATED LABELS (Zero DOM Mutation During Animate, 60 FPS Smooth) ──
  const hiveLabelElements = [];
  function initLabels() {
    if (!labelsC) return;
    labelsC.innerHTML = '';
    hive3D.forEach((obj, i) => {
      const hive = HIVES_ARRAY[i];
      if (!hive) return;
      const det = hive.details || {};
      const honey = parseFloat(det.total_honey) || 0;

      const lbl = document.createElement('div');
      lbl.className = 'hive-3d-label';
      lbl.style.display = 'none'; // Hidden until projected
      lbl.innerHTML = '<div class="hive-label-bubble">' +
        '<div class="hive-label-name">' + (hive.master_name || 'Sarang') + '</div>' +
        '<div class="hive-label-honey" id="hiveLabelHoney_' + hive.id + '"><i class="ph-fill ph-drop"></i> ' + honey.toFixed(1) + ' ml</div>' +
      '</div>';

      const onLabelClick = (e) => {
        if (e) { e.preventDefault(); e.stopPropagation(); }
        openHiveInspection(hive.id);
        setCineHive(i);
      };
      lbl.addEventListener('click', onLabelClick);
      lbl.addEventListener('touchend', onLabelClick, { passive: false });

      labelsC.appendChild(lbl);
      hiveLabelElements.push(lbl);
    });
  }

  function updateLabelsPosition() {
    if (!labelsC || hiveLabelElements.length === 0) return;
    const cw = container.clientWidth;
    const ch = container.clientHeight;
    if (cw === 0 || ch === 0) return;
    const halfW = cw / 2;
    const halfH = ch / 2;

    for (let i = 0; i < hive3D.length; i++) {
      const lbl = hiveLabelElements[i];
      if (!lbl) continue;

      // In cinematic hive view, hide label of current inspected hive
      if (cineIdx === i) {
        if (lbl.style.display !== 'none') lbl.style.display = 'none';
        continue;
      }

      const wp = hiveWP[i].clone();
      wp.project(camera);

      // Frustum clip
      if (wp.z > 1 || wp.z < -1 || Math.abs(wp.x) > 1.2 || Math.abs(wp.y) > 1.2) {
        if (lbl.style.display !== 'none') lbl.style.display = 'none';
        continue;
      }

      const sx = Math.round(wp.x * halfW + halfW);
      const sy = Math.round(-wp.y * halfH + halfH);

      if (lbl.style.display !== 'block') lbl.style.display = 'block';
      lbl.style.transform = `translate3d(${sx}px, ${sy}px, 0)`;
    }
  }

  initLabels();

  // ── AUTO ASPECT RATIO CHECK ──
  function checkResize() {
    const w = container.clientWidth;
    const h = container.clientHeight;
    if (w > 0 && h > 0) {
      const pr = Math.min(window.devicePixelRatio || 1, 2);
      if (renderer.domElement.width !== Math.floor(w * pr) || renderer.domElement.height !== Math.floor(h * pr)) {
        camera.aspect = w / h;
        camera.updateProjectionMatrix();
        renderer.setSize(w, h, false);
      }
    }
  }

  // ── ANIMATE ──
  const clock = new THREE.Clock();
  const lookTarget = new THREE.Vector3();
  let _lastT = 0;

  function animate() {
    requestAnimationFrame(animate);
    const t = clock.getElapsedTime();
    const _dt = Math.min(t - _lastT, 0.05);
    _lastT = t;

    // Ensure aspect ratio is always 100% pixel-perfect (prevents gepeng/stretched viewport)
    checkResize();

    // Smooth camera lerp
    const lerpSpeed = 0.04;
    camCurrent.x += (camTarget.x - camCurrent.x) * lerpSpeed;
    camCurrent.y += (camTarget.y - camCurrent.y) * lerpSpeed;
    camCurrent.z += (camTarget.z - camCurrent.z) * lerpSpeed;
    camCurrent.lx += (camTarget.lx - camCurrent.lx) * lerpSpeed;
    camCurrent.ly += (camTarget.ly - camCurrent.ly) * lerpSpeed;
    camCurrent.lz += (camTarget.lz - camCurrent.lz) * lerpSpeed;

    // Subtle cinematic handheld breath
    const swayAmp = cineIdx === -1 ? 0.25 : 0.06;
    const swayX = Math.sin(t * 0.25) * swayAmp;
    const swayY = Math.cos(t * 0.18) * (swayAmp * 0.6);

    camera.position.set(camCurrent.x + swayX, camCurrent.y + swayY, camCurrent.z);
    lookTarget.set(camCurrent.lx, camCurrent.ly, camCurrent.lz);
    camera.lookAt(lookTarget);

    // Jam angin untuk shader rumput/bunga + bayangan awan di tanah
    U.uTime.value = t;

    // Tajuk pohon & bunga matahari bergoyang mengikuti hembusan angin
    for (let i = 0; i < swayers.length; i++) {
      const s = swayers[i], w = windAt(t, s.x, s.z);
      s.o.rotation.z = (w + 0.35) * s.amp;
      s.o.rotation.x = (s.baseX || 0) + Math.sin(t * 0.8 + s.ph) * s.amp * 0.6 + w * s.amp * 0.4;
    }

    // Lebah — terbang bebas antara sarang dan bunga
    for (let i = 0; i < bees.length; i++) {
      const b = bees[i], d = b.userData;
      updFlyer(b, t, _dt, false);
      const f = Math.sin(t * 60 + d.ph) * 0.7;
      d.wR.rotation.z = 0.25 + f; d.wL.rotation.z = -0.25 - f;
    }

    // Kupu-kupu — mengepak lalu melayang sebentar
    for (let i = 0; i < bflies.length; i++) {
      const bf = bflies[i], d = bf.userData;
      d.maxSpd = d.slow + 0.5;
      updFlyer(bf, t, _dt, true);
      const glide = Math.sin(t * 0.9 + d.ph) > 0.75 ? 0.25 : 1;
      const f = 0.55 + Math.sin(t * 11 + d.ph) * 0.75 * glide;
      d.wR.rotation.z = f; d.wL.rotation.z = -f;
    }

    // Burung berputar di atas lembah
    for (let i = 0; i < birds.length; i++) {
      const b = birds[i], d = b.userData, a = d.a + t * d.spd;
      b.position.set(Math.sin(a) * d.r * 1.25, d.h + Math.sin(t * 0.4 + d.ph) * 0.8, Math.cos(a) * d.r - 4);
      b.rotation.y = a + Math.PI / 2; b.rotation.z = -0.25;
      const gl = Math.sin(t * 0.5 + d.ph) > 0.55 ? 0.15 : 1;
      const f = Math.sin(t * 7 + d.ph) * 0.7 * gl + 0.1;
      d.wR.rotation.z = f; d.wL.rotation.z = -f;
    }

    // Daun gugur
    for (let i = 0; i < leaves.length; i++) {
      const l = leaves[i], d = l.userData;
      if (!l.visible) {
        d.wait -= _dt;
        if (d.wait <= 0) { l.position.copy(leafSources[Math.floor(Math.random() * leafSources.length)]); l.position.x += Math.random() - 0.5; l.visible = true; }
        continue;
      }
      const w = windAt(t, l.position.x, l.position.z);
      l.position.x += (0.5 + w * 0.6 + Math.sin(t * 2.2 + d.ph) * 0.5) * _dt;
      l.position.z += Math.cos(t * 1.7 + d.ph) * 0.45 * _dt;
      l.position.y -= d.fall * (0.7 + 0.5 * Math.sin(t * 2.2 + d.ph)) * _dt;
      l.rotation.x += _dt * 2.6; l.rotation.z += _dt * 1.9;
      if (l.position.y < getGroundElevation(l.position.x, l.position.z) + 0.03) { l.visible = false; d.wait = 2 + Math.random() * 9; }
    }

    // Awan bergerak pelan + naik-turun halus
    for (let i = 0; i < clouds.length; i++) {
      const c = clouds[i], d = c.userData;
      c.position.x += d.speed * _dt; if (c.position.x > 46) c.position.x = -46;
      c.position.y = d.y + Math.sin(t * 0.15 + d.ph) * 0.35;
    }

    // Serbuk sari terbawa angin
    const pp = pGeo.getAttribute('position');
    for (let i = 0; i < pCnt; i++) {
      let x = pp.getX(i) + (0.25 + Math.sin(t * 0.7 + i) * 0.2) * _dt, y = pp.getY(i) + (0.18 + Math.sin(t * 1.3 + i * 2.1) * 0.25) * _dt;
      if (y > 6 || x > 11) { y = 0.2 + Math.random(); x = (Math.random() - 0.5) * 22 - 2; }
      pp.setX(i, x); pp.setY(i, y);
    }
    pp.needsUpdate = true;

    // Kolam: kilau, riak melebar, teratai mengapung
    pond.material.opacity = 0.84 + Math.sin(t * 1.5) * 0.04;
    for (let i = 0; i < pondRipples.length; i++) {
      const r = pondRipples[i], d = r.userData;
      d.t += _dt;
      if (d.t > d.dur) {
        d.t = 0; const a = Math.random() * 6.283, rad = Math.random() * 0.7;
        r.position.x = POND.x + Math.cos(a) * rad; r.position.z = POND.z + Math.sin(a) * rad;
      }
      const k = d.t / d.dur, s = 0.08 + k * 0.6;
      r.scale.set(s, 1, s); r.material.opacity = (1 - k) * 0.4 * Math.min(1, k * 8);
    }
    for (let i = 0; i < lilyPads.length; i++) {
      const p = lilyPads[i];
      p.position.y = 0.05 + Math.sin(t * 1.2 + p.userData.ph) * 0.006;
      p.rotation.y = p.userData.ry + Math.sin(t * 0.3 + p.userData.ph) * 0.25;
    }

    // Sarang memantul saat diketuk (squash & stretch)
    hive3D.forEach((obj) => {
      if (obj.userData.bounceT !== undefined) {
        obj.userData.bounceT += 0.08;
        if (obj.userData.bounceT < Math.PI) {
          const b = Math.sin(obj.userData.bounceT);
          obj.position.y = b * 0.25;
          obj.scale.set(1 - b * 0.05, 1 + b * 0.09, 1 - b * 0.05);
        } else { obj.position.y = 0; obj.scale.set(1, 1, 1); delete obj.userData.bounceT; }
      }
    });

    // Asap cerobong: membesar, memudar, dan condong terbawa angin
    chimneySmoke.forEach((sp) => {
      sp.position.y += sp.userData.speed;
      const prog = (sp.position.y - sp.userData.initialY) / 2.4;
      sp.position.x = 1.5 + prog * prog * 0.9 + Math.sin(t * 1.2 + sp.userData.seed) * 0.06 * prog;
      const s = 1 + prog * 1.6;
      sp.scale.set(s, s, s);
      sp.material.opacity = 0.5 * (1 - prog) * Math.min(1, prog * 6);
      if (prog > 1) sp.position.y = sp.userData.initialY;
    });
    if (cabinLanternLight) cabinLanternLight.intensity = 0.8 + Math.sin(t * 9) * 0.06 + Math.sin(t * 23.7) * 0.05;

    // ═══ BEEKEEPER STATE MACHINE (Wander + Harvest) ═══
    if (beekeeperMesh) {
      const dt = _dt;
      const ud = beekeeperMesh.userData;
      const walkSpeed = 1.8; // units/sec

      // Leg/arm swing helper
      function applyWalkAnim(cycle) {
        const swing = Math.sin(cycle) * 0.35;
        if (ud.legLGroup) ud.legLGroup.rotation.x = swing;
        if (ud.legRGroup) ud.legRGroup.rotation.x = -swing;
        if (ud.armLGroup) ud.armLGroup.rotation.x = -swing * 0.5;
        if (ud.armRGroup && bkState !== BK_STATE.HARVESTING) ud.armRGroup.rotation.x = swing * 0.4;
        // Subtle torso bob
        beekeeperMesh.position.y = Math.abs(Math.sin(cycle)) * 0.03;
      }

      function resetPose() {
        if (ud.legLGroup) ud.legLGroup.rotation.x = 0;
        if (ud.legRGroup) ud.legRGroup.rotation.x = 0;
        if (ud.armLGroup) ud.armLGroup.rotation.x = 0;
        if (ud.armRGroup) ud.armRGroup.rotation.x = 0;
        beekeeperMesh.position.y = 0;
      }

      // Move beekeeper towards a target with collision avoidance, return true when arrived
      function moveTowards(target, spd) {
        let dx = target.x - beekeeperMesh.position.x;
        let dz = target.z - beekeeperMesh.position.z;
        const dist = Math.sqrt(dx*dx + dz*dz);
        const targetRot = Math.atan2(dx, dz);
        // Smooth rotation
        let rotDiff = targetRot - beekeeperMesh.rotation.y;
        while (rotDiff > Math.PI) rotDiff -= Math.PI * 2;
        while (rotDiff < -Math.PI) rotDiff += Math.PI * 2;
        beekeeperMesh.rotation.y += rotDiff * 0.1;

        if (dist < 0.15) return true;
        const step = Math.min(spd * dt, dist);
        let nx = beekeeperMesh.position.x + (dx / dist) * step;
        let nz = beekeeperMesh.position.z + (dz / dist) * step;

        // Collision avoidance: push away from obstacles
        for (let oi = 0; oi < obstaclePositions.length; oi++) {
          const ob = obstaclePositions[oi];
          const odx = nx - ob.x;
          const odz = nz - ob.z;
          const oDist = Math.sqrt(odx*odx + odz*odz);
          if (oDist < ob.r && oDist > 0.01) {
            // Push outward from obstacle center
            const pushStr = (ob.r - oDist) * 1.5;
            nx += (odx / oDist) * pushStr;
            nz += (odz / oDist) * pushStr;
          }
        }

        beekeeperMesh.position.x = nx;
        beekeeperMesh.position.z = nz;
        bkWalkCycle += spd * dt * 6.0;
        applyWalkAnim(bkWalkCycle);
        return false;
      }

      // ── WANDER STATE MACHINE ──
      if (bkState === BK_STATE.PAUSING) {
        bkPauseTimer -= dt;
        // Idle micro-animations while pausing
        const breath = Math.sin(t * 1.8) * 0.015;
        beekeeperMesh.position.y = breath;
        // Random idle actions
        if (bkIdleAction === 1) {
          // Look around
          if (ud.head) ud.head.rotation.y = Math.sin(t * 0.7) * 0.3;
        } else if (bkIdleAction === 2) {
          // Check smoker
          if (ud.armRGroup) ud.armRGroup.rotation.x = -0.4 + Math.sin(t * 2) * 0.15;
        } else {
          // Gentle breathing sway
          if (ud.armRGroup) ud.armRGroup.rotation.x = Math.sin(t * 1.4) * 0.05;
        }
        if (ud.head) ud.head.rotation.y *= 0.98; // dampen

        if (bkPauseTimer <= 0) {
          // Pick next waypoint and start walking
          bkWalkTarget = pickNextWaypoint();
          bkState = BK_STATE.WALKING;
          bkWalkCycle = 0;
          resetPose();
          if (ud.head) ud.head.rotation.y = 0;
        }
      } else if (bkState === BK_STATE.WALKING) {
        const arrived = moveTowards(bkWalkTarget, walkSpeed);
        if (arrived) {
          resetPose();
          bkState = BK_STATE.PAUSING;
          bkPauseTimer = 1.5 + Math.random() * 3.0; // pause 1.5-4.5s
          bkIdleAction = Math.floor(Math.random() * 3); // random idle behavior
        }
      } else if (bkState === BK_STATE.IDLE) {
        // Simple breathing when no waypoints
        beekeeperMesh.position.y = Math.sin(t * 1.6) * 0.02;
        if (ud.armRGroup) ud.armRGroup.rotation.x = Math.sin(t * 1.6) * 0.04;
      }

      // ── HARVEST STATE MACHINE ──
      if (bkState === BK_STATE.HARVESTING) {
        const totalHarvestTime = 4.0; // seconds for full harvest

        if (harvestPhase === 0) {
          // Phase 0: Walk to hive
          const arrived = moveTowards(harvestTargetPos, walkSpeed * 1.2);
          if (arrived) {
            resetPose();
            harvestPhase = 1;
            harvestProgress = 0;
            FarmAudio.playSmoker();
            // Face the hive
            const hp = hivePos(0, HIVES_ARRAY.length);
            const dx2 = (harvestTargetPos.x - 0.6) - beekeeperMesh.position.x;
            const dz2 = (harvestTargetPos.z - 0.8) - beekeeperMesh.position.z;
            beekeeperMesh.rotation.y = Math.atan2(dx2, dz2);
          }
        } else if (harvestPhase === 1) {
          // Phase 1: Smoking the hive (0% - 30%)
          harvestProgress += dt / totalHarvestTime;
          const pct = Math.min(harvestProgress / 0.3, 1.0);
          const pump = Math.sin(harvestProgress * 45);
          if (ud.armRGroup) ud.armRGroup.rotation.x = -0.45 + pump * 0.42;
          // Smoker puffs
          const pIdx = Math.floor((harvestProgress * 40) % smokerPuffParticles.length);
          const sp = smokerPuffParticles[pIdx];
          if (sp && pump > 0.3) {
            sp.material.opacity = 0.65;
            const fwd = beekeeperMesh.rotation.y;
            sp.position.set(
              beekeeperMesh.position.x + Math.sin(fwd) * 0.85,
              1.25 + pump * 0.12,
              beekeeperMesh.position.z + Math.cos(fwd) * 0.85
            );
          }
          // Update progress bar
          const totalPct = Math.min(pct * 30, 30);
          if (harvestProgressDiv) {
            document.getElementById('harvestBarInner').style.width = totalPct + '%';
            document.getElementById('harvestBarPct').textContent = Math.floor(totalPct) + '%';
          }
          if (harvestProgress >= 0.3) {
            harvestPhase = 2;
            setTimeout(() => FarmAudio.playBee(1.4, 215), 100);
          }
        } else if (harvestPhase === 2) {
          // Phase 2: Extracting honey (30% - 75%)
          harvestProgress += dt / totalHarvestTime;
          const pct = Math.min((harvestProgress - 0.3) / 0.45, 1.0);
          if (ud.armRGroup) ud.armRGroup.rotation.x = -0.9 + Math.sin(t * 6) * 0.12;
          // Honey sparkle particles
          const sIdx = Math.floor((harvestProgress * 55) % honeySparkles.length);
          const spk = honeySparkles[sIdx];
          if (spk) {
            spk.material.opacity = 0.9;
            const hiveCenter = new THREE.Vector3(harvestTargetPos.x - 0.6, 1.2, harvestTargetPos.z - 0.8);
            const bucketPos = new THREE.Vector3(beekeeperMesh.position.x - 0.45, 0.45, beekeeperMesh.position.z + 0.2);
            spk.position.lerpVectors(hiveCenter, bucketPos, pct);
            spk.position.y += Math.sin(pct * Math.PI) * 1.1;
          }
          const totalPct = 30 + Math.min(pct * 45, 45);
          if (harvestProgressDiv) {
            document.getElementById('harvestBarInner').style.width = totalPct + '%';
            document.getElementById('harvestBarPct').textContent = Math.floor(totalPct) + '%';
          }
          if (harvestProgress >= 0.75) {
            harvestPhase = 3;
            FarmAudio.playHarvest();
            FarmAudio.playCoin();
          }
        } else if (harvestPhase === 3) {
          // Phase 3: Collecting/finishing (75% - 100%)
          harvestProgress += dt / totalHarvestTime;
          if (ud.armRGroup) ud.armRGroup.rotation.x = -1.35 + Math.sin(t * 8) * 0.15;
          const pct = Math.min((harvestProgress - 0.75) / 0.25, 1.0);
          const totalPct = 75 + Math.min(pct * 25, 25);
          if (harvestProgressDiv) {
            document.getElementById('harvestBarInner').style.width = totalPct + '%';
            document.getElementById('harvestBarPct').textContent = Math.floor(totalPct) + '%';
          }
          if (harvestProgress >= 1.0) {
            harvestPhase = 4;
            // Hide progress bar with slight delay
            setTimeout(() => {
              if (harvestProgressDiv) harvestProgressDiv.classList.remove('active');
            }, 600);
            if (harvestProgressDiv) {
              document.getElementById('harvestBarInner').style.width = '100%';
              document.getElementById('harvestBarPct').textContent = '✓ Selesai!';
            }
          }
        } else if (harvestPhase === 4) {
          // Phase 4: Walk back to return position
          const arrived = moveTowards(harvestReturnPos, walkSpeed);
          if (arrived) {
            resetPose();
            if (ud.armRGroup) ud.armRGroup.rotation.x = 0;
            smokerPuffParticles.forEach(p => p.material.opacity = 0);
            honeySparkles.forEach(s => s.material.opacity = 0);
            // Resume wandering
            bkState = BK_STATE.PAUSING;
            bkPauseTimer = 2.0;
            bkIdleAction = 0;
          }
        }

        // Position the progress bar in screen space above the beekeeper
        if (harvestProgressDiv && harvestPhase >= 1 && harvestPhase <= 3) {
          const bkWorldPos = new THREE.Vector3();
          beekeeperMesh.getWorldPosition(bkWorldPos);
          bkWorldPos.y += 2.1;
          bkWorldPos.project(camera);
          if (bkWorldPos.z < 1) {
            const sx = (bkWorldPos.x * container.clientWidth / 2) + container.clientWidth / 2;
            const sy = -(bkWorldPos.y * container.clientHeight / 2) + container.clientHeight / 2;
            harvestProgressDiv.style.left = sx + 'px';
            harvestProgressDiv.style.top = sy + 'px';
          }
        }
      }
    }

    // Drift active smoke puffs
    smokerPuffParticles.forEach(p => {
      if (p.material.opacity > 0.01) {
        p.position.y += 0.014;
        p.position.x += (Math.random() - 0.5) * 0.01;
        p.material.opacity -= 0.016;
      }
    });

    renderer.render(scene, camera);
    updateLabelsPosition();
  }

  animate();
  setTimeout(() => { if (loadingEl) loadingEl.classList.add('hidden'); }, 400);
  window.addEventListener('resize', () => { 
    if (!container || !camera || !renderer) return;
    camera.aspect = container.clientWidth/container.clientHeight; 
    camera.updateProjectionMatrix(); 
    renderer.setSize(container.clientWidth, container.clientHeight); 
  });
})();
</script>
<?php require dirname(__DIR__) . '/partials/footer.php'; ?>
