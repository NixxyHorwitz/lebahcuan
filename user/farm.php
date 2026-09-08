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

// Ambil data terbaru user
$uStmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$uStmt->execute([$user['id']]);
$user = $uStmt->fetch(PDO::FETCH_ASSOC);

// Ambil kandang-kandang milik user
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

// Kalkulasi real-time per kandang
foreach ($user_hives as &$h) {
    $hDetail = BeeFarm::getHiveDetails($pdo, (int)$h['id'], (int)$user['id']);
    $h['details'] = $hDetail;
    $total_active_bees += (int)($hDetail['bee_count'] ?? 0);
    $total_hourly_rate += (float)($hDetail['hourly_production'] ?? 0.0);
    $total_unharvested_honey += (float)($hDetail['total_honey'] ?? 0.0);
}
unset($h);

// Ambil lapak aktif milik user
$active_stall = BeeFarm::getUserActiveStall($pdo, (int)$user['id']);

// Ambil master katalog
$hive_masters = $pdo->query("SELECT * FROM bee_hives_master WHERE is_active = 1 ORDER BY sort_order ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);
$bee_masters = $pdo->query("SELECT * FROM bee_types_master WHERE is_active = 1 ORDER BY sort_order ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);
$stall_masters = $pdo->query("SELECT * FROM bee_stalls_master WHERE is_active = 1 ORDER BY sort_order ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);

// Ambil riwayat aktivitas
$harvest_logs_stmt = $pdo->prepare("
    SELECT l.*, m.name as hive_name
    FROM bee_harvest_logs l
    JOIN user_bee_hives h ON h.id = l.hive_id
    JOIN bee_hives_master m ON m.id = h.hive_master_id
    WHERE l.user_id = ?
    ORDER BY l.id DESC LIMIT 10
");
$harvest_logs_stmt->execute([$user['id']]);
$recent_harvests = $harvest_logs_stmt->fetchAll(PDO::FETCH_ASSOC);

$sales_logs_stmt = $pdo->prepare("
    SELECT l.*, m.name as stall_name, m.tier_level
    FROM bee_sales_logs l
    JOIN user_bee_stalls s ON s.id = l.stall_id
    JOIN bee_stalls_master m ON m.id = s.stall_master_id
    WHERE l.user_id = ?
    ORDER BY l.id DESC LIMIT 10
");
$sales_logs_stmt->execute([$user['id']]);
$recent_sales = $sales_logs_stmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Peternakan Lebah Cuan';
$activePage = 'farm';
require dirname(__DIR__) . '/partials/header.php';
?>

<style>
/* ══════════════════════════════════════════════════════════
   BEE FARM CASUAL GAME UI — LIVING APIARY & 3D HEXAGONS
   ══════════════════════════════════════════════════════════ */
body { background: #fef08a !important; font-family: 'Nunito', sans-serif; overflow-x: hidden; }

/* ── TOP HEADER BAR ── */
.farm-top {
  background: linear-gradient(135deg, #f59e0b 0%, #d97706 50%, #b45309 100%);
  padding: 16px 14px 22px;
  position: relative; overflow: hidden;
  border-bottom: 4px solid #78350f;
  box-shadow: 0 6px 18px rgba(180,83,9,0.3);
}
.farm-top::before {
  content: ''; position: absolute; inset: 0;
  background: radial-gradient(circle, rgba(255,255,255,0.15) 10%, transparent 10%);
  background-size: 24px 24px; pointer-events: none;
}
.farm-top-row {
  display: flex; align-items: center; justify-content: space-between;
  position: relative; z-index: 2; margin-bottom: 14px;
}
.farm-back-btn {
  width: 38px; height: 38px; background: #fff;
  border: 3px solid #78350f; border-radius: 12px;
  box-shadow: 0 4px 0 #78350f; display: flex; align-items: center; justify-content: center;
  color: #78350f; font-size: 18px; text-decoration: none; transition: transform 0.1s;
}
.farm-back-btn:active { transform: translateY(3px); box-shadow: 0 1px 0 #78350f; }
.farm-title { font-size: 20px; font-weight: 900; color: #fff; text-shadow: 0 2px 0 #78350f; line-height: 1.1; }
.farm-sub { font-size: 11px; font-weight: 800; color: #fef3c7; }

/* ── BALANCES CHIPS ── */
.farm-chips {
  display: grid; grid-template-columns: 1fr 1fr; gap: 10px;
  position: relative; z-index: 2;
}
.farm-chip {
  background: #fff; border: 3px solid #78350f; border-radius: 16px;
  padding: 8px 12px; box-shadow: 0 4px 0 #78350f;
  display: flex; align-items: center; gap: 10px;
}
.farm-chip__icon {
  width: 34px; height: 34px; border-radius: 10px;
  display: flex; align-items: center; justify-content: center;
  font-size: 18px; color: #fff; flex-shrink: 0;
}
.farm-chip__icon--dep { background: linear-gradient(135deg, #3b82f6, #1d4ed8); border: 2px solid #1e3a8a; }
.farm-chip__icon--wd  { background: linear-gradient(135deg, #10b981, #059669); border: 2px solid #064e3b; }
.farm-chip__lbl { font-size: 9px; font-weight: 900; color: #64748b; text-transform: uppercase; }
.farm-chip__val { font-size: 13px; font-weight: 900; color: #1e293b; line-height: 1.2; }

/* ── LIVING APIARY SCENERY (TAMAN LEBAH HIDUP) ── */
.apiary-landscape {
  position: relative;
  background: linear-gradient(180deg, #38bdf8 0%, #7dd3fc 45%, #a7f3d0 60%, #86efac 100%);
  border-bottom: 4px solid #15803d;
  min-height: 340px;
  padding: 16px 14px 24px;
  overflow: hidden;
}
/* Awan Berarak */
.cloud {
  position: absolute; background: #fff; border-radius: 50px;
  opacity: 0.85; filter: drop-shadow(0 4px 0 rgba(0,0,0,0.06));
  animation: cloudDrift linear infinite;
  pointer-events: none; z-index: 1;
}
.cloud::before, .cloud::after { content: ''; position: absolute; background: #fff; border-radius: 50%; }
.cloud-1 { width: 90px; height: 32px; top: 18px; left: -100px; animation-duration: 22s; }
.cloud-1::before { width: 40px; height: 40px; top: -18px; left: 14px; }
.cloud-1::after  { width: 30px; height: 30px; top: -12px; left: 45px; }
.cloud-2 { width: 120px; height: 38px; top: 50px; left: -140px; animation-duration: 32s; animation-delay: 8s; opacity: 0.7; }
.cloud-2::before { width: 50px; height: 50px; top: -22px; left: 20px; }
@keyframes cloudDrift {
  from { transform: translateX(0); }
  to   { transform: translateX(650px); }
}

/* Partikel Bunga & Serbuk Sari Berkilau */
.pollen-field {
  position: absolute; inset: 0; pointer-events: none; z-index: 2;
  background-image:
    radial-gradient(circle, #fde047 1.5px, transparent 1.5px),
    radial-gradient(circle, #f59e0b 2px, transparent 2px),
    radial-gradient(circle, #ffffff 1px, transparent 1px);
  background-size: 80px 80px, 120px 120px, 60px 60px;
  background-position: 0 0, 40px 60px, 20px 30px;
  animation: pollenFloat 12s linear infinite;
  opacity: 0.7;
}
@keyframes pollenFloat {
  0%   { transform: translateY(0) scale(1); }
  50%  { transform: translateY(-15px) scale(1.02); }
  100% { transform: translateY(0) scale(1); }
}

/* Lebah Terbang Bebas di Udara */
.free-bee {
  position: absolute; width: 34px; height: 34px; z-index: 4;
  pointer-events: none; filter: drop-shadow(0 6px 4px rgba(0,0,0,0.25));
}
.free-bee img { width: 100%; height: 100%; object-fit: contain; }
.free-bee--1 { top: 75px; left: 15%; animation: beePath1 8s ease-in-out infinite; }
.free-bee--2 { top: 40px; right: 20%; animation: beePath2 11s ease-in-out infinite; }
.free-bee--3 { top: 120px; left: 60%; animation: beePath3 9s ease-in-out infinite; }
@keyframes beePath1 {
  0%   { transform: translate(0, 0) rotate(0deg) scale(1); }
  25%  { transform: translate(40px, -20px) rotate(12deg) scale(1.1); }
  50%  { transform: translate(70px, 10px) rotate(-8deg) scale(0.95); }
  75%  { transform: translate(25px, 25px) rotate(5deg) scale(1.05); }
  100% { transform: translate(0, 0) rotate(0deg) scale(1); }
}
@keyframes beePath2 {
  0%   { transform: translate(0, 0) scaleX(-1) rotate(0deg); }
  30%  { transform: translate(-50px, 20px) scaleX(-1) rotate(-15deg); }
  70%  { transform: translate(-30px, -30px) scaleX(-1) rotate(10deg); }
  100% { transform: translate(0, 0) scaleX(-1) rotate(0deg); }
}
@keyframes beePath3 {
  0%   { transform: translate(0, 0) rotate(0deg); }
  40%  { transform: translate(-35px, -25px) rotate(-10deg) scale(1.1); }
  80%  { transform: translate(20px, 15px) rotate(15deg) scale(0.9); }
  100% { transform: translate(0, 0) rotate(0deg); }
}

/* ── OVERVIEW STATS BANNER ── */
.farm-stats-bar {
  display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px;
  margin-bottom: 16px; position: relative; z-index: 3;
}
.stat-box {
  background: #ffffff; border: 3px solid #78350f; border-radius: 16px;
  padding: 10px 6px; text-align: center; box-shadow: 0 4px 0 #78350f;
  display: flex; flex-direction: column; align-items: center; justify-content: center;
}
.stat-box__val { font-size: 16px; font-weight: 900; color: #b45309; line-height: 1.1; margin-top: 2px; }
.stat-box__lbl { font-size: 9px; font-weight: 900; color: #64748b; text-transform: uppercase; margin-top: 2px; }
.stat-box__icon { font-size: 20px; line-height: 1; }

/* ── DERETAN KANDANG (HIVES GRID) ── */
.hives-header {
  display: flex; align-items: center; justify-content: space-between;
  margin-bottom: 12px; position: relative; z-index: 3;
}
.hives-title {
  font-size: 15px; font-weight: 900; color: #1e3a8a;
  display: flex; align-items: center; gap: 6px;
  background: #fff; border: 2.5px solid #1e3a8a; border-radius: 12px;
  padding: 4px 12px; box-shadow: 0 3px 0 #1e3a8a;
}
.hives-grid {
  display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px;
  position: relative; z-index: 3;
}
.hive-card {
  background: #fff; border: 3px solid #78350f; border-radius: 20px;
  box-shadow: 0 5px 0 #78350f; padding: 12px 10px 10px;
  position: relative; cursor: pointer; transition: transform 0.15s, box-shadow 0.15s;
  text-align: center; overflow: visible; -webkit-tap-highlight-color: transparent;
}
.hive-card:hover { transform: translateY(-3px); box-shadow: 0 8px 0 #78350f; }
.hive-card:active { transform: translateY(3px); box-shadow: 0 2px 0 #78350f; }

/* Bubble Indikator Madu Siap Panen */
.honey-bubble {
  position: absolute; top: -14px; right: -8px;
  background: linear-gradient(135deg, #f59e0b, #d97706);
  border: 2.5px solid #78350f; border-radius: 14px;
  padding: 4px 8px; box-shadow: 0 3px 0 #78350f;
  display: flex; align-items: center; gap: 4px;
  font-size: 10px; font-weight: 900; color: #fff;
  animation: honeyBounce 2s ease-in-out infinite;
  z-index: 5;
}
@keyframes honeyBounce {
  0%, 100% { transform: translateY(0); }
  50%      { transform: translateY(-6px); }
}

.hive-card__img {
  width: 96px; height: 96px; margin: 0 auto 6px;
  object-fit: contain; filter: drop-shadow(0 6px 6px rgba(0,0,0,0.18));
  transition: transform 0.2s;
}
.hive-card:hover .hive-card__img { transform: scale(1.08); }
.hive-card__title { font-size: 12px; font-weight: 900; color: #1e293b; line-height: 1.2; margin-bottom: 4px; }
.hive-card__slot {
  display: inline-flex; align-items: center; gap: 4px;
  background: #f1f5f9; border: 1.5px solid #cbd5e1; border-radius: 8px;
  padding: 2px 6px; font-size: 10px; font-weight: 800; color: #475569;
}
.hive-card__honey-bar {
  height: 8px; background: #e2e8f0; border-radius: 6px;
  overflow: hidden; margin-top: 8px; border: 1.5px solid #cbd5e1;
}
.hive-card__honey-fill {
  height: 100%;
  background: linear-gradient(90deg, #f59e0b, #eab308);
  border-radius: 6px; transition: width 0.4s ease;
}
.hive-card__action-btn {
  width: 100%; margin-top: 8px; padding: 6px 0;
  background: linear-gradient(135deg, #fbbf24, #f59e0b);
  border: 2px solid #b45309; border-radius: 10px;
  font-size: 11px; font-weight: 900; color: #78350f;
  box-shadow: 0 3px 0 #b45309; display: flex; align-items: center; justify-content: center; gap: 4px;
}

/* Empty Hives State */
.empty-apiary {
  background: #fff; border: 3.5px solid #78350f; border-radius: 24px;
  padding: 28px 18px; text-align: center; box-shadow: 0 6px 0 #78350f;
  margin-top: 10px; position: relative; z-index: 3;
}
.empty-apiary__img { width: 110px; height: 110px; margin: 0 auto 12px; object-fit: contain; }
.empty-apiary__title { font-size: 18px; font-weight: 900; color: #78350f; margin-bottom: 6px; }
.empty-apiary__desc { font-size: 12px; font-weight: 700; color: #64748b; margin-bottom: 18px; line-height: 1.4; }

/* ── NAV TABS ── */
.farm-tabs {
  display: flex; gap: 6px; padding: 14px 14px 0;
  overflow-x: auto; -webkit-overflow-scrolling: touch;
}
.farm-tabs::-webkit-scrollbar { display: none; }
.farm-tab {
  flex-shrink: 0; padding: 8px 14px; border-radius: 14px;
  font-size: 12px; font-weight: 900; border: 2.5px solid #78350f;
  background: #fff; color: #78350f; box-shadow: 0 3px 0 #78350f;
  cursor: pointer; display: flex; align-items: center; gap: 6px;
  transition: all 0.15s; text-decoration: none; font-family: 'Nunito', sans-serif;
}
.farm-tab.active {
  background: #f59e0b; color: #fff; text-shadow: 0 1px 2px rgba(0,0,0,0.25);
  box-shadow: 0 4px 0 #78350f; transform: translateY(-2px);
}

/* ── TAB PANELS ── */
.farm-body { padding: 14px 14px 100px; }
.tab-pane { display: none; }
.tab-pane.active { display: block; animation: tabFadeIn 0.2s ease; }
@keyframes tabFadeIn { from { opacity: 0; transform: translateY(4px); } to { opacity: 1; transform: none; } }

/* ── CATALOG ITEMS CARDS ── */
.catalog-grid { display: flex; flex-direction: column; gap: 12px; }
.catalog-card {
  background: #fff; border: 3px solid #78350f; border-radius: 20px;
  box-shadow: 0 5px 0 #78350f; padding: 14px;
  display: flex; gap: 14px; align-items: center;
}
.catalog-card__img {
  width: 78px; height: 78px; object-fit: contain; flex-shrink: 0;
  filter: drop-shadow(0 4px 4px rgba(0,0,0,0.15));
}
.catalog-card__info { flex: 1; min-width: 0; }
.catalog-card__title { font-size: 14px; font-weight: 900; color: #1e293b; line-height: 1.2; margin-bottom: 4px; }
.catalog-card__desc { font-size: 10px; font-weight: 700; color: #64748b; line-height: 1.3; margin-bottom: 6px; }
.catalog-card__tags { display: flex; flex-wrap: wrap; gap: 4px; margin-bottom: 8px; }
.cat-tag {
  font-size: 9px; font-weight: 800; padding: 2px 6px; border-radius: 6px;
  border: 1px solid;
}
.cat-tag--speed { background: #fef3c7; border-color: #f59e0b; color: #b45309; }
.cat-tag--slot  { background: #e0f2fe; border-color: #38bdf8; color: #0369a1; }
.cat-tag--prod  { background: #dcfce7; border-color: #4ade80; color: #15803d; }
.catalog-card__buy-row { display: flex; align-items: center; justify-content: space-between; gap: 8px; }
.catalog-card__price { font-size: 14px; font-weight: 900; color: #b45309; }
.btn-game {
  padding: 8px 14px; border-radius: 12px; font-size: 12px; font-weight: 900;
  border: 2.5px solid #78350f; cursor: pointer; transition: transform 0.1s;
  display: inline-flex; align-items: center; justify-content: center; gap: 4px;
  font-family: 'Nunito', sans-serif; text-decoration: none;
}
.btn-game:active { transform: translateY(3px); box-shadow: none !important; }
.btn-game--orange {
  background: linear-gradient(135deg, #f59e0b, #d97706);
  color: #fff; box-shadow: 0 3px 0 #78350f; text-shadow: 0 1px 1px rgba(0,0,0,0.2);
}
.btn-game--green {
  background: linear-gradient(135deg, #10b981, #059669);
  color: #fff; box-shadow: 0 3px 0 #064e3b; border-color: #064e3b; text-shadow: 0 1px 1px rgba(0,0,0,0.2);
}
.btn-game--blue {
  background: linear-gradient(135deg, #38bdf8, #0284c7);
  color: #fff; box-shadow: 0 3px 0 #0369a1; border-color: #0369a1;
}

/* ══════════════════════════════════════════════════════════
   MODAL INSPEKSI SARANG LEBAH (HIVE INTERIOR — HEXAGON MATRIX)
   ══════════════════════════════════════════════════════════ */
.hive-modal-backdrop {
  position: fixed; inset: 0; background: rgba(15, 23, 42, 0.75);
  backdrop-filter: blur(4px); z-index: 10000;
  display: none; align-items: center; justify-content: center;
  padding: 14px;
}
.hive-modal-backdrop.active { display: flex; }
.hive-modal-box {
  width: 100%; max-width: 420px; max-height: 90vh; overflow-y: auto;
  background: #fffbeb; border: 4px solid #78350f; border-radius: 28px;
  box-shadow: 0 12px 0 #78350f, 0 20px 40px rgba(0,0,0,0.4);
  padding: 20px 16px 18px; position: relative;
  animation: modalPop 0.25s cubic-bezier(0.34, 1.56, 0.64, 1);
}
@keyframes modalPop {
  from { opacity: 0; transform: scale(0.85); }
  to   { opacity: 1; transform: scale(1); }
}
.hive-modal-close {
  position: absolute; top: 12px; right: 12px;
  width: 32px; height: 32px; background: #fee2e2; border: 2.5px solid #991b1b;
  border-radius: 10px; color: #991b1b; font-size: 16px; font-weight: 900;
  display: flex; align-items: center; justify-content: center; cursor: pointer;
  box-shadow: 0 3px 0 #991b1b;
}
.hive-modal-close:active { transform: translateY(2px); box-shadow: none; }

/* Wooden Honeycomb Frame */
.honeycomb-frame {
  background: #78350f;
  background-image: repeating-linear-gradient(45deg, #78350f 0, #78350f 10px, #92400e 10px, #92400e 20px);
  border: 4px solid #451a03; border-radius: 20px;
  padding: 14px 10px; box-shadow: inset 0 4px 8px rgba(0,0,0,0.5), 0 4px 0 #451a03;
  margin: 12px 0 14px; position: relative; overflow: hidden;
}

/* Matriks Sel Hexagon Sarang Lebah */
.hex-grid {
  display: flex; flex-direction: column; align-items: center; gap: 4px;
  position: relative; z-index: 2;
}
.hex-row {
  display: flex; gap: 6px; justify-content: center;
}
.hex-row--offset { margin-left: 18px; }

/* Satuan Sel Hexagon 3D */
.hex-cell {
  width: 38px; height: 42px;
  position: relative;
  clip-path: polygon(50% 0%, 100% 25%, 100% 75%, 50% 100%, 0% 75%, 0% 25%);
  transition: all 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
  display: flex; align-items: center; justify-content: center;
}
/* Sel Kosong (Lilin Lebah) */
.hex-cell--empty {
  background: #451a03;
  border: 1px solid #78350f;
  box-shadow: inset 0 2px 4px rgba(0,0,0,0.7);
}
.hex-cell--empty::after {
  content: ''; position: absolute; inset: 3px;
  clip-path: polygon(50% 0%, 100% 25%, 100% 75%, 50% 100%, 0% 75%, 0% 25%);
  background: #271202; opacity: 0.9;
}
/* Sel Terisi Madu (Emas Berkilau Cair) */
.hex-cell--honey {
  background: linear-gradient(180deg, #fde047 0%, #eab308 50%, #b45309 100%);
  filter: drop-shadow(0 0 6px #f59e0b);
  animation: honeyCellGlow 2.5s ease-in-out infinite alternate;
}
.hex-cell--honey::after {
  content: ''; position: absolute; top: 4px; left: 8px; width: 14px; height: 10px;
  background: rgba(255, 255, 255, 0.65); border-radius: 50%;
  transform: rotate(-25deg); pointer-events: none;
}
@keyframes honeyCellGlow {
  0%   { filter: drop-shadow(0 0 3px #f59e0b); }
  100% { filter: drop-shadow(0 0 10px #fde047); }
}

/* Lebah Merayap di Dalam Sarang */
.hive-crawling-bee {
  position: absolute; width: 36px; height: 36px;
  z-index: 5; pointer-events: none;
  filter: drop-shadow(0 4px 6px rgba(0,0,0,0.5));
}
.hive-crawling-bee img {
  width: 100%; height: 100%; object-fit: contain;
  animation: beeWingFlap 0.08s linear infinite alternate;
}
@keyframes beeWingFlap {
  from { transform: scaleX(1); }
  to   { transform: scaleX(0.85) scaleY(1.05); }
}

/* Crawl trajectories */
.bee-crawler-1 { top: 20%; left: 25%; animation: crawl1 6s ease-in-out infinite; }
.bee-crawler-2 { top: 55%; right: 30%; animation: crawl2 8s ease-in-out infinite; }
.bee-crawler-3 { top: 35%; right: 20%; animation: crawl3 7s ease-in-out infinite; }
.bee-crawler-4 { top: 60%; left: 20%; animation: crawl4 9s ease-in-out infinite; }
@keyframes crawl1 {
  0%, 100% { transform: translate(0, 0) rotate(20deg); }
  50%      { transform: translate(35px, 20px) rotate(-30deg); }
}
@keyframes crawl2 {
  0%, 100% { transform: translate(0, 0) rotate(-45deg); }
  50%      { transform: translate(-30px, -25px) rotate(15deg); }
}
@keyframes crawl3 {
  0%, 100% { transform: translate(0, 0) rotate(10deg); }
  50%      { transform: translate(-25px, 30px) rotate(-20deg); }
}
@keyframes crawl4 {
  0%, 100% { transform: translate(0, 0) rotate(-15deg); }
  50%      { transform: translate(40px, -15px) rotate(25deg); }
}

/* Honey Counter & Harvest Area */
.harvest-box {
  background: #ffffff; border: 3px solid #78350f; border-radius: 18px;
  padding: 12px; text-align: center; box-shadow: 0 4px 0 #78350f;
  margin-bottom: 12px;
}
.harvest-honey-val {
  font-size: 26px; font-weight: 900; color: #b45309; line-height: 1;
  display: flex; align-items: center; justify-content: center; gap: 6px;
}
.btn-harvest {
  width: 100%; padding: 12px; border-radius: 14px;
  font-size: 15px; font-weight: 900; color: #fff;
  background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
  border: 3px solid #78350f; box-shadow: 0 5px 0 #78350f;
  cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 8px;
  font-family: 'Nunito', sans-serif; transition: transform 0.1s;
}
.btn-harvest:active { transform: translateY(3px); box-shadow: 0 2px 0 #78350f; }

/* ── FORM JUAL MADU ── */
.sell-stall-card {
  background: #fff; border: 3px solid #78350f; border-radius: 20px;
  box-shadow: 0 5px 0 #78350f; padding: 16px; margin-bottom: 14px;
}
.stall-preview-row { display: flex; align-items: center; gap: 12px; margin-bottom: 14px; }
.stall-preview-img { width: 68px; height: 68px; object-fit: contain; }
.sell-input-group {
  display: flex; align-items: center; gap: 8px; margin: 10px 0;
}
.sell-input {
  flex: 1; padding: 12px; border: 2.5px solid #78350f; border-radius: 12px;
  font-size: 16px; font-weight: 900; color: #1e293b; font-family: 'Nunito', sans-serif;
  box-shadow: inset 0 2px 4px rgba(0,0,0,0.06); outline: none;
}
.sell-calc-box {
  background: #fef3c7; border: 2px dashed #d97706; border-radius: 12px;
  padding: 10px; text-align: center; margin-bottom: 12px;
}
.sell-calc-amt { font-size: 18px; font-weight: 900; color: #059669; }

/* ════ THEMED BEE ALERT & CONFIRM MODAL ════ */
.bee-dialog-backdrop {
  position: fixed; inset: 0;
  background: rgba(15, 23, 42, 0.72);
  backdrop-filter: blur(8px);
  -webkit-backdrop-filter: blur(8px);
  z-index: 10000;
  display: none; align-items: center; justify-content: center;
  padding: 20px;
}
.bee-dialog-box {
  background: linear-gradient(180deg, #fffdfa 0%, #fffbeb 100%);
  border: 3.5px solid #78350f;
  border-radius: 26px;
  box-shadow: 0 20px 50px rgba(0,0,0,0.4), 0 8px 0 #78350f;
  max-width: 370px; width: 100%;
  padding: 26px 20px 22px;
  text-align: center; position: relative;
  animation: beeBounceIn 0.32s cubic-bezier(0.175, 0.885, 0.32, 1.275);
}
@keyframes beeBounceIn {
  0%   { opacity: 0; transform: scale(0.7) translateY(20px); }
  100% { opacity: 1; transform: scale(1) translateY(0); }
}
.bee-dialog-badge {
  width: 68px; height: 68px;
  background: linear-gradient(135deg, #fef08a 0%, #f59e0b 60%, #d97706 100%);
  border: 3.5px solid #78350f;
  border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  margin: -60px auto 14px;
  font-size: 32px;
  box-shadow: 0 8px 20px rgba(245,158,11,0.5), 0 4px 0 #78350f;
}
.bee-dialog-title {
  font-size: 19px; font-weight: 900;
  color: #78350f; margin-bottom: 8px;
  line-height: 1.25;
}
.bee-dialog-message {
  font-size: 13.5px; font-weight: 700;
  color: #475569; margin-bottom: 22px;
  line-height: 1.55; word-break: break-word;
}
.bee-dialog-actions {
  display: flex; gap: 10px; justify-content: center;
}
.btn-bee-dialog {
  flex: 1; padding: 12px 16px;
  border-radius: 16px; font-size: 13.5px; font-weight: 900;
  font-family: 'Nunito', sans-serif;
  cursor: pointer; transition: all 0.12s ease;
  display: inline-flex; align-items: center; justify-content: center; gap: 6px;
  border: 2.5px solid transparent;
  outline: none;
}
.btn-bee-dialog:active {
  transform: translateY(3px);
  box-shadow: 0 1px 0 rgba(0,0,0,0.3) !important;
}
.btn-bee-dialog--cancel {
  background: #f1f5f9; border-color: #94a3b8;
  color: #475569; box-shadow: 0 4px 0 #94a3b8;
}
.btn-bee-dialog--confirm {
  background: linear-gradient(180deg, #f59e0b 0%, #d97706 100%);
  border-color: #78350f; color: #fff;
  box-shadow: 0 4px 0 #78350f;
  text-shadow: 0 1px 1px rgba(0,0,0,0.2);
}
</style>

<!-- ════ TOP HEADER ════ -->
<div class="farm-top">
  <div class="farm-top-row">
    <div style="display:flex;align-items:center;gap:10px;">
      <a href="/home" class="farm-back-btn"><i class="ph-bold ph-arrow-left"></i></a>
      <div>
        <div class="farm-title">Peternakan Lebah Cuan 🐝</div>
        <div class="farm-sub">Panen madu murni & raup cuan setiap hari!</div>
      </div>
    </div>
  </div>

  <!-- Saldo Chips -->
  <div class="farm-chips">
    <div class="farm-chip">
      <div class="farm-chip__icon farm-chip__icon--dep"><i class="ph-fill ph-wallet"></i></div>
      <div>
        <div class="farm-chip__lbl">Saldo Deposit</div>
        <div class="farm-chip__val">Rp <?= number_format((float)$user['balance_dep'], 0, ',', '.') ?></div>
      </div>
    </div>
    <div class="farm-chip">
      <div class="farm-chip__icon farm-chip__icon--wd"><i class="ph-fill ph-money"></i></div>
      <div>
        <div class="farm-chip__lbl">Saldo Penarikan</div>
        <div class="farm-chip__val">Rp <?= number_format((float)$user['balance_wd'], 0, ',', '.') ?></div>
      </div>
    </div>
  </div>
</div>

<!-- ════ LIVING APIARY SCENERY (TAMAN LEBAH HIDUP) ════ -->
<div class="apiary-landscape">
  <!-- Awan Berarak -->
  <div class="cloud cloud-1"></div>
  <div class="cloud cloud-2"></div>
  <div class="pollen-field"></div>

  <!-- Lebah Terbang Bebas di Udara -->
  <div class="free-bee free-bee--1"><img src="/assets/game/bee_worker.png" alt="Bee"></div>
  <div class="free-bee free-bee--2"><img src="/assets/game/bee_golden.png" alt="Bee"></div>
  <div class="free-bee free-bee--3"><img src="/assets/game/bee_trigona.png" alt="Bee"></div>

  <!-- Stat Bar -->
  <div class="farm-stats-bar">
    <div class="stat-box">
      <span class="stat-box__icon">🍯</span>
      <div class="stat-box__val" id="top-honey-stock"><?= number_format((float)$user['honey_stock'], 1, ',', '.') ?> ml</div>
      <div class="stat-box__lbl">Stok Madu</div>
    </div>
    <div class="stat-box">
      <span class="stat-box__icon">🐝</span>
      <div class="stat-box__val"><?= $total_active_bees ?> Ekor</div>
      <div class="stat-box__lbl">Total Lebah</div>
    </div>
    <div class="stat-box">
      <span class="stat-box__icon">⚡</span>
      <div class="stat-box__val">+<?= number_format($total_hourly_rate, 1, ',', '.') ?>/j</div>
      <div class="stat-box__lbl">Laju Panen</div>
    </div>
  </div>

  <!-- Hives Section -->
  <div class="hives-header">
    <div class="hives-title"><i class="ph-fill ph-house-line"></i> Kandang Lebah Saya (<?= count($user_hives) ?>)</div>
    <button onclick="switchTab('buy_hive')" class="btn-game btn-game--orange" style="padding:4px 10px;font-size:11px;">
      <i class="ph-bold ph-plus"></i> Tambah Kandang
    </button>
  </div>

  <?php if (!empty($user_hives)): ?>
    <div class="hives-grid">
      <?php foreach ($user_hives as $h):
        $hDetail = $h['details'];
        $unharvested = (float)($hDetail['total_honey'] ?? 0);
        $fillPct = (float)($hDetail['fill_percentage'] ?? 0);
      ?>
      <div class="hive-card" onclick="openHiveInspection(<?= (int)$h['id'] ?>)">
        <!-- Bubble Notifikasi jika ada madu -->
        <?php if ($unharvested >= 0.5): ?>
        <div class="honey-bubble">
          <img src="/assets/game/honey_drop.png" style="width:14px;height:14px;" alt="Drop">
          <span>+<?= number_format($unharvested, 1) ?> ml</span>
        </div>
        <?php endif; ?>

        <img src="<?= htmlspecialchars($h['master_image']) ?>" class="hive-card__img" alt="<?= htmlspecialchars($h['master_name']) ?>">
        <div class="hive-card__title"><?= htmlspecialchars($h['custom_name'] ?: $h['master_name']) ?></div>
        
        <div class="hive-card__slot">
          <i class="ph-fill ph-shield-check"></i>
          <span><?= (int)$hDetail['bee_count'] ?> / <?= (int)$h['max_slots'] ?> Lebah</span>
        </div>

        <!-- Mini Progress Bar Madu -->
        <div class="hive-card__honey-bar">
          <div class="hive-card__honey-fill" style="width: <?= min(100, $fillPct) ?>%;"></div>
        </div>

        <button class="hive-card__action-btn">
          <i class="ph-bold ph-magnifying-glass-plus"></i> Buka Sarang
        </button>
      </div>
      <?php endforeach; ?>
    </div>
  <?php else: ?>
    <!-- Empty Garden State -->
    <div class="empty-apiary">
      <img src="/assets/game/beehive_wooden.png" class="empty-apiary__img" alt="Kandang Kosong">
      <div class="empty-apiary__title">Belum Ada Kandang Lebah!</div>
      <div class="empty-apiary__desc">
        Taman lebahmu masih sepi. Beli kandang lebah pertamamu dan masukkan lebah pekerja untuk mulai mengumpulkan madu manis!
      </div>
      <button onclick="switchTab('buy_hive')" class="btn-game btn-game--orange" style="padding:12px 24px;font-size:14px;">
        <i class="ph-fill ph-shopping-cart"></i> Beli Kandang Sekarang
      </button>
    </div>
  <?php endif; ?>
</div>

<!-- ════ NAV TABS ════ -->
<div class="farm-tabs">
  <button class="farm-tab active" id="tab-btn-market" onclick="switchTab('market')">
    <i class="ph-fill ph-storefront"></i> Lapak & Jual Madu
  </button>
  <button class="farm-tab" id="tab-btn-buy_bee" onclick="switchTab('buy_bee')">
    <i class="ph-fill ph-flower-lotus"></i> Beli Lebah
  </button>
  <button class="farm-tab" id="tab-btn-buy_hive" onclick="switchTab('buy_hive')">
    <i class="ph-fill ph-house-simple"></i> Beli Kandang
  </button>
  <button class="farm-tab" id="tab-btn-logs" onclick="switchTab('logs')">
    <i class="ph-fill ph-receipt"></i> Riwayat
  </button>
</div>

<!-- ════ TAB CONTENTS ════ -->
<div class="farm-body">

  <!-- ── 1. TAB PASAR & LAPAK MADU ── -->
  <div class="tab-pane active" id="pane-market">
    <?php if ($active_stall): ?>
      <!-- Kartu Lapak Aktif & Form Jual Madu -->
      <div class="sell-stall-card">
        <div class="stall-preview-row">
          <img src="<?= htmlspecialchars($active_stall['tier_image']) ?>" class="stall-preview-img" alt="Lapak">
          <div style="flex:1;">
            <div style="font-size:16px;font-weight:900;color:#1e293b;"><?= htmlspecialchars($active_stall['tier_name']) ?></div>
            <div style="font-size:11px;font-weight:700;color:#64748b;">Tier <?= (int)$active_stall['tier_level'] ?> • Aktif s/d <?= date('d M Y', strtotime((string)$active_stall['expires_at'])) ?></div>
            <div style="font-size:12px;font-weight:900;color:#059669;margin-top:2px;">
              Harga Jual: <b>Rp <?= number_format((float)$active_stall['sell_price_per_ml'], 0, ',', '.') ?></b> / ml
            </div>
          </div>
        </div>

        <!-- Kuota Jual Harian -->
        <div style="margin-bottom:12px;">
          <div style="display:flex;justify-content:space-between;font-size:11px;font-weight:900;color:#64748b;margin-bottom:4px;">
            <span>Kuota Terjual Hari Ini</span>
            <span><?= number_format((float)$active_stall['daily_sold_today'], 1) ?> / <?= number_format((float)$active_stall['daily_max_ml'], 0) ?> ml</span>
          </div>
          <div style="height:10px;background:#e2e8f0;border-radius:6px;overflow:hidden;border:1.5px solid #cbd5e1;">
            <div style="height:100%;background:linear-gradient(90deg,#10b981,#059669);width:<?= $active_stall['quota_percentage'] ?>%;"></div>
          </div>
          <div style="font-size:10px;font-weight:800;color:#059669;margin-top:4px;">
            Sisa kuota jual hari ini: <b><?= number_format((float)$active_stall['daily_remaining'], 1) ?> ml</b>
          </div>
        </div>

        <!-- Form Jual Madu -->
        <div style="border-top:2px dashed #e2e8f0;padding-top:12px;">
          <div style="font-size:13px;font-weight:900;color:#1e293b;">Jual Stok Madu ke Lapak</div>
          <div style="font-size:11px;font-weight:700;color:#64748b;margin-bottom:8px;">
            Madu yang terjual akan langsung dicairkan menjadi Rupiah ke <b>Saldo Penarikan</b>.
          </div>

          <div class="sell-input-group">
            <input type="number" step="0.1" id="sell-amount-input" class="sell-input" placeholder="Masukkan jumlah (ml)..." oninput="calculateHoneySale()">
            <button class="btn-game btn-game--blue" onclick="setMaxSellAmount(<?= (float)$user['honey_stock'] ?>, <?= (float)$active_stall['daily_remaining'] ?>)">
              Maksimal
            </button>
          </div>

          <div class="sell-calc-box">
            <div style="font-size:11px;font-weight:800;color:#78350f;">Perkiraan Pendapatan Bersih:</div>
            <div class="sell-calc-amt" id="sell-calc-display">Rp 0</div>
          </div>

          <button id="btn-sell-honey" class="btn-harvest" style="background:linear-gradient(135deg,#10b981,#059669);border-color:#064e3b;box-shadow:0 4px 0 #064e3b;" onclick="submitSellHoney()">
            <i class="ph-bold ph-hand-coins"></i> Jual Madu Sekarang
          </button>
        </div>
      </div>
    <?php else: ?>
      <!-- Belum Punya Lapak -->
      <div class="empty-apiary" style="border-color:#0284c7;box-shadow:0 6px 0 #0284c7;margin-bottom:16px;">
        <img src="/assets/game/honey_stall.png" class="empty-apiary__img" alt="Lapak">
        <div class="empty-apiary__title" style="color:#0284c7;">Kamu Belum Memiliki Lapak Madu!</div>
        <div class="empty-apiary__desc">
          Untuk menjual madu dan memperoleh uang tunai ke saldo penarikan, kamu harus menyewa atau membeli Lapak Madu terlebih dahulu.
        </div>
      </div>
    <?php endif; ?>

    <!-- Katalog Tier Lapak Madu -->
    <div style="font-size:14px;font-weight:900;color:#78350f;margin-bottom:10px;display:flex;align-items:center;gap:6px;">
      <i class="ph-fill ph-sparkle"></i> Katalog Upgrade / Sewa Lapak Madu
    </div>
    <div class="catalog-grid">
      <?php foreach ($stall_masters as $sm):
        $isCurrent = $active_stall && (int)$active_stall['stall_master_id'] === (int)$sm['id'];
      ?>
      <div class="catalog-card" style="<?= $isCurrent ? 'border-color:#059669;background:#f0fdf4;' : '' ?>">
        <img src="<?= htmlspecialchars($sm['image']) ?>" class="catalog-card__img" alt="<?= htmlspecialchars($sm['name']) ?>">
        <div class="catalog-card__info">
          <div class="catalog-card__title">
            Tier <?= (int)$sm['tier_level'] ?>: <?= htmlspecialchars($sm['name']) ?>
            <?php if ($isCurrent): ?>
              <span style="background:#10b981;color:#fff;font-size:9px;padding:2px 6px;border-radius:6px;">Lapak Aktif</span>
            <?php endif; ?>
          </div>
          <div class="catalog-card__desc"><?= htmlspecialchars($sm['description']) ?></div>
          <div class="catalog-card__tags">
            <span class="cat-tag cat-tag--prod">Harga Jual: Rp <?= number_format((float)$sm['sell_price_per_ml'], 0, ',', '.') ?>/ml</span>
            <span class="cat-tag cat-tag--slot">Maks: <?= number_format((float)$sm['daily_max_ml'], 0) ?> ml/hari</span>
            <span class="cat-tag cat-tag--speed"><?= (int)$sm['duration_days'] ?> Hari</span>
          </div>
          <div class="catalog-card__buy-row">
            <div class="catalog-card__price">Rp <?= number_format((float)$sm['price'], 0, ',', '.') ?></div>
            <button class="btn-game btn-game--green" onclick="buyStall(<?= (int)$sm['id'] ?>, '<?= htmlspecialchars(addslashes($sm['name'])) ?>', <?= (float)$sm['price'] ?>)">
              <?= $isCurrent ? 'Perpanjang' : 'Sewa Lapak' ?>
            </button>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- ── 2. TAB BELI LEBAH ── -->
  <div class="tab-pane" id="pane-buy_bee">
    <div style="background:#fff;border:3px solid #78350f;border-radius:16px;padding:12px;margin-bottom:14px;box-shadow:0 4px 0 #78350f;">
      <div style="font-size:12px;font-weight:900;color:#78350f;margin-bottom:4px;">Pilih Kandang Tujuan:</div>
      <?php if (!empty($user_hives)): ?>
        <select id="select-target-hive" style="width:100%;padding:10px;border:2.5px solid #78350f;border-radius:10px;font-size:13px;font-weight:800;color:#1e293b;font-family:'Nunito',sans-serif;outline:none;">
          <?php foreach ($user_hives as $uh):
            $isFull = (int)$uh['details']['bee_count'] >= (int)$uh['max_slots'];
          ?>
            <option value="<?= (int)$uh['id'] ?>" <?= $isFull ? 'disabled' : '' ?>>
              <?= htmlspecialchars($uh['custom_name'] ?: $uh['master_name']) ?> (Isi: <?= (int)$uh['details']['bee_count'] ?>/<?= (int)$uh['max_slots'] ?> Lebah) <?= $isFull ? '— PENUH' : '' ?>
            </option>
          <?php endforeach; ?>
        </select>
      <?php else: ?>
        <div style="font-size:11px;font-weight:700;color:#e11d48;">
          Kamu belum memiliki kandang! Silakan beli kandang terlebih dahulu di tab <b>Beli Kandang</b>.
        </div>
      <?php endif; ?>
    </div>

    <div class="catalog-grid">
      <?php foreach ($bee_masters as $bm): ?>
      <div class="catalog-card">
        <img src="<?= htmlspecialchars($bm['image']) ?>" class="catalog-card__img" alt="<?= htmlspecialchars($bm['name']) ?>">
        <div class="catalog-card__info">
          <div class="catalog-card__title"><?= htmlspecialchars($bm['name']) ?></div>
          <div class="catalog-card__desc"><?= htmlspecialchars($bm['description']) ?></div>
          <div class="catalog-card__tags">
            <span class="cat-tag cat-tag--prod"><i class="ph-fill ph-drop"></i> +<?= number_format((float)$bm['honey_per_hour'], 0) ?> ml/jam</span>
            <span class="cat-tag cat-tag--speed"><i class="ph-fill ph-clock"></i> <?= (int)$bm['duration_days'] ?> Hari</span>
          </div>
          <div class="catalog-card__buy-row">
            <div class="catalog-card__price">Rp <?= number_format((float)$bm['price'], 0, ',', '.') ?></div>
            <button class="btn-game btn-game--orange" onclick="buyBee(<?= (int)$bm['id'] ?>, '<?= htmlspecialchars(addslashes($bm['name'])) ?>', <?= (float)$bm['price'] ?>)">
              <i class="ph-bold ph-plus"></i> Beli Lebah
            </button>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- ── 3. TAB BELI KANDANG ── -->
  <div class="tab-pane" id="pane-buy_hive">
    <div class="catalog-grid">
      <?php foreach ($hive_masters as $hm): ?>
      <div class="catalog-card">
        <img src="<?= htmlspecialchars($hm['image']) ?>" class="catalog-card__img" alt="<?= htmlspecialchars($hm['name']) ?>">
        <div class="catalog-card__info">
          <div class="catalog-card__title"><?= htmlspecialchars($hm['name']) ?></div>
          <div class="catalog-card__desc"><?= htmlspecialchars($hm['description']) ?></div>
          <div class="catalog-card__tags">
            <span class="cat-tag cat-tag--slot"><i class="ph-fill ph-users"></i> Muat <?= (int)$hm['max_slots'] ?> Lebah</span>
            <?php if ((int)$hm['bonus_speed_pct'] > 0): ?>
              <span class="cat-tag cat-tag--speed"><i class="ph-fill ph-lightning"></i> Bonus +<?= (int)$hm['bonus_speed_pct'] ?>% Speed</span>
            <?php endif; ?>
            <span class="cat-tag cat-tag--prod"><i class="ph-fill ph-clock"></i> <?= (int)$hm['duration_days'] ?> Hari</span>
          </div>
          <div class="catalog-card__buy-row">
            <div class="catalog-card__price">Rp <?= number_format((float)$hm['price'], 0, ',', '.') ?></div>
            <button class="btn-game btn-game--orange" onclick="buyHive(<?= (int)$hm['id'] ?>, '<?= htmlspecialchars(addslashes($hm['name'])) ?>', <?= (float)$hm['price'] ?>)">
              <i class="ph-bold ph-shopping-cart"></i> Beli Kandang
            </button>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- ── 4. TAB RIWAYAT ── -->
  <div class="tab-pane" id="pane-logs">
    <div style="font-size:13px;font-weight:900;color:#78350f;margin-bottom:8px;">📜 Riwayat Panen Terakhir</div>
    <?php if (!empty($recent_harvests)): ?>
      <div style="background:#fff;border:3px solid #78350f;border-radius:16px;overflow:hidden;box-shadow:0 4px 0 #78350f;margin-bottom:16px;">
        <?php foreach ($recent_harvests as $rh): ?>
        <div style="padding:10px 14px;border-bottom:1px solid #f1f5f9;display:flex;justify-content:space-between;align-items:center;">
          <div>
            <div style="font-size:12px;font-weight:900;color:#1e293b;"><?= htmlspecialchars($rh['hive_name']) ?></div>
            <div style="font-size:10px;font-weight:700;color:#94a3b8;"><?= date('d M Y, H:i', strtotime((string)$rh['harvested_at'])) ?></div>
          </div>
          <div style="font-size:13px;font-weight:900;color:#d97706;">
            +<?= number_format((float)$rh['amount_ml'], 2, ',', '.') ?> ml
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <div style="background:#fff;border:2.5px solid #cbd5e1;border-radius:14px;padding:14px;text-align:center;font-size:11px;font-weight:700;color:#94a3b8;margin-bottom:16px;">
        Belum ada riwayat panen madu.
      </div>
    <?php endif; ?>

    <div style="font-size:13px;font-weight:900;color:#78350f;margin-bottom:8px;">💰 Riwayat Penjualan Madu</div>
    <?php if (!empty($recent_sales)): ?>
      <div style="background:#fff;border:3px solid #78350f;border-radius:16px;overflow:hidden;box-shadow:0 4px 0 #78350f;">
        <?php foreach ($recent_sales as $rs): ?>
        <div style="padding:10px 14px;border-bottom:1px solid #f1f5f9;display:flex;justify-content:space-between;align-items:center;">
          <div>
            <div style="font-size:12px;font-weight:900;color:#1e293b;"><?= htmlspecialchars($rs['stall_name']) ?> (<?= number_format((float)$rs['amount_ml'], 1) ?> ml)</div>
            <div style="font-size:10px;font-weight:700;color:#94a3b8;"><?= date('d M Y, H:i', strtotime((string)$rs['created_at'])) ?></div>
          </div>
          <div style="font-size:13px;font-weight:900;color:#059669;">
            +Rp <?= number_format((float)$rs['total_revenue'], 0, ',', '.') ?>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <div style="background:#fff;border:2.5px solid #cbd5e1;border-radius:14px;padding:14px;text-align:center;font-size:11px;font-weight:700;color:#94a3b8;">
        Belum ada riwayat penjualan madu.
      </div>
    <?php endif; ?>
  </div>

</div>

<!-- ══════════════════════════════════════════════════════════
     MODAL INSPEKSI SARANG LEBAH (HIVE INTERIOR — HEXAGON MATRIX)
     ══════════════════════════════════════════════════════════ -->
<div class="hive-modal-backdrop" id="hive-modal" onclick="closeHiveInspectionOnBackdrop(event)">
  <div class="hive-modal-box">
    <button class="hive-modal-close" onclick="closeHiveInspection()"><i class="ph-bold ph-x"></i></button>

    <div style="text-align:center;margin-bottom:6px;">
      <div style="font-size:18px;font-weight:900;color:#78350f;" id="modal-hive-title">Sarang Lebah</div>
      <div style="font-size:11px;font-weight:800;color:#b45309;" id="modal-hive-subtitle">Kapasitas: 4 Lebah • Bonus +10% Speed</div>
    </div>

    <!-- Wooden Honeycomb Frame with Hexagon Grid & Crawling Bees -->
    <div class="honeycomb-frame">
      <!-- Lebah-lebah merayap di atas sel -->
      <div id="modal-crawling-bees-container"></div>

      <!-- Hexagon Grid (24 cells) -->
      <div class="hex-grid" id="modal-hex-grid"></div>
    </div>

    <!-- Status Panen & Tombol -->
    <div class="harvest-box">
      <div style="font-size:11px;font-weight:800;color:#64748b;margin-bottom:2px;">Madu Siap Dipanen:</div>
      <div class="harvest-honey-val">
        <img src="/assets/game/honey_drop.png" style="width:24px;height:24px;" alt="Drop">
        <span id="modal-honey-amount">0.00 ml</span>
      </div>
      <div style="font-size:10px;font-weight:800;color:#b45309;margin-top:2px;" id="modal-honey-cap">
        Kapasitas Sarang: 0 / 300 ml
      </div>
    </div>

    <button id="btn-modal-harvest" class="btn-harvest" onclick="executeHarvestFromModal()">
      <i class="ph-fill ph-drop"></i> Panen Madu Sekarang!
    </button>

    <!-- Daftar Lebah di Kandang Ini -->
    <div style="margin-top:14px;border-top:2px dashed #fcd34d;padding-top:10px;">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
        <span style="font-size:12px;font-weight:900;color:#78350f;">Penghuni Sarang (<span id="modal-bees-count">0</span>):</span>
        <button id="btn-modal-add-bee" class="btn-game btn-game--blue" style="padding:4px 8px;font-size:10px;" onclick="goToAddBeeForCurrentHive()">
          <i class="ph-bold ph-plus"></i> Tambah Lebah
        </button>
      </div>
      <div id="modal-bees-list" style="display:flex;flex-direction:column;gap:6px;max-height:160px;overflow-y:auto;"></div>
    </div>

  </div>
</div>

<!-- ════ THEMED BEE FARM DIALOG (ALERT & CONFIRM) ════ -->
<div class="bee-dialog-backdrop" id="bee-dialog-backdrop">
  <div class="bee-dialog-box" id="bee-dialog-box">
    <div class="bee-dialog-badge" id="bee-dialog-badge">
      <span id="bee-dialog-icon">🐝</span>
    </div>
    <div class="bee-dialog-title" id="bee-dialog-title">Informasi Peternakan</div>
    <div class="bee-dialog-message" id="bee-dialog-message">Pesan peternakan lebah</div>
    <div class="bee-dialog-actions" id="bee-dialog-actions"></div>
  </div>
</div>

<script>
const _csrf = '<?= csrf_token() ?>';
let currentActiveHiveId = null;
let currentHiveData = null;
let activeStallPricePerMl = <?= (float)($active_stall['sell_price_per_ml'] ?? 0) ?>;

// ════ THEMED BEE DIALOG CONTROLLER (REPLACES NATIVE ALERT/CONFIRM) ════
function beeAlert(message, type = 'info', title = null) {
  return new Promise((resolve) => {
    const backdrop = document.getElementById('bee-dialog-backdrop');
    const badge = document.getElementById('bee-dialog-badge');
    const iconEl = document.getElementById('bee-dialog-icon');
    const titleEl = document.getElementById('bee-dialog-title');
    const msgEl = document.getElementById('bee-dialog-message');
    const actionsEl = document.getElementById('bee-dialog-actions');

    let defaultTitle = 'Informasi Peternakan';
    let icon = '🐝';
    let btnBg = 'linear-gradient(180deg, #f59e0b 0%, #d97706 100%)';
    let btnBorder = '#78350f';
    let badgeBg = 'linear-gradient(135deg, #fef08a 0%, #f59e0b 60%, #d97706 100%)';

    if (type === 'success') {
      defaultTitle = 'Berhasil! 🎉';
      icon = '🍯';
      btnBg = 'linear-gradient(180deg, #10b981 0%, #059669 100%)';
      btnBorder = '#065f46';
      badgeBg = 'linear-gradient(135deg, #a7f3d0 0%, #10b981 100%)';
    } else if (type === 'error') {
      defaultTitle = 'Perhatian! ⚠️';
      icon = '🐝';
      btnBg = 'linear-gradient(180deg, #ef4444 0%, #dc2626 100%)';
      btnBorder = '#991b1b';
      badgeBg = 'linear-gradient(135deg, #fecaca 0%, #ef4444 100%)';
    } else if (type === 'warning') {
      defaultTitle = 'Peringatan ⚠️';
      icon = '⚠️';
      btnBg = 'linear-gradient(180deg, #f59e0b 0%, #d97706 100%)';
      btnBorder = '#78350f';
      badgeBg = 'linear-gradient(135deg, #fef08a 0%, #f59e0b 100%)';
    }

    badge.style.background = badgeBg;
    titleEl.innerText = title || defaultTitle;
    msgEl.innerHTML = message;
    iconEl.innerText = icon;

    actionsEl.innerHTML = `
      <button type="button" class="btn-bee-dialog" style="background:${btnBg};border-color:${btnBorder};box-shadow:0 4px 0 ${btnBorder};color:#fff;width:100%;" id="bee-dialog-btn-ok">
        OK, Mengerti 👍
      </button>
    `;

    backdrop.style.display = 'flex';

    document.getElementById('bee-dialog-btn-ok').onclick = function() {
      backdrop.style.display = 'none';
      resolve(true);
    };
  });
}

function beeConfirm({ title = 'Konfirmasi', message, icon = '🐝', confirmText = 'Ya, Lanjutkan', cancelText = 'Batal' }) {
  return new Promise((resolve) => {
    const backdrop = document.getElementById('bee-dialog-backdrop');
    const badge = document.getElementById('bee-dialog-badge');
    const iconEl = document.getElementById('bee-dialog-icon');
    const titleEl = document.getElementById('bee-dialog-title');
    const msgEl = document.getElementById('bee-dialog-message');
    const actionsEl = document.getElementById('bee-dialog-actions');

    badge.style.background = 'linear-gradient(135deg, #fef08a 0%, #f59e0b 60%, #d97706 100%)';
    titleEl.innerText = title;
    msgEl.innerHTML = message;
    iconEl.innerText = icon;

    actionsEl.innerHTML = `
      <button type="button" class="btn-bee-dialog btn-bee-dialog--cancel" id="bee-dialog-btn-cancel">
        ${cancelText}
      </button>
      <button type="button" class="btn-bee-dialog btn-bee-dialog--confirm" id="bee-dialog-btn-confirm">
        ${confirmText}
      </button>
    `;

    backdrop.style.display = 'flex';

    document.getElementById('bee-dialog-btn-cancel').onclick = function() {
      backdrop.style.display = 'none';
      resolve(false);
    };

    document.getElementById('bee-dialog-btn-confirm').onclick = function() {
      backdrop.style.display = 'none';
      resolve(true);
    };
  });
}

// Audio context untuk efek panen madu yang renyah
function playHarvestSound() {
  try {
    const AudioCtx = window.AudioContext || window.webkitAudioContext;
    if (!AudioCtx) return;
    const actx = new AudioCtx();
    const osc = actx.createOscillator();
    const gain = actx.createGain();
    osc.connect(gain);
    gain.connect(actx.destination);
    osc.type = 'triangle';
    osc.frequency.setValueAtTime(523.25, actx.currentTime); // C5
    osc.frequency.exponentialRampToValueAtTime(1046.5, actx.currentTime + 0.15); // C6
    gain.gain.setValueAtTime(0.25, actx.currentTime);
    gain.gain.exponentialRampToValueAtTime(0.01, actx.currentTime + 0.35);
    osc.start();
    osc.stop(actx.currentTime + 0.35);
  } catch(e) {}
}

// Tab switcher
function switchTab(tabId) {
  document.querySelectorAll('.farm-tab').forEach(b => b.classList.remove('active'));
  document.querySelectorAll('.tab-pane').forEach(p => p.classList.remove('active'));
  const btn = document.getElementById('tab-btn-' + tabId);
  const pane = document.getElementById('pane-' + tabId);
  if (btn) btn.classList.add('active');
  if (pane) pane.classList.add('active');
}

// Buka Modal Inspeksi Sarang Lebah (Hive Interior)
function openHiveInspection(hiveId) {
  currentActiveHiveId = hiveId;
  const modal = document.getElementById('hive-modal');
  modal.classList.add('active');
  loadHiveDetails(hiveId);
}

function closeHiveInspection() {
  const modal = document.getElementById('hive-modal');
  modal.classList.remove('active');
  currentActiveHiveId = null;
}

function closeHiveInspectionOnBackdrop(e) {
  if (e.target.id === 'hive-modal') {
    closeHiveInspection();
  }
}

// Load data real-time sarang lebah via API
function loadHiveDetails(hiveId) {
  fetch(`/api/farm_action.php?action=get_hive_detail&hive_id=${hiveId}`)
    .then(r => r.json())
    .then(res => {
      if (res.ok && res.data) {
        currentHiveData = res.data;
        renderHiveInterior(res.data);
      } else {
        beeAlert(res.msg || 'Gagal memuat isi kandang.', 'error');
      }
    })
    .catch(() => beeAlert('Terjadi kesalahan jaringan saat memuat isi kandang.', 'error'));
}

// Render tampilan isi sarang hexagon
function renderHiveInterior(data) {
  const hive = data.hive;
  document.getElementById('modal-hive-title').innerText = hive.custom_name || hive.master_name;
  document.getElementById('modal-hive-subtitle').innerText =
    `Kapasitas: ${hive.max_slots} Lebah • Bonus +${hive.bonus_speed_pct}% Kecepatan`;

  document.getElementById('modal-honey-amount').innerText = (parseFloat(data.total_honey) || 0).toFixed(2) + ' ml';
  document.getElementById('modal-honey-cap').innerText =
    `Kapasitas Sarang: ${(parseFloat(data.total_honey) || 0).toFixed(1)} / ${(parseFloat(data.max_capacity) || 0).toFixed(0)} ml (${data.fill_percentage}%)`;

  document.getElementById('modal-bees-count').innerText = `${data.bee_count} / ${hive.max_slots}`;

  // 1. Render Sel-sel Hexagon (24 sel total: 4 baris)
  const totalCells = 24;
  const honeyCellsCount = Math.round(totalCells * (data.fill_percentage / 100));
  const gridContainer = document.getElementById('modal-hex-grid');
  gridContainer.innerHTML = '';

  const rows = [6, 5, 6, 5, 2]; // susunan sarang lebah
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
    gridContainer.appendChild(rowDiv);
  });

  // 2. Render Lebah Merayap Hidup di Sarang
  const beesContainer = document.getElementById('modal-crawling-bees-container');
  beesContainer.innerHTML = '';
  const beeCount = Math.min(4, data.bee_count);
  for (let i = 1; i <= beeCount; i++) {
    const beeDiv = document.createElement('div');
    beeDiv.className = `hive-crawling-bee bee-crawler-${i}`;
    const beeImg = data.bees[i - 1]?.type_image || '/assets/game/bee_worker.png';
    beeDiv.innerHTML = `<img src="${beeImg}" alt="Bee">`;
    beesContainer.appendChild(beeDiv);
  }

  // 3. Render List Lebah
  const beesList = document.getElementById('modal-bees-list');
  beesList.innerHTML = '';
  if (data.bees && data.bees.length > 0) {
    data.bees.forEach(b => {
      const item = document.createElement('div');
      item.style.cssText = 'background:#fff;border:1.5px solid #cbd5e1;border-radius:10px;padding:6px 10px;display:flex;align-items:center;justify-content:space-between;';
      item.innerHTML = `
        <div style="display:flex;align-items:center;gap:8px;">
          <img src="${b.type_image}" style="width:24px;height:24px;object-fit:contain;" alt="">
          <div>
            <div style="font-size:11px;font-weight:900;color:#1e293b;">${b.type_name}</div>
            <div style="font-size:9px;font-weight:700;color:#64748b;">+${b.effective_rate} ml/jam</div>
          </div>
        </div>
        <span style="font-size:10px;font-weight:800;color:#10b981;">Aktif</span>
      `;
      beesList.appendChild(item);
    });
  } else {
    beesList.innerHTML = '<div style="font-size:11px;color:#94a3b8;text-align:center;padding:8px;">Kandang ini masih kosong dari lebah.</div>';
  }

  // Tombol tambah lebah
  const addBtn = document.getElementById('btn-modal-add-bee');
  if (data.bee_count >= hive.max_slots) {
    addBtn.style.display = 'none';
  } else {
    addBtn.style.display = 'inline-flex';
  }
}

// Panen madu dari modal
async function executeHarvestFromModal() {
  if (!currentActiveHiveId) return;
  const btn = document.getElementById('btn-modal-harvest');
  btn.disabled = true;
  btn.innerHTML = '<i class="ph-bold ph-spinner-gap" style="animation:spin 0.8s linear infinite"></i> Memanen madu...';

  const fd = new FormData();
  fd.append('action', 'harvest_hive');
  fd.append('hive_id', currentActiveHiveId);
  fd.append('_csrf', _csrf);

  try {
    const r = await fetch('/api/farm_action.php', { method: 'POST', body: fd });
    const res = await r.json();
    btn.disabled = false;
    btn.innerHTML = '<i class="ph-fill ph-drop"></i> Panen Madu Sekarang!';
    if (res.ok) {
      playHarvestSound();
      await beeAlert(res.msg, 'success', 'Panen Berhasil! 🍯');
      document.getElementById('top-honey-stock').innerText = parseFloat(res.new_honey_stock).toFixed(1) + ' ml';
      loadHiveDetails(currentActiveHiveId);
      location.reload();
    } else {
      await beeAlert(res.msg || 'Gagal memproses panen madu.', 'error', 'Panen Gagal');
    }
  } catch (err) {
    btn.disabled = false;
    btn.innerHTML = '<i class="ph-fill ph-drop"></i> Panen Madu Sekarang!';
    await beeAlert('Koneksi terputus saat memproses panen madu.', 'error');
  }
}

// Beralih ke tab beli lebah dengan auto-select kandang saat ini
function goToAddBeeForCurrentHive() {
  closeHiveInspection();
  switchTab('buy_bee');
  const sel = document.getElementById('select-target-hive');
  if (sel && currentActiveHiveId) sel.value = currentActiveHiveId;
}

// Kalkulator Penjualan Madu
function calculateHoneySale() {
  const input = document.getElementById('sell-amount-input');
  const display = document.getElementById('sell-calc-display');
  const amt = parseFloat(input.value) || 0;
  const total = Math.round(amt * activeStallPricePerMl);
  display.innerText = 'Rp ' + total.toLocaleString('id-ID');
}

function setMaxSellAmount(honeyStock, dailyRemaining) {
  const maxCanSell = Math.max(0, Math.min(honeyStock, dailyRemaining));
  const input = document.getElementById('sell-amount-input');
  input.value = maxCanSell.toFixed(1);
  calculateHoneySale();
}

// Submit penjualan madu
async function submitSellHoney() {
  const input = document.getElementById('sell-amount-input');
  const amt = parseFloat(input.value) || 0;
  if (amt <= 0) {
    await beeAlert('Silakan masukkan jumlah <strong>ml madu</strong> yang ingin dijual ke lapak.', 'warning', 'Jumlah Madu Kosong');
    return;
  }

  const estTotal = Math.round(amt * activeStallPricePerMl);
  const confirmed = await beeConfirm({
    title: 'Jual Madu Murni',
    icon: '💰',
    message: `Jual <strong>${amt.toFixed(1)} ml madu</strong>? Estimasi cuan <strong style="color:#059669">Rp ${estTotal.toLocaleString('id-ID')}</strong> akan langsung masuk ke Saldo Penarikan.`,
    confirmText: 'Jual Sekarang 💰',
    cancelText: 'Batal'
  });
  if (!confirmed) return;

  const btn = document.getElementById('btn-sell-honey');
  btn.disabled = true;
  btn.innerHTML = '<i class="ph-bold ph-spinner-gap" style="animation:spin 0.8s linear infinite"></i> Memproses penjualan...';

  const fd = new FormData();
  fd.append('action', 'sell_honey');
  fd.append('amount_ml', amt);
  fd.append('_csrf', _csrf);

  try {
    const r = await fetch('/api/farm_action.php', { method: 'POST', body: fd });
    const res = await r.json();
    btn.disabled = false;
    btn.innerHTML = '<i class="ph-bold ph-hand-coins"></i> Jual Madu Sekarang';
    if (res.ok) {
      playHarvestSound();
      await beeAlert(res.msg, 'success', 'Penjualan Berhasil! 💰');
      location.reload();
    } else {
      await beeAlert(res.msg || 'Gagal memproses penjualan madu.', 'error', 'Penjualan Gagal');
    }
  } catch (err) {
    btn.disabled = false;
    btn.innerHTML = '<i class="ph-bold ph-hand-coins"></i> Jual Madu Sekarang';
    await beeAlert('Koneksi terputus saat memproses penjualan madu.', 'error');
  }
}

// Beli Kandang
async function buyHive(hiveMasterId, name, price) {
  const confirmed = await beeConfirm({
    title: 'Beli Kandang Lebah',
    icon: '🏡',
    message: `Beli <strong>${name}</strong> seharga <span style="color:#d97706;font-weight:900;">Rp ${price.toLocaleString('id-ID')}</span> menggunakan Saldo Deposit?`,
    confirmText: 'Beli Sekarang 🐝',
    cancelText: 'Batal'
  });
  if (!confirmed) return;

  const fd = new FormData();
  fd.append('action', 'buy_hive');
  fd.append('hive_master_id', hiveMasterId);
  fd.append('_csrf', _csrf);

  try {
    const r = await fetch('/api/farm_action.php', { method: 'POST', body: fd });
    const res = await r.json();
    if (res.ok) {
      playHarvestSound();
      await beeAlert(res.msg, 'success', 'Kandang Siap! 🏡');
      location.reload();
    } else {
      await beeAlert(res.msg || 'Gagal memproses pembelian kandang.', 'error', 'Pembelian Gagal');
    }
  } catch (err) {
    await beeAlert('Terjadi kendala koneksi saat memproses transaksi.', 'error');
  }
}

// Beli Lebah
async function buyBee(beeTypeId, name, price) {
  const sel = document.getElementById('select-target-hive');
  if (!sel || !sel.value) {
    await beeAlert('Silakan pilih <strong>kandang tujuan</strong> terlebih dahulu sebelum membeli lebah pekerja.', 'warning', 'Pilih Kandang');
    return;
  }
  const targetHiveId = sel.value;

  const confirmed = await beeConfirm({
    title: 'Adopsi Lebah Pekerja',
    icon: '🐝',
    message: `Beli <strong>${name}</strong> seharga <span style="color:#d97706;font-weight:900;">Rp ${price.toLocaleString('id-ID')}</span> untuk dimasukkan ke kandang pilihan?`,
    confirmText: 'Adopsi Sekarang 🐝',
    cancelText: 'Batal'
  });
  if (!confirmed) return;

  const fd = new FormData();
  fd.append('action', 'buy_bee');
  fd.append('bee_type_id', beeTypeId);
  fd.append('target_hive_id', targetHiveId);
  fd.append('_csrf', _csrf);

  try {
    const r = await fetch('/api/farm_action.php', { method: 'POST', body: fd });
    const res = await r.json();
    if (res.ok) {
      playHarvestSound();
      await beeAlert(res.msg, 'success', 'Lebah Siap Bekerja! 🐝');
      location.reload();
    } else {
      await beeAlert(res.msg || 'Gagal membeli lebah pekerja.', 'error', 'Gagal Membeli');
    }
  } catch (err) {
    await beeAlert('Terjadi kendala koneksi saat memproses transaksi.', 'error');
  }
}

// Beli Lapak
async function buyStall(stallMasterId, name, price) {
  const confirmed = await beeConfirm({
    title: 'Sewa Lapak Madu',
    icon: '🏪',
    message: `Sewa/Beli <strong>${name}</strong> seharga <span style="color:#d97706;font-weight:900;">Rp ${price.toLocaleString('id-ID')}</span> menggunakan Saldo Deposit?`,
    confirmText: 'Sewa Lapak 🏪',
    cancelText: 'Batal'
  });
  if (!confirmed) return;

  const fd = new FormData();
  fd.append('action', 'buy_stall');
  fd.append('stall_master_id', stallMasterId);
  fd.append('_csrf', _csrf);

  try {
    const r = await fetch('/api/farm_action.php', { method: 'POST', body: fd });
    const res = await r.json();
    if (res.ok) {
      playHarvestSound();
      await beeAlert(res.msg, 'success', 'Lapak Siap Digunakan! 🏪');
      location.reload();
    } else {
      await beeAlert(res.msg || 'Gagal menyewa lapak.', 'error', 'Gagal Sewa');
    }
  } catch (err) {
    await beeAlert('Terjadi kendala koneksi saat memproses transaksi.', 'error');
  }
}
</script>

<?php require dirname(__DIR__) . '/partials/footer.php'; ?>
