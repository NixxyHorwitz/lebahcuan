<?php
/** partials/header.php — requires: $pageTitle, $activePage, $user */
$_seo_title  = setting($pdo, 'seo_title', 'TontonCuan');
$_seo_desc   = setting($pdo, 'seo_description', '');
$_seo_kw     = setting($pdo, 'seo_keywords', '');
$_seo_robots = setting($pdo, 'seo_robots', 'index,follow');
$_seo_og       = setting($pdo, 'seo_og_image', '');
$_seo_twcard   = setting($pdo, 'seo_twitter_card', 'summary_large_image');
$_seo_author   = setting($pdo, 'seo_author', 'TontonCuan');
$_seo_og_title = setting($pdo, 'seo_og_title', '');
$_seo_og_desc  = setting($pdo, 'seo_og_description', '');
$_seo_og_type  = setting($pdo, 'seo_og_type', 'website');
$_favicon    = setting($pdo, 'favicon_path', '');
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1">
<meta name="theme-color" content="#d97706">
<title><?= htmlspecialchars(($pageTitle ?? '') ? $pageTitle . ' — ' . $_seo_title : $_seo_title) ?></title>
<?php if ($_seo_desc): ?><meta name="description" content="<?= htmlspecialchars($_seo_desc) ?>"><?php endif; ?>
<?php if ($_seo_kw):   ?><meta name="keywords"    content="<?= htmlspecialchars($_seo_kw) ?>"><?php endif; ?>
<?php if ($_seo_author):?><meta name="author"     content="<?= htmlspecialchars($_seo_author) ?>"><?php endif; ?>
<meta name="robots" content="<?= htmlspecialchars($_seo_robots) ?>">
<?php
$absolute_og = $_seo_og ? (preg_match('~^https?://~', $_seo_og) ? $_seo_og : base_url(ltrim($_seo_og, '/'))) : '';
$fav_url = $_favicon ? (preg_match('~^https?://~', $_favicon) ? $_favicon : '/' . ltrim($_favicon, '/')) : '';
$current_url = base_url(ltrim($_SERVER['REQUEST_URI'] ?? '', '/'));
$final_og_desc = $_seo_og_desc ?: $_seo_desc;
?>
<link rel="canonical" href="<?= htmlspecialchars($current_url) ?>">
<meta property="og:locale" content="id_ID">
<meta property="og:site_name" content="<?= htmlspecialchars($_seo_title) ?>">
<meta property="og:url" content="<?= htmlspecialchars($current_url) ?>">
<meta property="og:type" content="<?= htmlspecialchars($_seo_og_type) ?>">
<meta property="og:title" content="<?= htmlspecialchars($_seo_og_title ?: (($pageTitle ?? '') ? $pageTitle . ' — ' . $_seo_title : $_seo_title)) ?>">
<?php if ($final_og_desc): ?>
<meta property="og:description" content="<?= htmlspecialchars($final_og_desc) ?>">
<?php endif; ?>
<?php if ($absolute_og): ?>
<meta property="og:image" content="<?= htmlspecialchars($absolute_og) ?>">
<meta property="og:image:secure_url" content="<?= htmlspecialchars($absolute_og) ?>">
<meta property="og:image:alt" content="<?= htmlspecialchars($_seo_title) ?>">
<?php endif; ?>
<meta name="twitter:card" content="<?= htmlspecialchars($_seo_twcard) ?>">
<meta name="twitter:title" content="<?= htmlspecialchars($_seo_og_title ?: (($pageTitle ?? '') ? $pageTitle . ' — ' . $_seo_title : $_seo_title)) ?>">
<?php if ($final_og_desc): ?><meta name="twitter:description" content="<?= htmlspecialchars($final_og_desc) ?>"><?php endif; ?>
<?php if ($absolute_og): ?><meta name="twitter:image" content="<?= htmlspecialchars($absolute_og) ?>"><?php endif; ?>
<?php if ($fav_url): ?>
<link rel="icon" href="<?= htmlspecialchars($fav_url) ?>?v=<?= @filemtime(dirname(__DIR__) . '/' . ltrim($_favicon, '/')) ?: time() ?>">
<link rel="apple-touch-icon" href="<?= htmlspecialchars($fav_url) ?>?v=<?= @filemtime(dirname(__DIR__) . '/' . ltrim($_favicon, '/')) ?: time() ?>">
<?php endif; ?>
<script src="https://unpkg.com/@phosphor-icons/web"></script>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Nunito:wght@700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/app.css?v=<?= filemtime(dirname(__DIR__) . '/assets/css/app.css') ?>">
<style>
/* ══ GLOBAL RESET ══ */
*, *::before, *::after { box-sizing: border-box; }
html, body { margin: 0; padding: 0; width: 100%; overflow-x: hidden; }
body {
  font-family: 'Nunito', 'Inter', sans-serif !important;
  background: #fef8ee !important;
  background-image:
    radial-gradient(#fde68a 1.8px, transparent 1.8px),
    radial-gradient(#fde68a 1.8px, transparent 1.8px) !important;
  background-size: 28px 28px !important;
  background-position: 0 0, 14px 14px !important;
  padding-bottom: 96px !important;
  -webkit-font-smoothing: antialiased;
  color: #451a03;
}
i[class^="ph-"] {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  vertical-align: middle;
}
.app-shell { width: 100%; max-width: 480px; margin: 0 auto; }

/* ══ TOPBAR — AMBER HONEY GRADIENT ══ */
/* ══ TOPBAR — REMADE AMBER HONEY GAME HUD ══ */
.topbar {
  position: sticky; top: 0; z-index: 1000;
  width: 100%;
  height: 60px;
  background: linear-gradient(135deg, #b45309 0%, #d97706 45%, #f59e0b 100%);
  border-bottom: 3.5px solid #78350f;
  box-shadow: 0 4px 16px rgba(120, 53, 15, 0.28);
  display: flex;
  align-items: center;
  position: sticky;
}

.topbar__inner {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0 12px;
  height: 100%;
  width: 100%;
}

/* ── Brand & Mascot ── */
.tb-brand {
  display: flex;
  align-items: center;
  gap: 8px;
  text-decoration: none;
  flex-shrink: 0;
}
.tb-mascot-box {
  width: 38px; height: 38px;
  background: #fffbeb;
  border: 2.5px solid #78350f;
  border-radius: 13px;
  box-shadow: 0 3px 0 #78350f;
  display: flex; align-items: center; justify-content: center;
  flex-shrink: 0;
  position: relative;
  overflow: hidden;
}
.tb-mascot-img {
  width: 28px; height: 28px;
  object-fit: contain;
  animation: tbBeeFloat 2.6s ease-in-out infinite;
}
@keyframes tbBeeFloat {
  0%, 100% { transform: translateY(0) rotate(0deg); }
  50% { transform: translateY(-3px) rotate(4deg); }
}

.tb-brand-info {
  display: flex;
  flex-direction: column;
  line-height: 1.1;
}
.tb-brand-name {
  font-size: 16.5px;
  font-weight: 900;
  color: #ffffff;
  text-shadow: 0 2px 0 #78350f;
  letter-spacing: -0.3px;
}
.tb-brand-name em {
  font-style: normal;
  color: #fef08a;
  text-shadow: 0 2px 0 #78350f;
}
.tb-brand-tag {
  display: inline-flex;
  align-items: center;
  gap: 3px;
  font-size: 8px;
  font-weight: 900;
  color: #78350f;
  background: #fde68a;
  padding: 1px 5px;
  border-radius: 6px;
  border: 1px solid #78350f;
  width: fit-content;
  margin-top: 2px;
  box-shadow: 0 1px 0 #78350f;
  text-transform: uppercase;
  letter-spacing: 0.2px;
}

/* ── Actions HUD ── */
.tb-actions {
  display: flex;
  align-items: center;
  gap: 6px;
  flex-shrink: 0;
}

/* ── Saldo Capsule ── */
.tb-saldo-capsule {
  display: flex;
  align-items: center;
  gap: 5px;
  background: #fffbeb;
  border: 2.5px solid #78350f;
  border-radius: 16px;
  padding: 3px 7px 3px 5px;
  box-shadow: 0 3px 0 #78350f;
  cursor: pointer;
  user-select: none;
  transition: transform 0.1s, box-shadow 0.1s;
}
.tb-saldo-capsule:active {
  transform: translateY(2px);
  box-shadow: 0 1px 0 #78350f;
}
.tb-saldo-icon {
  width: 24px; height: 24px;
  background: linear-gradient(135deg, #fde047, #f59e0b);
  border: 1.5px solid #78350f;
  border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  font-size: 13px;
  color: #78350f;
  flex-shrink: 0;
  box-shadow: 0 1px 0 #78350f;
}
.tb-saldo-texts {
  display: flex;
  flex-direction: column;
  line-height: 1.1;
}
.tb-saldo-lbl {
  font-size: 7.5px;
  font-weight: 900;
  color: #92400e;
  letter-spacing: 0.3px;
  text-transform: uppercase;
}
.tb-saldo-val {
  font-size: 11.5px;
  font-weight: 900;
  color: #78350f;
  white-space: nowrap;
}
.tb-saldo-plus {
  width: 16px; height: 16px;
  background: #fde68a;
  border: 1.5px solid #78350f;
  border-radius: 6px;
  display: flex; align-items: center; justify-content: center;
  font-size: 9px;
  color: #78350f;
  font-weight: 900;
  transition: transform 0.2s;
}
.tb-saldo-capsule.active .tb-saldo-plus {
  transform: rotate(180deg);
}

/* ── Buttons (Bell & Avatar) ── */
.tb-btn-icon {
  width: 36px; height: 36px;
  background: #fffbeb;
  border: 2.5px solid #78350f;
  border-radius: 12px;
  box-shadow: 0 3px 0 #78350f;
  display: flex; align-items: center; justify-content: center;
  color: #78350f;
  text-decoration: none;
  position: relative;
  font-weight: 900;
  font-size: 17px;
  flex-shrink: 0;
  transition: transform 0.1s, box-shadow 0.1s;
}
.tb-btn-icon:active {
  transform: translateY(2px);
  box-shadow: 0 1px 0 #78350f;
}
.tb-avatar {
  background: linear-gradient(135deg, #fde68a, #f59e0b);
  color: #78350f;
  font-size: 14.5px;
}
.tb-badge-count {
  position: absolute;
  top: -5px; right: -5px;
  background: #dc2626;
  color: #fff;
  font-size: 9px;
  font-weight: 900;
  min-width: 17px; height: 17px;
  border-radius: 10px;
  padding: 0 4px;
  border: 2px solid #fff;
  box-shadow: 0 2px 0 rgba(0,0,0,0.25);
  display: none;
  align-items: center; justify-content: center;
  line-height: 1;
}

/* Guest buttons */
.tb-login-btn {
  background: #fffbeb;
  border: 2px solid #78350f;
  border-radius: 10px;
  padding: 6px 12px;
  color: #78350f;
  font-weight: 900;
  font-size: 12px;
  text-decoration: none;
  box-shadow: 0 2px 0 #78350f;
}
.tb-reg-btn {
  background: #fde68a;
  border: 2px solid #78350f;
  border-radius: 10px;
  padding: 6px 12px;
  color: #78350f;
  font-weight: 900;
  font-size: 12px;
  text-decoration: none;
  box-shadow: 0 2px 0 #78350f;
}

@media (max-width: 360px) {
  .tb-brand-tag { display: none; }
  .tb-saldo-lbl { display: none; }
  .tb-saldo-capsule { padding: 3px 5px; }
  .tb-btn-icon { width: 32px; height: 32px; font-size: 15px; }
  .tb-brand-name { font-size: 15px; }
  .tb-mascot-box { width: 34px; height: 34px; }
  .tb-mascot-img { width: 24px; height: 24px; }
}

/* ── WALLET POPOVER DRAWER ── */
.tb-wallet-backdrop {
  display: none;
  position: fixed; inset: 0;
  background: rgba(69, 26, 3, 0.45);
  backdrop-filter: blur(4px); -webkit-backdrop-filter: blur(4px);
  z-index: 9998;
  animation: tbFadeIn .2s ease;
}
.tb-wallet-backdrop.open { display: block; }

.tb-wallet-popover {
  display: none;
  position: fixed;
  top: 68px; left: 50%;
  transform: translateX(-50%);
  width: calc(100% - 24px); max-width: 440px;
  background: #fffbeb;
  border: 3px solid #78350f;
  border-radius: 20px;
  box-shadow: 0 8px 0 #78350f, 0 16px 36px rgba(120, 53, 15, 0.35);
  z-index: 9999;
  overflow: hidden;
  animation: tbPopIn .25s cubic-bezier(0.175, 0.885, 0.32, 1.275);
}
.tb-wallet-popover.open { display: block; }

@keyframes tbPopIn {
  from { transform: translateX(-50%) translateY(-10px) scale(0.96); opacity: 0; }
  to { transform: translateX(-50%) translateY(0) scale(1); opacity: 1; }
}
@keyframes tbFadeIn {
  from { opacity: 0; }
  to { opacity: 1; }
}

.twp-header {
  background: linear-gradient(135deg, #f59e0b, #d97706);
  padding: 12px 16px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  border-bottom: 2.5px solid #78350f;
  color: #fff;
}
.twp-title {
  font-size: 14px;
  font-weight: 900;
  display: flex;
  align-items: center;
  gap: 6px;
  text-shadow: 0 1.5px 0 #78350f;
}
.twp-close {
  background: #78350f;
  color: #fde68a;
  border: 1.5px solid #fff;
  border-radius: 8px;
  width: 26px; height: 26px;
  display: flex; align-items: center; justify-content: center;
  font-size: 16px;
  font-weight: 900;
  cursor: pointer;
  box-shadow: 0 2px 0 rgba(0,0,0,0.2);
}

.twp-body {
  padding: 14px;
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.twp-card {
  background: #ffffff;
  border: 2px solid #78350f;
  border-radius: 14px;
  padding: 10px 12px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  box-shadow: 0 3px 0 #78350f;
}
.twp-card-left {
  display: flex;
  align-items: center;
  gap: 10px;
}
.twp-card-icon {
  width: 36px; height: 36px;
  border-radius: 10px;
  border: 1.5px solid #78350f;
  display: flex; align-items: center; justify-content: center;
  font-size: 18px;
  flex-shrink: 0;
  box-shadow: 0 2px 0 #78350f;
}
.twp-card-icon.wd { background: #d1fae5; color: #065f46; }
.twp-card-icon.dep { background: #fef3c7; color: #b45309; }
.twp-card-icon.honey { background: #fed7aa; color: #9a3412; }

.twp-card-info {
  display: flex;
  flex-direction: column;
}
.twp-card-lbl {
  font-size: 9.5px;
  font-weight: 800;
  color: #92400e;
  text-transform: uppercase;
}
.twp-card-val {
  font-size: 14px;
  font-weight: 900;
  color: #78350f;
}

.twp-card-btn {
  padding: 6px 12px;
  border-radius: 10px;
  font-size: 11px;
  font-weight: 900;
  text-decoration: none;
  white-space: nowrap;
  box-shadow: 0 2px 0 rgba(0,0,0,0.25);
  transition: transform 0.1s;
}
.twp-card-btn:active { transform: translateY(2px); box-shadow: none; }
.twp-card-btn.wd { background: #10b981; color: #fff; border: 1.5px solid #065f46; }
.twp-card-btn.dep { background: #f59e0b; color: #78350f; border: 1.5px solid #78350f; }
.twp-card-btn.honey { background: #f97316; color: #fff; border: 1.5px solid #7c2d12; }

.twp-footer {
  padding: 0 14px 14px;
  display: flex;
  justify-content: center;
}
.twp-farm-link {
  width: 100%;
  text-align: center;
  background: #fef08a;
  border: 2px solid #78350f;
  border-radius: 12px;
  padding: 8px 12px;
  font-size: 11.5px;
  font-weight: 900;
  color: #78350f;
  text-decoration: none;
  box-shadow: 0 3px 0 #78350f;
}
</style>
</head>
<body>
<div class="app-shell">
  <!-- ══ REMADE TOPBAR ══ -->
  <header class="topbar">
    <div class="topbar__inner">
      <!-- Left: Logo & Mascot -->
      <a href="/home" class="tb-brand">
        <div class="tb-mascot-box">
          <img src="/assets/game/bee_worker.png" alt="Lebah Cuan" class="tb-mascot-img">
        </div>
        <div class="tb-brand-info">
          <div class="tb-brand-name">Lebah<em>Cuan</em></div>
          <div class="tb-brand-tag"><i class="ph-fill ph-sparkle"></i> Watch & Farm</div>
        </div>
      </a>

      <!-- Right: Game HUD / Actions -->
      <?php if (!empty($user)): ?>
      <?php
      if (!function_exists('fmt_short_tb')) {
        function fmt_short_tb(float $n): string {
          if ($n >= 1_000_000_000) return 'Rp ' . number_format($n/1_000_000_000, 1, '.', '') . 'M';
          if ($n >= 1_000_000)     return 'Rp ' . number_format($n/1_000_000, 1, '.', '') . 'jt';
          if ($n >= 100_000)       return 'Rp ' . number_format($n/1_000, 0, '.', '') . 'rb';
          return 'Rp ' . number_format($n, 0, ',', '.');
        }
      }
      ?>
      <div class="tb-actions">
        <!-- Saldo Capsule HUD -->
        <div class="tb-saldo-capsule" id="tb-saldo-trigger" onclick="toggleWalletPopover()" title="Klik untuk rincian dompet">
          <div class="tb-saldo-icon">
            <i class="ph-fill ph-coins"></i>
          </div>
          <div class="tb-saldo-texts">
            <span class="tb-saldo-lbl">SALDO WD</span>
            <span class="tb-saldo-val"><?= fmt_short_tb((float)$user['balance_wd']) ?></span>
          </div>
          <div class="tb-saldo-plus">
            <i class="ph-bold ph-caret-down"></i>
          </div>
        </div>

        <!-- Notifikasi -->
        <a href="/notifications" class="tb-btn-icon" id="notif-bell-btn" title="Notifikasi">
          <i class="ph-bold ph-bell"></i>
          <span id="notif-badge" class="tb-badge-count"></span>
        </a>

        <!-- Avatar Profil -->
        <a href="/profile" class="tb-btn-icon tb-avatar" title="Profil Akun">
          <?= strtoupper(substr($user['username'], 0, 1)) ?>
        </a>
      </div>
      <?php else: ?>
      <div class="tb-actions">
        <a href="/login" class="tb-login-btn">Masuk</a>
        <a href="/register" class="tb-reg-btn">Daftar</a>
      </div>
      <?php endif; ?>
    </div>

    <?php if (!empty($user)): ?>
    <!-- Wallet Popover Modal -->
    <div class="tb-wallet-backdrop" id="tb-wallet-backdrop" onclick="toggleWalletPopover(false)"></div>
    <div class="tb-wallet-popover" id="tb-wallet-popover">
      <div class="twp-header">
        <div class="twp-title">
          <i class="ph-bold ph-wallet"></i> Dompet LebahCuan
        </div>
        <button type="button" class="twp-close" onclick="toggleWalletPopover(false)">&times;</button>
      </div>
      <div class="twp-body">
        <!-- Card 1: Saldo WD -->
        <div class="twp-card">
          <div class="twp-card-left">
            <div class="twp-card-icon wd"><i class="ph-bold ph-coins"></i></div>
            <div class="twp-card-info">
              <span class="twp-card-lbl">Saldo Siap Tarik (WD)</span>
              <span class="twp-card-val"><?= format_rp((float)$user['balance_wd']) ?></span>
            </div>
          </div>
          <a href="/withdraw" class="twp-card-btn wd">Tarik →</a>
        </div>

        <!-- Card 2: Saldo Depo -->
        <div class="twp-card">
          <div class="twp-card-left">
            <div class="twp-card-icon dep"><i class="ph-bold ph-wallet"></i></div>
            <div class="twp-card-info">
              <span class="twp-card-lbl">Saldo Pembelian (Depo)</span>
              <span class="twp-card-val"><?= format_rp((float)$user['balance_dep']) ?></span>
            </div>
          </div>
          <a href="/deposit" class="twp-card-btn dep">+ Isi Saldo</a>
        </div>

        <!-- Card 3: Stok Madu -->
        <div class="twp-card">
          <div class="twp-card-left">
            <div class="twp-card-icon honey"><i class="ph-bold ph-drop"></i></div>
            <div class="twp-card-info">
              <span class="twp-card-lbl">Stok Madu Sidejob</span>
              <span class="twp-card-val"><?= number_format((float)($user['honey_stock'] ?? 0), 1) ?> ml</span>
            </div>
          </div>
          <a href="/farm" class="twp-card-btn honey">Sidejob 🐝</a>
        </div>
      </div>
      <div class="twp-footer">
        <a href="/farm" class="twp-farm-link">
          🌻 Buka Peternakan Lebah untuk panen madu! →
        </a>
      </div>
    </div>

    <script>
    function toggleWalletPopover(force) {
      const popover = document.getElementById('tb-wallet-popover');
      const backdrop = document.getElementById('tb-wallet-backdrop');
      const trigger = document.getElementById('tb-saldo-trigger');
      if (!popover || !backdrop) return;

      const shouldOpen = typeof force === 'boolean' ? force : !popover.classList.contains('open');
      if (shouldOpen) {
        popover.classList.add('open');
        backdrop.classList.add('open');
        if (trigger) trigger.classList.add('active');
      } else {
        popover.classList.remove('open');
        backdrop.classList.remove('open');
        if (trigger) trigger.classList.remove('active');
      }
    }
    </script>
    <?php endif; ?>
  </header>

  <main class="page-content">

<?php if (!empty($user)): ?>
<script>
(function() {
  function fetchNotifCount() {
    fetch('/notif_action?action=count')
      .then(r => r.json())
      .then(data => {
        const badge = document.getElementById('notif-badge');
        if (!badge) return;
        if (data.count > 0) {
          badge.textContent = data.count > 9 ? '9+' : data.count;
          badge.style.display = 'inline-flex';
        } else {
          badge.style.display = 'none';
        }
      })
      .catch(() => {});
  }
  fetchNotifCount();
  setInterval(fetchNotifCount, 60000);
})();
</script>
<?php endif; ?>
