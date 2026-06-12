<?php
session_start();
require 'config/db_connect.php';

// ==============================
// KONFIGURASI & VARIABEL GLOBAL
// ==============================
$stokMinimum = 10;

$nama_login = $_SESSION['username'] ?? 'User';
$role_login = $_SESSION['role'] ?? 'user';

// Cek session login.
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: index.php");
    exit;
}

// Helper sederhana untuk mengambil nilai total dari query.
function ambilTotal($conn, $sql)
{
    $hasil = $conn->query($sql);
    return $hasil ? (int) $hasil->fetch_assoc()['total'] : 0;
}

// Query total kategori aktif.
$sqlTotalKategori = "
    SELECT COUNT(id) AS total
    FROM kategori_barang
    WHERE is_delete = 0
";
$totalKategori = ambilTotal($conn, $sqlTotalKategori);

// Query total barang aktif.
$sqlTotalProduk = "
    SELECT COUNT(id) AS total
    FROM barang
    WHERE is_delete = 0
";
$totalProduk = ambilTotal($conn, $sqlTotalProduk);

// Query total barang masuk bulan ini.
$sqlTotalMasuk = "
    SELECT COALESCE(SUM(dt.qty), 0) AS total
    FROM detail_transaksi dt
    JOIN transaksi t ON dt.id_transaksi = t.id
    WHERE t.jenis_transaksi = 'masuk'
    AND MONTH(t.tanggal_transaksi) = MONTH(CURRENT_DATE())
    AND YEAR(t.tanggal_transaksi) = YEAR(CURRENT_DATE())
    AND t.is_delete = 0
";
$totalMasuk = ambilTotal($conn, $sqlTotalMasuk);

// Query total barang keluar bulan ini.
$sqlTotalKeluar = "
    SELECT COALESCE(SUM(dt.qty), 0) AS total
    FROM detail_transaksi dt
    JOIN transaksi t ON dt.id_transaksi = t.id
    WHERE t.jenis_transaksi = 'keluar'
    AND MONTH(t.tanggal_transaksi) = MONTH(CURRENT_DATE())
    AND YEAR(t.tanggal_transaksi) = YEAR(CURRENT_DATE())
    AND t.is_delete = 0
";
$totalKeluar = ambilTotal($conn, $sqlTotalKeluar);

// Query total barang dengan stok di bawah batas minimum.
$sqlTotalStokRendah = "
    SELECT COUNT(id) AS total
    FROM barang
    WHERE stok < $stokMinimum
    AND is_delete = 0
";
$totalStokRendah = ambilTotal($conn, $sqlTotalStokRendah);

// Query daftar barang stok rendah untuk tabel dashboard.
$sqlTabelStok = "
    SELECT
        b.kode_barang,
        b.nama_barang,
        k.nama_kategori,
        b.stok,
        b.satuan
    FROM barang b
    LEFT JOIN kategori_barang k ON b.id_kategori = k.id
    WHERE b.stok < $stokMinimum
    AND b.is_delete = 0
    ORDER BY b.stok ASC
    LIMIT 10
";
$queryTabelStok = $conn->query($sqlTabelStok);
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Sistem Manajemen Persediaan</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <style>
        body {
            font-family: 'Inter', sans-serif;
            background-color: #f8f9fa;
        }

        .sidebar {
            width: 260px;
            min-height: 100vh;
            background-color: #ffffff;
            border-right: 1px solid #e9ecef;
            position: fixed;
            left: 0;
            top: 0;
        }

        .sidebar-logo {
            height: 70px;
            display: flex;
            align-items: center;
            padding: 0 24px;
            font-weight: 700;
            font-size: 1.25rem;
            border-bottom: 1px solid #e9ecef;
        }

        .nav-item-custom {
            padding: 12px 24px;
            color: #495057;
            text-decoration: none;
            display: flex;
            align-items: center;
            font-weight: 500;
            transition: all 0.2s;
        }

        .nav-item-custom i {
            width: 24px;
            font-size: 1.1rem;
            margin-right: 10px;
        }

        .nav-item-custom:hover {
            background-color: #f8f9fa;
            color: #0d6efd;
        }

        .nav-item-custom.active {
            background-color: #eef2ff;
            color: #4f46e5;
            border-right: 3px solid #4f46e5;
        }

        .main-content {
            margin-left: 260px;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        .top-navbar {
            height: 70px;
            background-color: #ffffff;
            border-bottom: 1px solid #e9ecef;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 30px;
        }

        .hifi-card {
            border: none;
            border-radius: 12px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05),
                        0 2px 4px -1px rgba(0, 0, 0, 0.03);
        }

        .stat-card {
            padding: 20px;
            height: 100%;
        }

        .stat-icon {
            width: 44px;
            height: 44px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
        }

        .stat-title {
            font-size: 0.85rem;
            color: #6c757d;
            margin-bottom: 4px;
            font-weight: 500;
        }

        .stat-value {
            font-size: 1.6rem;
            font-weight: 700;
            margin: 0;
        }

        .table-custom th {
            background-color: #f8f9fa;
            font-weight: 600;
            color: #6c757d;
            border-bottom-width: 1px;
            padding: 14px 16px;
            white-space: nowrap;
        }

        .table-custom td {
            vertical-align: middle;
            padding: 14px 16px;
        }

        .row-stok-rendah {
            background-color: #fff8db !important;
        }

        .row-stok-rendah:hover {
            background-color: #fff3bf !important;
        }

        .badge-kategori {
            background-color: #f1f3f5;
            color: #495057;
            border: 1px solid #dee2e6;
            font-weight: 500;
        }

        .stok-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background-color: #fff3cd;
            color: #856404;
            border: 1px solid #ffe69c;
            border-radius: 999px;
            padding: 4px 10px;
            font-weight: 700;
            font-size: 0.85rem;
        }

        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
            }
        }
    </style>
</head>
<body>

    <div class="sidebar d-none d-md-block d-flex flex-column justify-content-between">
        <div>
            <div class="sidebar-logo text-dark">LOGO WEB</div>

            <div class="mt-3">
                <a href="dashboard.php" class="nav-item-custom active">
                    <i class="fa-solid fa-house"></i> Dashboard
                </a>

                <a href="#persediaan" class="nav-item-custom" data-bs-toggle="collapse">
                    <i class="fa-solid fa-box"></i> Persediaan Barang
                </a>

                <div class="collapse" id="persediaan">
                    <a href="form_barang.php?jenis=masuk" class="nav-item-custom ps-5 py-2 text-muted" style="font-size: 0.9rem;">
                        <i class="fa-solid fa-arrow-turn-down"></i> Barang Masuk
                    </a>

                    <a href="form_barang.php?jenis=keluar" class="nav-item-custom ps-5 py-2 text-muted" style="font-size: 0.9rem;">
                        <i class="fa-solid fa-arrow-turn-up"></i> Barang Keluar
                    </a>
                </div>

                <?php if ($role_login === 'admin'): ?>
                    <a href="master.php" class="nav-item-custom">
                        <i class="fa-solid fa-database"></i> Master
                    </a>
                <?php endif; ?>

                <a href="laporan.php" class="nav-item-custom">
                    <i class="fa-regular fa-file-lines"></i> Laporan
                </a>
            </div>
        </div>

        <div class="mb-4 border-top pt-3">
            <a href="logout.php" class="nav-item-custom text-danger">
                <i class="fa-solid fa-arrow-right-from-bracket text-danger"></i> Logout
            </a>
        </div>
    </div>

    <div class="main-content">

        <header class="top-navbar">
            <button class="btn btn-light d-md-none">
                <i class="fa-solid fa-bars"></i>
            </button>

            <div class="d-none d-md-block">
                <i class="fa-solid fa-bars fs-5 text-secondary cursor-pointer"></i>
            </div>

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
            <div class="mb-4">
                <h2 class="fw-bold mb-1">Dashboard</h2>
                <p class="text-muted mb-0">Ringkasan inventori dan pergerakan barang bulan ini.</p>
            </div>

            <div class="row g-4 mb-4">
                <div class="col-12 col-sm-6 col-xl-3">
                    <div class="card hifi-card stat-card">
                        <div class="d-flex align-items-center gap-3">
                            <div class="stat-icon bg-primary bg-opacity-10 text-primary">
                                <i class="fa-solid fa-cube"></i>
                            </div>
                            <div>
                                <div class="stat-title">Total Barang</div>
                                <h3 class="stat-value"><?= number_format($totalProduk, 0, ',', '.') ?></h3>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-12 col-sm-6 col-xl-3">
                    <div class="card hifi-card stat-card">
                        <div class="d-flex align-items-center gap-3">
                            <div class="stat-icon bg-warning bg-opacity-10 text-warning">
                                <i class="fa-solid fa-triangle-exclamation"></i>
                            </div>
                            <div>
                                <div class="stat-title">Stok &lt; 10</div>
                                <h3 class="stat-value"><?= number_format($totalStokRendah, 0, ',', '.') ?></h3>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-12 col-sm-6 col-xl-3">
                    <div class="card hifi-card stat-card">
                        <div class="d-flex align-items-center gap-3">
                            <div class="stat-icon bg-success bg-opacity-10 text-success">
                                <i class="fa-solid fa-arrow-turn-down"></i>
                            </div>
                            <div>
                                <div class="stat-title">Barang Masuk</div>
                                <h3 class="stat-value"><?= number_format($totalMasuk, 0, ',', '.') ?></h3>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-12 col-sm-6 col-xl-3">
                    <div class="card hifi-card stat-card">
                        <div class="d-flex align-items-center gap-3">
                            <div class="stat-icon bg-danger bg-opacity-10 text-danger">
                                <i class="fa-solid fa-arrow-turn-up"></i>
                            </div>
                            <div>
                                <div class="stat-title">Barang Keluar</div>
                                <h3 class="stat-value"><?= number_format($totalKeluar, 0, ',', '.') ?></h3>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card hifi-card p-4">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div>
                        <h5 class="fw-bold mb-1">Daftar Barang Stok Dibawah 10</h5>
                        <p class="text-muted mb-0 small">Baris berwarna kuning menandakan stok barang sudah rendah.</p>
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="table table-custom table-hover mb-0">
                        <thead>
                            <tr>
                                <th>No</th>
                                <th>Kode Barang</th>
                                <th>Nama Barang</th>
                                <th>Kategori</th>
                                <th>Stok</th>
                                <th>Satuan</th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php if ($queryTabelStok && $queryTabelStok->num_rows > 0): ?>
                                <?php $no = 1; ?>
                                <?php while ($row = $queryTabelStok->fetch_assoc()): ?>
                                    <tr class="row-stok-rendah">
                                        <td><?= $no++ ?></td>

                                        <td class="fw-semibold text-primary">
                                            <?= htmlspecialchars($row['kode_barang']) ?>
                                        </td>

                                        <td class="fw-medium">
                                            <?= htmlspecialchars($row['nama_barang']) ?>
                                        </td>

                                        <td>
                                            <span class="badge badge-kategori">
                                                <?= htmlspecialchars($row['nama_kategori'] ?? 'Tidak ada') ?>
                                            </span>
                                        </td>

                                        <td>
                                            <span class="stok-badge">
                                                <i class="fa-solid fa-triangle-exclamation"></i>
                                                <?= number_format($row['stok'], 0, ',', '.') ?>
                                            </span>
                                        </td>

                                        <td>
                                            <?= htmlspecialchars($row['satuan'] ?? '-') ?>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6" class="text-center py-4 text-muted">
                                        Tidak ada barang dengan stok di bawah 10.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>
