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
    $qris_raw_dl = '00020101021126610014COM.GO-JEK.WWW01189360091439543369860210G9543369860303UMI51440014ID.CO.QRIS.WWW0215ID10265064130650303UMI5204581653033605802ID5913TOKUY DIGITAL6013JAKARTA TIMUR61051341062070703A016304AC50';
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

$qris_raw     = '00020101021126610014COM.GO-JEK.WWW01189360091439543369860210G9543369860303UMI51440014ID.CO.QRIS.WWW0215ID10265064130650303UMI5204581653033605802ID5913TOKUY DIGITAL6013JAKARTA TIMUR61051341062070703A016304AC50';
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
<style>
* { box-sizing: border-box; }
.pay-wrap { min-height:100dvh; display:flex; flex-direction:column; background:var(--bg); }

/* Topbar */
.pay-topbar {
  background:var(--white); border-bottom:2.5px solid var(--ink);
  padding:0 16px; height:52px;
  display:flex; align-items:center; justify-content:space-between;
  position:sticky; top:0; z-index:100;
}
.pay-topbar__back {
  display:flex; align-items:center; gap:6px;
  color:var(--ink); text-decoration:none; font-weight:800; font-size:14px;
}
.pay-topbar__title { font-size:14px; font-weight:900; }
.pay-topbar__amt {
  font-size:13px; font-weight:900;
  background:var(--yellow); border:2px solid var(--ink);
  border-radius:8px; padding:4px 10px;
}

/* Body */
.pay-body { padding:16px; flex:1; display:flex; flex-direction:column; gap:14px; }

/* Expiry strip */
.exp-strip {
  display:flex; align-items:center; gap:10px;
  background:#fff7ed; border:2px solid #fb923c;
  border-radius:10px; padding:10px 14px;
}
.exp-strip__dot {
  width:9px; height:9px; border-radius:50%;
  background:#f97316; flex-shrink:0;
  animation:blink .9s ease-in-out infinite;
}
@keyframes blink { 0%,100%{opacity:1} 50%{opacity:.15} }
.exp-strip__lbl  { font-size:13px; font-weight:700; flex:1; color:#9a3412; }
.exp-strip__time { font-size:15px; font-weight:900; color:#9a3412; font-variant-numeric:tabular-nums; }

/* QR Block */
.qr-block {
  background:#fff; border:2.5px solid var(--ink);
  border-radius:16px; box-shadow:4px 4px 0 var(--ink);
  padding:20px 16px 16px; text-align:center;
}
.qr-block img {
  width:240px; height:240px;
  border:2.5px solid var(--ink); border-radius:12px;
  display:block; margin:0 auto 14px;
  padding:10px; background:#fff;
}
.qr-block__amt  { font-size:24px; font-weight:900; letter-spacing:-1px; margin-bottom:2px; }
.qr-block__id   { font-size:12px; font-weight:700; color:#aaa; margin-bottom:6px; }
.qr-block__note { font-size:12px; color:#888; font-weight:600; margin-bottom:14px; }
.qr-block__row  { display:flex; gap:10px; }
.qr-btn {
  flex:1; display:inline-flex; align-items:center; justify-content:center; gap:5px;
  padding:10px 8px; font-size:13px; font-weight:800;
  border:2px solid var(--ink); border-radius:9px; cursor:pointer;
  text-decoration:none; color:var(--ink); transition:opacity .15s;
}
.qr-btn:active { opacity:.65; }
.qr-btn--dl   { background:var(--yellow); box-shadow:3px 3px 0 var(--ink); }
.qr-btn--open { background:var(--white); }

/* Steps */
.steps-card {
  background:var(--yellow); border:2px solid var(--ink);
  border-radius:12px; padding:14px 16px;
  display:flex; flex-direction:column; gap:10px;
}
.steps-card__title { font-size:13px; font-weight:900; }
.step-row  { display:flex; align-items:flex-start; gap:10px; }
.step-num  {
  width:24px; height:24px; flex-shrink:0;
  background:#fff; border:2px solid var(--ink); border-radius:50%;
  font-size:11px; font-weight:900;
  display:flex; align-items:center; justify-content:center; margin-top:1px;
}
.step-txt  { font-size:13px; font-weight:700; line-height:1.4; }

/* Upload */
.upload-card {
  background:#fff; border:2px solid var(--ink);
  border-radius:12px; padding:16px;
}
.upload-card__title { font-size:14px; font-weight:900; margin-bottom:12px; }

/* Actions */
.pay-actions { display:flex; flex-direction:column; gap:10px; }

/* Confirmed */
.pay-confirmed {
  text-align:center; padding:32px 16px;
  background:var(--lime); border:2.5px solid var(--ink);
  border-radius:16px; box-shadow:4px 4px 0 var(--ink);
}

/* Toast */
#toast-container {
  position:fixed; bottom:20px; left:50%; transform:translateX(-50%);
  z-index:9999; display:flex; flex-direction:column; gap:8px;
  pointer-events:none; width:calc(100% - 32px); max-width:380px;
}
.nb-toast {
  display:flex; align-items:center; gap:10px;
  padding:12px 16px; border:2.5px solid var(--ink); border-radius:11px;
  box-shadow:3px 3px 0 var(--ink); font-size:14px; font-weight:800; color:var(--ink);
  pointer-events:auto; width:100%;
  animation:toastIn .22s cubic-bezier(.2,.8,.4,1.2) both;
}
.nb-toast.out { animation:toastOut .18s ease forwards; }
.nb-toast--success { background:#d1fae5; }
.nb-toast--error   { background:#fee2e2; }
.nb-toast--warn    { background:#fff3cd; }
.nb-toast__icon { font-size:18px; flex-shrink:0; }
.nb-toast__msg  { flex:1; line-height:1.3; }
@keyframes toastIn  { from{opacity:0;transform:translateY(12px)} to{opacity:1;transform:none} }
@keyframes toastOut { from{opacity:1} to{opacity:0;transform:translateY(6px)} }
</style>
</head>
<body>
<div id="toast-container"></div>
<div class="pay-wrap">

  <div class="pay-topbar">
    <a href="/deposit" class="pay-topbar__back">
      <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><polyline points="15 18 9 12 15 6"/></svg>
      Kembali
    </a>
    <div class="pay-topbar__title">📱 Bayar QRIS</div>
    <div class="pay-topbar__amt"><?= format_rp($amount) ?></div>
  </div>

  <div class="pay-body">
    <?php if ($flash): ?>
    <div class="alert alert--<?= $flashType==='error'?'error':'success' ?>"><?= htmlspecialchars($flash) ?></div>
    <?php endif; ?>

    <?php if ($dep['status'] === 'confirmed'): ?>
    <div class="pay-confirmed">
      <div style="font-size:48px;margin-bottom:10px">✅</div>
      <div style="font-size:20px;font-weight:900;margin-bottom:6px">Pembayaran Dikonfirmasi!</div>
      <div style="font-size:13px;color:#555;margin-bottom:18px">Saldo beli kamu sudah ditambahkan.</div>
      <a href="/home" class="btn btn--primary btn--full">🏠 Ke Beranda</a>
    </div>

    <?php elseif ($dep['proof_image']): ?>
    <div style="text-align:center;padding:32px 0">
      <div style="font-size:48px;margin-bottom:10px">⏳</div>
      <div style="font-size:16px;font-weight:900;margin-bottom:6px">Bukti Sudah Diupload</div>
      <div style="font-size:13px;color:#777;margin-bottom:20px">Menunggu konfirmasi admin. Biasanya 1–24 jam.</div>
      <a href="/history" class="btn btn--ghost btn--full">📜 Lihat Riwayat</a>
    </div>

    <?php else: ?>

    <!-- Expiry strip — 1 jam dari created_at, persistent across refresh -->
    <div class="exp-strip" id="exp-strip">
      <div class="exp-strip__dot" id="exp-dot"></div>
      <div class="exp-strip__lbl" id="exp-lbl">⏳ Menunggu pembayaran</div>
      <div class="exp-strip__time" id="exp-timer">--:--</div>
    </div>

    <!-- QR Block -->
    <?php if ($qr_url): ?>
    <div class="qr-block">
      <img id="qr-img" src="<?= htmlspecialchars($qr_url) ?>" alt="QRIS">
      <div class="qr-block__amt"><?= format_rp($amount) ?></div>
      <div class="qr-block__id">Deposit #<?= $dep_id ?></div>
      <div class="qr-block__note">Scan dengan app bank / dompet digital manapun</div>
      <div class="qr-block__row">
        <a href="<?= htmlspecialchars($qr_dl_url) ?>" class="qr-btn qr-btn--dl">⬇ Unduh QR</a>
        <a href="<?= htmlspecialchars($qr_url) ?>" target="_blank" class="qr-btn qr-btn--open">↗ Buka</a>
      </div>
    </div>
    <?php else: ?>
    <div class="alert alert--warn">QRIS belum dikonfigurasi. Hubungi admin.</div>
    <?php endif; ?>

    <!-- Info Unique Nominal -->
    <div style="font-size:12px;margin-bottom:14px;background:#fff3cd;border:2px dashed #f59e0b;color:#b45309;border-radius:12px;padding:12px;display:flex;gap:8px;align-items:flex-start">
      <div style="font-size:20px;line-height:1">💡</div>
      <div>
        <div style="font-weight:900;margin-bottom:3px">Keberatan dengan nominal unik?</div>
        Jika saldo Anda pas-pasan dan tidak bisa mentransfer nominal persis di atas, silakan hubungi <strong>Admin via LiveChat</strong> (ikon di pojok kanan bawah) untuk bantuan.
      </div>
    </div>

    <!-- Steps -->
    <div class="steps-card">
      <div class="steps-card__title">📋 Cara Bayar</div>
      <div class="step-row"><div class="step-num">1</div><div class="step-txt">Buka app bank atau e-wallet (GoPay, OVO, DANA, dll)</div></div>
      <div class="step-row"><div class="step-num">2</div><div class="step-txt">Scan QR di atas — nominal sudah otomatis terisi</div></div>
      <div class="step-row"><div class="step-num">3</div><div class="step-txt">Konfirmasi pembayaran di aplikasi kamu</div></div>
      <?php if ($confirm_mode === 'manual'): ?>
      <div class="step-row"><div class="step-num">4</div><div class="step-txt">Screenshot bukti &amp; upload di bawah ini</div></div>
      <?php else: ?>
      <div class="step-row"><div class="step-num">4</div><div class="step-txt">Tunggu — saldo otomatis masuk dalam hitungan detik ⚡</div></div>
      <?php endif; ?>
    </div>

    <!-- Upload bukti -->
    <?php 
    $pending_secs = time() - $created_ts;
    // Tampilkan jika manual, ATAU jika sudah lewat 5 menit (300 detik)
    $show_upload = ($confirm_mode !== 'auto' || $pending_secs >= 300);
    ?>
    <div class="upload-card" id="upload-proof-card" style="display: <?= $show_upload ? 'block' : 'none' ?>;">
      <div class="upload-card__title">📸 Upload Bukti Pembayaran</div>
      <form method="POST" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="upload_proof">
        <div class="form-group">
          <input class="form-control" type="file" name="proof" accept="image/*" required>
          <div class="form-hint">Screenshot notifikasi / struk pembayaran QRIS</div>
        </div>
        <button type="submit" class="btn btn--primary btn--full">📤 Upload &amp; Konfirmasi</button>
      </form>
      <div style="text-align:center;margin-top:12px">
        <a href="/history" style="font-size:13px;color:#aaa;font-weight:700">Nanti saja → Lihat Riwayat</a>
      </div>
    </div>

    <!-- Actions -->
    <div class="pay-actions">
      <button id="btn-check-status" onclick="manualCheckStatus()" class="btn btn--primary btn--full">
        🔄 Cek Status Pembayaran
      </button>
      <form method="POST" style="margin:0">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="cancel_deposit">
        <button id="btn-cancel-dep" type="submit" class="btn btn--ghost btn--full"
                style="border-color:#ef4444;color:#ef4444;width:100%;font-size:13px">
          ❌ Batalkan Deposit
        </button>
      </form>
    </div>

    <script>
    const DEP_ID      = <?= $dep_id ?>;
    const CSRF_TOK    = '<?= csrf_token() ?>';
    const EXPIRE_SECS = <?= $expire_secs ?>; // sisa detik dari PHP (tidak reset saat refresh)
    let isChecking    = false;

    // ── Toast ──
    function toast(msg, type = 'success', duration = 3200) {
      const icons = { success:'✅', error:'❌', warn:'⚠️' };
      const c  = document.getElementById('toast-container');
      const el = document.createElement('div');
      el.className = 'nb-toast nb-toast--' + type;
      el.innerHTML = '<span class="nb-toast__icon">' + icons[type] + '</span><span class="nb-toast__msg">' + msg + '</span>';
      c.appendChild(el);
      const dismiss = () => { el.classList.add('out'); setTimeout(() => el.remove(), 200); };
      el.addEventListener('click', dismiss);
      setTimeout(dismiss, duration);
    }

    // ── Expiry countdown (dari PHP, persistent) ──
    let expSecs = EXPIRE_SECS;
    const timerEl = document.getElementById('exp-timer');
    const lblEl   = document.getElementById('exp-lbl');
    const dotEl   = document.getElementById('exp-dot');

    function updateExpTimer() {
      if (expSecs <= 0) {
        if (timerEl) timerEl.textContent = '00:00';
        if (lblEl)   lblEl.textContent   = '⚠️ Deposit kedaluwarsa — hubungi admin';
        if (dotEl)   { dotEl.style.background = '#ef4444'; dotEl.style.animation = 'none'; }
        return;
      }
      const m = Math.floor(expSecs / 60), s = expSecs % 60;
      if (timerEl) timerEl.textContent = String(m).padStart(2,'0') + ':' + String(s).padStart(2,'0');
    }
    updateExpTimer();
    const expTimer = setInterval(() => {
      expSecs--;
      updateExpTimer();
      
      // Munculkan form upload otomatis setelah 5 menit (300 detik) berjalan
      const elapsed = 3600 - expSecs;
      if (elapsed >= 300) {
        const upCard = document.getElementById('upload-proof-card');
        if (upCard && upCard.style.display === 'none') {
            upCard.style.display = 'block';
            upCard.style.animation = 'toastIn 0.3s ease forwards';
        }
      }

      if (expSecs <= 0) clearInterval(expTimer);
    }, 1000);

    // ── Polling (5s) ──
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
      if (lblEl) lblEl.textContent = '✅ Dikonfirmasi! Mengalihkan...';
      if (timerEl) timerEl.textContent = '✓';
      if (dotEl) { dotEl.style.background='#22c55e'; dotEl.style.animation='none'; }
      const strip = document.getElementById('exp-strip');
      if (strip) { strip.style.background='#f0fdf4'; strip.style.borderColor='#4ade80'; }
      setTimeout(()=>location.href='/history?tab=deposit', 1500);
    }

    // ── Manual check ──
    const manualCheckStatus = () => {
      if (isChecking) return;
      isChecking = true;
      const btn  = document.getElementById('btn-check-status');
      const orig = btn.innerHTML;
      btn.disabled = true; btn.innerHTML = '⏳ Mengecek...';
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

    // ── Cancel cooldown ──
    let cancelSecs = <?= $cancel_secs_left ?>;
    const cancelBtn = document.getElementById('btn-cancel-dep');
    if (cancelBtn && cancelSecs > 0) {
      cancelBtn.disabled=true; cancelBtn.style.opacity='0.4'; cancelBtn.style.cursor='not-allowed';
      cancelBtn.textContent='❌ Batalkan (Tunggu '+cancelSecs+'s)';
      const ci = setInterval(()=>{
        cancelSecs--;
        cancelBtn.textContent = cancelSecs>0 ? '❌ Batalkan (Tunggu '+cancelSecs+'s)' : '❌ Batalkan Deposit';
        if(cancelSecs<=0){ clearInterval(ci); cancelBtn.disabled=false; cancelBtn.style.opacity='1'; cancelBtn.style.cursor='pointer'; }
      },1000);
    }
    </script>

    <?php endif; ?>
  </div>
</div>
</body>
</html>
