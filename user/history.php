<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/auth/guard.php';

$flash = $flashType = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'refund_wd_hold') {
    $wd_id = (int)($_POST['wd_id'] ?? 0);
    $stmtPending = $pdo->prepare("SELECT id FROM admin_requests WHERE user_id=? AND type='refund_wd_hold' AND status='pending' AND payload LIKE ?");
    $stmtPending->execute([$user['id'], '%"withdrawal_id":'.$wd_id.'%']);
    if ($stmtPending->fetchColumn()) {
        $flash = '❌ Permintaan pengembalian untuk WD ini sudah diajukan.'; $flashType = 'error';
    } else {
        $w = $pdo->prepare("SELECT * FROM withdrawals WHERE id=? AND user_id=? AND status='hold'");
        $w->execute([$wd_id, $user['id']]);
        $wData = $w->fetch();
        if (!$wData) {
            $flash = '❌ Withdraw tidak ditemukan atau bukan berstatus Hold.'; $flashType = 'error';
        } else {
            $payload = json_encode(['withdrawal_id' => $wd_id]);
            $pdo->prepare("INSERT INTO admin_requests (user_id, type, payload) VALUES (?, 'refund_wd_hold', ?)")
                ->execute([$user['id'], $payload]);
            $req_id = $pdo->lastInsertId();
            
            $msg  = "💰 <b>REQUEST PENGEMBALIAN WD HOLD</b>\n\n";
            $msg .= "👤 User: <code>{$user['username']}</code>\n";
            $msg .= "💸 Jumlah WD: <b>" . format_rp((float)$wData['amount']) . "</b>\n";
            $msg .= "🏦 Tujuan Awal: {$wData['bank_name']} - {$wData['account_number']}\n\n";
            $msg .= "⚠️ <i>Refund ini akan mengembalikan saldo WD yang ditahan (Hold) ke Saldo Tarik user secara utuh.</i>\n";
            $kb = [
                [['text'=>'✅ Approve Refund', 'callback_data'=>'req_approve_'.$req_id], ['text'=>'❌ Reject', 'callback_data'=>'req_reject_'.$req_id]]
            ];
            send_telegram_notif($pdo, $msg, $kb, 'permintaan');
            
            $flash = '✅ Permintaan pengembalian dana telah masuk dan sedang diverifikasi admin.';
            $flashType = 'success';
        }
    }
    $_GET['tab'] = 'withdraw'; // force stay on withdraw tab
}

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

// Pending Refund Requests
$pending_refunds = $pdo->prepare("SELECT payload FROM admin_requests WHERE user_id=? AND type='refund_wd_hold' AND status='pending'");
$pending_refunds->execute([$user['id']]);
$requested_wds = [];
foreach ($pending_refunds->fetchAll() as $pr) {
    $p = json_decode($pr['payload'], true);
    if (isset($p['withdrawal_id'])) {
        $requested_wds[] = (int)$p['withdrawal_id'];
    }
}

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
/* ══════════════════════════════════════════════
   HISTORY PAGE — CASUAL GAME STYLE
   ══════════════════════════════════════════════ */
.history-page { padding: 0 0 20px; }

/* ── Title Bar ── */
.h-title {
  background: linear-gradient(135deg, #0f172a, #1e293b);
  border: 3px solid #020617;
  border-radius: 18px;
  padding: 16px 20px;
  box-shadow: 0 6px 0 #020617;
  color: #fff;
  margin-bottom: 16px;
  position: relative;
  overflow: hidden;
}
.h-title::before { content:''; position:absolute; top:-20px; right:-10px; width:80px; height:80px; background:rgba(255,255,255,0.05); border-radius:50%; }
.h-title h1 { font-size:18px; font-weight:900; color:#38bdf8; display:flex; align-items:center; gap:8px; margin-bottom:4px; letter-spacing:-0.5px; }
.h-title p { font-size:11px; font-weight:700; color:#94a3b8; }

/* ── Stats ── */
.h-stats { display: flex; gap: 8px; margin-bottom: 16px; }
.h-stat {
  flex: 1; border: 2.5px solid #0f172a; border-radius: 16px; padding: 12px 6px; text-align: center; position: relative; overflow: hidden;
  box-shadow: 0 5px 0 #0f172a;
}
.h-stat-1 { background: linear-gradient(135deg, #c084fc, #9333ea); border-color: #581c87; box-shadow: 0 5px 0 #581c87; color:#fff; }
.h-stat-2 { background: linear-gradient(135deg, #38bdf8, #0ea5e9); border-color: #0c4a6e; box-shadow: 0 5px 0 #0c4a6e; color:#fff; }
.h-stat-3 { background: linear-gradient(135deg, #fbbf24, #f59e0b); border-color: #78350f; box-shadow: 0 5px 0 #78350f; color:#fff; }
.h-stat__lbl { font-size: 10px; font-weight: 900; margin-bottom: 4px; text-transform: uppercase; letter-spacing:0.5px; opacity:0.9; }
.h-stat__val { font-size: 14px; font-weight: 900; letter-spacing: -0.5px; }

/* ── Tabs ── */
.h-tabs { display: flex; gap: 6px; margin-bottom: 16px; background:#f1f5f9; padding:6px; border-radius:18px; border:2.5px solid #cbd5e1; }
.h-tab {
  flex: 1; text-align: center; padding: 12px 4px; font-size: 12px; font-weight: 900;
  text-decoration: none; color: #64748b; background: transparent; border-radius: 12px;
  transition: all 0.15s; display: flex; align-items: center; justify-content: center; gap: 6px;
}
.h-tab--active {
  background: #fff; color: #0ea5e9; border: 2.5px solid #7dd3e8;
  box-shadow: 0 4px 0 #7dd3e8; transform: translateY(-2px);
}
.h-tab:not(.h-tab--active):active { background: #e2e8f0; }

/* ── List ── */
.h-list { display: flex; flex-direction: column; gap: 10px; }
.h-item {
  display: flex; align-items: center; padding: 14px 12px; background: #fff;
  border: 2.5px solid #cbd5e1; border-radius: 16px; box-shadow: 0 4px 0 #cbd5e1;
  gap: 12px; transition: transform 0.1s, box-shadow 0.1s;
}
.h-item:hover { transform: translateY(-2px); box-shadow: 0 6px 0 #cbd5e1; border-color:#94a3b8; }
.h-item__ico { width: 42px; height: 42px; flex-shrink: 0; border: 2.5px solid #0f172a; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 20px; overflow: hidden; background: #f8fafc; box-shadow: 0 3px 0 #0f172a; }
.h-item__bd { flex: 1; min-width: 0; line-height: 1.3; }
.h-item__title { font-size: 14px; font-weight: 900; color: #0f172a; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; margin-bottom: 4px; letter-spacing:-0.3px; }
.h-item__sub { font-size: 11px; font-weight: 800; color: #64748b; }
.h-item__note { font-size: 10px; color: #991b1b; font-weight: 900; margin-top: 6px; display: inline-flex; align-items: center; gap: 4px; background: #fef2f2; padding: 4px 8px; border-radius: 8px; border: 2px dashed #fca5a5; }
.h-item__rt { text-align: right; display: flex; flex-direction: column; align-items: flex-end; gap: 6px; }
.h-item__amt { font-size: 15px; font-weight: 900; letter-spacing:-0.5px; }

/* ── Badges ── */
.cg-badge { font-size:9px; font-weight:900; padding:4px 8px; border-radius:8px; border:2px solid; text-transform:uppercase; letter-spacing:0.5px; }
.cg-badge--succ { background:#d1fae5; color:#065f46; border-color:#34d399; }
.cg-badge--warn { background:#fef3c7; color:#92400e; border-color:#fbbf24; }
.cg-badge--err  { background:#fee2e2; color:#991b1b; border-color:#f87171; }
.cg-badge--info { background:#e0f2fe; color:#075985; border-color:#38bdf8; }

/* ── Empty ── */
.h-empty { text-align:center; padding:40px 20px; background:#fff; border:3px dashed #cbd5e1; border-radius:20px; }
.h-empty-ico { font-size:48px; margin-bottom:12px; opacity:0.4; }
.h-empty-txt { font-size:13px; font-weight:900; color:#94a3b8; }
/* ── Modals ── */
.cg-modal { display:none; position:fixed; inset:0; z-index:9999; background:rgba(0,0,0,.6); align-items:center; justify-content:center; backdrop-filter:blur(3px); padding:20px; }
.cg-modal-card { background:#fff; border-radius:24px; border:3px solid #0f172a; width:100%; max-width:380px; box-shadow:0 8px 0 #0f172a; animation:popIn .3s cubic-bezier(.175,.885,.32,1.275); padding:24px; position:relative; overflow:hidden; }
@keyframes popIn { 0% { transform:scale(0.8); opacity:0; } 100% { transform:scale(1); opacity:1; } }
.cg-mc-hdr { font-size: 18px; font-weight: 900; color: #0f172a; margin-bottom: 6px; display:flex; align-items:center; gap:8px; }
.cg-mc-sub { font-size: 12px; font-weight: 800; color: #64748b; margin-bottom: 16px; }
.cg-btn-row { display: flex; gap: 10px; }
.cg-btn { flex: 1; border: 2.5px solid #0f172a; border-radius: 12px; font-size: 13px; font-weight: 900; padding: 12px; box-shadow: 0 4px 0 #0f172a; cursor: pointer; text-align:center; }
.cg-btn:active { transform: translateY(3px); box-shadow: 0 1px 0 #0f172a; }
.cg-btn--cancel { background: #f1f5f9; color: #475569; }

</style>

<div class="history-page">
  <!-- Title -->

  <?php if ($flash): ?>
  <div style="background:<?= $flashType==='error'?'#fef2f2':'#f0fdf4' ?>;border:2.5px solid <?= $flashType==='error'?'#f87171':'#34d399' ?>;border-radius:14px;padding:12px 16px;color:<?= $flashType==='error'?'#991b1b':'#065f46' ?>;font-weight:800;font-size:12px;margin-bottom:16px;box-shadow:0 4px 0 <?= $flashType==='error'?'#fca5a5':'#6ee7b7' ?>;">
    <?= htmlspecialchars($flash) ?>
  </div>
  <?php endif; ?>

  <!-- Summary stats -->
  <div class="h-stats">
    <div class="h-stat h-stat-1">
      <div class="h-stat__lbl">Reward</div>
      <div class="h-stat__val"><?= format_rp($total_earned) ?></div>
    </div>
    <div class="h-stat h-stat-2">
      <div class="h-stat__lbl">Top Up</div>
      <div class="h-stat__val"><?= format_rp($total_dep) ?></div>
    </div>
    <div class="h-stat h-stat-3">
      <div class="h-stat__lbl">Tarik</div>
      <div class="h-stat__val"><?= format_rp($total_wd) ?></div>
    </div>
  </div>

  <!-- Tabs -->
  <div class="h-tabs">
    <a href="?tab=reward" class="h-tab <?= $tab==='reward'?'h-tab--active':'' ?>"><i class="ph-bold ph-gift"></i> Reward</a>
    <a href="?tab=deposit" class="h-tab <?= $tab==='deposit'?'h-tab--active':'' ?>"><i class="ph-bold ph-wallet"></i> Top Up</a>
    <a href="?tab=withdraw" class="h-tab <?= $tab==='withdraw'?'h-tab--active':'' ?>"><i class="ph-bold ph-paper-plane-right"></i> Tarik</a>
  </div>

  <!-- Reward Tab -->
  <?php if ($tab === 'reward'): ?>
  <div class="h-list">
    <?php if (empty($rewards)): ?>
    <div class="h-empty">
      <div class="h-empty-ico">🎁</div>
      <div class="h-empty-txt">Belum ada riwayat reward.</div>
    </div>
    <?php else: ?>
      <?php foreach ($rewards as $r): ?>
      <div class="h-item">
        <div class="h-item__ico" style="background:#d1fae5;color:#10b981;border-color:#059669;box-shadow:0 3px 0 #059669">
          <i class="ph-fill ph-play-circle"></i>
        </div>
        <div class="h-item__bd">
          <div class="h-item__title"><?= htmlspecialchars($r['video_title'] ?? 'Video #'.$r['video_id']) ?></div>
          <div class="h-item__sub"><i class="ph-bold ph-clock"></i> <?= date('d M Y H:i', strtotime($r['watched_at'])) ?></div>
        </div>
        <div class="h-item__rt">
          <div class="h-item__amt" style="color:#10b981">+<?= format_rp((float)$r['reward_given']) ?></div>
        </div>
      </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <!-- Deposit Tab -->
  <?php elseif ($tab === 'deposit'): ?>
  <div class="h-list">
    <?php if (empty($deposits)): ?>
    <div class="h-empty">
      <div class="h-empty-ico">💳</div>
      <div class="h-empty-txt">Belum ada riwayat top up.</div>
    </div>
    <?php else: ?>
      <?php foreach ($deposits as $d): ?>
      <?php $dl = $channel_logos[strtolower($d['method'])] ?? null; ?>
      <div class="h-item">
        <?php if ($dl): ?>
        <div class="h-item__ico" style="padding:4px;background:#fff;border-color:#e2e8f0;box-shadow:0 3px 0 #e2e8f0">
          <img src="/assets/banks/<?= htmlspecialchars($dl) ?>" style="width:100%;height:100%;object-fit:contain">
        </div>
        <?php else: ?>
          <div class="h-item__ico" style="background:#e0f2fe;color:#0284c7;border-color:#0369a1;box-shadow:0 3px 0 #0369a1">
            <i class="<?= $d['method']==='qris' ? 'ph-bold ph-qr-code' : 'ph-bold ph-bank' ?>"></i>
          </div>
        <?php endif; ?>
        <div class="h-item__bd">
          <div class="h-item__title"><?= format_rp((float)$d['amount']) ?></div>
          <div class="h-item__sub"><?= strtoupper($d['method']) ?> &bull; <?= date('d M y H:i', strtotime($d['created_at'])) ?></div>
          <?php if ($d['admin_note']): ?>
          <div class="h-item__note"><i class="ph-bold ph-note"></i> <?= htmlspecialchars($d['admin_note']) ?></div>
          <?php endif; ?>
        </div>
        <div class="h-item__rt">
          <span class="cg-badge cg-badge--<?= match($d['status']){'confirmed'=>'succ','pending'=>'warn','rejected'=>'err',default=>'err'} ?>">
            <?= match($d['status']){'confirmed'=>'Sukses', 'pending'=>'Menunggu', 'rejected'=>'Ditolak', default=>ucfirst($d['status'])} ?>
          </span>
          <?php if ($d['status']==='pending' && $d['method']==='qris'): ?>
          <a href="/pay?id=<?= $d['id'] ?>" class="cg-badge cg-badge--warn" style="background:#fde68a;border-color:#d97706;color:#92400e;text-decoration:none;box-shadow:0 2px 0 #d97706"><i class="ph-bold ph-arrow-right"></i> Bayar</a>
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
    <div class="h-empty">
      <div class="h-empty-ico">💸</div>
      <div class="h-empty-txt">Belum ada riwayat penarikan.</div>
    </div>
    <?php else: ?>
      <?php foreach ($wds as $w): ?>
      <?php $wl = $channel_logos[strtolower($w['bank_name'])] ?? null; ?>
      <div class="h-item">
        <?php if ($wl): ?>
        <div class="h-item__ico" style="padding:4px;background:#fff;border-color:#e2e8f0;box-shadow:0 3px 0 #e2e8f0">
          <img src="/assets/banks/<?= htmlspecialchars($wl) ?>" style="width:100%;height:100%;object-fit:contain">
        </div>
        <?php else: ?>
        <div class="h-item__ico" style="background:#fef3c7;color:#d97706;border-color:#b45309;box-shadow:0 3px 0 #b45309">
          <i class="ph-bold ph-bank"></i>
        </div>
        <?php endif; ?>
        <div class="h-item__bd">
          <div class="h-item__title"><?= format_rp((float)$w['amount']) ?></div>
          <div class="h-item__sub"><?= htmlspecialchars($w['bank_name']) ?> &bull; <?= date('d M y H:i', strtotime($w['created_at'])) ?></div>
          <?php if ($w['admin_note']): ?>
          <div class="h-item__note"><i class="ph-bold ph-note"></i> <?= htmlspecialchars($w['admin_note']) ?></div>
          <?php endif; ?>
        </div>
        <div class="h-item__rt">
          <span class="cg-badge cg-badge--<?= match($w['status']){'approved'=>'succ','pending'=>'warn','hold'=>'warn','rejected'=>'err','refunded'=>'info',default=>'err'} ?>">
            <?= match($w['status']){'approved'=>'Sukses', 'pending'=>'Menunggu', 'hold'=>'Ditahan', 'rejected'=>'Ditolak', 'refunded'=>'Dikembalikan', default=>ucfirst($w['status'])} ?>
          </span>
          <div class="h-item__amt" style="color:#ef4444">-<?= format_rp((float)$w['amount']) ?></div>
          <?php if ($w['status'] === 'hold'): ?>
          <div style="margin-top:4px;width:100%">
            <?php if (in_array($w['id'], $requested_wds)): ?>
            <button type="button" class="cg-badge cg-badge--info" style="background:#f1f5f9;border-color:#cbd5e1;color:#64748b;cursor:not-allowed;width:100%;text-align:center;box-shadow:0 2px 0 #cbd5e1" disabled>(Diajukan)</button>
            <?php else: ?>
            <button type="button" class="cg-badge cg-badge--info" style="background:#e0f2fe;border-color:#38bdf8;color:#075985;cursor:pointer;width:100%;text-align:center;box-shadow:0 2px 0 #38bdf8" onclick="openRefundModal(<?= $w['id'] ?>)">Ajukan Refund</button>
            <?php endif; ?>
          </div>
          <?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</div>

<!-- Refund Modal -->
<div id="refund-modal" class="cg-modal">
  <div class="cg-modal-card" style="border-color:#0369a1;box-shadow:0 8px 0 #0369a1">
    <div class="cg-mc-hdr" style="color:#0284c7">💸 Ajukan Refund WD?</div>
    <div class="cg-mc-sub">Kamu yakin ingin mengajukan pengembalian saldo dari penarikan yang ditahan ini?</div>
    
    <div style="background:#f0f9ff;border:2.5px solid #bae6fd;border-radius:14px;padding:12px;margin-bottom:16px;font-size:11px;font-weight:800;color:#0369a1;line-height:1.4">
      Saldo akan dikembalikan utuh ke <strong>Saldo Tarik</strong> jika pengajuan disetujui oleh admin.
    </div>
    
    <form method="POST" id="refund-form">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="refund_wd_hold">
      <input type="hidden" name="wd_id" id="refund-wd-id" value="">
      <div class="cg-btn-row">
        <button type="button" class="cg-btn cg-btn--cancel" onclick="closeRefundModal()">Batal</button>
        <button type="submit" class="cg-btn" style="background:linear-gradient(135deg, #38bdf8, #0ea5e9);color:#fff;border-color:#fff;box-shadow:0 4px 0 #0284c7;text-shadow:0 1px 1px rgba(0,0,0,0.3)" onclick="this.disabled=true;this.textContent='Memproses...';document.getElementById('refund-form').submit();">Ajukan Sekarang</button>
      </div>
    </form>
  </div>
</div>

<script>
function openRefundModal(wdId) {
  document.getElementById('refund-wd-id').value = wdId;
  document.getElementById('refund-modal').style.display = 'flex';
  document.body.style.overflow = 'hidden';
}
function closeRefundModal() {
  document.getElementById('refund-modal').style.display = 'none';
  document.body.style.overflow = '';
}
document.getElementById('refund-modal').addEventListener('click', function(e) {
  if (e.target === this) closeRefundModal();
});
</script>

<?php require dirname(__DIR__) . '/partials/footer.php'; ?>
