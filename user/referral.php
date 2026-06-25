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
   REFERRAL PAGE — TRUE CASUAL GAME STYLE
   ══════════════════════════════════════════════ */
.ref-page { padding: 0 0 20px; }

/* ── Hero Banner ── */
.ref-hero {
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
.ref-hero::before { content:''; position:absolute; top:-10px; left:-10px; width:60px; height:60px; background:url('/assets/dollar.png') no-repeat center/contain; opacity:0.15; transform:rotate(-15deg); pointer-events:none; }
.ref-hero::after { content:''; position:absolute; bottom:-10px; right:-10px; width:80px; height:80px; background:rgba(255,255,255,0.1); border-radius:50%; pointer-events:none; }
.ref-hero-star { position:absolute; top:12px; right:20px; color:#fde047; font-size:24px; opacity:0.6; transform:rotate(20deg); pointer-events:none; }
.ref-hero__lbl { font-size:12px; font-weight:900; color:#e0f2fe; margin-bottom:4px; text-transform:uppercase; letter-spacing:1px; display:flex; align-items:center; justify-content:center; gap:6px; position:relative; z-index:1; }
.ref-hero__val { font-size:22px; font-weight:900; color:#fef08a; text-shadow:0 2px 4px rgba(0,0,0,0.2); letter-spacing:-0.5px; position:relative; z-index:1; }

/* ── Promotor Banner ── */
.ref-promo {
  background: linear-gradient(135deg, #34d399, #10b981);
  border: 3px solid #059669;
  border-radius: 16px;
  box-shadow: 0 5px 0 #047857;
  padding: 12px 16px;
  margin-bottom: 16px;
  display: flex; align-items: center; justify-content: space-between;
}
.ref-promo__text { font-size:14px; font-weight:900; color:#fff; text-shadow:0 1px 2px rgba(0,0,0,0.2); }
.ref-promo__sub { font-size:10px; font-weight:800; color:#ecfdf5; margin-top:2px; }
.ref-promo__btn {
  background: linear-gradient(135deg, #fde047, #f59e0b); color: #78350f;
  border: 2px solid #fff; border-radius: 10px;
  font-weight: 900; font-size:10px; padding:6px 12px;
  box-shadow: 0 3px 0 #d97706; text-decoration: none;
}
.ref-promo__btn:active { transform:translateY(2px); box-shadow:0 1px 0 #d97706; }

/* ── Stats ── */
.ref-stats { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; margin-bottom: 16px; }
.ref-stat {
  background: #fff; border: 2.5px solid #7dd3e8; border-radius: 16px;
  box-shadow: 0 5px 0 #7dd3e8; padding: 12px 6px; text-align: center;
}
.ref-stat__val { font-size: 16px; font-weight: 900; color: #0284c7; margin-bottom: 2px; letter-spacing: -0.5px; }
.ref-stat__val--purple { color: #7e22ce; }
.ref-stat__val--orange { color: #d97706; }
.ref-stat__lbl { font-size: 9px; font-weight: 900; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px; }

/* ── Form Card ── */
.ref-card {
  background: #fff;
  border: 3px solid #7dd3e8;
  border-radius: 20px;
  box-shadow: 0 6px 0 #7dd3e8;
  padding: 16px;
  margin-bottom: 16px;
}
.ref-card-title {
  font-size: 14px; font-weight: 900; color: #0369a1;
  display: flex; align-items: center; gap: 6px; margin-bottom: 12px;
  border-bottom: 2.5px solid #e0f2fe; padding-bottom: 10px; text-transform:uppercase; letter-spacing:0.5px;
}

/* ── Share ── */
.ref-link-box { display: flex; align-items: center; gap: 8px; border: 2.5px solid #bae6fd; border-radius: 14px; padding: 6px; background: #f0f9ff; margin-bottom: 12px; box-shadow: 0 3px 0 #e0f2fe; }
.ref-link-box input { flex: 1; border: none; background: transparent; font-size: 12px; font-weight: 800; color: #0c4a6e; outline: none; }
.ref-btn-copy {
  background: linear-gradient(135deg, #fde047, #f59e0b); border: 2px solid #fff; border-radius: 10px;
  color: #78350f; font-size: 11px; font-weight: 900; padding: 8px 12px; box-shadow: 0 3px 0 #d97706; cursor: pointer; text-shadow:0 1px 0 rgba(255,255,255,0.5);
}
.ref-btn-copy:active { transform: translateY(2px); box-shadow: 0 1px 0 #d97706; }

.ref-share-row { display: flex; gap: 8px; }
.ref-btn-wa {
  flex: 1; background: linear-gradient(135deg, #4ade80, #22c55e); border: 2.5px solid #fff; border-radius: 12px;
  color: #fff; font-size: 12px; font-weight: 900; padding: 10px; text-align: center; box-shadow: 0 4px 0 #15803d;
  text-decoration: none; display: flex; justify-content: center; align-items: center; gap: 4px; text-shadow: 0 1px 2px rgba(0,0,0,0.2);
}
.ref-btn-wa:active { transform: translateY(3px); box-shadow: 0 1px 0 #15803d; }
.ref-btn-tg {
  flex: 1; background: linear-gradient(135deg, #60a5fa, #3b82f6); border: 2.5px solid #fff; border-radius: 12px;
  color: #fff; font-size: 12px; font-weight: 900; padding: 10px; text-align: center; box-shadow: 0 4px 0 #1d4ed8;
  text-decoration: none; display: flex; justify-content: center; align-items: center; gap: 4px; text-shadow: 0 1px 2px rgba(0,0,0,0.2);
}
.ref-btn-tg:active { transform: translateY(3px); box-shadow: 0 1px 0 #1d4ed8; }

/* ── Steps ── */
.ref-step { display: flex; align-items: flex-start; gap: 12px; margin-bottom: 12px; }
.ref-step:last-child { margin-bottom: 0; }
.ref-step__num {
  width: 32px; height: 32px; border-radius: 10px; border: 2.5px solid #fff;
  display: flex; align-items: center; justify-content: center; font-size: 14px; font-weight: 900; flex-shrink: 0; color: #fff; text-shadow: 0 1px 2px rgba(0,0,0,0.2);
}
.ref-step__num--1 { background: linear-gradient(135deg, #fde047, #f59e0b); box-shadow: 0 3px 0 #d97706; color: #78350f; text-shadow: none; }
.ref-step__num--2 { background: linear-gradient(135deg, #34d399, #10b981); box-shadow: 0 3px 0 #059669; }
.ref-step__num--3 { background: linear-gradient(135deg, #c084fc, #9333ea); box-shadow: 0 3px 0 #7e22ce; }
.ref-step__txt { font-size: 12px; font-weight: 800; color: #334155; line-height: 1.4; padding-top: 6px; }

/* ── List ── */
.c-list { display: flex; flex-direction: column; gap: 8px; }
.c-list-item { display: flex; align-items: center; gap: 12px; padding: 12px; background: #f8fafc; border: 2.5px solid #e2e8f0; border-radius: 14px; transition: transform 0.1s; }
.c-list-item:hover { transform: translateY(-2px); border-color: #cbd5e1; box-shadow: 0 3px 0 #cbd5e1; }
.c-list-item__icon {
  width: 36px; height: 36px; border-radius: 10px; border: 2.5px solid #fff; display: flex; align-items: center; justify-content: center;
  font-size: 18px; flex-shrink: 0; background: linear-gradient(135deg, #e0f2fe, #bae6fd); box-shadow: 0 3px 0 #7dd3e8;
}
.c-list-item__body { flex: 1; min-width: 0; }
.c-list-item__title { font-size: 13px; font-weight: 900; color: #0c4a6e; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; margin-bottom: 2px; }
.c-list-item__sub { font-size: 10px; font-weight: 800; color: #64748b; display: flex; align-items: center; gap: 4px; }
.c-list-item__right { text-align: right; }
.c-badge { font-size: 9px; font-weight: 900; padding: 3px 6px; border-radius: 6px; border: 1.5px solid; text-transform: uppercase; display: inline-block; margin-bottom: 4px; }
.badge--brand { background: #e0f2fe; color: #0284c7; border-color: #38bdf8; }

/* Pagination */
.ref-pg { display: flex; align-items: center; justify-content: space-between; margin-top: 16px; padding-top: 16px; border-top: 2.5px dashed #e2e8f0; }
.ref-pg-btn { padding: 8px 14px; background: #f1f5f9; border: 2.5px solid #cbd5e1; border-radius: 10px; font-size: 11px; font-weight: 900; color: #64748b; box-shadow: 0 3px 0 #cbd5e1; cursor: pointer; }
.ref-pg-btn:active { transform: translateY(3px); box-shadow: none; }
.ref-pg-info { font-size: 12px; font-weight: 900; color: #475569; }

/* Empty */
.ref-empty { text-align: center; padding: 24px; border: 3px dashed #cbd5e1; border-radius: 16px; background: #f8fafc; }
.ref-empty-ico { font-size: 40px; margin-bottom: 8px; opacity: 0.5; }
.ref-empty-txt { font-size: 12px; font-weight: 800; color: #94a3b8; }
</style>

<div class="ref-page">
  <!-- HERO -->
  <div class="ref-hero">
    <i class="ph-fill ph-star ref-hero-star"></i>
    <div class="ref-hero__lbl"><i class="ph-bold ph-users-three"></i> Referral</div>
    <div class="ref-hero__val">Ajak Teman, Panen Komisi</div>
  </div>

  <?php if ((int)$user['is_promotor'] === 1): ?>
  <!-- Promotor Banner -->
  <div class="ref-promo">
    <div>
      <div class="ref-promo__text">🚀 Promotor Aktif</div>
      <div class="ref-promo__sub">Pantau traffic & target harianmu.</div>
    </div>
    <a href="/user/promotor.php" class="ref-promo__btn">Dashboard</a>
  </div>
  <?php endif; ?>

  <!-- Stats -->
  <div class="ref-stats">
    <div class="ref-stat">
      <div class="ref-stat__val ref-stat__val--purple"><?= $ref_count ?></div>
      <div class="ref-stat__lbl">Teman</div>
    </div>
    <div class="ref-stat">
      <div class="ref-stat__val"><?= format_rp($ref_earned) ?></div>
      <div class="ref-stat__lbl">Komisi</div>
    </div>
    <div class="ref-stat">
      <div class="ref-stat__val ref-stat__val--orange" style="font-family:monospace;letter-spacing:0px"><?= $user['referral_code'] ?></div>
      <div class="ref-stat__lbl">Kode Unik</div>
    </div>
  </div>

  <!-- Share -->
  <div class="ref-card">
    <div class="ref-card-title"><i class="ph-bold ph-share-network" style="color:#0ea5e9;font-size:18px"></i> Bagikan Link Referral</div>
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

  <!-- How it works -->
  <div class="ref-card">
    <div class="ref-card-title"><i class="ph-bold ph-lightbulb" style="color:#f59e0b;font-size:18px"></i> Cara Kerja</div>
    <div class="ref-steps">
      <div class="ref-step">
        <div class="ref-step__num ref-step__num--1">1</div>
        <div class="ref-step__txt">Bagikan link referral ke teman-temanmu.</div>
      </div>
      <div class="ref-step">
        <div class="ref-step__num ref-step__num--2">2</div>
        <div class="ref-step__txt">Teman mendaftar melalui link tersebut.</div>
      </div>
      <div class="ref-step">
        <div class="ref-step__num ref-step__num--3">3</div>
        <div class="ref-step__txt">Dapatkan komisi dari setiap transaksi mereka!</div>
      </div>
    </div>
  </div>

  <!-- Referred Users -->
  <div class="ref-card">
    <div class="ref-card-title"><i class="ph-bold ph-users" style="color:#10b981;font-size:18px"></i> Teman Bergabung</div>
    <?php if (empty($referreds)): ?>
    <div class="ref-empty">
      <div class="ref-empty-ico">👥</div>
      <div class="ref-empty-txt">Belum ada teman yang bergabung.<br>Ayo bagikan link referral kamu!</div>
    </div>
    <?php else: ?>
    <div class="c-list">
      <?php foreach ($referreds as $idx => $r): ?>
      <div class="c-list-item ref-item-row" data-index="<?= $idx ?>" style="<?= $idx >= 5 ? 'display:none' : '' ?>">
        <div class="c-list-item__icon">👤</div>
        <div class="c-list-item__body">
          <div class="c-list-item__title"><?= htmlspecialchars($r['username']) ?></div>
          <div class="c-list-item__sub"><i class="ph-bold ph-calendar-blank"></i> <?= date('d M y', strtotime($r['created_at'])) ?></div>
        </div>
        <div class="c-list-item__right">
          <div class="c-badge badge--brand"><?= htmlspecialchars($r['membership_name']) ?></div>
          <div style="color:#10b981;font-size:14px;font-weight:900;letter-spacing:-0.5px">+<?= format_rp((float)$r['commission_earned']) ?></div>
          <div style="font-size:10px;color:#94a3b8;font-weight:800;margin-top:2px">Depo: <?= format_rp((float)$r['total_deposit']) ?></div>
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

  <!-- Commission History -->
  <?php if (!empty($history)): ?>
  <div class="ref-card">
    <div class="ref-card-title"><i class="ph-bold ph-coins" style="color:#8b5cf6;font-size:18px"></i> Riwayat Komisi</div>
    <div class="c-list">
      <?php foreach ($history as $h): ?>
      <div class="c-list-item">
        <div class="c-list-item__icon" style="background:linear-gradient(135deg, #fef08a, #fde047);box-shadow:0 3px 0 #f59e0b;color:#d97706;border-color:#fff">🎁</div>
        <div class="c-list-item__body">
          <div class="c-list-item__title">Dari <?= htmlspecialchars($h['username']) ?></div>
          <div class="c-list-item__sub"><i class="ph-bold ph-clock"></i> <?= date('d M y H:i', strtotime($h['created_at'])) ?></div>
        </div>
        <div class="c-list-item__right" style="color:#10b981;font-size:15px;font-weight:900;letter-spacing:-0.5px">
          +<?= format_rp((float)$h['amount']) ?>
        </div>
      </div>
      <?php endforeach; ?>
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
