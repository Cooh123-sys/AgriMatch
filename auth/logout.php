<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Clear all session variables
$_SESSION = [];

// Destroy the session cookie itself (good practice, avoids stale cookie issues)
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'],
        $params['domain'],
        $params['secure'],
        $params['httponly']
    );
}

// Destroy the session data on the server
session_destroy();

// Start a fresh session just to carry the flash message to the login page
session_start();
$_SESSION['flash'] = [
    'type' => 'success',
    'msg'  => 'You have been logged out successfully.'
];

header('Location: /AgriMatch/auth/login.php');
exit;