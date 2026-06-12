<?php
session_start();
require 'config/db_connect.php';

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: index.php");
    exit;
}

// Halaman Master hanya boleh diakses admin.
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: dashboard.php");
    exit;
}

$id_user_login = (int) $_SESSION['id'];
$nama_login = $_SESSION['username'] ?? 'Admin';
$role_login = $_SESSION['role'] ?? 'admin';
$is_admin = true;

$status = '';
$message = '';

// Membuat kode barang otomatis dengan format BRG-001, BRG-002, dan seterusnya.
function buatKodeBarangOtomatis($conn) {
    $sql = "SELECT kode_barang FROM barang WHERE kode_barang LIKE 'BRG-%' ORDER BY CAST(SUBSTRING(kode_barang, 5) AS UNSIGNED) DESC LIMIT 1";
    $result = $conn->query($sql);
    $nomorTerakhir = 0;

    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $nomorTerakhir = (int) str_replace('BRG-', '', $row['kode_barang']);
    }

    return 'BRG-' . str_pad($nomorTerakhir + 1, 3, '0', STR_PAD_LEFT);
}

// Flash message setelah proses redirect (hapus/restore/hapus permanen)
if (isset($_SESSION['flash_status'], $_SESSION['flash_message'])) {
    $status = $_SESSION['flash_status'];
    $message = $_SESSION['flash_message'];
    unset($_SESSION['flash_status'], $_SESSION['flash_message']);
}

// Mode Tampilan Data: Aktif (0) atau Terhapus/Trash (1)
$view_status = isset($_GET['status']) && $_GET['status'] == 'trash' ? 1 : 0;
// Simpan state tab aktif agar tidak reset saat pindah halaman/proses form
$active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'kategori';
if (!in_array($active_tab, ['kategori', 'barang', 'pengguna'])) {
    $active_tab = 'kategori';
}

// ==========================================
// 1. LOGIKA CRUD (HANYA UNTUK ROLE ADMIN)
// ==========================================
if ($_SERVER["REQUEST_METHOD"] == "POST" && $is_admin) {

    // --- TAMBAH DATA ---
    if (isset($_POST['tambah_kategori'])) {
        $nama_kategori = trim($_POST['nama_kategori']);
        $deskripsi = trim($_POST['deskripsi']);

        $stmt_check = $conn->prepare("SELECT id FROM kategori_barang WHERE nama_kategori = ? AND is_delete = 0");
        $stmt_check->bind_param("s", $nama_kategori);
        $stmt_check->execute();
        if ($stmt_check->get_result()->num_rows > 0) {
            $status = 'error'; $message = 'Kategori sudah ada!';
        } else {
            $stmt_in = $conn->prepare("INSERT INTO kategori_barang (nama_kategori, deskripsi) VALUES (?, ?)");
            $stmt_in->bind_param("ss", $nama_kategori, $deskripsi);
            if ($stmt_in->execute()) { $status = 'success'; $message = 'Kategori berhasil ditambahkan!'; }
            $stmt_in->close();
        }
        $stmt_check->close();
    }

    if (isset($_POST['tambah_barang'])) {
        $id_kategori = (int) $_POST['id_kategori'];
        $kode_barang = buatKodeBarangOtomatis($conn);
        $nama_barang = trim($_POST['nama_barang']);
        $stok = (int)$_POST['stok'];
        $satuan = trim($_POST['satuan']);
        $deskripsi = trim($_POST['deskripsi_barang']);

        if ($id_kategori <= 0 || $nama_barang == '') {
            $status = 'error';
            $message = 'Kategori dan nama barang wajib diisi!';
        } else {
            // Query tambah barang. Kode barang tidak diinput manual, tetapi dibuat otomatis oleh sistem.
            $stmt_in = $conn->prepare("INSERT INTO barang (id_kategori, kode_barang, nama_barang, deskripsi, stok, satuan) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt_in->bind_param("isssis", $id_kategori, $kode_barang, $nama_barang, $deskripsi, $stok, $satuan);

            if ($stmt_in->execute()) {
                $status = 'success';
                $message = 'Data barang berhasil ditambahkan dengan kode ' . $kode_barang . '!';
            } else {
                $status = 'error';
                $message = 'Data barang gagal ditambahkan.';
            }

            $stmt_in->close();
        }
    }

    if (isset($_POST['tambah_pengguna'])) {
        $nama = trim($_POST['nama']);
        $email = trim($_POST['email']);
        $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
        $role = in_array(($_POST['role'] ?? 'user'), ['admin', 'user']) ? $_POST['role'] : 'user';

        $stmt_check = $conn->prepare("SELECT id FROM users WHERE email = ? AND is_delete = 0");
        $stmt_check->bind_param("s", $email);
        $stmt_check->execute();
        if ($stmt_check->get_result()->num_rows > 0) {
            $status = 'error'; $message = 'Email sudah terdaftar!';
        } else {
            $stmt_in = $conn->prepare("INSERT INTO users (nama, email, password_hash, role) VALUES (?, ?, ?, ?)");
            $stmt_in->bind_param("ssss", $nama, $email, $password, $role);
            if ($stmt_in->execute()) { $status = 'success'; $message = 'Pengguna berhasil ditambahkan!'; }
            $stmt_in->close();
        }
        $stmt_check->close();
    }

    // --- EDIT DATA ---
    if (isset($_POST['edit_kategori'])) {
        $id = $_POST['id_kategori_edit'];
        $nama = trim($_POST['nama_kategori']);
        $deskripsi = trim($_POST['deskripsi']);
        $stmt_upd = $conn->prepare("UPDATE kategori_barang SET nama_kategori=?, deskripsi=? WHERE id=?");
        $stmt_upd->bind_param("ssi", $nama, $deskripsi, $id);
        if ($stmt_upd->execute()) { $status = 'success'; $message = 'Kategori berhasil diperbarui!'; }
        $stmt_upd->close();
    }

    if (isset($_POST['edit_barang'])) {
        $id = (int) $_POST['id_barang_edit'];
        $id_kat = (int) $_POST['id_kategori'];
        $nama = trim($_POST['nama_barang']);
        $stok = (int)$_POST['stok'];
        $satuan = trim($_POST['satuan']);
        $deskripsi = trim($_POST['deskripsi_barang']);

        // Query edit barang. Kode barang tidak diedit agar nomor barang tetap konsisten.
        $stmt_upd = $conn->prepare("UPDATE barang SET id_kategori=?, nama_barang=?, deskripsi=?, stok=?, satuan=? WHERE id=?");
        $stmt_upd->bind_param("issisi", $id_kat, $nama, $deskripsi, $stok, $satuan, $id);
        if ($stmt_upd->execute()) { $status = 'success'; $message = 'Data barang berhasil diperbarui!'; }
        $stmt_upd->close();
    }

    if (isset($_POST['edit_pengguna'])) {
        $id = $_POST['id_pengguna_edit'];
        $nama = trim($_POST['nama']);
        $email = trim($_POST['email']);
        $role = in_array(($_POST['role'] ?? 'user'), ['admin', 'user']) ? $_POST['role'] : 'user';

        if (!empty($_POST['password'])) {
            $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
            $stmt_upd = $conn->prepare("UPDATE users SET nama=?, email=?, password_hash=?, role=? WHERE id=?");
            $stmt_upd->bind_param("ssssi", $nama, $email, $password, $role, $id);
        } else {
            $stmt_upd = $conn->prepare("UPDATE users SET nama=?, email=?, role=? WHERE id=?");
            $stmt_upd->bind_param("sssi", $nama, $email, $role, $id);
        }
        if ($stmt_upd->execute()) { $status = 'success'; $message = 'Pengguna berhasil diperbarui!'; }
        $stmt_upd->close();
    }

    // --- SOFT DELETE DATA ---
    if (isset($_POST['delete_data']) && $_POST['delete_data'] == '1') {
        $id = isset($_POST['delete_id']) ? (int) $_POST['delete_id'] : 0;
        $tipe = $_POST['delete_type'] ?? '';

        $can_delete = true;
        $table = '';

        if ($id <= 0 || !in_array($tipe, ['kategori', 'barang', 'pengguna'])) {
            $status = 'error';
            $message = 'Data tidak valid.';
            $can_delete = false;
        }

        if ($can_delete && $tipe == 'kategori') {
            // Kategori tidak boleh dihapus kalau masih punya barang aktif
            $stmt_check = $conn->prepare("
                SELECT id
                FROM barang
                WHERE id_kategori = ?
                AND is_delete = 0
                LIMIT 1
            ");
            $stmt_check->bind_param("i", $id);
            $stmt_check->execute();
            $result_check = $stmt_check->get_result();

            if ($result_check->num_rows > 0) {
                $can_delete = false;
                $status = 'error';
                $message = 'Gagal! Kategori masih memiliki barang aktif. Hapus atau pindahkan barangnya terlebih dahulu.';
            }

            $stmt_check->close();
            $table = 'kategori_barang';
        }

        if ($can_delete && $tipe == 'barang') {
            // Barang boleh dipindahkan ke Sampah meskipun sudah punya riwayat transaksi.
            // Alasannya: ini hanya soft delete, sehingga data transaksi lama tetap aman untuk laporan.
            // Hapus permanen tetap dibatasi pada bagian force delete karena ada relasi detail_transaksi.
            $table = 'barang';
        }

        if ($can_delete && $tipe == 'pengguna') {
            // User tidak boleh hapus akun sendiri
            if ($id == $id_user_login) {
                $can_delete = false;
                $status = 'error';
                $message = 'Gagal! Akun yang sedang digunakan tidak boleh dihapus.';
            } else {
                // User tidak boleh dihapus kalau punya transaksi aktif
                $stmt_check = $conn->prepare("
                    SELECT id
                    FROM transaksi
                    WHERE id_user = ?
                    AND is_delete = 0
                    LIMIT 1
                ");
                $stmt_check->bind_param("i", $id);
                $stmt_check->execute();
                $result_check = $stmt_check->get_result();

                if ($result_check->num_rows > 0) {
                    $can_delete = false;
                    $status = 'error';
                    $message = 'Gagal! Pengguna masih memiliki transaksi aktif.';
                }

                $stmt_check->close();
            }

            $table = 'users';
        }

        if ($can_delete && $table != '') {
            $stmt_del = $conn->prepare("UPDATE $table SET is_delete = 1 WHERE id = ? AND is_delete = 0");
            $stmt_del->bind_param("i", $id);
            $stmt_del->execute();

            if ($stmt_del->affected_rows > 0) {
                $status = 'success';
                $message = 'Data berhasil dipindahkan ke Sampah!';
            } else {
                $status = 'error';
                $message = 'Data gagal dipindahkan atau sudah berada di Sampah.';
            }

            $stmt_del->close();
        }
    }

    // --- RESTORE DATA ---
    if (isset($_POST['restore_data']) && $_POST['restore_data'] == '1') {
        $id = isset($_POST['restore_id']) ? (int) $_POST['restore_id'] : 0;
        $tipe = $_POST['restore_type'] ?? '';

        $can_restore = true;
        $table = '';

        if ($id <= 0 || !in_array($tipe, ['kategori', 'barang', 'pengguna'])) {
            $status = 'error';
            $message = 'Data tidak valid.';
            $can_restore = false;
        }

        if ($can_restore && $tipe == 'kategori') {
            $table = 'kategori_barang';
        }

        if ($can_restore && $tipe == 'barang') {
            // Barang tidak boleh direstore kalau kategori parent-nya masih di sampah
            $stmt_check = $conn->prepare("
                SELECT b.id, k.is_delete AS kategori_terhapus
                FROM barang b
                JOIN kategori_barang k ON b.id_kategori = k.id
                WHERE b.id = ?
                LIMIT 1
            ");
            $stmt_check->bind_param("i", $id);
            $stmt_check->execute();
            $result_check = $stmt_check->get_result();

            if ($result_check->num_rows == 0) {
                $can_restore = false;
                $status = 'error';
                $message = 'Data barang tidak ditemukan.';
            } else {
                $row_check = $result_check->fetch_assoc();

                if ((int) $row_check['kategori_terhapus'] === 1) {
                    $can_restore = false;
                    $status = 'error';
                    $message = 'Gagal restore! Kategori dari barang ini masih berada di Sampah. Restore kategorinya terlebih dahulu.';
                }
            }

            $stmt_check->close();
            $table = 'barang';
        }

        if ($can_restore && $tipe == 'pengguna') {
            $table = 'users';
        }

        if ($can_restore && $table != '') {
            $stmt_res = $conn->prepare("UPDATE $table SET is_delete = 0 WHERE id = ? AND is_delete = 1");
            $stmt_res->bind_param("i", $id);
            $stmt_res->execute();

            if ($stmt_res->affected_rows > 0) {
                $status = 'success';
                $message = 'Data berhasil direstore!';
            } else {
                $status = 'error';
                $message = 'Data gagal direstore atau data sudah aktif.';
            }

            $stmt_res->close();
        }
    }

    // --- HAPUS TOTAL (PERMANEN) ---
    if (isset($_POST['force_delete_data']) && $_POST['force_delete_data'] == '1') {
        $id = isset($_POST['force_id']) ? (int) $_POST['force_id'] : 0;
        $tipe = $_POST['force_type'] ?? '';

        $can_force_delete = true;
        $table = '';

        if ($id <= 0 || !in_array($tipe, ['kategori', 'barang', 'pengguna'])) {
            $status = 'error';
            $message = 'Data tidak valid.';
            $can_force_delete = false;
        }

        if ($can_force_delete && $tipe == 'kategori') {
            // Kategori tidak boleh hapus permanen kalau masih punya barang, aktif maupun sampah
            $stmt_check = $conn->prepare("
                SELECT id
                FROM barang
                WHERE id_kategori = ?
                LIMIT 1
            ");
            $stmt_check->bind_param("i", $id);
            $stmt_check->execute();

            if ($stmt_check->get_result()->num_rows > 0) {
                $can_force_delete = false;
                $status = 'error';
                $message = 'Gagal hapus permanen! Kategori masih memiliki data barang.';
            }

            $stmt_check->close();
            $table = 'kategori_barang';
        }

        if ($can_force_delete && $tipe == 'barang') {
            // Barang boleh dihapus permanen walaupun sudah memiliki transaksi.
            // Riwayat transaksi tetap aman karena detail_transaksi menyimpan snapshot barang,
            // dan foreign key id_barang sudah diatur ON DELETE SET NULL melalui migration SQL.
            $table = 'barang';
        }

        if ($can_force_delete && $tipe == 'pengguna') {
            if ($id == $id_user_login) {
                $can_force_delete = false;
                $status = 'error';
                $message = 'Gagal! Akun yang sedang digunakan tidak boleh dihapus permanen.';
            } else {
                // User tidak boleh hapus permanen kalau punya transaksi
                $stmt_check = $conn->prepare("
                    SELECT id
                    FROM transaksi
                    WHERE id_user = ?
                    LIMIT 1
                ");
                $stmt_check->bind_param("i", $id);
                $stmt_check->execute();

                if ($stmt_check->get_result()->num_rows > 0) {
                    $can_force_delete = false;
                    $status = 'error';
                    $message = 'Gagal hapus permanen! Pengguna masih memiliki transaksi.';
                }

                $stmt_check->close();
            }

            $table = 'users';
        }

        if ($can_force_delete && $table != '') {
            $stmt_fdel = $conn->prepare("DELETE FROM $table WHERE id = ?");
            $stmt_fdel->bind_param("i", $id);
            $stmt_fdel->execute();

            if ($stmt_fdel->affected_rows > 0) {
                $status = 'success';
                $message = 'Data berhasil dihapus permanen dari sistem!';
            } else {
                $status = 'error';
                $message = 'Data gagal dihapus permanen.';
            }

            $stmt_fdel->close();
        }
    }
}

// ==========================================
// 2. MEKANISME PAGINASI (MAKS 10 DATA)
// ==========================================
$limit = 10;

// Paginasi Kategori
$page_kat = isset($_GET['page_kat']) ? (int)$_GET['page_kat'] : 1;
$offset_kat = ($page_kat - 1) * $limit;
$total_kat = $conn->query("SELECT COUNT(id) as total FROM kategori_barang WHERE is_delete = $view_status")->fetch_assoc()['total'];
$pages_kat = ceil($total_kat / $limit);
$kategoriList = $conn->query("SELECT * FROM kategori_barang WHERE is_delete = $view_status ORDER BY nama_kategori ASC LIMIT $limit OFFSET $offset_kat");

// Paginasi Barang
$page_brg = isset($_GET['page_brg']) ? (int)$_GET['page_brg'] : 1;
$offset_brg = ($page_brg - 1) * $limit;
$total_brg = $conn->query("SELECT COUNT(id) as total FROM barang WHERE is_delete = $view_status")->fetch_assoc()['total'];
$pages_brg = ceil($total_brg / $limit);
$barangList = $conn->query("SELECT b.*, k.nama_kategori FROM barang b LEFT JOIN kategori_barang k ON b.id_kategori = k.id WHERE b.is_delete = $view_status ORDER BY b.nama_barang ASC LIMIT $limit OFFSET $offset_brg");

// Paginasi Pengguna
$page_usr = isset($_GET['page_usr']) ? (int)$_GET['page_usr'] : 1;
$offset_usr = ($page_usr - 1) * $limit;
$total_usr = $conn->query("SELECT COUNT(id) as total FROM users WHERE is_delete = $view_status")->fetch_assoc()['total'];
$pages_usr = ceil($total_usr / $limit);
$userList = $conn->query("SELECT id, nama, email, role FROM users WHERE is_delete = $view_status ORDER BY nama ASC LIMIT $limit OFFSET $offset_usr");

$kode_barang_otomatis = buatKodeBarangOtomatis($conn);

// Ambil list kategori aktif untuk kebutuhan form barang
$queryListKategori = $conn->query("SELECT id, nama_kategori FROM kategori_barang WHERE is_delete = 0 ORDER BY nama_kategori ASC");
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Master Data - Sistem Manajemen Persediaan</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <style>
        :root { --primary: #1f2937; --bg-color: #f9fafb; --border-color: #e5e7eb; }
        body { background-color: var(--bg-color); font-family: 'Inter', sans-serif; color: #1f2937; }

        /* Sidebar & Layout */
        .sidebar { width: 260px; min-height: 100vh; background-color: #ffffff; border-right: 1px solid var(--border-color); position: fixed; left: 0; top: 0; z-index: 1000; }
        .sidebar-logo { height: 70px; display: flex; align-items: center; padding: 0 24px; font-weight: 800; font-size: 1.25rem; border-bottom: 1px solid var(--border-color); }
        .nav-item-custom { padding: 12px 24px; color: #4b5563; text-decoration: none; display: flex; align-items: center; font-weight: 500; transition: all 0.2s; }
        .nav-item-custom i { width: 24px; font-size: 1.1rem; margin-right: 10px; }
        .nav-item-custom:hover { background-color: #f3f4f6; color: #000; }
        .nav-item-custom.active { background-color: #f3f4f6; color: #000; font-weight: 600; border-right: 3px solid #000; }
        .main-content { margin-left: 260px; min-height: 100vh; display: flex; flex-direction: column; }
        .top-navbar { height: 70px; background-color: #ffffff; border-bottom: 1px solid var(--border-color); display: flex; align-items: center; justify-content: space-between; padding: 0 30px; }

        /* Component Master Card */
        .master-card { background: #ffffff; border: 1px solid var(--border-color); border-radius: 12px; padding: 24px; text-align: center; box-shadow: 0 1px 3px rgba(0,0,0,0.05); height: 100%; display: flex; flex-direction: column; }
        .card-icon { width: 60px; height: 60px; margin: 0 auto 15px auto; background: #f3f4f6; color: #1f2937; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.5rem; }
        .card-title { font-weight: 600; font-size: 1.05rem; margin-bottom: 8px; }
        .card-desc { font-size: 0.85rem; color: #6b7280; flex-grow: 1; margin-bottom: 20px;}
        .btn-add { background: #ffffff; border: 1px solid var(--border-color); color: #1f2937; font-size: 0.9rem; font-weight: 600; padding: 8px 16px; border-radius: 8px; transition: all 0.2s; display: inline-flex; align-items: center; justify-content: center; gap: 8px; width: 100%; }
        .btn-add:hover { background: #f9fafb; border-color: #9ca3af; }

        /* Tabs UI & Table Layout */
        .data-section { background: #ffffff; border: 1px solid var(--border-color); border-radius: 12px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); overflow: hidden;}
        .nav-tabs { border-bottom: 1px solid var(--border-color); background-color: #f9fafb; padding: 0 20px;}
        .nav-tabs .nav-link { border: none; color: #6b7280; font-weight: 500; padding: 15px 20px; border-bottom: 2px solid transparent; border-radius: 0; margin-bottom: -1px;}
        .nav-tabs .nav-link.active { color: #000; background: transparent; border-bottom: 2px solid #000; font-weight: 600;}

        .table-custom th { background-color: #f9fafb; font-weight: 600; color: #4b5563; font-size: 0.85rem; padding: 14px 20px; text-transform: uppercase; border-bottom: 1px solid var(--border-color);}
        .table-custom td { padding: 14px 20px; vertical-align: middle; font-size: 0.9rem; border-bottom: 1px solid var(--border-color);}

        .action-btn { background: #f3f4f6; border: none; padding: 6px 10px; border-radius: 6px; color: #4b5563; transition: 0.2s; }
        .action-btn:hover { background: #e5e7eb; color: #000; }
        .action-btn.delete:hover { color: #dc3545; background: #fee2e2; }
        .action-btn.restore:hover { color: #198754; background: #d1e7dd; }

        /* Modal & Form Control */
        .modal-content { border-radius: 12px; border: none; }
        .modal-header { border-bottom: 1px solid var(--border-color); padding: 20px 24px; }
        .modal-footer { border-top: 1px solid var(--border-color); padding: 16px 24px; background: #f9fafb;}
        .form-control:focus, .form-select:focus { border-color: #000; box-shadow: 0 0 0 3px rgba(0, 0, 0, 0.05); }
    </style>
</head>
<body>

    <div class="sidebar d-none d-md-block d-flex flex-column justify-content-between">
        <div>
            <div class="sidebar-logo text-dark">LOGO WEB</div>
            <div class="mt-3">
                <a href="dashboard.php" class="nav-item-custom"><i class="fa-solid fa-house"></i> Dashboard</a>
                <a href="#persediaan" class="nav-item-custom" data-bs-toggle="collapse"><i class="fa-solid fa-box"></i> Persediaan Barang</a>
                <div class="collapse" id="persediaan">
                    <a href="form_barang.php?jenis=masuk" class="nav-item-custom ps-5 py-2 text-muted" style="font-size: 0.9rem;"><i class="fa-regular fa-circle" style="font-size: 0.5rem;"></i> Barang Masuk</a>
                    <a href="form_barang.php?jenis=keluar" class="nav-item-custom ps-5 py-2 text-muted" style="font-size: 0.9rem;"><i class="fa-regular fa-circle" style="font-size: 0.5rem;"></i> Barang Keluar</a>
                </div>
                <a href="master.php" class="nav-item-custom active"><i class="fa-solid fa-database"></i> Master</a>
                <a href="laporan.php" class="nav-item-custom"><i class="fa-regular fa-file-lines"></i> Laporan</a>
            </div>
        </div>
        <div class="mb-4 border-top pt-3">
            <a href="logout.php" class="nav-item-custom text-danger"><i class="fa-solid fa-arrow-right-from-bracket"></i> Logout</a>
        </div>
    </div>

    <div class="main-content">
        <header class="top-navbar">
            <button class="btn btn-light d-md-none"><i class="fa-solid fa-bars"></i></button>
            <div class="d-none d-md-block"><i class="fa-solid fa-bars fs-5 text-secondary cursor-pointer"></i></div>
            <div class="dropdown">
                <a class="text-dark text-decoration-none dropdown-toggle d-flex align-items-center gap-2" href="#" role="button" data-bs-toggle="dropdown">
                    <i class="fa-regular fa-circle-user fs-4"></i>
                    <span class="fw-medium"><?= htmlspecialchars($nama_login) ?></span>
                </a>

                <ul class="dropdown-menu dropdown-menu-end shadow-sm border-0">
                    <li><a class="dropdown-item text-danger" href="logout.php">Logout</a></li>
                </ul>
            </div>
        </header>

        <main class="p-4 p-md-5">
            <div class="d-flex justify-content-between align-items-start mb-4">
                <div>
                    <h2 class="fw-bold mb-1">Master</h2>
                    <p class="text-muted">Kelola data master sistem secara terpusat dan terintegrasi.</p>
                </div>

                <div>
                    <a href="master.php?status=active&tab=<?= $active_tab ?>" class="btn btn-sm <?= $view_status == 0 ? 'btn-dark' : 'btn-outline-secondary' ?> fw-medium" style="border-radius: 6px;">Data Aktif</a>
                    <a href="master.php?status=trash&tab=<?= $active_tab ?>" class="btn btn-sm <?= $view_status == 1 ? 'btn-danger' : 'btn-outline-danger' ?> fw-medium" style="border-radius: 6px;"><i class="fa-regular fa-trash-can me-1"></i> Sampah</a>
                </div>
            </div>

            <?php if ($is_admin): ?>
            <div class="row g-4 mb-5">
                <div class="col-12 col-md-4">
                    <div class="master-card">
                        <div class="card-icon"><i class="fa-solid fa-tags"></i></div>
                        <div class="card-title">Kategori Barang</div>
                        <div class="card-desc">Tambah kategori baru untuk pengelompokan barang inventori.</div>
                        <button type="button" class="btn-add" data-bs-toggle="modal" data-bs-target="#modalKategori" onclick="resetKategoriModal()"><i class="fa-solid fa-plus"></i> Tambah Kategori</button>
                    </div>
                </div>
                <div class="col-12 col-md-4">
                    <div class="master-card">
                        <div class="card-icon"><i class="fa-solid fa-box-open"></i></div>
                        <div class="card-title">Daftar Barang</div>
                        <div class="card-desc">Tambahkan produk atau barang master baru ke dalam sistem.</div>
                        <button type="button" class="btn-add" data-bs-toggle="modal" data-bs-target="#modalBarang" onclick="resetBarangModal()"><i class="fa-solid fa-plus"></i> Tambah Barang</button>
                    </div>
                </div>
                <div class="col-12 col-md-4">
                    <div class="master-card">
                        <div class="card-icon"><i class="fa-regular fa-user"></i></div>
                        <div class="card-title">Manajemen Pengguna</div>
                        <div class="card-desc">Tambahkan akun pengguna baru ke dalam database sistem.</div>
                        <button type="button" class="btn-add" data-bs-toggle="modal" data-bs-target="#modalPengguna" onclick="resetPenggunaModal()"><i class="fa-solid fa-plus"></i> Tambah Pengguna</button>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <div class="data-section">
                <ul class="nav nav-tabs" id="masterTabs" role="tablist">
                    <li class="nav-item"><button class="nav-link <?= $active_tab == 'kategori' ? 'active' : '' ?>" onclick="switchTab('kategori')" data-bs-toggle="tab" data-bs-target="#tab-kategori" type="button">Kategori (<?= $total_kat ?>)</button></li>
                    <li class="nav-item"><button class="nav-link <?= $active_tab == 'barang' ? 'active' : '' ?>" onclick="switchTab('barang')" data-bs-toggle="tab" data-bs-target="#tab-barang" type="button">Barang (<?= $total_brg ?>)</button></li>
                    <li class="nav-item"><button class="nav-link <?= $active_tab == 'pengguna' ? 'active' : '' ?>" onclick="switchTab('pengguna')" data-bs-toggle="tab" data-bs-target="#tab-pengguna" type="button">Pengguna (<?= $total_usr ?>)</button></li>
                </ul>

                <div class="tab-content">
                    <div class="tab-pane fade <?= $active_tab == 'kategori' ? 'show active' : '' ?>" id="tab-kategori">
                        <table class="table table-custom mb-0">
                            <thead>
                                <tr>
                                    <th width="8%">No</th>
                                    <th width="30%">Nama Kategori</th>
                                    <th width="42%">Deskripsi</th>
                                    <?php if ($is_admin): ?><th width="20%" class="text-center">Aksi</th><?php endif; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $no=1+$offset_kat; while($row = $kategoriList->fetch_assoc()): ?>
                                <tr>
                                    <td><?= $no++ ?></td>
                                    <td class="fw-medium"><?= htmlspecialchars($row['nama_kategori']) ?></td>
                                    <td class="text-muted"><?= htmlspecialchars($row['deskripsi'] ?? '-') ?></td>
                                    <?php if ($is_admin): ?>
                                    <td class="text-center">
                                        <?php if($view_status == 0): ?>
                                            <button class="action-btn me-1" onclick="editKategori(<?= $row['id'] ?>, '<?= htmlspecialchars($row['nama_kategori'], ENT_QUOTES) ?>', '<?= htmlspecialchars($row['deskripsi'] ?? '', ENT_QUOTES) ?>')"><i class="fa-solid fa-pen"></i></button>
                                            <button class="action-btn delete" onclick="confirmDelete(<?= $row['id'] ?>, 'kategori')"><i class="fa-solid fa-trash"></i></button>
                                        <?php else: ?>
                                            <button class="action-btn restore me-1" title="Restore" onclick="confirmRestore(<?= $row['id'] ?>, 'kategori')"><i class="fa-solid fa-trash-arrow-up"></i></button>
                                            <button class="action-btn delete" title="Hapus Permanen" onclick="confirmForceDelete(<?= $row['id'] ?>, 'kategori')"><i class="fa-solid fa-skull-crossbones"></i></button>
                                        <?php endif; ?>
                                    </td>
                                    <?php endif; ?>
                                </tr>
                                <?php endwhile; if($total_kat == 0) echo "<tr><td colspan='4' class='text-center py-4 text-muted'>Tidak ada data.</td></tr>"; ?>
                            </tbody>
                        </table>
                        <?php if($pages_kat > 1): ?>
                        <div class="p-3 bg-light border-top d-flex justify-content-center">
                            <ul class="pagination pagination-sm m-0">
                                <?php for($i=1; $i<=$pages_kat; $i++): ?>
                                    <li class="page-item <?= $page_kat == $i ? 'active' : '' ?>"><a class="page-link" href="master.php?status=<?= $view_status==1?'trash':'active' ?>&tab=kategori&page_kat=<?= $i ?>"><?= $i ?></a></li>
                                <?php endfor; ?>
                            </ul>
                        </div>
                        <?php endif; ?>
                    </div>

                    <div class="tab-pane fade <?= $active_tab == 'barang' ? 'show active' : '' ?>" id="tab-barang">
                        <table class="table table-custom mb-0">
                            <thead>
                                <tr>
                                    <th>No</th><th>Kode</th><th>Nama Barang</th><th>Kategori</th><th>Stok</th><th>Satuan</th>
                                    <?php if ($is_admin): ?><th class="text-center">Aksi</th><?php endif; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $no=1+$offset_brg; while($row = $barangList->fetch_assoc()): ?>
                                <tr>
                                    <td><?= $no++ ?></td>
                                    <td class="text-primary fw-medium"><?= htmlspecialchars($row['kode_barang']) ?></td>
                                    <td class="fw-medium"><?= htmlspecialchars($row['nama_barang']) ?></td>
                                    <td><span class="badge bg-secondary bg-opacity-10 text-secondary border"><?= htmlspecialchars($row['nama_kategori'] ?? '-') ?></span></td>
                                    <td class="fw-bold"><?= $row['stok'] ?></td>
                                    <td><?= htmlspecialchars($row['satuan']) ?></td>
                                    <?php if ($is_admin): ?>
                                    <td class="text-center">
                                        <?php if($view_status == 0): ?>
                                            <button class="action-btn me-1" onclick="editBarang(<?= $row['id'] ?>, <?= $row['id_kategori'] ?>, '<?= htmlspecialchars($row['kode_barang'], ENT_QUOTES) ?>', '<?= htmlspecialchars($row['nama_barang'], ENT_QUOTES) ?>', <?= $row['stok'] ?>, '<?= htmlspecialchars($row['satuan'], ENT_QUOTES) ?>', '<?= htmlspecialchars($row['deskripsi'], ENT_QUOTES) ?>')"><i class="fa-solid fa-pen"></i></button>
                                            <button class="action-btn delete" onclick="confirmDelete(<?= $row['id'] ?>, 'barang')"><i class="fa-solid fa-trash"></i></button>
                                        <?php else: ?>
                                            <button class="action-btn restore me-1" title="Restore" onclick="confirmRestore(<?= $row['id'] ?>, 'barang')"><i class="fa-solid fa-trash-arrow-up"></i></button>
                                            <button class="action-btn delete" title="Hapus Permanen" onclick="confirmForceDelete(<?= $row['id'] ?>, 'barang')"><i class="fa-solid fa-skull-crossbones"></i></button>
                                        <?php endif; ?>
                                    </td>
                                    <?php endif; ?>
                                </tr>
                                <?php endwhile; if($total_brg == 0) echo "<tr><td colspan='7' class='text-center py-4 text-muted'>Tidak ada data.</td></tr>"; ?>
                            </tbody>
                        </table>
                        <?php if($pages_brg > 1): ?>
                        <div class="p-3 bg-light border-top d-flex justify-content-center">
                            <ul class="pagination pagination-sm m-0">
                                <?php for($i=1; $i<=$pages_brg; $i++): ?>
                                    <li class="page-item <?= $page_brg == $i ? 'active' : '' ?>"><a class="page-link" href="master.php?status=<?= $view_status==1?'trash':'active' ?>&tab=barang&page_brg=<?= $i ?>"><?= $i ?></a></li>
                                <?php endfor; ?>
                            </ul>
                        </div>
                        <?php endif; ?>
                    </div>

                    <div class="tab-pane fade <?= $active_tab == 'pengguna' ? 'show active' : '' ?>" id="tab-pengguna">
                        <table class="table table-custom mb-0">
                            <thead>
                                <tr>
                                    <th>No</th><th>Nama Lengkap</th><th>Email</th><th>Role</th>
                                    <?php if ($is_admin): ?><th class="text-center">Aksi</th><?php endif; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $no=1+$offset_usr; while($row = $userList->fetch_assoc()): ?>
                                <tr>
                                    <td><?= $no++ ?></td>
                                    <td class="fw-medium"><?= htmlspecialchars($row['nama']) ?></td>
                                    <td class="text-muted"><?= htmlspecialchars($row['email']) ?></td>
                                    <td><span class="badge bg-dark bg-opacity-10 text-dark border"><?= ucfirst($row['role']) ?></span></td>
                                    <?php if ($is_admin): ?>
                                    <td class="text-center">
                                        <?php if($view_status == 0): ?>
                                            <button class="action-btn me-1" onclick="editPengguna(<?= $row['id'] ?>, '<?= htmlspecialchars($row['nama'], ENT_QUOTES) ?>', '<?= htmlspecialchars($row['email'], ENT_QUOTES) ?>', '<?= $row['role'] ?>')"><i class="fa-solid fa-pen"></i></button>

                                            <?php if($row['id'] == $id_user_login): ?>
                                                <button class="action-btn" disabled text="Akun Anda" style="opacity: 0.4; cursor: not-allowed;"><i class="fa-solid fa-ban"></i></button>
                                            <?php else: ?>
                                                <button class="action-btn delete" onclick="confirmDelete(<?= $row['id'] ?>, 'pengguna')"><i class="fa-solid fa-trash"></i></button>
                                            <?php endif; ?>

                                        <?php else: ?>
                                            <button class="action-btn restore me-1" title="Restore" onclick="confirmRestore(<?= $row['id'] ?>, 'pengguna')"><i class="fa-solid fa-trash-arrow-up"></i></button>
                                            <button class="action-btn delete" title="Hapus Permanen" onclick="confirmForceDelete(<?= $row['id'] ?>, 'pengguna')"><i class="fa-solid fa-skull-crossbones"></i></button>
                                        <?php endif; ?>
                                    </td>
                                    <?php endif; ?>
                                </tr>
                                <?php endwhile; if($total_usr == 0) echo "<tr><td colspan='5' class='text-center py-4 text-muted'>Tidak ada data.</td></tr>"; ?>
                            </tbody>
                        </table>
                        <?php if($pages_usr > 1): ?>
                        <div class="p-3 bg-light border-top d-flex justify-content-center">
                            <ul class="pagination pagination-sm m-0">
                                <?php for($i=1; $i<=$pages_usr; $i++): ?>
                                    <li class="page-item <?= $page_usr == $i ? 'active' : '' ?>"><a class="page-link" href="master.php?status=<?= $view_status==1?'trash':'active' ?>&tab=pengguna&page_usr=<?= $i ?>"><?= $i ?></a></li>
                                <?php endfor; ?>
                            </ul>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <?php if ($is_admin): ?>
    <div class="modal fade" id="modalKategori" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><div class="modal-header"><h5 class="modal-title fw-bold" id="kategoriTitle">Tambah Kategori</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><form method="POST" action=""><input type="hidden" name="id_kategori_edit" id="id_kategori_edit" value=""><div class="modal-body"><div class="mb-3"><label class="form-label fw-medium">Nama Kategori <span class="text-danger">*</span></label><input type="text" name="nama_kategori" id="kat_nama" class="form-control" required></div><div class="mb-2"><label class="form-label fw-medium">Deskripsi</label><textarea name="deskripsi" id="kat_desc" class="form-control" rows="3"></textarea></div></div><div class="modal-footer"><button type="button" class="btn btn-light border" data-bs-dismiss="modal">Batal</button><button type="submit" id="btnSubmitKategori" name="tambah_kategori" class="btn btn-dark">Simpan</button></div></form></div></div></div>
    <div class="modal fade" id="modalBarang" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <div>
                        <h5 class="modal-title fw-bold" id="barangTitle">Tambah Barang</h5>
                        <small class="text-muted">Kode barang dibuat otomatis oleh sistem.</small>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="">
                    <input type="hidden" name="id_barang_edit" id="id_barang_edit" value="">
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-md-5">
                                <label class="form-label fw-medium">Kode Barang</label>
                                <input type="text" name="kode_barang" id="brg_kode" class="form-control bg-light" value="<?= htmlspecialchars($kode_barang_otomatis) ?>" readonly>
                                <small class="text-muted">Contoh: BRG-001, BRG-002, dan seterusnya.</small>
                            </div>
                            <div class="col-md-7">
                                <label class="form-label fw-medium">Nama Barang <span class="text-danger">*</span></label>
                                <input type="text" name="nama_barang" id="brg_nama" class="form-control" placeholder="Contoh: Pulpen Joy" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-medium">Kategori <span class="text-danger">*</span></label>
                                <select name="id_kategori" id="brg_kat" class="form-select" required>
                                    <?php $queryListKategori->data_seek(0); while($rowKat = $queryListKategori->fetch_assoc()): ?>
                                        <option value="<?= $rowKat['id'] ?>"><?= htmlspecialchars($rowKat['nama_kategori']) ?></option>
                                    <?php endwhile; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label fw-medium">Satuan</label>
                                <input type="text" name="satuan" id="brg_satuan" class="form-control" placeholder="pcs / box / unit">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label fw-medium">Stok Awal</label>
                                <input type="number" name="stok" id="brg_stok" class="form-control" value="0" min="0">
                            </div>
                            <div class="col-12">
                                <label class="form-label fw-medium">Deskripsi</label>
                                <textarea name="deskripsi_barang" id="brg_desc" class="form-control" rows="2" placeholder="Contoh: Pulpen untuk kebutuhan kelas dan kantor."></textarea>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" id="btnSubmitBarang" name="tambah_barang" class="btn btn-dark">Simpan</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <div class="modal fade" id="modalPengguna" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><div class="modal-header"><h5 class="modal-title fw-bold" id="penggunaTitle">Tambah Pengguna</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><form method="POST" action=""><input type="hidden" name="id_pengguna_edit" id="id_pengguna_edit" value=""><div class="modal-body"><div class="mb-3"><label class="form-label fw-medium">Nama Lengkap <span class="text-danger">*</span></label><input type="text" name="nama" id="usr_nama" class="form-control" required></div><div class="mb-3"><label class="form-label fw-medium">Email <span class="text-danger">*</span></label><input type="email" name="email" id="usr_email" class="form-control" required></div><div class="mb-3"><label class="form-label fw-medium">Password <span class="text-danger" id="usr_pass_req">*</span></label><input type="password" name="password" id="usr_pass" class="form-control" required><small class="text-muted" id="usr_pass_help" style="display:none;">Kosongkan jika tidak ingin mengubah password.</small></div><div class="mb-2"><label class="form-label fw-medium">Role <span class="text-danger">*</span></label><select name="role" id="usr_role" class="form-select" required><option value="user">User Biasa</option><option value="admin">Administrator</option></select></div></div><div class="modal-footer"><button type="button" class="btn btn-light border" data-bs-dismiss="modal">Batal</button><button type="submit" id="btnSubmitPengguna" name="tambah_pengguna" class="btn btn-dark">Simpan</button></div></form></div></div></div>

    <form id="formMasterAction" method="POST" action="" style="display: none;">
        <input type="hidden" name="action_handler" id="action_handler" value="1">
        <input type="hidden" name="delete_data" id="trigger_delete">
        <input type="hidden" name="delete_id" id="delete_id">
        <input type="hidden" name="delete_type" id="delete_type">

        <input type="hidden" name="restore_data" id="trigger_restore">
        <input type="hidden" name="restore_id" id="restore_id">
        <input type="hidden" name="restore_type" id="restore_type">

        <input type="hidden" name="force_delete_data" id="trigger_fdelete">
        <input type="hidden" name="force_id" id="force_id">
        <input type="hidden" name="force_type" id="force_type">
    </form>
    <?php endif; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Membantu navigasi tab agar tetap konsisten saat re-render halaman / paginasi
        function switchTab(tabName) {
            const urlParams = new URLSearchParams(window.location.search);
            urlParams.set('tab', tabName);
            // Reset paginasi komponen lain saat berpindah fokus entitas data
            window.location.search = urlParams.toString();
        }

        <?php if($is_admin): ?>
        // RESET MODAL TAMBAH DATA
        function resetKategoriModal() {
            document.getElementById('kategoriTitle').innerText = 'Tambah Kategori';
            document.getElementById('btnSubmitKategori').name = 'tambah_kategori';
            document.getElementById('id_kategori_edit').value = '';
            document.getElementById('kat_nama').value = '';
            document.getElementById('kat_desc').value = '';
        }

        function resetBarangModal() {
            document.getElementById('barangTitle').innerText = 'Tambah Barang';
            document.getElementById('btnSubmitBarang').name = 'tambah_barang';
            document.getElementById('id_barang_edit').value = '';
            document.getElementById('brg_kode').value = '<?= htmlspecialchars($kode_barang_otomatis) ?>';
            document.getElementById('brg_nama').value = '';
            document.getElementById('brg_stok').value = 0;
            document.getElementById('brg_satuan').value = '';
            document.getElementById('brg_desc').value = '';
            const kategoriSelect = document.getElementById('brg_kat');
            if (kategoriSelect.options.length > 0) kategoriSelect.selectedIndex = 0;
        }

        function resetPenggunaModal() {
            document.getElementById('penggunaTitle').innerText = 'Tambah Pengguna';
            document.getElementById('btnSubmitPengguna').name = 'tambah_pengguna';
            document.getElementById('id_pengguna_edit').value = '';
            document.getElementById('usr_nama').value = '';
            document.getElementById('usr_email').value = '';
            document.getElementById('usr_role').value = 'user';
            document.getElementById('usr_pass').value = '';
            document.getElementById('usr_pass').required = true;
            document.getElementById('usr_pass_req').style.display = 'inline';
            document.getElementById('usr_pass_help').style.display = 'none';
        }

        // LOGIKA AUTO-FILL MODAL EDIT DATA
        function editKategori(id, nama, desc) {
            document.getElementById('kategoriTitle').innerText = 'Edit Kategori';
            document.getElementById('btnSubmitKategori').name = 'edit_kategori';
            document.getElementById('id_kategori_edit').value = id;
            document.getElementById('kat_nama').value = nama;
            document.getElementById('kat_desc').value = desc;
            new bootstrap.Modal(document.getElementById('modalKategori')).show();
        }

        function editBarang(id, id_kat, kode, nama, stok, satuan, desc) {
            document.getElementById('barangTitle').innerText = 'Edit Barang';
            document.getElementById('btnSubmitBarang').name = 'edit_barang';
            document.getElementById('id_barang_edit').value = id;
            document.getElementById('brg_kode').value = kode;
            document.getElementById('brg_nama').value = nama;
            document.getElementById('brg_kat').value = id_kat;
            document.getElementById('brg_stok').value = stok;
            document.getElementById('brg_satuan').value = satuan;
            document.getElementById('brg_desc').value = desc;
            new bootstrap.Modal(document.getElementById('modalBarang')).show();
        }

        function editPengguna(id, nama, email, role) {
            document.getElementById('penggunaTitle').innerText = 'Edit Pengguna';
            document.getElementById('btnSubmitPengguna').name = 'edit_pengguna';
            document.getElementById('id_pengguna_edit').value = id;
            document.getElementById('usr_nama').value = nama;
            document.getElementById('usr_email').value = email;
            document.getElementById('usr_role').value = role;
            document.getElementById('usr_pass').required = false;
            document.getElementById('usr_pass_req').style.display = 'none';
            document.getElementById('usr_pass_help').style.display = 'block';
            new bootstrap.Modal(document.getElementById('modalPengguna')).show();
        }

        // POP-UP CONFIRMATION INTERACTION (SWEETALERT2)
        function resetActionForm() {
            document.getElementById('trigger_delete').value = '';
            document.getElementById('delete_id').value = '';
            document.getElementById('delete_type').value = '';
            document.getElementById('trigger_restore').value = '';
            document.getElementById('restore_id').value = '';
            document.getElementById('restore_type').value = '';
            document.getElementById('trigger_fdelete').value = '';
            document.getElementById('force_id').value = '';
            document.getElementById('force_type').value = '';
        }

        function confirmDelete(id, type) {
            Swal.fire({
                title: 'Pindahkan ke Sampah?',
                text: "Data akan dinonaktifkan sementara.",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#1f2937',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Ya, Buang'
            }).then((res) => {
                if (res.isConfirmed) {
                    resetActionForm();
                    document.getElementById('trigger_delete').value = "1";
                    document.getElementById('delete_id').value = id;
                    document.getElementById('delete_type').value = type;
                    document.getElementById('formMasterAction').submit();
                }
            });
        }

        function confirmRestore(id, type) {
            Swal.fire({
                title: 'Kembalikan Data?',
                text: "Data akan diaktifkan kembali ke sistem utama.",
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#198754',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Ya, Aktifkan'
            }).then((res) => {
                if (res.isConfirmed) {
                    resetActionForm();
                    document.getElementById('trigger_restore').value = "1";
                    document.getElementById('restore_id').value = id;
                    document.getElementById('restore_type').value = type;
                    document.getElementById('formMasterAction').submit();
                }
            });
        }

        function confirmForceDelete(id, type) {
            Swal.fire({
                title: 'Hapus Permanen?',
                text: "Tindakan ini tidak dapat dibatalkan secara sistem database fisik!",
                icon: 'error',
                showCancelButton: true,
                confirmButtonColor: '#dc3545',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Ya, Hapus Total'
            }).then((res) => {
                if (res.isConfirmed) {
                    resetActionForm();
                    document.getElementById('trigger_fdelete').value = "1";
                    document.getElementById('force_id').value = id;
                    document.getElementById('force_type').value = type;
                    document.getElementById('formMasterAction').submit();
                }
            });
        }
        <?php endif; ?>
    </script>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        <?php if ($status == 'success'): ?>
            Swal.fire({ title: 'Berhasil!', text: '<?= $message ?>', icon: 'success', confirmButtonColor: '#1f2937' });
        <?php elseif ($status == 'error'): ?>
            Swal.fire({ title: 'Gagal!', text: '<?= $message ?>', icon: 'error', confirmButtonColor: '#ef4444' });
        <?php endif; ?>
    </script>
</body>
</html>
