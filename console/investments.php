<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
staff_require('investments');
csrf_enforce();

$flash = $flashType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $id     = (int)($_POST['id'] ?? 0);

    if ($action === 'add' || $action === 'edit') {
        $name         = clean_input($_POST['name'] ?? '');
        $price        = (float)preg_replace('/[^\d.]/', '', $_POST['price'] ?? '0');
        $roi_percent  = (float)($_POST['roi_percent'] ?? 100);
        $duration     = (int)($_POST['duration_days'] ?? 30);
        $active       = isset($_POST['is_active']) ? 1 : 0;

        if (!$name) { 
            $flash = 'Nama paket wajib diisi.'; 
            $flashType = 'error'; 
        } elseif ($price <= 0) {
            $flash = 'Harga paket harus lebih besar dari 0.';
            $flashType = 'error';
        } elseif ($roi_percent <= 0) {
            $flash = 'ROI harus lebih besar dari 0%';
            $flashType = 'error';
        } elseif ($duration <= 0) {
            $flash = 'Durasi hari harus minimal 1 hari.';
            $flashType = 'error';
        } else {
            // Calculate daily profit: (price * roi_percent / 100) / duration
            $total_return = ($price * $roi_percent) / 100;
            $daily_profit = $total_return / $duration;

            if ($action === 'add') {
                $pdo->prepare("INSERT INTO investment_packages (name, price, roi_percent, duration_days, daily_profit, is_active) VALUES (?, ?, ?, ?, ?, ?)")
                    ->execute([$name, $price, $roi_percent, $duration, $daily_profit, $active]);
                $flash = "Paket investasi '{$name}' berhasil ditambahkan.";
            } else {
                $pdo->prepare("UPDATE investment_packages SET name=?, price=?, roi_percent=?, duration_days=?, daily_profit=?, is_active=? WHERE id=?")
                    ->execute([$name, $price, $roi_percent, $duration, $daily_profit, $active, $id]);
                $flash = "Paket investasi berhasil diperbarui."; 
            }
        }
    }
    
    if ($action === 'delete' && $id) {
        // Safe delete: We check if there are users with active contracts, if so we toggle active=0 instead of hard delete, else hard delete!
        $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM user_investments WHERE package_id=? AND status='active'");
        $stmtCheck->execute([$id]);
        $has_active = (int)$stmtCheck->fetchColumn();
        if ($has_active > 0) {
            $pdo->prepare("UPDATE investment_packages SET is_active=0 WHERE id=?")->execute([$id]);
            $flash = 'Paket memiliki investasi aktif dari user. Paket dinonaktifkan dari toko (tidak dihapus permanen).';
        } else {
            $pdo->prepare("DELETE FROM investment_packages WHERE id=?")->execute([$id]);
            $flash = 'Paket investasi berhasil dihapus.';
        }
    }
}

// Fetch Data
$packages = $pdo->query("SELECT * FROM investment_packages ORDER BY price ASC, id ASC")->fetchAll();

$user_investments = $pdo->query("
    SELECT ui.*, u.username, u.email, ip.name as package_original_name
    FROM user_investments ui
    JOIN users u ON ui.user_id = u.id
    LEFT JOIN investment_packages ip ON ui.package_id = ip.id
    ORDER BY ui.created_at DESC
")->fetchAll();

$profit_logs = $pdo->query("
    SELECT pl.*, u.username, u.email, ip.name as package_name
    FROM investment_profit_logs pl
    JOIN users u ON pl.user_id = u.id
    JOIN user_investments ui ON pl.user_investment_id = ui.id
    LEFT JOIN investment_packages ip ON ui.package_id = ip.id
    ORDER BY pl.claimed_at DESC
    LIMIT 200
")->fetchAll();

$active_tab = $_GET['tab'] ?? 'packages';

$pageTitle  = 'Kelola Investasi';
$activePage = 'investments';
require __DIR__ . '/partials/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-4">
  <div>
    <h5 class="mb-0 fw-bold">📈 Kelola Investasi Ponzi</h5>
    <p class="text-muted mb-0" style="font-size:12px">Kelola produk investasi, lihat portofolio user, dan audit klaim profit.</p>
  </div>
  <div class="d-flex gap-2">
    <button class="btn btn-sm btn-success text-white px-3 py-2 fw-bold" onclick="openShareProfitModal()" style="border-radius:8px">⚙️ Run Share Profit</button>
    <?php if ($active_tab === 'packages'): ?>
    <button class="btn btn-sm text-white px-3 py-2 fw-bold" style="background:var(--brand);border-radius:8px" data-bs-toggle="modal" data-bs-target="#addPackageModal">+ Tambah Paket</button>
    <?php endif; ?>
  </div>
</div>

<?php if ($flash): ?>
<div class="alert alert-<?= $flashType==='error'?'danger':'success' ?> py-2 mb-3" style="border-radius:10px;font-size:13px"><?= htmlspecialchars($flash) ?></div>
<?php endif; ?>

<!-- Tabs -->
<div class="mb-4" style="display:flex;gap:6px;flex-wrap:wrap;padding:6px;background:rgba(255,255,255,.04);border-radius:12px;border:1px solid rgba(255,255,255,.07)">
  <a href="?tab=packages" class="btn btn-sm text-white px-3 py-2" style="font-size:12px;font-weight:700;background:<?= $active_tab==='packages'?'var(--brand)':'transparent' ?>;border:none;border-radius:8px">📦 Paket Investasi</a>
  <a href="?tab=portfolios" class="btn btn-sm text-white px-3 py-2" style="font-size:12px;font-weight:700;background:<?= $active_tab==='portfolios'?'var(--brand)':'transparent' ?>;border:none;border-radius:8px">👥 Portofolio User</a>
  <a href="?tab=logs" class="btn btn-sm text-white px-3 py-2" style="font-size:12px;font-weight:700;background:<?= $active_tab==='logs'?'var(--brand)':'transparent' ?>;border:none;border-radius:8px">📜 Log Klaim Profit</a>
</div>

<!-- Tab Content -->
<?php if ($active_tab === 'packages'): ?>
<div class="row g-3">
  <?php if (empty($packages)): ?>
  <div class="col-12">
    <div class="c-card p-4 text-center">
      <span style="font-size:36px">📭</span>
      <h6 class="fw-bold mt-2">Belum ada paket investasi</h6>
      <p class="text-muted mb-0" style="font-size:12px">Silakan tambahkan paket investasi baru melalui tombol di kanan atas.</p>
    </div>
  </div>
  <?php endif; ?>
  
  <?php foreach ($packages as $p): 
      // Count total active investment instances for stats
      $stmtActive = $pdo->prepare("SELECT COUNT(*) FROM user_investments WHERE package_id=? AND status='active'");
      $stmtActive->execute([$p['id']]);
      $count_active = (int)$stmtActive->fetchColumn();
      
      $stmtInvested = $pdo->prepare("SELECT SUM(amount) FROM user_investments WHERE package_id=?");
      $stmtInvested->execute([$p['id']]);
      $total_invested = (float)$stmtInvested->fetchColumn();
  ?>
  <div class="col-md-6 col-xl-4">
    <div class="c-card h-100">
      <div class="c-card-body d-flex flex-column justify-content-between">
        <div>
          <div class="d-flex align-items-center justify-content-between mb-3">
            <span class="badge <?= $p['is_active'] ? 'b-success' : 'b-danger' ?>"><?= $p['is_active'] ? 'Aktif' : 'Nonaktif' ?></span>
            <div class="d-flex gap-1">
              <button class="btn btn-sm b-neutral" style="border:none;border-radius:8px;font-size:11px" onclick='editPackage(<?= json_encode($p) ?>)'>✏️ Edit</button>
              <form method="POST" class="d-inline" onsubmit="return confirm('Hapus/nonaktifkan paket ini?')">
                <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $p['id'] ?>">
                <button class="btn btn-sm b-danger" style="border:none;border-radius:8px;font-size:11px">🗑</button>
              </form>
            </div>
          </div>
          <h5 class="fw-bold mb-2 text-white"><?= htmlspecialchars($p['name']) ?></h5>
          <h3 class="fw-extrabold mb-1" style="color:var(--brand)"><?= format_rp((float)$p['price']) ?></h3>
          <div class="text-muted mb-3" style="font-size:12px">Kontrak: <strong><?= $p['duration_days'] ?> Hari</strong></div>
          
          <div class="p-3 mb-3" style="background:rgba(255,255,255,.02);border-radius:8px;border:1px dashed #2d3149;font-size:13px">
            <div class="d-flex justify-content-between mb-1">
              <span class="text-muted">Total ROI</span>
              <span class="fw-bold text-white"><?= (float)$p['roi_percent'] ?>%</span>
            </div>
            <div class="d-flex justify-content-between mb-1">
              <span class="text-muted">Profit Harian</span>
              <span class="fw-bold text-success">+<?= format_rp((float)$p['daily_profit']) ?>/hari</span>
            </div>
            <div class="d-flex justify-content-between">
              <span class="text-muted">Total Pengembalian</span>
              <span class="fw-bold text-info"><?= format_rp((float)$p['daily_profit'] * $p['duration_days']) ?></span>
            </div>
          </div>
        </div>
        
        <div style="font-size:11px;color:#666;border-top:1px solid #1f2235;padding-top:10px">
          <div>🔥 Sedang Berjalan: <strong class="text-white"><?= $count_active ?> portofolio</strong></div>
          <div>💰 Total Omset Terkumpul: <strong class="text-white"><?= format_rp($total_invested) ?></strong></div>
        </div>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<?php elseif ($active_tab === 'portfolios'): ?>
<div class="c-card">
  <div class="c-card-header"><span class="c-card-title">👥 Daftar Portofolio Pengguna</span></div>
  <div class="c-card-body p-0">
    <div class="table-responsive">
      <table class="c-table">
        <thead>
          <tr>
            <th>User</th>
            <th>Paket</th>
            <th>Jumlah Investasi</th>
            <th>Profit Harian</th>
            <th>Progress Kontrak</th>
            <th>Total Diterima</th>
            <th>Status</th>
            <th>Mulai Pada</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($user_investments)): ?>
          <tr>
            <td colspan="8" class="text-center py-4 text-muted">Belum ada portofolio investasi terdaftar.</td>
          </tr>
          <?php endif; ?>
          <?php foreach ($user_investments as $ui): ?>
          <tr>
            <td>
              <strong class="text-white"><?= htmlspecialchars($ui['username']) ?></strong>
              <div style="font-size:10px;color:#666"><?= htmlspecialchars($ui['email']) ?></div>
            </td>
            <td><?= htmlspecialchars($ui['package_original_name'] ?: 'Paket Custom/Dihapus') ?></td>
            <td class="fw-bold text-white"><?= format_rp((float)$ui['amount']) ?></td>
            <td class="text-success">+<?= format_rp((float)$ui['daily_profit']) ?>/hr</td>
            <td>
              <div class="d-flex align-items-center gap-2">
                <span class="fw-bold"><?= $ui['days_passed'] ?> / <?= $ui['duration_days'] ?> Hari</span>
                <div class="progress" style="height:6px;width:60px;background:#0f1117">
                  <div class="progress-bar bg-success" style="width:<?= ($ui['days_passed'] / $ui['duration_days']) * 100 ?>%"></div>
                </div>
              </div>
            </td>
            <td class="fw-bold text-info"><?= format_rp($ui['days_passed'] * (float)$ui['daily_profit']) ?></td>
            <td>
              <span class="badge <?= $ui['status'] === 'active' ? 'b-success' : 'b-neutral' ?>">
                <?= $ui['status'] === 'active' ? 'Aktif' : 'Selesai' ?>
              </span>
            </td>
            <td style="font-size:11px"><?= date('d/m/Y H:i', strtotime($ui['created_at'])) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php elseif ($active_tab === 'logs'): ?>
<div class="c-card">
  <div class="c-card-header"><span class="c-card-title">📜 Log Riwayat Klaim Keuntungan</span></div>
  <div class="c-card-body p-0">
    <div class="table-responsive">
      <table class="c-table">
        <thead>
          <tr>
            <th>User</th>
            <th>Produk</th>
            <th>Keuntungan Diklaim</th>
            <th>Siklus Diklaim</th>
            <th>Waktu Klaim</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($profit_logs)): ?>
          <tr>
            <td colspan="5" class="text-center py-4 text-muted">Belum ada riwayat klaim keuntungan.</td>
          </tr>
          <?php endif; ?>
          <?php foreach ($profit_logs as $log): ?>
          <tr>
            <td>
              <strong class="text-white"><?= htmlspecialchars($log['username']) ?></strong>
              <div style="font-size:10px;color:#666"><?= htmlspecialchars($log['email']) ?></div>
            </td>
            <td><?= htmlspecialchars($log['package_name'] ?: 'Paket Dihapus') ?></td>
            <td class="fw-bold text-success"><?= format_rp((float)$log['amount']) ?></td>
            <td><span class="badge b-warn"><?= $log['days_claimed'] ?> Hari</span></td>
            <td><?= date('d M Y H:i:s', strtotime($log['claimed_at'])) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- Add Package Modal -->
<div class="modal fade" id="addPackageModal" tabindex="-1">
  <div class="modal-dialog"><div class="modal-content" style="background:#1a1d27;border:1px solid #2d3149">
    <form method="POST" id="add-pkg-form">
    <?= csrf_field() ?><input type="hidden" name="action" value="add">
    <div class="modal-header border-0">
      <h5 class="modal-title fw-bold text-white">+ Tambah Paket Investasi</h5>
      <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
    </div>
    <div class="modal-body">
      <div class="c-form-group mb-3">
        <label class="c-label">Nama Paket</label>
        <input type="text" name="name" class="c-form-control" placeholder="Contoh: Paket Investasi Pemula" required>
      </div>
      <div class="row g-2 mb-3">
        <div class="col-6">
          <label class="c-label">Harga Paket (Rp)</label>
          <input type="number" name="price" id="add-price" class="c-form-control" placeholder="50000" min="1000" required>
        </div>
        <div class="col-6">
          <label class="c-label">ROI (%)</label>
          <input type="number" name="roi_percent" id="add-roi" class="c-form-control" placeholder="120" min="100" max="1000" required>
        </div>
      </div>
      <div class="row g-2 mb-3">
        <div class="col-6">
          <label class="c-label">Durasi Kontrak (Hari)</label>
          <input type="number" name="duration_days" id="add-days" class="c-form-control" placeholder="10" min="1" required>
        </div>
        <div class="col-6 d-flex align-items-end">
          <div class="form-check ms-1 mb-2">
            <input class="form-check-input" type="checkbox" name="is_active" id="add-active" value="1" checked>
            <label class="form-check-label text-secondary" for="add-active" style="font-size:13px">Langsung Aktif</label>
          </div>
        </div>
      </div>
      
      <!-- Calculator Display -->
      <div class="p-3" style="background:rgba(255,107,53,.05);border-radius:8px;border:1.5px solid var(--brand);font-size:12px">
        <div class="fw-bold mb-2 text-white">🧮 Estimasi Kalkulator Yield:</div>
        <div class="d-flex justify-content-between mb-1">
          <span>Profit Harian:</span>
          <span class="fw-bold text-success" id="add-calc-daily">Rp 0/hari</span>
        </div>
        <div class="d-flex justify-content-between mb-1">
          <span>Total Pengembalian:</span>
          <span class="fw-bold text-white" id="add-calc-total">Rp 0</span>
        </div>
        <div class="d-flex justify-content-between">
          <span>Laba Bersih:</span>
          <span class="fw-bold text-info" id="add-calc-net">Rp 0</span>
        </div>
      </div>
    </div>
    <div class="modal-footer border-0">
      <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Batal</button>
      <button type="submit" class="btn btn-sm text-white" style="background:var(--brand)">Simpan Paket</button>
    </div>
    </form>
  </div></div>
</div>

<!-- Edit Package Modal -->
<div class="modal fade" id="editPackageModal" tabindex="-1">
  <div class="modal-dialog"><div class="modal-content" style="background:#1a1d27;border:1px solid #2d3149">
    <form method="POST" id="edit-pkg-form">
    <?= csrf_field() ?><input type="hidden" name="action" value="edit"><input type="hidden" name="id" id="ep-id">
    <div class="modal-header border-0">
      <h5 class="modal-title fw-bold text-white">✏️ Edit Paket Investasi</h5>
      <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
    </div>
    <div class="modal-body">
      <div class="c-form-group mb-3">
        <label class="c-label">Nama Paket</label>
        <input type="text" name="name" id="ep-name" class="c-form-control" required>
      </div>
      <div class="row g-2 mb-3">
        <div class="col-6">
          <label class="c-label">Harga Paket (Rp)</label>
          <input type="number" name="price" id="ep-price" class="c-form-control" min="1000" required>
        </div>
        <div class="col-6">
          <label class="c-label">ROI (%)</label>
          <input type="number" name="roi_percent" id="ep-roi" class="c-form-control" min="100" max="1000" required>
        </div>
      </div>
      <div class="row g-2 mb-3">
        <div class="col-6">
          <label class="c-label">Durasi Kontrak (Hari)</label>
          <input type="number" name="duration_days" id="ep-days" class="c-form-control" min="1" required>
        </div>
        <div class="col-6 d-flex align-items-end">
          <div class="form-check ms-1 mb-2">
            <input class="form-check-input" type="checkbox" name="is_active" id="ep-active" value="1">
            <label class="form-check-label text-secondary" for="ep-active" style="font-size:13px">Paket Aktif</label>
          </div>
        </div>
      </div>
      
      <!-- Calculator Display -->
      <div class="p-3" style="background:rgba(255,107,53,.05);border-radius:8px;border:1.5px solid var(--brand);font-size:12px">
        <div class="fw-bold mb-2 text-white">🧮 Estimasi Kalkulator Yield:</div>
        <div class="d-flex justify-content-between mb-1">
          <span>Profit Harian:</span>
          <span class="fw-bold text-success" id="ep-calc-daily">Rp 0/hari</span>
        </div>
        <div class="d-flex justify-content-between mb-1">
          <span>Total Pengembalian:</span>
          <span class="fw-bold text-white" id="ep-calc-total">Rp 0</span>
        </div>
        <div class="d-flex justify-content-between">
          <span>Laba Bersih:</span>
          <span class="fw-bold text-info" id="ep-calc-net">Rp 0</span>
        </div>
      </div>
    </div>
    <div class="modal-footer border-0">
      <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Batal</button>
      <button type="submit" class="btn btn-sm text-white" style="background:var(--brand)">Update Paket</button>
    </div>
    </form>
  </div></div>
</div>

<!-- Share Profit Modal -->
<div class="modal fade" id="shareProfitModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content" style="background:#1a1d27;border:2px solid var(--brand);box-shadow: 0 0 15px rgba(255, 107, 53, 0.25);">
      <div class="modal-header border-0 pb-0">
        <h5 class="modal-title fw-bold text-white">⚙️ Run Share Profit (Bagi Hasil)</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" id="share-profit-close-btn"></button>
      </div>
      <div class="modal-body pt-3">
        <!-- Step 1: Mode Selection -->
        <div id="sp-step-select">
          <p class="text-secondary" style="font-size:13px">Sistem akan membagikan profit harian (+1 hari siklus) secara merata ke seluruh kontrak investasi pengguna yang saat ini masih berstatus <strong class="text-white">Aktif</strong>.</p>
          
          <div class="p-3 mb-4" style="background:rgba(255,255,255,0.02);border:1px solid #2d3149;border-radius:8px">
            <span class="text-muted" style="font-size:11px;text-transform:uppercase;font-weight:700">📊 Ringkasan Saat Ini</span>
            <div class="row g-2 mt-1">
              <div class="col-6">
                <div class="p-2 text-center" style="background:#0f1117;border-radius:6px">
                  <div style="font-size:11px;color:#666">Aktif Portofolio</div>
                  <div class="fw-bold text-white" id="sp-count-active" style="font-size:18px">-</div>
                </div>
              </div>
              <div class="col-6">
                <div class="p-2 text-center" style="background:#0f1117;border-radius:6px">
                  <div style="font-size:11px;color:#666">Estimasi Total Profit/Hari</div>
                  <div class="fw-bold text-success" id="sp-est-profit" style="font-size:18px">-</div>
                </div>
              </div>
            </div>
          </div>
          
          <div class="row g-3">
            <!-- Option 1: Animated Run -->
            <div class="col-md-6">
              <div class="p-3 h-100 d-flex flex-column justify-content-between text-start" style="background:rgba(255,107,53,0.05);border:2px solid var(--brand);border-radius:10px;cursor:pointer;transition:transform 0.2s" onclick="startShareProfit('animated')">
                <div>
                  <h6 class="fw-bold text-white mb-2">🚀 Animated Run</h6>
                  <p class="text-secondary mb-0" style="font-size:12px">Menampilkan visual pembagian keuntungan real-time per user secara berurutan dengan animasi premium yang memukau!</p>
                </div>
                <div class="mt-3 text-end">
                  <span class="btn btn-sm text-white" style="background:var(--brand);font-size:11px;font-weight:700">Mulai Animasi →</span>
                </div>
              </div>
            </div>
            <!-- Option 2: Background Run -->
            <div class="col-md-6">
              <div class="p-3 h-100 d-flex flex-column justify-content-between text-start" style="background:rgba(25,135,84,0.05);border:2px solid #198754;border-radius:10px;cursor:pointer;transition:transform 0.2s" onclick="startShareProfit('background')">
                <div>
                  <h6 class="fw-bold text-white mb-2">⚡ Background Run</h6>
                  <p class="text-secondary mb-0" style="font-size:12px">Memproses seluruh pembagian profit secara instan di latar belakang server. Sangat cepat dan cocok untuk data berskala besar.</p>
                </div>
                <div class="mt-3 text-end">
                  <span class="btn btn-sm text-white btn-success" style="font-size:11px;font-weight:700">Eksekusi Instan →</span>
                </div>
              </div>
            </div>
          </div>
        </div>
        
        <!-- Step 2: Processing (Animated) -->
        <div id="sp-step-processing" style="display:none">
          <div class="text-center mb-3">
            <h6 class="fw-bold text-white" id="sp-process-title">Sedang mendistribusikan profit harian...</h6>
            <div class="progress mt-2" style="height:12px;background:#0f1117;border-radius:6px;border:1px solid #2d3149">
              <div class="progress-bar progress-bar-striped progress-bar-animated bg-warning" id="sp-progress-bar" role="progressbar" style="width: 0%"></div>
            </div>
            <div class="d-flex justify-content-between mt-1" style="font-size:11px;color:#888">
              <span id="sp-progress-text">Memproses: 0 / 0</span>
              <span id="sp-progress-percent">0%</span>
            </div>
          </div>
          
          <!-- Animated terminal log console -->
          <div class="p-3" style="background:#090a0f;border:1px solid #1f2235;border-radius:8px;font-family:monospace;font-size:11px;max-height:220px;overflow-y:auto;text-align:left;color:#a0a0c0" id="sp-terminal-log">
            <!-- logs will appear here -->
          </div>
        </div>
        
        <!-- Step 3: Success Summary -->
        <div id="sp-step-success" style="display:none" class="text-center py-3">
          <span style="font-size:48px">🎉</span>
          <h4 class="fw-bold text-white mt-3 mb-2">Proses Selesai!</h4>
          <p class="text-secondary mb-4" id="sp-success-desc" style="font-size:13px">Semua profit berhasil dibagikan.</p>
          <button type="button" class="btn btn-sm btn-success px-4" data-bs-dismiss="modal" onclick="window.location.reload()">Selesai & Muat Ulang Halaman</button>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
// JS dynamic ROI calculator helper
function calculateYield(priceId, roiId, daysId, dailyTextId, totalTextId, netTextId) {
  const price = parseFloat(document.getElementById(priceId).value) || 0;
  const roi = parseFloat(document.getElementById(roiId).value) || 0;
  const days = parseInt(document.getElementById(daysId).value) || 0;

  if (price > 0 && roi > 0 && days > 0) {
    const totalReturn = (price * roi) / 100;
    const dailyProfit = totalReturn / days;
    const netProfit = totalReturn - price;

    document.getElementById(dailyTextId).textContent = 'Rp ' + Math.round(dailyProfit).toLocaleString('id-ID') + '/hari';
    document.getElementById(totalTextId).textContent = 'Rp ' + Math.round(totalReturn).toLocaleString('id-ID');
    document.getElementById(netTextId).textContent = 'Rp ' + Math.round(netProfit).toLocaleString('id-ID');
  } else {
    document.getElementById(dailyTextId).textContent = 'Rp 0/hari';
    document.getElementById(totalTextId).textContent = 'Rp 0';
    document.getElementById(netTextId).textContent = 'Rp 0';
  }
}

// Attach listeners for Add Form
['add-price', 'add-roi', 'add-days'].forEach(id => {
  const el = document.getElementById(id);
  if (el) {
    el.addEventListener('input', () => {
      calculateYield('add-price', 'add-roi', 'add-days', 'add-calc-daily', 'add-calc-total', 'add-calc-net');
    });
  }
});

// Attach listeners for Edit Form
['ep-price', 'ep-roi', 'ep-days'].forEach(id => {
  const el = document.getElementById(id);
  if (el) {
    el.addEventListener('input', () => {
      calculateYield('ep-price', 'ep-roi', 'ep-days', 'ep-calc-daily', 'ep-calc-total', 'ep-calc-net');
    });
  }
});

function editPackage(p) {
  document.getElementById('ep-id').value = p.id;
  document.getElementById('ep-name').value = p.name;
  document.getElementById('ep-price').value = Math.round(p.price);
  document.getElementById('ep-roi').value = Math.round(p.roi_percent);
  document.getElementById('ep-days').value = p.duration_days;
  document.getElementById('ep-active').checked = p.is_active == 1;

  // Run calculation immediately
  calculateYield('ep-price', 'ep-roi', 'ep-days', 'ep-calc-daily', 'ep-calc-total', 'ep-calc-net');

  new bootstrap.Modal(document.getElementById('editPackageModal')).show();
}

// Share Profit Functions
let activeInvestments = [];
let csrfToken = '<?= csrf_token() ?>';

function openShareProfitModal() {
  const modal = new bootstrap.Modal(document.getElementById('shareProfitModal'));
  
  // Reset UI steps
  document.getElementById('sp-step-select').style.display = 'block';
  document.getElementById('sp-step-processing').style.display = 'none';
  document.getElementById('sp-step-success').style.display = 'none';
  document.getElementById('share-profit-close-btn').style.display = 'block';
  document.getElementById('sp-terminal-log').innerHTML = '';
  document.getElementById('sp-progress-bar').style.width = '0%';
  document.getElementById('sp-progress-bar').className = 'progress-bar progress-bar-striped progress-bar-animated bg-warning';
  
  // Show Modal
  modal.show();
  
  // Fetch active summary
  const fd = new FormData();
  fd.append('action', 'get_active');
  fd.append('_csrf', csrfToken);
  
  fetch('/console/ajax_share_profit.php', {
    method: 'POST',
    body: fd
  })
  .then(r => r.json())
  .then(res => {
    if (res.status === 'success') {
      activeInvestments = res.data;
      document.getElementById('sp-count-active').textContent = activeInvestments.length + ' Kontrak';
      
      let estProfit = 0;
      activeInvestments.forEach(item => {
        estProfit += parseFloat(item.daily_profit);
      });
      document.getElementById('sp-est-profit').textContent = 'Rp ' + Math.round(estProfit).toLocaleString('id-ID');
    } else {
      alert('Gagal memuat data aktif investasi: ' + res.message);
    }
  })
  .catch(err => {
    console.error(err);
    alert('Error fetching active investments data.');
  });
}

function appendLog(html, color = '#a0a0c0') {
  const logEl = document.getElementById('sp-terminal-log');
  const div = document.createElement('div');
  div.style.color = color;
  div.style.marginBottom = '4px';
  div.innerHTML = html;
  logEl.appendChild(div);
  logEl.scrollTop = logEl.scrollHeight;
}

function startShareProfit(mode) {
  if (activeInvestments.length === 0) {
    alert('Tidak ada kontrak investasi aktif untuk diproses.');
    return;
  }
  
  // Switch to processing step
  document.getElementById('sp-step-select').style.display = 'none';
  document.getElementById('sp-step-processing').style.display = 'block';
  document.getElementById('share-profit-close-btn').style.display = 'none'; // Lock closing
  
  if (mode === 'background') {
    // Run background batch
    appendLog('⚡ Memulai pemrosesan background batch...');
    appendLog('🔄 Mengirim data ke server...');
    
    const fd = new FormData();
    fd.append('action', 'process_all_background');
    fd.append('_csrf', csrfToken);
    
    fetch('/console/ajax_share_profit.php', {
      method: 'POST',
      body: fd
    })
    .then(r => r.json())
    .then(res => {
      if (res.status === 'success') {
        appendLog('✅ Sukses memproses batch di background!', '#198754');
        appendLog(`📊 Total kontrak diproses: ${res.data.total_processed}`);
        appendLog(`💰 Total profit dibagikan: Rp ${Math.round(res.data.total_profit).toLocaleString('id-ID')}`);
        
        setTimeout(() => {
          document.getElementById('sp-step-processing').style.display = 'none';
          document.getElementById('sp-step-success').style.display = 'block';
          document.getElementById('sp-success-desc').innerHTML = `Berhasil memproses <strong>${res.data.total_processed}</strong> kontrak investasi dengan total profit dibagikan sebesar <strong>Rp ${Math.round(res.data.total_profit).toLocaleString('id-ID')}</strong>.`;
        }, 1200);
      } else {
        appendLog('❌ Error: ' + res.message, '#dc3545');
        document.getElementById('share-profit-close-btn').style.display = 'block'; // Unlock closing
      }
    })
    .catch(err => {
      appendLog('❌ Network Error.', '#dc3545');
      document.getElementById('share-profit-close-btn').style.display = 'block';
    });
  } else {
    // Animated sequential run
    appendLog('🚀 Memulai pembagian profit sekuensial (Animated)...');
    runSequentialAnimation(0, 0);
  }
}

function runSequentialAnimation(index, totalProfitShared) {
  const total = activeInvestments.length;
  if (index >= total) {
    appendLog('🎉 Semua kontrak aktif berhasil diproses!', '#198754');
    appendLog(`📊 Total Kontrak Selesai: ${total}`);
    appendLog(`💰 Total Profit Dibagikan: Rp ${Math.round(totalProfitShared).toLocaleString('id-ID')}`);
    
    document.getElementById('sp-progress-bar').className = 'progress-bar bg-success';
    
    setTimeout(() => {
      document.getElementById('sp-step-processing').style.display = 'none';
      document.getElementById('sp-step-success').style.display = 'block';
      document.getElementById('sp-success-desc').innerHTML = `Berhasil membagikan profit ke <strong>${total}</strong> kontrak investasi aktif dengan total nilai <strong>Rp ${Math.round(totalProfitShared).toLocaleString('id-ID')}</strong>!`;
    }, 1500);
    return;
  }
  
  const item = activeInvestments[index];
  const percent = Math.round(((index + 1) / total) * 100);
  
  appendLog(`🔄 [${index + 1}/${total}] Memproses portfolio user <strong>@${item.username}</strong> (${item.package_name})...`);
  
  const fd = new FormData();
  fd.append('action', 'process_single');
  fd.append('id', item.id);
  fd.append('_csrf', csrfToken);
  
  fetch('/console/ajax_share_profit.php', {
    method: 'POST',
    body: fd
  })
  .then(r => r.json())
  .then(res => {
    if (res.status === 'success') {
      const pft = parseFloat(item.daily_profit);
      appendLog(`💸 Sukses: @${item.username} menerima profit harian <strong>+Rp ${Math.round(pft).toLocaleString('id-ID')}</strong> (Hari ${res.detail.new_days_passed}/${item.duration_days})`, '#198754');
      
      // Update progress bar
      document.getElementById('sp-progress-bar').style.width = percent + '%';
      document.getElementById('sp-progress-text').textContent = `Memproses: ${index + 1} / ${total}`;
      document.getElementById('sp-progress-percent').textContent = percent + '%';
      
      // Continue to next with delay for animation effect
      setTimeout(() => {
        runSequentialAnimation(index + 1, totalProfitShared + pft);
      }, 500); // 500ms delay per user for aesthetic animation
    } else {
      appendLog(`❌ Gagal memproses @${item.username}: ${res.message}`, '#dc3545');
      // Continue anyway
      setTimeout(() => {
        runSequentialAnimation(index + 1, totalProfitShared);
      }, 500);
    }
  })
  .catch(err => {
    appendLog(`❌ Network Error pada portfolio @${item.username}`, '#dc3545');
    setTimeout(() => {
      runSequentialAnimation(index + 1, totalProfitShared);
    }, 500);
  });
}
</script>

<?php require __DIR__ . '/partials/footer.php'; ?>
