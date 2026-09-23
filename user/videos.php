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

// All active videos with watch status for today
$videos = $pdo->prepare(
    "SELECT v.*,
       (SELECT COUNT(*) FROM watch_history wh
        WHERE wh.user_id=? AND wh.video_id=v.id AND DATE(wh.watched_at)=CURDATE()) AS watched_today
     FROM videos v
     WHERE v.is_active=1
     ORDER BY {$order_by}
     LIMIT {$limit} OFFSET {$offset}"
);
$videos->execute([$user['id']]);
$videos = $videos->fetchAll();

if (isset($_GET['ajax'])) {
    if (empty($videos)) {
        echo '';
        exit;
    }
    foreach ($videos as $v) {
        $done    = (bool)$v['watched_today'];
        $blocked = !$done && ($watch_today >= $watch_limit);
        $href    = ($done || $blocked) ? 'javascript:void(0)' : '/watch?id='.$v['id'];
        ?>
        <a href="<?= $href ?>" class="vcard <?= $done ? 'vcard--done' : '' ?>" <?= ($done||$blocked) ? 'style="pointer-events:none"' : '' ?>>
          <div class="vcard__thumb-wrapper">
            <img src="<?= yt_thumb($v['youtube_id']) ?>" alt="<?= htmlspecialchars($v['title']) ?>" loading="lazy" onerror="this.src='https://img.youtube.com/vi/<?= $v['youtube_id'] ?>/hqdefault.jpg'">
            <div class="vcard__play">
              <?php if ($done): ?>
                <i class="ph-fill ph-check-circle" style="color:#10b981; filter: drop-shadow(0 4px 0 #047857); font-size:42px;"></i>
              <?php else: ?>
                <div class="vcard__play-btn"><i class="ph-fill ph-play"></i></div>
              <?php endif; ?>
            </div>
            <div class="vcard__badge <?= $done ? 'vcard__badge--done' : '' ?>">
              <?= $done ? '✓ Selesai' : '+'.format_rp((float)$v['reward_amount']) ?>
            </div>
          </div>
          <div class="vcard__info">
            <div class="vcard__title"><?= htmlspecialchars($v['title']) ?></div>
            <div class="vcard__meta">
              <span class="vcard__reward <?= $done ? 'vcard__reward--done' : '' ?>">
                <?php if ($done): ?>
                  <i class="ph-bold ph-check"></i> Selesai
                <?php else: ?>
                  <i class="ph-bold ph-coins" style="color:#eab308; font-size:14px"></i> <?= format_rp((float)$v['reward_amount']) ?>
                <?php endif; ?>
              </span>
              <span class="vcard__duration"><i class="ph-bold ph-clock"></i> <?= $v['watch_duration'] ?>s</span>
            </div>
          </div>
        </a>
        <?php
    }
    exit;
}

$pageTitle  = 'Tonton Video  ';
$activePage = 'videos';
require dirname(__DIR__) . '/partials/header.php';
?>

<style>
  body { 
    background-color: #fef8ee !important; 
    background-image: radial-gradient(rgba(217, 119, 6, 0.08) 1.5px, transparent 1.5px) !important;
    background-size: 16px 16px !important;
  }
</style>

<div style="padding: 16px 14px 100px;">

<!-- Header Mascot Banner -->
<div class="cg-card" style="background: linear-gradient(135deg, #fffbeb 0%, #fef3c7 100%); border: 3px solid #78350f; border-radius: 20px; box-shadow: 0 6px 0 #78350f; padding: 16px; margin-bottom: 14px; margin-top: 4px; display: flex; align-items: center; gap: 14px; position: relative; overflow: hidden;">
  <!-- Decorative Honeycomb Pattern -->
  <div style="position: absolute; right: -15px; top: -15px; opacity: 0.08; font-size: 100px; pointer-events: none; line-height: 1;">🍯</div>
  
  <div style="width: 58px; height: 58px; background: #fde68a; border: 3px solid #78350f; border-radius: 18px; box-shadow: 0 4px 0 #78350f; display: flex; align-items: center; justify-content: center; flex-shrink: 0; position: relative;">
    <img src="/assets/game/bee_worker.png" alt="Buzzy" style="width: 48px; height: 48px; object-fit: contain; animation: buzzyFloat 2.5s ease-in-out infinite;">
  </div>
  <div style="flex: 1; z-index: 1;">
    <div style="display: inline-flex; align-items: center; gap: 5px; background: #f59e0b; color: #78350f; font-size: 10px; font-weight: 900; padding: 2px 8px; border-radius: 20px; border: 1.5px solid #78350f; margin-bottom: 4px; box-shadow: 0 2px 0 #78350f;">
      <i class="ph-bold ph-sparkle"></i> MISI UTAMA
    </div>
    <h1 style="font-size: 18px; font-weight: 900; color: #78350f; margin: 0; line-height: 1.2;">Tonton & Panen Cuan</h1>
    <p style="font-size: 11px; font-weight: 800; color: #92400e; margin: 2px 0 0;">Tonton video pilihan hingga timer selesai untuk klaim saldo rupiahmu!</p>
  </div>
</div>

<!-- Progress Bar Harian -->
<div class="cg-card" style="background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); border: 3px solid #78350f; border-radius: 20px; box-shadow: 0 6px 0 #78350f; padding: 16px; margin-bottom: 18px; color: #fff; position: relative;">
  <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:10px;">
    <span style="font-size:13px; font-weight:900; display:flex; align-items:center; gap:6px; color:#fff; text-shadow:0 1px 2px rgba(120,53,15,0.4);">
      <i class="ph-fill ph-chart-pie-slice" style="color:#fef08a; font-size:20px;"></i> Progres Tonton Hari Ini
    </span>
    <span style="font-size:12px; font-weight:900; background:#78350f; color:#fde68a; border:2px solid #fff; padding:3px 10px; border-radius:12px; box-shadow: 0 3px 0 rgba(0,0,0,0.2);">
      <?= $watch_today ?> / <?= $watch_limit ?> Video
    </span>
  </div>
  
  <div style="background:#78350f; border-radius:20px; height:15px; overflow:hidden; border:2px solid #78350f; box-shadow:inset 0 2px 4px rgba(0,0,0,0.4); padding: 1.5px;">
    <?php $pct = $watch_limit > 0 ? min(100, round(($watch_today / $watch_limit) * 100)) : 0; ?>
    <div style="background: <?= $pct >= 100 ? '#10b981' : 'linear-gradient(90deg, #fde68a, #f59e0b)' ?>; height: 100%; width: <?= $pct ?>%; border-radius: 20px; transition: width .5s cubic-bezier(0.4, 0, 0.2, 1); box-shadow: inset 0 -2px 0 rgba(0,0,0,0.15);"></div>
  </div>

  <div style="display:flex; justify-content:space-between; align-items:center; margin-top:8px; font-size:10.5px; font-weight:800; color:#fef3c7;">
    <span>⚡ Reset tiap pukul 00:00 WIB</span>
    <span><?= $pct ?>% Tercapai</span>
  </div>

  <?php if ($watch_today >= $watch_limit): ?>
  <div style="font-size:11.5px; color:#fff; margin-top:12px; font-weight:900; background:#dc2626; padding:10px 12px; border-radius:14px; border:2.5px solid #78350f; box-shadow: 0 4px 0 #78350f; display:flex; align-items:center; justify-content:space-between;">
    <div style="display:flex; align-items:center; gap:6px;"><i class="ph-bold ph-warning-circle" style="font-size:18px;"></i> Kuota harian habis!</div>
    <a href="/upgrade" style="color:#78350f; font-weight:900; text-decoration:none; background:#fde68a; padding:4px 10px; border-radius:10px; border:1.5px solid #78350f; box-shadow:0 2px 0 #78350f;">Upgrade VIP →</a>
  </div>
  <?php endif; ?>
</div>

<!-- Sidejob Banner Cue -->
<div style="background: #fff; border: 2.5px solid #78350f; border-radius: 16px; box-shadow: 0 4px 0 #78350f; padding: 10px 14px; margin-bottom: 18px; display: flex; align-items: center; justify-content: space-between; gap: 10px;">
  <div style="display: flex; align-items: center; gap: 10px;">
    <img src="/assets/game/honey_jar.png" alt="Honey" style="width: 32px; height: 32px; object-fit: contain;">
    <div>
      <div style="font-size: 11.5px; font-weight: 900; color: #78350f;">Pekerjaan Sampingan (Sidejob)</div>
      <div style="font-size: 10px; font-weight: 700; color: #92400e;">Lebahmu terus panen madu di kandang!</div>
    </div>
  </div>
  <a href="/farm" style="background: #f59e0b; color: #78350f; border: 2px solid #78350f; border-radius: 10px; font-size: 10.5px; font-weight: 900; padding: 6px 12px; text-decoration: none; box-shadow: 0 3px 0 #78350f; white-space: nowrap;">
    Buka Kebun Lebah 🐝
  </a>
</div>

<?php if (empty($videos)): ?>
<div style="background:#fff; border:3px solid #78350f; border-radius:20px; padding:32px 16px; text-align:center; box-shadow:0 6px 0 #78350f; margin-bottom:16px">
  <div style="width:68px; height:68px; background:#fef3c7; border:3px solid #78350f; box-shadow:0 4px 0 #78350f; border-radius:20px; display:flex; align-items:center; justify-content:center; margin:0 auto 16px; color:#d97706; font-size:34px;">
    <i class="ph-fill ph-video-camera-slash"></i>
  </div>
  <h3 style="font-size:16px; font-weight:900; color:#78350f; margin:0 0 6px;">Belum Ada Video Baru</h3>
  <p style="font-size:12px; font-weight:700; color:#92400e; margin:0">Video misi baru akan segera di-upload oleh pengiklan. Pantau terus ya!</p>
</div>
<?php else: ?>

<style>
/* Casual Game Grid & Cards — Amber Honey Theme */
@keyframes buzzyFloat {
  0%, 100% { transform: translateY(0px) rotate(0deg); }
  50% { transform: translateY(-4px) rotate(3deg); }
}

.vgrid { 
    display: grid; 
    grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); 
    gap: 14px; 
    margin-bottom: 24px; 
}
.vcard { 
    text-decoration: none; 
    display: block; 
    background: #fff; 
    border: 3px solid #78350f; 
    border-radius: 20px; 
    box-shadow: 0 5px 0 #78350f; 
    transition: transform 0.12s, box-shadow 0.12s; 
    position: relative;
    padding: 8px;
}
.vcard:hover { transform: translateY(-2px); box-shadow: 0 7px 0 #78350f; }
.vcard:active { transform: translateY(3px); box-shadow: 0 2px 0 #78350f; }
.vcard--done { opacity: 0.65; filter: grayscale(30%); box-shadow: 0 4px 0 #92400e; border-color: #92400e; background: #fafaf9; }
.vcard--done:active { box-shadow: 0 4px 0 #92400e; transform: none; }

.vcard__thumb-wrapper {
    position: relative; 
    aspect-ratio: 16/9; 
    background: #1c1917; 
    border-radius: 12px; 
    border: 2px solid #78350f;
    overflow: hidden;
    margin-bottom: 8px;
}
.vcard__thumb-wrapper img { 
    width: 100%; height: 100%; object-fit: cover; opacity: 0.92; transition: transform 0.3s ease;
}
.vcard:hover .vcard__thumb-wrapper img { transform: scale(1.06); }

.vcard__badge { 
    position: absolute; 
    top: 6px; right: 6px; 
    background: linear-gradient(135deg, #f59e0b, #d97706); 
    color: #fff; font-size: 10px; font-weight: 900; 
    padding: 3px 8px; border-radius: 12px; 
    border: 2px solid #78350f; 
    box-shadow: 0 3px 0 #78350f; 
    z-index: 2;
    text-shadow: 0 1px 1px rgba(0,0,0,0.3);
}
.vcard__badge--done { 
    background: #10b981; 
    border-color: #065f46; 
    box-shadow: 0 3px 0 #065f46; 
}

.vcard__play { 
    position: absolute; inset: 0; 
    display: flex; align-items: center; justify-content: center; 
    background: rgba(120, 53, 15, 0.25); 
    opacity: 0; transition: opacity 0.2s; z-index: 1;
}
.vcard:hover .vcard__play { opacity: 1; }
.vcard--done:hover .vcard__play { opacity: 1; background: transparent; }

.vcard__play-btn {
    width: 42px; height: 42px;
    background: linear-gradient(135deg, #f59e0b, #d97706);
    border: 3px solid #78350f;
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    color: #fff; font-size: 18px;
    box-shadow: 0 4px 0 #78350f;
    transform: scale(0.85); transition: transform 0.2s cubic-bezier(0.34, 1.56, 0.64, 1);
    padding-left: 3px;
}
.vcard:hover .vcard__play-btn { transform: scale(1.05); }

.vcard__info { padding: 0 4px; }
.vcard__title { 
    font-size: 12px; font-weight: 900; color: #78350f; 
    display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; 
    overflow: hidden; line-height: 1.35; height: 32px; 
}
.vcard__meta { 
    display: flex; align-items: center; justify-content: space-between; 
    font-size: 11px; font-weight: 900; color: #92400e; 
    margin-top: 6px; padding-top: 6px; border-top: 2px dashed #fde68a;
}
.vcard__reward { color: #d97706; display: flex; align-items: center; gap: 4px; font-weight: 900; }
.vcard__reward--done { color: #10b981; }
.vcard__duration { display: flex; align-items: center; gap: 3px; background: #fef3c7; border: 1px solid #fde68a; padding: 2px 6px; border-radius: 8px; color: #78350f; font-size: 10px; }
</style>

<div class="vgrid" id="vgrid">
<?php foreach ($videos as $v):
  $done    = (bool)$v['watched_today'];
  $blocked = !$done && ($watch_today >= $watch_limit);
  $href    = ($done || $blocked) ? 'javascript:void(0)' : '/watch?id='.$v['id'];
?>
<a href="<?= $href ?>" class="vcard <?= $done ? 'vcard--done' : '' ?>" <?= ($done||$blocked) ? 'style="pointer-events:none"' : '' ?>>
  <div class="vcard__thumb-wrapper">
    <img src="<?= yt_thumb($v['youtube_id']) ?>" alt="<?= htmlspecialchars($v['title']) ?>" loading="lazy" onerror="this.src='https://img.youtube.com/vi/<?= $v['youtube_id'] ?>/hqdefault.jpg'">
    <div class="vcard__play">
      <?php if ($done): ?>
        <i class="ph-fill ph-check-circle" style="color:#10b981; filter: drop-shadow(0 4px 0 #047857); font-size:42px;"></i>
      <?php else: ?>
        <div class="vcard__play-btn"><i class="ph-fill ph-play"></i></div>
      <?php endif; ?>
    </div>
    <div class="vcard__badge <?= $done ? 'vcard__badge--done' : '' ?>">
      <?= $done ? '✓ Selesai' : '+'.format_rp((float)$v['reward_amount']) ?>
    </div>
  </div>
  <div class="vcard__info">
    <div class="vcard__title"><?= htmlspecialchars($v['title']) ?></div>
    <div class="vcard__meta">
      <span class="vcard__reward <?= $done ? 'vcard__reward--done' : '' ?>">
        <?php if ($done): ?>
          <i class="ph-bold ph-check"></i> Selesai
        <?php else: ?>
          <i class="ph-bold ph-coins" style="color:#eab308; font-size:14px"></i> <?= format_rp((float)$v['reward_amount']) ?>
        <?php endif; ?>
      </span>
      <span class="vcard__duration"><i class="ph-bold ph-clock"></i> <?= $v['watch_duration'] ?>s</span>
    </div>
  </div>
</a>
<?php endforeach; ?>
</div>

<div id="loader" style="text-align:center;padding:20px;display:none">
  <div style="background:#fde68a; width:48px; height:48px; border-radius:50%; border:2.5px solid #78350f; display:flex; align-items:center; justify-content:center; margin:0 auto; box-shadow:0 4px 0 #78350f;">
    <i class="ph-bold ph-spinner ph-spin" style="font-size:24px;color:#78350f"></i>
  </div>
  <div style="font-size:12px;font-weight:800;color:#78350f;margin-top:12px">Memuat video...</div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
  let page = 1;
  let isLoading = false;
  let hasMore = <?= count($videos) === $limit ? 'true' : 'false' ?>;
  const grid = document.getElementById('vgrid');
  const loader = document.getElementById('loader');

  if (!hasMore) return; // No more pages to load initially

  const observer = new IntersectionObserver((entries) => {
    if (entries[0].isIntersecting && !isLoading && hasMore) {
      loadMore();
    }
  }, { rootMargin: '100px' });

  // Create a sentinel element to observe
  const sentinel = document.createElement('div');
  sentinel.style.height = '1px';
  grid.parentNode.insertBefore(sentinel, grid.nextSibling);
  observer.observe(sentinel);

  async function loadMore() {
    isLoading = true;
    loader.style.display = 'block';
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
      page--; // revert page count on error
    } finally {
      isLoading = false;
      loader.style.display = 'none';
    }
  }
});
</script>
<?php endif; ?>

<?php if (!empty($flash)): ?>
<script>
document.addEventListener('DOMContentLoaded', () => {
    if (typeof nToast !== 'undefined') {
        nToast('<?= addslashes($flash) ?>', '<?= $flash_type ?>');
    }
});
</script>
<?php endif; ?>

</div>

<?php require dirname(__DIR__) . '/partials/footer.php'; ?>
