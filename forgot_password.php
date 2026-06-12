<?php
session_start();
require 'config/db_connect.php';

// ==============================
// KONFIGURASI & VARIABEL GLOBAL
// ==============================
$status = '';
$message = '';
$step = 'email';
$email_found = '';

$isPost = $_SERVER["REQUEST_METHOD"] === "POST";

if ($isPost && isset($_POST['cek_email'])) {
    $email = trim($_POST['email'] ?? '');

    if ($email === '') {
        $status = 'error';
        $message = 'Email wajib diisi.';
    } else {
        // Query untuk mengecek email user aktif.
        $sqlCekEmail = "
            SELECT id, email
            FROM users
            WHERE email = ?
            AND is_delete = 0
            LIMIT 1
        ";

        $stmt = $conn->prepare($sqlCekEmail);
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            $user = $result->fetch_assoc();

            $step = 'password';
            $email_found = $user['email'];
            $status = 'success';
            $message = 'Email ditemukan. Silakan masukkan password baru.';
        } else {
            $status = 'error';
            $message = 'Email tidak ditemukan di sistem.';
        }

        $stmt->close();
    }
}

if ($isPost && isset($_POST['update_password'])) {
    $email = trim($_POST['email'] ?? '');
    $password_baru = $_POST['password_baru'] ?? '';
    $konfirmasi_password = $_POST['konfirmasi_password'] ?? '';

    $step = 'password';
    $email_found = $email;

    if ($password_baru === '' || $konfirmasi_password === '') {
        $status = 'error';
        $message = 'Password baru dan konfirmasi password wajib diisi.';
    } elseif ($password_baru !== $konfirmasi_password) {
        $status = 'error';
        $message = 'Konfirmasi password tidak sama.';
    } elseif (strlen($password_baru) < 6) {
        $status = 'error';
        $message = 'Password minimal 6 karakter.';
    } else {
        $password_hash = password_hash($password_baru, PASSWORD_DEFAULT);

        // Query untuk update password user aktif berdasarkan email.
        $sqlUpdatePassword = "
            UPDATE users
            SET password_hash = ?
            WHERE email = ?
            AND is_delete = 0
        ";

        $stmt = $conn->prepare($sqlUpdatePassword);
        $stmt->bind_param("ss", $password_hash, $email);

        if ($stmt->execute()) {
            $step = 'done';
            $status = 'success';
            $message = 'Password berhasil diubah. Silakan login menggunakan password baru.';
        } else {
            $status = 'error';
            $message = 'Gagal mengubah password.';
        }

        $stmt->close();
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - Sistem Manajemen Persediaan</title>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <style>
        :root {
            --primary: #4f46e5;
            --primary-hover: #4338ca;
            --bg-color: #f3f4f6;
            --card-bg: #ffffff;
            --text-main: #1f2937;
            --text-muted: #6b7280;
            --border-color: #d1d5db;
            --danger: #dc2626;
            --success: #16a34a;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: 'Inter', sans-serif;
        }

        body {
            background-color: var(--bg-color);
            color: var(--text-main);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        .navbar {
            padding: 20px 40px;
            background-color: var(--card-bg);
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
            font-weight: 700;
            font-size: 1.2rem;
            color: var(--primary);
            letter-spacing: 0.5px;
        }

        .main-wrapper {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 40px 20px;
        }

        .forgot-card {
            width: 100%;
            max-width: 460px;
            background: var(--card-bg);
            border-radius: 16px;
            padding: 40px;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1),
                        0 10px 10px -5px rgba(0, 0, 0, 0.04);
        }

        .icon-box {
            width: 64px;
            height: 64px;
            border-radius: 16px;
            background: rgba(79, 70, 229, 0.1);
            color: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.8rem;
            margin-bottom: 22px;
        }

        h2 {
            font-size: 1.8rem;
            margin-bottom: 8px;
            font-weight: 700;
        }

        .subtitle {
            font-size: 0.95rem;
            color: var(--text-muted);
            margin-bottom: 28px;
            line-height: 1.6;
        }

        .alert {
            padding: 12px 14px;
            border-radius: 8px;
            font-size: 0.9rem;
            margin-bottom: 20px;
            line-height: 1.5;
        }

        .alert-error {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }

        .alert-success {
            background: #dcfce7;
            color: #166534;
            border: 1px solid #bbf7d0;
        }

        .form-group {
            margin-bottom: 18px;
        }

        .form-group label {
            display: block;
            font-size: 0.85rem;
            font-weight: 600;
            margin-bottom: 8px;
        }

        .input-box {
            position: relative;
            display: flex;
            align-items: center;
        }

        .input-box i {
            position: absolute;
            left: 16px;
            color: #9ca3af;
            font-size: 1rem;
        }

        .input-box input {
            width: 100%;
            padding: 14px 16px 14px 45px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            font-size: 0.95rem;
            color: var(--text-main);
            outline: none;
            background: #f9fafb;
            transition: all 0.3s ease;
        }

        .input-box input:focus {
            background: #ffffff;
            border-color: var(--primary);
            box-shadow: 0 0 0 4px rgba(79, 70, 229, 0.1);
        }

        .btn-submit {
            width: 100%;
            padding: 14px;
            background-color: var(--primary);
            color: #ffffff;
            border: none;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            margin-top: 8px;
            box-shadow: 0 4px 6px -1px rgba(79, 70, 229, 0.2);
            transition: all 0.2s ease;
        }

        .btn-submit:hover {
            background-color: var(--primary-hover);
            transform: translateY(-1px);
        }

        .back-login {
            text-align: center;
            margin-top: 22px;
        }

        .back-login a {
            color: var(--primary);
            font-size: 0.9rem;
            font-weight: 600;
            text-decoration: none;
        }

        .back-login a:hover {
            text-decoration: underline;
        }

        @media (max-width: 576px) {
            .forgot-card {
                padding: 30px 22px;
            }

            .navbar {
                padding: 18px 22px;
            }
        }
    </style>
</head>
<body>

    <div class="navbar">
        LOGO WEB
    </div>

    <div class="main-wrapper">
        <div class="forgot-card">
            <div class="icon-box">
                <i class="fa-solid fa-key"></i>
            </div>

            <h2>Lupa Password</h2>

            <?php if ($step == 'email'): ?>
                <p class="subtitle">
                    Masukkan email akun yang sudah terdaftar untuk mengganti password.
                </p>
            <?php elseif ($step == 'password'): ?>
                <p class="subtitle">
                    Email ditemukan. Silakan buat password baru untuk akun tersebut.
                </p>
            <?php else: ?>
                <p class="subtitle">
                    Password berhasil diperbarui. Kamu bisa login kembali.
                </p>
            <?php endif; ?>

            <?php if ($message != ''): ?>
                <div class="alert <?= $status == 'success' ? 'alert-success' : 'alert-error' ?>">
                    <?= htmlspecialchars($message) ?>
                </div>
            <?php endif; ?>

            <?php if ($step == 'email'): ?>
                <form method="POST" action="">
                    <div class="form-group">
                        <label for="email">Email</label>
                        <div class="input-box">
                            <input type="email" name="email" id="email" placeholder="Masukkan email" required>
                            <i class="fa-regular fa-envelope"></i>
                        </div>
                    </div>

                    <button type="submit" name="cek_email" class="btn-submit">
                        Cek Email
                    </button>
                </form>
            <?php endif; ?>

            <?php if ($step == 'password'): ?>
                <form method="POST" action="">
                    <input type="hidden" name="email" value="<?= htmlspecialchars($email_found) ?>">

                    <div class="form-group">
                        <label>Email Akun</label>
                        <div class="input-box">
                            <input type="email" value="<?= htmlspecialchars($email_found) ?>" readonly>
                            <i class="fa-regular fa-envelope"></i>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="password_baru">Password Baru</label>
                        <div class="input-box">
                            <input type="password" name="password_baru" id="password_baru" placeholder="Masukkan password baru" required>
                            <i class="fa-solid fa-lock"></i>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="konfirmasi_password">Konfirmasi Password</label>
                        <div class="input-box">
                            <input type="password" name="konfirmasi_password" id="konfirmasi_password" placeholder="Ulangi password baru" required>
                            <i class="fa-solid fa-lock"></i>
                        </div>
                    </div>

                    <button type="submit" name="update_password" class="btn-submit">
                        Simpan Password Baru
                    </button>
                </form>
            <?php endif; ?>

            <?php if ($step == 'done'): ?>
                <a href="index.php">
                    <button type="button" class="btn-submit">
                        Kembali Login
                    </button>
                </a>
            <?php endif; ?>

            <div class="back-login">
                <a href="index.php">
                    <i class="fa-solid fa-arrow-left"></i>
                    Kembali ke Login
                </a>
            </div>
        </div>
    </div>

</body>
</html>
