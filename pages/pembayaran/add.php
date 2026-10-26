<?php
/**
 * Input Pembayaran (Bulanan/Tahunan)
 * Sistem Administrasi Keuangan Sekolah
 */

$page_title = 'Input Pembayaran';
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

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $id_santri = (int) ($_POST['id_santri'] ?? 0);
    $tgl_bayar = date('Y-m-d');
    $mode = strtolower(sanitize($_POST['mode'] ?? 'tahunan'));
    if ($mode !== 'bulanan' && $mode !== 'tahunan') {
        $mode = 'tahunan';
    }
    $metode_bayar = 'tunai';
    $keterangan = sanitize($_POST['keterangan'] ?? '');

    $pilih_item = $_POST['pilih_item'] ?? [];
    // Bulanan: SATU rentang periode (format YYYY-MM) dari flatpickr yang berlaku untuk semua iuran
    $periode_awal_global = trim((string) ($_POST['periode_awal'] ?? ''));
    $periode_akhir_global = trim((string) ($_POST['periode_akhir'] ?? ''));
    // Tahunan: tahun dibayar (per item)
    $tahun_dibayar = $_POST['tahun_dibayar'] ?? [];
    $jumlah_bayar = $_POST['jumlah_bayar'] ?? [];

    $periode_list_bulanan = [];
    if ($mode === 'bulanan') {
        if ($periode_awal_global === '') {
            $errors[] = 'Periode bulan harus dipilih.';
        } else {
            $periode_list_bulanan = expandPeriodeBulanan($periode_awal_global, $periode_akhir_global);
            if (empty($periode_list_bulanan)) {
                $errors[] = 'Rentang periode tidak valid (maksimal 36 bulan).';
            }
        }
    }


    if ($id_santri <= 0)
        $errors[] = 'Santri harus dipilih!';

    if (empty($pilih_item)) {
        $errors[] = 'Pilih minimal 1 tagihan untuk dibayar!';
    }

    $iuran_map = [];
    if (empty($errors)) {
        $hasPeriodeTipe = appColumnExists('iuran', 'periode_tipe');
        $periodeExpr = $hasPeriodeTipe ? "IFNULL(b.periode_tipe, 'bulanan')" : "'bulanan'";

        if (appTableExists('santri_iuran')) {
            $q = "SELECT b.id_iuran, b.nama_iuran, b.nominal, $periodeExpr as periode_tipe
                  FROM santri_iuran sb
                  JOIN iuran b ON sb.id_iuran = b.id_iuran
                  WHERE sb.id_santri = '$id_santri' AND sb.is_active = 1";
            $r = mysqli_query($conn, $q);
            if ($r) {
                while ($row = mysqli_fetch_assoc($r)) {
                    $iuran_map[(int) $row['id_iuran']] = $row;
                }
            }
        }

        // fallback legacy if relation table not available/empty
        if (empty($iuran_map)) {
            $q = "SELECT b.id_iuran, b.nama_iuran, b.nominal,
                         $periodeExpr as periode_tipe
                  FROM santri s
                  JOIN iuran b ON s.id_iuran = b.id_iuran
                  WHERE s.id_santri = '$id_santri'";
            $r = mysqli_query($conn, $q);
            while ($row = mysqli_fetch_assoc($r)) {
                $iuran_map[(int) $row['id_iuran']] = $row;
            }
        }
    }

    $detail_items = [];
    $grand_total = 0;

    if (empty($errors)) {
        foreach ($pilih_item as $id_iuran_raw => $val) {
            $id_iuran = (int) $id_iuran_raw;
            if ($id_iuran <= 0 || !isset($iuran_map[$id_iuran])) {
                $errors[] = 'Tagihan tidak valid.';
                continue;
            }

            $iuran = $iuran_map[$id_iuran];
            $periode_tipe = $iuran['periode_tipe'] === 'tahunan' ? 'tahunan' : 'bulanan';
            if ($periode_tipe !== $mode) {
                $errors[] = 'Tagihan ' . $iuran['nama_iuran'] . ' tidak sesuai mode pembayaran saat ini.';
                continue;
            }
            $jumlah_raw = str_replace(['.', ','], '', (string) ($jumlah_bayar[$id_iuran] ?? '0'));
            $jumlah = (float) $jumlah_raw;

            if ($jumlah <= 0) {
                $errors[] = 'Jumlah bayar untuk ' . $iuran['nama_iuran'] . ' harus lebih dari 0.';
                continue;
            }

            // Build list of periods to process: [['bulan' => ..., 'tahun' => ..., 'periode_key' => ...], ...]
            $periode_list_process = [];
            if ($mode === 'bulanan') {
                $periode_list_process = $periode_list_bulanan;
            } else {
                $tahun = preg_replace('/\D/', '', (string) ($tahun_dibayar[$id_iuran] ?? ''));
                if (empty($tahun)) {
                    $errors[] = 'Tahun dibayar untuk ' . $iuran['nama_iuran'] . ' harus diisi.';
                    continue;
                }
                $periode_list_process[] = ['bulan' => '-', 'tahun' => $tahun, 'periode_key' => buildPeriodeKey('tahunan', $tahun)];
            }

            foreach ($periode_list_process as $periode) {
                $bln = $periode['bulan'];
                $tahun = $periode['tahun'];
                $periode_key = $periode['periode_key'];
                if (empty($periode_key)) {
                    $errors[] = 'Periode pembayaran untuk ' . $iuran['nama_iuran'] . ' tidak valid.';
                    continue;
                }

                $nominal = (float) $iuran['nominal'];
                if ($periode_tipe === 'bulanan') {
                    $paidState = getSantriIuranBulananPaidState($id_santri, $id_iuran, $nominal, $periode_key);
                    $sudah_bayar = (float) $paidState['sudah_bayar'];
                    if (!empty($paidState['ikut_lunas'])) {
                        $errors[] = 'Periode ' . $bln . ' ' . $tahun . ' untuk ' . $iuran['nama_iuran'] . ' sudah ikut lunas karena periode ' . $paidState['lunas_oleh_label'] . ' sudah dibayar lunas.';
                        continue;
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
                    $nama_bln = $bln !== '-' ? " ($bln $tahun)" : " ($tahun)";
                    $errors[] = 'Pembayaran ' . $iuran['nama_iuran'] . $nama_bln . ' melebihi nominal periode. Sisa maksimal: ' . formatRupiah(max($nominal - $sudah_bayar, 0)) . '.';
                    continue;
                }

                $detail_items[] = [
                    'id_iuran' => $id_iuran,
                    'nama_iuran' => $iuran['nama_iuran'],
                    'periode_tipe' => $periode_tipe,
                    'bulan_dibayar' => $bln,
                    'tahun_dibayar' => $tahun,
                    'periode_key' => $periode_key,
                    'jumlah_bayar' => $jumlah,
                    'keterangan' => $keterangan,
                ];

                $grand_total += $jumlah;
            }
        }
    }

    if (empty($errors) && !empty($detail_items)) {
        mysqli_begin_transaction($conn);
        try {

            $last_insert_id = 0;
            foreach ($detail_items as $item) {
                $insert_detail = "INSERT INTO pembayaran (id_santri, tgl_bayar, periode_tipe, periode_key, bulan_dibayar, tahun_dibayar, id_iuran, jumlah_bayar, metode_bayar, keterangan)
                                  VALUES ('$id_santri', '$tgl_bayar', '" . $item['periode_tipe'] . "', '" . mysqli_real_escape_string($conn, $item['periode_key']) . "', '" . mysqli_real_escape_string($conn, $item['bulan_dibayar']) . "', '" . mysqli_real_escape_string($conn, $item['tahun_dibayar']) . "', '" . $item['id_iuran'] . "', '" . $item['jumlah_bayar'] . "', '$metode_bayar', '" . mysqli_real_escape_string($conn, $item['keterangan']) . "')";
                if (!mysqli_query($conn, $insert_detail)) {
                    throw new Exception('Gagal menyimpan item pembayaran.');
                }
                $last_insert_id = mysqli_insert_id($conn);
            }

            if (!mysqli_commit($conn)) {
                throw new Exception('Gagal menyelesaikan transaksi pembayaran.');
            }

            logActivity('Input pembayaran', 'pembayaran', $last_insert_id);
            $santri_info = mysqli_fetch_assoc(mysqli_query($conn, "
                SELECT s.nama, k.nama_kelas
                FROM santri s
                JOIN kelas k ON s.id_kelas = k.id_kelas
                WHERE s.id_santri = '$id_santri'
            "));
            setFlash('success', 'Pembayaran iuran berhasil disimpan. Total: ' . formatRupiah($grand_total)
                . '. Santri: ' . ($santri_info['nama'] ?? '-') . ' (' . ($santri_info['nama_kelas'] ?? '-')
                . '). Jumlah item: ' . count($detail_items) . '. Bukti pembayaran dapat dicetak di riwayat pembayaran.');
            header('Location: add.php', true, 303);
            exit;
        } catch (Exception $e) {
            mysqli_rollback($conn);
            $errors[] = 'Gagal menyimpan pembayaran: ' . $e->getMessage();
        }
    }
}

// Get classes
$kelas_list = mysqli_query($conn, "SELECT id_kelas, nama_kelas FROM kelas ORDER BY nama_kelas");

// Get students
$siswa_list = mysqli_query($conn, "
    SELECT s.id_santri, s.nama, s.id_kelas, k.nama_kelas
    FROM santri s
    JOIN kelas k ON s.id_kelas = k.id_kelas
    WHERE s.status = 'active'
    ORDER BY k.nama_kelas, s.nama
");

// Pre-select student if passed via GET
$selected_id = isset($_GET['id_santri']) ? sanitize($_GET['id_santri']) : '';
$selected_kelas = isset($_GET['id_kelas']) ? (int) $_GET['id_kelas'] : 0;

$bulan_list = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];

?>

<!-- Page Header (SB Admin Pro style) -->
<header class="page-header page-header-compact page-header-light border-bottom bg-white mb-4">
    <div class="container-xl px-4">
        <div class="page-header-content">
            <div class="row align-items-center justify-content-between pt-3">
                <div class="col-auto mb-3">
                    <h1 class="page-header-title">
                        <div class="page-header-icon"><i class="fas fa-plus-circle"></i></div>
                        Input Pembayaran
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

    <div class="row">
        <div class="col-lg-8">
            <div class="card shadow mb-4">
                <div class="card-header py-3">
                    <h6 class="m-0 font-weight-bold text-primary">Form Pembayaran</h6>
                </div>
                <div class="card-body">
                    <form method="POST" action="" id="formPembayaranBatch">
                        <div class="form-group">
                            <label>Mode Pembayaran <span class="text-danger">*</span></label>
                            <select name="mode" id="modeSelect" class="form-control" required>
                                <option value="tahunan">Tahunan</option>
                                <option value="bulanan">Bulanan</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Pilih Kelas</label>
                            <select id="kelasSelect" class="form-control">
                                <option value="">-- Semua Kelas --</option>
                                <?php while ($kls = mysqli_fetch_assoc($kelas_list)): ?>
                                    <option value="<?php echo $kls['id_kelas']; ?>" <?php echo $selected_kelas == $kls['id_kelas'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($kls['nama_kelas']); ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Pilih Santri <span class="text-danger">*</span></label>
                            <select name="id_santri" id="siswaSelect" class="form-control" required>
                                <option value="">-- Pilih Santri --</option>
                                <?php while ($siswa = mysqli_fetch_assoc($siswa_list)): ?>
                                    <option value="<?php echo $siswa['id_santri']; ?>"
                                        data-kelas="<?php echo htmlspecialchars($siswa['nama_kelas']); ?>"
                                        data-id-kelas="<?php echo $siswa['id_kelas']; ?>" <?php echo $selected_id == $siswa['id_santri'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($siswa['nama']); ?> -
                                        <?php echo htmlspecialchars($siswa['nama_kelas']); ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>

                        <div class="form-group" id="periodeGlobalGroup" style="display:none;">
                            <label>Periode Bulan <span class="text-danger">*</span> <small class="text-muted font-weight-normal">(berlaku untuk semua iuran bulanan yang dicentang)</small></label>
                            <input type="text" class="form-control periode-picker" id="periodeGlobal" placeholder="Klik untuk pilih bulan / rentang bulan..." readonly>
                            <input type="hidden" name="periode_awal" id="periode_awal" value="">
                            <input type="hidden" name="periode_akhir" id="periode_akhir" value="">
                            <small class="form-text text-muted periode-info" id="periodeGlobalInfo"></small>
                        </div>

                        <div class="form-group">
                            <label>Tagihan Aktif <span class="text-danger">*</span></label>
                            <div id="tagihanContainer" class="border rounded p-3 bg-light">
                                <div class="text-muted">Pilih santri terlebih dahulu untuk menampilkan tagihan.</div>
                            </div>
                        </div>



                        <div class="form-group">
                            <label>Keterangan</label>
                            <textarea name="keterangan" class="form-control" rows="2" placeholder="Opsional"></textarea>
                        </div>

                        <hr>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Simpan Pembayaran
                        </button>
                        <button type="reset" class="btn btn-secondary">
                            <i class="fas fa-undo"></i> Reset
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card shadow mb-4">
                <div class="card-header py-3">
                    <h6 class="m-0 font-weight-bold text-primary">Info Santri</h6>
                </div>
                <div class="card-body" id="infoSiswa">
                    <p class="text-muted text-center">Pilih santri untuk melihat informasi</p>
                </div>
            </div>

            <div class="card shadow mb-4">
                <div class="card-header py-3">
                    <h6 class="m-0 font-weight-bold text-primary">Statistik Hari Ini</h6>
                </div>
                <div class="card-body">
                    <?php
                    $todayCountQuery = "SELECT COUNT(*) as jumlah, COALESCE(SUM(jumlah_bayar), 0) as total FROM pembayaran WHERE DATE(tgl_bayar) = CURDATE()";
                    $today = mysqli_fetch_assoc(mysqli_query($conn, $todayCountQuery));
                    ?>
                    <p class="mb-1"><strong>Transaksi:</strong> <?php echo $today['jumlah']; ?></p>
                    <p class="mb-0"><strong>Total:</strong> <?php echo formatRupiah($today['total']); ?></p>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
$extra_js = '
<script src="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/l10n/id.js"></script>
<script src="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/plugins/monthSelect/index.js"></script>
<script>
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
        const msg = mode === "bulanan" ? "Tagihan bulanan aktif tidak ditemukan." : "Tagihan tahunan aktif tidak ditemukan.";
        document.getElementById("tagihanContainer").innerHTML = `<div class="text-danger">${msg}</div>`;
        return;
    }

    const currentYear = new Date().getFullYear();
    const isBulanan = mode === "bulanan";

    const html = items.map((item) => {
        const disabledAttr = "disabled";
        const defaultAmount = Math.max(item.sisa_tagihan || 0, 0);
        const statusInfo = item.ikut_lunas
            ? `<span class="text-success font-weight-bold">Ikut lunas (sudah bayar ${item.lunas_oleh_label})</span>`
            : `Sudah bayar: ${formatRupiahJs(item.sudah_bayar)}`;

        return `
            <div class="card mb-3 border-left-primary">
                <div class="card-body py-3">
                    <div class="custom-control custom-checkbox mb-2">
                        <input type="checkbox" class="custom-control-input item-check" id="check_${item.id_iuran}" name="pilih_item[${item.id_iuran}]" value="1" data-id="${item.id_iuran}" ${item.sisa_tagihan <= 0 ? "disabled" : ""}>
                        <label class="custom-control-label font-weight-bold" for="check_${item.id_iuran}">
                            ${item.nama_iuran} (${isBulanan ? "Bulanan" : "Tahunan"})
                            ${item.sisa_tagihan <= 0 ? `<span class="badge bg-success text-white ms-1">Lunas</span>` : ""}
                        </label>
                    </div>
                    <div class="small text-muted mb-2">Nominal tagihan: ${formatRupiahJs(item.nominal)} | ${statusInfo} | Sisa: ${formatRupiahJs(item.sisa_tagihan)}</div>

                    <input type="hidden" name="periode_tipe[${item.id_iuran}]" value="${item.periode_tipe}">

                    <div class="form-row">
                        ${isBulanan ? "" : `
                        <div class="col-md-4 mb-2">
                            <label class="small mb-1">Tahun</label>
                            <input type="number" class="form-control form-control-sm item-field" name="tahun_dibayar[${item.id_iuran}]" value="${item.default_tahun || currentYear}" min="2020" max="2100" data-item="${item.id_iuran}" ${disabledAttr}>
                        </div>`}

                        <div class="${isBulanan ? "col-md-6" : "col-md-8"} mb-2">
                            <label class="small mb-1">${isBulanan ? "Jumlah per Bulan" : "Jumlah"}</label>
                            <div class="input-group input-group-sm">
                                <div class="input-group-prepend"><span class="input-group-text">Rp</span></div>
                                <input type="text" class="form-control item-field jumlah-field" name="jumlah_bayar[${item.id_iuran}]" id="jumlah_${item.id_iuran}" value="${new Intl.NumberFormat("id-ID").format(defaultAmount)}" data-item="${item.id_iuran}" onkeyup="formatCurrency(this); updatePeriodeInfo();" ${disabledAttr}>
                            </div>
                        </div>
                        ${isBulanan ? `
                        <div class="col-md-6 mb-2 d-flex align-items-end">
                            <small class="text-muted item-subtotal" data-item="${item.id_iuran}"></small>
                        </div>` : ""}
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
                if (this.checked && (field.name || "").includes("jumlah_bayar")) {
                    field.focus();
                }
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
    const awal = periodeKeyToDate(document.getElementById("periode_awal").value);
    const akhir = periodeKeyToDate(document.getElementById("periode_akhir").value) || awal;
    return awal ? monthsBetween(awal, akhir) : 0;
}

function updatePeriodeInfo() {
    const isBulanan = (document.getElementById("modeSelect").value || "tahunan") === "bulanan";
    const info = document.getElementById("periodeGlobalInfo");
    if (!isBulanan) { if (info) info.textContent = ""; return; }

    const n = getPeriodeGlobalCount();
    let grand = 0;
    document.querySelectorAll(".item-subtotal").forEach((el) => {
        const id = el.getAttribute("data-item");
        const cb = document.getElementById("check_" + id);
        const perBulan = parseRupiah((document.getElementById("jumlah_" + id) || {}).value);
        if (cb && cb.checked && n > 0 && perBulan > 0) {
            const sub = n * perBulan;
            grand += sub;
            el.innerHTML = `${n} bulan &times; ${formatRupiahJs(perBulan)} = <strong>${formatRupiahJs(sub)}</strong>`;
        } else {
            el.textContent = "";
        }
    });

    if (!info) return;
    if (n <= 0) { info.textContent = ""; return; }
    info.innerHTML = `<i class="fas fa-calendar-check mr-1"></i>${n} bulan dipilih` + (grand > 0 ? ` &nbsp;|&nbsp; Total semua iuran tercentang: <strong>${formatRupiahJs(grand)}</strong>` : "");
}

function initPeriodeGlobalPicker() {
    const input = document.getElementById("periodeGlobal");
    const hiddenAwal = document.getElementById("periode_awal");
    const hiddenAkhir = document.getElementById("periode_akhir");
    const defaultDate = new Date(new Date().getFullYear(), new Date().getMonth(), 1);

    if (periodeGlobalPicker) { return; }

    periodeGlobalPicker = flatpickr(input, {
        mode: "range",
        locale: "id",
        disableMobile: true,
        defaultDate: [defaultDate, defaultDate],
        plugins: [new monthSelectPlugin({ shorthand: false, dateFormat: "F Y", altFormat: "F Y", theme: "light" })],
        onChange: function(selectedDates) {
            const prevAwal = hiddenAwal.value;
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
            if (hiddenAwal.value && hiddenAwal.value !== prevAwal && document.getElementById("siswaSelect").value) {
                loadTagihan();
            }
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

function togglePeriodeGlobal(mode) {
    const group = document.getElementById("periodeGlobalGroup");
    const isBulanan = mode === "bulanan";
    group.style.display = isBulanan ? "" : "none";
    if (isBulanan) {
        initPeriodeGlobalPicker();
    } else {
        // Kosongkan agar tidak ikut terkirim saat mode tahunan
        document.getElementById("periode_awal").value = "";
        document.getElementById("periode_akhir").value = "";
    }
    updatePeriodeInfo();
}

function loadTagihan() {
    const siswaSelect = document.getElementById("siswaSelect");
    const mode = document.getElementById("modeSelect").value || "tahunan";
    togglePeriodeGlobal(mode);
    const id_santri = siswaSelect.value;
    const option = siswaSelect.options[siswaSelect.selectedIndex];
    const kelas = option ? option.getAttribute("data-kelas") : "";
    const nama = option ? (option.text.split(" - ")[0] || "") : "";

    if (!id_santri) {
        document.getElementById("infoSiswa").innerHTML = "<p class=\"text-muted text-center\">Pilih santri untuk melihat informasi</p>";
        document.getElementById("tagihanContainer").innerHTML = "<div class=\"text-muted\">Pilih santri terlebih dahulu untuk menampilkan tagihan.</div>";
        return;
    }

    document.getElementById("infoSiswa").innerHTML = `<p><strong>Nama:</strong> ${nama}</p><p><strong>Kelas:</strong> ${kelas || "-"}</p>`;

    const pAwal = document.getElementById("periode_awal") ? document.getElementById("periode_awal").value : "";
    const pAkhir = document.getElementById("periode_akhir") ? document.getElementById("periode_akhir").value : "";
    const extraParams = (mode === "bulanan" && pAwal)
        ? "&periode_awal=" + encodeURIComponent(pAwal) + "&periode_akhir=" + encodeURIComponent(pAkhir || pAwal)
        : "";

    fetch("../../api/get_santri_iuran.php?id=" + encodeURIComponent(id_santri) + "&mode=" + encodeURIComponent(mode) + extraParams)
        .then(response => response.json())
        .then(data => {
            if (data.status === "success" && Array.isArray(data.data)) {
                renderTagihan(data.data, mode);
            } else {
                document.getElementById("tagihanContainer").innerHTML = `<div class="text-danger">${data.message || "Gagal memuat tagihan."}</div>`;
            }
        })
        .catch(() => {
            document.getElementById("tagihanContainer").innerHTML = "<div class=\"text-danger\">Gagal memuat informasi tagihan.</div>";
        });
}

function filterSantriByKelas() {
    const kelasSelect = document.getElementById("kelasSelect");
    const siswaSelect = document.getElementById("siswaSelect");
    const idKelas = kelasSelect.value;
    let currentStillVisible = false;

    Array.from(siswaSelect.options).forEach((opt) => {
        if (!opt.value) return;
        const match = !idKelas || opt.getAttribute("data-id-kelas") === idKelas;
        opt.hidden = !match;
        opt.disabled = !match;
        if (match && opt.value === siswaSelect.value) {
            currentStillVisible = true;
        }
    });

    if (!currentStillVisible) {
        siswaSelect.value = "";
        loadTagihan();
    }
}

document.getElementById("kelasSelect").addEventListener("change", filterSantriByKelas);
document.getElementById("siswaSelect").addEventListener("change", loadTagihan);
document.getElementById("modeSelect").addEventListener("change", loadTagihan);

document.getElementById("formPembayaranBatch").addEventListener("submit", function(e) {
    const checkedItems = document.querySelectorAll(".item-check:checked");
    if (checkedItems.length === 0) {
        e.preventDefault();
        toastr.warning("Pilih minimal 1 item tagihan yang akan dibayar.", "Pilih Tagihan");
        return;
    }
    // Validasi periode untuk mode bulanan
    const mode = document.getElementById("modeSelect").value || "tahunan";
    if (mode === "bulanan" && !document.getElementById("periode_awal").value) {
        e.preventDefault();
        toastr.warning("Pilih periode bulan terlebih dahulu.", "Periode Kosong");
        return;
    }
});

filterSantriByKelas();
togglePeriodeGlobal(document.getElementById("modeSelect").value || "tahunan");

if (document.getElementById("siswaSelect").value) {
    loadTagihan();
}
</script>
';

require_once '../../includes/footer.php';
?>
