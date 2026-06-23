<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
staff_require('video_analytics');

try {
    // 1. Top Videos (Real dari watch_history)
    $topVideos = $pdo->query(
        "SELECT v.id, v.title, COUNT(wh.id) as watch_count, SUM(wh.reward_given) as total_reward 
         FROM watch_history wh 
         JOIN videos v ON v.id = wh.video_id 
         GROUP BY wh.video_id 
         ORDER BY watch_count DESC LIMIT 50"
    )->fetchAll();

    // 2. Top Viewers (Real dari watch_history)
    $topViewers = $pdo->query(
        "SELECT u.id, u.username, u.email, COUNT(wh.id) as watch_count, SUM(wh.reward_given) as total_reward 
         FROM watch_history wh 
         JOIN users u ON u.id = wh.user_id 
         GROUP BY wh.user_id 
         ORDER BY watch_count DESC LIMIT 50"
    )->fetchAll();

    // Ambil detail apa saja yang ditonton oleh top viewers
    foreach ($topViewers as &$tv) {
        $recent = $pdo->prepare(
            "SELECT v.title, COUNT(wh.id) as cnt, SUM(wh.reward_given) as rwd 
             FROM watch_history wh JOIN videos v ON v.id = wh.video_id 
             WHERE wh.user_id=? 
             GROUP BY wh.video_id ORDER BY cnt DESC LIMIT 10"
        );
        $recent->execute([$tv['id']]);
        $tv['watched_details'] = $recent->fetchAll();
    }
    unset($tv);

} catch (\Throwable $e) {
    $topVideos = [];
    $topViewers = [];
    $error = $e->getMessage();
}

$pageTitle  = 'Analisis Video';
$activePage = 'video_analytics';
require __DIR__ . '/partials/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-4">
  <div><h5 class="mb-0 fw-bold">📊 Analisis Video & Tontonan</h5><small class="text-secondary">Berdasarkan data real time riwayat tontonan user</small></div>
</div>

<?php if (isset($error)): ?>
<div class="alert alert-danger py-2 mb-3" style="border-radius:10px;font-size:13px">Terjadi kesalahan query: <?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<div class="row g-4">
  <!-- Top Videos -->
  <div class="col-md-5">
    <div class="c-card h-100">
      <div class="c-card-header"><span class="c-card-title">🏆 Top Video (Paling Banyak Ditonton)</span></div>
      <div class="c-card-body" style="padding:0; overflow-y:auto; max-height:800px;">
        <?php foreach ($topVideos as $i => $v): ?>
        <div style="padding:14px 20px; border-bottom:1px solid #1a1d27; display:flex; align-items:center; gap:14px">
          <div style="width:28px;height:28px;border-radius:50%;background:<?= $i<3?'rgba(251,188,4,.15)':'rgba(255,255,255,.05)' ?>;color:<?= $i<3?'#FBBC04':'#888' ?>;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:12px;flex-shrink:0">
            <?= $i + 1 ?>
          </div>
          <div style="flex:1;min-width:0">
            <div style="font-size:13.5px;font-weight:600;color:#e0e0f0;margin-bottom:2px;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden"><?= htmlspecialchars($v['title']) ?></div>
            <div style="font-size:11.5px;color:#888">
              <strong style="color:var(--brand)"><?= number_format((int)$v['watch_count']) ?>× ditonton</strong>
            </div>
          </div>
          <div style="text-align:right">
            <div style="font-size:10px;color:#666">Total Reward Diberikan</div>
            <div style="font-size:13px;font-weight:700;color:#4CAF82"><?= format_rp((float)$v['total_reward']) ?></div>
          </div>
        </div>
        <?php endforeach; ?>
        <?php if (empty($topVideos)): ?><div style="padding:30px;text-align:center;color:#666">Belum ada riwayat tontonan.</div><?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Top Viewers -->
  <div class="col-md-7">
    <div class="c-card h-100">
      <div class="c-card-header"><span class="c-card-title">👀 Top Viewers (User Paling Sering Nonton)</span></div>
      <div class="c-card-body" style="padding:0; overflow-y:auto; max-height:800px;">
        <?php foreach ($topViewers as $i => $u): ?>
        <div style="padding:16px 20px; border-bottom:1px solid #1a1d27;">
          <div style="display:flex; align-items:center; gap:14px; margin-bottom:12px">
            <div style="width:36px;height:36px;border-radius:10px;background:var(--brand);display:flex;align-items:center;justify-content:center;color:#fff;font-weight:800;font-size:15px;flex-shrink:0">
              <?= strtoupper(substr($u['username'],0,1)) ?>
            </div>
            <div style="flex:1;min-width:0">
              <div style="font-size:14px;font-weight:700;color:#fff"><?= htmlspecialchars($u['username']) ?></div>
              <div style="font-size:11px;color:#888"><?= htmlspecialchars($u['email']) ?></div>
            </div>
            <div style="text-align:right">
              <div style="font-size:14px;font-weight:800;color:var(--brand)"><?= number_format((int)$u['watch_count']) ?>x Nonton</div>
              <div style="font-size:12px;font-weight:700;color:#4CAF82">Total: <?= format_rp((float)$u['total_reward']) ?></div>
            </div>
            <a href="/console/user_detail.php?id=<?= $u['id'] ?>" class="btn btn-sm" style="background:rgba(255,255,255,.05);color:#ccc;border:1px solid rgba(255,255,255,.1);border-radius:8px;font-size:11px;margin-left:10px">Detail</a>
          </div>
          
          <!-- Detail Video Yg Ditonton -->
          <div style="background:rgba(0,0,0,.2);border-radius:8px;padding:10px;border:1px solid #1f2235">
            <div style="font-size:11px;color:#888;margin-bottom:6px;font-weight:600;text-transform:uppercase;letter-spacing:.5px">Video Paling Sering Ditonton:</div>
            <?php foreach ($u['watched_details'] as $wd): ?>
            <div style="display:flex;justify-content:space-between;align-items:center;padding:4px 0;border-bottom:1px solid rgba(255,255,255,.03)">
              <div style="font-size:12px;color:#ccc;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;flex:1;padding-right:10px">
                <?= htmlspecialchars($wd['title']) ?>
              </div>
              <div style="font-size:11.5px;color:#aaa;flex-shrink:0;text-align:right;width:120px">
                <?= $wd['cnt'] ?>x <span style="color:#4CAF82;margin-left:6px"><?= format_rp((float)$wd['rwd']) ?></span>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endforeach; ?>
        <?php if (empty($topViewers)): ?><div style="padding:30px;text-align:center;color:#666">Belum ada user yang menonton.</div><?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>
