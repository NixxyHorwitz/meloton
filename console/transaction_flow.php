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
.tf-container {
    display: flex; flex-direction: column; gap: 20px; margin-top: 20px;
}
.tf-row {
    display: flex; align-items: center; justify-content: center;
    background: #1a1d27; border: 1px solid #2d3149;
    border-radius: 16px; padding: 20px;
    position: relative; overflow: hidden;
}
.tf-card {
    background: #232736; border: 2px solid #363b57;
    border-radius: 12px; padding: 16px; min-width: 200px;
    text-align: center; position: relative; z-index: 2;
    box-shadow: 0 4px 0 #161822;
}
.tf-card.tf-depositor { border-color: #06b6d4; }
.tf-card.tf-upline { border-color: #f59e0b; }
.tf-card.tf-promotor { border-color: #8b5cf6; }

.tf-name { font-size: 15px; font-weight: 800; color: #fff; margin-bottom: 4px; }
.tf-role { font-size: 11px; font-weight: 700; text-transform: uppercase; padding: 4px 8px; border-radius: 6px; display: inline-block; margin-bottom: 10px; }
.tf-role-dep { background: rgba(6,182,212,0.15); color: #22d3ee; }
.tf-role-upl { background: rgba(245,158,11,0.15); color: #fbbf24; }
.tf-role-pro { background: rgba(139,92,246,0.15); color: #a78bfa; }

.tf-amount { font-size: 18px; font-weight: 900; color: #4CAF82; }
.tf-date { font-size: 11px; color: #888; margin-top: 8px; }

.tf-connector {
    flex: 1; min-width: 60px; max-width: 150px;
    height: 4px; background: #363b57;
    position: relative; margin: 0 10px; z-index: 1;
}
.tf-connector::after {
    content: ''; position: absolute; right: -10px; top: -8px;
    border-top: 10px solid transparent;
    border-bottom: 10px solid transparent;
    border-left: 12px solid #363b57;
}
.tf-comm {
    position: absolute; top: -30px; left: 50%; transform: translateX(-50%);
    background: #FF6B35; color: #fff; font-size: 11px; font-weight: 800;
    padding: 4px 10px; border-radius: 20px; white-space: nowrap;
    box-shadow: 0 2px 0 #c2410c;
}

@media (max-width: 768px) {
    .tf-row { flex-direction: column; gap: 30px; }
    .tf-connector { 
        width: 4px; height: 40px; min-height: 40px; max-height: 40px; min-width: 4px; max-width: 4px; margin: 0;
    }
    .tf-connector::after {
        right: -8px; top: auto; bottom: -10px;
        border-left: 10px solid transparent;
        border-right: 10px solid transparent;
        border-top: 12px solid #363b57;
        border-bottom: none;
    }
    .tf-comm { top: 50%; left: 30px; transform: translateY(-50%); }
}
</style>

<div class="d-flex align-items-center justify-content-between mb-4">
  <div><h5 class="mb-0 fw-bold">🔄 Transaction Flow</h5></div>
</div>

<div class="alert alert-secondary py-2" style="background:#1a1d27;border-color:#2d3149;color:#ccc;border-radius:10px;font-size:13px;">
  <i class="fw-bold">Info:</i> Halaman ini memvisualisasikan aliran Deposit yang sukses (Confirmed) dari User ke Upline/Promotor mereka.
</div>

<div class="tf-container">
    <?php if (empty($rows)): ?>
        <div style="padding:40px;text-align:center;color:#555">Belum ada transaksi deposit berafiliasi.</div>
    <?php endif; ?>
    
    <?php foreach ($rows as $row): ?>
        <?php 
            $is_pro = (int)$row['is_promotor'] === 1;
            $upline_class = $is_pro ? 'tf-promotor' : 'tf-upline';
            $upline_role_class = $is_pro ? 'tf-role-pro' : 'tf-role-upl';
            $upline_label = $is_pro ? 'Promotor' : 'Upline';
        ?>
        <div class="tf-row">
            <!-- Depositor -->
            <div class="tf-card tf-depositor">
                <div class="tf-role tf-role-dep">Depositor</div>
                <div class="tf-name"><?= htmlspecialchars($row['depositor_name']) ?></div>
                <div class="tf-amount"><?= format_rp((float)$row['amount']) ?></div>
                <div class="tf-date">Tgl: <?= date('d M Y H:i', strtotime($row['confirmed_at'])) ?></div>
            </div>
            
            <!-- Connection Line -->
            <div class="tf-connector">
                <?php if ($row['commission_amount'] > 0): ?>
                    <div class="tf-comm">+ <?= format_rp((float)$row['commission_amount']) ?> Komisi</div>
                <?php elseif ($is_pro): ?>
                    <div class="tf-comm" style="background:#8b5cf6;box-shadow:0 2px 0 #5b21b6;">Target Harian</div>
                <?php endif; ?>
            </div>
            
            <!-- Upline -->
            <div class="tf-card <?= $upline_class ?>">
                <div class="tf-role <?= $upline_role_class ?>"><?= $upline_label ?></div>
                <div class="tf-name"><?= htmlspecialchars($row['upline_name']) ?></div>
                <div style="font-size:12px;color:#888;margin-top:8px">Penerima Manfaat</div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<!-- Pagination -->
<?php if ($totalPages > 1): ?>
<div class="d-flex justify-content-center mt-4">
  <div class="btn-group">
    <?php if ($page > 1): ?>
      <a href="?page=<?= $page-1 ?>" class="btn btn-sm btn-secondary">Prev</a>
    <?php endif; ?>
    <button class="btn btn-sm btn-dark" disabled>Hal <?= $page ?> / <?= $totalPages ?></button>
    <?php if ($page < $totalPages): ?>
      <a href="?page=<?= $page+1 ?>" class="btn btn-sm btn-secondary">Next</a>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<?php require __DIR__ . '/partials/footer.php'; ?>
