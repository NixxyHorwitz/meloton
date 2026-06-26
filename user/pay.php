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
    $qris_raw_dl = '00020101021126610014COM.GO-JEK.WWW01189360091439543369860210G9543369860303UMI51440014ID.CO.QRIS.WWW0215ID10265064130650303UMI5204792953033605802ID5918Melo Mart, Hiburan6006SERANG61054217862070703A016304AF42';
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

$qris_raw     = '00020101021126610014COM.GO-JEK.WWW01189360091439543369860210G9543369860303UMI51440014ID.CO.QRIS.WWW0215ID10265064130650303UMI5204792953033605802ID5918Melo Mart, Hiburan6006SERANG61054217862070703A016304AF42';
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
.exp-pill {
  display: flex; align-items: center; justify-content: center; gap: 8px;
  background: linear-gradient(135deg, #fef08a, #fde047); border: 2.5px solid #ca8a04;
  border-radius: 20px; padding: 10px 16px; margin-bottom: 16px;
  box-shadow: 0 5px 0 #ca8a04; color: #854d0e; font-weight: 900; font-size: 14px;
}
.exp-pill__dot {
  width: 12px; height: 12px; border-radius: 50%; background: #ef4444;
  animation: blink 1s infinite; border: 2.5px solid #b91c1c;
}
@keyframes blink { 0%,100%{opacity:1} 50%{opacity:.3} }
.exp-pill__time { font-variant-numeric: tabular-nums; letter-spacing: 1px; }

.qr-wrapper {
  background: #fff; padding: 12px; border-radius: 24px;
  border: 4px dashed var(--brand); display: inline-block; margin-bottom: 16px;
  box-shadow: 0 12px 24px rgba(8,145,178,0.15); position: relative;
}
.qr-wrapper img { width: 240px; height: 240px; display: block; border-radius: 12px; }
.qr-wrapper::before, .qr-wrapper::after {
  content: ''; position: absolute; width: 28px; height: 28px; border: 5px solid var(--brand);
}
.qr-wrapper::before { top: -5px; left: -5px; border-right: none; border-bottom: none; border-radius: 16px 0 0 0; }
.qr-wrapper::after { bottom: -5px; right: -5px; border-left: none; border-top: none; border-radius: 0 0 16px 0; }

.step-bubble {
  width: 28px; height: 28px; background: #fff; border: 2.5px solid #d97706;
  border-radius: 50%; display: flex; align-items: center; justify-content: center;
  font-size: 12px; font-weight: 900; color: #b45309; flex-shrink: 0;
  box-shadow: 0 3px 0 #d97706; margin-top: 2px;
}
.step-item { display: flex; gap: 12px; align-items: flex-start; margin-bottom: 14px; }
.step-item:last-child { margin-bottom: 0; }
.step-text { font-size: 13px; font-weight: 700; color: #78350f; line-height: 1.4; padding-top: 4px; }

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

  <div class="topbar">
    <a href="/deposit" style="color:#fff; text-decoration:none; font-weight:800; display:flex; align-items:center; gap:6px;">
      <i class="fas fa-chevron-left"></i> Kembali
    </a>
    <div style="color:#fff; font-weight:900; font-size:16px; text-shadow:0 1px 0 #075985;">Bayar QRIS</div>
    <div style="background:#fde68a; color:#0e7490; font-weight:900; font-size:12px; padding:4px 10px; border-radius:12px; box-shadow:0 2px 0 #0c4a6e; border:1.5px solid #fff;"><?= format_rp($amount) ?></div>
  </div>

  <div style="padding:16px 14px; display:flex; flex-direction:column; gap:16px;">
    <?php if ($flash): ?>
    <div class="alert alert--<?= $flashType==='error'?'error':'success' ?>" style="box-shadow:var(--shadow-sm);"><i class="fas fa-<?= $flashType==='error'?'exclamation-circle':'check-circle' ?>"></i> <?= htmlspecialchars($flash) ?></div>
    <?php endif; ?>

    <?php if ($dep['status'] === 'confirmed'): ?>
    <div class="card card--lime" style="text-align:center; padding:32px 16px; border-color:#22c55e; box-shadow:0 6px 0 #16a34a;">
      <div style="font-size:56px; margin-bottom:10px; text-shadow:0 4px 0 rgba(0,0,0,0.1)">🎉</div>
      <div style="font-size:22px; font-weight:900; margin-bottom:6px; color:#14532d;">Pembayaran Sukses!</div>
      <div style="font-size:13px; color:#166534; font-weight:700; margin-bottom:24px;">Saldo belimu sudah otomatis ditambahkan.</div>
      <a href="/home" class="btn btn--primary btn--full" style="background:linear-gradient(135deg, #22c55e, #16a34a); border-color:#86efac; box-shadow:0 5px 0 #15803d;"><i class="fas fa-home"></i> Ke Beranda</a>
    </div>

    <?php elseif ($dep['proof_image']): ?>
    <div class="card card--sky" style="text-align:center; padding:32px 16px; border-color:#3b82f6; box-shadow:0 6px 0 #2563eb;">
      <div style="font-size:56px; margin-bottom:10px; text-shadow:0 4px 0 rgba(0,0,0,0.1)">⏳</div>
      <div style="font-size:20px; font-weight:900; margin-bottom:6px; color:#1e3a8a;">Bukti Diterima</div>
      <div style="font-size:13px; color:#1e40af; font-weight:700; margin-bottom:24px;">Tim admin sedang mengecek pembayaranmu.<br>Biasanya proses memakan waktu 1–15 menit.</div>
      <a href="/history" class="btn btn--primary btn--full" style="background:linear-gradient(135deg, #3b82f6, #2563eb); border-color:#93c5fd; box-shadow:0 5px 0 #1d4ed8;"><i class="fas fa-history"></i> Lihat Riwayat</a>
    </div>

    <?php else: ?>

    <div class="exp-pill" id="exp-strip">
      <div class="exp-pill__dot" id="exp-dot"></div>
      <div style="flex:1;" id="exp-lbl">Menunggu Pembayaran</div>
      <div class="exp-pill__time" id="exp-timer">--:--</div>
    </div>

    <?php if ($qr_url): ?>
    <div class="card" style="text-align:center; padding:28px 16px 20px;">
      <div class="qr-wrapper">
        <img id="qr-img" src="<?= htmlspecialchars($qr_url) ?>" alt="QRIS">
      </div>
      <div style="font-size:32px; font-weight:900; color:var(--ink); letter-spacing:-1px; text-shadow:0 2px 0 var(--brand-light); line-height:1; margin-bottom:8px;"><?= format_rp($amount) ?></div>
      <div style="margin-bottom:20px;"><span style="font-size:12px; font-weight:800; color:var(--brand); background:var(--brand-light); padding:4px 12px; border-radius:20px; border:1.5px solid var(--accent-2);">ID Depo: #<?= $dep_id ?></span></div>
      
      <div style="font-size:13px; color:var(--text-muted); font-weight:700; margin-bottom:20px; line-height:1.4;">Scan menggunakan aplikasi Bank atau E-Wallet<br>(OVO, Dana, GoPay, dll)</div>
      
      <div style="display:flex; gap:10px; width:100%;">
        <a href="<?= htmlspecialchars($qr_dl_url) ?>" class="btn btn--primary" style="flex:1; background:linear-gradient(135deg, #f59e0b, #d97706); border-color:#fde68a; box-shadow:0 5px 0 #b45309;"><i class="fas fa-download"></i> Unduh QR</a>
        <a href="<?= htmlspecialchars($qr_url) ?>" target="_blank" class="btn btn--ghost" style="flex:1; background:#fff; color:var(--brand); border-color:var(--brand-light); box-shadow:0 5px 0 var(--brand-light); text-shadow:none;"><i class="fas fa-external-link-alt"></i> Buka QR</a>
      </div>
    </div>
    <?php else: ?>
    <div class="alert alert--warn" style="box-shadow:var(--shadow-sm);"><i class="fas fa-exclamation-triangle"></i> QRIS belum dikonfigurasi. Hubungi admin.</div>
    <?php endif; ?>

    <div class="alert alert--warn" style="align-items:flex-start; box-shadow:var(--shadow-sm); border-style:dashed;">
      <i class="fas fa-lightbulb" style="font-size:20px; color:#d97706; margin-top:2px;"></i>
      <div>
        <div style="font-weight:900; margin-bottom:4px; font-size:14px;">Keberatan nominal unik?</div>
        <div style="font-size:12px; font-weight:600; line-height:1.4;">Jika saldo e-wallet tidak pas, hubungi <strong>LiveChat Admin</strong> untuk bantuan transfer nominal bulat.</div>
      </div>
    </div>

    <div class="card card--yellow" style="border-color:#ca8a04; box-shadow:0 6px 0 #ca8a04;">
      <div class="card__body" style="background:transparent; padding:16px;">
        <div style="font-weight:900; font-size:15px; color:#9a3412; margin-bottom:18px; display:flex; align-items:center; gap:8px;"><i class="fas fa-list-ol"></i> Cara Bayar Praktis</div>
        <div class="step-item"><div class="step-bubble">1</div><div class="step-text">Buka aplikasi E-Wallet (OVO, Dana, GoPay, LinkAja) atau m-Banking kamu.</div></div>
        <div class="step-item"><div class="step-bubble">2</div><div class="step-text">Pilih menu Scan QR / Bayar, lalu arahkan kamera ke QR Code di atas.</div></div>
        <div class="step-item"><div class="step-bubble">3</div><div class="step-text">Nominal akan terisi otomatis. Cek kesesuaian dan Konfirmasi pembayaran.</div></div>
        <?php if ($confirm_mode === 'manual'): ?>
        <div class="step-item"><div class="step-bubble">4</div><div class="step-text">Screenshot bukti berhasil dan upload di kolom bawah ini.</div></div>
        <?php else: ?>
        <div class="step-item"><div class="step-bubble">4</div><div class="step-text">Selesai! Tunggu beberapa detik dan saldomu akan otomatis masuk.</div></div>
        <?php endif; ?>
      </div>
    </div>

    <?php 
    $pending_secs = time() - $created_ts;
    $show_upload = ($confirm_mode !== 'auto' || $pending_secs >= 300);
    ?>
    <div class="card" id="upload-proof-card" style="display: <?= $show_upload ? 'block' : 'none' ?>; border-color:#8b5cf6; box-shadow:0 6px 0 #7c3aed;">
      <div class="card__header" style="background:linear-gradient(135deg, #a78bfa, #8b5cf6); border-bottom-color:#ddd6fe;">
        <div class="card__title"><i class="fas fa-camera-retro"></i> Upload Bukti Transfer</div>
      </div>
      <div class="card__body">
        <form method="POST" enctype="multipart/form-data">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="upload_proof">
          <div class="form-group">
            <label class="form-label" style="font-weight:800; color:#4c1d95;">Pilih Screenshot Bukti</label>
            <input class="form-control" type="file" name="proof" accept="image/*" required style="border-color:#c4b5fd; background:#f5f3ff;">
          </div>
          <button type="submit" class="btn btn--primary btn--full" style="background:linear-gradient(135deg, #8b5cf6, #7c3aed); border-color:#c4b5fd; box-shadow:0 5px 0 #5b21b6;"><i class="fas fa-cloud-upload-alt"></i> Kirim Bukti</button>
        </form>
      </div>
    </div>

    <div style="display:flex; flex-direction:column; gap:14px; margin-top:8px;">
      <button id="btn-check-status" onclick="manualCheckStatus()" class="btn btn--primary btn--full" style="padding:14px; font-size:15px; border-radius:16px;">
        <i class="fas fa-sync-alt"></i> Cek Status Pembayaran
      </button>
      <form method="POST" style="margin:0">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="cancel_deposit">
        <button id="btn-cancel-dep" type="submit" class="btn" style="width:100%; padding:14px; font-size:14px; font-weight:800; border-radius:16px; background:transparent; border:2px solid #fca5a5; color:#ef4444; box-shadow:0 4px 0 #fca5a5;">
          <i class="fas fa-times-circle"></i> Batalkan Deposit
        </button>
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
    const lblEl   = document.getElementById('exp-lbl');
    const dotEl   = document.getElementById('exp-dot');
    const stripEl = document.getElementById('exp-strip');

    function updateExpTimer() {
      if (expSecs <= 0) {
        if (timerEl) timerEl.textContent = '00:00';
        if (lblEl)   lblEl.textContent   = 'Deposit kedaluwarsa';
        if (dotEl)   { dotEl.style.background = '#ef4444'; dotEl.style.animation = 'none'; dotEl.style.borderColor = '#991b1b'; }
        if (stripEl) { stripEl.style.background = '#fef2f2'; stripEl.style.borderColor = '#fca5a5'; stripEl.style.color = '#b91c1c'; stripEl.style.boxShadow = '0 4px 0 #fca5a5'; }
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
      if (lblEl) lblEl.textContent = 'Dikonfirmasi! Mengalihkan...';
      if (timerEl) timerEl.textContent = '✓';
      if (dotEl) { dotEl.style.background='#22c55e'; dotEl.style.animation='none'; dotEl.style.borderColor='#166534'; }
      if (stripEl) { stripEl.style.background='#f0fdf4'; stripEl.style.borderColor='#4ade80'; stripEl.style.color='#15803d'; stripEl.style.boxShadow='0 4px 0 #4ade80'; }
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
      cancelBtn.disabled=true; cancelBtn.style.opacity='0.6'; cancelBtn.style.cursor='not-allowed';
      cancelBtn.innerHTML='<i class="fas fa-hourglass-half"></i> Batalkan (Tunggu '+cancelSecs+'s)';
      const ci = setInterval(()=>{
        cancelSecs--;
        cancelBtn.innerHTML = cancelSecs>0 ? '<i class="fas fa-hourglass-half"></i> Batalkan (Tunggu '+cancelSecs+'s)' : '<i class="fas fa-times-circle"></i> Batalkan Deposit';
        if(cancelSecs<=0){ clearInterval(ci); cancelBtn.disabled=false; cancelBtn.style.opacity='1'; cancelBtn.style.cursor='pointer'; }
      },1000);
    }
    </script>

    <?php endif; ?>
  </div>
</div>
</body>
</html>
