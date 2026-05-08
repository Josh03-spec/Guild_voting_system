<?php
/*
 * vote.php
 * ---------
 * Shows candidates for a specific open election and allows
 * a student to cast their vote.
 *
 * Expects: ?election_id=X in the URL
 * Guards:  student must be logged in, election must be open,
 *          student must not have already voted in this election.
 */

session_start();
require_once 'db.php';

// --- Session guard ---
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

if ($_SESSION['role'] !== 'student') {
    header("Location: admin_dashboard.php");
    exit();
}

$student_id = $_SESSION['user_id'];

// --- Get and validate election_id from URL ---
$election_id = intval($_GET['election_id'] ?? 0);

if ($election_id <= 0) {
    header("Location: student_dashboard.php");
    exit();
}

// --- Fetch the election — must exist and be open ---
$stmt = mysqli_prepare($conn,
    "SELECT id, title, description, status FROM elections WHERE id = ?"
);
mysqli_stmt_bind_param($stmt, "i", $election_id);
mysqli_stmt_execute($stmt);
$result   = mysqli_stmt_get_result($stmt);
$election = mysqli_fetch_assoc($result);
mysqli_stmt_close($stmt);

// Election does not exist
if (!$election) {
    header("Location: student_dashboard.php");
    exit();
}

// Election is not open — redirect with a sensible destination
if ($election['status'] !== 'open') {
    header("Location: student_dashboard.php");
    exit();
}

// --- Check if student has already voted in this election ---
$stmt = mysqli_prepare($conn,
    "SELECT id FROM votes WHERE student_id = ? AND election_id = ?"
);
mysqli_stmt_bind_param($stmt, "ii", $student_id, $election_id);
mysqli_stmt_execute($stmt);
mysqli_stmt_store_result($stmt);
$already_voted = mysqli_stmt_num_rows($stmt) > 0;
mysqli_stmt_close($stmt);

if ($already_voted) {
    // Already voted — send them to results instead
    header("Location: results.php?election_id=" . $election_id);
    exit();
}

// --- Fetch candidates for this election ---
$stmt = mysqli_prepare($conn,
    "SELECT id, name, description FROM candidates WHERE election_id = ? ORDER BY id ASC"
);
mysqli_stmt_bind_param($stmt, "i", $election_id);
mysqli_stmt_execute($stmt);
$result     = mysqli_stmt_get_result($stmt);
$candidates = [];

while ($row = mysqli_fetch_assoc($result)) {
    $candidates[] = $row;
}
mysqli_stmt_close($stmt);

// -----------------------------------------------------------------------
// Handle vote submission (POST)
// -----------------------------------------------------------------------
$error   = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $candidate_id = intval($_POST['candidate_id'] ?? 0);

    if ($candidate_id <= 0) {
        $error = "Please select a candidate before submitting your vote.";

    } else {
        // Confirm the chosen candidate actually belongs to this election
        $stmt = mysqli_prepare($conn,
            "SELECT id FROM candidates WHERE id = ? AND election_id = ?"
        );
        mysqli_stmt_bind_param($stmt, "ii", $candidate_id, $election_id);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_store_result($stmt);
        $valid_candidate = mysqli_stmt_num_rows($stmt) > 0;
        mysqli_stmt_close($stmt);

        if (!$valid_candidate) {
            $error = "Invalid candidate selected. Please try again.";

        } else {
            // -------------------------------------------------------------------
            // Insert the vote — the UNIQUE constraint on (student_id, election_id)
            // is our last line of defence against double voting at the DB level
            // -------------------------------------------------------------------
            $stmt = mysqli_prepare($conn,
                "INSERT INTO votes (student_id, election_id, candidate_id)
                 VALUES (?, ?, ?)"
            );
            mysqli_stmt_bind_param($stmt, "iii", $student_id, $election_id, $candidate_id);

            if (mysqli_stmt_execute($stmt)) {
                mysqli_stmt_close($stmt);
                // Vote recorded — redirect to results immediately
                header("Location: results.php?election_id=" . $election_id . "&voted=1");
                exit();

            } else {
                // Most likely cause: duplicate entry caught by UNIQUE constraint
                $error = "Your vote could not be recorded. You may have already voted in this election.";
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
    <title>PollPoint &mdash; Cast Your Vote</title>
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

        .topbar-logo {
            font-family: var(--font-serif);
            font-size: 1.3rem;
            font-weight: 900;
        }

        .topbar-logo span { color: var(--accent); }

        .topbar-right {
            display: flex;
            align-items: center;
            gap: 20px;
            font-size: 0.875rem;
        }

        .topbar-name { color: var(--text-muted); }
        .topbar-name strong { color: var(--text-primary); }

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

        .btn-logout:hover {
            border-color: var(--danger);
            color: var(--danger);
        }

        /* ===================== MAIN ===================== */
        .main {
            max-width: 720px;
            margin: 0 auto;
            padding: 40px 24px 60px;
        }

        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            color: var(--text-muted);
            text-decoration: none;
            font-size: 0.875rem;
            margin-bottom: 28px;
            transition: color var(--transition-base);
        }

        .back-link:hover { color: var(--text-primary); }

        .page-header {
            margin-bottom: 32px;
            padding-bottom: 24px;
            border-bottom: 1px solid var(--border-subtle);
        }

        .page-header h1 {
            font-family: var(--font-serif);
            font-size: 1.7rem;
            margin-bottom: 8px;
        }

        .page-header p {
            color: var(--text-muted);
            font-size: 0.92rem;
            line-height: 1.6;
        }

        /* ===================== ALERTS ===================== */
        .alert {
            padding: 12px 16px;
            border-radius: var(--radius-md);
            margin-bottom: 24px;
            font-size: 0.875rem;
            display: flex;
            gap: 8px;
            align-items: flex-start;
        }

        .alert-error {
            background: var(--danger-soft);
            border: 1px solid rgba(220,38,38,0.18);
            color: var(--danger);
        }

        /* ===================== INSTRUCTION BAR ===================== */
        .instruction {
            background: var(--surface);
            border: 1px solid var(--card-border);
            border-radius: var(--radius-md);
            padding: 14px 18px;
            font-size: 0.875rem;
            color: var(--text-muted);
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .instruction strong { color: var(--accent); }

        /* ===================== CANDIDATE CARDS ===================== */
        .candidates-list {
            display: flex;
            flex-direction: column;
            gap: 12px;
            margin-bottom: 32px;
        }

        .candidate-option input[type="radio"] { display: none; }

        .candidate-card {
            display: flex;
            align-items: flex-start;
            gap: 16px;
            background: var(--card-background);
            border: 2px solid var(--card-border);
            border-radius: var(--radius-md);
            padding: 20px 22px;
            cursor: pointer;
            transition: border-color var(--transition-base), background var(--transition-base), transform 0.15s;
            user-select: none;
        }

        .candidate-card:hover {
            border-color: var(--accent);
            background: var(--surface-elevated);
            transform: translateX(3px);
        }

        .candidate-option input[type="radio"]:checked + .candidate-card {
            border-color: var(--accent);
            background: var(--accent-soft);
        }

        .radio-circle {
            width: 20px;
            height: 20px;
            border-radius: 50%;
            border: 2px solid var(--card-border);
            flex-shrink: 0;
            margin-top: 2px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: border-color var(--transition-base);
        }

        .candidate-option input[type="radio"]:checked + .candidate-card .radio-circle {
            border-color: var(--accent);
            background: var(--accent);
        }

        .candidate-option input[type="radio"]:checked + .candidate-card .radio-circle::after {
            content: '';
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: var(--text-inverse);
        }

        .candidate-info { flex: 1; }

        .candidate-name {
            font-family: var(--font-serif);
            font-size: 1.05rem;
            font-weight: 700;
            margin-bottom: 5px;
        }

        .candidate-desc {
            font-size: 0.85rem;
            color: var(--text-muted);
            line-height: 1.6;
        }

        /* ===================== SUBMIT AREA ===================== */
        .submit-area {
            background: var(--surface);
            border: 1px solid var(--card-border);
            border-radius: var(--radius-md);
            padding: 24px;
        }

        .submit-warning {
            font-size: 0.82rem;
            color: var(--text-muted);
            margin-bottom: 16px;
            line-height: 1.6;
        }

        .submit-warning strong { color: var(--danger); }

        .btn-submit {
            width: 100%;
            padding: 14px;
            background: var(--button-primary-bg);
            color: var(--button-primary-text);
            border: none;
            border-radius: var(--radius-md);
            font-family: var(--font-sans);
            font-size: 1rem;
            font-weight: 700;
            cursor: pointer;
            transition: background var(--transition-base), transform 0.1s;
        }

        .btn-submit:hover  { background: var(--button-primary-hover, var(--accent-hover)); }
        .btn-submit:active { transform: scale(0.98); }

        /* Confirmation modal overlay */
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
            border: 1px solid var(--card-border);
            border-radius: var(--radius-md);
            padding: 36px 32px;
            max-width: 420px;
            width: 90%;
            text-align: center;
            animation: fadeUp 0.25s ease both;
        }

        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(16px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        .modal-icon { font-size: 2.5rem; margin-bottom: 16px; }

        .modal h3 {
            font-family: var(--font-serif);
            font-size: 1.2rem;
            margin-bottom: 10px;
        }

        .modal p {
            color: var(--text-muted);
            font-size: 0.9rem;
            line-height: 1.6;
            margin-bottom: 24px;
        }

        .modal p strong { color: var(--text-primary); }

        .modal-buttons { display: flex; gap: 12px; }

        .btn-cancel {
            flex: 1;
            padding: 11px;
            background: transparent;
            border: 1px solid var(--card-border);
            border-radius: var(--radius-md);
            color: var(--text-muted);
            font-family: var(--font-sans);
            font-size: 0.9rem;
            cursor: pointer;
            transition: border-color var(--transition-base), color var(--transition-base);
        }

        .btn-cancel:hover {
            border-color: var(--text-muted);
            color: var(--text-primary);
        }

        .btn-confirm {
            flex: 1;
            padding: 11px;
            background: var(--button-primary-bg);
            color: var(--button-primary-text);
            border: none;
            border-radius: var(--radius-md);
            font-family: var(--font-sans);
            font-size: 0.9rem;
            font-weight: 700;
            cursor: pointer;
            transition: background var(--transition-base);
        }

        .btn-confirm:hover { background: var(--button-primary-hover, var(--accent-hover)); }

        @media (max-width: 600px) {
            .topbar { padding: 0 16px; }
            .main   { padding: 24px 16px 48px; }
            .topbar-name { display: none; }
        }
    </style>
    <script src="script.js"></script>
</head>
<body>

<!-- TOPBAR -->
<nav class="topbar">
    <div class="topbar-logo">Poll<span>Point</span></div>
    <div class="topbar-right">
        <span class="topbar-name">
            Signed in as <strong><?php echo htmlspecialchars($_SESSION['full_name']); ?></strong>
        </span>
        <button id="theme-toggle-btn" class="theme-toggle-btn" onclick="toggleTheme()">🌙 Dark Mode</button>
        <a href="logout.php" class="btn-logout">Sign Out</a>
    </div>
</nav>

<!-- MAIN -->
<main class="main">

    <a href="student_dashboard.php" class="back-link">&#8592; Back to Dashboard</a>

    <div class="page-header">
        <h1><?php echo htmlspecialchars($election['title']); ?></h1>
        <p><?php echo htmlspecialchars($election['description']); ?></p>
    </div>

    <?php if (!empty($error)): ?>
        <div class="alert alert-error">&#9888; <?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <div class="instruction">
        &#128717;&nbsp; <span><strong>Select one candidate</strong> from the list below, then click Submit Vote. Your vote is final and cannot be changed.</span>
    </div>

    <?php if (empty($candidates)): ?>
        <div class="alert alert-error">&#9888; No candidates have been added to this election yet.</div>
    <?php else: ?>

    <!-- The form posts to this same page -->
    <form method="POST" action="vote.php?election_id=<?php echo $election_id; ?>" id="voteForm">

        <div class="candidates-list">
            <?php foreach ($candidates as $index => $c): ?>
            <div class="candidate-option">
                <input
                    type="radio"
                    name="candidate_id"
                    id="candidate_<?php echo $c['id']; ?>"
                    value="<?php echo $c['id']; ?>"
                    <?php echo (isset($_POST['candidate_id']) && $_POST['candidate_id'] == $c['id']) ? 'checked' : ''; ?>
                >
                <label class="candidate-card" for="candidate_<?php echo $c['id']; ?>">
                    <div class="radio-circle"></div>
                    <div class="candidate-info">
                        <div class="candidate-name"><?php echo htmlspecialchars($c['name']); ?></div>
                        <div class="candidate-desc"><?php echo htmlspecialchars($c['description']); ?></div>
                    </div>
                </label>
            </div>
            <?php endforeach; ?>
        </div>

        <div class="submit-area">
            <p class="submit-warning">
                <strong>Important:</strong> Once submitted, your vote cannot be changed or withdrawn.
                Please make sure you have selected the correct candidate.
            </p>
            <button type="button" class="btn-submit" onclick="openConfirmModal()">
                Submit Vote &rarr;
            </button>
        </div>

    </form>

    <?php endif; ?>

</main>

<!-- ====================================================
     CONFIRMATION MODAL
     Shows the selected candidate name before final submit
===================================================== -->
<div class="modal-overlay" id="confirmModal">
    <div class="modal">
        <div class="modal-icon">&#128717;</div>
        <h3>Confirm Your Vote</h3>
        <p>You are voting for: <strong id="modalCandidateName">—</strong><br>
        This action cannot be undone.</p>
        <div class="modal-buttons">
            <button type="button" class="btn-cancel" onclick="closeConfirmModal()">Go Back</button>
            <button type="button" class="btn-confirm" onclick="submitVote()">Yes, Submit</button>
        </div>
    </div>
</div>

<script>
    // Candidate names indexed by their value for the modal display
    const candidateNames = {
        <?php foreach ($candidates as $c): ?>
        <?php echo $c['id']; ?>: "<?php echo addslashes(htmlspecialchars($c['name'])); ?>",
        <?php endforeach; ?>
    };

    function openConfirmModal() {
        // Check a candidate is selected
        const selected = document.querySelector('input[name="candidate_id"]:checked');
        if (!selected) {
            alert("Please select a candidate first.");
            return;
        }
        // Show their name in the modal
        document.getElementById('modalCandidateName').textContent = candidateNames[selected.value];
        document.getElementById('confirmModal').classList.add('active');
    }

    function closeConfirmModal() {
        document.getElementById('confirmModal').classList.remove('active');
    }

    function submitVote() {
        // Actually submit the hidden form
        document.getElementById('voteForm').submit();
    }

    // Close modal if user clicks the dark overlay behind it
    document.getElementById('confirmModal').addEventListener('click', function(e) {
        if (e.target === this) closeConfirmModal();
    });
</script>

</body>
</html>