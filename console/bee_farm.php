<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';

if (!staff_can('bee_farm')) {
    redirect(staff_home_url());
}

$msg = '';
$err = '';

// Handle POST updates
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // 1. Update Kandang
    if ($action === 'update_hive') {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $price = (float)($_POST['price'] ?? 0);
        $max_slots = (int)($_POST['max_slots'] ?? 2);
        $bonus_speed_pct = (int)($_POST['bonus_speed_pct'] ?? 0);
        $duration_days = (int)($_POST['duration_days'] ?? 30);
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        $image = trim($_POST['image'] ?? '');

        if ($id > 0 && $name !== '') {
            $stmt = $pdo->prepare("
                UPDATE bee_hives_master
                SET name = ?, description = ?, price = ?, max_slots = ?, bonus_speed_pct = ?,
                    duration_days = ?, is_active = ?, image = ?
                WHERE id = ?
            ");
            $stmt->execute([$name, $description, $price, $max_slots, $bonus_speed_pct, $duration_days, $is_active, $image, $id]);
            $msg = "Kandang '{$name}' berhasil diperbarui!";
        } else {
            $err = "Data kandang tidak lengkap.";
        }
    }

    // 2. Update Lebah
    if ($action === 'update_bee') {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $price = (float)($_POST['price'] ?? 0);
        $honey_per_hour = (float)($_POST['honey_per_hour'] ?? 10);
        $duration_days = (int)($_POST['duration_days'] ?? 30);
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        $image = trim($_POST['image'] ?? '');

        if ($id > 0 && $name !== '') {
            $stmt = $pdo->prepare("
                UPDATE bee_types_master
                SET name = ?, description = ?, price = ?, honey_per_hour = ?, duration_days = ?,
                    is_active = ?, image = ?
                WHERE id = ?
            ");
            $stmt->execute([$name, $description, $price, $honey_per_hour, $duration_days, $is_active, $image, $id]);
            $msg = "Spesies Lebah '{$name}' berhasil diperbarui!";
        } else {
            $err = "Data lebah tidak lengkap.";
        }
    }

    // 3. Update Lapak
    if ($action === 'update_stall') {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $price = (float)($_POST['price'] ?? 0);
        $sell_price_per_ml = (float)($_POST['sell_price_per_ml'] ?? 25);
        $daily_max_ml = (float)($_POST['daily_max_ml'] ?? 200);
        $duration_days = (int)($_POST['duration_days'] ?? 30);
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        $image = trim($_POST['image'] ?? '');

        if ($id > 0 && $name !== '') {
            $stmt = $pdo->prepare("
                UPDATE bee_stalls_master
                SET name = ?, description = ?, price = ?, sell_price_per_ml = ?, daily_max_ml = ?,
                    duration_days = ?, is_active = ?, image = ?
                WHERE id = ?
            ");
            $stmt->execute([$name, $description, $price, $sell_price_per_ml, $daily_max_ml, $duration_days, $is_active, $image, $id]);
            $msg = "Lapak '{$name}' berhasil diperbarui!";
        } else {
            $err = "Data lapak tidak lengkap.";
        }
    }
}

// Ambil Master Data
$hives = $pdo->query("SELECT * FROM bee_hives_master ORDER BY sort_order ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);
$bees  = $pdo->query("SELECT * FROM bee_types_master ORDER BY sort_order ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);
$stalls= $pdo->query("SELECT * FROM bee_stalls_master ORDER BY tier_level ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);

// Metrik Peternakan Global
$statHivesOwned   = (int)$pdo->query("SELECT COUNT(*) FROM user_bee_hives WHERE is_active=1 AND (expires_at IS NULL OR expires_at > NOW())")->fetchColumn();
$statBeesActive   = (int)$pdo->query("SELECT COUNT(*) FROM user_bees WHERE is_active=1 AND (expires_at IS NULL OR expires_at > NOW())")->fetchColumn();
$statHoneySupply  = (float)$pdo->query("SELECT COALESCE(SUM(honey_stock), 0) FROM users")->fetchColumn();
$statHarvestedMl  = (float)$pdo->query("SELECT COALESCE(SUM(amount_ml), 0) FROM bee_harvest_logs")->fetchColumn();
$statSalesRevenue = (float)$pdo->query("SELECT COALESCE(SUM(total_revenue), 0) FROM bee_sales_logs")->fetchColumn();
$statActiveStalls = (int)$pdo->query("SELECT COUNT(*) FROM user_bee_stalls WHERE is_active=1 AND (expires_at IS NULL OR expires_at > NOW())")->fetchColumn();

$pageTitle  = 'Kelola Peternakan Lebah';
$activePage = 'bee_farm';
require __DIR__ . '/partials/header.php';
?>

<div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
  <div>
    <h4 class="mb-1 text-white fw-bold d-flex align-items-center gap-2">
      <i class="ph-fill ph-drop text-warning"></i> Kelola Peternakan Lebah Cuan
    </h4>
    <div class="text-secondary small">Atur harga, kapasitas, kecepatan produksi madu, dan tier lapak jualan.</div>
  </div>
  <div class="d-flex gap-2">
    <button type="button" class="btn btn-warning btn-sm fw-bold d-flex align-items-center gap-1" onclick="switchBeeTab('calc')">
      <i class="ph-bold ph-calculator"></i> Kalkulator Profit
    </button>
    <a href="/console/bee_logs.php" class="btn btn-outline-warning btn-sm d-flex align-items-center gap-1">
      <i class="ph-fill ph-receipt"></i> Lihat Log Panen &amp; Jual
    </a>
    <a href="/farm" target="_blank" class="btn btn-outline-light btn-sm fw-bold d-flex align-items-center gap-1">
      <i class="ph-fill ph-arrow-square-out"></i> Buka Farm User
    </a>
  </div>
</div>

<?php if ($msg): ?>
  <div class="alert alert-success d-flex align-items-center gap-2 mb-3">
    <i class="ph-bold ph-check-circle fs-5"></i>
    <div><?= htmlspecialchars($msg) ?></div>
  </div>
<?php endif; ?>
<?php if ($err): ?>
  <div class="alert alert-danger d-flex align-items-center gap-2 mb-3">
    <i class="ph-bold ph-warning-circle fs-5"></i>
    <div><?= htmlspecialchars($err) ?></div>
  </div>
<?php endif; ?>

<!-- ── GLOBAL STATS ROW ── -->
<div class="row g-3 mb-4">
  <div class="col-6 col-md-4 col-xl-2">
    <div class="c-stat">
      <div class="c-stat__lbl">Kandang Aktif User</div>
      <div class="c-stat__val text-warning"><?= number_format($statHivesOwned) ?></div>
      <div class="small text-muted mt-1"><i class="ph-bold ph-house-line"></i> Kandang</div>
    </div>
  </div>
  <div class="col-6 col-md-4 col-xl-2">
    <div class="c-stat">
      <div class="c-stat__lbl">Lebah Bekerja</div>
      <div class="c-stat__val text-info"><?= number_format($statBeesActive) ?></div>
      <div class="small text-muted mt-1"><i class="ph-bold ph-flower"></i> Ekor lebah</div>
    </div>
  </div>
  <div class="col-6 col-md-4 col-xl-2">
    <div class="c-stat">
      <div class="c-stat__lbl">Lapak Madu Aktif</div>
      <div class="c-stat__val text-success"><?= number_format($statActiveStalls) ?></div>
      <div class="small text-muted mt-1"><i class="ph-bold ph-storefront"></i> Stan aktif</div>
    </div>
  </div>
  <div class="col-6 col-md-4 col-xl-2">
    <div class="c-stat">
      <div class="c-stat__lbl">Stok Madu Beredar</div>
      <div class="c-stat__val text-warning"><?= number_format($statHoneySupply, 1) ?> <span class="fs-6">ml</span></div>
      <div class="small text-muted mt-1"><i class="ph-bold ph-drop"></i> Di tangan user</div>
    </div>
  </div>
  <div class="col-6 col-md-4 col-xl-2">
    <div class="c-stat">
      <div class="c-stat__lbl">Total Panen Madu</div>
      <div class="c-stat__val text-primary"><?= number_format($statHarvestedMl, 1) ?> <span class="fs-6">ml</span></div>
      <div class="small text-muted mt-1"><i class="ph-bold ph-chart-line-up"></i> Akumulasi</div>
    </div>
  </div>
  <div class="col-6 col-md-4 col-xl-2">
    <div class="c-stat">
      <div class="c-stat__lbl">Omset Penjualan Madu</div>
      <div class="c-stat__val text-success">Rp <?= number_format($statSalesRevenue, 0, ',', '.') ?></div>
      <div class="small text-muted mt-1"><i class="ph-bold ph-coins"></i> Cair ke Saldo WD</div>
    </div>
  </div>
</div>

<!-- ── TABS NAVIGATION ── -->
<ul class="nav nav-pills mb-3 gap-2" id="beeTabs" role="tablist">
  <li class="nav-item">
    <button class="nav-link active px-4 py-2 fw-bold" id="hives-tab" data-bs-toggle="tab" data-bs-target="#tab-hives" type="button">
      🏡 Katalog Kandang Lebah (<?= count($hives) ?>)
    </button>
  </li>
  <li class="nav-item">
    <button class="nav-link px-4 py-2 fw-bold" id="bees-tab" data-bs-toggle="tab" data-bs-target="#tab-bees" type="button">
      🐝 Spesies Lebah Pekerja (<?= count($bees) ?>)
    </button>
  </li>
  <li class="nav-item">
    <button class="nav-link px-4 py-2 fw-bold" id="stalls-tab" data-bs-toggle="tab" data-bs-target="#tab-stalls" type="button">
      🏪 Tier Lapak Jual Madu (<?= count($stalls) ?>)
    </button>
  </li>
  <li class="nav-item">
    <button class="nav-link px-4 py-2 fw-bold text-warning" id="calc-tab" data-bs-toggle="tab" data-bs-target="#tab-calc" type="button" style="border: 1px solid rgba(245, 158, 11, 0.45); background: rgba(245, 158, 11, 0.1);">
      <i class="ph-bold ph-calculator"></i> Kalkulator Simulasi Profit &amp; ROI
    </button>
  </li>
</ul>

<div class="tab-content" id="beeTabsContent">

  <!-- ── 1. TAB KANDANG LEBAH ── -->
  <div class="tab-pane fade show active" id="tab-hives" role="tabpanel">
    <div class="c-card">
      <div class="c-card-header d-flex align-items-center justify-content-between">
        <span class="c-card-title text-white">Master Kandang Lebah (Beehives)</span>
      </div>
      <div class="table-responsive">
        <table class="table table-dark table-hover align-middle mb-0">
          <thead>
            <tr class="text-secondary small">
              <th>Gambar</th>
              <th>Nama Kandang</th>
              <th>Kapasitas Slot</th>
              <th>Kapasitas Tangki</th>
              <th>Bonus Kecepatan</th>
              <th>Harga Beli</th>
              <th>Masa Aktif</th>
              <th>Status</th>
              <th class="text-end">Aksi</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($hives as $h): ?>
            <tr>
              <td>
                <img src="<?= htmlspecialchars($h['image']) ?>" alt="" style="width:48px;height:48px;object-fit:contain;background:#181b2a;border-radius:10px;padding:4px;border:1px solid #2a2f45;">
              </td>
              <td>
                <div class="fw-bold text-white"><?= htmlspecialchars($h['name']) ?></div>
                <div class="small text-muted"><?= htmlspecialchars($h['description']) ?></div>
              </td>
              <td><span class="badge bg-primary small"><?= (int)$h['max_slots'] ?> Ekor Lebah</span></td>
              <td>
                <?php $tankCap = max(100, (int)$h['max_slots'] * 75); ?>
                <span class="badge bg-secondary small"><?= number_format($tankCap, 0, ',', '.') ?> ml</span>
              </td>
              <td>
                <?php if ((int)$h['bonus_speed_pct'] > 0): ?>
                  <span class="badge bg-warning text-dark fw-bold">+<?= (int)$h['bonus_speed_pct'] ?>% Speed</span>
                <?php else: ?>
                  <span class="text-muted small">0%</span>
                <?php endif; ?>
              </td>
              <td class="text-warning fw-bold">Rp <?= number_format((float)$h['price'], 0, ',', '.') ?></td>
              <td><?= (int)$h['duration_days'] ?> Hari</td>
              <td>
                <?php if ($h['is_active']): ?>
                  <span class="badge bg-success">Aktif</span>
                <?php else: ?>
                  <span class="badge bg-secondary">Nonaktif</span>
                <?php endif; ?>
              </td>
              <td class="text-end">
                <button class="btn btn-sm btn-outline-warning" onclick='openEditHiveModal(<?= json_encode($h) ?>)'>
                  <i class="ph-bold ph-pencil-simple"></i> Edit
                </button>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- ── 2. TAB SPESIES LEBAH ── -->
  <div class="tab-pane fade" id="tab-bees" role="tabpanel">
    <div class="c-card">
      <div class="c-card-header d-flex align-items-center justify-content-between">
        <span class="c-card-title text-white">Master Spesies Lebah (Bee Types)</span>
      </div>
      <div class="table-responsive">
        <table class="table table-dark table-hover align-middle mb-0">
          <thead>
            <tr class="text-secondary small">
              <th>Gambar</th>
              <th>Nama Spesies</th>
              <th>Produksi Madu</th>
              <th>Total Produksi</th>
              <th>Estimasi Nilai Jual</th>
              <th>Harga Beli</th>
              <th>Masa Aktif</th>
              <th>Status</th>
              <th class="text-end">Aksi</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($bees as $b): ?>
            <?php 
              $dailyBeeMl = (float)$b['honey_per_hour'] * 24;
              $lifetimeBeeMl = $dailyBeeMl * (int)$b['duration_days'];
              $estValMin = $lifetimeBeeMl * 25; // Tier 1 lapak rate
              $estValMax = $lifetimeBeeMl * 55; // Tier 3 lapak rate
            ?>
            <tr>
              <td>
                <img src="<?= htmlspecialchars($b['image']) ?>" alt="" style="width:48px;height:48px;object-fit:contain;background:#181b2a;border-radius:10px;padding:4px;border:1px solid #2a2f45;">
              </td>
              <td>
                <div class="fw-bold text-white"><?= htmlspecialchars($b['name']) ?></div>
                <div class="small text-muted"><?= htmlspecialchars($b['description']) ?></div>
              </td>
              <td>
                <span class="badge bg-success small">
                  <i class="ph-fill ph-drop"></i> +<?= number_format((float)$b['honey_per_hour'], 1) ?> ml / jam
                </span>
                <div class="small text-warning fw-bold mt-1">+<?= number_format($dailyBeeMl, 0) ?> ml / hari</div>
              </td>
              <td>
                <div class="text-white fw-bold"><?= number_format($lifetimeBeeMl, 0, ',', '.') ?> ml</div>
                <div class="small text-muted"><?= (int)$b['duration_days'] ?> hari kerja</div>
              </td>
              <td>
                <div class="text-success small fw-bold">Rp <?= number_format($estValMin, 0, ',', '.') ?></div>
                <div class="small text-muted">s/d Rp <?= number_format($estValMax, 0, ',', '.') ?></div>
              </td>
              <td class="text-warning fw-bold">Rp <?= number_format((float)$b['price'], 0, ',', '.') ?></td>
              <td><?= (int)$b['duration_days'] ?> Hari</td>
              <td>
                <?php if ($b['is_active']): ?>
                  <span class="badge bg-success">Aktif</span>
                <?php else: ?>
                  <span class="badge bg-secondary">Nonaktif</span>
                <?php endif; ?>
              </td>
              <td class="text-end">
                <button class="btn btn-sm btn-outline-warning" onclick='openEditBeeModal(<?= json_encode($b) ?>)'>
                  <i class="ph-bold ph-pencil-simple"></i> Edit
                </button>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- ── 3. TAB TIER LAPAK MADU ── -->
  <div class="tab-pane fade" id="tab-stalls" role="tabpanel">
    <div class="c-card">
      <div class="c-card-header d-flex align-items-center justify-content-between">
        <span class="c-card-title text-white">Master Tier Lapak Jual Madu (Honey Stalls)</span>
      </div>
      <div class="table-responsive">
        <table class="table table-dark table-hover align-middle mb-0">
          <thead>
            <tr class="text-secondary small">
              <th>Gambar</th>
              <th>Tier &amp; Nama Lapak</th>
              <th>Harga Jual / ml</th>
              <th>Maks Jual / Hari</th>
              <th>Maks Hasil / Hari</th>
              <th>Harga Sewa Lapak</th>
              <th>Masa Aktif</th>
              <th>Potensi Total Omset</th>
              <th>Status</th>
              <th class="text-end">Aksi</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($stalls as $s): ?>
            <?php 
              $stallDailyMaxRev = (float)$s['daily_max_ml'] * (float)$s['sell_price_per_ml'];
              $stallLifetimeRev = $stallDailyMaxRev * (int)$s['duration_days'];
              $stallNetProfit   = $stallLifetimeRev - (float)$s['price'];
            ?>
            <tr>
              <td>
                <img src="<?= htmlspecialchars($s['image']) ?>" alt="" style="width:48px;height:48px;object-fit:contain;background:#181b2a;border-radius:10px;padding:4px;border:1px solid #2a2f45;">
              </td>
              <td>
                <div class="fw-bold text-white">Tier <?= (int)$s['tier_level'] ?>: <?= htmlspecialchars($s['name']) ?></div>
                <div class="small text-muted"><?= htmlspecialchars($s['description']) ?></div>
              </td>
              <td>
                <span class="badge bg-success small">Rp <?= number_format((float)$s['sell_price_per_ml'], 0, ',', '.') ?> / ml</span>
              </td>
              <td><span class="badge bg-info text-dark fw-bold"><?= number_format((float)$s['daily_max_ml'], 0) ?> ml / hari</span></td>
              <td>
                <span class="badge bg-success fw-bold">Rp <?= number_format($stallDailyMaxRev, 0, ',', '.') ?> / hari</span>
              </td>
              <td class="text-warning fw-bold">Rp <?= number_format((float)$s['price'], 0, ',', '.') ?></td>
              <td><?= (int)$s['duration_days'] ?> Hari</td>
              <td>
                <div class="text-warning fw-bold">Rp <?= number_format($stallLifetimeRev, 0, ',', '.') ?></div>
                <div class="small <?= $stallNetProfit >= 0 ? 'text-success' : 'text-danger' ?>">
                  Net: <?= $stallNetProfit >= 0 ? '+' : '' ?>Rp <?= number_format($stallNetProfit, 0, ',', '.') ?>
                </div>
              </td>
              <td>
                <?php if ($s['is_active']): ?>
                  <span class="badge bg-success">Aktif</span>
                <?php else: ?>
                  <span class="badge bg-secondary">Nonaktif</span>
                <?php endif; ?>
              </td>
              <td class="text-end">
                <div class="d-flex justify-content-end gap-1">
                  <button class="btn btn-sm btn-outline-warning" onclick='openEditStallModal(<?= json_encode($s) ?>)'>
                    <i class="ph-bold ph-pencil-simple"></i> Edit
                  </button>
                  <button class="btn btn-sm btn-dark border-secondary text-info" title="Uji Lapak di Kalkulator" onclick="loadStallToCalc(<?= (int)$s['id'] ?>)">
                    <i class="ph-bold ph-calculator"></i>
                  </button>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- ── 4. TAB KALKULATOR SIMULASI PROFIT & ROI ── -->
  <div class="tab-pane fade" id="tab-calc" role="tabpanel">
    
    <!-- PRESET SELECTOR BAR -->
    <div class="c-card mb-3 p-3">
      <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
        <div>
          <span class="text-white fw-bold d-flex align-items-center gap-1 fs-6">
            <i class="ph-bold ph-lightning text-warning"></i> Preset Cepat Simulasi Kombinasi Peternakan
          </span>
          <div class="text-secondary small">Pilih salah satu preset kombo di bawah untuk memuat otomatis konfigurasi kandang, lebah, dan tier lapak:</div>
        </div>
        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="resetCalcToDefaults()">
          <i class="ph-bold ph-arrows-clockwise"></i> Reset Input
        </button>
      </div>
      <div class="d-flex flex-wrap gap-2 pt-1">
        <button type="button" class="btn btn-sm btn-dark border-secondary text-warning fw-bold d-flex align-items-center gap-1" onclick="applyCalcPreset(1)">
          🎋 Tier 1: Raw Amber (Pemula)
        </button>
        <button type="button" class="btn btn-sm btn-dark border-secondary text-warning fw-bold d-flex align-items-center gap-1" onclick="applyCalcPreset(2)">
          🪵 Tier 2: Golden Amber (Menengah)
        </button>
        <button type="button" class="btn btn-sm btn-dark border-secondary text-warning fw-bold d-flex align-items-center gap-1" onclick="applyCalcPreset(3)">
          🏛️ Tier 3: Royal Amber (Sultan)
        </button>
        <button type="button" class="btn btn-sm btn-dark border-secondary text-warning fw-bold d-flex align-items-center gap-1" onclick="applyCalcPreset(4)">
          👑 Tier 4: Imperial Amber (VIP)
        </button>
        <button type="button" class="btn btn-sm btn-dark border-secondary text-warning fw-bold d-flex align-items-center gap-1" onclick="applyCalcPreset(5)">
          🏭 Tier 5: Pabrik Ekspor Madu
        </button>
      </div>
    </div>

    <!-- DUAL COLUMN INPUTS -->
    <div class="row g-3 mb-3">
      
      <!-- KOLOM KIRI: KANDANG & LEBAH -->
      <div class="col-12 col-lg-6">
        <div class="c-card h-100">
          <div class="c-card-header d-flex align-items-center justify-content-between">
            <span class="c-card-title text-warning d-flex align-items-center gap-2">
              <i class="ph-bold ph-house-line"></i> 1. Kandang &amp; Populasi Lebah
            </span>
            <span class="badge bg-primary small" id="calc-badge-slots-status">2 Slot Lebah</span>
          </div>
          <div class="p-3">
            <div class="mb-3">
              <label class="form-label text-secondary small fw-bold">Pilih Kandang Master</label>
              <select id="calc-sel-hive" class="form-select bg-black text-white border-secondary" onchange="onCalcHiveChange()">
                <?php foreach ($hives as $h): ?>
                  <option value="<?= $h['id'] ?>"><?= htmlspecialchars($h['name']) ?> (<?= $h['max_slots'] ?> slot, +<?= (int)$h['bonus_speed_pct'] ?>% spd, Rp <?= number_format((float)$h['price'],0,',','.') ?>)</option>
                <?php endforeach; ?>
                <option value="custom">⚙️ Kustom / Atur Manual...</option>
              </select>
            </div>

            <div class="row g-2 mb-3">
              <div class="col-6">
                <label class="form-label text-secondary small">Harga Kandang (Rp)</label>
                <input type="number" id="calc-hive-price" class="form-control bg-black text-white border-secondary" value="20000" oninput="runLiveCalc()">
              </div>
              <div class="col-3">
                <label class="form-label text-secondary small">Max Slot</label>
                <input type="number" id="calc-hive-slots" class="form-control bg-black text-white border-secondary" value="2" min="1" max="50" oninput="onCalcSlotChange()">
              </div>
              <div class="col-3">
                <label class="form-label text-secondary small">Bonus Spd (%)</label>
                <input type="number" id="calc-hive-speed" class="form-control bg-black text-white border-secondary" value="0" min="0" oninput="runLiveCalc()">
              </div>
            </div>

            <hr class="border-secondary my-3">

            <div class="mb-3">
              <label class="form-label text-secondary small fw-bold">Pilih Spesies Lebah Master</label>
              <select id="calc-sel-bee" class="form-select bg-black text-white border-secondary" onchange="onCalcBeeChange()">
                <?php foreach ($bees as $b): ?>
                  <option value="<?= $b['id'] ?>"><?= htmlspecialchars($b['name']) ?> (+<?= number_format((float)$b['honey_per_hour'],1) ?> ml/jam, Rp <?= number_format((float)$b['price'],0,',','.') ?>)</option>
                <?php endforeach; ?>
                <option value="custom">⚙️ Kustom / Atur Manual...</option>
              </select>
            </div>

            <div class="row g-2 mb-3">
              <div class="col-4">
                <label class="form-label text-secondary small">Harga / Ekor (Rp)</label>
                <input type="number" id="calc-bee-price" class="form-control bg-black text-white border-secondary" value="10000" oninput="runLiveCalc()">
              </div>
              <div class="col-4">
                <label class="form-label text-secondary small">Laju Dasar (ml/jam)</label>
                <input type="number" step="0.1" id="calc-bee-rate" class="form-control bg-black text-white border-secondary" value="5" oninput="runLiveCalc()">
              </div>
              <div class="col-4">
                <label class="form-label text-secondary small">Jumlah Ekor</label>
                <input type="number" id="calc-bee-count" class="form-control bg-black text-white border-secondary" value="2" min="1" max="50" oninput="runLiveCalc()">
              </div>
            </div>

            <!-- Mini Produksi Status Box -->
            <div class="p-2 rounded bg-black border border-secondary">
              <div class="d-flex justify-content-between align-items-center small text-secondary">
                <span>Total Produksi Madu Bersih:</span>
                <span class="text-warning fw-bold" id="calc-sum-rate">0 ml / jam</span>
              </div>
              <div class="d-flex justify-content-between align-items-center small text-secondary mt-1">
                <span>Akumulasi Produksi per Hari (24 Jam):</span>
                <span class="text-info fw-bold" id="calc-sum-daily-ml">0 ml / hari</span>
              </div>
            </div>

          </div>
        </div>
      </div>

      <!-- KOLOM KANAN: LAPAK & REGULASI PASAR -->
      <div class="col-12 col-lg-6">
        <div class="c-card h-100">
          <div class="c-card-header d-flex align-items-center justify-content-between">
            <span class="c-card-title text-warning d-flex align-items-center gap-2">
              <i class="ph-bold ph-storefront"></i> 2. Lapak Jual &amp; Regulasi Pasar
            </span>
            <span class="badge bg-success small" id="calc-badge-stall-tier">Tier 1 Lapak</span>
          </div>
          <div class="p-3">
            <div class="mb-3">
              <label class="form-label text-secondary small fw-bold">Pilih Tier Lapak Master</label>
              <select id="calc-sel-stall" class="form-select bg-black text-white border-secondary" onchange="onCalcStallChange()">
                <?php foreach ($stalls as $s): ?>
                  <option value="<?= $s['id'] ?>">Tier <?= $s['tier_level'] ?>: <?= htmlspecialchars($s['name']) ?> (Rp <?= number_format((float)$s['sell_price_per_ml'],0,',','.') ?>/ml, Max <?= number_format((float)$s['daily_max_ml'],0) ?> ml/hari, Sewa Rp <?= number_format((float)$s['price'],0,',','.') ?>)</option>
                <?php endforeach; ?>
                <option value="custom">⚙️ Kustom / Atur Manual...</option>
              </select>
            </div>

            <div class="row g-2 mb-3">
              <div class="col-6">
                <label class="form-label text-secondary small">Harga Sewa Lapak (Rp)</label>
                <input type="number" id="calc-stall-price" class="form-control bg-black text-white border-secondary" value="0" oninput="runLiveCalc()">
              </div>
              <div class="col-6">
                <label class="form-label text-secondary small">Harga Jual Madu (Rp / ml)</label>
                <input type="number" step="1" id="calc-stall-rate" class="form-control bg-black text-white border-secondary" value="25" oninput="runLiveCalc()">
              </div>
            </div>

            <div class="row g-2 mb-3">
              <div class="col-6">
                <label class="form-label text-secondary small">Batas Maks Jual / Hari (ml)</label>
                <input type="number" step="10" id="calc-stall-maxml" class="form-control bg-black text-white border-secondary" value="100" oninput="runLiveCalc()">
              </div>
              <div class="col-6">
                <label class="form-label text-secondary small">Masa Aktif Simulasi (Hari)</label>
                <input type="number" id="calc-duration" class="form-control bg-black text-white border-secondary" value="30" min="1" max="365" oninput="runLiveCalc()">
              </div>
            </div>

            <!-- Mini Kapasitas Pasar Status Box -->
            <div class="p-2 rounded bg-black border border-secondary">
              <div class="d-flex justify-content-between align-items-center small text-secondary">
                <span>Kapasitas Maksimal Jual Harian:</span>
                <span class="text-success fw-bold" id="calc-sum-stall-cap">100 ml / hari</span>
              </div>
              <div class="d-flex justify-content-between align-items-center small text-secondary mt-1">
                <span>Maksimal Omset Lapak / Hari:</span>
                <span class="text-warning fw-bold" id="calc-sum-stall-daily-max">Rp 2.500 / hari</span>
              </div>
            </div>

          </div>
        </div>
      </div>

    </div>

    <!-- ── DASHBOARD HASIL KALKULASI PROFIT & FINANCIAL METRICS ── -->
    <div class="c-card mb-4 border-warning" style="box-shadow: 0 0 20px rgba(245,158,11,0.15);">
      <div class="c-card-header bg-black d-flex align-items-center justify-content-between border-secondary">
        <span class="c-card-title text-warning fw-bold fs-6 d-flex align-items-center gap-2">
          <i class="ph-bold ph-chart-line-up"></i> HASIL PROYEKSI &amp; KALKULASI FINANSIAL
        </span>
        <span class="badge bg-warning text-dark fw-bold px-3 py-1" id="calc-status-badge">Kalkulasi Otomatis Aktif</span>
      </div>

      <div class="p-3">
        <!-- 6 KPI CARDS -->
        <div class="row g-3 mb-3">
          
          <!-- 1. Omset Harian (Rupiah per Hari) -->
          <div class="col-12 col-md-6 col-xl-4">
            <div class="p-3 rounded bg-black border border-secondary h-100 position-relative">
              <div class="small text-secondary fw-bold text-uppercase d-flex justify-content-between">
                <span>Hasil Penjualan / Hari</span>
                <i class="ph-fill ph-money text-success fs-5"></i>
              </div>
              <div class="fs-4 fw-bold text-success mt-1" id="res-daily-rev">Rp 0 / hari</div>
              <div class="small text-muted mt-1" id="res-daily-desc">Madu terjual: 0 ml / hari</div>
            </div>
          </div>

          <!-- 2. Total Modal Awal (Investasi) -->
          <div class="col-12 col-md-6 col-xl-4">
            <div class="p-3 rounded bg-black border border-secondary h-100 position-relative">
              <div class="small text-secondary fw-bold text-uppercase d-flex justify-content-between">
                <span>Total Modal Awal (Investasi)</span>
                <i class="ph-fill ph-wallet text-warning fs-5"></i>
              </div>
              <div class="fs-4 fw-bold text-warning mt-1" id="res-total-capital">Rp 0</div>
              <div class="small text-muted mt-1" id="res-capital-desc">Kandang + Lebah + Lapak</div>
            </div>
          </div>

          <!-- 3. Total Omset Siklus Aktif -->
          <div class="col-12 col-md-6 col-xl-4">
            <div class="p-3 rounded bg-black border border-secondary h-100 position-relative">
              <div class="small text-secondary fw-bold text-uppercase d-flex justify-content-between">
                <span>Total Omset (<span id="res-days-label">30</span> Hari)</span>
                <i class="ph-fill ph-coins text-info fs-5"></i>
              </div>
              <div class="fs-4 fw-bold text-info mt-1" id="res-total-gross">Rp 0</div>
              <div class="small text-muted mt-1" id="res-gross-desc">Akumulasi pendapatan kotor</div>
            </div>
          </div>

          <!-- 4. Keuntungan Bersih (Net Profit) -->
          <div class="col-12 col-md-6 col-xl-4">
            <div class="p-3 rounded bg-black border border-secondary h-100 position-relative">
              <div class="small text-secondary fw-bold text-uppercase d-flex justify-content-between">
                <span>Keuntungan Bersih (Net Profit)</span>
                <i class="ph-fill ph-trend-up text-primary fs-5"></i>
              </div>
              <div class="fs-4 fw-bold mt-1" id="res-net-profit">Rp 0</div>
              <div class="small text-muted mt-1" id="res-profit-desc">Setelah dikurangi modal awal</div>
            </div>
          </div>

          <!-- 5. Return on Investment (ROI) -->
          <div class="col-12 col-md-6 col-xl-4">
            <div class="p-3 rounded bg-black border border-secondary h-100 position-relative">
              <div class="small text-secondary fw-bold text-uppercase d-flex justify-content-between">
                <span>Persentase ROI</span>
                <i class="ph-fill ph-percent text-warning fs-5"></i>
              </div>
              <div class="fs-4 fw-bold mt-1" id="res-roi-pct">0%</div>
              <div class="small text-muted mt-1" id="res-roi-desc">Return on Investment</div>
            </div>
          </div>

          <!-- 6. Break-Even Point (BEP) -->
          <div class="col-12 col-md-6 col-xl-4">
            <div class="p-3 rounded bg-black border border-secondary h-100 position-relative">
              <div class="small text-secondary fw-bold text-uppercase d-flex justify-content-between">
                <span>Balik Modal (BEP)</span>
                <i class="ph-fill ph-hourglass-high text-danger fs-5"></i>
              </div>
              <div class="fs-4 fw-bold text-white mt-1" id="res-bep-days">0 Hari</div>
              <div class="small text-muted mt-1" id="res-bep-desc">Sisa hari adalah pure profit</div>
            </div>
          </div>

        </div>

        <!-- ANALYSIS & HEALTH CHECK ALERT BANNER -->
        <div id="res-health-alert" class="alert alert-info d-flex align-items-center gap-2 mb-0">
          <i class="ph-bold ph-info fs-4 flex-shrink-0" id="res-health-icon"></i>
          <div id="res-health-text" class="small">
            Kalkulator siap digunakan. Pilih preset atau ubah angka di atas untuk melihat proyeksi keuntungan peternakan.
          </div>
        </div>

      </div>
    </div>

  </div>

</div>

<!-- ══════════════════════════════════════════════════════════
     MODAL EDIT KANDANG
     ══════════════════════════════════════════════════════════ -->
<div class="modal fade" id="modalEditHive" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content bg-dark border-secondary text-white">
      <form method="POST">
        <input type="hidden" name="action" value="update_hive">
        <input type="hidden" name="id" id="edit-hive-id">
        <div class="modal-header border-secondary">
          <h5 class="modal-title fw-bold text-warning"><i class="ph-bold ph-house-line"></i> Edit Kandang Lebah</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label text-secondary small">Nama Kandang</label>
            <input type="text" name="name" id="edit-hive-name" class="form-control bg-black text-white border-secondary" required>
          </div>
          <div class="mb-3">
            <label class="form-label text-secondary small">Deskripsi</label>
            <textarea name="description" id="edit-hive-desc" class="form-control bg-black text-white border-secondary" rows="2"></textarea>
          </div>
          <div class="row g-2 mb-3">
            <div class="col-6">
              <label class="form-label text-secondary small">Harga Beli (Rp)</label>
              <input type="number" step="1000" name="price" id="edit-hive-price" class="form-control bg-black text-white border-secondary" required oninput="updateEditHivePreview()">
            </div>
            <div class="col-6">
              <label class="form-label text-secondary small">Kapasitas Lebah</label>
              <input type="number" name="max_slots" id="edit-hive-slots" class="form-control bg-black text-white border-secondary" required oninput="updateEditHivePreview()">
            </div>
          </div>
          <div class="row g-2 mb-3">
            <div class="col-6">
              <label class="form-label text-secondary small">Bonus Kecepatan (%)</label>
              <input type="number" name="bonus_speed_pct" id="edit-hive-bonus" class="form-control bg-black text-white border-secondary" required oninput="updateEditHivePreview()">
            </div>
            <div class="col-6">
              <label class="form-label text-secondary small">Masa Aktif (Hari)</label>
              <input type="number" name="duration_days" id="edit-hive-duration" class="form-control bg-black text-white border-secondary" required oninput="updateEditHivePreview()">
            </div>
          </div>
          <div class="mb-3">
            <label class="form-label text-secondary small">Path Gambar Asset</label>
            <input type="text" name="image" id="edit-hive-image" class="form-control bg-black text-white border-secondary" required>
          </div>
          <div class="form-check form-switch mt-2">
            <input class="form-check-input" type="checkbox" name="is_active" id="edit-hive-active" value="1">
            <label class="form-check-label text-white" for="edit-hive-active">Kandang Aktif (Bisa dibeli user)</label>
          </div>

          <!-- LIVE MINI PREVIEW -->
          <div class="p-2 rounded bg-black border border-secondary mt-3">
            <div class="small text-secondary fw-bold mb-1">Live Karakteristik Kandang:</div>
            <div class="d-flex justify-content-between align-items-center small text-secondary">
              <span>Kapasitas Tangki Penyimpan:</span>
              <span class="text-warning fw-bold" id="edit-hive-prev-cap">0 ml</span>
            </div>
            <div class="d-flex justify-content-between align-items-center small text-secondary mt-1">
              <span>Efek Akselerasi Lebah:</span>
              <span class="text-info fw-bold" id="edit-hive-prev-spd">+0% speed</span>
            </div>
          </div>

        </div>
        <div class="modal-footer border-secondary">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
          <button type="submit" class="btn btn-warning fw-bold">Simpan Perubahan</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ══════════════════════════════════════════════════════════
     MODAL EDIT LEBAH
     ══════════════════════════════════════════════════════════ -->
<div class="modal fade" id="modalEditBee" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content bg-dark border-secondary text-white">
      <form method="POST">
        <input type="hidden" name="action" value="update_bee">
        <input type="hidden" name="id" id="edit-bee-id">
        <div class="modal-header border-secondary">
          <h5 class="modal-title fw-bold text-warning"><i class="ph-bold ph-flower"></i> Edit Spesies Lebah</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label text-secondary small">Nama Spesies Lebah</label>
            <input type="text" name="name" id="edit-bee-name" class="form-control bg-black text-white border-secondary" required>
          </div>
          <div class="mb-3">
            <label class="form-label text-secondary small">Deskripsi</label>
            <textarea name="description" id="edit-bee-desc" class="form-control bg-black text-white border-secondary" rows="2"></textarea>
          </div>
          <div class="row g-2 mb-3">
            <div class="col-6">
              <label class="form-label text-secondary small">Harga Beli (Rp)</label>
              <input type="number" step="1000" name="price" id="edit-bee-price" class="form-control bg-black text-white border-secondary" required oninput="updateEditBeePreview()">
            </div>
            <div class="col-6">
              <label class="form-label text-secondary small">Laju Madu (ml / jam)</label>
              <input type="number" step="0.1" name="honey_per_hour" id="edit-bee-rate" class="form-control bg-black text-white border-secondary" required oninput="updateEditBeePreview()">
            </div>
          </div>
          <div class="row g-2 mb-3">
            <div class="col-6">
              <label class="form-label text-secondary small">Masa Aktif (Hari)</label>
              <input type="number" name="duration_days" id="edit-bee-duration" class="form-control bg-black text-white border-secondary" required oninput="updateEditBeePreview()">
            </div>
            <div class="col-6">
              <label class="form-label text-secondary small">Path Gambar Asset</label>
              <input type="text" name="image" id="edit-bee-image" class="form-control bg-black text-white border-secondary" required>
            </div>
          </div>
          <div class="form-check form-switch mt-2">
            <input class="form-check-input" type="checkbox" name="is_active" id="edit-bee-active" value="1">
            <label class="form-check-label text-white" for="edit-bee-active">Lebah Aktif (Bisa dibeli user)</label>
          </div>

          <!-- LIVE MINI PREVIEW -->
          <div class="p-2 rounded bg-black border border-secondary mt-3">
            <div class="small text-secondary fw-bold mb-1">Live Proyeksi Produksi Lebah:</div>
            <div class="d-flex justify-content-between align-items-center small text-secondary">
              <span>Produksi per Hari:</span>
              <span class="text-warning fw-bold" id="edit-bee-prev-daily">0 ml / hari</span>
            </div>
            <div class="d-flex justify-content-between align-items-center small text-secondary mt-1">
              <span>Total Masa Aktif:</span>
              <span class="text-info fw-bold" id="edit-bee-prev-total">0 ml</span>
            </div>
            <div class="d-flex justify-content-between align-items-center small text-secondary mt-1">
              <span>Estimasi Nilai Madu (kurs Rp 25 - 55):</span>
              <span class="text-success fw-bold" id="edit-bee-prev-val">Rp 0 - Rp 0</span>
            </div>
          </div>

        </div>
        <div class="modal-footer border-secondary">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
          <button type="submit" class="btn btn-warning fw-bold">Simpan Perubahan</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ══════════════════════════════════════════════════════════
     MODAL EDIT LAPAK
     ══════════════════════════════════════════════════════════ -->
<div class="modal fade" id="modalEditStall" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content bg-dark border-secondary text-white">
      <form method="POST">
        <input type="hidden" name="action" value="update_stall">
        <input type="hidden" name="id" id="edit-stall-id">
        <div class="modal-header border-secondary">
          <h5 class="modal-title fw-bold text-warning"><i class="ph-bold ph-storefront"></i> Edit Tier Lapak Madu</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label text-secondary small">Nama Lapak</label>
            <input type="text" name="name" id="edit-stall-name" class="form-control bg-black text-white border-secondary" required>
          </div>
          <div class="mb-3">
            <label class="form-label text-secondary small">Deskripsi</label>
            <textarea name="description" id="edit-stall-desc" class="form-control bg-black text-white border-secondary" rows="2"></textarea>
          </div>
          <div class="row g-2 mb-3">
            <div class="col-6">
              <label class="form-label text-secondary small">Harga Sewa / Beli (Rp)</label>
              <input type="number" step="1000" name="price" id="edit-stall-price" class="form-control bg-black text-white border-secondary" required oninput="updateEditStallPreview()">
            </div>
            <div class="col-6">
              <label class="form-label text-secondary small">Harga Jual Madu (Rp / ml)</label>
              <input type="number" step="1" name="sell_price_per_ml" id="edit-stall-sellrate" class="form-control bg-black text-white border-secondary" required oninput="updateEditStallPreview()">
            </div>
          </div>
          <div class="row g-2 mb-3">
            <div class="col-6">
              <label class="form-label text-secondary small">Maks Jual per Hari (ml)</label>
              <input type="number" step="10" name="daily_max_ml" id="edit-stall-maxml" class="form-control bg-black text-white border-secondary" required oninput="updateEditStallPreview()">
            </div>
            <div class="col-6">
              <label class="form-label text-secondary small">Masa Aktif (Hari)</label>
              <input type="number" name="duration_days" id="edit-stall-duration" class="form-control bg-black text-white border-secondary" required oninput="updateEditStallPreview()">
            </div>
          </div>
          <div class="mb-3">
            <label class="form-label text-secondary small">Path Gambar Asset</label>
            <input type="text" name="image" id="edit-stall-image" class="form-control bg-black text-white border-secondary" required>
          </div>
          <div class="form-check form-switch mt-2">
            <input class="form-check-input" type="checkbox" name="is_active" id="edit-stall-active" value="1">
            <label class="form-check-label text-white" for="edit-stall-active">Lapak Aktif (Bisa disewa user)</label>
          </div>

          <!-- LIVE MINI PREVIEW -->
          <div class="p-2 rounded bg-black border border-secondary mt-3">
            <div class="small text-secondary fw-bold mb-1">Live Proyeksi Omset &amp; Profit Lapak:</div>
            <div class="d-flex justify-content-between align-items-center small text-secondary">
              <span>Maksimal Hasil / Hari:</span>
              <span class="text-success fw-bold" id="edit-stall-prev-daily">Rp 0 / hari</span>
            </div>
            <div class="d-flex justify-content-between align-items-center small text-secondary mt-1">
              <span>Total Potensi Omset:</span>
              <span class="text-warning fw-bold" id="edit-stall-prev-total">Rp 0</span>
            </div>
            <div class="d-flex justify-content-between align-items-center small text-secondary mt-1">
              <span>Potensi Keuntungan Bersih:</span>
              <span class="text-info fw-bold" id="edit-stall-prev-net">Rp 0</span>
            </div>
          </div>

        </div>
        <div class="modal-footer border-secondary">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
          <button type="submit" class="btn btn-warning fw-bold">Simpan Perubahan</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
// Master Data Objects for Instant Calculator Access
const MASTER_HIVES  = <?= json_encode($hives) ?>;
const MASTER_BEES   = <?= json_encode($bees) ?>;
const MASTER_STALLS = <?= json_encode($stalls) ?>;

// Utility Rupiah Formatter
function formatRp(val) {
  const num = Math.round(Number(val) || 0);
  return 'Rp ' + num.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ".");
}

function formatMl(val) {
  const num = Number(val) || 0;
  return (Math.round(num * 10) / 10).toLocaleString('id-ID');
}

// Tab Switcher Helper
function switchBeeTab(tabKey) {
  const tabEl = document.getElementById(tabKey + '-tab');
  if (tabEl) {
    const tabTrigger = new bootstrap.Tab(tabEl);
    tabTrigger.show();
    if (tabKey === 'calc') {
      runLiveCalc();
    }
  }
}

// ── KALKULATOR PROFIT & ROI SIMULATION ENGINE ──
function onCalcHiveChange() {
  const val = document.getElementById('calc-sel-hive').value;
  if (val === 'custom') return;
  const h = MASTER_HIVES.find(item => item.id == val);
  if (h) {
    document.getElementById('calc-hive-price').value = h.price;
    document.getElementById('calc-hive-slots').value = h.max_slots;
    document.getElementById('calc-hive-speed').value = h.bonus_speed_pct;
    onCalcSlotChange();
  }
  runLiveCalc();
}

function onCalcSlotChange() {
  const slots = parseInt(document.getElementById('calc-hive-slots').value) || 1;
  document.getElementById('calc-badge-slots-status').innerText = slots + ' Slot Lebah';
  const beeCountInput = document.getElementById('calc-bee-count');
  beeCountInput.max = slots;
  if (parseInt(beeCountInput.value) > slots) {
    beeCountInput.value = slots;
  }
  runLiveCalc();
}

function onCalcBeeChange() {
  const val = document.getElementById('calc-sel-bee').value;
  if (val === 'custom') return;
  const b = MASTER_BEES.find(item => item.id == val);
  if (b) {
    document.getElementById('calc-bee-price').value = b.price;
    document.getElementById('calc-bee-rate').value = b.honey_per_hour;
  }
  runLiveCalc();
}

function onCalcStallChange() {
  const val = document.getElementById('calc-sel-stall').value;
  if (val === 'custom') return;
  const s = MASTER_STALLS.find(item => item.id == val);
  if (s) {
    document.getElementById('calc-stall-price').value = s.price;
    document.getElementById('calc-stall-rate').value = s.sell_price_per_ml;
    document.getElementById('calc-stall-maxml').value = s.daily_max_ml;
    document.getElementById('calc-duration').value = s.duration_days;
    document.getElementById('calc-badge-stall-tier').innerText = 'Tier ' + s.tier_level + ' Lapak';
  }
  runLiveCalc();
}

function loadStallToCalc(stallId) {
  switchBeeTab('calc');
  const stallSel = document.getElementById('calc-sel-stall');
  if (stallSel) {
    stallSel.value = stallId;
    onCalcStallChange();
  }
}

function applyCalcPreset(presetTier) {
  // Preset 1: Bambu (2 slot) + 2x Apis + Lapak Raw
  // Preset 2: Kayu Jati (4 slot, +10%) + 4x Trigona + Lapak Golden
  // Preset 3: Paviliun (6 slot, +20%) + 6x Mellifera + Lapak Royal
  // Preset 4: Istana Royal (8 slot, +30%) + 8x Royal Queen + Lapak Imperial
  // Preset 5: Istana Royal (8 slot, +30%) + 8x Royal Queen + Pabrik Ekspor

  const hiveIndex = Math.min(presetTier - 1, MASTER_HIVES.length - 1);
  const beeIndex  = Math.min(presetTier - 1, MASTER_BEES.length - 1);
  const stallIndex= Math.min(presetTier - 1, MASTER_STALLS.length - 1);

  const h = MASTER_HIVES[hiveIndex] || MASTER_HIVES[0];
  const b = MASTER_BEES[beeIndex]   || MASTER_BEES[0];
  const s = MASTER_STALLS[stallIndex] || MASTER_STALLS[0];

  document.getElementById('calc-sel-hive').value = h ? h.id : 'custom';
  document.getElementById('calc-hive-price').value = h ? h.price : 20000;
  document.getElementById('calc-hive-slots').value = h ? h.max_slots : 2;
  document.getElementById('calc-hive-speed').value = h ? h.bonus_speed_pct : 0;
  document.getElementById('calc-badge-slots-status').innerText = (h ? h.max_slots : 2) + ' Slot Lebah';

  document.getElementById('calc-sel-bee').value = b ? b.id : 'custom';
  document.getElementById('calc-bee-price').value = b ? b.price : 10000;
  document.getElementById('calc-bee-rate').value = b ? b.honey_per_hour : 5;
  document.getElementById('calc-bee-count').value = h ? h.max_slots : 2;

  document.getElementById('calc-sel-stall').value = s ? s.id : 'custom';
  document.getElementById('calc-stall-price').value = s ? s.price : 0;
  document.getElementById('calc-stall-rate').value = s ? s.sell_price_per_ml : 25;
  document.getElementById('calc-stall-maxml').value = s ? s.daily_max_ml : 100;
  document.getElementById('calc-duration').value = s ? s.duration_days : 30;
  document.getElementById('calc-badge-stall-tier').innerText = s ? ('Tier ' + s.tier_level + ' Lapak') : 'Tier Lapak';

  runLiveCalc();
}

function resetCalcToDefaults() {
  applyCalcPreset(1);
}

function runLiveCalc() {
  const hivePrice  = Math.max(0, parseFloat(document.getElementById('calc-hive-price').value) || 0);
  const hiveSlots  = Math.max(1, parseInt(document.getElementById('calc-hive-slots').value) || 1);
  const hiveSpeed  = Math.max(0, parseFloat(document.getElementById('calc-hive-speed').value) || 0);

  const beePrice   = Math.max(0, parseFloat(document.getElementById('calc-bee-price').value) || 0);
  const beeRate    = Math.max(0, parseFloat(document.getElementById('calc-bee-rate').value) || 0);
  const beeCount   = Math.max(1, parseInt(document.getElementById('calc-bee-count').value) || 1);

  const stallPrice = Math.max(0, parseFloat(document.getElementById('calc-stall-price').value) || 0);
  const stallRate  = Math.max(0, parseFloat(document.getElementById('calc-stall-rate').value) || 0);
  const stallMaxMl = Math.max(0, parseFloat(document.getElementById('calc-stall-maxml').value) || 0);
  const duration   = Math.max(1, parseInt(document.getElementById('calc-duration').value) || 30);

  // 1. Produksi Madu
  const speedBonusMultiplier = 1.0 + (hiveSpeed / 100.0);
  const effectiveRatePerBee = beeRate * speedBonusMultiplier;
  const totalHourlyMl = effectiveRatePerBee * beeCount;
  const totalDailyMl  = totalHourlyMl * 24.0;

  document.getElementById('calc-sum-rate').innerText = formatMl(totalHourlyMl) + ' ml / jam';
  document.getElementById('calc-sum-daily-ml').innerText = formatMl(totalDailyMl) + ' ml / hari';

  // 2. Kapasitas Lapak
  const maxStallDailyGross = stallMaxMl * stallRate;
  document.getElementById('calc-sum-stall-cap').innerText = formatMl(stallMaxMl) + ' ml / hari';
  document.getElementById('calc-sum-stall-daily-max').innerText = formatRp(maxStallDailyGross) + ' / hari';

  // 3. Penjualan Aktual & Finansial
  const actualSoldDailyMl = Math.min(totalDailyMl, stallMaxMl);
  const wastedDailyMl     = Math.max(0, totalDailyMl - stallMaxMl);
  const dailyGrossRev     = actualSoldDailyMl * stallRate;

  // 4. Modal Investasi Awal
  const totalCapital = hivePrice + (beePrice * beeCount) + stallPrice;

  // 5. Total Omset & Profit Siklus
  const totalGrossRev = dailyGrossRev * duration;
  const netProfit     = totalGrossRev - totalCapital;
  const roiPct        = totalCapital > 0 ? ((netProfit / totalCapital) * 100.0) : (totalGrossRev > 0 ? 100.0 : 0.0);
  const bepDays       = dailyGrossRev > 0 ? Math.ceil(totalCapital / dailyGrossRev) : 0;

  // Render to UI
  document.getElementById('res-daily-rev').innerText = formatRp(dailyGrossRev) + ' / hari';
  document.getElementById('res-daily-desc').innerText = 'Madu terjual: ' + formatMl(actualSoldDailyMl) + ' ml / hari (Kurs ' + formatRp(stallRate) + '/ml)';

  document.getElementById('res-total-capital').innerText = formatRp(totalCapital);
  document.getElementById('res-capital-desc').innerText = 'Kandang ' + formatRp(hivePrice) + ' + ' + beeCount + ' Lebah ' + formatRp(beePrice * beeCount) + ' + Lapak ' + formatRp(stallPrice);

  document.getElementById('res-days-label').innerText = duration;
  document.getElementById('res-total-gross').innerText = formatRp(totalGrossRev);
  document.getElementById('res-gross-desc').innerText = 'Akumulasi pendapatan kotor selama ' + duration + ' hari';

  const netElem = document.getElementById('res-net-profit');
  if (netProfit >= 0) {
    netElem.className = 'fs-4 fw-bold mt-1 text-success';
    netElem.innerText = '+' + formatRp(netProfit);
  } else {
    netElem.className = 'fs-4 fw-bold mt-1 text-danger';
    netElem.innerText = '-' + formatRp(Math.abs(netProfit));
  }
  document.getElementById('res-profit-desc').innerText = netProfit >= 0 ? 'Keuntungan bersih setelah modal tertutup' : 'Defisit (Omset belum menutupi modal)';

  const roiElem = document.getElementById('res-roi-pct');
  if (roiPct >= 0) {
    roiElem.className = 'fs-4 fw-bold mt-1 text-warning';
    roiElem.innerText = '+' + (Math.round(roiPct * 10) / 10) + '%';
  } else {
    roiElem.className = 'fs-4 fw-bold mt-1 text-danger';
    roiElem.innerText = (Math.round(roiPct * 10) / 10) + '%';
  }
  document.getElementById('res-roi-desc').innerText = 'Rasio Return on Investment';

  const bepElem = document.getElementById('res-bep-days');
  if (dailyGrossRev > 0 && bepDays <= duration) {
    bepElem.innerText = 'Hari ke-' + bepDays;
    document.getElementById('res-bep-desc').innerText = 'Sisa ' + Math.max(0, duration - bepDays) + ' hari berikutnya adalah masa laba bersih!';
  } else if (dailyGrossRev > 0) {
    bepElem.innerText = '>' + duration + ' Hari';
    document.getElementById('res-bep-desc').innerText = 'Waktu balik modal melebihi masa aktif yang ditentukan.';
  } else {
    bepElem.innerText = 'Tidak Balik Modal';
    document.getElementById('res-bep-desc').innerText = 'Belum ada hasil penjualan yang terbentuk.';
  }

  // 6. HEALTH CHECK EVALUATION & BOTTLENECK WARNING
  const alertBox  = document.getElementById('res-health-alert');
  const alertIcon = document.getElementById('res-health-icon');
  const alertText = document.getElementById('res-health-text');

  let alertClass = 'alert-info';
  let iconClass  = 'ph-bold ph-info';
  let message    = '';

  if (wastedDailyMl > 1) {
    alertClass = 'alert-warning';
    iconClass  = 'ph-bold ph-warning';
    message += '⚠️ <strong>Bottleneck Lapak:</strong> Produksi madu (' + formatMl(totalDailyMl) + ' ml/hari) melampaui kuota jual lapak (' + formatMl(stallMaxMl) + ' ml/hari). Ada <strong>' + formatMl(wastedDailyMl) + ' ml madu/hari</strong> yang menumpuk di stok. User disarankan upgrade tier lapak agar semua madu bisa diuangkan.<br>';
  }

  if (netProfit < 0) {
    alertClass = 'alert-danger';
    iconClass  = 'ph-bold ph-warning-circle';
    message += '❌ <strong>Peringatan Defisit:</strong> Pengguna merugi <strong>' + formatRp(Math.abs(netProfit)) + '</strong>! Total modal awal lebih besar dari potensi omset selama ' + duration + ' hari. Naikkan harga jual madu atau turunkan harga modal.';
  } else if (roiPct > 120) {
    if (!message) alertClass = 'alert-warning';
    message += '⚡ <strong>Peringatan Margin Platform:</strong> Keuntungan pengguna sangat tinggi (+<strong>' + Math.round(roiPct) + '%</strong>). Balik modal dalam <strong>' + bepDays + ' hari</strong>. Pastikan cadangan dana kas platform memadai untuk pembayaran penarikan saldo.';
  } else if (roiPct >= 20 && roiPct <= 120) {
    if (!message) alertClass = 'alert-success';
    iconClass = 'ph-bold ph-check-circle';
    message += '✅ <strong>Keseimbangan Finansial Sangat Ideal:</strong> Margin keuntungan pengguna menarik (+<strong>' + Math.round(roiPct) + '%</strong>), waktu balik modal <strong>' + bepDays + ' hari</strong>. Sangat aman dan menguntungkan bagi kelangsungan ekosistem platform.';
  } else {
    if (!message) alertClass = 'alert-info';
    message += 'ℹ️ Margin keuntungan tipis (+<strong>' + Math.round(roiPct) + '%</strong>). User balik modal pada hari ke-<strong>' + bepDays + '</strong>.';
  }

  alertBox.className = 'alert ' + alertClass + ' d-flex align-items-center gap-2 mb-0';
  alertIcon.className = iconClass + ' fs-4 flex-shrink-0';
  alertText.innerHTML = message;
}

// ── LIVE PREVIEW IN EDIT MODALS ──
function updateEditHivePreview() {
  const slots = parseInt(document.getElementById('edit-hive-slots').value) || 0;
  const bonus = parseInt(document.getElementById('edit-hive-bonus').value) || 0;
  const tankCap = Math.max(100, slots * 75);
  document.getElementById('edit-hive-prev-cap').innerText = formatMl(tankCap) + ' ml';
  document.getElementById('edit-hive-prev-spd').innerText = '+' + bonus + '% speed';
}

function updateEditBeePreview() {
  const rate = parseFloat(document.getElementById('edit-bee-rate').value) || 0;
  const days = parseInt(document.getElementById('edit-bee-duration').value) || 30;
  const dailyMl = rate * 24.0;
  const totalMl = dailyMl * days;
  document.getElementById('edit-bee-prev-daily').innerText = formatMl(dailyMl) + ' ml / hari';
  document.getElementById('edit-bee-prev-total').innerText = formatMl(totalMl) + ' ml (' + days + ' hari)';
  document.getElementById('edit-bee-prev-val').innerText = formatRp(totalMl * 25) + ' - ' + formatRp(totalMl * 55);
}

function updateEditStallPreview() {
  const price   = parseFloat(document.getElementById('edit-stall-price').value) || 0;
  const sellRate= parseFloat(document.getElementById('edit-stall-sellrate').value) || 0;
  const maxMl   = parseFloat(document.getElementById('edit-stall-maxml').value) || 0;
  const days    = parseInt(document.getElementById('edit-stall-duration').value) || 30;

  const dailyRev = maxMl * sellRate;
  const totalRev = dailyRev * days;
  const net      = totalRev - price;

  document.getElementById('edit-stall-prev-daily').innerText = formatRp(dailyRev) + ' / hari';
  document.getElementById('edit-stall-prev-total').innerText = formatRp(totalRev) + ' (' + days + ' hari)';
  document.getElementById('edit-stall-prev-net').innerText   = (net >= 0 ? '+' : '') + formatRp(net);
}

// Modal Open Handlers
function openEditHiveModal(h) {
  document.getElementById('edit-hive-id').value = h.id;
  document.getElementById('edit-hive-name').value = h.name;
  document.getElementById('edit-hive-desc').value = h.description || '';
  document.getElementById('edit-hive-price').value = h.price;
  document.getElementById('edit-hive-slots').value = h.max_slots;
  document.getElementById('edit-hive-bonus').value = h.bonus_speed_pct;
  document.getElementById('edit-hive-duration').value = h.duration_days;
  document.getElementById('edit-hive-image').value = h.image;
  document.getElementById('edit-hive-active').checked = parseInt(h.is_active) === 1;
  updateEditHivePreview();
  new bootstrap.Modal(document.getElementById('modalEditHive')).show();
}

function openEditBeeModal(b) {
  document.getElementById('edit-bee-id').value = b.id;
  document.getElementById('edit-bee-name').value = b.name;
  document.getElementById('edit-bee-desc').value = b.description || '';
  document.getElementById('edit-bee-price').value = b.price;
  document.getElementById('edit-bee-rate').value = b.honey_per_hour;
  document.getElementById('edit-bee-duration').value = b.duration_days;
  document.getElementById('edit-bee-image').value = b.image;
  document.getElementById('edit-bee-active').checked = parseInt(b.is_active) === 1;
  updateEditBeePreview();
  new bootstrap.Modal(document.getElementById('modalEditBee')).show();
}

function openEditStallModal(s) {
  document.getElementById('edit-stall-id').value = s.id;
  document.getElementById('edit-stall-name').value = s.name;
  document.getElementById('edit-stall-desc').value = s.description || '';
  document.getElementById('edit-stall-price').value = s.price;
  document.getElementById('edit-stall-sellrate').value = s.sell_price_per_ml;
  document.getElementById('edit-stall-maxml').value = s.daily_max_ml;
  document.getElementById('edit-stall-duration').value = s.duration_days;
  document.getElementById('edit-stall-image').value = s.image;
  document.getElementById('edit-stall-active').checked = parseInt(s.is_active) === 1;
  updateEditStallPreview();
  new bootstrap.Modal(document.getElementById('modalEditStall')).show();
}

// Initialize on Load
document.addEventListener('DOMContentLoaded', function() {
  runLiveCalc();
});
</script>

<?php require __DIR__ . '/partials/footer.php'; ?>
