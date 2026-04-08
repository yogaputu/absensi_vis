<?php
$host = "localhost";
$user = "root";
$pass = "";
$db   = "absensi_vistri";
$conn = mysqli_connect($host, $user, $pass, $db);

// Ambil data dari Telegram
$content = file_get_contents("php://input");
$update = json_decode($content, true);

if (!$update) exit;

$chat_id = $update["message"]["chat"]["id"];
$text    = $update["message"]["text"]; // Asumsi: Pegawai kirim PIN mereka
$nama_user = $update["message"]["from"]["first_name"];

// 1. Logika Sederhana: Pegawai mengetik PIN untuk absen
// Anda bisa kembangkan dengan validasi lokasi (GPS) jika perlu
if (is_numeric($text)) {
    $pin = mysqli_real_escape_string($conn, $text);
    $waktu = date("Y-m-d H:i:s");
    $dept = "LAPANGAN"; // Tandai sebagai dept lapangan

    // Cek Nama Pegawai berdasarkan PIN (Jika ada tabel master_karyawan)
    // Untuk contoh ini, kita masukkan sesuai input
    $sql = "INSERT INTO presensi (department, nama, no_pin, waktu, keterangan) 
            VALUES ('$dept', 'LAPANGAN - $nama_user', '$pin', '$waktu', 'HADIR (BOT)')";
    
    if (mysqli_query($conn, $sql)) {
        $reply = "✅ Absen Berhasil!\nNama: $nama_user\nPIN: $pin\nWaktu: $waktu";
    } else {
        $reply = "❌ Gagal menyimpan data.";
    }
} else {
    $reply = "Halo $nama_user, silakan masukkan nomor PIN Anda untuk melakukan absensi.";
}

// Kirim balik ke Telegram
$apiToken = "YOUR_BOT_TOKEN_HERE";
file_get_contents("https://api.telegram.org/bot$apiToken/sendMessage?chat_id=$chat_id&text=" . urlencode($reply));