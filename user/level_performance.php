<?php
require_once dirname(__DIR__) . '/bootstrap.php';
$user = require_auth($pdo);

$pageTitle  = 'Kinerja WD Per Level';
$activePage = 'withdraw';
require dirname(__DIR__) . '/partials/header.php';

$levels = $pdo->query("SELECT * FROM memberships ORDER BY sort_order ASC, price ASC")->fetchAll();
$current_level_id = (int)$user['membership_id'];

// Get all level IDs the user has ever purchased
$purchased_level_ids = [];
$stmt_purchases = $pdo->prepare("SELECT DISTINCT membership_id FROM upgrade_orders WHERE user_id = ? AND status = 'confirmed'");
$stmt_purchases->execute([$user['id']]);
$purchased_level_ids = $stmt_purchases->fetchAll(PDO::FETCH_COLUMN);
?>
<style>
.perf-card { background: #fff; border: 2.5px solid var(--ink); border-radius: 12px; box-shadow: 3px 3px 0 var(--ink); padding: 14px; margin-bottom: 14px; position: relative; overflow: hidden; }
.perf-card__title { font-size: 15px; font-weight: 900; color: var(--ink); display: flex; align-items: flex-start; justify-content: space-between; gap: 10px; margin-bottom: 12px; }
.perf-card__icon { width: 32px; height: 32px; background: #fef08a; border: 2px solid var(--ink); border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 16px; }

.perf-bar { height: 14px; background: #e2e8f0; border: 2px solid var(--ink); border-radius: 10px; margin-bottom: 6px; overflow: hidden; }
.perf-bar__fill { height: 100%; border-right: 2px solid var(--ink); transition: width 1s ease-in-out; }
.perf-stats { display: flex; justify-content: space-between; font-size: 12px; font-weight: 800; }
.perf-status { padding: 4px 8px; border-radius: 6px; border: 2px solid var(--ink); font-size: 10px; font-weight: 800; text-transform: uppercase; }

.status-good { background: #4CAF82; color: #fff; }
.status-bad { background: #F44E3B; color: #fff; }
</style>

<div class="mb-4">
  <div style="font-size:24px; font-weight:900; color:var(--ink); line-height:1.2; margin-bottom:4px">📊 Kinerja Server & WD</div>
  <div style="font-size:12px; color:#64748b; font-weight:700">Pantau *uptime* dan kecepatan verifikasi penarikan (WD) secara *real-time* untuk setiap level.</div>
</div>

<div class="alert" style="background:#fef08a; border:2.5px solid var(--ink); border-radius:10px; box-shadow:2.5px 2.5px 0 var(--ink); font-size:12px; font-weight:700; color:#d97706; margin-bottom:20px; display:flex; gap:10px; align-items:flex-start">
  <i class="ph-fill ph-warning-circle" style="font-size:20px"></i>
  <div><strong>Catatan:</strong> Jika kinerja level Anda sedang merah/rendah, penarikan Anda mungkin akan memakan waktu lebih lama atau tertunda. Disarankan *upgrade* ke level dengan kinerja stabil.</div>
</div>

<div class="d-flex flex-column gap-2">
  <?php foreach ($levels as $lvl): 
    $is_own = ($current_level_id === (int)$lvl['id']);
    $has_owned = ($is_own || in_array($lvl['id'], $purchased_level_ids));
    $is_down = (!empty($lvl['perf_down_if_own']) && $has_owned);
    $is_wd_disabled = !empty($lvl['is_wd_disabled']);
    
    if ($is_wd_disabled) {
        $perf_val = 0;
        $status_text = "🔴 Penarikan Ditutup";
        $color = "#dc2626";
        $status_class = "status-bad";
    } elseif ($is_down) {
        // Random bad performance for "Down If Own"
        // Seed based on user id and level id so it doesn't jump wildly on every refresh during the same hour
        srand((int)($user['id'] . $lvl['id'] . date('H')));
        $perf_val = mt_rand(150, 350) / 10; // 15.0 to 35.0
        srand(); // reset seed
        $status_text = "Sistem Sibuk / Kinerja Rendah";
        $color = "#F44E3B";
        $status_class = "status-bad";
    } else {
        $perf_val = (float)($lvl['perf_avg'] ?? 99.8);
        $status_text = "Normal / Stabil";
        $color = "#4CAF82";
        $status_class = "status-good";
    }
  ?>
  <div class="perf-card">
    <div class="perf-card__title">
      <div class="d-flex align-items-center gap-2">
        <div class="perf-card__icon"><?= htmlspecialchars($lvl['icon'] ?: '⭐') ?></div>
        <div style="line-height: 1.2;">
          <?= htmlspecialchars($lvl['name']) ?>
          <?php if ($is_own): ?>
            <div style="margin-top:2px"><span class="badge" style="background:var(--ink); color:#fff; font-size:9px; padding:2px 6px; border-radius:4px;">Level Anda</span></div>
          <?php endif; ?>
        </div>
      </div>
      <div class="perf-status <?= $status_class ?>" style="text-align:right; flex-shrink:0; max-width: 50%;"><?= $status_text ?></div>
    </div>
    
    <div class="perf-bar">
      <div class="perf-bar__fill" style="width: <?= min(100, $perf_val) ?>%; background: <?= $color ?>"></div>
    </div>
    <div class="perf-stats">
      <div style="color:#64748b">Uptime Withdraw</div>
      <div style="color:<?= $color ?>"><?= number_format($perf_val, 1) ?>%</div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<a href="/user/withdraw.php" class="btn btn-sm w-100 mt-4 mb-5" style="background:#fff; border:2.5px solid var(--ink); border-radius:10px; box-shadow:3px 3px 0 var(--ink); font-weight:800; color:var(--ink); padding:10px; text-align:center; display:block">
  ⬅️ Kembali ke Halaman Withdraw
</a>

<?php require dirname(__DIR__) . '/partials/footer.php'; ?>
