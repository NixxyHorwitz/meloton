<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
staff_require('settings');
csrf_enforce();

$flash     = $_SESSION['settings_flash']      ?? '';
$flashType = $_SESSION['settings_flash_type'] ?? '';
unset($_SESSION['settings_flash'], $_SESSION['settings_flash_type']);
global $pdo;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'save_wd_settings') {
        setting_set($pdo, 'wd_global_enabled', isset($_POST['wd_global_enabled']) ? '1' : '0');
        setting_set($pdo, 'wd_require_level', isset($_POST['wd_require_level']) ? '1' : '0');
        setting_set($pdo, 'min_withdraw', clean_input($_POST['min_withdraw'] ?? '50000'));
        setting_set($pdo, 'wd_min_level', clean_input($_POST['wd_min_level'] ?? '0'));
        
        setting_set($pdo, 'wd_free_require_1day', isset($_POST['wd_free_require_1day']) ? '1' : '0');
        setting_set($pdo, 'wd_free_limit_1x', isset($_POST['wd_free_limit_1x']) ? '1' : '0');
        setting_set($pdo, 'wd_free_only_dana', isset($_POST['wd_free_only_dana']) ? '1' : '0');
        
        // Lock schedule
        setting_set($pdo, 'wd_lock_start', trim($_POST['wd_lock_start'] ?? ''));
        setting_set($pdo, 'wd_lock_end',   trim($_POST['wd_lock_end'] ?? ''));
        setting_set($pdo, 'wd_lock_notice', trim($_POST['wd_lock_notice'] ?? 'Penarikan hanya bisa dilakukan pada jam tertentu.'));
        
        $flash = 'Pengaturan Penarikan (WD) berhasil disimpan!';
        $flashType = 'success';
    }
}

$s = fn($k, $d='') => setting($pdo, $k, $d);

$pageTitle  = 'Pengaturan Withdraw';
$activePage = 'wd_settings';
require __DIR__ . '/partials/header.php';

// WD Lock Estimation Logic
$wd_locked = is_wd_locked($pdo);
$start_lock = $s('wd_lock_start');
$end_lock   = $s('wd_lock_end');
$wd_estimation = '';

if ($start_lock && $end_lock) {
    $now_ts = time();
    $s_ts = strtotime(date('Y-m-d ') . $start_lock);
    $e_ts = strtotime(date('Y-m-d ') . $end_lock);
    
    if ($wd_locked) {
        if ($e_ts <= $now_ts) $e_ts += 86400;
        $diff = $e_ts - $now_ts;
        $h = floor($diff / 3600);
        $m = floor(($diff % 3600) / 60);
        $wd_estimation = "<div class='alert alert-warning py-2 mb-3' style='border-radius:8px;font-size:13px'>⏳ Saat ini WD <strong>DITUTUP</strong>. Akan dibuka kembali dalam <strong>{$h} jam {$m} menit</strong>.</div>";
    } else {
        if ($s_ts <= $now_ts) $s_ts += 86400;
        $diff = $s_ts - $now_ts;
        $h = floor($diff / 3600);
        $m = floor(($diff % 3600) / 60);
        $wd_estimation = "<div class='alert alert-success py-2 mb-3' style='border-radius:8px;font-size:13px'>✅ Saat ini WD <strong>DIBUKA</strong>. Akan ditutup dalam <strong>{$h} jam {$m} menit</strong>.</div>";
    }
}
?>

<div class="mb-4"><h5 class="mb-0 fw-bold">💸 Pengaturan Withdraw (WD)</h5></div>

<?php if ($flash): ?>
<div class="alert alert-<?= $flashType==='error'?'danger':'success' ?> py-2 mb-3" style="border-radius:10px;font-size:13px"><?= htmlspecialchars($flash) ?></div>
<?php endif; ?>

<div class="row g-3">
  <div class="col-md-7">
    <div class="c-card mb-3">
      <div class="c-card-header"><span class="c-card-title">⚙️ Aturan Dasar Penarikan</span></div>
      <div class="c-card-body">
        <form method="POST">
          <?= csrf_field() ?><input type="hidden" name="action" value="save_wd_settings">
          
          <div class="c-form-group mb-3">
            <label class="c-label">Status Fitur Withdraw Global</label>
            <div class="form-check ms-1">
              <input class="form-check-input" type="checkbox" name="wd_global_enabled" id="wd_global_enabled" value="1" <?= $s('wd_global_enabled','1')==='1'?'checked':'' ?>>
              <label class="form-check-label text-secondary" for="wd_global_enabled" style="font-size:13px;font-weight:700">
                Aktifkan Fitur Withdraw untuk Semua Pengguna
              </label>
            </div>
            <small style="color:#888;font-size:11px">Jika dimatikan, TIDAK ADA user yang bisa melakukan penarikan (WD maintenance total).</small>
          </div>
          
          <div class="row g-2">
            <div class="col-md-6">
              <div class="c-form-group">
                <label class="c-label">Minimum Withdraw (Rp)</label>
                <input type="number" name="min_withdraw" class="c-form-control" value="<?= htmlspecialchars($s('min_withdraw','50000')) ?>" min="0">
              </div>
            </div>
            <div class="col-md-6">
              <div class="c-form-group">
                <label class="c-label">Level Minimum WD <small style="color:#888">(0=Semua, 1=Silver+)</small></label>
                <input type="number" name="wd_min_level" class="c-form-control" value="<?= htmlspecialchars($s('wd_min_level','0')) ?>" min="0" max="10">
              </div>
            </div>
          </div>
          
          <div class="c-form-group mb-4">
            <label class="c-label">Paksa Level Minimum untuk WD</label>
            <div class="form-check ms-1">
              <input class="form-check-input" type="checkbox" name="wd_require_level" id="wd_require_level_chk" value="1" <?= $s('wd_require_level','0')==='1'?'checked':'' ?>>
              <label class="form-check-label text-secondary" for="wd_require_level_chk" style="font-size:13px">
                Aktifkan syarat level minimum sebelum bisa WD
              </label>
            </div>
            <small style="color:#888;font-size:11px">Jika dimatikan, semua user bisa WD tanpa syarat level.</small>
          </div>

          <div style="border-top:1px solid #2d3149;margin:20px 0 16px;"></div>
          
          <h6 style="font-weight:800;font-size:14px;margin-bottom:12px;color:var(--brand)">🆓 Aturan Khusus Akun Free (Gratis)</h6>
          
          <div class="c-form-group mb-2">
            <div class="form-check ms-1">
              <input class="form-check-input" type="checkbox" name="wd_free_require_1day" id="wd_free_require_1day" value="1" <?= $s('wd_free_require_1day','1')==='1'?'checked':'' ?>>
              <label class="form-check-label text-secondary" for="wd_free_require_1day" style="font-size:13px">
                Akun Free harus berumur minimal 1 hari untuk bisa WD
              </label>
            </div>
          </div>
          
          <div class="c-form-group mb-2">
            <div class="form-check ms-1">
              <input class="form-check-input" type="checkbox" name="wd_free_limit_1x" id="wd_free_limit_1x" value="1" <?= $s('wd_free_limit_1x','1')==='1'?'checked':'' ?>>
              <label class="form-check-label text-secondary" for="wd_free_limit_1x" style="font-size:13px">
                Akun Free hanya bisa melakukan WD maksimal 1 kali seumur hidup
              </label>
            </div>
          </div>
          
          <div class="c-form-group mb-4">
            <div class="form-check ms-1">
              <input class="form-check-input" type="checkbox" name="wd_free_only_dana" id="wd_free_only_dana" value="1" <?= $s('wd_free_only_dana','1')==='1'?'checked':'' ?>>
              <label class="form-check-label text-secondary" for="wd_free_only_dana" style="font-size:13px">
                Akun Free hanya bisa menarik dana ke e-wallet DANA
              </label>
            </div>
          </div>

          <div style="border-top:1px solid #2d3149;margin:20px 0 16px;"></div>
          
          <h6 style="font-weight:800;font-size:14px;margin-bottom:12px;color:var(--brand)">🔒 Jam Lock Penarikan (WD)</h6>
          
          <?= $wd_estimation ?>
          
          <!-- Hidden inputs for backend -->
          <input type="hidden" name="wd_lock_start" id="wd_lock_start" value="<?= htmlspecialchars($s('wd_lock_start')) ?>">
          <input type="hidden" name="wd_lock_end" id="wd_lock_end" value="<?= htmlspecialchars($s('wd_lock_end')) ?>">
          
          <div class="row g-2 mb-2">
            <div class="col-md-6">
              <div class="c-form-group">
                <label class="c-label">Mulai Lock</label>
                <div style="display:flex;gap:4px">
                  <select id="start_h" class="c-form-control" style="padding:4px"><option value="">--</option><?php for($i=1;$i<=12;$i++){ $v=str_pad((string)$i,2,'0',STR_PAD_LEFT); echo "<option value='$v'>$v</option>"; } ?></select>
                  <select id="start_m" class="c-form-control" style="padding:4px"><option value="">--</option><?php for($i=0;$i<60;$i++){ $v=str_pad((string)$i,2,'0',STR_PAD_LEFT); echo "<option value='$v'>$v</option>"; } ?></select>
                  <select id="start_p" class="c-form-control" style="padding:4px"><option value="">--</option><option value="AM">AM</option><option value="PM">PM</option></select>
                </div>
                <small style="color:#888">Kosongkan untuk hapus jadwal lock</small>
              </div>
            </div>
            <div class="col-md-6">
              <div class="c-form-group">
                <label class="c-label">Selesai Lock</label>
                <div style="display:flex;gap:4px">
                  <select id="end_h" class="c-form-control" style="padding:4px"><option value="">--</option><?php for($i=1;$i<=12;$i++){ $v=str_pad((string)$i,2,'0',STR_PAD_LEFT); echo "<option value='$v'>$v</option>"; } ?></select>
                  <select id="end_m" class="c-form-control" style="padding:4px"><option value="">--</option><?php for($i=0;$i<60;$i++){ $v=str_pad((string)$i,2,'0',STR_PAD_LEFT); echo "<option value='$v'>$v</option>"; } ?></select>
                  <select id="end_p" class="c-form-control" style="padding:4px"><option value="">--</option><option value="AM">AM</option><option value="PM">PM</option></select>
                </div>
              </div>
            </div>
          </div>
          
          <div class="c-form-group">
            <label class="c-label">Pesan saat WD dikunci (Jam Lock / Global Lock)</label>
            <input type="text" name="wd_lock_notice" class="c-form-control" value="<?= htmlspecialchars($s('wd_lock_notice','Penarikan hanya bisa dilakukan pada jam tertentu.')) ?>">
          </div>
          
          <button type="submit" class="btn btn-sm text-white mt-3" style="background:var(--brand)">💾 Simpan Pengaturan WD</button>
        </form>
      </div>
    </div>
  </div>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>
<script>
// Logic to populate and sync AM/PM select clock for WD lock
function parseTime(val) {
  if(!val) return {h:'', m:'', p:''};
  let parts = val.split(':');
  if(parts.length < 2) return {h:'', m:'', p:''};
  let h = parseInt(parts[0], 10);
  let m = parts[1];
  let p = h >= 12 ? 'PM' : 'AM';
  h = h % 12;
  if(h === 0) h = 12;
  return { h: h.toString().padStart(2, '0'), m: m.padStart(2, '0'), p: p };
}

function initTimeSelects(id) {
  let val = document.getElementById(id).value;
  let parsed = parseTime(val);
  let pfx = id === 'wd_lock_start' ? 'start_' : 'end_';
  document.getElementById(pfx+'h').value = parsed.h;
  document.getElementById(pfx+'m').value = parsed.m;
  document.getElementById(pfx+'p').value = parsed.p;
}

function syncTime(id) {
  let pfx = id === 'wd_lock_start' ? 'start_' : 'end_';
  let h = document.getElementById(pfx+'h').value;
  let m = document.getElementById(pfx+'m').value;
  let p = document.getElementById(pfx+'p').value;
  
  if(!h || !m || !p) {
    document.getElementById(id).value = '';
    return;
  }
  
  h = parseInt(h, 10);
  if(p === 'PM' && h < 12) h += 12;
  if(p === 'AM' && h === 12) h = 0;
  
  document.getElementById(id).value = h.toString().padStart(2, '0') + ':' + m;
}

initTimeSelects('wd_lock_start');
initTimeSelects('wd_lock_end');

['start_h','start_m','start_p'].forEach(x => document.getElementById(x).addEventListener('change', () => syncTime('wd_lock_start')));
['end_h','end_m','end_p'].forEach(x => document.getElementById(x).addEventListener('change', () => syncTime('wd_lock_end')));
</script>
