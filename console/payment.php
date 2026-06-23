<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
staff_require('payment');
csrf_enforce();

$flash = $flashType = '';
global $pdo;
$s = fn($k,$d='') => setting($pdo,$k,$d);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_bank_settings') {
        setting_set($pdo, 'bank_enabled', isset($_POST['bank_enabled']) ? '1' : '0');
        setting_set($pdo, 'bank_name',    clean_input($_POST['bank_name'] ?? ''));
        setting_set($pdo, 'bank_account', clean_input($_POST['bank_account'] ?? ''));
        setting_set($pdo, 'bank_holder',  clean_input($_POST['bank_holder'] ?? ''));
        $flash = '✅ Pengaturan bank disimpan!';
    }

    if ($action === 'save_qris_settings') {
        setting_set($pdo, 'qris_enabled', isset($_POST['qris_enabled']) ? '1' : '0');
        setting_set($pdo, 'qris_raw', trim($_POST['qris_raw'] ?? ''));
        $flash = '✅ Pengaturan QRIS disimpan!';
    }

    if ($action === 'save_deposit_limits') {
        setting_set($pdo, 'min_deposit', clean_input($_POST['min_deposit'] ?? '10000'));
        $flash = '✅ Limit deposit disimpan!';
    }

    if ($action === 'save_confirm_mode') {
        setting_set($pdo, 'deposit_confirm_mode', $_POST['confirm_mode'] === 'auto' ? 'auto' : 'manual');
        $flash = '✅ Mode konfirmasi disimpan!';
    }
}

$bank_on     = $s('bank_enabled','1') === '1';
$qris_on     = $s('qris_enabled','1') === '1';
$confirm_mode= $s('deposit_confirm_mode','manual');

$pageTitle  = 'Rekening & Pembayaran';
$activePage = 'payment';
require __DIR__ . '/partials/header.php';
?>

<div class="mb-4 d-flex align-items-center gap-3">
  <div>
    <h5 class="mb-0 fw-bold">💳 Rekening & Pembayaran</h5>
    <div style="font-size:12px;color:#666;margin-top:2px">Kelola metode deposit yang tersedia untuk user</div>
  </div>
</div>

<?php if ($flash): ?>
<div class="alert alert-success py-2 mb-3" style="border-radius:10px;font-size:13px"><?= htmlspecialchars($flash) ?></div>
<?php endif; ?>

<div class="row g-3">
  <!-- Deposit limits + Confirm Mode -->
  <div class="col-12">
    <div class="row g-3">
      <div class="col-md-5">
        <div class="c-card h-100">
          <div class="c-card-header"><span class="c-card-title">⚙️ Batas Deposit</span></div>
          <div class="c-card-body">
            <form method="POST">
              <?= csrf_field() ?><input type="hidden" name="action" value="save_deposit_limits">
              <div class="c-form-group">
                <label class="c-label">Minimum Deposit (Rp)</label>
                <input type="number" name="min_deposit" class="c-form-control" value="<?= $s('min_deposit','10000') ?>" min="1000" step="any">
              </div>
              <button type="submit" class="btn btn-sm text-white w-100" style="background:var(--brand)">Simpan</button>
            </form>
          </div>
        </div>
      </div>
      <div class="col-md-7">
        <div class="c-card h-100">
          <div class="c-card-header">
            <span class="c-card-title">🔄 Mode Konfirmasi Deposit</span>
            <?php if ($confirm_mode === 'auto'): ?>
            <span class="badge b-warn px-2 py-1 rounded" style="font-size:10px">⚠️ Coming Soon</span>
            <?php else: ?>
            <span class="badge b-success px-2 py-1 rounded" style="font-size:10px">● Aktif</span>
            <?php endif; ?>
          </div>
          <div class="c-card-body">
            <form method="POST">
              <?= csrf_field() ?><input type="hidden" name="action" value="save_confirm_mode">
              <div class="c-form-group">
                <div style="display:flex;flex-direction:column;gap:10px">
                  <!-- Manual mode -->
                  <label style="display:flex;align-items:flex-start;gap:10px;cursor:pointer;padding:12px;border:1.5px solid <?= $confirm_mode==='manual'?'var(--brand)':'#1f2235' ?>;border-radius:8px;background:<?= $confirm_mode==='manual'?'rgba(255,107,53,.08)':'transparent' ?>">
                    <input type="radio" name="confirm_mode" value="manual" <?= $confirm_mode==='manual'?'checked':'' ?> style="margin-top:2px;accent-color:var(--brand)">
                    <div>
                      <div style="font-size:13px;font-weight:700">📋 Manual (Upload Bukti)</div>
                      <div style="font-size:11px;color:#666;margin-top:3px">User upload screenshot/bukti transfer. Admin konfirmasi secara manual.</div>
                    </div>
                  </label>
                  <!-- Auto mode (fake/coming soon) -->
                  <label style="display:flex;align-items:flex-start;gap:10px;cursor:pointer;padding:12px;border:1.5px solid <?= $confirm_mode==='auto'?'#FFC107':'#1f2235' ?>;border-radius:8px;background:<?= $confirm_mode==='auto'?'rgba(255,193,7,.08)':'transparent' ?>;opacity:.75">
                    <input type="radio" name="confirm_mode" value="auto" <?= $confirm_mode==='auto'?'checked':'' ?> style="margin-top:2px;accent-color:#FFC107">
                    <div style="flex:1">
                      <div style="display:flex;align-items:center;gap:6px">
                        <span style="font-size:13px;font-weight:700">⚡ Otomatis (Auto Confirm)</span>
                        <span style="font-size:9px;font-weight:800;background:#FFC107;color:#000;padding:2px 6px;border-radius:4px">COMING SOON</span>
                      </div>
                      <div style="font-size:11px;color:#666;margin-top:3px">Saldo otomatis ditambahkan setelah pembayaran terdeteksi via API (perlu integrasi payment gateway).</div>
                    </div>
                  </label>
                </div>
              </div>
              <button type="submit" class="btn btn-sm text-white w-100" style="background:var(--brand)">Simpan Mode</button>
            </form>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Bank Transfer -->
  <div class="col-md-6">
    <div class="c-card h-100">
      <div class="c-card-header">
        <span class="c-card-title">🏦 Transfer Bank</span>
        <span class="badge px-2 py-1 rounded-pill <?= $bank_on ? 'b-success' : 'b-danger' ?>" style="font-size:11px">
          <?= $bank_on ? '● Aktif' : '● Nonaktif' ?>
        </span>
      </div>
      <div class="c-card-body">
        <form method="POST">
          <?= csrf_field() ?><input type="hidden" name="action" value="save_bank_settings">
          <div class="c-form-group">
            <label class="c-label">Status</label>
            <div style="display:flex;gap:12px;align-items:center;padding:8px 0">
              <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:13px">
                <input type="checkbox" name="bank_enabled" value="1" <?= $bank_on?'checked':'' ?> style="width:16px;height:16px">
                Enable Transfer Bank
              </label>
            </div>
          </div>
          <div class="c-form-group">
            <label class="c-label">Nama Bank</label>
            <input type="text" name="bank_name" class="c-form-control" value="<?= htmlspecialchars($s('bank_name','BCA')) ?>" placeholder="BCA, Mandiri, BNI...">
          </div>
          <div class="c-form-group">
            <label class="c-label">Nomor Rekening</label>
            <input type="text" name="bank_account" class="c-form-control" value="<?= htmlspecialchars($s('bank_account')) ?>" placeholder="1234567890">
          </div>
          <div class="c-form-group">
            <label class="c-label">Nama Pemilik</label>
            <input type="text" name="bank_holder" class="c-form-control" value="<?= htmlspecialchars($s('bank_holder')) ?>" placeholder="Nama sesuai rekening">
          </div>
          <button type="submit" class="btn btn-sm text-white w-100" style="background:var(--brand)">Simpan Bank</button>
        </form>
      </div>
    </div>
  </div>

  <!-- QRIS -->
  <div class="col-md-6">
    <div class="c-card h-100">
      <div class="c-card-header">
        <span class="c-card-title">📱 QRIS</span>
        <span class="badge px-2 py-1 rounded-pill <?= $qris_on ? 'b-success' : 'b-danger' ?>" style="font-size:11px">
          <?= $qris_on ? '● Aktif' : '● Nonaktif' ?>
        </span>
      </div>
      <div class="c-card-body">
        <form method="POST">
          <?= csrf_field() ?><input type="hidden" name="action" value="save_qris_settings">
          <div class="c-form-group">
            <label class="c-label">Status</label>
            <div style="display:flex;gap:12px;align-items:center;padding:8px 0">
              <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:13px">
                <input type="checkbox" name="qris_enabled" value="1" <?= $qris_on?'checked':'' ?> style="width:16px;height:16px">
                Enable Bayar via QRIS
              </label>
            </div>
          </div>
          <div class="c-form-group">
            <label class="c-label">QRIS Raw String</label>
            <textarea name="qris_raw" class="c-form-control" rows="6"
              placeholder="00020101021226..."><?= htmlspecialchars($s('qris_raw')) ?></textarea>
            <div style="font-size:11px;color:#666;margin-top:4px">
              Paste QRIS statis tanpa nominal (Tag 54 akan diisi otomatis sesuai input user).<br>
              Kosongkan = QRIS dinonaktifkan otomatis.
            </div>
          </div>
          <?php if (!empty($s('qris_raw'))): ?>
          <div style="margin-bottom:12px">
            <label class="c-label">Preview QR (test nominal Rp 10.000)</label>
            <div style="text-align:center;padding:8px;background:#1a1d27;border-radius:8px">
              <img src="https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=<?= urlencode(qris_with_amount($s('qris_raw'), 10000)) ?>"
                   alt="QR Preview" style="border-radius:6px">
            </div>
          </div>
          <?php endif; ?>
          <button type="submit" class="btn btn-sm text-white w-100" style="background:var(--brand)">Simpan QRIS</button>
        </form>
      </div>
    </div>
  </div>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>
