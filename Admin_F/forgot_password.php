<?php
// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Start session with secure settings
session_start([
    'cookie_lifetime' => 604800,
    'cookie_httponly' => true,
    'cookie_secure' => false,
    'cookie_samesite' => 'Strict'
]);

// Database connection settings (shared)
require_once 'db.php';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
}
catch (PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

// Generate CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Initialize reset attempts
if (!isset($_SESSION['reset_attempts'])) {
    $_SESSION['reset_attempts'] = ['count' => 0, 'timestamp' => time()];
}

// Include PHPMailer
require 'vendor/autoload.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// SMTP configuration (loaded from .env via smtp_config.php)
require_once __DIR__ . '/smtp_config.php';

// Handle forgot password request
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['request_reset'])) {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $error = "Invalid CSRF token!";
    }
    elseif ($_SESSION['reset_attempts']['count'] >= 10 && (time() - $_SESSION['reset_attempts']['timestamp']) < 3600) {
        $error = "Too many reset attempts. Please try again later.";
    }
    else {
        $email = trim($_POST['email']);
        $_SESSION['reset_attempts']['count']++;
        if ((time() - $_SESSION['reset_attempts']['timestamp']) > 3600) {
            $_SESSION['reset_attempts'] = ['count' => 1, 'timestamp' => time()];
        }

        // Check if email exists in admins table
        $stmt = $pdo->prepare("SELECT admin_id, email FROM admins WHERE email = :email");
        $stmt->bindParam(':email', $email);
        $stmt->execute();
        $admin = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($admin) {
            // Invalidate any existing unused tokens for this admin
            $stmt = $pdo->prepare("UPDATE admin_password_resets SET used_at = NOW() WHERE admin_id = :admin_id AND used_at IS NULL");
            $stmt->bindParam(':admin_id', $admin['admin_id']);
            $stmt->execute();

            // Generate reset token
            $token = bin2hex(random_bytes(32));
            $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));

            // Store token in database
            $stmt = $pdo->prepare("INSERT INTO admin_password_resets (admin_id, token, expires_at) VALUES (:admin_id, :token, :expires)");
            $stmt->bindParam(':admin_id', $admin['admin_id']);
            $stmt->bindParam(':token', $token);
            $stmt->bindParam(':expires', $expires);
            $stmt->execute();

            // Send reset email
            $reset_link = $app_url . "/forgot_password?token=$token";
            $mail = new PHPMailer(true);
            try {
                if (empty($smtp_username) || empty($smtp_password)) {
                    throw new Exception('SMTP credentials are not configured. Please set SMTP_USER and SMTP_PASS in the .env file.');
                }

                $mail->isSMTP();
                $mail->Host = $smtp_host;
                $mail->SMTPAuth = true;
                $mail->Username = $smtp_username;
                $mail->Password = $smtp_password;
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                $mail->Port = $smtp_port;
                $mail->SMTPDebug = 0; // 0=off, 2=verbose in error_log

                $mail->setFrom($smtp_from, $smtp_from_name);
                $mail->addAddress($email);

                $mail->isHTML(true);
                $mail->Subject = 'Password Reset Request — MMU Admin Portal';
                $mail->Body = "
                <div style='font-family:Inter,Arial,sans-serif; max-width:520px; margin:0 auto; padding:32px; background:#ffffff; border-radius:12px; border:1px solid #e2e8f0;'>
                    <div style='text-align:center; margin-bottom:24px;'>
                        <h2 style='color:#002147; font-size:22px; margin:0 0 8px;'>Password Reset Request</h2>
                        <p style='color:#6b7280; font-size:14px; margin:0;'>MMU AI Chatbot Admin Portal</p>
                    </div>
                    <p style='color:#374151; font-size:14px; line-height:1.6;'>Hello,</p>
                    <p style='color:#374151; font-size:14px; line-height:1.6;'>We received a request to reset your admin password. Click the button below to proceed:</p>
                    <div style='text-align:center; margin:28px 0;'>
                        <a href='$reset_link' style='display:inline-block; padding:12px 32px; background:#002147; color:#ffffff; text-decoration:none; border-radius:8px; font-weight:600; font-size:14px;'>Reset Password</a>
                    </div>
                    <p style='color:#6b7280; font-size:13px; line-height:1.5;'>This link will expire in <strong>1 hour</strong>. If you did not request this, please ignore this email — your password will remain unchanged.</p>
                    <hr style='border:none; border-top:1px solid #e5e7eb; margin:24px 0;'>
                    <p style='color:#9ca3af; font-size:12px; text-align:center; margin:0;'>MMU AI Chatbot Admin Team</p>
                </div>";
                $mail->AltBody = "Click this link to reset your password: $reset_link\nLink expires in 1 hour.\n\nIf you did not request a password reset, please ignore this email.";

                $mail->send();
                $success = "Password reset link has been sent to your email address.";
            }
            catch (Exception $e) {
                $smtp_err = $mail->ErrorInfo ?: $e->getMessage();
                error_log("SMTP Error [forgot_password.php] for {$email}: {$smtp_err}");
                $error = "Failed to send reset email. SMTP error: " . htmlspecialchars($smtp_err) . " — Check your SMTP settings in the .env file.";
            }
        }
        else {
            // Email not found — explicit message (admins know their own email)
            $error = "No account found with that email address. Please check and try again.";
        }
    }
}

// Handle password reset and admin details update
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['reset_password'])) {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $error = "Invalid CSRF token!";
    }
    else {
        $token = isset($_POST['token']) ? trim($_POST['token']) : '';
        $new_password = isset($_POST['new_password']) ? trim($_POST['new_password']) : '';
        $confirm_password = isset($_POST['confirm_password']) ? trim($_POST['confirm_password']) : '';
        $username_input = isset($_POST['username']) ? trim($_POST['username']) : '';
        $email = isset($_POST['email']) ? trim($_POST['email']) : '';

        if (empty($token)) {
            $error = "No reset token provided!";
        }
        else {
            // Validate token from admin_password_resets table
            $stmt = $pdo->prepare("SELECT admin_id, expires_at, used_at FROM admin_password_resets WHERE token = :token");
            $stmt->bindParam(':token', $token);
            $stmt->execute();
            $reset = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$reset) {
                $error = "Invalid reset token!";
            }
            elseif (!empty($reset['used_at'])) {
                $error = "Reset token has already been used!";
            }
            elseif (strtotime($reset['expires_at']) <= time()) {
                $error = "Reset token has expired!";
            }
            else {
                // Check if username or email is already taken
                $stmt = $pdo->prepare("SELECT admin_id FROM admins WHERE (username = :username OR email = :email) AND admin_id != :admin_id");
                $stmt->bindParam(':username', $username_input);
                $stmt->bindParam(':email', $email);
                $stmt->bindParam(':admin_id', $reset['admin_id']);
                $stmt->execute();
                
                if (empty($username_input) || empty($email)) {
                    $error = "Username and email are required fields!";
                }
                elseif ($stmt->fetch(PDO::FETCH_ASSOC)) {
                    $error = "Username or email is already in use!";
                }
                elseif ($new_password !== $confirm_password) {
                    $error = "Passwords do not match!";
                }
                elseif (strlen($new_password) < 8) {
                    $error = "Password must be at least 8 characters long!";
                }
                else {
                    // Update admin details
                    $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                    $stmt = $pdo->prepare("UPDATE admins SET username = :username, email = :email, password_hash = :password WHERE admin_id = :admin_id");
                    $stmt->bindParam(':username', $username_input);
                    $stmt->bindParam(':email', $email);
                    $stmt->bindParam(':password', $hashed_password);
                    $stmt->bindParam(':admin_id', $reset['admin_id']);
                    $stmt->execute();

                    // Mark token as used
                    $stmt = $pdo->prepare("UPDATE admin_password_resets SET used_at = NOW() WHERE token = :token");
                    $stmt->bindParam(':token', $token);
                    $stmt->execute();

                    $success = "Your account details have been updated successfully. <br><br><a href='admin-login' style='display:inline-block; padding:8px 16px; background:var(--primary); color:white; border-radius:6px; text-decoration:none;'>Go to Login</a>";
                }
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <title>Forgot Password - AI Chatbot Admin Portal</title>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <meta name="description" content="Reset password for AI Chatbot admin portal">
    <meta name="keywords" content="forgot password, admin, AI, chatbot">
    <link rel="shortcut icon" href="images/mmu_logo_- no bg.png" type="image/png">
    <link rel="icon" href="images/mmu_logo_- no bg.png" type="image/png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --primary: #002147;
            --primary-hover: #05356b;
            --primary-light: rgba(0, 33, 71, 0.1);
            --accent: #3b82f6;
            --accent-light: rgba(59, 130, 246, 0.1);
            --dark: #1e293b;
            --light: #f8fafc;
            --danger: #ef4444;
            --danger-light: rgba(239, 68, 68, 0.08);
            --success: #10b981;
            --success-light: rgba(16, 185, 129, 0.08);
            --text: #334155;
            --text-light: #94a3b8;
            --border: #e2e8f0;
            --shadow-sm: 0 1px 2px rgba(0, 0, 0, 0.05);
            --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
            --shadow-xl: 0 20px 40px -8px rgba(0, 0, 0, 0.12);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #f0f4f8 0%, #d9e2ec 50%, #bcccdc 100%);
            color: var(--text);
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            overflow: auto;
            position: relative;
        }

        body::before {
            content: '';
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background:
                radial-gradient(circle at 20% 80%, rgba(0, 33, 71, 0.06) 0%, transparent 50%),
                radial-gradient(circle at 80% 20%, rgba(59, 130, 246, 0.06) 0%, transparent 50%),
                radial-gradient(circle at 50% 50%, rgba(16, 185, 129, 0.03) 0%, transparent 60%);
            pointer-events: none;
            z-index: 0;
        }

        .reset-container {
            position: relative;
            z-index: 1;
            display: flex;
            max-width: 820px;
            width: 92%;
            margin: 1.5rem;
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border-radius: 20px;
            box-shadow: var(--shadow-xl), 0 0 0 1px rgba(255, 255, 255, 0.6);
            overflow: hidden;
            min-height: 460px;
            animation: containerSlideIn 0.6s cubic-bezier(0.4, 0, 0.2, 1);
        }

        @keyframes containerSlideIn {
            from {
                opacity: 0;
                transform: translateY(20px) scale(0.98);
            }

            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }

        .reset-image {
            flex: 1;
            background: linear-gradient(135deg, #002147 0%, #05356b 40%, #18569d 100%);
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            color: white;
            padding: 40px 30px;
            position: relative;
            overflow: hidden;
        }

        .reset-image::after {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 100%;
            height: 100%;
            background: radial-gradient(circle, rgba(59, 130, 246, 0.15) 0%, transparent 70%);
            pointer-events: none;
        }

        .reset-quote {
            max-width: 500px;
            text-align: center;
            position: relative;
            z-index: 2;
        }

        .reset-quote h2 {
            font-size: 1.8rem;
            font-weight: 800;
            margin-bottom: 16px;
            line-height: 1.2;
            letter-spacing: -0.02em;
        }

        .reset-quote p {
            font-size: 0.95rem;
            line-height: 1.6;
            opacity: 0.85;
            font-weight: 300;
        }

        .reset-form-container {
            flex: 1;
            max-width: 500px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            padding: 40px 36px;
            background: transparent;
        }

        .form-header {
            text-align: center;
            margin-bottom: 28px;
        }

        .logo {
            width: 56px;
            height: 56px;
            margin: 0 auto 14px;
            display: block;
            padding: 8px;
            background: white;
            border-radius: 14px;
            box-shadow: var(--shadow-md);
            transition: transform 0.3s;
        }

        .logo:hover {
            transform: scale(1.05) rotate(2deg);
        }

        .reset-title {
            font-size: 1.4rem;
            font-weight: 800;
            color: var(--primary);
            margin-bottom: 6px;
            letter-spacing: -0.01em;
        }

        .reset-subtitle {
            font-size: 0.82rem;
            color: var(--text-light);
            line-height: 1.5;
        }

        .error,
        .success {
            padding: 14px 16px;
            border-radius: 12px;
            margin-bottom: 20px;
            font-size: 0.82rem;
            display: flex;
            align-items: center;
            gap: 10px;
            line-height: 1.5;
            animation: alertSlide 0.4s ease;
        }

        @keyframes alertSlide {
            from {
                opacity: 0;
                transform: translateY(-8px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .error {
            background: var(--danger-light);
            color: var(--danger);
            border: 1px solid rgba(239, 68, 68, 0.15);
        }

        .success {
            background: var(--success-light);
            color: var(--success);
            border: 1px solid rgba(16, 185, 129, 0.15);
        }

        .error i,
        .success i {
            font-size: 16px;
            flex-shrink: 0;
        }

        .reset-form {
            display: flex;
            flex-direction: column;
            gap: 14px;
        }

        .input-group {
            position: relative;
        }

        .input-group i:first-child {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-light);
            font-size: 15px;
            transition: color 0.3s;
        }

        .input-group input {
            width: 100%;
            padding: 13px 40px 13px 42px;
            background: #f8fafc;
            border: 1.5px solid var(--border);
            border-radius: 12px;
            font-size: 0.88rem;
            font-family: 'Inter', sans-serif;
            color: var(--dark);
            transition: all 0.3s ease;
        }

        .input-group input::placeholder {
            color: var(--text-light);
            font-weight: 400;
        }

        .input-group input:focus {
            outline: none;
            border-color: var(--accent);
            background: #fff;
            box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.1);
        }

        .input-group input:focus~i:first-child,
        .input-group input:focus+i {
            color: var(--accent);
        }

        .input-group .toggle-password {
            position: absolute;
            right: 16px;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            color: var(--text-light);
            font-size: 15px;
            transition: color 0.2s;
        }

        .input-group .toggle-password:hover {
            color: var(--accent);
        }

        .reset-btn {
            background: var(--primary);
            color: white;
            padding: 13px;
            border: none;
            border-radius: 12px;
            font-size: 0.88rem;
            font-weight: 600;
            font-family: 'Inter', sans-serif;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 10px;
            margin-top: 4px;
        }

        .reset-btn:hover {
            background: var(--primary-hover);
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(0, 33, 71, 0.25);
        }

        .reset-btn:active {
            transform: translateY(0);
        }

        .reset-btn:disabled {
            background: var(--text-light);
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }

        .form-footer {
            margin-top: 28px;
            text-align: center;
            font-size: 0.82rem;
            color: var(--text-light);
        }

        .form-footer a {
            color: var(--primary);
            text-decoration: none;
            font-weight: 600;
            transition: color 0.2s;
        }

        .form-footer a:hover {
            color: var(--accent);
            text-decoration: underline;
        }

        .animation-container {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            overflow: hidden;
            opacity: 0.15;
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

        @media (max-width: 992px) {
            .reset-image {
                display: none;
            }

            .reset-container {
                max-width: 480px;
            }
        }

        @media (max-width: 576px) {
            .reset-form-container {
                padding: 28px 20px;
            }

            .reset-title {
                font-size: 1.25rem;
            }
        }
    </style>
</head>

<body>
    <div class="reset-container">
        <div class="reset-image">
            <div class="animation-container" id="animationContainer"></div>
            <div class="reset-quote">
                <h2>Reset Your Password</h2>
                <p>Secure your admin account with a new password. We'll send a verification link to your registered
                    email address.</p>
            </div>
        </div>
        <div class="reset-form-container">
            <div class="form-header">
                <img src="images/mmu_logo_- no bg.png" alt="Campus Logo" class="logo">
                <h1 class="reset-title">
                    <?php echo isset($_GET['token']) ? 'Reset Password & Edit Details' : 'Forgot Password'; ?>
                </h1>
                <p class="reset-subtitle">
                    <?php echo isset($_GET['token']) ? 'Update your account information below' : 'Enter your registered admin email to receive a reset link'; ?>
                </p>
            </div>

            <?php if (isset($error)): ?>
            <div class="error">
                <i class="fas fa-exclamation-circle"></i>
                <?php echo htmlspecialchars($error); ?>
            </div>
            <?php
elseif (isset($success)): ?>
            <div class="success">
                <i class="fas fa-check-circle"></i>
                <?php echo $success; ?>
            </div>
            <?php
endif; ?>

            <?php if (isset($_GET['token'])): ?>
            <?php
    // Verify token and fetch admin details from admin_password_resets
    $token = $_GET['token'];
    $stmt = $pdo->prepare("SELECT a.admin_id, a.username, a.email, pr.expires_at
                                      FROM admins a
                                      JOIN admin_password_resets pr ON a.admin_id = pr.admin_id
                                      WHERE pr.token = :token AND pr.used_at IS NULL");
    $stmt->bindParam(':token', $token);
    $stmt->execute();
    $admin_data = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$admin_data || strtotime($admin_data['expires_at']) <= time()) {
        $token_error = "Invalid or expired reset token!";
    }
?>
            <?php if (!isset($token_error) && !isset($success)): ?>
            <form class="reset-form" method="POST" action="" id="resetForm">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">
                <input type="hidden" name="reset_password" value="1">

                <div class="input-group">
                    <i class="fas fa-user"></i>
                    <input type="text" name="username" id="username" placeholder="Username"
                        value="<?php echo htmlspecialchars($admin_data['username'] ?? ''); ?>" required>
                </div>

                <div class="input-group">
                    <i class="fas fa-envelope"></i>
                    <input type="email" name="email" id="email" placeholder="Email"
                        value="<?php echo htmlspecialchars($admin_data['email'] ?? ''); ?>" required>
                </div>

                <div class="input-group">
                    <i class="fas fa-lock"></i>
                    <input type="password" name="new_password" id="new_password" placeholder="New Password" required>
                    <span class="toggle-password" id="toggleNewPassword">
                        <i class="fas fa-eye"></i>
                    </span>
                </div>

                <div class="input-group">
                    <i class="fas fa-lock"></i>
                    <input type="password" name="confirm_password" id="confirm_password" placeholder="Confirm Password"
                        required>
                    <span class="toggle-password" id="toggleConfirmPassword">
                        <i class="fas fa-eye"></i>
                    </span>
                </div>

                <button type="submit" class="reset-btn" id="resetBtn">
                    Update Details
                    <i class="fas fa-arrow-right"></i>
                </button>
            </form>
            <?php
    elseif (isset($token_error) && !isset($success)): ?>
            <div class="error">
                <i class="fas fa-exclamation-circle"></i>
                <?php echo htmlspecialchars($token_error); ?>
            </div>
            <?php
    endif; ?>
            <?php
else: ?>
            <form class="reset-form" method="POST" action="" id="requestForm">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                <input type="hidden" name="request_reset" value="1">

                <div class="input-group">
                    <i class="fas fa-envelope"></i>
                    <input type="email" name="email" id="email" placeholder="Enter your admin email" required>
                </div>

                <button type="submit" class="reset-btn" id="requestBtn">
                    Send Reset Link
                    <i class="fas fa-arrow-right"></i>
                </button>
            </form>
            <?php
endif; ?>

            <div class="form-footer">
                <p>Remember your password? <a href="admin-login">Back to Login</a></p>
            </div>
        </div>
    </div>

    <script>
        // Create floating particles for animation
        const animationContainer = document.getElementById('animationContainer');
        const particleCount = 15;

        for (let i = 0; i < particleCount; i++) {
            const particle = document.createElement('div');
            particle.classList.add('floating-particle');
            const size = Math.floor(Math.random() * 20) + 5;
            particle.style.width = `${size}px`;
            particle.style.height = `${size}px`;
            particle.style.left = `${Math.random() * 100}%`;
            particle.style.bottom = `${Math.random() * 20}%`;
            const duration = Math.floor(Math.random() * 15) + 15;
            particle.style.animationDuration = `${duration}s`;
            const delay = Math.floor(Math.random() * 10);
            particle.style.animationDelay = `${delay}s`;
            animationContainer.appendChild(particle);
        }

        // Toggle password visibility
        const togglePasswordFields = ['toggleNewPassword', 'toggleConfirmPassword'];
        togglePasswordFields.forEach(id => {
            const toggle = document.getElementById(id);
            if (toggle) {
                toggle.addEventListener('click', function () {
                    const input = this.parentElement.querySelector('input');
                    const type = input.getAttribute('type') === 'password' ? 'text' : 'password';
                    input.setAttribute('type', type);
                    const icon = this.querySelector('i');
                    icon.classList.toggle('fa-eye');
                    icon.classList.toggle('fa-eye-slash');
                });
            }
        });

        // Form validation
        const resetForm = document.getElementById('resetForm');
        const requestForm = document.getElementById('requestForm');

        if (resetForm) {
            const inputs = resetForm.querySelectorAll('input:not([type="hidden"])');
            const resetBtn = document.getElementById('resetBtn');
            const originalBtnText = resetBtn.innerHTML;

            resetForm.addEventListener('submit', (e) => {
                let valid = true;
                inputs.forEach(input => {
                    if (!input.value.trim()) {
                        valid = false;
                        input.style.borderColor = '#ef4444';
                        input.style.boxShadow = '0 0 0 3px rgba(239, 68, 68, 0.1)';
                    }
                });

                const newPassword = document.getElementById('new_password');
                const confirmPassword = document.getElementById('confirm_password');
                if (newPassword.value !== confirmPassword.value) {
                    valid = false;
                    confirmPassword.style.borderColor = '#ef4444';
                    confirmPassword.style.boxShadow = '0 0 0 3px rgba(239, 68, 68, 0.1)';
                }

                if (!valid) {
                    e.preventDefault();
                    resetBtn.disabled = true;
                    setTimeout(() => {
                        resetBtn.disabled = false;
                    }, 1000);
                } else {
                    resetBtn.innerHTML = '<i class="fas fa-circle-notch fa-spin"></i> Updating...';
                    resetBtn.disabled = true;
                    setTimeout(() => {
                        if (resetBtn.disabled) {
                            resetBtn.innerHTML = originalBtnText;
                            resetBtn.disabled = false;
                        }
                    }, 3000);
                }
            });
        }

        if (requestForm) {
            const emailInput = document.getElementById('email');
            const requestBtn = document.getElementById('requestBtn');
            const originalBtnText = requestBtn.innerHTML;

            requestForm.addEventListener('submit', (e) => {
                if (!emailInput.value.trim()) {
                    e.preventDefault();
                    emailInput.style.borderColor = '#ef4444';
                    emailInput.style.boxShadow = '0 0 0 3px rgba(239, 68, 68, 0.1)';
                    requestBtn.disabled = true;
                    setTimeout(() => {
                        requestBtn.disabled = false;
                    }, 1000);
                } else {
                    requestBtn.innerHTML = '<i class="fas fa-circle-notch fa-spin"></i> Sending...';
                    requestBtn.disabled = true;
                    setTimeout(() => {
                        if (requestBtn.disabled) {
                            requestBtn.innerHTML = originalBtnText;
                            requestBtn.disabled = false;
                        }
                    }, 3000);
                }
            });
        }

        // Input field animations
        const inputFields = document.querySelectorAll('.input-group input');
        inputFields.forEach(input => {
            input.addEventListener('focus', () => {
                const icon = input.parentElement.querySelector('i:first-child');
                if (icon) icon.style.color = '#3b82f6';
            });
            input.addEventListener('blur', () => {
                const icon = input.parentElement.querySelector('i:first-child');
                if (icon) icon.style.color = '#94a3b8';
            });
        });
    </script>
</body>

</html>