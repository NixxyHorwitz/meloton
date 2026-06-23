<?php
require __DIR__ . '/bootstrap.php';
try {
    $sql = "CREATE TABLE IF NOT EXISTS `forwarder_heartbeats` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `interval_minutes` int(11) NOT NULL DEFAULT 5,
        `device_info` varchar(255) DEFAULT NULL,
        `payload_text` text DEFAULT NULL,
        `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `created_at` (`created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
    
    $pdo->exec($sql);
    echo "Table forwarder_heartbeats created successfully.\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
