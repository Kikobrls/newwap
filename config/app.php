<?php
ob_start();
/**
 * Application Configuration
 * Sistem Administrasi Keuangan Sekolah
 */

// Application settings
define('APP_NAME', 'Administrasi Keuangan');
define('APP_VERSION', '1.0.0');

// Time zone
date_default_timezone_set('Asia/Jakarta');

// Session configuration
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', 1);
    ini_set('session.use_only_cookies', 1);
    session_start();
}

// Error reporting (set to 0 in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Paths
define('BASE_PATH', dirname(__DIR__));
define('ASSETS_PATH', BASE_PATH . '/assets');
define('UPLOADS_PATH', BASE_PATH . '/uploads');

// Calculate dynamic subfolder and APP_URL
$doc_root_check = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
$base_path_check = rtrim(str_replace('\\', '/', BASE_PATH), '/');
$app_subfolder = '';
if (!empty($doc_root_check) && strpos($base_path_check, $doc_root_check) === 0) {
    $app_subfolder = substr($base_path_check, strlen($doc_root_check));
}
$app_subfolder = '/' . trim($app_subfolder, '/');
if ($app_subfolder === '/') {
    $app_subfolder = '';
}
$protocol_check = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
$host_check = $_SERVER['HTTP_HOST'] ?? 'localhost';
define('APP_URL', $protocol_check . '://' . $host_check . $app_subfolder);

// Role based access control (RBAC)
if (isset($_SESSION['login']) && isset($_SESSION['level'])) {
    $current_script = $_SERVER['SCRIPT_NAME'] ?? '';
    
    // Check access for level bendahara (nilai role, bukan nama tabel)
    if ($_SESSION['level'] === 'bendahara') {
        $is_restricted = false;
        $restricted_paths = [
            '/pages/santri/',
            '/pindahkelas.php',
            '/pages/kelas/',
            '/pages/iuran/',
            '/pages/users/',
            '/pages/setting/'
        ];
        
        foreach ($restricted_paths as $path) {
            if (strpos($current_script, $path) !== false) {
                $is_restricted = true;
                break;
            }
        }
        
        // Exception for profile page
        if (strpos($current_script, '/pages/users/profile.php') !== false) {
            $is_restricted = false;
        }
        
        if ($is_restricted) {
            if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
                http_response_code(403);
                echo json_encode(['error' => 'Anda tidak memiliki akses ke halaman ini.']);
                exit;
            }
            
            $_SESSION['flash'] = [
                'type' => 'danger',
                'message' => 'Anda tidak memiliki akses ke halaman master data!'
            ];
            header("Location: " . APP_URL . "/index.php");
            exit;
        }
    }
}

// Months array (Indonesian)
$bulan_indo = [
    'Januari',
    'Februari',
    'Maret',
    'April',
    'Mei',
    'Juni',
    'Juli',
    'Agustus',
    'September',
    'Oktober',
    'November',
    'Desember'
];

// Alert types
$alert_types = ['success', 'danger', 'warning', 'info'];

/**
 * Convert date to Indonesian format
 */
function tanggalIndo($date)
{
    global $bulan_indo;
    $timestamp = strtotime($date);
    $day = date('d', $timestamp);
    $month = $bulan_indo[date('n', $timestamp) - 1];
    $year = date('Y', $timestamp);
    return "$day $month $year";
}

/**
 * Get Indonesian month name
 */
function bulanIndo($month_number)
{
    global $bulan_indo;
    return $bulan_indo[$month_number - 1];
}

/**
 * Flash message
 */
function setFlash($type, $message)
{
    $_SESSION['flash'] = [
        'type' => $type,
        'message' => $message
    ];
}

function getFlash()
{
    if (isset($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}

/**
 * Redirect with flash
 */
function redirect($url, $type = null, $message = null)
{
    if ($type && $message) {
        setFlash($type, $message);
    }
    header("Location: $url");
    exit;
}

/**
 * Check if request is AJAX
 */
function isAjax()
{
    return isset($_SERVER['HTTP_X_REQUESTED_WITH']) &&
        strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
}

/**
 * JSON response
 */
function jsonResponse($data, $status = 200)
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

/**
 * Get base URL
 */
function baseUrl($path = '')
{
    return rtrim(APP_URL, '/') . '/' . ltrim($path, '/');
}

/**
 * Get assets URL
 */
function assetUrl($path = '')
{
    return baseUrl('assets/' . ltrim($path, '/'));
}

/**
 * Convert month name to month number string
 */
function bulanKeAngka($bulan)
{
    global $bulan_indo;
    $index = array_search($bulan, $bulan_indo, true);
    if ($index === false) {
        return null;
    }
    return str_pad((string)($index + 1), 2, '0', STR_PAD_LEFT);
}

/**
 * Build normalized period key
 */
function buildPeriodeKey($periode_tipe, $tahun, $bulan = '')
{
    $tahun = preg_replace('/\D/', '', (string)$tahun);
    if ($periode_tipe === 'tahunan') {
        return $tahun;
    }

    if (is_numeric($bulan)) {
        $bulan_num = str_pad((string)((int)$bulan), 2, '0', STR_PAD_LEFT);
    } else {
        $bulan_num = bulanKeAngka($bulan);
    }

    if (empty($bulan_num) || empty($tahun)) {
        return '';
    }

    return $tahun . '-' . $bulan_num;
}

/**
 * Build readable period label
 */
function formatPeriodeTagihan($periode_tipe, $bulan, $tahun)
{
    if ($periode_tipe === 'tahunan') {
        return (string)$tahun;
    }
    return trim((string)$bulan . ' ' . (string)$tahun);
}

/**
 * Format monthly periode_key ("YYYY-MM") into Indonesian label ("Bulan Tahun").
 */
function formatPeriodeKeyBulanan($periode_key)
{
    if (!preg_match('/^(\d{4})-(\d{2})$/', (string)$periode_key, $m)) {
        return (string)$periode_key;
    }
    $bln = bulanIndo((int)$m[2]);
    return trim(($bln ?: $m[2]) . ' ' . $m[1]);
}

/**
 * Check cumulative monthly paid state for a santri & iuran on a given periode_key ("YYYY-MM").
 *
 * Rule: If a later month (>= $periode_key) for the same santri & monthly iuran is already
 * fully paid (total >= nominal), then this month ($periode_key) is also considered paid
 * ("ikut lunas").
 */
function getSantriIuranBulananPaidState($id_santri, $id_iuran, $nominal, $periode_key)
{
    global $conn;

    $id_santri = (int)$id_santri;
    $id_iuran = (int)$id_iuran;
    $nominal = (float)$nominal;
    $periode_key = trim((string)$periode_key);
    $pk_safe = mysqli_real_escape_string($conn, $periode_key);

    $q = mysqli_query($conn, "
        SELECT periode_key, COALESCE(SUM(jumlah_bayar), 0) AS total
        FROM pembayaran
        WHERE id_santri = '$id_santri'
          AND id_iuran = '$id_iuran'
          AND periode_tipe = 'bulanan'
          AND periode_key >= '$pk_safe'
        GROUP BY periode_key
        ORDER BY periode_key DESC
    ");

    $direct_paid = 0.0;
    $later_full_key = null;

    while ($q && ($r = mysqli_fetch_assoc($q))) {
        $rk = (string)$r['periode_key'];
        $rtotal = (float)$r['total'];
        if ($rk === $periode_key) {
            $direct_paid = $rtotal;
        } elseif ($rtotal >= $nominal && $nominal > 0 && $later_full_key === null) {
            $later_full_key = $rk;
        }
    }

    if ($direct_paid >= $nominal && $nominal > 0) {
        return [
            'sudah_bayar_langsung' => $direct_paid,
            'sudah_bayar' => $direct_paid,
            'sisa_tagihan' => 0.0,
            'is_lunas' => true,
            'ikut_lunas' => false,
            'lunas_oleh_periode' => $periode_key,
            'lunas_oleh_label' => formatPeriodeKeyBulanan($periode_key),
        ];
    }

    if ($later_full_key !== null) {
        return [
            'sudah_bayar_langsung' => $direct_paid,
            'sudah_bayar' => $nominal,
            'sisa_tagihan' => 0.0,
            'is_lunas' => true,
            'ikut_lunas' => true,
            'lunas_oleh_periode' => $later_full_key,
            'lunas_oleh_label' => formatPeriodeKeyBulanan($later_full_key),
        ];
    }

    $sisa = max($nominal - $direct_paid, 0.0);
    return [
        'sudah_bayar_langsung' => $direct_paid,
        'sudah_bayar' => $direct_paid,
        'sisa_tagihan' => $sisa,
        'is_lunas' => ($sisa <= 0 && $nominal > 0),
        'ikut_lunas' => false,
        'lunas_oleh_periode' => null,
        'lunas_oleh_label' => null,
    ];
}

/**
 * Expand a monthly period range (inclusive) into a list of months.
 *
 * Accepts period keys in "YYYY-MM" format (as submitted by the flatpickr
 * month-range picker on the payment forms). The range may cross a year
 * boundary (e.g. 2026-07 .. 2027-06). If $akhir is empty it defaults to
 * $awal (single month). If $akhir < $awal they are swapped.
 *
 * Returns an array of ['bulan' => 'Januari', 'tahun' => '2026', 'periode_key' => '2026-01']
 * or an empty array when the input is invalid / exceeds $max_months.
 */
function expandPeriodeBulanan($awal, $akhir = '', $max_months = 36)
{
    global $bulan_indo;

    $awal = trim((string)$awal);
    $akhir = trim((string)$akhir);
    if ($akhir === '') {
        $akhir = $awal;
    }

    if (!preg_match('/^(\d{4})-(\d{2})$/', $awal, $ma) || !preg_match('/^(\d{4})-(\d{2})$/', $akhir, $mb)) {
        return [];
    }

    $ya = (int)$ma[1]; $ba = (int)$ma[2];
    $yb = (int)$mb[1]; $bb = (int)$mb[2];
    if ($ba < 1 || $ba > 12 || $bb < 1 || $bb > 12 || $ya < 2000 || $yb < 2000 || $ya > 2100 || $yb > 2100) {
        return [];
    }

    $start = $ya * 12 + ($ba - 1);
    $end = $yb * 12 + ($bb - 1);
    if ($end < $start) {
        [$start, $end] = [$end, $start];
    }
    if (($end - $start + 1) > $max_months) {
        return [];
    }

    $list = [];
    for ($i = $start; $i <= $end; $i++) {
        $y = intdiv($i, 12);
        $m = ($i % 12) + 1;
        $list[] = [
            'bulan' => $bulan_indo[$m - 1],
            'tahun' => (string)$y,
            'periode_key' => $y . '-' . str_pad((string)$m, 2, '0', STR_PAD_LEFT),
        ];
    }
    return $list;
}

/**
 * Check table existence in current database.
 * Cached per-request in a static array — the schema doesn't change
 * mid-request, so there's no reason to re-query information_schema
 * every time this is called (it used to run on every page load of
 * the santri listing, and on every AJAX call to get_kelas_iuran.php
 * / get_santri_iuran.php).
 */
function appTableExists($table_name)
{
    static $cache = [];
    if (array_key_exists($table_name, $cache)) {
        return $cache[$table_name];
    }

    global $conn;
    $table_name_safe = mysqli_real_escape_string($conn, $table_name);
    $db_res = mysqli_query($conn, "SELECT DATABASE() as db");
    $db = mysqli_fetch_assoc($db_res)['db'] ?? '';
    $db = mysqli_real_escape_string($conn, $db);

    $sql = "SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = '$db' AND TABLE_NAME = '$table_name_safe' LIMIT 1";
    $res = mysqli_query($conn, $sql);
    return $cache[$table_name] = ($res && mysqli_num_rows($res) > 0);
}

/**
 * Check column existence in current database.
 * Cached per-request in a static array — see appTableExists() above.
 */
function appColumnExists($table_name, $column_name)
{
    static $cache = [];
    $cache_key = $table_name . '.' . $column_name;
    if (array_key_exists($cache_key, $cache)) {
        return $cache[$cache_key];
    }

    global $conn;
    $table_name_safe = mysqli_real_escape_string($conn, $table_name);
    $column_name_safe = mysqli_real_escape_string($conn, $column_name);
    $db_res = mysqli_query($conn, "SELECT DATABASE() as db");
    $db = mysqli_fetch_assoc($db_res)['db'] ?? '';
    $db = mysqli_real_escape_string($conn, $db);

    $sql = "SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = '$db' AND TABLE_NAME = '$table_name_safe' AND COLUMN_NAME = '$column_name_safe' LIMIT 1";
    $res = mysqli_query($conn, $sql);
    return $cache[$cache_key] = ($res && mysqli_num_rows($res) > 0);
}

/**
 * Calculate monthly payment progress for many santri.
 * Month is considered paid when all active monthly fees are fully paid in that month.
 */
function getSantriMonthlyProgressMap($santri_ids, $year = null)
{
    global $conn;

    $current_year = (int)date('Y');
    $current_month = (int)date('n');
    $year = $year ? (int)$year : $current_year;
    $target_month = ($year < $current_year) ? 12 : (($year > $current_year) ? 0 : $current_month);

    $santri_ids = array_values(array_unique(array_filter(array_map('intval', (array)$santri_ids))));
    if (empty($santri_ids)) {
        return [];
    }

    $result = [];
    foreach ($santri_ids as $id_santri) {
        $result[$id_santri] = [
            'status_key' => 'no_tagihan',
            'status_text' => 'Tidak ada tagihan bulanan aktif',
            'badge' => 'secondary',
            'lunas_sampai' => null,
            'bulan_lunas_tahun_berjalan' => 0,
            'bulan_lunas_berurutan' => 0,
            'is_telat' => false,
            'telat_bulan' => 0,
            'telat_nominal' => 0,
            'telat_daftar_bulan' => [],
            'telat_text' => null,
        ];
    }

    $id_list = implode(',', $santri_ids);
    $has_period_type = appColumnExists('iuran', 'periode_tipe');
    $periode_filter = $has_period_type ? "IFNULL(b.periode_tipe, 'bulanan') = 'bulanan'" : "1=1";

    $fee_rows = null;
    if (appTableExists('santri_iuran')) {
        $fee_rows = mysqli_query($conn, "
            SELECT sb.id_santri, b.id_iuran, b.nominal
            FROM santri_iuran sb
            JOIN iuran b ON sb.id_iuran = b.id_iuran
            WHERE sb.is_active = 1 AND $periode_filter AND sb.id_santri IN ($id_list)
        ");
    } else {
        $fee_rows = mysqli_query($conn, "
            SELECT s.id_santri, b.id_iuran, b.nominal
            FROM santri s
            JOIN iuran b ON s.id_iuran = b.id_iuran
            WHERE $periode_filter AND s.id_santri IN ($id_list)
        ");
    }

    $fee_nominal = [];
    while ($fee_rows && ($row = mysqli_fetch_assoc($fee_rows))) {
        $id_santri = (int)$row['id_santri'];
        $id_iuran = (int)$row['id_iuran'];
        $fee_nominal[$id_santri][$id_iuran] = (float)$row['nominal'];
    }

    $sum_rows = mysqli_query($conn, "
        SELECT id_santri, periode_key, id_iuran, COALESCE(SUM(jumlah_bayar), 0) as total
        FROM pembayaran
        WHERE id_santri IN ($id_list)
          AND periode_tipe = 'bulanan'
          AND periode_key >= '" . (int)$year . "-01'
        GROUP BY id_santri, periode_key, id_iuran
    ");

    $paid_sum = [];
    $max_paid_key = [];
    while ($sum_rows && ($row = mysqli_fetch_assoc($sum_rows))) {
        $id_santri = (int)$row['id_santri'];
        $periode_key = (string)$row['periode_key'];
        $id_iuran = (int)$row['id_iuran'];
        $total = (float)$row['total'];
        $paid_sum[$id_santri][$periode_key][$id_iuran] = $total;

        $nominal_target = $fee_nominal[$id_santri][$id_iuran] ?? 0;
        if ($nominal_target > 0 && $total >= $nominal_target) {
            if (!isset($max_paid_key[$id_santri][$id_iuran]) || strcmp($periode_key, $max_paid_key[$id_santri][$id_iuran]) > 0) {
                $max_paid_key[$id_santri][$id_iuran] = $periode_key;
            }
        }
    }

    foreach ($santri_ids as $id_santri) {
        $fees = $fee_nominal[$id_santri] ?? [];
        if (empty($fees)) {
            continue;
        }

        $complete_months = [];
        for ($m = 1; $m <= 12; $m++) {
            $periode_key = $year . '-' . str_pad((string)$m, 2, '0', STR_PAD_LEFT);
            $is_complete = true;
            foreach ($fees as $id_iuran => $nominal) {
                $total = $paid_sum[$id_santri][$periode_key][$id_iuran] ?? 0;
                $latest_lunas_key = $max_paid_key[$id_santri][$id_iuran] ?? null;
                // Bulan dianggap lunas jika dibayar penuh langsung ATAU ada bulan setelahnya yang sudah lunas
                if ($total < $nominal && ($latest_lunas_key === null || strcmp($latest_lunas_key, $periode_key) < 0)) {
                    $is_complete = false;
                    break;
                }
            }
            if ($is_complete) {
                $complete_months[$m] = true;
            }
        }

        $berurutan = 0;
        for ($m = 12; $m >= 1; $m--) {
            if (!empty($complete_months[$m])) {
                $berurutan = $m;
                break;
            }
        }

        for ($m = 1; $m <= $berurutan; $m++) {
            $complete_months[$m] = true;
        }

        // Hitung keterlambatan / tunggakan sampai dengan bulan berjalan ($target_month)
        $telat_bulan = 0;
        $telat_nominal = 0.0;
        $telat_daftar_bulan = [];
        for ($m = 1; $m <= $target_month; $m++) {
            if (empty($complete_months[$m])) {
                $telat_bulan++;
                $telat_daftar_bulan[] = bulanIndo($m);
                $pk_m = $year . '-' . str_pad((string)$m, 2, '0', STR_PAD_LEFT);
                foreach ($fees as $id_iuran => $nominal) {
                    $latest_lunas_key = $max_paid_key[$id_santri][$id_iuran] ?? null;
                    if ($latest_lunas_key !== null && strcmp($latest_lunas_key, $pk_m) >= 0) {
                        continue;
                    }
                    $paid_m = $paid_sum[$id_santri][$pk_m][$id_iuran] ?? 0.0;
                    $telat_nominal += max($nominal - $paid_m, 0.0);
                }
            }
        }
        $is_telat = ($telat_bulan > 0);
        $telat_range = '';
        if ($is_telat) {
            if (count($telat_daftar_bulan) === 1) {
                $telat_range = $telat_daftar_bulan[0];
            } else {
                $telat_range = $telat_daftar_bulan[0] . ' - ' . $telat_daftar_bulan[count($telat_daftar_bulan) - 1];
            }
        }
        $telat_text = $is_telat
            ? ('Telat ' . $telat_bulan . ' bln (' . $telat_range . ')')
            : null;

        $jumlah_lunas = count($complete_months);
        if ($jumlah_lunas === 0) {
            $has_any_payment = !empty($paid_sum[$id_santri]);
            $result[$id_santri] = [
                'status_key' => $has_any_payment ? 'belum_lunas' : 'belum_bayar',
                'status_text' => $has_any_payment ? 'Belum lunas' : 'Belum ada pembayaran bulanan',
                'badge' => $is_telat ? 'danger' : ($has_any_payment ? 'warning' : 'secondary'),
                'lunas_sampai' => null,
                'bulan_lunas_tahun_berjalan' => 0,
                'bulan_lunas_berurutan' => 0,
                'is_telat' => $is_telat,
                'telat_bulan' => $telat_bulan,
                'telat_nominal' => (int)$telat_nominal,
                'telat_daftar_bulan' => $telat_daftar_bulan,
                'telat_text' => $telat_text,
            ];
            continue;
        }

        if ($berurutan > 0) {
            $result[$id_santri] = [
                'status_key' => 'lunas_berurutan',
                'status_text' => 'Lunas s/d ' . bulanIndo($berurutan) . ' ' . $year,
                'badge' => 'success',
                'lunas_sampai' => $year . '-' . str_pad((string)$berurutan, 2, '0', STR_PAD_LEFT),
                'bulan_lunas_tahun_berjalan' => $jumlah_lunas,
                'bulan_lunas_berurutan' => $berurutan,
                'is_telat' => $is_telat,
                'telat_bulan' => $telat_bulan,
                'telat_nominal' => (int)$telat_nominal,
                'telat_daftar_bulan' => $telat_daftar_bulan,
                'telat_text' => $telat_text,
            ];
        } else {
            $result[$id_santri] = [
                'status_key' => 'belum_lunas',
                'status_text' => 'Belum lunas',
                'badge' => $is_telat ? 'danger' : 'warning',
                'lunas_sampai' => null,
                'bulan_lunas_tahun_berjalan' => $jumlah_lunas,
                'bulan_lunas_berurutan' => 0,
                'is_telat' => $is_telat,
                'telat_bulan' => $telat_bulan,
                'telat_nominal' => (int)$telat_nominal,
                'telat_daftar_bulan' => $telat_daftar_bulan,
                'telat_text' => $telat_text,
            ];
        }
    }

    return $result;
}

/**
 * Calculate monthly payment progress for one santri.
 */
function getSantriMonthlyProgress($id_santri, $year = null)
{
    $map = getSantriMonthlyProgressMap([(int)$id_santri], $year);
    return $map[(int)$id_santri] ?? [
        'status_key' => 'no_tagihan',
        'status_text' => 'Tidak ada tagihan bulanan aktif',
        'badge' => 'secondary',
        'lunas_sampai' => null,
        'bulan_lunas_tahun_berjalan' => 0,
        'bulan_lunas_berurutan' => 0,
    ];
}

/**
 * Calculate yearly payment progress for many santri.
 * Year is considered paid when all active yearly fees are fully paid in that year.
 */
function getSantriYearlyProgressMap($santri_ids, $year = null)
{
    global $conn;

    $year = $year ?: (int)date('Y');
    $santri_ids = array_values(array_unique(array_filter(array_map('intval', (array)$santri_ids))));
    if (empty($santri_ids)) {
        return [];
    }

    $result = [];
    foreach ($santri_ids as $id_santri) {
        $result[$id_santri] = [
            'status_key' => 'no_tagihan',
            'status_text' => 'Tidak ada tagihan tahunan aktif',
            'badge' => 'secondary',
            'tahun' => $year,
        ];
    }

    $id_list = implode(',', $santri_ids);
    $has_period_type = appColumnExists('iuran', 'periode_tipe');
    $periode_filter = $has_period_type ? "IFNULL(b.periode_tipe, 'bulanan') = 'tahunan'" : "1=0";

    $fee_rows = null;
    if (appTableExists('santri_iuran')) {
        $fee_rows = mysqli_query($conn, "
            SELECT sb.id_santri, b.id_iuran, b.nominal
            FROM santri_iuran sb
            JOIN iuran b ON sb.id_iuran = b.id_iuran
            WHERE sb.is_active = 1 AND $periode_filter AND sb.id_santri IN ($id_list)
        ");
    } else {
        $fee_rows = mysqli_query($conn, "
            SELECT s.id_santri, b.id_iuran, b.nominal
            FROM santri s
            JOIN iuran b ON s.id_iuran = b.id_iuran
            WHERE $periode_filter AND s.id_santri IN ($id_list)
        ");
    }

    $fee_nominal = [];
    while ($fee_rows && ($row = mysqli_fetch_assoc($fee_rows))) {
        $id_santri = (int)$row['id_santri'];
        $id_iuran = (int)$row['id_iuran'];
        $fee_nominal[$id_santri][$id_iuran] = (float)$row['nominal'];
    }

    $sum_rows = mysqli_query($conn, "
        SELECT id_santri, id_iuran, COALESCE(SUM(jumlah_bayar), 0) as total
        FROM pembayaran
        WHERE id_santri IN ($id_list)
          AND periode_tipe = 'tahunan'
          AND periode_key = '" . (int)$year . "'
        GROUP BY id_santri, id_iuran
    ");

    $paid_sum = [];
    while ($sum_rows && ($row = mysqli_fetch_assoc($sum_rows))) {
        $id_santri = (int)$row['id_santri'];
        $id_iuran = (int)$row['id_iuran'];
        $paid_sum[$id_santri][$id_iuran] = (float)$row['total'];
    }

    foreach ($santri_ids as $id_santri) {
        $fees = $fee_nominal[$id_santri] ?? [];
        if (empty($fees)) {
            continue;
        }

        $all_complete = true;
        $has_any_payment = false;
        foreach ($fees as $id_iuran => $nominal) {
            $total = $paid_sum[$id_santri][$id_iuran] ?? 0;
            if ($total > 0) {
                $has_any_payment = true;
            }
            if ($total < $nominal) {
                $all_complete = false;
            }
        }

        if ($all_complete) {
            $result[$id_santri] = [
                'status_key' => 'lunas',
                'status_text' => 'Lunas tahun ' . $year,
                'badge' => 'success',
                'tahun' => $year,
            ];
        } else {
            $result[$id_santri] = [
                'status_key' => $has_any_payment ? 'belum_lunas' : 'belum_bayar',
                'status_text' => $has_any_payment ? 'Belum lunas tahun ' . $year : 'Belum ada pembayaran tahunan',
                'badge' => $has_any_payment ? 'warning' : 'secondary',
                'tahun' => $year,
            ];
        }
    }

    return $result;
}

/**
 * Calculate yearly payment progress for one santri.
 */
function getSantriYearlyProgress($id_santri, $year = null)
{
    $map = getSantriYearlyProgressMap([(int)$id_santri], $year);
    return $map[(int)$id_santri] ?? [
        'status_key' => 'no_tagihan',
        'status_text' => 'Tidak ada tagihan tahunan aktif',
        'badge' => 'secondary',
        'tahun' => $year ?: (int)date('Y'),
    ];
}
?>
