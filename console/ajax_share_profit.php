<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
staff_require('investments');

// Set JSON header
header('Content-Type: application/json');

// Enforce POST and verify CSRF
csrf_enforce();

$action = $_POST['action'] ?? '';

if ($action === 'get_active') {
    try {
        $stmt = $pdo->query("
            SELECT ui.id, ui.amount, ui.daily_profit, ui.days_passed, ui.duration_days, u.username, u.email, IFNULL(ip.name, 'Paket Investasi') as package_name 
            FROM user_investments ui 
            JOIN users u ON ui.user_id = u.id 
            LEFT JOIN investment_packages ip ON ui.package_id = ip.id 
            WHERE ui.status = 'active'
            ORDER BY ui.id ASC
        ");
        $active = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['status' => 'success', 'data' => $active]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

if ($action === 'process_single') {
    $id = (int)($_POST['id'] ?? 0);
    if (!$id) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'ID Portofolio tidak valid.']);
        exit;
    }
    
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("SELECT * FROM user_investments WHERE id = ? AND status = 'active' FOR UPDATE");
        $stmt->execute([$id]);
        $inv = $stmt->fetch();
        if (!$inv) {
            throw new Exception("Portofolio investasi tidak aktif atau tidak ditemukan.");
        }
        if ((int)$inv['days_passed'] >= (int)$inv['duration_days']) {
            throw new Exception("Portofolio investasi sudah selesai.");
        }
        
        $profit = (float)$inv['daily_profit'];
        $new_days_passed = (int)$inv['days_passed'] + 1;
        $new_status = ($new_days_passed >= (int)$inv['duration_days']) ? 'completed' : 'active';
        
        // Update investment
        $upd = $pdo->prepare("UPDATE user_investments SET days_passed = ?, last_profit_claimed_at = NOW(), status = ? WHERE id = ?");
        $upd->execute([$new_days_passed, $new_status, $id]);
        
        // Add profit to balance_wd and total_earned directly
        $upd_user = $pdo->prepare("UPDATE users SET balance_wd = balance_wd + ?, total_earned = total_earned + ? WHERE id = ?");
        $upd_user->execute([$profit, $profit, $inv['user_id']]);
        
        // Write to profit logs
        $log = $pdo->prepare("INSERT INTO investment_profit_logs (user_id, user_investment_id, amount, days_claimed, claimed_at) VALUES (?, ?, ?, 1, NOW())");
        $log->execute([$inv['user_id'], $id, $profit]);
        
        $pdo->commit();
        
        echo json_encode([
            'status' => 'success',
            'message' => 'Profit berhasil dibagikan.',
            'detail' => [
                'profit' => $profit,
                'new_days_passed' => $new_days_passed,
                'new_status' => $new_status
            ]
        ]);
    } catch (Exception $e) {
        $pdo->rollBack();
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

if ($action === 'process_all_background') {
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->query("SELECT * FROM user_investments WHERE status = 'active' FOR UPDATE");
        $investments = $stmt->fetchAll();
        
        $processed = 0;
        $total_profit = 0.0;
        
        foreach ($investments as $inv) {
            if ((int)$inv['days_passed'] < (int)$inv['duration_days']) {
                $profit = (float)$inv['daily_profit'];
                $new_days_passed = (int)$inv['days_passed'] + 1;
                $new_status = ($new_days_passed >= (int)$inv['duration_days']) ? 'completed' : 'active';
                
                // Update investment
                $upd = $pdo->prepare("UPDATE user_investments SET days_passed = ?, last_profit_claimed_at = NOW(), status = ? WHERE id = ?");
                $upd->execute([$new_days_passed, $new_status, $inv['id']]);
                
                // Add profit to balance_wd and total_earned directly
                $upd_user = $pdo->prepare("UPDATE users SET balance_wd = balance_wd + ?, total_earned = total_earned + ? WHERE id = ?");
                $upd_user->execute([$profit, $profit, $inv['user_id']]);
                
                // Write to profit logs
                $log = $pdo->prepare("INSERT INTO investment_profit_logs (user_id, user_investment_id, amount, days_claimed, claimed_at) VALUES (?, ?, ?, 1, NOW())");
                $log->execute([$inv['user_id'], $inv['id'], $profit]);
                
                $processed++;
                $total_profit += $profit;
            }
        }
        
        $pdo->commit();
        echo json_encode([
            'status' => 'success',
            'message' => 'Semua profit berhasil dibagikan di background.',
            'data' => [
                'total_processed' => $processed,
                'total_profit' => $total_profit
            ]
        ]);
    } catch (Exception $e) {
        $pdo->rollBack();
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

http_response_code(400);
echo json_encode(['status' => 'error', 'message' => 'Action tidak valid.']);
