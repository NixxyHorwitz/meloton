<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
staff_require('users');
csrf_enforce();

$flash = $flashType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $id     = (int)($_POST['id'] ?? 0);
    $status = $_POST['status'] ?? ''; // 'approved' or 'rejected'
    
    if ($action === 'process_request' && $id && in_array($status, ['approved', 'rejected'])) {
        try {
            $pdo->beginTransaction();
            $req = $pdo->prepare("SELECT r.*, u.username, u.balance_dep FROM admin_requests r JOIN users u ON u.id = r.user_id WHERE r.id=? FOR UPDATE");
            $req->execute([$id]); 
            $reqData = $req->fetch();
            
            if ($reqData && $reqData['status'] === 'pending') {
                if ($status === 'approved') {
                    if ($reqData['type'] === 'change_bank') {
                        $payload = json_decode($reqData['payload'], true);
                        if ($payload) {
                            $pdo->prepare("UPDATE users SET bank_name=?, account_number=?, account_name=? WHERE id=?")
                                ->execute([$payload['bank_name'], $payload['account_number'], $payload['account_name'], $reqData['user_id']]);
                        }
                        $flash = "Berhasil menyetujui perubahan rekening untuk user {$reqData['username']}.";
                    } elseif ($reqData['type'] === 'refund_level') {
                        $s = $pdo->prepare("SELECT u.membership_id, m.price, m.name, u.refund_cut_percent FROM users u LEFT JOIN memberships m ON u.membership_id = m.id WHERE u.id=?");
                        $s->execute([$reqData['user_id']]);
                        $uInfo = $s->fetch();
                        
                        if ($uInfo && $uInfo['membership_id']) {
                            $oStmt = $pdo->prepare("SELECT amount FROM upgrade_orders WHERE user_id=? AND membership_id=? AND status='confirmed' ORDER BY id DESC LIMIT 1");
                            $oStmt->execute([$reqData['user_id'], $uInfo['membership_id']]);
                            $basePrice = (float)$oStmt->fetchColumn();
                            if (!$basePrice) $basePrice = (float)$uInfo['price'];
                            
                            $pct = (float)$uInfo['refund_cut_percent'];
                            $refundAmt = $basePrice * ((100 - $pct) / 100);
                            
                            $pdo->prepare("UPDATE users SET balance_dep = balance_dep + ?, membership_id = NULL, membership_expires_at = NULL WHERE id = ?")
                                ->execute([$refundAmt, $reqData['user_id']]);
                            $flash = "Berhasil menyetujui refund level untuk user {$reqData['username']}. Saldo " . format_rp($refundAmt) . " telah dikembalikan (Potongan {$pct}%).";
                        } else {
                            throw new \Exception("User tidak memiliki level aktif yang dapat di-refund.");
                        }
                    } elseif ($reqData['type'] === 'refund_wd_hold') {
                        $payload = json_decode($reqData['payload'], true) ?: [];
                        $wd_id = $payload['withdrawal_id'] ?? 0;
                        $wd = $pdo->prepare("SELECT * FROM withdrawals WHERE id=? AND status='hold' FOR UPDATE");
                        $wd->execute([$wd_id]);
                        $wd = $wd->fetch();
                        if ($wd) {
                            $pdo->prepare("UPDATE withdrawals SET status='refunded', admin_note='Dikembalikan ke Saldo Tarik', processed_at=NOW() WHERE id=?")->execute([$wd_id]);
                            $pdo->prepare("UPDATE users SET balance_wd = balance_wd + ? WHERE id = ?")->execute([$wd['amount'], $reqData['user_id']]);
                            $flash = "Berhasil menyetujui refund WD Hold senilai " . format_rp((float)$wd['amount']) . " untuk user {$reqData['username']}.";
                        } else {
                            throw new \Exception("WD tidak ditemukan atau sudah tidak berstatus Hold.");
                        }
                    }
                } else {
                    $flash = "Permintaan telah ditolak (Rejected).";
                }
                
                $pdo->prepare("UPDATE admin_requests SET status=?, updated_at=NOW() WHERE id=?")->execute([$status, $id]);
                $pdo->commit();
            } else {
                $pdo->rollBack();
                $flash = 'Permintaan sudah diproses atau tidak ditemukan.'; $flashType = 'error';
            }
        } catch (\Throwable $th) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $flash = 'Error: ' . $th->getMessage(); $flashType = 'error';
        }
    }
}

// Fetch requests
$stmt = $pdo->query("SELECT r.*, u.username FROM admin_requests r JOIN users u ON u.id = r.user_id ORDER BY CASE WHEN r.status='pending' THEN 0 ELSE 1 END ASC, r.created_at DESC");
$requests = $stmt->fetchAll();

$pageTitle = 'Antrean Permintaan';
$activePage = 'requests';
require __DIR__ . '/partials/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-4">
  <div>
    <h5 class="mb-0 fw-bold">🔔 Antrean Permintaan</h5>
    <small class="text-secondary">Persetujuan Ganti Rekening & Refund Level</small>
  </div>
</div>

<?php if ($flash): ?>
<div class="alert alert-<?= $flashType==='error'?'danger':'success' ?> py-2 mb-3" style="border-radius:10px;font-size:13px">
  <?= htmlspecialchars($flash) ?>
</div>
<?php endif; ?>

<div class="c-card">
  <div style="overflow-x:auto">
    <table class="c-table">
      <thead>
        <tr>
          <th>Tanggal</th>
          <th>User</th>
          <th>Tipe Permintaan</th>
          <th>Detail (Payload)</th>
          <th>Status</th>
          <th>Aksi</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($requests as $req): ?>
        <tr>
          <td style="font-size:12px;color:#888"><?= date('d M Y H:i', strtotime($req['created_at'])) ?></td>
          <td><strong style="font-size:13px"><?= htmlspecialchars($req['username']) ?></strong></td>
          <td>
            <span class="badge bg-secondary" style="font-size:11px">
              <?= $req['type'] === 'change_bank' ? '🏦 Ganti Rekening' : ($req['type'] === 'refund_level' ? '⏪ Refund Level' : ($req['type'] === 'refund_wd_hold' ? '💸 Refund WD Hold' : htmlspecialchars($req['type']))) ?>
            </span>
          </td>
          <td style="font-size:12px;color:#ccc;max-width:300px;white-space:normal;">
            <?php 
                if ($req['type'] === 'change_bank' && $req['payload']) {
                    $p = json_decode($req['payload'], true);
                    echo htmlspecialchars($p['bank_name'] ?? '') . " - " . htmlspecialchars($p['account_number'] ?? '') . " a/n " . htmlspecialchars($p['account_name'] ?? '');
                } else if ($req['type'] === 'refund_level') {
                    echo "Minta pengembalian dana atas level yang aktif saat ini.";
                } else if ($req['type'] === 'refund_wd_hold') {
                    $p = json_decode($req['payload'], true) ?: [];
                    echo "Minta pengembalian dana untuk WD Hold #" . ($p['withdrawal_id'] ?? '?');
                } else {
                    echo "-";
                }
            ?>
          </td>
          <td>
            <span class="badge <?= match($req['status']){'approved'=>'bg-success','pending'=>'bg-warning text-dark','rejected'=>'bg-danger',default=>'bg-secondary'} ?>" style="font-size:11px">
              <?= ucfirst($req['status']) ?>
            </span>
          </td>
          <td style="white-space:nowrap">
            <?php if ($req['status'] === 'pending'): ?>
            <form method="POST" class="d-inline">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="process_request">
              <input type="hidden" name="id" value="<?= $req['id'] ?>">
              <input type="hidden" name="status" value="approved">
              <button type="submit" class="btn btn-sm btn-success" style="font-size:11px;font-weight:700;padding:4px 8px;border-radius:6px;margin-right:4px;" onclick="return confirm('Setujui permintaan ini?')">✅ Approve</button>
            </form>
            <form method="POST" class="d-inline">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="process_request">
              <input type="hidden" name="id" value="<?= $req['id'] ?>">
              <input type="hidden" name="status" value="rejected">
              <button type="submit" class="btn btn-sm btn-danger" style="font-size:11px;font-weight:700;padding:4px 8px;border-radius:6px;" onclick="return confirm('Tolak permintaan ini?')">❌ Reject</button>
            </form>
            <?php else: ?>
              <span style="font-size:11px;color:#666">Selesai</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($requests)): ?>
        <tr>
          <td colspan="6" class="text-center text-secondary py-4">Belum ada antrean permintaan.</td>
        </tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>
