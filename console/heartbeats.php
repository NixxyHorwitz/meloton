<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
staff_require('qris_logs');

$pageTitle = 'Status Forwarder (Heartbeat)';
$activePage = 'heartbeats';

// Pagination
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 50;
$offset = ($page - 1) * $limit;

// Fetch latest heartbeat
$latest = $pdo->query("SELECT * FROM forwarder_heartbeats ORDER BY created_at DESC LIMIT 1")->fetch();

$status = 'UNKNOWN';
$last_seen = '-';
$interval = 5;

if ($latest) {
    $last_seen = date('d M Y, H:i:s', strtotime($latest['created_at']));
    $interval = (int)$latest['interval_minutes'];
    
    // Check if within interval
    $now = time();
    $latest_time = strtotime($latest['created_at']);
    // We add a 1-minute buffer in case of slight delays
    if (($now - $latest_time) <= (($interval + 1) * 60)) {
        $status = 'ON';
    } else {
        $status = 'OFF';
    }
}

// Fetch total records
$total_records = (int)$pdo->query("SELECT COUNT(*) FROM forwarder_heartbeats")->fetchColumn();
$total_pages = ceil($total_records / $limit);

// Fetch logs
$stmt = $pdo->prepare("SELECT * FROM forwarder_heartbeats ORDER BY created_at DESC LIMIT ? OFFSET ?");
$stmt->bindValue(1, $limit, PDO::PARAM_INT);
$stmt->bindValue(2, $offset, PDO::PARAM_INT);
$stmt->execute();
$logs = $stmt->fetchAll();

require __DIR__ . '/partials/header.php';
?>

<div class="page-title-bar">
    <h1>💓 Monitor Status Forwarder</h1>
    <p>Pantau status aktif aplikasi PGAForwarder Anda secara real-time</p>
</div>

<div class="c-content">
    <div class="row mb-4">
        <!-- New Endpoint URL section spanning full width if needed, or inline -->
        <div class="col-md-12 mb-3">
            <div class="c-stat" style="display:flex;align-items:center;justify-content:space-between;gap:12px;padding:12px 20px;background:#1a1d27;border:1px dashed var(--brand)">
                <div>
                    <div style="font-size:11px;color:#888;text-transform:uppercase;font-weight:700;letter-spacing:1px">Endpoint Heartbeat API</div>
                    <div style="font-size:14px;font-weight:800;color:var(--brand);margin-top:2px;font-family:monospace" id="endpoint-url">
                        <?= (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . '/api/heartbeat.php' ?>
                    </div>
                </div>
                <button onclick="copyEndpoint()" class="btn btn-sm" style="background:var(--brand);color:#fff;border-radius:8px;font-weight:700;font-size:12px;padding:6px 16px">📋 Salin Tautan</button>
            </div>
        </div>
        <div class="col-md-6">
            <div class="c-stat" style="display:flex;align-items:center;gap:20px">
                <div class="c-stat__icon" style="background:<?= $status === 'ON' ? 'var(--lime)' : ($status === 'OFF' ? 'var(--peach)' : '#555') ?>;width:60px;height:60px;border-radius:16px;font-size:24px">
                    <?= $status === 'ON' ? '🟢' : '🔴' ?>
                </div>
                <div>
                    <div style="font-size:12px;color:#888;text-transform:uppercase;font-weight:700;letter-spacing:1px">Status Server</div>
                    <div style="font-size:28px;font-weight:900;color:<?= $status === 'ON' ? '#4CAF82' : ($status === 'OFF' ? '#F44E3B' : '#fff') ?>;line-height:1.2">
                        <?= $status ?>
                    </div>
                    <div style="font-size:11px;color:#666;margin-top:4px">
                        Interval Deteksi: <b><?= $interval ?> Menit</b>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="c-stat" style="display:flex;align-items:center;gap:20px">
                <div class="c-stat__icon" style="background:#1f2235;width:60px;height:60px;border-radius:16px;font-size:24px">
                    ⏱️
                </div>
                <div>
                    <div style="font-size:12px;color:#888;text-transform:uppercase;font-weight:700;letter-spacing:1px">Detak Terakhir (Last Seen)</div>
                    <div style="font-size:20px;font-weight:800;color:#fff;line-height:1.2;margin-top:4px">
                        <?= $last_seen ?>
                    </div>
                    <div style="font-size:11px;color:#666;margin-top:4px">
                        Halaman ini akan me-refresh otomatis setiap 60 detik.
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="c-card">
        <div class="c-card-header">
            <div class="c-card-title">Riwayat Heartbeat (<?= number_format($total_records) ?> Logs)</div>
            <button onclick="window.location.reload()" class="btn btn-sm btn-outline-secondary" style="border-radius:8px;font-size:12px;font-weight:600;background:none;border:1px solid #1f2235;color:#888">🔄 Segarkan</button>
        </div>
        <div class="c-card-body p-0">
            <div class="table-responsive">
                <table class="table" style="font-size:13.5px;color:#e0e0f0;margin-bottom:0">
                    <thead style="background:#0f1117;color:#666;font-size:11px;text-transform:uppercase;letter-spacing:.5px">
                        <tr>
                            <th width="15%" style="border-bottom:1px solid #1f2235;padding:10px 14px">Waktu</th>
                            <th width="15%" style="border-bottom:1px solid #1f2235;padding:10px 14px">Device</th>
                            <th width="10%" style="border-bottom:1px solid #1f2235;padding:10px 14px">Interval</th>
                            <th style="border-bottom:1px solid #1f2235;padding:10px 14px">Payload JSON</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($logs)): ?>
                        <tr><td colspan="4" style="text-align:center;padding:30px;color:#666">Belum ada riwayat heartbeat yang masuk.</td></tr>
                        <?php else: ?>
                            <?php foreach ($logs as $log): ?>
                            <tr>
                                <td><?= date('d/m/Y H:i:s', strtotime($log['created_at'])) ?></td>
                                <td><span class="badge b-neutral"><?= htmlspecialchars((string)$log['device_info']) ?></span></td>
                                <td><?= $log['interval_minutes'] ?> Menit</td>
                                <td style="font-family:monospace;font-size:11px;color:#999;max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?= htmlspecialchars((string)$log['payload_text']) ?>">
                                    <?= htmlspecialchars((string)$log['payload_text']) ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            
            <?php if ($total_pages > 1): ?>
            <div style="display:flex;justify-content:space-between;align-items:center;padding:16px 20px;border-top:1px solid #1f2235">
                <a href="?page=<?= max(1, $page - 1) ?>" class="c-nav-link" style="margin:0;padding:6px 12px;display:inline-block;<?= $page <= 1 ? 'pointer-events:none;opacity:0.5' : '' ?>">← Prev</a>
                <span style="font-size:12px;color:#666">Page <?= $page ?> of <?= $total_pages ?></span>
                <a href="?page=<?= min($total_pages, $page + 1) ?>" class="c-nav-link" style="margin:0;padding:6px 12px;display:inline-block;<?= $page >= $total_pages ? 'pointer-events:none;opacity:0.5' : '' ?>">Next →</a>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
    function copyEndpoint() {
        const url = document.getElementById('endpoint-url').innerText.trim();
        navigator.clipboard.writeText(url).then(() => {
            alert('✅ Tautan berhasil disalin: ' + url);
        }).catch(err => {
            alert('Gagal menyalin tautan: ' + err);
        });
    }

    // Auto refresh halaman setiap 60 detik
    setTimeout(() => {
        window.location.reload();
    }, 60000);
</script>

<?php require __DIR__ . '/partials/footer.php'; ?>
