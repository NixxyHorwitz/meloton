<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/auth.php';
staff_require('orderkuota');
require_once dirname(__DIR__) . '/lib/OrderKuota.php';

use YuF1Dev\OrderKuota;

$pageTitle = 'API OrderKuota';
$activePage = 'orderkuota';

// Create table if not exists
$pdo->exec("CREATE TABLE IF NOT EXISTS `orderkuota_accounts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(255) NOT NULL,
  `auth_token` varchar(500) DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

$flash = ''; $flashType = 'success';

// Handle Post Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'login') {
        $username = trim($_POST['username'] ?? '');
        $password = trim($_POST['password'] ?? '');
        if ($username && $password) {
            $ok = new OrderKuota();
            $res = $ok->loginRequest($username, $password);
            $data = json_decode($res, true);
            if (!empty($data['success'])) {
                // Check if OTP needed
                if (!empty($data['message']) && stripos($data['message'], 'OTP') !== false) {
                    $_SESSION['ok_wait_otp'] = $username;
                    $_SESSION['ok_wait_pass'] = $password;
                    $flash = 'Login berhasil, silakan masukkan OTP yang dikirimkan ke Anda.';
                } elseif (!empty($data['auth_token'])) {
                    // Logged in directly (rare but possible)
                    $pdo->prepare("DELETE FROM orderkuota_accounts")->execute();
                    $pdo->prepare("INSERT INTO orderkuota_accounts (username, auth_token) VALUES (?, ?)")->execute([$username, $data['auth_token']]);
                    $flash = 'Login berhasil tanpa OTP!';
                    unset($_SESSION['ok_wait_otp']);
                } else {
                    $_SESSION['ok_wait_otp'] = $username;
                    $_SESSION['ok_wait_pass'] = $password;
                    $flash = 'Silakan masukkan OTP.';
                }
            } else {
                $flash = 'Login gagal: ' . ($data['message'] ?? 'Error tidak diketahui.');
                $flashType = 'danger';
            }
        }
    } elseif ($action === 'verify_otp') {
        $username = $_SESSION['ok_wait_otp'] ?? '';
        $otp = trim($_POST['otp'] ?? '');
        if ($username && $otp) {
            $ok = new OrderKuota();
            $res = $ok->getAuthToken($username, $otp);
            $data = json_decode($res, true);
            if (!empty($data['success']) && !empty($data['results']['token'])) {
                $token = $data['results']['token'];
                $uname = $data['results']['auth_username'] ?? $username;
                $pdo->prepare("DELETE FROM orderkuota_accounts")->execute();
                $pdo->prepare("INSERT INTO orderkuota_accounts (username, auth_token) VALUES (?, ?)")->execute([$uname, $token]);
                $flash = 'Berhasil terhubung ke API OrderKuota!';
                unset($_SESSION['ok_wait_otp']);
                unset($_SESSION['ok_wait_pass']);
            } else {
                $flash = 'Verifikasi OTP gagal: ' . ($data['message'] ?? 'Kode salah.');
                $flashType = 'danger';
            }
        }
    } elseif ($action === 'cancel_otp') {
        unset($_SESSION['ok_wait_otp']);
        unset($_SESSION['ok_wait_pass']);
    } elseif ($action === 'logout') {
        $pdo->prepare("DELETE FROM orderkuota_accounts")->execute();
        $flash = 'Berhasil memutus koneksi API OrderKuota.';
    }
}

// Check current account
$stmt = $pdo->query("SELECT * FROM orderkuota_accounts ORDER BY id DESC LIMIT 1");
$account = $stmt->fetch();

$balanceData = null;
if ($account && !empty($account['auth_token'])) {
    $ok = new OrderKuota($account['username'], $account['auth_token']);
    $res = $ok->getBalance();
    $balanceData = json_decode($res, true);
}

require_once __DIR__ . '/partials/header.php';
?>

<div class="row">
  <div class="col-12 col-md-8 mx-auto">
    <?php if ($flash): ?>
    <div class="alert alert-<?= $flashType ?> alert-dismissible fade show" role="alert">
      <?= htmlspecialchars($flash) ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
    <?php endif; ?>

    <div class="c-card">
      <div class="c-card-header bg-dark text-white d-flex align-items-center gap-2">
        <svg width="24" height="24" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
        <span class="c-card-title m-0" style="font-size:16px">Status Koneksi OrderKuota</span>
      </div>
      <div class="c-card-body">
        <?php if ($account && !empty($account['auth_token'])): ?>
            <!-- TERHUBUNG -->
            <div class="alert alert-success d-flex align-items-center gap-2 mb-4">
                <svg width="24" height="24" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                <strong>Terhubung sebagai <?= htmlspecialchars($account['username']) ?></strong>
            </div>

            <?php if ($balanceData && !empty($balanceData['success'])): ?>
            <div class="row g-3 mb-4">
                <div class="col-12 col-sm-4">
                    <div class="c-stat border-warning">
                        <div class="c-stat__lbl">Saldo Utama</div>
                        <div class="c-stat__val text-warning"><?= htmlspecialchars($balanceData['balance_str'] ?? 'Rp 0') ?></div>
                    </div>
                </div>
                <div class="col-12 col-sm-4">
                    <div class="c-stat border-info">
                        <div class="c-stat__lbl">Saldo QRIS</div>
                        <div class="c-stat__val text-info"><?= htmlspecialchars($balanceData['qris_balance_str'] ?? 'Rp 0') ?></div>
                    </div>
                </div>
                <div class="col-12 col-sm-4">
                    <div class="c-stat border-success">
                        <div class="c-stat__lbl">Poin Reward</div>
                        <div class="c-stat__val text-success"><?= htmlspecialchars($balanceData['point'] ?? '0') ?></div>
                    </div>
                </div>
            </div>
            <?php else: ?>
            <div class="alert alert-danger mb-4">
                Gagal mengambil data Saldo. Token mungkin sudah kedaluwarsa atau terjadi masalah koneksi.
            </div>
            <?php endif; ?>

            <form method="POST" onsubmit="return confirm('Yakin ingin memutus koneksi API ini?');">
                <input type="hidden" name="action" value="logout">
                <button type="submit" class="btn btn-danger btn-sm">Disconnect / Logout API</button>
            </form>

        <?php elseif (!empty($_SESSION['ok_wait_otp'])): ?>
            <!-- OTP STATE -->
            <div class="alert alert-warning mb-4">
                OTP telah dikirim ke nomor/email OrderKuota Anda (<?= htmlspecialchars($_SESSION['ok_wait_otp']) ?>). Silakan masukkan kode tersebut di bawah ini.
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="verify_otp">
                <div class="mb-3">
                    <label class="c-label">Kode OTP</label>
                    <input type="text" name="otp" class="c-form-control" required placeholder="Contoh: 123456" autocomplete="off">
                </div>
                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-primary" style="background:var(--brand);border:none;">Verifikasi OTP</button>
                    <button type="submit" name="action" value="cancel_otp" class="btn btn-secondary" formnovalidate>Batal</button>
                </div>
            </form>
            
        <?php else: ?>
            <!-- LOGIN STATE -->
            <p class="text-muted mb-4">API OrderKuota belum terhubung. Silakan login menggunakan akun OrderKuota Anda.</p>
            <form method="POST">
                <input type="hidden" name="action" value="login">
                <div class="mb-3">
                    <label class="c-label">Nomor HP / Username OrderKuota</label>
                    <input type="text" name="username" class="c-form-control" required placeholder="08xxxxxx">
                </div>
                <div class="mb-4">
                    <label class="c-label">Password / PIN</label>
                    <input type="password" name="password" class="c-form-control" required placeholder="Masukkan password">
                </div>
                <button type="submit" class="btn btn-primary w-100" style="background:var(--brand);border:none;font-weight:700">Login OrderKuota</button>
            </form>
        <?php endif; ?>
      </div>
    </div>

  </div>
</div>

<?php require_once __DIR__ . '/partials/footer.php'; ?>
