<?php
/*
 * student_dashboard.php
 * ----------------------
 * Main page for logged-in students.
 * Shows: open elections they can vote in, pending elections (coming soon),
 *        and closed elections whose results they can view.
 */

session_start();
require_once 'db.php';

// --- Session guard: only students allowed here ---
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

if ($_SESSION['role'] !== 'student') {
    header("Location: admin_dashboard.php");
    exit();
}

$student_id   = $_SESSION['user_id'];
$student_name = $_SESSION['full_name'];

// -----------------------------------------------------------------------
// Fetch all elections with a flag showing whether this student has voted
// -----------------------------------------------------------------------
$stmt = mysqli_prepare($conn,
    "SELECT
        e.id,
        e.title,
        e.description,
        e.status,
        e.created_at,
        (SELECT COUNT(*) FROM votes v
         WHERE v.election_id = e.id AND v.student_id = ?) AS has_voted,
        (SELECT COUNT(*) FROM candidates c WHERE c.election_id = e.id) AS candidate_count,
        (SELECT COUNT(*) FROM votes v WHERE v.election_id = e.id) AS total_votes
     FROM elections e
     ORDER BY
        FIELD(e.status, 'open', 'pending', 'closed'),
        e.created_at DESC"
);
mysqli_stmt_bind_param($stmt, "i", $student_id);
mysqli_stmt_execute($stmt);
$result    = mysqli_stmt_get_result($stmt);
$elections = [];

while ($row = mysqli_fetch_assoc($result)) {
    $elections[] = $row;
}
mysqli_stmt_close($stmt);

// Separate into groups for cleaner display
$open_elections   = array_filter($elections, fn($e) => $e['status'] === 'open');
$pending_elections = array_filter($elections, fn($e) => $e['status'] === 'pending');
$closed_elections = array_filter($elections, fn($e) => $e['status'] === 'closed');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PollPoint &mdash; Student Dashboard</title>
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
            background: var(--bg-surface);
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

        .topbar-name { color: var(--text-secondary); }
        .topbar-name strong { color: var(--text-primary); }

        .btn-logout {
            padding: 7px 16px;
            background: transparent;
            border: 1px solid var(--border-subtle);
            border-radius: var(--radius-md);
            color: var(--text-secondary);
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

        /* ===================== MAIN CONTENT ===================== */
        .main {
            max-width: 900px;
            margin: 0 auto;
            padding: 40px 24px 60px;
        }

        .page-header {
            margin-bottom: 36px;
        }

        .page-header h1 {
            font-family: var(--font-serif);
            font-size: 1.8rem;
            margin-bottom: 6px;
        }

        .page-header p {
            color: var(--text-secondary);
            font-size: 0.95rem;
        }

        /* ===================== SECTION HEADINGS ===================== */
        .section-label {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 16px;
        }

        .section-label h2 {
            font-family: var(--font-serif);
            font-size: 1.1rem;
        }

        .section-label .count-badge {
            background: var(--bg-surface-2);
            border: 1px solid var(--border-subtle);
            border-radius: 20px;
            padding: 2px 10px;
            font-size: 0.78rem;
            color: var(--text-secondary);
        }

        .section { margin-bottom: 44px; }

        /* ===================== ELECTION CARDS ===================== */
        .election-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
            gap: 16px;
        }

        .election-card {
            background: var(--bg-surface);
            border: 1px solid var(--border-subtle);
            border-radius: 12px;
            padding: 22px 20px;
            display: flex;
            flex-direction: column;
            gap: 12px;
            transition: border-color var(--transition-base), transform var(--transition-base);
        }

        .election-card:hover {
            border-color: var(--accent);
            transform: translateY(-2px);
        }

        .election-card.voted {
            border-color: rgba(63,185,80,0.3);
        }

        .card-top {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
        }

        .election-title {
            font-family: var(--font-serif);
            font-size: 1rem;
            line-height: 1.4;
            flex: 1;
            margin-right: 10px;
        }

        /* Status pill */
        .status-pill {
            font-size: 0.7rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            padding: 3px 9px;
            border-radius: 20px;
            flex-shrink: 0;
        }

        .pill-open    { background: var(--success-soft);  color: var(--success); }
        .pill-pending { background: var(--warning-soft); color: var(--warning); }
        .pill-closed  { background: rgba(139,148,158,0.12); color: var(--text-secondary); }

        .election-desc {
            font-size: 0.85rem;
            color: var(--text-secondary);
            line-height: 1.6;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .card-meta {
            display: flex;
            gap: 16px;
            font-size: 0.78rem;
            color: var(--text-secondary);
        }

        .voted-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-size: 0.78rem;
            color: var(--success);
            font-weight: 600;
        }

        /* Card action buttons */
        .btn-vote {
            display: block;
            text-align: center;
            padding: 10px;
            background: var(--accent);
            color: var(--text-inverse);
            border-radius: var(--radius-md);
            text-decoration: none;
            font-weight: 700;
            font-size: 0.875rem;
            transition: background var(--transition-base);
            margin-top: auto;
        }

        .btn-vote:hover { background: var(--accent-hover); }

        .btn-results {
            display: block;
            text-align: center;
            padding: 10px;
            background: transparent;
            color: var(--text-secondary);
            border: 1px solid var(--border-subtle);
            border-radius: var(--radius-md);
            text-decoration: none;
            font-size: 0.875rem;
            transition: border-color var(--transition-base), color var(--transition-base);
            margin-top: auto;
        }

        .btn-results:hover {
            border-color: var(--text-muted);
            color: var(--text-primary);
        }

        .btn-disabled {
            display: block;
            text-align: center;
            padding: 10px;
            background: var(--bg-surface-2);
            color: var(--text-secondary);
            border-radius: var(--radius-md);
            font-size: 0.875rem;
            margin-top: auto;
            cursor: not-allowed;
        }

        /* Empty state */
        .empty-state {
            text-align: center;
            padding: 32px;
            color: var(--text-secondary);
            font-size: 0.9rem;
            background: var(--bg-surface);
            border: 1px dashed var(--border-subtle);
            border-radius: 12px;
        }

        @media (max-width: 600px) {
            .topbar { padding: 0 16px; }
            .main   { padding: 24px 16px 48px; }
            .topbar-name { display: none; }
        }
    </style>    <script src="script.js"></script></head>
<body>

<!-- ====================================================
     TOP NAVIGATION BAR
===================================================== -->
<nav class="topbar">
    <div class="topbar-logo">Poll<span>Point</span></div>
    <div class="topbar-right">
        <span class="topbar-name">
            Signed in as <strong><?php echo htmlspecialchars($student_name); ?></strong>
        </span>
        <button id="theme-toggle-btn" class="theme-toggle-btn" onclick="toggleTheme()">🌙 Dark Mode</button>
        <a href="logout.php" class="btn-logout">Sign Out</a>
    </div>
</nav>

<!-- ====================================================
     MAIN CONTENT
===================================================== -->
<main class="main">

    <div class="page-header">
        <h1>Good day, <?php echo htmlspecialchars(explode(' ', $student_name)[0]); ?> </h1>
        <p>Here are all available elections. Open elections are ready for your vote.</p>
    </div>

    <!-- ================= OPEN ELECTIONS ================= -->
    <div class="section">
        <div class="section-label">
            <h2>Open Elections</h2>
            <span class="count-badge"><?php echo count($open_elections); ?></span>
        </div>

        <?php if (empty($open_elections)): ?>
            <div class="empty-state">No elections are currently open for voting.</div>
        <?php else: ?>
            <div class="election-grid">
            <?php foreach ($open_elections as $e): ?>
                <div class="election-card <?php echo $e['has_voted'] ? 'voted' : ''; ?>">
                    <div class="card-top">
                        <div class="election-title"><?php echo htmlspecialchars($e['title']); ?></div>
                        <span class="status-pill pill-open">Open</span>
                    </div>

                    <p class="election-desc"><?php echo htmlspecialchars($e['description']); ?></p>

                    <div class="card-meta">
                        <span>&#128101; <?php echo $e['candidate_count']; ?> candidates</span>
                        <span>&#128221; <?php echo $e['total_votes']; ?> votes cast</span>
                    </div>

                    <?php if ($e['has_voted']): ?>
                        <span class="voted-badge">&#10003; You have voted in this election</span>
                        <a href="results.php?election_id=<?php echo $e['id']; ?>" class="btn-results">
                            View Live Tally
                        </a>
                    <?php else: ?>
                        <a href="vote.php?election_id=<?php echo $e['id']; ?>" class="btn-vote">
                            Cast Your Vote &rarr;
                        </a>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- ================= PENDING ELECTIONS ================= -->
    <div class="section">
        <div class="section-label">
            <h2>Upcoming Elections</h2>
            <span class="count-badge"><?php echo count($pending_elections); ?></span>
        </div>

        <?php if (empty($pending_elections)): ?>
            <div class="empty-state">No upcoming elections at this time.</div>
        <?php else: ?>
            <div class="election-grid">
            <?php foreach ($pending_elections as $e): ?>
                <div class="election-card">
                    <div class="card-top">
                        <div class="election-title"><?php echo htmlspecialchars($e['title']); ?></div>
                        <span class="status-pill pill-pending">Soon</span>
                    </div>
                    <p class="election-desc"><?php echo htmlspecialchars($e['description']); ?></p>
                    <div class="card-meta">
                        <span>&#128101; <?php echo $e['candidate_count']; ?> candidates registered</span>
                    </div>
                    <span class="btn-disabled">Voting not yet open</span>
                </div>
            <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- ================= CLOSED ELECTIONS ================= -->
    <div class="section">
        <div class="section-label">
            <h2>Past Elections</h2>
            <span class="count-badge"><?php echo count($closed_elections); ?></span>
        </div>

        <?php if (empty($closed_elections)): ?>
            <div class="empty-state">No past elections to display.</div>
        <?php else: ?>
            <div class="election-grid">
            <?php foreach ($closed_elections as $e): ?>
                <div class="election-card">
                    <div class="card-top">
                        <div class="election-title"><?php echo htmlspecialchars($e['title']); ?></div>
                        <span class="status-pill pill-closed">Closed</span>
                    </div>
                    <p class="election-desc"><?php echo htmlspecialchars($e['description']); ?></p>
                    <div class="card-meta">
                        <span>&#128221; <?php echo $e['total_votes']; ?> total votes</span>
                    </div>
                    <a href="results.php?election_id=<?php echo $e['id']; ?>" class="btn-results">
                        View Final Results
                    </a>
                </div>
            <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

</main>

</body>
</html>