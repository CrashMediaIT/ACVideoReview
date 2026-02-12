<?php
// ACVideoReview API - Clip Delete
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
$clip_id = (int)($_POST['clip_id'] ?? 0);

if ($clip_id <= 0) {
    $_SESSION['error'] = 'Invalid clip ID.';
    header('Location: ../dashboard.php?page=film_room');
    exit;
}

if (!in_array($user_role, COACH_ROLES, true)) {
    $_SESSION['error'] = 'You do not have permission to delete clips.';
    header('Location: ../dashboard.php?page=film_room');
    exit;
}

try {
    // Get clip file paths for cleanup
    $stmt = $pdo->prepare("SELECT clip_file_path, thumbnail_path FROM vr_video_clips WHERE id = :id");
    $stmt->execute([':id' => $clip_id]);
    $clip = $stmt->fetch();

    if (!$clip) {
        throw new Exception('Clip not found.');
    }

    // Delete from database (cascades to tags, athletes)
    $stmt = $pdo->prepare("DELETE FROM vr_video_clips WHERE id = :id");
    $stmt->execute([':id' => $clip_id]);

    // Delete physical files if they exist
    if ($clip['clip_file_path']) {
        $full_path = APP_ROOT . '/' . $clip['clip_file_path'];
        if (file_exists($full_path)) {
            unlink($full_path);
        }
    }
    if ($clip['thumbnail_path']) {
        $thumb_path = APP_ROOT . '/' . $clip['thumbnail_path'];
        if (file_exists($thumb_path)) {
            unlink($thumb_path);
        }
    }

    $_SESSION['success'] = 'Clip deleted successfully.';
} catch (Exception $e) {
    $_SESSION['error'] = $e->getMessage();
}

header('Location: ../dashboard.php?page=film_room');
exit;
