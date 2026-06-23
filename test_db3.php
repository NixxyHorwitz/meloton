<?php
require __DIR__ . '/bootstrap.php';
$stmt = $pdo->query("SHOW COLUMNS FROM users");
print_r(array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'Field'));
