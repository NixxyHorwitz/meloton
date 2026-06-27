<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/auth/guard.php';

$flash = $flashType = '';
$min_deposit  = (float) setting($pdo, 'min_deposit', '10000');
$bank_enabled = setting($pdo, 'bank_enabled', '1') === '1';
$qris_enabled = setting($pdo, 'qris_enabled', '1') === '1';
$bankName     = setting($pdo, 'bank_name', 'BCA');
$bankAccount  = setting($pdo, 'bank_account', '-');
$bankHolder   = setting($pdo, 'bank_holder', 'Admin');
$qris_raw     = '00020101021126610014COM.GO-JEK.WWW01189360091431528826820210G1528826820303UMI51440014ID.CO.QRIS.WWW0215ID10265193497510303UMI5204899953033605802ID5916PRO PLAN DIGITAL6013JAKARTA UTARA61051411062070703A016304B1F7';

$u_enabled = setting($pdo, 'depo_unique_code_enabled', '0') === '1';
$u_min = (int)setting($pdo, 'depo_unique_code_min', '1');
$u_max = (int)setting($pdo, 'depo_unique_code_max', '999');
$unique_code = $u_enabled ? random_int(min($u_min, $u_max), max($u_min, $u_max)) : 0;

// ── Double-submit prevention ──────────────────────────────────────────────
$_ftk = 'dep_form_token';
if (empty($_SESSION[$_ftk])) $_SESSION[$_ftk] = bin2hex(random_bytes(16));
$_form_token = $_SESSION[$_ftk];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Reconnect MySQL in case connection has gone away (error 2006/2013)
    pdo_reconnect($pdo);
    
    $submitted_ftk = $_POST['form_token'] ?? '';
    if (!hash_equals($_SESSION[$_ftk] ?? '', $submitted_ftk)) {
        $flash = '⚠️ Request kamu gagal diproses atau gak valid. Coba refresh halaman dulu ya!';
        $flashType = 'error';
        goto end_dep;
    }
    // Invalidate immediately to prevent double-submit
    unset($_SESSION[$_ftk]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit_bank') {
    $amount = (int) preg_replace('/\D/', '', $_POST['amount'] ?? '0');
    $u_code = (int) preg_replace('/\D/', '', $_POST['unique_code'] ?? '0');
    if ($u_enabled && $u_code >= min($u_min, $u_max) && $u_code <= max($u_min, $u_max)) {
        $amount += $u_code;
    }
    if ($amount < $min_deposit) {
        $flash = 'Minimal deposit ' . format_rp($min_deposit) . ' ya.'; $flashType = 'error';
    } elseif (!$bank_enabled) {
        $flash = 'Transfer bank lagi gak tersedia nih.'; $flashType = 'error';
    } else {
        $proof = null;
        if (!empty($_FILES['proof']['tmp_name'])) {
            $ext = strtolower(pathinfo($_FILES['proof']['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg','jpeg','png','webp'])) {
                $flash = 'Bukti transfer harus format JPG/PNG/WEBP ya.'; $flashType = 'error';
                goto end_dep;
            }
            $dir = dirname(__DIR__) . '/uploads/deposits/';
            if (!is_dir($dir)) mkdir($dir, 0755, true);
            $fname = 'dep_' . $user['id'] . '_' . time() . '.' . $ext;
            move_uploaded_file($_FILES['proof']['tmp_name'], $dir . $fname);
            $proof = 'deposits/' . $fname;
        }
        $pdo->prepare("INSERT INTO deposits (user_id,amount,method,proof_image) VALUES (?,?,?,?)")
            ->execute([$user['id'], $amount, 'transfer', $proof]);
        $dep_id = $pdo->lastInsertId();
        
        $msg = "<b>📢 DEPOSIT BARU (Transfer)</b>\nUser: {$user['username']}\nAmount: " . format_rp((float)$amount) . "\nStatus: Pending";
        $kb = [
            [['text'=>'✅ Approve', 'callback_data'=>'depo_approve_'.$dep_id], ['text'=>'❌ Reject', 'callback_data'=>'depo_reject_'.$dep_id]],
            [['text'=>'⚡ Acc Expired', 'callback_data'=>'depo_accexp_'.$dep_id], ['text'=>'🔄 Refresh Status', 'callback_data'=>'refresh_depo_'.$dep_id]]
        ];
        send_telegram_notif($pdo, $msg, $kb, 'depo');
        
        // Success — regenerate token for next request
        $_SESSION[$_ftk] = bin2hex(random_bytes(16));
        $flash = '✅ Bukti transfer berhasil dikirim! Admin bakal memproses dalam 1×24 jam ya.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit_qris') {
    $amount = (int) preg_replace('/\D/', '', $_POST['amount'] ?? '0');
    $u_code = (int) preg_replace('/\D/', '', $_POST['unique_code'] ?? '0');
    if ($u_enabled && $u_code >= min($u_min, $u_max) && $u_code <= max($u_min, $u_max)) {
        $amount += $u_code;
    }
    if ($amount < $min_deposit) {
        $flash = 'Minimal deposit ' . format_rp($min_deposit) . ' ya.'; $flashType = 'error';
    } elseif (!$qris_enabled || empty($qris_raw)) {
        $flash = 'QRIS lagi gak tersedia nih.'; $flashType = 'error';
    } else {
        $pdo->prepare("INSERT INTO deposits (user_id,amount,method,status) VALUES (?,?,'qris','pending')")
            ->execute([$user['id'], $amount]);
        $dep_id = $pdo->lastInsertId();
        
        $merchant_name = 'Unknown';
        $idx = 0;
        while ($idx < strlen($qris_raw) - 4) {
            $tag = substr($qris_raw, $idx, 2);
            $len = (int)substr($qris_raw, $idx+2, 2);
            if ($tag === '59') {
                $merchant_name = substr($qris_raw, $idx+4, $len);
                break;
            }
            $idx += 4 + $len;
        }

        $fmt_amount = format_rp((float)$amount);
        $msg = "📢 <b>DEPOSIT BARU ({$fmt_amount})</b>\n";
        $msg .= "━━━━━━━━━━━━━━━━━━━━━━\n";
        $msg .= "👤 <b>User:</b> <code>" . htmlspecialchars($user['username']) . "</code>\n";
        $msg .= "💵 <b>Amount:</b> <code>" . format_rp((float)$amount) . "</code>\n";
        $msg .= "🕒 <b>Time:</b> <code>" . date('d-m-Y H:i:s') . " WIB</code>\n";
        $msg .= "🏪 <b>QRIS:</b> <code>" . htmlspecialchars($merchant_name) . "</code>\n";
        $msg .= "💳 <b>Method:</b> <code>QRIS Otomatis</code>\n";
        $msg .= "⏳ <b>Status:</b> <code>Pending</code>\n";
        $msg .= "━━━━━━━━━━━━━━━━━━━━━━\n";
        $msg .= "<i>Sistem memantau secara otomatis. Status di Telegram ini akan diperbarui ketika sukses terbayar via callback!</i>";
        
        $kb = [
            [['text'=>'✅ Approve', 'callback_data'=>'depo_approve_'.$dep_id], ['text'=>'❌ Reject', 'callback_data'=>'depo_reject_'.$dep_id]],
            [['text'=>'⚡ Acc Expired', 'callback_data'=>'depo_accexp_'.$dep_id], ['text'=>'🔄 Refresh Status', 'callback_data'=>'refresh_depo_'.$dep_id]]
        ];
        
        $tg_msg_id = send_telegram_notif($pdo, $msg, $kb, 'depo');
        if ($tg_msg_id) {
            $pdo->prepare("UPDATE deposits SET tg_msg_id = ? WHERE id = ?")->execute([$tg_msg_id, $dep_id]);
        }

        redirect('/pay?id=' . $dep_id);
    }
}
end_dep:

$deps = $pdo->prepare("SELECT * FROM deposits WHERE user_id=? ORDER BY created_at DESC LIMIT 6");
$deps->execute([$user['id']]); $deps = $deps->fetchAll();

$pageTitle  = 'Isi Saldo — Meloton';
$activePage = 'deposit';
require dirname(__DIR__) . '/partials/header.php';
?>

<style>
/* ══════════════════════════════════════════════
   DEPOSIT PAGE — CASUAL GAME STYLE (V3)
   ══════════════════════════════════════════════ */
.dep-page { padding: 0 0 20px; }

/* ── Hero Balance (Compact & Ornamented) ── */
.dep-hero {
  background: linear-gradient(135deg, #1e3a8a, #3b82f6, #60a5fa);
  border: 3px solid #1e40af;
  border-radius: 12px;
  box-shadow: 0 5px 0 #1e3a8a;
  padding: 12px;
  text-align: center;
  position: relative;
  overflow: hidden;
  margin-bottom: 12px;
}
.dep-hero::before { content:''; position:absolute; top:-20px; left:-20px; width:80px; height:80px; background:url('/assets/dollar.png') no-repeat center/contain; opacity:0.1; transform:rotate(-15deg); pointer-events:none; }
.dep-hero::after { content:''; position:absolute; bottom:-20px; right:-20px; width:100px; height:100px; background:rgba(255,255,255,0.06); border-radius:50%; pointer-events:none; }
.dep-hero-star { position:absolute; top:10px; right:30px; color:#fde68a; font-size:20px; opacity:0.3; transform:rotate(20deg); pointer-events:none; }
.dep-hero-dot { position:absolute; bottom:15px; left:40px; width:6px; height:6px; background:#fde68a; border-radius:50%; opacity:0.4; pointer-events:none; }

.dep-hero__lbl { font-size:11px; font-weight:900; color:rgba(255,255,255,0.7); margin-bottom:2px; text-transform:uppercase; letter-spacing:1px; display:flex; align-items:center; justify-content:center; gap:4px; position:relative; z-index:1; }
.dep-hero__val { font-size:24px; font-weight:900; color:#eff6ff; text-shadow:0 2px 4px rgba(0,0,0,0.3); letter-spacing:-1px; position:relative; z-index:1; margin-top: 2px; }

/* ── Alerts ── */
.dep-alert {
  padding: 8px 10px;
  border-radius: 10px;
  font-size: 10px; font-weight: 800;
  display: flex; gap: 8px; align-items: center;
  margin-bottom: 12px;
  border: 2px solid;
  line-height: 1.3;
}
.dep-alert--err { background: #fef2f2; color: #991b1b; border-color: #fca5a5; }
.dep-alert--warn { background: #fffbeb; color: #b45309; border-color: #fcd34d; }
.dep-alert--succ { background: #f0fdf4; color: #166534; border-color: #86efac; }
.dep-alert--info { background: #eff6ff; color: #1e40af; border-color: #93c5fd; }
.dep-alert-icon { font-size: 16px; flex-shrink: 0; }

/* ── Segmented Tabs ── */
.dep-tabs { display: flex; background: #e2e8f0; border-radius: 12px; padding: 3px; margin-bottom: 12px; border: 2px solid #cbd5e1; }
.dep-tab { flex: 1; text-align: center; padding: 8px; font-size: 11px; font-weight: 900; color: #64748b; cursor: pointer; border-radius: 8px; transition: all 0.2s; display:flex; align-items:center; justify-content:center; gap:6px; }
.dep-tab.active { background: #fff; color: #1e3a8a; box-shadow: 0 2px 0 #cbd5e1; }
.dep-tab.active i { color: #3b82f6; }

/* ── Form Card ── */
.dep-form-card { background: #fff; border: 1.5px solid #93c5fd; border-radius: 12px; box-shadow: 0 3px 0 #93c5fd; padding: 12px 10px; margin-bottom: 12px; display: none; }
.dep-form-card.active { display: block; animation: fadein 0.3s ease; }
@keyframes fadein { from { opacity: 0; transform: translateY(5px); } to { opacity: 1; transform: translateY(0); } }

/* ── Bank Account Info ── */
.dep-rek { background: linear-gradient(135deg, #eff6ff, #dbeafe); border: 1.5px dashed #93c5fd; border-radius: 10px; padding: 10px; margin-bottom: 12px; text-align: center; }
.dep-rek__lbl { font-size: 9px; color: #3b82f6; font-weight: 900; margin-bottom: 2px; text-transform: uppercase; letter-spacing: 0.5px; }
.dep-rek__bank { font-size: 11px; font-weight: 900; color: #1e3a8a; }
.dep-rek__num { font-size: 16px; font-weight: 900; letter-spacing: 1px; margin: 2px 0; color: #1e3a8a; }
.dep-rek__name { font-size: 10px; color: #64748b; font-weight: 800; }
.dep-rek__copy {
  margin-top: 6px; width: 100%;
  background: #fff; border: 1.5px solid #93c5fd; border-radius: 6px;
  padding: 6px; font-size: 10px; font-weight: 900; color: #2563eb;
  cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 4px;
  box-shadow: 0 2px 0 #93c5fd; transition: transform 0.1s;
}
.dep-rek__copy:active { transform: translateY(2px); box-shadow: 0 0 0 #93c5fd; }

/* ── Amount Input (Minimalist & Compact) ── */
.dep-amount-wrap { background: #f8fafc; border: 1.5px solid #cbd5e1; border-radius: 8px; padding: 8px 10px; text-align: left; margin-bottom: 12px; position: relative; box-shadow: inset 0 1px 2px rgba(0,0,0,0.05); }
.dep-amount-lbl { font-size: 9px; font-weight: 900; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 4px; display: block; }
.dep-amount-inner { display: flex; align-items: center; justify-content: flex-start; gap: 6px; }
.dep-amount-prefix { font-size: 14px; font-weight: 900; color: #1e3a8a; }
.dep-amount-input {
  width: 100%; background: transparent; border: none;
  font-size: 16px; font-weight: 900; color: #1e3a8a;
  font-family: inherit; outline: none; text-align: left;
  padding: 0; margin: 0; box-sizing: border-box;
}
.dep-amount-input::placeholder { color: #cbd5e1; }

/* ── Amount Grid (Pills) ── */
.dep-grid { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 12px; justify-content: center; }
.dep-amt-btn {
  background: #fff; border: 1.5px solid #cbd5e1; border-radius: 8px;
  padding: 6px 8px; flex: 1 1 calc(33.333% - 6px); min-width: 70px;
  text-align: center; cursor: pointer; transition: all 0.1s; box-shadow: 0 2px 0 #cbd5e1; outline: none;
}
.dep-amt-btn:active { transform: translateY(2px); box-shadow: 0 0 0 #cbd5e1; }
.dep-amt-btn.active { background: linear-gradient(135deg, #60a5fa, #3b82f6); border-color: #1d4ed8; box-shadow: 0 2px 0 #1e40af; color: #fff; }
.dep-amt-btn.active:active { transform: translateY(2px); box-shadow: 0 0 0 #1e40af; }
.dep-amt-val { font-size: 10px; font-weight: 900; color: #0f172a; letter-spacing: -0.5px; }
.dep-amt-btn.active .dep-amt-val { color: #fff; }

/* ── File Upload ── */
.dep-file-wrap { margin-bottom: 12px; }
.dep-file-lbl { font-size: 9px; font-weight: 900; color: #475569; margin-bottom: 4px; display: block; text-align: center; }
.dep-file {
  width: 100%; background: #f8fafc;
  border: 1.5px dashed #94a3b8; border-radius: 8px;
  padding: 8px; font-size: 10px; font-weight: 800; color: #475569;
  cursor: pointer; box-sizing: border-box; text-align: center;
}
.dep-file::file-selector-button { background: #e2e8f0; border: none; border-radius: 4px; padding: 4px 6px; margin-right: 6px; font-weight: 900; color: #334155; cursor: pointer; transition: background 0.2s; }
.dep-file::file-selector-button:hover { background: #cbd5e1; }

/* ── Submit Button ── */
.dep-submit {
  width: 100%; padding: 10px;
  background: linear-gradient(135deg, #34d399, #10b981);
  border: 1.5px solid #059669;
  border-radius: 8px;
  color: #fff; font-size: 12px; font-weight: 900; text-transform: uppercase; letter-spacing: 0.5px;
  box-shadow: 0 3px 0 #047857;
  cursor: pointer; transition: transform 0.1s;
  display: flex; align-items: center; justify-content: center; gap: 6px;
}
.dep-submit:active { transform: translateY(3px); box-shadow: 0 0 0 #047857; }

/* ── History (Vertical List at Bottom) ── */
.hist-wrap { margin-top: 20px; border-top: 2px dashed #cbd5e1; padding-top: 12px; }
.hist-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; }
.hist-head h3 { font-size: 13px; font-weight: 900; color: #1e3a8a; margin: 0; display:flex; align-items:center; gap:6px; }
.hist-head a { font-size: 9px; font-weight: 900; color: #3b82f6; text-decoration: none; background: #eff6ff; padding: 4px 8px; border-radius: 6px; border: 1.5px solid #bfdbfe; box-shadow: 0 2px 0 #bfdbfe; transition: transform 0.1s; }
.hist-head a:active { transform: translateY(2px); box-shadow: 0 0 0 #bfdbfe; }
.hist-list { display: flex; flex-direction: column; gap: 8px; }
.hist-card { background: #fff; border: 1.5px solid #bfdbfe; border-radius: 10px; padding: 10px; box-shadow: 0 2px 0 #bfdbfe; display: flex; align-items: center; gap: 8px; }
.hist-card-icon { width: 32px; height: 32px; border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 16px; flex-shrink: 0; }
.hist-card-body { flex: 1; min-width: 0; }
.hist-card-amt { font-size: 13px; font-weight: 900; color: #1e3a8a; letter-spacing: -0.5px; margin-bottom: 2px; }
.hist-card-date { font-size: 9px; font-weight: 800; color: #64748b; }
.hist-card-right { display: flex; flex-direction: column; align-items: flex-end; gap: 4px; }
.hist-badge { font-size: 8px; font-weight: 900; padding: 2px 6px; border-radius: 4px; text-transform: uppercase; }

.dep-badge.pending { background: #fef3c7; color: #b45309; }
.dep-badge.confirmed { background: #d1fae5; color: #065f46; }
.dep-badge.rejected { background: #fee2e2; color: #991b1b; }
.dep-badge.error { background: #fee2e2; color: #991b1b; }
</style>

<div class="dep-page">
  <!-- HERO BALANCE -->
  <div class="dep-hero">
    <i class="ph-fill ph-star dep-hero-star"></i>
    <div class="dep-hero-dot"></div>
    <div class="dep-hero__lbl"><i class="ph-bold ph-wallet"></i> Saldo Beli</div>
    <div class="dep-hero__val"><?= format_rp((float)$user['balance_dep']) ?></div>
  </div>

  <div class="dep-alert dep-alert--warn">
    <div class="dep-alert-icon">💡</div>
    <div style="flex:1">Minimal top up <strong><?= format_rp($min_deposit) ?></strong>.</div>
  </div>

  <?php if ($flash): ?>
  <div class="dep-alert dep-alert--<?= $flashType === 'error' ? 'err' : 'succ' ?>">
    <div class="dep-alert-icon"><?= $flashType === 'error' ? '❌' : '✨' ?></div>
    <div style="flex:1"><?= htmlspecialchars($flash) ?></div>
  </div>
  <?php endif; ?>

  <?php if (!$bank_enabled && (!$qris_enabled || empty($qris_raw))): ?>
  <div class="dep-alert dep-alert--err">
    <div class="dep-alert-icon">⚠️</div>
    <div style="flex:1">Tidak ada metode deposit aktif. Hubungi admin.</div>
  </div>
  <?php else: ?>

  <!-- SEGMENTED TABS -->
  <div class="dep-tabs">
    <?php if ($qris_enabled && !empty($qris_raw)): ?>
    <div class="dep-tab" id="tab-qris" onclick="switchForm('qris')">
      <i class="ph-bold ph-qr-code"></i> QRIS
    </div>
    <?php endif; ?>
    <?php if ($bank_enabled): ?>
    <div class="dep-tab" id="tab-bank" onclick="switchForm('bank')">
      <i class="ph-bold ph-bank"></i> Bank Transfer
    </div>
    <?php endif; ?>
  </div>

  <?php if ($qris_enabled && !empty($qris_raw)): ?>
  <!-- QRIS FORM -->
  <div class="dep-form-card" id="form-qris">
    <form method="POST">
      <?= csrf_field() ?>
      <input type="hidden" name="form_token" value="<?= htmlspecialchars($_form_token) ?>">
      <input type="hidden" name="action" value="submit_qris">
      
      <div class="dep-amount-wrap">
        <label class="dep-amount-lbl">Nominal Top Up</label>
        <div class="dep-amount-inner">
          <span class="dep-amount-prefix">Rp</span>
          <input class="dep-amount-input" id="qris-amount" type="number" name="amount" min="<?= $min_deposit ?>" step="any" placeholder="<?= number_format($min_deposit,0,'','') ?>" required>
        </div>
      </div>
      
      <div class="dep-grid">
        <?php foreach ([10000,25000,50000,100000,200000,500000] as $q): ?>
        <div class="dep-amt-btn" onclick="setAmt('qris',<?= $q ?>, this)">
          <div class="dep-amt-val"><?= format_rp($q) ?></div>
        </div>
        <?php endforeach; ?>
      </div>
      
      <?php if ($u_enabled): ?>
      <input type="hidden" name="unique_code" value="<?= $unique_code ?>">
      <?php endif; ?>

      <div class="dep-alert dep-alert--info" style="margin-bottom:20px; border-radius:14px;">
        <div class="dep-alert-icon"><i class="ph-fill ph-lightning" style="color:#2563eb"></i></div>
        <div style="flex:1; font-size:12px;">Klik Lanjut untuk bayar instan pakai QRIS.</div>
      </div>
      <button type="submit" class="dep-submit no-dbl-submit" style="background: linear-gradient(135deg, #3b82f6, #1d4ed8); border-color: #1e40af; box-shadow: 0 6px 0 #1e3a8a;"><i class="ph-bold ph-qr-code"></i> Lanjut Bayar QRIS</button>
    </form>
  </div>
  <?php endif; ?>

  <?php if ($bank_enabled): ?>
  <!-- BANK FORM -->
  <div class="dep-form-card" id="form-bank">
    <div class="dep-rek">
      <div class="dep-rek__lbl">Rekening Tujuan</div>
      <div class="dep-rek__bank">Bank <?= htmlspecialchars($bankName) ?></div>
      <div class="dep-rek__num" id="rek-num"><?= htmlspecialchars($bankAccount) ?></div>
      <div class="dep-rek__name">a.n. <?= htmlspecialchars($bankHolder) ?></div>
      <button type="button" class="dep-rek__copy" onclick="copyRek()"><i class="ph-bold ph-copy"></i> Salin Nomor Rekening</button>
    </div>
    <form method="POST" enctype="multipart/form-data">
      <?= csrf_field() ?>
      <input type="hidden" name="form_token" value="<?= htmlspecialchars($_form_token) ?>">
      <input type="hidden" name="action" value="submit_bank">
      
      <div class="dep-amount-wrap">
        <label class="dep-amount-lbl">Nominal Top Up</label>
        <div class="dep-amount-inner">
          <span class="dep-amount-prefix">Rp</span>
          <input class="dep-amount-input" id="bank-amount" type="number" name="amount" min="<?= $min_deposit ?>" step="any" placeholder="<?= number_format($min_deposit,0,'','') ?>" required>
        </div>
      </div>
      
      <div class="dep-grid">
        <?php foreach ([10000,25000,50000,100000,200000,500000] as $q): ?>
        <div class="dep-amt-btn" onclick="setAmt('bank',<?= $q ?>, this)">
          <div class="dep-amt-val"><?= format_rp($q) ?></div>
        </div>
        <?php endforeach; ?>
      </div>
      
      <?php if ($u_enabled): ?>
      <input type="hidden" name="unique_code" value="<?= $unique_code ?>">
      <?php endif; ?>

      <div class="dep-file-wrap">
        <label class="dep-file-lbl">Upload Bukti Transfer <span style="font-weight:700;color:#94a3b8;font-size:10px">(JPG/PNG)</span></label>
        <input class="dep-file" type="file" name="proof" accept="image/*" required>
      </div>
      <button type="submit" class="dep-submit no-dbl-submit"><i class="ph-bold ph-paper-plane-tilt"></i> Kirim Bukti</button>
    </form>
  </div>
  <?php endif; ?>
  <?php endif; ?>

  <!-- RIWAYAT (Paling Bawah) -->
  <?php if (!empty($deps)): ?>
  <div class="hist-wrap">
    <div class="hist-head">
      <h3><i class="ph-fill ph-clock-counter-clockwise"></i> Riwayat Top Up</h3>
      <a href="/history">Semua →</a>
    </div>
    <div class="hist-list">
      <?php foreach ($deps as $d): ?>
        <?php
        $st = strtolower($d['status']);
        $bclass = 'error';
        if ($st === 'pending') $bclass = 'pending';
        elseif ($st === 'confirmed' || $st === 'approved') $bclass = 'confirmed';
        elseif ($st === 'rejected') $bclass = 'rejected';
        ?>
        <div class="hist-card">
          <div class="hist-card-icon" style="background:<?= $d['method']==='qris'?'#d1fae5':'#dbeafe' ?>;color:<?= $d['method']==='qris'?'#059669':'#2563eb' ?>;">
            <i class="<?= $d['method']==='qris' ? 'ph-bold ph-qr-code' : 'ph-bold ph-bank' ?>"></i>
          </div>
          <div class="hist-card-body">
            <div class="hist-card-amt"><?= format_rp((float)$d['amount']) ?></div>
            <div class="hist-card-date"><?= strtoupper($d['method']) ?> · <?= date('d M H:i', strtotime($d['created_at'])) ?></div>
          </div>
          <div class="hist-card-right">
            <span class="hist-badge dep-badge <?= $bclass ?>"><?= ucfirst($d['status']) ?></span>
            <?php if ($st === 'pending' && $d['method'] === 'qris'): ?>
            <a href="/pay?id=<?= $d['id'] ?>" style="display:inline-block; margin-top:4px; font-size:10px; font-weight:900; color:#fff; background:#3b82f6; padding:6px 10px; border-radius:8px; text-decoration:none; box-shadow:0 3px 0 #1d4ed8;"><i class="ph-bold ph-arrow-right"></i> Bayar</a>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

</div>

<script>
function switchForm(id) {
  const tabs = document.querySelectorAll('.dep-tab');
  const forms = document.querySelectorAll('.dep-form-card');
  tabs.forEach(t => t.classList.remove('active'));
  forms.forEach(f => f.classList.remove('active'));
  
  const targetTab = document.getElementById('tab-' + id);
  const targetForm = document.getElementById('form-' + id);
  
  if (targetTab) targetTab.classList.add('active');
  if (targetForm) targetForm.classList.add('active');
}

function setAmt(type, v, btn) {
  if (type === 'bank') {
    document.getElementById('bank-amount').value = v;
    const btns = document.querySelectorAll('#form-bank .dep-amt-btn');
    btns.forEach(b => b.classList.remove('active'));
  }
  if (type === 'qris') {
    document.getElementById('qris-amount').value = v;
    const btns = document.querySelectorAll('#form-qris .dep-amt-btn');
    btns.forEach(b => b.classList.remove('active'));
  }
  if (btn) btn.classList.add('active');
}

function copyRek() {
  const t = document.getElementById('rek-num').textContent.trim();
  nToast.copy ? nToast.copy(t, 'Nomor rekening') : navigator.clipboard.writeText(t);
}

document.addEventListener('DOMContentLoaded', () => {
  const tabQris = document.getElementById('tab-qris');
  const tabBank = document.getElementById('tab-bank');
  if (tabQris) {
    switchForm('qris');
  } else if (tabBank) {
    switchForm('bank');
  }
});
</script>

<?php require dirname(__DIR__) . '/partials/footer.php'; ?>
