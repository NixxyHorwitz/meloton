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
    } catch (\Throwable $ex) {}
}

$unread_count = 0;
foreach ($notifications as $n) {
    if (!$n['is_read']) $unread_count++;
}

$pageTitle  = 'Notifikasi — Meloton';
$activePage = 'notifications';
require dirname(__DIR__) . '/partials/header.php';

// Type config for casual game style
$type_cfg = [
    'info'     => ['bg' => 'linear-gradient(135deg, #e0f2fe, #bae6fd)', 'icon' => 'ℹ️', 'label' => 'Info', 'color' => '#0284c7', 'border' => '#7dd3e8'],
    'success'  => ['bg' => 'linear-gradient(135deg, #d1fae5, #a7f3d0)', 'icon' => '✅', 'label' => 'Sukses', 'color' => '#059669', 'border' => '#6ee7b7'],
    'warning'  => ['bg' => 'linear-gradient(135deg, #fef08a, #fde047)', 'icon' => '⚠️', 'label' => 'Awas', 'color' => '#b45309', 'border' => '#facc15'],
    'alert'    => ['bg' => 'linear-gradient(135deg, #fee2e2, #fecaca)', 'icon' => '🚨', 'label' => 'Penting', 'color' => '#b91c1c', 'border' => '#fca5a5'],
    'congrats' => ['bg' => 'linear-gradient(135deg, #ede9fe, #ddd6fe)', 'icon' => '🎉', 'label' => 'Selamat', 'color' => '#6d28d9', 'border' => '#c4b5fd'],
];
?>

<style>
/* ══════════════════════════════════════════════
   NOTIFICATIONS — CASUAL GAME STYLE (COMPACT)
   ══════════════════════════════════════════════ */
.notif-page { padding: 0 0 20px; }

/* ── Slim Header ── */
.notif-header {
  background: linear-gradient(135deg, #0ea5e9, #0284c7);
  color: #fff;
  padding: 12px 14px;
  border-radius: 16px;
  border: 3px solid #0369a1;
  box-shadow: 0 4px 0 #075985;
  display: flex; align-items: center; justify-content: space-between;
  position: relative; overflow: hidden;
  margin-bottom: 16px;
}
.notif-header::after {
  content: '🔔';
  position: absolute; right: 80px; top: 50%;
  transform: translateY(-50%) rotate(15deg);
  font-size: 40px; opacity: 0.15; pointer-events: none;
}
.notif-header__left { position: relative; z-index: 1; display:flex; align-items:center; gap:8px; }
.notif-header__icon { background: #fde047; color: #b45309; width: 32px; height: 32px; border-radius: 10px; border: 2px solid #d97706; display:flex; align-items:center; justify-content:center; font-size: 16px; box-shadow: 0 2px 0 #b45309; }
.notif-header__title { font-size: 16px; font-weight: 900; letter-spacing: -0.5px; line-height: 1.1; margin-bottom: 2px; }
.notif-header__sub { font-size: 11px; font-weight: 800; color: #e0f2fe; }

.notif-mark-all {
  position: relative; z-index: 1;
  background: rgba(255,255,255,0.2);
  border: 2px solid #fff;
  color: #fff;
  border-radius: 10px;
  padding: 6px 10px;
  font-size: 10px; font-weight: 900;
  cursor: pointer;
  box-shadow: 0 2px 0 rgba(0,0,0,0.15);
  transition: transform .1s, box-shadow .1s;
  text-transform: uppercase;
  letter-spacing: 0.5px;
  backdrop-filter: blur(4px);
}
.notif-mark-all:active { transform: translateY(2px); box-shadow: 0 0 0 rgba(0,0,0,0.15); }

/* ── Empty State ── */
.notif-empty {
  background: #f8fafc; border: 2.5px dashed #cbd5e1; border-radius: 16px;
  text-align: center; padding: 30px 20px; margin-top: 8px;
}
.notif-empty__icon { font-size: 40px; margin-bottom: 8px; filter: grayscale(1); opacity: 0.5; }
.notif-empty__title { font-weight: 900; font-size: 14px; color: #64748b; margin-bottom: 4px; }
.notif-empty__desc { font-size: 11px; color: #94a3b8; font-weight: 600; }

/* ── Notification List ── */
#notif-list { display: flex; flex-direction: column; gap: 8px; }

.notif-item {
  display: flex;
  gap: 10px;
  align-items: center;
  padding: 10px 12px;
  border: 2.5px solid;
  border-radius: 14px;
  background: #fff;
  position: relative;
  transition: opacity .3s, transform .1s;
}
.notif-item:active { transform: scale(0.98); }
.notif-item--read { opacity: 0.65; border-color: #e2e8f0 !important; box-shadow: none !important; background: #f8fafc; filter: grayscale(0.5); }

.notif-item__icon {
  font-size: 18px;
  flex-shrink: 0;
  width: 34px; height: 34px;
  border: 2px solid;
  border-radius: 10px;
  display: flex; align-items: center; justify-content: center;
}
.notif-item--read .notif-item__icon { border-color: #cbd5e1 !important; background: #f1f5f9 !important; box-shadow: none !important; }

.notif-item__body { flex: 1; min-width: 0; }
.notif-item__header { display: flex; align-items: center; gap: 6px; margin-bottom: 2px; }
.notif-item__unread-dot { width: 8px; height: 8px; background: #ef4444; border-radius: 50%; flex-shrink: 0; border: 1.5px solid #fff; box-shadow: 0 1px 2px rgba(0,0,0,0.2); animation: pulse 2s infinite; }
@keyframes pulse { 0% { transform:scale(0.95); box-shadow:0 0 0 0 rgba(239,68,68,0.7); } 70% { transform:scale(1); box-shadow:0 0 0 4px rgba(239,68,68,0); } 100% { transform:scale(0.95); box-shadow:0 0 0 0 rgba(239,68,68,0); } }

.notif-item__type-badge {
  font-size: 9px; font-weight: 900; text-transform: uppercase;
  padding: 2px 6px; border-radius: 6px;
  border: 1.5px solid; letter-spacing: 0.5px;
}
.notif-item__title { font-weight: 900; font-size: 13px; color: #0f172a; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; flex: 1; }
.notif-item__msg { font-size: 11px; color: #475569; font-weight: 700; line-height: 1.4; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
.notif-item__cta {
  display: inline-flex; align-items: center; gap: 4px;
  margin-top: 6px;
  font-size: 10px; font-weight: 900;
  color: #0369a1; text-decoration: none;
  border: 2px solid #bae6fd; border-radius: 8px;
  padding: 4px 10px; background: #e0f2fe;
  text-transform: uppercase; letter-spacing: 0.5px;
}
.notif-item__time { font-size: 9px; color: #94a3b8; margin-top: 4px; font-weight: 800; display:block; }

.notif-item__read-btn {
  background: #f8fafc;
  border: 2px solid #cbd5e1;
  color: #64748b;
  border-radius: 8px;
  width: 28px; height: 28px;
  display: flex; align-items: center; justify-content: center;
  font-size: 14px; font-weight: 900; cursor: pointer;
  box-shadow: 0 2px 0 #e2e8f0;
  transition: all .1s;
  flex-shrink: 0;
}
.notif-item__read-btn:active { transform: translateY(2px); box-shadow: 0 0 0 #e2e8f0; }
</style>

<div class="notif-page">
  <div class="notif-header">
    <div class="notif-header__left">
      <div class="notif-header__icon"><i class="ph-fill ph-bell-ringing"></i></div>
      <div>
        <div class="notif-header__title">Notifikasi</div>
        <div class="notif-header__sub">
          <?php if ($unread_count > 0): ?><?= $unread_count ?> belum dibaca<?php else: ?>Semua sudah dibaca <i class="ph-bold ph-check"></i><?php endif; ?>
        </div>
      </div>
    </div>
    <?php if ($unread_count > 0): ?>
    <button id="btn-mark-all" class="notif-mark-all" onclick="markAllRead()"><i class="ph-bold ph-checks"></i> Baca Semua</button>
    <?php endif; ?>
  </div>

  <?php if (empty($notifications)): ?>
  <div class="notif-empty">
    <div class="notif-empty__icon"><i class="ph-fill ph-mailbox"></i></div>
    <div class="notif-empty__title">Belum ada notifikasi</div>
    <div class="notif-empty__desc">Notifikasi dari admin akan muncul di sini</div>
  </div>

  <?php else: ?>
  <div id="notif-list">
  <?php foreach ($notifications as $n):
    $cfg = $type_cfg[$n['type']] ?? $type_cfg['info'];
    $icon = $n['icon'] ?: $cfg['icon'];
    $is_read = (bool)$n['is_read'];
  ?>
  <div class="notif-item <?= $is_read ? 'notif-item--read' : '' ?>" data-id="<?= $n['id'] ?>"
       style="border-color: <?= $cfg['border'] ?>; box-shadow: <?= $is_read ? 'none' : '0 4px 0 '.$cfg['border'] ?>;">
    <div class="notif-item__icon" style="background: <?= $cfg['bg'] ?>; border-color: <?= $cfg['border'] ?>; box-shadow: <?= $is_read ? 'none' : '0 2px 0 '.$cfg['border'] ?>;">
      <?= $icon ?>
    </div>
    <div class="notif-item__body">
      <div class="notif-item__header">
        <?php if (!$is_read): ?><span class="notif-item__unread-dot"></span><?php endif; ?>
        <span class="notif-item__type-badge" style="color: <?= $cfg['color'] ?>; border-color: <?= $cfg['border'] ?>; background: <?= $cfg['bg'] ?>;"><?= $cfg['label'] ?></span>
        <div class="notif-item__title"><?= htmlspecialchars($n['title']) ?></div>
      </div>
      <div class="notif-item__msg"><?= nl2br(htmlspecialchars($n['message'])) ?></div>
      <?php if ($n['action_url'] && $n['action_text']): ?>
      <a href="<?= htmlspecialchars($n['action_url']) ?>" class="notif-item__cta">
        <?= htmlspecialchars($n['action_text']) ?> <i class="ph-bold ph-arrow-right"></i>
      </a>
      <?php endif; ?>
      <span class="notif-item__time"><?= date('d M Y, H:i', strtotime($n['created_at'])) ?></span>
    </div>
    <?php if (!$is_read): ?>
    <button class="notif-item__read-btn" onclick="markRead(<?= $n['id'] ?>, this)" title="Tandai dibaca">
      <i class="ph-bold ph-check"></i>
    </button>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>

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
    item.style.boxShadow = 'none';
    
    // Remove the unread dot
    const dot = item.querySelector('.notif-item__unread-dot');
    if (dot) dot.remove();
    btn.remove();
    
    // Update count badge in header if present
    updateBadge(data.count);
    
    // Update subtitle
    const sub = document.querySelector('.notif-header__sub');
    if (sub && data.count > 0) {
      sub.innerHTML = data.count + ' belum dibaca';
    } else if (sub) { 
      sub.innerHTML = 'Semua sudah dibaca <i class="ph-bold ph-check"></i>'; 
      // Also hide the "Baca Semua" button
      const markAllBtn = document.getElementById('btn-mark-all');
      if (markAllBtn) markAllBtn.style.display = 'none';
    }
  }
}

async function markAllRead() {
  const btn = document.getElementById('btn-mark-all');
  if (btn) {
    btn.disabled = true;
    btn.innerHTML = '<i class="ph-bold ph-spinner ph-spin"></i>';
  }
  const fd = new FormData();
  fd.append('action', 'mark_all');
  fd.append('_csrf', CSRF);
  await fetch('/notif_action', { method: 'POST', body: fd });
  // Refresh page
  location.reload();
}

function updateBadge(count) {
  // Try to find any active notification bell badges in the layout (if they exist in header)
  const badges = document.querySelectorAll('.nav-badge, #notif-badge');
  badges.forEach(badge => {
    if (count > 0) { 
      badge.textContent = count > 9 ? '9+' : count; 
      badge.style.display = ''; 
    } else {
      badge.style.display = 'none';
    }
  });
}
</script>

<?php require dirname(__DIR__) . '/partials/footer.php'; ?>
