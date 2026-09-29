<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
staff_require('users');
csrf_enforce();

$flash = $flashType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $uid    = (int)($_POST['user_id'] ?? 0);

    if ($action === 'toggle_active' && $uid) {
        $s = $pdo->prepare("SELECT is_active FROM users WHERE id=?"); $s->execute([$uid]);
        $cur = (int)$s->fetchColumn();
        $pdo->prepare("UPDATE users SET is_active=? WHERE id=?")->execute([$cur?0:1, $uid]);
        $flash = 'Status pengguna diperbarui.';
    }

    if ($action === 'toggle_debug' && $uid) {
        $s = $pdo->prepare("SELECT is_debug FROM users WHERE id=?"); $s->execute([$uid]);
        $cur = (int)$s->fetchColumn();
        $new = $cur ? 0 : 1;
        $pdo->prepare("UPDATE users SET is_debug=? WHERE id=?")->execute([$new, $uid]);
        $flash = 'Mode Debug user ' . ($new ? 'DIAKTIFKAN (Toolbar durasi pendek & custom reward aktif di watch.php)' : 'DINONAKTIFKAN') . '.';
    }

    if ($action === 'adjust_balance' && $uid) {
        $amount = (float)$_POST['amount'];
        $type   = $_POST['type'] === 'add' ? 1 : -1;
        $field  = $_POST['bal_field'] === 'dep' ? 'balance_dep' : 'balance_wd';
        $pdo->prepare("UPDATE users SET {$field}=GREATEST(0,{$field}+?) WHERE id=?")->execute([$type*abs($amount), $uid]);
        $flash = 'Saldo pengguna diperbarui.';
    }

    if ($action === 'edit_user' && $uid) {
        $username      = trim($_POST['username'] ?? '');
        $email         = trim($_POST['email'] ?? '');
        $whatsapp      = trim($_POST['whatsapp'] ?? '');
        $new_pass      = trim($_POST['new_password'] ?? '');
        $is_active     = (int)($_POST['is_active'] ?? 1);
        $created_at    = trim($_POST['created_at'] ?? '');

        // Rekening
        $bank_name     = trim($_POST['bank_name'] ?? '');
        $account_number = trim($_POST['account_number'] ?? '');
        $acc_num_type  = in_array($_POST['acc_num_input_type'] ?? '', ['typed', 'pasted']) ? $_POST['acc_num_input_type'] : 'typed';
        $account_name  = trim($_POST['account_name'] ?? '');
        $acc_name_type = in_array($_POST['acc_name_input_type'] ?? '', ['typed', 'pasted']) ? $_POST['acc_name_input_type'] : 'typed';
        $edit_bank_min = (int)($_POST['edit_bank_deposit_min'] ?? 50000);
        $acc_num_rec   = trim($_POST['acc_num_record'] ?? '');
        $acc_name_rec  = trim($_POST['acc_name_record'] ?? '');

        // Saldo & Game
        $bal_wd        = (float)($_POST['balance_wd'] ?? 0);
        $bal_dep       = (float)($_POST['balance_dep'] ?? 0);
        $total_e       = (float)($_POST['total_earned'] ?? 0);
        $spin_tickets  = (int)($_POST['spin_tickets'] ?? 0);

        // Membership & Izin
        $mem_id        = $_POST['membership_id'] === '' ? null : (int)$_POST['membership_id'];
        $mem_exp       = trim($_POST['membership_expires_at'] ?? '');
        $mem_exp_val   = ($mem_exp && $mem_id) ? $mem_exp : null;
        $can_wd        = (int)($_POST['can_withdraw'] ?? 1);
        $can_chat      = (int)($_POST['can_chat'] ?? 1);
        $ref_en        = (int)($_POST['is_refund_enabled'] ?? 1);
        $ref_cut       = (float)($_POST['refund_cut_percent'] ?? 20.0);

        // Referral
        $ref_code      = trim($_POST['referral_code'] ?? '');
        $ref_by        = trim($_POST['referred_by'] ?? '') ?: null;
        $is_ref_active = (int)($_POST['is_referral_active'] ?? 1);

        // Promotor
        $is_promo      = (int)($_POST['is_promotor'] ?? 0);
        $promo_salary  = (float)($_POST['promotor_salary_rate'] ?? 0);
        $promo_target_dep = (float)($_POST['promotor_target_deposits'] ?? 0);
        $promo_target_reg = (int)($_POST['promotor_target_regs'] ?? 0);

        // Misi
        $watch_today   = (int)($_POST['watch_count_today'] ?? 0);
        $watch_reset   = trim($_POST['watch_reset_date'] ?? '') ?: null;
        $last_checkin  = trim($_POST['last_checkin'] ?? '') ?: null;

        // Mode Debug Tester
        $is_debug      = (int)($_POST['is_debug'] ?? 0);
        $dbg_dur       = max(1, (int)($_POST['debug_watch_duration'] ?? 3));
        $dbg_rwd       = max(0.0, (float)($_POST['debug_watch_reward'] ?? 50000.00));

        $errors = [];
        if (strlen($username) < 3) $errors[] = 'Username minimal 3 karakter.';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Email tidak valid.';
        if ($ref_code === '') $errors[] = 'Referral code tidak boleh kosong.';

        // Check username/email/referral uniqueness
        $chk = $pdo->prepare("SELECT id FROM users WHERE username=? AND id!=?");
        $chk->execute([$username, $uid]);
        if ($chk->fetch()) $errors[] = 'Username sudah digunakan.';

        $chk2 = $pdo->prepare("SELECT id FROM users WHERE email=? AND id!=?");
        $chk2->execute([$email, $uid]);
        if ($chk2->fetch()) $errors[] = 'Email sudah digunakan.';

        $chk3 = $pdo->prepare("SELECT id FROM users WHERE referral_code=? AND id!=?");
        $chk3->execute([$ref_code, $uid]);
        if ($chk3->fetch()) $errors[] = 'Referral code sudah digunakan oleh user lain.';

        if ($errors) {
            $flash = implode(' ', $errors); $flashType = 'error';
        } else {
            $created_val = $created_at ? $created_at : date('Y-m-d H:i:s');

            $sql = "UPDATE users SET 
                    username=?, email=?, whatsapp=?, is_active=?, created_at=?,
                    bank_name=?, account_number=?, acc_num_input_type=?, account_name=?, acc_name_input_type=?, edit_bank_deposit_min=?, acc_num_record=?, acc_name_record=?,
                    balance_wd=?, balance_dep=?, total_earned=?, spin_tickets=?,
                    membership_id=?, membership_expires_at=?, can_withdraw=?, can_chat=?, is_refund_enabled=?, refund_cut_percent=?,
                    referral_code=?, referred_by=?, is_referral_active=?,
                    is_promotor=?, promotor_salary_rate=?, promotor_target_deposits=?, promotor_target_regs=?,
                    watch_count_today=?, watch_reset_date=?, last_checkin=?,
                    is_debug=?, debug_watch_duration=?, debug_watch_reward=?
                    WHERE id=?";

            $pdo->prepare($sql)->execute([
                $username, $email, $whatsapp, $is_active, $created_val,
                $bank_name, $account_number, $acc_num_type, $account_name, $acc_name_type, $edit_bank_min, $acc_num_rec ?: null, $acc_name_rec ?: null,
                $bal_wd, $bal_dep, $total_e, $spin_tickets,
                $mem_id, $mem_exp_val, $can_wd, $can_chat, $ref_en, $ref_cut,
                $ref_code, $ref_by, $is_ref_active,
                $is_promo, $promo_salary, $promo_target_dep, $promo_target_reg,
                $watch_today, $watch_reset, $last_checkin,
                $is_debug, $dbg_dur, $dbg_rwd,
                $uid
            ]);

            if ($new_pass !== '') {
                $pdo->prepare("UPDATE users SET password_hash=? WHERE id=?")
                    ->execute([password_hash($new_pass, PASSWORD_BCRYPT), $uid]);
            }
            $flash = "Seluruh data user '{$username}' (ID: #{$uid}) berhasil diperbarui.";
        }
    }
    if ($action === 'refund_level' && $uid) {
        $cut = isset($_POST['cut']) ? (int)$_POST['cut'] : 0;
        
        $s = $pdo->prepare("SELECT u.membership_id, m.price, m.name FROM users u LEFT JOIN memberships m ON u.membership_id = m.id WHERE u.id=?");
        $s->execute([$uid]);
        $uInfo = $s->fetch();
        
        if (!$uInfo || !$uInfo['membership_id']) {
            $flash = 'User tidak memiliki paket aktif.'; $flashType = 'error';
        } else {
            try {
                $pdo->beginTransaction();
                $oStmt = $pdo->prepare("SELECT amount FROM upgrade_orders WHERE user_id=? AND membership_id=? AND status='confirmed' ORDER BY id DESC LIMIT 1");
                $oStmt->execute([$uid, $uInfo['membership_id']]);
                $basePrice = (float)$oStmt->fetchColumn();
                
                if (!$basePrice) $basePrice = (float)$uInfo['price'];
                
                $refundAmt = $cut === 15 ? ($basePrice * 0.85) : $basePrice;
                
                // Cancel pending & hold WDs
                $wds = $pdo->prepare("SELECT id, amount FROM withdrawals WHERE user_id = ? AND status IN ('pending', 'hold') FOR UPDATE");
                $wds->execute([$uid]);
                $wd_refund_total = 0;
                foreach ($wds->fetchAll() as $w) {
                    $wd_refund_total += (float)$w['amount'];
                    $pdo->prepare("UPDATE withdrawals SET status = 'rejected', admin_note = 'Dibatalkan (Refund Level)', processed_at = NOW() WHERE id = ?")->execute([$w['id']]);
                }
                
                $pdo->prepare("UPDATE users SET balance_dep = balance_dep + ?, balance_wd = balance_wd + ?, membership_id = NULL, membership_expires_at = NULL WHERE id = ?")
                    ->execute([$refundAmt, $wd_refund_total, $uid]);
                    
                $notifTitle = "Refund Level Disetujui ✅";
                $pct = $cut === 15 ? 15 : 0;
                $notifMsg = "Refund untuk level {$uInfo['name']} telah disetujui. Saldo " . format_rp($refundAmt) . " (setelah potongan {$pct}%) telah dikembalikan ke Saldo Beli kamu.";
                if ($wd_refund_total > 0) {
                    $notifMsg .= " Semua penarikan yang tertunda juga dibatalkan dan saldo " . format_rp($wd_refund_total) . " dikembalikan ke Saldo WD kamu.";
                }
                $pdo->prepare("INSERT INTO notifications (title, message, type, icon, target_type, target_user_ids, action_url, action_text) VALUES (?, ?, 'success', '💰', 'single', ?, '/user/upgrade.php', 'Cek Saldo')")
                    ->execute([$notifTitle, $notifMsg, json_encode([$uid])]);
                    
                $pdo->commit();
                $flash = "Refund sukses untuk paket {$uInfo['name']}. Saldo dikembalikan: " . format_rp($refundAmt) . ($wd_refund_total > 0 ? " (+ WD dikembalikan: " . format_rp($wd_refund_total) . ")" : "");
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $flash = 'Error: ' . $e->getMessage(); $flashType = 'error';
            }
        }
    }
    if ($action === 'delete_user' && $uid) {
        $pdo->prepare("DELETE FROM users WHERE id=?")->execute([$uid]);
        $flash = 'Akun pengguna berhasil dihapus permanen.';
    }
    if ($action === 'login_as' && $uid) {
        session_regenerate_id(true);
        set_auth_cookie((int)$uid);
        redirect('/home');
        exit;
    }
}

$memberships = $pdo->query("SELECT id, name FROM memberships WHERE is_active=1 ORDER BY sort_order ASC")->fetchAll();

$limit = 50;
$page  = max(1, (int)($_GET['p'] ?? 1));
$q     = trim($_GET['q'] ?? '');

$where = "";
$params = [];
if ($q !== '') {
    $where = "WHERE u.username LIKE ? OR u.email LIKE ?";
    $params = ["%{$q}%", "%{$q}%"];
}

$stmtTotal = $pdo->prepare("SELECT COUNT(*) FROM users u $where");
$stmtTotal->execute($params);
$total = (int)$stmtTotal->fetchColumn();
$totalPages = ceil($total / $limit) ?: 1;
if ($page > $totalPages) $page = $totalPages;

$offset = ($page - 1) * $limit;
$stmt = $pdo->prepare("SELECT u.*, m.name as membership_name FROM users u LEFT JOIN memberships m ON m.id=u.membership_id $where ORDER BY u.created_at DESC LIMIT $limit OFFSET $offset");
$stmt->execute($params);
$users = $stmt->fetchAll();

$pageTitle  = 'Pengguna';
$activePage = 'users';
require __DIR__ . '/partials/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-3">
  <div>
    <h5 class="mb-0 fw-bold">👥 Pengguna</h5>
    <small class="text-secondary"><?= number_format($total) ?> pengguna <?= $q ? 'ditemukan' : 'terdaftar' ?></small>
  </div>
  <form method="GET" class="d-flex gap-2">
    <input type="text" name="q" class="form-control form-control-sm bg-dark text-white border-secondary" placeholder="Cari username / email..." value="<?= htmlspecialchars($q) ?>">
    <button type="submit" class="btn btn-sm btn-primary">Cari</button>
    <?php if ($q): ?>
    <a href="users.php" class="btn btn-sm btn-secondary">Reset</a>
    <?php endif; ?>
  </form>
</div>

<?php if ($flash): ?>
<div class="alert alert-<?= $flashType==='error'?'danger':'success' ?> py-2 mb-3" style="border-radius:10px;font-size:13px"><?= htmlspecialchars($flash) ?></div>
<?php endif; ?>

<div class="c-card">
  <div style="overflow-x:auto">
    <table class="c-table" style="white-space: nowrap;">
      <thead><tr><th>Username</th><th>Email / WA</th><th>Saldo (WD/Dep)</th><th>Total Earned</th><th>Paket</th><th>Referral</th><th>Status</th><th>Aksi</th></tr></thead>
      <tbody>
        <?php foreach ($users as $u): ?>
        <tr>
          <td data-label="Username">
            <strong style="font-size:13px"><?= htmlspecialchars($u['username']) ?></strong>
            <?php if (!empty($u['is_debug'])): ?>
              <span class="badge" style="background:#6366f1;color:#fff;font-size:9.5px;padding:2px 6px;border-radius:6px;margin-left:4px" title="Mode Debug Tester Aktif">🛠 DEBUG</span>
            <?php endif; ?>
            <div style="font-size:11px;color:#555"><?= date('d M Y', strtotime($u['created_at'])) ?></div>
          </td>
          <td data-label="Kontak"><div style="font-size:12px"><?= htmlspecialchars($u['email']) ?></div><div style="font-size:11px;color:#666"><?= htmlspecialchars($u['whatsapp']) ?></div></td>
          <td data-label="Saldo"><div style="color:#4CAF82;font-weight:700;font-size:12px">WD: <?= format_rp((float)$u['balance_wd']) ?></div><div style="color:#4E9BFF;font-size:11px">Dep: <?= format_rp((float)$u['balance_dep']) ?></div></td>
          <td data-label="Total Earned" style="color:#888;font-size:12px"><?= format_rp((float)$u['total_earned']) ?></td>
          <td data-label="Paket">
            <?php if ($u['membership_name'] && $u['membership_expires_at'] && strtotime($u['membership_expires_at'])>time()): ?>
            <span class="badge b-success" style="border-radius:6px;font-size:11px"><?= htmlspecialchars($u['membership_name']) ?></span>
            <?php else: ?><span class="badge b-neutral" style="border-radius:6px;font-size:11px"><?= htmlspecialchars(get_free_tier_name($pdo)) ?></span><?php endif; ?>
          </td>
          <td data-label="Referral" style="font-size:12px;letter-spacing:1px;color:#888"><?= $u['referral_code'] ?></td>
          <td data-label="Status">
            <form method="POST" class="d-inline">
              <?= csrf_field() ?><input type="hidden" name="action" value="toggle_active"><input type="hidden" name="user_id" value="<?= $u['id'] ?>">
              <button type="submit" class="badge border-0 <?= $u['is_active']?'b-success':'b-danger' ?>" style="cursor:pointer;border-radius:6px;padding:4px 8px">
                <?= $u['is_active']?'Aktif':'Nonaktif' ?>
              </button>
            </form>
          </td>
          <td data-label="Aksi" style="white-space:nowrap">
            <button class="btn btn-sm" style="border-radius:6px;font-size:11px;margin-right:4px;background:#2d3149;color:#fff;border:1px solid #3e445b;padding:4px 8px;font-weight:600;"
              onclick='editUser(<?= htmlspecialchars(json_encode($u), ENT_QUOTES) ?>)'>✏️ Edit</button>
            <a href="/console/user_detail.php?id=<?= $u['id'] ?>" class="btn btn-sm" style="border-radius:6px;font-size:11px;margin-right:4px;background:#32433e;color:#b2dfdb;border:1px solid #4a665e;padding:4px 8px;font-weight:600;text-decoration:none;">👁️ Detail</a>
            <form method="POST" class="d-inline">
              <?= csrf_field() ?><input type="hidden" name="action" value="toggle_debug"><input type="hidden" name="user_id" value="<?= $u['id'] ?>">
              <button type="submit" class="btn btn-sm" style="border-radius:6px;font-size:11px;margin-right:4px;background:<?= !empty($u['is_debug']) ? 'rgba(99,102,241,0.25)' : 'rgba(255,255,255,0.05)' ?>;color:<?= !empty($u['is_debug']) ? '#818cf8' : '#888' ?>;border:1px solid <?= !empty($u['is_debug']) ? '#6366f1' : '#3e445b' ?>;padding:4px 8px;font-weight:600;" title="Klik untuk mengaktifkan/menonaktifkan Mode Debug tester">
                🛠 <?= !empty($u['is_debug']) ? 'Debug ON' : 'Debug OFF' ?>
              </button>
            </form>
            <button type="button" class="btn btn-sm" style="border-radius:6px;font-size:11px;margin-right:4px;background:#4b3f72;color:#d1c4e9;border:1px solid #6b5a9e;padding:4px 8px;font-weight:600;"
              onclick="if(confirm('Yakin ingin login sebagai user ini?')) document.getElementById('loginas-form-<?= $u['id'] ?>').submit()">🔑 Login As</button>
            <form id="loginas-form-<?= $u['id'] ?>" method="POST" style="display:none;"><?= csrf_field() ?><input type="hidden" name="action" value="login_as"><input type="hidden" name="user_id" value="<?= $u['id'] ?>"></form>
            <button class="btn btn-sm" style="border-radius:6px;font-size:11px;margin-right:4px;background:#1e3a5f;color:#90caf9;border:1px solid #2b4f7e;padding:4px 8px;font-weight:600;"
              onclick="adjustBalance(<?= $u['id'] ?>, '<?= htmlspecialchars($u['username']) ?>')">💰 Saldo</button>
            <?php if ($u['membership_id'] && $u['membership_name']): ?>
            <button class="btn btn-sm" style="border-radius:6px;font-size:11px;background:#4a1923;color:#ef9a9a;border:1px solid #6b2533;padding:4px 8px;font-weight:600;"
              onclick="refundLevel(<?= $u['id'] ?>, '<?= htmlspecialchars($u['username']) ?>', '<?= htmlspecialchars($u['membership_name']) ?>')">⏪ Refund</button>
            <?php endif; ?>
            <button class="btn btn-sm" style="border-radius:6px;font-size:11px;margin-left:2px;background:#4a1923;color:#ef9a9a;border:1px solid #6b2533;padding:4px 8px;font-weight:600;"
              onclick="if(confirm('Yakin ingin menghapus akun ini permanen?')) document.getElementById('del-form-<?= $u['id'] ?>').submit()">🗑️ Hapus</button>
            <form id="del-form-<?= $u['id'] ?>" method="POST" style="display:none;"><?= csrf_field() ?><input type="hidden" name="action" value="delete_user"><input type="hidden" name="user_id" value="<?= $u['id'] ?>"></form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php if (empty($users)): ?><div style="padding:40px;text-align:center;color:#555">Tidak ada user ditemukan.</div><?php endif; ?>
  </div>
</div>

<!-- Pagination Controls -->
<?php if ($totalPages > 1): ?>
<div class="d-flex justify-content-center mt-4">
  <nav>
    <ul class="pagination pagination-sm" style="--bs-pagination-bg:#1a1d27;--bs-pagination-border-color:#2d3149;--bs-pagination-color:#e0e0f0;--bs-pagination-hover-bg:#2d3149;--bs-pagination-hover-color:#fff">
      <?php if ($page > 1): ?>
      <li class="page-item"><a class="page-link" href="?p=1&q=<?= urlencode($q) ?>">First</a></li>
      <li class="page-item"><a class="page-link" href="?p=<?= $page-1 ?>&q=<?= urlencode($q) ?>">Prev</a></li>
      <?php endif; ?>
      
      <li class="page-item active"><span class="page-link" style="background:var(--brand);border-color:var(--brand);"><?= $page ?> / <?= $totalPages ?></span></li>
      
      <?php if ($page < $totalPages): ?>
      <li class="page-item"><a class="page-link" href="?p=<?= $page+1 ?>&q=<?= urlencode($q) ?>">Next</a></li>
      <li class="page-item"><a class="page-link" href="?p=<?= $totalPages ?>&q=<?= urlencode($q) ?>">Last</a></li>
      <?php endif; ?>
    </ul>
  </nav>
</div>
<?php endif; ?>

<!-- ── Edit User Modal ───────────────────────────────── -->
<div class="modal fade" id="editUserModal" tabindex="-1">
  <div class="modal-dialog modal-xl"><div class="modal-content" style="background:#1a1d27;border:1px solid #2d3149">
    <form method="POST" id="edit-user-form">
    <?= csrf_field() ?><input type="hidden" name="action" value="edit_user"><input type="hidden" name="user_id" id="eu-uid">
    <div class="modal-header border-0 pb-1">
      <div>
        <h6 class="modal-title fw-bold" id="eu-title">✏️ Edit Pengguna</h6>
        <div style="font-size:12px;color:#888;">Kelola dan ubah seluruh atribut data pengguna tanpa terkecuali.</div>
      </div>
      <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
    </div>
    
    <div class="modal-body pt-2">
      <!-- Nav Tabs -->
      <ul class="nav nav-pills mb-3 gap-1" id="editUserTabs" role="tablist" style="background:#12141c;padding:6px;border-radius:8px;border:1px solid #24283b">
        <li class="nav-item" role="presentation">
          <button class="nav-link active py-1 px-3" id="tab-account-btn" data-bs-toggle="pill" data-bs-target="#tab-account" type="button" role="tab" style="font-size:12px;font-weight:600">👤 Akun & Login</button>
        </li>
        <li class="nav-item" role="presentation">
          <button class="nav-link py-1 px-3" id="tab-bank-btn" data-bs-toggle="pill" data-bs-target="#tab-bank" type="button" role="tab" style="font-size:12px;font-weight:600">💳 Rekening & Keamanan</button>
        </li>
        <li class="nav-item" role="presentation">
          <button class="nav-link py-1 px-3" id="tab-balance-btn" data-bs-toggle="pill" data-bs-target="#tab-balance" type="button" role="tab" style="font-size:12px;font-weight:600">💰 Saldo & Games</button>
        </li>
        <li class="nav-item" role="presentation">
          <button class="nav-link py-1 px-3" id="tab-access-btn" data-bs-toggle="pill" data-bs-target="#tab-access" type="button" role="tab" style="font-size:12px;font-weight:600">🏆 Paket & Izin</button>
        </li>
        <li class="nav-item" role="presentation">
          <button class="nav-link py-1 px-3" id="tab-ref-btn" data-bs-toggle="pill" data-bs-target="#tab-ref" type="button" role="tab" style="font-size:12px;font-weight:600">🔗 Referral & Promotor</button>
        </li>
        <li class="nav-item" role="presentation">
          <button class="nav-link py-1 px-3" id="tab-activity-btn" data-bs-toggle="pill" data-bs-target="#tab-activity" type="button" role="tab" style="font-size:12px;font-weight:600">📊 Misi & Aktivitas</button>
        </li>
      </ul>

      <div class="tab-content" id="editUserTabsContent">
        <!-- Tab 1: Akun & Login -->
        <div class="tab-pane fade show active" id="tab-account" role="tabpanel">
          <div class="row g-3">
            <div class="col-md-6">
              <div class="c-form-group mb-3">
                <label class="c-label">Username <span class="text-danger">*</span></label>
                <input type="text" name="username" id="eu-username" class="c-form-control" required minlength="3">
              </div>
            </div>
            <div class="col-md-6">
              <div class="c-form-group mb-3">
                <label class="c-label">Email <span class="text-danger">*</span></label>
                <input type="email" name="email" id="eu-email" class="c-form-control" required>
              </div>
            </div>
            <div class="col-md-6">
              <div class="c-form-group mb-3">
                <label class="c-label">WhatsApp</label>
                <input type="text" name="whatsapp" id="eu-whatsapp" class="c-form-control" placeholder="08xxxxxxxxxx">
              </div>
            </div>
            <div class="col-md-6">
              <div class="c-form-group mb-3">
                <label class="c-label">Password Baru <small style="color:#888">(kosongkan jika tak diubah)</small></label>
                <input type="text" name="new_password" class="c-form-control" placeholder="Biarkan kosong jika tidak diubah">
              </div>
            </div>
            <div class="col-md-6">
              <div class="c-form-group mb-3">
                <label class="c-label">Status Akun</label>
                <select name="is_active" id="eu-is-active" class="c-form-control">
                  <option value="1">Aktif (Normal)</option>
                  <option value="0">Banned / Dinonaktifkan</option>
                </select>
              </div>
            </div>
            <div class="col-md-6">
              <div class="c-form-group mb-3">
                <label class="c-label">Tanggal Registrasi (created_at)</label>
                <input type="datetime-local" name="created_at" id="eu-created-at" class="c-form-control">
              </div>
            </div>
          </div>
        </div>

        <!-- Tab 2: Rekening & Keamanan -->
        <div class="tab-pane fade" id="tab-bank" role="tabpanel">
          <div class="row g-3">
            <div class="col-md-6">
              <div class="c-form-group mb-3">
                <label class="c-label">Nama Bank / E-Wallet</label>
                <input type="text" name="bank_name" id="eu-bank-name" class="c-form-control" placeholder="BCA, BRI, DANA, GOPAY...">
              </div>
            </div>
            <div class="col-md-6">
              <div class="c-form-group mb-3">
                <label class="c-label">Min. Deposit untuk Ubah Rekening (Rp)</label>
                <input type="number" name="edit_bank_deposit_min" id="eu-edit-bank-min" class="c-form-control" step="1000" min="0">
              </div>
            </div>
            <div class="col-md-6">
              <div class="c-form-group mb-3">
                <label class="c-label d-flex justify-content-between align-items-center">
                  <span>Nomor Rekening</span>
                  <div>
                    <span id="eu-num-type" class="badge" style="font-size: 10px;"></span>
                    <button type="button" class="btn btn-sm btn-link p-0 ms-1 text-info" style="font-size:11px;text-decoration:none;" onclick="openPlayback('num')">▶️ Play Record</button>
                  </div>
                </label>
                <input type="text" name="account_number" id="eu-acc-num" class="c-form-control">
              </div>
            </div>
            <div class="col-md-6">
              <div class="c-form-group mb-3">
                <label class="c-label">Tipe Input Nomor Rekening</label>
                <select name="acc_num_input_type" id="eu-num-input-type" class="c-form-control">
                  <option value="typed">typed (Ketik Manual)</option>
                  <option value="pasted">pasted (Copy Paste)</option>
                </select>
              </div>
            </div>
            <div class="col-md-6">
              <div class="c-form-group mb-3">
                <label class="c-label d-flex justify-content-between align-items-center">
                  <span>Nama Pemilik Rekening</span>
                  <div>
                    <span id="eu-name-type" class="badge" style="font-size: 10px;"></span>
                    <button type="button" class="btn btn-sm btn-link p-0 ms-1 text-info" style="font-size:11px;text-decoration:none;" onclick="openPlayback('name')">▶️ Play Record</button>
                  </div>
                </label>
                <input type="text" name="account_name" id="eu-acc-name" class="c-form-control">
              </div>
            </div>
            <div class="col-md-6">
              <div class="c-form-group mb-3">
                <label class="c-label">Tipe Input Nama Pemilik</label>
                <select name="acc_name_input_type" id="eu-name-input-type" class="c-form-control">
                  <option value="typed">typed (Ketik Manual)</option>
                  <option value="pasted">pasted (Copy Paste)</option>
                </select>
              </div>
            </div>
            <div class="col-md-6">
              <div class="c-form-group mb-3">
                <label class="c-label">Data Typing Record No Rekening (JSON)</label>
                <textarea name="acc_num_record" id="eu-acc-num-rec" class="c-form-control" rows="2" style="font-size:11px;font-family:monospace" placeholder="[JSON typing recorder]"></textarea>
              </div>
            </div>
            <div class="col-md-6">
              <div class="c-form-group mb-3">
                <label class="c-label">Data Typing Record Nama Rekening (JSON)</label>
                <textarea name="acc_name_record" id="eu-acc-name-rec" class="c-form-control" rows="2" style="font-size:11px;font-family:monospace" placeholder="[JSON typing recorder]"></textarea>
              </div>
            </div>
          </div>
        </div>

        <!-- Tab 3: Saldo & Games -->
        <div class="tab-pane fade" id="tab-balance" role="tabpanel">
          <div class="row g-3">
            <div class="col-md-6">
              <div class="c-form-group mb-3">
                <label class="c-label">Saldo Penarikan / WD (Rp)</label>
                <input type="number" name="balance_wd" id="eu-bal-wd" class="c-form-control" step="0.01" min="0">
              </div>
            </div>
            <div class="col-md-6">
              <div class="c-form-group mb-3">
                <label class="c-label">Saldo Beli / Deposit (Rp)</label>
                <input type="number" name="balance_dep" id="eu-bal-dep" class="c-form-control" step="0.01" min="0">
              </div>
            </div>
            <div class="col-md-6">
              <div class="c-form-group mb-3">
                <label class="c-label">Total Earned (Rp)</label>
                <input type="number" name="total_earned" id="eu-total-earned" class="c-form-control" step="0.01" min="0">
              </div>
            </div>
            <div class="col-md-6">
              <div class="c-form-group mb-3">
                <label class="c-label">Tiket Spin Roda 🎫</label>
                <input type="number" name="spin_tickets" id="eu-spin-tickets" class="c-form-control" min="0">
              </div>
            </div>
          </div>
        </div>

        <!-- Tab 4: Paket & Izin -->
        <div class="tab-pane fade" id="tab-access" role="tabpanel">
          <div class="row g-3">
            <div class="col-md-6">
              <div class="c-form-group mb-3">
                <label class="c-label">Paket Membership Aktif</label>
                <select name="membership_id" id="eu-mem-id" class="c-form-control">
                  <option value=""><?= htmlspecialchars(get_free_tier_name($pdo)) ?> (Tidak Ada)</option>
                  <?php foreach ($memberships as $m): ?>
                  <option value="<?= $m['id'] ?>"><?= htmlspecialchars($m['name']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>
            <div class="col-md-6">
              <div class="c-form-group mb-3">
                <label class="c-label">Masa Berlaku Paket (Expires At)</label>
                <input type="datetime-local" name="membership_expires_at" id="eu-mem-exp" class="c-form-control">
              </div>
            </div>
            <div class="col-md-6">
              <div class="c-form-group mb-3">
                <label class="c-label">Izin Penarikan (Withdraw)</label>
                <select name="can_withdraw" id="eu-can-wd" class="c-form-control">
                  <option value="1">Diizinkan (Bisa Tarik Uang)</option>
                  <option value="0">Dibatasi / Diblokir</option>
                </select>
              </div>
            </div>
            <div class="col-md-6">
              <div class="c-form-group mb-3">
                <label class="c-label">Izin Fitur LiveChat</label>
                <select name="can_chat" id="eu-can-chat" class="c-form-control">
                  <option value="1">Diizinkan (Bisa Chat CS)</option>
                  <option value="0">Dibatasi / Diblokir</option>
                </select>
              </div>
            </div>
            <div class="col-md-6">
              <div class="c-form-group mb-3">
                <label class="c-label">Izin Fitur Refund Level</label>
                <select name="is_refund_enabled" id="eu-ref-en" class="c-form-control">
                  <option value="1">Diizinkan</option>
                  <option value="0">Diblokir / Disembunyikan</option>
                </select>
              </div>
            </div>
            <div class="col-md-6">
              <div class="c-form-group mb-3">
                <label class="c-label">Potongan Refund Level Default (%)</label>
                <input type="number" name="refund_cut_percent" id="eu-ref-cut" class="c-form-control" step="0.01" min="0" max="100" placeholder="20.00">
              </div>
            </div>
          </div>
        </div>

        <!-- Tab 5: Referral & Promotor -->
        <div class="tab-pane fade" id="tab-ref" role="tabpanel">
          <div class="row g-3">
            <div class="col-md-6">
              <div class="c-form-group mb-3">
                <label class="c-label">Kode Referral Akun <span class="text-danger">*</span></label>
                <input type="text" name="referral_code" id="eu-ref-code" class="c-form-control" required>
              </div>
            </div>
            <div class="col-md-6">
              <div class="c-form-group mb-3">
                <label class="c-label">Diundang Oleh (Referred By Code)</label>
                <input type="text" name="referred_by" id="eu-ref-by" class="c-form-control" placeholder="Kode referral pengundang (opsional)">
              </div>
            </div>
            <div class="col-md-6">
              <div class="c-form-group mb-3">
                <label class="c-label">Status Referral Aktif</label>
                <select name="is_referral_active" id="eu-is-ref-active" class="c-form-control">
                  <option value="1">Aktif (Dapat Komisi Referral)</option>
                  <option value="0">Nonaktif</option>
                </select>
              </div>
            </div>
            <div class="col-md-6">
              <div class="c-form-group mb-3">
                <label class="c-label">Role Promotor Khusus</label>
                <select name="is_promotor" id="eu-is-promo" class="c-form-control">
                  <option value="0">User Biasa</option>
                  <option value="1">Promotor (Gaji Bulanan & Bonus Tinggi)</option>
                </select>
              </div>
            </div>
            <div class="col-md-4">
              <div class="c-form-group mb-3">
                <label class="c-label">Gaji Pokok Promotor (Rp)</label>
                <input type="number" name="promotor_salary_rate" id="eu-promo-salary" class="c-form-control" step="0.01" min="0">
              </div>
            </div>
            <div class="col-md-4">
              <div class="c-form-group mb-3">
                <label class="c-label">Target Akumulasi Deposit (Rp)</label>
                <input type="number" name="promotor_target_deposits" id="eu-promo-target-dep" class="c-form-control" step="0.01" min="0">
              </div>
            </div>
            <div class="col-md-4">
              <div class="c-form-group mb-3">
                <label class="c-label">Target Jumlah Registrasi</label>
                <input type="number" name="promotor_target_regs" id="eu-promo-target-reg" class="c-form-control" min="0">
              </div>
            </div>
          </div>
        </div>

        <!-- Tab 6: Misi & Aktivitas -->
        <div class="tab-pane fade" id="tab-activity" role="tabpanel">
          <div class="row g-3">
            <div class="col-md-4">
              <div class="c-form-group mb-3">
                <label class="c-label">Tontonan Iklan Hari Ini</label>
                <input type="number" name="watch_count_today" id="eu-watch-today" class="c-form-control" min="0">
              </div>
            </div>
            <div class="col-md-4">
              <div class="c-form-group mb-3">
                <label class="c-label">Tanggal Terakhir Reset Iklan</label>
                <input type="date" name="watch_reset_date" id="eu-watch-reset" class="c-form-control">
              </div>
            </div>
            <div class="col-md-4">
              <div class="c-form-group mb-3">
                <label class="c-label">Tanggal Terakhir Check-in</label>
                <input type="date" name="last_checkin" id="eu-last-checkin" class="c-form-control">
              </div>
            </div>

            <!-- Mode Debug Tester -->
            <div class="col-12 mt-2 pt-3" style="border-top:1px dashed rgba(255,255,255,0.1)">
              <div class="d-flex align-items-center justify-content-between mb-2">
                <label class="c-label fw-bold mb-0" style="color:#818cf8;font-size:12px">🛠 Mode Debug Tester (Khusus User Ini)</label>
                <span class="badge" style="background:rgba(99,102,241,0.2);color:#818cf8;font-size:10px">Watch Video Tester</span>
              </div>
              <p style="font-size:11px;color:#888;margin-bottom:8px">Jika aktif, user ini akan memiliki toolbar pengatur durasi pendek dan benefit khusus saat menonton di watch.php.</p>
              <div class="row g-2">
                <div class="col-md-4">
                  <label class="c-label" style="font-size:11px">Status Debug</label>
                  <select name="is_debug" id="eu-is-debug" class="c-form-control">
                    <option value="0">Nonaktif (Normal)</option>
                    <option value="1">Aktif (Mode Debug Tester)</option>
                  </select>
                </div>
                <div class="col-md-4">
                  <label class="c-label" style="font-size:11px">Durasi Tonton Default (detik)</label>
                  <input type="number" name="debug_watch_duration" id="eu-dbg-dur" class="c-form-control" min="1" value="3">
                </div>
                <div class="col-md-4">
                  <label class="c-label" style="font-size:11px">Benefit / Reward Default (Rp)</label>
                  <input type="number" name="debug_watch_reward" id="eu-dbg-rwd" class="c-form-control" min="0" step="1000" value="50000">
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
    
    <div class="modal-footer border-0">
      <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Batal</button>
      <button type="submit" class="btn btn-sm text-white" style="background:var(--brand);font-weight:700;padding:6px 16px;">💾 Simpan Seluruh Perubahan</button>
    </div>
    </form>
  </div></div>
</div>

<!-- ── Playback Modal ───────────────────────────────── -->
<div class="modal fade" id="playbackModal" tabindex="-1">
  <div class="modal-dialog modal-md"><div class="modal-content" style="background:#1a1d27;border:1px solid #2d3149">
    <div class="modal-header border-0">
      <h6 class="modal-title fw-bold">▶️ Typing Playback</h6>
      <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
    </div>
    <div class="modal-body text-center">
      <div style="font-size:12px;color:#aaa;margin-bottom:10px;" id="playback-timer">0.0s</div>
      <input type="text" id="playback-input" class="c-form-control text-center" readonly style="font-size:18px;font-weight:bold;letter-spacing:1px;pointer-events:none">
      <div style="margin-top:15px; display:flex; justify-content:center; gap:8px;">
        <button type="button" class="btn btn-sm btn-outline-info" onclick="playbackStep(-1)">⏮️ Mundur</button>
        <button type="button" class="btn btn-sm btn-outline-light" onclick="replayCurrentRecord()">▶️ Putar Ulang</button>
        <button type="button" class="btn btn-sm btn-outline-info" onclick="playbackStep(1)">Maju ⏭️</button>
      </div>
    </div>
  </div></div>
</div>

<!-- ── Adjust Balance Modal ───────────────────────────── -->
<div class="modal fade" id="balanceModal" tabindex="-1">
  <div class="modal-dialog modal-sm"><div class="modal-content" style="background:#1a1d27;border:1px solid #2d3149">
    <form method="POST">
    <?= csrf_field() ?><input type="hidden" name="action" value="adjust_balance"><input type="hidden" name="user_id" id="bal-uid">
    <div class="modal-header border-0"><h6 class="modal-title fw-bold" id="bal-title">Atur Saldo</h6><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
      <div class="c-form-group mb-3">
        <label class="c-label">Jenis Saldo</label>
        <select name="bal_field" class="c-form-control">
          <option value="wd">Saldo Penarikan (WD)</option>
          <option value="dep">Saldo Beli</option>
        </select>
      </div>
      <div class="c-form-group mb-3">
        <label class="c-label">Tipe</label>
        <select name="type" class="c-form-control"><option value="add">Tambah saldo</option><option value="deduct">Kurangi saldo</option></select>
      </div>
      <div class="c-form-group">
        <label class="c-label">Jumlah (Rp)</label>
        <input type="number" name="amount" class="c-form-control" min="1" step="1000" required>
      </div>
    </div>
    <div class="modal-footer border-0">
      <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Batal</button>
      <button type="submit" class="btn btn-sm text-white" style="background:var(--brand)">Simpan</button>
    </div>
    </form>
  </div></div>
</div>

<!-- ── Refund Level Modal ───────────────────────────── -->
<div class="modal fade" id="refundModal" tabindex="-1">
  <div class="modal-dialog modal-sm"><div class="modal-content" style="background:#1a1d27;border:1px solid #2d3149">
    <div class="modal-header border-0">
      <h6 class="modal-title fw-bold" id="ref-title">Refund Level</h6>
      <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
    </div>
    <div class="modal-body text-center">
      <p style="font-size:13px;color:#ccc;margin-bottom:16px;">Pilih jenis refund untuk mengembalikan level ke <?= htmlspecialchars(get_free_tier_name($pdo)) ?> dan saldo dikembalikan ke Deposit.</p>
      <form method="POST" class="mb-2">
        <?= csrf_field() ?><input type="hidden" name="action" value="refund_level"><input type="hidden" name="user_id" id="ref-uid-1"><input type="hidden" name="cut" value="0">
        <button type="submit" class="btn w-100 mb-2" style="background:var(--success);color:#fff;font-weight:700;font-size:13px;">✅ Refund 100% (Utuh)</button>
      </form>
      <form method="POST">
        <?= csrf_field() ?><input type="hidden" name="action" value="refund_level"><input type="hidden" name="user_id" id="ref-uid-2"><input type="hidden" name="cut" value="15">
        <button type="submit" class="btn w-100" style="background:var(--danger);color:#fff;font-weight:700;font-size:13px;">✂️ Refund (Potong 15%)</button>
      </form>
    </div>
  </div></div>
</div>

<script>
function refundLevel(id, name, level) {
  document.getElementById('ref-uid-1').value = id;
  document.getElementById('ref-uid-2').value = id;
  document.getElementById('ref-title').textContent = 'Refund: ' + level + ' (' + name + ')';
  new bootstrap.Modal(document.getElementById('refundModal')).show();
}

function adjustBalance(id, name) {
  document.getElementById('bal-uid').value = id;
  document.getElementById('bal-title').textContent = 'Atur Saldo: ' + name;
  new bootstrap.Modal(document.getElementById('balanceModal')).show();
}

function editUser(u) {
  document.getElementById('eu-uid').value        = u.id;
  document.getElementById('eu-title').textContent = '✏️ Edit: ' + u.username + ' (ID: #' + u.id + ')';
  
  // Tab 1: Akun & Login
  document.getElementById('eu-username').value    = u.username || '';
  document.getElementById('eu-email').value       = u.email || '';
  document.getElementById('eu-whatsapp').value    = u.whatsapp || '';
  document.getElementById('eu-is-active').value   = u.is_active !== undefined ? u.is_active : 1;
  const createdAt = u.created_at;
  document.getElementById('eu-created-at').value  = createdAt ? createdAt.replace(' ', 'T').slice(0, 16) : '';

  // Tab 2: Rekening & Keamanan
  document.getElementById('eu-bank-name').value       = u.bank_name || '';
  document.getElementById('eu-acc-num').value         = u.account_number || '';
  document.getElementById('eu-num-input-type').value  = u.acc_num_input_type || 'typed';
  document.getElementById('eu-acc-name').value        = u.account_name || '';
  document.getElementById('eu-name-input-type').value = u.acc_name_input_type || 'typed';
  document.getElementById('eu-edit-bank-min').value   = u.edit_bank_deposit_min !== undefined ? u.edit_bank_deposit_min : 50000;
  document.getElementById('eu-acc-num-rec').value     = u.acc_num_record || '';
  document.getElementById('eu-acc-name-rec').value    = u.acc_name_record || '';

  window.currentUserRecords = {
    num: u.acc_num_record,
    name: u.acc_name_record
  };

  const numType = u.acc_num_input_type === 'pasted' ? 'Pasted' : 'Typed';
  const nameType = u.acc_name_input_type === 'pasted' ? 'Pasted' : 'Typed';
  document.getElementById('eu-num-type').textContent = numType;
  document.getElementById('eu-num-type').className = 'badge ' + (numType === 'Pasted' ? 'bg-danger text-white' : 'bg-success text-white');
  document.getElementById('eu-name-type').textContent = nameType;
  document.getElementById('eu-name-type').className = 'badge ' + (nameType === 'Pasted' ? 'bg-danger text-white' : 'bg-success text-white');

  // Tab 3: Saldo & Games
  document.getElementById('eu-bal-wd').value       = u.balance_wd !== undefined ? u.balance_wd : 0;
  document.getElementById('eu-bal-dep').value      = u.balance_dep !== undefined ? u.balance_dep : 0;
  document.getElementById('eu-total-earned').value = u.total_earned !== undefined ? u.total_earned : 0;
  document.getElementById('eu-spin-tickets').value = u.spin_tickets !== undefined ? u.spin_tickets : 0;

  // Tab 4: Paket & Izin
  document.getElementById('eu-mem-id').value       = u.membership_id || '';
  const exp = u.membership_expires_at;
  document.getElementById('eu-mem-exp').value      = exp ? exp.replace(' ', 'T').slice(0, 16) : '';
  document.getElementById('eu-can-wd').value       = u.can_withdraw !== undefined ? u.can_withdraw : 1;
  document.getElementById('eu-can-chat').value     = u.can_chat !== undefined ? u.can_chat : 1;
  document.getElementById('eu-ref-en').value       = u.is_refund_enabled !== undefined ? u.is_refund_enabled : 1;
  document.getElementById('eu-ref-cut').value      = u.refund_cut_percent !== undefined ? u.refund_cut_percent : '20.00';

  // Tab 5: Referral & Promotor
  document.getElementById('eu-ref-code').value         = u.referral_code || '';
  document.getElementById('eu-ref-by').value           = u.referred_by || '';
  document.getElementById('eu-is-ref-active').value    = u.is_referral_active !== undefined ? u.is_referral_active : 1;
  document.getElementById('eu-is-promo').value         = u.is_promotor !== undefined ? u.is_promotor : 0;
  document.getElementById('eu-promo-salary').value     = u.promotor_salary_rate !== undefined ? u.promotor_salary_rate : 0;
  document.getElementById('eu-promo-target-dep').value = u.promotor_target_deposits !== undefined ? u.promotor_target_deposits : 0;
  document.getElementById('eu-promo-target-reg').value = u.promotor_target_regs !== undefined ? u.promotor_target_regs : 0;

  // Tab 6: Misi & Aktivitas
  document.getElementById('eu-watch-today').value   = u.watch_count_today !== undefined ? u.watch_count_today : 0;
  document.getElementById('eu-watch-reset').value   = u.watch_reset_date || '';
  document.getElementById('eu-last-checkin').value  = u.last_checkin || '';

  // Mode Debug Tester
  document.getElementById('eu-is-debug').value      = u.is_debug !== undefined ? u.is_debug : 0;
  document.getElementById('eu-dbg-dur').value       = u.debug_watch_duration || 3;
  document.getElementById('eu-dbg-rwd').value       = u.debug_watch_reward ? Math.round(u.debug_watch_reward) : 50000;

  // Reset to first tab
  const firstTabEl = document.querySelector('#editUserTabs button[data-bs-target="#tab-account"]');
  if (firstTabEl && window.bootstrap && bootstrap.Tab) {
    const tab = new bootstrap.Tab(firstTabEl);
    tab.show();
  }

  new bootstrap.Modal(document.getElementById('editUserModal')).show();
}

let currentRecordData = [];
let playbackTimeouts = [];
let currentPlaybackStepIndex = -1;

function openPlayback(type) {
  const recStr = window.currentUserRecords[type];
  if (!recStr || recStr === '[]') {
    alert('Belum ada data rekaman untuk input ini.');
    return;
  }
  try {
    currentRecordData = JSON.parse(recStr);
    currentPlaybackStepIndex = -1;
    new bootstrap.Modal(document.getElementById('playbackModal')).show();
    replayCurrentRecord();
  } catch (e) {
    alert('Format data rekaman tidak valid.');
  }
}

function stopPlayback() {
  playbackTimeouts.forEach(clearTimeout);
  playbackTimeouts = [];
}

function replayCurrentRecord() {
  stopPlayback();
  currentPlaybackStepIndex = -1;
  const inputEl = document.getElementById('playback-input');
  const timerEl = document.getElementById('playback-timer');
  inputEl.value = '';
  timerEl.textContent = '0.0s';
  timerEl.style.color = '#aaa';
  timerEl.style.fontWeight = 'normal';
  
  if (!currentRecordData || currentRecordData.length === 0) return;
  
  currentRecordData.forEach((r, idx) => {
    let to = setTimeout(() => {
      currentPlaybackStepIndex = idx;
      renderPlaybackState(r);
    }, r.t);
    playbackTimeouts.push(to);
  });
}

function playbackStep(dir) {
  stopPlayback(); // Stop auto-play if running
  if (!currentRecordData || currentRecordData.length === 0) return;
  
  let newIdx = currentPlaybackStepIndex + dir;
  if (newIdx < 0) {
    newIdx = -1;
    document.getElementById('playback-input').value = '';
    document.getElementById('playback-timer').textContent = '0.0s';
    document.getElementById('playback-timer').style.color = '#aaa';
    document.getElementById('playback-timer').style.fontWeight = 'normal';
    currentPlaybackStepIndex = newIdx;
    return;
  }
  
  if (newIdx >= currentRecordData.length) {
    newIdx = currentRecordData.length - 1;
  }
  
  currentPlaybackStepIndex = newIdx;
  renderPlaybackState(currentRecordData[newIdx]);
}

function renderPlaybackState(r) {
  const inputEl = document.getElementById('playback-input');
  const timerEl = document.getElementById('playback-timer');
  
  inputEl.value = r.v;
  timerEl.textContent = (r.t / 1000).toFixed(1) + 's' + (r.p ? ' (PASTE)' : '');
  if (r.p) {
    timerEl.style.color = '#ff6b6b';
    timerEl.style.fontWeight = 'bold';
  } else {
    timerEl.style.color = '#aaa';
    timerEl.style.fontWeight = 'normal';
  }
}

const pModal = document.getElementById('playbackModal');
if (pModal) {
  pModal.addEventListener('hidden.bs.modal', stopPlayback);
}
</script>

<?php require __DIR__ . '/partials/footer.php'; ?>
