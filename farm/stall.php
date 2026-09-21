<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

$user = require_auth($pdo);

if (is_maintenance($pdo) && !auth_admin()) {
    $maintenance_msg = setting($pdo, 'maintenance_message', 'Sistem sedang dalam perbaikan.');
    require dirname(__DIR__) . '/user/maintenance.php';
    exit;
}

track_pageview($pdo, parse_url($_SERVER['REQUEST_URI'] ?? '/farm/stall', PHP_URL_PATH));

// Fetch fresh user data
$uStmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$uStmt->execute([$user['id']]);
$user = $uStmt->fetch(PDO::FETCH_ASSOC);

// Fetch user active stall
$active_stall = BeeFarm::getUserActiveStall($pdo, (int)$user['id']);

// All stall tiers available for upgrade info
$all_stalls = $pdo->query("SELECT * FROM bee_stalls_master WHERE is_active = 1 ORDER BY tier_level ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);

// Recent sales log
$sales_logs_stmt = $pdo->prepare("
    SELECT l.*, m.name as stall_name, m.tier_level
    FROM bee_sales_logs l
    JOIN user_bee_stalls s ON s.id = l.stall_id
    JOIN bee_stalls_master m ON m.id = s.stall_master_id
    WHERE l.user_id = ?
    ORDER BY l.id DESC LIMIT 5
");
$sales_logs_stmt->execute([$user['id']]);
$recent_sales = $sales_logs_stmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Lapak Jual Madu — Peternakan Lebah Cuan';
$activePage = 'farm';
$farmSubPage = 'stall';
require dirname(__DIR__) . '/partials/header.php';
?>

<style>
body { background: #fef3c7 !important; font-family: 'Nunito', sans-serif; }
.stall-container { padding: 16px 14px 120px; max-width: 480px; margin: 0 auto; }

/* Market Stall Hero */
.stall-hero-card {
  background: #ffffff;
  border: 3.5px solid #78350f;
  border-radius: 24px;
  box-shadow: 0 6px 0 #78350f;
  padding: 16px;
  margin-bottom: 18px;
  position: relative;
  overflow: hidden;
}
.stall-hero-card::before {
  content: '';
  position: absolute;
  top: 0; left: 0; right: 0;
  height: 8px;
  background: repeating-linear-gradient(45deg, #f59e0b, #f59e0b 15px, #d97706 15px, #d97706 30px);
}

.stall-stand-row {
  display: flex;
  align-items: center;
  gap: 14px;
  margin-top: 6px;
  margin-bottom: 14px;
}
.stall-sprite-box {
  width: 72px; height: 72px;
  background: #fef08a;
  border: 3px solid #78350f;
  border-radius: 18px;
  box-shadow: 0 4px 0 #78350f;
  display: flex; align-items: center; justify-content: center;
  flex-shrink: 0;
}
.stall-sprite-box img {
  width: 54px; height: 54px; object-fit: contain;
}
.stall-title-badge {
  font-size: 16px; font-weight: 900; color: #78350f;
  line-height: 1.2; margin-bottom: 2px;
}
.stall-tier-pill {
  display: inline-flex; align-items: center; gap: 4px;
  background: #fef3c7; border: 1.5px solid #d97706;
  border-radius: 10px; padding: 2px 8px;
  font-size: 10.5px; font-weight: 900; color: #b45309;
}

/* Quota Bar */
.stall-quota-box {
  background: #f8fafc;
  border: 2.5px solid #78350f;
  border-radius: 16px;
  padding: 12px;
  margin-bottom: 14px;
}
.quota-bar-track {
  height: 14px;
  background: #e2e8f0;
  border: 2px solid #78350f;
  border-radius: 10px;
  overflow: hidden;
  margin-top: 6px;
}
.quota-bar-fill {
  height: 100%;
  background: linear-gradient(90deg, #10b981, #059669);
  border-radius: 6px;
  transition: width 0.3s;
}

/* Cashout Console */
.cashout-card {
  background: #ffffff;
  border: 3.5px solid #065f46;
  border-radius: 24px;
  box-shadow: 0 6px 0 #065f46;
  padding: 16px;
  margin-bottom: 18px;
}
.cashout-title {
  font-size: 15px; font-weight: 900; color: #065f46;
  display: flex; align-items: center; gap: 6px;
  margin-bottom: 12px;
}
.cashout-input-box {
  background: #f0fdf4;
  border: 2.5px solid #059669;
  border-radius: 16px;
  padding: 12px;
  margin-bottom: 12px;
}
.cashout-input-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
}
.cashout-input-field {
  width: 100%;
  border: none;
  background: transparent;
  font-size: 22px; font-weight: 900; color: #065f46;
  font-family: 'Nunito', sans-serif;
  outline: none;
}
.cashout-unit-label {
  font-size: 14px; font-weight: 900; color: #059669;
}

/* Quick chips */
.cashout-chips {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 6px;
  margin-bottom: 14px;
}
.btn-chip {
  background: #ecfdf5;
  border: 2px solid #059669;
  border-radius: 10px;
  padding: 6px 2px;
  font-size: 11px; font-weight: 900; color: #065f46;
  cursor: pointer;
  text-align: center;
  transition: transform 0.1s;
}
.btn-chip:active {
  transform: translateY(2px);
  background: #a7f3d0;
}

/* Estimated revenue box */
.revenue-preview-box {
  background: #fefce8;
  border: 2px dashed #ca8a04;
  border-radius: 14px;
  padding: 10px 12px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 14px;
}
.revenue-val {
  font-size: 17px; font-weight: 900; color: #15803d;
}

.btn-sell-madu {
  width: 100%;
  background: linear-gradient(135deg, #10b981 0%, #059669 100%);
  border: 3px solid #064e3b;
  border-radius: 16px;
  box-shadow: 0 5px 0 #064e3b;
  padding: 13px;
  font-size: 15px; font-weight: 900; color: #fff;
  display: flex; align-items: center; justify-content: center; gap: 8px;
  cursor: pointer;
  font-family: 'Nunito', sans-serif;
  transition: transform 0.1s;
}
.btn-sell-madu:active {
  transform: translateY(3px);
  box-shadow: 0 2px 0 #064e3b;
}
.btn-sell-madu:disabled {
  background: #cbd5e1;
  border-color: #64748b;
  color: #475569;
  box-shadow: none;
  cursor: not-allowed;
  transform: none;
}
</style>

<?php require __DIR__ . '/partials/farm_header.php'; ?>

<div class="stall-container">

  <?php if (!$active_stall): ?>
    <!-- Belum Punya Lapak -->
    <div class="stall-hero-card" style="text-align:center;padding:24px 16px;">
      <div style="font-size:48px;margin-bottom:10px;">🏪</div>
      <div style="font-size:18px;font-weight:900;color:#78350f;margin-bottom:6px;">Kamu Belum Memiliki Lapak Madu!</div>
      <div style="font-size:12px;font-weight:700;color:#64748b;line-height:1.4;margin-bottom:16px;">
        Sewa atau beli lapak madu di Toko agar kamu bisa mencairkan hasil panen madu menjadi Saldo Penarikan (Rupiah).
      </div>
      <a href="/farm/shop" class="btn-sell-madu" style="text-decoration:none;display:inline-flex;width:auto;padding:10px 24px;">
        <i class="ph-fill ph-shopping-cart"></i> Sewa Lapak Sekarang
      </a>
    </div>
  <?php else: 
    $pricePerMl = (float)$active_stall['sell_price_per_ml'];
    $dailyMax   = (float)$active_stall['daily_max_ml'];
    $dailySold  = (float)$active_stall['daily_sold_today'];
    $dailyRem   = (float)$active_stall['daily_remaining'];
    $quotaPct   = (float)$active_stall['quota_percentage'];
    $userStock  = (float)$user['honey_stock'];
    $maxSellable = max(0.0, min($userStock, $dailyRem));
  ?>
    <!-- Info Lapak Aktif -->
    <div class="stall-hero-card">
      <div class="stall-stand-row">
        <div class="stall-sprite-box">
          <img src="<?= htmlspecialchars($active_stall['tier_image']) ?>" onerror="this.src='/assets/game/stall_wood.png'" alt="Stall">
        </div>
        <div style="flex:1;">
          <div class="stall-title-badge"><?= htmlspecialchars($active_stall['tier_name']) ?></div>
          <div class="stall-tier-pill">
            <i class="ph-fill ph-tag"></i> Harga Jual: <strong>Rp <?= number_format($pricePerMl, 0, ',', '.') ?> / ml</strong>
          </div>
          <div style="font-size:10px;font-weight:700;color:#64748b;margin-top:3px;">
            Masa aktif: <?= htmlspecialchars((string)$active_stall['expires_at']) ?>
          </div>
        </div>
      </div>

      <!-- Sisa Kuota Penjualan Hari Ini -->
      <div class="stall-quota-box">
        <div style="display:flex;justify-content:space-between;font-size:11px;font-weight:900;">
          <span style="color:#475569;">Kuota Penjualan Harian</span>
          <span style="color:#059669;" id="quotaText">
            <?= number_format($dailySold, 1) ?> / <?= number_format($dailyMax, 0) ?> ml
          </span>
        </div>
        <div class="quota-bar-track">
          <div class="quota-bar-fill" id="quotaFillBar" style="width: <?= $quotaPct ?>%;"></div>
        </div>
        <div style="display:flex;justify-content:space-between;font-size:10px;font-weight:800;color:#64748b;margin-top:4px;">
          <span>Sisa kuota hari ini: <strong id="quotaRemText"><?= number_format($dailyRem, 1) ?> ml</strong></span>
          <a href="/farm/shop" style="color:#2563eb;text-decoration:none;font-weight:900;">+ Upgrade Lapak</a>
        </div>
      </div>
    </div>

    <!-- Konsol Pencairan Madu ke Rupiah -->
    <div class="cashout-card">
      <div class="cashout-title">
        <i class="ph-fill ph-coins" style="font-size:20px;color:#10b981;"></i>
        <span>Cairkan Madu ke Saldo Tarik (WD)</span>
      </div>

      <div class="cashout-input-box">
        <div style="font-size:10px;font-weight:900;color:#047857;text-transform:uppercase;margin-bottom:2px;">
          Jumlah Madu Dijual (Maks: <span id="maxSellableText"><?= number_format($maxSellable, 1) ?></span> ml)
        </div>
        <div class="cashout-input-row">
          <input type="number" step="0.1" min="0.1" max="<?= $maxSellable ?>" 
                 id="sellAmountInput" class="cashout-input-field" 
                 placeholder="0.0" value="<?= $maxSellable > 0 ? min(50.0, $maxSellable) : 0 ?>"
                 oninput="calculateRevenue()">
          <span class="cashout-unit-label">ml</span>
        </div>
      </div>

      <!-- Quick Preset Chips -->
      <div class="cashout-chips">
        <button type="button" class="btn-chip" onclick="setPresetPct(0.25)">25%</button>
        <button type="button" class="btn-chip" onclick="setPresetPct(0.50)">50%</button>
        <button type="button" class="btn-chip" onclick="setPresetPct(0.75)">75%</button>
        <button type="button" class="btn-chip" onclick="setPresetPct(1.0)">100% (Semua)</button>
      </div>

      <!-- Live Revenue Preview -->
      <div class="revenue-preview-box">
        <div>
          <div style="font-size:10.5px;font-weight:800;color:#854d0e;">Uang Masuk ke Saldo Tarik:</div>
          <div style="font-size:9.5px;font-weight:700;color:#a16207;">Bisa langsung di-withdraw ke rekening</div>
        </div>
        <div class="revenue-val" id="previewRevenue">
          Rp 0
        </div>
      </div>

      <!-- Tombol Aksi Jual -->
      <button type="button" class="btn-sell-madu" id="btnSellHoney" onclick="executeSellHoney()" <?= $maxSellable < 0.1 ? 'disabled' : '' ?>>
        <i class="ph-fill ph-hand-coins" style="font-size:20px;"></i>
        <span>JUAL SEKARANG &amp; TERIMA SALDO</span>
      </button>
    </div>

    <!-- Riwayat Penjualan Terbaru -->
    <div style="background:#fff;border:3px solid #78350f;border-radius:20px;padding:14px;box-shadow:0 4px 0 #78350f;">
      <div style="font-size:13px;font-weight:900;color:#78350f;margin-bottom:10px;display:flex;justify-content:space-between;align-items:center;">
        <span><i class="ph-fill ph-receipt"></i> Penjualan Terakhir</span>
        <a href="/farm/logs" style="font-size:11px;color:#b45309;text-decoration:none;font-weight:800;">Lihat Semua &rarr;</a>
      </div>
      <?php if (empty($recent_sales)): ?>
        <div style="text-align:center;padding:10px;font-size:11px;color:#94a3b8;font-weight:700;">
          Belum ada penjualan madu tercatat.
        </div>
      <?php else: ?>
        <?php foreach ($recent_sales as $rs): ?>
          <div style="display:flex;justify-content:space-between;align-items:center;padding:7px 0;border-bottom:1px solid #f1f5f9;">
            <div>
              <div style="font-size:12px;font-weight:900;color:#1e293b;">
                <?= number_format((float)$rs['amount_ml'], 1) ?> ml madu
              </div>
              <div style="font-size:9.5px;color:#64748b;">
                <?= date('d M Y H:i', strtotime($rs['created_at'])) ?>
              </div>
            </div>
            <div style="font-size:13px;font-weight:900;color:#059669;">
              + Rp <?= number_format((float)$rs['total_revenue'], 0, ',', '.') ?>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

  <?php endif; ?>

</div>

<!-- CSRF Field -->
<?= csrf_field() ?>

<script>
const PRICE_PER_ML = <?= isset($pricePerMl) ? (float)$pricePerMl : 0 ?>;
let CURRENT_USER_STOCK = <?= (float)$user['honey_stock'] ?>;
let DAILY_REMAINING = <?= isset($dailyRem) ? (float)$dailyRem : 0 ?>;

function calculateRevenue() {
  const input = document.getElementById('sellAmountInput');
  const val = parseFloat(input.value) || 0;
  const rev = Math.max(0, Math.round(val * PRICE_PER_ML));
  document.getElementById('previewRevenue').innerText = 'Rp ' + rev.toLocaleString('id-ID');
}

function setPresetPct(pct) {
  const maxSellable = Math.max(0, Math.min(CURRENT_USER_STOCK, DAILY_REMAINING));
  const amount = Math.floor(maxSellable * pct * 10) / 10;
  const input = document.getElementById('sellAmountInput');
  if (input) {
    input.value = amount;
    calculateRevenue();
  }
}

function executeSellHoney() {
  const input = document.getElementById('sellAmountInput');
  const amount = parseFloat(input.value) || 0;
  if (amount <= 0) {
    alert('Masukkan jumlah madu yang valid.');
    return;
  }

  const btn = document.getElementById('btnSellHoney');
  btn.disabled = true;
  const origText = btn.innerHTML;
  btn.innerHTML = '<i class="ph-bold ph-spinner ph-spin"></i> Memproses Penjualan...';

  const csrfToken = document.querySelector('input[name="_csrf"]')?.value || '';

  const fd = new FormData();
  fd.append('action', 'sell_honey');
  fd.append('amount_ml', amount);
  fd.append('_csrf', csrfToken);

  fetch('/api/farm_action', {
    method: 'POST',
    body: fd
  })
  .then(res => res.json())
  .then(data => {
    if (data.ok) {
      // Play celebratory coin sound!
      FarmAudio.playCoin();

      alert(data.msg);

      // Update local variables
      CURRENT_USER_STOCK = parseFloat(data.new_honey_stock);
      DAILY_REMAINING -= amount;

      // Update HUD
      const hudStock = document.getElementById('hudHoneyStock');
      if (hudStock) hudStock.innerText = CURRENT_USER_STOCK.toLocaleString('id-ID', {minimumFractionDigits: 1}) + ' ml';

      const hudWd = document.getElementById('hudBalWd');
      if (hudWd && data.new_balance_wd !== undefined) {
        hudWd.innerText = 'Rp ' + Number(data.new_balance_wd).toLocaleString('id-ID');
      }

      // Reload page to refresh stall quota and recent logs smoothly
      setTimeout(() => {
        window.location.reload();
      }, 1000);
    } else {
      alert(data.msg || 'Gagal menjual madu.');
      btn.disabled = false;
      btn.innerHTML = origText;
    }
  })
  .catch(err => {
    btn.disabled = false;
    btn.innerHTML = origText;
    alert('Terjadi kesalahan jaringan.');
  });
}

// Initial calculation on load
document.addEventListener('DOMContentLoaded', calculateRevenue);
</script>

<?php require dirname(__DIR__) . '/partials/footer.php'; ?>
