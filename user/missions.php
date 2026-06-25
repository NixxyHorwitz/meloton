<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/auth/guard.php';

// ── Mission definitions (hardcoded) ───────────────────────────
$ALL_MISSIONS = [
    // HARIAN
    ['slug'=>'daily_watch_3',       'category'=>'daily',    'title'=>'Tonton 3 Video',             'desc'=>'Tonton minimal 3 video hari ini.',              'target'=>3,   'reward'=>1000,  'icon'=>'ph-film-slate'],
    ['slug'=>'daily_watch_5',       'category'=>'daily',    'title'=>'Tonton 5 Video',             'desc'=>'Tonton minimal 5 video hari ini.',              'target'=>5,   'reward'=>2500,  'icon'=>'ph-film-reel'],
    // MINGGUAN
    ['slug'=>'weekly_streak_7',     'category'=>'weekly',   'title'=>'Streak 7 Hari',              'desc'=>'Check-in setiap hari selama 7 hari penuh.',  'target'=>7,   'reward'=>10000, 'icon'=>'ph-fire'],
    ['slug'=>'weekly_watch_20',     'category'=>'weekly',   'title'=>'Tonton 20 Video Minggu Ini', 'desc'=>'Tonton total 20 video minggu ini.',          'target'=>20,  'reward'=>8000,  'icon'=>'ph-television'],
    ['slug'=>'weekly_watch_7days',  'category'=>'weekly',   'title'=>'Aktif 7 Hari (Nonton)',      'desc'=>'Tonton video di 7 hari berbeda minggu ini.', 'target'=>7,   'reward'=>12000, 'icon'=>'ph-star'],
    // LIFETIME
    ['slug'=>'lifetime_first_ref',  'category'=>'lifetime', 'title'=>'Daftarkan 1 Referral',       'desc'=>'Ajak 1 teman bergabung via kode referralmu.','target'=>1,   'reward'=>5000,  'icon'=>'ph-user-plus'],
    ['slug'=>'lifetime_5_refs',     'category'=>'lifetime', 'title'=>'Agen Rekruter',               'desc'=>'Ajak 5 teman bergabung via kode referralmu.','target'=>5,   'reward'=>15000, 'icon'=>'ph-users-three'],
    ['slug'=>'lifetime_first_wd',   'category'=>'lifetime', 'title'=>'Penarikan Pertama',           'desc'=>'Lakukan penarikan saldo pertama kalinya.',   'target'=>1,   'reward'=>3000,  'icon'=>'ph-money'],
    ['slug'=>'lifetime_100_videos', 'category'=>'lifetime', 'title'=>'Penonton Sejati',             'desc'=>'Tonton total 100 video di Meloton.',       'target'=>100, 'reward'=>10000, 'icon'=>'ph-popcorn'],
    ['slug'=>'lifetime_upgrade',    'category'=>'lifetime', 'title'=>'Member Premium',              'desc'=>'Upgrade ke paket membership berbayar.',      'target'=>1,   'reward'=>8000,  'icon'=>'ph-crown'],
];

$today    = date('Y-m-d');
$weekKey  = date('Y-\WW'); // e.g. 2026-W23

// ── Helper: get real-time progress ───────────────────────────
function get_progress(PDO $pdo, array $user, array $mission): int {
    $uid = $user['id'];
    switch ($mission['slug']) {
        case 'daily_watch_3':
        case 'daily_watch_5':
            $s = $pdo->prepare("SELECT COUNT(*) FROM watch_history WHERE user_id=? AND DATE(watched_at)=CURDATE()");
            $s->execute([$uid]);
            return (int)$s->fetchColumn();
        case 'daily_checkin':
            return ($user['last_checkin'] === date('Y-m-d')) ? 1 : 0;
        case 'weekly_streak_7':
            // Count distinct days this week with check-in (approximate via watch_history)
            $s = $pdo->prepare("SELECT COUNT(DISTINCT DATE(watched_at)) FROM watch_history WHERE user_id=? AND YEARWEEK(watched_at,1)=YEARWEEK(CURDATE(),1)");
            $s->execute([$uid]);
            return min(7, (int)$s->fetchColumn());
        case 'weekly_watch_20':
            $s = $pdo->prepare("SELECT COUNT(*) FROM watch_history WHERE user_id=? AND YEARWEEK(watched_at,1)=YEARWEEK(CURDATE(),1)");
            $s->execute([$uid]);
            return (int)$s->fetchColumn();
        case 'weekly_watch_7days':
            $s = $pdo->prepare("SELECT COUNT(DISTINCT DATE(watched_at)) FROM watch_history WHERE user_id=? AND YEARWEEK(watched_at,1)=YEARWEEK(CURDATE(),1)");
            $s->execute([$uid]);
            return (int)$s->fetchColumn();
        case 'lifetime_first_ref':
        case 'lifetime_5_refs':
            $s = $pdo->prepare("SELECT COUNT(*) FROM users WHERE referred_by=?");
            $s->execute([$user['referral_code']]);
            return (int)$s->fetchColumn();
        case 'lifetime_first_wd':
            $s = $pdo->prepare("SELECT COUNT(*) FROM withdrawals WHERE user_id=?");
            $s->execute([$uid]);
            return (int)$s->fetchColumn();
        case 'lifetime_100_videos':
            $s = $pdo->prepare("SELECT COUNT(*) FROM watch_history WHERE user_id=?");
            $s->execute([$uid]);
            return (int)$s->fetchColumn();
        case 'lifetime_upgrade':
            return ($user['membership_id'] ? 1 : 0);
    }
    return 0;
}

// ── Helper: get period key ────────────────────────────────────
function get_period_key(string $category): ?string {
    if ($category === 'daily')   return date('Y-m-d');
    if ($category === 'weekly')  return date('Y-\WW');
    return null; // lifetime
}

// ── Helper: check if already claimed ─────────────────────────
function is_claimed(PDO $pdo, int|string $user_id, string $slug, ?string $period_key): bool {
    $user_id = (int)$user_id;
    $s = $pdo->prepare("SELECT claimed_at FROM user_missions WHERE user_id=? AND mission_slug=? AND period_key<=>?");
    $s->execute([$user_id, $slug, $period_key]);
    $row = $s->fetch();
    return $row && $row['claimed_at'] !== null;
}

// ── Handle AJAX claim ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'claim_mission') {
    header('Content-Type: application/json');
    if (!csrf_verify()) { echo json_encode(['ok'=>false,'msg'=>'CSRF tidak valid.']); exit; }

    $slug = trim($_POST['slug'] ?? '');
    $mission = null;
    foreach ($ALL_MISSIONS as $m) {
        if ($m['slug'] === $slug) { $mission = $m; break; }
    }
    if (!$mission) { echo json_encode(['ok'=>false,'msg'=>'Misi tidak ditemukan.']); exit; }

    $period = get_period_key($mission['category']);
    if (is_claimed($pdo, (int)$user['id'], $slug, $period)) {
        echo json_encode(['ok'=>false,'msg'=>'Misi ini sudah pernah diklaim!']); exit;
    }

    $progress = get_progress($pdo, $user, $mission);
    if ($progress < $mission['target']) {
        echo json_encode(['ok'=>false,'msg'=>"Progress belum cukup. ({$progress}/{$mission['target']})"]); exit;
    }

    try {
        $pdo->beginTransaction();
        // Upsert record
        $pdo->prepare("INSERT INTO user_missions (user_id, mission_slug, progress, completed_at, claimed_at, period_key)
            VALUES (?, ?, ?, NOW(), NOW(), ?)
            ON DUPLICATE KEY UPDATE progress=VALUES(progress), completed_at=COALESCE(completed_at,NOW()), claimed_at=NOW()")
            ->execute([$user['id'], $slug, $progress, $period]);
        // Give reward
        $pdo->prepare("UPDATE users SET balance_wd = balance_wd + ? WHERE id=?")
            ->execute([$mission['reward'], $user['id']]);
        $pdo->commit();
        echo json_encode(['ok'=>true,'msg'=>'🎉 Reward diklaim! +'.number_format($mission['reward'],0,',','.').' ke Saldo Tarik.','reward'=>$mission['reward']]);
    } catch (\Throwable $e) {
        $pdo->rollBack();
        echo json_encode(['ok'=>false,'msg'=>'Terjadi kesalahan: '.$e->getMessage()]);
    }
    exit;
}

// ── Build mission data with progress ─────────────────────────
$missions_data = [];
foreach ($ALL_MISSIONS as $m) {
    $period   = get_period_key($m['category']);
    $progress = get_progress($pdo, $user, $m);
    $claimed  = is_claimed($pdo, (int)$user['id'], $m['slug'], $period);
    $done     = $progress >= $m['target'];

    $missions_data[] = array_merge($m, [
        'progress' => min($progress, $m['target']),
        'claimed'  => $claimed,
        'done'     => $done,
        'period'   => $period,
    ]);
}

$daily    = array_filter($missions_data, fn($m) => $m['category'] === 'daily');
$weekly   = array_filter($missions_data, fn($m) => $m['category'] === 'weekly');
$lifetime = array_filter($missions_data, fn($m) => $m['category'] === 'lifetime');

$claimed_today = count(array_filter($missions_data, fn($m) => $m['claimed']));

$pageTitle  = 'Misi — Meloton';
$activePage = 'missions';
require dirname(__DIR__) . '/partials/header.php';
?>

<style>
/* ══════════════════════════════════════════════
   MISSION PAGE — CASUAL GAME STYLE
   ══════════════════════════════════════════════ */
.mission-page { padding: 0 0 20px; }

/* ── Hero Card ── */
.ms-hero {
  background: linear-gradient(135deg, #0c4a6e 0%, #0e7490 55%, #06b6d4 100%);
  border: 3px solid #075985;
  border-radius: 22px;
  box-shadow: 0 8px 0 #0c4a6e;
  padding: 16px;
  position: relative;
  overflow: hidden;
  margin-bottom: 16px;
  display: flex;
  align-items: center;
  justify-content: space-between;
}
.ms-hero::before {
  content: '';
  position: absolute; top: -30px; left: -20px;
  width: 120px; height: 120px;
  border-radius: 50%;
  background: rgba(255,255,255,0.06);
  pointer-events: none;
}
.ms-hero__title { font-size: 24px; font-weight: 900; color: #fff; text-shadow: 0 2px 0 rgba(0,0,0,0.2); line-height: 1.1; margin-bottom: 2px; }
.ms-hero__sub { font-size: 11px; font-weight: 700; color: rgba(255,255,255,0.7); }
.ms-hero__badge {
  background: linear-gradient(135deg, #fbbf24, #f59e0b);
  border: 2.5px solid #fff;
  border-radius: 16px;
  padding: 6px 14px;
  text-align: center;
  box-shadow: 0 4px 0 rgba(0,0,0,0.2);
  transform: rotate(3deg);
}
.ms-hero__badge-val { font-size: 22px; font-weight: 900; color: #0c4a6e; line-height: 1; }
.ms-hero__badge-lbl { font-size: 9px; font-weight: 900; color: #92400e; text-transform: uppercase; letter-spacing: 0.5px; }

/* ── Tabs ── */
.ms-tabs {
  display: flex;
  background: #e0f9ff;
  border: 2.5px solid #7dd3e8;
  border-radius: 16px;
  padding: 4px;
  box-shadow: 0 4px 0 #7dd3e8;
  margin-bottom: 16px;
}
.ms-tab {
  flex: 1;
  padding: 8px 4px;
  text-align: center;
  font-size: 12px; font-weight: 800; color: #0c4a6e;
  background: transparent;
  border: none; border-radius: 12px;
  transition: all 0.2s;
  cursor: pointer;
  -webkit-tap-highlight-color: transparent;
}
.ms-tab.active {
  background: #fff;
  box-shadow: 0 2px 4px rgba(8,145,178,0.15);
  color: #0891b2;
}

/* ── Mission Card ── */
.ms-card {
  background: #fff;
  border: 2.5px solid #7dd3e8;
  border-radius: 16px;
  box-shadow: 0 5px 0 #7dd3e8;
  margin-bottom: 12px;
  overflow: hidden;
  transition: opacity 0.3s;
}
.ms-card--done { background: #f0fdf4; border-color: #86efac; box-shadow: 0 5px 0 #86efac; }
.ms-card--claimed { background: #f8fafc; border-color: #cbd5e1; box-shadow: 0 5px 0 #cbd5e1; opacity: 0.7; }

.ms-card__head {
  display: flex; align-items: center; gap: 12px;
  padding: 12px 12px 8px;
}
.ms-card__icon {
  width: 44px; height: 44px; flex-shrink: 0;
  background: linear-gradient(135deg, #fbbf24, #f59e0b);
  border: 2px solid #fff; border-radius: 14px;
  box-shadow: 0 3px 0 rgba(0,0,0,0.1);
  display: flex; align-items: center; justify-content: center;
  font-size: 22px; color: #fff; text-shadow: 0 1px 0 rgba(0,0,0,0.2);
}
.ms-card--done .ms-card__icon { background: linear-gradient(135deg, #34d399, #10b981); }
.ms-card--claimed .ms-card__icon { background: linear-gradient(135deg, #94a3b8, #64748b); box-shadow: none; }

.ms-card__info { flex: 1; min-width: 0; }
.ms-card__title { font-size: 14px; font-weight: 900; color: #0c4a6e; line-height: 1.2; margin-bottom: 2px; }
.ms-card__desc { font-size: 10px; font-weight: 700; color: #64748b; line-height: 1.3; }

.ms-card__reward {
  background: #fef3c7; border: 1.5px solid #fbbf24; border-radius: 10px;
  padding: 4px 8px; font-size: 11px; font-weight: 900; color: #d97706;
  flex-shrink: 0; box-shadow: 0 2px 0 rgba(245,158,11,0.2);
}
.ms-card--claimed .ms-card__reward { background: #f1f5f9; border-color: #cbd5e1; color: #94a3b8; box-shadow: none; }

/* ── Progress ── */
.ms-prog { padding: 0 12px 12px; }
.ms-prog-bar-wrap {
  height: 12px; background: #e0f9ff;
  border-radius: 6px; overflow: hidden;
  position: relative; box-shadow: inset 0 2px 4px rgba(0,0,0,0.05);
}
.ms-card--done .ms-prog-bar-wrap { background: #dcfce7; }
.ms-card--claimed .ms-prog-bar-wrap { background: #f1f5f9; box-shadow: none; }

.ms-prog-bar {
  height: 100%;
  background: linear-gradient(90deg, #38bdf8, #0ea5e9);
  border-radius: 6px;
  transition: width 0.5s ease;
}
.ms-card--done .ms-prog-bar { background: linear-gradient(90deg, #34d399, #10b981); }
.ms-card--claimed .ms-prog-bar { background: #cbd5e1; }

.ms-prog-meta {
  display: flex; justify-content: space-between;
  font-size: 10px; font-weight: 800; color: #64748b; margin-top: 4px;
}

/* ── Buttons ── */
.ms-btn {
  width: 100%; margin-top: 10px; padding: 10px;
  border-radius: 12px; font-size: 12px; font-weight: 900;
  display: flex; align-items: center; justify-content: center; gap: 6px;
  cursor: pointer; transition: transform 0.1s, box-shadow 0.1s;
}
.ms-btn:active { transform: translateY(3px); box-shadow: none !important; }

/* Locked */
.ms-btn--locked {
  background: #f1f5f9; border: 2px solid #e2e8f0; color: #94a3b8;
  cursor: not-allowed;
}
/* Claim ready */
.ms-btn--ready {
  background: linear-gradient(135deg, #22d3ee, #0891b2);
  border: 2px solid #a5f3fc; color: #fff;
  box-shadow: 0 4px 0 #0e7490;
}
/* Claimed */
.ms-btn--claimed {
  background: #dcfce7; border: 2px solid #86efac; color: #16a34a;
  cursor: default;
}
.ms-btn--claimed:active { transform: none; }

/* ── Sections ── */
.ms-panel { display: none; }
.ms-panel.active { display: block; animation: fade-in 0.3s; }
.ms-section-hdr {
  font-size: 11px; font-weight: 900; color: #94a3b8;
  text-transform: uppercase; letter-spacing: 0.5px;
  margin-bottom: 10px; display: flex; align-items: center; gap: 6px;
}

@keyframes fade-in { from { opacity: 0; transform: translateY(5px); } to { opacity: 1; transform: none; } }
@keyframes spin { to { transform: rotate(360deg); } }
</style>

<div class="mission-page">

  <!-- ── HERO ── -->
  <div class="ms-hero">
    <div>
      <div class="ms-hero__title">🎯 Misi</div>
      <div class="ms-hero__sub">Selesaikan misi, klaim reward gratis!</div>
    </div>
    <div class="ms-hero__badge">
      <div class="ms-hero__badge-val"><?= $claimed_today ?></div>
      <div class="ms-hero__badge-lbl">Diklaim</div>
    </div>
  </div>

  <!-- ── TABS ── -->
  <div class="ms-tabs" role="tablist">
    <button class="ms-tab active" id="tab-daily" onclick="switchTab('daily')">Harian</button>
    <button class="ms-tab" id="tab-weekly" onclick="switchTab('weekly')">Mingguan</button>
    <button class="ms-tab" id="tab-lifetime" onclick="switchTab('lifetime')">Pencapaian</button>
  </div>

  <a href="/minigame" style="display:block; background:linear-gradient(135deg, #fef08a, #f59e0b); border:3px solid #fff; border-radius:20px; box-shadow:0 8px 0 #d97706; padding:16px; text-decoration:none; margin-bottom:20px; position:relative; overflow:hidden; transition:transform 0.2s;" onclick="this.style.transform='translateY(4px)'; this.style.boxShadow='0 4px 0 #d97706'; setTimeout(()=>this.style.transform='none',200);">
    <div style="position:relative; z-index:2; display:flex; align-items:center; justify-content:space-between;">
        <div>
            <h3 style="margin:0 0 4px; font-size:18px; font-weight:900; color:#78350f; text-shadow:1px 1px 0 #fde047;">TAP-TAP FRENZY 🪅</h3>
            <p style="margin:0; font-size:12px; font-weight:800; color:#92400e;">Mainkan game harian dan menangkan Saldo instan!</p>
        </div>
        <div style="background:#fff; width:44px; height:44px; border-radius:50%; display:flex; align-items:center; justify-content:center; color:#d97706; font-size:24px; box-shadow:0 4px 6px rgba(0,0,0,0.1);">
            <i class="ph-fill ph-play"></i>
        </div>
    </div>
    <i class="ph-fill ph-coin" style="position:absolute; right:-10px; bottom:-20px; font-size:80px; color:rgba(255,255,255,0.3); z-index:1;"></i>
  </a>

  <!-- ── PANELS ── -->
  
  <!-- DAILY -->
  <div class="ms-panel active" id="panel-daily">
    <div class="ms-section-hdr"><i class="ph-fill ph-sun" style="color:#f59e0b"></i> Misi Harian — Reset tiap hari</div>
    <?php foreach ($daily as $m): ?>
    <?php
      $pct = $m['target'] > 0 ? min(100, round($m['progress'] / $m['target'] * 100)) : 0;
      $cardClass = $m['claimed'] ? 'ms-card--claimed' : ($m['done'] ? 'ms-card--done' : '');
    ?>
    <div class="ms-card <?= $cardClass ?>" id="mc-<?= htmlspecialchars($m['slug']) ?>">
      <div class="ms-card__head">
        <div class="ms-card__icon"><i class="ph-fill <?= htmlspecialchars($m['icon']) ?>"></i></div>
        <div class="ms-card__info">
          <div class="ms-card__title"><?= htmlspecialchars($m['title']) ?></div>
          <div class="ms-card__desc"><?= htmlspecialchars($m['desc']) ?></div>
        </div>
        <div class="ms-card__reward">+Rp<?= number_format($m['reward'],0,',','.') ?></div>
      </div>
      <div class="ms-prog">
        <div class="ms-prog-bar-wrap"><div class="ms-prog-bar" style="width:<?= $pct ?>%"></div></div>
        <div class="ms-prog-meta">
          <span><?= $m['progress'] ?> / <?= $m['target'] ?></span>
          <span><?= $pct ?>%</span>
        </div>
        <?php if ($m['claimed']): ?>
          <button class="ms-btn ms-btn--claimed" disabled><i class="ph-bold ph-check-circle"></i> Selesai</button>
        <?php elseif ($m['done']): ?>
          <button class="ms-btn ms-btn--ready" onclick="claimMission('<?= $m['slug'] ?>', this)"><i class="ph-bold ph-gift"></i> Klaim Reward!</button>
        <?php else: ?>
          <button class="ms-btn ms-btn--locked" disabled><i class="ph-bold ph-lock"></i> Belum Selesai</button>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- WEEKLY -->
  <div class="ms-panel" id="panel-weekly">
    <div class="ms-section-hdr"><i class="ph-fill ph-calendar" style="color:#3b82f6"></i> Misi Mingguan — Reset tiap Senin</div>
    <?php foreach ($weekly as $m): ?>
    <?php
      $pct = $m['target'] > 0 ? min(100, round($m['progress'] / $m['target'] * 100)) : 0;
      $cardClass = $m['claimed'] ? 'ms-card--claimed' : ($m['done'] ? 'ms-card--done' : '');
    ?>
    <div class="ms-card <?= $cardClass ?>" id="mc-<?= htmlspecialchars($m['slug']) ?>">
      <div class="ms-card__head">
        <div class="ms-card__icon"><i class="ph-fill <?= htmlspecialchars($m['icon']) ?>"></i></div>
        <div class="ms-card__info">
          <div class="ms-card__title"><?= htmlspecialchars($m['title']) ?></div>
          <div class="ms-card__desc"><?= htmlspecialchars($m['desc']) ?></div>
        </div>
        <div class="ms-card__reward">+Rp<?= number_format($m['reward'],0,',','.') ?></div>
      </div>
      <div class="ms-prog">
        <div class="ms-prog-bar-wrap"><div class="ms-prog-bar" style="width:<?= $pct ?>%"></div></div>
        <div class="ms-prog-meta">
          <span><?= $m['progress'] ?> / <?= $m['target'] ?></span>
          <span><?= $pct ?>%</span>
        </div>
        <?php if ($m['claimed']): ?>
          <button class="ms-btn ms-btn--claimed" disabled><i class="ph-bold ph-check-circle"></i> Selesai</button>
        <?php elseif ($m['done']): ?>
          <button class="ms-btn ms-btn--ready" onclick="claimMission('<?= $m['slug'] ?>', this)"><i class="ph-bold ph-gift"></i> Klaim Reward!</button>
        <?php else: ?>
          <button class="ms-btn ms-btn--locked" disabled><i class="ph-bold ph-lock"></i> Belum Selesai</button>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- LIFETIME -->
  <div class="ms-panel" id="panel-lifetime">
    <div class="ms-section-hdr"><i class="ph-fill ph-trophy" style="color:#f59e0b"></i> Pencapaian — Klaim sekali selamanya</div>
    <?php foreach ($lifetime as $m): ?>
    <?php
      $pct = $m['target'] > 0 ? min(100, round($m['progress'] / $m['target'] * 100)) : 0;
      $cardClass = $m['claimed'] ? 'ms-card--claimed' : ($m['done'] ? 'ms-card--done' : '');
    ?>
    <div class="ms-card <?= $cardClass ?>" id="mc-<?= htmlspecialchars($m['slug']) ?>">
      <div class="ms-card__head">
        <div class="ms-card__icon"><i class="ph-fill <?= htmlspecialchars($m['icon']) ?>"></i></div>
        <div class="ms-card__info">
          <div class="ms-card__title"><?= htmlspecialchars($m['title']) ?></div>
          <div class="ms-card__desc"><?= htmlspecialchars($m['desc']) ?></div>
        </div>
        <div class="ms-card__reward">+Rp<?= number_format($m['reward'],0,',','.') ?></div>
      </div>
      <div class="ms-prog">
        <div class="ms-prog-bar-wrap"><div class="ms-prog-bar" style="width:<?= $pct ?>%"></div></div>
        <div class="ms-prog-meta">
          <span><?= $m['progress'] ?> / <?= $m['target'] ?></span>
          <span><?= $pct ?>%</span>
        </div>
        <?php if ($m['claimed']): ?>
          <button class="ms-btn ms-btn--claimed" disabled><i class="ph-bold ph-check-circle"></i> Selesai</button>
        <?php elseif ($m['done']): ?>
          <button class="ms-btn ms-btn--ready" onclick="claimMission('<?= $m['slug'] ?>', this)"><i class="ph-bold ph-gift"></i> Klaim Reward!</button>
        <?php else: ?>
          <button class="ms-btn ms-btn--locked" disabled><i class="ph-bold ph-lock"></i> Belum Selesai</button>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

</div>

<script>
const _csrf = '<?= csrf_token() ?>';

function switchTab(cat) {
  document.querySelectorAll('.ms-tab').forEach(t => t.classList.remove('active'));
  document.querySelectorAll('.ms-panel').forEach(p => p.classList.remove('active'));
  document.getElementById('tab-' + cat).classList.add('active');
  document.getElementById('panel-' + cat).classList.add('active');
}

function claimMission(slug, btn) {
  btn.disabled = true;
  btn.innerHTML = '<i class="ph-bold ph-spinner-gap" style="animation:spin 0.8s linear infinite"></i> Mengklaim...';

  const fd = new FormData();
  fd.append('action', 'claim_mission');
  fd.append('slug', slug);
  fd.append('_csrf', _csrf);

  fetch(location.href, { method: 'POST', body: fd })
    .then(r => r.json())
    .then(data => {
      if (data.ok) {
        const card = document.getElementById('mc-' + slug);
        if (card) {
          card.classList.remove('ms-card--done');
          card.classList.add('ms-card--claimed');
        }
        btn.className = 'ms-btn ms-btn--claimed';
        btn.innerHTML = '<i class="ph-bold ph-check-circle"></i> Selesai';
        btn.disabled = true;
        
        // Success SFX if possible
        try {
          const AudioCtx = window.AudioContext || window.webkitAudioContext;
          if (AudioCtx) {
            const actx = new AudioCtx();
            const osc = actx.createOscillator();
            const gain = actx.createGain();
            osc.connect(gain); gain.connect(actx.destination);
            osc.frequency.value = 1046.5; // C6
            gain.gain.setValueAtTime(0, actx.currentTime);
            gain.gain.linearRampToValueAtTime(0.2, actx.currentTime + 0.05);
            gain.gain.exponentialRampToValueAtTime(0.01, actx.currentTime + 0.3);
            osc.start(); osc.stop(actx.currentTime + 0.3);
          }
        } catch(e) {}
        
        if (typeof nToast !== 'undefined') nToast(data.msg, 'success');
      } else {
        btn.disabled = false;
        btn.className = 'ms-btn ms-btn--ready';
        btn.innerHTML = '<i class="ph-bold ph-gift"></i> Klaim Reward!';
        if (typeof nToast !== 'undefined') nToast(data.msg, 'error');
      }
    })
    .catch(() => {
      btn.disabled = false;
      btn.className = 'ms-btn ms-btn--ready';
      btn.innerHTML = '<i class="ph-bold ph-gift"></i> Klaim Reward!';
      if (typeof nToast !== 'undefined') nToast('Koneksi terputus.', 'error');
    });
}
</script>

<?php require dirname(__DIR__) . '/partials/footer.php'; ?>
