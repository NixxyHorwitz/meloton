  </main>

  <style>
  /* ══════════════════════════════════════════════
     CASUAL GAME BOTTOM NAVBAR (COMPACT & MINIMAL)
     ══════════════════════════════════════════════ */
  .bottom-nav {
      position: fixed !important;
      bottom: 24px !important;
      left: 50% !important;
      transform: translateX(-50%) !important;
      width: max-content !important;
      min-width: 320px !important;
      height: auto !important;
      background: rgba(255, 255, 255, 0.95) !important;
      backdrop-filter: blur(12px) !important;
      border: 2px solid #e2e8f0 !important;
      border-radius: 32px !important;
      box-shadow: 0 8px 32px rgba(0,0,0,0.08) !important;
      display: flex !important;
      justify-content: space-between !important;
      align-items: center !important;
      padding: 8px 16px !important;
      z-index: 9999 !important;
      margin: 0 !important;
  }
  
  /* Space out main content so it doesn't hide behind floating nav */
  body { padding-bottom: 96px !important; }
  
  .nav-item {
      display: flex !important;
      flex-direction: column !important;
      align-items: center !important;
      justify-content: center !important;
      text-decoration: none !important;
      color: #94a3b8 !important;
      font-size: 10px !important;
      font-weight: 700 !important;
      gap: 4px !important;
      padding: 4px 8px !important;
      transition: all 0.2s ease !important;
      border: none !important;
      background: transparent !important;
      border-radius: 16px !important;
  }
  .nav-item i { font-size: 22px !important; transition: all 0.2s ease !important; }
  
  /* Active State */
  .nav-item.active { color: #0ea5e9 !important; }
  .nav-item.active i { color: #0ea5e9 !important; transform: translateY(-2px) !important; }
  
  /* The Play Button (Center) - Minimalist Version */
  .nav-item--play i {
      font-size: 28px !important;
      color: #f59e0b !important;
      filter: drop-shadow(0 2px 4px rgba(245,158,11,0.3)) !important;
  }
  .nav-item--play.active i {
      color: #d97706 !important;
      transform: translateY(-2px) scale(1.1) !important;
  }
  
  /* Floating contact buttons adjustment */
  .float-contact-wrap { bottom: 106px !important; }
  </style>

  <nav class="bottom-nav">
    <a href="/home" class="nav-item <?= ($activePage??'')==='home'?'active':'' ?>">
      <i class="<?= ($activePage??'')==='home'?'ph-fill':'ph-bold' ?> ph-house"></i>
      Beranda
    </a>
    <a href="/upgrade" class="nav-item <?= ($activePage??'')==='upgrade'?'active':'' ?>">
      <i class="<?= ($activePage??'')==='upgrade'?'ph-fill':'ph-bold' ?> ph-rocket-launch"></i>
      Upgrade
    </a>
    
    <a href="/videos" class="nav-item nav-item--play <?= ($activePage??'')==='videos'?'active':'' ?>">
      <i class="<?= ($activePage??'')==='videos'?'ph-fill':'ph-bold' ?> ph-play-circle"></i>
      Tonton
    </a>
    
    <a href="/referral" class="nav-item <?= ($activePage??'')==='referral'?'active':'' ?>">
      <i class="<?= ($activePage??'')==='referral'?'ph-fill':'ph-bold' ?> ph-users"></i>
      Teman
    </a>
    <a href="/profile" class="nav-item <?= ($activePage??'')==='profile'?'active':'' ?>">
      <i class="<?= ($activePage??'')==='profile'?'ph-fill':'ph-bold' ?> ph-user-circle"></i>
      Profil
    </a>
  </nav>
</div>

<?php
// ── Floating contact buttons ─────────────────────────────────
$_floating_on = setting($pdo, 'floating_enabled', '1') === '1';
$_float_btns  = [];
if ($_floating_on) {
    try {
        $__q = $pdo->query("SELECT * FROM contact_buttons WHERE is_active=1 ORDER BY sort_order ASC, id ASC");
        $_float_btns = $__q ? $__q->fetchAll() : [];
    } catch (\Throwable) {}
}
if ($_floating_on && !empty($_float_btns)):
// Preset SVGs inline (needed for rendering without external calls)
$_fsvg = [
  'wa'   => '<svg viewBox="0 0 24 24" fill="currentColor" width="22" height="22"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347z"/><path d="M12 0C5.373 0 0 5.373 0 12c0 2.123.554 4.118 1.528 5.847L.057 23.883a.5.5 0 00.61.61l6.037-1.472A11.944 11.944 0 0012 24c6.627 0 12-5.373 12-12S18.627 0 12 0zm0 22c-1.89 0-3.655-.518-5.17-1.42l-.37-.22-3.823.933.954-3.722-.242-.383A9.958 9.958 0 012 12C2 6.477 6.477 2 12 2s10 4.477 10 10-4.477 10-10 10z"/></svg>',
  'tele' => '<svg viewBox="0 0 24 24" fill="currentColor" width="22" height="22"><path d="M11.944 0A12 12 0 000 12a12 12 0 0012 12 12 12 0 0012-12A12 12 0 0012 0a12 12 0 00-.056 0zm4.962 7.224c.1-.002.321.023.465.14a.506.506 0 01.171.325c.016.093.036.306.02.472-.18 1.898-.962 6.502-1.36 8.627-.168.9-.499 1.201-.82 1.23-.696.065-1.225-.46-1.9-.902-1.056-.693-1.653-1.124-2.678-1.8-1.185-.78-.417-1.21.258-1.91.177-.184 3.247-2.977 3.307-3.23.007-.032.014-.15-.056-.212s-.174-.041-.249-.024c-.106.024-1.793 1.14-5.061 3.345-.48.33-.913.49-1.302.48-.428-.008-1.252-.241-1.865-.44-.752-.245-1.349-.374-1.297-.789.027-.216.325-.437.893-.663 3.498-1.524 5.83-2.529 6.998-3.014 3.332-1.386 4.025-1.627 4.476-1.635z"/></svg>',
  'cs'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="22" height="22"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg>',
  'ig'   => '<svg viewBox="0 0 24 24" fill="currentColor" width="22" height="22"><path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zm0-2.163c-3.259 0-3.667.014-4.947.072-4.358.2-6.78 2.618-6.98 6.98-.059 1.281-.073 1.689-.073 4.948 0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98 1.281.058 1.689.072 4.948.072 3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98-1.281-.059-1.69-.073-4.949-.073zm0 5.838a6.162 6.162 0 100 12.324 6.162 6.162 0 000-12.324zM12 16a4 4 0 110-8 4 4 0 010 8zm6.406-11.845a1.44 1.44 0 100 2.881 1.44 1.44 0 000-2.881z"/></svg>',
  'fb'   => '<svg viewBox="0 0 24 24" fill="currentColor" width="22" height="22"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>',
];
?>
<style>
.float-contact-wrap { position:fixed; bottom:80px; right:14px; z-index:500; display:flex; flex-direction:column; align-items:flex-end; gap:10px; }
.float-btn {
  width: 52px; height: 52px;
  border-radius: 18px;
  border: 3px solid #fff;
  box-shadow: 0 6px 0 rgba(0,0,0,0.15), 0 10px 15px rgba(0,0,0,0.1);
  display: flex; align-items: center; justify-content: center;
  text-decoration: none;
  transition: transform 0.1s, box-shadow 0.1s;
  overflow: hidden; position: relative;
}
.float-btn::after {
  content: ''; position: absolute; top: 0; left: 0; right: 0; height: 50%;
  background: linear-gradient(180deg, rgba(255,255,255,0.3) 0%, rgba(255,255,255,0) 100%);
  pointer-events: none;
}
.float-btn:active {
  transform: translateY(4px);
  box-shadow: 0 2px 0 rgba(0,0,0,0.15), 0 4px 6px rgba(0,0,0,0.1);
}
.float-btn img { width: 100%; height: 100%; object-fit: cover; }
.float-btn__label {
  position: absolute; right: 64px; top: 50%; transform: translateY(-50%);
  background: #0c4a6e; color: #fff; font-size: 11px; font-weight: 900;
  white-space: nowrap; padding: 6px 12px; border-radius: 12px;
  border: 2px solid #075985; box-shadow: 0 4px 0 rgba(0,0,0,0.2);
  opacity: 0; pointer-events: none; transition: all 0.2s cubic-bezier(0.34, 1.56, 0.64, 1);
  margin-right: -10px;
}
.float-btn:hover .float-btn__label {
  opacity: 1; margin-right: 0;
}
</style>
<div class="float-contact-wrap" id="float-contacts">
  <?php foreach ($_float_btns as $_fb): ?>
  <a href="<?= htmlspecialchars($_fb['url']) ?>" target="_blank" rel="noopener"
     class="float-btn" style="background-color:<?= htmlspecialchars($_fb['bg_color']) ?>"
     title="<?= htmlspecialchars($_fb['label']) ?>">
    <?php if ($_fb['icon_type'] === 'custom'): ?>
      <img src="<?= htmlspecialchars($_fb['icon_value']) ?>" alt="<?= htmlspecialchars($_fb['label']) ?>">
    <?php else: ?>
      <span style="color:#fff;display:flex;align-items:center;justify-content:center;position:relative;z-index:2;"><?= $_fsvg[$_fb['icon_value']] ?? '<i class="ph-fill ph-headset" style="font-size:26px"></i>' ?></span>
    <?php endif; ?>
    <span class="float-btn__label"><?= htmlspecialchars($_fb['label']) ?></span>
  </a>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<script src="/assets/js/toast.js"></script>
<?php
// Fitur Global WD Notifs (6 Jam Terakhir)
$show_wd_notif = true;
if (function_exists('is_wd_locked') && isset($pdo)) {
    if (is_wd_locked($pdo)) {
        $show_wd_notif = false;
    }
}

$recent_wd_list = [];
if ($show_wd_notif) {
    try {
        $wd_notif_stmt = $pdo->query("SELECT u.username, w.amount FROM withdrawals w JOIN users u ON u.id = w.user_id WHERE w.created_at >= DATE_SUB(NOW(), INTERVAL 6 HOUR) ORDER BY w.id DESC LIMIT 15");
        $recent_wd_list = $wd_notif_stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable $th) {
        $recent_wd_list = [];
    }
}
?>
<?php if ($show_wd_notif): ?>
<script>
(function() {
  // Ambil data WD asli
  let wdNotifs = <?= json_encode($recent_wd_list ?: []) ?>;
  
  // Daftar nama palsu untuk meramaikan
  const fakeNames = ["Andi", "Budi", "Cici", "Dedi", "Eka", "Fajar", "Gita", "Hadi", "Indra", "Joko", "Rina", "Siti", "Ayu", "Dian", "Fitri", "Maya", "Nina", "Putra", "Rizky", "Sari", "Tri", "Wahyu", "Yudi", "Agus", "Bambang", "Rudi", "Hendra", "Iwan", "Yanto", "Arif", "Hasan", "Rizki", "Nanda", "Ahmad", "Irfan", "0812", "0821", "0852", "0896"];
  
  // Fungsi generate WD palsu
  function generateFakeWD() {
    const name = fakeNames[Math.floor(Math.random() * fakeNames.length)];
    // Nominal acak kelipatan 10.000 dari 20.000 sampai 250.000
    const amount = (Math.floor(Math.random() * 24) + 2) * 10000;
    return { username: name, amount: amount };
  }

  // Tambahkan fake data jika data terlalu sedikit (biar ramai)
  // Total akan dibuat minimal 25 notif campuran
  while (wdNotifs.length < 25) {
    wdNotifs.push(generateFakeWD());
  }

  // Acak (shuffle) array agar tampil natural
  wdNotifs.sort(() => Math.random() - 0.5);

  if (wdNotifs && wdNotifs.length > 0) {
    function showRandomWD() {
      // Jangan tampilkan lagi jika array sudah kosong
      if (wdNotifs.length === 0) return;

      // Ambil dan hapus 1 data dari array (supaya tidak berulang)
      const wd = wdNotifs.pop();
      const amtStr = 'Rp ' + parseFloat(wd.amount).toLocaleString('id-ID');
      
      // Mask username (e.g. Budi -> Bud*** atau 0812 -> 081***)
      let uname = wd.username;
      if (uname.length > 3) {
          uname = uname.substring(0, 3) + '***';
      } else {
          uname = uname + '***';
      }

      if (typeof window.nToast === 'function') {
        window.nToast(`💸 <b>${uname}</b> baru saja menarik <b>${amtStr}</b>`, 'success', 4000);
      }
      
      // Panggil lagi untuk notifikasi berikutnya
      if (wdNotifs.length > 0) {
        const nextDelay = Math.floor(Math.random() * (40000 - 15000 + 1)) + 15000; // Delay 15s - 40s
        setTimeout(showRandomWD, nextDelay);
      }
    }
    
    //setTimeout(showRandomWD, Math.floor(Math.random() * 6000) + 3000);
  }
})();
</script>
<?php endif; ?>
</body>
</html>

