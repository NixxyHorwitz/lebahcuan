<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
staff_require('withdrawals');
csrf_enforce();

$flash = $flashType = '';
$filter = $_GET['status'] ?? 'pending';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $id     = (int)($_POST['id'] ?? 0);
    $note   = trim($_POST['note'] ?? '');

    if ($action === 'bulk_refund_pending') {
        $wds = $pdo->query("SELECT * FROM withdrawals WHERE status='pending'")->fetchAll();
        $count = 0;
        if ($wds) {
            $pdo->beginTransaction();
            try {
                foreach ($wds as $wd) {
                    $pdo->prepare("UPDATE users SET balance_wd=balance_wd+? WHERE id=?")->execute([$wd['amount'], $wd['user_id']]);
                    $pdo->prepare("DELETE FROM withdrawals WHERE id=?")->execute([$wd['id']]);
                    $count++;
                }
                $pdo->commit();
                $flash = "$count Withdraw pending berhasil dihapus massal dan saldo dikembalikan.";
            } catch (\Throwable $e) {
                $pdo->rollBack();
                $flash = 'Gagal memproses bulk refund: ' . $e->getMessage();
                $flashType = 'error';
            }
        } else {
            $flash = "Tidak ada withdraw pending.";
            $flashType = 'error';
        }
    }

    if ($action === 'approve' && $id) {
        $wd = $pdo->prepare("SELECT * FROM withdrawals WHERE id=? AND status='pending'");
        $wd->execute([$id]); $wd = $wd->fetch();
        if ($wd) {
            $pdo->prepare("UPDATE withdrawals SET status='approved',admin_note=?,processed_at=NOW() WHERE id=?")->execute([$note, $id]);
            
            // Kirim notifikasi sukses ke user
            $notifTitle = "Penarikan Berhasil Disetujui! 🎉";
            $notifMsg   = "Penarikan sebesar " . format_rp((float)$wd['amount']) . " ke " . $wd['bank_name'] . " (" . $wd['account_number'] . ") telah berhasil disetujui & ditransfer.";
            $pdo->prepare("INSERT INTO notifications (title, message, type, icon, target_type, target_user_ids, action_url, action_text, created_at) VALUES (?, ?, 'success', '💰', 'single', ?, '/withdraw', 'Lihat Status', NOW())")
                ->execute([$notifTitle, $notifMsg, (string)$wd['user_id']]);

            $flash = "Withdraw #{$id} disetujui.";
        }
    }
    if ($action === 'reject' && $id) {
        $wd = $pdo->prepare("SELECT * FROM withdrawals WHERE id=? AND status='pending'");
        $wd->execute([$id]); $wd = $wd->fetch();
        if ($wd) {
            $reasonNote = $note ?: 'Ditolak admin (Data rekening tidak sesuai)';
            // Refund balance
            $pdo->prepare("UPDATE users SET balance_wd=balance_wd+? WHERE id=?")->execute([$wd['amount'], $wd['user_id']]);
            $pdo->prepare("UPDATE withdrawals SET status='rejected',admin_note=?,processed_at=NOW() WHERE id=?")->execute([$reasonNote, $id]);
            
            // Kirim notifikasi penolakan ke user beserta alasannya
            $notifTitle = "Penarikan Dana Ditolak ⚠️";
            $notifMsg   = "Penarikan sebesar " . format_rp((float)$wd['amount']) . " ditolak dan saldo telah dikembalikan. Alasan: " . $reasonNote;
            $pdo->prepare("INSERT INTO notifications (title, message, type, icon, target_type, target_user_ids, action_url, action_text, created_at) VALUES (?, ?, 'warning', '⚠️', 'single', ?, '/withdraw', 'Cek Detail', NOW())")
                ->execute([$notifTitle, $notifMsg, (string)$wd['user_id']]);

            $flash = "Withdraw #{$id} ditolak dan saldo dikembalikan.";
        }
    }
    if ($action === 'hold' && $id) {
        $wd = $pdo->prepare("SELECT * FROM withdrawals WHERE id=? AND status='pending'");
        $wd->execute([$id]); $wd = $wd->fetch();
        if ($wd) {
            $holdNote = $note ?: 'Peninjauan antrean audit keuangan';
            $pdo->prepare("UPDATE withdrawals SET status='hold',admin_note=?,processed_at=NOW() WHERE id=?")->execute([$holdNote, $id]);
            
            $notifTitle = "Penarikan Sedang Ditahan (Hold) ⏸️";
            $notifMsg   = "Penarikan sebesar " . format_rp((float)$wd['amount']) . " sedang ditahan untuk peninjauan. Catatan: " . $holdNote;
            $pdo->prepare("INSERT INTO notifications (title, message, type, icon, target_type, target_user_ids, action_url, action_text, created_at) VALUES (?, ?, 'warning', '⏸️', 'single', ?, '/withdraw', 'Cek Status', NOW())")
                ->execute([$notifTitle, $notifMsg, (string)$wd['user_id']]);

            $flash = "Withdraw #{$id} ditahan (Hold), saldo TIDAK dikembalikan.";
        }
    }
    if ($action === 'refund' && $id) {
        $wd = $pdo->prepare("SELECT * FROM withdrawals WHERE id=? AND status='hold'");
        $wd->execute([$id]); $wd = $wd->fetch();
        if ($wd) {
            $refundNote = $note ?: 'Refund saldo dari status Hold';
            // Refund balance from hold state
            $pdo->prepare("UPDATE users SET balance_wd=balance_wd+? WHERE id=?")->execute([$wd['amount'], $wd['user_id']]);
            $pdo->prepare("UPDATE withdrawals SET status='refunded',admin_note=?,processed_at=NOW() WHERE id=?")->execute([$refundNote, $id]);
            
            $notifTitle = "Saldo Penarikan Dikembalikan ↩️";
            $notifMsg   = "Saldo penarikan sebesar " . format_rp((float)$wd['amount']) . " telah dikembalikan ke akunmu. Catatan: " . $refundNote;
            $pdo->prepare("INSERT INTO notifications (title, message, type, icon, target_type, target_user_ids, action_url, action_text, created_at) VALUES (?, ?, 'info', '↩️', 'single', ?, '/withdraw', 'Cek Saldo', NOW())")
                ->execute([$notifTitle, $notifMsg, (string)$wd['user_id']]);

            $flash = "Withdraw #{$id} di-refund. Status menjadi Rejected dan saldo dikembalikan.";
        }
    }
}

$where = $filter !== 'all' ? "WHERE status=?" : "";
$params = $filter !== 'all' ? [$filter] : [];
$rows = $pdo->prepare("SELECT w.*, u.username, u.email FROM withdrawals w JOIN users u ON u.id=w.user_id $where ORDER BY w.created_at DESC");
$rows->execute($params); $rows = $rows->fetchAll();

// Counts
$counts = $pdo->query("SELECT status, COUNT(*) as cnt FROM withdrawals GROUP BY status")->fetchAll();
$countMap = array_column($counts, 'cnt', 'status');

// Payment Channels Logos
$channels = $pdo->query("SELECT name, logo FROM payment_channels WHERE logo IS NOT NULL AND logo != ''")->fetchAll();
$channel_logos = [];
foreach ($channels as $c) {
    $channel_logos[strtolower($c['name'])] = $c['logo'];
}

$pageTitle  = 'Manajemen Withdraw';
$activePage = 'withdrawals';
require __DIR__ . '/partials/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-4">
  <div><h5 class="mb-0 fw-bold">⬇️ Manajemen Withdraw</h5></div>
  <?php if ($filter === 'pending' && !empty($countMap['pending'])): ?>
  <form method="POST" onsubmit="return confirm('Yakin ingin MENGHAPUS dan refund SEMUA withdraw yang pending (<?= $countMap['pending'] ?> data)? Aksi ini tidak dapat dibatalkan!');">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="bulk_refund_pending">
      <button type="submit" class="btn btn-sm btn-danger fw-bold" style="border-radius:10px;box-shadow:0 3px 0 #b91c1c;">🗑️ Hapus & Refund Semua Pending</button>
  </form>
  <?php endif; ?>
</div>

<?php if ($flash): ?>
<div class="alert alert-<?= $flashType==='error'?'danger':'success' ?> py-2 mb-3" style="border-radius:10px;font-size:13px"><?= htmlspecialchars($flash) ?></div>
<?php endif; ?>

<!-- Filter tabs -->
<div class="d-flex gap-2 mb-3 flex-wrap">
  <?php foreach (['all'=>'Semua','pending'=>'Pending','approved'=>'Approved','rejected'=>'Rejected','hold'=>'Hold','refunded'=>'Refunded'] as $s=>$lbl): ?>
  <a href="?status=<?= $s ?>" class="btn btn-sm <?= $filter===$s?'text-white':'btn-secondary' ?>" style="<?= $filter===$s?'background:var(--brand)':'' ?>">
    <?= $lbl ?> <?php $cnt=$s==='all'?array_sum($countMap):($countMap[$s]??0); if($cnt>0): ?><span class="badge bg-dark ms-1"><?= $cnt ?></span><?php endif; ?>
  </a>
  <?php endforeach; ?>
</div>

<div class="c-card">
  <div style="overflow-x:auto">
    <table class="c-table">
      <thead><tr><th>User</th><th>Jumlah</th><th>Bank/Akun</th><th>Tanggal</th><th>Status</th><th>Aksi</th></tr></thead>
      <tbody>
        <?php foreach ($rows as $w): ?>
        <tr>
          <td><strong style="font-size:13px"><?= htmlspecialchars($w['username']) ?></strong><div style="font-size:11px;color:#666"><?= htmlspecialchars($w['email']) ?></div></td>
          <td style="color:#FF6B35;font-weight:700;font-size:15px"><?= format_rp((float)$w['amount']) ?></td>
          <td>
            <?php $wl = $channel_logos[strtolower($w['bank_name'])] ?? null; ?>
            <div style="font-size:13px;font-weight:600">
              <?php if ($wl): ?>
              <img src="/assets/banks/<?= htmlspecialchars($wl) ?>" style="height:20px;vertical-align:middle;margin-right:6px;border-radius:4px">
              <?php endif; ?>
              <?= htmlspecialchars($w['bank_name']) ?>
            </div>
            <div style="font-size:12px;color:#888"><?= htmlspecialchars($w['account_number']) ?></div>
            <div style="font-size:11px;color:#666">a.n. <?= htmlspecialchars($w['account_name']) ?></div>
          </td>
          <td style="font-size:12px;color:#666"><?= date('d M Y H:i', strtotime($w['created_at'])) ?></td>
          <td>
            <span class="badge <?= match($w['status']){'approved'=>'b-success','pending'=>'b-warn','hold'=>'b-warn','rejected'=>'b-danger','refunded'=>'b-info'} ?>" style="border-radius:6px;padding:4px 8px">
              <?= ucfirst($w['status']) ?>
            </span>
            <?php if ($w['admin_note']): ?><div style="font-size:11px;color:#666;margin-top:3px"><?= htmlspecialchars($w['admin_note']) ?></div><?php endif; ?>
          </td>
          <td>
            <?php if ($w['status'] === 'pending'): ?>
            <div style="display:flex;gap:4px;flex-wrap:wrap">
              <button class="btn btn-sm b-success" style="border:none;border-radius:8px;font-size:11px" onclick="processWd(<?= $w['id'] ?>,'approve')">✓ Approve</button>
              <button class="btn btn-sm b-danger" style="border:none;border-radius:8px;font-size:11px" onclick="processWd(<?= $w['id'] ?>,'reject')">✗ Reject</button>
              <button class="btn btn-sm b-warn" style="border:none;border-radius:8px;font-size:11px;color:#fff" onclick="processWd(<?= $w['id'] ?>,'hold')">⏸ Hold</button>
            </div>
            <?php elseif ($w['status'] === 'hold'): ?>
            <button class="btn btn-sm b-danger" style="border:none;border-radius:8px;font-size:11px" onclick="processWd(<?= $w['id'] ?>,'refund')">↩️ Refund Saldo</button>
            <?php else: ?>
            <span style="font-size:11px;color:#555">—</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php if (empty($rows)): ?><div style="padding:40px;text-align:center;color:#555">Tidak ada data.</div><?php endif; ?>
  </div>
</div>

<!-- Process modal -->
<div class="modal fade" id="wdModal" tabindex="-1">
  <div class="modal-dialog modal-sm"><div class="modal-content" style="background:#1a1d27;border:1px solid #2d3149">
    <form method="POST">
    <?= csrf_field() ?>
    <input type="hidden" name="action" id="wd-action">
    <input type="hidden" name="id" id="wd-id">
    <div class="modal-header border-0"><h6 class="modal-title fw-bold" id="wd-title">Proses Withdraw</h6><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
      <div class="c-form-group"><label class="c-label">Catatan Admin (opsional)</label>
        <textarea name="note" class="c-form-control" rows="2" placeholder="Catatan untuk user..."></textarea></div>
    </div>
    <div class="modal-footer border-0">
      <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Batal</button>
      <button type="submit" id="wd-submit" class="btn btn-sm text-white" style="background:var(--brand)">Konfirmasi</button>
    </div>
    </form>
  </div></div>
</div>

<script>
function processWd(id, action) {
  document.getElementById('wd-id').value = id;
  document.getElementById('wd-action').value = action;
  
  let title = '', color = '';
  if (action==='approve') { title = '✅ Setujui Withdraw'; color = '#4CAF82'; }
  else if (action==='reject') { title = '❌ Tolak Withdraw & Refund'; color = '#F44E3B'; }
  else if (action==='hold') { title = '⏸ Hold Withdraw (No Refund)'; color = '#f59e0b'; }
  else if (action==='refund') { title = '↩️ Refund Saldo Hold'; color = '#F44E3B'; }
  
  document.getElementById('wd-title').textContent = title;
  document.getElementById('wd-submit').style.background = color;
  new bootstrap.Modal(document.getElementById('wdModal')).show();
}
</script>

<?php require __DIR__ . '/partials/footer.php'; ?>
