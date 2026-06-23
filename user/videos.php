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
                <i class="ph-fill ph-check-circle" style="color:var(--green)"></i>
              <?php else: ?>
                <i class="ph-fill ph-play-circle" style="color:var(--white)"></i>
              <?php endif; ?>
            </div>
            <div class="vcard__badge <?= $done ? 'vcard__badge--done' : '' ?>">
              <?= $done ? '✓ Done' : '+'.format_rp((float)$v['reward_amount']) ?>
            </div>
          </div>
          <div class="vcard__info">
            <div class="vcard__title"><?= htmlspecialchars($v['title']) ?></div>
            <div class="vcard__meta">
              <span class="vcard__reward <?= $done ? 'vcard__reward--done' : '' ?>">
                <?= $done ? '<i class="ph-bold ph-check"></i> Claimed' : '<i class="ph-bold ph-coins" style="color:var(--yellow)"></i> '.format_rp((float)$v['reward_amount']) ?>
              </span>
              <span style="display:flex;align-items:center;gap:2px"><i class="ph-bold ph-clock" style="color:var(--sky)"></i> <?= $v['watch_duration'] ?>s</span>
            </div>
          </div>
        </a>
        <?php
    }
    exit;
}

$pageTitle  = 'Tonton Video — NontonKuy';
$activePage = 'videos';
require dirname(__DIR__) . '/partials/header.php';
?>

<div class="section-header" style="margin-bottom:12px">
  <div class="section-title" style="display:flex;align-items:center;gap:6px;font-size:16px">
    <i class="ph-fill ph-film-strip" style="color:var(--brand)"></i> Semua Video
  </div>
  <p style="font-size:11px;font-weight:700;color:#666;margin:0">Tonton video untuk kumpulkan reward</p>
</div>

<!-- Progress bar -->
<div style="background:var(--white);border:2.5px solid var(--ink);border-radius:14px;box-shadow:4px 4px 0 var(--ink);padding:14px;margin-bottom:16px">
  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px">
    <span style="font-size:12px;font-weight:900"><i class="ph-fill ph-chart-pie-slice" style="color:var(--blue)"></i> Progres Hari Ini</span>
    <span style="font-size:12px;font-weight:900;color:var(--brand)"><?= $watch_today ?>/<?= $watch_limit ?></span>
  </div>
  <div style="background:#eee;border-radius:20px;height:6px;overflow:hidden;border:1.5px solid var(--ink)">
    <?php $pct = $watch_limit > 0 ? min(100, round(($watch_today / $watch_limit) * 100)) : 0; ?>
    <div style="background:<?= $pct >= 100 ? 'var(--salmon)' : 'var(--green)' ?>;height:100%;width:<?= $pct ?>%;border-radius:20px;transition:width .5s"></div>
  </div>
  <?php if ($watch_today >= $watch_limit): ?>
  <div style="font-size:11px;color:var(--ink);margin-top:8px;font-weight:800;background:var(--yellow);padding:6px;border-radius:6px;border:1.5px solid var(--ink)">
    <i class="ph-bold ph-warning-circle"></i> Limit tercapai! <a href="/upgrade" style="color:var(--ink);font-weight:900;text-decoration:underline">Upgrade →</a>
  </div>
  <?php endif; ?>
</div>

<?php if (empty($videos)): ?>
<div class="card" style="margin-bottom:16px">
  <div class="empty-state">
    <i class="ph-fill ph-video-camera" style="font-size:36px;color:var(--ink);opacity:0.3"></i>
    <p style="font-size:13px;font-weight:800;margin-top:6px;color:var(--ink)">Belum ada video tersedia.</p>
  </div>
</div>
<?php else: ?>

<style>
.vgrid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px; margin-bottom: 16px; }
.vcard { flex: 0 0 100%; text-decoration: none; display: flex; flex-direction: column; background: var(--white); border: 2.5px solid var(--ink); border-radius: 12px; overflow: hidden; box-shadow: 3px 3px 0 var(--ink); transition: transform 0.1s; }
.vcard:active { transform: translate(2px, 2px); box-shadow: 1px 1px 0 var(--ink); }
.vcard--done { opacity: 0.7; filter: grayscale(50%); }
.vcard__thumb { position: relative; aspect-ratio: 16/9; background: #000; border-bottom: 2px solid var(--ink); }
.vcard__thumb img { width: 100%; height: 100%; object-fit: cover; opacity: 0.9; transition: opacity 0.2s; }
.vcard:hover .vcard__thumb img { opacity: 1; }
.vcard__badge { position: absolute; top: 6px; right: 6px; background: var(--brand); color: #fff; font-size: 10px; font-weight: 900; padding: 2px 6px; border-radius: 6px; border: 1.5px solid var(--ink); }
.vcard__badge--done { background: var(--green); }
.vcard__play { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; }
.vcard__play i { font-size: 36px; color: #fff; filter: drop-shadow(0 2px 4px rgba(0,0,0,0.5)); transition: transform 0.2s; }
.vcard:hover .vcard__play i { transform: scale(1.1); }
.vcard__info { padding: 10px; display: flex; flex-direction: column; gap: 6px; }
.vcard__title { font-size: 12px; font-weight: 800; color: var(--ink); display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; line-height: 1.3; height: 31px; }
.vcard__meta { display: flex; align-items: center; justify-content: space-between; font-size: 10px; font-weight: 800; color: #555; }
.vcard__reward { color:var(--brand); display:flex; align-items:center; gap:2px; }
.vcard__reward--done { color:var(--green); }
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
        <i class="ph-fill ph-check-circle" style="color:var(--green)"></i>
      <?php else: ?>
        <i class="ph-fill ph-play-circle" style="color:var(--white)"></i>
      <?php endif; ?>
    </div>
    <div class="vcard__badge <?= $done ? 'vcard__badge--done' : '' ?>">
      <?= $done ? '✓ Done' : '+'.format_rp((float)$v['reward_amount']) ?>
    </div>
  </div>
  <div class="vcard__info">
    <div class="vcard__title"><?= htmlspecialchars($v['title']) ?></div>
    <div class="vcard__meta">
      <span class="vcard__reward <?= $done ? 'vcard__reward--done' : '' ?>">
        <?= $done ? '<i class="ph-bold ph-check"></i> Claimed' : '<i class="ph-bold ph-coins" style="color:var(--yellow)"></i> '.format_rp((float)$v['reward_amount']) ?>
      </span>
      <span style="display:flex;align-items:center;gap:2px"><i class="ph-bold ph-clock" style="color:var(--sky)"></i> <?= $v['watch_duration'] ?>s</span>
    </div>
  </div>
</a>
<?php endforeach; ?>
</div>

<div id="loader" style="text-align:center;padding:20px;display:none">
  <i class="ph-bold ph-spinner ph-spin" style="font-size:24px;color:var(--ink)"></i>
  <div style="font-size:11px;font-weight:700;margin-top:8px">Memuat video...</div>
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
