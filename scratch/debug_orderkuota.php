<?php
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../lib/OrderKuota.php';

use YuF1Dev\OrderKuota;

echo "=== DEBUG ORDERKUOTA API ===\n";

if (php_sapi_name() !== 'cli') {
    die("Script ini hanya bisa dijalankan lewat CLI.\n");
}

echo "Masukkan Nomor HP / Username: ";
$handle = fopen("php://stdin","r");
$username = trim(fgets($handle));

echo "Masukkan Password: ";
$password = trim(fgets($handle));

$ok = new OrderKuota();

echo "\n[1] MENGIRIM LOGIN REQUEST...\n";
$res1 = $ok->loginRequest($username, $password);
echo "RESPONSE LOGIN:\n";
echo $res1 . "\n\n";

$data1 = json_decode($res1, true);

if (!empty($data1['success']) && empty($data1['auth_token'])) {
    echo ">> API Meminta OTP!\n";
    echo "Masukkan kode OTP yang Anda terima: ";
    $otp = trim(fgets($handle));
    
    echo "\n[2] MENGIRIM VERIFIKASI OTP...\n";
    
    // Mari kita lihat request payload apa yang sebenarnya dikirim:
    echo ">> Menguji Payload Asli (password=otp) ...\n";
    // Sesuai script asli YuF1Dev
    $payloadAsli = "username=" . $username . "&password=" . $otp . "&app_reg_id=" . OrderKuota::APP_REG_ID . "&app_version_code=" . OrderKuota::APP_VERSION_CODE . "&app_version_name=" . OrderKuota::APP_VERSION_NAME . "";
    
    echo "Payload: " . $payloadAsli . "\n";
    
    $ch = curl_init(OrderKuota::API_URL . '/login');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payloadAsli);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Host: app.orderkuota.com',
        'User-Agent: okhttp/4.12.0',
        'Content-Type: application/x-www-form-urlencoded'
    ]);
    
    $res2 = curl_exec($ch);
    echo "RESPONSE OTP (Cara Asli):\n";
    echo $res2 . "\n\n";
    
    // Jika gagal, mari kita coba payload lain
    $data2 = json_decode($res2, true);
    if (empty($data2['success'])) {
        echo ">> GAGAL! Mari coba Payload Alternatif (password=pass&otp=otp)...\n";
        $payloadAlt1 = "username=" . urlencode($username) . "&password=" . urlencode($password) . "&otp=" . urlencode($otp) . "&app_reg_id=" . OrderKuota::APP_REG_ID . "&app_version_code=" . OrderKuota::APP_VERSION_CODE . "&app_version_name=" . OrderKuota::APP_VERSION_NAME;
        echo "Payload: " . $payloadAlt1 . "\n";
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payloadAlt1);
        $res3 = curl_exec($ch);
        echo "RESPONSE OTP (Alternatif 1):\n";
        echo $res3 . "\n\n";

        echo ">> Mari coba Payload Alternatif (otp=otp)...\n";
        $payloadAlt2 = "username=" . urlencode($username) . "&otp=" . urlencode($otp) . "&app_reg_id=" . OrderKuota::APP_REG_ID . "&app_version_code=" . OrderKuota::APP_VERSION_CODE . "&app_version_name=" . OrderKuota::APP_VERSION_NAME;
        echo "Payload: " . $payloadAlt2 . "\n";
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payloadAlt2);
        $res4 = curl_exec($ch);
        echo "RESPONSE OTP (Alternatif 2):\n";
        echo $res4 . "\n\n";
    }

} elseif (!empty($data1['auth_token'])) {
    echo ">> Login Berhasil Tanpa OTP!\n";
    echo "Token: " . $data1['auth_token'] . "\n";
} else {
    echo ">> Login Gagal atau response tidak terduga.\n";
}

echo "Selesai.\n";
