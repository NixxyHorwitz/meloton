<?php
require 'c:\laragon\www\meloton\bootstrap.php';
$stmt = $pdo->query("SHOW COLUMNS FROM videos");
$cols = $stmt->fetchAll(PDO::FETCH_COLUMN);
print_r($cols);
