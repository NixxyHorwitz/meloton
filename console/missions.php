<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
staff_require('analytics');

$pageTitle  = 'Log Misi User';
$activePage = 'missions';

// ── Filters ───────────────────────────────────────────────────
$filter_cat  = $_GET['cat']    ?? '';   // daily | weekly | lifetime
$filter_slug = $_GET['slug']   ?? '';
$filter_user = trim($_GET['user'] ?? '');
$page        = max(1, (int)($_GET['page'] ?? 1));
$per_page    = 50;
$offset      = ($page - 1) * $per_page;

// ── Build query ───────────────────────────────────────────────
$where  = ['um.claimed_at IS NOT NULL'];
$params = [];

if ($filter_cat) {
    $cat_slugs = match($filter_cat) {
        'daily'    => ['daily_watch_3','daily_watch_5','daily_checkin'],
        'weekly'   => ['weekly_streak_7','weekly_watch_20','weekly_watch_7days'],
        'lifetime' => ['lifetime_first_ref','lifetime_5_refs','lifetime_first_wd','lifetime_100_videos','lifetime_upgrade'],
        default    => []
    };
    if ($cat_slugs) {
        $in = implode(',', array_fill(0, count($cat_slugs), '?'));
        $where[] = "um.mission_slug IN ($in)";
        $params  = array_merge($params, $cat_slugs);
    }
}

if ($filter_slug) {
    $where[]  = 'um.mission_slug = ?';
    $params[] = $filter_slug;
}

if ($filter_user) {
    $where[]  = '(u.username LIKE ? OR u.id = ?)';
    $params[] = '%' . $filter_user . '%';
    $params[] = (int)$filter_user;
}

$where_sql = 'WHERE ' . implode(' AND ', $where);

$total = (int)$pdo->prepare(
    "SELECT COUNT(*) FROM user_missions um
     LEFT JOIN users u ON u.id = um.user_id
     $where_sql"
)->execute($params) ? $pdo->prepare(
    "SELECT COUNT(*) FROM user_missions um
     LEFT JOIN users u ON u.id = um.user_id
     $where_sql"
) : null;

// Proper count
$cnt_stmt = $pdo->prepare(
    "SELECT COUNT(*) FROM user_missions um
     LEFT JOIN users u ON u.id = um.user_id
     $where_sql"
);
$cnt_stmt->execute($params);
$total_records = (int)$cnt_stmt->fetchColumn();
$total_pages   = max(1, (int)ceil($total_records / $per_page));

// Fetch rows
$stmt = $pdo->prepare(
    "SELECT um.*, u.username, u.id as uid
     FROM user_missions um
     LEFT JOIN users u ON u.id = um.user_id
     $where_sql
     ORDER BY um.claimed_at DESC
     LIMIT $per_page OFFSET $offset"
);
$stmt->execute($params);
$rows = $stmt->fetchAll();

// ── Reward map (mirrors user/missions.php) ─────────────────
$REWARD_MAP = [
    'daily_watch_3'       => ['label'=>'Tonton 3 Video',          'cat'=>'Harian',    'reward'=>1000],
    'daily_watch_5'       => ['label'=>'Tonton 5 Video',          'cat'=>'Harian',    'reward'=>2500],

    'daily_checkin'       => ['label'=>'Check-in Harian',         'cat'=>'Harian',    'reward'=>100],
    'weekly_streak_7'     => ['label'=>'Streak 7 Hari',           'cat'=>'Mingguan',  'reward'=>10000],
    'weekly_watch_20'     => ['label'=>'Tonton 20 Video',         'cat'=>'Mingguan',  'reward'=>8000],
    'weekly_watch_7days'  => ['label'=>'Aktif 7 Hari',            'cat'=>'Mingguan',  'reward'=>12000],
    'lifetime_first_ref'  => ['label'=>'1 Referral',              'cat'=>'Pencapaian','reward'=>5000],
    'lifetime_5_refs'     => ['label'=>'5 Referral',              'cat'=>'Pencapaian','reward'=>15000],
    'lifetime_first_wd'   => ['label'=>'Penarikan Pertama',       'cat'=>'Pencapaian','reward'=>3000],
    'lifetime_100_videos' => ['label'=>'Penonton Sejati 100 Vid', 'cat'=>'Pencapaian','reward'=>10000],
    'lifetime_upgrade'    => ['label'=>'Member Premium',          'cat'=>'Pencapaian','reward'=>8000],
];

// ── Summary stats ─────────────────────────────────────────────
$total_reward_given = 0;
foreach ($rows as $r) {
    $total_reward_given += $REWARD_MAP[$r['mission_slug']]['reward'] ?? 0;
}

// All-time totals
try {
    $all_total_claims = (int)$pdo->query("SELECT COUNT(*) FROM user_missions WHERE claimed_at IS NOT NULL")->fetchColumn();
    $slug_top = $pdo->query("SELECT mission_slug, COUNT(*) as cnt FROM user_missions WHERE claimed_at IS NOT NULL GROUP BY mission_slug ORDER BY cnt DESC LIMIT 1")->fetch();
} catch (\Throwable) { $all_total_claims = 0; $slug_top = null; }

// ── Cat badge colors ──────────────────────────────────────────
$cat_badge = [
    'Harian'    => 'b-success',
    'Mingguan'  => 'b-warn',
    'Pencapaian'=> 'b-danger',
];

require __DIR__ . '/partials/header.php';
?>

<div class="page-title-bar">
    <h1>🎯 Log Misi User</h1>
    <p>Pantau misi yang telah diselesaikan dan diklaim oleh pengguna</p>
</div>

<div class="c-content">

  <!-- Stats Row -->
  <div class="row mb-4">
    <div class="col-md-4 mb-3">
      <div class="c-stat" style="display:flex;align-items:center;gap:16px">
        <div class="c-stat__icon" style="background:#1a2a1a;font-size:22px;width:48px;height:48px;border-radius:12px;display:flex;align-items:center;justify-content:center">🎯</div>
        <div>
          <div class="c-stat__val"><?= number_format($all_total_claims) ?></div>
          <div class="c-stat__lbl">Total Klaim Sepanjang Masa</div>
        </div>
      </div>
    </div>
    <div class="col-md-4 mb-3">
      <div class="c-stat" style="display:flex;align-items:center;gap:16px">
        <div class="c-stat__icon" style="background:#1a1a2a;font-size:22px;width:48px;height:48px;border-radius:12px;display:flex;align-items:center;justify-content:center">🔥</div>
        <div>
          <div class="c-stat__val" style="font-size:14px;font-weight:700"><?= htmlspecialchars($REWARD_MAP[$slug_top['mission_slug'] ?? '']['label'] ?? '-') ?></div>
          <div class="c-stat__lbl">Misi Terpopuler</div>
        </div>
      </div>
    </div>
    <div class="col-md-4 mb-3">
      <div class="c-stat" style="display:flex;align-items:center;gap:16px">
        <div class="c-stat__icon" style="background:#1a2a1a;font-size:22px;width:48px;height:48px;border-radius:12px;display:flex;align-items:center;justify-content:center">💸</div>
        <div>
          <div class="c-stat__val">Rp <?= number_format($total_reward_given, 0, ',', '.') ?></div>
          <div class="c-stat__lbl">Total Reward Halaman Ini</div>
        </div>
      </div>
    </div>
  </div>

  <!-- Filter Bar -->
  <div class="c-card mb-4">
    <div class="c-card-header"><div class="c-card-title">Filter</div></div>
    <div class="c-card-body">
      <form method="GET" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
        <div>
          <label class="c-label">Kategori</label>
          <select name="cat" class="c-form-control" style="width:140px">
            <option value="">Semua</option>
            <option value="daily"    <?= $filter_cat==='daily'   ?'selected':''?>>Harian</option>
            <option value="weekly"   <?= $filter_cat==='weekly'  ?'selected':''?>>Mingguan</option>
            <option value="lifetime" <?= $filter_cat==='lifetime'?'selected':''?>>Pencapaian</option>
          </select>
        </div>
        <div>
          <label class="c-label">Misi Spesifik</label>
          <select name="slug" class="c-form-control" style="width:200px">
            <option value="">Semua Misi</option>
            <?php foreach ($REWARD_MAP as $slug => $info): ?>
            <option value="<?= $slug ?>" <?= $filter_slug===$slug?'selected':''?>><?= htmlspecialchars($info['label']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="c-label">User (nama/ID)</label>
          <input type="text" name="user" class="c-form-control" style="width:160px" value="<?= htmlspecialchars($filter_user) ?>" placeholder="Cari username...">
        </div>
        <div style="padding-bottom:0">
          <button type="submit" class="btn btn-sm" style="background:var(--brand);color:#fff;border-radius:8px;font-weight:700;padding:8px 18px">Cari</button>
          <a href="/console/missions.php" class="btn btn-sm btn-outline-secondary" style="border-radius:8px;font-weight:700;padding:8px 14px;margin-left:4px">Reset</a>
        </div>
      </form>
    </div>
  </div>

  <!-- Table -->
  <div class="c-card">
    <div class="c-card-header">
      <div class="c-card-title">Riwayat Klaim Misi (<?= number_format($total_records) ?> data)</div>
    </div>
    <div class="c-card-body p-0">
      <div class="table-responsive">
        <table class="table c-table" style="margin-bottom:0">
          <thead>
            <tr>
              <th>User</th>
              <th>Misi</th>
              <th>Kategori</th>
              <th>Reward</th>
              <th>Progress</th>
              <th>Periode</th>
              <th>Diklaim</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($rows)): ?>
            <tr><td colspan="7" style="text-align:center;padding:30px;color:#555">Belum ada misi yang diklaim.</td></tr>
            <?php else: foreach ($rows as $r):
              $info = $REWARD_MAP[$r['mission_slug']] ?? ['label'=>$r['mission_slug'],'cat'=>'?','reward'=>0];
              $badgeCls = $cat_badge[$info['cat']] ?? 'b-neutral';
            ?>
            <tr>
              <td>
                <a href="/console/user_detail.php?id=<?= $r['uid'] ?>" style="font-weight:700;color:var(--brand);text-decoration:none">
                  <?= htmlspecialchars((string)$r['username']) ?>
                </a>
                <div style="font-size:11px;color:#555">#<?= $r['uid'] ?></div>
              </td>
              <td>
                <div style="font-weight:700;font-size:13px"><?= htmlspecialchars($info['label']) ?></div>
                <div style="font-size:11px;color:#555;font-family:monospace"><?= htmlspecialchars($r['mission_slug']) ?></div>
              </td>
              <td><span class="badge <?= $badgeCls ?>"><?= $info['cat'] ?></span></td>
              <td style="font-weight:700;color:#4CAF82">+Rp <?= number_format($info['reward'],0,',','.') ?></td>
              <td>
                <?php $pct = $r['progress'] > 0 ? 100 : 0; ?>
                <div style="font-size:12px;font-weight:700"><?= $r['progress'] ?></div>
              </td>
              <td style="font-size:12px;color:#666"><?= $r['period_key'] ?? '—' ?></td>
              <td style="font-size:12px;white-space:nowrap">
                <?= $r['claimed_at'] ? date('d/m/Y H:i', strtotime($r['claimed_at'])) : '-' ?>
              </td>
            </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>

      <?php if ($total_pages > 1): ?>
      <div style="display:flex;justify-content:space-between;align-items:center;padding:14px 20px;border-top:1px solid #1f2235">
        <?php $q = http_build_query(array_merge($_GET, ['page' => max(1,$page-1)])); ?>
        <?php $q2= http_build_query(array_merge($_GET, ['page' => min($total_pages,$page+1)])); ?>
        <a href="?<?= $q ?>" class="btn btn-sm btn-outline-secondary" style="border-radius:8px;<?= $page<=1?'opacity:.4;pointer-events:none':'' ?>">← Prev</a>
        <span style="font-size:12px;color:#666">Halaman <?= $page ?> / <?= $total_pages ?></span>
        <a href="?<?= $q2 ?>" class="btn btn-sm btn-outline-secondary" style="border-radius:8px;<?= $page>=$total_pages?'opacity:.4;pointer-events:none':'' ?>">Next →</a>
      </div>
      <?php endif; ?>
    </div>
  </div>

</div>

<?php require __DIR__ . '/partials/footer.php'; ?>
