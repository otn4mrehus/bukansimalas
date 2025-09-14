<?php
ob_start();
// Set zona waktu ke Asia/Jakarta
date_default_timezone_set('Asia/Jakarta');

// Pastikan tidak ada output sebelum session_start() - 5 minutes (300 seconds)
// Start session with custom params
$session_name = 'absensiku_session';
$secure = false; // Set true jika menggunakan HTTPS
$httponly = true; // Mencegah akses cookie via JavaScript
$session_timeout = 900; // 15 menit (900 detik)

$cookieParams = session_get_cookie_params();
session_set_cookie_params([
    'lifetime' => $session_timeout,
    'path' => $cookieParams["path"],
    'domain' => $cookieParams["domain"],
    'secure' => $secure,
    'httponly' => $httponly,
    'samesite' => 'Lax'
]);
session_name($session_name);

if (session_status() == PHP_SESSION_NONE) {
    session_start();
    
    // Check if session is expired
    if (isset($_SESSION['LAST_ACTIVITY']) && (time() - $_SESSION['LAST_ACTIVITY'] > $session_timeout)) {
        // Session expired
        session_unset();
        session_destroy();
        $_SESSION = array();
        
        // Set flag for JavaScript to show alert
        if (!isset($_GET['session_expired'])) {
            header("Location: index.php?page=login&session_expired=1");
            exit();
        }
    }
    $_SESSION['LAST_ACTIVITY'] = time(); // Update last activity time
}

if (session_status() == PHP_SESSION_NONE) {
    session_start();
    
    // Regenerate session ID untuk mencegah session fixation
    if (!isset($_SESSION['CREATED'])) {
        $_SESSION['CREATED'] = time();
    } else if (time() - $_SESSION['CREATED'] > 900) {
        // Session started more than 1 minutes ago
        session_regenerate_id(true);    // Change session ID and delete old session
        $_SESSION['CREATED'] = time(); // Update creation time
    }
}



// Setelah berhasil mengirim izin
if (isset($_SESSION['success_izin'])) {
    $success_izin = $_SESSION['success_izin'];
    unset($_SESSION['success_izin']);
}
// ============ HEADER KEAMANAN ============ //
// Lindungi dari XSS (browser akan memblokir serangan XSS jika dideteksi)
header("X-XSS-Protection: 1; mode=block");

// Content Security Policy (Sesuaikan dengan kebutuhan aplikasi)
//header("Content-Security-Policy: default-src 'self'; img-src 'self' data: https://maps.googleapis.com; style-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com; font-src 'self' https://cdnjs.cloudflare.com; script-src 'self' 'unsafe-inline' 'unsafe-eval';");
// ============ END HEADER KEAMANAN ============ //

// Inisialisasi variabel $page dan $action dengan nilai default
$page = isset($_GET['page']) ? $_GET['page'] : 'login';
$action = isset($_GET['action']) ? $_GET['action'] : '';

// Koneksi ke MySQL server (tanpa memilih database terlebih dahulu)
require "db.php";

$labelkelas = explode('_', $db)[0];
// Pisahkan huruf dan angka di akhir string
preg_match('/^([A-Z]+)(\d+)$/i', $labelkelas, $hasil);
// Gabungkan huruf dan angka dengan tanda strip
$labelkelas = $hasil[1] . '-' . $hasil[2];


// Buat koneksi pertama ke MySQL server
$conn = new mysqli($host, $user, $pass);

// Cek koneksi ke server MySQL
if ($conn->connect_error) {
    die("Koneksi ke server MySQL gagal: " . $conn->connect_error);
}

$upload_dir = __DIR__ . '/uploads/logo/';
if (!file_exists($upload_dir)) {
    if (!mkdir($upload_dir, 0777, true)) {
        $_SESSION['error_pengaturan'] = "Gagal membuat direktori untuk logo!";
        header('Location: index.php?page=admin#tab-pengaturan');
        exit();
    }
}


// Cek apakah database sudah ada
$result = $conn->query("SHOW DATABASES LIKE '$db'");
if ($result->num_rows == 0) {
    // Buat database jika belum ada
    if (!$conn->query("CREATE DATABASE IF NOT EXISTS $db DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci")) {
        die("Gagal membuat database: " . $conn->error);
    }
    
    // Pilih database yang baru dibuat
    $conn->select_db($db);
    
    // Buat semua tabel
    $tables = [
        "absensi_izin" => "CREATE TABLE IF NOT EXISTS absensi_izin (
            id INT(11) NOT NULL AUTO_INCREMENT,
            nis VARCHAR(20) NOT NULL,
            tanggal DATE NOT NULL,
            jenis ENUM('sakit','ijin') NOT NULL,
            keterangan TEXT DEFAULT NULL,
            lampiran VARCHAR(255) DEFAULT NULL,
            status ENUM('pending','diterima','ditolak') DEFAULT 'pending',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        
        /*
        "kelas" => "CREATE TABLE IF NOT EXISTS kelas (
            id INT(11) NOT NULL AUTO_INCREMENT,
            nama_kelas VARCHAR(10) NOT NULL,
            wali_kelas VARCHAR(100) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        */
        
        "pengaturan" => "CREATE TABLE IF NOT EXISTS pengaturan (
            id INT(11) NOT NULL AUTO_INCREMENT,
            latitude DECIMAL(10,8) NOT NULL,
            longitude DECIMAL(11,8) NOT NULL,
            radius INT(11) NOT NULL COMMENT 'dalam meter',
            waktu_masuk TIME NOT NULL DEFAULT '07:30:00', 
            waktu_pulang TIME NOT NULL DEFAULT '15:30:00', 
            tanggal_awal_ganjil DATE NOT NULL, 
            tanggal_awal_genap DATE NOT NULL,
            logo_sekolah VARCHAR(255) DEFAULT NULL,
            nama_sekolah VARCHAR(100) DEFAULT NULL,
            kepala_sekolah VARCHAR(100) DEFAULT NULL,
            nip_kepsek VARCHAR(50) DEFAULT NULL,
            wali_kelas VARCHAR(100) DEFAULT NULL,
            nip_walikelas VARCHAR(50) DEFAULT NULL,
            kota_sekolah VARCHAR(50) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "siswa" => "CREATE TABLE IF NOT EXISTS siswa (
            nis VARCHAR(20) NOT NULL,
            nama VARCHAR(100) NOT NULL,
            password VARCHAR(255) NOT NULL,
    		password_hint VARCHAR(255) DEFAULT NULL, 
            kelas_id INT(11) DEFAULT NULL,
            kelas VARCHAR(20) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (nis),
            KEY kelas_id (kelas_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        
        "presensi" => "CREATE TABLE IF NOT EXISTS presensi (
            id INT(11) NOT NULL AUTO_INCREMENT,
            nis VARCHAR(20) NOT NULL,
            tanggal DATE NOT NULL,
            jam_masuk TIME DEFAULT NULL,
            jam_pulang TIME DEFAULT NULL,
            foto_masuk VARCHAR(255) DEFAULT NULL,
            foto_pulang VARCHAR(255) DEFAULT NULL,
            status_masuk ENUM('tepat waktu','terlambat') DEFAULT 'tepat waktu',
            keterangan_terlambat TEXT DEFAULT NULL,
            lokasi_masuk VARCHAR(50) DEFAULT NULL,
            status_pulang ENUM('tepat waktu','cepat','belum presensi') DEFAULT 'tepat waktu',
            keterangan_pulang_cepat TEXT DEFAULT NULL,
            lokasi_pulang VARCHAR(50) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY nis (nis),
            CONSTRAINT presensi_ibfk_1 FOREIGN KEY (nis) REFERENCES siswa(nis)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "periode_libur" => "CREATE TABLE IF NOT EXISTS periode_libur (
            id INT(11) NOT NULL AUTO_INCREMENT,
            nama_periode VARCHAR(100) NOT NULL,
            tanggal_mulai DATE NOT NULL,
            tanggal_selesai DATE NOT NULL,
            keterangan TEXT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        // Di bagian pembuatan tabel
        "poin_kehadiran" => "CREATE TABLE IF NOT EXISTS poin_kehadiran (
            id INT(11) NOT NULL AUTO_INCREMENT,
            jenis VARCHAR(50) NOT NULL,
            poin INT(11) NOT NULL,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "pulang_cepat" => "CREATE TABLE IF NOT EXISTS pulang_cepat (
            id INT(11) NOT NULL AUTO_INCREMENT,
            nis VARCHAR(20) NOT NULL,
            tanggal DATE NOT NULL,
            keterangan TEXT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        
        "terlambat" => "CREATE TABLE IF NOT EXISTS terlambat (
            id INT(11) NOT NULL AUTO_INCREMENT,
            nis VARCHAR(20) NOT NULL,
            tanggal DATE NOT NULL,
            keterangan TEXT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    ];

    foreach ($tables as $tableName => $sql) {
        if (!$conn->query($sql)) {
            die("Gagal membuat tabel $tableName: " . $conn->error);
        }
    }

    // Insert data default  [Pass: 123]
    $password_hash_1 = '$2y$10$TaIHxsQzHlzRuJdQU9k6Mu44ZhFQjpWOj6SIm12vygyaQfhF8jenu';   //123
    //$password_hash_2 = '$2y$10$TaIHxsQzHlzRuJdQU9k6Mu44ZhFQjpWOj6SIm12vygyaQfhF8jenu';

    // password '123'
    $defaultData = [
        "siswa" => "INSERT INTO siswa (nis, nama, password, kelas_id, kelas) VALUES 
         
         ('123', 'Demo Murid', '$password_hash_1', NULL, NULL)",

        "poin_kehadiran" => "INSERT INTO poin_kehadiran (jenis, poin) VALUES 
            ('hadir_tepat_waktu', 5),
            ('hadir_terlambat', 3),
            ('pulang_tepat_waktu', 5),
            ('pulang_cepat', 3),
            ('sakit', 1),
            ('ijin', 1),
            ('tidak_hadir', 0),
            ('belum_presensi_pulang', 2);", 

        "pengaturan" => "INSERT INTO pengaturan (latitude, longitude, radius, waktu_masuk, waktu_pulang, tanggal_awal_ganjil, tanggal_awal_genap) VALUES 
            ('-6.084264', '106.191407', 100, '07:30:00', '15:30:00', 
            '" . date('Y') . "-07-01',  
            '" . date('Y') . "-01-01')" 
    ];

    foreach ($defaultData as $table => $sql) {
        if (!$conn->query($sql)) {
            die("Gagal insert data default ke tabel $table: " . $conn->error);
        }
    }

    echo "Database dan tabel berhasil dibuat serta diisi dengan data awal.";
} else {
    // Jika database sudah ada, hanya pilih database
    $conn->select_db($db);
}


// ============ HANDLER HAPUS MASSAL ============ //
// LOGO
if (isset($_GET['action']) && $_GET['action'] == 'delete_logo') {
    $sql = "SELECT logo_sekolah FROM pengaturan LIMIT 1";
    $result = $conn->query($sql);
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $logo = $row['logo_sekolah'];
        
        if ($logo) {
            $file_path = __DIR__ . '/uploads/logo/' . $logo;
            if (file_exists($file_path)) {
                unlink($file_path);
            }
            
            $conn->query("UPDATE pengaturan SET logo_sekolah = NULL");
            $_SESSION['success_pengaturan'] = "Logo sekolah berhasil dihapus!";
        }
    }
    header('Location: index.php?page=admin#tab-pengaturan');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    if ($_POST['action'] == 'delete_selected_presensi' && !empty($_POST['selected_ids'])) {
	    $ids = implode(',', array_map('intval', $_POST['selecteApakah Anda yakin ingin menghapus 1 data terpilih?d_ids']));
	    $sql = "DELETE FROM presensi WHERE id IN ($ids)";
	    if ($conn->query($sql)) {
		  $_SESSION['success_admin'] = count($_POST['selected_ids']) . " data presensi berhasil dihapus!";
	    } else {
		  $_SESSION['error_admin'] = "Error: " . $conn->error;
		  error_log("Gagal menghapus presensi: " . $conn->error);
	    }
	    header("Location: index.php?page=admin#tab-presensi");
	    exit();
	} else if ($_POST['action'] == 'delete_selected_presensi') {
	    $_SESSION['error_admin'] = "Tidak ada data yang dipilih untuk dihapus.";
	    header("Location: index.php?page=admin#tab-presensi");
	    exit();
	}

    // Hapus massal izin
    if ($_POST['action'] == 'delete_selected_izin' && !empty($_POST['selected_ids'])) {
        $ids = implode(',', array_map('intval', $_POST['selected_ids']));
        $sql = "DELETE FROM absensi_izin WHERE id IN ($ids)";
        if ($conn->query($sql)) {
            $_SESSION['success_admin'] = count($_POST['selected_ids']) . " data izin berhasil dihapus!";
        } else {
            $_SESSION['error_admin'] = "Error: " . $conn->error;
        }
	  // Tambahkan session write sebelum redirect
	  session_write_close(); // Pastikan session disimpan
        header("Location: index.php?page=admin#tab-pengajuan");
        exit();
    }

    // Proses untuk mengambil data izin bulanan (AJAX endpoint)
    if (isset($_GET['action']) && $_GET['action'] == 'get_izin' && isset($_GET['id'])) {
        $id = $_GET['id'];
        $sql = "SELECT * FROM absensi_izin WHERE id = $id";
        $result = $conn->query($sql);
        
        if ($result->num_rows > 0) {
            $data = $result->fetch_assoc();
            header('Content-Type: application/json');
            echo json_encode($data);
            exit();
        } else {
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Data tidak ditemukan']);
            exit();
        }
    }
    // Hapus massal periode libur
    if ($_POST['action'] == 'delete_selected_libur' && !empty($_POST['selected_ids'])) {
        $ids = implode(',', array_map('intval', $_POST['selected_ids']));
        $sql = "DELETE FROM periode_libur WHERE id IN ($ids)";
        if ($conn->query($sql)) {
            $_SESSION['success_admin'] = count($_POST['selected_ids']) . " periode libur berhasil dihapus!";
        } else {
            $_SESSION['error_admin'] = "Error: " . $conn->error;
        }
	  // Tambahkan session write sebelum redirect
	  session_write_close(); // Pastikan session disimpan
        header("Location: index.php?page=admin#tab-libur");
        exit();
    }
}
// ============ END HANDLER HAPUS MASSAL ============ //

// Cek apakah ada data pengaturan
$sql_pengaturan = "SELECT * FROM pengaturan ORDER BY id DESC LIMIT 1";
$result_pengaturan = $conn->query($sql_pengaturan);

if ($result_pengaturan->num_rows > 0) {
    $pengaturan = $result_pengaturan->fetch_assoc();
    $latSekolah = $pengaturan['latitude'];
    $lngSekolah = $pengaturan['longitude'];
    $radiusSekolah = $pengaturan['radius'];
    $jamMasuk = $pengaturan['waktu_masuk'];    // Fixed column name
    $jamPulang = $pengaturan['waktu_pulang'];  // Fixed column name
} else {
    // Default jika tidak ada pengaturan
    $latSekolah = -6.4105;
    $lngSekolah = 106.8440;
    $radiusSekolah = 100;
    $jamMasuk = '07:30:00';
    $jamPulang = '15:30:00';   
    // Insert data default
    $conn->query("INSERT INTO pengaturan (latitude, longitude, radius, waktu_masuk, waktu_pulang) VALUES ($latSekolah, $lngSekolah, $radiusSekolah, '$jamMasuk', '$jamPulang')");
}

// Fungsi untuk Format Waktu (Hari dan Tanggal Indonesia)
function formatTanggalID($tgl) {
    $hari = ['Minggu','Senin','Selasa','Rabu','Kamis','Jumat','Sabtu'];
    $bulan = ['','Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];
    
    $date = date_create($tgl);
    $hariIndo = $hari[date_format($date, 'w')];
    $tglIndo = date_format($date, 'j');
    $blnIndo = $bulan[(int)date_format($date, 'n')];
    $thnIndo = date_format($date, 'Y');
    
    return "$hariIndo, $tglIndo $blnIndo $thnIndo";
}

// Fungsi untuk menghitung selisih waktu dalam format yang benar
function hitungSelisihWaktu($waktuAwal, $waktuAkhir) {
    $awal = DateTime::createFromFormat('H:i:s', $waktuAwal);
    $akhir = DateTime::createFromFormat('H:i:s', $waktuAkhir);
    
    if (!$awal || !$akhir) return null;
    
    $selisih = $akhir->diff($awal);
    
    $menitTotal = ($selisih->h * 60) + $selisih->i;
    
    if ($menitTotal >= 60) {
        $jam = floor($menitTotal / 60);
        $menit = $menitTotal % 60;
        return "{$jam} Jam {$menit} Menit";
    }
    
    return "{$menitTotal} Menit";
}

// Fungsi untuk kompres gambar
function compressImage($source, $destination, $quality) {
    if (!file_exists($source)) {
        return false;
    }
    
    $info = getimagesize($source);
    
    if ($info['mime'] == 'image/jpeg') {
        $image = imagecreatefromjpeg($source);
        imagejpeg($image, $destination, $quality);
    } elseif ($info['mime'] == 'image/png') {
        $image = imagecreatefrompng($source);
        imagepng($image, $destination, round(9 * $quality / 100));
    } else {
        return false;
    }
    
    return true;
}

// Fungsi untuk menghitung jarak
function hitungJarak($lat1, $lon1, $lat2, $lon2) {
    $theta = $lon1 - $lon2;
    $dist = sin(deg2rad($lat1)) * sin(deg2rad($lat2)) +  cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * cos(deg2rad($theta));
    $dist = acos($dist);
    $dist = rad2deg($dist);
    $miles = $dist * 60 * 1.1515;
    return ($miles * 1609.344); // Meter
}

// Proses login terpadu untuk admin dan siswa
if (isset($_POST['login'])) {
    $identifier = $_POST['identifier'];
    $password = $_POST['password'];
    
    // Coba login sebagai siswa
    $sql = "SELECT * FROM siswa WHERE nis = '$identifier'";
    $result = $conn->query($sql);
    
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        if (password_verify($password, $row['password'])) {
            $_SESSION['nis'] = $identifier;
            $_SESSION['nama'] = $row['nama'];
            header('Location: index.php?page=presensi');
            exit();
        } else {
            $error = "Password salah!";
        }
    } else {
        // Perbaikan kondisi login
        if (($identifier == 'admin' && $password == 'admin123') || ($identifier == 'walas' && $password == 'walas123')) {
            $_SESSION['admin'] = true;
            $_SESSION['walas'] = true;
            header('Location: index.php?page=admin');
            exit();
        } else {
            $error = "NISN/Username atau password salah!";
        }
    }
}

// Proses untuk mengambil data presensi (AJAX endpoint)
if (isset($_GET['action']) && $_GET['action'] == 'get_presensi' && isset($_GET['id'])) {
    $id = $_GET['id'];
    $sql = "SELECT * FROM presensi WHERE id = $id";
    $result = $conn->query($sql);
    
    if ($result->num_rows > 0) {
        $data = $result->fetch_assoc();
        header('Content-Type: application/json');
        echo json_encode($data);
        exit();
    } else {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Data tidak ditemukan']);
        exit();
    }
}

// Proses untuk mengambil data periode libur (AJAX endpoint)
if (isset($_GET['action']) && $_GET['action'] == 'get_periode_libur' && isset($_GET['id'])) {
    $id = $_GET['id'];
    $sql = "SELECT * FROM periode_libur WHERE id = $id";
    $result = $conn->query($sql);
    
    if ($result->num_rows > 0) {
        $data = $result->fetch_assoc();
        header('Content-Type: application/json');
        echo json_encode($data);
        exit();
    } else {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Data tidak ditemukan']);
        exit();
    }
}

// Proses update status dan catatan izin
if (isset($_POST['update_status_dan_catatan'])) {
    $id = $_POST['id'];
    $status = $_POST['status'];
    $catatan = $_POST['catatan'] ?? null;
    
    // Gunakan prepared statement untuk keamanan
    $sql = "UPDATE absensi_izin SET status = ?, catatan = ? WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ssi", $status, $catatan, $id);
    
    if ($stmt->execute()) {
        $_SESSION['success_admin'] = "Status dan catatan izin berhasil diperbarui!";
    } else {
        $_SESSION['error_admin'] = "Error: " . $stmt->error;
    }
    $stmt->close();
    
    // Redirect ke tab yang sesuai
    header('Location: index.php?page=admin#tab-pengajuan-bulanan');
    exit();
}

// Proses Update Presensi
if (isset($_POST['update_presensi'])) {
    $id = $_POST['id'];
    $tanggal = $_POST['tanggal'];
    $jam_masuk = $_POST['jam_masuk'];
    $jam_pulang = $_POST['jam_pulang'];
    $catatan = $_POST['catatan'] ?? null;

    // Validasi format waktu
    if ($jam_masuk && !preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $jam_masuk)) {
        $_SESSION['error_admin'] = "Format jam masuk tidak valid";
        header("Location: index.php?page=admin#tab-presensi");
        exit();
    }
    if ($jam_pulang && !preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $jam_pulang)) {
        $_SESSION['error_admin'] = "Format jam pulang tidak valid";
        header("Location: index.php?page=admin#tab-presensi");
        exit();
    }
    
    // Hitung status masuk berdasarkan jam baru
    $status_masuk = 'tepat waktu';
    $keterangan_terlambat = null;
    
    if ($jam_masuk) {
        // Bandingkan dengan jam masuk setting
        $selisih = hitungSelisihWaktu($jamMasuk, $jam_masuk);
        
        if ($selisih !== null && $jam_masuk > $jamMasuk) {
            $status_masuk = 'terlambat';
            $keterangan_terlambat = $selisih;
        }
    }
    
    // Hitung status pulang berdasarkan jam baru
    $status_pulang = 'tepat waktu';
    $keterangan_pulang_cepat = null;
    
    if ($jam_pulang) {
        $selisih = hitungSelisihWaktu($jam_pulang, $jamPulang);
        
        if ($selisih !== null && $jam_pulang < $jamPulang) {
            $status_pulang = 'cepat';
            $keterangan_pulang_cepat = $selisih;
            $_SESSION['show_pulang_cepat_modal'] = true;
        }
    }
    
    $sql = "UPDATE presensi SET 
            tanggal = '$tanggal',
            jam_masuk = " . ($jam_masuk ? "'$jam_masuk'" : "NULL") . ",
            jam_pulang = " . ($jam_pulang ? "'$jam_pulang'" : "NULL") . ",
            status_masuk = '$status_masuk',
            keterangan_terlambat = " . ($keterangan_terlambat ? "'$keterangan_terlambat'" : "NULL") . ",
            status_pulang = '$status_pulang',
            keterangan_pulang_cepat = " . ($keterangan_pulang_cepat ? "'$keterangan_pulang_cepat'" : "NULL") . ",
            catatan = " . ($catatan ? "'$catatan'" : "NULL") . "
            WHERE id = $id";
    
    if ($conn->query($sql)) {
        $_SESSION['success_admin'] = "Data presensi berhasil diperbarui!";
    } else {
        $_SESSION['error_admin'] = "Error: " . $conn->error;
    }
    header("Location: index.php?page=admin#tab-presensi");
    exit();
}

// Proses Update Poin Presensi
if (isset($_POST['update_poin'])) {
    $id = $_POST['id'];
    $poin = $_POST['poin'];
    
    $sql = "UPDATE poin_kehadiran SET poin = $poin WHERE id = $id";
    if ($conn->query($sql)) {
        $_SESSION['success_admin'] = "Poin berhasil diperbarui!";
    } else {
        $_SESSION['error_admin'] = "Error: " . $conn->error;
    }
}

if (isset($_POST['reset_poin'])) {
    $id = $_POST['id'];
    $defaults = [
        1 => 5, // hadir_tepat_waktu
        2 => 3, // hadir_terlambat
        3 => 5, // pulang_tepat_waktu
        4 => 3, // pulang_cepat
        5 => 1, // sakit
        6 => 1, // ijin
        7 => 0  // tidak_hadir
    ];
    
    if (isset($defaults[$id])) {
        $sql = "UPDATE poin_kehadiran SET poin = {$defaults[$id]} WHERE id = $id";
        if ($conn->query($sql)) {
            $_SESSION['success_admin'] = "Poin berhasil direset!";
        }
    }
}



// Proses Hapus Presensi
if (isset($_GET['action']) && $_GET['action'] == 'delete_presensi' && isset($_GET['id'])) {
    $id = $_GET['id'];
    $sql = "DELETE FROM presensi WHERE id = $id";
    
    if ($conn->query($sql)) {
        $_SESSION['success_admin'] = "Data presensi berhasil dihapus!";
    } else {
        $_SESSION['error_admin'] = "Error: " . $conn->error;
    }
    header("Location: index.php?page=admin#tab-presensi");
    exit();
}
// End Proses untuk mengambil data presensi (AJAX endpoint)

// Fungsi Reset Password dengan Hint
if (isset($_POST['reset_password'])) {
    $nis = $_POST['nis'];
    $hint_answer = $_POST['hint_answer'];
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];
    
    // Validasi password
    if ($new_password !== $confirm_password) {
        $reset_error = "Password baru dan konfirmasi password tidak cocok!";
    } else {
        // Cek apakah NIS ada di database
        $sql = "SELECT * FROM siswa WHERE nis = '$nis'";
        $result = $conn->query($sql);
        
        if ($result->num_rows > 0) {
            $row = $result->fetch_assoc();
            
            // Verifikasi hint answer
            if (empty($row['password_hint']) || $hint_answer !== $row['password_hint']) {
                $reset_error = "Jawaban hint salah!";
            } else {
                // Hash password baru
                $password_hashed = password_hash($new_password, PASSWORD_DEFAULT);
                
                // Update password
                $update_sql = "UPDATE siswa SET password = '$password_hashed' WHERE nis = '$nis'";
                
                if ($conn->query($update_sql)) {
                    $reset_success = "Password berhasil direset! Silakan login dengan password baru Anda.";
                } else {
                    $reset_error = "Error: " . $conn->error;
                }
            }
        } else {
            $reset_error = "NISN tidak ditemukan!";
        }
    }
}

// Proses CRUD Siswa
$nis_edit = isset($_GET['nis']) ? $_GET['nis'] : '';

if ($action == 'edit_siswa' && $nis_edit != '') {
    $sql = "SELECT * FROM siswa WHERE nis = '$nis_edit'";
    $result = $conn->query($sql);
    $siswa_edit = $result->fetch_assoc();
}

if (isset($_POST['save_siswa'])) {
    $nis = $_POST['nis'];
    $nama = $_POST['nama'];
    $password = $_POST['password'];
    
    $password_hint = $_POST['password_hint'];   // Di proses save_siswa

    if (!empty($password)) {
        $password_hashed = password_hash($password, PASSWORD_DEFAULT);
        $sql = "UPDATE siswa SET nama = '$nama', password = '$password_hashed', password_hint = '$password_hint' WHERE nis = '$nis'";
    } else {
        $sql = "UPDATE siswa SET nama = '$nama', password_hint = '$password_hint' WHERE nis = '$nis'";
    }
    
    if ($conn->query($sql) === TRUE) {
        $success_siswa = "Data siswa berhasil diperbarui!";
        // Redirect untuk menghindari form resubmission
        header('Location: index.php?page=admin#tab-siswa');
        exit();
    } else {
        $error_siswa = "Error: " . $conn->error;
    }
}

if (isset($_POST['delete_siswa'])) {
    $nis = $_POST['nis'];
    $sql = "DELETE FROM siswa WHERE nis = '$nis'";
    
    if ($conn->query($sql) === TRUE) {
        $success_siswa = "Siswa berhasil dihapus!";
        // Redirect untuk menghindari form resubmission
        header('Location: index.php?page=admin#tab-siswa');
        exit();
    } else {
        $error_siswa = "Error: " . $conn->error;
    }
}

if (isset($_POST['add_siswa'])) {
    $nis = $_POST['nis'];
    $nama = $_POST['nama'];
    $password = $_POST['password'];
    $password_hint = $_POST['password_hint'];
    $password_hashed = password_hash($password, PASSWORD_DEFAULT);
    $sql = "INSERT INTO siswa (nis, nama, password, password_hint) VALUES ('$nis', '$nama', '$password_hashed', '$password_hint')";
    if ($conn->query($sql) === TRUE) {
        $success_siswa = "Siswa berhasil ditambahkan!";
        // Redirect untuk menghindari form resubmission
        header('Location: index.php?page=admin#tab-siswa');
        exit();
    } else {
        $error_siswa = "Error: " . $conn->error;
    }
}

// Proses simpan pengaturan
if (isset($_POST['save_pengaturan'])) {
    $latitude = $_POST['latitude'];
    $longitude = $_POST['longitude'];
    $radius = $_POST['radius'];
    $jamMasuk = $_POST['jamMasuk'];   // NEW
    $jamPulang =  $_POST['jamPulang']; // NEW
    $tanggal_awal_ganjil = $_POST['tanggal_awal_ganjil'];
    $tanggal_awal_genap = $_POST['tanggal_awal_genap'];

     // Data baru
     $nama_sekolah = $_POST['nama_sekolah'];
     $kepala_sekolah = $_POST['kepala_sekolah'];
     $nip_kepsek = $_POST['nip_kepsek'];
     $wali_kelas = $_POST['wali_kelas'];
     $nip_walikelas = $_POST['nip_walikelas'];
     $kota_sekolah = $_POST['kota_sekolah'];
     // Proses upload logo
     $logo_sekolah = $pengaturan['logo_sekolah'] ?? null;
     if (isset($_FILES['logo_sekolah']) && $_FILES['logo_sekolah']['error'] == UPLOAD_ERR_OK) {
         $file = $_FILES['logo_sekolah'];
         
         // Validasi file
         $allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
         if (!in_array($file['type'], $allowed_types)) {
             $_SESSION['error_pengaturan'] = "Format file logo tidak didukung. Gunakan JPG, PNG atau GIF.";
             header('Location: index.php?page=admin#tab-pengaturan');
             exit();
         }
         
         // Generate nama file unik
         $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
         $namaFile = 'logo-' . time() . '.' . $ext;
         $targetDir = __DIR__ . '/uploads/logo/';
         
         // Buat direktori jika belum ada
         if (!file_exists($targetDir)) {
             mkdir($targetDir, 0777, true);
         }
         
         // Pindahkan file
         if (move_uploaded_file($file['tmp_name'], $targetDir . $namaFile)) {
             // Hapus logo lama jika ada
             if ($logo_sekolah && file_exists($targetDir . $logo_sekolah)) {
                 unlink($targetDir . $logo_sekolah);
             }
             $logo_sekolah = $namaFile;
         } else {
             $_SESSION['error_pengaturan'] = "Gagal menyimpan logo. Pastikan folder uploads memiliki izin tulis.";
             header('Location: index.php?page=admin#tab-pengaturan');
             exit();
         }
     }
    
    // Update atau insert
    $check = $conn->query("SELECT id FROM pengaturan ORDER BY id ASC LIMIT 1");
    
    if ($check->num_rows > 0) {
        $row = $check->fetch_assoc();
        $id = $row['id'];
        $sql = "UPDATE pengaturan SET 
                latitude='$latitude', 
                longitude='$longitude', 
                radius='$radius',
                waktu_masuk='$jamMasuk',
                waktu_pulang='$jamPulang',
                tanggal_awal_ganjil='$tanggal_awal_ganjil', 
                tanggal_awal_genap='$tanggal_awal_genap',
                logo_sekolah=" . ($logo_sekolah ? "'$logo_sekolah'" : "NULL") . ",
                nama_sekolah='$nama_sekolah',
                kepala_sekolah='$kepala_sekolah',
                nip_kepsek='$nip_kepsek',
                wali_kelas='$wali_kelas',
                nip_walikelas='$nip_walikelas',
                kota_sekolah='$kota_sekolah'
                WHERE id=$id";
    } else {
        $sql = "INSERT INTO pengaturan (
                latitude, longitude, radius, 
                waktu_masuk, waktu_pulang,
                tanggal_awal_ganjil, tanggal_awal_genap,
                logo_sekolah, nama_sekolah,
                kepala_sekolah, nip_kepsek,
                wali_kelas, nip_walikelas,
                kota_sekolah
                ) VALUES (
                '$latitude', '$longitude', '$radius',
                '$jamMasuk', '$jamPulang',
                '$tanggal_awal_ganjil', '$tanggal_awal_genap',
                " . ($logo_sekolah ? "'$logo_sekolah'" : "NULL") . ",
                '$nama_sekolah',
                '$kepala_sekolah', '$nip_kepsek',
                '$wali_kelas', '$nip_walikelas',
                '$kota_sekolah'
                )";
    }
    
    if ($conn->query($sql) === TRUE) {
        $_SESSION['success_pengaturan'] = "Pengaturan berhasil disimpan!";
    } else {
        $_SESSION['error_pengaturan'] = "Error: " . $conn->error;
        error_log("Error update pengaturan: " . $sql . " - " . $conn->error); // Log error untuk debugging     
    }
    header('Location: index.php?page=admin#tab-pengaturan');
    exit();
}

// Proses delete izin (single)
if (isset($_GET['action']) && $_GET['action'] == 'delete_izin' && isset($_GET['id'])) {
    $id = $_GET['id'];
    
    // Hapus file lampiran jika ada
    $sql_select = "SELECT lampiran FROM absensi_izin WHERE id = $id";
    $result = $conn->query($sql_select);
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        if ($row['lampiran']) {
            $file_path = __DIR__ . '/uploads/lampiran/' . $row['lampiran'];
            if (file_exists($file_path)) {
                unlink($file_path);
            }
        }
    }
    
    // Hapus data dari database
    $sql = "DELETE FROM absensi_izin WHERE id = $id";
    
    if ($conn->query($sql)) {
        $_SESSION['success_admin'] = "Data izin berhasil dihapus!";
    } else {
        $_SESSION['error_admin'] = "Error: " . $conn->error;
    }
    
    // Redirect ke tab yang sesuai
    header('Location: index.php?page=admin#tab-pengajuan-bulanan');
    exit();
}

//////////////////////////  HAPUS MASSAL DENGAN MULTI SELECT /////////////
// Proses delete massal izin
if (isset($_POST['action']) && $_POST['action'] == 'delete_selected_izin_bulanan' && !empty($_POST['selected_ids'])) {
    $ids = implode(',', array_map('intval', $_POST['selected_ids']));
    
    // Hapus file lampiran terlebih dahulu
    $sql_select = "SELECT lampiran FROM absensi_izin WHERE id IN ($ids)";
    $result = $conn->query($sql_select);
    if ($result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            if ($row['lampiran']) {
                $file_path = __DIR__ . '/uploads/lampiran/' . $row['lampiran'];
                if (file_exists($file_path)) {
                    unlink($file_path);
                }
            }
        }
    }
    
    // Hapus data dari database
    $sql = "DELETE FROM absensi_izin WHERE id IN ($ids)";
    if ($conn->query($sql)) {
        $_SESSION['success_admin'] = count($_POST['selected_ids']) . " data izin berhasil dihapus!";
    } else {
        $_SESSION['error_admin'] = "Error: " . $conn->error;
    }
    
    // Redirect ke tab yang sesuai
    header('Location: index.php?page=admin#tab-pengajuan-bulanan');
    exit();
}


// Proses hapus periode libur
if ($action == 'delete_periode_libur' && isset($_GET['id'])) {
    $id = $_GET['id'];
    $sql = "DELETE FROM periode_libur WHERE id = $id";
    if ($conn->query($sql)) {
        $success_admin = "Periode libur berhasil dihapus!";
    } else {
        $error_admin = "Error: " . $conn->error;
    }
    header('Location: index.php?page=admin#tab-libur');
    exit();
}

//////////////////////////  END HAPUS MASSAL DENGAN MULTI SELECT /////////////

// Proses simpan keterangan terlambat
if (isset($_POST['save_keterangan_terlambat'])) {
    $nis = $_SESSION['nis'];
    $tanggal = date('Y-m-d');
    $keterangan = $_POST['keterangan'];
    
    $sql = "INSERT INTO terlambat (nis, tanggal, keterangan) VALUES ('$nis', '$tanggal', '$keterangan')";
    
    if ($conn->query($sql) === TRUE) {
        $_SESSION['show_terlambat_modal'] = false;
        header('Location: index.php?page=presensi');
        exit();
    } else {
        $error = "Error menyimpan keterangan: " . $conn->error;
    }
}

// Proses simpan keterangan pulang cepat
if (isset($_POST['save_keterangan_pulang_cepat'])) {
    $nis = $_POST['nis'];
    $tanggal = $_POST['tanggal'];
    $keterangan = $_POST['keterangan'];
    
    // Simpan ke tabel pulang_cepat
    $sql = "INSERT INTO pulang_cepat (nis, tanggal, keterangan) 
            VALUES ('$nis', '$tanggal', '$keterangan')";
    
    if ($conn->query($sql)) {
        $_SESSION['show_pulang_cepat_modal'] = false;
        header('Location: index.php?page=presensi');
        exit();
    } else {
        $error = "Error menyimpan keterangan: " . $conn->error;
    }
}


// Proses pengajuan izin
if (isset($_POST['ajukan_izin'])) {
    $nis = $_SESSION['nis'];
    $tanggal = $_POST['tanggal'];
    $jenis = $_POST['jenis'];
    $keterangan = $_POST['keterangan'];
    $lampiran = '';
    
    // Cek duplikat izin untuk tanggal yang sama
    $cek_sql = "SELECT id FROM absensi_izin 
                WHERE nis = '$nis' AND tanggal = '$tanggal' 
                LIMIT 1";
    $cek_result = $conn->query($cek_sql);

    if ($cek_result->num_rows > 0) {
        $error_izin = "Anda sudah mengajukan izin/sakit untuk tanggal ini!";
    } else { 
        // Proses lampiran jika ada
        if (isset($_FILES['lampiran']) && $_FILES['lampiran']['error'] == UPLOAD_ERR_OK) {
            $file = $_FILES['lampiran'];
            $fileExt = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            
            // Validasi tipe file
            $allowed_types = ['jpg', 'jpeg', 'png', 'gif', 'pdf'];
            if (!in_array($fileExt, $allowed_types)) {
                $error_izin = "Format file tidak didukung. Gunakan JPG, PNG, GIF, atau PDF.";
            } 
            // Validasi ukuran file (max 2MB)
            elseif ($file['size'] > 2097152) {
                $error_izin = "Ukuran file terlalu besar. Maksimal 2MB.";
            }
            else {
                // Tentukan nama file berdasarkan jenis izin
                $namaFile = ($jenis == 'sakit') ? "sakit-$nis-" . date('YmdHis') . ".$fileExt" : "izin-$nis-" . date('YmdHis') . ".$fileExt";
                
                // Gunakan path absolute
                $baseDir = __DIR__;
                $lampiranDir = "$baseDir/uploads/lampiran";
                $subDir = ($jenis == 'sakit') ? "$lampiranDir/sakit" : "$lampiranDir/ijin";
                
                // Buat direktori jika belum ada
                if (!file_exists($subDir)) {
                    if (!mkdir($subDir, 0777, true) && !is_dir($subDir)) {
                        $error_izin = "Gagal membuat direktori: $subDir";
                    }
                }
                
                if (!isset($error_izin)) {
                    $targetFile = "$subDir/$namaFile";
                    
                    // Pindahkan file upload
                    if (move_uploaded_file($file['tmp_name'], $targetFile)) {
                        // Untuk keperluan database, simpan path relatif
                        $lampiran = ($jenis == 'sakit') ? "sakit/$namaFile" : "ijin/$namaFile";
                    } else {
                        $error_izin = "Gagal menyimpan lampiran!";
                    }
                }
            }
        }
        
        // Jika tidak ada error, lanjut simpan ke database
        if (!isset($error_izin)) {
            $sql = "INSERT INTO absensi_izin (nis, tanggal, jenis, keterangan, lampiran) 
                    VALUES ('$nis', '$tanggal', '$jenis', '$keterangan', '$lampiran')";
            
            if ($conn->query($sql) === TRUE) {
                $_SESSION['success_izin'] = "Pengajuan izin berhasil dikirim!";
                header('Location: index.php?page=izin');
                exit();
            } else {
                $error_izin = "Error database: " . $conn->error;
            }
        }
    }
}


// Proses ubah status izin (admin)
if (isset($_POST['update_status_izin'])) {
    $id = $_POST['id'];
    $status = $_POST['status'];
    $catatan = $_POST['catatan'] ?? null;
    
    $sql = "UPDATE absensi_izin SET 
            status = '$status', 
            catatan = " . ($catatan ? "'$catatan'" : "NULL") . "
            WHERE id = $id";
    
    if ($conn->query($sql) === TRUE) {
        $success_admin = "Status izin berhasil diperbarui!";
    } else {
        $error_admin = "Error: " . $conn->error;
    }
}

// Proses presensi
if (isset($_POST['presensi'])) {
    $nis = $_SESSION['nis'];
    $jenis = $_POST['jenis_presensi'];
    $latitude = $_POST['latitude'];
    $longitude = $_POST['longitude'];
    $tanggal = date('Y-m-d');
    
    // Gunakan pengaturan dari database
    $jarak = hitungJarak($latSekolah, $lngSekolah, $latitude, $longitude);
    
    // Radius dari database
    if ($jarak > $radiusSekolah) {
        $error = "Anda berada di luar area sekolah! (".round($jarak)." Meter dari pusat)";
    } else {
        // Cek apakah sudah ada presensi hari ini
        $cek_sql = "SELECT * FROM presensi WHERE nis = '$nis' AND tanggal = '$tanggal'";
        $cek_result = $conn->query($cek_sql);
        $row_presensi = $cek_result->fetch_assoc();
        
        // Jika presensi masuk
        if ($jenis == 'masuk') {
            // Jika sudah ada presensi masuk hari ini
            if ($row_presensi && $row_presensi['jam_masuk']) {
                $error = "Anda sudah melakukan presensi masuk hari ini!";
            } else {
                // Proses foto
                if (isset($_FILES['foto']) && $_FILES['foto']['error'] == UPLOAD_ERR_OK) {
                    $foto = $_FILES['foto'];
                    $namaFile = "foto-masuk-$nis-" . date('H.i-d.m.Y') . ".jpg";
                    
                    // Gunakan path absolute
                    $baseDir = __DIR__;
                    $fotoDir = $baseDir . '/uploads/foto/masuk';
                    
                    // Buat direktori jika belum ada
                    if (!file_exists($fotoDir)) {
                        if (!mkdir($fotoDir, 0777, true)) {
                            $error = "Gagal membuat folder untuk menyimpan foto!";
                        }
                    }
                    
                    $targetFile = $fotoDir . '/' . $namaFile;
                    
                    // Pindahkan file upload
                    if (move_uploaded_file($foto['tmp_name'], $targetFile)) {
                        // Kompres gambar ke 500KB
                        if (compressImage($targetFile, $targetFile, 60)) {
                            // Simpan data presensi
                            $waktu = date('H:i:s');
                            
                            // Tentukan status kehadiran
                            $status_masuk = 'tepat waktu';
                            $keterangan_terlambat = null;

                            // Perbaikan perhitungan terlambat
                            $selisih = hitungSelisihWaktu($jamMasuk, $waktu);
                            
                            if ($selisih !== null && $waktu > $jamMasuk) {
                                $status_masuk = 'terlambat';
                                $keterangan_terlambat = $selisih;
                                $_SESSION['show_terlambat_modal'] = true;
                            }
                          
                            // Simpan lokasi presensi
                            $lokasi = "$latitude,$longitude";
                            
                            if ($row_presensi) {
                                // Update jika sudah ada (mungkin hanya ada pulang sebelumnya, tapi seharusnya tidak)
                                $update_sql = "UPDATE presensi SET 
                                    jam_masuk = '$waktu', 
                                    foto_masuk = '$namaFile', 
                                    status_masuk = '$status_masuk',
                                    keterangan_terlambat = " . ($keterangan_terlambat ? "'$keterangan_terlambat'" : "NULL") . ",
                                    lokasi_masuk = '$lokasi' 
                                    WHERE nis = '$nis' AND tanggal = '$tanggal'";
                                    
                                if ($conn->query($update_sql) === TRUE) {
                                    $success = "Presensi masuk berhasil dicatat!";
                                } else {
                                    $error = "Error update: " . $conn->error;
                                }
                            } else {
                                // Insert baru
                                $insert_sql = "INSERT INTO presensi (nis, tanggal, jam_masuk, foto_masuk, status_masuk, keterangan_terlambat, lokasi_masuk) 
                                        VALUES ('$nis', '$tanggal', '$waktu', '$namaFile', '$status_masuk', " . ($keterangan_terlambat ? "'$keterangan_terlambat'" : "NULL") . ", '$lokasi')";
                                        
                                if ($conn->query($insert_sql) === TRUE) {
                                    $success = "Presensi masuk berhasil dicatat!";
                                } else {
                                    $error = "Error insert: " . $conn->error;
                                }
                            }
                        } else {
                            $error = "Gagal mengkompres foto!";
                        }
                    } else {
                        $error = "Gagal menyimpan foto! Pastikan folder 'uploads/foto' memiliki izin tulis.";
                    }
                } else {
                    $error = "Foto tidak terupload! Error: " . $_FILES['foto']['error'];
                }
            }
        } else { // presensi pulang
            // Cek apakah sudah ada presensi masuk hari ini
            if (!$row_presensi || !$row_presensi['jam_masuk']) {
                $error = "Anda belum melakukan presensi masuk hari ini!";
            } else if ($row_presensi['jam_pulang']) {
                $error = "Anda sudah melakukan presensi pulang hari ini!";
            } else {
                // Proses foto
                if (isset($_FILES['foto']) && $_FILES['foto']['error'] == UPLOAD_ERR_OK) {
                    $foto = $_FILES['foto'];
                    $namaFile = "foto-pulang-$nis-" . date('H.i-d.m.Y') . ".jpg";
                    
                    // Gunakan path absolute
                    $baseDir = __DIR__;
                    $fotoDir = $baseDir . '/uploads/foto/pulang';
                    
                    // Buat direktori jika belum ada
                    if (!file_exists($fotoDir)) {
                        if (!mkdir($fotoDir, 0777, true)) {
                            $error = "Gagal membuat folder untuk menyimpan foto!";
                        }
                    }
                    
                    $targetFile = $fotoDir . '/' . $namaFile;
                    
                    // Pindahkan file upload
                    if (move_uploaded_file($foto['tmp_name'], $targetFile)) {
                        // Kompres gambar ke 500KB
                        if (compressImage($targetFile, $targetFile, 60)) {
                            // Simpan data presensi
                            $waktu = date('H:i:s');
                            
                            $status_pulang = 'tepat waktu';
                            $keterangan_pulang_cepat = null;
                            
                            // Perbaikan perhitungan pulang cepat
                            $selisih = hitungSelisihWaktu($waktu, $jamPulang);
                            
                            if ($selisih !== null && $waktu < $jamPulang) {
                                $status_pulang = 'cepat';
                                $_SESSION['show_pulang_cepat_modal'] = true;
                                $keterangan_pulang_cepat = $selisih;
                                
                            }
                            
                            // Simpan lokasi presensi
                            $lokasi = "$latitude,$longitude";
                            
                            $update_sql = "UPDATE presensi SET 
                                jam_pulang = '$waktu', 
                                foto_pulang = '$namaFile', 
                                status_pulang = '$status_pulang',
                                keterangan_pulang_cepat = " . ($keterangan_pulang_cepat ? "'$keterangan_pulang_cepat'" : "NULL") . ",
                                lokasi_pulang = '$lokasi' 
                                WHERE nis = '$nis' AND tanggal = '$tanggal'";
                            
                            if ($conn->query($update_sql) === TRUE) {
                                $success = "Presensi pulang berhasil dicatat!";
                            } else {
                                $error = "Error: " . $conn->error;
                            }
                        } else {
                            $error = "Gagal mengkompres foto!";
                        }
                    } else {
                        $error = "Gagal menyimpan foto! Pastikan folder 'uploads/foto' memiliki izin tulis.";
                    }
                } else {
                    $error = "Foto tidak terupload! Error: " . $_FILES['foto']['error'];
                }
            }
        }
    }
}

		// Tangani logout
		if ($page == 'logout') {
		    session_unset();
		    session_destroy();
		    setcookie(session_name(), '', time() - 3600, '/');
		    session_write_close();
		    header('Location: index.php?page=login');
		    exit();
		}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Presensi Siswa </title>
    <link rel="icon" type="image/png" sizes="96x96" href="/favicon-96x96.png">
    <link rel="manifest" href="/manifest.json">
    <meta name="theme-color" content="#ffffff">
    <link rel="shortcut icon" href="/favicon.ico" type="image/x-icon">
    <link rel="icon" href="/favicon.ico" type="image/x-icon">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css">
    <link rel="stylesheet" href="/leaflet/leaflet.css" />
    <script src="https://cdn.jsdelivr.net/npm/face-api.js@0.22.2/dist/face-api.min.js"></script>
    <script src="/leaflet/leaflet.js"></script>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        body {
            background-color: #f5f7fa;
            color: #333;
            line-height: 1.6;
            overflow-x: hidden;
        }
        
        .container {
            max-width: 100%;
            padding: 10px;
            margin: 0 auto;
        }
        
        .card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
            padding: 20px;
            margin-bottom: 15px;
            transition: transform 0.3s ease;
        }
        
        .card:hover {
            transform: translateY(-3px);
        }
        
        .header {
            background: linear-gradient(135deg, #3498db, #1a5276);
            color: white;
            padding: 15px 0;
            text-align: center;
            border-radius: 0 0 30px 30px;
            box-shadow: 0 20px 10px rgba(0, 0, 0, 0.1);
            position: sticky;
            top: 90;
            z-index: 100;
        }
        
        .header h1 {
            font-size: 1.3rem;
            margin-bottom: 25px;
        }
        
        .header p {
            opacity: 0.7;
            font-size: 0.8rem;
        }
        .header a {
            opacity: 0.9;
            font-size: 2rem;
            text-decoration: none;
            color: white;
        }
        
        
	.nav-tabs {
	    display: flex;
	    justify-content: space-between;
	    border-bottom: 1px solid #ddd;
	    margin-bottom: 15px;
	    overflow-x: auto;
	    -webkit-overflow-scrolling: touch;
	    flex-wrap: wrap;
	}

	.tab-group {
	    display: flex;
	    flex-wrap: wrap;
	    gap: 5px;
	}

	.tab-group-left {
	    justify-content: flex-start;
	    flex: 1;
	}

	.tab-group-right {
	    justify-content: flex-end;
	}

        
        .nav-tabs a {
            padding: 10px 15px;
            text-decoration: none;
            color: #2c3e50;
            font-weight: 600;
            border-bottom: 3px solid transparent;
            transition: all 0.3s;
            white-space: nowrap;
            font-size: 0.9rem;
        }
        
        .nav-tabs a.active {
            border-bottom: 3px solid #3498db;
            color: #3498db;
        }
        
        .nav-tabs a:hover {
            color: #3498db;
        }
        
        .tabs-container {
            margin-bottom: 15px;
        }
        
        .tab-content {
            display: none;
        }
        
        .tab-content.active {
            display: block;
        }
        
        .form-group {
            margin-bottom: 15px;
        }
        
        label {
            display: block;
            margin-bottom: 6px;
            font-weight: 600;
            color: #2c3e50;
            font-size: 0.9rem;
        }
        
        input, button, select, textarea {
            width: 100%;
            padding: 12px;
            border-radius: 8px;
            border: 1px solid #ddd;
            font-size: 0.95rem;
        }
        
        input:focus, select:focus, textarea:focus {
            border-color: #3498db;
            outline: none;
            box-shadow: 0 0 0 2px rgba(52, 152, 219, 0.2);
        }
        
        button {
            background: linear-gradient(135deg, #3498db, #2980b9);
            color: white;
            border: none;
            cursor: pointer;
            font-weight: 600;
            letter-spacing: 0.5px;
            transition: all 0.3s ease;
            font-size: 0.95rem;
        }
        
        button:hover {
            background: linear-gradient(135deg, #2980b9, #1a5276);
            transform: translateY(-2px);
        }
        
        .btn-secondary {
            background: linear-gradient(135deg, #7f8c8d, #95a5a6);
            color: white;
            border: none;
            border-radius: 4px;
            padding: 8px 16px;
            cursor: pointer;
            font-weight: 500;
        }
        
        .btn-secondary:hover {
            background: linear-gradient(135deg, #95a5a6, #7f8c8d);
        }
        
        .btn-success {
            background: linear-gradient(135deg, #2ecc71, #27ae60);
            color: white;
            border: none;
            border-radius: 4px;
            padding: 8px 16px;
            cursor: pointer;
            font-weight: 500;
        }
        
        .btn-success:hover {
            background: linear-gradient(135deg, #27ae60, #219653);
        }
        
        .btn-danger {
            background: linear-gradient(135deg, #e74c3c, #c0392b);
        }
        
        .btn-danger:hover {
            background: linear-gradient(135deg, #c0392b, #a93226);
        }
        
        .btn-warning {
            background: linear-gradient(135deg, #f39c12, #e67e22);
        }
        
        .btn-warning:hover {
            background: linear-gradient(135deg, #e67e22, #d35400);
        }
        
        .error {
            background: #f8d7da;
            color: #721c24;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 15px;
            font-size: 0.85rem;
        }
        
        .success {
            background: #d4edda;
            color: #155724;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 15px;
            font-size: 0.85rem;
        }
        
        .camera-container {
            position: relative;
            width: 90%;
            max-width: 90%;
            margin: 15px auto;
            border-radius: 10px;
            overflow: hidden;
            background: #000;
            aspect-ratio: 4/3;
        }
        
        #video {
            width: 100%;
            height: 100%;
            display: block;
            object-fit: cover;
            transform: scaleX(-1); /* Mirror efek */
        }
        
        .camera-controls {
            position: absolute;
            bottom: 15px;
            left: 0;
            right: 0;
            text-align: center;
        }
        
        .btn-capture {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: white;
            border: 3px solid #3498db;
            cursor: pointer;
        }
        
        .presensi-info {
            display: flex;
            flex-wrap: wrap;
            justify-content: space-between;
            background: #e8f4fc;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 15px;
            gap: 8px;
        }
        
        .info-item {
            text-align: center;
            flex: 1 1 calc(25% - 8px);
            min-width: 100px;
            padding: 8px;
            background: #FFFCFB;
            border-radius: 8px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        }
        
        .info-value {
            font-size: 1.1rem;
            font-weight: 700;
            color: #2980b9;
            margin-bottom: 4px;
        }
        
        .info-label {
            font-size: 0.8rem;
            color: #7f8c8d;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
            font-size: 0.85rem;
        }
        
        th, td {
            padding: 10px 12px;
            text-align: left;
            border-bottom: 1px solid #e0e0e0;
        }
        
        th {
            background-color: #f8f9fa;
            color: #2c3e50;
            font-weight: 600;
            font-size: 0.9rem;
        }
        
        tr:hover {
            background-color: #f5f7fa;
        }
        
        .foto-presensi {
            width: 60px;
            height: 60px;
            object-fit: cover;
            border-radius: 6px;
            cursor: pointer;
            transition: transform 0.2s;
        }
        
        .foto-presensi:hover {
            transform: scale(1.05);
        }
        
        .status-tepat {
            color: #27ae60;
            font-weight: 600;
        }
        
        .status-telambat {
            color: #e74c3c;
            font-weight: 600;
        }
        
        .status-cepat {
            color: #f39c12;
            font-weight: 600;
        }
        
        .status-pending {
            color: #3498db;
            font-weight: 600;
        }
        
        .status-diterima {
            color: #27ae60;
            font-weight: 600;
        }
        
        .status-ditolak {
            color: #e74c3c;
            font-weight: 600;
        }
        
        .status-libur {
            color: #9b59b6;
            font-weight: 600;
        }
        
        .footer {
            text-align: center;
            padding: 15px;
            color: #7f8c8d;
            font-size: 0.8rem;
            margin-top: 15px;
        }
        
        .presensi-options {
            display: flex;
            flex-direction: column;
            gap: 10px;
            margin: 15px 0;
        }
        
        @media (min-width: 480px) {
            .presensi-options {
                flex-direction: row;
            }
        }
        
        .presensi-option {
            text-align: center;
            padding: 12px;
            border-radius: 8px;
            cursor: pointer;
            background: #f5f7fa;
            border: 2px solid #ddd;
            transition: all 0.3s;
            flex: 1;
        }
        
        .presensi-option.active {
            border-color: #3498db;
            background: #e8f4fc;
        }
        
        .presensi-option.masuk.active {
            border-color: #27ae60;
            background: #e8f6f0;
        }
        
        .presensi-option.pulang.active {
            border-color: #e74c3c;
            background: #fceae8;
        }
        
        .file-input-container {
            margin: 15px 0;
            text-align: center;
        }
        
        .file-input-label {
            display: inline-block;
            padding: 10px 16px;
            background: #3498db;
            color: white;
            border-radius: 8px;
            cursor: pointer;
            transition: background 0.3s;
            font-size: 0.9rem;
        }
        
        .file-input-label:hover {
            background: #2980b9;
        }
        
        #foto-input {
            display: none;
        }
        
        /* Modal */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(0,0,0,0.4);
        }
        
        .modal-content {
            background-color: #fff;
            margin: 10% auto;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.2);
            width: 90%;
            max-width: 500px;
            position: relative;
        }
        
        .close-modal {
            position: absolute;
            top: 10px;
            right: 10px;
            font-size: 20px;
            color: #aaa;
            cursor: pointer;
        }
        
        .close-modal:hover {
            color: #333;
        }
        
        .modal-title {
            margin-bottom: 15px;
            text-align: center;
            color: #2c3e50;
            font-size: 1.2rem;
        }
        
        .delete-confirm {
            text-align: center;
            padding: 15px;
        }
        
        .delete-confirm p {
            margin-bottom: 15px;
            font-size: 1rem;
        }
        
        .btn-group {
            display: flex;
            gap: 8px;
            flex-direction: column;
        }
        
        @media (min-width: 480px) {
            .btn-group {
                flex-direction: row;
            }
        }
        
        .btn-group button {
            flex: 1;
        }
        
        /* Responsive table */
        .table-responsive {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }
        
        /* Responsive adjustments */
        @media (max-width: 767px) {
            .container {
                padding: 10px;
            }
            
            .card {
                padding: 15px;
            }
            
            .header h1 {
                font-size: 1.1rem;
            }
            
            .header p {
                font-size: 0.7rem;
            }
            
            th, td {
                padding: 8px 10px;
                font-size: 0.7rem;
            }
            
            .foto-presensi {
                width: 45px;
                height: 45px;
            }
            
            /*.info-item {
                flex: 1 1 calc(50% - 8px);
            }*/
            
            .info-value {
                font-size: 0.8rem;
            }
            
            .presensi-option {
                padding: 10px;
            }
        }
        
        @media (max-width: 480px) {
            .info-item {
                flex: 1 1 100%;
            }
            
            .nav-tabs a {
                padding: 8px 12px;
                font-size: 0.85rem;
            }
            
            .file-input-label {
                padding: 8px 12px;
                font-size: 0.85rem;
            }
            
            .modal-content {
                padding: 15px;
            }
        }
        
        /* Scrollbar styling */
        ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }
        
        ::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 10px;
        }
        
        ::-webkit-scrollbar-thumb {
            background: #b8c2cc;
            border-radius: 10px;
        }
        
        ::-webkit-scrollbar-thumb:hover {
            background: #3498db;
        }
        
        /* Action buttons in table */
        .action-buttons {
            display: flex;
            gap: 5px;
            flex-wrap: wrap;
        }
        
        .action-buttons button {
            padding: 8px 10px;
            font-size: 0.8rem;
        }
        
        .lokasi-link {
            display: inline-block;
            padding: 5px 10px;
            background: #3498db;
            color: white;
            border-radius: 5px;
            text-decoration: none;
            font-size: 0.8rem;
            margin-top: 5px;
        }
        
        .lokasi-link:hover {
            background: #2980b9;
        }
        
        /* Ikon tombol kecil */
        .btn-icon {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0;
        }
        
        .btn-icon-edit {
            background: #3498db;
            color: white;
            border: none;
        }
        
        .btn-icon-delete {
            background: #e74c3c;
            color: white;
            border: none;
            margin-left: 5px;
        }
        
        /* Form edit sederhana */
        .edit-form {
            padding: 20px;
        }
        
        .edit-form .form-group {
            margin-bottom: 15px;
        }
        
        .loading-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 2000;
            justify-content: center;
            align-items: center;
        }
        
        .loading-spinner {
            border: 5px solid #f3f3f3;
            border-top: 5px solid #3498db;
            border-radius: 50%;
            width: 50px;
            height: 50px;
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        .menu-options {
            display: flex;
            flex-direction: column;
            gap: 20px;
            margin-top: 20px;
        }
        
        .menu-option {
            display: flex;
            align-items: center;
            padding: 20px;
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 10px rgba(0,0,0,0.05);
            transition: all 0.3s;
            text-decoration: none;
            color: #333;
        }
        
        .menu-option:hover {
            transform: translateY(-5px);
            box-shadow: 0 6px 15px rgba(0,0,0,0.1);
        }
        
        .menu-icon {
            font-size: 2rem;
            margin-right: 20px;
            width: 60px;
            height: 60px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            color: white;
        }
        
        .menu-kehadiran .menu-icon {
            background: linear-gradient(135deg, #2ecc71, #27ae60);
        }
        
        .menu-ketidakhadiran .menu-icon {
            background: linear-gradient(135deg, #3498db, #2980b9);
        }
        
        .menu-text {
            flex: 1;
        }
        
        .menu-title {
            font-size: 1.2rem;
            font-weight: 600;
            margin-bottom: 5px;
        }
        
        .menu-description {
            font-size: 0.9rem;
            color: #7f8c8d;
        }

        /* Modal Gambar */
        #imageModal {
            display: none;
            position: fixed;
            z-index: 2000;
            padding-top: 100px;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(0,0,0,0.9);
        }
        
        #imageModal .modal-content {
            max-width: 95%;
            max-height: 80vh;
            display: block;
            margin: auto;
            margin-top: 5vh;
        }
        
        #imageModal .close-modal {
            position: absolute;
            top: 15px;
            right: 35px;
            color: white;
            font-size: 40px;
            font-weight: bold;
            text-shadow: 0 0 5px rgba(0,0,0,0.5);
            z-index: 100;
        }
        
        #imageModal .close-modal:hover,
        #imageModal .close-modal:focus {
            color: #bbb;
            cursor: pointer;
        }
        .status-hadir {
            color: #27ae60;
            font-weight: 600;
        }

        /*  FORM FILTER   */
        .form-control {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
        }

        .btn-secondary {
            background: linear-gradient(135deg, #7f8c8d, #95a5a6);
            color: white;
            border: none;
            border-radius: 4px;
            padding: 8px 16px;
            cursor: pointer;
            font-weight: 500;
        }
        .menu-status .menu-icon {
            background: linear-gradient(135deg, #3498db, #2980b9);
        }

        .menu-rekap .menu-icon {
            background: linear-gradient(135deg, #9b59b6, #8e44ad);
        }

        /* Tambahkan di bagian CSS */
        .status-tepat { color: #27ae60; font-weight: 600; }
        .status-pending { color: #3498db; font-weight: 600; }
        .status-ditolak { color: #e74c3c; font-weight: 600; }

        /* Style untuk tabel ranking */
        #ranking-table {
            width: 100%;
            margin-top: 15px;
            border-collapse: collapse;
        }
        #ranking-table th {
            background-color: #2c3e50;
            color: white;
            padding: 10px;
            text-align: left;
        }
        #ranking-table td {
            padding: 8px 10px;
            border-bottom: 1px solid #ddd;
        }
        #ranking-table tr:hover {
            background-color: #f5f7fa;
        }

        .table-ranking {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
        }
        .table-ranking th {
            background-color: #2c3e50;
            color: white;
            padding: 10px;
            text-align: center;
            font-size: 0.9rem;
        }
        .table-ranking td {
            padding: 8px;
            border-bottom: 1px solid #ddd;
            font-size: 0.85rem;
        }
        .table-ranking tr:hover {
            background-color: #f5f7fa;
        }
        .text-center {
            text-align: center;
        }
        .presensi-option.disabled {
            opacity: 0.6;
            cursor: not-allowed;
            background-color: #f8f9fa !important;
            border-color: #ddd !important;
        }

        /* Face DEtection Style */
        #face-status {
        transition: all 0.3s ease;
        font-weight: bold;
        }

        #btn-submit:disabled {
        opacity: 0.6;
        cursor: not-allowed;
        }


        .info {
            position: absolute;
            top: 8px;
            left: 8px;
            z-index: 1000;
            background: rgba(255,255,255,0.95);
            padding: 8px 12px;
            border-radius: 8px;
            box-shadow: 0 1px 6px rgba(0,0,0,0.15);
            font-size: 14px;
            }

            #errorBox {
            position: absolute;
            left: 50%;
            top: 50%;
            transform: translate(-50%, -50%);
            z-index: 1100;
            background: rgba(255,255,255,0.98);
            padding: 16px 18px;
            border-radius: 10px;
            box-shadow: 0 4px 18px rgba(0,0,0,0.2);
            max-width: 420px;
            display: none;
            }

            #errorBox h3 { margin: 0 0 8px 0; font-size: 16px; }
            #errorBox p { margin: 0 0 10px 0; font-size: 13px; color: #333; }
            #errorBox button { padding: 8px 10px; border-radius: 6px; border: none; cursor: pointer; }

            .leaflet-container { font: 12px/1.5 "Helvetica Neue", Arial, Helvetica, sans-serif; }
            .iframe-container {
                    position: relative;
                    align: center;
                    width: 100%;
                    max-width: 500px; /* batas lebar maksimum di layar besar */
                    aspect-ratio: 4 / 3; /* rasio 4:3, bisa diganti */
                }

                .iframe-container iframe {
                    position: absolute;
                    top: 0;
                    left: 0;
                    width: 100%;
                    height: 100%;
                    border: 1px solid #ccc;
                    border-radius: 8px;
                }
                .bottom-menu {
                    position: fixed;
                    bottom: 0;
                    left: 0;
                    right: 0;
                    background: #3674B5;
                    display: flex;
                    border-top: 1px solid #ddd;
                    z-index: 1000;
                    height: 60px;
                }

                .menu-item {
                    flex: 1;
                    text-align: center;
                    padding: 5px 5px;
                    text-decoration: none;
                    color: #fffff0;
                    font-size: 0.8rem;
                    display: flex;
                    flex-direction: column;
                    justify-content: center;
                    align-items: center;
                    transition: all 0.3s;
                }

                .menu-item i {
                    font-size: 1.3rem;
                    margin-bottom: 4px;
                }

                .menu-item.active {
                    color: #FFE700;
                    background-color: rgba(255, 255, 255, 0.1);
                    border-top: 2px solid #FFE700;
                }

                body.has-bottom-menu {
                    padding-bottom: 45px;
                }
                .btn-danger {
                    background: linear-gradient(135deg, #e74c3c, #c0392b);
                    color: white;
                    border: none;
                    border-radius: 4px;
                    padding: 8px 16px;
                    cursor: pointer;
                    font-weight: 500;
                    display: inline-block;
                }

                .btn-success {
                    background: linear-gradient(135deg, #2ecc71, #27ae60);
                    color: white;
                    border: none;
                    border-radius: 4px;
                    padding: 8px 16px;
                    cursor: pointer;
                    font-weight: 500;
                    display: inline-block;
                }

                .btn-danger:hover, .btn-success:hover {
                    opacity: 0.9;
                    transform: translateY(-2px);
                }    
                .btn-warning {
                    background: linear-gradient(135deg, #f39c12, #e67e22);
                    color: white;
                    border: none;
                    border-radius: 4px;
                    padding: 8px 16px;
                    cursor: pointer;
                    font-weight: 500;
                    display: inline-block;
                    text-decoration: none;
                    transition: all 0.3s ease;
                }

                .btn-warning:hover {
                    background: linear-gradient(135deg, #e67e22, #d35400);
                    transform: translateY(-2px);
                    color: white;
                    text-decoration: none;
                    box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
                }    
    </style>
</head>

<body class="<?php 
    // Tambahkan class ke body jika menu statis harus ditampilkan
    //$body_class = '';
    //if ((isset($_SESSION['nis']) && $page != 'login' && $page != 'reset_password' && !isset($_SESSION['admin'])) {
   //     $body_class = 'has-bottom-menu';
   // }
   // if (isset($_SESSION['admin']) && $page == 'admin') {
   //     $body_class = 'has-bottom-menu';
   // }
    //echo $body_class;
?>">

    <div class="header">
    <h1><a href="<?php echo is_dir($_SERVER['DOCUMENT_ROOT'] . '/' . strtolower($labelkelas)) 
    ? 'https://' . $_SERVER['HTTP_HOST'] . '/' . strtolower($labelkelas) 
    : 'https://' . $_SERVER['HTTP_HOST']. '/absensiku'; ?>">
    <i class="fas fa-user-check" style="font-size:0.6em"></i></a> PRESENSI SISWA <?php echo $labelkelas;?></h1>

<!-- Header Top -->
<?php if ($page == 'menu' || $page == 'izin' || $page == 'status_presensi' || $page == 'rekap_presensi' || $page == 'presensi' && isset($_SESSION['nis']) || isset($_SESSION['admin']) || $page == 'tab-pengajuan-bulanan' || $page == 'tab-rekap-harian' || $page == 'tab-rekap' || $page == 'tab-status' || $page == 'tab-siswa' || $page == 'tab-pengaturan' || $page == 'tab-libur' ): ?>
    <div style="font-size:0.75em; position: absolute; top: 65px; left: 50%; transform: translateX(-50%); 
            color:#fff; text-align:center; white-space: nowrap;" class="info-value">
        <i class="fas fa-id-card" style="font-size: 0.75rem;"></i> 
        <?php echo $_SESSION['nis']; ?> &nbsp;
        <i class="fas fa-user-check" style="font-size: 0.75rem;"></i> 
        <?php echo $_SESSION['nama']; ?> 
    </div>

    <div style="font-size:0.75em; position: absolute; top: 83px; right: 30px; color:#fff;" class="info-value"  >
        <i class="fas fa-clock" style="font-size: 0.57rem;"></i> <?php echo date('H:i'); ?> &nbsp;
        <a href="?page=logout" style="color:#FFE100; text-decoration: none; font-size: 0.75rem;">
        <i class="fas fa-power-off" style="font-size: 0.57rem;"></i> Keluar
        </a>
    </div>

<?php else: ?>
    <p style="text-align:center; font-size:1em; color:#FFF;">
        Bukti Unjuk Kehadiran dengan Sistem Manajemen Kelas V3.0a
    </p>
<?php endif; ?>
		
    </div>

    
    
    
    <div class="container">
        <?php if ($page == 'login'): ?>
            <!-- Halaman Login Terpadu -->
		<div class="card">
			<div style="position: absolute; top: 15px; right: 15px;">
            <a href="<?php echo is_dir($_SERVER['DOCUMENT_ROOT'] . '/' . strtolower($labelkelas)) 
                ? 'https://' . $_SERVER['HTTP_HOST'] . '/' . strtolower($labelkelas) 
                : 'https://' . $_SERVER['HTTP_HOST']. '/absensiku'; ?>" style="color: #3498db; font-size: 1.5rem; text-decoration: none;">
                <i class="fas fa-home"></i>
            </a>     
			</div>
                <h2 style="text-align: center; margin-bottom: 15px; font-size: 1.3rem;">Login Presensi</h2>
                
		    <?php if (isset($_GET['session_expired'])): ?>
            	<div class="error" style="text-align: center;">
				Sesi Anda telah berakhir karena tidak ada aktivitas. Silakan login kembali.
            	</div>
        	    <?php endif; ?>
                <?php if (isset($error)): ?>
                    <div class="error">
                        <?php echo $error; ?>
                        <?php if (strpos($error, 'Password salah') !== false): ?>
                            <div style="margin-top: 8px;">
                                <a href="index.php?page=reset_password&nis=<?php echo isset($_POST['identifier']) ? $_POST['identifier'] : ''; ?>" 
                                style="color: #3498db; font-size: 0.9rem;">
                                <i class="fas fa-question-circle"></i> Lupa Password? Gunakan Password Hint
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <form method="POST">
                    <div class="form-group">
                        <label for="identifier"><i class="fas fa-user"></i> Username (NISN)</label>
                        <input type="text" id="identifier" name="identifier" required placeholder="Masukkan Username atau NISN ">
                    </div>
                    
                    <div class="form-group">
                        <label for="password"><i class="fas fa-lock"></i> Password</label>
                        <input type="password" id="password" name="password" required placeholder="Masukkan password">
                    </div>
                    
                    <button type="submit" name="login"><i class="fas fa-sign-in-alt"></i> Mulai Presensi</button>
                </form>
            </div>
        <?php endif; ?>
        

<?php if ($page == 'reset_password'): ?>
    <!-- Halaman Reset Password -->
    <div class="card">
        <h2 style="text-align: center; margin-bottom: 15px; font-size: 1.3rem;"><i class="fas fa-key"></i> Reset Password</h2>
        
        <?php if (isset($reset_error)): ?>
            <div class="error"><?php echo $reset_error; ?></div>
        <?php endif; ?>
        
        <?php if (isset($reset_success)): ?>
            <div class="success"><?php echo $reset_success; ?></div>
        <?php endif; ?>
        
        <form method="POST">
            <div class="form-group">
                <label for="nis"><i class="fas fa-id-card"></i> NISN</label>
                <input type="text" id="nis" name="nis" required placeholder="Masukkan NISN Anda">
            </div>
            
            <div class="form-group">
                <label for="hint_answer"><?php 
                    // Tampilkan pertanyaan hint default
                    echo isset($_POST['nis']) ? "Masukkan jawaban hint: " : "Pertanyaan hint akan ditampilkan setelah memasukkan NISN";
                ?></label>
                <input type="text" id="hint_answer" name="hint_answer" required placeholder="Masukkan jawaban hint">
                <small>Contoh: Tokoh favorit, nama hewan peliharaan, dll.</small>
            </div>
            
            <div class="form-group">
                <label for="new_password"><i class="fas fa-lock"></i> Password Baru</label>
                <input type="password" id="new_password" name="new_password" required placeholder="Masukkan password baru">
            </div>
            
            <div class="form-group">
                <label for="confirm_password"><i class="fas fa-lock"></i> Konfirmasi Password Baru</label>
                <input type="password" id="confirm_password" name="confirm_password" required placeholder="Konfirmasi password baru">
            </div>
            
            <button type="submit" name="reset_password" class="btn-warning">
                <i class="fas fa-sync-alt"></i> Reset Password
            </button>
            
            <div style="text-align: center; margin-top: 15px;">
                <a href="index.php?page=login" style="color: #3498db;">
                    <i class="fas fa-arrow-left"></i> Kembali ke Login
                </a>
            </div>
        </form>
    </div>
<?php endif; ?>	

        <?php if ($page == 'menu' && isset($_SESSION['nis'])): ?>
            <?php
            $today = date('Y-m-d');
            $ganjil_start = $pengaturan['tanggal_awal_ganjil'];
            $genap_start = $pengaturan['tanggal_awal_genap'];
            
            // Tentukan semester aktif
            $semester = 'Ganjil';
            if ($today >= $ganjil_start && $today < $genap_start) {
                $semester = 'Genap';
            } else {
                $semester = 'Ganjil';
            }    
            ?>

            <!-- Menu Utama Siswa -->
            <div class="presensi-info">
                <div class="info-item" style="flex: 1; min-width: 60px; text-align: center;">
                    <div style="font-size:0.8em;" class="info-value"><?php echo $semester; ?></div>
                    <div style="font-size:0.6em;" class="info-label"><i class="fas fa-calendar-alt"></i> Semester</div>
                </div>
                <div class="info-item" style="flex: 1; min-width: 90px; text-align: center;">
                    <div style="font-size:0.8em;" class="info-value"><?php echo $_SESSION['nis']; ?></div>
                    <div style="font-size:0.6em;" class="info-label"><i class="fas fa-id-card"></i> NISN</div>
                </div>
                <div class="info-item" style="flex: 1; min-width: 60x; text-align: center;">
                    <div style="font-size:0.8em;" class="info-value"><?php echo date('H:i'); ?></div>
                    <div style="font-size:0.6em;" class="info-label"><i class="fas fa-clock"></i>Waktu</div>
                 </div>
                 <div class="info-item" style="flex: 1; min-width: 160px; text-align: center;">
                    <div style="font-size:1.0em;" class="info-value"><?php echo $_SESSION['nama']; ?></div>
                    <div style="font-size:0.8em;" class="info-label"><i class="fas fa-user"></i> Nama</div>
                </div>
            </div>
            <div class="card">
                <h2 style="text-align: center; margin-bottom: 20px; font-size: 1.3rem;">Menu Utama</h2>
                <p style="text-align: center;">Selamat datang, <strong><?php echo $_SESSION['nama']; ?></strong>!</p>
                <p style="text-align: center; margin-top: 15px;">Silakan gunakan menu di bagian bawah layar untuk mengakses fitur.</p>
            </div>

           
        <?php endif; ?>
        
        <?php if ($page == 'presensi' && isset($_SESSION['nis'])): ?>
            <?php
            // Cek apakah sudah presensi masuk hari ini
            $nis_siswa = $_SESSION['nis'];
            $today = date('Y-m-d');
            $sql_check_masuk = "SELECT jam_masuk FROM presensi WHERE nis = '$nis_siswa' AND tanggal = '$today'";
            $result_check_masuk = $conn->query($sql_check_masuk);
            $sudah_masuk = ($result_check_masuk->num_rows > 0);
            ?>
            <!-- Halaman Presensi -->
            <?php
            $today = date('Y-m-d');
            $sql_check_libur = "SELECT * FROM periode_libur 
                                WHERE '$today' BETWEEN tanggal_mulai AND tanggal_selesai";
            $result_libur = $conn->query($sql_check_libur);

            $is_libur = false;
            $error_libur = '';
            if ($result_libur->num_rows > 0) {
                $is_libur = true;
                $libur = $result_libur->fetch_assoc();
                $error_libur = "Presensi tidak dapat dilakukan karena periode libur: <b>" . $libur['nama_periode'] . 
                            "</b> (" . formatTanggalID($libur['tanggal_mulai']) . " s/d " . formatTanggalID($libur['tanggal_selesai']) . ")";
            }
            // Cek hari Sabtu (6) atau Minggu (0)
            $hari_ini = date('w'); // 0 (Minggu) sampai 6 (Sabtu)
            $is_weekend = ($hari_ini == 0 || $hari_ini == 6);
                    // Ambil rekap absen bulan ini untuk siswa ini
                    $nis_siswa = $_SESSION['nis'];
                    $bulan_ini = date('m');
                    $tahun_ini = date('Y');

                    $sql_rekap = "SELECT 
                        COUNT(*) AS total_hadir,
                        SUM(CASE WHEN status_masuk = 'tepat waktu' THEN 1 ELSE 0 END) AS tepat_waktu,
                        SUM(CASE WHEN status_masuk = 'terlambat' THEN 1 ELSE 0 END) AS terlambat,
                        SUM(CASE WHEN jam_pulang IS NOT NULL THEN 1 ELSE 0 END) AS pulang
                        FROM presensi 
                        WHERE nis = '$nis_siswa' 
                        AND MONTH(tanggal) = '$bulan_ini' 
                        AND YEAR(tanggal) = '$tahun_ini'";

                    $result_rekap = $conn->query($sql_rekap);
                    $rekap = $result_rekap->fetch_assoc();

                    $total_hadir = $rekap['total_hadir'] ?? 0;
                    $tepat_waktu = $rekap['tepat_waktu'] ?? 0;
                    $terlambat = $rekap['terlambat'] ?? 0;
                    $pulang = $rekap['pulang'] ?? 0;
                    ?>
                    <div class="info-item" style="font-size: 0.6rem; text-align:left; font-weight: bold;">
                    &nbsp; &nbsp; Bulan: <?= ["","Januari","Februari","Maret","April","Mei","Juni","Juli","Agustus","September","Oktober","November","Desember"][(int)date("n")] . " " . date("Y"); ?>
                    </div>

                        <div class="presensi-info">
                            <div class="info-item" style="flex: 1; min-width: 60px; text-align: center;">
                                <div style="font-size: 0.8rem; font-weight: bold;"><?php echo ($total_hadir == 0) ? '-' : $total_hadir; ?></div>
                                <div style="font-size: 0.6rem; font-weight: bold;">Total Hadir</div>
                            </div>
                            <div class="info-item" style="flex: 1; min-width: 60px; text-align: center;">
                                <div style="font-size: 0.8rem; font-weight: bold;"><?php echo ($tepat_waktu == 0) ? '-' : $tepat_waktu; ?></div>
                                <div style="font-size: 0.6rem; font-weight: bold;">Tepat Waktu</div>
                            </div>
                            <div class="info-item" style="flex: 1; min-width: 60px; text-align: center;">
                                <div style="font-size: 0.8rem; font-weight: bold;"><?php echo ($terlambat == 0) ? '-' : $terlambat; ?>
                                </div>
                                <div style="font-size: 0.6rem; font-weight: bold;">Terlambat</div>
                            </div>
                            <div class="info-item" style="flex: 1; min-width: 60px; text-align: center;">
                                <div style="font-size: 0.8rem; font-weight: bold;"><?php echo ($pulang == 0) ? '-' : $pulang; ?></div>
                                <div style="font-size: 0.6rem; font-weight: bold;">Pulang</div>
                            </div>
                        </div>
                    
                    <?php if (isset($error)): ?>
                        <div class="error">
                            <strong>Error:</strong> <?php echo $error; ?>
                            <?php if (isset($foto) && is_array($foto)): ?>
                                <div style="margin-top: 8px; font-size: 11px;">
                                    <div>Nama File: <?php echo $foto['name']; ?></div>
                                    <div>Ukuran: <?php echo round($foto['size']/1024, 2); ?> KB</div>
                                    <div>Tipe: <?php echo $foto['type']; ?></div>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                    
                    <?php if (isset($success)): ?>
                        <div class="success"><?php echo $success; ?></div>
                    <?php endif; ?>
                    
                    <div class="card">
                        <h2 style="text-align: center; margin-bottom: 15px; font-size: 1.3rem;"><i class="fas fa-camera"></i> Presensi Siswa</h2>
                        <?php if ($is_libur || $is_weekend): ?>
            <div class="error" style="text-align: center; padding: 20px;">
                <i class="fas fa-calendar-times fa-2x"></i>
                <?php if ($is_weekend): ?>
                    <h3 style="margin: 10px 0;">Presensi tidak dapat dilakukan karena hari <?php echo $hari_ini == 0 ? 'Minggu' : 'Sabtu'; ?></h3>
                <?php else: ?>
                    <h3 style="margin: 10px 0;"><?php echo $error_libur; ?></h3>
                <?php endif; ?>
                <p>Silakan kembali ke menu utama.</p>
                </div>
            <?php else: ?>                
                    <form method="POST" enctype="multipart/form-data" id="presensi-form">
                        <input type="hidden" name="latitude" id="latitude">
                        <input type="hidden" name="longitude" id="longitude">
                        <input type="hidden" name="jenis_presensi" id="jenis_presensi" value="masuk">

                        <!-- Preview Kamera -->
                        <div class="camera-container">
                            <video id="video" autoplay playsinline></video>
                            <div class="camera-controls">
                                <button type="button" id="btn-capture" class="btn-capture">
                                    <i class="fas fa-camera"></i>
                                </button>
                            </div>
                        </div>
                        <div id="face-status" style="text-align:center; padding:10px; margin:10px 0; background:#f8f9fa; border-radius:5px;">
                        <i class="fas fa-user-clock"></i> Menunggu deteksi wajah...
                        </div>
                        <canvas id="canvas" style="display: none;"></canvas>
                        
                        <!-- Input File untuk Foto -->
                        <div class="file-input-container">

                            <label for="foto-input" class="file-input-label">
                                <i class="fas fa-camera"></i> Ambil Foto Wajah
                            </label>
                            <input type="file" name="foto" id="foto-input" accept="image/*" capture="user" required>
                            <button type="submit" name="presensi" id="btn-submit" disabled class="btn-success" style="width: 100%; margin-top: 10px;">
                                <i class="fas fa-paper-plane"></i> Kirim Presensi
                            </button>

                            
                        </div>
                        <!-- Opsi Presensi (Foto Masuk dan Foto Pulang) -->                        
                        <div class="presensi-options" >
                            <div class="presensi-option masuk <?php echo !$sudah_masuk ? 'active' : ''; ?>" data-jenis="masuk">
                                <i class="fas fa-sign-in-alt fa-lg"></i>
                                <div>Presensi Masuk</div>
                            </div>
                        <div class="presensi-option pulang <?php echo $sudah_masuk ? 'active' : 'disabled'; ?>" 
                                data-jenis="pulang" 
                                <?php echo !$sudah_masuk ? 'style="opacity: 0.6; cursor: not-allowed;"' : ''; ?>>
                                <i class="fas fa-sign-out-alt fa-lg"></i>
                                <div>Presensi Pulang</div>
                                <?php if (!$sudah_masuk): ?>
                                    <small style="display: block; color: #e74c3c; font-size: 0.8rem;">Harap presensi masuk terlebih dahulu</small>
                                <?php endif; ?>
                            </div>
                        </div>              

                        <input type="hidden" name="jenis_presensi" id="jenis_presensi" value="<?php echo $sudah_masuk ? 'pulang' : 'masuk'; ?>">
                                                
                        <!-- Info Lokasi -->
                        <div id="lokasi-info" style="margin-top: 12px; padding: 10px; background: #f8f9fa; border-radius: 8px; text-align: center; font-size: 0.9rem;">
                            <i class="fas fa-sync fa-spin"></i> Mengambil lokasi...
                        </div>
                        <!-- Responsive iframe wrapper (lokasi openstreetmap)--> 
                        <div class="iframe-container" aria-label="Embed Absensi Sekolah" style="text-align: center;">
				                <!--
                                <iframe
                                    style="border:1px solid #ccc; border-radius:8px; width: 100%; height: 260px;"
                                    src="<?php // echo 'https://' . $_SERVER['HTTP_HOST'] . '/' . strtolower($labelkelas) . '/sekolah.php'; ?>"
                                    title="Absensi Sekolah"
                                    loading="lazy"
                                    sandbox="allow-same-origin allow-scripts allow-forms allow-popups"
                                ></iframe>
                                -->
                        
					    <iframe style="border:1px solid #ccc;  border-radius:8px; width: 100%; height: 230px;"
                                    src="<?php echo 'https://' . $_SERVER['HTTP_HOST'] . '/absensiku/sekolah.php'; ?>"
                                    title="Absensi Sekolah"
                                    loading="lazy"
                                    sandbox="allow-same-origin allow-scripts allow-forms allow-popups">
                        </iframe>
                        </div>

                </form>
 <?php endif; ?>               

            </div>
            
            <!-- Modal Keterangan Terlambat -->
            <?php if (isset($_SESSION['show_terlambat_modal']) && $_SESSION['show_terlambat_modal']): ?>
                <div id="terlambatModal" class="modal" style="display: block;">
                    <div class="modal-content">
                        <h3 class="modal-title"><i class="fas fa-exclamation-triangle"></i> Keterangan Terlambat</h3>
                        <p style="margin-bottom: 15px; text-align: center;">
                            Anda terlambat melakukan presensi. Silakan berikan keterangan alasan keterlambatan Anda.
                        </p>
                        
                        <form method="POST">
                            <div class="form-group">
                                <label for="keterangan">Alasan Keterlambatan</label>
                                <textarea id="keterangan" name="keterangan" rows="4" required placeholder="Mohon jelaskan alasan keterlambatan Anda..."></textarea>
                            </div>
                            
                            <button type="submit" name="save_keterangan_terlambat" class="btn-warning">
                                <i class="fas fa-paper-plane"></i> Kirim Keterangan
                            </button>
                        </form>
                    </div>
                </div>
            <?php endif; ?>
            
            <!-- Modal Keterangan Pulang Cepat -->
            <?php if (isset($_SESSION['show_pulang_cepat_modal']) && $_SESSION['show_pulang_cepat_modal']): ?>
                <div id="pulangCepatModal" class="modal" style="display: block;">
                    <div class="modal-content">
                        <h3 class="modal-title"><i class="fas fa-running"></i> Keterangan Pulang Cepat</h3>
                        <p style="margin-bottom: 15px; text-align: center;">
                            Anda melakukan presensi pulang lebih awal. Silakan berikan keterangan alasan pulang cepat.
                        </p>
                        
                        <form method="POST">
                            <input type="hidden" name="nis" value="<?= $_SESSION['nis'] ?>">
                            <input type="hidden" name="tanggal" value="<?= date('Y-m-d') ?>">
                            
                            <div class="form-group">
                                <label for="keterangan_pulang">Alasan Pulang Cepat</label>
                                <textarea id="keterangan_pulang" name="keterangan" rows="4" required placeholder="Mohon jelaskan alasan pulang cepat Anda..."></textarea>
                            </div>
                            
                            <button type="submit" name="save_keterangan_pulang_cepat" class="btn-warning">
                                <i class="fas fa-paper-plane"></i> Kirim Keterangan
                            </button>
                        </form>
                    </div>
                </div>
            <?php endif; ?>
            
            <script>
                // Face Detection Script presensi 
                // Variabel global
                let isFaceDetected = false;
                const faceOptions = new faceapi.TinyFaceDetectorOptions({ 
                inputSize: 224, 
                scoreThreshold: 0.5 
                });

                // 1. Load model saat halaman siap
                document.addEventListener('DOMContentLoaded', async () => {
                try {
                    await loadModels();
                    startDetection();
                } catch (error) {
                    console.error("Error:", error);
                    document.getElementById('face-status').innerHTML = 
                    '<i class="fas fa-exclamation-triangle"></i> Fitur deteksi wajah tidak tersedia';
                }
                });

                // 2. Fungsi load model
                async function loadModels() {
                const modelPath = 'https://justadudewhohacks.github.io/face-api.js/models';
                await faceapi.nets.tinyFaceDetector.loadFromUri(modelPath);
                await faceapi.nets.faceLandmark68Net.loadFromUri(modelPath);
                }

                // 3. Fungsi deteksi wajah
                function startDetection() {
                const video = document.getElementById('video');
                const canvas = document.getElementById('canvas');
                const statusEl = document.getElementById('face-status');
                const btnSubmit = document.getElementById('btn-submit');
                
                setInterval(async () => {
                    if (video.readyState === video.HAVE_ENOUGH_DATA) {
                    const detections = await faceapi.detectAllFaces(video, faceOptions);
                    
                    if (detections.length > 0) {
                        isFaceDetected = true;
                        btnSubmit.disabled = false;
                        statusEl.innerHTML = '<i class="fas fa-user-check" style="color:green"></i> Wajah terdeteksi!';
                        statusEl.style.background = '#e8f5e9';
                    } else {
                        isFaceDetected = false;
                        btnSubmit.disabled = true;
                        statusEl.innerHTML = '<i class="fas fa-user-slash" style="color:red"></i> Arahkan wajah ke kamera';
                        statusEl.style.background = '#ffebee';
                    }
                    }
                }, 1000); // Deteksi setiap 1 detik
                }

                // 4. Validasi sebelum submit
                document.getElementById('presensi-form').addEventListener('submit', function(e) {
                if (!isFaceDetected) {
                    e.preventDefault();
                    alert('Wajah tidak terdeteksi! Pastikan wajah Anda terlihat jelas di kamera.');
                }
                });
                // Inisialisasi variabel
                const video = document.getElementById('video');
                const canvas = document.getElementById('canvas');
                const ctx = canvas.getContext('2d');
                const btnCapture = document.getElementById('btn-capture');
                const fotoInput = document.getElementById('foto-input');
                const lokasiInfo = document.getElementById('lokasi-info');
                const latitudeInput = document.getElementById('latitude');
                const longitudeInput = document.getElementById('longitude');
                const btnSubmit = document.getElementById('btn-submit');
                const jenisPresensiInput = document.getElementById('jenis_presensi');
                const presensiOptions = document.querySelectorAll('.presensi-option');
                
                // Set ukuran canvas sesuai video
                function setCanvasSize() {
                    if (video.videoWidth > 0 && video.videoHeight > 0) {
                        canvas.width = video.videoWidth;
                        canvas.height = video.videoHeight;
                    }
                }
                
                // Mengakses kamera
                if (navigator.mediaDevices && navigator.mediaDevices.getUserMedia) {
                    navigator.mediaDevices.getUserMedia({ 
                        video: { facingMode: 'user' } // Gunakan kamera depan
                    })
                    .then(function(stream) {
                        video.srcObject = stream;
                        video.addEventListener('loadedmetadata', function() {
                            setCanvasSize();
                        });
                    })
                    .catch(function(error) {
                        lokasiInfo.innerHTML = "Tidak dapat mengakses kamera: " . error.name;
                        console.error("Camera error: ", error);
                    });
                } else {
                    lokasiInfo.innerHTML = "Browser Anda tidak mendukung akses kamera";
                }
                
                // Geolocation
                if (navigator.geolocation) {
                    navigator.geolocation.getCurrentPosition(showPosition, showError, {
                        enableHighAccuracy: true,
                        timeout: 10000,
                        maximumAge: 0
                    });
                } else {
                    lokasiInfo.innerHTML = "Geolocation tidak didukung oleh browser ini.";
                }
                
                function showPosition(position) {
                    const lat = position.coords.latitude;
                    const lng = position.coords.longitude;
                    
                    latitudeInput.value = lat;
                    longitudeInput.value = lng;
                    
                    // Koordinat sekolah diambil dari PHP
                    const latSekolah = <?php echo $latSekolah; ?>;
                    const lngSekolah = <?php echo $lngSekolah; ?>;
                    const radiusSekolah = <?php echo $radiusSekolah; ?>;
                    
                    // Hitung jarak dalam meter
                    const jarak = hitungJarak(latSekolah, lngSekolah, lat, lng);
                    
                    if (jarak <= radiusSekolah) {
                        lokasiInfo.innerHTML = `<i class="fas fa-check-circle"></i> <b>ANDA</b> berada <b>DI DALAM AREA SEKOLAH</b> <br/>(${jarak.toFixed(0)} meter dari pusat radius presensi).`;
                        btnSubmit.disabled = false;
                    } else {
                        lokasiInfo.innerHTML = `<i class="fas fa-exclamation-triangle"></i> <b>ANDA</b> berada <b>DI LUAR AREA SEKOLAH</b> <br/>(${jarak.toFixed(0)} meter dari pusat). Hanya bisa melakukan presensi dalam radius ${radiusSekolah} meter.`;
                        btnSubmit.disabled = true;
                    }
                }
                
                function showError(error) {
                    let message = '';
                    switch(error.code) {
                        case error.PERMISSION_DENIED:
                            message = "Izin lokasi ditolak. Aktifkan izin lokasi untuk presensi.";
                            break;
                        case error.POSITION_UNAVAILABLE:
                            message = "Informasi lokasi tidak tersedia.";
                            break;
                        case error.TIMEOUT:
                            message = "Permintaan lokasi timeout.";
                            break;
                        case error.UNKNOWN_ERROR:
                            message = "Terjadi kesalahan tidak diketahui.";
                            break;
                    }
                    lokasiInfo.innerHTML = `<i class='fas fa-exclamation-circle'></i> ${message}`;
                    btnSubmit.disabled = true;
                }
                
                // Fungsi untuk menghitung jarak
                function hitungJarak(lat1, lon1, lat2, lon2) {
                    const R = 6371e3; // Radius bumi dalam meter
                    const φ1 = lat1 * Math.PI/180;
                    const φ2 = lat2 * Math.PI/180;
                    const Δφ = (lat2-lat1) * Math.PI/180;
                    const Δλ = (lon2-lon1) * Math.PI/180;
                    
                    const a = Math.sin(Δφ/2) * Math.sin(Δφ/2) +
                              Math.cos(φ1) * Math.cos(φ2) *
                              Math.sin(Δλ/2) * Math.sin(Δλ/2);
                    const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1-a));
                    
                    const distance = R * c;
                    return distance;
                }
                
                // Tombol ambil foto
                btnCapture.addEventListener('click', function() {
                    // Ambil gambar dari video
                    setCanvasSize();
                    ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
                    
                    // Konversi ke blob dan set ke input file
                    canvas.toBlob(function(blob) {
                        const file = new File([blob], "presensi.jpg", {type: 'image/jpeg'});
                        const dataTransfer = new DataTransfer();
                        dataTransfer.items.add(file);
                        fotoInput.files = dataTransfer.files;
                        
                        // Tampilkan notifikasi
                        alert('Foto telah diambil! Silakan kirim presensi.');
                    }, 'image/jpeg', 0.9);
                });
                
                // Pilihan jenis presensi
                presensiOptions.forEach(option => {
                    option.addEventListener('click', function() {
                        // Hapus active class dari semua opsi
                        presensiOptions.forEach(opt => opt.classList.remove('active'));
                        
                        // Tambahkan active class ke opsi yang dipilih
                        this.classList.add('active');
                        
                        // Set nilai jenis presensi
                        jenisPresensiInput.value = this.dataset.jenis;
                    });
                });
                
                // Form submission handler
                document.getElementById('presensi-form').addEventListener('submit', function(e) {
                    if (!fotoInput.files.length) {
                        e.preventDefault();
                        alert('Silakan ambil foto terlebih dahulu!');
                    } else if (btnSubmit.disabled) {
                        e.preventDefault();
                        alert('Lokasi Anda di luar area sekolah atau tidak valid!');
                    } else {
                        // Tampilkan loading indicator
                        const overlay = document.createElement('div');
                        overlay.className = 'loading-overlay';
                        overlay.innerHTML = '<div class="loading-spinner"></div>';
                        document.body.appendChild(overlay);
                        overlay.style.display = 'flex';
                    }
                });
            </script>
        <?php endif; ?>
        
        <?php if ($page == 'izin' && isset($_SESSION['nis'])): ?>
            <!-- Halaman Pengajuan Izin -->
           
            <?php if (isset($error_izin)): ?>
                <div class="error"><?php echo $error_izin; ?></div>
            <?php endif; ?>
            
            <?php if (isset($success_izin)): ?>
                <div class="success"><?php echo $success_izin; ?></div>
            <?php endif; ?>
            
            <div class="card">
                <h2 style="text-align: center; margin-bottom: 15px; font-size: 1.3rem;"><i class="fas fa-user-times"></i> Pengajuan Izin</h2>
                
                <form method="POST" enctype="multipart/form-data" id="form-izin">
                    <div class="form-group">
                        <label for="tanggal"><i class="fas fa-calendar"></i> Tanggal Izin</label>
                        <input type="date" id="tanggal" name="tanggal" required value="<?php echo date('Y-m-d'); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="jenis"><i class="fas fa-info-circle"></i> Jenis Izin</label>
                        <select id="jenis" name="jenis" required>
                            <option value="">-- Pilih Jenis Izin --</option>
                            <option value="sakit">Sakit</option>
                            <option value="ijin">Ijin</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="keterangan"><i class="fas fa-comment"></i> Keterangan</label>
                        <textarea id="keterangan" name="keterangan" rows="4" required placeholder="Berikan keterangan alasan ketidakhadiran Anda..."></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label for="lampiran"><i class="fas fa-paperclip"></i> Lampiran (Opsional)</label>
                        <input type="file" id="lampiran" name="lampiran" accept="image/*,application/pdf">
                        <small style="display: block; margin-top: 5px; color: #7f8c8d;">Format: JPG, PNG, PDF (maks. 2MB)</small>
                    </div>
                    
                    <button type="submit" name="ajukan_izin" id="btn-ajukan" class="btn-warning">
                        <i class="fas fa-paper-plane"></i> Ajukan Izin
                    </button>
            <!-- Loading Indicator -->
            <div id="loading-izin" style="display: none; text-align: center; margin-top: 15px;">
                <i class="fas fa-spinner fa-spin"></i> Mengirim data...
            </div>
                </form>
                
                <div style="margin-top: 30px;">
                    <h3 style="margin-bottom: 15px; font-size: 1.1rem;"><i class="fas fa-history"></i> Riwayat Pengajuan Izin</h3>
                    
                    <?php
                    $nis_siswa = $_SESSION['nis'];
                    $sql = "SELECT * FROM absensi_izin WHERE nis = '$nis_siswa' ORDER BY tanggal DESC, jenis ASC";
                    $result = $conn->query($sql);
                    
                    if ($result->num_rows > 0): ?>
                        <div class="table-responsive">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Tanggal</th>
                                        <th>Jenis</th>
                                        <th>Keterangan</th>
                                        <th>Lampiran</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php while ($row = $result->fetch_assoc()): ?>
                                        <tr>
                                            <td><?php echo date('d/m/Y', strtotime($row['tanggal'])); ?></td>
                                            <td><?php echo ucfirst($row['jenis']); ?></td>
                                            <td><?php echo substr($row['keterangan'], 0, 50) . (strlen($row['keterangan']) > 50 ? '...' : ''); ?></td>
                                            <td>
                                                <?php if ($row['lampiran']): 
                                                    $ext = pathinfo($row['lampiran'], PATHINFO_EXTENSION);
                                                    $ext = strtolower($ext);
                                                ?>
                                                    <?php if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif'])): ?>
                                                        <?php 
                                                        // Periksa apakah file ada sebelum menampilkan
                                                        $file_path = 'uploads/lampiran/' . $row['lampiran'];
                                                        if (file_exists($file_path)): ?>
                                                            <img src="<?php echo $file_path; ?>" 
                                                                class="foto-presensi" 
                                                                onclick="showImageModal('<?php echo $file_path; ?>')">
                                                        <?php else: ?>
                                                            <span style="color: red;">File tidak ditemukan</span>
                                                        <?php endif; ?>
                                                    <?php elseif ($ext === 'pdf'): ?>
                                                        <?php 
                                                        $file_path = 'uploads/lampiran/' . $row['lampiran'];
                                                        if (file_exists($file_path)): ?>
                                                            <a href="<?php echo $file_path; ?>" target="_blank" style="display: inline-block; padding: 5px 10px; background: #e74c3c; color: white; border-radius: 4px; text-decoration: none;">
                                                                <i class="fas fa-file-pdf"></i> Buka PDF
                                                            </a>
                                                        <?php else: ?>
                                                            <span style="color: red;">File PDF tidak ditemukan</span>
                                                        <?php endif; ?>
                                                    <?php else: ?>
                                                        <a href="uploads/lampiran/<?php echo $row['lampiran']; ?>" target="_blank">Lihat Lampiran</a>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    -
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($row['status'] == 'pending'): ?>
                                                    <span class="status-pending">Menunggu</span>
                                                <?php elseif ($row['status'] == 'diterima'): ?>
                                                    <span class="status-diterima">Diterima</span>
                                                <?php else: ?>
                                                    <span class="status-ditolak">Ditolak</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <p style="text-align: center; padding: 15px; color: #7f8c8d;">Belum ada riwayat pengajuan izin</p>
                    <?php endif; ?>
                </div>
                

            </div>
        <?php endif; ?>

    <!-- Dashboard Siswa - Status Presensi Siswa -->
    <?php if ($page == 'status_presensi' && isset($_SESSION['nis'])): ?>

        <div class="card">
            <h2 style="text-align: center; margin-bottom: 15px; font-size: 1.3rem;"><i class="fas fa-calendar-check"></i> Status Presensi Harian</h2>
            <!-- Form Filter Tanggal -->
            <form method="GET" style="margin-bottom: 20px;">
                <input type="hidden" name="page" value="status_presensi">
                <div style="display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 10px;">
                    <div style="flex: 1; min-width: 200px;">
                        <label for="start_date">Tanggal Awal</label>
                        <input type="date" id="start_date" name="start_date" 
                            value="<?= isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-01') ?>" 
                            class="form-control">
                    </div>
                    
                    <div style="flex: 1; min-width: 200px;">
                        <label for="end_date">Tanggal Akhir</label>
                        <input type="date" id="end_date" name="end_date" 
                            value="<?= isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d') ?>" 
                            class="form-control">
                    </div>
                </div>
                <div style="display: flex; gap: 10px;">
                    <!-- Tombol Filter -->
                    <button type="submit" style=" background-color: #28a745; color: white;  padding: 12px 20px;  border: none;  border-radius: 5px;   cursor: pointer;  font-size: 16px; ">  Filter   </button>
                    <a href="index.php?page=status_presensi" style=" background-color: #28a745; color: white; padding: 12px 20px; border: none;	  border-radius: 5px; cursor: pointer; font-size: 16px; text-decoration: none; display: inline-block;"> .::Reset::.  </a>
                </div>
            </form>

            <?php
            $nis_siswa = $_SESSION['nis'];
            $start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-01');
            $end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');

            $sql = "SELECT * FROM presensi 
                    WHERE nis = '$nis_siswa' 
                    AND tanggal BETWEEN '$start_date' AND '$end_date'
                    ORDER BY tanggal DESC";
            $result = $conn->query($sql);

            if ($result->num_rows > 0):
            ?>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>No</th>
                                <th>Tanggal</th>
                                <th>Jam Masuk</th>
                                <th>Status Masuk</th>
                                <th>Jam Pulang</th>
                                <th>Status Pulang</th>
                                <th>Keterangan</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $no=1; while ($row = $result->fetch_assoc()): ?>
                                <tr>
                                    <td><?= $no++ ?></td>
                                    <td><?= formatTanggalID($row['tanggal']) ?></td>
                                    <td><?= $row['jam_masuk'] ?></td>
                                    <td>
                                        <?php if ($row['status_masuk'] == 'tepat waktu'): ?>
                                            <span class="status-tepat">Tepat Waktu</span>
                                        <?php else: ?>
                                            <span class="status-telambat">Terlambat (<?= $row['keterangan_terlambat'] ?>)</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= $row['jam_pulang'] ?: '-' ?></td>
                                    <td>
                                        <?php if ($row['jam_pulang']): ?>
                                            <?php if ($row['status_pulang'] == 'tepat waktu'): ?>
                                                <span class="status-tepat">Tepat Waktu</span>
                                            <?php elseif ($row['status_pulang'] == 'cepat'): ?>
                                                <span class="status-cepat">Pulang Cepat (<?= $row['keterangan_pulang_cepat'] ?>)</span>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="status-ditolak">Belum Presensi</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= $row['keterangan'] ?></td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <p style="text-align: center; padding: 15px; color: #7f8c8d;">Tidak ada data presensi dalam rentang tanggal ini.</p>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <!-- Dashboard Siswa - Rekap Presensi Siswa -->
    <?php if ($page == 'rekap_presensi' && isset($_SESSION['nis'])): ?>

        <div class="card">
            <h2 style="text-align: center; margin-bottom: 15px; font-size: 1.3rem;"><i class="fas fa-chart-line"></i> Rekap Presensi Bulanan</h2>
            <!-- Form Filter Bulan dan Tahun -->
            <form method="GET" style="margin-bottom: 20px;">
                <input type="hidden" name="page" value="rekap_presensi">
                <div style="display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 10px;">
                    <div style="flex: 1; min-width: 200px;">
                        <label for="bulan">Bulan</label>
                        <select id="bulan" name="bulan" class="form-control">
                            <?php
                            $bulan = isset($_GET['bulan']) ? $_GET['bulan'] : date('m');
                            for ($i=1; $i<=12; $i++):
                                $selected = ($i == $bulan) ? 'selected' : '';
                            ?>
                                <option value="<?= $i ?>" <?= $selected ?>><?= date('F', mktime(0,0,0,$i,1)) ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div style="flex: 1; min-width: 200px;">
                        <label for="tahun">Tahun</label>
                        <select id="tahun" name="tahun" class="form-control">
                            <?php
                            $tahun = isset($_GET['tahun']) ? $_GET['tahun'] : date('Y');
                            for ($i=date('Y')-1; $i<=date('Y')+1; $i++):
                                $selected = ($i == $tahun) ? 'selected' : '';
                            ?>
                                <option value="<?= $i ?>" <?= $selected ?>><?= $i ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                </div>
                <button type="submit" class="btn-success">Tampilkan</button>
            </form>

            <?php
            $nis_siswa = $_SESSION['nis'];
            $bulan = isset($_GET['bulan']) ? $_GET['bulan'] : date('m');
            $tahun = isset($_GET['tahun']) ? $_GET['tahun'] : date('Y');

            // Query rekap per bulan
            $sql = "SELECT 
                        COUNT(*) AS total_hadir,
                        SUM(CASE WHEN status_masuk = 'tepat waktu' THEN 1 ELSE 0 END) AS tepat_waktu,
                        SUM(CASE WHEN status_masuk = 'terlambat' THEN 1 ELSE 0 END) AS terlambat,
                        (SELECT COUNT(*) FROM absensi_izin 
                        WHERE nis = '$nis_siswa' 
                        AND MONTH(tanggal) = '$bulan' 
                        AND YEAR(tanggal) = '$tahun'
                        AND jenis = 'sakit' AND status = 'diterima') AS sakit,
                        (SELECT COUNT(*) FROM absensi_izin 
                        WHERE nis = '$nis_siswa' 
                        AND MONTH(tanggal) = '$bulan' 
                        AND YEAR(tanggal) = '$tahun'
                        AND jenis = 'ijin' AND status = 'diterima') AS ijin
                    FROM presensi 
                    WHERE nis = '$nis_siswa'
                    AND MONTH(tanggal) = '$bulan'
                    AND YEAR(tanggal) = '$tahun'";

            $result = $conn->query($sql);
            $rekap = $result->fetch_assoc();

            // Hitung total hari efektif (belum termasuk libur)
            //$hari_efektif = cal_days_in_month(CAL_GREGORIAN, $bulan, $tahun);
            $hari_efektif = date('t', strtotime("$tahun-$bulan-01"));
            $hari_akhir_pekan = 0;
            for ($i=1; $i<=$hari_efektif; $i++) {
                $tanggal = "$tahun-$bulan-$i";
                $hari = date('w', strtotime($tanggal));
                if ($hari == 0 || $hari == 6) {
                    $hari_akhir_pekan++;
                }
            }
            $hari_efektif -= $hari_akhir_pekan;

            // Hitung alpa
            $hadir = $rekap['total_hadir'];
            $sakit = $rekap['sakit'];
            $ijin = $rekap['ijin'];
            $alpa = $hari_efektif - ($hadir + $sakit + $ijin);
            $alpa = $alpa < 0 ? 0 : $alpa;
            ?>
            
            <div class="presensi-info">
                <!--<div class="info-item">
                    <div class="info-value"><?= $hari_efektif ?></div>
                    <div class="info-label">Hari Efektif</div>
                </div>-->
                <div class="info-item">
                    <div class="info-value"><?= !empty($hadir) ? $hadir : '-' ?></div>
                    <div class="info-label">Hadir</div>
                </div>
                <div class="info-item">
                    <div class="info-value"><?= !empty($rekap['tepat_waktu']) ? $rekap['tepat_waktu'] : '-' ?></div>
                    <div class="info-label">Tepat Waktu</div>
                </div>
                <div class="info-item">
                    <div class="info-value"><?= !empty($rekap['terlambat']) ? $rekap['terlambat'] : '-' ?></div>
                    <div class="info-label">Terlambat</div>
                </div>
            </div>
            <div class="presensi-info">
                <div class="info-item">
                    <div class="info-value"><?= ($sakit ?? 0) + ($ijin ?? 0) ?: '-' ?></div> <!-- Bentuk penulisan kondisi if yg lain -->
                    <div class="info-label">Tidak Hadir</div>
                </div>
                <div class="info-item">
                    <div class="info-value"><?= !empty($sakit) ? $sakit : '-' ?> </div>
                    <div class="info-label">Sakit</div>
                </div>
                <div class="info-item">
                    <div class="info-value"><?= $total_hari_efektif > 0 ? round(($hadir / $total_hari_efektif) * 100, 1) : '-' ?></div>
                    <div class="info-label">Ijin</div>
                </div>


            </div>
        </div>
    <?php endif; ?>
        
        <?php if ($page == 'admin' && isset($_SESSION['admin'])): ?>
            <!-- Admin Dashboard -->
            
            <?php if (isset($success_siswa)): ?>
                <div class="success"><?php echo $success_siswa; ?></div>
            <?php endif; ?>
            <?php if (isset($error_siswa)): ?>
                <div class="error"><?php echo $error_siswa; ?></div>
            <?php endif; ?>
            <?php if (isset($success_pengaturan)): ?>
                <div class="success"><?php echo $success_pengaturan; ?></div>
            <?php endif; ?>
            <?php if (isset($error_pengaturan)): ?>
                <div class="error"><?php echo $error_pengaturan; ?></div>
            <?php endif; ?>
            <?php if (isset($success_admin)): ?>
                <div class="success"><?php echo $success_admin; ?></div>
            <?php endif; ?>
            <?php if (isset($error_admin)): ?>
                <div class="error"><?php echo $error_admin; ?></div>
            <?php endif; ?>
            
            <!--QUERY EDIT TAMBAHAN DATA PRESENSI SISWA -->
		<?php
		    // Total Siswa
		    $sql_total_siswa = "SELECT COUNT(*) as total FROM siswa";
		    $result_total_siswa = $conn->query($sql_total_siswa);
		    $row_total = $result_total_siswa->fetch_assoc();
		    $total_siswa = $row_total['total'];

		    // Hadir Hari Ini
		    $today = date('Y-m-d');
		    $sql_hadir = "SELECT COUNT(DISTINCT nis) as total FROM presensi WHERE tanggal = '$today' AND jam_masuk IS NOT NULL";
		    $result_hadir = $conn->query($sql_hadir);
		    $row_hadir = $result_hadir->fetch_assoc();
		    $hadir_hari_ini = $row_hadir['total'];

		    // Terlambat Hari Ini
		    $sql_terlambat = "SELECT COUNT(DISTINCT nis) as total FROM presensi WHERE tanggal = '$today' AND status_masuk = 'terlambat'";
		    $result_terlambat = $conn->query($sql_terlambat);
		    $row_terlambat = $result_terlambat->fetch_assoc();
		    $terlambat_hari_ini = $row_terlambat['total'];

		    // Pulang Cepat Hari Ini
		    $sql_cepat = "SELECT COUNT(DISTINCT nis) as total FROM presensi WHERE tanggal = '$today' AND status_pulang = 'cepat'";
		    $result_cepat = $conn->query($sql_cepat);
		    $row_cepat = $result_cepat->fetch_assoc();
		    $cepat_hari_ini = $row_cepat['total'];

		?>
  
          <!--QUERY EDIT TAMBAHAN DATA PENGAJUAN IJIN -->
		<?php
		    // Total Ijin Siswa
		    $sql_total_ijin_siswa = "SELECT COUNT(*) as total FROM absensi_izin";
		    $result_total_ijin_siswa = $conn->query($sql_total_ijin_siswa);
		    $row_total_ijin = $result_total_ijin_siswa->fetch_assoc();
		    $total_ijin_siswa = $row_total_ijin['total'];

		    // Ijin Hari Ini
		    $sql_ijin = "SELECT COUNT(DISTINCT nis) as total FROM absensi_izin WHERE tanggal = '$today' AND jenis = 'ijin'";
		    $result_ijin = $conn->query($sql_ijin);
		    $row_ijin = $result_ijin->fetch_assoc();
		    $ijin_hari_ini = $row_ijin['total'];

		    // Sakit Hari Ini
		    $sql_sakit = "SELECT COUNT(DISTINCT nis) as total FROM absensi_izin WHERE tanggal = '$today' AND jenis = 'sakit'";
		    $result_sakit = $conn->query($sql_sakit);
		    $row_sakit = $result_sakit->fetch_assoc();
		    $sakit_hari_ini = $row_sakit['total'];
		?>
            <!--END QUERY EDIT TAMBAHAN-->

		<div class="tabs-container">


		<div id="tab-presensi" class="tab-content active">
		    <div class="card">
			  <h3 style="margin-bottom: 12px; font-size: 1.1rem;"><i class="fas fa-list"></i> Data Presensi Siswa (<?= formatTanggalID($today) ?>)</h3>
			  			<!--EDIT RUBAH-->
				  <div class="presensi-info">
					    <div class="info-item">
						  <div class="info-value"><?php echo $total_siswa; ?></div>
						  <div class="info-label">Total Siswa Kelas</div>
					    </div>
					    <div class="info-item">
						  <div class="info-value"><?php echo $hadir_hari_ini; ?></div>
						  <div class="info-label">Hadir Hari Ini (Presensi)</div>
					    </div>
					    <div class="info-item">
						  <div class="info-value"><?php echo $terlambat_hari_ini; ?></div>
						  <div class="info-label">Terlambat Hari Ini</div>
					    </div>
					    <div class="info-item">
						  <div class="info-value"><?php echo $cepat_hari_ini; ?></div>
						  <div class="info-label">Pulang Cepat Hari Ini</div>
					    </div>
					</div>
				  </div>
                <!-- Form Filter Tanggal -->
                <form method="GET" style="margin-bottom: 20px;">
                <input type="hidden" name="page" value="admin">
                <div style="display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 10px;">
                    <div style="flex: 1; min-width: 200px;">
                        <label for="start_date_presensi">Tanggal Awal</label>
                        <input type="date" id="start_date_presensi" name="start_date_presensi" 
                            value="<?php echo isset($_GET['start_date_presensi']) ? $_GET['start_date_presensi'] : date('Y-m-d'); ?>" 
                            class="form-control">
                    </div>
                    
                    <div style="flex: 1; min-width: 200px;">
                        <label for="end_date_presensi">Tanggal Akhir</label>
                        <input type="date" id="end_date_presensi" name="end_date_presensi" 
                            value="<?php echo isset($_GET['end_date_presensi']) ? $_GET['end_date_presensi'] : date('Y-m-d'); ?>" 
                            class="form-control">
                    </div>
                </div>
                     <div style="display: flex; gap: 10px;">
                            <!-- Tombol Filter -->
                            <button type="submit" style=" background-color: #28a745; color: white;  padding: 12px 20px;  border: none;  border-radius: 5px;   cursor: pointer;  font-size: 16px; ">  Filter   </button>

                            <!-- Tombol Reset -->
                            <a href="index.php?page=admin#tab-rekap" style=" background-color: #28a745; color: white; padding: 12px 20px; border: none;	  border-radius: 5px; cursor: pointer; font-size: 16px; text-decoration: none; display: inline-block;"> .::Reset::.  </a>
                    </div>
            </form>

			  <?php 
			  // Ambil parameter filter
			  $start_date = isset($_GET['start_date_presensi']) ? $_GET['start_date_presensi'] : date('Y-m-d');
			  $end_date = isset($_GET['end_date_presensi']) ? $_GET['end_date_presensi'] : date('Y-m-d');
			  
			  // Query data presensi dengan filter tanggal
              $sql = "SELECT DISTINCT p.*, s.nama, t.keterangan, p.keterangan_pulang_cepat
              FROM presensi p 
              JOIN siswa s ON p.nis = s.nis 
              LEFT JOIN terlambat t ON p.nis = t.nis AND p.tanggal = t.tanggal
              WHERE p.tanggal BETWEEN '$start_date' AND '$end_date'
              ORDER BY p.tanggal DESC, p.jam_masuk DESC";
			  $result = $conn->query($sql);
			  
			  if ($result->num_rows > 0):
			
			?>

		<form id="deletePresensiForm" method="POST" action="index.php?page=admin#tab-presensi">

                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr><!--<th><input type="checkbox" id="selectAllPresensi"></th>-->
                                <th>No</th>
                                <th>Tanggal</th>
                                <th>NISN</th>
                                <th style="width: 60px;">Nama</th>
                                <th>Jam Masuk</th>
                                <th>Foto Masuk</th>
                                <th>Status Masuk</th>
                                <th>Lokasi Masuk</th>
                                <th>Jam Pulang</th>
                                <th>Foto Pulang</th>
                                <th>Status Pulang</th>
                                <th>Lokasi Pulang</th>
                                <th style="width: 100px;">Keterangan Terlambat</th>
                                <th style="width: 100px;">Keterangan Pulang Cepat</th>
                                <th>Catatan</th>
                                <th>Aksi</th> <!-- Kolom Baru -->
                            </tr>
                        </thead>
                        <tbody>
                            <?php $no=1; while ($row = $result->fetch_assoc()): ?>
                                <tr>
                                    <!--<td><input type="checkbox" class="presensi-check" name="selected_ids[]" value="<?= $row['id'] ?>"></td>-->
                                    <td><?php echo $no++; ?></td>
				                    <td><?php echo formatTanggalID($row['tanggal']); ?></td>
				                    <td><?php echo $row['nis']; ?></td>
				                    <td><?php echo $row['nama']; ?></td>
				                    <td><?php echo $row['jam_masuk']; ?></td>
				                    <td>
				                        <?php if ($row['foto_masuk']): ?>
				                            <img src="uploads/foto/masuk/<?php echo $row['foto_masuk']; ?>" 
                                         class="foto-presensi" 
                                         onclick="showImageModal('uploads/foto/masuk/<?php echo $row['foto_masuk']; ?>')">
				                        <?php else: ?>
				                            -
				                        <?php endif; ?>
				                    </td>
 							        <td>
				                        <?php if ($row['jam_masuk']): ?>
				                            <span class="status-<?php 
				                                echo ($row['status_masuk'] == 'tepat waktu') ? 'tepat' : 'telambat'; 
				                            ?>">
				                                <?php echo $row['status_masuk']; ?>
				                            </span><br><?= $row['keterangan_terlambat'] ? $row['keterangan_terlambat'] : '-' ?>
				                        <?php else: ?>
				                            -
				                        <?php endif; ?>
				                        
				                    </td>
				                    <td>
				                        <?php if (!empty($row['lokasi_masuk'])): ?>
				                            <a href="https://www.google.com/maps/search/?api=1&query=<?= $row['lokasi_masuk'] ?>" 
				                               target="_blank" class="lokasi-link">
				                                <i class="fas fa-map-marker-alt"></i> Masuk
				                            </a>
				                        <?php endif; ?>
				                    </td>
				                    <td><?php echo $row['jam_pulang'] ? $row['jam_pulang'] : '-'; ?></td>
				                    <td>
				                        <?php if ($row['foto_pulang']): ?>
				                            <img src="uploads/foto/pulang/<?php echo $row['foto_pulang']; ?>" 
                                         class="foto-presensi" 
                                         onclick="showImageModal('uploads/foto/pulang/<?php echo $row['foto_pulang']; ?>')">
				                        <?php else: ?>
				                            -
				                        <?php endif; ?>
				                    </td>
				                    <td>
                                        <?php if ($row['jam_pulang']): ?>
                                            <?php if ($row['status_pulang'] == 'tepat waktu'): ?>
                                                <span class="status-tepat">Tepat Waktu</span>
                                            <?php elseif ($row['status_pulang'] == 'cepat'): ?>
                                                <span class="status-cepat">Pulang Cepat</span><br> <?= $row['keterangan_pulang_cepat'] ?>
                                               
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="status-ditolak">Belum Presensi</span>
                                        <?php endif; ?>
                                    </td>
				                    <td>
				                        <?php if (!empty($row['lokasi_pulang'])): ?>
				                   
				                            <a href="https://www.google.com/maps/search/?api=1&query=<?= $row['lokasi_pulang'] ?>" 
				                               target="_blank" class="lokasi-link">
				                                <i class="fas fa-map-marker-alt"></i> Pulang
				                            </a>
				                        <?php endif; ?>
				                    </td>
                                    <td><?php echo $row['keterangan']; ?> <!--<?= $row['keterangan_terlambat'] ?: '-' ?>--></td>
                                    <td><?php 
                                                // Ambil keterangan pulang cepat dari tabel baru
                                                $sql_keterangan = "SELECT distinct keterangan FROM pulang_cepat WHERE nis = '{$row['nis']}' AND tanggal = '{$row['tanggal']}'";
                                                $result_keterangan = $conn->query($sql_keterangan);
                                                if ($result_keterangan->num_rows > 0) {
                                                    $keterangan = $result_keterangan->fetch_assoc()['keterangan'];
                                                    echo "<br><small>$keterangan</small>";
                                                }
                                                ?></td>
                                    <td><?= $row['catatan'] ?: '-' ?></td>            
                                    <td>
                                        <div class="action-buttons">
                                            <!-- Tombol Edit -->
                                            <button class="btn-icon btn-icon-edit" onclick="openEditPresensiModal(<?php echo $row['id']; ?>)" title="Edit">
                                            <i class="fas fa-edit"></i>
                                            </button>
                                            
                                            <!-- Tombol Hapus -->
                                            <button class="btn-icon btn-icon-delete" onclick="if(confirm('Hapus data presensi ini?')) { 
             window.location.href='index.php?page=admin&action=delete_presensi&id=<?php echo $row['id']; ?>#presensi'}" title="Hapus">
                                            <i class="fas fa-trash"></i>
                                            </button>
                                        </div>
                                    </td>
				                </tr>
				            <?php endwhile; ?>
				        </tbody>
				    </table>
				</div>

			  <?php else: ?>
				<p style="text-align: center; padding: 15px; color: #7f8c8d;">Tidak ada data presensi</p>
			  <?php endif; ?>
		    </div>
		</div>
              <!-- TAB DATA SISWA -->
                <div id="tab-siswa" class="tab-content">
                    <div class="card">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; flex-wrap: wrap; gap: 10px;">
                            <h3 style="margin: 0; font-size: 1.1rem;"><i class="fas fa-users"></i> Data Siswa</h3>
                            <a href="javascript:void(0)" class="btn-success" onclick="openModal('add', event)" style="padding: 10px 15px; font-size: 0.9rem; display: inline-block; text-align: center; text-decoration: none; color: white; border-radius: 8px; cursor: pointer;">
                                <i class="fas fa-plus"></i> Tambah Siswa
                            </a>
                        </div>
                        
                        <?php 
                        $sql = "SELECT * FROM siswa Order By nama";
                        $result = $conn->query($sql);
                        
                        if ($result->num_rows > 0): ?>
                            <div class="table-responsive">
                                <table>
                                    <thead>
                                        <tr> 
							  <th>No</th>
                                            <th>NISN</th>
                                            <th>Nama</th>
                                            <th>Password</th>
                                            <th>Password Hint</th>
                                            <th>Aksi</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php  
                                        $no = 1; // Inisialisasi no
                                        while ($row = $result->fetch_assoc()): 
                                        ?>
                                            <tr>
                                                <td><?php echo $no++; ?></td>
                                                <td><?php echo $row['nis']; ?></td>
                                                <td><?php echo $row['nama']; ?></td>
                                                <td>••••••••</td>
                                                <td><?php echo $row['password_hint']; ?></td>
                                                <td>
                                                    <div class="action-buttons">
                                                        <button class="btn-icon btn-icon-edit" onclick="openModal('edit', event, '<?php echo $row['nis']; ?>')" title="Edit">
                                                            <i class="fas fa-edit"></i>
                                                        </button>
                                                        <button class="btn-icon btn-icon-delete" onclick="openModal('delete', event, '<?php echo $row['nis']; ?>', '<?php echo $row['nama']; ?>')" title="Hapus">
                                                            <i class="fas fa-trash"></i>
                                                        </button>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endwhile; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <p style="text-align: center; padding: 15px; color: #7f8c8d;">Tidak ada data siswa</p>
                        <?php endif; ?>
                    </div>
                </div>          
            
             <!-- TAB PENGAJUAN IJIN BULANAN-->  
             <div id="tab-pengajuan-bulanan" class="tab-content">
                <div class="card">
                    <h3 style="margin-bottom: 12px; font-size: 1.1rem;">
                        <i class="fas fa-file-alt"></i> Pengajuan Izin Siswa (Filter Bulanan)
                    </h3>
                    <!--EDIT RUBAH-->
				  <div class="presensi-info">
					    <div class="info-item">
						  <div class="info-value"><?php echo $total_ijin_siswa; ?></div>
						  <div class="info-label">Total Ijin Siswa Kelas</div>
					    </div>
					    <div class="info-item">
						  <div class="info-value"><?php echo $ijin_hari_ini; ?></div>
						  <div class="info-label">Ijin Hari Ini</div>
					    </div>
					    <div class="info-item">
						  <div class="info-value"><?php echo $sakit_hari_ini ?></div>
						  <div class="info-label">Sakit Hari Ini</div>
					    </div>
					</div>
				  </div>

                    <!-- Form Filter Bulan dan Tahun -->
                    <form method="GET" style="margin-bottom: 20px;">
                        <input type="hidden" name="page" value="admin">
                        <div style="display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 10px;">
                            <div style="flex: 1; min-width: 200px;">
                                <label for="bulan_pengajuan">Bulan</label>
                                <select id="bulan_pengajuan" name="bulan_pengajuan" class="form-control">
                                    <?php
                                    $bulan = isset($_GET['bulan_pengajuan']) ? $_GET['bulan_pengajuan'] : date('m');
                                    for ($i = 1; $i <= 12; $i++):
                                        $selected = ($i == $bulan) ? 'selected' : '';
                                        $nama_bulan = DateTime::createFromFormat('!m', $i)->format('F');
                                    ?>
                                        <option value="<?= $i ?>" <?= $selected ?>><?= $nama_bulan ?></option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                            <div style="flex: 1; min-width: 200px;">
                                <label for="tahun_pengajuan">Tahun</label>
                                <select id="tahun_pengajuan" name="tahun_pengajuan" class="form-control">
                                    <?php
                                    $tahun = isset($_GET['tahun_pengajuan']) ? $_GET['tahun_pengajuan'] : date('Y');
                                    for ($i = date('Y') - 1; $i <= date('Y') + 1; $i++):
                                        $selected = ($i == $tahun) ? 'selected' : '';
                                    ?>
                                        <option value="<?= $i ?>" <?= $selected ?>><?= $i ?></option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                        </div>
                        <div style="display: flex; gap: 10px;">
                            <button type="submit" class="btn-success">Filter</button>
                            <a href="index.php?page=admin#tab-pengajuan-bulanan" class="btn-secondary">Reset</a>
                        </div>
                    </form>

                    <?php
                    // Proses filter
                    $bulan_filter = isset($_GET['bulan_pengajuan']) ? $_GET['bulan_pengajuan'] : date('m');
                    $tahun_filter = isset($_GET['tahun_pengajuan']) ? $_GET['tahun_pengajuan'] : date('Y');

                    $sql = "SELECT a.*, s.nama FROM absensi_izin a 
                            JOIN siswa s ON a.nis = s.nis 
                            WHERE MONTH(a.tanggal) = '$bulan_filter' 
                            AND YEAR(a.tanggal) = '$tahun_filter'
                            ORDER BY a.tanggal DESC, a.created_at DESC";
                    $result = $conn->query($sql);

                    if ($result->num_rows > 0):
                    ?>
                        <form method="POST" action="index.php?page=admin#tab-pengajuan-bulanan" id="deleteMassalForm">
                            <input type="hidden" name="action" value="delete_selected_izin_bulanan">
                            <button type="button" onclick="confirmDeleteMassal()" class="btn-danger" style="margin-bottom: 10px;">
                                <i class="fas fa-trash"></i> Hapus Data Terpilih
                            </button>

                            <div class="table-responsive">
                                <table>
                                    <thead>
                                        <tr>
                                            <th><input type="checkbox" id="selectAllIzinBulanan"></th>
                                            <th>No</th>
                                            <th>Tanggal</th>
                                            <th>NISN</th>
                                            <th>Nama</th>
                                            <th>Jenis</th>
                                            <th>Keterangan</th>
                                            <th>Lampiran</th>
                                            <th>Status & Catatan</th>
                                            <th>Aksi</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php $no = 1; while ($row = $result->fetch_assoc()): ?>
                                            <tr>
                                                <td><input type="checkbox" class="izin-bulanan-check" name="selected_ids[]" value="<?= $row['id'] ?>"></td>
                                                <td><?= $no++ ?></td>
                                                <td><?= formatTanggalID($row['tanggal']) ?></td>
                                                <td><?= $row['nis'] ?></td>
                                                <td><?= $row['nama'] ?></td>
                                                <td><?= ucfirst($row['jenis']) ?></td>
                                                <td><?= substr($row['keterangan'], 0, 50) . (strlen($row['keterangan']) > 50 ? '...' : '') ?></td>
                                                <td>
                                                    <?php if ($row['lampiran']): 
                                                        $ext = pathinfo($row['lampiran'], PATHINFO_EXTENSION);
                                                        $ext = strtolower($ext);
                                                    ?>
                                                        <?php if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif'])): ?>
                                                            <img src="uploads/lampiran/<?= $row['lampiran'] ?>" 
                                                                class="foto-presensi" 
                                                                onclick="showImageModal('uploads/lampiran/<?= $row['lampiran'] ?>')">
                                                        <?php elseif ($ext === 'pdf'): ?>
                                                            <a href="uploads/lampiran/<?= $row['lampiran'] ?>" target="_blank" style="display: inline-block; padding: 5px 10px; background: #e74c3c; color: white; border-radius: 4px; text-decoration: none;">
                                                                <i class="fas fa-file-pdf"></i> Buka PDF
                                                            </a>
                                                        <?php else: ?>
                                                            <a href="uploads/lampiran/<?= $row['lampiran'] ?>" target="_blank">Lihat Lampiran</a>
                                                        <?php endif; ?>
                                                    <?php else: ?>
                                                        -
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <!-- Form terpadu untuk update status dan catatan -->
                                                    <form method="POST" action="index.php?page=admin#tab-pengajuan-bulanan">
                                                        <input type="hidden" name="id" value="<?= $row['id'] ?>">
                                                        <div style="margin-bottom: 5px;">
                                                            <select name="status" style="padding: 5px; border-radius: 5px; font-size: 0.8rem; width: 100%;">
                                                                <option value="pending" <?= $row['status'] == 'pending' ? 'selected' : '' ?>>Pending</option>
                                                                <option value="diterima" <?= $row['status'] == 'diterima' ? 'selected' : '' ?>>Diterima</option>
                                                                <option value="ditolak" <?= $row['status'] == 'ditolak' ? 'selected' : '' ?>>Ditolak</option>
                                                            </select>
                                                        </div>
                                                        <div style="margin-bottom: 5px;">
                                                            <textarea name="catatan" rows="2" style="width: 100%; padding: 5px; border-radius: 5px; font-size: 0.8rem;" placeholder="Tambah catatan..."><?= $row['catatan'] ?? '' ?></textarea>
                                                        </div>

                                                    </form>
                                                </td>
                                                <td>
                                                <button type="submit" name="update_status_dan_catatan" class="btn-success" style="width: 100%; padding: 5px; font-size: 1rem;">
                                                            <i class="fas fa-save"></i> 
                                                        </button>
                                                <button type="button" class="btn-icon btn-icon-delete" 
                                                    onclick="confirmDelete(<?= $row['id'] ?>)" title="Hapus">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                </form>

                <script>
                    // Fungsi untuk konfirmasi hapus massal
                    function confirmDeleteMassal() {
                        const selectedCount = document.querySelectorAll('.izin-bulanan-check:checked').length;
                        if (selectedCount === 0) {
                            alert('Pilih setidaknya satu data untuk dihapus!');
                            return;
                        }
                        
                        if (confirm(`Apakah Anda yakin ingin menghapus ${selectedCount} data terpilih?`)) {
                            document.getElementById('deleteMassalForm').submit();
                        }
                    }
                    
                    // Fungsi untuk konfirmasi hapus individual
                    function confirmDelete(id) {
                        if (confirm('Apakah Anda yakin ingin menghapus data ini?')) {
                            window.location.href = `index.php?page=admin&action=delete_izin&id=${id}#tab-pengajuan-bulanan`;
                        }
                    }
                    
                    // Fungsi untuk select all checkbox
                    document.getElementById('selectAllIzinBulanan').addEventListener('click', function() {
                        const checkboxes = document.querySelectorAll('.izin-bulanan-check');
                        checkboxes.forEach(checkbox => {
                            checkbox.checked = this.checked;
                        });
                    });
                </script>
                <?php else: ?>
                   <p style="text-align: center; padding: 15px; color: #7f8c8d;">
                     Tidak ada pengajuan izin untuk bulan <?= DateTime::createFromFormat('!m', $bulan_filter)->format('F') ?> <?= $tahun_filter ?>
                </p>
        <?php endif; ?>
      </div>
    </div>


    <div id="tab-rekap-harian" class="tab-content">
    <div class="card">
        <h3 style="margin-bottom: 12px; font-size: 1.1rem;">
            <i class="fas fa-calendar-day"></i> Rekap Harian Presensi
        </h3>
        
        <!-- Form Filter Tanggal -->
        <form method="GET" style="margin-bottom: 20px;">
            <input type="hidden" name="page" value="admin">
            <div style="display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 10px;">
                <div style="flex: 1; min-width: 200px;">
                    <label for="rekap_tanggal">Tanggal</label>
                    <input type="date" id="rekap_tanggal" name="rekap_tanggal" 
                        value="<?php echo isset($_GET['rekap_tanggal']) ? $_GET['rekap_tanggal'] : date('Y-m-d'); ?>" 
                        class="form-control">
                </div>
            </div>
                <div style="display: flex; gap: 10px;">
                <button type="submit" style=" background-color: #28a745; color: white;  padding: 12px 20px;  border: none;  border-radius: 5px;   cursor: pointer;  font-size: 16px; ">  Filter   </button>
                <a href="index.php?page=admin#rekap" style=" background-color: #28a745; color: white; padding: 12px 20px; border: none;	  border-radius: 5px; cursor: pointer; font-size: 16px; text-decoration: none; display: inline-block;"> .::Reset::.  </a>               
                <?php if (isset($_GET['rekap_tanggal'])): ?>
                    <button type="button" onclick="printRekapHarian()" class="btn-warning">
                        <i class="fas fa-print"></i> Cetak Rekap
                    </button>
                <?php endif; ?>
            </div>
        </form>
        
        <?php
        // Ambil tanggal dari parameter GET, default hari ini
        $rekap_tanggal = isset($_GET['rekap_tanggal']) ? $_GET['rekap_tanggal'] : date('Y-m-d');
        
        // Query untuk siswa yang hadir pada tanggal tersebut
        $sql_hadir = "SELECT s.nis, s.nama, p.status_masuk, p.jam_masuk 
                    FROM presensi p 
                    JOIN siswa s ON p.nis = s.nis 
                    WHERE p.tanggal = '$rekap_tanggal' 
                    ORDER BY s.nama";
        $result_hadir = $conn->query($sql_hadir);
        
        // Query untuk siswa yang tidak hadir (dengan keterangan sakit/izin) pada tanggal tersebut
        $sql_izin = "SELECT s.nis, s.nama, a.jenis, a.status 
                    FROM absensi_izin a 
                    JOIN siswa s ON a.nis = s.nis 
                    WHERE a.tanggal = '$rekap_tanggal' AND a.status = 'diterima'
                    ORDER BY s.nama";
        $result_izin = $conn->query($sql_izin);
        
        // Daftar semua siswa (untuk mencari yang alpa)
        $sql_siswa = "SELECT nis, nama FROM siswa WHERE nis != '123' ORDER BY nama";
        $result_siswa = $conn->query($sql_siswa);
        
        // Array untuk menyimpan siswa yang hadir dan tidak hadir
        $siswa_hadir = [];
        $siswa_tidak_hadir = [];
        
        // Proses siswa hadir
        if ($result_hadir->num_rows > 0) {
            while ($row = $result_hadir->fetch_assoc()) {
                $siswa_hadir[$row['nis']] = $row;
            }
        }
        
        // Proses siswa tidak hadir (dengan izin/sakit)
        if ($result_izin->num_rows > 0) {
            while ($row = $result_izin->fetch_assoc()) {
                $siswa_tidak_hadir[$row['nis']] = $row;
            }
        }
        
        // Proses siswa alpa: siswa yang tidak hadir dan tidak ada izin/sakit
        $siswa_alpa = [];
        if ($result_siswa->num_rows > 0) {
            while ($siswa = $result_siswa->fetch_assoc()) {
                $nis = $siswa['nis'];
                if (!isset($siswa_hadir[$nis]) && !isset($siswa_tidak_hadir[$nis])) {
                    $siswa_alpa[$nis] = $siswa;
                }
            }
        }
        ?>

        <!-- Printable Content (hidden until printed) -->
        <div id="printable-status-rekap" style="display: none;">
            <div style="text-align: center; margin-bottom: 20px;">
                <?php if (!empty($pengaturan['logo_sekolah'])): ?>
                    <img src="uploads/logo/<?= $pengaturan['logo_sekolah'] ?>" style="max-height: 80px; margin-bottom: 10px;">
                <?php endif; ?>
                <h3 style="margin: 5px 0;"><?= $pengaturan['nama_sekolah'] ?? 'SMK NEGERI 6 KOTA SERANG' ?></h3>
                <h4 style="margin: 5px 0;">REKAPITULASI STATUS PRESENSI SISWA</h4>
                <p style="margin: 5px 0;">Periode: <?= formatTanggalID($start_date) ?> s/d <?= formatTanggalID($end_date) ?></p>
                <?php if (!empty($status_filters)): ?>
                    <p style="margin: 5px 0;">Filter Status: <?= implode(', ', $status_filters) ?></p>
                <?php endif; ?>
            </div>
            
            <table style="width: 100%; border-collapse: collapse; margin-bottom: 20px;">
            <thead>
                <tr>
                    <th style="border: 1px solid #000; padding: 8px; text-align: center;">No</th>
                    <th style="border: 1px solid #000; padding: 8px; text-align: center;">NISN</th>
                    <th style="border: 1px solid #000; padding: 8px; text-align: center;">Nama Siswa</th>
                    <?php
                    // Generate kolom tanggal berdasarkan filter
                    $current_date = new DateTime($start_date);
                    $end_date_obj = new DateTime($end_date);
                    
                    while ($current_date <= $end_date_obj) {
                        $date_str = $current_date->format('Y-m-d');
                        $date_label = $current_date->format('d/m');
                        echo "<th style='border: 1px solid #000; padding: 8px; text-align: center;'>$date_label</th>";
                        $current_date->modify('+1 day');
                    }
                    ?>
                </tr>
            </thead>
            <tbody>
                    <?php 
                    $no = 1;
                    $sql_siswa = "SELECT nis, nama FROM siswa ORDER BY nama";
                    $result_siswa = $conn->query($sql_siswa);
                    
                    while ($siswa = $result_siswa->fetch_assoc()): 
                        $nis = $siswa['nis'];
                        $nama = $siswa['nama'];
                    ?>
                        <tr>
                            <td style="border: 1px solid #000; padding: 8px; text-align: center;"><?= $no++ ?></td>
                            <td style="border: 1px solid #000; padding: 8px;"><?= $nis ?></td>
                            <td style="border: 1px solid #000; padding: 8px;"><?= $nama ?></td>
                            
                            <?php
                            // Reset current_date untuk setiap siswa
                            $current_date = new DateTime($start_date);
                            $end_date_obj = new DateTime($end_date);
                            
                            while ($current_date <= $end_date_obj) {
                                $date_str = $current_date->format('Y-m-d');
                                
                                // Cek status presensi
                                $status = '-';
                                
                                // Cek apakah ada presensi
                                $sql_presensi = "SELECT status_masuk FROM presensi 
                                                WHERE nis = '$nis' AND tanggal = '$date_str'";
                                $result_presensi = $conn->query($sql_presensi);
                                
                                if ($result_presensi->num_rows > 0) {
                                    $row_presensi = $result_presensi->fetch_assoc();
                                    $status = $row_presensi['status_masuk'] == 'tepat waktu' ? 'H' : 'T';
                                } else {
                                    // Cek apakah ada izin
                                    $sql_izin = "SELECT jenis FROM absensi_izin 
                                                WHERE nis = '$nis' AND tanggal = '$date_str'
                                                AND status = 'diterima'";
                                    $result_izin = $conn->query($sql_izin);
                                    
                                    if ($result_izin->num_rows > 0) {
                                        $row_izin = $result_izin->fetch_assoc();
                                        $status = $row_izin['jenis'] == 'sakit' ? 'S' : 'I';
                                    } else {
                                        // Cek apakah hari libur
                                        $sql_libur = "SELECT id FROM periode_libur 
                                                    WHERE '$date_str' BETWEEN tanggal_mulai AND tanggal_selesai";
                                        $result_libur = $conn->query($sql_libur);
                                        
                                        if ($result_libur->num_rows > 0) {
                                            $status = 'L';
                                        } else {
                                            // Cek apakah akhir pekan
                                            $hari = $current_date->format('w');
                                            if ($hari == 0 || $hari == 6) {
                                                $status = 'L';
                                            } else {
                                                $status = 'A';
                                            }
                                        }
                                    }
                                }
                                
                                echo "<td style='border: 1px solid #000; padding: 8px; text-align: center;'>$status</td>";
                                $current_date->modify('+1 day');
                            }
                            ?>
                        </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
            <?php
            // Query untuk mendapatkan siswa yang memenuhi filter status
$sql_siswa = "SELECT DISTINCT s.nis, s.nama FROM siswa s";

if (!empty($status_filters)) {
    $conditions = [];
    
    foreach ($status_filters as $filter) {
        switch ($filter) {
            case 'Hadir':
                $conditions[] = "EXISTS (SELECT 1 FROM presensi p WHERE p.nis = s.nis AND p.tanggal BETWEEN '$start_date' AND '$end_date' AND p.status_masuk = 'tepat waktu')";
                break;
            case 'Terlambat':
                $conditions[] = "EXISTS (SELECT 1 FROM presensi p WHERE p.nis = s.nis AND p.tanggal BETWEEN '$start_date' AND '$end_date' AND p.status_masuk = 'terlambat')";
                break;
            case 'Sakit':
                $conditions[] = "EXISTS (SELECT 1 FROM absensi_izin a WHERE a.nis = s.nis AND a.tanggal BETWEEN '$start_date' AND '$end_date' AND a.jenis = 'sakit' AND a.status = 'diterima')";
                break;
            case 'Ijin':
                $conditions[] = "EXISTS (SELECT 1 FROM absensi_izin a WHERE a.nis = s.nis AND a.tanggal BETWEEN '$start_date' AND '$end_date' AND a.jenis = 'ijin' AND a.status = 'diterima')";
                break;
            case 'Tidak Hadir':
                $conditions[] = "NOT EXISTS (SELECT 1 FROM presensi p WHERE p.nis = s.nis AND p.tanggal BETWEEN '$start_date' AND '$end_date') 
                                AND NOT EXISTS (SELECT 1 FROM absensi_izin a WHERE a.nis = s.nis AND a.tanggal BETWEEN '$start_date' AND '$end_date' AND a.status = 'diterima')";
                break;
        }
    }
    
    if (!empty($conditions)) {
        $sql_siswa .= " WHERE " . implode(" OR ", $conditions);
    }
}

$sql_siswa .= " ORDER BY s.nama";
            ?>
            <div style="margin-top: 20px; padding: 10px; border: 1px solid #000; border-radius: 5px;">
                <h5>Keterangan:</h5>
                <p><strong>H</strong> = Hadir Tepat Waktu</p>
                <p><strong>T</strong> = Terlambat</p>
                <p><strong>S</strong> = Sakit</p>
                <p><strong>I</strong> = Ijin</p>
                <p><strong>A</strong> = Alpa</p>
                <p><strong>L</strong> = Libur</p>
            </div>
            
            <div style="display: flex; justify-content: space-between; margin-top: 50px;">
                <div style="text-align: center; width: 40%;">
                    <p>Mengetahui,</p>
                    <p>Kepala Sekolah</p>
                    <br><br><br>
                    <p><u><?= $pengaturan['kepala_sekolah'] ?? 'Nama Kepala Sekolah' ?></u></p>
                    <p>NIP. <?= $pengaturan['nip_kepsek'] ?? '123456789' ?></p>
                </div>
                <div style="text-align: center; width: 40%;">
                    <p><?= $pengaturan['kota_sekolah'] ?? 'Kota Serang' ?>, <?= date('d F Y') ?></p>
                    <p>Wali Kelas</p>
                    <br><br><br>
                    <p><u><?= $pengaturan['wali_kelas'] ?? 'Nama Wali Kelas' ?></u></p>
                    <p>NIP. <?= $pengaturan['nip_walikelas'] ?? '987654321' ?></p>
                </div>
            </div>
        </div>
        
        <!-- Tampilkan dalam dua bagian: Hadir dan Tidak Hadir -->
        <div style="display: flex; flex-wrap: wrap; gap: 20px; margin-top: 20px;">
            <!-- Kolom Siswa Hadir -->
            <div style="flex: 1; min-width: 300px;">
                <h4 style="margin-bottom: 10px; color: #27ae60;">
                    <i class="fas fa-user-check"></i> Siswa Hadir (<?php echo count($siswa_hadir); ?>)
                </h4>
                <?php if (!empty($siswa_hadir)): ?>
                    <div class="table-responsive">
                        <table style="width: 100%;">
                            <thead>
                                <tr>
                                    <th>No</th>
                                    <th>NISN</th>
                                    <th>Nama</th>
                                    <th>Status</th>
                                    <th>Jam Masuk</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $no=1; foreach ($siswa_hadir as $nis => $data): ?>
                                    <tr>
                                        <td><?= $no++ ?></td>
                                        <td><?= $data['nis'] ?></td>
                                        <td><?= $data['nama'] ?></td>
                                        <td>
                                            <?php if ($data['status_masuk'] == 'tepat waktu'): ?>
                                                <span class="status-tepat">Tepat Waktu</span>
                                            <?php else: ?>
                                                <span class="status-telambat">Terlambat</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= $data['jam_masuk'] ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p style="text-align: center; padding: 15px; color: #7f8c8d;">Tidak ada siswa hadir</p>
                <?php endif; ?>
            </div>
            
            <!-- Kolom Siswa Tidak Hadir -->
            <div style="flex: 1; min-width: 300px;">
                <h4 style="margin-bottom: 10px; color: #e74c3c;">
                    <i class="fas fa-user-times"></i> Siswa Tidak Hadir (<?php echo count($siswa_tidak_hadir) + count($siswa_alpa); ?>)
                </h4>
                
                <!-- Tabel Sakit/Izin -->
                <?php if (!empty($siswa_tidak_hadir)): ?>
                    <h5 style="margin: 15px 0 5px;">Dengan Keterangan</h5>
                    <div class="table-responsive">
                        <table style="width: 100%;">
                            <thead>
                                <tr>
                                    <th>No</th>
                                    <th>NISN</th>
                                    <th>Nama</th>
                                    <th>Keterangan</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $no=1; foreach ($siswa_tidak_hadir as $nis => $data): ?>
                                    <tr>
                                        <td><?= $no++ ?></td>
                                        <td><?= $data['nis'] ?></td>
                                        <td><?= $data['nama'] ?></td>
                                        <td>
                                            <?php if ($data['jenis'] == 'sakit'): ?>
                                                <span class="status-pending">Sakit</span>
                                            <?php else: ?>
                                                <span class="status-pending">Izin</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
                
                <!-- Tabel Alpa -->
                <?php if (!empty($siswa_alpa)): ?>
                    <h5 style="margin: 15px 0 5px;">Tanpa Keterangan (Alpa)</h5>
                    <div class="table-responsive">
                        <table style="width: 100%;">
                            <thead>
                                <tr>
                                    <th>No</th>
                                    <th>NISN</th>
                                    <th>Nama</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $no=1; foreach ($siswa_alpa as $nis => $data): ?>
                                    <tr>
                                        <td><?= $no++ ?></td>
                                        <td><?= $nis ?></td>
                                        <td><?= $data['nama'] ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
                
                <?php if (empty($siswa_tidak_hadir) && empty($siswa_alpa)): ?>
                    <p style="text-align: center; padding: 15px; color: #7f8c8d;">Semua siswa hadir</p>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Summary Card -->
        <div class="card" style="margin-top: 20px;">
            <h4 style="margin-bottom: 15px;"><i class="fas fa-chart-pie"></i> Rekapitulasi</h4>
            <div class="presensi-info">
                <div class="info-item">
                    <div class="info-value"><?= count($siswa_hadir) ?></div>
                    <div class="info-label">Hadir</div>
                </div>
                <div class="info-item">
                    <div class="info-value"><?= count($siswa_tidak_hadir) ?></div>
                    <div class="info-label">Izin/Sakit</div>
                </div>
                <div class="info-item">
                    <div class="info-value"><?= count($siswa_alpa) ?></div>
                    <div class="info-label">Alpa</div>
                </div>
                <div class="info-item">
                    <div class="info-value"><?= $result_siswa->num_rows ?></div>
                    <div class="info-label">Total Siswa</div>
                </div>
            </div>
        </div>
    </div>
</div>
                
                <div id="tab-pengaturan" class="tab-content">
                    <div class="card">
                        <h3 style="margin-bottom: 12px; font-size: 1.1rem;"><i class="fas fa-cog"></i> Pengaturan Identitas Sekolah, Geolokasi dan Poin Kehadiran </h3>
                        
                        <form method="POST"  enctype="multipart/form-data">
                        <!--FORMA ISIAN SEKOLAH -->
                        <div class="container" style="display: flex; flex-wrap: wrap; gap: 20px;">
                        <div class="form-column" style="flex: 1; min-width: 300px;">
                            <h4 style="margin: 25px 0 10px;"><i class="fas fa-school"></i> Identitas Sekolah</h4>
                        
                            <div class="form-group">
                                <label for="logo_sekolah">Logo Sekolah</label>
                                <input type="file" id="logo_sekolah" name="logo_sekolah" accept="image/*">
                                <?php if (!empty($pengaturan['logo_sekolah'])): ?>
                                    <div style="margin-top: 10px;">
                                        <img src="uploads/logo/<?= $pengaturan['logo_sekolah'] ?>" 
                                            style="max-height: 100px; max-width: 100%; border: 1px solid #ddd; padding: 5px;">
                                        <div style="margin-top: 5px;">
                                            <a href="index.php?page=admin&action=delete_logo#pengaturan" 
                                            class="btn-danger" 
                                            onclick="return confirm('Hapus logo sekolah?')"
                                            style="padding: 5px 10px; font-size: 0.8rem; text-decoration: none;">
                                                <i class="fas fa-trash"></i> Hapus Logo
                                            </a>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                            
                            <div class="form-group">
                                <label for="nama_sekolah">Nama Sekolah</label>
                                <input type="text" id="nama_sekolah" name="nama_sekolah" 
                                    value="<?= htmlspecialchars($pengaturan['nama_sekolah'] ?? 'SMK NEGERI 6 KABUPATEN TANGERANG') ?>">
                            </div>
                            
                            <div class="form-group">
                                <label for="kepala_sekolah">Nama Kepala Sekolah</label>
                                <input type="text" id="kepala_sekolah" name="kepala_sekolah" 
                                    value="<?= htmlspecialchars($pengaturan['kepala_sekolah'] ?? 'Hj. Ani Risma, S.Kom, M.Pd') ?>">
                            </div>
                            
                            <div class="form-group">
                                <label for="nip_kepsek">NIP Kepala Sekolah</label>
                                <input type="text" id="nip_kepsek" name="nip_kepsek" 
                                    value="<?= htmlspecialchars($pengaturan['nip_kepsek'] ?? '197301022006042015') ?>">
                            </div>
                            
                            <div class="form-group">
                                <label for="wali_kelas">Nama Wali Kelas</label>
                                <input type="text" id="wali_kelas" name="wali_kelas" 
                                    value="<?= htmlspecialchars($pengaturan['wali_kelas'] ?? 'Suhermanto, S.Kom') ?>">
                            </div>
                            
                            <div class="form-group">
                                <label for="nip_walikelas">NIP Wali Kelas</label>
                                <input type="text" id="nip_walikelas" name="nip_walikelas" 
                                    value="<?= htmlspecialchars($pengaturan['nip_walikelas'] ?? '198007192022211006') ?>">
                            </div>
                            
                            <div class="form-group">
                                <label for="kota_sekolah">Kota Sekolah</label>
                                <input type="text" id="kota_sekolah" name="kota_sekolah" 
                                    value="<?= htmlspecialchars($pengaturan['kota_sekolah'] ?? 'Kota Serang') ?>">
                            </div>
                        </div>
                        <div class="form-column" style="flex: 1; min-width: 300px;">
                         <!-- SETTING WAKTU MASUK -->
                         <h4 style="margin: 25px 0 10px; color: #2c3e50;"><i class="fas fa-clock"></i> Waktu Presensi</h4>
                            <div class="form-group">
                                <label for="jam_masuk">Waktu Masuk</label>
                                <input type="time" id="jam_masuk" name="jamMasuk" value="<?php echo $jamMasuk; ?>" required>
                                <small>Waktu maksimal untuk presensi masuk</small>
                            </div>
                            
                            <div class="form-group">
                                <label for="jam_pulang">Waktu Pulang</label>
                                <input type="time" id="jam_pulang" name="jamPulang" value="<?php echo $jamPulang; ?>" required>
                                <small>Waktu minimal untuk presensi pulang</small>
                            </div>
                            <!-- Di dalam form pengaturan -->
                            <div class="form-group">
                                <label for="tanggal_awal_ganjil">Tanggal Awal Semester Ganjil</label>
                                <input type="date" id="tanggal_awal_ganjil" name="tanggal_awal_ganjil" 
                                    value="<?php echo $pengaturan['tanggal_awal_ganjil'] ?? ''; ?>" required>
                            </div>

                            <div class="form-group">
                                <label for="tanggal_awal_genap">Tanggal Awal Semester Genap</label>
                                <input type="date" id="tanggal_awal_genap" name="tanggal_awal_genap" 
                                    value="<?php echo $pengaturan['tanggal_awal_genap'] ?? ''; ?>" required>
                            </div>
                            <!-- END NEW TIME SETTINGS -->
                        </div>
                        </div>
                        <!--FORM ATUR LOKASI DAN RADIUS-->
                        <h4 style="margin: 25px 0 10px;"><i class="fas fa-map"></i> Lokasi Sekolah</h4>
                        <div class="container" style="display: flex; flex-wrap: wrap; gap: 20px;">
                        <!-- Kolom Kiri: Pengisian Teks -->
                        <div class="form-column" style="flex: 1; min-width: 300px;">
                            <div class="form-group">
                                <label for="latitude">Latitude Sekolah</label>
                                <input type="text" id="latitude" name="latitude" 
                                    value="<?= htmlspecialchars($latSekolah) ?>" required>
                            </div>
                            
                            <div class="form-group">
                                <label for="longitude">Longitude Sekolah</label>
                                <input type="text" id="longitude" name="longitude" 
                                    value="<?= htmlspecialchars($lngSekolah) ?>" required>
                            </div>
                            
                            <div class="form-group">
                                <label for="radius">Radius (meter)</label>
                                <input type="number" id="radius" name="radius" 
                                    value="<?= htmlspecialchars($radiusSekolah) ?>" required min="10">
                            </div>
                        </div>
                        
                        <!-- Kolom Kanan: Map  (lokasi openstreetmap)-->
                        <div class="map-column" style="flex: 1; min-width: 260px;">
                            <div class="iframe-container" aria-label="Embed Absensi Sekolah">
                                <!--
                                <iframe
                                    style="border:1px solid #ccc; border-radius:8px; width: 100%; height: 260px;"
                                    src="<?php echo 'https://' . $_SERVER['HTTP_HOST'] . '/' . strtolower($labelkelas) . '/sekolah.php'; ?>"
                                    title="Absensi Sekolah"
                                    loading="lazy"
                                    sandbox="allow-same-origin allow-scripts allow-forms allow-popups"
                                ></iframe>
                                -->
					  <iframe
                                    style="border:1px solid #ccc; border-radius:8px; width: 100%; height: 260px;"
                                    src="<?php echo 'https://' . $_SERVER['HTTP_HOST'] . '/absensiku/sekolah.php'; ?>"
                                    title="Absensi Sekolah"
                                    loading="lazy"
                                    sandbox="allow-same-origin allow-scripts allow-forms allow-popups"
                                ></iframe>
                            </div>
                        </div>
                    </div>                                             
                            
                            <button type="submit" name="save_pengaturan" class="btn-success">Simpan Pengaturan</button>
                        </form>
                        
                        <!--FORM ATUR POIN -->                      
                        <h4 style="margin: 25px 0 10px;"><i class="fas fa-star"></i> Pengaturan Poin Kehadiran</h4>
                        <?php
                        $sql_poin = "SELECT * FROM poin_kehadiran";
                        $result_poin = $conn->query($sql_poin);
                        if ($result_poin->num_rows > 0):
                        ?>
                            <table style="width:100%; margin-bottom:20px;">
                                <thead>
                                    <tr>
                                        <th>Jenis Kehadiran</th>
                                        <th>Poin</th>
                                        <th>Aksi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php while ($row_poin = $result_poin->fetch_assoc()): ?>
                                    <tr>
                                        <td>
                                            <?= ucwords(str_replace('_', ' ', $row_poin['jenis'])) ?>
                                        </td>
                                        <td>
                                            <form method="POST" style="display:flex;">
                                                <input type="hidden" name="id" value="<?= $row_poin['id'] ?>">
                                                <input type="number" name="poin" value="<?= $row_poin['poin'] ?>" 
                                                    min="0" max="100" style="width:80px; margin-right:10px;">
                                                <button type="submit" name="update_poin" class="btn-icon btn-icon-edit">
                                                    <i class="fas fa-save"></i>
                                                </button>
                                            </form>
                                        </td>
                                        <td>
                                            <button class="btn-icon btn-icon-delete" 
                                                onclick="if(confirm('Reset poin ke default?')) {
                                                    document.getElementById('reset_poin_id').value='<?= $row_poin['id'] ?>';
                                                    document.getElementById('reset_poin_form').submit();
                                                }">
                                                <i class="fas fa-undo"></i>
                                            </button>
                                        </td>
                                    </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>

                        <form method="POST" id="reset_poin_form" style="display:none;">
                            <input type="hidden" name="id" id="reset_poin_id">
                            <input type="hidden" name="reset_poin" value="1">
                        </form>
                    </div>
                </div>

                <div id="tab-libur" class="tab-content">
                    <div class="card">
                        <h3 style="margin-bottom: 12px; font-size: 1.1rem;"><i class="fas fa-calendar-times"></i> Periode Non-Presensi (Libur)</h3>
                        <?php
                        
                            ?>                       
                           
                            <form method="POST" action="index.php?page=admin#tab-libur">
                                <div class="form-group">
                                    <label for="nama_periode">Nama Periode</label>
                                    <input type="text" id="nama_periode" name="nama_periode" required>
                                </div>
                                
                                <div class="form-group">
                                    <label for="tanggal_mulai">Tanggal Mulai</label>
                                    <input type="date" id="tanggal_mulai" name="tanggal_mulai" required>
                                </div>
                                
                                <div class="form-group">
                                    <label for="tanggal_selesai">Tanggal Selesai</label>
                                    <input type="date" id="tanggal_selesai" name="tanggal_selesai" required>
                                </div>
                                
                                <div class="form-group">
                                    <label for="keterangan_libur">Keterangan</label>
                                    <textarea id="keterangan_libur" name="keterangan" rows="3"></textarea>
                                </div>
                                
                                <button type="submit" name="add_periode_libur" class="btn-success">Tambah Periode</button>
                            </form>

                        <?php
                        // Proses update periode libur
                        if (isset($_POST['update_periode_libur'])) {
                            $id = $_POST['id'];
                            $nama_periode = $_POST['nama_periode'];
                            $tanggal_mulai = $_POST['tanggal_mulai'];
                            $tanggal_selesai = $_POST['tanggal_selesai'];
                            $keterangan = $_POST['keterangan'];

                            $sql = "UPDATE periode_libur SET 
                                    nama_periode = '$nama_periode',
                                    tanggal_mulai = '$tanggal_mulai',
                                    tanggal_selesai = '$tanggal_selesai',
                                    keterangan = '$keterangan'
                                    WHERE id = $id";

                            if ($conn->query($sql)) {
                                $_SESSION['success_admin'] = "Periode libur berhasil diperbarui!";
                            } else {
                                $_SESSION['error_admin'] = "Error: " . $conn->error;
                            }
                            header('Location: index.php?page=admin#tab-libur');
                            exit();
                        }


                        // Proses tambah periode libur
                        if (isset($_POST['add_periode_libur'])) {
                            $nama_periode = $_POST['nama_periode'];
                            $tanggal_mulai = $_POST['tanggal_mulai'];
                            $tanggal_selesai = $_POST['tanggal_selesai'];
                            $keterangan = $_POST['keterangan'];
                            
                            $sql = "INSERT INTO periode_libur (nama_periode, tanggal_mulai, tanggal_selesai, keterangan) 
                                    VALUES ('$nama_periode', '$tanggal_mulai', '$tanggal_selesai', '$keterangan')";
                            if ($conn->query($sql)) {
                                $success_admin = "Periode libur berhasil ditambahkan!";
                            } else {
                                $error_admin = "Error: " . $conn->error;
                            }
                        }
                        ?>
                        
                        <div style="margin-top: 30px;">
                            <h4 style="margin-bottom: 12px;">Daftar Periode Libur</h4>
                            <?php
                            $sql = "SELECT * FROM periode_libur ORDER BY tanggal_mulai DESC";
                            $result = $conn->query($sql);
                            if ($result->num_rows > 0): ?>
                            <form method="POST" action="index.php?page=admin#tab-libur">
                                <input type="hidden" name="action" value="delete_selected_libur">
                                <button type="submit" class="btn-danger" style="margin-bottom: 10px;">
                                    <i class="fas fa-trash"></i> Hapus Data Terpilih
                                </button>
                                <div class="table-responsive">
                                    <table>
                                        <thead>
                                            <tr>
                                                <th><input type="checkbox" id="selectAllLibur"></th>
                                                <th>No</th>
                                                <th>Nama Periode</th>
                                                <th>Tanggal Mulai</th>
                                                <th>Tanggal Selesai</th>
                                                <th>Keterangan</th>
                                                <th>Aksi</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php $no=1; while ($row = $result->fetch_assoc()): ?>
                                                <tr>
                                                    <td><input type="checkbox" class="libur-check" name="selected_ids[]" value="<?= $row['id'] ?>"></td>
                                                    <td><?= $no++ ?></td>
                                                    <td><?= $row['nama_periode'] ?></td>
                                                    <td><?= formatTanggalID($row['tanggal_mulai']) ?></td>
                                                    <td><?= formatTanggalID($row['tanggal_selesai']) ?></td>
                                                    <td><?= $row['keterangan'] ?></td>
                                                    <td>
                                                        <!--
                                                        <a href="index.php?page=admin&action=edit_periode_libur&id=<?= $row['id'] ?>#libur" 
                                                        class="btn-icon btn-icon-edit" title="Edit" style="text-decoration: none; display: inline-block; width: 30px; height: 30px; background: #3498db; color: white; border-radius: 50%; text-align: center; line-height: 30px;">
                                                            <i class="fas fa-edit"></i>
                                                        </a>
                                                        -->
                                                        <a href="javascript:void(0)" onclick="openEditLiburModal(<?= $row['id'] ?>)" 
                                                        class="btn-icon btn-icon-edit" title="Edit">
                                                            <i class="fas fa-edit"></i>
                                                        </a>
                                                        <button class="btn-icon btn-icon-delete" 
                                                                onclick="if(confirm('Hapus periode ini?')){ location.href='index.php?page=admin&action=delete_periode_libur&id=<?= $row['id'] ?>#libur'; }"
                                                                title="Hapus" style="border: none; width: 30px; height: 30px; background: #e74c3c; color: white; border-radius: 50%; text-align: center; line-height: 30px; cursor: pointer;">
                                                            <i class="fas fa-trash"></i>
                                                        </button>
                                                    </td>
                                                </tr>
                                            <?php endwhile; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </form>
                            <!-- TAMBAHKAN SCRIPT INI DI AKHIR TAB -->
                            <script>
                                document.getElementById('selectAllLibur').addEventListener('click', function() {
                                    const checkboxes = document.querySelectorAll('.libur-check');
                                    checkboxes.forEach(checkbox => {
                                        checkbox.checked = this.checked;
                                    });
                                });
                            </script>
                            <?php else: ?>
                                <p style="text-align: center; padding: 15px; color: #7f8c8d;">Belum ada periode libur</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
 <div id="tab-status" class="tab-content">
    <div class="card">
        <h3 style="margin-bottom: 12px; font-size: 1.1rem;">
            <i class="fas fa-user-check"></i> Status Presensi Siswa (<?= formatTanggalID($today) ?>)
        </h3>

        <!-- Form Filter Tanggal -->
        <form method="GET" style="margin-bottom: 20px;">
            <input type="hidden" name="page" value="admin">
           
            <div style="display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 10px;">
                <div style="flex: 1; min-width: 200px;">
                    <label for="start_date_status">Tanggal Awal</label>
                    <input type="date" id="start_date_status" name="start_date_status" 
                        value="<?php echo isset($_GET['start_date_status']) ? $_GET['start_date_status'] : date('Y-m-d'); ?>" 
                        class="form-control">
                </div>
                
                <div style="flex: 1; min-width: 200px;">
                    <label for="end_date_status">Tanggal Akhir</label>
                    <input type="date" id="end_date_status" name="end_date_status" 
                        value="<?php echo isset($_GET['end_date_status']) ? $_GET['end_date_status'] : date('Y-m-d'); ?>" 
                        class="form-control">
                </div>
            </div>
            
            <div style="display: flex; flex-wrap: wrap; gap: 15px; align-items: center; margin-bottom: 15px; padding: 10px; background: #f8f9fa; border-radius: 8px;">
                <strong>Filter Status:</strong>
                <div style="display: flex; flex-wrap: wrap; gap: 10px;">
                    <label style="display: flex; align-items: center; cursor: pointer;">
                        <input type="checkbox" name="status_filter[]" value="Hadir" 
                            <?php echo (isset($_GET['status_filter']) && in_array('Hadir', $_GET['status_filter'])) ? 'checked' : ''; ?>
                            style="margin-right: 5px;">
                        Hadir
                    </label>
                    
                    <label style="display: flex; align-items: center; cursor: pointer;">
                        <input type="checkbox" name="status_filter[]" value="Sakit" 
                            <?php echo (isset($_GET['status_filter']) && in_array('Sakit', $_GET['status_filter'])) ? 'checked' : ''; ?>
                            style="margin-right: 5px;">
                        Sakit
                    </label>
                    
                    <label style="display: flex; align-items: center; cursor: pointer;">
                        <input type="checkbox" name="status_filter[]" value="Ijin" 
                            <?php echo (isset($_GET['status_filter']) && in_array('Ijin', $_GET['status_filter'])) ? 'checked' : ''; ?>
                            style="margin-right: 5px;">
                        Ijin
                    </label>
                    
                    <label style="display: flex; align-items: center; cursor: pointer;">
                        <input type="checkbox" name="status_filter[]" value="Terlambat" 
                            <?php echo (isset($_GET['status_filter']) && in_array('Terlambat', $_GET['status_filter'])) ? 'checked' : ''; ?>
                            style="margin-right: 5px;">
                        Terlambat
                    </label>
                    
                    <label style="display: flex; align-items: center; cursor: pointer; white-space: nowrap;">
                        <input type="checkbox" name="status_filter[]" value="Tidak Hadir" 
                            <?php echo (isset($_GET['status_filter']) && in_array('Tidak Hadir', $_GET['status_filter'])) ? 'checked' : ''; ?>
                            style="margin-right: 0px;">
                        Tidak Hadir
                    </label>
                    <label style="display: flex; align-items: center; cursor: pointer; white-space: nowrap;">
                        <input type="checkbox" name="status_filter[]" value="Libur" 
                            <?php echo (isset($_GET['status_filter']) && in_array('Tidak Hadir', $_GET['status_filter'])) ? 'checked' : ''; ?>
                            style="margin-right: 0px;">
                        Libur
                    </label>
                </div>
            </div>
            
            <div style="display: flex; gap: 10px;">
                <button type="submit" style="background-color: #28a745; color: white; padding: 12px 20px; border: none; border-radius: 5px; cursor: pointer; font-size: 16px;">Tampilkan Data</button>
                <a href="index.php?page=admin#tab-status" style="background-color: #28a745; color: white; padding: 12px 20px; border: none; border-radius: 5px; cursor: pointer; font-size: 16px; text-decoration: none; display: inline-block;">.::Reset::.</a>
                <?php if (isset($_GET['start_date_status']) || isset($_GET['status_filter'])): ?>
                    <button type="button" onclick="printRekapStatus()" class="btn-warning">
                        <i class="fas fa-print"></i> Cetak Rekap
                    </button>
                <?php endif; ?>
            </div>
        </form>

        <?php
        // PROSES FILTER DATA
        $start_date = isset($_GET['start_date_status']) ? $_GET['start_date_status'] : date('Y-m-d');
        $end_date = isset($_GET['end_date_status']) ? $_GET['end_date_status'] : date('Y-m-d');
        $status_filters = isset($_GET['status_filter']) ? $_GET['status_filter'] : [];
        
        // Ambil semua periode libur untuk pengecekan
        $sql_libur = "SELECT * FROM periode_libur";
        $result_libur = $conn->query($sql_libur);
        $periode_libur = [];
        while ($row = $result_libur->fetch_assoc()) {
            $periode_libur[] = [
                'start' => $row['tanggal_mulai'],
                'end' => $row['tanggal_selesai']
            ];
        }
        
        // Hitung selisih hari
        $start = new DateTime($start_date);
        $end = new DateTime($end_date);
        $end->modify('+1 day'); // Termasuk tanggal akhir
        $interval = DateInterval::createFromDateString('1 day');
        $period = new DatePeriod($start, $interval, $end);
        
        // Query semua siswa
        $sql_siswa = "SELECT nis, nama FROM siswa WHERE nis != '123' ORDER BY nama ASC ";
        $result_siswa = $conn->query($sql_siswa);
        
        if ($result_siswa->num_rows > 0):
            // Array untuk menyimpan semua tanggal dalam rentang
            $all_dates = [];
            foreach ($period as $dt) {
                $all_dates[] = $dt->format("Y-m-d");
            }
            
            // Array untuk menyimpan data per siswa
            $siswa_data = [];
            while ($siswa = $result_siswa->fetch_assoc()) {
                $nis = $siswa['nis'];
                $nama = $siswa['nama'];
                
                // Query status presensi per siswa
                $sql_presensi = "SELECT tanggal, jam_masuk, status_masuk 
                                 FROM presensi 
                                 WHERE nis = '$nis' 
                                 AND tanggal BETWEEN '$start_date' AND '$end_date' AND nis != '123'";
                $result_presensi = $conn->query($sql_presensi);
                $presensi = [];
                while ($row = $result_presensi->fetch_assoc()) {
                    $presensi[$row['tanggal']] = $row;
                }
                
                // Query izin per siswa
                $sql_izin = "SELECT tanggal, jenis 
                             FROM absensi_izin 
                             WHERE nis = '$nis' 
                             AND status = 'diterima'
                             AND tanggal BETWEEN '$start_date' AND '$end_date' AND nis != '123'";
                $result_izin = $conn->query($sql_izin);
                $izin = [];
                while ($row = $result_izin->fetch_assoc()) {
                    $izin[$row['tanggal']] = $row;
                }
                
                // Simpan data siswa
                $siswa_data[$nis] = [
                    'nama' => $nama,
                    'presensi' => $presensi,
                    'izin' => $izin
                ];
            }
        ?>

            <!-- Printable Content (hidden until printed) -->
            <div id="printable-status-rekap" style="display: none;">
                <div style="text-align: center; margin-bottom: 20px;">
                    <?php if (!empty($pengaturan['logo_sekolah'])): ?>
                        <img src="uploads/logo/<?= $pengaturan['logo_sekolah'] ?>" style="max-height: 80px; margin-bottom: 10px;">
                    <?php endif; ?>
                    <h3 style="margin: 5px 0;"><?= $pengaturan['nama_sekolah'] ?? 'SMK NEGERI 6 KOTA SERANG' ?></h3>
                    <h4 style="margin: 5px 0;">REKAPITULASI STATUS PRESENSI SISWA</h4>
                    <p style="margin: 5px 0;">Periode: <?= formatTanggalID($start_date) ?> s/d <?= formatTanggalID($end_date) ?></p>
                    <?php if (!empty($status_filters)): ?>
                        <p style="margin: 5px 0;">Filter Status: <?= implode(', ', $status_filters) ?></p>
                    <?php endif; ?>
                </div>
                
                <table style="width: 100%; border-collapse: collapse; margin-bottom: 20px;">
                    <thead>
                        <tr>
                            <th style="border: 1px solid #000; padding: 8px; text-align: center;">No</th>
                            <th style="border: 1px solid #000; padding: 8px; text-align: center;">Tanggal</th>
                            <th style="border: 1px solid #000; padding: 8px; text-align: center;">NISN</th>
                            <th style="border: 1px solid #000; padding: 8px; text-align: center;">Nama Siswa</th>
                            <th style="border: 1px solid #000; padding: 8px; text-align: center;">Status</th>
                            <th style="border: 1px solid #000; padding: 8px; text-align: center;">Keterangan</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        // Query untuk mendapatkan data status presensi berdasarkan filter
                        $sql = "SELECT 
                                    p.tanggal,
                                    s.nis,
                                    s.nama,
                                    CASE 
                                        WHEN p.status_masuk = 'tepat waktu' THEN 'Hadir'
                                        WHEN p.status_masuk = 'terlambat' THEN 'Terlambat'
                                        ELSE 'Tidak Hadir'
                                    END as status,
                                    p.keterangan_terlambat as keterangan
                                FROM 
                                    presensi p
                                JOIN 
                                    siswa s ON p.nis = s.nis
                                WHERE 
                                    p.tanggal BETWEEN '$start_date' AND '$end_date'";
                        
                        // Tambahkan filter status jika ada
                        if (!empty($status_filters)) {
                            $status_conditions = [];
                            foreach ($status_filters as $filter) {
                                if ($filter == 'Hadir') {
                                    $status_conditions[] = "p.status_masuk = 'tepat waktu'";
                                } elseif ($filter == 'Terlambat') {
                                    $status_conditions[] = "p.status_masuk = 'terlambat'";
                                } elseif ($filter == 'Tidak Hadir') {
                                    // Untuk tidak hadir, kita perlu memeriksa siswa yang tidak ada di presensi
                                    // Ini memerlukan query yang lebih kompleks
                                }
                            }
                            
                            if (!empty($status_conditions)) {
                                $sql .= " AND (" . implode(" OR ", $status_conditions) . ")";
                            }
                        }
                        
                        $sql .= " ORDER BY p.tanggal DESC, s.nama";
                        
                        $result = $conn->query($sql);
                        $no = 1;
                        
                        while ($row = $result->fetch_assoc()): ?>
                            <tr>
                                <td style="border: 1px solid #000; padding: 8px; text-align: center;"><?= $no++ ?></td>
                                <td style="border: 1px solid #000; padding: 8px;"><?= formatTanggalID($row['tanggal']) ?></td>
                                <td style="border: 1px solid #000; padding: 8px;"><?= $row['nis'] ?></td>
                                <td style="border: 1px solid #000; padding: 8px;"><?= $row['nama'] ?></td>
                                <td style="border: 1px solid #000; padding: 8px; text-align: center;">
                                    <?= $row['status'] ?>
                                </td>
                                <td style="border: 1px solid #000; padding: 8px;">
                                    <?= $row['keterangan'] ?: '-' ?>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
                
                <div style="display: flex; justify-content: space-between; margin-top: 50px;">
                    <div style="text-align: center; width: 40%;">
                        <p>Mengetahui,</p>
                        <p>Kepala Sekolah</p>
                        <br><br><br>
                        <p><u><?= $pengaturan['kepala_sekolah'] ?? 'Nama Kepala Sekolah' ?></u></p>
                        <p>NIP. <?= $pengaturan['nip_kepsek'] ?? '123456789' ?></p>
                    </div>
                    <div style="text-align: center; width: 40%;">
                        <p><?= $pengaturan['kota_sekolah'] ?? 'Kota Serang' ?>, <?= date('d F Y') ?></p>
                        <p>Wali Kelas</p>
                        <br><br><br>
                        <p><u><?= $pengaturan['wali_kelas'] ?? 'Nama Wali Kelas' ?></u></p>
                        <p>NIP. <?= $pengaturan['nip_walikelas'] ?? '987654321' ?></p>
                    </div>
                </div>
            </div>

            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th rowspan="2">No</th>
                            <th rowspan="2">NISN</th>
                            <th rowspan="2">Nama Siswa</th>
                            <th colspan="<?= count($all_dates) ?>" style="text-align: center;">Tanggal Presensi</th>
                        </tr>
                        <tr>
                            <?php foreach ($all_dates as $date): ?>
                                <th style="text-align: center; font-size: 0.8rem; padding: 5px;">
                                    <?= date('d/m', strtotime($date)) ?>
                                </th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $no = 1; foreach ($siswa_data as $nis => $data): ?>
                            <tr>
                                <td><?= $no++ ?></td>
                                <td><?= $nis ?></td>
                                <td><?= $data['nama'] ?></td>
                                <?php foreach ($all_dates as $date): 
                                    $status = '';
                                    $status_class = '';
                                    
                                    // Cek apakah hari ini libur (Sabtu/Minggu)
                                    $hariIni = date('w', strtotime($date)); // 0=Minggu, 6=Sabtu
                                    $isWeekend = ($hariIni == 0 || $hariIni == 6);
                                    
                                    // Cek apakah termasuk dalam periode libur
                                    $isLiburPeriod = false;
                                    foreach ($periode_libur as $libur) {
                                        if ($date >= $libur['start'] && $date <= $libur['end']) {
                                            $isLiburPeriod = true;
                                            break;
                                        }
                                    }
                                    
                                    if ($isWeekend || $isLiburPeriod) {
                                        $status = 'L';
                                        $status_class = 'status-libur';
                                        $title = 'Libur';
                                    } 
                                    else if (isset($data['presensi'][$date])) {
                                        if ($data['presensi'][$date]['status_masuk'] == 'terlambat') {
                                            $status = 'T';
                                            $status_class = 'status-telambat';
                                            $title = 'Terlambat: '.$data['presensi'][$date]['jam_masuk'];
                                        } else {
                                            $status = 'H';
                                            $status_class = 'status-tepat';
                                            $title = 'Hadir: '.$data['presensi'][$date]['jam_masuk'];
                                        }
                                    } elseif (isset($data['izin'][$date])) {
                                        if ($data['izin'][$date]['jenis'] == 'sakit') {
                                            $status = 'S';
                                            $status_class = 'status-pending';
                                            $title = 'Sakit';
                                        } else {
                                            $status = 'I';
                                            $status_class = 'status-pending';
                                            $title = 'Ijin';
                                        }
                                    } else {
                                        $status = 'A';
                                        $status_class = 'status-ditolak';
                                        $title = 'Tidak Hadir';
                                    }
                                    
                                    // Filter status
                                    $show = true;
                                    if (!empty($status_filters)) {
                                        $show = false;
                                        if (in_array('Hadir', $status_filters) && $status == 'H') $show = true;
                                        if (in_array('Terlambat', $status_filters) && $status == 'T') $show = true;
                                        if (in_array('Sakit', $status_filters) && $status == 'S') $show = true;
                                        if (in_array('Ijin', $status_filters) && $status == 'I') $show = true;
                                        if (in_array('Tidak Hadir', $status_filters) && $status == 'A') $show = true;
                                    }
                                ?>
                                    <td style="text-align: center; <?= $show ? '' : 'background-color: #f8f9fa;' ?>">
                                        <?php if ($show): ?>
                                            <span class="<?= $status_class ?>" title="<?= $title ?>">
                                                <?= $status ?>
                                            </span>
                                        <?php else: ?>
                                            -
                                        <?php endif; ?>
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <div style="margin-top: 20px; background: #f8f9fa; padding: 15px; border-radius: 8px;">
                <h4>Keterangan Status:</h4>
                <ul style="display: flex; flex-wrap: wrap; gap: 15px; list-style: none; padding: 0; margin: 10px 0;">
                    <li><span class="status-tepat">H</span> = Hadir Tepat Waktu</li>
                    <li><span class="status-telambat">T</span> = Terlambat</li>
                    <li><span class="status-pending">S</span> = Sakit</li>
                    <li><span class="status-pending">I</span> = Ijin</li>
                    <li><span class="status-libur">L</span> = Libur</li>
                    <li><span class="status-ditolak">A</span> = Tidak Hadir (Alpa)</li>
                </ul>
            </div>
        <?php else: ?>
            <p style="text-align: center; padding: 15px; color: #7f8c8d;">
                Tidak ada data siswa
            </p>
        <?php endif; ?>
    </div>
</div>

                <div id="tab-rekap" class="tab-content">
                    <div class="card">
                        <h3 style="margin-bottom: 12px; font-size: 1.1rem;"><i class="fas fa-chart-bar"></i> Rekap Presensi Siswa</h3>
                        <!-- Di dalam tab-rekap, setelah form filter -->
                        <div style="display: flex; gap: 10px; margin-bottom: 20px; justify-content: center;">
                            <a href="rekap_per_siswa.php?start_date=<?php echo $start_date; ?>&end_date=<?php echo $end_date; ?>" 
                            target="_blank" class="btn-warning" style="padding: 10px 15px; text-decoration: none;">
                                <i class="fas fa-external-link-alt"></i> Buka Halaman Rekap Siswa
                            </a>
                        </div>
                        <!-- TOMBOL CETAK - Tambahkan di sini -->
                        <div style="display: flex; gap: 10px; margin-bottom: 20px; justify-content: center;">
                            <button type="button" onclick="showCetakOptions('pdf')" class="btn-warning">
                                <i class="fas fa-file-pdf"></i> Cetak PDF
                            </button>
                            <button type="button" onclick="showCetakOptions('excel')" class="btn-success">
                                <i class="fas fa-file-excel"></i> Export Excel
                            </button>
                            <button type="button" onclick="showCetakOptions('html')" class="btn-info" style="background-color: #17a2b8; color: white; padding: 12px 20px; border: none; border-radius: 5px; cursor: pointer; font-size: 16px;">
				      <i class="fas fa-file-code"></i> Tampilkan HTML
				  </button>
                        </div>
                        <form method="GET" style="margin-bottom: 20px;">
                        <input type="hidden" name="page" value="admin">
                        
                        <!-- Baris pertama: Filter tanggal -->
                        <div style="display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 10px;">
                            <div style="flex: 1; min-width: 200px;">
                                <label for="start_date">Tanggal Awal</label>
                                <input type="date" id="start_date" name="start_date" 
                                    value="<?php echo isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-d'); ?>" 
                                    class="form-control">
                            </div>
                            
                            <div style="flex: 1; min-width: 200px;">
                                <label for="end_date">Tanggal Akhir</label>
                                <input type="date" id="end_date" name="end_date" 
                                    value="<?php echo isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d'); ?>" 
                                    class="form-control">
                            </div>
                        </div>
                        <div style="display: flex; gap: 10px;">
                            <!-- Tombol Filter -->
                            <button type="submit" style=" background-color: #28a745; color: white;  padding: 12px 20px;  border: none;  border-radius: 5px;   cursor: pointer;  font-size: 16px; ">  Filter   </button>

                            <!-- Tombol Reset -->
                            <a href="index.php?page=admin#tab-rekap" style=" background-color: #28a745; color: white; padding: 12px 20px; border: none;	  border-radius: 5px; cursor: pointer; font-size: 16px; text-decoration: none; display: inline-block;"> .::Reset::.  </a>
                        </div>
                        </form>
                        
                        
                        <?php
                        // Fungsi untuk menghitung hari efektif (termasuk pengecekan hari libur)
                        function hitungHariEfektif($start_date, $end_date, $periode_libur) {
                            $start = new DateTime($start_date);
                            $end = new DateTime($end_date);
                            $end->modify('+1 day'); // Termasuk tanggal akhir
                            $interval = new DateInterval('P1D');
                            $period = new DatePeriod($start, $interval, $end);
                        
                            $hari_efektif = 0;
                        
                            foreach ($period as $date) {
                                $tanggal = $date->format('Y-m-d');
                                $hari = $date->format('w'); // 0=Minggu, 6=Sabtu
                        
                                // Skip akhir pekan
                                if ($hari == 0 || $hari == 6) {
                                    continue;
                                }
                        
                                // Cek apakah termasuk dalam periode libur
                                $isLibur = false;
                                foreach ($periode_libur as $libur) {
                                    if ($tanggal >= $libur['tanggal_mulai'] && $tanggal <= $libur['tanggal_selesai']) {
                                        $isLibur = true;
                                        break;
                                    }
                                }
                        
                                if (!$isLibur) {
                                    $hari_efektif++;
                                }
                            }
                        
                            return $hari_efektif;
                        }
                        // Ambil rentang tanggal dari GET
                        $start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-01');
                        $end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');
                        
                        // Ambil semua periode libur
                        $sql_libur = "SELECT tanggal_mulai, tanggal_selesai FROM periode_libur";
                        $result_libur = $conn->query($sql_libur);
                        $periode_libur = [];
                        while ($row = $result_libur->fetch_assoc()) {
                            $periode_libur[] = $row;
                        }

                        // Hitung hari efektif untuk rentang tanggal
                        $total_hari_efektif = hitungHariEfektif($start_date, $end_date, $periode_libur);
                        $hadir = $rekap['total_hadir'];
                        $sakit = $rekap['sakit'];
                        $ijin = $rekap['ijin'];
                        $tidak_hadir = $total_hari_efektif - ($hadir + $sakit + $ijin);
                        $tidak_hadir = $tidak_hadir < 0 ? 0 : $tidak_hadir; // Pastikan tidak negatif
                        
                        $poin = [];
                        $sql_poin = "SELECT jenis, poin FROM poin_kehadiran";
                        $result_poin = $conn->query($sql_poin);
                        if ($result_poin->num_rows > 0) {
                            while ($row_poin = $result_poin->fetch_assoc()) {
                                $poin[$row_poin['jenis']] = $row_poin['poin'];
                            }
                        } else {
                            // Default values jika tabel kosong
                            $poin = [
                                'hadir_tepat_waktu' => 5,
                                'hadir_terlambat' => 3,
                                'pulang_tepat_waktu' => 5,
                                'pulang_cepat' => 3,
                                'sakit' => 1,
                                'ijin' => 1,
                                'tidak_hadir' => 0
                            ];
                        }

                        // Ganti query rekap siswa dengan:
                        $sql_per_siswa = "SELECT 
                        s.nis,
                        s.nama,
                        COUNT(p.id) AS hadir,
                        SUM(CASE WHEN p.status_masuk = 'tepat waktu' THEN 1 ELSE 0 END) AS tepat_waktu,
                        SUM(CASE WHEN p.status_masuk = 'terlambat' THEN 1 ELSE 0 END) AS terlambat,
                        (SELECT COUNT(*) FROM presensi p2 
                        WHERE p2.nis = s.nis 
                        AND p2.tanggal BETWEEN '$start_date' AND '$end_date'
                        AND p2.status_pulang = 'tepat waktu') AS pulang_tepat_waktu,
                        (SELECT COUNT(*) FROM presensi p3 
                        WHERE p3.nis = s.nis 
                        AND p3.tanggal BETWEEN '$start_date' AND '$end_date'
                        AND p3.status_pulang = 'cepat') AS pulang_cepat,
                        (SELECT COUNT(*) FROM absensi_izin a 
                        WHERE a.nis = s.nis 
                        AND a.tanggal BETWEEN '$start_date' AND '$end_date'
                        AND a.status = 'diterima'
                        AND a.jenis = 'sakit') AS sakit,
                        (SELECT COUNT(*) FROM absensi_izin a 
                        WHERE a.nis = s.nis 
                        AND a.tanggal BETWEEN '$start_date' AND '$end_date'
                        AND a.status = 'diterima'
                        AND a.jenis = 'ijin') AS ijin,
                        (SELECT GROUP_CONCAT(CASE 
                            WHEN jenis = 'sakit' THEN 'Sakit' 
                            WHEN jenis = 'ijin' THEN 'Ijin' 
                            ELSE NULL 
                        END SEPARATOR ', ') 
                        FROM absensi_izin 
                        WHERE nis = s.nis 
                        AND tanggal BETWEEN '$start_date' AND '$end_date'
                        AND status = 'diterima'
                        LIMIT 1) AS status_ketidakhadiran
                        FROM siswa s
                        LEFT JOIN presensi p ON s.nis = p.nis AND p.tanggal BETWEEN '$start_date' AND '$end_date'
                        WHERE s.nis != '123'
                        GROUP BY s.nis, s.nama
                        ORDER BY s.nama";

                        $result_per_siswa = $conn->query($sql_per_siswa);
                        ?>
                        
                        <h4 style="margin: 25px 0 10px;">Rekap Harian Kelas</h4>
                        <!-- Di dalam tab-rekap-harian, setelah form filter -->
                        <!-- Di dalam tab-rekap-harian, setelah form filter -->
                        <div style="display: flex; gap: 10px; margin-bottom: 20px; justify-content: center;">
                            <a href="rekap_harian_kelas.php?start_date=<?php echo $start_date; ?>&end_date=<?php echo $end_date; ?>" 
                            target="_blank" class="btn-warning" style="padding: 10px 15px; text-decoration: none;">
                                <i class="fas fa-external-link-alt"></i> Buka Halaman Rekap Harian
                            </a>
                        </div>
                        <?php

                        // Query rekap harian kelas
                        $sql_harian = "SELECT 
                            tanggal,
                            COUNT(DISTINCT nis) AS hadir,
                            SUM(CASE WHEN status_masuk = 'tepat waktu' THEN 1 ELSE 0 END) AS tepat_waktu,
                            SUM(CASE WHEN status_masuk = 'terlambat' THEN 1 ELSE 0 END) AS terlambat,
                            (SELECT COUNT(DISTINCT a.nis) FROM absensi_izin a 
                             WHERE a.tanggal = p.tanggal
                             AND a.status = 'diterima'
                             AND a.jenis = 'sakit') AS sakit,
                            (SELECT COUNT(DISTINCT a.nis) FROM absensi_izin a 
                             WHERE a.tanggal = p.tanggal
                             AND a.status = 'diterima'
                             AND a.jenis = 'ijin') AS ijin
                          FROM presensi p
                          WHERE tanggal BETWEEN '$start_date' AND '$end_date' 
                          GROUP BY tanggal
                          ORDER BY tanggal DESC";
                        
                        $result_harian = $conn->query($sql_harian);
                        ?>
                        
                        <?php if ($result_harian->num_rows > 0): ?>
                            <div class="table-responsive">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Tanggal</th>
                                            <th>Hadir</th>
                                            <th>Tidak Hadir</th> 
                                            <th>Tepat Waktu</th>
                                            <th>Terlambat</th>
                                            <th>Sakit</th>
                                            <th>Ijin</th>

                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php 
                                        // Dapatkan jumlah total siswa
                                        $total_siswa = $conn->query("SELECT COUNT(*) as total FROM siswa WHERE nis != '123'")->fetch_assoc()['total'];
                                        while ($row = $result_harian->fetch_assoc()): 
                                                // Hitung siswa tidak hadir
                                                 $tidak_hadir = $total_siswa - ($row['hadir'] + $row['sakit'] + $row['ijin']);
                                        
                                        ?>
                                            <tr>
                                                <td><?= formatTanggalID($row['tanggal']) ?></td>
                                                <td><?= $row['hadir'] ?></td>
                                                <td><?= $tidak_hadir ?></td>
                                                <td><?= $row['tepat_waktu'] ?></td>
                                                <td><?= $row['terlambat'] ?></td>
                                                <td><?= $row['sakit'] ?></td>
                                                <td><?= $row['ijin'] ?></td>

                                            </tr>
                                        <?php endwhile; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <p style="text-align: center; padding: 15px; color: #7f8c8d;">Tidak ada data harian</p>
                        <?php endif; ?>
                        
                        
                        <div style="margin-top: 30px;">
                            <h4 style="margin-bottom: 12px;"><i class="fas fa-trophy"></i> Rangking Total Poin</h4>
                            <!-- Di dalam tab-rekap, di bagian rangking -->
                        <div style="display: flex; gap: 10px; margin-bottom: 10px; justify-content: center;">
                            <a href="rekap_rangking_poin.php?start_date=<?php echo $start_date; ?>&end_date=<?php echo $end_date; ?>" 
                            target="_blank" class="btn-warning" style="padding: 10px 15px; text-decoration: none;">
                                <i class="fas fa-external-link-alt"></i> Buka Halaman Rangking Poin
                            </a>
                        </div>
                            <?php
                            // Query untuk mendapatkan ranking dengan detail poin
                            // Di bagian Rekap Presensi, ganti query $sql_ranking dengan:
                            $sql_ranking = "SELECT 
                                s.nis,
                                s.nama,
                                SUM(CASE WHEN p.status_masuk = 'tepat waktu' THEN 1 ELSE 0 END) AS tepat_hadir,
                                SUM(CASE WHEN p.status_pulang = 'tepat waktu' THEN 1 ELSE 0 END) AS tepat_pulang,
                                SUM(CASE WHEN p.status_masuk = 'terlambat' THEN 1 ELSE 0 END) AS terlambat,
                                SUM(CASE WHEN p.status_pulang = 'cepat' THEN 1 ELSE 0 END) AS pulang_cepat,
                                SUM(CASE WHEN p.jam_masuk IS NOT NULL AND p.jam_pulang IS NULL THEN 1 ELSE 0 END) AS belum_pulang,
                                COUNT(DISTINCT p.tanggal) AS total_hadir,
                                (SELECT COUNT(*) FROM absensi_izin a 
                                WHERE a.nis = s.nis 
                                AND a.status = 'diterima'
                                AND a.tanggal BETWEEN '$start_date' AND '$end_date') AS tidak_hadir,
                                COALESCE(SUM(
                                    CASE 
                                        WHEN p.status_masuk = 'tepat waktu' THEN (SELECT poin FROM poin_kehadiran WHERE jenis = 'hadir_tepat_waktu')
                                        WHEN p.status_masuk = 'terlambat' THEN (SELECT poin FROM poin_kehadiran WHERE jenis = 'hadir_terlambat')
                                        ELSE 0
                                    END +
                                    CASE 
                                        WHEN p.status_pulang = 'tepat waktu' THEN (SELECT poin FROM poin_kehadiran WHERE jenis = 'pulang_tepat_waktu')
                                        WHEN p.status_pulang = 'cepat' THEN (SELECT poin FROM poin_kehadiran WHERE jenis = 'pulang_cepat')
                                        ELSE 0
                                    END
                                ), 0) +
                                COALESCE((
                                    SELECT COUNT(*) * (SELECT poin FROM poin_kehadiran WHERE jenis = 'sakit') 
                                    FROM absensi_izin a 
                                    WHERE a.nis = s.nis 
                                    AND a.status = 'diterima' 
                                    AND a.jenis = 'sakit'
                                    AND a.tanggal BETWEEN '$start_date' AND '$end_date'
                                ), 0) +
                                COALESCE((
                                    SELECT COUNT(*) * (SELECT poin FROM poin_kehadiran WHERE jenis = 'ijin') 
                                    FROM absensi_izin a 
                                    WHERE a.nis = s.nis 
                                    AND a.status = 'diterima' 
                                    AND a.jenis = 'ijin'
                                    AND a.tanggal BETWEEN '$start_date' AND '$end_date'
                                ), 0) +
                                COALESCE((
                                    SELECT COUNT(*) * (SELECT poin FROM poin_kehadiran WHERE jenis = 'belum_presensi_pulang')
                                    FROM presensi p2
                                    WHERE p2.nis = s.nis
                                    AND p2.tanggal BETWEEN '$start_date' AND '$end_date'
                                    AND p2.jam_masuk IS NOT NULL
                                    AND p2.jam_pulang IS NULL
                                ), 0) AS total_poin
                            FROM siswa s
                            LEFT JOIN presensi p ON s.nis = p.nis AND p.tanggal BETWEEN '$start_date' AND '$end_date'
                            WHERE s.nis != '123'
                            GROUP BY s.nis, s.nama
                            ORDER BY total_poin DESC, tepat_hadir DESC, s.nama ASC";
                            
                            $result_ranking = $conn->query($sql_ranking);
                            ?>
                            
                            <div class="table-responsive">
                                <table class="table-ranking">
                                    <thead>
                                    <tr>
                                        <th rowspan="2">Peringkat</th>
                                        <th rowspan="2">NISN</th>
                                        <th rowspan="2">Nama</th>
                                        <th colspan="2">Sesuai Waktu</th>
                                        <th colspan="3">Tidak Sesuai Waktu</th>
                                        <th rowspan="2">Total Poin</th>
                                        <th rowspan="2">Kehadiran (%)</th>
                                        
                                    </tr>
                                    <tr>
                                        <th>Hadir</th>
                                        <th>Pulang</th>
                                        <th>Terlambat <br>Masuk</th>
                                        <th>Pulang <br> Cepat</th>
                                        <th>Belum <br>Presensi</th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                        <?php 
                                        $rank = 1;
                                        $previous_poin = null;
                                        $actual_rank = 1;
                                        
                                        while ($row = $result_ranking->fetch_assoc()): 
                                            // Handle ranking yang sama untuk poin yang sama
                                            if ($previous_poin !== null && $row['total_poin'] < $previous_poin) {
                                                $actual_rank = $rank;
                                            }
                                            $previous_poin = $row['total_poin'];
                                            
                                            // Hitung hari efektif
                                            $total_hari_efektif = hitungHariEfektif($start_date, $end_date, $periode_libur);
                                            $persentase_hadir = ($total_hari_efektif > 0) ? round(($row['total_hadir'] / $total_hari_efektif) * 100) : 0;
                                        ?>
                                            <tr>
                                                <td class="text-center"><?= $actual_rank ?></td>
                                                <td><?= $row['nis'] ?></td>
                                                <td><?= $row['nama'] ?></td>
                                                <td class="text-center"><?= $row['tepat_hadir'] == 0 ? '-' : $row['tepat_hadir'] ?></td>
                                                <td class="text-center"><?= $row['tepat_pulang'] == 0 ? '-' : $row['tepat_pulang'] ?></td>
                                                <td class="text-center"><?= $row['terlambat'] == 0 ? '-' : $row['terlambat'] ?></td>
                                                <td class="text-center"><?= $row['pulang_cepat'] == 0 ? '-' : $row['pulang_cepat'] ?></td>
                                                <td class="text-center"><?= $row['belum_pulang'] == 0 ? '-' : $row['belum_pulang'] ?></td>
                                                <td class="text-center">
                                                    <?php if ($row['total_poin'] == 0): ?>
                                                        -
                                                    <?php else: ?>
                                                        <span class="<?= $row['total_poin'] >= 80 ? 'status-tepat' : ($row['total_poin'] >= 50 ? 'status-pending' : 'status-ditolak') ?>">
                                                            <?= $row['total_poin'] ?>
                                                        </span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-center">
                                                    <?= $row['total_hadir'] == 0 ? '-' : $row['total_hadir'] ?> 
                                                    <?php if ($row['total_hadir'] != 0): ?>
                                                        <small>(<?= $persentase_hadir ?>%)</small>
                                                    <?php endif; ?>
                                                </td>


                                            </tr>
                                        <?php 
                                            $rank++;
                                        endwhile; 
                                        ?>
                                    </tbody>
                                </table>
                            </div>
                            
                            <div style="margin-top: 15px; background: #f8f9fa; padding: 15px; border-radius: 8px;">
                                <h5>Keterangan:</h5>
                                <ul style="display: flex; flex-wrap: wrap; gap: 15px; list-style: none; padding: 0; margin: 10px 0;">
                                    <li><span class="status-tepat">≥ 80 Poin</span> = Sangat Baik</li>
                                    <li><span class="status-pending">50-79 Poin</span> = Cukup</li>
                                    <li><span class="status-ditolak">≤ 49 Poin</span> = Perlu Perbaikan</li>
                                    <li><i>Hadir</i> = Total hari dengan presensi masuk</li>
                                    <li><i>Tidak Hadir</i> = Total hari dengan izin/sakit</li>
                                </ul>
                            </div>
                        </div>

                        <h4 style="margin: 15px 0 10px;">Rekap Per Siswa</h4>
                        <?php if ($result_per_siswa->num_rows > 0): ?>
                            <div class="table-responsive">
                                <table>
                                    <thead>
                                    <tr>
                                        <th rowspan="2"  style="width = 5%;">No</th>
                                        <th rowspan="2" style="width = 10%;">NISN</th>
                                        <th rowspan="2" style="width = 10%;">Nama</th>
                                        <th colspan="2" style="border-bottom: 1px solid #ddd; width = 40%; text-align: center; ">Jumlah Kehadiran (Hari)</th>
                                        <th colspan="3" style="border-bottom: 1px solid #ddd; width = 40%; text-align: center; ">Jumlah ketidakhadiran (Hari)</th>
                                        <th rowspan="2"  style="text-align: center; width = 10%">Total Hari Efektif</th>
                                        <th rowspan="2">Poin</th>
                                        <!--<th rowspan="2" style="text-align: center; ">Kehadiran (%)</th>-->
                                        
                                    </tr>
                                    </thead>
                                    <tbody>
                                        <?php 
                                            $no=1;
                                            while ($row = $result_per_siswa->fetch_assoc()): 
                                                $hadir = $row['hadir'];
                                                $tepat_waktu = $row['tepat_waktu'];
                                                $terlambat = $row['terlambat'];
                                                $sakit = $row['sakit'];
                                                $ijin = $row['ijin'];
                                                $alpa = $tidak_hadir;
                                                
                                                // Di dalam loop siswa
                                                $pulang_tepat_waktu = $row['pulang_tepat_waktu'];
                                                $pulang_cepat = $row['pulang_cepat'];

                                                $total_poin = 
                                                    ($tepat_waktu * $poin['hadir_tepat_waktu']) +
                                                    ($terlambat * $poin['hadir_terlambat']) +
                                                    ($pulang_tepat_waktu * $poin['pulang_tepat_waktu']) +
                                                    ($pulang_cepat * $poin['pulang_cepat']) +
                                                    ($sakit * $poin['sakit']) +
                                                    ($ijin * $poin['ijin']);

                                                // Hitung tidak hadir total
                                                $tidak_hadir = $total_hari_efektif - ($hadir + $sakit + $ijin);
                                                        
                                                // Hitung Alpa (tanpa keterangan)
                                                $alpa = $tidak_hadir; // Karena tidak hadir tanpa keterangan = alpa

                                                // Hitung persentase kehadiran
                                                $persen_kehadiran = $total_hari_efektif > 0 ? round(($hadir / $total_hari_efektif) * 100, 1) : 0;

                                                // Hitung persentase ketidakhadiran
                                                $persen_ketidakhadiran = $total_hari_efektif > 0 ? round(($tidak_hadir / $total_hari_efektif) * 100, 1) : 0;
                                        ?>
                                            <tr>
                                               <!-- Baris pertama -->
                                                <td rowspan="2" style="vertical-align: middle;"><?= $no++ ?></td>
                                                <td rowspan="2" style="vertical-align: middle;"><?= $row['nis'] ?></td>
                                                <td rowspan="2" style="vertical-align: middle;"><?= $row['nama'] ?></td>
                                                
                                                <!-- Baris kedua: Detail Hadir -->
                                                <td style="text-align: center; font-size: 10px; background-color: #e8f6f0;">Tepat<br>
                                                    <?= $tepat_waktu == 0 ? '-' : $tepat_waktu ?>
                                                </td>
                                                <td style="text-align: center; font-size: 10px; background-color: #e8f6f0;">Terlambat <br>
                                                    <?= $terlambat == 0 ? '-' : $terlambat ?>
                                                </td>
                                                
                                                <!-- Baris kedua: Detail Tidak Hadir -->
                                                <td style="text-align: center;  font-size: 10px; background-color: #fceae8;"> Sakit<br>
                                                    <?= $sakit == 0 ? '-' : $sakit ?>
                                                </td>
                                                <td style="text-align: center; font-size: 10px; background-color: #fceae8;">Ijin <br>
                                                    <?= $ijin == 0 ? '-' : $ijin ?>
                                                </td>
                                                <td style="text-align: center; font-size: 10px; background-color: #fceae8;">Alpa <br>
                                                    <?= $alpa == 0 ? '-' : $alpa ?>
                                                </td>      
                                                
                                                <td rowspan="2" style="vertical-align: middle;text-align: center;  "><?= $total_hari_efektif ?></td>
                                                <td rowspan="2" style="text-align: center; vertical-align: middle;"><?= $total_poin ?></td>     

                                            </tr>
                                            <tr style="height: 10px;">
                                               

                                                <!-- Kolom Hadir (baris pertama) -->
                                                <td colspan="2" style="text-align: center; background-color: #e8f6f0; font-weight: bold;">
                                                    <?= $hadir == 0 ? '-' : $hadir." [".$persen_kehadiran." %]"; ?> 
                                                </td>
                                                
                                                <!-- Kolom Tidak Hadir (baris pertama) -->
                                                <td colspan="3" style="text-align: center; background-color: #fceae8; font-weight: bold;">
                                                    <?= ($sakit + $ijin + $alpa) == 0 ? '-' : ($sakit + $ijin + $alpa) ." [".$persen_ketidakhadiran." %]";?>
                                                </td>                                                
                                            </tr>
                                        <?php endwhile; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <p style="text-align: center; padding: 15px; color: #7f8c8d;">Tidak ada data siswa</p>
                        <?php endif; ?>
                        
                       
                    </div>
                </div>
            </div>
            
            <!-- Modal Opsi Cetak -->
            <div id="cetakOptionsModal" class="modal">
                <div class="modal-content" style="max-width: 500px;">
                    <span class="close-modal" onclick="closeCetakOptions()">&times;</span>
                    <h3 class="modal-title"><i class="fas fa-print"></i> Opsi Cetak Laporan</h3>
                    
                    <form id="cetakForm" method="POST" target="_blank">
                        <input type="hidden" name="format" id="cetakFormat">
                        <input type="hidden" name="start_date" value="<?= $start_date ?>">
                        <input type="hidden" name="end_date" value="<?= $end_date ?>">
                        
                        <div class="form-group">
                            <label for="cetak_bulan">Bulan</label>
                            <select id="cetak_bulan" name="bulan" class="form-control" required>
                                <?php
                                $current_month = date('m');
                                $months = [
                                    1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
                                    5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
                                    9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
                                ];
                                
                                foreach ($months as $num => $name) {
                                    $selected = ($num == $current_month) ? 'selected' : '';
                                    echo "<option value='$num' $selected>$name</option>";
                                }
                                ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="cetak_tahun">Tahun</label>
                            <select id="cetak_tahun" name="tahun" class="form-control" required>
                                <?php
                                $current_year = date('Y');
                                for ($i = $current_year - 1; $i <= $current_year + 1; $i++) {
                                    $selected = ($i == $current_year) ? 'selected' : '';
                                    echo "<option value='$i' $selected>$i</option>";
                                }
                                ?>
                            </select>
                        </div>
                        
                        <div style="display: flex; gap: 10px;">
                            <button type="button" onclick="closeCetakOptions()" class="btn-secondary" style="flex: 1;">
                                Batal
                            </button>
                            <button type="submit" class="btn-success" style="flex: 1;">
                                <i class="fas fa-print"></i> Proses Cetak
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Modal untuk CRUD Siswa -->
            <div id="crudModal" class="modal">
                <div class="modal-content">
                    <span class="close-modal" onclick="closeModal()">&times;</span>
                    <div id="modalContent"></div>
                </div>
            </div>
            
            <!-- Modal Edit Presensi -->
            <div id="editPresensiModal" class="modal">
                <div class="modal-content">
                    <span class="close-modal" onclick="closeEditPresensiModal()">&times;</span>
                    <h3 class="modal-title"><i class="fas fa-edit"></i> Edit Data Presensi</h3>
                    <form method="POST" action="index.php?page=admin#tab-presensi" id="editPresensiForm">
                        <input type="hidden" name="id" id="edit_presensi_id">
                        
                        <div class="form-group">
                            <label for="edit_tanggal">Tanggal</label>
                            <input type="date" id="edit_tanggal" name="tanggal" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="edit_jam_masuk">Jam Masuk</label>
                            <input type="time" id="edit_jam_masuk" name="jam_masuk" step="1" onchange="calculateStatus()">
                            <input type="hidden" id="edit_status_masuk" name="status_masuk">
                            <input type="hidden" id="edit_keterangan_terlambat" name="keterangan_terlambat">
                        </div>
                        
                        <div class="form-group">
                            <label for="edit_jam_pulang">Jam Pulang</label>
                            <input type="time" id="edit_jam_pulang" name="jam_pulang" step="1" onchange="calculateStatus()">
                            <input type="hidden" id="edit_status_pulang" name="status_pulang">
                            <input type="hidden" id="edit_keterangan_pulang_cepat" name="keterangan_pulang_cepat">
                        </div>
                        
                        <div class="form-group">
                            <label>Status Masuk</label>
                            <div id="status_masuk_display" style="padding: 8px; background: #f0f0f0; border-radius: 4px;">
                                - Status akan dihitung otomatis -
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label>Status Pulang</label>
                            <div id="status_pulang_display" style="padding: 8px; background: #f0f0f0; border-radius: 4px;">
                                - Status akan dihitung otomatis -
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="edit_catatan">Catatan</label>
                            <textarea id="edit_catatan" name="catatan" rows="3" placeholder="Tambahkan catatan khusus..."></textarea>
                        </div>
                        <button type="submit" name="update_presensi" class="btn-success">Simpan Perubahan</button>
                    </form>
                </div>
            </div>

            <!-- Modal Edit Periode Libur -->
            <div id="editLiburModal" class="modal">
                <div class="modal-content">
                    <span class="close-modal" onclick="closeModal('editLiburModal')">&times;</span>
                    <h3 class="modal-title"><i class="fas fa-edit"></i> Edit Periode Libur</h3>
                    <form method="POST" action="index.php?page=admin#tab-libur" id="editLiburForm">
                        <input type="hidden" name="id" id="edit_libur_id">
                        
                        <div class="form-group">
                            <label for="edit_nama_periode">Nama Periode</label>
                            <input type="text" id="edit_nama_periode" name="nama_periode" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="edit_tanggal_mulai">Tanggal Mulai</label>
                            <input type="date" id="edit_tanggal_mulai" name="tanggal_mulai" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="edit_tanggal_selesai">Tanggal Selesai</label>
                            <input type="date" id="edit_tanggal_selesai" name="tanggal_selesai" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="edit_keterangan_libur">Keterangan</label>
                            <textarea id="edit_keterangan_libur" name="keterangan" rows="3"></textarea>
                        </div>
                        
                        <button type="submit" name="update_periode_libur" class="btn-success">Simpan Perubahan</button>
                    </form>
                </div>
            </div>
        <!-- JAVASCRIPT DISINI AWALNYA --->
        <?php endif; ?>
        
        <?php if ($page == 'admin' && $action == 'edit_siswa' && $nis_edit != ''): ?>
            <!-- Form Edit Siswa -->
            <div class="edit-form">
                <h3 class="modal-title"><i class="fas fa-user-edit"></i> Edit Siswa</h3>
                <form method="POST">
                    <div class="form-group">
                        <label for="edit_nis">NISN</label>
                        <input type="text" id="edit_nis" name="nis" value="<?php echo $siswa_edit['nis']; ?>" readonly>
                    </div>
                    
                    <div class="form-group">
                        <label for="edit_nama">Nama</label>
                        <input type="text" id="edit_nama" name="nama" value="<?php echo $siswa_edit['nama']; ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="edit_password">Password (Kosongkan jika tidak ingin mengubah)</label>
                        <input type="password" id="edit_password" name="password">
                    </div>
                    
                    <div class="form-group">
                        <label for="edit_password_hint">Password Hint</label>
                        <input type="text" id="edit_password_hint" name="password_hint" value="<?php echo $siswa_edit['password_hint'] ?? ''; ?>" required>
                        <h6 style="color:grey;"><i>Di form edit siswa</h6>
                        <small>Pertanyaan/petunjuk untuk reset password</small>
                    </div>


                    <button type="submit" name="save_siswa" class="btn-success">Simpan Perubahan</button>
                </form>
            </div>
        <?php endif; ?>
    </div>
    
    <div class="footer">
        <p>© <?php echo date('Y'); ?> Sistem Presensi Kelas - Bukan Simalas V2.1b  <br/> <h6>Development & Maintenance By Otnamrehus	</h6><h5>masihgurutkj@gmail.com</h5></p>
    </div>
    
    <!-- Loading overlay -->
    <div class="loading-overlay" id="loading-overlay">
        <div class="loading-spinner"></div>
    </div>

    <!-- Modal untuk gambar popup -->
    <div id="imageModal" class="modal" style="display: none; z-index: 2000;">
        <span class="close-modal" onclick="closeImageModal()">&times;</span>
        <img class="modal-content" id="modalImage">
    </div>
    <script>
// Fungsi untuk menghitung status berdasarkan jam
function calculateStatus() {
    // Ambil nilai jam masuk dan pulang
    const jamMasuk = document.getElementById('edit_jam_masuk').value;
    const jamPulang = document.getElementById('edit_jam_pulang').value;
    
    // Ambil pengaturan jam dari PHP
    const jamMasukStandar = "<?php echo $jamMasuk; ?>";
    const jamPulangStandar = "<?php echo $jamPulang; ?>";
    
    // Hitung status masuk
    let statusMasuk = 'tepat waktu';
    let keteranganMasuk = null;
    
    if (jamMasuk) {
        // Perbaikan: Hitung selisih waktu dengan fungsi PHP yang sudah diperbaiki
        const waktuMasuk = jamMasuk;
        const waktuStandarMasuk = jamMasukStandar;
        
        if (waktuMasuk > waktuStandarMasuk) {
            statusMasuk = 'terlambat';
            
            // Gunakan format yang benar untuk perhitungan
            const waktu1 = new Date(`2000-01-01T${waktuStandarMasup}`);
            const waktu2 = new Date(`2000-01-01T${waktuMasuk}`);
            const selisihDetik = (waktu2 - waktu1) / 1000;
            const selisihMenit = Math.round(selisihDetik / 60);
            
            if (selisihMenit >= 60) {
                const jam = Math.floor(selisihMenit / 60);
                const menit = selisihMenit % 60;
                keteranganMasuk = `${jam} Jam ${menit} Menit`;
            } else {
                keteranganMasuk = `${selisihMenit} Menit`;
            }
        }
    }
    
    // Hitung status pulang
    let statusPulang = 'tepat waktu';
    let keteranganPulang = null;
    
    if (jamPulang) {
        const waktuPulang = jamPulang;
        const waktuStandarPulang = "<?php echo $jamPulang; ?>";
        
        if (waktuPulang < waktuStandarPulang) {
            statusPulang = 'cepat';
            
            // Hitung selisih waktu
            const time1 = waktuStandarPulang.split(':');
            const time2 = waktuPulang.split(':');
            const hours1 = parseInt(time1[0]);
            const minutes1 = parseInt(time1[1]);
            const hours2 = parseInt(time2[0]);
            const minutes2 = parseInt(time2[1]);
            
            let jam = hours1 - hours2;
            let menit = minutes1 - minutes2;
            
            if (menit < 0) {
                jam--;
                menit += 60;
            }
            
            keteranganPulang = jam > 0 ? `${jam} Jam ${menit} Menit` : `${menit} Menit`;
        }
    }
    
    // Set nilai hidden fields
    document.getElementById('edit_status_masuk').value = statusMasuk;
    document.getElementById('edit_keterangan_terlambat').value = keteranganMasuk || '';
    document.getElementById('edit_status_pulang').value = statusPulang;
    document.getElementById('edit_keterangan_pulang_cepat').value = keteranganPulang || '';
    
    // Update tampilan
    document.getElementById('status_masuk_display').innerHTML = 
        `<b>${statusMasuk.toUpperCase()}</b>` + 
        (keteranganMasuk ? `<br>${keteranganMasuk}` : '');
    /*
    document.getElementById('status_pulang_display').innerHTML = 
        `<b>${statusPulang.toUpperCase()}</b>` + 
        (keteranganPulang ? `<br>${keteranganPulang}` : '');
        */

        document.getElementById('status_pulang_display').innerHTML = statusPulang === 'tepat waktu' ? 
        '<span class="status-tepat">Tepat Waktu</span>' : 
        '<span class="status-cepat">Pulang Cepat (' + keteranganPulang + ')</span>';
}

// Panggil fungsi saat modal dibuka
function openEditPresensiModal(id) {
    fetch('index.php?page=admin&action=get_presensi&id=' + id)
        .then(response => response.json())
        .then(data => {
            // Isi form
            document.getElementById('edit_presensi_id').value = data.id;
            document.getElementById('edit_tanggal').value = data.tanggal;
            document.getElementById('edit_jam_masuk').value = data.jam_masuk;
            document.getElementById('edit_jam_pulang').value = data.jam_pulang;
            
            // Hitung status awal
            calculateStatus();
            
            // Tampilkan modal
            document.getElementById('editPresensiModal').style.display = 'block';
        });
}

                // Tab switching
                const tabs = document.querySelectorAll('.nav-tabs a');
                const tabContents = document.querySelectorAll('.tab-content');
                
                tabs.forEach(tab => {
                    tab.addEventListener('click', (e) => {
                        e.preventDefault();
                        const target = tab.getAttribute('href').substring(1);
                        
                        // Remove active class from all tabs and contents
                        tabs.forEach(t => t.classList.remove('active'));
                        tabContents.forEach(tc => tc.classList.remove('active'));
                        
                        // Add active class to current tab and content
                        tab.classList.add('active');
                        document.getElementById(target).classList.add('active');
                        
                        // Update URL hash
                        window.location.hash = target;
                    });
                });
                
                // Check hash on page load
                if (window.location.hash) {
                    const targetTab = document.querySelector(`.nav-tabs a[href="${window.location.hash}"]`);
                    if (targetTab) {
                        tabs.forEach(t => t.classList.remove('active'));
                        tabContents.forEach(tc => tc.classList.remove('active'));
                        
                        targetTab.classList.add('active');
                        document.querySelector(targetTab.getAttribute('href')).classList.add('active');
                    }
                }
                
                // Modal functions
                function openModal(action, event, nis = '', nama = '') {
                    // Hentikan event jika ada
                    if (event) {
                        event.preventDefault();
                        event.stopPropagation();
                    }

                    const modal = document.getElementById('crudModal');
                    const modalContent = document.getElementById('modalContent');
                    
                    if (action === 'add') {
                        modalContent.innerHTML = `
                            <h3 class="modal-title"><i class="fas fa-user-plus"></i> Tambah Siswa Baru</h3>
                            <form method="POST">
                                <div class="form-group">
                                    <label for="add_nis">NISN</label>
                                    <input type="text" id="add_nis" name="nis" required>
                                </div>
                                
                                <div class="form-group">
                                    <label for="add_nama">Nama</label>
                                    <input type="text" id="add_nama" name="nama" required>
                                </div>
                                
                                <div class="form-group">
                                    <label for="add_password">Password</label>
                                    <input type="password" id="add_password" name="password" required>
                                </div>
                                                  
                                <div class="form-group">
                                    <label for="add_password_hint">Password Hint</label>
                                    <input type="text" id="add_password_hint" name="password_hint" required placeholder="Contoh: Tokoh favorit">
                                    <small>Pertanyaan/petunjuk untuk reset password</small>
                                </div>

                                
                                <button type="submit" name="add_siswa" class="btn-success">Simpan</button>
                            </form>
                        `;
                    } else if (action === 'edit') {
                        // AJAX untuk ambil data siswa
                        fetch(`index.php?page=admin&action=edit_siswa&nis=${nis}`)
                            .then(response => response.text())
                            .then(data => {
                                modalContent.innerHTML = `
                                    <div class="edit-form">
                                        <h3 class="modal-title"><i class="fas fa-user-edit"></i> Edit Siswa</h3>
                                        ${data}
                                    </div>
                                `;
                            })
                            .catch(error => {
                                console.error('Error:', error);
                                modalContent.innerHTML = '<div class="error">Terjadi kesalahan saat mengambil data siswa</div>';
                            });
                    } else if (action === 'delete') {
                        modalContent.innerHTML = `
                            <div class="delete-confirm">
                                <h3 class="modal-title"><i class="fas fa-trash"></i> Hapus Siswa</h3>
                                <p>Apakah Anda yakin ingin menghapus siswa: <strong>${nama} (${nis})</strong>?</p>
                                <form method="POST">
                                    <input type="hidden" name="nis" value="${nis}">
                                    <div class="btn-group">
                                        <button type="button" class="btn-secondary" onclick="closeModal()">Batal</button>
                                        <button type="submit" name="delete_siswa" class="btn-danger">Hapus</button>
                                    </div>
                                </form>
                            </div>
                        `;
                    }
                    
                    modal.style.display = 'block';
                }
                
                function closeModal() {
                    document.getElementById('crudModal').style.display = 'none';
                }
                
                // Close modal when clicking outside
                window.onclick = function(event) {
                    const modal = document.getElementById('crudModal');
                    if (event.target == modal) {
                        closeModal();
                    }
                };

                // Fungsi untuk membuka modal edit presensi
                function openEditPresensiModal(id) {
                    fetch('index.php?page=admin&action=get_presensi&id=' + id)
                        .then(response => response.json())
                        .then(data => {
                            if (data.error) {
                                alert(data.error);
                            } else {
                                // Isi form
                                document.getElementById('edit_presensi_id').value = data.id;
                                document.getElementById('edit_tanggal').value = data.tanggal;
                                document.getElementById('edit_jam_masuk').value = formatTimeForInput(data.jam_masuk);
                                document.getElementById('edit_jam_pulang').value = formatTimeForInput(data.jam_pulang);
                                document.getElementById('edit_catatan').value = data.catatan || '';
                                // Tampilkan modal
                                document.getElementById('editPresensiModal').style.display = 'block';
                            }
                        })
                        .catch(error => {
                            console.error('Error:', error);
                            alert('Terjadi kesalahan saat mengambil data presensi');
                        });
                }
                function closeModal(modalId) {
                    document.getElementById(modalId).style.display = 'none';
                }

                // Fungsi untuk menutup modal edit presensi
                function closeEditPresensiModal() {
                    document.getElementById('editPresensiModal').style.display = 'none';
                }
                function formatTimeForInput(timeStr) {
                    if (!timeStr) return '';
                    const parts = timeStr.split(':');
                    if (parts.length < 2) return '';
                    
                    // Pastikan semua bagian memiliki 2 digit
                    return parts.map(part => part.padStart(2, '0')).join(':');
                    }


                    function disableSubmitButton() {
                        const btn = document.getElementById('btn-ajukan');
                        const loading = document.getElementById('loading-izin');
                        
                        // Nonaktifkan tombol dan tampilkan loading
                        btn.disabled = true;
                        loading.style.display = 'block';
                        
                        // Ganti teks tombol
                        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Mengirim...';
                    }

                    // Handler untuk mencegah form resubmit saat refresh halaman
                    if (window.history.replaceState) {
                        window.history.replaceState(null, null, window.location.href);
                    }

                    // Modal untuk gambar popup
                    function showImageModal(src) {
                        const modal = document.getElementById('imageModal');
                        const modalImg = document.getElementById('modalImage');
                        modal.style.display = 'block';
                        modalImg.src = src;
                        
                        // Tambahkan efek zoom-in
                        modalImg.style.transform = 'scale(0.95)';
                        setTimeout(() => {
                            modalImg.style.transform = 'scale(1)';
                            modalImg.style.transition = 'transform 0.3s ease-out';
                        }, 10);
                    }

                    function closeImageModal() {
                        document.getElementById('imageModal').style.display = 'none';
                    }

                    // Tutup modal jika klik di luar gambar
                    window.addEventListener('click', function(event) {
                        const modal = document.getElementById('imageModal');
                        if (event.target === modal) {
                            closeImageModal();
                        }
                    });

			/*  PERLU DIHAPUS */
            document.getElementById('deletePresensiForm').addEventListener('submit', function(e) {
			    const checked = this.querySelectorAll('input[type="checkbox"]:checked').length;
			    if (checked === 0) {
				  alert('Apakah anda yakin dengan data ini?');
				  e.preventDefault();
				  return;
			    }
			    if (!confirm(`Apakah Anda yakin ingin menghapus ${checked} data terpilih?`)) {
				  e.preventDefault();
			    }
			});
            

            function printRekapHarian() {
                // Clone the printable content
                var printContents = document.getElementById('printable-rekap').innerHTML;
                
                // Create a new window
                var originalContents = document.body.innerHTML;
                var printWindow = window.open('', '', 'width=800,height=600');
                
                // Write the print content
                printWindow.document.write(`
                    <!DOCTYPE html>
                    <html>
                    <head>
                        <title>Rekap Presensi Harian</title>
                        <style>
                            @page {
                                size: A4 portrait;
                                margin: 1cm;
                            }
                            body {
                                font-family: Arial, sans-serif;
                                margin: 0;
                                padding: 0;
                            }
                            table {
                                width: 100%;
                                border-collapse: collapse;
                                margin-bottom: 20px;
                            }
                            th, td {
                                border: 1px solid #000;
                                padding: 8px;
                                text-align: left;
                            }
                            th {
                                background-color: #f2f2f2;
                                text-align: center;
                            }
                            .text-center {
                                text-align: center;
                            }
                            .signature {
                                margin-top: 50px;
                            }
                        </style>
                    </head>
                    <body>
                        ${printContents}
                        <script>
                            window.onload = function() {
                                window.print();
                                window.onafterprint = function() {
                                    window.close();
                                };
                            };
                        <\/script>
                    </body>
                    </html>
                `);
                
                printWindow.document.close();
            }
            // Nonaktifkan klik tombol pulang jika belum presensi masuk
            document.querySelectorAll('.presensi-option.disabled').forEach(option => {
                option.addEventListener('click', function(e) {
                    e.preventDefault(); // Menghentikan aksi default
                    return false;
                });
            });

            // Face Detection Presensi dengan ini:
            // Variabel global
            let isFaceDetected = false;
            const faceOptions = new faceapi.TinyFaceDetectorOptions({ 
            inputSize: 224, 
            scoreThreshold: 0.5 
            });

            // 1. Load model saat halaman siap
            document.addEventListener('DOMContentLoaded', async () => {
            try {
                await loadModels();
                startDetection();
            } catch (error) {
                console.error("Error:", error);
                document.getElementById('face-status').innerHTML = 
                '<i class="fas fa-exclamation-triangle"></i> Fitur deteksi wajah tidak tersedia';
            }
            });

            // 2. Fungsi load model
            async function loadModels() {
            const modelPath = 'https://justadudewhohacks.github.io/face-api.js/models';
            await faceapi.nets.tinyFaceDetector.loadFromUri(modelPath);
            await faceapi.nets.faceLandmark68Net.loadFromUri(modelPath);
            }

            // 3. Fungsi deteksi wajah
            function startDetection() {
            const video = document.getElementById('video');
            const canvas = document.getElementById('canvas');
            const statusEl = document.getElementById('face-status');
            const btnSubmit = document.getElementById('btn-submit');
            
            setInterval(async () => {
                if (video.readyState === video.HAVE_ENOUGH_DATA) {
                const detections = await faceapi.detectAllFaces(video, faceOptions);
                
                if (detections.length > 0) {
                    isFaceDetected = true;
                    btnSubmit.disabled = false;
                    statusEl.innerHTML = '<i class="fas fa-user-check" style="color:green"></i> Wajah terdeteksi!';
                    statusEl.style.background = '#e8f5e9';
                } else {
                    isFaceDetected = false;
                    btnSubmit.disabled = true;
                    statusEl.innerHTML = '<i class="fas fa-user-slash" style="color:red"></i> Arahkan wajah ke kamera';
                    statusEl.style.background = '#ffebee';
                }
                }
            }, 1000); // Deteksi setiap 1 detik
            }

            // 4. Validasi sebelum submit
            document.getElementById('presensi-form').addEventListener('submit', function(e) {
            if (!isFaceDetected) {
                e.preventDefault();
                alert('Wajah tidak terdeteksi! Pastikan wajah Anda terlihat jelas di kamera.');
            }
            });

		// Check for expired session
		function checkSessionExpired() {
		    // Check URL for session_expired parameter
		    const urlParams = new URLSearchParams(window.location.search);
		    if (urlParams.get('session_expired') === '1') {
			  alert('Waktu sesi Anda telah habis. Silakan login kembali.');
			  window.location.href = 'index.php?page=login'; // Remove the parameter from URL
		    }
		    
		    // Inactivity timer (5 minutes)
		    let inactivityTimer;
		    const resetTimer = () => {
			  clearTimeout(inactivityTimer);
			  inactivityTimer = setTimeout(() => {
				alert('Anda telah tidak aktif selama 5 menit. Sesi akan diakhiri.');
				window.location.href = 'index.php?page=logout';
			  }, <?php echo $session_timeout * 1000; ?>); // Convert to milliseconds
		    };
		    
		    // Reset timer on these events
		    window.onload = resetTimer;
		    window.onmousemove = resetTimer;
		    window.onmousedown = resetTimer;
		    window.ontouchstart = resetTimer;
		    window.onclick = resetTimer;
		    window.onkeypress = resetTimer;
		    window.onkeydown = resetTimer;
		    window.onkeyup = resetTimer;
		    window.onfocus = resetTimer;
		}

		// Run the check when page loads
		document.addEventListener('DOMContentLoaded', checkSessionExpired);

        //Cetak Halaman Status Presensi Siswa
        function printRekapStatus() {
            const printContents = document.getElementById('printable-status-rekap').innerHTML;
            const originalContents = document.body.innerHTML;
            
            document.body.innerHTML = printContents;
            window.print();
            document.body.innerHTML = originalContents;
            window.location.reload();
        }
        document.getElementById('form-izin').addEventListener('submit', function() {
            document.getElementById('btn-ajukan').disabled = true;
            document.getElementById('loading-izin').style.display = 'block';
        });     
    </script>
    <script>
        // LIBRARY UNTUK MENU BOTTOM ADMIN
        // Fungsi untuk mengaktifkan tab
        function activateTab(tabId) {
            // Sembunyikan semua tab content
            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.classList.remove('active');
            });
            
            // Tampilkan tab yang dipilih
            document.querySelector(tabId).classList.add('active');
            
            // Perbarui menu bottom
            document.querySelectorAll('.bottom-menu .menu-item').forEach(item => {
                item.classList.remove('active');
            });
            document.querySelector(`.bottom-menu a[href="${tabId}"]`).classList.add('active');
            
            // Scroll ke tab
            document.querySelector(tabId).scrollIntoView({behavior: 'smooth'});
        }

        // Event listener untuk menu bottom
        document.querySelectorAll('.bottom-menu .menu-item').forEach(item => {
            item.addEventListener('click', function(e) {
                e.preventDefault();
                const tabId = this.getAttribute('href');
                activateTab(tabId);
                window.location.hash = tabId;
            });
        });

        // Aktifkan tab berdasarkan URL hash saat halaman dimuat
        document.addEventListener('DOMContentLoaded', function() {
            const hash = window.location.hash;
            if (hash) {
                activateTab(hash);
            } else {
                // Aktifkan tab default
                activateTab('#tab-presensi');
            }

            var filterForm = document.getElementById('filterIzinForm');
            if (filterForm) {
                // Clone form untuk menghapus event listener lama
                var newFilterForm = filterForm.cloneNode(true);
                filterForm.parentNode.replaceChild(newFilterForm, filterForm);
                
                // Tambahkan event listener baru yang sederhana
                newFilterForm.addEventListener('submit', function() {
                    // Biarkan form melakukan submit normal
                    return true;
                });
            }
        });

        // Tangani perubahan hash URL
        window.addEventListener('hashchange', function() {
            const hash = window.location.hash;
            if (hash) {
                activateTab(hash);
            }
        });

        // Fungsi untuk membuka modal edit periode libur
        function openEditLiburModal(id) {
            fetch('index.php?page=admin&action=get_periode_libur&id=' + id)
                .then(response => response.json())
                .then(data => {
                    if (data.error) {
                        alert(data.error);
                    } else {
                        // Isi form
                        document.getElementById('edit_libur_id').value = data.id;
                        document.getElementById('edit_nama_periode').value = data.nama_periode;
                        document.getElementById('edit_tanggal_mulai').value = data.tanggal_mulai;
                        document.getElementById('edit_tanggal_selesai').value = data.tanggal_selesai;
                        document.getElementById('edit_keterangan_libur').value = data.keterangan || '';
                        
                        // Tampilkan modal
                        document.getElementById('editLiburModal').style.display = 'block';
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Terjadi kesalahan saat mengambil data periode libur');
                });
        }

        // Fungsi untuk menutup modal
        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }

        // Tutup modal jika klik di luar modal
        window.addEventListener('click', function(event) {
            const modal = document.getElementById('editLiburModal');
            if (event.target === modal) {
                closeModal('editLiburModal');
            }
        });

        function openEditIzinModal(id) {
            fetch('index.php?page=admin&action=get_izin&id=' + id)
                .then(response => response.json())
                .then(data => {
                    // Isi form edit izin di modal
                    // (Anda perlu membuat modal untuk edit izin)
                    console.log('Data izin:', data);
                    alert('Fitur edit izin akan ditambahkan di sini');
                });
        }
        // Fungsi untuk menampilkan modal opsi cetak
        function showCetakOptions(format) {
            document.getElementById('cetakFormat').value = format;
            document.getElementById('cetakOptionsModal').style.display = 'block';
        }

        // Fungsi untuk menutup modal opsi cetak
        function closeCetakOptions() {
            document.getElementById('cetakOptionsModal').style.display = 'none';
        }

        // Atur action form berdasarkan format
        document.getElementById('cetakForm').addEventListener('submit', function(e) {
            const format = document.getElementById('cetakFormat').value;
            this.action = 'cetak_rekap.php?format=' + format;
        });

        // Tutup modal jika klik di luar area modal
        window.addEventListener('click', function(event) {
            const modal = document.getElementById('cetakOptionsModal');
            if (event.target === modal) {
                closeCetakOptions();
            }
        });
        </script>
     <?php if (isset($_SESSION['nis']) && $page != 'login' && $page != 'reset_password' && !isset($_SESSION['admin'])): ?>
        <?php $page = isset($_GET['page']) ? $_GET['page'] : 'presensi'; ?>

        <div class="bottom-menu">
            <a href="index.php?page=menu" class="menu-item <?php echo ($page == 'menu') ? 'active' : ''; ?>">
                <i class="fas fa-home"></i>
                <span>Beranda</span>
            </a>
            <a href="index.php?page=izin" class="menu-item <?php echo ($page == 'izin') ? 'active' : ''; ?>">
                <i class="fas fa-check-double"></i>
                <span>Izin</span>
            </a>
            <a href="index.php?page=presensi" class="menu-item <?php echo ($page == 'presensi') ? 'active' : ''; ?>">
                <i class="fas fa-camera"></i>
                <span>Presensi</span>
            </a>
            <a href="index.php?page=status_presensi" class="menu-item <?php echo ($page == 'status_presensi') ? 'active' : ''; ?>">
                <i class="fas fa-chart-area"></i>
                <span>Status</span>
            </a>
            <a href="index.php?page=rekap_presensi" class="menu-item <?php echo ($page == 'rekap_presensi') ? 'active' : ''; ?>">
                <i class="fas fa-chart-line"></i>
                <span>Laporan</span>
            </a>
        </div>
    <?php endif; ?>

     <!-- MENU BOTTOM ADMIN-->
    <?php if (isset($_SESSION['admin']) && $page == 'admin'): ?>
        <div class="bottom-menu">
            <a href="#tab-presensi" class="menu-item active">
                <i class="fas fa-map-marked-alt"></i>
                <span>Presensi</span>
            </a>
            <a href="#tab-pengajuan-bulanan" class="menu-item">
                <i class="fas fa-list"></i>
                <span>Izin</span>
            </a>
            <a href="#tab-rekap-harian" class="menu-item">
                <i class="fas fa-calendar-day"></i>
                <span>Harian</span>
            </a>
            <a href="#tab-rekap" class="menu-item">
                <i class="fas fa-book"></i>
                <span>Rekap</span>
            </a>
            <a href="#tab-status" class="menu-item">
                <i class="fas fa-chart-pie"></i>
                <span>Status</span>
            </a>
            <a href="#tab-siswa" class="menu-item">
                <i class="fas fa-users"></i>
                <span>Siswa</span>
            </a>
            <a href="#tab-pengaturan" class="menu-item">
                <i class="fas fa-cog"></i>
                <span>Setting</span>
            </a>
            <a href="#tab-libur" class="menu-item">
                <i class="fas fa-calendar-times"></i>
                <span>Libur</span>
            </a>
        </div>
        <?php endif; ?>

        
</body>
</html>
