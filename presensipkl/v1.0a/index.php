<?php
// Error reporting untuk development
error_reporting(E_ALL);
ini_set('display_errors', 1);
//Session
ob_start();
session_start();

// Koneksi ke database
require "db.php";
$conn = new mysqli($host, $user, $password, $database);
if ($conn->connect_error) {
    die("Koneksi database gagal: " . $conn->connect_error);
}

// Set charset ke utf8
$conn->set_charset("utf8");

// Fungsi untuk mencegah XSS
function sanitize_output($data) {
    return htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
}

// Fungsi untuk validasi input
function validate_input($data, $type = 'text') {
    $data = trim($data);
    $data = stripslashes($data);
    
    switch ($type) {
        case 'email':
            if (!filter_var($data, FILTER_VALIDATE_EMAIL)) {
                return false;
            }
            break;
        case 'int':
            if (!filter_var($data, FILTER_VALIDATE_INT)) {
                return false;
            }
            break;
        case 'float':
            if (!filter_var($data, FILTER_VALIDATE_FLOAT)) {
                return false;
            }
            break;
        case 'date':
            if (!DateTime::createFromFormat('Y-m-d', $data)) {
                return false;
            }
            break;
        case 'time':
            if (!DateTime::createFromFormat('H:i:s', $data)) {
                return false;
            }
            break;
    }
    
    return $data;
}

// Fungsi untuk menjalankan query dengan prepared statement
// Fungsi untuk menjalankan query dengan prepared statement
function query($sql, $params = []) {
    global $conn;
    
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log("Error dalam prepared statement: " . $conn->error);
        return false;
    }
    
    if (!empty($params)) {
        $types = '';
        foreach ($params as $param) {
            if (is_int($param)) $types .= 'i';
            elseif (is_double($param)) $types .= 'd';
            else $types .= 's';
        }
        $stmt->bind_param($types, ...$params);
    }
    
    if (!$stmt->execute()) {
        error_log("Query execution error: " . $stmt->error);
        return false;
    }
    
    if (preg_match('/^\s*(select|show|describe|explain)/i', $sql)) {
        $result = $stmt->get_result();
        if (!$result) {
            error_log("Get result error: " . $stmt->error);
            return false;
        }
        return $result;
    } else {
        return $stmt->affected_rows;
    }
}

// Fungsi untuk generate CSRF token
function generateCsrfToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

// Fungsi untuk validasi CSRF token
function validateCsrfToken($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

// Fungsi untuk memastikan direktori ada
function ensureDirectory($dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
}

// Buat direktori yang diperlukan
ensureDirectory('uploads/presensi/masuk');
ensureDirectory('uploads/presensi/pulang');
ensureDirectory('uploads/lampiran/ijin');
ensureDirectory('uploads/lampiran/sakit');
ensureDirectory('uploads/profil');

// Inisialisasi data contoh jika belum ada
function initDatabase() {
    // Cek apakah tabel sudah ada
    $result = query("SHOW TABLES LIKE 'users'");
    if ($result && $result->num_rows > 0) {
        return true; // Database sudah diinisialisasi
    }
    
    // Jalankan skema SQL
    $sql = file_get_contents('schema.sql'); // Pisahkan skema SQL ke file terpisah
    
    if (!$sql) {
        error_log("File schema.sql tidak ditemukan");
        return false;
    }
    
    // Pisahkan setiap perintah SQL dan jalankan
    $sql_commands = array_filter(array_map('trim', explode(';', $sql)));
    foreach ($sql_commands as $command) {
        if (!empty($command)) {
            if (!query($command)) {
                error_log("Gagal menjalankan perintah: " . $command);
                return false;
            }
        }
    }
    
    return true;
}

// Panggil fungsi inisialisasi database
if (!initDatabase()) {
    die("Gagal menginisialisasi database. Lihat log untuk detail.");
}

// Handle login
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $username = validate_input($_POST['username']);
    $password = $_POST['password'];
    
    if (!$username) {
        $error = "Username tidak valid";
    } else {
        $result = query("SELECT * FROM users WHERE username = ?", [$username]);
        if ($result && $result->num_rows === 1) {
            $user = $result->fetch_assoc();
            if (password_verify($password, $user['password'])) {
                $_SESSION['user_id'] = $user['id_user'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['role'] = $user['role'];
                $_SESSION['id_referensi'] = $user['id_referensi'];
                
                header("Location: index.php");
                exit();
            } else {
                $error = "Password salah!";
            }
        } else {
            $error = "Username tidak ditemukan!";
        }
    }
}

// Handle logout
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: index.php");
    exit();
}

// Redirect ke login jika belum login
if (!isset($_SESSION['user_id']) && basename($_SERVER['PHP_SELF']) !== 'index.php') {
    header("Location: index.php");
    exit();
}

// Fungsi untuk mendapatkan data user berdasarkan role
function getUserData($role, $id) {
    switch ($role) {
        case 'siswa':
            $result = query("SELECT s.*, k.nama_kelas, j.nama_jurusan, sk.nama_sekolah 
                         FROM siswa s 
                         JOIN kelas k ON s.id_kelas = k.id_kelas
                         JOIN jurusan j ON k.id_jurusan = j.id_jurusan
                         JOIN sekolah sk ON j.id_sekolah = sk.id_sekolah
                         WHERE s.id_siswa = ?", [$id]);
            return $result ? $result->fetch_assoc() : null;
            
        case 'pembimbing_sekolah':
            $result = query("SELECT g.*, s.nama_sekolah 
                         FROM guru g 
                         JOIN sekolah s ON g.id_sekolah = s.id_sekolah
                         WHERE g.id_guru = ?", [$id]);
            return $result ? $result->fetch_assoc() : null;
            
        case 'pembimbing_industri':
            $result = query("SELECT pi.*, i.nama_industri 
                         FROM pembimbing_industri pi 
                         JOIN industri i ON pi.id_industri = i.id_industri
                         WHERE pi.id_pembimbing_industri = ?", [$id]);
            return $result ? $result->fetch_assoc() : null;
            
        case 'admin':
        case 'manager':
            return ['nama' => ucfirst($role), 'role' => $role];
            
        default:
            return null;
    }
}

// Fungsi untuk mendapatkan rekap perizinan berdasarkan role
function getRekapPerizinan($role, $id_referensi) {
    $rekap = ['sakit' => 0, 'ijin' => 0, 'total' => 0];
    
    switch ($role) {
        case 'siswa':
            // Dapatkan id_anggota dari siswa
            $anggota = query("SELECT id_anggota FROM anggota_kelompok WHERE id_siswa = ?", [$id_referensi]);
            if ($anggota && $anggota->num_rows === 1) {
                $id_anggota = $anggota->fetch_assoc()['id_anggota'];
                
                // Hitung per jenis perizinan
                $result_sakit = query("SELECT COUNT(*) as total FROM perizinan WHERE id_anggota = ? AND jenis = 'sakit'", [$id_anggota]);
                $result_ijin = query("SELECT COUNT(*) as total FROM perizinan WHERE id_anggota = ? AND jenis = 'ijin'", [$id_anggota]);
                
                if ($result_sakit) {
                    $rekap['sakit'] = $result_sakit->fetch_assoc()['total'];
                }
                if ($result_ijin) {
                    $rekap['ijin'] = $result_ijin->fetch_assoc()['total'];
                }
                $rekap['total'] = $rekap['sakit'] + $rekap['ijin'];
            }
            break;
            
        case 'pembimbing_sekolah':
            // Dapatkan perizinan untuk siswa yang dibimbing
            $result = query("SELECT per.jenis, COUNT(*) as total 
                           FROM perizinan per
                           JOIN anggota_kelompok ak ON per.id_anggota = ak.id_anggota
                           JOIN kelompok_pkl kp ON ak.id_kelompok = kp.id_kelompok
                           WHERE kp.id_guru = ?
                           GROUP BY per.jenis", [$id_referensi]);
            
            if ($result) {
                while ($row = $result->fetch_assoc()) {
                    $rekap[$row['jenis']] = $row['total'];
                    $rekap['total'] += $row['total'];
                }
            }
            break;
            
        case 'pembimbing_industri':
            // Dapatkan data industri dari pembimbing
            $pembimbing = query("SELECT id_industri FROM pembimbing_industri WHERE id_pembimbing_industri = ?", [$id_referensi]);
            if ($pembimbing && $pembimbing->num_rows === 1) {
                $id_industri = $pembimbing->fetch_assoc()['id_industri'];
                
                // Dapatkan perizinan untuk siswa di industri tersebut
                $result = query("SELECT per.jenis, COUNT(*) as total 
                               FROM perizinan per
                               JOIN anggota_kelompok ak ON per.id_anggota = ak.id_anggota
                               JOIN kelompok_pkl kp ON ak.id_kelompok = kp.id_kelompok
                               WHERE kp.id_industri = ?
                               GROUP BY per.jenis", [$id_industri]);
                
                if ($result) {
                    while ($row = $result->fetch_assoc()) {
                        $rekap[$row['jenis']] = $row['total'];
                        $rekap['total'] += $row['total'];
                    }
                }
            }
            break;
            
        case 'admin':
        case 'manager':
            // Hitung semua perizinan
            $result = query("SELECT jenis, COUNT(*) as total FROM perizinan GROUP BY jenis");
            if ($result) {
                while ($row = $result->fetch_assoc()) {
                    $rekap[$row['jenis']] = $row['total'];
                    $rekap['total'] += $row['total'];
                }
            }
            break;
    }
    
    return $rekap;
}

/*// Fungsi untuk mendapatkan rekap presensi harian pembimbing
function getRekapPresensiHarianPembimbing($id_pembimbing, $role, $tanggal) {
    $rekap = [
        'ijin' => 0,
        'sakit' => 0,
        'absen' => 0,
        'tepat_waktu' => 0,
        'terlambat' => 0,
        'pulang_cepat' => 0,
        'belum_pulang' => 0
    ];
    
    if ($role === 'pembimbing_sekolah') {
        // Query untuk pembimbing sekolah
        $query = "SELECT 
                    COUNT(DISTINCT ak.id_siswa) as total_siswa,
                    SUM(CASE WHEN per.id_izin IS NOT NULL AND per.jenis = 'ijin' THEN 1 ELSE 0 END) as ijin,
                    SUM(CASE WHEN per.id_izin IS NOT NULL AND per.jenis = 'sakit' THEN 1 ELSE 0 END) as sakit,
                    SUM(CASE WHEN pre.id_anggota IS NOT NULL AND pre.status = 'tepat waktu' THEN 1 ELSE 0 END) as tepat_waktu,
                    SUM(CASE WHEN pre.id_anggota IS NOT NULL AND pre.status = 'terlambat' THEN 1 ELSE 0 END) as terlambat,
                    SUM(CASE WHEN pre.id_anggota IS NOT NULL AND pre.status = 'pulang cepat' THEN 1 ELSE 0 END) as pulang_cepat,
                    SUM(CASE WHEN pre.id_anggota IS NOT NULL AND pre.jam_pulang IS NULL THEN 1 ELSE 0 END) as belum_pulang
                  FROM anggota_kelompok ak
                  JOIN kelompok_pkl kp ON ak.id_kelompok = kp.id_kelompok
                  LEFT JOIN perizinan per ON ak.id_anggota = per.id_anggota 
                    AND per.tanggal_mulai <= ? AND per.tanggal_selesai >= ? 
                    AND per.status = 'disetujui'
                  LEFT JOIN presensi pre ON ak.id_anggota = pre.id_anggota AND pre.tanggal = ?
                  WHERE kp.id_guru = ?";
        
        $result = query($query, [$tanggal, $tanggal, $tanggal, $id_pembimbing]);
    } else {
        // Query untuk pembimbing industri
        $query = "SELECT 
                    COUNT(DISTINCT ak.id_siswa) as total_siswa,
                    SUM(CASE WHEN per.id_izin IS NOT NULL AND per.jenis = 'ijin' THEN 1 ELSE 0 END) as ijin,
                    SUM(CASE WHEN per.id_izin IS NOT NULL AND per.jenis = 'sakit' THEN 1 ELSE 0 END) as sakit,
                    SUM(CASE WHEN pre.id_anggota IS NOT NULL AND pre.status = 'tepat waktu' THEN 1 ELSE 0 END) as tepat_waktu,
                    SUM(CASE WHEN pre.id_anggota IS NOT NULL AND pre.status = 'terlambat' THEN 1 ELSE 0 END) as terlambat,
                    SUM(CASE WHEN pre.id_anggota IS NOT NULL AND pre.status = 'pulang cepat' THEN 1 ELSE 0 END) as pulang_cepat,
                    SUM(CASE WHEN pre.id_anggota IS NOT NULL AND pre.jam_pulang IS NULL THEN 1 ELSE 0 END) as belum_pulang
                  FROM anggota_kelompok ak
                  JOIN kelompok_pkl kp ON ak.id_kelompok = kp.id_kelompok
                  LEFT JOIN perizinan per ON ak.id_anggota = per.id_anggota 
                    AND per.tanggal_mulai <= ? AND per.tanggal_selesai >= ? 
                    AND per.status = 'disetujui'
                  LEFT JOIN presensi pre ON ak.id_anggota = pre.id_anggota AND pre.tanggal = ?
                  WHERE kp.id_industri = (SELECT id_industri FROM pembimbing_industri WHERE id_pembimbing_industri = ?)";
        
        $result = query($query, [$tanggal, $tanggal, $tanggal, $id_pembimbing]);
    }
    
    if ($result && $result->num_rows > 0) {
        $data = $result->fetch_assoc();
        $rekap['ijin'] = $data['ijin'];
        $rekap['sakit'] = $data['sakit'];
        $rekap['tepat_waktu'] = $data['tepat_waktu'];
        $rekap['terlambat'] = $data['terlambat'];
        $rekap['pulang_cepat'] = $data['pulang_cepat'];
        $rekap['belum_pulang'] = $data['belum_pulang'];
        
        // Hitung yang absen (tidak ada presensi dan tidak ada izin)
        $total_siswa = $data['total_siswa'];
        $hadir = $data['tepat_waktu'] + $data['terlambat'] + $data['pulang_cepat'] + $data['belum_pulang'];
        $rekap['absen'] = $total_siswa - $hadir - $data['ijin'] - $data['sakit'];
    }
    
    return $rekap;
}*/
// Fungsi untuk mendapatkan rekap presensi harian pembimbing
function getRekapPresensiHarianPembimbing($id_pembimbing, $role, $tanggal) {
    $rekap = [
        'ijin' => 0,
        'sakit' => 0,
        'absen' => 0,
        'tepat_waktu' => 0,
        'terlambat' => 0,
        'pulang_cepat' => 0,
        'belum_pulang' => 0
    ];
    
    if ($role === 'pembimbing_sekolah') {
        // Query untuk pembimbing sekolah
        $query = "SELECT 
                    COUNT(DISTINCT ak.id_siswa) as total_siswa,
                    SUM(CASE WHEN per.id_izin IS NOT NULL AND per.jenis = 'ijin' THEN 1 ELSE 0 END) as ijin,
                    SUM(CASE WHEN per.id_izin IS NOT NULL AND per.jenis = 'sakit' THEN 1 ELSE 0 END) as sakit,
                    SUM(CASE WHEN pre.id_anggota IS NOT NULL AND pre.status = 'tepat waktu' THEN 1 ELSE 0 END) as tepat_waktu,
                    SUM(CASE WHEN pre.id_anggota IS NOT NULL AND pre.status = 'terlambat' THEN 1 ELSE 0 END) as terlambat,
                    SUM(CASE WHEN pre.id_anggota IS NOT NULL AND pre.status = 'pulang cepat' THEN 1 ELSE 0 END) as pulang_cepat,
                    SUM(CASE WHEN pre.id_anggota IS NOT NULL AND pre.jam_pulang IS NULL THEN 1 ELSE 0 END) as belum_pulang
                  FROM anggota_kelompok ak
                  JOIN kelompok_pkl kp ON ak.id_kelompok = kp.id_kelompok
                  LEFT JOIN perizinan per ON ak.id_anggota = per.id_anggota 
                    AND per.tanggal_mulai <= ? AND per.tanggal_selesai >= ? 
                    AND per.status = 'disetujui'
                  LEFT JOIN presensi pre ON ak.id_anggota = pre.id_anggota AND pre.tanggal = ?
                  WHERE kp.id_guru = ?";
        
        $result = query($query, [$tanggal, $tanggal, $tanggal, $id_pembimbing]);
    } else {
        // Query untuk pembimbing industri
        $query = "SELECT 
                    COUNT(DISTINCT ak.id_siswa) as total_siswa,
                    SUM(CASE WHEN per.id_izin IS NOT NULL AND per.jenis = 'ijin' THEN 1 ELSE 0 END) as ijin,
                    SUM(CASE WHEN per.id_izin IS NOT NULL AND per.jenis = 'sakit' THEN 1 ELSE 0 END) as sakit,
                    SUM(CASE WHEN pre.id_anggota IS NOT NULL AND pre.status = 'tepat waktu' THEN 1 ELSE 0 END) as tepat_waktu,
                    SUM(CASE WHEN pre.id_anggota IS NOT NULL AND pre.status = 'terlambat' THEN 1 ELSE 0 END) as terlambat,
                    SUM(CASE WHEN pre.id_anggota IS NOT NULL AND pre.status = 'pulang cepat' THEN 1 ELSE 0 END) as pulang_cepat,
                    SUM(CASE WHEN pre.id_anggota IS NOT NULL AND pre.jam_pulang IS NULL THEN 1 ELSE 0 END) as belum_pulang
                  FROM anggota_kelompok ak
                  JOIN kelompok_pkl kp ON ak.id_kelompok = kp.id_kelompok
                  LEFT JOIN perizinan per ON ak.id_anggota = per.id_anggota 
                    AND per.tanggal_mulai <= ? AND per.tanggal_selesai >= ? 
                    AND per.status = 'disetujui'
                  LEFT JOIN presensi pre ON ak.id_anggota = pre.id_anggota AND pre.tanggal = ?
                  WHERE kp.id_industri = (SELECT id_industri FROM pembimbing_industri WHERE id_pembimbing_industri = ?)";
        
        $result = query($query, [$tanggal, $tanggal, $tanggal, $id_pembimbing]);
    }
    
    if ($result && $result->num_rows > 0) {
        $data = $result->fetch_assoc();
        $rekap['ijin'] = $data['ijin'] ?? 0;
        $rekap['sakit'] = $data['sakit'] ?? 0;
        $rekap['tepat_waktu'] = $data['tepat_waktu'] ?? 0;
        $rekap['terlambat'] = $data['terlambat'] ?? 0;
        $rekap['pulang_cepat'] = $data['pulang_cepat'] ?? 0;
        $rekap['belum_pulang'] = $data['belum_pulang'] ?? 0;
        
        // Hitung yang absen (tidak ada presensi dan tidak ada izin)
        $total_siswa = $data['total_siswa'] ?? 0;
        $hadir = ($rekap['tepat_waktu'] + $rekap['terlambat'] + $rekap['pulang_cepat'] + $rekap['belum_pulang']);
        $total_izin = ($rekap['ijin'] + $rekap['sakit']);
        $rekap['absen'] = max(0, $total_siswa - $hadir - $total_izin);
    }
    
    return $rekap;
}

// Fungsi untuk mendapatkan rekap presensi bulanan siswa
function getRekapPresensiBulananSiswa($id_siswa, $bulan, $tahun) {
    $rekap = [
        'ijin' => 0,
        'sakit' => 0,
        'absen' => 0,
        'tepat_waktu' => 0,
        'terlambat' => 0,
        'pulang_cepat' => 0,
        'belum_pulang' => 0
    ];
    
    // Dapatkan id_anggota
    $anggota_result = query("SELECT id_anggota FROM anggota_kelompok WHERE id_siswa = ?", [$id_siswa]);
    
    if ($anggota_result && $anggota_result->num_rows > 0) {
        $id_anggota = $anggota_result->fetch_assoc()['id_anggota'];
        
        // Hitung perizinan
        $izin_query = "SELECT jenis, COUNT(*) as jumlah 
                       FROM perizinan 
                       WHERE id_anggota = ? 
                         AND MONTH(tanggal_mulai) = ? AND YEAR(tanggal_mulai) = ?
                         AND status = 'disetujui'
                       GROUP BY jenis";
        
        $izin_result = query($izin_query, [$id_anggota, $bulan, $tahun]);
        
        if ($izin_result) {
            while ($row = $izin_result->fetch_assoc()) {
                if ($row['jenis'] === 'ijin') {
                    $rekap['ijin'] = $row['jumlah'];
                } elseif ($row['jenis'] === 'sakit') {
                    $rekap['sakit'] = $row['jumlah'];
                }
            }
        }
        
        // Hitung presensi
        $presensi_query = "SELECT 
                            SUM(CASE WHEN status = 'tepat waktu' THEN 1 ELSE 0 END) as tepat_waktu,
                            SUM(CASE WHEN status = 'terlambat' THEN 1 ELSE 0 END) as terlambat,
                            SUM(CASE WHEN status = 'pulang cepat' THEN 1 ELSE 0 END) as pulang_cepat,
                            SUM(CASE WHEN jam_pulang IS NULL THEN 1 ELSE 0 END) as belum_pulang
                           FROM presensi 
                           WHERE id_anggota = ? 
                             AND MONTH(tanggal) = ? AND YEAR(tanggal) = ?";
        
        $presensi_result = query($presensi_query, [$id_anggota, $bulan, $tahun]);
        
        if ($presensi_result && $presensi_result->num_rows > 0) {
            $data = $presensi_result->fetch_assoc();
            $rekap['tepat_waktu'] = $data['tepat_waktu'] ?? 0;
            $rekap['terlambat'] = $data['terlambat'] ?? 0;
            $rekap['pulang_cepat'] = $data['pulang_cepat'] ?? 0;
            $rekap['belum_pulang'] = $data['belum_pulang'] ?? 0;
        }
        
        // Hitung hari kerja dalam bulan (tanpa minggu)
        $total_hari_kerja = getHariKerjaBulan($bulan, $tahun);
        
        // Hitung yang absen
        $total_presensi = $rekap['tepat_waktu'] + $rekap['terlambat'] + $rekap['pulang_cepat'] + $rekap['belum_pulang'];
        $total_izin = $rekap['ijin'] + $rekap['sakit'];
        $rekap['absen'] = max(0, $total_hari_kerja - $total_presensi - $total_izin);
    }
    
    return $rekap;
}

// Fungsi untuk menghitung hari kerja dalam bulan (Senin-Jumat)
function getHariKerjaBulan($bulan, $tahun) {
    $jumlah = 0;
    // Dapatkan jumlah hari dalam bulan
    $jumlah_hari = date('t', strtotime("$tahun-$bulan-01"));
    
    for ($i = 1; $i <= $jumlah_hari; $i++) {
        $tanggal = "$tahun-$bulan-$i";
        $hari_minggu = date('N', strtotime($tanggal));
        
        // Jika bukan Sabtu (6) dan Minggu (7)
        if ($hari_minggu < 6) {
            $jumlah++;
        }
    }
    
    return $jumlah;
}

// Ambil data rekap presensi berdasarkan role
if (isset($_SESSION['user_id'])) {
    $tanggal_hari_ini = date('Y-m-d');
    $bulan_ini = date('n');
    $tahun_ini = date('Y');
    
    switch ($_SESSION['role']) {
        case 'pembimbing_sekolah':
        case 'pembimbing_industri':
            $rekapPresensiHarian = getRekapPresensiHarianPembimbing($_SESSION['id_referensi'], $_SESSION['role'], $tanggal_hari_ini);
            break;
            
        case 'siswa':
            $rekapPresensiBulanan = getRekapPresensiBulananSiswa($_SESSION['id_referensi'], $bulan_ini, $tahun_ini);
            break;
    }
}

// Panggil fungsi rekap perizinan setelah userData diinisialisasi
if (isset($_SESSION['user_id'])) {
    $rekapPerizinan = getRekapPerizinan($_SESSION['role'], $_SESSION['id_referensi']);
}

// Jika sudah login, ambil data user
if (isset($_SESSION['user_id'])) {
    $userData = getUserData($_SESSION['role'], $_SESSION['id_referensi']);
}

// Handle CRUD operations
$action = $_GET['action'] ?? '';
$table = $_GET['table'] ?? '';
$id = $_GET['id'] ?? '';

// Fungsi untuk mendapatkan daftar data berdasarkan table
function getTableData($table) {
    $allowedTables = ['sekolah', 'guru', 'siswa', 'industri', 'kelompok_pkl', 'pembimbing_industri', 'jurusan', 'kelas'];
    
    if (!in_array($table, $allowedTables)) {
        return ['data' => [], 'columns' => []];
    }
    
    $result = query("SELECT * FROM $table");
    $data = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $data[] = $row;
        }
    }
    
    // Get column names
    $columns = [];
    if (!empty($data)) {
        $columns = array_keys($data[0]);
    }
    
    return ['data' => $data, 'columns' => $columns];
}

// Handle form submissions for CRUD
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['csrf_token']) && validateCsrfToken($_POST['csrf_token'])) {
    // Handle add operation
    if (isset($_POST['add'])) {
        $table = validate_input($_POST['table']);
        $data = [];
        
        foreach ($_POST['data'] as $key => $value) {
            $data[$key] = validate_input($value);
        }
        
        // Handle file uploads
        if (($table === 'guru' || $table === 'siswa' || $table === 'pembimbing_industri') && isset($_FILES['foto']) && $_FILES['foto']['error'] === UPLOAD_ERR_OK) {
            $foto_name = uniqid() . '_' . basename($_FILES['foto']['name']);
            $foto_path = 'uploads/profil/' . $foto_name;
            
            // Validasi tipe file
            $allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
            $file_type = $_FILES['foto']['type'];
            
            if (in_array($file_type, $allowed_types) && move_uploaded_file($_FILES['foto']['tmp_name'], $foto_path)) {
                $data['foto'] = $foto_path;
            }
        }
        
        $columns = implode(', ', array_keys($data));
        $placeholders = implode(', ', array_fill(0, count($data), '?'));
        $values = array_values($data);
        
        $sql = "INSERT INTO $table ($columns) VALUES ($placeholders)";
        if (query($sql, $values)) {
            $_SESSION['success'] = "Data berhasil ditambahkan";
        } else {
            $_SESSION['error'] = "Gagal menambahkan data";
        }
        
        header("Location: index.php?table=$table");
        exit();
    } 
    // Handle edit operation
    elseif (isset($_POST['edit'])) {
        $table = validate_input($_POST['table']);
        $id = validate_input($_POST['id'], 'int');
        $data = [];
        
        foreach ($_POST['data'] as $key => $value) {
            $data[$key] = validate_input($value);
        }
        
        // Handle file uploads
        if (($table === 'guru' || $table === 'siswa' || $table === 'pembimbing_industri') && isset($_FILES['foto']) && $_FILES['foto']['error'] === UPLOAD_ERR_OK) {
            $foto_name = uniqid() . '_' . basename($_FILES['foto']['name']);
            $foto_path = 'uploads/profil/' . $foto_name;
            
            // Validasi tipe file
            $allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
            $file_type = $_FILES['foto']['type'];
            
            if (in_array($file_type, $allowed_types) && move_uploaded_file($_FILES['foto']['tmp_name'], $foto_path)) {
                $data['foto'] = $foto_path;
                
                // Hapus foto lama jika ada
                if (!empty($_POST['old_foto'])) {
                    @unlink($_POST['old_foto']);
                }
            }
        }
        
        $setClause = implode(' = ?, ', array_keys($data)) . ' = ?';
        $values = array_values($data);
        $values[] = $id;
        
        $sql = "UPDATE $table SET $setClause WHERE id_" . $table . " = ?";
        if (query($sql, $values)) {
            $_SESSION['success'] = "Data berhasil diperbarui";
        } else {
            $_SESSION['error'] = "Gagal memperbarui data";
        }
        
        header("Location: index.php?table=$table");
        exit();
    } 
    // Handle delete operation
    elseif (isset($_POST['delete'])) {
        $table = validate_input($_POST['table']);
        $id = validate_input($_POST['id'], 'int');
        
        // Hapus file terkait jika ada
        if (($table === 'guru' || $table === 'siswa' || $table === 'pembimbing_industri')) {
            $result = query("SELECT foto FROM $table WHERE id_" . $table . " = ?", [$id]);
            if ($result && $row = $result->fetch_assoc()) {
                if (!empty($row['foto'])) {
                    @unlink($row['foto']);
                }
            }
        }
        
        $sql = "DELETE FROM $table WHERE id_" . $table . " = ?";
        if (query($sql, [$id])) {
            $_SESSION['success'] = "Data berhasil dihapus";
        } else {
            $_SESSION['error'] = "Gagal menghapus data";
        }
        
        header("Location: index.php?table=$table");
        exit();
    } 
    // Handle approval perizinan
    elseif (isset($_POST['approve_izin'])) {
        $id_izin = validate_input($_POST['id_izin'], 'int');
        $status = validate_input($_POST['status']);
        $catatan = validate_input($_POST['catatan']);
        $role = $_SESSION['role'];
        
        if ($role === 'pembimbing_sekolah') {
            $sql = "UPDATE perizinan SET status = ?, catatan_pembimbing_sekolah = ? WHERE id_izin = ?";
            if (query($sql, [$status, $catatan, $id_izin])) {
                $_SESSION['success'] = "Status perizinan berhasil diperbarui";
            } else {
                $_SESSION['error'] = "Gagal memperbarui status perizinan";
            }
        } elseif ($role === 'pembimbing_industri') {
            $sql = "UPDATE perizinan SET status = ?, catatan_pembimbing_industri = ? WHERE id_izin = ?";
            if (query($sql, [$status, $catatan, $id_izin])) {
                $_SESSION['success'] = "Status perizinan berhasil diperbarui";
            } else {
                $_SESSION['error'] = "Gagal memperbarui status perizinan";
            }
        }
        
        header("Location: index.php?action=monitoring");
        exit();
    } 
    // Handle update perizinan oleh siswa
    elseif (isset($_POST['update_izin'])) {
        if ($_SESSION['role'] === 'siswa') {
            $id_izin = validate_input($_POST['id_izin'], 'int');
            $tanggal_mulai = validate_input($_POST['tanggal_mulai'], 'date');
            $tanggal_selesai = validate_input($_POST['tanggal_selesai'], 'date');
            $jenis = validate_input($_POST['jenis']);
            $alasan = validate_input($_POST['alasan']);
            
            // Validasi tanggal
            if ($tanggal_mulai > $tanggal_selesai) {
                $_SESSION['error'] = "Tanggal mulai tidak boleh setelah tanggal selesai";
                header("Location: index.php?action=perizinan");
                exit();
            }
            
            // Dapatkan id_anggota
            $siswa_id = $_SESSION['id_referensi'];
            $anggota = query("SELECT id_anggota FROM anggota_kelompok WHERE id_siswa = ?", [$siswa_id]);
            
            if ($anggota && $anggota->num_rows === 1) {
                $id_anggota = $anggota->fetch_assoc()['id_anggota'];
                
                // Check if owned by user and status menunggu
                $izin = query("SELECT * FROM perizinan WHERE id_izin = ? AND id_anggota = ? AND status = 'menunggu'", [$id_izin, $id_anggota]);
                
                if ($izin && $izin->num_rows === 1) {
                    $current_izin = $izin->fetch_assoc();
                    $upload_dir = $jenis === 'ijin' ? 'uploads/lampiran/ijin/' : 'uploads/lampiran/sakit/';
                    ensureDirectory($upload_dir);
                    
                    $lampiran_path = $current_izin['lampiran'];
                    if (isset($_FILES['lampiran']) && $_FILES['lampiran']['error'] === UPLOAD_ERR_OK) {
                        // Validasi tipe file
                        $allowed_types = ['image/jpeg', 'image/png', 'application/pdf'];
                        $file_type = $_FILES['lampiran']['type'];
                        
                        if (in_array($file_type, $allowed_types)) {
                            $lampiran_name = uniqid() . '_' . basename($_FILES['lampiran']['name']);
                            $lampiran_path = $upload_dir . $lampiran_name;
                            
                            if (move_uploaded_file($_FILES['lampiran']['tmp_name'], $lampiran_path)) {
                                // Hapus lampiran lama jika ada
                                if (!empty($current_izin['lampiran'])) {
                                    @unlink($current_izin['lampiran']);
                                }
                            } else {
                                $lampiran_path = $current_izin['lampiran']; // Tetap gunakan yang lama jika upload gagal
                            }
                        }
                    }
                    
                    if (query("UPDATE perizinan SET tanggal_mulai = ?, tanggal_selesai = ?, jenis = ?, alasan = ?, lampiran = ? WHERE id_izin = ?", 
                              [$tanggal_mulai, $tanggal_selesai, $jenis, $alasan, $lampiran_path, $id_izin])) {
                        $_SESSION['success'] = "Perizinan berhasil diperbarui";
                    } else {
                        $_SESSION['error'] = "Gagal memperbarui perizinan";
                    }
                }
            }
            
            header("Location: index.php?action=perizinan");
            exit();
        }
    } 
    // Handle delete perizinan oleh siswa
    elseif (isset($_POST['delete_izin'])) {
        if ($_SESSION['role'] === 'siswa') {
            $id_izin = validate_input($_POST['id_izin'], 'int');
            
            // Dapatkan id_anggota
            $siswa_id = $_SESSION['id_referensi'];
            $anggota = query("SELECT id_anggota FROM anggota_kelompok WHERE id_siswa = ?", [$siswa_id]);
            
            if ($anggota && $anggota->num_rows === 1) {
                $id_anggota = $anggota->fetch_assoc()['id_anggota'];
                
                // Check if owned by user and status menunggu
                $izin = query("SELECT * FROM perizinan WHERE id_izin = ? AND id_anggota = ? AND status = 'menunggu'", [$id_izin, $id_anggota]);
                
                if ($izin && $izin->num_rows === 1) {
                    $current_izin = $izin->fetch_assoc();
                    
                    // Hapus lampiran jika ada
                    if (!empty($current_izin['lampiran'])) {
                        @unlink($current_izin['lampiran']);
                    }
                    
                    if (query("DELETE FROM perizinan WHERE id_izin = ?", [$id_izin])) {
                        $_SESSION['success'] = "Perizinan berhasil dihapus";
                    } else {
                        $_SESSION['error'] = "Gagal menghapus perizinan";
                    }
                }
            }
            
            header("Location: index.php?action=perizinan");
            exit();
        }
    }
}

// Handle presensi
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_presensi']) && validateCsrfToken($_POST['csrf_token'])) {
    if ($_SESSION['role'] === 'siswa') {
        $jenis_presensi = validate_input($_POST['jenis_presensi']);
        $latitude = validate_input($_POST['latitude'], 'float');
        $longitude = validate_input($_POST['longitude'], 'float');
        $foto_data = $_POST['foto_data'];
        
        // Validasi data
        if (empty($latitude) || empty($longitude) || empty($foto_data)) {
            $_SESSION['error'] = "Data presensi tidak lengkap";
            header("Location: index.php");
            exit();
        }
        
        // Tentukan direktori berdasarkan jenis presensi
        $upload_dir = $jenis_presensi === 'masuk' ? 'uploads/presensi/masuk/' : 'uploads/presensi/pulang/';
        ensureDirectory($upload_dir);
        
        // Simpan foto ke server
        $foto_path = $upload_dir . uniqid() . '.png';
        $image_data = base64_decode(preg_replace('#^data:image/\w+;base64,#i', '', $foto_data));
        
        if (file_put_contents($foto_path, $image_data) === false) {
            $_SESSION['error'] = "Gagal menyimpan foto presensi";
            header("Location: index.php");
            exit();
        }
        
        // Dapatkan id_anggota dari siswa
        $siswa_id = $_SESSION['id_referensi'];
        $anggota = query("SELECT id_anggota FROM anggota_kelompok WHERE id_siswa = ?", [$siswa_id]);
        
        if (!$anggota || $anggota->num_rows !== 1) {
            $_SESSION['error'] = "Data siswa tidak valid";
            header("Location: index.php");
            exit();
        }
        
        $id_anggota = $anggota->fetch_assoc()['id_anggota'];
        
        // Tentukan status presensi
        $waktu_sekarang = date('H:i:s');
        $status = 'tepat waktu';
        
        if ($jenis_presensi == 'masuk') {
            // Dapatkan jam masuk yang seharusnya
            $kelompok = query("SELECT jam_masuk FROM kelompok_pkl kp 
                             JOIN anggota_kelompok ak ON kp.id_kelompok = ak.id_kelompok 
                             WHERE ak.id_anggota = ?", [$id_anggota]);
            
            if ($kelompok && $kelompok->num_rows === 1) {
                $jam_masuk = $kelompok->fetch_assoc()['jam_masuk'];
                if ($waktu_sekarang > $jam_masuk) {
                    $status = 'terlambat';
                }
            }
            
            // Simpan presensi masuk
            $result = query("INSERT INTO presensi (id_anggota, tanggal, jam_masuk, status, foto_masuk, latitude, longitude) 
                           VALUES (?, CURDATE(), ?, ?, ?, ?, ?)
                           ON DUPLICATE KEY UPDATE jam_masuk = ?, status = ?, 
                           foto_masuk = ?, latitude = ?, longitude = ?", 
                           [$id_anggota, $waktu_sekarang, $status, $foto_path, $latitude, $longitude,
                            $waktu_sekarang, $status, $foto_path, $latitude, $longitude]);
        } else {
            // Dapatkan jam pulang yang seharusnya
            $kelompok = query("SELECT jam_pulang FROM kelompok_pkl kp 
                             JOIN anggota_kelompok ak ON kp.id_kelompok = ak.id_kelompok 
                             WHERE ak.id_anggota = ?", [$id_anggota]);
            
            if ($kelompok && $kelompok->num_rows === 1) {
                $jam_pulang = $kelompok->fetch_assoc()['jam_pulang'];
                if ($waktu_sekarang < $jam_pulang) {
                    $status = 'pulang cepat';
                }
            }
            
            // Simpan presensi pulang
            $result = query("UPDATE presensi SET jam_pulang = ?, foto_pulang = ?, status = ? 
                           WHERE id_anggota = ? AND tanggal = CURDATE()", 
                           [$waktu_sekarang, $foto_path, $status, $id_anggota]);
        }
        
        if ($result) {
            $_SESSION['success'] = "Presensi berhasil dicatat";
        } else {
            $_SESSION['error'] = "Gagal mencatat presensi";
        }
        
        // Redirect untuk menghindari resubmit form
        header("Location: index.php");
        exit();
    }
}

// Handle perizinan
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_izin']) && validateCsrfToken($_POST['csrf_token'])) {
    if ($_SESSION['role'] === 'siswa') {
        $tanggal_mulai = validate_input($_POST['tanggal_mulai'], 'date');
        $tanggal_selesai = validate_input($_POST['tanggal_selesai'], 'date');
        $jenis = validate_input($_POST['jenis']);
        $alasan = validate_input($_POST['alasan']);
        
        // Validasi tanggal
        if ($tanggal_mulai > $tanggal_selesai) {
            $_SESSION['error'] = "Tanggal mulai tidak boleh setelah tanggal selesai";
            header("Location: index.php?action=perizinan");
            exit();
        }
        
        // Validasi alasan
        if (empty($alasan)) {
            $_SESSION['error'] = "Alasan harus diisi";
            header("Location: index.php?action=perizinan");
            exit();
        }
        
        // Tentukan direktori berdasarkan jenis izin
        $upload_dir = $jenis === 'ijin' ? 'uploads/lampiran/ijin/' : 'uploads/lampiran/sakit/';
        ensureDirectory($upload_dir);
        
        $lampiran_path = null;
        // Handle file upload
        if (isset($_FILES['lampiran']) && $_FILES['lampiran']['error'] === UPLOAD_ERR_OK) {
            // Validasi tipe file
            $allowed_types = ['image/jpeg', 'image/png', 'application/pdf'];
            $file_type = $_FILES['lampiran']['type'];
            
            if (in_array($file_type, $allowed_types)) {
                $lampiran_name = uniqid() . '_' . basename($_FILES['lampiran']['name']);
                $lampiran_path = $upload_dir . $lampiran_name;
                
                if (!move_uploaded_file($_FILES['lampiran']['tmp_name'], $lampiran_path)) {
                    $lampiran_path = null; // Tetap null jika upload gagal
                }
            }
        }
        
        // Dapatkan id_anggota dari siswa
        $siswa_id = $_SESSION['id_referensi'];
        $anggota = query("SELECT id_anggota FROM anggota_kelompok WHERE id_siswa = ?", [$siswa_id]);
        
        if (!$anggota || $anggota->num_rows !== 1) {
            $_SESSION['error'] = "Data siswa tidak valid";
            header("Location: index.php?action=perizinan");
            exit();
        }
        
        $id_anggota = $anggota->fetch_assoc()['id_anggota'];
        
        // Cek duplikasi pengajuan untuk rentang tanggal yang sama
        $existing_izin = query("SELECT id_izin FROM perizinan 
                               WHERE id_anggota = ? 
                               AND (
                                   (tanggal_mulai BETWEEN ? AND ?) 
                                   OR (tanggal_selesai BETWEEN ? AND ?)
                                   OR (? BETWEEN tanggal_mulai AND tanggal_selesai)
                                   OR (? BETWEEN tanggal_mulai AND tanggal_selesai)
                               ) AND status != 'ditolak'",
                               [$id_anggota, $tanggal_mulai, $tanggal_selesai, 
                                $tanggal_mulai, $tanggal_selesai,
                                $tanggal_mulai, $tanggal_selesai]);
        
        if ($existing_izin && $existing_izin->num_rows > 0) {
            $_SESSION['error'] = "Sudah ada pengajuan perizinan pada rentang tanggal tersebut";
            header("Location: index.php?action=perizinan");
            exit();
        }
        
        // Simpan perizinan
        $result = query("INSERT INTO perizinan (id_anggota, tanggal_pengajuan, tanggal_mulai, tanggal_selesai, jenis, alasan, lampiran) 
               VALUES (?, CURDATE(), ?, ?, ?, ?, ?)", 
               [$id_anggota, $tanggal_mulai, $tanggal_selesai, $jenis, $alasan, $lampiran_path]);
        
        if ($result) {
            $_SESSION['success'] = "Perizinan berhasil diajukan";
        } else {
            $_SESSION['error'] = "Gagal mengajukan perizinan";
        }
        
        // Redirect untuk menghindari resubmit form
        header("Location: index.php?action=perizinan");
        exit();
    }
}

// Ambil data untuk dashboard berdasarkan role
if (isset($_SESSION['user_id'])) {
    switch ($_SESSION['role']) {
        case 'siswa':
            $siswa_id = $_SESSION['id_referensi'];
            $siswa_data_result = query("SELECT s.*, k.nama_kelas, j.nama_jurusan, sk.nama_sekolah, 
                                ind.nama_industri, pi.nama_pembimbing as pembimbing_industri,
                                g.nama_guru as pembimbing_sekolah,
                                ind.latitude as industri_latitude, ind.longitude as industri_longitude, ind.radius_area
                                FROM siswa s 
                                JOIN kelas k ON s.id_kelas = k.id_kelas
                                JOIN jurusan j ON k.id_jurusan = j.id_jurusan
                                JOIN sekolah sk ON j.id_sekolah = sk.id_sekolah
                                JOIN anggota_kelompok ak ON s.id_siswa = ak.id_siswa
                                JOIN kelompok_pkl kp ON ak.id_kelompok = kp.id_kelompok
                                JOIN industri ind ON kp.id_industri = ind.id_industri
                                JOIN pembimbing_industri pi ON ind.id_industri = pi.id_industri
                                JOIN guru g ON kp.id_guru = g.id_guru
                                WHERE s.id_siswa = ?", [$siswa_id]);
            
            $siswa_data = $siswa_data_result ? $siswa_data_result->fetch_assoc() : [];
            
            // Ambil riwayat presensi
            $anggota = query("SELECT id_anggota FROM anggota_kelompok WHERE id_siswa = ?", [$siswa_id]);
            $id_anggota = $anggota && $anggota->num_rows === 1 ? $anggota->fetch_assoc()['id_anggota'] : null;
            
            $riwayat_presensi = $id_anggota ? query("SELECT * FROM presensi WHERE id_anggota = ? ORDER BY tanggal DESC LIMIT 5", [$id_anggota]) : null;
            
            // Presensi hari ini
            $presensi_hari_ini = $id_anggota ? query("SELECT * FROM presensi WHERE id_anggota = ? AND tanggal = CURDATE()", [$id_anggota])->fetch_assoc() : null;
            
            // Perizinan
            $riwayat_perizinan = $id_anggota ? query("SELECT * FROM perizinan WHERE id_anggota = ? ORDER BY tanggal_pengajuan DESC LIMIT 5", [$id_anggota]) : null;
            
            // Pass industrial coordinates and radius to JavaScript
            $industri_latitude = $siswa_data['industri_latitude'] ?? -7.2575; // Fallback if null
            $industri_longitude = $siswa_data['industri_longitude'] ?? 112.7521; // Fallback if null
            $radius_area = $siswa_data['radius_area'] ?? 100; // Fallback if null
            break;
            
        case 'admin':
            // Data statistik untuk admin
            $jumlah_siswa_result = query("SELECT COUNT(*) as total FROM siswa");
            $jumlah_siswa = $jumlah_siswa_result ? $jumlah_siswa_result->fetch_assoc()['total'] : 0;
            
            $jumlah_guru_result = query("SELECT COUNT(*) as total FROM guru");
            $jumlah_guru = $jumlah_guru_result ? $jumlah_guru_result->fetch_assoc()['total'] : 0;
            
            $jumlah_industri_result = query("SELECT COUNT(*) as total FROM industri");
            $jumlah_industri = $jumlah_industri_result ? $jumlah_industri_result->fetch_assoc()['total'] : 0;
            
            $jumlah_kelompok_result = query("SELECT COUNT(*) as total FROM kelompok_pkl");
            $jumlah_kelompok = $jumlah_kelompok_result ? $jumlah_kelompok_result->fetch_assoc()['total'] : 0;
            break;
            
        /*case 'pembimbing_sekolah':
            $guru_id = $_SESSION['id_referensi'];
            $guru_data_result = query("SELECT g.*, s.nama_sekolah FROM guru g 
                               JOIN sekolah s ON g.id_sekolah = s.id_sekolah 
                               WHERE g.id_guru = ?", [$guru_id]);
            $guru_data = $guru_data_result ? $guru_data_result->fetch_assoc() : [];
            
            // Dapatkan kelompok yang dibimbing
            $kelompok = query("SELECT kp.*, i.nama_industri, COUNT(ak.id_siswa) as jumlah_siswa
                              FROM kelompok_pkl kp
                              JOIN industri i ON kp.id_industri = i.id_industri
                              LEFT JOIN anggota_kelompok ak ON kp.id_kelompok = ak.id_kelompok
                              WHERE kp.id_guru = ?
                              GROUP BY kp.id_kelompok", [$guru_id]);
            
            // Ambil perizinan pending untuk siswa di kelompok ini
            $pending_perizinan = query("SELECT per.*, s.nama_siswa
                                       FROM perizinan per
                                       JOIN anggota_kelompok ak ON per.id_anggota = ak.id_anggota
                                       JOIN kelompok_pkl kp ON ak.id_kelompok = kp.id_kelompok
                                       JOIN siswa s ON ak.id_siswa = s.id_siswa
                                       WHERE kp.id_guru = ? AND per.status = 'menunggu'", [$guru_id]);
            
            // Handle filter presensi harian
            $filter_tanggal = isset($_POST['filter_tanggal']) ? validate_input($_POST['filter_tanggal'], 'date') : date('Y-m-d');
            $presensi_harian = query("SELECT p.*, s.nama_siswa
                                     FROM presensi p
                                     JOIN anggota_kelompok ak ON p.id_anggota = ak.id_anggota
                                     JOIN kelompok_pkl kp ON ak.id_kelompok = kp.id_kelompok
                                     JOIN siswa s ON ak.id_siswa = s.id_siswa
                                     WHERE kp.id_guru = ? AND p.tanggal = ?", [$guru_id, $filter_tanggal]);
            break;
            */
        case 'pembimbing_sekolah':
            $guru_id = $_SESSION['id_referensi'];
            $guru_data_result = query("SELECT g.*, s.nama_sekolah FROM guru g 
                               JOIN sekolah s ON g.id_sekolah = s.id_sekolah 
                               WHERE g.id_guru = ?", [$guru_id]);
            $guru_data = $guru_data_result ? $guru_data_result->fetch_assoc() : [];
            
            // Dapatkan kelompok yang dibimbing
            $kelompok = query("SELECT kp.*, i.nama_industri, COUNT(ak.id_siswa) as jumlah_siswa
                              FROM kelompok_pkl kp
                              JOIN industri i ON kp.id_industri = i.id_industri
                              LEFT JOIN anggota_kelompok ak ON kp.id_kelompok = ak.id_kelompok
                              WHERE kp.id_guru = ?
                              GROUP BY kp.id_kelompok", [$guru_id]);
            
            // Ambil perizinan pending untuk siswa di kelompok ini
            $pending_perizinan = query("SELECT per.*, s.nama_siswa
                                       FROM perizinan per
                                       JOIN anggota_kelompok ak ON per.id_anggota = ak.id_anggota
                                       JOIN kelompok_pkl kp ON ak.id_kelompok = kp.id_kelompok
                                       JOIN siswa s ON ak.id_siswa = s.id_siswa
                                       WHERE kp.id_guru = ? AND per.status = 'menunggu'", [$guru_id]);
            
            // Handle filter presensi harian
            $filter_tanggal = isset($_POST['filter_tanggal']) ? validate_input($_POST['filter_tanggal'], 'date') : date('Y-m-d');
            $presensi_harian = query("SELECT p.*, s.nama_siswa
                                     FROM presensi p
                                     JOIN anggota_kelompok ak ON p.id_anggota = ak.id_anggota
                                     JOIN kelompok_pkl kp ON ak.id_kelompok = kp.id_kelompok
                                     JOIN siswa s ON ak.id_siswa = s.id_siswa
                                     WHERE kp.id_guru = ? AND p.tanggal = ?", [$guru_id, $filter_tanggal]);
            
            // Rekap presensi harian
            $rekapPresensiHarian = getRekapPresensiHarianPembimbing($guru_id, $_SESSION['role'], $filter_tanggal);
            break;

            
        /*case 'pembimbing_industri':
            $pembimbing_id = $_SESSION['id_referensi'];
            $pembimbing_data_result = query("SELECT pi.*, i.nama_industri, i.id_industri FROM pembimbing_industri pi 
                                     JOIN industri i ON pi.id_industri = i.id_industri 
                                     WHERE pi.id_pembimbing_industri = ?", [$pembimbing_id]);
            $pembimbing_data = $pembimbing_data_result ? $pembimbing_data_result->fetch_assoc() : [];
            
            // Dapatkan kelompok di industri tersebut
            $kelompok = query("SELECT kp.*, g.nama_guru as pembimbing_sekolah, COUNT(ak.id_siswa) as jumlah_siswa
                              FROM kelompok_pkl kp
                              JOIN guru g ON kp.id_guru = g.id_guru
                              LEFT JOIN anggota_kelompok ak ON kp.id_kelompok = ak.id_kelompok
                              WHERE kp.id_industri = ?
                              GROUP BY kp.id_kelompok", [$pembimbing_data['id_industri']]);
            
            // Ambil perizinan pending untuk siswa di kelompok ini
            $pending_perizinan = query("SELECT per.*, s.nama_siswa
                                       FROM perizinan per
                                       JOIN anggota_kelompok ak ON per.id_anggota = ak.id_anggota
                                       JOIN kelompok_pkl kp ON ak.id_kelompok = kp.id_kelompok
                                       JOIN siswa s ON ak.id_siswa = s.id_siswa
                                       WHERE kp.id_industri = ? AND per.status = 'menunggu'", [$pembimbing_data['id_industri']]);
            
            // Handle filter presensi harian
            $filter_tanggal = isset($_POST['filter_tanggal']) ? validate_input($_POST['filter_tanggal'], 'date') : date('Y-m-d');
            $presensi_harian = query("SELECT p.*, s.nama_siswa
                                     FROM presensi p
                                     JOIN anggota_kelompok ak ON p.id_anggota = ak.id_anggota
                                     JOIN kelompok_pkl kp ON ak.id_kelompok = kp.id_kelompok
                                     JOIN siswa s ON ak.id_siswa = s.id_siswa
                                     WHERE kp.id_industri = ? AND p.tanggal = ?", [$pembimbing_data['id_industri'], $filter_tanggal]);
            break;
        */
        case 'pembimbing_industri':
            $pembimbing_id = $_SESSION['id_referensi'];
            $pembimbing_data_result = query("SELECT pi.*, i.nama_industri, i.id_industri FROM pembimbing_industri pi 
                                     JOIN industri i ON pi.id_industri = i.id_industri 
                                     WHERE pi.id_pembimbing_industri = ?", [$pembimbing_id]);
            $pembimbing_data = $pembimbing_data_result ? $pembimbing_data_result->fetch_assoc() : [];
            
            // Dapatkan kelompok di industri tersebut
            $kelompok = query("SELECT kp.*, g.nama_guru as pembimbing_sekolah, COUNT(ak.id_siswa) as jumlah_siswa
                              FROM kelompok_pkl kp
                              JOIN guru g ON kp.id_guru = g.id_guru
                              LEFT JOIN anggota_kelompok ak ON kp.id_kelompok = ak.id_kelompok
                              WHERE kp.id_industri = ?
                              GROUP BY kp.id_kelompok", [$pembimbing_data['id_industri']]);
            
            // Ambil perizinan pending untuk siswa di kelompok ini
            $pending_perizinan = query("SELECT per.*, s.nama_siswa
                                       FROM perizinan per
                                       JOIN anggota_kelompok ak ON per.id_anggota = ak.id_anggota
                                       JOIN kelompok_pkl kp ON ak.id_kelompok = kp.id_kelompok
                                       JOIN siswa s ON ak.id_siswa = s.id_siswa
                                       WHERE kp.id_industri = ? AND per.status = 'menunggu'", [$pembimbing_data['id_industri']]);
            
            // Handle filter presensi harian
            $filter_tanggal = isset($_POST['filter_tanggal']) ? validate_input($_POST['filter_tanggal'], 'date') : date('Y-m-d');
            $presensi_harian = query("SELECT p.*, s.nama_siswa
                                     FROM presensi p
                                     JOIN anggota_kelompok ak ON p.id_anggota = ak.id_anggota
                                     JOIN kelompok_pkl kp ON ak.id_kelompok = kp.id_kelompok
                                     JOIN siswa s ON ak.id_siswa = s.id_siswa
                                     WHERE kp.id_industri = ? AND p.tanggal = ?", [$pembimbing_data['id_industri'], $filter_tanggal]);
            
            // Rekap presensi harian
            $rekapPresensiHarian = getRekapPresensiHarianPembimbing($pembimbing_id, $_SESSION['role'], $filter_tanggal);
            break;
    }
}

// Generate CSRF token
$csrf_token = generateCsrfToken();

// Tampilkan notifikasi
$success_message = $_SESSION['success'] ?? '';
$error_message = $_SESSION['error'] ?? '';
unset($_SESSION['success']);
unset($_SESSION['error']);
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sistem Presensi PKL</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.7.1/dist/leaflet.css" />
    <style>
        body {
            background-color: #f8f9fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .navbar-brand {
            font-weight: bold;
        }
        .card {
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
            border-radius: 10px;
            margin-bottom: 20px;
        }
        .card-header {
            border-radius: 10px 10px 0 0 !important;
            font-weight: bold;
        }
        #map {
            height: 300px;
            border-radius: 8px;
        }
        #video-container {
            position: relative;
            width: 100%;
            background-color: #000;
            border-radius: 8px;
            overflow: hidden;
        }
        #video {
            width: 100%;
            height: auto;
        }
        #capture-btn {
            position: absolute;
            bottom: 20px;
            left: 50%;
            transform: translateX(-50%);
            z-index: 100;
        }
        .presence-status {
            font-size: 1.2rem;
            font-weight: bold;
            padding: 10px;
            border-radius: 8px;
            text-align: center;
        }
        .status-tepat-waktu {
            background-color: #d4edda;
            color: #155724;
        }
        .status-terlambat {
            background-color: #fff3cd;
            color: #856404;
        }
        .status-pulang-cepat {
            background-color: #f8d7da;
            color: #721c24;
        }
        .history-item {
            border-left: 4px solid #007bff;
            padding-left: 15px;
            margin-bottom: 15px;
        }
        .footer {
            background-color: #343a40;
            color: white;
            padding: 15px 0;
            margin-top: 30px;
        }
        .stat-box {
            text-align: center;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            color: white;
        }
        .stat-box.siswa { background-color: #007bff; }
        .stat-box.guru { background-color: #28a745; }
        .stat-box.industri { background-color: #ffc107; color: #212529; }
        .stat-box.kelompok { background-color: #dc3545; }
        .table-responsive {
            overflow-x: auto;
        }
        .img-thumbnail {
            max-width: 100px;
            max-height: 100px;
            object-fit: cover;
        }
        @media (max-width: 768px) {
            .container {
                padding: 10px;
            }
            .card {
                margin-bottom: 15px;
            }
        }
        /*CSS REAKP*/ 
        .stat-box {
            text-align: center;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 15px;
            color: white;
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
            transition: transform 0.3s;
        }
        .stat-box:hover {
            transform: translateY(-5px);
        }
        .stat-box h3 {
            font-size: 24px;
            margin-bottom: 5px;
        }
        .stat-box p {
            margin-bottom: 0;
            font-weight: 500;
        }
    </style>
</head>
<body>
    <?php if (!isset($_SESSION['user_id'])): ?>
    <!-- Login Form -->
    <div class="container mt-5">
        <div class="row justify-content-center">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header bg-primary text-white text-center">
                        <h3>Login Sistem Presensi PKL</h3>
                    </div>
                    <div class="card-body">
                        <?php if ($error): ?>
                        <div class="alert alert-danger"><?php echo sanitize_output($error); ?></div>
                        <?php endif; ?>
                        
                        <form method="POST" action="">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                            <div class="form-group">
                                <label for="username">Username</label>
                                <input type="text" class="form-control" id="username" name="username" required>
                            </div>
                            <div class="form-group">
                                <label for="password">Password</label>
                                <input type="password" class="form-control" id="password" name="password" required>
                            </div>
                            <button type="submit" name="login" class="btn btn-primary btn-block">Login</button>
                        </form>
                        
                        <div class="mt-3">
                            <h6>Akun Demo:</h6>
                            <ul>
                                <li>Admin: admin / password123</li>
                                <li>Manager: manager / password123</li>
                                <li>Pembimbing Sekolah: pembimbing1 / password123</li>
                                <li>Pembimbing Industri: pembimbing2 / password123</li>
                                <li>Siswa: ahmad / password123</li>
                                <li>Siswa: dewi / password123</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php else: ?>
    <!-- Navbar -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container">
            <a class="navbar-brand" href="index.php">Sistem Presensi PKL</a>
            <button class="navbar-toggler" type="button" data-toggle="collapse" data-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav mr-auto">
                    <li class="nav-item active">
                        <a class="nav-link" href="index.php">Beranda</a>
                    </li>
                    
                    <?php if ($_SESSION['role'] === 'admin' || $_SESSION['role'] === 'manager'): ?>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" id="navbarDropdown" role="button" data-toggle="dropdown">
                            Data Master
                        </a>
                        <div class="dropdown-menu">
                            <a class="dropdown-item" href="index.php?table=sekolah">Sekolah</a>
                            <a class="dropdown-item" href="index.php?table=jurusan">Jurusan</a>
                            <a class="dropdown-item" href="index.php?table=kelas">Kelas</a>
                            <a class="dropdown-item" href="index.php?table=guru">Guru</a>
                            <a class="dropdown-item" href="index.php?table=siswa">Siswa</a>
                            <a class="dropdown-item" href="index.php?table=industri">Industri</a>
                            <a class="dropdown-item" href="index.php?table=pembimbing_industri">Pembimbing Industri</a>
                            <a class="dropdown-item" href="index.php?table=kelompok_pkl">Kelompok PKL</a>
                        </div>
                    </li>
                    <?php endif; ?>
                    
                    <?php if ($_SESSION['role'] === 'siswa'): ?>
                    <li class="nav-item">
                        <a class="nav-link" href="index.php">Presensi</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="index.php?action=riwayat">Riwayat</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="index.php?action=perizinan">Perizinan</a>
                    </li>
                    <?php endif; ?>
                    
                    <?php if ($_SESSION['role'] === 'pembimbing_sekolah' || $_SESSION['role'] === 'pembimbing_industri'): ?>
                    <li class="nav-item">
                        <a class="nav-link" href="index.php?action=monitoring">Monitoring</a>
                    </li>
                    <?php endif; ?>
                </ul>
                <ul class="navbar-nav ml-auto">
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" id="userDropdown" role="button" data-toggle="dropdown">
                            <?php 
                            $displayName = '';
                            if (isset($userData)) {
                                if ($_SESSION['role'] === 'siswa') {
                                    $displayName = $userData['nama_siswa'] ?? $_SESSION['username'];
                                } elseif ($_SESSION['role'] === 'pembimbing_sekolah') {
                                    $displayName = $userData['nama_guru'] ?? $_SESSION['username'];
                                } elseif ($_SESSION['role'] === 'pembimbing_industri') {
                                    $displayName = $userData['nama_pembimbing'] ?? $_SESSION['username'];
                                } else {
                                    $displayName = $userData['nama'] ?? $_SESSION['username'];
                                }
                            } else {
                                $displayName = $_SESSION['username'];
                            }
                            echo sanitize_output($displayName) . ' (' . $_SESSION['role'] . ')';
                            ?>
                        </a>
                        <div class="dropdown-menu dropdown-menu-right">
                            <a class="dropdown-item" href="index.php?action=profile">Profil</a>
                            <div class="dropdown-divider"></div>
                            <a class="dropdown-item" href="index.php?logout=1">Logout</a>
                        </div>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <!-- Notifications -->
    <?php if (!empty($success_message)): ?>
    <div class="container mt-3">
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <?php echo sanitize_output($success_message); ?>
            <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                <span aria-hidden="true">&times;</span>
            </button>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($error_message)): ?>
    <div class="container mt-3">
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <?php echo sanitize_output($error_message); ?>
            <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                <span aria-hidden="true">&times;</span>
            </button>
        </div>
    </div>
    <?php endif; ?>

    <div class="container mt-4">
        <!-- Tampilkan berdasarkan role -->
        <?php if ($_SESSION['role'] === 'siswa'): ?>
        <!-- Dashboard Siswa -->
        <div class="card mb-4">
            <div class="card-header bg-info text-white">
                Informasi Siswa
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-2 text-center">
                        <img src="<?php echo !empty($siswa_data['foto']) ? sanitize_output($siswa_data['foto']) : 'https://via.placeholder.com/100'; ?>" class="img-fluid rounded-circle img-thumbnail" alt="Foto Profil">
                    </div>
                    <div class="col-md-10">
                        <h5>Nama Siswa: <?php echo !empty($siswa_data['nama_siswa']) ? sanitize_output($siswa_data['nama_siswa']) : 'N/A'; ?></h5>
                        <p>NIS: <?php echo !empty($siswa_data['nis']) ? sanitize_output($siswa_data['nis']) : 'N/A'; ?> | Kelas: <?php echo !empty($siswa_data['nama_kelas']) ? sanitize_output($siswa_data['nama_kelas']) : 'N/A'; ?> | Jurusan: <?php echo !empty($siswa_data['nama_jurusan']) ? sanitize_output($siswa_data['nama_jurusan']) : 'N/A'; ?></p>
                        <p>Industri: <?php echo !empty($siswa_data['nama_industri']) ? sanitize_output($siswa_data['nama_industri']) : 'N/A'; ?> | Pembimbing: <?php echo !empty($siswa_data['pembimbing_industri']) ? sanitize_output($siswa_data['pembimbing_industri']) : 'N/A'; ?></p>
                    </div>
                </div>
            </div>
        </div>

        <?php if ($action === 'perizinan'): ?>
        <!-- Form Perizinan -->
        <div class="card mb-4">
            <div class="card-header bg-warning text-dark">
                Form Perizinan
            </div>
            <div class="card-body">
                <form method="POST" action="" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <input type="hidden" name="submit_izin" value="1">
                    
                    <div class="form-group">
                        <label for="jenis">Jenis Perizinan</label>
                        <select class="form-control" id="jenis" name="jenis" required>
                            <option value="sakit">Sakit</option>
                            <option value="ijin">Ijin</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="tanggal_mulai">Tanggal Mulai</label>
                        <input type="date" class="form-control" id="tanggal_mulai" name="tanggal_mulai" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="tanggal_selesai">Tanggal Selesai</label>
                        <input type="date" class="form-control" id="tanggal_selesai" name="tanggal_selesai" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="alasan">Alasan</label>
                        <textarea class="form-control" id="alasan" name="alasan" rows="3" required></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label for="lampiran">Lampiran (Surat Keterangan Dokter/Surat Ijin)</label>
                        <input type="file" class="form-control-file" id="lampiran" name="lampiran" accept=".jpg,.jpeg,.png,.pdf">
                        <small class="form-text text-muted">Format file: JPG, PNG, PDF (maks. 2MB)</small>
                    </div>
                    
                    <div class="text-center">
                        <button type="submit" class="btn btn-primary btn-lg">Ajukan Perizinan</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Riwayat Perizinan -->
        <div class="card">
            <div class="card-header bg-secondary text-white">
                Riwayat Perizinan
            </div>
            <div class="card-body">
                <?php if ($riwayat_perizinan && $riwayat_perizinan->num_rows > 0): ?>
                    <?php while($izin = $riwayat_perizinan->fetch_assoc()): ?>
                        <div class="history-item">
                            <h5><?php echo date('l, d F Y', strtotime($izin['tanggal_pengajuan'])); ?> - <?php echo ucfirst($izin['jenis']); ?></h5>
                            <p>Periode: <?php echo date('d/m/Y', strtotime($izin['tanggal_mulai'])); ?> s/d <?php echo date('d/m/Y', strtotime($izin['tanggal_selesai'])); ?></p>
                            <!--
                            <p>Status: <span class="badge badge-<?php 
                                if ($izin['status'] === 'disetujui') echo 'success';
                                elseif ($izin['status'] === 'ditolak') echo 'danger';
                                else echo 'warning';
                            ?>"><?php echo ucfirst($izin['status']); ?></span></p>
                            -->
                            <p>Status: 
                                <span class="badge badge-<?php 
                                    if ($izin['status'] === 'disetujui') echo 'success';
                                    elseif ($izin['status'] === 'ditolak') echo 'danger';
                                    else echo 'warning';
                                ?>"><?php echo ucfirst($izin['status']); ?></span>
                            </p>
                            <?php if ($izin['status'] !== 'menunggu'): ?>
                                <?php if (!empty($izin['catatan_pembimbing_sekolah'])): ?>
                                    <p>Catatan Pembimbing Sekolah: <?php echo sanitize_output($izin['catatan_pembimbing_sekolah']); ?></p>
                                <?php endif; ?>
                                <?php if (!empty($izin['catatan_pembimbing_industri'])): ?>
                                    <p>Catatan Pembimbing Industri: <?php echo sanitize_output($izin['catatan_pembimbing_industri']); ?></p>
                                <?php endif; ?>
                            <?php endif; ?>
                            
                            <p>Alasan: <?php echo sanitize_output($izin['alasan']); ?></p>
                            <?php if ($izin['lampiran']): ?>
                                <p>Lampiran: <a href="<?php echo sanitize_output($izin['lampiran']); ?>" target="_blank">Lihat</a></p>
                            <?php endif; ?>
                            <?php if ($izin['status'] === 'menunggu'): ?>
                                <button class="btn btn-sm btn-warning" data-toggle="modal" data-target="#editIzinModal" 
                                        data-id="<?php echo $izin['id_izin']; ?>"
                                        data-tanggal_mulai="<?php echo $izin['tanggal_mulai']; ?>"
                                        data-tanggal_selesai="<?php echo $izin['tanggal_selesai']; ?>"
                                        data-jenis="<?php echo $izin['jenis']; ?>"
                                        data-alasan="<?php echo htmlspecialchars($izin['alasan']); ?>">Edit</button>
                                <button class="btn btn-sm btn-danger" data-toggle="modal" data-target="#deleteIzinModal" 
                                        data-id="<?php echo $izin['id_izin']; ?>">Hapus</button>
                            <?php endif; ?>
                        </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <p>Belum ada riwayat perizinan</p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Rekap Perizinan Siswa -->
        <div class="card mb-4">
            <div class="card-header bg-info text-white">
                Rekap Perizinan
            </div>
            <div class="card-body">
                <!-- Rekap Perizinan -->
                <div class="row mt-4">
                    <div class="col-md-3">
                        <div class="stat-box" style="background-color: #17a2b8; color: white;">
                            <h3><?php echo $rekapPerizinan['total']; ?></h3>
                            <p>Total Perizinan</p>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-box" style="background-color: #ffc107; color: #212529;">
                            <h3><?php echo $rekapPerizinan['ijin']; ?></h3>
                            <p>Ijin</p>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-box" style="background-color: #dc3545; color: white;">
                            <h3><?php echo $rekapPerizinan['sakit']; ?></h3>
                            <p>Sakit</p>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-box" style="background-color: #28a745; color: white;">
                            <h3>
                                <?php 
                                $result = query("SELECT COUNT(*) as total FROM perizinan WHERE status = 'disetujui'");
                                echo $result ? $result->fetch_assoc()['total'] : 0;
                                ?>
                            </h3>
                            <p>Disetujui</p>
                        </div>
                    </div>
                </div>
                <!--
                <div class="row text-center">
                    <div class="col-md-4">
                        <div class="stat-box" style="background-color: #17a2b8; color: white;">
                            <h3><?php echo $rekapPerizinan['total']; ?></h3>
                            <p>Total Perizinan</p>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="stat-box" style="background-color: #ffc107; color: #212529;">
                            <h3><?php echo $rekapPerizinan['ijin']; ?></h3>
                            <p>Ijin</p>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="stat-box" style="background-color: #dc3545; color: white;">
                            <h3><?php echo $rekapPerizinan['sakit']; ?></h3>
                            <p>Sakit</p>
                        </div>
                    </div>
                </div>
                -->
            </div>
        </div>

        <!-- Rekap Presensi Bulanan -->
        <div class="card mb-4">
            <div class="card-header bg-primary text-white">
                Rekap Presensi Bulanan - <?php echo date('F Y'); ?>
            </div>
            <div class="card-body">
                <h5>Total Absensi</h5>
                <div class="row text-center mb-4">
                    <div class="col-md-4">
                        <div class="stat-box" style="background-color: #6c757d; color: white;">
                            <h3><?php echo $rekapPresensiBulanan['absen'] ?? 0; ?></h3>
                            <p>Absen</p>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="stat-box" style="background-color: #ffc107; color: red;">
                            <h3><?php echo $rekapPresensiBulanan['ijin'] ?? 0; ?></h3>
                            <!--<h3><?php echo isset($rekapPresensiBulanan['ijin']) ? $rekapPresensiBulanan['ijin'] : 0; ?></h3>-->
                            <p>Ijin</p>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="stat-box" style="background-color: #dc3545; color: white;">
                            <h3><?php echo $rekapPresensiBulanan['sakit'] ?? 0; ?></h3>
                            <p>Sakit</p>
                        </div>
                    </div>
                </div>
                
                <h5>Total Presensi</h5>
                <div class="row text-center">
                    <div class="col-md-3">
                        <div class="stat-box" style="background-color: #28a745; color: white;">
                            <h3><?php echo $rekapPresensiBulanan['tepat_waktu'] ?? 0; ?></h3>
                            <p>Tepat Waktu</p>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-box" style="background-color: #fd7e14; color: white;">
                            <h3><?php echo $rekapPresensiBulanan['terlambat'] ?? 0; ?></h3>
                            <p>Terlambat</p>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-box" style="background-color: #e83e8c; color: white;">
                            <h3><?php echo $rekapPresensiBulanan['pulang_cepat'] ?? 0; ?></h3>
                            <p>Pulang Cepat</p>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-box" style="background-color: #6f42c1; color: white;">
                            <h3><?php echo $rekapPresensiBulanan['belum_pulang'] ?? 0; ?></h3>
                            <p>Belum Pulang</p>
                        </div>
                    </div>
                </div>
                
                <!-- Grafik Presensi (Opsional) -->
                <div class="mt-4">
                    <h5>Grafik Presensi Bulanan</h5>
                    <canvas id="presensiChart" width="400" height="200"></canvas>
                </div>
            </div>
        </div>


        <!-- Edit Izin Modal -->
        <div class="modal fade" id="editIzinModal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Edit Perizinan</h5>
                        <button type="button" class="close" data-dismiss="modal">&times;</button>
                    </div>
                    <form method="POST" action="" enctype="multipart/form-data">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                        <input type="hidden" name="update_izin" value="1">
                        <input type="hidden" name="id_izin" id="edit_izin_id">
                        <div class="modal-body">
                            <div class="form-group">
                                <label for="edit_jenis">Jenis Perizinan</label>
                                <select class="form-control" id="edit_jenis" name="jenis" required>
                                    <option value="sakit">Sakit</option>
                                    <option value="ijin">Ijin</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="edit_tanggal_mulai">Tanggal Mulai</label>
                                <input type="date" class="form-control" id="edit_tanggal_mulai" name="tanggal_mulai" required>
                            </div>
                            <div class="form-group">
                                <label for="edit_tanggal_selesai">Tanggal Selesai</label>
                                <input type="date" class="form-control" id="edit_tanggal_selesai" name="tanggal_selesai" required>
                            </div>
                            <div class="form-group">
                                <label for="edit_alasan">Alasan</label>
                                <textarea class="form-control" id="edit_alasan" name="alasan" rows="3" required></textarea>
                            </div>
                            <div class="form-group">
                                <label for="edit_lampiran">Lampiran (Biarkan kosong jika tidak ingin mengubah)</label>
                                <input type="file" class="form-control-file" id="edit_lampiran" name="lampiran" accept=".jpg,.jpeg,.png,.pdf">
                                <small class="form-text text-muted">Format file: JPG, PNG, PDF (maks. 2MB)</small>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
                            <button type="submit" class="btn btn-primary">Simpan Perubahan</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Delete Izin Modal -->
        <div class="modal fade" id="deleteIzinModal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Hapus Perizinan</h5>
                        <button type="button" class="close" data-dismiss="modal">&times;</button>
                    </div>
                    <form method="POST" action="">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                        <input type="hidden" name="delete_izin" value="1">
                        <input type="hidden" name="id_izin" id="delete_izin_id">
                        <div class="modal-body">
                            <p>Apakah Anda yakin ingin menghapus perizinan ini?</p>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
                            <button type="submit" class="btn btn-danger">Hapus</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <?php else: ?>
        <div class="row">
            <!-- Presensi Hari Ini -->
            <div class="col-md-6">
                <div class="card mb-4">
                    <div class="card-header bg-success text-white">
                        Presensi Hari Ini
                    </div>
                    <div class="card-body">
                        <div class="text-center mb-3">
                            <h3 id="current-time">00:00:00</h3>
                            <p id="current-date">Senin, 1 Januari 2024</p>
                        </div>
                        
                        <?php if ($presensi_hari_ini): ?>
                            <div id="presence-status" class="presence-status status-<?php echo str_replace(' ', '-', $presensi_hari_ini['status']); ?> mb-3">
                                Hari ini: <?php echo ucfirst($presensi_hari_ini['status']); ?>
                            </div>
                            
                            <div class="row text-center">
                                <div class="col-6">
                                    <p>Masuk</p>
                                    <h4><?php echo $presensi_hari_ini['jam_masuk'] ?: '-'; ?></h4>
                                </div>
                                <div class="col-6">
                                    <p>Pulang</p>
                                    <h4><?php echo $presensi_hari_ini['jam_pulang'] ?: '-'; ?></h4>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-info">
                                Belum melakukan presensi hari ini
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Riwayat Presensi -->
                <div class="card">
                    <div class="card-header bg-secondary text-white">
                        Riwayat Presensi
                    </div>
                    <div class="card-body">
                        <?php if ($riwayat_presensi && $riwayat_presensi->num_rows > 0): ?>
                            <?php while($presensi = $riwayat_presensi->fetch_assoc()): ?>
                                <div class="history-item">
                                    <h5><?php echo date('l, d F Y', strtotime($presensi['tanggal'])); ?></h5>
                                    <p>Masuk: <?php echo $presensi['jam_masuk'] ?: '-'; ?> 
                                    (<?php echo ucfirst($presensi['status']); ?>) | 
                                    Pulang: <?php echo $presensi['jam_pulang'] ?: '-'; ?></p>
                                </div>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <p>Belum ada riwayat presensi</p>
                        <?php endif; ?>
                        <a href="index.php?action=riwayat" class="btn btn-outline-primary btn-block">Lihat Semua Riwayat</a>
                    </div>
                </div>
            </div>

            <!-- Form Presensi -->
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header bg-warning text-dark">
                        Form Presensi
                    </div>
                    <div class="card-body">
                        <form id="presensi-form" method="POST" action="">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                            <input type="hidden" name="submit_presensi" value="1">
                            <input type="hidden" name="latitude" id="input-latitude">
                            <input type="hidden" name="longitude" id="input-longitude">
                            <input type="hidden" name="foto_data" id="input-foto">
                            
                            <div class="form-group">
                                <label for="jenis_presensi">Jenis Presensi</label>
                                <select class="form-control" id="jenis_presensi" name="jenis_presensi">
                                    <option value="masuk">Presensi Masuk</option>
                                    <option value="pulang">Presensi Pulang</option>
                                </select>
                            </div>
                            
                            <div class="mb-3">
                                <label>Lokasi Saat Ini</label>
                                <div id="map"></div>
                                <small class="form-text text-muted">Pastikan Anda berada di area industri untuk melakukan presensi</small>
                                <div id="location-status" class="mt-2"></div>
                            </div>
                            
                            <div class="mb-3">
                                <label>Ambil Foto</label>
                                <div id="video-container">
                                    <video id="video" autoplay playsinline></video>
                                    <button type="button" id="capture-btn" class="btn btn-primary">Ambil Foto</button>
                                </div>
                                <canvas id="canvas" style="display: none;"></canvas>
                                <div id="photo-preview" class="mt-2 text-center"></div>
                            </div>
                            
                            <div class="text-center">
                                <button type="submit" id="submit-btn" class="btn btn-success btn-lg" disabled>Submit Presensi</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php elseif ($_SESSION['role'] === 'admin' || $_SESSION['role'] === 'manager'): ?>
        <!-- Dashboard Admin/Manager -->
        <div class="row">
            <div class="col-md-3">
                <div class="stat-box siswa">
                    <h3><?php echo $jumlah_siswa; ?></h3>
                    <p>Siswa</p>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-box guru">
                    <h3><?php echo $jumlah_guru; ?></h3>
                    <p>Guru</p>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-box industri">
                    <h3><?php echo $jumlah_industri; ?></h3>
                    <p>Industri</p>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-box kelompok">
                    <h3><?php echo $jumlah_kelompok; ?></h3>
                    <p>Kelompok PKL</p>
                </div>
            </div>
        </div>

        <?php if ($table): ?>
        <?php
        // Get column info
        $columns_info = [];
        $col_result = query("SHOW COLUMNS FROM $table");
        if ($col_result) {
            while ($col_row = $col_result->fetch_assoc()) {
                $columns_info[$col_row['Field']] = $col_row;
            }
        }
        ?>
        <!-- CRUD Table -->
        <div class="card">
            <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                <h5>Data <?php echo ucfirst(str_replace('_', ' ', $table)); ?></h5>
                <button class="btn btn-sm btn-light" data-toggle="modal" data-target="#addModal">Tambah Data</button>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-striped table-bordered">
                        <thead>
                            <tr>
                                <?php
                                $tableData = getTableData($table);
                                foreach ($tableData['columns'] as $column):
                                    if ($column !== 'password' && $column !== 'created_at' && $column !== 'updated_at'):
                                ?>
                                <th><?php echo ucfirst(str_replace('_', ' ', $column)); ?></th>
                                <?php endif; endforeach; ?>
                                <th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($tableData['data'] as $row): ?>
                            <tr>
                                <?php foreach ($tableData['columns'] as $column):
                                    if ($column !== 'password' && $column !== 'created_at' && $column !== 'updated_at'):
                                        if ($column === 'foto' && !empty($row[$column])):
                                ?>
                                <td><img src="<?php echo sanitize_output($row[$column]); ?>" alt="Foto" class="img-thumbnail"></td>
                                <?php   else: ?>
                                <td><?php echo !empty($row[$column]) ? sanitize_output($row[$column]) : ''; ?></td>
                                <?php   endif; endif; endforeach; ?>
                                <td>
                                    <button class="btn btn-sm btn-warning" data-toggle="modal" data-target="#editModal" 
                                        data-id="<?php echo $row['id_'. $table]; ?>" 
                                        data-data='<?php echo json_encode($row); ?>'>Edit</button>
                                    <button class="btn btn-sm btn-danger" data-toggle="modal" data-target="#deleteModal" 
                                        data-id="<?php echo $row['id_'. $table]; ?>">Hapus</button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Add Modal -->
        <div class="modal fade" id="addModal" tabindex="-1">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Tambah <?php echo ucfirst(str_replace('_', ' ', $table)); ?></h5>
                        <button type="button" class="close" data-dismiss="modal">&times;</button>
                    </div>
                    <form method="POST" action="" enctype="multipart/form-data">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                        <input type="hidden" name="table" value="<?php echo $table; ?>">
                        <input type="hidden" name="add" value="1">
                        <div class="modal-body">
                            <?php
                            $columns = $tableData['columns'];
                            foreach ($columns as $column):
                                if ($column !== 'id_'. $table && $column !== 'created_at' && $column !== 'updated_at'):
                                    $col_info = $columns_info[$column];
                                    $type = $col_info['Type'];
                                    $label = ucfirst(str_replace('_', ' ', $column));
                                    if (preg_match('/enum\((.*)\)/i', $type, $matches)) {
                                        $enum_values = array_map(function($v) { return trim($v, "'"); }, explode(',', $matches[1]));
                                        ?>
                                        <div class="form-group">
                                            <label for="add_<?php echo $column; ?>"><?php echo $label; ?></label>
                                            <select class="form-control" id="add_<?php echo $column; ?>" name="data[<?php echo $column; ?>]">
                                                <?php foreach ($enum_values as $val): ?>
                                                    <option value="<?php echo $val; ?>"><?php echo $val; ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <?php
                                    } elseif (strpos($type, 'text') !== false) {
                                        ?>
                                        <div class="form-group">
                                            <label for="add_<?php echo $column; ?>"><?php echo $label; ?></label>
                                            <textarea class="form-control" id="add_<?php echo $column; ?>" name="data[<?php echo $column; ?>]" rows="3"></textarea>
                                        </div>
                                        <?php
                                    } elseif (strpos($column, 'id_') === 0 && $column !== 'id_'.$table) {
                                        $ref_table = substr($column, 3);
                                        $ref_id = 'id_'.$ref_table;
                                        $ref_name = 'nama_'.$ref_table;
                                        if ($ref_table == 'pembimbing_industri') $ref_name = 'nama_pembimbing';
                                        if ($ref_table == 'guru') $ref_name = 'nama_guru';
                                        if ($ref_table == 'siswa') $ref_name = 'nama_siswa';
                                        $options_result = query("SELECT $ref_id, $ref_name FROM $ref_table");
                                        ?>
                                        <div class="form-group">
                                            <label for="add_<?php echo $column; ?>"><?php echo $label; ?></label>
                                            <select class="form-control" id="add_<?php echo $column; ?>" name="data[<?php echo $column; ?>]">
                                                <?php if ($options_result): while ($opt = $options_result->fetch_assoc()): ?>
                                                    <option value="<?php echo $opt[$ref_id]; ?>"><?php echo $opt[$ref_name]; ?></option>
                                                <?php endwhile; endif; ?>
                                            </select>
                                        </div>
                                        <?php
                                    } elseif ($column === 'foto') {
                                        ?>
                                        <div class="form-group">
                                            <label for="add_<?php echo $column; ?>"><?php echo $label; ?></label>
                                            <input type="file" class="form-control-file" id="add_<?php echo $column; ?>" name="foto" accept=".jpg,.jpeg,.png,.gif">
                                        </div>
                                        <?php
                                    } else {
                                        ?>
                                        <div class="form-group">
                                            <label for="add_<?php echo $column; ?>"><?php echo $label; ?></label>
                                            <input type="text" class="form-control" id="add_<?php echo $column; ?>" name="data[<?php echo $column; ?>]">
                                        </div>
                                        <?php
                                    }
                                endif;
                            endforeach;
                            ?>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
                            <button type="submit" class="btn btn-primary">Simpan</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Edit Modal -->
        <div class="modal fade" id="editModal" tabindex="-1">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Edit <?php echo ucfirst(str_replace('_', ' ', $table)); ?></h5>
                        <button type="button" class="close" data-dismiss="modal">&times;</button>
                    </div>
                    <form method="POST" action="" enctype="multipart/form-data">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                        <input type="hidden" name="table" value="<?php echo $table; ?>">
                        <input type="hidden" name="edit" value="1">
                        <input type="hidden" name="id" id="edit_id">
                        <input type="hidden" name="old_foto" id="edit_old_foto">
                        <div class="modal-body">
                            <?php
                            foreach ($columns as $column):
                                if ($column !== 'id_'. $table && $column !== 'created_at' && $column !== 'updated_at'):
                                    $col_info = $columns_info[$column];
                                    $type = $col_info['Type'];
                                    $label = ucfirst(str_replace('_', ' ', $column));
                                    if (preg_match('/enum\((.*)\)/i', $type, $matches)) {
                                        $enum_values = array_map(function($v) { return trim($v, "'"); }, explode(',', $matches[1]));
                                        ?>
                                        <div class="form-group">
                                            <label for="edit_<?php echo $column; ?>"><?php echo $label; ?></label>
                                            <select class="form-control" id="edit_<?php echo $column; ?>" name="data[<?php echo $column; ?>]">
                                                <?php foreach ($enum_values as $val): ?>
                                                    <option value="<?php echo $val; ?>"><?php echo $val; ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <?php
                                    } elseif (strpos($type, 'text') !== false) {
                                        ?>
                                        <div class="form-group">
                                            <label for="edit_<?php echo $column; ?>"><?php echo $label; ?></label>
                                            <textarea class="form-control" id="edit_<?php echo $column; ?>" name="data[<?php echo $column; ?>]" rows="3"></textarea>
                                        </div>
                                        <?php
                                    } elseif (strpos($column, 'id_') === 0 && $column !== 'id_'.$table) {
                                        $ref_table = substr($column, 3);
                                        $ref_id = 'id_'.$ref_table;
                                        $ref_name = 'nama_'.$ref_table;
                                        if ($ref_table == 'pembimbing_industri') $ref_name = 'nama_pembimbing';
                                        if ($ref_table == 'guru') $ref_name = 'nama_guru';
                                        if ($ref_table == 'siswa') $ref_name = 'nama_siswa';
                                        $options_result = query("SELECT $ref_id, $ref_name FROM $ref_table");
                                        ?>
                                        <div class="form-group">
                                            <label for="edit_<?php echo $column; ?>"><?php echo $label; ?></label>
                                            <select class="form-control" id="edit_<?php echo $column; ?>" name="data[<?php echo $column; ?>]">
                                                <?php if ($options_result): while ($opt = $options_result->fetch_assoc()): ?>
                                                    <option value="<?php echo $opt[$ref_id]; ?>"><?php echo $opt[$ref_name]; ?></option>
                                                <?php endwhile; endif; ?>
                                            </select>
                                        </div>
                                        <?php
                                    } elseif ($column === 'foto') {
                                        ?>
                                        <div class="form-group">
                                            <label for="edit_<?php echo $column; ?>"><?php echo $label; ?></label>
                                            <input type="file" class="form-control-file" id="edit_<?php echo $column; ?>" name="foto" accept=".jpg,.jpeg,.png,.gif">
                                            <small>Biarkan kosong jika tidak ingin mengubah foto</small>
                                            <img id="current_foto" src="" alt="Current Foto" class="img-thumbnail mt-2" style="display: none;">
                                        </div>
                                        <?php
                                    } else {
                                        ?>
                                        <div class="form-group">
                                            <label for="edit_<?php echo $column; ?>"><?php echo $label; ?></label>
                                            <input type="text" class="form-control" id="edit_<?php echo $column; ?>" name="data[<?php echo $column; ?>]">
                                        </div>
                                        <?php
                                    }
                                endif;
                            endforeach;
                            ?>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
                            <button type="submit" class="btn btn-primary">Simpan Perubahan</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Delete Modal -->
        <div class="modal fade" id="deleteModal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Hapus Data</h5>
                        <button type="button" class="close" data-dismiss="modal">&times;</button>
                    </div>
                    <form method="POST" action="">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                        <input type="hidden" name="table" value="<?php echo $table; ?>">
                        <input type="hidden" name="delete" value="1">
                        <input type="hidden" name="id" id="delete_id">
                        <div class="modal-body">
                            <p>Apakah Anda yakin ingin menghapus data ini?</p>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-dismiss="modal">Batal</button>
                            <button type="submit" class="btn btn-danger">Hapus</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <?php else: ?>
        <!-- Default Admin Dashboard -->
        <div class="card">
            <div class="card-header bg-primary text-white">
                <h5>Selamat Datang, <?php echo sanitize_output($_SESSION['username']); ?></h5>
            </div>
            <div class="card-body">
                <p>Silakan pilih menu Data Master dari navbar untuk mengelola data.</p>
            </div>
        </div>
        <?php endif; ?>

        <?php elseif ($_SESSION['role'] === 'pembimbing_sekolah' || $_SESSION['role'] === 'pembimbing_industri'): ?>
        
        <!-- Dashboard Pembimbing -->
        <div class="card mb-4">
            <div class="card-header bg-info text-white">
                Informasi <?php echo $_SESSION['role'] === 'pembimbing_sekolah' ? 'Pembimbing Sekolah' : 'Pembimbing Industri'; ?>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-2 text-center">
                        <img src="<?php echo !empty($userData['foto']) ? sanitize_output($userData['foto']) : 'https://via.placeholder.com/100'; ?>" class="img-fluid rounded-circle img-thumbnail" alt="Foto Profil">
                    </div>
                    <div class="col-md-10">
                        <h5>Nama: <?php echo $_SESSION['role'] === 'pembimbing_sekolah' ? sanitize_output($userData['nama_guru']) : sanitize_output($userData['nama_pembimbing']); ?></h5>
                        <p>
                            <?php if ($_SESSION['role'] === 'pembimbing_sekolah'): ?>
                            NIP: <?php echo !empty($userData['nip']) ? sanitize_output($userData['nip']) : 'N/A'; ?> | Sekolah: <?php echo !empty($userData['nama_sekolah']) ? sanitize_output($userData['nama_sekolah']) : 'N/A'; ?>
                            <?php else: ?>
                            Jabatan: <?php echo !empty($userData['jabatan']) ? sanitize_output($userData['jabatan']) : 'N/A'; ?> | Industri: <?php echo !empty($userData['nama_industri']) ? sanitize_output($userData['nama_industri']) : 'N/A'; ?>
                            <?php endif; ?>
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Daftar Kelompok -->
        <div class="card mb-4">
            <div class="card-header bg-primary text-white">
                Daftar Kelompok yang Dibimbing
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-striped">
                        <thead>
                            <tr>
                                <th>Nama Kelompok</th>
                                <th>Industri</th>
                                <th>Tanggal Mulai</th>
                                <th>Tanggal Selesai</th>
                                <th>Jumlah Siswa</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($kelompok): while($row = $kelompok->fetch_assoc()): ?>
                            <tr>
                                <td><?php echo sanitize_output($row['nama_kelompok']); ?></td>
                                <td><?php echo isset($row['nama_industri']) ? sanitize_output($row['nama_industri']) : sanitize_output($row['pembimbing_sekolah']); ?></td>
                                <td><?php echo sanitize_output($row['tgl_mulai']); ?></td>
                                <td><?php echo sanitize_output($row['tgl_selesai']); ?></td>
                                <td><?php echo sanitize_output($row['jumlah_siswa']); ?></td>
                                <td>
                                    <a href="index.php?action=detail_kelompok&id=<?php echo $row['id_kelompok']; ?>" class="btn btn-sm btn-info">Detail</a>
                                </td>
                            </tr>
                            <?php endwhile; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <?php if ($action === 'monitoring'): ?>
        <!-- Approval Perizinan -->
        <div class="card mb-4">
            <div class="card-header bg-warning text-dark">
                Approval Perizinan Pending
            </div>
            <div class="card-body">
                <?php if ($pending_perizinan && $pending_perizinan->num_rows > 0): ?>
                    <?php while($izin = $pending_perizinan->fetch_assoc()): ?>
                        <div class="history-item mb-4">
                            <h5><?php echo sanitize_output($izin['nama_siswa']); ?> - <?php echo ucfirst($izin['jenis']); ?></h5>
                            <p>Periode: <?php echo date('d/m/Y', strtotime($izin['tanggal_mulai'])); ?> s/d <?php echo date('d/m/Y', strtotime($izin['tanggal_selesai'])); ?></p>
                            <p>Alasan: <?php echo sanitize_output($izin['alasan']); ?></p>
                            <?php if ($izin['lampiran']): ?>
                                <p>Lampiran: <a href="<?php echo sanitize_output($izin['lampiran']); ?>" target="_blank">Lihat</a></p>
                            <?php endif; ?>
                            <form method="POST" action="">
                                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                <input type="hidden" name="approve_izin" value="1">
                                <input type="hidden" name="id_izin" value="<?php echo $izin['id_izin']; ?>">
                                <div class="form-group">
                                    <label>Status Approval</label>
                                    <select class="form-control" name="status" required>
                                        <option value="disetujui">Disetujui</option>
                                        <option value="ditolak">Ditolak</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label>Catatan</label>
                                    <textarea class="form-control" name="catatan" rows="2"></textarea>
                                </div>
                                <button type="submit" class="btn btn-primary">Submit Approval</button>
                            </form>
                        </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <p>Tidak ada perizinan pending.</p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Rekap Perizinan -->
        <div class="card mb-4">
            <div class="card-header bg-info text-white">
                Rekap Perizinan Siswa Bimbingan
            </div>
            <div class="card-body">
                <div class="row text-center">
                    <div class="col-md-3">
                        <div class="stat-box" style="background-color: #17a2b8; color: white;">
                            <h3><?php echo $rekapPerizinan['total']; ?></h3>
                            <p>Total Perizinan</p>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-box" style="background-color: #ffc107; color: #212529;">
                            <h3><?php echo $rekapPerizinan['ijin']; ?></h3>
                            <p>Ijin</p>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-box" style="background-color: #dc3545; color: white;">
                            <h3><?php echo $rekapPerizinan['sakit']; ?></h3>
                            <p>Sakit</p>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-box" style="background-color: #28a745; color: white;">
                            <h3>
                                <?php 
                                // Hitung persetujuan
                                $approved = 0;
                                if ($_SESSION['role'] === 'pembimbing_sekolah') {
                                    $result = query("SELECT COUNT(*) as total FROM perizinan per
                                                JOIN anggota_kelompok ak ON per.id_anggota = ak.id_anggota
                                                JOIN kelompok_pkl kp ON ak.id_kelompok = kp.id_kelompok
                                                WHERE kp.id_guru = ? AND per.status = 'disetujui'", [$_SESSION['id_referensi']]);
                                } else {
                                    $pembimbing = query("SELECT id_industri FROM pembimbing_industri WHERE id_pembimbing_industri = ?", [$_SESSION['id_referensi']]);
                                    if ($pembimbing && $pembimbing->num_rows === 1) {
                                        $id_industri = $pembimbing->fetch_assoc()['id_industri'];
                                        $result = query("SELECT COUNT(*) as total FROM perizinan per
                                                    JOIN anggota_kelompok ak ON per.id_anggota = ak.id_anggota
                                                    JOIN kelompok_pkl kp ON ak.id_kelompok = kp.id_kelompok
                                                    WHERE kp.id_industri = ? AND per.status = 'disetujui'", [$id_industri]);
                                    }
                                }
                                if ($result) {
                                    $approved = $result->fetch_assoc()['total'];
                                }
                                echo $approved;
                                ?>
                            </h3>
                            <p>Disetujui</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Rekap Presensi Harian -->
        <div class="card mb-4">
            <div class="card-header bg-primary text-white">
                Rekap Presensi Harian - <?php echo date('d F Y'); ?>
            </div>
            <div class="card-body">
                <h5>Total Absensi</h5>
                <div class="row text-center mb-4">
                    <div class="col-md-4">
                        <div class="stat-box" style="background-color: #ffc107; color: #212529;">
                            <!--<h3><?php echo $rekapPresensiHarian['ijin'] ?? 0; ?></h3>-->
                            <h3><?php echo isset($rekapPresensiHarian['ijin']) ? $rekapPresensiHarian['ijin'] : 0; ?></
                            <p>Ijin</p>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="stat-box" style="background-color: #dc3545; color: white;">
                            <h3><?php echo $rekapPresensiHarian['sakit'] ?? 0; ?></h3>
                            <p>Sakit</p>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="stat-box" style="background-color: #6c757d; color: white;">
                            <h3><?php echo $rekapPresensiHarian['absen'] ?? 0; ?></h3>
                            <p>Absen</p>
                        </div>
                    </div>
                </div>
                
                <h5>Total Presensi</h5>
                <div class="row text-center">
                    <div class="col-md-3">
                        <div class="stat-box" style="background-color: #28a745; color: white;">
                            <h3><?php echo $rekapPresensiHarian['tepat_waktu'] ?? 0; ?></h3>
                            <p>Tepat Waktu</p>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-box" style="background-color: #fd7e14; color: white;">
                            <h3><?php echo $rekapPresensiHarian['terlambat'] ?? 0; ?></h3>
                            <p>Terlambat</p>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-box" style="background-color: #e83e8c; color: white;">
                            <h3><?php echo $rekapPresensiHarian['pulang_cepat'] ?? 0; ?></h3>
                            <p>Pulang Cepat</p>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-box" style="background-color: #6f42c1; color: white;">
                            <h3><?php echo $rekapPresensiHarian['belum_pulang'] ?? 0; ?></h3>
                            <p>Belum Pulang</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>


        <!-- Riwayat Approval Perizinan -->
        <div class="card mt-4">
            <div class="card-header bg-secondary text-white">
                Riwayat Approval Perizinan
            </div>
            <div class="card-body">
                <?php
                if ($_SESSION['role'] === 'pembimbing_sekolah') {
                    $riwayat_approval = query("SELECT per.*, s.nama_siswa 
                                            FROM perizinan per
                                            JOIN anggota_kelompok ak ON per.id_anggota = ak.id_anggota
                                            JOIN kelompok_pkl kp ON ak.id_kelompok = kp.id_kelompok
                                            JOIN siswa s ON ak.id_siswa = s.id_siswa
                                            WHERE kp.id_guru = ? AND per.status != 'menunggu'
                                            ORDER BY per.tanggal_pengajuan DESC LIMIT 10", [$_SESSION['id_referensi']]);
                } else {
                    $pembimbing = query("SELECT id_industri FROM pembimbing_industri WHERE id_pembimbing_industri = ?", [$_SESSION['id_referensi']]);
                    if ($pembimbing && $pembimbing->num_rows === 1) {
                        $id_industri = $pembimbing->fetch_assoc()['id_industri'];
                        $riwayat_approval = query("SELECT per.*, s.nama_siswa 
                                                FROM perizinan per
                                                JOIN anggota_kelompok ak ON per.id_anggota = ak.id_anggota
                                                JOIN kelompok_pkl kp ON ak.id_kelompok = kp.id_kelompok
                                                JOIN siswa s ON ak.id_siswa = s.id_siswa
                                                WHERE kp.id_industri = ? AND per.status != 'menunggu'
                                                ORDER BY per.tanggal_pengajuan DESC LIMIT 10", [$id_industri]);
                    }
                }
                
                if ($riwayat_approval && $riwayat_approval->num_rows > 0): 
                ?>
                    <div class="table-responsive">
                        <table class="table table-striped">
                            <thead>
                                <tr>
                                    <th>Tanggal</th>
                                    <th>Siswa</th>
                                    <th>Jenis</th>
                                    <th>Periode</th>
                                    <th>Status</th>
                                    <th>Catatan</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while($approval = $riwayat_approval->fetch_assoc()): ?>
                                <tr>
                                    <td><?php echo date('d/m/Y', strtotime($approval['tanggal_pengajuan'])); ?></td>
                                    <td><?php echo sanitize_output($approval['nama_siswa']); ?></td>
                                    <td><?php echo ucfirst($approval['jenis']); ?></td>
                                    <td><?php echo date('d/m/Y', strtotime($approval['tanggal_mulai'])); ?> - <?php echo date('d/m/Y', strtotime($approval['tanggal_selesai'])); ?></td>
                                    <td>
                                        <span class="badge badge-<?php 
                                            if ($approval['status'] === 'disetujui') echo 'success';
                                            else echo 'danger';
                                        ?>"><?php echo ucfirst($approval['status']); ?></span>
                                    </td>
                                    <td>
                                        <?php 
                                        if ($_SESSION['role'] === 'pembimbing_sekolah') {
                                            echo !empty($approval['catatan_pembimbing_sekolah']) ? sanitize_output($approval['catatan_pembimbing_sekolah']) : '-';
                                        } else {
                                            echo !empty($approval['catatan_pembimbing_industri']) ? sanitize_output($approval['catatan_pembimbing_industri']) : '-';
                                        }
                                        ?>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p>Belum ada riwayat approval</p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Presensi Harian dengan Filter Tanggal -->
        <div class="card">
            <div class="card-header bg-secondary text-white">
                Presensi Harian Siswa
            </div>
            <div class="card-body">
                <form method="POST" action="index.php?action=monitoring">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <div class="form-group">
                        <label for="filter_tanggal">Filter Tanggal</label>
                        <input type="date" class="form-control" id="filter_tanggal" name="filter_tanggal" value="<?php echo $filter_tanggal; ?>" required>
                    </div>
                    <button type="submit" class="btn btn-primary">Filter</button>
                </form>
                <div class="table-responsive mt-3">
                    <table class="table table-striped">
                        <thead>
                            <tr>
                                <th>Nama Siswa</th>
                                <th>Jam Masuk</th>
                                <th>Jam Pulang</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($presensi_harian && $presensi_harian->num_rows > 0): ?>
                                <?php while($presensi = $presensi_harian->fetch_assoc()): ?>
                                    <tr>
                                        <td><?php echo sanitize_output($presensi['nama_siswa']); ?></td>
                                        <td><?php echo $presensi['jam_masuk'] ?: '-'; ?></td>
                                        <td><?php echo $presensi['jam_pulang'] ?: '-'; ?></td>
                                        <td><?php echo ucfirst($presensi['status']); ?></td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr><td colspan="4">Tidak ada data presensi untuk tanggal ini.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>

    <footer class="footer mt-5">
        <div class="container text-center">
            <p>&copy; 2024 Sistem Presensi PKL - SMK Negeri 1 Contoh</p>
        </div>
    </footer>
    <?php endif; ?>

    <script src="https://code.jquery.com/jquery-3.5.1.slim.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/popper.js@1.16.1/dist/umd/popper.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.min.js"></script>
    <script src="https://unpkg.com/leaflet@1.7.1/dist/leaflet.js"></script>
    
    <script>
        // Update waktu secara real-time
        function updateClock() {
            const now = new Date();
            const timeStr = now.toLocaleTimeString('id-ID');
            const dateStr = now.toLocaleDateString('id-ID', { 
                weekday: 'long', 
                year: 'numeric', 
                month: 'long', 
                day: 'numeric' 
            });
            
            document.getElementById('current-time').textContent = timeStr;
            document.getElementById('current-date').textContent = dateStr;
            
            setTimeout(updateClock, 1000);
        }
        
        updateClock();
        
        // Inisialisasi peta
        function initMap() {
            // Koordinat dan radius industri dari PHP
            const industriCoords = [<?php echo $industri_latitude; ?>, <?php echo $industri_longitude; ?>];
            const radiusArea = <?php echo $radius_area; ?>;
            
            // Validasi koordinat industri
            if (!industriCoords[0] || !industriCoords[1] || isNaN(industriCoords[0]) || isNaN(industriCoords[1])) {
                console.error('Koordinat industri tidak valid:', industriCoords);
                document.getElementById('location-status').innerHTML = `<div class="alert alert-danger">Koordinat industri tidak valid. Hubungi administrator.</div>`;
                document.getElementById('submit-btn').disabled = true;
                return;
            }

            // Buat peta
            const map = L.map('map').setView(industriCoords, 16);
            
            // Tambahkan tile layer OpenStreetMap
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
            }).addTo(map);
            
            // Tambahkan marker untuk lokasi industri
            L.marker(industriCoords).addTo(map)
                .bindPopup('Lokasi Industri: <?php echo isset($siswa_data) ? addslashes($siswa_data['nama_industri']) : 'PT. Teknologi Indonesia'; ?>')
                .openPopup();
            
            // Tambahkan lingkaran untuk menunjukkan radius valid
            L.circle(industriCoords, {
                color: 'blue',
                fillColor: '#3388ff',
                fillOpacity: 0.2,
                radius: radiusArea
            }).addTo(map);
            
            // Coba dapatkan lokasi pengguna
            if (navigator.geolocation) {
                navigator.geolocation.getCurrentPosition(
                    function(position) {
                        const userCoords = [position.coords.latitude, position.coords.longitude];
                        
                        // Validasi koordinat pengguna
                        if (!userCoords[0] || !userCoords[1] || isNaN(userCoords[0]) || isNaN(userCoords[1])) {
                            console.error('Koordinat pengguna tidak valid:', userCoords);
                            document.getElementById('location-status').innerHTML = `<div class="alert alert-danger">Koordinat Anda tidak valid. Pastikan GPS aktif.</div>`;
                            document.getElementById('submit-btn').disabled = true;
                            return;
                        }
                        
                        // Update input hidden dengan koordinat
                        document.getElementById('input-latitude').value = userCoords[0];
                        document.getElementById('input-longitude').value = userCoords[1];
                        
                        // Tambahkan marker untuk lokasi pengguna
                        L.marker(userCoords).addTo(map)
                            .bindPopup('Lokasi Anda Sekarang')
                            .openPopup();
                        
                        // Hitung jarak dari pengguna ke industri
                        try {
                            const distance = map.distance(userCoords, industriCoords).toFixed(0);
                            
                            // Update status berdasarkan jarak
                            const locationStatus = document.getElementById('location-status');
                            if (distance <= radiusArea) {
                                locationStatus.innerHTML = `<div class="alert alert-success">Anda berada dalam area industri (${distance}m)</div>`;
                                document.getElementById('submit-btn').disabled = false;
                            } else {
                                locationStatus.innerHTML = `<div class="alert alert-danger">Anda di luar area industri (${distance}m). Presensi tidak dapat dilakukan.</div>`;
                                document.getElementById('submit-btn').disabled = true;
                            }
                        } catch (error) {
                            console.error('Error menghitung jarak:', error);
                            document.getElementById('location-status').innerHTML = `<div class="alert alert-danger">Gagal menghitung jarak. Coba lagi nanti.</div>`;
                            document.getElementById('submit-btn').disabled = true;
                        }
                    },
                    function(error) {
                        console.error('Error getting location:', error);
                        let errorMessage = 'Tidak dapat mengakses lokasi. Pastikan GPS aktif dan izin diberikan.';
                        switch (error.code) {
                            case error.PERMISSION_DENIED:
                                errorMessage = 'Izin lokasi ditolak. Harap izinkan akses di pengaturan browser.';
                                break;
                            case error.POSITION_UNAVAILABLE:
                                errorMessage = 'Informasi lokasi tidak tersedia. Pastikan GPS aktif.';
                                break;
                            case error.TIMEOUT:
                                errorMessage = 'Permintaan lokasi timeout. Coba lagi.';
                                break;
                        }
                        document.getElementById('location-status').innerHTML = `<div class="alert alert-danger">${errorMessage}</div>`;
                        document.getElementById('submit-btn').disabled = true;
                    },
                    {
                        enableHighAccuracy: true, // Gunakan akurasi tinggi untuk GPS
                        timeout: 10000, // Timeout setelah 10 detik
                        maximumAge: 0 // Jangan gunakan cache lokasi
                    }
                );
            } else {
                document.getElementById('location-status').innerHTML = `<div class="alert alert-danger">Geolocation tidak didukung oleh browser Anda.</div>`;
                document.getElementById('submit-btn').disabled = true;
            }
        }
        
        // Akses kamera
        function initCamera() {
            const video = document.getElementById('video');
            const captureBtn = document.getElementById('capture-btn');
            const canvas = document.getElementById('canvas');
            const context = canvas.getContext('2d');
            const photoPreview = document.getElementById('photo-preview');
            
            // Meminta akses ke kamera
            navigator.mediaDevices.getUserMedia({ video: true, audio: false })
                .then(function(stream) {
                    video.srcObject = stream;
                    
                    // Sesuaikan ukuran canvas dengan video
                    video.addEventListener('loadedmetadata', function() {
                        canvas.width = video.videoWidth;
                        canvas.height = video.videoHeight;
                    });
                    
                    // Tangani pengambilan foto
                    captureBtn.addEventListener('click', function() {
                        context.drawImage(video, 0, 0, canvas.width, canvas.height);
                        
                        // Konversi canvas ke data URL (base64)
                        const imageData = canvas.toDataURL('image/png');
                        
                        // Simpan data gambar ke input hidden
                        document.getElementById('input-foto').value = imageData;
                        
                        // Tampilkan pratinjau foto
                        photoPreview.innerHTML = `<img src="${imageData}" class="img-fluid rounded" alt="Foto Presensi" style="max-height: 150px;">`;
                    });
                })
                .catch(function(error) {
                    console.error('Error accessing camera:', error);
                    alert('Tidak dapat mengakses kamera. Pastikan izin diberikan.');
                });
        }
        
        // Handle modal events untuk CRUD
        $('#editModal').on('show.bs.modal', function (event) {
            const button = $(event.relatedTarget);
            const id = button.data('id');
            const data = button.data('data');
            
            const modal = $(this);
            modal.find('#edit_id').val(id);
            
            // Isi form dengan data yang ada
            for (const key in data) {
                if (data.hasOwnProperty(key)) {
                    const input = modal.find('#edit_' + key);
                    if (input.length) {
                        if (input.is('select')) {
                            input.val(data[key]);
                        } else {
                            input.val(data[key]);
                        }
                    }
                    if (key === 'foto' && data[key]) {
                        modal.find('#current_foto').attr('src', data[key]).show();
                        modal.find('#edit_old_foto').val(data[key]);
                    }
                }
            }
        });
        
        $('#deleteModal').on('show.bs.modal', function (event) {
            const button = $(event.relatedTarget);
            const id = button.data('id');
            
            const modal = $(this);
            modal.find('#delete_id').val(id);
        });
        
        // Handle edit izin modal
        $('#editIzinModal').on('show.bs.modal', function (event) {
            const button = $(event.relatedTarget);
            const modal = $(this);
            modal.find('#edit_izin_id').val(button.data('id'));
            modal.find('#edit_tanggal_mulai').val(button.data('tanggal_mulai'));
            modal.find('#edit_tanggal_selesai').val(button.data('tanggal_selesai'));
            modal.find('#edit_jenis').val(button.data('jenis'));
            modal.find('#edit_alasan').val(button.data('alasan'));
        });

        // Handle delete izin modal
        $('#deleteIzinModal').on('show.bs.modal', function (event) {
            const button = $(event.relatedTarget);
            const modal = $(this);
            modal.find('#delete_izin_id').val(button.data('id'));
        });
        
        // Inisialisasi setelah halaman dimuat
        <?php if (isset($_SESSION['user_id']) && $_SESSION['role'] === 'siswa' && $action !== 'perizinan'): ?>
        window.onload = function() {
            initMap();
            initCamera();
        };
        <?php endif; ?>
    </script>

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        // Grafik Presensi Bulanan untuk Siswa
        <?php if ($_SESSION['role'] === 'siswa' && isset($rekapPresensiBulanan)): ?>
        document.addEventListener('DOMContentLoaded', function() {
            const ctx = document.getElementById('presensiChart').getContext('2d');
            const presensiChart = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: ['Ijin', 'Sakit', 'Absen', 'Tepat Waktu', 'Terlambat', 'Pulang Cepat', 'Belum Pulang'],
                    datasets: [{
                        label: 'Jumlah Hari',
                        data: [
                            <?php echo $rekapPresensiBulanan['ijin']; ?>,
                            <?php echo $rekapPresensiBulanan['sakit']; ?>,
                            <?php echo $rekapPresensiBulanan['absen']; ?>,
                            <?php echo $rekapPresensiBulanan['tepat_waktu']; ?>,
                            <?php echo $rekapPresensiBulanan['terlambat']; ?>,
                            <?php echo $rekapPresensiBulanan['pulang_cepat']; ?>,
                            <?php echo $rekapPresensiBulanan['belum_pulang']; ?>
                        ],
                        backgroundColor: [
                            '#ffc107',
                            '#dc3545',
                            '#6c757d',
                            '#28a745',
                            '#fd7e14',
                            '#e83e8c',
                            '#6f42c1'
                        ],
                        borderColor: [
                            '#ffc107',
                            '#dc3545',
                            '#6c757d',
                            '#28a745',
                            '#fd7e14',
                            '#e83e8c',
                            '#6f42c1'
                        ],
                        borderWidth: 1
                    }]
                },
                options: {
                    scales: {
                        y: {
                            beginAtZero: true,
                            title: {
                                display: true,
                                text: 'Jumlah Hari'
                            }
                        },
                        x: {
                            title: {
                                display: true,
                                text: 'Jenis Presensi'
                            }
                        }
                    }
                }
            });
        });
        <?php endif; ?>
    </script>

</body>
</html>
