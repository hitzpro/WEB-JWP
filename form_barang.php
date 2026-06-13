<?php
session_start();
require 'config/db_connect.php';

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: index.php");
    exit;
}

$id_user = (int) $_SESSION['id'];
$nama_login = $_SESSION['username'] ?? 'User';
$role_login = $_SESSION['role'] ?? 'user';

$status = '';
$message = '';

$jenis_form = isset($_GET['jenis']) && $_GET['jenis'] == 'keluar' ? 'keluar' : 'masuk';
$judul_form = $jenis_form == 'masuk' ? 'Form Barang Masuk' : 'Form Barang Keluar';
$deskripsi_form = $jenis_form == 'masuk'
    ? 'Isi barang yang diterima, jumlahnya, dan sumber barang.'
    : 'Isi barang yang keluar, jumlahnya, dan tujuan penggunaan barang.';
$label_sumber = $jenis_form == 'masuk' ? 'Sumber Barang' : 'Tujuan Barang';
$placeholder_sumber = $jenis_form == 'masuk' ? 'Contoh: Koperasi pusat' : 'Contoh: Ruang Guru / Kelas 8A';
$teks_bantuan = $jenis_form == 'masuk'
    ? 'Untuk transaksi masuk, stok barang akan bertambah sesuai jumlah yang kamu isi.'
    : 'Untuk transaksi keluar, stok barang akan berkurang sesuai jumlah yang kamu isi.';

// ==========================================
// PROSES SIMPAN TRANSAKSI BARANG
// ==========================================
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $jenis_transaksi = $_POST['jenis_transaksi'] ?? $jenis_form;
    $id_barang = isset($_POST['id_barang']) ? (int) $_POST['id_barang'] : 0;
    $qty = isset($_POST['qty']) ? (int) $_POST['qty'] : 0;
    $tanggal = trim($_POST['tanggal'] ?? date('Y-m-d'));
    $keterangan = trim($_POST['keterangan'] ?? '');

    if (!in_array($jenis_transaksi, ['masuk', 'keluar']) || $jenis_transaksi !== $jenis_form) {
        $status = 'error';
        $message = 'Jenis transaksi tidak valid.';
    } elseif ($id_barang <= 0) {
        $status = 'error';
        $message = 'Barang wajib dipilih.';
    } elseif ($qty <= 0) {
        $status = 'error';
        $message = 'Jumlah barang harus lebih dari 0.';
    } elseif ($keterangan == '') {
        $status = 'error';
        $message = $label_sumber . ' wajib diisi.';
    } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal)) {
        $status = 'error';
        $message = 'Format tanggal tidak valid.';
    } else {
        try {
            // Database transaction dipakai agar data transaksi, detail, dan stok tetap sinkron.
            $conn->begin_transaction();

            // Query mengambil stok barang dan mengunci baris agar tidak bentrok saat transaksi bersamaan.
            $sql_barang = "SELECT b.stok, b.kode_barang, b.nama_barang, b.satuan, COALESCE(k.nama_kategori, '-') AS nama_kategori
                FROM barang b
                LEFT JOIN kategori_barang k ON b.id_kategori = k.id
                WHERE b.id = ?
                AND b.is_delete = 0
                FOR UPDATE";
            $stmt = $conn->prepare($sql_barang);
            $stmt->bind_param("i", $id_barang);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows <= 0) {
                throw new Exception('Barang tidak ditemukan di database.');
            }

            $barang = $result->fetch_assoc();
            $stok_sebelum = (int) $barang['stok'];

            if ($jenis_transaksi == 'keluar' && $qty > $stok_sebelum) {
                throw new Exception('Stok tidak mencukupi. Stok ' . $barang['nama_barang'] . ' saat ini hanya ' . $stok_sebelum . '.');
            }

            $stok_sesudah = $jenis_transaksi == 'keluar'
                ? $stok_sebelum - $qty
                : $stok_sebelum + $qty;

            $no_transaksi = strtoupper('TRX-' . $jenis_transaksi . '-' . date('YmdHis') . '-' . random_int(100, 999));

            // Query menyimpan header transaksi.
            $sql_transaksi = "INSERT INTO transaksi (no_transaksi, jenis_transaksi, tanggal_transaksi, id_user, keterangan) VALUES (?, ?, ?, ?, ?)";
            $stmt_trx = $conn->prepare($sql_transaksi);
            $stmt_trx->bind_param("sssis", $no_transaksi, $jenis_transaksi, $tanggal, $id_user, $keterangan);
            $stmt_trx->execute();
            $id_transaksi = $conn->insert_id;

            // Query menyimpan detail transaksi barang.
            $kode_snapshot = $barang['kode_barang'];
            $nama_snapshot = $barang['nama_barang'];
            $kategori_snapshot = $barang['nama_kategori'];
            $satuan_snapshot = $barang['satuan'];

            // Query menyimpan detail transaksi beserta snapshot barang.
            // Snapshot dipakai agar laporan tetap bisa tampil walaupun barang dihapus permanen.
            $sql_detail = "INSERT INTO detail_transaksi (id_transaksi, id_barang, kode_barang_snapshot, nama_barang_snapshot, nama_kategori_snapshot, satuan_snapshot, qty, stok_sebelum, stok_sesudah) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt_dtl = $conn->prepare($sql_detail);
            $stmt_dtl->bind_param("iissssiii", $id_transaksi, $id_barang, $kode_snapshot, $nama_snapshot, $kategori_snapshot, $satuan_snapshot, $qty, $stok_sebelum, $stok_sesudah);
            $stmt_dtl->execute();

            // Query memperbarui stok barang.
            $sql_update_stok = "UPDATE barang SET stok = ? WHERE id = ?";
            $stmt_upd = $conn->prepare($sql_update_stok);
            $stmt_upd->bind_param("ii", $stok_sesudah, $id_barang);
            $stmt_upd->execute();

            $conn->commit();
            $status = 'success';
            $message = 'Transaksi barang ' . $jenis_transaksi . ' berhasil disimpan!';
        } catch (Exception $e) {
            $conn->rollback();
            $status = 'error';
            $message = 'Terjadi kesalahan: ' . $e->getMessage();
        }
    }
}

// ==========================================
// DATA MASTER BARANG UNTUK DROPDOWN
// ==========================================
$sql_list_barang = "SELECT id, kode_barang, nama_barang, stok, satuan FROM barang WHERE is_delete = 0 ORDER BY nama_barang ASC";
$queryBarang = $conn->query($sql_list_barang);

$barangData = [];
if ($queryBarang) {
    while ($row = $queryBarang->fetch_assoc()) {
        $barangData[$row['id']] = [
            'kode' => $row['kode_barang'],
            'nama' => $row['nama_barang'],
            'stok' => $row['stok'],
            'satuan' => $row['satuan']
        ];
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $judul_form ?> - Inventori</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <style>
        :root { --primary: #1f2937; --bg-color: #f9fafb; --border-color: #e5e7eb; --muted: #6b7280; }
        body { background-color: var(--bg-color); font-family: 'Inter', sans-serif; color: #1f2937; }
        .sidebar { width: 260px; min-height: 100vh; background-color: #ffffff; border-right: 1px solid var(--border-color); position: fixed; left: 0; top: 0; z-index: 1000; }
        .sidebar-logo { height: 70px; display: flex; align-items: center; padding: 0 24px; font-weight: 800; font-size: 1.25rem; border-bottom: 1px solid var(--border-color); }
        .nav-item-custom { padding: 12px 24px; color: #4b5563; text-decoration: none; display: flex; align-items: center; font-weight: 500; transition: all 0.2s; }
        .nav-item-custom i { width: 24px; font-size: 1.1rem; margin-right: 10px; }
        .nav-item-custom:hover { background-color: #f3f4f6; color: #000; }
        .nav-item-custom.active { background-color: #f3f4f6; color: #000; font-weight: 600; border-right: 3px solid #000; }
        .main-content { margin-left: 260px; min-height: 100vh; display: flex; flex-direction: column; }
        .top-navbar {
            height: 70px;
            background-color: #ffffff;
            border-bottom: 1px solid #e9ecef;
            display: flex;
            align-items: center;
            justify-content: flex-end;
            padding: 0 30px;
        }
        .form-card { background: #fff; border: 1px solid var(--border-color); border-radius: 16px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); overflow: hidden; }
        .help-box { border: 1px solid #dbeafe; background: #eff6ff; color: #1e3a8a; border-radius: 12px; padding: 14px 16px; display: flex; gap: 12px; align-items: flex-start; }
        .step-title { font-weight: 700; font-size: 1rem; margin-bottom: 4px; }
        .step-desc { color: var(--muted); font-size: 0.86rem; margin-bottom: 14px; }
        .section-box { padding: 24px; border-bottom: 1px solid var(--border-color); }
        .section-box:last-child { border-bottom: none; }
        .form-label { font-weight: 600; color: #374151; font-size: 0.9rem; margin-bottom: 7px; }
        .form-control, .form-select { border-radius: 10px; padding: 12px 14px; border: 1px solid #d1d5db; font-size: 0.95rem; color: #111827; }
        .form-control:focus, .form-select:focus { border-color: #111827; box-shadow: 0 0 0 3px rgba(17,24,39,0.07); }
        .readonly-field { background-color: #f3f4f6 !important; color: #6b7280 !important; }
        .preview-card { background: #f9fafb; border: 1px dashed #d1d5db; border-radius: 12px; padding: 16px; height: 100%; }
        .preview-label { font-size: 0.8rem; color: #6b7280; margin-bottom: 3px; }
        .preview-value { font-size: 1.05rem; font-weight: 700; }
        .preview-card.is-danger { background: #fef2f2; border-color: #ef4444; }
        .preview-card.is-danger .preview-label, .preview-card.is-danger .preview-value { color: #b91c1c; }
        .input-danger { border-color: #ef4444 !important; box-shadow: 0 0 0 3px rgba(239, 68, 68, 0.12) !important; }
        .error-text { display: none; color: #b91c1c; font-size: 0.82rem; font-weight: 600; margin-top: 6px; }
        .error-text.show { display: block; }
        .form-footer { padding: 20px 24px; background: #f9fafb; display: flex; justify-content: flex-end; gap: 12px; }
        .btn-cancel { background-color: #fff; border: 1px solid #d1d5db; color: #374151; padding: 11px 24px; border-radius: 10px; font-weight: 700; }
        .btn-submit { background-color: #1f2937; color: white; padding: 11px 28px; border-radius: 10px; font-weight: 700; border: none; }
        .btn-submit:hover { background-color: #000; }
        .btn-submit:disabled { background-color: #9ca3af; cursor: not-allowed; opacity: 0.75; }
        @media (max-width: 768px) { .main-content { margin-left: 0; } .sidebar { display: none; } }

        /* Responsive sidebar overlay */
        .sidebar {
            z-index: 1045;
            transition: transform 0.25s ease;
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
            .sidebar {
                transform: none !important;
            }

            .sidebar-backdrop {
                display: none !important;
            }

            .mobile-menu-btn {
                display: none !important;
            }
        }

        @media (max-width: 768px) {
            body.sidebar-open {
                overflow: hidden;
            }

            .sidebar {
                width: 280px;
                max-width: 85vw;
                transform: translateX(-100%);
                box-shadow: 12px 0 30px rgba(15, 23, 42, 0.16);
            }

            .sidebar.sidebar-open {
                transform: translateX(0);
            }

            .main-content {
                margin-left: 0 !important;
            }

            .top-navbar {
                padding: 0 16px;
                justify-content: space-between;
            }

            .mobile-menu-btn {
                display: inline-flex !important;
                align-items: center;
                justify-content: center;
            }

            main {
                padding: 24px 16px !important;
            }

            .table-wrapper,
            .table-responsive {
                overflow-x: auto;
            }
        }

    </style>
</head>
<body>

    <div class="sidebar d-flex flex-column justify-content-between">
        <div>
            <div class="sidebar-logo text-dark">LOGO WEB</div>
            <div class="mt-3">
                <a href="dashboard.php" class="nav-item-custom"><i class="fa-solid fa-house"></i> Dashboard</a>
                <a href="#persediaan" class="nav-item-custom active" data-bs-toggle="collapse"><i class="fa-solid fa-box"></i> Persediaan Barang</a>
                <div class="collapse show" id="persediaan">
                    <a href="form_barang.php?jenis=masuk" class="nav-item-custom ps-5 py-2 <?= $jenis_form == 'masuk' ? 'active' : 'text-muted' ?>" style="font-size: 0.9rem;"><i class="fa-regular fa-circle" style="font-size: 0.5rem;"></i> Form Barang Masuk</a>
                    <a href="form_barang.php?jenis=keluar" class="nav-item-custom ps-5 py-2 <?= $jenis_form == 'keluar' ? 'active' : 'text-muted' ?>" style="font-size: 0.9rem;"><i class="fa-regular fa-circle" style="font-size: 0.5rem;"></i> Form Barang Keluar</a>
                </div>
                <?php if ($role_login === 'admin'): ?>
                    <a href="master.php" class="nav-item-custom"><i class="fa-solid fa-database"></i> Master</a>
                <?php endif; ?>
                <a href="laporan.php" class="nav-item-custom"><i class="fa-regular fa-file-lines"></i> Laporan</a>
            </div>
        </div>
        <div class="mb-4 border-top pt-3"><a href="logout.php" class="nav-item-custom text-danger"><i class="fa-solid fa-arrow-right-from-bracket text-danger"></i> Logout</a></div>
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
            <div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mb-4">
                <div>
                    <h2 class="fw-bold mb-1"><?= $judul_form ?></h2>
                    <p class="text-muted mb-0"><?= $deskripsi_form ?></p>
                </div>
                <div class="help-box">
                    <i class="fa-solid fa-circle-info mt-1"></i>
                    <div>
                        <strong>Yang perlu diisi:</strong> pilih barang, isi jumlah, tanggal, lalu isi <?= strtolower($label_sumber) ?>.
                        <div><?= $teks_bantuan ?></div>
                    </div>
                </div>
            </div>

            <form method="POST" action="">
                <input type="hidden" name="jenis_transaksi" value="<?= $jenis_form ?>">

                <div class="form-card">
                    <div class="section-box">
                        <div class="step-title">1. Pilih Barang</div>
                        <div class="step-desc">Pilih barang dari data master. Satuan dan stok akan muncul otomatis.</div>

                        <div class="row g-3 align-items-end">
                            <div class="col-lg-6">
                                <label class="form-label">Barang <span class="text-danger">*</span></label>
                                <select name="id_barang" id="select_barang" class="form-select" required onchange="updateDetailBarang()">
                                    <option value="" disabled selected>Pilih barang yang ingin diproses</option>
                                    <?php foreach($barangData as $id => $brg): ?>
                                        <option value="<?= $id ?>"><?= htmlspecialchars($brg['kode'] . ' - ' . $brg['nama']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-lg-3">
                                <label class="form-label">Jumlah / Qty <span class="text-danger">*</span></label>
                                <input type="number" name="qty" id="input_qty" class="form-control" placeholder="Contoh: 5" min="1" required oninput="hitungPreview()">
                                <div class="error-text" id="error_qty">Jumlah barang keluar melebihi stok tersedia.</div>
                            </div>
                            <div class="col-lg-3">
                                <div class="preview-card" id="preview_card">
                                    <div class="preview-label">Stok setelah disimpan</div>
                                    <div class="preview-value" id="preview_stok">-</div>
                                </div>
                            </div>
                        </div>

                        <div class="row g-3 mt-1">
                            <div class="col-md-4">
                                <label class="form-label">Satuan</label>
                                <input type="text" id="view_satuan" class="form-control readonly-field" placeholder="Otomatis" readonly>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Stok Saat Ini</label>
                                <input type="text" id="view_stok" class="form-control readonly-field" placeholder="Otomatis" readonly>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">No. Transaksi</label>
                                <input type="text" class="form-control readonly-field" placeholder="Dibuat otomatis setelah disimpan" readonly>
                            </div>
                        </div>
                    </div>

                    <div class="section-box">
                        <div class="step-title">2. Lengkapi Informasi Transaksi</div>
                        <div class="step-desc">Bagian ini membantu laporan agar jelas barang berasal dari mana atau dipakai untuk apa.</div>

                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label">Tanggal <span class="text-danger">*</span></label>
                                <input type="date" name="tanggal" class="form-control" value="<?= date('Y-m-d') ?>" required>
                            </div>
                            <div class="col-md-8">
                                <label class="form-label"><?= $label_sumber ?> <span class="text-danger">*</span></label>
                                <input type="text" name="keterangan" class="form-control" placeholder="<?= $placeholder_sumber ?>" required>
                            </div>
                        </div>
                    </div>

                    <div class="form-footer">
                        <button type="button" class="btn-cancel" onclick="window.location.href='dashboard.php';">Batal</button>
                        <button type="submit" class="btn-submit" id="btn_simpan"><i class="fa-solid fa-floppy-disk me-1"></i> Simpan Transaksi</button>
                    </div>
                </div>
            </form>
        </main>
    </div>

    <script>
        const dataMasterBarang = <?= json_encode($barangData) ?>;
        const jenisTransaksi = '<?= $jenis_form ?>';

        function resetValidasiStok() {
            document.getElementById('input_qty').classList.remove('input-danger');
            document.getElementById('preview_card').classList.remove('is-danger');
            document.getElementById('error_qty').classList.remove('show');
            document.getElementById('btn_simpan').disabled = false;
        }

        function tampilkanErrorStok(sisaStok, satuan) {
            document.getElementById('input_qty').classList.add('input-danger');
            document.getElementById('preview_card').classList.add('is-danger');
            document.getElementById('error_qty').classList.add('show');
            document.getElementById('preview_stok').innerText = sisaStok + ' ' + (satuan || '') + ' (tidak valid)';
            document.getElementById('btn_simpan').disabled = true;
        }

        function updateDetailBarang() {
            const selectedId = document.getElementById('select_barang').value;
            const barangInfo = dataMasterBarang[selectedId];

            if (barangInfo) {
                document.getElementById('view_satuan').value = barangInfo.satuan || '-';
                document.getElementById('view_stok').value = barangInfo.stok;
            } else {
                document.getElementById('view_satuan').value = '';
                document.getElementById('view_stok').value = '';
            }

            hitungPreview();
        }

        function hitungPreview() {
            const selectedId = document.getElementById('select_barang').value;
            const qty = parseInt(document.getElementById('input_qty').value || '0');
            const barangInfo = dataMasterBarang[selectedId];

            resetValidasiStok();

            if (!barangInfo || qty <= 0) {
                document.getElementById('preview_stok').innerText = '-';
                return;
            }

            const stokSekarang = parseInt(barangInfo.stok || '0');
            const stokAkhir = jenisTransaksi === 'keluar' ? stokSekarang - qty : stokSekarang + qty;

            if (jenisTransaksi === 'keluar' && stokAkhir < 0) {
                tampilkanErrorStok(stokAkhir, barangInfo.satuan);
                return;
            }

            document.getElementById('preview_stok').innerText = stokAkhir + ' ' + (barangInfo.satuan || '');
        }
    </script>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        <?php if ($status == 'success'): ?>
            Swal.fire({
                title: 'Berhasil!',
                text: '<?= addslashes($message) ?>',
                icon: 'success',
                confirmButtonColor: '#1f2937'
            });
        <?php elseif ($status == 'error'): ?>
            Swal.fire({
                title: 'Gagal!',
                text: '<?= addslashes($message) ?>',
                icon: 'error',
                confirmButtonColor: '#1f2937'
            });
        <?php endif; ?>
    </script>

    <script>
        // Sidebar mobile: buka/tutup menu sebagai slider overlay.
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

</body>
</html>
