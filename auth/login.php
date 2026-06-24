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
/* Game UI Login Styles */
.auth-page {
  background-color: #faebd7;
}
.auth-card {
  border: 4px solid var(--ink);
  border-radius: 20px;
  box-shadow: 0 8px 0 var(--ink);
  padding: 32px 24px;
  background: #ffffff;
  overflow: hidden;
  position: relative;
}
.deco-bar {
  position: absolute;
  top: 0; left: 0; right: 0;
  height: 6px;
  background: repeating-linear-gradient(90deg, #f1c40f 0, #f1c40f 30px, #d1fae5 30px, #d1fae5 60px, #ede9fe 60px, #ede9fe 90px);
}
.auth-logo-img {
  width: 42px; height: 42px;
  object-fit: contain;
  border-radius: 10px;
  border: 2px solid var(--ink);
  padding: 2px;
  background: #fff;
  flex-shrink: 0;
}
.auth-title {
  font-weight: 900;
  font-size: 18px;
  color: var(--ink);
  line-height: 1.2;
}
.auth-subtitle {
  font-size: 11px;
  color: #6b7280;
  font-weight: 700;
  margin-top: 2px;
}
.form-label {
  font-weight: 800;
  font-size: 12px;
  color: #a16238;
  margin-bottom: 6px;
  display: block;
}
.input-wrap {
  display: flex;
  align-items: center;
  gap: 10px;
  border: 3px solid var(--ink);
  border-radius: 16px;
  padding: 12px 14px;
  background: #ffffff;
  margin-bottom: 16px;
  transition: all 0.2s;
}
.input-wrap:focus-within {
  border-color: #d97706;
}
.input-icon {
  color: #9ca3af;
  flex-shrink: 0;
}
.form-control {
  border: none;
  outline: none;
  background: transparent;
  flex: 1;
  font-size: 14px;
  font-weight: 600;
  color: var(--ink);
}
.form-control::placeholder {
  color: #9ca3af;
  font-weight: 500;
}
.input-toggle {
  background: none;
  border: 1px solid var(--ink);
  border-radius: 4px;
  padding: 2px 4px;
  cursor: pointer;
  font-size: 10px;
  color: var(--ink);
  display: flex;
  align-items: center;
  justify-content: center;
}
.btn-masuk {
  background: #f1f5f9;
  color: #000;
  font-weight: 900;
  font-size: 14px;
  border: none;
  border-radius: 24px;
  padding: 14px;
  width: 100%;
  cursor: pointer;
  transition: transform 0.1s;
}
.btn-masuk:active { transform: scale(0.98); }
.btn-daftar {
  background: #ffffff;
  color: #0000ff;
  font-weight: 900;
  font-size: 14px;
  border: 3px solid var(--ink);
  border-radius: 24px;
  padding: 14px;
  width: 100%;
  cursor: pointer;
  box-shadow: 0 4px 0 var(--ink);
  text-decoration: none;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  transition: transform 0.1s, box-shadow 0.1s;
}
.btn-daftar:active {
  transform: translateY(2px);
  box-shadow: 0 2px 0 var(--ink);
}
.auth-switch {
  text-align: center;
  margin-top: 24px;
  font-size: 13px;
  color: #8c5b35;
  font-weight: 600;
}
.auth-switch a {
  color: #ff8c00;
  font-weight: 800;
  text-decoration: none;
}
</style>
</head>
<div class="auth-page">
  <div class="auth-card">
    <div class="deco-bar"></div>

    <div style="display:flex;align-items:center;gap:12px;margin-bottom:24px;margin-top:12px;">
      <?php if ($_favicon): ?>
      <img src="<?= htmlspecialchars($_favicon) ?>" alt="" class="auth-logo-img">
      <?php else: ?>
      <div class="auth-logo-img" style="display:flex;align-items:center;justify-content:center;font-size:20px;">🎬</div>
      <?php endif; ?>
      <div>
        <div class="auth-title"><?= htmlspecialchars($_seo_title) ?></div>
        <div class="auth-subtitle">Tonton video &amp; kumpulkan reward</div>
      </div>
    </div>

    <?php if ($error): ?>
    <div class="alert alert--error" style="margin-bottom:16px;">⚠️ <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST">
      <?= csrf_field() ?>
      
      <label class="form-label">Username / Email</label>
      <div class="input-wrap">
        <svg class="input-icon" width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
        <input class="form-control" type="text" name="login"
          value="<?= htmlspecialchars($_POST['login'] ?? '') ?>"
          placeholder="username atau email" autofocus autocomplete="username">
      </div>
      
      <label class="form-label">Password</label>
      <div class="input-wrap">
        <svg class="input-icon" width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>
        <input class="form-control" type="password" id="pwd" name="password"
          placeholder="Password kamu" autocomplete="current-password">
        <button type="button" class="input-toggle" onclick="document.getElementById('pwd').type=document.getElementById('pwd').type==='password'?'text':'password'" title="Tampilkan/Sembunyikan">👁</button>
      </div>

      <div style="display:flex; flex-direction:column; gap:12px; margin-top:24px;">
        <button type="submit" class="btn-masuk">🚀 MASUK</button>
        <a href="/register" class="btn-daftar">✨ DAFTAR GRATIS</a>
      </div>
    </form>

    <div class="auth-switch">Belum punya akun? <a href="/register">Daftar gratis →</a></div>
  </div>
</div>
</body>
</html>
