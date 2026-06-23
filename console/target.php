<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
staff_require('target');

// Deposit target
$targetDepositDaily = (float) setting($pdo, 'target_deposit_daily', '10000000');
$targetMemberDaily = (int) setting($pdo, 'target_member_daily', '100');

// Calculate current deposit
$depoQuery = $pdo->query("SELECT SUM(amount) FROM deposits WHERE (status = 'confirmed' OR status = 'approved') AND DATE(created_at) = CURDATE()");
$currentDeposit = (float) $depoQuery->fetchColumn();

// Calculate current members
$memberQuery = $pdo->query("SELECT COUNT(*) FROM users WHERE DATE(created_at) = CURDATE()");
$currentMembers = (int) $memberQuery->fetchColumn();

// Percentages
$depoPercent = $targetDepositDaily > 0 ? min(100, round(($currentDeposit / $targetDepositDaily) * 100, 1)) : 0;
$memberPercent = $targetMemberDaily > 0 ? min(100, round(($currentMembers / $targetMemberDaily) * 100, 1)) : 0;

$pageTitle = 'Persentase Target';
$activePage = 'target';
require __DIR__ . '/partials/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-4">
  <div><h5 class="mb-0 fw-bold">🎯 Persentase Target Harian</h5></div>
</div>

<div class="row g-3 mb-4">
  <div class="col-md-6">
    <div class="c-card">
      <div class="c-card-header"><span class="c-card-title">💰 Target Deposit</span></div>
      <div class="c-card-body text-center">
        <div style="font-size:48px;font-weight:900;color:<?= $depoPercent >= 100 ? '#4CAF82' : 'var(--brand)' ?>;margin-bottom:15px"><?= $depoPercent ?>%</div>
        <div class="progress" style="height: 12px; background: #1a1d27; border-radius: 10px; border: 1px solid #2d3149;">
          <div class="progress-bar <?= $depoPercent >= 100 ? 'bg-success' : 'bg-primary' ?>" role="progressbar" style="width: <?= $depoPercent ?>%; background-color: var(--brand) !important;" aria-valuenow="<?= $depoPercent ?>" aria-valuemin="0" aria-valuemax="100"></div>
        </div>
      </div>
    </div>
  </div>
  
  <div class="col-md-6">
    <div class="c-card">
      <div class="c-card-header"><span class="c-card-title">👥 Target Registrasi Member</span></div>
      <div class="c-card-body text-center">
        <div style="font-size:48px;font-weight:900;color:<?= $memberPercent >= 100 ? '#4CAF82' : '#F29900' ?>;margin-bottom:15px"><?= $memberPercent ?>%</div>
        <div class="progress" style="height: 12px; background: #1a1d27; border-radius: 10px; border: 1px solid #2d3149;">
          <div class="progress-bar <?= $memberPercent >= 100 ? 'bg-success' : 'bg-warning' ?>" role="progressbar" style="width: <?= $memberPercent ?>%;" aria-valuenow="<?= $memberPercent ?>" aria-valuemin="0" aria-valuemax="100"></div>
        </div>
      </div>
    </div>
  </div>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>
