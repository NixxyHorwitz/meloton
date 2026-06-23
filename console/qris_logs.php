<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
staff_require('deposits');
csrf_enforce();

$flash = $flashType = '';

// Handle Clear/Truncate Logs Action
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'clear_qris_logs') {
        try {
            $pdo->query("TRUNCATE TABLE payment_gateway_logs");
            $flash = "Seluruh log payment gateway berhasil dibersihkan!";
        } catch (\Throwable $e) {
            $flash = "Gagal membersihkan log: " . $e->getMessage();
            $flashType = 'error';
        }
    }
}

// Fetch logs along with associated deposit and user username
$logs = $pdo->query("
    SELECT pgl.*, d.amount as depo_amount, d.user_id, u.username 
    FROM payment_gateway_logs pgl 
    LEFT JOIN deposits d ON d.id = pgl.deposit_id 
    LEFT JOIN users u ON u.id = d.user_id 
    ORDER BY pgl.created_at DESC
")->fetchAll();

$pageTitle  = 'Log Payment QRIS';
$activePage = 'qris_logs';
require __DIR__ . '/partials/header.php';
?>

<div class="mb-4 d-flex align-items-center justify-content-between flex-wrap gap-2">
  <div>
    <h5 class="mb-0 fw-bold">🤖 Log Payment Gateway QRIS</h5>
    <div style="font-size:12px;color:#666;margin-top:2px">Audit pencocokan otomatis transaksi QRIS dengan deposit pending user</div>
  </div>
  <?php if (!empty($logs)): ?>
  <form method="POST" onsubmit="return confirm('Apakah Anda yakin ingin menghapus seluruh log payment gateway?')">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="clear_qris_logs">
    <button type="submit" class="btn btn-sm btn-danger">🗑️ Bersihkan Semua Log</button>
  </form>
  <?php endif; ?>
</div>

<?php if ($flash): ?>
<div class="alert alert-<?= $flashType==='error'?'danger':'success' ?> py-2 mb-3" style="border-radius:10px;font-size:13px">
  <?= htmlspecialchars($flash) ?>
</div>
<?php endif; ?>

<div class="c-card">
  <div class="c-card-header"><span class="c-card-title">Riwayat Log Callback Gateway</span></div>
  <div class="c-card-body p-3">
    <div class="table-responsive">
      <table class="c-table table table-dark table-striped table-hover mb-0" data-order='[[0, "desc"]]' style="font-size: 13px;">
        <thead>
          <tr>
            <th>ID Log</th>
            <th>Tanggal Masuk</th>
            <th>Nominal Terdeteksi</th>
            <th>Status Pencocokan</th>
            <th>Deposit Terhubung</th>
            <th class="text-end">Payload</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($logs as $log): ?>
            <tr style="vertical-align: middle;">
              <td><code>#<?= $log['id'] ?></code></td>
              <td style="color:#aaa;"><?= htmlspecialchars($log['created_at']) ?></td>
              <td style="font-weight: 700; color: #fff;"><?= format_rp((float)$log['extracted_amount']) ?></td>
              <td>
                <?php if ($log['status'] === 'matched'): ?>
                  <span class="badge b-success" style="padding: 4px 8px; border-radius: 6px;">Matched ✅</span>
                <?php elseif ($log['status'] === 'unmatched'): ?>
                  <span class="badge b-warn" style="padding: 4px 8px; border-radius: 6px; background:#FF6B35; color:#fff">Unmatched ⏳</span>
                <?php elseif ($log['status'] === 'disabled'): ?>
                  <span class="badge b-neutral" style="padding: 4px 8px; border-radius: 6px; background:#444; color:#aaa">Disabled ❌</span>
                <?php else: ?>
                  <span class="badge b-neutral" style="padding: 4px 8px; border-radius: 6px; background:#ef4444; color:#fff">Failed ⚠️</span>
                <?php endif; ?>
              </td>
              <td>
                <?php if ($log['deposit_id'] && $log['user_id']): ?>
                  <a href="/console/user_txns?uid=<?= $log['user_id'] ?>" class="text-info" style="text-decoration:none;font-weight:700">
                    👤 @<?= htmlspecialchars($log['username']) ?> (Depo #<?= $log['deposit_id'] ?>)
                  </a>
                <?php else: ?>
                  <span style="color:#555">—</span>
                <?php endif; ?>
              </td>
              <td class="text-end">
                <button class="btn btn-sm btn-info text-white" style="border:none;border-radius:6px;font-size:11px"
                        onclick="showPayloadModal(<?= htmlspecialchars(json_encode($log['payload'])) ?>)">
                  🔍 Lihat Payload
                </button>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Payload Raw View Modal -->
<div class="modal fade" id="payloadModal" tabindex="-1">
  <div class="modal-dialog modal-md modal-dialog-centered">
    <div class="modal-content" style="background:#1a1d27;border:1px solid #2d3149">
      <div class="modal-header border-0">
        <h6 class="modal-title fw-bold">📋 Raw Payload Gateway</h6>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <pre id="raw-payload-content" style="background:#0f1117;border:1px solid #1f2235;color:#9cdcfe;font-family:monospace;font-size:12px;padding:12px;border-radius:8px;max-height:350px;overflow-y:auto;white-space:pre-wrap;word-break:break-all;"></pre>
      </div>
      <div class="modal-footer border-0">
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Tutup</button>
      </div>
    </div>
  </div>
</div>

<script>
function showPayloadModal(rawPayload) {
  try {
    const parsed = JSON.parse(rawPayload);
    document.getElementById('raw-payload-content').textContent = JSON.stringify(parsed, null, 2);
  } catch (e) {
    document.getElementById('raw-payload-content').textContent = rawPayload;
  }
  new bootstrap.Modal(document.getElementById('payloadModal')).show();
}
</script>

<?php require __DIR__ . '/partials/footer.php'; ?>
