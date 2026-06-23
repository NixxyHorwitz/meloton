<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
http_response_code(404);
$user = auth_user($pdo);
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>404 — Halaman Tidak Ditemukan</title>
<link rel="stylesheet" href="/assets/css/app.css?v=<?= @filemtime($_SERVER['DOCUMENT_ROOT'].'/assets/css/app.css') ?: time() ?>">
<style>
  .not-found-page {
    min-height: 100vh;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 32px 20px;
    background: var(--bg);
    text-align: center;
    gap: 0;
  }

  .nf-card {
    width: 100%;
    max-width: 420px;
    background: var(--white);
    border: var(--border);
    border-radius: 24px;
    box-shadow: var(--shadow-lg);
    padding: 36px 28px 32px;
    position: relative;
    overflow: hidden;
  }

  /* Big decorative "404" background text */
  .nf-bg-text {
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -52%);
    font-size: 180px;
    font-weight: 900;
    color: rgba(0,0,0,.04);
    line-height: 1;
    pointer-events: none;
    user-select: none;
    white-space: nowrap;
    z-index: 0;
  }

  .nf-inner { position: relative; z-index: 1; }

  .nf-emoji-box {
    width: 80px;
    height: 80px;
    background: var(--yellow);
    border: var(--border);
    box-shadow: var(--shadow);
    border-radius: 22px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 38px;
    margin: 0 auto 20px;
    animation: nfWiggle 3s ease-in-out infinite;
  }
  @keyframes nfWiggle {
    0%,100% { transform: rotate(-4deg); }
    50%      { transform: rotate(4deg); }
  }

  .nf-code {
    font-size: 72px;
    font-weight: 900;
    line-height: 1;
    letter-spacing: -4px;
    color: var(--ink);
    margin-bottom: 6px;
  }
  .nf-code span { color: var(--brand); }

  .nf-title {
    font-size: 20px;
    font-weight: 900;
    color: var(--ink);
    margin-bottom: 8px;
  }

  .nf-desc {
    font-size: 13.5px;
    color: #777;
    font-weight: 600;
    line-height: 1.6;
    margin-bottom: 28px;
  }

  .nf-actions {
    display: flex;
    flex-direction: column;
    gap: 10px;
  }

  .nf-sticker {
    display: inline-block;
    background: var(--salmon);
    border: var(--border);
    box-shadow: 3px 3px 0 var(--ink);
    border-radius: 10px;
    font-size: 11px;
    font-weight: 900;
    padding: 5px 12px;
    transform: rotate(-2deg);
    position: absolute;
    top: -12px;
    right: 20px;
    color: var(--ink);
    white-space: nowrap;
  }

  /* Decorative corner shapes */
  .nf-corner {
    position: absolute;
    width: 40px;
    height: 40px;
    border: var(--border);
    border-radius: 8px;
  }
  .nf-corner--tl { top: -8px; left: -8px; background: var(--mint); transform: rotate(12deg); }
  .nf-corner--br { bottom: -8px; right: -8px; background: var(--lavender); transform: rotate(-8deg); }

  @media (min-width: 520px) {
    body { background: #E8E4DA; }
  }
</style>
</head>
<body>
<div class="not-found-page">

  <div class="nf-card">
    <div class="nf-bg-text">404</div>
    <div class="nf-corner nf-corner--tl"></div>
    <div class="nf-corner nf-corner--br"></div>
    <span class="nf-sticker">⚠️ Error!</span>

    <div class="nf-inner">
      <div class="nf-emoji-box">🔍</div>
      <div class="nf-code">4<span>0</span>4</div>
      <div class="nf-title">Halaman Tidak Ditemukan</div>
      <div class="nf-desc">
        Halaman yang kamu cari udah pindah,<br>
        dihapus, atau memang belum pernah ada. 🤷
      </div>

      <div class="nf-actions">
        <?php if ($user): ?>
          <a href="/home" class="btn btn--primary btn--full">
            <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
            Kembali ke Beranda
          </a>
          <a href="javascript:history.back()" class="btn btn--ghost btn--full">
            <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><polyline points="15 18 9 12 15 6"/></svg>
            Kembali ke Halaman Sebelumnya
          </a>
        <?php else: ?>
          <a href="/login" class="btn btn--primary btn--full">
            <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path d="M15 3h4a2 2 0 012 2v14a2 2 0 01-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/></svg>
            Masuk ke Akun
          </a>
          <a href="javascript:history.back()" class="btn btn--ghost btn--full">
            <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><polyline points="15 18 9 12 15 6"/></svg>
            Kembali
          </a>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Fun decorative tags below card -->
  <div style="display:flex;gap:8px;margin-top:20px;flex-wrap:wrap;justify-content:center;">
    <?php
    $tags = ['😅 Lost?', '🗺️ No Map', '🚧 Dead End', '👻 Boo!'];
    $colors = ['var(--yellow)','var(--mint)','var(--lavender)','var(--peach)'];
    foreach ($tags as $i => $tag):
    ?>
    <span style="
      background:<?= $colors[$i] ?>;
      border:2px solid var(--ink);
      box-shadow:2px 2px 0 var(--ink);
      border-radius:8px;
      font-size:11px;font-weight:800;
      padding:4px 12px;
      transform:rotate(<?= ($i%2===0?'-':''). (1+$i) ?>deg);
      display:inline-block;
    "><?= $tag ?></span>
    <?php endforeach; ?>
  </div>

</div>
</body>
</html>
