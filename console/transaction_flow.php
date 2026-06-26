<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
staff_require('transaction_flow');

// Pagination
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 50;
$offset = ($page - 1) * $limit;

// Fetch confirmed deposits that have an upline
$sql = "
    SELECT 
        d.id AS deposit_id, d.amount, d.confirmed_at,
        u1.id AS depositor_id, u1.username AS depositor_name, u1.email AS depositor_email,
        u2.id AS upline_id, u2.username AS upline_name, u2.is_promotor,
        0 AS commission_amount
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
    display: flex; flex-direction: column; gap: 6px; flex: 1;
    border-right: 2px solid #363b57; padding-right: 16px;
    justify-content: center; position: relative;
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
    border-top: 4px solid transparent; border-bottom: 4px solid transparent; border-left: 5px solid #363b57;
}

.tf-dep-node {
    background: #232736; border: 1px solid #06b6d4; padding: 6px 10px;
    border-radius: 8px; display: flex; align-items: center; justify-content: space-between;
    position: relative; font-size: 11px; color: #ccc;
}
.tf-dep-node::after {
    content: ''; position: absolute; right: -16px; top: 50%;
    width: 16px; height: 2px; background: #363b57;
}
.tf-dep-name { font-weight: 800; color: #fff; font-size: 12px; }
.tf-dep-amt { color: #4CAF82; font-weight: 800; font-size: 12px; }
.tf-dep-time { font-size: 9px; color: #666; margin-left: 8px; }

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
    .tf-dep-node::after { right: 50%; top: 100%; width: 2px; height: 16px; }
    .tf-upline-wrapper { padding-left: 0; padding-top: 20px; }
    .tf-upline-wrapper::before { left: 50%; top: 0; width: 2px; height: 20px; }
    .tf-upline-wrapper::after { left: calc(50% - 4px); top: 16px; border-left: 4px solid transparent; border-right: 4px solid transparent; border-top: 5px solid #363b57; border-bottom: none; }
}
</style>

<div class="d-flex align-items-center justify-content-between mb-3">
  <div><h6 class="mb-0 fw-bold">🔄 Transaction Flow</h6></div>
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
                        'deposits' => []
                    ];
                }
                $groups[$uid]['deposits'][] = $row;
            }
        ?>
        <?php foreach ($groups as $g): ?>
            <div class="tf-group">
                <div class="tf-depositors">
                    <?php foreach ($g['deposits'] as $d): ?>
                        <div class="tf-dep-node">
                            <div>
                                <span class="tf-dep-name"><?= htmlspecialchars($d['depositor_name']) ?></span>
                                <span class="tf-dep-time"><?= date('H:i', strtotime($d['confirmed_at'])) ?></span>
                            </div>
                            <div class="tf-dep-amt"><?= format_rp((float)$d['amount']) ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="tf-upline-wrapper">
                    <div class="tf-up-node <?= $g['is_promotor'] ? 'pro' : '' ?>">
                        <div class="tf-up-role"><?= $g['is_promotor'] ? 'Promotor' : 'Upline' ?></div>
                        <div class="tf-up-name"><?= htmlspecialchars($g['upline_name']) ?></div>
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

<?php require __DIR__ . '/partials/footer.php'; ?>
