<?php
require_once __DIR__ . '/../bootstrap.php';

$stmt = $pdo->query("SELECT id, name, price FROM memberships ORDER BY sort_order ASC, id ASC");
$memberships = $stmt->fetchAll(PDO::FETCH_ASSOC);

$new_names = [
    'Warga Biasa',
    'Juragan Silver',
    'Sultan Emas',
    'Bos Berlian',
    'Raja Legendaris',
    'Dewa Mitos'
];

foreach ($memberships as $index => $m) {
    if (isset($new_names[$index])) {
        $newName = $new_names[$index];
        $pdo->prepare("UPDATE memberships SET name = ? WHERE id = ?")->execute([$newName, $m['id']]);
        echo "Updated {$m['name']} to {$newName}\n";
    }
}
echo "Done.\n";
