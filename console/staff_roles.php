<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';

// Check RBAC permission (which automatically handles head admin check as well)
staff_require('staff_roles');

$flash = $flashType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_enforce();
    $action = $_POST['action'] ?? '';
    $id     = (int)($_POST['id'] ?? 0);

    if ($action === 'add' || $action === 'edit') {
        $name  = trim($_POST['name'] ?? '');
        $desc  = trim($_POST['description'] ?? '');
        $perms = (array)($_POST['permissions'] ?? []);
        $validPerms = array_keys(STAFF_PERMISSIONS);
        $perms = array_filter($perms, fn($p) => in_array($p, $validPerms, true));

        if (!$name) { $flash = 'Nama role wajib diisi.'; $flashType = 'error'; }
        else {
            if ($action === 'add') {
                $pdo->prepare("INSERT INTO staff_roles (name, description) VALUES (?,?)")->execute([$name, $desc]);
                $rid = (int)$pdo->lastInsertId();
            } else {
                $pdo->prepare("UPDATE staff_roles SET name=?, description=? WHERE id=?")->execute([$name, $desc, $id]);
                $pdo->prepare("DELETE FROM staff_role_permissions WHERE role_id=?")->execute([$id]);
                $rid = $id;
            }
            $ins = $pdo->prepare("INSERT IGNORE INTO staff_role_permissions (role_id, permission) VALUES (?,?)");
            foreach ($perms as $p) { $ins->execute([$rid, $p]); }
            $flash = $action === 'add' ? "Role {$name} ditambahkan." : "Role berhasil diperbarui.";
        }
    }
    if ($action === 'delete' && $id) {
        $pdo->prepare("DELETE FROM staff_roles WHERE id=?")->execute([$id]);
        $flash = 'Role dihapus.';
    }
}

$roles = $pdo->query("SELECT r.*, COUNT(s.id) as staff_count FROM staff_roles r LEFT JOIN staff s ON s.role_id = r.id GROUP BY r.id ORDER BY r.created_at DESC")->fetchAll();

// Load permissions per role
$rolePerms = [];
foreach ($pdo->query("SELECT role_id, permission FROM staff_role_permissions")->fetchAll() as $rp) {
    $rolePerms[$rp['role_id']][] = $rp['permission'];
}

$pageTitle  = 'Manajemen Role';
$activePage = 'staff';
require __DIR__ . '/partials/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-4">
  <div><h5 class="mb-0 fw-bold">🎭 Manajemen Role Staff</h5>
  <div style="font-size:12px;color:#666;margin-top:2px">Buat role dan atur hak akses menu per role</div></div>
  <div class="d-flex gap-2">
    <a href="/console/staff.php" class="btn btn-sm b-neutral" style="border:1px solid #333;border-radius:8px;font-size:12px">👥 Kelola Staff</a>
    <button class="btn btn-sm text-white" style="background:var(--brand)" data-bs-toggle="modal" data-bs-target="#addRoleModal">+ Tambah Role</button>
  </div>
</div>

<?php if ($flash): ?>
<div class="alert alert-<?= $flashType==='error'?'danger':'success' ?> py-2 mb-3" style="border-radius:10px;font-size:13px"><?= htmlspecialchars($flash) ?></div>
<?php endif; ?>

<div class="row g-3">
<?php foreach ($roles as $r): ?>
  <?php $rp = $rolePerms[$r['id']] ?? []; ?>
  <div class="col-md-6">
    <div class="c-card">
      <div class="c-card-body">
        <div class="d-flex justify-content-between align-items-start mb-3">
          <div>
            <div style="font-size:15px;font-weight:800"><?= htmlspecialchars($r['name']) ?></div>
            <?php if ($r['description']): ?>
            <div style="font-size:12px;color:#666;margin-top:2px"><?= htmlspecialchars($r['description']) ?></div>
            <?php endif; ?>
            <div style="font-size:11px;color:#555;margin-top:4px">👥 <?= $r['staff_count'] ?> staff menggunakan role ini</div>
          </div>
          <div class="d-flex gap-1">
            <button class="btn btn-sm b-neutral" style="border:none;border-radius:8px;font-size:11px"
              onclick='editRole(<?= json_encode(['id'=>$r['id'],'name'=>$r['name'],'description'=>$r['description']??'','permissions'=>$rp]) ?>)'>✏️</button>
            <?php if ($r['staff_count'] == 0): ?>
            <form method="POST" class="d-inline" onsubmit="return confirm('Hapus role ini?')">
              <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $r['id'] ?>">
              <button class="btn btn-sm b-danger" style="border:none;border-radius:8px;font-size:11px">🗑</button>
            </form>
            <?php endif; ?>
          </div>
        </div>
        <!-- Permission chips -->
        <div style="display:flex;flex-wrap:wrap;gap:5px">
          <?php if (empty($rp)): ?>
          <span style="font-size:11px;color:#555;font-style:italic">Tidak ada permission</span>
          <?php else: foreach ($rp as $pk): ?>
          <span style="font-size:10px;padding:2px 8px;background:rgba(255,107,53,.15);color:#FF6B35;border-radius:99px;font-weight:700">
            <?= htmlspecialchars(STAFF_PERMISSIONS[$pk] ?? $pk) ?>
          </span>
          <?php endforeach; endif; ?>
        </div>
      </div>
    </div>
  </div>
<?php endforeach; ?>
<?php if (empty($roles)): ?>
<div class="col-12"><div class="c-card"><div class="c-card-body text-center" style="padding:40px;color:#555">
  Belum ada role. Buat role pertama!
</div></div></div>
<?php endif; ?>
</div>

<!-- Add Modal -->
<div class="modal fade" id="addRoleModal" tabindex="-1">
  <div class="modal-dialog modal-lg"><div class="modal-content" style="background:#1a1d27;border:1px solid #2d3149">
    <form method="POST">
    <?= csrf_field() ?><input type="hidden" name="action" value="add">
    <div class="modal-header border-0"><h5 class="modal-title fw-bold">+ Tambah Role</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
      <div class="row g-3 mb-3">
        <div class="col-6"><label class="c-label">Nama Role</label><input type="text" name="name" class="c-form-control" placeholder="Contoh: Customer Service" required></div>
        <div class="col-6"><label class="c-label">Deskripsi</label><input type="text" name="description" class="c-form-control" placeholder="Opsional"></div>
      </div>
      <label class="c-label mb-2">🔑 Hak Akses (Permission)</label>
      <div class="row g-2">
        <?php foreach (STAFF_PERMISSIONS as $key => $label): ?>
        <div class="col-6 col-md-4">
          <label style="display:flex;align-items:center;gap:8px;cursor:pointer;padding:8px 10px;border:1px solid #2d3149;border-radius:8px;transition:border-color .15s" class="perm-label">
            <input type="checkbox" name="permissions[]" value="<?= $key ?>" class="form-check-input" style="margin:0">
            <span style="font-size:12px;font-weight:600"><?= htmlspecialchars($label) ?></span>
          </label>
        </div>
        <?php endforeach; ?>
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
<div class="modal fade" id="editRoleModal" tabindex="-1">
  <div class="modal-dialog modal-lg"><div class="modal-content" style="background:#1a1d27;border:1px solid #2d3149">
    <form method="POST">
    <?= csrf_field() ?><input type="hidden" name="action" value="edit"><input type="hidden" name="id" id="er-id">
    <div class="modal-header border-0"><h5 class="modal-title fw-bold">✏️ Edit Role</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
    <div class="modal-body" id="er-body"></div>
    <div class="modal-footer border-0">
      <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Batal</button>
      <button type="submit" class="btn btn-sm text-white" style="background:var(--brand)">Update</button>
    </div>
    </form>
  </div></div>
</div>

<style>
.perm-label:hover { border-color: var(--brand) !important; }
.perm-label:has(input:checked) { border-color: var(--brand) !important; background: rgba(255,107,53,.08); }
</style>

<script>
const ALL_PERMS = <?= json_encode(STAFF_PERMISSIONS) ?>;

function editRole(r) {
  document.getElementById('er-id').value = r.id;
  let html = `<div class="row g-3 mb-3">
    <div class="col-6"><label class="c-label">Nama Role</label><input type="text" name="name" class="c-form-control" value="${escH(r.name)}" required></div>
    <div class="col-6"><label class="c-label">Deskripsi</label><input type="text" name="description" class="c-form-control" value="${escH(r.description||'')}"></div>
  </div><label class="c-label mb-2">🔑 Hak Akses</label><div class="row g-2">`;
  for (const [key, label] of Object.entries(ALL_PERMS)) {
    const checked = r.permissions.includes(key) ? 'checked' : '';
    html += `<div class="col-6 col-md-4">
      <label style="display:flex;align-items:center;gap:8px;cursor:pointer;padding:8px 10px;border:1px solid #2d3149;border-radius:8px" class="perm-label">
        <input type="checkbox" name="permissions[]" value="${key}" class="form-check-input" style="margin:0" ${checked}>
        <span style="font-size:12px;font-weight:600">${escH(label)}</span>
      </label></div>`;
  }
  html += '</div>';
  document.getElementById('er-body').innerHTML = html;
  new bootstrap.Modal(document.getElementById('editRoleModal')).show();
}
function escH(s){return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}
</script>

<?php require __DIR__ . '/partials/footer.php'; ?>
