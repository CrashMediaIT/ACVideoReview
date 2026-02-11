<?php
// ACVideoReview API - Video Delete
session_start();

require_once __DIR__ . '/../config/app.php';
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
$video_id = (int)($_POST['video_id'] ?? 0);

if ($video_id <= 0) {
    $_SESSION['error'] = 'Invalid video ID.';
    header('Location: ../dashboard.php?page=film_room');
    exit;
}

// Only coaches/admins can delete
if (!in_array($user_role, COACH_ROLES, true)) {
    $_SESSION['error'] = 'You do not have permission to delete videos.';
    header('Location: ../dashboard.php?page=film_room');
    exit;
}

try {
    // Fetch file path before deleting
    $stmt = $pdo->prepare("SELECT file_path, thumbnail_path FROM vr_video_sources WHERE id = :id");
    $stmt->execute([':id' => $video_id]);
    $video = $stmt->fetch();

    if (!$video) {
        throw new Exception('Video not found.');
    }

    // Delete from database (cascades to clips, tags, etc.)
    $stmt = $pdo->prepare("DELETE FROM vr_video_sources WHERE id = :id");
    $stmt->execute([':id' => $video_id]);

    // Delete physical files
    if ($video['file_path']) {
        $full_path = APP_ROOT . '/' . $video['file_path'];
        if (file_exists($full_path)) {
            unlink($full_path);
        }
    }
    if ($video['thumbnail_path']) {
        $thumb_path = APP_ROOT . '/' . $video['thumbnail_path'];
        if (file_exists($thumb_path)) {
            unlink($thumb_path);
        }
    }

    $_SESSION['success'] = 'Video deleted successfully.';
} catch (Exception $e) {
    $_SESSION['error'] = $e->getMessage();
}

header('Location: ../dashboard.php?page=film_room');
exit;
