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

$pageTitle = 'Kebun Sarang Lebah Cuan — Tycoon Simulator';
$activePage = 'farm';
$farmSubPage = 'meadow';
require dirname(__DIR__) . '/partials/header.php';
?>

<style>
/* ══════════════════════════════════════════════════════════
   ULTRA-REALISTIC LIVING TYCOON MEADOW & 3D LANDSCAPE
   ══════════════════════════════════════════════════════════ */
body {
  background: #1a5c2e !important;
  font-family: 'Nunito', sans-serif;
  overflow-x: hidden;
}

/* ── DEEP LAYERED SKY + PARALLAX ROLLING HILLS ── */
.tycoon-landscape {
  position: relative;
  min-height: 100vh;
  overflow: hidden;
  padding: 0 0 160px;
}

/* Sky gradient — atmospheric, warm golden hour feel */
.sky-layer {
  position: absolute; top: 0; left: 0; right: 0;
  height: 45vh;
  background: linear-gradient(180deg,
    #1e40af 0%,
    #3b82f6 15%,
    #60a5fa 30%,
    #93c5fd 45%,
    #bfdbfe 60%,
    #fef3c7 78%,
    #fde68a 88%,
    #fbbf24 100%
  );
  z-index: 0;
}

/* Sun disc + glow */
.sun-orb {
  position: absolute;
  top: 5vh; right: 15%;
  width: 70px; height: 70px;
  background: radial-gradient(circle, #fff 0%, #fef08a 30%, #fbbf24 60%, rgba(251,191,36,0) 100%);
  border-radius: 50%;
  z-index: 1;
  filter: blur(2px);
  animation: sunPulse 4s ease-in-out infinite alternate;
}
.sun-rays {
  position: absolute;
  top: 0; right: 8%;
  width: 200px; height: 45vh;
  background: linear-gradient(180deg,
    rgba(251,191,36,0.08) 0%,
    rgba(253,230,138,0.12) 30%,
    rgba(254,243,199,0.06) 60%,
    transparent 100%
  );
  clip-path: polygon(40% 0%, 60% 0%, 85% 100%, 15% 100%);
  z-index: 1;
  animation: rayShimmer 6s ease-in-out infinite alternate;
  pointer-events: none;
}
@keyframes sunPulse {
  0%   { transform: scale(1); opacity: 0.95; }
  100% { transform: scale(1.12); opacity: 1; }
}
@keyframes rayShimmer {
  0%   { opacity: 0.5; transform: scaleX(1); }
  100% { opacity: 0.9; transform: scaleX(1.15); }
}

/* Drifting clouds — soft, volumetric */
.sky-cloud {
  position: absolute; border-radius: 50px;
  opacity: 0.85; pointer-events: none; z-index: 2;
  background: rgba(255,255,255,0.9);
  filter: blur(1px) drop-shadow(0 4px 8px rgba(0,0,0,0.05));
}
.sky-cloud::before {
  content: ''; position: absolute; background: rgba(255,255,255,0.95); border-radius: 50%;
  width: 55%; height: 160%; top: -60%; left: 20%;
}
.sky-cloud::after {
  content: ''; position: absolute; background: rgba(255,255,255,0.8); border-radius: 50%;
  width: 40%; height: 130%; top: -40%; left: 55%;
}
.sky-cloud--1 { width: 100px; height: 28px; top: 22px; left: -120px; animation: cloudDrift 32s linear infinite; }
.sky-cloud--2 { width: 150px; height: 40px; top: 65px; left: -170px; animation: cloudDrift 48s linear infinite 16s; }
.sky-cloud--3 { width: 80px; height: 22px; top: 42px; left: -90px; animation: cloudDrift 38s linear infinite 8s; opacity: 0.6; }
@keyframes cloudDrift {
  from { transform: translateX(0); }
  to   { transform: translateX(calc(100vw + 250px)); }
}

/* ── DISTANT MOUNTAIN RIDGE ── */
.mountain-ridge {
  position: absolute;
  bottom: 58vh; left: 0; right: 0;
  height: 14vh;
  z-index: 2;
  pointer-events: none;
}
.mountain-ridge::before {
  content: '';
  position: absolute; bottom: 0; left: -5%; right: -5%;
  height: 100%;
  background:
    conic-gradient(from 170deg at 18% 100%, #4b7c4f 0deg, transparent 40deg),
    conic-gradient(from 160deg at 42% 100%, #3d6b42 0deg, transparent 50deg),
    conic-gradient(from 165deg at 65% 100%, #5a8c5e 0deg, transparent 45deg),
    conic-gradient(from 155deg at 88% 100%, #4b7c4f 0deg, transparent 38deg);
  opacity: 0.7;
  filter: blur(2px);
}

/* ── PARALLAX ROLLING HILLS — 3 LAYERS ── */
.hill-layer {
  position: absolute; left: -5%; right: -5%;
  pointer-events: none;
}

/* Far hill */
.hill-far {
  bottom: 52vh; height: 18vh; z-index: 3;
  background: #3f8c4d;
  border-radius: 50% 60% 0 0 / 100% 100% 0 0;
  opacity: 0.7;
  filter: blur(1px);
}

/* Mid hill */
.hill-mid {
  bottom: 42vh; height: 20vh; z-index: 4;
  background: linear-gradient(180deg, #48a858 0%, #3d9648 40%, #2d8239 100%);
  border-radius: 45% 55% 0 0 / 100% 100% 0 0;
  opacity: 0.85;
}

/* Near hill */
.hill-near {
  bottom: 32vh; height: 22vh; z-index: 5;
  background: linear-gradient(180deg, #34d058 0%, #2ea44f 30%, #28a745 60%, #22863a 100%);
  border-radius: 52% 48% 0 0 / 100% 100% 0 0;
}

/* ── GROUND MEADOW PLANE ── */
.meadow-ground {
  position: absolute;
  bottom: 0; left: 0; right: 0;
  height: 55vh;
  z-index: 6;
  background: linear-gradient(180deg,
    #28a745 0%,
    #22863a 15%,
    #1e7e34 30%,
    #1a6b2d 50%,
    #166b27 70%,
    #145c23 100%
  );
}

/* Grass texture overlay — procedural striped grass blades */
.meadow-ground::before {
  content: '';
  position: absolute; top: 0; left: 0; right: 0; bottom: 0;
  background:
    repeating-linear-gradient(88deg, transparent 0px, transparent 3px, rgba(0,80,20,0.12) 3px, rgba(0,80,20,0.12) 4px),
    repeating-linear-gradient(92deg, transparent 0px, transparent 5px, rgba(50,160,70,0.08) 5px, rgba(50,160,70,0.08) 6px);
  pointer-events: none;
}

/* Earthy ground shadow at bottom */
.meadow-ground::after {
  content: '';
  position: absolute; bottom: 0; left: 0; right: 0;
  height: 60px;
  background: linear-gradient(180deg, transparent, rgba(15,60,20,0.4));
  pointer-events: none;
}

/* ── DECORATIVE TREES (SILHOUETTES) ── */
.tree-deco {
  position: absolute; z-index: 5; pointer-events: none;
  filter: drop-shadow(0 4px 6px rgba(0,0,0,0.2));
}
.tree-deco--1 {
  left: 4%; bottom: 48vh; font-size: 52px; opacity: 0.5;
  animation: treeSway 8s ease-in-out infinite;
}
.tree-deco--2 {
  right: 6%; bottom: 46vh; font-size: 44px; opacity: 0.45;
  animation: treeSway 10s ease-in-out infinite 2s;
}
.tree-deco--3 {
  left: 25%; bottom: 50vh; font-size: 36px; opacity: 0.35;
  animation: treeSway 12s ease-in-out infinite 4s;
}
.tree-deco--4 {
  right: 30%; bottom: 49vh; font-size: 30px; opacity: 0.3;
}
@keyframes treeSway {
  0%, 100% { transform: rotate(-1deg); }
  50%      { transform: rotate(1.5deg); }
}

/* ── ROCKS & STONES ── */
.rock-deco {
  position: absolute; z-index: 7; pointer-events: none;
  font-size: 18px; opacity: 0.5;
  filter: drop-shadow(0 2px 3px rgba(0,0,0,0.3));
}
.rock-deco--1 { left: 8%; bottom: 38vh; font-size: 22px; opacity: 0.4; }
.rock-deco--2 { right: 12%; bottom: 34vh; font-size: 16px; opacity: 0.35; transform: scaleX(-1); }
.rock-deco--3 { left: 55%; bottom: 28vh; font-size: 14px; opacity: 0.3; }

/* ── WILDFLOWER PATCHES ── */
.wildflower-patch {
  position: absolute; z-index: 7; pointer-events: none;
  font-size: 20px;
  filter: drop-shadow(0 1px 2px rgba(0,0,0,0.15));
  animation: flowerSway 5s ease-in-out infinite;
}
.wildflower-patch--1 { left: 15%; bottom: 36vh; }
.wildflower-patch--2 { right: 18%; bottom: 32vh; animation-delay: 1.5s; }
.wildflower-patch--3 { left: 40%; bottom: 26vh; animation-delay: 3s; font-size: 16px; }
.wildflower-patch--4 { right: 40%; bottom: 40vh; animation-delay: 0.5s; font-size: 18px; }
@keyframes flowerSway {
  0%, 100% { transform: rotate(-3deg) translateY(0); }
  50%      { transform: rotate(3deg) translateY(-2px); }
}

/* ── FLOATING POLLEN / FIREFLY PARTICLES ── */
.pollen-particle {
  position: absolute; z-index: 8; pointer-events: none;
  width: 4px; height: 4px;
  background: radial-gradient(circle, rgba(253,224,71,0.9) 0%, rgba(253,224,71,0) 100%);
  border-radius: 50%;
}
@keyframes pollenFloat1 {
  0%   { transform: translate(0, 0) scale(1); opacity: 0; }
  20%  { opacity: 0.8; }
  50%  { transform: translate(30px, -60px) scale(1.3); opacity: 0.9; }
  80%  { opacity: 0.5; }
  100% { transform: translate(-20px, -120px) scale(0.6); opacity: 0; }
}
@keyframes pollenFloat2 {
  0%   { transform: translate(0, 0) scale(0.8); opacity: 0; }
  30%  { opacity: 0.7; }
  60%  { transform: translate(-25px, -50px) scale(1.2); opacity: 0.85; }
  100% { transform: translate(15px, -110px) scale(0.5); opacity: 0; }
}
@keyframes pollenFloat3 {
  0%   { transform: translate(0, 0) scale(1.1); opacity: 0; }
  25%  { opacity: 0.6; }
  55%  { transform: translate(40px, -40px) scale(0.9); opacity: 0.7; }
  100% { transform: translate(-10px, -100px) scale(0.4); opacity: 0; }
}

/* ── AMBIENT GOLDEN LIGHT OVERLAY ── */
.ambient-glow {
  position: absolute; top: 0; left: 0; right: 0; bottom: 0;
  z-index: 9;
  background: radial-gradient(ellipse at 75% 15%,
    rgba(251,191,36,0.08) 0%,
    rgba(251,191,36,0.03) 40%,
    transparent 70%
  );
  pointer-events: none;
}

/* ── FREE FLYING BEES IN SKY ── */
.field-bee {
  position: absolute; width: 34px; height: 34px; z-index: 10;
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
.field-bee--1 { top: 14vh; left: 12%; animation: beePath1 9s ease-in-out infinite; }
.field-bee--2 { top: 20vh; right: 14%; animation: beePath2 12s ease-in-out infinite 2s; }
.field-bee--3 { top: 28vh; left: 35%; animation: beePath3 15s ease-in-out infinite 4s; }
@keyframes beePath1 {
  0%, 100% { transform: translate(0, 0) rotate(0deg); }
  25%      { transform: translate(50px, -20px) rotate(12deg); }
  50%      { transform: translate(85px, 10px) rotate(-8deg); }
  75%      { transform: translate(30px, -15px) rotate(15deg); }
}
@keyframes beePath2 {
  0%, 100% { transform: translate(0, 0) scaleX(-1) rotate(0deg); }
  33%      { transform: translate(-60px, 25px) scaleX(-1) rotate(-15deg); }
  66%      { transform: translate(-30px, -20px) scaleX(-1) rotate(10deg); }
}
@keyframes beePath3 {
  0%, 100% { transform: translate(0, 0) rotate(5deg); }
  50%      { transform: translate(45px, -35px) rotate(-12deg); }
}

/* ══════════════════════════════════════════════════════════
   3D LIVING BEEHIVE OBJECTS IN REALISTIC NATURAL ENVIRONMENT
   ══════════════════════════════════════════════════════════ */
.meadow-content {
  position: relative;
  z-index: 10;
  padding: 16px 14px 0;
}

.meadow-trail-title {
  display: flex; align-items: center; justify-content: space-between;
  margin-bottom: 16px;
}
.trail-title-pill {
  background: rgba(255,255,255,0.95); border: 2.5px solid #166534; border-radius: 14px;
  padding: 4px 12px; font-size: 13px; font-weight: 900; color: #166534;
  box-shadow: 0 3px 0 #166534, 0 6px 14px rgba(0,0,0,0.15);
  display: inline-flex; align-items: center; gap: 6px;
  backdrop-filter: blur(4px);
}

/* Winding Meadow Terrain */
.pasture-terrain {
  position: relative;
  z-index: 10;
  display: flex;
  flex-direction: column;
  gap: 40px;
  margin-top: 10px;
}

/* Staggered Spots */
.pasture-hill-tier {
  position: relative;
  display: flex;
  width: 100%;
}
.pasture-hill-tier--left   { justify-content: flex-start; padding-left: 24px; }
.pasture-hill-tier--right  { justify-content: flex-end; padding-right: 24px; }
.pasture-hill-tier--center { justify-content: center; }

/* ── REAL 3D WOODEN BEEHIVE OBJECT ── */
.beehive-3d-unit {
  position: relative;
  display: flex;
  flex-direction: column;
  align-items: center;
  cursor: pointer;
  -webkit-tap-highlight-color: transparent;
  transition: transform 0.25s cubic-bezier(0.34, 1.56, 0.64, 1);
}
.beehive-3d-unit:hover {
  transform: translateY(-6px) scale(1.04);
}
.beehive-3d-unit:active {
  transform: translateY(2px) scale(0.97);
}

/* Ground Grass Patch under each hive — more realistic */
.beehive-grass-patch {
  position: absolute;
  bottom: -14px;
  width: 140px;
  height: 36px;
  background: radial-gradient(ellipse at center,
    rgba(34,134,58,0.9) 0%,
    rgba(40,167,69,0.6) 40%,
    rgba(30,126,52,0.3) 65%,
    transparent 85%
  );
  border-radius: 50%;
  z-index: 1;
}

/* Contact shadow on grass */
.beehive-ground-shadow {
  position: absolute;
  bottom: -8px;
  width: 100px;
  height: 20px;
  background: radial-gradient(ellipse at center,
    rgba(10,50,20,0.55) 0%,
    rgba(10,50,20,0.25) 45%,
    transparent 75%
  );
  border-radius: 50%;
  z-index: 2;
}

/* Wildflowers at hive base */
.hive-base-flower {
  position: absolute;
  bottom: -4px;
  font-size: 20px;
  z-index: 4;
  filter: drop-shadow(0 2px 2px rgba(0,0,0,0.2));
  animation: flowerSway 4s ease-in-out infinite;
}
.hive-base-flower--left  { left: -16px; }
.hive-base-flower--right { right: -16px; }

/* 3D Beehive Sprite Container */
.beehive-sprite-wrapper {
  position: relative;
  z-index: 3;
  width: 130px;
  height: 130px;
  display: flex;
  align-items: center;
  justify-content: center;
  filter: drop-shadow(0 12px 18px rgba(0,0,0,0.4));
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

/* Worker Bees Swarming entrance */
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
  z-index: 12;
  white-space: nowrap;
  animation: floatBob 2.2s ease-in-out infinite;
}
@keyframes floatBob {
  0%, 100% { transform: translateY(0); }
  50%      { transform: translateY(-7px); }
}

/* Rustic Wooden Plank Label beneath Hive */
.hive-ground-plank {
  position: relative;
  z-index: 3;
  margin-top: 4px;
  background: #78350f;
  background-image: linear-gradient(180deg, #8b5420 0%, #78350f 40%, #5c2d0e 100%);
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
  background: linear-gradient(180deg, #a0522d 0%, #8b4513 50%, #6d3610 100%);
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
  background: linear-gradient(90deg, #5c2d0e, #78350f, #5c2d0e);
  border: 2px solid #451a03;
  margin-top: -3px; z-index: 2;
  box-shadow: 0 3px 0 #451a03;
}

/* ══════════════════════════════════════════════════════════
   FULLSCREEN IMMERSIVE HIVE INSPECTION MODAL
   (Replaces the old static hexagon showcase — opens on 3D hive tap)
   ══════════════════════════════════════════════════════════ */
.hive-inspection-overlay {
  position: fixed;
  top: 0; left: 0; right: 0; bottom: 0;
  z-index: 9999;
  display: flex;
  align-items: center;
  justify-content: center;
  opacity: 0;
  visibility: hidden;
  transition: opacity 0.4s, visibility 0.4s;
}
.hive-inspection-overlay.active {
  opacity: 1;
  visibility: visible;
}

/* Deep radial vignette — as if camera entered the hive interior */
.hive-inspection-vignette {
  position: absolute;
  top: 0; left: 0; right: 0; bottom: 0;
  background: radial-gradient(circle at center,
    rgba(45,20,5,0.82) 0%,
    rgba(30,12,3,0.92) 35%,
    rgba(15,6,1,0.97) 65%,
    rgba(5,2,0,0.99) 100%
  );
  backdrop-filter: blur(8px);
}

/* Close button */
.hive-inspection-close {
  position: absolute;
  top: 18px; right: 18px;
  width: 44px; height: 44px;
  background: rgba(255,255,255,0.1);
  border: 2px solid rgba(255,255,255,0.2);
  border-radius: 50%;
  color: #fef3c7;
  font-size: 22px;
  display: flex; align-items: center; justify-content: center;
  cursor: pointer;
  z-index: 10;
  transition: background 0.2s, transform 0.2s;
}
.hive-inspection-close:hover {
  background: rgba(255,255,255,0.2);
  transform: scale(1.1);
}

/* Hive name title at top */
.hive-inspection-title {
  position: absolute;
  top: 22px; left: 0; right: 0;
  text-align: center;
  z-index: 10;
}
.hive-inspection-title span {
  display: inline-flex; align-items: center; gap: 8px;
  background: rgba(120,53,15,0.6);
  border: 2px solid rgba(251,191,36,0.4);
  border-radius: 16px;
  padding: 6px 16px;
  font-size: 14px; font-weight: 900; color: #fde68a;
  text-shadow: 0 2px 4px rgba(0,0,0,0.5);
  backdrop-filter: blur(4px);
}

/* The organic honeycomb frame — central element */
.hive-inspection-frame {
  position: relative;
  z-index: 5;
  width: 290px;
  max-width: 85vw;
  animation: frameSlideIn 0.5s cubic-bezier(0.34, 1.56, 0.64, 1) forwards;
}
@keyframes frameSlideIn {
  0%   { transform: scale(0.7) translateY(30px); opacity: 0; }
  100% { transform: scale(1) translateY(0); opacity: 1; }
}

/* Wooden frame border — organic shape */
.honeycomb-organic-frame {
  background:
    linear-gradient(180deg, #92400e 0%, #78350f 35%, #5c2d0e 100%);
  background-image:
    repeating-linear-gradient(45deg,
      rgba(120,53,15,0.3) 0px, rgba(120,53,15,0.3) 2px,
      transparent 2px, transparent 6px
    ),
    linear-gradient(180deg, #92400e 0%, #78350f 35%, #5c2d0e 100%);
  border: 4px solid #451a03;
  border-radius: 24px;
  padding: 18px 14px;
  box-shadow:
    inset 0 4px 8px rgba(0,0,0,0.5),
    inset 0 -2px 4px rgba(255,200,50,0.1),
    0 0 40px rgba(251,191,36,0.2),
    0 8px 24px rgba(0,0,0,0.5);
  position: relative;
  overflow: hidden;
}

/* Wood grain texture overlay */
.honeycomb-organic-frame::before {
  content: '';
  position: absolute; top: 0; left: 0; right: 0; bottom: 0;
  background: repeating-linear-gradient(
    90deg,
    transparent 0px, transparent 8px,
    rgba(69,26,3,0.15) 8px, rgba(69,26,3,0.15) 9px
  );
  pointer-events: none;
}

/* Warm amber glow inside the frame */
.honeycomb-organic-frame::after {
  content: '';
  position: absolute; top: 0; left: 0; right: 0; bottom: 0;
  background: radial-gradient(ellipse at center,
    rgba(251,191,36,0.08) 0%,
    transparent 70%
  );
  pointer-events: none;
}

/* Crawling Worker Bees inside Honeycomb Frame */
.hive-crawling-bee {
  position: absolute; width: 32px; height: 32px;
  z-index: 8; pointer-events: none;
  filter: drop-shadow(0 3px 4px rgba(0,0,0,0.6));
}
.hive-crawling-bee img {
  width: 100%; height: 100%; object-fit: contain;
  animation: beeWingFlap 0.08s linear infinite alternate;
}
@keyframes beeWingFlap {
  from { transform: scaleX(1); }
  to   { transform: scaleX(0.85) scaleY(1.05); }
}
.bee-crawler-1 { top: 18%; left: 16%; animation: crawl1 7s ease-in-out infinite; }
.bee-crawler-2 { top: 55%; right: 18%; animation: crawl2 8s ease-in-out infinite; }
.bee-crawler-3 { bottom: 15%; left: 40%; animation: crawl3 9s ease-in-out infinite 3s; }
@keyframes crawl1 {
  0%, 100% { transform: translate(0, 0) rotate(15deg); }
  50%      { transform: translate(45px, 20px) rotate(-25deg); }
}
@keyframes crawl2 {
  0%, 100% { transform: translate(0, 0) rotate(-40deg); }
  50%      { transform: translate(-35px, -20px) rotate(20deg); }
}
@keyframes crawl3 {
  0%, 100% { transform: translate(0, 0) rotate(10deg); }
  50%      { transform: translate(25px, -15px) rotate(-30deg); }
}

/* 3D Hexagon Matrix Grid */
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
  background: rgba(255, 255, 255, 0.65); border-radius: 50%;
  transform: rotate(-25deg); pointer-events: none;
}
@keyframes honeyGlow {
  0%   { filter: drop-shadow(0 0 3px #f59e0b); }
  100% { filter: drop-shadow(0 0 12px #fde047); }
}

/* Bottom info bar inside inspection modal */
.hive-inspection-info {
  position: relative;
  z-index: 5;
  margin-top: 16px;
  text-align: center;
}
.inspection-honey-amount {
  font-size: 28px; font-weight: 900; color: #fbbf24;
  text-shadow: 0 2px 8px rgba(251,191,36,0.4), 0 1px 2px rgba(0,0,0,0.5);
  display: flex; align-items: center; justify-content: center; gap: 8px;
}
.inspection-honey-amount i {
  font-size: 24px; color: #f59e0b;
}
.inspection-honey-sub {
  font-size: 11px; font-weight: 800; color: rgba(254,243,199,0.7);
  margin-top: 2px;
}
.inspection-bee-info {
  font-size: 11px; font-weight: 800; color: rgba(254,243,199,0.5);
  margin-top: 6px;
  display: flex; align-items: center; justify-content: center; gap: 5px;
}

/* Harvest button inside inspection */
.btn-inspection-harvest {
  margin-top: 14px;
  background: linear-gradient(135deg, #f59e0b 0%, #d97706 60%, #b45309 100%);
  border: 3px solid #78350f;
  border-radius: 18px;
  box-shadow: 0 5px 0 #78350f, 0 8px 20px rgba(180,83,9,0.4);
  padding: 12px 24px;
  color: #fff;
  font-size: 14px; font-weight: 900;
  display: inline-flex; align-items: center; gap: 8px;
  cursor: pointer; font-family: 'Nunito', sans-serif;
  text-shadow: 0 2px 0 rgba(0,0,0,0.3);
  transition: transform 0.1s;
}
.btn-inspection-harvest:active { transform: translateY(3px); box-shadow: 0 2px 0 #78350f; }
.btn-inspection-harvest:disabled {
  background: #4b5563; border-color: #374151; color: #9ca3af;
  text-shadow: none; box-shadow: 0 3px 0 #374151; cursor: not-allowed;
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
     THE ULTRA-REALISTIC LIVING TYCOON MEADOW CANVAS
     ══════════════════════════════════════════════════════════ -->
<div class="tycoon-landscape">
  <!-- Atmospheric Sky -->
  <div class="sky-layer"></div>
  <div class="sun-orb"></div>
  <div class="sun-rays"></div>

  <!-- Drifting Volumetric Clouds -->
  <div class="sky-cloud sky-cloud--1"></div>
  <div class="sky-cloud sky-cloud--2"></div>
  <div class="sky-cloud sky-cloud--3"></div>

  <!-- Distant Mountain Ridge -->
  <div class="mountain-ridge"></div>

  <!-- Parallax Rolling Hills -->
  <div class="hill-layer hill-far"></div>
  <div class="hill-layer hill-mid"></div>
  <div class="hill-layer hill-near"></div>

  <!-- Decorative Trees (silhouettes on hills) -->
  <div class="tree-deco tree-deco--1">🌲</div>
  <div class="tree-deco tree-deco--2">🌳</div>
  <div class="tree-deco tree-deco--3">🌲</div>
  <div class="tree-deco tree-deco--4">🌳</div>

  <!-- Ground Meadow Plane -->
  <div class="meadow-ground"></div>

  <!-- Rocks & Stones -->
  <div class="rock-deco rock-deco--1">🪨</div>
  <div class="rock-deco rock-deco--2">🪨</div>
  <div class="rock-deco rock-deco--3">🪨</div>

  <!-- Wildflower Patches -->
  <div class="wildflower-patch wildflower-patch--1">🌸🌼</div>
  <div class="wildflower-patch wildflower-patch--2">🌻🌺</div>
  <div class="wildflower-patch wildflower-patch--3">🌼🌸</div>
  <div class="wildflower-patch wildflower-patch--4">🌺🌻</div>

  <!-- Flying Dynamic Bees in Sky -->
  <div class="field-bee field-bee--1">
    <img src="/assets/game/bee_worker.png" alt="Bee">
  </div>
  <div class="field-bee field-bee--2">
    <img src="/assets/game/bee_queen.png" onerror="this.src='/assets/game/bee_worker.png'" alt="Bee">
  </div>
  <div class="field-bee field-bee--3">
    <img src="/assets/game/bee_worker.png" alt="Bee">
  </div>

  <!-- Floating Pollen Particles (spawned by JS) -->
  <div id="pollenContainer"></div>

  <!-- Ambient Golden Light Overlay -->
  <div class="ambient-glow"></div>

  <!-- ══════════════════════════════════════════════════════════
       3D TYCOON BEEHIVE STRUCTURES STANDING IN GREEN MEADOW
       (Tap any hive to open immersive interior inspection!)
       ══════════════════════════════════════════════════════════ -->
  <div class="meadow-content">
    <div class="meadow-trail-title">
      <div class="trail-title-pill">
        <i class="ph-fill ph-plant"></i> Kebun Sarang 3D (<?= count($user_hives) ?> Kandang)
      </div>
      <span style="font-size:11px;font-weight:800;color:#fff;text-shadow:0 1px 3px rgba(0,0,0,0.4);">Tap sarang untuk inspeksi</span>
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
                 onclick="openHiveInspection(<?= $uh['id'] ?>)">
              
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

              <!-- Ground Grass Patch -->
              <div class="beehive-grass-patch"></div>
              <!-- Contact Shadow on Grass -->
              <div class="beehive-ground-shadow"></div>
              <div class="hive-base-flower hive-base-flower--left"><?= $flowerL ?></div>
              <div class="hive-base-flower hive-base-flower--right"><?= $flowerR ?></div>

              <!-- Rustic Wooden Signpost Name Plank -->
              <div class="hive-ground-plank">
                <div class="hive-plank-name"><?= htmlspecialchars($uh['master_name']) ?></div>
                <div class="hive-plank-bees">
                  <?= (int)$det['bee_count'] ?>/<?= (int)$uh['max_slots'] ?> Lebah • +<?= number_format($prodRate, 1) ?> ml/jam
                </div>
              </div>

            </div>
          </div>
      <?php endforeach; ?>

        <!-- Expansion Signpost: Add New Hive Spot -->
        <div class="pasture-hill-tier pasture-hill-tier--center" style="margin-top: 10px;">
          <a href="/farm/shop" class="pasture-signpost-unit">
            <div class="signpost-board" style="background:linear-gradient(180deg,#059669,#047857);border-color:#064e3b;">
              <div class="signpost-title">+ Beli Sarang Baru</div>
              <div class="signpost-sub">Perluas kapasitas kebun lebahmu &rarr;</div>
            </div>
            <div class="signpost-pole" style="background:#064e3b;border-color:#022c22;"></div>
          </a>
        </div>

      <?php endif; ?>
    </div>
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

<!-- ══════════════════════════════════════════════════════════
     FULLSCREEN IMMERSIVE HIVE INSPECTION MODAL
     (Dark vignette — organic honeycomb frame — ONLY on tap)
     ══════════════════════════════════════════════════════════ -->
<div class="hive-inspection-overlay" id="hiveInspectionOverlay">
  <div class="hive-inspection-vignette" onclick="closeHiveInspection()"></div>

  <!-- Close button -->
  <button class="hive-inspection-close" onclick="closeHiveInspection()">
    <i class="ph-bold ph-x"></i>
  </button>

  <!-- Hive Name Title -->
  <div class="hive-inspection-title">
    <span id="inspectionHiveName">
      <i class="ph-fill ph-hexagon" style="color:#fbbf24;"></i>
      Sarang Lebah
    </span>
  </div>

  <!-- Central Organic Honeycomb Frame -->
  <div class="hive-inspection-frame">
    <div class="honeycomb-organic-frame">
      <!-- Crawling Bees over the honeycomb -->
      <div class="hive-crawling-bee bee-crawler-1">
        <img src="/assets/game/bee_worker.png" alt="Worker">
      </div>
      <div class="hive-crawling-bee bee-crawler-2">
        <img src="/assets/game/bee_golden.png" onerror="this.src='/assets/game/bee_worker.png'" alt="Worker">
      </div>
      <div class="hive-crawling-bee bee-crawler-3">
        <img src="/assets/game/bee_worker.png" alt="Worker">
      </div>

      <!-- 24 Hexagonal Cells Grid Matrix -->
      <div class="hex-grid" id="inspectionHexGrid"></div>
    </div>

    <!-- Honey Amount & Harvest Controls -->
    <div class="hive-inspection-info">
      <div class="inspection-honey-amount">
        <i class="ph-fill ph-drop"></i>
        <span id="inspectionHoneyAmt">0.00 ml</span>
      </div>
      <div class="inspection-honey-sub" id="inspectionHoneyCap">Kapasitas: 0 / 0 ml (0%)</div>
      <div class="inspection-bee-info" id="inspectionBeeInfo">
        <i class="ph-fill ph-bug-beetle"></i>
        <span>0 Lebah Aktif • +0.0 ml/jam</span>
      </div>
      <button type="button" class="btn-inspection-harvest" id="btnInspectionHarvest" onclick="harvestInspectedHive()">
        <i class="ph-fill ph-drop"></i>
        <span>PANEN MADU SARANG INI</span>
      </button>
    </div>
  </div>
</div>

<!-- CSRF Hidden Token -->
<?= csrf_field() ?>

<script>
// JSON cache of user hives loaded on page
const USER_HIVES_MAP = <?= json_encode(array_column($user_hives, null, 'id')) ?>;
let currentInspectedHiveId = 0;

// ── RENDER 24 3D HEXAGONAL CELLS ──
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
        const isHoney = cellIndex < honeyCellsCount;
        cell.className = 'hex-cell ' + (isHoney ? 'hex-cell--honey' : 'hex-cell--empty');
        rowDiv.appendChild(cell);
        cellIndex++;
      }
    }
    grid.appendChild(rowDiv);
  });
}

// ── OPEN IMMERSIVE HIVE INSPECTION ──
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

  // Update title
  document.getElementById('inspectionHiveName').innerHTML = 
    '<i class="ph-fill ph-hexagon" style="color:#fbbf24;"></i> ' +
    (hiveData.master_name || 'Sarang Lebah');

  // Update honey info
  document.getElementById('inspectionHoneyAmt').innerText = totalHoney.toFixed(2) + ' ml';
  document.getElementById('inspectionHoneyCap').innerText = 
    'Kapasitas: ' + totalHoney.toFixed(1) + ' / ' + maxCap.toFixed(0) + ' ml (' + pct + '%)';
  document.getElementById('inspectionBeeInfo').innerHTML = 
    '<i class="ph-fill ph-bug-beetle"></i> ' +
    '<span>' + beeCount + '/' + maxSlots + ' Lebah Aktif • +' + hourlyProd.toFixed(1) + ' ml/jam</span>';

  // Harvest button
  const btn = document.getElementById('btnInspectionHarvest');
  if (btn) btn.disabled = totalHoney < 0.1;

  // Render honeycomb cells
  renderHexagonCells(pct, 'inspectionHexGrid');

  // Show overlay
  document.getElementById('hiveInspectionOverlay').classList.add('active');
  document.body.style.overflow = 'hidden';
}

// ── CLOSE INSPECTION ──
function closeHiveInspection() {
  document.getElementById('hiveInspectionOverlay').classList.remove('active');
  document.body.style.overflow = '';
  currentInspectedHiveId = 0;
}

// Close on Escape key
document.addEventListener('keydown', function(e) {
  if (e.key === 'Escape') closeHiveInspection();
});

// ── HARVEST INSPECTED HIVE ──
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
      // Update top HUD honey stock
      const hudStock = document.getElementById('hudHoneyStock');
      if (hudStock && data.new_honey_stock !== undefined) {
        hudStock.innerText = Number(data.new_honey_stock).toLocaleString('id-ID', {minimumFractionDigits: 1}) + ' ml';
      }

      // Reset inspection display
      document.getElementById('inspectionHoneyAmt').innerText = '0.00 ml';
      renderHexagonCells(0, 'inspectionHexGrid');

      // Reset bubble on landscape
      const bubble = document.getElementById('bubbleVal_' + currentInspectedHiveId);
      if (bubble) bubble.innerText = '0.0 ml';

      if (USER_HIVES_MAP[currentInspectedHiveId] && USER_HIVES_MAP[currentInspectedHiveId].details) {
        USER_HIVES_MAP[currentInspectedHiveId].details.total_honey = 0;
        USER_HIVES_MAP[currentInspectedHiveId].details.fill_percentage = 0;
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

// ── SPAWN FLOATING HONEY BADGE ──
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

// ── HARVEST ALL HIVES ──
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

// ── PROCEDURAL POLLEN PARTICLES ──
function spawnPollenParticles() {
  const container = document.getElementById('pollenContainer');
  if (!container) return;

  const anims = ['pollenFloat1', 'pollenFloat2', 'pollenFloat3'];
  
  for (let i = 0; i < 15; i++) {
    const p = document.createElement('div');
    p.className = 'pollen-particle';
    p.style.left = (5 + Math.random() * 90) + '%';
    p.style.bottom = (20 + Math.random() * 35) + 'vh';
    p.style.width = (3 + Math.random() * 4) + 'px';
    p.style.height = p.style.width;

    const anim = anims[Math.floor(Math.random() * anims.length)];
    const dur = 6 + Math.random() * 8;
    const delay = Math.random() * 10;
    p.style.animation = anim + ' ' + dur + 's ease-in-out ' + delay + 's infinite';

    container.appendChild(p);
  }
}

// ── INIT ON PAGE LOAD ──
document.addEventListener('DOMContentLoaded', function() {
  spawnPollenParticles();
});
</script>

<?php require dirname(__DIR__) . '/partials/footer.php'; ?>
