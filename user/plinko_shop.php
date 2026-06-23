<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/auth/guard.php';

// Guard: plinko enabled?
$plinko_enabled = setting($pdo, 'plinko_enabled', '1') === '1';
if (!$plinko_enabled) {
    $_SESSION['flash_home_err'] = '⚠️ Mini Game Plinko sedang dinonaktifkan oleh Administrator.';
    redirect('/home');
}

$plinko_buy_rate  = (float)setting($pdo, 'plinko_buy_rate',  '100.0');
$plinko_sell_rate = (float)setting($pdo, 'plinko_sell_rate', '100.0');

// ── AJAX POST HANDLERS ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // 1. CLAIM DAILY
    if ($action === 'claim_daily') {
        header('Content-Type: application/json');
        csrf_verify() or die(json_encode(['error' => 'Invalid CSRF token.']));
        $today = date('Y-m-d');
        if ($user['last_plinko_claim'] === $today) {
            echo json_encode(['error' => 'Kamu sudah mengklaim koin gratis hari ini. Kembali lagi besok!']);
            exit;
        }
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("UPDATE users SET plinko_coins = plinko_coins + 50, last_plinko_claim = CURDATE() WHERE id = ? AND (last_plinko_claim IS NULL OR last_plinko_claim < CURDATE())");
            $stmt->execute([$user['id']]);
            if ($stmt->rowCount() > 0) {
                $pdo->commit();
                $nc = (int)$pdo->query("SELECT plinko_coins FROM users WHERE id = {$user['id']}")->fetchColumn();
                echo json_encode(['ok' => true, 'new_coins' => $nc, 'message' => '🎉 Sukses mengklaim 50 Koin Plinko gratis!']);
            } else {
                $pdo->rollBack();
                echo json_encode(['error' => 'Gagal mengklaim. Sudah diklaim hari ini.']);
            }
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            echo json_encode(['error' => 'Kesalahan sistem: ' . $e->getMessage()]);
        }
        exit;
    }

    // 2. BUY COINS
    if ($action === 'buy_coins') {
        header('Content-Type: application/json');
        csrf_verify() or die(json_encode(['error' => 'Invalid CSRF token.']));
        $qty = (int)($_POST['qty'] ?? 0);
        if ($qty < 10) { echo json_encode(['error' => 'Minimal pembelian adalah 10 koin.']); exit; }
        $cost = $qty * $plinko_buy_rate;
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("SELECT balance_dep FROM users WHERE id = ? FOR UPDATE");
            $stmt->execute([$user['id']]);
            $dep = (float)$stmt->fetchColumn();
            if ($dep < $cost) {
                $pdo->rollBack();
                echo json_encode(['error' => 'Saldo beli tidak mencukupi. Butuh ' . format_rp($cost) . '.']);
                exit;
            }
            $pdo->prepare("UPDATE users SET balance_dep = balance_dep - ?, plinko_coins = plinko_coins + ? WHERE id = ?")->execute([$cost, $qty, $user['id']]);
            $pdo->commit();
            $fresh = $pdo->query("SELECT plinko_coins, balance_dep FROM users WHERE id = {$user['id']}")->fetch();
            echo json_encode(['ok' => true, 'new_coins' => (int)$fresh['plinko_coins'], 'new_balance_dep' => format_rp((float)$fresh['balance_dep']), 'message' => '✓ Berhasil beli ' . $qty . ' Koin seharga ' . format_rp($cost) . '!']);
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            echo json_encode(['error' => 'Gagal: ' . $e->getMessage()]);
        }
        exit;
    }

    // 3. SELL COINS
    if ($action === 'sell_coins') {
        header('Content-Type: application/json');
        csrf_verify() or die(json_encode(['error' => 'Invalid CSRF token.']));
        $qty = (int)($_POST['qty'] ?? 0);
        if ($qty < 1) { echo json_encode(['error' => 'Jumlah koin tidak valid.']); exit; }
        $earnings = $qty * $plinko_sell_rate;
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("SELECT plinko_coins FROM users WHERE id = ? FOR UPDATE");
            $stmt->execute([$user['id']]);
            $coins = (int)$stmt->fetchColumn();
            if ($coins < $qty) {
                $pdo->rollBack();
                echo json_encode(['error' => 'Koin tidak mencukupi. Kamu punya ' . $coins . ' koin.']);
                exit;
            }
            $pdo->prepare("UPDATE users SET plinko_coins = plinko_coins - ?, balance_wd = balance_wd + ?, total_earned = total_earned + ? WHERE id = ?")->execute([$qty, $earnings, $earnings, $user['id']]);
            $pdo->commit();
            $fresh = $pdo->query("SELECT plinko_coins, balance_wd FROM users WHERE id = {$user['id']}")->fetch();
            echo json_encode(['ok' => true, 'new_coins' => (int)$fresh['plinko_coins'], 'new_balance_wd' => format_rp((float)$fresh['balance_wd']), 'message' => '✓ Berhasil jual ' . $qty . ' Koin = ' . format_rp($earnings) . ' ke Saldo Penarikan!']);
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            echo json_encode(['error' => 'Gagal: ' . $e->getMessage()]);
        }
        exit;
    }
}

$pageTitle  = 'Lapak Koin Plinko — NontonKuy';
$activePage = 'plinko-shop';
require dirname(__DIR__) . '/partials/header.php';

$today          = date('Y-m-d');
$already_claimed = $user['last_plinko_claim'] === $today;
?>

<!-- Header Bar -->
<div style="display:flex;align-items:center;justify-content:space-between;background:var(--brand);border:3px solid var(--ink);border-radius:12px;box-shadow:4px 4px 0 var(--ink);padding:12px 16px;margin-bottom:14px;color:#fff;">
  <div>
    <div style="font-weight:900;font-size:16px;">🛒 Lapak Koin Plinko</div>
    <div style="font-size:10px;opacity:.85;margin-top:2px;">Beli · Jual · Klaim Gratis Harian</div>
  </div>
  <a href="/plinko" style="display:flex;align-items:center;gap:5px;background:var(--yellow);color:var(--ink);border:2px solid var(--ink);border-radius:8px;box-shadow:2px 2px 0 var(--ink);padding:7px 12px;font-weight:900;font-size:11px;text-decoration:none;white-space:nowrap;transition:transform .1s,box-shadow .1s;"
     onmouseover="this.style.transform='translate(-1px,-1px)';this.style.boxShadow='3px 3px 0 var(--ink)'"
     onmouseout="this.style.transform='none';this.style.boxShadow='2px 2px 0 var(--ink)'">
    🎮 Main Plinko
  </a>
</div>

<!-- Balance Row -->
<div style="display:flex;gap:6px;margin-bottom:14px;">
  <div style="flex:1;background:var(--yellow);border:2px solid var(--ink);border-radius:8px;box-shadow:2.5px 2.5px 0 var(--ink);padding:8px 10px;text-align:center;">
    <div style="font-size:14px;font-weight:900;" id="disp-coins">🪙 <?= number_format((int)$user['plinko_coins']) ?></div>
    <div style="font-size:9px;font-weight:800;color:#555;">KOIN</div>
  </div>
  <div style="flex:1;background:#fff;border:2px solid var(--ink);border-radius:8px;box-shadow:2.5px 2.5px 0 var(--ink);padding:8px 10px;text-align:center;">
    <div style="font-size:11px;font-weight:800;" id="disp-dep"><?= format_rp((float)$user['balance_dep']) ?></div>
    <div style="font-size:9px;font-weight:700;color:#888;">DEPOSIT</div>
  </div>
  <div style="flex:1;background:var(--mint);border:2px solid var(--ink);border-radius:8px;box-shadow:2.5px 2.5px 0 var(--ink);padding:8px 10px;text-align:center;">
    <div style="font-size:11px;font-weight:800;" id="disp-wd"><?= format_rp((float)$user['balance_wd']) ?></div>
    <div style="font-size:9px;font-weight:800;">SALDO PENARIKAN</div>
  </div>
</div>

<!-- Daily Claim Strip -->
<div style="display:flex;align-items:center;justify-content:space-between;gap:10px;background:var(--lavender);border:2.5px solid var(--ink);border-radius:10px;box-shadow:3px 3px 0 var(--ink);padding:10px 14px;margin-bottom:14px;">
  <div>
    <div style="font-weight:900;font-size:13px;">🎁 Koin Gratis Harian</div>
    <div style="font-size:10px;color:#555;margin-top:2px;"><?= $already_claimed ? '✅ Sudah diklaim hari ini.' : 'Klaim 50 koin gratis setiap hari!' ?></div>
  </div>
  <form id="form-claim-daily" onsubmit="claimDaily(event)" style="flex-shrink:0;">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="claim_daily">
    <button type="submit" id="btn-claim-daily" <?= $already_claimed ? 'disabled' : '' ?> style="background:<?= $already_claimed ? '#ccc' : 'var(--brand)' ?>;color:<?= $already_claimed ? '#888' : '#fff' ?>;border:2px solid var(--ink);border-radius:8px;box-shadow:2px 2px 0 var(--ink);font-weight:900;font-size:11px;padding:8px 14px;cursor:<?= $already_claimed ? 'not-allowed' : 'pointer' ?>;white-space:nowrap;"><?= $already_claimed ? '✅ Diklaim' : '🎁 Klaim 50 Koin' ?></button>
  </form>
</div>

<!-- Combined Lapak Card -->
<div style="background:#fff;border:3px solid var(--ink);border-radius:12px;box-shadow:4px 4px 0 var(--ink);overflow:hidden;margin-bottom:16px;">

  <!-- Tab Toggle -->
  <div style="display:flex;border-bottom:2.5px solid var(--ink);">
    <button type="button" id="tab-beli-btn" onclick="switchTab('beli')" style="flex:1;padding:11px 8px;font-size:12px;font-weight:900;border:none;border-right:2px solid var(--ink);background:var(--sky);color:var(--ink);cursor:pointer;">🪙 BELI KOIN</button>
    <button type="button" id="tab-jual-btn" onclick="switchTab('jual')" style="flex:1;padding:11px 8px;font-size:12px;font-weight:900;border:none;background:#eee;color:#999;cursor:pointer;">💰 JUAL KOIN</button>
  </div>

  <!-- Panel: BELI -->
  <div id="panel-beli" style="padding:14px;">
    <div style="font-size:11px;color:#666;margin-bottom:10px;">
      Rate: <strong style="color:var(--brand);">1 Koin = Rp <?= number_format($plinko_buy_rate, 0, ',', '.') ?></strong> &nbsp;·&nbsp; Min. 10 koin
    </div>
    <div style="display:flex;gap:6px;margin-bottom:10px;">
      <?php foreach ([50, 100, 250, 500] as $q): $p = $q * $plinko_buy_rate; ?>
        <button type="button" onclick="setBuyQty(<?= $q ?>)" class="lapak-chip">
          <?= $q ?><br><span style="color:#777;font-size:9px;">Rp<?= number_format($p,0,',','.') ?></span>
        </button>
      <?php endforeach; ?>
    </div>
    <form id="form-buy-coins" onsubmit="buyCoins(event)" novalidate>
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="buy_coins">
      <input type="number" name="qty" id="buy-qty" placeholder="Jumlah koin..." min="10" step="1" required class="lapak-input">
      <div id="buy-summary-box" class="lapak-summary lapak-summary--buy">
        <div class="lapak-summary__label">💳 Total Dibayar</div>
        <div id="buy-summary-val" class="lapak-summary__val"></div>
        <div id="buy-summary-sub" class="lapak-summary__sub"></div>
      </div>
      <button type="submit" id="btn-buy" class="lapak-btn lapak-btn--buy">💳 Beli Koin</button>
    </form>
  </div>

  <!-- Panel: JUAL -->
  <div id="panel-jual" style="display:none;padding:14px;">
    <div style="font-size:11px;color:#666;margin-bottom:10px;">
      Rate: <strong style="color:var(--green);">1 Koin = Rp <?= number_format($plinko_sell_rate, 0, ',', '.') ?></strong> &nbsp;·&nbsp; Masuk Saldo Penarikan
    </div>
    <div style="display:flex;gap:6px;margin-bottom:10px;">
      <?php foreach ([50, 100, 250, 500] as $q): $e = $q * $plinko_sell_rate; ?>
        <button type="button" onclick="setSellQty(<?= $q ?>)" class="lapak-chip">
          <?= $q ?><br><span style="color:#777;font-size:9px;">→<?= number_format($e,0,',','.') ?></span>
        </button>
      <?php endforeach; ?>
    </div>
    <form id="form-sell-coins" onsubmit="sellCoins(event)" novalidate>
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="sell_coins">
      <input type="number" name="qty" id="sell-qty" placeholder="Jumlah koin dijual..." min="1" required class="lapak-input">
      <div id="sell-summary-box" class="lapak-summary lapak-summary--sell">
        <div class="lapak-summary__label">💰 Total Hasil Jual</div>
        <div id="sell-summary-val" class="lapak-summary__val"></div>
        <div id="sell-summary-sub" class="lapak-summary__sub"></div>
      </div>
      <button type="submit" id="btn-sell" class="lapak-btn lapak-btn--sell">💰 Jual Koin</button>
    </form>
  </div>

</div>

<style>
.lapak-chip{flex:1;padding:6px 4px;font-size:10px;font-weight:800;border:2px solid var(--ink);border-radius:7px;background:#fafafa;box-shadow:2px 2px 0 var(--ink);cursor:pointer;text-align:center;line-height:1.4;transition:transform .1s,background .1s;}
.lapak-chip:hover{background:var(--yellow);transform:translate(-1px,-1px);}
.lapak-chip:active{transform:translate(1px,1px);}
.lapak-input{width:100%;box-sizing:border-box;padding:10px 12px;margin-bottom:10px;border:2.5px solid var(--ink);border-radius:8px;font-size:13px;font-weight:700;box-shadow:2px 2px 0 var(--ink);outline:none;display:block;}
.lapak-input:focus{border-color:var(--brand);box-shadow:3px 3px 0 var(--ink);}
.lapak-summary{display:none;flex-direction:column;align-items:center;border:2px solid var(--ink);border-radius:8px;box-shadow:2px 2px 0 var(--ink);padding:8px 12px;margin-bottom:10px;text-align:center;}
.lapak-summary--buy{background:var(--yellow);}
.lapak-summary--sell{background:var(--mint);}
.lapak-summary__label{font-size:9px;font-weight:800;color:#555;letter-spacing:1px;text-transform:uppercase;}
.lapak-summary__val{font-size:22px;font-weight:900;color:var(--ink);line-height:1.2;}
.lapak-summary__sub{font-size:10px;color:#666;margin-top:2px;}
.lapak-btn{width:100%;padding:11px;font-size:13px;font-weight:900;border:2.5px solid var(--ink);border-radius:9px;box-shadow:3px 3px 0 var(--ink);cursor:pointer;text-transform:uppercase;letter-spacing:1px;transition:transform .1s,box-shadow .1s;}
.lapak-btn:hover:not(:disabled){transform:translate(-1px,-1px);box-shadow:4px 4px 0 var(--ink);}
.lapak-btn:active:not(:disabled){transform:translate(1px,1px);box-shadow:1px 1px 0 var(--ink);}
.lapak-btn--buy{background:var(--brand);color:#fff;}
.lapak-btn--sell{background:var(--mint);color:var(--ink);}
#btn-claim-daily{transition:transform .1s,box-shadow .1s;}
#btn-claim-daily:hover:not(:disabled){transform:translate(-1px,-1px);box-shadow:3px 3px 0 var(--ink);}
#btn-claim-daily:active:not(:disabled){transform:translate(1px,1px);}
</style>

<script>
const _csrf    = "<?= csrf_token() ?>";
const BUY_RATE = <?= (float)$plinko_buy_rate ?>;
const SELL_RATE= <?= (float)$plinko_sell_rate ?>;

function switchTab(tab) {
  const pB=document.getElementById('panel-beli'), pJ=document.getElementById('panel-jual');
  const bB=document.getElementById('tab-beli-btn'), bJ=document.getElementById('tab-jual-btn');
  if (tab==='beli') {
    pB.style.display=''; pJ.style.display='none';
    bB.style.cssText='flex:1;padding:11px 8px;font-size:12px;font-weight:900;border:none;border-right:2px solid var(--ink);background:var(--sky);color:var(--ink);cursor:pointer;';
    bJ.style.cssText='flex:1;padding:11px 8px;font-size:12px;font-weight:900;border:none;background:#eee;color:#999;cursor:pointer;';
  } else {
    pB.style.display='none'; pJ.style.display='';
    bJ.style.cssText='flex:1;padding:11px 8px;font-size:12px;font-weight:900;border:none;background:var(--mint);color:var(--ink);cursor:pointer;';
    bB.style.cssText='flex:1;padding:11px 8px;font-size:12px;font-weight:900;border:none;border-right:2px solid var(--ink);background:#eee;color:#999;cursor:pointer;';
  }
}

let audioCtx=null;
function playWinChime(){
  try{
    if(!audioCtx) audioCtx=new(window.AudioContext||window.webkitAudioContext)();
    const now=audioCtx.currentTime;
    [523.25,659.25,783.99,1046.5].forEach((f,i)=>{
      const o=audioCtx.createOscillator(),g=audioCtx.createGain();
      o.connect(g);g.connect(audioCtx.destination);
      o.frequency.setValueAtTime(f,now+i*.07);
      g.gain.setValueAtTime(.05,now+i*.07);
      g.gain.exponentialRampToValueAtTime(.001,now+i*.07+.3);
      o.start(now+i*.07);o.stop(now+i*.07+.3);
    });
  }catch(e){}
}

function updateCoins(c){
  document.getElementById('disp-coins').innerText='🪙 '+c.toLocaleString('id-ID');
  const t=document.getElementById('user-coins');if(t)t.innerText=c;
}

function showSummary(boxId, valId, subId, amount, subText) {
  const box=document.getElementById(boxId);
  box.style.display='flex';
  document.getElementById(valId).innerText='Rp '+amount.toLocaleString('id-ID');
  document.getElementById(subId).innerText=subText;
}
function hideSummary(boxId){ document.getElementById(boxId).style.display='none'; }

function setBuyQty(q){ document.getElementById('buy-qty').value=q; updateBuySummary(q); }
document.getElementById('buy-qty').addEventListener('input',function(){ updateBuySummary(parseInt(this.value)||0); });
function updateBuySummary(q){
  if(q>=10) showSummary('buy-summary-box','buy-summary-val','buy-summary-sub', q*BUY_RATE, q.toLocaleString('id-ID')+' koin ← dari Saldo Beli');
  else hideSummary('buy-summary-box');
}

function setSellQty(q){ document.getElementById('sell-qty').value=q; updateSellSummary(q); }
document.getElementById('sell-qty').addEventListener('input',function(){ updateSellSummary(parseInt(this.value)||0); });
function updateSellSummary(q){
  if(q>=1) showSummary('sell-summary-box','sell-summary-val','sell-summary-sub', q*SELL_RATE, q.toLocaleString('id-ID')+' koin → masuk Saldo Penarikan');
  else hideSummary('sell-summary-box');
}

function claimDaily(e){
  e.preventDefault();
  const btn=document.getElementById('btn-claim-daily');
  btn.disabled=true; btn.innerText='Mengklaim...';
  fetch('',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'action=claim_daily&_csrf='+encodeURIComponent(_csrf)})
  .then(r=>r.json()).then(res=>{
    if(res.error){btn.disabled=false;btn.innerText='🎁 Klaim 50 Koin';nToast(res.error,'error');}
    else{btn.innerText='✅ Diklaim';btn.style.background='#ccc';btn.style.color='#888';btn.style.cursor='not-allowed';updateCoins(res.new_coins);playWinChime();nToast(res.message,'success');}
  }).catch(()=>{btn.disabled=false;btn.innerText='🎁 Klaim 50 Koin';nToast('Koneksi terputus.','error');});
}

function buyCoins(e){
  e.preventDefault();
  const btn=document.getElementById('btn-buy'), qtyStr=document.getElementById('buy-qty').value;
  if(!qtyStr){ nToast('Masukkan jumlah koin!', 'error'); return; }
  const qty = parseInt(qtyStr);
  if(qty < 10){ nToast('Minimal pembelian 10 koin!', 'error'); return; }
  btn.disabled=true; btn.innerText='Memproses...';
  fetch('',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'action=buy_coins&qty='+qty+'&_csrf='+encodeURIComponent(_csrf)})
  .then(r=>r.json()).then(res=>{
    btn.disabled=false; btn.innerText='💳 Beli Koin';
    if(res.error){nToast(res.error,'error');}
    else{document.getElementById('buy-qty').value='';hideSummary('buy-summary-box');updateCoins(res.new_coins);document.getElementById('disp-dep').innerText=res.new_balance_dep;playWinChime();nToast(res.message,'success');}
  }).catch(()=>{btn.disabled=false;btn.innerText='💳 Beli Koin';nToast('Koneksi terputus.','error');});
}

function sellCoins(e){
  e.preventDefault();
  const btn=document.getElementById('btn-sell'), qtyStr=document.getElementById('sell-qty').value;
  if(!qtyStr){ nToast('Masukkan jumlah koin!', 'error'); return; }
  const qty = parseInt(qtyStr);
  if(qty < 1){ nToast('Minimal penjualan 1 koin!', 'error'); return; }
  btn.disabled=true; btn.innerText='Memproses...';
  fetch('',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'action=sell_coins&qty='+qty+'&_csrf='+encodeURIComponent(_csrf)})
  .then(r=>r.json()).then(res=>{
    btn.disabled=false; btn.innerText='💰 Jual Koin';
    if(res.error){nToast(res.error,'error');}
    else{document.getElementById('sell-qty').value='';hideSummary('sell-summary-box');updateCoins(res.new_coins);document.getElementById('disp-wd').innerText=res.new_balance_wd;playWinChime();nToast(res.message,'success');}
  }).catch(()=>{btn.disabled=false;btn.innerText='💰 Jual Koin';nToast('Koneksi terputus.','error');});
}
</script>

<?php require dirname(__DIR__) . '/partials/footer.php'; ?>
