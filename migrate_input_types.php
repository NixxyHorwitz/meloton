<?php
require __DIR__ . '/bootstrap.php';

try {
    $pdo->exec("ALTER TABLE users ADD COLUMN acc_num_input_type VARCHAR(10) DEFAULT 'typed'");
    echo "Column acc_num_input_type added.\n";
} catch (Exception $e) {
    echo "Error adding acc_num_input_type: " . $e->getMessage() . "\n";
}

try {
    $pdo->exec("ALTER TABLE users ADD COLUMN acc_name_input_type VARCHAR(10) DEFAULT 'typed'");
    echo "Column acc_name_input_type added.\n";
} catch (Exception $e) {
    echo "Error adding acc_name_input_type: " . $e->getMessage() . "\n";
}
