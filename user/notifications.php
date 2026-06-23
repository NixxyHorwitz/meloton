<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/auth/guard.php';

// ── Fetch notifications for this user ────────────────────────────────────────
$notifications = [];
try {
    $stmt = $pdo->prepare(
        "SELECT n.*, IF(nr.id IS NOT NULL, 1, 0) as is_read
         FROM notifications n
         LEFT JOIN notification_reads nr ON nr.notification_id=n.id AND nr.user_id=?
         WHERE (n.target_type='all' OR (n.target_user_ids IS NOT NULL AND JSON_CONTAINS(n.target_user_ids, JSON_QUOTE(?))))
           AND (n.expires_at IS NULL OR n.expires_at > NOW())
         ORDER BY n.created_at DESC
         LIMIT 50"
    );
    $stmt->execute([$user['id'], (string)$user['id']]);
    $notifications = $stmt->fetchAll();
} catch (\Throwable $e) {
    // Fallback: hanya ambil notif 'all' jika JSON_CONTAINS error
    try {
        $stmt = $pdo->prepare(
            "SELECT n.*, IF(nr.id IS NOT NULL, 1, 0) as is_read
             FROM notifications n
             LEFT JOIN notification_reads nr ON nr.notification_id=n.id AND nr.user_id=?
             WHERE n.target_type='all'
               AND (n.expires_at IS NULL OR n.expires_at > NOW())
             ORDER BY n.created_at DESC LIMIT 50"
        );
        $stmt->execute([$user['id']]);
        $notifications = $stmt->fetchAll();
    } catch (\Throwable) {}
}

$unread_count = 0;
foreach ($notifications as $n) {
    if (!$n['is_read']) $unread_count++;
}

$pageTitle  = 'Notifikasi — NontonKuy';
$activePage = 'notifications';
require dirname(__DIR__) . '/partials/header.php';

// Type config
$type_cfg = [
    'info'     => ['bg' => 'var(--sky)',     'icon' => 'ℹ️',  'label' => 'Info'],
    'success'  => ['bg' => 'var(--lime)',    'icon' => '✅',  'label' => 'Sukses'],
    'warning'  => ['bg' => 'var(--peach)',   'icon' => '⚠️',  'label' => 'Peringatan'],
    'alert'    => ['bg' => 'var(--salmon)',  'icon' => '🚨',  'label' => 'Alert'],
    'congrats' => ['bg' => 'var(--yellow)',  'icon' => '🎉',  'label' => 'Selamat'],
];
?>

<style>
.notif-header {
  background: linear-gradient(135deg, #a78bfa, #7c3aed);
  color: #fff;
  padding: 14px 14px 12px;
  margin: -14px -14px 14px;
  border-bottom: 3px solid var(--ink);
  box-shadow: 0 3px 0 var(--ink);
  display: flex; align-items: center; justify-content: space-between;
  position: relative; overflow: hidden;
}
.notif-header::after {
  content: '🔔';
  position: absolute; right: 56px; top: 50%;
  transform: translateY(-50%);
  font-size: 52px; opacity: 0.12; pointer-events: none;
}
.notif-header__left { position: relative; z-index: 1; }
.notif-header__title { font-size: 20px; font-weight: 900; text-transform: uppercase; letter-spacing: -0.5px; line-height: 1; }
.notif-header__sub { font-size: 11px; font-weight: 700; opacity: 0.85; margin-top: 2px; }
.notif-header__badge {
  background: var(--ink); color: #a78bfa;
  border: 2px solid var(--ink); border-radius: 10px;
  padding: 5px 10px; text-align: center;
  font-weight: 900; font-size: 20px; line-height: 1;
  flex-shrink: 0; position: relative; z-index: 1;
  box-shadow: 2px 2px 0 rgba(0,0,0,0.2);
}
.notif-header__badge small { display: block; font-size: 8px; font-weight: 800; text-transform: uppercase; opacity: 0.8; margin-top: 1px; }
.notif-mark-all {
  position: relative; z-index: 1;
  background: rgba(255,255,255,0.2);
  border: 2px solid #fff;
  color: #fff;
  border-radius: 8px;
  padding: 5px 12px;
  font-size: 11px; font-weight: 900;
  cursor: pointer;
  box-shadow: 2px 2px 0 rgba(0,0,0,0.15);
  transition: transform .1s;
  text-transform: uppercase;
  letter-spacing: 0.3px;
}
.notif-mark-all:active { transform: translate(1px,1px); }
</style>

<div class="notif-header">
  <div class="notif-header__left">
    <div class="notif-header__title">🔔 Notifikasi</div>
    <div class="notif-header__sub">
      <?php if ($unread_count > 0): ?><?= $unread_count ?> belum dibaca<?php else: ?>Semua sudah dibaca ✓<?php endif; ?>
    </div>
  </div>
  <?php if ($unread_count > 0): ?>
  <button id="btn-mark-all" class="notif-mark-all" onclick="markAllRead()">Baca Semua</button>
  <?php else: ?>
  <div class="notif-header__badge"><?= count($notifications) ?><small>Total</small></div>
  <?php endif; ?>
</div>

<?php if (empty($notifications)): ?>
<div style="background:#fff;border:2.5px solid var(--ink);border-radius:12px;box-shadow:4px 4px 0 var(--ink);text-align:center;padding:40px 20px;margin-top:4px">
  <div style="font-size:44px;margin-bottom:10px">📭</div>
  <div style="font-weight:900;font-size:15px;margin-bottom:4px">Belum ada notifikasi</div>
  <div style="font-size:12px;color:#888">Notifikasi dari admin akan muncul di sini</div>
</div>

<?php else: ?>
<div id="notif-list">
<?php foreach ($notifications as $n):
  $cfg = $type_cfg[$n['type']] ?? $type_cfg['info'];
  $icon = $n['icon'] ?: $cfg['icon'];
  $is_read = (bool)$n['is_read'];
?>
<div class="notif-item <?= $is_read ? 'notif-item--read' : '' ?>" data-id="<?= $n['id'] ?>"
     style="background:<?= $is_read ? 'var(--white)' : $cfg['bg'] ?>">
  <div class="notif-item__icon"><?= $icon ?></div>
  <div class="notif-item__body">
    <div style="display:flex;align-items:center;gap:6px;margin-bottom:2px">
      <?php if (!$is_read): ?>
      <span style="width:8px;height:8px;background:var(--brand);border-radius:50%;flex-shrink:0;border:1.5px solid var(--ink)"></span>
      <?php endif; ?>
      <div class="notif-item__title"><?= htmlspecialchars($n['title']) ?></div>
    </div>
    <div class="notif-item__msg"><?= nl2br(htmlspecialchars($n['message'])) ?></div>
    <?php if ($n['action_url'] && $n['action_text']): ?>
    <a href="<?= htmlspecialchars($n['action_url']) ?>" class="notif-item__cta">
      <?= htmlspecialchars($n['action_text']) ?> →
    </a>
    <?php endif; ?>
    <div class="notif-item__time">
      <span class="badge badge--neutral" style="font-size:9px;padding:2px 6px"><?= $cfg['label'] ?></span>
      <?= date('d M Y, H:i', strtotime($n['created_at'])) ?>
    </div>
  </div>
  <?php if (!$is_read): ?>
  <button class="notif-item__read-btn" onclick="markRead(<?= $n['id'] ?>, this)" title="Tandai dibaca">
    ✓
  </button>
  <?php endif; ?>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<style>
/* ── Notif type accent colors ── */
:root {
  --notif-info:    #38bdf8;
  --notif-success: #4ade80;
  --notif-warning: #fbbf24;
  --notif-alert:   #f87171;
  --notif-congrats:#c084fc;
}
.notif-item {
  display: flex;
  gap: 10px;
  align-items: flex-start;
  padding: 12px 12px 10px;
  border: 2.5px solid var(--ink);
  border-radius: 12px;
  box-shadow: 3px 3px 0 var(--ink);
  margin-bottom: 10px;
  position: relative;
  transition: opacity .3s;
  background: #fff;
}
.notif-item--read {
  opacity: .62;
  box-shadow: 2px 2px 0 #bbb;
  border-color: #ccc;
}
.notif-item__accent {
  width: 4px;
  border-radius: 3px;
  flex-shrink: 0;
  align-self: stretch;
  min-height: 40px;
}
.notif-item__icon {
  font-size: 22px;
  flex-shrink: 0;
  width: 38px; height: 38px;
  border: 2px solid var(--ink);
  border-radius: 10px;
  display: flex; align-items: center; justify-content: center;
  box-shadow: 2px 2px 0 var(--ink);
  background: #fff;
}
.notif-item--read .notif-item__icon { box-shadow: none; border-color: #ccc; }
.notif-item__body { flex: 1; min-width: 0; }
.notif-item__header { display: flex; align-items: center; gap: 6px; margin-bottom: 3px; flex-wrap: wrap; }
.notif-item__unread-dot { width: 7px; height: 7px; background: var(--brand); border-radius: 50%; flex-shrink: 0; border: 1.5px solid var(--ink); }
.notif-item__type-badge {
  font-size: 9px; font-weight: 900; text-transform: uppercase;
  padding: 2px 7px; border-radius: 5px;
  border: 1.5px solid var(--ink); letter-spacing: 0.3px;
}
.notif-item__title { font-weight: 900; font-size: 13px; line-height: 1.3; flex: 1; }
.notif-item__msg { font-size: 12px; color: #444; margin-top: 3px; font-weight: 600; line-height: 1.5; }
.notif-item__cta {
  display: inline-block;
  margin-top: 7px;
  font-size: 11px; font-weight: 900;
  color: var(--ink);
  text-decoration: none;
  border: 2px solid var(--ink);
  border-radius: 6px;
  padding: 3px 10px;
  background: var(--yellow);
  box-shadow: 2px 2px 0 var(--ink);
  text-transform: uppercase;
  letter-spacing: 0.3px;
}
.notif-item__time {
  font-size: 10px; color: #888;
  margin-top: 5px; font-weight: 700;
}
.notif-item__read-btn {
  position: absolute;
  top: 10px; right: 10px;
  background: var(--yellow);
  border: 2px solid var(--ink);
  border-radius: 6px;
  width: 26px; height: 26px;
  display: flex; align-items: center; justify-content: center;
  font-size: 12px; font-weight: 900; cursor: pointer;
  box-shadow: 2px 2px 0 var(--ink);
  transition: transform .1s, box-shadow .1s;
}
.notif-item__read-btn:hover { transform: translate(-1px,-1px); box-shadow: 3px 3px 0 var(--ink); }
.notif-item__read-btn:active { transform: translate(1px,1px); box-shadow: 0 0 0 var(--ink); }
</style>

<script>
const CSRF = '<?= csrf_token() ?>';

async function markRead(id, btn) {
  const item = btn.closest('.notif-item');
  const fd = new FormData();
  fd.append('action', 'mark_read');
  fd.append('id', id);
  fd.append('_csrf', CSRF);
  const res = await fetch('/notif_action', { method: 'POST', body: fd });
  const data = await res.json();
  if (data.ok) {
    item.classList.add('notif-item--read');
    item.style.background = 'var(--white)';
    // Remove the unread dot
    const dot = item.querySelector('[style*="width:8px"]');
    if (dot) dot.remove();
    btn.remove();
    // Update count badge in header if present
    updateBadge(data.count);
    // Update subtitle
    const sub = document.querySelector('.page-title-bar p');
    if (sub && data.count > 0) sub.textContent = data.count + ' belum dibaca';
    else if (sub) { sub.textContent = 'Semua sudah dibaca ✓'; sub.style.color = '#22c55e'; }
  }
}

async function markAllRead() {
  const btn = document.getElementById('btn-mark-all');
  if (btn) btn.disabled = true;
  const fd = new FormData();
  fd.append('action', 'mark_all');
  fd.append('_csrf', CSRF);
  await fetch('/notif_action', { method: 'POST', body: fd });
  // Refresh page
  location.reload();
}

function updateBadge(count) {
  const badge = document.getElementById('notif-badge');
  if (!badge) return;
  if (count > 0) { badge.textContent = count > 9 ? '9+' : count; badge.style.display = ''; }
  else badge.style.display = 'none';
}
</script>

<?php require dirname(__DIR__) . '/partials/footer.php'; ?>
