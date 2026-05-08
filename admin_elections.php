<?php
/*
 * admin_elections.php
 * --------------------
 * Admin page to manage elections.
 *
 * Features:
 *   - List all elections with status, candidate count, vote count
 *   - Create a new election
 *   - Change election status (pending -> open -> closed)
 *   - Delete an election (only if no votes have been cast)
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

$admin_id = $_SESSION['user_id'];
$message  = '';
$msg_type = '';

// -----------------------------------------------------------------------
// Handle POST actions: create, change status, delete
// -----------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action = $_POST['action'] ?? '';

    // -------------------------------------------------------------------
    // ACTION: Create a new election
    // -------------------------------------------------------------------
    if ($action === 'create') {

        $title       = trim($_POST['title']       ?? '');
        $description = trim($_POST['description'] ?? '');

        if (empty($title)) {
            $message  = "Election title cannot be empty.";
            $msg_type = "error";

        } elseif (strlen($title) > 200) {
            $message  = "Election title must be 200 characters or fewer.";
            $msg_type = "error";

        } else {
            $stmt = mysqli_prepare($conn,
                "INSERT INTO elections (title, description, status, created_by)
                 VALUES (?, ?, 'pending', ?)"
            );
            mysqli_stmt_bind_param($stmt, "ssi", $title, $description, $admin_id);

            if (mysqli_stmt_execute($stmt)) {
                $new_id   = mysqli_insert_id($conn);
                $message  = "Election created. You can now add candidates to it.";
                $msg_type = "success";
                mysqli_stmt_close($stmt);

                // Send admin straight to the edit page to add candidates
                header("Location: admin_election_edit.php?election_id={$new_id}&created=1");
                exit();
            } else {
                $message  = "Failed to create election. Please try again.";
                $msg_type = "error";
                mysqli_stmt_close($stmt);
            }
        }
    }

    // -------------------------------------------------------------------
    // ACTION: Change election status
    // -------------------------------------------------------------------
    elseif ($action === 'set_status') {

        $election_id = intval($_POST['election_id'] ?? 0);
        $new_status  = $_POST['new_status'] ?? '';

        $allowed = ['pending', 'open', 'closed'];

        if ($election_id <= 0 || !in_array($new_status, $allowed)) {
            $message  = "Invalid status change request.";
            $msg_type = "error";

        } else {
            // Extra safety: if opening an election, make sure it has candidates
            if ($new_status === 'open') {
                $stmt = mysqli_prepare($conn,
                    "SELECT COUNT(*) AS c FROM candidates WHERE election_id = ?"
                );
                mysqli_stmt_bind_param($stmt, "i", $election_id);
                mysqli_stmt_execute($stmt);
                $r = mysqli_stmt_get_result($stmt);
                $has_candidates = mysqli_fetch_assoc($r)['c'] > 0;
                mysqli_stmt_close($stmt);

                if (!$has_candidates) {
                    $message  = "Cannot open an election with no candidates. Add at least one candidate first.";
                    $msg_type = "error";
                    // Fall through to redirect below
                    header("Location: admin_elections.php?msg=" . urlencode($message) . "&msg_type=error");
                    exit();
                }
            }

            $stmt = mysqli_prepare($conn,
                "UPDATE elections SET status = ? WHERE id = ?"
            );
            mysqli_stmt_bind_param($stmt, "si", $new_status, $election_id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);

            $labels   = ['pending' => 'set to Pending', 'open' => 'opened', 'closed' => 'closed'];
            $message  = "Election has been " . $labels[$new_status] . ".";
            $msg_type = "success";
        }

        header("Location: admin_elections.php?msg=" . urlencode($message) . "&msg_type=" . $msg_type);
        exit();
    }

    // -------------------------------------------------------------------
    // ACTION: Delete an election
    // -------------------------------------------------------------------
    elseif ($action === 'delete') {

        $election_id = intval($_POST['election_id'] ?? 0);

        if ($election_id <= 0) {
            $message  = "Invalid election.";
            $msg_type = "error";

        } else {
            // Block deletion if any votes exist for this election
            $stmt = mysqli_prepare($conn,
                "SELECT COUNT(*) AS c FROM votes WHERE election_id = ?"
            );
            mysqli_stmt_bind_param($stmt, "i", $election_id);
            mysqli_stmt_execute($stmt);
            $r         = mysqli_stmt_get_result($stmt);
            $has_votes = mysqli_fetch_assoc($r)['c'] > 0;
            mysqli_stmt_close($stmt);

            if ($has_votes) {
                $message  = "This election has votes recorded and cannot be deleted. Close it instead.";
                $msg_type = "error";
            } else {
                // Candidates cascade-delete automatically (ON DELETE CASCADE on FK)
                $stmt = mysqli_prepare($conn, "DELETE FROM elections WHERE id = ?");
                mysqli_stmt_bind_param($stmt, "i", $election_id);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
                $message  = "Election and all its candidates have been deleted.";
                $msg_type = "success";
            }
        }

        header("Location: admin_elections.php?msg=" . urlencode($message) . "&msg_type=" . $msg_type);
        exit();
    }
}

// -----------------------------------------------------------------------
// Pick up flash message from redirect
// -----------------------------------------------------------------------
if (isset($_GET['msg']) && !empty($_GET['msg'])) {
    $message  = htmlspecialchars($_GET['msg']);
    $msg_type = htmlspecialchars($_GET['msg_type'] ?? 'success');
}

// -----------------------------------------------------------------------
// Fetch all elections with counts
// -----------------------------------------------------------------------
$r         = mysqli_query($conn,
    "SELECT
        e.id,
        e.title,
        e.description,
        e.status,
        e.created_at,
        COUNT(DISTINCT c.id) AS candidate_count,
        COUNT(DISTINCT v.id) AS vote_count
     FROM elections e
     LEFT JOIN candidates c ON c.election_id = e.id
     LEFT JOIN votes      v ON v.election_id = e.id
     GROUP BY e.id, e.title, e.description, e.status, e.created_at
     ORDER BY FIELD(e.status,'open','pending','closed'), e.created_at DESC"
);
$elections = [];
while ($row = mysqli_fetch_assoc($r)) {
    $elections[] = $row;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PollPoint &mdash; Elections</title>
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
            max-width: 1100px;
            margin: 0 auto;
            padding: 36px 24px 60px;
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 28px;
            gap: 16px;
        }

        .page-header h1 {
            font-family: var(--font-serif);
            font-size: 1.7rem;
            margin-bottom: 4px;
        }

        .page-header p { color: var(--text-muted); font-size: 0.9rem; }

        .btn-primary {
            padding: 10px 22px;
            background: var(--accent);
            color: var(--text-on-accent);
            border: none;
            border-radius: var(--radius-md);
            font-family: var(--font-sans);
            font-size: 0.9rem;
            font-weight: 700;
            cursor: pointer;
            white-space: nowrap;
            transition: background var(--transition-fast);
            text-decoration: none;
            display: inline-block;
        }

        .btn-primary:hover { background: var(--accent-hover); }

        /* ===================== ALERT ===================== */
        .alert {
            padding: 13px 18px;
            border-radius: var(--radius-md);
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

        /* ===================== ELECTIONS GRID ===================== */
        .elections-grid {
            display: flex;
            flex-direction: column;
            gap: 14px;
        }

        .election-card {
            background: var(--surface);
            border: 1px solid var(--card-border);
            border-radius: var(--radius-lg);
            padding: 22px 24px;
            display: flex;
            align-items: center;
            gap: 20px;
            transition: border-color var(--transition-fast);
        }

        .election-card:hover { border-color: var(--accent); }

        .election-card.is-open    { border-left: 3px solid var(--success); }
        .election-card.is-pending { border-left: 3px solid var(--warning); }
        .election-card.is-closed  { border-left: 3px solid var(--border-subtle); }

        /* Main info block — takes available space */
        .election-info { flex: 1; min-width: 0; }

        .election-title {
            font-family: var(--font-serif);
            font-size: 1.05rem;
            font-weight: 700;
            margin-bottom: 4px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .election-desc {
            font-size: 0.82rem;
            color: var(--text-muted);
            margin-bottom: 10px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .election-meta {
            display: flex;
            gap: 20px;
            font-size: 0.78rem;
            color: var(--text-muted);
        }

        /* Status pill */
        .status-pill {
            font-size: 0.7rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            padding: 3px 10px;
            border-radius: 20px;
            white-space: nowrap;
            flex-shrink: 0;
        }

        .pill-open    { background: var(--success-soft);  color: var(--success); }
        .pill-pending { background: var(--warning-soft);  color: var(--warning); }
        .pill-closed  { background: var(--border-subtle); color: var(--text-muted); }

        /* Action buttons group */
        .election-actions {
            display: flex;
            gap: 8px;
            align-items: center;
            flex-shrink: 0;
            flex-wrap: wrap;
            justify-content: flex-end;
        }

        .btn-action {
            padding: 7px 14px;
            border-radius: var(--radius-md);
            font-family: var(--font-sans);
            font-size: 0.78rem;
            font-weight: 600;
            cursor: pointer;
            border: 1px solid var(--card-border);
            background: transparent;
            color: var(--text-muted);
            text-decoration: none;
            display: inline-block;
            transition: all var(--transition-fast);
            white-space: nowrap;
        }

        .btn-action:hover            { background: var(--surface-elevated); color: var(--text-primary); border-color: var(--text-muted); }
        .btn-action.edit:hover       { border-color: var(--accent);  color: var(--accent); }
        .btn-action.open-btn:hover   { border-color: var(--success); color: var(--success); }
        .btn-action.close-btn:hover  { border-color: var(--warning); color: var(--warning); }
        .btn-action.danger:hover     { border-color: var(--danger);  color: var(--danger); }

        /* Empty state */
        .empty-state {
            text-align: center;
            padding: 56px 32px;
            color: var(--text-muted);
            background: var(--surface);
            border: 1px dashed var(--border-subtle);
            border-radius: var(--radius-lg);
        }

        .empty-state p { margin-bottom: 20px; font-size: 0.95rem; }

        /* ===================== CREATE MODAL ===================== */
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
            padding: 36px 32px;
            max-width: 480px;
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
            margin-bottom: 6px;
        }

        .modal-sub {
            font-size: 0.85rem;
            color: var(--text-muted);
            margin-bottom: 24px;
            line-height: 1.5;
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

        .form-group textarea { min-height: 90px; }

        .hint {
            font-size: 0.75rem;
            color: var(--text-muted);
            margin-top: 5px;
        }

        .modal-buttons {
            display: flex;
            gap: 10px;
            margin-top: 24px;
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
        }

        .btn-modal-save:hover { background: var(--accent-hover); }

        @media (max-width: 900px) {
            .topbar-nav { display: none; }
        }

        @media (max-width: 700px) {
            .topbar { padding: 0 16px; }
            .main   { padding: 24px 16px 48px; }
            .election-card { flex-direction: column; align-items: flex-start; }
            .election-actions { width: 100%; justify-content: flex-start; }
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

    <div class="page-header">
        <div>
            <h1>Elections</h1>
            <p>Create, manage and control all guild elections.</p>
        </div>
        <button type="button" class="btn-primary" onclick="openCreateModal()">
            + New Election
        </button>
    </div>

    <!-- Flash message -->
    <?php if (!empty($message)): ?>
    <div class="alert alert-<?php echo $msg_type; ?>">
        <?php echo $msg_type === 'success' ? '&#10003;' : '&#9888;'; ?>
        <?php echo $message; ?>
    </div>
    <?php endif; ?>

    <!-- ================= ELECTIONS LIST ================= -->
    <?php if (empty($elections)): ?>
        <div class="empty-state">
            <p>No elections have been created yet.</p>
            <button type="button" class="btn-primary" onclick="openCreateModal()">
                Create your first election
            </button>
        </div>

    <?php else: ?>
        <div class="elections-grid">
        <?php foreach ($elections as $e): ?>

        <div class="election-card is-<?php echo $e['status']; ?>">

            <!-- Status pill -->
            <span class="status-pill pill-<?php echo $e['status']; ?>">
                <?php echo ucfirst($e['status']); ?>
            </span>

            <!-- Election info -->
            <div class="election-info">
                <div class="election-title"><?php echo htmlspecialchars($e['title']); ?></div>
                <?php if (!empty($e['description'])): ?>
                <div class="election-desc"><?php echo htmlspecialchars($e['description']); ?></div>
                <?php endif; ?>
                <div class="election-meta">
                    <span>&#128101; <?php echo $e['candidate_count']; ?> candidate<?php echo $e['candidate_count'] != 1 ? 's' : ''; ?></span>
                    <span>&#128221; <?php echo $e['vote_count']; ?> vote<?php echo $e['vote_count'] != 1 ? 's' : ''; ?> cast</span>
                    <span>&#128197; <?php echo date('d M Y', strtotime($e['created_at'])); ?></span>
                </div>
            </div>

            <!-- Action buttons -->
            <div class="election-actions">

                <!-- Always: Edit candidates / details -->
                <a href="admin_election_edit.php?election_id=<?php echo $e['id']; ?>"
                   class="btn-action edit">
                    Edit / Candidates
                </a>

                <!-- View Results: visible for open and closed elections -->
                <?php if ($e['status'] === 'open' || $e['status'] === 'closed'): ?>
                <a href="admin_results.php?election_id=<?php echo $e['id']; ?>"
                   class="btn-action">
                    View Results
                </a>
                <?php endif; ?>

                <!-- Status transitions -->
                <?php if ($e['status'] === 'pending'): ?>
                    <form method="POST" style="display:inline">
                        <input type="hidden" name="action"      value="set_status">
                        <input type="hidden" name="election_id" value="<?php echo $e['id']; ?>">
                        <input type="hidden" name="new_status"  value="open">
                        <button type="submit" class="btn-action open-btn"
                            onclick="return confirm('Open this election for voting now?')">
                            Open Voting
                        </button>
                    </form>

                <?php elseif ($e['status'] === 'open'): ?>
                    <form method="POST" style="display:inline">
                        <input type="hidden" name="action"      value="set_status">
                        <input type="hidden" name="election_id" value="<?php echo $e['id']; ?>">
                        <input type="hidden" name="new_status"  value="closed">
                        <button type="submit" class="btn-action close-btn"
                            onclick="return confirm('Close this election? Students will no longer be able to vote.')">
                            Close Voting
                        </button>
                    </form>

                <?php elseif ($e['status'] === 'closed'): ?>
                    <!-- Reopen to pending if needed (no votes required) -->
                    <?php if ($e['vote_count'] == 0): ?>
                    <form method="POST" style="display:inline">
                        <input type="hidden" name="action"      value="set_status">
                        <input type="hidden" name="election_id" value="<?php echo $e['id']; ?>">
                        <input type="hidden" name="new_status"  value="pending">
                        <button type="submit" class="btn-action"
                            onclick="return confirm('Reset this election back to pending?')">
                            Reset to Pending
                        </button>
                    </form>
                    <?php endif; ?>
                <?php endif; ?>

                <!-- Delete — only allowed if no votes -->
                <?php if ($e['vote_count'] == 0): ?>
                <form method="POST" style="display:inline">
                    <input type="hidden" name="action"      value="delete">
                    <input type="hidden" name="election_id" value="<?php echo $e['id']; ?>">
                    <button type="submit" class="btn-action danger"
                        onclick="return confirm('Permanently delete this election and all its candidates?')">
                        Delete
                    </button>
                </form>
                <?php endif; ?>

            </div>
        </div>

        <?php endforeach; ?>
        </div>
    <?php endif; ?>

</main>

<!-- ===================== CREATE ELECTION MODAL ===================== -->
<div class="modal-overlay" id="createModal">
    <div class="modal">
        <h3>Create New Election</h3>
        <p class="modal-sub">
            The election will start in <strong>Pending</strong> status.
            You can add candidates and open voting when ready.
        </p>

        <form method="POST" action="admin_elections.php">
            <input type="hidden" name="action" value="create">

            <div class="form-group">
                <label>Election Title</label>
                <input type="text" name="title"
                    placeholder="e.g. Guild President Election 2026"
                    maxlength="200" required>
            </div>

            <div class="form-group">
                <label>Description <span style="font-weight:400;text-transform:none">(optional)</span></label>
                <textarea name="description"
                    placeholder="Brief description shown to students..."></textarea>
                <p class="hint">Explain the purpose of the election or any relevant instructions.</p>
            </div>

            <div class="modal-buttons">
                <button type="button" class="btn-modal-cancel" onclick="closeCreateModal()">Cancel</button>
                <button type="submit" class="btn-modal-save">Create &rarr;</button>
            </div>
        </form>
    </div>
</div>

<script>
    function openCreateModal() {
        document.getElementById('createModal').classList.add('active');
    }

    function closeCreateModal() {
        document.getElementById('createModal').classList.remove('active');
    }

    document.getElementById('createModal').addEventListener('click', function(e) {
        if (e.target === this) closeCreateModal();
    });

    // Auto-open create modal if ?action=create is in the URL
    // (for the quick action button on dashboard)
    const params = new URLSearchParams(window.location.search);
    if (params.get('action') === 'create') {
        openCreateModal();
    }
</script>

</body>
</html>