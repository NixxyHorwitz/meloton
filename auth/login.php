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
        $error = "Akun terkunci. Coba lagi dalam {$wait} menit.";
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
        $error = 'Terlalu banyak percobaan. Coba lagi dalam 10 menit.';
    } else {
        $left  = 5 - $new_att;
        $error = "Username/email atau password salah. Sisa percobaan: {$left}";
    }
}
end_login:
?>
<?php
// Load SEO settings
$_seo_title  = setting($pdo, 'seo_title', 'Meloton');
$_seo_desc   = setting($pdo, 'seo_description', 'Tonton video dan kumpulkan reward di Meloton!');
$_seo_kw     = setting($pdo, 'seo_keywords', '');
$_seo_og     = setting($pdo, 'seo_og_image', '');
$_seo_robots = setting($pdo, 'seo_robots', 'index,follow');
$_seo_og_type = setting($pdo, 'seo_og_type', 'website');
$_favicon    = setting($pdo, 'favicon_path', '');
$_page_title = 'Masuk — ' . $_seo_title;
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
/* Bubble Game UI Login Styles */
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
  max-width: 400px;
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
.auth-icon-money {
  font-size: 56px; line-height: 1; margin-bottom: 8px;
  filter: drop-shadow(0 4px 0 rgba(0,0,0,0.1));
}
.auth-reward-text {
  font-weight: 900; font-size: 24px; color: #8b4513; margin-bottom: 24px;
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

.btn-glossy {
  width: 100%; border: none; border-radius: 30px; padding: 14px;
  font-weight: 900; font-size: 16px; display: flex; align-items: center; justify-content: center; gap: 8px;
  position: relative; overflow: hidden; cursor: pointer; text-decoration: none; margin-bottom: 16px;
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

.auth-footer {
  text-align: center; font-size: 11px; color: #8b4513; font-weight: 600; margin-top: 8px;
}
.auth-footer a { color: #8b4513; text-decoration: underline; }

.input-icon { color: #cc8e00; }
.input-toggle { background: none; border: none; font-size: 16px; cursor: pointer; }
</style>
</head>
<body>
<div class="auth-page">
  <div class="auth-card">
    <div class="auth-card-header">Selamat Datang</div>
    <a href="/" class="auth-card-close">×</a>
    
    <div class="auth-card-inner">
      <div class="auth-title-inner">Login untuk menarik<br>uang.</div>
      
      <?php if ($error): ?>
      <div class="alert alert--error" style="margin-bottom:16px;width:100%;border-radius:12px;">⚠️ <?= htmlspecialchars($error) ?></div>
      <?php endif; ?>

      <form method="POST" style="width: 100%;">
        <?= csrf_field() ?>
        
        <div class="input-wrap">
          <svg class="input-icon" width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
          <input class="form-control" type="text" name="login"
            value="<?= htmlspecialchars($_POST['login'] ?? '') ?>"
            placeholder="Username atau Email" autofocus autocomplete="username">
        </div>
        
        <div class="input-wrap">
          <svg class="input-icon" width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>
          <input class="form-control" type="password" id="pwd" name="password"
            placeholder="Password Kamu" autocomplete="current-password">
          <button type="button" class="input-toggle" onclick="document.getElementById('pwd').type=document.getElementById('pwd').type==='password'?'text':'password'" title="Tampilkan/Sembunyikan">👁</button>
        </div>

        <button type="submit" class="btn-glossy btn-blue" style="margin-top:12px;">
          🚀 MASUK
        </button>
        <a href="/register" class="btn-glossy btn-yellow">
          ✨ DAFTAR GRATIS
        </a>
      </form>

      <div class="auth-footer">
        Belum punya akun? <a href="/register">Daftar sekarang</a>
      </div>
    </div>
  </div>
</div>
</body>
</html>
