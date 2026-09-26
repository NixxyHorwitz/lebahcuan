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

  /* CENTER PLAY BUTTON (HEXAGONAL 3D NOTCH) */
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
    margin-top: -28px; /* Elevated above floating dock */
  }
  /* Hexagonal Cradle Base */
  .nav-hex-cradle {
    position: absolute;
    top: -5px;
    left: 50%;
    transform: translateX(-50%);
    width: 66px;
    height: 66px;
    background: #ffffff;
    border: 2px solid #78350f;
    clip-path: polygon(50% 0%, 93% 25%, 93% 75%, 50% 100%, 7% 75%, 7% 25%);
    box-shadow: 0 4px 10px rgba(120,53,15,0.15);
    z-index: 1;
  }
  .nav-play-btn {
    width: 56px; height: 56px;
    background: linear-gradient(135deg, #fde047 0%, #f59e0b 50%, #d97706 100%) !important;
    border: 2.5px solid #78350f !important;
    clip-path: polygon(50% 0%, 93% 25%, 93% 75%, 50% 100%, 7% 75%, 7% 25%);
    display: flex; align-items: center; justify-content: center;
    position: relative;
    z-index: 2;
    filter: drop-shadow(0 3px 0 #78350f);
    transition: transform 0.15s, filter 0.15s;
  }
  .nav-item--play:active .nav-play-btn {
    transform: translateY(3px) scale(0.95);
    filter: drop-shadow(0 1px 0 #78350f);
  }
  .nav-item--play.active .nav-play-btn {
    background: linear-gradient(135deg, #fffbeb, #fbbf24) !important;
    filter: drop-shadow(0 3px 0 #78350f) drop-shadow(0 0 10px rgba(245,158,11,0.5));
  }
  .nav-play-btn img {
    width: 30px; height: 30px;
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
  }
  .nav-item--play.active .nav-play-label {
    color: #d97706;
  }

  /* Floating contact adjustment */
  .float-contact-wrap {
    bottom: 88px !important;
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

    <!-- Center: SIDEJOB TERNAK LEBAH button (Floating Hexagon) -->
    <a href="/farm" class="nav-item nav-item--play <?= ($activePage??'')==='farm'?'active':'' ?>">
      <div class="nav-play-wrap">
        <div class="nav-hex-cradle"></div>
        <div class="nav-play-btn">
          <img src="/assets/game/bee_worker.png" alt="Ternak Lebah">
        </div>
        <span class="nav-play-label">Ternak Lebah</span>
      </div>
    </a>

    <a href="/referral" class="nav-item <?= ($activePage??'')==='referral'?'active':'' ?>">
      <i class="<?= ($activePage??'')==='referral'?'ph-fill':'ph-bold' ?> ph-users-three"></i>
      Squad
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
    // setTimeout(showRandomWD, Math.floor(Math.random()*6000)+3000);
  }
})();
</script>
<?php endif; ?>
</body>
</html>
