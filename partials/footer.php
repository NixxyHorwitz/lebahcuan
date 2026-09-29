  </main>

  <style>
  /* ══════════════════════════════════════════════════════
     FLOATING HONEY DOCK WITH HEXAGONAL NOTCH
     ══════════════════════════════════════════════════════ */
  .bottom-nav {
    position: fixed !important;
    bottom: 12px !important;
    left: 50% !important;
    transform: translateX(-50%) !important;
    width: calc(100% - 24px) !important;
    max-width: 440px !important;
    background: rgba(255, 255, 255, 0.96) !important;
    backdrop-filter: blur(16px) !important;
    -webkit-backdrop-filter: blur(16px) !important;
    border: 2.5px solid #78350f !important;
    border-radius: 28px !important;
    box-shadow: 0 8px 24px -4px rgba(120, 53, 15, 0.22), 0 4.5px 0 #78350f !important;
    display: grid !important;
    grid-template-columns: 1fr 1fr 1.25fr 1fr 1fr !important;
    align-items: center !important;
    height: 64px !important;
    padding: 0 6px !important;
    z-index: 9999 !important;
  }

  body { padding-bottom: 96px !important; }

  /* NAV ITEMS */
  .nav-item {
    display: flex !important;
    flex-direction: column !important;
    align-items: center !important;
    justify-content: center !important;
    text-decoration: none !important;
    color: #92400e !important;
    font-size: 10px !important;
    font-weight: 800 !important;
    font-family: 'Nunito', sans-serif !important;
    gap: 2px !important;
    height: 48px !important;
    border-radius: 14px !important;
    transition: all 0.18s cubic-bezier(0.34, 1.56, 0.64, 1) !important;
    position: relative;
    background: transparent !important;
    border: none !important;
    box-shadow: none !important;
  }
  .nav-item:active {
    transform: translateY(2px) scale(0.96) !important;
  }
  .nav-item i {
    font-size: 21px !important;
    color: #92400e !important;
    transition: transform 0.2s cubic-bezier(0.34, 1.56, 0.64, 1), color 0.15s !important;
  }

  /* ACTIVE STATE */
  .nav-item.active {
    color: #78350f !important;
    font-weight: 900 !important;
  }
  .nav-item.active i {
    color: #d97706 !important;
    transform: scale(1.15) !important;
  }
  .nav-item.active::after {
    content: '';
    position: absolute;
    bottom: 2px;
    width: 14px;
    height: 3px;
    background: #f59e0b;
    border-radius: 4px;
    border: 1px solid #78350f;
  }

  /* CENTER PLAY BUTTON (3D HALO BADGE) */
  .nav-item--play {
    position: relative !important;
    overflow: visible !important;
    height: auto !important;
    background: transparent !important;
    border: none !important;
    box-shadow: none !important;
    transform: none !important;
  }
  .nav-play-wrap {
    display: flex;
    flex-direction: column;
    align-items: center;
    position: relative;
    margin-top: -20px; /* Elevated cleanly above dock */
  }
  .nav-play-btn {
    width: 50px; height: 50px;
    background: linear-gradient(135deg, #fde047 0%, #f59e0b 50%, #d97706 100%) !important;
    border-radius: 17px;
    border: 2.5px solid #78350f !important;
    display: flex; align-items: center; justify-content: center;
    position: relative;
    z-index: 5;
    box-shadow: 0 0 0 3px #ffffff, 0 3.5px 0 3px #78350f, 0 8px 16px rgba(180,83,9,0.3) !important;
    transition: transform 0.12s, box-shadow 0.12s;
  }
  .nav-item--play:active .nav-play-btn {
    transform: translateY(2px) scale(0.96);
    box-shadow: 0 0 0 3px #ffffff, 0 1.5px 0 3px #78350f !important;
  }
  .nav-item--play.active .nav-play-btn {
    background: linear-gradient(135deg, #fef08a 0%, #fbbf24 60%, #ea580c 100%) !important;
    box-shadow: 0 0 0 3px #ffffff, 0 3.5px 0 3px #78350f, 0 0 14px rgba(245,158,11,0.6) !important;
  }
  .nav-play-btn img {
    width: 28px; height: 28px;
    object-fit: contain;
    filter: drop-shadow(0 2px 4px rgba(0,0,0,0.25));
    animation: navBeeWiggle 3.5s ease-in-out infinite;
  }
  @keyframes navBeeWiggle {
    0%, 100% { transform: translateY(0) rotate(0deg); }
    25% { transform: translateY(-2px) rotate(-4deg); }
    75% { transform: translateY(-1px) rotate(4deg); }
  }

  .nav-play-label {
    font-size: 9.5px;
    font-weight: 900;
    color: #78350f;
    font-family: 'Nunito', sans-serif;
    margin-top: 3px;
    letter-spacing: -0.2px;
    position: relative;
    z-index: 6;
  }
  .nav-item--play.active .nav-play-label {
    color: #d97706;
  }

  /* Floating contact adjustment */
  .float-contact-wrap {
    bottom: <?= ($activePage ?? '') === 'farm' && ($farmSubPage ?? '') === 'meadow' ? '154px' : '88px' ?> !important;
  }
  </style>

  <nav class="bottom-nav">
    <a href="/home" class="nav-item <?= ($activePage??'')==='home'?'active':'' ?>">
      <i class="<?= ($activePage??'')==='home'?'ph-fill':'ph-bold' ?> ph-house-simple"></i>
      Lobby
    </a>
    <a href="/videos" class="nav-item <?= ($activePage??'')==='videos'?'active':'' ?>">
      <i class="<?= ($activePage??'')==='videos'?'ph-fill':'ph-bold' ?> ph-film-strip"></i>
      Video
    </a>

    <!-- Center: SIDEJOB TERNAK LEBAH button -->
    <a href="/farm" class="nav-item nav-item--play <?= ($activePage??'')==='farm'?'active':'' ?>">
      <div class="nav-play-wrap">
        <div class="nav-play-btn">
          <img src="/assets/game/bee_worker.png" alt="Ternak Lebah">
        </div>
        <span class="nav-play-label">Ternak Lebah</span>
      </div>
    </a>

    <a href="/referral" class="nav-item <?= ($activePage??'')==='referral'?'active':'' ?>">
      <i class="<?= ($activePage??'')==='referral'?'ph-fill':'ph-bold' ?> ph-users-three"></i>
      Undang
    </a>
    <a href="/profile" class="nav-item <?= ($activePage??'')==='profile'?'active':'' ?>">
      <i class="<?= ($activePage??'')==='profile'?'ph-fill':'ph-bold' ?> ph-user-circle"></i>
      Akun
    </a>
  </nav>
</div>

<?php
$_floating_on = setting($pdo, 'floating_enabled', '1') === '1';
$_float_btns  = [];
if ($_floating_on) {
    try {
        $__q = $pdo->query("SELECT * FROM contact_buttons WHERE is_active=1 ORDER BY sort_order ASC, id ASC");
        $_float_btns = $__q ? $__q->fetchAll() : [];
    } catch (\Throwable) {}
}
if ($_floating_on && !empty($_float_btns)):
$_fsvg = [
  'wa'   => '<svg viewBox="0 0 24 24" fill="currentColor" width="22" height="22"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347z"/><path d="M12 0C5.373 0 0 5.373 0 12c0 2.123.554 4.118 1.528 5.847L.057 23.883a.5.5 0 00.61.61l6.037-1.472A11.944 11.944 0 0012 24c6.627 0 12-5.373 12-12S18.627 0 12 0zm0 22c-1.89 0-3.655-.518-5.17-1.42l-.37-.22-3.823.933.954-3.722-.242-.383A9.958 9.958 0 012 12C2 6.477 6.477 2 12 2s10 4.477 10 10-4.477 10-10 10z"/></svg>',
  'tele' => '<svg viewBox="0 0 24 24" fill="currentColor" width="22" height="22"><path d="M11.944 0A12 12 0 000 12a12 12 0 0012 12 12 12 0 0012-12A12 12 0 0012 0a12 12 0 00-.056 0zm4.962 7.224c.1-.002.321.023.465.14a.506.506 0 01.171.325c.016.093.036.306.02.472-.18 1.898-.962 6.502-1.36 8.627-.168.9-.499 1.201-.82 1.23-.696.065-1.225-.46-1.9-.902-1.056-.693-1.653-1.124-2.678-1.8-1.185-.78-.417-1.21.258-1.91.177-.184 3.247-2.977 3.307-3.23.007-.032.014-.15-.056-.212s-.174-.041-.249-.024c-.106.024-1.793 1.14-5.061 3.345-.48.33-.913.49-1.302.48-.428-.008-1.252-.241-1.865-.44-.752-.245-1.349-.374-1.297-.789.027-.216.325-.437.893-.663 3.498-1.524 5.83-2.529 6.998-3.014 3.332-1.386 4.025-1.627 4.476-1.635z"/></svg>',
  'cs'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="22" height="22"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg>',
  'ig'   => '<svg viewBox="0 0 24 24" fill="currentColor" width="22" height="22"><path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zm0-2.163c-3.259 0-3.667.014-4.947.072-4.358.2-6.78 2.618-6.98 6.98-.059 1.281-.073 1.689-.073 4.948 0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98 1.281.058 1.689.072 4.948.072 3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98-1.281-.059-1.69-.073-4.949-.073zm0 5.838a6.162 6.162 0 100 12.324 6.162 6.162 0 000-12.324zM12 16a4 4 0 110-8 4 4 0 010 8zm6.406-11.845a1.44 1.44 0 100 2.881 1.44 1.44 0 000-2.881z"/></svg>',
  'fb'   => '<svg viewBox="0 0 24 24" fill="currentColor" width="22" height="22"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>',
];
?>
<style>
.float-contact-wrap {
  position: fixed; bottom: 82px; right: 14px;
  z-index: 500; display: flex; flex-direction: column;
  align-items: flex-end; gap: 10px;
}
.float-btn {
  width: 52px; height: 52px; border-radius: 18px;
  border: 3px solid #fff;
  box-shadow: 0 5px 0 rgba(0,0,0,0.18), 0 8px 16px rgba(0,0,0,0.1);
  display: flex; align-items: center; justify-content: center;
  text-decoration: none; transition: transform 0.1s, box-shadow 0.1s;
  overflow: hidden; position: relative;
}
.float-btn::after { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 50%; background: linear-gradient(180deg, rgba(255,255,255,0.28) 0%, transparent 100%); pointer-events: none; }
.float-btn:active { transform: translateY(4px); box-shadow: 0 1px 0 rgba(0,0,0,0.15); }
.float-btn img { width: 100%; height: 100%; object-fit: cover; }
.float-btn__label { position: absolute; right: 62px; top: 50%; transform: translateY(-50%); background: #0f172a; color: #fff; font-size: 11px; font-weight: 900; white-space: nowrap; padding: 6px 12px; border-radius: 12px; border: 2px solid #1e293b; box-shadow: 0 4px 0 rgba(0,0,0,0.3); opacity: 0; pointer-events: none; transition: all 0.2s cubic-bezier(0.34,1.56,0.64,1); margin-right: -10px; font-family: 'Nunito', sans-serif; }
.float-btn:hover .float-btn__label { opacity: 1; margin-right: 0; }
</style>
<div class="float-contact-wrap" id="float-contacts">
  <?php foreach ($_float_btns as $_fb): ?>
  <a href="<?= htmlspecialchars($_fb['url']) ?>" target="_blank" rel="noopener"
     class="float-btn" style="background-color:<?= htmlspecialchars($_fb['bg_color']) ?>"
     title="<?= htmlspecialchars($_fb['label']) ?>">
    <?php if ($_fb['icon_type'] === 'custom'): ?>
      <img src="<?= htmlspecialchars($_fb['icon_value']) ?>" alt="<?= htmlspecialchars($_fb['label']) ?>">
    <?php else: ?>
      <span style="color:#fff;display:flex;align-items:center;justify-content:center;position:relative;z-index:2;"><?= $_fsvg[$_fb['icon_value']] ?? '<i class="ph-fill ph-headset" style="font-size:26px"></i>' ?></span>
    <?php endif; ?>
    <span class="float-btn__label"><?= htmlspecialchars($_fb['label']) ?></span>
  </a>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- ══════════════════════════════════════════════════════════
     UNIVERSAL SPOTLIGHT TOUR ENGINE (CROSS-PAGE & SCROLL-SYNC)
     ══════════════════════════════════════════════════════════ -->
<style>
/* Global Spotlight Backdrop (allows pointer events to pass so user can freely scroll) */
#lebah-tour-overlay {
  position: fixed;
  inset: 0;
  z-index: 99990;
  pointer-events: none;
  display: none;
  background: transparent;
}

/* Spotlight Cutout Box with Real-time Sync & Dynamic Border Radius */
#lebah-tour-spotlight {
  position: fixed;
  z-index: 99992;
  border-radius: 18px;
  border: 3px solid #fbbf24;
  box-shadow: 0 0 0 9999px rgba(10, 15, 29, 0.82), 0 0 25px rgba(251, 191, 36, 0.75);
  pointer-events: none;
  box-sizing: border-box;
  opacity: 0;
  transform: scale(0.98);
  transition: opacity 0.25s ease, transform 0.25s ease;
}
#lebah-tour-spotlight.active {
  opacity: 1;
  transform: scale(1);
}

/* Spotlight pulsing beacon indicator */
#lebah-tour-spotlight::after {
  content: '';
  position: absolute;
  inset: -6px;
  border-radius: inherit;
  border: 2px solid rgba(251, 191, 36, 0.65);
  animation: beaconPulse 1.8s cubic-bezier(0, 0, 0.2, 1) infinite;
  pointer-events: none;
}
@keyframes beaconPulse {
  0% { transform: scale(0.98); opacity: 0.9; }
  100% { transform: scale(1.05); opacity: 0; }
}

/* Floating Tour Popover Card */
#lebah-tour-popover {
  position: fixed;
  z-index: 99999;
  background: #ffffff;
  border: 3px solid #78350f;
  border-radius: 22px;
  box-shadow: 0 8px 0 #78350f, 0 20px 40px rgba(0,0,0,0.5);
  padding: 15px 15px 13px;
  max-width: 370px;
  width: calc(100vw - 28px);
  box-sizing: border-box;
  display: none;
  pointer-events: auto;
  font-family: 'Nunito', sans-serif;
  opacity: 0;
  transform: translateY(6px);
  transition: opacity 0.22s ease, transform 0.22s ease;
}
#lebah-tour-popover.active {
  opacity: 1;
  transform: translateY(0);
}

.tour-pop-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 8px;
}
.tour-pop-track-tag {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  font-size: 10px;
  font-weight: 900;
  padding: 3px 9px;
  border-radius: 10px;
  background: #fef3c7;
  color: #b45309;
  border: 1.5px solid #fde68a;
  letter-spacing: 0.2px;
}
.tour-pop-step-count {
  font-size: 11px;
  font-weight: 800;
  color: #94a3b8;
}
.tour-pop-close {
  background: transparent;
  border: none;
  font-size: 17px;
  color: #94a3b8;
  cursor: pointer;
  padding: 2px 6px;
  border-radius: 8px;
  line-height: 1;
  transition: color 0.15s;
}
.tour-pop-close:hover {
  color: #ef4444;
}
.tour-pop-title {
  font-size: 14.5px;
  font-weight: 900;
  color: #78350f;
  margin-bottom: 4px;
  display: flex;
  align-items: center;
  gap: 6px;
  line-height: 1.3;
}
.tour-pop-desc {
  font-size: 11.5px;
  font-weight: 700;
  color: #475569;
  line-height: 1.45;
  margin-bottom: 8px;
}
.tour-pop-tip {
  background: #fffbeb;
  border: 1.5px solid #fef08a;
  border-radius: 12px;
  padding: 6px 10px;
  font-size: 10.5px;
  font-weight: 800;
  color: #92400e;
  margin-bottom: 11px;
  display: flex;
  align-items: flex-start;
  gap: 5px;
  line-height: 1.35;
}
.tour-pop-footer {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  padding-top: 9px;
  border-top: 1.5px dashed #f1f5f9;
}
.tour-pop-dots {
  display: flex;
  align-items: center;
  gap: 4px;
}
.tour-pop-dot {
  width: 6px;
  height: 6px;
  border-radius: 50%;
  background: #cbd5e1;
  transition: all 0.2s ease;
}
.tour-pop-dot.active {
  width: 16px;
  border-radius: 4px;
  background: #d97706;
}
.tour-pop-nav-btns {
  display: flex;
  align-items: center;
  gap: 6px;
}
.btn-tour-nav-prev {
  background: #f1f5f9;
  border: 2px solid #cbd5e1;
  color: #475569;
  font-size: 11px;
  font-weight: 800;
  padding: 6px 11px;
  border-radius: 10px;
  cursor: pointer;
  transition: all 0.15s ease;
  display: inline-flex;
  align-items: center;
  gap: 4px;
}
.btn-tour-nav-prev:disabled {
  opacity: 0.35;
  cursor: not-allowed;
  pointer-events: none;
}
.btn-tour-nav-next {
  background: linear-gradient(180deg, #f59e0b, #d97706);
  border: 2px solid #78350f;
  color: #ffffff;
  font-size: 11.5px;
  font-weight: 900;
  padding: 6px 14px;
  border-radius: 10px;
  cursor: pointer;
  box-shadow: 0 2.5px 0 #78350f;
  text-shadow: 0 1px 1px #78350f;
  display: inline-flex;
  align-items: center;
  gap: 5px;
  transition: all 0.15s ease;
}
.btn-tour-nav-next.finish {
  background: linear-gradient(180deg, #10b981, #059669);
  border-color: #064e3b;
  box-shadow: 0 2.5px 0 #064e3b;
  text-shadow: 0 1px 1px #064e3b;
}
.btn-tour-nav-next:active {
  transform: translateY(2px);
  box-shadow: 0 0 0 #78350f;
}
</style>

<!-- Fullscreen Spotlight Tour Overlay & Popover Container -->
<div id="lebah-tour-overlay">
  <div id="lebah-tour-spotlight"></div>
  <div id="lebah-tour-popover">
    <div class="tour-pop-header">
      <div class="tour-pop-track-tag">
        <span id="tour-pop-track-text">🐝 Tour Fitur Lebah</span>
      </div>
      <div style="display:flex;align-items:center;gap:8px;">
        <span class="tour-pop-step-count" id="tour-pop-step-text">1/7</span>
        <button type="button" class="tour-pop-close" onclick="window.closeGlobalTour()" title="Lewati / Tutup Tour">
          <i class="ph-bold ph-x"></i>
        </button>
      </div>
    </div>
    <div class="tour-pop-title" id="tour-pop-title-text"></div>
    <div class="tour-pop-desc" id="tour-pop-desc-text"></div>
    <div class="tour-pop-tip" id="tour-pop-tip-box" style="display:none;">
      <span id="tour-pop-tip-text"></span>
    </div>
    <div class="tour-pop-footer">
      <div class="tour-pop-dots" id="tour-pop-dots"></div>
      <div class="tour-pop-nav-btns">
        <button type="button" class="btn-tour-nav-prev" id="btn-tour-prev" onclick="window.prevGlobalTourStep()">
          <i class="ph-bold ph-arrow-left"></i> Mundur
        </button>
        <button type="button" class="btn-tour-nav-next" id="btn-tour-next" onclick="window.nextGlobalTourStep()">
          Maju <i class="ph-bold ph-arrow-right"></i>
        </button>
      </div>
    </div>
  </div>
</div>

<script src="/assets/js/toast.js"></script>
<?php
$show_wd_notif = true;
if (function_exists('is_wd_locked') && isset($pdo)) {
    if (is_wd_locked($pdo)) $show_wd_notif = false;
}
$recent_wd_list = [];
if ($show_wd_notif) {
    try {
        $wd_notif_stmt = $pdo->query("SELECT u.username, w.amount FROM withdrawals w JOIN users u ON u.id = w.user_id WHERE w.created_at >= DATE_SUB(NOW(), INTERVAL 6 HOUR) ORDER BY w.id DESC LIMIT 15");
        $recent_wd_list = $wd_notif_stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable $th) { $recent_wd_list = []; }
}
?>
<?php if ($show_wd_notif): ?>
<script>
(function() {
  let wdNotifs = <?= json_encode($recent_wd_list ?: []) ?>;
  const fakeNames = ["Andi","Budi","Cici","Dedi","Eka","Fajar","Gita","Hadi","Indra","Joko","Rina","Siti","Ayu","Dian","Fitri","Maya","Nina","Putra","Rizky","Sari","Tri","Wahyu","Yudi","Agus","Bambang","Rudi","Hendra","Iwan","Yanto","Arif","Hasan","Rizki","Nanda","Ahmad","Irfan"];
  function generateFakeWD() {
    const name = fakeNames[Math.floor(Math.random() * fakeNames.length)];
    const amount = (Math.floor(Math.random() * 24) + 2) * 10000;
    return { username: name, amount: amount };
  }
  while (wdNotifs.length < 25) wdNotifs.push(generateFakeWD());
  wdNotifs.sort(() => Math.random() - 0.5);
  if (wdNotifs && wdNotifs.length > 0) {
    function showRandomWD() {
      if (wdNotifs.length === 0) return;
      const wd = wdNotifs.pop();
      const amtStr = 'Rp ' + parseFloat(wd.amount).toLocaleString('id-ID');
      let uname = wd.username;
      uname = uname.length > 3 ? uname.substring(0,3)+'***' : uname+'***';
      if (typeof window.nToast === 'function') window.nToast(`💸 <b>${uname}</b> baru saja menarik <b>${amtStr}</b>`, 'success', 4000);
      if (wdNotifs.length > 0) setTimeout(showRandomWD, Math.floor(Math.random()*(40000-15000+1))+15000);
    }
  }
})();
</script>
<?php endif; ?>

<!-- GLOBAL SPOTLIGHT TOUR SCRIPT ENGINE -->
<script>
(function() {
  // ── 1. DEFINISI LANGKAH TOUR LENGKAP LINTAS HALAMAN (CROSS-PAGE) ──
  const GLOBAL_TOUR_CONFIG = {
    lebah: [
      {
        url: '/home',
        selector: '#tour-balance-box, .cuan-card-balance-box',
        trackName: '🐝 Tour Fitur Lebah',
        title: '<i class="ph-fill ph-wallet" style="color:#d97706;"></i> Saldo Siap Tarik (WD)',
        desc: 'Semua hasil penjualan madu murni dan dividen peternakan lebahmu akan langsung masuk ke Saldo Siap Tarik ini dan siap kamu cairkan 24 jam.',
        tip: '💡 Saldo bisa ditarik langsung ke rekening Bank atau E-Wallet (DANA, GoPay, OVO, ShopeePay).',
        btnNext: 'Menu Ternak ➔',
        pad: 6
      },
      {
        url: '/home',
        selector: '#tour-tile-farm, [href="/farm"].b-tile, .nav-item--play',
        trackName: '🐝 Tour Fitur Lebah',
        title: '<i class="ph-fill ph-drop" style="color:#16a34a;"></i> Akses Ternak Lebah',
        desc: 'Menu utama untuk mengelola kandang dan kawanan lebah pekerjamu. Dari sini lebah akan terus memproduksi madu otomatis setiap detik!',
        tip: '💡 Kamu juga bisa mengakses kebun kapan saja melalui tombol lebah di navigasi bawah.',
        btnNext: 'Buka Kebun Lebah ➔',
        pad: 8
      },
      {
        url: '/farm',
        selector: '#farm3dCanvas, .farm3d-container, .farm-ground-ui',
        trackName: '🐝 Tour Fitur Lebah',
        title: '<i class="ph-fill ph-binoculars" style="color:#fbbf24;"></i> Kandang Sarang Lebah 3D',
        desc: 'Selamat datang di kebun lebahmu! Di sini kamu bisa memantau kotak sarang kayu dan melihat lebah pekerjamu terbang aktif memproduksi madu secara otomatis.',
        tip: '💡 Geser layar untuk melihat kebun sarang dari berbagai sudut pandang sinematik!',
        btnNext: 'Lihat Tombol Panen ➔',
        pad: 4
      },
      {
        url: '/farm',
        selector: '.btn-harvest-all, .meadow-sticky-bar, .hive-quick-list',
        trackName: '🐝 Tour Fitur Lebah',
        title: '<i class="ph-fill ph-hand-heart" style="color:#f59e0b;"></i> Tombol Panen Madu',
        desc: 'Ketika sarang sudah terisi madu, tekan tombol Panen ini untuk memindahkan madu dari sarang ke gudang tokomu!',
        tip: '💡 Panen secara rutin agar kapasitas sarang tidak penuh sehingga produksi madu terus mengalir lancar.',
        btnNext: 'Buka Lapak Jual Madu ➔',
        pad: 6
      },
      {
        url: '/farm/stall',
        selector: '.stall-hero-card, .cashout-card, .stall-container',
        trackName: '🐝 Tour Fitur Lebah',
        title: '<i class="ph-fill ph-storefront" style="color:#ca8a04;"></i> Lapak Jual Madu',
        desc: 'Di Lapak Madu ini, madu yang sudah kamu panen siap dijual untuk langsung ditukarkan menjadi Uang Tunai Rupiah!',
        tip: '💡 Tingkatkan level lapakmu di Toko agar memiliki kuota penjualan madu harian yang semakin besar.',
        btnNext: 'Kunjungi Toko ➔',
        pad: 6
      },
      {
        url: '/farm/shop',
        selector: '.shop-cat-tabs, .catalog-grid, .catalog-card',
        trackName: '🐝 Tour Fitur Lebah',
        title: '<i class="ph-fill ph-shopping-bag" style="color:#0284c7;"></i> Toko Bibit & Kandang Baru',
        desc: 'Beli bibit lebah pekerja baru atau tambah kotak sarang kayu baru di sini agar produksi madumu semakin deras dan cuan berlipat ganda!',
        tip: '💡 Semakin banyak lebah pekerja dan sarang, semakin kencang passive income yang mengalir ke akunmu.',
        btnNext: 'Kembali ke Beranda ➔',
        pad: 8
      },
      {
        url: '/home',
        selector: '#tour-sidejob-card, .sidejob-3d-card',
        trackName: '🐝 Tour Fitur Lebah',
        title: '<i class="ph-fill ph-chart-line-up" style="color:#16a34a;"></i> Live Monitor Beranda',
        desc: 'Hebat! Sekarang kamu sudah menguasai seluruh alur peternakan lebah. Dari beranda depan ini kamu selalu bisa memantau sarang aktif dan produksi ml/jam secara real-time!',
        tip: '💡 Selesai! Kamu siap menjadi juragan madu terkaya di LebahCuan! 🐝🍯',
        btnNext: 'Selesai 🎉',
        pad: 8
      }
    ],
    watch: [
      {
        url: '/home',
        selector: '#tour-video-mission, .cuan-video-mission',
        trackName: '🎬 Tour Cuan Nonton',
        title: '<i class="ph-fill ph-film-strip" style="color:#f97316;"></i> Kuota Misi Video Harian',
        desc: 'Setiap hari kamu mendapatkan kuota video berbayar. Progress bar ini menampilkan berapa video yang sudah kamu selesaikan dan sisa kuota hari ini.',
        tip: '💡 Kuota direset secara otomatis setiap malam pukul 00:00 WIB.',
        btnNext: 'Lihat Tombol Nonton ➔',
        pad: 8
      },
      {
        url: '/home',
        selector: '#tour-btn-watch, [href="/videos"].btn-cuan-watch-now',
        trackName: '🎬 Tour Cuan Nonton',
        title: '<i class="ph-fill ph-play-circle" style="color:#f59e0b;"></i> Tombol Cepat Mulai Nonton',
        desc: 'Klik tombol cepat ini untuk langsung memutar video cuan dan menghasilkan saldo rupiah dari video berdurasi singkat!',
        tip: '💡 Cukup tonton video sampai hitungan mundur selesai dan saldo rupiah otomatis bertambah ke akunmu.',
        btnNext: 'Buka Galeri Video ➔',
        pad: 6
      },
      {
        url: '/videos',
        selector: '.vcard, .vhub-hero, .video-hub-page',
        trackName: '🎬 Tour Cuan Nonton',
        title: '<i class="ph-fill ph-film-reel" style="color:#ea580c;"></i> Galeri Video Pilihan',
        desc: 'Pilih aneka video menarik yang ingin kamu tonton. Setiap video dilengkapi keterangan nominal reward uang rupiah yang transparan!',
        tip: '💡 Tonton seluruh kuota video setiap hari untuk mengumpulkan saldo maksimal.',
        btnNext: 'Lanjut ke Sarang Harian ➔',
        pad: 6
      },
      {
        url: '/checkin',
        selector: '.honeycomb-hive-stage, .hex-row, .ci-streak-strip, .ci-wrap',
        trackName: '🎬 Tour Cuan Nonton',
        title: '<i class="ph-fill ph-hexagon" style="color:#f59e0b;"></i> Buka Sarang Madu Harian',
        desc: 'Klaim hadiah gratis setiap hari! Cukup 1 klik buka hexagon sarang madu untuk mendapatkan kejutan uang tunai gratis tanpa syarat.',
        tip: '💡 Check-in rutin setiap hari untuk menjaga streak bonus uang yang semakin melimpah!',
        btnNext: 'Lanjut ke Misi Cuan ➔',
        pad: 8
      },
      {
        url: '/missions',
        selector: '.ms-tabs, .ms-card',
        trackName: '🎬 Tour Cuan Nonton',
        title: '<i class="ph-fill ph-target" style="color:#8b5cf6;"></i> Misi & Tantangan Harian',
        desc: 'Dapatkan saldo tambahan berlipat dengan menyelesaikan berbagai misi harian, mingguan, dan pencapaian spesial!',
        tip: '💡 Tekan tombol Klaim begitu progress misi sudah tercapai 100%.',
        btnNext: 'Lanjut ke Tarik Saldo ➔',
        pad: 6
      },
      {
        url: '/withdraw',
        selector: '.wd-top-banner, .wd-balance-card, .wd-form',
        trackName: '🎬 Tour Cuan Nonton',
        title: '<i class="ph-fill ph-arrow-up-right" style="color:#059669;"></i> Tarik Saldo ke Rekening',
        desc: 'Klaim uang tunaimu! Tarik saldo langsung ke rekening DANA, GoPay, OVO, ShopeePay, atau Bank transfer dalam hitungan menit.',
        tip: '💡 Penarikan diproses setiap hari dengan proses pencairan cepat dan aman.',
        btnNext: 'Kembali ke Beranda ➔',
        pad: 6
      },
      {
        url: '/home',
        selector: '#tour-balance-box, .cuan-card-balance-box',
        trackName: '🎬 Tour Cuan Nonton',
        title: '<i class="ph-fill ph-sparkle" style="color:#fbbf24;"></i> Siap Mendulang Rupiah!',
        desc: 'Luar biasa! Kamu sudah menyelesaikan seluruh panduan cuan nonton video. Mulai tonton video pertamamu sekarang dan nikmati cuan yang mengalir!',
        tip: '💡 Tonton video secara rutin setiap hari untuk hasil yang maksimal! 🚀💰',
        btnNext: 'Selesai 🎉',
        pad: 6
      }
    ]
  };

  // ── 2. STATE MANAGER ──
  let isTourRunning = false;
  let currentTrack = 'lebah';
  let currentStepIndex = 0;
  let activeElement = null;
  let activeStepConfig = null;
  let rafSyncId = null;

  function normalizePath(p) {
    if (!p) return '/';
    let clean = p.split('?')[0].split('#')[0].replace(/\/+$/, '');
    return clean || '/';
  }

  function getSavedTourState() {
    try {
      const raw = localStorage.getItem('lebahcuan_tour_active');
      return raw ? JSON.parse(raw) : null;
    } catch(e) { return null; }
  }

  function setSavedTourState(track, step) {
    try {
      localStorage.setItem('lebahcuan_tour_active', JSON.stringify({
        track: track,
        step: step,
        isRunning: true,
        updatedAt: Date.now()
      }));
    } catch(e) {}
  }

  function clearSavedTourState() {
    try {
      localStorage.removeItem('lebahcuan_tour_active');
    } catch(e) {}
  }

  // ── 3. REAL-TIME SCROLL & RESIZE SYNCHRONIZER ──
  // Menyesuaikan spotlight dan popover secara instan saat layar atau menu di dalam di-scroll
  function syncSpotlightGeometry() {
    if (!isTourRunning || !activeElement || !activeStepConfig) return;
    if (rafSyncId) cancelAnimationFrame(rafSyncId);
    rafSyncId = requestAnimationFrame(() => {
      applySpotlightCoordinates(activeElement, activeStepConfig, false);
    });
  }

  // Tangkap scroll di window maupun di internal scrollable container dengan capture: true
  window.addEventListener('scroll', syncSpotlightGeometry, { passive: true, capture: true });
  window.addEventListener('resize', syncSpotlightGeometry, { passive: true });

  // ── 4. RENDER SPOTLIGHT PADA ELEMENT TARGET ──
  function applySpotlightCoordinates(el, step, isSmooth = false) {
    const spotlight = document.getElementById('lebah-tour-spotlight');
    const popover = document.getElementById('lebah-tour-popover');
    if (!spotlight || !popover) return;

    const rect = el.getBoundingClientRect();
    const pad = step.pad || 8;

    const top = Math.max(0, rect.top - pad);
    const left = Math.max(4, rect.left - pad);
    const width = Math.min(window.innerWidth - 8, rect.width + (pad * 2));
    const height = rect.height + (pad * 2);

    // Saat user scrolling, disable CSS transition agar spotlight tidak lag atau tertinggal
    spotlight.style.transition = isSmooth ? 'all 0.28s cubic-bezier(0.25, 1, 0.5, 1)' : 'none';
    popover.style.transition = isSmooth ? 'opacity 0.2s ease, transform 0.2s ease' : 'none';

    spotlight.style.top = top + 'px';
    spotlight.style.left = left + 'px';
    spotlight.style.width = width + 'px';
    spotlight.style.height = height + 'px';

    // Sesuaikan border-radius spotlight dengan bentuk asli element (bento, pill, circle, box)
    try {
      const comp = window.getComputedStyle(el);
      const radius = comp.borderRadius;
      if (radius && radius !== '0px') {
        spotlight.style.borderRadius = radius;
      } else {
        spotlight.style.borderRadius = '16px';
      }
    } catch(e) {
      spotlight.style.borderRadius = '16px';
    }

    // Posisikan popover card secara ergonomis
    positionPopoverCard(top, left, width, height, popover);
  }

  function positionPopoverCard(targetTop, targetLeft, targetWidth, targetHeight, popover) {
    const vh = window.innerHeight;
    const vw = window.innerWidth;
    const popHeight = popover.offsetHeight || 190;
    const spaceBelow = vh - (targetTop + targetHeight);
    const spaceAbove = targetTop;

    if (vw <= 480) {
      popover.style.left = '12px';
      popover.style.right = '12px';
      popover.style.width = 'calc(100vw - 24px)';
      popover.style.maxWidth = '370px';
      popover.style.margin = '0 auto';

      if (spaceBelow >= popHeight + 14) {
        popover.style.top = (targetTop + targetHeight + 10) + 'px';
        popover.style.bottom = 'auto';
      } else if (spaceAbove >= popHeight + 14) {
        popover.style.bottom = (vh - targetTop + 10) + 'px';
        popover.style.top = 'auto';
      } else {
        popover.style.bottom = '12px';
        popover.style.top = 'auto';
      }
    } else {
      popover.style.width = '360px';
      popover.style.right = 'auto';
      let centerLeft = targetLeft + (targetWidth / 2) - 180;
      centerLeft = Math.max(14, Math.min(vw - 374, centerLeft));
      popover.style.left = centerLeft + 'px';

      if (spaceBelow >= popHeight + 14) {
        popover.style.top = (targetTop + targetHeight + 12) + 'px';
        popover.style.bottom = 'auto';
      } else {
        popover.style.bottom = Math.max(12, vh - targetTop + 12) + 'px';
        popover.style.top = 'auto';
      }
    }
  }

  // ── 5. ASYNC ELEMENT FINDER DENGAN RETRY ──
  function locateElement(selector, maxTries = 12, delay = 120) {
    return new Promise((resolve) => {
      let tries = 0;
      function check() {
        const parts = selector.split(',').map(s => s.trim());
        for (let sel of parts) {
          try {
            const el = document.querySelector(sel);
            if (el) return resolve(el);
          } catch(e) {}
        }
        tries++;
        if (tries >= maxTries) return resolve(null);
        setTimeout(check, delay);
      }
      check();
    });
  }

  // ── 6. RENDER LANGKAH AKTIF ──
  async function renderActiveStep() {
    const steps = GLOBAL_TOUR_CONFIG[currentTrack];
    if (!steps || !steps[currentStepIndex]) {
      window.finishGlobalTour();
      return;
    }

    const step = steps[currentStepIndex];
    activeStepConfig = step;

    // Cek apakah langkah ini membutuhkan navigasi ke halaman lain
    const currentPath = normalizePath(window.location.pathname);
    const targetPath = normalizePath(step.url);

    if (currentPath !== targetPath) {
      // Simpan state tour dan redirect ke halaman yang sesuai
      setSavedTourState(currentTrack, currentStepIndex);
      window.location.href = step.url;
      return;
    }

    // Jika sudah di halaman yang benar, simpan state
    setSavedTourState(currentTrack, currentStepIndex);

    // Tampilkan container overlay
    const overlay = document.getElementById('lebah-tour-overlay');
    const spotlight = document.getElementById('lebah-tour-spotlight');
    const popover = document.getElementById('lebah-tour-popover');
    if (overlay) overlay.style.display = 'block';

    // Cari element di halaman
    const el = await locateElement(step.selector);
    activeElement = el || document.body;

    // Scroll element target ke tengah pandangan secara halus
    if (el && el !== document.body) {
      try {
        el.scrollIntoView({ behavior: 'smooth', block: 'center', inline: 'nearest' });
      } catch(e) {}
    }

    // Isi konten teks popover
    document.getElementById('tour-pop-track-text').textContent = step.trackName;
    document.getElementById('tour-pop-step-text').textContent = `${currentStepIndex + 1}/${steps.length}`;
    document.getElementById('tour-pop-title-text').innerHTML = step.title;
    document.getElementById('tour-pop-desc-text').textContent = step.desc;

    const tipBox = document.getElementById('tour-pop-tip-box');
    if (step.tip) {
      tipBox.style.display = 'flex';
      document.getElementById('tour-pop-tip-text').textContent = step.tip;
    } else {
      tipBox.style.display = 'none';
    }

    // Render step indicator dots
    const dotsContainer = document.getElementById('tour-pop-dots');
    dotsContainer.innerHTML = '';
    for (let i = 0; i < steps.length; i++) {
      const d = document.createElement('span');
      d.className = 'tour-pop-dot' + (i === currentStepIndex ? ' active' : '');
      dotsContainer.appendChild(d);
    }

    // Tombol Mundur
    const prevBtn = document.getElementById('btn-tour-prev');
    prevBtn.disabled = (currentStepIndex === 0);

    // Tombol Maju
    const nextBtn = document.getElementById('btn-tour-next');
    if (currentStepIndex === steps.length - 1) {
      nextBtn.className = 'btn-tour-nav-next finish';
      nextBtn.innerHTML = 'Selesai 🎉';
    } else {
      nextBtn.className = 'btn-tour-nav-next';
      nextBtn.innerHTML = (step.btnNext || 'Maju ➔');
    }

    popover.style.display = 'block';

    // Berikan sedikit jeda untuk kalkulasi posisi setelah layout/scroll selesai
    setTimeout(() => {
      applySpotlightCoordinates(activeElement, step, true);
      if (spotlight) spotlight.classList.add('active');
      if (popover) popover.classList.add('active');
    }, 220);
  }

  // ── 7. KONTROL TOUR PUBLIK (WINDOW EXPOSURE) ──
  window.startGlobalTour = function(track = 'lebah', stepIndex = 0) {
    currentTrack = (track === 'watch') ? 'watch' : 'lebah';
    currentStepIndex = stepIndex;
    isTourRunning = true;

    const steps = GLOBAL_TOUR_CONFIG[currentTrack];
    const targetPath = normalizePath(steps[currentStepIndex].url);
    const currentPath = normalizePath(window.location.pathname);

    if (currentPath !== targetPath) {
      setSavedTourState(currentTrack, currentStepIndex);
      window.location.href = steps[currentStepIndex].url;
      return;
    }

    renderActiveStep();
  };

  window.nextGlobalTourStep = function() {
    const steps = GLOBAL_TOUR_CONFIG[currentTrack];
    if (!steps) return;
    if (currentStepIndex < steps.length - 1) {
      currentStepIndex++;
      const nextStep = steps[currentStepIndex];
      const currentPath = normalizePath(window.location.pathname);
      const nextPath = normalizePath(nextStep.url);

      if (currentPath !== nextPath) {
        setSavedTourState(currentTrack, currentStepIndex);
        window.location.href = nextStep.url;
      } else {
        renderActiveStep();
      }
    } else {
      window.finishGlobalTour();
    }
  };

  window.prevGlobalTourStep = function() {
    if (currentStepIndex > 0) {
      currentStepIndex--;
      const prevStep = GLOBAL_TOUR_CONFIG[currentTrack][currentStepIndex];
      const currentPath = normalizePath(window.location.pathname);
      const prevPath = normalizePath(prevStep.url);

      if (currentPath !== prevPath) {
        setSavedTourState(currentTrack, currentStepIndex);
        window.location.href = prevStep.url;
      } else {
        renderActiveStep();
      }
    }
  };

  window.closeGlobalTour = function() {
    isTourRunning = false;
    clearSavedTourState();

    const overlay = document.getElementById('lebah-tour-overlay');
    const spotlight = document.getElementById('lebah-tour-spotlight');
    const popover = document.getElementById('lebah-tour-popover');

    if (spotlight) spotlight.classList.remove('active');
    if (popover) popover.classList.remove('active');
    setTimeout(() => {
      if (overlay) overlay.style.display = 'none';
      if (popover) popover.style.display = 'none';
    }, 200);
  };

  window.finishGlobalTour = function() {
    window.closeGlobalTour();
    try {
      localStorage.setItem('lebahcuan_tour_done', '1');
      const b = document.getElementById('tour-invite-card');
      if (b) b.style.display = 'none';
    } catch(e) {}

    if (typeof window.nToast === 'function') {
      window.nToast('🎉 Selamat! Kamu telah menguasai panduan LebahCuan dan siap mendulang rupiah!', 'success', 5000);
    }
  };

  // Keyboard Navigation: ArrowRight (Maju), ArrowLeft (Mundur), Escape (Tutup)
  window.addEventListener('keydown', (e) => {
    if (!isTourRunning) return;
    if (e.key === 'ArrowRight') {
      window.nextGlobalTourStep();
    } else if (e.key === 'ArrowLeft') {
      window.prevGlobalTourStep();
    } else if (e.key === 'Escape') {
      window.closeGlobalTour();
    }
  });

  // ── 8. AUTO-RESUME TOUR SAAT PINDAH HALAMAN ──
  document.addEventListener('DOMContentLoaded', () => {
    const saved = getSavedTourState();
    if (saved && saved.isRunning) {
      currentTrack = saved.track || 'lebah';
      currentStepIndex = parseInt(saved.step, 10) || 0;
      isTourRunning = true;

      // Tunggu layout dan canvas 3D siap render
      setTimeout(() => {
        renderActiveStep();
      }, 300);
    }
  });
})();
</script>
</body>
</html>
