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

/* Outer yellow card */
.gc{background:linear-gradient(180deg,#ffcc00 0%,#f0a500 100%);border:4px solid #c47f17;border-radius:28px;box-shadow:0 8px 0 #a06a10,0 12px 24px rgba(0,0,0,.4);padding:48px 12px 12px;position:relative;width:100%;max-width:380px}
.gc-hd{position:absolute;top:0;left:0;right:0;height:48px;display:flex;align-items:center;justify-content:center;font-weight:900;font-size:18px;color:#fff;text-shadow:0 2px 0 #c47f17}
.gc-x{position:absolute;right:12px;top:10px;width:28px;height:28px;background:#e08600;color:#fff;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:18px;font-weight:900;text-decoration:none;border:2px solid #c47f17;box-shadow:inset 0 -2px 0 rgba(0,0,0,.15)}

/* Inner cream card */
.gc-in{background:#fef8e8;border:3px solid #e8d5a3;border-radius:20px;padding:28px 20px 20px}
.gc-title{font-weight:900;font-size:17px;color:#6d3a0a;text-align:center;margin-bottom:20px;line-height:1.35}

/* Error alert */
.gc-err{background:#fee2e2;border:2px solid #f87171;border-radius:12px;padding:10px 14px;font-size:12px;font-weight:700;color:#991b1b;margin-bottom:16px;text-align:center}

/* Label */
.gc-lbl{font-size:12px;font-weight:800;color:#9a6b3a;margin-bottom:5px;display:block}

/* Input row */
.gc-inp{display:flex;align-items:center;gap:10px;border:2.5px solid #d4a64a;border-radius:14px;padding:11px 14px;background:#fff;margin-bottom:14px}
.gc-inp:focus-within{border-color:#c47f17;box-shadow:0 0 0 3px rgba(196,127,23,.15)}
.gc-inp svg{color:#c9a24e;flex-shrink:0}
.gc-inp input{border:none;outline:none;background:none;flex:1;font-size:13px;font-weight:700;color:#5a3510;font-family:inherit}
.gc-inp input::placeholder{color:#c4a370;font-weight:600}
.gc-inp .eye{background:none;border:none;font-size:14px;cursor:pointer;padding:0}

/* Glossy 3D button */
.btn3d{width:100%;border:none;border-radius:28px;padding:13px;font-weight:900;font-size:15px;font-family:inherit;display:flex;align-items:center;justify-content:center;gap:6px;cursor:pointer;position:relative;overflow:hidden;text-decoration:none;transition:transform .08s}
.btn3d::after{content:'';position:absolute;top:3px;left:12%;right:12%;height:40%;background:linear-gradient(180deg,rgba(255,255,255,.45) 0%,rgba(255,255,255,0) 100%);border-radius:20px;pointer-events:none}
.btn3d:active{transform:translateY(4px)}
.btn3d-blue{background:linear-gradient(180deg,#5bb8f5 0%,#2e86de 50%,#2574c4 100%);color:#fff;box-shadow:0 5px 0 #1a5fa0,0 7px 12px rgba(0,0,0,.25);border:2px solid #6ec6ff;text-shadow:0 1px 2px rgba(0,0,0,.2)}
.btn3d-blue:active{box-shadow:0 1px 0 #1a5fa0}
.btn3d-gold{background:linear-gradient(180deg,#ffe082 0%,#ffca28 50%,#f0b400 100%);color:#8b5e0a;box-shadow:0 5px 0 #c47f17,0 7px 12px rgba(0,0,0,.2);border:2px solid #ffe599}
.btn3d-gold:active{box-shadow:0 1px 0 #c47f17}

/* Footer text */
.gc-ft{text-align:center;font-size:12px;color:#8b6914;font-weight:700;margin-top:14px}
.gc-ft a{color:#6d3a0a;font-weight:800;text-decoration:underline}
</style>
</head>
<body>

<div class="gc">
  <div class="gc-hd">Selamat Datang</div>
  <a href="/" class="gc-x">✕</a>

  <div class="gc-in">
    <div class="gc-title">Masuk ke akunmu<br>dan mulai nonton!</div>

    <?php if ($error): ?>
    <div class="gc-err">⚠️ <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST">
      <?= csrf_field() ?>

      <label class="gc-lbl">Username / Email</label>
      <div class="gc-inp">
        <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
        <input type="text" name="login" value="<?= htmlspecialchars($_POST['login'] ?? '') ?>" placeholder="username atau email" autofocus autocomplete="username">
      </div>

      <label class="gc-lbl">Password</label>
      <div class="gc-inp">
        <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>
        <input type="password" id="pwd" name="password" placeholder="Password kamu" autocomplete="current-password">
        <button type="button" class="eye" onclick="let p=document.getElementById('pwd');p.type=p.type==='password'?'text':'password'">👁</button>
      </div>

      <button type="submit" class="btn3d btn3d-blue" style="margin-top:8px">Masuk →</button>
      <a href="/register" class="btn3d btn3d-gold" style="margin-top:10px">✨ Daftar Gratis</a>
    </form>

    <div class="gc-ft">Belum punya akun? <a href="/register">Daftar gratis</a></div>
  </div>
</div>

</body>
</html>
