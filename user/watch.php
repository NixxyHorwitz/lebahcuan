<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/bootstrap.php';
$user = require_auth($pdo);

$vid_id = (int)($_GET['id'] ?? 0);
if (!$vid_id) redirect('/videos');

$vs = $pdo->prepare("SELECT * FROM videos WHERE id=? AND is_active=1");
$vs->execute([$vid_id]);
$video = $vs->fetch();
if (!$video) redirect('/videos');

$watch_limit = user_watch_limit($pdo, $user);
$watch_today = user_watch_today($pdo, $user);

$chk = $pdo->prepare("SELECT id FROM watch_history WHERE user_id=? AND video_id=? AND DATE(watched_at)=CURDATE()");
$chk->execute([$user['id'], $vid_id]);
$already_watched = (bool)$chk->fetch();
$canWatch = !$already_watched && $watch_today < $watch_limit;

// ─────────────────────────────────────────────────────────────
// AJAX: start_watch — server issues a signed token with timestamp
// Client harus call ini dulu sebelum bisa claim.
// ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'start_watch') {
    header('Content-Type: application/json');
    if (!csrf_verify()) { echo json_encode(['ok'=>false,'msg'=>'Invalid CSRF']); exit; }
    if (!$canWatch)     { echo json_encode(['ok'=>false,'msg'=>'Tidak bisa menonton.']); exit; }

    $ts    = time();
    $secret = $_ENV['APP_SECRET'] ?? 'tonton_secret_2024';
    $sig   = hash_hmac('sha256', $user['id'] . '|' . $vid_id . '|' . $ts, $secret);
    $token = base64_encode($user['id'] . '|' . $vid_id . '|' . $ts . '|' . $sig);

    echo json_encode(['ok'=>true,'watch_token'=>$token]);
    exit;
}

// ─────────────────────────────────────────────────────────────
// AJAX: claim reward — wajib sertakan watch_token
// Server validasi: signature benar + waktu sudah cukup
// ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'claim') {
    header('Content-Type: application/json');
    if (!csrf_verify()) { echo json_encode(['ok'=>false,'msg'=>'Invalid CSRF']); exit; }

    // Verifikasi watch_token
    $raw_token = $_POST['watch_token'] ?? '';
    if (empty($raw_token)) {
        echo json_encode(['ok'=>false,'msg'=>'Token tidak valid. Tonton video dari awal.']); exit;
    }

    $decoded = base64_decode($raw_token, true);
    if ($decoded === false || substr_count($decoded, '|') < 3) {
        echo json_encode(['ok'=>false,'msg'=>'Token rusak.']); exit;
    }

    [$tok_uid, $tok_vid, $tok_ts, $tok_sig] = explode('|', $decoded, 4);
    $secret  = $_ENV['APP_SECRET'] ?? 'tonton_secret_2024';
    $expected= hash_hmac('sha256', $tok_uid . '|' . $tok_vid . '|' . $tok_ts, $secret);

    // Validasi identitas
    if ((int)$tok_uid !== (int)$user['id'] || (int)$tok_vid !== $vid_id) {
        echo json_encode(['ok'=>false,'msg'=>'Token tidak cocok.']); exit;
    }
    // Validasi signature
    if (!hash_equals($expected, $tok_sig)) {
        echo json_encode(['ok'=>false,'msg'=>'Signature tidak valid.']); exit;
    }
    // Validasi waktu — harus sudah tonton minimal watch_duration detik
    $elapsed = time() - (int)$tok_ts;
    $required = (int)$video['watch_duration'];
    if ($elapsed < $required) {
        $kurang = $required - $elapsed;
        echo json_encode(['ok'=>false,'msg'=>"Belum cukup waktu. Tunggu {$kurang} detik lagi."]); exit;
    }
    // Token tidak boleh terlalu lama (maks 3× durasi, untuk toleransi pause)
    if ($elapsed > $required * 4 + 300) {
        echo json_encode(['ok'=>false,'msg'=>'Token sudah kedaluwarsa. Refresh dan coba lagi.']); exit;
    }

    $reward = (float)$video['reward_amount'];
    try {
        $pdo->beginTransaction();
        
        // Lock baris user untuk mencegah race condition (concurrent claims)
        $pdo->prepare("SELECT id FROM users WHERE id=? FOR UPDATE")->execute([$user['id']]);
        
        // Cek lagi setelah dilock (atomic)
        $chk2 = $pdo->prepare("SELECT id FROM watch_history WHERE user_id=? AND video_id=? AND DATE(watched_at)=CURDATE()");
        $chk2->execute([$user['id'], $vid_id]);
        if ($chk2->fetch()) { 
            $pdo->rollBack();
            echo json_encode(['ok'=>false,'msg'=>'Sudah ditonton hari ini.']); exit; 
        }

        $wt = $pdo->prepare("SELECT COUNT(*) FROM watch_history WHERE user_id=? AND DATE(watched_at)=CURDATE()");
        $wt->execute([$user['id']]);
        if ((int)$wt->fetchColumn() >= $watch_limit) {
            $pdo->rollBack();
            echo json_encode(['ok'=>false,'msg'=>'Limit tonton habis!']); exit;
        }

        $pdo->prepare("INSERT INTO watch_history (user_id,video_id,reward_given) VALUES (?,?,?)")
            ->execute([$user['id'], $vid_id, $reward]);
        $pdo->prepare("UPDATE users SET balance_wd=balance_wd+?,total_earned=total_earned+? WHERE id=?")
            ->execute([$reward, $reward, $user['id']]);
        $pdo->prepare("UPDATE videos SET total_watches=total_watches+1 WHERE id=?")
            ->execute([$vid_id]);
        $pdo->commit();
        $_SESSION['flash_videos_msg'] = '🎉 Reward ' . format_rp($reward) . ' berhasil diklaim!';
        $_SESSION['flash_videos_type'] = 'success';
        echo json_encode(['ok'=>true,'reward'=>format_rp($reward),'msg'=>'+'.format_rp($reward).' berhasil!']);
    } catch (\Throwable) {
        $pdo->rollBack();
        echo json_encode(['ok'=>false,'msg'=>'Terjadi kesalahan server.']);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= htmlspecialchars($video['title']) ?>  </title>
<link rel="stylesheet" href="/assets/css/app.css?v=<?= @filemtime($_SERVER['DOCUMENT_ROOT'].'/assets/css/app.css') ?: time() ?>">
<style>
/* ── Watch page overrides — Amber Honey Theme ── */
body {
  background-color: #fef8ee !important;
  background-image: radial-gradient(rgba(217, 119, 6, 0.08) 1.5px, transparent 1.5px) !important;
  background-size: 16px 16px !important;
}
.watch-topbar {
  position:sticky; top:0; z-index:100;
  background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
  border-bottom: 3px solid #78350f;
  padding: 0 16px; height: 54px;
  display: flex; align-items: center; justify-content: space-between; gap: 12px;
  box-shadow: 0 3px 0 #78350f;
}
.back-btn {
  display: flex; align-items: center; gap: 6px;
  color: #78350f; background: #fde68a; border: 2px solid #78350f;
  border-radius: 12px; padding: 5px 12px;
  text-decoration: none; font-weight: 900; font-size: 13px;
  box-shadow: 0 2px 0 #78350f;
  transition: transform 0.1s;
}
.back-btn:active { transform: translateY(2px); box-shadow: none; }

.watch-topbar__bal {
  background: #78350f; color: #fde68a; border: 2px solid #fff;
  border-radius: 20px; padding: 4px 12px; font-size: 12px; font-weight: 900;
  display: inline-flex; align-items: center; gap: 5px;
  box-shadow: 0 2px 0 rgba(0,0,0,0.2);
}

/* Clean video wrapper */
.yt-wrapper {
  position: relative;
  background: #000;
  aspect-ratio: 16/9;
  width: 100%;
}
.yt-wrapper iframe {
  position: absolute; inset: 0;
  width: 100%; height: 100%;
  border: none;
}

/* Timer bar */
.watch-progress-bar {
  height: 8px;
  background: #fde68a;
  border-bottom: 2.5px solid #78350f;
  overflow: hidden;
}
.watch-progress-fill {
  height: 100%; width: 0%;
  background: linear-gradient(90deg, #f59e0b, #d97706);
  transition: width 1s linear;
}
.watch-progress-fill.done { background: #10b981; }

.watch-status {
  background: #fffbeb;
  border-bottom: 2.5px solid #78350f;
  padding: 12px 16px;
  display: flex; align-items: center; justify-content: space-between; gap: 10px;
  font-size: 13px; font-weight: 800;
}
.watch-status__timer {
  display: flex; align-items: center; gap: 10px;
}
.timer-badge {
  width: 42px; height: 42px;
  border-radius: 50%;
  border: 2.5px solid #78350f;
  box-shadow: 0 3px 0 #78350f;
  display: flex; align-items: center; justify-content: center;
  font-size: 14px; font-weight: 900;
  background: #fde68a;
  color: #78350f;
  flex-shrink: 0;
}
.watch-status__hint { color: #92400e; font-size: 11.5px; font-weight: 700; margin-top: 1px; }

/* ── Page loader ── */
#page-loader{
  position: fixed; inset: 0; z-index: 9999;
  background: #fef8ee;
  display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 16px;
  transition: opacity .35s;
}
#page-loader.hidden{ opacity: 0; pointer-events: none; }
.loader-spinner{
  width: 52px; height: 52px;
  border: 5px solid #fde68a;
  border-top-color: #d97706;
  border-radius: 50%;
  animation: spin .7s linear infinite;
}
@keyframes spin{to{transform:rotate(360deg)}}
.loader-label{ font-size: 13px; font-weight: 900; color: #78350f; }
</style>
</head>
<body>
<!-- Page loader -->
<div id="page-loader">
  <div style="width:64px;height:64px;background:#fde68a;border:3px solid #78350f;border-radius:20px;display:flex;align-items:center;justify-content:center;box-shadow:0 4px 0 #78350f;margin-bottom:8px;">
    <img src="/assets/game/bee_worker.png" alt="Buzzy" style="width:48px;height:48px;object-fit:contain;">
  </div>
  <div class="loader-spinner"></div>
  <div class="loader-label"><i class="ph-bold ph-hourglass-high" style="color:#d97706;font-size:16px;vertical-align:middle"></i> Memuat video misi...</div>
</div>
<div class="app-shell">

  <!-- Topbar -->
  <div class="watch-topbar">
    <a href="/videos" class="back-btn">
      <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"><polyline points="15 18 9 12 15 6"/></svg>
      Misi Video
    </a>
    <div class="watch-topbar__bal" title="Saldo Siap Tarik">
      <i class="ph-fill ph-wallet"></i> <?= format_rp((float)$user['balance_wd']) ?>
    </div>
  </div>

  <!-- Player -->
  <div class="yt-wrapper">
    <div id="yt-player"></div>
  </div>

  <!-- Progress bar di bawah video -->
  <div class="watch-progress-bar">
    <div class="watch-progress-fill" id="prog-fill"></div>
  </div>

  <!-- Status bar -->
  <div class="watch-status" id="status-bar">
    <div class="watch-status__timer">
      <div class="timer-badge" id="timer-badge">
        <?php if ($canWatch): ?>
          <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
        <?php else: ?>–<?php endif; ?>
      </div>
      <div>
        <div id="status-text" style="color: #78350f; font-weight: 900;">
          <?php if ($already_watched): ?><i class="ph-bold ph-check-circle" style="color:#10b981"></i> Sudah ditonton hari ini
          <?php elseif ($watch_today >= $watch_limit): ?><i class="ph-bold ph-warning-circle" style="color:#dc2626"></i> Limit tonton habis
          <?php else: ?><i class="ph-bold ph-play-circle" style="color:#d97706"></i> Putar video untuk mulai hitung waktu<?php endif; ?>
        </div>
        <div class="watch-status__hint" id="status-hint">
          <?php if ($canWatch): ?>Reward: <?= format_rp((float)$video['reward_amount']) ?> setelah <?= $video['watch_duration'] ?>s<?php endif; ?>
        </div>
      </div>
    </div>
    <?php if (!$already_watched && $watch_today < $watch_limit): ?>
    <div id="claim-wrap" style="display:none">
      <button id="claim-btn" onclick="claimReward()" style="display:flex;align-items:center;gap:6px;background:#10b981;color:#fff;border:2.5px solid #065f46;box-shadow:0 3px 0 #065f46;font-size:13px;font-weight:900;padding:8px 14px;border-radius:12px;cursor:pointer;">
        <i class="ph-bold ph-gift" style="color:#fde047;font-size:16px"></i> Klaim Cuan
      </button>
    </div>
    <?php elseif ($watch_today >= $watch_limit): ?>
    <a href="/upgrade" style="display:flex;align-items:center;gap:5px;background:#f59e0b;color:#78350f;border:2px solid #78350f;box-shadow:0 3px 0 #78350f;font-weight:900;font-size:12px;padding:6px 12px;border-radius:10px;text-decoration:none;">
      <i class="ph-bold ph-crown" style="font-size:15px"></i> Upgrade VIP
    </a>
    <?php endif; ?>
  </div>

  <!-- Video info & Mascot Tips -->
  <div style="padding:16px">
    <h1 style="font-size:16px;font-weight:900;line-height:1.4;margin-bottom:10px;color:#78350f;"><?= htmlspecialchars($video['title']) ?></h1>
    <div style="display:flex;flex-wrap:wrap;gap:8px">
      <span style="display:inline-flex;align-items:center;gap:4px;background:#fef3c7;border:1.5px solid #78350f;color:#78350f;font-weight:900;font-size:11px;padding:4px 10px;border-radius:12px;box-shadow:0 2px 0 #78350f;"><i class="ph-bold ph-coins" style="color:#d97706"></i> +<?= format_rp((float)$video['reward_amount']) ?></span>
      <span style="display:inline-flex;align-items:center;gap:4px;background:#fff;border:1.5px solid #78350f;color:#78350f;font-weight:900;font-size:11px;padding:4px 10px;border-radius:12px;box-shadow:0 2px 0 #78350f;"><i class="ph-bold ph-clock" style="color:#d97706"></i> <?= $video['watch_duration'] ?>s minimum</span>
      <span style="display:inline-flex;align-items:center;gap:4px;background:#fff;border:1.5px solid #78350f;color:#78350f;font-weight:900;font-size:11px;padding:4px 10px;border-radius:12px;box-shadow:0 2px 0 #78350f;"><i class="ph-bold ph-eye" style="color:#d97706"></i> <?= number_format((int)$video['total_watches']) ?>× ditonton</span>
    </div>

    <!-- Mascot Buzzy Encouragement Card -->
    <div style="background: linear-gradient(135deg, #fffbeb 0%, #fef3c7 100%); border: 2.5px solid #78350f; border-radius: 16px; box-shadow: 0 4px 0 #78350f; padding: 12px 14px; margin-top: 14px; display: flex; align-items: center; gap: 12px;">
      <div style="width: 44px; height: 44px; background: #fde68a; border: 2px solid #78350f; border-radius: 12px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; box-shadow: 0 2px 0 #78350f;">
        <img src="/assets/game/bee_worker.png" alt="Buzzy" style="width: 36px; height: 36px; object-fit: contain;">
      </div>
      <div style="font-size: 11.5px; font-weight: 800; color: #78350f; line-height: 1.4;">
        <b>Buzzy si Lebah Cuan:</b> Tonton video sampai timer 0 detik ya! Setelah selesai, tekan tombol hijau <b>Klaim Cuan</b> agar saldo langsung masuk.
      </div>
    </div>

    <?php if ($already_watched): ?>
    <div class="alert alert--success" style="margin-top:12px;display:flex;align-items:center;gap:6px;background:#ecfdf5;border:2.5px solid #065f46;color:#065f46;font-weight:900;border-radius:14px;box-shadow:0 3px 0 #065f46;padding:12px;">
      <i class="ph-bold ph-check-circle" style="font-size:20px"></i> Kamu sudah menonton dan menerima reward video ini hari ini!
    </div>
    <a href="/videos" style="display:block;text-align:center;background:#fff;border:2.5px solid #78350f;border-radius:14px;padding:10px;color:#78350f;font-weight:900;text-decoration:none;box-shadow:0 3px 0 #78350f;margin-top:10px;">
      ← Pilih Video Lainnya
    </a>
    <?php endif; ?>
  </div>

  <?php
  // Load other videos the user hasn't watched today
  $others = $pdo->prepare(
    "SELECT v.*,
       (SELECT COUNT(*) FROM watch_history wh WHERE wh.user_id=? AND wh.video_id=v.id AND DATE(wh.watched_at)=CURDATE()) AS watched_today
     FROM videos v
     WHERE v.is_active=1 AND v.id != ?
     ORDER BY RAND() LIMIT 4"
  );
  $others->execute([$user['id'], $vid_id]);
  $other_videos = $others->fetchAll();
  ?>
  <?php if (!empty($other_videos)): ?>
  <div style="padding:0 16px 24px">
    <div style="font-size:14px;font-weight:900;margin-bottom:12px;padding-top:10px;border-top:2.5px dashed #fde68a;color:#78350f;display:flex;align-items:center;gap:6px;">
      <i class="ph-fill ph-film-strip" style="color:#d97706;font-size:18px;"></i> Rekomendasi Video Lainnya
    </div>
    <?php foreach ($other_videos as $ov):
      $ov_done    = (bool)$ov['watched_today'];
      $ov_blocked = !$ov_done && ($watch_today >= $watch_limit);
      $ov_href    = ($ov_done || $ov_blocked) ? '#' : '/watch?id=' . $ov['id'];
    ?>
    <a href="<?= $ov_href ?>" style="display:flex;align-items:center;gap:12px;padding:10px;margin-bottom:8px;background:#fff;border:2px solid #78350f;border-radius:14px;box-shadow:0 3px 0 #78350f;text-decoration:none;color:inherit;<?= ($ov_done || $ov_blocked) ? 'opacity:.65;pointer-events:none' : '' ?>">
      <div style="position:relative;flex-shrink:0;width:96px;height:54px;border-radius:8px;overflow:hidden;border:2px solid #78350f;background:#000;">
        <img src="<?= yt_thumb($ov['youtube_id']) ?>" alt="" style="width:100%;height:100%;object-fit:cover" onerror="this.src='https://img.youtube.com/vi/<?= $ov['youtube_id'] ?>/hqdefault.jpg'">
        <?php if ($ov_done): ?>
        <div style="position:absolute;inset:0;background:rgba(16,185,129,.75);display:flex;align-items:center;justify-content:center">
          <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="#fff" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
        </div>
        <?php else: ?>
        <div style="position:absolute;inset:0;background:rgba(120,53,15,.25);display:flex;align-items:center;justify-content:center">
          <svg width="18" height="18" fill="#fff" viewBox="0 0 24 24"><polygon points="5 3 19 12 5 21 5 3"/></svg>
        </div>
        <?php endif; ?>
      </div>
      <div style="flex:1;min-width:0">
        <div style="font-size:12px;font-weight:900;line-height:1.35;overflow:hidden;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;color:#78350f;"><?= htmlspecialchars($ov['title']) ?></div>
        <div style="font-size:11px;color:#92400e;margin-top:4px;font-weight:800;display:flex;align-items:center;gap:6px;">
          <?= $ov_done ? '<span style="color:#10b981;font-weight:900;"><i class="ph-bold ph-check-circle"></i> Selesai</span>' : '<span style="color:#d97706;font-weight:900;"><i class="ph-bold ph-coins"></i> +' . format_rp((float)$ov['reward_amount']) . '</span>' ?>
          <span style="color:#b45309;font-size:10px;">• <?= $ov['watch_duration'] ?>s</span>
        </div>
      </div>
    </a>
    <?php endforeach; ?>
    <a href="/videos" style="display:block;text-align:center;background:#f59e0b;color:#78350f;border:2.5px solid #78350f;border-radius:14px;padding:10px;font-size:12.5px;font-weight:900;text-decoration:none;box-shadow:0 3px 0 #78350f;margin-top:12px;">Lihat Semua Video Misi →</a>
  </div>
  <?php endif; ?>
</div>

<!-- Reward popup -->
<div class="reward-popup" id="reward-popup"></div>

<script>
// ── Konstanta dari server ────────────────────────────
const DURATION  = <?= (int)$video['watch_duration'] ?>;
const CAN_WATCH = <?= $canWatch ? 'true' : 'false' ?>;
const CSRF      = '<?= csrf_token() ?>';
const WATCH_URL = '';   // POST ke halaman ini sendiri

// ── State ────────────────────────────────────────────
let watchToken  = null;   // diisi saat server OK start_watch
let timerLeft   = DURATION;
let timerHandle = null;
let watchStarted= false;
let claimReady  = false;
let playerReady = false;
let ytPlayer    = null;

// ── YouTube IFrame API ───────────────────────────────
window.onYouTubeIframeAPIReady = function() {
  console.log('[DEBUG] onYouTubeIframeAPIReady fired. Init player with videoId: <?= htmlspecialchars($video['youtube_id']) ?>');
  ytPlayer = new YT.Player('yt-player', {
    videoId: '<?= htmlspecialchars($video['youtube_id']) ?>',
    playerVars: {
      rel: 0,
      modestbranding: 1,
      playsinline: 1,
      autoplay: 1,
      origin: window.location.origin
    },
    events: {
      onReady: function(e) {
          console.log('[DEBUG] Player onReady');
          onPlayerReady(e);
      },
      onStateChange: function(e) {
          console.log('[DEBUG] Player onStateChange, state:', e.data);
          onPlayerStateChange(e);
      },
      onError: function(e) {
          console.log('[DEBUG] Player onError, error code:', e.data);
          setStatus('⚠️ YouTube Error: ' + e.data, 'Video tidak dapat diputar. ID: <?= htmlspecialchars($video['youtube_id']) ?>');
      }
    }
  });
};

// Load API script
console.log('[DEBUG] Injecting YouTube iframe_api script');
const tag = document.createElement('script');
tag.src = 'https://www.youtube.com/iframe_api';
document.head.appendChild(tag);

function onPlayerReady(e) {
  playerReady = true;
  // Hide the page loader once the player is ready
  const loader = document.getElementById('page-loader');
  if (loader) {
    loader.classList.add('hidden');
    setTimeout(() => loader.remove(), 400);
  }
  console.log('[DEBUG] Player is actually ready now.');
}

function onPlayerStateChange(e) {
  console.log('[DEBUG] onPlayerStateChange logic triggered. State=', e.data, 'CAN_WATCH=', CAN_WATCH);
  if (!CAN_WATCH) {
      console.log('[DEBUG] CAN_WATCH is false, ignoring state change.');
      return;
  }

  if (e.data === YT.PlayerState.PLAYING) {
    console.log('[DEBUG] Video is PLAYING.');
    if (!watchStarted) {
      console.log('[DEBUG] First time playing, calling startWatchSession().');
      startWatchSession();
    } else if (timerHandle === null && !claimReady) {
      console.log('[DEBUG] Resuming countdown.');
      resumeCountdown();
    }
  } else if (e.data === YT.PlayerState.PAUSED ||
             e.data === YT.PlayerState.BUFFERING) {
    console.log('[DEBUG] Video paused or buffering. Calling pauseCountdown().');
    pauseCountdown();
  } else if (e.data === YT.PlayerState.ENDED) {
    console.log('[DEBUG] Video ended.');
  }
}

// ── Server: minta watch token ────────────────────────
async function startWatchSession() {
  const fd = new FormData();
  fd.append('action', 'start_watch');
  fd.append('_csrf', CSRF);
  const res  = await fetch(WATCH_URL, {method:'POST', body:fd});
  const data = await res.json();
  if (!data.ok) {
    setStatus('⚠️ ' + data.msg, '');
    return;
  }
  watchToken   = data.watch_token;
  watchStarted = true;
  timerLeft    = DURATION;
  startCountdown();
}

// ── Countdown ────────────────────────────────────────
function startCountdown() {
  updateTimerUI();
  timerHandle = setInterval(() => {
    timerLeft--;
    updateTimerUI();
    if (timerLeft <= 0) {
      clearInterval(timerHandle);
      timerHandle = null;
      claimReady  = true;
      showClaimButton();
    }
  }, 1000);
}

function pauseCountdown() {
  if (timerHandle) { clearInterval(timerHandle); timerHandle = null; }
  if (!claimReady) setStatus('⏸ Video dijeda — lanjutkan untuk hitung waktu', '');
}

function resumeCountdown() {
  startCountdown();
}

function updateTimerUI() {
  const badge = document.getElementById('timer-badge');
  const fill  = document.getElementById('prog-fill');
  const pct   = Math.min(100, ((DURATION - timerLeft) / DURATION) * 100);

  badge.textContent   = timerLeft > 0 ? timerLeft : '✓';
  fill.style.width    = pct + '%';
  fill.style.transition = 'width 1s linear';
  if (timerLeft <= 0) fill.classList.add('done');

  setStatus(
    timerLeft > 0 ? `⏱ ${timerLeft}s lagi untuk klaim reward` : '🎉 Reward siap diklaim!',
    timerLeft > 0 ? `Jangan pause video` : ''
  );
}

function showClaimButton() {
  const w = document.getElementById('claim-wrap');
  if (w) { w.style.display = 'block'; }
  document.getElementById('prog-fill').classList.add('done');
}

function setStatus(text, hint) {
  const el = document.getElementById('status-text');
  const eh = document.getElementById('status-hint');
  if (el) el.textContent = text;
  if (eh) eh.textContent = hint;
}

// ── Claim reward ─────────────────────────────────────
async function claimReward() {
  if (!watchToken) {
    nToast('Token tidak ditemukan. Putar video dari awal.', 'error');
    return;
  }
  const btn = document.getElementById('claim-btn');
  btn.disabled = true;
  btn.textContent = '⏳...';

  const fd = new FormData();
  fd.append('action', 'claim');
  fd.append('_csrf', CSRF);
  fd.append('watch_token', watchToken);

  const res  = await fetch(WATCH_URL, {method:'POST', body:fd});
  const data = await res.json();

  if (data.ok) {
    showPop('🎉 ' + data.msg);
    btn.textContent = '✅ Reward Diterima!';
    document.getElementById('prog-fill').classList.add('done');
    setStatus('✅ Reward berhasil! Mengalihkan...', '');
    setTimeout(() => location.href = '/videos', 2500);
  } else {
    nToast(data.msg, 'error');
    btn.disabled    = false;
    btn.textContent = '🎁 Klaim';
  }
}

// ── Popup ────────────────────────────────────────────
function showPop(msg) {
  const el = document.getElementById('reward-popup');
  el.textContent = msg;
  el.classList.add('show');
  setTimeout(() => el.classList.remove('show'), 3500);
}
</script>
<script src="/assets/js/toast.js"></script>
</body>
</html>
