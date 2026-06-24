<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/auth/guard.php';



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
  <h1 style="font-weight:900; font-size:22px; display:flex; align-items:center; gap:6px; color: var(--ink);">🎉 Event Khusus</h1>
  <p style="color:#444; font-weight:700; margin-top:2px; font-size:12px;">Selamat datang di arena event! Pilih arena bermain atau kunjungi toko koin untuk bertransaksi.</p>
</div>

<!-- Event Options Panel Grid -->
<div style="display: flex; flex-direction: column; gap: 16px; margin-bottom: 16px;">
  <div class="card" style="padding: 32px 16px; text-align: center;">
    <div style="font-size: 48px; margin-bottom: 16px;">🎉</div>
    <div style="font-weight: 800; font-size: 16px; margin-bottom: 8px;">Belum Ada Event</div>
    <div style="font-size: 13px; color: var(--text-muted); font-weight: 700;">Nantikan event menarik selanjutnya!</div>
  </div>
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
