<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
staff_require('analytics');

$range = (int)($_GET['range'] ?? 7);
$range = in_array($range, [1, 7, 14, 30]) ? $range : 7;

try {
    // Basic Metrics
    $total_users = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
    $today_users = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE DATE(created_at) = CURDATE()")->fetchColumn();
    
    // Average per minute in the last hour
    $last_hour_users = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)")->fetchColumn();
    $avg_per_min_last_hour = $last_hour_users / 60;

    // Spike Detection: Find minutes with > 3 registrations in the last 24 hours
    $spikes = $pdo->query(
        "SELECT DATE_FORMAT(created_at, '%Y-%m-%d %H:%i') as m, COUNT(*) as cnt 
         FROM users 
         WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
         GROUP BY m 
         HAVING cnt >= 3
         ORDER BY m DESC"
    )->fetchAll(PDO::FETCH_ASSOC);

    // Hourly Data (Last 24 hours) for Chart
    $hourly_data = $pdo->query(
        "SELECT DATE_FORMAT(created_at, '%Y-%m-%d %H:00') as h, COUNT(*) as cnt 
         FROM users 
         WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
         GROUP BY h 
         ORDER BY h ASC"
    )->fetchAll(PDO::FETCH_ASSOC);

    // Minutely Data (Last 60 minutes) for Chart
    $minutely_data = $pdo->query(
        "SELECT DATE_FORMAT(created_at, '%H:%i') as m, COUNT(*) as cnt 
         FROM users 
         WHERE created_at >= DATE_SUB(NOW(), INTERVAL 60 MINUTE)
         GROUP BY m 
         ORDER BY m ASC"
    )->fetchAll(PDO::FETCH_ASSOC);

    // Daily Chart Data (Last N days)
    $daily = $pdo->query(
        "SELECT DATE(created_at) as d, COUNT(*) as cnt
         FROM users
         WHERE created_at >= DATE_SUB(NOW(), INTERVAL {$range} DAY)
         GROUP BY d ORDER BY d ASC"
    )->fetchAll(PDO::FETCH_ASSOC);

    // Recent Registrations
    $recent_users = $pdo->query(
        "SELECT id, username, whatsapp, referral_code, referred_by, created_at
         FROM users
         ORDER BY created_at DESC LIMIT 50"
    )->fetchAll(PDO::FETCH_ASSOC);

    $has_data = true;
} catch (\Throwable $e) {
    $has_data = false;
    $total_users = $today_users = $last_hour_users = 0;
    $avg_per_min_last_hour = 0;
    $spikes = $hourly_data = $minutely_data = $daily = $recent_users = [];
}

// Build Chart Data
// 1. Daily Chart
$daily_labels = []; $daily_chart = [];
for ($i = $range - 1; $i >= 0; $i--) {
    $day = date('Y-m-d', strtotime("-{$i} days"));
    $daily_labels[] = date('d/m', strtotime($day));
    $found = array_filter($daily, fn($r) => $r['d'] === $day);
    $daily_chart[] = $found ? (int)array_values($found)[0]['cnt'] : 0;
}

// 2. Hourly Chart (Last 24h)
$hourly_labels = []; $hourly_chart = [];
for ($i = 23; $i >= 0; $i--) {
    $h = date('Y-m-d H:00', strtotime("-{$i} hours"));
    $hourly_labels[] = date('H:00', strtotime($h));
    $found = array_filter($hourly_data, fn($r) => $r['h'] === $h);
    $hourly_chart[] = $found ? (int)array_values($found)[0]['cnt'] : 0;
}

// 3. Minutely Chart (Last 60m)
$min_labels = []; $min_chart = [];
for ($i = 59; $i >= 0; $i--) {
    $m_full = date('Y-m-d H:i', strtotime("-{$i} minutes"));
    $m_lbl = date('H:i', strtotime($m_full));
    $min_labels[] = $m_lbl;
    $found = array_filter($minutely_data, fn($r) => $r['m'] === $m_lbl);
    $min_chart[] = $found ? (int)array_values($found)[0]['cnt'] : 0;
}


$pageTitle  = 'Analisis Pendaftaran';
$activePage = 'registration_analytics';
require __DIR__ . '/partials/header.php';
?>

<div class="mb-4 d-flex align-items-center justify-content-between flex-wrap gap-2">
  <div>
    <h5 class="mb-0 fw-bold">📈 Analisis Pendaftaran User</h5>
    <div style="font-size:12px;color:#666;margin-top:2px">Deteksi anomali, rate pendaftaran, dan grafik real-time.</div>
  </div>
</div>

<!-- SPIKE ALERTS -->
<?php if (!empty($spikes)): ?>
<div class="alert alert-warning d-flex align-items-start gap-2" style="background:rgba(245, 158, 11, 0.15);border:1px solid rgba(245, 158, 11, 0.3);color:#f59e0b;">
  <div style="font-size:20px;line-height:1;">⚠️</div>
  <div>
    <strong>Peringatan Lonjakan Pendaftaran (Spike Detected)</strong><br>
    <div style="font-size:13px;margin-top:4px;">
      Dalam 24 jam terakhir, ditemukan lonjakan pendaftaran (> 3 user/menit) pada:
      <ul style="margin:5px 0 0 15px;padding:0;">
        <?php foreach (array_slice($spikes, 0, 5) as $s): ?>
          <li><strong><?= $s['m'] ?></strong> - <?= $s['cnt'] ?> pendaftar sekaligus.</li>
        <?php endforeach; ?>
        <?php if(count($spikes) > 5): ?>
          <li>...dan <?= count($spikes)-5 ?> lainnya.</li>
        <?php endif; ?>
      </ul>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- Stat cards -->
<div class="row row-cols-2 row-cols-md-4 g-3 mb-4">
  <div class="col">
    <div class="c-stat h-100">
      <div class="d-flex align-items-center justify-content-between mb-2">
        <div class="c-stat__lbl">Total User</div>
        <div class="c-stat__icon" style="background:rgba(59,130,246,.15)">👥</div>
      </div>
      <div class="c-stat__val"><?= number_format($total_users) ?></div>
      <div style="font-size:11px;color:#555;margin-top:3px">Sepanjang waktu</div>
    </div>
  </div>
  <div class="col">
    <div class="c-stat h-100">
      <div class="d-flex align-items-center justify-content-between mb-2">
        <div class="c-stat__lbl">Pendaftar Hari Ini</div>
        <div class="c-stat__icon" style="background:rgba(76,175,130,.15)">📅</div>
      </div>
      <div class="c-stat__val"><?= number_format($today_users) ?></div>
      <div style="font-size:11px;color:#555;margin-top:3px">Sejak 00:00</div>
    </div>
  </div>
  <div class="col">
    <div class="c-stat h-100">
      <div class="d-flex align-items-center justify-content-between mb-2">
        <div class="c-stat__lbl">Pendaftar 1 Jam Terakhir</div>
        <div class="c-stat__icon" style="background:rgba(255,193,7,.15)">⏱️</div>
      </div>
      <div class="c-stat__val"><?= number_format($last_hour_users) ?></div>
      <div style="font-size:11px;color:#555;margin-top:3px">User baru sejam terakhir</div>
    </div>
  </div>
  <div class="col">
    <div class="c-stat h-100" style="border: 1.5px solid var(--brand, #ff5e00);">
      <div class="d-flex align-items-center justify-content-between mb-2">
        <div class="c-stat__lbl">Current Rate (Avg)</div>
        <div class="c-stat__icon" style="background:rgba(255,107,53,.2)">🚀</div>
      </div>
      <div class="c-stat__val" style="color:var(--brand, #ff5e00)"><?= number_format($avg_per_min_last_hour, 2) ?></div>
      <div style="font-size:11px;color:#fff;margin-top:3px">pendaftar per menit (saat ini)</div>
    </div>
  </div>
</div>

<div class="row g-3 mb-4">
  <div class="col-md-6">
    <!-- Chart: Minutely -->
    <div class="c-card h-100">
      <div class="c-card-header d-flex justify-content-between align-items-center">
        <span class="c-card-title">🔴 Live: 60 Menit Terakhir</span>
        <span class="badge bg-danger" style="font-size:10px">Per Menit</span>
      </div>
      <div class="c-card-body">
        <canvas id="minutely-chart" style="max-height:200px"></canvas>
      </div>
    </div>
  </div>
  <div class="col-md-6">
    <!-- Chart: Hourly -->
    <div class="c-card h-100">
      <div class="c-card-header d-flex justify-content-between align-items-center">
        <span class="c-card-title">🕒 24 Jam Terakhir</span>
        <span class="badge bg-primary" style="font-size:10px">Per Jam</span>
      </div>
      <div class="c-card-body">
        <canvas id="hourly-chart" style="max-height:200px"></canvas>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
// Minutely Chart (Line)
new Chart(document.getElementById('minutely-chart'), {
  type: 'line',
  data: {
    labels: <?= json_encode($min_labels) ?>,
    datasets: [{
      label: 'Registrations',
      data: <?= json_encode($min_chart) ?>,
      backgroundColor: 'rgba(239, 68, 68, 0.2)',
      borderColor: '#ef4444',
      borderWidth: 2,
      tension: 0.3,
      fill: true,
      pointRadius: 1,
      pointHoverRadius: 4
    }]
  },
  options: {
    responsive: true,
    plugins: {
      legend: { display: false },
      tooltip: { callbacks: { label: ctx => ctx.parsed.y + ' user mendaftar' } }
    },
    scales: {
      y: { beginAtZero:true, ticks:{color:'#666',stepSize:1}, grid:{color:'#1f2235'} },
      x: { ticks:{color:'#666', maxTicksLimit: 12}, grid:{display:false} }
    }
  }
});

// Hourly Chart (Bar)
new Chart(document.getElementById('hourly-chart'), {
  type: 'bar',
  data: {
    labels: <?= json_encode($hourly_labels) ?>,
    datasets: [{
      label: 'Registrations',
      data: <?= json_encode($hourly_chart) ?>,
      backgroundColor: 'rgba(59, 130, 246, 0.7)',
      borderColor: '#3b82f6',
      borderWidth: 1,
      borderRadius: 4
    }]
  },
  options: {
    responsive: true,
    plugins: {
      legend: { display: false },
      tooltip: { callbacks: { label: ctx => ctx.parsed.y + ' user mendaftar' } }
    },
    scales: {
      y: { beginAtZero:true, ticks:{color:'#666',stepSize:1}, grid:{color:'#1f2235'} },
      x: { ticks:{color:'#666', maxTicksLimit: 12}, grid:{display:false} }
    }
  }
});
</script>

<!-- Recent Registrations Table -->
<div class="c-card mt-4">
  <div class="c-card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <span class="c-card-title">🆕 50 Pendaftar Terbaru</span>
    <div style="font-size:11px;color:#aaa;">Cek kemiripan username/IP untuk mendeteksi bot.</div>
  </div>
  <div class="c-card-body p-3">
    <div class="table-responsive">
      <table class="c-table table table-dark table-striped table-hover mb-0" style="font-size: 13px; background: #131520; border: none; width: 100%;">
        <thead>
          <tr style="border-bottom: 2px solid #1f2235; color: #aaa;">
            <th class="px-3 py-3" style="font-weight: 700;">Waktu Daftar</th>
            <th class="px-3 py-3" style="font-weight: 700;">Username</th>
            <th class="px-3 py-3" style="font-weight: 700;">WhatsApp</th>
            <th class="px-3 py-3" style="font-weight: 700;">Kode Ref (Sbg Upline)</th>
            <th class="px-3 py-3" style="font-weight: 700;">Daftar Lewat (Downline dr)</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($recent_users)): ?>
            <tr>
              <td colspan="6" class="text-center py-4 text-muted">Belum ada user terdaftar.</td>
            </tr>
          <?php else: ?>
            <?php foreach ($recent_users as $u): ?>
              <tr style="border-bottom: 1px solid #1f2235; vertical-align: middle;">
                <td class="px-3 py-3 fw-bold text-warning">
                  <?= date('d/m/Y H:i:s', strtotime($u['created_at'])) ?>
                </td>
                <td class="px-3 py-3" style="color:#fff; font-weight:600;">
                  <a href="/console/users.php?search=<?= urlencode($u['username']) ?>" style="color:#fff;text-decoration:none;">
                    <?= htmlspecialchars($u['username']) ?>
                  </a>
                </td>
                <td class="px-3 py-3 text-muted">
                  <?= htmlspecialchars($u['whatsapp'] ?: '-') ?>
                </td>
                <td class="px-3 py-3" style="color:var(--brand)">
                  <?= htmlspecialchars($u['referral_code'] ?: '-') ?>
                </td>
                <td class="px-3 py-3" style="color:#4CAF82">
                  <?= htmlspecialchars($u['referred_by'] ?: '-') ?>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>
