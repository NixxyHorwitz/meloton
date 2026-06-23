<?php
declare(strict_types=1);

if (!isset($pdo)) {
    return;
}

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("SELECT id, user_id, amount FROM withdrawals WHERE status = 'pending' AND created_at < DATE_SUB(NOW(), INTERVAL 1 DAY) FOR UPDATE");
    $stmt->execute();
    $withdrawals = $stmt->fetchAll();

    if (!empty($withdrawals)) {
        $updateUserStmt = $pdo->prepare("UPDATE users SET balance_wd = balance_wd + ? WHERE id = ?");
        $updateWdStmt = $pdo->prepare("UPDATE withdrawals SET status = 'rejected' WHERE id = ?");
        
        foreach ($withdrawals as $wd) {
            $updateUserStmt->execute([$wd['amount'], $wd['user_id']]);
            $updateWdStmt->execute([$wd['id']]);
        }
    }

    $pdo->commit();
} catch (\Throwable $th) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    // Silently fail to not interrupt the request
}
