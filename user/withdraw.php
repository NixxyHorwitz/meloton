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
$wd_global_enabled = setting($pdo, 'wd_global_enabled', '1') === '1';

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

$is_free_level = !$membership_active;
$free_wd_limit_reached = false;
$free_wrong_bank = false;
$free_age_blocked = false;

if ($is_free_level) {
    $wd_free_only_dana = setting($pdo, 'wd_free_only_dana', '1') === '1';
    $wd_free_limit_1x  = setting($pdo, 'wd_free_limit_1x', '1') === '1';
    $wd_free_require_1day = setting($pdo, 'wd_free_require_1day', '1') === '1';
    
    if ($wd_free_require_1day && strtotime($user['created_at']) > strtotime('-1 day')) {
        $free_age_blocked = true;
    }
    
    if ($wd_free_only_dana && $has_bank && strtolower(trim($user['bank_name'])) !== 'dana') {
        $free_wrong_bank = true;
    }
    
    if ($wd_free_limit_1x) {
        $wd_cnt = $pdo->prepare("SELECT COUNT(*) FROM withdrawals WHERE user_id=? AND status='approved'");
        $wd_cnt->execute([$user['id']]);
        if ($wd_cnt->fetchColumn() >= 1) {
            $free_wd_limit_reached = true;
        }
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
    } elseif (!$user['can_withdraw']) {
        $flash = '❌ Akses withdraw kamu dibatasi nih. Hubungi admin yuk buat info lebih lanjut!'; $flashType = 'error';
    } elseif (!$wd_global_enabled) {
        $flash = '🔴 Fitur penarikan saat ini sedang dinonaktifkan secara global (Maintenance).'; $flashType = 'error';
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
    } elseif ($free_age_blocked) {
        $flash = 'Akun harus berumur min. 1 hari untuk WD (Level Gratis).'; $flashType = 'error';
    } else {
        $amount  = (float) preg_replace('/\D/', '', $_POST['amount'] ?? '0');
        
        $bank    = $has_bank ? $user['bank_name'] : trim($_POST['bank_name'] ?? '');
        $accnum  = $has_bank ? $user['account_number'] : trim($_POST['account_number'] ?? '');
        $accname = $has_bank ? $user['account_name'] : trim($_POST['account_name'] ?? '');

        if (!in_array((int)$amount, $available_amounts, true)) {
            $flash = 'Nominal penarikan gak valid nih. Harus pilih dari daftar ya!'; $flashType = 'error';
        } elseif ($is_free_level && setting($pdo, 'wd_free_only_dana', '1') === '1' && strtolower($bank) !== 'dana') {
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
if (!$wd_global_enabled) {
    $wd_estimation = "🔴 Penarikan saat ini <strong>DINONAKTIFKAN</strong> (Maintenance).";
} elseif ($wd_lock_start && $wd_lock_end) {
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

$pageTitle  = 'Withdraw — Meloton';
$activePage = 'withdraw';
require dirname(__DIR__) . '/partials/header.php';
?>

<style>
/* ══════════════════════════════════════════════
   WITHDRAW PAGE — CASUAL GAME STYLE
   ══════════════════════════════════════════════ */
.wd-page { padding: 0 0 20px; }

/* ── Hero Balance (Compact & Ornamented) ── */
.wd-hero {
  background: linear-gradient(135deg, #0c4a6e, #0e7490, #06b6d4);
  border: 3px solid #075985;
  border-radius: 18px;
  box-shadow: 0 6px 0 #0c4a6e;
  padding: 16px;
  text-align: center;
  position: relative;
  overflow: hidden;
  margin-bottom: 12px;
}
/* Ornaments */
.wd-hero::before { content:''; position:absolute; top:-20px; left:-20px; width:80px; height:80px; background:url('/assets/dollar.png') no-repeat center/contain; opacity:0.1; transform:rotate(-15deg); pointer-events:none; }
.wd-hero::after { content:''; position:absolute; bottom:-20px; right:-20px; width:100px; height:100px; background:rgba(255,255,255,0.06); border-radius:50%; pointer-events:none; }
.wd-hero-star { position:absolute; top:10px; right:30px; color:#fde68a; font-size:24px; opacity:0.3; transform:rotate(20deg); pointer-events:none; }
.wd-hero-dot { position:absolute; bottom:15px; left:40px; width:8px; height:8px; background:#fde68a; border-radius:50%; opacity:0.4; pointer-events:none; }

.wd-hero__lbl { font-size:11px; font-weight:900; color:rgba(255,255,255,0.7); margin-bottom:2px; text-transform:uppercase; letter-spacing:1px; display:flex; align-items:center; justify-content:center; gap:6px; position:relative; z-index:1; }
.wd-hero__val { font-size:28px; font-weight:900; color:#fde68a; text-shadow:0 2px 4px rgba(0,0,0,0.2); letter-spacing:-1px; position:relative; z-index:1; }

/* ── Alerts ── */
.wd-alert {
  padding: 10px 12px;
  border-radius: 12px;
  font-size: 11px; font-weight: 800;
  display: flex; gap: 8px; align-items: center;
  margin-bottom: 12px;
  border: 2px solid;
  line-height: 1.3;
}
.wd-alert--err { background: #fef2f2; color: #991b1b; border-color: #fca5a5; }
.wd-alert--warn { background: #fffbeb; color: #b45309; border-color: #fcd34d; }
.wd-alert--succ { background: #f0fdf4; color: #166534; border-color: #86efac; }
.wd-alert-icon { font-size: 18px; flex-shrink: 0; }
.wd-alert-btn { background: #fbbf24; color: #92400e; border: 2px solid #fff; border-radius: 8px; font-size: 9px; font-weight: 900; padding: 4px 10px; text-decoration: none; box-shadow: 0 2px 0 rgba(0,0,0,0.1); flex-shrink: 0; }

/* ── Form Card ── */
.wd-card {
  background: #fff;
  border: 2.5px solid #7dd3e8;
  border-radius: 16px;
  box-shadow: 0 5px 0 #7dd3e8;
  padding: 16px;
  margin-bottom: 12px;
}
.wd-card-title {
  font-size: 14px; font-weight: 900; color: #0c4a6e;
  display: flex; align-items: center; gap: 6px; margin-bottom: 12px;
  border-bottom: 2px solid #e0f9ff; padding-bottom: 10px;
}

/* ── Amount Grid ── */
.wd-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 6px; margin: 8px 0 16px; }
.wd-amt-btn {
  background: #f8fafc;
  border: 2px solid #cbd5e1;
  border-radius: 12px;
  padding: 8px 4px;
  display: flex; flex-direction: column; align-items: center; justify-content: center;
  cursor: pointer; transition: all 0.1s;
  box-shadow: 0 3px 0 #cbd5e1;
  outline: none; position: relative;
}
.wd-amt-btn:active { transform: translateY(2px); box-shadow: 0 1px 0 #cbd5e1; }
.wd-amt-btn.active {
  background: linear-gradient(135deg, #fde68a, #f59e0b);
  border-color: #fff;
  box-shadow: 0 3px 0 #d97706;
  color: #fff;
}
.wd-amt-btn.active:active { transform: translateY(3px); box-shadow: 0 0 0 #d97706; }
.wd-amt-img { width: 22px; height: 22px; object-fit: contain; margin-bottom: 2px; mix-blend-mode: multiply; filter: drop-shadow(1px 1px 0 rgba(0,0,0,0.1)); }
.wd-amt-val { font-size: 11px; font-weight: 900; color: #0c4a6e; letter-spacing: -0.5px; }
.wd-amt-btn.active .wd-amt-val { color: #78350f; }

/* ── Inputs ── */
.wd-group { margin-bottom: 10px; }
.wd-label { font-size: 10px; font-weight: 900; color: #64748b; margin-bottom: 4px; display: block; }
.wd-input {
  width: 100%; background: #f8fafc;
  border: 2px solid #e2e8f0; border-radius: 10px;
  padding: 10px; font-size: 12px; font-weight: 800; color: #0c4a6e;
  font-family: inherit; outline: none; transition: border-color 0.2s;
}
.wd-input:focus { border-color: #7dd3e8; background: #fff; }
.wd-input:disabled, .wd-input[readonly] { background: #f1f5f9; color: #94a3b8; cursor: not-allowed; }

/* ── Bank Info Display (If already has bank) ── */
.wd-bank-display {
  background: linear-gradient(135deg, #0c4a6e, #0e7490);
  border-radius: 12px; padding: 12px; margin-bottom: 16px;
  color: #fff; position: relative; overflow: hidden;
}
.wd-bank-display::before { content:''; position:absolute; top:-20px; right:-20px; width:60px; height:60px; border-radius:50%; background:rgba(255,255,255,0.05); }
.wd-bank-hdr { display:flex; justify-content:space-between; align-items:center; margin-bottom:10px; border-bottom:1.5px solid rgba(255,255,255,0.1); padding-bottom:8px; }
.wd-bank-num { font-size:16px; font-family:monospace; font-weight:900; letter-spacing:2px; margin-bottom:2px; }
.wd-bank-name { font-size:11px; font-weight:700; color:rgba(255,255,255,0.7); }

/* ── Submit Button ── */
.wd-submit {
  width: 100%; padding: 12px;
  background: linear-gradient(135deg, #22d3ee, #0891b2);
  border: 2.5px solid #a5f3fc;
  border-radius: 14px;
  color: #fff; font-size: 13px; font-weight: 900;
  box-shadow: 0 5px 0 #0e7490;
  cursor: pointer; transition: transform 0.1s;
  display: flex; align-items: center; justify-content: center; gap: 8px;
}
.wd-submit:active { transform: translateY(4px); box-shadow: 0 1px 0 #0e7490; }
.wd-submit:disabled { background: #f1f5f9; border-color: #cbd5e1; color: #94a3b8; box-shadow: none; cursor: not-allowed; transform: none; }

/* ── Modal ── */
#cg-modal { display:none; position:fixed; inset:0; background:rgba(15,23,42,0.7); z-index:99999; align-items:center; justify-content:center; padding:16px; backdrop-filter:blur(4px); }
.cg-modal-box { background: #fff; width:100%; max-width:300px; border-radius:20px; border:3px solid #7dd3e8; box-shadow:0 8px 0 #0c4a6e; animation: popIn 0.3s cubic-bezier(0.34, 1.56, 0.64, 1); overflow:hidden; }
.cg-modal-hdr { background: linear-gradient(135deg, #fde68a, #f59e0b); padding: 12px; text-align: center; color: #78350f; font-weight: 900; font-size: 14px; border-bottom: 2px solid rgba(255,255,255,0.5); }
.cg-modal-bd { padding: 16px; text-align: center; }
.cg-modal-actions { display:flex; gap:8px; padding:0 16px 16px; }
.cg-btn-cancel { flex:1; padding:10px; background:#f1f5f9; border:2px solid #cbd5e1; border-radius:10px; font-weight:900; color:#64748b; font-size:12px; }
.cg-btn-confirm { flex:1.5; padding:10px; background:linear-gradient(135deg, #34d399, #10b981); border:2px solid #6ee7b7; border-radius:10px; font-weight:900; color:#fff; box-shadow:0 4px 0 #059669; font-size:12px; }
.cg-btn-confirm:active { transform:translateY(3px); box-shadow:0 1px 0 #059669; }

@keyframes popIn { from{transform:scale(0.8);opacity:0;} to{transform:scale(1);opacity:1;} }
</style>

<div class="wd-page">
  <!-- HERO BALANCE -->
  <div class="wd-hero">
    <i class="ph-fill ph-star wd-hero-star"></i>
    <div class="wd-hero-dot"></div>
    <div class="wd-hero__lbl"><i class="ph-bold ph-wallet"></i> Saldo Penarikan</div>
    <div class="wd-hero__val"><?= format_rp((float)$user['balance_wd']) ?></div>
  </div>

  <!-- FLASH ALERTS -->
  <?php if ($flash): ?>
  <div class="wd-alert wd-alert--<?= $flashType === 'error' ? 'err' : 'succ' ?>">
    <div class="wd-alert-icon"><?= $flashType === 'error' ? '❌' : '✨' ?></div>
    <div style="flex:1"><?= htmlspecialchars($flash) ?></div>
  </div>
  <?php endif; ?>

  <!-- NOTICES -->
  <?php if ($wd_estimation): ?>
    <?php if ($wd_locked): ?>
    <div class="wd-alert wd-alert--err">
      <div class="wd-alert-icon">🔒</div>
      <div style="flex:1">
        <div style="margin-bottom:2px"><?= $wd_estimation ?></div>
        <?php if ($wd_lock_notice): ?>
          <div style="font-size:10px;opacity:0.9"><em>"<?= htmlspecialchars($wd_lock_notice) ?>"</em></div>
        <?php endif; ?>
        <div style="font-size:9px;margin-top:2px"><i class="ph-bold ph-clock"></i> Buka: <?= date('h:i A', strtotime($wd_lock_end)) ?></div>
      </div>
    </div>
    <?php else: ?>
    <div class="wd-alert wd-alert--succ">
      <div class="wd-alert-icon">✅</div>
      <div style="flex:1"><?= $wd_estimation ?></div>
    </div>
    <?php endif; ?>
  <?php endif; ?>

  <?php if ($free_wd_limit_reached): ?>
  <div class="wd-alert wd-alert--err">
    <div class="wd-alert-icon">🛑</div>
    <div style="flex:1">Akun Free max 1x penarikan.</div>
    <a href="/upgrade" class="wd-alert-btn">Upgrade</a>
  </div>
  <?php elseif ($free_wrong_bank): ?>
  <div class="wd-alert wd-alert--err">
    <div class="wd-alert-icon">⚠️</div>
    <div style="flex:1">Akun Free hanya bisa ke DANA.</div>
    <a href="/edit-rekening" class="wd-alert-btn">Ubah</a>
  </div>
  <?php elseif (!empty($user_mem['is_wd_disabled'])): ?>
  <div class="wd-alert wd-alert--err">
    <div class="wd-alert-icon">🛑</div>
    <div style="flex:1">Penarikan level ini ditutup sementara.</div>
    <a href="/upgrade" class="wd-alert-btn">Upgrade</a>
  </div>
  <?php elseif ($level_blocked): ?>
  <div class="wd-alert wd-alert--warn">
    <div class="wd-alert-icon">🔒</div>
    <div style="flex:1">
      <div style="margin-bottom:2px"><strong>Terkunci!</strong></div>
      <div style="font-size:10px;font-weight:700">Akun Gratis belum bisa WD. Butuh minimal level <?= htmlspecialchars($min_level_name) ?>.</div>
    </div>
    <a href="/upgrade" class="wd-alert-btn">Upgrade</a>
  </div>
  <?php elseif ($free_age_blocked): ?>
  <div class="wd-alert wd-alert--warn">
    <div class="wd-alert-icon">⏳</div>
    <div style="flex:1">
      <div style="margin-bottom:2px"><strong>Level Gratis: Akun Baru</strong></div>
      <div style="font-size:10px;font-weight:700">Level Gratis minimal harus berumur 1 hari untuk narik. Yuk Upgrade biar bisa langsung WD tanpa nunggu!</div>
    </div>
    <a href="/upgrade" class="wd-alert-btn">Upgrade</a>
  </div>
  <?php endif; ?>

  <!-- FORM CARD -->
  <?php if ($has_pending_bank): ?>
  <div class="wd-card" style="text-align:center;padding:24px 16px;border-color:#f59e0b;box-shadow:0 5px 0 #f59e0b;background:#fffbeb">
    <div style="font-size:36px;margin-bottom:8px">⚙️</div>
    <div style="font-size:14px;font-weight:900;color:#d97706;margin-bottom:4px">Verifikasi Rekening Baru</div>
    <div style="font-size:11px;font-weight:700;color:#92400e">Akses ditunda. Sistem sedang memverifikasi rekening barumu.</div>
  </div>
  <?php elseif (!$user['can_withdraw']): ?>
  <div class="wd-card" style="text-align:center;padding:24px 16px;border-color:#ef4444;box-shadow:0 5px 0 #ef4444;background:#fef2f2">
    <div style="font-size:36px;margin-bottom:8px">🛑</div>
    <div style="font-size:14px;font-weight:900;color:#dc2626;margin-bottom:4px">Akses Dibatasi</div>
    <div style="font-size:11px;font-weight:700;color:#991b1b">Akun ini tidak diizinkan melakukan penarikan dana.</div>
  </div>
  <?php else: ?>
  <div class="wd-card">
    <div class="wd-card-title"><i class="ph-bold ph-paper-plane-right" style="color:#0ea5e9;font-size:16px"></i> Form Penarikan</div>
    <form method="POST" id="wd-form">
      <?= csrf_field() ?>
      <input type="hidden" name="form_token" value="<?= htmlspecialchars($_form_token_wd) ?>">
      <input type="hidden" name="amount" id="selected-amount" value="" required>

      <div class="wd-group">
        <label class="wd-label">Pilih Nominal</label>
        <div class="wd-grid">
          <?php foreach ($available_amounts as $amt): ?>
            <button type="button" class="wd-amt-btn" data-value="<?= $amt ?>" onclick="selectWdAmount(this, <?= $amt ?>)">
              <img src="/assets/dollar.png" alt="coin" class="wd-amt-img">
              <div class="wd-amt-val"><?= format_rp($amt) ?></div>
            </button>
          <?php endforeach; ?>
        </div>
      </div>

      <?php if (!$has_bank): ?>
        <div class="wd-alert wd-alert--warn" style="font-size:10px;padding:8px">⚠️ Data rekening tidak bisa diubah setelah disimpan!</div>
        <?php if ($is_free_level): ?>
          <div class="wd-group">
            <label class="wd-label">Bank / E-Wallet</label>
            <input class="wd-input" type="text" name="bank_name" value="DANA" readonly>
          </div>
        <?php else: ?>
          <div class="wd-group">
            <label class="wd-label">Bank / E-Wallet</label>
            <input class="wd-input" type="text" name="bank_name" placeholder="BCA, Dana, OVO..." required>
          </div>
        <?php endif; ?>
        <div class="wd-group">
          <label class="wd-label">Nomor Rekening</label>
          <input class="wd-input" type="text" name="account_number" placeholder="08xxxxxxxx" required>
        </div>
        <div class="wd-group">
          <label class="wd-label">Nama Pemilik</label>
          <input class="wd-input" type="text" name="account_name" placeholder="Sesuai nama rekening" required>
        </div>
      <?php else: ?>
        <div class="wd-bank-display">
          <div class="wd-bank-hdr">
            <div style="font-size:9px;font-weight:900;text-transform:uppercase;color:rgba(255,255,255,0.7)"><i class="ph-bold ph-bank"></i> Tujuan Transfer</div>
            <a href="/edit-rekening" style="font-size:9px;color:#fff;font-weight:800;background:rgba(0,0,0,0.2);padding:4px 8px;border-radius:6px;text-decoration:none"><i class="ph-bold ph-pencil-simple"></i> Edit</a>
          </div>
          <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px">
            <?php $user_wl = $channel_logos[strtolower($user['bank_name'] ?? '')] ?? null; ?>
            <?php if ($user_wl): ?>
              <img src="/assets/banks/<?= htmlspecialchars($user_wl) ?>" style="height:20px;border-radius:4px;object-fit:contain;background:#fff;padding:2px">
            <?php endif; ?>
            <span style="font-size:14px;font-weight:900"><?= htmlspecialchars($user['bank_name']) ?></span>
          </div>
          <div style="display:flex;align-items:center;gap:8px">
            <div id="wd-accnum-display" class="wd-bank-num"><?= htmlspecialchars(mask_account($user['account_number'] ?? '')) ?></div>
            <button type="button" id="wd-accnum-toggle" onclick="toggleAccNum()" style="background:none;border:none;color:rgba(255,255,255,0.6);cursor:pointer;padding:0"><i class="ph-bold ph-eye"></i></button>
          </div>
          <div class="wd-bank-name"><?= htmlspecialchars($user['account_name']) ?></div>
        </div>
      <?php endif; ?>

      <!-- Submit logic -->
      <?php if ($free_wd_limit_reached || $free_wrong_bank || $wd_locked || $level_blocked || $free_age_blocked): ?>
        <button type="button" class="wd-submit" disabled>❌ Tidak Memenuhi Syarat</button>
      <?php elseif ($has_pending_wd): ?>
        <button type="button" class="wd-submit" disabled>⏳ Ada Penarikan Pending</button>
      <?php elseif ((float)$user['balance_wd'] < $min_withdraw): ?>
        <button type="button" class="wd-submit" disabled>⚠️ Saldo Kurang</button>
      <?php else: ?>
        <button type="submit" id="wd-submit-btn" class="wd-submit">🚀 Tarik Sekarang</button>
      <?php endif; ?>
    </form>
  </div>
  <?php endif; ?>

</div>

<!-- CONFIRM MODAL -->
<div id="cg-modal">
  <div class="cg-modal-box">
    <div class="cg-modal-hdr">Konfirmasi Penarikan</div>
    <div class="cg-modal-bd">
      <div style="font-size:11px;font-weight:800;color:#64748b;margin-bottom:6px">Total Tarik Dana</div>
      <div id="cg-modal-amt" style="font-size:24px;font-weight:900;color:#0c4a6e;margin-bottom:10px;letter-spacing:-1px"></div>
      <?php if (!$has_bank): ?>
      <div style="font-size:10px;font-weight:700;color:#ef4444;background:#fef2f2;padding:6px;border-radius:6px">Pastikan nomor rekening yang kamu masukkan sudah benar!</div>
      <?php endif; ?>
    </div>
    <div class="cg-modal-actions">
      <button type="button" class="cg-btn-cancel" onclick="document.getElementById('cg-modal').style.display='none'">Batal</button>
      <button type="button" class="cg-btn-confirm" onclick="confirmCGWd()">Gas Tarik!</button>
    </div>
  </div>
</div>

<script>
function selectWdAmount(btn, val) {
  document.querySelectorAll('.wd-amt-btn').forEach(el => el.classList.remove('active'));
  btn.classList.add('active');
  document.getElementById('selected-amount').value = val;
}

const _maskedNum = '<?= htmlspecialchars(mask_account($user['account_number'] ?? '')) ?>';
const _realNum   = '<?= htmlspecialchars($user['account_number'] ?? '') ?>';
let _numVisible  = false;
function toggleAccNum() {
  _numVisible = !_numVisible;
  const el  = document.getElementById('wd-accnum-display');
  const btn = document.getElementById('wd-accnum-toggle');
  if (el) el.textContent = _numVisible ? _realNum : _maskedNum;
  if (btn) btn.style.color = _numVisible ? '#fff' : 'rgba(255,255,255,0.6)';
}

(function(){
  const form  = document.getElementById('wd-form');
  const btn   = document.getElementById('wd-submit-btn');
  const minWd = <?= (int)$min_withdraw ?>;
  const maxWd = <?= (float)$max_available ?>;

  if (!form) return;

  form.addEventListener('submit', function(e) {
    const amtInput = document.querySelector('[name=amount]');
    const amt = amtInput ? parseFloat(amtInput.value) : 0;
    
    if (!amt || isNaN(amt)) { e.preventDefault(); if(typeof nToast !== 'undefined') nToast('Pilih nominal dulu bosku!', 'warn'); return; }
    if (amt < minWd) { e.preventDefault(); if(typeof nToast !== 'undefined') nToast('Minimal tarik Rp ' + minWd.toLocaleString('id-ID'), 'error'); return; }
    if (amt > maxWd) { e.preventDefault(); if(typeof nToast !== 'undefined') nToast('Maksimal tarik Rp ' + maxWd.toLocaleString('id-ID'), 'error'); return; }

    if (!form.dataset.confirmed) {
      e.preventDefault();
      document.getElementById('cg-modal-amt').innerText = 'Rp ' + amt.toLocaleString('id-ID');
      document.getElementById('cg-modal').style.display = 'flex';
    }
  });

  window.confirmCGWd = function() {
    document.getElementById('cg-modal').style.display = 'none';
    if (btn) { btn.disabled = true; btn.innerText = '⏳ Memproses...'; }
    
    const fd = new FormData(form);
    fd.append('ajax', '1');
    fetch('', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(res => {
      if (res.error) {
        if (typeof nToast !== 'undefined') nToast(res.error, 'error'); else alert(res.error);
        if (btn) { btn.disabled = false; btn.innerText = '🚀 Tarik Sekarang'; }
      } else {
        if (typeof nToast !== 'undefined') nToast(res.message, 'success'); else alert(res.message);
        setTimeout(() => window.location.href = '/history?tab=withdraw', 1500); // Redirect to history immediately
      }
    })
    .catch(() => {
      if (typeof nToast !== 'undefined') nToast('Koneksi terputus.', 'error'); else alert('Koneksi.');
      if (btn) { btn.disabled = false; btn.innerText = '🚀 Tarik Sekarang'; }
    });
  };
})();
</script>

<?php require dirname(__DIR__) . '/partials/footer.php'; ?>
