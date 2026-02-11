<?php
// ACVideoReview API - Draw Play Save (AJAX)
session_start();

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../db_config.php';
require_once __DIR__ . '/../security.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

if (!DB_CONNECTED || !$pdo) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database unavailable']);
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$user_role = $_SESSION['role'] ?? 'athlete';

if (!in_array($user_role, COACH_ROLES, true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Permission denied']);
    exit;
}

try {
    $game_plan_id = (int)($_POST['game_plan_id'] ?? 0);
    $play_id = !empty($_POST['play_id']) ? (int)$_POST['play_id'] : null;
    $title = sanitizeInput($_POST['title'] ?? 'Untitled Play');
    $description = sanitizeInput($_POST['description'] ?? '');
    $play_type = sanitizeInput($_POST['play_type'] ?? 'offensive');
    $canvas_data = $_POST['canvas_data'] ?? '{}';
    $display_order = (int)($_POST['display_order'] ?? 0);

    if ($game_plan_id <= 0) {
        throw new Exception('Invalid game plan ID.');
    }

    // Validate play type
    $valid_types = ['offensive', 'defensive', 'breakout', 'forecheck', 'power_play', 'penalty_kill', 'faceoff'];
    if (!in_array($play_type, $valid_types, true)) {
        throw new Exception('Invalid play type.');
    }

    // Validate canvas_data is valid JSON
    if (json_decode($canvas_data) === null && $canvas_data !== 'null') {
        throw new Exception('Invalid canvas data format.');
    }

    if ($play_id) {
        // Update existing play
        $stmt = $pdo->prepare(
            "UPDATE vr_draw_plays SET
                title = :title, description = :desc, play_type = :type,
                canvas_data = :canvas, display_order = :ord
             WHERE id = :id AND game_plan_id = :pid"
        );
        $stmt->execute([
            ':title'  => $title,
            ':desc'   => $description,
            ':type'   => $play_type,
            ':canvas' => $canvas_data,
            ':ord'    => $display_order,
            ':id'     => $play_id,
            ':pid'    => $game_plan_id,
        ]);
        $result_id = $play_id;
    } else {
        // Create new play
        $stmt = $pdo->prepare(
            "INSERT INTO vr_draw_plays
                (game_plan_id, title, description, play_type, canvas_data, display_order, created_by)
             VALUES (:pid, :title, :desc, :type, :canvas, :ord, :uid)"
        );
        $stmt->execute([
            ':pid'    => $game_plan_id,
            ':title'  => $title,
            ':desc'   => $description,
            ':type'   => $play_type,
            ':canvas' => $canvas_data,
            ':ord'    => $display_order,
            ':uid'    => $user_id,
        ]);
        $result_id = $pdo->lastInsertId();
    }

    echo json_encode(['success' => true, 'play_id' => $result_id]);
} catch (Exception $e) {
    error_log('Draw play save error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
