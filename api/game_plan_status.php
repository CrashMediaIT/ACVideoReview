<?php
// ACVideoReview API - Game Plan Status Update (publish / archive)
session_start();

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../db_config.php';
require_once __DIR__ . '/../security.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../dashboard.php?page=game_plan');
    exit;
}

requireAuth();

if (!checkCsrfToken()) {
    $_SESSION['error'] = 'Invalid security token.';
    header('Location: ../dashboard.php?page=game_plan');
    exit;
}

if (!DB_CONNECTED || !$pdo) {
    $_SESSION['error'] = 'Database unavailable.';
    header('Location: ../dashboard.php?page=game_plan');
    exit;
}

$user_role = $_SESSION['role'] ?? 'athlete';

if (!in_array($user_role, COACH_ROLES, true)) {
    $_SESSION['error'] = 'You do not have permission to update game plans.';
    header('Location: ../dashboard.php?page=game_plan');
    exit;
}

try {
    $plan_id = (int)($_POST['plan_id'] ?? 0);
    $new_status = sanitizeInput($_POST['status'] ?? '');

    $valid_statuses = ['draft', 'published', 'archived'];
    if (!in_array($new_status, $valid_statuses, true)) {
        throw new Exception('Invalid status.');
    }

    if ($plan_id <= 0) {
        throw new Exception('Invalid plan ID.');
    }

    $updates = ['status = :status'];
    $params = [':status' => $new_status, ':id' => $plan_id];

    // Set published_at when publishing
    if ($new_status === 'published') {
        $updates[] = 'published_at = NOW()';
    }

    $stmt = $pdo->prepare("UPDATE vr_game_plans SET " . implode(', ', $updates) . " WHERE id = :id");
    $stmt->execute($params);

    $_SESSION['success'] = 'Game plan status updated to ' . $new_status . '.';
} catch (Exception $e) {
    $_SESSION['error'] = $e->getMessage();
}

header('Location: ../dashboard.php?page=game_plan');
exit;
