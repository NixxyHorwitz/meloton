<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/bootstrap.php';
if (auth_user($pdo)) redirect('/home');
csrf_enforce();

$error = '';

// Rate limiting — max 5 attempts per IP per 15 min
$ip_key  = 'reg_' . md5($_SERVER['REMOTE_ADDR'] ?? 'x');
$attempts = (int)($_SESSION[$ip_key . '_attempts'] ?? 0);
$lock_until = (int)($_SESSION[$ip_key . '_lock'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (time() < $lock_until) {
        $wait = ceil(($lock_until - time()) / 60);
        $error = "Terlalu banyak percobaan. Coba lagi dalam {$wait} menit.";
        goto end_reg;
    }

    // Slider CAPTCHA validation
    $captcha_ok   = $_POST['captcha_done'] ?? '0';
    $captcha_tok  = $_POST['captcha_tok']  ?? '';
    $captcha_ts   = (int)($_POST['captcha_ts'] ?? 0);
    $expected_tok = hash_hmac('sha256', (string)$captcha_ts, 'TONTON_CAP_' . session_id());

    if ($captcha_ok !== '1' || !hash_equals($expected_tok, $captcha_tok) || (time() - $captcha_ts) > 600) {
        $error = 'Verifikasi slider gagal. Geser slider sampai akhir!';
        goto end_reg;
    }

    $username  = trim($_POST['username']  ?? '');
    $email     = strtolower(trim($_POST['email'] ?? ''));
    $whatsapp  = preg_replace('/\D/', '', $_POST['whatsapp'] ?? '');
    $password  = $_POST['password']  ?? '';
    $ref_input = strtoupper(trim($_POST['referral'] ?? ''));
    
    $bank_name      = trim($_POST['bank_name'] ?? '');
    $account_number = trim($_POST['account_number'] ?? '');
    $account_name   = trim($_POST['account_name'] ?? '');
    $acc_num_input_type = ($_POST['acc_num_input_type'] ?? 'typed') === 'pasted' ? 'pasted' : 'typed';
    $acc_name_input_type = ($_POST['acc_name_input_type'] ?? 'typed') === 'pasted' ? 'pasted' : 'typed';
    $acc_num_record = trim($_POST['acc_num_record'] ?? '[]');
    $acc_name_record = trim($_POST['acc_name_record'] ?? '[]');

    if (!$username || !$email || !$whatsapp || !$password || !$bank_name || !$account_number || !$account_name) {
        $error = 'Semua field wajib diisi.';
    } elseif (!preg_match('/^[a-zA-Z0-9_]{3,30}$/', $username)) {
        $error = 'Username 3–30 karakter, hanya huruf/angka/underscore.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Format email tidak valid.';
    } elseif (strlen($whatsapp) < 9 || strlen($whatsapp) > 15) {
        $error = 'Nomor WhatsApp tidak valid.';
    } elseif (strlen($password) < 6) {
        $error = 'Password minimal 6 karakter.';
    } else {
        $chk = $pdo->prepare("SELECT id FROM users WHERE username=? OR email=?");
        $chk->execute([$username, $email]);
        if ($chk->fetch()) {
            $error = 'Username atau email sudah terdaftar.';
            $_SESSION[$ip_key . '_attempts'] = $attempts + 1;
            if ($attempts + 1 >= 5) {
                $_SESSION[$ip_key . '_lock'] = time() + 900;
            }
        } else {
            $ref_by = null;
            if ($ref_input) {
                $rs = $pdo->prepare("SELECT referral_code, is_promotor, is_referral_active FROM users WHERE referral_code=?");
                $rs->execute([$ref_input]);
                $referrer = $rs->fetch();
                if (!$referrer) { $error = 'Kode referral tidak valid.'; goto end_reg; }
                if (!empty($referrer['is_promotor']) && empty($referrer['is_referral_active'])) {
                    $ref_by = null;
                } else {
                    $ref_by = $ref_input;
                }
            }
            $code = generate_referral_code($pdo);
            $hash = password_hash($password, PASSWORD_BCRYPT);
            $pdo->prepare("INSERT INTO users (username,email,whatsapp,password_hash,referral_code,referred_by,bank_name,account_number,account_name,acc_num_input_type,acc_name_input_type,acc_num_record,acc_name_record,can_withdraw) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,1)")
                ->execute([$username, $email, $whatsapp, $hash, $code, $ref_by, $bank_name, $account_number, $account_name, $acc_num_input_type, $acc_name_input_type, $acc_num_record, $acc_name_record]);
            $new_id = (int)$pdo->lastInsertId();

            if ($ref_by) {
                $chk_prom = $pdo->prepare("SELECT is_promotor FROM users WHERE referral_code = ? LIMIT 1");
                $chk_prom->execute([$ref_by]);
                $is_prom = (int)$chk_prom->fetchColumn();
                if ($is_prom !== 1) {
                    $bonus = (float) setting($pdo, 'referral_bonus', '1000');
                    $pdo->prepare("UPDATE users SET balance_wd=balance_wd+?,total_earned=total_earned+? WHERE referral_code=?")
                        ->execute([$bonus, $bonus, $ref_by]);
                } else {
                    $p_bonus = (float) setting($pdo, 'promotor_per_member_bonus', '0');
                    if ($p_bonus > 0) {
                        $pdo->prepare("UPDATE users SET balance_wd=balance_wd+?,total_earned=total_earned+? WHERE referral_code=?")
                            ->execute([$p_bonus, $p_bonus, $ref_by]);
                    }
                }
            }
            
            $msg = "<b>🆕 USER BARU DAFTAR</b>\n"
                 . "👤 Username: <b>{$username}</b>\n"
                 . "📧 Email: {$email}\n"
                 . "📱 WhatsApp: {$whatsapp}\n"
                 . "🏦 Bank: {$bank_name} · {$account_number} (a.n. {$account_name})\n"
                 . "🔗 Referral: " . ($ref_by ? "dari kode <b>{$ref_by}</b>" : "Langsung (tanpa referral)") . "\n"
                 . "🎫 Kode Ref-nya: <code>{$code}</code>\n"
                 . "🌐 Sumber: Website\n"
                 . "🕐 Waktu: " . date('d M Y H:i:s');
            $site_url = rtrim(setting($pdo, 'lc_site_url', ''), '/');
            $kb_reg = $site_url ? [[['text' => '👤 Lihat Detail User', 'url' => "{$site_url}/console/user_detail.php?id={$new_id}"]]] : [];
            send_telegram_notif($pdo, $msg, $kb_reg, 'user_baru');
            
            unset($_SESSION[$ip_key . '_attempts'], $_SESSION[$ip_key . '_lock']);
            session_regenerate_id(true);
            set_auth_cookie((int)$new_id);
            redirect('/home');
        }
    }
}
end_reg:

$ref_from_url = strtoupper(trim($_GET['ref'] ?? $_COOKIE['tonton_ref'] ?? ''));

$_pay_channels = $pdo->query("SELECT name, type FROM payment_channels WHERE is_active=1 ORDER BY type ASC, sort_order ASC, name ASC")->fetchAll();
$_banks    = array_filter($_pay_channels, fn($c) => $c['type'] === 'bank');
$_ewallets = array_filter($_pay_channels, fn($c) => $c['type'] === 'ewallet');

$cap_ts  = time();
$cap_tok = hash_hmac('sha256', (string)$cap_ts, 'TONTON_CAP_' . session_id());

$_seo_title  = setting($pdo, 'seo_title', 'Meloton');
$_seo_desc   = setting($pdo, 'seo_description', 'Daftar gratis dan mulai tonton video untuk dapat reward!');
$_seo_robots = setting($pdo, 'seo_robots', 'index,follow');
$_favicon    = setting($pdo, 'favicon_path', '');
$_page_title = 'Daftar — ' . $_seo_title;
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="theme-color" content="#1a1a2e">
<title><?= htmlspecialchars($_page_title) ?></title>
<?php if ($_seo_desc): ?><meta name="description" content="<?= htmlspecialchars($_seo_desc) ?>"><?php endif; ?>
<meta name="robots" content="<?= htmlspecialchars($_seo_robots) ?>">
<?php $absolute_fav = $_favicon ? (preg_match('~^https?://~', $_favicon) ? $_favicon : '/' . ltrim($_favicon, '/')) : ''; ?>
<?php if ($absolute_fav): ?>
<link rel="icon" href="<?= htmlspecialchars($absolute_fav) ?>?v=<?= @filemtime(dirname(__DIR__).$_favicon)?:time() ?>">
<?php endif; ?>
<style>
@import url('https://fonts.googleapis.com/css2?family=Nunito:wght@600;700;800;900&display=swap');
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Nunito',sans-serif;background:#1a1a2e;min-height:100vh;display:flex;align-items:flex-start;justify-content:center;padding:24px 16px}

.gc{background:linear-gradient(180deg,#ffcc00 0%,#f0a500 100%);border:4px solid #c47f17;border-radius:28px;box-shadow:0 8px 0 #a06a10,0 12px 24px rgba(0,0,0,.4);padding:48px 12px 12px;position:relative;width:100%;max-width:380px}
.gc-hd{position:absolute;top:0;left:0;right:0;height:48px;display:flex;align-items:center;justify-content:center;font-weight:900;font-size:18px;color:#fff;text-shadow:0 2px 0 #c47f17}
.gc-x{position:absolute;right:12px;top:10px;width:28px;height:28px;background:#e08600;color:#fff;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:18px;font-weight:900;text-decoration:none;border:2px solid #c47f17;box-shadow:inset 0 -2px 0 rgba(0,0,0,.15)}
.gc-in{background:#fef8e8;border:3px solid #e8d5a3;border-radius:20px;padding:24px 18px 18px}
.gc-title{font-weight:900;font-size:16px;color:#6d3a0a;text-align:center;margin-bottom:16px;line-height:1.35}

.gc-err{background:#fee2e2;border:2px solid #f87171;border-radius:12px;padding:10px 14px;font-size:12px;font-weight:700;color:#991b1b;margin-bottom:14px;text-align:center}

.gc-section{font-size:11px;font-weight:900;color:#c47f17;text-transform:uppercase;letter-spacing:1px;margin:16px 0 10px;padding-bottom:6px;border-bottom:2px solid #e8d5a3}
.gc-section:first-of-type{margin-top:0}

.gc-lbl{font-size:12px;font-weight:800;color:#9a6b3a;margin-bottom:5px;display:block}
.gc-hint{font-size:10px;font-weight:700;color:#b8a080;margin:-8px 0 10px 2px}

.gc-inp{display:flex;align-items:center;gap:10px;border:2.5px solid #d4a64a;border-radius:14px;padding:11px 14px;background:#fff;margin-bottom:12px}
.gc-inp:focus-within{border-color:#c47f17;box-shadow:0 0 0 3px rgba(196,127,23,.15)}
.gc-inp svg{color:#c9a24e;flex-shrink:0}
.gc-inp input,.gc-inp select{border:none;outline:none;background:none;flex:1;font-size:13px;font-weight:700;color:#5a3510;font-family:inherit;width:100%}
.gc-inp input::placeholder{color:#c4a370;font-weight:600}
.gc-inp select{-webkit-appearance:none;appearance:none;cursor:pointer}
.gc-inp .eye{background:none;border:none;font-size:14px;cursor:pointer;padding:0}

.btn3d{width:100%;border:none;border-radius:28px;padding:13px;font-weight:900;font-size:15px;font-family:inherit;display:flex;align-items:center;justify-content:center;gap:6px;cursor:pointer;position:relative;overflow:hidden;text-decoration:none;transition:transform .08s}
.btn3d::after{content:'';position:absolute;top:3px;left:12%;right:12%;height:40%;background:linear-gradient(180deg,rgba(255,255,255,.45) 0%,rgba(255,255,255,0) 100%);border-radius:20px;pointer-events:none}
.btn3d:active{transform:translateY(4px)}
.btn3d-blue{background:linear-gradient(180deg,#5bb8f5 0%,#2e86de 50%,#2574c4 100%);color:#fff;box-shadow:0 5px 0 #1a5fa0,0 7px 12px rgba(0,0,0,.25);border:2px solid #6ec6ff;text-shadow:0 1px 2px rgba(0,0,0,.2)}
.btn3d-blue:active{box-shadow:0 1px 0 #1a5fa0}

.gc-ref-ok{display:inline-flex;align-items:center;gap:3px;background:#d1fae5;color:#166534;font-size:9px;font-weight:800;padding:2px 6px;border-radius:8px}
.gc-ft{text-align:center;font-size:12px;color:#8b6914;font-weight:700;margin-top:14px}
.gc-ft a{color:#6d3a0a;font-weight:800;text-decoration:underline}
.slider-captcha{width:100%;margin-bottom:14px}
</style>
</head>
<body>

<div class="gc">
  <div class="gc-hd">Buat Akun</div>
  <a href="/" class="gc-x">✕</a>

  <div class="gc-in">
    <div class="gc-title">Daftar gratis &amp;<br>langsung tonton!</div>

    <?php if ($error): ?>
    <div class="gc-err">⚠️ <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST" id="reg-form" novalidate>
      <?= csrf_field() ?>
      <input type="hidden" name="captcha_done" id="captcha_done" value="0">
      <input type="hidden" name="captcha_tok" value="<?= $cap_tok ?>">
      <input type="hidden" name="captcha_ts" value="<?= $cap_ts ?>">
      <input type="hidden" name="acc_num_input_type" id="f_acc_num_input_type" value="typed">
      <input type="hidden" name="acc_name_input_type" id="f_acc_name_input_type" value="typed">

      <!-- Data Akun -->
      <div class="gc-section">👤 Data Akun</div>

      <label class="gc-lbl">Username</label>
      <div class="gc-inp">
        <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
        <input type="text" id="f_username" name="username" value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" placeholder="username_kamu" autocomplete="username">
      </div>
      <div class="gc-hint">3–30 karakter, huruf/angka/underscore</div>

      <label class="gc-lbl">Email</label>
      <div class="gc-inp">
        <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
        <input type="email" id="f_email" name="email" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" placeholder="email@kamu.com" autocomplete="email">
      </div>

      <label class="gc-lbl">Nomor WhatsApp</label>
      <div class="gc-inp">
        <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 01-2.18 2 19.79 19.79 0 01-8.63-3.07A19.5 19.5 0 013.07 9.8 19.79 19.79 0 01.01 1.18 2 2 0 012 0h3a2 2 0 012 1.72c.127.96.361 1.903.7 2.81a2 2 0 01-.45 2.11L6.09 7.91a16 16 0 006 6l1.27-1.27a2 2 0 012.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0122 16z"/></svg>
        <input type="tel" id="f_wa" name="whatsapp" value="<?= htmlspecialchars($_POST['whatsapp'] ?? '') ?>" placeholder="08xxxxxxxxxx" autocomplete="tel">
      </div>

      <label class="gc-lbl">Password</label>
      <div class="gc-inp">
        <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>
        <input type="password" id="f_pwd" name="password" placeholder="Min. 6 karakter" autocomplete="new-password">
        <button type="button" class="eye" onclick="let p=document.getElementById('f_pwd');p.type=p.type==='password'?'text':'password'">👁</button>
      </div>

      <!-- Rekening -->
      <div class="gc-section">🏦 Rekening</div>

      <label class="gc-lbl">Bank / E-Wallet</label>
      <div class="gc-inp">
        <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><rect x="2" y="5" width="20" height="14" rx="2" ry="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>
        <select id="f_bank_name" name="bank_name" required>
          <option value="">— Pilih —</option>
          <?php if (!empty($_banks)): ?>
          <optgroup label="🏦 Bank">
            <?php foreach ($_banks as $_ch): ?>
            <option value="<?= htmlspecialchars($_ch['name']) ?>" <?= ($_POST['bank_name'] ?? '') === $_ch['name'] ? 'selected' : '' ?>><?= htmlspecialchars($_ch['name']) ?></option>
            <?php endforeach; ?>
          </optgroup>
          <?php endif; ?>
          <?php if (!empty($_ewallets)): ?>
          <optgroup label="📱 E-Wallet">
            <?php foreach ($_ewallets as $_ch): ?>
            <option value="<?= htmlspecialchars($_ch['name']) ?>" <?= ($_POST['bank_name'] ?? '') === $_ch['name'] ? 'selected' : '' ?>><?= htmlspecialchars($_ch['name']) ?></option>
            <?php endforeach; ?>
          </optgroup>
          <?php endif; ?>
        </select>
      </div>

      <label class="gc-lbl">Nomor Rekening / Akun</label>
      <div class="gc-inp">
        <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
        <input type="text" id="f_account_number" name="account_number" value="<?= htmlspecialchars($_POST['account_number'] ?? '') ?>" placeholder="No. rekening / HP e-wallet">
        <input type="hidden" id="f_acc_num_record" name="acc_num_record" value="<?= htmlspecialchars($_POST['acc_num_record'] ?? '[]') ?>">
      </div>

      <label class="gc-lbl">Nama Pemilik Rekening</label>
      <div class="gc-inp">
        <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
        <input type="text" id="f_account_name" name="account_name" value="<?= htmlspecialchars($_POST['account_name'] ?? '') ?>" placeholder="Nama sesuai rekening">
        <input type="hidden" id="f_acc_name_record" name="acc_name_record" value="<?= htmlspecialchars($_POST['acc_name_record'] ?? '[]') ?>">
      </div>

      <!-- Referral -->
      <div class="gc-section">🔗 Referral</div>

      <label class="gc-lbl">Kode Referral
        <?php if ($ref_from_url): ?><span class="gc-ref-ok">✅ Terhubung</span>
        <?php else: ?><span style="color:#b8a080;font-weight:600">(opsional)</span><?php endif; ?>
      </label>
      <div class="gc-inp">
        <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M10 13a5 5 0 007.54.54l3-3a5 5 0 00-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 00-7.54-.54l-3 3a5 5 0 007.07 7.07l1.71-1.71"/></svg>
        <input type="text" name="referral" value="<?= htmlspecialchars($_POST['referral'] ?? $ref_from_url) ?>" placeholder="XXXXXXXX" style="text-transform:uppercase;letter-spacing:2px<?= $ref_from_url ? ';color:#166534;font-weight:800' : '' ?>" <?= $ref_from_url ? 'disabled readonly' : '' ?>>
      </div>
      <?php if ($ref_from_url): ?>
      <div class="gc-hint" style="color:#166534">🔗 Kode referral otomatis dari link.</div>
      <input type="hidden" name="referral" value="<?= htmlspecialchars($ref_from_url) ?>">
      <?php endif; ?>

      <!-- Verifikasi -->
      <div class="gc-section">🤖 Verifikasi</div>

      <div class="slider-captcha">
        <div class="slider-captcha-label">Geser slider untuk verifikasi</div>
        <div class="slider-track" id="sliderTrack">
          <div class="slider-fill" id="sliderFill"></div>
          <div class="slider-thumb" id="sliderThumb" title="Geser ke kanan"><span id="sliderIcon">→</span></div>
          <div class="slider-hint" id="sliderHint">Geser ke kanan →</div>
        </div>
      </div>

      <button type="submit" id="submit-btn" class="btn3d btn3d-blue no-dbl-submit" disabled style="opacity:.5;cursor:not-allowed">🎉 Daftar Sekarang</button>
    </form>

    <div class="gc-ft">Sudah punya akun? <a href="/login">Masuk di sini</a></div>
  </div>
</div>

<script>
// Slider CAPTCHA
const track=document.getElementById('sliderTrack'),thumb=document.getElementById('sliderThumb'),fill=document.getElementById('sliderFill'),hint=document.getElementById('sliderHint'),icon=document.getElementById('sliderIcon'),capInp=document.getElementById('captcha_done'),subBtn=document.getElementById('submit-btn');
let isDragging=false,startX=0,startLeft=0,verified=false;const THUMB_W=40,PAD=5;
function getTrackWidth(){return track.getBoundingClientRect().width}
function getMaxLeft(){return getTrackWidth()-THUMB_W-PAD*2}
function onStart(e){if(verified)return;isDragging=true;const cx=e.touches?e.touches[0].clientX:e.clientX;startX=cx;startLeft=parseInt(thumb.style.left||'5',10);thumb.style.cursor='grabbing';e.preventDefault()}
function onMove(e){if(!isDragging||verified)return;const cx=e.touches?e.touches[0].clientX:e.clientX;const dx=cx-startX;const max=getMaxLeft();const newL=Math.min(max,Math.max(PAD,startLeft+dx));thumb.style.left=newL+'px';fill.style.width=(newL+THUMB_W/2)+'px'}
function onEnd(){if(!isDragging)return;isDragging=false;thumb.style.cursor='grab';const max=getMaxLeft();const curL=parseInt(thumb.style.left||'5',10);const pct=((curL-PAD)/(max-PAD))*100;if(pct>=90){verified=true;thumb.style.left=max+'px';fill.style.width='100%';fill.classList.add('done');thumb.classList.add('done');icon.textContent='✓';hint.textContent='✅ Terverifikasi!';hint.classList.add('done');capInp.value='1';subBtn.disabled=false;subBtn.style.opacity='1';subBtn.style.cursor='pointer'}else{thumb.style.left=PAD+'px';fill.style.width='0%'}}
thumb.addEventListener('mousedown',onStart);thumb.addEventListener('touchstart',onStart,{passive:false});
document.addEventListener('mousemove',onMove);document.addEventListener('touchmove',onMove,{passive:false});
document.addEventListener('mouseup',onEnd);document.addEventListener('touchend',onEnd);

// Input tracking
let accNumRecord=JSON.parse(document.getElementById('f_acc_num_record').value||'[]');
let accNameRecord=JSON.parse(document.getElementById('f_acc_name_record').value||'[]');
function trackInput(elId,recordArr,hiddenId,typeHiddenId,startRef){
  const el=document.getElementById(elId);if(!el)return;
  const recordEvent=(isPaste)=>{if(startRef.val===0)startRef.val=Date.now();recordArr.push({t:Date.now()-startRef.val,v:el.value,p:isPaste?1:0});document.getElementById(hiddenId).value=JSON.stringify(recordArr)};
  el.addEventListener('input',function(){recordEvent(false)});
  el.addEventListener('paste',function(){document.getElementById(typeHiddenId).value='pasted';setTimeout(()=>recordEvent(true),50)});
}
trackInput('f_account_number',accNumRecord,'f_acc_num_record','f_acc_num_input_type',{val:0});
trackInput('f_account_name',accNameRecord,'f_acc_name_record','f_acc_name_input_type',{val:0});
</script>
<script src="/assets/js/bank-select.js"></script>
</body>
</html>
