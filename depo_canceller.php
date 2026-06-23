<?php
declare(strict_types=1);

if (!isset($pdo)) {
    return;
}

try {
    $stmt = $pdo->prepare("UPDATE deposits SET status = 'rejected' WHERE status = 'pending' AND created_at < DATE_SUB(NOW(), INTERVAL 3 HOUR)");
    $stmt->execute();
} catch (\Throwable $th) {
    // Silently fail to not interrupt the request
}
