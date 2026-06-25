<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/auth/guard.php';

$watch_limit = user_watch_limit($pdo, $user);
$watch_today = user_watch_today($pdo, $user);

// Determine Sort Order
$sort_mode = setting($pdo, 'video_sort_mode', 'default');
$order_by = 'v.sort_order ASC, v.id DESC';
if ($sort_mode === 'newest') $order_by = 'v.id DESC';
if ($sort_mode === 'oldest') $order_by = 'v.id ASC';
if ($sort_mode === 'reward_desc') $order_by = 'v.reward_amount DESC, v.id DESC';
if ($sort_mode === 'reward_asc') $order_by = 'v.reward_amount ASC, v.id DESC';
if ($sort_mode === 'duration_asc') $order_by = 'v.watch_duration ASC, v.id DESC';
if ($sort_mode === 'random') $order_by = 'RAND()';

$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 10;
$offset = ($page - 1) * $limit;

// All active videos with watch status for today
$videos = $pdo->prepare(
    "SELECT v.*,
       (SELECT COUNT(*) FROM watch_history wh
        WHERE wh.user_id=? AND wh.video_id=v.id AND DATE(wh.watched_at)=CURDATE()) AS watched_today
     FROM videos v
     WHERE v.is_active=1
     ORDER BY {$order_by}
     LIMIT {$limit} OFFSET {$offset}"
);
$videos->execute([$user['id']]);
$videos = $videos->fetchAll();

if (isset($_GET['ajax'])) {
    if (empty($videos)) {
        echo '';
        exit;
    }
    foreach ($videos as $v) {
        $done    = (bool)$v['watched_today'];
        $blocked = !$done && ($watch_today >= $watch_limit);
        $href    = ($done || $blocked) ? 'javascript:void(0)' : '/watch?id='.$v['id'];
        ?>
        <a href="<?= $href ?>" class="vcard <?= $done ? 'vcard--done' : '' ?>" <?= ($done||$blocked) ? 'style="pointer-events:none"' : '' ?>>
          <div class="vcard__thumb">
            <img src="<?= yt_thumb($v['youtube_id']) ?>" alt="<?= htmlspecialchars($v['title']) ?>" loading="lazy" onerror="this.src='https://img.youtube.com/vi/<?= $v['youtube_id'] ?>/hqdefault.jpg'">
            <div class="vcard__play">
              <?php if ($done): ?>
                <i class="ph-fill ph-check-circle" style="color:#10b981; filter: drop-shadow(0 2px 4px rgba(0,0,0,0.5)); font-size:42px;"></i>
              <?php else: ?>
                <div class="vcard__play-btn"><i class="ph-fill ph-play"></i></div>
              <?php endif; ?>
            </div>
            <div class="vcard__badge <?= $done ? 'vcard__badge--done' : '' ?>">
              <?= $done ? '✓ Selesai' : '+'.format_rp((float)$v['reward_amount']) ?>
            </div>
          </div>
          <div class="vcard__info">
            <div class="vcard__title"><?= htmlspecialchars($v['title']) ?></div>
            <div class="vcard__meta">
              <span class="vcard__reward <?= $done ? 'vcard__reward--done' : '' ?>">
                <?php if ($done): ?>
                  <i class="ph-bold ph-check"></i> Selesai
                <?php else: ?>
                  <img src="/assets/dollar.png" style="width:16px;height:16px;object-fit:contain" alt="Coins">
                  <?= format_rp((float)$v['reward_amount']) ?>
                <?php endif; ?>
              </span>
              <span style="display:flex;align-items:center;gap:4px;color:#94a3b8;"><i class="ph-bold ph-clock" style="color:#0ea5e9"></i> <?= $v['watch_duration'] ?>s</span>
            </div>
          </div>
        </a>
        <?php
    }
    exit;
}

$pageTitle  = 'Tonton Video — Meloton';
$activePage = 'videos';
require dirname(__DIR__) . '/partials/header.php';
?>

<div class="section-header" style="margin-bottom:16px; background: #fff; padding: 14px 16px; border: 3px solid #7dd3e8; border-radius: 20px; box-shadow: 0 6px 0 #7dd3e8;">
  <div class="section-title" style="display:flex;align-items:center;gap:8px;font-size:18px; color: #0369a1; font-weight: 900;">
    <div style="background:#e0f9ff; width:36px; height:36px; border-radius:12px; display:flex; align-items:center; justify-content:center; color:#0891b2; font-size:20px;">
        <i class="ph-fill ph-film-strip"></i>
    </div>
    Semua Video
  </div>
  <p style="font-size:12px;font-weight:700;color:#64748b;margin:6px 0 0">Tonton video untuk kumpulkan reward</p>
</div>

<!-- Progress bar -->
<div style="background:linear-gradient(135deg, #0ea5e9, #0284c7);border:3px solid #fff;border-radius:20px;box-shadow:0 8px 0 #0369a1;padding:16px;margin-bottom:20px; color:#fff;">
  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px">
    <span style="font-size:13px;font-weight:900; display:flex; align-items:center; gap:6px;">
        <i class="ph-fill ph-chart-pie-slice" style="color:#fde047; font-size:20px;"></i> Progres Hari Ini
    </span>
    <span style="font-size:14px;font-weight:900;background:rgba(255,255,255,0.2); padding:4px 10px; border-radius:12px;"><?= $watch_today ?>/<?= $watch_limit ?></span>
  </div>
  <div style="background:rgba(255,255,255,0.3);border-radius:20px;height:10px;overflow:hidden;border:2px solid rgba(255,255,255,0.5); box-shadow:inset 0 2px 4px rgba(0,0,0,0.1);">
    <?php $pct = $watch_limit > 0 ? min(100, round(($watch_today / $watch_limit) * 100)) : 0; ?>
    <div style="background:<?= $pct >= 100 ? 'linear-gradient(135deg, #34d399, #10b981)' : 'linear-gradient(135deg, #fde047, #f59e0b)' ?>;height:100%;width:<?= $pct ?>%;border-radius:20px;transition:width .5s cubic-bezier(0.4, 0, 0.2, 1);"></div>
  </div>
  <?php if ($watch_today >= $watch_limit): ?>
  <div style="font-size:12px;color:#78350f;margin-top:12px;font-weight:800;background:linear-gradient(135deg, #fde047, #f59e0b);padding:10px;border-radius:12px;border:2px solid #d97706; display:flex; align-items:center; gap:6px;">
    <i class="ph-bold ph-warning-circle" style="font-size:16px;"></i> Limit tercapai! <a href="/upgrade" style="color:#78350f;font-weight:900;text-decoration:none;border-bottom:2px solid #78350f;">Upgrade →</a>
  </div>
  <?php endif; ?>
</div>

<?php if (empty($videos)): ?>
<div style="background:#fff; border:3px solid #cbd5e1; border-radius:24px; padding:32px 16px; text-align:center; box-shadow:0 6px 0 #cbd5e1; margin-bottom:16px">
  <div style="width:64px;height:64px;background:#f1f5f9;border-radius:20px;display:flex;align-items:center;justify-content:center;margin:0 auto 12px;color:#94a3b8;font-size:32px;">
    <i class="ph-fill ph-video-camera"></i>
  </div>
  <h3 style="font-size:16px;font-weight:900;color:#334155;margin:0 0 4px;">Video Kosong</h3>
  <p style="font-size:13px;font-weight:700;color:#64748b;margin:0">Belum ada video tersedia saat ini.</p>
</div>
<?php else: ?>

<style>
/* Casual Game Grid & Cards */
.vgrid { 
    display: grid; 
    grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); 
    gap: 16px; 
    margin-bottom: 24px; 
}
.vcard { 
    text-decoration: none; 
    display: flex; 
    flex-direction: column; 
    background: #fff; 
    border: 3px solid #cbd5e1; 
    border-radius: 20px; 
    overflow: hidden; 
    box-shadow: 0 6px 0 #cbd5e1; 
    transition: transform 0.2s cubic-bezier(0.34, 1.56, 0.64, 1); 
    position: relative;
}
.vcard:active { transform: translateY(4px); box-shadow: 0 2px 0 #cbd5e1; }
.vcard--done { opacity: 0.6; filter: grayscale(80%); }

/* Thumbnails */
.vcard__thumb { 
    position: relative; 
    aspect-ratio: 16/9; 
    background: #e2e8f0; 
    border-bottom: 3px solid #cbd5e1; 
    overflow: hidden;
}
.vcard__thumb img { 
    width: 100%; 
    height: 100%; 
    object-fit: cover; 
    transition: transform 0.3s ease; 
}
.vcard:hover .vcard__thumb img { transform: scale(1.05); }

/* Badges */
.vcard__badge { 
    position: absolute; 
    top: 8px; 
    right: 8px; 
    background: linear-gradient(135deg, #0ea5e9, #0284c7); 
    color: #fff; 
    font-size: 11px; 
    font-weight: 900; 
    padding: 4px 8px; 
    border-radius: 10px; 
    border: 2px solid #fff; 
    box-shadow: 0 4px 0 rgba(0,0,0,0.15); 
    z-index: 2;
}
.vcard__badge--done { 
    background: linear-gradient(135deg, #34d399, #10b981); 
}

/* Play Button */
.vcard__play { 
    position: absolute; 
    inset: 0; 
    display: flex; 
    align-items: center; 
    justify-content: center; 
    background: rgba(15, 23, 42, 0.2); 
    opacity: 0; 
    transition: opacity 0.2s ease;
    z-index: 1;
}
.vcard:hover .vcard__play { opacity: 1; }
.vcard--done:hover .vcard__play { opacity: 1; background: transparent; }

.vcard__play-btn {
    width: 44px; height: 44px;
    background: linear-gradient(135deg, #fde047, #f59e0b);
    border: 3px solid #fff;
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    color: #78350f; font-size: 20px;
    box-shadow: 0 4px 8px rgba(0,0,0,0.3);
    transform: scale(0.8); transition: transform 0.2s cubic-bezier(0.34, 1.56, 0.64, 1);
}
.vcard:hover .vcard__play-btn { transform: scale(1); }

/* Info Section */
.vcard__info { 
    padding: 12px; 
    display: flex; 
    flex-direction: column; 
    gap: 8px; 
}
.vcard__title { 
    font-size: 12.5px; 
    font-weight: 800; 
    color: #0f172a; 
    display: -webkit-box; 
    -webkit-line-clamp: 2; 
    -webkit-box-orient: vertical; 
    overflow: hidden; 
    line-height: 1.4; 
    height: 35px; 
}
.vcard__meta { 
    display: flex; 
    align-items: center; 
    justify-content: space-between; 
    font-size: 11px; 
    font-weight: 800; 
    color: #64748b; 
    border-top: 2px dashed #e2e8f0;
    padding-top: 8px;
    margin-top: 4px;
}
.vcard__reward { 
    color: #d97706; 
    display: flex; 
    align-items: center; 
    gap: 4px; 
}
.vcard__reward--done { 
    color: #10b981; 
}
</style>

<div class="vgrid" id="vgrid">
<?php foreach ($videos as $v):
  $done    = (bool)$v['watched_today'];
  $blocked = !$done && ($watch_today >= $watch_limit);
  $href    = ($done || $blocked) ? 'javascript:void(0)' : '/watch?id='.$v['id'];
?>
<a href="<?= $href ?>" class="vcard <?= $done ? 'vcard--done' : '' ?>" <?= ($done||$blocked) ? 'style="pointer-events:none"' : '' ?>>
  <div class="vcard__thumb">
    <img src="<?= yt_thumb($v['youtube_id']) ?>" alt="<?= htmlspecialchars($v['title']) ?>" loading="lazy" onerror="this.src='https://img.youtube.com/vi/<?= $v['youtube_id'] ?>/hqdefault.jpg'">
    <div class="vcard__play">
      <?php if ($done): ?>
        <i class="ph-fill ph-check-circle" style="color:#10b981; filter: drop-shadow(0 2px 4px rgba(0,0,0,0.5)); font-size:42px;"></i>
      <?php else: ?>
        <div class="vcard__play-btn"><i class="ph-fill ph-play"></i></div>
      <?php endif; ?>
    </div>
    <div class="vcard__badge <?= $done ? 'vcard__badge--done' : '' ?>">
      <?= $done ? '✓ Selesai' : '+'.format_rp((float)$v['reward_amount']) ?>
    </div>
  </div>
  <div class="vcard__info">
    <div class="vcard__title"><?= htmlspecialchars($v['title']) ?></div>
    <div class="vcard__meta">
      <span class="vcard__reward <?= $done ? 'vcard__reward--done' : '' ?>">
        <?php if ($done): ?>
          <i class="ph-bold ph-check"></i> Selesai
        <?php else: ?>
          <img src="/assets/dollar.png" style="width:16px;height:16px;object-fit:contain" alt="Coins">
          <?= format_rp((float)$v['reward_amount']) ?>
        <?php endif; ?>
      </span>
      <span style="display:flex;align-items:center;gap:4px;color:#94a3b8;"><i class="ph-bold ph-clock" style="color:#0ea5e9"></i> <?= $v['watch_duration'] ?>s</span>
    </div>
  </div>
</a>
<?php endforeach; ?>
</div>

<div id="loader" style="text-align:center;padding:20px;display:none">
  <div style="background:#e2e8f0; width:48px; height:48px; border-radius:50%; display:flex; align-items:center; justify-content:center; margin:0 auto; box-shadow:0 4px 0 #cbd5e1;">
    <i class="ph-bold ph-spinner ph-spin" style="font-size:24px;color:#0ea5e9"></i>
  </div>
  <div style="font-size:12px;font-weight:800;color:#64748b;margin-top:12px">Memuat video...</div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
  let page = 1;
  let isLoading = false;
  let hasMore = <?= count($videos) === $limit ? 'true' : 'false' ?>;
  const grid = document.getElementById('vgrid');
  const loader = document.getElementById('loader');

  if (!hasMore) return; // No more pages to load initially

  const observer = new IntersectionObserver((entries) => {
    if (entries[0].isIntersecting && !isLoading && hasMore) {
      loadMore();
    }
  }, { rootMargin: '100px' });

  // Create a sentinel element to observe
  const sentinel = document.createElement('div');
  sentinel.style.height = '1px';
  grid.parentNode.insertBefore(sentinel, grid.nextSibling);
  observer.observe(sentinel);

  async function loadMore() {
    isLoading = true;
    loader.style.display = 'block';
    page++;

    try {
      const res = await fetch(`?ajax=1&page=${page}`);
      const html = await res.text();

      if (html.trim() === '') {
        hasMore = false;
        observer.unobserve(sentinel);
      } else {
        grid.insertAdjacentHTML('beforeend', html);
      }
    } catch (e) {
      console.error('Error loading more videos:', e);
      page--; // revert page count on error
    } finally {
      isLoading = false;
      loader.style.display = 'none';
    }
  }
});
</script>
<?php endif; ?>

<?php require dirname(__DIR__) . '/partials/footer.php'; ?>
