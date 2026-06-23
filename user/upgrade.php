<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/auth/guard.php';

$memberships = $pdo->query("SELECT * FROM memberships WHERE is_active=1 ORDER BY sort_order ASC")->fetchAll();
$flash = $flashType = '';

// Active membership info
$active_membership = null;
$can_refund = false;
$user_settings = ['refund_cut_percent' => 20, 'is_refund_enabled' => 1];

if ($user['membership_id'] && $user['membership_expires_at'] && strtotime($user['membership_expires_at']) > time()) {
    $ms = $pdo->prepare("SELECT * FROM memberships WHERE id=?");
    $ms->execute([$user['membership_id']]);
    $active_membership = $ms->fetch();
    
    $uSet = $pdo->prepare("SELECT refund_cut_percent, is_refund_enabled FROM users WHERE id=?");
    $uSet->execute([$user['id']]);
    $user_settings = $uSet->fetch() ?: $user_settings;
    
    if ($user_settings['is_refund_enabled']) {
        $lastUp = $pdo->prepare("SELECT confirmed_at FROM upgrade_orders WHERE user_id=? AND membership_id=? AND status='confirmed' ORDER BY id DESC LIMIT 1");
        $lastUp->execute([$user['id'], $active_membership['id']]);
        $last_confirmed = $lastUp->fetchColumn();
        if ($last_confirmed && strtotime($last_confirmed) > time() - (12 * 3600)) {
            $can_refund = true;
        }
    }
}

// AJAX Check Voucher
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'check_voucher') {
    header('Content-Type: application/json');
    $code = strtoupper(trim($_POST['code'] ?? ''));
    $mid  = (int)($_POST['membership_id'] ?? 0);
    
    if (!$code) { echo json_encode(['error' => 'Masukkan kode voucher.']); exit; }
    if (!$mid) { echo json_encode(['error' => 'Pilih paket terlebih dahulu.']); exit; }
    
    $stmt = $pdo->prepare("SELECT * FROM discount_vouchers WHERE code = ?");
    $stmt->execute([$code]);
    $v = $stmt->fetch();
    
    if (!$v) { echo json_encode(['error' => 'Kode voucher tidak ditemukan atau tidak valid.']); exit; }
    if ($v['expires_at'] && strtotime($v['expires_at']) < time()) { echo json_encode(['error' => 'Voucher ini sudah kedaluwarsa.']); exit; }
    if ($v['max_claims'] > 0 && $v['claims_count'] >= $v['max_claims']) { echo json_encode(['error' => 'Kuota voucher ini sudah habis.']); exit; }
    
    $chk = $pdo->prepare("SELECT id FROM user_discount_claims WHERE user_id = ? AND voucher_id = ?");
    $chk->execute([$user['id'], $v['id']]);
    if ($chk->fetch()) { echo json_encode(['error' => 'Kamu sudah menggunakan voucher ini sebelumnya.']); exit; }
    
    $discounts = json_decode($v['discounts'], true) ?: [];
    if (!isset($discounts[$mid])) { echo json_encode(['error' => 'Voucher ini tidak dapat digunakan untuk paket pilihanmu.']); exit; }
    
    $discount_pct = (int)$discounts[$mid];
    
    $ms = $pdo->prepare("SELECT price FROM memberships WHERE id=? AND is_active=1");
    $ms->execute([$mid]);
    $price = (float)$ms->fetchColumn();
    if (!$price) { echo json_encode(['error' => 'Paket tidak valid.']); exit; }
    
    $discount_amount = ($price * $discount_pct) / 100;
    $final_price     = $price - $discount_amount;
    
    echo json_encode([
        'ok' => true,
        'discount_pct' => $discount_pct,
        'discount_amount' => $discount_amount,
        'discount_amount_formatted' => format_rp($discount_amount),
        'final_price' => $final_price,
        'final_price_formatted' => format_rp($final_price)
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'refund_level') {
        $stmtPending = $pdo->prepare("SELECT id FROM admin_requests WHERE user_id=? AND type='refund_level' AND status='pending'");
        $stmtPending->execute([$user['id']]);
        
        if ($stmtPending->fetchColumn()) {
            $flash = '❌ Permintaan pengembalian dana kamu sebelumnya masih dalam proses verifikasi otomatis.'; $flashType = 'error';
        } else {
            $s = $pdo->prepare("SELECT m.id as membership_id, m.name, m.price, u.refund_cut_percent, u.is_refund_enabled FROM users u LEFT JOIN memberships m ON u.membership_id = m.id WHERE u.id=?");
            $s->execute([$user['id']]);
            $uInfo = $s->fetch();
            $mName = $uInfo['name'] ?? null;
            
            if (!$mName) {
                $flash = '❌ Kamu tidak memiliki paket aktif.'; $flashType = 'error';
            } elseif (!$uInfo['is_refund_enabled']) {
                $flash = '❌ Akses refund kamu telah dinonaktifkan.'; $flashType = 'error';
            } else {
                $pdo->prepare("INSERT INTO admin_requests (user_id, type) VALUES (?, 'refund_level')")->execute([$user['id']]);
                $req_id = $pdo->lastInsertId();
                
                $oStmt = $pdo->prepare("SELECT amount FROM upgrade_orders WHERE user_id=? AND membership_id=? AND status='confirmed' ORDER BY id DESC LIMIT 1");
                $oStmt->execute([$user['id'], $uInfo['membership_id']]);
                $basePrice = (float)$oStmt->fetchColumn();
                if (!$basePrice) $basePrice = (float)$uInfo['price'];
                
                $pct = (float)$uInfo['refund_cut_percent'];
                $cutAmount = ($basePrice * $pct) / 100;
                $afterCut = $basePrice - $cutAmount;
                
                $msg  = "💰 <b>REQUEST REFUND LEVEL</b>\n\n";
                $msg .= "👤 User: <code>{$user['username']}</code>\n";
                $msg .= "🏆 Level: <b>{$mName}</b>\n";
                $msg .= "💵 Harga Awal: <b>" . format_rp($basePrice) . "</b>\n";
                $msg .= "✂️ Setelah Dipotong ({$pct}%): <b>" . format_rp($afterCut) . "</b>\n\n";
                $msg .= "⚠️ <i>Refund ini akan membatalkan level user dan mengembalikan saldo dengan potongan {$pct}% (jika di-Approve).</i>\n";
                $kb = [
                    [['text'=>'✅ Approve Refund', 'callback_data'=>'req_approve_'.$req_id], ['text'=>'❌ Reject', 'callback_data'=>'req_reject_'.$req_id]],
                    [['text'=>"⚙️ Ubah Potongan ({$pct}%)", 'callback_data'=>'edit_refcut_'.$user['id']], ['text'=>'🔒 Cabut Akses Refund', 'callback_data'=>'toggle_ref_'.$user['id']]]
                ];
                send_telegram_notif($pdo, $msg, $kb, 'permintaan');
                
                $flash = '✅ Permintaan pengembalian dana kamu telah masuk dan sedang diverifikasi oleh sistem secara otomatis.';
            }
        }
        goto end_post;
    }

    $mid = (int)($_POST['membership_id'] ?? 0);
    $ms  = $pdo->prepare("SELECT * FROM memberships WHERE id=? AND is_active=1");
    $ms->execute([$mid]);
    $chosen = $ms->fetch();

    if (!$chosen) {
        $flash = 'Duh, paketnya gak ketemu nih.'; $flashType = 'error';
    } elseif ((float)$chosen['price'] == 0) {
        $flash = 'Paket Free gak usah diupgrade ya!'; $flashType = 'error';
    } else {
        $voucher_code = strtoupper(trim($_POST['voucher_code'] ?? ''));
        $price = (float)$chosen['price'];
        $final_price = $price;
        $v_data = null;
        
        if ($voucher_code !== '') {
            $v_stmt = $pdo->prepare("SELECT * FROM discount_vouchers WHERE code = ? FOR UPDATE");
            $v_stmt->execute([$voucher_code]);
            $v_data = $v_stmt->fetch();
            
            if (!$v_data) {
                $flash = 'Kode vouchermu gak valid nih.'; $flashType = 'error';
                goto end_post;
            }
            if ($v_data['expires_at'] && strtotime($v_data['expires_at']) < time()) {
                $flash = 'Wah, voucher diskon ini udah kedaluwarsa.'; $flashType = 'error';
                goto end_post;
            }
            if ($v_data['max_claims'] > 0 && $v_data['claims_count'] >= $v_data['max_claims']) {
                $flash = 'Kuota voucher diskon ini udah abis ya.'; $flashType = 'error';
                goto end_post;
            }
            
            $chk = $pdo->prepare("SELECT id FROM user_discount_claims WHERE user_id = ? AND voucher_id = ?");
            $chk->execute([$user['id'], $v_data['id']]);
            if ($chk->fetch()) {
                $flash = 'Kamu udah pernah pakai voucher diskon ini sebelumnya.'; $flashType = 'error';
                goto end_post;
            }
            
            $discounts = json_decode($v_data['discounts'], true) ?: [];
            if (!isset($discounts[$mid])) {
                $flash = 'Voucher diskon ini gak bisa dipakai buat paket pilihanmu ya.'; $flashType = 'error';
                goto end_post;
            }
            
            $pct = (int)$discounts[$mid];
            $discount_amount = ($price * $pct) / 100;
            $final_price = $price - $discount_amount;
        }
        
        if ((float)$user['balance_dep'] < $final_price) {
            $flash = 'Saldo Beli kamu kurang nih. Yuk deposit dulu!'; $flashType = 'error';
        } else {
            try {
                $pdo->beginTransaction();
                
                $stmt = $pdo->prepare("UPDATE users SET balance_dep=balance_dep-? WHERE id=? AND balance_dep >= ?");
                $stmt->execute([$final_price, $user['id'], $final_price]);
                
                if ($stmt->rowCount() > 0) {
                    if ($v_data) {
                        $v_lock = $pdo->prepare("SELECT claims_count, max_claims FROM discount_vouchers WHERE id = ? FOR UPDATE");
                        $v_lock->execute([$v_data['id']]);
                        $v_current = $v_lock->fetch();
                        if ($v_current['max_claims'] > 0 && $v_current['claims_count'] >= $v_current['max_claims']) {
                            throw new \Exception("Kuota voucher diskon sudah habis.");
                        }
                        
                        $pdo->prepare("INSERT INTO user_discount_claims (user_id, voucher_id) VALUES (?, ?)")
                            ->execute([$user['id'], $v_data['id']]);
                        
                        $pdo->prepare("UPDATE discount_vouchers SET claims_count = claims_count + 1 WHERE id = ?")
                            ->execute([$v_data['id']]);
                    }
                    
                    $pdo->prepare("INSERT INTO upgrade_orders (user_id,membership_id,amount,status,confirmed_at) VALUES (?,?,?,'confirmed',NOW())")
                        ->execute([$user['id'], $mid, $final_price]);
                    
                    $new_expires = date('Y-m-d H:i:s', strtotime("+{$chosen['duration_days']} days"));
                    $pdo->prepare("UPDATE users SET membership_id=?, membership_expires_at=? WHERE id=?")
                        ->execute([$mid, $new_expires, $user['id']]);
                    
                    $pdo->commit();
                    
                    $us = $pdo->prepare("SELECT * FROM users WHERE id=?"); $us->execute([$user['id']]); $user = $us->fetch();
                    $flash = '🎉 Hore! Upgrade ke ' . htmlspecialchars($chosen['name']) . ' berhasil! Berlaku s/d ' . date('d M Y', strtotime($new_expires)) . ' ya.';
                    $active_membership = $chosen;
                    $can_refund = true; // just upgraded, well within 12 hours
                    
                    $msgNotif = "🎉 <b>MEMBER UPGRADE LEVEL</b>\n\n";
                    $msgNotif .= "👤 User: <code>{$user['username']}</code>\n";
                    $msgNotif .= "🏆 Level Baru: <b>{$chosen['name']}</b>\n";
                    $msgNotif .= "💰 Harga: " . format_rp((float)$final_price) . "\n";
                    $msgNotif .= "🕐 Waktu: " . date('d M Y H:i:s');
                    send_telegram_notif($pdo, $msgNotif, [], 'log');
                } else {
                    $pdo->rollBack();
                    $flash = 'Saldo Beli kamu kurang nih. Transaksi gagal ya.'; $flashType = 'error';
                }
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $flash = 'Terjadi kesalahan: ' . $e->getMessage(); $flashType = 'error';
            }
        }
    }
}
end_post:

$pageTitle  = 'Upgrade Paket — Meloton';
$activePage = 'upgrade';
require dirname(__DIR__) . '/partials/header.php';
?>

<div class="page-title-bar">
  <h1>👑 Upgrade Paket</h1>
  <p>Tonton lebih banyak, earn lebih besar!</p>
</div>

<!-- How it works -->
<div class="card" style="margin-bottom:14px;border-left:4px solid var(--yellow)">
  <div class="card__body" style="padding:14px 16px">
    <div style="font-size:13px;font-weight:800;margin-bottom:10px">📖 Cara Kerja Upgrade</div>
    <div style="display:flex;flex-direction:column;gap:8px;font-size:12px;color:#555">
      <div style="display:flex;gap:10px;align-items:flex-start">
        <span style="background:var(--yellow);border:1.5px solid var(--ink);border-radius:50%;width:22px;height:22px;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:900;flex-shrink:0">1</span>
        <span><strong>Deposit saldo</strong> terlebih dahulu melalui menu Deposit (transfer bank atau QRIS). Saldo Beli dipakai khusus untuk pembelian paket.</span>
      </div>
      <div style="display:flex;gap:10px;align-items:flex-start">
        <span style="background:var(--yellow);border:1.5px solid var(--ink);border-radius:50%;width:22px;height:22px;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:900;flex-shrink:0">2</span>
        <span><strong>Pilih paket</strong> membership yang sesuai budget & kebutuhanmu di bawah ini.</span>
      </div>
      <div style="display:flex;gap:10px;align-items:flex-start">
        <span style="background:var(--yellow);border:1.5px solid var(--ink);border-radius:50%;width:22px;height:22px;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:900;flex-shrink:0">3</span>
        <span><strong>Konfirmasi upgrade</strong> — harga paket langsung dipotong dari Saldo Beli, membership aktif seketika!</span>
      </div>
    </div>
  </div>
</div>

<!-- Benefits info -->
<div class="card" style="margin-bottom:14px;border-left:4px solid var(--mint)">
  <div class="card__body" style="padding:14px 16px">
    <div style="font-size:13px;font-weight:800;margin-bottom:8px">✅ Keuntungan Paket Berbayar</div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px 10px;font-size:12px;color:#555">
      <div>📹 Limit tonton lebih banyak/hari</div>
      <div>💸 Min. WD lebih rendah</div>
      <div>📈 Potensi earn lebih besar</div>
      <div>💰 Max. WD lebih tinggi</div>
      <div>⚡ Akses fitur eksklusif</div>
      <div>🎯 Prioritas dukungan admin</div>
    </div>
  </div>
</div>

<?php if ($flash): ?>
<div class="alert alert--<?= $flashType === 'error' ? 'error' : 'success' ?>"><?= htmlspecialchars($flash) ?></div>
<?php endif; ?>

<!-- Balance dep info -->
<div class="hero-card" style="background:var(--yellow);margin-bottom:16px">
  <div class="hero-card__label">💳 Saldo Beli (untuk Upgrade)</div>
  <div class="hero-card__amount"><?= format_rp((float)$user['balance_dep']) ?></div>
  <div class="hero-card__sub">Upgrade langsung dipotong dari saldo beli</div>
  <div class="hero-card__actions">
    <a href="/deposit" class="hero-card__btn">⬆️ Tambah Deposit</a>
    <a href="/checkin" class="hero-card__btn">📅 Check-in</a>
  </div>
</div>

<?php if ($active_membership): ?>
<div class="alert alert--info" style="margin-bottom:16px">
  ⭐ Paket aktif: <strong><?= htmlspecialchars($active_membership['name']) ?></strong>
  — Limit <?= $active_membership['watch_limit'] ?>× /hari,
  berlaku s/d <?= date('d M Y', strtotime($user['membership_expires_at'])) ?>
  
  <div style="margin-top:10px;">
    <?php if ($can_refund): ?>
    <button type="button" class="btn" onclick="document.getElementById('brutal-refund-confirm').style.display='flex'" style="background:#fff;border:2px solid var(--red);color:var(--red);font-size:13px;font-weight:900;box-shadow:3px 3px 0 var(--red); white-space: normal; padding: 12px 16px; width: 100%; text-align: center; display: flex; align-items: center; justify-content: center; gap: 8px; line-height: 1.4; border-radius: 12px; margin-top: 12px; cursor: pointer;">
      <span style="font-size:16px;">⏪</span> 
      <span>Minta Pengembalian Uang (Refund)</span>
    </button>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<!-- Packages -->
<form method="POST" id="upgrade-form">
  <?= csrf_field() ?>
  <input type="hidden" name="membership_id" id="chosen-id" value="">
  <input type="hidden" name="voucher_code" id="applied-voucher-code" value="">
  <div style="display:flex;flex-direction:column;gap:16px;margin-bottom:20px">
    <?php $colors = ['#FF6B35','#4E9BFF','#9C6FFF','#4CAF82'];
    foreach ($memberships as $i => $m):
      if ((float)$m['price'] == 0) continue;
      $color = $colors[$i % count($colors)];
      $can_afford = (float)$user['balance_dep'] >= (float)$m['price'];
    ?>
    <div class="membership-card" id="card-<?= $m['id'] ?>"
         style="transition:all .2s; padding:14px; position:relative; border:2px solid var(--ink); border-radius:16px; background:#fff; box-shadow:3px 3px 0 var(--ink)">
      
      <?php if ($i === 2): ?>
      <div style="position:absolute;top:-10px;right:-10px;background:#FF6B35;color:#fff;font-size:10px;font-weight:900;padding:4px 8px;border-radius:12px;border:1.5px solid var(--ink);transform:rotate(4deg);z-index:2">🔥 Populer</div>
      <?php endif; ?>
      
      <?php if ((float)$m['original_price'] > 0): ?>
      <div style="position:absolute;top:-10px;left:-10px;background:#4CAF82;color:#fff;font-size:10px;font-weight:900;padding:4px 8px;border-radius:12px;border:1.5px solid var(--ink);transform:rotate(-4deg);z-index:2">🎉 PROMO DISKON!</div>
      <?php endif; ?>
      
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;margin-top:2px">
        <div style="display:flex;align-items:center;gap:10px">
          <div style="width:40px;height:40px;background:<?= $color ?>22;border-radius:10px;border:1.5px solid <?= $color ?>;display:flex;align-items:center;justify-content:center;font-size:20px">
            <?= htmlspecialchars($m['icon'] ?: '⭐') ?>
          </div>
          <div>
            <div style="font-size:15px;font-weight:900;color:<?= $color ?>;line-height:1.1;margin-bottom:2px"><?= htmlspecialchars($m['name']) ?></div>
            <div style="font-size:11px;color:#888;font-weight:700">⏳ <?= $m['duration_days'] ?> Hari</div>
          </div>
        </div>
        <div style="text-align:right">
          <?php if ((float)$m['original_price'] > 0): ?>
          <div style="font-size:11px;color:#999;text-decoration:line-through;margin-bottom:-2px;font-weight:700"><?= format_rp((float)$m['original_price']) ?></div>
          <?php endif; ?>
          <div style="font-size:17px;font-weight:900;line-height:1"><?= format_rp((float)$m['price']) ?></div>
        </div>
      </div>
      
      <div style="background:var(--brand-soft,#f4f6f8);border:1.5px solid #e0e4e8;border-radius:10px;padding:8px 10px;margin-bottom:12px;font-size:11.5px;color:#555;display:grid;grid-template-columns:1fr 1fr;gap:6px;font-weight:600">
        <div>📹 <?= $m['watch_limit'] ?>× / hari</div>
        <?php if ((float)$m['min_wd'] > 0): ?><div>💸 Min. WD: <?= format_rp((float)$m['min_wd']) ?></div><?php endif; ?>
        <div style="grid-column:1/-1">📤 Max. WD: <?= (float)$m['max_wd'] > 0 ? format_rp((float)$m['max_wd']) : '<span style="color:#4CAF82;font-weight:900">Tanpa batas</span>' ?></div>
        <?php if ($m['description']): ?><div style="grid-column:1/-1;color:#888;font-size:11px;margin-top:2px;font-weight:500;line-height:1.4">ℹ️ <?= nl2br(htmlspecialchars($m['description'])) ?></div><?php endif; ?>
      </div>
      
      <div style="display:flex;gap:8px;align-items:center">
        <button type="button" class="btn btn--primary" style="flex:1;font-size:13px;padding:9px;height:auto;box-shadow:3px 3px 0 var(--ink)"
          onclick="openConfirm(<?= $m['id'] ?>, '<?= htmlspecialchars($m['name'], ENT_QUOTES) ?>', <?= (float)$m['price'] ?>, <?= $m['duration_days'] ?>)">
          🚀 Upgrade Sekarang
        </button>
        <?php if (!$can_afford): ?>
        <div style="font-size:22px;cursor:help;filter:grayscale(1);opacity:0.6" title="Saldo Kurang / Gunakan Voucher">🎟️</div>
        <?php else: ?>
        <div style="font-size:22px;cursor:help" title="Saldo Cukup">✅</div>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</form>

<!-- Confirmation Modal -->
<div id="upgrade-modal" style="display:none;position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,.55);align-items:flex-end;justify-content:center">
  <div style="background:#fff;border-radius:20px 20px 0 0;border:2.5px solid var(--ink);padding:24px 20px 32px;width:100%;max-width:480px;box-shadow:0 -4px 0 var(--ink);animation:slideUp .25s ease">
    <div style="font-size:18px;font-weight:900;margin-bottom:6px">🚀 Konfirmasi Upgrade</div>
    <div style="font-size:13px;color:#555;margin-bottom:16px">Pastikan kamu yakin sebelum melanjutkan.</div>
    <div style="background:var(--yellow);border:2px solid var(--ink);border-radius:12px;padding:14px 16px;margin-bottom:16px">
      <div style="font-size:12px;color:#666;font-weight:700">Paket dipilih</div>
      <div style="font-size:18px;font-weight:900" id="modal-name">—</div>
      <div style="font-size:13px;font-weight:700;margin-top:4px" id="price-row">Harga: <span id="modal-price">—</span></div>
      <div style="font-size:13px;font-weight:700;margin-top:4px;color:#FF6B35;display:none" id="discount-row">Diskon: -<span id="modal-discount">—</span> (<span id="modal-pct">—</span>)</div>
      <div style="font-size:15px;font-weight:900;margin-top:4px;border-top:1.5px dashed var(--ink);padding-top:4px;display:none" id="final-price-row">Total Bayar: <span id="modal-final-price">—</span></div>
      <div style="font-size:12px;color:#666;margin-top:4px">Berlaku <span id="modal-days">—</span> hari setelah aktivasi</div>
    </div>
    
    <!-- Voucher Coupon Section -->
    <div style="margin-bottom:16px;">
      <button type="button" id="toggle-voucher-btn" onclick="toggleVoucherInput()" style="background:none;border:none;color:var(--brand);font-weight:800;font-size:12px;cursor:pointer;padding:0;text-decoration:underline;outline:none;">🎟️ Apa km punya voucher diskon?</button>
      <div id="voucher-input-container" style="display:none;margin-top:8px;gap:8px;">
        <input type="text" id="voucher-code-input" placeholder="KODE VOUCHER" style="flex:1;border:2px solid var(--ink);border-radius:8px;padding:6px 12px;font-weight:900;text-transform:uppercase;font-size:12px;outline:none;">
        <button type="button" onclick="applyVoucher()" style="background:var(--brand);color:#fff;border:2px solid var(--ink);border-radius:8px;padding:6px 12px;font-weight:900;font-size:12px;cursor:pointer;box-shadow:2px 2px 0 var(--ink);">Gunakan</button>
      </div>
      <div id="voucher-message" style="font-size:11px;font-weight:700;margin-top:4px;display:none;"></div>
    </div>

    <!-- Balance Warning -->
    <div id="modal-balance-warning" style="display:none;font-size:12px;color:#F44E3B;font-weight:700;margin-bottom:12px;background:#FFF0EE;border:1.5px solid var(--ink);border-radius:8px;padding:8px 10px;"></div>

    <div style="font-size:12px;color:#888;margin-bottom:16px">⚠️ Saldo Beli akan dipotong langsung. Aksi ini tidak bisa dibatalkan.</div>
    <div style="display:flex;gap:8px">
      <button type="button" class="btn btn--full" style="flex:1;font-size:13px" onclick="closeConfirm()">✖ Batal</button>
      <button type="button" id="modal-confirm-btn" class="btn btn--primary btn--full" style="flex:1;font-size:13px;font-weight:900" onclick="submitUpgrade()">✅ Ya, Upgrade!</button>
    </div>
  </div>
</div>

<!-- FAQ / Notes -->
<div class="card" style="margin-top:14px;margin-bottom:8px">
  <div class="card__body" style="padding:14px 16px">
    <div style="font-size:13px;font-weight:800;margin-bottom:8px">❓ Yang Perlu Kamu Tahu</div>
    <div style="display:flex;flex-direction:column;gap:8px;font-size:12px;color:#555">
      <div>🔄 <strong>Upgrade saat masih aktif</strong> akan <em>mengganti</em> paket yang berjalan. Durasi dimulai ulang dari sekarang.</div>
      <div>💳 <strong>Saldo Beli ≠ Saldo Penarikan.</strong> Saldo Beli hanya bisa dipakai beli paket, bukan ditarik langsung.</div>
      <div>💸 <strong>Limit Withdraw</strong> (Min & Max) mengikuti paket aktifmu. Upgrade untuk memperbesar limit withdraw.</div>
      <div>⚡ <strong>Aktivasi instan</strong> — tidak perlu menunggu konfirmasi admin. Paket langsung aktif begitu kamu klik upgrade.</div>
      <div>📅 <strong>Paket expired</strong> berarti kamu kembali ke limit free. Pastikan selalu perpanjang sebelum habis!</div>
    </div>
  </div>
</div>

<style>
@keyframes slideUp { from { transform:translateY(100%); opacity:0; } to { transform:translateY(0); opacity:1; } }
</style>
<script>
const userBalance = <?= (float)$user['balance_dep'] ?>;

function checkAffordability(finalPrice) {
  const btn = document.getElementById('modal-confirm-btn');
  const warnEl = document.getElementById('modal-balance-warning');
  if (userBalance < finalPrice) {
    btn.disabled = true;
    btn.style.opacity = '0.5';
    btn.style.cursor = 'not-allowed';
    btn.innerText = '💳 Saldo Kurang';
    if (warnEl) {
      warnEl.style.display = 'block';
      warnEl.innerHTML = '⚠️ Saldo Beli tidak mencukupi (Kurang <strong>Rp ' + (finalPrice - userBalance).toLocaleString('id-ID') + '</strong>). Silakan gunakan voucher diskon atau isi saldo beli Anda.';
    }
  } else {
    btn.disabled = false;
    btn.style.opacity = '1';
    btn.style.cursor = 'pointer';
    btn.innerText = '✅ Ya, Upgrade!';
    if (warnEl) warnEl.style.display = 'none';
  }
}

function openConfirm(id, name, price, days) {
  document.getElementById('chosen-id').value = id;
  document.getElementById('modal-name').textContent  = name;
  document.getElementById('modal-price').textContent = 'Rp ' + price.toLocaleString('id-ID');
  document.getElementById('modal-days').textContent  = days;
  
  // Reset voucher states
  document.getElementById('applied-voucher-code').value = '';
  const codeInput = document.getElementById('voucher-code-input');
  if (codeInput) codeInput.value = '';
  const msgEl = document.getElementById('voucher-message');
  if (msgEl) msgEl.style.display = 'none';
  const container = document.getElementById('voucher-input-container');
  if (container) container.style.display = 'none';
  const toggleBtn = document.getElementById('toggle-voucher-btn');
  if (toggleBtn) {
    toggleBtn.style.display = 'inline-block';
    toggleBtn.innerText = '🎟️ Apa km punya voucher diskon?';
  }
  
  document.getElementById('discount-row').style.display = 'none';
  document.getElementById('final-price-row').style.display = 'none';
  
  // Check if balance is enough for original price
  checkAffordability(price);
  
  const m = document.getElementById('upgrade-modal');
  m.style.display = 'flex';
  document.body.style.overflow = 'hidden';
}
function closeConfirm() {
  document.getElementById('upgrade-modal').style.display = 'none';
  document.body.style.overflow = '';
}
function toggleVoucherInput() {
  const container = document.getElementById('voucher-input-container');
  const toggleBtn = document.getElementById('toggle-voucher-btn');
  if (container.style.display === 'none') {
    container.style.display = 'flex';
    toggleBtn.innerText = '✖ Tutup';
  } else {
    container.style.display = 'none';
    toggleBtn.innerText = '🎟️ Apa km punya voucher diskon?';
  }
}
function applyVoucher() {
  const codeInput = document.getElementById('voucher-code-input');
  const code = codeInput.value.toUpperCase().trim();
  const mid = document.getElementById('chosen-id').value;
  const msgEl = document.getElementById('voucher-message');
  
  if (!code) {
    msgEl.style.color = '#F44E3B';
    msgEl.innerText = '⚠️ Masukkan kode voucher.';
    msgEl.style.display = 'block';
    return;
  }
  
  msgEl.style.color = '#777';
  msgEl.innerText = '⏳ Mengecek voucher...';
  msgEl.style.display = 'block';
  
  fetch('', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: 'action=check_voucher&code=' + encodeURIComponent(code) + '&membership_id=' + encodeURIComponent(mid) + '&_csrf=' + encodeURIComponent(document.querySelector('input[name="_csrf"]')?.value || '')
  })
  .then(r => r.json())
  .then(res => {
    if (res.error) {
      msgEl.style.color = '#F44E3B';
      msgEl.innerText = '❌ ' + res.error;
      msgEl.style.display = 'block';
      
      document.getElementById('applied-voucher-code').value = '';
      document.getElementById('discount-row').style.display = 'none';
      document.getElementById('final-price-row').style.display = 'none';
      
      // Reset affordability check to original price
      const originalPrice = parseFloat(document.getElementById('modal-price').textContent.replace(/[^0-9]/g, ''));
      checkAffordability(originalPrice);
    } else {
      msgEl.style.color = '#4CAF82';
      msgEl.innerText = '✅ Diskon ' + res.discount_pct + '% diterapkan!';
      msgEl.style.display = 'block';
      
      document.getElementById('applied-voucher-code').value = code;
      
      document.getElementById('modal-discount').textContent = res.discount_amount_formatted;
      document.getElementById('modal-pct').textContent = res.discount_pct + '%';
      document.getElementById('modal-final-price').textContent = res.final_price_formatted;
      
      document.getElementById('discount-row').style.display = 'block';
      document.getElementById('final-price-row').style.display = 'block';
      
      document.getElementById('voucher-input-container').style.display = 'none';
      document.getElementById('toggle-voucher-btn').style.display = 'none';
      
      // Check affordability based on final price
      checkAffordability(res.final_price);
    }
  })
  .catch(err => {
    msgEl.style.color = '#F44E3B';
    msgEl.innerText = '❌ Gagal mengecek voucher.';
    msgEl.style.display = 'block';
  });
}
function submitUpgrade() {
  const btn = document.getElementById('modal-confirm-btn');
  btn.disabled = true;
  btn.textContent = '⏳ Memproses...';
  document.getElementById('upgrade-form').submit();
}
// Close on backdrop click
document.getElementById('upgrade-modal').addEventListener('click', function(e) {
  if (e.target === this) closeConfirm();
});
</script>
<!-- Neo-brutalism Refund Confirm Modal -->
<div id="brutal-refund-confirm" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.6);z-index:9999;align-items:center;justify-content:center;padding:20px;backdrop-filter:blur(3px)">
  <div class="card card--danger" style="width:100%;max-width:340px;box-shadow:6px 6px 0 var(--red);border:3px solid var(--red);border-radius:12px;animation:popIn .3s cubic-bezier(.175,.885,.32,1.275)">
    <div class="card__header" style="background:var(--red);border-bottom:3px solid var(--red);border-radius:9px 9px 0 0;padding:12px 16px">
      <div class="card__title" style="color:#fff;font-weight:900;font-size:15px">⚠️ Konfirmasi Pengembalian</div>
    </div>
    <div class="card__body" style="padding:16px;background:#fff;border-radius:0 0 9px 9px">
      <div style="font-size:13px;font-weight:700;margin-bottom:14px;color:#333;line-height:1.5">
        Yakin ingin meminta pengembalian uang dari pembelian level Anda?
      </div>
      <div style="font-size:12px;color:#d35400;font-weight:700;margin-bottom:16px;background:#fdf2e9;padding:10px 12px;border-radius:8px;border:1.5px dashed #e67e22;line-height:1.4">
        ⚠️ <strong>Penting:</strong> Uang yang dikembalikan akan masuk kembali ke <strong>Saldo Beli</strong> (bukan transfer ke rekening bank), dan nominalnya akan dikenakan biaya potongan sesuai kebijakan yang berlaku.
      </div>
      <div style="font-size:11px;color:#c0392b;font-weight:700;margin-bottom:20px;text-align:center">
        Level aktif Anda saat ini akan dibatalkan saat pengajuan ini disetujui.
      </div>
      
      <form method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="refund_level">
        <div style="display:flex;gap:10px">
          <button type="button" class="btn btn--ghost" style="flex:1;font-size:13px;border:2px solid #ccc;color:#555" onclick="document.getElementById('brutal-refund-confirm').style.display='none'">Batal</button>
          <button type="submit" class="btn btn--primary" style="flex:1;font-size:13px;background:var(--red);color:#fff;border-color:var(--red);box-shadow:3px 3px 0 #8b0000">Ya, Minta Refund</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php require dirname(__DIR__) . '/partials/footer.php'; ?>
