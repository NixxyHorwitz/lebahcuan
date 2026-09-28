<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
staff_require('user_txns');
csrf_enforce();

$flash = $flashType = '';
$selected_uid = (int)($_GET['uid'] ?? 0);
$selected_user = null;

// ── Handle POST Actions ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $id     = (int)($_POST['id'] ?? 0);
    $uid    = (int)($_POST['uid'] ?? 0);
    $note   = trim($_POST['note'] ?? '');

    // ── DEPOSIT actions ─────────────────────────────────────────────
    if ($action === 'dep_confirm' && $id) {
        $dep = $pdo->prepare("SELECT * FROM deposits WHERE id=? AND status='pending'");
        $dep->execute([$id]); $dep = $dep->fetch();
        if ($dep) {
            $pdo->beginTransaction();
            try {
                $pdo->prepare("UPDATE users SET balance_dep=balance_dep+? WHERE id=?")->execute([$dep['amount'], $dep['user_id']]);
                $pdo->prepare("UPDATE deposits SET status='confirmed',admin_note=?,confirmed_at=NOW() WHERE id=?")->execute([$note, $id]);
                // Referral commission
                credit_deposit_referral_commission($pdo, (int)$dep['user_id'], (float)$dep['amount']);
                $pdo->commit();
                $flash = "✅ Deposit #{$id} dikonfirmasi.";
            } catch (\Throwable $e) { $pdo->rollBack(); $flash = "Error: ".$e->getMessage(); $flashType='error'; }
        }
    }
    if ($action === 'dep_reject' && $id) {
        $pdo->prepare("UPDATE deposits SET status='rejected',admin_note=? WHERE id=? AND status='pending'")->execute([$note ?: 'Ditolak admin', $id]);
        $flash = "❌ Deposit #{$id} ditolak.";
    }

    // ── WITHDRAW actions ────────────────────────────────────────────
    if ($action === 'wd_approve' && $id) {
        $wd = $pdo->prepare("SELECT * FROM withdrawals WHERE id=? AND status='pending'");
        $wd->execute([$id]); $wd = $wd->fetch();
        if ($wd) {
            $pdo->prepare("UPDATE withdrawals SET status='approved',admin_note=?,processed_at=NOW() WHERE id=?")->execute([$note, $id]);
            $flash = "✅ Withdraw #{$id} disetujui.";
        }
    }
    if ($action === 'wd_reject' && $id) {
        $wd = $pdo->prepare("SELECT * FROM withdrawals WHERE id=? AND status IN ('pending','hold')");
        $wd->execute([$id]); $wd = $wd->fetch();
        if ($wd) {
            $pdo->prepare("UPDATE users SET balance_wd=balance_wd+? WHERE id=?")->execute([$wd['amount'], $wd['user_id']]);
            $pdo->prepare("UPDATE withdrawals SET status='rejected',admin_note=?,processed_at=NOW() WHERE id=?")->execute([$note ?: 'Ditolak admin', $id]);
            $flash = "💸 Withdraw #{$id} ditolak & saldo dikembalikan.";
        }
    }
    if ($action === 'wd_hold' && $id) {
        $wd = $pdo->prepare("SELECT * FROM withdrawals WHERE id=? AND status='pending'");
        $wd->execute([$id]); $wd = $wd->fetch();
        if ($wd) {
            $pdo->prepare("UPDATE withdrawals SET status='hold',admin_note=?,processed_at=NOW() WHERE id=?")->execute([$note ?: 'Hold oleh admin', $id]);
            $flash = "⏸ Withdraw #{$id} di-hold.";
        }
    }
    if ($action === 'wd_refund' && $id) {
        $wd = $pdo->prepare("SELECT * FROM withdrawals WHERE id=? AND status='hold'");
        $wd->execute([$id]); $wd = $wd->fetch();
        if ($wd) {
            $pdo->prepare("UPDATE users SET balance_wd=balance_wd+? WHERE id=?")->execute([$wd['amount'], $wd['user_id']]);
            $pdo->prepare("UPDATE withdrawals SET status='refunded',admin_note=?,processed_at=NOW() WHERE id=?")->execute([$note ?: 'Refund dari Hold', $id]);
            $flash = "💸 Withdraw #{$id} di-refund dari Hold.";
        }
    }
    if ($action === 'wd_refund_all_hold' && $uid) {
        $holds = $pdo->prepare("SELECT * FROM withdrawals WHERE user_id=? AND status='hold'");
        $holds->execute([$uid]); $holds = $holds->fetchAll();
        $total = 0;
        foreach ($holds as $h) {
            $pdo->prepare("UPDATE users SET balance_wd=balance_wd+? WHERE id=?")->execute([$h['amount'], $h['user_id']]);
            $pdo->prepare("UPDATE withdrawals SET status='refunded',admin_note=?,processed_at=NOW() WHERE id=?")->execute(['Bulk refund Hold oleh admin', $h['id']]);
            $total += $h['amount'];
        }
        $flash = count($holds) > 0
            ? "💸 " . count($holds) . " WD Hold di-refund. Total: " . format_rp($total)
            : "Tidak ada WD hold untuk user ini.";
    }

    $selected_uid = $uid ?: $selected_uid;
}

// ── Load selected user ──────────────────────────────────────────────────────
if ($selected_uid) {
    $su = $pdo->prepare("SELECT id,username,email,balance_dep,balance_wd FROM users WHERE id=?");
    $su->execute([$selected_uid]); $selected_user = $su->fetch();
}

// ── User search ─────────────────────────────────────────────────────────────
$search = trim($_GET['q'] ?? '');
$user_results = [];
if ($search !== '') {
    $sq = $pdo->prepare("SELECT id,username,email FROM users WHERE username LIKE ? OR email LIKE ? ORDER BY username LIMIT 20");
    $sq->execute(["%$search%", "%$search%"]);
    $user_results = $sq->fetchAll();
}

// ── Load transactions for selected user ────────────────────────────────────
$deposits = $withdrawals = [];
$hold_total = 0;
if ($selected_user) {
    $dq = $pdo->prepare("SELECT * FROM deposits WHERE user_id=? ORDER BY created_at DESC");
    $dq->execute([$selected_uid]); $deposits = $dq->fetchAll();

    $wq = $pdo->prepare("SELECT * FROM withdrawals WHERE user_id=? ORDER BY created_at DESC");
    $wq->execute([$selected_uid]); $withdrawals = $wq->fetchAll();

    foreach ($withdrawals as $w) {
        if ($w['status'] === 'hold') $hold_total += $w['amount'];
    }
}

$pageTitle  = 'Transaksi User';
$activePage = 'users';
require __DIR__ . '/partials/header.php';

function status_badge(string $s): string {
    $map = [
        'pending'   => ['b-warn',    '⏳ Pending'],
        'confirmed' => ['b-success', '✅ Confirmed'],
        'approved'  => ['b-success', '✅ Approved'],
        'rejected'  => ['b-danger',  '❌ Rejected'],
        'refunded'  => ['b-info',    '↩️ Refunded'],
        'hold'      => ['b-neutral', '⏸ Hold'],
    ];
    [$cls, $lbl] = $map[$s] ?? ['b-neutral', $s];
    return "<span class='badge {$cls}' style='padding:4px 8px;border-radius:6px;font-size:11px'>{$lbl}</span>";
}
?>

<div class="d-flex align-items-center justify-content-between mb-4">
  <div>
    <h5 class="mb-0 fw-bold">💼 Transaksi per User</h5>
    <div style="font-size:12px;color:#666;margin-top:2px">Cari user, lalu kelola deposit dan withdraw mereka</div>
  </div>
</div>

<?php if ($flash): ?>
<div class="alert alert-<?= $flashType==='error'?'danger':'success' ?> py-2 mb-3" style="border-radius:10px;font-size:13px"><?= htmlspecialchars($flash) ?></div>
<?php endif; ?>

<!-- ── User Picker ───────────────────────────────────────────────────── -->
<div class="c-card mb-4">
  <div class="c-card-header"><div class="c-card-title">🔍 Cari & Pilih User</div></div>
  <div class="c-card-body">
    <form method="GET" class="d-flex gap-2 align-items-center flex-wrap mb-3">
      <input type="text" name="q" class="c-form-control" style="max-width:320px"
             placeholder="Cari username atau email..." value="<?= htmlspecialchars($search) ?>" autofocus>
      <?php if ($selected_uid): ?><input type="hidden" name="uid" value="<?= $selected_uid ?>"> <?php endif; ?>
      <button type="submit" class="btn btn-sm text-white" style="background:var(--brand);white-space:nowrap">🔍 Cari</button>
      <?php if ($selected_uid): ?>
      <a href="/console/user_txns" class="btn btn-sm btn-secondary">✖ Reset</a>
      <?php endif; ?>
    </form>

    <?php if (!empty($user_results)): ?>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:8px">
      <?php foreach ($user_results as $ur): ?>
      <a href="?uid=<?= $ur['id'] ?>" class="d-flex align-items-center gap-3 p-2"
         style="border:1.5px solid <?= $selected_uid===$ur['id']?'var(--brand)':'#1f2235' ?>;border-radius:10px;text-decoration:none;background:<?= $selected_uid===$ur['id']?'rgba(255,107,53,.1)':'#0f1117' ?>;transition:border-color .15s">
        <div style="width:36px;height:36px;background:var(--brand);border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:900;color:#fff;flex-shrink:0">
          <?= strtoupper(substr($ur['username'],0,1)) ?>
        </div>
        <div>
          <div style="font-size:13px;font-weight:700;color:#e0e0f0"><?= htmlspecialchars($ur['username']) ?></div>
          <div style="font-size:11px;color:#555"><?= htmlspecialchars($ur['email']) ?></div>
        </div>
        <?php if ($selected_uid===$ur['id']): ?>
        <span style="margin-left:auto;font-size:10px;color:var(--brand)">✓ Aktif</span>
        <?php endif; ?>
      </a>
      <?php endforeach; ?>
    </div>
    <?php elseif ($search !== ''): ?>
    <div style="text-align:center;padding:20px;color:#555;font-size:13px">Tidak ada user yang cocok.</div>
    <?php else: ?>
    <div style="text-align:center;padding:10px;color:#555;font-size:12px">Ketik nama atau email untuk mencari user.</div>
    <?php endif; ?>
  </div>
</div>

<?php if ($selected_user): ?>
<!-- ── User Info Strip ─────────────────────────────────────────────── -->
<div class="c-card mb-4">
  <div class="c-card-body" style="padding:16px 20px">
    <div class="d-flex align-items-center gap-3 flex-wrap">
      <div style="width:48px;height:48px;background:var(--brand);border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:900;font-size:20px;color:#fff;flex-shrink:0">
        <?= strtoupper(substr($selected_user['username'],0,1)) ?>
      </div>
      <div style="flex:1">
        <div style="font-size:16px;font-weight:800"><?= htmlspecialchars($selected_user['username']) ?></div>
        <div style="font-size:12px;color:#666"><?= htmlspecialchars($selected_user['email']) ?></div>
      </div>
      <div class="d-flex gap-3">
        <div style="text-align:center">
          <div style="font-size:10px;color:#666;font-weight:700">SALDO DEP</div>
          <div style="font-size:15px;font-weight:900;color:#4CAF82"><?= format_rp((float)$selected_user['balance_dep']) ?></div>
        </div>
        <div style="text-align:center">
          <div style="font-size:10px;color:#666;font-weight:700">SALDO PENARIKAN</div>
          <div style="font-size:15px;font-weight:900;color:#FF6B35"><?= format_rp((float)$selected_user['balance_wd']) ?></div>
        </div>
        <?php if ($hold_total > 0): ?>
        <div style="text-align:center">
          <div style="font-size:10px;color:#666;font-weight:700">TOTAL HOLD</div>
          <div style="font-size:15px;font-weight:900;color:#F29900"><?= format_rp($hold_total) ?></div>
        </div>
        <?php endif; ?>
      </div>
      <a href="/console/user_detail.php?id=<?= $selected_uid ?>" class="btn btn-sm btn-secondary" style="font-size:12px">👤 Detail User</a>
    </div>
  </div>
</div>

<!-- ── Refund All Hold ─────────────────────────────────────────────── -->
<?php if ($hold_total > 0): ?>
<div class="c-card mb-4" style="border:1px solid rgba(242,153,0,.4);background:rgba(242,153,0,.05)">
  <div class="c-card-body d-flex align-items-center justify-content-between flex-wrap gap-2" style="padding:14px 20px">
    <div>
      <div style="font-weight:800;font-size:14px">⚠️ Ada <?= count(array_filter($withdrawals, fn($w)=>$w['status']==='hold')) ?> WD Hold</div>
      <div style="font-size:12px;color:#888">Total yang akan dikembalikan: <strong style="color:#F29900"><?= format_rp($hold_total) ?></strong></div>
    </div>
    <form method="POST" onsubmit="return confirm('Refund semua WD Hold milik <?= htmlspecialchars($selected_user['username']) ?>?')">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="wd_refund_all_hold">
      <input type="hidden" name="uid" value="<?= $selected_uid ?>">
      <button class="btn btn-sm" style="background:#F29900;color:#000;font-weight:700;border-radius:8px">💸 Refund Semua Hold</button>
    </form>
  </div>
</div>
<?php endif; ?>

<!-- ── Tabs ────────────────────────────────────────────────────────── -->
<ul class="nav nav-tabs mb-3" id="txnTabs" style="border-color:#1f2235">
  <li class="nav-item">
    <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-wd" style="font-size:13px;font-weight:700">
      💸 Withdraw <span class="badge bg-secondary ms-1"><?= count($withdrawals) ?></span>
    </button>
  </li>
  <li class="nav-item">
    <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-dep" style="font-size:13px;font-weight:700">
      ⬆️ Deposit <span class="badge bg-secondary ms-1"><?= count($deposits) ?></span>
    </button>
  </li>
</ul>

<div class="tab-content">

<!-- ── WITHDRAW Tab ───────────────────────────────────────────────── -->
<div class="tab-pane fade show active" id="tab-wd">
  <div class="c-card">
    <div class="c-card-body" style="padding:0">
      <?php if (empty($withdrawals)): ?>
      <div style="text-align:center;padding:40px;color:#555">Belum ada riwayat withdraw.</div>
      <?php else: ?>
      <table class="c-table">
        <thead>
          <tr>
            <th>#</th>
            <th>Tanggal</th>
            <th>Jumlah</th>
            <th>Rekening</th>
            <th>Status</th>
            <th>Note Admin</th>
            <th style="text-align:right">Aksi</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($withdrawals as $w): ?>
        <tr>
          <td style="color:#555;font-size:11px">#<?= $w['id'] ?></td>
          <td style="font-size:12px;color:#666"><?= date('d M Y H:i', strtotime($w['created_at'])) ?></td>
          <td style="font-weight:800;font-size:13px"><?= format_rp((float)$w['amount']) ?></td>
          <td style="font-size:12px">
            <div style="font-weight:700"><?= htmlspecialchars($w['bank_name']) ?></div>
            <div style="color:#666"><?= htmlspecialchars($w['account_number']) ?></div>
            <div style="color:#888;font-size:11px"><?= htmlspecialchars($w['account_name']) ?></div>
          </td>
          <td><?= status_badge($w['status']) ?></td>
          <td style="font-size:11px;color:#666;max-width:120px;word-break:break-word"><?= htmlspecialchars($w['admin_note'] ?? '') ?></td>
          <td>
            <div class="d-flex gap-1 justify-content-end flex-wrap">
            <?php if ($w['status'] === 'pending'): ?>
              <?php foreach (['wd_approve'=>['✅ Approve','b-success'],'wd_reject'=>['❌ Reject','b-danger'],'wd_hold'=>['⏸ Hold','b-neutral']] as $act=>[$lbl,$cls]): ?>
              <form method="POST" class="d-inline" onsubmit="return confirm('<?= $lbl ?> WD #<?= $w['id'] ?>?')">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="<?= $act ?>">
                <input type="hidden" name="id" value="<?= $w['id'] ?>">
                <input type="hidden" name="uid" value="<?= $selected_uid ?>">
                <button class="btn btn-sm <?= $cls ?>" style="border:none;border-radius:6px;font-size:11px;white-space:nowrap"><?= $lbl ?></button>
              </form>
              <?php endforeach; ?>
            <?php elseif ($w['status'] === 'hold'): ?>
              <form method="POST" class="d-inline" onsubmit="return confirm('Refund WD #<?= $w['id'] ?>?')">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="wd_refund">
                <input type="hidden" name="id" value="<?= $w['id'] ?>">
                <input type="hidden" name="uid" value="<?= $selected_uid ?>">
                <button class="btn btn-sm b-warn" style="border:none;border-radius:6px;font-size:11px">💸 Refund</button>
              </form>
              <form method="POST" class="d-inline" onsubmit="return confirm('Reject (dengan refund) WD #<?= $w['id'] ?>?')">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="wd_reject">
                <input type="hidden" name="id" value="<?= $w['id'] ?>">
                <input type="hidden" name="uid" value="<?= $selected_uid ?>">
                <button class="btn btn-sm b-danger" style="border:none;border-radius:6px;font-size:11px">❌ Reject</button>
              </form>
            <?php elseif ($w['status'] === 'rejected' || $w['status'] === 'refunded'): ?>
              <span style="font-size:11px;color:#555;font-style:italic">—</span>
            <?php else: ?>
              <span style="font-size:11px;color:#555;font-style:italic">Selesai</span>
            <?php endif; ?>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- ── DEPOSIT Tab ────────────────────────────────────────────────── -->
<div class="tab-pane fade" id="tab-dep">
  <div class="c-card">
    <div class="c-card-body" style="padding:0">
      <?php if (empty($deposits)): ?>
      <div style="text-align:center;padding:40px;color:#555">Belum ada riwayat deposit.</div>
      <?php else: ?>
      <table class="c-table">
        <thead>
          <tr>
            <th>#</th>
            <th>Tanggal</th>
            <th>Jumlah</th>
            <th>Metode</th>
            <th>Bukti</th>
            <th>Status</th>
            <th style="text-align:right">Aksi</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($deposits as $d): ?>
        <tr>
          <td style="color:#555;font-size:11px">#<?= $d['id'] ?></td>
          <td style="font-size:12px;color:#666"><?= date('d M Y H:i', strtotime($d['created_at'])) ?></td>
          <td style="font-weight:800;font-size:13px"><?= format_rp((float)$d['amount']) ?></td>
          <td><span class="badge b-neutral" style="padding:3px 8px;border-radius:6px;font-size:11px"><?= strtoupper($d['method'] ?? 'transfer') ?></span></td>
          <td>
            <?php if (!empty($d['proof_image'])): ?>
            <a href="/uploads/<?= htmlspecialchars($d['proof_image']) ?>" target="_blank"
               style="font-size:11px;color:var(--brand);font-weight:700">🖼 Lihat</a>
            <?php else: ?>
            <span style="font-size:11px;color:#555">—</span>
            <?php endif; ?>
          </td>
          <td><?= status_badge($d['status']) ?></td>
          <td>
            <div class="d-flex gap-1 justify-content-end">
            <?php if ($d['status'] === 'pending'): ?>
              <form method="POST" class="d-inline" onsubmit="return confirm('Konfirmasi deposit #<?= $d['id'] ?>?')">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="dep_confirm">
                <input type="hidden" name="id" value="<?= $d['id'] ?>">
                <input type="hidden" name="uid" value="<?= $selected_uid ?>">
                <button class="btn btn-sm b-success" style="border:none;border-radius:6px;font-size:11px">✅ Confirm</button>
              </form>
              <form method="POST" class="d-inline" onsubmit="return confirm('Tolak deposit #<?= $d['id'] ?>?')">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="dep_reject">
                <input type="hidden" name="id" value="<?= $d['id'] ?>">
                <input type="hidden" name="uid" value="<?= $selected_uid ?>">
                <button class="btn btn-sm b-danger" style="border:none;border-radius:6px;font-size:11px">❌ Reject</button>
              </form>
            <?php else: ?>
              <span style="font-size:11px;color:#555;font-style:italic">—</span>
            <?php endif; ?>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>
  </div>
</div>

</div><!-- tab-content -->

<?php else: ?>
<div class="c-card">
  <div class="c-card-body" style="text-align:center;padding:60px 20px;color:#555">
    <div style="font-size:48px;margin-bottom:16px">🔍</div>
    <div style="font-weight:700;font-size:14px;margin-bottom:6px">Belum ada user dipilih</div>
    <div style="font-size:12px">Cari dan pilih user di atas untuk melihat transaksi mereka.</div>
  </div>
</div>
<?php endif; ?>

<?php require __DIR__ . '/partials/footer.php'; ?>
