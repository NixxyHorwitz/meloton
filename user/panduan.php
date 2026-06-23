<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/auth/guard.php';

$pageTitle  = 'Panduan — NontonKuy';
$activePage = 'panduan';

// ── Settings ────────────────────────────────────────────────
$free_limit  = (int)   setting($pdo, 'free_watch_limit', '5');
$min_wd      = (float) setting($pdo, 'min_withdraw', '50000');
$ref_bonus   = (float) setting($pdo, 'referral_bonus', '1000');

$panduan_intro    = setting($pdo, 'panduan_intro',      'Cara kerja platform reward video NontonKuy');
$panduan_step1    = setting($pdo, 'panduan_step1',      'Buat akun gratis, tidak perlu verifikasi ribet. Langsung bisa mulai tonton.');
$panduan_step2    = setting($pdo, 'panduan_step2',      'Setiap video yang ditonton hingga selesai akan otomatis memberikan reward ke Saldo Penarikan kamu.');
$panduan_step3    = setting($pdo, 'panduan_step3',      'Reward terkumpul di Saldo Penarikan. Cek progresmu di halaman Beranda kapan saja.');
$panduan_step4    = setting($pdo, 'panduan_step4',      'Minimal withdraw ' . format_rp($min_wd) . '. Proses cepat ke rekening/e-wallet pilihanmu.');
$panduan_faq      = setting($pdo, 'panduan_faq_custom', '');
$panduan_cta_text = setting($pdo, 'panduan_cta_text',   '🎬 Mulai Tonton Sekarang →');
$panduan_cta_url  = setting($pdo, 'panduan_cta_url',    '/videos');
$plinko_on        = setting($pdo, 'plinko_enabled',     '1') === '1';

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
$mem_colors = ['#e0f2fe','#d1fae5','#fef9c3','#fce7f3','#ede9fe'];
$mem_icons  = ['🆓','🌱','⚡','🗡️','💎'];

require dirname(__DIR__) . '/partials/header.php';
?>

<style>
/* ── Guide Cards ──── */
.guide-card{border:2.5px solid var(--ink);border-radius:12px;box-shadow:3px 3px 0 var(--ink);background:var(--white);margin-bottom:12px;overflow:hidden}
.guide-card__hd{padding:10px 14px;border-bottom:2px solid var(--ink);font-weight:900;font-size:13px;background:var(--yellow);display:flex;align-items:center;gap:6px}
.guide-card__bd{padding:12px 14px}

/* ── Steps ──── */
.guide-step{display:flex;gap:10px;align-items:flex-start;margin-bottom:12px}
.guide-step:last-child{margin-bottom:0}
.guide-step__num{width:30px;height:30px;flex-shrink:0;border-radius:50%;background:var(--yellow);border:2px solid var(--ink);box-shadow:2px 2px 0 var(--ink);display:flex;align-items:center;justify-content:center;font-weight:900;font-size:13px}
.guide-step__title{font-weight:900;font-size:13px;margin-bottom:2px}
.guide-step__desc{font-size:11px;color:#555;line-height:1.5}

/* ── Tip boxes ──── */
.tip-box{border:2px solid var(--ink);border-radius:8px;padding:9px 10px;font-size:11px;margin-bottom:8px;display:flex;gap:8px;align-items:flex-start;background:var(--lime)}
.tip-box:last-child{margin-bottom:0}
.tip-box__icon{font-size:15px;flex-shrink:0;margin-top:1px}

/* ── Membership cards ──── */
.mem-grid{display:flex;flex-direction:column;gap:10px}
.mem-card{border:2.5px solid var(--ink);border-radius:10px;box-shadow:3px 3px 0 var(--ink);overflow:hidden}
.mem-card__head{display:flex;align-items:center;justify-content:space-between;padding:8px 12px;border-bottom:2px solid var(--ink)}
.mem-card__name{font-weight:900;font-size:14px;display:flex;align-items:center;gap:6px}
.mem-card__price{font-weight:900;font-size:13px;background:var(--ink);color:var(--yellow);padding:3px 9px;border-radius:6px}
.mem-card__price--free{background:var(--mint);color:var(--ink);border:1.5px solid var(--ink)}
.mem-card__body{padding:9px 12px;display:flex;flex-direction:column;gap:6px}
.mem-card__stat{display:flex;gap:8px;flex-wrap:wrap}
.mem-badge{font-size:10px;font-weight:800;background:#fff;border:1.5px solid var(--ink);border-radius:5px;padding:2px 7px;white-space:nowrap}
.mem-card__desc{font-size:11px;color:#555;line-height:1.5}

/* ── FAQ accordion ──── */
.faq-item{border-bottom:1.5px solid #eee;padding:8px 0}
.faq-item:last-child{border-bottom:none;padding-bottom:0}
.faq-q{font-weight:800;font-size:12px;cursor:pointer;display:flex;justify-content:space-between;align-items:center;gap:8px}
.faq-q::after{content:'＋';font-size:14px;flex-shrink:0;transition:transform .2s}
.faq-item.open .faq-q::after{transform:rotate(45deg)}
.faq-a{font-size:11px;color:#555;line-height:1.5;margin-top:5px;display:none}
.faq-item.open .faq-a{display:block}

/* ── Page header ──── */
.panduan-header{background:linear-gradient(135deg,var(--yellow),#fb923c);border:2.5px solid var(--ink);border-radius:12px;box-shadow:3px 3px 0 var(--ink);padding:14px;margin-bottom:14px;display:flex;align-items:center;gap:12px}
.panduan-header__icon{font-size:36px;flex-shrink:0}
.panduan-header__title{font-size:18px;font-weight:900;line-height:1.2}
.panduan-header__sub{font-size:11px;font-weight:700;opacity:.75;margin-top:2px}
</style>

<!-- Header -->
<div class="panduan-header">
  <div class="panduan-header__icon">📖</div>
  <div>
    <div class="panduan-header__title">Panduan NontonKuy</div>
    <div class="panduan-header__sub"><?= htmlspecialchars($panduan_intro) ?></div>
  </div>
</div>

<!-- Cara Kerja -->
<div class="guide-card">
  <div class="guide-card__hd">🎯 Cara Kerja</div>
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
<div class="guide-card">
  <div class="guide-card__hd">💰 Jenis Saldo</div>
  <div class="guide-card__bd">
    <div class="tip-box" style="background:#d1fae5">
      <div class="tip-box__icon">💸</div>
      <div><strong>Saldo Penarikan (WD)</strong> — Didapat dari reward tonton video, bonus referral, check-in harian, dan klaim misi. Bisa ditarik ke rekening/e-wallet.</div>
    </div>
    <div class="tip-box" style="background:#dbeafe">
      <div class="tip-box__icon">💳</div>
      <div><strong>Saldo Beli</strong> — Diisi via deposit (transfer/QRIS). Digunakan untuk upgrade paket membership agar bisa tonton lebih banyak video &amp; dapat reward lebih besar.</div>
    </div>
  </div>
</div>

<!-- Paket Membership -->
<div class="guide-card">
  <div class="guide-card__hd">👑 Paket Membership</div>
  <div class="guide-card__bd">
    <div style="font-size:11px;color:#555;margin-bottom:10px">Upgrade paket untuk meningkatkan limit tonton harian dan akses reward yang lebih besar.</div>
    <?php if (!empty($memberships)): ?>
    <div class="mem-grid">
      <?php foreach ($memberships as $i => $mem):
        $color = $mem_colors[$i % count($mem_colors)];
        $icon  = $mem_icons[$i % count($mem_icons)];
        $isFree = (float)$mem['price'] === 0.0;
        $descLines = array_filter(array_map('trim', preg_split('/\r?\n/', $mem['description'] ?? '')));
      ?>
      <div class="mem-card" style="background:<?= $color ?>">
        <div class="mem-card__head" style="background:<?= $color ?>">
          <div class="mem-card__name"><?= $icon ?> <?= htmlspecialchars($mem['name']) ?></div>
          <div class="mem-card__price <?= $isFree ? 'mem-card__price--free' : '' ?>">
            <?= $isFree ? 'GRATIS' : format_rp((float)$mem['price']) ?>
          </div>
        </div>
        <div class="mem-card__body">
          <div class="mem-card__stat">
            <span class="mem-badge">📺 <?= $mem['watch_limit'] ?>× video/hari</span>
            <?php if (!$isFree): ?>
            <span class="mem-badge">📅 <?= $mem['duration_days'] ?> hari</span>
            <?php endif; ?>
          </div>
          <?php if (!empty($descLines)): ?>
          <div class="mem-card__desc"><?php foreach ($descLines as $line): ?><div style="display:flex;gap:5px;align-items:flex-start"><span style="flex-shrink:0">•</span><span><?= htmlspecialchars($line) ?></span></div><?php endforeach; ?></div>
          <?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <div style="font-size:11px;color:#888;margin-top:10px">Lihat harga & detail lengkap di halaman <a href="/upgrade" style="font-weight:800">Upgrade →</a></div>
  </div>
</div>

<!-- Program Referral -->
<div class="guide-card">
  <div class="guide-card__hd">🤝 Program Referral</div>
  <div class="guide-card__bd">
    <div class="tip-box" style="background:#ede9fe">
      <div class="tip-box__icon">🎁</div>
      <div>Ajak temanmu daftar pakai <strong>kode referralmu</strong>. Kamu dapat bonus <strong><?= format_rp($ref_bonus) ?></strong> ke Saldo Penarikan untuk setiap teman yang berhasil bergabung. Komisi berlapis hingga 3 level!</div>
    </div>
    <div style="font-size:11px;color:#555;margin-top:6px">Kode referralmu ada di halaman <a href="/referral" style="font-weight:800">Referral →</a></div>
  </div>
</div>

<!-- Check-in & Misi -->
<div class="guide-card">
  <div class="guide-card__hd">🎯 Check-in &amp; Misi Harian</div>
  <div class="guide-card__bd">
    <div class="tip-box" style="background:#fef9c3">
      <div class="tip-box__icon">📅</div>
      <div><strong>Check-in Harian</strong> — Login setiap hari dan klik Check-in untuk mendapatkan bonus reward harian. Makin lama streak-mu, makin besar bonusnya!</div>
    </div>
    <div class="tip-box" style="background:#cffafe">
      <div class="tip-box__icon">🎯</div>
      <div><strong>Misi</strong> — Selesaikan misi harian, mingguan, &amp; pencapaian untuk klaim reward tambahan ke Saldo Tarik. Ada misi tonton video, main plinko<?= $plinko_on ? '' : ' (jika aktif)' ?>, referral, dan banyak lagi!</div>
    </div>
    <div style="font-size:11px;color:#555;margin-top:6px;display:flex;gap:10px;flex-wrap:wrap">
      <a href="/checkin" style="font-weight:800">Check-in →</a>
      <a href="/missions" style="font-weight:800">Lihat Misi →</a>
    </div>
  </div>
</div>

<?php if ($plinko_on): ?>
<!-- Plinko -->
<div class="guide-card">
  <div class="guide-card__hd">🎰 Plinko Game</div>
  <div class="guide-card__bd">
    <div class="tip-box" style="background:#fce7f3">
      <div class="tip-box__icon">🪙</div>
      <div><strong>Plinko</strong> — Gunakan Koin Plinko untuk bermain dan menangkan reward saldo tarik! Koin Plinko bisa dibeli di Lapak Koin atau didapat dari misi.</div>
    </div>
    <div style="font-size:11px;color:#555;margin-top:6px">
      <a href="/plinko" style="font-weight:800">Main Plinko →</a>
      &nbsp;·&nbsp;
      <a href="/plinko-shop" style="font-weight:800">Lapak Koin →</a>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- FAQ -->
<div class="guide-card">
  <div class="guide-card__hd">❓ FAQ</div>
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
      <div class="faq-q">Apa bedanya Saldo Tarik dan Saldo Beli?</div>
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
<div style="text-align:center;margin-top:4px;margin-bottom:8px">
  <a href="<?= htmlspecialchars($panduan_cta_url) ?>" class="btn btn--primary btn--full" style="font-size:14px;font-weight:900"><?= htmlspecialchars($panduan_cta_text) ?></a>
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
