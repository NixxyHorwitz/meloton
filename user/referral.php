<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/auth/guard.php';

// Referral stats
$s = $pdo->prepare("SELECT COUNT(*) FROM users WHERE referred_by=?");
$s->execute([$user['referral_code']]);
$ref_count = (int)$s->fetchColumn();

$e = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM referral_commissions WHERE user_id=?");
$e->execute([$user['id']]);
$ref_earned = (float)$e->fetchColumn();

// Referral history
$hist = $pdo->prepare(
  "SELECT rc.amount, rc.created_at, u.username
   FROM referral_commissions rc
   JOIN users u ON u.id = rc.from_user_id
   WHERE rc.user_id = ?
   ORDER BY rc.created_at DESC LIMIT 20"
);
$hist->execute([$user['id']]);
$history = $hist->fetchAll();

// Referred users list
$refs = $pdo->prepare(
  "SELECT u.username, u.created_at, 
          COALESCE(m.name, 'Free') as membership_name,
          COALESCE((SELECT SUM(amount) FROM deposits WHERE user_id = u.id AND status = 'confirmed'), 0) as total_deposit,
          COALESCE((SELECT SUM(amount) FROM referral_commissions WHERE user_id = ? AND from_user_id = u.id), 0) as commission_earned
   FROM users u
   LEFT JOIN memberships m ON m.id = u.membership_id
   WHERE u.referred_by = ?
   ORDER BY u.created_at DESC"
);
$refs->execute([$user['id'], $user['referral_code']]);
$referreds = $refs->fetchAll();

$ref_url = base_url('register/' . $user['referral_code']);

$pageTitle  = 'Referral — Meloton';
$activePage = 'referral';
require dirname(__DIR__) . '/partials/header.php';
?>

<style>
/* ── Compact Neo-Brutalism Overrides ── */
.ref-card { border: 2.5px solid var(--ink); border-radius: 10px; background: #fff; box-shadow: 3px 3px 0 var(--ink); margin-bottom: 12px; overflow: hidden; }
.ref-card__hd { padding: 8px 12px; font-size: 13px; font-weight: 900; border-bottom: 2px solid var(--ink); background: var(--yellow); display: flex; align-items: center; justify-content: space-between; }
.ref-card__bd { padding: 10px 12px; }

/* Stats mini */
.ref-stats { display: flex; gap: 8px; margin-bottom: 12px; }
.ref-stat { flex: 1; border: 2.5px solid var(--ink); border-radius: 10px; background: #fff; box-shadow: 3px 3px 0 var(--ink); padding: 8px; text-align: center; }
.ref-stat__val { font-size: 15px; font-weight: 900; color: var(--ink); line-height: 1.1; }
.ref-stat__lbl { font-size: 10px; font-weight: 800; color: #666; margin-top: 3px; }

/* Link & Share */
.ref-link-box { display: flex; align-items: center; gap: 6px; border: 2px solid var(--ink); border-radius: 8px; padding: 4px; background: var(--white); margin-bottom: 8px; }
.ref-link-box input { border: none; outline: none; background: transparent; font-size: 11px; font-weight: 700; width: 100%; padding: 4px 6px; color: #555; }
.ref-link-box .btn { padding: 4px 10px; font-size: 11px; }

.ref-share-row { display: flex; gap: 6px; }
.ref-share-row .btn { flex: 1; font-size: 11px; padding: 6px; display: flex; justify-content: center; align-items: center; gap: 4px; }

/* How it works */
.ref-steps { display: flex; flex-direction: column; gap: 8px; }
.ref-step { display: flex; align-items: flex-start; gap: 8px; }
.ref-step__num { width: 22px; height: 22px; border-radius: 6px; border: 2px solid var(--ink); display: flex; align-items: center; justify-content: center; font-size: 11px; font-weight: 900; flex-shrink: 0; box-shadow: 1.5px 1.5px 0 var(--ink); }
.ref-step__txt { font-size: 11px; font-weight: 700; color: #444; line-height: 1.3; padding-top: 3px; }

/* Compact List */
.c-list { display: flex; flex-direction: column; }
.c-list-item { display: flex; align-items: center; gap: 8px; padding: 8px 0; border-bottom: 1.5px dashed #ccc; }
.c-list-item:last-child { border-bottom: none; }
.c-list-item__icon { width: 28px; height: 28px; border-radius: 8px; border: 1.5px solid var(--ink); display: flex; align-items: center; justify-content: center; font-size: 14px; flex-shrink: 0; }
.c-list-item__body { flex: 1; min-width: 0; }
.c-list-item__title { font-size: 12px; font-weight: 900; color: var(--ink); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; line-height: 1.2; }
.c-list-item__sub { font-size: 9px; font-weight: 700; color: #666; margin-top: 2px; }
.c-list-item__right { text-align: right; font-size: 11px; font-weight: 900; }
</style>

<div class="page-title-bar" style="margin-bottom:12px">
  <h1 style="font-size:18px">👥 Referral</h1>
  <p style="font-size:11px">Ajak teman, dapatkan komisi otomatis</p>
</div>

<?php if ((int)$user['is_promotor'] === 1): ?>
<!-- Promotor Banner -->
<div class="ref-card" style="background:var(--mint)">
  <div class="ref-card__bd" style="display:flex;align-items:center;justify-content:space-between;padding:10px">
    <div>
      <div style="font-weight:900;font-size:13px">🚀 Promotor Aktif</div>
      <div style="font-size:10px;font-weight:700;color:#555">Pantau traffic & target harianmu.</div>
    </div>
    <a href="/user/promotor.php" class="btn btn--primary btn--sm" style="font-size:10px;padding:5px 10px">Dashboard</a>
  </div>
</div>
<?php endif; ?>

<!-- Stats Row -->
<div class="ref-stats">
  <div class="ref-stat">
    <div class="ref-stat__val"><?= $ref_count ?></div>
    <div class="ref-stat__lbl">Teman Diajak</div>
  </div>
  <div class="ref-stat">
    <div class="ref-stat__val" style="color:var(--green)"><?= format_rp($ref_earned) ?></div>
    <div class="ref-stat__lbl">Total Komisi</div>
  </div>
  <div class="ref-stat">
    <div class="ref-stat__val" style="font-family:monospace;letter-spacing:1px"><?= $user['referral_code'] ?></div>
    <div class="ref-stat__lbl">Kode Referral</div>
  </div>
</div>

<!-- Share Section -->
<div class="ref-card">
  <div class="ref-card__hd">🔗 Bagikan Link Referral</div>
  <div class="ref-card__bd">
    <div class="ref-link-box">
      <input id="ref-link-input" type="text" value="<?= htmlspecialchars($ref_url) ?>" readonly>
      <button onclick="copyRef()" class="btn btn--primary" id="copy-btn">📋 Salin</button>
    </div>
    <div class="ref-share-row">
      <a href="https://wa.me/?text=<?= urlencode('Yuk gabung Meloton! Daftar pakai link ku: ' . $ref_url) ?>" target="_blank" class="btn btn--green">
        <i class="ph-bold ph-whatsapp-logo"></i> WhatsApp
      </a>
      <a href="https://t.me/share/url?url=<?= urlencode($ref_url) ?>&text=<?= urlencode('Gabung Meloton, dapat reward tiap nonton video!') ?>" target="_blank" class="btn btn--ghost">
        <i class="ph-bold ph-telegram-logo"></i> Telegram
      </a>
    </div>
  </div>
</div>

<!-- How it works -->
<div class="ref-card">
  <div class="ref-card__hd" style="background:var(--sky)">💡 Cara Kerja</div>
  <div class="ref-card__bd">
    <div class="ref-steps">
      <div class="ref-step">
        <div class="ref-step__num" style="background:var(--yellow)">1</div>
        <div class="ref-step__txt">Bagikan link referral ke teman-temanmu</div>
      </div>
      <div class="ref-step">
        <div class="ref-step__num" style="background:var(--mint)">2</div>
        <div class="ref-step__txt">Teman mendaftar melalui link tersebut</div>
      </div>
      <div class="ref-step">
        <div class="ref-step__num" style="background:var(--lavender)">3</div>
        <div class="ref-step__txt">Dapatkan komisi dari setiap deposit mereka</div>
      </div>
    </div>
  </div>
</div>

<!-- Referred Users -->
<div class="ref-card">
  <div class="ref-card__hd" style="background:var(--white)">🧑‍🤝‍🧑 Teman Bergabung</div>
  <div class="ref-card__bd">
    <?php if (empty($referreds)): ?>
    <div style="text-align:center;padding:16px 0;color:#aaa">
      <div style="font-size:24px;margin-bottom:4px">👥</div>
      <div style="font-size:11px;font-weight:700">Belum ada teman yang bergabung.<br>Mulai bagikan link kamu!</div>
    </div>
    <?php else: ?>
    <div class="c-list">
      <?php foreach ($referreds as $idx => $r): ?>
      <div class="c-list-item ref-item-row" data-index="<?= $idx ?>" style="<?= $idx >= 5 ? 'display:none' : '' ?>">
        <div class="c-list-item__icon" style="background:var(--sky)">👤</div>
        <div class="c-list-item__body">
          <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:2px">
            <span class="c-list-item__title"><?= htmlspecialchars($r['username']) ?></span>
            <span class="badge badge--brand" style="font-size:8px;padding:1px 5px"><?= htmlspecialchars($r['membership_name']) ?></span>
          </div>
          <div class="c-list-item__sub">Bergabung: <?= date('d M y', strtotime($r['created_at'])) ?></div>
        </div>
        <div class="c-list-item__right" style="text-align:right">
          <div style="color:var(--green)">+<?= format_rp((float)$r['commission_earned']) ?></div>
          <div style="font-size:9px;color:#888;font-weight:800;margin-top:2px">Depo: <?= format_rp((float)$r['total_deposit']) ?></div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php if (count($referreds) > 5): ?>
    <div style="display:flex;align-items:center;justify-content:space-between;margin-top:10px;padding-top:10px;border-top:2px solid var(--ink)">
      <button onclick="refPrev()" id="ref-btn-prev" class="btn btn--ghost btn--sm" style="font-size:10px;padding:4px 12px;border:1.5px solid var(--ink);border-radius:12px;pointer-events:none;opacity:.5">← Prev</button>
      <span id="ref-page-info" style="font-size:11px;font-weight:800;color:#666">1/<?= ceil(count($referreds) / 5) ?></span>
      <button onclick="refNext()" id="ref-btn-next" class="btn btn--ghost btn--sm" style="font-size:10px;padding:4px 12px;border:1.5px solid var(--ink);border-radius:12px">Next →</button>
    </div>
    <?php endif; ?>
    <?php endif; ?>
  </div>
</div>

<!-- Commission History -->
<?php if (!empty($history)): ?>
<div class="ref-card">
  <div class="ref-card__hd" style="background:var(--lime)">💰 Riwayat Komisi Terbaru</div>
  <div class="ref-card__bd">
    <div class="c-list">
      <?php foreach ($history as $h): ?>
      <div class="c-list-item">
        <div class="c-list-item__icon" style="background:var(--lime)">🎁</div>
        <div class="c-list-item__body">
          <div class="c-list-item__title">Dari <?= htmlspecialchars($h['username']) ?></div>
          <div class="c-list-item__sub"><?= date('d M y H:i', strtotime($h['created_at'])) ?></div>
        </div>
        <div class="c-list-item__right" style="color:var(--green)">
          +<?= format_rp((float)$h['amount']) ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>
<?php endif; ?>

<script>
function copyRef() {
  const input = document.getElementById('ref-link-input');
  input.select();
  input.setSelectionRange(0, 99999);
  navigator.clipboard.writeText(input.value).then(() => {
    const btn = document.getElementById('copy-btn');
    btn.textContent = '✅ Tersalin!';
    setTimeout(() => btn.textContent = '📋 Salin', 2000);
  }).catch(() => {
    document.execCommand('copy');
  });
}
</script>

<script>
let refCurrentPage = 1;
const refLimit = 5;
const refTotal = <?= count($referreds) ?>;
const refTotalPages = Math.max(1, Math.ceil(refTotal / refLimit));

function updateRefPagination() {
  const items = document.querySelectorAll('.ref-item-row');
  items.forEach((item, idx) => {
    if (idx >= (refCurrentPage - 1) * refLimit && idx < refCurrentPage * refLimit) {
      item.style.display = 'flex';
    } else {
      item.style.display = 'none';
    }
  });
  
  const info = document.getElementById('ref-page-info');
  if (info) info.textContent = refCurrentPage + '/' + refTotalPages;
  
  const prevBtn = document.getElementById('ref-btn-prev');
  if (prevBtn) {
    prevBtn.style.opacity = refCurrentPage <= 1 ? '0.5' : '1';
    prevBtn.style.pointerEvents = refCurrentPage <= 1 ? 'none' : 'auto';
  }
  const nextBtn = document.getElementById('ref-btn-next');
  if (nextBtn) {
    nextBtn.style.opacity = refCurrentPage >= refTotalPages ? '0.5' : '1';
    nextBtn.style.pointerEvents = refCurrentPage >= refTotalPages ? 'none' : 'auto';
  }
}

function refPrev() {
  if (refCurrentPage > 1) {
    refCurrentPage--;
    updateRefPagination();
  }
}

function refNext() {
  if (refCurrentPage < refTotalPages) {
    refCurrentPage++;
    updateRefPagination();
  }
}
</script>

<?php require dirname(__DIR__) . '/partials/footer.php'; ?>
