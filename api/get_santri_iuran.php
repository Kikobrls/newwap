<?php
/**
 * API Get Santri Iuran
 * Returns list of active iuran for a specific santri
 */
header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once '../config/database.php';
require_once '../config/app.php';

if (!isset($_SESSION['login'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

if (!isset($_GET['id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Missing ID']);
    exit;
}

$id_santri = (int)$_GET['id'];
if ($id_santri <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid ID']);
    exit;
}

$items = [];
$hasPeriodeTipe = appColumnExists('iuran', 'periode_tipe');
$periodeExpr = $hasPeriodeTipe ? "IFNULL(b.periode_tipe, 'bulanan')" : "'bulanan'";
$mode = isset($_GET['mode']) ? strtolower(sanitize($_GET['mode'])) : 'tahunan';
if ($mode !== 'bulanan' && $mode !== 'tahunan') {
    $mode = 'tahunan';
}

$periode_awal = isset($_GET['periode_awal']) ? trim(sanitize($_GET['periode_awal'])) : '';
$periode_akhir = isset($_GET['periode_akhir']) ? trim(sanitize($_GET['periode_akhir'])) : '';
if ($periode_akhir === '') {
    $periode_akhir = $periode_awal;
}

if (appTableExists('santri_iuran')) {
    $query = "SELECT sb.id_iuran, b.nama_iuran, b.tahun, b.nominal, $periodeExpr as periode_tipe
              FROM santri_iuran sb
              JOIN iuran b ON sb.id_iuran = b.id_iuran
              WHERE sb.id_santri = '$id_santri' AND sb.is_active = 1
              ORDER BY b.nama_iuran ASC, b.tahun DESC";
} else {
    // Fallback legacy schema
    $query = "SELECT b.id_iuran, b.nama_iuran, b.tahun, b.nominal,
                     'bulanan' as periode_tipe
              FROM santri s
              JOIN iuran b ON s.id_iuran = b.id_iuran
              WHERE s.id_santri = '$id_santri'";
}

$result = mysqli_query($conn, $query);

while ($row = mysqli_fetch_assoc($result)) {
    $periode_tipe = $row['periode_tipe'] === 'tahunan' ? 'tahunan' : 'bulanan';
    if ($periode_tipe !== $mode) {
        continue;
    }

    $id_iuran = (int)$row['id_iuran'];
    $nominal = (float)$row['nominal'];

    if ($periode_tipe === 'tahunan') {
        $tahun = date('Y');
        $bulan = '';
        $periode_key = buildPeriodeKey('tahunan', $tahun, '');

        $sumQuery = mysqli_query($conn, "
            SELECT COALESCE(SUM(jumlah_bayar), 0) as total
            FROM pembayaran
            WHERE id_santri = '$id_santri'
              AND id_iuran = '$id_iuran'
              AND periode_key = '" . mysqli_real_escape_string($conn, $periode_key) . "'
        ");
        $sum = mysqli_fetch_assoc($sumQuery);
        $sudah_bayar = (float)$sum['total'];
        $sisa_tagihan = max($nominal - $sudah_bayar, 0.0);
        $ikut_lunas = false;
        $lunas_oleh_label = null;
    } else {
        if (preg_match('/^(\d{4})-(\d{2})$/', $periode_awal, $m)) {
            $tahun = $m[1];
            $bulan = bulanIndo((int)$m[2]);
            $periode_key = $periode_awal;
        } else {
            $tahun = date('Y');
            $bulan = bulanIndo((int)date('n'));
            $periode_key = buildPeriodeKey('bulanan', $tahun, $bulan);
        }

        $paidState = getSantriIuranBulananPaidState($id_santri, $id_iuran, $nominal, $periode_key);
        $sudah_bayar = (float)$paidState['sudah_bayar'];
        $sisa_tagihan = (float)$paidState['sisa_tagihan'];
        $ikut_lunas = (bool)$paidState['ikut_lunas'];
        $lunas_oleh_label = $paidState['lunas_oleh_label'];
    }

    $items[] = [
        'id_iuran' => $id_iuran,
        'nama_iuran' => $row['nama_iuran'],
        'tahun_iuran' => (int)$row['tahun'],
        'nominal' => (int)$nominal,
        'periode_tipe' => $periode_tipe,
        'default_bulan' => $bulan,
        'default_tahun' => (string)$tahun,
        'default_periode_key' => $periode_key,
        'sudah_bayar' => (int)$sudah_bayar,
        'sisa_tagihan' => (int)$sisa_tagihan,
        'ikut_lunas' => $ikut_lunas,
        'lunas_oleh_label' => $lunas_oleh_label,
    ];
}

if (empty($items)) {
    $msg = $mode === 'bulanan' ? 'Tidak ada tagihan bulanan aktif untuk santri ini' : 'Tidak ada tagihan tahunan aktif untuk santri ini';
    echo json_encode(['status' => 'error', 'message' => $msg]);
    exit;
}

echo json_encode([
    'status' => 'success',
    'data' => $items
]);
?>
