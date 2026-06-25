<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/auth/guard.php';

$checkin_min  = max(1, (float) setting($pdo, 'checkin_reward_min', '500'));
$checkin_max  = max($checkin_min, (float) setting($pdo, 'checkin_reward_max', '2000'));
$today        = date('Y-m-d');
$last_checkin = $user['last_checkin'] ?? null;
$already      = $last_checkin === $today;

// Streak hitung
$streak = 0;
if ($last_checkin) {
    $diff = (int)((strtotime($today) - strtotime($last_checkin)) / 86400);
    if ($diff <= 1) {
        $sq = $pdo->prepare("SELECT COUNT(DISTINCT DATE(watched_at)) FROM watch_history WHERE user_id=? AND watched_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)");
        $sq->execute([$user['id']]);
        $streak = max(1, (int)$sq->fetchColumn());
    }
}

$flash = $flashType = '';
$reward_given = 0;

// Ambil reward dari session jika ada (untuk display setelah redirect)
if (!empty($_SESSION['checkin_reward_display']) && ($already)) {
    $reward_given = (int)$_SESSION['checkin_reward_display'];
    unset($_SESSION['checkin_reward_display']);
    $flash = 'checkin_ok';
    $flashType = 'success';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'checkin') {
    if ($already && $flash !== 'checkin_ok') {
        $flash = 'Kamu sudah check-in hari ini. Kembali besok!';
        $flashType = 'warn';
    } elseif ($flash !== 'checkin_ok') {
        // Generate reward server-side — klien tidak bisa manipulasi
        $reward_given = rand((int)$checkin_min, (int)$checkin_max);
        try {
            $pdo->beginTransaction();
            // Double-guard: WHERE clause pakai CURDATE() server → tanggal device klien tidak berpengaruh
            $stmt = $pdo->prepare(
                "UPDATE users SET balance_dep=balance_dep+?, last_checkin=CURDATE()
                 WHERE id=? AND (last_checkin IS NULL OR last_checkin < CURDATE())"
            );
            $stmt->execute([$reward_given, $user['id']]);
            if ($stmt->rowCount() > 0) {
                $pdo->commit();
                // Simpan reward ke session untuk ditampilkan setelah page load
                $_SESSION['checkin_reward_display'] = $reward_given;
                // PRG redirect untuk hindari double-submit
                header('Location: /checkin');
                exit;
            } else {
                $pdo->rollBack();
                $flash = 'Kamu sudah check-in hari ini!';
                $flashType = 'warn';
                $already = true;
            }
        } catch (\Throwable) {
            $pdo->rollBack();
            $flash = 'Terjadi kesalahan.';
            $flashType = 'error';
        }
    }
}

$pageTitle  = 'Check-in Harian';
$activePage = 'checkin';
require dirname(__DIR__) . '/partials/header.php';

$completed_days = $already ? ($streak % 7 ?: 7) : ($streak % 7);
if ($streak == 0 && $already) $completed_days = 1;
?>

<style>
/* ══════════════════════════════════════════
   SCRATCH CARD CHECK-IN
   ══════════════════════════════════════════ */
.checkin-page { padding: 4px 0 20px; }

/* Page title */
.ci-heading { text-align:center; margin-bottom:4px; }
.ci-heading h1 { font-size:20px; font-weight:900; color:#0c4a6e; margin:0; }
.ci-heading p  { font-size:11px; color:#64748b; font-weight:700; margin:4px 0 0; }

/* Streak bar */
.streak-bar {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 5px;
  margin: 12px 0;
}
.streak-day {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 3px;
}
.streak-dot {
  width: 32px; height: 32px;
  border-radius: 10px;
  display: flex; align-items: center; justify-content: center;
  font-size: 14px;
  border: 2px solid;
  transition: all 0.2s;
}
.streak-dot--done  { background: linear-gradient(135deg,#34d399,#10b981); border-color: #6ee7b7; color:#fff; box-shadow:0 3px 0 #047857; }
.streak-dot--today { background: linear-gradient(135deg,#fbbf24,#f59e0b); border-color: #fde68a; color:#fff; box-shadow:0 3px 0 #d97706; animation: pulse-today 1.5s ease infinite; }
.streak-dot--future{ background: #f1f5f9; border-color: #cbd5e1; color:#94a3b8; }
.streak-lbl { font-size:9px; font-weight:800; color:#64748b; }
@keyframes pulse-today {
  0%,100% { box-shadow:0 3px 0 #d97706; }
  50% { box-shadow:0 3px 0 #d97706, 0 0 10px rgba(251,191,36,0.5); }
}

/* Scratch Card container */
.scratch-wrap {
  position: relative;
  margin: 16px auto 0;
  width: 100%;
  max-width: 320px;
}
.scratch-card-bg {
  background: linear-gradient(135deg, #0c4a6e 0%, #0e7490 50%, #06b6d4 100%);
  border: 3px solid #075985;
  border-radius: 22px;
  box-shadow: 0 8px 0 #0c4a6e, 0 12px 28px rgba(8,145,178,0.3);
  padding: 20px;
  text-align: center;
  position: relative;
  overflow: hidden;
  min-height: 220px;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
}
.scratch-card-bg::before {
  content: '';
  position: absolute;
  top: -40px; right: -30px;
  width: 140px; height: 140px;
  border-radius: 50%;
  background: rgba(255,255,255,0.06);
}
.scratch-reveal {
  position: relative; z-index: 2;
}
.scratch-icon { font-size: 40px; margin-bottom: 6px; }
.scratch-label { font-size: 11px; font-weight: 800; color: rgba(255,255,255,0.7); text-transform: uppercase; letter-spacing: 1px; margin-bottom: 6px; }
.scratch-amount {
  font-size: 32px; font-weight: 900; color: #fde68a;
  text-shadow: 0 2px 0 rgba(0,0,0,0.3);
  letter-spacing: -1px;
  animation: pop-in 0.4s cubic-bezier(.36,.07,.19,.97);
}
.scratch-sub { font-size: 11px; color: rgba(255,255,255,0.6); font-weight: 700; margin-top: 4px; }
.scratch-done-badge {
  display: inline-flex; align-items: center; gap: 5px;
  background: rgba(52,211,153,0.2);
  border: 1.5px solid #34d399;
  border-radius: 20px;
  padding: 4px 14px;
  font-size: 11px; font-weight: 900; color: #34d399;
  margin-top: 10px;
}

/* Canvas overlay */
.scratch-canvas-wrap {
  position: absolute;
  inset: 0;
  border-radius: 20px;
  overflow: hidden;
  z-index: 10;
  cursor: crosshair;
}
#scratchCanvas {
  width: 100%;
  height: 100%;
  display: block;
  touch-action: none;
}
.scratch-hint {
  position: absolute;
  bottom: 14px;
  left: 50%;
  transform: translateX(-50%);
  z-index: 20;
  background: rgba(0,0,0,0.45);
  color: #fff;
  font-size: 11px; font-weight: 800;
  padding: 5px 14px;
  border-radius: 20px;
  white-space: nowrap;
  pointer-events: none;
  animation: hint-bounce 1s ease infinite alternate;
}
@keyframes hint-bounce {
  from { transform: translateX(-50%) translateY(0); }
  to   { transform: translateX(-50%) translateY(-4px); }
}

/* Already done state */
.scratch-done-wrap {
  margin: 16px auto 0;
  max-width: 320px;
}
.done-card {
  background: #f0fdf4;
  border: 2.5px solid #bbf7d0;
  border-radius: 20px;
  padding: 24px 20px;
  text-align: center;
  box-shadow: 0 5px 0 #86efac;
}
.done-card i { font-size: 40px; color: #10b981; margin-bottom: 8px; display: block; }
.done-card__title { font-size: 16px; font-weight: 900; color: #0c4a6e; margin-bottom: 4px; }
.done-card__sub { font-size: 11px; color: #64748b; font-weight: 700; }
.done-card__amount { font-size: 26px; font-weight: 900; color: #10b981; margin: 8px 0; }

/* Stats row */
.ci-stats {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 10px;
  margin-top: 16px;
}
.ci-stat {
  background: #fff;
  border: 2.5px solid #7dd3e8;
  border-radius: 16px;
  padding: 12px;
  text-align: center;
  box-shadow: 0 4px 0 #7dd3e8;
}
.ci-stat__val { font-size: 20px; font-weight: 900; color: #0c4a6e; }
.ci-stat__lbl { font-size: 10px; font-weight: 800; color: #64748b; margin-top: 2px; }

/* Flash */
.ci-alert { padding: 10px 14px; border-radius: 12px; font-size: 12px; font-weight: 700; margin-bottom: 12px; border: 2px solid; display: flex; align-items: center; gap: 8px; }
.ci-alert--warn  { background: #fffbeb; color: #92400e; border-color: #fde68a; }
.ci-alert--error { background: #fef2f2; color: #991b1b; border-color: #fecaca; }

@keyframes pop-in {
  0% { transform: scale(0.5); opacity: 0; }
  70% { transform: scale(1.1); }
  100% { transform: scale(1); opacity: 1; }
}
</style>

<div class="checkin-page">

  <!-- Heading -->
  <div class="ci-heading">
    <h1><i class="ph-fill ph-calendar-check" style="color:#0891b2;font-size:22px;vertical-align:middle"></i> Check-in Harian</h1>
    <p>Gosok kartunya setiap hari untuk dapetin reward acak!</p>
  </div>

  <!-- Flash alerts (non-success) -->
  <?php if ($flash && $flash !== 'checkin_ok'): ?>
  <div class="ci-alert ci-alert--<?= $flashType === 'error' ? 'error' : 'warn' ?>">
    <i class="ph-bold ph-warning-circle" style="font-size:16px;flex-shrink:0"></i>
    <?= htmlspecialchars($flash) ?>
  </div>
  <?php endif; ?>

  <!-- Streak bar (7 days) -->
  <div class="streak-bar">
    <?php for ($i = 1; $i <= 7; $i++):
      $is_done   = $i < $completed_days || ($i == $completed_days && $already);
      $is_today  = !$already && $i == $completed_days + 1;
      $cls = $is_done ? 'done' : ($is_today ? 'today' : 'future');
    ?>
    <div class="streak-day">
      <div class="streak-dot streak-dot--<?= $cls ?>">
        <?php if ($is_done): ?><i class="ph-bold ph-check"></i><?php elseif ($is_today): ?>⭐<?php else: ?><?= $i ?><?php endif; ?>
      </div>
      <span class="streak-lbl">H<?= $i ?></span>
    </div>
    <?php endfor; ?>
  </div>

  <!-- MAIN: scratch card or done state -->
  <?php if (!$already): ?>
  <!-- ── SCRATCH CARD ── -->
  <div class="scratch-wrap" id="scratch-wrap">
    <!-- The reward behind -->
    <div class="scratch-card-bg" id="scratch-bg">
      <div class="scratch-reveal" id="reveal-content" style="opacity:0;transition:opacity 0.3s">
        <div class="scratch-icon">🎉</div>
        <div class="scratch-label">Reward Check-in Hari Ini</div>
        <div class="scratch-amount" id="reward-display"></div>
        <div class="scratch-sub">masuk ke Saldo Beli</div>
      </div>
    </div>

    <!-- Canvas layer on top -->
    <div class="scratch-canvas-wrap" id="canvas-wrap">
      <canvas id="scratchCanvas"></canvas>
      <div class="scratch-hint" id="scratch-hint">✋ Gosok untuk buka reward!</div>
    </div>
  </div>

  <!-- Hidden form – submitted after card is scratched -->
  <form method="POST" id="checkin-form" style="display:none">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="checkin">
  </form>

  <?php elseif ($flash === 'checkin_ok'): ?>
  <!-- ── JUST SCRATCHED – show reward ── -->
  <div class="scratch-done-wrap">
    <div class="done-card" style="background:#f0fdf4;border-color:#bbf7d0;box-shadow:0 5px 0 #86efac;padding:28px 20px">
      <div style="font-size:44px;margin-bottom:6px">🎊</div>
      <div style="font-size:12px;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:1px;margin-bottom:4px">Reward Check-in Hari Ini</div>
      <div style="font-size:36px;font-weight:900;color:#10b981;letter-spacing:-1px;margin:6px 0;animation:pop-in .4s ease"><?= format_rp($reward_given) ?></div>
      <div style="font-size:11px;color:#64748b;font-weight:700">masuk ke Saldo Beli kamu 🎉</div>
      <div style="display:inline-flex;align-items:center;gap:5px;background:#dcfce7;border:1.5px solid #86efac;border-radius:20px;padding:5px 16px;font-size:11px;font-weight:900;color:#15803d;margin-top:12px">
        <i class="ph-bold ph-check-circle"></i> Check-in Berhasil!
      </div>
      <div style="margin-top:12px;font-size:12px;color:#64748b">Saldo Beli sekarang: <strong style="color:#0c4a6e"><?= format_rp((float)$user['balance_dep']) ?></strong></div>
    </div>
  </div>

  <?php else: ?>
  <!-- ── ALREADY DONE TODAY ── -->
  <div class="scratch-done-wrap">
    <div class="done-card">
      <i class="ph-fill ph-check-circle"></i>
      <div class="done-card__title">Sudah Check-in Hari Ini!</div>
      <div class="done-card__sub">Kembali lagi besok untuk gosok kartu baru.</div>
      <div class="done-card__amount"><?= format_rp((float)$user['balance_dep']) ?></div>
      <div style="font-size:11px;color:#64748b">Saldo Beli saat ini</div>
    </div>
  </div>
  <?php endif; ?>

  <!-- Stats -->
  <div class="ci-stats">
    <div class="ci-stat">
      <div class="ci-stat__val"><?= $streak ?> 🔥</div>
      <div class="ci-stat__lbl">Hari Aktif</div>
    </div>
    <div class="ci-stat">
      <div class="ci-stat__val" style="font-size:15px"><?= format_rp((float)$user['balance_dep']) ?></div>
      <div class="ci-stat__lbl">Saldo Beli</div>
    </div>
  </div>

  <!-- Range info -->
  <div style="text-align:center;margin-top:10px;font-size:10px;color:#94a3b8;font-weight:700">
    Range reward: <?= format_rp($checkin_min) ?> – <?= format_rp($checkin_max) ?>
  </div>

</div>

<?php if (!$already): ?>
<script>
(function(){
  const canvas  = document.getElementById('scratchCanvas');
  const wrap    = document.getElementById('canvas-wrap');
  const hint    = document.getElementById('scratch-hint');
  const reveal_el = document.getElementById('reveal-content');
  const display = document.getElementById('reward-display');
  const form    = document.getElementById('checkin-form');

  const MIN = <?= (int)$checkin_min ?>;
  const MAX = <?= (int)$checkin_max ?>;
  const localReward = Math.floor(Math.random() * (MAX - MIN + 1)) + MIN;

  function formatRp(n) { return 'Rp\u00a0' + n.toLocaleString('id-ID'); }

  /* ── Web Audio ─────────────────────────────── */
  const AudioCtx = window.AudioContext || window.webkitAudioContext;
  let actx = null;
  function ensureAudio() {
    if (!actx && AudioCtx) { try { actx = new AudioCtx(); } catch(e){} }
    return actx;
  }
  function playScratch() {
    try {
      const a = ensureAudio(); if (!a) return;
      const buf = a.createBuffer(1, a.sampleRate * 0.055, a.sampleRate);
      const d   = buf.getChannelData(0);
      for (let i = 0; i < d.length; i++) d[i] = (Math.random()*2-1)*0.15;
      const src = a.createBufferSource();
      const flt = a.createBiquadFilter(); flt.type='bandpass'; flt.frequency.value=3000;
      const g   = a.createGain();
      src.buffer = buf;
      src.connect(flt); flt.connect(g); g.connect(a.destination);
      g.gain.setValueAtTime(1, a.currentTime);
      g.gain.exponentialRampToValueAtTime(0.001, a.currentTime+0.055);
      src.start();
    } catch(e){}
  }
  function playReveal() {
    try {
      const a = ensureAudio(); if (!a) return;
      [523.25, 659.25, 783.99, 1046.5].forEach((f, i) => {
        const osc = a.createOscillator(); const g = a.createGain();
        osc.connect(g); g.connect(a.destination);
        osc.frequency.value = f; osc.type = 'sine';
        const t = a.currentTime + i*0.1;
        g.gain.setValueAtTime(0,t);
        g.gain.linearRampToValueAtTime(0.22, t+0.02);
        g.gain.exponentialRampToValueAtTime(0.001, t+0.38);
        osc.start(t); osc.stop(t+0.4);
      });
    } catch(e){}
  }

  /* ── Canvas State ─────────────────────────── */
  let ctx2d, dpr, W, H;
  let submitted    = false;
  let active       = false;
  let lastSfx      = 0;
  let strokes      = 0;

  wrap.style.touchAction     = 'none';
  wrap.style.overscrollBehavior = 'none';
  canvas.style.touchAction   = 'none';

  function initCanvas() {
    dpr = window.devicePixelRatio || 1;
    const rect = wrap.getBoundingClientRect();
    W = rect.width;
    H = rect.height;
    if (!W || !H) { requestAnimationFrame(initCanvas); return; }

    canvas.width  = Math.round(W * dpr);
    canvas.height = Math.round(H * dpr);
    canvas.style.width  = W + 'px';
    canvas.style.height = H + 'px';

    ctx2d = canvas.getContext('2d');
    ctx2d.setTransform(dpr, 0, 0, dpr, 0, 0);
    drawCover();
  }

  function drawCover() {
    ctx2d.globalCompositeOperation = 'source-over';
    ctx2d.globalAlpha = 1;

    const g = ctx2d.createLinearGradient(0,0,W,H);
    g.addColorStop(0,   '#607d8b');
    g.addColorStop(0.35,'#b0bec5');
    g.addColorStop(0.5, '#eceff1');
    g.addColorStop(0.65,'#b0bec5');
    g.addColorStop(1,   '#607d8b');
    ctx2d.fillStyle = g;
    ctx2d.fillRect(0,0,W,H);

    ctx2d.save();
    ctx2d.strokeStyle = 'rgba(255,255,255,0.28)';
    ctx2d.lineWidth = 1.5;
    for (let i=-H; i<W+H; i+=13) {
      ctx2d.beginPath(); ctx2d.moveTo(i,0); ctx2d.lineTo(i+H,H); ctx2d.stroke();
    }
    ctx2d.restore();

    ctx2d.textAlign   = 'center';
    ctx2d.textBaseline = 'middle';
    ctx2d.font = '18px serif';
    for (let r=0; r<3; r++) for (let c=0; c<4; c++) {
      ctx2d.globalAlpha = 0.22;
      ctx2d.fillText('\uD83E\uDE99', (c+0.5)*(W/4), (r+0.5)*(H/3));
    }
    ctx2d.globalAlpha = 1;

    ctx2d.fillStyle = 'rgba(0,0,0,0.48)';
    ctx2d.font = 'bold 13px Nunito,sans-serif';
    ctx2d.fillText('GOSOK DI SINI', W/2, H/2-12);
    ctx2d.font = '24px serif';
    ctx2d.fillText('\u270B', W/2, H/2+14);
  }

  function getXY(e) {
    const rect = canvas.getBoundingClientRect();
    const list = e.changedTouches || e.touches;
    const src  = list ? list[0] : e;
    return { x: src.clientX - rect.left, y: src.clientY - rect.top };
  }

  function paint(x, y) {
    ctx2d.globalCompositeOperation = 'destination-out';
    ctx2d.beginPath();
    ctx2d.arc(x, y, 32, 0, Math.PI*2);
    ctx2d.fill();

    const now = Date.now();
    if (now - lastSfx > 80) { playScratch(); lastSfx = now; }

    strokes++;
    if (strokes % 6 === 0) checkPercent();
  }

  function checkPercent() {
    if (submitted) return;
    const px = ctx2d.getImageData(0, 0, canvas.width, canvas.height).data;
    let gone=0, tot=0;
    for (let i=3; i<px.length; i+=16) { if (px[i]<10) gone++; tot++; }
    if ((gone/tot)*100 > 38) reveal();
  }

  function reveal() {
    submitted = true;
    ctx2d.globalCompositeOperation = 'destination-out';
    ctx2d.fillRect(0,0,W,H);
    display.textContent = formatRp(localReward);
    reveal_el.style.opacity = '1';
    hint.style.display = 'none';
    playReveal();
    setTimeout(() => form.submit(), 1700);
  }

  canvas.addEventListener('mousedown', e => {
    ensureAudio(); active = true; const p=getXY(e); paint(p.x,p.y);
  });
  canvas.addEventListener('mousemove', e => {
    if (!active || submitted) return; const p=getXY(e); paint(p.x,p.y);
  });
  window.addEventListener('mouseup', () => { active = false; });

  function onTouchStart(e) {
    ensureAudio();
    active = true;
    e.preventDefault();
    const p = getXY(e); paint(p.x, p.y);
  }
  function onTouchMove(e) {
    if (!active || submitted) return;
    e.preventDefault();
    for (let i=0; i<e.changedTouches.length; i++) {
      const t  = e.changedTouches[i];
      const rect = canvas.getBoundingClientRect();
      paint(t.clientX - rect.left, t.clientY - rect.top);
    }
  }
  function onTouchEnd() { active = false; }

  canvas.addEventListener('touchstart', onTouchStart, { passive: false, capture: false });
  canvas.addEventListener('touchmove',  onTouchMove,  { passive: false, capture: false });
  canvas.addEventListener('touchend',   onTouchEnd,   { passive: true  });
  canvas.addEventListener('touchcancel',onTouchEnd,   { passive: true  });

  requestAnimationFrame(initCanvas);
})();
</script>
<?php endif; ?>

<?php require dirname(__DIR__) . '/partials/footer.php'; ?>
