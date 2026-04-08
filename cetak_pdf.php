<?php
date_default_timezone_set('Asia/Jakarta');

require_once 'dompdf/autoload.inc.php';
require_once 'SimpleXLSX.php'; 
// Koneksi database
$host = "localhost";
$user = "swakary1_absensi";
$pass = "@absensi123";
$db   = "swakary1_absensi";
$conn = mysqli_connect($host, $user, $pass, $db);

if (!$conn) exit;

// 2. SET TIMEZONE DATABASE
mysqli_query($conn, "SET time_zone = '+07:00'");

use Dompdf\Dompdf;
use Dompdf\Options;

$bulan_pilih = $_GET['m'];
$tahun_pilih = $_GET['y'];
$nama_bulan = ["01"=>"Januari","02"=>"Februari","03"=>"Maret","04"=>"April","05"=>"Mei","06"=>"Juni","07"=>"Juli","08"=>"Agustus","09"=>"September","10"=>"Oktober","11"=>"November","12"=>"Desember"];

// Ambil data hari libur (Fungsi yang sama dengan index)
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

$jumlah_hari = cal_days_in_month(CAL_GREGORIAN, $bulan_pilih, $tahun_pilih);
$daftar_libur = getHariLibur($bulan_pilih, $tahun_pilih);
$res_karyawan = mysqli_query($conn, "SELECT DISTINCT nama, no_pin, department FROM presensi WHERE nama IS NOT NULL AND nama != '' AND MONTH(waktu)='$bulan_pilih' AND YEAR(waktu)='$tahun_pilih' ORDER BY nama ASC");

// Mulai Output Buffering untuk menangkap HTML
ob_start();
?>
<!DOCTYPE html>
<html>
<head>
    <style>
        @page { 
            size: A4 landscape; 
            margin: 0.3cm; /* Margin sangat minimal */
        }
        body { 
            font-family: Arial, Helvetica, sans-serif; 
            font-size: 6.5px; /* Font utama diperkecil */
            margin: 0;
            padding: 0;
        }
        
        /* Gunakan fixed agar kita bisa mengontrol persentase lebar setiap kolom */
        table { 
            border-collapse: collapse; 
            width: 100%; 
            table-layout: fixed; 
        }
        
        th, td { 
            border: 0.5px solid #333; 
            padding: 2px 0; 
            text-align: center;
            overflow: hidden; /* Mencegah konten meluap keluar sel */
        }

        /* Pengaturan Lebar Kolom dalam Persen */
        .col-no { width: 3%; }      /* Kolom Nomor */
        .col-nama { 
            width: 8%;            /* Lebar kolom nama yang ideal (tidak terlalu lebar) */
            text-align: left; 
            padding-left: 3px;
            white-space: nowrap; 
            text-overflow: ellipsis; /* Menambahkan '...' jika nama terlalu panjang */
        }
        .col-tgl { width: 2.5%; }   /* Lebar setiap kolom tanggal (31 kolom x 2.5% = 77.5%) */
        .col-total { width: 4.5%; background: #ffeb3b; font-weight: bold; }
        
        .header-rekap { background: #343a40; color: white; font-weight: bold; }
        /* Status Warna */
        /*.bg-cuti { background-color: #808080 !important; color: white !important; }*/
        /*.bg-ijin { background-color: #ffff00 !important; color: black !important; }*/
        /*.bg-sakit { background-color: #800080 !important; color: white !important; }*/
        /*.bg-alpa { background-color: #a52a2a !important; color: white !important; }*/
        /*.bg-dinas { background-color: #ffc0cb !important; color: black !important; }*/
        /*.bg-no-scan { background-color: #006400 !important; color: white !important; }*/
        /*.libur-merah { background-color: #ffcccc !important; color: red !important; }*/
        /*.terlambat { background-color: #ffa500 !important; font-weight: bold; }*/
        /*.hanya-satu-scan { background-color: #00008B !important; color: white !important; }*/

        /*.jam-masuk { color: blue; display: block; border-bottom: 0.1px solid #ddd; }*/
        /*.jam-pulang { color: red; display: block; }*/
        /*.keterangan { font-size: 6px; font-weight: bold; }*/
        /*.footer-container { margin-top: 8px; width: 100%; }*/
        /*.box-ket { border: 0.5px solid #ddd; padding: 4px; vertical-align: top; }*/
        
        /* Status Warna Sesuai Index */
        .bg-cuti { background-color: #64748b !important; color: white !important; }
        .bg-ijin { background-color: #fef08a !important; color: #854d0e !important; }
        .bg-sakit { background-color: #e9d5ff !important; color: #6b21a8 !important; }
        .bg-alpa { background-color: #fecaca !important; color: #991b1b !important; }
        .bg-dinas { background-color: #fbcfe8 !important; color: #9d174d !important; }
        .bg-no-scan { background-color: #bbf7d0 !important; color: #166534 !important; }
        .libur-merah { background-color: #fee2e2 !important; color: #ef4444 !important; font-weight: bold; }
        .terlambat { background-color: #fff7ed !important; color: #c2410c !important; font-weight: bold; border: 0.5px solid #fdba74 !important; }
        .hanya-satu-scan { background-color: #eff6ff !important; color: #1d4ed8 !important; }

        /* Style Jam */
        .jam-masuk { color: #2563eb; display: block; font-weight: bold; border-bottom: 0.1px solid #f1f5f9; font-size: 6.5px; }
        .jam-pulang { color: #64748b; display: block; font-size: 6px; }
        
        /* Legend & Footer */
        .footer-container { margin-top: 15px; width: 100%; }
        .box-ket { border: 0.5px solid #e2e8f0; padding: 6px; vertical-align: top; border-radius: 4px; }
        .legend-item { padding: 2px 5px; display: inline-block; margin-right: 5px; margin-bottom: 3px; border-radius: 2px; font-weight: bold; font-size: 6px; }
    </style>
</head>
<body>
    <h2 style="text-align:center;">REKAP ABSENSI - <?= strtoupper($nama_bulan[$bulan_pilih]) ?> <?= $tahun_pilih ?></h2>
    
    <table>
        <thead>
            <tr class="header-rekap">
                <th rowspan="2" class="col-no">No</th>
                <th rowspan="2" class="col-nama">Nama</th>
                <th colspan="<?= $jumlah_hari ?>">Tanggal</th>
                <th rowspan="2" class="col-total" style="color:black;">Terlambat</th>
            </tr>
            <tr class="header-rekap">
                <?php for($d=1; $d<=$jumlah_hari; $d++): ?>
                    <th class="col-tgl"><?= $d ?></th>
                <?php endfor; ?>
            </tr>
        </thead>
        <?php if(mysqli_num_rows($res_karyawan) == 0): ?>
                <tr><td colspan="<?= $jumlah_hari + 3 ?>">Tidak ada data untuk periode ini.</td></tr>
            <?php endif; ?>

            <?php $no = 1; while($k = mysqli_fetch_assoc($res_karyawan)): 
                    $telat = 0;
                    
                    // 1. CEK APAKAH ADA CUTI MELAHIRKAN DI BULAN INI
                    $cek_melahirkan = mysqli_query($conn, "SELECT id FROM presensi 
                        WHERE no_pin='{$k['no_pin']}' 
                        AND department='{$k['department']}' 
                        AND MONTH(waktu)='$bulan_pilih' 
                        AND YEAR(waktu)='$tahun_pilih' 
                        AND LOWER(keterangan) = 'cuti melahirkan' LIMIT 1");
                    $is_cuti_melahirkan = (mysqli_num_rows($cek_melahirkan) > 0);
                ?>
                <tr>
                    <td class="col-no"><?= $no++ ?></td>
                    <td class="col-nama">
                        <b><?= strtoupper($k['nama']) ?></b> </td>
                    
                    <?php if ($is_cuti_melahirkan): ?>
                        <td colspan="<?= $jumlah_hari ?>" class="bg-cuti" style="font-size: 7px; font-weight: bold;">
                            CUTI MELAHIRKAN
                        </td>
                    <?php else: ?>
                        <?php for($d=1; $d<=$jumlah_hari; $d++): 
                            $tgl = "$tahun_pilih-$bulan_pilih-".sprintf("%02d", $d);
                            $q = mysqli_query($conn, "SELECT MIN(waktu) as masuk, MAX(waktu) as pulang, MAX(keterangan) as ket 
                                FROM presensi 
                                WHERE no_pin='{$k['no_pin']}' 
                                AND department='{$k['department']}' 
                                AND DATE(waktu)='$tgl'");
                            $data = mysqli_fetch_assoc($q);
                            
                            $libur = $daftar_libur[$tgl] ?? (date('N', strtotime($tgl)) == 7 ? "Minggu" : "");
                            $cls = $libur ? "libur-merah" : "";
                            $isi = "-";

                            if (!empty($data['ket'])) {
                                $isi = "<div class='keterangan'>{$data['ket']}</div>";
                                $status = strtolower($data['ket']);
                                if ($status == 'cuti') $cls = "bg-cuti";
                                elseif ($status == 'ijin' || $status == 'izin') $cls = "bg-ijin";
                                elseif ($status == 'sakit') $cls = "bg-sakit";
                                elseif ($status == 'tanpa keterangan' || $status == 'alpa') $cls = "bg-alpa";
                                elseif ($status == 'dinas luar' || $status == 'dl') $cls = "bg-dinas";
                                elseif ($status == 'tidak absen') $cls = "bg-no-scan";
                            } elseif ($data['masuk']) {
                                $m = date('H:i', strtotime($data['masuk']));
                                $p = date('H:i', strtotime($data['pulang']));
                                if ($m == "00:00" && $p == "00:00") {
                                    // $cls = "bg-no-scan"; 
                                    $isi = "-";
                                } else {
                                    if(strtotime($m) > strtotime("08:30")) { $cls = "terlambat"; $telat++; }
                                    if($m == $p) {
                                        // Jika tidak terlambat, berikan class khusus (background biru dari CSS Anda)
                                        if($cls != "terlambat") $cls = "hanya-satu-scan";
                                        
                                        // Tambahkan style color: white agar teks jam tetap terlihat jelas di background biru
                                        $isi = "<span class='jam-masuk' style='color: white !important;'>$m</span>
                                                <span class='jam-pulang' style='color: white !important;'>--:--</span>";
                                    } else {
                                        // Kondisi normal (Scan Masuk & Pulang berbeda)
                                        $isi = "<span class='jam-masuk'>$m</span>
                                                <span class='jam-pulang'>$p</span>";
                                    }
                                }
                            }
                        ?>
                            <td class="<?= $cls ?> col-tgl"><?= $isi ?></td>
                        <?php endfor; ?>
                    <?php endif; ?>
                    
                    <td class="total-kolom"><?= $telat ?></td>
                </tr>
                <?php endwhile; ?>
        </tbody>
    </table>
    <div class="footer-container">
        <table style="border: none;">
            <tr>
                <td class="box-ket" style="width: 45%; background-color: #fffaf0; text-align: left;">
                    <b style="color: #d9534f; font-size: 7.5px;">LIBUR NASIONAL & MINGGU</b>
                    <ul style="list-style: none; padding: 0; margin: 2px 0 0 0; font-size: 6.5px;">
                        <?php foreach ($daftar_libur as $tgl => $ket): ?>
                            <li><span style="color:red; font-weight:bold;"><?= date('d', strtotime($tgl)) ?></span>: <?= $ket ?></li>
                        <?php endforeach; ?>
                        <li style="font-size: 5.5px; color: #666; margin-top: 2px;">* Merah tanpa teks = Hari Minggu</li>
                    </ul>
                </td>
                <td style="width: 2%; border: none;"></td>
                <td class="box-ket" style="width: 53%; background-color: #f9f9f9; text-align: left;">
                    <b style="font-size: 7.5px;">WARNA STATUS</b>
                    <table style="width: 100%; border: none; margin-top: 2px;">
                        <tr>
                            <td style="border:none; padding: 1px;"><div class="bg-cuti">CUTI</div></td>
                            <td style="border:none; padding: 1px;"><div class="bg-ijin">IJIN</div></td>
                            <td style="border:none; padding: 1px;"><div class="bg-sakit">SAKIT</div></td>
                            <td style="border:none; padding: 1px;"><div class="bg-alpa">ALPA</div></td>
                        </tr>
                        <tr>
                            <td style="border:none; padding: 1px;"><div class="bg-no-scan">TIDAK ABSEN</div></td>
                            <td style="border:none; padding: 1px;"><div class="bg-dinas">DINAS LUAR</div></td>
                            <td style="border:none; padding: 1px;"><div class="bg-cuti">CUTI MLHRKN</div></td>
                            <td style="border:none; padding: 1px;"><div class="hanya-satu-scan">1x SCAN</div></td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>
    </div>
</body>
</html>
<?php
$html = ob_get_clean();

$options = new Options();
$options->set('isRemoteEnabled', true);
$options->set('isHtml5ParserEnabled', true);

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'landscape');
$dompdf->render();
$dompdf->stream("Rekap_Absensi.pdf", ["Attachment" => false]);
?>