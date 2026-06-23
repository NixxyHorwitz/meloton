<?php
require_once __DIR__ . '/bootstrap.php';

try {
    $pdo->exec("ALTER TABLE `users` ADD COLUMN `refund_cut_percent` DECIMAL(5,2) NOT NULL DEFAULT 20.00;");
    echo "<h1>✅ SUKSES: refund_cut_percent!</h1>";
} catch (PDOException $e) {
    echo "<p>Info (refund_cut_percent): " . $e->getMessage() . "</p>";
}

try {
    $pdo->exec("ALTER TABLE `users` ADD COLUMN `is_refund_enabled` TINYINT(1) NOT NULL DEFAULT 1;");
    echo "<h1>✅ SUKSES: is_refund_enabled!</h1>";
} catch (PDOException $e) {
    echo "<p>Info (is_refund_enabled): " . $e->getMessage() . "</p>";
}

