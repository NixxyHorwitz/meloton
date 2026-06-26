<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/bootstrap.php';

$user = auth_user($pdo);
$is_guest = false;
if (!$user) {
    $is_guest = true;
    $user = [
        'id' => 0, 'username' => 'Tamu', 'balance_wd' => 0, 'balance_dep' => 0,
        'membership_id' => null, 'membership_expires_at' => null, 'referral_code' => '-', 'is_promotor' => 0,
    ];
}

if (is_maintenance($pdo) && !auth_admin()) {
    $maintenance_msg = setting($pdo, 'maintenance_message', 'Sistem sedang dalam perbaikan.');
    require dirname(__DIR__) . '/user/maintenance.php'; exit;
}

track_pageview($pdo, parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));

$watch_limit = $is_guest ? 0 : user_watch_limit($pdo, $user);
$watch_today = $is_guest ? 0 : user_watch_today($pdo, $user);

if ($is_guest) {
    $videos = $pdo->query("SELECT v.* FROM videos v WHERE v.is_active=1 ORDER BY v.sort_order ASC, v.id DESC LIMIT 6")->fetchAll();
    $history = []; $notif_preview = []; $notif_unread = 0;
} else {
    $videos = $pdo->prepare(
        "SELECT v.* FROM videos v WHERE v.is_active=1
           AND v.id NOT IN (SELECT video_id FROM watch_history WHERE user_id=? AND DATE(watched_at)=CURDATE())
         ORDER BY v.sort_order ASC, v.id DESC LIMIT 6"
    );
    $videos->execute([$user['id']]); $videos = $videos->fetchAll();

    $history = $pdo->prepare(
        "SELECT wh.reward_given, wh.watched_at, v.title FROM watch_history wh
         JOIN videos v ON v.id=wh.video_id WHERE wh.user_id=? ORDER BY wh.watched_at DESC LIMIT 4"
    );
    $history->execute([$user['id']]); $history = $history->fetchAll();

    $notif_preview = []; $notif_unread = 0;
    try {
        $uid = $user['id'];
        $np = $pdo->prepare(
            "SELECT n.* FROM notifications n LEFT JOIN notification_reads nr ON nr.notification_id=n.id AND nr.user_id=?
             WHERE nr.id IS NULL AND (n.target_type='all' OR (n.target_user_ids IS NOT NULL AND JSON_CONTAINS(n.target_user_ids, JSON_QUOTE(?))))
               AND (n.expires_at IS NULL OR n.expires_at > NOW()) ORDER BY n.created_at DESC LIMIT 3"
        );
        $np->execute([$uid, (string)$uid]); $notif_preview = $np->fetchAll();
        $nc = $pdo->prepare(
            "SELECT COUNT(*) FROM notifications n LEFT JOIN notification_reads nr ON nr.notification_id=n.id AND nr.user_id=?
             WHERE nr.id IS NULL AND (n.target_type='all' OR (n.target_user_ids IS NOT NULL AND JSON_CONTAINS(n.target_user_ids, JSON_QUOTE(?))))
               AND (n.expires_at IS NULL OR n.expires_at > NOW())"
        );
        $nc->execute([$uid, (string)$uid]); $notif_unread = (int)$nc->fetchColumn();
    } catch (\Throwable) {}
}

$membership_name = 'Free';
if ($user['membership_id'] && $user['membership_expires_at'] && strtotime($user['membership_expires_at']) > time()) {
    $ms = $pdo->prepare("SELECT name FROM memberships WHERE id=?");
    $ms->execute([$user['membership_id']]);
    $membership_name = $ms->fetchColumn() ?: 'Free';
}

$showcase_memberships = $pdo->query("SELECT * FROM memberships WHERE is_active=1 AND price > 0 ORDER BY sort_order ASC")->fetchAll();

$wd_require_level = setting($pdo, 'wd_require_level', '0') === '1';
$wd_min_level  = (int) setting($pdo, 'wd_min_level', '0');
$user_level    = user_membership_level($pdo, $user);
$level_blocked = $wd_require_level && $wd_min_level > 0 && $user_level < $wd_min_level;

$pageTitle  = 'Beranda';
$activePage = 'home';
require dirname(__DIR__) . '/partials/header.php';
?>

<style>
/* ══════════════════════════════════════════
   HOME PAGE — NEW COMPACT PANGLING LAYOUT
   ══════════════════════════════════════════ */

/* ── Flash ── */
.flash-alert { padding: 10px 14px; border-radius: 12px; font-size: 12px; font-weight: 700; margin-bottom: 12px; display: flex; align-items: center; gap: 8px; border: 2px solid; }
.flash-alert--err { background: #fef2f2; color: #991b1b; border-color: #fecaca; }

/* ── 1. HERO STRIP ── */
.hero-strip {
  background: linear-gradient(135deg, #0c4a6e 0%, #0e7490 60%, #06b6d4 100%);
  border-radius: 20px;
  padding: 14px;
  margin-bottom: 12px;
  position: relative;
  overflow: hidden;
  border: 2.5px solid #075985;
  box-shadow: 0 6px 0 #0c4a6e;
}
.hero-strip::before {
  content: ''; position: absolute;
  top: -40px; right: -30px;
  width: 140px; height: 140px;
  border-radius: 50%;
  background: rgba(255,255,255,0.06);
  pointer-events: none;
}
.hero-strip::after {
  content: ''; position: absolute;
  bottom: -20px; left: 20px;
  width: 80px; height: 80px;
  border-radius: 50%;
  background: rgba(255,255,255,0.05);
  pointer-events: none;
}

.hero-strip__top {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 12px;
}
.hero-strip__identity {
  display: flex;
  align-items: center;
  gap: 10px;
  min-width: 0;
}
.hero-strip__avatar {
  width: 44px; height: 44px;
  background: linear-gradient(135deg, #fde68a, #f59e0b);
  border-radius: 14px;
  border: 2.5px solid rgba(255,255,255,0.3);
  display: flex; align-items: center; justify-content: center;
  font-weight: 900; font-size: 20px; color: #0c4a6e;
  box-shadow: 0 4px 0 rgba(0,0,0,0.2);
  flex-shrink: 0;
}
.hero-strip__name {
  font-size: 14px; font-weight: 900; color: #fff;
  line-height: 1.2;
  text-shadow: 0 1px 2px rgba(0,0,0,0.2);
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.hero-strip__tier {
  display: inline-flex;
  align-items: center;
  gap: 3px;
  background: rgba(255,255,255,0.15);
  border: 1px solid rgba(255,255,255,0.2);
  border-radius: 20px;
  padding: 2px 8px;
  font-size: 10px; font-weight: 800; color: #fde68a;
  margin-top: 2px;
}
.hero-strip__cta {
  display: flex; align-items: center; gap: 4px;
  background: linear-gradient(135deg, #f97316, #ea580c);
  border: 2px solid #fed7aa;
  border-radius: 10px;
  padding: 6px 10px;
  color: #fff; font-size: 11px; font-weight: 900;
  text-decoration: none;
  box-shadow: 0 3px 0 #c2410c;
  flex-shrink: 0;
  transition: transform 0.1s;
  animation: pulse-cta 2.5s ease infinite;
}
.hero-strip__cta:active { transform: translateY(2px); box-shadow: none; }
@keyframes pulse-cta {
  0%, 100% { box-shadow: 0 3px 0 #c2410c; }
  50% { box-shadow: 0 3px 0 #c2410c, 0 0 12px rgba(249,115,22,0.5); }
}

/* ── Watch Progress Bar ── */
.hero-strip__progress-wrap {
  background: rgba(255,255,255,0.1);
  border: 1.5px solid rgba(255,255,255,0.15);
  border-radius: 12px;
  padding: 10px 12px;
}
.hero-strip__progress-header {
  display: flex; align-items: center; justify-content: space-between;
  margin-bottom: 6px;
}
.hero-strip__progress-label {
  font-size: 10px; font-weight: 800; color: rgba(255,255,255,0.75);
  display: flex; align-items: center; gap: 4px;
}
.hero-strip__progress-count {
  font-size: 11px; font-weight: 900; color: #fde68a;
}
.hero-strip__progress-track {
  height: 8px;
  background: rgba(0,0,0,0.2);
  border-radius: 20px;
  overflow: hidden;
}
.hero-strip__progress-fill {
  height: 100%;
  background: linear-gradient(90deg, #34d399, #10b981);
  border-radius: 20px;
  transition: width 0.5s ease;
  position: relative;
}
.hero-strip__progress-fill::after {
  content: '';
  position: absolute;
  top: 0; left: 0; right: 0;
  height: 50%;
  background: rgba(255,255,255,0.3);
  border-radius: 20px;
}

/* ── 2. BALANCE + ACTIONS ROW ── */
.dash-row {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 10px;
  margin-bottom: 12px;
}
.bal-tile {
  background: #fff;
  border: 2.5px solid #7dd3e8;
  border-radius: 16px;
  padding: 12px;
  box-shadow: 0 5px 0 #7dd3e8;
  display: flex;
  flex-direction: column;
  gap: 6px;
  position: relative;
  overflow: hidden;
}
.bal-tile::before {
  content: '';
  position: absolute;
  bottom: -10px; right: -10px;
  width: 56px; height: 56px;
  border-radius: 50%;
  opacity: 0.08;
}
.bal-tile--wd::before { background: #10b981; }
.bal-tile--dep::before { background: #3b82f6; }
.bal-tile__icon {
  width: 32px; height: 32px;
  border-radius: 10px;
  display: flex; align-items: center; justify-content: center;
  font-size: 17px;
}
.bal-tile--wd .bal-tile__icon { background: #d1fae5; }
.bal-tile--dep .bal-tile__icon { background: #dbeafe; }
.bal-tile__lbl { font-size: 10px; font-weight: 700; color: #64748b; }
.bal-tile__val { font-size: 15px; font-weight: 900; color: #0c4a6e; line-height: 1; }
.bal-tile__btn {
  display: inline-flex; align-items: center; gap: 4px;
  font-size: 10px; font-weight: 900;
  padding: 4px 10px;
  border-radius: 20px;
  border: 1.5px solid;
  text-decoration: none;
  width: fit-content;
  transition: all 0.1s;
}
.bal-tile--wd .bal-tile__btn { background: #d1fae5; color: #065f46; border-color: #6ee7b7; }
.bal-tile--dep .bal-tile__btn { background: #dbeafe; color: #1e40af; border-color: #93c5fd; }
.bal-tile__btn:active { filter: brightness(0.9); }

/* ── 3. QUICK ACTIONS ── */
.qa-grid {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 8px;
  margin-bottom: 12px;
}
.qa-item {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 5px;
  text-decoration: none;
}
.qa-item__icon {
  width: 50px; height: 50px;
  border-radius: 16px;
  display: flex; align-items: center; justify-content: center;
  font-size: 22px;
  border: 2px solid rgba(0,0,0,0.08);
  transition: transform 0.1s;
}
.qa-item:active .qa-item__icon { transform: scale(0.93); }
.qa-item__label {
  font-size: 10px; font-weight: 800;
  color: #0c4a6e;
  text-align: center;
}

/* ── 4. SECTION HEADER ── */
.sh {
  display: flex; align-items: center; justify-content: space-between;
  margin-bottom: 8px;
}
.sh__title {
  display: flex; align-items: center; gap: 5px;
  font-size: 13px; font-weight: 900; color: #0c4a6e;
}
.sh__title i { font-size: 16px; }
.sh__link {
  font-size: 10px; font-weight: 900;
  color: #0891b2;
  background: #e0f9ff;
  border: 1.5px solid #7dd3e8;
  padding: 3px 10px;
  border-radius: 20px;
  text-decoration: none;
}
.sh__badge {
  font-size: 9px; font-weight: 900;
  background: #ef4444;
  color: #fff;
  padding: 1px 6px;
  border-radius: 10px;
}

/* ── 5. VIDEO SCROLL ── */
.vid-scroll {
  display: flex; gap: 10px;
  overflow-x: auto; padding-bottom: 10px;
  margin: 0 -14px; padding-left: 14px; padding-right: 14px;
  scroll-snap-type: x mandatory;
  scrollbar-width: none;
  margin-bottom: 12px;
}
.vid-scroll::-webkit-scrollbar { display: none; }
.vid-card {
  flex: 0 0 160px;
  scroll-snap-align: start;
  text-decoration: none;
  display: flex; flex-direction: column;
  background: #fff;
  border: 2.5px solid #7dd3e8;
  border-radius: 16px;
  overflow: hidden;
  box-shadow: 0 4px 0 #7dd3e8;
  transition: transform 0.1s;
}
.vid-card:active { transform: translateY(3px); box-shadow: none; }
.vid-card__thumb {
  position: relative;
  aspect-ratio: 16/9;
  background: #000;
  overflow: hidden;
}
.vid-card__thumb img { width: 100%; height: 100%; object-fit: cover; opacity: 0.9; }
.vid-card__badge {
  position: absolute;
  bottom: 5px; left: 5px;
  background: #10b981;
  color: #fff;
  font-size: 9px; font-weight: 900;
  padding: 2px 6px;
  border-radius: 6px;
  border: 1.5px solid rgba(255,255,255,0.3);
}
.vid-card__play-icon {
  position: absolute;
  inset: 0; display: flex; align-items: center; justify-content: center;
  background: rgba(0,0,0,0.2);
}
.vid-card__play-icon i { font-size: 28px; color: #fff; filter: drop-shadow(0 2px 4px rgba(0,0,0,0.4)); }
.vid-card__body { padding: 8px; }
.vid-card__title {
  font-size: 11px; font-weight: 800; color: #0c4a6e;
  display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical;
  overflow: hidden; line-height: 1.3;
}
.vid-card__meta {
  display: flex; align-items: center; justify-content: space-between;
  font-size: 10px; font-weight: 800; color: #64748b;
  margin-top: 4px;
}

/* ── 6. VIDEO EMPTY STATE ── */
.vid-done-card {
  background: #f0fdf4;
  border: 2px solid #bbf7d0;
  border-radius: 16px;
  padding: 20px;
  display: flex; align-items: center; gap: 12px;
  margin-bottom: 12px;
}
.vid-done-card i { font-size: 28px; color: #10b981; flex-shrink: 0; }

/* ── 7. NOTIFICATIONS COMPACT ── */
.notif-compact {
  background: #fff;
  border: 2px solid #7dd3e8;
  border-radius: 14px;
  overflow: hidden;
  box-shadow: 0 4px 0 #7dd3e8;
  margin-bottom: 12px;
}
.notif-compact-item {
  display: flex; align-items: flex-start; gap: 10px;
  padding: 10px 12px;
  border-bottom: 1.5px solid #e0f9ff;
}
.notif-compact-item:last-child { border-bottom: none; }
.notif-compact-dot {
  width: 8px; height: 8px;
  border-radius: 50%;
  margin-top: 4px;
  flex-shrink: 0;
}
.notif-compact-body { flex: 1; min-width: 0; }
.notif-compact-title {
  font-size: 11px; font-weight: 800; color: #0c4a6e;
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.notif-compact-msg { font-size: 10px; color: #64748b; margin-top: 1px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

/* ── 8. ACTIVITY FEED ── */
.activity-feed {
  background: #fff;
  border: 2.5px solid #7dd3e8;
  border-radius: 16px;
  overflow: hidden;
  box-shadow: 0 5px 0 #7dd3e8;
  margin-bottom: 12px;
}
.activity-item {
  display: flex; align-items: center; gap: 10px;
  padding: 9px 12px;
  border-bottom: 1.5px solid #f0fdff;
}
.activity-item:last-child { border-bottom: none; }
.activity-icon {
  width: 32px; height: 32px;
  background: #e0f9ff;
  border-radius: 10px;
  display: flex; align-items: center; justify-content: center;
  font-size: 15px; color: #0891b2;
  flex-shrink: 0;
}
.activity-text { flex: 1; min-width: 0; }
.activity-title { font-size: 11px; font-weight: 800; color: #0c4a6e; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.activity-date { font-size: 10px; color: #94a3b8; font-weight: 700; }
.activity-amount { font-size: 12px; font-weight: 900; color: #10b981; white-space: nowrap; }

/* ── 9. PROMO BANNER COMPACT ── */
.promo-pill {
  display: flex; align-items: center; gap: 12px;
  background: linear-gradient(135deg, #fef3c7, #fffbeb);
  border: 2.5px solid #fde68a;
  border-radius: 16px;
  padding: 12px 14px;
  box-shadow: 0 4px 0 #fbbf24;
  margin-bottom: 12px;
  text-decoration: none;
  transition: transform 0.1s;
}
.promo-pill:active { transform: translateY(3px); box-shadow: none; }
.promo-pill__icon {
  width: 44px; height: 44px;
  background: linear-gradient(135deg, #f59e0b, #d97706);
  border-radius: 14px;
  display: flex; align-items: center; justify-content: center;
  font-size: 22px; color: #fff;
  box-shadow: 0 3px 0 rgba(0,0,0,0.2);
  flex-shrink: 0;
}
.promo-pill__body { flex: 1; }
.promo-pill__tag { font-size: 9px; font-weight: 900; color: #d97706; text-transform: uppercase; letter-spacing: 0.5px; }
.promo-pill__title { font-size: 13px; font-weight: 900; color: #0c4a6e; }
.promo-pill__desc { font-size: 10px; color: #64748b; font-weight: 700; }
.promo-pill__arrow { font-size: 18px; color: #d97706; flex-shrink: 0; }

/* ── 10. GUIDE BANNER ── */
.guide-banner {
  display: flex; align-items: center; gap: 10px;
  background: #f0f9ff;
  border: 2px solid #bae6fd;
  border-radius: 14px;
  padding: 10px 12px;
  margin-bottom: 12px;
}
.guide-banner__icon { font-size: 22px; color: #0891b2; flex-shrink: 0; }
.guide-banner__text { flex: 1; min-width: 0; }
.guide-banner__text strong { font-size: 11px; font-weight: 900; color: #0c4a6e; display: block; }
.guide-banner__text span { font-size: 10px; color: #64748b; }
.guide-banner__btn {
  font-size: 10px; font-weight: 900;
  background: #0891b2; color: #fff;
  padding: 5px 12px; border-radius: 20px;
  text-decoration: none; flex-shrink: 0;
}

/* ── LIMIT WARN BANNER ── */
.limit-warn {
  display: flex; align-items: center; gap: 8px;
  background: #fffbeb;
  border: 2px solid #fde68a;
  border-radius: 12px;
  padding: 9px 12px;
  margin-bottom: 10px;
  font-size: 11px; font-weight: 700; color: #92400e;
}
.limit-warn a { color: #0891b2; font-weight: 900; text-decoration: none; margin-left: 2px; }

/* ── Membership Showcase ── */
.m-card { background: #fff; border: 3px solid #0f172a; border-radius: 20px; box-shadow: 0 6px 0 #0f172a; padding: 14px; margin-bottom: 12px; position: relative; text-decoration: none; display: block; transition: transform 0.1s; }
.m-card:active { transform: translateY(2px); box-shadow: 0 4px 0 #0f172a; }
.m-card--0 { border-color: #64748b; box-shadow: 0 6px 0 #64748b; }
.m-card--1 { border-color: #0ea5e9; box-shadow: 0 6px 0 #0369a1; }
.m-card--2 { border-color: #f59e0b; box-shadow: 0 6px 0 #d97706; }
.m-card--3 { border-color: #8b5cf6; box-shadow: 0 6px 0 #6d28d9; }
.m-card--4 { border-color: #ef4444; box-shadow: 0 6px 0 #b91c1c; }
.m-badge-pop { position:absolute; top:-10px; right:-8px; background:linear-gradient(135deg, #ef4444, #b91c1c); color:#fff; font-size:9px; font-weight:900; padding:3px 8px; border-radius:12px; border:2px solid #fff; box-shadow:0 3px 0 #7f1d1d; transform:rotate(5deg); z-index:2; text-shadow:0 1px 1px rgba(0,0,0,0.3); }
.m-badge-pro { position:absolute; top:-10px; left:-8px; background:linear-gradient(135deg, #34d399, #10b981); color:#fff; font-size:9px; font-weight:900; padding:3px 8px; border-radius:12px; border:2px solid #fff; box-shadow:0 3px 0 #059669; transform:rotate(-5deg); z-index:2; text-shadow:0 1px 1px rgba(0,0,0,0.3); }
.m-hdr { display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px; }
.m-ico-box { width: 40px; height: 40px; border-radius: 10px; border: 2px solid #fff; display: flex; align-items: center; justify-content: center; font-size: 20px; box-shadow: 0 3px 0 rgba(0,0,0,0.1); }
.m-name { font-size: 14px; font-weight: 900; line-height: 1.1; margin-bottom: 2px; }
.m-dur { font-size: 10px; font-weight: 800; color: #64748b; display:flex; align-items:center; gap:4px; }
.m-price-box { text-align: right; }
.m-price-old { font-size: 10px; font-weight: 800; color: #94a3b8; text-decoration: line-through; margin-bottom: -2px; }
.m-price { font-size: 16px; font-weight: 900; letter-spacing: -0.5px; }
.m-specs { background: #f8fafc; border: 2px dashed #cbd5e1; border-radius: 12px; padding: 10px; display: grid; grid-template-columns: 1fr 1fr; gap: 6px; font-size: 10px; font-weight: 800; color: #475569; }
.m-spec-full { grid-column: 1 / -1; }
</style>

<?php if (!empty($_SESSION['flash_home_err'])): ?>
<div class="flash-alert flash-alert--err">
  <i class="ph-bold ph-warning-circle"></i>
  <?= htmlspecialchars($_SESSION['flash_home_err']) ?>
</div>
<?php unset($_SESSION['flash_home_err']); endif; ?>

<!-- ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ -->
<!-- 1. HERO STRIP                          -->
<!-- ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ -->
<div class="hero-strip">
  <div class="hero-strip__top">
    <div class="hero-strip__identity">
      <div class="hero-strip__avatar"><?= strtoupper(substr($user['username'], 0, 1)) ?></div>
      <div>
        <div class="hero-strip__name">Halo, <?= htmlspecialchars($user['username']) ?>!</div>
        <div class="hero-strip__tier">
          <i class="ph-fill ph-star" style="font-size:9px"></i>
          <?= htmlspecialchars($membership_name) ?>
        </div>
      </div>
    </div>
    <a href="<?= $is_guest ? '/login' : '/upgrade' ?>" class="hero-strip__cta">
      <i class="ph-bold <?= $is_guest ? 'ph-sign-in' : 'ph-rocket-launch' ?>" style="font-size:13px"></i>
      <?= $is_guest ? 'MASUK' : 'UPGRADE' ?>
    </a>
  </div>

  <?php if (!$is_guest): ?>
  <?php $pct = $watch_limit > 0 ? min(100, round(($watch_today / $watch_limit) * 100)) : 0; ?>
  <div class="hero-strip__progress-wrap">
    <div class="hero-strip__progress-header">
      <div class="hero-strip__progress-label">
        <i class="ph-bold ph-video-camera" style="font-size:11px"></i>
        Video Ditonton Hari Ini
      </div>
      <div class="hero-strip__progress-count"><?= $watch_today ?>/<?= $watch_limit ?></div>
    </div>
    <div class="hero-strip__progress-track">
      <div class="hero-strip__progress-fill" style="width:<?= $pct ?>%"></div>
    </div>
  </div>
  <?php endif; ?>
</div>

<!-- ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ -->
<!-- 2. BALANCE + REF ROW                   -->
<!-- ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ -->
<div class="dash-row">
  <a href="/withdraw" class="bal-tile bal-tile--wd" style="text-decoration:none;color:inherit;display:block">
    <div style="display:flex;align-items:center;gap:6px;margin-bottom:4px">
      <div class="bal-tile__icon" style="width:26px;height:26px;border-radius:8px"><i class="ph-fill ph-arrow-circle-up" style="color:#10b981;font-size:14px"></i></div>
      <div class="bal-tile__lbl" style="font-size:9px">Saldo Dapat Dicairkan</div>
    </div>
    <div class="bal-tile__val" style="font-size:14px;margin-bottom:6px"><?= format_rp((float)$user['balance_wd']) ?></div>
    <div class="bal-tile__btn"><i class="ph-bold ph-upload-simple" style="font-size:10px"></i> Cairkan</div>
  </a>
  <a href="/deposit" class="bal-tile bal-tile--dep" style="text-decoration:none;color:inherit;display:block">
    <div style="display:flex;align-items:center;gap:6px;margin-bottom:4px">
      <div class="bal-tile__icon" style="width:26px;height:26px;border-radius:8px"><i class="ph-fill ph-bank" style="color:#3b82f6;font-size:14px"></i></div>
      <div class="bal-tile__lbl" style="font-size:9px">Saldo Beli</div>
    </div>
    <div class="bal-tile__val" style="font-size:14px;margin-bottom:6px"><?= format_rp((float)$user['balance_dep']) ?></div>
    <div class="bal-tile__btn"><i class="ph-bold ph-plus-circle" style="font-size:10px"></i> Top Up</div>
  </a>
</div>

<!-- ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ -->
<!-- 3. QUICK ACTIONS 4-COLUMN              -->
<!-- ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ -->
<div class="qa-grid" style="margin-bottom:10px">
  <a href="/history" class="qa-item">
    <div class="qa-item__icon" style="width:44px;height:44px;border-radius:14px;background:linear-gradient(135deg,#a78bfa,#7c3aed);box-shadow:0 3px 0 #5b21b6;color:#fff"><i class="ph-fill ph-receipt"></i></div>
    <span class="qa-item__label">Riwayat</span>
  </a>
  <a href="/missions" class="qa-item">
    <div class="qa-item__icon" style="width:44px;height:44px;border-radius:14px;background:linear-gradient(135deg,#f97316,#ea580c);box-shadow:0 3px 0 #c2410c;color:#fff"><i class="ph-fill ph-target"></i></div>
    <span class="qa-item__label">Misi</span>
  </a>
  <a href="/checkin" class="qa-item">
    <div class="qa-item__icon" style="width:44px;height:44px;border-radius:14px;background:linear-gradient(135deg,#f472b6,#db2777);box-shadow:0 3px 0 #9d174d;color:#fff"><i class="ph-fill ph-calendar-check"></i></div>
    <span class="qa-item__label">Absen</span>
  </a>
  <a href="/referral" class="qa-item">
    <div class="qa-item__icon" style="width:44px;height:44px;border-radius:14px;background:linear-gradient(135deg,#34d399,#059669);box-shadow:0 3px 0 #047857;color:#fff"><i class="ph-fill ph-users"></i></div>
    <span class="qa-item__label">Referral</span>
  </a>
  <a href="/redeem" class="qa-item">
    <div class="qa-item__icon" style="width:44px;height:44px;border-radius:14px;background:linear-gradient(135deg,#60a5fa,#1d4ed8);box-shadow:0 3px 0 #1e3a8a;color:#fff"><i class="ph-fill ph-gift"></i></div>
    <span class="qa-item__label">Redeem</span>
  </a>
  <?php if (setting($pdo, 'investment_enabled', '1') === '1'): ?>
  <a href="/invest" class="qa-item">
    <div class="qa-item__icon" style="width:44px;height:44px;border-radius:14px;background:linear-gradient(135deg,#fbbf24,#d97706);box-shadow:0 3px 0 #b45309;color:#fff"><i class="ph-fill ph-trend-up"></i></div>
    <span class="qa-item__label">Investasi</span>
  </a>
  <?php endif; ?>
  <a href="/upgrade" class="qa-item">
    <div class="qa-item__icon" style="width:44px;height:44px;border-radius:14px;background:linear-gradient(135deg,#fb923c,#dc2626);box-shadow:0 3px 0 #991b1b;color:#fff"><i class="ph-fill ph-crown"></i></div>
    <span class="qa-item__label">Upgrade</span>
  </a>
  <a href="/panduan" class="qa-item">
    <div class="qa-item__icon" style="width:44px;height:44px;border-radius:14px;background:linear-gradient(135deg,#6ee7b7,#0891b2);box-shadow:0 3px 0 #0e7490;color:#fff"><i class="ph-fill ph-book-open"></i></div>
    <span class="qa-item__label">Panduan</span>
  </a>
</div>

<!-- Limit warn -->
<?php if (!$is_guest && $watch_today >= $watch_limit): ?>
<div class="limit-warn">
  <i class="ph-bold ph-warning-circle" style="font-size:16px;color:#d97706;flex-shrink:0"></i>
  Limit tonton hari ini sudah penuh.
  <a href="/upgrade">Upgrade →</a>
</div>
<?php endif; ?>

<!-- Newcomer guide -->
<?php
$is_newcomer = !$is_guest && (empty($history) || (isset($user['created_at']) && strtotime($user['created_at']) > time() - 3 * 86400) || ($user['balance_wd'] == 0 && $user['balance_dep'] == 0));
if ($is_newcomer): ?>
<div class="guide-banner">
  <i class="ph-fill ph-book-open-text guide-banner__icon"></i>
  <div class="guide-banner__text">
    <strong>Baru gabung di Meloton?</strong>
    <span>Baca panduan cara dapetin reward dulu yuk!</span>
  </div>
  <a href="/panduan" class="guide-banner__btn">Panduan</a>
</div>
<?php endif; ?>

<!-- ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ -->
<!-- 4. NOTIFICATIONS PREVIEW               -->
<!-- ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ -->
<?php if (!empty($notif_preview)): ?>
<?php
$notif_dot_colors = [
  'info' => '#0284c7', 'success' => '#16a34a',
  'warning' => '#d97706', 'alert' => '#e11d48', 'congrats' => '#ca8a04',
];
?>
<div class="sh">
  <div class="sh__title">
    <i class="ph-fill ph-bell-ringing" style="color:#e11d48"></i>
    Notifikasi
    <?php if ($notif_unread > 0): ?>
      <span class="sh__badge"><?= $notif_unread > 9 ? '9+' : $notif_unread ?></span>
    <?php endif; ?>
  </div>
  <a href="/notifications" class="sh__link">Lihat →</a>
</div>
<div class="notif-compact">
  <?php foreach ($notif_preview as $nf):
    $dot_color = $notif_dot_colors[$nf['type']] ?? '#0284c7'; ?>
  <div class="notif-compact-item">
    <div class="notif-compact-dot" style="background:<?= $dot_color ?>"></div>
    <div class="notif-compact-body">
      <div class="notif-compact-title"><?= htmlspecialchars($nf['title']) ?></div>
      <div class="notif-compact-msg"><?= htmlspecialchars($nf['message']) ?></div>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- ━━ 5. VIDEOS ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ -->
<?php if (!empty($videos)): ?>
<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px">
  <div class="sh__title"><i class="ph-fill ph-video-camera" style="color:#7c3aed"></i> Video Tersedia</div>
  <a href="/videos" class="sh__link">Semua →</a>
</div>
<div class="vid-scroll">
  <?php foreach ($videos as $v): ?>
  <a href="/watch?id=<?= $v['id'] ?>" class="vid-card">
    <div class="vid-card__thumb">
      <img src="<?= yt_thumb($v['youtube_id']) ?>" alt="<?= htmlspecialchars($v['title']) ?>" loading="lazy"
           onerror="this.src='https://img.youtube.com/vi/<?= $v['youtube_id'] ?>/hqdefault.jpg'">
      <div class="vid-card__play-icon"><i class="ph-fill ph-play-circle"></i></div>
      <div class="vid-card__badge">+<?= format_rp((float)$v['reward_amount']) ?></div>
    </div>
    <div class="vid-card__body">
      <div class="vid-card__title"><?= htmlspecialchars($v['title']) ?></div>
      <div class="vid-card__meta">
        <span style="color:#10b981;display:flex;align-items:center;gap:2px"><i class="ph-bold ph-coins"></i> <?= format_rp((float)$v['reward_amount']) ?></span>
        <span style="display:flex;align-items:center;gap:2px"><i class="ph-bold ph-clock"></i> <?= $v['watch_duration'] ?>s</span>
      </div>
    </div>
  </a>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- ━━ 5B. UPGRADE SHOWCASE ━━━━━━━━━━━━━━━━━━━━ -->
<?php if (!empty($showcase_memberships)): ?>
<div style="display:flex;align-items:center;justify-content:space-between;margin-top:16px;margin-bottom:8px">
  <div class="sh__title"><i class="ph-fill ph-crown" style="color:#f59e0b"></i> Upgrade Level</div>
  <a href="/upgrade" class="sh__link">Semua Level →</a>
</div>
<div>
  <?php 
  foreach ($showcase_memberships as $i => $m):
    $m_class = "m-card--" . ($i % 5);
    $bg_color = ['#f8fafc','#f0f9ff','#fefce8','#faf5ff','#fef2f2'][$i % 5];
    $txt_color = ['#0f172a','#0369a1','#b45309','#6b21a8','#b91c1c'][$i % 5];
  ?>
  <a href="/upgrade" class="m-card <?= $m_class ?>">
    <?php if ($i === 2): ?>
      <div class="m-badge-pop">🔥 TERPOPULER</div>
    <?php elseif ((float)$m['original_price'] > 0): ?>
      <div class="m-badge-pro">🎉 PROMO DISKON!</div>
    <?php endif; ?>
    
    <div class="m-hdr">
      <div style="display:flex;align-items:center;gap:10px">
        <div class="m-ico-box" style="background:<?= $bg_color ?>;color:<?= $txt_color ?>;border-color:<?= $txt_color ?>">
          <?= htmlspecialchars($m['icon'] ?: '⭐') ?>
        </div>
        <div>
          <div class="m-name" style="color:<?= $txt_color ?>"><?= htmlspecialchars($m['name']) ?></div>
          <div class="m-dur"><i class="ph-bold ph-hourglass"></i> <?= $m['duration_days'] ?> Hari</div>
        </div>
      </div>
      <div class="m-price-box">
        <?php if ((float)$m['original_price'] > 0): ?>
        <div class="m-price-old"><?= format_rp((float)$m['original_price']) ?></div>
        <?php endif; ?>
        <div class="m-price" style="color:<?= $txt_color ?>"><?= format_rp((float)$m['price']) ?></div>
      </div>
    </div>
    <div class="m-specs">
      <div><i class="ph-bold ph-video-camera"></i> <?= $m['watch_limit'] ?>× Tonton / hari</div>
      <div><i class="ph-bold ph-trend-up"></i> Maksimal Narik <?= (float)$m['max_wd'] > 0 ? format_rp((float)$m['max_wd']) : '<span style="color:#10b981">Tanpa batas</span>' ?></div>
    </div>
  </a>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ -->
<!-- 6. REFERRAL COPY ROW                   -->
<!-- ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ -->
<?php if (!$is_guest): ?>
<div style="background:#fff;border:2.5px solid #7dd3e8;border-radius:14px;padding:10px 12px;box-shadow:0 4px 0 #7dd3e8;display:flex;align-items:center;gap:10px;margin-bottom:12px">
  <i class="ph-fill ph-share-network" style="font-size:20px;color:#0891b2;flex-shrink:0"></i>
  <div style="flex:1;min-width:0">
    <div style="font-size:10px;font-weight:700;color:#64748b">Kode Referral Kamu</div>
    <div style="font-size:14px;font-weight:900;color:#0c4a6e;letter-spacing:1px"><?= htmlspecialchars($user['referral_code']) ?></div>
  </div>
  <button type="button" onclick="copyRef('<?= htmlspecialchars($user['referral_code']) ?>')"
          style="background:#e0f9ff;border:1.5px solid #7dd3e8;border-radius:10px;padding:7px 12px;font-size:11px;font-weight:900;color:#0891b2;cursor:pointer;display:flex;align-items:center;gap:4px;flex-shrink:0">
    <i class="ph-bold ph-copy"></i> Salin
  </button>
</div>
<div id="ref-toast" style="display:none;text-align:center;font-size:11px;font-weight:700;color:#10b981;margin-bottom:8px;padding:6px;background:#f0fdf4;border-radius:8px">
  ✓ Kode berhasil disalin!
</div>
<?php endif; ?>

<!-- ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ -->
<!-- 7. RECENT ACTIVITY                     -->
<!-- ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ -->
<?php if (!empty($history)): ?>
<div class="sh">
  <div class="sh__title">
    <i class="ph-fill ph-clock-counter-clockwise" style="color:#0891b2"></i>
    Aktivitas
  </div>
</div>
<div class="activity-feed">
  <?php foreach ($history as $h): ?>
  <div class="activity-item">
    <div class="activity-icon"><i class="ph-fill ph-monitor-play"></i></div>
    <div class="activity-text">
      <div class="activity-title"><?= htmlspecialchars($h['title']) ?></div>
      <div class="activity-date"><i class="ph-bold ph-calendar-blank" style="font-size:9px"></i> <?= date('d M H:i', strtotime($h['watched_at'])) ?></div>
    </div>
    <div class="activity-amount">+<?= format_rp((float)$h['reward_given']) ?></div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ -->
<!-- POPUP PANDUAN                          -->
<!-- ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ -->
<?php
$popup_enabled     = setting($pdo, 'popup_enabled', '1') === '1';
$popup_title       = setting($pdo, 'popup_title', 'Hei, sudah baca panduan?');
$popup_body        = setting($pdo, 'popup_body', 'Biar makin lancar dapat reward, yuk baca dulu cara kerja Meloton!');
$popup_cta_text    = setting($pdo, 'popup_cta_text', 'Baca Panduan');
$popup_cta_url     = setting($pdo, 'popup_cta_url', '/panduan');
$popup_delay       = max(0, (int) setting($pdo, 'popup_delay', '1500'));
$popup_reset_hours = max(0, (int) setting($pdo, 'popup_reset_hours', '0'));
?>
<?php if ($popup_enabled): ?>
<div id="guide-popup" style="display:none; position:fixed; inset:0; background:rgba(15, 23, 42, 0.6); backdrop-filter:blur(4px); z-index:100000; align-items:center; justify-content:center; padding:20px;">
  <div style="background:#fff; border-radius:24px; padding:24px 20px 20px; max-width:320px; width:100%; transform:scale(0.8); opacity:0; transition:all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275); position:relative; border:4px solid #7dd3e8; box-shadow:0 10px 0 #0369a1, 0 16px 32px rgba(0,0,0,0.3);">
    <button onclick="closePopup()" style="position:absolute; top:-12px; right:-12px; background:#ef4444; color:#fff; border:3px solid #fff; width:36px; height:36px; border-radius:50%; font-size:16px; font-weight:900; display:flex; align-items:center; justify-content:center; cursor:pointer; box-shadow:0 4px 0 #b91c1c; transition:transform 0.1s;">
      <i class="ph-bold ph-x"></i>
    </button>
    <div style="width:64px; height:64px; background:linear-gradient(135deg, #fde047, #f59e0b); border:3px solid #d97706; box-shadow:0 4px 0 #b45309; border-radius:20px; display:flex; align-items:center; justify-content:center; font-size:32px; margin:-48px auto 16px; color:#78350f;">
      <i class="ph-fill ph-book-open"></i>
    </div>
    <h3 style="font-size:18px; font-weight:900; text-align:center; margin:0 0 8px; color:#0f172a; line-height:1.2;"><?= htmlspecialchars($popup_title) ?></h3>
    <p style="font-size:13px; line-height:1.5; color:#475569; font-weight:700; text-align:center; margin:0 0 20px"><?= nl2br(htmlspecialchars($popup_body)) ?></p>
    <div style="display:flex; flex-direction:column; gap:8px;">
      <a href="<?= htmlspecialchars($popup_cta_url) ?>" style="display:flex; align-items:center; justify-content:center; gap:8px; width:100%; font-size:14px; font-weight:900; padding:14px; border-radius:16px; background:linear-gradient(135deg, #0ea5e9, #0284c7); border:3px solid #fff; box-shadow:0 6px 0 #0369a1; color:#fff; text-decoration:none; transition:transform 0.1s;">
        <i class="ph-bold ph-book-bookmark"></i> <?= htmlspecialchars($popup_cta_text) ?>
      </a>
      <button type="button" onclick="closePopup()" style="width:100%; padding:10px; background:transparent; border:none; font-size:12px; font-weight:800; color:#94a3b8; cursor:pointer;">Nanti Saja</button>
    </div>
  </div>
</div>
<script>
function closePopup() {
  const p = document.getElementById('guide-popup');
  const c = p.querySelector('div');
  c.style.transform = 'scale(0.8)';
  c.style.opacity = '0';
  setTimeout(() => p.style.display = 'none', 300);
  try { localStorage.setItem('tonton_popup_seen', JSON.stringify({ts: Date.now()})); } catch(e){}
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
      if (resetMs <= 0 || (Date.now() - data.ts) < resetMs) return;
    }
  } catch(e){}
  setTimeout(() => { 
    p.style.display = 'flex'; 
    p.offsetHeight; // force reflow
    c.style.transform = 'scale(1)'; 
    c.style.opacity = '1';
  }, <?= $popup_delay ?>);
});
</script>
<?php endif; ?>

<script>
function copyRef(code) {
  navigator.clipboard.writeText(code).then(() => {
    const toast = document.getElementById('ref-toast');
    toast.style.display = 'block';
    setTimeout(() => toast.style.display = 'none', 2000);
  }).catch(() => {});
}
</script>

<?php require dirname(__DIR__) . '/partials/footer.php'; ?>
