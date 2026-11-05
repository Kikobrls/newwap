<?php
/**
 * API: status lunas santri untuk Pembayaran Kolektif (bulk)
 *
 * Dipakai pages/pembayaran/bulk.php untuk menyembunyikan santri yang
 * sudah lunas pada iuran + periode yang sedang dipilih.
 *
 * Parameter (GET):
 *   kelas          : id_kelas atau "all"
 *   mode           : "bulanan" | "tahunan"
 *   items          : daftar id_iuran yang dicentang, dipisah koma (mis. "1,3")
 *   periode_awal   : "YYYY-MM" (mode bulanan)
 *   periode_akhir  : "YYYY-MM" (mode bulanan, opsional = periode_awal)
 *   tahun[<id>]    : tahun per iuran (mode tahunan)
 *
 * Aturan lunas sama dengan getSantriIuranBulananPaidState():
 *   - Bulanan: bulan P lunas jika total bayar bulan P >= nominal, ATAU ada
 *     bulan setelah P (iuran yang sama) yang sudah lunas ("ikut lunas").
 *   - Tahunan: lunas jika total bayar tahun tsb >= nominal.
 * Santri dianggap "lunas" (disembunyikan) bila SEMUA iuran terpilih pada
 * SEMUA periode terpilih sudah lunas.
 */
header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once '../config/database.php';
require_once '../config/app.php';
require_once __DIR__ . '/../includes/pembayaran_helpers.php';

if (!isset($_SESSION['login'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

$kelas_raw = trim((string) ($_GET['kelas'] ?? ''));
$is_all_kelas = ($kelas_raw === 'all' || $kelas_raw === '0');
$id_kelas = $is_all_kelas ? 0 : (int) $kelas_raw;
if (!$is_all_kelas && $id_kelas <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'Kelas tidak valid']);
    exit;
}

$mode = strtolower(trim((string) ($_GET['mode'] ?? 'bulanan')));
if ($mode !== 'bulanan' && $mode !== 'tahunan') {
    $mode = 'bulanan';
}

// Iuran terpilih
$item_ids = [];
foreach (explode(',', (string) ($_GET['items'] ?? '')) as $v) {
    $id = (int) $v;
    if ($id > 0) {
        $item_ids[$id] = $id;
    }
}

$empty = ['status' => 'success', 'lunas' => [], 'sebagian' => new stdClass()];
if (empty($item_ids)) {
    echo json_encode($empty);
    exit;
}

// Nominal iuran dari database (bukan dari input form)
$iuran = [];
$res = mysqli_query($conn, "SELECT id_iuran, nominal FROM iuran WHERE id_iuran IN (" . implode(',', $item_ids) . ")");
while ($res && ($row = mysqli_fetch_assoc($res))) {
    $iuran[(int) $row['id_iuran']] = (float) $row['nominal'];
}
if (empty($iuran)) {
    echo json_encode($empty);
    exit;
}

// Periode yang dicek per iuran: [id_iuran => [periode_key, ...]]
$periode_per_iuran = [];
if ($mode === 'bulanan') {
    $awal = trim((string) ($_GET['periode_awal'] ?? ''));
    $akhir = trim((string) ($_GET['periode_akhir'] ?? ''));
    $list = expandPeriodeBulanan($awal, $akhir);
    if (empty($list)) {
        echo json_encode($empty);
        exit;
    }
    $keys = array_column($list, 'periode_key');
    foreach ($iuran as $id => $_) {
        $periode_per_iuran[$id] = $keys;
    }
} else {
    $tahun_in = $_GET['tahun'] ?? [];
    foreach ($iuran as $id => $_) {
        $th = preg_replace('/\D/', '', (string) (is_array($tahun_in) ? ($tahun_in[$id] ?? '') : ''));
        if ($th === '' || strlen($th) !== 4) {
            $th = date('Y');
        }
        $periode_per_iuran[$id] = [$th];
    }
}

// Daftar santri aktif sesuai filter kelas (sama dengan halaman bulk)
$where_kelas = $is_all_kelas ? '' : "AND id_kelas = '$id_kelas'";
$santri_ids = [];
$res = mysqli_query($conn, "SELECT id_santri FROM santri WHERE status = 'active' $where_kelas");
while ($res && ($row = mysqli_fetch_assoc($res))) {
    $santri_ids[] = (int) $row['id_santri'];
}
if (empty($santri_ids)) {
    echo json_encode($empty);
    exit;
}

// Ambil total pembayaran sekaligus (1 query) lalu hitung di PHP
$min_key = null;
foreach ($periode_per_iuran as $keys) {
    foreach ($keys as $k) {
        if ($min_key === null || strcmp($k, $min_key) < 0) {
            $min_key = $k;
        }
    }
}
$min_key_safe = mysqli_real_escape_string($conn, (string) $min_key);

$totals = []; // [id_santri][id_iuran][periode_key] => total
$sql = "SELECT id_santri, id_iuran, periode_key, COALESCE(SUM(jumlah_bayar), 0) AS total
        FROM pembayaran
        WHERE id_santri IN (" . implode(',', $santri_ids) . ")
          AND id_iuran IN (" . implode(',', array_keys($iuran)) . ")
          AND periode_tipe = '$mode'
          AND periode_key >= '$min_key_safe'
        GROUP BY id_santri, id_iuran, periode_key";
$res = mysqli_query($conn, $sql);
while ($res && ($row = mysqli_fetch_assoc($res))) {
    $totals[(int) $row['id_santri']][(int) $row['id_iuran']][(string) $row['periode_key']] = (float) $row['total'];
}

$lunas = [];
$sebagian = [];
foreach ($santri_ids as $sid) {
    $all_lunas = true;
    $ada_bayar = false;

    foreach ($periode_per_iuran as $iid => $keys) {
        $nominal = $iuran[$iid];
        $paid = $totals[$sid][$iid] ?? [];

        // Bulan lunas paling akhir (untuk aturan "ikut lunas")
        $max_full = null;
        if ($mode === 'bulanan' && $nominal > 0) {
            foreach ($paid as $k => $t) {
                if ($t >= $nominal && ($max_full === null || strcmp($k, $max_full) > 0)) {
                    $max_full = $k;
                }
            }
        }

        foreach ($keys as $k) {
            $direct = $paid[$k] ?? 0.0;
            if ($direct > 0) {
                $ada_bayar = true;
            }
            $is_lunas = ($nominal > 0 && $direct >= $nominal)
                || ($mode === 'bulanan' && $max_full !== null && strcmp($max_full, $k) > 0);
            if ($is_lunas) {
                $ada_bayar = true;
            } else {
                $all_lunas = false;
            }
        }
    }

    if ($all_lunas) {
        $lunas[] = $sid;
    } elseif ($ada_bayar) {
        $sebagian[$sid] = true;
    }
}

echo json_encode([
    'status' => 'success',
    'lunas' => $lunas,
    'sebagian' => empty($sebagian) ? new stdClass() : $sebagian,
]);
