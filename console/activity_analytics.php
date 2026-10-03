<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
staff_require('analytics');

// Range Filter: today, 7, 14, 30 days
$range = trim($_GET['range'] ?? '7');
if (!in_array($range, ['today', '7', '14', '30'], true)) {
    $range = '7';
}

$days = $range === 'today' ? 1 : (int)$range;

if ($range === 'today') {
    $dateCondition = "created_at >= CURDATE()";
    $dateConditionWatched = "watched_at >= CURDATE()";
    $rangeTitle = "Hari Ini (Live)";
} else {
    $dateCondition = "created_at >= DATE_SUB(CURDATE(), INTERVAL " . ($days - 1) . " DAY)";
    $dateConditionWatched = "watched_at >= DATE_SUB(CURDATE(), INTERVAL " . ($days - 1) . " DAY)";
    $rangeTitle = "{$days} Hari Terakhir";
}

try {
    // ── 1. DEPOSIT METRICS ─────────────────────────────────────────────
    $depoQuery = $pdo->query("
        SELECT 
            COALESCE(SUM(CASE WHEN status IN ('confirmed','approved') THEN amount ELSE 0 END), 0) as total_success_amount,
            COUNT(CASE WHEN status IN ('confirmed','approved') THEN 1 END) as success_count,
            COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending_count,
            COUNT(CASE WHEN status IN ('rejected','expired','cancelled') THEN 1 END) as failed_count,
            COUNT(*) as total_count
        FROM deposits 
        WHERE {$dateCondition}
    ")->fetch();

    $totalDepoSuccessAmount = (float)($depoQuery['total_success_amount'] ?? 0);
    $totalDepoSuccessCount  = (int)($depoQuery['success_count'] ?? 0);
    $totalDepoPendingCount  = (int)($depoQuery['pending_count'] ?? 0);
    $totalDepoFailedCount   = (int)($depoQuery['failed_count'] ?? 0);
    $totalDepoAllCount      = (int)($depoQuery['total_count'] ?? 0);
    $depoSuccessRate        = $totalDepoAllCount > 0 ? round(($totalDepoSuccessCount / $totalDepoAllCount) * 100, 1) : 0.0;
    $avgDepoAmount          = $totalDepoSuccessCount > 0 ? round($totalDepoSuccessAmount / $totalDepoSuccessCount) : 0;

    // Daily Deposit breakdown for chart
    $depoDailyStmt = $pdo->query("
        SELECT DATE(created_at) as d, 
               COALESCE(SUM(CASE WHEN status IN ('confirmed','approved') THEN amount ELSE 0 END), 0) as total_nominal,
               COUNT(CASE WHEN status IN ('confirmed','approved') THEN 1 END) as total_trans
        FROM deposits 
        WHERE {$dateCondition}
        GROUP BY d ORDER BY d ASC
    ");
    $depoDailyMap = [];
    while ($r = $depoDailyStmt->fetch()) {
        $depoDailyMap[$r['d']] = $r;
    }

    // ── 2. VISITOR / TRAFFIC METRICS ──────────────────────────────────
    $pvQuery = $pdo->query("
        SELECT 
            COUNT(*) as total_views,
            COUNT(DISTINCT ip_hash) as unique_visitors,
            COUNT(DISTINCT user_id) as active_logged_users
        FROM page_views 
        WHERE {$dateCondition}
    ")->fetch();

    $totalViews           = (int)($pvQuery['total_views'] ?? 0);
    $totalUniqueVisitors  = (int)($pvQuery['unique_visitors'] ?? 0);
    $totalActiveUsers     = (int)($pvQuery['active_logged_users'] ?? 0);

    // Daily Pageviews breakdown
    $pvDailyStmt = $pdo->query("
        SELECT DATE(created_at) as d, COUNT(*) as views, COUNT(DISTINCT ip_hash) as visitors
        FROM page_views 
        WHERE {$dateCondition}
        GROUP BY d ORDER BY d ASC
    ");
    $pvDailyMap = [];
    while ($r = $pvDailyStmt->fetch()) {
        $pvDailyMap[$r['d']] = $r;
    }

    // Top Pages
    $topPages = $pdo->query("
        SELECT path, COUNT(*) as cnt, COUNT(DISTINCT ip_hash) as unique_ips
        FROM page_views 
        WHERE {$dateCondition}
        GROUP BY path ORDER BY cnt DESC LIMIT 7
    ")->fetchAll();

    // ── 3. WATCH / VIDEO METRICS ──────────────────────────────────────
    $watchQuery = $pdo->query("
        SELECT 
            COUNT(*) as total_watches,
            COALESCE(SUM(reward_given), 0) as total_rewards,
            COUNT(DISTINCT user_id) as unique_viewers,
            COUNT(DISTINCT video_id) as unique_videos_watched
        FROM watch_history 
        WHERE {$dateConditionWatched}
    ")->fetch();

    $totalWatches       = (int)($watchQuery['total_watches'] ?? 0);
    $totalRewardsGiven  = (float)($watchQuery['total_rewards'] ?? 0);
    $totalUniqueViewers = (int)($watchQuery['unique_viewers'] ?? 0);
    $avgWatchPerViewer  = $totalUniqueViewers > 0 ? round($totalWatches / $totalUniqueViewers, 1) : 0.0;

    // Daily Watches breakdown
    $watchDailyStmt = $pdo->query("
        SELECT DATE(watched_at) as d, COUNT(*) as cnt, COALESCE(SUM(reward_given),0) as rewards
        FROM watch_history 
        WHERE {$dateConditionWatched}
        GROUP BY d ORDER BY d ASC
    ");
    $watchDailyMap = [];
    while ($r = $watchDailyStmt->fetch()) {
        $watchDailyMap[$r['d']] = $r;
    }

    // Top Videos Watched
    $topWatchedVideos = $pdo->query("
        SELECT v.id, v.title, v.youtube_id, COUNT(wh.id) as watch_count, SUM(wh.reward_given) as rewards_sum
        FROM watch_history wh
        JOIN videos v ON v.id = wh.video_id
        WHERE wh.{$dateConditionWatched}
        GROUP BY wh.video_id
        ORDER BY watch_count DESC LIMIT 5
    ")->fetchAll();

    // ── 4. HOURLY ACTIVITY HEATMAP (00:00 - 23:00) ───────────────────
    $hourlyActivity = array_fill(0, 24, ['watches' => 0, 'deposits' => 0, 'views' => 0]);

    // Hourly watches
    $hw = $pdo->query("
        SELECT HOUR(watched_at) as h, COUNT(*) as cnt 
        FROM watch_history 
        WHERE {$dateConditionWatched}
        GROUP BY h
    ")->fetchAll();
    foreach ($hw as $row) { $hourlyActivity[(int)$row['h']]['watches'] = (int)$row['cnt']; }

    // Hourly deposits
    $hd = $pdo->query("
        SELECT HOUR(created_at) as h, COUNT(*) as cnt 
        FROM deposits 
        WHERE status IN ('confirmed','approved') AND {$dateCondition}
        GROUP BY h
    ")->fetchAll();
    foreach ($hd as $row) { $hourlyActivity[(int)$row['h']]['deposits'] = (int)$row['cnt']; }

    // Hourly pageviews
    $hp = $pdo->query("
        SELECT HOUR(created_at) as h, COUNT(*) as cnt 
        FROM page_views 
        WHERE {$dateCondition}
        GROUP BY h
    ")->fetchAll();
    foreach ($hp as $row) { $hourlyActivity[(int)$row['h']]['views'] = (int)$row['cnt']; }

    // Cari jam tersibuk (Peak Hour)
    $peakHour = 0; $peakScore = 0;
    foreach ($hourlyActivity as $h => $act) {
        $score = ($act['watches'] * 2) + ($act['deposits'] * 5) + $act['views'];
        if ($score > $peakScore) { $peakScore = $score; $peakHour = $h; }
    }

    // ── 5. LIVE RECENT ACTIVITY STREAM (50 Terkini) ───────────────────
    $activityStream = [];

    // Recent deposits
    $recDepo = $pdo->query("
        SELECT d.id, d.amount, d.status, d.created_at, u.username, 'deposit' as act_type
        FROM deposits d
        JOIN users u ON u.id = d.user_id
        ORDER BY d.id DESC LIMIT 15
    ")->fetchAll();
    foreach ($recDepo as $item) { $activityStream[] = $item; }

    // Recent watches
    $recWatch = $pdo->query("
        SELECT wh.id, wh.reward_given as amount, 'watch' as status, wh.watched_at as created_at, u.username, v.title as extra_info, 'watch' as act_type
        FROM watch_history wh
        JOIN users u ON u.id = wh.user_id
        JOIN videos v ON v.id = wh.video_id
        ORDER BY wh.id DESC LIMIT 15
    ")->fetchAll();
    foreach ($recWatch as $item) { $activityStream[] = $item; }

    // Recent upgrades
    $recUpg = $pdo->query("
        SELECT uo.id, uo.amount as amount, uo.status, uo.created_at, u.username, m.name as extra_info, 'upgrade' as act_type
        FROM upgrade_orders uo
        JOIN users u ON u.id = uo.user_id
        JOIN memberships m ON m.id = uo.membership_id
        ORDER BY uo.id DESC LIMIT 10
    ")->fetchAll();
    foreach ($recUpg as $item) { $activityStream[] = $item; }

    // Recent registrations
    $recReg = $pdo->query("
        SELECT u.id, 0 as amount, 'registered' as status, u.created_at, u.username, u.referral_code as extra_info, 'register' as act_type
        FROM users u
        ORDER BY u.id DESC LIMIT 10
    ")->fetchAll();
    foreach ($recReg as $item) { $activityStream[] = $item; }

    // Urutkan seluruh aktivitas stream berdasarkan timestamp DESC
    usort($activityStream, function($a, $b) {
        return strtotime($b['created_at']) <=> strtotime($a['created_at']);
    });
    $activityStream = array_slice($activityStream, 0, 30);

    // ── 6. BUILD MULTI-METRIC CHART DATA ──────────────────────────────
    $chartLabels = [];
    $chartDepoData = [];
    $chartViewsData = [];
    $chartWatchData = [];

    if ($range === 'today') {
        // Mode today: Breakdown 24 jam
        for ($h = 0; $h <= 23; $h++) {
            $label = sprintf("%02d:00", $h);
            $chartLabels[] = $label;
            $chartDepoData[] = ($hourlyActivity[$h]['deposits'] ?? 0);
            $chartViewsData[] = ($hourlyActivity[$h]['views'] ?? 0);
            $chartWatchData[] = ($hourlyActivity[$h]['watches'] ?? 0);
        }
    } else {
        // Mode N days
        for ($i = $days - 1; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-{$i} days"));
            $chartLabels[] = date('d/m', strtotime($date));
            $chartDepoData[] = (float)($depoDailyMap[$date]['total_nominal'] ?? 0);
            $chartViewsData[] = (int)($pvDailyMap[$date]['views'] ?? 0);
            $chartWatchData[] = (int)($watchDailyMap[$date]['cnt'] ?? 0);
        }
    }

} catch (\Throwable $e) {
    $error = $e->getMessage();
}

$pageTitle = 'Pusat Analisis & Aktivitas';
$activePage = 'activity_analytics';
require __DIR__ . '/partials/header.php';
?>

<!-- Header Toolbar -->
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
  <div>
    <div class="d-flex align-items-center gap-2">
      <span style="font-size:24px;">📊</span>
      <h4 class="mb-0 fw-bold text-white">Pusat Analisis &amp; Degup Aktivitas Web</h4>
      <span class="badge" style="background:rgba(245,158,11,0.2);color:#f59e0b;border:1px solid rgba(245,158,11,0.35);font-size:11px;padding:4px 8px;">
        ⭐ Master Analytics Hub
      </span>
    </div>
    <div style="font-size:12.5px;color:#94a3b8;margin-top:4px;">
      Visualisasi komprehensif performa deposit omset, trafik pengunjung, aktivitas nonton video, dan jam sibuk platform.
    </div>
  </div>

  <!-- Range Filter Buttons -->
  <div class="d-flex align-items-center gap-1 p-1" style="background:#121524;border-radius:10px;border:1px solid #1f2438;">
    <span class="text-secondary small px-2 fw-semibold" style="font-size:11px;">Periode:</span>
    <a href="?range=today" class="btn btn-sm <?= $range==='today'?'btn-warning text-dark fw-bold':'btn-dark text-secondary' ?>" style="font-size:11.5px;border-radius:7px;padding:4px 10px;">Hari Ini</a>
    <a href="?range=7" class="btn btn-sm <?= $range==='7'?'btn-warning text-dark fw-bold':'btn-dark text-secondary' ?>" style="font-size:11.5px;border-radius:7px;padding:4px 10px;">7 Hari</a>
    <a href="?range=14" class="btn btn-sm <?= $range==='14'?'btn-warning text-dark fw-bold':'btn-dark text-secondary' ?>" style="font-size:11.5px;border-radius:7px;padding:4px 10px;">14 Hari</a>
    <a href="?range=30" class="btn btn-sm <?= $range==='30'?'btn-warning text-dark fw-bold':'btn-dark text-secondary' ?>" style="font-size:11.5px;border-radius:7px;padding:4px 10px;">30 Hari</a>
  </div>
</div>

<?php if (!empty($error)): ?>
<div class="alert alert-danger" style="border-radius:12px;font-size:13px;">
  <strong>Error:</strong> <?= htmlspecialchars($error) ?>
</div>
<?php endif; ?>

<!-- 4 PILAR METRIK UTAMA -->
<div class="row g-3 mb-4">
  <!-- 1. DEPOSIT ANALYTICS CARD -->
  <div class="col-xl-3 col-md-6">
    <div class="c-stat h-100 position-relative overflow-hidden" style="border-top:3px solid #10b981;background:#101321;">
      <div class="d-flex align-items-center justify-content-between mb-2">
        <div class="c-stat__lbl" style="color:#10b981;">💰 Deposit Sukses</div>
        <div class="c-stat__icon" style="background:rgba(16,185,129,0.15);color:#10b981;font-size:16px;">💳</div>
      </div>
      <div class="c-stat__val" style="color:#10b981;"><?= format_rp($totalDepoSuccessAmount) ?></div>
      <div class="d-flex align-items-center justify-content-between mt-3 pt-2" style="border-top:1px solid rgba(255,255,255,0.06);font-size:11.5px;">
        <span class="text-secondary"><?= number_format($totalDepoSuccessCount) ?> tx berhasil</span>
        <span class="badge" style="background:rgba(16,185,129,0.15);color:#34d399;">Success <?= $depoSuccessRate ?>%</span>
      </div>
      <div style="font-size:10.5px;color:#64748b;margin-top:3px;">
        Avg: <?= format_rp($avgDepoAmount) ?> · <?= $totalDepoPendingCount ?> pending
      </div>
    </div>
  </div>

  <!-- 2. TRAFFIC / VISITOR CARD -->
  <div class="col-xl-3 col-md-6">
    <div class="c-stat h-100 position-relative overflow-hidden" style="border-top:3px solid #38bdf8;background:#101321;">
      <div class="d-flex align-items-center justify-content-between mb-2">
        <div class="c-stat__lbl" style="color:#38bdf8;">🌐 Kunjungan Visitor</div>
        <div class="c-stat__icon" style="background:rgba(56,189,248,0.15);color:#38bdf8;font-size:16px;">👥</div>
      </div>
      <div class="c-stat__val" style="color:#38bdf8;"><?= number_format($totalViews) ?> <span style="font-size:13px;color:#94a3b8;font-weight:600;">Views</span></div>
      <div class="d-flex align-items-center justify-content-between mt-3 pt-2" style="border-top:1px solid rgba(255,255,255,0.06);font-size:11.5px;">
        <span class="text-secondary"><?= number_format($totalUniqueVisitors) ?> IP Unik</span>
        <span class="badge" style="background:rgba(56,189,248,0.15);color:#7dd3fc;"><?= number_format($totalActiveUsers) ?> User Login</span>
      </div>
      <div style="font-size:10.5px;color:#64748b;margin-top:3px;">
        Top URL: <?= !empty($topPages[0]['path']) ? htmlspecialchars($topPages[0]['path']) : '/' ?>
      </div>
    </div>
  </div>

  <!-- 3. NONTON & REWARD CARD -->
  <div class="col-xl-3 col-md-6">
    <div class="c-stat h-100 position-relative overflow-hidden" style="border-top:3px solid #f59e0b;background:#101321;">
      <div class="d-flex align-items-center justify-content-between mb-2">
        <div class="c-stat__lbl" style="color:#f59e0b;">🎬 Nonton Video</div>
        <div class="c-stat__icon" style="background:rgba(245,158,11,0.15);color:#f59e0b;font-size:16px;">📺</div>
      </div>
      <div class="c-stat__val" style="color:#f59e0b;"><?= number_format($totalWatches) ?> <span style="font-size:13px;color:#94a3b8;font-weight:600;">Ditonton</span></div>
      <div class="d-flex align-items-center justify-content-between mt-3 pt-2" style="border-top:1px solid rgba(255,255,255,0.06);font-size:11.5px;">
        <span class="text-secondary"><?= number_format($totalUniqueViewers) ?> penonton</span>
        <span class="badge" style="background:rgba(245,158,11,0.15);color:#fde047;"><?= $avgWatchPerViewer ?> vid/user</span>
      </div>
      <div style="font-size:10.5px;color:#64748b;margin-top:3px;">
        Total Reward: <strong><?= format_rp($totalRewardsGiven) ?></strong>
      </div>
    </div>
  </div>

  <!-- 4. JAM SIBUK & VELOCITY CARD -->
  <div class="col-xl-3 col-md-6">
    <div class="c-stat h-100 position-relative overflow-hidden" style="border-top:3px solid #a855f7;background:#101321;">
      <div class="d-flex align-items-center justify-content-between mb-2">
        <div class="c-stat__lbl" style="color:#a855f7;">⚡ Jam Puncak Aktivitas</div>
        <div class="c-stat__icon" style="background:rgba(168,85,247,0.15);color:#a855f7;font-size:16px;">⏰</div>
      </div>
      <div class="c-stat__val" style="color:#a855f7;"><?= sprintf("%02d:00 - %02d:00", $peakHour, ($peakHour+1)%24) ?></div>
      <div class="d-flex align-items-center justify-content-between mt-3 pt-2" style="border-top:1px solid rgba(255,255,255,0.06);font-size:11.5px;">
        <span class="text-secondary">Waktu Indonesia Barat (WIB)</span>
        <span class="badge" style="background:rgba(168,85,247,0.15);color:#d8b4fe;">🔥 Paling Ramai</span>
      </div>
      <div style="font-size:10.5px;color:#64748b;margin-top:3px;">
        Kombinasi nonton, deposit &amp; pageview tertinggi
      </div>
    </div>
  </div>
</div>

<!-- GRAFIK UTAMA: MULTI-AXIS TREND VISUALIZATION -->
<div class="row g-3 mb-4">
  <div class="col-lg-8">
    <div class="c-card h-100">
      <div class="c-card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
        <div class="d-flex align-items-center gap-2">
          <span style="font-size:18px;">📈</span>
          <span class="c-card-title">Tren Aktivitas Web (Deposit vs Visitor vs Nonton) — <?= htmlspecialchars($rangeTitle) ?></span>
        </div>
        <div style="font-size:11px;color:#94a3b8;">
          <span style="display:inline-block;width:9px;height:9px;border-radius:2px;background:#10b981;margin-right:3px;"></span> Deposit
          <span style="display:inline-block;width:9px;height:9px;border-radius:2px;background:#38bdf8;margin-left:8px;margin-right:3px;"></span> Visitor
          <span style="display:inline-block;width:9px;height:9px;border-radius:2px;background:#f59e0b;margin-left:8px;margin-right:3px;"></span> Nonton
        </div>
      </div>
      <div class="c-card-body">
        <div style="position:relative;height:280px;width:100%;">
          <canvas id="multiTrendChart"></canvas>
        </div>
      </div>
    </div>
  </div>

  <!-- JAM SIBUK / HOURLY BAR CHART -->
  <div class="col-lg-4">
    <div class="c-card h-100">
      <div class="c-card-header d-flex align-items-center justify-content-between">
        <div class="d-flex align-items-center gap-2">
          <span style="font-size:18px;">🕒</span>
          <span class="c-card-title">Pola Jam Sibuk (24 Jam)</span>
        </div>
        <span class="badge" style="background:#1e2438;color:#a855f7;font-size:10px;">Peak: <?= sprintf("%02d:00", $peakHour) ?></span>
      </div>
      <div class="c-card-body">
        <div style="position:relative;height:280px;width:100%;">
          <canvas id="hourlyBarChart"></canvas>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- BREAKDOWN SECTION & LIVE ACTIVITY FEED -->
<div class="row g-3 mb-4">
  <!-- TOP KONTEN & HALAMAN -->
  <div class="col-lg-5">
    <div class="c-card mb-3">
      <div class="c-card-header d-flex align-items-center justify-content-between">
        <span class="c-card-title">🎬 Top Video Ditonton (<?= htmlspecialchars($rangeTitle) ?>)</span>
        <a href="/console/video_analytics.php" class="btn btn-sm btn-link text-warning p-0" style="font-size:11.5px;text-decoration:none;">Lihat Semua &rarr;</a>
      </div>
      <div class="c-card-body p-0">
        <div class="table-responsive">
          <table class="c-table">
            <thead><tr><th>Video</th><th class="text-end">Ditonton</th><th class="text-end">Cuan Keluar</th></tr></thead>
            <tbody>
              <?php if (empty($topWatchedVideos)): ?>
              <tr><td colspan="3" class="text-center text-secondary py-3" style="font-size:12px;">Belum ada video ditonton pada periode ini.</td></tr>
              <?php else: foreach ($topWatchedVideos as $v): ?>
              <tr>
                <td>
                  <div class="fw-semibold text-white text-truncate" style="max-width:180px;font-size:12px;" title="<?= htmlspecialchars($v['title']) ?>">
                    <?= htmlspecialchars($v['title']) ?>
                  </div>
                  <div style="font-size:10.5px;color:#64748b;">ID #<?= $v['id'] ?></div>
                </td>
                <td class="text-end font-monospace fw-bold text-warning" style="font-size:12px;"><?= number_format((int)$v['watch_count']) ?>x</td>
                <td class="text-end font-monospace text-emerald" style="color:#10b981;font-size:12px;"><?= format_rp((float)$v['rewards_sum']) ?></td>
              </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- TOP HALAMAN VISITOR -->
    <div class="c-card">
      <div class="c-card-header d-flex align-items-center justify-content-between">
        <span class="c-card-title">🌐 Top Halaman Dikunjungi</span>
        <a href="/console/analytics.php" class="btn btn-sm btn-link text-info p-0" style="font-size:11.5px;text-decoration:none;">Traffic Detail &rarr;</a>
      </div>
      <div class="c-card-body p-0">
        <div class="table-responsive">
          <table class="c-table">
            <thead><tr><th>Path / Halaman</th><th class="text-end">Views</th><th class="text-end">IP Unik</th></tr></thead>
            <tbody>
              <?php if (empty($topPages)): ?>
              <tr><td colspan="3" class="text-center text-secondary py-3" style="font-size:12px;">Belum ada kunjungan tercatat.</td></tr>
              <?php else: foreach ($topPages as $p): ?>
              <tr>
                <td>
                  <span class="font-monospace text-white fw-semibold" style="font-size:12px;"><?= htmlspecialchars($p['path']) ?></span>
                </td>
                <td class="text-end font-monospace text-info fw-bold" style="font-size:12px;"><?= number_format((int)$p['cnt']) ?></td>
                <td class="text-end font-monospace text-secondary" style="font-size:12px;"><?= number_format((int)$p['unique_ips']) ?></td>
              </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>

  <!-- LIVE RECENT ACTIVITY FEED -->
  <div class="col-lg-7">
    <div class="c-card h-100">
      <div class="c-card-header d-flex align-items-center justify-content-between">
        <div class="d-flex align-items-center gap-2">
          <span style="display:inline-block;width:9px;height:9px;border-radius:50%;background:#10b981;box-shadow:0 0 8px #10b981;"></span>
          <span class="c-card-title">Degup Aktivitas Web Terkini (Live Activity Stream)</span>
        </div>
        <span class="badge" style="background:#1e2438;color:#94a3b8;font-size:10px;">30 Aksi Terakhir</span>
      </div>
      <div class="c-card-body p-0">
        <div style="max-height:540px;overflow-y:auto;padding:8px 14px;">
          <?php if (empty($activityStream)): ?>
          <div class="text-center text-secondary py-4" style="font-size:12.5px;">Belum ada aktivitas tercatat hari ini.</div>
          <?php else: foreach ($activityStream as $act): 
            $timeAgo = date('H:i:s', strtotime($act['created_at'])) . ' (' . date('d M', strtotime($act['created_at'])) . ')';
          ?>
          <div class="d-flex align-items-center justify-content-between py-2.5 px-2 my-1" style="border-radius:8px;background:rgba(255,255,255,0.02);border:1px solid rgba(255,255,255,0.04);transition:all .15s ease;">
            <div class="d-flex align-items-center gap-2.5 min-w-0">
              <?php if ($act['act_type'] === 'deposit'): ?>
                <div style="width:30px;height:30px;border-radius:8px;background:rgba(16,185,129,0.15);color:#10b981;display:flex;align-items:center;justify-content:center;font-size:13px;flex-shrink:0;">💳</div>
                <div>
                  <div style="font-size:12.5px;font-weight:600;color:#f8fafc;">
                    <strong style="color:#fff;"><?= htmlspecialchars($act['username']) ?></strong> deposit 
                    <span style="color:#10b981;font-weight:700;"><?= format_rp((float)$act['amount']) ?></span>
                  </div>
                  <div style="font-size:10.5px;color:#64748b;">
                    Status: <span class="badge" style="font-size:9px;background:<?= $act['status']==='confirmed'||$act['status']==='approved'?'rgba(16,185,129,0.2);color:#34d399;':'rgba(245,158,11,0.2);color:#fbbf24;' ?>"><?= htmlspecialchars($act['status']) ?></span>
                  </div>
                </div>

              <?php elseif ($act['act_type'] === 'watch'): ?>
                <div style="width:30px;height:30px;border-radius:8px;background:rgba(245,158,11,0.15);color:#f59e0b;display:flex;align-items:center;justify-content:center;font-size:13px;flex-shrink:0;">🎬</div>
                <div>
                  <div style="font-size:12.5px;font-weight:600;color:#f8fafc;">
                    <strong style="color:#fff;"><?= htmlspecialchars($act['username']) ?></strong> nonton video 
                    <span style="color:#f59e0b;font-weight:700;">+<?= format_rp((float)$act['amount']) ?></span>
                  </div>
                  <div style="font-size:10.5px;color:#64748b;max-width:240px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                    <?= htmlspecialchars($act['extra_info'] ?? 'Video') ?>
                  </div>
                </div>

              <?php elseif ($act['act_type'] === 'upgrade'): ?>
                <div style="width:30px;height:30px;border-radius:8px;background:rgba(168,85,247,0.15);color:#a855f7;display:flex;align-items:center;justify-content:center;font-size:13px;flex-shrink:0;">👑</div>
                <div>
                  <div style="font-size:12.5px;font-weight:600;color:#f8fafc;">
                    <strong style="color:#fff;"><?= htmlspecialchars($act['username']) ?></strong> klaim kasta 
                    <span style="color:#a855f7;font-weight:700;"><?= htmlspecialchars($act['extra_info'] ?? '') ?></span>
                  </div>
                  <div style="font-size:10.5px;color:#64748b;">
                    Biaya: <?= format_rp((float)$act['amount']) ?>
                  </div>
                </div>

              <?php else: ?>
                <div style="width:30px;height:30px;border-radius:8px;background:rgba(56,189,248,0.15);color:#38bdf8;display:flex;align-items:center;justify-content:center;font-size:13px;flex-shrink:0;">👤</div>
                <div>
                  <div style="font-size:12.5px;font-weight:600;color:#f8fafc;">
                    Pengguna baru mendaftar: <strong style="color:#38bdf8;"><?= htmlspecialchars($act['username']) ?></strong>
                  </div>
                  <div style="font-size:10.5px;color:#64748b;">
                    Kode Ref: <?= htmlspecialchars($act['extra_info'] ?? '-') ?>
                  </div>
                </div>
              <?php endif; ?>
            </div>

            <div class="text-end" style="font-size:11px;color:#64748b;flex-shrink:0;">
              <?= $timeAgo ?>
            </div>
          </div>
          <?php endforeach; endif; ?>
        </div>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
  // 1. Multi-Trend Chart
  const ctxMulti = document.getElementById('multiTrendChart');
  if (ctxMulti) {
    new Chart(ctxMulti, {
      type: 'line',
      data: {
        labels: <?= json_encode($chartLabels) ?>,
        datasets: [
          {
            label: 'Deposit Sukses (Rp)',
            data: <?= json_encode($chartDepoData) ?>,
            borderColor: '#10b981',
            backgroundColor: 'rgba(16, 185, 129, 0.1)',
            borderWidth: 2.5,
            fill: true,
            tension: 0.35,
            yAxisID: 'yDeposit',
            pointRadius: 3,
            pointHoverRadius: 6
          },
          {
            label: 'Visitor (Pageviews)',
            data: <?= json_encode($chartViewsData) ?>,
            borderColor: '#38bdf8',
            backgroundColor: 'transparent',
            borderWidth: 2,
            borderDash: [4, 4],
            tension: 0.35,
            yAxisID: 'yCount',
            pointRadius: 2.5,
            pointHoverRadius: 5
          },
          {
            label: 'Video Ditonton',
            data: <?= json_encode($chartWatchData) ?>,
            borderColor: '#f59e0b',
            backgroundColor: 'transparent',
            borderWidth: 2,
            tension: 0.35,
            yAxisID: 'yCount',
            pointRadius: 2.5,
            pointHoverRadius: 5
          }
        ]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        plugins: {
          legend: {
            display: false
          },
          tooltip: {
            backgroundColor: 'rgba(15, 23, 42, 0.95)',
            titleColor: '#fff',
            bodyColor: '#cbd5e1',
            borderColor: '#334155',
            borderWidth: 1,
            padding: 10,
            callbacks: {
              label: function(context) {
                let label = context.dataset.label || '';
                if (label) label += ': ';
                if (context.datasetIndex === 0) {
                  label += 'Rp ' + Number(context.parsed.y).toLocaleString('id-ID');
                } else {
                  label += Number(context.parsed.y).toLocaleString('id-ID');
                }
                return label;
              }
            }
          }
        },
        scales: {
          x: {
            grid: { color: 'rgba(255,255,255,0.05)' },
            ticks: { color: '#94a3b8', font: { size: 10.5 } }
          },
          yDeposit: {
            type: 'linear',
            position: 'left',
            grid: { color: 'rgba(255,255,255,0.05)' },
            ticks: {
              color: '#10b981',
              font: { size: 10.5 },
              callback: function(val) {
                if (val >= 1000000) return (val/1000000).toFixed(1) + 'jt';
                if (val >= 1000) return (val/1000).toFixed(0) + 'rb';
                return val;
              }
            }
          },
          yCount: {
            type: 'linear',
            position: 'right',
            grid: { drawOnChartArea: false },
            ticks: { color: '#94a3b8', font: { size: 10.5 } }
          }
        }
      }
    });
  }

  // 2. Hourly Bar Chart (Peak Activity)
  const ctxHourly = document.getElementById('hourlyBarChart');
  if (ctxHourly) {
    const hours = Array.from({length: 24}, (_, i) => i + ':00');
    const hourlyViews = <?= json_encode(array_column($hourlyActivity, 'views')) ?>;
    const hourlyWatches = <?= json_encode(array_column($hourlyActivity, 'watches')) ?>;

    new Chart(ctxHourly, {
      type: 'bar',
      data: {
        labels: hours,
        datasets: [
          {
            label: 'Nonton Video',
            data: hourlyWatches,
            backgroundColor: '#f59e0b',
            borderRadius: 4,
            stack: 'combined'
          },
          {
            label: 'Page Views',
            data: hourlyViews,
            backgroundColor: 'rgba(56, 189, 248, 0.4)',
            borderRadius: 4,
            stack: 'combined'
          }
        ]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: {
            position: 'bottom',
            labels: { color: '#94a3b8', boxWidth: 10, font: { size: 10 } }
          },
          tooltip: {
            backgroundColor: 'rgba(15, 23, 42, 0.95)',
            titleColor: '#fff',
            bodyColor: '#cbd5e1',
            borderColor: '#334155',
            borderWidth: 1
          }
        },
        scales: {
          x: {
            grid: { display: false },
            ticks: { color: '#94a3b8', font: { size: 9 }, maxRotation: 0 }
          },
          y: {
            grid: { color: 'rgba(255,255,255,0.05)' },
            ticks: { color: '#94a3b8', font: { size: 10 } }
          }
        }
      }
    });
  }
});
</script>

<?php require __DIR__ . '/partials/footer.php'; ?>
