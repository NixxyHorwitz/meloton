<?php
// Script Pemindai Backdoor Sederhana
// HARAP HAPUS SCRIPT INI SETELAH SELESAI DIGUNAKAN!

$scan_dir = __DIR__;

// Daftar fungsi/pola yang sering digunakan oleh webshell / backdoor
$suspicious_patterns = [
    '/eval\s*\(\s*base64_decode/i',
    '/eval\s*\(\s*\$/i',
    '/system\s*\(/i',
    '/shell_exec\s*\(/i',
    '/exec\s*\(/i',
    '/passthru\s*\(/i',
    '/proc_open\s*\(/i',
    '/popen\s*\(/i',
    '/assert\s*\(\s*\$_/i',
    '/preg_replace\s*\(\s*[\'"].*?e[\'"]/i', // /e modifier
    '/gzinflate\s*\(\s*base64_decode/i',
    '/str_rot13\s*\(\s*base64_decode/i',
    '/urldecode\s*\(\s*\$_/i',
    '/\$_(POST|GET|REQUEST|COOKIE)\[.*?\]\s*\(/i', // Dynamic function execution e.g. $_POST['cmd']()
    '/<script\s+language=[\'"]php[\'"]>/i'
];

$results = [];

function scan_folder($dir, $patterns, &$results) {
    if (!is_dir($dir)) return;
    $files = scandir($dir);
    
    foreach ($files as $file) {
        if ($file === '.' || $file === '..') continue;
        if ($file === 'scan_backdoor.php') continue; // Skip script ini sendiri
        
        $path = $dir . DIRECTORY_SEPARATOR . $file;
        
        if (is_dir($path)) {
            // Jangan scan folder uploads yang isinya gambar (opsional, tapi disarankan file .php di folder upload itu bahaya)
            scan_folder($path, $patterns, $results);
        } else {
            // Kita fokus pada file dengan ekstensi PHP atau file yang mencurigakan (tidak punya ekstensi tapi teks)
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            if (in_array($ext, ['php', 'phtml', 'php5', 'php7', 'inc'])) {
                $content = @file_get_contents($path);
                if ($content !== false) {
                    $matches = [];
                    foreach ($patterns as $pattern) {
                        if (preg_match($pattern, $content)) {
                            $matches[] = $pattern;
                        }
                    }
                    if (!empty($matches)) {
                        $results[] = [
                            'file' => $path,
                            'modified' => date('Y-m-d H:i:s', filemtime($path)),
                            'matches' => $matches
                        ];
                    }
                }
            }
        }
    }
}

scan_folder($scan_dir, $suspicious_patterns, $results);

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Meloton Backdoor Scanner</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; background: #f1f5f9; color: #1e293b; padding: 20px; }
        .container { max-width: 1000px; margin: 0 auto; background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1); }
        h1 { font-size: 24px; border-bottom: 2px solid #e2e8f0; padding-bottom: 10px; margin-top: 0; }
        .alert { background: #fee2e2; color: #991b1b; padding: 15px; border-radius: 6px; font-weight: bold; margin-bottom: 20px; border: 1px solid #f87171; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #e2e8f0; }
        th { background: #f8fafc; font-weight: 600; }
        tr:hover { background: #f1f5f9; }
        .file-path { font-family: monospace; font-size: 14px; background: #e2e8f0; padding: 2px 6px; border-radius: 4px; }
        .pattern { font-family: monospace; color: #b91c1c; font-size: 12px; }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔍 PHP Backdoor / Webshell Scanner</h1>
        <div class="alert">
            ⚠️ PERHATIAN: Hapus file <code>scan_backdoor.php</code> ini setelah selesai digunakan! Jika dibiarkan, orang lain bisa melihat struktur file Anda.
        </div>
        
        <p>Men-scan direktori: <strong><?= htmlspecialchars($scan_dir) ?></strong></p>
        
        <?php if (empty($results)): ?>
            <div style="background: #dcfce7; color: #166534; padding: 15px; border-radius: 6px; border: 1px solid #4ade80;">
                ✅ <strong>Bagus!</strong> Tidak ditemukan pola mencurigakan yang cocok dengan signature backdoor umum.
            </div>
        <?php else: ?>
            <p>Ditemukan <strong><?= count($results) ?></strong> file dengan pola mencurigakan (perlu dicek manual):</p>
            <table>
                <thead>
                    <tr>
                        <th>File Path</th>
                        <th>Terakhir Diubah</th>
                        <th>Pola Ditemukan</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($results as $res): ?>
                        <tr>
                            <td><span class="file-path"><?= htmlspecialchars(str_replace($scan_dir, '', $res['file'])) ?></span></td>
                            <td><?= $res['modified'] ?></td>
                            <td>
                                <?php foreach ($res['matches'] as $m): ?>
                                    <div class="pattern"><?= htmlspecialchars($m) ?></div>
                                <?php endforeach; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        
        <div style="margin-top: 30px; padding: 15px; background: #fffbeb; border: 1px solid #fcd34d; border-radius: 6px; font-size: 14px; line-height: 1.5;">
            <strong>💡 Tips Analisis Lanjutan:</strong><br>
            1. Periksa file PHP yang ada di dalam folder <code>uploads/</code> atau folder aset lainnya. Seharusnya folder itu hanya berisi gambar.<br>
            2. Cek file-file yang tanggal modifikasinya sangat baru (terutama jika Anda sedang tidak mengedit file tersebut).<br>
            3. Serangan "ganti database setiap detik" biasanya dilakukan lewat <i>cron job</i>, *background task* (daemon), atau bisa jadi ada script PHP yang disisipkan *(include)* ke <code>header.php</code>, <code>index.php</code>, atau <code>bootstrap.php</code> yang selalu dipanggil setiap kali ada pengunjung. Periksa file-file utama (index) Anda apakah ada kode aneh di paling atas atau bawah.
        </div>
    </div>
</body>
</html>
