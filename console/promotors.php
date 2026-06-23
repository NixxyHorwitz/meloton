<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
staff_require('analytics');
csrf_enforce();

$flash = $flashType = '';
$tab = $_GET['tab'] ?? 'list';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    // Save or Edit Promotor
    if ($action === 'save_promotor') {
        $user_id = (int)($_POST['user_id'] ?? 0);
        $target_dep = (float)($_POST['target_deposits'] ?? 0.00);
        $target_reg = (int)($_POST['target_regs'] ?? 0);
        $salary_rate = (float)($_POST['salary_rate'] ?? 0.00);
        
        if ($user_id > 0) {
            $pdo->prepare("
                UPDATE users 
                SET is_promotor = 1, 
                    promotor_target_deposits = ?, 
                    promotor_target_regs = ?, 
                    promotor_salary_rate = ? 
                WHERE id = ?
            ")->execute([$target_dep, $target_reg, $salary_rate, $user_id]);
            
            // Sync today's target snapshot immediately
            sync_promotor_daily_targets($pdo, $user_id, date('Y-m-d'));
            
            $flash = "Pengaturan promotor berhasil disimpan!";
        } else {
            $flash = "Pilih user terlebih dahulu."; $flashType = 'error';
        }
    }
    
    // Remove Promotor Role
    if ($action === 'remove_promotor') {
        $user_id = (int)($_POST['user_id'] ?? 0);
        if ($user_id > 0) {
            $pdo->prepare("UPDATE users SET is_promotor = 0 WHERE id = ?")->execute([$user_id]);
            $flash = "Peran promotor berhasil dicabut.";
        }
    }
    
    // Toggle Referral Status
    if ($action === 'toggle_referral') {
        $user_id = (int)($_POST['user_id'] ?? 0);
        $new_val = (int)($_POST['new_val'] ?? 1);
        if ($user_id > 0) {
            $pdo->prepare("UPDATE users SET is_referral_active = ? WHERE id = ?")->execute([$new_val, $user_id]);
            $flash = $new_val ? "Referral berhasil diaktifkan." : "Referral berhasil dihentikan.";
        }
    }
    
    // Payout Promotor Salary
    if ($action === 'pay_salary') {
        $log_id = (int)($_POST['log_id'] ?? 0);
        $pdo->beginTransaction();
        try {
            $log = $pdo->prepare("SELECT * FROM promotor_daily_targets WHERE id=? FOR UPDATE");
            $log->execute([$log_id]);
            $log = $log->fetch();
            
            if ($log && !$log['is_paid']) {
                // Calculate proportional pay amount based on percentage (capped at 100%)
                $pay_amount = (float)round(($log['salary_rate'] * min(100.0, (float)$log['percentage'])) / 100.0);
                
                if ($pay_amount <= 0) {
                    throw new \Exception("Jumlah gaji yang diperoleh adalah Rp 0 karena pencapaian target 0%.");
                }
                
                // 1. Mark target log as paid and save actual paid amount
                $pdo->prepare("UPDATE promotor_daily_targets SET is_paid = 1, paid_amount = ? WHERE id = ?")->execute([$pay_amount, $log_id]);
                // 2. Credit salary to promotor's balance_wd
                $pdo->prepare("UPDATE users SET balance_wd = balance_wd + ? WHERE id = ?")->execute([$pay_amount, $log['user_id']]);
                
                // Get username for notification logs
                $u_stmt = $pdo->prepare("SELECT username FROM users WHERE id = ?");
                $u_stmt->execute([$log['user_id']]);
                $username = $u_stmt->fetchColumn() ?: 'Promotor';
                
                $pdo->commit();
                
                // Send Telegram Notification
                $msg = "<b>💸 GAJI PROMOTOR DICAIRKAN (PROPORSIAL)</b>\n"
                     . "👤 Promotor: <b>@{$username}</b>\n"
                     . "📅 Tanggal Target: " . date('d M Y', strtotime($log['date'])) . "\n"
                     . "🎯 Pencapaian: <b>" . number_format((float)$log['percentage'], 1) . "%</b>\n"
                     . "💰 Gaji Diperoleh: <b>" . format_rp($pay_amount) . "</b> (dari total " . format_rp((float)$log['salary_rate']) . ")\n"
                     . "✅ Status: Berhasil dicairkan langsung ke Saldo Penarikan.";
                send_telegram_notif($pdo, $msg, [], 'log');
                
                $flash = "Gaji promotor @{$username} sebesar " . format_rp($pay_amount) . " (" . number_format((float)$log['percentage'], 1) . "% pencapaian) berhasil dicairkan!";
            } else {
                $pdo->rollBack();
                $flash = "Target log tidak ditemukan atau sudah dibayar."; $flashType = 'error';
            }
        } catch (\Throwable $e) {
            $pdo->rollBack();
            $flash = "Gagal mencairkan gaji: " . $e->getMessage(); $flashType = 'error';
        }
    }
    
    // Save Global Flat Rates
    if ($action === 'save_global_rates') {
        if (isset($_POST['promotor_per_member_bonus'])) {
            setting_set($pdo, 'promotor_per_member_bonus', clean_input($_POST['promotor_per_member_bonus']));
        }
        if (isset($_POST['promotor_per_deposit_bonus'])) {
            setting_set($pdo, 'promotor_per_deposit_bonus', clean_input($_POST['promotor_per_deposit_bonus']));
        }
        if (isset($_POST['promotor_deposit_scheme'])) {
            setting_set($pdo, 'promotor_deposit_scheme', clean_input($_POST['promotor_deposit_scheme']));
        }
        if (isset($_POST['promotor_deposit_percent'])) {
            setting_set($pdo, 'promotor_deposit_percent', clean_input($_POST['promotor_deposit_percent']));
        }
        $flash = "Pengaturan Skenario Komisi berhasil disimpan!";
    }
}

// Fetch active promotors
$promotors = $pdo->query("SELECT id, username, email, referral_code, promotor_target_deposits, promotor_target_regs, promotor_salary_rate, is_referral_active FROM users WHERE is_promotor=1 ORDER BY username ASC")->fetchAll();

// Fetch potential promotors (non-promotors) for selection
$eligible_users = $pdo->query("SELECT id, username FROM users WHERE is_promotor=0 ORDER BY username ASC")->fetchAll();

// Fetch daily target performance logs
$logs = $pdo->query("
    SELECT pt.*, u.username 
    FROM promotor_daily_targets pt 
    JOIN users u ON u.id = pt.user_id 
    ORDER BY pt.date DESC, pt.percentage DESC
")->fetchAll();

// Fetch referred members if tab is members
$referred_members = [];
if ($tab === 'members') {
    $filter_promotor_id = (int)($_GET['promotor_id'] ?? 0);
    $whereClause = "WHERE p.is_promotor = 1";
    $params = [];
    
    if ($filter_promotor_id > 0) {
        $whereClause .= " AND p.id = ?";
        $params[] = $filter_promotor_id;
    }

    $stmt = $pdo->prepare("
        SELECT u.id, u.username, u.email, u.created_at, p.username as promotor_name,
               COALESCE((SELECT SUM(amount) FROM deposits WHERE user_id = u.id AND status = 'confirmed'), 0) as total_deposit,
               COALESCE(m.name, 'Free') as membership_name
        FROM users u 
        JOIN users p ON u.referred_by = p.referral_code 
        LEFT JOIN memberships m ON u.membership_id = m.id
        $whereClause
        ORDER BY u.created_at DESC
    ");
    $stmt->execute($params);
    $referred_members = $stmt->fetchAll();
}

$pageTitle  = 'Kelola Promotor';
$activePage = 'promotors';
require __DIR__ . '/partials/header.php';
?>

<div class="mb-4 d-flex align-items-center justify-content-between flex-wrap gap-2">
  <div>
    <h5 class="mb-0 fw-bold">🚀 Manajemen Promotor</h5>
    <div style="font-size:12px;color:#666;margin-top:2px">Kelola target, rate gaji, dan pembayaran promotor program</div>
  </div>
  <?php if ($tab === 'list'): ?>
  <button class="btn btn-sm btn-primary text-white" onclick="openAddModal()" style="background:var(--brand);border-color:var(--brand)">
    + Tambah Promotor
  </button>
  <?php endif; ?>
</div>

<?php if ($flash): ?>
<div class="alert alert-<?= $flashType==='error'?'danger':'success' ?> py-2 mb-3" style="border-radius:10px;font-size:13px">
  <?= htmlspecialchars($flash) ?>
</div>
<?php endif; ?>

<!-- Tabs navigation -->
<div class="d-flex gap-2 mb-4 flex-wrap">
  <a href="?tab=list" class="btn btn-sm <?= $tab==='list'?'text-white':'btn-secondary' ?>" style="<?= $tab==='list'?'background:var(--brand)':'' ?>">
    🧑‍💼 Daftar Promotor
  </a>
  <a href="?tab=scheme" class="btn btn-sm <?= $tab==='scheme'?'text-white':'btn-secondary' ?>" style="<?= $tab==='scheme'?'background:var(--brand)':'' ?>">
    ⚙️ Skenario Komisi
  </a>
  <a href="?tab=logs" class="btn btn-sm <?= $tab==='logs'?'text-white':'btn-secondary' ?>" style="<?= $tab==='logs'?'background:var(--brand)':'' ?>">
    📜 Riwayat Target &amp; Payout
  </a>
  <a href="?tab=members" class="btn btn-sm <?= $tab==='members'?'text-white':'btn-secondary' ?>" style="<?= $tab==='members'?'background:var(--brand)':'' ?>">
    👥 Member Promotor
  </a>
</div>



<?php if ($tab === 'list'): ?>
<!-- LIST TAB -->
<div class="c-card">
  <div class="c-card-header"><span class="c-card-title">Daftar Promotor Aktif</span></div>
  <div class="c-card-body p-0">
    <div class="table-responsive">
      <table class="c-table table table-dark table-striped table-hover mb-0" style="font-size: 13.5px;">
        <thead>
          <tr>
            <th>Promotor</th>
            <th>Referral Code</th>
            <th>Target Depo</th>
            <th>Target Registrasi</th>
            <th>Rate Gaji Harian</th>
            <th class="text-end">Aksi</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($promotors)): ?>
            <tr>
              <td colspan="6" class="text-center py-4 text-muted">Belum ada promotor aktif. Silakan tambahkan.</td>
            </tr>
          <?php else: ?>
            <?php foreach ($promotors as $p): ?>
              <tr style="vertical-align: middle;">
                <td>
                  <strong style="color: #fff;"><?= htmlspecialchars($p['username']) ?></strong>
                  <div style="font-size: 11px; color: #666;"><?= htmlspecialchars($p['email']) ?></div>
                </td>
                <td><code><?= htmlspecialchars($p['referral_code']) ?></code></td>
                <td style="color: #4CAF82; font-weight: 700;"><?= format_rp((float)$p['promotor_target_deposits']) ?></td>
                <td><?= number_format((int)$p['promotor_target_regs']) ?> member</td>
                <td style="color: #FF6B35; font-weight: 700;"><?= format_rp((float)$p['promotor_salary_rate']) ?></td>
                <td class="text-end">
                  <a href="?tab=members&promotor_id=<?= $p['id'] ?>" class="btn btn-sm btn-primary text-white me-1" style="border:none;border-radius:6px;font-size:11px">
                    👥 Downlines
                  </a>
                  <button class="btn btn-sm btn-info text-white me-1" style="border:none;border-radius:6px;font-size:11px"
                          onclick="openEditModal(<?= htmlspecialchars(json_encode($p)) ?>)">
                    ✏️ Edit Target
                  </button>
                  <form method="POST" style="display:inline;" onsubmit="return confirm('Yakin ingin ' + (<?= $p['is_referral_active'] ? "'menghentikan'" : "'mengaktifkan'" ?>) + ' referral promotor ini?');">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="toggle_referral">
                    <input type="hidden" name="user_id" value="<?= $p['id'] ?>">
                    <input type="hidden" name="new_val" value="<?= $p['is_referral_active'] ? 0 : 1 ?>">
                    <button type="submit" class="btn btn-sm <?= $p['is_referral_active'] ? 'btn-warning' : 'btn-success' ?> text-white me-1" style="border:none;border-radius:6px;font-size:11px">
                      <?= $p['is_referral_active'] ? '🛑 Stop Referral' : '✅ Aktifkan Referral' ?>
                    </button>
                  </form>
                  <button class="btn btn-sm btn-danger" style="border:none;border-radius:6px;font-size:11px"
                          onclick="confirmRemove(<?= $p['id'] ?>, '<?= htmlspecialchars($p['username']) ?>')">
                    ❌ Nonaktifkan
                  </button>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php elseif ($tab === 'scheme'): ?>
<!-- SCHEME & SIMULATION TAB -->
<div class="row g-3">
  <div class="col-md-6">
    <div class="c-card">
      <div class="c-card-header"><span class="c-card-title">⚙️ Konfigurasi Skenario Komisi</span></div>
      <div class="c-card-body p-3">
        <form method="POST">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="save_global_rates">
          
          <div class="c-form-group">
            <label class="c-label">Skenario Komisi Deposit</label>
            <select name="promotor_deposit_scheme" id="cfg_scheme" class="c-form-control" onchange="runSim()">
              <?php $cur_scheme = setting($pdo, 'promotor_deposit_scheme', 'flat'); ?>
              <option value="flat" <?= $cur_scheme==='flat'?'selected':'' ?>>1️⃣ Flat Rate (Pasti per Transaksi)</option>
              <option value="percent" <?= $cur_scheme==='percent'?'selected':'' ?>>2️⃣ Persentase dari Nominal Deposit</option>
              <option value="hybrid" <?= $cur_scheme==='hybrid'?'selected':'' ?>>3️⃣ Hybrid (Flat + Persentase)</option>
            </select>
          </div>
          
          <div class="c-form-group">
            <label class="c-label">Bonus Per-Member Baru Daftar (Rp)</label>
            <input type="number" name="promotor_per_member_bonus" id="cfg_reg" class="c-form-control" value="<?= setting($pdo, 'promotor_per_member_bonus', '0') ?>" min="0" oninput="runSim()">
          </div>
          
          <div class="row g-2">
            <div class="col-md-6">
              <div class="c-form-group mb-0">
                <label class="c-label">Bonus Deposit Flat (Rp)</label>
                <input type="number" name="promotor_per_deposit_bonus" id="cfg_dep_flat" class="c-form-control" value="<?= setting($pdo, 'promotor_per_deposit_bonus', '0') ?>" min="0" oninput="runSim()">
                <small style="color:#888;font-size:11px">Dipakai jika Flat/Hybrid</small>
              </div>
            </div>
            <div class="col-md-6">
              <div class="c-form-group mb-0">
                <label class="c-label">Bonus Deposit Persen (%)</label>
                <input type="number" name="promotor_deposit_percent" id="cfg_dep_pct" class="c-form-control" value="<?= setting($pdo, 'promotor_deposit_percent', '0') ?>" min="0" max="100" step="0.1" oninput="runSim()">
                <small style="color:#888;font-size:11px">Dipakai jika Persen/Hybrid</small>
              </div>
            </div>
          </div>
          
          <button type="submit" class="btn btn-sm text-white mt-3" style="background:var(--brand)">Simpan Konfigurasi</button>
        </form>
      </div>
    </div>
  </div>
  
  <div class="col-md-6">
    <div class="c-card" style="border:2px dashed var(--brand); background:rgba(99,102,241,0.05)">
      <div class="c-card-header" style="border-bottom:1px dashed var(--brand)"><span class="c-card-title text-brand">🕹️ Kalkulator Simulasi</span></div>
      <div class="c-card-body p-3">
        <div style="font-size:12px;color:#aaa;margin-bottom:12px">Uji coba Skenario sebelum disimpan! Ubah input di bawah ini untuk melihat estimasi perhitungan gaji harian promotor.</div>
        
        <div class="row g-2 mb-3">
          <div class="col-6">
            <label class="c-label">Jumlah Member Baru Daftar</label>
            <input type="number" id="sim_reg_count" class="c-form-control" value="10" oninput="runSim()">
          </div>
          <div class="col-6">
            <label class="c-label">Berapa Kali Deposit?</label>
            <input type="number" id="sim_dep_count" class="c-form-control" value="2" oninput="runSim()">
          </div>
          <div class="col-12 mt-2">
            <label class="c-label">Total Nominal Seluruh Deposit (Rp)</label>
            <input type="number" id="sim_dep_amount" class="c-form-control" value="100000" oninput="runSim()">
          </div>
        </div>
        
        <div style="background:#11131a;border-radius:8px;padding:12px;border:1px solid #2d3149">
          <div style="font-size:11px;font-weight:700;color:#666;text-transform:uppercase;margin-bottom:8px">Hasil Simulasi (Berdasarkan Konfigurasi Kiri):</div>
          
          <div class="d-flex justify-content-between mb-1" style="font-size:13px">
            <span style="color:#aaa">Dari Pendaftaran:</span>
            <strong id="res_reg" style="color:#fff">Rp 0</strong>
          </div>
          <div class="d-flex justify-content-between mb-2" style="font-size:13px">
            <span style="color:#aaa">Dari Transaksi Deposit:</span>
            <strong id="res_dep" style="color:#fff">Rp 0</strong>
          </div>
          <div style="border-top:1px dashed #444;margin:8px 0"></div>
          <div class="d-flex justify-content-between align-items-center">
            <span style="font-size:14px;font-weight:800;color:var(--lime)">Total Gaji Promotor:</span>
            <strong id="res_total" style="font-size:18px;color:var(--lime)">Rp 0</strong>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
function formatRp(num) {
    return 'Rp ' + parseFloat(num).toLocaleString('id-ID');
}
function runSim() {
    let scheme = document.getElementById('cfg_scheme').value;
    let b_reg = parseFloat(document.getElementById('cfg_reg').value) || 0;
    let b_flat = parseFloat(document.getElementById('cfg_dep_flat').value) || 0;
    let b_pct = parseFloat(document.getElementById('cfg_dep_pct').value) || 0;
    
    let sim_regs = parseInt(document.getElementById('sim_reg_count').value) || 0;
    let sim_deps = parseInt(document.getElementById('sim_dep_count').value) || 0;
    let sim_amt = parseFloat(document.getElementById('sim_dep_amount').value) || 0;
    
    let total_reg = sim_regs * b_reg;
    let total_dep = 0;
    
    if (scheme === 'flat') {
        total_dep = sim_deps * b_flat;
    } else if (scheme === 'percent') {
        total_dep = sim_amt * (b_pct / 100);
    } else if (scheme === 'hybrid') {
        total_dep = (sim_deps * b_flat) + (sim_amt * (b_pct / 100));
    }
    
    document.getElementById('res_reg').textContent = formatRp(total_reg);
    document.getElementById('res_dep').textContent = formatRp(total_dep);
    document.getElementById('res_total').textContent = formatRp(total_reg + total_dep);
}
// Run initially
setTimeout(runSim, 100);
</script>

<?php elseif ($tab === 'logs'): ?>
<!-- LOGS TAB -->
<div class="c-card">
  <div class="c-card-header"><span class="c-card-title">Riwayat Performansi Target Harian</span></div>
  <div class="c-card-body p-3">
    <div class="table-responsive">
      <table class="c-table table table-dark table-striped table-hover mb-0" data-order='[[0, "desc"]]' style="font-size: 13px;">
        <thead>
          <tr>
            <th>Tanggal</th>
            <th>Promotor</th>
            <th>Persentase</th>
            <th>Pencapaian (Depo / Reg)</th>
            <th>Target (Depo / Reg)</th>
            <th>Gaji Harian</th>
            <th>Status Payout</th>
            <th class="text-end">Aksi</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($logs as $log): ?>
            <tr style="vertical-align: middle;">
              <td class="fw-bold" style="color: #fff;"><?= htmlspecialchars($log['date']) ?></td>
              <td><strong>@<?= htmlspecialchars($log['username']) ?></strong></td>
              <td>
                <span class="badge <?= (float)$log['percentage'] >= 100 ? 'b-success' : 'b-warn' ?>" style="font-size: 11px; padding: 4px 8px; border-radius: 6px;">
                  <?= number_format((float)$log['percentage'], 1) ?>%
                </span>
              </td>
              <td style="color:#aaa;">
                Depo: <?= format_rp((float)$log['actual_deposits']) ?> <br>
                Reg: <?= $log['actual_regs'] ?> member
              </td>
              <td style="color:#666; font-size:11px;">
                Depo: <?= format_rp((float)$log['target_deposits']) ?> <br>
                Reg: <?= $log['target_regs'] ?> member
              </td>
              <td>
                <div style="font-size:11px;color:#aaa">Rate: <?= format_rp((float)$log['salary_rate']) ?></div>
                <?php 
                $earned = (float)round(($log['salary_rate'] * min(100.0, (float)$log['percentage'])) / 100.0);
                if ($log['is_paid']): ?>
                  <div style="font-weight:700;color:#4CAF82;font-size:12.5px">Paid: <?= format_rp((float)$log['paid_amount']) ?></div>
                <?php else: ?>
                  <div style="font-weight:700;color:#FF6B35;font-size:12.5px">Earned: <?= format_rp($earned) ?></div>
                <?php endif; ?>
              </td>
              <td>
                <?php if ($log['is_paid']): ?>
                  <span class="badge b-success" style="padding: 4px 8px; border-radius: 6px;">Paid ✅</span>
                <?php elseif ($earned > 0): ?>
                  <span class="badge b-warn" style="padding: 4px 8px; border-radius: 6px; background:#FF6B35; color:#fff">Ready ⏳</span>
                <?php else: ?>
                  <span class="badge b-neutral" style="padding: 4px 8px; border-radius: 6px; background:#444; color:#aaa">0% ❌</span>
                <?php endif; ?>
              </td>
              <td class="text-end">
                <?php if (!$log['is_paid'] && $earned > 0): ?>
                  <button class="btn btn-sm btn-success text-white" style="border:none;border-radius:6px;font-size:11px;background:#4CAF82"
                          onclick="openPayoutModal(<?= $log['id'] ?>, '<?= htmlspecialchars($log['username']) ?>', '<?= date('d M Y', strtotime($log['date'])) ?>', '<?= format_rp($earned) ?>', '<?= number_format((float)$log['percentage'], 1) ?>%')">
                    💸 Bayar Gaji
                  </button>
                <?php else: ?>
                  <span style="font-size: 11px; color:#555">—</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php elseif ($tab === 'members'): ?>
<!-- MEMBERS TAB -->
<div class="c-card">
  <div class="c-card-header"><span class="c-card-title">Member Referral Promotor</span></div>
  <div class="c-card-body p-3">
    <div class="table-responsive">
      <table class="c-table table table-dark table-striped table-hover mb-0" data-order='[[4, "desc"]]' style="font-size: 13px;">
        <thead>
          <tr>
            <th>Member</th>
            <th>Promotor</th>
            <th>Level</th>
            <th>Total Deposit</th>
            <th>Waktu Daftar</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($referred_members)): ?>
            <tr>
              <td colspan="5" class="text-center py-4 text-muted">Belum ada member yang mendaftar dari referral promotor.</td>
            </tr>
          <?php else: ?>
            <?php foreach ($referred_members as $rm): ?>
              <tr style="vertical-align: middle;">
                <td>
                  <strong style="color: #fff;"><?= htmlspecialchars($rm['username']) ?></strong>
                  <div style="font-size: 11px; color: #666;"><?= htmlspecialchars($rm['email']) ?></div>
                </td>
                <td><strong style="color:var(--brand)">@<?= htmlspecialchars($rm['promotor_name']) ?></strong></td>
                <td>
                  <span class="badge b-neutral" style="font-size: 11px; padding: 4px 8px; border-radius: 6px;">
                    <?= htmlspecialchars($rm['membership_name']) ?>
                  </span>
                </td>
                <td style="color: #4CAF82; font-weight: 700;"><?= format_rp((float)$rm['total_deposit']) ?></td>
                <td style="color: #ccc; font-size: 12px;"><?= date('d M Y H:i', strtotime($rm['created_at'])) ?></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- MODALS -->

<!-- Promotor Setup Modal -->
<div class="modal fade" id="promotorModal" tabindex="-1">
  <div class="modal-dialog modal-md">
    <div class="modal-content" style="background:#1a1d27;border:1px solid #2d3149">
      <form method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_promotor">
        <div class="modal-header border-0">
          <h6 class="modal-title fw-bold" id="modal-title">Konfigurasi Promotor</h6>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="c-form-group" id="user-select-group">
            <label class="c-label">Pilih User</label>
            <select name="user_id" id="f_user_id" class="c-form-control">
              <option value="">— Pilih Pengguna —</option>
              <?php foreach ($eligible_users as $eu): ?>
                <option value="<?= $eu['id'] ?>"><?= htmlspecialchars($eu['username']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="c-form-group" id="user-display-group" style="display:none">
            <label class="c-label">User Seleksi</label>
            <input type="hidden" name="user_id" id="edit_user_id">
            <input type="text" id="edit_username" class="c-form-control" readonly style="background:#0f1117;color:#888">
          </div>
          
          <div class="c-form-group mt-3">
            <label class="c-label">Target Volume Deposit Harian (Rp)</label>
            <input type="number" name="target_deposits" id="f_target_deposits" class="c-form-control" min="0" step="any" required placeholder="Contoh: 500000">
            <span style="font-size:10px;color:#666">Reset harian. Total deposit downlines promotor hari itu.</span>
          </div>
          
          <div class="c-form-group mt-3">
            <label class="c-label">Target Registrasi Harian (Jumlah Member)</label>
            <input type="number" name="target_regs" id="f_target_regs" class="c-form-control" min="0" required placeholder="Contoh: 5">
            <span style="font-size:10px;color:#666">Reset harian. Jumlah member baru mendaftar pakai kode promotor hari itu.</span>
          </div>
          
          <div class="c-form-group mt-3">
            <label class="c-label">Gaji / Rate Harian ketika Target Tercapai (Rp)</label>
            <input type="number" name="salary_rate" id="f_salary_rate" class="c-form-control" min="0" step="any" required placeholder="Contoh: 50000">
            <span style="font-size:10px;color:#666">Akan dirilis ke balance WD promotor setelah divalidasi admin.</span>
          </div>
        </div>
        <div class="modal-footer border-0">
          <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Batal</button>
          <button type="submit" class="btn btn-sm text-white" style="background:var(--brand)">Simpan Pengaturan</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Nonaktifkan Promotor Form Modal -->
<div class="modal fade" id="removeModal" tabindex="-1">
  <div class="modal-dialog modal-sm">
    <div class="modal-content" style="background:#1a1d27;border:1px solid #2d3149">
      <form method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="remove_promotor">
        <input type="hidden" name="user_id" id="remove_user_id">
        <div class="modal-header border-0">
          <h6 class="modal-title fw-bold">Cabut Peran Promotor</h6>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <p style="font-size:13px;color:#ccc">Apakah Anda yakin ingin mencabut peran Promotor dari <strong id="remove_username" style="color:#fff"></strong>?</p>
        </div>
        <div class="modal-footer border-0">
          <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Batal</button>
          <button type="submit" class="btn btn-danger btn-sm">Cabut Peran</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Pay Salary Confirmation Modal -->
<div class="modal fade" id="payoutModal" tabindex="-1">
  <div class="modal-dialog modal-md">
    <div class="modal-content" style="background:#1a1d27;border:1px solid #2d3149">
      <form method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="pay_salary">
        <input type="hidden" name="log_id" id="pay_log_id">
        <div class="modal-header border-0">
          <h6 class="modal-title fw-bold">💸 Pencairan Gaji Promotor</h6>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <p style="font-size:13.5px;color:#ccc;line-height:1.5">
            Anda akan merilis gaji harian untuk promotor <strong id="pay_username" style="color:#fff"></strong>.<br>
            Tanggal Target: <strong id="pay_date" style="color:#fff"></strong><br>
            Pencapaian Target: <strong id="pay_pct" style="color:#FF6B35"></strong><br>
            Jumlah Pencairan Gaji: <strong id="pay_amount" style="color:#4CAF82;font-size:16px"></strong><br><br>
            <span style="font-size:11px;color:#aaa">⚠️ Setelah diklik, saldo Penarikan milik promotor akan langsung bertambah secara proporsional sesuai pencapaian target dan notifikasi Telegram akan terkirim.</span>
          </p>
        </div>
        <div class="modal-footer border-0">
          <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Batal</button>
          <button type="submit" class="btn btn-sm text-white" style="background:#4CAF82">Rilis Payout Gaji</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
function openAddModal() {
  document.getElementById('modal-title').textContent = '➕ Tambah Promotor Baru';
  document.getElementById('user-select-group').style.display = 'block';
  document.getElementById('user-display-group').style.display = 'none';
  
  // Enable dropdown and disable edit input so only the selected user_id is sent
  document.getElementById('f_user_id').disabled = false;
  document.getElementById('f_user_id').value = '';
  document.getElementById('edit_user_id').disabled = true;
  document.getElementById('edit_user_id').value = '';
  
  document.getElementById('f_target_deposits').value = '';
  document.getElementById('f_target_regs').value = '';
  document.getElementById('f_salary_rate').value = '';
  
  new bootstrap.Modal(document.getElementById('promotorModal')).show();
}

function openEditModal(p) {
  document.getElementById('modal-title').textContent = '✏️ Edit Konfigurasi Promotor: ' + p.username;
  document.getElementById('user-select-group').style.display = 'none';
  document.getElementById('user-display-group').style.display = 'block';
  
  // Disable dropdown and enable edit input so only the edit user_id is sent
  document.getElementById('f_user_id').disabled = true;
  document.getElementById('edit_user_id').disabled = false;
  document.getElementById('edit_user_id').value = p.id;
  
  document.getElementById('edit_username').value = p.username;
  document.getElementById('f_target_deposits').value = p.promotor_target_deposits;
  document.getElementById('f_target_regs').value = p.promotor_target_regs;
  document.getElementById('f_salary_rate').value = p.promotor_salary_rate;
  
  new bootstrap.Modal(document.getElementById('promotorModal')).show();
}

function confirmRemove(uid, uname) {
  document.getElementById('remove_user_id').value = uid;
  document.getElementById('remove_username').textContent = '@' + uname;
  
  new bootstrap.Modal(document.getElementById('removeModal')).show();
}

function openPayoutModal(lid, uname, date, amount, pct) {
  document.getElementById('pay_log_id').value = lid;
  document.getElementById('pay_username').textContent = '@' + uname;
  document.getElementById('pay_date').textContent = date;
  document.getElementById('pay_amount').textContent = amount;
  document.getElementById('pay_pct').textContent = pct;
  
  new bootstrap.Modal(document.getElementById('payoutModal')).show();
}
</script>

<?php require __DIR__ . '/partials/footer.php'; ?>
