<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

$user = require_auth($pdo);

if (is_maintenance($pdo) && !auth_admin()) {
    $maintenance_msg = setting($pdo, 'maintenance_message', 'Sistem sedang dalam perbaikan.');
    require dirname(__DIR__) . '/user/maintenance.php';
    exit;
}

track_pageview($pdo, parse_url($_SERVER['REQUEST_URI'] ?? '/farm/shop', PHP_URL_PATH));

// Fetch fresh user data
$uStmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$uStmt->execute([$user['id']]);
$user = $uStmt->fetch(PDO::FETCH_ASSOC);

// Fetch catalogs
$bee_masters   = $pdo->query("SELECT * FROM bee_types_master WHERE is_active = 1 ORDER BY sort_order ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);
$hive_masters  = $pdo->query("SELECT * FROM bee_hives_master WHERE is_active = 1 ORDER BY sort_order ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);
$stall_masters = $pdo->query("SELECT * FROM bee_stalls_master WHERE is_active = 1 ORDER BY tier_level ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);

// Fetch user's active hives with slot calculation for bee assignment
$user_hives = $pdo->prepare("
    SELECT h.*, m.name as master_name, m.max_slots,
           (SELECT COUNT(*) FROM user_bees WHERE hive_id = h.id AND is_active = 1 AND (expires_at IS NULL OR expires_at > NOW())) as bee_count
    FROM user_bee_hives h
    JOIN bee_hives_master m ON m.id = h.hive_master_id
    WHERE h.user_id = ? AND h.is_active = 1 AND (h.expires_at IS NULL OR h.expires_at > NOW())
    ORDER BY h.id ASC
");
$user_hives->execute([$user['id']]);
$user_hives = $user_hives->fetchAll(PDO::FETCH_ASSOC);

$tab = $_GET['tab'] ?? 'bees';

$pageTitle = 'Toko Peternakan Tycoon — Lebah Cuan';
$activePage = 'farm';
$farmSubPage = 'shop';
require dirname(__DIR__) . '/partials/header.php';
?>

<style>
body { background: #fef3c7 !important; font-family: 'Nunito', sans-serif; }
.shop-container { padding: 16px 14px 120px; max-width: 480px; margin: 0 auto; }

/* Shop Segmented Control */
.shop-cat-tabs {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 8px;
  margin-bottom: 18px;
}
.btn-cat-tab {
  background: #ffffff;
  border: 2.5px solid #78350f;
  border-radius: 14px;
  padding: 10px 4px;
  text-align: center;
  font-size: 12px; font-weight: 900;
  color: #78350f;
  text-decoration: none;
  box-shadow: 0 3px 0 #78350f;
  display: flex; flex-direction: column; align-items: center; gap: 4px;
  transition: transform 0.1s;
}
.btn-cat-tab.active {
  background: linear-gradient(135deg, #f59e0b, #d97706);
  color: #ffffff;
  box-shadow: 0 3px 0 #78350f;
  transform: translateY(-2px);
}
.btn-cat-tab i { font-size: 20px; }

/* Item Catalog Cards */
.catalog-grid {
  display: flex;
  flex-direction: column;
  gap: 14px;
}
.catalog-card {
  background: #ffffff;
  border: 3.5px solid #78350f;
  border-radius: 20px;
  box-shadow: 0 5px 0 #78350f;
  padding: 14px;
  display: flex;
  align-items: center;
  gap: 12px;
}
.catalog-sprite-box {
  width: 72px; height: 72px;
  background: radial-gradient(circle, #fef08a 0%, #fde047 70%, #ca8a04 100%);
  border: 3px solid #78350f;
  border-radius: 16px;
  box-shadow: 0 3px 0 #78350f;
  display: flex; align-items: center; justify-content: center;
  flex-shrink: 0;
}
.catalog-sprite-box img {
  width: 54px; height: 54px; object-fit: contain;
}
.catalog-info {
  flex: 1;
}
.catalog-title {
  font-size: 14px; font-weight: 900; color: #1e293b;
  line-height: 1.2; margin-bottom: 3px;
}
.catalog-sub {
  font-size: 10px; font-weight: 700; color: #64748b;
  margin-bottom: 6px;
}
.catalog-stat-badge {
  display: inline-flex; align-items: center; gap: 4px;
  background: #f0fdf4; border: 1.5px solid #16a34a;
  border-radius: 8px; padding: 2px 6px;
  font-size: 10px; font-weight: 900; color: #15803d;
}
.catalog-price-val {
  font-size: 14px; font-weight: 900; color: #b45309;
  margin-top: 4px;
}

.btn-buy-action {
  background: linear-gradient(135deg, #f59e0b, #d97706);
  border: 2.5px solid #78350f;
  border-radius: 12px;
  padding: 8px 12px;
  font-size: 12px; font-weight: 900; color: #fff;
  box-shadow: 0 3px 0 #78350f;
  cursor: pointer;
  font-family: 'Nunito', sans-serif;
  transition: transform 0.1s;
  flex-shrink: 0;
}
.btn-buy-action:active {
  transform: translateY(2px);
  box-shadow: 0 1px 0 #78350f;
}
.btn-buy-action:disabled {
  background: #cbd5e1;
  border-color: #94a3b8;
  color: #64748b;
  box-shadow: none;
  cursor: not-allowed;
}

/* Hive selector select dropdown */
.hive-select-box {
  margin-top: 6px;
  background: #f8fafc;
  border: 2px solid #78350f;
  border-radius: 10px;
  padding: 4px 8px;
  font-size: 11px; font-weight: 800; color: #334155;
  outline: none; width: 100%;
}
</style>

<?php require __DIR__ . '/partials/farm_header.php'; ?>

<div class="shop-container">

  <!-- Category Filter Tabs -->
  <div class="shop-cat-tabs">
    <a href="/farm/shop?tab=bees" class="btn-cat-tab <?= $tab === 'bees' ? 'active' : '' ?>">
      <i class="ph-fill ph-flower-lotus"></i>
      <span>Bibit Lebah</span>
    </a>
    <a href="/farm/shop?tab=hives" class="btn-cat-tab <?= $tab === 'hives' ? 'active' : '' ?>">
      <i class="ph-fill ph-house-line"></i>
      <span>Kandang Baru</span>
    </a>
    <a href="/farm/shop?tab=stalls" class="btn-cat-tab <?= $tab === 'stalls' ? 'active' : '' ?>">
      <i class="ph-fill ph-storefront"></i>
      <span>Sewa Lapak</span>
    </a>
  </div>

  <!-- TAB 1: BIBIT LEBAH -->
  <?php if ($tab === 'bees'): ?>
    <div class="catalog-grid">
      <div style="font-size:12px;font-weight:800;color:#78350f;margin-bottom:-4px;">
        🐝 Beli bibit lebah untuk ditempatkan di sarang aktif kamu:
      </div>

      <?php foreach ($bee_masters as $bm): ?>
        <div class="catalog-card">
          <div class="catalog-sprite-box">
            <img src="<?= htmlspecialchars($bm['image']) ?>" alt="Bee">
          </div>
          <div class="catalog-info">
            <div class="catalog-title"><?= htmlspecialchars($bm['name']) ?></div>
            <div class="catalog-stat-badge">
              <i class="ph-fill ph-drop"></i> +<?= number_format((float)$bm['honey_per_hour'], 0) ?> ml/jam
            </div>
            <div class="catalog-price-val">
              Rp <?= number_format((float)$bm['price'], 0, ',', '.') ?>
            </div>

            <!-- Hive Target Dropdown -->
            <?php if (!empty($user_hives)): ?>
              <select id="targetHive_<?= $bm['id'] ?>" class="hive-select-box">
                <?php foreach ($user_hives as $uh): 
                  $remSlots = (int)$uh['max_slots'] - (int)$uh['bee_count'];
                ?>
                  <option value="<?= $uh['id'] ?>" <?= $remSlots <= 0 ? 'disabled' : '' ?>>
                    <?= htmlspecialchars($uh['master_name']) ?> (Sisa: <?= $remSlots ?> Slot)
                  </option>
                <?php endforeach; ?>
              </select>
            <?php else: ?>
              <div style="font-size:9.5px;color:#ef4444;font-weight:800;margin-top:4px;">
                Belum ada kandang! Beli kandang terlebih dahulu.
              </div>
            <?php endif; ?>
          </div>

          <button type="button" class="btn-buy-action" 
                  onclick="buyBee(<?= $bm['id'] ?>, '<?= addslashes($bm['name']) ?>', <?= (float)$bm['price'] ?>)"
                  <?= empty($user_hives) ? 'disabled' : '' ?>>
            Beli
          </button>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <!-- TAB 2: KANDANG LEBAH -->
  <?php if ($tab === 'hives'): ?>
    <div class="catalog-grid">
      <div style="font-size:12px;font-weight:800;color:#78350f;margin-bottom:-4px;">
        🏡 Tambah kandang untuk memperluas kapasitas koloni lebah:
      </div>

      <?php foreach ($hive_masters as $hm): ?>
        <div class="catalog-card">
          <div class="catalog-sprite-box">
            <img src="<?= htmlspecialchars($hm['image']) ?>" alt="Hive">
          </div>
          <div class="catalog-info">
            <div class="catalog-title"><?= htmlspecialchars($hm['name']) ?></div>
            <div style="display:flex;gap:4px;flex-wrap:wrap;margin-bottom:3px;">
              <span class="catalog-stat-badge">
                <i class="ph-bold ph-users"></i> Kapasitas: <?= $hm['max_slots'] ?> Lebah
              </span>
              <?php if ((int)$hm['bonus_speed_pct'] > 0): ?>
                <span class="catalog-stat-badge" style="background:#fef3c7;border-color:#d97706;color:#b45309;">
                  +<?= $hm['bonus_speed_pct'] ?>% Kecepatan
                </span>
              <?php endif; ?>
            </div>
            <div style="font-size:9.5px;font-weight:700;color:#64748b;">
              Masa aktif: <?= $hm['duration_days'] ?> Hari
            </div>
            <div class="catalog-price-val">
              Rp <?= number_format((float)$hm['price'], 0, ',', '.') ?>
            </div>
          </div>

          <button type="button" class="btn-buy-action" onclick="buyHive(<?= $hm['id'] ?>, '<?= addslashes($hm['name']) ?>', <?= (float)$hm['price'] ?>)">
            Beli
          </button>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <!-- TAB 3: SEWA LAPAK MADU -->
  <?php if ($tab === 'stalls'): ?>
    <div class="catalog-grid">
      <div style="font-size:12px;font-weight:800;color:#78350f;margin-bottom:-4px;">
        🏪 Tingkatkan lapak agar harga jual madu lebih mahal & kuota harian bertambah:
      </div>

      <?php foreach ($stall_masters as $sm): ?>
        <div class="catalog-card">
          <div class="catalog-sprite-box">
            <img src="<?= htmlspecialchars($sm['image']) ?>" alt="Stall">
          </div>
          <div class="catalog-info">
            <div class="catalog-title"><?= htmlspecialchars($sm['name']) ?></div>
            <div style="display:flex;gap:4px;flex-wrap:wrap;margin-bottom:3px;">
              <span class="catalog-stat-badge">
                <i class="ph-fill ph-tag"></i> Rp <?= number_format((float)$sm['sell_price_per_ml'], 0) ?>/ml
              </span>
              <span class="catalog-stat-badge" style="background:#fef3c7;border-color:#d97706;color:#b45309;">
                Maks <?= number_format((float)$sm['daily_max_ml'], 0) ?> ml/hari
              </span>
            </div>
            <div style="font-size:9.5px;font-weight:700;color:#64748b;">
              Masa sewa: <?= $sm['duration_days'] ?> Hari
            </div>
            <div class="catalog-price-val">
              Rp <?= number_format((float)$sm['price'], 0, ',', '.') ?>
            </div>
          </div>

          <button type="button" class="btn-buy-action" onclick="buyStall(<?= $sm['id'] ?>, '<?= addslashes($sm['name']) ?>', <?= (float)$sm['price'] ?>)">
            Sewa
          </button>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

</div>

<!-- CSRF Field -->
<?= csrf_field() ?>

<script>
const CSRF_TOKEN = document.querySelector('input[name="_csrf"]')?.value || '';
const USER_BAL_DEP = <?= (float)$user['balance_dep'] ?>;

// Custom branded confirm dialog (replaces native confirm())
function cuanConfirm(msg) {
  return new Promise((resolve) => {
    // Remove old dialog if exists
    const old = document.getElementById('cuanConfirmOverlay');
    if (old) old.remove();

    const overlay = document.createElement('div');
    overlay.id = 'cuanConfirmOverlay';
    overlay.style.cssText = 'position:fixed;top:0;left:0;right:0;bottom:0;z-index:99999;display:flex;align-items:center;justify-content:center;background:rgba(0,0,0,0.6);backdrop-filter:blur(6px);animation:fadeIn .2s';
    
    const box = document.createElement('div');
    box.style.cssText = 'background:linear-gradient(135deg,#1a2e05,#0f2a16);border:2px solid rgba(251,191,36,0.3);border-radius:20px;padding:24px 20px;max-width:320px;width:90%;text-align:center;box-shadow:0 12px 40px rgba(0,0,0,0.5);animation:scaleIn .25s cubic-bezier(.34,1.56,.64,1)';
    
    box.innerHTML = '<div style="font-size:32px;margin-bottom:10px">🐝</div>' +
      '<div style="font-size:13px;font-weight:800;color:#fef3c7;line-height:1.5;margin-bottom:18px">' + msg + '</div>' +
      '<div style="display:flex;gap:10px;justify-content:center">' +
        '<button id="cuanConfirmNo" style="flex:1;padding:10px;border-radius:12px;border:2px solid rgba(255,255,255,0.15);background:rgba(255,255,255,0.08);color:#e2e8f0;font-size:13px;font-weight:800;cursor:pointer;font-family:inherit">Batal</button>' +
        '<button id="cuanConfirmYes" style="flex:1;padding:10px;border-radius:12px;border:2px solid #78350f;background:linear-gradient(135deg,#f59e0b,#d97706);color:#fff;font-size:13px;font-weight:800;cursor:pointer;box-shadow:0 3px 0 #78350f;font-family:inherit">Ya, Lanjut</button>' +
      '</div>';
    
    overlay.appendChild(box);
    document.body.appendChild(overlay);
    
    document.getElementById('cuanConfirmYes').onclick = () => { overlay.remove(); resolve(true); };
    document.getElementById('cuanConfirmNo').onclick = () => { overlay.remove(); resolve(false); };
    overlay.onclick = (e) => { if (e.target === overlay) { overlay.remove(); resolve(false); } };
  });
}

// Buy Bee
async function buyBee(beeId, beeName, price) {
  if (USER_BAL_DEP < price) {
    const go = await cuanConfirm('Saldo Beli kamu tidak mencukupi (Harga: Rp ' + price.toLocaleString('id-ID') + '). Mau isi saldo sekarang?');
    if (go) window.location.href = '/deposit';
    return;
  }

  const selectEl = document.getElementById('targetHive_' + beeId);
  const targetHiveId = selectEl ? selectEl.value : 0;
  if (!targetHiveId) {
    if (typeof nToast === 'function') nToast('Pilih kandang tujuan yang masih memiliki slot kosong.', 'warn');
    return;
  }

  const ok = await cuanConfirm('Beli ' + beeName + ' seharga Rp ' + price.toLocaleString('id-ID') + '?');
  if (!ok) return;

  FarmAudio.playPop();

  const fd = new FormData();
  fd.append('action', 'buy_bee');
  fd.append('bee_type_id', beeId);
  fd.append('target_hive_id', targetHiveId);
  fd.append('_csrf', CSRF_TOKEN);

  fetch('/api/farm_action', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(data => {
      if (data.ok) {
        if (typeof nToast === 'function') nToast(data.msg, 'success');
        setTimeout(() => { window.location.href = '/farm'; }, 1200);
      } else {
        if (typeof nToast === 'function') nToast(data.msg || 'Gagal membeli bibit lebah.', 'error');
      }
    })
    .catch(() => { if (typeof nToast === 'function') nToast('Terjadi kendala jaringan.', 'error'); });
}

// Buy Hive
async function buyHive(hiveMasterId, hiveName, price) {
  if (USER_BAL_DEP < price) {
    const go = await cuanConfirm('Saldo Beli kamu tidak mencukupi (Harga: Rp ' + price.toLocaleString('id-ID') + '). Mau isi saldo sekarang?');
    if (go) window.location.href = '/deposit';
    return;
  }

  const ok = await cuanConfirm('Beli kandang ' + hiveName + ' seharga Rp ' + price.toLocaleString('id-ID') + '?');
  if (!ok) return;

  FarmAudio.playPop();

  const fd = new FormData();
  fd.append('action', 'buy_hive');
  fd.append('hive_master_id', hiveMasterId);
  fd.append('_csrf', CSRF_TOKEN);

  fetch('/api/farm_action', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(data => {
      if (data.ok) {
        if (typeof nToast === 'function') nToast(data.msg, 'success');
        setTimeout(() => { window.location.href = '/farm'; }, 1200);
      } else {
        if (typeof nToast === 'function') nToast(data.msg || 'Gagal membeli kandang.', 'error');
      }
    })
    .catch(() => { if (typeof nToast === 'function') nToast('Terjadi kendala jaringan.', 'error'); });
}

// Buy / Lease Stall
async function buyStall(stallMasterId, stallName, price) {
  if (USER_BAL_DEP < price) {
    const go = await cuanConfirm('Saldo Beli kamu tidak mencukupi (Harga: Rp ' + price.toLocaleString('id-ID') + '). Mau isi saldo sekarang?');
    if (go) window.location.href = '/deposit';
    return;
  }

  const ok = await cuanConfirm('Sewa/Upgrade ke ' + stallName + ' seharga Rp ' + price.toLocaleString('id-ID') + '?');
  if (!ok) return;

  FarmAudio.playPop();

  const fd = new FormData();
  fd.append('action', 'buy_stall');
  fd.append('stall_master_id', stallMasterId);
  fd.append('_csrf', CSRF_TOKEN);

  fetch('/api/farm_action', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(data => {
      if (data.ok) {
        if (typeof nToast === 'function') nToast(data.msg, 'success');
        setTimeout(() => { window.location.href = '/farm/stall'; }, 1200);
      } else {
        if (typeof nToast === 'function') nToast(data.msg || 'Gagal menyewa lapak.', 'error');
      }
    })
    .catch(() => { if (typeof nToast === 'function') nToast('Terjadi kendala jaringan.', 'error'); });
}
</script>

<?php require dirname(__DIR__) . '/partials/footer.php'; ?>
