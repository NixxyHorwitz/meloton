<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/auth/guard.php';

$pageTitle  = 'Panduan — Meloton';
$activePage = 'panduan';

// ── Settings ────────────────────────────────────────────────
$free_limit  = (int)   setting($pdo, 'free_watch_limit', '5');
$min_wd      = (float) setting($pdo, 'min_withdraw', '50000');
$ref_bonus   = (float) setting($pdo, 'referral_bonus', '1000');

$panduan_intro    = setting($pdo, 'panduan_intro',      'Cara kerja platform reward video Meloton');
$panduan_step1    = setting($pdo, 'panduan_step1',      'Buat akun gratis, tidak perlu verifikasi ribet. Langsung bisa mulai tonton.');
$panduan_step2    = setting($pdo, 'panduan_step2',      'Setiap video yang ditonton hingga selesai akan otomatis memberikan reward ke Saldo Penarikan kamu.');
$panduan_step3    = setting($pdo, 'panduan_step3',      'Reward terkumpul di Saldo Penarikan. Cek progresmu di halaman Beranda kapan saja.');
$panduan_step4    = setting($pdo, 'panduan_step4',      'Minimal withdraw ' . format_rp($min_wd) . '. Proses cepat ke rekening/e-wallet pilihanmu.');
$panduan_faq      = setting($pdo, 'panduan_faq_custom', '');
$panduan_cta_text = setting($pdo, 'panduan_cta_text',   'Mulai Tonton Sekarang');
$panduan_cta_url  = setting($pdo, 'panduan_cta_url',    '/videos');


// ── Fetch memberships from DB ───────────────────────────────
try {
    $memberships = $pdo->query(
        "SELECT name, price, watch_limit, duration_days, description
         FROM memberships WHERE is_active=1 ORDER BY sort_order ASC"
    )->fetchAll();
} catch (\Throwable) {
    $memberships = [];
}

// Membership accent colors
$mem_colors = ['#e0f2fe','#d1fae5','#fef08a','#fce7f3','#ede9fe'];
$mem_borders = ['#7dd3e8','#6ee7b7','#facc15','#f9a8d4','#c4b5fd'];
$mem_icons  = ['🆓','🌱','⚡','🗡️','💎'];

require dirname(__DIR__) . '/partials/header.php';
?>

<style>
/* ── Casual Game Panduan Style ──── */
.panduan-page { padding-bottom: 24px; }
.guide-card {
  background: #fff;
  border: 3px solid #7dd3e8;
  border-radius: 20px;
  box-shadow: 0 6px 0 #7dd3e8;
  margin-bottom: 20px;
  overflow: hidden;
}
.guide-card__hd {
  padding: 12px 16px;
  background: linear-gradient(135deg, #0ea5e9, #0284c7);
  color: #fff;
  font-weight: 900;
  font-size: 15px;
  display: flex;
  align-items: center;
  gap: 8px;
  border-bottom: 3px solid #0369a1;
  text-shadow: 0 1px 2px rgba(0,0,0,0.2);
}
.guide-card__bd { padding: 20px 16px; }

/* ── Steps ──── */
.guide-step {
  display: flex;
  gap: 14px;
  align-items: flex-start;
  margin-bottom: 16px;
  position: relative;
}
.guide-step:last-child { margin-bottom: 0; }
.guide-step:not(:last-child)::before {
  content: '';
  position: absolute;
  left: 17px; top: 40px; bottom: -12px;
  width: 3px;
  background: #bae6fd;
}
.guide-step__num {
  width: 38px; height: 38px; flex-shrink: 0;
  border-radius: 12px;
  background: linear-gradient(135deg, #fde047, #f59e0b);
  border: 3px solid #d97706;
  box-shadow: 0 4px 0 #b45309;
  display: flex; align-items: center; justify-content: center;
  font-weight: 900; font-size: 16px; color: #78350f;
  position: relative; z-index: 1;
}
.guide-step__title { font-weight: 900; font-size: 14px; margin-bottom: 4px; color: #0f172a; }
.guide-step__desc { font-size: 12px; color: #475569; line-height: 1.5; font-weight: 700; }

/* ── Tip boxes ──── */
.tip-box {
  border: 2.5px solid;
  border-radius: 16px;
  padding: 14px;
  font-size: 12px;
  margin-bottom: 12px;
  display: flex;
  gap: 12px;
  align-items: flex-start;
  font-weight: 700;
  line-height: 1.5;
  color: #0f172a;
}
.tip-box:last-child { margin-bottom: 0; }
.tip-box__icon {
  font-size: 24px; flex-shrink: 0;
  width: 44px; height: 44px;
  background: rgba(255,255,255,0.6);
  border-radius: 12px;
  display: flex; align-items: center; justify-content: center;
  box-shadow: inset 0 2px 4px rgba(255,255,255,0.8);
}

/* ── Membership cards ──── */
.mem-grid { display: flex; flex-direction: column; gap: 12px; }
.mem-card {
  border: 3px solid;
  border-radius: 16px;
  overflow: hidden;
  box-shadow: 0 6px 0;
}
.mem-card__head {
  display: flex; align-items: center; justify-content: space-between;
  padding: 10px 14px;
  border-bottom: 3px solid;
}
.mem-card__name { font-weight: 900; font-size: 14px; display: flex; align-items: center; gap: 6px; color: #0f172a; }
.mem-card__price {
  font-weight: 900; font-size: 13px;
  background: linear-gradient(135deg, #0ea5e9, #0284c7);
  color: #fff; padding: 4px 10px; border-radius: 10px;
  border: 2px solid #0369a1; box-shadow: 0 2px 0 #0369a1;
}
.mem-card__price--free {
  background: linear-gradient(135deg, #34d399, #10b981);
  border-color: #047857; box-shadow: 0 2px 0 #047857;
}
.mem-card__body { padding: 12px 14px; display: flex; flex-direction: column; gap: 8px; background: rgba(255,255,255,0.4); }
.mem-card__stat { display: flex; gap: 8px; flex-wrap: wrap; }
.mem-badge {
  font-size: 10px; font-weight: 900;
  background: #fff; border: 2px solid; border-radius: 8px;
  padding: 4px 8px; white-space: nowrap; color: #0f172a;
}
.mem-card__desc { font-size: 11.5px; color: #334155; line-height: 1.5; font-weight: 700; }

/* ── FAQ accordion ──── */
.faq-item {
  border: 2px solid #cbd5e1;
  border-radius: 16px;
  margin-bottom: 10px;
  background: #f8fafc;
  overflow: hidden;
  transition: all 0.2s;
}
.faq-item:last-child { margin-bottom: 0; }
.faq-item.open {
  border-color: #0ea5e9;
  background: #f0f9ff;
  box-shadow: 0 4px 0 #bae6fd;
}
.faq-q {
  font-weight: 800; font-size: 13px;
  padding: 14px 16px;
  cursor: pointer; display: flex; justify-content: space-between; align-items: center; gap: 8px;
  color: #0f172a;
}
.faq-q::after {
  content: '＋'; font-size: 16px; font-weight: 900; flex-shrink: 0; transition: transform .2s; color: #0284c7;
}
.faq-item.open .faq-q::after { transform: rotate(45deg); color: #0ea5e9; }
.faq-a {
  font-size: 12px; color: #475569; font-weight: 700; line-height: 1.5;
  padding: 0 16px 16px; display: none;
}
.faq-item.open .faq-a { display: block; }

/* ── Page header ──── */
.panduan-header {
  background: linear-gradient(135deg, #a855f7, #7e22ce);
  border: 3px solid #fff;
  border-radius: 20px;
  box-shadow: 0 8px 0 #6b21a8;
  padding: 20px;
  margin-bottom: 24px;
  display: flex;
  align-items: center;
  gap: 16px;
  color: #fff;
  position: relative;
  overflow: hidden;
}
.panduan-header::after {
  content: '📖';
  position: absolute; right: -10px; bottom: -20px;
  font-size: 100px; opacity: 0.15; transform: rotate(-15deg);
}
.panduan-header__icon {
  width: 54px; height: 54px;
  background: linear-gradient(135deg, #fde047, #f59e0b); border: 3px solid #d97706; box-shadow: 0 4px 0 #b45309;
  border-radius: 16px; display: flex; align-items: center; justify-content: center;
  font-size: 28px; flex-shrink: 0; position: relative; z-index: 1;
}
.panduan-header__title { font-size: 20px; font-weight: 900; line-height: 1.1; margin-bottom: 4px; text-shadow: 0 2px 4px rgba(0,0,0,0.2); position:relative; z-index:1; }
.panduan-header__sub { font-size: 12px; font-weight: 700; color: #f3e8ff; line-height: 1.4; position:relative; z-index:1; }
</style>

<div class="panduan-page">

  <!-- Header -->
  <div class="panduan-header">
    <div class="panduan-header__icon"><i class="ph-fill ph-book-open"></i></div>
    <div>
      <div class="panduan-header__title">Panduan Meloton</div>
      <div class="panduan-header__sub"><?= htmlspecialchars($panduan_intro) ?></div>
    </div>
  </div>

  <!-- Cara Kerja -->
  <div class="guide-card">
    <div class="guide-card__hd"><i class="ph-fill ph-target"></i> Cara Kerja</div>
    <div class="guide-card__bd">
      <div class="guide-step">
        <div class="guide-step__num">1</div>
        <div><div class="guide-step__title">Daftar &amp; Login</div><div class="guide-step__desc"><?= nl2br(htmlspecialchars($panduan_step1)) ?></div></div>
      </div>
      <div class="guide-step">
        <div class="guide-step__num">2</div>
        <div><div class="guide-step__title">Tonton Video</div><div class="guide-step__desc"><?= nl2br(htmlspecialchars($panduan_step2)) ?></div></div>
      </div>
      <div class="guide-step">
        <div class="guide-step__num">3</div>
        <div><div class="guide-step__title">Kumpulkan Reward</div><div class="guide-step__desc"><?= nl2br(htmlspecialchars($panduan_step3)) ?></div></div>
      </div>
      <div class="guide-step">
        <div class="guide-step__num">4</div>
        <div><div class="guide-step__title">Tarik Saldo</div><div class="guide-step__desc"><?= nl2br(htmlspecialchars($panduan_step4)) ?></div></div>
      </div>
    </div>
  </div>

  <!-- Jenis Saldo -->
  <div class="guide-card" style="border-color: #6ee7b7; box-shadow: 0 6px 0 #6ee7b7;">
    <div class="guide-card__hd" style="background: linear-gradient(135deg, #10b981, #059669); border-bottom-color: #047857;"><i class="ph-fill ph-wallet"></i> Jenis Saldo</div>
    <div class="guide-card__bd">
      <div class="tip-box" style="background: #d1fae5; border-color: #6ee7b7;">
        <div class="tip-box__icon">💸</div>
        <div><strong>Saldo Penarikan (WD)</strong> — Didapat dari reward tonton video, bonus referral, check-in harian, dan klaim misi. Bisa ditarik ke rekening/e-wallet.</div>
      </div>
      <div class="tip-box" style="background: #dbeafe; border-color: #93c5fd;">
        <div class="tip-box__icon">💳</div>
        <div><strong>Saldo Beli</strong> — Diisi via deposit (transfer/QRIS). Digunakan untuk upgrade paket membership agar bisa tonton lebih banyak video &amp; dapat reward lebih besar.</div>
      </div>
    </div>
  </div>

  <!-- Paket Membership -->
  <div class="guide-card" style="border-color: #fca5a5; box-shadow: 0 6px 0 #fca5a5;">
    <div class="guide-card__hd" style="background: linear-gradient(135deg, #ef4444, #b91c1c); border-bottom-color: #991b1b;"><i class="ph-fill ph-crown"></i> Paket Membership</div>
    <div class="guide-card__bd">
      <div style="font-size:12px; font-weight:700; color:#475569; margin-bottom:14px">Upgrade paket untuk meningkatkan limit tonton harian dan akses reward yang lebih besar.</div>
      <?php if (!empty($memberships)): ?>
      <div class="mem-grid">
        <?php foreach ($memberships as $i => $mem):
          $color = $mem_colors[$i % count($mem_colors)];
          $border = $mem_borders[$i % count($mem_borders)];
          $icon  = $mem_icons[$i % count($mem_icons)];
          $isFree = (float)$mem['price'] === 0.0;
          $descLines = array_filter(array_map('trim', preg_split('/\r?\n/', $mem['description'] ?? '')));
        ?>
        <div class="mem-card" style="background: <?= $color ?>; border-color: <?= $border ?>; color: <?= $border ?>;">
          <div class="mem-card__head" style="border-bottom-color: <?= $border ?>;">
            <div class="mem-card__name"><?= $icon ?> <?= htmlspecialchars($mem['name']) ?></div>
            <div class="mem-card__price <?= $isFree ? 'mem-card__price--free' : '' ?>">
              <?= $isFree ? 'GRATIS' : format_rp((float)$mem['price']) ?>
            </div>
          </div>
          <div class="mem-card__body">
            <div class="mem-card__stat">
              <span class="mem-badge" style="border-color: <?= $border ?>;">📺 <?= $mem['watch_limit'] ?>× video/hari</span>
              <?php if (!$isFree): ?>
              <span class="mem-badge" style="border-color: <?= $border ?>;">📅 <?= $mem['duration_days'] ?> hari</span>
              <?php endif; ?>
            </div>
            <?php if (!empty($descLines)): ?>
            <div class="mem-card__desc"><?php foreach ($descLines as $line): ?><div style="display:flex;gap:6px;align-items:flex-start"><span style="flex-shrink:0;color:<?= $border ?>;">•</span><span><?= htmlspecialchars($line) ?></span></div><?php endforeach; ?></div>
            <?php endif; ?>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
      <div style="font-size:12px; color:#64748b; font-weight:700; margin-top:16px; text-align:center;">Lihat harga & detail lengkap di halaman <a href="/upgrade" style="font-weight:900; color:#0ea5e9; text-decoration:none;">Upgrade <i class="ph-bold ph-arrow-right"></i></a></div>
    </div>
  </div>

  <!-- Program Referral -->
  <div class="guide-card" style="border-color: #c4b5fd; box-shadow: 0 6px 0 #c4b5fd;">
    <div class="guide-card__hd" style="background: linear-gradient(135deg, #a855f7, #7e22ce); border-bottom-color: #6b21a8;"><i class="ph-fill ph-users-three"></i> Program Referral</div>
    <div class="guide-card__bd">
      <div class="tip-box" style="background:#ede9fe; border-color:#c4b5fd;">
        <div class="tip-box__icon">🎁</div>
        <div>Ajak temanmu daftar pakai <strong>kode referralmu</strong>. Kamu dapat bonus <strong><?= format_rp($ref_bonus) ?></strong> ke Saldo Penarikan untuk setiap teman yang berhasil bergabung. Komisi berlapis hingga 3 level!</div>
      </div>
      <div style="font-size:12px; color:#64748b; font-weight:700; margin-top:12px; text-align:center;">Kode referralmu ada di halaman <a href="/referral" style="font-weight:900; color:#8b5cf6; text-decoration:none;">Teman <i class="ph-bold ph-arrow-right"></i></a></div>
    </div>
  </div>

  <!-- Check-in & Misi -->
  <div class="guide-card" style="border-color: #fde047; box-shadow: 0 6px 0 #fde047;">
    <div class="guide-card__hd" style="background: linear-gradient(135deg, #eab308, #ca8a04); border-bottom-color: #a16207;"><i class="ph-fill ph-calendar-check"></i> Check-in &amp; Misi</div>
    <div class="guide-card__bd">
      <div class="tip-box" style="background:#fef9c3; border-color:#fde047;">
        <div class="tip-box__icon">📅</div>
        <div><strong>Check-in Harian</strong> — Login setiap hari dan klik Check-in untuk mendapatkan bonus reward harian. Makin lama streak-mu, makin besar bonusnya!</div>
      </div>
      <div class="tip-box" style="background:#cffafe; border-color:#67e8f9;">
        <div class="tip-box__icon">🎯</div>
        <div><strong>Misi</strong> — Selesaikan misi harian, mingguan, &amp; pencapaian untuk klaim reward tambahan ke Saldo Tarik. Ada misi tonton video, referral, dan banyak lagi!</div>
      </div>
    </div>
  </div>

  <!-- FAQ -->
  <div class="guide-card" style="border-color: #cbd5e1; box-shadow: 0 6px 0 #cbd5e1;">
    <div class="guide-card__hd" style="background: linear-gradient(135deg, #64748b, #475569); border-bottom-color: #334155;"><i class="ph-fill ph-question"></i> FAQ</div>
    <div class="guide-card__bd" id="faq-wrap">
      <div class="faq-item">
        <div class="faq-q">Kapan reward masuk ke saldo?</div>
        <div class="faq-a">Reward otomatis masuk setelah video selesai ditonton sesuai durasi minimum yang ditentukan.</div>
      </div>
      <div class="faq-item">
        <div class="faq-q">Apakah bisa skip video?</div>
        <div class="faq-a">Tidak bisa. Reward hanya diberikan jika kamu menonton sampai waktu minimum tercapai.</div>
      </div>
      <div class="faq-item">
        <div class="faq-q">Apakah daftar gratis?</div>
        <div class="faq-a">Ya! Daftar dan tonton video sepenuhnya gratis. Deposit hanya diperlukan jika ingin upgrade ke paket berbayar.</div>
      </div>
      <div class="faq-item">
        <div class="faq-q">Withdraw ke mana saja?</div>
        <div class="faq-a">Semua bank dan e-wallet Indonesia: BCA, BNI, BRI, Mandiri, GoPay, OVO, Dana, dll. Minimal penarikan <?= format_rp($min_wd) ?>.</div>
      </div>
      <div class="faq-item">
        <div class="faq-q">Apa bedanya Saldo Tarik dan Beli?</div>
        <div class="faq-a">Saldo Tarik (WD) berasal dari reward menonton &amp; misi — bisa dicairkan ke rekening. Saldo Beli diisi via deposit dan hanya digunakan untuk upgrade membership.</div>
      </div>
      <div class="faq-item">
        <div class="faq-q">Berapa komisi referral?</div>
        <div class="faq-a">Kamu mendapat komisi 5% dari setiap deposit level 1, 3% dari level 2, dan 1% dari level 3 — berlapis hingga 3 generasi di bawahmu!</div>
      </div>
      <?php if ($panduan_faq): ?>
      <div class="faq-item">
        <div class="faq-q">Informasi tambahan</div>
        <div class="faq-a"><?= nl2br(htmlspecialchars($panduan_faq)) ?></div>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- CTA -->
  <div style="text-align:center; padding: 0 16px;">
    <a href="<?= htmlspecialchars($panduan_cta_url) ?>" class="btn btn--primary btn--full" style="font-size:16px; font-weight:900; padding:16px; border-radius:20px; background:linear-gradient(135deg, #10b981, #059669); border:3px solid #fff; box-shadow:0 8px 0 #047857; color:#fff; display:flex; align-items:center; justify-content:center; gap:8px;">
      <i class="ph-bold ph-play-circle" style="font-size:24px;"></i> <?= htmlspecialchars($panduan_cta_text) ?>
    </a>
  </div>

</div>

<script>
document.querySelectorAll('.faq-q').forEach(q => {
  q.addEventListener('click', () => {
    const item = q.closest('.faq-item');
    const wasOpen = item.classList.contains('open');
    document.querySelectorAll('.faq-item').forEach(i => i.classList.remove('open'));
    if (!wasOpen) item.classList.add('open');
  });
});
</script>

<?php require dirname(__DIR__) . '/partials/footer.php'; ?>
