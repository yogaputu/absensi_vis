<?php
// Koneksi database
$host = "localhost";
$user = "root";
$pass = "";
$db   = "absensi_vistri";
$conn = mysqli_connect($host, $user, $pass, $db);

if (isset($_GET['no_pin']) && isset($_GET['m']) && isset($_GET['y'])) {
    $no_pin = mysqli_real_escape_string($conn, $_GET['no_pin']);
    $m = mysqli_real_escape_string($conn, $_GET['m']);
    $y = mysqli_real_escape_string($conn, $_GET['y']);

    // Query hapus data presensi karyawan tersebut hanya di bulan & tahun terpilih
    $query = "DELETE FROM presensi 
              WHERE no_pin = '$no_pin' 
              AND MONTH(waktu) = '$m' 
              AND YEAR(waktu) = '$y'";

    if (mysqli_query($conn, $query)) {
        echo "<script>alert('Data berhasil dihapus dari rekap.'); window.location.href='index.php?m=$m&y=$y';</script>";
    } else {
        echo "Error: " . mysqli_error($conn);
    }
}
?>