    <?php
// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Start session with secure settings (cookie_secure only on HTTPS)
$__is_https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);
session_start([
    'cookie_lifetime' => 604800, // 7 days
    'cookie_httponly' => true,
    'cookie_secure' => $__is_https,
    'cookie_samesite' => 'Strict'
]);

// Database connection (MySQLi)
require_once 'db.php';

// Generate CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Initialize login attempts if not set
if (!isset($_SESSION['login_attempts'])) {
    $_SESSION['login_attempts'] = ['count' => 0, 'timestamp' => time()];
}

// ── Remember Me: Auto-login from cookie if no active session ──
if (!isset($_SESSION['admin_id']) && isset($_COOKIE['admin_remember_token'])) {
    $cookie_token = $_COOKIE['admin_remember_token'];
    // Lookup token in DB
    $stmt = $conn->prepare("SELECT admin_id, token_hash, expires_at FROM admin_remember_tokens WHERE expires_at > NOW()");
    if ($stmt) {
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            if (hash_equals($row['token_hash'], hash('sha256', $cookie_token))) {
                // Valid token found — auto-login
                $_SESSION['admin_id'] = $row['admin_id'];
                $_SESSION['last_activity'] = time();
                // Fetch username
                $u_stmt = $conn->prepare("SELECT username FROM admins WHERE admin_id = ?");
                $u_stmt->bind_param('i', $row['admin_id']);
                $u_stmt->execute();
                $u_res = $u_stmt->get_result()->fetch_assoc();
                $_SESSION['username'] = $u_res['username'] ?? '';
                $u_stmt->close();
                header("Location: index.php");
                exit();
            }
        }
        $stmt->close();
    }
    // Invalid/expired token — clear cookie
    setcookie('admin_remember_token', '', time() - 42000, '/', '', false, true);
}

// Handle form submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Verify CSRF token
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $error = "Invalid CSRF token!";
    } else {
        // Rate limiting check
        if ($_SESSION['login_attempts']['count'] >= 5 && (time() - $_SESSION['login_attempts']['timestamp']) < 900) {
            $error = "Too many login attempts. Please try again later.";
        } else {
            $input_username = trim($_POST['username']);
            $input_password = trim($_POST['password']);

            // Increment login attempts
            $_SESSION['login_attempts']['count']++;
            if ((time() - $_SESSION['login_attempts']['timestamp']) > 900) {
                $_SESSION['login_attempts'] = ['count' => 1, 'timestamp' => time()];
            }

            // Fetch admin details (allow login by username or email)
            $stmt = $conn->prepare("SELECT admin_id, username, password_hash FROM admins WHERE username = ? OR email = ?");
            if (!$stmt) {
                die("Prepare failed: " . $conn->error);
            }
            $stmt->bind_param('ss', $input_username, $input_username);
            if (!$stmt->execute()) {
                die("Execute failed: " . $stmt->error);
            }
            $result = $stmt->get_result();
            if (!$result) {
                die("Get result failed: " . $stmt->error);
            }
            $admin = $result->fetch_assoc();

            // Verify credentials
            if ($admin && password_verify($input_password, $admin['password_hash'])) {
                // Reset login attempts
                $_SESSION['login_attempts'] = ['count' => 0, 'timestamp' => time()];
                // Set session variables
                $_SESSION['admin_id'] = $admin['admin_id'];
                $_SESSION['username'] = $admin['username'];
                $_SESSION['last_activity'] = time();

                // Update last_login_at timestamp (auto-add column if missing)
                $conn->query("ALTER TABLE admins ADD COLUMN IF NOT EXISTS last_login_at DATETIME DEFAULT NULL");
                $conn->query("ALTER TABLE admins ADD COLUMN IF NOT EXISTS created_at DATETIME DEFAULT CURRENT_TIMESTAMP");
                $login_upd = $conn->prepare("UPDATE admins SET last_login_at = NOW() WHERE admin_id = ?");
                if ($login_upd) { $login_upd->bind_param('i', $admin['admin_id']); $login_upd->execute(); }

                // ── Remember Me: set persistent cookie + DB token ──
                if (!empty($_POST['remember'])) {
                    $raw_token = bin2hex(random_bytes(32));
                    $token_hash = hash('sha256', $raw_token);
                    $expires = date('Y-m-d H:i:s', strtotime('+30 days'));

                    // Create table if not exists
                    $conn->query("CREATE TABLE IF NOT EXISTS admin_remember_tokens (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        admin_id INT NOT NULL,
                        token_hash VARCHAR(64) NOT NULL,
                        expires_at DATETIME NOT NULL,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        INDEX(admin_id),
                        INDEX(expires_at)
                    )");

                    // Remove old tokens for this admin
                    $del = $conn->prepare("DELETE FROM admin_remember_tokens WHERE admin_id = ?");
                    $del->bind_param('i', $admin['admin_id']);
                    $del->execute();
                    $del->close();

                    // Insert new token
                    $ins = $conn->prepare("INSERT INTO admin_remember_tokens (admin_id, token_hash, expires_at) VALUES (?, ?, ?)");
                    $ins->bind_param('iss', $admin['admin_id'], $token_hash, $expires);
                    $ins->execute();
                    $ins->close();

                    // Set HTTPOnly cookie for 30 days
                    setcookie('admin_remember_token', $raw_token, [
                        'expires'  => time() + (30 * 24 * 60 * 60),
                        'path'     => '/',
                        'secure'   => $__is_https,
                        'httponly'  => true,
                        'samesite' => 'Strict',
                    ]);
                }

                header("Location: index.php");
                exit();
            } else {
                $error = "Invalid username/email or password!";
            }
        }
    }
}

// Check session timeout (example: 30 minutes inactivity)
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > 1800)) {
    session_unset();
    session_destroy();
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <title>AI Chatbot Admin Portal</title>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="AI Chatbot admin login for educational platforms">
    <meta name="keywords" content="education, admin, login, AI, chatbot">
    <link rel="shortcut icon" href="images/mmu_logo_- no bg.png" type="image/x-icon">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --primary: #3b82f6;
            --primary-hover: #2563eb;
            --primary-light: rgba(59, 130, 246, 0.1);
            --secondary: #f0f9ff;
            --dark: #1e293b;
            --light: #f8fafc;
            --danger: #ef4444;
            --danger-light: rgba(239, 68, 68, 0.1);
            --success: #10b981;
            --text: #334155;
            --text-light: #94a3b8;
            --shadow-sm: 0 1px 2px rgba(0, 0, 0, 0.05);
            --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
            --shadow-xl: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background-color: rgb(238, 241, 241);
            color: var(--text);
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            overflow: auto;
            zoom: 0.8;

        }

        .login-container {
            display: flex;
            max-width: 720px;
            width: 90%;
            margin: 1.5rem;
            background-color: white;
            border-radius: 12px;
            box-shadow: var(--shadow-xl);
            overflow: hidden;
        }

        .login-image {
            flex: 1;
            background: linear-gradient(135deg, rgba(0, 0, 0, 0.7), rgba(0, 0, 0, 0.5)), url('images/login/bg3.jpg');
            background-size: cover;
            background-position: center;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            color: white;
            padding: 20px;
            position: relative;
        }

        .login-quote {
            max-width: 500px;
            text-align: center;
            position: relative;
            z-index: 2;
        }

        .login-quote h2 {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 20px;
            line-height: 1.2;
        }

        .login-quote p {
            font-size: 1rem;
            line-height: 1.5;
            opacity: 0.9;
        }

        .login-form-container {
            flex: 1;
            max-width: 500px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            padding: 20px;
            background-color: white;
        }

        .form-header {
            text-align: center;
            margin-bottom: 20px;
        }

        .logo {
            width: 60px;
            height: 60px;
            margin: 0 auto 10px;
            display: block;
            padding: 10px;
            background-color: white;
            border-radius: 50%;
            box-shadow: var(--shadow-md);
        }

        .login-title {
            font-size: 22px;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 5px;
        }

        .login-subtitle {
            font-size: 13px;
            color: var(--text-light);
        }

        .error {
            background-color: var(--danger-light);
            color: var(--danger);
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .error i {
            font-size: 16px;
        }

        .login-form {
            display: flex;
            flex-direction: column;
            gap: 15px;
        }

        .input-group {
            position: relative;
        }

        .input-group i {
            position: absolute;
            left: 16px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-light);
            font-size: 18px;
        }

        .input-group input {
            width: 100%;
            padding: 12px 12px 12px 40px;
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            font-size: 14px;
            color: var(--dark);
            transition: all 0.3s ease;
        }

        .input-group input::placeholder {
            color: var(--text-light);
        }

        .input-group input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.15);
        }

        .input-group .toggle-password {
            position: absolute;
            right: 50px;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            color: var(--text-light);
            font-size: 18px;
        }

        .remember-forgot {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin: 5px 0 15px;
        }

        .remember-me {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .remember-me input {
            appearance: none;
            width: 18px;
            height: 18px;
            border: 1px solid #cbd5e1;
            border-radius: 4px;
            position: relative;
            cursor: pointer;
        }

        .remember-me input:checked {
            background-color: var(--primary);
            border-color: var(--primary);
        }

        .remember-me input:checked::after {
            content: "✓";
            position: absolute;
            color: white;
            font-size: 12px;
            top: 0;
            left: 4px;
        }

        .remember-me label {
            font-size: 14px;
            color: var(--text);
            cursor: pointer;
        }

        .forgot-password {
            font-size: 14px;
            color: var(--primary);
            text-decoration: none;
            font-weight: 500;
        }

        .forgot-password:hover {
            text-decoration: underline;
        }

        .login-btn {
            background: var(--primary);
            color: white;
            padding: 12px;
            border: none;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 10px;
        }

        .login-btn:hover {
            background: var(--primary-hover);
            transform: translateY(-1px);
            box-shadow: var(--shadow-md);
        }

        .login-btn:active {
            transform: translateY(0);
        }

        .login-btn:disabled {
            background: var(--text-light);
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }

        .login-btn i {
            font-size: 18px;
            transition: transform 0.3s ease;
        }

        .login-btn:hover i {
            transform: translateX(3px);
        }

        .form-footer {
            margin-top: 30px;
            text-align: center;
            font-size: 14px;
            color: var(--text-light);
        }

        .form-footer a {
            color: var(--primary);
            text-decoration: none;
            font-weight: 500;
        }

        .form-footer a:hover {
            text-decoration: underline;
        }

        .animation-container {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            overflow: hidden;
            opacity: 0.2;
        }

        .floating-particle {
            position: absolute;
            background-color: rgba(255, 255, 255, 0.5);
            border-radius: 50%;
            animation: float 20s infinite linear;
        }

        @keyframes float {
            0% {
                transform: translateY(100vh) translateX(0);
                opacity: 0;
            }

            10% {
                opacity: 1;
            }

            90% {
                opacity: 1;
            }

            100% {
                transform: translateY(-100vh) translateX(20vw);
                opacity: 0;
            }
        }

        /* Responsive styles */
        @media (max-width: 992px) {
            .login-image {
                display: none;
            }

            .login-container {
                max-width: 500px;
            }
        }

        @media (max-width: 576px) {
            .login-form-container {
                padding: 30px 20px;
            }

            .login-title {
                font-size: 22px;
            }

            .login-subtitle {
                font-size: 14px;
            }

            .remember-forgot {
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
            }
        }

        /* Page transition animations */
        body {
            animation: pageIn 0.5s ease-out;
        }
        @keyframes pageIn {
            from { opacity: 0; transform: translateY(8px); }
            to   { opacity: 1; transform: translateY(0); }
        }
        body.page-exit {
            animation: pageOut 0.4s ease-in forwards;
        }
        @keyframes pageOut {
            from { opacity: 1; transform: scale(1); }
            to   { opacity: 0; transform: scale(0.98); }
        }
    </style>
</head>

<body>

    <body>
        <div class="login-container">
            <div class="login-image">
                <div class="animation-container" id="animationContainer"></div>
                <div class="login-quote">
                    <h2>Welcome to AI Chatbot Admin</h2>
                    <p></p>
                </div>
            </div>
            <div class="login-form-container">
                <div class="form-header">
                    <img src="images/mmu_logo_- no bg.png" alt="Campus Logo" class="logo">
                    <h1 class="login-title">AI Chatbot</h1>
                    <p class="login-subtitle">Sign in to access the dashboard</p>
                </div>

                <?php if (isset($error)): ?>
                    <div class="error">
                        <i class="fas fa-exclamation-circle"></i>
                        <?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>

                <form class="login-form" method="POST" action="" id="loginForm">
                    <input type="hidden" name="csrf_token"
                        value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">

                    <div class="input-group">
                        <i class="fas fa-user-circle"></i>
                        <input type="text" name="username" id="username" placeholder="Username or Email" required>
                    </div>

                    <div class="input-group">
                        <i class="fas fa-lock"></i>
                        <input type="password" name="password" id="password" placeholder="Password" required>
                        <span class="toggle-password" id="togglePassword">
                            <i class="fas fa-eye"></i>
                        </span>
                    </div>

                    <div class="remember-forgot" style="justify-content:flex-end;">
                        <a href="forgot_password.php" class="forgot-password">Forgot password?</a>
                    </div>

                    <button type="submit" class="login-btn" id="loginBtn">
                        Sign In
                        <i class="fas fa-arrow-right"></i>
                    </button>
                </form>


            </div>
        </div>

        <script>
            // Create floating particles for animation
            // Create floating particles for animation
            const animationContainer = document.getElementById('animationContainer');
            const particleCount = 15;

            for (let i = 0; i < particleCount; i++) {
                const particle = document.createElement('div');
                particle.classList.add('floating-particle');

                // Random size between 5px and 25px
                const size = Math.floor(Math.random() * 20) + 5;
                particle.style.width = `${size}px`;
                particle.style.height = `${size}px`;

                // Random starting position
                particle.style.left = `${Math.random() * 100}%`;
                particle.style.bottom = `${Math.random() * 20}%`;

                // Random animation duration between 15s and 30s
                const duration = Math.floor(Math.random() * 15) + 15;
                particle.style.animationDuration = `${duration}s`;

                // Random animation delay
                const delay = Math.floor(Math.random() * 10);
                particle.style.animationDelay = `${delay}s`;

                animationContainer.appendChild(particle);
            }

            // Toggle password visibility
            const togglePassword = document.getElementById('togglePassword');
            const password = document.getElementById('password');

            togglePassword.addEventListener('click', function () {
                const type = password.getAttribute('type') === 'password' ? 'text' : 'password';
                password.setAttribute('type', type);

                // Toggle icon
                const icon = this.querySelector('i');
                icon.classList.toggle('fa-eye');
                icon.classList.toggle('fa-eye-slash');
            });

            // Form validation
            const form = document.getElementById('loginForm');
            const username = document.getElementById('username');
            const loginBtn = document.getElementById('loginBtn');
            const originalBtnText = loginBtn.innerHTML;

            form.addEventListener('submit', (e) => {
                let valid = true;

                if (!username.value.trim()) {
                    valid = false;
                    username.style.borderColor = '#ef4444'; // Direct color value instead of var()
                    username.style.boxShadow = '0 0 0 3px rgba(239, 68, 68, 0.1)';
                }

                if (!password.value.trim()) {
                    valid = false;
                    password.style.borderColor = '#ef4444';
                    password.style.boxShadow = '0 0 0 3px rgba(239, 68, 68, 0.1)';
                }

                if (!valid) {
                    e.preventDefault();
                    loginBtn.disabled = true;
                    setTimeout(() => {
                        loginBtn.disabled = false;
                    }, 1000);
                } else {
                    // Show loading state with smooth transition
                    loginBtn.innerHTML = '<i class="fas fa-circle-notch fa-spin"></i> Signing in...';
                    loginBtn.disabled = true;

                    // Trigger page fade-out for smooth transition
                    document.body.classList.add('page-exit');

                    // If form doesn't submit within 3 seconds (for demo purposes)
                    setTimeout(() => {
                        if (loginBtn.disabled) {
                            loginBtn.innerHTML = originalBtnText;
                            loginBtn.disabled = false;
                            document.body.classList.remove('page-exit');
                        }
                    }, 3000);
                }
            });

            // Remember Me is now handled server-side via secure cookies

            // Update last activity on user interaction
            document.addEventListener('mousemove', () => {
                // Use debounce to prevent too many requests
                clearTimeout(window.activityTimer);
                window.activityTimer = setTimeout(() => {
                    fetch('update_activity.php', {
                        method: 'POST',
                        headers: {
                            'X-CSRF-Token': '<?php echo $_SESSION['csrf_token']; ?>'
                        }
                    });
                }, 30000); // Update every 30 seconds of activity
            });

            // Input field animations
            const inputFields = document.querySelectorAll('.input-group input');

            inputFields.forEach(input => {
                input.addEventListener('focus', () => {
                    input.parentElement.querySelector('i').style.color = '#3b82f6'; // Direct color value
                });

                input.addEventListener('blur', () => {
                    input.parentElement.querySelector('i').style.color = '#94a3b8';
                });
            });
        </script>
    </body>

</html>