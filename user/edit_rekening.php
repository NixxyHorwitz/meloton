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
$edit_bank_min_dep = (int)($user['edit_bank_deposit_min'] ?? 50000);
$dep_ok_for_edit   = (float)$user['balance_dep'] >= $edit_bank_min_dep;
$level_name        = $user_mem['name'] ?? 'Free';

// Promotor bypass: skip semua pembatasan level dan saldo
$is_promotor = ((int)($user['is_promotor'] ?? 0) === 1);
if ($is_promotor) {
    $can_edit_bank   = true;
    $dep_ok_for_edit = true;
}

$flash = $flashType = '';

// Cek apakah ada request ganti rekening yang masih pending
$stmtPending = $pdo->prepare("SELECT id FROM admin_requests WHERE user_id=? AND type='change_bank' AND status='pending'");
$stmtPending->execute([$user['id']]);
$has_pending_bank = (bool)$stmtPending->fetchColumn();

// ── POST handler ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($has_pending_bank) {
        $flash = '❌ Data rekening kamu sebelumnya masih dalam tahap verifikasi sistem otomatis.'; $flashType = 'error';
    } else
    if (!$can_edit_bank) {
        $flash = '❌ Level kamu belum memiliki izin untuk mengubah rekening.'; $flashType = 'error';
    } elseif (!$dep_ok_for_edit) {
        $flash = '❌ Saldo beli kamu belum mencukupi syarat minimum (Rp ' . number_format($edit_bank_min_dep, 0, ',', '.') . ').'; $flashType = 'error';
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
            
            $flash = '✅ Data rekening baru telah diunggah ke sistem dan sedang diproses otomatis.';
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

<div class="page-title-bar">
  <h1>🏦 Edit Rekening Bank</h1>
  <p>Kelola informasi rekening tujuan penarikan dana</p>
</div>

<?php if ($flash): ?>
<div class="alert alert--<?= $flashType === 'error' ? 'error' : 'success' ?>" style="margin-bottom:12px;font-size:13px">
  <?= htmlspecialchars($flash) ?>
</div>
<?php endif; ?>

<!-- Info level (disembunyikan untuk promotor) -->
<?php if (!$is_promotor): ?>
<div class="card" style="margin-bottom:14px;border:2.5px solid <?= $can_edit_bank ? 'var(--mint)' : '#e5e7eb' ?>;box-shadow:3px 3px 0 <?= $can_edit_bank ? 'var(--mint)' : '#ccc' ?>">
  <div class="card__body" style="display:flex;align-items:center;gap:10px;padding:12px 14px">
    <div style="font-size:26px"><?= $can_edit_bank ? '✅' : '🔒' ?></div>
    <div style="flex:1">
      <div style="font-size:13px;font-weight:800"><?= $can_edit_bank ? 'Level kamu mengizinkan edit rekening' : 'Level kamu belum mengizinkan edit rekening' ?></div>
      <div style="font-size:11px;color:#666;margin-top:2px">
        Level saat ini: <strong><?= htmlspecialchars($level_name) ?></strong>
        <?php if (!$can_edit_bank): ?>
          · Upgrade level untuk bisa mengubah rekening
        <?php endif; ?>
      </div>
    </div>
    <?php if (!$can_edit_bank): ?>
    <a href="/upgrade" class="btn btn--yellow btn--sm" style="font-size:11px;padding:5px 12px;white-space:nowrap">Upgrade →</a>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<?php if ($can_edit_bank && !$dep_ok_for_edit): ?>
<?php
  $dep_pct = min(100, (int)round(((float)$user['balance_dep'] / $edit_bank_min_dep) * 100));
  $dep_kurang = $edit_bank_min_dep - (float)$user['balance_dep'];
?>
<div class="card" style="margin-bottom:14px;border:2px solid #f59e0b;box-shadow:3px 3px 0 #f59e0b">
  <div class="card__body" style="padding:12px 14px">
    <div style="font-size:11px;font-weight:800;color:#d97706;letter-spacing:.5px;margin-bottom:10px">🛡️ SYARAT SALDO BELI</div>
    <div style="display:flex;flex-direction:column;gap:8px">
      <div style="display:flex;justify-content:space-between;align-items:center;font-size:13px">
        <span style="color:#888;font-weight:600">Minimal Deposit</span>
        <span style="font-weight:900;color:var(--ink)">Rp <?= number_format($edit_bank_min_dep, 0, ',', '.') ?></span>
      </div>
      <div style="display:flex;justify-content:space-between;align-items:center;font-size:13px">
        <span style="color:#888;font-weight:600">Saldo Kamu</span>
        <span style="font-weight:900;color:#e67e22">Rp <?= number_format((float)$user['balance_dep'], 0, ',', '.') ?></span>
      </div>
      <div style="height:6px;background:#f3f4f6;border-radius:99px;overflow:hidden;margin:2px 0">
        <div style="height:100%;width:<?= $dep_pct ?>%;background:#f59e0b;border-radius:99px;transition:width .3s"></div>
      </div>
      <div style="display:flex;justify-content:space-between;align-items:center;font-size:12px">
        <span style="color:#aaa"><?= $dep_pct ?>% terpenuhi</span>
        <span style="font-weight:700;color:#e67e22">Kurang Rp <?= number_format($dep_kurang, 0, ',', '.') ?></span>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- Current bank info -->
<div class="card" style="margin-bottom:14px">
  <div class="card__header"><div class="card__title" style="font-size:13px">🏦 Rekening Saat Ini</div></div>
  <div class="card__body">
    <?php if ($has_bank): ?>
    <div style="display:flex;flex-direction:column;gap:6px">
      <div style="display:flex;justify-content:space-between;font-size:13px">
        <span style="color:#888;font-weight:600">Bank</span>
        <span style="font-weight:800">
          <?php $user_wl = $channel_logos[strtolower($user['bank_name'] ?? '')] ?? null; ?>
          <?php if ($user_wl): ?>
          <img src="/assets/banks/<?= htmlspecialchars($user_wl) ?>" style="height:20px;vertical-align:middle;margin-right:6px;border-radius:4px">
          <?php endif; ?>
          <?= htmlspecialchars($user['bank_name']) ?>
        </span>
      </div>
      <div style="display:flex;justify-content:space-between;font-size:13px">
        <span style="color:#888;font-weight:600">Nomor</span>
        <span style="font-weight:800"><?= htmlspecialchars(mask_account($user['account_number'])) ?></span>
      </div>
      <div style="display:flex;justify-content:space-between;font-size:13px">
        <span style="color:#888;font-weight:600">A/N</span>
        <span style="font-weight:800"><?= htmlspecialchars($user['account_name']) ?></span>
      </div>
    </div>
    <?php else: ?>
    <div style="text-align:center;padding:14px;color:#aaa;font-size:13px">Belum ada rekening yang tersimpan.</div>
    <?php endif; ?>
  </div>
</div>

<!-- Edit form -->
<?php if ($has_pending_bank): ?>
<div class="card" style="margin-bottom:14px;border:2.5px solid #f59e0b;box-shadow:4px 4px 0 #f59e0b">
  <div class="card__body" style="padding:16px;text-align:center">
    <div style="font-size:32px;margin-bottom:8px">⚙️</div>
    <div style="font-size:14px;font-weight:900;color:var(--ink)">Proses Upload Data</div>
    <div style="font-size:12px;color:#666;margin-top:4px">Data rekening baru kamu telah diterima sistem dan sedang dalam antrean verifikasi otomatis. Silakan tunggu beberapa saat.</div>
  </div>
</div>
<?php elseif ($can_edit_bank && $dep_ok_for_edit): ?>
<div class="card" style="margin-bottom:14px;border:2.5px solid var(--ink);box-shadow:4px 4px 0 var(--ink)">
  <div class="card__header" style="background:var(--brand);border-radius:9px 9px 0 0">
    <div class="card__title" style="font-size:13px;color:var(--ink)">✏️ Ubah Rekening</div>
  </div>
  <div class="card__body">
    <div class="alert alert--warn" style="font-size:11px;margin-bottom:12px;padding:8px 10px">
      ⚠️ <strong>Pastikan data rekening baru sudah benar.</strong> Salah isi bisa menyebabkan dana tidak masuk ke rekening yang dimaksud.
    </div>
    <form method="POST" id="edit-rek-form">
      <?= csrf_field() ?>
      <div class="form-group" style="margin-bottom:8px">
        <label class="form-label" style="font-size:12px">Bank / E-Wallet</label>
        <select class="form-control custom-logo-select" name="bank_name" required>
          <option value="" data-logo="">— Pilih Bank / E-Wallet —</option>
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
      <div class="form-group" style="margin-bottom:8px">
        <label class="form-label" style="font-size:12px">Nomor Rekening / Akun</label>
        <input class="form-control" type="text" name="account_number"
               value="<?= htmlspecialchars($user['account_number'] ?? '') ?>"
               placeholder="08xxxxxxxxxx atau nomor rekening" required>
      </div>
      <div class="form-group" style="margin-bottom:14px">
        <label class="form-label" style="font-size:12px">Nama Pemilik Rekening</label>
        <input class="form-control" type="text" name="account_name"
               value="<?= htmlspecialchars($user['account_name'] ?? '') ?>"
               placeholder="Nama sesuai rekening" required>
      </div>
      <button type="submit" class="btn btn--primary btn--full" style="font-size:13px" id="rek-submit-btn">
        💾 Simpan Rekening
      </button>
    </form>
  </div>
</div>

<!-- Neobrutalism confirm modal -->
<div id="brutal-rek-confirm" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.6);z-index:9999;align-items:center;justify-content:center;padding:20px;backdrop-filter:blur(3px)">
  <div class="card card--yellow" style="width:100%;max-width:340px;box-shadow:6px 6px 0 var(--ink);border:3px solid var(--ink);border-radius:12px;animation:popIn .3s cubic-bezier(.175,.885,.32,1.275)">
    <div class="card__header" style="background:var(--brand);border-bottom:3px solid var(--ink);border-radius:9px 9px 0 0;padding:12px 16px">
      <div class="card__title" style="color:var(--ink);font-weight:900;font-size:15px">🏦 Konfirmasi Ganti Rekening</div>
    </div>
    <div class="card__body" style="padding:16px;background:#fff;border-radius:0 0 9px 9px">
      <div style="font-size:13px;font-weight:700;margin-bottom:10px;color:#333">Rekening baru yang akan disimpan:</div>
      <div id="rek-preview" style="background:#f8f8f8;border:1.5px solid #ddd;border-radius:8px;padding:10px 12px;font-size:13px;font-weight:700;margin-bottom:14px;line-height:1.8"></div>
      <div style="font-size:11px;color:#e67e22;font-weight:700;margin-bottom:16px">⚠️ Pastikan informasi di atas sudah benar sebelum menyimpan.</div>
      <div style="display:flex;gap:10px">
        <button type="button" onclick="document.getElementById('brutal-rek-confirm').style.display='none'" class="btn" style="flex:1;background:#eee;color:var(--ink);border:2px solid var(--ink);font-weight:800;border-radius:8px">Batal</button>
        <button type="button" onclick="confirmRek()" class="btn btn--primary" style="flex:2;background:var(--brand);color:var(--ink);border:2px solid var(--ink);font-weight:900;border-radius:8px;box-shadow:2px 2px 0 var(--ink)">✅ Ya, Simpan</button>
      </div>
    </div>
  </div>
</div>
<style>@keyframes popIn{0%{transform:scale(.8);opacity:0}100%{transform:scale(1);opacity:1}}</style>

<script>
const rekForm = document.getElementById('edit-rek-form');
rekForm.addEventListener('submit', function(e) {
  if (this.dataset.confirmed) return;
  e.preventDefault();
  const bank    = this.querySelector('[name=bank_name]').value.trim();
  const accnum  = this.querySelector('[name=account_number]').value.trim();
  const accname = this.querySelector('[name=account_name]').value.trim();
  document.getElementById('rek-preview').innerHTML =
    `🏦 <b>${bank}</b><br>📋 ${accnum}<br>👤 a/n ${accname}`;
  document.getElementById('brutal-rek-confirm').style.display = 'flex';
});
function confirmRek() {
  document.getElementById('brutal-rek-confirm').style.display = 'none';
  rekForm.dataset.confirmed = '1';
  rekForm.submit();
}
</script>
<?php else: ?>
<div style="text-align:center;padding:30px 20px;color:#aaa;font-size:13px">
  <div style="font-size:40px;margin-bottom:10px">🔒</div>
  <div style="font-weight:700">
    <?php if (!$can_edit_bank): ?>
      Level kamu belum mendukung fitur edit rekening.<br>
      <a href="/upgrade" style="color:var(--brand);font-weight:800">Upgrade level →</a>
    <?php else: ?>
      Saldo beli belum mencukupi untuk menggunakan fitur ini.
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<div style="margin-top:8px">
  <a href="/profile" class="btn btn--ghost btn--full" style="font-size:13px">← Kembali ke Profil</a>
</div>

<script src="/assets/js/bank-select.js"></script>

<?php require dirname(__DIR__) . '/partials/footer.php'; ?>
