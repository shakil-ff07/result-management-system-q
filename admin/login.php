<?php
session_start();
include('../includes/db_config.php');

if (isset($_SESSION['admin_id'])) {
    header("Location: dashboard.php");
    exit();
}

$error = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = $_POST['username'];
    $password = $_POST['password'];

    $stmt = $conn->prepare("SELECT * FROM admins WHERE username = ?");
    $stmt->execute([$username]);
    $admin = $stmt->fetch();

    if ($admin && password_verify($password, $admin['password'])) {
        if (($admin['status'] ?? 'active') === 'suspended') {
            $error = "Your account has been suspended. Please contact the administrator.";
        } else if (($admin['role'] ?? '') === 'superadmin') {
            header("Location: ../superadmin/login.php");
            exit();
        } else {
            $_SESSION['admin_id'] = $admin['id'];
            $_SESSION['admin_username'] = $admin['username'];
            $_SESSION['admin_fullname'] = $admin['full_name'];
            $_SESSION['admin_role'] = $admin['role'] ?? 'headmaster'; // Store role with fallback
            header("Location: dashboard.php");
            exit();
        }
    } else {
        // Fallback for initial login
        if ($username == 'admin' && $password == 'admin123') {
            $_SESSION['admin_id'] = 1;
            $_SESSION['admin_username'] = 'admin';
            $_SESSION['admin_fullname'] = 'Principal';
            $_SESSION['admin_role'] = 'headmaster'; // Store role
            header("Location: dashboard.php");
            exit();
        }
        $error = "Invalid username or password!";
    }
}
?>
<script>(function () { var t = localStorage.getItem('srms-theme') || 'light'; document.documentElement.setAttribute('data-theme', t); })();</script>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Portal | SRMS</title>
    <link rel="icon" type="image/png" href="../logo/logo.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link
        href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600;700&family=Inter:wght@400;500;600;700&display=swap"
        rel="stylesheet">

    <style>
        :root {
            --prestige-navy: #0f172a;
            --prestige-gold: #b45309;
            --prestige-gold-light: #fef3c7;
            --prestige-slate: #f8fafc;
            --prestige-border: #e2e8f0;
            --prestige-text: #1e293b;
        }

        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--prestige-slate);
            color: var(--prestige-text);
            margin: 0;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
        }

        .serif-font {
            font-family: 'Playfair Display', serif;
        }

        .login-container {
            width: 100%;
            max-width: 420px;
            padding: 0 15px;
        }

        .login-card {
            background: white;
            border: 1px solid var(--prestige-border);
            border-radius: 8px;
            box-shadow: 0 15px 40px rgba(0, 0, 0, 0.06);
            overflow: hidden;
            animation: slideUp 0.6s cubic-bezier(0.16, 1, 0.3, 1);
        }

        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .login-header {
            padding: 40px 40px 30px;
            border-bottom: 1px solid var(--prestige-border);
            text-align: center;
            background: #fdfdfd;
        }

        .brand-logo {
            width: 70px;
            height: 70px;
            background: var(--prestige-navy);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1.5rem;
            font-size: 2rem;
            color: var(--prestige-gold-light);
            border: 2px solid var(--prestige-gold);
            box-shadow: 0 10px 20px rgba(15, 23, 42, 0.1);
        }

        .login-header h3 {
            font-size: 1.5rem;
            margin-bottom: 5px;
            color: var(--prestige-navy);
            font-weight: 700;
        }

        .login-header p {
            color: #64748b;
            font-size: 0.85rem;
            margin-bottom: 0;
        }

        .login-body {
            padding: 35px 40px;
        }

        .form-label {
            font-size: 0.75rem;
            font-weight: 800;
            text-transform: uppercase;
            color: #64748b;
            letter-spacing: 0.5px;
            margin-bottom: 8px;
            display: block;
        }

        .input-group {
            position: relative;
            margin-bottom: 1.5rem;
        }

        .input-icon {
            position: absolute;
            left: 16px;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
            z-index: 10;
        }

        .prestige-input {
            width: 100%;
            height: 50px;
            padding: 0 16px 0 45px;
            background: var(--prestige-slate);
            border: 1.5px solid var(--prestige-border);
            border-radius: 6px;
            font-weight: 600;
            color: var(--prestige-navy);
            transition: 0.2s;
            outline: none;
        }

        .prestige-input:focus {
            border-color: var(--prestige-gold);
            background: white;
            box-shadow: 0 0 0 4px rgba(180, 83, 9, 0.05);
        }

        .prestige-input::placeholder {
            color: #94a3b8;
            font-weight: 400;
        }

        .btn-toggle-pw {
            position: absolute;
            right: 16px;
            top: 50%;
            transform: translateY(-50%);
            background: transparent;
            border: none;
            color: #94a3b8;
            padding: 0;
            transition: color 0.2s;
            z-index: 10;
        }

        .btn-toggle-pw:hover {
            color: var(--prestige-navy);
        }

        .btn-primary {
            height: 54px;
            background: var(--prestige-navy);
            color: white;
            border: none;
            border-radius: 6px;
            font-weight: 700;
            font-size: 0.95rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            width: 100%;
            transition: 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            margin-top: 10px;
        }

        .btn-primary:hover {
            background: #1e293b;
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(15, 23, 42, 0.15);
            color: white;
        }

        .alert {
            background: rgba(220, 38, 38, 0.05);
            border: 1px solid rgba(220, 38, 38, 0.2);
            color: #dc2626;
            border-radius: 6px;
            font-size: 0.85rem;
            padding: 12px 16px;
            margin-bottom: 1.5rem;
            font-weight: 500;
        }

        .footer-text {
            margin-top: 2rem;
            color: #94a3b8;
            font-size: 0.8rem;
            text-align: center;
            font-weight: 500;
        }

        .footer-text a {
            color: var(--prestige-navy);
            text-decoration: none;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: 0.2s;
        }

        .footer-text a:hover {
            color: var(--prestige-gold);
        }

        /* Fix for Chrome/Edge Autofill Background */
        input:-webkit-autofill,
        input:-webkit-autofill:hover,
        input:-webkit-autofill:focus,
        input:-webkit-autofill:active {
            -webkit-box-shadow: 0 0 0 30px white inset !important;
            -webkit-text-fill-color: var(--prestige-navy) !important;
        }

        /* Midnight Prestige - Dark Mode for Login */
        body,
        .login-card,
        .prestige-input {
            transition: background-color 0.3s ease, border-color 0.3s ease, color 0.3s ease !important;
        }

        [data-theme="dark"] body {
            background-color: #060c18 !important;
        }

        [data-theme="dark"] .login-card {
            background: #111827 !important;
            border-color: rgba(255, 255, 255, 0.07) !important;
            box-shadow: 0 20px 50px rgba(0, 0, 0, 0.5) !important;
        }

        [data-theme="dark"] .login-header {
            background: #0f172a !important;
            border-bottom-color: rgba(255, 255, 255, 0.07) !important;
        }

        [data-theme="dark"] .login-header h3 {
            color: #e2e8f0 !important;
        }

        [data-theme="dark"] .login-header p {
            color: #6b7280 !important;
        }

        [data-theme="dark"] .brand-logo {
            background: #0a1020 !important;
            border-color: #f59e0b !important;
            color: #f59e0b !important;
        }

        [data-theme="dark"] .form-label {
            color: #6b7280 !important;
        }

        [data-theme="dark"] .prestige-input {
            background: #0a1020 !important;
            border-color: rgba(255, 255, 255, 0.1) !important;
            color: #e2e8f0 !important;
        }

        [data-theme="dark"] .prestige-input:focus {
            background: #060c18 !important;
            border-color: #f59e0b !important;
            box-shadow: 0 0 0 4px rgba(245, 158, 11, 0.1) !important;
        }

        [data-theme="dark"] .prestige-input::placeholder {
            color: #374151 !important;
        }

        [data-theme="dark"] .input-icon {
            color: #4b5563 !important;
        }

        [data-theme="dark"] .btn-primary {
            background: #f59e0b !important;
            border-color: #f59e0b !important;
            color: #0f172a !important;
        }

        [data-theme="dark"] .btn-primary:hover {
            background: #d97706 !important;
            box-shadow: 0 8px 20px rgba(245, 158, 11, 0.3) !important;
        }

        [data-theme="dark"] .alert {
            background: rgba(239, 68, 68, 0.1) !important;
            border-color: rgba(239, 68, 68, 0.2) !important;
            color: #f87171 !important;
        }

        [data-theme="dark"] .footer-text {
            color: #4b5563 !important;
        }

        [data-theme="dark"] .footer-text a {
            color: #94a3b8 !important;
        }

        [data-theme="dark"] .footer-text a:hover {
            color: #f59e0b !important;
        }

        [data-theme="dark"] input:-webkit-autofill,
        [data-theme="dark"] input:-webkit-autofill:focus {
            -webkit-box-shadow: 0 0 0 30px #0a1020 inset !important;
            -webkit-text-fill-color: #e2e8f0 !important;
        }

        /* Floating Dark Mode Toggle */
        .dm-float {
            position: fixed;
            top: 20px;
            right: 20px;
            width: 42px;
            height: 42px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: white;
            border: 1px solid var(--prestige-border);
            color: var(--prestige-navy);
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            font-size: 1rem;
            z-index: 9999;
            box-shadow: 0 4px 15px rgba(15, 23, 42, 0.08);
        }

        .dm-float:hover {
            background: var(--prestige-slate);
            border-color: var(--prestige-gold);
            color: var(--prestige-gold);
            transform: translateY(-2px) rotate(15deg);
            box-shadow: 0 8px 25px rgba(180, 83, 9, 0.12);
        }

        [data-theme="dark"] .dm-float {
            background: #111827 !important;
            border-color: rgba(255, 255, 255, 0.1) !important;
            color: #f59e0b !important;
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.4) !important;
        }

        [data-theme="dark"] .dm-float:hover {
            background: #1e293b !important;
            border-color: #f59e0b !important;
            box-shadow: 0 10px 35px rgba(245, 158, 11, 0.2) !important;
        }
    </style>
</head>

<body>

    <!-- Dark Mode Toggle -->
    <button id="dmFloatBtn" class="dm-float" onclick="toggleDarkMode()" title="Toggle Dark Mode">
        <i class="fas fa-moon"></i>
    </button>

    <div class="login-container">
        <div class="login-card">
            <div class="login-header">
                <div class="brand-logo">
                    <i class="fas fa-university"></i>
                </div>
                <h3 class="serif-font">Admin Portal</h3>
                <p>Enter your credentials to access the system</p>
            </div>

            <div class="login-body">
                <?php if ($error): ?>
                    <div class="alert d-flex align-items-center">
                        <i class="fas fa-exclamation-circle me-2"></i>
                        <?php echo $error; ?>
                    </div>
                <?php endif; ?>

                <form action="" method="POST">
                    <div>
                        <label class="form-label">Admin Username</label>
                        <div class="input-group">
                            <i class="fas fa-user-shield input-icon"></i>
                            <input type="text" name="username" class="prestige-input" placeholder="e.g. admin" required
                                autocomplete="username">
                        </div>
                    </div>
                    <div>
                        <label class="form-label">Security Phrase</label>
                        <div class="input-group">
                            <i class="fas fa-lock input-icon"></i>
                            <input type="password" name="password" id="password" class="prestige-input"
                                placeholder="••••••••" required autocomplete="current-password">
                            <button class="btn-toggle-pw" type="button" id="togglePassword">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                    </div>
                    <button type="submit" class="btn-primary">
                        <i class="fas fa-shield-alt"></i> SECURE LOGIN
                    </button>
                </form>
            </div>
        </div>

        <div class="footer-text">
            &copy; <?php echo date('Y'); ?> Education Board | Official Publication Portal
            <br><br>
            <a href="../index.php"><i class="fas fa-arrow-left"></i> Return to Registry</a>
        </div>
    </div>

    <script>
        // Dark Mode
        window.toggleDarkMode = function () {
            const html = document.documentElement;
            const next = html.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
            html.setAttribute('data-theme', next);
            localStorage.setItem('srms-theme', next);
            const btn = document.getElementById('dmFloatBtn');
            if (btn) btn.innerHTML = next === 'dark' ? '<i class="fas fa-sun" style="color:#f59e0b"></i>' : '<i class="fas fa-moon"></i>';
        };
        document.addEventListener('DOMContentLoaded', function () {
            const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
            const btn = document.getElementById('dmFloatBtn');
            if (btn && isDark) btn.innerHTML = '<i class="fas fa-sun" style="color:#f59e0b"></i>';
        });

        // Password Toggle
        const togglePassword = document.querySelector('#togglePassword');
        const password = document.querySelector('#password');

        togglePassword.addEventListener('click', function (e) {
            const type = password.getAttribute('type') === 'password' ? 'text' : 'password';
            password.setAttribute('type', type);
            const icon = this.querySelector('i');
            icon.classList.toggle('fa-eye');
            icon.classList.toggle('fa-eye-slash');
        });
    </script>
</body>

</html>