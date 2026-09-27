<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/bootstrap.php';
if (auth_user($pdo)) redirect('/home');
csrf_enforce();

$error = '';

// Rate limit
$ip_key   = 'login_' . md5($_SERVER['REMOTE_ADDR'] ?? 'x');
$attempts = (int)($_SESSION[$ip_key . '_att'] ?? 0);
$lock_until = (int)($_SESSION[$ip_key . '_lock'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (time() < $lock_until) {
        $wait  = ceil(($lock_until - time()) / 60);
        $error = "Akun terkunci sementara. Coba lagi dalam {$wait} menit.";
        goto end_login;
    }

    $login = trim($_POST['login'] ?? '');
    $pwd   = $_POST['password'] ?? '';
    $field = filter_var($login, FILTER_VALIDATE_EMAIL) ? 'email' : 'username';
    $s     = $pdo->prepare("SELECT * FROM users WHERE {$field}=? AND is_active=1");
    $s->execute([$login]);
    $user  = $s->fetch();

    if ($user && password_verify($pwd, $user['password_hash'])) {
        unset($_SESSION[$ip_key . '_att'], $_SESSION[$ip_key . '_lock']);
        session_regenerate_id(true);
        set_auth_cookie((int)$user['id']);
        redirect('/home');
    }

    $new_att = $attempts + 1;
    $_SESSION[$ip_key . '_att'] = $new_att;
    if ($new_att >= 5) {
        $_SESSION[$ip_key . '_lock'] = time() + 600;
        $error = 'Terlalu banyak percobaan gagal. Coba lagi dalam 10 menit.';
    } else {
        $left  = 5 - $new_att;
        $error = "Username/email atau kata sandi salah. Sisa percobaan: {$left}";
    }
}
end_login:

// Load SEO settings
$_seo_title  = setting($pdo, 'seo_title', 'LebahCuan');
$_seo_desc   = setting($pdo, 'seo_description', 'Platform nonton video dan ternak lebah penghasil cuan resmi.');
$_favicon    = setting($pdo, 'favicon_path', '');
$_page_title = 'Masuk — ' . $_seo_title;
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,user-scalable=no">
<meta name="theme-color" content="#78350f">
<title><?= htmlspecialchars($_page_title) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Nunito:wght@600;700;800;900&display=swap" rel="stylesheet">
<!-- Phosphor Icons (CDN) -->
<script src="https://unpkg.com/@phosphor-icons/web@2.1.1"></script>

<?php if ($_favicon): ?>
<link rel="icon" href="<?= htmlspecialchars($_favicon) ?>">
<?php endif; ?>

<style>
/* ══════════════════════════════════════════════════════════
   LEBAHCUAN — COMPACT AMBER HONEY LOGIN
   Zero Emojis • Phosphor Icons • OJK & Bappebti Badges
   ══════════════════════════════════════════════════════════ */
* {
  box-sizing: border-box;
  margin: 0;
  padding: 0;
}

body {
  font-family: 'Nunito', sans-serif;
  background-color: #fef8ee;
  background-image: radial-gradient(rgba(217, 119, 6, 0.08) 1.5px, transparent 1.5px);
  background-size: 16px 16px;
  min-height: 100vh;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 16px;
  color: #1e293b;
}

/* Compact Login Wrapper */
.login-card {
  width: 100%;
  max-width: 360px;
  background: #ffffff;
  border: 3px solid #78350f;
  border-radius: 28px;
  box-shadow: 0 6px 0 #78350f, 0 16px 30px rgba(120, 53, 15, 0.12);
  overflow: hidden;
  position: relative;
}

/* Top Banner Header */
.login-header {
  background: linear-gradient(180deg, #78350f 0%, #92400e 40%, #b45309 75%, #d97706 100%);
  padding: 22px 18px 18px;
  text-align: center;
  position: relative;
  border-bottom: 3px solid #78350f;
}
.login-header::before {
  content: '';
  position: absolute;
  inset: 0;
  background-image: radial-gradient(#fbbf24 1px, transparent 1px);
  background-size: 14px 14px;
  opacity: 0.18;
  pointer-events: none;
}

.brand-badge {
  width: 54px;
  height: 54px;
  border-radius: 18px;
  background: #ffffff;
  border: 2.5px solid #78350f;
  box-shadow: 0 3px 0 #78350f;
  margin: 0 auto 10px;
  display: flex;
  align-items: center;
  justify-content: center;
  position: relative;
  z-index: 2;
}
.brand-badge img {
  width: 38px;
  height: 38px;
  object-fit: contain;
}

.login-title {
  position: relative;
  z-index: 2;
  font-size: 19px;
  font-weight: 900;
  color: #ffffff;
  letter-spacing: -0.3px;
  text-shadow: 0 2px 4px rgba(0,0,0,0.3);
  margin-bottom: 2px;
}
.login-sub {
  position: relative;
  z-index: 2;
  font-size: 11.5px;
  font-weight: 700;
  color: #fef3c7;
}

/* Form Body */
.login-body {
  padding: 20px 18px 16px;
}

/* Error Flash */
.auth-err {
  background: #fee2e2;
  border: 2px solid #dc2626;
  border-radius: 14px;
  padding: 9px 12px;
  font-size: 11.5px;
  font-weight: 800;
  color: #991b1b;
  margin-bottom: 16px;
  display: flex;
  align-items: center;
  gap: 8px;
  box-shadow: 0 2px 0 #dc2626;
}

/* Input Fields */
.inp-group {
  margin-bottom: 14px;
}
.inp-label {
  display: block;
  font-size: 11px;
  font-weight: 900;
  color: #78350f;
  margin-bottom: 5px;
  text-transform: uppercase;
  letter-spacing: 0.3px;
}
.inp-wrap {
  display: flex;
  align-items: center;
  gap: 8px;
  background: #ffffff;
  border: 2px solid #78350f;
  border-radius: 14px;
  padding: 0 12px;
  height: 46px;
  box-shadow: 0 2.5px 0 #78350f;
  transition: border-color 0.15s, box-shadow 0.15s;
}
.inp-wrap:focus-within {
  border-color: #d97706;
  box-shadow: 0 3px 0 #d97706, 0 0 8px rgba(245, 158, 11, 0.25);
}
.inp-icon {
  font-size: 18px;
  color: #b45309;
  flex-shrink: 0;
}
.inp-field {
  flex: 1;
  border: none;
  outline: none;
  background: transparent;
  font-family: inherit;
  font-size: 13.5px;
  font-weight: 800;
  color: #0f172a;
  width: 100%;
}
.inp-field::placeholder {
  color: #94a3b8;
  font-weight: 700;
  font-size: 12.5px;
}
.eye-btn {
  background: none;
  border: none;
  cursor: pointer;
  padding: 4px;
  color: #94a3b8;
  font-size: 18px;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: color 0.1s;
}
.eye-btn:hover {
  color: #78350f;
}

/* Submit CTA */
.btn-login-submit {
  width: 100%;
  height: 48px;
  border: 2.5px solid #78350f;
  border-radius: 15px;
  background: linear-gradient(180deg, #f59e0b 0%, #d97706 100%);
  color: #ffffff;
  font-size: 14px;
  font-weight: 900;
  letter-spacing: 0.3px;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  box-shadow: 0 4px 0 #78350f;
  text-shadow: 0 1px 2px #78350f;
  cursor: pointer;
  font-family: inherit;
  margin-top: 18px;
  transition: transform 0.1s;
}
.btn-login-submit:active {
  transform: translateY(3px);
  box-shadow: 0 1px 0 #78350f;
}

/* Register link */
.login-footer-links {
  text-align: center;
  margin-top: 14px;
  font-size: 11.5px;
  font-weight: 700;
  color: #78350f;
}
.login-reg-link {
  font-weight: 900;
  color: #b45309;
  text-decoration: underline;
  margin-left: 3px;
}

/* ── TRUST & REGULATION BOX (OJK & BAPPEBTI) ── */
.auth-trust-box {
  margin-top: 18px;
  padding-top: 14px;
  border-top: 1.5px dashed #fde68a;
  text-align: center;
}
.trust-lbl {
  font-size: 9.5px;
  font-weight: 900;
  color: #92400e;
  text-transform: uppercase;
  letter-spacing: 0.4px;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 4px;
  margin-bottom: 8px;
}
.trust-logos {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 14px;
  background: #fdfaf6;
  border: 1.5px solid #fde68a;
  border-radius: 12px;
  padding: 8px 12px;
}
.trust-logo-img {
  height: 24px;
  max-width: 95px;
  object-fit: contain;
  filter: contrast(1.05);
}
.trust-sep {
  width: 1px;
  height: 20px;
  background: #cbd5e1;
}
</style>
</head>
<body>

<div class="login-card">
  <!-- Top Banner Header -->
  <div class="login-header">
    <div class="brand-badge">
      <img src="/assets/game/bee_worker.png" alt="LebahCuan">
    </div>
    <h1 class="login-title">Masuk Akun LebahCuan</h1>
    <p class="login-sub">Tonton Video & Raih Saldo Rupiah Setiap Hari</p>
  </div>

  <!-- Form Body -->
  <div class="login-body">
    <?php if ($error): ?>
      <div class="auth-err">
        <i class="ph-bold ph-warning-circle" style="font-size:16px;"></i>
        <span><?= htmlspecialchars($error) ?></span>
      </div>
    <?php endif; ?>

    <form method="POST">
      <?= csrf_field() ?>

      <!-- Username / Email Field -->
      <div class="inp-group">
        <label class="inp-label">Username atau Email</label>
        <div class="inp-wrap">
          <i class="ph-bold ph-user inp-icon"></i>
          <input type="text"
                 name="login"
                 class="inp-field"
                 value="<?= htmlspecialchars($_POST['login'] ?? '') ?>"
                 placeholder="Masukkan username / email"
                 autocomplete="username"
                 required
                 autofocus>
        </div>
      </div>

      <!-- Password Field -->
      <div class="inp-group">
        <label class="inp-label">Kata Sandi (Password)</label>
        <div class="inp-wrap">
          <i class="ph-bold ph-lock-key inp-icon"></i>
          <input type="password"
                 id="pwd-input"
                 name="password"
                 class="inp-field"
                 placeholder="Masukkan kata sandi"
                 autocomplete="current-password"
                 required>
          <button type="button"
                  class="eye-btn"
                  onclick="togglePasswordVisibility()"
                  title="Lihat password">
            <i class="ph-bold ph-eye" id="eye-icon"></i>
          </button>
        </div>
      </div>

      <!-- Submit CTA Button -->
      <button type="submit" class="btn-login-submit">
        <span>Masuk Sekarang</span>
        <i class="ph-bold ph-arrow-right"></i>
      </button>
    </form>

    <!-- Register Link -->
    <div class="login-footer-links">
      <span>Belum punya akun?</span>
      <a href="/register" class="login-reg-link">Daftar Akun Baru</a>
    </div>

    <!-- ── LOGO DIAWASI OJK & BAPPEBTI ── -->
    <div class="auth-trust-box">
      <div class="trust-lbl">
        <i class="ph-fill ph-shield-check" style="color:#059669;font-size:12px;"></i>
        <span>Diawasi & Terdaftar Resmi</span>
      </div>
      <div class="trust-logos">
        <img src="/assets/ojkkk.png" alt="Otoritas Jasa Keuangan" class="trust-logo-img">
        <div class="trust-sep"></div>
        <img src="/assets/bap.png" alt="Bappebti" class="trust-logo-img">
      </div>
    </div>

  </div>
</div>

<script>
function togglePasswordVisibility() {
  const inp = document.getElementById('pwd-input');
  const ico = document.getElementById('eye-icon');
  if (!inp || !ico) return;

  if (inp.type === 'password') {
    inp.type = 'text';
    ico.className = 'ph-bold ph-eye-slash';
  } else {
    inp.type = 'password';
    ico.className = 'ph-bold ph-eye';
  }
}
</script>

</body>
</html>
