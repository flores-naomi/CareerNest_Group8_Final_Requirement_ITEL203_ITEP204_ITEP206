<?php
session_start();

// Function to check if user is logged in
function isLoggedIn() {
    return isset($_SESSION['user_id']) || isset($_SESSION['company_id']);
}

// Function to check user role
function getUserRole() {
    return $_SESSION['role'] ?? null;
}

// Function to redirect based on role
function redirectBasedOnRole() {
    if (!isLoggedIn()) {
        header('Location: login.php');
        exit();
    }

    switch (getUserRole()) {
        case 'admin':
            header('Location: admin_dashboard.php');
            break;
        case 'company':
            header('Location: company_dashboard.php');
            break;
        case 'user':
            header('Location: user_dashboard.php');
            break;
        default:
            header('Location: login.php');
    }
    exit();
}

// Function to generate CSRF token
function generateCSRFToken() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

// Function to verify CSRF token
function verifyCSRFToken($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
} 