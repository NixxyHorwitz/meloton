<?php

namespace YuF1Dev;

/**
 * [OrderKuota] OrderKuota Api PHP Class (Un-Official)
 * Author : YuF1Dev <https://github.com/yuf1dev>
 * Created at 10-10-2023 00:22
 * Last Modified at 21-12-2025 02:10
 */
class OrderKuota
{
    const API_URL = 'https://app.orderkuota.com:443/api/v2';
    const API_URL_EWALLET = 'https://checker.orderkuota.com:443/api/checkname/produk/095f701f85/11/1263871';
    const API_URL_ORDER = 'https://app.orderkuota.com:443/api/v2/order';
    const HOST = 'app.orderkuota.com';
    const USER_AGENT = 'okhttp/4.12.0';
    const APP_VERSION_NAME = '25.08.11';
    const APP_VERSION_CODE = '250811';
    const APP_REG_ID = 'di309HvATsaiCppl5eDpoc:APA91bFUcTOH8h2XHdPRz2qQ5Bezn-3_TaycFcJ5pNLGWpmaxheQP9Ri0E56wLHz0_b1vcss55jbRQXZgc9loSfBdNa5nZJZVMlk7GS1JDMGyFUVvpcwXbMDg8tjKGZAurCGR4kDMDRJ';
    const PHONE_MODEL = 'SM-G960N';
    const PHONE_UUID = 'di309HvATsaiCppl5eDpoc';
    
    // URL Proxy Server Anda
    const PROXY_URL = 'https://hosting.bersamakita.my.id/proxy.php';
    
    private $authToken, $username;

    public function __construct($username = false, $authToken = false)
    {
        if ($username) {
            $this->username = $username;
        }
        if ($authToken) {
            $this->authToken = $authToken;
        }
    }

    //Login pertama
    public function loginRequest($username, $password)
    {
        $payload = "username=" . $username . "&password=" . $password . "&app_reg_id=" . self::APP_REG_ID . "&app_version_code=" . self::APP_VERSION_CODE . "&app_version_name=" . self::APP_VERSION_NAME . "";
        return self::Request(self::API_URL . '/login', "POST", $payload, true);
    }

    //Login kedua
    public function getAuthToken($username, $otp)
    {
        $payload = "username=" . $username . "&password=" . $otp . "&app_reg_id=" . self::APP_REG_ID . "&app_version_code=" . self::APP_VERSION_CODE . "&app_version_name=" . self::APP_VERSION_NAME . "";
        return self::Request(self::API_URL . '/login', "POST", $payload, true);
    }


    private function getBasePayload()
    {
        return [
            "request_time" => round(microtime(true) * 1000), // Menggunakan milidetik
            "app_reg_id" => self::APP_REG_ID,
            "phone_android_version" => "15", // Sesuai data Anda
            "app_version_code" => self::APP_VERSION_CODE,
            "phone_uuid" => self::PHONE_UUID,
            "auth_username" => $this->username,
            "auth_token" => $this->authToken,
            "app_version_name" => self::APP_VERSION_NAME,
            "ui_mode" => "light",
            "phone_model" => self::PHONE_MODEL
        ];
    }

    /**
     * Reset PIN Transaksi
     * Endpoint: /api/v2/reset-pin
     */
    public function resetPin()
    {
        $data_post = $this->getBasePayload();

        // Mengirimkan payload standar (device info + auth) ke endpoint reset-pin
        $payload = http_build_query($data_post);
        return $this->Request(self::API_URL . '/reset-pin', "POST", $payload, true);
    }

    /**
     * Update Place Order sesuai payload yang Anda berikan
     */
    public function placeOrder($sku_id, $target_phone, $nominal = 0, $pin = "")
    {
        $data_post = $this->getBasePayload();

        $data_post["quantity"] = 1;
        $data_post["phone"] = $target_phone; // Nomor tujuan
        $data_post["id_plgn"] = $nominal; // Nominal yang diinput
        $data_post["voucher_id"] = $sku_id; // ID voucher (seperti 3056)
        $data_post["payment"] = "balance";
        $data_post["kode_promo"] = "";
        $data_post["pin"] = "$pin";

        $payload = http_build_query($data_post);
        return $this->Request(self::API_URL_ORDER, "POST", $payload, true);
    }

    /**
     * Get QRIS Ajaib History
     * @param int $page Halaman history
     */
    public function getQrisAjaibHistory($page = 1)
    {
        $data_post = $this->getBasePayload();

        // Parameter khusus untuk history qris ajaib
        $data_post["requests[0]"] = "qris_ajaib_history";
        $data_post["requests[qris_ajaib_history][page]"] = $page;

        $payload = http_build_query($data_post);
        return $this->Request(self::API_URL . '/get', "POST", $payload, true);
    }

    /**
     * Pengecekan status QRIS Ajaib berdasarkan ID melalui history
     * @param int $targetId ID transaksi yang dicari (contoh: 3531021)
     */
    /**
     * Pengecekan status QRIS Ajaib berdasarkan ID melalui history
     */
    public function checkStatusByHistoryId($targetId)
    {
        $response = $this->getQrisAjaibHistory(1);
        $data = json_decode($response, true);

        if (isset($data['success']) && $data['success'] == true) {
            $results = $data['qris_ajaib_history']['results'] ?? [];

            foreach ($results as $item) {
                // Validasi ID HARUS SAMA dengan yang diinput
                if ($item['id'] == $targetId) {
                    return [
                        'status'  => true,
                        'data'    => $item
                    ];
                }
            }
        }
        return ['status' => false];
    }

    /**
     * Mengambil Syarat & Ketentuan QRIS Ajaib (Min/Max Deposit)
     */
    public function getQrisAjaibTerms()
    {
        $data_post = $this->getBasePayload();
        $data_post["requests[0]"] = "qris_ajaib_terms";

        $payload = http_build_query($data_post);
        return $this->Request(self::API_URL . '/get', "POST", $payload, true);
    }

    /**
     * Membuat Request QRIS Ajaib (Generate QR Code)
     * @param int $amount Nominal deposit
     */
    public function createQrisAjaib($amount)
    {
        // Kita susun array secara manual agar urutannya mirip dengan log
        $data_post = [
            "requests[qris_ajaib][amount]" => $amount,
            "request_time" => round(microtime(true) * 1000),
            "app_reg_id" => self::APP_REG_ID,
            "phone_android_version" => "14",
            "app_version_code" => self::APP_VERSION_CODE,
            "phone_uuid" => self::PHONE_UUID,
            "auth_username" => $this->username,
            "auth_token" => $this->authToken,
            "app_version_name" => self::APP_VERSION_NAME,
            "ui_mode" => "light",
            "phone_model" => self::PHONE_MODEL
        ];

        $payload = http_build_query($data_post);
        return $this->Request(self::API_URL . '/get', "POST", $payload, true);
    }
    /**
     * Get QRIS Withdrawal Terms
     * Menambahkan request untuk qris_withdraw_terms
     */
    public function getWithdrawTerms()
    {
        $data_post = [
            "request_time" => time(),
            "app_reg_id" => self::APP_REG_ID,
            "phone_android_version" => "9",
            "app_version_code" => self::APP_VERSION_CODE,
            "phone_uuid" => self::PHONE_UUID,
            "auth_username" => $this->username,
            "auth_token" => $this->authToken,
            "app_version_name" => self::APP_VERSION_NAME,
            "ui_mode" => "light",
            "phone_model" => self::PHONE_MODEL,
            "requests[0]" => "qris_withdraw_terms"
        ];

        $payload = http_build_query($data_post);
        return self::Request(self::API_URL . '/get', "POST", $payload, true);
    }

    /**
     * Get QRIS Mutation using dynamic User ID from Token
     * Endpoint: /api/v2/qris/mutasi/{userId}
     */
    public function gettQrisMutation($page = 1)
    {
        // Mengambil userId dari token (biasanya angka sebelum titik dua)
        $parts = explode(':', $this->authToken);
        $userId = $parts[0] ?? '';

        // Gunakan array untuk payload agar build_query lebih rapi
        $data_post = [
            "request_time" => time(),
            "app_reg_id" => self::APP_REG_ID,
            "phone_android_version" => "9",
            "app_version_code" => self::APP_VERSION_CODE,
            "phone_uuid" => self::PHONE_UUID,
            "auth_username" => $this->username,
            "auth_token" => $this->authToken,
            "app_version_name" => self::APP_VERSION_NAME,
            "ui_mode" => "light",
            "phone_model" => self::PHONE_MODEL,
            "page" => $page
        ];

        // OrderKuota sering meminta parameter qris_history dibungkus seperti ini
        $data_post["requests[qris_history][user_id]"] = $userId;
        $data_post["requests[qris_history][page]"] = $page;

        $payload = http_build_query($data_post);
        $url = self::API_URL . "/qris/mutasi/" . $userId;

        return self::Request($url, "POST", $payload, true);
    }


    public function getTransactionQris($type = '')
    {
        $payload = "request_time=" . time() . "&app_reg_id=" . self::APP_REG_ID . "&phone_android_version=9&app_version_code=" . self::APP_VERSION_CODE . "&phone_uuid=" . self::PHONE_UUID . "&auth_username=" . $this->username . "&requests[1]=point&auth_token=" . $this->authToken . "&app_version_name=" . self::APP_VERSION_NAME . "&ui_mode=light&requests[0]=account&phone_model=" . self::PHONE_MODEL . "";
        $response = self::Request(self::API_URL . '/get', "POST", $payload, true);

        if (!empty($type)) {
            $decoded = json_decode($response, true);
            if (isset($decoded['account']['results']['history'])) {
                $filtered = array_filter($decoded['account']['results']['history'], function ($transaction) use ($type) {
                    return strtolower($transaction['type']) === strtolower($type);
                });
                $decoded['account']['results']['history'] = array_values($filtered);
                return json_encode($decoded);
            }
        }
        return $response;
    }

    public function withdrawalQris($amount = '')
    {
        $payload = "request_time=" . time() . "&app_reg_id=" . self::APP_REG_ID . "&phone_android_version=9&app_version_code=" . self::APP_VERSION_CODE . "&phone_uuid=" . self::PHONE_UUID . "&auth_username=" . $this->username . "&requests[qris_withdraw][amount]=" . $amount . "&auth_token=" . $this->authToken . "&app_version_name=" . self::APP_VERSION_NAME . "&ui_mode=light&requests[0]=account&phone_model=" . self::PHONE_MODEL . "";
        return self::Request(self::API_URL . '/get', "POST", $payload, true);
    }

    public function getBalance()
    {
        $response = $this->getTransactionQris();
        $decoded = json_decode($response, true);

        if (isset($decoded['account']['results']['balance'])) {
            $acc = $decoded['account']['results'];
            return json_encode([
                'success' => true,
                'balance' => $acc['balance'],
                'balance_str' => $acc['balance_str'] ?? null,
                'qris_balance' => $acc['qris_balance'] ?? null,
                'qris_balance_str' => $acc['qris_balance_str'] ?? null,
                'qris' => $acc['qris'] ?? null,
                'qris_name' => $acc['qris_name'] ?? null,
                'point' => $decoded['point']['results']['point'] ?? null
            ]);
        }
        return json_encode(['success' => false, 'message' => 'Unable to retrieve balance']);
    }

    public function getProfile()
    {
        $response = $this->getTransactionQris();
        $decoded = json_decode($response, true);

        if (isset($decoded['account']['results'])) {
            return json_encode(['success' => true, 'profile' => $decoded['account']['results']]);
        }
        return json_encode(['success' => false, 'message' => 'Unable to retrieve profile']);
    }

    protected function buildHeaders()
    {
        // Menambahkan Timestamp dalam milidetik sesuai log aplikasi
        $timestamp = round(microtime(true) * 1000);

        return array(
            'Host: ' . self::HOST,
            'User-Agent: ' . self::USER_AGENT,
            'Content-Type: application/x-www-form-urlencoded',
            'Timestamp: ' . $timestamp,
            'Connection: Keep-Alive'
        );
    }

   
      /**
     * Request langsung ke API OrderKuota tanpa proxy
     */
    protected function Request($url, $type = "GET", $post = false, $headers = false)
    {
        $ch = curl_init();

        $curlOpts = [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 45,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
        ];

        if ($headers) {
            $curlOpts[CURLOPT_HTTPHEADER] = $this->buildHeaders();
        }

        if (strtoupper($type) === 'POST') {
            $curlOpts[CURLOPT_POST]       = true;
            $curlOpts[CURLOPT_POSTFIELDS] = $post;
        } elseif ($post) {
            $curlOpts[CURLOPT_CUSTOMREQUEST] = $type;
            $curlOpts[CURLOPT_POSTFIELDS]    = $post;
        }

        curl_setopt_array($ch, $curlOpts);

        $result    = curl_exec($ch);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($result === false) {
            return json_encode(['success' => false, 'message' => 'cURL ERROR: ' . $curlError]);
        }

        return $result;
    }
}