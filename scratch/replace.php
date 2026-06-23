<?php
$dir = new RecursiveDirectoryIterator('c:\laragon\www\tonton');
$ite = new RecursiveIteratorIterator($dir);
$files = new RegexIterator($ite, '/^.+\.php$/i', RecursiveRegexIterator::GET_MATCH);

$replacements = [
    'Saldo Deposit' => 'Saldo Beli',
    'saldo deposit' => 'saldo beli',
    'Saldo deposit' => 'Saldo beli',
    'SALDO DEPOSIT' => 'SALDO BELI',
    'Saldo Depo' => 'Saldo Beli',
    'saldo depo' => 'saldo beli',
    'SALDO DEPO' => 'SALDO BELI',
    'Saldo DP' => 'Saldo Beli',
    'SALDO DP' => 'SALDO BELI',
    'Saldo WD' => 'Saldo Penarikan',
    'saldo WD' => 'saldo Penarikan',
    'saldo wd' => 'saldo penarikan',
    'SALDO WD' => 'SALDO PENARIKAN',
    'Saldo Withdraw' => 'Saldo Penarikan',
    'saldo withdraw' => 'saldo penarikan',
    'SALDO WITHDRAW' => 'SALDO PENARIKAN'
];

foreach ($files as $file) {
    $path = $file[0];
    if (strpos($path, 'vendor') !== false || strpos($path, 'scratch') !== false) continue;
    $content = file_get_contents($path);
    $newContent = strtr($content, $replacements);
    if ($newContent !== $content) {
        file_put_contents($path, $newContent);
        echo "Updated $path\n";
    }
}
