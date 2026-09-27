<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/bootstrap.php';
if (auth_user($pdo)) redirect('/home');
csrf_enforce();

$error = '';
$error_fields = [];

// Client IP resolver (Handles Cloudflare & Reverse Proxy)
$client_ip = $_SERVER['HTTP_CF_CONNECTING_IP'] 
    ?? $_SERVER['HTTP_X_FORWARDED_FOR'] 
    ?? $_SERVER['REMOTE_ADDR'] 
    ?? '127.0.0.1';
if (str_contains($client_ip, ',')) {
    $client_ip = trim(explode(',', $client_ip)[0]);
}

// Rate limiting by session
$ip_key = 'reg_' . md5($client_ip);
$attempts = (int)($_SESSION[$ip_key . '_attempts'] ?? 0);
$lock_until = (int)($_SESSION[$ip_key . '_lock'] ?? 0);

// Captcha Generator Function
function generate_captcha_challenge(): array {
    $n1 = rand(10, 35);
    $n2 = rand(2, 12);
    $op = (rand(0, 1) === 1) ? '+' : '-';
    if ($op === '-' && $n1 < $n2) {
        [$n1, $n2] = [$n2, $n1];
    }
    $ans = ($op === '+') ? ($n1 + $n2) : ($n1 - $n2);
    
    $payload = [
        'ans' => $ans,
        'ts'  => time(),
        'n'   => bin2hex(random_bytes(6))
    ];
    $token = base64_encode(json_encode($payload));
    $secret = 'LebahCuan_SecCap_' . date('Ymd');
    $sig = hash_hmac('sha256', $token, $secret);
    
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 160 46" width="160" height="46" style="display:block;border-radius:12px;background:#fffbeb;border:2px solid #fde68a;">'
         . '<defs>'
         . '<pattern id="hdots" x="0" y="0" width="10" height="10" patternUnits="userSpaceOnUse">'
         . '<circle cx="5" cy="5" r="1.5" fill="#fde68a" opacity="0.6"/>'
         . '</pattern>'
         . '</defs>'
         . '<rect width="100%" height="100%" fill="url(#hdots)"/>'
         . '<path d="M 8 23 Q 45 5, 85 23 T 152 21" fill="none" stroke="#fcd34d" stroke-width="2.5" opacity="0.6"/>'
         . '<path d="M 12 33 Q 52 43, 92 31 T 148 25" fill="none" stroke="#fbbf24" stroke-width="1.8" opacity="0.4"/>'
         . '<text x="50%" y="60%" dominant-baseline="middle" text-anchor="middle" font-family="\'Nunito\', sans-serif" font-weight="900" font-size="20" fill="#78350f" letter-spacing="1">'
         . "{$n1} {$op} {$n2} = ?"
         . '</text>'
         . '</svg>';
         
    return [
        'svg'   => $svg,
        'token' => $token,
        'sig'   => $sig,
        'ans'   => $ans
    ];
}

// AJAX Refresh Captcha Endpoint
if (isset($_GET['refresh_captcha'])) {
    header('Content-Type: application/json');
    $c = generate_captcha_challenge();
    echo json_encode(['ok' => true, 'svg' => $c['svg'], 'token' => $c['token'], 'sig' => $c['sig']]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (time() < $lock_until) {
        $wait = ceil(($lock_until - time()) / 60);
        $error = "Terlalu banyak percobaan pendaftaran gagal. Silakan tunggu {$wait} menit lagi.";
        goto end_reg;
    }

    // ── 1. HONEYPOT ANTI-BOT TRAP ──
    if (!empty($_POST['website_hp_check'])) {
        $msg_bot = "🚨 <b>PERINGATAN ABUSE: BOT SPAMMER TERPERANGKAP HONEYPOT!</b>\n\n"
            . "🌐 <b>IP Address:</b> <code>{$client_ip}</code>\n"
            . "👤 <b>Username:</b> <code>" . htmlspecialchars($_POST['username'] ?? '') . "</code>\n"
            . "📝 <b>Input Perangkap:</b> " . htmlspecialchars($_POST['website_hp_check']) . "\n"
            . "🛑 <b>Status:</b> Pendaftaran otomatis digagalkan sistem.\n"
            . "🕐 <b>Waktu:</b> " . date('d M Y H:i:s');
        send_telegram_notif($pdo, $msg_bot, [], 'abuse');
        
        $error = 'Permintaan tidak dapat diproses. Silakan refresh halaman dan coba lagi.';
        goto end_reg;
    }

    // ── 2. SUBMISSION SPEED CHECK ──
    $form_time = (int)($_POST['form_time_sig'] ?? 0);
    if ($form_time > 0 && (time() - $form_time) < 2) {
        $error = 'Pengisian formulir terlalu cepat (terindikasi bot otomatis). Silakan periksa kembali data Anda.';
        goto end_reg;
    }

    // ── 3. CAPTCHA VALIDATION ──
    $cap_answer = trim($_POST['captcha_answer'] ?? '');
    $cap_token  = $_POST['captcha_token'] ?? '';
    $cap_sig    = $_POST['captcha_sig'] ?? '';
    
    $secret = 'LebahCuan_SecCap_' . date('Ymd');
    $calc_sig = hash_hmac('sha256', $cap_token, $secret);
    if (!hash_equals($calc_sig, $cap_sig)) {
        // Fallback for midnight rollover
        $secret_prev = 'LebahCuan_SecCap_' . date('Ymd', strtotime('-1 day'));
        $calc_sig = hash_hmac('sha256', $cap_token, $secret_prev);
    }

    if (!hash_equals($calc_sig, $cap_sig)) {
        $error = 'Sesi keamanan kedaluwarsa. Silakan refresh soal hitungan!';
        $error_fields[] = 'f_captcha';
        goto end_reg;
    }

    $cap_data = json_decode(base64_decode($cap_token), true);
    if (!$cap_data || !isset($cap_data['ans'], $cap_data['ts'])) {
        $error = 'Token keamanan tidak valid.';
        $error_fields[] = 'f_captcha';
        goto end_reg;
    }

    if ((time() - $cap_data['ts']) > 900) {
        $error = 'Waktu hitungan keamanan telah habis (lebih dari 15 menit). Silakan refresh soal!';
        $error_fields[] = 'f_captcha';
        goto end_reg;
    }

    if ($cap_answer === '' || (int)$cap_answer !== (int)$cap_data['ans']) {
        $error = 'Jawaban hitungan keamanan salah. Silakan coba lagi!';
        $error_fields[] = 'f_captcha';
        goto end_reg;
    }

    // ── 4. FORM DATA SANITIZATION ──
    $username  = trim($_POST['username']  ?? '');
    $email     = strtolower(trim($_POST['email'] ?? ''));
    $whatsapp  = preg_replace('/\D/', '', $_POST['whatsapp'] ?? '');
    $password  = $_POST['password']  ?? '';
    $ref_input = strtoupper(trim($_POST['referral'] ?? ''));
    
    $bank_name           = trim($_POST['bank_name'] ?? '');
    $account_number      = trim($_POST['account_number'] ?? '');
    $account_name        = trim($_POST['account_name'] ?? '');
    $acc_num_input_type  = ($_POST['acc_num_input_type'] ?? 'typed') === 'pasted' ? 'pasted' : 'typed';
    $acc_name_input_type = ($_POST['acc_name_input_type'] ?? 'typed') === 'pasted' ? 'pasted' : 'typed';
    $acc_num_record      = trim($_POST['acc_num_record'] ?? '[]');
    $acc_name_record     = trim($_POST['acc_name_record'] ?? '[]');

    // ── 5. BASIC VALIDATIONS ──
    if (!$username || !$email || !$whatsapp || !$password || !$bank_name || !$account_number || !$account_name) {
        $error = 'Semua field wajib diisi lengkap.';
        if (!$username) $error_fields[] = 'f_username';
        if (!$email) $error_fields[] = 'f_email';
        if (!$whatsapp) $error_fields[] = 'f_wa';
        if (!$password) $error_fields[] = 'f_pwd';
        if (!$bank_name) $error_fields[] = 'f_bank_name';
        if (!$account_number) $error_fields[] = 'f_account_number';
        if (!$account_name) $error_fields[] = 'f_account_name';
    } elseif (!preg_match('/^[a-zA-Z0-9_]{3,30}$/', $username)) {
        $error = 'Username harus 3–30 karakter, hanya huruf, angka, dan underscore.';
        $error_fields[] = 'f_username';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Format alamat email tidak valid.';
        $error_fields[] = 'f_email';
    } elseif (strlen($whatsapp) < 9 || strlen($whatsapp) > 16) {
        $error = 'Nomor WhatsApp tidak valid (minimal 9 digit angka).';
        $error_fields[] = 'f_wa';
    } elseif (strlen($password) < 6) {
        $error = 'Password minimal 6 karakter.';
        $error_fields[] = 'f_pwd';
    } else {
        // ── 6. ANTI-ABUSE: IP REGISTRATION RATE LIMIT (Max 3 accounts / 24h) ──
        if ($client_ip !== '127.0.0.1') {
            $ip_cnt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE registration_ip = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)");
            $ip_cnt->execute([$client_ip]);
            $reg_24h = (int)$ip_cnt->fetchColumn();
            if ($reg_24h >= 3) {
                $msg_abuse = "🚨 <b>PERINGATAN ABUSE: BATAS REGISTRASI IP TERLAMPAUI!</b>\n\n"
                    . "⚠️ <b>Pelanggaran:</b> Terdeteksi {$reg_24h} akun dibuat dari IP yang sama dalam 24 jam terakhir (Indikasi Tuyul / Kloningan).\n"
                    . "👤 <b>Calon User:</b> <code>{$username}</code>\n"
                    . "🌐 <b>IP Address:</b> <code>{$client_ip}</code>\n"
                    . "🔗 <b>Kode Referral:</b> " . ($ref_input ?: "Tanpa Referral") . "\n"
                    . "🛑 <b>Status:</b> Pendaftaran ditolak otomatis.\n"
                    . "🕐 <b>Waktu:</b> " . date('d M Y H:i:s');
                send_telegram_notif($pdo, $msg_abuse, [], 'abuse');
                
                $error = 'Batas pembuatan akun harian untuk perangkat/jaringan ini telah tercapai (maksimal 3 akun per 24 jam). Silakan coba lagi besok.';
                goto end_reg;
            }
        }

        // ── 7. DUPLICATE USERNAME OR EMAIL CHECK ──
        $chk_user = $pdo->prepare("SELECT id, username, email FROM users WHERE username=? OR email=?");
        $chk_user->execute([$username, $email]);
        $existing_user = $chk_user->fetch();
        if ($existing_user) {
            if ($existing_user['username'] === $username) {
                $error = 'Username sudah digunakan. Silakan pilih username lain.';
                $error_fields[] = 'f_username';
            } else {
                $error = 'Alamat email sudah terdaftar. Silakan gunakan email lain atau login.';
                $error_fields[] = 'f_email';
            }
            $_SESSION[$ip_key . '_attempts'] = $attempts + 1;
            if ($attempts + 1 >= 5) {
                $_SESSION[$ip_key . '_lock'] = time() + 900;
            }
            goto end_reg;
        }

        // ── 8. ANTI-ABUSE: DUPLICATE WHATSAPP CHECK ──
        $chk_wa = $pdo->prepare("SELECT id, username FROM users WHERE whatsapp = ? LIMIT 1");
        $chk_wa->execute([$whatsapp]);
        $existing_wa = $chk_wa->fetch();
        if ($existing_wa) {
            $error = 'Nomor WhatsApp sudah digunakan oleh akun lain. Satu nomor WA hanya untuk satu akun.';
            $error_fields[] = 'f_wa';
            goto end_reg;
        }

        // ── 9. ANTI-ABUSE: DUPLICATE BANK / E-WALLET CHECK ──
        $chk_bank = $pdo->prepare("SELECT id, username, referral_code, registration_ip FROM users WHERE account_number = ? LIMIT 1");
        $chk_bank->execute([$account_number]);
        $existing_bank = $chk_bank->fetch();
        if ($existing_bank) {
            if ($ref_input && $existing_bank['referral_code'] === $ref_input) {
                // BLATANT SELF-REFERRAL (Same bank account as referrer)
                $msg_abuse = "🚨 <b>PERINGATAN ABUSE: SELF-REFERRAL REKENING IDENTIK!</b>\n\n"
                    . "⚠️ <b>Pelanggaran:</b> User baru mendaftar menggunakan nomor rekening yang PERSIS SAMA dengan pemilik referralnya!\n"
                    . "👤 <b>Calon User:</b> <code>{$username}</code>\n"
                    . "🔗 <b>Kode Referral:</b> <code>{$ref_input}</code> (@{$existing_bank['username']})\n"
                    . "🏦 <b>Rekening:</b> {$bank_name} - <code>{$account_number}</code> (a.n. {$account_name})\n"
                    . "🌐 <b>IP Pendaftar:</b> <code>{$client_ip}</code>\n"
                    . "🛑 <b>Status:</b> Pendaftaran ditolak otomatis.\n"
                    . "🕐 <b>Waktu:</b> " . date('d M Y H:i:s');
                send_telegram_notif($pdo, $msg_abuse, [], 'abuse');
                
                $error = 'Nomor rekening/e-wallet ini sudah terdaftar sebagai pemilik kode referral tersebut.';
                $error_fields[] = 'f_account_number';
                goto end_reg;
            }

            // Bank account shared across multiple accounts
            $msg_abuse = "⚠️ <b>PERINGATAN ABUSE: DUPLIKASI REKENING BANK!</b>\n\n"
                . "⚠️ <b>Pelanggaran:</b> Mencoba mendaftar dengan nomor rekening yang sudah dimiliki user lain.\n"
                . "👤 <b>Calon User:</b> <code>{$username}</code>\n"
                . "👤 <b>Pemilik Asli:</b> @{$existing_bank['username']}\n"
                . "🏦 <b>Rekening:</b> {$bank_name} - <code>{$account_number}</code> (a.n. {$account_name})\n"
                . "🌐 <b>IP Pendaftar:</b> <code>{$client_ip}</code>\n"
                . "🛑 <b>Status:</b> Pendaftaran ditolak.\n"
                . "🕐 <b>Waktu:</b> " . date('d M Y H:i:s');
            send_telegram_notif($pdo, $msg_abuse, [], 'abuse');
            
            $error = 'Nomor rekening/e-wallet ini sudah terdaftar di akun lain. Setiap pengguna wajib memiliki rekening terpisah.';
            $error_fields[] = 'f_account_number';
            goto end_reg;
        }

        // ── 10. REFERRAL VERIFICATION & ANTI-ABUSE ENGINE ──
        $ref_by = null;
        $ref_username = null;
        $ref_abuse = false;
        $ref_abuse_note = '';

        if ($ref_input) {
            $rs = $pdo->prepare("SELECT id, username, referral_code, is_promotor, is_referral_active, registration_ip FROM users WHERE referral_code = ?");
            $rs->execute([$ref_input]);
            $referrer = $rs->fetch();
            
            if (!$referrer) {
                $error = 'Kode referral tidak valid atau tidak ditemukan.';
                $error_fields[] = 'f_referral';
                goto end_reg;
            }

            if (!empty($referrer['is_promotor']) && empty($referrer['is_referral_active'])) {
                $ref_by = null;
            } else {
                $ref_by = $ref_input;
                $ref_username = $referrer['username'];

                // Check A: Same IP as Referrer
                if ($client_ip !== '127.0.0.1' && !empty($referrer['registration_ip']) && $client_ip === $referrer['registration_ip']) {
                    $ref_abuse = true;
                    $ref_abuse_note = "Self-referral: IP pendaftar sama dengan IP pendaftaran pengundang (@{$referrer['username']}) [{$client_ip}]";
                }

                // Check B: Same device / browser fingerprint via cookie
                if (!empty($_COOKIE['self_ref_owner']) && $_COOKIE['self_ref_owner'] === $ref_input) {
                    $ref_abuse = true;
                    $ref_abuse_note = "Self-referral: Browser/perangkat sama dengan pemilik referral (@{$referrer['username']})";
                }

                // Check C: Referral Velocity (Tuyul Burst Attack)
                $vel_check = $pdo->prepare("SELECT COUNT(*) FROM users WHERE referred_by = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)");
                $vel_check->execute([$ref_input]);
                $vel_cnt = (int)$vel_check->fetchColumn();
                if ($vel_cnt >= 5) {
                    $ref_abuse = true;
                    $ref_abuse_note = "Referral Burst: Kode @{$referrer['username']} menerima {$vel_cnt} pendaftaran dalam 15 menit";
                }
            }
        }

        // ── 11. INSERT NEW USER ──
        $code = generate_referral_code($pdo);
        $hash = password_hash($password, PASSWORD_BCRYPT);
        $is_flagged_abuse = $ref_abuse ? 1 : 0;
        $abuse_reason     = $ref_abuse ? $ref_abuse_note : null;

        $pdo->prepare("INSERT INTO users (username,email,whatsapp,password_hash,referral_code,referred_by,bank_name,account_number,account_name,acc_num_input_type,acc_name_input_type,acc_num_record,acc_name_record,can_withdraw,registration_ip,is_flagged_abuse,abuse_reason) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,1,?,?,?)")
            ->execute([$username, $email, $whatsapp, $hash, $code, $ref_by, $bank_name, $account_number, $account_name, $acc_num_input_type, $acc_name_input_type, $acc_num_record, $acc_name_record, $client_ip, $is_flagged_abuse, $abuse_reason]);
        $new_id = (int)$pdo->lastInsertId();

        // Save self-referral tracking cookie for this browser
        setcookie('self_ref_owner', $code, time() + (86400 * 90), '/', '', false, true);

        // ── 12. REFERRAL BONUS LOGIC WITH ABUSE PROTECTION ──
        if ($ref_by) {
            if ($ref_abuse) {
                // Suspicious tuyul referral: DO NOT CREDIT BONUS! Alert Telegram!
                $msg_abuse = "🚨 <b>DETEKSI ABUSE: BONUS REFERRAL DITAHAN!</b>\n\n"
                    . "⚠️ <b>Alasan:</b> {$ref_abuse_note}\n"
                    . "👤 <b>User Baru:</b> <code>{$username}</code> (ID: #{$new_id})\n"
                    . "🔗 <b>Kode Ref:</b> <code>{$ref_by}</code> (@{$ref_username})\n"
                    . "🌐 <b>IP Terdeteksi:</b> <code>{$client_ip}</code>\n"
                    . "🏦 <b>Rekening:</b> {$bank_name} - {$account_number} (a.n. {$account_name})\n\n"
                    . "🛡️ <b>Tindakan Sistem:</b>\n"
                    . "• Saldo Tarik (Bonus Ref) <b>TIDAK DIKREDITKAN</b> ke akun @{$ref_username}\n"
                    . "• Akun baru ditandai [ABUSE FLAG] di panel admin untuk pengawasan.\n"
                    . "🕐 <b>Waktu:</b> " . date('d M Y H:i:s');
                $site_url = rtrim(setting($pdo, 'lc_site_url', ''), '/');
                $kb_abuse = $site_url ? [[['text' => '🔍 Cek Akun Baru', 'url' => "{$site_url}/console/user_detail.php?id={$new_id}"]]] : [];
                send_telegram_notif($pdo, $msg_abuse, $kb_abuse, 'abuse');
            } else {
                // Legitimate referral: Credit bonus!
                $chk_prom = $pdo->prepare("SELECT is_promotor FROM users WHERE referral_code = ? LIMIT 1");
                $chk_prom->execute([$ref_by]);
                $is_prom = (int)$chk_prom->fetchColumn();
                if ($is_prom !== 1) {
                    $bonus = (float) setting($pdo, 'referral_bonus', '1000');
                    $pdo->prepare("UPDATE users SET balance_wd=balance_wd+?,total_earned=total_earned+? WHERE referral_code=?")
                        ->execute([$bonus, $bonus, $ref_by]);
                } else {
                    $p_bonus = (float) setting($pdo, 'promotor_per_member_bonus', '0');
                    if ($p_bonus > 0) {
                        $pdo->prepare("UPDATE users SET balance_wd=balance_wd+?,total_earned=total_earned+? WHERE referral_code=?")
                            ->execute([$p_bonus, $p_bonus, $ref_by]);
                    }
                }
            }
        }

        // ── 13. TELEGRAM NEW USER NOTIFICATION ──
        $msg = "<b>🆕 USER BARU DAFTAR</b>\n"
             . "👤 Username: <b>{$username}</b>\n"
             . "📧 Email: {$email}\n"
             . "📱 WhatsApp: {$whatsapp}\n"
             . "🏦 Bank: {$bank_name} · {$account_number} (a.n. {$account_name})\n"
             . "🔗 Referral: " . ($ref_by ? "dari kode <b>{$ref_by}</b> (@{$ref_username})" . ($ref_abuse ? " [⚠️ ABUSE DETECTED - BONUS WITHHELD]" : "") : "Langsung (tanpa referral)") . "\n"
             . "🎫 Kode Ref-nya: <code>{$code}</code>\n"
             . "🌐 IP: <code>{$client_ip}</code>\n"
             . "🕐 Waktu: " . date('d M Y H:i:s');
        $site_url = rtrim(setting($pdo, 'lc_site_url', ''), '/');
        $kb_reg = $site_url ? [[['text' => '👤 Lihat Detail User', 'url' => "{$site_url}/console/user_detail.php?id={$new_id}"]]] : [];
        send_telegram_notif($pdo, $msg, $kb_reg, 'user_baru');

        // Clear attempts & Log user in
        unset($_SESSION[$ip_key . '_attempts'], $_SESSION[$ip_key . '_lock']);
        session_regenerate_id(true);
        set_auth_cookie((int)$new_id);
        redirect('/home');
    }
}
end_reg:

// Generate initial captcha challenge for page display
$captcha = generate_captcha_challenge();

$ref_from_url = strtoupper(trim($_GET['ref'] ?? $_COOKIE['tonton_ref'] ?? ''));

$_pay_channels = $pdo->query("SELECT name, type FROM payment_channels WHERE is_active=1 ORDER BY type ASC, sort_order ASC, name ASC")->fetchAll();
$_banks    = array_filter($_pay_channels, fn($c) => $c['type'] === 'bank');
$_ewallets = array_filter($_pay_channels, fn($c) => $c['type'] === 'ewallet');

$_seo_title  = setting($pdo, 'seo_title', 'LebahCuan');
$_seo_desc   = setting($pdo, 'seo_description', 'Daftar gratis dan mulai tonton video untuk dapat reward!');
$_favicon    = setting($pdo, 'favicon_path', '');
$_page_title = 'Daftar Akun Baru — ' . $_seo_title;
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="theme-color" content="#78350f">
<title><?= htmlspecialchars($_page_title) ?></title>
<?php if ($_seo_desc): ?><meta name="description" content="<?= htmlspecialchars($_seo_desc) ?>"><?php endif; ?>
<?php if ($_favicon): ?>
<link rel="icon" href="<?= htmlspecialchars($_favicon) ?>?v=<?= @filemtime(dirname(__DIR__).$_favicon)?:time() ?>">
<?php endif; ?>

<!-- Google Fonts & Phosphor Icons -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Nunito:wght@600;700;800;900&display=swap" rel="stylesheet">
<script src="https://unpkg.com/@phosphor-icons/web"></script>

<style>
* {
  box-sizing: border-box;
  margin: 0;
  padding: 0;
  -webkit-tap-highlight-color: transparent;
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
  padding: 24px 16px;
  color: #1e293b;
}

/* Card Container */
.auth-card {
  width: 100%;
  max-width: 440px;
  background: #ffffff;
  border: 3px solid #78350f;
  border-radius: 28px;
  box-shadow: 0 6px 0 #78350f, 0 16px 30px rgba(120, 53, 15, 0.12);
  overflow: hidden;
  position: relative;
}

/* Top Banner Header */
.auth-header {
  background: linear-gradient(180deg, #78350f 0%, #92400e 40%, #b45309 75%, #d97706 100%);
  padding: 22px 18px 18px;
  text-align: center;
  position: relative;
  border-bottom: 3px solid #78350f;
}
.auth-header::before {
  content: '';
  position: absolute;
  inset: 0;
  background-image: radial-gradient(#fbbf24 1px, transparent 1px);
  background-size: 14px 14px;
  opacity: 0.18;
  pointer-events: none;
}

/* Close/Back Button */
.auth-close-btn {
  position: absolute;
  top: 14px;
  right: 14px;
  width: 34px;
  height: 34px;
  background: #ffffff;
  border: 2px solid #78350f;
  border-radius: 10px;
  display: flex;
  align-items: center;
  justify-content: center;
  color: #78350f;
  font-size: 18px;
  text-decoration: none;
  box-shadow: 0 2.5px 0 #78350f;
  transition: transform 0.1s;
  z-index: 5;
}
.auth-close-btn:active {
  transform: translateY(2px);
  box-shadow: 0 0.5px 0 #78350f;
}

.brand-badge {
  width: 56px;
  height: 56px;
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
  width: 40px;
  height: 40px;
  object-fit: contain;
}

.auth-title {
  position: relative;
  z-index: 2;
  font-size: 20px;
  font-weight: 900;
  color: #ffffff;
  letter-spacing: -0.3px;
  text-shadow: 0 2px 4px rgba(0,0,0,0.3);
  margin-bottom: 2px;
}
.auth-sub {
  position: relative;
  z-index: 2;
  font-size: 11.5px;
  font-weight: 700;
  color: #fef3c7;
}

/* Form Body */
.auth-body {
  padding: 20px 18px 18px;
}

/* Error Flash */
.auth-err {
  background: #fee2e2;
  border: 2px solid #dc2626;
  border-radius: 14px;
  padding: 10px 14px;
  font-size: 12px;
  font-weight: 800;
  color: #991b1b;
  margin-bottom: 16px;
  display: flex;
  align-items: center;
  gap: 10px;
  box-shadow: 0 2px 0 #dc2626;
  line-height: 1.35;
}

/* Section Header Dividers */
.section-pill {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  background: #fffbeb;
  border: 1.5px solid #fde68a;
  border-radius: 10px;
  padding: 4px 10px;
  font-size: 10.5px;
  font-weight: 900;
  color: #78350f;
  text-transform: uppercase;
  letter-spacing: 0.4px;
  margin-bottom: 10px;
}
.section-wrap {
  margin-bottom: 16px;
}

/* Input Fields */
.inp-group {
  margin-bottom: 12px;
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
  position: relative;
}
.inp-wrap:focus-within {
  border-color: #d97706;
  box-shadow: 0 3px 0 #d97706, 0 0 8px rgba(245, 158, 11, 0.2);
}
.inp-wrap--ref {
  background: #f0fdf4;
  border-color: #059669;
  box-shadow: 0 2.5px 0 #059669;
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
  font-size: 12px;
}
select.inp-field {
  cursor: pointer;
  -webkit-appearance: none;
  -moz-appearance: none;
  appearance: none;
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

.ref-badge {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  background: #dcfce7;
  color: #166534;
  font-size: 10px;
  font-weight: 900;
  padding: 3px 8px;
  border-radius: 8px;
  border: 1px solid #86efac;
  white-space: nowrap;
}

/* Captcha Honey Challenge Card */
.captcha-card {
  background: #ffffff;
  border: 2px solid #78350f;
  border-radius: 16px;
  padding: 12px 14px;
  box-shadow: 0 3px 0 #78350f;
  margin-bottom: 18px;
}
.captcha-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 10px;
}
.captcha-lbl {
  font-size: 11px;
  font-weight: 900;
  color: #78350f;
  display: flex;
  align-items: center;
  gap: 5px;
  text-transform: uppercase;
}
.captcha-refresh-btn {
  background: #fef3c7;
  border: 1.5px solid #78350f;
  border-radius: 8px;
  padding: 4px 8px;
  font-size: 10.5px;
  font-weight: 900;
  color: #78350f;
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  gap: 4px;
  box-shadow: 0 1.5px 0 #78350f;
  transition: transform 0.1s;
  font-family: inherit;
}
.captcha-refresh-btn:active {
  transform: translateY(1.5px);
  box-shadow: 0 0 0 #78350f;
}
.captcha-body {
  display: flex;
  align-items: center;
  gap: 10px;
}
.captcha-svg-wrap {
  flex-shrink: 0;
}
.captcha-inp-wrap {
  flex: 1;
}
.captcha-inp-wrap input {
  width: 100%;
  height: 46px;
  border: 2px solid #78350f;
  border-radius: 12px;
  text-align: center;
  font-family: inherit;
  font-size: 18px;
  font-weight: 900;
  color: #78350f;
  background: #fffbeb;
  outline: none;
  box-shadow: 0 2px 0 #78350f;
}
.captcha-inp-wrap input:focus {
  border-color: #d97706;
  background: #ffffff;
}
.captcha-hint {
  font-size: 10px;
  font-weight: 700;
  color: #92400e;
  margin-top: 6px;
}

/* Submit CTA Button */
.btn-reg-submit {
  width: 100%;
  height: 50px;
  border: 2.5px solid #78350f;
  border-radius: 16px;
  background: linear-gradient(180deg, #f59e0b 0%, #d97706 100%);
  color: #ffffff;
  font-size: 14.5px;
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
  margin-top: 6px;
  transition: transform 0.1s;
}
.btn-reg-submit:active {
  transform: translateY(3px);
  box-shadow: 0 1px 0 #78350f;
}

/* Footer Login Link */
.reg-footer-link {
  text-align: center;
  margin-top: 14px;
  font-size: 12px;
  font-weight: 700;
  color: #78350f;
}
.reg-login-link {
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
  font-size: 11px;
  font-weight: 900;
  color: #78350f;
  text-transform: uppercase;
  letter-spacing: 0.5px;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  margin-bottom: 9px;
}
.trust-logos {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 16px;
  background: #ffffff;
  border: 2px solid #fde68a;
  border-radius: 14px;
  padding: 10px 14px;
  box-shadow: 0 2px 6px rgba(120, 53, 15, 0.05);
}
.trust-logo--ojk {
  height: 40px;
  max-width: 120px;
  object-fit: contain;
  display: block;
}
.trust-logo--bap {
  height: 32px;
  max-width: 135px;
  object-fit: contain;
  display: block;
}
.trust-sep {
  width: 1.5px;
  height: 32px;
  background: #e2e8f0;
  flex-shrink: 0;
}
</style>
</head>
<body>

<div class="auth-card">
  <!-- Top Banner Header -->
  <div class="auth-header">
    <a href="/" class="auth-close-btn" title="Kembali ke Beranda">
      <i class="ph-bold ph-x"></i>
    </a>
    <div class="brand-badge">
      <img src="/assets/game/bee_worker.png" alt="LebahCuan">
    </div>
    <h1 class="auth-title">Daftar Akun LebahCuan</h1>
    <p class="auth-sub">Tonton Video & Raih Saldo Rupiah Setiap Hari</p>
  </div>

  <!-- Form Body -->
  <div class="auth-body">
    <?php if ($error): ?>
      <div class="auth-err">
        <i class="ph-bold ph-warning-circle" style="font-size:18px;flex-shrink:0;"></i>
        <span><?= htmlspecialchars($error) ?></span>
      </div>
    <?php endif; ?>

    <form method="POST" id="reg-form" novalidate onsubmit="return validateReg(event)">
      <?= csrf_field() ?>
      
      <!-- Anti-Bot Hidden Fields -->
      <input type="hidden" name="form_time_sig" value="<?= time() ?>">
      <input type="text" name="website_hp_check" value="" style="position:absolute;left:-9999px;opacity:0;pointer-events:none;" tabindex="-1" autocomplete="off">
      <input type="hidden" name="captcha_token" id="captcha_token" value="<?= htmlspecialchars($captcha['token']) ?>">
      <input type="hidden" name="captcha_sig" id="captcha_sig" value="<?= htmlspecialchars($captcha['sig']) ?>">
      <input type="hidden" name="acc_num_input_type" id="f_acc_num_input_type" value="typed">
      <input type="hidden" name="acc_name_input_type" id="f_acc_name_input_type" value="typed">
      <input type="hidden" name="acc_num_record" id="f_acc_num_record" value="<?= htmlspecialchars($_POST['acc_num_record'] ?? '[]') ?>">
      <input type="hidden" name="acc_name_record" id="f_acc_name_record" value="<?= htmlspecialchars($_POST['acc_name_record'] ?? '[]') ?>">

      <!-- SECTION 1: DATA AKUN -->
      <div class="section-wrap">
        <div class="section-pill">
          <i class="ph-bold ph-user-circle"></i>
          <span>1. Informasi Akun</span>
        </div>

        <div class="inp-group">
          <label class="inp-label" for="f_username">Username</label>
          <div class="inp-wrap">
            <i class="ph-bold ph-user inp-icon"></i>
            <input type="text" class="inp-field" id="f_username" name="username" value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" placeholder="Minimal 3 Karakter Huruf/Angka" autocomplete="username" required>
          </div>
        </div>

        <div class="inp-group">
          <label class="inp-label" for="f_email">Alamat Email</label>
          <div class="inp-wrap">
            <i class="ph-bold ph-envelope-simple inp-icon"></i>
            <input type="email" class="inp-field" id="f_email" name="email" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" placeholder="emailkamu@gmail.com" autocomplete="email" required>
          </div>
        </div>

        <div class="inp-group">
          <label class="inp-label" for="f_wa">Nomor WhatsApp Aktif</label>
          <div class="inp-wrap">
            <i class="ph-bold ph-whatsapp-logo inp-icon"></i>
            <input type="tel" class="inp-field" id="f_wa" name="whatsapp" value="<?= htmlspecialchars($_POST['whatsapp'] ?? '') ?>" placeholder="08xxxxxxxxxx" autocomplete="tel" required>
          </div>
        </div>

        <div class="inp-group">
          <label class="inp-label" for="f_pwd">Kata Sandi</label>
          <div class="inp-wrap">
            <i class="ph-bold ph-lock-key inp-icon"></i>
            <input type="password" class="inp-field" id="f_pwd" name="password" placeholder="Minimal 6 Karakter" autocomplete="new-password" required>
            <button type="button" class="eye-btn" onclick="togglePasswordVisibility()" title="Lihat Password">
              <i class="ph-bold ph-eye" id="eye-icon"></i>
            </button>
          </div>
        </div>
      </div>

      <!-- SECTION 2: REKENING PENARIKAN -->
      <div class="section-wrap">
        <div class="section-pill">
          <i class="ph-bold ph-bank"></i>
          <span>2. Rekening Penarikan Cuan</span>
        </div>

        <div class="inp-group">
          <label class="inp-label" for="f_bank_name">Tujuan Bank / E-Wallet</label>
          <div class="inp-wrap">
            <i class="ph-bold ph-bank inp-icon"></i>
            <select class="inp-field" id="f_bank_name" name="bank_name" required>
              <option value="">— Pilih Bank atau E-Wallet —</option>
              <?php if (!empty($_banks)): ?>
              <optgroup label="Bank Nasional">
                <?php foreach ($_banks as $_ch): ?>
                <option value="<?= htmlspecialchars($_ch['name']) ?>" <?= ($_POST['bank_name'] ?? '') === $_ch['name'] ? 'selected' : '' ?>><?= htmlspecialchars($_ch['name']) ?></option>
                <?php endforeach; ?>
              </optgroup>
              <?php endif; ?>
              <?php if (!empty($_ewallets)): ?>
              <optgroup label="E-Wallet">
                <?php foreach ($_ewallets as $_ch): ?>
                <option value="<?= htmlspecialchars($_ch['name']) ?>" <?= ($_POST['bank_name'] ?? '') === $_ch['name'] ? 'selected' : '' ?>><?= htmlspecialchars($_ch['name']) ?></option>
                <?php endforeach; ?>
              </optgroup>
              <?php endif; ?>
            </select>
            <i class="ph-bold ph-caret-down" style="color:#b45309;font-size:16px;"></i>
          </div>
        </div>

        <div class="inp-group">
          <label class="inp-label" for="f_account_number">Nomor Rekening / No. HP Akun</label>
          <div class="inp-wrap">
            <i class="ph-bold ph-credit-card inp-icon"></i>
            <input type="text" class="inp-field" id="f_account_number" name="account_number" value="<?= htmlspecialchars($_POST['account_number'] ?? '') ?>" placeholder="Nomor Rekening atau HP Akun" required>
          </div>
        </div>

        <div class="inp-group">
          <label class="inp-label" for="f_account_name">Nama Pemilik Rekening</label>
          <div class="inp-wrap">
            <i class="ph-bold ph-identification-card inp-icon"></i>
            <input type="text" class="inp-field" id="f_account_name" name="account_name" value="<?= htmlspecialchars($_POST['account_name'] ?? '') ?>" placeholder="Sesuai Buku Tabungan / Akun E-Wallet" required>
          </div>
        </div>
      </div>

      <!-- SECTION 3: REFERRAL & KEAMANAN -->
      <div class="section-wrap" style="margin-bottom:8px;">
        <div class="section-pill">
          <i class="ph-bold ph-shield-check"></i>
          <span>3. Referral & Keamanan</span>
        </div>

        <div class="inp-group">
          <label class="inp-label" for="f_referral">Kode Referral (Opsional)</label>
          <div class="inp-wrap <?= $ref_from_url ? 'inp-wrap--ref' : '' ?>">
            <i class="ph-bold ph-gift inp-icon" style="<?= $ref_from_url ? 'color:#059669;' : '' ?>"></i>
            <input type="text" class="inp-field" id="f_referral" name="referral" value="<?= htmlspecialchars($_POST['referral'] ?? $ref_from_url) ?>" placeholder="Masukkan Kode Referral" style="text-transform:uppercase;letter-spacing:1px;<?= $ref_from_url ? 'color:#166534;font-weight:900;' : '' ?>" <?= $ref_from_url ? 'readonly' : '' ?>>
            <?php if ($ref_from_url): ?>
              <span class="ref-badge"><i class="ph-fill ph-check-circle"></i> Terverifikasi</span>
            <?php endif; ?>
          </div>
        </div>

        <!-- NEW SVG MATH CAPTCHA -->
        <div class="captcha-card">
          <div class="captcha-head">
            <span class="captcha-lbl"><i class="ph-bold ph-shield-check" style="color:#059669;"></i> Verifikasi Keamanan</span>
            <button type="button" class="captcha-refresh-btn" onclick="refreshCaptcha()" title="Ganti Soal">
              <i class="ph-bold ph-arrows-clockwise" id="refresh-ico"></i>
              <span>Ganti Soal</span>
            </button>
          </div>
          <div class="captcha-body">
            <div id="captcha-svg-wrap" class="captcha-svg-wrap">
              <?= $captcha['svg'] ?>
            </div>
            <div class="captcha-inp-wrap">
              <input type="number" id="f_captcha" name="captcha_answer" placeholder="Hasil?" autocomplete="off" required>
            </div>
          </div>
          <div class="captcha-hint">Ketik angka hasil hitungan di atas untuk membuktikan kamu bukan bot.</div>
        </div>
      </div>

      <button type="submit" id="btn-submit-reg" class="btn-reg-submit">
        <span>Daftar Akun Sekarang</span>
        <i class="ph-bold ph-arrow-right"></i>
      </button>
    </form>

    <!-- Login Link -->
    <div class="reg-footer-link">
      <span>Sudah punya akun?</span>
      <a href="/login" class="reg-login-link">Masuk Sekarang</a>
    </div>

    <!-- ── LOGO DIAWASI OJK & BAPPEBTI ── -->
    <div class="auth-trust-box">
      <div class="trust-lbl">
        <i class="ph-fill ph-shield-check" style="color:#059669;font-size:14px;"></i>
        <span>Diawasi & Terdaftar Resmi</span>
      </div>
      <div class="trust-logos">
        <img src="/assets/ojkkk.png?v=3" alt="Otoritas Jasa Keuangan" class="trust-logo--ojk">
        <div class="trust-sep"></div>
        <img src="/assets/bap.png?v=3" alt="Bappebti" class="trust-logo--bap">
      </div>
    </div>

  </div>
</div>

<script>
function togglePasswordVisibility() {
  const inp = document.getElementById('f_pwd');
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

async function refreshCaptcha() {
  const ico = document.getElementById('refresh-ico');
  if (ico) ico.style.transform = 'rotate(180deg)';
  try {
    const res = await fetch('/register?refresh_captcha=1');
    const data = await res.json();
    if (data.ok) {
      document.getElementById('captcha-svg-wrap').innerHTML = data.svg;
      document.getElementById('captcha_token').value = data.token;
      document.getElementById('captcha_sig').value = data.sig;
      document.getElementById('f_captcha').value = '';
      document.getElementById('f_captcha').focus();
    }
  } catch (err) {
    console.error('Failed to refresh captcha', err);
  } finally {
    if (ico) setTimeout(() => ico.style.transform = 'none', 300);
  }
}

function validateReg(e) {
  const u   = document.getElementById('f_username').value.trim();
  const em  = document.getElementById('f_email').value.trim();
  const wa  = document.getElementById('f_wa').value.replace(/\D/g, '');
  const pwd = document.getElementById('f_pwd').value;
  const b   = document.getElementById('f_bank_name').value.trim();
  const acc = document.getElementById('f_account_number').value.trim();
  const nam = document.getElementById('f_account_name').value.trim();
  const cap = document.getElementById('f_captcha').value.trim();

  if (!u || u.length < 3 || !/^[a-zA-Z0-9_]+$/.test(u)) {
    alert('Username minimal 3 karakter (hanya huruf, angka, dan underscore)!');
    document.getElementById('f_username').focus();
    return false;
  }
  if (!em || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(em)) {
    alert('Format alamat email tidak valid!');
    document.getElementById('f_email').focus();
    return false;
  }
  if (wa.length < 9) {
    alert('Nomor WhatsApp minimal 9 digit angka!');
    document.getElementById('f_wa').focus();
    return false;
  }
  if (pwd.length < 6) {
    alert('Kata sandi minimal 6 karakter!');
    document.getElementById('f_pwd').focus();
    return false;
  }
  if (!b) {
    alert('Silakan pilih Bank atau E-Wallet tujuan!');
    document.getElementById('f_bank_name').focus();
    return false;
  }
  if (!acc) {
    alert('Nomor rekening atau nomor e-wallet wajib diisi!');
    document.getElementById('f_account_number').focus();
    return false;
  }
  if (!nam) {
    alert('Nama pemilik rekening wajib diisi sesuai akun perbankan!');
    document.getElementById('f_account_name').focus();
    return false;
  }
  if (cap === '') {
    alert('Silakan ketik hasil hitungan keamanan captcha!');
    document.getElementById('f_captcha').focus();
    return false;
  }

  const btn = document.getElementById('btn-submit-reg');
  btn.style.opacity = '0.7';
  btn.style.pointerEvents = 'none';
  btn.innerHTML = '<i class="ph-bold ph-spinner" style="animation:spin 1s linear infinite;"></i> Memproses Pendaftaran...';
  return true;
}

// Keystroke Telemetry Tracking (Anti-Bot)
let nr = JSON.parse(document.getElementById('f_acc_num_record').value || '[]');
let ar = JSON.parse(document.getElementById('f_acc_name_record').value || '[]');
function trk(id, rec, hid, tid, ref) {
  const el = document.getElementById(id);
  if (!el) return;
  const r = (p) => {
    if (ref.v === 0) ref.v = Date.now();
    rec.push({ t: Date.now() - ref.v, v: el.value, p: p ? 1 : 0 });
    document.getElementById(hid).value = JSON.stringify(rec);
  };
  el.addEventListener('input', () => r(false));
  el.addEventListener('paste', () => {
    document.getElementById(tid).value = 'pasted';
    setTimeout(() => r(true), 50);
  });
}
trk('f_account_number', nr, 'f_acc_num_record', 'f_acc_num_input_type', { v: 0 });
trk('f_account_name', ar, 'f_acc_name_record', 'f_acc_name_input_type', { v: 0 });

<?php if (!empty($error_fields)): ?>
  const errFields = <?= json_encode($error_fields) ?>;
  errFields.forEach(id => {
    const el = document.getElementById(id);
    if (el) {
      const inp = el.closest('.inp-wrap') || el.closest('.captcha-card');
      if (inp) {
        inp.style.borderColor = '#dc2626';
        inp.style.boxShadow = '0 0 0 3px rgba(220, 38, 38, 0.2)';
        el.addEventListener('focus', () => {
          inp.style.borderColor = '';
          inp.style.boxShadow = '';
        }, { once: true });
      }
    }
  });
<?php endif; ?>
</script>
</body>
</html>
