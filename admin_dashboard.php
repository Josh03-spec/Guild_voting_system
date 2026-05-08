<?php
/*
 * admin_dashboard.php
 * --------------------
 * Main landing page for logged-in admins.
 * Shows: summary stats, quick actions, recent activity snapshot.
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

$admin_name = $_SESSION['full_name'];

// -----------------------------------------------------------------------
// Fetch summary statistics — all in separate simple queries
// -----------------------------------------------------------------------

// Total registered students (role = student)
$r = mysqli_query($conn, "SELECT COUNT(*) AS total FROM users WHERE role = 'student'");
$total_students = mysqli_fetch_assoc($r)['total'];

// Active students
$r = mysqli_query($conn, "SELECT COUNT(*) AS total FROM users WHERE role = 'student' AND is_active = 1");
$active_students = mysqli_fetch_assoc($r)['total'];

// Disabled students
$disabled_students = $total_students - $active_students;

// Total eligible students not yet registered
$r = mysqli_query($conn, "SELECT COUNT(*) AS total FROM eligible_students WHERE is_claimed = 0");
$unclaimed = mysqli_fetch_assoc($r)['total'];

// Elections by status
$r = mysqli_query($conn, "SELECT status, COUNT(*) AS total FROM elections GROUP BY status");
$election_counts = ['open' => 0, 'pending' => 0, 'closed' => 0];
while ($row = mysqli_fetch_assoc($r)) {
    $election_counts[$row['status']] = $row['total'];
}

// Total votes cast across all elections
$r = mysqli_query($conn, "SELECT COUNT(*) AS total FROM votes");
$total_votes = mysqli_fetch_assoc($r)['total'];

// -----------------------------------------------------------------------
// Fetch open elections with live vote counts for the quick overview table
// -----------------------------------------------------------------------
$r = mysqli_query($conn,
    "SELECT
        e.id,
        e.title,
        COUNT(DISTINCT v.id)  AS votes_cast,
        COUNT(DISTINCT c.id)  AS candidate_count
     FROM elections e
     LEFT JOIN votes      v ON v.election_id = e.id
     LEFT JOIN candidates c ON c.election_id = e.id
     WHERE e.status = 'open'
     GROUP BY e.id, e.title
     ORDER BY e.created_at DESC"
);
$open_elections = [];
while ($row = mysqli_fetch_assoc($r)) {
    $open_elections[] = $row;
}

// -----------------------------------------------------------------------
// Fetch 5 most recent votes for activity feed
// -----------------------------------------------------------------------
$r = mysqli_query($conn,
    "SELECT
        u.full_name   AS student_name,
        e.title       AS election_title,
        c.name        AS candidate_name,
        v.voted_at
     FROM votes v
     JOIN users      u ON u.id = v.student_id
     JOIN elections  e ON e.id = v.election_id
     JOIN candidates c ON c.id = v.candidate_id
     ORDER BY v.voted_at DESC
     LIMIT 5"
);
$recent_votes = [];
while ($row = mysqli_fetch_assoc($r)) {
    $recent_votes[] = $row;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PollPoint &mdash; Admin Dashboard</title>
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

        .topbar-left {
            display: flex;
            align-items: center;
            gap: 32px;
        }

        .topbar-logo {
            font-family: var(--font-serif);
            font-size: 1.3rem;
            font-weight: 900;
        }

        .topbar-logo span { color: var(--accent); }

        /* Admin nav links in topbar */
        .topbar-nav {
            display: flex;
            gap: 4px;
        }

        .topbar-nav a {
            padding: 6px 14px;
            border-radius: 7px;
            text-decoration: none;
            font-size: 0.85rem;
            color: var(--text-muted);
            transition: background var(--transition-base), color var(--transition-base);
        }

        .topbar-nav a:hover,
        .topbar-nav a.active {
            background: var(--surface-elevated);
            color: var(--text-primary);
        }

        .topbar-right {
            display: flex;
            align-items: center;
            gap: 16px;
            font-size: 0.875rem;
        }

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

        .topbar-name { color: var(--text-muted); font-size: 0.85rem; }
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
            max-width: 1100px;
            margin: 0 auto;
            padding: 36px 24px 60px;
        }

        .page-header {
            margin-bottom: 32px;
        }

        .page-header h1 {
            font-family: var(--font-serif);
            font-size: 1.7rem;
            margin-bottom: 4px;
        }

        .page-header p { color: var(--text-muted); font-size: 0.92rem; }

        /* ===================== STAT CARDS ===================== */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
            gap: 14px;
            margin-bottom: 36px;
        }

        .stat-card {
            background: var(--surface);
            border: 1px solid var(--card-border);
            border-radius: 12px;
            padding: 20px 18px;
        }

        .stat-label {
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.07em;
            color: var(--text-muted);
            margin-bottom: 10px;
        }

        .stat-number {
            font-family: var(--font-serif);
            font-size: 2.2rem;
            font-weight: 900;
            color: var(--text-primary);
            line-height: 1;
            margin-bottom: 4px;
        }

        .stat-sub {
            font-size: 0.78rem;
            color: var(--text-muted);
        }

        .stat-card.accent  { border-color: rgba(232,168,56,0.3); }
        .stat-card.success { border-color: rgba(34,197,94,0.25); }
        .stat-card.danger  { border-color: rgba(248,81,73,0.2); }

        .stat-card.accent  .stat-number { color: var(--accent); }
        .stat-card.success .stat-number { color: var(--success); }
        .stat-card.danger  .stat-number { color: var(--danger); }

        /* ===================== TWO COLUMN LAYOUT ===================== */
        .two-col {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 28px;
        }

        /* ===================== SECTION CARDS ===================== */
        .section-card {
            background: var(--surface);
            border: 1px solid var(--card-border);
            border-radius: 12px;
            overflow: hidden;
        }

        .section-card-header {
            padding: 18px 22px;
            border-bottom: 1px solid var(--border-subtle);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .section-card-header h2 {
            font-family: var(--font-serif);
            font-size: 1rem;
        }

        .section-card-header a {
            font-size: 0.8rem;
            color: var(--accent);
            text-decoration: none;
            transition: color var(--transition-base);
        }

        .section-card-header a:hover { color: var(--accent-hover); }

        /* ===================== OPEN ELECTIONS TABLE ===================== */
        .elections-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.875rem;
        }

        .elections-table th {
            padding: 12px 22px;
            text-align: left;
            font-size: 0.72rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.07em;
            color: var(--text-muted);
            background: var(--table-header-bg);
            border-bottom: 1px solid var(--border-subtle);
        }

        .elections-table td {
            padding: 14px 22px;
            border-bottom: 1px solid var(--border-subtle);
            vertical-align: middle;
        }

        .elections-table tr:last-child td { border-bottom: none; }

        .elections-table tr:hover td { background: var(--table-row-hover); }

        .election-link {
            color: var(--text-primary);
            text-decoration: none;
            font-weight: 600;
            transition: color var(--transition-base);
        }

        .election-link:hover { color: var(--accent); }

        /* Mini bar for vote count */
        .mini-bar-wrap {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .mini-bar-track {
            flex: 1;
            height: 6px;
            background: var(--bg-page);
            border-radius: 3px;
            overflow: hidden;
            min-width: 60px;
        }

        .mini-bar-fill {
            height: 100%;
            background: var(--accent);
            border-radius: 3px;
        }

        .mini-bar-num {
            font-size: 0.8rem;
            color: var(--text-muted);
            white-space: nowrap;
        }

        .empty-row td {
            text-align: center;
            color: var(--text-muted);
            padding: 28px;
            font-size: 0.875rem;
        }

        /* ===================== ACTIVITY FEED ===================== */
        .activity-list {
            padding: 8px 0;
        }

        .activity-item {
            display: flex;
            align-items: flex-start;
            gap: 14px;
            padding: 14px 22px;
            border-bottom: 1px solid var(--border-subtle);
            transition: background var(--transition-fast);
        }

        .activity-item:last-child { border-bottom: none; }
        .activity-item:hover { background: var(--surface-elevated); }

        .activity-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: var(--accent);
            flex-shrink: 0;
            margin-top: 5px;
        }

        .activity-text {
            flex: 1;
            font-size: 0.85rem;
            line-height: 1.5;
            color: var(--text-muted);
        }

        .activity-text strong { color: var(--text-primary); }

        .activity-time {
            font-size: 0.75rem;
            color: var(--text-muted);
            white-space: nowrap;
            margin-top: 2px;
        }

        .no-activity {
            text-align: center;
            padding: 28px;
            color: var(--text-muted);
            font-size: 0.875rem;
        }

        /* ===================== QUICK ACTIONS ===================== */
        .quick-actions {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 12px;
            margin-bottom: 28px;
        }

        .quick-btn {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 16px 18px;
            background: var(--surface);
            border: 1px solid var(--card-border);
            border-radius: 10px;
            text-decoration: none;
            color: var(--text-primary);
            font-size: 0.875rem;
            font-weight: 600;
            transition: border-color var(--transition-base), background var(--transition-base), transform 0.15s;
        }

        .quick-btn:hover {
            border-color: var(--accent);
            background: var(--surface-elevated);
            transform: translateY(-2px);
        }

        .quick-btn-icon {
            font-size: 1.3rem;
            flex-shrink: 0;
        }

        .quick-btn-sub {
            font-size: 0.75rem;
            color: var(--text-muted);
            font-weight: 400;
            margin-top: 2px;
        }

        @media (max-width: 800px) {
            .two-col { grid-template-columns: 1fr; }
            .topbar-nav { display: none; }
            .topbar { padding: 0 16px; }
            .main   { padding: 24px 16px 48px; }
        }

        @media (max-width: 500px) {
            .topbar-name { display: none; }
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
            <a href="admin_dashboard.php" class="active">Dashboard</a>
            <a href="admin_users.php">Users</a>
            <a href="admin_elections.php">Elections</a>
            <a href="admin_eligible.php">Eligible Students</a>
        </div>
    </div>
    <div class="topbar-right">
        <span class="admin-badge">Admin</span>
        <span class="topbar-name">
            <strong><?php echo htmlspecialchars($admin_name); ?></strong>
        </span>
        <button id="theme-toggle-btn" class="theme-toggle-btn" onclick="toggleTheme()">🌙 Dark Mode</button>
        <a href="logout.php" class="btn-logout">Sign Out</a>
    </div>
</nav>

<main class="main">

    <div class="page-header">
        <h1>Dashboard</h1>
        <p>Welcome back, <?php echo htmlspecialchars(explode(' ', $admin_name)[0]); ?>. Here is a live overview of PollPoint.</p>
    </div>

    <!-- ================= STAT CARDS ================= -->
    <div class="stats-grid">

        <div class="stat-card accent">
            <div class="stat-label">Total Students</div>
            <div class="stat-number"><?php echo $total_students; ?></div>
            <div class="stat-sub"><?php echo $unclaimed; ?> not yet registered</div>
        </div>

        <div class="stat-card success">
            <div class="stat-label">Active Students</div>
            <div class="stat-number"><?php echo $active_students; ?></div>
            <div class="stat-sub">Eligible to vote</div>
        </div>

        <div class="stat-card danger">
            <div class="stat-label">Disabled Accounts</div>
            <div class="stat-number"><?php echo $disabled_students; ?></div>
            <div class="stat-sub">Cannot log in or vote</div>
        </div>

        <div class="stat-card">
            <div class="stat-label">Open Elections</div>
            <div class="stat-number"><?php echo $election_counts['open']; ?></div>
            <div class="stat-sub">Voting in progress</div>
        </div>

        <div class="stat-card">
            <div class="stat-label">Pending Elections</div>
            <div class="stat-number"><?php echo $election_counts['pending']; ?></div>
            <div class="stat-sub">Not yet opened</div>
        </div>

        <div class="stat-card">
            <div class="stat-label">Total Votes Cast</div>
            <div class="stat-number"><?php echo $total_votes; ?></div>
            <div class="stat-sub">Across all elections</div>
        </div>

    </div>

    <!-- ================= QUICK ACTIONS ================= -->
    <div class="quick-actions">
        <a href="admin_elections.php?action=create" class="quick-btn">
            <span class="quick-btn-icon">&#43;&#9711;</span>
            <div>
                <div>New Election</div>
                <div class="quick-btn-sub">Create and configure</div>
            </div>
        </a>
        <a href="admin_users.php" class="quick-btn">
            <span class="quick-btn-icon">&#128101;</span>
            <div>
                <div>Manage Users</div>
                <div class="quick-btn-sub">Search, edit, disable</div>
            </div>
        </a>
        <a href="admin_eligible.php" class="quick-btn">
            <span class="quick-btn-icon">&#128203;</span>
            <div>
                <div>Eligible Students</div>
                <div class="quick-btn-sub">Add pre-verified records</div>
            </div>
        </a>
        <a href="admin_elections.php" class="quick-btn">
            <span class="quick-btn-icon">&#128202;</span>
            <div>
                <div>All Elections</div>
                <div class="quick-btn-sub">View, edit, open, close</div>
            </div>
        </a>
    </div>

    <!-- ================= TWO COLUMN: OPEN ELECTIONS + ACTIVITY ================= -->
    <div class="two-col">

        <!-- Open elections live overview -->
        <div class="section-card">
            <div class="section-card-header">
                <h2>Open Elections</h2>
                <a href="admin_elections.php">View all &rarr;</a>
            </div>
            <table class="elections-table">
                <thead>
                    <tr>
                        <th>Election</th>
                        <th>Candidates</th>
                        <th>Votes</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($open_elections)): ?>
                    <tr class="empty-row">
                        <td colspan="3">No elections are currently open.</td>
                    </tr>
                <?php else: ?>
                    <?php
                    // Find max votes among open elections for mini bar scaling
                    $max_open_votes = max(array_column($open_elections, 'votes_cast')) ?: 1;
                    foreach ($open_elections as $e):
                        $bar_pct = round(($e['votes_cast'] / $max_open_votes) * 100);
                    ?>
                    <tr>
                        <td>
                            <a href="admin_election_edit.php?election_id=<?php echo $e['id']; ?>"
                               class="election-link">
                                <?php echo htmlspecialchars($e['title']); ?>
                            </a>
                        </td>
                        <td style="color:var(--text-muted)">
                            <?php echo $e['candidate_count']; ?>
                        </td>
                        <td>
                            <div class="mini-bar-wrap">
                                <div class="mini-bar-track">
                                    <div class="mini-bar-fill" style="width:<?php echo $bar_pct; ?>%"></div>
                                </div>
                                <span class="mini-bar-num"><?php echo $e['votes_cast']; ?></span>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Recent activity feed -->
        <div class="section-card">
            <div class="section-card-header">
                <h2>Recent Activity</h2>
                <span style="font-size:0.75rem;color:var(--text-muted)">Last 5 votes</span>
            </div>
            <div class="activity-list">
                <?php if (empty($recent_votes)): ?>
                    <div class="no-activity">No votes have been cast yet.</div>
                <?php else: ?>
                    <?php foreach ($recent_votes as $v): ?>
                    <div class="activity-item">
                        <div class="activity-dot"></div>
                        <div class="activity-text">
                            <strong><?php echo htmlspecialchars($v['student_name']); ?></strong>
                            voted in
                            <strong><?php echo htmlspecialchars($v['election_title']); ?></strong>
                        </div>
                        <div class="activity-time">
                            <?php echo date('d M, H:i', strtotime($v['voted_at'])); ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

    </div>

</main>

</body>
</html>