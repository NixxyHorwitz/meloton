<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/auth/guard.php';

// Guard: Check if Plinko feature is enabled globally
$plinko_enabled = setting($pdo, 'plinko_enabled', '1') === '1';
if (!$plinko_enabled) {
    $_SESSION['flash_home_err'] = '⚠️ Event Mini Game Plinko sedang dinonaktifkan oleh Administrator.';
    redirect('/home');
}

$pageTitle  = 'Event Khusus — Meloton';
$activePage = 'events';
require dirname(__DIR__) . '/partials/header.php';
?>

<!-- Premium Header (Neo-Brutalist Arcade Machine Style) -->
<div class="page-title-bar" style="
  background: var(--yellow);
  border: 3.5px solid var(--ink);
  border-radius: 14px;
  box-shadow: 5px 5px 0 var(--ink);
  padding: 16px 14px;
  margin-bottom: 20px;
  position: relative;
  overflow: hidden;
">
  <div style="font-size: 26px; position: absolute; right: 10px; top: 10px; opacity: 0.15;">🎉</div>
  <h1 style="font-weight:900; font-size:22px; display:flex; align-items:center; gap:6px; color: var(--ink);">🎉 Event Plinko & Lapak</h1>
  <p style="color:#444; font-weight:700; margin-top:2px; font-size:12px;">Selamat datang di arena event! Pilih arena bermain atau kunjungi toko koin untuk bertransaksi.</p>
</div>

<!-- Event Options Panel Grid -->
<div style="display: flex; flex-direction: column; gap: 16px; margin-bottom: 16px;">

  <!-- Plinko Game Card -->
  <a href="/plinko" class="card card--sky" style="
    display: block; 
    text-decoration: none; 
    color: var(--ink); 
    box-shadow: 5px 5px 0 var(--ink); 
    border: 3.5px solid var(--ink);
    transition: transform 0.15s, box-shadow 0.15s;
  " onmouseover="this.style.transform='translate(-2px, -2px)'; this.style.boxShadow='6px 6px 0 var(--ink)';" onmouseout="this.style.transform='none'; this.style.boxShadow='5px 5px 0 var(--ink)';">
    <div class="card__body" style="display: flex; align-items: center; justify-content: space-between; padding: 18px 16px;">
      <div style="flex: 1; padding-right: 12px;">
        <div style="font-weight: 900; font-size: 16px; display: flex; align-items: center; gap: 6px;">🎮 Main Plinko Neon Arcade</div>
        <div style="font-size: 11px; font-weight: 700; color: #333; margin-top: 5px; line-height: 1.4;">
          Jatuhkan bola neon di papan pin besi Galton Board untuk melipatgandakan koin Anda hingga 10x lipat!
        </div>
      </div>
      <div style="font-size: 26px; background: #fff; border: 2.5px solid var(--ink); box-shadow: 2px 2px 0 var(--ink); border-radius: 12px; width: 48px; height: 48px; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">🎮</div>
    </div>
  </a>

  <!-- Plinko Shop Card -->
  <a href="/plinko-shop" class="card card--mint" style="
    display: block; 
    text-decoration: none; 
    color: var(--ink); 
    box-shadow: 5px 5px 0 var(--ink); 
    border: 3.5px solid var(--ink);
    transition: transform 0.15s, box-shadow 0.15s;
  " onmouseover="this.style.transform='translate(-2px, -2px)'; this.style.boxShadow='6px 6px 0 var(--ink)';" onmouseout="this.style.transform='none'; this.style.boxShadow='5px 5px 0 var(--ink)';">
    <div class="card__body" style="display: flex; align-items: center; justify-content: space-between; padding: 18px 16px;">
      <div style="flex: 1; padding-right: 12px;">
        <div style="font-weight: 900; font-size: 16px; display: flex; align-items: center; gap: 6px;">🛒 Lapak Jual-Beli Koin</div>
        <div style="font-size: 11px; font-weight: 700; color: #333; margin-top: 5px; line-height: 1.4;">
          Klaim koin gratis harian, beli koin dengan Saldo Beli, atau jual koin kemenangan Anda langsung menjadi Saldo Penarikan!
        </div>
      </div>
      <div style="font-size: 26px; background: #fff; border: 2.5px solid var(--ink); box-shadow: 2px 2px 0 var(--ink); border-radius: 12px; width: 48px; height: 48px; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">🪙</div>
    </div>
  </a>

</div>

<!-- Return Button to Home -->
<div style="margin-top: 10px; margin-bottom: 20px;">
  <a href="/home" class="btn btn--secondary btn--full" style="
    font-weight: 900;
    font-size: 12px;
    border: 2.5px solid var(--ink);
    box-shadow: 3px 3px 0 var(--ink);
    background: #fff;
    color: var(--ink);
    height: 42px;
    display: flex;
    align-items: center;
    justify-content: center;
    text-decoration: none;
    transition: transform 0.1s, box-shadow 0.1s;
  " onmouseover="this.style.transform='translate(-1.5px, -1.5px)'; this.style.boxShadow='4px 4px 0 var(--ink)';" onmouseout="this.style.transform='none'; this.style.boxShadow='3px 3px 0 var(--ink)';">
    🏠 Kembali ke Beranda
  </a>
</div>

<?php require dirname(__DIR__) . '/partials/footer.php'; ?>
