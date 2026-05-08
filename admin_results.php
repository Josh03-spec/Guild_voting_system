<?php
/*
 * admin_results.php
 * ------------------
 * Admin page to view detailed vote results for any election.
 *
 * Features:
 *   - View results for any election (pending, open, or closed)
 *   - See full vote breakdown per candidate
 *   - See total votes cast
 *   - See voter participation %
 *   - Admin-only access
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

$election_id = intval($_GET['election_id'] ?? 0);

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

// -----------------------------------------------------------------------
// Fetch results: each candidate with their vote count and percentage
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

// --- Eligible student count (for participation %) ---
$r = mysqli_query($conn, "SELECT COUNT(*) AS c FROM eligible_students WHERE is_claimed = 1");
$eligible_count = mysqli_fetch_assoc($r)['c'] ?? 0;
$participation_pct = $eligible_count > 0 ? round(($total_votes / $eligible_count) * 100, 1) : 0;

// --- Total students in system ---
$r = mysqli_query($conn, "SELECT COUNT(*) AS c FROM users WHERE role = 'student'");
$total_students = mysqli_fetch_assoc($r)['c'];
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

        .topbar-left { display: flex; align-items: center; gap: 32px; }

        .topbar-logo {
            font-family: var(--font-serif);
            font-size: 1.3rem;
            font-weight: 900;
        }

        .topbar-logo span { color: var(--accent); }

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

        .btn-back {
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

        .btn-back:hover { border-color: var(--text-muted); color: var(--text-primary); }

        /* ===================== MAIN ===================== */
        .main {
            max-width: 1000px;
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
            margin-bottom: 28px;
        }

        .page-header h1 {
            font-family: var(--font-serif);
            font-size: 1.8rem;
            margin-bottom: 6px;
        }

        .page-header p { color: var(--text-muted); font-size: 0.9rem; }

        .status-pill {
            font-size: 0.72rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            padding: 4px 12px;
            border-radius: 20px;
            white-space: nowrap;
            display: inline-block;
            margin-top: 8px;
        }

        .pill-open    { background: var(--success-soft);  color: var(--success); }
        .pill-pending { background: var(--warning-soft);  color: var(--warning); }
        .pill-closed  { background: var(--border-subtle); color: var(--text-muted); }

        /* ===================== STAT BOX ROW ===================== */
        .stat-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 32px;
        }

        .stat-box {
            background: var(--surface);
            border: 1px solid var(--card-border);
            border-radius: var(--radius-md);
            padding: 20px;
            text-align: center;
        }

        .stat-box .num {
            font-family: var(--font-serif);
            font-size: 2rem;
            font-weight: 900;
            color: var(--accent);
            margin-bottom: 6px;
        }

        .stat-box .lbl {
            font-size: 0.75rem;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.07em;
        }

        /* ===================== RESULTS TABLE ===================== */
        .results-title {
            font-family: var(--font-serif);
            font-size: 1.2rem;
            margin-bottom: 16px;
            margin-top: 32px;
        }

        .table-wrap {
            background: var(--surface);
            border: 1px solid var(--card-border);
            border-radius: var(--radius-lg);
            overflow: hidden;
            margin-bottom: 32px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.875rem;
        }

        thead th {
            padding: 14px 18px;
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
            padding: 18px;
            border-bottom: 1px solid var(--card-border);
            vertical-align: middle;
        }

        tbody tr:last-child td { border-bottom: none; }
        tbody tr:hover td { background: var(--table-row-hover); }

        .candidate-info { flex: 1; min-width: 0; }

        .candidate-name {
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 4px;
        }

        .candidate-desc {
            font-size: 0.8rem;
            color: var(--text-muted);
        }

        .vote-count {
            text-align: center;
            font-weight: 600;
            font-size: 1rem;
            color: var(--accent);
        }

        .vote-pct {
            text-align: right;
            color: var(--text-muted);
            font-size: 0.85rem;
            min-width: 50px;
        }

        .bar-container {
            background: var(--surface-elevated);
            border-radius: 4px;
            height: 28px;
            overflow: hidden;
            position: relative;
        }

        .bar-fill {
            background: var(--accent);
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: flex-end;
            padding-right: 8px;
            min-width: 30px;
            color: var(--text-inverse);
            font-size: 0.72rem;
            font-weight: 600;
            transition: width var(--transition-base);
        }

        .bar-fill.leading {
            background: var(--accent);
        }

        .empty-message {
            text-align: center;
            padding: 40px 20px;
            color: var(--text-muted);
        }

        .empty-message p {
            font-size: 0.95rem;
            line-height: 1.6;
        }

        @media (max-width: 900px) {
            .main { padding: 24px 16px 48px; }
            .stat-row { grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); }
        }

        @media (max-width: 650px) {
            .topbar { padding: 0 16px; }
            .page-header h1 { font-size: 1.4rem; }
            .stat-row { grid-template-columns: 1fr; }
            table { font-size: 0.8rem; }
            thead th, tbody td { padding: 12px; }
        }
    </style>
</head>
<body>

<!-- TOPBAR -->
<nav class="topbar">
    <div class="topbar-left">
        <div class="topbar-logo">Poll<span>Point</span></div>
    </div>
    <div class="topbar-right">
        <span class="admin-badge">Admin</span>
        <button id="theme-toggle-btn" class="theme-toggle-btn" onclick="toggleTheme()">🌙 Dark Mode</button>
        <a href="admin_elections.php" class="btn-back">← Back</a>
    </div>
</nav>

<main class="main">

    <!-- PAGE HEADER -->
    <div class="page-header">
        <h1><?php echo htmlspecialchars($election['title']); ?></h1>
        <?php if (!empty($election['description'])): ?>
        <p><?php echo htmlspecialchars($election['description']); ?></p>
        <?php endif; ?>
        <span class="status-pill pill-<?php echo $election['status']; ?>">
            <?php echo ucfirst($election['status']); ?>
        </span>
    </div>

    <!-- STAT BOXES -->
    <div class="stat-row">
        <div class="stat-box">
            <div class="num"><?php echo $total_votes; ?></div>
            <div class="lbl">Total Votes</div>
        </div>
        <div class="stat-box">
            <div class="num"><?php echo count($candidates); ?></div>
            <div class="lbl">Candidates</div>
        </div>
        <div class="stat-box">
            <div class="num"><?php echo $participation_pct; ?>%</div>
            <div class="lbl">Participation</div>
        </div>
    </div>

    <!-- RESULTS TABLE -->
    <h2 class="results-title">Vote Results</h2>

    <?php if (!empty($candidates)): ?>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Candidate</th>
                    <th style="text-align: center;">Votes</th>
                    <th style="text-align: right;">Percentage</th>
                    <th>Distribution</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($candidates as $idx => $candidate): 
                $vote_pct = $total_votes > 0 ? ($candidate['vote_count'] / $total_votes) * 100 : 0;
                $bar_width = $total_votes > 0 ? ($candidate['vote_count'] / $max_votes) * 100 : 0;
            ?>
                <tr>
                    <td>
                        <div class="candidate-info">
                            <div class="candidate-name">
                                <?php if ($idx === 0 && $max_votes > 0): ?>
                                👑 
                                <?php endif; ?>
                                <?php echo htmlspecialchars($candidate['name']); ?>
                            </div>
                            <?php if (!empty($candidate['description'])): ?>
                            <div class="candidate-desc"><?php echo htmlspecialchars($candidate['description']); ?></div>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td class="vote-count"><?php echo $candidate['vote_count']; ?></td>
                    <td class="vote-pct"><?php echo number_format($vote_pct, 1); ?>%</td>
                    <td>
                        <div class="bar-container">
                            <div class="bar-fill<?php echo $idx === 0 && $max_votes > 0 ? ' leading' : ''; ?>" 
                                 style="width: <?php echo $bar_width; ?>%">
                                <?php if ($bar_width > 15): ?>
                                    <?php echo $candidate['vote_count']; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <div class="empty-message">
        <p>No candidates have been added to this election yet.</p>
    </div>
    <?php endif; ?>

</main>

<script src="script.js"></script>
</body>
</html>
