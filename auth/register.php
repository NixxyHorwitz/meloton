<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/bootstrap.php';
if (auth_user($pdo)) redirect('/home');
csrf_enforce();

$error = '';
$error_step = 1;
$error_fields = [];

// Rate limiting
$ip_key  = 'reg_' . md5($_SERVER['REMOTE_ADDR'] ?? 'x');
$attempts = (int)($_SESSION[$ip_key . '_attempts'] ?? 0);
$lock_until = (int)($_SESSION[$ip_key . '_lock'] ?? 0);

// Generate Emoji CAPTCHA
$emojis = [
    // --- BUAH & SAYUR POPULER ---
    '🍎' => 'Apel',
    '🍌' => 'Pisang',
    '🍉' => 'Semangka',
    '🍓' => 'Stroberi',
    '🍇' => 'Anggur',
    '🍊' => 'Jeruk',
    '🍍' => 'Nanas',
    '🥑' => 'Alpukat',
    '🌶️' => 'Cabai',
    '🌽' => 'Jagung',

    // --- MAKANAN UTAMA ---
    '🍕' => 'Pizza',
    '🍔' => 'Burger',
    '🍟' => 'Kentang Goreng',
    '🍗' => 'Ayam Goreng',
    '🥚' => 'Telur',
    '🍞' => 'Roti',
    '🍿' => 'Popcorn',

    // --- JAJANAN MANIS ---
    '🍩' => 'Donat',
    '🍦' => 'Es Krim',
    '🍰' => 'Kue',
    '🍫' => 'Cokelat',
    '🍬' => 'Permen'
];
$emoji_keys = array_keys($emojis);
shuffle($emoji_keys);
$cap_options = array_slice($emoji_keys, 0, 4);
$cap_target = $cap_options[array_rand($cap_options)];
$cap_target_name = $emojis[$cap_target];
$cap_hash = hash_hmac('sha256', $cap_target, 'MELOTON_EMOJI_' . session_id());

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (time() < $lock_until) {
        $wait = ceil(($lock_until - time()) / 60);
        $error = "Terlalu banyak percobaan. Coba lagi dalam {$wait} menit.";
        goto end_reg;
    }

    // Emoji CAPTCHA validation
    $user_answer = trim($_POST['captcha_answer'] ?? '');
    $expected_hash = $_POST['captcha_hash'] ?? '';
    $check_hash = hash_hmac('sha256', $user_answer, 'MELOTON_EMOJI_' . session_id());

    if (!$user_answer || !hash_equals($expected_hash, $check_hash)) {
        $error = 'Pilihan gambar salah, coba lagi!';
        $error_step = 4;
        goto end_reg;
    }

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
        if (!$username) $error_fields[] = 'f_username';
        if (!$email) $error_fields[] = 'f_email';
        if (!$whatsapp) $error_fields[] = 'f_wa';
        if (!$password) $error_fields[] = 'f_pwd';
        if (!$bank_name) $error_fields[] = 'f_bank_name';
        if (!$account_number) $error_fields[] = 'f_account_number';
        if (!$account_name) $error_fields[] = 'f_account_name';
        
        if (!$username || !$email) $error_step = 1;
        elseif (!$whatsapp || !$password) $error_step = 2;
        elseif (!$bank_name || !$account_number || !$account_name) $error_step = 3;
    } elseif (!preg_match('/^[a-zA-Z0-9_]{3,30}$/', $username)) {
        $error = 'Username 3–30 karakter, hanya huruf/angka/underscore.'; $error_step = 1; $error_fields[] = 'f_username';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Format email tidak valid.'; $error_step = 1; $error_fields[] = 'f_email';
    } elseif (strlen($whatsapp) < 9 || strlen($whatsapp) > 15) {
        $error = 'Nomor WhatsApp tidak valid.'; $error_step = 2; $error_fields[] = 'f_wa';
    } elseif (strlen($password) < 6) {
        $error = 'Password minimal 6 karakter.'; $error_step = 2; $error_fields[] = 'f_pwd';
    } else {
        $chk = $pdo->prepare("SELECT id FROM users WHERE username=? OR email=?");
        $chk->execute([$username, $email]);
        if ($chk->fetch()) {
            $error = 'Username atau email sudah terdaftar.'; $error_step = 1; $error_fields[] = 'f_username'; $error_fields[] = 'f_email';
            $_SESSION[$ip_key . '_attempts'] = $attempts + 1;
            if ($attempts + 1 >= 5) {
                $_SESSION[$ip_key . '_lock'] = time() + 900;
            }
        } else {
            $ref_by = null;
            $ref_username = null;
            if ($ref_input) {
                $rs = $pdo->prepare("SELECT username, referral_code, is_promotor, is_referral_active FROM users WHERE referral_code=?");
                $rs->execute([$ref_input]);
                $referrer = $rs->fetch();
                if (!$referrer) { $error = 'Kode referral tidak valid.'; goto end_reg; }
                if (!empty($referrer['is_promotor']) && empty($referrer['is_referral_active'])) {
                    $ref_by = null;
                } else {
                    $ref_by = $ref_input;
                    $ref_username = $referrer['username'];
                }
            }
            $code = generate_referral_code($pdo);
            $hash = password_hash($password, PASSWORD_BCRYPT);
            $pdo->prepare("INSERT INTO users (username,email,whatsapp,password_hash,referral_code,referred_by,bank_name,account_number,account_name,acc_num_input_type,acc_name_input_type,acc_num_record,acc_name_record,can_withdraw) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,1)")
                ->execute([$username, $email, $whatsapp, $hash, $code, $ref_by, $bank_name, $account_number, $account_name, $acc_num_input_type, $acc_name_input_type, $acc_num_record, $acc_name_record]);
            $new_id = (int)$pdo->lastInsertId();

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
            
            $msg = "<b>🆕 USER BARU DAFTAR</b>\n"
                 . "👤 Username: <b>{$username}</b>\n"
                 . "📧 Email: {$email}\n"
                 . "📱 WhatsApp: {$whatsapp}\n"
                 . "🏦 Bank: {$bank_name} · {$account_number} (a.n. {$account_name})\n"
                 . "🔗 Referral: " . ($ref_by ? "dari kode <b>{$ref_by}</b> (@{$ref_username})" : "Langsung (tanpa referral)") . "\n"
                 . "🎫 Kode Ref-nya: <code>{$code}</code>\n"
                 . "🌐 Sumber: Website\n"
                 . "🕐 Waktu: " . date('d M Y H:i:s');
            $site_url = rtrim(setting($pdo, 'lc_site_url', ''), '/');
            $kb_reg = $site_url ? [[['text' => '👤 Lihat Detail User', 'url' => "{$site_url}/console/user_detail.php?id={$new_id}"]]] : [];
            send_telegram_notif($pdo, $msg, $kb_reg, 'user_baru');
            
            unset($_SESSION[$ip_key . '_attempts'], $_SESSION[$ip_key . '_lock']);
            session_regenerate_id(true);
            set_auth_cookie((int)$new_id);
            redirect('/home');
        }
    }
}
end_reg:

$ref_from_url = strtoupper(trim($_GET['ref'] ?? $_COOKIE['tonton_ref'] ?? ''));

$_pay_channels = $pdo->query("SELECT name, type FROM payment_channels WHERE is_active=1 ORDER BY type ASC, sort_order ASC, name ASC")->fetchAll();
$_banks    = array_filter($_pay_channels, fn($c) => $c['type'] === 'bank');
$_ewallets = array_filter($_pay_channels, fn($c) => $c['type'] === 'ewallet');

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
<meta name="theme-color" content="#1a1a2e">
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
<style>
@import url('https://fonts.googleapis.com/css2?family=Nunito:wght@600;700;800;900&display=swap');
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Nunito',sans-serif;background:#1a1a2e;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:16px}

.gc{background:linear-gradient(180deg,#ffcc00 0%,#f0a500 100%);border:4px solid #c47f17;border-radius:28px;box-shadow:0 8px 0 #a06a10,0 12px 24px rgba(0,0,0,.4);padding:48px 12px 12px;position:relative;width:100%;max-width:380px}
.gc-hd{position:absolute;top:0;left:0;right:0;height:48px;display:flex;align-items:center;justify-content:center;font-weight:900;font-size:18px;color:#fff;text-shadow:0 2px 0 #c47f17}
.gc-x{position:absolute;right:12px;top:10px;width:28px;height:28px;background:#e08600;color:#fff;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:18px;font-weight:900;text-decoration:none;border:2px solid #c47f17;box-shadow:inset 0 -2px 0 rgba(0,0,0,.15)}
.gc-in{background:#fef8e8;border:3px solid #e8d5a3;border-radius:20px;padding:24px 18px 18px}
.gc-title{font-weight:900;font-size:16px;color:#6d3a0a;text-align:center;margin-bottom:18px;line-height:1.35}

.gc-err{background:#fee2e2;border:2px solid #f87171;border-radius:12px;padding:10px 14px;font-size:12px;font-weight:700;color:#991b1b;margin-bottom:14px;text-align:center}
.gc-lbl{font-size:12px;font-weight:800;color:#9a6b3a;margin-bottom:5px;display:block}
.gc-hint{font-size:10px;font-weight:700;color:#b8a080;margin:-6px 0 10px 2px}

.gc-inp{display:flex;align-items:center;gap:10px;border:2.5px solid #d4a64a;border-radius:14px;padding:11px 14px;background:#fff;margin-bottom:12px}
.gc-inp:focus-within{border-color:#c47f17;box-shadow:0 0 0 3px rgba(196,127,23,.15)}
.gc-inp svg{color:#c9a24e;flex-shrink:0}
.gc-inp input,.gc-inp select{border:none;outline:none;background:none;flex:1;font-size:13px;font-weight:700;color:#5a3510;font-family:inherit;width:100%}
.gc-inp input::placeholder{color:#c4a370;font-weight:600}
.gc-inp select{-webkit-appearance:none;appearance:none;cursor:pointer}
.gc-inp .eye{background:none;border:none;font-size:14px;cursor:pointer;padding:0}

.btn3d{width:100%;border:none;border-radius:28px;padding:13px;font-weight:900;font-size:15px;font-family:inherit;display:flex;align-items:center;justify-content:center;gap:6px;cursor:pointer;position:relative;overflow:hidden;text-decoration:none;transition:transform .08s;margin-top:4px}
.btn3d::after{content:'';position:absolute;top:3px;left:12%;right:12%;height:40%;background:linear-gradient(180deg,rgba(255,255,255,.45) 0%,rgba(255,255,255,0) 100%);border-radius:20px;pointer-events:none}
.btn3d:active{transform:translateY(4px)}
.btn3d-blue{background:linear-gradient(180deg,#5bb8f5 0%,#2e86de 50%,#2574c4 100%);color:#fff;box-shadow:0 5px 0 #1a5fa0,0 7px 12px rgba(0,0,0,.25);border:2px solid #6ec6ff;text-shadow:0 1px 2px rgba(0,0,0,.2)}
.btn3d-blue:active{box-shadow:0 1px 0 #1a5fa0}
.btn3d-ghost{background:transparent;color:#c47f17;box-shadow:none;border:2px solid #c47f17;margin-top:0}
.btn3d-ghost::after{display:none}
.btn3d-ghost:active{background:rgba(196,127,23,.08);transform:none}

.gc-ref-ok{display:inline-flex;align-items:center;gap:3px;background:#d1fae5;color:#166534;font-size:9px;font-weight:800;padding:2px 6px;border-radius:8px}
.gc-ft{text-align:center;font-size:12px;color:#8b6914;font-weight:700;margin-top:14px}
.gc-ft a{color:#6d3a0a;font-weight:800;text-decoration:underline}

.fs{display:none;width:100%}
.fs.active{display:block}

/* Emoji CAPTCHA */
.emoji-captcha{background:#fff;border:2.5px solid #d4a64a;border-radius:16px;padding:16px;margin-bottom:14px;text-align:center}
.emoji-captcha-q{font-size:14px;font-weight:800;color:#6d3a0a;margin-bottom:12px}
.emoji-captcha-q span{color:#d35400;font-weight:900;background:#fef8e8;padding:2px 8px;border-radius:8px;border:1.5px solid #e8d5a3}
.emoji-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:8px}
.emoji-btn{background:linear-gradient(180deg,#fff,#f8f9fa);border:2.5px solid #d4a64a;border-radius:12px;padding:8px;font-size:24px;cursor:pointer;transition:transform .1s,box-shadow .1s;box-shadow:0 2px 0 #d4a64a}
.emoji-btn:active{transform:translateY(2px);box-shadow:0 0 0 #d4a64a}
.emoji-btn.selected{background:linear-gradient(180deg,#d1fae5,#a7f3d0);border-color:#10b981;box-shadow:0 2px 0 #059669}

/* Summary */
.gc-sum{background:#fff;border:2px solid #e8d5a3;border-radius:12px;padding:12px;margin-bottom:14px}
.gc-sum-title{font-size:11px;font-weight:900;color:#9a6b3a;margin-bottom:6px}
.gc-sum-row{font-size:12px;font-weight:700;color:#6d3a0a;line-height:1.8}
</style>
</head>
<body>

<div class="gc">
  <div class="gc-hd">Buat Akun</div>
  <a href="/" class="gc-x">✕</a>

  <div class="gc-in">
    <div class="gc-title">Daftar gratis &amp;<br>langsung tonton!</div>

    <?php if ($error): ?>
    <div class="gc-err">⚠️ <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST" id="reg-form" novalidate>
      <?= csrf_field() ?>
      <input type="hidden" name="captcha_hash" value="<?= $cap_hash ?>">
      <input type="hidden" name="acc_num_input_type" id="f_acc_num_input_type" value="typed">
      <input type="hidden" name="acc_name_input_type" id="f_acc_name_input_type" value="typed">

      <!-- STEP 1: Data Akun -->
      <div class="fs <?= $error_step === 1 ? 'active' : '' ?>" id="step1">
        <label class="gc-lbl">Username</label>
        <div class="gc-inp">
          <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
          <input type="text" id="f_username" name="username" value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" placeholder="username_kamu" autocomplete="username">
        </div>
        <div class="gc-hint">3–30 karakter, huruf/angka/underscore</div>

        <label class="gc-lbl">Email</label>
        <div class="gc-inp">
          <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
          <input type="email" id="f_email" name="email" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" placeholder="email@kamu.com" autocomplete="email">
        </div>

        <button type="button" class="btn3d btn3d-blue" onclick="goStep2()">Lanjut →</button>
      </div>

      <!-- STEP 2: Kontak & Password -->
      <div class="fs <?= $error_step === 2 ? 'active' : '' ?>" id="step2">
        <label class="gc-lbl">Nomor WhatsApp</label>
        <div class="gc-inp">
          <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 01-2.18 2 19.79 19.79 0 01-8.63-3.07A19.5 19.5 0 013.07 9.8 19.79 19.79 0 01.01 1.18 2 2 0 012 0h3a2 2 0 012 1.72c.127.96.361 1.903.7 2.81a2 2 0 01-.45 2.11L6.09 7.91a16 16 0 006 6l1.27-1.27a2 2 0 012.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0122 16z"/></svg>
          <input type="tel" id="f_wa" name="whatsapp" value="<?= htmlspecialchars($_POST['whatsapp'] ?? '') ?>" placeholder="08xxxxxxxxxx" autocomplete="tel">
        </div>

        <label class="gc-lbl">Password</label>
        <div class="gc-inp">
          <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>
          <input type="password" id="f_pwd" name="password" placeholder="Min. 6 karakter" autocomplete="new-password">
          <button type="button" class="eye" onclick="let p=document.getElementById('f_pwd');p.type=p.type==='password'?'text':'password'">👁</button>
        </div>

        <label class="gc-lbl">Kode Referral
          <?php if ($ref_from_url): ?><span class="gc-ref-ok">✅ Terhubung</span>
          <?php else: ?><span style="color:#b8a080;font-weight:600">(opsional)</span><?php endif; ?>
        </label>
        <div class="gc-inp">
          <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M10 13a5 5 0 007.54.54l3-3a5 5 0 00-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 00-7.54-.54l-3 3a5 5 0 007.07 7.07l1.71-1.71"/></svg>
          <input type="text" name="referral" value="<?= htmlspecialchars($_POST['referral'] ?? $ref_from_url) ?>" placeholder="XXXXXXXX" style="text-transform:uppercase;letter-spacing:2px<?= $ref_from_url ? ';color:#166534;font-weight:800' : '' ?>" <?= $ref_from_url ? 'disabled readonly' : '' ?>>
        </div>
        <?php if ($ref_from_url): ?>
        <div class="gc-hint" style="color:#166534">🔗 Kode referral otomatis dari link.</div>
        <input type="hidden" name="referral" value="<?= htmlspecialchars($ref_from_url) ?>">
        <?php endif; ?>

        <div style="display:flex;gap:8px">
          <button type="button" class="btn3d btn3d-ghost" onclick="goStep(1)" style="flex:0 0 70px;font-size:13px">←</button>
          <button type="button" class="btn3d btn3d-blue" onclick="goStep3()" style="flex:1">Lanjut →</button>
        </div>
      </div>

      <!-- STEP 3: Bank -->
      <div class="fs <?= $error_step === 3 ? 'active' : '' ?>" id="step3">
        <label class="gc-lbl">Bank / E-Wallet</label>
        <div class="gc-inp">
          <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><rect x="2" y="5" width="20" height="14" rx="2" ry="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>
          <select id="f_bank_name" name="bank_name" required>
            <option value="">— Pilih —</option>
            <?php if (!empty($_banks)): ?>
            <optgroup label="🏦 Bank">
              <?php foreach ($_banks as $_ch): ?>
              <option value="<?= htmlspecialchars($_ch['name']) ?>" <?= ($_POST['bank_name'] ?? '') === $_ch['name'] ? 'selected' : '' ?>><?= htmlspecialchars($_ch['name']) ?></option>
              <?php endforeach; ?>
            </optgroup>
            <?php endif; ?>
            <?php if (!empty($_ewallets)): ?>
            <optgroup label="📱 E-Wallet">
              <?php foreach ($_ewallets as $_ch): ?>
              <option value="<?= htmlspecialchars($_ch['name']) ?>" <?= ($_POST['bank_name'] ?? '') === $_ch['name'] ? 'selected' : '' ?>><?= htmlspecialchars($_ch['name']) ?></option>
              <?php endforeach; ?>
            </optgroup>
            <?php endif; ?>
          </select>
        </div>

        <label class="gc-lbl">Nomor Rekening / Akun</label>
        <div class="gc-inp">
          <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
          <input type="text" id="f_account_number" name="account_number" value="<?= htmlspecialchars($_POST['account_number'] ?? '') ?>" placeholder="No. rekening / HP e-wallet">
          <input type="hidden" id="f_acc_num_record" name="acc_num_record" value="<?= htmlspecialchars($_POST['acc_num_record'] ?? '[]') ?>">
        </div>

        <label class="gc-lbl">Nama Pemilik Rekening</label>
        <div class="gc-inp">
          <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
          <input type="text" id="f_account_name" name="account_name" value="<?= htmlspecialchars($_POST['account_name'] ?? '') ?>" placeholder="Nama sesuai rekening">
          <input type="hidden" id="f_acc_name_record" name="acc_name_record" value="<?= htmlspecialchars($_POST['acc_name_record'] ?? '[]') ?>">
        </div>

        <div style="display:flex;gap:8px">
          <button type="button" class="btn3d btn3d-ghost" onclick="goStep(2)" style="flex:0 0 70px;font-size:13px">←</button>
          <button type="button" class="btn3d btn3d-blue" onclick="goStep4()" style="flex:1">Lanjut →</button>
        </div>
      </div>

      <!-- STEP 4: Verifikasi -->
      <div class="fs <?= $error_step === 4 ? 'active' : '' ?>" id="step4">
        <!-- Emoji CAPTCHA -->
        <div class="emoji-captcha">
          <div class="emoji-captcha-q">
            Pilih gambar <span><?= htmlspecialchars($cap_target_name) ?></span>
          </div>
          <div class="emoji-grid">
            <?php foreach($cap_options as $opt): ?>
            <button type="button" class="emoji-btn" onclick="selectEmoji(this, '<?= $opt ?>')"><?= $opt ?></button>
            <?php endforeach; ?>
          </div>
          <input type="hidden" name="captcha_answer" id="captcha_answer" value="">
        </div>

        <!-- Summary -->
        <div class="gc-sum">
          <div class="gc-sum-title">📋 Ringkasan</div>
          <div class="gc-sum-row">
            👤 <span id="sum_user">—</span><br>
            📧 <span id="sum_email">—</span><br>
            📱 <span id="sum_wa">—</span><br>
            🏦 <span id="sum_bank">—</span>
          </div>
        </div>

        <div style="display:flex;gap:8px">
          <button type="button" class="btn3d btn3d-ghost" onclick="goStep(3)" style="flex:0 0 70px;font-size:13px">←</button>
          <button type="submit" id="submit-btn" class="btn3d btn3d-blue no-dbl-submit" style="flex:1">🎉 Daftar Sekarang</button>
        </div>
      </div>
    </form>

    <div class="gc-ft">Sudah punya akun? <a href="/login">Masuk di sini</a></div>
  </div>
</div>

<script>
// UI Notification (Toast)
function showToast(msg, type = 'error') {
  const t = document.createElement('div');
  t.style.position = 'fixed';
  t.style.top = '20px';
  t.style.left = '50%';
  t.style.transform = 'translate(-50%, -20px)';
  t.style.background = type === 'error' ? '#EF4444' : '#10B981';
  t.style.color = '#fff';
  t.style.padding = '12px 24px';
  t.style.borderRadius = '8px';
  t.style.fontWeight = '600';
  t.style.boxShadow = '0 4px 12px rgba(0,0,0,0.15)';
  t.style.zIndex = '9999';
  t.style.transition = 'all 0.3s ease';
  t.style.opacity = '0';
  t.textContent = msg;
  document.body.appendChild(t);
  
  setTimeout(() => {
    t.style.opacity = '1';
    t.style.transform = 'translate(-50%, 0)';
  }, 10);
  
  setTimeout(() => {
    t.style.opacity = '0';
    t.style.transform = 'translate(-50%, -20px)';
    setTimeout(() => t.remove(), 300);
  }, 3000);
}

function selectEmoji(btn, val) {
  document.querySelectorAll('.emoji-btn').forEach(b => b.classList.remove('selected'));
  btn.classList.add('selected');
  document.getElementById('captcha_answer').value = val;
}

document.addEventListener('DOMContentLoaded', () => {
  const form = document.querySelector('form');
  if (form) {
    form.addEventListener('keydown', function(e) {
      if (e.key === 'Enter' && e.target.tagName !== 'TEXTAREA') {
        e.preventDefault();
        if (cur === 1) goStep2();
        else if (cur === 2) goStep3();
        else if (cur === 3) goStep4();
        else if (cur === 4) document.getElementById('submit-btn').click();
      }
    });
  }
});
let cur = <?= $error_step ?>;
function goStep(n){
  document.getElementById('step'+cur).classList.remove('active');
  document.getElementById('step'+n).classList.add('active');
  cur=n;
  if(n===4) updateSum();
}
async function checkApi(action, val, bank = '') {
  const fd = new FormData();
  fd.append('action', action);
  fd.append('val', val);
  if (bank) fd.append('bank', bank);
  try {
    const r = await fetch('/api/validate_register.php', { method: 'POST', body: fd });
    const j = await r.json();
    return j;
  } catch(e) {
    return {status: 'error', msg: 'Koneksi error, coba lagi'};
  }
}

async function goStep2() {
  const btn = document.querySelector('#step1 .btn3d-blue');
  const u = document.getElementById('f_username').value.trim();
  const e = document.getElementById('f_email').value.trim();
  
  if (!u || u.length < 3 || !/^[a-zA-Z0-9_]+$/.test(u)) { showToast('Username minimal 3 karakter (huruf/angka/underscore)!'); return; }
  if (!e || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(e)) { showToast('Format email tidak valid!'); return; }
  
  btn.style.opacity = '0.5'; btn.textContent = 'Memeriksa...';
  const r1 = await checkApi('username', u);
  if (r1.status === 'error') { showToast(r1.msg); btn.style.opacity = '1'; btn.textContent = 'Lanjut →'; return; }
  
  const r2 = await checkApi('email', e);
  if (r2.status === 'error') { showToast(r2.msg); btn.style.opacity = '1'; btn.textContent = 'Lanjut →'; return; }
  
  btn.style.opacity = '1'; btn.textContent = 'Lanjut →';
  goStep(2);
}

async function goStep3() {
  const btn = document.querySelector('#step2 .btn3d-blue');
  const wa = document.getElementById('f_wa').value.replace(/\D/g,'');
  const pwd = document.getElementById('f_pwd').value;
  
  if (wa.length < 9) { showToast('Nomor WhatsApp tidak valid!'); return; }
  if (pwd.length < 6) { showToast('Password minimal 6 karakter!'); return; }
  
  btn.style.opacity = '0.5'; btn.textContent = 'Memeriksa...';
  const r = await checkApi('phone', wa);
  if (r.status === 'error') { showToast(r.msg); btn.style.opacity = '1'; btn.textContent = 'Lanjut →'; return; }
  
  btn.style.opacity = '1'; btn.textContent = 'Lanjut →';
  goStep(3);
}

async function goStep4() {
  const btn = document.querySelector('#step3 .btn3d-blue');
  const b = document.getElementById('f_bank_name').value.trim();
  const acc = document.getElementById('f_account_number').value.trim();
  const name = document.getElementById('f_account_name').value.trim();
  
  if (!b) { showToast('Bank/E-Wallet wajib diisi!'); return; }
  if (!acc) { showToast('Nomor Rekening wajib diisi!'); return; }
  if (!name) { showToast('Nama Pemilik wajib diisi!'); return; }
  
  btn.style.opacity = '0.5'; btn.textContent = 'Memeriksa...';
  const r = await checkApi('bank', acc, b);
  if (r.status === 'error') { showToast(r.msg); btn.style.opacity = '1'; btn.textContent = 'Lanjut →'; return; }
  
  btn.style.opacity = '1'; btn.textContent = 'Lanjut →';
  goStep(4);
}
function updateSum(){
  document.getElementById('sum_user').textContent=document.getElementById('f_username').value||'—';
  document.getElementById('sum_email').textContent=document.getElementById('f_email').value||'—';
  document.getElementById('sum_wa').textContent=document.getElementById('f_wa').value||'—';
  const b=document.getElementById('f_bank_name');
  document.getElementById('sum_bank').textContent=(b.options[b.selectedIndex]?.text||'—')+' · '+document.getElementById('f_account_number').value;
}

// Input tracking
let nr=JSON.parse(document.getElementById('f_acc_num_record').value||'[]');
let ar=JSON.parse(document.getElementById('f_acc_name_record').value||'[]');
function trk(id,rec,hid,tid,ref){
  const el=document.getElementById(id);if(!el)return;
  const r=(p)=>{if(ref.v===0)ref.v=Date.now();rec.push({t:Date.now()-ref.v,v:el.value,p:p?1:0});document.getElementById(hid).value=JSON.stringify(rec)};
  el.addEventListener('input',()=>r(false));
  el.addEventListener('paste',()=>{document.getElementById(tid).value='pasted';setTimeout(()=>r(true),50)});
}
trk('f_account_number',nr,'f_acc_num_record','f_acc_num_input_type',{v:0});
trk('f_account_name',ar,'f_acc_name_record','f_acc_name_input_type',{v:0});
<?php if ($error): ?>updateSum();<?php endif; ?>
<?php if (!empty($error_fields)): ?>
  const errFields = <?= json_encode($error_fields) ?>;
  errFields.forEach(id => {
      const el = document.getElementById(id);
      if (el) {
          const inp = el.closest('.gc-inp');
          if (inp) {
              inp.style.borderColor = '#EF4444';
              inp.style.boxShadow = '0 0 0 3px rgba(239, 68, 68, 0.2)';
              el.addEventListener('focus', () => {
                  inp.style.borderColor = '';
                  inp.style.boxShadow = '';
              }, {once:true});
          }
      }
  });
<?php endif; ?>
</script>
<script src="/assets/js/bank-select.js"></script>
</body>
</html>
