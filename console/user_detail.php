<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
staff_require('users');

$uid = (int)($_GET['id'] ?? 0);
if (!$uid) {
    echo "ID User tidak valid.";
    exit;
}

// Get user info
$stmt = $pdo->prepare("SELECT u.*, m.name as membership_name FROM users u LEFT JOIN memberships m ON m.id=u.membership_id WHERE u.id=?");
$stmt->execute([$uid]);
$u = $stmt->fetch();
if (!$u) {
    echo "User tidak ditemukan.";
    exit;
}

$flash = $flashType = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_debug_mode') {
    $is_dbg  = isset($_POST['is_debug']) ? 1 : 0;
    $dbg_dur = max(1, (int)($_POST['debug_watch_duration'] ?? 3));
    $dbg_rwd = max(0.0, (float)($_POST['debug_watch_reward'] ?? 50000.00));

    $pdo->prepare("UPDATE users SET is_debug=?, debug_watch_duration=?, debug_watch_reward=? WHERE id=?")
        ->execute([$is_dbg, $dbg_dur, $dbg_rwd, $uid]);

    $stmt->execute([$uid]);
    $u = $stmt->fetch();
    $flash = "Pengaturan Mode Debug berhasil disimpan!";
    $flashType = "success";
}

// Pagination parameters
$depPage = max(1, (int)($_GET['dep_page'] ?? 1));
$wdPage  = max(1, (int)($_GET['wd_page'] ?? 1));
$perPage = 10;

// Deposits
$depOffset = ($depPage - 1) * $perPage;
$depTotal = (int)$pdo->prepare("SELECT COUNT(*) FROM deposits WHERE user_id=?")->execute([$uid]) ? $pdo->query("SELECT FOUND_ROWS()")->fetchColumn() : 0; // Better way:
$dtStmt = $pdo->prepare("SELECT COUNT(*) FROM deposits WHERE user_id=?");
$dtStmt->execute([$uid]);
$depTotal = (int)$dtStmt->fetchColumn();
$depTotalPages = max(1, ceil($depTotal / $perPage));

$depStmt = $pdo->prepare("SELECT * FROM deposits WHERE user_id=? ORDER BY created_at DESC LIMIT $perPage OFFSET $depOffset");
$depStmt->execute([$uid]);
$deposits = $depStmt->fetchAll();

// Withdrawals
$wdOffset = ($wdPage - 1) * $perPage;
$wtStmt = $pdo->prepare("SELECT COUNT(*) FROM withdrawals WHERE user_id=?");
$wtStmt->execute([$uid]);
$wdTotal = (int)$wtStmt->fetchColumn();
$wdTotalPages = max(1, ceil($wdTotal / $perPage));

$wdStmt = $pdo->prepare("SELECT * FROM withdrawals WHERE user_id=? ORDER BY created_at DESC LIMIT $perPage OFFSET $wdOffset");
$wdStmt->execute([$uid]);
$withdrawals = $wdStmt->fetchAll();

$pageTitle = 'Detail User';
$activePage = 'users';
require __DIR__ . '/partials/header.php';
?>

<div class="d-flex align-items-center mb-4">
  <a href="javascript:history.back()" class="btn btn-sm btn-secondary me-3" style="border-radius:8px">← Kembali</a>
  <div>
    <h5 class="mb-0 fw-bold">👤 Detail Pengguna</h5>
    <small class="text-secondary"><?= htmlspecialchars($u['username']) ?></small>
  </div>
</div>

<?php if ($flash): ?>
<div class="alert alert-success py-2 mb-3" style="border-radius:10px;font-size:13px"><?= htmlspecialchars($flash) ?></div>
<?php endif; ?>

<div class="row g-4">
  <!-- Info User -->
  <div class="col-md-4">
    <div class="c-card h-100">
      <div class="c-card-header"><span class="c-card-title">Profil User</span></div>
      <div class="c-card-body">
        <div style="font-size:24px;font-weight:800;color:var(--brand);margin-bottom:5px"><?= htmlspecialchars($u['username']) ?></div>
        <div style="color:#888;font-size:13px;margin-bottom:20px"><?= htmlspecialchars($u['email']) ?> <br> <?= htmlspecialchars($u['whatsapp'] ?: '-') ?></div>
        
        <table class="table table-sm table-borderless text-white" style="font-size:13px">
          <tr><td style="color:#888">Status</td><td>
            <span class="badge <?= $u['is_active'] ? 'bg-success' : 'bg-danger' ?>"><?= $u['is_active'] ? 'Aktif' : 'Nonaktif' ?></span>
            <?php if (!empty($u['is_debug'])): ?>
              <span class="badge" style="background:#6366f1;color:#fff;font-size:10px;margin-left:4px">🛠 DEBUG TESTER</span>
            <?php endif; ?>
          </td></tr>
          <tr><td style="color:#888">Membership</td><td><?= htmlspecialchars($u['membership_name'] ?: get_free_tier_name($pdo)) ?></td></tr>
          <tr><td style="color:#888">Saldo Beli</td><td style="color:#4E9BFF;font-weight:700"><?= format_rp((float)$u['balance_dep']) ?></td></tr>
          <tr><td style="color:#888">Saldo Penarikan</td><td style="color:#4CAF82;font-weight:700"><?= format_rp((float)$u['balance_wd']) ?></td></tr>
          <tr><td style="color:#888">Total Earned</td><td><?= format_rp((float)$u['total_earned']) ?></td></tr>
          <tr><td style="color:#888">Bank</td><td><?= htmlspecialchars($u['bank_name'] ?: '-') ?></td></tr>
          <tr><td style="color:#888">No. Rek</td><td><?= htmlspecialchars($u['account_number'] ?: '-') ?></td></tr>
          <tr><td style="color:#888">A.N.</td><td><?= htmlspecialchars($u['account_name'] ?: '-') ?></td></tr>
          <tr><td style="color:#888">Referral Code</td><td><?= htmlspecialchars($u['referral_code']) ?></td></tr>
          <tr><td style="color:#888">Referred By</td><td><?= htmlspecialchars($u['referred_by'] ?: '-') ?></td></tr>
          <tr><td style="color:#888">Terdaftar</td><td><?= date('d M Y H:i', strtotime($u['created_at'])) ?></td></tr>
        </table>

        <!-- Mode Debug Tester Card -->
        <div class="mt-3 p-3" style="background:#161922;border:1px solid <?= !empty($u['is_debug']) ? '#818cf8' : '#232738' ?>;border-radius:12px">
          <div class="d-flex align-items-center justify-content-between mb-2">
            <span style="font-size:12px;font-weight:800;color:<?= !empty($u['is_debug']) ? '#818cf8' : '#94a3b8' ?>">
              🛠 Mode Debug Tester
            </span>
            <span class="badge" style="background:<?= !empty($u['is_debug']) ? 'rgba(99,102,241,0.2)' : 'rgba(255,255,255,0.06)' ?>;color:<?= !empty($u['is_debug']) ? '#818cf8' : '#888' ?>">
              <?= !empty($u['is_debug']) ? 'AKTIF' : 'NONAKTIF' ?>
            </span>
          </div>
          <p style="font-size:11px;color:#888;margin-bottom:10px">
            Jika aktif, akun ini akan memunculkan toolbar pengatur durasi tonton pendek & custom benefit saat membuka halaman tonton video.
          </p>
          <form method="POST">
            <input type="hidden" name="action" value="save_debug_mode">
            <div class="form-check form-switch mb-2">
              <input class="form-check-input" type="checkbox" name="is_debug" value="1" id="chkDebug" <?= !empty($u['is_debug']) ? 'checked' : '' ?>>
              <label class="form-check-label text-white" for="chkDebug" style="font-size:12px;font-weight:700">Aktifkan Mode Debug</label>
            </div>
            <div class="row g-2 mb-2">
              <div class="col-6">
                <label style="font-size:10.5px;color:#aaa">Durasi (Detik)</label>
                <input type="number" name="debug_watch_duration" class="form-control form-control-sm text-white" style="background:#0f172a;border-color:#334155;font-size:11.5px" min="1" value="<?= (int)($u['debug_watch_duration'] ?: 3) ?>">
              </div>
              <div class="col-6">
                <label style="font-size:10.5px;color:#aaa">Benefit / Reward (Rp)</label>
                <input type="number" name="debug_watch_reward" class="form-control form-control-sm text-white" style="background:#0f172a;border-color:#334155;font-size:11.5px" min="0" step="1000" value="<?= (int)($u['debug_watch_reward'] ?: 50000) ?>">
              </div>
            </div>
            <button type="submit" class="btn btn-sm w-100" style="background:#6366f1;color:#fff;font-weight:800;border-radius:8px;font-size:11.5px">
              Simpan Pengaturan Debug
            </button>
          </form>
        </div>

  <!-- History -->
  <div class="col-md-8">
    <ul class="nav nav-pills mb-3" id="pills-tab" role="tablist">
      <li class="nav-item" role="presentation">
        <button class="nav-link <?= !isset($_GET['wd_page']) ? 'active' : '' ?>" id="pills-depo-tab" data-bs-toggle="pill" data-bs-target="#pills-depo" type="button" role="tab" style="font-size:13px;border-radius:8px">History Deposit</button>
      </li>
      <li class="nav-item" role="presentation">
        <button class="nav-link <?= isset($_GET['wd_page']) ? 'active' : '' ?>" id="pills-wd-tab" data-bs-toggle="pill" data-bs-target="#pills-wd" type="button" role="tab" style="font-size:13px;border-radius:8px">History Withdraw</button>
      </li>
    </ul>

    <div class="tab-content" id="pills-tabContent">
      <!-- Deposit Tab -->
      <div class="tab-pane fade <?= !isset($_GET['wd_page']) ? 'show active' : '' ?>" id="pills-depo" role="tabpanel">
        <div class="c-card">
          <div style="overflow-x:auto">
            <table class="c-table">
              <thead><tr><th>Waktu</th><th>Jumlah</th><th>Metode</th><th>Status</th></tr></thead>
              <tbody>
                <?php foreach ($deposits as $d): ?>
                <tr>
                  <td style="font-size:12px;color:#888"><?= date('d M Y H:i', strtotime($d['created_at'])) ?></td>
                  <td style="color:#4CAF82;font-weight:700"><?= format_rp((float)$d['amount']) ?></td>
                  <td><span class="badge bg-secondary" style="font-size:11px"><?= strtoupper($d['method']) ?></span></td>
                  <td>
                    <span class="badge <?= match($d['status']){'confirmed'=>'bg-success','pending'=>'bg-warning text-dark','rejected'=>'bg-danger',default=>'bg-secondary'} ?>" style="font-size:11px">
                      <?= ucfirst($d['status']) ?>
                    </span>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <?php if($depTotalPages > 1): ?>
          <div class="d-flex justify-content-center mt-3 pb-3">
            <nav><ul class="pagination pagination-sm mb-0">
              <?php for($i=1; $i<=$depTotalPages; $i++): ?>
              <li class="page-item <?= $i===$depPage ? 'active' : '' ?>">
                <a class="page-link" href="?id=<?= $uid ?>&dep_page=<?= $i ?>#pills-depo"><?= $i ?></a>
              </li>
              <?php endfor; ?>
            </ul></nav>
          </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- Withdraw Tab -->
      <div class="tab-pane fade <?= isset($_GET['wd_page']) ? 'show active' : '' ?>" id="pills-wd" role="tabpanel">
        <div class="c-card">
          <div style="overflow-x:auto">
            <table class="c-table">
              <thead><tr><th>Waktu</th><th>Jumlah</th><th>Status</th><th>Catatan</th></tr></thead>
              <tbody>
                <?php foreach ($withdrawals as $w): ?>
                <tr>
                  <td style="font-size:12px;color:#888"><?= date('d M Y H:i', strtotime($w['created_at'])) ?></td>
                  <td style="color:#FF6B35;font-weight:700"><?= format_rp((float)$w['amount']) ?></td>
                  <td>
                    <span class="badge <?= match($w['status']){'approved'=>'bg-success','pending'=>'bg-warning text-dark','hold'=>'bg-warning text-dark','rejected'=>'bg-danger','refunded'=>'bg-info text-dark',default=>'bg-secondary'} ?>" style="font-size:11px">
                      <?= ucfirst($w['status']) ?>
                    </span>
                  </td>
                  <td style="font-size:12px;color:#666"><?= htmlspecialchars($w['admin_note'] ?: '-') ?></td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <?php if($wdTotalPages > 1): ?>
          <div class="d-flex justify-content-center mt-3 pb-3">
            <nav><ul class="pagination pagination-sm mb-0">
              <?php for($i=1; $i<=$wdTotalPages; $i++): ?>
              <li class="page-item <?= $i===$wdPage ? 'active' : '' ?>">
                <a class="page-link" href="?id=<?= $uid ?>&wd_page=<?= $i ?>#pills-wd"><?= $i ?></a>
              </li>
              <?php endfor; ?>
            </ul></nav>
          </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>
