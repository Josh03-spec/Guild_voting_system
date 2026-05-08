<?php
/*
 * register.php
 * -------------
 * Allows a new student to create an account.
 * The student must exist in eligible_students (verified by student number + email).
 * On success, redirects to index.php to log in.
 */

session_start();
require_once 'db.php';

// If already logged in, no need to be here
if (isset($_SESSION['user_id'])) {
    header("Location: " . ($_SESSION['role'] === 'admin' ? 'admin_dashboard.php' : 'student_dashboard.php'));
    exit();
}

$error   = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // -----------------------------------------------------------------------
    // Collect and trim all inputs
    // -----------------------------------------------------------------------
    $student_number = strtoupper(trim($_POST['student_number'] ?? ''));
    $email          = strtolower(trim($_POST['email'] ?? ''));
    $password       = $_POST['password'] ?? '';
    $confirm        = $_POST['confirm_password'] ?? '';

    // -----------------------------------------------------------------------
    // Input validation — check every field before touching the database
    // -----------------------------------------------------------------------
    if (empty($student_number) || empty($email) || empty($password) || empty($confirm)) {
        $error = "All fields are required. Please fill in the form completely.";

    } elseif (!preg_match('/^\d{10}$/', $student_number)) {
        // Student numbers in our DB are 10 digits e.g. 2500501001
        $error = "Student number must be exactly 10 digits (e.g. 2500501001).";

    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid email address.";

    } elseif (!str_ends_with($email, '@stud.umu.ac.ug')) {
        // Only official university emails are accepted
        $error = "You must use your official university email (@stud.umu.ac.ug).";

    } elseif (strlen($password) < 8) {
        $error = "Password must be at least 8 characters long.";

    } elseif (!preg_match('/[A-Z]/', $password) || !preg_match('/[0-9]/', $password)) {
        $error = "Password must contain at least one uppercase letter and one number.";

    } elseif ($password !== $confirm) {
        $error = "Passwords do not match. Please try again.";

    } else {

        // -----------------------------------------------------------------------
        // Check eligible_students: student_number AND email must both match
        // -----------------------------------------------------------------------
        $stmt = mysqli_prepare($conn,
            "SELECT id, full_name, is_claimed
             FROM eligible_students
             WHERE student_number = ? AND official_email = ?"
        );
        mysqli_stmt_bind_param($stmt, "ss", $student_number, $email);
        mysqli_stmt_execute($stmt);
        $result  = mysqli_stmt_get_result($stmt);
        $eligible = mysqli_fetch_assoc($result);
        mysqli_stmt_close($stmt);

        if (!$eligible) {
            // No match — either wrong number, wrong email, or combination mismatch
            $error = "Your student number and email do not match our records. 
                      Please check your details or contact the administrator.";

        } elseif ($eligible['is_claimed'] == 1) {
            // Someone already registered with this identity
            $error = "An account already exists for this student number. 
                      If this is unexpected, please contact the administrator.";

        } else {
            // -----------------------------------------------------------------------
            // All checks passed — create the account
            // -----------------------------------------------------------------------
            $full_name    = $eligible['full_name'];   // name comes from our records, not user input
            $password_hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

            // Insert into users
            $stmt = mysqli_prepare($conn,
                "INSERT INTO users (student_number, full_name, email, password_hash, role, is_active)
                 VALUES (?, ?, ?, ?, 'student', 1)"
            );
            mysqli_stmt_bind_param($stmt, "ssss", $student_number, $full_name, $email, $password_hash);

            if (mysqli_stmt_execute($stmt)) {
                // Mark the eligible_students row as claimed
                mysqli_stmt_close($stmt);
                $stmt2 = mysqli_prepare($conn,
                    "UPDATE eligible_students SET is_claimed = 1 WHERE id = ?"
                );
                mysqli_stmt_bind_param($stmt2, "i", $eligible['id']);
                mysqli_stmt_execute($stmt2);
                mysqli_stmt_close($stmt2);

                $success = "Account created successfully! You can now log in.";

            } else {
                $error = "Registration failed due to a system error. Please try again.";
                mysqli_stmt_close($stmt);
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PollPoint &mdash; Register</title>
    <link rel="stylesheet" href="styles.css">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: var(--font-sans);
            background-color: var(--bg-page);
            color: var(--text-primary);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background-image:
                radial-gradient(circle at 20% 20%, var(--accent-soft) 0%, transparent 50%),
                radial-gradient(circle at 80% 80%, rgba(199,119,15,0.06) 0%, transparent 50%);
            padding: 24px;
        }

        .card {
            background: var(--bg-surface);
            border: 1px solid var(--border-subtle);
            border-radius: var(--radius-lg);
            padding: 48px 44px;
            width: 100%;
            max-width: 520px;
            box-shadow: var(--shadow-lg);
            animation: fadeUp 0.4s ease both;
        }

        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(20px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        .card-logo {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 32px;
        }

        .logo-icon {
            width: 36px; height: 36px;
            background: var(--accent);
            border-radius: 7px;
            display: flex; align-items: center; justify-content: center;
            font-size: 18px;
        }

        .logo-text {
            font-family: var(--font-serif);
            font-size: 1.5rem;
            font-weight: 900;
        }

        .logo-text span { color: var(--accent); }

        h2 {
            font-family: var(--font-serif);
            font-size: 1.4rem;
            margin-bottom: 6px;
        }

        .subtitle {
            font-size: 0.875rem;
            color: var(--text-secondary);
            margin-bottom: 28px;
            line-height: 1.6;
        }

        .alert {
            padding: 12px 16px;
            border-radius: var(--radius-md);
            margin-bottom: 22px;
            font-size: 0.875rem;
            display: flex;
            gap: 8px;
            align-items: flex-start;
        }

        .alert-error {
            background: var(--danger-soft);
            border: 1px solid rgba(220,38,38,0.28);
            color: var(--danger);
        }

        .alert-success {
            background: var(--success-soft);
            border: 1px solid rgba(21,128,61,0.28);
            color: var(--success);
        }

        /* Two columns for student number + email side by side */
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }

        .form-group { margin-bottom: 18px; }

        label {
            display: block;
            font-size: 0.78rem;
            font-weight: 600;
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: 0.08em;
            margin-bottom: 7px;
        }

        input[type="text"],
        input[type="email"],
        input[type="password"] {
            width: 100%;
            padding: 11px 14px;
            background: var(--bg-surface);
            border: 1px solid var(--border-subtle);
            border-radius: var(--radius-md);
            color: var(--text-primary);
            font-family: var(--font-sans);
            font-size: 0.92rem;
            outline: none;
            transition: border-color var(--transition-base), box-shadow var(--transition-base);
        }

        input:focus {
            border-color: var(--accent);
            box-shadow: var(--focus-ring);
        }

        input::placeholder { color: var(--text-muted); }

        .hint {
            font-size: 0.78rem;
            color: var(--text-secondary);
            margin-top: 5px;
        }

        .btn {
            width: 100%;
            padding: 13px;
            background: var(--accent);
            color: var(--text-inverse);
            border: none;
            border-radius: var(--radius-md);
            font-family: var(--font-sans);
            font-size: 0.95rem;
            font-weight: 700;
            cursor: pointer;
            margin-top: 6px;
            transition: background var(--transition-base), transform var(--transition-fast);
        }

        .btn:hover  { background: var(--accent-hover); }
        .btn:active { transform: scale(0.98); }

        .login-link {
            margin-top: 24px;
            text-align: center;
            font-size: 0.875rem;
            color: var(--text-secondary);
            padding-top: 24px;
            border-top: 1px solid var(--border-subtle);
        }

        .login-link a {
            color: var(--accent);
            text-decoration: none;
            font-weight: 600;
        }

        .login-link a:hover { text-decoration: underline; }

        @media (max-width: 500px) {
            .card { padding: 36px 22px; }
            .form-row { grid-template-columns: 1fr; }
        }
    </style>
    <script src="script.js"></script>
</head>
<body>
<div class="card">

    <div class="card-logo">
        <div class="logo-icon">&#128717;</div>
        <div class="logo-text">Poll<span>Point</span></div>
    </div>

    <h2>Create your account</h2>
    <p class="subtitle">
        Registration is open to enrolled UMU students only.
        Your student number and university email must match our records.
    </p>

    <?php if (!empty($error)): ?>
        <div class="alert alert-error">&#9888; <?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <?php if (!empty($success)): ?>
        <div class="alert alert-success">&#10003; <?php echo htmlspecialchars($success); ?></div>
    <?php endif; ?>

    <?php if (empty($success)): ?>
    <form method="POST" action="register.php" novalidate>

        <div class="form-row">
            <div class="form-group">
                <label for="student_number">Student Number</label>
                <input type="text" id="student_number" name="student_number"
                    placeholder="2500501001"
                    value="<?php echo htmlspecialchars($_POST['student_number'] ?? ''); ?>"
                    maxlength="10" required>
            </div>
            <div class="form-group">
                <label for="email">University Email</label>
                <input type="email" id="email" name="email"
                    placeholder="you@stud.umu.ac.ug"
                    value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>"
                    required>
            </div>
        </div>

        <div class="form-group">
            <label for="password">Password</label>
            <input type="password" id="password" name="password"
                placeholder="Create a strong password" required>
            <p class="hint">Min. 8 characters, one uppercase letter, one number.</p>
        </div>

        <div class="form-group">
            <label for="confirm_password">Confirm Password</label>
            <input type="password" id="confirm_password" name="confirm_password"
                placeholder="Repeat your password" required>
        </div>

        <button type="submit" class="btn">Create Account &rarr;</button>

    </form>
    <?php endif; ?>

    <div class="login-link">
        Already have an account? <a href="index.php">Sign in here</a>
    </div>

</div>
</body>
</html>
