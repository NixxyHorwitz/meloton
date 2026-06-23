<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
staff_require('payment');

$flash = $flashType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_enforce();
    $action = $_POST['action'] ?? '';
    $id     = (int)($_POST['id'] ?? 0);

    if ($action === 'add') {
        $name  = trim($_POST['name'] ?? '');
        $type  = $_POST['type'] === 'ewallet' ? 'ewallet' : 'bank';
        $order = (int)($_POST['sort_order'] ?? 0);
        $logo  = null;
        if (!empty($_FILES['logo']['tmp_name'])) {
            $ext = strtolower(pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['png','jpg','jpeg','webp'])) {
                $logo = time() . '_' . rand(100,999) . '.' . $ext;
                move_uploaded_file($_FILES['logo']['tmp_name'], dirname(__DIR__) . '/assets/banks/' . $logo);
            }
        }

        if (!$name) { $flash = 'Nama wajib diisi.'; $flashType = 'error'; }
        else {
            $pdo->prepare("INSERT INTO payment_channels (name, type, sort_order, is_active, logo) VALUES (?,?,?,1,?)")->execute([$name, $type, $order, $logo]);
            $flash = "{$name} ditambahkan.";
        }
    }
    if ($action === 'edit' && $id) {
        $name  = trim($_POST['name'] ?? '');
        $type  = $_POST['type'] === 'ewallet' ? 'ewallet' : 'bank';
        $order = (int)($_POST['sort_order'] ?? 0);
        $active= isset($_POST['is_active']) ? 1 : 0;
        
        $logo = null;
        if (!empty($_FILES['logo']['tmp_name'])) {
            $ext = strtolower(pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['png','jpg','jpeg','webp'])) {
                $logo = time() . '_' . rand(100,999) . '.' . $ext;
                move_uploaded_file($_FILES['logo']['tmp_name'], dirname(__DIR__) . '/assets/banks/' . $logo);
            }
        }
        
        if ($logo) {
            $pdo->prepare("UPDATE payment_channels SET name=?, type=?, sort_order=?, is_active=?, logo=? WHERE id=?")->execute([$name, $type, $order, $active, $logo, $id]);
        } else {
            $pdo->prepare("UPDATE payment_channels SET name=?, type=?, sort_order=?, is_active=? WHERE id=?")->execute([$name, $type, $order, $active, $id]);
        }
        $flash = "Berhasil diperbarui.";
    }
    if ($action === 'delete' && $id) {
        $pdo->prepare("DELETE FROM payment_channels WHERE id=?")->execute([$id]);
        $flash = 'Dihapus.';
    }
    if ($action === 'toggle' && $id) {
        $pdo->prepare("UPDATE payment_channels SET is_active = 1 - is_active WHERE id=?")->execute([$id]);
    }
}

$channels = $pdo->query("SELECT * FROM payment_channels ORDER BY type ASC, sort_order ASC, name ASC")->fetchAll();

$pageTitle  = 'Bank & E-Wallet';
$activePage = 'payment';
require __DIR__ . '/partials/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-4">
  <div>
    <h5 class="mb-0 fw-bold">🏦 Bank &amp; E-Wallet</h5>
    <div style="font-size:12px;color:#666;margin-top:2px">Kelola daftar bank dan e-wallet yang bisa dipilih pengguna</div>
  </div>
  <button class="btn btn-sm text-white" style="background:var(--brand)" data-bs-toggle="modal" data-bs-target="#addModal">+ Tambah</button>
</div>

<?php if ($flash): ?>
<div class="alert alert-<?= $flashType==='error'?'danger':'success' ?> py-2 mb-3" style="border-radius:10px;font-size:13px"><?= htmlspecialchars($flash) ?></div>
<?php endif; ?>

<!-- Banks -->
<?php foreach (['bank' => '🏦 Bank', 'ewallet' => '📱 E-Wallet'] as $type => $label): ?>
<?php $group = array_filter($channels, fn($c) => $c['type'] === $type); ?>
<?php if (!empty($group)): ?>
<div class="mb-2" style="font-size:11px;font-weight:700;color:#555;letter-spacing:.5px;text-transform:uppercase"><?= $label ?></div>
<div class="c-card mb-4">
  <div class="c-card-body" style="padding:0">
    <table class="c-table">
      <thead>
        <tr>
          <th>Nama</th>
          <th>Urutan</th>
          <th>Status</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($group as $c): ?>
        <tr>
          <td style="font-weight:700">
            <?php if (!empty($c['logo'])): ?>
            <img src="/assets/banks/<?= htmlspecialchars($c['logo']) ?>" style="height:20px;width:auto;border-radius:4px;margin-right:6px;vertical-align:middle;object-fit:contain">
            <?php endif; ?>
            <?= htmlspecialchars($c['name']) ?>
          </td>
          <td style="color:#666"><?= $c['sort_order'] ?></td>
          <td>
            <form method="POST" class="d-inline">
              <?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= $c['id'] ?>">
              <button type="submit" class="badge <?= $c['is_active']?'b-success':'b-neutral' ?>" style="border:none;cursor:pointer;padding:4px 10px;border-radius:6px;font-size:11px;font-weight:700">
                <?= $c['is_active'] ? 'Aktif' : 'Nonaktif' ?>
              </button>
            </form>
          </td>
          <td>
            <div class="d-flex gap-1 justify-content-end">
              <button class="btn btn-sm b-neutral" style="border:none;border-radius:6px;font-size:11px"
                onclick='editChannel(<?= json_encode(['id'=>$c['id'],'name'=>$c['name'],'type'=>$c['type'],'sort_order'=>$c['sort_order'],'is_active'=>$c['is_active']]) ?>)'>✏️</button>
              <form method="POST" class="d-inline" onsubmit="return confirm('Hapus?')">
                <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $c['id'] ?>">
                <button class="btn btn-sm b-danger" style="border:none;border-radius:6px;font-size:11px">🗑</button>
              </form>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; endforeach; ?>

<!-- Add Modal -->
<div class="modal fade" id="addModal" tabindex="-1">
  <div class="modal-dialog"><div class="modal-content" style="background:#1a1d27;border:1px solid #2d3149">
    <form method="POST" enctype="multipart/form-data">
    <?= csrf_field() ?><input type="hidden" name="action" value="add">
    <div class="modal-header border-0"><h5 class="modal-title fw-bold">+ Tambah</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
      <div class="c-form-group"><label class="c-label">Nama</label><input type="text" name="name" class="c-form-control" placeholder="BCA, GoPay, dll" required></div>
      <div class="c-form-group"><label class="c-label">Tipe</label>
        <select name="type" class="c-form-control">
          <option value="bank">🏦 Bank</option>
          <option value="ewallet">📱 E-Wallet</option>
        </select>
      </div>
      <div class="c-form-group"><label class="c-label">Urutan Tampil</label><input type="number" name="sort_order" class="c-form-control" value="0" min="0"></div>
      <div class="c-form-group"><label class="c-label">Logo (Opsional)</label><input type="file" name="logo" class="c-form-control" accept="image/*"></div>
    </div>
    <div class="modal-footer border-0">
      <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Batal</button>
      <button type="submit" class="btn btn-sm text-white" style="background:var(--brand)">Simpan</button>
    </div>
    </form>
  </div></div>
</div>

<!-- Edit Modal -->
<div class="modal fade" id="editModal" tabindex="-1">
  <div class="modal-dialog"><div class="modal-content" style="background:#1a1d27;border:1px solid #2d3149">
    <form method="POST" enctype="multipart/form-data">
    <?= csrf_field() ?><input type="hidden" name="action" value="edit"><input type="hidden" name="id" id="em-id">
    <div class="modal-header border-0"><h5 class="modal-title fw-bold">✏️ Edit</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
    <div class="modal-body" id="em-body"></div>
    <div class="modal-footer border-0">
      <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Batal</button>
      <button type="submit" class="btn btn-sm text-white" style="background:var(--brand)">Update</button>
    </div>
    </form>
  </div></div>
</div>

<script>
function editChannel(c) {
  document.getElementById('em-id').value = c.id;
  document.getElementById('em-body').innerHTML = `
    <div class="c-form-group"><label class="c-label">Nama</label><input type="text" name="name" class="c-form-control" value="${escH(c.name)}" required></div>
    <div class="c-form-group"><label class="c-label">Tipe</label>
      <select name="type" class="c-form-control">
        <option value="bank" ${c.type==='bank'?'selected':''}>🏦 Bank</option>
        <option value="ewallet" ${c.type==='ewallet'?'selected':''}>📱 E-Wallet</option>
      </select>
    </div>
    <div class="c-form-group"><label class="c-label">Urutan Tampil</label><input type="number" name="sort_order" class="c-form-control" value="${c.sort_order}" min="0"></div>
    <div class="c-form-group"><label class="c-label">Ganti Logo (Opsional)</label><input type="file" name="logo" class="c-form-control" accept="image/*"></div>
    <div class="form-check ms-1"><input class="form-check-input" type="checkbox" name="is_active" id="em-active" ${c.is_active?'checked':''}>
      <label class="form-check-label text-secondary" for="em-active" style="font-size:13px">Aktif</label></div>`;
  new bootstrap.Modal(document.getElementById('editModal')).show();
}
function escH(s){return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}
</script>

<?php require __DIR__ . '/partials/footer.php'; ?>
