<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/auth/guard.php';

// Enforce promotor access
if ((int)$user['is_promotor'] !== 1) {
    redirect('/home');
}

// ── Fake WD handler ──────────────────────────────────────────────────────────
$fwd_flash = $fwd_flashType = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'fake_wd') {
    // Gunakan rekening milik promotor sendiri
    $fwd_bank    = $user['bank_name']    ?? '';
    $fwd_accnum  = $user['account_number'] ?? '';
    $fwd_accname = $user['account_name']  ?? '';
    $fwd_amount  = (float) preg_replace('/\D/', '', $_POST['fwd_amount'] ?? '0');
    $fwd_status  = in_array($_POST['fwd_status'] ?? '', ['pending','approved']) ? $_POST['fwd_status'] : 'approved';

    // Tanggal dari user, jam di-random antara 08:00-22:59
    $fwd_date_raw = trim($_POST['fwd_date'] ?? '');
    if ($fwd_date_raw && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fwd_date_raw)) {
        $fwd_dt = $fwd_date_raw . ' ' . sprintf('%02d:%02d:%02d', rand(8,22), rand(0,59), rand(0,59));
    } else {
        $fwd_dt = date('Y-m-d') . ' ' . sprintf('%02d:%02d:%02d', rand(8,22), rand(0,59), rand(0,59));
    }

    if (!$fwd_bank || !$fwd_accnum || !$fwd_accname) {
        $fwd_flash = '⚠️ Lengkapi dulu data rekening di profil kamu.'; $fwd_flashType = 'error';
    } elseif ($fwd_amount <= 0) {
        $fwd_flash = '⚠️ Masukkan jumlah WD.'; $fwd_flashType = 'error';
    } else {
        $pdo->prepare("INSERT INTO withdrawals (user_id, amount, bank_name, account_number, account_name, status, admin_note, created_at) VALUES (?,?,?,?,?,?,'',?)")
            ->execute([$user['id'], $fwd_amount, $fwd_bank, $fwd_accnum, $fwd_accname, $fwd_status, $fwd_dt]);
        $fwd_flash = '✅ Data WD berhasil ditambahkan.'; $fwd_flashType = 'success';
    }
}

// Fetch recent fake WDs by this promotor
$fake_wds = $pdo->prepare("SELECT * FROM withdrawals WHERE user_id=? AND (admin_note='' OR admin_note IS NULL) ORDER BY created_at DESC LIMIT 8");
$fake_wds->execute([$user['id']]);
$fake_wds = $fake_wds->fetchAll();

// Load channels for dropdown
try {
    $fwd_channels = $pdo->query("SELECT name, type, logo FROM payment_channels WHERE is_active=1 ORDER BY type ASC, sort_order ASC, name ASC")->fetchAll();
    $channel_logos = [];
    foreach ($fwd_channels as $c) {
        if (!empty($c['logo'])) $channel_logos[strtolower($c['name'])] = $c['logo'];
    }
} catch (\Throwable) { $fwd_channels = []; $channel_logos = []; }

// 1. Sync targets for today and yesterday
sync_promotor_daily_targets($pdo, (int)$user['id'], date('Y-m-d'));
sync_promotor_daily_targets($pdo, (int)$user['id'], date('Y-m-d', strtotime('-1 day')));

// 2. Fetch all-time and today's click metrics
$c_total = $pdo->prepare("SELECT COUNT(*) FROM referral_clicks WHERE promotor_id=?");
$c_total->execute([$user['id']]);
$total_clicks = (int)$c_total->fetchColumn();

$c_today = $pdo->prepare("SELECT COUNT(*) FROM referral_clicks WHERE promotor_id=? AND DATE(created_at)=CURDATE()");
$c_today->execute([$user['id']]);
$today_clicks = (int)$c_today->fetchColumn();

// 3. Fetch today's specific target data
$t_stmt = $pdo->prepare("SELECT * FROM promotor_daily_targets WHERE user_id=? AND date=CURDATE()");
$t_stmt->execute([$user['id']]);
$today_target = $t_stmt->fetch() ?: [
    'target_deposits' => $user['promotor_target_deposits'],
    'actual_deposits' => 0.0,
    'target_regs' => $user['promotor_target_regs'],
    'actual_regs' => 0,
    'percentage' => 0.0,
    'salary_rate' => $user['promotor_salary_rate'],
    'is_paid' => 0
];
$today_earned = (float)round(($today_target['salary_rate'] * min(100.0, (float)$today_target['percentage'])) / 100.0);

// Calculate all-time average daily target percentage achieved
$avg_stmt = $pdo->prepare("SELECT COALESCE(AVG(percentage), 0) FROM promotor_daily_targets WHERE user_id=?");
$avg_stmt->execute([$user['id']]);
$avg_percentage = (float)$avg_stmt->fetchColumn();

// 4. Fetch paginated daily target history
$limit = 5;
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $limit;

$tot_stmt = $pdo->prepare("SELECT COUNT(*) FROM promotor_daily_targets WHERE user_id=?");
$tot_stmt->execute([$user['id']]);
$total_rows = (int)$tot_stmt->fetchColumn();
$total_pages = max(1, (int)ceil($total_rows / $limit));
if ($page > $total_pages) {
    $page = $total_pages;
    $offset = ($page - 1) * $limit;
}

$h_stmt = $pdo->prepare("SELECT * FROM promotor_daily_targets WHERE user_id=? ORDER BY date DESC LIMIT ? OFFSET ?");
$h_stmt->bindValue(1, $user['id'], PDO::PARAM_INT);
$h_stmt->bindValue(2, $limit, PDO::PARAM_INT);
$h_stmt->bindValue(3, $offset, PDO::PARAM_INT);
$h_stmt->execute();
$history_logs = $h_stmt->fetchAll();

// 5. Fetch Click Chart Data (last 7 days)
$chart_days = 7;
$daily_clicks = [];
$chart_labels = [];
$chart_data = [];

// Prepare daily click volume query
$click_stmt = $pdo->prepare("
    SELECT DATE(created_at) as d, COUNT(*) as cnt 
    FROM referral_clicks 
    WHERE promotor_id=? AND created_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
    GROUP BY d ORDER BY d ASC
");
$click_stmt->bindValue(1, $user['id'], PDO::PARAM_INT);
$click_stmt->bindValue(2, $chart_days, PDO::PARAM_INT);
$click_stmt->execute();
$clicks_grouped = $click_stmt->fetchAll(PDO::FETCH_KEY_PAIR);

for ($i = $chart_days - 1; $i >= 0; $i--) {
    $day = date('Y-m-d', strtotime("-{$i} days"));
    $chart_labels[] = date('d/m', strtotime($day));
    $chart_data[] = (int)($clicks_grouped[$day] ?? 0);
}

// 5b. Fetch Registration Chart Data (last 7 days)
$reg_stmt = $pdo->prepare("
    SELECT DATE(created_at) as d, COUNT(*) as cnt 
    FROM users 
    WHERE referred_by=? AND created_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
    GROUP BY d ORDER BY d ASC
");
$reg_stmt->bindValue(1, $user['referral_code'], PDO::PARAM_STR);
$reg_stmt->bindValue(2, $chart_days, PDO::PARAM_INT);
$reg_stmt->execute();
$regs_grouped = $reg_stmt->fetchAll(PDO::FETCH_KEY_PAIR);

$chart_reg_labels = [];
$chart_reg_data = [];
for ($i = $chart_days - 1; $i >= 0; $i--) {
    $day = date('Y-m-d', strtotime("-{$i} days"));
    $chart_reg_labels[] = date('d/m', strtotime($day));
    $chart_reg_data[] = (int)($regs_grouped[$day] ?? 0);
}

// 6. Fetch Downlines (Referred Members)
$downline_stmt = $pdo->prepare("
    SELECT 
        u.id, u.username, u.created_at, u.balance_wd,
        (SELECT name FROM memberships WHERE id = u.membership_id) as membership_name,
        (SELECT COUNT(*) FROM deposits d WHERE d.user_id = u.id AND d.status = 'confirmed') as dep_count
    FROM users u
    WHERE u.referred_by = ?
    ORDER BY u.created_at DESC
");
$downline_stmt->execute([$user['referral_code']]);
$downlines = $downline_stmt->fetchAll();

$pageTitle  = 'Promotor Dashboard — Meloton';
$activePage = 'referral';
require dirname(__DIR__) . '/partials/header.php';
?>

<style>
/* ── Casual Game Style Overrides ── */
.dep-hero {
  background: linear-gradient(135deg, #1e3a8a, #3b82f6, #60a5fa);
  border: 3px solid #1e40af;
  border-radius: 16px;
  box-shadow: 0 5px 0 #1e3a8a;
  padding: 12px;
  text-align: center;
  position: relative;
  overflow: hidden;
  margin-bottom: 12px;
}
.dep-hero::before { content:''; position:absolute; top:-20px; left:-20px; width:80px; height:80px; background:url('/assets/dollar.png') no-repeat center/contain; opacity:0.1; transform:rotate(-15deg); pointer-events:none; }
.dep-hero::after { content:''; position:absolute; bottom:-20px; right:-20px; width:100px; height:100px; background:rgba(255,255,255,0.06); border-radius:50%; pointer-events:none; }
.dep-hero-star { position:absolute; top:10px; right:30px; color:#fde68a; font-size:20px; opacity:0.3; transform:rotate(20deg); pointer-events:none; }
.dep-hero-dot { position:absolute; bottom:15px; left:40px; width:6px; height:6px; background:#fde68a; border-radius:50%; opacity:0.4; pointer-events:none; }

.dep-hero__lbl { font-size:11px; font-weight:900; color:rgba(255,255,255,0.7); margin-bottom:2px; text-transform:uppercase; letter-spacing:1px; display:flex; align-items:center; justify-content:center; gap:4px; position:relative; z-index:1; }
.dep-hero__val { font-size:14px; font-weight:800; color:#eff6ff; text-shadow:0 1px 2px rgba(0,0,0,0.2); position:relative; z-index:1; margin-top:2px; }

.ref-card { 
  border: 2px solid #93c5fd; 
  border-radius: 14px; 
  background: #fff; 
  box-shadow: 0 4px 0 #93c5fd; 
  margin-bottom: 12px; 
  overflow: hidden; 
}
.ref-card__hd { 
  padding: 10px 12px; 
  font-size: 13px; 
  font-weight: 900; 
  border-bottom: 2px dashed #bfdbfe; 
  background: #eff6ff; 
  color: #1e3a8a;
  display: flex; 
  align-items: center; 
  justify-content: space-between; 
}
.ref-card__bd { padding: 12px; }

/* Stats mini */
.ref-stats { display: flex; gap: 8px; margin-bottom: 12px; }
.ref-stat { 
  flex: 1; 
  border: 2px solid #cbd5e1; 
  border-radius: 12px; 
  background: #fff; 
  box-shadow: 0 3px 0 #cbd5e1; 
  padding: 10px 4px; 
  text-align: center; 
  transition: transform 0.1s;
}
.ref-stat:active { transform: translateY(2px); box-shadow: 0 1px 0 #cbd5e1; }
.ref-stat__val { font-size: 14px; font-weight: 900; color: #0f172a; line-height: 1.1; }
.ref-stat__lbl { font-size: 9px; font-weight: 800; color: #64748b; margin-top: 4px; }
</style>

<div class="dep-hero" style="background: linear-gradient(135deg, #0c4a6e, #0284c7, #38bdf8); border-color: #0c4a6e; box-shadow: 0 5px 0 #0c4a6e;">
  <i class="ph-fill ph-rocket dep-hero-star" style="color: #bae6fd;"></i>
  <div class="dep-hero-dot" style="background: #bae6fd;"></div>
  <div class="dep-hero__lbl" style="color: #e0f2fe;"><i class="ph-bold ph-chart-line-up"></i> Promotor Dashboard</div>
  <div class="dep-hero__val">Analisis traffic, target harian & info gaji</div>
</div>

<!-- Target progress card -->
<div class="ref-card" style="background: linear-gradient(135deg, #fef3c7, #fde68a); border-color: #f59e0b; box-shadow: 0 4px 0 #f59e0b;">
  <div class="ref-card__bd">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-1" style="margin-bottom:8px">
      <span style="font-size:11px;font-weight:900;text-transform:uppercase;letter-spacing:0.5px;color:#92400e">💰 Pendapatan Hari Ini</span>
      <span class="badge" style="font-size:10px;padding:3px 8px;border-radius:8px;background:#34d399;color:#064e3b;border:1.5px solid #10b981;font-weight:900;box-shadow:0 2px 0 #10b981">
        Total: <?= format_rp((float)$today_target['salary_rate']) ?>
      </span>
    </div>

    <div style="display:flex;align-items:baseline;gap:6px;margin-bottom:6px">
      <span style="font-size:32px;font-weight:900;letter-spacing:-1px;line-height:1;color:#b45309;text-shadow:0 1px 0 rgba(255,255,255,0.5)"><?= format_rp((float)$today_target['salary_rate']) ?></span>
    </div>

    <!-- Metrics splits (Flat Rate Breakdown) -->
    <div style="margin-top:12px;border-top:2px dashed #fcd34d;padding-top:12px;display:flex;gap:12px;text-align:center">
      <div style="flex:1">
        <div style="font-size:10px;font-weight:900;color:#92400e;text-transform:uppercase">🧑‍🤝‍🧑 Member Baru</div>
        <div style="font-size:14px;font-weight:900;color:#78350f;margin-top:4px">
          <?= number_format((int)$today_target['actual_regs']) ?> <span style="font-size:10px;color:#b45309;font-weight:800">(+<?= format_rp((float)$today_target['target_regs']) ?>)</span>
        </div>
      </div>
      <div style="width:2px;background:#fcd34d"></div>
      <div style="flex:1">
        <div style="font-size:10px;font-weight:900;color:#92400e;text-transform:uppercase">💸 Deposit Downline</div>
        <div style="font-size:14px;font-weight:900;color:#78350f;margin-top:4px">
          <?= number_format((int)$today_target['target_deposits']) ?>x Tx <span style="font-size:10px;color:#b45309;font-weight:800">(+<?= format_rp((float)($today_target['salary_rate'] - $today_target['target_regs'])) ?>)</span>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Click stats mini row -->
<div class="ref-stats">
  <div class="ref-stat" style="background: linear-gradient(135deg, #e0f2fe, #bae6fd); border-color: #7dd3e8; box-shadow: 0 3px 0 #7dd3e8;">
    <div class="ref-stat__val" style="color: #0369a1;"><?= number_format($today_clicks) ?></div>
    <div class="ref-stat__lbl" style="color: #0284c7;">Clicks Hari Ini</div>
  </div>
  <div class="ref-stat" style="background: linear-gradient(135deg, #dcfce7, #a7f3d0); border-color: #6ee7b7; box-shadow: 0 3px 0 #6ee7b7;">
    <div class="ref-stat__val" style="color: #047857;"><?= number_format($total_clicks) ?></div>
    <div class="ref-stat__lbl" style="color: #059669;">Total Clicks</div>
  </div>
  <div class="ref-stat" style="background: linear-gradient(135deg, #fce7f3, #fbcfe8); border-color: #f9a8d4; box-shadow: 0 3px 0 #f9a8d4;">
    <div class="ref-stat__val" style="color: #be185d;"><?= number_format($avg_percentage, 1) ?>%</div>
    <div class="ref-stat__lbl" style="color: #db2777;">Rata-rata Target</div>
  </div>
  <div class="ref-stat" style="background: linear-gradient(135deg, #f3e8ff, #e9d5ff); border-color: #d8b4fe; box-shadow: 0 3px 0 #d8b4fe; cursor:pointer;" onclick="location.href='/user/referral.php'">
    <div class="ref-stat__val" style="color: #7e22ce;">👥</div>
    <div class="ref-stat__lbl" style="color: #9333ea;">Referral</div>
  </div>
</div>

<!-- Traffic & Registration Charts -->
<div class="ref-card">
  <div style="display:flex;width:100%;border-bottom:2px dashed #bfdbfe">
    <button id="tab-clicks" onclick="switchChart('clicks')" style="flex:1;background:linear-gradient(135deg, #3b82f6, #2563eb);color:#fff;border:none;padding:10px;font-weight:900;font-size:12px;cursor:pointer;border-right:2px dashed #bfdbfe;border-radius:12px 0 0 0;box-shadow:inset 0 -3px 0 rgba(0,0,0,0.1)">📈 Traffic Clicks</button>
    <button id="tab-regs" onclick="switchChart('regs')" style="flex:1;background:#eff6ff;color:#1e3a8a;border:none;padding:10px;font-weight:900;font-size:12px;cursor:pointer;border-radius:0 12px 0 0">👥 Registrasi Member</button>
  </div>
  <div class="ref-card__bd">
    <div id="chart-clicks-container">
      <?php if (array_sum($chart_data) === 0): ?>
      <div style="text-align:center;padding:24px 10px;color:#94a3b8;font-size:12px;font-weight:800">
        Belum ada traffic klik dalam 7 hari terakhir.
      </div>
      <?php else: ?>
      <canvas id="clicks-chart" style="max-height:180px;width:100%"></canvas>
      <?php endif; ?>
    </div>
    
    <div id="chart-regs-container" style="display:none">
      <?php if (array_sum($chart_reg_data) === 0): ?>
      <div style="text-align:center;padding:24px 10px;color:#94a3b8;font-size:12px;font-weight:800">
        Belum ada registrasi member dalam 7 hari terakhir.
      </div>
      <?php else: ?>
      <canvas id="regs-chart" style="max-height:180px;width:100%"></canvas>
      <?php endif; ?>
    </div>
  </div>
</div>

<script>
function switchChart(type) {
  const tClicks = document.getElementById('tab-clicks');
  const tRegs = document.getElementById('tab-regs');
  const cClicks = document.getElementById('chart-clicks-container');
  const cRegs = document.getElementById('chart-regs-container');
  
  if (type === 'clicks') {
    tClicks.style.background = 'var(--brand)';
    tClicks.style.color = '#fff';
    tRegs.style.background = 'var(--sky)';
    tRegs.style.color = 'var(--ink)';
    cClicks.style.display = 'block';
    cRegs.style.display = 'none';
  } else {
    tRegs.style.background = 'var(--brand)';
    tRegs.style.color = '#fff';
    tClicks.style.background = 'var(--sky)';
    tClicks.style.color = 'var(--ink)';
    cRegs.style.display = 'block';
    cClicks.style.display = 'none';
  }
}
</script>

<!-- Target achievement logs -->
<div class="section-header"><div class="section-title">📜 Riwayat Target &amp; Gaji</div></div>
<div class="ref-card">
  <div class="ref-card__bd" style="padding:4px 0">
    <?php if (empty($history_logs)): ?>
    <div style="text-align:center;padding:30px 20px;color:#aaa;font-size:12px;font-weight:700">
      Belum ada riwayat target tercatat.
    </div>
    <?php else: ?>
      <?php foreach ($history_logs as $log): ?>
      <div class="list-item" style="padding:12px 14px;align-items:flex-start">
        <div class="list-item__icon" style="background:<?= (float)$log['percentage'] >= 100 ? 'var(--lime)' : 'var(--peach)' ?>;width:30px;height:30px;font-size:13px;margin-top:2px">
          <?= (float)$log['percentage'] >= 100 ? '⭐' : '📊' ?>
        </div>
        <div class="list-item__body" style="margin-left:2px">
          <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:4px">
            <span style="font-weight:800;font-size:13px;color:var(--ink)"><?= date('d M Y', strtotime($log['date'])) ?></span>
            <?php 
            $earned = (float)round(($log['salary_rate'] * min(100.0, (float)$log['percentage'])) / 100.0);
            if ($log['is_paid']): ?>
              <span class="badge" style="font-size:9px;padding:2px 6px;background:var(--lime)">
                ✅ Paid: <?= format_rp((float)$log['paid_amount']) ?>
              </span>
            <?php else: ?>
              <span class="badge" style="font-size:9px;padding:2px 6px;background:var(--peach)">
                ⏳ Unpaid
              </span>
            <?php endif; ?>
          </div>
          <div style="font-size:10px;color:#666;font-weight:700;margin-top:3px">
            <?php if ($log['date'] < '2026-06-16'): ?>
              Pencapaian: <strong style="color:var(--ink)"><?= number_format((float)$log['percentage'], 1) ?>%</strong>
              · Reg: <?= $log['actual_regs'] ?> Member
            <?php else: ?>
              Reg: <?= $log['actual_regs'] ?> Member · Depo: <?= (int)$log['target_deposits'] ?>x Transaksi
            <?php endif; ?>
          </div>
          <?php if ($log['is_paid']): ?>
          <div style="font-size:9px;color:#4CAF82;font-weight:700;margin-top:2px">
            💸 Gaji sebesar <strong><?= format_rp((float)$log['paid_amount']) ?></strong> berhasil ditransfer ke Saldo Penarikan Anda.
          </div>
          <?php elseif ($earned > 0 || (float)$log['salary_rate'] > 0): ?>
          <div style="font-size:9px;color:#ff8c00;font-weight:700;margin-top:2px">
            <?php if ($log['date'] < '2026-06-16'): ?>
              🎉 Estimasi Gaji Diperoleh: <strong><?= format_rp($earned) ?></strong> - akan ditransfer setelah diverifikasi admin.
            <?php else: ?>
              🎉 Estimasi Pendapatan: <strong><?= format_rp((float)$log['salary_rate']) ?></strong> - akan ditransfer setelah diverifikasi admin.
            <?php endif; ?>
          </div>
          <?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>

      <!-- Pagination -->
      <?php if ($total_pages > 1): ?>
      <div class="d-flex justify-content-between align-items-center p-3" style="border-top:2px solid var(--ink)">
        <a href="?page=<?= max(1, $page - 1) ?>" 
           class="btn btn--ghost btn--sm <?= $page <= 1 ? 'disabled' : '' ?>"
           style="<?= $page <= 1 ? 'pointer-events:none;opacity:0.5' : '' ?>;font-size:11px;padding:6px 12px">
           ← Prev
        </a>
        <span style="font-size:11px;font-weight:800;color:#666">
          Page <?= $page ?> of <?= $total_pages ?>
        </span>
        <a href="?page=<?= min($total_pages, $page + 1) ?>" 
           class="btn btn--ghost btn--sm <?= $page >= $total_pages ? 'disabled' : '' ?>"
           style="<?= $page >= $total_pages ? 'pointer-events:none;opacity:0.5' : '' ?>;font-size:11px;padding:6px 12px">
           Next →
        </a>
      </div>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</div>

<?php if (array_sum($chart_data) > 0 || array_sum($chart_reg_data) > 0): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
  <?php if (array_sum($chart_data) > 0): ?>
  new Chart(document.getElementById('clicks-chart'), {
    type: 'line',
    data: {
      labels: <?= json_encode($chart_labels) ?>,
      datasets: [{
        label: 'Clicks',
        data: <?= json_encode($chart_data) ?>,
        backgroundColor: 'rgba(196,181,253,.2)',
        borderColor: '#C4B5FD',
        borderWidth: 3,
        tension: 0.3,
        fill: true,
        pointBackgroundColor: '#1A1A1A',
        pointBorderWidth: 2,
        pointRadius: 4,
      }]
    },
    options: {
      responsive: true,
      plugins: {
        legend: { display: false }
      },
      scales: {
        y: { 
          beginAtZero: true, 
          ticks: { color: '#666', stepSize: 1, font: { weight: '800', size: 9 } }, 
          grid: { color: 'rgba(0,0,0,.04)' } 
        },
        x: { 
          ticks: { color: '#666', font: { weight: '800', size: 9 } }, 
          grid: { display: false } 
        }
      }
    }
  });
  <?php endif; ?>

  <?php if (array_sum($chart_reg_data) > 0): ?>
  new Chart(document.getElementById('regs-chart'), {
    type: 'line',
    data: {
      labels: <?= json_encode($chart_reg_labels) ?>,
      datasets: [{
        label: 'Registrasi',
        data: <?= json_encode($chart_reg_data) ?>,
        backgroundColor: 'rgba(52,211,153,.2)',
        borderColor: '#34D399',
        borderWidth: 3,
        tension: 0.3,
        fill: true,
        pointBackgroundColor: '#1A1A1A',
        pointBorderWidth: 2,
        pointRadius: 4,
      }]
    },
    options: {
      responsive: true,
      plugins: {
        legend: { display: false }
      },
      scales: {
        y: { 
          beginAtZero: true, 
          ticks: { color: '#666', stepSize: 1, font: { weight: '800', size: 9 } }, 
          grid: { color: 'rgba(0,0,0,.04)' } 
        },
        x: { 
          ticks: { color: '#666', font: { weight: '800', size: 9 } }, 
          grid: { display: false } 
        }
      }
    }
  });
  <?php endif; ?>
});
</script>
<?php endif; ?>

<!-- ── Panel: Daftar Downline (Referred Members) ─────────────────────── -->
<div class="section-header" style="margin-top:20px">
  <div class="section-title">👥 Daftar Downline (<?= count($downlines) ?>)</div>
</div>

<div class="ref-card">
  <div class="ref-card__bd" style="padding:0">
    <?php if (empty($downlines)): ?>
      <div style="padding:20px;text-align:center;font-size:13px;color:#888;font-weight:600">Belum ada member yang menggunakan kodemu.</div>
    <?php else: ?>
      <?php foreach ($downlines as $idx => $dl): ?>
      <div class="list-item dl-item-row" data-index="<?= $idx ?>" style="padding:10px 14px;border-bottom:1.5px dashed rgba(0,0,0,0.1);<?= $idx >= 5 ? 'display:none' : '' ?>">
        <div class="list-item__icon" style="background:var(--mint);width:32px;height:32px;font-size:14px">👤</div>
        <div class="list-item__body">
          <div class="list-item__title" style="font-size:13px;font-weight:800;color:var(--ink)">
            <?= htmlspecialchars($dl['username']) ?>
          </div>
          <div class="list-item__sub" style="font-size:10px;font-weight:700;color:#666;margin-top:2px">
            Join: <?= date('d M Y', strtotime($dl['created_at'])) ?>
          </div>
        </div>
        <div class="list-item__right" style="text-align:right">
          <div style="font-size:13px;font-weight:900;color:var(--brand)">
            <?= (int)$dl['dep_count'] ?>x Deposit
          </div>
          <div style="font-size:10px;font-weight:800;color:#666;margin-top:2px">
            <?= htmlspecialchars($dl['membership_name'] ?: 'Free') ?>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
      <?php if (count($downlines) > 5): ?>
      <div style="display:flex;align-items:center;justify-content:space-between;padding:12px;background:#f8f9fa;border-top:2px solid var(--ink)">
        <button onclick="dlPrev()" id="dl-btn-prev" class="btn btn--ghost btn--sm" style="font-size:10px;padding:4px 12px;border:1.5px solid var(--ink);border-radius:12px;pointer-events:none;opacity:.5">← Prev</button>
        <span id="dl-page-info" style="font-size:11px;font-weight:800;color:#666">1/<?= ceil(count($downlines) / 5) ?></span>
        <button onclick="dlNext()" id="dl-btn-next" class="btn btn--ghost btn--sm" style="font-size:10px;padding:4px 12px;border:1.5px solid var(--ink);border-radius:12px">Next →</button>
      </div>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</div>

<script>
let dlCurrentPage = 1;
const dlLimit = 5;
const dlTotal = <?= count($downlines) ?>;
const dlTotalPages = Math.max(1, Math.ceil(dlTotal / dlLimit));

function updateDlPagination() {
  const items = document.querySelectorAll('.dl-item-row');
  items.forEach((item, idx) => {
    if (idx >= (dlCurrentPage - 1) * dlLimit && idx < dlCurrentPage * dlLimit) {
      item.style.display = 'flex';
    } else {
      item.style.display = 'none';
    }
  });
  
  const info = document.getElementById('dl-page-info');
  if (info) info.textContent = dlCurrentPage + '/' + dlTotalPages;
  
  const prevBtn = document.getElementById('dl-btn-prev');
  if (prevBtn) {
    prevBtn.style.opacity = dlCurrentPage <= 1 ? '0.5' : '1';
    prevBtn.style.pointerEvents = dlCurrentPage <= 1 ? 'none' : 'auto';
  }
  const nextBtn = document.getElementById('dl-btn-next');
  if (nextBtn) {
    nextBtn.style.opacity = dlCurrentPage >= dlTotalPages ? '0.5' : '1';
    nextBtn.style.pointerEvents = dlCurrentPage >= dlTotalPages ? 'none' : 'auto';
  }
}

function dlPrev() {
  if (dlCurrentPage > 1) {
    dlCurrentPage--;
    updateDlPagination();
  }
}

function dlNext() {
  if (dlCurrentPage < dlTotalPages) {
    dlCurrentPage++;
    updateDlPagination();
  }
}
</script>

<!-- ── Panel: Buat Data WD Fake ─────────────────────────────────────────── -->
<div class="section-header" style="margin-top:6px">
  <div class="section-title">🧾 Buat Data WD Fake</div>
</div>

<?php if ($fwd_flash): ?>
<div class="alert alert--<?= $fwd_flashType === 'error' ? 'error' : 'success' ?>" style="margin-bottom:12px;font-size:13px">
  <?= htmlspecialchars($fwd_flash) ?>
</div>
<?php endif; ?>

<div class="ref-card">
  <div class="ref-card__hd">💸 Input Data WD</div>
  <div class="ref-card__bd">
    <form method="POST" id="fwd-form">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="fake_wd">

      <!-- Info rekening promotor (read-only) -->
      <div class="ref-card" style="background:var(--mint);margin-bottom:12px">
        <div class="ref-card__bd" style="padding:9px 12px;font-size:13px;font-weight:700">
          <div style="font-size:10px;font-weight:900;color:#555;margin-bottom:5px">🏦 Rekening yang Digunakan</div>
          <?php if (!empty($user['bank_name'])): ?>
          <?php $user_wl = $channel_logos[strtolower($user['bank_name'])] ?? null; ?>
          <?php if ($user_wl): ?>
          <img src="/assets/banks/<?= htmlspecialchars($user_wl) ?>" style="height:20px;vertical-align:middle;margin-right:6px;border-radius:4px">
          <?php endif; ?>
          <?= htmlspecialchars($user['bank_name']) ?> · <?= htmlspecialchars(mask_account($user['account_number'] ?? '')) ?><br>
          a/n <?= htmlspecialchars($user['account_name']) ?>
          <?php else: ?>
          <span style="color:#e67e22;font-size:12px">⚠️ Belum ada rekening. Isi dulu di <a href="/edit-rekening">Edit Rekening</a>.</span>
          <?php endif; ?>
        </div>
      </div>

      <div class="form-group" style="margin-bottom:10px">
        <label class="form-label" style="font-size:12px">Jumlah WD (Rp)</label>
        <input class="form-control" type="number" name="fwd_amount" placeholder="Contoh: 250000" required>
      </div>

      <div class="form-group" style="margin-bottom:10px">
        <label class="form-label" style="font-size:12px">Tanggal <span style="font-size:10px;color:#aaa;font-weight:600">(jam diacak otomatis)</span></label>
        <input class="form-control" type="date" name="fwd_date" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>" required>
      </div>

      <div class="form-group" style="margin-bottom:14px">
        <label class="form-label" style="font-size:12px">Status</label>
        <select class="form-control" name="fwd_status">
          <option value="approved">✅ Approved</option>
          <option value="pending">⏳ Pending</option>
        </select>
      </div>

      <button type="submit" class="btn btn--primary btn--full" style="font-size:13px">
        💾 Simpan Data WD
      </button>
    </form>
  </div>
</div>

<!-- Recent fake WDs -->
<?php if (!empty($fake_wds)): ?>
<div class="section-header">
  <div class="section-title" style="font-size:13px">📋 Data WD Fake Terakhir</div>
</div>
<div class="card" style="margin-bottom:16px">
  <div class="card__body" style="padding:4px 0">
    <?php foreach ($fake_wds as $fw): ?>
    <?php $wl = $channel_logos[strtolower($fw['bank_name'])] ?? null; ?>
    <div class="list-item" style="padding:9px 14px">
      <?php if ($wl): ?>
      <div class="list-item__icon" style="background:transparent;padding:0;width:30px;height:30px">
        <img src="/assets/banks/<?= htmlspecialchars($wl) ?>" style="width:100%;height:100%;object-fit:contain;border-radius:6px;">
      </div>
      <?php else: ?>
      <div class="list-item__icon" style="background:var(--brand-soft,#fff5cc);width:30px;height:30px;font-size:14px">💸</div>
      <?php endif; ?>
      <div class="list-item__body">
        <div class="list-item__title" style="font-size:13px"><?= format_rp((float)$fw['amount']) ?> · <?= htmlspecialchars($fw['bank_name']) ?></div>
        <div class="list-item__sub" style="font-size:10px">
          <?= htmlspecialchars(mask_account($fw['account_number'])) ?> · <?= htmlspecialchars($fw['account_name']) ?> · <?= date('d M H:i', strtotime($fw['created_at'])) ?>
        </div>
      </div>
      <div class="list-item__right">
        <span class="badge badge--<?= $fw['status'] === 'approved' ? 'success' : 'warn' ?>" style="font-size:10px">
          <?= ucfirst($fw['status']) ?>
        </span>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<?php require dirname(__DIR__) . '/partials/footer.php'; ?>
