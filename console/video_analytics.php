<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
staff_require('video_analytics');

try {
    // 0. Summary Stats Metrics
    $totalWatchesAll = (int)$pdo->query("SELECT COUNT(*) FROM watch_history")->fetchColumn();
    $totalRewardAll  = (float)$pdo->query("SELECT COALESCE(SUM(reward_given), 0) FROM watch_history")->fetchColumn();
    $totalRealLikes  = (int)$pdo->query("SELECT COUNT(*) FROM video_likes")->fetchColumn();
    $totalFakeLikes  = (int)$pdo->query("SELECT COALESCE(SUM(fake_likes), 0) FROM videos")->fetchColumn();
    $totalPublicLikes = (int)$pdo->query("SELECT COALESCE(SUM(total_likes), 0) FROM videos")->fetchColumn();
    $avgLikeRate     = $totalWatchesAll > 0 ? round(($totalRealLikes / $totalWatchesAll) * 100, 1) : 0.0;

    // 1. Top Videos (Real dari watch_history + video_likes breakdown)
    $topVideos = $pdo->query(
        "SELECT v.id, v.title, v.youtube_id, v.total_likes, v.fake_likes,
                COUNT(wh.id) as watch_count,
                COALESCE(SUM(wh.reward_given), 0) as total_reward,
                (SELECT COUNT(*) FROM video_likes vl WHERE vl.video_id = v.id) as real_likes
         FROM videos v
         LEFT JOIN watch_history wh ON wh.video_id = v.id
         GROUP BY v.id
         ORDER BY watch_count DESC, real_likes DESC, v.id DESC LIMIT 50"
    )->fetchAll();

    // 2. Top Viewers (Real dari watch_history + total like yang diberikan)
    $topViewers = $pdo->query(
        "SELECT u.id, u.username, u.email,
                COUNT(wh.id) as watch_count,
                COALESCE(SUM(wh.reward_given), 0) as total_reward,
                (SELECT COUNT(*) FROM video_likes vl WHERE vl.user_id = u.id) as liked_count
         FROM watch_history wh 
         JOIN users u ON u.id = wh.user_id 
         GROUP BY wh.user_id 
         ORDER BY watch_count DESC LIMIT 50"
    )->fetchAll();

    // Ambil detail apa saja yang ditonton oleh top viewers
    foreach ($topViewers as &$tv) {
        $recent = $pdo->prepare(
            "SELECT v.title, COUNT(wh.id) as cnt, SUM(wh.reward_given) as rwd 
             FROM watch_history wh JOIN videos v ON v.id = wh.video_id 
             WHERE wh.user_id=? 
             GROUP BY wh.video_id ORDER BY cnt DESC LIMIT 10"
        );
        $recent->execute([$tv['id']]);
        $tv['watched_details'] = $recent->fetchAll();
    }
    unset($tv);

    // 3. Aktivitas Like Real Terbaru (15 Riwayat Suka Terkini)
    $recentLikes = $pdo->query(
        "SELECT vl.id, vl.created_at, u.id as user_id, u.username, u.email, v.id as video_id, v.title, v.youtube_id
         FROM video_likes vl
         JOIN users u ON u.id = vl.user_id
         JOIN videos v ON v.id = vl.video_id
         ORDER BY vl.id DESC LIMIT 15"
    )->fetchAll();

} catch (\Throwable $e) {
    $topVideos = [];
    $topViewers = [];
    $recentLikes = [];
    $totalWatchesAll = $totalRewardAll = $totalRealLikes = $totalFakeLikes = $totalPublicLikes = $avgLikeRate = 0;
    $error = $e->getMessage();
}

$pageTitle  = 'Analisis Video & Like';
$activePage = 'video_analytics';
require __DIR__ . '/partials/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-4">
  <div>
    <h5 class="mb-0 fw-bold">📊 Analisis Video & Interaksi Like</h5>
    <small class="text-secondary">Statistik penayangan, like asli dari pengguna nyata, dan performa video</small>
  </div>
</div>

<?php if (isset($error)): ?>
<div class="alert alert-danger py-2 mb-3" style="border-radius:10px;font-size:13px">Terjadi kesalahan query: <?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<!-- ── SUMMARY STAT CARDS ── -->
<div class="row g-3 mb-4">
  <!-- Card 1: Views -->
  <div class="col-sm-6 col-xl-3">
    <div class="c-card p-3 h-100" style="background:#161922;border:1px solid #232738">
      <div class="d-flex align-items-center justify-content-between mb-2">
        <span class="text-secondary" style="font-size:11.5px;font-weight:700;text-transform:uppercase;letter-spacing:.5px">Total Tayangan</span>
        <div style="width:34px;height:34px;border-radius:10px;background:rgba(99,102,241,0.15);color:#818cf8;display:flex;align-items:center;justify-content:center;font-size:16px">
          <i class="ph-bold ph-eye"></i>
        </div>
      </div>
      <div style="font-size:22px;font-weight:900;color:#fff"><?= number_format($totalWatchesAll) ?>×</div>
      <div style="font-size:11.5px;color:#94a3b8;margin-top:2px">
        Total reward: <span style="color:#4CAF82;font-weight:700"><?= format_rp((float)$totalRewardAll) ?></span>
      </div>
    </div>
  </div>

  <!-- Card 2: Real Likes -->
  <div class="col-sm-6 col-xl-3">
    <div class="c-card p-3 h-100" style="background:#161922;border:1px solid rgba(16,185,129,0.3)">
      <div class="d-flex align-items-center justify-content-between mb-2">
        <span style="font-size:11.5px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#34d399">Real Likes User</span>
        <div style="width:34px;height:34px;border-radius:10px;background:rgba(16,185,129,0.15);color:#34d399;display:flex;align-items:center;justify-content:center;font-size:16px">
          <i class="ph-fill ph-thumbs-up"></i>
        </div>
      </div>
      <div style="font-size:22px;font-weight:900;color:#34d399"><?= number_format($totalRealLikes) ?></div>
      <div style="font-size:11.5px;color:#94a3b8;margin-top:2px">
        Like murni dari akun pengguna aktif
      </div>
    </div>
  </div>

  <!-- Card 3: Fake Likes -->
  <div class="col-sm-6 col-xl-3">
    <div class="c-card p-3 h-100" style="background:#161922;border:1px solid rgba(251,191,36,0.25)">
      <div class="d-flex align-items-center justify-content-between mb-2">
        <span style="font-size:11.5px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#fbbf24">Fake Likes Sistem</span>
        <div style="width:34px;height:34px;border-radius:10px;background:rgba(251,191,36,0.15);color:#fbbf24;display:flex;align-items:center;justify-content:center;font-size:16px">
          <i class="ph-bold ph-robot"></i>
        </div>
      </div>
      <div style="font-size:22px;font-weight:900;color:#fbbf24"><?= number_format($totalFakeLikes) ?></div>
      <div style="font-size:11.5px;color:#94a3b8;margin-top:2px">
        Total tampil: <strong style="color:#38bdf8"><?= number_format($totalPublicLikes) ?> likes</strong>
      </div>
    </div>
  </div>

  <!-- Card 4: Engagement Rate -->
  <div class="col-sm-6 col-xl-3">
    <div class="c-card p-3 h-100" style="background:#161922;border:1px solid #232738">
      <div class="d-flex align-items-center justify-content-between mb-2">
        <span class="text-secondary" style="font-size:11.5px;font-weight:700;text-transform:uppercase;letter-spacing:.5px">Rasio Suka (Real Rate)</span>
        <div style="width:34px;height:34px;border-radius:10px;background:rgba(56,189,248,0.15);color:#38bdf8;display:flex;align-items:center;justify-content:center;font-size:16px">
          <i class="ph-bold ph-chart-line-up"></i>
        </div>
      </div>
      <div style="font-size:22px;font-weight:900;color:#38bdf8"><?= number_format($avgLikeRate, 1) ?>%</div>
      <div style="font-size:11.5px;color:#94a3b8;margin-top:2px">
        Persentase penonton yang memberi like asli
      </div>
    </div>
  </div>
</div>

<div class="row g-4 mb-4">
  <!-- Top Videos & Likes Breakdown -->
  <div class="col-lg-6">
    <div class="c-card h-100">
      <div class="c-card-header d-flex justify-content-between align-items-center">
        <span class="c-card-title">🏆 Top Video & Performa Like</span>
        <span class="badge" style="background:rgba(255,255,255,0.06);color:#94a3b8;font-size:11px"><?= count($topVideos) ?> Video</span>
      </div>
      <div class="c-card-body" style="padding:0; overflow-y:auto; max-height:800px;">
        <?php foreach ($topVideos as $i => $v): 
          $wCount = (int)$v['watch_count'];
          $rLikes = (int)$v['real_likes'];
          $fLikes = (int)$v['fake_likes'];
          $tLikes = (int)$v['total_likes'];
          $likeRate = $wCount > 0 ? round(($rLikes / $wCount) * 100, 1) : 0;
        ?>
        <div style="padding:14px 18px; border-bottom:1px solid #1a1d27; display:flex; align-items:flex-start; gap:12px">
          <div style="width:28px;height:28px;border-radius:50%;background:<?= $i===0?'rgba(251,188,4,.2)':($i===1?'rgba(192,192,192,.2)':($i===2?'rgba(205,127,50,.2)':'rgba(255,255,255,.05)')) ?>;color:<?= $i===0?'#FBBC04':($i===1?'#e2e8f0':($i===2?'#f97316':'#888')) ?>;display:flex;align-items:center;justify-content:center;font-weight:900;font-size:12px;flex-shrink:0;margin-top:4px">
            <?= $i + 1 ?>
          </div>
          
          <img src="https://img.youtube.com/vi/<?= htmlspecialchars($v['youtube_id']) ?>/default.jpg" style="width:64px;height:38px;object-fit:cover;border-radius:6px;flex-shrink:0" onerror="this.style.display='none'">

          <div style="flex:1;min-width:0">
            <div style="font-size:13px;font-weight:700;color:#e0e0f0;margin-bottom:3px;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden">
              <?= htmlspecialchars($v['title']) ?>
            </div>
            
            <div class="d-flex flex-wrap align-items-center gap-2 mb-2" style="font-size:11.5px">
              <span style="color:#818cf8;font-weight:700"><i class="ph-bold ph-eye"></i> <?= number_format($wCount) ?>× ditonton</span>
              <span class="text-secondary">•</span>
              <span style="color:#4CAF82;font-weight:700"><?= format_rp((float)$v['total_reward']) ?></span>
            </div>

            <!-- Like Pill Breakdown -->
            <div class="d-flex flex-wrap align-items-center gap-1" style="font-size:10.5px">
              <span class="badge" style="background:rgba(56,189,248,0.15);color:#38bdf8;font-weight:800">
                👍 <?= number_format($tLikes) ?> total
              </span>
              <span class="badge" style="background:rgba(16,185,129,0.15);color:#34d399;font-weight:800" title="Like asli dari user aktif">
                👤 <?= number_format($rLikes) ?> real
              </span>
              <span class="badge" style="background:rgba(251,191,36,0.15);color:#fbbf24;font-weight:800" title="Like bawaan sistem">
                🤖 <?= number_format($fLikes) ?> fake
              </span>
              <?php if ($wCount > 0): ?>
              <span class="badge" style="background:rgba(255,255,255,0.06);color:#94a3b8" title="Rasio like real per tontonan">
                📈 <?= $likeRate ?>% rate
              </span>
              <?php endif; ?>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
        <?php if (empty($topVideos)): ?>
        <div style="padding:40px;text-align:center;color:#666">Belum ada data video tersimpan.</div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Top Viewers (User Paling Banyak Menonton & Suka) -->
  <div class="col-lg-6">
    <div class="c-card h-100">
      <div class="c-card-header d-flex justify-content-between align-items-center">
        <span class="c-card-title">👀 Top Viewers & Keaktifan Like</span>
        <span class="badge" style="background:rgba(255,255,255,0.06);color:#94a3b8;font-size:11px"><?= count($topViewers) ?> User</span>
      </div>
      <div class="c-card-body" style="padding:0; overflow-y:auto; max-height:800px;">
        <?php foreach ($topViewers as $i => $u): ?>
        <div style="padding:14px 18px; border-bottom:1px solid #1a1d27;">
          <div style="display:flex; align-items:center; gap:12px; margin-bottom:10px">
            <div style="width:36px;height:36px;border-radius:10px;background:var(--brand);display:flex;align-items:center;justify-content:center;color:#fff;font-weight:800;font-size:15px;flex-shrink:0">
              <?= strtoupper(substr($u['username'],0,1)) ?>
            </div>
            <div style="flex:1;min-width:0">
              <div style="font-size:13.5px;font-weight:700;color:#fff"><?= htmlspecialchars($u['username']) ?></div>
              <div style="font-size:11px;color:#888"><?= htmlspecialchars($u['email']) ?></div>
            </div>
            <div style="text-align:right">
              <div style="font-size:13px;font-weight:800;color:var(--brand)"><?= number_format((int)$u['watch_count']) ?>x Nonton</div>
              <div style="font-size:11px;font-weight:700;color:#4CAF82"><?= format_rp((float)$u['total_reward']) ?></div>
            </div>
            <a href="/console/user_detail.php?id=<?= $u['id'] ?>" class="btn btn-sm" style="background:rgba(255,255,255,.05);color:#ccc;border:1px solid rgba(255,255,255,.1);border-radius:8px;font-size:11px;margin-left:6px">Detail</a>
          </div>

          <!-- Like Stats & Video Sering Ditonton -->
          <div class="d-flex align-items-center gap-2 mb-2">
            <span class="badge" style="background:rgba(16,185,129,0.15);color:#34d399;font-size:11px;font-weight:800">
              👍 <?= number_format((int)$u['liked_count']) ?> Video Disukai
            </span>
          </div>
          
          <!-- Detail Video Yg Ditonton -->
          <div style="background:rgba(0,0,0,.2);border-radius:8px;padding:8px 10px;border:1px solid #1f2235">
            <div style="font-size:10.5px;color:#888;margin-bottom:4px;font-weight:700;text-transform:uppercase;letter-spacing:.5px">Video Paling Sering Ditonton:</div>
            <?php foreach ($u['watched_details'] as $wd): ?>
            <div style="display:flex;justify-content:space-between;align-items:center;padding:3px 0;border-bottom:1px solid rgba(255,255,255,.03)">
              <div style="font-size:11.5px;color:#ccc;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;flex:1;padding-right:10px">
                <?= htmlspecialchars($wd['title']) ?>
              </div>
              <div style="font-size:11px;color:#aaa;flex-shrink:0;text-align:right">
                <?= $wd['cnt'] ?>x <span style="color:#4CAF82;margin-left:6px"><?= format_rp((float)$wd['rwd']) ?></span>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endforeach; ?>
        <?php if (empty($topViewers)): ?><div style="padding:30px;text-align:center;color:#666">Belum ada user yang menonton.</div><?php endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- ── RECENT REAL LIKES FEED ── -->
<div class="c-card">
  <div class="c-card-header d-flex justify-content-between align-items-center">
    <span class="c-card-title">❤️ Riwayat Like Pengguna Nyata Terbaru</span>
    <span class="badge" style="background:rgba(16,185,129,0.15);color:#34d399;font-size:11px">15 Terakhir</span>
  </div>
  <div style="overflow-x:auto">
    <table class="c-table">
      <thead>
        <tr>
          <th>Waktu</th>
          <th>Pengguna</th>
          <th>Video yang Disukai</th>
          <th>Aksi</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($recentLikes as $rl): ?>
        <tr>
          <td style="color:#94a3b8;font-size:12px;white-space:nowrap">
            <?= date('d M Y, H:i', strtotime($rl['created_at'])) ?>
          </td>
          <td>
            <div style="font-weight:700;color:#fff;font-size:13px"><?= htmlspecialchars($rl['username']) ?></div>
            <div style="font-size:11px;color:#64748b"><?= htmlspecialchars($rl['email']) ?></div>
          </td>
          <td>
            <div class="d-flex align-items-center gap-2">
              <img src="https://img.youtube.com/vi/<?= htmlspecialchars($rl['youtube_id']) ?>/default.jpg" style="width:48px;height:28px;object-fit:cover;border-radius:4px" onerror="this.style.display='none'">
              <div style="font-size:12.5px;color:#e2e8f0;font-weight:600;max-width:320px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                <?= htmlspecialchars($rl['title']) ?>
              </div>
            </div>
          </td>
          <td>
            <a href="/console/user_detail.php?id=<?= $rl['user_id'] ?>" class="btn btn-sm" style="background:rgba(255,255,255,.05);color:#ccc;border:1px solid rgba(255,255,255,.1);border-radius:6px;font-size:11px">
              Profil User
            </a>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($recentLikes)): ?>
        <tr>
          <td colspan="4" style="text-align:center;padding:30px;color:#64748b">
            Belum ada pengguna yang memberi like pada video.
          </td>
        </tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>
