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
<title>404 — Nyasar Bosku!</title>
<link rel="stylesheet" href="/assets/css/app.css?v=<?= @filemtime($_SERVER['DOCUMENT_ROOT'].'/assets/css/app.css') ?: time() ?>">
<style>
  body, html { height: 100%; margin: 0; }
  .not-found-page {
    min-height: 100vh;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 20px;
    background: radial-gradient(circle at top, #e0f9ff 0%, #bae6fd 100%);
    text-align: center;
  }

  .nf-card {
    width: 100%;
    max-width: 360px;
    background: #fff;
    border: 4px solid #0ea5e9;
    border-radius: 32px;
    box-shadow: 0 10px 0 #0284c7;
    padding: 50px 24px 32px;
    position: relative;
    margin-top: 50px;
    animation: bounceIn 0.6s cubic-bezier(0.34, 1.56, 0.64, 1);
  }

  .nf-badge {
    position: absolute;
    top: -50px;
    left: 50%;
    transform: translateX(-50%);
    background: linear-gradient(135deg, #fde047, #f59e0b);
    border: 4px solid #fff;
    border-radius: 50%;
    width: 100px;
    height: 100px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 56px;
    box-shadow: 0 8px 0 rgba(217, 119, 6, 0.8), 0 12px 20px rgba(0,0,0,0.2);
    z-index: 2;
    animation: float 3s ease-in-out infinite;
  }

  .nf-title {
    font-size: 26px;
    font-weight: 900;
    color: #0f172a;
    margin: 16px 0 8px;
    line-height: 1.2;
  }

  .nf-desc {
    font-size: 14px;
    color: #64748b;
    font-weight: 700;
    line-height: 1.5;
    margin-bottom: 28px;
  }

  .btn-game {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    width: 100%;
    padding: 14px;
    border-radius: 100px;
    font-size: 15px;
    font-weight: 900;
    text-decoration: none;
    transition: transform 0.1s;
  }
  .btn-game--primary {
    background: linear-gradient(135deg, #22d3ee, #0ea5e9);
    border: 3px solid #fff;
    color: #fff;
    box-shadow: 0 6px 0 #0284c7;
    margin-bottom: 12px;
  }
  .btn-game--primary:active { transform: translateY(6px); box-shadow: 0 0 0 #0284c7; }

  .btn-game--ghost {
    background: #f1f5f9;
    border: 3px solid #cbd5e1;
    color: #475569;
    box-shadow: 0 4px 0 #94a3b8;
  }
  .btn-game--ghost:active { transform: translateY(4px); box-shadow: 0 0 0 #94a3b8; }

  .clouds {
    position: absolute;
    top: 0; left: 0; right: 0; bottom: 0;
    pointer-events: none;
    z-index: 0;
    overflow: hidden;
  }
  .cloud {
    position: absolute;
    background: #fff;
    border-radius: 100px;
    opacity: 0.6;
  }
  .cloud::before, .cloud::after {
    content: '';
    position: absolute;
    background: #fff;
    border-radius: 50%;
  }

  @keyframes float {
    0%, 100% { transform: translate(-50%, 0); }
    50% { transform: translate(-50%, -10px); }
  }
  @keyframes bounceIn {
    0% { transform: scale(0.5); opacity: 0; }
    100% { transform: scale(1); opacity: 1; }
  }
</style>
</head>
<body>
<div class="not-found-page">
  <div class="clouds">
    <div class="cloud" style="width: 100px; height: 30px; top: 10%; left: -20px;"></div>
    <div class="cloud" style="width: 150px; height: 40px; top: 25%; right: -40px;"></div>
    <div class="cloud" style="width: 80px; height: 25px; bottom: 15%; left: 10%;"></div>
  </div>

  <div class="nf-card">
    <div class="nf-badge">😵‍💫</div>

    <div class="nf-title">Nyasar Bosku!</div>
    <div class="nf-desc">
      Halaman yang kamu cari nggak ada di map. Mungkin udah kena *game over* atau di-delete. 🎮❌
    </div>

    <div class="nf-actions">
      <?php if ($user): ?>
        <a href="/home" class="btn-game btn-game--primary">
          <i class="ph-bold ph-house"></i> Mulai Ulang (Beranda)
        </a>
        <a href="javascript:history.back()" class="btn-game btn-game--ghost">
          <i class="ph-bold ph-arrow-u-up-left"></i> Mundur Selangkah
        </a>
      <?php else: ?>
        <a href="/login" class="btn-game btn-game--primary">
          <i class="ph-bold ph-sign-in"></i> Login Dulu Sini
        </a>
        <a href="javascript:history.back()" class="btn-game btn-game--ghost">
          <i class="ph-bold ph-arrow-u-up-left"></i> Mundur Selangkah
        </a>
      <?php endif; ?>
    </div>
  </div>

</div>
<script src="https://unpkg.com/@phosphor-icons/web"></script>
</body>
</html>
