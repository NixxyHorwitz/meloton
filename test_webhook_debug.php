<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');
require __DIR__ . '/bootstrap.php';

echo "--- PHP ERRORS ---\n";

try {
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Simulate webhook handling
    $fake_input = [
        "callback_query" => [
            "id" => "123",
            "data" => "depo_accexp_1", // Modify this as needed
            "message" => [
                "message_id" => 1,
                "chat" => ["id" => 12345]
            ]
        ]
    ];
    
    // Test if we can read the deposits table
    echo "Testing deposits table query...\n";
    $dep = $pdo->prepare("SELECT * FROM deposits WHERE id=1 FOR UPDATE");
    $dep->execute(); 
    echo "Query OK. Found: " . ($dep->fetch() ? "Yes" : "No") . "\n";
    
    // Test referral commissions table
    echo "Testing referral_commissions table insert query simulation...\n";
    $pdo->prepare("SELECT user_id, from_user_id, amount FROM referral_commissions LIMIT 1")->execute();
    echo "referral_commissions OK.\n";
    
    // Test users table for refund_cut_percent
    echo "Testing users table columns...\n";
    $s = $pdo->query("SHOW COLUMNS FROM users");
    $cols = array_column($s->fetchAll(PDO::FETCH_ASSOC), 'Field');
    echo "Columns in users: " . implode(", ", $cols) . "\n";
    
} catch (Exception $e) {
    echo "EXCEPTION: " . $e->getMessage() . "\n";
}
