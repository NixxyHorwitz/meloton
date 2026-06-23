<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');
require __DIR__ . '/bootstrap.php';

try {
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->beginTransaction();
    $id = 1;
    $req = $pdo->prepare("SELECT r.*, u.username, u.balance_dep FROM admin_requests r JOIN users u ON u.id = r.user_id WHERE r.id=? FOR UPDATE");
    $req->execute([$id]); 
    $req = $req->fetch(PDO::FETCH_ASSOC);
    
    if ($req) {
        $payload = json_decode($req['payload'], true);
        $pdo->prepare("UPDATE users SET bank_name=?, account_number=?, account_name=? WHERE id=?")
            ->execute([$payload['bank_name'], $payload['account_number'], $payload['account_name'], $req['user_id']]);
        echo "Update users OK.\n";
    }
    $pdo->rollBack();
    echo "Done.\n";
} catch (Exception $e) {
    echo "EXCEPTION: " . $e->getMessage() . "\n";
}
