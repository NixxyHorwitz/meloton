<?php
require 'c:\laragon\www\tonton\bootstrap.php';
try {
    $stmt = $pdo->query('DESCRIBE withdrawals');
    print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
} catch(PDOException $e) {
    if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
        echo "ALTER TABLE SUCCESS (Already exists)";
    } else {
        echo "ALTER TABLE FAILED: " . $e->getMessage();
    }
}
