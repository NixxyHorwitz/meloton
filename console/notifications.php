<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
staff_require('notifications');
csrf_enforce();

$flash = $flashType = '';

// ── POST: Send Notification ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'send_notif') {
        $title       = trim($_POST['title'] ?? '');
        $message     = trim($_POST['message'] ?? '');
        $type        = $_POST['type'] ?? 'info';
        $icon        = trim($_POST['icon'] ?? '');
        $target_type = $_POST['target_type'] ?? 'all';
        $action_url  = trim($_POST['action_url'] ?? '');
        $action_text = trim($_POST['action_text'] ?? '');
        $expires_at  = trim($_POST['expires_at'] ?? '');

        $valid_types   = ['info','success','warning','alert','congrats'];
        $valid_targets = ['all','single','selected','has_balance','has_membership','level'];

        if (!$title || !$message) {
            $flash = 'Judul dan pesan wajib diisi.'; $flashType = 'error';
        } elseif (!in_array($type, $valid_types)) {
            $flash = 'Tipe notifikasi tidak valid.'; $flashType = 'error';
        } elseif (!in_array($target_type, $valid_targets)) {
            $flash = 'Target tidak valid.'; $flashType = 'error';
        } else {
            // Compute target_user_ids
            $target_user_ids = null;

            if ($target_type === 'all') {
                // No ids needed — handled at query time
                $target_user_ids = null;

            } elseif ($target_type === 'single') {
                $identifier = trim($_POST['single_user'] ?? '');
                $s = $pdo->prepare("SELECT id FROM users WHERE username=? OR email=? LIMIT 1");
                $s->execute([$identifier, $identifier]);
                $uid = $s->fetchColumn();
                if (!$uid) { $flash = "User '{$identifier}' tidak ditemukan."; $flashType = 'error'; }
                else { $target_user_ids = json_encode([$uid]); }

            } elseif ($target_type === 'selected') {
                $raw_ids = $_POST['selected_ids'] ?? '';
                $ids = array_filter(array_map('intval', preg_split('/[\s,]+/', $raw_ids)));
                if (empty($ids)) { $flash = 'Masukkan minimal 1 User ID.'; $flashType = 'error'; }
                else {
                    // Validate IDs exist
                    $in = implode(',', array_fill(0, count($ids), '?'));
                    $s = $pdo->prepare("SELECT id FROM users WHERE id IN ({$in})");
                    $s->execute(array_values($ids));
                    $valid_ids = $s->fetchAll(PDO::FETCH_COLUMN);
                    if (empty($valid_ids)) { $flash = 'Tidak ada user ditemukan dengan ID tersebut.'; $flashType = 'error'; }
                    else { $target_user_ids = json_encode(array_values(array_map('intval', $valid_ids))); }
                }

            } elseif ($target_type === 'has_balance') {
                $min_balance = max(0, (float)($_POST['min_balance'] ?? 0));
                $s = $pdo->prepare("SELECT id FROM users WHERE (balance_wd >= ? OR balance_dep >= ?) AND is_active=1");
                $s->execute([$min_balance, $min_balance]);
                $ids = $s->fetchAll(PDO::FETCH_COLUMN);
                if (empty($ids)) { $flash = 'Tidak ada user dengan saldo yang memenuhi syarat.'; $flashType = 'error'; }
                else { $target_user_ids = json_encode(array_values(array_map('intval', $ids))); }

            } elseif ($target_type === 'has_membership') {
                $s = $pdo->query("SELECT id FROM users WHERE membership_id IS NOT NULL AND membership_expires_at > NOW() AND is_active=1");
                $ids = $s->fetchAll(PDO::FETCH_COLUMN);
                if (empty($ids)) { $flash = 'Tidak ada user dengan membership aktif.'; $flashType = 'error'; }
                else { $target_user_ids = json_encode(array_values(array_map('intval', $ids))); }

            } elseif ($target_type === 'level') {
                $level_id = (int)($_POST['target_level_id'] ?? 0);
                $s = $pdo->prepare("SELECT id FROM users WHERE membership_id = ? AND membership_expires_at > NOW() AND is_active=1");
                $s->execute([$level_id]);
                $ids = $s->fetchAll(PDO::FETCH_COLUMN);
                if (empty($ids)) { $flash = 'Tidak ada user aktif pada level tersebut.'; $flashType = 'error'; }
                else { $target_user_ids = json_encode(array_values(array_map('intval', $ids))); }
            }

            // Insert if no errors
            if (!$flash) {
                $db_target_type = ($target_type === 'level') ? 'has_membership' : $target_type;
                $expires_val = ($expires_at && strtotime($expires_at)) ? $expires_at : null;
                $pdo->prepare(
                    "INSERT INTO notifications (title, message, type, icon, target_type, target_user_ids, action_url, action_text, expires_at)
                     VALUES (?,?,?,?,?,?,?,?,?)"
                )->execute([
                    $title, $message, $type,
                    $icon ?: null,
                    $db_target_type,
                    $target_user_ids,
                    $action_url ?: null,
                    $action_text ?: null,
                    $expires_val
                ]);

                // Count recipients for flash
                if ($target_type === 'all') {
                    $cnt = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE is_active=1")->fetchColumn();
                    $flash = "✅ Notifikasi terkirim ke semua {$cnt} pengguna aktif!";
                } else {
                    $ids_arr = json_decode($target_user_ids ?? '[]', true);
                    $cnt = count($ids_arr);
                    $flash = "✅ Notifikasi terkirim ke {$cnt} pengguna!";
                }
            }
        }
    }

    if ($action === 'delete_notif') {
        $id = (int)($_POST['notif_id'] ?? 0);
        if ($id) {
            $pdo->prepare("DELETE FROM notification_reads WHERE notification_id=?")->execute([$id]);
            $pdo->prepare("DELETE FROM notifications WHERE id=?")->execute([$id]);
            $flash = 'Notifikasi dihapus.';
        }
    }
}

// ── Load recent notifications ─────────────────────────────────────────────────
$notifs = $pdo->query(
    "SELECT n.*,
            (SELECT COUNT(*) FROM notification_reads WHERE notification_id=n.id) as read_count
     FROM notifications n
     ORDER BY n.created_at DESC
     LIMIT 100"
)->fetchAll();

// User count for stats
$total_users = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE is_active=1")->fetchColumn();
$users_list  = $pdo->query("SELECT id, username, email FROM users WHERE is_active=1 ORDER BY username ASC")->fetchAll();
$memberships_list = $pdo->query("SELECT id, name FROM memberships ORDER BY sort_order ASC")->fetchAll();

$pageTitle  = 'Push Notifikasi';
$activePage = 'notifications';
require __DIR__ . '/partials/header.php';

$type_cfg = [
    'info'     => ['bg' => '#BAE6FD', 'label' => 'Info',       'icon' => 'ℹ️'],
    'success'  => ['bg' => '#BBF7D0', 'label' => 'Sukses',     'icon' => '✅'],
    'warning'  => ['bg' => '#FED7AA', 'label' => 'Peringatan', 'icon' => '⚠️'],
    'alert'    => ['bg' => '#FCA5A5', 'label' => 'Alert',      'icon' => '🚨'],
    'congrats' => ['bg' => '#FFE566', 'label' => 'Selamat',    'icon' => '🎉'],
];
$target_labels = [
    'all'            => ['lbl' => 'Semua User',         'icon' => '👥'],
    'level'          => ['lbl' => 'Level Tertentu',     'icon' => '⭐'],
    'single'         => ['lbl' => 'Satu User',          'icon' => '👤'],
    'selected'       => ['lbl' => 'User Tertentu',      'icon' => '🎯'],
    'has_balance'    => ['lbl' => 'User Bersaldo',      'icon' => '💰'],
    'has_membership' => ['lbl' => 'User Berlangganan',  'icon' => '👑'],
];
?>

<div class="d-flex align-items-center justify-content-between mb-4">
  <div>
    <h5 class="mb-0 fw-bold">🔔 Push Notifikasi</h5>
    <small class="text-secondary"><?= $total_users ?> pengguna aktif terdaftar</small>
  </div>
</div>

<?php if ($flash): ?>
<div class="alert alert-<?= ($flashType === 'error') ? 'danger' : 'success' ?> py-2 mb-3"
     style="border-radius:10px;font-size:13px;border:none;background:<?= $flashType==='error'?'rgba(239,68,68,.15)':'rgba(34,197,94,.15)' ?>;color:<?= $flashType==='error'?'#f87171':'#4ade80' ?>">
  <?= htmlspecialchars($flash) ?>
</div>
<?php endif; ?>

<div class="row g-3 mb-4">
  <!-- ── Send Form ── -->
  <div class="col-lg-6">
    <div class="c-card h-100">
      <div class="c-card-header">
        <span class="c-card-title">📤 Kirim Notifikasi Baru</span>
      </div>
      <div class="c-card-body">
        <form method="POST" id="notif-form">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="send_notif">

          <!-- Title -->
          <div class="c-form-group mb-3">
            <label class="c-label">Judul <span style="color:#f87171">*</span></label>
            <input type="text" name="title" class="c-form-control" placeholder="Cth: Selamat! Reward kamu bertambah 🎉" required maxlength="255" id="notif-title">
          </div>

          <!-- Message -->
          <div class="c-form-group mb-3">
            <label class="c-label">Pesan <span style="color:#f87171">*</span></label>
            <textarea name="message" class="c-form-control" rows="3" placeholder="Tulis pesan notifikasi..." required id="notif-msg" style="resize:vertical"></textarea>
          </div>

          <!-- Type & Icon row -->
          <div class="row g-2 mb-3">
            <div class="col-7">
              <label class="c-label">Tipe</label>
              <select name="type" class="c-form-control" id="notif-type" onchange="updatePreview()">
                <?php foreach ($type_cfg as $k => $v): ?>
                <option value="<?= $k ?>"><?= $v['icon'] ?> <?= $v['label'] ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-5">
              <label class="c-label">Icon (emoji)</label>
              <input type="text" name="icon" class="c-form-control" placeholder="🎉" maxlength="10" id="notif-icon"
                     style="font-size:20px;text-align:center" oninput="updatePreview()">
            </div>
          </div>

          <!-- Target -->
          <div class="c-form-group mb-3">
            <label class="c-label">Target Penerima</label>
            <select name="target_type" class="c-form-control" id="target-type" onchange="toggleTargetFields()">
              <?php foreach ($target_labels as $k => $v): ?>
              <option value="<?= $k ?>"><?= $v['icon'] ?> <?= $v['lbl'] ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          
          <!-- Specific Level -->
          <div id="field-level" class="c-form-group mb-3" style="display:none">
            <label class="c-label">Pilih Level</label>
            <select name="target_level_id" class="c-form-control">
              <?php foreach ($memberships_list as $m): ?>
              <option value="<?= $m['id'] ?>"><?= htmlspecialchars($m['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <!-- Single user -->
          <div id="field-single" class="c-form-group mb-3" style="display:none">
            <label class="c-label">Username / Email</label>
            <input type="text" name="single_user" class="c-form-control" placeholder="username atau email" list="users-list">
            <datalist id="users-list">
              <?php foreach ($users_list as $u): ?>
              <option value="<?= htmlspecialchars($u['username']) ?>">
              <?php endforeach; ?>
            </datalist>
          </div>

          <!-- Selected IDs -->
          <div id="field-selected" class="c-form-group mb-3" style="display:none">
            <label class="c-label">User ID (pisahkan dengan koma)</label>
            <textarea name="selected_ids" class="c-form-control" rows="2" placeholder="1, 5, 23, 45"></textarea>
            <div style="font-size:11px;color:#666;margin-top:4px">
              <strong>Referensi user:</strong>
              <?php foreach (array_slice($users_list, 0, 8) as $u): ?>
              <span style="font-size:10px;opacity:.7">#<?= $u['id'] ?> <?= htmlspecialchars($u['username']) ?></span>
              <?php endforeach; ?>
              <?php if (count($users_list) > 8): ?><em style="font-size:10px">+<?= count($users_list)-8 ?> lainnya</em><?php endif; ?>
            </div>
          </div>

          <!-- Has balance min -->
          <div id="field-balance" class="c-form-group mb-3" style="display:none">
            <label class="c-label">Minimal Saldo (Rp, 0 = semua yg bersaldo)</label>
            <input type="number" name="min_balance" class="c-form-control" value="0" min="0" step="1000">
          </div>

          <!-- Action URL / Text -->
          <div class="row g-2 mb-3">
            <div class="col-7">
              <label class="c-label">URL Aksi (opsional)</label>
              <input type="text" name="action_url" class="c-form-control" placeholder="/upgrade">
            </div>
            <div class="col-5">
              <label class="c-label">Teks Tombol</label>
              <input type="text" name="action_text" class="c-form-control" placeholder="Lihat →">
            </div>
          </div>

          <!-- Expires -->
          <div class="c-form-group mb-4">
            <label class="c-label">Kedaluwarsa (opsional)</label>
            <input type="datetime-local" name="expires_at" class="c-form-control">
            <div style="font-size:11px;color:#666;margin-top:3px">Kosongkan = tidak kedaluwarsa</div>
          </div>

          <button type="submit" class="btn btn-sm text-white w-100" style="background:var(--brand);font-weight:700;font-size:14px;padding:12px">
            🚀 Kirim Notifikasi
          </button>
        </form>
      </div>
    </div>
  </div>

  <!-- ── Live Preview ── -->
  <div class="col-lg-6">
    <div class="c-card" style="position:sticky;top:80px">
      <div class="c-card-header">
        <span class="c-card-title">👁️ Preview</span>
      </div>
      <div class="c-card-body">
        <div id="preview-box" style="
          border:2.5px solid #1A1A1A;
          border-radius:14px;
          padding:16px;
          background:#BBF7D0;
          box-shadow:4px 4px 0 #1A1A1A;
          display:flex;gap:12px;align-items:flex-start
        ">
          <div id="prev-icon" style="
            font-size:24px;width:44px;height:44px;
            background:#fff;border:2px solid #1A1A1A;border-radius:10px;
            display:flex;align-items:center;justify-content:center;
            box-shadow:2px 2px 0 #1A1A1A;flex-shrink:0
          ">🎉</div>
          <div style="flex:1;min-width:0">
            <div id="prev-title" style="font-weight:900;font-size:14px;margin-bottom:3px">Judul notifikasi</div>
            <div id="prev-msg" style="font-size:12px;color:#444;line-height:1.5">Pesan notifikasi akan tampil di sini...</div>
            <div style="font-size:10px;color:#666;margin-top:6px;font-weight:700">Baru saja</div>
          </div>
        </div>

        <!-- Quick templates -->
        <div style="margin-top:16px">
          <div class="c-label" style="margin-bottom:8px">⚡ Template Cepat</div>
          <div style="display:flex;flex-wrap:wrap;gap:6px">
            <?php
            $templates = [
              ['icon'=>'🎉','type'=>'congrats','title'=>'Selamat! Reward diterima','msg'=>'Reward kamu sudah ditambahkan ke saldo. Terus tonton video untuk dapat lebih banyak!'],
              ['icon'=>'💰','type'=>'success','title'=>'Penarikan diproses','msg'=>'Penarikan kamu sedang diproses. Dana akan masuk dalam 1×24 jam.'],
              ['icon'=>'⚠️','type'=>'warning','title'=>'Verifikasi akun','msg'=>'Lengkapi data akun kamu agar bisa melakukan penarikan.'],
              ['icon'=>'🔥','type'=>'info','title'=>'Video baru tersedia!','msg'=>'Ada video baru yang bisa kamu tonton hari ini. Yuk dapatkan rewardnya!'],
              ['icon'=>'👑','type'=>'info','title'=>'Promo Upgrade!','msg'=>'Upgrade ke Platinum sekarang dan nikmati 100 video/hari dengan reward lebih besar!'],
            ];
            foreach ($templates as $t):
            ?>
            <button type="button" class="btn btn-sm b-neutral"
              style="border-radius:8px;font-size:11px;border:none"
              onclick="applyTemplate(<?= htmlspecialchars(json_encode($t)) ?>)">
              <?= $t['icon'] ?> <?= $t['title'] ?>
            </button>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- ── History ── -->
<div class="c-card">
  <div class="c-card-header d-flex justify-content-between align-items-center">
    <span class="c-card-title">📋 Riwayat Notifikasi</span>
    <small class="text-secondary"><?= count($notifs) ?> total</small>
  </div>
  <div class="c-card-body" style="padding:0">
    <?php if (empty($notifs)): ?>
    <div style="text-align:center;padding:40px;color:#555">Belum ada notifikasi dikirim.</div>
    <?php else: ?>
    <div style="overflow-x:auto">
      <table class="c-table">
        <thead>
          <tr>
            <th>Judul</th>
            <th>Tipe</th>
            <th>Target</th>
            <th>Dibaca</th>
            <th>Dikirim</th>
            <th>Kedaluwarsa</th>
            <th>Aksi</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($notifs as $n):
            $tc = $type_cfg[$n['type']] ?? $type_cfg['info'];
            $tl = $target_labels[$n['target_type']] ?? ['lbl'=>$n['target_type'],'icon'=>'?'];
            $ids = $n['target_user_ids'] ? json_decode($n['target_user_ids'], true) : [];
            $target_count = $n['target_type'] === 'all' ? $total_users : count($ids);
          ?>
          <tr>
            <td>
              <div style="display:flex;align-items:center;gap:6px">
                <span style="font-size:16px"><?= $n['icon'] ?: $tc['icon'] ?></span>
                <div>
                  <div style="font-weight:700;font-size:13px"><?= htmlspecialchars($n['title']) ?></div>
                  <div style="font-size:11px;color:#666;max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= htmlspecialchars($n['message']) ?></div>
                </div>
              </div>
            </td>
            <td>
              <span class="badge" style="background:<?= $tc['bg'] ?>;color:#1A1A1A;border-radius:6px;font-size:11px;border:1.5px solid #1A1A1A">
                <?= $tc['icon'] ?> <?= $tc['label'] ?>
              </span>
            </td>
            <td>
              <div style="font-size:12px"><?= $tl['icon'] ?> <?= $tl['lbl'] ?></div>
              <div style="font-size:11px;color:#666"><?= $target_count ?> user</div>
            </td>
            <td>
              <div style="font-size:13px;font-weight:700;color:#4ade80"><?= (int)$n['read_count'] ?></div>
              <div style="font-size:10px;color:#666">dari <?= $target_count ?></div>
            </td>
            <td style="font-size:12px;color:#888"><?= date('d M Y\nH:i', strtotime($n['created_at'])) ?></td>
            <td style="font-size:12px;color:#888">
              <?= $n['expires_at'] ? date('d M Y', strtotime($n['expires_at'])) : '<span style="color:#555">—</span>' ?>
            </td>
            <td>
              <form method="POST" onsubmit="return confirm('Hapus notifikasi ini?')">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete_notif">
                <input type="hidden" name="notif_id" value="<?= $n['id'] ?>">
                <button class="btn btn-sm b-danger" style="border-radius:8px;font-size:11px;border:none">🗑️ Hapus</button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
</div>

<script>
const typeBg = {
  info:     '#BAE6FD',
  success:  '#BBF7D0',
  warning:  '#FED7AA',
  alert:    '#FCA5A5',
  congrats: '#FFE566'
};
const typeIcon = {
  info:'ℹ️', success:'✅', warning:'⚠️', alert:'🚨', congrats:'🎉'
};

function updatePreview() {
  const title = document.getElementById('notif-title').value || 'Judul notifikasi';
  const msg   = document.getElementById('notif-msg').value || 'Pesan notifikasi akan tampil di sini...';
  const type  = document.getElementById('notif-type').value;
  const icon  = document.getElementById('notif-icon').value || typeIcon[type];

  document.getElementById('preview-box').style.background = typeBg[type];
  document.getElementById('prev-icon').textContent = icon;
  document.getElementById('prev-title').textContent = title;
  document.getElementById('prev-msg').textContent = msg;
}

function toggleTargetFields() {
  const t = document.getElementById('target-type').value;
  document.getElementById('field-single').style.display   = t === 'single'   ? '' : 'none';
  document.getElementById('field-selected').style.display = t === 'selected' ? '' : 'none';
  document.getElementById('field-balance').style.display  = t === 'has_balance' ? '' : 'none';
  document.getElementById('field-level').style.display    = t === 'level' ? '' : 'none';
}

function applyTemplate(t) {
  document.getElementById('notif-title').value = t.title;
  document.getElementById('notif-msg').value   = t.msg;
  document.getElementById('notif-icon').value  = t.icon;
  document.getElementById('notif-type').value  = t.type;
  updatePreview();
}

// Live preview
document.getElementById('notif-title').addEventListener('input', updatePreview);
document.getElementById('notif-msg').addEventListener('input', updatePreview);
updatePreview();
toggleTargetFields();
</script>

<?php require __DIR__ . '/partials/footer.php'; ?>
