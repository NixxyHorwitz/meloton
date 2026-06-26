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
    
    if (isset($discounts['*'])) {
        $val = $discounts['*'];
    } elseif (isset($discounts[$mid])) {
        $val = $discounts[$mid];
    } else {
        echo json_encode(['error' => 'Voucher ini tidak dapat digunakan untuk paket pilihanmu.']); exit;
    }
    
    $ms = $pdo->prepare("SELECT price FROM memberships WHERE id=? AND is_active=1");
    $ms->execute([$mid]);
    $price = (float)$ms->fetchColumn();
    if (!$price) { echo json_encode(['error' => 'Paket tidak valid.']); exit; }
    
    $is_rp = false;
    $pct = 0;
    if (is_string($val) && stripos($val, 'rp') !== false) {
        $discount_amount = (float)str_ireplace('rp', '', $val);
        $is_rp = true;
    } elseif (is_numeric($val) && $val > 100) {
        $discount_amount = (float)$val; // legacy format fallback
        $is_rp = true;
    } else {
        $pct = (float)$val;
        $discount_amount = ($price * $pct) / 100;
    }
    
    $final_price = $price - $discount_amount;
    if ($final_price < 0) $final_price = 0;
    
    $discount_text = $is_rp ? 'Rp ' . number_format($discount_amount, 0, ',', '.') : $pct . '%';
    
    echo json_encode([
        'ok' => true,
        'discount_text' => $discount_text,
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
            
            if (isset($discounts['*'])) {
                $val = $discounts['*'];
            } elseif (isset($discounts[$mid])) {
                $val = $discounts[$mid];
            } else {
                $flash = 'Voucher diskon ini gak bisa dipakai buat paket pilihanmu ya.'; $flashType = 'error';
                goto end_post;
            }
            
            if (is_string($val) && stripos($val, 'rp') !== false) {
                $discount_amount = (float)str_ireplace('rp', '', $val);
            } elseif (is_numeric($val) && $val > 100) {
                $discount_amount = (float)$val; // legacy format fallback
            } else {
                $pct = (float)$val;
                $discount_amount = ($price * $pct) / 100;
            }
            
            $final_price = $price - $discount_amount;
            if ($final_price < 0) $final_price = 0;
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

<style>
/* ══════════════════════════════════════════════
   UPGRADE PAGE — TRUE CASUAL GAME STYLE
   ══════════════════════════════════════════════ */
.up-page { padding: 0 0 20px; }

/* ── Hero Banner ── */
.up-hero {
  background: linear-gradient(135deg, #0ea5e9, #0284c7);
  border: 3px solid #0369a1;
  border-radius: 20px;
  box-shadow: 0 6px 0 #075985;
  padding: 16px;
  text-align: center;
  position: relative;
  overflow: hidden;
  margin-bottom: 16px;
}
.up-hero::before { content:''; position:absolute; top:-10px; left:-10px; width:60px; height:60px; background:url('/assets/dollar.png') no-repeat center/contain; opacity:0.15; transform:rotate(-15deg); pointer-events:none; }
.up-hero::after { content:''; position:absolute; bottom:-10px; right:-10px; width:80px; height:80px; background:rgba(255,255,255,0.1); border-radius:50%; pointer-events:none; }
.up-hero-star { position:absolute; top:12px; right:20px; color:#fde047; font-size:24px; opacity:0.6; transform:rotate(20deg); pointer-events:none; }
.up-hero__lbl { font-size:12px; font-weight:900; color:#e0f2fe; margin-bottom:4px; text-transform:uppercase; letter-spacing:1px; display:flex; align-items:center; justify-content:center; gap:6px; position:relative; z-index:1; }
.up-hero__val { font-size:20px; font-weight:900; color:#fef08a; text-shadow:0 2px 4px rgba(0,0,0,0.2); letter-spacing:-0.5px; position:relative; z-index:1; }

/* ── Balance Card ── */
.up-bal {
  background: linear-gradient(135deg, #fef08a, #fde047);
  border: 3px solid #d97706;
  border-radius: 20px;
  box-shadow: 0 6px 0 #b45309;
  padding: 16px;
  text-align: center;
  margin-bottom: 16px;
  position: relative;
}
.up-bal__lbl { font-size:12px; font-weight:900; color:#78350f; text-transform:uppercase; margin-bottom:4px; }
.up-bal__val { font-size:28px; font-weight:900; color:#b45309; letter-spacing:-1px; text-shadow:0 2px 0 rgba(255,255,255,0.5); margin-bottom:12px; display:flex; align-items:center; justify-content:center; gap:8px; }
.up-bal__actions { display:grid; grid-template-columns:1fr 1fr; gap:8px; }
.up-bal__btn {
  background: #fff; border: 2.5px solid #d97706; border-radius: 12px;
  color: #d97706; font-size: 12px; font-weight: 900; padding: 10px;
  box-shadow: 0 4px 0 #b45309; text-decoration: none; display: flex; align-items: center; justify-content: center; gap: 6px;
}
.up-bal__btn:active { transform:translateY(3px); box-shadow:0 1px 0 #b45309; }

/* ── Info Cards ── */
.up-card {
  background: #fff; border: 3px solid #7dd3e8; border-radius: 20px; box-shadow: 0 6px 0 #7dd3e8; padding: 16px; margin-bottom: 16px;
}
.up-card-title {
  font-size: 14px; font-weight: 900; color: #0369a1; display: flex; align-items: center; gap: 6px; margin-bottom: 12px;
  border-bottom: 2.5px solid #e0f2fe; padding-bottom: 10px; text-transform:uppercase; letter-spacing:0.5px;
}

/* Steps */
.up-step { display: flex; align-items: flex-start; gap: 12px; margin-bottom: 12px; }
.up-step:last-child { margin-bottom: 0; }
.up-step__num {
  width: 28px; height: 28px; border-radius: 8px; border: 2.5px solid #fff;
  display: flex; align-items: center; justify-content: center; font-size: 13px; font-weight: 900; flex-shrink: 0; color: #fff; text-shadow: 0 1px 2px rgba(0,0,0,0.2);
  background: linear-gradient(135deg, #a7f3d0, #10b981); box-shadow: 0 3px 0 #059669;
}
.up-step__txt { font-size: 12px; font-weight: 800; color: #334155; line-height: 1.4; padding-top: 4px; }

/* Benefits Grid */
.up-bens { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
.up-ben { display: flex; align-items: center; gap: 6px; font-size: 11px; font-weight: 800; color: #334155; background: #f0f9ff; padding: 8px; border-radius: 10px; border: 1.5px solid #bae6fd; }

/* ── Membership Cards ── */
.m-card {
  background: #fff; border: 3px solid #0f172a; border-radius: 20px; box-shadow: 0 6px 0 #0f172a;
  padding: 16px; margin-bottom: 16px; position: relative; transition: transform 0.1s;
}
.m-card:active { transform: translateY(2px); box-shadow: 0 4px 0 #0f172a; }

/* Dynamic Borders per index */
.m-card--0 { border-color: #64748b; box-shadow: 0 6px 0 #64748b; }
.m-card--1 { border-color: #0ea5e9; box-shadow: 0 6px 0 #0369a1; }
.m-card--2 { border-color: #f59e0b; box-shadow: 0 6px 0 #d97706; }
.m-card--3 { border-color: #8b5cf6; box-shadow: 0 6px 0 #6d28d9; }
.m-card--4 { border-color: #ef4444; box-shadow: 0 6px 0 #b91c1c; }

.m-badge-pop { position:absolute; top:-12px; right:-8px; background:linear-gradient(135deg, #ef4444, #b91c1c); color:#fff; font-size:10px; font-weight:900; padding:4px 10px; border-radius:12px; border:2px solid #fff; box-shadow:0 3px 0 #7f1d1d; transform:rotate(5deg); z-index:2; text-shadow:0 1px 1px rgba(0,0,0,0.3); }
.m-badge-pro { position:absolute; top:-12px; left:-8px; background:linear-gradient(135deg, #34d399, #10b981); color:#fff; font-size:10px; font-weight:900; padding:4px 10px; border-radius:12px; border:2px solid #fff; box-shadow:0 3px 0 #059669; transform:rotate(-5deg); z-index:2; text-shadow:0 1px 1px rgba(0,0,0,0.3); }

.m-hdr { display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px; }
.m-ico-box { width: 46px; height: 46px; border-radius: 12px; border: 2.5px solid #fff; display: flex; align-items: center; justify-content: center; font-size: 24px; box-shadow: 0 3px 0 rgba(0,0,0,0.1); }
.m-name { font-size: 16px; font-weight: 900; line-height: 1.1; margin-bottom: 2px; }
.m-dur { font-size: 11px; font-weight: 800; color: #64748b; display:flex; align-items:center; gap:4px; }
.m-price-box { text-align: right; }
.m-price-old { font-size: 11px; font-weight: 800; color: #94a3b8; text-decoration: line-through; margin-bottom: -2px; }
.m-price { font-size: 18px; font-weight: 900; letter-spacing: -0.5px; }

.m-specs { background: #f8fafc; border: 2px dashed #cbd5e1; border-radius: 14px; padding: 12px; display: grid; grid-template-columns: 1fr 1fr; gap: 8px; font-size: 11px; font-weight: 800; color: #475569; margin-bottom: 14px; }
.m-spec-full { grid-column: 1 / -1; }
.m-desc { grid-column: 1 / -1; color: #94a3b8; font-size: 10px; margin-top: 4px; font-weight: 700; line-height: 1.4; border-top: 2px dashed #e2e8f0; padding-top: 8px; }

.m-actions { display: flex; gap: 10px; align-items: center; }
.m-btn { flex: 1; border: 2.5px solid #fff; border-radius: 12px; color: #fff; font-size: 13px; font-weight: 900; padding: 12px; box-shadow: 0 4px 0 rgba(0,0,0,0.2); display: flex; align-items: center; justify-content: center; gap: 6px; cursor: pointer; text-shadow: 0 1px 1px rgba(0,0,0,0.2); }
.m-btn:active { transform: translateY(3px); box-shadow: 0 1px 0 rgba(0,0,0,0.2); }
.m-btn--buy { background: linear-gradient(135deg, #0ea5e9, #0284c7); }
.m-btn--disabled { background: #cbd5e1 !important; border-color: #f1f5f9; box-shadow: 0 4px 0 #94a3b8 !important; cursor: not-allowed; text-shadow: none; color: #64748b; }

/* Modal Override */
.cg-modal { display:none; position:fixed; inset:0; z-index:9999; background:rgba(0,0,0,.6); align-items:center; justify-content:center; backdrop-filter:blur(3px); padding:20px; }
.cg-modal-card { background:#fff; border-radius:24px; border:3px solid #0f172a; width:100%; max-width:380px; box-shadow:0 8px 0 #0f172a; animation:popIn .3s cubic-bezier(.175,.885,.32,1.275); padding:24px; position:relative; overflow:hidden; }
@keyframes popIn { 0% { transform:scale(0.8); opacity:0; } 100% { transform:scale(1); opacity:1; } }

.cg-mc-hdr { font-size: 18px; font-weight: 900; color: #0f172a; margin-bottom: 6px; display:flex; align-items:center; gap:8px; }
.cg-mc-sub { font-size: 12px; font-weight: 800; color: #64748b; margin-bottom: 16px; }

.cg-mc-box { background: #f0f9ff; border: 2.5px solid #bae6fd; border-radius: 16px; padding: 16px; margin-bottom: 16px; }
.cg-mc-lbl { font-size: 11px; font-weight: 800; color: #0284c7; text-transform: uppercase; margin-bottom: 4px; }
.cg-mc-val { font-size: 20px; font-weight: 900; color: #0c4a6e; letter-spacing: -0.5px; }
.cg-mc-price { font-size: 14px; font-weight: 900; color: #0f172a; margin-top: 8px; }
.cg-mc-discount { font-size: 14px; font-weight: 900; color: #ef4444; margin-top: 4px; display: none; }
.cg-mc-total { font-size: 16px; font-weight: 900; color: #10b981; margin-top: 8px; border-top: 2px dashed #bae6fd; padding-top: 8px; display: none; }
.cg-mc-dur { font-size: 11px; font-weight: 800; color: #64748b; margin-top: 8px; }

.cg-btn-row { display: flex; gap: 10px; }
.cg-btn { flex: 1; border: 2.5px solid #0f172a; border-radius: 12px; font-size: 13px; font-weight: 900; padding: 12px; box-shadow: 0 4px 0 #0f172a; cursor: pointer; text-align:center; }
.cg-btn:active { transform: translateY(3px); box-shadow: 0 1px 0 #0f172a; }
.cg-btn--cancel { background: #f1f5f9; color: #475569; }
.cg-btn--confirm { background: linear-gradient(135deg, #10b981, #059669); color: #fff; border-color: #fff; box-shadow: 0 4px 0 #047857; text-shadow:0 1px 1px rgba(0,0,0,0.2); }
</style>

<div class="up-page">

  <!-- HERO -->
  <div class="up-hero">
    <i class="ph-fill ph-star up-hero-star"></i>
    <div class="up-hero__lbl"><i class="ph-bold ph-crown"></i> Upgrade Paket</div>
    <div class="up-hero__val">Tonton Lebih Banyak, Earn Bebas!</div>
  </div>

  <!-- HOW IT WORKS -->
  <div class="up-card">
    <div class="up-card-title"><i class="ph-bold ph-lightbulb" style="color:#f59e0b;font-size:18px"></i> Cara Kerja Upgrade</div>
    <div class="up-step">
      <div class="up-step__num">1</div>
      <div class="up-step__txt"><strong>Deposit saldo</strong> melalui menu Deposit. Saldo Beli dipakai khusus untuk beli paket.</div>
    </div>
    <div class="up-step">
      <div class="up-step__num">2</div>
      <div class="up-step__txt"><strong>Pilih paket</strong> membership yang paling menguntungkan di bawah ini.</div>
    </div>
    <div class="up-step">
      <div class="up-step__num">3</div>
      <div class="up-step__txt"><strong>Konfirmasi upgrade!</strong> Harga akan dipotong otomatis dan langsung Aktif!</div>
    </div>
  </div>

  <!-- BENEFITS -->
  <div class="up-card">
    <div class="up-card-title"><i class="ph-bold ph-check-circle" style="color:#10b981;font-size:18px"></i> Keuntungan Paket Berbayar</div>
    <div class="up-bens">
      <div class="up-ben">📹 Limit tonton ekstra</div>
      <div class="up-ben">💸 Min. WD lebih kecil</div>
      <div class="up-ben">📈 Cuan lebih deras</div>
      <div class="up-ben">💰 Max. WD tembus</div>
      <div class="up-ben">⚡ Fitur Eksklusif</div>
      <div class="up-ben">🎯 Prioritas Admin</div>
    </div>
  </div>

  <?php if ($flash): ?>
  <div style="background:#fef2f2;border:2.5px solid #f87171;border-radius:14px;padding:12px 16px;color:#991b1b;font-weight:800;font-size:12px;margin-bottom:16px;box-shadow:0 4px 0 #fca5a5;">
    <?= htmlspecialchars($flash) ?>
  </div>
  <?php endif; ?>

  <!-- BALANCE -->
  <div class="up-bal">
    <div class="up-bal__lbl">💳 Saldo Beli (untuk Upgrade)</div>
    <div class="up-bal__val"><?= format_rp((float)$user['balance_dep']) ?></div>
    <div class="up-bal__actions">
      <a href="/deposit" class="up-bal__btn"><i class="ph-bold ph-arrow-circle-up" style="font-size:16px"></i> Isi Saldo</a>
      <a href="/checkin" class="up-bal__btn"><i class="ph-bold ph-calendar-check" style="font-size:16px"></i> Check-in</a>
    </div>
  </div>

  <?php if ($active_membership): ?>
  <div style="background:#e0f2fe;border:2.5px solid #38bdf8;border-radius:16px;padding:14px 16px;margin-bottom:20px;box-shadow:0 4px 0 #7dd3e8;">
    <div style="font-size:13px;font-weight:900;color:#0369a1;margin-bottom:4px;display:flex;align-items:center;gap:6px;"><i class="ph-fill ph-star"></i> Paket Aktif Saat Ini</div>
    <div style="font-size:18px;font-weight:900;color:#0ea5e9;letter-spacing:-0.5px;margin-bottom:6px;"><?= htmlspecialchars($active_membership['name']) ?></div>
    <div style="font-size:11px;font-weight:800;color:#0c4a6e;margin-bottom:12px;">Limit <?= $active_membership['watch_limit'] ?>× /hari, berlaku s/d <?= date('d M Y', strtotime($user['membership_expires_at'])) ?></div>
    
    <?php if ($can_refund): ?>
    <button type="button" onclick="document.getElementById('brutal-refund-confirm').style.display='flex'" style="background:#fff;border:2.5px solid #ef4444;color:#ef4444;font-size:12px;font-weight:900;box-shadow:0 4px 0 #ef4444; padding: 10px 14px; width: 100%; display: flex; align-items: center; justify-content: center; gap: 8px; border-radius: 12px; cursor: pointer; text-transform:uppercase;">
      <i class="ph-bold ph-arrow-u-up-left" style="font-size:16px"></i> Minta Refund
    </button>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <!-- PACKAGES FORM -->
  <form method="POST" id="upgrade-form">
    <?= csrf_field() ?>
    <input type="hidden" name="membership_id" id="chosen-id" value="">
    <input type="hidden" name="voucher_code" id="applied-voucher-code" value="">
    
    <div style="display:flex;flex-direction:column;">
      <?php 
      foreach ($memberships as $i => $m):
        if ((float)$m['price'] == 0) continue;
        $can_afford = (float)$user['balance_dep'] >= (float)$m['price'];
        
        $m_class = "m-card--" . ($i % 5);
        $bg_color = ['#f8fafc','#f0f9ff','#fefce8','#faf5ff','#fef2f2'][$i % 5];
        $txt_color = ['#0f172a','#0369a1','#b45309','#6b21a8','#b91c1c'][$i % 5];
      ?>
      <div class="m-card <?= $m_class ?>">
        
        <?php if ($i === 2): ?>
        <div class="m-badge-pop">🔥 TERPOPULER</div>
        <?php endif; ?>
        
        <?php if ((float)$m['original_price'] > 0): ?>
        <div class="m-badge-pro">🎉 PROMO DISKON!</div>
        <?php endif; ?>
        
        <div class="m-hdr">
          <div style="display:flex;align-items:center;gap:10px">
            <div class="m-ico-box" style="background:<?= $bg_color ?>;color:<?= $txt_color ?>;border-color:<?= $txt_color ?>">
              <?= htmlspecialchars($m['icon'] ?: '⭐') ?>
            </div>
            <div>
              <div class="m-name" style="color:<?= $txt_color ?>"><?= htmlspecialchars($m['name']) ?></div>
              <div class="m-dur"><i class="ph-bold ph-hourglass"></i> <?= $m['duration_days'] ?> Hari</div>
            </div>
          </div>
          <div class="m-price-box">
            <?php if ((float)$m['original_price'] > 0): ?>
            <div class="m-price-old"><?= format_rp((float)$m['original_price']) ?></div>
            <?php endif; ?>
            <div class="m-price" style="color:<?= $txt_color ?>"><?= format_rp((float)$m['price']) ?></div>
          </div>
        </div>
        
        <div class="m-specs">
          <div><i class="ph-bold ph-video-camera"></i> <?= $m['watch_limit'] ?>× Tonton / hari</div>
          <div><i class="ph-bold ph-trend-up"></i> Maksimal Narik <?= (float)$m['max_wd'] > 0 ? format_rp((float)$m['max_wd']) : '<span style="color:#10b981;font-weight:900">Tanpa batas</span>' ?></div>
          <?php if ($m['description']): ?><div class="m-desc"><i class="ph-bold ph-info"></i> <?= nl2br(htmlspecialchars($m['description'])) ?></div><?php endif; ?>
        </div>
        
        <div class="m-actions">
          <button type="button" class="m-btn <?= $can_afford ? 'm-btn--buy' : 'm-btn--disabled' ?>"
            onclick="openConfirm(<?= $m['id'] ?>, '<?= htmlspecialchars($m['name'], ENT_QUOTES) ?>', <?= (float)$m['price'] ?>, <?= $m['duration_days'] ?>)">
            <i class="ph-bold ph-rocket-launch" style="font-size:16px"></i> <?= $can_afford ? 'Upgrade Sekarang' : 'Saldo Kurang' ?>
          </button>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </form>

  <!-- FAQ / Notes -->
  <div class="up-card" style="margin-top:8px">
    <div class="up-card-title"><i class="ph-bold ph-question" style="color:#8b5cf6;font-size:18px"></i> Info Penting</div>
    <div class="up-step">
      <div class="up-step__num" style="background:#8b5cf6;box-shadow:0 3px 0 #6d28d9">!</div>
      <div class="up-step__txt"><strong>Upgrade menimpa paket aktif!</strong> Paket baru akan otomatis menggantikan paket lama kamu.</div>
    </div>
    <div class="up-step">
      <div class="up-step__num" style="background:#8b5cf6;box-shadow:0 3px 0 #6d28d9">!</div>
      <div class="up-step__txt">Saldo Beli tidak dapat ditarik kembali ke rekening. Hanya bisa untuk beli paket.</div>
    </div>
  </div>

</div>

<!-- Confirmation Modal -->
<div id="upgrade-modal" class="cg-modal">
  <div class="cg-modal-card">
    <div class="cg-mc-hdr">🚀 Konfirmasi Upgrade</div>
    <div class="cg-mc-sub">Pastikan kamu yakin sebelum memproses!</div>
    
    <div class="cg-mc-box">
      <div class="cg-mc-lbl">Paket Dipilih</div>
      <div class="cg-mc-val" id="modal-name">—</div>
      <div class="cg-mc-price" id="price-row">Harga: <span id="modal-price">—</span></div>
      <div class="cg-mc-discount" id="discount-row">Diskon: -<span id="modal-discount">—</span> (<span id="modal-pct">—</span>)</div>
      <div class="cg-mc-total" id="final-price-row">Total Bayar: <span id="modal-final-price">—</span></div>
      <div class="cg-mc-dur">Berlaku <span id="modal-days">—</span> hari setelah aktivasi</div>
    </div>
    
    <!-- Voucher Coupon Section -->
    <div style="margin-bottom:16px;">
      <button type="button" id="toggle-voucher-btn" onclick="toggleVoucherInput()" style="background:none;border:none;color:#0ea5e9;font-weight:900;font-size:12px;cursor:pointer;padding:0;outline:none;display:flex;align-items:center;gap:4px;"><i class="ph-bold ph-tag"></i> Pakai Voucher Diskon?</button>
      <div id="voucher-input-container" style="display:none;margin-top:8px;gap:8px;">
        <input type="text" id="voucher-code-input" placeholder="KODE VOUCHER" style="flex:1;border:2.5px solid #cbd5e1;border-radius:10px;padding:8px 12px;font-weight:900;text-transform:uppercase;font-size:12px;outline:none;">
        <button type="button" onclick="applyVoucher()" style="background:linear-gradient(135deg, #fde047, #f59e0b);color:#78350f;border:2px solid #fff;border-radius:10px;padding:8px 14px;font-weight:900;font-size:12px;cursor:pointer;box-shadow:0 3px 0 #d97706;text-shadow:0 1px 0 rgba(255,255,255,0.5);">Gunakan</button>
      </div>
      <div id="voucher-message" style="font-size:11px;font-weight:800;margin-top:6px;display:none;"></div>
    </div>

    <!-- Balance Warning -->
    <div id="modal-balance-warning" style="display:none;font-size:11px;color:#ef4444;font-weight:800;margin-bottom:16px;background:#fef2f2;border:2px solid #fca5a5;border-radius:10px;padding:10px 12px;"></div>

    <div style="font-size:11px;color:#94a3b8;font-weight:800;margin-bottom:16px;text-align:center;">⚠️ Saldo Beli akan otomatis terpotong. Aksi ini permanen.</div>
    
    <div class="cg-btn-row">
      <button type="button" class="cg-btn cg-btn--cancel" onclick="closeConfirm()">Batal</button>
      <button type="button" id="modal-confirm-btn" class="cg-btn cg-btn--confirm" onclick="submitUpgrade()">✅ YA, GAS!</button>
    </div>
  </div>
</div>

<!-- Neo-brutalism Refund Confirm Modal -->
<div id="brutal-refund-confirm" class="cg-modal">
  <div class="cg-modal-card" style="border-color:#b91c1c;box-shadow:0 8px 0 #b91c1c">
    <div class="cg-mc-hdr" style="color:#ef4444">⚠️ Minta Refund?</div>
    <div class="cg-mc-sub">Kamu yakin ingin refund paket kamu saat ini?</div>
    
    <div style="background:#fef2f2;border:2.5px solid #fca5a5;border-radius:14px;padding:12px;margin-bottom:16px;font-size:11px;font-weight:800;color:#991b1b;line-height:1.4">
      Uang refund akan dikembalikan ke <strong>Saldo Beli</strong> (bukan transfer bank) dengan potongan biaya admin (jika ada). Level kamu akan dibatalkan!
    </div>
    
    <form method="POST">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="refund_level">
      <div class="cg-btn-row">
        <button type="button" class="cg-btn cg-btn--cancel" onclick="document.getElementById('brutal-refund-confirm').style.display='none'">Gak Jadi</button>
        <button type="submit" class="cg-btn" style="background:linear-gradient(135deg, #ef4444, #dc2626);color:#fff;border-color:#fff;box-shadow:0 4px 0 #991b1b;text-shadow:0 1px 1px rgba(0,0,0,0.3)">Refund Sekarang</button>
      </div>
    </form>
  </div>
</div>

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
      warnEl.innerHTML = '⚠️ Saldo Beli tidak cukup (Kurang <strong>Rp ' + (finalPrice - userBalance).toLocaleString('id-ID') + '</strong>). Silakan deposit dulu.';
    }
  } else {
    btn.disabled = false;
    btn.style.opacity = '1';
    btn.style.cursor = 'pointer';
    btn.innerText = '✅ YA, GAS!';
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
    toggleBtn.style.display = 'flex';
    toggleBtn.innerHTML = '<i class="ph-bold ph-tag"></i> Pakai Voucher Diskon?';
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
    toggleBtn.innerHTML = '✖ Tutup Voucher';
  } else {
    container.style.display = 'none';
    toggleBtn.innerHTML = '<i class="ph-bold ph-tag"></i> Pakai Voucher Diskon?';
  }
}
function applyVoucher() {
  const codeInput = document.getElementById('voucher-code-input');
  const code = codeInput.value.toUpperCase().trim();
  const mid = document.getElementById('chosen-id').value;
  const msgEl = document.getElementById('voucher-message');
  
  if (!code) {
    msgEl.style.color = '#ef4444';
    msgEl.innerText = '⚠️ Masukkan kode voucher.';
    msgEl.style.display = 'block';
    return;
  }
  
  msgEl.style.color = '#94a3b8';
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
      msgEl.style.color = '#ef4444';
      msgEl.innerText = '❌ ' + res.error;
      msgEl.style.display = 'block';
      
      document.getElementById('applied-voucher-code').value = '';
      document.getElementById('discount-row').style.display = 'none';
      document.getElementById('final-price-row').style.display = 'none';
      
      const originalPrice = parseFloat(document.getElementById('modal-price').textContent.replace(/[^0-9]/g, ''));
      checkAffordability(originalPrice);
    } else {
      msgEl.style.color = '#10b981';
      msgEl.innerText = '✅ Diskon ' + res.discount_text + ' aktif!';
      msgEl.style.display = 'block';
      
      document.getElementById('applied-voucher-code').value = code;
      
      document.getElementById('modal-discount').textContent = res.discount_amount_formatted;
      document.getElementById('modal-pct').textContent = res.discount_text;
      document.getElementById('modal-final-price').textContent = res.final_price_formatted;
      
      document.getElementById('discount-row').style.display = 'block';
      document.getElementById('final-price-row').style.display = 'block';
      
      document.getElementById('voucher-input-container').style.display = 'none';
      document.getElementById('toggle-voucher-btn').style.display = 'none';
      
      checkAffordability(res.final_price);
    }
  })
  .catch(err => {
    msgEl.style.color = '#ef4444';
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
document.getElementById('brutal-refund-confirm').addEventListener('click', function(e) {
  if (e.target === this) this.style.display='none';
});
</script>

<?php require dirname(__DIR__) . '/partials/footer.php'; ?>
