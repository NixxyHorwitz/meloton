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
/* ══════════════════════════════════════════════
   REFERRAL PAGE — CASUAL GAME STYLE
   ══════════════════════════════════════════════ */
.ref-page { padding: 0 0 20px; }

/* ── Title Bar ── */
.ref-title {
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
.ref-title::before { content:''; position:absolute; top:-20px; right:-10px; width:80px; height:80px; background:rgba(255,255,255,0.05); border-radius:50%; }
.ref-title h1 { font-size:18px; font-weight:900; color:#34d399; display:flex; align-items:center; gap:8px; margin-bottom:4px; letter-spacing:-0.5px; }
.ref-title p { font-size:11px; font-weight:700; color:#94a3b8; }

/* ── Stats ── */
.ref-stats { display: flex; gap: 8px; margin-bottom: 16px; }
.ref-stat {
  flex: 1; border: 2.5px solid #0f172a; border-radius: 16px; padding: 14px 6px; text-align: center; position: relative; overflow: hidden;
  box-shadow: 0 5px 0 #0f172a; background:#fff;
}
.ref-stat-1 { border-color: #0c4a6e; box-shadow: 0 5px 0 #0c4a6e; }
.ref-stat-2 { border-color: #065f46; box-shadow: 0 5px 0 #065f46; }
.ref-stat-3 { border-color: #581c87; box-shadow: 0 5px 0 #581c87; }

.ref-stat__val { font-size: 16px; font-weight: 900; letter-spacing: -0.5px; margin-bottom:4px; color:#0f172a; }
.ref-stat__lbl { font-size: 10px; font-weight: 900; text-transform: uppercase; letter-spacing:0.5px; color:#64748b; }

/* ── Cards ── */
.ref-card {
  border: 3px solid #cbd5e1; border-radius: 20px; background: #fff; box-shadow: 0 6px 0 #cbd5e1;
  margin-bottom: 16px; overflow: hidden;
}
.ref-card__hd {
  padding: 12px 16px; font-size: 13px; font-weight: 900; border-bottom: 3px solid #cbd5e1;
  display: flex; align-items: center; justify-content: space-between; text-transform:uppercase; letter-spacing:0.5px;
  color:#0f172a;
}
.ref-card__bd { padding: 16px; }

/* Promotor Banner Override */
.ref-card.promo-banner {
  background: linear-gradient(135deg, #a7f3d0, #34d399);
  border-color: #059669; box-shadow: 0 6px 0 #059669;
}
.promo-banner .ref-card__bd { display:flex; align-items:center; justify-content:space-between; }
.promo-btn {
  background: #0f172a; color:#fff; font-size:11px; font-weight:900; text-decoration:none;
  padding: 8px 12px; border-radius:10px; border:2px solid #020617; box-shadow:0 3px 0 #020617;
}
.promo-btn:active { transform:translateY(2px); box-shadow:0 1px 0 #020617; }

/* ── Share ── */
.ref-link-box { display: flex; align-items: center; gap: 8px; border: 2.5px solid #cbd5e1; border-radius: 14px; padding: 6px; background: #f8fafc; margin-bottom: 12px; box-shadow:0 3px 0 #e2e8f0; }
.ref-link-box input { border: none; outline: none; background: transparent; font-size: 12px; font-weight: 800; width: 100%; padding: 4px 6px; color: #334155; }
.ref-btn-copy {
  background: linear-gradient(135deg, #38bdf8, #0ea5e9); border: 2px solid #0284c7; border-radius: 10px;
  color: #fff; font-size: 11px; font-weight: 900; padding: 8px 12px; box-shadow: 0 3px 0 #0369a1; cursor:pointer;
}
.ref-btn-copy:active { transform:translateY(2px); box-shadow:0 1px 0 #0369a1; }

.ref-share-row { display: flex; gap: 8px; }
.ref-btn-wa { flex:1; background:linear-gradient(135deg, #4ade80, #22c55e); border:2px solid #16a34a; border-radius:12px; color:#fff; font-size:11px; font-weight:900; padding:10px; text-align:center; box-shadow:0 4px 0 #15803d; text-decoration:none; display:flex; justify-content:center; align-items:center; gap:4px; }
.ref-btn-wa:active { transform:translateY(3px); box-shadow:0 1px 0 #15803d; }
.ref-btn-tg { flex:1; background:linear-gradient(135deg, #60a5fa, #3b82f6); border:2px solid #2563eb; border-radius:12px; color:#fff; font-size:11px; font-weight:900; padding:10px; text-align:center; box-shadow:0 4px 0 #1d4ed8; text-decoration:none; display:flex; justify-content:center; align-items:center; gap:4px; }
.ref-btn-tg:active { transform:translateY(3px); box-shadow:0 1px 0 #1d4ed8; }

/* ── Steps ── */
.ref-steps { display: flex; flex-direction: column; gap: 12px; }
.ref-step { display: flex; align-items: flex-start; gap: 12px; }
.ref-step__num { width: 30px; height: 30px; border-radius: 10px; border: 2.5px solid #0f172a; display: flex; align-items: center; justify-content: center; font-size: 14px; font-weight: 900; flex-shrink: 0; box-shadow: 0 3px 0 #0f172a; color:#0f172a; }
.ref-step__txt { font-size: 12px; font-weight: 800; color: #334155; line-height: 1.4; padding-top: 5px; }

/* ── List ── */
.c-list { display: flex; flex-direction: column; gap:10px; }
.c-list-item { display: flex; align-items: center; gap: 12px; padding: 12px; border:2.5px solid #e2e8f0; border-radius:14px; background:#f8fafc; box-shadow:0 3px 0 #e2e8f0; transition:transform 0.1s; }
.c-list-item:hover { transform:translateY(-2px); border-color:#cbd5e1; box-shadow:0 5px 0 #cbd5e1; }
.c-list-item__icon { width: 38px; height: 38px; border-radius: 10px; border: 2.5px solid #0f172a; display: flex; align-items: center; justify-content: center; font-size: 18px; flex-shrink: 0; box-shadow:0 3px 0 #0f172a; }
.c-list-item__body { flex: 1; min-width: 0; line-height:1.3; }
.c-list-item__title { font-size: 13px; font-weight: 900; color: #0f172a; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.c-list-item__sub { font-size: 10px; font-weight: 800; color: #64748b; margin-top:4px; display:flex; align-items:center; gap:4px; }
.c-list-item__right { text-align: right; }
.c-list-badge { font-size:8px; font-weight:900; padding:3px 6px; border-radius:6px; border:1.5px solid; text-transform:uppercase; display:inline-block; margin-bottom:4px; }
.badge--brand { background:#e0f2fe; color:#0369a1; border-color:#38bdf8; }

/* Pagination */
.ref-pg { display:flex; align-items:center; justify-content:space-between; margin-top:16px; padding-top:16px; border-top:2px dashed #cbd5e1; }
.ref-pg-btn { padding:6px 12px; background:#f1f5f9; border:2px solid #cbd5e1; border-radius:10px; font-size:10px; font-weight:900; color:#64748b; box-shadow:0 2px 0 #cbd5e1; cursor:pointer; }
.ref-pg-btn:active { transform:translateY(2px); box-shadow:none; }
.ref-pg-info { font-size:12px; font-weight:900; color:#475569; }
</style>

<div class="ref-page">
  <!-- Title -->
  <div class="ref-title">
    <h1><i class="ph-bold ph-users-three"></i> Referral</h1>
    <p>Ajak teman, panen komisi berkali-kali</p>
  </div>

  <?php if ((int)$user['is_promotor'] === 1): ?>
  <!-- Promotor Banner -->
  <div class="ref-card promo-banner">
    <div class="ref-card__bd">
      <div>
        <div style="font-weight:900;font-size:14px;color:#064e3b;margin-bottom:2px">🚀 Promotor Aktif</div>
        <div style="font-size:11px;font-weight:700;color:#065f46">Pantau traffic & target harianmu.</div>
      </div>
      <a href="/user/promotor.php" class="promo-btn">Dashboard</a>
    </div>
  </div>
  <?php endif; ?>

  <!-- Stats Row -->
  <div class="ref-stats">
    <div class="ref-stat ref-stat-1">
      <div class="ref-stat__val" style="color:#7e22ce"><?= $ref_count ?></div>
      <div class="ref-stat__lbl">Teman</div>
    </div>
    <div class="ref-stat ref-stat-2">
      <div class="ref-stat__val" style="color:#0369a1"><?= format_rp($ref_earned) ?></div>
      <div class="ref-stat__lbl">Komisi</div>
    </div>
    <div class="ref-stat ref-stat-3">
      <div class="ref-stat__val" style="color:#b45309;font-family:monospace;letter-spacing:1px"><?= $user['referral_code'] ?></div>
      <div class="ref-stat__lbl">Kode Unik</div>
    </div>
  </div>

  <!-- Share Section -->
  <div class="ref-card" style="border-color:#38bdf8;box-shadow:0 6px 0 #38bdf8">
    <div class="ref-card__hd" style="background:#e0f2fe;border-color:#38bdf8;color:#0369a1">🔗 Bagikan Link Referral</div>
    <div class="ref-card__bd">
      <div class="ref-link-box">
        <input id="ref-link-input" type="text" value="<?= htmlspecialchars($ref_url) ?>" readonly>
        <button onclick="copyRef()" class="ref-btn-copy" id="copy-btn">📋 Salin</button>
      </div>
      <div class="ref-share-row">
        <a href="https://wa.me/?text=<?= urlencode('Yuk gabung Meloton! Daftar pakai link ku: ' . $ref_url) ?>" target="_blank" class="ref-btn-wa">
          <i class="ph-bold ph-whatsapp-logo"></i> WhatsApp
        </a>
        <a href="https://t.me/share/url?url=<?= urlencode($ref_url) ?>&text=<?= urlencode('Gabung Meloton, dapat reward tiap nonton video!') ?>" target="_blank" class="ref-btn-tg">
          <i class="ph-bold ph-telegram-logo"></i> Telegram
        </a>
      </div>
    </div>
  </div>

  <!-- How it works -->
  <div class="ref-card" style="border-color:#fde047;box-shadow:0 6px 0 #fde047">
    <div class="ref-card__hd" style="background:#fef9c3;border-color:#fde047;color:#a16207">💡 Cara Kerja</div>
    <div class="ref-card__bd">
      <div class="ref-steps">
        <div class="ref-step">
          <div class="ref-step__num" style="background:#fde047">1</div>
          <div class="ref-step__txt">Bagikan link referral ke teman-temanmu.</div>
        </div>
        <div class="ref-step">
          <div class="ref-step__num" style="background:#a7f3d0">2</div>
          <div class="ref-step__txt">Temanmu mendaftar melalui link tersebut.</div>
        </div>
        <div class="ref-step">
          <div class="ref-step__num" style="background:#e9d5ff">3</div>
          <div class="ref-step__txt">Dapatkan komisi dari setiap transaksi mereka!</div>
        </div>
      </div>
    </div>
  </div>

  <!-- Referred Users -->
  <div class="ref-card">
    <div class="ref-card__hd" style="background:#f8fafc">🧑‍🤝‍🧑 Teman Bergabung</div>
    <div class="ref-card__bd">
      <?php if (empty($referreds)): ?>
      <div style="text-align:center;padding:24px 0;border:3px dashed #e2e8f0;border-radius:16px;background:#f8fafc">
        <div style="font-size:36px;margin-bottom:8px;opacity:0.5">👥</div>
        <div style="font-size:12px;font-weight:800;color:#94a3b8">Belum ada teman yang bergabung.<br>Mulai bagikan link kamu!</div>
      </div>
      <?php else: ?>
      <div class="c-list">
        <?php foreach ($referreds as $idx => $r): ?>
        <div class="c-list-item ref-item-row" data-index="<?= $idx ?>" style="<?= $idx >= 5 ? 'display:none' : '' ?>">
          <div class="c-list-item__icon" style="background:#e0f2fe;color:#0284c7;border-color:#0369a1;box-shadow:0 3px 0 #0369a1">👤</div>
          <div class="c-list-item__body">
            <div class="c-list-item__title"><?= htmlspecialchars($r['username']) ?></div>
            <div class="c-list-item__sub"><i class="ph-bold ph-calendar-blank"></i> <?= date('d M y', strtotime($r['created_at'])) ?></div>
          </div>
          <div class="c-list-item__right">
            <div class="c-list-badge badge--brand"><?= htmlspecialchars($r['membership_name']) ?></div>
            <div style="color:#10b981;font-size:13px;font-weight:900;letter-spacing:-0.5px">+<?= format_rp((float)$r['commission_earned']) ?></div>
            <div style="font-size:9px;color:#94a3b8;font-weight:800;margin-top:2px">Depo: <?= format_rp((float)$r['total_deposit']) ?></div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php if (count($referreds) > 5): ?>
      <div class="ref-pg">
        <button onclick="refPrev()" id="ref-btn-prev" class="ref-pg-btn" style="pointer-events:none;opacity:.5">← Prev</button>
        <span id="ref-page-info" class="ref-pg-info">1/<?= ceil(count($referreds) / 5) ?></span>
        <button onclick="refNext()" id="ref-btn-next" class="ref-pg-btn">Next →</button>
      </div>
      <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>

  <!-- Commission History -->
  <?php if (!empty($history)): ?>
  <div class="ref-card" style="border-color:#a7f3d0;box-shadow:0 6px 0 #a7f3d0">
    <div class="ref-card__hd" style="background:#d1fae5;border-color:#a7f3d0;color:#065f46">💰 Riwayat Komisi Terbaru</div>
    <div class="ref-card__bd">
      <div class="c-list">
        <?php foreach ($history as $h): ?>
        <div class="c-list-item">
          <div class="c-list-item__icon" style="background:#fef08a;color:#d97706;border-color:#b45309;box-shadow:0 3px 0 #b45309">🎁</div>
          <div class="c-list-item__body">
            <div class="c-list-item__title">Dari <?= htmlspecialchars($h['username']) ?></div>
            <div class="c-list-item__sub"><i class="ph-bold ph-clock"></i> <?= date('d M y H:i', strtotime($h['created_at'])) ?></div>
          </div>
          <div class="c-list-item__right" style="color:#10b981;font-size:14px;font-weight:900;letter-spacing:-0.5px">
            +<?= format_rp((float)$h['amount']) ?>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
  <?php endif; ?>

</div>

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
