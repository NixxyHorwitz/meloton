<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/auth/guard.php';

// Handle AJAX POST on Game Over
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'gameover') {
    header('Content-Type: application/json');
    if (!csrf_verify()) {
        echo json_encode(['success' => false, 'message' => 'CSRF tidak valid.']);
        exit;
    }

    $score = (int)($_POST['score'] ?? 0);
    if ($score < 0) $score = 0;
    if ($score > 100) $score = 100; // Cap at 100 jumps

    $reward = $score * 50; // Rp 50 per successful jump

    // Check if played today
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM minigame_logs WHERE user_id=? AND game_type='frog_jump' AND DATE(played_at)=CURDATE()");
    $stmt->execute([$user['id']]);
    if ((int)$stmt->fetchColumn() > 0) {
        echo json_encode(['success' => false, 'message' => 'Hari ini sudah main Lompat Katak! Besok lagi ya.']);
        exit;
    }

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("INSERT INTO minigame_logs (user_id, game_type, score, reward) VALUES (?, 'frog_jump', ?, ?)");
        $stmt->execute([$user['id'], $score, $reward]);

        if ($reward > 0) {
            $stmt = $pdo->prepare("UPDATE users SET balance_wd = balance_wd + ? WHERE id = ?");
            $stmt->execute([$reward, $user['id']]);
        }

        $pdo->commit();
        echo json_encode(['success' => true, 'reward' => $reward, 'score' => $score]);
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'SysErr']);
    }
    exit;
}

// Check played today for UI
$stmt = $pdo->prepare("SELECT COUNT(*) FROM minigame_logs WHERE user_id=? AND game_type='frog_jump' AND DATE(played_at)=CURDATE()");
$stmt->execute([$user['id']]);
$played_today = (int)$stmt->fetchColumn();

$pageTitle = 'Lompat Katak — Meloton';
$activePage = 'home';
require dirname(__DIR__) . '/partials/header.php';
?>

<div class="section-header" style="margin-bottom:16px; background: #fff; padding: 14px 16px; border: 3px solid #0284c7; border-radius: 20px; box-shadow: 0 6px 0 #0369a1; display:flex; align-items:center; justify-content:space-between;">
  <div>
      <div class="section-title" style="display:flex;align-items:center;gap:8px;font-size:18px; color: #0c4a6e; font-weight: 900;">
        <div style="background:#e0f2fe; width:36px; height:36px; border-radius:12px; display:flex; align-items:center; justify-content:center; color:#0284c7; font-size:20px;">
            🐸
        </div>
        Lompat Katak
      </div>
      <p style="font-size:12px;font-weight:700;color:#0369a1;margin:6px 0 0">Setiap 1 pijakan bernilai Rp 50. Max Rp 5.000!</p>
  </div>
  <a href="/home" style="background:#e0f2fe; padding:8px; border-radius:12px; color:#0284c7;"><i class="ph-bold ph-x"></i></a>
</div>

<div class="game-wrapper">
    <?php if ($played_today > 0): ?>
        <div class="played-state">
            <div class="played-icon">✨🐸✨</div>
            <h3 style="font-size:18px;font-weight:900;color:#334155;margin:0 0 8px;">Katak Sedang Istirahat</h3>
            <p style="font-size:13px;font-weight:700;color:#64748b;margin:0;line-height:1.5;">Kamu sudah memainkan game ini hari ini. Besok datang lagi ya untuk kumpulkan Saldo ekstra!</p>
            <a href="/home" class="btn-back">Kembali ke Beranda</a>
        </div>
    <?php else: ?>
        <!-- Game HUD -->
        <div id="game-hud">
            <div class="score-board">Skor: <span id="score-val">0</span></div>
            <div class="tutor-text" id="tutor-text">Tap layar untuk MELOMPAT!</div>
        </div>

        <canvas id="gameCanvas" width="360" height="480"></canvas>
        
        <div id="result-overlay" style="display:none;">
            <div class="result-box">
                <div id="reward-loading" style="display:block;">
                    <i class="ph-bold ph-spinner ph-spin" style="font-size:32px;color:#0284c7;"></i>
                    <p style="font-size:14px; margin-top:12px; font-weight:700; color:#555;">Menyimpan Skor...</p>
                </div>
                
                <div id="reward-success" style="display:none;">
                    <h2 style="color:#d97706; font-size:24px; font-weight:900; margin:0 0 8px;">GAME OVER!</h2>
                    <p style="font-size:12px; color:#64748b; font-weight:700; margin:0 0 12px;">Total Pijakan: <span id="res-score" style="color:#0f172a;font-size:14px;">0</span></p>
                    <div style="background:#f0f9ff; border:2px dashed #38bdf8; padding:16px; border-radius:16px; margin-bottom:16px;">
                        <p style="font-size:12px; color:#0284c7; font-weight:700; margin:0 0 4px;">Hadiah Saldo Diterima:</p>
                        <h1 style="font-size:28px; font-weight:900; color:#0369a1; margin:0;">Rp <span id="res-reward">0</span></h1>
                    </div>
                    <button onclick="window.location.href='/home'" class="btn-back" style="width:100%; background:#0284c7; box-shadow:0 4px 0 #0369a1; border-color:#fff;">Mantap!</button>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<style>
.game-wrapper {
    background: linear-gradient(180deg, #38bdf8, #0284c7);
    border: 3px solid #cbd5e1; border-radius: 24px;
    box-shadow: 0 8px 0 #cbd5e1; position: relative; overflow: hidden;
    min-height: 480px; display: flex; flex-direction: column;
}
.played-state {
    padding: 40px 20px; text-align: center; flex: 1; display: flex;
    flex-direction: column; align-items: center; justify-content: center;
    background: #fff;
}
.played-icon { font-size: 64px; margin-bottom: 16px; animation: float 3s ease-in-out infinite; }

#gameCanvas {
    width: 100%; height: 480px; display: block;
    cursor: pointer; -webkit-tap-highlight-color: transparent;
}

#game-hud {
    position: absolute; top: 16px; left: 16px; right: 16px;
    display: flex; justify-content: space-between; align-items: flex-start;
    pointer-events: none; z-index: 10;
}
.score-board {
    background: rgba(255,255,255,0.9); padding: 8px 16px; border-radius: 20px;
    font-size: 16px; font-weight: 900; color: #0284c7;
    border: 2px solid #bae6fd; box-shadow: 0 4px 0 rgba(2,132,199,0.3);
}
.tutor-text {
    background: rgba(0,0,0,0.5); color: #fff; padding: 6px 12px; border-radius: 12px;
    font-size: 11px; font-weight: 700; text-align: center; margin-top: 4px;
    animation: pulse 1.5s infinite;
}

#result-overlay {
    position: absolute; inset: 0; background: rgba(15, 23, 42, 0.8);
    backdrop-filter: blur(8px); z-index: 30; display: flex;
    align-items: center; justify-content: center; padding: 20px;
}
.result-box {
    background: #fff; width: 100%; max-width: 300px; border-radius: 24px;
    padding: 24px; text-align: center; border: 4px solid #0284c7;
    box-shadow: 0 8px 0 #0369a1; animation: popIn 0.5s cubic-bezier(0.34, 1.56, 0.64, 1);
}

.btn-back {
    display: inline-block; background: #8b5cf6; color: #fff; font-weight: 800; font-size: 14px;
    padding: 12px 24px; border-radius: 100px; text-decoration: none; border: 2px solid #fff;
    box-shadow: 0 4px 0 #7c3aed; margin-top: 16px; transition: transform 0.1s; cursor:pointer;
}
.btn-back:active { transform: translateY(4px); box-shadow: 0 0 0 transparent; }

@keyframes float { 0%, 100% { transform: translateY(0); } 50% { transform: translateY(-10px); } }
@keyframes popIn { 0% { transform: scale(0); opacity: 0; } 100% { transform: scale(1); opacity: 1; } }
@keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.5; } }
</style>

<script>
<?php if ($played_today === 0): ?>
const canvas = document.getElementById('gameCanvas');
const ctx = canvas.getContext('2d');
const scoreEl = document.getElementById('score-val');
const tutorEl = document.getElementById('tutor-text');
const _csrf = '<?= csrf_token() ?>';

// Assets
const imgFrog = new Image(); imgFrog.src = '/assets/img/frogjump/frog.png';
const imgPad = new Image(); imgPad.src = '/assets/img/frogjump/pad.png';

let w = canvas.width;
let h = canvas.height;
let score = 0;
let gameOver = false;
let isJumping = false;
let jumpProgress = 0;

// Entities
let frog = { x: w/2, y: 350, size: 60 };
let currentPad = { x: w/2, y: 360, size: 80 };
let nextPad = { x: w/2, y: 150, size: 80, speed: 2, dir: 1 };
let particles = [];

// Game Loop
let lastTime = performance.now();
function loop(time) {
    let dt = (time - lastTime) / 1000;
    lastTime = time;
    update(dt);
    draw();
    if (!gameOver) requestAnimationFrame(loop);
}

function update(dt) {
    if (gameOver) return;

    // Move next pad
    nextPad.x += nextPad.speed * nextPad.dir * dt * 60;
    if (nextPad.x < 40) { nextPad.x = 40; nextPad.dir = 1; }
    if (nextPad.x > w - 40) { nextPad.x = w - 40; nextPad.dir = -1; }

    // Jumping animation logic
    if (isJumping) {
        jumpProgress += dt * 2.5; // Jump duration ~0.4s
        
        // Arc interpolation
        let t = jumpProgress;
        if (t > 1) t = 1;
        
        // Parabolic arc for Y
        let jumpHeight = 60;
        let arcY = -4 * jumpHeight * (t * t - t);
        
        // Move frog from currentPad to wherever nextPad was WHEN WE JUMPED?
        // No, frog just jumps straight towards nextPad's Y.
        // Actually, we want the frog to land on the pad's X at that exact moment.
        frog.x = lerp(currentPad.x, nextPad.x, t);
        frog.y = lerp(currentPad.y, nextPad.y, t) + arcY;

        if (t === 1) {
            isJumping = false;
            checkLanding();
        }
    }
    
    // Update particles (splashes)
    particles.forEach(p => {
        p.x += p.vx; p.y += p.vy; p.life -= dt;
    });
    particles = particles.filter(p => p.life > 0);
}

function checkLanding() {
    // Check distance between frog and nextPad
    let dx = frog.x - nextPad.x;
    let dy = frog.y - nextPad.y;
    let dist = Math.sqrt(dx*dx + dy*dy);

    if (dist < 40) {
        // Success
        score++;
        scoreEl.innerText = score;
        if(score === 1) tutorEl.style.display = 'none';

        // Shift world
        currentPad = { x: nextPad.x, y: 360, size: 80 };
        frog.x = currentPad.x;
        frog.y = currentPad.y - 10;
        
        // Generate new next pad
        nextPad = { 
            x: Math.random() > 0.5 ? 40 : w - 40, 
            y: 150, 
            size: 80, 
            speed: 2 + (score * 0.2), 
            dir: Math.random() > 0.5 ? 1 : -1 
        };
        
        createSplash(frog.x, frog.y + 20, '#bae6fd');
    } else {
        // Fail
        gameOver = true;
        createSplash(frog.x, frog.y, '#ffffff'); // Big splash
        setTimeout(sendScore, 1000);
    }
}

function lerp(a, b, t) { return a + (b - a) * t; }

function createSplash(x, y, color) {
    for(let i=0; i<10; i++) {
        particles.push({
            x: x, y: y,
            vx: (Math.random() - 0.5) * 4,
            vy: (Math.random() - 0.5) * 4,
            life: 0.5 + Math.random() * 0.5,
            color: color
        });
    }
}

function draw() {
    ctx.clearRect(0, 0, w, h);

    // Draw Pads
    drawImgCentered(imgPad, currentPad.x, currentPad.y, currentPad.size, currentPad.size);
    drawImgCentered(imgPad, nextPad.x, nextPad.y, nextPad.size, nextPad.size);

    // Draw Particles
    particles.forEach(p => {
        ctx.fillStyle = p.color;
        ctx.globalAlpha = p.life;
        ctx.beginPath();
        ctx.arc(p.x, p.y, 4, 0, Math.PI*2);
        ctx.fill();
    });
    ctx.globalAlpha = 1;

    // Draw Frog
    if (!gameOver || isJumping) {
        let frogSize = frog.size;
        if(isJumping) frogSize += Math.sin(jumpProgress * Math.PI) * 15; // Scale up mid-air
        drawImgCentered(imgFrog, frog.x, frog.y, frogSize, frogSize);
    }
}

function drawImgCentered(img, x, y, width, height) {
    if(!img.complete) return;
    ctx.drawImage(img, x - width/2, y - height/2, width, height);
}

// Controls
canvas.addEventListener('mousedown', jump);
canvas.addEventListener('touchstart', jump);

function jump(e) {
    e.preventDefault();
    if (gameOver || isJumping) return;
    isJumping = true;
    jumpProgress = 0;
}

async function sendScore() {
    document.getElementById('result-overlay').style.display = 'flex';
    
    try {
        const formData = new FormData();
        formData.append('action', 'gameover');
        formData.append('score', score);
        formData.append('_csrf', _csrf);
        
        const res = await fetch('', { method: 'POST', body: formData });
        const data = await res.json();
        
        if (!data.success) {
            alert(data.message);
            window.location.href = '/home';
            return;
        }
        
        document.getElementById('reward-loading').style.display = 'none';
        document.getElementById('res-score').innerText = data.score;
        document.getElementById('res-reward').innerText = data.reward;
        document.getElementById('reward-success').style.display = 'block';
        
    } catch (err) {
        alert("Terjadi kesalahan jaringan.");
        window.location.href = '/home';
    }
}

// Start game when images load
Promise.all([
    new Promise(r => imgFrog.onload = r),
    new Promise(r => imgPad.onload = r)
]).then(() => {
    requestAnimationFrame(loop);
});
<?php endif; ?>
</script>

<?php require dirname(__DIR__) . '/partials/footer.php'; ?>
