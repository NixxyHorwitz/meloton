<?php
declare(strict_types=1);
// This file is included by guard.php — $maintenance_msg is already set
// Can also be accessed directly as a standalone page
if (!isset($pdo)) {
    require_once dirname(__DIR__) . '/bootstrap.php';
}
$maintenance_msg ??= setting($pdo, 'maintenance_message', 'Sistem sedang dalam perbaikan.');
?><!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Maintenance — Meloton</title>
<link rel="stylesheet" href="/assets/css/app.css?v=<?= @filemtime($_SERVER['DOCUMENT_ROOT'].'/assets/css/app.css') ?: time() ?>">
<style>
  .maint-shell {
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--bg);
    padding: 24px 16px;
  }
  .maint-card {
    background: var(--yellow);
    border: 3px solid var(--border);
    box-shadow: 6px 6px 0 var(--border);
    border-radius: 16px;
    padding: 40px 32px;
    max-width: 420px;
    width: 100%;
    text-align: center;
  }
  .maint-icon {
    font-size: 72px;
    display: block;
    margin-bottom: 16px;
    animation: spin 4s linear infinite;
  }
  @keyframes spin {
    0%,100%{transform:rotate(-8deg) scale(1)}
    50%{transform:rotate(8deg) scale(1.05)}
  }
  .maint-title {
    font-size: 24px;
    font-weight: 900;
    color: var(--text);
    margin-bottom: 12px;
    letter-spacing: -0.5px;
  }
  .maint-msg {
    font-size: 15px;
    color: var(--text2);
    line-height: 1.6;
    margin-bottom: 24px;
  }
  .maint-badge {
    display: inline-block;
    background: var(--border);
    color: #fff;
    font-size: 12px;
    font-weight: 700;
    padding: 6px 16px;
    border-radius: 50px;
    letter-spacing: 0.5px;
    text-transform: uppercase;
  }
  .maint-dots {
    display: flex;
    justify-content: center;
    gap: 6px;
    margin: 20px 0 0;
  }
  .maint-dots span {
    width: 10px; height: 10px;
    background: var(--border);
    border-radius: 50%;
    animation: bounce 1.2s infinite;
  }
  .maint-dots span:nth-child(2) { animation-delay: .2s; }
  .maint-dots span:nth-child(3) { animation-delay: .4s; }
  @keyframes bounce {
    0%,80%,100%{ transform: scale(0.7); opacity:.5 }
    40%{ transform: scale(1); opacity:1 }
  }
</style>
</head>
<body>
<div class="maint-shell">
  <div class="maint-card">
    <span class="maint-icon">🔧</span>
    <div class="maint-title">Sedang Maintenance</div>
    <div class="maint-msg"><?= htmlspecialchars($maintenance_msg) ?></div>
    <div class="maint-badge">🕐 Harap Tunggu</div>
    <div class="maint-dots">
      <span></span><span></span><span></span>
    </div>
  </div>
</div>
</body>
</html>
