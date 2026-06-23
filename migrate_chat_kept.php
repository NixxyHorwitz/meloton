<?php
require_once __DIR__ . '/bootstrap.php';

try {
    $pdo->exec("ALTER TABLE chat_sessions ADD COLUMN is_kept TINYINT(1) DEFAULT 0");
    echo "Column is_kept added.\n";
} catch (PDOException $e) {
    if ($e->getCode() == '42S21') {
        echo "Column is_kept already exists.\n";
    } else {
        echo "Error: " . $e->getMessage() . "\n";
    }
}
