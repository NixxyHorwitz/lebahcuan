<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/bootstrap.php';

// ── Auth: accept either admin session OR valid signed token ──────────────────
// Token format: uid={id}&exp={timestamp}&sig={hmac}
// Generated in chat_action.php when sending the PM with Mini App button.

$is_authed = false;

// 1. Admin session (for normal browser access)
if (!empty($_SESSION['admin'])) {
    $is_authed = true;
}

// 2. Signed token via GET (for Telegram Mini App, no session)
if (!$is_authed && isset($_GET['tok'])) {
    $tok_raw = $_GET['tok'];
    $parts   = [];
    parse_str(base64_decode($tok_raw), $parts);
    $tok_uid = (int)($parts['uid'] ?? 0);
    $tok_exp = (int)($parts['exp'] ?? 0);
    $tok_sig = $parts['sig'] ?? '';

    $secret   = hash('sha256', 'TONTON_EDIT_' . ($_ENV['DB_PASSWORD'] ?? 'secret'));
    $expected = hash_hmac('sha256', "uid={$tok_uid}&exp={$tok_exp}", $secret);

    if ($tok_uid > 0 && $tok_exp > time() && hash_equals($expected, $tok_sig)) {
        $is_authed = true;
        // Override uid from token (ignore GET id, use token's uid)
        $_GET['id'] = $tok_uid;
    }
}

if (!$is_authed) {
    http_response_code(403);
    echo '<div style="font-family:sans-serif;padding:40px;text-align:center;color:#e55;background:#131520;min-height:100vh">'
       . '<div style="font-size:48px">🔒</div>'
       . '<h3>Akses Ditolak</h3>'
       . '<p style="color:#888">Token tidak valid atau sudah kadaluarsa.<br>Silakan hubungi administrator pusat.</p>'
       . '</div>';
    exit;
}

// ── Load user ────────────────────────────────────────────────────────────────
$uid = (int)($_GET['id'] ?? 0);
if (!$uid) {
    echo "ID User tidak valid."; exit;
}

$stmt = $pdo->prepare("SELECT * FROM users WHERE id=?");
$stmt->execute([$uid]);
$u = $stmt->fetch();
if (!$u) {
    echo "User tidak ditemukan."; exit;
}

$flash = $flashType = '';
$memberships = $pdo->query("SELECT id, name FROM memberships WHERE is_active=1 ORDER BY sort_order ASC")->fetchAll();

// ── Handle POST ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $wd   = (float)preg_replace('/\D/', '', $_POST['balance_wd']         ?? '0');
    $dep  = (float)preg_replace('/\D/', '', $_POST['balance_dep']        ?? '0');
    $ebdm = (int)  preg_replace('/\D/', '', $_POST['edit_bank_deposit_min'] ?? '50000');

    
    $is_act = isset($_POST['is_active']) ? (int)$_POST['is_active'] : $u['is_active'];
    $is_pro = isset($_POST['is_promotor']) ? (int)$_POST['is_promotor'] : $u['is_promotor'];
    $mem_id = !empty($_POST['membership_id']) ? (int)$_POST['membership_id'] : null;
    $new_pw = trim($_POST['new_password'] ?? '');

    $pdo->prepare("UPDATE users SET balance_wd=?, balance_dep=?, edit_bank_deposit_min=?, is_active=?, is_promotor=?, membership_id=? WHERE id=?")
        ->execute([$wd, $dep, $ebdm, $is_act, $is_pro, $mem_id, $uid]);

    if ($new_pw !== '') {
        $pdo->prepare("UPDATE users SET password_hash=? WHERE id=?")->execute([password_hash($new_pw, PASSWORD_DEFAULT), $uid]);
    }

    $flash = "✅ Data berhasil diupdate!";
    $flashType = "success";

    // Refresh data
    $stmt->execute([$uid]);
    $u = $stmt->fetch();
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Edit User — <?= htmlspecialchars($u['username']) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://telegram.org/js/telegram-web-app.js"></script>
    <style>
        body {
            background: var(--tg-theme-bg-color, #131520);
            color: var(--tg-theme-text-color, #fff);
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            padding: 16px;
            margin: 0;
        }
        .card {
            background: var(--tg-theme-secondary-bg-color, #1f2235);
            border: 1px solid rgba(255,255,255,.08);
            border-radius: 16px;
            padding: 20px;
            margin-bottom: 12px;
        }
        .user-badge {
            display: flex; align-items: center; gap: 10px;
            padding: 12px; border-radius: 10px;
            background: rgba(78,155,255,.12);
            border: 1px solid rgba(78,155,255,.25);
            margin-bottom: 20px;
        }
        .user-avatar {
            width: 40px; height: 40px; border-radius: 50%;
            background: var(--tg-theme-button-color, #4E9BFF);
            display: flex; align-items: center; justify-content: center;
            font-weight: 900; font-size: 16px; flex-shrink: 0;
        }
        .form-control {
            background: var(--tg-theme-bg-color, #131520);
            color: var(--tg-theme-text-color, #fff);
            border: 1px solid rgba(255,255,255,.15);
            border-radius: 10px;
            padding: 10px 14px;
            font-size: 16px;
        }
        .form-control:focus {
            background: var(--tg-theme-bg-color, #131520);
            color: var(--tg-theme-text-color, #fff);
            box-shadow: 0 0 0 2px var(--tg-theme-button-color, #4E9BFF);
            border-color: transparent;
            outline: none;
        }
        label { color: var(--tg-theme-hint-color, #aaa); font-size: 12px; font-weight: 600; margin-bottom: 6px; display: block; }
        .btn-save {
            background: var(--tg-theme-button-color, #4E9BFF);
            color: var(--tg-theme-button-text-color, #fff);
            border: none; border-radius: 12px;
            padding: 14px; font-size: 15px; font-weight: 700;
            width: 100%; cursor: pointer;
        }
        .btn-save:active { opacity: 0.85; }
        .flash { padding: 10px 14px; border-radius: 10px; font-size: 13px; margin-bottom: 14px; }
        .flash-success { background: rgba(52,199,89,.2); border: 1px solid rgba(52,199,89,.4); color: #34c759; }
        .flash-error   { background: rgba(255,59,48,.2);  border: 1px solid rgba(255,59,48,.4);  color: #ff3b30; }
        .hint { font-size: 11px; color: var(--tg-theme-hint-color, #888); margin-top: 5px; }
        .section-title { font-size: 11px; font-weight: 700; color: var(--tg-theme-hint-color, #888); text-transform: uppercase; letter-spacing: 0.8px; margin-bottom: 12px; }
    </style>
</head>
<body>
    <div class="user-badge">
        <div class="user-avatar"><?= strtoupper(mb_substr($u['username'], 0, 1)) ?></div>
        <div>
            <div style="font-weight:800;font-size:15px"><?= htmlspecialchars($u['username']) ?></div>
            <div style="font-size:11px;color:var(--tg-theme-hint-color,#888)"><?= htmlspecialchars($u['email']) ?></div>
        </div>
    </div>

    <?php if ($flash): ?>
    <div class="flash flash-<?= $flashType ?>">
        <?= htmlspecialchars($flash) ?>
    </div>
    <?php endif; ?>

    <form method="POST">
        <div class="card">
            <div class="section-title">👤 Edit Profil & Status</div>

            <div class="mb-3">
                <label>Status Akun</label>
                <select name="is_active" class="form-control">
                    <option value="1" <?= $u['is_active']==1?'selected':'' ?>>Aktif (Bisa Komentar)</option>
                    <option value="0" <?= $u['is_active']==0?'selected':'' ?>>Banned (Mute)</option>
                </select>
            </div>
            
            <div class="mb-3">
                <label>Status Promotor</label>
                <select name="is_promotor" class="form-control">
                    <option value="0" <?= $u['is_promotor']==0?'selected':'' ?>>User Biasa</option>
                    <option value="1" <?= $u['is_promotor']==1?'selected':'' ?>>Promotor</option>
                </select>
            </div>

            <div class="mb-3">
                <label>Paket Membership</label>
                <select name="membership_id" class="form-control">
                    <option value=""><?= htmlspecialchars(get_free_tier_name($pdo)) ?> (Tidak ada)</option>
                    <?php foreach($memberships as $m): ?>
                    <option value="<?= $m['id'] ?>" <?= $u['membership_id']==$m['id']?'selected':'' ?>><?= htmlspecialchars($m['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="mb-3">
                <label>Password Baru</label>
                <input type="text" name="new_password" class="form-control" placeholder="Kosongkan jika tidak diubah">
            </div>
        </div>

        <div class="card">
            <div class="section-title">💰 Edit Saldo</div>

            <div class="mb-3">
                <label>Saldo Penarikan (WD)</label>
                <input type="number" name="balance_wd" class="form-control"
                       value="<?= (int)$u['balance_wd'] ?>" required>
                <div class="hint">Saat ini: Rp <?= number_format((float)$u['balance_wd'], 0, ',', '.') ?></div>
            </div>

            <div class="mb-3">
                <label>Saldo Beli</label>
                <input type="number" name="balance_dep" class="form-control"
                       value="<?= (int)$u['balance_dep'] ?>" required>
                <div class="hint">Saat ini: Rp <?= number_format((float)$u['balance_dep'], 0, ',', '.') ?></div>
            </div>
        </div>

        <div class="card">
            <div class="section-title">🛡️ Keamanan Edit Rekening</div>
            <div class="mb-3">
                <label>Min. Saldo Beli untuk Edit Rekening (Rp)</label>
                <input type="number" name="edit_bank_deposit_min" class="form-control"
                       value="<?= (int)($u['edit_bank_deposit_min'] ?? 50000) ?>">
                <div class="hint">Jika level user mengizinkan edit rekening, user wajib punya saldo beli minimal ini. Default: 50.000</div>
            </div>
        </div>

        <button type="submit" class="btn-save">💾 Simpan Perubahan</button>
    </form>

    <script>
        if (window.Telegram && window.Telegram.WebApp) {
            window.Telegram.WebApp.ready();
            window.Telegram.WebApp.expand();
        }
    </script>
</body>
</html>
