<?php
/*
 * admin_election_edit.php
 * ------------------------
 * Admin page to edit a specific election's details
 * and manage its candidates (add, edit, delete).
 *
 * Expects: ?election_id=X in the URL
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

// --- Get and validate election_id ---
$election_id = intval($_GET['election_id'] ?? $_POST['election_id'] ?? 0);

if ($election_id <= 0) {
    header("Location: admin_elections.php");
    exit();
}

// --- Fetch the election ---
$stmt = mysqli_prepare($conn,
    "SELECT id, title, description, status FROM elections WHERE id = ?"
);
mysqli_stmt_bind_param($stmt, "i", $election_id);
mysqli_stmt_execute($stmt);
$result   = mysqli_stmt_get_result($stmt);
$election = mysqli_fetch_assoc($result);
mysqli_stmt_close($stmt);

if (!$election) {
    header("Location: admin_elections.php");
    exit();
}

$message  = '';
$msg_type = '';

// -----------------------------------------------------------------------
// Handle POST actions: update election, add candidate,
//                      edit candidate, delete candidate
// -----------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action = $_POST['action'] ?? '';

    // -------------------------------------------------------------------
    // ACTION: Update election title and description
    // -------------------------------------------------------------------
    if ($action === 'update_election') {

        $title       = trim($_POST['title']       ?? '');
        $description = trim($_POST['description'] ?? '');

        if (empty($title)) {
            $message  = "Election title cannot be empty.";
            $msg_type = "error";

        } elseif (strlen($title) > 200) {
            $message  = "Title must be 200 characters or fewer.";
            $msg_type = "error";

        } else {
            $stmt = mysqli_prepare($conn,
                "UPDATE elections SET title = ?, description = ? WHERE id = ?"
            );
            mysqli_stmt_bind_param($stmt, "ssi", $title, $description, $election_id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);

            // Refresh the $election array to show updated values
            $election['title']       = $title;
            $election['description'] = $description;

            $message  = "Election details updated successfully.";
            $msg_type = "success";
        }
    }

    // -------------------------------------------------------------------
    // ACTION: Add a new candidate
    // -------------------------------------------------------------------
    elseif ($action === 'add_candidate') {

        $name        = trim($_POST['candidate_name']        ?? '');
        $description = trim($_POST['candidate_description'] ?? '');

        if (empty($name)) {
            $message  = "Candidate name cannot be empty.";
            $msg_type = "error";

        } elseif (strlen($name) > 100) {
            $message  = "Candidate name must be 100 characters or fewer.";
            $msg_type = "error";

        } else {
            $stmt = mysqli_prepare($conn,
                "INSERT INTO candidates (election_id, name, description) VALUES (?, ?, ?)"
            );
            mysqli_stmt_bind_param($stmt, "iss", $election_id, $name, $description);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            $message  = "Candidate added successfully.";
            $msg_type = "success";
        }
    }

    // -------------------------------------------------------------------
    // ACTION: Edit an existing candidate
    // -------------------------------------------------------------------
    elseif ($action === 'edit_candidate') {

        $candidate_id = intval($_POST['candidate_id'] ?? 0);
        $name         = trim($_POST['candidate_name']        ?? '');
        $description  = trim($_POST['candidate_description'] ?? '');

        if ($candidate_id <= 0 || empty($name)) {
            $message  = "Candidate name cannot be empty.";
            $msg_type = "error";

        } else {
            // Confirm candidate belongs to this election before updating
            $stmt = mysqli_prepare($conn,
                "UPDATE candidates SET name = ?, description = ?
                 WHERE id = ? AND election_id = ?"
            );
            mysqli_stmt_bind_param($stmt, "ssii", $name, $description, $candidate_id, $election_id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            $message  = "Candidate updated successfully.";
            $msg_type = "success";
        }
    }

    // -------------------------------------------------------------------
    // ACTION: Delete a candidate
    // -------------------------------------------------------------------
    elseif ($action === 'delete_candidate') {

        $candidate_id = intval($_POST['candidate_id'] ?? 0);

        if ($candidate_id <= 0) {
            $message  = "Invalid candidate.";
            $msg_type = "error";

        } else {
            // Block if this candidate has votes
            $stmt = mysqli_prepare($conn,
                "SELECT COUNT(*) AS c FROM votes WHERE candidate_id = ?"
            );
            mysqli_stmt_bind_param($stmt, "i", $candidate_id);
            mysqli_stmt_execute($stmt);
            $r         = mysqli_stmt_get_result($stmt);
            $has_votes = mysqli_fetch_assoc($r)['c'] > 0;
            mysqli_stmt_close($stmt);

            if ($has_votes) {
                $message  = "This candidate has votes recorded and cannot be deleted.";
                $msg_type = "error";
            } else {
                $stmt = mysqli_prepare($conn,
                    "DELETE FROM candidates WHERE id = ? AND election_id = ?"
                );
                mysqli_stmt_bind_param($stmt, "ii", $candidate_id, $election_id);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
                $message  = "Candidate removed.";
                $msg_type = "success";
            }
        }
    }
}

// Pick up flash message from redirect (just-created election)
if (isset($_GET['created']) && $_GET['created'] == 1 && empty($message)) {
    $message  = "Election created. Add candidates below, then open voting when ready.";
    $msg_type = "success";
}

// -----------------------------------------------------------------------
// Fetch candidates for this election with vote counts
// -----------------------------------------------------------------------
$stmt = mysqli_prepare($conn,
    "SELECT
        c.id,
        c.name,
        c.description,
        COUNT(v.id) AS vote_count
     FROM candidates c
     LEFT JOIN votes v ON v.candidate_id = c.id
     WHERE c.election_id = ?
     GROUP BY c.id, c.name, c.description
     ORDER BY c.added_at ASC"
);
mysqli_stmt_bind_param($stmt, "i", $election_id);
mysqli_stmt_execute($stmt);
$result     = mysqli_stmt_get_result($stmt);
$candidates = [];
while ($row = mysqli_fetch_assoc($result)) {
    $candidates[] = $row;
}
mysqli_stmt_close($stmt);

// Total votes for this election (for the results bar preview)
$total_votes = array_sum(array_column($candidates, 'vote_count'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PollPoint &mdash; Edit Election</title>
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
            max-width: 900px;
            margin: 0 auto;
            padding: 36px 24px 60px;
        }

        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            color: var(--text-muted);
            text-decoration: none;
            font-size: 0.875rem;
            margin-bottom: 24px;
            transition: color var(--transition-fast);
        }

        .back-link:hover { color: var(--text-primary); }

        /* ===================== PAGE HEADER ===================== */
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 28px;
            gap: 16px;
        }

        .page-header h1 {
            font-family: var(--font-serif);
            font-size: 1.6rem;
            margin-bottom: 4px;
        }

        .page-header p { color: var(--text-muted); font-size: 0.88rem; }

        .status-pill {
            font-size: 0.72rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            padding: 4px 12px;
            border-radius: 20px;
            white-space: nowrap;
            flex-shrink: 0;
        }

        .pill-open    { background: var(--success-soft);  color: var(--success); }
        .pill-pending { background: var(--warning-soft);  color: var(--warning); }
        .pill-closed  { background: var(--border-subtle); color: var(--text-muted); }

        /* ===================== ALERT ===================== */
        .alert {
            padding: 13px 18px;
            border-radius: var(--radius-md);
            margin-bottom: 22px;
            font-size: 0.875rem;
            display: flex;
            gap: 8px;
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

        /* ===================== TWO COLUMN LAYOUT ===================== */
        .layout {
            display: grid;
            grid-template-columns: 1fr 1.6fr;
            gap: 20px;
            align-items: start;
        }

        /* ===================== SECTION CARDS ===================== */
        .section-card {
            background: var(--surface);
            border: 1px solid var(--card-border);
            border-radius: var(--radius-lg);
            overflow: hidden;
        }

        .section-card-header {
            padding: 16px 22px;
            border-bottom: 1px solid var(--card-border);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .section-card-header h2 {
            font-family: var(--font-serif);
            font-size: 1rem;
        }

        .section-card-body { padding: 22px; }

        /* ===================== FORM ELEMENTS ===================== */
        .form-group { margin-bottom: 16px; }

        .form-group label {
            display: block;
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.07em;
            margin-bottom: 7px;
        }

        .form-group input[type="text"],
        .form-group textarea {
            width: 100%;
            padding: 10px 13px;
            background: var(--input-background);
            border: 1px solid var(--input-border);
            border-radius: var(--radius-md);
            color: var(--text-primary);
            font-family: var(--font-sans);
            font-size: 0.9rem;
            outline: none;
            transition: border-color var(--transition-fast);
            resize: vertical;
        }

        .form-group input:focus,
        .form-group textarea:focus { border-color: var(--accent); }

        .form-group textarea { min-height: 80px; }

        .btn-save {
            width: 100%;
            padding: 11px;
            background: var(--accent);
            color: var(--text-on-accent);
            border: none;
            border-radius: var(--radius-md);
            font-family: var(--font-sans);
            font-size: 0.9rem;
            font-weight: 700;
            cursor: pointer;
            transition: background var(--transition-fast);
            margin-top: 4px;
        }

        .btn-save:hover { background: var(--accent-hover); }

        /* Read-only fields shown for open/closed elections */
        .readonly-field {
            padding: 10px 13px;
            background: var(--surface-elevated);
            border: 1px solid var(--card-border);
            border-radius: var(--radius-md);
            color: var(--text-muted);
            font-size: 0.9rem;
            line-height: 1.5;
        }

        .locked-notice {
            font-size: 0.78rem;
            color: var(--warning);
            margin-top: 14px;
            padding: 10px 14px;
            background: var(--warning-soft);
            border: 1px solid var(--warning);
            border-radius: var(--radius-md);
        }

        /* ===================== CANDIDATES LIST ===================== */
        .candidates-list {
            display: flex;
            flex-direction: column;
            gap: 10px;
            padding: 16px 22px;
        }

        .candidate-row {
            background: var(--surface-elevated);
            border: 1px solid var(--card-border);
            border-radius: var(--radius-md);
            padding: 14px 16px;
            display: flex;
            align-items: flex-start;
            gap: 14px;
        }

        .candidate-row-info { flex: 1; min-width: 0; }

        .candidate-row-name {
            font-weight: 600;
            font-size: 0.95rem;
            margin-bottom: 3px;
        }

        .candidate-row-desc {
            font-size: 0.8rem;
            color: var(--text-muted);
            line-height: 1.5;
            margin-bottom: 8px;
        }

        /* Mini results bar inside candidate row */
        .mini-result {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .mini-bar-track {
            flex: 1;
            height: 5px;
            background: var(--bg-page);
            border-radius: 3px;
            overflow: hidden;
        }

        .mini-bar-fill {
            height: 100%;
            background: var(--accent);
            border-radius: 3px;
        }

        .mini-bar-label {
            font-size: 0.72rem;
            color: var(--text-muted);
            white-space: nowrap;
        }

        .candidate-row-actions {
            display: flex;
            gap: 6px;
            flex-shrink: 0;
        }

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

        .btn-sm:hover            { background: var(--bg-page); color: var(--text-primary); }
        .btn-sm.edit:hover       { border-color: var(--accent); color: var(--accent); }
        .btn-sm.danger:hover     { border-color: var(--danger); color: var(--danger); }

        /* Add candidate form inside card */
        .add-candidate-form {
            padding: 16px 22px;
            border-top: 1px solid var(--card-border);
            background: var(--bg-page);
        }

        .add-candidate-form h3 {
            font-size: 0.8rem;
            font-weight: 600;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.07em;
            margin-bottom: 14px;
        }

        .add-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            margin-bottom: 10px;
        }

        .add-candidate-form input[type="text"],
        .add-candidate-form textarea {
            width: 100%;
            padding: 9px 12px;
            background: var(--surface);
            border: 1px solid var(--card-border);
            border-radius: var(--radius-md);
            color: var(--text-primary);
            font-family: var(--font-sans);
            font-size: 0.875rem;
            outline: none;
            transition: border-color var(--transition-fast);
            resize: none;
        }

        .add-candidate-form input:focus,
        .add-candidate-form textarea:focus { border-color: var(--accent); }

        .btn-add {
            padding: 9px 20px;
            background: var(--accent);
            color: var(--text-on-accent);
            border: none;
            border-radius: var(--radius-md);
            font-family: var(--font-sans);
            font-size: 0.875rem;
            font-weight: 700;
            cursor: pointer;
            transition: background var(--transition-fast);
            white-space: nowrap;
        }

        .btn-add:hover { background: var(--accent-hover); }

        .empty-candidates {
            text-align: center;
            padding: 28px;
            color: var(--text-muted);
            font-size: 0.875rem;
        }

        /* ===================== EDIT CANDIDATE MODAL ===================== */
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
            padding: 32px 28px;
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
            font-size: 1.15rem;
            margin-bottom: 20px;
        }

        .modal-form-group { margin-bottom: 16px; }

        .modal-form-group label {
            display: block;
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.07em;
            margin-bottom: 7px;
        }

        .modal-form-group input,
        .modal-form-group textarea {
            width: 100%;
            padding: 10px 13px;
            background: var(--input-background);
            border: 1px solid var(--input-border);
            border-radius: var(--radius-md);
            color: var(--text-primary);
            font-family: var(--font-sans);
            font-size: 0.875rem;
            outline: none;
            transition: border-color 0.2s;
            resize: vertical;
        }

        .modal-form-group input:focus,
        .modal-form-group textarea:focus { border-color: var(--accent); }

        .modal-form-group textarea { min-height: 80px; }

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
            font-size: 0.9rem;
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
            font-size: 0.9rem;
            font-weight: 700;
            cursor: pointer;
            transition: background var(--transition-fast);
        }}

        .btn-modal-save:hover { background: var(--accent-hover); }

        @media (max-width: 900px) {
            .topbar-nav { display: none; }
            .layout     { grid-template-columns: 1fr; }
        }

        @media (max-width: 600px) {
            .topbar { padding: 0 16px; }
            .main   { padding: 24px 16px 48px; }
            .add-row { grid-template-columns: 1fr; }
            .page-header { flex-direction: column; }
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
            <a href="admin_elections.php" class="active">Elections</a>
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

    <a href="admin_elections.php" class="back-link">&#8592; Back to Elections</a>

    <div class="page-header">
        <div>
            <h1><?php echo htmlspecialchars($election['title']); ?></h1>
            <p>Edit election details and manage candidates.</p>
        </div>
        <span class="status-pill pill-<?php echo $election['status']; ?>">
            <?php echo ucfirst($election['status']); ?>
        </span>
    </div>

    <!-- Flash message -->
    <?php if (!empty($message)): ?>
    <div class="alert alert-<?php echo $msg_type; ?>">
        <?php echo $msg_type === 'success' ? '&#10003;' : '&#9888;'; ?>
        <?php echo htmlspecialchars($message); ?>
    </div>
    <?php endif; ?>

    <div class="layout">

        <!-- ====================================================
             LEFT COLUMN — Election details form
        ===================================================== -->
        <div class="section-card">
            <div class="section-card-header">
                <h2>Election Details</h2>
            </div>
            <div class="section-card-body">

                <?php if ($election['status'] === 'pending'): ?>
                <!-- Pending: fields are editable -->
                <form method="POST">
                    <input type="hidden" name="action"      value="update_election">
                    <input type="hidden" name="election_id" value="<?php echo $election_id; ?>">

                    <div class="form-group">
                        <label>Election Title</label>
                        <input type="text" name="title"
                            value="<?php echo htmlspecialchars($election['title']); ?>"
                            maxlength="200" required>
                    </div>

                    <div class="form-group">
                        <label>Description</label>
                        <textarea name="description"
                            placeholder="Brief description shown to students..."><?php echo htmlspecialchars($election['description']); ?></textarea>
                    </div>

                    <button type="submit" class="btn-save">Save Details</button>
                </form>

                <?php else: ?>
                <!-- Open or Closed: details are locked -->
                <div class="form-group">
                    <label>Election Title</label>
                    <div class="readonly-field"><?php echo htmlspecialchars($election['title']); ?></div>
                </div>

                <div class="form-group">
                    <label>Description</label>
                    <div class="readonly-field">
                        <?php echo !empty($election['description'])
                            ? htmlspecialchars($election['description'])
                            : '—'; ?>
                    </div>
                </div>

                <div class="locked-notice">
                    &#9432; Election details are locked while the election is
                    <strong><?php echo $election['status']; ?></strong>.
                    Close the election first if changes are needed.
                </div>
                <?php endif; ?>

            </div>
        </div>

        <!-- ====================================================
             RIGHT COLUMN — Candidates
        ===================================================== -->
        <div class="section-card">
            <div class="section-card-header">
                <h2>Candidates</h2>
                <span style="font-size:0.78rem;color:var(--text-muted)">
                    <?php echo count($candidates); ?> registered
                    &bull; <?php echo $total_votes; ?> vote<?php echo $total_votes != 1 ? 's' : ''; ?> cast
                </span>
            </div>

            <!-- Candidate rows -->
            <?php if (empty($candidates)): ?>
                <div class="empty-candidates">
                    No candidates yet. Add the first one below.
                </div>
            <?php else: ?>
            <div class="candidates-list">
                <?php foreach ($candidates as $c):
                    $pct = $total_votes > 0 ? round(($c['vote_count'] / $total_votes) * 100) : 0;
                ?>
                <div class="candidate-row">
                    <div class="candidate-row-info">
                        <div class="candidate-row-name"><?php echo htmlspecialchars($c['name']); ?></div>
                        <div class="candidate-row-desc">
                            <?php echo !empty($c['description'])
                                ? htmlspecialchars($c['description'])
                                : '<em style="color:var(--text-muted)">No description</em>'; ?>
                        </div>
                        <!-- Mini live bar -->
                        <div class="mini-result">
                            <div class="mini-bar-track">
                                <div class="mini-bar-fill" style="width:<?php echo $pct; ?>%"></div>
                            </div>
                            <span class="mini-bar-label">
                                <?php echo $c['vote_count']; ?> vote<?php echo $c['vote_count'] != 1 ? 's' : ''; ?>
                                (<?php echo $pct; ?>%)
                            </span>
                        </div>
                    </div>

                    <!-- Edit / Delete buttons -->
                    <div class="candidate-row-actions">
                        <button type="button" class="btn-sm edit"
                            onclick="openEditModal(
                                <?php echo $c['id']; ?>,
                                '<?php echo addslashes(htmlspecialchars($c['name'])); ?>',
                                '<?php echo addslashes(htmlspecialchars($c['description'])); ?>'
                            )">
                            Edit
                        </button>

                        <?php if ($c['vote_count'] == 0): ?>
                        <form method="POST" style="display:inline">
                            <input type="hidden" name="action"       value="delete_candidate">
                            <input type="hidden" name="election_id"  value="<?php echo $election_id; ?>">
                            <input type="hidden" name="candidate_id" value="<?php echo $c['id']; ?>">
                            <button type="submit" class="btn-sm danger"
                                onclick="return confirm('Remove this candidate?')">
                                Remove
                            </button>
                        </form>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <!-- Add candidate form — shown for pending and open elections -->
            <?php if ($election['status'] !== 'closed'): ?>
            <div class="add-candidate-form">
                <h3>Add Candidate</h3>
                <form method="POST">
                    <input type="hidden" name="action"      value="add_candidate">
                    <input type="hidden" name="election_id" value="<?php echo $election_id; ?>">

                    <div class="add-row">
                        <input type="text" name="candidate_name"
                            placeholder="Full name *"
                            maxlength="100" required>
                        <input type="text" name="candidate_description"
                            placeholder="Position / year / manifesto (optional)"
                            maxlength="255">
                    </div>

                    <button type="submit" class="btn-add">+ Add Candidate</button>
                </form>
            </div>
            <?php else: ?>
            <div style="padding:14px 22px;font-size:0.78rem;color:var(--text-muted);
                        border-top:1px solid var(--border);background:var(--bg-dark);">
                &#9432; This election is closed. Candidates cannot be added or removed.
            </div>
            <?php endif; ?>

        </div>
        <!-- /right column -->

    </div>
    <!-- /layout -->

</main>

<!-- ===================== EDIT CANDIDATE MODAL ===================== -->
<div class="modal-overlay" id="editCandidateModal">
    <div class="modal">
        <h3>Edit Candidate</h3>
        <form method="POST">
            <input type="hidden" name="action"       value="edit_candidate">
            <input type="hidden" name="election_id"  value="<?php echo $election_id; ?>">
            <input type="hidden" name="candidate_id" id="edit_candidate_id">

            <div class="modal-form-group">
                <label>Full Name</label>
                <input type="text" name="candidate_name"
                    id="edit_candidate_name"
                    maxlength="100" required>
            </div>

            <div class="modal-form-group">
                <label>Description / Manifesto</label>
                <textarea name="candidate_description"
                    id="edit_candidate_desc"
                    placeholder="Position, year of study, manifesto..."></textarea>
            </div>

            <div class="modal-buttons">
                <button type="button" class="btn-modal-cancel" onclick="closeEditModal()">Cancel</button>
                <button type="submit" class="btn-modal-save">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<script>
    function openEditModal(id, name, description) {
        document.getElementById('edit_candidate_id').value   = id;
        document.getElementById('edit_candidate_name').value = name;
        document.getElementById('edit_candidate_desc').value = description;
        document.getElementById('editCandidateModal').classList.add('active');
    }

    function closeEditModal() {
        document.getElementById('editCandidateModal').classList.remove('active');
    }

    document.getElementById('editCandidateModal').addEventListener('click', function(e) {
        if (e.target === this) closeEditModal();
    });
</script>

</body>
</html>