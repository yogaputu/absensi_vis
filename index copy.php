<?php
require_once 'SimpleXLSX.php'; 
require_once 'SimpleXLS.php';  

use Shuchkin\SimpleXLSX;
use Shuchkin\SimpleXLS;

$host = "localhost";
$user = "root";
$pass = "";
$db   = "absensi_vistri";
$conn = mysqli_connect($host, $user, $pass, $db);

// --- 1. FUNGSI AMBIL HARI LIBUR ---
function getHariLibur($bulan, $tahun) {
    $url = "https://day-off-api.vercel.app/api?month=$bulan&year=$tahun";
    $data = @file_get_contents($url);
    $libur = [];
    if ($data) {
        $array = json_decode($data, true);
        foreach ($array as $row) {
            $libur[$row['tanggal']] = $row['keterangan'];
        }
    }
    return $libur;
}

// --- 2. FUNGSI SIMPAN DATA STANDAR (SHEET 1) ---
function simpanDataStandar($conn, $rows) {
    foreach ($rows as $index => $row) {
        if ($index == 0 || empty($row[1])) continue;
        $dept  = mysqli_real_escape_string($conn, $row[0]);
        $nama  = mysqli_real_escape_string($conn, $row[1]);
        $pin   = mysqli_real_escape_string($conn, $row[2]);
        $raw_t = $row[3];
        $waktu = is_numeric($raw_t) ? date("Y-m-d H:i:s", ($raw_t - 25569) * 86400) : date('Y-m-d H:i:s', strtotime(str_replace('/', '-', $raw_t)));
        mysqli_query($conn, "INSERT INTO presensi (department, nama, no_pin, waktu) VALUES ('$dept', '$nama', '$pin', '$waktu')");
    }
}

// --- 3. FUNGSI SIMPAN DATA MATRIKS (SHEET LOG) ---
function importFormatMatriks($conn, $rows, $bulan, $tahun) {
    $current_nama = ""; $current_pin = "";
    foreach ($rows as $row) {
        // Cek baris Identitas (No: 1001, Nama: aris)
        // Gunakan trim() untuk membersihkan spasi tak terlihat
        $cell0 = isset($row[0]) ? trim((string)$row[0]) : "";
        
        if (strpos($cell0, 'No:') !== false) {
            $current_pin = trim((string)$row[2]); 
            $current_nama = trim((string)$row[9]); 
            continue;
        }

        // Jika Nama sudah terisi, berarti baris ini/bawahnya adalah baris Jam
        if (!empty($current_nama)) {
            $ada_data_jam = false;
            for ($d = 1; $d <= 31; $d++) {
                $col_idx = $d - 1; // Kolom 0-30 mewakili Tgl 1-31
                if (isset($row[$col_idx]) && !empty(trim((string)$row[$col_idx]))) {
                    $ada_data_jam = true;
                    $tgl_full = "$tahun-$bulan-" . sprintf("%02d", $d);
                    
                    // Pisahkan jika ada dua jam dalam satu sel (Masuk & Pulang)
                    $jam_data = explode("\n", trim((string)$row[$col_idx]));
                    foreach ($jam_data as $jam) {
                        $jam_clean = trim($jam);
                        if (strlen($jam_clean) >= 4) { // Validasi format jam minimal (misal 8:00)
                            $waktu_db = $tgl_full . " " . $jam_clean . ":00";
                            mysqli_query($conn, "INSERT INTO presensi (nama, no_pin, waktu) VALUES ('$current_nama', '$current_pin', '$waktu_db')");
                        }
                    }
                }
            }
            // Jika baris ini berisi data jam, setelah selesai kita reset identitas untuk orang berikutnya
            if ($ada_data_jam) {
                $current_nama = ""; 
                $current_pin = "";
            }
        }
    }
}

// --- 4. LOGIKA TOMBOL ---
if (isset($_POST['upload_umum'])) {
    $ext = pathinfo($_FILES['file_umum']['name'], PATHINFO_EXTENSION);
    if ($ext == 'xlsx') { $xlsx = SimpleXLSX::parse($_FILES['file_umum']['tmp_name']); if ($xlsx) simpanDataStandar($conn, $xlsx->rows(0)); }
    else { $xls = SimpleXLS::parse($_FILES['file_umum']['tmp_name']); if ($xls) simpanDataStandar($conn, $xls->rows(0)); }
    header("Location: index.php?status=success");
}

if (isset($_POST['upload_log'])) {
    $ext = pathinfo($_FILES['file_log']['name'], PATHINFO_EXTENSION);
    $target_rows = null;
    if ($ext == 'xlsx') {
        $xlsx = SimpleXLSX::parse($_FILES['file_log']['tmp_name']);
        if ($xlsx) { foreach ($xlsx->sheetNames() as $idx => $name) { if (strtolower(trim($name)) == 'log') { $target_rows = $xlsx->rows($idx); break; } } }
    } else {
        $xls = SimpleXLS::parse($_FILES['file_log']['tmp_name']);
        if ($xls) { foreach ($xls->sheetNames() as $idx => $name) { if (strtolower(trim($name)) == 'log') { $target_rows = $xls->rows($idx); break; } } }
    }
    
    if ($target_rows) { 
        importFormatMatriks($conn, $target_rows, "01", "2026"); 
        header("Location: index.php?status=success_log"); 
    } else {
        echo "<script>alert('Error: Sheet bernama LOG tidak ditemukan di file ini!'); window.location='index.php';</script>";
    }
}

// --- 5. TAMPILAN ---
$bulan_pilih = "01"; $tahun_pilih = "2026";
$jumlah_hari = cal_days_in_month(CAL_GREGORIAN, $bulan_pilih, $tahun_pilih);
$daftar_libur = getHariLibur($bulan_pilih, $tahun_pilih);
$res_karyawan = mysqli_query($conn, "SELECT DISTINCT nama, no_pin FROM presensi WHERE nama IS NOT NULL AND nama != '' ORDER BY nama ASC");
?>

<!DOCTYPE html>
<html>
<head>
    <title>Rekap Absensi Vistri</title>
    <style>
        body { font-family: 'Segoe UI', Tahoma; font-size: 11px; margin: 20px; background: #f8f9fa; }
        .box { background: white; padding: 20px; border-radius: 8px; border: 1px solid #ddd; }
        .upload-area { display: flex; gap: 20px; margin-bottom: 20px; padding: 15px; background: #f1f3f5; border-radius: 5px; }
        table { border-collapse: collapse; width: 100%; }
        th, td { border: 1px solid #444; padding: 4px; text-align: center; }
        .bg-black { background: #212529; color: white; }
        .libur { background: #ffdee2 !important; color: #d63031; }
        .telat { background: #fdcb6e !important; font-weight: bold; }
        .biru-tua { background: #0984e3 !important; color: white !important; }
        .jam-m { color: #0984e3; display: block; border-bottom: 1px solid #eee; font-weight: bold; }
        .biru-tua .jam-m { color: white; }
        .jam-p { color: #d63031; display: block; }
    </style>
</head>
<body>

<div class="box">
    <h2>Manajemen Absensi - Januari 2026</h2>
    
    <div class="upload-area">
        <form method="post" enctype="multipart/form-data">
            <strong>📁 Input 1: Standard (.xls/.xlsx)</strong><br>
            <input type="file" name="file_umum" required>
            <button type="submit" name="upload_umum">Proses</button>
        </form>
        <div style="border-left: 2px solid #ccc; padding-left: 20px;">
            <form method="post" enctype="multipart/form-data">
                <strong>📝 Input 2: Sheet "Log" (.xls/.xlsx)</strong><br>
                <input type="file" name="file_log" required>
                <button type="submit" name="upload_log">Proses Log</button>
            </form>
        </div>
    </div>

    

    <table>
        <thead>
            <tr class="bg-black">
                <th rowspan="2">No</th><th rowspan="2">Nama</th>
                <th colspan="<?= $jumlah_hari ?>">Tanggal</th>
                <th rowspan="2" style="background:#f1c40f; color:black">Total<br>Telat</th>
            </tr>
            <tr class="bg-black">
                <?php for($d=1; $d<=$jumlah_hari; $d++): 
                    $tgl = "$tahun_pilih-$bulan_pilih-".sprintf("%02d", $d);
                    $libur = $daftar_libur[$tgl] ?? (date('N', strtotime($tgl)) == 7 ? "Minggu" : "");
                    $cls = $libur ? "class='libur'" : "";
                    echo "<th $cls title='$libur'>$d</th>";
                endfor; ?>
            </tr>
        </thead>
        <tbody>
            <?php $no = 1; while($k = mysqli_fetch_assoc($res_karyawan)): $telat = 0; ?>
            <tr>
                <td><?= $no++ ?></td>
                <td style="text-align:left; padding:0 5px;"><strong><?= $k['nama'] ?></strong></td>
                <?php for($d=1; $d<=$jumlah_hari; $d++): 
                    $tgl = "$tahun_pilih-$bulan_pilih-".sprintf("%02d", $d);
                    $q = mysqli_query($conn, "SELECT MIN(waktu) as m, MAX(waktu) as p FROM presensi WHERE no_pin='{$k['no_pin']}' AND DATE(waktu)='$tgl'");
                    $data = mysqli_fetch_assoc($q);
                    
                    $cls = (isset($daftar_libur[$tgl]) || date('N', strtotime($tgl)) == 7) ? "libur" : "";
                    $isi = "-";

                    if($data['m']) {
                        $m = date('H:i', strtotime($data['m']));
                        $p = date('H:i', strtotime($data['p']));
                        if(strtotime($m) > strtotime("08:30") && $m != "00:00") { $cls = "telat"; $telat++; }
                        if($m == $p) {
                            if($cls != "telat") $cls = "biru-tua";
                            $isi = "<span class='jam-m'>$m</span><span>--:--</span>";
                        } else {
                            $isi = "<span class='jam-m'>$m</span><span class='jam-p'>$p</span>";
                        }
                    }
                    echo "<td class='$cls'>$isi</td>";
                endfor; ?>
                <td style="background:#f1c40f; font-weight:bold;"><?= $telat ?></td>
            </tr>
            <?php endwhile; ?>
        </tbody>
    </table>
</div>

</body>
</html>