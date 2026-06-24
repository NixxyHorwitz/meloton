<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/bootstrap.php';

$user = auth_user($pdo);
$is_guest = false;
if (!$user) {
    $is_guest = true;
    $user = [
        'id' => 0,
        'username' => 'Tamu',
        'balance_wd' => 0,
        'balance_dep' => 0,
        'membership_id' => null,
        'membership_expires_at' => null,
        'referral_code' => '-',
        'is_promotor' => 0,
        'plinko_coins' => 0,
    ];
}

// Maintenance mode check — block users but not admins
if (is_maintenance($pdo) && !auth_admin()) {
    $maintenance_msg = setting($pdo, 'maintenance_message', 'Sistem sedang dalam perbaikan.');
    require dirname(__DIR__) . '/user/maintenance.php';
    exit;
}

// Track pageview (analytics)
track_pageview($pdo, parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));

$watch_limit = $is_guest ? 0 : user_watch_limit($pdo, $user);
$watch_today = $is_guest ? 0 : user_watch_today($pdo, $user);

// Available videos
if ($is_guest) {
    $videos = $pdo->query("SELECT v.* FROM videos v WHERE v.is_active=1 ORDER BY v.sort_order ASC, v.id DESC LIMIT 6")->fetchAll();
    $history = [];
    $notif_preview = [];
    $notif_unread = 0;
} else {
    $videos = $pdo->prepare(
        "SELECT v.* FROM videos v
         WHERE v.is_active=1
           AND v.id NOT IN (
               SELECT video_id FROM watch_history
               WHERE user_id=? AND DATE(watched_at)=CURDATE()
           )
         ORDER BY v.sort_order ASC, v.id DESC LIMIT 6"
    );
    $videos->execute([$user['id']]);
    $videos = $videos->fetchAll();
    
    // Recent activity
    $history = $pdo->prepare(
        "SELECT wh.reward_given, wh.watched_at, v.title
         FROM watch_history wh
         JOIN videos v ON v.id=wh.video_id
         WHERE wh.user_id=?
         ORDER BY wh.watched_at DESC LIMIT 4"
    );
    $history->execute([$user['id']]);
    $history = $history->fetchAll();
    
    // Unread notifications preview (max 3)
    $notif_preview = [];
    $notif_unread  = 0;
    try {
        $uid = $user['id'];
        $np = $pdo->prepare(
            "SELECT n.* FROM notifications n
             LEFT JOIN notification_reads nr ON nr.notification_id=n.id AND nr.user_id=?
             WHERE nr.id IS NULL
               AND (n.target_type='all' OR (n.target_user_ids IS NOT NULL AND JSON_CONTAINS(n.target_user_ids, JSON_QUOTE(?))))
               AND (n.expires_at IS NULL OR n.expires_at > NOW())
             ORDER BY n.created_at DESC LIMIT 3"
        );
        $np->execute([$uid, (string)$uid]);
        $notif_preview = $np->fetchAll();
        // Total unread count
        $nc = $pdo->prepare(
            "SELECT COUNT(*) FROM notifications n
             LEFT JOIN notification_reads nr ON nr.notification_id=n.id AND nr.user_id=?
             WHERE nr.id IS NULL
               AND (n.target_type='all' OR (n.target_user_ids IS NOT NULL AND JSON_CONTAINS(n.target_user_ids, JSON_QUOTE(?))))
               AND (n.expires_at IS NULL OR n.expires_at > NOW())"
        );
        $nc->execute([$uid, (string)$uid]);
        $notif_unread = (int)$nc->fetchColumn();
    } catch (\Throwable) {}
}

// Membership name
$membership_name = 'Free';
if ($user['membership_id'] && $user['membership_expires_at'] && strtotime($user['membership_expires_at']) > time()) {
    $ms = $pdo->prepare("SELECT name FROM memberships WHERE id=?");
    $ms->execute([$user['membership_id']]);
    $membership_name = $ms->fetchColumn() ?: 'Free';
}

$wd_require_level = setting($pdo, 'wd_require_level', '0') === '1';
$wd_min_level  = (int) setting($pdo, 'wd_min_level', '0');
$user_level    = user_membership_level($pdo, $user);
$level_blocked = $wd_require_level && $wd_min_level > 0 && $user_level < $wd_min_level;
$min_level_name = '';
if ($wd_require_level && $wd_min_level > 0) {
    $lv = $pdo->prepare("SELECT name FROM memberships WHERE sort_order=? AND is_active=1 LIMIT 1");
    $lv->execute([$wd_min_level]);
    $min_level_name = $lv->fetchColumn() ?: "Level {$wd_min_level}";
}

$pageTitle  = 'Beranda — Meloton';
$activePage = 'home';
require dirname(__DIR__) . '/partials/header.php';
?>

<?php if (!empty($_SESSION['flash_home_err'])): ?>
<div class="alert alert--error" style="margin-bottom:12px;font-size:13px; font-weight:800; border-radius:16px; text-align:center;">
  <?= htmlspecialchars($_SESSION['flash_home_err']) ?>
</div>
<?php unset($_SESSION['flash_home_err']); endif; ?>

<!-- GAME HEADER: Balances -->
<div class="game-header-bar">
  <div class="game-capsule" title="Saldo Beli (Koin)">
    <div class="game-capsule__icon">
      <i class="ph-fill ph-coin" style="color: #f1c40f;"></i>
    </div>
    <?= format_rp((float)$user['balance_dep']) ?>
  </div>
  
  <div class="game-capsule" title="Saldo Penarikan (Uang Hijau)">
    <div class="game-capsule__icon">
      <i class="ph-fill ph-money" style="color: #2ecc71;"></i>
    </div>
    <?= format_rp((float)$user['balance_wd']) ?>
  </div>
</div>

<!-- GAME TITLE -->
<h1 class="page-title-stroke">BERANDA</h1>

<!-- EXP / LEVEL BADGE -->
<div class="game-level-badge">
  <div class="game-level-badge__icon">
    <span>EXP</span>
  </div>
  Lv. <?= htmlspecialchars((string)($user_level ?: 1)) ?>
</div>

<!-- MAIN CONTENT LIST -->
<div class="game-list">

  <!-- Misi Utama: Nonton Hari Ini -->
  <div class="game-item">
    <div class="game-item__icon">
      <i class="ph-fill ph-film-strip" style="color: #e74c3c;"></i>
    </div>
    <div class="game-item__content">
      <div class="game-item__title">Misi Nonton Hari Ini</div>
      <div class="game-item__progress-bg">
        <?php $pct = $watch_limit > 0 ? min(100, round(($watch_today / $watch_limit) * 100)) : 0; ?>
        <div class="game-item__progress-fill" style="width: <?= $pct ?>%;"></div>
        <div class="game-item__progress-text"><?= $watch_today ?>/<?= $watch_limit ?></div>
      </div>
    </div>
    <div class="game-item__action">
      <a href="/videos" class="btn-game btn-game--yellow">LANJUT</a>
    </div>
  </div>

  <!-- Video Tersedia -->
  <?php if (!empty($videos)): ?>
    <?php foreach ($videos as $v): ?>
    <div class="game-item">
      <div class="game-item__icon" style="border-radius:12px; overflow:hidden; border:2px solid #f0e0c9;">
        <img src="<?= yt_thumb($v['youtube_id']) ?>" alt="thumb" onerror="this.src='https://img.youtube.com/vi/<?= $v['youtube_id'] ?>/hqdefault.jpg'">
      </div>
      <div class="game-item__content">
        <div class="game-item__title"><?= htmlspecialchars($v['title']) ?></div>
        <div style="font-size:10px; font-weight:800; color:#e67e22; margin-top:2px; display:flex; align-items:center; gap:2px;">
           <i class="ph-fill ph-clock"></i> <?= $v['watch_duration'] ?>s
        </div>
      </div>
      <div class="game-item__action">
        <div class="game-item__reward">
          <i class="ph-fill ph-coin"></i> <?= format_rp((float)$v['reward_amount']) ?>
        </div>
        <a href="/watch?id=<?= $v['id'] ?>" class="btn-game btn-game--green">AMBIL</a>
      </div>
    </div>
    <?php endforeach; ?>
  <?php else: ?>
    <div class="game-item" style="justify-content:center; padding: 24px;">
      <div class="game-item__title" style="text-align:center; color:#9e7b60;">Semua misi video sudah selesai!</div>
    </div>
  <?php endif; ?>

  <!-- Fitur Lainnya (Undang Teman, Tarik, dll) -->
  <div class="game-item">
    <div class="game-item__icon">
      <i class="ph-fill ph-users-three" style="color: #3498db;"></i>
    </div>
    <div class="game-item__content">
      <div class="game-item__title">Undang Teman</div>
      <div style="font-size:10px; font-weight:800; color:#e67e22; margin-top:2px;">
         Kode: <span id="ref-code-text"><?= htmlspecialchars($user['referral_code']) ?></span>
      </div>
    </div>
    <div class="game-item__action">
      <button type="button" class="btn-game btn-game--yellow" onclick="copyRef('<?= htmlspecialchars($user['referral_code']) ?>')">SALIN</button>
    </div>
  </div>

  <div class="game-item">
    <div class="game-item__icon">
      <i class="ph-fill ph-bank" style="color: #9b59b6;"></i>
    </div>
    <div class="game-item__content">
      <div class="game-item__title">Tarik Keuntungan</div>
      <div style="font-size:10px; font-weight:800; color:#e67e22; margin-top:2px;">
         Cairkan saldo uangmu
      </div>
    </div>
    <div class="game-item__action">
      <a href="/withdraw" class="btn-game btn-game--yellow">TARIK</a>
    </div>
  </div>

</div>

<!-- Popups and Scripts -->
<div id="ref-toast" style="display:none; position:fixed; bottom:80px; left:50%; transform:translateX(-50%); background:#2ecc71; color:#fff; font-weight:800; padding:10px 20px; border-radius:20px; box-shadow:0 4px 0 #27ae60; z-index:9999; font-size:12px;">
  ✓ Kode berhasil disalin!
</div>

<script>
function copyRef(code) {
  navigator.clipboard.writeText(code).then(()=>{
    const toast = document.getElementById('ref-toast');
    toast.style.display = 'block';
    setTimeout(() => toast.style.display = 'none', 2000);
  }).catch(()=>{
    alert("Gagal menyalin: " + code);
  });
}
</script>

<?php require dirname(__DIR__) . '/partials/footer.php'; ?>
