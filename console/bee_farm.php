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
    <a href="/console/bee_logs.php" class="btn btn-outline-warning btn-sm d-flex align-items-center gap-1">
      <i class="ph-fill ph-receipt"></i> Lihat Log Panen &amp; Jual
    </a>
    <a href="/farm" target="_blank" class="btn btn-warning btn-sm fw-bold d-flex align-items-center gap-1">
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
      <div class="c-stat__val text-amber"><?= number_format($statHoneySupply, 1) ?> <span class="fs-6">ml</span></div>
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
              <td><span class="badge bg-primary fs-7"><?= (int)$h['max_slots'] ?> Lebah</span></td>
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
              <th>Harga Beli</th>
              <th>Masa Aktif</th>
              <th>Status</th>
              <th class="text-end">Aksi</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($bees as $b): ?>
            <tr>
              <td>
                <img src="<?= htmlspecialchars($b['image']) ?>" alt="" style="width:48px;height:48px;object-fit:contain;background:#181b2a;border-radius:10px;padding:4px;border:1px solid #2a2f45;">
              </td>
              <td>
                <div class="fw-bold text-white"><?= htmlspecialchars($b['name']) ?></div>
                <div class="small text-muted"><?= htmlspecialchars($b['description']) ?></div>
              </td>
              <td>
                <span class="badge bg-success fs-7">
                  <i class="ph-fill ph-drop"></i> +<?= number_format((float)$b['honey_per_hour'], 1) ?> ml / jam
                </span>
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
              <th>Harga Jual Madu</th>
              <th>Maks Jual / Hari</th>
              <th>Harga Sewa Lapak</th>
              <th>Masa Aktif</th>
              <th>Status</th>
              <th class="text-end">Aksi</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($stalls as $s): ?>
            <tr>
              <td>
                <img src="<?= htmlspecialchars($s['image']) ?>" alt="" style="width:48px;height:48px;object-fit:contain;background:#181b2a;border-radius:10px;padding:4px;border:1px solid #2a2f45;">
              </td>
              <td>
                <div class="fw-bold text-white">Tier <?= (int)$s['tier_level'] ?>: <?= htmlspecialchars($s['name']) ?></div>
                <div class="small text-muted"><?= htmlspecialchars($s['description']) ?></div>
              </td>
              <td>
                <span class="badge bg-success fs-7">Rp <?= number_format((float)$s['sell_price_per_ml'], 0, ',', '.') ?> / ml</span>
              </td>
              <td><span class="badge bg-info text-dark fw-bold"><?= number_format((float)$s['daily_max_ml'], 0) ?> ml / hari</span></td>
              <td class="text-warning fw-bold">Rp <?= number_format((float)$s['price'], 0, ',', '.') ?></td>
              <td><?= (int)$s['duration_days'] ?> Hari</td>
              <td>
                <?php if ($s['is_active']): ?>
                  <span class="badge bg-success">Aktif</span>
                <?php else: ?>
                  <span class="badge bg-secondary">Nonaktif</span>
                <?php endif; ?>
              </td>
              <td class="text-end">
                <button class="btn btn-sm btn-outline-warning" onclick='openEditStallModal(<?= json_encode($s) ?>)'>
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
              <input type="number" step="1000" name="price" id="edit-hive-price" class="form-control bg-black text-white border-secondary" required>
            </div>
            <div class="col-6">
              <label class="form-label text-secondary small">Kapasitas Lebah</label>
              <input type="number" name="max_slots" id="edit-hive-slots" class="form-control bg-black text-white border-secondary" required>
            </div>
          </div>
          <div class="row g-2 mb-3">
            <div class="col-6">
              <label class="form-label text-secondary small">Bonus Kecepatan (%)</label>
              <input type="number" name="bonus_speed_pct" id="edit-hive-bonus" class="form-control bg-black text-white border-secondary" required>
            </div>
            <div class="col-6">
              <label class="form-label text-secondary small">Masa Aktif (Hari)</label>
              <input type="number" name="duration_days" id="edit-hive-duration" class="form-control bg-black text-white border-secondary" required>
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
              <input type="number" step="1000" name="price" id="edit-bee-price" class="form-control bg-black text-white border-secondary" required>
            </div>
            <div class="col-6">
              <label class="form-label text-secondary small">Laju Madu (ml / jam)</label>
              <input type="number" step="0.1" name="honey_per_hour" id="edit-bee-rate" class="form-control bg-black text-white border-secondary" required>
            </div>
          </div>
          <div class="row g-2 mb-3">
            <div class="col-6">
              <label class="form-label text-secondary small">Masa Aktif (Hari)</label>
              <input type="number" name="duration_days" id="edit-bee-duration" class="form-control bg-black text-white border-secondary" required>
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
              <input type="number" step="1000" name="price" id="edit-stall-price" class="form-control bg-black text-white border-secondary" required>
            </div>
            <div class="col-6">
              <label class="form-label text-secondary small">Harga Jual Madu (Rp / ml)</label>
              <input type="number" step="1" name="sell_price_per_ml" id="edit-stall-sellrate" class="form-control bg-black text-white border-secondary" required>
            </div>
          </div>
          <div class="row g-2 mb-3">
            <div class="col-6">
              <label class="form-label text-secondary small">Maks Jual per Hari (ml)</label>
              <input type="number" step="10" name="daily_max_ml" id="edit-stall-maxml" class="form-control bg-black text-white border-secondary" required>
            </div>
            <div class="col-6">
              <label class="form-label text-secondary small">Masa Aktif (Hari)</label>
              <input type="number" name="duration_days" id="edit-stall-duration" class="form-control bg-black text-white border-secondary" required>
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
  new bootstrap.Modal(document.getElementById('modalEditStall')).show();
}
</script>

<?php require __DIR__ . '/partials/footer.php'; ?>
