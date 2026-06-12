<?php
session_start();
require 'config/db_connect.php';

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: index.php");
    exit;
}

$nama_login = $_SESSION['username'] ?? 'User';
$role_login = $_SESSION['role'] ?? 'user';

// ==========================================
// INISIALISASI VARIABEL & FILTER
// ==========================================
// Set default filter ke bulan berjalan jika tidak ada input dari user
$tgl_awal = isset($_GET['tgl_awal']) ? $_GET['tgl_awal'] : date('Y-m-01');
$tgl_akhir = isset($_GET['tgl_akhir']) ? $_GET['tgl_akhir'] : date('Y-m-t');
$kategori = isset($_GET['kategori']) ? $_GET['kategori'] : '';
$keyword = isset($_GET['keyword']) ? $_GET['keyword'] : '';

// Mengambil data kategori untuk opsi dropdown filter
$queryKategori = $conn->query("SELECT id, nama_kategori FROM kategori_barang WHERE is_delete = 0 ORDER BY nama_kategori ASC");

// ==========================================
// ALGORITMA PENARIKAN DATA LAPORAN
// ==========================================
// Query dasar menggabungkan transaksi, detail, barang, dan kategori
$sql = "SELECT
            t.tanggal_transaksi as tanggal,
            COALESCE(k.nama_kategori, dt.nama_kategori_snapshot, '-') as kategori,
            COALESCE(b.nama_barang, dt.nama_barang_snapshot, '[Barang sudah dihapus]') as nama_barang,
            dt.stok_sebelum,
            IF(t.jenis_transaksi = 'masuk', dt.qty, 0) as masuk,
            IF(t.jenis_transaksi = 'keluar', dt.qty, 0) as keluar,
            dt.stok_sesudah,
            COALESCE(b.satuan, dt.satuan_snapshot, '-') as satuan
        FROM detail_transaksi dt
        JOIN transaksi t ON dt.id_transaksi = t.id
        LEFT JOIN barang b ON dt.id_barang = b.id
        LEFT JOIN kategori_barang k ON b.id_kategori = k.id
        WHERE t.tanggal_transaksi BETWEEN ? AND ?
        AND t.is_delete = 0";

// Parameter untuk prepared statement
$params = [$tgl_awal, $tgl_akhir];
$types = "ss";

// Menambahkan filter kategori jika dipilih
if ($kategori != '') {
    $sql .= " AND b.id_kategori = ?";
    $params[] = $kategori;
    $types .= "i";
}

// Menambahkan filter kata kunci jika diisi
if ($keyword != '') {
    $sql .= " AND COALESCE(b.nama_barang, dt.nama_barang_snapshot) LIKE ?";
    $params[] = "%" . $keyword . "%";
    $types .= "s";
}

// Urutkan data dari yang terbaru
$sql .= " ORDER BY t.tanggal_transaksi DESC, t.id DESC";

// Eksekusi Query
$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

// Variabel untuk menampung summary/ringkasan di bawah tabel
$total_data = 0;
$total_masuk = 0;
$total_keluar = 0;
$data_laporan = [];

// Memproses hasil query
while ($row = $result->fetch_assoc()) {
    $data_laporan[] = $row;
    $total_data++;
    $total_masuk += $row['masuk'];
    $total_keluar += $row['keluar'];
}
$stmt->close();

// ==========================================
// EXPORT EXCEL (HANYA TABEL LAPORAN)
// ==========================================
if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    $nama_file = 'laporan_persediaan_' . date('Ymd_His') . '.xls';

    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $nama_file . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    echo "<table border='1'>";
    echo "<thead>";
    echo "<tr>";
    echo "<th>No.</th>";
    echo "<th>Tanggal</th>";
    echo "<th>Kategori</th>";
    echo "<th>Nama Barang</th>";
    echo "<th>Stok Awal</th>";
    echo "<th>Barang Masuk</th>";
    echo "<th>Barang Keluar</th>";
    echo "<th>Stok Akhir</th>";
    echo "<th>Satuan</th>";
    echo "</tr>";
    echo "</thead>";
    echo "<tbody>";

    if (count($data_laporan) > 0) {
        $no_export = 1;
        foreach ($data_laporan as $row) {
            echo "<tr>";
            echo "<td>" . $no_export++ . "</td>";
            echo "<td>" . date('d/m/Y', strtotime($row['tanggal'])) . "</td>";
            echo "<td>" . htmlspecialchars($row['kategori'] ?? '-') . "</td>";
            echo "<td>" . htmlspecialchars($row['nama_barang']) . "</td>";
            echo "<td>" . $row['stok_sebelum'] . "</td>";
            echo "<td>" . ($row['masuk'] > 0 ? $row['masuk'] : '-') . "</td>";
            echo "<td>" . ($row['keluar'] > 0 ? $row['keluar'] : '-') . "</td>";
            echo "<td>" . $row['stok_sesudah'] . "</td>";
            echo "<td>" . htmlspecialchars($row['satuan']) . "</td>";
            echo "</tr>";
        }
    } else {
        echo "<tr><td colspan='9'>Tidak ada data transaksi ditemukan pada periode/filter ini.</td></tr>";
    }

    echo "</tbody>";
    echo "</table>";
    exit;
}

// Query string untuk tombol export agar filter yang aktif tetap terbawa
$export_query = http_build_query([
    'tgl_awal' => $tgl_awal,
    'tgl_akhir' => $tgl_akhir,
    'kategori' => $kategori,
    'keyword' => $keyword
]);
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Laporan - Sistem Manajemen Persediaan</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <style>
        :root {
            --primary: #1f2937;
            --bg-color: #f9fafb;
            --border-color: #e5e7eb;
            --text-main: #111827;
            --text-muted: #6b7280;
        }

        body { background-color: var(--bg-color); font-family: 'Inter', sans-serif; color: var(--text-main); }

        /* Sidebar Styling */
        .sidebar { width: 260px; min-height: 100vh; background-color: #ffffff; border-right: 1px solid var(--border-color); position: fixed; left: 0; top: 0; z-index: 1000; }
        .sidebar-logo { height: 70px; display: flex; align-items: center; padding: 0 24px; font-weight: 800; font-size: 1.25rem; border-bottom: 1px solid var(--border-color); letter-spacing: 0.5px;}
        .nav-item-custom { padding: 12px 24px; color: #4b5563; text-decoration: none; display: flex; align-items: center; font-weight: 500; transition: all 0.2s; }
        .nav-item-custom i { width: 24px; font-size: 1.1rem; margin-right: 10px; }
        .nav-item-custom:hover { background-color: #f3f4f6; color: #000; }
        .nav-item-custom.active { background-color: #f3f4f6; color: #000; font-weight: 600; border-right: 3px solid #000; }

        .main-content { margin-left: 260px; min-height: 100vh; display: flex; flex-direction: column; }
        .top-navbar { height: 70px; background-color: #ffffff; border-bottom: 1px solid var(--border-color); display: flex; align-items: center; justify-content: space-between; padding: 0 30px; }

        /* General Card Hi-Fi Styling */
        .hifi-card {
            background: #ffffff;
            border: 1px solid var(--border-color);
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
            margin-bottom: 24px;
        }

        /* Top Header Box */
        .header-box { padding: 20px 24px; display: flex; align-items: center; gap: 16px; }
        .header-icon { width: 48px; height: 48px; border-radius: 50%; border: 1px solid var(--border-color); display: flex; align-items: center; justify-content: center; font-size: 1.2rem; color: var(--primary); }

        /* Filter Section */
        .filter-section { padding: 20px 24px; }
        .form-label { font-size: 0.85rem; font-weight: 600; color: var(--text-muted); margin-bottom: 6px; }
        .form-control, .form-select { border-radius: 8px; border: 1px solid #d1d5db; font-size: 0.9rem; padding: 10px 14px; }
        .form-control:focus, .form-select:focus { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(0, 0, 0, 0.05); }

        /* Buttons */
        .btn-custom { padding: 10px 20px; border-radius: 8px; font-size: 0.9rem; font-weight: 600; display: inline-flex; align-items: center; gap: 8px; transition: all 0.2s; }
        .btn-primary-custom { background-color: var(--primary); color: #fff; border: 1px solid var(--primary); }
        .btn-primary-custom:hover { background-color: #000; border-color: #000; color: #fff; }
        .btn-outline-custom { background-color: #fff; border: 1px solid #d1d5db; color: var(--text-main); }
        .btn-outline-custom:hover { background-color: #f3f4f6; color: #000; }

        /* Table Customization */
        .table-wrapper { overflow-x: auto; padding: 0; border-radius: 12px; }
        .table-custom { margin-bottom: 0; font-size: 0.9rem; }
        .table-custom thead th { background-color: #f9fafb; border-bottom: 1px solid var(--border-color); color: var(--text-muted); font-weight: 600; padding: 16px; text-align: center; white-space: nowrap; }
        .table-custom tbody td { padding: 16px; vertical-align: middle; border-bottom: 1px solid var(--border-color); text-align: center; }
        .table-custom tbody tr:hover { background-color: #f9fafb; }
        .table-custom tbody td.text-start { text-align: left; }

        /* Summary Cards */
        .summary-card { padding: 20px; display: flex; align-items: center; gap: 16px; height: 100%; }
        .summary-icon { width: 40px; height: 40px; border-radius: 8px; background: #f3f4f6; display: flex; align-items: center; justify-content: center; font-size: 1.1rem; }
        .summary-val { font-size: 1.5rem; font-weight: 700; margin: 0; line-height: 1; }
        .summary-label { font-size: 0.8rem; color: var(--text-muted); margin-top: 4px; }


        /* Report single-section layout */
        .report-section {
            background: #ffffff;
            border: 1px solid var(--border-color);
            border-radius: 14px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
            overflow: hidden;
        }
        .report-section-header {
            padding: 24px;
            display: flex;
            align-items: center;
            gap: 16px;
            border-bottom: 1px solid var(--border-color);
            background: #ffffff;
        }
        .report-block {
            padding: 24px;
            border-bottom: 1px solid var(--border-color);
        }
        .section-title-mini {
            margin-bottom: 16px;
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        .section-title-mini span {
            font-weight: 700;
            color: var(--text-main);
            font-size: 1rem;
        }
        .section-title-mini small {
            color: var(--text-muted);
            font-size: 0.86rem;
        }
        .summary-card-clean {
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 20px;
            display: flex;
            align-items: center;
            gap: 16px;
            height: 100%;
            background: #ffffff;
        }
        .report-table-box {
            border: 1px solid var(--border-color);
            border-radius: 12px;
            overflow-x: auto;
        }
        .report-note {
            margin-top: 18px;
            padding: 16px;
            border: 1px solid var(--border-color);
            border-radius: 12px;
            background: #f9fafb;
            display: flex;
            gap: 14px;
            align-items: flex-start;
        }

        .print-header {
            display: none;
        }

        @media print {
            @page {
                size: A4 landscape;
                margin: 12mm;
            }

            body {
                background: #ffffff !important;
                color: #000000 !important;
                font-size: 12px;
            }

            .sidebar,
            .top-navbar,
            main > h2,
            .report-section-header,
            .report-filter-block,
            .report-note,
            .btn-custom,
            .section-title-mini small,
            .no-print {
                display: none !important;
            }

            .main-content {
                margin-left: 0 !important;
                min-height: auto !important;
                display: block !important;
            }

            main {
                padding: 0 !important;
            }

            .report-section {
                border: none !important;
                box-shadow: none !important;
                border-radius: 0 !important;
                overflow: visible !important;
            }

            .print-header {
                display: block !important;
                margin-bottom: 16px;
                padding-bottom: 10px;
                border-bottom: 1px solid #000;
            }

            .print-header h2 {
                font-size: 20px;
                margin: 0 0 6px 0;
                font-weight: 700;
            }

            .print-header p {
                margin: 0;
                font-size: 12px;
            }

            .report-block {
                padding: 0 !important;
                border-bottom: none !important;
                margin-bottom: 16px !important;
            }

            .section-title-mini {
                margin-bottom: 8px !important;
            }

            .section-title-mini span {
                font-size: 13px !important;
            }

            .report-block .row {
                display: flex !important;
                flex-wrap: nowrap !important;
                gap: 10px !important;
                margin-left: 0 !important;
                margin-right: 0 !important;
            }

            .report-block .row > .col-md-4 {
                flex: 1 1 0 !important;
                width: 33.333% !important;
                max-width: 33.333% !important;
                padding-left: 0 !important;
                padding-right: 0 !important;
            }

            .summary-card-clean {
                box-shadow: none !important;
                border: 1px solid #000 !important;
                padding: 10px !important;
                min-height: 56px;
                width: 100% !important;
            }

            .summary-icon {
                display: none !important;
            }

            .summary-val {
                font-size: 18px !important;
            }

            .summary-label {
                font-size: 11px !important;
                color: #000 !important;
            }

            .report-table-box {
                border: none !important;
                border-radius: 0 !important;
                overflow: visible !important;
            }

            .table-wrapper {
                overflow: visible !important;
            }

            .table-custom {
                width: 100% !important;
                border-collapse: collapse !important;
                font-size: 11px !important;
            }

            .table-custom thead th,
            .table-custom tbody td {
                border: 1px solid #000 !important;
                padding: 6px !important;
                color: #000 !important;
                background: #ffffff !important;
            }

            .text-success,
            .text-danger,
            .text-muted {
                color: #000 !important;
            }
        }

        @media (max-width: 768px) {
            .report-section-header,
            .report-block {
                padding: 18px;
            }
        }
    </style>
</head>
<body>

    <div class="sidebar d-none d-md-block d-flex flex-column justify-content-between">
        <div>
            <div class="sidebar-logo text-dark">LOGO WEB</div>
            <div class="mt-3">
                <a href="dashboard.php" class="nav-item-custom">
                    <i class="fa-solid fa-house"></i> Dashboard
                </a>
                <a href="#persediaan" class="nav-item-custom" data-bs-toggle="collapse">
                    <i class="fa-solid fa-box"></i> Persediaan Barang
                </a>
                <div class="collapse" id="persediaan">
                    <?php $jenis_form = ''; ?>
                    <a href="form_barang.php?jenis=masuk" class="nav-item-custom ps-5 py-2 text-muted" style="font-size: 0.9rem;">
                        <i class="fa-regular fa-circle" style="font-size: 0.5rem;"></i> Form Barang Masuk
                    </a>
                    <a href="form_barang.php?jenis=keluar" class="nav-item-custom ps-5 py-2 text-muted" style="font-size: 0.9rem;">
                        <i class="fa-regular fa-circle" style="font-size: 0.5rem;"></i> Form Barang Keluar
                    </a>
                </div>
                <?php if ($role_login === 'admin'): ?>
                <a href="master.php" class="nav-item-custom">
                    <i class="fa-solid fa-database"></i> Master
                </a>
                <?php endif; ?>
                <a href="laporan.php" class="nav-item-custom active">
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
            <button class="btn btn-light d-md-none"><i class="fa-solid fa-bars"></i></button>
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
            <h2 class="fw-bold mb-4">Laporan</h2>

            <section class="report-section">
                <div class="report-section-header">
                    <div class="header-icon">
                        <i class="fa-regular fa-file-alt"></i>
                    </div>
                    <div>
                        <h6 class="fw-bold mb-1">Laporan Persediaan Barang</h6>
                        <p class="text-muted mb-0" style="font-size: 0.9rem;">Lihat ringkasan, atur filter, lalu cek detail transaksi barang masuk dan keluar.</p>
                    </div>
                </div>

                <div class="print-header">
                    <h2>Laporan Persediaan Barang</h2>
                    <p>Periode: <?= date('d/m/Y', strtotime($tgl_awal)) ?> sampai <?= date('d/m/Y', strtotime($tgl_akhir)) ?></p>
                    <?php if ($keyword != '' || $kategori != ''): ?>
                        <p>Filter aktif: <?= $keyword != '' ? 'Kata kunci: ' . htmlspecialchars($keyword) : '' ?> <?= $kategori != '' ? 'Kategori dipilih' : '' ?></p>
                    <?php endif; ?>
                </div>

                <!-- 1. Statistik laporan -->
                <div class="report-block">
                    <div class="section-title-mini">
                        <span>Ringkasan Laporan</span>
                        <small>Data dihitung berdasarkan filter yang sedang aktif.</small>
                    </div>

                    <div class="row g-3">
                        <div class="col-md-4">
                            <div class="summary-card-clean">
                                <div class="summary-icon"><i class="fa-solid fa-list-ul"></i></div>
                                <div>
                                    <p class="summary-val"><?= number_format($total_data, 0, ',', '.') ?></p>
                                    <div class="summary-label">Total Data</div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="summary-card-clean">
                                <div class="summary-icon text-success bg-success bg-opacity-10"><i class="fa-solid fa-arrow-down"></i></div>
                                <div>
                                    <p class="summary-val"><?= number_format($total_masuk, 0, ',', '.') ?></p>
                                    <div class="summary-label">Total Barang Masuk</div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="summary-card-clean">
                                <div class="summary-icon text-danger bg-danger bg-opacity-10"><i class="fa-solid fa-arrow-up"></i></div>
                                <div>
                                    <p class="summary-val"><?= number_format($total_keluar, 0, ',', '.') ?></p>
                                    <div class="summary-label">Total Barang Keluar</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- 2. Form filter laporan -->
                <div class="report-block report-filter-block">
                    <div class="section-title-mini">
                        <span>Filter Laporan</span>
                        <small>Pilih rentang tanggal, kategori, atau nama barang.</small>
                    </div>

                    <form method="GET" action="laporan.php">
                        <div class="row g-3 align-items-end">
                            <div class="col-md-2">
                                <label class="form-label">Tanggal Awal</label>
                                <input type="date" name="tgl_awal" class="form-control" value="<?= htmlspecialchars($tgl_awal) ?>">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Tanggal Akhir</label>
                                <input type="date" name="tgl_akhir" class="form-control" value="<?= htmlspecialchars($tgl_akhir) ?>">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Kategori</label>
                                <select name="kategori" class="form-select">
                                    <option value="">Semua Kategori</option>
                                    <?php while($rowKat = $queryKategori->fetch_assoc()): ?>
                                        <option value="<?= $rowKat['id'] ?>" <?= $kategori == $rowKat['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($rowKat['nama_kategori']) ?>
                                        </option>
                                    <?php endwhile; ?>
                                </select>
                            </div>
                            <div class="col-md-5">
                                <label class="form-label">Kata Kunci</label>
                                <div class="position-relative">
                                    <input type="text" name="keyword" class="form-control pe-5" placeholder="Cari nama barang..." value="<?= htmlspecialchars($keyword) ?>">
                                    <i class="fa-solid fa-magnifying-glass position-absolute text-muted" style="right: 15px; top: 12px;"></i>
                                </div>
                            </div>
                        </div>

                        <div class="d-flex justify-content-end gap-2 mt-4 pt-3 border-top">
                            <button type="submit" class="btn-custom btn-primary-custom">
                                <i class="fa-solid fa-filter"></i> Tampilkan
                            </button>
                            <button type="button" class="btn-custom btn-outline-custom" onclick="window.print()">
                                <i class="fa-solid fa-print"></i> Cetak Laporan
                            </button>
                            <a href="laporan.php?<?= $export_query ?>&export=excel" class="btn-custom btn-outline-custom text-decoration-none">
                                <i class="fa-regular fa-file-excel"></i> Export Excel
                            </a>
                        </div>
                    </form>
                </div>

                <!-- 3. Informasi hasil laporan -->
                <div class="report-block border-bottom-0 pb-0">
                    <div class="section-title-mini">
                        <span>Hasil Laporan</span>
                        <small>Detail perubahan stok berdasarkan transaksi.</small>
                    </div>

                    <div class="table-wrapper report-table-box">
                        <table class="table table-custom">
                            <thead>
                                <tr>
                                    <th>No.</th>
                                    <th>Tanggal</th>
                                    <th>Kategori</th>
                                    <th class="text-start">Nama Barang</th>
                                    <th>Stok Awal</th>
                                    <th class="text-success">Barang Masuk</th>
                                    <th class="text-danger">Barang Keluar</th>
                                    <th>Stok Akhir</th>
                                    <th>Satuan</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (count($data_laporan) > 0): ?>
                                    <?php $no = 1; foreach ($data_laporan as $row): ?>
                                    <tr>
                                        <td><?= $no++ ?></td>
                                        <td><?= date('d/m/Y', strtotime($row['tanggal'])) ?></td>
                                        <td><?= htmlspecialchars($row['kategori'] ?? '-') ?></td>
                                        <td class="text-start fw-medium"><?= htmlspecialchars($row['nama_barang']) ?></td>
                                        <td class="text-muted"><?= $row['stok_sebelum'] ?></td>
                                        <td class="text-success fw-medium"><?= $row['masuk'] > 0 ? '+'.$row['masuk'] : '-' ?></td>
                                        <td class="text-danger fw-medium"><?= $row['keluar'] > 0 ? '-'.$row['keluar'] : '-' ?></td>
                                        <td class="fw-bold"><?= $row['stok_sesudah'] ?></td>
                                        <td class="text-muted"><?= htmlspecialchars($row['satuan']) ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="9" class="py-5 text-muted">Tidak ada data transaksi ditemukan pada periode/filter ini.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="report-note">
                        <div class="summary-icon bg-transparent border"><i class="fa-solid fa-circle-info text-muted"></i></div>
                        <div>
                            <h6 class="fw-bold mb-1" style="font-size: 0.95rem;">Catatan</h6>
                            <p class="text-muted mb-0" style="font-size: 0.85rem; line-height: 1.5;">Laporan menampilkan data berdasarkan filter yang dipilih. Pastikan rentang tanggal sudah sesuai sebelum mencetak atau mengunduh file Excel untuk keperluan audit.</p>
                        </div>
                    </div>
                </div>
            </section>
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
