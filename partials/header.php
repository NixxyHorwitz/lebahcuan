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
.topbar {
  position: sticky; top: 0; z-index: 1000;
  width: 100%;
  height: auto !important;
  min-height: 56px;
  background: linear-gradient(135deg, #b45309 0%, #d97706 40%, #f59e0b 80%, #fbbf24 100%);
  border-bottom: 4px solid #78350f;
  box-shadow: 0 4px 0 #451a03;
  display: flex;
  flex-direction: column;
}

/* ── Row 1: Logo + Actions ── */
.topbar__row1 {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0 14px;
  height: 56px;
  width: 100%;
  flex-shrink: 0;
}

.topbar__logo {
  display: flex; align-items: center; gap: 8px;
  font-weight: 900; font-size: 19px;
  color: #fff; text-decoration: none; flex-shrink: 0;
  text-shadow: 0 2px 0 #78350f;
}
.topbar__logo-box {
  width: 38px; height: 38px;
  background: #fff;
  border: 2.5px solid #78350f;
  border-radius: 13px;
  display: flex; align-items: center; justify-content: center;
  font-size: 18px; box-shadow: 0 3px 0 #78350f; flex-shrink: 0;
}
.topbar__logo span em { font-style: normal; color: #fef08a; }

.topbar__actions {
  display: flex; align-items: center; gap: 8px; flex-shrink: 0;
}
.topbar__bell {
  position: relative;
  width: 38px; height: 38px;
  background: rgba(255,255,255,0.22);
  border: 2px solid rgba(255,255,255,0.45);
  border-radius: 13px;
  display: flex; align-items: center; justify-content: center;
  color: #fff; font-size: 19px; text-decoration: none;
  box-shadow: 0 3px 0 rgba(120,53,15,0.3);
  flex-shrink: 0;
  transition: transform 0.1s;
}
.topbar__bell:active { transform: translateY(2px); }
.topbar__avatar {
  width: 38px; height: 38px;
  background: #fff;
  color: #78350f;
  border: 2.5px solid #78350f;
  border-radius: 13px;
  display: flex; align-items: center; justify-content: center;
  font-weight: 900; font-size: 16px; text-decoration: none;
  box-shadow: 0 3px 0 #78350f; flex-shrink: 0;
  transition: transform 0.1s;
}
.topbar__avatar:active { transform: translateY(2px); }
.notif-dot {
  position: absolute; top: -5px; right: -5px;
  display: none;
  background: #ef4444; color: #fff;
  font-size: 9px; font-weight: 900;
  min-width: 18px; height: 18px;
  border-radius: 10px; padding: 0 4px;
  border: 2.5px solid #b45309;
  align-items: center; justify-content: center; line-height: 1;
}

/* ── Row 2: Balance Pills ── */
.topbar__row2 {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 0 12px 10px;
  width: 100%;
  flex-shrink: 0;
}

.bal-dropdown { flex: 1; min-width: 0; }

.bal-pill {
  display: flex; align-items: center; gap: 6px;
  background: #fff;
  border: 2.5px solid #78350f;
  border-radius: 18px;
  padding: 5px 10px 5px 6px;
  cursor: pointer; transition: transform 0.15s, box-shadow 0.15s;
  width: 100%;
  box-shadow: 0 3px 0 #78350f;
}
.bal-pill:active { transform: translateY(2px); box-shadow: 0 1px 0 #78350f; }
.bal-pill__icon {
  width: 24px; height: 24px; border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  font-size: 13px; flex-shrink: 0;
  border: 1.5px solid rgba(0,0,0,0.05);
}
.bal-pill__texts { display: flex; flex-direction: column; gap: 0; min-width: 0; }
.bal-pill__label {
  font-size: 8.5px; font-weight: 800; color: #78350f; line-height: 1;
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.bal-pill__val {
  font-size: 12px; font-weight: 900; color: #b45309; line-height: 1.2;
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}

/* Dropdown panel */
.bal-dropdown__panel {
  display: none; position: absolute;
  left: 12px; top: calc(100% + 4px);
  background: #fff;
  border: 3px solid #78350f;
  border-radius: 18px;
  box-shadow: 0 6px 0 #78350f, 0 12px 24px rgba(120,53,15,0.25);
  min-width: 200px; z-index: 9999; overflow: hidden;
  animation: bdFadeIn .15s ease;
}
.bal-dropdown__panel.open { display: block; }
@keyframes bdFadeIn { from { opacity:0; transform:translateY(-6px) } to { opacity:1; transform:none } }
.bal-dropdown__row {
  display: flex; justify-content: space-between; align-items: center;
  padding: 12px 14px; font-size: 12px; font-weight: 800;
}
.bal-dropdown__row--wd  { background: #ecfdf5; color: #065f46; border-bottom: 1.5px solid #d1fae5; }
.bal-dropdown__row--dep { background: #fffbeb; color: #78350f; }
.bal-dropdown__lbl { display: flex; align-items: center; gap: 4px; }
</style>
</head>
<body>
<div class="app-shell">
  <header class="topbar">
    <!-- Row 1: Logo + Actions -->
    <div class="topbar__row1">
      <a href="/home" class="topbar__logo">
        <div class="topbar__logo-box">
          <img src="/assets/game/bee_worker.png" alt="Lebah Cuan" style="width:26px;height:26px;object-fit:contain;">
        </div>
        <span>Lebah<em>Cuan</em></span>
      </a>

      <?php if (!empty($user)): ?>
      <div class="topbar__actions">
        <a href="/notifications" class="topbar__bell" id="notif-bell-btn" title="Notifikasi">
          <i class="ph-bold ph-bell"></i>
          <span id="notif-badge" class="notif-dot"></span>
        </a>
        <a href="/profile" class="topbar__avatar" title="Akun">
          <?= strtoupper(substr($user['username'], 0, 1)) ?>
        </a>
      </div>
      <?php endif; ?>
    </div>

    <?php if (!empty($user)): ?>
    <?php
    function fmt_short(float $n): string {
      if ($n >= 1_000_000) return 'Rp ' . number_format($n/1_000_000, 1, '.', '') . 'jt';
      if ($n >= 1_000)     return 'Rp ' . number_format($n/1_000, 1, '.', '') . 'rb';
      return 'Rp ' . (string)(int)$n;
    }
    ?>


    <script>
    (function(){
      var _justOpened = {};
      window.toggleBal = function(key, e) {
        if (e) { e.preventDefault(); e.stopPropagation(); }
        var panel = document.getElementById('bal-panel-' + key);
        if (!panel) return;
        var opening = !panel.classList.contains('open');
        ['wd','dep'].forEach(function(k) {
          var p = document.getElementById('bal-panel-' + k);
          if (p) p.classList.remove('open');
        });
        if (opening) {
          panel.classList.add('open');
          _justOpened[key] = true;
          setTimeout(function(){ _justOpened[key] = false; }, 150);
        }
      };
      document.addEventListener('click', function(e) {
        ['wd','dep'].forEach(function(key) {
          if (_justOpened[key]) return;
          var wrap = document.getElementById('bal-dropdown-' + key);
          if (wrap && wrap.contains(e.target)) return;
          var panel = document.getElementById('bal-panel-' + key);
          if (panel) panel.classList.remove('open');
        });
      });
      document.addEventListener('touchend', function(e) {
        ['wd','dep'].forEach(function(key) {
          if (_justOpened[key]) return;
          var wrap = document.getElementById('bal-dropdown-' + key);
          if (wrap && wrap.contains(e.target)) return;
          var panel = document.getElementById('bal-panel-' + key);
          if (panel) panel.classList.remove('open');
        });
      }, {passive: true});
    })();
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
