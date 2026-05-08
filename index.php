<?php
/*
 * index.php
 * ----------
 * Landing page and login handler for PollPoint.
 * - If user is already logged in, redirect to the correct dashboard.
 * - If login form is submitted, validate credentials and route by role.
 * - If not logged in, display the welcome page with the login form.
 */

session_start();
require_once 'db.php';

// ---------------------------------------------------------------------------
// 1. If already logged in, send them straight to their dashboard
// ---------------------------------------------------------------------------
if (isset($_SESSION['user_id'])) {
    if ($_SESSION['role'] === 'admin') {
        header("Location: admin_dashboard.php");
    } else {
        header("Location: student_dashboard.php");
    }
    exit();
}

// ---------------------------------------------------------------------------
// 2. Handle login form submission
// ---------------------------------------------------------------------------
$error_message = ''; // will hold any login error to display back to the user

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Grab and sanitize inputs
    $email    = trim($_POST['email'] ?? '');
    $password = trim($_POST['password'] ?? '');

    // Basic presence check
    if (empty($email) || empty($password)) {
        $error_message = "Please enter both your email and password.";

    } else {
        // Look up the user by email using a prepared statement
        $stmt = mysqli_prepare($conn, "SELECT id, full_name, password_hash, role, is_active FROM users WHERE email = ?");
        mysqli_stmt_bind_param($stmt, "s", $email);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $user   = mysqli_fetch_assoc($result);
        mysqli_stmt_close($stmt);

        if (!$user) {
            // Email not found — keep message vague for security
            $error_message = "Invalid email or password. Please try again.";

        } elseif ($user['is_active'] == 0) {
            // Account exists but has been disabled by admin
            $error_message = "Your account has been disabled. Please contact the administrator.";

        } elseif (!password_verify($password, $user['password_hash'])) {
            // Wrong password
            $error_message = "Invalid email or password. Please try again.";

        } else {
            // ---------------------------------------------------------------
            // Credentials are correct — start the session
            // ---------------------------------------------------------------
            $_SESSION['user_id']   = $user['id'];
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['role']      = $user['role'];

            // Route to the correct dashboard based on role
            if ($user['role'] === 'admin') {
                header("Location: admin_dashboard.php");
            } else {
                header("Location: student_dashboard.php");
            }
            exit();
        }
    }
}
// ---------------------------------------------------------------------------
// 3. If we reach here, show the login page (GET request or failed login)
// ---------------------------------------------------------------------------
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PollPoint &mdash; Student Guild Voting</title>

    <link rel="stylesheet" href="styles.css">

    <style>
        /* ===================================================================
           RESET & BASE
        =================================================================== */
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: var(--font-sans);
            background-color: var(--bg-page);
            color: var(--text-primary);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;

            /* Subtle geometric background pattern */
            background-image:
                radial-gradient(circle at 20% 20%, var(--accent-soft) 0%, transparent 50%),
                radial-gradient(circle at 80% 80%, rgba(199,119,15,0.06) 0%, transparent 50%),
                repeating-linear-gradient(
                    45deg,
                    transparent,
                    transparent 60px,
                    rgba(15,23,42,0.018) 60px,
                    rgba(15,23,42,0.018) 61px
                );
        }

        /* ===================================================================
           PAGE LAYOUT — two columns on wide screens, stacked on mobile
        =================================================================== */
        .page-wrapper {
            display: flex;
            width: 100%;
            max-width: 960px;
            min-height: 560px;
            border: 1px solid var(--border-subtle);
            border-radius: var(--radius-lg);
            overflow: hidden;
            box-shadow: var(--shadow-lg);
            margin: 24px;
            background: var(--bg-surface);

            /* Entrance animation */
            animation: fadeUp 0.5s ease both;
        }

        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(24px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        /* ===================================================================
           LEFT PANEL — branding / welcome
        =================================================================== */
        .brand-panel {
            flex: 1;
            background:
                linear-gradient(160deg, var(--bg-surface-2) 0%, var(--bg-page) 60%),
                repeating-linear-gradient(
                    -45deg,
                    transparent,
                    transparent 40px,
                    rgba(199,119,15,0.05) 40px,
                    rgba(199,119,15,0.05) 41px
                );
            display: flex;
            flex-direction: column;
            justify-content: center;
            padding: 56px 48px;
            border-right: 1px solid var(--border-subtle);
            position: relative;
            overflow: hidden;
        }

        /* Decorative circle behind text */
        .brand-panel::before {
            content: '';
            position: absolute;
            width: 320px;
            height: 320px;
            border-radius: 50%;
            border: 1px solid var(--accent-soft);
            top: -80px;
            left: -80px;
        }

        .brand-panel::after {
            content: '';
            position: absolute;
            width: 200px;
            height: 200px;
            border-radius: 50%;
            border: 1px solid rgba(199,119,15,0.08);
            bottom: 40px;
            right: -60px;
        }

        .brand-logo {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 40px;
        }

        /* Simple ballot-box icon drawn in CSS */
        .logo-icon {
            width: 40px;
            height: 40px;
            background: var(--accent);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            flex-shrink: 0;
        }

        .logo-text {
            font-family: var(--font-serif);
            font-size: 1.75rem;
            font-weight: 900;
            color: var(--text-primary);
            letter-spacing: -0.5px;
        }

        .logo-text span {
            color: var(--accent);
        }

        .brand-headline {
            font-family: var(--font-serif);
            font-size: 2rem;
            font-weight: 700;
            line-height: 1.25;
            color: var(--text-primary);
            margin-bottom: 20px;
        }

        .brand-sub {
            font-size: 1rem;
            font-weight: 300;
            color: var(--text-secondary);
            line-height: 1.7;
            max-width: 280px;
        }

        .brand-divider {
            width: 48px;
            height: 3px;
            background: var(--accent);
            border-radius: 2px;
            margin: 28px 0;
        }

        .brand-badges {
            display: flex;
            flex-direction: column;
            gap: 12px;
            margin-top: 40px;
        }

        .badge {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 0.85rem;
            color: var(--text-secondary);
        }

        .badge-dot {
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: var(--accent);
            flex-shrink: 0;
        }

        /* ===================================================================
           RIGHT PANEL — login form
        =================================================================== */
        .login-panel {
            width: 400px;
            flex-shrink: 0;
            background: var(--bg-surface);
            display: flex;
            flex-direction: column;
            justify-content: center;
            padding: 56px 44px;
        }

        .login-title {
            font-family: var(--font-serif);
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 6px;
        }

        .login-subtitle {
            font-size: 0.9rem;
            color: var(--text-secondary);
            margin-bottom: 36px;
        }

        /* ===================================================================
           ERROR BOX
        =================================================================== */
        .error-box {
            background: var(--danger-soft);
            border: 1px solid rgba(220,38,38,0.28);
            border-radius: var(--radius-md);
            padding: 12px 16px;
            margin-bottom: 24px;
            font-size: 0.875rem;
            color: var(--danger);
            display: flex;
            align-items: flex-start;
            gap: 8px;
        }

        .error-box::before {
            content: '⚠';
            flex-shrink: 0;
        }

        /* ===================================================================
           FORM ELEMENTS
        =================================================================== */
        .form-group {
            margin-bottom: 20px;
        }

        label {
            display: block;
            font-size: 0.8rem;
            font-weight: 600;
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: 0.08em;
            margin-bottom: 8px;
        }

        input[type="email"],
        input[type="password"] {
            width: 100%;
            padding: 12px 16px;
            background: var(--bg-surface);
            border: 1px solid var(--border-subtle);
            border-radius: var(--radius-md);
            color: var(--text-primary);
            font-family: var(--font-sans);
            font-size: 0.95rem;
            transition: border-color var(--transition-base), box-shadow var(--transition-base);
            outline: none;
        }

        input[type="email"]:focus,
        input[type="password"]:focus {
            border-color: var(--accent);
            box-shadow: var(--focus-ring);
        }

        input::placeholder {
            color: var(--text-muted);
        }

        /* ===================================================================
           SUBMIT BUTTON
        =================================================================== */
        .btn-login {
            width: 100%;
            padding: 13px;
            background: var(--accent);
            color: var(--text-inverse);
            border: none;
            border-radius: var(--radius-md);
            font-family: var(--font-sans);
            font-size: 0.95rem;
            font-weight: 700;
            letter-spacing: 0.04em;
            cursor: pointer;
            margin-top: 8px;
            transition: background var(--transition-base), transform var(--transition-fast);
        }

        .btn-login:hover  { background: var(--accent-hover); }
        .btn-login:active { transform: scale(0.98); }

        /* ===================================================================
           REGISTER LINK
        =================================================================== */
        .register-link {
            margin-top: 28px;
            text-align: center;
            font-size: 0.875rem;
            color: var(--text-secondary);
            padding-top: 24px;
            border-top: 1px solid var(--border-subtle);
        }

        .register-link a {
            color: var(--accent);
            text-decoration: none;
            font-weight: 600;
            transition: color 0.2s;
        }

        .register-link a:hover {
            color: var(--accent-hover);
            text-decoration: underline;
        }

        /* ===================================================================
           RESPONSIVE — stack panels on small screens
        =================================================================== */
        @media (max-width: 720px) {
            .page-wrapper {
                flex-direction: column;
                margin: 0;
                border-radius: 0;
                min-height: 100vh;
                border: none;
            }

            .brand-panel {
                padding: 40px 28px 32px;
                border-right: none;
                border-bottom: 1px solid var(--border-subtle);
            }

            .brand-badges  { display: none; } /* hide on mobile to save space */
            .brand-sub     { max-width: 100%; }

            .login-panel {
                width: 100%;
                padding: 36px 28px 48px;
            }
        }
    </style>
    <script src="script.js"></script>
</head>
<body>

<div class="page-wrapper">

    <!-- ====================================================
         LEFT — Brand / Welcome panel
    ===================================================== -->
    <div class="brand-panel">

        <div class="brand-logo">
            <div class="logo-icon">&#128717;</div>
            <div class="logo-text">Poll<span>Point</span></div>
        </div>

        <h1 class="brand-headline">
            Your vote.<br>Your voice.<br>Your guild.
        </h1>

        <div class="brand-divider"></div>

        <p class="brand-sub">
            The official Student Guild voting platform. Cast your vote securely,
            track live results, and be part of decisions that shape your campus.
        </p>

        <div class="brand-badges">
            <div class="badge">
                <div class="badge-dot"></div>
                One verified vote per student per election
            </div>
            <div class="badge">
                <div class="badge-dot"></div>
                Results updated in real time
            </div>
            <div class="badge">
                <div class="badge-dot"></div>
                Accessible to all registered UMU students
            </div>
        </div>

    </div>

    <!-- ====================================================
         RIGHT — Login form panel
    ===================================================== -->
    <div class="login-panel">

        <h2 class="login-title">Welcome back</h2>
        <p class="login-subtitle">Sign in to your PollPoint account to continue.</p>

        <?php if (!empty($error_message)): ?>
            <!-- Error message shown only when login fails -->
            <div class="error-box">
                <?php echo htmlspecialchars($error_message); ?>
            </div>
        <?php endif; ?>

        <!-- Login form — posts to this same page (index.php) -->
        <form method="POST" action="index.php" novalidate>

            <div class="form-group">
                <label for="email">University Email</label>
                <input
                    type="email"
                    id="email"
                    name="email"
                    placeholder="you@stud.umu.ac.ug"
                    value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>"
                    required
                    autocomplete="email"
                >
            </div>

            <div class="form-group">
                <label for="password">Password</label>
                <input
                    type="password"
                    id="password"
                    name="password"
                    placeholder="Enter your password"
                    required
                    autocomplete="current-password"
                >
            </div>

            <button type="submit" class="btn-login">Sign In &rarr;</button>

        </form>

        <div class="register-link">
            New student? &nbsp;
            <a href="register.php">Create your account here</a>
        </div>

    </div><!-- /login-panel -->

</div><!-- /page-wrapper -->

</body>
</html>
