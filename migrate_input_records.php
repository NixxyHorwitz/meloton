<?php
require_once __DIR__ . '/bootstrap.php';

try {
    $pdo->exec("ALTER TABLE users ADD COLUMN acc_num_record TEXT NULL");
    echo "Column acc_num_record added.\n";
} catch (PDOException $e) {
    if ($e->getCode() == '42S21') {
        echo "Column acc_num_record already exists.\n";
    } else {
        echo "Error: " . $e->getMessage() . "\n";
    }
}

try {
    $pdo->exec("ALTER TABLE users ADD COLUMN acc_name_record TEXT NULL");
    echo "Column acc_name_record added.\n";
} catch (PDOException $e) {
    if ($e->getCode() == '42S21') {
        echo "Column acc_name_record already exists.\n";
    } else {
        echo "Error: " . $e->getMessage() . "\n";
    }
}
