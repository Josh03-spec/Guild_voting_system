<?php
/*
 * logout.php
 * -----------
 * Destroys the session completely and redirects to index.php.
 * No HTML needed — this is pure logic.
 */

session_start();

// Remove all session variables
$_SESSION = [];

// Destroy the session cookie if it exists
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Destroy the session on the server
session_destroy();

// Send back to the login/landing page
header("Location: index.php");
exit();
?>