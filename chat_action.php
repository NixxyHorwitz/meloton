<?php
/**
 * chat_action.php — AJAX handler untuk LiveChat (Telegram + OpenAI)
 * Endpoint: /chat_action?action=...
 */
ini_set('log_errors', '1');
error_reporting(E_ALL);
require_once __DIR__ . '/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

// Reconnect MySQL in case connection has gone away (error 2006/2013)
pdo_reconnect($pdo);

$action = $_GET['action'] ?? $_POST['action'] ?? '';
error_log('[chat_action] action=' . $action . ' method=' . ($_SERVER['REQUEST_METHOD'] ?? '?') . ' cookie=' . (isset($_COOKIE['chat_session']) ? 'yes' : 'no'));

// ─── Helper: JSON response ────────────────────────────────────
function json_ok(array $data = []): never {
    echo json_encode(['ok' => true, ...$data]);
    exit;
}
function json_err(string $msg, int $code = 400): never {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

// ─── Helper: Get/create session ──────────────────────────────
function get_chat_session(PDO $pdo, string $key): ?array {
    $s = $pdo->prepare("SELECT * FROM chat_sessions WHERE session_key=?");
    $s->execute([$key]);
    return $s->fetch() ?: null;
}

// ─── Helper: Telegram API call (pakai lc_tg_token — BUKAN bot depo/WD) ──
function tg_api(PDO $pdo, string $method, array $params): array {
    $token = setting($pdo, 'lc_tg_token', '');
    if (!$token) return ['ok' => false];
    $ch = curl_init("https://api.telegram.org/bot{$token}/{$method}");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($params),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 10,
    ]);
    $res  = curl_exec($ch);
    curl_close($ch);
    return json_decode($res ?: '{}', true) ?: [];
}

function tg_api_upload(PDO $pdo, string $method, array $params): array {
    $token = setting($pdo, 'lc_tg_token', '');
    if (!$token) return ['ok' => false];
    $ch = curl_init("https://api.telegram.org/bot{$token}/{$method}");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $params,
        CURLOPT_TIMEOUT        => 30,
    ]);
    $res  = curl_exec($ch);
    curl_close($ch);
    return json_decode($res ?: '{}', true) ?: [];
}

// ─── Helper: OpenAI chat completion ──────────────────────────
function openai_chat(PDO $pdo, array $messages): string {
    $apiKey = setting($pdo, 'openai_api_key', '');
    $model  = setting($pdo, 'openai_model', 'gpt-4o-mini');
    if (!$apiKey) return 'Maaf, layanan AI sedang tidak tersedia.';

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode([
            'model'       => $model,
            'messages'    => $messages,
            'max_tokens'  => 600,
            'temperature' => 0.7,
        ]),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            "Authorization: Bearer {$apiKey}",
        ],
        CURLOPT_TIMEOUT => 30,
    ]);
    $res  = curl_exec($ch);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err) return 'Maaf, gagal menghubungi AI: ' . $err;
    $data = json_decode($res ?: '{}', true);
    return trim($data['choices'][0]['message']['content'] ?? 'Maaf, AI tidak merespons.');
}

// ─── Helper: escape plain text for Telegram ──────────────────
function tg_escape(string $text): string {
    return str_replace(
        ['_','*','[',']','(',')','{','}','~','`','>','#','+','-','=','|','.',',','!','\\'],
        ['\_','\*','\[','\]','\(','\)','\{','\}','\~','\`','\>','\#','\+','\-','\=','\|','\.','\,','\!','\\\\'],
        $text
    );
}

// ─── Helper: Cleanup Inactive Sessions ────────────────────────
function cleanup_inactive_sessions(PDO $pdo): void {
    try {
        $stale = $pdo->query("SELECT id, tg_thread_id FROM chat_sessions WHERE status='open' AND is_kept=0 AND last_message_at < DATE_SUB(NOW(), INTERVAL 3 HOUR)")->fetchAll();
        if (!$stale) return;
        $chatId = setting($pdo, 'lc_tg_chat_id', '');
        foreach ($stale as $st) {
            $pdo->prepare("UPDATE chat_sessions SET status='closed' WHERE id=?")->execute([$st['id']]);
            if ($st['tg_thread_id'] && $chatId) {
                tg_api($pdo, 'deleteForumTopic', [
                    'chat_id'           => $chatId,
                    'message_thread_id' => (int)$st['tg_thread_id'],
                ]);
            }
        }
    } catch (\Throwable $th) {}
}

// ═══════════════════════════════════════════════════════════════
// ACTIONS
// ═══════════════════════════════════════════════════════════════

try {
$user_check = auth_user($pdo);
session_write_close(); // Unlock session immediately so long polling/uploads don't block other requests!

if ($user_check && isset($user_check['can_chat']) && $user_check['can_chat'] == 0) {
    if (in_array($action, ['start', 'send', 'switch_mode'])) {
        json_err('Akses LiveChat Anda telah dibatasi oleh Administrator.');
    }
}

switch ($action) {

    // ── Start / get session ─────────────────────────────────────
    case 'start':
        $user       = auth_user($pdo);
        $sessionKey = $_COOKIE['chat_session'] ?? '';

        // Cek sesi existing — hanya return jika masih OPEN
        if ($sessionKey) {
            $sess = get_chat_session($pdo, $sessionKey);
            if ($sess && $sess['status'] === 'open') {
                $msgs = $pdo->prepare(
                    "SELECT id,sender,message,attachment,created_at FROM chat_messages
                     WHERE session_id=? ORDER BY id ASC LIMIT 100"
                );
                $msgs->execute([$sess['id']]);
                $rows = $msgs->fetchAll();
                json_ok([
                    'session_key' => $sess['session_key'],
                    'mode'        => $sess['mode'],
                    'status'      => $sess['status'],
                    'messages'    => $rows,
                    'last_msg_id' => !empty($rows) ? (int)end($rows)['id'] : 0,
                    'welcome'     => setting($pdo, 'chat_welcome_msg', 'Halo! Ada yang bisa dibantu?'),
                ]);
            }
            // Sesi tidak valid / sudah closed — bersihkan cookie, buat baru
            setcookie('chat_session', '', time() - 3600, '/');
        }

        // Buat sesi baru
        $newKey    = bin2hex(random_bytes(16));
        $userName  = $user ? $user['username'] : (trim($_POST['name'] ?? '') ?: 'Guest');
        $userEmail = $user ? $user['email'] : (trim($_POST['email'] ?? '') ?: null);
        $userId    = $user ? (int)$user['id'] : null;
        $initMode  = in_array($_POST['mode'] ?? '', ['ai','admin'], true) ? $_POST['mode'] : 'ai';

        $pdo->prepare(
            "INSERT INTO chat_sessions (session_key,user_id,user_name,user_email,mode) VALUES (?,?,?,?,?)"
        )->execute([$newKey, $userId, $userName, $userEmail, $initMode]);
        $sessId = (int)$pdo->lastInsertId();

        // Welcome message
        $welcome = setting($pdo, 'chat_welcome_msg', 'Halo! Ada yang bisa kami bantu?');
        $pdo->prepare(
            "INSERT INTO chat_messages (session_id,sender,message) VALUES (?,'system',?)"
        )->execute([$sessId, $welcome]);
        $welcomeMsgId = (int)$pdo->lastInsertId();

        setcookie('chat_session', $newKey, time() + 86400 * 7, '/', '', false, true);

        // ── Telegram: buat thread + inline keyboard ──
        $chatId  = setting($pdo, 'lc_tg_chat_id', '');
        $isForum = setting($pdo, 'lc_tg_forum', '1') === '1';
        $siteUrl = rtrim(setting($pdo, 'lc_site_url', ''), '/');
        $tgThreadId = null;
        $tgDebug    = null;

        // Inline keyboard untuk admin
        $consoleLink = $siteUrl ? "{$siteUrl}/console/livechat.php?view={$sessId}" : null;
        
        $inlineKbd = ['inline_keyboard' => []];
        if ($consoleLink) {
            $inlineKbd['inline_keyboard'][] = [['text' => "🖥️ Buka Console", 'url' => $consoleLink]];
        }
        if ($userId) {
            // First row of user-specific actions: History and Refund
            $inlineKbd['inline_keyboard'][] = [
                ['text' => "📜 Cek History Depo WD", 'callback_data' => "txnhist:{$userId}"],
                ['text' => "💸 Refund All Holded WD", 'callback_data' => "refhold:{$userId}"]
            ];
            
            // Fallback siteUrl if empty
            $actualSiteUrl = $siteUrl;
            if (!$actualSiteUrl && isset($_SERVER['HTTP_HOST'])) {
                $scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
                $actualSiteUrl = "{$scheme}://{$_SERVER['HTTP_HOST']}";
            }
            
            if ($actualSiteUrl) {
                // We use callback_data because web_app is strictly forbidden in group chats by Telegram API.
                // We will catch this callback and send the admin a Private Message (PM) containing the web_app button.
                $inlineKbd['inline_keyboard'][] = [
                    ['text' => "✏️ Edit User & Saldo", 'callback_data' => "req_edit:{$userId}"]
                ];
            }
        }
        $inlineKbd['inline_keyboard'][] = [
            ['text' => "📌 Keep", 'callback_data' => "keep_sess:{$sessId}"],
            ['text' => "🔒 Tutup", 'callback_data' => "close_sess:{$sessId}"],
            ['text' => "🗑️ Hapus Sesi", 'callback_data' => "del_thread:{$sessId}"]
        ];
        $inlineKbd['inline_keyboard'][] = [
            ['text' => "🤖 Mode AI", 'callback_data' => "mode_ai:{$sessId}"],
            ['text' => "👨‍💼 Mode Admin", 'callback_data' => "mode_admin:{$sessId}"]
        ];
        $inlineKbd['inline_keyboard'][] = [
            ['text' => "🗑️ Hapus Pesan Ini", 'callback_data' => "del_msg:{$sessId}"]
        ];

        $lvl = 'Free';
        $balance_wd = 0.0;
        $balance_dep = 0.0;
        if ($userId) {
            $uStmt = $pdo->prepare("SELECT u.*, m.name as membership_name FROM users u LEFT JOIN memberships m ON m.id=u.membership_id WHERE u.id=?");
            $uStmt->execute([$userId]);
            $uInfo = $uStmt->fetch();
            if ($uInfo) {
                $lvl = $uInfo['membership_name'] ?: 'Free';
                $balance_wd = (float)$uInfo['balance_wd'];
                $balance_dep = (float)$uInfo['balance_dep'];
            }
        }

        if ($chatId) {
            $threadTitle = "{$userName} #{$sessId}";
            $intro = "💬 Sesi Chat Baru\n" 
                   . "👤 User: {$userName}\n";
            if ($userId) {
                $intro .= "🏅 Level: {$lvl}\n"
                        . "💰 Saldo Penarikan: Rp" . number_format($balance_wd, 0, ',', '.') . "\n"
                        . "💳 Saldo Beli: Rp" . number_format($balance_dep, 0, ',', '.') . "\n";
            } else {
                $intro .= "🏅 Level: Guest\n";
            }
            $intro .= "🔑 Session: #{$sessId}\n"
                    . "🤖 Mode: " . ($initMode === 'admin' ? 'Admin' : 'AI');

            // Always try to create Forum Topic first
            $tgRes = tg_api($pdo, 'createForumTopic', [
                'chat_id'    => $chatId,
                'name'       => mb_substr($threadTitle, 0, 128),
            ]);
            $tgDebug = $tgRes;

            if (!empty($tgRes['ok'])) {
                // Forum topic berhasil dibuat
                $tgThreadId = $tgRes['result']['message_thread_id'] ?? null;
                $pdo->prepare("UPDATE chat_sessions SET tg_thread_id=? WHERE id=?")
                    ->execute([$tgThreadId, $sessId]);
                // Update forum setting in DB
                setting_set($pdo, 'lc_tg_forum', '1');
                $tgSend = tg_api($pdo, 'sendMessage', [
                    'chat_id'           => $chatId,
                    'message_thread_id' => $tgThreadId,
                    'text'              => $intro,
                    'reply_markup'      => $inlineKbd,
                ]);
            } else {
                // Bukan forum / gagal — kirim ke chat biasa
                setting_set($pdo, 'lc_tg_forum', '0');
                $tgRes = tg_api($pdo, 'sendMessage', [
                    'chat_id'      => $chatId,
                    'text'         => $intro,
                    'reply_markup' => $inlineKbd,
                ]);
                $tgDebug = ['forum_failed' => $tgDebug, 'fallback' => $tgRes];
            }
        }

        json_ok([
            'session_key' => $newKey,
            'mode'        => $initMode,
            'status'      => 'open',
            'messages'    => [
                ['id' => $welcomeMsgId, 'sender' => 'system', 'message' => $welcome, 'created_at' => date('Y-m-d H:i:s')],
            ],
            'last_msg_id' => $welcomeMsgId,
            'welcome'     => $welcome,
            'tg_debug'    => $tgDebug,
        ]);


    // ── Send message ────────────────────────────────────────────
    case 'send':
        $sessionKey = $_COOKIE['chat_session'] ?? $_POST['session_key'] ?? '';
        $text       = trim($_POST['message'] ?? '');
        if (!$sessionKey) json_err('Sesi tidak ditemukan.');
        if (!$text && empty($_FILES['attachment']['tmp_name'])) json_err('Pesan tidak valid.');
        if (mb_strlen($text) > 2000) json_err('Pesan terlalu panjang.');
        
        $attachmentPath = null;
        if (setting($pdo, 'lc_attachment_enabled', '1') === '1' && !empty($_FILES['attachment']['tmp_name'])) {
            $f = $_FILES['attachment'];
            if ($f['error'] === UPLOAD_ERR_OK && $f['size'] <= 5*1024*1024) {
                $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
                if (in_array($ext, ['jpg','jpeg','png','gif','pdf','zip','rar'])) {
                    $dir = __DIR__ . '/uploads/chat/' . date('Y/m');
                    if (!is_dir($dir)) mkdir($dir, 0777, true);
                    $filename = uniqid('att_') . '.' . $ext;
                    if (move_uploaded_file($f['tmp_name'], $dir . '/' . $filename)) {
                        $attachmentPath = 'uploads/chat/' . date('Y/m') . '/' . $filename;
                    }
                }
            }
        }

        $sess = get_chat_session($pdo, $sessionKey);
        if (!$sess) json_err('Sesi tidak valid.');
        if ($sess['status'] === 'closed') json_err('Sesi ini sudah ditutup.');

        $sessId = (int)$sess['id'];

        $pdo->prepare(
            "INSERT INTO chat_messages (session_id,sender,message,attachment) VALUES (?,'user',?,?)"
        )->execute([$sessId, $text, $attachmentPath]);
        $userMsgId = (int)$pdo->lastInsertId();

        // Update last_message_at so session doesn't auto-close while active
        $pdo->prepare("UPDATE chat_sessions SET last_message_at=NOW() WHERE id=?")->execute([$sessId]);

        // Kirim ke Telegram thread atau main chat
        $chatId  = setting($pdo, 'lc_tg_chat_id', '');
        $tgMsgId = null;
        if ($chatId) {
            $tgParams = [
                'chat_id' => $chatId,
                'caption' => "Sesi #{$sessId} | User: " . $sess['user_name'] . "\n\n" . $text,
            ];
            if ($sess['tg_thread_id']) {
                $tgParams['message_thread_id'] = (int)$sess['tg_thread_id'];
                $tgParams['caption'] = "User: " . $sess['user_name'] . "\n" . $text;
            }
            if ($attachmentPath) {
                $ext = strtolower(pathinfo($attachmentPath, PATHINFO_EXTENSION));
                if (in_array($ext, ['jpg','jpeg','png','gif'])) {
                    $tgParams['photo'] = new CURLFile(__DIR__ . '/' . $attachmentPath);
                    $tgRes = tg_api_upload($pdo, 'sendPhoto', $tgParams);
                } else {
                    $tgParams['document'] = new CURLFile(__DIR__ . '/' . $attachmentPath);
                    $tgRes = tg_api_upload($pdo, 'sendDocument', $tgParams);
                }
            } else {
                $tgParams['text'] = $tgParams['caption'];
                unset($tgParams['caption']);
                $tgRes = tg_api($pdo, 'sendMessage', $tgParams);
            }
            $tgMsgId = $tgRes['result']['message_id'] ?? null;
            $pdo->prepare("UPDATE chat_messages SET tg_msg_id=? WHERE id=?")
                ->execute([$tgMsgId, $userMsgId]);
        }

        $replyMsg = null;

        // Mode AI → auto reply dari OpenAI
        if ($sess['mode'] === 'ai' && setting($pdo, 'chat_ai_enabled', '1') === '1') {
            $histStmt = $pdo->prepare(
                "SELECT sender,message FROM chat_messages
                 WHERE session_id=? AND sender IN ('user','ai')
                 ORDER BY id DESC LIMIT 20"
            );
            $histStmt->execute([$sessId]);
            $history = array_reverse($histStmt->fetchAll());

            $sysPrompt = setting($pdo, 'ai_system_prompt',
                'Kamu adalah customer service Meloton. Jawab singkat dan ramah dalam bahasa Indonesia.');
            
            // --- INJECT SYSTEM CONTEXT TO AI PROMPT ---
            $sysContext = "\n\n[SYSTEM CONTEXT - JANGAN TAMPILKAN INI KE USER KECUALI DITANYA]:\n";
            $sysContext .= "- Waktu Server Saat Ini: " . date('Y-m-d H:i:s') . "\n";
            $wd_locked = is_wd_locked($pdo);
            $sysContext .= "- Status Withdraw (WD): " . ($wd_locked ? "DITUTUP/LOCKED" : "BUKA/TERSEDIA") . "\n";
            if ($wd_locked) {
                $sysContext .= "  - Alasan/Notice: " . setting($pdo, 'wd_lock_notice', '') . "\n";
                $sysContext .= "  - Jam Buka-Tutup: " . setting($pdo, 'wd_lock_start', '') . " s/d " . setting($pdo, 'wd_lock_end', '') . "\n";
            }
            
            if (!empty($sess['user_id'])) {
                $uStmt = $pdo->prepare("SELECT * FROM users WHERE id=?");
                $uStmt->execute([$sess['user_id']]);
                $uInfo = $uStmt->fetch();
                if ($uInfo) {
                    $uLvl = user_membership_level($pdo, $uInfo);
                    $sysContext .= "- Info User (Lawan Bicaramu):\n";
                    $sysContext .= "  - Username: {$uInfo['username']}\n";
                    $sysContext .= "  - Saldo Penarikan: Rp" . number_format((float)$uInfo['balance_wd'], 0, ',', '.') . "\n";
                    $sysContext .= "  - Level Membership: Level {$uLvl}\n";
                    
                    $wd_min_level = (int)setting($pdo, 'wd_min_level', '0');
                    $wd_require_level = setting($pdo, 'wd_require_level', '0') === '1';
                    if ($wd_require_level && $wd_min_level > 0) {
                        $sysContext .= "  - Status Syarat WD: " . ($uLvl >= $wd_min_level ? "Memenuhi syarat (Level $uLvl >= $wd_min_level)" : "BELUM memenuhi syarat (butuh Level $wd_min_level)") . "\n";
                    }
                }
            } else {
                $sysContext .= "- Info User: Guest (Belum Login).\n";
            }
            
            $oaiMsgs   = [['role' => 'system', 'content' => $sysPrompt . $sysContext]];
            foreach ($history as $h) {
                $oaiMsgs[] = [
                    'role'    => $h['sender'] === 'user' ? 'user' : 'assistant',
                    'content' => $h['message'],
                ];
            }

            $aiReply = openai_chat($pdo, $oaiMsgs);

            $pdo->prepare(
                "INSERT INTO chat_messages (session_id,sender,message) VALUES (?,'ai',?)"
            )->execute([$sessId, $aiReply]);
            $aiMsgId = (int)$pdo->lastInsertId();
            $pdo->prepare("UPDATE chat_sessions SET last_message_at=NOW() WHERE id=?")->execute([$sessId]);

            if ($chatId) {
                $tgParams = [
                    'chat_id' => $chatId,
                    'text'    => "Sesi #{$sessId} | AI: " . $aiReply,
                ];
                if ($sess['tg_thread_id']) {
                    $tgParams['message_thread_id'] = (int)$sess['tg_thread_id'];
                    $tgParams['text'] = "AI: " . $aiReply;
                }
                $tgAi = tg_api($pdo, 'sendMessage', $tgParams);
                $pdo->prepare("UPDATE chat_messages SET tg_msg_id=? WHERE id=?")
                    ->execute([$tgAi['result']['message_id'] ?? null, $aiMsgId]);
            }

            $replyMsg = ['id' => $aiMsgId, 'sender' => 'ai', 'message' => $aiReply, 'created_at' => date('Y-m-d H:i:s')];
        }

        json_ok([
            'user_message' => ['id' => $userMsgId, 'sender' => 'user', 'message' => $text, 'attachment' => $attachmentPath, 'created_at' => date('Y-m-d H:i:s')],
            'last_msg_id'  => $replyMsg ? (int)$replyMsg['id'] : $userMsgId,
            'reply'        => $replyMsg,
        ]);


    // ── Poll new messages ────────────────────────────────────────
    case 'poll':
        if (rand(1, 10) === 1) cleanup_inactive_sessions($pdo); // Auto-close inactive
        $sessionKey = $_COOKIE['chat_session'] ?? $_GET['session_key'] ?? '';
        $afterId    = (int)($_GET['after_id'] ?? 0);
        if (!$sessionKey) json_err('Sesi tidak ditemukan.');

        $sess = get_chat_session($pdo, $sessionKey);
        if (!$sess) json_err('Sesi tidak valid.');

        $msgs = $pdo->prepare(
            "SELECT id,sender,message,attachment,created_at FROM chat_messages
             WHERE session_id=? AND id>? ORDER BY id ASC LIMIT 50"
        );
        $msgs->execute([$sess['id'], $afterId]);
        $rows = $msgs->fetchAll();

        json_ok([
            'messages' => $rows,
            'status'   => $sess['status'],
            'mode'     => $sess['mode'],
        ]);


    // ── Switch mode (AI ↔ Admin) ─────────────────────────────────
    case 'switch_mode':
        $sessionKey = $_COOKIE['chat_session'] ?? $_POST['session_key'] ?? '';
        $newMode    = $_POST['mode'] ?? '';
        if (!in_array($newMode, ['ai', 'admin'], true)) json_err('Mode tidak valid.');
        if (!$sessionKey) json_err('Sesi tidak ditemukan.');

        $sess = get_chat_session($pdo, $sessionKey);
        if (!$sess) json_err('Sesi tidak valid.');

        $pdo->prepare("UPDATE chat_sessions SET mode=? WHERE id=?")->execute([$newMode, $sess['id']]);

        $switchMsg = $newMode === 'admin'
            ? 'Beralih ke Mode Admin — tim kami akan segera membalas.'
            : 'Beralih ke Mode AI — Asisten AI siap membantu.';
        $pdo->prepare(
            "INSERT INTO chat_messages (session_id,sender,message) VALUES (?,'system',?)"
        )->execute([$sess['id'], $switchMsg]);
        $switchMsgId = (int)$pdo->lastInsertId();

        // Notif ke Telegram untuk semua mode switch
        $chatId = setting($pdo, 'lc_tg_chat_id', '');
        if ($chatId) {
            $modeLabel = $newMode === 'admin' ? 'Admin' : 'AI';
            $tgParams  = [
                'chat_id' => $chatId,
                'text'    => "[{$sess['user_name']}] beralih ke Mode {$modeLabel}. Sesi #{$sess['id']}",
            ];
            if ($sess['tg_thread_id']) {
                $tgParams['message_thread_id'] = (int)$sess['tg_thread_id'];
            }
            tg_api($pdo, 'sendMessage', $tgParams);
        }

        json_ok([
            'mode'           => $newMode,
            'switch_msg_id'  => $switchMsgId,
            'switch_message' => $switchMsg,
        ]);


    // ── Close session ────────────────────────────────────────────
    case 'close':
        $sessionKey = $_COOKIE['chat_session'] ?? $_POST['session_key'] ?? '';
        if (!$sessionKey) json_err('Sesi tidak ditemukan.');

        $sess = get_chat_session($pdo, $sessionKey);
        if (!$sess) json_err('Sesi tidak valid.');

        $reason = trim($_POST['reason'] ?? $_GET['reason'] ?? '');
        $closeMsg = $reason ? "Sesi chat telah ditutup. Alasan: {$reason}" : "Sesi chat telah ditutup.";

        $pdo->prepare("UPDATE chat_sessions SET status='closed' WHERE id=?")->execute([$sess['id']]);
        $pdo->prepare(
            "INSERT INTO chat_messages (session_id,sender,message) VALUES (?, 'system', ?)"
        )->execute([$sess['id'], $closeMsg]);

        $chatId = setting($pdo, 'lc_tg_chat_id', '');
        if ($chatId && $sess['tg_thread_id']) {
            tg_api($pdo, 'closeForumTopic', [
                'chat_id'           => $chatId,
                'message_thread_id' => (int)$sess['tg_thread_id'],
            ]);
        }

        setcookie('chat_session', '', time() - 3600, '/');
        json_ok(['closed' => true]);


    // ── Webhook dari Telegram ─────────────────────────────────────
    case 'tg_webhook':
        $update = json_decode(file_get_contents('php://input'), true);
        
        // Pass to the notification/transaction webhook logic first
        // It will NOT exit if it doesn't recognize the command, allowing LiveChat to process it.
        require_once __DIR__ . '/webhook.php';

        // Handle callback_query (inline button dari Telegram)
        if (!empty($update['callback_query'])) {
            $cb     = $update['callback_query'];
            $cbId   = $cb['id'];
            $cbData = $cb['data'] ?? '';

            [$cbAction, $cbSessId] = array_pad(explode(':', $cbData, 2), 2, '');
            $cbSessId = (int)$cbSessId;
            $ackText  = 'Done';

            if ($cbAction === 'uinfo' && $cbSessId) {
                $uId = $cbSessId;
                $uStmt = $pdo->prepare("SELECT u.*, m.name as membership_name FROM users u LEFT JOIN memberships m ON m.id=u.membership_id WHERE u.id=?");
                $uStmt->execute([$uId]);
                $uInfo = $uStmt->fetch();
                
                if ($uInfo) {
                    $lvl = $uInfo['membership_name'] ?: 'Free';
                    $txt = "👤 <b>Info User: {$uInfo['username']}</b>\n"
                         . "Level: {$lvl}\n"
                         . "WD: Rp" . number_format((float)$uInfo['balance_wd'], 0, ',', '.') . "\n"
                         . "Depo: Rp" . number_format((float)$uInfo['balance_dep'], 0, ',', '.');
                         
                    tg_api($pdo, 'sendMessage', [
                        'chat_id' => $cb['message']['chat']['id'],
                        'message_thread_id' => $cb['message']['message_thread_id'] ?? null,
                        'text' => $txt,
                        'parse_mode' => 'HTML'
                    ]);
                    $ackText = "Data dikirim!";
                } else {
                    $ackText = "User tidak ditemukan";
                }
                
                tg_api($pdo, 'answerCallbackQuery', [
                    'callback_query_id' => $cbId,
                    'text'              => $ackText,
                    'show_alert'        => false,
                ]);
                echo '{}'; exit;
            }

            if ($cbAction === 'txnhist' && $cbSessId) {
                $uId = $cbSessId;
                $uStmt = $pdo->prepare("SELECT username FROM users WHERE id=?");
                $uStmt->execute([$uId]);
                $uInfo = $uStmt->fetch();
                
                if ($uInfo) {
                    // Fetch recent deposits (last 5)
                    $depStmt = $pdo->prepare("SELECT amount, status, created_at FROM deposits WHERE user_id=? ORDER BY created_at DESC LIMIT 5");
                    $depStmt->execute([$uId]);
                    $deposits = $depStmt->fetchAll();
                    
                    // Fetch recent withdrawals (last 5)
                    $wdStmt = $pdo->prepare("SELECT amount, status, created_at FROM withdrawals WHERE user_id=? ORDER BY created_at DESC LIMIT 5");
                    $wdStmt->execute([$uId]);
                    $withdrawals = $wdStmt->fetchAll();
                    
                    $txt = "📜 <b>History Depo & WD: @{$uInfo['username']}</b>\n\n";
                    
                    $txt .= "<b>⬆️ DEPOSIT (Last 5):</b>\n";
                    if (empty($deposits)) {
                        $txt .= "<i>(Belum ada deposit)</i>\n";
                    } else {
                        foreach ($deposits as $d) {
                            $statusIcon = $d['status'] === 'confirmed' ? '✅' : ($d['status'] === 'pending' ? '⏳' : '❌');
                            $txt .= "• " . date('d M H:i', strtotime($d['created_at'])) . ": Rp" . number_format((float)$d['amount'], 0, ',', '.') . " {$statusIcon} " . ucfirst($d['status']) . "\n";
                        }
                    }
                    
                    $txt .= "\n<b>💸 WITHDRAW (Last 5):</b>\n";
                    if (empty($withdrawals)) {
                        $txt .= "<i>(Belum ada withdraw)</i>\n";
                    } else {
                        foreach ($withdrawals as $w) {
                            $statusIcon = $w['status'] === 'approved' ? '✅' : ($w['status'] === 'pending' ? '⏳' : ($w['status'] === 'hold' ? '⏸' : '❌'));
                            $txt .= "• " . date('d M H:i', strtotime($w['created_at'])) . ": Rp" . number_format((float)$w['amount'], 0, ',', '.') . " {$statusIcon} " . ucfirst($w['status']) . "\n";
                        }
                    }
                         
                    tg_api($pdo, 'sendMessage', [
                        'chat_id' => $cb['message']['chat']['id'],
                        'message_thread_id' => $cb['message']['message_thread_id'] ?? null,
                        'text' => $txt,
                        'parse_mode' => 'HTML'
                    ]);
                    $ackText = "History terkirim!";
                } else {
                    $ackText = "User tidak ditemukan";
                }
                
                tg_api($pdo, 'answerCallbackQuery', [
                    'callback_query_id' => $cbId,
                    'text'              => $ackText,
                    'show_alert'        => false,
                ]);
                echo '{}'; exit;
            }

            if ($cbAction === 'refhold' && $cbSessId) {
                $uId = $cbSessId;
                $uStmt = $pdo->prepare("SELECT username FROM users WHERE id=?");
                $uStmt->execute([$uId]);
                $uInfo = $uStmt->fetch();
                
                if ($uInfo) {
                    $pdo->beginTransaction();
                    try {
                        $holds = $pdo->prepare("SELECT * FROM withdrawals WHERE user_id=? AND status='hold' FOR UPDATE");
                        $holds->execute([$uId]);
                        $holds = $holds->fetchAll();
                        
                        if (empty($holds)) {
                            $ackText = "Tidak ada WD Hold untuk user ini!";
                            $pdo->commit();
                        } else {
                            $total = 0;
                            foreach ($holds as $h) {
                                $pdo->prepare("UPDATE users SET balance_wd=balance_wd+? WHERE id=?")->execute([$h['amount'], $h['user_id']]);
                                $pdo->prepare("UPDATE withdrawals SET status='refunded',admin_note=?,processed_at=NOW() WHERE id=?")
                                    ->execute(['WD Hold Refunded', $h['id']]);
                                $total += $h['amount'];
                            }
                            $pdo->commit();
                            
                            $txt = "💸 <b>Bulk Refund WD Hold Berhasil!</b>\n"
                                 . "User: @{$uInfo['username']}\n"
                                 . "Jumlah Transaksi: " . count($holds) . " WD\n"
                                 . "Total Refund: <b>Rp" . number_format($total, 0, ',', '.') . "</b>\n"
                                 . "Saldo Penarikan user telah dikembalikan.";
                                 
                            tg_api($pdo, 'sendMessage', [
                                'chat_id' => $cb['message']['chat']['id'],
                                'message_thread_id' => $cb['message']['message_thread_id'] ?? null,
                                'text' => $txt,
                                'parse_mode' => 'HTML'
                            ]);
                            $ackText = "Berhasil me-refund " . count($holds) . " WD Hold!";
                        }
                    } catch (\Throwable $th) {
                        $pdo->rollBack();
                        $ackText = "Error: " . $th->getMessage();
                    }
                } else {
                    $ackText = "User tidak ditemukan";
                }
                
                tg_api($pdo, 'answerCallbackQuery', [
                    'callback_query_id' => $cbId,
                    'text'              => $ackText,
                    'show_alert'        => true,
                ]);
                echo '{}'; exit;
            }

            if ($cbAction === 'req_edit' && $cbSessId) {
                $uId = $cbSessId;
                
                $actualSiteUrl = $siteUrl;
                if (!$actualSiteUrl && isset($_SERVER['HTTP_HOST'])) {
                    $scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
                    $actualSiteUrl = "{$scheme}://{$_SERVER['HTTP_HOST']}";
                }
                
                $userEditLink = "{$actualSiteUrl}/console/user_edit.php?id={$uId}";
                // Force https if http, as Telegram API strictly requires https for web_app
                if (strpos($userEditLink, 'http://') === 0) {
                    $userEditLink = str_replace('http://', 'https://', $userEditLink);
                }
                // Generate time-limited signed token (valid 30 minutes) so admin doesn't need to log in
                $tok_exp    = time() + 1800;
                $tok_secret = hash('sha256', 'TONTON_EDIT_' . ($_ENV['DB_PASSWORD'] ?? 'secret'));
                $tok_sig    = hash_hmac('sha256', "uid={$uId}&exp={$tok_exp}", $tok_secret);
                $tok_b64    = base64_encode("uid={$uId}&exp={$tok_exp}&sig={$tok_sig}");
                $userEditLink = rtrim(str_replace('http://', 'https://', $actualSiteUrl ?: ''), '/')
                              . "/console/user_edit.php?tok=" . urlencode($tok_b64);
                
                // Fetch user info for context
                $euStmt = $pdo->prepare("SELECT u.*, m.name as mem_name FROM users u LEFT JOIN memberships m ON m.id=u.membership_id WHERE u.id=?");
                $euStmt->execute([$uId]);
                $euInfo = $euStmt->fetch();
                
                $pmText = "✏️ <b>Edit User & Saldo</b>\n";
                if ($euInfo) {
                    $lvlName = $euInfo['mem_name'] ?: 'Free';
                    $pmText .= "👤 Username: <b>{$euInfo['username']}</b>\n";
                    $pmText .= "🏅 Level: {$lvlName}\n";
                    $pmText .= "💰 Saldo Penarikan: Rp" . number_format((float)$euInfo['balance_wd'], 0, ',', '.') . "\n";
                    $pmText .= "💳 Saldo Beli: Rp" . number_format((float)$euInfo['balance_dep'], 0, ',', '.') . "\n";
                } else {
                    $pmText .= "User ID: {$uId}\n";
                }
                $pmText .= "\nKlik tombol di bawah untuk membuka Mini App Edit User.";
                
                // Send Private Message to the admin who clicked the button
                $pmRes = tg_api($pdo, 'sendMessage', [
                    'chat_id'    => $cb['from']['id'],
                    'text'       => $pmText,
                    'parse_mode' => 'HTML',
                    'reply_markup' => [
                        'inline_keyboard' => [
                            [['text' => "📱 Buka Mini App Edit User", 'web_app' => ['url' => $userEditLink]]]
                        ]
                    ]
                ]);
                
                if (!empty($pmRes['ok'])) {
                    $ackText = "Berhasil! Silakan cek pesan pribadi (Japri) dari Bot ini untuk membuka Mini App.";
                } else {
                    $ackText = "Gagal kirim pesan. Pastikan kamu sudah Start bot ini secara pribadi (Private Chat) terlebih dahulu!";
                }
                
                tg_api($pdo, 'answerCallbackQuery', [
                    'callback_query_id' => $cbId,
                    'text'              => $ackText,
                    'show_alert'        => true,
                ]);
                echo '{}'; exit;
            }

            if ($cbSessId) {
                $csStmt = $pdo->prepare("SELECT * FROM chat_sessions WHERE id=?");
                $csStmt->execute([$cbSessId]);
                $csRow = $csStmt->fetch();

                if ($csRow) {
                    $tgChatId = setting($pdo, 'lc_tg_chat_id', '');
                    if ($cbAction === 'close_sess') {
                        if ($csRow['status'] === 'open') {
                            $pdo->prepare("UPDATE chat_sessions SET status='closed' WHERE id=?")->execute([$cbSessId]);
                            $pdo->prepare("INSERT INTO chat_messages (session_id,sender,message) VALUES (?,'system','Sesi chat ditutup oleh Admin.')")->execute([$cbSessId]);
                            if ($csRow['tg_thread_id'] && $tgChatId) {
                                tg_api($pdo, 'closeForumTopic', [
                                    'chat_id'           => $tgChatId,
                                    'message_thread_id' => (int)$csRow['tg_thread_id'],
                                ]);
                            }
                            $ackText = 'Sesi ditutup!';
                        } else {
                            $ackText = 'Sesi sudah ditutup.';
                        }
                    } elseif ($cbAction === 'keep_sess') {
                        if ($csRow['status'] === 'open') {
                            $pdo->prepare("UPDATE chat_sessions SET is_kept=1, last_message_at=NOW() WHERE id=?")->execute([$cbSessId]);
                            $pdo->prepare("INSERT INTO chat_messages (session_id,sender,message) VALUES (?,'system','Sesi chat di-keep (permanen) oleh Admin.')")->execute([$cbSessId]);
                            if ($tgChatId) {
                                $tgParams = [
                                    'chat_id' => $tgChatId,
                                    'text'    => "📌 Sesi chat ini di-keep (ditandai penting) oleh Admin dan tidak akan dihapus otomatis.",
                                ];
                                if ($csRow['tg_thread_id']) {
                                    $tgParams['message_thread_id'] = (int)$csRow['tg_thread_id'];
                                }
                                tg_api($pdo, 'sendMessage', $tgParams);
                            }
                            $ackText = 'Sesi diperpanjang!';
                        } else {
                            $ackText = 'Gagal, sesi sudah ditutup.';
                        }
                    } elseif ($cbAction === 'mode_ai') {
                        $pdo->prepare("UPDATE chat_sessions SET mode='ai' WHERE id=?")->execute([$cbSessId]);
                        $pdo->prepare("INSERT INTO chat_messages (session_id,sender,message) VALUES (?,'system','Mode beralih ke Asisten AI oleh Admin.')")->execute([$cbSessId]);
                        $ackText = 'Mode AI aktif';
                    } elseif ($cbAction === 'mode_admin') {
                        $pdo->prepare("UPDATE chat_sessions SET mode='admin' WHERE id=?")->execute([$cbSessId]);
                        $pdo->prepare("INSERT INTO chat_messages (session_id,sender,message) VALUES (?,'system','Mode beralih ke Admin.')")->execute([$cbSessId]);
                        $ackText = 'Mode Admin aktif';
                    } elseif ($cbAction === 'del_thread') {
                        $pdo->prepare("UPDATE chat_sessions SET status='closed' WHERE id=?")->execute([$cbSessId]);
                        if ($csRow['tg_thread_id'] && $tgChatId) {
                            tg_api($pdo, 'deleteForumTopic', [
                                'chat_id'           => $tgChatId,
                                'message_thread_id' => (int)$csRow['tg_thread_id'],
                            ]);
                        }
                        $ackText = 'Sesi dihapus!';
                    } elseif ($cbAction === 'del_msg') {
                        tg_api($pdo, 'deleteMessage', [
                            'chat_id'    => $cb['message']['chat']['id'],
                            'message_id' => $cb['message']['message_id']
                        ]);
                        $ackText = 'Pesan dihapus!';
                    }
                }
            }

            tg_api($pdo, 'answerCallbackQuery', [
                'callback_query_id' => $cbId,
                'text'              => $ackText,
                'show_alert'        => false,
            ]);
            echo '{}'; exit;
        }

        // Handle regular message (admin reply)
        if (empty($update['message'])) { echo '{}'; exit; }

        $msg      = $update['message'];
        $threadId = $msg['message_thread_id'] ?? null;
        $text     = $msg['text'] ?? $msg['caption'] ?? '';
        $fromUser = $msg['from'] ?? [];

        if (!empty($fromUser['is_bot'])) { echo '{}'; exit; }
        
        // Intercept /panel command in private chat for Mini App (Admin only)
        if (($msg['chat']['type'] ?? '') === 'private' && strpos(trim($text), '/panel') === 0) {
            $siteUrl = rtrim(setting($pdo, 'lc_site_url', ''), '/');
            if (!$siteUrl && isset($_SERVER['HTTP_HOST'])) {
                $scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
                $siteUrl = "{$scheme}://{$_SERVER['HTTP_HOST']}";
            }
            $miniAppUrl = "{$siteUrl}/console/miniapp.php";
            if (strpos($miniAppUrl, 'http://') === 0) {
                $miniAppUrl = str_replace('http://', 'https://', $miniAppUrl);
            }
            
            tg_api($pdo, 'sendMessage', [
                'chat_id' => $msg['chat']['id'],
                'text' => "Halo Admin! 👋\nIni adalah Bot Livechat & Panel Admin.\nKlik tombol di bawah untuk membuka Mini App manajemen user.",
                'reply_markup' => [
                    'inline_keyboard' => [
                        [['text' => "📱 Buka Panel Admin (Mini App)", 'web_app' => ['url' => $miniAppUrl]]]
                    ]
                ]
            ]);
            echo '{}'; exit;
        }
        
        // Handle attachment download
        $attachmentPath = null;
        $fileId = null;
        if (!empty($msg['photo'])) {
            $photo = end($msg['photo']);
            $fileId = $photo['file_id'];
        } elseif (!empty($msg['document'])) {
            $fileId = $msg['document']['file_id'];
        }
        
        if ($fileId && setting($pdo, 'lc_attachment_enabled', '1') === '1') {
            $token = setting($pdo, 'lc_tg_token', '');
            $fileInfo = tg_api($pdo, 'getFile', ['file_id' => $fileId]);
            if (!empty($fileInfo['result']['file_path'])) {
                $filePath = $fileInfo['result']['file_path'];
                $dlUrl = "https://api.telegram.org/file/bot{$token}/{$filePath}";
                $fileData = @file_get_contents($dlUrl);
                if ($fileData) {
                    $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION)) ?: 'jpg';
                    $dir = __DIR__ . '/uploads/chat/' . date('Y/m');
                    if (!is_dir($dir)) mkdir($dir, 0777, true);
                    $filename = uniqid('tg_') . '.' . $ext;
                    if (file_put_contents($dir . '/' . $filename, $fileData)) {
                        $attachmentPath = 'uploads/chat/' . date('Y/m') . '/' . $filename;
                    }
                }
            }
        }

        if (!$threadId || (!$text && !$attachmentPath)) { echo '{}'; exit; }

        $s = $pdo->prepare("SELECT * FROM chat_sessions WHERE tg_thread_id=? AND status='open' LIMIT 1");
        $s->execute([$threadId]);
        $sess = $s->fetch();
        if (!$sess) { echo '{}'; exit; }

        $adminName = trim(($fromUser['first_name'] ?? '') . ' ' . ($fromUser['last_name'] ?? '')) ?: 'Admin';
        $fullText  = "[{$adminName}] {$text}";

        $pdo->prepare(
            "INSERT INTO chat_messages (session_id,sender,message,attachment,tg_msg_id) VALUES (?,'admin',?,?,?)"
        )->execute([$sess['id'], $fullText, $attachmentPath, $msg['message_id']]);

        if ($sess['mode'] === 'ai') {
            $pdo->prepare("UPDATE chat_sessions SET mode='admin' WHERE id=?")->execute([$sess['id']]);
        }

        echo '{}';
        exit;


    default:
        json_err('Action tidak dikenal.', 404);
}
} catch (\Throwable $e) {
    error_log('[chat_action] UNCAUGHT ' . get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    json_err('Server error: ' . $e->getMessage(), 500);
}
