<?php
// Run: php scripts/test_wali.php (pure tests; no database or session required).
date_default_timezone_set('Asia/Jakarta');
require_once __DIR__ . '/../includes/wali_helpers.php';
require_once __DIR__ . '/../includes/pembayaran_helpers.php';
function bulanIndo($month) { return ['Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'][$month - 1]; }
$count = 0;
function check($condition, $label) {
    global $count;
    if (!$condition) throw new RuntimeException('FAIL: ' . $label);
    $count++;
    echo "PASS: $label\n";
}
check(waliDueDate(2026, 2, 31)->format('Y-m-d') === '2026-02-28', 'February clamps to month end');
check(waliDueDate(2028, 2, 31)->format('Y-m-d') === '2028-02-29', 'Leap year');
check(waliDueDate(2026, 4, 31)->format('Y-m-d') === '2026-04-30', 'April clamps to month end');
$today = new DateTimeImmutable('2026-11-05');
foreach ([-1 => 'danger', 0 => 'warning', 7 => 'warning', 8 => 'secondary'] as $days => $color) {
    $status = waliBillStatus(100, $today->modify(sprintf('%+d days', $days)), $today);
    check($status['color'] === $color, 'Reminder day offset ' . $days);
    check($status['remind'] === ($days <= 7), 'Reminder visibility offset ' . $days);
}
check(!waliBillStatus(0, $today->modify('-30 days'), $today)['remind'], 'Paid bills never remind');
$fee = ['id_iuran' => 1, 'nama_iuran' => 'SPP', 'tahun' => 2026, 'periode_tipe' => 'bulanan', 'nominal' => 100000, 'jatuh_tempo_hari' => 10, 'jatuh_tempo_bulan' => 7, 'mulai' => '2026-09-01'];
$bills = waliBuildBills([$fee], [], $today);
check(count($bills) === 4, 'September through December only');
check($bills[0]['periode_key'] === '2026-09', 'No bills before enrollment');
check(count(array_filter($bills, function ($b) { return $b['status']['remind']; })) === 3, 'Two overdue and current H-5 reminder');
$payments = [['id_iuran' => 1, 'periode_tipe' => 'bulanan', 'periode_key' => '2026-11', 'total' => 40000]];
$bills = waliBuildBills([$fee], $payments, $today);
$byPeriod = array_column($bills, null, 'periode_key');
check($byPeriod['2026-11']['sisa'] === 60000.0, 'Partial payment balance');
check($byPeriod['2026-09']['sisa'] === 100000.0, 'Partial future payment does not settle earlier months');
$payments[0]['total'] = 100000;
$byPeriod = array_column(waliBuildBills([$fee], $payments, $today), null, 'periode_key');
check($byPeriod['2026-09']['ikut_lunas'] === true && $byPeriod['2026-09']['sisa'] === 0.0, 'Existing ikut lunas rule');
check($byPeriod['2026-11']['ikut_lunas'] === false && $byPeriod['2026-11']['sisa'] === 0.0, 'Direct full payment');
check($byPeriod['2026-12']['sisa'] === 100000.0, 'Full payment does not settle later months');
$payments[0]['total'] = 150000;
$byPeriod = array_column(waliBuildBills([$fee], $payments, $today), null, 'periode_key');
check($byPeriod['2026-11']['sisa'] === 0.0, 'Overpayments never produce negative balance');
$fee['periode_tipe'] = 'tahunan';
$annual = waliBuildBills([$fee], [], $today);
check(count($annual) === 1 && $annual[0]['due'] === '2026-07-10', 'Annual due date uses configured month');
$annual = waliBuildBills([$fee], [['id_iuran' => 1, 'periode_tipe' => 'tahunan', 'periode_key' => '2026', 'total' => 100000]], $today);
check($annual[0]['sisa'] === 0.0 && !$annual[0]['status']['remind'], 'Annual full payment');
$fee['periode_tipe'] = 'bulanan';
$fee['tahun'] = 2027;
$fee['mulai'] = '2026-09-01';
$bills = waliBuildBills([$fee], [], new DateTimeImmutable('2026-12-28'));
check(count($bills) === 1 && $bills[0]['periode_key'] === '2027-01', 'Next-year upcoming bill');
$fee['tahun'] = 2025;
$fee['mulai'] = '2025-11-01';
$bills = waliBuildBills([$fee], [], $today);
check(count($bills) === 2 && $bills[1]['periode_key'] === '2025-12', 'Old tariff never repeats into another year');
check(waliBuildBills([], [], $today) === [], 'Empty assignments');
echo "$count checks passed.\n";
