<?php
// ACVideoReview API - Clip Save
require_once __DIR__ . '/../config/app.php';
initSession();

require_once __DIR__ . '/../db_config.php';
require_once __DIR__ . '/../security.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../dashboard.php?page=film_room');
    exit;
}

requireAuth();

if (!checkCsrfToken()) {
    $_SESSION['error'] = 'Invalid security token.';
    header('Location: ../dashboard.php?page=film_room');
    exit;
}

if (!DB_CONNECTED || !$pdo) {
    $_SESSION['error'] = 'Database unavailable.';
    header('Location: ../dashboard.php?page=film_room');
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$user_role = $_SESSION['role'] ?? 'athlete';

// Only coaches can create clips
if (!in_array($user_role, COACH_ROLES, true)) {
    $_SESSION['error'] = 'You do not have permission to create clips.';
    header('Location: ../dashboard.php?page=film_room');
    exit;
}

try {
    $source_video_id = (int)($_POST['source_video_id'] ?? 0);
    $title = sanitizeInput($_POST['title'] ?? '');
    $description = sanitizeInput($_POST['description'] ?? '');
    $start_time = (float)($_POST['start_time'] ?? 0);
    $end_time = (float)($_POST['end_time'] ?? 0);
    $game_schedule_id = !empty($_POST['game_schedule_id']) ? (int)$_POST['game_schedule_id'] : null;

    if ($source_video_id <= 0) {
        throw new Exception('Invalid source video.');
    }
    if (empty($title)) {
        throw new Exception('Clip title is required.');
    }
    if ($end_time <= $start_time) {
        throw new Exception('End time must be after start time.');
    }

    // Verify source video exists
    $stmt = $pdo->prepare("SELECT id FROM vr_video_sources WHERE id = :id");
    $stmt->execute([':id' => $source_video_id]);
    if (!$stmt->fetch()) {
        throw new Exception('Source video not found.');
    }

    $stmt = $pdo->prepare(
        "INSERT INTO vr_video_clips
            (source_video_id, game_schedule_id, title, description, start_time, end_time, created_by)
         VALUES (:source, :game_id, :title, :desc, :start, :end, :uid)"
    );
    $stmt->execute([
        ':source'  => $source_video_id,
        ':game_id' => $game_schedule_id,
        ':title'   => $title,
        ':desc'    => $description,
        ':start'   => $start_time,
        ':end'     => $end_time,
        ':uid'     => $user_id,
    ]);

    $clip_id = $pdo->lastInsertId();

    // Save tags if provided
    $tags = $_POST['tags'] ?? [];
    if (is_array($tags) && !empty($tags)) {
        $tag_stmt = $pdo->prepare(
            "INSERT INTO vr_clip_tags (clip_id, tag_id, created_by) VALUES (:clip, :tag, :uid)"
        );
        foreach ($tags as $tag_id) {
            $tag_id = (int)$tag_id;
            if ($tag_id > 0) {
                $tag_stmt->execute([':clip' => $clip_id, ':tag' => $tag_id, ':uid' => $user_id]);
            }
        }
    }

    // Save athlete associations if provided
    $athletes = $_POST['athletes'] ?? [];
    if (is_array($athletes) && !empty($athletes)) {
        $ath_stmt = $pdo->prepare(
            "INSERT INTO vr_clip_athletes (clip_id, athlete_id, role_in_clip) VALUES (:clip, :ath, :role)"
        );
        foreach ($athletes as $athlete) {
            $ath_id = (int)($athlete['id'] ?? 0);
            $ath_role = sanitizeInput($athlete['role'] ?? 'primary');
            if ($ath_id > 0) {
                $ath_stmt->execute([':clip' => $clip_id, ':ath' => $ath_id, ':role' => $ath_role]);
            }
        }
    }

    $_SESSION['success'] = 'Clip saved successfully.';
} catch (Exception $e) {
    $_SESSION['error'] = $e->getMessage();
}

header('Location: ../dashboard.php?page=film_room');
exit;
