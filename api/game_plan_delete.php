<?php
// ACVideoReview API - Game Plan Delete
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
    $_SESSION['error'] = 'You do not have permission to delete game plans.';
    header('Location: ../dashboard.php?page=game_plan');
    exit;
}

try {
    $plan_id = (int)($_POST['plan_id'] ?? 0);

    if ($plan_id <= 0) {
        throw new Exception('Invalid plan ID.');
    }

    // Cascading delete handles line_assignments and draw_plays
    $stmt = $pdo->prepare("DELETE FROM vr_game_plans WHERE id = :id");
    $stmt->execute([':id' => $plan_id]);

    if ($stmt->rowCount() === 0) {
        throw new Exception('Game plan not found.');
    }

    $_SESSION['success'] = 'Game plan deleted successfully.';
} catch (Exception $e) {
    $_SESSION['error'] = $e->getMessage();
}

header('Location: ../dashboard.php?page=game_plan');
exit;
