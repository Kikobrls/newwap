<?php
/**
 * Helper periode pembayaran.
 *
 * Dipisah dari config/app.php supaya halaman pembayaran (input, kolektif/bulk)
 * dan API-nya tetap jalan walaupun config/app.php di server masih versi lama
 * (sebelumnya muncul "Call to undefined function expandPeriodeBulanan()").
 *
 * Aman di-require berkali-kali: tiap fungsi dibungkus function_exists().
 * Membutuhkan bulanIndo() dan $bulan_indo dari config/app.php (ada di semua versi).
 */

/**
 * Format monthly periode_key ("YYYY-MM") into Indonesian label ("Bulan Tahun").
 */
if (!function_exists('formatPeriodeKeyBulanan')) {
    function formatPeriodeKeyBulanan($periode_key)
    {
        if (!preg_match('/^(\d{4})-(\d{2})$/', (string)$periode_key, $m)) {
            return (string)$periode_key;
        }
        $bln = bulanIndo((int)$m[2]);
        return trim(($bln ?: $m[2]) . ' ' . $m[1]);
    }
}

/**
 * Check cumulative monthly paid state for a santri & iuran on a given periode_key ("YYYY-MM").
 *
 * Rule: If a later month (>= $periode_key) for the same santri & monthly iuran is already
 * fully paid (total >= nominal), then this month ($periode_key) is also considered paid
 * ("ikut lunas").
 */
if (!function_exists('getSantriIuranBulananPaidState')) {
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
if (!function_exists('expandPeriodeBulanan')) {
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
}
