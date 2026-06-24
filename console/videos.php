<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
staff_require('videos');
csrf_enforce();

$flash = $flashType = '';

// Handle CRUD actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add' || $action === 'edit') {
        $id       = (int)($_POST['id'] ?? 0);
        $title    = trim($_POST['title'] ?? '');
        $yt_raw   = trim($_POST['youtube_url'] ?? '');
        $yt_id    = extract_youtube_id($yt_raw);
        $reward   = (float)preg_replace('/[^\d.]/', '', $_POST['reward_amount'] ?? '0');
        $duration = (int)($_POST['watch_duration'] ?? 30);
        $active   = isset($_POST['is_active']) ? 1 : 0;
        $sort     = (int)($_POST['sort_order'] ?? 0);

        if (!$title || !$yt_id) { $flash = 'Judul dan URL YouTube wajib diisi & valid.'; $flashType = 'error'; }
        elseif ($reward < 1) { $flash = 'Reward harus lebih dari 0.'; $flashType = 'error'; }
        elseif ($duration < 5) { $flash = 'Durasi minimal 5 detik.'; $flashType = 'error'; }
        else {
            if ($action === 'add') {
                $pdo->prepare("INSERT INTO videos (title,youtube_id,reward_amount,watch_duration,is_active,sort_order) VALUES (?,?,?,?,?,?)")
                    ->execute([$title, $yt_id, $reward, $duration, $active, $sort]);
                $flash = "Video '{$title}' berhasil ditambahkan.";
            } else {
                $pdo->prepare("UPDATE videos SET title=?,youtube_id=?,reward_amount=?,watch_duration=?,is_active=?,sort_order=? WHERE id=?")
                    ->execute([$title, $yt_id, $reward, $duration, $active, $sort, $id]);
                $flash = "Video berhasil diperbarui.";
            }
        }
    }

    if ($action === 'toggle') {
        $id  = (int)($_POST['id'] ?? 0);
        $val = (int)($_POST['val'] ?? 0);
        $pdo->prepare("UPDATE videos SET is_active=? WHERE id=?")->execute([$val, $id]);
        $flash = 'Status video diperbarui.';
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("DELETE FROM videos WHERE id=?")->execute([$id]);
        $flash = 'Video dihapus.';
    }

    if ($action === 'save_sort') {
        $sort = $_POST['sort_mode'] ?? 'default';
        $pdo->prepare("INSERT INTO settings (`key`,`value`) VALUES ('video_sort_mode',?) ON DUPLICATE KEY UPDATE `value`=?")->execute([$sort, $sort]);
        $flash = 'Mode sortir video berhasil disimpan.';
    }
}

$videos = $pdo->query("SELECT * FROM videos ORDER BY sort_order ASC, id DESC")->fetchAll();
$currentSort = setting($pdo, 'video_sort_mode', 'default');

$pageTitle  = 'Manajemen Video';
$activePage = 'videos';
require __DIR__ . '/partials/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-4">
  <div><h5 class="mb-0 fw-bold">🎬 Manajemen Video</h5><small class="text-secondary"><?= count($videos) ?> video tersimpan</small></div>
  <button class="btn btn-sm text-white" style="background:var(--brand)" data-bs-toggle="modal" data-bs-target="#addModal">+ Tambah Video</button>
</div>

<?php if ($flash): ?>
<div class="alert alert-<?= $flashType==='error'?'danger':'success' ?> py-2 mb-3" style="border-radius:10px;font-size:13px"><?= htmlspecialchars($flash) ?></div>
<?php endif; ?>

<div class="c-card mb-4">
  <div class="c-card-body py-3">
    <form method="POST" class="d-flex align-items-center gap-3">
      <?= csrf_field() ?><input type="hidden" name="action" value="save_sort">
      <div style="font-size:13px;font-weight:600;white-space:nowrap">Urutkan Video User:</div>
      <select name="sort_mode" class="c-form-control" style="width:auto;min-width:200px" onchange="this.form.submit()">
        <option value="default" <?= $currentSort==='default'?'selected':'' ?>>Custom (berdasarkan Urutan & ID)</option>
        <option value="newest" <?= $currentSort==='newest'?'selected':'' ?>>Terbaru (ID Terbesar)</option>
        <option value="oldest" <?= $currentSort==='oldest'?'selected':'' ?>>Terlama (ID Terkecil)</option>
        <option value="reward_desc" <?= $currentSort==='reward_desc'?'selected':'' ?>>Reward Terbesar (Rp)</option>
        <option value="reward_asc" <?= $currentSort==='reward_asc'?'selected':'' ?>>Reward Terkecil (Rp)</option>
        <option value="duration_asc" <?= $currentSort==='duration_asc'?'selected':'' ?>>Durasi Tersingkat</option>
        <option value="random" <?= $currentSort==='random'?'selected':'' ?>>Acak (Random)</option>
      </select>
    </form>
  </div>
</div>

<div class="c-card">
  <div style="overflow-x:auto">
    <table class="c-table">
      <thead><tr><th>Urutan</th><th>Thumbnail</th><th>Judul</th><th>Reward</th><th>Durasi</th><th>Tonton</th><th>Status</th><th>Aksi</th></tr></thead>
      <tbody>
      <?php foreach ($videos as $v): ?>
      <tr>
        <td>
          <span class="badge bg-secondary" style="font-size:11px"><?= $v['sort_order'] ?></span>
        </td>
        <td><img src="https://img.youtube.com/vi/<?= $v['youtube_id'] ?>/default.jpg" style="width:80px;height:45px;object-fit:cover;border-radius:6px"></td>
        <td style="max-width:200px">
          <div style="font-weight:600;font-size:13px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= htmlspecialchars($v['title']) ?></div>
          <div style="font-size:11px;color:#666"><?= $v['youtube_id'] ?></div>
        </td>
        <td style="color:#4CAF82;font-weight:700"><?= format_rp((float)$v['reward_amount']) ?></td>
        <td style="color:#888"><?= $v['watch_duration'] ?>s</td>
        <td><span class="badge b-neutral" style="border-radius:6px">👁 <?= number_format((int)$v['total_watches']) ?></span></td>
        <td>
          <form method="POST" class="d-inline">
            <?= csrf_field() ?><input type="hidden" name="action" value="toggle">
            <input type="hidden" name="id" value="<?= $v['id'] ?>">
            <input type="hidden" name="val" value="<?= $v['is_active']?0:1 ?>">
            <button type="submit" class="badge border-0 <?= $v['is_active']?'b-success':'b-danger' ?>" style="cursor:pointer;border-radius:6px;padding:4px 8px">
              <?= $v['is_active']?'Aktif':'Nonaktif' ?>
            </button>
          </form>
        </td>
        <td>
          <button class="btn btn-sm b-neutral" style="border-radius:8px;font-size:11px;border:none"
            onclick='editVideo(<?= json_encode($v) ?>)'>✏️ Edit</button>
          <form method="POST" class="d-inline" onsubmit="return confirm('Hapus video ini?')">
            <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $v['id'] ?>">
            <button class="btn btn-sm b-danger" style="border-radius:8px;font-size:11px;border:none">🗑</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php if (empty($videos)): ?><div style="padding:40px;text-align:center;color:#555">Belum ada video. Tambahkan sekarang!</div><?php endif; ?>
  </div>
</div>

<!-- Add Modal -->
<div class="modal fade" id="addModal" tabindex="-1">
  <div class="modal-dialog"><div class="modal-content" style="background:#1a1d27;border:1px solid #2d3149">
    <form method="POST">
    <?= csrf_field() ?><input type="hidden" name="action" value="add">
    <div class="modal-header border-0"><h5 class="modal-title fw-bold">+ Tambah Video</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
      <?php include __DIR__ . '/partials/video_form.php'; ?>
    </div>
    <div class="modal-footer border-0">
      <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Batal</button>
      <button type="submit" class="btn btn-sm text-white" style="background:var(--brand)">Simpan Video</button>
    </div>
    </form>
  </div></div>
</div>

<!-- Edit Modal -->
<div class="modal fade" id="editModal" tabindex="-1">
  <div class="modal-dialog"><div class="modal-content" style="background:#1a1d27;border:1px solid #2d3149">
    <form method="POST" id="edit-form">
    <?= csrf_field() ?><input type="hidden" name="action" value="edit"><input type="hidden" name="id" id="edit-id">
    <div class="modal-header border-0"><h5 class="modal-title fw-bold">✏️ Edit Video</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
    <div class="modal-body" id="edit-body"></div>
    <div class="modal-footer border-0">
      <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Batal</button>
      <button type="submit" class="btn btn-sm text-white" style="background:var(--brand)">Update Video</button>
    </div>
    </form>
  </div></div>
</div>

<script>
function editVideo(v) {
  document.getElementById('edit-id').value = v.id;
  document.getElementById('edit-body').innerHTML = `
    <div class="c-form-group mb-3"><label class="c-label">Judul Video</label>
      <input type="text" name="title" class="c-form-control" value="${escH(v.title)}" required></div>
    <div class="c-form-group mb-3"><label class="c-label">YouTube URL / ID</label>
      <input type="text" name="youtube_url" class="c-form-control" value="${escH(v.youtube_id)}" required>
      <div style="font-size:11px;color:#666;margin-top:4px">Masukkan URL atau ID YouTube</div></div>
    <div class="row g-2 mb-3">
      <div class="col-6"><label class="c-label">Reward (Rp)</label>
        <input type="number" name="reward_amount" class="c-form-control" value="${v.reward_amount}" min="1" step="any" required></div>
      <div class="col-6"><label class="c-label">Durasi Min (detik)</label>
        <input type="number" name="watch_duration" class="c-form-control" value="${v.watch_duration}" min="5" required></div>
    </div>
    <div class="row g-2">
      <div class="col-6"><label class="c-label">Urutan</label>
        <input type="number" name="sort_order" class="c-form-control" value="${v.sort_order}"></div>
      <div class="col-6 d-flex align-items-end pb-1">
        <div class="form-check ms-2">
          <input class="form-check-input" type="checkbox" name="is_active" id="ea" ${v.is_active==1?'checked':''}>
          <label class="form-check-label text-secondary" for="ea" style="font-size:13px">Aktif</label>
        </div>
      </div>
    </div>`;
  new bootstrap.Modal(document.getElementById('editModal')).show();
}
function escH(s){return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}
</script>

<?php require __DIR__ . '/partials/footer.php'; ?>
