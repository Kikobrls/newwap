<?php
/** Guardian billing uses existing payment periods and the existing "ikut lunas" rule. */
function waliDueDate($year, $month, $day)
{
    $first = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
    return $first->setDate((int)$year, (int)$month, min(max((int)$day, 1), (int)$first->format('t')));
}

function waliBillStatus($remaining, DateTimeImmutable $due, DateTimeImmutable $today)
{
    if ($remaining <= 0) return ['label' => 'Lunas', 'color' => 'success', 'remind' => false];
    $days = (int)$today->diff($due)->format('%r%a');
    if ($days < 0) return ['label' => 'Terlambat ' . abs($days) . ' hari', 'color' => 'danger', 'remind' => true];
    if ($days === 0) return ['label' => 'Jatuh tempo hari ini', 'color' => 'warning', 'remind' => true];
    if ($days <= 7) return ['label' => 'Jatuh tempo ' . $days . ' hari lagi', 'color' => 'warning', 'remind' => true];
    return ['label' => 'Belum jatuh tempo', 'color' => 'secondary', 'remind' => false];
}

/** Pure function, so period boundaries / partial payments can be regression-tested. */
function waliBuildBills(array $assignments, array $payments, DateTimeImmutable $today)
{
    $paid = [];
    $latestFull = [];
    foreach ($payments as $payment) {
        $id = (int)$payment['id_iuran'];
        $type = $payment['periode_tipe'];
        $key = $payment['periode_key'];
        $paid[$id][$type][$key] = (float)$payment['total'];
    }
    $bills = [];
    foreach ($assignments as $fee) {
        $id = (int)$fee['id_iuran'];
        $year = (int)$fee['tahun'];
        if ($year < 2000 || $year > 2100 || (float)$fee['nominal'] <= 0) continue;
        $annual = $fee['periode_tipe'] === 'tahunan';
        $type = $annual ? 'tahunan' : 'bulanan';
        $periods = [];
        if ($annual) {
            $periods[] = [(string)$year, (int)$fee['jatuh_tempo_bulan']];
        } else {
            // Never create bills before enrollment / assignment or outside the tariff year.
            $start = max(sprintf('%04d-01', $year), substr($fee['mulai'], 0, 7));
            $end = min(sprintf('%04d-12', $year), $today->modify('first day of next month')->format('Y-m'));
            for ($month = 1; $month <= 12; $month++) {
                $key = sprintf('%04d-%02d', $year, $month);
                if ($key >= $start && $key <= $end) $periods[] = [$key, $month];
            }
            foreach ($paid[$id]['bulanan'] ?? [] as $key => $amount) {
                if ($amount >= (float)$fee['nominal'] && $key > ($latestFull[$id] ?? '')) $latestFull[$id] = $key;
            }
        }
        foreach ($periods as [$key, $month]) {
            $direct = $paid[$id][$type][$key] ?? 0.0;
            $carried = !$annual && $direct < (float)$fee['nominal'] && ($latestFull[$id] ?? '') > $key;
            $remaining = $carried ? 0.0 : max((float)$fee['nominal'] - $direct, 0.0);
            $due = waliDueDate($year, $month, $fee['jatuh_tempo_hari']);
            $bills[] = array_merge($fee, [
                'periode_key' => $key,
                'periode_label' => $annual ? (string)$year : formatPeriodeKeyBulanan($key),
                'terbayar' => $direct,
                'sisa' => $remaining,
                'ikut_lunas' => $carried,
                'due' => $due->format('Y-m-d'),
                'status' => waliBillStatus($remaining, $due, $today),
            ]);
        }
    }
    usort($bills, function ($a, $b) {
        return [($a['sisa'] <= 0 ? 1 : 0), $a['due'], $a['nama_iuran']] <=> [($b['sisa'] <= 0 ? 1 : 0), $b['due'], $b['nama_iuran']];
    });
    return $bills;
}

function waliSantriList($conn, $userId)
{
    $stmt = mysqli_prepare($conn, 'SELECT s.*, k.nama_kelas FROM wali_santri w JOIN santri s ON s.id_santri = w.id_santri LEFT JOIN kelas k ON k.id_kelas = s.id_kelas WHERE w.id_user = ? ORDER BY s.nama');
    mysqli_stmt_bind_param($stmt, 'i', $userId);
    mysqli_stmt_execute($stmt);
    $children = [];
    foreach (mysqli_stmt_get_result($stmt) as $child) $children[(int)$child['id_santri']] = $child;
    return $children;
}

function waliSantriBills($conn, $userId, $santriId)
{
    // Ownership is checked in SQL too, not just by hiding the student selector.
    $stmt = mysqli_prepare($conn, "SELECT i.*, GREATEST(s.created_at, si.created_at, i.created_at) AS mulai
        FROM wali_santri w JOIN santri s ON s.id_santri = w.id_santri
        JOIN santri_iuran si ON si.id_santri = s.id_santri AND si.is_active = 1
        JOIN iuran i ON i.id_iuran = si.id_iuran
        WHERE w.id_user = ? AND s.id_santri = ? AND s.status = 'active'");
    mysqli_stmt_bind_param($stmt, 'ii', $userId, $santriId);
    mysqli_stmt_execute($stmt);
    $assignments = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
    $stmt = mysqli_prepare($conn, 'SELECT p.id_iuran, p.periode_tipe, p.periode_key, SUM(p.jumlah_bayar) AS total
        FROM pembayaran p JOIN wali_santri w ON w.id_santri = p.id_santri
        WHERE w.id_user = ? AND p.id_santri = ? GROUP BY p.id_iuran, p.periode_tipe, p.periode_key');
    mysqli_stmt_bind_param($stmt, 'ii', $userId, $santriId);
    mysqli_stmt_execute($stmt);
    return waliBuildBills($assignments, mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC), new DateTimeImmutable('today'));
}
