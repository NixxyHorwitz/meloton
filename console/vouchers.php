<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
staff_require('vouchers');

$flash = $flashType = '';

// Handle Delete
if (isset($_POST['delete_id'])) {
    $del = (int)$_POST['delete_id'];
    $pdo->prepare("DELETE FROM discount_vouchers WHERE id=?")->execute([$del]);
    $flash = "Voucher diskon berhasil dihapus!";
    $flashType = "success";
}

// Handle Add
if (isset($_POST['add_voucher'])) {
    $code   = strtoupper(trim($_POST['code'] ?? ''));
    $quota  = (int)($_POST['quota'] ?? 0);
    $expiry = trim($_POST['expiry'] ?? '');
    $type   = $_POST['discount_type'] ?? 'pct';
    
    // Parse discounts
    $discounts = [];
    foreach ($_POST['discount_level'] ?? [] as $lvl_id => $val) {
        $val = (float)$val;
        if ($val > 0) {
            if ($type === 'pct' && $val > 100) $val = 100;
            $discounts[(int)$lvl_id] = $type === 'rp' ? $val . 'rp' : $val;
        }
    }
    
    if (!$code) {
        $flash = "Kode voucher tidak boleh kosong!";
        $flashType = "danger";
    } elseif (empty($discounts)) {
        $flash = "Minimal satu level harus memiliki diskon > 0!";
        $flashType = "danger";
    } else {
        $exp_date = null;
        if ($expiry) {
            $exp_date = date('Y-m-d H:i:s', strtotime($expiry));
        }
        $discounts_json = json_encode($discounts);
        
        try {
            $pdo->prepare("INSERT INTO discount_vouchers (code, discounts, max_claims, expires_at) VALUES (?, ?, ?, ?)")
                ->execute([$code, $discounts_json, $quota, $exp_date]);
            $flash = "Voucher diskon berhasil ditambahkan!";
            $flashType = "success";
        } catch (\PDOException $e) {
            if ($e->getCode() == 23000) {
                $flash = "Kode voucher '$code' sudah ada!";
            } else {
                $flash = "Terjadi kesalahan database.";
            }
            $flashType = "danger";
        }
    }
}

// Fetch memberships for dropdown & lookup
$m_stmt = $pdo->query("SELECT id, name, price FROM memberships WHERE is_active=1 ORDER BY sort_order ASC");
$memberships = $m_stmt->fetchAll(PDO::FETCH_ASSOC);
$mem_map = [];
foreach ($memberships as $m) {
    $mem_map[$m['id']] = $m['name'];
}

$vouchers = $pdo->query("SELECT * FROM discount_vouchers ORDER BY created_at DESC")->fetchAll();

$pageTitle  = 'Voucher Diskon';
$activePage = 'vouchers';
require __DIR__ . '/partials/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
  <h4 class="mb-0 text-white" style="font-weight:700">Manajemen Voucher Diskon</h4>
  <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addModal">+ Tambah Voucher</button>
</div>

<?php if ($flash): ?>
<div class="alert alert-<?= $flashType ?>"><?= htmlspecialchars($flash) ?></div>
<?php endif; ?>

<div class="c-card">
  <div class="c-card-body p-0">
    <div class="table-responsive">
      <table class="c-table">
        <thead>
          <tr>
            <th>Kode Voucher</th>
            <th>Rincian Diskon</th>
            <th>Klaim / Kuota</th>
            <th>Kedaluwarsa</th>
            <th>Dibuat</th>
            <th>Aksi</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($vouchers as $v): ?>
          <?php 
            $is_expired = $v['expires_at'] && strtotime($v['expires_at']) < time();
            $is_depleted = $v['max_claims'] > 0 && $v['claims_count'] >= $v['max_claims'];
            $status = 'Aktif';
            $badge = 'success';
            if ($is_expired) { $status = 'Expired'; $badge = 'danger'; }
            elseif ($is_depleted) { $status = 'Habis'; $badge = 'warning text-dark'; }
            
            $disc_list = json_decode($v['discounts'], true) ?: [];
            $disc_desc = [];
            foreach ($disc_list as $lvl_id => $val) {
                $lvl_name = $lvl_id === '*' ? 'Semua Paket' : ($mem_map[$lvl_id] ?? "Level #$lvl_id");
                
                $is_rp = is_string($val) && stripos((string)$val, 'rp') !== false;
                if (!$is_rp && is_numeric($val) && $val > 100) { $is_rp = true; } // legacy
                
                if ($is_rp) {
                    $amt = (float)str_ireplace('rp', '', (string)$val);
                    $disc_desc[] = '<span style="color:#FFC107">' . htmlspecialchars($lvl_name) . ': Diskon <strong>Rp ' . number_format($amt, 0, ',', '.') . '</strong></span>';
                } else {
                    $disc_desc[] = '<span style="color:#FFC107">' . htmlspecialchars($lvl_name) . ': Diskon <strong>' . $val . '%</strong></span>';
                }
            }
          ?>
          <tr>
            <td>
              <strong style="font-family:monospace;font-size:14px;letter-spacing:1px;color:var(--brand)"><?= htmlspecialchars($v['code']) ?></strong><br>
              <span class="badge bg-<?= $badge ?>" style="font-size:10px"><?= $status ?></span>
            </td>
            <td style="font-size:12px;font-weight:600;line-height:1.5">
              <?= !empty($disc_desc) ? implode('<br>', $disc_desc) : '-' ?>
            </td>
            <td>
              <?= $v['claims_count'] ?> / <?= $v['max_claims'] > 0 ? $v['max_claims'] : '∞' ?>
            </td>
            <td style="color:#aaa;font-size:12px">
              <?= $v['expires_at'] ? date('d M Y H:i', strtotime($v['expires_at'])) : 'Tanpa batas' ?>
            </td>
            <td style="color:#888;font-size:12px"><?= date('d M Y', strtotime($v['created_at'])) ?></td>
            <td>
              <form method="POST" onsubmit="return confirm('Hapus voucher ini?');" style="display:inline-block">
                <input type="hidden" name="delete_id" value="<?= $v['id'] ?>">
                <button type="submit" class="btn btn-sm btn-danger py-1 px-2" style="font-size:11px">Hapus</button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if (empty($vouchers)): ?>
          <tr><td colspan="6" class="text-center py-4 text-muted">Belum ada voucher diskon</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Modal Add -->
<div class="modal fade" id="addModal" tabindex="-1">
  <div class="modal-dialog">
    <form class="modal-content" style="background:#131520;border:1px solid #1f2235" method="POST">
      <div class="modal-header border-bottom-0 pb-0">
        <h5 class="modal-title text-white fw-bold">Tambah Voucher Diskon</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="add_voucher" value="1">
        
        <div class="mb-3">
          <label class="c-label">Kode Voucher <span class="text-danger">*</span></label>
          <input type="text" name="code" class="c-form-control" required style="text-transform:uppercase" placeholder="Contoh: HEMAT20">
        </div>
        
        <div class="mb-3">
          <label class="c-label">Tipe Diskon <span class="text-danger">*</span></label>
          <select name="discount_type" id="discount_type" class="c-form-control mb-3">
            <option value="pct">Persentase (%)</option>
            <option value="rp">Nominal (Rp)</option>
          </select>
        </div>

        <div class="mb-3">
          <label class="c-label mb-2">Nilai Diskon Per Level <span class="text-danger">*</span></label>
          <div class="p-3 rounded" style="background:#0f1117;border:1px solid #1f2235">
            <?php foreach ($memberships as $m): ?>
            <?php if ((float)$m['price'] == 0) continue; // skip Free plan ?>
            <div class="mb-3 row align-items-center">
              <label class="col-sm-7 text-white-50 mb-0" style="font-size:12.5px;font-weight:600">
                <?= htmlspecialchars($m['name']) ?> 
                <span class="d-block text-muted" style="font-size:11px;font-weight:400">Harga: <?= format_rp((float)$m['price']) ?></span>
                <span class="d-block text-success mt-1" id="calc_<?= $m['id'] ?>" style="font-size:11px;font-weight:700;display:none;"></span>
              </label>
              <div class="col-sm-5">
                <div class="input-group input-group-sm">
                  <span class="input-group-text bg-dark border-secondary text-white unit-prefix" style="font-size:11px; display:none;">Rp</span>
                  <input type="number" name="discount_level[<?= $m['id'] ?>]" data-price="<?= (float)$m['price'] ?>" data-target="calc_<?= $m['id'] ?>" class="c-form-control py-1 px-2 disc-input" min="0" placeholder="0" value="0" style="text-align:right">
                  <span class="input-group-text bg-dark border-secondary text-white unit-suffix" style="font-size:11px">%</span>
                </div>
              </div>
            </div>
            <?php endforeach; ?>
            <small class="text-muted d-block" style="font-size:11px;line-height:1.4">Isi nilai diskon pada paket yang diinginkan. Biarkan 0 jika level tersebut tidak didiskon.</small>
          </div>
        </div>
        
        <div class="mb-3">
          <label class="c-label">Batas Kuota Klaim</label>
          <input type="number" name="quota" class="c-form-control" min="0" placeholder="0 = Tanpa batas kuota">
          <small class="text-muted" style="font-size:11px">Biarkan kosong atau isi 0 jika tidak ada batasan kuota.</small>
        </div>
        
        <div class="mb-3">
          <label class="c-label">Tanggal Kedaluwarsa</label>
          <input type="datetime-local" name="expiry" class="c-form-control">
          <small class="text-muted" style="font-size:11px">Biarkan kosong jika voucher tidak memiliki batas waktu.</small>
        </div>
      </div>
      <div class="modal-footer border-top-0 pt-0">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
        <button type="submit" class="btn btn-primary">Simpan Voucher</button>
      </div>
    </form>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const typeSelect = document.getElementById('discount_type');
    
    function updatePreviews() {
        let type = typeSelect ? typeSelect.value : 'pct';
        document.querySelectorAll('.disc-input').forEach(input => {
            let val = parseFloat(input.value) || 0;
            let price = parseFloat(input.getAttribute('data-price')) || 0;
            let targetEl = document.getElementById(input.getAttribute('data-target'));
            let prefix = input.parentElement.querySelector('.unit-prefix');
            let suffix = input.parentElement.querySelector('.unit-suffix');
            
            if (type === 'pct') {
                if (val > 100) { val = 100; input.value = 100; }
                if (prefix) prefix.style.display = 'none';
                if (suffix) { suffix.style.display = 'block'; suffix.innerText = '%'; }
            } else {
                if (prefix) prefix.style.display = 'block';
                if (suffix) suffix.style.display = 'none';
            }
            
            if (val > 0) {
                let discAmt = type === 'pct' ? (price * (val / 100)) : val;
                let finalPrice = price - discAmt;
                if (finalPrice < 0) finalPrice = 0;
                
                let formatRp = (num) => 'Rp ' + Math.round(num).toLocaleString('id-ID');
                
                targetEl.innerHTML = `Diskon: -${formatRp(discAmt)} <br> Jadi: <span style="color:#fff">${formatRp(finalPrice)}</span>`;
                targetEl.style.display = 'block';
            } else {
                targetEl.style.display = 'none';
            }
        });
    }
    
    if (typeSelect) typeSelect.addEventListener('change', updatePreviews);
    document.querySelectorAll('.disc-input').forEach(input => {
        input.addEventListener('input', updatePreviews);
    });
});
</script>
<?php require __DIR__ . '/partials/footer.php'; ?>
