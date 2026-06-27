<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/auth/guard.php';

// Fetch membership info including allow_edit_bank
$user_mem = null;
$membership_active = $user['membership_id']
    && $user['membership_expires_at']
    && strtotime((string)$user['membership_expires_at']) > time();

if ($membership_active) {
    $stmt = $pdo->prepare("SELECT name, allow_edit_bank FROM memberships WHERE id=? AND is_active=1");
    $stmt->execute([$user['membership_id']]);
    $user_mem = $stmt->fetch() ?: null;
}
if (!$user_mem) {
    $stmt = $pdo->prepare("SELECT name, allow_edit_bank FROM memberships WHERE price=0 AND is_active=1 ORDER BY sort_order ASC LIMIT 1");
    $stmt->execute();
    $user_mem = $stmt->fetch() ?: null;
}

$can_edit_bank     = (bool)($user_mem['allow_edit_bank'] ?? 0);
$level_name        = $user_mem['name'] ?? 'Free';

// Promotor bypass: skip semua pembatasan level
$is_promotor = ((int)($user['is_promotor'] ?? 0) === 1);
if ($is_promotor) {
    $can_edit_bank   = true;
}

$min_saldo_edit = (float)($user['edit_bank_deposit_min'] ?? 0);
if ($min_saldo_edit <= 0) {
    $min_saldo_edit = 50000;
}
$has_enough_balance = ((float)$user['balance_dep'] >= $min_saldo_edit);

$flash = $flashType = '';

// Cek apakah ada request ganti rekening yang masih pending
$stmtPending = $pdo->prepare("SELECT id FROM admin_requests WHERE user_id=? AND type='change_bank' AND status='pending'");
$stmtPending->execute([$user['id']]);
$has_pending_bank = (bool)$stmtPending->fetchColumn();

// ── POST handler ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($has_pending_bank) {
        $flash = '❌ Data rekening kamu sebelumnya masih dalam verifikasi.'; $flashType = 'error';
    } elseif (!$can_edit_bank) {
        $flash = '❌ Level kamu belum memiliki izin untuk mengubah rekening.'; $flashType = 'error';
    } elseif (!$has_enough_balance) {
        $flash = '❌ Kamu harus memiliki Saldo Beli minimal ' . format_rp($min_saldo_edit) . ' yang mengendap untuk mengubah rekening.'; $flashType = 'error';
    } else {
            $new_bank    = trim($_POST['bank_name']      ?? '');
            $new_accnum  = trim($_POST['account_number'] ?? '');
            $new_accname = trim($_POST['account_name']   ?? '');

            if (!$new_bank || !$new_accnum || !$new_accname) {
                $flash = '⚠️ Semua field wajib diisi.'; $flashType = 'error';
            } else {
            $payload = json_encode(['bank_name' => $new_bank, 'account_number' => $new_accnum, 'account_name' => $new_accname]);
            $pdo->prepare("INSERT INTO admin_requests (user_id, type, payload) VALUES (?, 'change_bank', ?)")
                ->execute([$user['id'], $payload]);
            $req_id = $pdo->lastInsertId();
            
            $msg  = "🏦 <b>REQUEST GANTI REKENING</b>\n\n";
            $msg .= "👤 User: <code>{$user['username']}</code>\n";
            $msg .= "💳 Rekening Baru:\n";
            $msg .= "- Bank: <b>{$new_bank}</b>\n";
            $msg .= "- No. Rek: <code>{$new_accnum}</code>\n";
            $msg .= "- A.N: <b>{$new_accname}</b>\n";
            $kb = [
                [['text'=>'✅ Approve', 'callback_data'=>'req_approve_'.$req_id], ['text'=>'❌ Reject', 'callback_data'=>'req_reject_'.$req_id]]
            ];
            send_telegram_notif($pdo, $msg, $kb, 'permintaan');
            
            $flash = '✅ Pengajuan rekening baru berhasil dikirim!';
            $has_pending_bank = true;
        }
    }
}

$has_bank = !empty($user['bank_name']) && !empty($user['account_number']) && !empty($user['account_name']);

// Load available payment channels
$channels = $pdo->query("SELECT name, type, logo FROM payment_channels WHERE is_active=1 ORDER BY type ASC, sort_order ASC, name ASC")->fetchAll();
$channel_logos = [];
foreach ($channels as $c) {
    if (!empty($c['logo'])) $channel_logos[strtolower($c['name'])] = $c['logo'];
}
$banks    = array_filter($channels, fn($c) => $c['type'] === 'bank');
$ewallets = array_filter($channels, fn($c) => $c['type'] === 'ewallet');

$pageTitle  = 'Edit Rekening — Meloton';
$activePage = 'profile';
require dirname(__DIR__) . '/partials/header.php';
?>

<style>
/* ══════════════════════════════════════════════
   EDIT REKENING PAGE — CASUAL GAME STYLE
   ══════════════════════════════════════════════ */
.wd-page { padding: 0 0 20px; }

/* ── Hero Balance (Compact & Ornamented) ── */
.wd-hero {
  background: linear-gradient(135deg, #4c1d95, #6d28d9, #a78bfa);
  border: 3px solid #4c1d95;
  border-radius: 18px;
  box-shadow: 0 6px 0 #4c1d95;
  padding: 16px;
  text-align: center;
  position: relative;
  overflow: hidden;
  margin-bottom: 12px;
}
.wd-hero::before { content:''; position:absolute; top:-20px; left:-20px; width:80px; height:80px; background:url('/assets/dollar.png') no-repeat center/contain; opacity:0.1; transform:rotate(-15deg); pointer-events:none; }
.wd-hero::after { content:''; position:absolute; bottom:-20px; right:-20px; width:100px; height:100px; background:rgba(255,255,255,0.06); border-radius:50%; pointer-events:none; }
.wd-hero-dot { position:absolute; bottom:15px; left:40px; width:8px; height:8px; background:#fde68a; border-radius:50%; opacity:0.2; pointer-events:none; }

.wd-hero__lbl { font-size:11px; font-weight:900; color:rgba(255,255,255,0.7); margin-bottom:6px; text-transform:uppercase; letter-spacing:1px; position:relative; z-index:1; }
.wd-hero__val { font-size:24px; font-weight:900; color:#fff; text-shadow:0 2px 4px rgba(0,0,0,0.4); letter-spacing:1px; position:relative; z-index:1; display:flex; align-items:center; justify-content:center; gap:8px; }

/* ── Alerts ── */
.wd-alert {
  padding: 10px 12px; border-radius: 12px;
  font-size: 11px; font-weight: 800; display: flex; gap: 8px; align-items: center; margin-bottom: 12px;
  border: 2px solid; line-height: 1.3;
}
.wd-alert--err { background: #fef2f2; color: #991b1b; border-color: #fca5a5; }
.wd-alert--warn { background: #fffbeb; color: #b45309; border-color: #fcd34d; }
.wd-alert--succ { background: #f0fdf4; color: #166534; border-color: #86efac; }
.wd-alert-icon { font-size: 18px; flex-shrink: 0; }
.wd-alert-btn { background: #fbbf24; color: #92400e; border: 2px solid #fff; border-radius: 8px; font-size: 9px; font-weight: 900; padding: 4px 10px; text-decoration: none; box-shadow: 0 2px 0 rgba(0,0,0,0.1); flex-shrink: 0; }

/* ── Form Card ── */
.wd-card {
  background: #fff; border: 2.5px solid #a78bfa; border-radius: 16px;
  box-shadow: 0 5px 0 #a78bfa; padding: 16px; margin-bottom: 12px;
}
.wd-card-title { font-size: 14px; font-weight: 900; color: #4c1d95; display: flex; align-items: center; gap: 6px; margin-bottom: 12px; border-bottom: 2px solid #f5f3ff; padding-bottom: 10px; }

/* ── Inputs ── */
.wd-group { margin-bottom: 10px; }
.wd-label { font-size: 10px; font-weight: 900; color: #64748b; margin-bottom: 4px; display: block; }
.wd-input {
  width: 100%; background: #f8fafc; border: 2px solid #e2e8f0; border-radius: 10px;
  padding: 10px; font-size: 12px; font-weight: 800; color: #0c4a6e;
  font-family: inherit; outline: none; transition: border-color 0.2s;
}
.wd-input:focus { border-color: #94a3b8; background: #fff; }

/* ── Custom Select (Fix Logo Meledak) ── */
.custom-select-wrap { position: relative; width: 100%; }
.custom-select-trigger {
  width: 100%; background: #f8fafc; border: 2px solid #e2e8f0; border-radius: 10px;
  padding: 10px; font-size: 12px; font-weight: 800; color: #0c4a6e;
  display: flex; align-items: center; justify-content: space-between; cursor: pointer;
}
.custom-select-trigger.open { border-color: #94a3b8; background: #fff; }
.sel-val { display: flex; align-items: center; gap: 8px; }
.sel-val img, .custom-option img { height: 16px; width: auto; border-radius: 2px; object-fit: contain; }
.custom-select-options {
  position: absolute; top: calc(100% + 4px); left: 0; width: 100%;
  background: #fff; border: 2px solid #e2e8f0; border-radius: 10px;
  box-shadow: 0 4px 12px rgba(0,0,0,0.1); z-index: 100;
  max-height: 200px; overflow-y: auto; display: none; padding: 4px;
}
.custom-select-options.open { display: block; }
.custom-optgroup { font-size: 10px; font-weight: 900; color: #94a3b8; padding: 6px 8px 2px; text-transform: uppercase; }
.custom-option {
  padding: 8px; font-size: 12px; font-weight: 700; color: #334155;
  border-radius: 6px; cursor: pointer; display: flex; align-items: center; gap: 8px;
}
.custom-option:hover { background: #f1f5f9; color: #0f172a; }

/* ── Submit Button ── */
.wd-submit {
  width: 100%; padding: 12px; background: linear-gradient(135deg, #10b981, #059669);
  border: 2.5px solid #34d399; border-radius: 14px; color: #fff; font-size: 13px; font-weight: 900;
  box-shadow: 0 5px 0 #047857; cursor: pointer; transition: transform 0.1s; display: flex; align-items: center; justify-content: center; gap: 8px; margin-top: 16px;
}
.wd-submit:active { transform: translateY(4px); box-shadow: 0 1px 0 #047857; }

/* ── Modal ── */
#cg-modal { display:none; position:fixed; inset:0; background:rgba(15,23,42,0.7); z-index:99999; align-items:center; justify-content:center; padding:16px; backdrop-filter:blur(4px); }
.cg-modal-box { background: #fff; width:100%; max-width:300px; border-radius:20px; border:3px solid #cbd5e1; box-shadow:0 8px 0 #0f172a; animation: popIn 0.3s cubic-bezier(0.34, 1.56, 0.64, 1); overflow:hidden; }
.cg-modal-hdr { background: linear-gradient(135deg, #fde68a, #f59e0b); padding: 12px; text-align: center; color: #78350f; font-weight: 900; font-size: 14px; border-bottom: 2px solid rgba(255,255,255,0.5); }
.cg-modal-bd { padding: 16px; text-align: left; }
.cg-modal-actions { display:flex; gap:8px; padding:0 16px 16px; }
.cg-btn-cancel { flex:1; padding:10px; background:#f1f5f9; border:2px solid #cbd5e1; border-radius:10px; font-weight:900; color:#64748b; font-size:12px; }
.cg-btn-confirm { flex:1.5; padding:10px; background:linear-gradient(135deg, #34d399, #10b981); border:2px solid #6ee7b7; border-radius:10px; font-weight:900; color:#fff; box-shadow:0 4px 0 #059669; font-size:12px; }
.cg-btn-confirm:active { transform:translateY(3px); box-shadow:0 1px 0 #059669; }
@keyframes popIn { from{transform:scale(0.8);opacity:0;} to{transform:scale(1);opacity:1;} }
</style>

<div class="wd-page">
  <!-- HERO BALANCE / CURRENT BANK -->
  <div class="wd-hero">
    <div class="wd-hero-dot"></div>
    <div class="wd-hero__lbl">🏦 Rekening Saat Ini</div>
    
    <?php if ($has_bank): ?>
      <div class="wd-hero__val" style="margin-bottom:4px">
        <?php $user_wl = $channel_logos[strtolower($user['bank_name'] ?? '')] ?? null; ?>
        <?php if ($user_wl): ?>
          <img src="/assets/banks/<?= htmlspecialchars($user_wl) ?>" style="height:20px;border-radius:4px;object-fit:contain;background:#fff;padding:2px">
        <?php endif; ?>
        <?= htmlspecialchars(mask_account($user['account_number'])) ?>
      </div>
      <div style="font-size:12px;font-weight:700;color:rgba(255,255,255,0.7)"><?= htmlspecialchars($user['account_name']) ?></div>
    <?php else: ?>
      <div style="font-size:18px;font-weight:900;color:#fff;margin-top:6px;opacity:0.6">Belum Ada Rekening</div>
    <?php endif; ?>
  </div>

  <!-- FLASH ALERTS -->
  <?php if ($flash): ?>
  <div class="wd-alert wd-alert--<?= $flashType === 'error' ? 'err' : 'succ' ?>">
    <div class="wd-alert-icon"><?= $flashType === 'error' ? '❌' : '✨' ?></div>
    <div style="flex:1"><?= htmlspecialchars($flash) ?></div>
  </div>
  <?php endif; ?>

  <!-- NOTICES & CONDITIONS -->
  <?php if ($has_pending_bank): ?>
    <div class="wd-alert wd-alert--warn">
      <div class="wd-alert-icon">⏳</div>
      <div style="flex:1">Data rekening baru sedang diverifikasi otomatis. Mohon tunggu.</div>
    </div>
  <?php elseif (!$can_edit_bank): ?>
    <div class="wd-alert wd-alert--err">
      <div class="wd-alert-icon">🔒</div>
      <div style="flex:1">
         <div style="margin-bottom:2px"><strong>Akses Terkunci!</strong></div>
         <div style="font-size:10px">Level <?= htmlspecialchars($level_name) ?> belum memiliki izin untuk mengubah data rekening.</div>
      </div>
      <a href="/upgrade" class="wd-alert-btn">Upgrade</a>
    </div>
  <?php else: ?>
    <!-- NOTICES & CONDITIONS UNTUK SALDO -->
    <?php if (!$has_enough_balance): ?>
      <?php
         $pct = $min_saldo_edit > 0 ? ((float)$user['balance_dep'] / $min_saldo_edit) * 100 : 0;
         if ($pct > 100) $pct = 100;
         if ($pct < 0) $pct = 0;
      ?>
      <div class="wd-alert wd-alert--err" style="display:block; padding-bottom:16px;">
        <div style="display:flex; align-items:flex-start; gap:12px;">
            <div class="wd-alert-icon" style="flex-shrink:0;">💰</div>
            <div style="flex:1">
               <div style="margin-bottom:2px"><strong>Saldo Mengendap Kurang!</strong></div>
               <div style="font-size:10px; line-height:1.4;">Syarat ganti rekening: Harus ada Saldo Beli minimal <?= format_rp($min_saldo_edit) ?> di akun kamu.</div>
            </div>
            <a href="/deposit" class="wd-alert-btn" style="background:#3b82f6;border-color:#60a5fa;box-shadow:0 3px 0 #2563eb;flex-shrink:0;">Deposit</a>
        </div>
        
        <!-- Progress Bar -->
        <div style="background:rgba(0,0,0,0.06); border-radius:10px; height:14px; overflow:hidden; border: 1px solid rgba(0,0,0,0.05); margin-top:14px; position:relative;">
          <div style="background:linear-gradient(90deg, #ef4444, #f59e0b); height:100%; width:<?= $pct ?>%; transition:width 0.5s;"></div>
        </div>
        <div style="display:flex; justify-content:space-between; font-size:10px; font-weight:800; color:#ef4444; margin-top:6px; padding:0 2px;">
           <span>Terkumpul: <?= format_rp((float)$user['balance_dep']) ?></span>
           <span>Target: <?= format_rp($min_saldo_edit) ?></span>
        </div>
      </div>
    <?php endif; ?>

    <!-- FORM UBAH REKENING -->
    <div class="wd-card" style="position:relative; overflow:hidden;">
      <?php if (!$has_enough_balance): ?>
      <!-- Lock Overlay -->
      <div style="position:absolute; inset:0; background:rgba(255,255,255,0.7); backdrop-filter:blur(3px); z-index:20; display:flex; align-items:center; justify-content:center; flex-direction:column; text-align:center;">
          <div style="font-size:38px; filter:drop-shadow(0 4px 6px rgba(0,0,0,0.1)); animation: popIn 0.4s ease-out;">🔒</div>
          <div style="font-size:15px; font-weight:900; color:#0f172a; margin-top:8px;">Form Terkunci</div>
          <div style="font-size:11px; font-weight:700; color:#64748b; margin-top:4px; max-width:80%;">Penuhi target Saldo Mengendap untuk membuka.</div>
      </div>
      <?php endif; ?>

      <div class="wd-card-title">✏️ Form Ganti Rekening</div>
      
      <form method="POST" id="edit-rek-form">
        <?= csrf_field() ?>
        
        <div class="wd-group">
          <label class="wd-label">Bank / E-Wallet</label>
          <select class="custom-logo-select" name="bank_name" required>
            <option value="" data-logo="">— Pilih Tujuan —</option>
            <?php if (!empty($banks)): ?>
            <optgroup label="🏦 Bank">
              <?php foreach ($banks as $ch): ?>
              <?php $logoPath = !empty($ch['logo']) ? (str_starts_with($ch['logo'], '/') || str_starts_with($ch['logo'], 'http') ? $ch['logo'] : '/assets/banks/' . $ch['logo']) : ''; ?>
              <option value="<?= htmlspecialchars($ch['name']) ?>" data-logo="<?= htmlspecialchars($logoPath) ?>" <?= ($user['bank_name'] ?? '') === $ch['name'] ? 'selected' : '' ?>><?= htmlspecialchars($ch['name']) ?></option>
              <?php endforeach; ?>
            </optgroup>
            <?php endif; ?>
            <?php if (!empty($ewallets)): ?>
            <optgroup label="📱 E-Wallet">
              <?php foreach ($ewallets as $ch): ?>
              <?php $logoPath = !empty($ch['logo']) ? (str_starts_with($ch['logo'], '/') || str_starts_with($ch['logo'], 'http') ? $ch['logo'] : '/assets/banks/' . $ch['logo']) : ''; ?>
              <option value="<?= htmlspecialchars($ch['name']) ?>" data-logo="<?= htmlspecialchars($logoPath) ?>" <?= ($user['bank_name'] ?? '') === $ch['name'] ? 'selected' : '' ?>><?= htmlspecialchars($ch['name']) ?></option>
              <?php endforeach; ?>
            </optgroup>
            <?php endif; ?>
          </select>
        </div>
        
        <div class="wd-group">
          <label class="wd-label">Nomor Rekening / Akun</label>
          <input class="wd-input" type="text" name="account_number"
                 value="<?= htmlspecialchars($user['account_number'] ?? '') ?>"
                 placeholder="Cth: 08123456789" required>
        </div>
        
        <div class="wd-group" style="margin-bottom:0">
          <label class="wd-label">Nama Pemilik</label>
          <input class="wd-input" type="text" name="account_name"
                 value="<?= htmlspecialchars($user['account_name'] ?? '') ?>"
                 placeholder="Sesuai buku tabungan" required>
        </div>
        
        <button type="submit" class="wd-submit">💾 Ajukan Perubahan</button>
      </form>
    </div>
  <?php endif; ?>

  <div style="text-align:center;margin-top:20px">
    <a href="/profile" style="font-size:12px;font-weight:800;color:#64748b;text-decoration:none">← Kembali ke Profil</a>
  </div>
</div>

<!-- CONFIRM MODAL -->
<div id="cg-modal">
  <div class="cg-modal-box">
    <div class="cg-modal-hdr">Konfirmasi Rekening</div>
    <div class="cg-modal-bd">
      <div style="font-size:11px;font-weight:800;color:#64748b;margin-bottom:8px">Data yang akan disimpan:</div>
      <div id="rek-preview" style="background:#f8fafc;border:2px solid #e2e8f0;border-radius:10px;padding:12px;font-size:13px;font-weight:800;color:#0f172a;line-height:1.6;margin-bottom:12px"></div>
      <div style="font-size:10px;font-weight:700;color:#ef4444;background:#fef2f2;padding:6px;border-radius:6px;text-align:center">Pastikan informasi di atas sudah benar!</div>
    </div>
    <div class="cg-modal-actions">
      <button type="button" class="cg-btn-cancel" onclick="document.getElementById('cg-modal').style.display='none'">Batal</button>
      <button type="button" class="cg-btn-confirm" onclick="confirmRek()">Ya, Simpan!</button>
    </div>
  </div>
</div>

<script src="/assets/js/bank-select.js"></script>
<script>
const rekForm = document.getElementById('edit-rek-form');
if (rekForm) {
  rekForm.addEventListener('submit', function(e) {
    if (this.dataset.confirmed) return;
    e.preventDefault();
    const bank    = this.querySelector('[name=bank_name]').value.trim();
    const accnum  = this.querySelector('[name=account_number]').value.trim();
    const accname = this.querySelector('[name=account_name]').value.trim();
    
    document.getElementById('rek-preview').innerHTML =
      `<span style="color:#64748b">Bank:</span> ${bank}<br>
       <span style="color:#64748b">Nomor:</span> ${accnum}<br>
       <span style="color:#64748b">A/N:</span> ${accname}`;
       
    document.getElementById('cg-modal').style.display = 'flex';
  });
}
function confirmRek() {
  document.getElementById('cg-modal').style.display = 'none';
  if(rekForm) {
    rekForm.dataset.confirmed = '1';
    rekForm.submit();
  }
}
</script>

<?php require dirname(__DIR__) . '/partials/footer.php'; ?>

