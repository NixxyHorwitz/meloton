<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
staff_require('memberships');
csrf_enforce();

$flash = $flashType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $id     = (int)($_POST['id'] ?? 0);

    if ($action === 'add' || $action === 'edit') {
        $name     = trim($_POST['name'] ?? '');
        $icon     = trim($_POST['icon'] ?? '⭐');
        $price    = (float)preg_replace('/[^\d.]/', '', $_POST['price'] ?? '0');
        $orig_price = (float)preg_replace('/[^\d.]/', '', $_POST['original_price'] ?? '0');
        $limit    = (int)($_POST['watch_limit'] ?? 10);
        $days     = (int)($_POST['duration_days'] ?? 30);
        $desc     = trim($_POST['description'] ?? '');
        $active   = isset($_POST['is_active']) ? 1 : 0;
        $wd_hold         = isset($_POST['wd_hold']) ? 1 : 0;
        $allow_edit_bank = isset($_POST['allow_edit_bank']) ? 1 : 0;
        $sort     = (int)($_POST['sort_order'] ?? 0);
        $min_wd   = (float)preg_replace('/[^\d.]/', '', $_POST['min_wd'] ?? '50000');
        $max_wd   = (float)preg_replace('/[^\d.]/', '', $_POST['max_wd'] ?? '0');

        if (!$name) { $flash = 'Nama paket wajib diisi.'; $flashType = 'error'; }
        else {
            if ($action === 'add') {
                $pdo->prepare("INSERT INTO memberships (name,icon,price,original_price,watch_limit,duration_days,description,is_active,sort_order,min_wd,max_wd,wd_hold,allow_edit_bank) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)")
                    ->execute([$name, $icon, $price, $orig_price, $limit, $days, $desc, $active, $sort, $min_wd, $max_wd, $wd_hold, $allow_edit_bank]);
                $flash = "Paket {$name} ditambahkan.";
            } else {
                $pdo->prepare("UPDATE memberships SET name=?,icon=?,price=?,original_price=?,watch_limit=?,duration_days=?,description=?,is_active=?,sort_order=?,min_wd=?,max_wd=?,wd_hold=?,allow_edit_bank=? WHERE id=?")
                    ->execute([$name, $icon, $price, $orig_price, $limit, $days, $desc, $active, $sort, $min_wd, $max_wd, $wd_hold, $allow_edit_bank, $id]);
                $flash = "Paket berhasil diperbarui."; 
            }
        }
    }
    if ($action === 'delete' && $id) {
        $force = !empty($_POST['force']);
        try {
            if ($force) {
                $pdo->beginTransaction();
                // Hapus history order yang terkait
                $pdo->prepare("DELETE FROM upgrade_orders WHERE membership_id=?")->execute([$id]);
                // Reset user yang menggunakan paket ini ke paket Free (id=1)
                $pdo->prepare("UPDATE users SET membership_id=1, membership_expires_at=NULL WHERE membership_id=?")->execute([$id]);
                // Hapus paket
                $pdo->prepare("DELETE FROM memberships WHERE id=? AND price>0")->execute([$id]);
                $pdo->commit();
                $flash = 'Paket beserta seluruh riwayat order terkait berhasil dihapus.';
            } else {
                $pdo->prepare("DELETE FROM memberships WHERE id=? AND price>0")->execute([$id]);
                $flash = 'Paket dihapus.';
            }
        } catch (\PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ($e->getCode() == '23000') {
                $flash = 'Gagal menghapus: masih ada data user atau riwayat order terkait. Gunakan opsi "Hapus Paksa" pada konfirmasi hapus.';
            } else {
                $flash = 'Terjadi kesalahan sistem saat menghapus paket.';
            }
            $flashType = 'error';
        }
    }
    if ($action === 'save_level_perf') {
        $perfs = $_POST['perf'] ?? [];
        foreach ($perfs as $pid => $data) {
            $avg = (float)($data['avg'] ?? 99.8);
            $down = isset($data['down']) ? 1 : 0;
            $disabled = isset($data['disabled']) ? 1 : 0;
            $pdo->prepare("UPDATE memberships SET perf_avg=?, perf_down_if_own=?, is_wd_disabled=? WHERE id=?")->execute([$avg, $down, $disabled, $pid]);
        }
        $flash = "Pengaturan kinerja level berhasil disimpan!";
    }
}

$plans = $pdo->query("SELECT * FROM memberships ORDER BY sort_order ASC, price ASC")->fetchAll();

$pageTitle  = 'Paket Membership';
$activePage = 'memberships';
require __DIR__ . '/partials/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-4">
  <div><h5 class="mb-0 fw-bold">⭐ Paket Membership</h5></div>
  <div class="d-flex gap-2">
    <button class="btn btn-sm btn-info text-white" style="background:#17a2b8;border:none" onclick="new bootstrap.Modal(document.getElementById('perfModal')).show()">⚙️ Setting Kinerja WD</button>
    <button class="btn btn-sm text-white" style="background:var(--brand)" data-bs-toggle="modal" data-bs-target="#addPlanModal">+ Tambah Paket</button>
  </div>
</div>

<?php if ($flash): ?>
<div class="alert alert-<?= $flashType==='error'?'danger':'success' ?> py-2 mb-3" style="border-radius:10px;font-size:13px"><?= htmlspecialchars($flash) ?></div>
<?php endif; ?>

<div class="row g-3">
  <?php foreach ($plans as $p):
    $colors = ['#888','#4E9BFF','#FFC107','#4CAF82'];
    $color  = $colors[min($p['sort_order'], 3)];
    $icon   = htmlspecialchars($p['icon'] ?: '⭐');
    $activeUsersStmt = $pdo->prepare("SELECT username FROM users WHERE membership_id=? AND membership_expires_at>NOW()");
    $activeUsersStmt->execute([$p['id']]);
    $activeUsernames = $activeUsersStmt->fetchAll(PDO::FETCH_COLUMN);
    $activeUsersCount = count($activeUsernames);
    $activeUsersList = $activeUsersCount > 0 ? implode(', ', $activeUsernames) : 'Belum ada user';
  ?>
  <div class="col-md-6 col-xl-3">
    <div class="c-card h-100">
      <div class="c-card-body">
        <div class="d-flex align-items-center justify-content-between mb-3">
          <div style="font-size:28px"><?= $icon ?></div>
          <div class="d-flex gap-1">
            <button class="btn btn-sm b-neutral" style="border:none;border-radius:8px;font-size:11px" onclick='editPlan(<?= json_encode($p) ?>)'>✏️</button>
            <?php if ((float)$p['price'] > 0): ?>
            <button type="button" onclick="confirmDelete(<?= $p['id'] ?>, '<?= htmlspecialchars($p['name'], ENT_QUOTES) ?>')" class="btn btn-sm b-danger" style="border:none;border-radius:8px;font-size:11px">🗑</button>
            <?php endif; ?>
          </div>
        </div>
        <div style="font-size:16px;font-weight:800;color:<?= $color ?>"><?= htmlspecialchars($p['name']) ?></div>
        <div style="font-size:22px;font-weight:800;margin:4px 0">
          <?= (float)$p['price']===0.0?'Gratis':format_rp((float)$p['price']) ?>
          <?php if ((float)$p['original_price'] > 0): ?>
          <small style="text-decoration:line-through;color:#888;font-size:14px;margin-left:4px;font-weight:normal"><?= format_rp((float)$p['original_price']) ?></small>
          <?php endif; ?>
        </div>
        <div style="font-size:12px;color:#666;margin-bottom:12px"><?= (float)$p['price']>0?'/ '.$p['duration_days'].' hari':'' ?></div>
        <div style="font-size:13px;color:#888;display:flex;flex-direction:column;gap:4px">
          <div>📹 <?= $p['watch_limit'] ?>× video/hari</div>
          <div>💸 Min WD: <?= format_rp((float)$p['min_wd']) ?></div>
          <div>💸 Max WD: <?= (float)$p['max_wd']>0 ? format_rp((float)$p['max_wd']) : '<i>Tanpa batas</i>' ?></div>
          <?php if ($p['description']): ?><div>ℹ️ <?= nl2br(htmlspecialchars($p['description'])) ?></div><?php endif; ?>
        </div>
        <div style="margin-top:12px;padding-top:12px;border-top:1px solid #1f2235;font-size:12px;color:#666">
          👥 <strong style="color:#e0e0f0"><?= $activeUsersCount ?></strong> user aktif
          · <?= $p['is_active']?'<span style="color:#4CAF82">Aktif</span>':'<span style="color:#F44E3B">Nonaktif</span>' ?>
          <?= $p['wd_hold'] ? '· <span style="color:#FFC107;font-weight:bold" title="Auto Hold">⏸ Hold</span>' : '' ?>
          <?= $p['allow_edit_bank'] ? '· <span style="color:#4E9BFF;font-weight:bold" title="User bisa edit rekening bank">✏️ Edit Rek.</span>' : '' ?>
          <div style="font-size:10px;color:#888;margin-top:4px;line-height:1.4">👤 <?= htmlspecialchars($activeUsersList) ?></div>
        </div>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<!-- Add Modal -->
<div class="modal fade" id="addPlanModal" tabindex="-1">
  <div class="modal-dialog"><div class="modal-content" style="background:#1a1d27;border:1px solid #2d3149">
    <form method="POST">
    <?= csrf_field() ?><input type="hidden" name="action" value="add">
    <div class="modal-header border-0"><h5 class="modal-title fw-bold">+ Tambah Paket</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
    <div class="modal-body" id="add-plan-body">
      <?php include __DIR__ . '/partials/plan_form.php'; ?>
    </div>
    <div class="modal-footer border-0">
      <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Batal</button>
      <button type="submit" class="btn btn-sm text-white" style="background:var(--brand)">Simpan</button>
    </div>
    </form>
  </div></div>
</div>

<!-- Edit Modal -->
<div class="modal fade" id="editPlanModal" tabindex="-1">
  <div class="modal-dialog"><div class="modal-content" style="background:#1a1d27;border:1px solid #2d3149">
    <form method="POST">
    <?= csrf_field() ?><input type="hidden" name="action" value="edit"><input type="hidden" name="id" id="ep-id">
    <div class="modal-header border-0"><h5 class="modal-title fw-bold">✏️ Edit Paket</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
    <div class="modal-body" id="ep-body"></div>
    <div class="modal-footer border-0">
      <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Batal</button>
      <button type="submit" class="btn btn-sm text-white" style="background:var(--brand)">Update</button>
    </div>
    </form>
  </div></div>
</div>

<!-- Delete Modal -->
<div class="modal fade" id="deletePlanModal" tabindex="-1">
  <div class="modal-dialog modal-sm"><div class="modal-content" style="background:#1a1d27;border:1px solid #2d3149">
    <form method="POST">
    <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" id="dp-id">
    <div class="modal-header border-0"><h6 class="modal-title fw-bold text-danger">⚠️ Hapus Paket</h6><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
    <div class="modal-body" style="font-size:13px;color:#ccc">
      Apakah Anda yakin ingin menghapus paket <strong id="dp-name" style="color:#fff"></strong>?
      <div class="form-check mt-3">
        <input class="form-check-input" type="checkbox" name="force" id="dp-force" value="1">
        <label class="form-check-label text-warning" for="dp-force" style="font-size:12px;line-height:1.4">
          Hapus Paksa: Centang ini jika gagal dihapus karena masih ada user/riwayat order. <br><small class="text-muted">(User akan dikembalikan ke paket Free, riwayat order paket ini akan dihapus permanen)</small>
        </label>
      </div>
    </div>
    <div class="modal-footer border-0">
      <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Batal</button>
      <button type="submit" class="btn btn-danger btn-sm">Ya, Hapus</button>
    </div>
    </form>
  </div></div>
</div>

<script>
function confirmDelete(id, name) {
  document.getElementById('dp-id').value = id;
  document.getElementById('dp-name').textContent = name;
  document.getElementById('dp-force').checked = false;
  new bootstrap.Modal(document.getElementById('deletePlanModal')).show();
}

function editPlan(p) {
  document.getElementById('ep-id').value = p.id;
  document.getElementById('ep-body').innerHTML = `
    <div class="row g-2 mb-3">
      <div class="col-8"><label class="c-label">Nama Paket</label>
        <input type="text" name="name" class="c-form-control" value="${escH(p.name)}" required></div>
      <div class="col-4"><label class="c-label">Icon</label>
        <input type="text" name="icon" class="c-form-control" value="${escH(p.icon||'⭐')}" required></div>
    </div>
    <div class="row g-2 mb-3">
      <div class="col-6"><label class="c-label">Harga (Rp)</label>
        <input type="number" name="price" class="c-form-control" value="${p.price}" min="0" step="1"></div>
      <div class="col-6"><label class="c-label">Harga Coret (Rp)</label>
        <input type="number" name="original_price" class="c-form-control" value="${p.original_price}" min="0" step="1"></div>
    </div>
    <div class="row g-2 mb-3">
      <div class="col-6"><label class="c-label">Limit Tonton/Hari</label>
        <input type="number" name="watch_limit" class="c-form-control" value="${p.watch_limit}" min="1"></div>
      <div class="col-6"><label class="c-label">Durasi (hari)</label>
        <input type="number" name="duration_days" class="c-form-control" value="${p.duration_days}" min="1"></div>
    </div>
    <div class="c-form-group mb-3"><label class="c-label">Urutan</label>
      <input type="number" name="sort_order" class="c-form-control" value="${p.sort_order}"></div>
    <div class="row g-2 mb-3">
      <div class="col-6"><label class="c-label">Min. WD (Rp)</label>
        <input type="number" name="min_wd" class="c-form-control" value="${p.min_wd}" min="0" step="1"></div>
      <div class="col-6"><label class="c-label">Max. WD (Rp)</label>
        <input type="number" name="max_wd" class="c-form-control" value="${p.max_wd}" min="0" step="1">
        <small style="font-size:10px;color:#888">0 = Tanpa batas</small></div>
    </div>
    <div class="c-form-group mb-3"><label class="c-label">Deskripsi</label>
      <textarea name="description" class="c-form-control" rows="3">${escH(p.description||'')}</textarea></div>
    <div class="form-check ms-1 mb-2"><input class="form-check-input" type="checkbox" name="wd_hold" id="ep-wd-hold" ${p.wd_hold==1?'checked':''}>
      <label class="form-check-label text-warning fw-bold" for="ep-wd-hold" style="font-size:13px">Tahan Withdraw (Auto Hold)</label></div>
    <div class="form-check ms-1 mb-2"><input class="form-check-input" type="checkbox" name="allow_edit_bank" id="ep-allow-edit-bank" ${p.allow_edit_bank==1?'checked':''}>
      <label class="form-check-label text-info fw-bold" for="ep-allow-edit-bank" style="font-size:13px">✏️ Izinkan Edit Rekening Bank</label>
      <div style="font-size:10px;color:#888;margin-top:2px">User di level ini bisa edit rekening</div></div>
    <div class="form-check ms-1"><input class="form-check-input" type="checkbox" name="is_active" id="ep-active" ${p.is_active==1?'checked':''}>
      <label class="form-check-label text-secondary" for="ep-active" style="font-size:13px">Aktif</label></div>`;
  new bootstrap.Modal(document.getElementById('editPlanModal')).show();
}
function escH(s){return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}
</script>

<?php $all_memberships = $pdo->query("SELECT * FROM memberships ORDER BY sort_order ASC, price ASC")->fetchAll(); ?>
<div class="modal fade" id="perfModal" tabindex="-1">
  <div class="modal-dialog"><div class="modal-content" style="background:#1a1d27;border:1px solid #2d3149">
    <form method="POST">
    <?= csrf_field() ?><input type="hidden" name="action" value="save_level_perf">
    <div class="modal-header border-0"><h6 class="modal-title fw-bold">⚙️ Setting Kinerja WD Per Level</h6><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
    <div class="modal-body" style="max-height: 400px; overflow-y: auto;">
      <p style="font-size:12px;color:#aaa">Atur rata-rata keberhasilan/uptime WD untuk halaman user. Aktifkan "Down If Own" agar kinerjanya ditampilkan rendah saat diakses oleh pemilik level tersebut.</p>
      <?php foreach ($all_memberships as $m): ?>
      <div style="background:rgba(255,255,255,0.03);padding:10px;border-radius:8px;margin-bottom:10px;border:1px solid #2d3149">
        <div style="font-weight:700;font-size:14px;color:#fff;margin-bottom:8px"><?= htmlspecialchars($m['icon'].' '.$m['name']) ?></div>
        <div class="row g-2">
          <div class="col-7">
            <label class="c-label">AVG Kinerja (%)</label>
            <input type="number" step="0.01" name="perf[<?= $m['id'] ?>][avg]" class="c-form-control" value="<?= (float)($m['perf_avg'] ?? 99.8) ?>" min="0" max="100">
          </div>
          <div class="col-5 d-flex flex-column justify-content-end">
            <div class="form-check mb-1">
              <input type="checkbox" class="form-check-input" name="perf[<?= $m['id'] ?>][down]" id="chk_down_<?= $m['id'] ?>" value="1" <?= !empty($m['perf_down_if_own']) ? 'checked' : '' ?>>
              <label class="form-check-label text-warning" for="chk_down_<?= $m['id'] ?>" style="font-size:12px;font-weight:bold">Down If Own</label>
            </div>
            <div class="form-check">
              <input type="checkbox" class="form-check-input" name="perf[<?= $m['id'] ?>][disabled]" id="chk_dis_<?= $m['id'] ?>" value="1" <?= !empty($m['is_wd_disabled']) ? 'checked' : '' ?>>
              <label class="form-check-label text-danger" for="chk_dis_<?= $m['id'] ?>" style="font-size:12px;font-weight:bold" title="Matikan fungsi withdraw sepenuhnya untuk level ini">Tutup WD</label>
            </div>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <div class="modal-footer border-0">
      <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Batal</button>
      <button type="submit" class="btn btn-sm text-white" style="background:var(--brand)">Simpan</button>
    </div>
    </form>
  </div></div>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>
