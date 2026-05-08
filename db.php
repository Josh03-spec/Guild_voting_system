<?php
/*
 * db.php
 * -------
 * Single database connection file.
 * Every other PHP file in this project includes this file at the top.
 * Usage:  require_once 'db.php';
 *         Then use $conn for all queries.
 */


// Database credentials 

define('DB_HOST', 'localhost');
define('DB_USER', 'joshua');        
define('DB_PASS', '');            
define('DB_NAME', 'voting_system');
// ---------------------------------------------------------------------------

// Create the connection using MySQLi
$conn = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);

// Stop everything if the connection fails — nothing else can work without it
if (!$conn) {
    die("Database connection failed: " . mysqli_connect_error());
}


mysqli_set_charset($conn, 'utf8mb4');

?>
