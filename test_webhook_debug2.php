<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');
require __DIR__ . '/bootstrap.php';

try {
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    // test req_approve query
    $req = $pdo->prepare("SELECT r.*, u.username, u.balance_dep FROM admin_requests r JOIN users u ON u.id = r.user_id LIMIT 1");
    $req->execute();
    print_r($req->fetch(PDO::FETCH_ASSOC));
} catch (Exception $e) {
    echo "EXCEPTION: " . $e->getMessage() . "\n";
}
