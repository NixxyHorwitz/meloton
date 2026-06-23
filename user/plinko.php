<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/auth/guard.php';

// Guard: Check if Plinko feature is enabled globally
$plinko_enabled = setting($pdo, 'plinko_enabled', '1') === '1';
if (!$plinko_enabled) {
    $_SESSION['flash_home_err'] = '⚠️ Mini Game Plinko sedang dinonaktifkan oleh Administrator.';
    redirect('/home');
}

// Rates: Read dynamically from settings
$plinko_buy_rate  = (float)setting($pdo, 'plinko_buy_rate', '100.0');
$plinko_sell_rate = (float)setting($pdo, 'plinko_sell_rate', '100.0');

$multipliers = [10.0, 3.0, 1.5, 0.8, 0.2, 0.8, 1.5, 3.0, 10.0]; // 8 rows -> 9 buckets

// ── BACKEND AJAX ENDPOINTS ───────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    // PLAY PLINKO (DROP BALL)
    if ($action === 'play') {
        header('Content-Type: application/json');
        csrf_verify() or die(json_encode(['error' => 'Invalid CSRF token.']));
        
        $bet = (int)($_POST['bet'] ?? 10);
        if (!in_array($bet, [10, 25, 50, 100], true)) {
            echo json_encode(['error' => 'Jumlah taruhan koin tidak valid.']);
            exit;
        }
        
        try {
            $pdo->beginTransaction();
            
            // Lock user row (including plinko_rtp override)
            $stmt = $pdo->prepare("SELECT plinko_coins, balance_wd, plinko_rtp FROM users WHERE id = ? FOR UPDATE");
            $stmt->execute([$user['id']]);
            $usrData = $stmt->fetch();
            $current_coins = (int)$usrData['plinko_coins'];
            $user_rtp = $usrData['plinko_rtp'];
            
            if ($current_coins < $bet) {
                $pdo->rollBack();
                echo json_encode(['error' => 'Koin kamu tidak cukup. Silakan klaim koin gratis atau beli koin di Lapak terlebih dahulu!']);
                exit;
            }
            
            // Determine target RTP: check user override first, then global settings
            $default_rtp = (float)setting($pdo, 'plinko_default_rtp', '99.8');
            $rtp = $user_rtp !== null ? (float)$user_rtp : $default_rtp;
            
            // Mathematical bias engine for targeted Return to Player (RTP)
            $binom_coeffs = [1, 8, 28, 56, 70, 56, 28, 8, 1];
            $target = $rtp / 100.0;
            $target = max(0.2, min(10.0, $target)); // Clamp within physical limits [0.2x, 10.0x]
            
            // Binary search to find optimal p bias factor
            $low = 0.0001;
            $high = 1000.0;
            for ($i = 0; $i < 30; $i++) {
                $mid = ($low + $high) / 2.0;
                $sum_weight = 0.0;
                $sum_rtp = 0.0;
                for ($k = 0; $k <= 8; $k++) {
                    $weight = $binom_coeffs[$k] * pow($mid, abs($k - 4));
                    $sum_weight += $weight;
                    $sum_rtp += $weight * $multipliers[$k];
                }
                $expected = $sum_rtp / $sum_weight;
                if ($expected < $target) {
                    $low = $mid;
                } else {
                    $high = $mid;
                }
            }
            $p_factor = ($low + $high) / 2.0;
            
            // Normalized probability distribution
            $probs = [];
            $sum_weight = 0.0;
            for ($k = 0; $k <= 8; $k++) {
                $weight = $binom_coeffs[$k] * pow($p_factor, abs($k - 4));
                $probs[$k] = $weight;
                $sum_weight += $weight;
            }
            for ($k = 0; $k <= 8; $k++) {
                $probs[$k] /= $sum_weight;
            }
            
            // Choose landing bucket
            $rand = random_int(0, 1000000) / 1000000.0;
            $cumulative = 0.0;
            $bucket = 8; // fallback
            for ($k = 0; $k <= 8; $k++) {
                $cumulative += $probs[$k];
                if ($rand <= $cumulative) {
                    $bucket = $k;
                    break;
                }
            }
            
            // Generate standard steps path corresponding to the landing bucket
            $steps = [];
            for ($i = 0; $i < $bucket; $i++) {
                $steps[] = 1;
            }
            for ($i = 0; $i < (8 - $bucket); $i++) {
                $steps[] = 0;
            }
            shuffle($steps);
            $path = $steps;
            
            $mult = $multipliers[$bucket];
            $reward_coins = (int)round($bet * $mult);
            
            // Update user row (deduct bet coins, add reward coins)
            $pdo->prepare("
                UPDATE users 
                SET plinko_coins = plinko_coins - ? + ?
                WHERE id = ?
            ")->execute([$bet, $reward_coins, $user['id']]);
            
            // Write to Plinko History Log (reward_wd is set to 0.00, reward_coins tracks actual coins gained)
            $pdo->prepare("
                INSERT INTO plinko_history (user_id, coins_bet, multiplier, reward_wd, reward_coins) 
                VALUES (?, ?, ?, 0.00, ?)
            ")->execute([$user['id'], $bet, $mult, $reward_coins]);
            
            $pdo->commit();
            
            // Fetch updated user balances
            $fresh = $pdo->query("SELECT plinko_coins, balance_wd FROM users WHERE id = {$user['id']}")->fetch();
            
            echo json_encode([
                'ok' => true,
                'path' => $path,
                'bucket' => $bucket,
                'multiplier' => $mult,
                'reward_coins' => $reward_coins,
                'new_coins' => (int)$fresh['plinko_coins'],
                'new_balance_wd' => format_rp((float)$fresh['balance_wd'])
            ]);
            
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            echo json_encode(['error' => 'Gagal memproses taruhan: ' . $e->getMessage()]);
        }
        exit;
    }
}

// ── GET PLINKO LOGS HISTORY ──────────────────────────────────
$history = [];
try {
    $h_stmt = $pdo->prepare("
        SELECT * FROM plinko_history 
        WHERE user_id = ? 
        ORDER BY created_at DESC LIMIT 5
    ");
    $h_stmt->execute([$user['id']]);
    $history = $h_stmt->fetchAll();
} catch (\Throwable $e) {}

$pageTitle  = 'Mini Game Plinko — Meloton';
$activePage = 'plinko';
require dirname(__DIR__) . '/partials/header.php';
?>

<!-- Compact Plinko Header -->
<div style="display:flex;align-items:center;justify-content:space-between;background:var(--lavender);border:2.5px solid var(--ink);border-radius:10px;box-shadow:3px 3px 0 var(--ink);padding:10px 14px;margin-bottom:12px;">
  <div>
    <h1 style="font-weight:900;font-size:15px;margin:0;">🎮 Plinko Arcade</h1>
    <div style="font-size:10px;color:#555;margin-top:2px;">Jatuhkan bola · kumpulkan koin</div>
  </div>
  <a href="/plinko-shop" style="display:flex;align-items:center;gap:4px;background:var(--mint);color:var(--ink);border:2px solid var(--ink);border-radius:7px;box-shadow:2px 2px 0 var(--ink);padding:6px 10px;font-weight:900;font-size:11px;text-decoration:none;white-space:nowrap;">🛒 Lapak</a>
</div>

<!-- Compact Balance Row -->
<div style="display:flex;gap:6px;margin-bottom:12px;">
  <div style="flex:1;background:var(--yellow);border:2px solid var(--ink);border-radius:8px;box-shadow:2.5px 2.5px 0 var(--ink);padding:7px 8px;text-align:center;">
    <div style="font-size:15px;font-weight:900;" id="disp-coins">🪙 <?= number_format((int)$user['plinko_coins']) ?></div>
    <div style="font-size:9px;font-weight:800;color:#555;">KOIN</div>
  </div>
  <div style="flex:1;background:#fff;border:2px solid var(--ink);border-radius:8px;box-shadow:2.5px 2.5px 0 var(--ink);padding:7px 8px;text-align:center;">
    <div style="font-size:11px;font-weight:800;" id="disp-dep"><?= format_rp((float)$user['balance_dep']) ?></div>
    <div style="font-size:9px;font-weight:700;color:#888;">DEP</div>
  </div>
  <div style="flex:1;background:var(--mint);border:2px solid var(--ink);border-radius:8px;box-shadow:2.5px 2.5px 0 var(--ink);padding:7px 8px;text-align:center;">
    <div style="font-size:11px;font-weight:800;" id="disp-wd"><?= format_rp((float)$user['balance_wd']) ?></div>
    <div style="font-size:9px;font-weight:800;">SALDO PENARIKAN</div>
  </div>
</div>

<!-- Main Plinko Game Machine Console (Neo-Brutalist Frame) -->
<div class="card" style="margin-bottom:18px; overflow:hidden; border: 3.5px solid var(--ink); box-shadow: 6px 6px 0 var(--ink);">
  <div class="card__header" style="background:var(--orange); display:flex; justify-content:space-between; align-items:center; border-bottom: 3.5px solid var(--ink); padding: 10px 14px;">
    <div class="card__title" style="color:#fff; font-weight:900; font-size: 14px; text-shadow:1.5px 1.5px 0 var(--ink);">🎯 Papan Permainan Plinko</div>
    <button onclick="toggleSound(this)" class="btn btn--secondary btn--sm" style="font-size:11px; padding:3px 8px; background:var(--yellow); color:var(--ink); border:2px solid var(--ink); box-shadow:1.5px 1.5px 0 var(--ink);">🔊 Suara: ON</button>
  </div>
  <div class="card__body" style="padding:14px; display:flex; flex-direction:column; align-items:center; background:#181818;">
    
    <!-- Canvas Frame Container -->
    <div style="width:100%; max-width:400px; background:#111; border:3.5px solid var(--ink); border-radius:12px; box-shadow:4px 4px 0 var(--ink); overflow:hidden; position:relative;">
      <canvas id="plinkoCanvas" width="400" height="380" style="display:block; width:100%; height:auto;"></canvas>
    </div>
    
    <!-- Game Control Console -->
    <div style="width:100%; max-width:400px; margin-top:14px; background:#fff; border:3px solid var(--ink); border-radius:12px; box-shadow:3px 3px 0 var(--ink); padding:12px;">
      
      <!-- Total Coin Display inside Game Control Panel Card -->
      <div style="
        display: flex; 
        justify-content: space-between; 
        align-items: center; 
        background: var(--yellow); 
        border: 2.5px solid var(--ink); 
        border-radius: 8px; 
        padding: 8px 12px; 
        margin-bottom: 12px; 
        box-shadow: 2px 2px 0 var(--ink);
      ">
        <span style="font-size: 11px; font-weight: 800; color: var(--ink);">🪙 TOTAL KOIN ANDA:</span>
        <span style="font-size: 13px; font-weight: 900; color: var(--ink);" id="control-disp-coins"><?= number_format((int)$user['plinko_coins']) ?> Koin</span>
      </div>

      <div style="font-size:11px; font-weight:900; color:#555; text-transform:uppercase; letter-spacing:1px; margin-bottom:8px; text-align:center; display: flex; align-items: center; justify-content: center; gap: 4px;">💵 Pilih Taruhan (Koin Plinko)</div>
      <div style="display:flex; gap:6px; justify-content:center;">
        <?php foreach ([10, 25, 50, 100] as $bSize): ?>
          <button type="button" class="btn btn-bet-selector <?= $bSize===10?'active':'' ?>" data-bet="<?= $bSize ?>" onclick="selectBet(this)" style="
            flex:1;
            padding:9px 4px;
            font-size:13px;
            font-weight:900;
            border:2.5px solid var(--ink);
            border-radius:8px;
            cursor:pointer;
            background:#f0f0f0;
            box-shadow:3px 3px 0 var(--ink);
            transition:transform .1s, background .1s;
          ">
            <?= $bSize ?>
          </button>
        <?php endforeach; ?>
      </div>
      
      <!-- Launch Arcade Ball Action Button -->
      <button type="button" id="btn-drop" onclick="playPlinko()" class="btn btn--primary btn--full" style="
        margin-top:12px;
        font-size:16px;
        font-weight:900;
        background:var(--mint);
        color:var(--ink);
        border:3px solid var(--ink);
        box-shadow:3px 3px 0 var(--ink);
        padding:12px;
        border-radius:10px;
        text-transform:uppercase;
        letter-spacing:1px;
      ">
        🟢 JATUHKAN BOLA 🟢
      </button>
    </div>
    
  </div>
</div>

<!-- History Log Card -->
<div class="card" style="margin-bottom:16px; box-shadow:4px 4px 0 var(--ink); border:2.5px solid var(--ink);">
  <div class="card__header" style="border-bottom:2.5px solid var(--ink); background:#eee; padding:10px 14px;"><div class="card__title" style="color:var(--ink); font-weight:900; font-size:13px;">📋 Riwayat Bermain Terakhir</div></div>
  <div class="card__body" style="padding:0; background:#fff;">
    <div id="history-container">
      <?php if (empty($history)): ?>
        <div style="padding:16px; text-align:center; font-size:12px; color:#aaa;" id="history-empty">Belum ada riwayat bermain. Mulai jatuhkan bola pertama kamu! 🟢</div>
      <?php else: ?>
        <div style="display:flex; flex-direction:column;">
          <?php foreach ($history as $h): ?>
            <div class="list-item" style="padding:10px 14px; border-bottom:1px dashed #ddd; display:flex; justify-content:space-between; align-items:center; font-size:12px;">
              <div style="display:flex; align-items:center; gap:8px;">
                <div style="width:28px; height:28px; border-radius:6px; border:1.5px solid var(--ink); background:var(--yellow); display:flex; align-items:center; justify-content:center; font-size:12px;">🎮</div>
                <div>
                  <div style="font-weight:900;">Taruhan <?= (int)$h['coins_bet'] ?> Koin (<?= (float)$h['multiplier'] ?>x)</div>
                  <div style="font-size:10px; color:#888; margin-top:2px;"><?= date('d M Y H:i', strtotime($h['created_at'])) ?></div>
                </div>
              </div>
              <div style="font-weight:900; color:var(--green); font-size:13px;">
                <?php if (isset($h['reward_coins']) && $h['reward_coins'] > 0): ?>
                  +<?= number_format((int)$h['reward_coins']) ?> Koin
                <?php else: ?>
                  +<?= format_rp((float)$h['reward_wd']) ?> WD
                <?php endif; ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<style>
/* Bet button active states */
.btn-bet-selector {
  transition: transform 0.1s, box-shadow 0.1s, background-color 0.1s;
}
.btn-bet-selector:hover:not(.active) {
  transform: translate(-1.5px, -1.5px);
  box-shadow: 4.5px 4.5px 0 var(--ink) !important;
  background: #f7f7f7 !important;
}
.btn-bet-selector.active {
  background: var(--yellow) !important;
  color: var(--ink) !important;
  transform: translate(2px, 2px);
  box-shadow: 1px 1px 0 var(--ink) !important;
}
.btn-bet-selector:active {
  transform: translate(3px, 3px);
  box-shadow: 0px 0px 0 var(--ink) !important;
}

/* Launch button transitions */
#btn-drop {
  transition: transform 0.1s, box-shadow 0.1s, background-color 0.1s;
}
#btn-drop:hover:not(:disabled) {
  transform: translate(-2.5px, -2.5px);
  box-shadow: 5.5px 5.5px 0 var(--ink) !important;
}
#btn-drop:active:not(:disabled) {
  transform: translate(2px, 2px);
  box-shadow: 1px 1px 0 var(--ink) !important;
}
</style>

<script>
// CSRF Helper
const _csrf = "<?= csrf_token() ?>";

// Web Audio API Synthesizer Context
let audioCtx = null;
let soundEnabled = true;

function initAudio() {
  if (!audioCtx) {
    audioCtx = new (window.AudioContext || window.webkitAudioContext)();
  }
}

function playBip() {
  if (!soundEnabled) return;
  try {
    initAudio();
    const osc = audioCtx.createOscillator();
    const gain = audioCtx.createGain();
    osc.connect(gain);
    gain.connect(audioCtx.destination);
    
    const f = 600 + Math.random() * 300;
    osc.frequency.setValueAtTime(f, audioCtx.currentTime);
    gain.gain.setValueAtTime(0.04, audioCtx.currentTime);
    gain.gain.exponentialRampToValueAtTime(0.001, audioCtx.currentTime + 0.08);
    
    osc.start();
    osc.stop(audioCtx.currentTime + 0.08);
  } catch(e) {}
}

function playWinChime(isHigh = false) {
  if (!soundEnabled) return;
  try {
    initAudio();
    const now = audioCtx.currentTime;
    const notes = isHigh ? [523.25, 659.25, 783.99, 1046.50] : [261.63, 329.63, 392.00, 523.25];
    
    notes.forEach((freq, idx) => {
      const osc = audioCtx.createOscillator();
      const gain = audioCtx.createGain();
      osc.connect(gain);
      gain.connect(audioCtx.destination);
      
      osc.frequency.setValueAtTime(freq, now + idx * 0.08);
      gain.gain.setValueAtTime(0.06, now + idx * 0.08);
      gain.gain.exponentialRampToValueAtTime(0.001, now + idx * 0.08 + 0.35);
      
      osc.start(now + idx * 0.08);
      osc.stop(now + idx * 0.08 + 0.35);
    });
  } catch(e) {}
}

function toggleSound(btn) {
  soundEnabled = !soundEnabled;
  btn.innerText = soundEnabled ? '🔊 Suara: ON' : '🔇 Suara: OFF';
  btn.style.background = soundEnabled ? 'var(--yellow)' : '#ddd';
}

// Preset active bet choice
let activeBet = 10;
function selectBet(btn) {
  if (isPlaying) return;
  document.querySelectorAll('.btn-bet-selector').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
  activeBet = parseInt(btn.dataset.bet) || 10;
  playBip();
}

// ── CANVAS GAME RENDERING ENGINE ─────────────────────────────
const canvas = document.getElementById('plinkoCanvas');
const ctx = canvas.getContext('2d');

const BOARD_WIDTH = 400;
const BOARD_HEIGHT = 380;
const START_Y = 55;
const ROW_SPACING = 30;
const PEG_SPACING = 30;

const bucketsMultipliers = <?= json_encode($multipliers) ?>;
const totalRows = 8;

let glowingPins = [];

function addGlow(r, i) {
  glowingPins.push({
    row: r,
    idx: i,
    intensity: 1.0
  });
}

function updateGlows() {
  for (let i = glowingPins.length - 1; i >= 0; i--) {
    glowingPins[i].intensity -= 0.08;
    if (glowingPins[i].intensity <= 0) {
      glowingPins.splice(i, 1);
    }
  }
}

const bucketColors = [
  '#FF3366', // 10x
  '#FF9900', // 3x
  '#FFFF00', // 1.5x
  '#00FF66', // 0.8x
  '#555555', // 0.2x
  '#00FF66', // 0.8x
  '#FFFF00', // 1.5x
  '#FF9900', // 3x
  '#FF3366'  // 10x
];

function drawBoard() {
  ctx.fillStyle = '#111';
  ctx.fillRect(0, 0, BOARD_WIDTH, BOARD_HEIGHT);
  
  // Draw subtle premium cyber grid background
  ctx.strokeStyle = 'rgba(255, 255, 255, 0.035)';
  ctx.lineWidth = 1;
  for (let x = 0; x < BOARD_WIDTH; x += 20) {
    ctx.beginPath();
    ctx.moveTo(x, 0);
    ctx.lineTo(x, BOARD_HEIGHT);
    ctx.stroke();
  }
  for (let y = 0; y < BOARD_HEIGHT; y += 20) {
    ctx.beginPath();
    ctx.moveTo(0, y);
    ctx.lineTo(BOARD_WIDTH, y);
    ctx.stroke();
  }
  
  ctx.lineWidth = 1;
  ctx.strokeStyle = '#222';
  for (let r = 0; r < totalRows; r++) {
    const y = START_Y + r * ROW_SPACING;
    const pinsInRow = r + 3;
    const startX = BOARD_WIDTH / 2 - ((pinsInRow - 1) / 2) * PEG_SPACING;
    
    ctx.beginPath();
    ctx.moveTo(startX, y);
    ctx.lineTo(startX + (pinsInRow - 1) * PEG_SPACING, y);
    ctx.stroke();
  }
  
  const bucketY = 325;
  const bucketWidth = 32;
  const bucketHeight = 35;
  const startBucketX = BOARD_WIDTH / 2 - (9 / 2) * bucketWidth;
  
  for (let b = 0; b < 9; b++) {
    const bx = startBucketX + b * bucketWidth;
    
    ctx.fillStyle = '#000';
    ctx.strokeStyle = bucketColors[b]; // Neon outer borders matching multiplier!
    ctx.lineWidth = 2.5;
    
    ctx.beginPath();
    ctx.rect(bx + 2, bucketY, bucketWidth - 4, bucketHeight);
    ctx.fill();
    ctx.stroke();
    
    ctx.fillStyle = bucketColors[b];
    ctx.fillRect(bx + 4, bucketY + 2, bucketWidth - 8, 4);
    
    ctx.fillStyle = bucketColors[b];
    ctx.font = '900 11px monospace';
    ctx.textAlign = 'center';
    ctx.fillText(bucketsMultipliers[b] + 'x', bx + bucketWidth/2, bucketY + 22);
  }
  
  for (let r = 0; r < totalRows; r++) {
    const y = START_Y + r * ROW_SPACING;
    const pinsInRow = r + 3;
    const startX = BOARD_WIDTH / 2 - ((pinsInRow - 1) / 2) * PEG_SPACING;
    
    for (let i = 0; i < pinsInRow; i++) {
      const px = startX + i * PEG_SPACING;
      const glow = glowingPins.find(g => g.row === r && g.idx === i);
      
      ctx.beginPath();
      ctx.arc(px, y, 4.5, 0, Math.PI * 2);
      
      if (glow) {
        ctx.fillStyle = 'rgba(0, 240, 255, ' + glow.intensity + ')';
        ctx.strokeStyle = '#00f0ff';
        ctx.lineWidth = 2;
        ctx.shadowBlur = 10 * glow.intensity;
        ctx.shadowColor = '#00f0ff';
      } else {
        ctx.fillStyle = '#fff';
        ctx.strokeStyle = '#000';
        ctx.lineWidth = 2;
        ctx.shadowBlur = 0;
      }
      
      ctx.fill();
      ctx.stroke();
      ctx.shadowBlur = 0;
      
      // Draw tiny inner dot for professional vector graphic detail
      ctx.beginPath();
      ctx.arc(px, y, 1.2, 0, Math.PI * 2);
      ctx.fillStyle = glow ? '#fff' : '#111';
      ctx.fill();
    }
  }
}

// ── PLAY ANIMATION ENGINE ────────────────────────────────────
let isPlaying = false;
let ball = {
  x: 0,
  y: 0,
  targetFrames: [],
  currentFrameIdx: 0,
  color: 'var(--yellow)'
};

function generateFramesForPath(path, targetBucket) {
  let list = [];
  let currentX = BOARD_WIDTH / 2;
  let currentY = 15;
  
  const steps = 14;
  const r0_pins = 3;
  const r0_startX = BOARD_WIDTH / 2 - ((r0_pins - 1) / 2) * PEG_SPACING;
  const pin0X = r0_startX + 1 * PEG_SPACING;
  const pin0Y = START_Y;
  
  for (let f = 0; f <= steps; f++) {
    const t = f / steps;
    list.push({
      x: currentX + (pin0X - currentX) * t,
      y: currentY + (pin0Y - currentY) * t * t,
      hit: (f === steps) ? { row: 0, idx: 1 } : null
    });
  }
  
  currentX = pin0X;
  currentY = pin0Y;
  let index = 1;
  
  for (let r = 0; r < 8; r++) {
    const d = path[r];
    const nextIndex = index + d;
    let targetX, targetY;
    
    if (r < 7) {
      const nextPinsCount = r + 1 + 3;
      const nextStartX = BOARD_WIDTH / 2 - ((nextPinsCount - 1) / 2) * PEG_SPACING;
      targetX = nextStartX + nextIndex * PEG_SPACING;
      targetY = START_Y + (r + 1) * ROW_SPACING;
    } else {
      const bucketWidth = 32;
      const startBucketX = BOARD_WIDTH / 2 - (9 / 2) * bucketWidth;
      targetX = startBucketX + targetBucket * bucketWidth + bucketWidth / 2;
      targetY = 325;
    }
    
    const bounceX = currentX + (d === 1 ? 11 : -11);
    const bounceY = currentY + 5;
    
    const midX = (bounceX + targetX) / 2;
    const midY = (bounceY + targetY) / 2 - 8;
    
    const animSteps = 16;
    for (let f = 1; f <= animSteps; f++) {
      const t = f / animSteps;
      const x = (1 - t) * (1 - t) * bounceX + 2 * (1 - t) * t * midX + t * t * targetX;
      const y = (1 - t) * (1 - t) * bounceY + 2 * (1 - t) * t * midY + t * t * targetY;
      
      let hit = null;
      if (f === animSteps && r < 7) {
        hit = { row: r + 1, idx: nextIndex };
      }
      
      list.push({ x, y, hit });
    }
    
    currentX = targetX;
    currentY = targetY;
    index = nextIndex;
  }
  
  return list;
}

function animateGameLoop() {
  if (!isPlaying) return;
  
  drawBoard();
  updateGlows();
  
  const f = ball.targetFrames[ball.currentFrameIdx];
  if (f) {
    ball.x = f.x;
    ball.y = f.y;
    
    ctx.beginPath();
    ctx.arc(ball.x, ball.y, 8, 0, Math.PI * 2);
    ctx.fillStyle = '#00FF66';
    ctx.strokeStyle = '#000';
    ctx.lineWidth = 2.5;
    ctx.shadowBlur = 12;
    ctx.shadowColor = '#00FF66';
    ctx.fill();
    ctx.stroke();
    ctx.shadowBlur = 0;
    
    if (f.hit) {
      addGlow(f.hit.row, f.hit.idx);
      playBip();
    }
    
    ball.currentFrameIdx++;
    requestAnimationFrame(animateGameLoop);
  } else {
    isPlaying = false;
    document.getElementById('btn-drop').disabled = false;
    document.getElementById('btn-drop').innerText = '🟢 JATUHKAN BOLA 🟢';
    
    if (finalWinData) {
      const mult = finalWinData.multiplier;
      playWinChime(mult >= 1.5);
      
      nToast('🎯 Bola Mendarat! Multiplier ' + mult + 'x · Menang ' + finalWinData.reward_coins + ' Koin Plinko!', 'success', 5000);
      updateBalances(finalWinData);
      prependHistoryRow(finalWinData);
    }
  }
}

function updateBalances(data) {
  document.getElementById('disp-coins').innerText = '🪙 ' + data.new_coins.toLocaleString('id-ID');
  const controlCoins = document.getElementById('control-disp-coins');
  if (controlCoins) controlCoins.innerText = data.new_coins.toLocaleString('id-ID') + ' Koin';
  const topCoins = document.getElementById('user-coins');
  if (topCoins) topCoins.innerText = data.new_coins;
}

function prependHistoryRow(data) {
  const container = document.getElementById('history-container');
  const empty = document.getElementById('history-empty');
  if (empty) empty.style.display = 'none';
  
  const now = new Date();
  const timeStr = now.toLocaleDateString('id-ID', {day: 'numeric', month: 'short', year: 'numeric'}) + ' ' + 
                  String(now.getHours()).padStart(2, '0') + ':' + String(now.getMinutes()).padStart(2, '0');
  
  const row = document.createElement('div');
  row.className = 'list-item';
  row.style.cssText = 'padding:10px 14px; border-bottom:1px dashed #ddd; display:flex; justify-content:space-between; align-items:center; font-size:12px; animation: popIn 0.3s ease-out;';
  row.innerHTML = `
    <div style="display:flex; align-items:center; gap:8px;">
      <div style="width:28px; height:28px; border-radius:6px; border:1.5px solid var(--ink); background:var(--yellow); display:flex; align-items:center; justify-content:center; font-size:12px;">🎮</div>
      <div>
        <div style="font-weight:900;">Taruhan ${activeBet} Koin (${data.multiplier}x)</div>
        <div style="font-size:10px; color:#888; margin-top:2px;">${timeStr}</div>
      </div>
    </div>
    <div style="font-weight:900; color:var(--green); font-size:13px;">+${data.reward_coins.toLocaleString('id-ID')} Koin</div>
  `;
  
  if (container.firstChild && container.firstChild.className === 'list-item') {
    container.insertBefore(row, container.firstChild);
  } else {
    const wrapper = container.querySelector('div[style*="flex-direction:column"]');
    if (wrapper) {
      wrapper.insertBefore(row, wrapper.firstChild);
    } else {
      const wrap = document.createElement('div');
      wrap.style.cssText = 'display:flex; flex-direction:column;';
      wrap.appendChild(row);
      container.innerHTML = '';
      container.appendChild(wrap);
    }
  }
}

// ── PLAY PLINKO AJAX ACTION TRIGGER ──────────────────────────
let finalWinData = null;

function playPlinko() {
  if (isPlaying) return;
  
  const btn = document.getElementById('btn-drop');
  btn.disabled = true;
  btn.innerText = 'MEMANCAR BOLA...';
  
  initAudio();
  
  fetch('', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: 'action=play&bet=' + activeBet + '&_csrf=' + encodeURIComponent(_csrf)
  })
  .then(r => r.json())
  .then(res => {
    if (res.error) {
      btn.disabled = false;
      btn.innerText = '🟢 JATUHKAN BOLA 🟢';
      nToast(res.error, 'error');
    } else {
      finalWinData = res;
      
      // Get current coin balance from UI elements
      const topCoinsEl = document.getElementById('user-coins');
      let currentCoins = 0;
      if (topCoinsEl) {
        currentCoins = parseInt(topCoinsEl.innerText.replace(/[^0-9]/g, '')) || 0;
      } else {
        const controlCoinsEl = document.getElementById('control-disp-coins');
        if (controlCoinsEl) {
          currentCoins = parseInt(controlCoinsEl.innerText.replace(/[^0-9]/g, '')) || 0;
        }
      }
      
      const deductedCoins = Math.max(0, currentCoins - activeBet);
      
      // Update UI displays immediately to show deducted balance
      const dispCoinsEl = document.getElementById('disp-coins');
      if (dispCoinsEl) dispCoinsEl.innerText = '🪙 ' + deductedCoins.toLocaleString('id-ID');
      
      const controlCoinsEl = document.getElementById('control-disp-coins');
      if (controlCoinsEl) controlCoinsEl.innerText = deductedCoins.toLocaleString('id-ID') + ' Koin';
      
      if (topCoinsEl) topCoinsEl.innerText = deductedCoins;
      
      ball.targetFrames = generateFramesForPath(res.path, res.bucket);
      ball.currentFrameIdx = 0;
      isPlaying = true;
      animateGameLoop();
    }
  })
  .catch(err => {
    btn.disabled = false;
    btn.innerText = '🟢 JATUHKAN BOLA 🟢';
    nToast('Koneksi terputus. Coba lagi.', 'error');
  });
}

window.onload = function() {
  drawBoard();
};
</script>

<?php require dirname(__DIR__) . '/partials/footer.php'; ?>
