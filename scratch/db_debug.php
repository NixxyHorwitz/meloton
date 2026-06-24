<?php
require 'c:\laragon\www\meloton\bootstrap.php';
try {
    echo "Connected to: " . $_ENV['DB_HOST'] . "\n";
    $stmt = $pdo->query("SHOW COLUMNS FROM videos");
    $cols = $stmt->fetchAll(PDO::FETCH_COLUMN);
    echo "Current Columns: \n";
    print_r($cols);
    
    if (!in_array('video_type', $cols)) {
        echo "Adding video_type column...\n";
        $pdo->exec("ALTER TABLE videos ADD COLUMN video_type ENUM('youtube', 'tiktok') NOT NULL DEFAULT 'youtube' AFTER id");
        echo "Column video_type added successfully.\n";
        
        $stmt2 = $pdo->query("SHOW COLUMNS FROM videos");
        $cols2 = $stmt2->fetchAll(PDO::FETCH_COLUMN);
        echo "New Columns: \n";
        print_r($cols2);
    } else {
        echo "Column already exists.\n";
    }
} catch (Throwable $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
