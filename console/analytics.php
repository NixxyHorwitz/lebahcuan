<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
staff_require('analytics');

// Helper: Parse User Agent into readable text
if (!function_exists('parse_ua_short')) {
    function parse_ua_short(?string $ua): string {
        if (empty($ua)) return 'Unknown Device';
        
        // Detect OS
        $os = 'Unknown OS';
        if (stripos($ua, 'windows nt 10.0') !== false) $os = 'Windows 10/11';
        elseif (stripos($ua, 'windows nt 6.1') !== false) $os = 'Windows 7';
        elseif (stripos($ua, 'iphone') !== false) $os = 'iPhone';
        elseif (stripos($ua, 'ipad') !== false) $os = 'iPad';
        elseif (stripos($ua, 'macintosh') !== false || stripos($ua, 'mac os x') !== false) $os = 'macOS';
        elseif (stripos($ua, 'android') !== false) $os = 'Android';
        elseif (stripos($ua, 'linux') !== false) $os = 'Linux';
        
        // Detect Browser
        $browser = 'Unknown Browser';
        if (stripos($ua, 'edg/') !== false) $browser = 'Edge';
        elseif (stripos($ua, 'chrome') !== false) $browser = 'Chrome';
        elseif (stripos($ua, 'safari') !== false) $browser = 'Safari';
        elseif (stripos($ua, 'firefox') !== false) $browser = 'Firefox';
        elseif (stripos($ua, 'opera') !== false || stripos($ua, 'opr/') !== false) $browser = 'Opera';
        
        return "{$browser} on {$os}";
    }
}

// Display filter: today, yesterday, week
$time_filter = trim($_GET['time'] ?? 'today');
if (!in_array($time_filter, ['today', 'yesterday', 'week'], true)) {
    $time_filter = 'today';
}

$chart_range = (int)($_GET['range'] ?? 7);
$chart_range = in_array($chart_range, [7, 14, 30]) ? $chart_range : 7;

// Configure WHERE conditions based on time filter
if ($time_filter === 'yesterday') {
    $traffic_where = "created_at >= CURDATE() - INTERVAL 1 DAY AND created_at < CURDATE()";
    $filter_title = "Kemarin (Yesterday)";
    $filter_badge = "Yesterday's Traffic";
} elseif ($time_filter === 'week') {
    $traffic_where = "created_at >= NOW() - INTERVAL 7 DAY";
    $filter_title = "1 Minggu Terakhir (7 Days)";
    $filter_badge = "Last 7 Days Traffic";
} else { // today
    $traffic_where = "created_at >= CURDATE()";
    $filter_title = "Hari Ini (Today)";
    $filter_badge = "Live Activity Today";
}

// ── Analytics Stats ──────────────────────────────────────
try {
    // Total pageviews in range
    $total_pv = (int)$pdo->query("SELECT COUNT(*) FROM page_views WHERE created_at >= DATE_SUB(NOW(), INTERVAL {$chart_range} DAY)")->fetchColumn();
    // Unique IPs in range
    $unique_ip = (int)$pdo->query("SELECT COUNT(DISTINCT ip_hash) FROM page_views WHERE created_at >= DATE_SUB(NOW(), INTERVAL {$chart_range} DAY)")->fetchColumn();
    // Today's views
    $today_pv  = (int)$pdo->query("SELECT COUNT(*) FROM page_views WHERE created_at >= CURDATE()")->fetchColumn();

    // Daily chart data (last N days)
    $daily = $pdo->query(
        "SELECT DATE(created_at) as d, COUNT(*) as cnt
         FROM page_views
         WHERE created_at >= DATE_SUB(NOW(), INTERVAL {$chart_range} DAY)
         GROUP BY d ORDER BY d ASC"
    )->fetchAll(PDO::FETCH_ASSOC);

    // Top pages (based on active time filter)
    $top_pages = $pdo->query(
        "SELECT path, COUNT(*) as cnt FROM page_views
         WHERE {$traffic_where}
         GROUP BY path ORDER BY cnt DESC LIMIT 10"
    )->fetchAll(PDO::FETCH_ASSOC);

    // Top referrers (based on active time filter)
    $top_refs = $pdo->query(
        "SELECT COALESCE(NULLIF(referrer,''), '(direct)') as ref, COUNT(*) as cnt
         FROM page_views
         WHERE {$traffic_where}
         GROUP BY ref ORDER BY cnt DESC LIMIT 8"
    )->fetchAll(PDO::FETCH_ASSOC);

    // ── Detailed Real IP Traffic ──────────────────────────────
    $ips_stmt = $pdo->query("
        SELECT 
            ip_hash as ip,
            COUNT(*) as hits,
            MIN(created_at) as first_seen,
            MAX(created_at) as last_seen,
            SUBSTRING_INDEX(GROUP_CONCAT(path ORDER BY id DESC SEPARATOR '|||'), '|||', 1) as last_path,
            SUBSTRING_INDEX(GROUP_CONCAT(user_agent ORDER BY id DESC SEPARATOR '|||'), '|||', 1) as ua,
            SUBSTRING_INDEX(GROUP_CONCAT(referrer ORDER BY id DESC SEPARATOR '|||'), '|||', 1) as ref
        FROM page_views
        WHERE {$traffic_where}
        GROUP BY ip_hash
        ORDER BY last_seen DESC
        LIMIT 500
    ");
    $traffic_ips = $ips_stmt->fetchAll(PDO::FETCH_ASSOC);

    $ip_list = array_values(array_unique(array_filter(array_column($traffic_ips, 'ip'))));

    // ── IP to User Matching ──────────────────────────────────
    $ip_users_map = [];
    if (!empty($ip_list)) {
        $placeholders = implode(',', array_fill(0, count($ip_list), '?'));
        $user_match_stmt = $pdo->prepare("
            SELECT pv.ip_hash, u.id, u.username, u.email, u.whatsapp, u.balance_dep, u.balance_wd, u.is_active, u.created_at as registered_at, m.name as membership_name
            FROM page_views pv
            INNER JOIN users u ON u.id = pv.user_id
            LEFT JOIN memberships m ON m.id = u.membership_id
            WHERE pv.ip_hash IN ($placeholders)
            GROUP BY pv.ip_hash, u.id
        ");
        $user_match_stmt->execute($ip_list);
        while ($u_row = $user_match_stmt->fetch(PDO::FETCH_ASSOC)) {
            $ip_users_map[$u_row['ip_hash']][] = $u_row;
        }
    }

    // ── Page Breakdown per IP (Scoped to fetched IPs) ─────────
    $ip_pages_map = [];
    if (!empty($ip_list)) {
        $placeholders = implode(',', array_fill(0, count($ip_list), '?'));
        $pages_stmt = $pdo->prepare("
            SELECT ip_hash, path, COUNT(*) as hit_count, MIN(created_at) as first_visit, MAX(created_at) as last_visit
            FROM page_views
            WHERE ip_hash IN ($placeholders) AND {$traffic_where}
            GROUP BY ip_hash, path
            ORDER BY hit_count DESC
        ");
        $pages_stmt->execute($ip_list);
        while ($p_row = $pages_stmt->fetch(PDO::FETCH_ASSOC)) {
            $ip_pages_map[$p_row['ip_hash']][] = $p_row;
        }
    }

    $has_data = true;
} catch (\Throwable $e) {
    $has_data = false;
    $total_pv = $unique_ip = $today_pv = 0;
    $daily = $top_pages = $top_refs = $traffic_ips = [];
    $ip_users_map = [];
    $ip_pages_map = [];
}

// Build chart labels and data
$labels = []; $chart_data = [];
for ($i = $chart_range - 1; $i >= 0; $i--) {
    $day = date('Y-m-d', strtotime("-{$i} days"));
    $labels[] = date('d/m', strtotime($day));
    $found = array_filter($daily, fn($r) => $r['d'] === $day);
    $chart_data[] = $found ? (int)array_values($found)[0]['cnt'] : 0;
}

$pageTitle  = 'Traffic Analytics';
$activePage = 'analytics';
require __DIR__ . '/partials/header.php';
?>

<div class="mb-4 d-flex align-items-center justify-content-between flex-wrap gap-2">
  <div>
    <h5 class="mb-0 fw-bold">📈 Traffic & User IP Analytics</h5>
    <div style="font-size:12px;color:#888;margin-top:2px">Statistik kunjungan real-time, pencocokan data akun user, dan riwayat halaman</div>
  </div>
  <div class="d-flex align-items-center gap-2 flex-wrap">
    <span class="text-muted small me-1">Grafik Range:</span>
    <?php foreach ([7=>'7 Hari', 14=>'14 Hari', 30=>'30 Hari'] as $r=>$lbl): ?>
    <a href="?time=<?= urlencode($time_filter) ?>&range=<?= $r ?>" class="btn btn-sm <?= $chart_range===$r?'btn-primary text-white':'btn-secondary' ?>"
       style="<?= $chart_range===$r?'background:var(--brand);border-color:var(--brand)':'' ?>;font-size:12px;padding:5px 12px"><?= $lbl ?></a>
    <?php endforeach; ?>
  </div>
</div>

<!-- Stat cards -->
<div class="row row-cols-2 row-cols-md-5 g-3 mb-4">
  <div class="col">
    <div class="c-stat h-100">
      <div class="d-flex align-items-center justify-content-between mb-2">
        <div class="c-stat__lbl">Total Pageviews</div>
        <div class="c-stat__icon" style="background:rgba(59,130,246,.15)">📄</div>
      </div>
      <div class="c-stat__val"><?= number_format($total_pv) ?></div>
      <div style="font-size:11px;color:#777;margin-top:3px"><?= $chart_range ?> hari terakhir</div>
    </div>
  </div>
  <div class="col">
    <div class="c-stat h-100">
      <div class="d-flex align-items-center justify-content-between mb-2">
        <div class="c-stat__lbl">Unique Visitors</div>
        <div class="c-stat__icon" style="background:rgba(76,175,130,.15)">👥</div>
      </div>
      <div class="c-stat__val"><?= number_format($unique_ip) ?></div>
      <div style="font-size:11px;color:#777;margin-top:3px">berdasarkan IP</div>
    </div>
  </div>
  <div class="col">
    <div class="c-stat h-100">
      <div class="d-flex align-items-center justify-content-between mb-2">
        <div class="c-stat__lbl">Hari Ini (Views)</div>
        <div class="c-stat__icon" style="background:rgba(255,193,7,.15)">📅</div>
      </div>
      <div class="c-stat__val"><?= number_format($today_pv) ?></div>
      <div style="font-size:11px;color:#777;margin-top:3px">pageviews hari ini</div>
    </div>
  </div>
  <div class="col">
    <div class="c-stat h-100" style="border: 1.5px solid var(--brand, #ff5e00);">
      <div class="d-flex align-items-center justify-content-between mb-2">
        <div class="c-stat__lbl">Filter: <?= htmlspecialchars($filter_title) ?></div>
        <div class="c-stat__icon" style="background:rgba(255,107,53,.2)">👤</div>
      </div>
      <div class="c-stat__val" style="color:var(--brand, #ff5e00)"><?= number_format(count($traffic_ips)) ?></div>
      <div style="font-size:11px;color:#fff;margin-top:3px">IP unik dalam filter ini</div>
    </div>
  </div>
  <div class="col">
    <div class="c-stat h-100">
      <div class="d-flex align-items-center justify-content-between mb-2">
        <div class="c-stat__lbl">Avg/Hari</div>
        <div class="c-stat__icon" style="background:rgba(255,107,53,.15)">📊</div>
      </div>
      <div class="c-stat__val"><?= $chart_range > 0 ? number_format((int)round($total_pv / $chart_range)) : 0 ?></div>
      <div style="font-size:11px;color:#777;margin-top:3px">rata-rata views</div>
    </div>
  </div>
</div>

<!-- Chart -->
<div class="c-card mb-4">
  <div class="c-card-header d-flex justify-content-between align-items-center">
    <span class="c-card-title">📈 Grafik Kunjungan Harian (<?= $chart_range ?> Hari)</span>
  </div>
  <div class="c-card-body">
    <?php if (array_sum($chart_data) === 0): ?>
    <div style="text-align:center;padding:40px;color:#555;font-size:13px">
      Belum ada data kunjungan dalam <?= $chart_range ?> hari terakhir.<br>
      <span style="font-size:12px;color:#666">Pastikan tracking aktif dan user sudah mengunjungi halaman.</span>
    </div>
    <?php else: ?>
    <canvas id="traffic-chart" style="max-height:220px"></canvas>
    <?php endif; ?>
  </div>
</div>

<div class="row g-3 mb-4">
  <!-- Top pages -->
  <div class="col-md-6">
    <div class="c-card h-100">
      <div class="c-card-header d-flex justify-content-between align-items-center">
        <span class="c-card-title">🔝 Top Halaman Terpopuler (<?= htmlspecialchars($filter_title) ?>)</span>
      </div>
      <div class="c-card-body p-0">
        <?php if (empty($top_pages)): ?>
        <div style="padding:25px;text-align:center;color:#666;font-size:13px">Belum ada data pada periode ini</div>
        <?php else: ?>
        <?php $max = max(array_column($top_pages,'cnt')) ?: 1; ?>
        <?php foreach ($top_pages as $i=>$p): ?>
        <div style="padding:10px 18px;border-bottom:1px solid #1f2235;display:flex;align-items:center;gap:10px">
          <div style="width:24px;height:24px;background:#1f2235;border-radius:6px;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;color:#888"><?= $i+1 ?></div>
          <div style="flex:1;min-width:0">
            <div style="font-size:13px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;color:#fff"><?= htmlspecialchars($p['path']) ?></div>
            <div style="height:4px;background:#1f2235;border-radius:2px;margin-top:4px">
              <div style="height:100%;background:var(--brand);border-radius:2px;width:<?= round(($p['cnt']/$max)*100) ?>%"></div>
            </div>
          </div>
          <div style="font-size:13px;font-weight:700;color:#4CAF82;flex-shrink:0"><?= number_format((int)$p['cnt']) ?> hits</div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Top referrers -->
  <div class="col-md-6">
    <div class="c-card h-100">
      <div class="c-card-header d-flex justify-content-between align-items-center">
        <span class="c-card-title">🔗 Sumber Traffic / Referrer (<?= htmlspecialchars($filter_title) ?>)</span>
      </div>
      <div class="c-card-body p-0">
        <?php if (empty($top_refs)): ?>
        <div style="padding:25px;text-align:center;color:#666;font-size:13px">Belum ada data pada periode ini</div>
        <?php else: ?>
        <?php $maxr = max(array_column($top_refs,'cnt')) ?: 1; ?>
        <?php foreach ($top_refs as $r): ?>
        <div style="padding:10px 18px;border-bottom:1px solid #1f2235;display:flex;align-items:center;gap:10px">
          <div style="flex:1;min-width:0">
            <div style="font-size:12px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;color:#ccc">
              <?= htmlspecialchars(strlen($r['ref'])>50 ? substr($r['ref'],0,50).'...' : $r['ref']) ?>
            </div>
            <div style="height:3px;background:#1f2235;border-radius:2px;margin-top:4px">
              <div style="height:100%;background:#4E9BFF;border-radius:2px;width:<?= round(($r['cnt']/$maxr)*100) ?>%"></div>
            </div>
          </div>
          <div style="font-size:13px;font-weight:700;color:#4E9BFF;flex-shrink:0"><?= number_format((int)$r['cnt']) ?> views</div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- ============================================================
     REAL IP & USER MATCHING TRAFFIC TABLE WITH TIME FILTERS
     ============================================================ -->
<div class="c-card">
  <div class="c-card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
      <span class="c-card-title d-flex align-items-center gap-2">
        <span>🌐 Detail Traffic, Real IP & Pencocokan User</span>
        <span class="badge" style="background:#1f2235;color:var(--brand);font-size:11px;border:1px solid rgba(255,107,53,0.3)">
          <?= htmlspecialchars($filter_badge) ?>
        </span>
      </span>
      <div style="font-size:12px;color:#888;margin-top:3px">
        Melacak IP pengunjung, mencocokkan akun user terdaftar, dan merekam seluruh riwayat halaman yang dikunjungi.
      </div>
    </div>

    <!-- Filter Buttons (Today, Yesterday, 1 Weeks) -->
    <div class="btn-group p-1" style="background:#0e1017;border:1px solid #232738;border-radius:8px;">
      <a href="?time=today&range=<?= $chart_range ?>" 
         class="btn btn-sm px-3 <?= $time_filter==='today' ? 'btn-primary active fw-bold text-white' : 'btn-dark text-muted' ?>"
         style="<?= $time_filter==='today' ? 'background:var(--brand);border-color:var(--brand);box-shadow:0 2px 6px rgba(255,94,0,0.35);' : 'background:transparent;border:none;' ?> font-size:12px;border-radius:6px;">
        ☀️ Hari Ini (Today)
      </a>
      <a href="?time=yesterday&range=<?= $chart_range ?>" 
         class="btn btn-sm px-3 <?= $time_filter==='yesterday' ? 'btn-primary active fw-bold text-white' : 'btn-dark text-muted' ?>"
         style="<?= $time_filter==='yesterday' ? 'background:var(--brand);border-color:var(--brand);box-shadow:0 2px 6px rgba(255,94,0,0.35);' : 'background:transparent;border:none;' ?> font-size:12px;border-radius:6px;">
        📅 Kemarin (Yesterday)
      </a>
      <a href="?time=week&range=<?= $chart_range ?>" 
         class="btn btn-sm px-3 <?= $time_filter==='week' ? 'btn-primary active fw-bold text-white' : 'btn-dark text-muted' ?>"
         style="<?= $time_filter==='week' ? 'background:var(--brand);border-color:var(--brand);box-shadow:0 2px 6px rgba(255,94,0,0.35);' : 'background:transparent;border:none;' ?> font-size:12px;border-radius:6px;">
        🗓️ 1 Minggu (1 Week)
      </a>
    </div>
  </div>

  <div class="c-card-body p-3">
    <div class="table-responsive">
      <table id="analytics-table" class="c-table table table-dark table-striped table-hover mb-0" style="font-size: 13px; background: #131520; border: none; width: 100%;">
        <thead>
          <tr style="border-bottom: 2px solid #1f2235; color: #aaa;">
            <th class="px-3 py-3" style="font-weight: 700; width: 220px;">IP Address & Perangkat</th>
            <th class="px-3 py-3" style="font-weight: 700; width: 230px;">Pencocokan User Data</th>
            <th class="px-3 py-3 text-center" style="font-weight: 700; width: 90px;">Hits</th>
            <th class="px-3 py-3" style="font-weight: 700;">Halaman Terakhir & Riwayat</th>
            <th class="px-3 py-3" style="font-weight: 700; width: 160px;">Sumber / Referrer</th>
            <th class="px-3 py-3 text-end" style="font-weight: 700; width: 130px;">Waktu Terakhir</th>
            <th class="px-3 py-3 text-center" style="font-weight: 700; width: 100px;">Aksi</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($traffic_ips)): ?>
            <tr>
              <td colspan="7" class="text-center py-5 text-muted">
                <div style="font-size:24px;margin-bottom:6px">📭</div>
                Belum ada data kunjungan pada periode <strong><?= htmlspecialchars($filter_title) ?></strong>.
              </td>
            </tr>
          <?php else: ?>
            <?php foreach ($traffic_ips as $idx => $item): 
              $ip = (string)$item['ip'];
              $matched_users = $ip_users_map[$ip] ?? [];
              $visited_pages = $ip_pages_map[$ip] ?? [];
              $totalPagesCount = count($visited_pages);
              
              // Prepare JSON payload for the Detail Modal
              $modalData = [
                  'ip' => $ip,
                  'hits' => (int)$item['hits'],
                  'first_seen' => $item['first_seen'],
                  'last_seen' => $item['last_seen'],
                  'ua' => (string)$item['ua'],
                  'ua_parsed' => parse_ua_short((string)$item['ua']),
                  'ref' => (string)$item['ref'],
                  'users' => $matched_users,
                  'pages' => $visited_pages,
              ];
              $jsonDataAttr = htmlspecialchars(json_encode($modalData, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
            ?>
              <tr style="border-bottom: 1px solid #1f2235; vertical-align: middle;">
                <!-- IP Address & Device -->
                <td class="px-3 py-3">
                  <div class="d-flex align-items-center gap-1.5 mb-1">
                    <span class="fw-bold" style="color: #fff; font-family: monospace; font-size: 13px;">
                      <?php 
                        if (strlen($ip) === 64) {
                            echo '<span class="text-muted" title="' . htmlspecialchars($ip) . '">' . substr($ip, 0, 12) . '... (Hash)</span>';
                        } else {
                            echo htmlspecialchars($ip);
                        }
                      ?>
                    </span>
                    <?php if (strlen($ip) <= 45): ?>
                      <button type="button" class="btn btn-sm btn-link p-0 text-muted" title="Copy IP" onclick="navigator.clipboard.writeText('<?= htmlspecialchars($ip) ?>'); this.innerText='✓'; setTimeout(()=>this.innerText='📋', 1200);">📋</button>
                    <?php endif; ?>
                  </div>
                  <div style="font-size: 11px; color: #888;">
                    <?= htmlspecialchars(parse_ua_short((string)$item['ua'])) ?>
                  </div>
                </td>

                <!-- Matched User Profile -->
                <td class="px-3 py-3">
                  <?php if (!empty($matched_users)): ?>
                    <?php if (count($matched_users) > 1): ?>
                      <div class="mb-1">
                        <span class="badge bg-danger text-white px-2 py-1" style="font-size: 10px;">
                          ⚠️ Multi-Account (<?= count($matched_users) ?> Akun)
                        </span>
                      </div>
                    <?php endif; ?>

                    <?php foreach (array_slice($matched_users, 0, 2) as $u): ?>
                      <div class="d-flex align-items-center gap-1.5 mb-1">
                        <a href="/console/users.php?search=<?= urlencode($u['username']) ?>" target="_blank" class="fw-bold text-decoration-none" style="color: #4CAF82; font-size: 12.5px;">
                          👤 <?= htmlspecialchars($u['username']) ?>
                        </a>
                        <span class="badge" style="background:#202538;color:#aaa;font-size:9.5px;padding:2px 5px;">#<?= $u['id'] ?></span>
                        <?php if (!empty($u['membership_name'])): ?>
                          <span class="badge bg-warning text-dark" style="font-size: 9px; padding: 2px 4px;"><?= htmlspecialchars($u['membership_name']) ?></span>
                        <?php endif; ?>
                      </div>
                      <div style="font-size: 10.5px; color: #888;">
                        <?php if (!empty($u['whatsapp'])): ?>
                          <span>📱 <?= htmlspecialchars($u['whatsapp']) ?></span>
                        <?php elseif (!empty($u['email'])): ?>
                          <span>✉️ <?= htmlspecialchars(substr($u['email'], 0, 18)) ?><?= strlen($u['email'])>18?'...':'' ?></span>
                        <?php endif; ?>
                      </div>
                    <?php endforeach; ?>

                    <?php if (count($matched_users) > 2): ?>
                      <div style="font-size: 10px; color: #ff9800; margin-top: 2px;">
                        +<?= count($matched_users) - 2 ?> akun lainnya
                      </div>
                    <?php endif; ?>

                  <?php else: ?>
                    <span class="badge px-2 py-1" style="background: #1f2235; color: #777; font-size: 11px; border: 1px dashed #333852;">
                      👤 Guest (Belum Login)
                    </span>
                  <?php endif; ?>
                </td>

                <!-- Hits Count -->
                <td class="px-3 py-3 text-center">
                  <span class="badge px-2.5 py-1.5 fw-bold" style="background-color: var(--brand, #ff5e00) !important; font-size: 11.5px; border-radius: 6px; color: #fff;">
                    <?= number_format((int)$item['hits']) ?> hits
                  </span>
                </td>

                <!-- Last Path & Visited Pages Summary -->
                <td class="px-3 py-3">
                  <div class="d-flex align-items-center gap-1.5 mb-1">
                    <span class="text-muted" style="font-size:11px;">Terakhir:</span>
                    <code style="color: #4CAF82; background: rgba(76, 175, 130, 0.1); padding: 2px 6px; border-radius: 4px; font-size: 12px;">
                      <?= htmlspecialchars($item['last_path'] ?: '/') ?>
                    </code>
                  </div>

                  <!-- Visited Pages Pills Preview -->
                  <div class="d-flex flex-wrap gap-1 align-items-center mt-1">
                    <?php 
                      $previewPages = array_slice($visited_pages, 0, 3);
                      foreach ($previewPages as $p):
                    ?>
                      <span class="badge" style="background: #181b29; border: 1px solid #282d45; color: #bbb; font-size: 10.5px; font-weight: normal; padding: 3px 6px;">
                        <?= htmlspecialchars(strlen($p['path']) > 22 ? substr($p['path'], 0, 20).'..' : $p['path']) ?>
                        <strong style="color: var(--brand, #ff5e00); margin-left: 2px;"><?= $p['hit_count'] ?>x</strong>
                      </span>
                    <?php endforeach; ?>

                    <?php if ($totalPagesCount > 3): ?>
                      <span class="badge" style="background: #252a3d; color: #4E9BFF; font-size: 10px; padding: 3px 6px;">
                        +<?= $totalPagesCount - 3 ?> halaman
                      </span>
                    <?php endif; ?>
                  </div>
                </td>

                <!-- Referrer -->
                <td class="px-3 py-3 text-muted" style="font-size: 11.5px;">
                  <?= htmlspecialchars(empty($item['ref']) || $item['ref'] === '(direct)' ? 'Direct Traffic' : (strlen($item['ref']) > 35 ? substr($item['ref'], 0, 35) . '...' : $item['ref'])) ?>
                </td>

                <!-- Last Seen -->
                <td class="px-3 py-3 text-end">
                  <div class="fw-bold" style="color: #FFD166; font-size: 12.5px;">
                    <?= date('H:i:s', strtotime($item['last_seen'])) ?>
                  </div>
                  <div style="font-size: 10.5px; color: #777;">
                    <?= date('d M Y', strtotime($item['last_seen'])) ?>
                  </div>
                </td>

                <!-- Action: Detail Modal -->
                <td class="px-3 py-3 text-center">
                  <button type="button" class="btn btn-sm btn-outline-info px-2.5 py-1.5 btn-open-ip-detail" 
                          data-payload="<?= $jsonDataAttr ?>"
                          style="font-size: 11.5px; border-radius: 6px;"
                          title="Lihat riwayat halaman & data lengkap IP ini">
                    🔍 Riwayat
                  </button>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- ============================================================
     MODAL: DETAIL RIWAYAT HALAMAN & PENCOCOKAN USER IP
     ============================================================ -->
<div class="modal fade" id="modalIpDetail" tabindex="-1" aria-labelledby="modalIpDetailLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content text-white" style="background: #141724; border: 1px solid #2a3048; border-radius: 12px; box-shadow: 0 10px 30px rgba(0,0,0,0.6);">
      
      <div class="modal-header" style="border-bottom: 1px solid #23283e; padding: 14px 20px;">
        <div>
          <h5 class="modal-title fw-bold d-flex align-items-center gap-2" id="modalIpDetailLabel" style="font-size: 16px;">
            <span>🌐 Riwayat Kunjungan IP:</span>
            <span id="modal-ip-title" class="text-warning font-monospace"></span>
          </h5>
          <div style="font-size: 11.5px; color: #888;">Informasi kecocokan akun user dan seluruh halaman yang dijelajahi</div>
        </div>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>

      <div class="modal-body p-3" style="background: #111420;">
        
        <!-- Matched User Box -->
        <div id="modal-users-container" class="mb-3"></div>

        <!-- IP Meta Info Cards -->
        <div class="row g-2 mb-3">
          <div class="col-6 col-md-3">
            <div class="p-2 rounded" style="background: #191d2d; border: 1px solid #23293e;">
              <div class="text-muted" style="font-size: 10.5px;">TOTAL HITS</div>
              <div id="modal-total-hits" class="fw-bold" style="font-size: 16px; color: var(--brand, #ff5e00);">0</div>
            </div>
          </div>
          <div class="col-6 col-md-3">
            <div class="p-2 rounded" style="background: #191d2d; border: 1px solid #23293e;">
              <div class="text-muted" style="font-size: 10.5px;">TOTAL HALAMAN UNIK</div>
              <div id="modal-unique-pages" class="fw-bold text-success" style="font-size: 16px;">0</div>
            </div>
          </div>
          <div class="col-6 col-md-3">
            <div class="p-2 rounded" style="background: #191d2d; border: 1px solid #23293e;">
              <div class="text-muted" style="font-size: 10.5px;">PERTAMA DILIHAT</div>
              <div id="modal-first-seen" class="fw-bold text-light" style="font-size: 12px; margin-top: 2px;">-</div>
            </div>
          </div>
          <div class="col-6 col-md-3">
            <div class="p-2 rounded" style="background: #191d2d; border: 1px solid #23293e;">
              <div class="text-muted" style="font-size: 10.5px;">TERAKHIR DILIHAT</div>
              <div id="modal-last-seen" class="fw-bold text-warning" style="font-size: 12px; margin-top: 2px;">-</div>
            </div>
          </div>
        </div>

        <!-- Perangkat & Referrer -->
        <div class="p-2.5 rounded mb-3" style="background: #161927; border: 1px solid #22273d; font-size: 12px;">
          <div class="row g-2">
            <div class="col-md-6">
              <span class="text-muted">Perangkat / UA:</span>
              <div id="modal-device" class="fw-semibold text-light mt-0.5"></div>
              <div id="modal-ua-raw" class="font-monospace text-muted mt-0.5" style="font-size: 10.5px; word-break: break-all;"></div>
            </div>
            <div class="col-md-6">
              <span class="text-muted">Sumber Masuk / Referrer Terakhir:</span>
              <div id="modal-referrer" class="fw-semibold text-info mt-0.5" style="word-break: break-all;">Direct Traffic</div>
            </div>
          </div>
        </div>

        <!-- Visited Pages Detailed Table -->
        <h6 class="fw-bold mb-2 d-flex align-items-center justify-content-between" style="font-size: 13.5px; color: #fff;">
          <span>📑 Rincian Halaman Yang Dikunjungi (<span id="modal-pages-count">0</span>)</span>
          <span class="text-muted" style="font-size: 11px; font-weight: normal;">Diurutkan berdasarkan frekuensi hits</span>
        </h6>
        
        <div class="table-responsive rounded" style="border: 1px solid #23283e;">
          <table class="table table-dark table-striped mb-0" style="font-size: 12.5px; background: #131624;">
            <thead>
              <tr style="border-bottom: 2px solid #23283e; color: #999;">
                <th class="px-3 py-2.5" style="width: 40px;">#</th>
                <th class="px-3 py-2.5">Halaman / URL Path</th>
                <th class="px-3 py-2.5 text-center" style="width: 110px;">Jumlah Kunjungan</th>
                <th class="px-3 py-2.5" style="width: 140px;">Kunjungan Pertama</th>
                <th class="px-3 py-2.5 text-end" style="width: 140px;">Kunjungan Terakhir</th>
              </tr>
            </thead>
            <tbody id="modal-pages-tbody">
              <!-- Rendered via JS -->
            </tbody>
          </table>
        </div>

      </div>

      <div class="modal-footer" style="border-top: 1px solid #23283e; padding: 10px 20px;">
        <button type="button" class="btn btn-secondary btn-sm px-3" data-bs-dismiss="modal" style="font-size: 12px;">Tutup</button>
      </div>

    </div>
  </div>
</div>

<?php if (array_sum($chart_data) > 0): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
new Chart(document.getElementById('traffic-chart'), {
  type: 'bar',
  data: {
    labels: <?= json_encode($labels) ?>,
    datasets: [{
      label: 'Pageviews',
      data: <?= json_encode($chart_data) ?>,
      backgroundColor: 'rgba(255,107,53,.7)',
      borderColor: '#FF6B35',
      borderWidth: 2,
      borderRadius: 6,
      borderSkipped: false,
    }]
  },
  options: {
    responsive: true,
    plugins: {
      legend: { display: false },
      tooltip: { callbacks: { label: ctx => ctx.parsed.y + ' views' } }
    },
    scales: {
      y: { beginAtZero:true, ticks:{color:'#666',stepSize:1}, grid:{color:'#1f2235'} },
      x: { ticks:{color:'#666'}, grid:{color:'#1f2235'} }
    }
  }
});
</script>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function() {
  // Initialize DataTables if available
  if (typeof $ !== 'undefined' && $.fn.DataTable) {
    if (!$.fn.DataTable.isDataTable('#analytics-table')) {
      $('#analytics-table').DataTable({
        order: [[5, 'desc']], // sort by last seen desc
        pageLength: 25,
        language: {
          search: "Cari IP / User / Halaman:",
          lengthMenu: "Tampilkan _MENU_ baris",
          info: "Menampilkan _START_ sampai _END_ dari _TOTAL_ IP",
          paginate: {
            first: "Awal",
            last: "Akhir",
            next: "→",
            previous: "←"
          },
          emptyTable: "Belum ada data kunjungan."
        }
      });
    }
  }

  // Handle Modal IP Detail Click
  const modalEl = document.getElementById('modalIpDetail');
  const bsModal = modalEl ? new bootstrap.Modal(modalEl) : null;

  document.querySelectorAll('.btn-open-ip-detail').forEach(btn => {
    btn.addEventListener('click', function() {
      try {
        const raw = this.getAttribute('data-payload');
        if (!raw) return;
        const data = JSON.parse(raw);

        // Fill basic IP details
        document.getElementById('modal-ip-title').innerText = data.ip;
        document.getElementById('modal-total-hits').innerText = Number(data.hits).toLocaleString('id-ID') + ' Hits';
        document.getElementById('modal-unique-pages').innerText = (data.pages ? data.pages.length : 0) + ' Halaman';
        document.getElementById('modal-first-seen').innerText = data.first_seen || '-';
        document.getElementById('modal-last-seen').innerText = data.last_seen || '-';
        document.getElementById('modal-device').innerText = data.ua_parsed || 'Unknown Device';
        document.getElementById('modal-ua-raw').innerText = data.ua || '';
        document.getElementById('modal-referrer').innerText = data.ref && data.ref !== '(direct)' ? data.ref : 'Direct / Tanpa Referrer';
        document.getElementById('modal-pages-count').innerText = data.pages ? data.pages.length : 0;

        // Render Matched Users
        const userContainer = document.getElementById('modal-users-container');
        userContainer.innerHTML = '';
        if (data.users && data.users.length > 0) {
          let userHtml = `
            <div class="p-3 rounded" style="background: rgba(76, 175, 130, 0.08); border: 1px solid rgba(76, 175, 130, 0.3);">
              <div class="d-flex align-items-center justify-content-between mb-2">
                <span class="fw-bold text-success" style="font-size: 13px;">
                  ✅ Cocok dengan ${data.users.length} Akun User Terdaftar:
                </span>
                ${data.users.length > 1 ? '<span class="badge bg-danger">⚠️ Potensi Multi-Account</span>' : ''}
              </div>
              <div class="row g-2">
          `;

          data.users.forEach(u => {
            const dep = Number(u.balance_dep || 0).toLocaleString('id-ID');
            const wd = Number(u.balance_wd || 0).toLocaleString('id-ID');
            userHtml += `
              <div class="col-md-6">
                <div class="p-2.5 rounded" style="background: #151928; border: 1px solid #262c44;">
                  <div class="d-flex align-items-center justify-content-between mb-1">
                    <a href="/console/users.php?search=${encodeURIComponent(u.username)}" target="_blank" class="fw-bold text-decoration-none text-warning" style="font-size: 13px;">
                      👤 ${u.username} <span class="badge bg-dark text-muted" style="font-size: 10px;">#${u.id}</span>
                    </a>
                    <span class="badge ${u.is_active == 1 ? 'bg-success' : 'bg-secondary'}" style="font-size: 9.5px;">
                      ${u.is_active == 1 ? 'Active' : 'Banned'}
                    </span>
                  </div>
                  <div class="text-muted" style="font-size: 11px;">
                    <div>📱 WA: <span class="text-light">${u.whatsapp || '-'}</span></div>
                    <div>✉️ Email: <span class="text-light">${u.email || '-'}</span></div>
                    <div>⭐ Level: <span class="text-info">${u.membership_name || 'Reguler'}</span></div>
                    <div>💰 Saldo Dep/WD: <span class="text-success">Rp ${dep}</span> / <span class="text-warning">Rp ${wd}</span></div>
                  </div>
                </div>
              </div>
            `;
          });

          userHtml += `</div></div>`;
          userContainer.innerHTML = userHtml;
        } else {
          userContainer.innerHTML = `
            <div class="p-2.5 rounded text-muted" style="background: #181b2a; border: 1px dashed #2b3147; font-size: 12px;">
              👤 <strong>Belum ada akun user yang login dari IP ini.</strong> (Kunjungan anonim / guest).
            </div>
          `;
        }

        // Render Visited Pages Table
        const tbody = document.getElementById('modal-pages-tbody');
        tbody.innerHTML = '';
        if (data.pages && data.pages.length > 0) {
          const totalHits = data.hits || 1;
          data.pages.forEach((p, idx) => {
            const percent = Math.round((Number(p.hit_count) / totalHits) * 100);
            const tr = document.createElement('tr');
            tr.style.borderBottom = '1px solid #1e2338';
            tr.innerHTML = `
              <td class="px-3 py-2.5 text-muted">${idx + 1}</td>
              <td class="px-3 py-2.5">
                <div class="fw-semibold text-light font-monospace" style="font-size: 12px;">${p.path}</div>
                <div class="progress mt-1" style="height: 3px; background: #1e2235;">
                  <div class="progress-bar" style="width: ${percent}%; background: var(--brand, #ff5e00);"></div>
                </div>
              </td>
              <td class="px-3 py-2.5 text-center">
                <span class="badge px-2 py-1" style="background: var(--brand, #ff5e00); font-size: 11px;">
                  ${Number(p.hit_count).toLocaleString('id-ID')} views
                </span>
                <span class="text-muted ms-1" style="font-size: 10px;">(${percent}%)</span>
              </td>
              <td class="px-3 py-2.5 text-muted" style="font-size: 11px;">${p.first_visit || '-'}</td>
              <td class="px-3 py-2.5 text-end text-warning fw-bold" style="font-size: 11px;">${p.last_visit || '-'}</td>
            `;
            tbody.appendChild(tr);
          });
        } else {
          tbody.innerHTML = `<tr><td colspan="5" class="text-center py-3 text-muted">Belum ada rincian halaman.</td></tr>`;
        }

        if (bsModal) {
          bsModal.show();
        }
      } catch (err) {
        console.error('Error opening IP detail modal:', err);
      }
    });
  });
});
</script>

<?php require __DIR__ . '/partials/footer.php'; ?>


