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


// ==============================
// DATA REAL UNTUK CHART DASHBOARD
// ==============================
// Helper untuk menjalankan SELECT chart dengan prepared statement.
function ambilDataChart($conn, $sql, $types = '', $params = [])
{
    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        return [];
    }

    if ($types !== '' && count($params) > 0) {
        $stmt->bind_param($types, ...$params);
    }

    $stmt->execute();
    $result = $stmt->get_result();

    $data = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $data[] = $row;
        }
    }

    $stmt->close();
    return $data;
}

function namaBulanPendek($bulan)
{
    $listBulan = [
        1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr',
        5 => 'Mei', 6 => 'Jun', 7 => 'Jul', 8 => 'Agu',
        9 => 'Sep', 10 => 'Okt', 11 => 'Nov', 12 => 'Des'
    ];

    return $listBulan[(int) $bulan] ?? $bulan;
}

// Chart 1A: Tren transaksi 7 hari terakhir.
$labels7Hari = [];
$dataMasuk7Hari = [];
$dataKeluar7Hari = [];
$map7Hari = [];

for ($i = 6; $i >= 0; $i--) {
    $tanggalKey = date('Y-m-d', strtotime("-$i days"));
    $labels7Hari[] = date('d/m', strtotime($tanggalKey));
    $map7Hari[$tanggalKey] = ['masuk' => 0, 'keluar' => 0];
}

$sqlTren7Hari = "
    SELECT
        DATE(t.tanggal_transaksi) AS label,
        SUM(CASE WHEN t.jenis_transaksi = 'masuk' THEN dt.qty ELSE 0 END) AS masuk,
        SUM(CASE WHEN t.jenis_transaksi = 'keluar' THEN dt.qty ELSE 0 END) AS keluar
    FROM transaksi t
    JOIN detail_transaksi dt ON dt.id_transaksi = t.id
    WHERE t.tanggal_transaksi BETWEEN DATE_SUB(CURDATE(), INTERVAL 6 DAY) AND CURDATE()
    AND t.is_delete = 0
    GROUP BY DATE(t.tanggal_transaksi)
    ORDER BY DATE(t.tanggal_transaksi) ASC
";

foreach (ambilDataChart($conn, $sqlTren7Hari) as $row) {
    $key = $row['label'];
    if (isset($map7Hari[$key])) {
        $map7Hari[$key]['masuk'] = (int) $row['masuk'];
        $map7Hari[$key]['keluar'] = (int) $row['keluar'];
    }
}

foreach ($map7Hari as $row) {
    $dataMasuk7Hari[] = $row['masuk'];
    $dataKeluar7Hari[] = $row['keluar'];
}

// Chart 1B: Tren transaksi bulan ini per minggu.
$labelsBulanIni = ['Minggu 1', 'Minggu 2', 'Minggu 3', 'Minggu 4', 'Minggu 5'];
$dataMasukBulanIni = array_fill(0, 5, 0);
$dataKeluarBulanIni = array_fill(0, 5, 0);

$sqlTrenBulanIni = "
    SELECT
        CEIL(DAYOFMONTH(t.tanggal_transaksi) / 7) AS minggu_ke,
        SUM(CASE WHEN t.jenis_transaksi = 'masuk' THEN dt.qty ELSE 0 END) AS masuk,
        SUM(CASE WHEN t.jenis_transaksi = 'keluar' THEN dt.qty ELSE 0 END) AS keluar
    FROM transaksi t
    JOIN detail_transaksi dt ON dt.id_transaksi = t.id
    WHERE MONTH(t.tanggal_transaksi) = MONTH(CURDATE())
    AND YEAR(t.tanggal_transaksi) = YEAR(CURDATE())
    AND t.is_delete = 0
    GROUP BY CEIL(DAYOFMONTH(t.tanggal_transaksi) / 7)
    ORDER BY minggu_ke ASC
";

foreach (ambilDataChart($conn, $sqlTrenBulanIni) as $row) {
    $index = ((int) $row['minggu_ke']) - 1;
    if ($index >= 0 && $index < 5) {
        $dataMasukBulanIni[$index] = (int) $row['masuk'];
        $dataKeluarBulanIni[$index] = (int) $row['keluar'];
    }
}

// Chart 1C: Tren transaksi tahun ini per bulan.
$labelsTahunIni = [];
$dataMasukTahunIni = array_fill(0, 12, 0);
$dataKeluarTahunIni = array_fill(0, 12, 0);

for ($bulan = 1; $bulan <= 12; $bulan++) {
    $labelsTahunIni[] = namaBulanPendek($bulan);
}

$sqlTrenTahunIni = "
    SELECT
        MONTH(t.tanggal_transaksi) AS bulan,
        SUM(CASE WHEN t.jenis_transaksi = 'masuk' THEN dt.qty ELSE 0 END) AS masuk,
        SUM(CASE WHEN t.jenis_transaksi = 'keluar' THEN dt.qty ELSE 0 END) AS keluar
    FROM transaksi t
    JOIN detail_transaksi dt ON dt.id_transaksi = t.id
    WHERE YEAR(t.tanggal_transaksi) = YEAR(CURDATE())
    AND t.is_delete = 0
    GROUP BY MONTH(t.tanggal_transaksi)
    ORDER BY bulan ASC
";

foreach (ambilDataChart($conn, $sqlTrenTahunIni) as $row) {
    $index = ((int) $row['bulan']) - 1;
    if ($index >= 0 && $index < 12) {
        $dataMasukTahunIni[$index] = (int) $row['masuk'];
        $dataKeluarTahunIni[$index] = (int) $row['keluar'];
    }
}

// Chart 2: Komposisi kategori barang.
function ambilKomposisiKategori($conn, $kondisiTambahan = '')
{
    $sql = "
        SELECT
            COALESCE(k.nama_kategori, 'Tanpa Kategori') AS label,
            COUNT(b.id) AS total
        FROM barang b
        LEFT JOIN kategori_barang k ON b.id_kategori = k.id
        WHERE b.is_delete = 0
        $kondisiTambahan
        GROUP BY COALESCE(k.nama_kategori, 'Tanpa Kategori')
        ORDER BY total DESC, label ASC
    ";

    $labels = [];
    $data = [];

    foreach (ambilDataChart($conn, $sql) as $row) {
        $labels[] = $row['label'];
        $data[] = (int) $row['total'];
    }

    if (count($labels) === 0) {
        $labels = ['Tidak ada data'];
        $data = [0];
    }

    return ['labels' => $labels, 'data' => $data];
}

$kategoriSemua = ambilKomposisiKategori($conn);
$kategoriTersedia = ambilKomposisiKategori($conn, "AND b.stok > 0");
$kategoriStokRendah = ambilKomposisiKategori($conn, "AND b.stok < $stokMinimum");

// Chart 3: Top barang keluar berdasarkan transaksi.
function ambilTopBarangKeluar($conn, $filterWaktu)
{
    if ($filterWaktu === 'minggu_ini') {
        $kondisiWaktu = "AND YEARWEEK(t.tanggal_transaksi, 1) = YEARWEEK(CURDATE(), 1)";
    } elseif ($filterWaktu === 'tahun_ini') {
        $kondisiWaktu = "AND YEAR(t.tanggal_transaksi) = YEAR(CURDATE())";
    } else {
        $kondisiWaktu = "AND MONTH(t.tanggal_transaksi) = MONTH(CURDATE()) AND YEAR(t.tanggal_transaksi) = YEAR(CURDATE())";
    }

    $sql = "
        SELECT
            COALESCE(b.nama_barang, dt.nama_barang_snapshot, '[Barang sudah dihapus]') AS label,
            SUM(dt.qty) AS total
        FROM detail_transaksi dt
        JOIN transaksi t ON dt.id_transaksi = t.id
        LEFT JOIN barang b ON dt.id_barang = b.id
        WHERE t.jenis_transaksi = 'keluar'
        AND t.is_delete = 0
        $kondisiWaktu
        GROUP BY COALESCE(b.nama_barang, dt.nama_barang_snapshot, '[Barang sudah dihapus]')
        ORDER BY total DESC
        LIMIT 5
    ";

    $labels = [];
    $data = [];

    foreach (ambilDataChart($conn, $sql) as $row) {
        $labels[] = $row['label'];
        $data[] = (int) $row['total'];
    }

    if (count($labels) === 0) {
        $labels = ['Tidak ada data'];
        $data = [0];
    }

    return ['labels' => $labels, 'data' => $data];
}

$topMingguIni = ambilTopBarangKeluar($conn, 'minggu_ini');
$topBulanIni = ambilTopBarangKeluar($conn, 'bulan_ini');
$topTahunIni = ambilTopBarangKeluar($conn, 'tahun_ini');

$chartData = [
    'tren' => [
        '7_hari' => [
            'labels' => $labels7Hari,
            'masuk' => $dataMasuk7Hari,
            'keluar' => $dataKeluar7Hari
        ],
        'bulan_ini' => [
            'labels' => $labelsBulanIni,
            'masuk' => $dataMasukBulanIni,
            'keluar' => $dataKeluarBulanIni
        ],
        'tahun_ini' => [
            'labels' => $labelsTahunIni,
            'masuk' => $dataMasukTahunIni,
            'keluar' => $dataKeluarTahunIni
        ]
    ],
    'kategori' => [
        'semua' => $kategoriSemua,
        'tersedia' => $kategoriTersedia,
        'stok_rendah' => $kategoriStokRendah
    ],
    'top' => [
        'minggu_ini' => $topMingguIni,
        'bulan_ini' => $topBulanIni,
        'tahun_ini' => $topTahunIni
    ]
];
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
            z-index: 1045;
            transition: transform 0.25s ease;
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
            justify-content: flex-end;
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

        .sidebar-backdrop {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(17, 24, 39, 0.45);
            z-index: 1040;
            opacity: 0;
            transition: opacity 0.2s ease;
        }

        .sidebar-backdrop.show {
            display: block;
            opacity: 1;
        }

        .mobile-menu-btn {
            display: none;
        }

        @media (min-width: 769px) {
            .sidebar { transform: none !important; }
            .sidebar-backdrop { display: none !important; }
            .mobile-menu-btn { display: none !important; }
        }

        @media (max-width: 768px) {
            body.sidebar-open { overflow: hidden; }
            .sidebar {
                width: 280px;
                max-width: 85vw;
                transform: translateX(-100%);
                box-shadow: 12px 0 30px rgba(15, 23, 42, 0.16);
            }
            .sidebar.sidebar-open { transform: translateX(0); }
            .main-content { margin-left: 0 !important; }
            .top-navbar { padding: 0 16px; justify-content: space-between; }
            .mobile-menu-btn { display: inline-flex !important; align-items: center; justify-content: center; }
            main { padding: 24px 16px !important; }
            .table-wrapper, .table-responsive { overflow-x: auto; }
        }

    </style>
</head>
<body>

    <div class="sidebar d-flex flex-column justify-content-between">
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

    <div class="sidebar-backdrop" id="sidebarBackdrop"></div>

    <div class="main-content">

        <header class="top-navbar">
            <button type="button" class="btn btn-light mobile-menu-btn" id="sidebarToggle" aria-label="Buka menu">
                <i class="fa-solid fa-bars"></i>
            </button>
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

            <div class="card hifi-card p-4 mb-4">
                <div class="row align-items-center mb-4 g-3">
                    <div class="col-12 col-md-5">
                        <h5 class="fw-bold mb-1" id="chartTitle">Tren Transaksi</h5>
                        <p class="text-muted mb-0 small" id="chartSubtitle">Pergerakan barang masuk dan keluar.</p>
                    </div>

                    <div class="col-12 col-md-7 d-flex justify-content-md-end gap-2 flex-wrap">
                        <select class="form-select form-select-sm w-auto fw-medium" id="pilihChart">
                            <option value="tren" selected>Grafik: Tren Transaksi (Garis)</option>
                            <option value="kategori">Grafik: Komposisi Kategori (Lingkaran)</option>
                            <option value="top">Grafik: Top Barang Keluar (Batang)</option>
                        </select>

                        <select class="form-select form-select-sm w-auto fw-medium" id="pilihFilter">
                            </select>
                    </div>
                </div>

                <div class="chart-container d-flex justify-content-center" style="height: 350px; position: relative;">
                    <div id="wrapper-tren" class="w-100 h-100"><canvas id="lineChart"></canvas></div>
                    <div id="wrapper-kategori" class="w-100 h-100 d-none" style="max-width: 400px;"><canvas id="doughnutChart"></canvas></div>
                    <div id="wrapper-top" class="w-100 h-100 d-none"><canvas id="barChart"></canvas></div>
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
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <script>
        (function () {
            const sidebar = document.querySelector('.sidebar');
            const toggleBtn = document.getElementById('sidebarToggle');
            const backdrop = document.getElementById('sidebarBackdrop');

            if (!sidebar || !toggleBtn || !backdrop) return;

            function bukaSidebar() {
                sidebar.classList.add('sidebar-open');
                backdrop.classList.add('show');
                document.body.classList.add('sidebar-open');
            }

            function tutupSidebar() {
                sidebar.classList.remove('sidebar-open');
                backdrop.classList.remove('show');
                document.body.classList.remove('sidebar-open');
            }

            toggleBtn.addEventListener('click', function () {
                sidebar.classList.contains('sidebar-open') ? tutupSidebar() : bukaSidebar();
            });

            backdrop.addEventListener('click', tutupSidebar);

            document.addEventListener('keydown', function (event) {
                if (event.key === 'Escape') tutupSidebar();
            });

            window.addEventListener('resize', function () {
                if (window.innerWidth > 768) tutupSidebar();
            });

            document.querySelectorAll('.sidebar a').forEach(function (link) {
                link.addEventListener('click', function () {
                    if (window.innerWidth <= 768 && link.getAttribute('data-bs-toggle') !== 'collapse') {
                        tutupSidebar();
                    }
                });
            });
        })();
    </script>

    <script>
        document.addEventListener("DOMContentLoaded", function() {

            Chart.defaults.font.family = "'Inter', sans-serif";
            Chart.defaults.color = '#6c757d';

            // Data chart berasal dari hasil query database PHP, bukan dummy.
            const dataChart = <?= json_encode($chartData, JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK) ?>;

            const warnaChart = ['#4f46e5', '#0dcaf0', '#ffc107', '#198754', '#dc3545', '#6f42c1', '#fd7e14', '#adb5bd'];

            // ==========================================
            // 1. INIT SEMUA GRAFIK DENGAN DATA DEFAULT
            // ==========================================
            const ctxLine = document.getElementById('lineChart').getContext('2d');
            let lineChart = new Chart(ctxLine, {
                type: 'line',
                data: {
                    labels: dataChart.tren.bulan_ini.labels,
                    datasets: [
                        {
                            label: 'Masuk',
                            data: dataChart.tren.bulan_ini.masuk,
                            borderColor: '#198754',
                            backgroundColor: 'rgba(25, 135, 84, 0.1)',
                            borderWidth: 2,
                            tension: 0.3,
                            fill: true
                        },
                        {
                            label: 'Keluar',
                            data: dataChart.tren.bulan_ini.keluar,
                            borderColor: '#dc3545',
                            backgroundColor: 'rgba(220, 53, 69, 0.1)',
                            borderWidth: 2,
                            tension: 0.3,
                            fill: true
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { position: 'top' },
                        tooltip: { mode: 'index', intersect: false }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: { precision: 0 }
                        }
                    }
                }
            });

            const ctxDoughnut = document.getElementById('doughnutChart').getContext('2d');
            let doughnutChart = new Chart(ctxDoughnut, {
                type: 'doughnut',
                data: {
                    labels: dataChart.kategori.semua.labels,
                    datasets: [{
                        data: dataChart.kategori.semua.data,
                        backgroundColor: warnaChart,
                        borderWidth: 0
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '65%',
                    plugins: {
                        legend: { position: 'bottom' }
                    }
                }
            });

            const ctxBar = document.getElementById('barChart').getContext('2d');
            let barChart = new Chart(ctxBar, {
                type: 'bar',
                data: {
                    labels: dataChart.top.bulan_ini.labels,
                    datasets: [{
                        label: 'Total Keluar',
                        data: dataChart.top.bulan_ini.data,
                        backgroundColor: '#4f46e5',
                        borderRadius: 6
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { display: false } },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: { precision: 0 }
                        }
                    }
                }
            });

            // ==========================================
            // 2. KONFIGURASI CHART & FILTER
            // ==========================================
            const elemenPilihChart = document.getElementById('pilihChart');
            const elemenPilihFilter = document.getElementById('pilihFilter');
            const chartTitle = document.getElementById('chartTitle');
            const chartSubtitle = document.getElementById('chartSubtitle');

            const configChart = {
                'tren': {
                    title: 'Tren Transaksi',
                    subtitle: 'Pergerakan jumlah barang masuk dan keluar berdasarkan periode.',
                    wrapperId: 'wrapper-tren',
                    defaultFilter: 'bulan_ini',
                    filters: [
                        { val: '7_hari', text: '7 Hari Terakhir' },
                        { val: 'bulan_ini', text: 'Bulan Ini' },
                        { val: 'tahun_ini', text: 'Tahun Ini' }
                    ]
                },
                'kategori': {
                    title: 'Komposisi Kategori',
                    subtitle: 'Perbandingan jumlah barang berdasarkan kategori.',
                    wrapperId: 'wrapper-kategori',
                    defaultFilter: 'semua',
                    filters: [
                        { val: 'semua', text: 'Semua Barang Aktif' },
                        { val: 'tersedia', text: 'Stok Tersedia (> 0)' },
                        { val: 'stok_rendah', text: 'Stok Rendah (< <?= $stokMinimum ?>)' }
                    ]
                },
                'top': {
                    title: 'Top 5 Barang Keluar',
                    subtitle: 'Barang yang paling banyak keluar berdasarkan periode.',
                    wrapperId: 'wrapper-top',
                    defaultFilter: 'bulan_ini',
                    filters: [
                        { val: 'minggu_ini', text: 'Minggu Ini' },
                        { val: 'bulan_ini', text: 'Bulan Ini' },
                        { val: 'tahun_ini', text: 'Tahun Ini' }
                    ]
                }
            };

            function tampilkanWrapper(chartType) {
                document.getElementById('wrapper-tren').classList.add('d-none');
                document.getElementById('wrapper-kategori').classList.add('d-none');
                document.getElementById('wrapper-top').classList.add('d-none');
                document.getElementById(configChart[chartType].wrapperId).classList.remove('d-none');
            }

            function isiDropdownFilter(chartType) {
                const conf = configChart[chartType];
                elemenPilihFilter.innerHTML = '';

                conf.filters.forEach(f => {
                    const option = document.createElement('option');
                    option.value = f.val;
                    option.textContent = f.text;
                    if (f.val === conf.defaultFilter) option.selected = true;
                    elemenPilihFilter.appendChild(option);
                });
            }

            function updateDataChart(chartType, filterValue) {
                if (chartType === 'tren') {
                    const data = dataChart.tren[filterValue];
                    lineChart.data.labels = data.labels;
                    lineChart.data.datasets[0].data = data.masuk;
                    lineChart.data.datasets[1].data = data.keluar;
                    lineChart.update();
                }

                if (chartType === 'kategori') {
                    const data = dataChart.kategori[filterValue];
                    doughnutChart.data.labels = data.labels;
                    doughnutChart.data.datasets[0].data = data.data;
                    doughnutChart.update();
                }

                if (chartType === 'top') {
                    const data = dataChart.top[filterValue];
                    barChart.data.labels = data.labels;
                    barChart.data.datasets[0].data = data.data;
                    barChart.update();
                }
            }

            function updateChartView(chartType) {
                const conf = configChart[chartType];

                chartTitle.textContent = conf.title;
                chartSubtitle.textContent = conf.subtitle;
                tampilkanWrapper(chartType);
                isiDropdownFilter(chartType);
                updateDataChart(chartType, conf.defaultFilter);
            }

            updateChartView('tren');

            elemenPilihChart.addEventListener('change', function(e) {
                updateChartView(e.target.value);
            });

            elemenPilihFilter.addEventListener('change', function(e) {
                updateDataChart(elemenPilihChart.value, e.target.value);
            });

        });
    </script>

</body>
</html>
