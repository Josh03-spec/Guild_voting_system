<?php
/*
 * results.php
 * ------------
 * Displays vote results for a specific election.
 *
 * Students can view:
 *   - Open elections: live tally (only after they have voted)
 *   - Closed elections: final results (always visible)
 *
 * Expects: ?election_id=X in the URL
 * Optional: ?voted=1 (appended after a successful vote — shows success banner)
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

$student_id  = $_SESSION['user_id'];
$election_id = intval($_GET['election_id'] ?? 0);
$just_voted  = isset($_GET['voted']) && $_GET['voted'] == 1;

if ($election_id <= 0) {
    header("Location: student_dashboard.php");
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
    header("Location: student_dashboard.php");
    exit();
}

// --- For open elections: student must have voted to see results ---
if ($election['status'] === 'open') {
    $stmt = mysqli_prepare($conn,
        "SELECT id FROM votes WHERE student_id = ? AND election_id = ?"
    );
    mysqli_stmt_bind_param($stmt, "ii", $student_id, $election_id);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_store_result($stmt);
    $has_voted = mysqli_stmt_num_rows($stmt) > 0;
    mysqli_stmt_close($stmt);

    if (!$has_voted) {
        // Has not voted yet — send them to the voting page
        header("Location: vote.php?election_id=" . $election_id);
        exit();
    }
}

// --- Pending elections have no results to show ---
if ($election['status'] === 'pending') {
    header("Location: student_dashboard.php");
    exit();
}

// -----------------------------------------------------------------------
// Fetch results: each candidate with their vote count
// -----------------------------------------------------------------------
$stmt = mysqli_prepare($conn,
    "SELECT
        c.id,
        c.name,
        c.description,
        COUNT(v.id) AS vote_count
     FROM candidates c
     LEFT JOIN votes v ON v.candidate_id = c.id AND v.election_id = ?
     WHERE c.election_id = ?
     GROUP BY c.id, c.name, c.description
     ORDER BY vote_count DESC"
);
mysqli_stmt_bind_param($stmt, "ii", $election_id, $election_id);
mysqli_stmt_execute($stmt);
$result     = mysqli_stmt_get_result($stmt);
$candidates = [];

while ($row = mysqli_fetch_assoc($result)) {
    $candidates[] = $row;
}
mysqli_stmt_close($stmt);

// --- Total votes cast in this election ---
$total_votes = array_sum(array_column($candidates, 'vote_count'));

// --- Find the winning vote count (for highlighting the leader) ---
$max_votes = !empty($candidates) ? $candidates[0]['vote_count'] : 0;

// --- Find which candidate this student voted for (if any) ---
$my_vote_candidate_id = null;
$stmt = mysqli_prepare($conn,
    "SELECT candidate_id FROM votes WHERE student_id = ? AND election_id = ?"
);
mysqli_stmt_bind_param($stmt, "ii", $student_id, $election_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$my_vote_row = mysqli_fetch_assoc($result);
mysqli_stmt_close($stmt);

if ($my_vote_row) {
    $my_vote_candidate_id = $my_vote_row['candidate_id'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PollPoint &mdash; Results</title>
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
            max-width: 760px;
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

        /* ===================== SUCCESS BANNER ===================== */
        .success-banner {
            background: var(--success-soft);
            border: 1px solid rgba(34,197,94,0.18);
            border-radius: 12px;
            padding: 18px 22px;
            margin-bottom: 28px;
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .success-banner-icon { font-size: 1.8rem; }

        .success-banner-text h3 {
            font-size: 1rem;
            color: var(--success);
            margin-bottom: 2px;
        }

        .success-banner-text p {
            font-size: 0.85rem;
            color: var(--text-muted);
        }

        /* ===================== PAGE HEADER ===================== */
        .page-header {
            margin-bottom: 28px;
            padding-bottom: 22px;
            border-bottom: 1px solid var(--border-subtle);
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 16px;
        }

        .page-header h1 {
            font-family: var(--font-serif);
            font-size: 1.6rem;
            margin-bottom: 6px;
        }

        .page-header p {
            color: var(--text-muted);
            font-size: 0.88rem;
        }

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

        .pill-open   { background: var(--success-soft);   color: var(--success); }
        .pill-closed { background: rgba(139,148,158,0.15); color: var(--text-muted); }

        /* ===================== SUMMARY ROW ===================== */
        .summary-row {
            display: flex;
            gap: 14px;
            margin-bottom: 28px;
        }

        .summary-box {
            flex: 1;
            background: var(--surface);
            border: 1px solid var(--card-border);
            border-radius: var(--radius-md);
            padding: 18px 20px;
            text-align: center;
        }

        .summary-number {
            font-family: var(--font-serif);
            font-size: 2rem;
            font-weight: 900;
            color: var(--accent);
            display: block;
        }

        .summary-label {
            font-size: 0.78rem;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.06em;
            margin-top: 4px;
        }

        /* ===================== RESULTS LIST ===================== */
        .results-list {
            display: flex;
            flex-direction: column;
            gap: 14px;
        }

        .result-row {
            background: var(--surface);
            border: 1px solid var(--card-border);
            border-radius: 12px;
            padding: 20px 22px;
            position: relative;
            overflow: hidden;
            transition: border-color var(--transition-base);
        }

        /* Highlight the leading candidate */
        .result-row.leader {
            border-color: rgba(232,168,56,0.4);
        }

        /* Highlight candidate this student voted for */
        .result-row.my-vote {
            border-color: rgba(63,185,80,0.35);
        }

        /* Both: voted for the leader */
        .result-row.leader.my-vote {
            border-color: var(--accent);
        }

        .result-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
            gap: 10px;
        }

        .result-name {
            font-family: var(--font-serif);
            font-size: 1.05rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .crown { color: var(--accent); font-size: 1rem; }

        .voted-for-tag {
            font-size: 0.72rem;
            color: var(--success);
            font-weight: 700;
            background: var(--success-soft);
            border: 1px solid rgba(34,197,94,0.2);
            padding: 2px 8px;
            border-radius: 20px;
        }

        .result-count {
            font-size: 1.3rem;
            font-weight: 700;
            color: var(--text-primary);
            white-space: nowrap;
        }

        .result-desc {
            font-size: 0.83rem;
            color: var(--text-muted);
            margin-bottom: 14px;
            line-height: 1.5;
        }

        /* Progress bar track */
        .bar-track {
            background: var(--surface-elevated);
            border-radius: 4px;
            height: 8px;
            overflow: hidden;
        }

        /* Filled portion — width set inline via PHP */
        .bar-fill {
            height: 100%;
            border-radius: 4px;
            background: var(--accent);
            transition: width 1s ease;
        }

        .result-row.my-vote .bar-fill {
            background: var(--success);
        }

        .bar-percent {
            font-size: 0.78rem;
            color: var(--text-muted);
            margin-top: 6px;
            text-align: right;
        }

        /* ===================== LIVE NOTICE (open elections) ===================== */
        .live-notice {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.82rem;
            color: var(--text-muted);
            margin-top: 24px;
            padding: 12px 16px;
            background: var(--surface);
            border: 1px solid var(--card-border);
            border-radius: var(--radius-md);
        }

        .live-dot {
            width: 8px; height: 8px;
            border-radius: 50%;
            background: var(--success);
            flex-shrink: 0;
            animation: pulse 1.5s ease infinite;
        }

        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50%       { opacity: 0.3; }
        }

        /* ===================== EMPTY STATE ===================== */
        .empty-state {
            text-align: center;
            padding: 40px;
            color: var(--text-muted);
            font-size: 0.9rem;
            background: var(--surface);
            border: 1px dashed var(--border-subtle);
            border-radius: 12px;
        }

        @media (max-width: 600px) {
            .topbar     { padding: 0 16px; }
            .main       { padding: 24px 16px 48px; }
            .topbar-name { display: none; }
            .summary-row { flex-direction: column; }
            .page-header { flex-direction: column; }
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

    <!-- Success banner — only shown immediately after voting -->
    <?php if ($just_voted): ?>
    <div class="success-banner">
        <div class="success-banner-icon">&#9989;</div>
        <div class="success-banner-text">
            <h3>Your vote has been recorded!</h3>
            <p>Thank you for participating. Here are the current results.</p>
        </div>
    </div>
    <?php endif; ?>

    <!-- Page header -->
    <div class="page-header">
        <div>
            <h1><?php echo htmlspecialchars($election['title']); ?></h1>
            <p><?php echo htmlspecialchars($election['description']); ?></p>
        </div>
        <span class="status-pill pill-<?php echo $election['status']; ?>">
            <?php echo $election['status'] === 'open' ? 'Live' : 'Final'; ?>
        </span>
    </div>

    <!-- Summary stats -->
    <div class="summary-row">
        <div class="summary-box">
            <span class="summary-number"><?php echo $total_votes; ?></span>
            <div class="summary-label">Total Votes Cast</div>
        </div>
        <div class="summary-box">
            <span class="summary-number"><?php echo count($candidates); ?></span>
            <div class="summary-label">Candidates</div>
        </div>
        <div class="summary-box">
            <span class="summary-number">
                <?php echo $max_votes > 0 ? round(($max_votes / $total_votes) * 100) : 0; ?>%
            </span>
            <div class="summary-label">Leading Share</div>
        </div>
    </div>

    <!-- Results -->
    <?php if (empty($candidates)): ?>
        <div class="empty-state">No candidates found for this election.</div>
    <?php else: ?>

    <div class="results-list">
        <?php foreach ($candidates as $index => $c):
            $pct        = $total_votes > 0 ? round(($c['vote_count'] / $total_votes) * 100) : 0;
            $is_leader  = ($c['vote_count'] === $max_votes && $max_votes > 0);
            $is_my_vote = ($c['id'] === $my_vote_candidate_id);

            $row_class  = '';
            if ($is_leader)  $row_class .= ' leader';
            if ($is_my_vote) $row_class .= ' my-vote';
        ?>
        <div class="result-row <?php echo trim($row_class); ?>">
            <div class="result-top">
                <div class="result-name">
                    <?php if ($is_leader && $total_votes > 0): ?>
                        <span class="crown">&#9733;</span>
                    <?php endif; ?>
                    <?php echo htmlspecialchars($c['name']); ?>
                    <?php if ($is_my_vote): ?>
                        <span class="voted-for-tag">Your vote</span>
                    <?php endif; ?>
                </div>
                <div class="result-count">
                    <?php echo $c['vote_count']; ?>
                    <?php echo $c['vote_count'] === 1 ? 'vote' : 'votes'; ?>
                </div>
            </div>

            <p class="result-desc"><?php echo htmlspecialchars($c['description']); ?></p>

            <!-- Bar chart row -->
            <div class="bar-track">
                <div class="bar-fill" style="width: <?php echo $pct; ?>%"></div>
            </div>
            <div class="bar-percent"><?php echo $pct; ?>% of votes</div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Live notice for open elections -->
    <?php if ($election['status'] === 'open'): ?>
    <div class="live-notice">
        <div class="live-dot"></div>
        This election is still open. Results are updating in real time as votes are cast.
    </div>
    <?php endif; ?>

    <?php endif; ?>

</main>

</body>
</html>