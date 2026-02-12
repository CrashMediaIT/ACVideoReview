<?php
// ACVideoReview API - Game Plan Save
require_once __DIR__ . '/../config/app.php';
initSession();

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

$user_id = (int)$_SESSION['user_id'];
$user_role = $_SESSION['role'] ?? 'athlete';

// Only coaches can manage game plans
if (!in_array($user_role, COACH_ROLES, true)) {
    $_SESSION['error'] = 'You do not have permission to manage game plans.';
    header('Location: ../dashboard.php?page=game_plan');
    exit;
}

try {
    $plan_id = !empty($_POST['plan_id']) ? (int)$_POST['plan_id'] : null;
    $plan_type = sanitizeInput($_POST['plan_type'] ?? 'pre_game');
    $title = sanitizeInput($_POST['title'] ?? '');
    $description = sanitizeInput($_POST['description'] ?? '');
    $game_schedule_id = !empty($_POST['game_schedule_id']) ? (int)$_POST['game_schedule_id'] : null;
    $offensive_strategy = $_POST['offensive_strategy'] ?? '';
    $defensive_strategy = $_POST['defensive_strategy'] ?? '';
    $special_teams_notes = sanitizeInput($_POST['special_teams_notes'] ?? '');

    // Use team_id from form submission; fall back to session value
    $team_id = !empty($_POST['team_id']) ? (int)$_POST['team_id'] : (int)($_SESSION['team_id'] ?? 0);

    // Validate plan type
    $valid_types = ['pre_game', 'post_game', 'practice'];
    if (!in_array($plan_type, $valid_types, true)) {
        throw new Exception('Invalid plan type.');
    }

    if (empty($title)) {
        throw new Exception('Plan title is required.');
    }

    if ($plan_id) {
        // Update existing plan
        $stmt = $pdo->prepare(
            "UPDATE vr_game_plans SET
                game_schedule_id = :game_id, plan_type = :type, title = :title,
                description = :desc, offensive_strategy = :offense,
                defensive_strategy = :defense, special_teams_notes = :special
             WHERE id = :id AND team_id = :team_id"
        );
        $stmt->execute([
            ':game_id' => $game_schedule_id,
            ':type'    => $plan_type,
            ':title'   => $title,
            ':desc'    => $description,
            ':offense' => $offensive_strategy,
            ':defense' => $defensive_strategy,
            ':special' => $special_teams_notes,
            ':id'      => $plan_id,
            ':team_id' => $team_id,
        ]);
        $_SESSION['success'] = 'Game plan updated successfully.';
    } else {
        // Create new plan
        $stmt = $pdo->prepare(
            "INSERT INTO vr_game_plans
                (game_schedule_id, team_id, plan_type, title, description,
                 offensive_strategy, defensive_strategy, special_teams_notes, created_by)
             VALUES (:game_id, :team_id, :type, :title, :desc, :offense, :defense, :special, :uid)"
        );
        $stmt->execute([
            ':game_id' => $game_schedule_id,
            ':team_id' => $team_id,
            ':type'    => $plan_type,
            ':title'   => $title,
            ':desc'    => $description,
            ':offense' => $offensive_strategy,
            ':defense' => $defensive_strategy,
            ':special' => $special_teams_notes,
            ':uid'     => $user_id,
        ]);
        $_SESSION['success'] = 'Game plan created successfully.';
    }

    // Save line assignments if provided
    if (isset($_POST['lines']) && is_array($_POST['lines'])) {
        $plan_target_id = $plan_id ?: $pdo->lastInsertId();

        // Clear existing assignments
        $stmt = $pdo->prepare("DELETE FROM vr_line_assignments WHERE game_plan_id = :pid");
        $stmt->execute([':pid' => $plan_target_id]);

        $line_stmt = $pdo->prepare(
            "INSERT INTO vr_line_assignments (game_plan_id, line_type, line_number, position, athlete_id, notes)
             VALUES (:pid, :type, :num, :pos, :ath, :notes)"
        );

        foreach ($_POST['lines'] as $line) {
            $line_stmt->execute([
                ':pid'   => $plan_target_id,
                ':type'  => sanitizeInput($line['line_type'] ?? 'forward'),
                ':num'   => (int)($line['line_number'] ?? 1),
                ':pos'   => sanitizeInput($line['position'] ?? ''),
                ':ath'   => (int)($line['athlete_id'] ?? 0),
                ':notes' => sanitizeInput($line['notes'] ?? ''),
            ]);
        }
    }
} catch (Exception $e) {
    $_SESSION['error'] = $e->getMessage();
}

header('Location: ../dashboard.php?page=game_plan');
exit;
