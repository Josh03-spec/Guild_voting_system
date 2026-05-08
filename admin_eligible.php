<?php
/*
 * admin_eligible.php
 * -------------------
 * Admin page to manage the eligible students list.
 *
 * Features:
 *   - View all eligible students with claimed/unclaimed status
 *   - Add a single eligible student
 *   - Edit an existing eligible student record
 *   - Delete a single record (blocked if already claimed and has a user account)
 *   - Delete ALL records that are unclaimed (bulk clear)
 *   - Delete ALL records including claimed ones (full wipe — with strong warning)
 *   - Upload a CSV file to bulk-import eligible students
 *
 * CSV format expected (with or without header row):
 *   student_number, full_name, official_email
 *   Example row: 2500501011,Apio Sandra,apio.sandra@stud.umu.ac.ug
 *
 * No database changes needed — works entirely with the existing schema.
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

$message  = '';
$msg_type = '';

// -----------------------------------------------------------------------
// Handle POST actions
// -----------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action = $_POST['action'] ?? '';

    // -------------------------------------------------------------------
    // ACTION: Add a single eligible student
    // -------------------------------------------------------------------
    if ($action === 'add') {

        $student_number = strtoupper(trim($_POST['student_number'] ?? ''));
        $full_name      = trim($_POST['full_name']      ?? '');
        $official_email = strtolower(trim($_POST['official_email'] ?? ''));

        if (empty($student_number) || empty($full_name) || empty($official_email)) {
            $message  = "All three fields are required.";
            $msg_type = "error";

        } elseif (!preg_match('/^\d{10}$/', $student_number)) {
            $message  = "Student number must be exactly 10 digits.";
            $msg_type = "error";

        } elseif (!filter_var($official_email, FILTER_VALIDATE_EMAIL)) {
            $message  = "Please enter a valid email address.";
            $msg_type = "error";

        } elseif (!str_ends_with($official_email, '@stud.umu.ac.ug')) {
            $message  = "Email must be a university address (@stud.umu.ac.ug).";
            $msg_type = "error";

        } else {
            $stmt = mysqli_prepare($conn,
                "INSERT INTO eligible_students (student_number, full_name, official_email)
                 VALUES (?, ?, ?)"
            );
            mysqli_stmt_bind_param($stmt, "sss", $student_number, $full_name, $official_email);

            if (mysqli_stmt_execute($stmt)) {
                $message  = "Student {$full_name} added to the eligibility list.";
                $msg_type = "success";
            } else {
                // Duplicate student_number or email — UNIQUE constraint fires
                $message  = "A record with that student number or email already exists.";
                $msg_type = "error";
            }
            mysqli_stmt_close($stmt);
        }
    }

    // -------------------------------------------------------------------
    // ACTION: Edit an existing eligible student record
    // -------------------------------------------------------------------
    elseif ($action === 'edit') {

        $record_id      = intval($_POST['record_id']      ?? 0);
        $student_number = strtoupper(trim($_POST['student_number'] ?? ''));
        $full_name      = trim($_POST['full_name']      ?? '');
        $official_email = strtolower(trim($_POST['official_email'] ?? ''));

        if ($record_id <= 0 || empty($student_number) || empty($full_name) || empty($official_email)) {
            $message  = "All fields are required.";
            $msg_type = "error";

        } elseif (!preg_match('/^\d{10}$/', $student_number)) {
            $message  = "Student number must be exactly 10 digits.";
            $msg_type = "error";

        } elseif (!filter_var($official_email, FILTER_VALIDATE_EMAIL)) {
            $message  = "Please enter a valid email address.";
            $msg_type = "error";

        } elseif (!str_ends_with($official_email, '@stud.umu.ac.ug')) {
            $message  = "Email must be a university address (@stud.umu.ac.ug).";
            $msg_type = "error";

        } else {
            // Check for conflicts with OTHER records
            $stmt = mysqli_prepare($conn,
                "SELECT id FROM eligible_students
                 WHERE (student_number = ? OR official_email = ?) AND id != ?"
            );
            mysqli_stmt_bind_param($stmt, "ssi", $student_number, $official_email, $record_id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_store_result($stmt);
            $conflict = mysqli_stmt_num_rows($stmt) > 0;
            mysqli_stmt_close($stmt);

            if ($conflict) {
                $message  = "Another record already uses that student number or email.";
                $msg_type = "error";
            } else {
                $stmt = mysqli_prepare($conn,
                    "UPDATE eligible_students
                     SET student_number = ?, full_name = ?, official_email = ?
                     WHERE id = ?"
                );
                mysqli_stmt_bind_param($stmt, "sssi",
                    $student_number, $full_name, $official_email, $record_id);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
                $message  = "Record updated successfully.";
                $msg_type = "success";
            }
        }
    }

    // -------------------------------------------------------------------
    // ACTION: Delete a single record
    // -------------------------------------------------------------------
    elseif ($action === 'delete') {

        $record_id = intval($_POST['record_id'] ?? 0);

        if ($record_id <= 0) {
            $message  = "Invalid record.";
            $msg_type = "error";

        } else {
            // Check if claimed AND the user account still exists
            $stmt = mysqli_prepare($conn,
                "SELECT es.is_claimed, es.student_number,
                        (SELECT COUNT(*) FROM users u WHERE u.student_number = es.student_number) AS has_user
                 FROM eligible_students es WHERE es.id = ?"
            );
            mysqli_stmt_bind_param($stmt, "i", $record_id);
            mysqli_stmt_execute($stmt);
            $r   = mysqli_stmt_get_result($stmt);
            $row = mysqli_fetch_assoc($r);
            mysqli_stmt_close($stmt);

            if ($row && $row['is_claimed'] && $row['has_user']) {
                // Claimed and user account exists — block deletion
                // Admin should delete the user account first via admin_users.php
                $message  = "This student has already registered an account. Delete their user account on the Users page first.";
                $msg_type = "error";
            } else {
                $stmt = mysqli_prepare($conn,
                    "DELETE FROM eligible_students WHERE id = ?"
                );
                mysqli_stmt_bind_param($stmt, "i", $record_id);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
                $message  = "Record deleted.";
                $msg_type = "success";
            }
        }
    }

    // -------------------------------------------------------------------
    // ACTION: Delete all UNCLAIMED records only
    // -------------------------------------------------------------------
    elseif ($action === 'delete_unclaimed') {

        $stmt = mysqli_prepare($conn,
            "DELETE FROM eligible_students WHERE is_claimed = 0"
        );
        mysqli_stmt_execute($stmt);
        $deleted = mysqli_stmt_affected_rows($stmt);
        mysqli_stmt_close($stmt);
        $message  = "{$deleted} unclaimed record(s) deleted.";
        $msg_type = "success";
    }

    // -------------------------------------------------------------------
    // ACTION: Delete ALL records (full wipe)
    // Claimed records whose users have votes are protected by FK RESTRICT
    // so those simply stay — we delete what we can
    // -------------------------------------------------------------------
    elseif ($action === 'delete_all') {

        // First unclaim records where the user has no account (safe to delete)
        // Then delete all unclaimed rows
        // For claimed rows where user exists but has no votes,
        // we skip — admin must handle via Users page
        // We do a simple DELETE and let FK handle the rest
        $deleted = 0;

        // Delete unclaimed first (always safe)
        $stmt = mysqli_prepare($conn,
            "DELETE FROM eligible_students WHERE is_claimed = 0"
        );
        mysqli_stmt_execute($stmt);
        $deleted += mysqli_stmt_affected_rows($stmt);
        mysqli_stmt_close($stmt);

        // Delete claimed records where no matching user exists
        // (edge case: record marked claimed but user was already deleted)
        $stmt = mysqli_prepare($conn,
            "DELETE es FROM eligible_students es
             LEFT JOIN users u ON u.student_number = es.student_number
             WHERE u.id IS NULL"
        );
        mysqli_stmt_execute($stmt);
        $deleted += mysqli_stmt_affected_rows($stmt);
        mysqli_stmt_close($stmt);

        // Count what remains (claimed + active user accounts)
        $r        = mysqli_query($conn, "SELECT COUNT(*) AS c FROM eligible_students");
        $remaining = mysqli_fetch_assoc($r)['c'];

        if ($remaining > 0) {
            $message  = "{$deleted} record(s) deleted. {$remaining} record(s) remain because their owners have registered accounts — remove those from the Users page first.";
        } else {
            $message  = "All {$deleted} records deleted. The eligibility list is now empty.";
        }
        $msg_type = "success";
    }

    // -------------------------------------------------------------------
    // ACTION: CSV bulk upload
    // -------------------------------------------------------------------
    elseif ($action === 'csv_upload') {

        // Check a file was actually uploaded without errors
        if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
            $message  = "No file uploaded or an upload error occurred.";
            $msg_type = "error";

        } else {
            $file     = $_FILES['csv_file'];
            $ext      = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

            if ($ext !== 'csv') {
                $message  = "Only .csv files are accepted.";
                $msg_type = "error";

            } elseif ($file['size'] > 2 * 1024 * 1024) {
                // 2 MB limit — a CSV of 10,000 students is well under this
                $message  = "File is too large. Maximum size is 2 MB.";
                $msg_type = "error";

            } else {
                // Open the uploaded file
                $handle = fopen($file['tmp_name'], 'r');

                if (!$handle) {
                    $message  = "Could not read the uploaded file.";
                    $msg_type = "error";

                } else {
                    $inserted  = 0;   // rows successfully inserted
                    $skipped   = 0;   // rows skipped (duplicate or invalid)
                    $errors    = [];  // specific row-level problems (first 5 only)
                    $row_num   = 0;

                    // Prepare the insert statement once, reuse for every row
                    $stmt = mysqli_prepare($conn,
                        "INSERT IGNORE INTO eligible_students
                             (student_number, full_name, official_email)
                         VALUES (?, ?, ?)"
                        // INSERT IGNORE silently skips rows that violate UNIQUE constraints
                        // so existing records are never overwritten
                    );

                    while (($row = fgetcsv($handle, 1000, ',')) !== false) {
                        $row_num++;

                        // Skip completely empty lines
                        if (count(array_filter($row)) === 0) continue;

                        // Skip header row if present (detect by checking first cell)
                        if ($row_num === 1 &&
                            (strtolower(trim($row[0])) === 'student_number' ||
                             strtolower(trim($row[0])) === 'student number' ||
                             !is_numeric(trim($row[0])))) {
                            continue;
                        }

                        // Expect exactly 3 columns
                        if (count($row) < 3) {
                            $skipped++;
                            if (count($errors) < 5) {
                                $errors[] = "Row {$row_num}: expected 3 columns, found " . count($row) . ".";
                            }
                            continue;
                        }

                        $s_num  = strtoupper(trim($row[0]));
                        $s_name = trim($row[1]);
                        $s_mail = strtolower(trim($row[2]));

                        // Validate each field
                        $row_valid = true;

                        if (!preg_match('/^\d{10}$/', $s_num)) {
                            $row_valid = false;
                            if (count($errors) < 5) {
                                $errors[] = "Row {$row_num}: '{$s_num}' is not a valid 10-digit student number.";
                            }
                        }

                        if (empty($s_name)) {
                            $row_valid = false;
                            if (count($errors) < 5) {
                                $errors[] = "Row {$row_num}: name is empty.";
                            }
                        }

                        if (!filter_var($s_mail, FILTER_VALIDATE_EMAIL) ||
                            !str_ends_with($s_mail, '@stud.umu.ac.ug')) {
                            $row_valid = false;
                            if (count($errors) < 5) {
                                $errors[] = "Row {$row_num}: '{$s_mail}' is not a valid university email.";
                            }
                        }

                        if (!$row_valid) {
                            $skipped++;
                            continue;
                        }

                        // Bind and execute
                        mysqli_stmt_bind_param($stmt, "sss", $s_num, $s_name, $s_mail);
                        mysqli_stmt_execute($stmt);

                        // affected_rows = 1 means inserted, 0 means INSERT IGNORE skipped it
                        if (mysqli_stmt_affected_rows($stmt) === 1) {
                            $inserted++;
                        } else {
                            $skipped++;
                        }
                    }

                    mysqli_stmt_close($stmt);
                    fclose($handle);

                    // Build result message
                    $message = "{$inserted} student(s) imported successfully.";
                    if ($skipped > 0) {
                        $message .= " {$skipped} row(s) skipped (duplicates or invalid data).";
                    }
                    if (!empty($errors)) {
                        $message .= " First issues found: " . implode(' | ', $errors);
                    }

                    $msg_type = $inserted > 0 ? "success" : "error";
                }
            }
        }
    }

    // Redirect to GET to prevent resubmission on refresh
    $qs = http_build_query(['msg' => $message, 'msg_type' => $msg_type]);
    header("Location: admin_eligible.php?" . $qs);
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
// Fetch all eligible students
// -----------------------------------------------------------------------
$search_term = trim($_GET['search'] ?? '');
$filter_claimed = $_GET['claimed'] ?? ''; // '' | '0' | '1'

$where_parts = [];
$bind_types  = '';
$bind_values = [];

if (!empty($search_term)) {
    $where_parts[] = "(student_number LIKE ? OR full_name LIKE ? OR official_email LIKE ?)";
    $like = '%' . $search_term . '%';
    $bind_types   .= 'sss';
    $bind_values[] = $like;
    $bind_values[] = $like;
    $bind_values[] = $like;
}

if ($filter_claimed === '0') {
    $where_parts[] = "is_claimed = 0";
} elseif ($filter_claimed === '1') {
    $where_parts[] = "is_claimed = 1";
}

$where_sql = !empty($where_parts) ? "WHERE " . implode(" AND ", $where_parts) : "";

$sql  = "SELECT id, student_number, full_name, official_email, is_claimed, added_at
         FROM eligible_students {$where_sql}
         ORDER BY added_at DESC";

$stmt = mysqli_prepare($conn, $sql);

if (!empty($bind_values)) {
    $refs = [$stmt, $bind_types];
    foreach ($bind_values as &$val) { $refs[] = &$val; }
    call_user_func_array('mysqli_stmt_bind_param', $refs);
}

mysqli_stmt_execute($stmt);
$result   = mysqli_stmt_get_result($stmt);
$students = [];
while ($row = mysqli_fetch_assoc($result)) {
    $students[] = $row;
}
mysqli_stmt_close($stmt);

// Summary counts
$r        = mysqli_query($conn, "SELECT COUNT(*) AS c FROM eligible_students");
$total    = mysqli_fetch_assoc($r)['c'];

$r        = mysqli_query($conn, "SELECT COUNT(*) AS c FROM eligible_students WHERE is_claimed = 1");
$claimed  = mysqli_fetch_assoc($r)['c'];

$unclaimed = $total - $claimed;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PollPoint &mdash; Eligible Students</title>
    <link rel="stylesheet" href="styles.css">
    <style>

        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: var(--font-sans);
            background-color: var(--bg-page);
            color: var(--text-primary);
            min-height: 100vh;
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
            transition: background var(--transition-fast), color var(--transition-fast);
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
            border: 1px solid var(--card-border);
            border-radius: var(--radius-md);
            color: var(--text-muted);
            font-family: var(--font-sans);
            font-size: 0.82rem;
            cursor: pointer;
            text-decoration: none;
            transition: border-color var(--transition-fast), color var(--transition-fast);
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
            margin-bottom: 24px;
            gap: 16px;
        }

        .page-header h1 {
            font-family: var(--font-serif);
            font-size: 1.7rem;
            margin-bottom: 4px;
        }

        .page-header p { color: var(--text-muted); font-size: 0.9rem; }

        .header-btns { display: flex; gap: 10px; flex-shrink: 0; }

        .btn-primary {
            padding: 9px 18px;
            background: var(--accent);
            color: var(--text-on-accent);
            border: none;
            border-radius: var(--radius-md);
            font-family: var(--font-sans);
            font-size: 0.875rem;
            font-weight: 700;
            cursor: pointer;
            white-space: nowrap;
            transition: background var(--transition-fast);
        }

        .btn-primary:hover { background: var(--accent-hover); }

        .btn-outline {
            padding: 9px 18px;
            background: transparent;
            border: 1px solid var(--card-border);
            border-radius: var(--radius-md);
            color: var(--text-muted);
            font-family: var(--font-sans);
            font-size: 0.875rem;
            cursor: pointer;
            white-space: nowrap;
            transition: all var(--transition-fast);
        }

        .btn-outline:hover { border-color: var(--text-muted); color: var(--text-primary); }

        /* ===================== STAT ROW ===================== */
        .stat-row {
            display: flex;
            gap: 14px;
            margin-bottom: 24px;
        }

        .stat-box {
            background: var(--surface);
            border: 1px solid var(--card-border);
            border-radius: var(--radius-md);
            padding: 16px 20px;
            flex: 1;
        }

        .stat-box .num {
            font-family: var(--font-serif);
            font-size: 1.8rem;
            font-weight: 900;
            color: var(--accent);
        }

        .stat-box .lbl {
            font-size: 0.75rem;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.07em;
            margin-top: 3px;
        }

        /* ===================== ALERT ===================== */
        .alert {
            padding: 13px 18px;
            border-radius: var(--radius-md);
            margin-bottom: 20px;
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
            border-radius: var(--radius-md);
            padding: 14px 18px;
            margin-bottom: 16px;
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            align-items: flex-end;
        }

        .filter-group { display: flex; flex-direction: column; gap: 5px; }

        .filter-group label {
            font-size: 0.72rem;
            font-weight: 600;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.07em;
        }

        .filter-group input[type="text"],
        .filter-group select {
            padding: 8px 12px;
            background: var(--input-background);
            border: 1px solid var(--input-border);
            border-radius: var(--radius-md);
            color: var(--text-primary);
            font-family: var(--font-sans);
            font-size: 0.875rem;
            outline: none;
            transition: border-color var(--transition-fast);
            min-width: 180px;
        }

        .filter-group input:focus,
        .filter-group select:focus { border-color: var(--accent); }

        .btn-filter {
            padding: 8px 18px;
            background: var(--accent);
            color: var(--text-on-accent);
            border: none;
            border-radius: var(--radius-md);
            font-family: var(--font-sans);
            font-size: 0.875rem;
            font-weight: 700;
            cursor: pointer;
            align-self: flex-end;
            transition: background var(--transition-fast);
        }

        .btn-filter:hover { background: var(--accent-hover); }

        .btn-reset {
            padding: 8px 14px;
            background: transparent;
            border: 1px solid var(--card-border);
            border-radius: var(--radius-md);
            color: var(--text-muted);
            font-family: var(--font-sans);
            font-size: 0.875rem;
            cursor: pointer;
            text-decoration: none;
            align-self: flex-end;
            transition: all var(--transition-fast);
        }

        .btn-reset:hover { border-color: var(--text-muted); color: var(--text-primary); }

        /* ===================== RESULTS COUNT & BULK ACTIONS ===================== */
        .table-toolbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
            gap: 12px;
            flex-wrap: wrap;
        }

        .results-count {
            font-size: 0.82rem;
            color: var(--text-muted);
        }

        .results-count strong { color: var(--text-primary); }

        .bulk-actions { display: flex; gap: 8px; }

        .btn-bulk {
            padding: 6px 14px;
            border-radius: 7px;
            font-family: var(--font-sans);
            font-size: 0.78rem;
            font-weight: 600;
            cursor: pointer;
            border: 1px solid var(--card-border);
            background: transparent;
            color: var(--text-muted);
            transition: all var(--transition-fast);
            white-space: nowrap;
        }

        .btn-bulk:hover               { background: var(--surface-elevated); color: var(--text-primary); }
        .btn-bulk.danger-soft:hover   { border-color: var(--warning); color: var(--warning); }
        .btn-bulk.danger-hard:hover   { border-color: var(--danger);  color: var(--danger); }

        /* ===================== TABLE ===================== */
        .table-wrap {
            background: var(--surface);
            border: 1px solid var(--card-border);
            border-radius: var(--radius-lg);
            overflow: hidden;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.875rem;
        }

        thead th {
            padding: 12px 18px;
            text-align: left;
            font-size: 0.72rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.07em;
            color: var(--text-muted);
            background: var(--table-header-bg);
            border-bottom: 1px solid var(--card-border);
            white-space: nowrap;
        }

        tbody td {
            padding: 13px 18px;
            border-bottom: 1px solid var(--card-border);
            vertical-align: middle;
        }

        tbody tr:last-child td { border-bottom: none; }
        tbody tr:hover td { background: var(--table-row-hover); }

        .name-cell { font-weight: 600; }
        .mono      { font-family: monospace; font-size: 0.82rem; color: var(--text-muted); }

        .pill {
            font-size: 0.7rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            padding: 3px 9px;
            border-radius: 20px;
        }

        .pill-claimed   { background: var(--success-soft);  color: var(--success); }
        .pill-unclaimed { background: var(--border-subtle); color: var(--text-muted); }

        .action-btns { display: flex; gap: 6px; }

        .btn-sm {
            padding: 5px 11px;
            border-radius: var(--radius-md);
            font-family: var(--font-sans);
            font-size: 0.72rem;
            font-weight: 600;
            cursor: pointer;
            border: 1px solid var(--card-border);
            background: transparent;
            color: var(--text-muted);
            transition: all var(--transition-fast);
            white-space: nowrap;
        }

        .btn-sm:hover        { background: var(--bg-page); color: var(--text-primary); }
        .btn-sm.edit:hover   { border-color: var(--accent); color: var(--accent); }
        .btn-sm.danger:hover { border-color: var(--danger); color: var(--danger); }

        .empty-row td {
            text-align: center;
            color: var(--text-muted);
            padding: 40px;
        }

        /* ===================== MODALS (shared base) ===================== */
        .modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.72);
            z-index: 200;
            align-items: center;
            justify-content: center;
        }

        .modal-overlay.active { display: flex; }

        .modal {
            background: var(--surface);
            border: 1px solid var(--card-border);
            border-radius: var(--radius-lg);
            padding: 34px 30px;
            max-width: 460px;
            width: 92%;
            animation: fadeUp 0.22s ease both;
        }

        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(14px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        .modal h3 {
            font-family: var(--font-serif);
            font-size: 1.15rem;
            margin-bottom: 6px;
        }

        .modal-sub {
            font-size: 0.83rem;
            color: var(--text-muted);
            margin-bottom: 22px;
            line-height: 1.5;
        }

        .form-group { margin-bottom: 15px; }

        .form-group label {
            display: block;
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.07em;
            margin-bottom: 6px;
        }

        .form-group input {
            width: 100%;
            padding: 10px 13px;
            background: var(--input-background);
            border: 1px solid var(--input-border);
            border-radius: var(--radius-md);
            color: var(--text-primary);
            font-family: var(--font-sans);
            font-size: 0.875rem;
            outline: none;
            transition: border-color var(--transition-fast);
        }

        .form-group input:focus { border-color: var(--accent); }

        .modal-buttons {
            display: flex;
            gap: 10px;
            margin-top: 22px;
        }

        .btn-modal-cancel {
            flex: 1;
            padding: 10px;
            background: transparent;
            border: 1px solid var(--card-border);
            border-radius: var(--radius-md);
            color: var(--text-muted);
            font-family: var(--font-sans);
            font-size: 0.875rem;
            cursor: pointer;
            transition: all var(--transition-fast);
        }

        .btn-modal-cancel:hover { border-color: var(--text-muted); color: var(--text-primary); }

        .btn-modal-save {
            flex: 1;
            padding: 10px;
            background: var(--accent);
            color: var(--text-on-accent);
            border: none;
            border-radius: var(--radius-md);
            font-family: var(--font-sans);
            font-size: 0.875rem;
            font-weight: 700;
            cursor: pointer;
            transition: background var(--transition-fast);
        }

        .btn-modal-save:hover { background: var(--accent-hover); }

        .btn-modal-danger {
            flex: 1;
            padding: 10px;
            background: var(--danger);
            color: #fff;
            border: none;
            border-radius: var(--radius-md);
            font-family: var(--font-sans);
            font-size: 0.875rem;
            font-weight: 700;
            cursor: pointer;
            transition: opacity var(--transition-fast);
        }

        .btn-modal-danger:hover { opacity: 0.85; }

        /* Warning box inside modal */
        .warn-box {
            background: var(--danger-soft);
            border: 1px solid var(--danger);
            border-radius: var(--radius-md);
            padding: 12px 14px;
            font-size: 0.82rem;
            color: var(--danger);
            margin-bottom: 20px;
            line-height: 1.5;
        }

        /* CSV upload modal specifics */
        .csv-info {
            background: var(--surface-elevated);
            border: 1px solid var(--card-border);
            border-radius: var(--radius-md);
            padding: 14px 16px;
            margin-bottom: 18px;
            font-size: 0.82rem;
            color: var(--text-muted);
            line-height: 1.7;
        }

        .csv-info code {
            display: block;
            background: var(--bg-page);
            border: 1px solid var(--card-border);
            border-radius: 5px;
            padding: 8px 12px;
            font-family: monospace;
            font-size: 0.8rem;
            margin-top: 8px;
            color: var(--accent);
        }

        .file-input-wrap {
            border: 2px dashed var(--card-border);
            border-radius: var(--radius-md);
            padding: 24px;
            text-align: center;
            cursor: pointer;
            transition: border-color var(--transition-fast);
            position: relative;
        }

        .file-input-wrap:hover { border-color: var(--accent); }

        .file-input-wrap input[type="file"] {
            position: absolute;
            inset: 0;
            opacity: 0;
            cursor: pointer;
            width: 100%;
        }

        .file-input-icon { font-size: 1.8rem; margin-bottom: 6px; }

        .file-input-label {
            font-size: 0.85rem;
            color: var(--text-muted);
        }

        .file-input-label strong { color: var(--accent); }

        #selected-file-name {
            font-size: 0.78rem;
            color: var(--success);
            margin-top: 8px;
            min-height: 18px;
        }

        @media (max-width: 900px) {
            .topbar-nav { display: none; }
        }

        @media (max-width: 650px) {
            .topbar      { padding: 0 16px; }
            .main        { padding: 24px 16px 48px; }
            .stat-row    { flex-direction: column; }
            .page-header { flex-direction: column; }
            .header-btns { width: 100%; }
            .header-btns button { flex: 1; }
            .filter-bar  { flex-direction: column; }
            .filter-group input,
            .filter-group select { min-width: 100%; }
            .table-toolbar { flex-direction: column; align-items: flex-start; }
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
            <a href="admin_users.php">Users</a>
            <a href="admin_elections.php">Elections</a>
            <a href="admin_eligible.php" class="active">Eligible Students</a>
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
            <h1>Eligible Students</h1>
            <p>Pre-verified list of students who are permitted to register and vote.</p>
        </div>
        <div class="header-btns">
            <button type="button" class="btn-outline" onclick="openCsvModal()">
                &#8679; Import CSV
            </button>
            <button type="button" class="btn-primary" onclick="openAddModal()">
                + Add Student
            </button>
        </div>
    </div>

    <!-- Stat row -->
    <div class="stat-row">
        <div class="stat-box">
            <div class="num"><?php echo $total; ?></div>
            <div class="lbl">Total Records</div>
        </div>
        <div class="stat-box">
            <div class="num" style="color:var(--success)"><?php echo $claimed; ?></div>
            <div class="lbl">Registered (Claimed)</div>
        </div>
        <div class="stat-box">
            <div class="num" style="color:var(--text-muted)"><?php echo $unclaimed; ?></div>
            <div class="lbl">Not Yet Registered</div>
        </div>
    </div>

    <!-- Flash message -->
    <?php if (!empty($message)): ?>
    <div class="alert alert-<?php echo $msg_type; ?>">
        <?php echo $msg_type === 'success' ? '&#10003;' : '&#9888;'; ?>
        <?php echo $message; ?>
    </div>
    <?php endif; ?>

    <!-- Filter bar -->
    <form method="GET" action="admin_eligible.php">
        <div class="filter-bar">
            <div class="filter-group">
                <label>Search</label>
                <input type="text" name="search"
                    placeholder="Number, name or email..."
                    value="<?php echo htmlspecialchars($search_term); ?>">
            </div>
            <div class="filter-group">
                <label>Registration Status</label>
                <select name="claimed">
                    <option value="">All</option>
                    <option value="0" <?php echo $filter_claimed === '0' ? 'selected' : ''; ?>>
                        Not yet registered
                    </option>
                    <option value="1" <?php echo $filter_claimed === '1' ? 'selected' : ''; ?>>
                        Registered
                    </option>
                </select>
            </div>
            <button type="submit" class="btn-filter">Apply</button>
            <a href="admin_eligible.php" class="btn-reset">Reset</a>
        </div>
    </form>

    <!-- Table toolbar: results count + bulk delete buttons -->
    <div class="table-toolbar">
        <div class="results-count">
            Showing <strong><?php echo count($students); ?></strong>
            of <strong><?php echo $total; ?></strong> record(s)
        </div>
        <div class="bulk-actions">
            <form method="POST" style="display:inline">
                <input type="hidden" name="action" value="delete_unclaimed">
                <button type="submit" class="btn-bulk danger-soft"
                    onclick="return confirm('Delete all <?php echo $unclaimed; ?> unclaimed records? Students who have not yet registered will no longer be able to do so.')">
                    Delete Unclaimed (<?php echo $unclaimed; ?>)
                </button>
            </form>
            <button type="button" class="btn-bulk danger-hard"
                onclick="openDeleteAllModal()">
                &#9888; Wipe Entire List
            </button>
        </div>
    </div>

    <!-- Main table -->
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Student Number</th>
                    <th>Full Name</th>
                    <th>Official Email</th>
                    <th>Status</th>
                    <th>Added</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($students)): ?>
                <tr class="empty-row">
                    <td colspan="6">No records found matching your filters.</td>
                </tr>
            <?php else: ?>
                <?php foreach ($students as $s): ?>
                <tr>
                    <td class="mono"><?php echo htmlspecialchars($s['student_number']); ?></td>
                    <td class="name-cell"><?php echo htmlspecialchars($s['full_name']); ?></td>
                    <td style="color:var(--text-muted);font-size:0.82rem">
                        <?php echo htmlspecialchars($s['official_email']); ?>
                    </td>
                    <td>
                        <span class="pill <?php echo $s['is_claimed'] ? 'pill-claimed' : 'pill-unclaimed'; ?>">
                            <?php echo $s['is_claimed'] ? 'Registered' : 'Pending'; ?>
                        </span>
                    </td>
                    <td style="color:var(--text-muted);white-space:nowrap;font-size:0.8rem">
                        <?php echo date('d M Y', strtotime($s['added_at'])); ?>
                    </td>
                    <td>
                        <div class="action-btns">
                            <!-- Edit -->
                            <button type="button" class="btn-sm edit"
                                onclick="openEditModal(
                                    <?php echo $s['id']; ?>,
                                    '<?php echo addslashes(htmlspecialchars($s['student_number'])); ?>',
                                    '<?php echo addslashes(htmlspecialchars($s['full_name'])); ?>',
                                    '<?php echo addslashes(htmlspecialchars($s['official_email'])); ?>'
                                )">
                                Edit
                            </button>
                            <!-- Delete -->
                            <form method="POST" style="display:inline">
                                <input type="hidden" name="action"    value="delete">
                                <input type="hidden" name="record_id" value="<?php echo $s['id']; ?>">
                                <button type="submit" class="btn-sm danger"
                                    onclick="return confirm('Delete this record?<?php echo $s['is_claimed'] ? ' Warning: this student has registered an account.' : ''; ?>')">
                                    Delete
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

</main>

<!-- ===================== ADD MODAL ===================== -->
<div class="modal-overlay" id="addModal">
    <div class="modal">
        <h3>Add Eligible Student</h3>
        <p class="modal-sub">
            This student will be allowed to register an account using
            the student number and email provided here.
        </p>
        <form method="POST" action="admin_eligible.php">
            <input type="hidden" name="action" value="add">
            <div class="form-group">
                <label>Student Number</label>
                <input type="text" name="student_number"
                    placeholder="e.g. 2500501011" maxlength="10" required>
            </div>
            <div class="form-group">
                <label>Full Name</label>
                <input type="text" name="full_name"
                    placeholder="e.g. Apio Sandra" maxlength="100" required>
            </div>
            <div class="form-group">
                <label>Official Email</label>
                <input type="email" name="official_email"
                    placeholder="apio.sandra@stud.umu.ac.ug" maxlength="150" required>
            </div>
            <div class="modal-buttons">
                <button type="button" class="btn-modal-cancel" onclick="closeModal('addModal')">Cancel</button>
                <button type="submit" class="btn-modal-save">Add to List</button>
            </div>
        </form>
    </div>
</div>

<!-- ===================== EDIT MODAL ===================== -->
<div class="modal-overlay" id="editModal">
    <div class="modal">
        <h3>Edit Record</h3>
        <p class="modal-sub">
            Changes here affect login eligibility. If this student has
            already registered, update their user account separately.
        </p>
        <form method="POST" action="admin_eligible.php">
            <input type="hidden" name="action"    value="edit">
            <input type="hidden" name="record_id" id="edit_record_id">
            <div class="form-group">
                <label>Student Number</label>
                <input type="text" name="student_number"
                    id="edit_student_number" maxlength="10" required>
            </div>
            <div class="form-group">
                <label>Full Name</label>
                <input type="text" name="full_name"
                    id="edit_full_name" maxlength="100" required>
            </div>
            <div class="form-group">
                <label>Official Email</label>
                <input type="email" name="official_email"
                    id="edit_official_email" maxlength="150" required>
            </div>
            <div class="modal-buttons">
                <button type="button" class="btn-modal-cancel" onclick="closeModal('editModal')">Cancel</button>
                <button type="submit" class="btn-modal-save">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<!-- ===================== DELETE ALL MODAL ===================== -->
<div class="modal-overlay" id="deleteAllModal">
    <div class="modal">
        <h3>Wipe Entire Eligibility List</h3>
        <div class="warn-box">
            &#9888; <strong>This is a destructive action.</strong><br><br>
            All unclaimed records will be permanently deleted. Records belonging
            to students who have already registered will be removed only if
            their user account has been deleted first. Any remaining claimed records
            will be reported back to you.
        </div>
        <p class="modal-sub">
            Students whose records are wiped will not be able to register new
            accounts until their record is re-added.
        </p>
        <form method="POST" action="admin_eligible.php">
            <input type="hidden" name="action" value="delete_all">
            <div class="modal-buttons">
                <button type="button" class="btn-modal-cancel" onclick="closeModal('deleteAllModal')">
                    Cancel
                </button>
                <button type="submit" class="btn-modal-danger">
                    Yes, Wipe List
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ===================== CSV UPLOAD MODAL ===================== -->
<div class="modal-overlay" id="csvModal">
    <div class="modal">
        <h3>Import from CSV</h3>
        <div class="csv-info">
            <strong>Required CSV format</strong> — one student per row, three columns:
            <code>student_number, full_name, official_email</code>
            A header row is optional and will be detected and skipped automatically.
            Rows with duplicate student numbers or emails are silently skipped
            so existing records are never overwritten.
            Maximum file size: <strong>2 MB</strong>.
        </div>

        <form method="POST" action="admin_eligible.php"
              enctype="multipart/form-data">
            <input type="hidden" name="action" value="csv_upload">

            <div class="file-input-wrap" id="dropZone">
                <input type="file" name="csv_file" accept=".csv"
                    id="csvFileInput" required
                    onchange="showFileName(this)">
                <div class="file-input-icon">&#128196;</div>
                <div class="file-input-label">
                    <strong>Click to choose</strong> or drag your .csv file here
                </div>
                <div id="selected-file-name"></div>
            </div>

            <div class="modal-buttons">
                <button type="button" class="btn-modal-cancel" onclick="closeModal('csvModal')">
                    Cancel
                </button>
                <button type="submit" class="btn-modal-save">
                    Upload &amp; Import
                </button>
            </div>
        </form>
    </div>
</div>

<script>
    // -----------------------------------------------------------------------
    // Generic modal open / close
    // -----------------------------------------------------------------------
    function openModal(id)  { document.getElementById(id).classList.add('active'); }
    function closeModal(id) { document.getElementById(id).classList.remove('active'); }

    function openAddModal()       { openModal('addModal'); }
    function openCsvModal()       { openModal('csvModal'); }
    function openDeleteAllModal() { openModal('deleteAllModal'); }

    // Close any modal by clicking the dark overlay
    document.querySelectorAll('.modal-overlay').forEach(function(overlay) {
        overlay.addEventListener('click', function(e) {
            if (e.target === this) closeModal(this.id);
        });
    });

    // -----------------------------------------------------------------------
    // Edit modal — pre-fill fields
    // -----------------------------------------------------------------------
    function openEditModal(id, studentNumber, fullName, email) {
        document.getElementById('edit_record_id').value      = id;
        document.getElementById('edit_student_number').value = studentNumber;
        document.getElementById('edit_full_name').value      = fullName;
        document.getElementById('edit_official_email').value = email;
        openModal('editModal');
    }

    // -----------------------------------------------------------------------
    // CSV file input — show selected filename
    // -----------------------------------------------------------------------
    function showFileName(input) {
        const label = document.getElementById('selected-file-name');
        if (input.files && input.files[0]) {
            label.textContent = '&#10003; ' + input.files[0].name;
        } else {
            label.textContent = '';
        }
    }
</script>

</body>
</html>