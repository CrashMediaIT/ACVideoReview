<?php
// ACVideoReview API - Review Session Delete
require_once __DIR__ . '/../config/app.php';
initSession();

require_once __DIR__ . '/../db_config.php';
require_once __DIR__ . '/../security.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../dashboard.php?page=review_sessions');
    exit;
}

requireAuth();

if (!checkCsrfToken()) {
    $_SESSION['error'] = 'Invalid security token.';
    header('Location: ../dashboard.php?page=review_sessions');
    exit;
}

if (!DB_CONNECTED || !$pdo) {
    $_SESSION['error'] = 'Database unavailable.';
    header('Location: ../dashboard.php?page=review_sessions');
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$user_role = $_SESSION['role'] ?? 'athlete';
$session_id = (int)($_POST['session_id'] ?? 0);

if ($session_id <= 0) {
    $_SESSION['error'] = 'Invalid session ID.';
    header('Location: ../dashboard.php?page=review_sessions');
    exit;
}

if (!in_array($user_role, COACH_ROLES, true)) {
    $_SESSION['error'] = 'You do not have permission to delete review sessions.';
    header('Location: ../dashboard.php?page=review_sessions');
    exit;
}

try {
    // Verify session exists and user owns it
    $stmt = $pdo->prepare("SELECT id FROM vr_review_sessions WHERE id = :id AND created_by = :uid");
    $stmt->execute([':id' => $session_id, ':uid' => $user_id]);
    if (!$stmt->fetch()) {
        throw new Exception('Review session not found.');
    }

    // Delete from database (cascades to session clips)
    $stmt = $pdo->prepare("DELETE FROM vr_review_sessions WHERE id = :id AND created_by = :uid");
    $stmt->execute([':id' => $session_id, ':uid' => $user_id]);

    $_SESSION['success'] = 'Review session deleted successfully.';
} catch (Exception $e) {
    $_SESSION['error'] = $e->getMessage();
}

header('Location: ../dashboard.php?page=review_sessions');
exit;
