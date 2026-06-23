<?php
declare(strict_types=1);
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/auth.php';
staff_require('livechat');
$pageTitle  = 'Live Chat';
$activePage = 'livechat';

// ── Handle form saves ────────────────────────────────────────
$saved = false; $err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tab = $_POST['tab'] ?? 'settings';

    if ($tab === 'settings') {
        $keys = [
            'lc_tg_token','lc_tg_chat_id','lc_tg_forum',
            'openai_api_key','openai_model','ai_system_prompt',
            'chat_welcome_msg','chat_ai_enabled','chat_admin_enabled','chat_admin_name','livechat_enabled','lc_site_url',
            'lc_debug_panel','lc_attachment_enabled','lc_offline_msg',
        ];
        if (!isset($_POST['lc_attachment_enabled'])) $_POST['lc_attachment_enabled'] = '0';
        if (!isset($_POST['livechat_enabled'])) $_POST['livechat_enabled'] = '0';
        if (!isset($_POST['chat_ai_enabled'])) $_POST['chat_ai_enabled'] = '0';
        if (!isset($_POST['chat_admin_enabled'])) $_POST['chat_admin_enabled'] = '0';
        if (!isset($_POST['lc_debug_panel'])) $_POST['lc_debug_panel'] = '0';

        foreach ($keys as $k) {
            $v = trim($_POST[$k] ?? '');
            $pdo->prepare("INSERT INTO settings (`key`,`value`) VALUES (?,?) ON DUPLICATE KEY UPDATE `value`=?")
                ->execute([$k, $v, $v]);
        }
        $saved = true;
    }

    // Sync Webhook via CURL dari server
    if ($tab === 'sync_webhook') {
        $token   = setting($pdo, 'lc_tg_token', '');
        $siteUrl = rtrim(setting($pdo, 'lc_site_url', ''), '/');
        if (!$siteUrl) {
            // Fallback ke HTTP_HOST
            $scheme  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $siteUrl = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? '');
        }
        $webhookTarget = $siteUrl . '/chat_action?action=tg_webhook';
        
        if (!$token) {
            $_SESSION['wh_result'] = ['ok' => false, 'error' => 'Bot Token belum diisi di Pengaturan.'];
        } else {
            $ch = curl_init("https://api.telegram.org/bot{$token}/setWebhook");
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => json_encode(['url' => $webhookTarget, 'allowed_updates' => ['message', 'callback_query']]),
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
                CURLOPT_TIMEOUT        => 15,
            ]);
            $res = curl_exec($ch);
            $err = curl_error($ch);
            curl_close($ch);
            if ($err) {
                $_SESSION['wh_result'] = ['ok' => false, 'error' => 'CURL Error: ' . $err];
            } else {
                $data = json_decode($res ?: '{}', true);
                $_SESSION['wh_result'] = array_merge($data, ['webhook_url' => $webhookTarget]);
            }
        }
        header('Location: /console/livechat.php?t=webhook'); exit;
    }

    // Check Webhook Info
    if ($tab === 'check_webhook') {
        $token = setting($pdo, 'lc_tg_token', '');
        if (!$token) {
            $_SESSION['wh_info'] = ['ok' => false, 'error' => 'Bot Token belum diisi.'];
        } else {
            $ch = curl_init("https://api.telegram.org/bot{$token}/getWebhookInfo");
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
            $res = curl_exec($ch);
            $err = curl_error($ch);
            curl_close($ch);
            $_SESSION['wh_info'] = $err ? ['ok' => false, 'error' => $err] : (json_decode($res ?: '{}', true) ?: []);
        }
        header('Location: /console/livechat.php?t=webhook'); exit;
    }

    // Close a session
    if ($tab === 'close_session') {
        $sid = (int)($_POST['session_id'] ?? 0);
        if ($sid) {
            $reason = trim($_POST['close_reason'] ?? '');
            $closeMsg = $reason ? "Sesi ditutup oleh Admin. Alasan: {$reason}" : "Sesi ditutup oleh Admin.";
            $pdo->prepare("UPDATE chat_sessions SET status='closed' WHERE id=?")->execute([$sid]);
            $pdo->prepare("INSERT INTO chat_messages (session_id,sender,message) VALUES (?, 'system', ?)")->execute([$sid, $closeMsg]);
        }
    }

    // Reset ALL chat sessions
    if ($tab === 'reset_all_sessions') {
        $chatId = setting($pdo, 'lc_tg_chat_id', '');
        $token  = setting($pdo, 'lc_tg_token', '');
        // Delete all Telegram topics created by bot
        if ($chatId && $token) {
            $threads = $pdo->query("SELECT tg_thread_id FROM chat_sessions WHERE tg_thread_id IS NOT NULL")->fetchAll();
            foreach ($threads as $t) {
                if ((int)$t['tg_thread_id'] > 0) {
                    $ch = curl_init("https://api.telegram.org/bot{$token}/deleteForumTopic");
                    curl_setopt_array($ch, [
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_POST           => true,
                        CURLOPT_POSTFIELDS     => json_encode(['chat_id' => $chatId, 'message_thread_id' => (int)$t['tg_thread_id']]),
                        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
                        CURLOPT_TIMEOUT        => 5,
                    ]);
                    curl_exec($ch); curl_close($ch);
                }
            }
        }
        $pdo->exec("DELETE FROM chat_messages");
        $pdo->exec("DELETE FROM chat_sessions");
        $_SESSION['wh_result'] = ['ok' => true, 'webhook_url' => '(reset) Semua sesi dan topics berhasil dihapus.'];
        header('Location: /console/livechat.php?t=webhook'); exit;
    }

    // Delete all Telegram topics only (keep DB sessions)
    if ($tab === 'delete_tg_topics') {
        $chatId = setting($pdo, 'lc_tg_chat_id', '');
        $token  = setting($pdo, 'lc_tg_token', '');
        $deleted = 0; $failed = 0;
        if ($chatId && $token) {
            $threads = $pdo->query("SELECT id, tg_thread_id FROM chat_sessions WHERE tg_thread_id IS NOT NULL")->fetchAll();
            foreach ($threads as $t) {
                if ((int)$t['tg_thread_id'] <= 0) continue;
                $ch = curl_init("https://api.telegram.org/bot{$token}/deleteForumTopic");
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POST           => true,
                    CURLOPT_POSTFIELDS     => json_encode(['chat_id' => $chatId, 'message_thread_id' => (int)$t['tg_thread_id']]),
                    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
                    CURLOPT_TIMEOUT        => 5,
                ]);
                $res  = curl_exec($ch); curl_close($ch);
                $data = json_decode($res ?: '{}', true);
                if (!empty($data['ok'])) {
                    $pdo->prepare("UPDATE chat_sessions SET tg_thread_id=NULL WHERE id=?")->execute([$t['id']]);
                    $deleted++;
                } else {
                    $failed++;
                }
            }
        }
        $_SESSION['wh_result'] = ['ok' => true, 'webhook_url' => "Topics dihapus: {$deleted}, gagal: {$failed}. (Sesi DB tetap ada)"];
        header('Location: /console/livechat.php?t=webhook'); exit;
    }

    // Nuclear: delete ALL topics (brute force range) — General is protected by Telegram
    if ($tab === 'nuclear_clear_topics') {
        header('Content-Type: application/json');
        $chatId = setting($pdo, 'lc_tg_chat_id', '');
        $token  = setting($pdo, 'lc_tg_token', '');
        if (!$chatId || !$token) {
            echo json_encode(['ok' => false, 'error' => 'Token/ChatID belum diset']);
            exit;
        }
        // Determine range: max tg_thread_id in DB + buffer, min 500
        $maxDb = (int)$pdo->query("SELECT COALESCE(MAX(tg_thread_id),0) FROM chat_sessions WHERE tg_thread_id IS NOT NULL")->fetchColumn();
        $maxRange = max($maxDb + 200, 500);
        $deleted = 0; $failed = 0;
        set_time_limit(120);
        for ($tid = 2; $tid <= $maxRange; $tid++) {
            $ch = curl_init("https://api.telegram.org/bot{$token}/deleteForumTopic");
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => json_encode(['chat_id' => $chatId, 'message_thread_id' => $tid]),
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
                CURLOPT_TIMEOUT        => 4,
            ]);
            $res  = curl_exec($ch); curl_close($ch);
            $data = json_decode($res ?: '{}', true);
            if (!empty($data['ok'])) { $deleted++; } else { $failed++; }
            // Telegram rate limit: max ~30 req/s — small sleep every 10
            if ($tid % 10 === 0) usleep(300000); // 300ms per 10 requests
        }
        // Clear tg_thread_id in DB for all sessions
        $pdo->exec("UPDATE chat_sessions SET tg_thread_id=NULL");
        echo json_encode(['ok' => true, 'deleted' => $deleted, 'failed' => $failed, 'range' => "2–{$maxRange}"]);
        exit;
    }

    // Bulk delete sessions
    if ($tab === 'bulk_delete') {
        $ids = array_filter(array_map('intval', (array)($_POST['session_ids'] ?? [])));
        if ($ids) {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $pdo->prepare("DELETE FROM chat_messages WHERE session_id IN ({$ph})")->execute($ids);
            $pdo->prepare("DELETE FROM chat_sessions WHERE id IN ({$ph})")->execute($ids);
        }
        header('Location: /console/livechat.php'); exit;
    }
}

// ── Load settings ─────────────────────────────────────────────
$cfg = [];
foreach ([
    'lc_tg_token','lc_tg_chat_id','lc_tg_forum',
    'openai_api_key','openai_model','ai_system_prompt',
    'chat_welcome_msg','chat_ai_enabled','chat_admin_enabled','chat_admin_name','livechat_enabled','lc_site_url',
    'lc_debug_panel','lc_attachment_enabled','lc_offline_msg',
] as $k) { $cfg[$k] = setting($pdo, $k, ''); }
if (empty($cfg['chat_admin_name'])) $cfg['chat_admin_name'] = 'Admin';
if (empty($cfg['livechat_enabled'])) $cfg['livechat_enabled'] = '1';
if (!isset($cfg['lc_attachment_enabled']) || $cfg['lc_attachment_enabled'] === '') $cfg['lc_attachment_enabled'] = '1';

// ── Load sessions ─────────────────────────────────────────────
$sessions = $pdo->query(
    "SELECT s.*, 
        (SELECT COUNT(*) FROM chat_messages m WHERE m.session_id=s.id) as msg_count,
        (SELECT message FROM chat_messages m WHERE m.session_id=s.id ORDER BY m.id DESC LIMIT 1) as last_msg
     FROM chat_sessions s ORDER BY s.last_message_at DESC LIMIT 60"
)->fetchAll();

// ── Active session detail ──────────────────────────────────────
$viewId = (int)($_GET['view'] ?? 0);
$viewMsgs = [];
$viewSess = null;
if ($viewId) {
    $vs = $pdo->prepare("SELECT * FROM chat_sessions WHERE id=?");
    $vs->execute([$viewId]);
    $viewSess = $vs->fetch() ?: null;
    if ($viewSess) {
        $vm = $pdo->prepare("SELECT * FROM chat_messages WHERE session_id=? ORDER BY id ASC");
        $vm->execute([$viewId]);
        $viewMsgs = $vm->fetchAll();
    }
}

// ── Admin reply via AJAX (dipanggil dari JS) ──────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['tab'] ?? '') === 'reply') {
    header('Content-Type: application/json');
    $sid      = (int)($_POST['session_id'] ?? 0);
    $msg      = trim($_POST['reply_msg'] ?? '');
    $adminName = setting($pdo, 'chat_admin_name', 'Admin') ?: 'Admin';
    
    $attachmentPath = null;
    if (setting($pdo, 'lc_attachment_enabled', '1') === '1' && !empty($_FILES['attachment']['tmp_name'])) {
        $f = $_FILES['attachment'];
        if ($f['error'] === UPLOAD_ERR_OK && $f['size'] <= 5*1024*1024) {
            $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg','jpeg','png','gif','pdf','zip','rar'])) {
                $dir = __DIR__ . '/../uploads/chat/' . date('Y/m');
                if (!is_dir($dir)) mkdir($dir, 0777, true);
                $filename = uniqid('att_') . '.' . $ext;
                if (move_uploaded_file($f['tmp_name'], $dir . '/' . $filename)) {
                    $attachmentPath = 'uploads/chat/' . date('Y/m') . '/' . $filename;
                }
            }
        }
    }

    if (!$sid || (!$msg && !$attachmentPath)) { echo json_encode(['ok'=>false,'error'=>'Invalid']); exit; }
    
    $fullMsg = $msg ? "[{$adminName}] {$msg}" : "[{$adminName}] Mengirim lampiran";
    $pdo->prepare("INSERT INTO chat_messages (session_id,sender,message,attachment) VALUES (?,'admin',?,?)")
        ->execute([$sid, $fullMsg, $attachmentPath]);
    $newId = (int)$pdo->lastInsertId();
    $pdo->prepare("UPDATE chat_sessions SET last_message_at=NOW() WHERE id=?")->execute([$sid]);
    
    // Kirim ke Telegram
    $sess = $pdo->prepare("SELECT * FROM chat_sessions WHERE id=?");
    $sess->execute([$sid]); $sessRow = $sess->fetch();
    $token = setting($pdo, 'lc_tg_token', '');
    $chatId = setting($pdo, 'lc_tg_chat_id', '');
    if ($token && $chatId && $sessRow) {
        $params = ['chat_id' => $chatId];
        if ($sessRow['tg_thread_id']) $params['message_thread_id'] = (int)$sessRow['tg_thread_id'];
        
        if ($attachmentPath) {
            $ext = strtolower(pathinfo($attachmentPath, PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg','jpeg','png','gif'])) {
                $method = 'sendPhoto';
                $params['photo'] = new CURLFile(__DIR__ . '/../' . $attachmentPath);
                $params['caption'] = "🖥️ {$adminName}: {$msg}";
            } else {
                $method = 'sendDocument';
                $params['document'] = new CURLFile(__DIR__ . '/../' . $attachmentPath);
                $params['caption'] = "🖥️ {$adminName}: {$msg}";
            }
            $ch = curl_init("https://api.telegram.org/bot{$token}/{$method}");
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>$params]);
            curl_exec($ch); curl_close($ch);
        } else {
            $params['text'] = "🖥️ {$adminName}: {$msg}";
            $ch = curl_init("https://api.telegram.org/bot{$token}/sendMessage");
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,
                CURLOPT_POSTFIELDS=>json_encode($params),CURLOPT_HTTPHEADER=>['Content-Type: application/json']]);
            curl_exec($ch); curl_close($ch);
        }
    }
    echo json_encode(['ok'=>true,'id'=>$newId,'message'=>$fullMsg,'attachment'=>$attachmentPath,'created_at'=>date('Y-m-d H:i:s')]);
    exit;
}

// ── Console poll new messages ──────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'console_poll') {
    header('Content-Type: application/json');
    $sid     = (int)($_GET['session_id'] ?? 0);
    $afterId = (int)($_GET['after_id'] ?? 0);
    if (!$sid) { echo json_encode(['ok'=>false]); exit; }
    $rows = $pdo->prepare("SELECT id,sender,message,attachment,created_at FROM chat_messages WHERE session_id=? AND id>? ORDER BY id ASC LIMIT 50");
    $rows->execute([$sid, $afterId]);
    echo json_encode(['ok'=>true,'messages'=>$rows->fetchAll(PDO::FETCH_ASSOC)]);
    exit;
}

require_once __DIR__ . '/partials/header.php';
?>

<style>
.lc-tabs { display:flex; gap:4px; border-bottom:1px solid #1f2235; margin-bottom:20px; }
.lc-tab  { padding:10px 18px; font-size:13px; font-weight:600; color:#666; cursor:pointer; border-bottom:2px solid transparent; text-decoration:none; }
.lc-tab.active { color:var(--brand); border-bottom-color:var(--brand); }

.sess-row { display:flex; align-items:center; gap:10px; padding:10px 0; border-bottom:1px solid #1a1d27; }
.sess-row:last-child { border-bottom:none; }
.sess-row.selected { background:rgba(66,133,244,.07); border-radius:8px; }
.sess-cb { accent-color:#4285F4; width:15px; height:15px; cursor:pointer; flex-shrink:0; }
.sess-avatar { width:36px;height:36px;border-radius:50%;background:var(--brand);display:flex;align-items:center;justify-content:center;font-weight:800;font-size:14px;color:#fff;flex-shrink:0; }
.sess-body { flex:1;min-width:0; }
.sess-name { font-size:13.5px;font-weight:700;color:#e0e0f0; }
.sess-last { font-size:12px;color:#555;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-top:2px; }
.sess-right { text-align:right;flex-shrink:0; }
.sess-badge { display:inline-block;font-size:10px;font-weight:700;padding:2px 8px;border-radius:10px; }
.sess-badge.open   { background:rgba(76,175,130,.2);color:#4CAF82; }
.sess-badge.closed { background:rgba(255,255,255,.07);color:#555; }
.sess-mode { font-size:10px;color:#555;margin-top:3px; }
.bulk-bar { display:none;align-items:center;gap:8px;padding:8px 12px;background:rgba(66,133,244,.1);border:1px solid rgba(66,133,244,.25);border-radius:8px;margin-bottom:10px;font-size:12px;color:#a0b4f0; }
.bulk-bar.visible { display:flex; }

.msg-bubble { min-width:60px;max-width:75%;padding:9px 13px;border-radius:12px;font-size:13px;line-height:1.5;word-break:break-word;white-space:pre-wrap; }
.msg-user  .msg-bubble { background:#2a2d3e;color:#ddd; border-radius:12px 12px 4px 12px; }
.msg-ai    .msg-bubble { background:rgba(196,181,253,.15);color:#c4b5fd; border-radius:12px 12px 12px 4px; }
.msg-admin .msg-bubble { background:rgba(168,240,220,.12);color:#a8f0dc; border-radius:12px 12px 12px 4px; }
.msg-system .msg-bubble { background:transparent;color:#555;font-size:11px;font-style:italic;text-align:center;min-width:0; }
.msg-row { display:flex;margin-bottom:8px; }
.msg-row.msg-user  { justify-content:flex-end; }
.msg-row.msg-system{ justify-content:center; }
.msg-time { font-size:10px;color:#444;margin-top:3px; }
.msg-row.msg-user .msg-time { text-align:right; }

.detail-panel { background:#131520;border:1px solid #1f2235;border-radius:12px;overflow:hidden; }
.detail-header { padding:14px 18px;border-bottom:1px solid #1f2235;display:flex;align-items:center;gap:10px; }
.detail-msgs { padding:14px;max-height:400px;overflow-y:auto;display:flex;flex-direction:column;gap:2px; }
.detail-msgs::-webkit-scrollbar{width:4px} .detail-msgs::-webkit-scrollbar-thumb{background:#2a2d3e;border-radius:4px;}
.detail-reply { padding:14px;border-top:1px solid #1f2235; }

.webhook-url { background:#0f1117;border:1px solid #1f2235;border-radius:8px;padding:10px 14px;font-size:12px;font-family:monospace;color:#a8f0dc;word-break:break-all; }
</style>

<?php if ($saved): ?>
<div class="alert alert-success mb-3" style="background:rgba(76,175,130,.15);border:1px solid rgba(76,175,130,.3);color:#4CAF82;padding:10px 16px;border-radius:8px;font-size:13px;">
  ✅ Pengaturan berhasil disimpan.
</div>
<?php endif; ?>
<?php if (!empty($_GET['replied'])): ?>
<div class="alert alert-success mb-3" style="background:rgba(76,175,130,.15);border:1px solid rgba(76,175,130,.3);color:#4CAF82;padding:10px 16px;border-radius:8px;font-size:13px;">
  ✅ Balasan berhasil dikirim.
</div>
<?php endif; ?>

<div class="lc-tabs">
  <a href="/console/livechat.php" class="lc-tab <?= !$viewId && ($_GET['t']??'sessions')==='sessions' ? 'active':'' ?>">💬 Sesi Chat</a>
  <a href="/console/livechat.php?t=settings" class="lc-tab <?= ($_GET['t']??'')==='settings' ? 'active':'' ?>">⚙️ Pengaturan</a>
  <a href="/console/livechat.php?t=webhook" class="lc-tab <?= ($_GET['t']??'')==='webhook' ? 'active':'' ?>">🔗 Webhook Info</a>
</div>

<?php if (($viewId && $viewSess) || false): /* DETAIL VIEW */ ?>
<!-- handled below -->
<?php endif; ?>

<?php $activeTab = $_GET['t'] ?? ($viewId ? 'view' : 'sessions'); ?>

<!-- ═══ TAB: SESSIONS ══════════════════════════════════════ -->
<?php if ($activeTab === 'sessions' || $viewId): ?>
<div class="row g-3">
  <!-- Session list -->
  <div class="<?= $viewId ? 'col-lg-4' : 'col-12' ?>">
    <div class="c-card">
      <div class="c-card-header">
        <span class="c-card-title">Sesi Chat</span>
        <span style="font-size:12px;color:#555;"><?= count($sessions) ?> sesi</span>
      </div>
      <div class="c-card-body" style="padding:0 20px;">

        <!-- Bulk action toolbar -->
        <form method="post" id="bulk-form">
          <input type="hidden" name="tab" value="bulk_delete">

          <div class="bulk-bar" id="bulk-bar">
            <input type="checkbox" id="cb-all" class="sess-cb" onchange="toggleAll(this)" title="Pilih semua">
            <span id="bulk-count">0 dipilih</span>
            <button type="submit" onclick="return confirmDelete()"
              style="background:rgba(244,78,59,.2);border:1px solid rgba(244,78,59,.4);color:#F44E3B;padding:4px 14px;border-radius:6px;font-size:12px;font-weight:700;cursor:pointer;margin-left:auto;">
              🗑️ Hapus Terpilih
            </button>
          </div>

        <?php if (empty($sessions)): ?>
          <p style="color:#555;font-size:13px;padding:20px 0;text-align:center;">Belum ada sesi chat.</p>
        <?php else: ?>
          <?php foreach ($sessions as $s): ?>
          <div class="sess-row" id="sess-row-<?= $s['id'] ?>">
            <input type="checkbox" name="session_ids[]" value="<?= $s['id'] ?>" class="sess-cb sess-check"
              onchange="onCheckChange()">
            <div class="sess-avatar"><?= strtoupper(substr($s['user_name'],0,1)) ?></div>
            <div class="sess-body">
              <div class="sess-name"><?= htmlspecialchars($s['user_name']) ?>
                <?php if ($s['user_email']): ?><span style="color:#555;font-size:11px;font-weight:400;"> — <?= htmlspecialchars($s['user_email']) ?></span><?php endif; ?>
              </div>
              <div class="sess-last"><?= htmlspecialchars(mb_substr($s['last_msg']??'(kosong)',0,60)) ?></div>
            </div>
            <div class="sess-right">
              <span class="sess-badge <?= $s['status'] ?>"><?= $s['status'] ?></span>
              <div class="sess-mode">🤖 <?= $s['mode'] ?> · <?= $s['msg_count'] ?> pesan</div>
              <div style="margin-top:5px;display:flex;gap:4px;justify-content:flex-end;">
                <a href="/console/livechat.php?view=<?= $s['id'] ?>" class="btn btn-sm"
                   style="background:#1f2235;border:1px solid #2a2d3e;color:#ccc;padding:3px 10px;font-size:11px;border-radius:6px;text-decoration:none;">Detail</a>
                <?php if ($s['status']==='open'): ?>
                 <form method="post" style="margin:0;" onsubmit="return promptCloseSession(this);">
                   <input type="hidden" name="tab" value="close_session">
                   <input type="hidden" name="session_id" value="<?= $s['id'] ?>">
                   <input type="hidden" name="close_reason" value="">
                   <button type="submit"
                     style="background:rgba(244,78,59,.15);border:1px solid rgba(244,78,59,.3);color:#F44E3B;padding:3px 10px;font-size:11px;border-radius:6px;cursor:pointer;">Tutup</button>
                 </form>
                <?php endif; ?>
              </div>
            </div>
          </div>
          <?php endforeach; ?>
        <?php endif; ?>
        </form>

      </div>
    </div>
  </div>

  <script>
  // Multi-select logic
  function onCheckChange() {
    const checks = document.querySelectorAll('.sess-check');
    const checked = document.querySelectorAll('.sess-check:checked');
    const bar = document.getElementById('bulk-bar');
    const cbAll = document.getElementById('cb-all');
    document.getElementById('bulk-count').textContent = checked.length + ' dipilih';
    bar.classList.toggle('visible', checked.length > 0);
    cbAll.indeterminate = checked.length > 0 && checked.length < checks.length;
    cbAll.checked = checked.length === checks.length && checks.length > 0;
    // Highlight selected rows
    checks.forEach(c => c.closest('.sess-row')?.classList.toggle('selected', c.checked));
  }
  function toggleAll(cb) {
    document.querySelectorAll('.sess-check').forEach(c => {
      c.checked = cb.checked;
      c.closest('.sess-row')?.classList.toggle('selected', cb.checked);
    });
    onCheckChange();
  }
  function confirmDelete() {
    const n = document.querySelectorAll('.sess-check:checked').length;
    return n > 0 && confirm('Hapus ' + n + ' sesi beserta semua pesannya? Tindakan ini tidak bisa dibatalkan.');
  }
  // Show bulk bar only when there are sessions
  document.addEventListener('DOMContentLoaded', () => {
    if (document.querySelectorAll('.sess-check').length > 0) {
      document.getElementById('bulk-bar').style.display = 'flex';
      document.getElementById('bulk-bar').classList.remove('visible');
      // Actually keep hidden until at least 1 checked — reset to hidden
      document.getElementById('bulk-bar').style.display = '';
    }
  });

  function promptCloseSession(form) {
    const reason = prompt("Masukkan alasan penutupan sesi chat (opsional/bisa dikosongkan):", "");
    if (reason === null) return false; // Cancel clicked
    form.querySelector('input[name="close_reason"]').value = reason.trim();
    return true;
  }
  </script>

  <!-- Detail panel -->
  <?php if ($viewId && $viewSess): ?>
  <div class="col-lg-8">
    <div class="detail-panel" id="detail-panel">
      <div class="detail-header">
        <div class="sess-avatar"><?= strtoupper(substr($viewSess['user_name'],0,1)) ?></div>
        <div style="flex:1;">
          <div style="font-weight:700;font-size:14px;color:#e0e0f0;"><?= htmlspecialchars($viewSess['user_name']) ?></div>
          <div style="font-size:11px;color:#555;">
            <?= htmlspecialchars($viewSess['user_email']??'-') ?> &nbsp;&middot;&nbsp;
            Mode: <strong style="color:#a8f0dc" id="dp-mode"><?= $viewSess['mode'] ?></strong> &nbsp;&middot;&nbsp;
            Status: <strong style="color:<?= $viewSess['status']==='open'?'#4CAF82':'#555' ?>" id="dp-status"><?= $viewSess['status'] ?></strong>
          </div>
        </div>
        <a href="/console/livechat.php" style="color:#555;font-size:20px;text-decoration:none;line-height:1;">&times;</a>
      </div>

      <div class="detail-msgs" id="detail-msgs">
        <?php foreach ($viewMsgs as $m): ?>
        <div class="msg-row msg-<?= $m['sender'] ?>" data-id="<?= $m['id'] ?>">
          <div>
            <div class="msg-bubble">
              <?php if ($m['attachment']): ?>
                <?php $ext = strtolower(pathinfo($m['attachment'], PATHINFO_EXTENSION)); ?>
                <?php if (in_array($ext, ['jpg','jpeg','png','gif'])): ?>
                  <div style="margin-bottom:6px;"><a href="/<?= $m['attachment'] ?>" target="_blank"><img src="/<?= $m['attachment'] ?>" style="max-width:100%; border-radius:8px; border:1px solid rgba(255,255,255,0.1);"></a></div>
                <?php else: ?>
                  <div style="margin-bottom:6px;"><a href="/<?= $m['attachment'] ?>" target="_blank" style="display:inline-flex; align-items:center; gap:6px; padding:6px 10px; background:rgba(255,255,255,.1); border-radius:8px; text-decoration:none; color:inherit; border:1px solid rgba(255,255,255,.2); font-size:12px; font-weight:bold;">📎 Download Lampiran</a></div>
                <?php endif; ?>
              <?php endif; ?>
              <?= nl2br(htmlspecialchars($m['message'])) ?>
            </div>
            <div class="msg-time"><?= date('H:i', strtotime($m['created_at'])) ?></div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>

      <?php if ($viewSess['status']==='open'): ?>
      <div class="detail-reply" id="detail-reply">
        <div style="display:flex;gap:8px;align-items:flex-end;">
          <div style="flex:1; display:flex; flex-direction:column; gap:4px; position:relative;">
            <div id="console-attachment-preview" style="display:none; font-size:11px; background:#1f2235; color:#a0b4f0; border-radius:8px; padding:4px 8px; border:1px solid #2a2d3e;">
                <span id="console-att-preview-name" style="font-weight:600;"></span>
                <span style="color:#F44E3B; cursor:pointer; float:right; padding: 0 4px;" onclick="clearConsoleAttachment()">&times; Hapus</span>
            </div>
            <textarea id="console-reply-input" class="c-form-control" rows="2"
              placeholder="Ketik balasan sebagai <?= htmlspecialchars($cfg['chat_admin_name']) ?>..."
              style="width:100%;resize:none;"
              onkeydown="if(event.key==='Enter'&&!event.shiftKey){event.preventDefault();sendConsoleReply();}"
            ></textarea>
          </div>
          <?php if (setting($pdo, 'lc_attachment_enabled', '1') === '1'): ?>
          <button onclick="document.getElementById('console-attachment-input').click()" style="background:#2a2d3e;border:1px solid #1f2235;color:#fff;padding:10px 14px;border-radius:8px;font-size:16px;cursor:pointer;height:fit-content;white-space:nowrap;">📎</button>
          <input type="file" id="console-attachment-input" style="display:none;" accept="image/jpeg,image/png,image/gif,application/pdf,.zip,.rar" onchange="previewConsoleAttachment(this)">
          <?php endif; ?>
          <button onclick="sendConsoleReply()" id="console-reply-btn"
            style="background:var(--brand);border:none;color:#fff;padding:10px 18px;border-radius:8px;font-weight:700;font-size:13px;cursor:pointer;height:fit-content;white-space:nowrap;">
            Kirim
          </button>
        </div>
        <p style="font-size:11px;color:#444;margin-top:5px;">Balas sebagai <strong style="color:#ccc;"><?= htmlspecialchars($cfg['chat_admin_name']) ?></strong> &mdash; juga dikirim ke Telegram.</p>
      </div>
      <?php else: ?>
      <div style="padding:12px 18px;color:#555;font-size:12px;text-align:center;">🔒 Sesi sudah ditutup.</div>
      <?php endif; ?>
    </div>
  </div>

  <script>
  const CONSOLE_SESSION_ID = <?= $viewId ?>;
  const dm = document.getElementById('detail-msgs');
  if (dm) dm.scrollTop = dm.scrollHeight;
  let consolePollTimer = null;
  let consoleLastId    = <?= !empty($viewMsgs) ? (int)end($viewMsgs)['id'] : 0 ?>;

  // ── Append bubble (console side) ──────────────────────────
  function appendConsoleBubble(sender, message, time, id, attachment = null) {
    const row = document.createElement('div');
    row.className = `msg-row msg-${sender}`;
    row.dataset.id = id;
    const t = time ? new Date(time).toLocaleTimeString('id-ID',{hour:'2-digit',minute:'2-digit'}) : '';
    
    let attHtml = '';
    if (attachment) {
        const ext = attachment.split('.').pop().toLowerCase();
        if (['jpg','jpeg','png','gif'].includes(ext)) {
            attHtml = `<div style="margin-bottom:6px;"><a href="/${attachment}" target="_blank"><img src="/${attachment}" style="max-width:100%; border-radius:8px; border:1px solid rgba(255,255,255,0.1);"></a></div>`;
        } else {
            attHtml = `<div style="margin-bottom:6px;"><a href="/${attachment}" target="_blank" style="display:inline-flex; align-items:center; gap:6px; padding:6px 10px; background:rgba(255,255,255,.1); border-radius:8px; text-decoration:none; color:inherit; border:1px solid rgba(255,255,255,.2); font-size:12px; font-weight:bold;">📎 Download Lampiran</a></div>`;
        }
    }
    
    row.innerHTML = `<div><div class="msg-bubble">${attHtml}${nl2html(message)}</div><div class="msg-time">${t}</div></div>`;
    dm.appendChild(row);
    dm.scrollTop = dm.scrollHeight;
  }
  function nl2html(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/\n/g,'<br>');
  }

  function previewConsoleAttachment(input) {
    const file = input.files[0];
    if (file) {
        if (file.size > 5 * 1024 * 1024) {
            alert('Maksimal ukuran file adalah 5MB.');
            clearConsoleAttachment();
            return;
        }
        document.getElementById('console-attachment-preview').style.display = 'block';
        document.getElementById('console-att-preview-name').textContent = file.name;
    }
  }
  function clearConsoleAttachment() {
    const input = document.getElementById('console-attachment-input');
    if (input) input.value = '';
    document.getElementById('console-attachment-preview').style.display = 'none';
  }

  // ── Send reply via AJAX ────────────────────────────────────
  async function sendConsoleReply() {
    const input = document.getElementById('console-reply-input');
    const attInput = document.getElementById('console-attachment-input');
    const btn   = document.getElementById('console-reply-btn');
    const msg   = input.value.trim();
    const file = attInput && attInput.files[0] ? attInput.files[0] : null;

    if (!msg && !file) return;

    input.value = ''; btn.disabled = true; btn.textContent = '...';
    if (file) {
        document.getElementById('console-attachment-preview').style.display = 'none';
        if (attInput) attInput.value = '';
    }

    try {
      const fd = new FormData();
      fd.append('tab', 'reply');
      fd.append('session_id', CONSOLE_SESSION_ID);
      fd.append('reply_msg', msg);
      if (file) fd.append('attachment', file);
      
      const res  = await fetch('/console/livechat.php', {method:'POST',body:fd});
      const data = await res.json();
      if (data.ok) {
        appendConsoleBubble('admin', data.message, data.created_at, data.id, data.attachment);
        if (data.id > consoleLastId) consoleLastId = data.id;
      }
    } catch(e) { alert('Gagal kirim: ' + e.message); }
    btn.disabled = false; btn.textContent = 'Kirim';
    input.focus();
  }

  // ── Poll new messages (user & AI) ─────────────────────────
  async function consolePoll() {
    try {
      const res  = await fetch(`/console/livechat.php?action=console_poll&session_id=${CONSOLE_SESSION_ID}&after_id=${consoleLastId}`);
      const data = await res.json();
      if (!data.ok) return;
      (data.messages||[]).forEach(m => {
        if (parseInt(m.id) > consoleLastId) {
          consoleLastId = parseInt(m.id);
          appendConsoleBubble(m.sender, m.message, m.created_at, m.id, m.attachment);
        }
      });
    } catch {}
  }
  consolePollTimer = setInterval(consolePoll, 3000);
  </script>
  <?php endif; ?>
</div>
<?php endif; ?>


<!-- ═══ TAB: SETTINGS ══════════════════════════════════════ -->
<?php if ($activeTab === 'settings'): ?>
<form method="post">
  <input type="hidden" name="tab" value="settings">
  <div class="row g-3">

    <!-- Telegram -->
    <div class="col-md-6">
      <div class="c-card h-100">
        <div class="c-card-header">
          <span class="c-card-title">💬 Bot Livechat (Telegram)</span>
        </div>
        <div style="background:rgba(242,153,0,.1);border-bottom:1px solid rgba(242,153,0,.2);padding:8px 16px;font-size:11px;color:#F29900;font-weight:700;">
          ⚠️ Bot ini TERPISAH dari bot notifikasi Depo/WD.
        </div>
        <div class="c-card-body">
          <div class="c-form-group">
            <label class="c-label">Nama Admin (tampil di chat user)</label>
            <input type="text" name="chat_admin_name" class="c-form-control" value="<?= htmlspecialchars($cfg['chat_admin_name']) ?>" placeholder="Admin">
          </div>
          <div class="c-form-group">
            <label class="c-label">Bot Token <em style="color:#555;font-weight:400;">(khusus livechat)</em></label>
            <input type="text" name="lc_tg_token" class="c-form-control" value="<?= htmlspecialchars($cfg['lc_tg_token']) ?>" placeholder="1234567890:AAH...">
            <small style="color:#444;font-size:11px;">Buat bot baru via @BotFather. Beda dengan bot depo/WD.</small>
          </div>
          <div class="c-form-group">
            <label class="c-label">Group / Chat ID <em style="color:#555;font-weight:400;">(livechat)</em></label>
            <input type="text" name="lc_tg_chat_id" class="c-form-control" value="<?= htmlspecialchars($cfg['lc_tg_chat_id']) ?>" placeholder="-100123456789">
            <small style="color:#444;font-size:11px;">Supergroup khusus livechat, beda dari group notif Depo/WD.</small>
          </div>
          <div class="c-form-group">
            <label class="c-label">Tipe Grup</label>
            </select>
          </div>
          <div class="c-form-group">
            <label class="c-label">Pesan Sambutan</label>
            <textarea name="chat_welcome_msg" class="c-form-control" rows="2"><?= htmlspecialchars($cfg['chat_welcome_msg']) ?></textarea>
          </div>
        </div>
      </div>
    </div>

    <!-- OpenAI -->
    <div class="col-md-6">
      <div class="c-card h-100">
        <div class="c-card-header"><span class="c-card-title">✨ OpenAI (Mode AI)</span></div>
        <div class="c-card-body">
          <div class="c-form-group">
            <label class="c-label">OpenAI API Key</label>
            <input type="password" name="openai_api_key" class="c-form-control" value="<?= htmlspecialchars($cfg['openai_api_key']) ?>" placeholder="sk-...">
          </div>
          <div class="c-form-group">
            <label class="c-label">Model</label>
            <select name="openai_model" class="c-form-control">
              <?php foreach (['gpt-4o-mini','gpt-4o','gpt-3.5-turbo'] as $m): ?>
              <option value="<?= $m ?>" <?= $cfg['openai_model']===$m?'selected':'' ?>><?= $m ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="c-form-group">
            <label class="c-label">System Prompt AI</label>
            <textarea name="ai_system_prompt" class="c-form-control" rows="5"><?= htmlspecialchars($cfg['ai_system_prompt']) ?></textarea>
            <small style="color:#444;font-size:11px;">Instruksi untuk AI tentang cara menjawab.</small>
          </div>
          <div style="display:flex;gap:20px;flex-wrap:wrap;margin-bottom:12px;">
            <label style="display:flex;align-items:center;gap:8px;font-size:13px;cursor:pointer;">
              <input type="checkbox" name="livechat_enabled" value="1" <?= $cfg['livechat_enabled']==='1'?'checked':'' ?>>
              <strong style="color:#e0e0f0;">Livechat Aktif</strong>
            </label>
          </div>
          <div class="c-form-group">
            <label class="c-label">Pesan Livechat Ditutup (Offline)</label>
            <textarea name="lc_offline_msg" class="c-form-control" rows="2" placeholder="Layanan live chat saat ini tidak tersedia. Silakan coba lagi nanti pada jam operasional."><?= htmlspecialchars($cfg['lc_offline_msg'] ?? 'Layanan live chat saat ini tidak tersedia. Silakan coba lagi nanti pada jam operasional.') ?></textarea>
            <small style="color:#444;font-size:11px;">Pesan yang ditampilkan ke user jika Livechat dimatikan.</small>
          </div>
          <div style="display:flex;gap:20px;flex-wrap:wrap;margin-bottom:12px;">
            <label style="display:flex;align-items:center;gap:8px;font-size:13px;color:#888;cursor:pointer;">
              <input type="checkbox" name="chat_ai_enabled" value="1" <?= $cfg['chat_ai_enabled']==='1'?'checked':'' ?>>
              Aktifkan Mode AI
            </label>
            <label style="display:flex;align-items:center;gap:8px;font-size:13px;color:#888;cursor:pointer;">
              <input type="checkbox" name="chat_admin_enabled" value="1" <?= $cfg['chat_admin_enabled']==='1'?'checked':'' ?>>
              Aktifkan Mode Admin
            </label>
            <label style="display:flex;align-items:center;gap:8px;font-size:13px;color:#888;cursor:pointer;">
              <input type="checkbox" name="lc_attachment_enabled" value="1" <?= $cfg['lc_attachment_enabled']==='1'?'checked':'' ?>>
              Aktifkan Fitur Lampiran (Gambar/File)
            </label>
          </div>
          <!-- Debug Panel Toggle -->
          <div style="display:flex;gap:20px;flex-wrap:wrap;padding:10px 14px;background:rgba(251,188,4,.07);border:1px solid rgba(251,188,4,.2);border-radius:8px;">
            <label style="display:flex;align-items:center;gap:8px;font-size:13px;cursor:pointer;">
              <input type="checkbox" name="lc_debug_panel" value="1" <?= $cfg['lc_debug_panel']==='1'?'checked':'' ?>>
              <span>🐛 <strong style="color:#FBBC04;">Debug Panel</strong> <span style="color:#555;font-size:11px;">(tampilkan panel debug di livechat user)</span></span>
            </label>
          </div>
        </div>
      </div>
    </div>

    <div class="col-12 text-end">
      <button type="submit" style="background:var(--brand);border:none;color:#fff;padding:10px 28px;border-radius:8px;font-weight:700;font-size:13px;cursor:pointer;">
        💾 Simpan Pengaturan
      </button>
    </div>
  </div>
</form>
<?php endif; ?>


<!-- ═══ TAB: WEBHOOK ══════════════════════════════════════ -->
<?php if ($activeTab === 'webhook'): ?>
<?php
  $scheme     = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off') ? 'https' : 'http';
  $host       = $_SERVER['HTTP_HOST'] ?? 'yourdomain.com';
  $webhookUrl = $scheme . '://' . $host . '/chat_action?action=tg_webhook';
  $botToken   = $cfg['lc_tg_token'];
  $setWebhookUrl = $botToken
    ? "https://api.telegram.org/bot{$botToken}/setWebhook?url=" . urlencode($webhookUrl)
    : '';
?>
<div class="row g-3">
  <div class="col-md-7">
    <div class="c-card">
      <div class="c-card-header"><span class="c-card-title">🔗 Setup Telegram Webhook</span></div>
      <div class="c-card-body">
        <p style="font-size:13px;color:#888;margin-bottom:16px;">
          Agar balasan admin dari Telegram masuk ke chat user, set webhook ini ke bot kamu.
        </p>

        <div class="c-form-group">
          <label class="c-label">URL Webhook kamu</label>
          <div class="webhook-url"><?= htmlspecialchars($webhookUrl) ?></div>
        </div>

        <?php if ($setWebhookUrl): ?>
        <?php
          $wh_result = $_SESSION['wh_result'] ?? null;
          $wh_info   = $_SESSION['wh_info']   ?? null;
          unset($_SESSION['wh_result'], $_SESSION['wh_info']);
        ?>
        <?php if ($wh_result): ?>
        <div style="margin-bottom:14px;padding:12px 16px;border-radius:8px;font-size:12px;font-family:monospace;
             background:<?= !empty($wh_result['ok']) ? 'rgba(76,175,130,.12)' : 'rgba(244,78,59,.1)' ?>;
             border:1px solid <?= !empty($wh_result['ok']) ? 'rgba(76,175,130,.3)' : 'rgba(244,78,59,.3)' ?>;
             color:<?= !empty($wh_result['ok']) ? '#4CAF82' : '#F44E3B' ?>">
          <?= !empty($wh_result['ok']) ? '✅' : '❌' ?>
          <?php if (!empty($wh_result['ok'])): ?>
            Webhook berhasil diset ke: <strong><?= htmlspecialchars($wh_result['webhook_url'] ?? '') ?></strong>
          <?php else: ?>
            Gagal: <?= htmlspecialchars($wh_result['description'] ?? $wh_result['error'] ?? 'Unknown error') ?>
          <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if ($wh_info): ?>
        <div style="margin-bottom:14px;padding:12px 16px;border-radius:8px;font-size:12px;font-family:monospace;
             background:rgba(66,133,244,.08);border:1px solid rgba(66,133,244,.2);color:#a0b4f0;word-break:break-all;">
          <strong>ℹ️ Webhook Info:</strong><br>
          URL: <?= htmlspecialchars($wh_info['result']['url'] ?? '(kosong)') ?><br>
          Pending: <?= (int)($wh_info['result']['pending_update_count'] ?? 0) ?><br>
          Last Error: <?= htmlspecialchars($wh_info['result']['last_error_message'] ?? '–') ?>
        </div>
        <?php endif; ?>

        <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:4px;">
          <form method="POST">
            <input type="hidden" name="tab" value="sync_webhook">
            <button type="submit"
              style="background:var(--brand);border:none;color:#fff;padding:10px 22px;border-radius:8px;font-weight:700;font-size:13px;cursor:pointer;">
              🚀 Sync Webhook via Server
            </button>
          </form>
          <form method="POST">
            <input type="hidden" name="tab" value="check_webhook">
            <button type="submit"
              style="background:#1f2235;border:1px solid #2a2d3e;color:#ccc;padding:10px 22px;border-radius:8px;font-weight:700;font-size:13px;cursor:pointer;">
              🔍 Cek Status Webhook
            </button>
          </form>
        </div>
        <?php else: ?>
        <div style="background:rgba(242,153,0,.1);border:1px solid rgba(242,153,0,.3);color:#F29900;padding:10px 14px;border-radius:8px;font-size:12px;">
          ⚠️ Isi Bot Token di tab Pengaturan dulu.
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <div class="col-md-5">
    <div class="c-card">
      <div class="c-card-header"><span class="c-card-title">📋 Cara Kerja</span></div>
      <div class="c-card-body">
        <ol style="font-size:13px;color:#888;line-height:2;padding-left:18px;margin:0;">
          <li>Buat bot via <strong style="color:#ccc;">@BotFather</strong></li>
          <li>Buat Supergroup &amp; aktifkan <strong style="color:#ccc;">Topics</strong></li>
          <li>Tambahkan bot ke grup sebagai admin</li>
          <li>Isi <strong style="color:#ccc;">Bot Token</strong> &amp; <strong style="color:#ccc;">Chat ID</strong></li>
          <li>Set webhook dengan tombol di kiri</li>
          <li>Setiap sesi chat baru = 1 Thread baru di grup</li>
          <li>Balas thread di Telegram → pesan masuk ke chat user</li>
        </ol>
      </div>
    </div>
  </div>
</div>

<!-- Danger Zone card -->
<div class="row g-3 mt-2">
  <div class="col-12">
    <div class="c-card" style="border-color:rgba(244,78,59,.3);">
      <div class="c-card-header" style="background:rgba(244,78,59,.07);border-bottom-color:rgba(244,78,59,.2);">
        <span class="c-card-title" style="color:#F44E3B;">⚠️ Danger Zone</span>
        <span style="font-size:11px;color:#555;">Tindakan ini tidak bisa dibatalkan</span>
      </div>
      <div class="c-card-body">
        <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:center;">

          <!-- Reset all sessions + delete topics -->
          <form method="POST" onsubmit="return confirm('RESET SEMUA SESI? Ini akan menghapus semua chat dari DB dan semua topics dari Telegram. Tidak bisa dibatalkan!');">
            <input type="hidden" name="tab" value="reset_all_sessions">
            <button type="submit"
              style="background:rgba(244,78,59,.15);border:1.5px solid rgba(244,78,59,.4);color:#F44E3B;padding:10px 20px;border-radius:8px;font-weight:700;font-size:13px;cursor:pointer;">
              🗑️ Reset Semua Sesi + Topics
            </button>
          </form>

          <!-- Delete Telegram topics only -->
          <form method="POST" onsubmit="return confirm('Hapus semua Topics Telegram yang dibuat bot? Data sesi di database TETAP tersimpan.');">
            <input type="hidden" name="tab" value="delete_tg_topics">
            <button type="submit"
              style="background:rgba(251,188,4,.1);border:1.5px solid rgba(251,188,4,.3);color:#FBBC04;padding:10px 20px;border-radius:8px;font-weight:700;font-size:13px;cursor:pointer;">
              🧹 Hapus Topics Telegram Saja
            </button>
          </form>

          <!-- Nuclear: delete ALL topics -->
          <button type="button" id="btn-nuclear-topics" onclick="nuclearClearTopics()"
            style="background:rgba(139,0,0,.2);border:1.5px solid rgba(200,0,0,.5);color:#ff6b6b;padding:10px 20px;border-radius:8px;font-weight:700;font-size:13px;cursor:pointer;">
            ☢️ Hapus SEMUA Topics (Nuclear)
          </button>

        </div>
        <p style="font-size:11px;color:#555;margin-top:10px;margin-bottom:0;">
          ⚠️ Hanya topics yang dibuat oleh bot (punya <code>tg_thread_id</code> di database) yang akan dihapus.
          Topic <strong>General</strong> dan topic yang dibuat manual tidak akan tersentuh.
        </p>
        <div id="nuclear-result" style="display:none;margin-top:12px;padding:12px 16px;border-radius:8px;font-size:12px;font-family:monospace;"></div>
      </div>
    </div>
  </div>
</div>

<script>
async function nuclearClearTopics() {
  if (!confirm('HAPUS SEMUA TOPICS TELEGRAM? Bot akan mencoba menghapus semua topic ID mulai dari 2 hingga batas terdeteksi. General topic akan dilewati otomatis oleh Telegram. TIDAK BISA DIBATALKAN!')) return;

  const btn = document.getElementById('btn-nuclear-topics');
  const result = document.getElementById('nuclear-result');
  btn.disabled = true;
  btn.innerHTML = '⏳ Menghapus... (bisa 30-60 detik, mohon tunggu)';
  result.style.display = 'block';
  result.style.background = 'rgba(66,133,244,.08)';
  result.style.border = '1px solid rgba(66,133,244,.2)';
  result.style.color = '#a0b4f0';
  result.textContent = '⏳ Sedang memproses... bot mencoba menghapus semua topic. Jangan tutup halaman ini.';

  try {
    const fd = new FormData();
    fd.append('tab', 'nuclear_clear_topics');
    const res  = await fetch('/console/livechat.php', { method: 'POST', body: fd });
    const data = await res.json();
    if (data.ok) {
      result.style.background = 'rgba(76,175,130,.12)';
      result.style.border = '1px solid rgba(76,175,130,.3)';
      result.style.color = '#4CAF82';
      result.textContent = `✅ Selesai! Topics dihapus: ${data.deleted} | Gagal/tidak ada: ${data.failed} | Range dicek: ${data.range}`;
    } else {
      result.style.background = 'rgba(244,78,59,.1)';
      result.style.border = '1px solid rgba(244,78,59,.3)';
      result.style.color = '#F44E3B';
      result.textContent = '❌ Error: ' + (data.error || 'Unknown');
    }
  } catch(e) {
    result.style.background = 'rgba(244,78,59,.1)';
    result.style.border = '1px solid rgba(244,78,59,.3)';
    result.style.color = '#F44E3B';
    result.textContent = '❌ Gagal: ' + e.message;
  }
  btn.disabled = false;
  btn.innerHTML = '☢️ Hapus SEMUA Topics (Nuclear)';
}
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/partials/footer.php'; ?>
