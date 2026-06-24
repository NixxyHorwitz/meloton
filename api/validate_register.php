<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/bootstrap.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'msg' => 'Invalid request']);
    exit;
}

$action = $_POST['action'] ?? '';
$val = trim($_POST['val'] ?? '');
$response = ['status' => 'ok', 'msg' => ''];

if ($action === 'username') {
    if (strlen($val) < 3 || !preg_match('/^[a-zA-Z0-9_]+$/', $val)) {
        $response = ['status' => 'error', 'msg' => 'Username 3-30 karakter (huruf/angka/_)'];
    } else {
        $chk = $pdo->prepare("SELECT id FROM users WHERE username=?");
        $chk->execute([$val]);
        if ($chk->fetch()) $response = ['status' => 'error', 'msg' => 'Username sudah digunakan'];
    }
} elseif ($action === 'email') {
    if (!filter_var($val, FILTER_VALIDATE_EMAIL)) {
        $response = ['status' => 'error', 'msg' => 'Format email salah'];
    } else {
        $chk = $pdo->prepare("SELECT id FROM users WHERE email=?");
        $chk->execute([$val]);
        if ($chk->fetch()) $response = ['status' => 'error', 'msg' => 'Email sudah terdaftar'];
    }
} elseif ($action === 'phone') {
    $val = preg_replace('/\D/', '', $val);
    if (strlen($val) < 9) {
        $response = ['status' => 'error', 'msg' => 'Nomor WhatsApp tidak valid'];
    } else {
        $chk = $pdo->prepare("SELECT id FROM users WHERE whatsapp=?");
        $chk->execute([$val]);
        if ($chk->fetch()) $response = ['status' => 'error', 'msg' => 'Nomor WhatsApp sudah terdaftar'];
    }
} elseif ($action === 'bank') {
    $bank = trim($_POST['bank'] ?? '');
    if (!$val || !$bank) {
        $response = ['status' => 'error', 'msg' => 'Data bank/rekening tidak lengkap'];
    } else {
        $chk = $pdo->prepare("SELECT id FROM users WHERE bank_name=? AND account_number=?");
        $chk->execute([$bank, $val]);
        if ($chk->fetch()) $response = ['status' => 'error', 'msg' => 'Rekening/E-Wallet ini sudah pernah didaftarkan'];
    }
} else {
    $response = ['status' => 'error', 'msg' => 'Aksi tidak valid'];
}

echo json_encode($response);
