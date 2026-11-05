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

// Refresh authenticated roles so deactivation / role changes apply to existing sessions.
if (($_SESSION['login'] ?? false) === true) {
    require_once __DIR__ . '/database.php';
    $session_user_id = (int)($_SESSION['id_user'] ?? 0);
    $session_user = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT level, status FROM users WHERE id_user = $session_user_id"));
    if (!$session_user || $session_user['status'] !== 'active') {
        session_unset();
        header('Location: ' . APP_URL . '/login.php');
        exit;
    }
    $_SESSION['level'] = $session_user['level'];
    if ($session_user['level'] === 'wali_santri') {
        $route = substr($_SERVER['SCRIPT_NAME'] ?? '', strlen($app_subfolder));
        if ($route === '/index.php' || $route === '/login.php') {
            header('Location: ' . APP_URL . '/pages/wali/index.php');
            exit;
        }
        if (!in_array($route, ['/pages/wali/index.php', '/logout.php'], true)) {
            http_response_code(403);
            if (strpos($route, '/api/') === 0) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['error' => 'Akses hanya untuk portal santri Anda.']);
            } else {
                echo 'Akses ditolak. Silakan kembali ke portal wali santri.';
            }
            exit;
        }
    }
}

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

// Helper periode pembayaran (expandPeriodeBulanan, getSantriIuranBulananPaidState,
// formatPeriodeKeyBulanan) dipisah ke includes/pembayaran_helpers.php agar halaman
// pembayaran tetap jalan walaupun config/app.php di server masih versi lama.
if (file_exists(__DIR__ . '/../includes/pembayaran_helpers.php')) {
    require_once __DIR__ . '/../includes/pembayaran_helpers.php';
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
