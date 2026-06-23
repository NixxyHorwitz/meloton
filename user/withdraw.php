<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/auth/guard.php';

$flash = $flashType = '';
$wd_lock_notice = setting($pdo, 'wd_lock_notice', 'Penarikan hanya bisa dilakukan pada jam tertentu.');
$wd_lock_start  = setting($pdo, 'wd_lock_start', '');
$wd_lock_end    = setting($pdo, 'wd_lock_end', '');

// Fetch membership min/max WD — only if membership is still active (not expired)
$user_mem = null;
$membership_active = $user['membership_id']
    && $user['membership_expires_at']
    && strtotime((string)$user['membership_expires_at']) > time();

if ($membership_active) {
    $stmt = $pdo->prepare("SELECT name, min_wd, max_wd, wd_hold, allow_edit_bank FROM memberships WHERE id = ? AND is_active = 1");
    $stmt->execute([$user['membership_id']]);
    $user_mem = $stmt->fetch() ?: null;
}

// Fallback ke paket gratis jika tidak ada membership aktif
if (!$user_mem) {
    $stmt = $pdo->prepare("SELECT name, min_wd, max_wd, wd_hold, allow_edit_bank FROM memberships WHERE price = 0 AND is_active = 1 ORDER BY sort_order ASC LIMIT 1");
    $stmt->execute();
    $user_mem = $stmt->fetch() ?: null;
}

$min_withdraw  = $user_mem ? (float)$user_mem['min_wd'] : 0;
$max_withdraw  = $user_mem ? (float)$user_mem['max_wd'] : 0;
$max_available = min((float)$user['balance_wd'], $max_withdraw > 0 ? $max_withdraw : (float)$user['balance_wd']);

$predefined_amounts = [10000, 20000, 25000, 30000, 40000, 50000, 75000, 100000, 150000, 200000, 250000, 300000, 400000, 500000, 750000, 1000000, 1500000, 2000000, 2500000, 3000000, 5000000];

if ($min_withdraw > 0 && !in_array((int)$min_withdraw, $predefined_amounts, true)) {
    $predefined_amounts[] = (int)$min_withdraw;
}
if ($max_withdraw > 0 && !in_array((int)$max_withdraw, $predefined_amounts, true)) {
    $predefined_amounts[] = (int)$max_withdraw;
}

sort($predefined_amounts);

$has_bank = !empty($user['bank_name']) && !empty($user['account_number']) && !empty($user['account_name']);

$wd_locked = is_wd_locked($pdo);

// Level block — only enforced if admin enables the toggle
$wd_require_level = setting($pdo, 'wd_require_level', '0') === '1';
$wd_min_level  = (int) setting($pdo, 'wd_min_level', '0');
$user_level    = user_membership_level($pdo, $user);
$level_blocked = $wd_require_level && $wd_min_level > 0 && $user_level < $wd_min_level;

$available_amounts = [];
foreach ($predefined_amounts as $amt) {
    if ($level_blocked) {
        // Jika terblokir karena butuh upgrade, pamerkan opsi s/d 500rb
        if ($amt <= 500000) {
            $available_amounts[] = $amt;
        }
    } else {
        if ($amt >= $min_withdraw && ($max_withdraw == 0 || $amt <= $max_withdraw)) {
            $available_amounts[] = $amt;
        }
    }
}

$min_level_name = '';
if ($wd_require_level && $wd_min_level > 0) {
    $lv = $pdo->prepare("SELECT name FROM memberships WHERE sort_order=? AND is_active=1 LIMIT 1");
    $lv->execute([$wd_min_level]);
    $min_level_name = $lv->fetchColumn() ?: "Level {$wd_min_level}";
}

// Cek apakah ada WD pending
$pending_wd = $pdo->prepare("SELECT id FROM withdrawals WHERE user_id=? AND status='pending' LIMIT 1");
$pending_wd->execute([$user['id']]);
$has_pending_wd = (bool)$pending_wd->fetchColumn();

// Cek batas WD untuk akun free
$is_free_level = !$membership_active;
$free_wd_limit_reached = false;
$free_wrong_bank = false;

if ($is_free_level) {
    if ($has_bank && strtolower(trim($user['bank_name'])) !== 'dana') {
        $free_wrong_bank = true;
    }
    
    $wd_cnt = $pdo->prepare("SELECT COUNT(*) FROM withdrawals WHERE user_id=? AND status='approved'");
    $wd_cnt->execute([$user['id']]);
    if ($wd_cnt->fetchColumn() >= 1) {
        $free_wd_limit_reached = true;
    }
}

// ── Double-submit prevention ──────────────────────────────────────────────
$_ftk_wd = 'wd_form_token';
if (empty($_SESSION[$_ftk_wd])) $_SESSION[$_ftk_wd] = bin2hex(random_bytes(16));
$_form_token_wd = $_SESSION[$_ftk_wd];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submitted_ftk_wd = $_POST['form_token'] ?? '';
    if (!hash_equals($_SESSION[$_ftk_wd] ?? '', $submitted_ftk_wd)) {
        $flash = '⚠️ Request kamu gagal diproses atau gak valid. Coba refresh halaman dulu ya!';
        $flashType = 'error';
        goto end_wd;
    }
    unset($_SESSION[$_ftk_wd]);

    if (!$user['can_withdraw']) {
        $flash = '❌ Akses withdraw kamu dibatasi nih. Hubungi admin yuk buat info lebih lanjut!'; $flashType = 'error';
    } elseif ($wd_locked) {
        $flash = '⏰ ' . $wd_lock_notice; $flashType = 'error';
    } elseif ($free_wd_limit_reached) {
        $flash = '❌ Akun Free maksimal WD 1 kali. Yuk upgrade level buat tarik dana sepuasnya!'; $flashType = 'error';
    } elseif ($free_wrong_bank) {
        $flash = '❌ Akun Free hanya bisa menarik ke e-wallet DANA. Silakan ganti rekening Anda!'; $flashType = 'error';
    } elseif ($has_pending_wd) {
        $flash = '⏳ Kamu masih punya request WD yang lagi diproses nih. Tunggu kelar dulu ya!'; $flashType = 'error';
    } elseif (!empty($user_mem['is_wd_disabled'])) {
        $flash = '🔴 Penarikan untuk level Anda saat ini sedang ditutup (Maintenance). Silakan upgrade level Anda!'; $flashType = 'error';
    } elseif ($level_blocked) {
        $flash = "Upgrade ke {$min_level_name} dulu yuk biar bisa tarik saldo!"; $flashType = 'error';
    } elseif ($is_free_level && strtotime($user['created_at']) > strtotime('-1 day')) {
        $flash = '❌ Akun harus berumur minimal 1 hari untuk bisa melakukan WD di level Gratis!'; $flashType = 'error';
    } else {
        $amount  = (float) preg_replace('/\D/', '', $_POST['amount'] ?? '0');
        
        $bank    = $has_bank ? $user['bank_name'] : trim($_POST['bank_name'] ?? '');
        $accnum  = $has_bank ? $user['account_number'] : trim($_POST['account_number'] ?? '');
        $accname = $has_bank ? $user['account_name'] : trim($_POST['account_name'] ?? '');

        if (!in_array((int)$amount, $available_amounts, true)) {
            $flash = 'Nominal penarikan gak valid nih. Harus pilih dari daftar ya!'; $flashType = 'error';
        } elseif ($is_free_level && strtolower($bank) !== 'dana') {
            $flash = 'Akun Free hanya bisa menggunakan e-wallet DANA.'; $flashType = 'error';
        } elseif ($amount < $min_withdraw) {
            $flash = 'Minimal withdraw ' . format_rp($min_withdraw) . ' ya.'; $flashType = 'error';
        } elseif ($max_withdraw > 0 && $amount > $max_withdraw) {
            $flash = 'Maksimal withdraw ' . format_rp($max_withdraw) . ' ya.'; $flashType = 'error';
        } elseif ($amount > (float)$user['balance_wd']) {
            $flash = 'Saldo penarikan kamu gak cukup nih.'; $flashType = 'error';
        } elseif (!$bank || !$accnum || !$accname) {
            $flash = 'Lengkapi dulu data rekeningmu ya.'; $flashType = 'error';
        } else {
            $pdo->beginTransaction();
            if (!$has_bank) {
                $pdo->prepare("UPDATE users SET bank_name=?, account_number=?, account_name=? WHERE id=?")->execute([$bank, $accnum, $accname, $user['id']]);
                $has_bank = true;
                $user['bank_name'] = $bank;
                $user['account_number'] = $accnum;
                $user['account_name'] = $accname;
            }
            $is_auto_hold = (isset($user_mem['wd_hold']) && $user_mem['wd_hold'] == 1);
            $wd_status = $is_auto_hold ? 'hold' : 'pending';
            $admin_note = null;
            
            $pdo->prepare("UPDATE users SET balance_wd=balance_wd-? WHERE id=?")->execute([$amount, $user['id']]);
            $pdo->prepare("INSERT INTO withdrawals (user_id,amount,bank_name,account_number,account_name,status,admin_note) VALUES (?,?,?,?,?,?,?)")
                ->execute([$user['id'], $amount, $bank, $accnum, $accname, $wd_status, $admin_note]);
            $wd_id = $pdo->lastInsertId();
            $pdo->commit();
            $us = $pdo->prepare("SELECT * FROM users WHERE id=?"); $us->execute([$user['id']]); $user = $us->fetch();
            
            $levelInfo = $user_mem ? ($user_mem['name'] ?? 'Free') : 'Free';
            $wdHoldNote = $is_auto_hold ? ' ⏳ (Auto Hold Scheduled)' : '';
            $msg = "<b>💸 WITHDRAW BARU</b>\n👤 User: {$user['username']}\n🏅 Level: {$levelInfo}{$wdHoldNote}\n💰 Amount: " . format_rp((float)$amount) . "\n🏦 Bank: {$bank} - {$accnum}\n👨‍💼 a/n: {$accname}\n📋 Status: " . ucfirst($wd_status);
            $kb = [
                [['text'=>'✅ Approve', 'callback_data'=>'wd_approve_'.$wd_id], ['text'=>'❌ Reject', 'callback_data'=>'wd_reject_'.$wd_id]],
                [['text'=>'⏸ Hold (Selesai non-refund)', 'callback_data'=>'wd_hold_'.$wd_id]],
                [['text'=>'🔄 Refresh Status', 'callback_data'=>'refresh_wd_'.$wd_id]]
            ];
            send_telegram_notif($pdo, $msg, $kb, 'wd');
            
            // Regenerate token
            $_SESSION[$_ftk_wd] = bin2hex(random_bytes(16));
            $flash = '✅ Request withdraw berhasil dikirim! Proses 1-10 menit ya.';
            $flashType = 'success';
        }
    }
    
    if (isset($_POST['ajax'])) {
        header('Content-Type: application/json');
        if ($flashType === 'error' || $flash === '') {
            echo json_encode(['error' => $flash ?: 'Terjadi kesalahan.']);
        } else {
            echo json_encode(['ok' => true, 'message' => $flash]);
        }
        exit;
    }
}
end_wd:

$wds = $pdo->prepare("SELECT * FROM withdrawals WHERE user_id=? ORDER BY created_at DESC LIMIT 6");
$wds->execute([$user['id']]);
$wds = $wds->fetchAll();

$channels = $pdo->query("SELECT name, logo FROM payment_channels WHERE logo IS NOT NULL AND logo != ''")->fetchAll();
$channel_logos = [];
foreach ($channels as $c) {
    $channel_logos[strtolower($c['name'])] = $c['logo'];
}

$stmtPendingBank = $pdo->prepare("SELECT id FROM admin_requests WHERE user_id=? AND type='change_bank' AND status='pending'");
$stmtPendingBank->execute([$user['id']]);
$has_pending_bank = (bool)$stmtPendingBank->fetchColumn();

$wd_estimation = '';
if ($wd_lock_start && $wd_lock_end) {
    $now_ts = time();
    $s_ts = strtotime(date('Y-m-d ') . $wd_lock_start);
    $e_ts = strtotime(date('Y-m-d ') . $wd_lock_end);
    
    if ($wd_locked) {
        if ($e_ts <= $now_ts) $e_ts += 86400;
        $diff = $e_ts - $now_ts;
        $h = floor($diff / 3600);
        $m = floor(($diff % 3600) / 60);
        $wd_estimation = "Penarikan saat ini <strong>DITUTUP</strong>. Akan dibuka dalam <strong>{$h} jam {$m} menit</strong>.";
    } else {
        $wd_estimation = "✅ Penarikan Buka (Tutup Jam " . date('H:i', strtotime($wd_lock_start)) . ")";
    }
}

$pageTitle  = 'Withdraw — NontonKuy';
$activePage = 'withdraw';
require dirname(__DIR__) . '/partials/header.php';
?>

<style>
/* Trusted Neo-Brutalism Theme */
.wd-bal { background: var(--yellow, #eab308); color: var(--ink); border: 2.5px solid var(--ink); border-radius: 12px; box-shadow: 3px 3px 0 var(--ink); padding: 12px 14px; margin-bottom: 12px; position: relative; overflow: hidden; }
.wd-bal::after { content:''; position:absolute; top:-20px; right:-20px; width:70px; height:70px; background: rgba(0,0,0,0.05); border-radius: 50%; }
.wd-bal__lbl { font-size: 11px; font-weight: 800; color: rgba(0,0,0,0.6); margin-bottom: 2px; display: flex; align-items: center; gap: 4px; }
.wd-bal__val { font-size: 24px; font-weight: 900; letter-spacing: -0.5px; }

.qty-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 6px; margin-bottom: 12px; }
.qty-btn { font-size: 11px; font-weight: 800; padding: 8px 4px; text-align: center; background: #fff; border: 2px solid var(--ink); border-radius: 8px; box-shadow: 2px 2px 0 var(--ink); cursor: pointer; transition: transform 0.1s; }
.qty-btn:active { transform: translate(2px, 2px); box-shadow: 0px 0px 0 var(--ink); }

.card-trusted { background: #fff; border: 2.5px solid var(--ink); border-radius: 10px; box-shadow: 3px 3px 0 var(--ink); overflow: hidden; margin-bottom: 12px; }
.card-trusted__header { background: #f8fafc; border-bottom: 2.5px solid var(--ink); padding: 10px 14px; font-weight: 900; font-size: 13px; color: var(--ink); display: flex; align-items: center; gap: 6px; }
.card-trusted__body { padding: 12px 14px; }

.bank-info { background: #fef08a; border: 2.5px solid var(--ink); border-radius: 10px; padding: 12px 14px; margin-bottom: 12px; position: relative; box-shadow: 3px 3px 0 var(--ink); }
.amount-option-btn.active {
  background: var(--yellow) !important;
  box-shadow: 0px 0px 0 var(--ink) !important;
  transform: translate(2px, 2px) !important;
}
</style>

<!-- Balance -->
<div class="wd-bal">
  <div class="wd-bal__lbl"><i class="ph-bold ph-wallet"></i> Saldo Penarikan</div>
  <div class="wd-bal__val"><?= format_rp((float)$user['balance_wd']) ?></div>
</div>


<!-- Limit alert removed per user request -->

<?php if ($flash): ?>
<div class="alert alert--<?= $flashType === 'error' ? 'error' : 'success' ?>" style="margin-bottom:10px;font-size:12px;padding:8px 12px"><?= htmlspecialchars($flash) ?></div>
<?php endif; ?>

<!-- Lock notice / Estimation -->
<?php if ($wd_estimation): ?>
  <?php if ($wd_locked): ?>
  <div class="alert alert--error" style="margin-bottom:12px;font-size:11px;border:2px solid var(--red);border-radius:10px;padding:8px 12px;display:flex;align-items:flex-start;gap:6px;font-weight:800;box-shadow:2px 2px 0 var(--red)">
    <i class="ph-fill ph-lock-key" style="color:var(--red);font-size:16px;margin-top:1px"></i>
    <div>
      <div><?= $wd_estimation ?></div>
      <?php if ($wd_lock_notice): ?>
        <div style="margin-top:4px"><em>"<?= htmlspecialchars($wd_lock_notice) ?>"</em></div>
      <?php endif; ?>
      <div style="margin-top:4px;font-size:10px;opacity:0.8;font-weight:700"><i class="ph-bold ph-clock"></i> Jam operasional: <?= date('h:i A', strtotime($wd_lock_end)) ?> – <?= date('h:i A', strtotime($wd_lock_start)) ?></div>
    </div>
  </div>
  <?php else: ?>
  <div style="margin-bottom:12px;font-size:11px;color:#15803d;background:#dcfce7;border:2px solid #16a34a;border-radius:10px;padding:8px 12px;display:flex;align-items:center;gap:6px;font-weight:800;box-shadow:2px 2px 0 #16a34a">
    <i class="ph-fill ph-check-circle" style="font-size:16px"></i> 
    <div><?= $wd_estimation ?></div>
  </div>
  <?php endif; ?>
<?php endif; ?>

<!-- Level block notice -->
<?php if ($free_wd_limit_reached): ?>
<div class="alert alert--error" style="display:flex;margin-bottom:12px;font-size:11px;padding:8px 12px;align-items:center;justify-content:space-between;gap:6px;flex-wrap:nowrap;border:2px solid var(--red)">
  <span style="display:flex;align-items:center;gap:4px"><i class="ph-fill ph-prohibit" style="color:var(--red);font-size:14px"></i> Akun Free maksimal 1x penarikan. Upgrade untuk tarik dana lagi!</span>
  <a href="/upgrade" class="btn btn--yellow btn--sm" style="white-space:nowrap;font-size:10px;padding:4px 10px;flex-shrink:0">Upgrade →</a>
</div>
<?php elseif ($free_wrong_bank): ?>
<div class="alert alert--error" style="display:flex;margin-bottom:12px;font-size:11px;padding:8px 12px;align-items:center;justify-content:space-between;gap:6px;flex-wrap:nowrap;border:2px solid var(--red)">
  <span style="display:flex;align-items:center;gap:4px"><i class="ph-fill ph-warning-circle" style="color:var(--red);font-size:14px"></i> Akun Free hanya bisa withdraw ke DANA.</span>
  <a href="/edit-rekening" class="btn btn--yellow btn--sm" style="white-space:nowrap;font-size:10px;padding:4px 10px;flex-shrink:0">Ubah Bank →</a>
</div>
<?php elseif (!empty($user_mem['is_wd_disabled'])): ?>
<div class="alert alert--error" style="display:flex;margin-bottom:12px;font-size:11px;padding:8px 12px;align-items:center;justify-content:space-between;gap:6px;flex-wrap:nowrap;border:2px solid var(--red)">
  <span style="display:flex;align-items:center;gap:4px"><i class="ph-fill ph-warning-circle" style="color:var(--red);font-size:14px"></i> Penarikan untuk level Anda sedang ditutup!</span>
  <a href="/upgrade" class="btn btn--yellow btn--sm" style="white-space:nowrap;font-size:10px;padding:4px 10px;flex-shrink:0">Upgrade →</a>
</div>
<?php elseif ($level_blocked): ?>
<div id="level-blocked-notice" class="alert alert--warn" style="display:flex;margin-bottom:12px;font-size:12px;padding:12px;align-items:center;justify-content:space-between;gap:10px;border:2.5px solid var(--orange);background:#fffbeb;box-shadow:3px 3px 0 var(--orange);border-radius:12px;">
  <div style="display:flex;align-items:flex-start;gap:8px">
    <i class="ph-fill ph-lock-key" style="color:var(--orange);font-size:24px;margin-top:2px"></i>
    <div>
      <div style="font-weight:900;color:var(--ink);margin-bottom:4px">Penarikan Terkunci!</div>
      <div style="font-size:11px;color:#666;line-height:1.4">Pengguna akun <strong>Free (Gratis)</strong> saat ini belum bisa melakukan penarikan. Kamu perlu upgrade ke minimal <strong><?= htmlspecialchars($min_level_name) ?></strong> untuk membuka akses penarikan.</div>
    </div>
  </div>
  <a href="/upgrade" class="btn btn--yellow btn--sm" style="white-space:nowrap;font-size:11px;padding:6px 12px;flex-shrink:0;box-shadow:2px 2px 0 var(--ink);border:2px solid var(--ink);border-radius:8px;font-weight:900">Upgrade →</a>
</div>
<?php endif; ?>

<a href="/user/level_performance.php" class="btn w-100" style="background:#fff;border:2.5px dashed var(--ink);border-radius:10px;font-weight:800;color:var(--ink);text-align:center;display:flex;align-items:center;justify-content:center;gap:8px;padding:10px;margin-bottom:16px">
  <i class="ph-bold ph-chart-line-up" style="font-size:18px"></i> Cek Kinerja Server & WD Per Level
</a>

<!-- Form -->
<?php if ($has_pending_bank): ?>
<div class="card card--danger" style="margin-bottom:16px;background:rgba(245, 158, 11, 0.1);border:2.5px solid #f59e0b;box-shadow:4px 4px 0 #f59e0b">
  <div class="card__body" style="text-align:center;padding:24px 16px">
    <div style="font-size:42px;margin-bottom:12px">⚙️</div>
    <h6 style="color:#d97706;margin-bottom:6px;font-weight:900;font-size:16px">Verifikasi Rekening Baru</h6>
    <div style="font-size:12px;color:#555;font-weight:700">Akses penarikan ditunda sementara karena sistem sedang memverifikasi data rekening barumu secara otomatis.</div>
  </div>
</div>
<?php elseif (!$user['can_withdraw']): ?>
<div class="card card--danger" style="margin-bottom:16px;background:rgba(255,59,48,0.1);border:2px solid var(--red)">
  <div class="card__body" style="text-align:center;padding:24px 16px">
    <i class="ph-fill ph-prohibit" style="font-size:42px;color:var(--red);margin-bottom:12px"></i>
    <h6 style="color:var(--red);margin-bottom:6px;font-weight:900;font-size:16px">Akses Penarikan Dibatasi</h6>
    <div style="font-size:12px;color:#555;font-weight:700">Akun kamu saat ini tidak diizinkan untuk melakukan penarikan dana. Silakan hubungi admin.</div>
  </div>
</div>
<?php else: ?>
<div class="card-trusted">
  <div class="card-trusted__header"><i class="ph-fill ph-bank" style="color:var(--yellow);font-size:18px"></i> Form Penarikan</div>
  <div class="card-trusted__body">
    <form method="POST" id="wd-form">
      <?= csrf_field() ?>
      <input type="hidden" name="form_token" value="<?= htmlspecialchars($_form_token_wd) ?>">
      <input type="hidden" name="amount" id="selected-amount" value="" required>

      <div class="form-group" style="margin-bottom:12px">
        <label class="form-label" style="font-size:11px;font-weight:800;color:#555">Pilih Nominal Penarikan (Rp)</label>
        <div class="amount-selection-grid" style="display:grid; grid-template-columns: repeat(3, 1fr); gap: 8px; margin-top: 6px;">
          <?php foreach ($available_amounts as $amt): ?>
            <button type="button" class="amount-option-btn" data-value="<?= $amt ?>" onclick="selectWdAmount(this, <?= $amt ?>)" style="display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 6px 4px; background: #fff; border: 2.5px solid var(--ink); border-radius: 8px; box-shadow: 2.5px 2.5px 0 var(--ink); cursor: pointer; transition: all 0.1s; outline: none; font-family: inherit;">
              <img src="/assets/dollar.png" alt="uang" style="width: 28px; height: 28px; object-fit: contain; margin-bottom: 4px; mix-blend-mode: multiply; filter: drop-shadow(1px 1px 0 rgba(0,0,0,0.1));">
              <span style="font-size: 12px; font-weight: 900; color: var(--ink); letter-spacing: -0.5px;"><?= format_rp($amt) ?></span>
              <span style="font-size: 8px; font-weight: 800; color: #64748b; margin-top: 2px; text-transform: uppercase;">Tarik</span>
            </button>
          <?php endforeach; ?>
        </div>
      </div>

      <?php if (!$has_bank): ?>
      <div class="alert alert--warn" style="margin-bottom:12px;font-size:12px">⚠️ Harap isi data rekening bankmu ya. Data ini gak bisa diubah lagi setelah disimpan!</div>
      
      <?php if ($is_free_level): ?>
      <div class="form-group" style="margin-bottom:8px">
        <label class="form-label" style="font-size:12px">Nama Bank / E-Wallet (Khusus Akun Free)</label>
        <input class="form-control" type="text" name="bank_name" value="DANA" readonly style="background:#f1f5f9;cursor:not-allowed">
      </div>
      <?php else: ?>
      <div class="form-group" style="margin-bottom:8px">
        <label class="form-label" style="font-size:12px">Nama Bank / E-Wallet</label>
        <input class="form-control" type="text" name="bank_name"
               value="<?= htmlspecialchars($_POST['bank_name'] ?? '') ?>"
               placeholder="BCA, GoPay, OVO, Dana..." required>
      </div>
      <?php endif; ?>
      <div class="form-group" style="margin-bottom:8px">
        <label class="form-label" style="font-size:12px">Nomor Rekening / Akun</label>
        <input class="form-control" type="text" name="account_number"
               value="<?= htmlspecialchars($_POST['account_number'] ?? '') ?>"
               placeholder="08xxxxxxxxxx atau nomor rekening" required>
      </div>
      <div class="form-group" style="margin-bottom:12px">
        <label class="form-label" style="font-size:12px">Nama Pemilik Rekening</label>
        <input class="form-control" type="text" name="account_name"
               value="<?= htmlspecialchars($_POST['account_name'] ?? '') ?>"
               placeholder="Nama sesuai rekening bank" required>
      </div>
      <?php endif; ?>

      <?php if ($free_wd_limit_reached): ?>
        <button type="button" class="btn btn--primary btn--full" disabled style="font-size:13px;height:42px"><i class="ph-bold ph-prohibit"></i> Limit Free Tercapai</button>
      <?php elseif ($free_wrong_bank): ?>
        <button type="button" class="btn btn--primary btn--full" disabled style="font-size:13px;height:42px"><i class="ph-bold ph-prohibit"></i> Hanya Bisa ke DANA</button>
      <?php elseif ($has_pending_wd): ?>
        <div class="alert alert--warn" style="margin-bottom:10px;font-size:11px;border:2px solid var(--orange);padding:8px">
          <i class="ph-bold ph-hourglass" style="color:var(--orange)"></i> <strong>Ada penarikan pending.</strong> Tunggu kelar diproses dulu ya.
        </div>
        <button type="button" class="btn btn--primary btn--full" disabled style="font-size:13px;height:42px"><i class="ph-bold ph-hourglass-high"></i> Sabar, Lagi Diproses...</button>
      <?php elseif ((float)$user['balance_wd'] < $min_withdraw): ?>
        <button type="button" class="btn btn--primary btn--full" disabled style="font-size:13px;height:42px"><i class="ph-bold ph-wallet"></i> Saldo Kurang Dikit!</button>
      <?php elseif ($wd_locked): ?>
        <button type="button" class="btn btn--primary btn--full" disabled style="font-size:13px;height:42px"><i class="ph-bold ph-clock"></i> Lagi Tutup Nih</button>
      <?php elseif ($level_blocked): ?>
        <button type="button" class="btn btn--primary btn--full" disabled style="font-size:13px;height:42px;background:#e2e8f0;color:#64748b;border:2px solid #cbd5e1;cursor:not-allowed"><i class="ph-bold ph-lock-key"></i> Upgrade Level Dulu!</button>
      <?php else: ?>
        <button type="submit" id="wd-submit-btn" class="btn btn--primary btn--full no-dbl-submit" style="font-size:13px;height:42px;background:var(--yellow);color:var(--ink);border:2.5px solid var(--ink);box-shadow:3px 3px 0 var(--ink)"><i class="ph-bold ph-paper-plane-right"></i> Tarik Saldo Sekarang</button>
      <?php endif; ?>
    </form>
  </div>
</div>
<?php endif; ?>

<script>
function selectWdAmount(btn, val) {
  document.querySelectorAll('.amount-option-btn').forEach(el => el.classList.remove('active'));
  btn.classList.add('active');
  document.getElementById('selected-amount').value = val;
}

// ── Toggle show/hide account number ──
const _maskedNum = '<?= htmlspecialchars(mask_account($user['account_number'] ?? '')) ?>';
const _realNum   = '<?= htmlspecialchars($user['account_number'] ?? '') ?>';
let _numVisible  = false;
function toggleAccNum() {
  _numVisible = !_numVisible;
  const el  = document.getElementById('wd-accnum-display');
  const btn = document.getElementById('wd-accnum-toggle');
  if (el)  el.textContent = _numVisible ? _realNum : _maskedNum;
  if (btn) { btn.style.opacity = _numVisible ? '1' : '0.5'; btn.title = _numVisible ? 'Sembunyikan nomor' : 'Tampilkan nomor'; }
}
// Init opacity
document.addEventListener('DOMContentLoaded', () => {
  const b = document.getElementById('wd-accnum-toggle');
  if (b) b.style.opacity = '0.5';
});

(function(){
  const form    = document.getElementById('wd-form');
  const btn     = document.getElementById('wd-submit-btn');
  const notice  = document.getElementById('level-blocked-notice');
  const minWd   = <?= (int)$min_withdraw ?>;
  const balWd   = <?= (float)$user['balance_wd'] ?>;
  const levelBlocked = <?= $level_blocked ? 'true' : 'false' ?>;

  if (!form) return;

  form.addEventListener('submit', function(e) {
    const amtInput = document.querySelector('[name=amount]');
    const amt = amtInput ? parseFloat(amtInput.value) : 0;
    const maxWd = <?= (float)$max_available ?>;

    if (!amt || isNaN(amt)) {
      e.preventDefault();
      alert('Pilih dulu nominal penarikannya ya!');
      return;
    }
    if (amt < minWd) {
      e.preventDefault();
      alert('Minimal withdraw Rp ' + minWd.toLocaleString('id-ID'));
      return;
    }
    if (amt > maxWd) {
      e.preventDefault();
      alert('Maksimal withdraw Rp ' + maxWd.toLocaleString('id-ID'));
      return;
    }

    if (levelBlocked) {
      e.preventDefault();
      return;
    }

    // Modal confirmation
    if (!form.dataset.confirmed) {
      e.preventDefault();
      document.getElementById('brutal-confirm-amt').innerText = 'Rp ' + amt.toLocaleString('id-ID');
      document.getElementById('brutal-confirm').style.display = 'flex';
    }
  });

  window.confirmBrutalWd = function() {
    document.getElementById('brutal-confirm').style.display = 'none';
    if (btn) { btn.disabled = true; btn.innerText = 'Memproses...'; }
    const fd = new FormData(form);
    fd.append('ajax', '1');
    fetch('', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(res => {
      if (res.error) {
        if (typeof nToast !== 'undefined') nToast(res.error, 'error'); else alert(res.error);
        if (btn) { btn.disabled = false; btn.innerText = '💸 Ajukan Penarikan'; }
      } else {
        if (typeof nToast !== 'undefined') nToast(res.message, 'success'); else alert(res.message);
        setTimeout(() => window.location.reload(), 1500);
      }
    })
    .catch(() => {
      if (typeof nToast !== 'undefined') nToast('Koneksi terputus.', 'error'); else alert('Koneksi terputus.');
      if (btn) { btn.disabled = false; btn.innerText = '💸 Ajukan Penarikan'; }
    });
  };
})();
</script>

<!-- History -->
<?php if (!empty($wds)): ?>
<div class="section-header" style="margin-top:20px;margin-bottom:12px">
  <div class="section-title" style="font-size:14px;display:flex;align-items:center;gap:6px"><i class="ph-fill ph-clock-counter-clockwise" style="color:var(--ink)"></i> Riwayat Penarikan</div>
  <a href="/history" class="section-link" style="font-weight:800;color:var(--yellow)">Lihat semua →</a>
</div>
<div class="card-trusted" style="border:none;box-shadow:none;background:transparent"><div class="card__body" style="padding:0">
  <?php foreach ($wds as $w): ?>
  <?php $wl = $channel_logos[strtolower($w['bank_name'])] ?? null; ?>
    <div class="list-item" style="padding:12px 14px;background:#fff;border:2.5px solid var(--ink);border-radius:12px;box-shadow:3px 3px 0 var(--ink);margin-bottom:10px">
      <?php if ($wl): ?>
      <div class="list-item__icon" style="background:transparent;padding:0;width:34px;height:34px">
        <img src="/assets/banks/<?= htmlspecialchars($wl) ?>" style="width:100%;height:100%;object-fit:contain;border-radius:6px;">
      </div>
      <?php else: ?>
      <div class="list-item__icon" style="background:#fef08a;color:#d97706;width:34px;height:34px;font-size:16px"><i class="ph-bold ph-bank"></i></div>
      <?php endif; ?>
    <div class="list-item__body">
      <div class="list-item__title" style="font-size:13px"><?= format_rp((float)$w['amount']) ?></div>
      <div class="list-item__sub" style="font-size:10px"><?= htmlspecialchars($w['bank_name']) ?> · <?= date('d M H:i', strtotime($w['created_at'])) ?></div>
      <?php if ($w['admin_note']): ?>
      <div class="list-item__sub" style="color:var(--red,#ef4444);font-size:10px">📝 <?= htmlspecialchars($w['admin_note']) ?></div>
      <?php endif; ?>
    </div>
    <div class="list-item__right">
      <span class="badge badge--<?= match($w['status']){'approved'=>'success','pending'=>'warn','hold'=>'warn','rejected'=>'error','refunded'=>'info',default=>'error'} ?>" style="font-size:10px;font-weight:800">
        <?= match($w['status']){'approved'=>'Sukses', 'pending'=>'Menunggu', 'hold'=>'Ditahan', 'rejected'=>'Ditolak', 'refunded'=>'Dikembalikan', default=>ucfirst($w['status'])} ?>
      </span>
    </div>
  </div>
  <?php endforeach; ?>
</div></div>
<?php endif; ?>

<!-- Neobrutalism Modal Confirm -->
<div id="brutal-confirm" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(15,23,42,0.7);z-index:9999;align-items:center;justify-content:center;padding:20px;backdrop-filter:blur(4px);">
  <div class="card card--mint" style="width:100%;max-width:340px;box-shadow:6px 6px 0 var(--ink);border:3px solid var(--ink);border-radius:12px;animation: popIn 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275);">
    <div class="card__header" style="background:var(--yellow);border-bottom:3px solid var(--ink);border-radius:9px 9px 0 0;padding:14px 16px;">
      <div class="card__title" style="color:var(--ink);font-weight:900;font-size:16px;display:flex;align-items:center;gap:6px"><i class="ph-bold ph-paper-plane-right"></i> Konfirmasi Penarikan</div>
    </div>
    <div class="card__body" style="padding:16px;background:#fff;border-radius:0 0 9px 9px;">
      <div style="font-size:13px;font-weight:700;margin-bottom:8px;color:#333;text-align:center;">Nominal Penarikan:</div>
      <div id="brutal-confirm-amt" style="font-size:26px;font-weight:900;color:var(--brand);margin-bottom:16px;text-align:center;letter-spacing:-0.5px;"></div>
      
      <?php if ($has_bank): ?>
      <div class="bank-info" style="margin-bottom:20px;padding:12px;box-shadow:3px 3px 0 var(--ink);">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px">
          <div style="font-size:10px;font-weight:900;color:var(--ink);text-transform:uppercase"><i class="ph-bold ph-bank"></i> Rekening Tujuan</div>
          <a href="/edit-rekening" class="btn btn--sm" style="font-size:9px;padding:4px 8px;border:2px solid var(--ink);background:#fff;color:var(--ink);font-weight:900;border-radius:6px;box-shadow:2px 2px 0 var(--ink)"><i class="ph-bold ph-pencil-simple"></i> Edit</a>
        </div>
        <div style="font-size:13px;font-weight:700;color:var(--ink)">
          <?php $user_wl = $channel_logos[strtolower($user['bank_name'] ?? '')] ?? null; ?>
          <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px">
            <?php if ($user_wl): ?>
            <img src="/assets/banks/<?= htmlspecialchars($user_wl) ?>" style="height:24px;border-radius:4px;object-fit:contain">
            <?php else: ?>
            <div style="width:24px;height:24px;background:#fff;border:2px solid var(--ink);border-radius:4px;display:flex;align-items:center;justify-content:center"><i class="ph-bold ph-bank"></i></div>
            <?php endif; ?>
            <span style="font-size:14px;font-weight:900"><?= htmlspecialchars($user['bank_name']) ?></span>
          </div>
          <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px">
            <span id="wd-accnum-display" style="font-size:16px;font-family:monospace;letter-spacing:1px;font-weight:900;color:var(--ink)"><?= htmlspecialchars(mask_account($user['account_number'] ?? '')) ?></span>
            <button type="button" id="wd-accnum-toggle" onclick="toggleAccNum()"
              title="Tampilkan/sembunyikan nomor"
              style="background:none;border:none;cursor:pointer;padding:0;line-height:1;font-size:18px;color:#64748b;flex-shrink:0;transition:color 0.2s">
              <i class="ph-bold ph-eye"></i>
            </button>
          </div>
          <div style="font-size:12px;font-weight:800;color:#334155"><?= htmlspecialchars($user['account_name']) ?></div>
        </div>
      </div>
      <?php else: ?>
      <div style="font-size:12px;color:#666;margin-bottom:20px;font-weight:600;text-align:center;">Pastikan data rekening yang diisi sudah benar.<br>Apakah kamu ingin melanjutkan?</div>
      <?php endif; ?>

      <div style="display:flex;gap:12px;">
        <button type="button" onclick="document.getElementById('brutal-confirm').style.display='none'" class="btn" style="flex:1;background:#eee;color:var(--ink);border:3px solid var(--ink);font-weight:800;border-radius:8px;">Batal</button>
        <button type="button" onclick="confirmBrutalWd()" class="btn btn--primary" style="flex:1.5;background:var(--yellow);color:var(--ink);border:3px solid var(--ink);font-weight:900;border-radius:8px;box-shadow:3px 3px 0 var(--ink);">Ya, Tarik Dana</button>
      </div>
    </div>
  </div>
</div>
<style>
@keyframes popIn { 0% { transform: scale(0.8); opacity: 0; } 100% { transform: scale(1); opacity: 1; } }
</style>

<?php require dirname(__DIR__) . '/partials/footer.php'; ?>
