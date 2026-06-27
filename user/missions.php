<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/auth/guard.php';

// Dynamic level name for Daily Spin
$level2_name = $pdo->query("SELECT name FROM memberships WHERE id = 2")->fetchColumn() ?: 'Juragan Silver';


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
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'spin_wheel') {
    header('Content-Type: application/json');
    if (!csrf_verify()) { echo json_encode(['ok'=>false,'msg'=>'CSRF tidak valid.']); exit; }

    try {
        $pdo->beginTransaction();
        
        $stmt = $pdo->prepare("SELECT spin_tickets FROM users WHERE id=? FOR UPDATE");
        $stmt->execute([$user['id']]);
        $tickets = (int)$stmt->fetchColumn();

        if ($tickets <= 0) {
            $pdo->rollBack();
            echo json_encode(['ok'=>false,'msg'=>'Tiket Spin habis! Kerjakan misi harian untuk dapat tiket.']);
            exit;
        }

        $pdo->prepare("UPDATE users SET spin_tickets = spin_tickets - 1 WHERE id=?")->execute([$user['id']]);

        // SET TINGKAT KESULITAN (WEIGHT / PELUANG) DI SINI
        // Semakin besar angka, semakin mudah/sering muncul
        $rate_silver = 1;   // Sangat Sulit
        $rate_100k   = 5;   // Sangat Sulit
        $rate_50k    = 10;  // Sulit
        $rate_diskon = 50;  // Sedang
        $rate_beli   = 10;  // Dipersulit (Sebelumnya Mudah)
        $rate_10k    = 200; // Sangat Mudah

        $prizes = [
            ['id'=>0, 'name'=>$level2_name, 'weight'=>$rate_silver],
            ['id'=>1, 'name'=>'Tarik Rp 100k', 'weight'=>$rate_100k],
            ['id'=>2, 'name'=>'Tarik Rp 50k', 'weight'=>$rate_50k],
            ['id'=>3, 'name'=>'Diskon Rp 10k', 'weight'=>$rate_diskon],
            ['id'=>4, 'name'=>'Beli Rp 10k', 'weight'=>$rate_beli],
            ['id'=>5, 'name'=>'Tarik Rp 10k', 'weight'=>$rate_10k],
        ];

        $totalWeight = array_sum(array_column($prizes, 'weight'));
        $rand = random_int(1, $totalWeight);
        $winner = $prizes[count($prizes)-1];
        $current = 0;
        foreach ($prizes as $p) {
            $current += $p['weight'];
            if ($rand <= $current) {
                $winner = $p;
                break;
            }
        }

        $msg = "Selamat! Kamu memenangkan: " . $winner['name'];
        if ($winner['id'] === 0) {
            $pdo->prepare("UPDATE users SET membership_id=2 WHERE id=?")->execute([$user['id']]);
        } elseif ($winner['id'] === 1) {
            $pdo->prepare("UPDATE users SET balance_wd = balance_wd + 100000 WHERE id=?")->execute([$user['id']]);
        } elseif ($winner['id'] === 2) {
            $pdo->prepare("UPDATE users SET balance_wd = balance_wd + 50000 WHERE id=?")->execute([$user['id']]);
        } elseif ($winner['id'] === 3) {
            $code = "SPIN" . strtoupper(substr(md5(uniqid()), 0, 6));
            $discountsJson = json_encode(['*'=>'10000rp']);
            $pdo->prepare("INSERT INTO discount_vouchers (code, discounts, max_claims, claims_count) VALUES (?, ?, 1, 0)")->execute([$code, $discountsJson]);
            $msg .= ". Kode Vouchermu: " . $code;
        } elseif ($winner['id'] === 4) {
            $pdo->prepare("UPDATE users SET balance_dep = balance_dep + 10000 WHERE id=?")->execute([$user['id']]);
        } elseif ($winner['id'] === 5) {
            $pdo->prepare("UPDATE users SET balance_wd = balance_wd + 10000 WHERE id=?")->execute([$user['id']]);
        }

        $tgMsg = "🎁 <b>MEMBER MENANG SPIN!</b>\n";
        $tgMsg .= "Username: <code>" . htmlspecialchars($user['username']) . "</code>\n";
        $tgMsg .= "Hadiah: <b>" . htmlspecialchars($winner['name']) . "</b>\n";
        if ($winner['id'] === 3) {
            $tgMsg .= "Kode Voucher: <code>" . $code . "</code>\n";
        }
        send_telegram_notif($pdo, $tgMsg, [], 'log');

        $pdo->commit();
        echo json_encode(['ok'=>true, 'prize_index'=>$winner['id'], 'msg'=>$msg, 'prize_name'=>$winner['name']]);
    } catch (\Throwable $e) {
        $pdo->rollBack();
        echo json_encode(['ok'=>false,'msg'=>'Terjadi kesalahan: '.$e->getMessage()]);
    }
    exit;
}

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
        $is_daily = ($mission['category'] === 'daily');
        $ticketSql = $is_daily ? ", spin_tickets = spin_tickets + 1" : "";
        $pdo->prepare("UPDATE users SET balance_wd = balance_wd + ? {$ticketSql} WHERE id=?")
            ->execute([$mission['reward'], $user['id']]);

        $tgMsg = "🎯 <b>MEMBER KLAIM MISI!</b>\n";
        $tgMsg .= "Username: <code>" . htmlspecialchars($user['username']) . "</code>\n";
        $tgMsg .= "Misi: <b>" . htmlspecialchars($mission['title']) . "</b>\n";
        $tgMsg .= "Reward: Rp " . number_format($mission['reward'], 0, ',', '.') . "\n";
        if ($is_daily) {
            $tgMsg .= "Tambahan: +1 Tiket Spin\n";
        }
        send_telegram_notif($pdo, $tgMsg, [], 'misi');

        $pdo->commit();
        
        $msg = '🎉 Reward diklaim! +'.number_format($mission['reward'],0,',','.').' ke Saldo Tarik.';
        if ($is_daily) $msg .= ' (+1 Tiket Spin)';
        echo json_encode(['ok'=>true,'msg'=>$msg,'reward'=>$mission['reward'],'tickets_added'=>$is_daily ? 1 : 0]);
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

$stmt = $pdo->prepare("SELECT spin_tickets FROM users WHERE id=?");
$stmt->execute([$user['id']]);
$spin_tickets = (int)$stmt->fetchColumn();

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
@keyframes popIn { 0% { transform: scale(0); opacity: 0; } 100% { transform: scale(1); opacity: 1; } }
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

  <div class="ms-method" id="card-games" style="background: #fff; border: 2.5px solid #7dd3e8; border-radius: 16px; box-shadow: 0 5px 0 #7dd3e8; overflow: hidden; margin-bottom: 20px; transition: transform 0.1s;">
    <div class="ms-method__hd" onclick="toggleGames()" style="display: flex; align-items: center; gap: 10px; padding: 12px 14px; cursor: pointer; user-select: none;">
      <div style="width: 40px; height: 40px; flex-shrink: 0; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 20px; background: linear-gradient(135deg, #fde68a, #f59e0b); color: #fff; box-shadow: 0 3px 0 #d97706;">
        <i class="ph-fill ph-game-controller"></i>
      </div>
      <div style="flex: 1; min-width: 0;">
        <div style="font-weight: 900; font-size: 14px; color: #1e3a8a;">Minigames</div>
        <div style="font-size: 10px; color: #64748b; font-weight: 700; margin-top:2px;">Tap Tap Frenzy & Daily Spin</div>
      </div>
      <div id="chev-games" style="font-size: 14px; color: #94a3b8; transition: transform 0.2s; flex-shrink: 0;"><i class="ph-bold ph-caret-down"></i></div>
    </div>
    <div id="body-games" style="padding: 14px; border-top: 2px dashed #bfdbfe; display: none; background: #f8fafc;">
      <a href="/minigame" style="display:block; background:linear-gradient(135deg, #fef08a, #f59e0b); border:3px solid #fff; border-radius:20px; box-shadow:0 8px 0 #d97706; padding:16px; text-decoration:none; margin-bottom:12px; position:relative; overflow:hidden; transition:transform 0.2s;" onclick="this.style.transform='translateY(4px)'; this.style.boxShadow='0 4px 0 #d97706'; setTimeout(()=>this.style.transform='none',200);">
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
      
      <a href="/luckycard" style="display:block; background:linear-gradient(135deg, #e9d5ff, #a855f7); border:3px solid #fff; border-radius:20px; box-shadow:0 8px 0 #9333ea; padding:16px; text-decoration:none; margin-bottom:20px; position:relative; overflow:hidden; transition:transform 0.2s;" onclick="this.style.transform='translateY(4px)'; this.style.boxShadow='0 4px 0 #9333ea'; setTimeout(()=>this.style.transform='none',200);">
        <div style="position:relative; z-index:2; display:flex; align-items:center; justify-content:space-between;">
            <div>
                <h3 style="margin:0 0 4px; font-size:18px; font-weight:900; color:#4c1d95; text-shadow:1px 1px 0 #d8b4fe;">LUCKY CARD 🃏</h3>
                <p style="margin:0; font-size:12px; font-weight:800; color:#581c87;">Pilih kartu keberuntunganmu dan menangkan kejutan!</p>
            </div>
            <div style="background:#fff; width:44px; height:44px; border-radius:50%; display:flex; align-items:center; justify-content:center; color:#9333ea; font-size:24px; box-shadow:0 4px 6px rgba(0,0,0,0.1);">
                <i class="ph-fill ph-cards"></i>
            </div>
        </div>
        <i class="ph-fill ph-sparkle" style="position:absolute; right:-10px; bottom:-20px; font-size:80px; color:rgba(255,255,255,0.3); z-index:1;"></i>
      </a>

      <div class="spin-container" style="background: linear-gradient(135deg, #a78bfa, #818cf8); border: 4px solid #c4b5fd; border-radius: 24px; padding: 20px; margin-bottom: 0; box-shadow: 0 8px 0 #6366f1; text-align: center; position: relative; overflow: hidden;">
        <div style="position: absolute; top: -50px; right: -50px; font-size: 150px; color: rgba(255,255,255,0.1); transform: rotate(15deg); z-index: 1;"><i class="ph-fill ph-spinner-gap"></i></div>
        <div style="position: relative; z-index: 2;">
          <h3 style="margin: 0 0 8px; color: #fff; font-weight: 900; font-size: 20px; text-shadow: 1px 1px 0 #4f46e5;"><i class="ph-fill ph-gift"></i> DAILY SPIN</h3>
          <p style="margin: 0 0 16px; font-size: 13px; font-weight: 700; color: #e0e7ff;">Gunakan tiket spin untuk memutar roda hadiah!</p>
          
          <div style="font-size: 16px; font-weight: 900; background: #fff; color: #4f46e5; padding: 10px 20px; border-radius: 100px; display: inline-flex; align-items: center; gap: 8px; margin-bottom: 24px; box-shadow: 0 4px 6px rgba(0,0,0,0.1);">
            <i class="ph-fill ph-ticket"></i> Tiket Anda: <span id="spin-tickets-count"><?= $spin_tickets ?></span>
          </div>

          <div style="position: relative; width: 260px; height: 260px; margin: 0 auto;">
              <div id="wheel" data-rotation="0" style="width: 100%; height: 100%; border-radius: 50%; border: 6px solid #fff; box-shadow: 0 0 0 6px #6366f1, 0 10px 20px rgba(0,0,0,0.3); background: conic-gradient(from -30deg, #fde047 0% 16.6%, #fbbf24 16.6% 33.3%, #34d399 33.3% 50%, #2dd4bf 50% 66.6%, #60a5fa 66.6% 83.3%, #c084fc 83.3% 100%); transition: transform 8s cubic-bezier(0.1, 0.9, 0.1, 1); position: relative; overflow: hidden;">
                  <div style="position:absolute; top: 0; left: 50%; width: 80px; height: 50%; margin-left: -40px; transform-origin: bottom center; transform: rotate(0deg); padding-top: 15px; text-align: center; font-weight: 900; font-size: 11px; color: #fff; text-shadow: 0 2px 4px rgba(0,0,0,0.8), 0 0 2px #000; line-height: 1.1; word-break: break-word;"><i class="ph-fill ph-crown" style="font-size:24px; display:block; margin:0 auto 2px;"></i><?= htmlspecialchars($level2_name) ?></div>
                  <div style="position:absolute; top: 0; left: 50%; width: 80px; height: 50%; margin-left: -40px; transform-origin: bottom center; transform: rotate(60deg); padding-top: 15px; text-align: center; font-weight: 900; font-size: 11px; color: #fff; text-shadow: 0 2px 4px rgba(0,0,0,0.8), 0 0 2px #000; line-height: 1.1;"><img src="/assets/moneybag_v2.png" style="width:24px; height:24px; display:block; margin:0 auto 2px; object-fit:contain;">Tarik<br>100k</div>
                  <div style="position:absolute; top: 0; left: 50%; width: 80px; height: 50%; margin-left: -40px; transform-origin: bottom center; transform: rotate(120deg); padding-top: 15px; text-align: center; font-weight: 900; font-size: 11px; color: #fff; text-shadow: 0 2px 4px rgba(0,0,0,0.8), 0 0 2px #000; line-height: 1.1;"><img src="/assets/moneybag_v2.png" style="width:24px; height:24px; display:block; margin:0 auto 2px; object-fit:contain;">Tarik<br>50k</div>
                  <div style="position:absolute; top: 0; left: 50%; width: 80px; height: 50%; margin-left: -40px; transform-origin: bottom center; transform: rotate(180deg); padding-top: 15px; text-align: center; font-weight: 900; font-size: 11px; color: #fff; text-shadow: 0 2px 4px rgba(0,0,0,0.8), 0 0 2px #000; line-height: 1.1;"><i class="ph-fill ph-ticket" style="font-size:24px; display:block; margin:0 auto 2px;"></i>Diskon<br>10k</div>
                  <div style="position:absolute; top: 0; left: 50%; width: 80px; height: 50%; margin-left: -40px; transform-origin: bottom center; transform: rotate(240deg); padding-top: 15px; text-align: center; font-weight: 900; font-size: 11px; color: #fff; text-shadow: 0 2px 4px rgba(0,0,0,0.8), 0 0 2px #000; line-height: 1.1;"><img src="/assets/dollar.png" style="width:24px; height:24px; display:block; margin:0 auto 2px; object-fit:contain;">Beli<br>10k</div>
                  <div style="position:absolute; top: 0; left: 50%; width: 80px; height: 50%; margin-left: -40px; transform-origin: bottom center; transform: rotate(300deg); padding-top: 15px; text-align: center; font-weight: 900; font-size: 11px; color: #fff; text-shadow: 0 2px 4px rgba(0,0,0,0.8), 0 0 2px #000; line-height: 1.1;"><img src="/assets/dollar.png" style="width:24px; height:24px; display:block; margin:0 auto 2px; object-fit:contain;">Tarik<br>10k</div>
              </div>
              <div style="position: absolute; top: -20px; left: 50%; transform: translateX(-50%); width: 0; height: 0; border-left: 20px solid transparent; border-right: 20px solid transparent; border-top: 35px solid #ef4444; z-index: 10; filter: drop-shadow(0 4px 4px rgba(0,0,0,0.3));"></div>
              <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); width: 60px; height: 60px; border-radius: 50%; background: #ef4444; color: #fff; border: 4px solid #fff; box-shadow: 0 4px 0 #b91c1c; z-index: 11; display:flex; align-items:center; justify-content:center;">
                  <button id="btn-spin" onclick="spinWheel()" <?= $spin_tickets <= 0 ? 'disabled' : '' ?> style="background:transparent; border:none; color:#fff; font-weight:900; font-size:16px; cursor:pointer; width:100%; height:100%; outline:none; transition:opacity 0.2s; <?= $spin_tickets <= 0 ? 'opacity:0.5;' : '' ?>">SPIN</button>
              </div>
          </div>
        </div>

        <!-- Custom Modal -->
        <div id="spin-result-modal" style="display:none; position:fixed; inset:0; background:rgba(15,23,42,0.8); backdrop-filter:blur(8px); z-index:9999; align-items:center; justify-content:center; padding:20px;">
            <div style="background:#fff; width:100%; max-width:320px; border-radius:24px; padding:30px 20px; text-align:center; border:4px solid #fde047; box-shadow:0 8px 0 #f59e0b; animation:popIn 0.5s cubic-bezier(0.34, 1.56, 0.64, 1);">
                <div id="spin-modal-icon" style="font-size:60px; line-height:1; margin-bottom:16px;">🎁</div>
                <h2 style="color:#d97706; font-size:24px; font-weight:900; margin:0 0 8px;">SELAMAT!</h2>
                <p id="spin-modal-text" style="font-size:14px; color:#78350f; font-weight:700; margin:0 0 24px; line-height:1.4;">Kamu memenangkan hadiah!</p>
                <button onclick="document.getElementById('spin-result-modal').style.display='none'" style="display:inline-block; width:100%; background:#0ea5e9; color:#fff; font-weight:800; font-size:16px; padding:14px 24px; border-radius:100px; border:none; box-shadow:0 4px 0 #0284c7; cursor:pointer; font-family:inherit; transition:transform 0.1s;">Mantap!</button>
            </div>
        </div>
      </div>
    </div>
  </div>

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

function toggleGames() {
  const body = document.getElementById('body-games');
  const chev = document.getElementById('chev-games');
  if (body.style.display === 'none') {
    body.style.display = 'block';
    chev.style.transform = 'rotate(180deg)';
    chev.style.color = '#1e3a8a';
  } else {
    body.style.display = 'none';
    chev.style.transform = 'rotate(0deg)';
    chev.style.color = '#94a3b8';
  }
}

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
        if (data.tickets_added && data.tickets_added > 0) {
            const tc = document.getElementById('spin-tickets-count');
            if (tc) {
                tc.innerText = parseInt(tc.innerText) + data.tickets_added;
                const btnSpin = document.getElementById('btn-spin');
                if (btnSpin) {
                    btnSpin.disabled = false;
                    btnSpin.style.opacity = '1';
                }
            }
        }
        
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
function spinWheel() {
    if (typeof window.isSpinning !== 'undefined' && window.isSpinning) return;
    const btn = document.getElementById('btn-spin');
    if (btn.disabled) return;
    
    window.isSpinning = true;
    btn.innerHTML = '<i class="ph-bold ph-spinner-gap ph-spin"></i>';
    
    const fd = new FormData();
    fd.append('action', 'spin_wheel');
    fd.append('_csrf', _csrf);
    
    fetch(location.href, { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (!data.ok) {
                window.isSpinning = false;
                btn.innerHTML = 'SPIN';
                if (typeof nToast !== 'undefined') nToast(data.msg, 'error');
                return;
            }
            
            const tc = document.getElementById('spin-tickets-count');
            let t = parseInt(tc.innerText) - 1;
            tc.innerText = t >= 0 ? t : 0;
            
            const sliceDeg = 60;
            // Add a slight random offset so it doesn't always land exactly in the middle of the slice
            const offset = Math.floor(Math.random() * 40) - 20; 
            const targetRotation = (360 - (data.prize_index * sliceDeg)) + offset; 
            
            const fullSpins = 360 * 6;
            const wheel = document.getElementById('wheel');
            let currentRot = parseFloat(wheel.dataset.rotation || '0');
            // Normalize current rotation so we don't just spin backwards if logic misaligns
            const normalizedCurrent = currentRot % 360;
            const diff = targetRotation - normalizedCurrent;
            const finalRotation = currentRot + fullSpins + diff;
            
            const totalDuration = 8000;
            const startTime = Date.now();
            let isTickingActive = true;
            
            let actx = null;
            try {
                const AudioCtx = window.AudioContext || window.webkitAudioContext;
                if (AudioCtx) actx = new AudioCtx();
            } catch(e) {}
            
            function playTick() {
                if (!window.isSpinning || !isTickingActive) return;
                const elapsed = Date.now() - startTime;
                if (elapsed >= totalDuration) return;
                
                if (actx && actx.state !== 'closed') {
                    try {
                        const osc = actx.createOscillator();
                        const gain = actx.createGain();
                        osc.connect(gain); gain.connect(actx.destination);
                        osc.frequency.value = 800;
                        gain.gain.setValueAtTime(0, actx.currentTime);
                        gain.gain.linearRampToValueAtTime(0.1, actx.currentTime + 0.01);
                        gain.gain.exponentialRampToValueAtTime(0.01, actx.currentTime + 0.05);
                        osc.start(); osc.stop(actx.currentTime + 0.05);
                    } catch(e) {}
                }
                
                const progress = elapsed / totalDuration;
                const currentDelay = 50 + (Math.pow(progress, 3) * 450); 
                setTimeout(playTick, currentDelay);
            }
            playTick();
            
            wheel.style.transform = `rotate(${finalRotation}deg)`;
            wheel.dataset.rotation = finalRotation;
            
            setTimeout(() => {
                isTickingActive = false;
                window.isSpinning = false;
                btn.innerHTML = 'SPIN';
                if (t <= 0) {
                    btn.disabled = true;
                    btn.style.opacity = '0.5';
                }
                
                if (actx && actx.state !== 'closed') {
                    try {
                        const osc = actx.createOscillator();
                        const gain = actx.createGain();
                        osc.connect(gain); gain.connect(actx.destination);
                        osc.frequency.value = 600;
                        gain.gain.setValueAtTime(0, actx.currentTime);
                        gain.gain.linearRampToValueAtTime(0.3, actx.currentTime + 0.1);
                        gain.gain.exponentialRampToValueAtTime(0.01, actx.currentTime + 1.5);
                        osc.start(); osc.stop(actx.currentTime + 1.5);
                        
                        [800, 1000, 1200].forEach((freq, i) => {
                            setTimeout(() => {
                                try {
                                    const o = actx.createOscillator();
                                    const g = actx.createGain();
                                    o.connect(g); g.connect(actx.destination);
                                    o.frequency.value = freq;
                                    g.gain.setValueAtTime(0, actx.currentTime);
                                    g.gain.linearRampToValueAtTime(0.2, actx.currentTime + 0.05);
                                    g.gain.exponentialRampToValueAtTime(0.01, actx.currentTime + 0.8);
                                    o.start(); o.stop(actx.currentTime + 0.8);
                                } catch(e) {}
                            }, i * 150);
                        });
                    } catch(e) {}
                }
                
                const icons = {
                    0: '👑',
                    1: '💰',
                    2: '💰',
                    3: '🎫',
                    4: '🪙',
                    5: '🪙',
                };
                document.getElementById('spin-modal-icon').innerText = icons[data.prize_index] || '🎁';
                document.getElementById('spin-modal-text').innerText = data.msg;
                document.getElementById('spin-result-modal').style.display = 'flex';
            }, totalDuration);
        })
        .catch(e => {
            window.isSpinning = false;
            btn.innerHTML = 'SPIN';
            if (typeof nToast !== 'undefined') nToast('Error: ' + e, 'error');
        });
}
</script>

<?php require dirname(__DIR__) . '/partials/footer.php'; ?>
