<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
staff_require('deposits');
csrf_enforce();

$flash = $flashType = '';
$filter = $_GET['status'] ?? 'pending';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $id     = (int)($_POST['id'] ?? 0);
    $note   = trim($_POST['note'] ?? '');

    if (($action === 'confirm' || $action === 'acc_expired') && $id) {
        $expectedStatus = $action === 'confirm' ? 'pending' : 'rejected';
        $dep = $pdo->prepare("SELECT * FROM deposits WHERE id=? AND status=?");
        $dep->execute([$id, $expectedStatus]); $dep = $dep->fetch();
        if ($dep) {
            $pdo->beginTransaction();
            try {
                // 1. Credit balance_dep to user
                $pdo->prepare("UPDATE users SET balance_dep=balance_dep+? WHERE id=?")
                    ->execute([$dep['amount'], $dep['user_id']]);
                // 2. Mark deposit as confirmed
                $pdo->prepare("UPDATE deposits SET status='confirmed',admin_note=?,confirmed_at=NOW() WHERE id=?")
                    ->execute([$note, $id]);
                // 3. Process referral commission
                $comm = credit_deposit_referral_commission($pdo, (int)$dep['user_id'], (float)$dep['amount']);
                $pdo->commit();
                $flash = "Deposit #{$id} dikonfirmasi. balance_dep user ditambahkan." . ($comm > 0 ? " Komisi referral dikirim ke upline (" . format_rp($comm) . ")." : "");
            } catch (\Throwable $e) {
                $pdo->rollBack();
                $flash = "Terjadi error: " . $e->getMessage(); $flashType = 'error';
            }
        }
    }
    if ($action === 'reject' && $id) {
        $pdo->prepare("UPDATE deposits SET status='rejected',admin_note=? WHERE id=?")->execute([$note ?: 'Ditolak admin', $id]);
        $flash = "Deposit #{$id} ditolak.";
    }
}

$where  = $filter !== 'all' ? "WHERE d.status=?" : "";
$params = $filter !== 'all' ? [$filter] : [];
$rows   = $pdo->prepare("SELECT d.*, u.username, u.email FROM deposits d JOIN users u ON u.id=d.user_id $where ORDER BY d.created_at DESC");
$rows->execute($params); $rows = $rows->fetchAll();

$counts = $pdo->query("SELECT status, COUNT(*) as cnt FROM deposits GROUP BY status")->fetchAll();
$countMap = array_column($counts, 'cnt', 'status');

$pageTitle  = 'Manajemen Deposit';
$activePage = 'deposits';
require __DIR__ . '/partials/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-4">
  <div><h5 class="mb-0 fw-bold">⬆️ Manajemen Deposit</h5></div>
</div>

<?php if ($flash): ?>
<div class="alert alert-<?= $flashType==='error'?'danger':'success' ?> py-2 mb-3" style="border-radius:10px;font-size:13px"><?= htmlspecialchars($flash) ?></div>
<?php endif; ?>

<div class="d-flex gap-2 mb-3 flex-wrap">
  <?php foreach (['all'=>'Semua','pending'=>'Pending','confirmed'=>'Confirmed','rejected'=>'Rejected'] as $s=>$lbl): ?>
  <a href="?status=<?= $s ?>" class="btn btn-sm <?= $filter===$s?'text-white':'btn-secondary' ?>" style="<?= $filter===$s?'background:var(--brand)':'' ?>">
    <?= $lbl ?> <?php $cnt=$s==='all'?array_sum($countMap):($countMap[$s]??0); if($cnt>0): ?><span class="badge bg-dark ms-1"><?= $cnt ?></span><?php endif; ?>
  </a>
  <?php endforeach; ?>
</div>

<div class="c-card">
  <div style="overflow-x:auto">
    <table class="c-table">
      <thead><tr><th>User</th><th>Jumlah</th><th>Metode</th><th>Bukti</th><th>Tanggal</th><th>Status</th><th>Aksi</th></tr></thead>
      <tbody>
        <?php foreach ($rows as $d): ?>
        <tr>
          <td><strong style="font-size:13px"><?= htmlspecialchars($d['username']) ?></strong><div style="font-size:11px;color:#666"><?= htmlspecialchars($d['email']) ?></div></td>
          <td style="color:#4CAF82;font-weight:700;font-size:15px"><?= format_rp((float)$d['amount']) ?></td>
          <td><span class="badge b-neutral" style="border-radius:6px"><?= strtoupper($d['method']) ?></span></td>
          <td>
            <?php if ($d['proof_image']): ?>
            <a href="/uploads/<?= htmlspecialchars($d['proof_image']) ?>" target="_blank">
              <img src="/uploads/<?= htmlspecialchars($d['proof_image']) ?>" style="width:50px;height:35px;object-fit:cover;border-radius:6px;border:1px solid #2d3149">
            </a>
            <?php else: ?><span style="color:#555;font-size:12px">—</span><?php endif; ?>
          </td>
          <td style="font-size:12px;color:#666"><?= date('d M Y H:i', strtotime($d['created_at'])) ?></td>
          <td>
            <span class="badge <?= match($d['status']){'confirmed'=>'b-success','pending'=>'b-warn','rejected'=>'b-danger'} ?>" style="border-radius:6px;padding:4px 8px">
              <?= ucfirst($d['status']) ?>
            </span>
          </td>
          <td>
            <?php if ($d['status'] === 'pending'): ?>
            <button class="btn btn-sm b-success" style="border:none;border-radius:8px;font-size:11px" onclick="processDep(<?= $d['id'] ?>,'confirm')">✓ Konfirmasi</button>
            <button class="btn btn-sm b-danger" style="border:none;border-radius:8px;font-size:11px" onclick="processDep(<?= $d['id'] ?>,'reject')">✗ Tolak</button>
            <?php elseif ($d['status'] === 'rejected'): ?>
            <button class="btn btn-sm b-success" style="border:none;border-radius:8px;font-size:11px" onclick="processDep(<?= $d['id'] ?>,'acc_expired')">✓ Acc Expired</button>
            <?php else: ?><span style="font-size:11px;color:#555">—</span><?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php if (empty($rows)): ?><div style="padding:40px;text-align:center;color:#555">Tidak ada data.</div><?php endif; ?>
  </div>
</div>

<div class="modal fade" id="depModal" tabindex="-1">
  <div class="modal-dialog modal-sm"><div class="modal-content" style="background:#1a1d27;border:1px solid #2d3149">
    <form method="POST">
    <?= csrf_field() ?><input type="hidden" name="action" id="dep-action"><input type="hidden" name="id" id="dep-id">
    <div class="modal-header border-0"><h6 class="modal-title fw-bold" id="dep-title">Proses Deposit</h6><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
      <div class="c-form-group"><label class="c-label">Catatan (opsional)</label>
        <textarea name="note" class="c-form-control" rows="2"></textarea></div>
    </div>
    <div class="modal-footer border-0">
      <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Batal</button>
      <button type="submit" id="dep-submit" class="btn btn-sm text-white" style="background:#4CAF82">Konfirmasi</button>
    </div>
    </form>
  </div></div>
</div>

<script>
function processDep(id, action) {
  document.getElementById('dep-id').value = id;
  document.getElementById('dep-action').value = action;
  if (action === 'confirm') {
    document.getElementById('dep-title').textContent = '✅ Konfirmasi Deposit';
    document.getElementById('dep-submit').style.background = '#4CAF82';
    document.getElementById('dep-submit').textContent = 'Konfirmasi';
  } else if (action === 'acc_expired') {
    document.getElementById('dep-title').textContent = '✅ Acc Deposit Expired';
    document.getElementById('dep-submit').style.background = '#4CAF82';
    document.getElementById('dep-submit').textContent = 'Acc Expired';
  } else {
    document.getElementById('dep-title').textContent = '❌ Tolak Deposit';
    document.getElementById('dep-submit').style.background = '#F44E3B';
    document.getElementById('dep-submit').textContent = 'Tolak';
  }
  new bootstrap.Modal(document.getElementById('depModal')).show();
}
</script>

<?php require __DIR__ . '/partials/footer.php'; ?>
