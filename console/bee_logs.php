<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';

if (!staff_can('bee_logs') && !staff_can('bee_farm')) {
    redirect(staff_home_url());
}

// Summary stats hari ini
$todayHarvestMl = (float)$pdo->query("SELECT COALESCE(SUM(amount_ml), 0) FROM bee_harvest_logs WHERE DATE(harvested_at)=CURDATE()")->fetchColumn();
$todaySalesMl   = (float)$pdo->query("SELECT COALESCE(SUM(amount_ml), 0) FROM bee_sales_logs WHERE DATE(created_at)=CURDATE()")->fetchColumn();
$todayRevenue   = (float)$pdo->query("SELECT COALESCE(SUM(total_revenue), 0) FROM bee_sales_logs WHERE DATE(created_at)=CURDATE()")->fetchColumn();
$todaySalesTx   = (int)$pdo->query("SELECT COUNT(*) FROM bee_sales_logs WHERE DATE(created_at)=CURDATE()")->fetchColumn();

// Ambil Log Panen Terakhir (500 limit untuk DataTables)
$harvestLogs = $pdo->query("
    SELECT l.*, u.username, m.name as hive_name
    FROM bee_harvest_logs l
    JOIN users u ON u.id = l.user_id
    JOIN user_bee_hives h ON h.id = l.hive_id
    JOIN bee_hives_master m ON m.id = h.hive_master_id
    ORDER BY l.id DESC LIMIT 500
")->fetchAll(PDO::FETCH_ASSOC);

// Ambil Log Penjualan Madu Terakhir
$salesLogs = $pdo->query("
    SELECT l.*, u.username, m.name as stall_name, m.tier_level
    FROM bee_sales_logs l
    JOIN users u ON u.id = l.user_id
    JOIN user_bee_stalls s ON s.id = l.stall_id
    JOIN bee_stalls_master m ON m.id = s.stall_master_id
    ORDER BY l.id DESC LIMIT 500
")->fetchAll(PDO::FETCH_ASSOC);

$pageTitle  = 'Log Panen & Penjualan Madu';
$activePage = 'bee_logs';
require __DIR__ . '/partials/header.php';
?>

<div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
  <div>
    <h4 class="mb-1 text-white fw-bold d-flex align-items-center gap-2">
      <i class="ph-fill ph-receipt text-warning"></i> Log Panen &amp; Penjualan Madu
    </h4>
    <div class="text-secondary small">Pantau arus hasil panen lebah dan pencairan madu ke saldo penarikan pengguna.</div>
  </div>
  <div>
    <a href="/console/bee_farm.php" class="btn btn-warning btn-sm fw-bold d-flex align-items-center gap-1">
      <i class="ph-fill ph-drop"></i> Kelola Peternakan
    </a>
  </div>
</div>

<!-- ── SUMMARY STATS HARI INI ── -->
<div class="row g-3 mb-4">
  <div class="col-6 col-md-3">
    <div class="c-stat">
      <div class="c-stat__lbl">Panen Madu Hari Ini</div>
      <div class="c-stat__val text-warning">+<?= number_format($todayHarvestMl, 1) ?> <span class="fs-6">ml</span></div>
      <div class="small text-muted mt-1"><i class="ph-bold ph-drop"></i> Dari sarang lebah</div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="c-stat">
      <div class="c-stat__lbl">Madu Terjual Hari Ini</div>
      <div class="c-stat__val text-info"><?= number_format($todaySalesMl, 1) ?> <span class="fs-6">ml</span></div>
      <div class="small text-muted mt-1"><i class="ph-bold ph-shopping-bag"></i> Ke lapak madu</div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="c-stat">
      <div class="c-stat__lbl">Cuan Dicairkan Hari Ini</div>
      <div class="c-stat__val text-success">Rp <?= number_format($todayRevenue, 0, ',', '.') ?></div>
      <div class="small text-muted mt-1"><i class="ph-bold ph-money"></i> Masuk Saldo WD User</div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="c-stat">
      <div class="c-stat__lbl">Transaksi Penjualan</div>
      <div class="c-stat__val text-white"><?= number_format($todaySalesTx) ?> <span class="fs-6">Order</span></div>
      <div class="small text-muted mt-1"><i class="ph-bold ph-receipt"></i> Hari ini</div>
    </div>
  </div>
</div>

<!-- ── TABS LOGS ── -->
<ul class="nav nav-pills mb-3 gap-2" id="logTabs" role="tablist">
  <li class="nav-item">
    <button class="nav-link active px-4 py-2 fw-bold" id="sales-tab" data-bs-toggle="tab" data-bs-target="#tab-sales" type="button">
      💰 Log Penjualan Madu (<?= count($salesLogs) ?>)
    </button>
  </li>
  <li class="nav-item">
    <button class="nav-link px-4 py-2 fw-bold" id="harvest-tab" data-bs-toggle="tab" data-bs-target="#tab-harvest" type="button">
      🍯 Log Panen Madu (<?= count($harvestLogs) ?>)
    </button>
  </li>
</ul>

<div class="tab-content" id="logTabsContent">

  <!-- ── 1. LOG PENJUALAN MADU ── -->
  <div class="tab-pane fade show active" id="tab-sales" role="tabpanel">
    <div class="c-card">
      <div class="c-card-header">
        <span class="c-card-title text-white">Transaksi Penjualan Madu ke Lapak</span>
      </div>
      <div class="table-responsive p-3">
        <table class="table table-dark table-hover c-table align-middle w-100">
          <thead>
            <tr class="text-secondary small">
              <th>ID</th>
              <th>Waktu</th>
              <th>Pengguna</th>
              <th>Lapak Digunakan</th>
              <th>Madu Terjual</th>
              <th>Harga / ml</th>
              <th>Total Dicairkan (WD)</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($salesLogs as $sl): ?>
            <tr>
              <td>#<?= (int)$sl['id'] ?></td>
              <td class="small text-muted"><?= date('d/m/Y H:i:s', strtotime((string)$sl['created_at'])) ?></td>
              <td>
                <a href="/console/user_detail.php?id=<?= (int)$sl['user_id'] ?>" class="fw-bold text-white text-decoration-none">
                  <?= htmlspecialchars($sl['username']) ?>
                </a>
              </td>
              <td>
                <span class="badge bg-secondary">
                  Tier <?= (int)$sl['tier_level'] ?>: <?= htmlspecialchars($sl['stall_name']) ?>
                </span>
              </td>
              <td class="text-info fw-bold"><?= number_format((float)$sl['amount_ml'], 2, ',', '.') ?> ml</td>
              <td>Rp <?= number_format((float)$sl['price_per_ml'], 0, ',', '.') ?></td>
              <td class="text-success fw-bold fs-7">
                +Rp <?= number_format((float)$sl['total_revenue'], 0, ',', '.') ?>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- ── 2. LOG PANEN MADU ── -->
  <div class="tab-pane fade" id="tab-harvest" role="tabpanel">
    <div class="c-card">
      <div class="c-card-header">
        <span class="c-card-title text-white">Aktivitas Panen Madu dari Kandang</span>
      </div>
      <div class="table-responsive p-3">
        <table class="table table-dark table-hover c-table align-middle w-100">
          <thead>
            <tr class="text-secondary small">
              <th>ID</th>
              <th>Waktu Panen</th>
              <th>Pengguna</th>
              <th>Kandang Asal</th>
              <th>Jumlah Madu Masuk Stok</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($harvestLogs as $hl): ?>
            <tr>
              <td>#<?= (int)$hl['id'] ?></td>
              <td class="small text-muted"><?= date('d/m/Y H:i:s', strtotime((string)$hl['harvested_at'])) ?></td>
              <td>
                <a href="/console/user_detail.php?id=<?= (int)$hl['user_id'] ?>" class="fw-bold text-white text-decoration-none">
                  <?= htmlspecialchars($hl['username']) ?>
                </a>
              </td>
              <td>
                <span class="badge bg-warning text-dark fw-bold">
                  <?= htmlspecialchars($hl['hive_name']) ?>
                </span>
              </td>
              <td class="text-warning fw-bold fs-7">
                +<?= number_format((float)$hl['amount_ml'], 2, ',', '.') ?> ml
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

</div>

<?php require __DIR__ . '/partials/footer.php'; ?>
