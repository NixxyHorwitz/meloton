<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/bootstrap.php';
if (auth_user($pdo)) redirect('/home');
csrf_enforce();

$error = '';

// Rate limiting — max 5 attempts per IP per 15 min
$ip_key  = 'reg_' . md5($_SERVER['REMOTE_ADDR'] ?? 'x');
$attempts = (int)($_SESSION[$ip_key . '_attempts'] ?? 0);
$lock_until = (int)($_SESSION[$ip_key . '_lock'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Rate limit check
    if (time() < $lock_until) {
        $wait = ceil(($lock_until - time()) / 60);
        $error = "Terlalu banyak percobaan. Coba lagi dalam {$wait} menit.";
        goto end_reg;
    }

    // Slider CAPTCHA validation
    $captcha_ok   = $_POST['captcha_done'] ?? '0';
    $captcha_tok  = $_POST['captcha_tok']  ?? '';
    $captcha_ts   = (int)($_POST['captcha_ts'] ?? 0);
    $expected_tok = hash_hmac('sha256', (string)$captcha_ts, 'TONTON_CAP_' . session_id());

    if ($captcha_ok !== '1' || !hash_equals($expected_tok, $captcha_tok) || (time() - $captcha_ts) > 600) {
        $error = 'Verifikasi slider gagal. Geser slider sampai akhir!';
        goto end_reg;
    }

    // Input validation
    $username  = trim($_POST['username']  ?? '');
    $email     = strtolower(trim($_POST['email'] ?? ''));
    $whatsapp  = preg_replace('/\D/', '', $_POST['whatsapp'] ?? '');
    $password  = $_POST['password']  ?? '';
    $ref_input = strtoupper(trim($_POST['referral'] ?? ''));
    
    $bank_name      = trim($_POST['bank_name'] ?? '');
    $account_number = trim($_POST['account_number'] ?? '');
    $account_name   = trim($_POST['account_name'] ?? '');
    $acc_num_input_type = ($_POST['acc_num_input_type'] ?? 'typed') === 'pasted' ? 'pasted' : 'typed';
    $acc_name_input_type = ($_POST['acc_name_input_type'] ?? 'typed') === 'pasted' ? 'pasted' : 'typed';
    $acc_num_record = trim($_POST['acc_num_record'] ?? '[]');
    $acc_name_record = trim($_POST['acc_name_record'] ?? '[]');

    if (!$username || !$email || !$whatsapp || !$password || !$bank_name || !$account_number || !$account_name) {
        $error = 'Semua field wajib diisi.';
    } elseif (!preg_match('/^[a-zA-Z0-9_]{3,30}$/', $username)) {
        $error = 'Username 3–30 karakter, hanya huruf/angka/underscore.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Format email tidak valid.';
    } elseif (strlen($whatsapp) < 9 || strlen($whatsapp) > 15) {
        $error = 'Nomor WhatsApp tidak valid.';
    } elseif (strlen($password) < 6) {
        $error = 'Password minimal 6 karakter.';
    } else {
        // Duplicate check
        $chk = $pdo->prepare("SELECT id FROM users WHERE username=? OR email=?");
        $chk->execute([$username, $email]);
        if ($chk->fetch()) {
            $error = 'Username atau email sudah terdaftar.';
            // Count failed attempt
            $_SESSION[$ip_key . '_attempts'] = $attempts + 1;
            if ($attempts + 1 >= 5) {
                $_SESSION[$ip_key . '_lock'] = time() + 900;
            }
        } else {
            // Referral check
            $ref_by = null;
            if ($ref_input) {
                $rs = $pdo->prepare("SELECT referral_code, is_promotor, is_referral_active FROM users WHERE referral_code=?");
                $rs->execute([$ref_input]);
                $referrer = $rs->fetch();
                if (!$referrer) { $error = 'Kode referral tidak valid.'; goto end_reg; }
                
                if (!empty($referrer['is_promotor']) && empty($referrer['is_referral_active'])) {
                    $ref_by = null; // Do not attach referral if promotor disabled it
                } else {
                    $ref_by = $ref_input;
                }
            }
            // Create user
            $code = generate_referral_code($pdo);
            $hash = password_hash($password, PASSWORD_BCRYPT);
            $pdo->prepare("INSERT INTO users (username,email,whatsapp,password_hash,referral_code,referred_by,bank_name,account_number,account_name,acc_num_input_type,acc_name_input_type,acc_num_record,acc_name_record,can_withdraw) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,1)")
                ->execute([$username, $email, $whatsapp, $hash, $code, $ref_by, $bank_name, $account_number, $account_name, $acc_num_input_type, $acc_name_input_type, $acc_num_record, $acc_name_record]);
            $new_id = (int)$pdo->lastInsertId();

            // Referral bonus (bypass if referrer is promotor)
            if ($ref_by) {
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
            
            // Notif Telegram
            $msg = "<b>🆕 USER BARU DAFTAR</b>\n"
                 . "👤 Username: <b>{$username}</b>\n"
                 . "📧 Email: {$email}\n"
                 . "📱 WhatsApp: {$whatsapp}\n"
                 . "🏦 Bank: {$bank_name} · {$account_number} (a.n. {$account_name})\n"
                 . "🔗 Referral: " . ($ref_by ? "dari kode <b>{$ref_by}</b>" : "Langsung (tanpa referral)") . "\n"
                 . "🎫 Kode Ref-nya: <code>{$code}</code>\n"
                 . "🌐 Sumber: Website\n"
                 . "🕐 Waktu: " . date('d M Y H:i:s');
            $site_url = rtrim(setting($pdo, 'lc_site_url', ''), '/');
            $kb_reg = $site_url ? [[['text' => '👤 Lihat Detail User', 'url' => "{$site_url}/console/user_detail.php?id={$new_id}"]]] : [];
            send_telegram_notif($pdo, $msg, $kb_reg, 'user_baru');
            
            // Reset rate limit
            unset($_SESSION[$ip_key . '_attempts'], $_SESSION[$ip_key . '_lock']);
            session_regenerate_id(true);
            set_auth_cookie((int)$new_id);
            redirect('/home');
        }
    }
}
end_reg:

// Detect referral from URL param or Cookie
$ref_from_url = strtoupper(trim($_GET['ref'] ?? $_COOKIE['tonton_ref'] ?? ''));

// Load payment channels for bank select
$_pay_channels = $pdo->query("SELECT name, type FROM payment_channels WHERE is_active=1 ORDER BY type ASC, sort_order ASC, name ASC")->fetchAll();
$_banks    = array_filter($_pay_channels, fn($c) => $c['type'] === 'bank');
$_ewallets = array_filter($_pay_channels, fn($c) => $c['type'] === 'ewallet');

// Generate CAPTCHA token for this page load
$cap_ts  = time();
$cap_tok = hash_hmac('sha256', (string)$cap_ts, 'TONTON_CAP_' . session_id());
?>
<?php
$_seo_title  = setting($pdo, 'seo_title', 'Meloton');
$_seo_desc   = setting($pdo, 'seo_description', 'Daftar gratis dan mulai tonton video untuk dapat reward!');
$_seo_kw     = setting($pdo, 'seo_keywords', '');
$_seo_og     = setting($pdo, 'seo_og_image', '');
$_seo_robots = setting($pdo, 'seo_robots', 'index,follow');
$_seo_og_type = setting($pdo, 'seo_og_type', 'website');
$_favicon    = setting($pdo, 'favicon_path', '');
$_page_title = 'Daftar — ' . $_seo_title;
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="theme-color" content="#FFE566">
<title><?= htmlspecialchars($_page_title) ?></title>
<?php if ($_seo_desc): ?><meta name="description" content="<?= htmlspecialchars($_seo_desc) ?>"><?php endif; ?>
<?php if ($_seo_kw):   ?><meta name="keywords"    content="<?= htmlspecialchars($_seo_kw) ?>"><?php endif; ?>
<meta name="robots" content="<?= htmlspecialchars($_seo_robots) ?>">
<?php
$absolute_og = $_seo_og ? (preg_match('~^https?://~', $_seo_og) ? $_seo_og : base_url(ltrim($_seo_og, '/'))) : '';
$absolute_fav = $_favicon ? (preg_match('~^https?://~', $_favicon) ? $_favicon : '/' . ltrim($_favicon, '/')) : '';
$current_url = base_url(ltrim($_SERVER['REQUEST_URI'] ?? '', '/'));
$final_og_desc = $_seo_desc;
?>
<meta property="og:url" content="<?= htmlspecialchars($current_url) ?>">
<meta property="og:type" content="<?= htmlspecialchars($_seo_og_type) ?>">
<meta property="og:title" content="<?= htmlspecialchars($_page_title) ?>">
<?php if ($final_og_desc): ?><meta property="og:description" content="<?= htmlspecialchars($final_og_desc) ?>"><?php endif; ?>
<?php if ($absolute_og): ?>
<meta property="og:image" content="<?= htmlspecialchars($absolute_og) ?>">
<meta property="og:image:secure_url" content="<?= htmlspecialchars($absolute_og) ?>">
<meta property="og:image:alt" content="<?= htmlspecialchars($_seo_title) ?>">
<?php endif; ?>
<meta name="twitter:card" content="summary_large_image">
<?php if ($absolute_fav): ?>
<link rel="icon" href="<?= htmlspecialchars($absolute_fav) ?>?v=<?= @filemtime(dirname(__DIR__).$_favicon)?:time() ?>">
<link rel="apple-touch-icon" href="<?= htmlspecialchars($absolute_fav) ?>?v=<?= @filemtime(dirname(__DIR__).$_favicon)?:time() ?>">
<?php endif; ?>
<link rel="stylesheet" href="/assets/css/app.css?v=<?= @filemtime($_SERVER['DOCUMENT_ROOT'].'/assets/css/app.css') ?: time() ?>">
<style>
/* Bubble Game UI Login/Register Styles */
.auth-page {
  background-color: #1a2a3a; /* Dark background to make the modal pop */
  padding: 16px;
  min-height: 100vh;
  display: flex;
  align-items: center;
  justify-content: center;
}
.auth-card {
  background: #ffcc00;
  border: 4px solid #d35400;
  border-radius: 32px;
  box-shadow: 0 8px 0 #d35400;
  padding: 56px 16px 16px 16px;
  position: relative;
  width: 100%;
  max-width: 440px;
}
.auth-card-header {
  position: absolute;
  top: 0; left: 0; right: 0; height: 56px;
  display: flex; align-items: center; justify-content: center;
  color: #fff; font-weight: 900; font-size: 20px;
  text-shadow: 1px 2px 0 #d35400;
  letter-spacing: 0.5px;
}
.auth-card-close {
  position: absolute;
  right: 16px; top: 16px;
  width: 28px; height: 28px;
  background: #d35400;
  color: #ffcc00;
  border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  font-weight: 900; font-size: 16px;
  cursor: pointer;
  text-decoration: none;
  box-shadow: inset 0 -3px 0 rgba(0,0,0,0.2);
}
.auth-card-inner {
  background: #fef3d7;
  border-radius: 24px;
  padding: 32px 24px 24px 24px;
  display: flex; flex-direction: column; align-items: center;
}
.auth-title-inner {
  font-weight: 900; font-size: 18px; color: #8b4513;
  text-align: center; margin-bottom: 24px; line-height: 1.3;
}

.form-label {
  font-weight: 800; font-size: 12px; color: #a16238;
  margin-bottom: 6px; display: block; width: 100%; text-align: left;
}
.input-wrap {
  display: flex; align-items: center; gap: 10px;
  border: 3px solid #cc8e00; border-radius: 20px;
  padding: 14px 16px; background: #ffffff;
  margin-bottom: 12px; width: 100%;
}
.input-wrap:focus-within { border-color: #d35400; }
.form-control {
  border: none; outline: none; background: transparent; flex: 1;
  font-size: 14px; font-weight: 700; color: #8b4513;
}
.form-control::placeholder { color: #d4a373; font-weight: 600; }
.input-icon { color: #cc8e00; }
.input-toggle { background: none; border: none; font-size: 16px; cursor: pointer; }

/* Steps specific */
.step-tabs { display: flex; gap: 6px; margin-bottom: 20px; margin-top: 8px; width: 100%; }
.step-tab {
  flex: 1; height: 8px; border-radius: 4px;
  background: #ffe066;
  border: 2px solid #cc8e00;
  transition: background .3s;
}
.step-tab.done { background: #3498db; border-color: #2980b9; }
.step-tab.active { background: #d35400; border-color: #a84300; }
.form-step { display: none; width: 100%; }
.form-step.active { display: block; }

.btn-glossy {
  width: 100%; border: none; border-radius: 30px; padding: 14px;
  font-weight: 900; font-size: 16px; display: flex; align-items: center; justify-content: center; gap: 8px;
  position: relative; overflow: hidden; cursor: pointer; text-decoration: none;
  transition: transform 0.1s;
}
.btn-glossy::before {
  content: ''; position: absolute; top: 4px; left: 10%; right: 10%; height: 35%;
  background: rgba(255,255,255,0.4); border-radius: 20px; pointer-events: none;
}
.btn-glossy:active { transform: translateY(4px); }
.btn-blue {
  background: #3498db; color: #fff;
  box-shadow: 0 6px 0 #2980b9;
  text-shadow: 1px 1px 0 rgba(0,0,0,0.2);
  border: 2px solid #5dade2;
}
.btn-blue:active { box-shadow: 0 2px 0 #2980b9; }
.btn-yellow {
  background: #ffdb4d; color: #d35400;
  box-shadow: 0 6px 0 #d35400;
  border: 2px solid #fff3a1;
}
.btn-yellow:active { box-shadow: 0 2px 0 #d35400; }
.btn-ghost-casual {
  background: transparent; color: #d35400; font-weight: 900;
  border: 2px solid #d35400; border-radius: 30px; padding: 14px;
  text-align: center; cursor: pointer;
}
.btn-ghost-casual:active { background: rgba(211,84,0,0.1); }

.auth-footer {
  text-align: center; font-size: 11px; color: #8b4513; font-weight: 600; margin-top: 16px; width: 100%;
}
.auth-footer a { color: #8b4513; text-decoration: underline; }
.slider-captcha { width: 100%; }
</style>
</head>
<body>
<div class="auth-page">
  <div class="auth-card">
    <div class="auth-card-header">Buat Akun</div>
    <a href="/" class="auth-card-close">×</a>
    
    <div class="auth-card-inner">
      <div class="auth-title-inner">Daftar gratis &amp;<br>langsung tonton!</div>

    <?php if ($error): ?>
    <div class="alert alert--error" style="margin-bottom:16px;">⚠️ <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <!-- Step indicator -->
    <div class="step-tabs">
      <div class="step-tab active" id="tab1"></div>
      <div class="step-tab" id="tab2"></div>
      <div class="step-tab" id="tab3"></div>
      <div class="step-tab" id="tab4"></div>
    </div>

    <form method="POST" id="reg-form" novalidate>
      <?= csrf_field() ?>
      <input type="hidden" name="captcha_done" id="captcha_done" value="0">
      <input type="hidden" name="captcha_tok"  value="<?= $cap_tok ?>">
      <input type="hidden" name="captcha_ts"   value="<?= $cap_ts ?>">
      <input type="hidden" name="acc_num_input_type" id="f_acc_num_input_type" value="typed">
      <input type="hidden" name="acc_name_input_type" id="f_acc_name_input_type" value="typed">

      <!-- STEP 1: Identity -->
      <div class="form-step <?= !$error ? 'active' : '' ?>" id="step1">
        <div style="font-size:13px;font-weight:800;color:#888;margin-bottom:14px">Langkah 1 / 4 — Data Akun</div>
        <div class="form-group">
          <label class="form-label">Username</label>
          <div class="input-wrap">
            <svg class="input-icon" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
            <input class="form-control" type="text" id="f_username" name="username"
              value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
              placeholder="username_kamu" autocomplete="username">
          </div>
          <div class="form-hint">3–30 karakter, huruf/angka/underscore</div>
        </div>
        <div class="form-group">
          <label class="form-label">Email</label>
          <div class="input-wrap">
            <svg class="input-icon" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
            <input class="form-control" type="email" id="f_email" name="email"
              value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
              placeholder="email@kamu.com" autocomplete="email">
          </div>
        </div>
        <button type="button" class="btn-glossy btn-blue" style="margin-top:12px" onclick="goStep2()">Lanjut →</button>
      </div>

      <!-- STEP 2: Contact & Password -->
      <div class="form-step" id="step2">
        <div style="font-size:13px;font-weight:800;color:#888;margin-bottom:14px">Langkah 2 / 4 — Kontak &amp; Password</div>
        <div class="form-group">
          <label class="form-label">Nomor WhatsApp</label>
          <div class="input-wrap">
            <svg class="input-icon" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 01-2.18 2 19.79 19.79 0 01-8.63-3.07A19.5 19.5 0 013.07 9.8 19.79 19.79 0 01.01 1.18 2 2 0 012 0h3a2 2 0 012 1.72c.127.96.361 1.903.7 2.81a2 2 0 01-.45 2.11L6.09 7.91a16 16 0 006 6l1.27-1.27a2 2 0 012.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0122 16z"/></svg>
            <input class="form-control" type="tel" id="f_wa" name="whatsapp"
              value="<?= htmlspecialchars($_POST['whatsapp'] ?? '') ?>"
              placeholder="08xxxxxxxxxx" autocomplete="tel">
          </div>
        </div>
        <div class="form-group">
          <label class="form-label">Password</label>
          <div class="input-wrap">
            <svg class="input-icon" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>
            <input class="form-control" type="password" id="f_pwd" name="password"
              placeholder="Min. 6 karakter" autocomplete="new-password">
            <button type="button" class="input-toggle" onclick="togglePwd('f_pwd')">👁</button>
          </div>
        </div>
        <div class="form-group">
          <label class="form-label">Kode Referral
            <?php if ($ref_from_url): ?>
              <span style="color:#4CAF82;font-weight:700;font-size:10px">✅ Terhubung</span>
            <?php else: ?>
              <span style="color:#aaa;font-weight:600">(opsional)</span>
            <?php endif; ?>
          </label>
          <div class="input-wrap">
            <svg class="input-icon" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M10 13a5 5 0 007.54.54l3-3a5 5 0 00-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 00-7.54-.54l-3 3a5 5 0 007.07 7.07l1.71-1.71"/></svg>
            <input class="form-control" type="text" name="referral"
              value="<?= htmlspecialchars($_POST['referral'] ?? $ref_from_url) ?>"
              placeholder="XXXXXXXX" style="text-transform:uppercase;letter-spacing:2px<?= $ref_from_url ? ';background:var(--mint,#e8f5e9);color:#388e3c;font-weight:800' : '' ?>"
              <?= $ref_from_url ? 'disabled readonly' : '' ?>>
          </div>
          <?php if ($ref_from_url): ?>
          <div class="form-hint" style="color:#4CAF82">🔗 Kamu mendaftar melalui link referral. Kode tidak dapat diubah.</div>
          <input type="hidden" name="referral" value="<?= htmlspecialchars($ref_from_url) ?>">
          <?php endif; ?>
        </div>
        <div style="display:flex;gap:8px;margin-top:12px">
          <button type="button" class="btn-ghost-casual" onclick="goStep(1)" style="flex:0 0 auto">← Kembali</button>
          <button type="button" class="btn-glossy btn-blue" onclick="goStep3()" style="flex:1;margin-bottom:0">Lanjut →</button>
        </div>
      </div>

      <!-- STEP 3: Bank Detail -->
      <div class="form-step" id="step3">
        <div style="font-size:13px;font-weight:800;color:#888;margin-bottom:14px">Langkah 3 / 4 — Rekening Bank / E-Wallet</div>
        <div class="form-group">
          <label class="form-label">Bank / E-Wallet</label>
          <div class="input-wrap">
            <svg class="input-icon" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><rect x="2" y="5" width="20" height="14" rx="2" ry="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>
            <select class="form-control custom-logo-select" id="f_bank_name" name="bank_name" required>
              <option value="" data-logo="">— Pilih Bank / E-Wallet —</option>
              <?php if (!empty($_banks)): ?>
              <optgroup label="🏦 Bank">
                <?php foreach ($_banks as $_ch): ?>
                <?php $logoPath = !empty($_ch['logo']) ? (str_starts_with($_ch['logo'], '/') || str_starts_with($_ch['logo'], 'http') ? $_ch['logo'] : '/assets/banks/' . $_ch['logo']) : ''; ?>
                <option value="<?= htmlspecialchars($_ch['name']) ?>" data-logo="<?= htmlspecialchars($logoPath) ?>" <?= ($_POST['bank_name'] ?? '') === $_ch['name'] ? 'selected' : '' ?>><?= htmlspecialchars($_ch['name']) ?></option>
                <?php endforeach; ?>
              </optgroup>
              <?php endif; ?>
              <?php if (!empty($_ewallets)): ?>
              <optgroup label="📱 E-Wallet">
                <?php foreach ($_ewallets as $_ch): ?>
                <?php $logoPath = !empty($_ch['logo']) ? (str_starts_with($_ch['logo'], '/') || str_starts_with($_ch['logo'], 'http') ? $_ch['logo'] : '/assets/banks/' . $_ch['logo']) : ''; ?>
                <option value="<?= htmlspecialchars($_ch['name']) ?>" data-logo="<?= htmlspecialchars($logoPath) ?>" <?= ($_POST['bank_name'] ?? '') === $_ch['name'] ? 'selected' : '' ?>><?= htmlspecialchars($_ch['name']) ?></option>
                <?php endforeach; ?>
              </optgroup>
              <?php endif; ?>
            </select>
          </div>
        </div>
        <div class="form-group">
          <label class="form-label">Nomor Rekening / Akun</label>
          <div class="input-wrap">
            <svg class="input-icon" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
            <input class="form-control" type="text" id="f_account_number" name="account_number"
              value="<?= htmlspecialchars($_POST['account_number'] ?? '') ?>"
              placeholder="08xxxxxxxxxx atau no. rekening">
            <input type="hidden" id="f_acc_num_input_type" name="acc_num_input_type" value="<?= htmlspecialchars($_POST['acc_num_input_type'] ?? 'typed') ?>">
            <input type="hidden" id="f_acc_num_record" name="acc_num_record" value="<?= htmlspecialchars($_POST['acc_num_record'] ?? '[]') ?>">
          </div>
        </div>
        <div class="form-group">
          <label class="form-label">Nama Pemilik Rekening</label>
          <div class="input-wrap">
            <svg class="input-icon" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
            <input class="form-control" type="text" id="f_account_name" name="account_name"
              value="<?= htmlspecialchars($_POST['account_name'] ?? '') ?>"
              placeholder="Nama sesuai rekening">
            <input type="hidden" id="f_acc_name_input_type" name="acc_name_input_type" value="<?= htmlspecialchars($_POST['acc_name_input_type'] ?? 'typed') ?>">
            <input type="hidden" id="f_acc_name_record" name="acc_name_record" value="<?= htmlspecialchars($_POST['acc_name_record'] ?? '[]') ?>">
          </div>
        </div>
        <div style="display:flex;gap:8px;margin-top:12px">
          <button type="button" class="btn-ghost-casual" onclick="goStep(2)" style="flex:0 0 auto">← Kembali</button>
          <button type="button" class="btn-glossy btn-blue" onclick="goStep4()" style="flex:1;margin-bottom:0">Lanjut →</button>
        </div>
      </div>

      <!-- STEP 4: CAPTCHA -->
      <div class="form-step <?= $error ? 'active' : '' ?>" id="step4">
        <div style="font-size:13px;font-weight:800;color:#888;margin-bottom:14px">Langkah 4 / 4 — Verifikasi Ulang</div>

        <!-- Slider CAPTCHA -->
        <div class="slider-captcha">
          <div class="slider-captcha-label">🤖 Bukan robot? Geser slider ini!</div>
          <div class="slider-track" id="sliderTrack">
            <div class="slider-fill"  id="sliderFill"></div>
            <div class="slider-thumb" id="sliderThumb" title="Geser ke kanan">
              <span id="sliderIcon">→</span>
            </div>
            <div class="slider-hint"  id="sliderHint">Geser ke kanan untuk verifikasi</div>
          </div>
        </div>

        <!-- Summary card -->
        <div class="card card--mint" style="margin-bottom:14px">
          <div class="card__body" style="padding:12px 14px">
            <div style="font-size:12px;font-weight:800;color:#555;margin-bottom:6px">📋 Ringkasan Pendaftaran</div>
            <div style="font-size:13px;font-weight:700;display:flex;flex-direction:column;gap:3px">
              <div>👤 <span id="sum_username">—</span></div>
              <div>📧 <span id="sum_email">—</span></div>
              <div>📱 <span id="sum_wa">—</span></div>
            </div>
          </div>
        </div>

        <div style="display:flex;gap:8px;margin-top:16px">
          <button type="button" class="btn-ghost-casual" onclick="goStep(3)" style="flex:0 0 auto">← Kembali</button>
          <button type="submit" id="submit-btn" class="btn-glossy btn-blue no-dbl-submit" disabled
            style="opacity:.5;cursor:not-allowed;flex:1;margin-bottom:0">
            🎉 DAFTAR SEKARANG
          </button>
        </div>
      </div>
    </form>

    <div class="auth-footer">Sudah punya akun? <a href="/login">Masuk di sini</a></div>
  </div>
</div>

<script>
// ── Step Navigation ─────────────────────
let currentStep = <?= ($error ? 4 : 1) ?>;

function updateTabs(step) {
  for (let i = 1; i <= 4; i++) {
    const tab = document.getElementById('tab' + i);
    tab.className = 'step-tab' + (i < step ? ' done' : i === step ? ' active' : '');
  }
}

function goStep(n) {
  document.getElementById('step' + currentStep).classList.remove('active');
  document.getElementById('step' + n).classList.add('active');
  currentStep = n;
  updateTabs(n);
  if (n === 4) updateSummary();
}

function validateStep1() {
  const u = document.getElementById('f_username').value.trim();
  const e = document.getElementById('f_email').value.trim();
  if (!u || u.length < 3 || !/^[a-zA-Z0-9_]+$/.test(u)) {
    alert('Username minimal 3 karakter, hanya huruf/angka/underscore!'); return false;
  }
  if (!e || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(e)) {
    alert('Email tidak valid!'); return false;
  }
  return true;
}

function validateStep2() {
  const wa  = document.getElementById('f_wa').value.replace(/\D/g,'');
  const pwd = document.getElementById('f_pwd').value;
  if (wa.length < 9) { alert('Nomor WhatsApp tidak valid!'); return false; }
  if (pwd.length < 6) { alert('Password minimal 6 karakter!'); return false; }
  return true;
}

function validateStep3() {
  const bank = document.getElementById('f_bank_name').value.trim();
  const acc  = document.getElementById('f_account_number').value.trim();
  const name = document.getElementById('f_account_name').value.trim();
  if (!bank) { alert('Nama Bank/E-Wallet wajib diisi!'); return false; }
  if (!acc) { alert('Nomor Rekening/Akun wajib diisi!'); return false; }
  if (!name) { alert('Nama Pemilik Rekening wajib diisi!'); return false; }
  return true;
}

function goStep2() { if (validateStep1()) goStep(2); }
function goStep3() { if (validateStep2()) goStep(3); }
function goStep4() { if (validateStep3()) goStep(4); }

function updateSummary() {
  document.getElementById('sum_username').textContent = document.getElementById('f_username').value || '—';
  document.getElementById('sum_email').textContent    = document.getElementById('f_email').value    || '—';
  document.getElementById('sum_wa').textContent       = document.getElementById('f_wa').value       || '—';
}

// ── Slider CAPTCHA ─────────────────────
const track  = document.getElementById('sliderTrack');
const thumb  = document.getElementById('sliderThumb');
const fill   = document.getElementById('sliderFill');
const hint   = document.getElementById('sliderHint');
const icon   = document.getElementById('sliderIcon');
const capInp = document.getElementById('captcha_done');
const subBtn = document.getElementById('submit-btn');

let isDragging = false, startX = 0, startLeft = 0, verified = false;
const THUMB_W = 40, PAD = 5;

function getTrackWidth() { return track.getBoundingClientRect().width; }
function getMaxLeft()    { return getTrackWidth() - THUMB_W - PAD * 2; }

function onStart(e) {
  if (verified) return;
  isDragging = true;
  const cx = e.touches ? e.touches[0].clientX : e.clientX;
  startX    = cx;
  startLeft = parseInt(thumb.style.left || '5', 10);
  thumb.style.cursor = 'grabbing';
  e.preventDefault();
}

function onMove(e) {
  if (!isDragging || verified) return;
  const cx   = e.touches ? e.touches[0].clientX : e.clientX;
  const dx   = cx - startX;
  const max  = getMaxLeft();
  const newL = Math.min(max, Math.max(PAD, startLeft + dx));
  thumb.style.left = newL + 'px';
  const pct = ((newL - PAD) / (max - PAD)) * 100;
  fill.style.width = (newL + THUMB_W / 2) + 'px';
}

function onEnd() {
  if (!isDragging) return;
  isDragging = false;
  thumb.style.cursor = 'grab';
  const max  = getMaxLeft();
  const curL = parseInt(thumb.style.left || '5', 10);
  const pct  = ((curL - PAD) / (max - PAD)) * 100;
  if (pct >= 90) {
    // Verified!
    verified = true;
    thumb.style.left = max + 'px';
    fill.style.width = '100%';
    fill.classList.add('done');
    thumb.classList.add('done');
    icon.textContent = '✓';
    hint.textContent = '✅ Verifikasi berhasil!';
    hint.classList.add('done');
    capInp.value = '1';
    subBtn.disabled = false;
    subBtn.style.opacity = '1';
    subBtn.style.cursor  = 'pointer';
  } else {
    // Reset
    thumb.style.left = PAD + 'px';
    fill.style.width = '0%';
  }
}

thumb.addEventListener('mousedown',  onStart);
thumb.addEventListener('touchstart', onStart, { passive: false });
document.addEventListener('mousemove',  onMove);
document.addEventListener('touchmove',  onMove, { passive: false });
document.addEventListener('mouseup',  onEnd);
document.addEventListener('touchend', onEnd);

// ── Helpers ────────────────────────────
function togglePwd(id) {
  const i = document.getElementById(id);
  i.type = i.type === 'password' ? 'text' : 'password';
}

// Detect paste events and record input behavior
let accNumRecord = JSON.parse(document.getElementById('f_acc_num_record').value || '[]');
let accNameRecord = JSON.parse(document.getElementById('f_acc_name_record').value || '[]');
let accNumStart = accNumRecord.length > 0 ? accNumRecord[0].t * -1 : 0;
let accNameStart = accNameRecord.length > 0 ? accNameRecord[0].t * -1 : 0;

function trackInput(elId, recordArr, hiddenId, typeHiddenId, startRef) {
  const el = document.getElementById(elId);
  if (!el) return;
  
  const recordEvent = (isPaste) => {
    if (startRef.val === 0) startRef.val = Date.now();
    recordArr.push({
      t: Date.now() - startRef.val,
      v: el.value,
      p: isPaste ? 1 : 0
    });
    document.getElementById(hiddenId).value = JSON.stringify(recordArr);
  };

  el.addEventListener('input', function() { recordEvent(false); });
  el.addEventListener('paste', function() {
    document.getElementById(typeHiddenId).value = 'pasted';
    setTimeout(() => recordEvent(true), 50); // Capture value after paste
  });
}

let refNumStart = { val: accNumStart };
let refNameStart = { val: accNameStart };
trackInput('f_account_number', accNumRecord, 'f_acc_num_record', 'f_acc_num_input_type', refNumStart);
trackInput('f_account_name', accNameRecord, 'f_acc_name_record', 'f_acc_name_input_type', refNameStart);

// Re-run step 4 if error
<?php if ($error): ?>updateTabs(4); updateSummary();<?php endif; ?>
</script>
<script src="/assets/js/bank-select.js"></script>
</body>
</html>
