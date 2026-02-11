<?php
// ACVideoReview - Entry Point / Login Check
session_start();

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/db_config.php';

// If user is logged in, go to dashboard
if (isset($_SESSION['user_id']) && isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
    header('Location: dashboard.php');
    exit;
}

// Not logged in — redirect to main app login with return URL
header('Location: ' . MAIN_APP_URL . '/login.php?redirect=' . urlencode(APP_URL));
exit;
