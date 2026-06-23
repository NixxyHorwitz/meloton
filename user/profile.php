<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/auth/guard.php';

$flash = $flashType = '';
$active_tab = 'profil'; // default — always open profil tab

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'update_profile') {
        $active_tab = 'edit';
        $username = trim($_POST['username'] ?? '');
        if (strlen($username) < 3) { $flash = 'Username minimal 3 karakter.'; $flashType = 'error'; }
        elseif (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) { $flash = 'Username hanya boleh huruf, angka, underscore.'; $flashType = 'error'; }
        else {
            $ex = $pdo->prepare("SELECT id FROM users WHERE username=? AND id!=?");
            $ex->execute([$username, $user['id']]);
            if ($ex->fetch()) { $flash = 'Username sudah digunakan.'; $flashType = 'error'; }
            else {
                $pdo->prepare("UPDATE users SET username=? WHERE id=?")->execute([$username, $user['id']]);
                $flash = '✅ Username berhasil diperbarui.';
                $active_tab = 'profil';
            }
        }
    }
    if ($action === 'change_password') {
        $active_tab = 'password';
        $old = $_POST['old_password'] ?? '';
        $new = $_POST['new_password'] ?? '';
        if (!password_verify($old, $user['password_hash'])) { $flash = 'Password lama salah.'; $flashType = 'error'; }
        elseif (strlen($new) < 6) { $flash = 'Password baru minimal 6 karakter.'; $flashType = 'error'; }
        else {
            $pdo->prepare("UPDATE users SET password_hash=? WHERE id=?")->execute([password_hash($new, PASSWORD_BCRYPT), $user['id']]);
            $flash = '✅ Password berhasil diubah.';
            $active_tab = 'profil';
        }
    }
    
    $ru = $pdo->prepare("SELECT * FROM users WHERE id=?"); $ru->execute([$user['id']]); $user = $ru->fetch();
}

// Stats
$total_watches = (int)$pdo->prepare("SELECT COUNT(*) FROM watch_history WHERE user_id=?")->execute([$user['id']]) ? $pdo->query("SELECT COUNT(*) FROM watch_history WHERE user_id={$user['id']}")->fetchColumn() : 0;
$st = $pdo->prepare("SELECT COUNT(*) FROM watch_history WHERE user_id=?"); $st->execute([$user['id']]); $total_watches = (int)$st->fetchColumn();
$refs = $pdo->prepare("SELECT COUNT(*) FROM users WHERE referred_by=?"); $refs->execute([$user['referral_code']]); $refs = (int)$refs->fetchColumn();

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

// Contact buttons
try {
    $_contact_btns = $pdo->query("SELECT * FROM contact_buttons WHERE is_active=1 ORDER BY sort_order ASC, id ASC")->fetchAll();
} catch (\Throwable) { $_contact_btns = []; }

$pageTitle  = 'Profil — Meloton';
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
/* ── Profile compact styles ── */
.prof-hero{display:flex;align-items:center;gap:12px;padding:12px 14px;background:var(--yellow);border:2.5px solid var(--ink);border-radius:14px;box-shadow:4px 4px 0 var(--ink);margin-bottom:12px}
.prof-avatar{width:52px;height:52px;flex-shrink:0;background:var(--brand);color:#fff;border:2.5px solid var(--ink);box-shadow:3px 3px 0 var(--ink);border-radius:14px;display:flex;align-items:center;justify-content:center;font-size:24px;font-weight:900}
.prof-name{font-size:16px;font-weight:900;line-height:1.2}
.prof-email{font-size:11px;color:#555;margin-top:1px;word-break:break-all}
.prof-stats{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-bottom:12px}
.prof-stat{background:var(--white);border:2px solid var(--ink);border-radius:10px;box-shadow:2px 2px 0 var(--ink);padding:8px 6px;text-align:center}
.prof-stat__val{font-size:15px;font-weight:900;line-height:1}
.prof-stat__lbl{font-size:9px;font-weight:700;color:#888;margin-top:3px}
/* Ref code */
.ref-row{display:flex;align-items:center;gap:8px;padding:10px 12px;background:var(--bg);border:2px solid var(--ink);border-radius:10px;margin-bottom:12px}
.ref-code{flex:1;font-size:16px;font-weight:900;letter-spacing:3px;text-align:center}
/* Tabs */
.prof-tabs{display:flex;gap:0;margin-bottom:12px;border:2px solid var(--ink);border-radius:10px;overflow:hidden;box-shadow:3px 3px 0 var(--ink)}
.prof-tab{flex:1;padding:9px 6px;font-size:12px;font-weight:800;text-align:center;cursor:pointer;background:var(--white);border:none;outline:none;transition:background .15s;color:var(--ink)}
.prof-tab:not(:last-child){border-right:2px solid var(--ink)}
.prof-tab.active{background:var(--yellow)}
.prof-tab-panel{display:none}.prof-tab-panel.active{display:block}
/* Contact list */
.contact-item{display:flex;align-items:center;gap:10px;padding:9px 12px;border:1.5px solid var(--ink);border-radius:10px;box-shadow:2px 2px 0 var(--ink);text-decoration:none;color:var(--ink);background:var(--white);margin-bottom:8px;transition:transform .1s}
.contact-item:active{transform:translate(1px,1px);box-shadow:1px 1px 0 var(--ink)}
.contact-icon{width:34px;height:34px;flex-shrink:0;border-radius:9px;border:1.5px solid var(--ink);display:flex;align-items:center;justify-content:center;overflow:hidden}
/* Logout */
.logout-btn{display:flex;align-items:center;justify-content:center;gap:8px;background:#FF4D4D;color:#fff;border:2.5px solid var(--ink);border-radius:12px;box-shadow:3px 3px 0 var(--ink);padding:12px 16px;font-weight:900;font-size:14px;text-decoration:none;transition:transform .1s}
.logout-btn:active{transform:translate(2px,2px);box-shadow:1px 1px 0 var(--ink)}
</style>

<?php if ($flash): ?>
<div class="alert alert--<?= $flashType === 'error' ? 'error' : 'success' ?>" style="margin-bottom:10px;font-size:13px"><?= htmlspecialchars($flash) ?></div>
<?php endif; ?>

<!-- Hero -->
<div class="prof-hero">
  <div class="prof-avatar"><?= strtoupper(substr($user['username'], 0, 1)) ?></div>
  <div style="flex:1;min-width:0">
    <div class="prof-name"><?= htmlspecialchars($user['username']) ?></div>
    <div class="prof-email"><?= htmlspecialchars($user['email']) ?></div>
    <div style="margin-top:5px;display:flex;gap:5px;flex-wrap:wrap">
      <span class="badge badge--brand" style="font-size:10px;display:inline-flex;align-items:center;gap:3px"><i class="ph-fill ph-star"></i> <?= $membership_name ?></span>
      <?php if ($user['membership_expires_at'] && strtotime($user['membership_expires_at']) > time()): ?>
      <span class="badge badge--neutral" style="font-size:10px">s/d <?= date('d M Y', strtotime($user['membership_expires_at'])) ?></span>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- Stats -->
<div class="prof-stats">
  <div class="prof-stat">
    <div class="prof-stat__val" style="font-size:13px"><?= format_rp((float)$user['total_earned']) ?></div>
    <div class="prof-stat__lbl">Total Earned</div>
  </div>
  <div class="prof-stat">
    <div class="prof-stat__val"><?= number_format((int)$total_watches) ?></div>
    <div class="prof-stat__lbl">Video Ditonton</div>
  </div>
  <div class="prof-stat">
    <div class="prof-stat__val"><?= (int)$refs ?></div>
    <div class="prof-stat__lbl">Referral</div>
  </div>
</div>

<!-- Referral code -->
<div class="ref-row">
  <div style="font-size:10px;font-weight:700;color:#888;flex-shrink:0"><i class="ph-bold ph-link" style="color:var(--sky);font-size:14px"></i> Referral</div>
  <div class="ref-code" id="ref-code"><?= $user['referral_code'] ?></div>
  <button onclick="copyRef()" class="btn btn--secondary btn--sm" style="flex-shrink:0;font-size:11px;padding:4px 10px">Salin</button>
</div>

<!-- Tabs: Profil | Edit Profil | Ganti Password -->
<div class="prof-tabs" role="tablist">
  <button class="prof-tab <?= $active_tab === 'profil' ? 'active' : '' ?>" onclick="switchTab('profil')" id="tab-profil"><i class="ph-bold ph-user-circle" style="color:var(--blue);font-size:14px;vertical-align:middle"></i> Profil</button>
  <button class="prof-tab <?= $active_tab === 'edit' ? 'active' : '' ?>" onclick="switchTab('edit')" id="tab-edit"><i class="ph-bold ph-pencil-simple" style="color:var(--orange);font-size:14px;vertical-align:middle"></i> Edit</button>
  <button class="prof-tab <?= $active_tab === 'password' ? 'active' : '' ?>" onclick="switchTab('password')" id="tab-password"><i class="ph-bold ph-lock-key" style="color:var(--green);font-size:14px;vertical-align:middle"></i> Password</button>
</div>

<!-- Tab: Profil (default) -->
<div class="prof-tab-panel <?= $active_tab === 'profil' ? 'active' : '' ?>" id="panel-profil">
  <div class="card" style="margin-bottom:12px">
    <div class="card__body">
      <div style="display:flex;flex-direction:column;gap:8px">
        <div style="display:flex;justify-content:space-between;font-size:13px">
          <span style="color:#888;font-weight:600;display:flex;align-items:center;gap:4px"><i class="ph-bold ph-identification-card" style="color:var(--brand)"></i> Username</span>
          <span style="font-weight:800"><?= htmlspecialchars($user['username']) ?></span>
        </div>
        <div style="display:flex;justify-content:space-between;font-size:13px">
          <span style="color:#888;font-weight:600;display:flex;align-items:center;gap:4px"><i class="ph-bold ph-envelope-simple" style="color:var(--sky)"></i> Email</span>
          <span style="font-weight:700;font-size:12px"><?= htmlspecialchars($user['email']) ?></span>
        </div>
        <div style="display:flex;justify-content:space-between;font-size:13px">
          <span style="color:#888;font-weight:600;display:flex;align-items:center;gap:4px"><i class="ph-bold ph-whatsapp-logo" style="color:var(--green)"></i> WhatsApp</span>
          <span style="font-weight:700"><?= htmlspecialchars(mask_account($user['whatsapp'] ?? '')) ?></span>
        </div>
      </div>
    </div>
  </div>
  <!-- Bank info -->
  <div class="card" style="margin-bottom:12px">
    <div class="card__header">
      <div class="card__title" style="font-size:13px"><i class="ph-bold ph-bank" style="color:var(--blue);font-size:16px;vertical-align:middle"></i> Rekening Bank</div>
    </div>
    <div class="card__body">
      <?php if (!empty($user['bank_name'])): ?>
      <div style="display:flex;flex-direction:column;gap:6px;font-size:13px">
        <div style="display:flex;justify-content:space-between">
          <span style="color:#888;font-weight:600">Bank</span><span style="font-weight:800"><?= htmlspecialchars($user['bank_name']) ?></span>
        </div>
        <div style="display:flex;justify-content:space-between">
          <span style="color:#888;font-weight:600">Nomor</span><span style="font-weight:800"><?= htmlspecialchars(mask_account($user['account_number'] ?? '')) ?></span>
        </div>
        <div style="display:flex;justify-content:space-between">
          <span style="color:#888;font-weight:600">A/N</span><span style="font-weight:800"><?= htmlspecialchars($user['account_name']) ?></span>
        </div>
      </div>
      <?php else: ?>
      <div style="color:#aaa;font-size:12px;text-align:center;padding:8px">Belum ada rekening tersimpan.</div>
      <?php endif; ?>
      <?php if ($show_edit_rek_btn): ?>
      <div style="margin-top:12px;padding-top:10px;border-top:1px dashed #ddd">
        <a href="/edit-rekening" class="btn btn--ghost btn--full" style="font-size:12px;display:flex;align-items:center;justify-content:center;gap:4px">
          <i class="ph-bold ph-pencil-simple" style="color:var(--orange)"></i> Edit Rekening Bank
          <?php if (!$dep_ok_for_edit): ?>
          <span style="font-size:10px;color:#f59e0b;margin-left:4px"><i class="ph-bold ph-lock-key"></i> Butuh deposit Rp <?= number_format($edit_bank_min_dep,0,'','') ?></span>
          <?php endif; ?>
        </a>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- Tab: Edit Profil -->
<div class="prof-tab-panel <?= $active_tab === 'edit' ? 'active' : '' ?>" id="panel-edit">
  <div class="card" style="margin-bottom:12px">
    <div class="card__body">
      <form method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="update_profile">
        <div class="form-group" style="margin-bottom:8px">
          <label class="form-label" style="font-size:12px">Username</label>
          <input class="form-control" type="text" name="username"
                 value="<?= htmlspecialchars($user['username']) ?>"
                 pattern="[a-zA-Z0-9_]+" minlength="3" required>
          <div class="form-hint">3–30 karakter · huruf, angka, underscore</div>
        </div>
        <div class="form-group" style="margin-bottom:10px">
          <label class="form-label" style="font-size:12px">WhatsApp <span style="font-weight:600;color:#aaa;font-size:10px"><i class="ph-bold ph-lock-key"></i> tidak dapat diubah</span></label>
          <input class="form-control" type="tel" value="<?= htmlspecialchars($user['whatsapp']) ?>"
                 disabled readonly style="background:var(--bg);color:#aaa;cursor:not-allowed">
        </div>
        <button type="submit" class="btn btn--primary btn--full" style="font-size:13px;display:flex;align-items:center;justify-content:center;gap:6px"><i class="ph-bold ph-floppy-disk"></i> Simpan Username</button>
      </form>
    </div>
  </div>
</div>

<!-- Tab: Ganti Password -->
<div class="prof-tab-panel <?= $active_tab === 'password' ? 'active' : '' ?>" id="panel-password">
  <div class="card" style="margin-bottom:12px">
    <div class="card__body">
      <form method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="change_password">
        <div class="form-group" style="margin-bottom:8px">
          <label class="form-label" style="font-size:12px">Password Lama</label>
          <input class="form-control" type="password" name="old_password" required>
        </div>
        <div class="form-group" style="margin-bottom:10px">
          <label class="form-label" style="font-size:12px">Password Baru <span style="color:#aaa;font-size:10px">min. 6 karakter</span></label>
          <input class="form-control" type="password" name="new_password" required>
        </div>
        <button type="submit" class="btn btn--ghost btn--full" style="font-size:13px;display:flex;align-items:center;justify-content:center;gap:6px"><i class="ph-bold ph-lock-key"></i> Ganti Password</button>
      </form>
    </div>
  </div>
</div>

<?php if (!empty($_contact_btns)): ?>
<!-- Transaksi -->
<div style="font-size:11px;font-weight:800;color:#888;margin-bottom:6px;margin-top:4px">TRANSAKSI</div>
<a href="/history" class="contact-item">
  <div class="contact-icon" style="background:var(--sky);color:#fff;font-size:18px"><i class="ph-fill ph-receipt"></i></div>
  <div style="flex:1;font-weight:800;font-size:13px">Riwayat Transaksi</div>
  <svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" style="opacity:.35"><polyline points="9 18 15 12 9 6"/></svg>
</a>

<!-- Community / Contact -->
<div style="font-size:11px;font-weight:800;color:#888;margin-bottom:6px;margin-top:4px">BANTUAN & KOMUNITAS</div>
<a href="/panduan" class="contact-item">
  <div class="contact-icon" style="background:var(--brand);color:#fff;font-size:18px"><i class="ph-fill ph-book-open"></i></div>
  <div style="flex:1;font-weight:800;font-size:13px">Buku Panduan</div>
  <svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" style="opacity:.35"><polyline points="9 18 15 12 9 6"/></svg>
</a>
<?php if (setting($pdo, 'plinko_enabled', '1') === '1'): ?>
<a href="/events" class="contact-item">
  <div class="contact-icon" style="background:var(--yellow);color:var(--ink);font-size:18px"><i class="ph-fill ph-trophy"></i></div>
  <div style="flex:1;font-weight:800;font-size:13px">Event Plinko & Lapak</div>
  <svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" style="opacity:.35"><polyline points="9 18 15 12 9 6"/></svg>
</a>
<?php endif; ?>

<?php foreach ($_contact_btns as $_cb): ?>
<a href="<?= htmlspecialchars($_cb['url']) ?>" target="_blank" rel="noopener" class="contact-item">
  <div class="contact-icon" style="background:<?= htmlspecialchars($_cb['bg_color']) ?>">
    <?php if ($_cb['icon_type'] === 'custom'): ?>
      <img src="<?= htmlspecialchars($_cb['icon_value']) ?>" style="width:100%;height:100%;object-fit:cover" alt="">
    <?php else: ?>
      <span style="color:#fff;display:flex"><?= $_psvg[$_cb['icon_value']] ?? $_psvg['cs'] ?></span>
    <?php endif; ?>
  </div>
  <div style="flex:1;font-weight:800;font-size:13px"><?= htmlspecialchars($_cb['label']) ?></div>
  <svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" style="opacity:.35"><polyline points="9 18 15 12 9 6"/></svg>
</a>
<?php endforeach; ?>
<div style="margin-bottom:12px"></div>
<?php endif; ?>

<!-- Logout -->
<div style="margin-bottom:8px">
  <a href="/logout" id="logout-btn" class="logout-btn">
    <i class="ph-bold ph-sign-out" style="font-size:18px"></i>
    Keluar dari Akun
  </a>
</div>

<script>
function switchTab(tab) {
  document.querySelectorAll('.prof-tab').forEach(t => t.classList.remove('active'));
  document.querySelectorAll('.prof-tab-panel').forEach(p => p.classList.remove('active'));
  document.getElementById('tab-' + tab).classList.add('active');
  document.getElementById('panel-' + tab).classList.add('active');
}
function copyRef() {
  const c = document.getElementById('ref-code').textContent.trim();
  if (typeof nToast !== 'undefined' && nToast.copy) {
    nToast.copy(c, 'Kode referral');
  } else {
    navigator.clipboard.writeText(c).then(() => nToast('Kode referral disalin!', 'success'));
  }
}
document.getElementById('logout-btn').addEventListener('click', function(e) {
  e.preventDefault();
  const url = this.href;
  if (!this.dataset.confirmed) {
    nToast('Klik Keluar lagi untuk konfirmasi', 'warn', 3000);
    this.dataset.confirmed = '1';
    setTimeout(() => delete this.dataset.confirmed, 3500);
    return;
  }
  window.location.href = url;
});
</script>

<?php require dirname(__DIR__) . '/partials/footer.php'; ?>
