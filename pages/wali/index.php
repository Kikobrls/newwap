<?php
require_once __DIR__ . '/../../config/app.php';
if (($_SESSION['login'] ?? false) !== true) {
    header('Location: ' . baseUrl('login.php'));
    exit;
}
if (($_SESSION['level'] ?? '') !== 'wali_santri') {
    http_response_code(403);
    exit('Portal ini khusus akun wali santri.');
}
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/wali_helpers.php';
header('Cache-Control: no-store, private');
$page_title = 'Portal Wali Santri';
$ready = appTableExists('wali_santri') && appColumnExists('iuran', 'jatuh_tempo_hari');
$userId = (int)$_SESSION['id_user'];
$children = $ready ? waliSantriList($conn, $userId) : [];
$santriId = isset($_GET['santri']) ? (int)$_GET['santri'] : (int)(array_key_first($children) ?? 0);
if (isset($_GET['santri']) && !isset($children[$santriId])) {
    http_response_code(403);
    exit('Santri tidak terhubung dengan akun Anda.');
}
$child = $children[$santriId] ?? null;
$year = filter_var($_GET['tahun'] ?? 0, FILTER_VALIDATE_INT);
if ($year !== 0 && ($year === false || $year < 2000 || $year > 2100)) {
    http_response_code(400);
    exit('Tahun tidak valid.');
}
$page = max(1, (int)($_GET['halaman'] ?? 1));
$bills = $child ? waliSantriBills($conn, $userId, $santriId) : [];
$reminders = array_filter($bills, function ($bill) { return $bill['status']['remind']; });
$remaining = array_sum(array_column($bills, 'sisa'));
$history = [];
$totalPaid = 0;
$totalRows = 0;
if ($child) {
    $filter = ' FROM pembayaran p JOIN wali_santri w ON w.id_santri = p.id_santri WHERE w.id_user = ? AND p.id_santri = ? AND (? = 0 OR YEAR(p.tgl_bayar) = ?)';
    $stmt = mysqli_prepare($conn, 'SELECT COUNT(*) AS jumlah, COALESCE(SUM(p.jumlah_bayar), 0) AS total' . $filter);
    mysqli_stmt_bind_param($stmt, 'iiii', $userId, $santriId, $year, $year);
    mysqli_stmt_execute($stmt);
    $totals = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    $totalRows = (int)$totals['jumlah'];
    $totalPaid = (float)$totals['total'];
    $page = min($page, max(1, (int)ceil($totalRows / 20)));
    $offset = ($page - 1) * 20;
    $stmt = mysqli_prepare($conn, 'SELECT p.*, (SELECT nama_iuran FROM iuran i WHERE i.id_iuran = p.id_iuran) AS nama_iuran' . $filter . ' ORDER BY p.tgl_bayar DESC, p.id_pembayaran DESC LIMIT 20 OFFSET ?');
    mysqli_stmt_bind_param($stmt, 'iiiii', $userId, $santriId, $year, $year, $offset);
    mysqli_stmt_execute($stmt);
    $history = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
}
function waliEscape($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>
<header class="page-header page-header-compact page-header-light border-bottom bg-white mb-4">
    <div class="container-xl px-4"><div class="page-header-content py-4">
        <h1 class="page-header-title"><div class="page-header-icon"><i data-feather="home"></i></div>Portal Wali Santri</h1>
        <p class="text-muted mb-0 mt-2">Assalamu’alaikum, <?php echo waliEscape($_SESSION['nama_user']); ?>. Pantau iuran dan pembayaran putra-putri Anda di sini.</p>
    </div></div>
</header>
<div class="container-xl px-4 pb-4">
<?php if (!$ready): ?>
    <div class="alert alert-warning">Portal belum diaktifkan. Hubungi admin untuk menjalankan migrasi wali santri.</div>
<?php elseif (!$child): ?>
    <div class="card"><div class="card-body text-center py-5"><i data-feather="users"></i><h2 class="h5 mt-3">Belum ada santri terhubung</h2><p class="text-muted mb-0">Hubungi admin TPQ agar akun Anda dihubungkan ke data santri yang diasuh.</p></div></div>
<?php else: ?>
    <div class="card mb-4"><div class="card-body">
        <form method="GET" class="row align-items-end g-3">
            <div class="col-md-8"><label for="santri" class="form-label">Santri yang diasuh</label>
                <select class="form-select" id="santri" name="santri"><?php foreach ($children as $s): ?><option value="<?php echo (int)$s['id_santri']; ?>" <?php echo (int)$s['id_santri'] === $santriId ? 'selected' : ''; ?>><?php echo waliEscape($s['nama'] . ' — ' . $s['nama_kelas']); ?></option><?php endforeach; ?></select>
            </div><div class="col-md-4"><button class="btn btn-primary w-100" type="submit">Lihat pembayaran santri</button></div>
        </form>
        <p class="small text-muted mb-0 mt-3">Kelas: <?php echo waliEscape($child['nama_kelas']); ?> · Status: <?php echo waliEscape($child['status'] === 'active' ? 'Aktif' : 'Nonaktif'); ?> · Data hanya untuk santri yang terhubung dengan akun Anda.</p>
    </div></div>
    <div class="row">
        <div class="col-md-4 mb-4"><div class="card h-100 border-start border-primary border-4"><div class="card-body"><div class="small text-muted mb-2">Sisa tagihan yang ditampilkan</div><div class="h3 text-primary mb-0"><?php echo formatRupiah($remaining); ?></div></div></div></div>
        <div class="col-md-4 mb-4"><div class="card h-100 border-start border-warning border-4"><div class="card-body"><div class="small text-muted mb-2">Perlu perhatian</div><div class="h3 mb-0"><?php echo count($reminders); ?> tagihan</div><div class="small text-muted mt-1">Terlambat / jatuh tempo dalam 7 hari</div></div></div></div>
        <div class="col-md-4 mb-4"><div class="card h-100 border-start border-success border-4"><div class="card-body"><div class="small text-muted mb-2">Total pembayaran <?php echo $year ?: '(semua tahun)'; ?></div><div class="h3 text-success mb-0"><?php echo formatRupiah($totalPaid); ?></div></div></div></div>
    </div>
    <?php if ($reminders): ?>
    <div class="alert alert-warning" role="alert"><h2 class="h5"><i class="fas fa-bell me-2"></i>Pengingat pembayaran</h2>
        <p>Berikut iuran <?php echo waliEscape($child['nama']); ?> yang perlu segera dibayar:</p>
        <ul class="mb-0"><?php foreach ($reminders as $bill): ?><li class="mb-1"><strong><?php echo waliEscape($bill['nama_iuran'] . ' — ' . $bill['periode_label']); ?></strong>: sisa <?php echo formatRupiah($bill['sisa']); ?>. <?php echo waliEscape($bill['status']['label']); ?> (<?php echo tanggalIndo($bill['due']); ?>).</li><?php endforeach; ?></ul>
    </div>
    <?php elseif ($bills): ?><div class="alert alert-success">Tidak ada tagihan terlambat atau jatuh tempo dalam 7 hari ke depan.</div><?php endif; ?>
    <section class="card mb-4" id="tagihan">
        <div class="card-header"><h2 class="h5 mb-0">Tagihan &amp; jadwal iuran</h2></div>
        <div class="card-body"><p class="small text-muted">Tagihan berdasarkan iuran aktif sejak santri terdaftar / iuran ditetapkan, sesuai tahun tarif. Iuran bulanan ditampilkan sampai bulan depan. Pengingat muncul otomatis mulai H-7 dan tetap tampil sampai lunas. Pembayaran dicatat oleh bendahara.</p>
        <?php if (!$bills): ?><div class="text-center text-muted py-4">Belum ada tagihan aktif untuk santri ini.</div><?php else: ?>
        <div class="table-responsive"><table class="table table-bordered align-middle"><thead><tr><th>Iuran / Periode</th><th>Jatuh tempo</th><th>Nominal</th><th>Sisa</th><th>Status</th></tr></thead><tbody>
        <?php foreach ($bills as $bill): ?><tr>
            <td><strong><?php echo waliEscape($bill['nama_iuran']); ?></strong><div class="small text-muted"><?php echo waliEscape($bill['periode_label']); ?></div></td>
            <td><?php echo tanggalIndo($bill['due']); ?></td><td><?php echo formatRupiah($bill['nominal']); ?></td><td><?php echo formatRupiah($bill['sisa']); ?></td>
            <td><span class="badge bg-<?php echo $bill['status']['color']; ?>"><?php echo waliEscape($bill['status']['label']); ?></span><?php if ($bill['ikut_lunas']): ?><div class="small text-muted mt-1">Mengikuti pelunasan periode berikutnya</div><?php elseif ($bill['terbayar'] > 0 && $bill['sisa'] > 0): ?><div class="small text-muted mt-1">Sudah dicicil <?php echo formatRupiah($bill['terbayar']); ?></div><?php endif; ?></td>
        </tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
        <p class="small text-muted mb-0 mt-2">Jika ada perbedaan data atau membutuhkan bantuan pembayaran, silakan hubungi bendahara TPQ. Pengingat ini tampil di portal, bukan pesan WhatsApp/SMS otomatis.</p>
        </div>
    </section>
    <section class="card" id="riwayat">
        <div class="card-header"><h2 class="h5 mb-0">Riwayat pembayaran — <?php echo waliEscape($child['nama']); ?></h2></div>
        <div class="card-body">
        <form method="GET" action="#riwayat" class="row g-2 align-items-end mb-3">
            <input type="hidden" name="santri" value="<?php echo $santriId; ?>">
            <div class="col-sm-5"><label for="tahun" class="form-label">Tahun pembayaran</label><select name="tahun" id="tahun" class="form-select"><option value="0">Semua tahun</option><?php for ($y = max((int)date('Y'), $year); $y >= min((int)substr($child['created_at'], 0, 4), (int)date('Y')); $y--): ?><option value="<?php echo $y; ?>" <?php echo $y === $year ? 'selected' : ''; ?>><?php echo $y; ?></option><?php endfor; ?></select></div>
            <div class="col-sm-auto"><button class="btn btn-outline-primary" type="submit">Tampilkan</button></div>
        </form>
        <?php if (!$history): ?><div class="text-center text-muted py-4">Belum ada riwayat pembayaran<?php echo $year ? ' pada tahun ' . $year : ''; ?>.</div><?php else: ?>
        <div class="table-responsive"><table class="table table-bordered align-middle"><thead><tr><th>No. transaksi</th><th>Tanggal bayar</th><th>Iuran</th><th>Periode</th><th>Jumlah</th><th>Metode</th></tr></thead><tbody>
        <?php foreach ($history as $p): ?><tr><td>#<?php echo (int)$p['id_pembayaran']; ?></td><td><?php echo tanggalIndo($p['tgl_bayar']); ?></td><td><?php echo waliEscape($p['nama_iuran'] ?? 'Iuran'); ?></td><td><?php echo waliEscape($p['periode_tipe'] === 'tahunan' ? $p['tahun_dibayar'] : trim($p['bulan_dibayar'] . ' ' . $p['tahun_dibayar'])); ?></td><td class="text-success fw-bold"><?php echo formatRupiah($p['jumlah_bayar']); ?></td><td><?php echo waliEscape($p['metode_bayar']); ?></td></tr><?php endforeach; ?>
        </tbody></table></div>
        <nav class="d-flex flex-wrap align-items-center justify-content-between gap-2" aria-label="Halaman riwayat pembayaran"><span class="small text-muted"><?php echo $totalRows; ?> transaksi · Halaman <?php echo $page; ?> dari <?php echo max(1, (int)ceil($totalRows / 20)); ?></span><div>
            <?php foreach (['Sebelumnya' => $page - 1, 'Berikutnya' => $page + 1] as $label => $target): ?><?php if ($target >= 1 && $target <= ceil($totalRows / 20)): ?><a class="btn btn-sm btn-outline-primary" href="?<?php echo waliEscape(http_build_query(['santri' => $santriId, 'tahun' => $year, 'halaman' => $target])); ?>#riwayat"><?php echo $label; ?></a><?php endif; ?><?php endforeach; ?>
        </div></nav><?php endif; ?>
        </div>
    </section>
<?php endif; ?>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
