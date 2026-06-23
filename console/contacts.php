<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
staff_require('contacts');
csrf_enforce();

global $pdo;

// ── Ensure table exists ──────────────────────────────────────────
$pdo->exec("CREATE TABLE IF NOT EXISTS `contact_buttons` (
  `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `label`       VARCHAR(100) NOT NULL,
  `url`         VARCHAR(500) NOT NULL,
  `icon_type`   ENUM('preset','custom') NOT NULL DEFAULT 'preset',
  `icon_value`  VARCHAR(255) NOT NULL DEFAULT 'wa',
  `bg_color`    VARCHAR(20) NOT NULL DEFAULT '#25D366',
  `is_active`   TINYINT(1) NOT NULL DEFAULT 1,
  `sort_order`  INT NOT NULL DEFAULT 0,
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$flash = $flashType = '';

// ── POST handlers ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Toggle floating globally
    if ($action === 'toggle_floating') {
        $val = $_POST['floating_enabled'] ?? '0';
        setting_set($pdo, 'floating_enabled', $val === '1' ? '1' : '0');
        $flash = 'Pengaturan floating disimpan!';
    }

    // Add new button
    if ($action === 'add_button') {
        $label      = trim($_POST['label'] ?? '');
        $url        = trim($_POST['url'] ?? '');
        $icon_type  = $_POST['icon_type'] === 'custom' ? 'custom' : 'preset';
        $icon_value = trim($_POST['icon_value'] ?? 'wa');
        $bg_color   = trim($_POST['bg_color'] ?? '#25D366');
        $sort_order = (int)($_POST['sort_order'] ?? 0);

        if (!$label || !$url) {
            $flash = 'Label dan URL wajib diisi.'; $flashType = 'error';
        } else {
            // Handle custom icon upload
            if ($icon_type === 'custom' && !empty($_FILES['icon_file']['tmp_name'])) {
                $ext = strtolower(pathinfo($_FILES['icon_file']['name'], PATHINFO_EXTENSION));
                $allowed = ['png','jpg','jpeg','webp','gif'];
                if (!in_array($ext, $allowed)) {
                    $flash = 'Format ikon: PNG/JPG/WEBP/GIF.'; $flashType = 'error';
                    goto end_add;
                }
                $dir = dirname(__DIR__) . '/uploads/contacts/';
                if (!is_dir($dir)) mkdir($dir, 0755, true);
                $fname = 'icon_' . uniqid() . '.' . $ext;
                move_uploaded_file($_FILES['icon_file']['tmp_name'], $dir . $fname);
                $icon_value = '/uploads/contacts/' . $fname;
            }

            $pdo->prepare("INSERT INTO contact_buttons (label,url,icon_type,icon_value,bg_color,sort_order) VALUES (?,?,?,?,?,?)")
                ->execute([$label, $url, $icon_type, $icon_value, $bg_color, $sort_order]);
            $flash = '✅ Tombol berhasil ditambahkan!';
        }
        end_add:;
    }

    // Toggle single button active
    if ($action === 'toggle_btn') {
        $id  = (int)($_POST['btn_id'] ?? 0);
        $val = (int)($_POST['is_active'] ?? 0);
        $pdo->prepare("UPDATE contact_buttons SET is_active=? WHERE id=?")->execute([$val, $id]);
        $flash = 'Status tombol diperbarui.';
    }

    // Delete button
    if ($action === 'delete_btn') {
        $id = (int)($_POST['btn_id'] ?? 0);
        // Delete custom icon file
        $row = $pdo->prepare("SELECT icon_type, icon_value FROM contact_buttons WHERE id=?");
        $row->execute([$id]);
        $r = $row->fetch();
        if ($r && $r['icon_type'] === 'custom' && str_starts_with($r['icon_value'], '/uploads/')) {
            @unlink(dirname(__DIR__) . $r['icon_value']);
        }
        $pdo->prepare("DELETE FROM contact_buttons WHERE id=?")->execute([$id]);
        $flash = '🗑 Tombol dihapus.';
    }

    // Update sort order
    if ($action === 'update_order') {
        $orders = $_POST['orders'] ?? [];
        foreach ($orders as $id => $ord) {
            $pdo->prepare("UPDATE contact_buttons SET sort_order=? WHERE id=?")->execute([(int)$ord, (int)$id]);
        }
        $flash = 'Urutan disimpan.';
    }
}

$buttons = $pdo->query("SELECT * FROM contact_buttons ORDER BY sort_order ASC, id ASC")->fetchAll();
$floating_enabled = setting($pdo, 'floating_enabled', '1');

$pageTitle  = 'Tombol Kontak';
$activePage = 'contacts';
require __DIR__ . '/partials/header.php';

// Preset icon definitions
$presets = [
    'wa'   => ['label' => 'WhatsApp',  'color' => '#25D366', 'svg' => '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347z"/><path d="M12 0C5.373 0 0 5.373 0 12c0 2.123.554 4.118 1.528 5.847L.057 23.883a.5.5 0 00.61.61l6.037-1.472A11.944 11.944 0 0012 24c6.627 0 12-5.373 12-12S18.627 0 12 0zm0 22c-1.89 0-3.655-.518-5.17-1.42l-.37-.22-3.823.933.954-3.722-.242-.383A9.958 9.958 0 012 12C2 6.477 6.477 2 12 2s10 4.477 10 10-4.477 10-10 10z"/></svg>'],
    'tele' => ['label' => 'Telegram',  'color' => '#2CA5E0', 'svg' => '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M11.944 0A12 12 0 000 12a12 12 0 0012 12 12 12 0 0012-12A12 12 0 0012 0a12 12 0 00-.056 0zm4.962 7.224c.1-.002.321.023.465.14a.506.506 0 01.171.325c.016.093.036.306.02.472-.18 1.898-.962 6.502-1.36 8.627-.168.9-.499 1.201-.82 1.23-.696.065-1.225-.46-1.9-.902-1.056-.693-1.653-1.124-2.678-1.8-1.185-.78-.417-1.21.258-1.91.177-.184 3.247-2.977 3.307-3.23.007-.032.014-.15-.056-.212s-.174-.041-.249-.024c-.106.024-1.793 1.14-5.061 3.345-.48.33-.913.49-1.302.48-.428-.008-1.252-.241-1.865-.44-.752-.245-1.349-.374-1.297-.789.027-.216.325-.437.893-.663 3.498-1.524 5.83-2.529 6.998-3.014 3.332-1.386 4.025-1.627 4.476-1.635z"/></svg>'],
    'cs'   => ['label' => 'CS Admin',  'color' => '#FF6B35', 'svg' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg>'],
    'ig'   => ['label' => 'Instagram', 'color' => '#E1306C', 'svg' => '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zm0-2.163c-3.259 0-3.667.014-4.947.072-4.358.2-6.78 2.618-6.98 6.98-.059 1.281-.073 1.689-.073 4.948 0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98 1.281.058 1.689.072 4.948.072 3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98-1.281-.059-1.69-.073-4.949-.073zm0 5.838a6.162 6.162 0 100 12.324 6.162 6.162 0 000-12.324zM12 16a4 4 0 110-8 4 4 0 010 8zm6.406-11.845a1.44 1.44 0 100 2.881 1.44 1.44 0 000-2.881z"/></svg>'],
    'fb'   => ['label' => 'Facebook',  'color' => '#1877F2', 'svg' => '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>'],
];
?>

<div class="mb-4 d-flex align-items-center justify-content-between flex-wrap gap-2">
  <div>
    <h5 class="mb-0 fw-bold">💬 Tombol Kontak</h5>
    <div style="font-size:12px;color:#666;margin-top:2px">Kelola floating button & link komunitas</div>
  </div>
  <!-- Toggle floating globally -->
  <form method="POST" class="d-flex align-items-center gap-2">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="toggle_floating">
    <input type="hidden" name="floating_enabled" value="<?= $floating_enabled === '1' ? '0' : '1' ?>">
    <button type="submit" class="btn btn-sm <?= $floating_enabled === '1' ? 'btn-success' : 'btn-secondary' ?> text-white" style="border-radius:8px;font-size:12px">
      <?= $floating_enabled === '1' ? '✅ Floating Aktif' : '⭕ Floating Nonaktif' ?>
    </button>
  </form>
</div>

<?php if ($flash): ?>
<div class="alert alert-<?= $flashType === 'error' ? 'danger' : 'success' ?> py-2 mb-3" style="border-radius:10px;font-size:13px"><?= htmlspecialchars($flash) ?></div>
<?php endif; ?>

<div class="row g-3">
  <!-- Existing buttons list -->
  <div class="col-md-7">
    <div class="c-card">
      <div class="c-card-header"><span class="c-card-title">📋 Daftar Tombol</span></div>
      <div class="c-card-body" style="padding:0">
        <?php if (empty($buttons)): ?>
        <div style="padding:20px;text-align:center;color:#888;font-size:13px">Belum ada tombol. Tambahkan di sebelah kanan.</div>
        <?php else: ?>
        <?php foreach ($buttons as $btn): ?>
        <div style="display:flex;align-items:center;gap:10px;padding:10px 14px;border-bottom:1px solid #f0f0f0">
          <!-- Icon preview -->
          <div style="width:38px;height:38px;border-radius:10px;background:<?= htmlspecialchars($btn['bg_color']) ?>;display:flex;align-items:center;justify-content:center;flex-shrink:0;overflow:hidden">
            <?php if ($btn['icon_type'] === 'custom'): ?>
            <img src="<?= htmlspecialchars($btn['icon_value']) ?>" style="width:100%;height:100%;object-fit:cover" alt="">
            <?php else: ?>
            <span style="color:#fff;width:20px;height:20px;display:flex"><?= $presets[$btn['icon_value']]['svg'] ?? $presets['cs']['svg'] ?></span>
            <?php endif; ?>
          </div>
          <!-- Info -->
          <div style="flex:1;min-width:0">
            <div style="font-weight:700;font-size:13px"><?= htmlspecialchars($btn['label']) ?></div>
            <div style="font-size:11px;color:#888;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= htmlspecialchars($btn['url']) ?></div>
          </div>
          <!-- Active toggle -->
          <form method="POST" style="flex-shrink:0">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="toggle_btn">
            <input type="hidden" name="btn_id" value="<?= $btn['id'] ?>">
            <input type="hidden" name="is_active" value="<?= $btn['is_active'] ? '0' : '1' ?>">
            <button type="submit" class="btn btn-sm <?= $btn['is_active'] ? 'btn-success' : 'btn-secondary' ?> text-white" style="font-size:10px;padding:3px 8px;border-radius:6px">
              <?= $btn['is_active'] ? 'Aktif' : 'Off' ?>
            </button>
          </form>
          <!-- Delete -->
          <form method="POST" style="flex-shrink:0" onsubmit="return confirm('Hapus tombol ini?')">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete_btn">
            <input type="hidden" name="btn_id" value="<?= $btn['id'] ?>">
            <button type="submit" class="btn btn-sm btn-danger text-white" style="font-size:10px;padding:3px 8px;border-radius:6px">🗑</button>
          </form>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>

    <!-- Preview -->
    <div class="c-card mt-3">
      <div class="c-card-header"><span class="c-card-title">👁 Preview Floating</span></div>
      <div class="c-card-body">
        <div style="background:#f5f5f5;border-radius:12px;padding:16px;min-height:80px;position:relative;display:flex;justify-content:flex-end;align-items:flex-end;gap:10px;flex-wrap:wrap">
          <?php foreach (array_filter($buttons, fn($b) => $b['is_active']) as $btn): ?>
          <a href="<?= htmlspecialchars($btn['url']) ?>" target="_blank" title="<?= htmlspecialchars($btn['label']) ?>"
             style="width:44px;height:44px;border-radius:14px;background:<?= htmlspecialchars($btn['bg_color']) ?>;border:2px solid #1A1A1A;box-shadow:2px 2px 0 #1A1A1A;display:flex;align-items:center;justify-content:center;overflow:hidden;text-decoration:none">
            <?php if ($btn['icon_type'] === 'custom'): ?>
            <img src="<?= htmlspecialchars($btn['icon_value']) ?>" style="width:100%;height:100%;object-fit:cover" alt="">
            <?php else: ?>
            <span style="color:#fff;width:22px;height:22px;display:flex"><?= $presets[$btn['icon_value']]['svg'] ?? $presets['cs']['svg'] ?></span>
            <?php endif; ?>
          </a>
          <?php endforeach; ?>
          <?php if (empty(array_filter($buttons, fn($b) => $b['is_active']))): ?>
          <div style="font-size:12px;color:#aaa;width:100%;text-align:center">Tidak ada tombol aktif</div>
          <?php endif; ?>
        </div>
        <div style="font-size:11px;color:#888;margin-top:6px">Preview tampilan floating di halaman user (kanan bawah)</div>
      </div>
    </div>
  </div>

  <!-- Add button form -->
  <div class="col-md-5">
    <div class="c-card">
      <div class="c-card-header"><span class="c-card-title">➕ Tambah Tombol</span></div>
      <div class="c-card-body">
        <form method="POST" enctype="multipart/form-data" id="add-btn-form">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="add_button">

          <div class="c-form-group">
            <label class="c-label">Label</label>
            <input type="text" name="label" class="c-form-control" placeholder="Misal: Grup WhatsApp" required>
          </div>

          <div class="c-form-group">
            <label class="c-label">URL / Link</label>
            <input type="url" name="url" class="c-form-control" placeholder="https://wa.me/628..." required>
          </div>

          <div class="c-form-group">
            <label class="c-label">Tipe Ikon</label>
            <select name="icon_type" class="c-form-control" id="icon-type-select" onchange="toggleIconType(this.value)">
              <option value="preset">Preset (WA/Tele/CS/IG/FB)</option>
              <option value="custom">Custom (Upload Gambar/GIF)</option>
            </select>
          </div>

          <!-- Preset picker -->
          <div id="preset-picker" class="c-form-group">
            <label class="c-label">Pilih Preset Ikon</label>
            <div style="display:flex;gap:8px;flex-wrap:wrap">
              <?php foreach ($presets as $key => $p): ?>
              <label style="cursor:pointer;text-align:center">
                <input type="radio" name="icon_value" value="<?= $key ?>" <?= $key === 'wa' ? 'checked' : '' ?> style="display:none" onchange="updateBgColor('<?= $p['color'] ?>')">
                <div class="preset-opt" data-color="<?= $p['color'] ?>" style="width:42px;height:42px;border-radius:10px;background:<?= $p['color'] ?>;border:2.5px solid transparent;box-shadow:1px 1px 0 #aaa;display:flex;align-items:center;justify-content:center;transition:.15s">
                  <span style="color:#fff;width:22px;height:22px;display:flex"><?= $p['svg'] ?></span>
                </div>
                <div style="font-size:9px;font-weight:700;margin-top:3px;color:#555"><?= $p['label'] ?></div>
              </label>
              <?php endforeach; ?>
            </div>
          </div>

          <!-- Custom upload -->
          <div id="custom-picker" class="c-form-group" style="display:none">
            <label class="c-label">Upload Ikon (PNG/JPG/GIF/WEBP)</label>
            <input type="file" name="icon_file" class="c-form-control" accept="image/*">
            <div style="font-size:11px;color:#888;margin-top:4px">GIF animasi didukung. Ukuran ideal: 64×64px</div>
          </div>

          <div class="c-form-group">
            <label class="c-label">Warna Background</label>
            <input type="color" name="bg_color" id="bg-color-input" class="c-form-control" value="#25D366" style="height:38px;padding:2px 4px">
          </div>

          <div class="c-form-group">
            <label class="c-label">Urutan (sort_order)</label>
            <input type="number" name="sort_order" class="c-form-control" value="0" min="0">
          </div>

          <button type="submit" class="btn btn-sm text-white w-100" style="background:var(--brand)">➕ Tambah Tombol</button>
        </form>
      </div>
    </div>
  </div>
</div>

<script>
function toggleIconType(val) {
  document.getElementById('preset-picker').style.display = val === 'preset' ? '' : 'none';
  document.getElementById('custom-picker').style.display = val === 'custom' ? '' : 'none';
}
function updateBgColor(color) {
  document.getElementById('bg-color-input').value = color;
  document.querySelectorAll('.preset-opt').forEach(el => {
    el.style.border = el.dataset.color === color ? '2.5px solid #1A1A1A' : '2.5px solid transparent';
  });
}
// Init highlight on first preset
document.addEventListener('DOMContentLoaded', () => {
  const checked = document.querySelector('[name="icon_value"]:checked');
  if (checked) {
    const color = checked.closest('label').querySelector('.preset-opt').dataset.color;
    updateBgColor(color);
  }
  document.querySelectorAll('[name="icon_value"]').forEach(r => {
    r.addEventListener('change', () => {
      updateBgColor(r.closest('label').querySelector('.preset-opt').dataset.color);
    });
  });
});
</script>

<?php require __DIR__ . '/partials/footer.php'; ?>
