
<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/auth/guard.php';

$tab = $_GET['tab'] ?? 'reward';

// Reward / watch history
$rewards = $pdo->prepare(
    "SELECT wh.*, v.title as video_title FROM watch_history wh
     LEFT JOIN videos v ON v.id=wh.video_id
     WHERE wh.user_id=? ORDER BY wh.watched_at DESC LIMIT 30"
);
$rewards->execute([$user['id']]); $rewards = $rewards->fetchAll();

// Deposits
$deposits = $pdo->prepare("SELECT * FROM deposits WHERE user_id=? ORDER BY created_at DESC LIMIT 30");
$deposits->execute([$user['id']]); $deposits = $deposits->fetchAll();

// Withdrawals
$wds = $pdo->prepare("SELECT * FROM withdrawals WHERE user_id=? ORDER BY created_at DESC LIMIT 30");
$wds->execute([$user['id']]); $wds = $wds->fetchAll();

// Payment Channels Logos
$channels = $pdo->query("SELECT name, logo FROM payment_channels WHERE logo IS NOT NULL AND logo != ''")->fetchAll();
$channel_logos = [];
foreach ($channels as $c) {
    $channel_logos[strtolower($c['name'])] = $c['logo'];
}

// Totals
$total_earned = (float)$user['total_earned'];
$total_dep    = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM deposits WHERE user_id=? AND status='confirmed'");
$total_dep->execute([$user['id']]); $total_dep = (float)$total_dep->fetchColumn();
$total_wd     = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM withdrawals WHERE user_id=? AND status='approved'");
$total_wd->execute([$user['id']]); $total_wd = (float)$total_wd->fetchColumn();

$pageTitle  = 'Riwayat — Meloton';
$activePage = 'history';
require dirname(__DIR__) . '/partials/header.php';
?>

<style>
.history-stats { display: flex; gap: 8px; margin-bottom: 20px; }
.history-stat { flex: 1; border: 2.5px solid var(--ink); border-radius: 12px; padding: 12px 8px; box-shadow: 3px 3px 0 var(--ink); text-align: center; position: relative; overflow: hidden; }
.history-stat::before { content: ""; position: absolute; inset: 0; background-image: radial-gradient(#000 1px, transparent 1px); background-size: 8px 8px; opacity: 0.1; }
.history-stat__lbl { font-size: 10px; font-weight: 900; margin-bottom: 4px; text-transform: uppercase; display: flex; align-items: center; justify-content: center; gap: 4px; position: relative; z-index: 2; }
.history-stat__val { font-size: 16px; font-weight: 900; letter-spacing: -0.5px; position: relative; z-index: 2; }

.history-tabs { display: flex; gap: 8px; margin-bottom: 20px; }
.history-tab { flex: 1; text-align: center; padding: 10px 4px; font-size: 11px; font-weight: 900; text-decoration: none; color: var(--ink); background: #fff; border: 2.5px solid var(--ink); border-radius: 12px; box-shadow: 3px 3px 0 var(--ink); transition: transform .1s, box-shadow .1s; display: flex; align-items: center; justify-content: center; gap: 6px; }
.history-tab:active { transform: translate(2px, 2px); box-shadow: 1px 1px 0 var(--ink); }
.history-tab--active { background: linear-gradient(135deg, #fde047, #facc15); box-shadow: 3px 3px 0 var(--ink); }

.h-list { display: flex; flex-direction: column; gap: 12px; }
.h-item { display: flex; align-items: center; padding: 12px; background: #fff; border: 2.5px solid var(--ink); border-radius: 12px; box-shadow: 3px 3px 0 var(--ink); gap: 12px; position: relative; transition: transform 0.1s; }
.h-item:hover { transform: translateY(-2px); box-shadow: 4px 4px 0 var(--ink); }
.h-item__ico { width: 38px; height: 38px; flex-shrink: 0; border: 2px solid var(--ink); border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 20px; overflow: hidden; background: #f8fafc; box-shadow: 2px 2px 0 var(--ink); }
.h-item__bd { flex: 1; min-width: 0; line-height: 1.3; }
.h-item__title { font-size: 14px; font-weight: 900; color: var(--ink); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; margin-bottom: 2px; }
.h-item__sub { font-size: 10px; font-weight: 800; color: #64748b; }
.h-item__note { font-size: 10px; color: var(--red); font-weight: 900; margin-top: 4px; display: flex; align-items: center; gap: 4px; background: #fee2e2; padding: 4px 6px; border-radius: 6px; border: 1.5px dashed var(--red); display: inline-flex; }
.h-item__rt { text-align: right; display: flex; flex-direction: column; align-items: flex-end; gap: 4px; }
.h-item__amt { font-size: 15px; font-weight: 900; }
</style>

<div class="page-title-bar" style="margin-bottom:12px">
  <h1 style="font-size:18px"><i class="ph-bold ph-list-dashes" style="color:var(--brand)"></i> Riwayat</h1>
  <p style="font-size:11px">Aktivitas akun kamu</p>
</div>

<!-- Summary stats -->
<div class="history-stats">
  <div class="history-stat" style="background:linear-gradient(135deg, #c084fc, #9333ea);color:#fff">
    <div class="history-stat__lbl" style="color:#f3e8ff"><i class="ph-bold ph-gift"></i> Reward</div>
    <div class="history-stat__val"><?= format_rp($total_earned) ?></div>
  </div>
  <div class="history-stat" style="background:linear-gradient(135deg, #38bdf8, #0284c7);color:#fff">
    <div class="history-stat__lbl" style="color:#e0f2fe"><i class="ph-bold ph-wallet"></i> Top Up</div>
    <div class="history-stat__val"><?= format_rp($total_dep) ?></div>
  </div>
  <div class="history-stat" style="background:linear-gradient(135deg, #fb923c, #ea580c);color:#fff">
    <div class="history-stat__lbl" style="color:#ffedd5"><i class="ph-bold ph-paper-plane-right"></i> Tarik</div>
    <div class="history-stat__val"><?= format_rp($total_wd) ?></div>
  </div>
</div>

<!-- Tabs -->
<div class="history-tabs">
  <a href="?tab=reward" class="history-tab <?= $tab==='reward'?'history-tab--active':'' ?>"><i class="ph-bold ph-gift"></i> Reward</a>
  <a href="?tab=deposit" class="history-tab <?= $tab==='deposit'?'history-tab--active':'' ?>"><i class="ph-bold ph-wallet"></i> Top Up</a>
  <a href="?tab=withdraw" class="history-tab <?= $tab==='withdraw'?'history-tab--active':'' ?>"><i class="ph-bold ph-paper-plane-right"></i> Tarik</a>
</div>

<!-- Reward Tab -->
<?php if ($tab === 'reward'): ?>
<div class="h-list">
  <?php if (empty($rewards)): ?>
  <div class="empty-state" style="padding:30px;border:2.5px solid var(--ink);border-radius:10px;background:#fff;text-align:center"><p style="font-size:12px;font-weight:700;color:#888">Belum ada riwayat nonton.</p></div>
  <?php else: ?>
    <?php foreach ($rewards as $r): ?>
    <div class="h-item">
      <div class="h-item__ico" style="background:#ecfdf5;color:var(--green)">
        <i class="ph-fill ph-play-circle"></i>
      </div>
      <div class="h-item__bd">
        <div class="h-item__title"><?= htmlspecialchars($r['video_title'] ?? 'Video #'.$r['video_id']) ?></div>
        <div class="h-item__sub"><?= date('d M Y H:i', strtotime($r['watched_at'])) ?></div>
      </div>
      <div class="h-item__rt">
        <div class="h-item__amt" style="color:var(--green)">+<?= format_rp((float)$r['reward_given']) ?></div>
      </div>
    </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<!-- Deposit Tab -->
<?php elseif ($tab === 'deposit'): ?>
<div class="h-list">
  <?php if (empty($deposits)): ?>
  <div class="empty-state" style="padding:30px;border:2.5px solid var(--ink);border-radius:10px;background:#fff;text-align:center"><p style="font-size:12px;font-weight:700;color:#888">Belum ada riwayat top up.</p></div>
  <?php else: ?>
    <?php foreach ($deposits as $d): ?>
    <?php $dl = $channel_logos[strtolower($d['method'])] ?? null; ?>
    <div class="h-item">
      <?php if ($dl): ?>
      <div class="h-item__ico" style="padding:0;background:#fff">
        <img src="/assets/banks/<?= htmlspecialchars($dl) ?>" style="width:100%;height:100%;object-fit:contain">
      </div>
      <?php else: ?>
        <div class="h-item__ico" style="background:#fef08a;color:#d97706">
          <i class="<?= $d['method']==='qris' ? 'ph-bold ph-qr-code' : 'ph-bold ph-bank' ?>"></i>
        </div>
      <?php endif; ?>
      <div class="h-item__bd">
        <div class="h-item__title"><?= format_rp((float)$d['amount']) ?></div>
        <div class="h-item__sub"><?= strtoupper($d['method']) ?> · <?= date('d M y H:i', strtotime($d['created_at'])) ?></div>
        <?php if ($d['admin_note']): ?>
        <div class="h-item__note"><i class="ph-bold ph-note"></i> <?= htmlspecialchars($d['admin_note']) ?></div>
        <?php endif; ?>
      </div>
      <div class="h-item__rt">
        <span class="badge badge--<?= match($d['status']){'confirmed'=>'success','pending'=>'warn','rejected'=>'error',default=>'error'} ?>" style="font-size:9px;padding:2px 5px">
          <?= match($d['status']){'confirmed'=>'Sukses', 'pending'=>'Menunggu', 'rejected'=>'Ditolak', default=>ucfirst($d['status'])} ?>
        </span>
        <?php if ($d['status']==='pending' && $d['method']==='qris'): ?>
        <a href="/pay?id=<?= $d['id'] ?>" class="btn btn--yellow btn--sm" style="padding:3px 6px;font-size:9px"><i class="ph-bold ph-arrow-right"></i> Bayar</a>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<!-- Withdraw Tab -->
<?php elseif ($tab === 'withdraw'): ?>
<div class="h-list">
  <?php if (empty($wds)): ?>
  <div class="empty-state" style="padding:30px;border:2.5px solid var(--ink);border-radius:10px;background:#fff;text-align:center"><p style="font-size:12px;font-weight:700;color:#888">Belum ada riwayat penarikan.</p></div>
  <?php else: ?>
    <?php foreach ($wds as $w): ?>
    <?php $wl = $channel_logos[strtolower($w['bank_name'])] ?? null; ?>
    <div class="h-item">
      <?php if ($wl): ?>
      <div class="h-item__ico" style="padding:0;background:#fff">
        <img src="/assets/banks/<?= htmlspecialchars($wl) ?>" style="width:100%;height:100%;object-fit:contain">
      </div>
      <?php else: ?>
      <div class="h-item__ico" style="background:#fef08a;color:#d97706">
        <i class="ph-bold ph-bank"></i>
      </div>
      <?php endif; ?>
      <div class="h-item__bd">
        <div class="h-item__title"><?= format_rp((float)$w['amount']) ?></div>
        <div class="h-item__sub"><?= htmlspecialchars($w['bank_name']) ?> · <?= date('d M y H:i', strtotime($w['created_at'])) ?></div>
        <?php if ($w['admin_note']): ?>
        <div class="h-item__note"><i class="ph-bold ph-note"></i> <?= htmlspecialchars($w['admin_note']) ?></div>
        <?php endif; ?>
      </div>
      <div class="h-item__rt">
        <span class="badge badge--<?= match($w['status']){'approved'=>'success','pending'=>'warn','rejected'=>'error','refunded'=>'info',default=>'error'} ?>" style="font-size:9px;padding:2px 5px">
          <?= match($w['status']){'approved'=>'Sukses', 'pending'=>'Menunggu', 'hold'=>'Ditahan', 'rejected'=>'Ditolak', 'refunded'=>'Dikembalikan', default=>ucfirst($w['status'])} ?>
        </span>
        <div class="h-item__amt" style="color:var(--red);margin-top:2px">-<?= format_rp((float)$w['amount']) ?></div>
      </div>
    </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php require dirname(__DIR__) . '/partials/footer.php'; ?>
