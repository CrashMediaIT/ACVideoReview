<?php
// ACVideoReview API - Telestration Save (AJAX)
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

// Only coaches can save telestrations
if (!in_array($user_role, COACH_ROLES, true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Permission denied']);
    exit;
}

try {
    $telestration_id = !empty($_POST['telestration_id']) ? (int)$_POST['telestration_id'] : null;
    $clip_id = !empty($_POST['clip_id']) ? (int)$_POST['clip_id'] : null;
    $source_video_id = !empty($_POST['source_video_id']) ? (int)$_POST['source_video_id'] : null;
    $title = sanitizeInput($_POST['title'] ?? '');
    $video_time = (float)($_POST['video_time'] ?? 0);
    $duration = (float)($_POST['duration'] ?? 3.0);
    $canvas_data = $_POST['canvas_data'] ?? '{}';
    $canvas_width = (int)($_POST['canvas_width'] ?? 1920);
    $canvas_height = (int)($_POST['canvas_height'] ?? 1080);

    // Validate canvas_data is valid JSON
    json_decode($canvas_data);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Invalid canvas data format.');
    }

    // Must have either clip_id or source_video_id
    if (!$clip_id && !$source_video_id) {
        throw new Exception('Either clip_id or source_video_id is required.');
    }

    if ($telestration_id) {
        // Update existing telestration
        $stmt = $pdo->prepare(
            "UPDATE vr_telestrations SET
                title = :title, video_time = :vtime, duration = :dur,
                canvas_data = :canvas, canvas_width = :cw, canvas_height = :ch
             WHERE id = :id AND created_by = :uid"
        );
        $stmt->execute([
            ':title'  => $title,
            ':vtime'  => $video_time,
            ':dur'    => $duration,
            ':canvas' => $canvas_data,
            ':cw'     => $canvas_width,
            ':ch'     => $canvas_height,
            ':id'     => $telestration_id,
            ':uid'    => $user_id,
        ]);
        $result_id = $telestration_id;
    } else {
        // Create new telestration
        $stmt = $pdo->prepare(
            "INSERT INTO vr_telestrations
                (clip_id, source_video_id, created_by, title, video_time, duration,
                 canvas_data, canvas_width, canvas_height)
             VALUES (:clip, :source, :uid, :title, :vtime, :dur, :canvas, :cw, :ch)"
        );
        $stmt->execute([
            ':clip'   => $clip_id,
            ':source' => $source_video_id,
            ':uid'    => $user_id,
            ':title'  => $title,
            ':vtime'  => $video_time,
            ':dur'    => $duration,
            ':canvas' => $canvas_data,
            ':cw'     => $canvas_width,
            ':ch'     => $canvas_height,
        ]);
        $result_id = $pdo->lastInsertId();
    }

    echo json_encode(['success' => true, 'telestration_id' => $result_id]);
} catch (Exception $e) {
    error_log('Telestration save error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
