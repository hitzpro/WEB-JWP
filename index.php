<?php
session_start();
require 'config/db_connect.php';

// =========================
// Variabel global
// =========================
$status = '';
$pesan = '';
$halamanDashboard = 'dashboard.php';
$roleValid = ['admin', 'user'];

$sudahLogin = isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true;
$methodPost = $_SERVER['REQUEST_METHOD'] === 'POST';

// Jika sudah login dan bukan proses submit form, langsung masuk dashboard.
if ($sudahLogin && !$methodPost) {
    header("Location: $halamanDashboard");
    exit;
}

if ($methodPost) {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($email === '' || $password === '') {
        $status = 'error';
        $pesan = 'Email dan password wajib diisi.';
    } else {
        // Query untuk mencari user aktif berdasarkan email.
        $query = "SELECT id, nama, password_hash, role
                  FROM users
                  WHERE email = ? AND is_delete = 0
                  LIMIT 1";

        $stmt = $conn->prepare($query);
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $stmt->store_result();

        if ($stmt->num_rows === 1) {
            $stmt->bind_result($id, $nama, $passwordHash, $role);
            $stmt->fetch();

            if (password_verify($password, $passwordHash)) {
                // Role dibatasi hanya admin/user agar session tetap aman.
                $role = in_array($role, $roleValid) ? $role : 'user';

                session_regenerate_id(true);
                $_SESSION['loggedin'] = true;
                $_SESSION['id'] = $id;
                $_SESSION['username'] = $nama;
                $_SESSION['role'] = $role;

                $status = 'success';
                $pesan = 'Berhasil Login';
            } else {
                $status = 'error';
                $pesan = 'Username atau password mungkin salah.';
            }
        } else {
            $status = 'error';
            $pesan = 'Username atau password mungkin salah.';
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
    <title>Login - Sistem Manajemen Persediaan</title>
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
            display: flex;
            flex-direction: column;
            min-height: 100vh;
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

        .login-card {
            display: flex;
            width: 100%;
            max-width: 900px;
            background: var(--card-bg);
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
        }

        .left-panel {
            flex: 1;
            padding: 50px 40px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
            background: linear-gradient(135deg, #4f46e5 0%, #3b82f6 100%);
            color: #ffffff;
        }

        .graphic-circle {
            width: 140px;
            height: 140px;
            background: rgba(255, 255, 255, 0.15);
            backdrop-filter: blur(10px);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 30px;
            font-size: 3.5rem;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
        }

        .left-panel h3 {
            font-size: 1.4rem;
            margin-bottom: 12px;
            font-weight: 600;
            letter-spacing: 0.5px;
        }

        .left-panel p {
            font-size: 0.95rem;
            color: rgba(255, 255, 255, 0.85);
            line-height: 1.6;
            max-width: 280px;
        }

        .right-panel {
            flex: 1;
            padding: 50px 40px;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        .right-panel h2 {
            font-size: 2rem;
            margin-bottom: 8px;
            font-weight: 700;
            color: var(--text-main);
        }

        .subtitle {
            font-size: 0.95rem;
            color: var(--text-muted);
            margin-bottom: 30px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            font-size: 0.85rem;
            font-weight: 600;
            color: var(--text-main);
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
            font-size: 1.1rem;
            transition: color 0.3s;
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

        .input-box input:focus + i,
        .input-box input:focus ~ i.fa-solid {
            color: var(--primary);
        }

        .input-box input[type="password"],
        .input-box input[type="text"].password-field {
            padding-right: 45px;
        }

        .toggle-password {
            position: absolute;
            right: 16px;
            left: auto !important;
            cursor: pointer;
            color: #9ca3af;
        }

        .toggle-password:hover {
            color: var(--text-main);
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
            margin-top: 10px;
            box-shadow: 0 4px 6px -1px rgba(79, 70, 229, 0.2);
            transition: all 0.2s ease;
        }

        .btn-submit:hover {
            background-color: var(--primary-hover);
            transform: translateY(-1px);
            box-shadow: 0 6px 8px -1px rgba(79, 70, 229, 0.3);
        }

        .btn-submit:active {
            transform: translateY(0);
        }

        .divider {
            display: flex;
            align-items: center;
            text-align: center;
            margin: 25px 0;
            font-size: 0.85rem;
            color: var(--text-muted);
        }

        .divider::before,
        .divider::after {
            content: '';
            flex: 1;
            border-bottom: 1px solid var(--border-color);
        }

        .divider:not(:empty)::before { margin-right: 1em; }
        .divider:not(:empty)::after { margin-left: 1em; }

        .forgot-link {
            text-align: center;
        }

        .forgot-link a {
            font-size: 0.9rem;
            color: var(--primary);
            text-decoration: none;
            font-weight: 600;
            transition: color 0.2s;
        }

        .forgot-link a:hover {
            color: var(--primary-hover);
            text-decoration: underline;
        }

        @media (max-width: 768px) {
            .login-card {
                flex-direction: column;
                max-width: 450px;
            }

            .left-panel,
            .right-panel {
                padding: 40px 20px;
            }
        }
    </style>
</head>
<body>
    <div class="navbar">LOGO WEB</div>

    <div class="main-wrapper">
        <div class="login-card">
            <div class="left-panel">
                <div class="graphic-circle">
                    <i class="fa-solid fa-boxes-stacked"></i>
                </div>
                <h3>Sistem Manajemen Persediaan</h3>
                <p>Kelola stok, transaksi, dan data master secara efisien dan terintegrasi.</p>
            </div>

            <div class="right-panel">
                <h2>Login</h2>
                <div class="subtitle">Masuk untuk mengakses sistem Anda.</div>

                <form method="POST" action="<?= htmlspecialchars($_SERVER['PHP_SELF']); ?>">
                    <div class="form-group">
                        <label>Username / Email</label>
                        <div class="input-box">
                            <input type="text" name="email" placeholder="Masukkan username atau email" required>
                            <i class="fa-regular fa-user"></i>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Password</label>
                        <div class="input-box">
                            <input type="password" id="password" name="password" placeholder="Masukkan password" required>
                            <i class="fa-solid fa-lock"></i>
                            <i class="fa-regular fa-eye toggle-password" id="eyeIcon" onclick="togglePassword()"></i>
                        </div>
                    </div>

                    <button type="submit" class="btn-submit">Masuk</button>

                    <div class="divider">atau</div>

                    <div class="forgot-link">
                        <a href="forgot_password.php">Lupa password?</a>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        function togglePassword() {
            const input = document.getElementById('password');
            const icon = document.getElementById('eyeIcon');
            const terlihat = input.type === 'text';

            input.type = terlihat ? 'password' : 'text';
            input.classList.toggle('password-field', !terlihat);
            icon.className = terlihat
                ? 'fa-regular fa-eye toggle-password'
                : 'fa-solid fa-eye-slash toggle-password';
        }

        <?php if ($status === 'success'): ?>
            Swal.fire({
                title: 'Berhasil!',
                text: '<?= $pesan; ?>',
                icon: 'success',
                confirmButtonText: 'Lanjut ke Dashboard',
                confirmButtonColor: '#4f46e5'
            }).then(() => {
                window.location.href = '<?= $halamanDashboard; ?>';
            });
        <?php elseif ($status === 'error'): ?>
            Swal.fire({
                title: 'Akses Ditolak',
                text: '<?= $pesan; ?>',
                icon: 'error',
                confirmButtonText: 'Coba Lagi',
                confirmButtonColor: '#ef4444'
            });
        <?php endif; ?>
    </script>

</body>
</html>
