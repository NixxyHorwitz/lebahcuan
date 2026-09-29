<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/auth/guard.php';

$flash = '';
if (!empty($_SESSION['flash_videos_msg'])) {
    $flash = $_SESSION['flash_videos_msg'];
    $flash_type = $_SESSION['flash_videos_type'] ?? 'success';
    unset($_SESSION['flash_videos_msg'], $_SESSION['flash_videos_type']);
}

$watch_limit = user_watch_limit($pdo, $user);
$watch_today = user_watch_today($pdo, $user);

// Determine Sort Order
$sort_mode = setting($pdo, 'video_sort_mode', 'default');
$order_by = 'v.sort_order ASC, v.id DESC';
if ($sort_mode === 'newest') $order_by = 'v.id DESC';
if ($sort_mode === 'oldest') $order_by = 'v.id ASC';
if ($sort_mode === 'reward_desc') $order_by = 'v.reward_amount DESC, v.id DESC';
if ($sort_mode === 'reward_asc') $order_by = 'v.reward_amount ASC, v.id DESC';
if ($sort_mode === 'duration_asc') $order_by = 'v.watch_duration ASC, v.id DESC';
if ($sort_mode === 'random') $order_by = 'RAND()';

$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 10;
$offset = ($page - 1) * $limit;

// All active videos with watch status and user liked status
$videos = $pdo->prepare(
    "SELECT v.*,
       (SELECT COUNT(*) FROM watch_history wh
        WHERE wh.user_id=? AND wh.video_id=v.id AND DATE(wh.watched_at)=CURDATE()) AS watched_today,
       (SELECT COUNT(*) FROM video_likes vl
        WHERE vl.user_id=? AND vl.video_id=v.id) AS user_liked
     FROM videos v
     WHERE v.is_active=1
     ORDER BY {$order_by}
     LIMIT {$limit} OFFSET {$offset}"
);
$videos->execute([$user['id'], $user['id']]);
$videos = $videos->fetchAll();

// AJAX: Hapus progres tontonan dari riwayat user
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_watch_progress') {
    header('Content-Type: application/json');
    if (!csrf_verify()) { echo json_encode(['ok'=>false,'msg'=>'Invalid CSRF token.']); exit; }
    $del_vid = (int)($_POST['video_id'] ?? 0);
    if ($del_vid > 0) {
        $pdo->prepare("DELETE FROM user_watch_progress WHERE user_id=? AND video_id=?")->execute([$user['id'], $del_vid]);
    }
    echo json_encode(['ok'=>true]);
    exit;
}

// Bersihkan progres tontonan untuk video yang sudah tuntas ditonton hari ini
$pdo->prepare(
    "DELETE uwp FROM user_watch_progress uwp
     JOIN watch_history wh ON wh.user_id = uwp.user_id AND wh.video_id = uwp.video_id AND DATE(wh.watched_at) = CURDATE()
     WHERE uwp.user_id = ?"
)->execute([$user['id']]);

// Ambil riwayat tontonan yang sedang berjalan (maksimal 3 per user)
$cw_stmt = $pdo->prepare(
    "SELECT uwp.seconds_watched, uwp.duration, uwp.last_position, uwp.updated_at,
            v.id, v.title, v.youtube_id, v.watch_duration, v.reward_amount, v.total_watches, v.total_likes
     FROM user_watch_progress uwp
     JOIN videos v ON v.id = uwp.video_id
     WHERE uwp.user_id = ? AND v.is_active = 1
     ORDER BY uwp.updated_at DESC
     LIMIT 3"
);
$cw_stmt->execute([$user['id']]);
$continue_watching = $cw_stmt->fetchAll();

$saved_prog_map = [];
foreach ($continue_watching as $cw) {
    $saved_prog_map[$cw['id']] = $cw;
}

// AJAX Infinite Scroll Response
if (isset($_GET['ajax'])) {
    if (empty($videos)) {
        echo '';
        exit;
    }
    foreach ($videos as $v) {
        $done    = (bool)$v['watched_today'];
        $blocked = !$done && ($watch_today >= $watch_limit);
        $href    = ($done || $blocked) ? 'javascript:void(0)' : '/watch?id=' . $v['id'];
        $status_class = $done ? 'done' : ($blocked ? 'blocked' : 'ready');
        ?>
        <a href="<?= $href ?>" class="vcard vcard--<?= $status_class ?>" data-status="<?= $done ? 'done' : 'ready' ?>" <?= ($done || $blocked) ? 'style="pointer-events:none"' : '' ?>>
          <div class="vcard__thumb-wrap">
            <img src="<?= yt_thumb($v['youtube_id']) ?>" alt="<?= htmlspecialchars($v['title']) ?>" loading="lazy" onerror="this.src='https://img.youtube.com/vi/<?= $v['youtube_id'] ?>/hqdefault.jpg'">
            <?php if (isset($saved_prog_map[$v['id']]) && !$done): ?>
              <div class="vcard__resume-pill"><i class="ph-bold ph-arrow-counter-clockwise"></i> Lanjut <?= round(($saved_prog_map[$v['id']]['seconds_watched'] / $v['watch_duration']) * 100) ?>%</div>
            <?php endif; ?>
            <div class="vcard__play-overlay">
              <?php if ($done): ?>
                <div class="vcard__play-ico done"><i class="ph-fill ph-check-circle"></i></div>
              <?php else: ?>
                <div class="vcard__play-ico"><i class="ph-fill ph-play"></i></div>
              <?php endif; ?>
            </div>
            <div class="vcard__badge <?= $done ? 'vcard__badge--done' : '' ?>">
              <?= $done ? 'Selesai' : '+' . format_rp((float)$v['reward_amount']) ?>
            </div>
            <div class="vcard__duration-pill">
              <i class="ph-bold ph-clock"></i> <?= (int)$v['watch_duration'] ?>s
            </div>
          </div>
          <div class="vcard__body">
            <div class="vcard__title"><?= htmlspecialchars($v['title']) ?></div>
            <div class="vcard__footer">
              <div class="vcard__stats">
                <span title="Total Ditonton"><i class="ph-bold ph-eye"></i> <?= number_format((int)$v['total_watches']) ?></span>
                <span title="Disukai"><i class="ph-bold ph-thumbs-up"></i> <?= number_format((int)($v['total_likes'] ?? 0)) ?></span>
              </div>
              <div class="vcard__cta <?= $done ? 'vcard__cta--done' : (isset($saved_prog_map[$v['id']]) ? 'vcard__cta--resume' : '') ?>">
                <?= $done ? '<i class="ph-bold ph-check"></i> Sudah Diklaim' : (isset($saved_prog_map[$v['id']]) ? 'Lanjutkan →' : 'Tonton →') ?>
              </div>
            </div>
          </div>
        </a>
        <?php
    }
    exit;
}

$pageTitle  = 'Pusat Nonton Video';
$activePage = 'videos';
require dirname(__DIR__) . '/partials/header.php';
?>

<style>
/* ══════════════════════════════════════════════════════════
   VIDEOS HUB — CLEAN CINEMA & WATCH REWARD INTERFACE
   Zero Farm References • Modern Streaming Aesthetics
   ══════════════════════════════════════════════════════════ */
.video-hub-page {
  padding: 14px 14px 110px;
}

/* ── HERO BANNER ── */
.vhub-hero {
  background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);
  border: 2.5px solid #78350f;
  border-radius: 20px;
  box-shadow: 0 6px 0 #78350f, 0 12px 24px rgba(15, 23, 42, 0.2);
  padding: 16px;
  margin-bottom: 14px;
  color: #fff;
  position: relative;
  overflow: hidden;
}
.vhub-hero::after {
  content: '';
  position: absolute;
  top: -30px; right: -30px;
  width: 140px; height: 140px;
  background: radial-gradient(circle, rgba(245, 158, 11, 0.25) 0%, transparent 70%);
  border-radius: 50%;
  pointer-events: none;
}
.vhub-tag {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  background: rgba(245, 158, 11, 0.2);
  border: 1.5px solid #f59e0b;
  color: #fbbf24;
  font-size: 10px;
  font-weight: 900;
  padding: 3px 9px;
  border-radius: 12px;
  letter-spacing: 0.5px;
  text-transform: uppercase;
  margin-bottom: 6px;
}
.vhub-title {
  font-size: 18px;
  font-weight: 900;
  line-height: 1.25;
  color: #f8fafc;
  margin-bottom: 4px;
}
.vhub-desc {
  font-size: 11.5px;
  font-weight: 700;
  color: #94a3b8;
  line-height: 1.4;
  margin: 0;
}

/* ── PROGRESS STRIP ── */
.vhub-progress-card {
  background: #ffffff;
  border: 2.5px solid #78350f;
  border-radius: 18px;
  box-shadow: 0 5px 0 #78350f;
  padding: 14px 16px;
  margin-bottom: 16px;
}
.vhub-prog-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 8px;
}
.vhub-prog-lbl {
  font-size: 12.5px;
  font-weight: 900;
  color: #78350f;
  display: flex;
  align-items: center;
  gap: 6px;
}
.vhub-prog-badge {
  font-size: 11px;
  font-weight: 900;
  background: #fef3c7;
  color: #92400e;
  border: 1.5px solid #f59e0b;
  padding: 3px 10px;
  border-radius: 12px;
}
.vhub-bar-track {
  background: #f1f5f9;
  border: 1.5px solid #cbd5e1;
  border-radius: 14px;
  height: 12px;
  overflow: hidden;
  padding: 1.5px;
  box-shadow: inset 0 2px 4px rgba(0, 0, 0, 0.06);
}
.vhub-bar-fill {
  height: 100%;
  border-radius: 10px;
  background: linear-gradient(90deg, #f59e0b, #10b981);
  transition: width 0.6s cubic-bezier(0.34, 1.56, 0.64, 1);
  box-shadow: 0 1px 2px rgba(0, 0, 0, 0.15);
}
.vhub-bar-fill.full {
  background: #10b981;
}
.vhub-prog-meta {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-top: 6px;
  font-size: 10.5px;
  font-weight: 800;
  color: #64748b;
}

/* Alert Limit */
.vhub-limit-alert {
  margin-top: 10px;
  padding: 9px 12px;
  background: #fef2f2;
  border: 2px solid #ef4444;
  border-radius: 12px;
  color: #991b1b;
  font-size: 11px;
  font-weight: 900;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
}
.vhub-limit-link {
  background: #ef4444;
  color: #fff;
  padding: 4px 10px;
  border-radius: 8px;
  text-decoration: none;
  font-size: 10.5px;
  font-weight: 900;
  white-space: nowrap;
}

/* ── FILTER CHIPS ── */
.vhub-filter-strip {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 14px;
  overflow-x: auto;
  padding-bottom: 4px;
  scrollbar-width: none;
}
.vhub-filter-strip::-webkit-scrollbar { display: none; }
.vhub-chip {
  background: #fff;
  border: 2px solid #78350f;
  border-radius: 12px;
  padding: 6px 14px;
  font-size: 11.5px;
  font-weight: 900;
  color: #78350f;
  cursor: pointer;
  white-space: nowrap;
  box-shadow: 0 2.5px 0 #78350f;
  transition: all 0.15s ease;
}
.vhub-chip:active {
  transform: translateY(2px);
  box-shadow: 0 1px 0 #78350f;
}
.vhub-chip.active {
  background: #f59e0b;
  color: #fff;
  text-shadow: 0 1px 1px rgba(0,0,0,0.25);
  transform: translateY(-1px);
}

/* ── VIDEO CARDS GRID ── */
.vgrid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
  gap: 12px;
  margin-bottom: 24px;
}
@media (min-width: 480px) {
  .vgrid {
    grid-template-columns: repeat(2, 1fr);
    gap: 14px;
  }
}
.vcard {
  text-decoration: none;
  display: flex;
  flex-direction: column;
  background: #ffffff;
  border: 2.5px solid #78350f;
  border-radius: 18px;
  box-shadow: 0 4.5px 0 #78350f;
  padding: 7px;
  transition: transform 0.15s, box-shadow 0.15s;
  position: relative;
  overflow: hidden;
}
.vcard:hover {
  transform: translateY(-2px);
  box-shadow: 0 6.5px 0 #78350f;
}
.vcard:active {
  transform: translateY(2px);
  box-shadow: 0 2px 0 #78350f;
}
.vcard--done {
  opacity: 0.68;
  filter: grayscale(20%);
  background: #f8fafc;
  border-color: #64748b;
  box-shadow: 0 3.5px 0 #64748b;
}

/* Thumb */
.vcard__thumb-wrap {
  position: relative;
  aspect-ratio: 16/9;
  background: #0f172a;
  border-radius: 12px;
  border: 1.5px solid #78350f;
  overflow: hidden;
}
.vcard__thumb-wrap img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  display: block;
  transition: transform 0.3s ease;
}
.vcard:hover .vcard__thumb-wrap img {
  transform: scale(1.05);
}

/* Overlay play icon */
.vcard__play-overlay {
  position: absolute;
  inset: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  background: rgba(15, 23, 42, 0.3);
  opacity: 0;
  transition: opacity 0.2s;
}
.vcard:hover .vcard__play-overlay {
  opacity: 1;
}
.vcard__play-ico {
  width: 38px;
  height: 38px;
  border-radius: 50%;
  background: #f59e0b;
  border: 2px solid #fff;
  color: #fff;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 16px;
  padding-left: 2px;
  box-shadow: 0 3px 8px rgba(0,0,0,0.4);
}
.vcard__play-ico.done {
  background: #10b981;
  font-size: 20px;
  padding-left: 0;
}

/* Badges on Thumbnail */
.vcard__badge {
  position: absolute;
  top: 5px; right: 5px;
  background: linear-gradient(135deg, #f59e0b, #d97706);
  border: 1.5px solid #78350f;
  color: #fff;
  font-size: 9.5px;
  font-weight: 900;
  padding: 2px 7px;
  border-radius: 10px;
  box-shadow: 0 2px 0 #78350f;
  letter-spacing: 0.3px;
}
.vcard__badge--done {
  background: #10b981;
  border-color: #065f46;
  box-shadow: 0 2px 0 #065f46;
}
.vcard__duration-pill {
  position: absolute;
  bottom: 5px; right: 5px;
  background: rgba(15, 23, 42, 0.85);
  color: #f8fafc;
  font-size: 9px;
  font-weight: 900;
  padding: 2px 6px;
  border-radius: 6px;
  display: flex;
  align-items: center;
  gap: 3px;
}

/* Card Body */
.vcard__body {
  padding: 6px 3px 2px;
  display: flex;
  flex-direction: column;
  flex: 1;
  justify-content: space-between;
}
.vcard__title {
  font-size: 11.5px;
  font-weight: 800;
  color: #1e293b;
  line-height: 1.35;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
  height: 31px;
  margin-bottom: 6px;
}
.vcard__footer {
  display: flex;
  align-items: center;
  justify-content: space-between;
  border-top: 1.5px dashed #e2e8f0;
  padding-top: 6px;
  margin-top: auto;
}
.vcard__stats {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 10px;
  font-weight: 800;
  color: #64748b;
}
.vcard__stats span {
  display: inline-flex;
  align-items: center;
  gap: 2.5px;
}
.vcard__cta {
  font-size: 10px;
  font-weight: 900;
  color: #d97706;
}
.vcard__cta--done {
  color: #10b981;
}
.vcard__cta--resume {
  color: #d97706;
  font-weight: 900;
}
.vcard__resume-pill {
  position: absolute;
  top: 5px; left: 5px;
  background: #f59e0b;
  border: 1.5px solid #78350f;
  color: #fff;
  font-size: 9px;
  font-weight: 900;
  padding: 2px 6px;
  border-radius: 8px;
  display: flex;
  align-items: center;
  gap: 3px;
  box-shadow: 0 2px 0 #78350f;
  z-index: 2;
}

/* ── CONTINUE WATCHING (MAX 3) ── */
.vhub-continue-box {
  background: #ffffff;
  border: 2.5px solid #d97706;
  border-radius: 18px;
  box-shadow: 0 5px 0 #b45309, 0 10px 20px rgba(217, 119, 6, 0.08);
  padding: 14px 16px;
  margin-bottom: 16px;
}
.vhub-continue-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 12px;
}
.vhub-continue-title {
  font-size: 13.5px;
  font-weight: 900;
  color: #78350f;
  display: flex;
  align-items: center;
  gap: 6px;
}
.vhub-continue-pill {
  font-size: 10px;
  font-weight: 900;
  background: #fef3c7;
  color: #92400e;
  border: 1.5px solid #f59e0b;
  padding: 2px 8px;
  border-radius: 10px;
}
.vhub-continue-list {
  display: flex;
  flex-direction: column;
  gap: 10px;
}
.vhub-continue-item {
  display: flex;
  align-items: center;
  gap: 12px;
  background: #fffbeb;
  border: 1.5px solid #fde68a;
  border-radius: 14px;
  padding: 8px 10px;
  text-decoration: none;
  color: inherit;
  transition: all 0.2s ease;
  position: relative;
}
.vhub-continue-item:hover {
  background: #fef3c7;
  border-color: #f59e0b;
  transform: translateX(2px);
}
.vhub-continue-thumb {
  position: relative;
  width: 80px;
  height: 48px;
  border-radius: 8px;
  overflow: hidden;
  border: 1.5px solid #78350f;
  flex-shrink: 0;
  background: #0f172a;
}
.vhub-continue-thumb img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  display: block;
}
.vhub-continue-play {
  position: absolute;
  inset: 0;
  background: rgba(0,0,0,0.3);
  display: flex;
  align-items: center;
  justify-content: center;
  color: #fff;
  font-size: 16px;
}
.vhub-continue-info {
  flex: 1;
  min-width: 0;
}
.vhub-continue-name {
  font-size: 12px;
  font-weight: 800;
  color: #1e293b;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  margin-bottom: 4px;
  text-decoration: none;
  display: block;
}
.vhub-continue-bar-track {
  height: 6px;
  background: #e2e8f0;
  border-radius: 6px;
  overflow: hidden;
  margin-bottom: 4px;
}
.vhub-continue-bar-fill {
  height: 100%;
  background: linear-gradient(90deg, #f59e0b, #10b981);
  border-radius: 6px;
}
.vhub-continue-meta {
  display: flex;
  align-items: center;
  justify-content: space-between;
  font-size: 10px;
  font-weight: 800;
  color: #64748b;
}
.vhub-continue-action {
  display: flex;
  align-items: center;
  gap: 6px;
  flex-shrink: 0;
}
.vhub-continue-btn {
  background: linear-gradient(135deg, #f59e0b, #d97706);
  border: 1.5px solid #78350f;
  color: #fff;
  padding: 5px 10px;
  border-radius: 10px;
  font-size: 11px;
  font-weight: 900;
  text-decoration: none;
  box-shadow: 0 2px 0 #78350f;
  display: inline-flex;
  align-items: center;
  gap: 4px;
  transition: all 0.15s;
}
.vhub-continue-btn:hover {
  transform: translateY(-1px);
  box-shadow: 0 3px 0 #78350f;
}
.vhub-continue-btn-del {
  background: transparent;
  border: none;
  color: #94a3b8;
  font-size: 16px;
  cursor: pointer;
  padding: 4px;
  border-radius: 6px;
  transition: color 0.15s;
}
.vhub-continue-btn-del:hover {
  color: #ef4444;
}

/* Empty State */
.vhub-empty {
  background: #ffffff;
  border: 2.5px solid #78350f;
  border-radius: 18px;
  box-shadow: 0 5px 0 #78350f;
  padding: 32px 18px;
  text-align: center;
  margin-bottom: 20px;
}
.vhub-empty__ico {
  width: 60px; height: 60px;
  border-radius: 18px;
  background: #fef3c7;
  border: 2px solid #78350f;
  display: flex; align-items: center; justify-content: center;
  font-size: 28px; color: #d97706;
  margin: 0 auto 12px;
  box-shadow: 0 3px 0 #78350f;
}
.vhub-empty__title {
  font-size: 15px; font-weight: 900; color: #78350f; margin-bottom: 4px;
}
.vhub-empty__sub {
  font-size: 11.5px; font-weight: 700; color: #64748b; margin: 0;
}
</style>

<div class="video-hub-page">

  <!-- ── 1. CINEMA HERO BANNER ── -->
  <div class="vhub-hero">
    <div class="vhub-tag">
      <i class="ph-bold ph-film-strip"></i> Video Mission Hub
    </div>
    <div class="vhub-title">Streaming &amp; Dapatkan Saldo</div>
    <p class="vhub-desc">Tonton tayangan video kreator pilihan hingga durasi tuntas untuk langsung mengklaim komisi rupiah ke akun Anda.</p>
  </div>

  <!-- ── 2. DAILY WATCH PROGRESS ── -->
  <div class="vhub-progress-card">
    <div class="vhub-prog-row">
      <div class="vhub-prog-lbl">
        <i class="ph-fill ph-chart-pie-slice" style="color:#d97706;font-size:18px;"></i>
        <span>Kuota Tonton Hari Ini</span>
      </div>
      <div class="vhub-prog-badge">
        <?= (int)$watch_today ?> / <?= (int)$watch_limit ?> Video
      </div>
    </div>

    <?php $pct = $watch_limit > 0 ? min(100, (int)round(($watch_today / $watch_limit) * 100)) : 0; ?>
    <div class="vhub-bar-track">
      <div class="vhub-bar-fill <?= $pct >= 100 ? 'full' : '' ?>" style="width: <?= $pct ?>%;"></div>
    </div>

    <div class="vhub-prog-meta">
      <span><i class="ph-bold ph-arrows-clockwise"></i> Reset tiap 00:00 WIB</span>
      <span><?= $pct ?>% Selesai</span>
    </div>

    <?php if ($watch_today >= $watch_limit): ?>
    <div class="vhub-limit-alert">
      <span><i class="ph-bold ph-warning-circle"></i> Kuota harian Anda telah tercapai!</span>
      <a href="/upgrade" class="vhub-limit-link">Upgrade VIP →</a>
    </div>
    <?php endif; ?>
  </div>

  <?php if (!empty($continue_watching)): ?>
  <!-- ── 2.1 RIWAYAT LANJUTKAN MENONTON (MAKSIMAL 3) ── -->
  <div class="vhub-continue-box" id="continue-watching-box">
    <div class="vhub-continue-head">
      <div class="vhub-continue-title">
        <i class="ph-fill ph-clock-counter-clockwise" style="color:#d97706;font-size:18px;"></i>
        <span>Lanjutkan Menonton</span>
      </div>
      <span class="vhub-continue-pill" id="continue-watching-count"><?= count($continue_watching) ?> / 3 Video</span>
    </div>
    <div class="vhub-continue-list" id="continue-watching-list">
      <?php foreach ($continue_watching as $cw): 
        $cw_pct = min(99, max(1, (int)round(($cw['seconds_watched'] / $cw['watch_duration']) * 100)));
        $cw_remain = max(1, (int)$cw['watch_duration'] - (int)$cw['seconds_watched']);
      ?>
      <div class="vhub-continue-item" id="cw-item-<?= $cw['id'] ?>">
        <a href="/watch?id=<?= $cw['id'] ?>" class="vhub-continue-thumb">
          <img src="<?= yt_thumb($cw['youtube_id']) ?>" alt="<?= htmlspecialchars($cw['title']) ?>" onerror="this.src='https://img.youtube.com/vi/<?= $cw['youtube_id'] ?>/hqdefault.jpg'">
          <div class="vhub-continue-play"><i class="ph-fill ph-play"></i></div>
        </a>
        <div class="vhub-continue-info">
          <a href="/watch?id=<?= $cw['id'] ?>" class="vhub-continue-name" title="<?= htmlspecialchars($cw['title']) ?>">
            <?= htmlspecialchars($cw['title']) ?>
          </a>
          <div class="vhub-continue-bar-track">
            <div class="vhub-continue-bar-fill" style="width:<?= $cw_pct ?>%"></div>
          </div>
          <div class="vhub-continue-meta">
            <span><i class="ph-bold ph-hourglass-medium"></i> <?= $cw['seconds_watched'] ?>s / <?= $cw['watch_duration'] ?>s (<?= $cw_pct ?>%)</span>
            <span style="color:#d97706;font-weight:900;">+<?= format_rp((float)$cw['reward_amount']) ?></span>
          </div>
        </div>
        <div class="vhub-continue-action">
          <a href="/watch?id=<?= $cw['id'] ?>" class="vhub-continue-btn">
            Lanjut <i class="ph-bold ph-arrow-right"></i>
          </a>
          <button type="button" class="vhub-continue-btn-del" onclick="dismissWatchProgress(<?= $cw['id'] ?>)" title="Hapus riwayat video ini">
            <i class="ph-bold ph-x"></i>
          </button>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- ── 3. FILTER TABS ── -->
  <div class="vhub-filter-strip">
    <button type="button" class="vhub-chip active" onclick="filterVideos('all', this)">Semua Video</button>
    <button type="button" class="vhub-chip" onclick="filterVideos('ready', this)">Belum Ditonton</button>
    <button type="button" class="vhub-chip" onclick="filterVideos('done', this)">Sudah Selesai</button>
  </div>

  <!-- ── 4. VIDEOS GRID ── -->
  <?php if (empty($videos)): ?>
    <div class="vhub-empty">
      <div class="vhub-empty__ico">
        <i class="ph-fill ph-video-camera-slash"></i>
      </div>
      <div class="vhub-empty__title">Belum Ada Video Baru</div>
      <p class="vhub-empty__sub">Video misi baru akan segera diunggah oleh pengiklan. Silakan periksa kembali nanti.</p>
    </div>
  <?php else: ?>
    <div class="vgrid" id="vgrid">
      <?php foreach ($videos as $v):
        $done    = (bool)$v['watched_today'];
        $blocked = !$done && ($watch_today >= $watch_limit);
        $href    = ($done || $blocked) ? 'javascript:void(0)' : '/watch?id=' . $v['id'];
        $status_class = $done ? 'done' : ($blocked ? 'blocked' : 'ready');
      ?>
      <a href="<?= $href ?>" class="vcard vcard--<?= $status_class ?>" data-status="<?= $done ? 'done' : 'ready' ?>" <?= ($done || $blocked) ? 'style="pointer-events:none"' : '' ?>>
        <div class="vcard__thumb-wrap">
          <img src="<?= yt_thumb($v['youtube_id']) ?>" alt="<?= htmlspecialchars($v['title']) ?>" loading="lazy" onerror="this.src='https://img.youtube.com/vi/<?= $v['youtube_id'] ?>/hqdefault.jpg'">
          <?php if (isset($saved_prog_map[$v['id']]) && !$done): ?>
            <div class="vcard__resume-pill"><i class="ph-bold ph-arrow-counter-clockwise"></i> Lanjut <?= round(($saved_prog_map[$v['id']]['seconds_watched'] / $v['watch_duration']) * 100) ?>%</div>
          <?php endif; ?>
          <div class="vcard__play-overlay">
            <?php if ($done): ?>
              <div class="vcard__play-ico done"><i class="ph-fill ph-check-circle"></i></div>
            <?php else: ?>
              <div class="vcard__play-ico"><i class="ph-fill ph-play"></i></div>
            <?php endif; ?>
          </div>
          <div class="vcard__badge <?= $done ? 'vcard__badge--done' : '' ?>">
            <?= $done ? 'Selesai' : '+' . format_rp((float)$v['reward_amount']) ?>
          </div>
          <div class="vcard__duration-pill">
            <i class="ph-bold ph-clock"></i> <?= (int)$v['watch_duration'] ?>s
          </div>
        </div>
        <div class="vcard__body">
          <div class="vcard__title"><?= htmlspecialchars($v['title']) ?></div>
          <div class="vcard__footer">
            <div class="vcard__stats">
              <span title="Total Ditonton"><i class="ph-bold ph-eye"></i> <?= number_format((int)$v['total_watches']) ?></span>
              <span title="Disukai"><i class="ph-bold ph-thumbs-up"></i> <?= number_format((int)($v['total_likes'] ?? 0)) ?></span>
            </div>
            <div class="vcard__cta <?= $done ? 'vcard__cta--done' : (isset($saved_prog_map[$v['id']]) ? 'vcard__cta--resume' : '') ?>">
              <?= $done ? '<i class="ph-bold ph-check"></i> Selesai' : (isset($saved_prog_map[$v['id']]) ? 'Lanjutkan →' : 'Tonton →') ?>
            </div>
          </div>
        </div>
      </a>
      <?php endforeach; ?>
    </div>

    <!-- Infinite Scroll Loader -->
    <div id="loader" style="text-align:center;padding:16px;display:none;">
      <div style="background:#fff;width:40px;height:40px;border-radius:50%;border:2px solid #78350f;box-shadow:0 3px 0 #78350f;display:inline-flex;align-items:center;justify-content:center;">
        <i class="ph-bold ph-spinner ph-spin" style="font-size:20px;color:#d97706;"></i>
      </div>
    </div>
  <?php endif; ?>

</div>

<script>
// Filter Videos
function filterVideos(type, btn) {
  document.querySelectorAll('.vhub-chip').forEach(c => c.classList.remove('active'));
  btn.classList.add('active');
  const cards = document.querySelectorAll('#vgrid .vcard');
  cards.forEach(card => {
    const st = card.getAttribute('data-status');
    if (type === 'all') {
      card.style.display = '';
    } else if (type === st) {
      card.style.display = '';
    } else {
      card.style.display = 'none';
    }
  });
}

// Infinite Scroll
document.addEventListener('DOMContentLoaded', function() {
  let page = 1;
  let isLoading = false;
  let hasMore = <?= count($videos) === $limit ? 'true' : 'false' ?>;
  const grid = document.getElementById('vgrid');
  const loader = document.getElementById('loader');

  if (!grid || !hasMore) return;

  const observer = new IntersectionObserver((entries) => {
    if (entries[0].isIntersecting && !isLoading && hasMore) {
      loadMore();
    }
  }, { rootMargin: '100px' });

  const sentinel = document.createElement('div');
  sentinel.style.height = '1px';
  grid.parentNode.insertBefore(sentinel, grid.nextSibling);
  observer.observe(sentinel);

  async function loadMore() {
    isLoading = true;
    if (loader) loader.style.display = 'block';
    page++;

    try {
      const res = await fetch(`?ajax=1&page=${page}`);
      const html = await res.text();

      if (html.trim() === '') {
        hasMore = false;
        observer.unobserve(sentinel);
      } else {
        grid.insertAdjacentHTML('beforeend', html);
      }
    } catch (e) {
      console.error('Error loading more videos:', e);
      page--;
    } finally {
      isLoading = false;
      if (loader) loader.style.display = 'none';
    }
  }
});

// Dismiss Watch Progress
async function dismissWatchProgress(vidId) {
  const item = document.getElementById('cw-item-' + vidId);
  if (item) {
    item.style.opacity = '0.4';
    item.style.pointerEvents = 'none';
  }

  const fd = new FormData();
  fd.append('action', 'delete_watch_progress');
  fd.append('_csrf', '<?= csrf_token() ?>');
  fd.append('video_id', vidId);

  try {
    const res = await fetch('/videos', { method: 'POST', body: fd });
    const data = await res.json();
    if (data.ok) {
      if (item) {
        item.style.transition = 'all 0.3s ease';
        item.style.transform = 'scale(0.9)';
        item.style.opacity = '0';
        setTimeout(() => {
          item.remove();
          const list = document.getElementById('continue-watching-list');
          const remaining = list ? list.querySelectorAll('.vhub-continue-item').length : 0;
          const countBadge = document.getElementById('continue-watching-count');
          if (countBadge) countBadge.textContent = remaining + ' / 3 Video';
          if (remaining === 0) {
            const box = document.getElementById('continue-watching-box');
            if (box) box.remove();
          }
        }, 300);
      }
      if (typeof nToast === 'function') nToast('Riwayat tontonan dihapus.', 'info');
    }
  } catch(e) {
    if (item) {
      item.style.opacity = '';
      item.style.pointerEvents = '';
    }
  }
}
</script>

<?php if (!empty($flash)): ?>
<script>
document.addEventListener('DOMContentLoaded', () => {
    if (typeof nToast !== 'undefined') {
        nToast('<?= addslashes($flash) ?>', '<?= $flash_type ?>');
    }
});
</script>
<?php endif; ?>

<?php require dirname(__DIR__) . '/partials/footer.php'; ?>
