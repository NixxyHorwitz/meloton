<?php
declare(strict_types=1);
// DEBUG: tampilkan semua error PHP ke log
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/../bootstrap.php';
$user         = require_auth($pdo);
$_favicon     = setting($pdo, 'favicon_path', '');
$_seo_title   = setting($pdo, 'seo_title', 'NontonKuy');
$_lc_enabled  = setting($pdo, 'livechat_enabled', '1') === '1';
$_ai_enabled  = setting($pdo, 'chat_ai_enabled', '1') === '1';
$_adm_enabled = setting($pdo, 'chat_admin_enabled', '1') === '1';
$_att_enabled = setting($pdo, 'lc_attachment_enabled', '1') === '1';
$_debug_on    = setting($pdo, 'lc_debug_panel', '0') === '1';
if (!$_ai_enabled && !$_adm_enabled) $_lc_enabled = false;

error_log('[LiveChat] page loaded, user=' . ($user['username'] ?? 'null')
    . ' lc_enabled=' . (int)$_lc_enabled
    . ' ai=' . (int)$_ai_enabled
    . ' adm=' . (int)$_adm_enabled
    . ' debug=' . (int)$_debug_on);
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,viewport-fit=cover,interactive-widget=resizes-content">
<meta name="theme-color" content="#FFE566">
<title>Live Chat — <?= htmlspecialchars($_seo_title) ?></title>
<?php
$_abs_fav = $_favicon ? (preg_match('~^https?://~', $_favicon) ? $_favicon : '/' . ltrim($_favicon, '/')) : '';
if ($_abs_fav): ?>
<link rel="icon" href="<?= htmlspecialchars($_abs_fav) ?>">
<link rel="apple-touch-icon" href="<?= htmlspecialchars($_abs_fav) ?>">
<?php endif; ?>
<link rel="stylesheet" href="/assets/css/app.css?v=<?= @filemtime($_SERVER['DOCUMENT_ROOT'].'/assets/css/app.css') ?: time() ?>">
<script src="https://unpkg.com/@phosphor-icons/web"></script>
<style>
/* ── LiveChat: position:fixed layout (keyboard-safe, app.css-proof) ── */
* { box-sizing: border-box; }
body { margin: 0; padding: 0; overflow: hidden; background: var(--bg); }

/* Topbar: fixed to top */
.chat-topbar {
  position: fixed !important;
  top: 0; left: 0; right: 0;
  height: 54px;
  z-index: 100;
}

/* Chat root: fills everything below topbar */
#chat-root {
  position: fixed;
  top: 54px;
  left: 0; right: 0;
  bottom: 0;
  /* bottom diupdate JS saat keyboard muncul */
  display: flex;
  flex-direction: column;
  overflow: hidden;
  background: var(--bg);
}
</style>
</head>
<body>

<?php if (!$_lc_enabled): ?>
<!-- Live Chat Disabled -->
<div style="min-height:100vh;display:flex;align-items:center;justify-content:center;background:var(--bg);padding:24px;">
  <div style="text-align:center;max-width:320px;">
    <div style="font-size:52px;color:var(--ink);margin-bottom:12px;"><i class="ph-fill ph-clock"></i></div>
    <h2 style="font-weight:900;font-size:20px;margin-bottom:8px;color:var(--ink);">Live Chat Sedang Ditutup</h2>
    <p style="color:#64748b;font-size:13px;font-weight:700;margin-bottom:20px;"><?= nl2br(htmlspecialchars(setting($pdo, 'lc_offline_msg', 'Layanan live chat saat ini tidak tersedia. Silakan coba lagi nanti pada jam operasional.'))) ?></p>
    <a href="/home" style="background:var(--yellow);border:3px solid var(--ink);box-shadow:4px 4px 0 var(--ink);border-radius:12px;font-weight:900;padding:12px 24px;font-size:13px;text-decoration:none;color:var(--ink);display:inline-flex;align-items:center;gap:6px;"><i class="ph-bold ph-arrow-left"></i> Kembali ke Beranda</a>
  </div>
</div>
<?php else: ?>
<!-- Live Chat Active -->
<!-- ── Custom Topbar (no navbar) ── -->
<header class="chat-topbar" id="chat-topbar">
  <a href="/home" class="chat-back-btn" title="Kembali ke Beranda">
    <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><polyline points="15 18 9 12 15 6"/></svg>
  </a>
  <div class="chat-topbar__info">
    <span class="chat-topbar__title">💬 Live Support</span>
    <span class="chat-topbar__sub" id="topbar-username"><?= htmlspecialchars($user['username']) ?></span>
  </div>
  <div class="chat-topbar__actions">
    <div class="chat-status-badge online" id="chat-status-badge" style="border:1.5px solid var(--ink);">Online</div>
  </div>
</header>

<?php if ($_debug_on): ?>
<!-- ── DEBUG PANEL (aktif via Console → Livechat → Pengaturan) ── -->
<div id="lc-debug-wrapper" style="
  position:fixed; bottom:0; left:0; right:0; z-index:9999;
  background:rgba(0,0,10,0.92); color:#e0e0e0;
  font-size:10px; font-family:monospace; line-height:1.5;
  max-height:38vh; display:flex; flex-direction:column;
  border-top:2px solid #4fc3f7;
">
  <div style="display:flex;justify-content:space-between;padding:4px 8px;background:#111;flex-shrink:0;border-bottom:1px solid #333">
    <strong style="color:#4fc3f7">🐛 LiveChat Debug</strong>
    <button onclick="this.closest('#lc-debug-wrapper').style.display='none'"
      style="background:none;border:none;color:#aaa;cursor:pointer;font-size:12px">✕ tutup</button>
  </div>
  <div id="lc-debug" style="overflow-y:auto;flex:1;padding:6px 8px"></div>
</div>
<?php endif; ?>

<style>
/* ═══════════════════════════════════════
   LIVECHAT — Neobrutalism
═══════════════════════════════════════ */
/* ── Custom topbar ──────────────────── */
.chat-topbar {
  height: 54px;
  background: var(--white);
  border-bottom: var(--border);
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 0 12px;
  flex-shrink: 0;
  position: sticky;
  top: 0;
  z-index: 100;
}
.chat-back-btn {
  width: 36px; height: 36px;
  border: var(--border);
  border-radius: 10px;
  box-shadow: var(--shadow-sm);
  background: var(--white);
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  color: var(--ink);
  text-decoration: none;
  transition: transform .12s, box-shadow .12s;
}
.chat-back-btn:hover { transform: translate(-2px,-2px); box-shadow: 4px 4px 0 var(--ink); }
.chat-back-btn:active { transform: translate(1px,1px); box-shadow: 1px 1px 0 var(--ink); }
.chat-topbar__info { flex: 1; min-width: 0; }
.chat-topbar__title { font-size: 14px; font-weight: 900; display: block; line-height: 1.2; }
.chat-topbar__sub   { font-size: 11px; color: #888; font-weight: 600; }
.chat-topbar__actions { flex-shrink: 0; }

.chat-page {
  display: flex;
  flex-direction: column;
  width: 100%;
  height: 100%;
  padding: 0;
  overflow: hidden;
  min-height: 0;
}

/* ── Mode switch bar ─────────────────── */
.chat-modebar {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 10px 14px;
  background: var(--white);
  border-bottom: var(--border);
  flex-shrink: 0;
}
.chat-modebar__label {
  font-size: 11px;
  font-weight: 900;
  color: #888;
  text-transform: uppercase;
  letter-spacing: .5px;
  flex-shrink: 0;
}
.mode-pill {
  display: flex;
  background: #f0f0f0;
  border: var(--border);
  border-radius: 10px;
  box-shadow: var(--shadow-sm);
  overflow: hidden;
  flex: 1;
}
.mode-btn {
  flex: 1;
  border: none;
  background: transparent;
  font-family: inherit;
  font-size: 12px;
  font-weight: 800;
  padding: 8px 6px;
  cursor: pointer;
  transition: all .15s;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 5px;
  color: #888;
  border-radius: 8px;
}
.mode-btn.active {
  background: var(--ink);
  color: #fff;
  box-shadow: 2px 2px 0 #555;
}
.mode-btn.active.mode-ai  { background: var(--lavender); color: var(--ink); }
.mode-btn.active.mode-adm { background: var(--mint);     color: var(--ink); }

/* Status badge */
.chat-status-badge {
  flex-shrink: 0;
  font-size: 10px;
  font-weight: 900;
  padding: 4px 10px;
  border: 1.5px solid var(--ink);
  border-radius: 20px;
  display: flex;
  align-items: center;
  gap: 4px;
}
.chat-status-badge.online { background: var(--lime); }
.chat-status-badge.busy   { background: var(--peach); }
.chat-status-badge::before {
  content: '';
  width: 7px; height: 7px;
  border-radius: 50%;
  background: var(--green);
  display: inline-block;
}
.chat-status-badge.busy::before { background: var(--orange); }

/* ── Messages area ───────────────────── */
.chat-messages {
  flex: 1 1 0;
  overflow-y: auto;
  overflow-x: hidden;
  padding: 14px;
  display: flex;
  flex-direction: column;
  gap: 10px;
  background: var(--bg);
  scroll-behavior: smooth;
  min-height: 0;
  -webkit-overflow-scrolling: touch;
}
.chat-messages::-webkit-scrollbar { width: 4px; }
.chat-messages::-webkit-scrollbar-thumb { background: #ccc; border-radius: 4px; }

/* ── Bubble ──────────────────────────── */
.bubble-wrap {
  display: flex;
  flex-direction: column;
  max-width: 82%;
  animation: bubbleIn .2s ease;
}
@keyframes bubbleIn {
  from { opacity: 0; transform: translateY(8px); }
  to   { opacity: 1; transform: translateY(0); }
}
.bubble-wrap.user  { align-self: flex-end; align-items: flex-end; }
.bubble-wrap.other { align-self: flex-start; align-items: flex-start; }
.bubble-wrap.system{ align-self: center; align-items: center; }

.bubble {
  padding: 10px 14px;
  border-radius: 14px;
  border: 2px solid var(--ink);
  font-size: 13.5px;
  font-weight: 600;
  line-height: 1.55;
  word-break: break-word;
}
.bubble-wrap.user  .bubble { background: var(--yellow); box-shadow: 3px 3px 0 var(--ink); border-radius: 14px 14px 4px 14px; }
.bubble-wrap.ai    .bubble { background: var(--lavender); box-shadow: 3px 3px 0 var(--ink); border-radius: 14px 14px 14px 4px; }
.bubble-wrap.admin .bubble { background: var(--mint); box-shadow: 3px 3px 0 var(--ink); border-radius: 14px 14px 14px 4px; }
.bubble-wrap.system .bubble {
  background: #f5f5f5; color: #777;
  font-size: 11px; border-style: dashed;
  box-shadow: none; padding: 6px 14px;
  border-radius: 20px;
}
.bubble-meta {
  font-size: 10px;
  color: #aaa;
  font-weight: 700;
  margin-top: 3px;
  padding: 0 4px;
  display: flex;
  align-items: center;
  gap: 5px;
}
.bubble-sender-tag {
  font-size: 10px;
  font-weight: 900;
  padding: 2px 8px;
  border-radius: 10px;
  border: 1.5px solid var(--ink);
  margin-bottom: 4px;
  display: inline-block;
}
.sender-ai    { background: var(--lavender); }
.sender-admin { background: var(--mint); }
.sender-user  { background: var(--yellow); }

/* ── Typing indicator ────────────────── */
.typing-bubble {
  display: flex;
  align-items: center;
  gap: 5px;
  padding: 12px 16px;
  background: var(--lavender);
  border: 2px solid var(--ink);
  border-radius: 14px 14px 14px 4px;
  box-shadow: 3px 3px 0 var(--ink);
  max-width: 80px;
}
.typing-dot {
  width: 8px; height: 8px;
  background: var(--ink);
  border-radius: 50%;
  animation: typingBounce 1.2s infinite;
}
.typing-dot:nth-child(2) { animation-delay: .2s; }
.typing-dot:nth-child(3) { animation-delay: .4s; }
@keyframes typingBounce {
  0%,60%,100% { transform: translateY(0); }
  30%          { transform: translateY(-6px); }
}

/* ── Input area ──────────────────────── */
.chat-inputbar {
  padding: 10px 12px;
  padding-bottom: calc(10px + env(safe-area-inset-bottom));
  background: var(--white);
  border-top: var(--border);
  display: flex;
  gap: 8px;
  align-items: flex-end;
  flex-shrink: 0;
  z-index: 10;
}
.chat-textarea {
  flex: 1;
  min-height: 42px;
  max-height: 120px;
  resize: none;
  border: var(--border);
  border-radius: 12px;
  box-shadow: 3px 3px 0 var(--ink);
  padding: 10px 13px;
  /* iOS Safari: font-size HARUS >= 16px untuk mencegah auto-zoom */
  font-size: 16px;
  /* Secara visual dikecilkan dengan transform agar tetap pas */
  transform-origin: top left;
  font-family: inherit;
  font-weight: 600;
  color: var(--ink);
  background: var(--white);
  outline: none;
  transition: box-shadow .15s;
  overflow-y: auto;
  line-height: 1.45;
  touch-action: manipulation; /* cegah double-tap zoom */
  -webkit-text-size-adjust: 100%;
}
.chat-textarea:focus { box-shadow: 5px 5px 0 var(--ink); }
.chat-textarea::placeholder { color: #bbb; font-size: 15px; }
.chat-attach-btn {
  width: 44px; height: 44px;
  flex-shrink: 0;
  background: var(--white);
  border: var(--border);
  border-radius: 12px;
  box-shadow: 2px 2px 0 var(--ink);
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  transition: transform .12s, box-shadow .12s;
  font-size: 20px;
}
.chat-attach-btn:hover { transform: translate(-2px,-2px); box-shadow: 4px 4px 0 var(--ink); }
.chat-attach-btn:active { transform: translate(1px,1px); box-shadow: 1px 1px 0 var(--ink); }

.chat-send-btn {
  width: 44px; height: 44px;
  flex-shrink: 0;
  background: var(--brand);
  border: var(--border);
  border-radius: 12px;
  box-shadow: var(--shadow-sm);
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  transition: transform .12s, box-shadow .12s;
  color: #fff;
}
.chat-send-btn:hover { transform: translate(-2px,-2px); box-shadow: 5px 5px 0 var(--ink); }
.chat-send-btn:active { transform: translate(1px,1px); box-shadow: 1px 1px 0 var(--ink); }
.chat-send-btn:disabled { background: #ccc; cursor: not-allowed; transform: none; box-shadow: var(--shadow-sm); }

/* ── Mode info banner ────────────────── */
.mode-info-banner {
  margin: 0 14px;
  padding: 9px 13px;
  border-radius: 10px;
  border: 2px dashed var(--ink);
  font-size: 11.5px;
  font-weight: 700;
  display: flex;
  align-items: center;
  gap: 7px;
  flex-shrink: 0;
}
.mode-info-banner.ai-mode  { background: var(--lavender); }
.mode-info-banner.adm-mode { background: var(--mint); }

/* ── Session start overlay ───────────── */
.chat-start-overlay {
  position: absolute;
  inset: 0;
  background: var(--bg);
  z-index: 50;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 20px;
  overflow-y: auto;
  /* Important: don't capture touch on the container */
  -webkit-overflow-scrolling: touch;
}
.chat-start-card {
  width: 100%;
  background: var(--white);
  border: var(--border);
  border-radius: 20px;
  box-shadow: var(--shadow-lg);
  padding: 28px 22px;
  text-align: center;
}
.chat-start-icon {
  width: 70px; height: 70px;
  margin: 0 auto 14px;
  background: var(--yellow);
  border: var(--border);
  box-shadow: var(--shadow);
  border-radius: 20px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 32px;
}
.chat-start-title { font-size: 20px; font-weight: 900; margin-bottom: 6px; }
.chat-start-sub   { font-size: 13px; color: #666; font-weight: 600; margin-bottom: 20px; }

/* ── Closed overlay ──────────────────── */
.chat-closed-bar {
  padding: 12px 14px;
  background: var(--salmon);
  border-top: var(--border);
  text-align: center;
  font-size: 13px;
  font-weight: 800;
  flex-shrink: 0;
}
</style>

<div id="chat-root">

  <!-- Start Overlay (shown until session created) -->
  <div class="chat-start-overlay" id="chat-start-overlay">
    <div class="chat-start-card">
      <div class="chat-start-icon">💬</div>
      <div class="chat-start-title">Live Support</div>
      <div class="chat-start-sub">Pilih mode dan mulai percakapan dengan kami sekarang.</div>

      <!-- Mode selector in start screen -->
      <div style="margin-bottom:18px;">
        <p style="font-size:12px;font-weight:800;color:#888;margin-bottom:8px;text-transform:uppercase;letter-spacing:.5px;">Pilih Mode Chat</p>
        <div class="mode-pill" style="max-width:100%;box-shadow:var(--shadow);">
          <button class="mode-btn mode-ai active" id="start-mode-ai" onclick="selectStartMode('ai')">
            🤖 Asisten AI
          </button>
          <button class="mode-btn mode-adm" id="start-mode-admin" onclick="selectStartMode('admin')">
            👨‍💼 Admin
          </button>
        </div>
        <p id="start-mode-desc" style="font-size:11px;color:#888;margin-top:8px;font-weight:600;">
          AI akan menjawab pertanyaan Anda secara otomatis & instan.
        </p>
      </div>

      <button class="btn btn--primary btn--full btn--lg" id="btn-start-chat" onclick="startChat()">
        <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
          <path d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/>
        </svg>
        Mulai Chat
      </button>
    </div>
  </div>

  <!-- Chat UI (hidden until session created) -->
  <div class="chat-page" id="chat-ui" style="display:none;">

    <!-- Mode Bar -->
    <div class="chat-modebar">
      <span class="chat-modebar__label">Mode:</span>
      <div class="mode-pill" id="mode-pill-wrap">
        <?php if ($_ai_enabled): ?>
        <button class="mode-btn mode-ai" id="modebtn-ai" onclick="switchMode('ai')">
          🤖 AI
        </button>
        <?php endif; ?>
        <?php if ($_adm_enabled): ?>
        <button class="mode-btn mode-adm" id="modebtn-admin" onclick="switchMode('admin')">
          👨‍💼 Admin
        </button>
        <?php endif; ?>
      </div>
      <div class="chat-status-badge online" id="chat-status-badge">Online</div>
    </div>

    <!-- Mode info banner -->
    <div class="mode-info-banner ai-mode" id="mode-info-banner" style="margin-top:10px;">
      <span>🤖</span>
      <span id="mode-info-text">Mode AI aktif — Dijawab otomatis oleh Asisten AI.</span>
    </div>

    <!-- Messages -->
    <div class="chat-messages" id="chat-messages"></div>

    <!-- Typing indicator (hidden) -->
    <div id="typing-wrap" style="padding:0 14px 6px;display:none;">
      <div class="typing-bubble">
        <div class="typing-dot"></div>
        <div class="typing-dot"></div>
        <div class="typing-dot"></div>
      </div>
    </div>

    <!-- Input bar -->
    <div class="chat-inputbar" id="chat-inputbar">
      <?php if ($_att_enabled): ?>
      <button class="chat-attach-btn" onclick="document.getElementById('chat-attachment-input').click()">📎</button>
      <input type="file" id="chat-attachment-input" style="display:none;" accept="image/jpeg,image/png,image/gif,application/pdf,.zip,.rar" onchange="previewAttachment(this)">
      <?php endif; ?>
      <div style="flex:1; display:flex; flex-direction:column; gap:4px; position:relative;">
        <div id="attachment-preview" style="display:none; font-size:11px; background:#f0f0f0; border-radius:8px; padding:4px 8px; border:1px solid #ccc;">
            <span id="att-preview-name" style="font-weight:600;"></span>
            <span style="color:red; cursor:pointer; float:right; padding: 0 4px;" onclick="clearAttachment()">&times; Hapus</span>
        </div>
        <textarea class="chat-textarea" id="chat-input"
          placeholder="Ketik pesan..." rows="1" style="width:100%"
          onkeydown="handleKey(event)"
          oninput="autoResize(this)"
        ></textarea>
      </div>
      <button class="chat-send-btn" id="chat-send-btn" onclick="sendMessage()">
        <svg width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
          <line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/>
        </svg>
      </button>
    </div>

    <!-- Closed bar (hidden until closed) -->
    <div class="chat-closed-bar" id="chat-closed-bar" style="display:none;">
      🔒 Sesi chat telah ditutup.
      <button onclick="resetChat()" style="background:var(--ink);color:#fff;border:none;border-radius:8px;padding:5px 14px;font-weight:800;font-size:12px;font-family:inherit;cursor:pointer;margin-left:10px;">Chat Baru</button>
    </div>
  </div>

</div>

<script>
/* ═══════════════════════════════════════
   LIVECHAT CLIENT
═══════════════════════════════════════ */
let sessionKey   = null;
let currentMode  = 'ai';
let startMode    = 'ai';
let lastMsgId    = 0;
let pollTimer    = null;
let sessionStatus = 'open';
let isPolling    = false;

// ── DEBUG PANEL ──────────────────────────────────────────
const _debugEl = document.getElementById('lc-debug');
function dbg(msg, data) {
  const ts = new Date().toLocaleTimeString('id-ID');
  const str = data ? JSON.stringify(data) : '';
  console.log('[LiveChat]', msg, data ?? '');
  if (_debugEl) {
    const line = document.createElement('div');
    line.style.cssText = 'border-bottom:1px solid #333;padding:2px 0;word-break:break-all';
    line.innerHTML = `<span style="color:#aaa">${ts}</span> <span style="color:#4fc3f7">${msg}</span>` + (str ? ` <span style="color:#fff9c4">${str}</span>` : '');
    _debugEl.appendChild(line);
    _debugEl.scrollTop = _debugEl.scrollHeight;
  }
}
window.onerror = (msg, src, line, col, err) => {
  dbg('❌ JS Error: ' + msg, { src, line });
  return false;
};
window.addEventListener('unhandledrejection', e => dbg('❌ Promise rejected', String(e.reason)));

// log viewport info on load
dbg('init', {
  innerH: window.innerHeight,
  innerW: window.innerWidth,
  vvH: window.visualViewport?.height,
  dvh: CSS.supports('height','100dvh'),
  cookie: document.cookie.includes('chat_session')
});

// ── Mode selector on start screen ────────────────────────
function selectStartMode(mode) {
  startMode = mode;
  document.getElementById('start-mode-ai').classList.toggle('active', mode === 'ai');
  document.getElementById('start-mode-admin').classList.toggle('active', mode === 'admin');
  const desc = document.getElementById('start-mode-desc');
  if (mode === 'ai') {
    desc.textContent = 'AI akan menjawab pertanyaan Anda secara otomatis & instan.';
  } else {
    desc.textContent = 'Admin akan membalas pesan Anda langsung dari Telegram.';
  }
}

// ── Start session ─────────────────────────────────────────
async function startChat() {
  const btn = document.getElementById('btn-start-chat');
  btn.disabled = true;
  btn.innerHTML = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="animation:spin 1s linear infinite"><path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4"/></svg> Menghubungkan...';

  dbg('startChat()', { startMode });

  try {
    const fd = new FormData();
    fd.append('mode', startMode);
    const res = await fetch('/chat_action?action=start', { method: 'POST', body: fd, credentials: 'include' });
    dbg('start HTTP', { status: res.status, ok: res.ok });
    const data = await res.json();
    dbg('start response', data);
    if (!data.ok) throw new Error(data.error || 'Gagal memulai sesi.');

    sessionKey    = data.session_key;
    currentMode   = data.mode;
    sessionStatus = data.status;

    dbg('session created', { sessionKey, currentMode, sessionStatus, msgs: (data.messages||[]).length });

    // Show chat UI
    document.getElementById('chat-start-overlay').style.display = 'none';
    document.getElementById('chat-ui').style.display = 'flex';
    dbg('UI shown');

    // Render existing messages
    const msgs = document.getElementById('chat-messages');
    msgs.innerHTML = '';
    (data.messages || []).forEach(m => appendBubble(m.sender, m.message, m.created_at, false, m.attachment, m.id));

    // Mode sudah di-set di server, langsung update UI
    updateModeUI(currentMode);

    // Track lastMsgId dari DB id yg dikembalikan server (cegah double render saat poll)
    if (data.last_msg_id) lastMsgId = parseInt(data.last_msg_id);

    scrollBottom();
    startPolling();
    document.getElementById('chat-input').focus();

  } catch(e) {
    dbg('❌ startChat error', e.message);
    btn.disabled = false;
    btn.innerHTML = '💬 Mulai Chat';
    alert('❌ ' + e.message);
  }
}

// ── Append bubble ─────────────────────────────────────────
let _msgIdCounter = 0;
function appendBubble(sender, text, time, animate = true, attachment = null, serverId = null) {
  const msgs = document.getElementById('chat-messages');
  if (serverId && document.querySelector(`.bubble-wrap[data-server-id="${serverId}"]`)) return null;

  const wrap = document.createElement('div');
  const id   = ++_msgIdCounter;
  wrap.className   = `bubble-wrap ${sender === 'user' ? 'user' : sender === 'system' ? 'system' : sender}`;
  wrap.dataset.msgId = id;
  if (serverId) wrap.dataset.serverId = serverId;
  if (!animate) wrap.style.animation = 'none';

  const senderLabels = { ai: '🤖 AI', admin: '👨‍💼 Admin', user: '🙋 Kamu', system: '' };
  const senderClass  = { ai: 'sender-ai', admin: 'sender-admin', user: 'sender-user', system: '' };

  let labelName = senderLabels[sender] || sender;
  let actualText = text;

  if (sender === 'admin') {
      const match = text.match(/^\[(.*?)\]\s*(.*)$/is);
      if (match) {
          labelName = `👨‍💼 ${match[1]}`;
          actualText = match[2];
      }
  }

  const timeStr = time ? new Date(time).toLocaleTimeString('id-ID', {hour:'2-digit', minute:'2-digit'}) : '';
  const label   = sender !== 'system' && sender !== 'user' ? `<div class="bubble-sender-tag ${senderClass[sender]||''}">${escHtml(labelName)}</div>` : '';
  // AI dan admin pakai markdown, user dan system pakai plain text
  const bodyHtml = (sender === 'ai' || sender === 'admin') ? renderMarkdown(actualText) : (sender === 'system' ? escHtml(actualText) : escHtml(actualText) + '');

  let attHtml = '';
  if (attachment) {
      const ext = attachment.split('.').pop().toLowerCase();
      if (['jpg','jpeg','png','gif'].includes(ext)) {
          attHtml = `<div style="margin-bottom:6px;"><a href="/${attachment}" target="_blank"><img src="/${attachment}" style="max-width:100%; border-radius:8px; border:1px solid rgba(0,0,0,0.1);"></a></div>`;
      } else {
          attHtml = `<div style="margin-bottom:6px;"><a href="/${attachment}" target="_blank" style="display:inline-flex; align-items:center; gap:6px; padding:6px 10px; background:rgba(0,0,0,.05); border-radius:8px; text-decoration:none; color:inherit; border:1px solid rgba(0,0,0,.1); font-size:12px; font-weight:bold;">📎 Download Lampiran</a></div>`;
      }
  }

  wrap.innerHTML = `
    ${label}
    <div class="bubble">${attHtml}${bodyHtml}</div>
    ${sender !== 'system' ? `<div class="bubble-meta">${timeStr}</div>` : ''}
  `;
  msgs.appendChild(wrap);
  scrollBottom();
  return wrap;
}

function escHtml(s) {
  return String(s)
    .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

// ── Markdown renderer (untuk bubble AI & Admin) ───────────
function renderMarkdown(text) {
  let s = escHtml(text);
  // Headings: # Title
  s = s.replace(/^###\s+(.+)$/gm, '<strong style="font-size:13px;">$1</strong>');
  s = s.replace(/^##\s+(.+)$/gm,  '<strong style="font-size:14px;">$1</strong>');
  s = s.replace(/^#\s+(.+)$/gm,   '<strong style="font-size:15px;">$1</strong>');
  // Bold: **text** or __text__
  s = s.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
  s = s.replace(/__(.+?)__/g,     '<strong>$1</strong>');
  // Italic: *text* or _text_
  s = s.replace(/\*([^*\n]+?)\*/g, '<em>$1</em>');
  s = s.replace(/_([^_\n]+?)_/g,   '<em>$1</em>');
  // Inline code
  s = s.replace(/`([^`]+?)`/g, '<code style="background:rgba(0,0,0,.1);padding:1px 5px;border-radius:4px;font-size:12px;font-family:monospace;">$1</code>');
  // Numbered list: 1. item
  s = s.replace(/^(\d+)\.\s+(.+)$/gm, '<div style="display:flex;gap:6px;margin:2px 0;"><span style="font-weight:800;min-width:16px;">$1.</span><span>$2</span></div>');
  // Bullet list: - item or * item
  s = s.replace(/^[-*]\s+(.+)$/gm, '<div style="display:flex;gap:6px;margin:2px 0;"><span style="font-weight:900;">•</span><span>$1</span></div>');
  // Newlines
  s = s.replace(/\n/g, '<br>');
  return s;
}

function scrollBottom() {
  const msgs = document.getElementById('chat-messages');
  msgs.scrollTop = msgs.scrollHeight;
}

// ── Send message ──────────────────────────────────────────
function previewAttachment(input) {
  const file = input.files[0];
  if (file) {
      if (file.size > 5 * 1024 * 1024) {
          alert('Maksimal ukuran file adalah 5MB.');
          clearAttachment();
          return;
      }
      document.getElementById('attachment-preview').style.display = 'block';
      document.getElementById('att-preview-name').textContent = file.name;
  }
}
function clearAttachment() {
  const input = document.getElementById('chat-attachment-input');
  if (input) input.value = '';
  document.getElementById('attachment-preview').style.display = 'none';
}

async function sendMessage() {
  if (sessionStatus === 'closed') return;
  const input = document.getElementById('chat-input');
  const attInput = document.getElementById('chat-attachment-input');
  const text  = input.value.trim();
  const file = attInput && attInput.files[0] ? attInput.files[0] : null;

  if (!text && !file) return;

  const sendBtn = document.getElementById('chat-send-btn');
  input.value = '';
  input.style.height = '';
  sendBtn.disabled = true;

  if (file) {
      document.getElementById('attachment-preview').style.display = 'none';
      if (attInput) attInput.value = '';
  }

  let bubbleText = text;
  if (file && !text) bubbleText = '(Mengirim file...)';

  const tmpBubble = appendBubble('user', bubbleText, new Date().toISOString());

  // Show typing if AI mode
  if (currentMode === 'ai') showTyping(true);

  try {
    const fd = new FormData();
    fd.append('message', text);
    if (file) fd.append('attachment', file);
    fd.append('session_key', sessionKey);
    const res  = await fetch('/chat_action?action=send', { method:'POST', body:fd, credentials:'include' });
    const data = await res.json();
    showTyping(false);
    if (!data.ok) throw new Error(data.error || 'Gagal mengirim.');
    
    // Ganti bubble sementara dengan data asli (termasuk gambar) dari server
    tmpBubble.remove();
    if (data.user_message) {
        appendBubble('user', data.user_message.message, data.user_message.created_at, false, data.user_message.attachment, data.user_message.id);
    }

    if (data.last_msg_id) lastMsgId = parseInt(data.last_msg_id);
    if (data.reply) {
      appendBubble(data.reply.sender, data.reply.message, data.reply.created_at, true, data.reply.attachment, data.reply.id);
    }
  } catch(e) {
    tmpBubble.remove();
    showTyping(false);
    appendBubble('system', '⚠️ ' + e.message, new Date().toISOString());
  }

  sendBtn.disabled = false;
  input.focus();
}

function showTyping(show) {
  document.getElementById('typing-wrap').style.display = show ? 'block' : 'none';
  scrollBottom();
}

// ── Switch mode ───────────────────────────────────────────
async function switchMode(mode) {
  if (mode === currentMode) return;
  try {
    const fd = new FormData();
    fd.append('mode', mode);
    fd.append('session_key', sessionKey);
    const res  = await fetch('/chat_action?action=switch_mode', { method:'POST', body:fd, credentials:'include' });
    const data = await res.json();
    if (!data.ok) throw new Error(data.error);
    currentMode = data.mode;
    updateModeUI(currentMode);
    // Update lastMsgId agar poll tidak re-render divider switch
    if (data.switch_msg_id) lastMsgId = parseInt(data.switch_msg_id);
    // Tampilkan divider visual mode switch
    if (data.switch_message) appendModeDivider(data.mode, data.switch_message);
  } catch(e) {
    alert('Gagal beralih mode: ' + e.message);
  }
}

// ── Mode divider (pemisah visual antar section) ────────
function appendModeDivider(mode, label) {
  const msgs  = document.getElementById('chat-messages');
  const el    = document.createElement('div');
  const color = mode === 'ai' ? 'var(--lavender)' : 'var(--mint)';
  el.style.cssText = 'display:flex;align-items:center;gap:8px;margin:10px 0;';
  el.innerHTML = `
    <div style="flex:1;height:2px;border-radius:2px;background:${color};border:1px solid var(--ink);"></div>
    <span style="font-size:10px;font-weight:900;background:${color};border:1.5px solid var(--ink);padding:3px 10px;border-radius:20px;white-space:nowrap;box-shadow:2px 2px 0 var(--ink);">${escHtml(label)}</span>
    <div style="flex:1;height:2px;border-radius:2px;background:${color};border:1px solid var(--ink);"></div>
  `;
  msgs.appendChild(el);
  scrollBottom();
}

function updateModeUI(mode) {
  const aiBtn  = document.getElementById('modebtn-ai');
  const admBtn = document.getElementById('modebtn-admin');
  if (aiBtn)  aiBtn.classList.toggle('active', mode === 'ai');
  if (admBtn) admBtn.classList.toggle('active', mode === 'admin');

  const banner = document.getElementById('mode-info-banner');
  const bannerText = document.getElementById('mode-info-text');
  if (!banner || !bannerText) return;
  if (mode === 'ai') {
    banner.className = 'mode-info-banner ai-mode';
    bannerText.innerHTML = '🤖 Mode AI aktif &mdash; Dijawab otomatis oleh Asisten AI.';
  } else {
    banner.className = 'mode-info-banner adm-mode';
    bannerText.innerHTML = '👨‍💼 Mode Admin aktif &mdash; Tim kami akan segera membalas.';
  }
}

// ── Polling (near-realtime, 2s interval) ─────────────────
function startPolling() {
  if (pollTimer) clearInterval(pollTimer);
  pollTimer = setInterval(pollMessages, 2000);
}

// Pause saat tab tidak aktif, resume saat aktif (hemat request)
document.addEventListener('visibilitychange', () => {
  if (document.hidden) {
    clearInterval(pollTimer);
  } else if (sessionKey && sessionStatus !== 'closed') {
    pollMessages();      // langsung poll saat balik ke tab
    startPolling();
  }
});

async function pollMessages() {
  if (!sessionKey || sessionStatus === 'closed' || isPolling) return;
  isPolling = true;
  try {
    const res  = await fetch(`/chat_action?action=poll&session_key=${sessionKey}&after_id=${lastMsgId}`, { credentials:'include' });
    const data = await res.json();
    if (!data.ok) {
        isPolling = false;
        return;
    }

    (data.messages || []).forEach(m => {
      if (parseInt(m.id) > lastMsgId) {
        lastMsgId = parseInt(m.id);
        appendBubble(m.sender, m.message, m.created_at, true, m.attachment, m.id);
      }
    });

    if (data.status === 'closed' && sessionStatus !== 'closed') {
      sessionStatus = 'closed';
      onSessionClosed();
    }
    if (data.mode && data.mode !== currentMode) {
      currentMode = data.mode;
      updateModeUI(currentMode);
    }
  } catch (e) {
    dbg('Poll fetch error', e.message);
  }
  isPolling = false;
}

function onSessionClosed() {
  clearInterval(pollTimer);
  document.getElementById('chat-inputbar').style.display = 'none';
  document.getElementById('chat-closed-bar').style.display = 'block';
  document.getElementById('chat-status-badge').className = 'chat-status-badge busy';
  document.getElementById('chat-status-badge').textContent = 'Ditutup';
}

// ── Reset session ─────────────────────────────────────────
async function resetChat() {
  await fetch('/chat_action?action=close', { method:'POST', credentials:'include' });
  document.cookie = 'chat_session=; max-age=0; path=/';
  location.reload();
}

// ── Auto-resize textarea ──────────────────────────────────
function autoResize(el) {
  el.style.height = '';
  el.style.height = Math.min(el.scrollHeight, 120) + 'px';
}

function handleKey(e) {
  if (e.key === 'Enter' && !e.shiftKey) {
    e.preventDefault();
    sendMessage();
  }
}

// ── Init: check for existing session cookie ───────────────
(function() {
  const hasCookie = document.cookie.split(';').some(c => c.trim().startsWith('chat_session='));
  if (hasCookie) {
    startChat();
  }

  // ── Keyboard handler: update #chat-root bottom when keyboard appears ──
  // Dengan position:fixed, kita tinggal adjust 'bottom' = window.innerHeight - visualViewport.height
  const chatRoot = document.getElementById('chat-root');
  function onVpChange() {
    if (!chatRoot) return;
    const vvH   = window.visualViewport?.height ?? window.innerHeight;
    const vvTop = window.visualViewport?.offsetTop ?? 0;
    // bottom = space occupied by keyboard
    const kbHeight = window.innerHeight - vvH - vvTop;
    chatRoot.style.bottom = Math.max(0, kbHeight) + 'px';
    dbg('vpChange', { vvH, kbHeight, innerH: window.innerHeight });
    scrollBottom();
  }

  if (window.visualViewport) {
    window.visualViewport.addEventListener('resize', onVpChange);
    window.visualViewport.addEventListener('scroll', onVpChange);
  }
  window.addEventListener('resize', onVpChange);
  onVpChange();

  // iOS: scroll to bottom setelah keyboard animation selesai
  const chatInput = document.getElementById('chat-input');
  if (chatInput) {
    chatInput.addEventListener('focus', () => setTimeout(() => { onVpChange(); scrollBottom(); }, 350));
    chatInput.addEventListener('blur',  () => setTimeout(onVpChange, 350));
  }
})();
</script>

<style>
@keyframes spin {
  to { transform: rotate(360deg); }
}
</style>

<script>
// PHP settings passed to JS
const LC_AI_ENABLED  = <?= $_ai_enabled ? 'true' : 'false' ?>;
const LC_ADM_ENABLED = <?= $_adm_enabled ? 'true' : 'false' ?>;
// Force start mode if only one mode available
if (!LC_AI_ENABLED)  startMode = 'admin';
if (!LC_ADM_ENABLED) startMode = 'ai';
</script>

<script src="/assets/js/toast.js"></script>
<?php endif; ?>
</body>
</html>
