<?php
/**
 * Pembayaran Kolektif (Bulk Action) dengan Multi Bayar
 * Sistem Administrasi Keuangan Sekolah
 */

$page_title = 'Pembayaran Kolektif';
$extra_css = '
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/plugins/monthSelect/style.css">
<style>
.flatpickr-calendar.open { z-index: 1060; }
.flatpickr-monthSelect-months { display: flex; flex-wrap: wrap; padding: 4px; margin: 6px 0 2px; }
.flatpickr-monthSelect-month { flex: 0 0 33.333%; max-width: 33.333%; width: auto; margin: 0; padding: 9px 0; font-size: 0.85rem; }
.periode-picker { background-color: #fff !important; cursor: pointer; }
.periode-picker:disabled { background-color: #e9ecef !important; cursor: default; }
.periode-info { min-height: 1.2em; }
</style>
';
require_once '../../includes/header.php';

// Block kepala_tpq from accessing this page
if ($_SESSION['level'] == 'kepala_tpq') {
    header("Location: ../laporan/index.php");
    exit;
}

require_once '../../includes/sidebar.php';
require_once '../../includes/topbar.php';

$errors = [];


// Variables from GET for Step 1
$filter_kelas_raw = isset($_GET['kelas']) ? sanitize($_GET['kelas']) : '';
$is_all_kelas = ($filter_kelas_raw === 'all' || $filter_kelas_raw === '0');
$filter_kelas = $is_all_kelas ? 0 : (int) $filter_kelas_raw;
$filter_mode = isset($_GET['mode']) ? sanitize($_GET['mode']) : 'bulanan';

// Handle POST request (Step 2 - Submit Pembayaran)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['submit_bulk'])) {
    $tgl_bayar = date('Y-m-d');
    $metode_bayar = 'tunai';
    $keterangan = sanitize($_POST['keterangan'] ?? '');

    $pilih_santri = $_POST['pilih_santri'] ?? [];
    
    // Items data
    $pilih_item = $_POST['pilih_item'] ?? [];
    // Bulanan: rentang periode dari flatpickr (format YYYY-MM), 1 kolom untuk beberapa bulan
    // SATU rentang periode (format YYYY-MM) yang berlaku untuk semua iuran bulanan
    $periode_awal_global = trim((string) ($_POST['periode_awal'] ?? ''));
    $periode_akhir_global = trim((string) ($_POST['periode_akhir'] ?? ''));
    // Tahunan: tahun dibayar
    $tahun_dibayar = $_POST['tahun_dibayar'] ?? [];
    $jumlah_bayar = $_POST['jumlah_bayar'] ?? [];
    
    // Inherited mode
    $mode = strtolower(sanitize($_POST['mode_pembayaran'] ?? 'tahunan'));

    if (empty($pilih_santri)) {
        $errors[] = 'Pilih minimal 1 santri untuk diproses pembayarannya!';
    }
    if (empty($pilih_item)) {
        $errors[] = 'Pilih minimal 1 tagihan/iuran untuk dibayar!';
    }

    $iuran_map = [];
    if (empty($errors)) {
        $r = mysqli_query($conn, "SELECT * FROM iuran");
        while ($row = mysqli_fetch_assoc($r)) {
            $iuran_map[(int)$row['id_iuran']] = $row;
        }
    }

    if (empty($errors)) {
        mysqli_begin_transaction($conn);
        try {
            $count_success_santri = 0;
            $count_success_items = 0;
            $total_tersimpan = 0;
            
            // Validate all items first
            $detail_items = [];
            foreach ($pilih_item as $id_iuran_raw => $val) {
                $id_iuran = (int) $id_iuran_raw;
                if ($id_iuran <= 0 || !isset($iuran_map[$id_iuran])) continue;

                $iuran = $iuran_map[$id_iuran];
                $periode_tipe = isset($iuran['periode_tipe']) && $iuran['periode_tipe'] === 'tahunan' ? 'tahunan' : 'bulanan';
                
                $jumlah_raw = str_replace(['.', ','], '', (string) ($jumlah_bayar[$id_iuran] ?? '0'));
                $jumlah = (float) $jumlah_raw;
                if ($jumlah <= 0) {
                    throw new Exception('Jumlah bayar untuk tagihan ' . $iuran['nama_iuran'] . ' harus lebih dari 0.');
                }

                // Build list of periods to process
                $periode_list_process = [];
                if ($periode_tipe === 'bulanan') {
                    if ($periode_awal_global === '') {
                        throw new Exception('Periode bulan harus dipilih.');
                    }
                    $periode_list_process = expandPeriodeBulanan($periode_awal_global, $periode_akhir_global);
                    if (empty($periode_list_process)) {
                        throw new Exception('Rentang periode tidak valid (maksimal 36 bulan).');
                    }
                } else {
                    $tahun = preg_replace('/\D/', '', (string) ($tahun_dibayar[$id_iuran] ?? ''));
                    if (empty($tahun)) {
                        throw new Exception('Tahun untuk tagihan ' . $iuran['nama_iuran'] . ' harus diisi.');
                    }
                    $periode_list_process[] = ['bulan' => '-', 'tahun' => $tahun, 'periode_key' => buildPeriodeKey('tahunan', $tahun)];
                }

                foreach ($periode_list_process as $periode) {
                    if (empty($periode['periode_key'])) {
                        throw new Exception('Periode untuk tagihan ' . $iuran['nama_iuran'] . ' tidak lengkap.');
                    }

                    $detail_items[] = [
                        'id_iuran' => $id_iuran,
                        'iuran_info' => $iuran,
                        'periode_tipe' => $periode_tipe,
                        'bulan' => $periode['bulan'],
                        'tahun' => $periode['tahun'],
                        'periode_key' => $periode['periode_key'],
                        'jumlah' => $jumlah
                    ];
                }
            }

            if (empty($detail_items)) {
                throw new Exception("Tidak ada item tagihan valid.");
            }

            // Execute for each Santri
            foreach ($pilih_santri as $id_santri_raw) {
                $id_santri = (int) $id_santri_raw;
                if ($id_santri <= 0) continue;
                
                $santri_processed = false;

                foreach ($detail_items as $item) {
                    $id_iuran = $item['id_iuran'];
                    $periode_key = $item['periode_key'];
                    $jumlah = $item['jumlah'];
                    $nominal = (float) $item['iuran_info']['nominal'];
                    $nama_iuran = $item['iuran_info']['nama_iuran'];

                    // Get already paid amount (including cumulative prior-month paid state for bulanan)
                    if ($item['periode_tipe'] === 'bulanan') {
                        $paidState = getSantriIuranBulananPaidState($id_santri, $id_iuran, $nominal, $periode_key);
                        $sudah_bayar = (float) $paidState['sudah_bayar'];
                        if (!empty($paidState['ikut_lunas'])) {
                            $nm_q = mysqli_query($conn, "SELECT nama FROM santri WHERE id_santri = '$id_santri'");
                            $nm_row = $nm_q ? mysqli_fetch_assoc($nm_q) : null;
                            $nama_santri = $nm_row['nama'] ?? ('ID ' . $id_santri);
                            $label_periode = formatPeriodeTagihan($item['periode_tipe'], $item['bulan'], $item['tahun']);
                            throw new Exception("Pembayaran santri $nama_santri untuk tagihan $nama_iuran periode $label_periode sudah ikut lunas karena periode {$paidState['lunas_oleh_label']} sudah dibayar lunas.");
                        }
                    } else {
                        $sum_q = mysqli_query($conn, "
                            SELECT COALESCE(SUM(jumlah_bayar), 0) as total
                            FROM pembayaran
                            WHERE id_santri = '$id_santri'
                              AND id_iuran = '$id_iuran'
                              AND periode_key = '" . mysqli_real_escape_string($conn, $periode_key) . "'
                        ");
                        $sum_row = mysqli_fetch_assoc($sum_q);
                        $sudah_bayar = (float) $sum_row['total'];
                    }
                    
                    if (($sudah_bayar + $jumlah) > $nominal) {
                        // Throw to stop everything, or skip? We throw to prevent partial invalid states.
                        $nm_q = mysqli_query($conn, "SELECT nama FROM santri WHERE id_santri = '$id_santri'");
                        $nm_row = $nm_q ? mysqli_fetch_assoc($nm_q) : null;
                        $nama_santri = $nm_row['nama'] ?? ('ID ' . $id_santri);
                        $label_periode = formatPeriodeTagihan($item['periode_tipe'], $item['bulan'], $item['tahun']);
                        throw new Exception("Pembayaran santri $nama_santri untuk tagihan $nama_iuran periode $label_periode melebihi sisa tagihan. Sisa maksimal: " . formatRupiah(max($nominal - $sudah_bayar, 0)) . ".");
                    }

                    $insert_detail = "INSERT INTO pembayaran (id_santri, tgl_bayar, periode_tipe, periode_key, bulan_dibayar, tahun_dibayar, id_iuran, jumlah_bayar, metode_bayar, keterangan)
                                      VALUES ('$id_santri', '$tgl_bayar', '" . $item['periode_tipe'] . "', '" . mysqli_real_escape_string($conn, $periode_key) . "', '" . mysqli_real_escape_string($conn, $item['bulan']) . "', '" . mysqli_real_escape_string($conn, $item['tahun']) . "', '$id_iuran', '$jumlah', '$metode_bayar', '" . mysqli_real_escape_string($conn, $keterangan) . "')";
                    if (!mysqli_query($conn, $insert_detail)) {
                        throw new Exception('Gagal menyimpan item pembayaran.');
                    }
                    
                    $last_insert_id = mysqli_insert_id($conn);
                    logActivity("Pembayaran kolektif", 'pembayaran', $last_insert_id);
                    
                    $santri_processed = true;
                    $count_success_items++;
                    $total_tersimpan += $jumlah;
                }

                if ($santri_processed) {
                    $count_success_santri++;
                }
            }

            if ($count_success_items == 0) {
                throw new Exception("Tidak ada santri yang berhasil diproses.");
            }

            if (!mysqli_commit($conn)) {
                throw new Exception('Gagal menyelesaikan transaksi pembayaran.');
            }
            setFlash('success', 'Pembayaran iuran kolektif berhasil disimpan. Total: ' . formatRupiah($total_tersimpan)
                . '. Jumlah santri: ' . $count_success_santri . '. Jumlah item: ' . $count_success_items . '.');
            header('Location: index.php', true, 303);
            exit;
            
        } catch (Exception $e) {
            mysqli_rollback($conn);
            $errors[] = 'Gagal menyimpan pembayaran kolektif: ' . $e->getMessage();
        }
    }
}

// Prepare data for forms
$kelas_list = mysqli_query($conn, 'SELECT * FROM kelas ORDER BY nama_kelas');
$bulan_list = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];

// Get Santri list
$santri_targets = [];
$nama_kelas_terpilih = "";

if ($filter_kelas > 0 || $is_all_kelas) {
    if ($is_all_kelas) {
        $nama_kelas_terpilih = "Semua Kelas";
        $qs = mysqli_query($conn, "
            SELECT s.id_santri, s.nama, s.status, k.nama_kelas
            FROM santri s
            JOIN kelas k ON s.id_kelas = k.id_kelas
            WHERE s.status = 'active'
            ORDER BY k.nama_kelas ASC, s.nama ASC
        ");
    } else {
        // Get class name
        $q_k = mysqli_query($conn, "SELECT nama_kelas FROM kelas WHERE id_kelas = '$filter_kelas'");
        if ($q_k && mysqli_num_rows($q_k) > 0) {
            $k_row = mysqli_fetch_assoc($q_k);
            $nama_kelas_terpilih = $k_row['nama_kelas'];
        }

        $qs = mysqli_query($conn, "
            SELECT id_santri, nama, status
            FROM santri
            WHERE id_kelas = '$filter_kelas' AND status = 'active'
            ORDER BY nama ASC
        ");
    }

    if ($qs) {
        while ($row = mysqli_fetch_assoc($qs)) {
            $santri_targets[] = $row;
        }
    }
}
?>

<!-- Page Header (SB Admin Pro style) -->
<header class="page-header page-header-compact page-header-light border-bottom bg-white mb-4">
    <div class="container-xl px-4">
        <div class="page-header-content">
            <div class="row align-items-center justify-content-between pt-3">
                <div class="col-auto mb-3">
                    <h1 class="page-header-title">
                        <div class="page-header-icon"><i class="fas fa-layer-group"></i></div>
                        Pembayaran Kolektif
                    </h1>
                </div>
                <div class="col-12 col-md-auto mb-3">
                    <a href="index.php" class="btn btn-sm btn-secondary">
                        <i class="fas fa-arrow-left me-1"></i> Kembali
                    </a>
                </div>
            </div>
        </div>
    </div>
</header>

<div class="container-xl px-4 mt-4">

    <?php
    if (!empty($errors)) {
        setFlash('danger', implode(' ', $errors));
    }
    ?>

    <!-- STEP 1: Filter Form -->
    <div class="card shadow mb-4">
        <div class="card-header py-3">
            <h6 class="m-0 font-weight-bold text-primary">Langkah 1: Pilih Kelas & Mode</h6>
        </div>
        <div class="card-body">
            <form method="GET" action="">
                <div class="row align-items-end">
                    <div class="col-md-4 mb-3">
                        <label>Pilih Kelas <span class="text-danger">*</span></label>
                        <select name="kelas" class="form-control" required>
                            <option value="">-- Pilih Kelas --</option>
                            <option value="all" <?php echo $is_all_kelas ? 'selected' : ''; ?>>Semua Kelas</option>
                            <?php while ($kls = mysqli_fetch_assoc($kelas_list)): ?>
                                <option value="<?php echo $kls['id_kelas']; ?>" <?php echo $filter_kelas == $kls['id_kelas'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($kls['nama_kelas']); ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-4 mb-3">
                        <label>Mode Pembayaran <span class="text-danger">*</span></label>
                        <select name="mode" class="form-control" required>
                            <option value="bulanan" <?php echo $filter_mode === 'bulanan' ? 'selected' : ''; ?>>Bulanan</option>
                            <option value="tahunan" <?php echo $filter_mode === 'tahunan' ? 'selected' : ''; ?>>Tahunan</option>
                        </select>
                    </div>
                    
                    <div class="col-md-4 mb-3">
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="fas fa-search"></i> Tampilkan Form
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- STEP 2: Santri Selection and Payment Input Form -->
    <?php if ($filter_kelas > 0 || $is_all_kelas): ?>
        <div class="card shadow mb-4 border-bottom-primary">
            <div class="card-header py-3">
                <h6 class="m-0 font-weight-bold text-primary">Langkah 2: Lengkapi Pembayaran Kelas <?php echo htmlspecialchars($nama_kelas_terpilih); ?></h6>
            </div>
            <div class="card-body">
                <?php if (empty($santri_targets)): ?>
                    <div class="alert alert-warning text-center">
                        Tidak ada data santri aktif untuk Kelas ini.
                    </div>
                <?php else: ?>
                    <form method="POST" action="" id="formBulkPayment">
                        <input type="hidden" name="mode_pembayaran" value="<?php echo htmlspecialchars($filter_mode); ?>">

                        <div class="row">
                            <!-- Left Col: Pengaturan Tagihan -->
                            <div class="col-lg-6 mb-4">
                                <div class="card bg-light h-100">
                                    <div class="card-body">
                                        <h6 class="font-weight-bold mb-3"><i class="fas fa-list-check mr-2"></i>Pilih Tagihan (Berlaku Semua)</h6>

                                        <?php if ($filter_mode === 'bulanan'): ?>
                                        <div class="form-group mb-3" id="periodeGlobalGroup">
                                            <label class="small font-weight-bold">Periode Bulan <span class="text-danger">*</span> <span class="text-muted font-weight-normal">(berlaku untuk semua iuran)</span></label>
                                            <input type="text" class="form-control form-control-sm periode-picker" id="periodeGlobal" placeholder="Klik untuk pilih bulan / rentang bulan..." readonly>
                                            <input type="hidden" name="periode_awal" id="periode_awal" value="">
                                            <input type="hidden" name="periode_akhir" id="periode_akhir" value="">
                                            <small class="form-text text-muted periode-info" id="periodeGlobalInfo"></small>
                                        </div>
                                        <?php endif; ?>

                                        <div id="tagihanContainer" class="mb-3">
                                            <div class="text-center text-muted">Akan memuat tagihan...</div>
                                        </div>

                                        <hr>


                                        <div class="form-group mb-2">
                                            <label class="small font-weight-bold">Keterangan (Massal)</label>
                                            <textarea name="keterangan" class="form-control form-control-sm" rows="2" placeholder="Opsional"></textarea>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Right Col: Santri Checklist -->
                            <div class="col-lg-6 mb-4">
                                <div class="card shadow-sm h-100 border-0">
                                    <div class="card-body p-0">
                                        <div class="table-responsive" style="max-height: 500px; overflow-y: auto;">
                                            <table class="table table-bordered table-hover mb-0" id="bulkTable" width="100%" cellspacing="0">
                                                <thead class="bg-primary text-white" style="position: sticky; top: 0; z-index: 10;">
                                                    <tr>
                                                        <th style="width: 50px;" class="text-center">
                                                                <input type="checkbox" id="checkAllSantri">
                                                        </th>
                                                        <th style="width: 50px;">No</th>
                                                        <th>Nama Santri</th>
                                                        <?php if ($is_all_kelas): ?>
                                                            <th>Kelas</th>
                                                        <?php endif; ?>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php 
                                                    $no = 1;
                                                    foreach ($santri_targets as $santri): 
                                                    ?>
                                                        <tr>
                                                            <td class="text-center">
                                                                <input type="checkbox" class="santri-check" name="pilih_santri[]" value="<?php echo $santri['id_santri']; ?>">
                                                            </td>
                                                            <td><?php echo $no++; ?></td>
                                                            <td class="font-weight-bold"><?php echo htmlspecialchars($santri['nama']); ?></td>
                                                            <?php if ($is_all_kelas): ?>
                                                                <td><?php echo htmlspecialchars($santri['nama_kelas'] ?? '-'); ?></td>
                                                            <?php endif; ?>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                    <div class="card-footer bg-white text-muted small text-center border-top-0">
                                        Hanya santri yang dicentang yang akan diproses transaksinya. Pengecekan lunas tidak bisa dilakukan massal karena Sisa Terutang setiap siswa berbeda bergantung pada riwayat masing-masing.
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mt-2 text-right">
                            <button type="submit" name="submit_bulk" class="btn btn-success btn-lg shadow">
                                <i class="fas fa-save mr-2"></i> Simpan Pembayaran Massal
                            </button>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

</div>

<?php
$extra_js = '
<script src="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/l10n/id.js"></script>
<script src="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/plugins/monthSelect/index.js"></script>
<script>
    const filterKelas = "' . addslashes($filter_kelas_raw) . '";
    const filterMode = "' . htmlspecialchars($filter_mode) . '";
    const bulanList = ' . json_encode($bulan_list) . ';

    function formatRupiahJs(number) {
        return new Intl.NumberFormat("id-ID", { style: "currency", currency: "IDR", minimumFractionDigits: 0 }).format(number || 0);
    }

    function formatCurrency(element) {
        let value = element.value.replace(/[^,\d]/g, \'\');
        let parts = value.split(\',\');
        let sisa = parts[0].length % 3;
        let rupiah = parts[0].substr(0, sisa);
        let ribuan = parts[0].substr(sisa).match(/\d{3}/gi);

        if (ribuan) {
            let separator = sisa ? \'.\' : \'\';
            rupiah += separator + ribuan.join(\'.\');
        }

        rupiah = parts[1] !== undefined ? rupiah + \',\' + parts[1] : rupiah;
        element.value = rupiah;
    }

    function renderTagihan(items, mode) {
        if (!items || !items.length) {
            const targetText = (filterKelas === "all" || filterKelas === "0") ? "semua kelas" : "kelas ini";
            const msg = mode === "bulanan"
                ? `Tagihan bulanan aktif tidak ditemukan untuk ${targetText}.`
                : `Tagihan tahunan aktif tidak ditemukan untuk ${targetText}.`;
            document.getElementById("tagihanContainer").innerHTML = `<div class="text-danger small">${msg}</div>`;
            return;
        }

        const currentYear = new Date().getFullYear();
        const isBulanan = mode === "bulanan";

        const html = items.map((item) => {
            const disabledAttr = "";
            const defaultAmount = Math.max(item.nominal || 0, 0);

            return `
                <div class="card mb-2 border-left-info shadow-sm">
                    <div class="card-body p-2">
                        <div class="custom-control custom-checkbox mb-1">
                            <input type="checkbox" class="custom-control-input item-check" id="check_${item.id_iuran}" name="pilih_item[${item.id_iuran}]" value="1" data-id="${item.id_iuran}" checked>
                            <label class="custom-control-label font-weight-bold text-dark" for="check_${item.id_iuran}">
                                ${item.nama_iuran}
                            </label>
                        </div>
                        <div class="small text-muted mb-2 ml-4">Nominal Tagihan: ${formatRupiahJs(item.nominal)}</div>

                        <div class="pl-4">
                            <div class="form-row">
                                ${isBulanan ? "" : `
                                <div class="col-4 mb-1">
                                    <label class="small mb-1">Tahun</label>
                                    <input type="number" class="form-control form-control-sm item-field" name="tahun_dibayar[${item.id_iuran}]" placeholder="Tahun" value="${item.default_tahun || currentYear}" min="2020" max="2100" data-item="${item.id_iuran}" ${disabledAttr}>
                                </div>`}

                                <div class="col-12 mt-1 mb-1">
                                    <div class="input-group input-group-sm">
                                        <div class="input-group-prepend"><span class="input-group-text bg-light text-dark font-weight-bold" style="font-size: 0.75rem;">${isBulanan ? "Nominal per Bulan/Santri" : "Nominal per Santri"}</span><span class="input-group-text">Rp</span></div>
                                        <input type="text" class="form-control item-field jumlah-field" name="jumlah_bayar[${item.id_iuran}]" id="jumlah_${item.id_iuran}" value="${new Intl.NumberFormat("id-ID").format(defaultAmount)}" data-item="${item.id_iuran}" onkeyup="formatCurrency(this); updatePeriodeInfo();" ${disabledAttr}>
                                    </div>
                                    ${isBulanan ? `<small class="text-muted item-subtotal" data-item="${item.id_iuran}"></small>` : ""}
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            `;
        }).join("");

        document.getElementById("tagihanContainer").innerHTML = html;

        document.querySelectorAll(".item-check").forEach((checkbox) => {
            checkbox.addEventListener("change", function() {
                const id = this.getAttribute("data-id");
                document.querySelectorAll(`.item-field[data-item="${id}"]`).forEach((field) => {
                    field.disabled = !this.checked;
                });
                updatePeriodeInfo();
            });
        });

        updatePeriodeInfo();
    }

    // ===== Flatpickr month-range picker: SATU picker untuk semua iuran bulanan =====
    let periodeGlobalPicker = null;

    function pad2(n) { return String(n).padStart(2, "0"); }
    function toPeriodeKey(d) { return d.getFullYear() + "-" + pad2(d.getMonth() + 1); }
    function periodeKeyToDate(key) {
        const m = /^(\d{4})-(\d{2})$/.exec(key || "");
        return m ? new Date(parseInt(m[1], 10), parseInt(m[2], 10) - 1, 1) : null;
    }
    function monthsBetween(a, b) {
        return (b.getFullYear() - a.getFullYear()) * 12 + (b.getMonth() - a.getMonth()) + 1;
    }
    function parseRupiah(str) {
        return parseInt(String(str || "").replace(/[^\d]/g, ""), 10) || 0;
    }

    function getPeriodeGlobalCount() {
        const awalEl = document.getElementById("periode_awal");
        const akhirEl = document.getElementById("periode_akhir");
        if (!awalEl) return 0;
        const awal = periodeKeyToDate(awalEl.value);
        const akhir = periodeKeyToDate(akhirEl.value) || awal;
        return awal ? monthsBetween(awal, akhir) : 0;
    }

    function updatePeriodeInfo() {
        const info = document.getElementById("periodeGlobalInfo");
        if (!info) return; // mode tahunan

        const n = getPeriodeGlobalCount();
        let grand = 0;
        document.querySelectorAll(".item-subtotal").forEach((el) => {
            const id = el.getAttribute("data-item");
            const cb = document.getElementById("check_" + id);
            const perBulan = parseRupiah((document.getElementById("jumlah_" + id) || {}).value);
            if (cb && cb.checked && n > 0 && perBulan > 0) {
                const sub = n * perBulan;
                grand += sub;
                el.innerHTML = `${n} bulan &times; ${formatRupiahJs(perBulan)} = <strong>${formatRupiahJs(sub)}</strong> per santri`;
            } else {
                el.textContent = "";
            }
        });

        if (n <= 0) { info.textContent = ""; return; }
        info.innerHTML = `<i class="fas fa-calendar-check mr-1"></i>${n} bulan dipilih` + (grand > 0 ? ` &nbsp;|&nbsp; Total per santri: <strong>${formatRupiahJs(grand)}</strong>` : "");
    }

    function initPeriodeGlobalPicker() {
        const input = document.getElementById("periodeGlobal");
        if (!input || periodeGlobalPicker) return;
        const hiddenAwal = document.getElementById("periode_awal");
        const hiddenAkhir = document.getElementById("periode_akhir");
        const defaultDate = new Date(new Date().getFullYear(), new Date().getMonth(), 1);

        periodeGlobalPicker = flatpickr(input, {
            mode: "range",
            locale: "id",
            disableMobile: true,
            defaultDate: [defaultDate, defaultDate],
            plugins: [new monthSelectPlugin({ shorthand: false, dateFormat: "F Y", altFormat: "F Y", theme: "light" })],
            onChange: function(selectedDates) {
                if (selectedDates.length === 0) {
                    hiddenAwal.value = ""; hiddenAkhir.value = "";
                } else if (selectedDates.length === 1) {
                    hiddenAwal.value = toPeriodeKey(selectedDates[0]);
                    hiddenAkhir.value = hiddenAwal.value;
                } else {
                    hiddenAwal.value = toPeriodeKey(selectedDates[0]);
                    hiddenAkhir.value = toPeriodeKey(selectedDates[1]);
                }
                updatePeriodeInfo();
            },
            onClose: function(selectedDates, dateStr, fp) {
                // Jika hanya 1 bulan diklik lalu ditutup, anggap rentang 1 bulan (bukan dikosongkan).
                if (selectedDates.length === 1) {
                    fp.setDate([selectedDates[0], selectedDates[0]], true);
                }
            }
        });

        // Set nilai awal (1 bulan = bulan berjalan)
        hiddenAwal.value = toPeriodeKey(defaultDate);
        hiddenAkhir.value = toPeriodeKey(defaultDate);
        updatePeriodeInfo();
    }

    document.addEventListener("DOMContentLoaded", function() {
        initPeriodeGlobalPicker();

        if (filterKelas && filterKelas !== "") {
            const kelasParam = (filterKelas === "all" || filterKelas === "0") ? "all" : parseInt(filterKelas || "0");
            if (kelasParam > 0 || kelasParam === "all") {
                fetch("../../api/get_kelas_iuran.php?id_kelas=" + kelasParam + "&mode=" + filterMode)
                    .then(response => response.json())
                    .then(data => {
                        if (data.status === "success" && Array.isArray(data.data)) {
                            renderTagihan(data.data, filterMode);
                        } else {
                            document.getElementById("tagihanContainer").innerHTML = `<div class="text-danger small">${data.message || "Gagal memuat tagihan."}</div>`;
                        }
                    })
                    .catch(() => {
                        document.getElementById("tagihanContainer").innerHTML = "<div class=\"text-danger small\">Gagal memuat informasi tagihan kelas.</div>";
                    });
            }
        }

        const checkAllSantri = document.getElementById("checkAllSantri");
        const santriChecks = document.querySelectorAll(".santri-check");
        
        if(checkAllSantri && santriChecks.length > 0) {
            checkAllSantri.addEventListener("change", function() {
                santriChecks.forEach(function(checkbox) {
                    checkbox.checked = checkAllSantri.checked;
                });
            });
            
            santriChecks.forEach(function(checkbox) {
                checkbox.addEventListener("change", function() {
                    checkAllSantri.checked = Array.from(santriChecks).every(c => c.checked);
                });
            });
        }
        
        const formBulk = document.getElementById("formBulkPayment");
        if(formBulk) {
            formBulk.addEventListener("submit", function(e) {
                const checkedSantri = document.querySelectorAll(".santri-check:checked").length;
                if (checkedSantri === 0) {
                    e.preventDefault();
                    toastr.warning("Silakan centang minimal 1 santri untuk diproses!", "Pilih Santri");
                    return;
                }

                const checkedItems = document.querySelectorAll(".item-check:checked");
                if (checkedItems.length === 0) {
                    e.preventDefault();
                    toastr.warning("Silakan centang minimal 1 tagihan/iuran di sebelah kiri!", "Pilih Tagihan");
                    return;
                }
                const awalGlobal = document.getElementById("periode_awal");
                if (awalGlobal && !awalGlobal.value) {
                    e.preventDefault();
                    toastr.warning("Pilih periode bulan terlebih dahulu.", "Periode Kosong");
                    return;
                }
            });
        }
    });
</script>
';

require_once '../../includes/footer.php';
?>
