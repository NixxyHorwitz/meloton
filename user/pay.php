<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/bootstrap.php';
$user = require_auth($pdo);

$dep_id = (int)($_GET['id'] ?? 0);
if (!$dep_id) redirect('/deposit');

$dep = $pdo->prepare("SELECT * FROM deposits WHERE id=? AND user_id=?");
$dep->execute([$dep_id, $user['id']]);
$dep = $dep->fetch();
if (!$dep || $dep['method'] !== 'qris') redirect('/deposit');

// ── AJAX: check_status — HARUS sebelum redirect confirmed ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    pdo_reconnect($pdo);
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'check_status') {
    header('Content-Type: application/json; charset=utf-8');
    $st = $pdo->prepare("SELECT status FROM deposits WHERE id=? AND user_id=?");
    $st->execute([$dep_id, $user['id']]);
    $row = $st->fetch();
    echo json_encode(['confirmed' => ($row && $row['status'] === 'confirmed')]);
    exit;
}

// ── PHP Proxy: download QR image (avoid exposing external URL to browser) ──
if (($_GET['action'] ?? '') === 'dl_qr') {
    $qris_raw_dl = '00020101021126610014COM.GO-JEK.WWW01189360091431528826820210G1528826820303UMI51440014ID.CO.QRIS.WWW0215ID10265193497510303UMI5204899953033605802ID5916PRO PLAN DIGITAL6013JAKARTA UTARA61051411062070703A016304B1F7';
    $qris_str_dl = !empty($qris_raw_dl) ? qris_with_amount($qris_raw_dl, (int)(float)$dep['amount']) : '';
    if (!$qris_str_dl) { http_response_code(404); exit('QR not available'); }
    $remote = 'https://api.qrserver.com/v1/create-qr-code/?size=400x400&data=' . urlencode($qris_str_dl);
    $img    = @file_get_contents($remote);
    if (!$img) { http_response_code(502); exit('Failed to generate QR'); }
    header('Content-Type: image/png');
    header('Content-Disposition: attachment; filename="QRIS-Meloton-dep' . $dep_id . '.png"');
    header('Content-Length: ' . strlen($img));
    header('Cache-Control: no-store');
    echo $img;
    exit;
}

if ($dep['status'] === 'confirmed') redirect('/history');

$qris_raw     = '00020101021126610014COM.GO-JEK.WWW01189360091431528826820210G1528826820303UMI51440014ID.CO.QRIS.WWW0215ID10265193497510303UMI5204899953033605802ID5916PRO PLAN DIGITAL6013JAKARTA UTARA61051411062070703A016304B1F7';
$confirm_mode = setting($pdo, 'deposit_confirm_mode', 'manual');
$amount       = (float)$dep['amount'];
$qris_str     = !empty($qris_raw) ? qris_with_amount($qris_raw, (int)$amount) : '';
$_favicon     = setting($pdo, 'favicon_path', '');
$fav_url      = $_favicon ? '/' . ltrim($_favicon, '/') : '';

// Upload bukti
$flash = $flashType = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload_proof') {
    if (empty($_FILES['proof']['tmp_name'])) {
        $flash = 'Pilih file bukti pembayaran.'; $flashType = 'error';
    } else {
        $ext = strtolower(pathinfo($_FILES['proof']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg','jpeg','png','webp'])) {
            $flash = 'Format harus JPG/PNG/WEBP.'; $flashType = 'error';
        } else {
            $dir = dirname(__DIR__) . '/uploads/deposits/';
            if (!is_dir($dir)) mkdir($dir, 0755, true);
            $fname = 'dep_' . $user['id'] . '_' . time() . '.' . $ext;
            move_uploaded_file($_FILES['proof']['tmp_name'], $dir . $fname);
            $pdo->prepare("UPDATE deposits SET proof_image=? WHERE id=?")->execute(['deposits/' . $fname, $dep_id]);
            $flash = '✅ Bukti berhasil diupload! Admin akan memverifikasi segera.';
            
            // Telegram Notif
            $scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'] ?? 'Meloton.online';
            $proofUrl = $scheme . '://' . $host . '/uploads/deposits/' . $fname;
            
            $msg = "📢 <b>BUKTI DEPOSIT DIUPLOAD</b>\n";
            $msg .= "━━━━━━━━━━━━━━━━━━━━━━\n";
            $msg .= "👤 <b>User:</b> <code>" . htmlspecialchars($user['username']) . "</code>\n";
            $msg .= "💵 <b>Amount:</b> <code>" . format_rp((float)$dep['amount']) . "</code>\n";
            $msg .= "🖼️ <b>Bukti:</b> <a href=\"{$proofUrl}\">Klik untuk lihat gambar</a>\n";
            $msg .= "━━━━━━━━━━━━━━━━━━━━━━\n";
            $msg .= "<i>Silakan cek gambar bukti di atas sebelum melakukan Approve.</i>";
            
            $kb = [
                [['text'=>'✅ Approve', 'callback_data'=>'depo_approve_'.$dep_id], ['text'=>'❌ Reject', 'callback_data'=>'depo_reject_'.$dep_id]],
                [['text'=>'⚡ Acc Expired', 'callback_data'=>'depo_accexp_'.$dep_id], ['text'=>'🔄 Refresh Status', 'callback_data'=>'refresh_depo_'.$dep_id]]
            ];
            
            $tg_msg_id = send_telegram_notif($pdo, $msg, $kb, 'depo');
            if ($tg_msg_id) {
                $pdo->prepare("UPDATE deposits SET tg_msg_id = ? WHERE id = ?")->execute([$tg_msg_id, $dep_id]);
            }
        }
    }
    $dep2 = $pdo->prepare("SELECT * FROM deposits WHERE id=?"); $dep2->execute([$dep_id]); $dep = $dep2->fetch();
}

// Cancel
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'cancel_deposit') {
    if (time() - strtotime($dep['created_at']) >= 60) {
        $pdo->prepare("UPDATE deposits SET status='rejected', admin_note='Dibatalkan oleh Pengguna' WHERE id=? AND user_id=? AND status='pending'")
            ->execute([$dep_id, $user['id']]);
        redirect('/deposit');
    } else {
        $flash = 'Harap tunggu 1 menit sejak deposit dibuat sebelum membatalkan.'; $flashType = 'error';
    }
}

// Countdown: 1 jam dari created_at, tidak reset saat refresh
$created_ts       = strtotime($dep['created_at']);
$expire_secs      = max(0, 3600 - (time() - $created_ts));   // sisa waktu 1 jam
$cancel_secs_left = max(0, 60   - (time() - $created_ts));   // sisa cooldown batal

$qr_url      = !empty($qris_str)
    ? 'https://api.qrserver.com/v1/create-qr-code/?size=400x400&data=' . urlencode($qris_str)
    : '';
$qr_dl_url   = '?id=' . $dep_id . '&action=dl_qr';
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="theme-color" content="#FFE566">
<title>Bayar QRIS — Meloton</title>
<?php if ($fav_url): ?>
<link rel="icon" href="<?= htmlspecialchars($fav_url) ?>">
<link rel="apple-touch-icon" href="<?= htmlspecialchars($fav_url) ?>">
<?php endif; ?>
<link rel="stylesheet" href="/assets/css/app.css?v=<?= @filemtime($_SERVER['DOCUMENT_ROOT'].'/assets/css/app.css') ?: time() ?>">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
/* Scoped overrides */
.pay-ticket { background: #fff; border-radius: 20px; border: 2.5px solid var(--ink); box-shadow: 0 4px 0 var(--ink); overflow: hidden; margin-bottom: 16px; }
.pay-ticket__head { background: var(--brand-light); padding: 12px 16px; display: flex; align-items: center; justify-content: space-between; border-bottom: 2px solid var(--ink); }
.pay-ticket__body { padding: 24px 16px; text-align: center; }
.pay-ticket__timer { font-weight: 900; color: #dc2626; font-variant-numeric: tabular-nums; display: flex; align-items: center; gap: 6px; font-size: 14px; }
.qr-min { width: 180px; height: 180px; margin: 0 auto 16px; border: 2.5px dashed #cbd5e1; border-radius: 16px; padding: 10px; background: #fff; display: block; }
.btn-min { display: inline-flex; align-items: center; justify-content: center; gap: 6px; flex: 1; padding: 10px 8px; font-size: 12px; font-weight: 800; border: 2px solid var(--ink); border-radius: 12px; cursor: pointer; text-decoration: none; color: var(--ink); box-shadow: 0 3px 0 var(--ink); transition: transform 0.1s, box-shadow 0.1s; }
.btn-min:active { transform: translateY(3px); box-shadow: none; }
.btn-min--dark { background: var(--brand); color: #fff; border-color: #0369a1; box-shadow: 0 3px 0 #0369a1; }
.btn-min--light { background: #fff; }

.mini-steps { background: #fff; border: 2.5px solid var(--ink); border-radius: 16px; padding: 16px; margin-bottom: 16px; box-shadow: 0 4px 0 var(--ink); }
.mini-step { display: flex; align-items: flex-start; gap: 10px; margin-bottom: 10px; font-size: 12px; font-weight: 700; color: var(--ink); line-height: 1.4; }
.mini-step:last-child { margin-bottom: 0; }
.mini-step span { width: 22px; height: 22px; background: var(--yellow); border: 1.5px solid var(--ink); color: var(--ink); border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; font-size: 10px; font-weight: 900; flex-shrink: 0; margin-top: -1px; }

.text-btn { display: inline-block; background: none; border: none; font-size: 12px; font-weight: 800; color: #ef4444; cursor: pointer; padding: 8px; width: 100%; text-align: center; font-family: inherit; }
.text-btn:disabled { color: #fca5a5; cursor: not-allowed; text-decoration: none; }

/* Toast */
#toast-container { position:fixed; bottom:20px; left:50%; transform:translateX(-50%); z-index:9999; display:flex; flex-direction:column; gap:8px; pointer-events:none; width:calc(100% - 32px); max-width:380px; }
.nb-toast { display:flex; align-items:center; gap:10px; padding:12px 16px; border:2.5px solid var(--ink); border-radius:14px; box-shadow:0 5px 0 var(--ink); font-size:14px; font-weight:800; color:var(--ink); pointer-events:auto; width:100%; animation:toastIn .22s cubic-bezier(.2,.8,.4,1.2) both; background: var(--white); }
.nb-toast.out { animation:toastOut .18s ease forwards; }
.nb-toast--success { background:#d1fae5; }
.nb-toast--error   { background:#fee2e2; }
.nb-toast--warn    { background:#fff3cd; }
@keyframes toastIn  { from{opacity:0;transform:translateY(12px) scale(0.9)} to{opacity:1;transform:none scale(1)} }
@keyframes toastOut { from{opacity:1} to{opacity:0;transform:translateY(6px) scale(0.95)} }
</style>
</head>
<body>
<div id="toast-container"></div>
<div class="app-shell" style="background:var(--bg); margin:0 auto; padding-bottom:40px; min-height:100dvh;">

  <!-- Minimal Topbar -->
  <div class="topbar" style="background:var(--bg); border-bottom:none; box-shadow:none;">
    <a href="/deposit" style="color:var(--ink); text-decoration:none; font-weight:800; display:flex; align-items:center; gap:6px;">
      <i class="fas fa-chevron-left"></i> Kembali
    </a>
    <div style="color:var(--ink); font-weight:900; font-size:16px;">Deposit QRIS</div>
    <div style="width: 24px;"></div> <!-- spacer -->
  </div>

  <div style="padding:0 16px; display:flex; flex-direction:column;">
    <?php if ($flash): ?>
    <div class="alert alert--<?= $flashType==='error'?'error':'success' ?>" style="box-shadow:var(--shadow-sm);"><i class="fas fa-<?= $flashType==='error'?'exclamation-circle':'check-circle' ?>"></i> <?= htmlspecialchars($flash) ?></div>
    <?php endif; ?>

    <?php if ($dep['status'] === 'confirmed'): ?>
    <div class="pay-ticket" style="text-align:center; padding:40px 16px;">
      <div style="font-size:64px; margin-bottom:12px;">🎉</div>
      <div style="font-size:22px; font-weight:900; color:var(--ink); margin-bottom:8px;">Pembayaran Sukses</div>
      <div style="font-size:13px; color:var(--text-muted); font-weight:600; margin-bottom:32px;">Saldo belimu sudah otomatis ditambahkan.</div>
      <a href="/home" class="btn btn--primary btn--full"><i class="fas fa-home"></i> Ke Beranda</a>
    </div>

    <?php elseif ($dep['proof_image']): ?>
    <div class="pay-ticket" style="text-align:center; padding:40px 16px;">
      <div style="font-size:64px; margin-bottom:12px;">⏳</div>
      <div style="font-size:20px; font-weight:900; color:var(--ink); margin-bottom:8px;">Bukti Diterima</div>
      <div style="font-size:13px; color:var(--text-muted); font-weight:600; margin-bottom:32px;">Tim kami sedang mengecek pembayaranmu (1–15 menit).</div>
      <a href="/history" class="btn btn--primary btn--full"><i class="fas fa-history"></i> Lihat Riwayat</a>
    </div>

    <?php else: ?>

    <!-- Compact Ticket -->
    <div class="pay-ticket">
      <div class="pay-ticket__head" id="exp-strip">
        <span style="font-size:12px; font-weight:800; color:var(--ink);">#<?= $dep_id ?></span>
        <div class="pay-ticket__timer">
          <i class="fas fa-stopwatch"></i> <span id="exp-timer">--:--</span>
        </div>
      </div>
      <div class="pay-ticket__body">
        <?php if ($qr_url): ?>
        <img id="qr-img" src="<?= htmlspecialchars($qr_url) ?>" alt="QRIS" class="qr-min">
        <div style="font-size:11px; font-weight:700; color:var(--text-muted); text-transform:uppercase; letter-spacing:1px; margin-bottom:4px;">Total Bayar</div>
        <div style="font-size:32px; font-weight:900; color:var(--ink); letter-spacing:-1px; line-height:1; margin-bottom:20px;"><?= format_rp($amount) ?></div>
        
        <div style="display:flex; gap:12px;">
          <a href="<?= htmlspecialchars($qr_dl_url) ?>" class="btn-min btn-min--dark"><i class="fas fa-download"></i> Unduh</a>
          <a href="<?= htmlspecialchars($qr_url) ?>" target="_blank" class="btn-min btn-min--light"><i class="fas fa-external-link-alt"></i> Buka QR</a>
        </div>
        <?php else: ?>
        <div class="alert alert--warn" style="margin:0;"><i class="fas fa-exclamation-triangle"></i> QRIS belum dikonfigurasi. Hubungi admin.</div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Micro Notice -->
    <div style="text-align:center; margin-bottom:16px;">
      <a href="javascript:void(0)" onclick="alert('Silakan chat Admin di pojok kanan bawah jika saldo e-wallet Anda tidak bisa disesuaikan nominal uniknya.')" style="font-size:11px; font-weight:800; color:#b45309; text-decoration:none; background:#fef3c7; padding:6px 12px; border-radius:20px; border:1.5px solid #fde68a; display:inline-flex; align-items:center; gap:6px;">
        <i class="fas fa-info-circle"></i> Keberatan nominal unik? Hubungi Admin.
      </a>
    </div>

    <!-- Compact Steps -->
    <div class="mini-steps">
      <div style="font-size:13px; font-weight:900; color:var(--ink); margin-bottom:12px;">📋 Cara Bayar Praktis</div>
      <div class="mini-step"><span>1</span> Buka aplikasi E-Wallet (OVO, Dana, dll) atau m-Banking.</div>
      <div class="mini-step"><span>2</span> Scan QR di atas. Nominal akan terisi otomatis, mohon jangan diubah.</div>
      <div class="mini-step"><span>3</span> <?= $confirm_mode === 'manual' ? 'Selesaikan pembayaran, lalu upload bukti di bawah.' : 'Selesaikan pembayaran, saldo masuk seketika.' ?></div>
    </div>

    <!-- Upload Proof (Compact) -->
    <?php 
    $pending_secs = time() - $created_ts;
    $show_upload = ($confirm_mode !== 'auto' || $pending_secs >= 300);
    ?>
    <div id="upload-proof-card" style="display: <?= $show_upload ? 'block' : 'none' ?>; margin-bottom:16px;">
      <form method="POST" enctype="multipart/form-data" style="background:#fff; border:2.5px solid var(--ink); border-radius:16px; padding:16px; box-shadow:0 4px 0 var(--ink);">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="upload_proof">
        <div style="font-size:13px; font-weight:900; margin-bottom:10px;"><i class="fas fa-camera"></i> Upload Struk Pembayaran</div>
        <input type="file" name="proof" accept="image/*" required style="width:100%; font-size:12px; margin-bottom:12px; background:#f8fafc; border:1px solid #cbd5e1; padding:8px; border-radius:8px;">
        <button type="submit" class="btn btn--primary btn--full" style="padding:10px; font-size:13px; border-radius:10px;"><i class="fas fa-paper-plane"></i> Kirim Bukti</button>
      </form>
    </div>

    <!-- Actions Stack -->
    <div style="display:flex; flex-direction:column; gap:8px;">
      <button id="btn-check-status" onclick="manualCheckStatus()" class="btn btn--primary btn--full" style="padding:14px; font-size:14px; border-radius:16px; background:var(--white); color:var(--ink); border:2.5px solid var(--ink); box-shadow:0 4px 0 var(--ink); text-shadow:none;">
        <i class="fas fa-sync-alt"></i> Cek Status Pembayaran
      </button>
      <form method="POST" style="margin:0; margin-top:8px;">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="cancel_deposit">
        <button id="btn-cancel-dep" type="submit" class="text-btn">Batalkan Deposit</button>
      </form>
    </div>

    <script>
    const DEP_ID      = <?= $dep_id ?>;
    const CSRF_TOK    = '<?= csrf_token() ?>';
    const EXPIRE_SECS = <?= $expire_secs ?>;
    let isChecking    = false;

    function toast(msg, type = 'success', duration = 3200) {
      const icons = { success:'<i class="fas fa-check-circle" style="color:#10b981"></i>', error:'<i class="fas fa-times-circle" style="color:#ef4444"></i>', warn:'<i class="fas fa-exclamation-triangle" style="color:#f59e0b"></i>' };
      const c  = document.getElementById('toast-container');
      const el = document.createElement('div');
      el.className = 'nb-toast nb-toast--' + type;
      el.innerHTML = '<span class="nb-toast__icon">' + icons[type] + '</span><span class="nb-toast__msg">' + msg + '</span>';
      c.appendChild(el);
      const dismiss = () => { el.classList.add('out'); setTimeout(() => el.remove(), 200); };
      el.addEventListener('click', dismiss);
      setTimeout(dismiss, duration);
    }

    let expSecs = EXPIRE_SECS;
    const timerEl = document.getElementById('exp-timer');
    const stripEl = document.getElementById('exp-strip');

    function updateExpTimer() {
      if (expSecs <= 0) {
        if (timerEl) { timerEl.textContent = '00:00'; timerEl.style.color = '#ef4444'; }
        if (stripEl) { stripEl.style.background = '#fef2f2'; }
        return;
      }
      const m = Math.floor(expSecs / 60), s = expSecs % 60;
      if (timerEl) timerEl.textContent = String(m).padStart(2,'0') + ':' + String(s).padStart(2,'0');
    }
    updateExpTimer();
    const expTimer = setInterval(() => {
      expSecs--;
      updateExpTimer();
      
      const elapsed = 3600 - expSecs;
      if (elapsed >= 300) {
        const upCard = document.getElementById('upload-proof-card');
        if (upCard && upCard.style.display === 'none') {
            upCard.style.display = 'block';
            upCard.style.animation = 'toastIn 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275) forwards';
        }
      }

      if (expSecs <= 0) clearInterval(expTimer);
    }, 1000);

    const pollStatus = () => {
      if (isChecking) return;
      fetch('?id=' + DEP_ID, {
        method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:'_csrf=' + CSRF_TOK + '&action=check_status'
      }).then(r=>r.json()).then(d=>{ if(d.confirmed) confirmAndRedirect(); }).catch(()=>{});
    };
    const pollTimer = setInterval(pollStatus, 5000);

    function confirmAndRedirect() {
      clearInterval(pollTimer); clearInterval(expTimer);
      if (timerEl) { timerEl.textContent = 'Sukses'; timerEl.style.color = '#10b981'; }
      if (stripEl) stripEl.style.background = '#d1fae5';
      setTimeout(()=>location.href='/history?tab=deposit', 1500);
    }

    const manualCheckStatus = () => {
      if (isChecking) return;
      isChecking = true;
      const btn  = document.getElementById('btn-check-status');
      const orig = btn.innerHTML;
      btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Mengecek...';
      fetch('?id=' + DEP_ID, {
        method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:'_csrf=' + CSRF_TOK + '&action=check_status'
      }).then(r=>r.json()).then(d=>{
        isChecking=false; btn.disabled=false; btn.innerHTML=orig;
        if (d.confirmed) { confirmAndRedirect(); toast('Pembayaran Sukses 🎉','success'); }
        else             { toast('Pembayaran belum diterima','error'); }
      }).catch(()=>{
        isChecking=false; btn.disabled=false; btn.innerHTML=orig;
        toast('Gagal menghubungi server','warn');
      });
    };

    let cancelSecs = <?= $cancel_secs_left ?>;
    const cancelBtn = document.getElementById('btn-cancel-dep');
    if (cancelBtn && cancelSecs > 0) {
      cancelBtn.disabled=true;
      cancelBtn.textContent='Tunggu '+cancelSecs+'s untuk membatalkan';
      const ci = setInterval(()=>{
        cancelSecs--;
        cancelBtn.textContent = cancelSecs>0 ? 'Tunggu '+cancelSecs+'s untuk membatalkan' : 'Batalkan Deposit';
        if(cancelSecs<=0){ clearInterval(ci); cancelBtn.disabled=false; cancelBtn.style.textDecoration='underline'; }
      },1000);
    }
    </script>

    <?php endif; ?>
  </div>
</div>
</body>
</html>
