<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/auth/guard.php';

// Handle AJAX Play
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'play') {
    header('Content-Type: application/json');
    if (!csrf_verify()) {
        echo json_encode(['success' => false, 'message' => 'CSRF tidak valid.']);
        exit;
    }

    // Check if played today
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM minigame_logs WHERE user_id=? AND game_type='lucky_card' AND DATE(played_at)=CURDATE()");
    $stmt->execute([$user['id']]);
    if ((int)$stmt->fetchColumn() > 0) {
        echo json_encode(['success' => false, 'message' => 'Hari ini sudah tebak kartu! Besok lagi ya.']);
        exit;
    }

    // Prizes Definition (Small nominals)
    $prizes_pool = [
        ['val' => 0,     'weight' => 45],
        ['val' => 1000,  'weight' => 30],
        ['val' => 2500,  'weight' => 15],
        ['val' => 5000,  'weight' => 6],
        ['val' => 10000, 'weight' => 3],
        ['val' => 20000, 'weight' => 1],
    ];

    $totalWeight = array_sum(array_column($prizes_pool, 'weight'));
    $rand = random_int(1, $totalWeight);
    $prize_val = 0;
    $current = 0;
    foreach ($prizes_pool as $p) {
        $current += $p['weight'];
        if ($rand <= $current) {
            $prize_val = $p['val'];
            break;
        }
    }

    // Generate other 5 fake prizes for display
    $other_prizes = [];
    for ($i = 0; $i < 5; $i++) {
        $other_prizes[] = $prizes_pool[array_rand($prizes_pool)]['val'];
    }

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("INSERT INTO minigame_logs (user_id, game_type, score, reward) VALUES (?, 'lucky_card', 0, ?)");
        $stmt->execute([$user['id'], $prize_val]);

        if ($prize_val > 0) {
            $stmt = $pdo->prepare("UPDATE users SET balance_wd = balance_wd + ? WHERE id = ?");
            $stmt->execute([$prize_val, $user['id']]);
        }

        $pdo->commit();
        echo json_encode(['success' => true, 'prize' => $prize_val, 'others' => $other_prizes]);
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        echo json_encode(['success' => false, 'message' => 'SysErr: ' . $e->getMessage()]);
    }
    exit;
}

// Check if played today for UI
$stmt = $pdo->prepare("SELECT COUNT(*) FROM minigame_logs WHERE user_id=? AND game_type='lucky_card' AND DATE(played_at)=CURDATE()");
$stmt->execute([$user['id']]);
$played_today = (int)$stmt->fetchColumn();

$pageTitle = 'Lucky Card — Meloton';
$activePage = 'missions';
require dirname(__DIR__) . '/partials/header.php';
?>

<div class="section-header" style="margin-bottom:20px; background: #fff; padding: 14px 16px; border: 3px solid #c084fc; border-radius: 20px; box-shadow: 0 6px 0 #a855f7; display:flex; align-items:center; justify-content:space-between;">
  <div>
      <div class="section-title" style="display:flex;align-items:center;gap:8px;font-size:18px; color: #4c1d95; font-weight: 900;">
        <div style="background:#e9d5ff; width:36px; height:36px; border-radius:12px; display:flex; align-items:center; justify-content:center; color:#9333ea; font-size:20px;">
            <i class="ph-fill ph-cards"></i>
        </div>
        Lucky Card
      </div>
      <p style="font-size:12px;font-weight:700;color:#6b21a8;margin:6px 0 0">Pilih 1 kartu dan dapatkan kejutan receh!</p>
  </div>
  <a href="/missions" style="background:#e9d5ff; padding:8px; border-radius:12px; color:#9333ea;"><i class="ph-bold ph-x"></i></a>
</div>

<div class="game-container">
    <?php if ($played_today > 0): ?>
        <div class="played-state">
            <div class="played-icon">✨</div>
            <h3 style="font-size:18px;font-weight:900;color:#334155;margin:0 0 8px;">Sudah Main Hari Ini</h3>
            <p style="font-size:13px;font-weight:700;color:#64748b;margin:0;line-height:1.5;">Keberuntunganmu sudah diuji hari ini. Besok kembali lagi untuk tebak kartu yang baru!</p>
            <a href="/missions" class="btn-back">Kembali ke Misi</a>
        </div>
    <?php else: ?>
        <div class="cards-grid">
            <?php for($i = 0; $i < 6; $i++): ?>
            <div class="card-scene" onclick="flipCard(this, <?= $i ?>)">
                <div class="card-obj" id="card-<?= $i ?>">
                    <div class="card-face card-front">
                        <i class="ph-bold ph-question"></i>
                    </div>
                    <div class="card-face card-back">
                        <div class="prize-amt" id="prize-<?= $i ?>">Rp 0</div>
                    </div>
                </div>
            </div>
            <?php endfor; ?>
        </div>
        
        <div id="result-overlay" style="display:none;">
            <div class="result-box">
                <div id="reward-loading" style="display:block;">
                    <i class="ph-bold ph-spinner ph-spin" style="font-size:32px;color:#9333ea;"></i>
                    <p style="font-size:14px; margin-top:12px; font-weight:700; color:#555;">Memproses Hadiah...</p>
                </div>
                
                <div id="reward-success" style="display:none;">
                    <h2 id="result-title" style="color:#d97706; font-size:24px; font-weight:900; margin:0 0 8px;">SELAMAT!</h2>
                    <div style="background:#f5f3ff; border:2px dashed #8b5cf6; padding:16px; border-radius:16px; margin-bottom:16px;">
                        <p style="font-size:12px; color:#6d28d9; font-weight:700; margin:0 0 4px;">Kamu mendapatkan:</p>
                        <h1 style="font-size:28px; font-weight:900; color:#7c3aed; margin:0;"><span id="reward-amount">Rp 0</span></h1>
                    </div>
                    <button onclick="window.location.href='/missions'" class="btn-back" style="width:100%;">Mantap!</button>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<style>
.game-container {
    background: #fff; border: 3px solid #cbd5e1; border-radius: 24px;
    box-shadow: 0 8px 0 #cbd5e1; position: relative; overflow: hidden;
    min-height: 400px; padding: 20px; display: flex; flex-direction: column;
}

.played-state {
    padding: 40px 20px; text-align: center; flex: 1; display: flex;
    flex-direction: column; align-items: center; justify-content: center;
}
.played-icon { font-size: 64px; margin-bottom: 16px; animation: float 3s ease-in-out infinite; }

.cards-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 16px;
    flex: 1;
    align-content: center;
}
@media(max-width: 360px) {
    .cards-grid { grid-template-columns: repeat(2, 1fr); }
}

.card-scene {
    perspective: 600px;
    cursor: pointer;
    -webkit-tap-highlight-color: transparent;
}
.card-obj {
    width: 100%;
    aspect-ratio: 3/4;
    position: relative;
    transition: transform 0.6s cubic-bezier(0.34, 1.56, 0.64, 1);
    transform-style: preserve-3d;
}
.card-obj.is-flipped {
    transform: rotateY(180deg);
}

.card-face {
    position: absolute; width: 100%; height: 100%;
    -webkit-backface-visibility: hidden; backface-visibility: hidden;
    border-radius: 16px; display: flex; align-items: center; justify-content: center;
    box-shadow: 0 4px 6px rgba(0,0,0,0.1); border: 3px solid #fff;
}
.card-front {
    background: linear-gradient(135deg, #c084fc, #9333ea);
    border-color: #d8b4fe;
    box-shadow: 0 6px 0 #7e22ce;
}
.card-front i {
    font-size: 48px; color: #fff; text-shadow: 0 4px 0 #6b21a8;
}

.card-back {
    background: #fef08a;
    border-color: #fde047;
    box-shadow: 0 6px 0 #eab308;
    transform: rotateY(180deg);
    flex-direction: column;
}
.card-back.zonk {
    background: #cbd5e1; border-color: #e2e8f0; box-shadow: 0 6px 0 #94a3b8;
}
.card-back.zonk .prize-amt { color: #64748b; }

.prize-amt {
    font-size: 18px; font-weight: 900; color: #b45309; text-shadow: 0 1px 0 #fde047;
}

#result-overlay {
    position: absolute; inset: 0; background: rgba(15, 23, 42, 0.8);
    backdrop-filter: blur(8px); z-index: 30; display: flex;
    align-items: center; justify-content: center; padding: 20px;
}
.result-box {
    background: #fff; width: 100%; max-width: 300px; border-radius: 24px;
    padding: 24px; text-align: center; border: 4px solid #c084fc;
    box-shadow: 0 8px 0 #9333ea; animation: popIn 0.5s cubic-bezier(0.34, 1.56, 0.64, 1);
}

.btn-back {
    display: inline-block; background: #8b5cf6; color: #fff; font-weight: 800; font-size: 14px;
    padding: 12px 24px; border-radius: 100px; text-decoration: none; border: 2px solid #fff;
    box-shadow: 0 4px 0 #7c3aed; margin-top: 16px; transition: transform 0.1s; cursor:pointer;
}
.btn-back:active { transform: translateY(4px); box-shadow: 0 0 0 #7c3aed; }

@keyframes float { 0%, 100% { transform: translateY(0); } 50% { transform: translateY(-10px); } }
@keyframes popIn { 0% { transform: scale(0); opacity: 0; } 100% { transform: scale(1); opacity: 1; } }
</style>

<script>
const _csrf = '<?= csrf_token() ?>';
let isPlaying = false;

async function flipCard(el, idx) {
    if (isPlaying) return;
    isPlaying = true;
    
    // Animate click squish
    el.style.transform = 'scale(0.9)';
    setTimeout(() => {
        el.style.transform = 'none';
    }, 150);

    const overlay = document.getElementById('result-overlay');
    
    try {
        const formData = new FormData();
        formData.append('action', 'play');
        formData.append('_csrf', _csrf);
        
        const res = await fetch('', { method: 'POST', body: formData });
        const data = await res.json();
        
        if (!data.success) {
            alert(data.message);
            window.location.reload();
            return;
        }
        
        const prize = data.prize;
        const others = data.others;
        
        // Flip selected card
        const cardObj = document.getElementById('card-' + idx);
        const prizeEl = document.getElementById('prize-' + idx);
        
        prizeEl.innerText = prize > 0 ? 'Rp ' + prize : 'Zonk';
        if (prize === 0) cardObj.querySelector('.card-back').classList.add('zonk');
        
        cardObj.classList.add('is-flipped');
        
        // 1 second later, flip others
        setTimeout(() => {
            let otherIdx = 0;
            for(let i=0; i<6; i++) {
                if (i !== idx) {
                    const cObj = document.getElementById('card-' + i);
                    const pEl = document.getElementById('prize-' + i);
                    const pVal = others[otherIdx++];
                    pEl.innerText = pVal > 0 ? 'Rp ' + pVal : 'Zonk';
                    if (pVal === 0) cObj.querySelector('.card-back').classList.add('zonk');
                    cObj.classList.add('is-flipped');
                }
            }
            
            // Show result modal
            setTimeout(() => {
                overlay.style.display = 'flex';
                document.getElementById('reward-loading').style.display = 'none';
                
                const title = document.getElementById('result-title');
                if (prize === 0) {
                    title.innerText = 'YAHH ZONK!';
                    title.style.color = '#64748b';
                } else {
                    title.innerText = 'SELAMAT!';
                    title.style.color = '#d97706';
                }
                
                document.getElementById('reward-amount').innerText = prize > 0 ? 'Rp ' + prize : 'Zonk';
                document.getElementById('reward-success').style.display = 'block';
                
            }, 1000);
            
        }, 800);
        
    } catch (err) {
        alert("Terjadi kesalahan jaringan.");
        isPlaying = false;
    }
}
</script>

<?php require dirname(__DIR__) . '/partials/footer.php'; ?>
