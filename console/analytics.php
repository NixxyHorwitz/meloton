<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
staff_require('analytics');

// Helper: Parse User Agent into readable text
function parse_ua_short(?string $ua): string {
    if (empty($ua)) return 'Unknown Device';
    
    // Detect OS
    $os = 'Unknown OS';
    if (stripos($ua, 'windows nt 10.0') !== false) $os = 'Windows 10/11';
    elseif (stripos($ua, 'windows nt 6.1') !== false) $os = 'Windows 7';
    elseif (stripos($ua, 'iphone') !== false) $os = 'iPhone';
    elseif (stripos($ua, 'ipad') !== false) $os = 'iPad';
    elseif (stripos($ua, 'macintosh') !== false || stripos($ua, 'mac os x') !== false) $os = 'macOS';
    elseif (stripos($ua, 'android') !== false) $os = 'Android';
    elseif (stripos($ua, 'linux') !== false) $os = 'Linux';
    
    // Detect Browser
    $browser = 'Unknown Browser';
    if (stripos($ua, 'edg/') !== false) $browser = 'Edge';
    elseif (stripos($ua, 'chrome') !== false) $browser = 'Chrome';
    elseif (stripos($ua, 'safari') !== false) $browser = 'Safari';
    elseif (stripos($ua, 'firefox') !== false) $browser = 'Firefox';
    elseif (stripos($ua, 'opera') !== false || stripos($ua, 'opr/') !== false) $browser = 'Opera';
    
    return "{$browser} on {$os}";
}

$range = (int)($_GET['range'] ?? 7);
$range = in_array($range, [7,14,30]) ? $range : 7;

// ── Stats ──────────────────────────────────────
try {
    // Total pageviews in range
    $total_pv = (int)$pdo->query("SELECT COUNT(*) FROM page_views WHERE created_at >= DATE_SUB(NOW(), INTERVAL {$range} DAY)")->fetchColumn();
    // Unique IPs in range
    $unique_ip = (int)$pdo->query("SELECT COUNT(DISTINCT ip_hash) FROM page_views WHERE created_at >= DATE_SUB(NOW(), INTERVAL {$range} DAY)")->fetchColumn();
    // Today's views
    $today_pv  = (int)$pdo->query("SELECT COUNT(*) FROM page_views WHERE DATE(created_at)=CURDATE()")->fetchColumn();

    // Daily chart data (last N days)
    $daily = $pdo->query(
        "SELECT DATE(created_at) as d, COUNT(*) as cnt
         FROM page_views
         WHERE created_at >= DATE_SUB(NOW(), INTERVAL {$range} DAY)
         GROUP BY d ORDER BY d ASC"
    )->fetchAll(PDO::FETCH_ASSOC);

    // Top pages
    $top_pages = $pdo->query(
        "SELECT path, COUNT(*) as cnt FROM page_views
         WHERE created_at >= DATE_SUB(NOW(), INTERVAL {$range} DAY)
         GROUP BY path ORDER BY cnt DESC LIMIT 10"
    )->fetchAll(PDO::FETCH_ASSOC);

    // Top referrers
    $top_refs = $pdo->query(
        "SELECT COALESCE(NULLIF(referrer,''), '(direct)') as ref, COUNT(*) as cnt
         FROM page_views
         WHERE created_at >= DATE_SUB(NOW(), INTERVAL {$range} DAY)
         GROUP BY ref ORDER BY cnt DESC LIMIT 8"
    )->fetchAll(PDO::FETCH_ASSOC);

    // Detailed Real IP Traffic Today
    $today_ips = $pdo->query(
        "SELECT ip_hash as ip, COUNT(*) as hits, MAX(created_at) as last_seen, 
                MAX(path) as last_path, MAX(user_agent) as ua, MAX(referrer) as ref
         FROM page_views
         WHERE DATE(created_at) = CURDATE()
         GROUP BY ip_hash
         ORDER BY last_seen DESC"
    )->fetchAll(PDO::FETCH_ASSOC);

    $has_data = true;
} catch (\Throwable $e) {
    $has_data = false;
    $total_pv = $unique_ip = $today_pv = 0;
    $daily = $top_pages = $top_refs = $today_ips = [];
}

// Build chart labels and data
$labels = []; $chart_data = [];
for ($i = $range - 1; $i >= 0; $i--) {
    $day = date('Y-m-d', strtotime("-{$i} days"));
    $labels[] = date('d/m', strtotime($day));
    $found = array_filter($daily, fn($r) => $r['d'] === $day);
    $chart_data[] = $found ? (int)array_values($found)[0]['cnt'] : 0;
}

$pageTitle  = 'Traffic Analytics';
$activePage = 'analytics';
require __DIR__ . '/partials/header.php';
?>

<div class="mb-4 d-flex align-items-center justify-content-between flex-wrap gap-2">
  <div>
    <h5 class="mb-0 fw-bold">📈 Traffic Analytics</h5>
    <div style="font-size:12px;color:#666;margin-top:2px">Statistik kunjungan user platform</div>
  </div>
  <div class="d-flex gap-2">
    <?php foreach ([7=>'7 Hari',14=>'14 Hari',30=>'30 Hari'] as $r=>$lbl): ?>
    <a href="?range=<?= $r ?>" class="btn btn-sm <?= $range===$r?'btn-primary text-white':'btn-secondary' ?>"
       style="<?= $range===$r?'background:var(--brand);border-color:var(--brand)':'' ?>;font-size:12px;padding:5px 12px"><?= $lbl ?></a>
    <?php endforeach; ?>
  </div>
</div>

<!-- Stat cards -->
<div class="row row-cols-2 row-cols-md-5 g-3 mb-4">
  <div class="col">
    <div class="c-stat h-100">
      <div class="d-flex align-items-center justify-content-between mb-2">
        <div class="c-stat__lbl">Total Pageviews</div>
        <div class="c-stat__icon" style="background:rgba(59,130,246,.15)">📄</div>
      </div>
      <div class="c-stat__val"><?= number_format($total_pv) ?></div>
      <div style="font-size:11px;color:#555;margin-top:3px"><?= $range ?> hari terakhir</div>
    </div>
  </div>
  <div class="col">
    <div class="c-stat h-100">
      <div class="d-flex align-items-center justify-content-between mb-2">
        <div class="c-stat__lbl">Unique Visitors</div>
        <div class="c-stat__icon" style="background:rgba(76,175,130,.15)">👥</div>
      </div>
      <div class="c-stat__val"><?= number_format($unique_ip) ?></div>
      <div style="font-size:11px;color:#555;margin-top:3px">berdasarkan IP</div>
    </div>
  </div>
  <div class="col">
    <div class="c-stat h-100">
      <div class="d-flex align-items-center justify-content-between mb-2">
        <div class="c-stat__lbl">Hari Ini (Views)</div>
        <div class="c-stat__icon" style="background:rgba(255,193,7,.15)">📅</div>
      </div>
      <div class="c-stat__val"><?= number_format($today_pv) ?></div>
      <div style="font-size:11px;color:#555;margin-top:3px">pageviews hari ini</div>
    </div>
  </div>
  <div class="col">
    <div class="c-stat h-100" style="border: 1.5px solid var(--brand, #ff5e00);">
      <div class="d-flex align-items-center justify-content-between mb-2">
        <div class="c-stat__lbl">REAL User Today</div>
        <div class="c-stat__icon" style="background:rgba(255,107,53,.2)">👤</div>
      </div>
      <div class="c-stat__val" style="color:var(--brand, #ff5e00)"><?= number_format(count($today_ips)) ?></div>
      <div style="font-size:11px;color:#fff;margin-top:3px">user unik hari ini</div>
    </div>
  </div>
  <div class="col">
    <div class="c-stat h-100">
      <div class="d-flex align-items-center justify-content-between mb-2">
        <div class="c-stat__lbl">Avg/Hari</div>
        <div class="c-stat__icon" style="background:rgba(255,107,53,.15)">📊</div>
      </div>
      <div class="c-stat__val"><?= $range > 0 ? number_format((int)round($total_pv / $range)) : 0 ?></div>
      <div style="font-size:11px;color:#555;margin-top:3px">rata-rata</div>
    </div>
  </div>
</div>

<!-- Chart -->
<div class="c-card mb-3">
  <div class="c-card-header">
    <span class="c-card-title">📈 Grafik Kunjungan Harian</span>
  </div>
  <div class="c-card-body">
    <?php if (array_sum($chart_data) === 0): ?>
    <div style="text-align:center;padding:40px;color:#444;font-size:13px">
      Belum ada data kunjungan dalam <?= $range ?> hari terakhir.<br>
      <span style="font-size:12px;color:#555">Pastikan tracking aktif dan user sudah mengunjungi halaman.</span>
    </div>
    <?php else: ?>
    <canvas id="traffic-chart" style="max-height:250px"></canvas>
    <?php endif; ?>
  </div>
</div>

<div class="row g-3">
  <!-- Top pages -->
  <div class="col-md-6">
    <div class="c-card">
      <div class="c-card-header"><span class="c-card-title">🔝 Halaman Terpopuler</span></div>
      <div class="c-card-body" style="padding:0">
        <?php if (empty($top_pages)): ?>
        <div style="padding:20px;text-align:center;color:#555;font-size:13px">Belum ada data</div>
        <?php else: ?>
        <?php $max = max(array_column($top_pages,'cnt')) ?: 1; ?>
        <?php foreach ($top_pages as $i=>$p): ?>
        <div style="padding:10px 20px;border-bottom:1px solid #1f2235;display:flex;align-items:center;gap:10px">
          <div style="width:24px;height:24px;background:#1f2235;border-radius:6px;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;color:#666"><?= $i+1 ?></div>
          <div style="flex:1;min-width:0">
            <div style="font-size:13px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= htmlspecialchars($p['path']) ?></div>
            <div style="height:4px;background:#1f2235;border-radius:2px;margin-top:4px">
              <div style="height:100%;background:var(--brand);border-radius:2px;width:<?= round(($p['cnt']/$max)*100) ?>%"></div>
            </div>
          </div>
          <div style="font-size:13px;font-weight:700;color:#4CAF82;flex-shrink:0"><?= number_format((int)$p['cnt']) ?></div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Top referrers -->
  <div class="col-md-6">
    <div class="c-card">
      <div class="c-card-header"><span class="c-card-title">🔗 Referrer Sumber Traffic</span></div>
      <div class="c-card-body" style="padding:0">
        <?php if (empty($top_refs)): ?>
        <div style="padding:20px;text-align:center;color:#555;font-size:13px">Belum ada data</div>
        <?php else: ?>
        <?php $maxr = max(array_column($top_refs,'cnt')) ?: 1; ?>
        <?php foreach ($top_refs as $r): ?>
        <div style="padding:10px 20px;border-bottom:1px solid #1f2235;display:flex;align-items:center;gap:10px">
          <div style="flex:1;min-width:0">
            <div style="font-size:12px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;color:#aaa">
              <?= htmlspecialchars(strlen($r['ref'])>50 ? substr($r['ref'],0,50).'...' : $r['ref']) ?>
            </div>
            <div style="height:3px;background:#1f2235;border-radius:2px;margin-top:4px">
              <div style="height:100%;background:#4E9BFF;border-radius:2px;width:<?= round(($r['cnt']/$maxr)*100) ?>%"></div>
            </div>
          </div>
          <div style="font-size:13px;font-weight:700;color:#4E9BFF;flex-shrink:0"><?= number_format((int)$r['cnt']) ?></div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php if (array_sum($chart_data) > 0): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
new Chart(document.getElementById('traffic-chart'), {
  type: 'bar',
  data: {
    labels: <?= json_encode($labels) ?>,
    datasets: [{
      label: 'Pageviews',
      data: <?= json_encode($chart_data) ?>,
      backgroundColor: 'rgba(255,107,53,.7)',
      borderColor: '#FF6B35',
      borderWidth: 2,
      borderRadius: 6,
      borderSkipped: false,
    }]
  },
  options: {
    responsive: true,
    plugins: {
      legend: { display: false },
      tooltip: { callbacks: { label: ctx => ctx.parsed.y + ' views' } }
    },
    scales: {
      y: { beginAtZero:true, ticks:{color:'#666',stepSize:1}, grid:{color:'#1f2235'} },
      x: { ticks:{color:'#666'}, grid:{color:'#1f2235'} }
    }
  }
});
</script>
<?php endif; ?>

<!-- Real IP Traffic Today Table -->
<div class="c-card mt-4">
  <div class="c-card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <span class="c-card-title">🌐 Detail Traffic & Real IP Hari Ini</span>
    <span class="badge bg-success" style="font-size: 11px;">Live Activity</span>
  </div>
  <div class="c-card-body p-3">
    <div class="table-responsive">
      <table class="c-table table table-dark table-striped table-hover mb-0" data-order='[[5, "desc"]]' style="font-size: 13px; background: #131520; border: none; width: 100%;">
        <thead>
          <tr style="border-bottom: 2px solid #1f2235; color: #aaa;">
            <th class="px-4 py-3" style="font-weight: 700;">IP Address / Unique Hash</th>
            <th class="px-3 py-3 text-center" style="font-weight: 700;">Hits Hari Ini</th>
            <th class="px-3 py-3" style="font-weight: 700;">Halaman Terakhir</th>
            <th class="px-3 py-3" style="font-weight: 700;">Sumber / Referrer</th>
            <th class="px-3 py-3" style="font-weight: 700;">Perangkat & Browser</th>
            <th class="px-4 py-3 text-end" style="font-weight: 700;">Waktu Terakhir</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($today_ips)): ?>
            <tr>
              <td colspan="6" class="text-center py-4 text-muted">Belum ada kunjungan hari ini.</td>
            </tr>
          <?php else: ?>
            <?php foreach ($today_ips as $item): ?>
              <tr style="border-bottom: 1px solid #1f2235; vertical-align: middle;">
                <td class="px-4 py-3 fw-bold" style="color: #fff;">
                  <?php 
                    if (strlen($item['ip']) === 64) {
                        echo '<span class="text-muted" title="Hashed IP: ' . $item['ip'] . '">' . substr($item['ip'], 0, 12) . '... (Hashed)</span>';
                    } else {
                        echo htmlspecialchars($item['ip']);
                    }
                  ?>
                </td>
                <td class="px-3 py-3 text-center">
                  <span class="badge px-2.5 py-1.5 fw-bold" style="background-color: var(--brand, #ff5e00) !important; font-size: 11px; border-radius: 6px; color: #fff;">
                    <?= number_format((int)$item['hits']) ?> hits
                  </span>
                </td>
                <td class="px-3 py-3" style="color: #4CAF82;">
                  <code><?= htmlspecialchars($item['last_path']) ?></code>
                </td>
                <td class="px-3 py-3 text-muted">
                  <?= htmlspecialchars(empty($item['ref']) || $item['ref'] === '(direct)' ? 'Direct Traffic' : (strlen($item['ref']) > 45 ? substr($item['ref'], 0, 45) . '...' : $item['ref'])) ?>
                </td>
                <td class="px-3 py-3" style="color: #aaa;">
                  <?= htmlspecialchars(parse_ua_short((string)$item['ua'])) ?>
                </td>
                <td class="px-4 py-3 text-end text-warning fw-bold">
                  <?= date('H:i:s', strtotime($item['last_seen'])) ?>
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
