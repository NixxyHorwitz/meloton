<?php
// HAPUS FILE INI SETELAH SELESAI DEBUG!
if (php_sapi_name() !== 'cli') {
    header('Content-Type: text/plain; charset=utf-8');
}
require __DIR__ . '/../bootstrap.php';

$chatId = setting($pdo, 'lc_tg_chat_id', '');
$token  = setting($pdo, 'lc_tg_token', '');

echo "=== TG TOPIC DEBUG ===\n";
echo "chat_id: $chatId\n";
echo "token: " . (strlen($token) > 5 ? substr($token, 0, 8) . '...' : 'KOSONG/TIDAK ADA') . "\n";
echo "lc_tg_forum setting: " . setting($pdo, 'lc_tg_forum', '?') . "\n\n";

if (!$chatId || !$token) {
    echo "ERROR: lc_tg_chat_id atau lc_tg_token belum diset di database!\n";
    exit;
}

// Test 1: getMe - apakah token valid?
echo "--- Test getMe ---\n";
$ch = curl_init("https://api.telegram.org/bot{$token}/getMe");
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
$res = curl_exec($ch);
$err = curl_error($ch);
curl_close($ch);
echo "CURL err: " . ($err ?: 'none') . "\n";
$data = json_decode($res ?: '{}', true);
echo "ok: " . ($data['ok'] ?? 'n/a') . "\n";
echo "bot: " . ($data['result']['username'] ?? 'n/a') . "\n\n";

// Test 2: getChat - apakah bot bisa akses group?
echo "--- Test getChat ---\n";
$ch = curl_init("https://api.telegram.org/bot{$token}/getChat");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode(['chat_id' => $chatId]),
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    CURLOPT_TIMEOUT        => 10,
]);
$res = curl_exec($ch);
$err = curl_error($ch);
curl_close($ch);
echo "CURL err: " . ($err ?: 'none') . "\n";
$data = json_decode($res ?: '{}', true);
echo "ok: " . ($data['ok'] ?? 'n/a') . "\n";
echo "type: " . ($data['result']['type'] ?? 'n/a') . "\n";
echo "is_forum: " . (isset($data['result']['is_forum']) ? ($data['result']['is_forum'] ? 'YES' : 'NO') : 'n/a') . "\n";
if (!empty($data['description'])) echo "error: " . $data['description'] . "\n";
echo "\n";

// Test 3: createForumTopic
echo "--- Test createForumTopic ---\n";
$ch = curl_init("https://api.telegram.org/bot{$token}/createForumTopic");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode([
        'chat_id'    => $chatId,
        'name'       => 'Debug Test ' . date('H:i:s'),
        'icon_color' => 7322096,
    ]),
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    CURLOPT_TIMEOUT        => 10,
]);
$res = curl_exec($ch);
$err = curl_error($ch);
curl_close($ch);
echo "CURL err: " . ($err ?: 'none') . "\n";
$data = json_decode($res ?: '{}', true);
echo "ok: " . ($data['ok'] ?? 'n/a') . "\n";
if (!empty($data['description'])) echo "error: " . $data['description'] . "\n";
if (!empty($data['result']['message_thread_id'])) echo "thread_id: " . $data['result']['message_thread_id'] . "\n";

echo "\n=== SELESAI ===\n";
