<?php
require 'bootstrap.php';
$stmt = $pdo->query('DESCRIBE users');
header('Content-Type: application/json');
echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC), JSON_PRETTY_PRINT);
