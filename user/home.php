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
<div class="alert alert--error" style="margin-bottom:12px;font-size:13px">
  <?= htmlspecialchars($_SESSION['flash_home_err']) ?>
</div>
<?php unset($_SESSION['flash_home_err']); endif; ?>

<!-- Header Profile & Balance -->
<style>
@keyframes float {
  0% { transform: translateY(0); }
  50% { transform: translateY(-3px); }
  100% { transform: translateY(0); }
}
@keyframes pulse-glow {
  0% { box-shadow: 0 0 0 0 rgba(225, 29, 72, 0.4); }
  70% { box-shadow: 0 0 0 10px rgba(225, 29, 72, 0); }
  100% { box-shadow: 0 0 0 0 rgba(225, 29, 72, 0); }
}
.upgrade-btn-pulse {
  animation: pulse-glow 2s infinite;
}
</style>

<!-- 1. USER HERO CARD -->
<div class="user-hero-card">
  <div class="user-hero-card__top">
    <div class="user-hero-card__profile">
      <div class="user-hero-card__avatar">
        <?= strtoupper(substr($user['username'], 0, 1)) ?>
      </div>
      <div class="user-hero-card__info">
        <h2 class="user-hero-card__name">Halo, <?= htmlspecialchars($user['username']) ?>!</h2>
        <span class="user-hero-card__badge">
          <i class="ph-fill ph-star"></i> <?= htmlspecialchars($membership_name) ?>
        </span>
      </div>
    </div>
    <a href="<?= $is_guest ? '/login' : '/upgrade' ?>" class="user-hero-card__action-btn <?= $is_guest ? '' : 'upgrade-btn-pulse' ?>">
      <i class="ph-bold <?= $is_guest ? 'ph-sign-in' : 'ph-rocket-launch' ?>"></i>
      <?= $is_guest ? 'LOGIN / DAFTAR' : 'UPGRADE' ?>
    </a>
  </div>
  
  <div class="user-hero-card__stats">
    <div class="user-hero-card__stat-item">
      <div class="user-hero-card__stat-label">Tonton Hari Ini</div>
      <div class="user-hero-card__stat-val">
        <strong><?= $watch_today ?></strong>
        <span style="font-size:10px; opacity:0.8">/ <?= $watch_limit ?></span>
      </div>
      <?php $pct = $watch_limit > 0 ? min(100, round(($watch_today / $watch_limit) * 100)) : 0; ?>
      <div class="user-hero-card__progress-track">
        <div class="user-hero-card__progress-bar" style="width: <?= $pct ?>%;"></div>
      </div>
    </div>
    
    <div class="user-hero-card__stat-item">
      <div class="user-hero-card__stat-label">Kode Referral</div>
      <div class="user-hero-card__referral-wrap">
        <span class="user-hero-card__referral-code" id="ref-code-text"><?= htmlspecialchars($user['referral_code']) ?></span>
        <button type="button" class="user-hero-card__copy-btn" onclick="copyRef('<?= htmlspecialchars($user['referral_code']) ?>')" aria-label="Salin Kode Referral">
          <i class="ph-bold ph-copy"></i>
        </button>
      </div>
    </div>
  </div>
</div>

<div id="ref-toast" style="display:none;text-align:center;font-size:11px;font-weight:700;color:var(--brand);margin-bottom:12px">
  ✓ Kode berhasil disalin! Siap dibagikan!
</div>

<!-- 1.5. NEWCOMER GUIDE BANNER -->
<?php 
$is_newcomer = !$is_guest && (empty($history) || (isset($user['created_at']) && strtotime($user['created_at']) > time() - 3 * 86400) || ($user['balance_wd'] == 0 && $user['balance_dep'] == 0));
if ($is_newcomer): 
?>
<div style="background:var(--brand-light); border:var(--border-light); border-radius:12px; padding:12px; margin-bottom:16px; display:flex; align-items:center; gap:12px; box-shadow:var(--shadow-sm);">
  <div style="background:var(--white); border-radius:50%; width:36px; height:36px; display:flex; align-items:center; justify-content:center; flex-shrink:0; box-shadow:0 2px 4px rgba(0,0,0,0.05)">
    <i class="ph-bold ph-book-open-text" style="font-size:18px; color:var(--brand)"></i>
  </div>
  <div style="flex:1">
    <div style="font-size:11px; font-weight:700; color:var(--ink); margin-bottom:2px">Baru gabung di Meloton?</div>
    <div style="font-size:10px; color:var(--text-muted)">Yuk baca panduan dulu supaya paham cara dapetin duitnya!</div>
  </div>
  <a href="/panduan" class="btn btn--primary btn--sm" style="font-size:10px; padding:6px 12px; border-radius:8px; white-space:nowrap;">Panduan</a>
</div>
<?php endif; ?>

<!-- 2. DUAL BALANCE CARDS -->
<div class="balance-card">
  <div class="balance-card__grid">
    <div class="balance-card__col">
      <div class="balance-card__label">
        <i class="ph-fill ph-wallet"></i> Saldo Penarikan
      </div>
      <div class="balance-card__value">
        <?= format_rp((float)$user['balance_wd']) ?>
      </div>
      <a href="/withdraw" class="balance-card__btn balance-card__btn--withdraw">Tarik Saldo</a>
    </div>
    
    <div class="balance-card__divider"></div>
    
    <div class="balance-card__col">
      <div class="balance-card__label">
        <i class="ph-fill ph-bank"></i> Saldo Beli
      </div>
      <div class="balance-card__value">
        <?= format_rp((float)$user['balance_dep']) ?>
      </div>
      <a href="/deposit" class="balance-card__btn balance-card__btn--deposit">Isi Saldo</a>
    </div>
  </div>
</div>

<!-- 3. QUICK ACTIONS GRID -->
<div class="quick-actions">
  <div class="quick-actions__grid">
    <a href="/deposit" class="quick-actions__item">
      <div class="quick-actions__icon-wrapper" style="background:var(--brand-light); color:var(--brand);"><i class="ph-fill ph-download-simple"></i></div>
      <span class="quick-actions__label">Top Up</span>
    </a>
    <a href="/withdraw" class="quick-actions__item">
      <div class="quick-actions__icon-wrapper" style="background:var(--brand-light); color:var(--brand);"><i class="ph-fill ph-upload-simple"></i></div>
      <span class="quick-actions__label">Tarik</span>
    </a>
    <a href="/history" class="quick-actions__item">
      <div class="quick-actions__icon-wrapper" style="background:var(--brand-light); color:var(--brand);"><i class="ph-fill ph-receipt"></i></div>
      <span class="quick-actions__label">Riwayat</span>
    </a>
    <a href="/missions" class="quick-actions__item">
      <div class="quick-actions__icon-wrapper" style="background:var(--brand-light); color:var(--brand);"><i class="ph-fill ph-target"></i></div>
      <span class="quick-actions__label">Misi</span>
    </a>
    <a href="/checkin" class="quick-actions__item">
      <div class="quick-actions__icon-wrapper" style="background:#fff1f2; color:var(--brand);"><i class="ph-fill ph-calendar-check"></i></div>
      <span class="quick-actions__label">Absen</span>
    </a>
    <a href="/redeem" class="quick-actions__item">
      <div class="quick-actions__icon-wrapper" style="background:#fff1f2; color:var(--brand);"><i class="ph-fill ph-gift"></i></div>
      <span class="quick-actions__label">Redeem</span>
    </a>
    <a href="/referral" class="quick-actions__item">
      <div class="quick-actions__icon-wrapper" style="background:#fff1f2; color:var(--brand);"><i class="ph-fill ph-users"></i></div>
      <span class="quick-actions__label">Referral</span>
    </a>
    <a href="/panduan" class="quick-actions__item">
      <div class="quick-actions__icon-wrapper" style="background:#fff1f2; color:var(--brand);"><i class="ph-fill ph-book-open"></i></div>
      <span class="quick-actions__label">Panduan</span>
    </a>
  </div>
</div>

<!-- 4. PROMO BANNERS GRID -->
<div class="banners-grid">
  <?php if (setting($pdo, 'investment_enabled', '1') === '1'): ?>
  <a href="/invest" class="promo-banner promo-banner--invest">
    <div class="promo-banner__content">
      <div class="promo-banner__tag">PASIF</div>
      <h3 class="promo-banner__title">Investasi</h3>
      <p class="promo-banner__desc">Kembangkan saldo kamu</p>
    </div>
    <div class="promo-banner__icon"><i class="ph-fill ph-trend-up"></i></div>
  </a>
  <?php endif; ?>
</div>

<!-- 5. NOTIFICATIONS PREVIEW -->
<?php if (!empty($notif_preview)): ?>
<div style="margin-bottom:16px">
  <div class="section-header" style="margin-bottom:8px">
    <div class="section-title" style="font-size:14px; display:flex; align-items:center; gap:6px">
      <i class="ph-fill ph-bell-ringing" style="color:var(--brand)"></i> Notifikasi
      <?php if ($notif_unread > 0): ?>
      <span style="background:var(--brand); color:#fff; font-size:9px; font-weight:700; border-radius:10px; padding:1px 6px;"><?= $notif_unread > 9 ? '9+' : $notif_unread ?></span>
      <?php endif; ?>
    </div>
    <a href="/notifications" class="section-link">Lihat Semua →</a>
  </div>
  
  <?php
  $notif_colors = [
    'info'     => ['bg' => '#f0f9ff', 'border' => '#e0f2fe', 'color' => '#0284c7', 'icon' => 'ph-info'],
    'success'  => ['bg' => '#f0fdf4', 'border' => '#dcfce7', 'color' => '#16a34a', 'icon' => 'ph-check-circle'],
    'warning'  => ['bg' => '#fffbeb', 'border' => '#fef3c7', 'color' => '#d97706', 'icon' => 'ph-warning'],
    'alert'    => ['bg' => '#fff1f2', 'border' => '#ffe4e6', 'color' => '#e11d48', 'icon' => 'ph-warning-octagon'],
    'congrats' => ['bg' => '#fffbeb', 'border' => '#fef3c7', 'color' => '#ca8a04', 'icon' => 'ph-confetti'],
  ];
  foreach ($notif_preview as $nf):
    $nc = $notif_colors[$nf['type']] ?? $notif_colors['info'];
    $ni = $nc['icon'];
  ?>
  <div class="modern-notif" style="border-left-color: <?= $nc['color'] ?>;">
    <div class="modern-notif__icon" style="background: <?= $nc['bg'] ?>;">
      <i class="ph-fill <?= $ni ?>" style="color: <?= $nc['color'] ?>;"></i>
    </div>
    <div class="modern-notif__body">
      <div class="modern-notif__title"><?= htmlspecialchars($nf['title']) ?></div>
      <div class="modern-notif__desc"><?= htmlspecialchars($nf['message']) ?></div>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($watch_today >= $watch_limit): ?>
<div class="alert alert--warn" style="margin-bottom:16px; font-size:12px; padding:10px; border-radius:10px; border-color:#fde68a;">
  <i class="ph-bold ph-warning-circle" style="font-size:16px; color:#d97706"></i> Limit tonton hari ini udah habis ya (<?= $watch_limit ?>). <a href="/upgrade" style="color:var(--brand); font-weight:700; text-decoration:underline">Yuk upgrade sekarang!</a>
</div>
<?php endif; ?>

<!-- 6. HORIZONTAL VIDEO SCROLL -->
<style>
.video-scroll { display: flex; gap: 12px; overflow-x: auto; padding-bottom: 12px; margin: 0 -16px; padding-left: 16px; padding-right: 16px; scroll-snap-type: x mandatory; scrollbar-width: none; }
.video-scroll::-webkit-scrollbar { display: none; }
.v-card { flex: 0 0 200px; scroll-snap-align: center; text-decoration: none; display: flex; flex-direction: column; background: #fffcf2; border: 2px solid #f0e0c9; border-radius: 20px; overflow: hidden; box-shadow: 0 4px 0 #e6a760; transition: all 0.1s; }
.v-card:active { transform: translateY(4px); box-shadow: none; }
.v-card__thumb { position: relative; aspect-ratio: 16/9; background: #000; overflow: hidden; border-bottom: 2px solid #f0e0c9; }
.v-card__thumb img { width: 100%; height: 100%; object-fit: cover; opacity: 0.95; transition: opacity 0.2s, transform 0.3s; }
.v-card:hover .v-card__thumb img { opacity: 1; transform: scale(1.03); }
.v-card__badge { position: absolute; top: 6px; right: 6px; background: linear-gradient(180deg, #7bed9f, #2ecc71); color: #fff; font-size: 10px; font-weight: 800; padding: 2px 8px; border-radius: 12px; box-shadow: 0 3px 0 #27ae60; text-shadow: 1px 1px 0 rgba(0,0,0,0.2); }
.v-card__play { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; background: rgba(0,0,0,0.15); opacity: 0; transition: opacity 0.2s; }
.v-card:hover .v-card__play { opacity: 1; }
.v-card__play i { font-size: 32px; color: #fff; filter: drop-shadow(0 2px 4px rgba(0,0,0,0.3)); transition: transform 0.2s; }
.v-card:hover .v-card__play i { transform: scale(1.1); }
.v-card__info { padding: 12px; display: flex; flex-direction: column; gap: 4px; }
.v-card__title { font-size: 13px; font-weight: 800; color: var(--ink); display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; line-height: 1.3; height: 32px; text-shadow: 1px 1px 0 rgba(0,0,0,0.1); }
.v-card__meta { display: flex; align-items: center; justify-content: space-between; font-size: 11px; font-weight: 800; color: var(--text-muted); }
</style>

<div class="section-header" style="margin-bottom:10px">
  <div class="section-title" style="display:flex; align-items:center; gap:6px">
    <i class="ph-fill ph-video-camera" style="color:var(--brand)"></i> Video Tersedia
  </div>
  <a href="/videos" class="section-link">Lihat Semua →</a>
</div>

<?php if (empty($videos)): ?>
<div class="card" style="margin-bottom:16px;">
  <div class="empty-state" style="padding: 24px 16px;">
    <i class="ph-fill ph-check-circle" style="font-size:32px; color:var(--green)"></i>
    <p style="font-size:12px; font-weight:700; margin-top:6px; color:var(--ink)">Mantap! Semua video sudah ditonton hari ini.</p>
  </div>
</div>
<?php else: ?>
<div class="video-scroll" style="margin-bottom:16px">
  <?php foreach ($videos as $v): ?>
  <a href="/watch?id=<?= $v['id'] ?>" class="v-card">
    <div class="v-card__thumb">
      <img src="<?= yt_thumb($v['youtube_id']) ?>" alt="<?= htmlspecialchars($v['title']) ?>" loading="lazy" onerror="this.src='https://img.youtube.com/vi/<?= $v['youtube_id'] ?>/hqdefault.jpg'">
      <div class="v-card__play"><i class="ph-fill ph-play-circle"></i></div>
      <div class="v-card__badge">+<?= format_rp((float)$v['reward_amount']) ?></div>
    </div>
    <div class="v-card__info">
      <div class="v-card__title"><?= htmlspecialchars($v['title']) ?></div>
      <div class="v-card__meta">
        <span style="color:var(--green); display:flex; align-items:center; gap:2px"><i class="ph-bold ph-coins"></i> <?= format_rp((float)$v['reward_amount']) ?></span>
        <span style="display:flex; align-items:center; gap:2px"><i class="ph-bold ph-clock"></i> <?= $v['watch_duration'] ?>s</span>
      </div>
    </div>
  </a>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- 7. RECENT ACTIVITY -->
<?php if (!empty($history)): ?>
<div class="section-header" style="margin-bottom:8px">
  <div class="section-title" style="display:flex; align-items:center; gap:6px">
    <i class="ph-fill ph-clock-counter-clockwise" style="color:var(--blue)"></i> Aktivitas Terbaru
  </div>
</div>
<div class="card" style="margin-bottom:16px; overflow:hidden;">
  <div class="card__body" style="padding:0">
    <?php foreach ($history as $h): ?>
    <div class="list-item" style="padding:10px 14px; border-bottom:var(--border-light)">
      <div class="list-item__icon" style="background:var(--brand-light); width:28px; height:28px; font-size:14px; border-radius:6px; color:var(--green)">
        <i class="ph-bold ph-monitor-play"></i>
      </div>
      <div class="list-item__body">
        <div class="list-item__title" style="font-size:11px; font-weight:700; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:180px"><?= htmlspecialchars($h['title']) ?></div>
        <div class="list-item__sub" style="font-size:9px; display:flex; align-items:center; gap:4px">
          <i class="ph-bold ph-calendar-blank"></i> <?= date('d M H:i', strtotime($h['watched_at'])) ?>
        </div>
      </div>
      <div class="list-item__right">
        <div class="list-item__amount" style="font-size:11px; color:var(--green); font-weight:700; display:flex; align-items:center; gap:2px">
          +<?= format_rp((float)$h['reward_given']) ?>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<?php
// Popup settings from DB
$popup_enabled      = setting($pdo, 'popup_enabled', '1') === '1';
$popup_title        = setting($pdo, 'popup_title',   'Hei, sudah baca panduan?');
$popup_body         = setting($pdo, 'popup_body',    'Biar makin lancar dapat reward, yuk baca dulu cara kerja Meloton! Dari cara tonton, jenis saldo, sampai tips withdraw.');
$popup_cta_text     = setting($pdo, 'popup_cta_text', 'Baca Panduan');
$popup_cta_url      = setting($pdo, 'popup_cta_url',  '/panduan');
$popup_delay        = max(0, (int) setting($pdo, 'popup_delay', '1500'));
$popup_reset_hours  = max(0, (int) setting($pdo, 'popup_reset_hours', '0'));
?>
<?php if ($popup_enabled): ?>
<!-- Popup Panduan -->
<div id="guide-popup" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,.5); z-index:9999; align-items:flex-end; justify-content:center; padding-bottom:0">
  <div style="background:var(--white); border-radius:20px 20px 0 0; padding:20px; max-width:480px; width:100%; transform:translateY(100%); transition:transform .3s ease; position:relative; box-shadow: 0 -8px 24px rgba(0,0,0,0.5)">
    <button onclick="closePopup()" style="position:absolute; top:16px; right:16px; background:var(--bg); color:var(--ink); border:none; width:28px; height:28px; border-radius:50%; font-size:14px; display:flex; align-items:center; justify-content:center; cursor:pointer"><i class="ph-bold ph-x"></i></button>
    
    <div style="width:48px; height:48px; background:var(--brand-light); border-radius:12px; display:flex; align-items:center; justify-content:center; font-size:24px; margin:0 auto 12px; color:var(--brand)">
      <i class="ph-fill ph-book-open"></i>
    </div>
    
    <h3 style="font-size:15px; font-weight:700; text-align:center; margin:0 0 6px"><?= htmlspecialchars($popup_title) ?></h3>
    <p style="font-size:11px; line-height:1.4; color:var(--text-muted); text-align:center; margin:0 0 16px;">
      <?= nl2br(htmlspecialchars($popup_body)) ?>
    </p>
    
    <div style="display:flex; flex-direction:column; gap:8px">
      <a href="<?= htmlspecialchars($popup_cta_url) ?>" class="btn btn--primary" style="font-size:12px; padding:12px; border-radius:10px; display:flex; align-items:center; justify-content:center; gap:6px">
        <i class="ph-bold ph-book-bookmark"></i> <?= htmlspecialchars($popup_cta_text) ?>
      </a>
      <button type="button" onclick="closePopup()" class="btn btn--ghost" style="font-size:11px; font-weight:700; padding:10px; border-radius:10px; color:var(--text-muted); border:none;">Nanti Saja</button>
    </div>
  </div>
</div>

<script>
function closePopup() {
  const p = document.getElementById('guide-popup');
  const c = p.querySelector('div');
  c.style.transform = 'translateY(100%)';
  setTimeout(() => p.style.display = 'none', 300);
  try {
    const data = { ts: Date.now() };
    localStorage.setItem('tonton_popup_seen', JSON.stringify(data));
  } catch(e){}
}

document.addEventListener('DOMContentLoaded', () => {
  const p = document.getElementById('guide-popup');
  if(!p) return;
  const c = p.querySelector('div');
  const resetMs = <?= $popup_reset_hours ?> * 3600000;
  
  try {
    const raw = localStorage.getItem('tonton_popup_seen');
    if (raw) {
      const data = JSON.parse(raw);
      if (resetMs > 0 && (Date.now() - data.ts) > resetMs) {
        // expired
      } else {
        return;
      }
    }
  } catch(e){}

  setTimeout(() => {
    p.style.display = 'flex';
    p.offsetHeight;
    c.style.transform = 'translateY(0)';
  }, <?= $popup_delay ?>);
});
</script>
<?php endif; ?>

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
