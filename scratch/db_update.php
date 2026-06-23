<?php
require __DIR__ . '/../bootstrap.php';
try {
    $pdo->exec("ALTER TABLE users ADD COLUMN refund_cut_percent DECIMAL(5,2) NOT NULL DEFAULT 20.00");
    echo "Added refund_cut_percent. ";
} catch (\Exception $e) {
    echo "refund_cut_percent error: " . $e->getMessage() . ". ";
}

try {
    $pdo->exec("ALTER TABLE users ADD COLUMN is_refund_enabled TINYINT(1) NOT NULL DEFAULT 1");
    echo "Added is_refund_enabled.";
} catch (\Exception $e) {
    echo "is_refund_enabled error: " . $e->getMessage() . ".";
}
