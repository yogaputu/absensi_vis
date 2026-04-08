<?php
require_once 'SimpleXLSX.php'; 
require_once 'SimpleXLS.php';  

use Shuchkin\SimpleXLSX;
use Shuchkin\SimpleXLS;

// Konfigurasi Database
$host = "localhost";
$user = "root";
$pass = "";
$db   = "absensi_vistri";
$conn = mysqli_connect($host, $user, $pass, $db);

if (!$conn) { die("Koneksi gagal: " . mysqli_connect_error()); }

// --- 1. LOGIKA FILTER BULAN & TAHUN ---
$bulan_pilih = isset($_GET['m']) ? $_GET['m'] : date('m');
$tahun_pilih = isset($_GET['y']) ? $_GET['y'] : date('Y');

// --- 2. FUNGSI EKSTRAK DEPARTEMEN DARI NAMA FILE ---
function getDeptFromFileName($filename) {
    // Ubah nama file menjadi uppercase untuk mempermudah pencarian
    $filename = strtoupper($filename);
    $parts = explode(" ", $filename);
    
    foreach ($parts as $key => $part) {
        if (trim($part) == "KK" && isset($parts[$key + 1])) {
            // Mengambil kata setelah KK (MANGU atau CEPOGO)
            return mysqli_real_escape_string($GLOBALS['conn'], trim($parts[$key + 1]));
        }
    }
    return "Umum";
}

// --- 3. FUNGSI AMBIL HARI LIBUR ---
function getHariLibur($bulan, $tahun) {
    $url = "https://day-off-api.vercel.app/api?month=$bulan&year=$tahun";
    $data = @file_get_contents($url);
    $libur = [];
    if ($data) {
        $array = json_decode($data, true);
        if (is_array($array)) {
            foreach ($array as $row) { $libur[$row['tanggal']] = $row['keterangan']; }
        }
    }
    return $libur;
}

// --- 4. FUNGSI SIMPAN DATA STANDAR (SHEET 1) ---
function simpanDataStandar($conn, $rows, $dept) {
    foreach ($rows as $index => $row) {
        if ($index == 0 || empty($row[1])) continue;
        $nama  = mysqli_real_escape_string($conn, $row[1]);
        $pin   = mysqli_real_escape_string($conn, $row[2]);
        $raw_t = $row[3];

        if (is_numeric($raw_t)) {
            // Jika formatnya angka Serial Excel (misal: 46025.3276)
            $waktu = date("Y-m-d H:i:s", ($raw_t - 25569) * 86400);
        } else {
            // Jika formatnya teks "1/7/2026 7:51:45 AM"
            $raw_t = trim($raw_t);
            
            // Gunakan DateTime::createFromFormat untuk memaksa pembacaan M/j/Y (Bulan/Hari/Tahun)
            // 'n' = Bulan (1-12), 'j' = Hari (1-31), 'Y' = Tahun 4 digit, 'g:i:s A' = Jam:Menit:Detik AM/PM
            $dateObj = DateTime::createFromFormat('n/j/Y g:i:s A', $raw_t);

            if ($dateObj) {
                // Konversi ke format standar Database (Y-m-d H:i:s) -> 2026-01-07 07:51:45
                $waktu = $dateObj->format('Y-m-d H:i:s');
            } else {
                // Coba format tanpa detik jika gagal (n/j/Y g:i A)
                $dateObjFallback = DateTime::createFromFormat('n/j/Y g:i A', $raw_t);
                $waktu = ($dateObjFallback) ? $dateObjFallback->format('Y-m-d H:i:s') : date('Y-m-d H:i:s', strtotime(str_replace('/', '-', $raw_t)));
            }
        }
        mysqli_query($conn, "INSERT INTO presensi (department, nama, no_pin, waktu) VALUES ('$dept', '$nama', '$pin', '$waktu')");
    }
}

// --- 5. FUNGSI SIMPAN DATA MATRIKS (SHEET LOG) ---
function importFormatMatriks($conn, $rows, $bulan, $tahun, $dept) {
    // 1. Hapus data lama sesuai departemen agar tidak duplikat
    mysqli_query($conn, "DELETE FROM presensi WHERE MONTH(waktu)='$bulan' AND YEAR(waktu)='$tahun' AND department='$dept'");
    
    $jumlah_hari = cal_days_in_month(CAL_GREGORIAN, $bulan, $tahun);

    for ($i = 4; $i < count($rows); $i += 2) {
        $rowIdentitas = $rows[$i];
        $rowJam = isset($rows[$i + 1]) ? $rows[$i + 1] : null;

        // --- SOLUSI PIN TERPOTONG ---
        // Kita ambil raw data, hapus karakter biner (\x00-\x1F), lalu ambil angkanya saja
        $raw_pin = isset($rowIdentitas[2]) ? (string)$rowIdentitas[2] : "";
        $clean_step1 = preg_replace('/[\x00-\x1F\x7F]/', '', $raw_pin); // Hapus karakter kontrol biner
        $current_pin = preg_replace('/[^0-9]/', '', $clean_step1);    // Hanya sisakan angka murni

        // Ambil Nama dan bersihkan karakter kotak-kotak
        $raw_nama = isset($rowIdentitas[10]) ? (string)$rowIdentitas[10] : "";
        $current_nama = preg_replace('/[\x00-\x1F\x7F-\xFF]/', '', $raw_nama);
        $current_nama = trim($current_nama);

        if ($current_pin === "") continue;

        for ($d = 1; $d <= $jumlah_hari; $d++) {
            $col_idx = $d - 1; 
            $tgl_full = "$tahun-$bulan-" . sprintf("%02d", $d);
            $isi_sel = isset($rowJam[$col_idx]) ? (string)$rowJam[$col_idx] : "";

            if (trim($isi_sel) !== "") {
                // Pecah berdasarkan Enter agar jam pulang ikut ter-input
                $jam_data = preg_split('/[\r\n\s]+/', trim($isi_sel));
                foreach ($jam_data as $jam) {
                    $jam_clean = trim(preg_replace('/[^0-9:]/', '', $jam)); 
                    if (empty($jam_clean)) continue;

                    // Format waktu: YYYY-MM-DD HH:II:00
                    $waktu_db = $tgl_full . " " . $jam_clean . (strlen($jam_clean) <= 5 ? ":00" : "");

                    $n_safe = mysqli_real_escape_string($conn, $current_nama);
                    $p_safe = mysqli_real_escape_string($conn, $current_pin);
                    
                    mysqli_query($conn, "INSERT INTO presensi (department, nama, no_pin, waktu) 
                                       VALUES ('$dept', '$n_safe', '$p_safe', '$waktu_db')");
                }
            } else {
                // Input dummy agar nama tidak hilang dari list rekap
                $n_safe = mysqli_real_escape_string($conn, $current_nama);
                $p_safe = mysqli_real_escape_string($conn, $current_pin);
                mysqli_query($conn, "INSERT INTO presensi (department, nama, no_pin, waktu) 
                                   VALUES ('$dept', '$n_safe', '$p_safe', '$tgl_full 00:00:00')");
            }
        }
    }
}

// --- 6. LOGIKA TOMBOL ---
if (isset($_POST['upload_umum'])) {
    $filename = $_FILES['file_umum']['name'];
    $dept_auto = getDeptFromFileName($filename);
    $ext = pathinfo($filename, PATHINFO_EXTENSION);
    if ($ext == 'xlsx') { $xlsx = SimpleXLSX::parse($_FILES['file_umum']['tmp_name']); if ($xlsx) simpanDataStandar($conn, $xlsx->rows(0), $dept_auto); }
    else { $xls = SimpleXLS::parse($_FILES['file_umum']['tmp_name']); if ($xls) simpanDataStandar($conn, $xls->rows(0), $dept_auto); }
    header("Location: index.php?status=success&m=$bulan_pilih&y=$tahun_pilih"); exit;
}

if (isset($_POST['upload_log'])) {
    $filename = $_FILES['file_log']['name'];
    $dept_auto = getDeptFromFileName($filename);
    $ext = pathinfo($filename, PATHINFO_EXTENSION);
    $target_rows = null;
    if ($ext == 'xlsx') {
        $xlsx = SimpleXLSX::parse($_FILES['file_log']['tmp_name']);
        if ($xlsx) { foreach ($xlsx->sheetNames() as $idx => $name) { if (strtolower(trim($name)) == 'log') { $target_rows = $xlsx->rows($idx); break; } } }
    } else {
        $xls = SimpleXLS::parse($_FILES['file_log']['tmp_name']);
        if ($xls) { foreach ($xls->sheetNames() as $idx => $name) { if (strtolower(trim($name)) == 'log') { $target_rows = $xls->rows($idx); break; } } }
    }
    
    if ($target_rows) { 
        // Mengambil teks dari sel C3: "01/01/2026 ~ 31/01"
        $periode_raw = isset($target_rows[2][2]) ? $target_rows[2][2] : ""; 
        
        // Regex diperkuat untuk mencari pola tanggal pertama (awal periode)
        if (preg_match('/(\d{2})\/(\d{2})\/(\d{4})/', $periode_raw, $matches)) {
            $bulan_auto = $matches[2]; // Pasti mengambil "01" (Januari)
            $tahun_auto = $matches[3]; // Pasti mengambil "2026"
        } else {
            $bulan_auto = $bulan_pilih;
            $tahun_auto = $tahun_pilih;
        }

        // Lanjut ke proses import
        importFormatMatriks($conn, $target_rows, $bulan_auto, $tahun_auto, $dept_auto); 
        header("Location: index.php?status=success_log&m=$bulan_auto&y=$tahun_auto"); 
        exit;
    }
}

if (isset($_POST['update_keterangan'])) {
    $pin = $_POST['pin_k'];
    $tgl_awal = $_POST['tgl_k'];
    $tgl_akhir = $_POST['tgl_akhir_k'];
    $ket = $_POST['keterangan'];
    $dept = $_POST['dept_k'];

    // Jika Keterangan Kosong, berarti ingin menghapus data (Hadir Kembali)
    if (empty($ket)) {
        mysqli_query($conn, "DELETE FROM presensi WHERE no_pin='$pin' AND DATE(waktu)='$tgl_awal' AND department='$dept'");
    } else {
        // Jika Tanggal Akhir diisi (Cuti Melahirkan), lakukan pengisian massal
        if (!empty($tgl_akhir) && $ket == "CUTI MELAHIRKAN") {
            $begin = new DateTime($tgl_awal);
            $end = new DateTime($tgl_akhir);
            $end = $end->modify('+1 day'); // Agar tanggal akhir ikut terhitung

            $interval = new DateInterval('P1D');
            $daterange = new DatePeriod($begin, $interval ,$end);

            foreach($daterange as $date){
                $tgl_proses = $date->format("Y-m-d");
                
                // Cek apakah sudah ada datanya? Kalau ada update, kalau tidak ada insert.
                $cek = mysqli_query($conn, "SELECT id FROM presensi WHERE no_pin='$pin' AND DATE(waktu)='$tgl_proses' AND department='$dept'");
                if (mysqli_num_rows($cek) > 0) {
                    mysqli_query($conn, "UPDATE presensi SET keterangan='$ket' WHERE no_pin='$pin' AND DATE(waktu)='$tgl_proses' AND department='$dept'");
                } else {
                    mysqli_query($conn, "INSERT INTO presensi (no_pin, department, waktu, keterangan) VALUES ('$pin', '$dept', '$tgl_proses 00:00:00', '$ket')");
                }
            }
        } else {
            // Pengisian normal untuk satu hari
            $cek = mysqli_query($conn, "SELECT id FROM presensi WHERE no_pin='$pin' AND DATE(waktu)='$tgl_awal' AND department='$dept'");
            if (mysqli_num_rows($cek) > 0) {
                mysqli_query($conn, "UPDATE presensi SET keterangan='$ket' WHERE no_pin='$pin' AND DATE(waktu)='$tgl_awal' AND department='$dept'");
            } else {
                mysqli_query($conn, "INSERT INTO presensi (no_pin, department, waktu, keterangan) VALUES ('$pin', '$dept', '$tgl_awal 00:00:00', '$ket')");
            }
        }
    }
    header("Location: index.php?status=updated&m=$bulan_pilih&y=$tahun_pilih"); exit;
}


// --- 7. DATA UNTUK TABEL ---
$jumlah_hari = cal_days_in_month(CAL_GREGORIAN, $bulan_pilih, $tahun_pilih);
$daftar_libur = getHariLibur($bulan_pilih, $tahun_pilih);
$res_karyawan = mysqli_query($conn, "SELECT DISTINCT nama, no_pin, department FROM presensi WHERE nama IS NOT NULL AND nama != '' AND MONTH(waktu)='$bulan_pilih' AND YEAR(waktu)='$tahun_pilih' ORDER BY nama ASC");

$nama_bulan = ["01"=>"Januari","02"=>"Februari","03"=>"Maret","04"=>"April","05"=>"Mei","06"=>"Juni","07"=>"Juli","08"=>"Agustus","09"=>"September","10"=>"Oktober","11"=>"November","12"=>"Desember"];
?>

<!DOCTYPE html>
<html>
<head>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rekap Absensi Vistri</title>
    <style>
        body { font-family: Arial, sans-serif; font-size: 11px; margin: 20px; }
        .box-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 20px; }
        .box-upload { display: flex; gap: 20px; padding: 15px; background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 8px; }
        .box-filter { padding: 15px; background: #e9ecef; border: 1px solid #ced4da; border-radius: 8px; }
        table { border-collapse: collapse; width: 100%; box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
        th, td { border: 1px solid #333; padding: 5px; text-align: center; }
        .header-rekap { background: #343a40; color: white; font-weight: bold; }
        .libur-merah { background: #ffcccc !important; color: red; }
        .terlambat { background: #ffa500 !important; font-weight: bold; }
        .hanya-satu-scan { background: #00008B !important; color: white !important; }
        .total-kolom { background: #ffeb3b; font-weight: bold; color: black; }
        .jam-masuk { color: blue; display: block; border-bottom: 1px solid #eee; font-weight: bold; }
        .jam-pulang { color: red; display: block; }
        .keterangan {font-weight: bold; font-size: 10px; }
        #modalKet { display:none; position:fixed; top:50%; left:50%; transform:translate(-50%, -50%); background:white; padding:20px; border:2px solid #333; z-index:100; border-radius:10px; width: 300px; }
        #overlay { display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.6); z-index:99; }
        select, button { cursor: pointer; padding: 5px; }
        /* Warna berdasarkan kategori sesuai permintaan */
        .bg-cuti { background-color: #808080 !important; color: #ffffff !important; }      /* Abu-abu */
        .bg-cuti-melhairkan { background-color: #808080 !important; color: white; } 
        .bg-ijin { background-color: #ffff00 !important; color: black; }      /* Kuning */
        .bg-sakit { background-color: #800080 !important; color: white; }     /* Ungu */
        .bg-alpa { background-color: #a52a2a !important; color: white; }      /* Coklat (Tanpa Keterangan) */
        .bg-dinas { background-color: #ffc0cb !important; color: black; }     /* Pink */
        .bg-no-scan { background-color: #006400 !important; color: white; }   /* Hijau Tua (Tidak Absen) */


    /* PEMBUNGKUS TABEL RESPONSIVE */
    .table-container {
        width: 100%;
        overflow-x: auto; /* Membuat tabel bisa di-scroll ke kanan di HP */
        -webkit-overflow-scrolling: touch;
        margin-bottom: 20px;
        border: 1px solid #ddd;
    }

    /* PENYESUAIAN LAYOUT ATAS (UPLOAD & FILTER) */
    .box-header { 
        display: flex; 
        flex-direction: row; /* Default Desktop */
        justify-content: space-between; 
        align-items: flex-start; 
        margin-bottom: 20px; 
        gap: 15px;
    }

    /* RESPONSIVE BREAKPOINT (HP/TABLET) */
    @media screen and (max-width: 768px) {
        .box-header {
            flex-direction: column; /* Tumpuk ke bawah di HP */
        }
        
        .box-upload, .box-filter {
            width: 100%; /* Lebar penuh di HP */
            box-sizing: border-box;
        }

        .box-upload {
            flex-direction: column; /* Form upload tumpuk vertikal */
        }

        .box-upload div {
            border-left: none !important;
            border-top: 1px solid #ccc;
            padding-left: 0 !important;
            padding-top: 15px;
            margin-top: 10px;
        }

        h2 { font-size: 16px; }
    }

    /* Perbaikan Legenda agar rapi di HP */
    .legenda-container {
        display: flex; 
        gap: 10px; 
        flex-wrap: wrap; 
        font-weight: bold;
    }

    .legenda-item {
        padding: 5px 10px; 
        border-radius: 4px;
        font-size: 10px;
        flex: 1 1 auto; /* Ukuran fleksibel */
        text-align: center;
    }
    </style>
</head>
<body>

    <h2 style="text-align:center;">REKAPITULASI ABSENSI - <?= strtoupper($nama_bulan[$bulan_pilih]) ?> <?= $tahun_pilih ?></h2>

    <div class="box-header">
        <div class="box-upload">
            <form method="post" enctype="multipart/form-data">
                <strong>📁 Sheet 1 (Umum)</strong><br>
                <input type="file" name="file_umum" required><br><br>
                <button type="submit" name="upload_umum">Upload Umum</button>
            </form>
            <div style="border-left: 1px solid #ccc; padding-left: 15px;">
                <form method="post" enctype="multipart/form-data">
                    <strong>📝 Sheet Log (Matriks)</strong><br>
                    <input type="file" name="file_log" required><br><br>
                    <button type="submit" name="upload_log">Upload Log</button>
                </form>
            </div>
        </div>

        <div class="box-filter">
            <form method="GET">
                <strong>🔍 Filter Data</strong><br><br>
                <select name="m">
                    <?php foreach($nama_bulan as $m_code => $m_name): ?>
                        <option value="<?= $m_code ?>" <?= $bulan_pilih == $m_code ? 'selected' : '' ?>><?= $m_name ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="y">
                    <?php for($y=2024; $y<=2030; $y++): ?>
                        <option value="<?= $y ?>" <?= $tahun_pilih == $y ? 'selected' : '' ?>><?= $y ?></option>
                    <?php endfor; ?>
                </select>
                <button type="submit">Tampilkan</button>
            </form>
            <br>
            <a href="cetak_pdf.php?m=<?= $bulan_pilih ?>&y=<?= $tahun_pilih ?>" target="_blank" 
            style="background: #e74c3c; color: white; padding: 8px 15px; text-decoration: none; border-radius: 4px; font-weight: bold;">
            📥 Download PDF
            </a>
        </div>
    </div>

<div class="table-container">
    <table>
        <thead>
            <tr class="header-rekap">
                <th rowspan="2">No</th><th rowspan="2">Nama</th>
                <th colspan="<?= $jumlah_hari ?>">Tanggal</th>
                <th rowspan="2" style="background:#ffd600; color:black;">Total<br>Terlambat</th>
            </tr>
            <tr class="header-rekap">
                <?php for($d=1; $d<=$jumlah_hari; $d++) {
                    $tgl = "$tahun_pilih-$bulan_pilih-".sprintf("%02d", $d);
                    $libur = $daftar_libur[$tgl] ?? (date('N', strtotime($tgl)) == 7 ? "Minggu" : "");
                    $cls = $libur ? "class='libur-merah'" : "";
                    echo "<th $cls title='$libur'>$d</th>";
                } ?>
            </tr>
        </thead>
        <tbody>
            <?php if(mysqli_num_rows($res_karyawan) == 0): ?>
                <tr><td colspan="<?= $jumlah_hari + 3 ?>">Tidak ada data untuk periode ini.</td></tr>
            <?php endif; ?>

            <?php $no = 1; while($k = mysqli_fetch_assoc($res_karyawan)): 
                    $telat = 0;
                    
                    // Ambil data range Cuti Melahirkan khusus untuk karyawan ini di bulan/tahun terpilih
                    $q_melahirkan = mysqli_query($conn, "SELECT MIN(DATE(waktu)) as mulai, MAX(DATE(waktu)) as selesai 
                        FROM presensi 
                        WHERE no_pin='{$k['no_pin']}' 
                        AND MONTH(waktu)='$bulan_pilih' 
                        AND YEAR(waktu)='$tahun_pilih'
                        AND department='{$k['department']}' 
                        AND LOWER(keterangan) = 'cuti melahirkan'");
                    
                    $data_melahirkan = mysqli_fetch_assoc($q_melahirkan);
                    $tgl_mulai_cuti = $data_melahirkan['mulai']; // Format: YYYY-MM-DD
                    $tgl_selesai_cuti = $data_melahirkan['selesai'];
                ?>
                <tr>
                    <td style="background:#f1f1f1;"><?= $no++ ?></td>
                    <td style="text-align:left; white-space:nowrap; padding: 0 10px;">
                        <a href="javascript:void(0)" 
                        onclick="konfirmasiHapus('<?= $k['nama'] ?>', '<?= $k['no_pin'] ?>', '<?= $bulan_pilih ?>', '<?= $tahun_pilih ?>')" 
                        style="text-decoration:none; color:#333;">
                            <b><?= strtoupper($k['nama']) ?></b>
                        </a>
                    </td>

                    <?php 
                    for($d = 1; $d <= $jumlah_hari; $d++): 
                        $tgl_sekarang = "$tahun_pilih-$bulan_pilih-" . sprintf("%02d", $d);
                        
                        // --- LOGIKA RANGE CUTI MELAHIRKAN ---
                        if (!empty($tgl_mulai_cuti) && $tgl_sekarang == $tgl_mulai_cuti) {
                            // Hitung selisih hari untuk menentukan colspan
                            $diff = (strtotime($tgl_selesai_cuti) - strtotime($tgl_mulai_cuti)) / (60 * 60 * 24);
                            $span = (int)$diff + 1;
                            
                            // Pastikan span tidak melebihi sisa hari dalam bulan tersebut
                            $sisa_hari = $jumlah_hari - $d + 1;
                            $actual_span = ($span > $sisa_hari) ? $sisa_hari : $span;

                            $bln_nama = $nama_bulan[(int)$bulan_pilih];
                            $range_label = date('d', strtotime($tgl_mulai_cuti)) . " - " . date('d', strtotime($tgl_selesai_cuti)) . " " . $bln_nama;
                            ?>
                            <td colspan="<?= $actual_span ?>" class="bg-cuti" 
                                style="text-align:center; font-weight:bold; cursor:pointer; vertical-align:middle;" 
                                onclick="bukaModal('<?= $k['no_pin'] ?>','<?= $k['nama'] ?>','<?= $tgl_sekarang ?>','<?= $k['department'] ?>')">
                                <div style="font-size:11px;">CUTI MELAHIRKAN</div>
                                <div style="font-size:9px; font-weight:normal; color:#eee;"><?= $range_label ?></div>
                            </td>
                            <?php
                            $d += ($actual_span - 1); // Lompati indeks hari yang sudah masuk dalam colspan
                            continue; 
                        }

                        // --- LOGIKA NORMAL (Presensi Harian) ---
                        $q = mysqli_query($conn, "SELECT MIN(waktu) as masuk, MAX(waktu) as pulang, MAX(keterangan) as ket 
                            FROM presensi 
                            WHERE no_pin='{$k['no_pin']}' AND DATE(waktu)='$tgl_sekarang' AND department='{$k['department']}'");
                        $data = mysqli_fetch_assoc($q);
                        
                        $libur = $daftar_libur[$tgl_sekarang] ?? (date('N', strtotime($tgl_sekarang)) == 7 ? "Minggu" : "");
                        $cls = $libur ? "libur-merah" : "";
                        $isi = "-";

                        if (!empty($data['ket'])) {
                            $status = strtolower($data['ket']);
                            $isi = "<div class='keterangan'>{$data['ket']}</div>";
                            if ($status == 'cuti') $cls = "bg-cuti";
                            elseif ($status == 'ijin' || $status == 'izin') $cls = "bg-ijin";
                            elseif ($status == 'sakit') $cls = "bg-sakit";
                            elseif ($status == 'tanpa keterangan' || $status == 'alpa') $cls = "bg-alpa";
                            elseif ($status == 'dinas luar' || $status == 'dl') $cls = "bg-dinas";
                            elseif ($status == 'tidak absen') $cls = "bg-no-scan";
                        } elseif ($data['masuk']) {
                            $m = date('H:i', strtotime($data['masuk']));
                            $p = date('H:i', strtotime($data['pulang']));
                            if ($m != "00:00") {
                                if(strtotime($m) > strtotime("08:30")) { $cls = "terlambat"; $telat++; }
                                if($m == $p) {
                                    if($cls != "terlambat") $cls = "hanya-satu-scan";
                                    $isi = "<span class='jam-masuk' style='color:white !important;'>$m</span><span class='jam-pulang' style='color:white !important;'>--:--</span>";
                                } else {
                                    $isi = "<span class='jam-masuk'>$m</span><span class='jam-pulang'>$p</span>";
                                }
                            }
                        }
                    ?>
                        <td class="<?= $cls ?>" onclick="bukaModal('<?= $k['no_pin'] ?>','<?= $k['nama'] ?>','<?= $tgl_sekarang ?>','<?= $k['department'] ?>')">
                            <?= $isi ?>
                        </td>
                    <?php endfor; ?>

                    <td class="total-kolom"><?= $telat ?></td>
                </tr>
                <?php endwhile; ?>
        </tbody>
    </table>
</div>
    

    <div style="margin-top: 20px; padding: 15px; border: 1px solid #ddd; border-radius: 8px; background-color: #fffaf0;">
        <h4 style="margin-top: 0; color: #d9534f;">📌 Keterangan Hari Libur Nasional & Minggu (<?= $nama_bulan[$bulan_pilih] ?> <?= $tahun_pilih ?>)</h4>
        <ul style="list-style-type: none; padding-left: 5px;">
            <?php 
            $ada_libur = false;
            // Urutkan tanggal agar rapi
            ksort($daftar_libur); 
            
            foreach ($daftar_libur as $tgl => $ket): 
                $ada_libur = true;
                $tgl_indo = date('d', strtotime($tgl)) . " " . $nama_bulan[$bulan_pilih];
            ?>
                <li style="margin-bottom: 5px;">
                    <span style="background: #ffcccc; color: red; padding: 2px 6px; border-radius: 4px; font-weight: bold;">
                        <?= $tgl_indo ?>
                    </span> 
                    : <?= $ket ?>
                </li>
            <?php endforeach; ?>

            <?php if (!$ada_libur): ?>
                <li style="color: #888; font-style: italic;">Tidak ada libur nasional tercatat di bulan ini.</li>
            <?php endif; ?>
            
            <li style="margin-top: 10px; font-size: 10px; color: #666;">
                * Hari Minggu ditandai dengan warna merah pada kolom tanggal tanpa keterangan tambahan.
            </li>
        </ul>
    </div>
    <div style="margin-top: 10px; padding: 15px; border: 1px solid #ddd; border-radius: 8px; background-color: #f9f9f9;">
        <h4 style="margin-top: 0;">🎨 Legenda Warna Status</h4>
        <div class="legenda-container">
            <div class="legenda-item bg-cuti">CUTI</div>
            <div class="legenda-item bg-ijin">IJIN</div>
            <div class="legenda-item bg-sakit">SAKIT</div>
            <div class="legenda-item bg-alpa">TANPA KETERANGAN</div>
            <div class="legenda-item bg-no-scan">TIDAK ABSEN</div>
            <div class="legenda-item bg-dinas">DINAS LUAR</div>
            <div class="legenda-item bg-cuti">CUTI MELAHIRKAN</div>
            <div class="legenda-item" style="background: #00008B; color: white;">HANYA 1x SCAN</div>
        </div>
    </div>

    <div id="overlay" onclick="tutupModal()"></div>
    <div id="modalKet">
        <form method="post">
            <h3>Input Keterangan</h3>
            <p id="info_nama" style="color:blue; font-weight:bold;"></p>
            
            <input type="hidden" name="pin_k" id="pin_k">
            <input type="hidden" name="dept_k" id="dept_k"> 
            
            <label style="font-size: 10px;">Tanggal Awal / Tunggal:</label>
            <input type="date" name="tgl_k" id="tgl_k" style="width:100%; padding:8px; margin-bottom:10px;">

            <div id="range_tanggal_container" style="display:none; margin-bottom:10px;">
                <label style="font-size: 10px;">Sampai Tanggal (Akhir):</label>
                <input type="date" name="tgl_akhir_k" id="tgl_akhir_k" style="width:100%; padding:8px; border: 2px solid #808080;">
            </div>
            
            <select name="keterangan" id="pilih_keterangan" onchange="cekKategori(this.value)" style="width:100%; padding:10px; margin-bottom:15px;">
                <option value="">-- Hadir / Hapus --</option>
                <option value="IJIN">IJIN</option>
                <option value="CUTI">CUTI</option>
                <option value="SAKIT">SAKIT</option>
                <option value="DINAS LUAR">DINAS LUAR</option>
                <option value="CUTI MELAHIRKAN">CUTI MELAHIRKAN</option>
                <option value="TANPA KETERANGAN">TANPA KETERANGAN</option>
                <option value="TIDAK ABSEN">TIDAK ABSEN</option>
            </select><br>

            <button type="submit" name="update_keterangan" style="background:green; color:white; padding:8px 15px; border:none; border-radius:5px; width:48%;">Simpan</button>
            <button type="button" onclick="tutupModal()" style="background:red; color:white; padding:8px 15px; border:none; border-radius:5px; width:48%;">Batal</button>
        </form>
    </div>

    <script>
        function bukaModal(pin, nama, tgl, dept) {
            document.getElementById('modalKet').style.display = 'block';
            document.getElementById('overlay').style.display = 'block';
            document.getElementById('pin_k').value = pin;
            document.getElementById('tgl_k').value = tgl;
            document.getElementById('dept_k').value = dept;
            document.getElementById('info_nama').innerText = nama + " (" + tgl + ")";
        }
        function cekKategori(val) {
            var container = document.getElementById('range_tanggal_container');
            var tglAkhir = document.getElementById('tgl_akhir_k');
            var tglMulai = document.getElementById('tgl_k').value;

            if (val === "CUTI MELAHIRKAN") {
                container.style.display = 'block';
                // Set default tanggal akhir sama dengan tanggal mulai agar tidak kosong
                tglAkhir.value = tglMulai;
            } else {
                container.style.display = 'none';
                tglAkhir.value = '';
            }
        }

        // Tambahkan reset pada fungsi tutupModal agar saat dibuka lagi kondisinya bersih
        function tutupModal() {
            document.getElementById('modalKet').style.display = 'none';
            document.getElementById('overlay').style.display = 'none';
            document.getElementById('range_tanggal_container').style.display = 'none';
            document.getElementById('pilih_keterangan').value = '';
        }
        function konfirmasiHapus(nama, no_pin, bulan, tahun) {
            if (confirm("Apakah Anda yakin ingin menghapus SEMUA data presensi " + nama + " pada bulan " + bulan + " tahun " + tahun + "?\n\nData yang dihapus tidak bisa dikembalikan.")) {
                window.location.href = "proses_hapus.php?no_pin=" + no_pin + "&m=" + bulan + "&y=" + tahun;
            }
        }
    </script>
</body>
</html>