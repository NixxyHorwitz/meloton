<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/auth/guard.php';

$flash = $flashType = '';
$active_section = 'main'; // default

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'update_profile') {
        $username = trim($_POST['username'] ?? '');
        if (strlen($username) < 3) { $flash = 'Username minimal 3 karakter.'; $flashType = 'error'; }
        elseif (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) { $flash = 'Username hanya boleh huruf, angka, underscore.'; $flashType = 'error'; }
        else {
            $ex = $pdo->prepare("SELECT id FROM users WHERE username=? AND id!=?");
            $ex->execute([$username, $user['id']]);
            if ($ex->fetch()) { $flash = 'Username sudah digunakan.'; $flashType = 'error'; }
            else {
                $pdo->prepare("UPDATE users SET username=? WHERE id=?")->execute([$username, $user['id']]);
                $flash = '✅ Username berhasil diperbarui!';
            }
        }
        $active_section = 'edit';
    }
    if ($action === 'change_password') {
        $old = $_POST['old_password'] ?? '';
        $new = $_POST['new_password'] ?? '';
        if (!password_verify($old, $user['password_hash'])) { $flash = 'Password lama salah.'; $flashType = 'error'; }
        elseif (strlen($new) < 6) { $flash = 'Password baru minimal 6 karakter.'; $flashType = 'error'; }
        else {
            $pdo->prepare("UPDATE users SET password_hash=? WHERE id=?")->execute([password_hash($new, PASSWORD_BCRYPT), $user['id']]);
            $flash = '✅ Password berhasil diubah!';
        }
        $active_section = 'password';
    }
    $ru = $pdo->prepare("SELECT * FROM users WHERE id=?"); $ru->execute([$user['id']]); $user = $ru->fetch();
}

// Stats
$st = $pdo->prepare("SELECT COUNT(*) FROM watch_history WHERE user_id=?"); $st->execute([$user['id']]); $total_watches = (int)$st->fetchColumn();
$refs = $pdo->prepare("SELECT COUNT(*) FROM users WHERE referred_by=?"); $refs->execute([$user['referral_code']]); $refs = (int)$refs->fetchColumn();

// Membership
$membership_name = 'Free';
$membership_allow_edit_bank = false;
if ($user['membership_id'] && $user['membership_expires_at'] && strtotime($user['membership_expires_at']) > time()) {
    $ms = $pdo->prepare("SELECT name, allow_edit_bank FROM memberships WHERE id=?"); $ms->execute([$user['membership_id']]);
    $ms = $ms->fetch();
    $membership_name = $ms['name'] ?? 'Free';
    $membership_allow_edit_bank = (bool)($ms['allow_edit_bank'] ?? false);
}
$edit_bank_min_dep = (int)($user['edit_bank_deposit_min'] ?? 50000);
$dep_ok_for_edit   = (float)$user['balance_dep'] >= $edit_bank_min_dep;
$is_promotor_prof  = ((int)($user['is_promotor'] ?? 0) === 1);
$show_edit_rek_btn = $membership_allow_edit_bank || $is_promotor_prof;
$is_premium        = $membership_name !== 'Free';

// Contact buttons
try {
    $_contact_btns = $pdo->query("SELECT * FROM contact_buttons WHERE is_active=1 ORDER BY sort_order ASC, id ASC")->fetchAll();
} catch (\Throwable) { $_contact_btns = []; }

$pageTitle  = 'Profil';
$activePage = 'profile';
require dirname(__DIR__) . '/partials/header.php';

$_psvg = [
  'wa'   => '<svg viewBox="0 0 24 24" fill="currentColor" width="18" height="18"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347z"/><path d="M12 0C5.373 0 0 5.373 0 12c0 2.123.554 4.118 1.528 5.847L.057 23.883a.5.5 0 00.61.61l6.037-1.472A11.944 11.944 0 0012 24c6.627 0 12-5.373 12-12S18.627 0 12 0zm0 22c-1.89 0-3.655-.518-5.17-1.42l-.37-.22-3.823.933.954-3.722-.242-.383A9.958 9.958 0 012 12C2 6.477 6.477 2 12 2s10 4.477 10 10-4.477 10-10 10z"/></svg>',
  'tele' => '<svg viewBox="0 0 24 24" fill="currentColor" width="18" height="18"><path d="M11.944 0A12 12 0 000 12a12 12 0 0012 12 12 12 0 0012-12A12 12 0 0012 0a12 12 0 00-.056 0zm4.962 7.224c.1-.002.321.023.465.14a.506.506 0 01.171.325c.016.093.036.306.02.472-.18 1.898-.962 6.502-1.36 8.627-.168.9-.499 1.201-.82 1.23-.696.065-1.225-.46-1.9-.902-1.056-.693-1.653-1.124-2.678-1.8-1.185-.78-.417-1.21.258-1.91.177-.184 3.247-2.977 3.307-3.23.007-.032.014-.15-.056-.212s-.174-.041-.249-.024c-.106.024-1.793 1.14-5.061 3.345-.48.33-.913.49-1.302.48-.428-.008-1.252-.241-1.865-.44-.752-.245-1.349-.374-1.297-.789.027-.216.325-.437.893-.663 3.498-1.524 5.83-2.529 6.998-3.014 3.332-1.386 4.025-1.627 4.476-1.635z"/></svg>',
  'cs'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg>',
  'ig'   => '<svg viewBox="0 0 24 24" fill="currentColor" width="18" height="18"><path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zm0-2.163c-3.259 0-3.667.014-4.947.072-4.358.2-6.78 2.618-6.98 6.98-.059 1.281-.073 1.689-.073 4.948 0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98 1.281.058 1.689.072 4.948.072 3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98-1.281-.059-1.69-.073-4.949-.073zm0 5.838a6.162 6.162 0 100 12.324 6.162 6.162 0 000-12.324zM12 16a4 4 0 110-8 4 4 0 010 8zm6.406-11.845a1.44 1.44 0 100 2.881 1.44 1.44 0 000-2.881z"/></svg>',
  'fb'   => '<svg viewBox="0 0 24 24" fill="currentColor" width="18" height="18"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>',
];
?>

<style>
/* ══════════════════════════════════════════════
   PROFILE PAGE — CASUAL GAME STYLE
   ══════════════════════════════════════════════ */

.prof-page { padding: 0 0 20px; }

/* ── Flash ── */
.prof-flash { padding: 10px 14px; border-radius: 12px; font-size: 12px; font-weight: 700; margin-bottom: 12px; border: 2px solid; display: flex; align-items: center; gap: 8px; }
.prof-flash--success { background: #f0fdf4; color: #166534; border-color: #bbf7d0; }
.prof-flash--error   { background: #fef2f2; color: #991b1b; border-color: #fecaca; }

/* ── Hero Card ── */
.prof-hero {
  background: linear-gradient(135deg, #0c4a6e 0%, #0e7490 55%, #06b6d4 100%);
  border: 3px solid #075985;
  border-radius: 22px;
  box-shadow: 0 8px 0 #0c4a6e;
  padding: 20px 16px 16px;
  text-align: center;
  position: relative;
  overflow: hidden;
  margin-bottom: 12px;
}
.prof-hero::before {
  content: '';
  position: absolute;
  top: -40px; right: -30px;
  width: 150px; height: 150px;
  border-radius: 50%;
  background: rgba(255,255,255,0.05);
  pointer-events: none;
}
.prof-hero::after {
  content: '';
  position: absolute;
  bottom: -30px; left: -20px;
  width: 100px; height: 100px;
  border-radius: 50%;
  background: rgba(255,255,255,0.04);
  pointer-events: none;
}

/* Avatar circle */
.prof-avatar-ring {
  position: relative;
  width: 76px; height: 76px;
  margin: 0 auto 10px;
}
.prof-avatar {
  width: 76px; height: 76px;
  border-radius: 50%;
  background: linear-gradient(135deg, #fde68a, #f59e0b);
  border: 3.5px solid rgba(255,255,255,0.35);
  display: flex; align-items: center; justify-content: center;
  font-weight: 900; font-size: 32px; color: #0c4a6e;
  box-shadow: 0 4px 0 rgba(0,0,0,0.2);
}
.prof-tier-badge {
  position: absolute;
  bottom: -4px; right: -4px;
  background: linear-gradient(135deg, #fbbf24, #f59e0b);
  border: 2.5px solid #fff;
  border-radius: 20px;
  padding: 2px 8px;
  font-size: 9px; font-weight: 900;
  color: #0c4a6e;
  box-shadow: 0 2px 0 rgba(0,0,0,0.2);
  white-space: nowrap;
}
.prof-tier-badge--premium { background: linear-gradient(135deg, #a78bfa, #7c3aed); color: #fff; }

.prof-hero__name {
  font-size: 18px; font-weight: 900; color: #fff;
  text-shadow: 0 1px 2px rgba(0,0,0,0.2);
  margin-bottom: 2px;
}
.prof-hero__email {
  font-size: 10px; color: rgba(255,255,255,0.6);
  font-weight: 700; margin-bottom: 12px;
}

/* Stats row inside hero */
.prof-hero-stats {
  display: grid; grid-template-columns: repeat(3, 1fr);
  gap: 8px;
}
.prof-hero-stat {
  background: rgba(255,255,255,0.12);
  border: 1.5px solid rgba(255,255,255,0.2);
  border-radius: 12px;
  padding: 8px 4px;
  backdrop-filter: blur(4px);
}
.prof-hero-stat__val { font-size: 15px; font-weight: 900; color: #fde68a; line-height: 1; }
.prof-hero-stat__lbl { font-size: 9px; font-weight: 800; color: rgba(255,255,255,0.65); margin-top: 3px; }

/* ── Referral Strip ── */
.prof-ref {
  display: flex; align-items: center; gap: 10px;
  background: #fff;
  border: 2.5px solid #7dd3e8;
  border-radius: 14px;
  padding: 10px 12px;
  box-shadow: 0 4px 0 #7dd3e8;
  margin-bottom: 12px;
}
.prof-ref__icon { font-size: 20px; color: #0891b2; flex-shrink: 0; }
.prof-ref__body { flex: 1; min-width: 0; }
.prof-ref__label { font-size: 9px; font-weight: 800; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px; }
.prof-ref__code  { font-size: 15px; font-weight: 900; color: #0c4a6e; letter-spacing: 2px; }
.prof-ref__btn {
  background: #e0f9ff; border: 1.5px solid #7dd3e8; border-radius: 10px;
  padding: 6px 12px; font-size: 11px; font-weight: 900; color: #0891b2;
  cursor: pointer; flex-shrink: 0; display: flex; align-items: center; gap: 4px;
  transition: background 0.1s;
}
.prof-ref__btn:active { background: #bae6fd; }

/* ── Section Card ── */
.prof-section {
  background: #fff;
  border: 2.5px solid #7dd3e8;
  border-radius: 16px;
  box-shadow: 0 5px 0 #7dd3e8;
  overflow: hidden;
  margin-bottom: 10px;
}
.prof-section-hdr {
  display: flex; align-items: center; gap: 8px;
  padding: 10px 12px;
  border-bottom: 2px solid #e0f9ff;
  cursor: pointer;
  user-select: none;
  -webkit-tap-highlight-color: transparent;
}
.prof-section-hdr__icon {
  width: 32px; height: 32px;
  border-radius: 10px;
  display: flex; align-items: center; justify-content: center;
  font-size: 16px; flex-shrink: 0;
}
.prof-section-hdr__title { flex: 1; font-size: 13px; font-weight: 900; color: #0c4a6e; }
.prof-section-hdr__caret { font-size: 14px; color: #94a3b8; transition: transform 0.2s; }
.prof-section-hdr__caret.open { transform: rotate(180deg); }
.prof-section-body { padding: 12px; display: none; }
.prof-section-body.open { display: block; }

/* ── Info Row (label: value) ── */
.info-row {
  display: flex; align-items: center; justify-content: space-between;
  padding: 7px 0;
  border-bottom: 1.5px solid #f0fdff;
  font-size: 12px;
}
.info-row:last-child { border-bottom: none; }
.info-row__label { color: #64748b; font-weight: 700; display: flex; align-items: center; gap: 5px; }
.info-row__val   { font-weight: 900; color: #0c4a6e; }

/* ── Bank card ── */
.bank-display {
  background: linear-gradient(135deg, #0c4a6e, #0e7490);
  border-radius: 12px;
  padding: 14px;
  margin-bottom: 10px;
  position: relative;
  overflow: hidden;
}
.bank-display::before {
  content: '';
  position: absolute; top: -20px; right: -20px;
  width: 80px; height: 80px;
  border-radius: 50%;
  background: rgba(255,255,255,0.06);
}
.bank-display__name   { font-size: 10px; font-weight: 800; color: rgba(255,255,255,0.6); text-transform: uppercase; letter-spacing: 1px; }
.bank-display__bank   { font-size: 14px; font-weight: 900; color: #fde68a; margin: 4px 0 2px; }
.bank-display__number { font-size: 16px; font-weight: 900; color: #fff; letter-spacing: 2px; }
.bank-display__holder { font-size: 11px; color: rgba(255,255,255,0.65); margin-top: 4px; font-weight: 700; }

/* ── Form ── */
.pf-label { font-size: 11px; font-weight: 800; color: #64748b; margin-bottom: 4px; display: block; }
.pf-input {
  width: 100%; background: #f8fafc;
  border: 2px solid #e2e8f0; border-radius: 10px;
  padding: 10px 12px; font-size: 13px; font-family: inherit;
  font-weight: 700; color: #0c4a6e; outline: none;
  transition: border-color 0.2s;
}
.pf-input:focus { border-color: #7dd3e8; background: #fff; }
.pf-input:disabled { background: #f1f5f9; color: #94a3b8; cursor: not-allowed; }
.pf-group { margin-bottom: 10px; }
.pf-hint { font-size: 10px; color: #94a3b8; font-weight: 700; margin-top: 3px; }

/* ── Action buttons (save/ganti password) ── */
.pf-btn-primary {
  width: 100%; padding: 11px;
  background: linear-gradient(135deg, #22d3ee, #0891b2);
  border: 2px solid #a5f3fc; border-radius: 12px;
  box-shadow: 0 4px 0 #0e7490;
  color: #fff; font-size: 13px; font-weight: 900;
  cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 6px;
  transition: transform 0.1s;
}
.pf-btn-primary:active { transform: translateY(3px); box-shadow: none; }
.pf-btn-danger {
  width: 100%; padding: 11px;
  background: linear-gradient(135deg, #fb923c, #dc2626);
  border: 2px solid #fed7aa; border-radius: 12px;
  box-shadow: 0 4px 0 #991b1b;
  color: #fff; font-size: 13px; font-weight: 900;
  cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 6px;
  transition: transform 0.1s;
}
.pf-btn-danger:active { transform: translateY(3px); box-shadow: none; }

/* ── Menu link list ── */
.prof-menu-item {
  display: flex; align-items: center; gap: 10px;
  padding: 10px 12px;
  border-bottom: 1.5px solid #f0fdff;
  text-decoration: none;
  cursor: pointer;
  transition: background 0.1s;
  -webkit-tap-highlight-color: transparent;
}
.prof-menu-item:last-child { border-bottom: none; }
.prof-menu-item:active { background: #f0fdff; }
.prof-menu-icon {
  width: 36px; height: 36px;
  border-radius: 12px;
  display: flex; align-items: center; justify-content: center;
  font-size: 18px; flex-shrink: 0;
}
.prof-menu-label { flex: 1; font-size: 13px; font-weight: 800; color: #0c4a6e; }
.prof-menu-arrow { font-size: 14px; color: #94a3b8; }

/* ── Logout ── */
.prof-logout {
  display: flex; align-items: center; justify-content: center; gap: 8px;
  background: linear-gradient(135deg, #f87171, #dc2626);
  border: 2.5px solid #fca5a5;
  border-radius: 16px;
  box-shadow: 0 5px 0 #991b1b;
  padding: 13px; font-weight: 900; font-size: 14px;
  color: #fff; text-decoration: none;
  transition: transform 0.1s;
  width: 100%;
  margin-bottom: 4px;
}
.prof-logout:active { transform: translateY(4px); box-shadow: none; }

/* ── Ref copy toast ── */
#prof-ref-toast {
  text-align: center; font-size: 11px; font-weight: 700;
  color: #15803d; background: #f0fdf4; border-radius: 8px;
  padding: 5px; margin-bottom: 8px; display: none;
}
</style>

<div class="prof-page">

<?php if ($flash): ?>
<div class="prof-flash prof-flash--<?= $flashType === 'error' ? 'error' : 'success' ?>">
  <i class="ph-bold ph-<?= $flashType === 'error' ? 'warning-circle' : 'check-circle' ?>" style="font-size:16px;flex-shrink:0"></i>
  <?= htmlspecialchars($flash) ?>
</div>
<?php endif; ?>

<!-- ── HERO CARD ── -->
<div class="prof-hero">
  <div class="prof-avatar-ring">
    <div class="prof-avatar"><?= strtoupper(substr($user['username'], 0, 1)) ?></div>
    <div class="prof-tier-badge <?= $is_premium ? 'prof-tier-badge--premium' : '' ?>">
      <?= $is_premium ? '⭐ '.$membership_name : 'Free' ?>
    </div>
  </div>
  <div class="prof-hero__name"><?= htmlspecialchars($user['username']) ?></div>
  <div class="prof-hero__email"><?= htmlspecialchars($user['email']) ?></div>
  <?php if ($user['membership_expires_at'] && strtotime($user['membership_expires_at']) > time()): ?>
  <div style="font-size:10px;color:rgba(255,255,255,0.5);font-weight:700;margin-bottom:8px">
    s/d <?= date('d M Y', strtotime($user['membership_expires_at'])) ?>
  </div>
  <?php else: ?>
  <div style="margin-bottom:8px"></div>
  <?php endif; ?>
  <div class="prof-hero-stats">
    <div class="prof-hero-stat">
      <div class="prof-hero-stat__val" style="font-size:12px"><?= format_rp((float)$user['total_earned']) ?></div>
      <div class="prof-hero-stat__lbl">Total Earned</div>
    </div>
    <div class="prof-hero-stat">
      <div class="prof-hero-stat__val"><?= number_format($total_watches) ?></div>
      <div class="prof-hero-stat__lbl">Ditonton</div>
    </div>
    <div class="prof-hero-stat">
      <div class="prof-hero-stat__val"><?= $refs ?></div>
      <div class="prof-hero-stat__lbl">Referral</div>
    </div>
  </div>
</div>

<!-- ── REFERRAL STRIP ── -->
<div class="prof-ref">
  <i class="ph-fill ph-share-network prof-ref__icon"></i>
  <div class="prof-ref__body">
    <div class="prof-ref__label">Kode Referral</div>
    <div class="prof-ref__code" id="ref-code"><?= htmlspecialchars($user['referral_code']) ?></div>
  </div>
  <button type="button" class="prof-ref__btn" onclick="copyRef()">
    <i class="ph-bold ph-copy"></i> Salin
  </button>
</div>
<div id="prof-ref-toast">✓ Kode referral disalin!</div>

<!-- ── INFO AKUN ── -->
<div class="prof-section">
  <div class="prof-section-hdr" onclick="toggleSection('info')" id="hdr-info">
    <div class="prof-section-hdr__icon" style="background:#e0f9ff"><i class="ph-fill ph-user-circle" style="color:#0891b2"></i></div>
    <div class="prof-section-hdr__title">Info Akun</div>
    <i class="ph-bold ph-caret-down prof-section-hdr__caret open" id="caret-info"></i>
  </div>
  <div class="prof-section-body open" id="body-info">
    <div class="info-row">
      <span class="info-row__label"><i class="ph-bold ph-identification-card" style="color:#0891b2"></i> Username</span>
      <span class="info-row__val"><?= htmlspecialchars($user['username']) ?></span>
    </div>
    <div class="info-row">
      <span class="info-row__label"><i class="ph-bold ph-envelope-simple" style="color:#a78bfa"></i> Email</span>
      <span class="info-row__val" style="font-size:11px"><?= htmlspecialchars($user['email']) ?></span>
    </div>
    <div class="info-row">
      <span class="info-row__label"><i class="ph-bold ph-whatsapp-logo" style="color:#10b981"></i> WhatsApp</span>
      <span class="info-row__val"><?= htmlspecialchars(mask_account($user['whatsapp'] ?? '')) ?></span>
    </div>
    <div class="info-row">
      <span class="info-row__label"><i class="ph-bold ph-crown" style="color:#f59e0b"></i> Paket</span>
      <span class="info-row__val"><?= $membership_name ?></span>
    </div>
    <div class="info-row" style="border:none;padding-top:10px">
      <a href="/upgrade" style="flex:1;display:flex;align-items:center;justify-content:center;gap:5px;background:#e0f9ff;border:1.5px solid #7dd3e8;border-radius:10px;padding:8px;font-size:12px;font-weight:900;color:#0891b2;text-decoration:none">
        <i class="ph-fill ph-rocket-launch"></i> Upgrade Membership
      </a>
    </div>
  </div>
</div>

<!-- ── REKENING BANK ── -->
<div class="prof-section">
  <div class="prof-section-hdr" onclick="toggleSection('bank')" id="hdr-bank">
    <div class="prof-section-hdr__icon" style="background:#dbeafe"><i class="ph-fill ph-bank" style="color:#3b82f6"></i></div>
    <div class="prof-section-hdr__title">Rekening Bank</div>
    <i class="ph-bold ph-caret-down prof-section-hdr__caret" id="caret-bank"></i>
  </div>
  <div class="prof-section-body" id="body-bank">
    <?php if (!empty($user['bank_name'])): ?>
    <div class="bank-display">
      <div class="bank-display__name">Rekening Penarikan</div>
      <div class="bank-display__bank"><?= htmlspecialchars($user['bank_name']) ?></div>
      <div class="bank-display__number"><?= htmlspecialchars(mask_account($user['account_number'] ?? '')) ?></div>
      <div class="bank-display__holder"><?= htmlspecialchars($user['account_name']) ?></div>
    </div>
    <?php else: ?>
    <div style="text-align:center;color:#94a3b8;font-size:12px;font-weight:700;padding:12px 0">
      <i class="ph-fill ph-bank" style="font-size:28px;display:block;margin-bottom:4px;opacity:0.3"></i>
      Belum ada rekening tersimpan
    </div>
    <?php endif; ?>
    <?php if ($show_edit_rek_btn): ?>
    <a href="/edit-rekening" style="display:flex;align-items:center;justify-content:center;gap:6px;background:linear-gradient(135deg,#60a5fa,#3b82f6);border:1.5px solid #93c5fd;border-radius:10px;padding:10px;font-size:12px;font-weight:900;color:#fff;text-decoration:none;box-shadow:0 3px 0 #1d4ed8">
      <i class="ph-bold ph-pencil-simple"></i> Edit Rekening
      <?php if (!$dep_ok_for_edit): ?>
      <span style="font-size:9px;opacity:0.75">· Butuh Rp<?= number_format($edit_bank_min_dep,0,'','') ?></span>
      <?php endif; ?>
    </a>
    <?php endif; ?>
  </div>
</div>

<!-- ── EDIT USERNAME ── -->
<div class="prof-section">
  <div class="prof-section-hdr" onclick="toggleSection('edit')" id="hdr-edit">
    <div class="prof-section-hdr__icon" style="background:#fef3c7"><i class="ph-fill ph-pencil-simple" style="color:#d97706"></i></div>
    <div class="prof-section-hdr__title">Edit Username</div>
    <i class="ph-bold ph-caret-down prof-section-hdr__caret <?= $active_section === 'edit' ? 'open' : '' ?>" id="caret-edit"></i>
  </div>
  <div class="prof-section-body <?= $active_section === 'edit' ? 'open' : '' ?>" id="body-edit">
    <form method="POST">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="update_profile">
      <div class="pf-group">
        <label class="pf-label">Username Baru</label>
        <input class="pf-input" type="text" name="username" value="<?= htmlspecialchars($user['username']) ?>"
               pattern="[a-zA-Z0-9_]+" minlength="3" required>
        <div class="pf-hint">3–30 karakter · huruf, angka, underscore</div>
      </div>
      <div class="pf-group">
        <label class="pf-label">WhatsApp <span style="color:#94a3b8;font-size:9px">· tidak dapat diubah</span></label>
        <input class="pf-input" type="tel" value="<?= htmlspecialchars($user['whatsapp'] ?? '') ?>" disabled>
      </div>
      <button type="submit" class="pf-btn-primary">
        <i class="ph-bold ph-floppy-disk"></i> Simpan Username
      </button>
    </form>
  </div>
</div>

<!-- ── GANTI PASSWORD ── -->
<div class="prof-section">
  <div class="prof-section-hdr" onclick="toggleSection('password')" id="hdr-password">
    <div class="prof-section-hdr__icon" style="background:#dcfce7"><i class="ph-fill ph-lock-key" style="color:#16a34a"></i></div>
    <div class="prof-section-hdr__title">Ganti Password</div>
    <i class="ph-bold ph-caret-down prof-section-hdr__caret <?= $active_section === 'password' ? 'open' : '' ?>" id="caret-password"></i>
  </div>
  <div class="prof-section-body <?= $active_section === 'password' ? 'open' : '' ?>" id="body-password">
    <form method="POST">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="change_password">
      <div class="pf-group">
        <label class="pf-label">Password Lama</label>
        <input class="pf-input" type="password" name="old_password" required>
      </div>
      <div class="pf-group">
        <label class="pf-label">Password Baru <span style="color:#94a3b8;font-size:9px">min. 6 karakter</span></label>
        <input class="pf-input" type="password" name="new_password" required>
      </div>
      <button type="submit" class="pf-btn-danger">
        <i class="ph-bold ph-lock-key"></i> Ganti Password
      </button>
    </form>
  </div>
</div>

<!-- ── MENU NAVIGASI ── -->
<div class="prof-section" style="margin-bottom:12px">
  <a href="/history" class="prof-menu-item" style="color:inherit;text-decoration:none">
    <div class="prof-menu-icon" style="background:#e0f9ff"><i class="ph-fill ph-receipt" style="color:#0891b2;font-size:18px"></i></div>
    <span class="prof-menu-label">Riwayat Transaksi</span>
    <i class="ph-bold ph-caret-right prof-menu-arrow"></i>
  </a>
  <a href="/panduan" class="prof-menu-item" style="color:inherit;text-decoration:none">
    <div class="prof-menu-icon" style="background:#f0fdf4"><i class="ph-fill ph-book-open" style="color:#16a34a;font-size:18px"></i></div>
    <span class="prof-menu-label">Buku Panduan</span>
    <i class="ph-bold ph-caret-right prof-menu-arrow"></i>
  </a>
  <?php foreach ($_contact_btns as $_cb): ?>
  <a href="<?= htmlspecialchars($_cb['url']) ?>" target="_blank" rel="noopener" class="prof-menu-item" style="color:inherit;text-decoration:none">
    <div class="prof-menu-icon" style="background:<?= htmlspecialchars($_cb['bg_color']) ?>">
      <?php if ($_cb['icon_type'] === 'custom'): ?>
        <img src="<?= htmlspecialchars($_cb['icon_value']) ?>" style="width:22px;height:22px;object-fit:contain" alt="">
      <?php else: ?>
        <span style="color:#fff;display:flex"><?= $_psvg[$_cb['icon_value']] ?? $_psvg['cs'] ?></span>
      <?php endif; ?>
    </div>
    <span class="prof-menu-label"><?= htmlspecialchars($_cb['label']) ?></span>
    <i class="ph-bold ph-caret-right prof-menu-arrow"></i>
  </a>
  <?php endforeach; ?>
</div>

<!-- ── LOGOUT ── -->
<a href="/logout" id="logout-btn" class="prof-logout">
  <i class="ph-bold ph-sign-out" style="font-size:18px"></i>
  Keluar dari Akun
</a>

</div>

<script>
function toggleSection(id) {
  const body  = document.getElementById('body-' + id);
  const caret = document.getElementById('caret-' + id);
  const isOpen = body.classList.contains('open');
  body.classList.toggle('open', !isOpen);
  caret.classList.toggle('open', !isOpen);
}

function copyRef() {
  const code = document.getElementById('ref-code').textContent.trim();
  navigator.clipboard.writeText(code).then(() => {
    const toast = document.getElementById('prof-ref-toast');
    toast.style.display = 'block';
    setTimeout(() => toast.style.display = 'none', 2000);
  }).catch(() => {});
}

document.getElementById('logout-btn').addEventListener('click', function(e) {
  e.preventDefault();
  const url = this.href;
  if (!this.dataset.confirmed) {
    if (typeof nToast !== 'undefined') nToast('Klik Keluar lagi untuk konfirmasi', 'warn', 3000);
    this.dataset.confirmed = '1';
    setTimeout(() => delete this.dataset.confirmed, 3500);
    return;
  }
  window.location.href = url;
});
</script>

<?php require dirname(__DIR__) . '/partials/footer.php'; ?>
