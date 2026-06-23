<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/bootstrap.php';
if (!empty($_SESSION['admin']) || !empty($_SESSION['staff_id'])) redirect('/console/');
csrf_enforce();

$error = '';
$next  = preg_replace('/[^\\/a-zA-Z0-9_.?=&%-]/', '', $_GET['next'] ?? '/console/');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    // 1. Try head admin
    $stmt = $pdo->prepare("SELECT * FROM admins WHERE username=?");
    $stmt->execute([$username]);
    $admin = $stmt->fetch();
    if ($admin && password_verify($password, $admin['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['admin'] = ['id' => $admin['id'], 'username' => $admin['username']];
        $_SESSION['admin_last_rotate'] = time();
        redirect($next);
    }

    // 2. Try staff
    $stmt2 = $pdo->prepare("SELECT * FROM staff WHERE username=? AND is_active=1");
    $stmt2->execute([$username]);
    $staff = $stmt2->fetch();
    if ($staff && password_verify($password, $staff['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['staff_id']          = $staff['id'];
        $_SESSION['staff_username']    = $staff['username'];
        $_SESSION['staff_display']     = $staff['display_name'];
        $_SESSION['staff_last_rotate'] = time();
        // Load permissions
        $sp = $pdo->prepare("SELECT p.permission FROM staff_role_permissions p WHERE p.role_id = ?");
        $sp->execute([$staff['role_id']]);
        $_SESSION['staff_permissions'] = $sp->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $pdo->prepare("UPDATE staff SET last_login=NOW() WHERE id=?")->execute([$staff['id']]);
        // Need auth.php helpers loaded to call staff_home_url()
        // But auth.php redirects if not logged in — load bootstrap only
        require_once dirname(__DIR__) . '/bootstrap.php';
        // Manually compute first accessible page
        $perm_priority = ['dashboard','withdrawals','deposits','users','user_txns','videos','upgrades',
            'livechat','memberships','redeem','analytics','video_analytics',
            'notifications','panduan','contacts','payment','seo','settings','orders'];
        $perm_urls = [
            'dashboard'=>'/console/','withdrawals'=>'/console/withdrawals.php',
            'deposits'=>'/console/deposits.php','users'=>'/console/users.php',
            'user_txns'=>'/console/user_txns','videos'=>'/console/videos.php','upgrades'=>'/console/upgrades.php',
            'livechat'=>'/console/livechat.php','memberships'=>'/console/memberships.php',
            'redeem'=>'/console/redeem.php','analytics'=>'/console/analytics.php',
            'video_analytics'=>'/console/video_analytics.php',
            'notifications'=>'/console/notifications','panduan'=>'/console/panduan',
            'contacts'=>'/console/contacts','payment'=>'/console/payment.php',
            'seo'=>'/console/seo.php','settings'=>'/console/settings.php','orders'=>'/console/orders.php',
        ];
        $staff_perms = $_SESSION['staff_permissions'];
        $home = '/console/';
        foreach ($perm_priority as $p) {
            if (in_array($p, $staff_perms, true)) { $home = $perm_urls[$p]; break; }
        }
        redirect($next !== '/console/' ? $next : $home);
    }

    $error = 'Username atau password salah.';
}

$_favicon    = setting($pdo, 'favicon_path', '');
$absolute_fav = $_favicon ? (preg_match('~^https?://~', $_favicon) ? $_favicon : '/' . ltrim($_favicon, '/')) : '';
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Admin Login — Meloton</title>
<?php if ($absolute_fav): ?>
<link rel="icon" href="<?= htmlspecialchars($absolute_fav) ?>?v=<?= @filemtime(dirname(__DIR__) . $_favicon) ?: time() ?>">
<link rel="apple-touch-icon" href="<?= htmlspecialchars($absolute_fav) ?>?v=<?= @filemtime(dirname(__DIR__) . $_favicon) ?: time() ?>">
<?php endif; ?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
*{font-family:'Inter',sans-serif}
body{background:#0f1117;min-height:100vh;display:flex;align-items:center;justify-content:center}
.login-card{background:#1a1d27;border:1px solid #2d3149;border-radius:16px;padding:40px;width:100%;max-width:380px}
.brand-icon{width:52px;height:52px;background:linear-gradient(135deg,#FF6B35,#FF8C42);border-radius:14px;display:flex;align-items:center;justify-content:center;margin:0 auto 14px}
.form-control{background:#252836;border:1.5px solid #2d3149;color:#e0e0e0;border-radius:10px}
.form-control:focus{background:#252836;border-color:#FF6B35;color:#fff;box-shadow:0 0 0 3px rgba(255,107,53,.15)}
.btn-login{background:linear-gradient(135deg,#FF6B35,#FF8C42);border:none;border-radius:10px;font-weight:700;padding:12px;font-size:15px}
</style>
</head>
<body>
<div class="login-card text-center">
  <div class="brand-icon">
    <svg width="26" height="26" fill="none" viewBox="0 0 24 24" stroke="#fff" stroke-width="2.5"><polygon points="23 7 16 12 23 17 23 7"/><rect x="1" y="5" width="15" height="14" rx="2"/></svg>
  </div>
  <h4 class="text-white fw-bold mb-1">Meloton</h4>
  <p class="text-secondary mb-4" style="font-size:13px">Admin Console</p>
  <?php if ($error): ?>
  <div class="alert alert-danger py-2" style="font-size:13px;border-radius:10px"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>
  <form method="POST" class="text-start">
    <?= csrf_field() ?>
    <div class="mb-3">
      <label class="form-label text-secondary" style="font-size:13px">Username</label>
      <input type="text" name="username" class="form-control" value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" autofocus required>
    </div>
    <div class="mb-4">
      <label class="form-label text-secondary" style="font-size:13px">Password</label>
      <input type="password" name="password" class="form-control" required>
    </div>
    <button type="submit" class="btn btn-login w-100 text-white">Masuk ke Console</button>
  </form>
</div>
</body>
</html>
