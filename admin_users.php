<?php
/*
 * admin_users.php
 * ----------------
 * Admin page to manage registered student accounts.
 *
 * Features:
 *   - Search by name or email
 *   - Filter by status (active / disabled)
 *   - Filter by voted status in a selected election
 *   - Edit student details (name, email)
 *   - Toggle active / disabled
 *   - Promote to admin / demote to student
 *   - Delete account (only if student has never voted)
 */

session_start();
require_once 'db.php';

// --- Session guard: admins only ---
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

if ($_SESSION['role'] !== 'admin') {
    header("Location: student_dashboard.php");
    exit();
}

$current_admin_id = $_SESSION['user_id'];
$message = '';
$msg_type = '';

// -----------------------------------------------------------------------
// Handle POST actions: edit, toggle status, promote/demote, delete
// -----------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action  = $_POST['action']  ?? '';
    $user_id = intval($_POST['user_id'] ?? 0);

    // Safety: never let admin act on their own account destructively
    // (they can still edit their own name/email)

    // -------------------------------------------------------------------
    // ACTION: Save edited user details
    // -------------------------------------------------------------------
    if ($action === 'edit' && $user_id > 0) {

        $new_name  = trim($_POST['full_name'] ?? '');
        $new_email = strtolower(trim($_POST['email'] ?? ''));

        if (empty($new_name) || empty($new_email)) {
            $message  = "Name and email cannot be empty.";
            $msg_type = "error";

        } elseif (!filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
            $message  = "Please enter a valid email address.";
            $msg_type = "error";

        } else {
            // Check email is not taken by a different user
            $stmt = mysqli_prepare($conn,
                "SELECT id FROM users WHERE email = ? AND id != ?"
            );
            mysqli_stmt_bind_param($stmt, "si", $new_email, $user_id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_store_result($stmt);
            $email_taken = mysqli_stmt_num_rows($stmt) > 0;
            mysqli_stmt_close($stmt);

            if ($email_taken) {
                $message  = "That email address is already in use by another account.";
                $msg_type = "error";
            } else {
                $stmt = mysqli_prepare($conn,
                    "UPDATE users SET full_name = ?, email = ? WHERE id = ?"
                );
                mysqli_stmt_bind_param($stmt, "ssi", $new_name, $new_email, $user_id);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
                $message  = "User details updated successfully.";
                $msg_type = "success";
            }
        }
    }

    // -------------------------------------------------------------------
    // ACTION: Toggle active / disabled
    // -------------------------------------------------------------------
    elseif ($action === 'toggle_status' && $user_id > 0) {

        if ($user_id === $current_admin_id) {
            $message  = "You cannot disable your own account.";
            $msg_type = "error";
        } else {
            // Flip the current is_active value
            $stmt = mysqli_prepare($conn,
                "UPDATE users SET is_active = 1 - is_active WHERE id = ?"
            );
            mysqli_stmt_bind_param($stmt, "i", $user_id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            $message  = "Account status updated.";
            $msg_type = "success";
        }
    }

    // -------------------------------------------------------------------
    // ACTION: Promote to admin or demote to student
    // -------------------------------------------------------------------
    elseif ($action === 'toggle_role' && $user_id > 0) {

        if ($user_id === $current_admin_id) {
            $message  = "You cannot change your own role.";
            $msg_type = "error";
        } else {
            // Read current role first
            $stmt = mysqli_prepare($conn, "SELECT role FROM users WHERE id = ?");
            mysqli_stmt_bind_param($stmt, "i", $user_id);
            mysqli_stmt_execute($stmt);
            $r = mysqli_stmt_get_result($stmt);
            $u = mysqli_fetch_assoc($r);
            mysqli_stmt_close($stmt);

            $new_role = ($u['role'] === 'admin') ? 'student' : 'admin';

            $stmt = mysqli_prepare($conn, "UPDATE users SET role = ? WHERE id = ?");
            mysqli_stmt_bind_param($stmt, "si", $new_role, $user_id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);

            $label    = $new_role === 'admin' ? 'promoted to Admin' : 'demoted to Student';
            $message  = "User has been {$label}.";
            $msg_type = "success";
        }
    }

    // -------------------------------------------------------------------
    // ACTION: Delete account (blocked if user has votes)
    // -------------------------------------------------------------------
    elseif ($action === 'delete' && $user_id > 0) {

        if ($user_id === $current_admin_id) {
            $message  = "You cannot delete your own account.";
            $msg_type = "error";
        } else {
            // Check for votes first — FK RESTRICT would block it anyway,
            // but we give a human-readable message instead
            $stmt = mysqli_prepare($conn,
                "SELECT COUNT(*) AS c FROM votes WHERE student_id = ?"
            );
            mysqli_stmt_bind_param($stmt, "i", $user_id);
            mysqli_stmt_execute($stmt);
            $r = mysqli_stmt_get_result($stmt);
            $has_votes = mysqli_fetch_assoc($r)['c'] > 0;
            mysqli_stmt_close($stmt);

            if ($has_votes) {
                $message  = "This student has voting records and cannot be deleted. Disable the account instead.";
                $msg_type = "error";
            } else {
                // Also free up their eligible_students slot so they could re-register if needed
                $stmt = mysqli_prepare($conn, "SELECT student_number FROM users WHERE id = ?");
                mysqli_stmt_bind_param($stmt, "i", $user_id);
                mysqli_stmt_execute($stmt);
                $r  = mysqli_stmt_get_result($stmt);
                $u  = mysqli_fetch_assoc($r);
                mysqli_stmt_close($stmt);

                // Unclaim their eligible_students row
                $stmt = mysqli_prepare($conn,
                    "UPDATE eligible_students SET is_claimed = 0 WHERE student_number = ?"
                );
                mysqli_stmt_bind_param($stmt, "s", $u['student_number']);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);

                // Now delete the user
                $stmt = mysqli_prepare($conn, "DELETE FROM users WHERE id = ?");
                mysqli_stmt_bind_param($stmt, "i", $user_id);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);

                $message  = "Account deleted. The student may register again if needed.";
                $msg_type = "success";
            }
        }
    }

    // Redirect back to GET to prevent form resubmission on refresh
    // Carry any filter state back in the URL
    $qs = http_build_query([
        'search'         => $_POST['search_carry']  ?? '',
        'status_filter'  => $_POST['status_carry']  ?? '',
        'voted_filter'   => $_POST['voted_carry']   ?? '',
        'election_filter'=> $_POST['election_carry'] ?? '',
        'msg'            => $message,
        'msg_type'       => $msg_type,
    ]);
    header("Location: admin_users.php?" . $qs);
    exit();
}

// -----------------------------------------------------------------------
// Pick up flash message from redirect
// -----------------------------------------------------------------------
if (isset($_GET['msg']) && !empty($_GET['msg'])) {
    $message  = htmlspecialchars($_GET['msg']);
    $msg_type = htmlspecialchars($_GET['msg_type'] ?? 'success');
}

// -----------------------------------------------------------------------
// Read filter/search values from GET
// -----------------------------------------------------------------------
$search          = trim($_GET['search']          ?? '');
$status_filter   = $_GET['status_filter']        ?? '';   // '' | 'active' | 'disabled'
$voted_filter    = $_GET['voted_filter']         ?? '';   // '' | 'voted' | 'not_voted'
$election_filter = intval($_GET['election_filter'] ?? 0);

// -----------------------------------------------------------------------
// Fetch all elections for the voted-filter dropdown
// -----------------------------------------------------------------------
$r         = mysqli_query($conn, "SELECT id, title, status FROM elections ORDER BY created_at DESC");
$elections = [];
while ($row = mysqli_fetch_assoc($r)) {
    $elections[] = $row;
}

// -----------------------------------------------------------------------
// Build the user query dynamically based on active filters
// -----------------------------------------------------------------------
$where_parts  = ["u.role = 'student'"]; // always exclude admins from this list
$bind_types   = '';
$bind_values  = [];

if (!empty($search)) {
    $where_parts[] = "(u.full_name LIKE ? OR u.email LIKE ?)";
    $like = '%' . $search . '%';
    $bind_types  .= 'ss';
    $bind_values[] = $like;
    $bind_values[] = $like;
}

if ($status_filter === 'active') {
    $where_parts[] = "u.is_active = 1";
} elseif ($status_filter === 'disabled') {
    $where_parts[] = "u.is_active = 0";
}

// Voted filter only makes sense if an election is also selected
$join_votes = '';
if (!empty($voted_filter) && $election_filter > 0) {
    if ($voted_filter === 'voted') {
        $join_votes    = "INNER JOIN votes v ON v.student_id = u.id AND v.election_id = ?";
        $bind_types   .= 'i';
        $bind_values[] = $election_filter;
    } elseif ($voted_filter === 'not_voted') {
        $join_votes    = "LEFT JOIN votes v ON v.student_id = u.id AND v.election_id = ?";
        $where_parts[] = "v.id IS NULL";
        $bind_types   .= 'i';
        $bind_values[] = $election_filter;
    }
}

$where_sql = "WHERE " . implode(" AND ", $where_parts);

$sql = "SELECT
            u.id,
            u.student_number,
            u.full_name,
            u.email,
            u.role,
            u.is_active,
            u.registered_at,
            (SELECT COUNT(*) FROM votes vv WHERE vv.student_id = u.id) AS total_votes_cast
        FROM users u
        {$join_votes}
        {$where_sql}
        ORDER BY u.registered_at DESC";

$stmt = mysqli_prepare($conn, $sql);

if (!empty($bind_values)) {
    // bind_param needs variables, so we use spread with references
    $refs = [$stmt, $bind_types];
    foreach ($bind_values as &$val) {
        $refs[] = &$val;
    }
    call_user_func_array('mysqli_stmt_bind_param', $refs);
}

mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$users  = [];
while ($row = mysqli_fetch_assoc($result)) {
    $users[] = $row;
}
mysqli_stmt_close($stmt);

// Also fetch all admins separately for the admin list section
$r      = mysqli_query($conn, "SELECT id, full_name, email, is_active, registered_at FROM users WHERE role = 'admin' ORDER BY registered_at ASC");
$admins = [];
while ($row = mysqli_fetch_assoc($r)) {
    $admins[] = $row;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PollPoint &mdash; Manage Users</title>
    <link rel="stylesheet" href="styles.css">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: var(--font-sans);
            background-color: var(--bg-page);
            color: var(--text-primary);
            min-height: 100vh;
            transition: background-color var(--transition-base), color var(--transition-base);
        }

        /* ===================== TOPBAR ===================== */
        .topbar {
            background: var(--surface);
            border-bottom: 1px solid var(--border-subtle);
            padding: 0 32px;
            height: 60px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .topbar-left { display: flex; align-items: center; gap: 32px; }

        .topbar-logo {
            font-family: var(--font-serif);
            font-size: 1.3rem;
            font-weight: 900;
        }

        .topbar-logo span { color: var(--accent); }

        .topbar-nav { display: flex; gap: 4px; }

        .topbar-nav a {
            padding: 6px 14px;
            border-radius: 7px;
            text-decoration: none;
            font-size: 0.85rem;
            color: var(--text-muted);
            transition: background 0.2s, color 0.2s;
        }

        .topbar-nav a:hover,
        .topbar-nav a.active {
            background: var(--surface-elevated);
            color: var(--text-primary);
        }

        .topbar-right { display: flex; align-items: center; gap: 16px; }

        .admin-badge {
            font-size: 0.72rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            padding: 3px 9px;
            border-radius: 20px;
            background: var(--accent-soft);
            color: var(--accent);
        }

        .btn-logout {
            padding: 7px 16px;
            background: transparent;
            border: 1px solid var(--button-secondary-border);
            border-radius: var(--radius-md);
            color: var(--text-muted);
            font-family: var(--font-sans);
            font-size: 0.82rem;
            cursor: pointer;
            text-decoration: none;
            transition: border-color var(--transition-base), color var(--transition-base);
        }

        .btn-logout:hover { border-color: var(--danger); color: var(--danger); }

        /* ===================== MAIN ===================== */
        .main {
            max-width: 1100px;
            margin: 0 auto;
            padding: 36px 24px 60px;
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 28px;
        }

        .page-header h1 {
            font-family: var(--font-serif);
            font-size: 1.7rem;
            margin-bottom: 4px;
        }

        .page-header p { color: var(--text-muted); font-size: 0.9rem; }

        /* ===================== ALERT ===================== */
        .alert {
            padding: 13px 18px;
            border-radius: var(--radius);
            margin-bottom: 22px;
            font-size: 0.875rem;
            display: flex;
            gap: 8px;
            align-items: flex-start;
        }

        .alert-success {
            background: var(--success-soft);
            border: 1px solid var(--success);
            color: var(--success);
        }

        .alert-error {
            background: var(--danger-soft);
            border: 1px solid var(--danger);
            color: var(--danger);
        }

        /* ===================== FILTER BAR ===================== */
        .filter-bar {
            background: var(--surface);
            border: 1px solid var(--card-border);
            border-radius: 12px;
            padding: 18px 20px;
            margin-bottom: 20px;
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            align-items: flex-end;
        }

        .filter-group { display: flex; flex-direction: column; gap: 6px; }

        .filter-group label {
            font-size: 0.72rem;
            font-weight: 600;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.07em;
        }

        .filter-group input[type="text"],
        .filter-group select {
            padding: 9px 12px;
            background: var(--input-background);
            border: 1px solid var(--input-border);
            border-radius: 8px;
            color: var(--input-text);
            font-family: var(--font-sans);
            font-size: 0.875rem;
            outline: none;
            transition: border-color var(--transition-base);
            min-width: 160px;
        }

        .filter-group input:focus,
        .filter-group select:focus { border-color: var(--accent); }

        .filter-group select option { background: var(--surface); }

        .btn-filter {
            padding: 9px 20px;
            background: var(--button-primary-bg);
            color: var(--button-primary-text);
            border: none;
            border-radius: 8px;
            font-family: var(--font-sans);
            font-size: 0.875rem;
            font-weight: 700;
            cursor: pointer;
            transition: background var(--transition-base);
            align-self: flex-end;
        }

        .btn-filter:hover { background: var(--button-primary-hover, var(--accent-hover)); }

        .btn-reset {
            padding: 9px 16px;
            background: transparent;
                border: 1px solid var(--button-secondary-border);
            border-radius: 8px;
            color: var(--text-muted);
                font-family: var(--font-sans);
            font-size: 0.875rem;
            cursor: pointer;
            text-decoration: none;
            transition: border-color 0.2s, color 0.2s;
            align-self: flex-end;
        }

        .btn-reset:hover { border-color: var(--text-muted); color: var(--text-primary); }

        /* ===================== RESULTS COUNT ===================== */
        .results-count {
            font-size: 0.82rem;
            color: var(--text-muted);
            margin-bottom: 12px;
            padding-left: 2px;
        }

        .results-count strong { color: var(--text-primary); }

        /* ===================== TABLE ===================== */
        .table-wrap {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 12px;
            overflow: hidden;
            margin-bottom: 32px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.875rem;
        }

        thead th {
            padding: 13px 18px;
            text-align: left;
            font-size: 0.72rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.07em;
            color: var(--text-muted);
            background: var(--surface-elevated);
            border-bottom: 1px solid var(--border);
            white-space: nowrap;
        }

        tbody td {
            padding: 14px 18px;
            border-bottom: 1px solid var(--border);
            vertical-align: middle;
        }

        tbody tr:last-child td { border-bottom: none; }
        tbody tr:hover td { background: var(--surface-elevated); }

        .student-name { font-weight: 600; color: var(--text-primary); }
        .student-email { font-size: 0.8rem; color: var(--text-muted); margin-top: 2px; }
        .student-num { font-size: 0.75rem; color: var(--text-muted); margin-top: 2px; font-family: monospace; }

        /* Status pill */
        .pill {
            font-size: 0.7rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            padding: 3px 9px;
            border-radius: 20px;
            white-space: nowrap;
        }

        .pill-active   { background: var(--success-soft);  color: var(--success); }
        .pill-disabled { background: var(--danger-soft);   color: var(--danger); }
        .pill-admin    { background: var(--accent-soft);   color: var(--accent); }
        .pill-student  { background: var(--border-subtle); color: var(--text-muted); }

        /* Action buttons in table */
        .action-btns {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
        }

        .btn-action {
            padding: 5px 11px;
            border-radius: 6px;
            font-family: var(--font-sans);
            font-size: 0.75rem;
            font-weight: 600;
            cursor: pointer;
            border: 1px solid var(--border);
            background: transparent;
            color: var(--text-muted);
            transition: all 0.18s;
            white-space: nowrap;
        }

        .btn-action:hover { background: var(--bg-page); color: var(--text-primary); border-color: var(--text-muted); }
        .btn-action.edit:hover  { border-color: var(--accent); color: var(--accent); }
        .btn-action.danger:hover { border-color: var(--danger); color: var(--danger); }
        .btn-action.promote:hover { border-color: var(--accent); color: var(--accent); }

        .empty-row td {
            text-align: center;
            color: var(--text-muted);
            padding: 40px;
        }

        /* Section divider heading */
        .section-heading {
            font-family: var(--font-serif);
            font-size: 1.1rem;
            margin-bottom: 12px;
            margin-top: 8px;
        }

        /* ===================== EDIT MODAL ===================== */
        .modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: var(--modal-backdrop);
            z-index: 200;
            align-items: center;
            justify-content: center;
        }

        .modal-overlay.active { display: flex; }

        .modal {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 36px 32px;
            max-width: 440px;
            width: 92%;
            animation: fadeUp 0.22s ease both;
        }

        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(14px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        .modal h3 {
            font-family: var(--font-serif);
            font-size: 1.2rem;
            margin-bottom: 22px;
        }

        .form-group { margin-bottom: 18px; }

        .form-group label {
            display: block;
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.07em;
            margin-bottom: 7px;
        }

        .form-group input {
            width: 100%;
            padding: 10px 13px;
            background: var(--bg-page);
            border: 1px solid var(--border);
            border-radius: 8px;
            color: var(--text-primary);
            font-family: var(--font-sans);
            font-size: 0.9rem;
            outline: none;
            transition: border-color 0.2s;
        }

        .form-group input:focus { border-color: var(--accent); }

        .modal-buttons {
            display: flex;
            gap: 10px;
            margin-top: 24px;
        }

        .btn-modal-cancel {
            flex: 1;
            padding: 10px;
            background: transparent;
            border: 1px solid var(--border);
            border-radius: 8px;
            color: var(--text-muted);
            font-family: var(--font-sans);
            font-size: 0.9rem;
            cursor: pointer;
            transition: all 0.18s;
        }

        .btn-modal-cancel:hover { border-color: var(--text-muted); color: var(--text-primary); }

        .btn-modal-save {
            flex: 1;
            padding: 10px;
            background: var(--button-primary-bg);
            color: var(--button-primary-text);
            border: none;
            border-radius: 8px;
            font-family: var(--font-sans);
            font-size: 0.9rem;
            font-weight: 700;
            cursor: pointer;
            transition: background var(--transition-base);
        }

        .btn-modal-save:hover { background: var(--button-primary-hover); }

        @media (max-width: 900px) {
            .topbar-nav { display: none; }
        }

        @media (max-width: 700px) {
            .topbar  { padding: 0 16px; }
            .main    { padding: 24px 16px 48px; }
            .filter-bar { flex-direction: column; }
            .filter-group input,
            .filter-group select { min-width: 100%; }
            .btn-filter, .btn-reset { width: 100%; text-align: center; }
            table { font-size: 0.8rem; }
            thead th, tbody td { padding: 10px 12px; }
        }
    </style>
    <script src="script.js"></script>
</head>
<body>

<!-- TOPBAR -->
<nav class="topbar">
    <div class="topbar-left">
        <div class="topbar-logo">Poll<span>Point</span></div>
        <div class="topbar-nav">
            <a href="admin_dashboard.php">Dashboard</a>
            <a href="admin_users.php" class="active">Users</a>
            <a href="admin_elections.php">Elections</a>
            <a href="admin_eligible.php">Eligible Students</a>
        </div>
    </div>
    <div class="topbar-right">
        <span class="admin-badge">Admin</span>
        <button id="theme-toggle-btn" class="theme-toggle-btn" onclick="toggleTheme()">🌙 Dark Mode</button>
        <a href="logout.php" class="btn-logout">Sign Out</a>
    </div>
</nav>

<main class="main">

    <div class="page-header">
        <div>
            <h1>Manage Users</h1>
            <p>Search, filter, edit and manage all student accounts.</p>
        </div>
    </div>

    <!-- Flash message -->
    <?php if (!empty($message)): ?>
    <div class="alert alert-<?php echo $msg_type; ?>">
        <?php echo $msg_type === 'success' ? '&#10003;' : '&#9888;'; ?>
        <?php echo $message; ?>
    </div>
    <?php endif; ?>

    <!-- ================= FILTER BAR ================= -->
    <form method="GET" action="admin_users.php">
        <div class="filter-bar">

            <div class="filter-group">
                <label>Search</label>
                <input type="text" name="search"
                    placeholder="Name or email..."
                    value="<?php echo htmlspecialchars($search); ?>">
            </div>

            <div class="filter-group">
                <label>Account Status</label>
                <select name="status_filter">
                    <option value="">All statuses</option>
                    <option value="active"   <?php echo $status_filter === 'active'   ? 'selected' : ''; ?>>Active</option>
                    <option value="disabled" <?php echo $status_filter === 'disabled' ? 'selected' : ''; ?>>Disabled</option>
                </select>
            </div>

            <div class="filter-group">
                <label>Election</label>
                <select name="election_filter">
                    <option value="0">Select election...</option>
                    <?php foreach ($elections as $el): ?>
                    <option value="<?php echo $el['id']; ?>"
                        <?php echo $election_filter === $el['id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($el['title']); ?>
                        (<?php echo $el['status']; ?>)
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filter-group">
                <label>Voted Status</label>
                <select name="voted_filter">
                    <option value="">All</option>
                    <option value="voted"     <?php echo $voted_filter === 'voted'     ? 'selected' : ''; ?>>Has Voted</option>
                    <option value="not_voted" <?php echo $voted_filter === 'not_voted' ? 'selected' : ''; ?>>Not Yet Voted</option>
                </select>
            </div>

            <button type="submit" class="btn-filter">Apply</button>
            <a href="admin_users.php" class="btn-reset">Reset</a>

        </div>
    </form>

    <!-- ================= STUDENTS TABLE ================= -->
    <div class="results-count">
        Showing <strong><?php echo count($users); ?></strong> student<?php echo count($users) !== 1 ? 's' : ''; ?>
    </div>

    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Student</th>
                    <th>Status</th>
                    <th>Role</th>
                    <th>Votes Cast</th>
                    <th>Registered</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($users)): ?>
                <tr class="empty-row">
                    <td colspan="6">No students found matching your filters.</td>
                </tr>
            <?php else: ?>
                <?php foreach ($users as $u): ?>
                <tr>
                    <td>
                        <div class="student-name"><?php echo htmlspecialchars($u['full_name']); ?></div>
                        <div class="student-email"><?php echo htmlspecialchars($u['email']); ?></div>
                        <div class="student-num"><?php echo htmlspecialchars($u['student_number']); ?></div>
                    </td>
                    <td>
                        <span class="pill <?php echo $u['is_active'] ? 'pill-active' : 'pill-disabled'; ?>">
                            <?php echo $u['is_active'] ? 'Active' : 'Disabled'; ?>
                        </span>
                    </td>
                    <td>
                        <span class="pill <?php echo $u['role'] === 'admin' ? 'pill-admin' : 'pill-student'; ?>">
                            <?php echo ucfirst($u['role']); ?>
                        </span>
                    </td>
                    <td style="color:var(--text-muted)">
                        <?php echo $u['total_votes_cast']; ?>
                    </td>
                    <td style="color:var(--text-muted);white-space:nowrap">
                        <?php echo date('d M Y', strtotime($u['registered_at'])); ?>
                    </td>
                    <td>
                        <div class="action-btns">

                            <!-- Edit button — opens modal -->
                            <button type="button" class="btn-action edit"
                                onclick="openEditModal(
                                    <?php echo $u['id']; ?>,
                                    '<?php echo addslashes(htmlspecialchars($u['full_name'])); ?>',
                                    '<?php echo addslashes(htmlspecialchars($u['email'])); ?>'
                                )">
                                Edit
                            </button>

                            <!-- Toggle active/disabled -->
                            <form method="POST" style="display:inline">
                                <input type="hidden" name="action"          value="toggle_status">
                                <input type="hidden" name="user_id"         value="<?php echo $u['id']; ?>">
                                <input type="hidden" name="search_carry"    value="<?php echo htmlspecialchars($search); ?>">
                                <input type="hidden" name="status_carry"    value="<?php echo htmlspecialchars($status_filter); ?>">
                                <input type="hidden" name="voted_carry"     value="<?php echo htmlspecialchars($voted_filter); ?>">
                                <input type="hidden" name="election_carry"  value="<?php echo $election_filter; ?>">
                                <button type="submit" class="btn-action <?php echo $u['is_active'] ? 'danger' : ''; ?>">
                                    <?php echo $u['is_active'] ? 'Disable' : 'Enable'; ?>
                                </button>
                            </form>

                            <!-- Promote / Demote -->
                            <?php if ($u['id'] !== $current_admin_id): ?>
                            <form method="POST" style="display:inline">
                                <input type="hidden" name="action"         value="toggle_role">
                                <input type="hidden" name="user_id"        value="<?php echo $u['id']; ?>">
                                <input type="hidden" name="search_carry"   value="<?php echo htmlspecialchars($search); ?>">
                                <input type="hidden" name="status_carry"   value="<?php echo htmlspecialchars($status_filter); ?>">
                                <input type="hidden" name="voted_carry"    value="<?php echo htmlspecialchars($voted_filter); ?>">
                                <input type="hidden" name="election_carry" value="<?php echo $election_filter; ?>">
                                <button type="submit" class="btn-action promote"
                                    onclick="return confirm('<?php echo $u['role'] === 'admin' ? 'Demote this user to student?' : 'Promote this user to admin?'; ?>')">
                                    <?php echo $u['role'] === 'admin' ? 'Demote' : 'Promote'; ?>
                                </button>
                            </form>
                            <?php endif; ?>

                            <!-- Delete (only if no votes) -->
                            <?php if ($u['total_votes_cast'] == 0 && $u['id'] !== $current_admin_id): ?>
                            <form method="POST" style="display:inline">
                                <input type="hidden" name="action"         value="delete">
                                <input type="hidden" name="user_id"        value="<?php echo $u['id']; ?>">
                                <input type="hidden" name="search_carry"   value="<?php echo htmlspecialchars($search); ?>">
                                <input type="hidden" name="status_carry"   value="<?php echo htmlspecialchars($status_filter); ?>">
                                <input type="hidden" name="voted_carry"    value="<?php echo htmlspecialchars($voted_filter); ?>">
                                <input type="hidden" name="election_carry" value="<?php echo $election_filter; ?>">
                                <button type="submit" class="btn-action danger"
                                    onclick="return confirm('Delete this account permanently?')">
                                    Delete
                                </button>
                            </form>
                            <?php endif; ?>

                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- ================= ADMIN ACCOUNTS SECTION ================= -->
    <h2 class="section-heading">Administrator Accounts</h2>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Status</th>
                    <th>Added</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($admins as $a): ?>
            <tr>
                <td>
                    <div class="student-name">
                        <?php echo htmlspecialchars($a['full_name']); ?>
                        <?php if ($a['id'] === $current_admin_id): ?>
                            <span class="pill pill-admin" style="margin-left:6px;font-size:0.65rem">You</span>
                        <?php endif; ?>
                    </div>
                </td>
                <td style="color:var(--text-muted)"><?php echo htmlspecialchars($a['email']); ?></td>
                <td>
                    <span class="pill <?php echo $a['is_active'] ? 'pill-active' : 'pill-disabled'; ?>">
                        <?php echo $a['is_active'] ? 'Active' : 'Disabled'; ?>
                    </span>
                </td>
                <td style="color:var(--text-muted)">
                    <?php echo date('d M Y', strtotime($a['registered_at'])); ?>
                </td>
                <td>
                    <div class="action-btns">
                        <?php if ($a['id'] !== $current_admin_id): ?>
                        <form method="POST" style="display:inline">
                            <input type="hidden" name="action"         value="toggle_role">
                            <input type="hidden" name="user_id"        value="<?php echo $a['id']; ?>">
                            <input type="hidden" name="search_carry"   value="<?php echo htmlspecialchars($search); ?>">
                            <input type="hidden" name="status_carry"   value="<?php echo htmlspecialchars($status_filter); ?>">
                            <input type="hidden" name="voted_carry"    value="<?php echo htmlspecialchars($voted_filter); ?>">
                            <input type="hidden" name="election_carry" value="<?php echo $election_filter; ?>">
                            <button type="submit" class="btn-action promote"
                                onclick="return confirm('Demote this admin to student?')">
                                Demote
                            </button>
                        </form>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

</main>

<!-- ===================== EDIT MODAL ===================== -->
<div class="modal-overlay" id="editModal">
    <div class="modal">
        <h3>Edit Student Details</h3>
        <form method="POST" action="admin_users.php">
            <input type="hidden" name="action"          value="edit">
            <input type="hidden" name="user_id"         id="edit_user_id">
            <input type="hidden" name="search_carry"    value="<?php echo htmlspecialchars($search); ?>">
            <input type="hidden" name="status_carry"    value="<?php echo htmlspecialchars($status_filter); ?>">
            <input type="hidden" name="voted_carry"     value="<?php echo htmlspecialchars($voted_filter); ?>">
            <input type="hidden" name="election_carry"  value="<?php echo $election_filter; ?>">

            <div class="form-group">
                <label>Full Name</label>
                <input type="text" name="full_name" id="edit_full_name" required>
            </div>

            <div class="form-group">
                <label>Email Address</label>
                <input type="email" name="email" id="edit_email" required>
            </div>

            <div class="modal-buttons">
                <button type="button" class="btn-modal-cancel" onclick="closeEditModal()">Cancel</button>
                <button type="submit" class="btn-modal-save">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<script>
    function openEditModal(userId, fullName, email) {
        document.getElementById('edit_user_id').value   = userId;
        document.getElementById('edit_full_name').value = fullName;
        document.getElementById('edit_email').value     = email;
        document.getElementById('editModal').classList.add('active');
    }

    function closeEditModal() {
        document.getElementById('editModal').classList.remove('active');
    }

    // Close modal by clicking outside it
    document.getElementById('editModal').addEventListener('click', function(e) {
        if (e.target === this) closeEditModal();
    });
</script>

</body>
</html>