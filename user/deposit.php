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
$qris_raw     = '00020101021126610014COM.GO-JEK.WWW01189360091439543369860210G9543369860303UMI51440014ID.CO.QRIS.WWW0215ID10265064130650303UMI5204581653033605802ID5913TOKUY DIGITAL6013JAKARTA TIMUR61051341062070703A016304AC50';

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

$pageTitle  = 'Isi Saldo — NontonKuy';
$activePage = 'deposit';
require dirname(__DIR__) . '/partials/header.php';
?>

<style>
/* Trusted Neo-Brutalism Compact */
.dep-method { border: 2.5px solid var(--ink); border-radius: 8px; box-shadow: 3px 3px 0 var(--ink); background: #fff; overflow: hidden; margin-bottom: 10px; transition: transform 0.1s; }
.dep-method:active { transform: translate(2px, 2px); box-shadow: 1px 1px 0 var(--ink); }
.dep-method__hd { display: flex; align-items: center; gap: 10px; padding: 10px; cursor: pointer; user-select: none; }
.dep-method__ico { width: 36px; height: 36px; flex-shrink: 0; border-radius: 8px; border: 2px solid var(--ink); display: flex; align-items: center; justify-content: center; font-size: 18px; background: #fef08a; color: #d97706; }
.dep-method__info { flex: 1; min-width: 0; }
.dep-method__name { font-weight: 900; font-size: 13px; color: var(--ink); }
.dep-method__sub { font-size: 10px; color: #64748b; font-weight: 700; }
.dep-method__chev { font-size: 12px; color: #94a3b8; transition: transform 0.2s; flex-shrink: 0; }
.dep-method.open .dep-method__chev { transform: rotate(180deg); color: var(--ink); }
.dep-method__bd { padding: 0 10px 10px; border-top: 2.5px solid var(--ink); display: none; background: #f8fafc; }

.dep-rek { background: #fff; border: 2px dashed var(--ink); border-radius: 8px; padding: 12px; margin: 12px 0; text-align: center; }
.dep-rek__lbl { font-size: 10px; color: #64748b; font-weight: 800; margin-bottom: 4px; text-transform: uppercase; }
.dep-rek__bank { font-size: 12px; font-weight: 900; color: var(--ink); }
.dep-rek__num { font-size: 20px; font-weight: 900; letter-spacing: 1px; margin: 4px 0; color: var(--ink); }
.dep-rek__name { font-size: 11px; color: #475569; font-weight: 700; }

.qty-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 6px; margin-bottom: 12px; }
.qty-btn { font-size: 11px; font-weight: 800; padding: 8px 4px; text-align: center; background: #fff; border: 2px solid var(--ink); border-radius: 8px; box-shadow: 2px 2px 0 var(--ink); cursor: pointer; transition: transform 0.1s; }
.qty-btn:active { transform: translate(2px, 2px); box-shadow: 0px 0px 0 var(--ink); }
</style>



<div style="font-size:11px;font-weight:800;color:var(--ink);margin-bottom:12px;background:#fef08a;padding:8px 12px;border-radius:8px;border:2.5px solid var(--ink);box-shadow:2.5px 2.5px 0 var(--ink);display:flex;align-items:center;gap:6px">
  <i class="ph-fill ph-info" style="color:#d97706;font-size:16px"></i> <span>Min. top up <strong><?= format_rp($min_deposit) ?></strong></span>
</div>

<?php if ($flash): ?>
<div class="alert alert--<?= $flashType === 'error' ? 'error' : 'success' ?>" style="margin-bottom:10px;font-size:13px"><?= htmlspecialchars($flash) ?></div>
<?php endif; ?>

<?php if ($bank_enabled): ?>
<!-- Bank Transfer -->
<div class="dep-method" id="card-bank">
  <div class="dep-method__hd" onclick="toggleCard('bank')">
    <div class="dep-method__ico"><i class="ph-bold ph-bank"></i></div>
    <div class="dep-method__info">
      <div class="dep-method__name">Transfer Bank</div>
      <div class="dep-method__sub">BCA · Mandiri · BNI · BRI dll</div>
    </div>
    <div class="dep-method__chev" id="chev-bank"><i class="ph-bold ph-caret-down"></i></div>
  </div>
  <div class="dep-method__bd" id="body-bank">
    <div class="dep-rek">
      <div class="dep-rek__lbl">Rekening Tujuan</div>
      <div class="dep-rek__bank">Bank <?= htmlspecialchars($bankName) ?></div>
      <div class="dep-rek__num" id="rek-num"><?= htmlspecialchars($bankAccount) ?></div>
      <div class="dep-rek__name">a.n. <?= htmlspecialchars($bankHolder) ?></div>
      <button type="button" class="btn btn--secondary btn--sm" style="margin-top:8px;width:100%;font-size:11px;padding:6px" onclick="copyRek()">📋 Salin Nomor</button>
    </div>
    <form method="POST" enctype="multipart/form-data">
      <?= csrf_field() ?>
      <input type="hidden" name="form_token" value="<?= htmlspecialchars($_form_token) ?>">
      <input type="hidden" name="action" value="submit_bank">
      <div class="form-group" style="margin-top:12px;margin-bottom:10px">
        <label class="form-label" style="font-size:11px;font-weight:800;color:#475569">Nominal Top Up (Rp)</label>
        <div style="position:relative">
          <span style="position:absolute;left:12px;top:50%;transform:translateY(-50%);font-weight:900;color:var(--ink);font-size:14px">Rp</span>
          <input class="form-control" id="bank-amount" type="number" name="amount" min="<?= $min_deposit ?>" step="any" placeholder="Min. <?= number_format($min_deposit,0,'','') ?>" required style="padding-left:36px;font-size:16px;font-weight:900;height:42px">
        </div>
      </div>
      <div class="qty-grid">
        <?php foreach ([10000,25000,50000,100000,200000,500000] as $q): ?>
        <div class="qty-btn" onclick="setAmt('bank',<?= $q ?>)"><?= format_rp($q) ?></div>
        <?php endforeach; ?>
      </div>
      
      <?php if ($u_enabled): ?>
      <input type="hidden" name="unique_code" value="<?= $unique_code ?>">
      <?php endif; ?>

      <div class="form-group" style="margin-bottom:12px">
        <label class="form-label" style="font-size:11px;font-weight:800;color:#475569">Bukti <span style="font-weight:600;color:#94a3b8;font-size:9px">(JPG/PNG)</span></label>
        <input class="form-control" type="file" name="proof" accept="image/*" style="padding:8px;font-size:11px;background:#fff;height:38px">
      </div>
      <button type="submit" class="btn btn--primary btn--full no-dbl-submit" style="font-size:13px;height:42px;background:var(--yellow);color:var(--ink);border:2.5px solid var(--ink);box-shadow:3px 3px 0 var(--ink)"><i class="ph-bold ph-upload-simple"></i> Kirim Bukti</button>
    </form>
  </div>
</div>
<?php endif; ?>

<?php if ($qris_enabled && !empty($qris_raw)): ?>
<!-- QRIS -->
<div class="dep-method" id="card-qris">
  <div class="dep-method__hd" onclick="toggleCard('qris')">
    <div class="dep-method__ico" style="background:transparent;padding:0;border:none">
      <img src="/assets/banks/qris.jpg" style="width:100%;height:100%;object-fit:contain;background:#fff">
    </div>
    <div class="dep-method__info">
      <div class="dep-method__name">QRIS <span class="badge badge--success" style="font-size:8px;vertical-align:middle;margin-left:4px;padding:2px 4px">Otomatis</span></div>
      <div class="dep-method__sub">GoPay · OVO · Dana · ShopeePay</div>
    </div>
    <div class="dep-method__chev" id="chev-qris"><i class="ph-bold ph-caret-down"></i></div>
  </div>
  <div class="dep-method__bd" id="body-qris">
    <form method="POST" id="qris-form">
      <?= csrf_field() ?>
      <input type="hidden" name="form_token" value="<?= htmlspecialchars($_form_token) ?>">
      <input type="hidden" name="action" value="submit_qris">
      <div class="form-group" style="margin-top:12px;margin-bottom:10px">
        <label class="form-label" style="font-size:11px;font-weight:800;color:#475569">Nominal Top Up (Rp)</label>
        <div style="position:relative">
          <span style="position:absolute;left:12px;top:50%;transform:translateY(-50%);font-weight:900;color:var(--ink);font-size:14px">Rp</span>
          <input class="form-control" id="qris-amount" type="number" name="amount" min="<?= $min_deposit ?>" step="any" placeholder="Min. <?= number_format($min_deposit,0,'','') ?>" required style="padding-left:36px;font-size:16px;font-weight:900;height:42px">
        </div>
      </div>
      <div class="qty-grid">
        <?php foreach ([10000,25000,50000,100000,200000,500000] as $q): ?>
        <div class="qty-btn" onclick="setAmt('qris',<?= $q ?>)"><?= format_rp($q) ?></div>
        <?php endforeach; ?>
      </div>
      
      <?php if ($u_enabled): ?>
      <input type="hidden" name="unique_code" value="<?= $unique_code ?>">
      <?php endif; ?>

      <div class="alert alert--info" style="margin-bottom:12px;font-size:11px;padding:8px;display:flex;align-items:flex-start;gap:6px;border:2px solid var(--ink);background:#fef08a;color:var(--ink);border-radius:8px;box-shadow:2px 2px 0 var(--ink)">
        <i class="ph-fill ph-lightning" style="color:#d97706;font-size:16px;margin-top:1px"></i>
        <div>Klik Lanjut untuk bayar pakai QRIS.</div>
      </div>
      <button type="submit" class="btn btn--primary btn--full no-dbl-submit" style="font-size:13px;height:42px;background:var(--yellow);color:var(--ink);border:2.5px solid var(--ink);box-shadow:3px 3px 0 var(--ink)"><i class="ph-bold ph-qr-code"></i> Lanjut Bayar QRIS</button>
    </form>
  </div>
</div>
<?php endif; ?>

<?php if (!$bank_enabled && (!$qris_enabled || empty($qris_raw))): ?>
<div class="alert alert--warn" style="font-size:13px">⚠️ Tidak ada metode deposit aktif. Hubungi admin.</div>
<?php endif; ?>

<!-- Riwayat -->
<?php if (!empty($deps)): ?>
<div class="section-header" style="margin-top:16px;margin-bottom:10px">
  <div class="section-title" style="font-size:13px;display:flex;align-items:center;gap:4px"><i class="ph-fill ph-clock-counter-clockwise"></i> Riwayat Top Up</div>
  <a href="/history" class="section-link" style="font-weight:800;font-size:11px">Semua →</a>
</div>
<div class="c-list" style="display:flex;flex-direction:column;gap:8px">
  <?php foreach ($deps as $d): ?>
  <div class="list-item" style="padding:10px 12px;background:#fff;border:2px solid var(--ink);border-radius:10px;box-shadow:2px 2px 0 var(--ink)">
    <div class="list-item__icon" style="background:<?= $d['method']==='qris'?'#d1fae5':'#fef08a' ?>;color:<?= $d['method']==='qris'?'var(--green)':'#d97706' ?>;width:30px;height:30px;font-size:14px;display:flex;align-items:center;justify-content:center;border-radius:6px;border:1.5px solid var(--ink)">
      <i class="<?= $d['method']==='qris' ? 'ph-bold ph-qr-code' : 'ph-bold ph-bank' ?>"></i>
    </div>
    <div class="list-item__body" style="margin-left:8px;line-height:1.2">
      <div class="list-item__title" style="font-size:12px;font-weight:900"><?= format_rp((float)$d['amount']) ?></div>
      <div class="list-item__sub" style="font-size:9px;color:#666;font-weight:700;margin-top:2px"><?= strtoupper($d['method']) ?> · <?= date('d M H:i', strtotime($d['created_at'])) ?></div>
    </div>
    <div class="list-item__right" style="display:flex;flex-direction:column;align-items:flex-end;gap:4px">
      <span class="badge badge--<?= match($d['status']){'confirmed'=>'success','pending'=>'warn','rejected'=>'error',default=>'error'} ?>" style="font-size:9px;padding:2px 4px">
        <?= ucfirst($d['status']) ?>
      </span>
      <?php if ($d['status']==='pending' && $d['method']==='qris'): ?>
      <a href="/pay?id=<?= $d['id'] ?>" class="btn btn--yellow btn--sm" style="padding:2px 6px;font-size:9px"><i class="ph-bold ph-arrow-right"></i> Bayar</a>
      <?php endif; ?>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<script>
function toggleCard(id) {
  ['bank','qris'].forEach(k => {
    const el = document.getElementById('card-' + k);
    const b = document.getElementById('body-' + k);
    if (b) b.style.display = 'none';
    if (el) el.classList.remove('open');
  });
  const card = document.getElementById('card-' + id);
  const body = document.getElementById('body-' + id);
  if (body && body.style.display === 'none') {
    body.style.display = 'block';
    if (card) card.classList.add('open');
  }
}
function setAmt(type, v) {
  if (type === 'bank') document.querySelector('#body-bank input[name="amount"]').value = v;
  if (type === 'qris') document.getElementById('qris-amount').value = v;
  if (typeof updateTotals === 'function') updateTotals();
}
function copyRek() {
  const t = document.getElementById('rek-num').textContent.trim();
  nToast.copy ? nToast.copy(t, 'Nomor rekening') : navigator.clipboard.writeText(t);
}
document.addEventListener('DOMContentLoaded', () => {
  const cards = ['bank','qris'].filter(k => document.getElementById('card-' + k));
  if (cards.length === 1) toggleCard(cards[0]);
});
</script>

<?php require dirname(__DIR__) . '/partials/footer.php'; ?>
