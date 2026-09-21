<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

$user = require_auth($pdo);

if (is_maintenance($pdo) && !auth_admin()) {
    $maintenance_msg = setting($pdo, 'maintenance_message', 'Sistem sedang dalam perbaikan.');
    require dirname(__DIR__) . '/user/maintenance.php';
    exit;
}

track_pageview($pdo, parse_url($_SERVER['REQUEST_URI'] ?? '/farm/logs', PHP_URL_PATH));

// Fetch fresh user data
$uStmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$uStmt->execute([$user['id']]);
$user = $uStmt->fetch(PDO::FETCH_ASSOC);

$tab = $_GET['tab'] ?? 'sales';

// 1. Fetch sales logs
$sales_logs_stmt = $pdo->prepare("
    SELECT l.*, m.name as stall_name, m.tier_level
    FROM bee_sales_logs l
    JOIN user_bee_stalls s ON s.id = l.stall_id
    JOIN bee_stalls_master m ON m.id = s.stall_master_id
    WHERE l.user_id = ?
    ORDER BY l.id DESC LIMIT 40
");
$sales_logs_stmt->execute([$user['id']]);
$sales_logs = $sales_logs_stmt->fetchAll(PDO::FETCH_ASSOC);

// 2. Fetch harvest logs
$harvest_logs_stmt = $pdo->prepare("
    SELECT l.*, m.name as hive_name
    FROM bee_harvest_logs l
    JOIN user_bee_hives h ON h.id = l.hive_id
    JOIN bee_hives_master m ON m.id = h.hive_master_id
    WHERE l.user_id = ?
    ORDER BY l.id DESC LIMIT 40
");
$harvest_logs_stmt->execute([$user['id']]);
$harvest_logs = $harvest_logs_stmt->fetchAll(PDO::FETCH_ASSOC);

// Total lifetime metrics
$total_harvested_ml = (float)$pdo->prepare("SELECT COALESCE(SUM(amount_ml), 0) FROM bee_harvest_logs WHERE user_id=?")->execute([$user['id']]) ? $pdo->query("SELECT COALESCE(SUM(amount_ml), 0) FROM bee_harvest_logs WHERE user_id={$user['id']}")->fetchColumn() : 0;
$total_sales_rp = (float)$pdo->prepare("SELECT COALESCE(SUM(total_revenue), 0) FROM bee_sales_logs WHERE user_id=?")->execute([$user['id']]) ? $pdo->query("SELECT COALESCE(SUM(total_revenue), 0) FROM bee_sales_logs WHERE user_id={$user['id']}")->fetchColumn() : 0;

$pageTitle = 'Riwayat Panen & Penjualan — Lebah Cuan';
$activePage = 'farm';
$farmSubPage = 'logs';
require dirname(__DIR__) . '/partials/header.php';
?>

<style>
body { background: #fef3c7 !important; font-family: 'Nunito', sans-serif; }
.logs-container { padding: 16px 14px 120px; max-width: 480px; margin: 0 auto; }

/* Segmented Tabs */
.logs-cat-tabs {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 8px;
  margin-bottom: 16px;
}
.btn-log-tab {
  background: #ffffff;
  border: 2.5px solid #78350f;
  border-radius: 14px;
  padding: 10px 8px;
  text-align: center;
  font-size: 12px; font-weight: 900;
  color: #78350f;
  text-decoration: none;
  box-shadow: 0 3px 0 #78350f;
  display: flex; align-items: center; justify-content: center; gap: 6px;
  transition: transform 0.1s;
}
.btn-log-tab.active {
  background: linear-gradient(135deg, #f59e0b, #d97706);
  color: #ffffff;
  box-shadow: 0 3px 0 #78350f;
  transform: translateY(-2px);
}

/* Lifetime Summary Banner */
.lifetime-sum-card {
  background: #ffffff;
  border: 3px solid #78350f;
  border-radius: 20px;
  box-shadow: 0 4px 0 #78350f;
  padding: 14px;
  margin-bottom: 16px;
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 10px;
  text-align: center;
}
.lifetime-box__lbl { font-size: 10px; font-weight: 800; color: #64748b; margin-bottom: 2px; }
.lifetime-box__val { font-size: 15px; font-weight: 900; }

/* Log List Item */
.log-item-card {
  background: #ffffff;
  border: 2.5px solid #78350f;
  border-radius: 16px;
  box-shadow: 0 3px 0 #78350f;
  padding: 12px 14px;
  margin-bottom: 10px;
  display: flex;
  align-items: center;
  justify-content: space-between;
}
.log-item-icon {
  width: 40px; height: 40px;
  border-radius: 12px;
  display: flex; align-items: center; justify-content: center;
  font-size: 20px; flex-shrink: 0;
}
.log-item-title { font-size: 13px; font-weight: 900; color: #1e293b; line-height: 1.2; margin-bottom: 2px; }
.log-item-time  { font-size: 10px; font-weight: 700; color: #64748b; }
.log-item-val   { font-size: 14px; font-weight: 900; text-align: right; }
</style>

<?php require __DIR__ . '/partials/farm_header.php'; ?>

<div class="logs-container">

  <!-- Lifetime Summary -->
  <div class="lifetime-sum-card">
    <div>
      <div class="lifetime-box__lbl">Total Madu Dipanen</div>
      <div class="lifetime-box__val" style="color:#d97706;">
        <?= number_format((float)$total_harvested_ml, 1) ?> ml
      </div>
    </div>
    <div style="border-left:2px dashed #e2e8f0;">
      <div class="lifetime-box__lbl">Total Cuan Terjual</div>
      <div class="lifetime-box__val" style="color:#059669;">
        Rp <?= number_format((float)$total_sales_rp, 0, ',', '.') ?>
      </div>
    </div>
  </div>

  <!-- Switch Tabs -->
  <div class="logs-cat-tabs">
    <a href="/farm/logs?tab=sales" class="btn-log-tab <?= $tab === 'sales' ? 'active' : '' ?>">
      <i class="ph-fill ph-receipt"></i> Penjualan Madu
    </a>
    <a href="/farm/logs?tab=harvest" class="btn-log-tab <?= $tab === 'harvest' ? 'active' : '' ?>">
      <i class="ph-fill ph-drop"></i> Panen Sarang
    </a>
  </div>

  <!-- TAB 1: PENJUALAN MADU -->
  <?php if ($tab === 'sales'): ?>
    <?php if (empty($sales_logs)): ?>
      <div style="background:#fff;border:3px solid #78350f;border-radius:20px;padding:30px;text-align:center;">
        <div style="font-size:36px;margin-bottom:8px;">🍯</div>
        <div style="font-size:14px;font-weight:900;color:#78350f;">Belum Ada Penjualan Madu</div>
        <div style="font-size:11px;font-weight:700;color:#64748b;margin-top:4px;">
          Panen madu kamu lalu jual di Lapak Madu untuk mulai menghasilkan rupiah.
        </div>
        <a href="/farm/stall" style="display:inline-block;margin-top:12px;background:#10b981;color:#fff;border:2.5px solid #064e3b;border-radius:12px;padding:8px 16px;font-size:12px;font-weight:900;text-decoration:none;">
          Buka Lapak Madu &rarr;
        </a>
      </div>
    <?php else: ?>
      <?php foreach ($sales_logs as $sl): ?>
        <div class="log-item-card">
          <div style="display:flex;align-items:center;gap:12px;">
            <div class="log-item-icon" style="background:#ecfdf5;border:2px solid #059669;color:#059669;">
              <i class="ph-fill ph-coins"></i>
            </div>
            <div>
              <div class="log-item-title"><?= htmlspecialchars($sl['stall_name']) ?></div>
              <div class="log-item-time"><?= date('d M Y, H:i', strtotime($sl['created_at'])) ?> WIB</div>
            </div>
          </div>
          <div class="log-item-val" style="color:#059669;">
            + Rp <?= number_format((float)$sl['total_revenue'], 0, ',', '.') ?>
            <div style="font-size:10px;font-weight:700;color:#64748b;">
              <?= number_format((float)$sl['amount_ml'], 1) ?> ml @ Rp <?= number_format((float)$sl['price_per_ml'], 0) ?>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  <?php endif; ?>

  <!-- TAB 2: PANEN SARANG -->
  <?php if ($tab === 'harvest'): ?>
    <?php if (empty($harvest_logs)): ?>
      <div style="background:#fff;border:3px solid #78350f;border-radius:20px;padding:30px;text-align:center;">
        <div style="font-size:36px;margin-bottom:8px;">🌼</div>
        <div style="font-size:14px;font-weight:900;color:#78350f;">Belum Ada Riwayat Panen</div>
        <div style="font-size:11px;font-weight:700;color:#64748b;margin-top:4px;">
          Tunggu lebah mengumpulkan madu di sarang lalu tekan tombol panen!
        </div>
        <a href="/farm" style="display:inline-block;margin-top:12px;background:#f59e0b;color:#fff;border:2.5px solid #78350f;border-radius:12px;padding:8px 16px;font-size:12px;font-weight:900;text-decoration:none;">
          Buka Kebun &rarr;
        </a>
      </div>
    <?php else: ?>
      <?php foreach ($harvest_logs as $hl): ?>
        <div class="log-item-card">
          <div style="display:flex;align-items:center;gap:12px;">
            <div class="log-item-icon" style="background:#fef3c7;border:2px solid #d97706;color:#d97706;">
              <i class="ph-fill ph-drop"></i>
            </div>
            <div>
              <div class="log-item-title"><?= htmlspecialchars($hl['hive_name']) ?></div>
              <div class="log-item-time"><?= date('d M Y, H:i', strtotime($hl['harvested_at'])) ?> WIB</div>
            </div>
          </div>
          <div class="log-item-val" style="color:#d97706;">
            + <?= number_format((float)$hl['amount_ml'], 1) ?> ml
            <div style="font-size:10px;font-weight:700;color:#64748b;">Madu Segar</div>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  <?php endif; ?>

</div>

<?php require dirname(__DIR__) . '/partials/footer.php'; ?>
