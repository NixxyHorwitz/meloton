<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
staff_require('upgrades');
csrf_enforce();

$flash = $flashType = '';
$filter = $_GET['status'] ?? 'pending';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $id     = (int)($_POST['id'] ?? 0);
    $note   = trim($_POST['note'] ?? '');

    if ($action === 'confirm' && $id) {
        $upg = $pdo->prepare("SELECT * FROM upgrade_orders WHERE id=? AND status='pending'");
        $upg->execute([$id]); $upg = $upg->fetch();
        if ($upg) {
            $ms = $pdo->prepare("SELECT * FROM memberships WHERE id=?");
            $ms->execute([$upg['membership_id']]); $ms = $ms->fetch();
            if ($ms) {
                $days    = (int)$ms['duration_days'];
                $expires = date('Y-m-d H:i:s', strtotime("+{$days} days"));
                $pdo->prepare("UPDATE users SET membership_id=?,membership_expires_at=? WHERE id=?")
                    ->execute([$ms['id'], $expires, $upg['user_id']]);
                $pdo->prepare("UPDATE upgrade_orders SET status='confirmed',admin_note=?,confirmed_at=NOW() WHERE id=?")
                    ->execute([$note, $id]);
                $flash = "Upgrade #{$id} dikonfirmasi. Paket {$ms['name']} aktif hingga " . date('d M Y', strtotime($expires)) . ".";
            }
        }
    }
    if ($action === 'reject' && $id) {
        $pdo->prepare("UPDATE upgrade_orders SET status='rejected',admin_note=? WHERE id=?")->execute([$note ?: 'Ditolak admin', $id]);
        $flash = "Upgrade #{$id} ditolak.";
    }
}

$where  = $filter !== 'all' ? "WHERE uo.status=?" : "";
$params = $filter !== 'all' ? [$filter] : [];
$rows   = $pdo->prepare("SELECT uo.*, u.username, u.email, m.name as plan_name FROM upgrade_orders uo JOIN users u ON u.id=uo.user_id JOIN memberships m ON m.id=uo.membership_id $where ORDER BY uo.created_at DESC");
$rows->execute($params); $rows = $rows->fetchAll();

$counts = $pdo->query("SELECT status, COUNT(*) as cnt FROM upgrade_orders GROUP BY status")->fetchAll();
$countMap = array_column($counts, 'cnt', 'status');

$pageTitle  = 'Upgrade Orders';
$activePage = 'upgrades';
require __DIR__ . '/partials/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-4">
  <div><h5 class="mb-0 fw-bold">👑 Upgrade Orders</h5></div>
</div>

<?php if ($flash): ?>
<div class="alert alert-success py-2 mb-3" style="border-radius:10px;font-size:13px"><?= htmlspecialchars($flash) ?></div>
<?php endif; ?>

<div class="d-flex gap-2 mb-3 flex-wrap">
  <?php foreach (['all'=>'Semua','pending'=>'Pending','confirmed'=>'Confirmed','rejected'=>'Rejected'] as $s=>$lbl): ?>
  <a href="?status=<?= $s ?>" class="btn btn-sm <?= $filter===$s?'text-white':'btn-secondary' ?>" style="<?= $filter===$s?'background:var(--brand)':'' ?>">
    <?= $lbl ?> <?php $cnt=$s==='all'?array_sum($countMap):($countMap[$s]??0); if($cnt>0): ?><span class="badge bg-dark ms-1"><?= $cnt ?></span><?php endif; ?>
  </a>
  <?php endforeach; ?>
</div>

<div class="c-card">
  <div style="overflow-x:auto">
    <table class="c-table">
      <thead><tr><th>User</th><th>Paket</th><th>Harga</th><th>Bukti</th><th>Tanggal</th><th>Status</th><th>Aksi</th></tr></thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
        <tr>
          <td><strong style="font-size:13px"><?= htmlspecialchars($r['username']) ?></strong><div style="font-size:11px;color:#666"><?= htmlspecialchars($r['email']) ?></div></td>
          <td><span class="badge b-neutral" style="border-radius:6px;font-size:12px">⭐ <?= htmlspecialchars($r['plan_name']) ?></span></td>
          <td style="color:#FF6B35;font-weight:700"><?= format_rp((float)$r['amount']) ?></td>
          <td>
            <?php if ($r['proof_image']): ?>
            <a href="/uploads/<?= htmlspecialchars($r['proof_image']) ?>" target="_blank">
              <img src="/uploads/<?= htmlspecialchars($r['proof_image']) ?>" style="width:50px;height:35px;object-fit:cover;border-radius:6px;border:1px solid #2d3149">
            </a>
            <?php else: ?><span style="color:#555;font-size:12px">—</span><?php endif; ?>
          </td>
          <td style="font-size:12px;color:#666"><?= date('d M Y H:i', strtotime($r['created_at'])) ?></td>
          <td><span class="badge <?= match($r['status']){'confirmed'=>'b-success','pending'=>'b-warn','rejected'=>'b-danger'} ?>" style="border-radius:6px;padding:4px 8px"><?= ucfirst($r['status']) ?></span></td>
          <td>
            <?php if ($r['status'] === 'pending'): ?>
            <button class="btn btn-sm b-success" style="border:none;border-radius:8px;font-size:11px" onclick="processUpg(<?= $r['id'] ?>,'confirm')">✓ Aktifkan</button>
            <button class="btn btn-sm b-danger" style="border:none;border-radius:8px;font-size:11px" onclick="processUpg(<?= $r['id'] ?>,'reject')">✗ Tolak</button>
            <?php else: ?><span style="font-size:11px;color:#555">—</span><?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php if (empty($rows)): ?><div style="padding:40px;text-align:center;color:#555">Tidak ada data.</div><?php endif; ?>
  </div>
</div>

<div class="modal fade" id="upgModal" tabindex="-1">
  <div class="modal-dialog modal-sm"><div class="modal-content" style="background:#1a1d27;border:1px solid #2d3149">
    <form method="POST">
    <?= csrf_field() ?><input type="hidden" name="action" id="upg-action"><input type="hidden" name="id" id="upg-id">
    <div class="modal-header border-0"><h6 class="modal-title fw-bold" id="upg-title">Proses Upgrade</h6><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
    <div class="modal-body"><div class="c-form-group"><label class="c-label">Catatan (opsional)</label><textarea name="note" class="c-form-control" rows="2"></textarea></div></div>
    <div class="modal-footer border-0">
      <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Batal</button>
      <button type="submit" id="upg-submit" class="btn btn-sm text-white" style="background:#4CAF82">Aktifkan</button>
    </div>
    </form>
  </div></div>
</div>

<script>
function processUpg(id, action) {
  document.getElementById('upg-id').value = id;
  document.getElementById('upg-action').value = action;
  document.getElementById('upg-title').textContent = action==='confirm'?'✅ Aktifkan Paket':'❌ Tolak Upgrade';
  document.getElementById('upg-submit').style.background = action==='confirm'?'#4CAF82':'#F44E3B';
  document.getElementById('upg-submit').textContent = action==='confirm'?'Aktifkan':'Tolak';
  new bootstrap.Modal(document.getElementById('upgModal')).show();
}
</script>

<?php require __DIR__ . '/partials/footer.php'; ?>
