<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
staff_require('transaction_flow');
csrf_enforce();

$flash = '';
$flashType = '';
$pct = (float)setting($pdo, 'referral_commission_percent', '5');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $ids = isset($_POST['ids']) ? explode(',', $_POST['ids']) : [];
    $new_amount = (float)($_POST['new_amount'] ?? 0);
    
    $ids = array_filter(array_map('intval', $ids));

    if (!empty($ids) && in_array($action, ['delete', 'bulk_delete', 'edit', 'bulk_edit'])) {
        $pdo->beginTransaction();
        try {
            $success_count = 0;
            foreach ($ids as $id) {
                $stmtDep = $pdo->prepare("SELECT d.id, d.amount, d.user_id, u.referred_by FROM deposits d JOIN users u ON u.id = d.user_id WHERE d.id = ? AND d.status = 'confirmed'");
                $stmtDep->execute([$id]);
                $dep = $stmtDep->fetch();
                
                if (!$dep) continue;
                
                $old_amount = (float)$dep['amount'];
                $depositor_id = $dep['user_id'];
                
                $ref = $pdo->prepare("SELECT id, is_promotor FROM users WHERE referral_code = ? LIMIT 1");
                $ref->execute([$dep['referred_by']]);
                $upline = $ref->fetch();
                
                if ($action === 'delete' || $action === 'bulk_delete') {
                    $pdo->prepare("DELETE FROM deposits WHERE id = ?")->execute([$id]);
                    $success_count++;
                } 
                elseif (($action === 'edit' || $action === 'bulk_edit') && $new_amount > 0 && $new_amount != $old_amount) {
                    $pdo->prepare("UPDATE deposits SET amount = ? WHERE id = ?")->execute([$new_amount, $id]);
                    $success_count++;
                }
            }
            $pdo->commit();
            $flash = "✅ Berhasil memproses {$success_count} transaksi.";
            $flashType = 'success';
        } catch (\Throwable $e) {
            $pdo->rollBack();
            $flash = "❌ Error: " . $e->getMessage();
            $flashType = 'error';
        }
    }
}

// Pagination
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 50;
$offset = ($page - 1) * $limit;

// Fetch confirmed deposits that have an upline
$pct = (float)setting($pdo, 'referral_commission_percent', '5');
$sql = "
    SELECT 
        d.id AS deposit_id, d.amount, d.confirmed_at,
        u1.id AS depositor_id, u1.username AS depositor_name, u1.email AS depositor_email,
        u2.id AS upline_id, u2.username AS upline_name, u2.is_promotor,
        IF(u2.is_promotor = 1, 0, ROUND((d.amount * {$pct}) / 100, 2)) AS commission_amount
    FROM deposits d
    JOIN users u1 ON u1.id = d.user_id
    JOIN users u2 ON u2.referral_code = u1.referred_by
    WHERE d.status = 'confirmed'
    ORDER BY d.confirmed_at DESC
    LIMIT $limit OFFSET $offset
";
$rows = $pdo->query($sql)->fetchAll();

// Total count for pagination
$total = $pdo->query("
    SELECT COUNT(d.id)
    FROM deposits d
    JOIN users u1 ON u1.id = d.user_id
    JOIN users u2 ON u2.referral_code = u1.referred_by
    WHERE d.status = 'confirmed'
")->fetchColumn();
$totalPages = ceil($total / $limit);

$pageTitle  = 'Transaction Flow';
$activePage = 'transaction_flow';
require __DIR__ . '/partials/header.php';
?>

<style>
.tf-container { display: flex; flex-direction: column; gap: 12px; margin-top: 16px; }
.tf-group {
    display: flex; align-items: stretch;
    background: #1a1d27; border: 1px solid #2d3149;
    border-radius: 12px; padding: 12px;
}
.tf-depositors {
    display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 8px; flex: 1;
    border-right: 2px solid #363b57; padding-right: 16px;
    align-content: start; position: relative;
}
.tf-upline-wrapper {
    display: flex; align-items: center; justify-content: center;
    padding-left: 20px; min-width: 140px; position: relative;
}
.tf-upline-wrapper::before {
    content: ''; position: absolute; left: 0; top: 50%;
    width: 20px; height: 2px; background: #363b57;
}
.tf-upline-wrapper::after {
    content: ''; position: absolute; left: 16px; top: calc(50% - 4px);
    border-top: 5px solid transparent; border-bottom: 5px solid transparent; border-left: 6px solid #363b57;
}

.tf-dep-node {
    background: #232736; border: 1px solid #06b6d4; padding: 8px 10px;
    border-radius: 8px; display: flex; flex-direction: column; gap: 4px;
    position: relative; font-size: 11px; color: #ccc;
}
.tf-dep-header {
    display: flex; align-items: center; justify-content: space-between;
}
.tf-dep-footer {
    display: flex; align-items: center; justify-content: space-between;
    border-top: 1px solid #363b57; padding-top: 4px; margin-top: 2px;
}
.tf-dep-name { font-weight: 800; color: #fff; font-size: 12px; }
.tf-dep-amt { color: #4CAF82; font-weight: 800; font-size: 12px; }
.tf-dep-time { font-size: 9px; color: #666; }
.tf-dep-com { color: #f59e0b; font-size: 10px; font-weight: 700; }

.tf-up-node {
    background: #232736; border: 1.5px solid #f59e0b; padding: 8px 12px;
    border-radius: 10px; text-align: center; box-shadow: 0 3px 0 #161822;
}
.tf-up-node.pro { border-color: #8b5cf6; }
.tf-up-role { font-size: 9px; font-weight: 700; text-transform: uppercase; background: rgba(245,158,11,0.15); color: #fbbf24; padding: 2px 6px; border-radius: 4px; display: inline-block; margin-bottom: 4px; }
.tf-up-node.pro .tf-up-role { background: rgba(139,92,246,0.15); color: #a78bfa; }
.tf-up-name { font-weight: 800; color: #fff; font-size: 13px; }

@media (max-width: 600px) {
    .tf-group { flex-direction: column; padding: 10px; }
    .tf-depositors { border-right: none; border-bottom: 2px solid #363b57; padding-right: 0; padding-bottom: 16px; }
    .tf-upline-wrapper { padding-left: 0; padding-top: 20px; }
    .tf-upline-wrapper::before { left: 50%; top: 0; width: 2px; height: 20px; }
    .tf-upline-wrapper::after { left: calc(50% - 5px); top: 16px; border-left: 5px solid transparent; border-right: 5px solid transparent; border-top: 6px solid #363b57; border-bottom: none; }
}
</style>

<?php if ($flash): ?>
<div class="alert alert-<?= $flashType === 'error' ? 'danger' : 'success' ?> py-2 mb-3" style="border-radius:10px;font-size:13px">
    <?= htmlspecialchars($flash) ?>
</div>
<?php endif; ?>

<div class="d-flex align-items-center justify-content-between mb-3">
  <div><h6 class="mb-0 fw-bold">🔄 Transaction Flow</h6></div>
  
  <div id="bulk-actions" style="display:none; gap: 8px;">
      <span style="font-size: 12px; font-weight: bold; margin-right: 8px;" id="bulk-count">0 terpilih</span>
      <button class="btn btn-sm btn-primary" onclick="openBulkEditModal()" style="font-size: 11px;">✏️ Bulk Edit</button>
      <form id="bulk-delete-form" method="POST" style="display:inline;" onsubmit="return confirm('Hapus semua deposit yang dipilih? (Hanya riwayat)');">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="bulk_delete">
          <input type="hidden" name="ids" id="bulk-delete-ids">
          <button type="submit" class="btn btn-sm btn-danger" style="font-size: 11px;">🗑️ Bulk Delete</button>
      </form>
  </div>
</div>

<div class="tf-container">
    <?php if (empty($rows)): ?>
        <div style="padding:40px;text-align:center;color:#555;font-size:12px;">Belum ada transaksi deposit berafiliasi.</div>
    <?php else: ?>
        <?php
            // Group by upline
            $groups = [];
            foreach ($rows as $row) {
                $uid = $row['upline_id'];
                if (!isset($groups[$uid])) {
                    $groups[$uid] = [
                        'upline_name' => $row['upline_name'],
                        'is_promotor' => $row['is_promotor'],
                        'deposits' => [],
                        'total_deposit' => 0,
                        'total_commission' => 0
                    ];
                }
                $groups[$uid]['deposits'][] = $row;
                $groups[$uid]['total_deposit'] += (float)$row['amount'];
                $groups[$uid]['total_commission'] += (float)$row['commission_amount'];
            }
        ?>
        <?php foreach ($groups as $g): ?>
            <div class="tf-group">
                <div class="tf-depositors">
                    <?php foreach ($g['deposits'] as $d): ?>
                        <div class="tf-dep-node">
                            <div class="tf-dep-header">
                                <label style="display: flex; align-items: center; gap: 6px; cursor: pointer; margin: 0;">
                                    <input type="checkbox" class="dep-cb" value="<?= $d['deposit_id'] ?>" onchange="updateBulkUI()">
                                    <span class="tf-dep-name"><?= htmlspecialchars($d['depositor_name']) ?></span>
                                </label>
                                <span class="tf-dep-time"><?= date('H:i', strtotime($d['confirmed_at'])) ?></span>
                            </div>
                            <div class="tf-dep-footer">
                                <div class="tf-dep-amt">Depo: <?= format_rp((float)$d['amount']) ?></div>
                                <div class="tf-dep-com">Komisi: <?= format_rp((float)$d['commission_amount']) ?></div>
                            </div>
                            <div style="display:flex; gap: 4px; margin-top: 6px; border-top: 1px dashed #363b57; padding-top: 6px;">
                                <button onclick="openEditModal(<?= $d['deposit_id'] ?>, <?= (float)$d['amount'] ?>)" class="btn btn-sm btn-primary" style="font-size: 9px; padding: 2px 6px; flex: 1;">Edit</button>
                                <form method="POST" style="flex: 1; display:flex;" onsubmit="return confirm('Hapus deposit ini? (Hanya menghapus riwayat).');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="ids" value="<?= $d['deposit_id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-danger" style="font-size: 9px; padding: 2px 6px; width: 100%;">Delete</button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="tf-upline-wrapper">
                    <div class="tf-up-node <?= $g['is_promotor'] ? 'pro' : '' ?>">
                        <div class="tf-up-role"><?= $g['is_promotor'] ? 'Promotor' : 'Upline' ?></div>
                        <div class="tf-up-name"><?= htmlspecialchars($g['upline_name']) ?></div>
                        <div style="font-size:10px;color:#888;margin-top:8px">Total Masuk:</div>
                        <div style="font-size:12px;color:#4CAF82;font-weight:900;"><?= format_rp($g['total_deposit']) ?></div>
                        <div style="font-size:10px;color:#888;margin-top:4px">Total Komisi:</div>
                        <div style="font-size:12px;color:#f59e0b;font-weight:900;"><?= format_rp($g['total_commission']) ?></div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- Pagination -->
<?php if ($totalPages > 1): ?>
<div class="d-flex justify-content-center mt-3">
  <div class="btn-group">
    <?php if ($page > 1): ?>
      <a href="?page=<?= $page-1 ?>" class="btn btn-sm btn-secondary" style="font-size:11px;">Prev</a>
    <?php endif; ?>
    <button class="btn btn-sm btn-dark" disabled style="font-size:11px;">Hal <?= $page ?> / <?= $totalPages ?></button>
    <?php if ($page < $totalPages): ?>
      <a href="?page=<?= $page+1 ?>" class="btn btn-sm btn-secondary" style="font-size:11px;">Next</a>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<!-- Edit Modal -->
<div id="editModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.6); z-index:9999; align-items:center; justify-content:center;">
    <div style="background:#1a1d27; border: 1px solid #363b57; padding: 20px; border-radius: 12px; width: 300px; max-width: 90%;">
        <h6 id="editModalTitle" style="color:#fff; margin-bottom: 16px; font-weight: bold;">Edit Nominal Deposit</h6>
        <form method="POST" id="editForm" onsubmit="return confirm('Update deposit ini? (Saldo tidak terpengaruh).');">
            <?= csrf_field() ?>
            <input type="hidden" name="action" id="editAction" value="edit">
            <input type="hidden" name="ids" id="editIds">
            
            <div style="margin-bottom: 12px;">
                <label style="font-size: 11px; color: #aaa; margin-bottom: 4px; display: block;">Nominal Baru</label>
                <input type="number" name="new_amount" id="editAmount" class="form-control" style="background:#0f1117; color:#fff; border:1px solid #363b57;" required>
            </div>
            
            <div style="display:flex; justify-content: flex-end; gap: 8px; margin-top: 16px;">
                <button type="button" class="btn btn-sm btn-secondary" onclick="closeEditModal()">Batal</button>
                <button type="submit" class="btn btn-sm btn-primary">Simpan</button>
            </div>
        </form>
    </div>
</div>

<script>
function updateBulkUI() {
    const cbs = document.querySelectorAll('.dep-cb:checked');
    const panel = document.getElementById('bulk-actions');
    const countSpan = document.getElementById('bulk-count');
    const deleteIds = document.getElementById('bulk-delete-ids');
    
    if (cbs.length > 0) {
        panel.style.display = 'flex';
        countSpan.textContent = cbs.length + ' terpilih';
        
        let ids = [];
        cbs.forEach(cb => ids.push(cb.value));
        deleteIds.value = ids.join(',');
    } else {
        panel.style.display = 'none';
    }
}

function openEditModal(id, amount) {
    document.getElementById('editModal').style.display = 'flex';
    document.getElementById('editAction').value = 'edit';
    document.getElementById('editIds').value = id;
    document.getElementById('editAmount').value = amount;
    document.getElementById('editModalTitle').textContent = 'Edit Nominal Deposit';
}

function openBulkEditModal() {
    const cbs = document.querySelectorAll('.dep-cb:checked');
    let ids = [];
    cbs.forEach(cb => ids.push(cb.value));
    
    if (ids.length === 0) return;
    
    document.getElementById('editModal').style.display = 'flex';
    document.getElementById('editAction').value = 'bulk_edit';
    document.getElementById('editIds').value = ids.join(',');
    document.getElementById('editAmount').value = '';
    document.getElementById('editModalTitle').textContent = 'Bulk Edit (' + ids.length + ' transaksi)';
}

function closeEditModal() {
    document.getElementById('editModal').style.display = 'none';
}
</script>

<?php require __DIR__ . '/partials/footer.php'; ?>
