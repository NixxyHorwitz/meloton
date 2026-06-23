<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';

// Check RBAC permission (which automatically handles head admin check as well)
staff_require('staff');

$flash = $flashType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_enforce();
    $action = $_POST['action'] ?? '';
    $id     = (int)($_POST['id'] ?? 0);

    if ($action === 'add') {
        $uname  = strtolower(trim($_POST['username'] ?? ''));
        $dname  = trim($_POST['display_name'] ?? '');
        $pwd    = $_POST['password'] ?? '';
        $role   = (int)($_POST['role_id'] ?? 0);
        $active = isset($_POST['is_active']) ? 1 : 0;

        if (!$uname || !$dname || strlen($pwd) < 6) {
            $flash = 'Username, nama, dan password (min. 6 karakter) wajib diisi.'; $flashType = 'error';
        } else {
            $ex = $pdo->prepare("SELECT id FROM staff WHERE username=?"); $ex->execute([$uname]);
            if ($ex->fetch()) { $flash = 'Username sudah digunakan.'; $flashType = 'error'; }
            else {
                $pdo->prepare("INSERT INTO staff (username,display_name,password_hash,role_id,is_active) VALUES (?,?,?,?,?)")
                    ->execute([$uname, $dname, password_hash($pwd, PASSWORD_BCRYPT), $role ?: null, $active]);
                $flash = "Staff {$uname} ditambahkan.";
            }
        }
    }

    if ($action === 'edit' && $id) {
        $dname  = trim($_POST['display_name'] ?? '');
        $role   = (int)($_POST['role_id'] ?? 0);
        $active = isset($_POST['is_active']) ? 1 : 0;
        $pwd    = $_POST['password'] ?? '';

        $pdo->prepare("UPDATE staff SET display_name=?, role_id=?, is_active=? WHERE id=?")
            ->execute([$dname, $role ?: null, $active, $id]);
        if (strlen($pwd) >= 6) {
            $pdo->prepare("UPDATE staff SET password_hash=? WHERE id=?")->execute([password_hash($pwd, PASSWORD_BCRYPT), $id]);
        }
        // Flush staff permissions cache if they're logged in
        $flash = "Staff berhasil diperbarui.";
    }

    if ($action === 'delete' && $id) {
        $pdo->prepare("DELETE FROM staff WHERE id=?")->execute([$id]);
        $flash = 'Staff dihapus.';
    }
}

$staffList = $pdo->query("
    SELECT s.*, r.name as role_name
    FROM staff s
    LEFT JOIN staff_roles r ON r.id = s.role_id
    ORDER BY s.created_at DESC
")->fetchAll();

$roles = $pdo->query("SELECT * FROM staff_roles ORDER BY name ASC")->fetchAll();

$pageTitle  = 'Kelola Staff';
$activePage = 'staff';
require __DIR__ . '/partials/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-4">
  <div><h5 class="mb-0 fw-bold">👥 Kelola Staff</h5>
  <div style="font-size:12px;color:#666;margin-top:2px">Buat dan atur akun staff dengan role masing-masing</div></div>
  <div class="d-flex gap-2">
    <a href="/console/staff_roles.php" class="btn btn-sm b-neutral" style="border:1px solid #333;border-radius:8px;font-size:12px">🎭 Kelola Role</a>
    <button class="btn btn-sm text-white" style="background:var(--brand)" data-bs-toggle="modal" data-bs-target="#addStaffModal">+ Tambah Staff</button>
  </div>
</div>

<?php if ($flash): ?>
<div class="alert alert-<?= $flashType==='error'?'danger':'success' ?> py-2 mb-3" style="border-radius:10px;font-size:13px"><?= htmlspecialchars($flash) ?></div>
<?php endif; ?>

<?php if (empty($roles)): ?>
<div class="alert" style="background:rgba(255,193,7,.1);border:1px solid rgba(255,193,7,.3);border-radius:10px;font-size:13px;margin-bottom:16px">
  ⚠️ Belum ada role yang dibuat. <a href="/console/staff_roles.php" style="color:#FFC107;font-weight:700">Buat role dulu →</a>
</div>
<?php endif; ?>

<div class="c-card">
  <div class="c-card-body" style="padding:0">
    <table class="c-table">
      <thead>
        <tr>
          <th>Staff</th>
          <th>Role</th>
          <th>Status</th>
          <th>Last Login</th>
          <th>Dibuat</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($staffList as $s): ?>
        <tr>
          <td>
            <div style="font-weight:700"><?= htmlspecialchars($s['display_name']) ?></div>
            <div style="font-size:11px;color:#555">@<?= htmlspecialchars($s['username']) ?></div>
          </td>
          <td>
            <?php if ($s['role_name']): ?>
            <span style="font-size:11px;padding:3px 8px;background:rgba(255,107,53,.15);color:#FF6B35;border-radius:99px;font-weight:700"><?= htmlspecialchars($s['role_name']) ?></span>
            <?php else: ?>
            <span style="font-size:11px;color:#555;font-style:italic">Tanpa role</span>
            <?php endif; ?>
          </td>
          <td>
            <?php if ($s['is_active']): ?>
            <span class="badge b-success" style="padding:4px 8px;border-radius:6px;font-size:11px">Aktif</span>
            <?php else: ?>
            <span class="badge b-danger" style="padding:4px 8px;border-radius:6px;font-size:11px">Nonaktif</span>
            <?php endif; ?>
          </td>
          <td style="font-size:12px;color:#666"><?= $s['last_login'] ? date('d M Y H:i', strtotime($s['last_login'])) : '—' ?></td>
          <td style="font-size:12px;color:#666"><?= date('d M Y', strtotime($s['created_at'])) ?></td>
          <td>
            <div class="d-flex gap-1 justify-content-end">
              <button class="btn btn-sm b-neutral" style="border:none;border-radius:6px;font-size:11px"
                onclick='editStaff(<?= json_encode(['id'=>$s['id'],'username'=>$s['username'],'display_name'=>$s['display_name'],'role_id'=>$s['role_id']??0,'is_active'=>$s['is_active']]) ?>)'>✏️ Edit</button>
              <form method="POST" class="d-inline" onsubmit="return confirm('Hapus staff ini?')">
                <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $s['id'] ?>">
                <button class="btn btn-sm b-danger" style="border:none;border-radius:6px;font-size:11px">🗑</button>
              </form>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($staffList)): ?>
        <tr><td colspan="6" style="text-align:center;padding:40px;color:#555">Belum ada staff. Tambah staff pertama!</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Add Modal -->
<div class="modal fade" id="addStaffModal" tabindex="-1">
  <div class="modal-dialog"><div class="modal-content" style="background:#1a1d27;border:1px solid #2d3149">
    <form method="POST">
    <?= csrf_field() ?><input type="hidden" name="action" value="add">
    <div class="modal-header border-0"><h5 class="modal-title fw-bold">+ Tambah Staff</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
      <div class="c-form-group"><label class="c-label">Username</label><input type="text" name="username" class="c-form-control" placeholder="username_staff" required></div>
      <div class="c-form-group"><label class="c-label">Nama Tampil</label><input type="text" name="display_name" class="c-form-control" placeholder="Nama Lengkap Staff" required></div>
      <div class="c-form-group"><label class="c-label">Password <span style="color:#666">(min. 6 karakter)</span></label><input type="password" name="password" class="c-form-control" required></div>
      <div class="c-form-group"><label class="c-label">Role</label>
        <select name="role_id" class="c-form-control">
          <option value="">— Tanpa Role —</option>
          <?php foreach ($roles as $rl): ?>
          <option value="<?= $rl['id'] ?>"><?= htmlspecialchars($rl['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-check ms-1">
        <input class="form-check-input" type="checkbox" name="is_active" id="add-active" checked>
        <label class="form-check-label text-secondary" for="add-active" style="font-size:13px">Aktif</label>
      </div>
    </div>
    <div class="modal-footer border-0">
      <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Batal</button>
      <button type="submit" class="btn btn-sm text-white" style="background:var(--brand)">Simpan</button>
    </div>
    </form>
  </div></div>
</div>

<!-- Edit Modal -->
<div class="modal fade" id="editStaffModal" tabindex="-1">
  <div class="modal-dialog"><div class="modal-content" style="background:#1a1d27;border:1px solid #2d3149">
    <form method="POST">
    <?= csrf_field() ?><input type="hidden" name="action" value="edit"><input type="hidden" name="id" id="es-id">
    <div class="modal-header border-0"><h5 class="modal-title fw-bold">✏️ Edit Staff</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
    <div class="modal-body" id="es-body"></div>
    <div class="modal-footer border-0">
      <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Batal</button>
      <button type="submit" class="btn btn-sm text-white" style="background:var(--brand)">Update</button>
    </div>
    </form>
  </div></div>
</div>

<script>
const ROLES = <?= json_encode(array_map(fn($r)=>['id'=>$r['id'],'name'=>$r['name']], $roles)) ?>;

function editStaff(s) {
  document.getElementById('es-id').value = s.id;
  let roleOpts = '<option value="">— Tanpa Role —</option>';
  ROLES.forEach(r => {
    roleOpts += `<option value="${r.id}" ${s.role_id == r.id ? 'selected' : ''}>${escH(r.name)}</option>`;
  });
  document.getElementById('es-body').innerHTML = `
    <div class="c-form-group"><label class="c-label">Username</label><input type="text" class="c-form-control" value="${escH(s.username)}" disabled readonly style="opacity:.5"></div>
    <div class="c-form-group"><label class="c-label">Nama Tampil</label><input type="text" name="display_name" class="c-form-control" value="${escH(s.display_name)}" required></div>
    <div class="c-form-group"><label class="c-label">Password Baru <span style="color:#666">(kosongkan jika tidak diubah)</span></label><input type="password" name="password" class="c-form-control"></div>
    <div class="c-form-group"><label class="c-label">Role</label><select name="role_id" class="c-form-control">${roleOpts}</select></div>
    <div class="form-check ms-1"><input class="form-check-input" type="checkbox" name="is_active" id="es-active" ${s.is_active ? 'checked' : ''}>
      <label class="form-check-label text-secondary" for="es-active" style="font-size:13px">Aktif</label></div>`;
  new bootstrap.Modal(document.getElementById('editStaffModal')).show();
}
function escH(s){return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}
</script>

<?php require __DIR__ . '/partials/footer.php'; ?>
