<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

// Simple one-time migration — delete this file after running!
if (php_sapi_name() !== 'cli') {
    header('Content-Type: text/plain; charset=utf-8');
}

echo "=== Meloton Missions Migration ===\n\n";

// ── 1. Create missions table ──────────────────────────────────
$pdo->exec("CREATE TABLE IF NOT EXISTS missions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    slug VARCHAR(100) UNIQUE NOT NULL,
    category ENUM('daily','weekly','lifetime') NOT NULL,
    title VARCHAR(200) NOT NULL,
    description TEXT,
    target_value INT NOT NULL DEFAULT 1,
    reward_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    icon VARCHAR(100) DEFAULT 'ph-trophy',
    sort_order INT DEFAULT 0,
    is_active TINYINT(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
echo "✅ Table `missions` created.\n";

// ── 2. Create user_missions table ─────────────────────────────
$pdo->exec("CREATE TABLE IF NOT EXISTS user_missions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    mission_slug VARCHAR(100) NOT NULL,
    progress INT NOT NULL DEFAULT 0,
    completed_at DATETIME NULL,
    claimed_at DATETIME NULL,
    period_key VARCHAR(20) NULL,
    UNIQUE KEY uq_user_mission_period (user_id, mission_slug, period_key),
    INDEX idx_user_missions_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
echo "✅ Table `user_missions` created.\n";

// ── 3. Seed missions ──────────────────────────────────────────
$missions = [
    // HARIAN
    ['daily_watch_3',       'daily',    'Tonton 3 Video Hari Ini',       'Tonton minimal 3 video hari ini untuk klaim reward.',  3, 200,    'ph-film-slate',    10],
    ['daily_watch_5',       'daily',    'Tonton 5 Video Hari Ini',       'Tonton minimal 5 video hari ini untuk klaim reward.',  5, 500,    'ph-film-reel',     20],
    ['daily_checkin',       'daily',    'Check-in Hari Ini',             'Lakukan check-in harian untuk mendapatkan bonus.',     1, 100,    'ph-calendar-check',30],
    // MINGGUAN
    ['weekly_streak_7',     'weekly',   'Streak 7 Hari Berturut',        'Check-in setiap hari selama 7 hari dalam seminggu.',   7, 2000,   'ph-fire',          10],
    ['weekly_watch_20',     'weekly',   'Tonton 20 Video Minggu Ini',    'Tonton total 20 video dalam minggu ini.',              20, 1500,  'ph-television',    20],
    ['weekly_watch_7days',  'weekly',   'Aktif 7 Hari (Tonton)',         'Tonton video di setiap hari dalam 7 hari minggu ini.', 7, 2500,   'ph-star',          30],
    // LIFETIME
    ['lifetime_first_ref',  'lifetime', 'Daftarkan 1 Referral',         'Ajak 1 teman bergabung menggunakan kode referralmu.',  1, 5000,   'ph-user-plus',     10],
    ['lifetime_5_refs',     'lifetime', 'Agen Rekruter (5 Referral)',    'Ajak 5 teman bergabung menggunakan kode referralmu.', 5, 15000,  'ph-users-three',   20],
    ['lifetime_first_wd',   'lifetime', 'Penarikan Pertama',             'Lakukan penarikan saldo untuk pertama kalinya.',       1, 3000,   'ph-money',         30],
    ['lifetime_100_videos', 'lifetime', 'Penonton Sejati (100 Video)',   'Tonton total 100 video di Meloton.',                100, 10000, 'ph-popcorn',       40],
    ['lifetime_upgrade',    'lifetime', 'Member Premium',                'Upgrade ke paket membership berbayar.',               1, 8000,   'ph-crown',         50],
];

$stmt = $pdo->prepare("INSERT IGNORE INTO missions (slug, category, title, description, target_value, reward_amount, icon, sort_order)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?)");

foreach ($missions as $m) {
    $stmt->execute($m);
    echo "  → Seeded mission: {$m[2]}\n";
}

echo "\n✅ All " . count($missions) . " missions seeded.\n";
echo "\n🎉 Migration complete! You can delete this file now.\n";
