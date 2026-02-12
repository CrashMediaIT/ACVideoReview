<?php
// ACVideoReview API - Video Upload
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
    $_SESSION['error'] = 'Invalid security token. Please try again.';
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
$team_id = (int)($_SESSION['team_id'] ?? 0);

// Only coaches can upload
if (!in_array($user_role, COACH_ROLES, true)) {
    $_SESSION['error'] = 'You do not have permission to upload videos.';
    header('Location: ../dashboard.php?page=film_room');
    exit;
}

try {
    // Validate file upload
    if (!isset($_FILES['video_file']) || $_FILES['video_file']['error'] !== UPLOAD_ERR_OK) {
        $upload_errors = [
            UPLOAD_ERR_INI_SIZE   => 'File exceeds server upload limit.',
            UPLOAD_ERR_FORM_SIZE  => 'File exceeds form upload limit.',
            UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded.',
            UPLOAD_ERR_NO_FILE    => 'No file was uploaded.',
            UPLOAD_ERR_NO_TMP_DIR => 'Server temporary directory missing.',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
            UPLOAD_ERR_EXTENSION  => 'File upload stopped by extension.',
        ];
        $err_code = $_FILES['video_file']['error'] ?? UPLOAD_ERR_NO_FILE;
        throw new Exception($upload_errors[$err_code] ?? 'Unknown upload error.');
    }

    $file = $_FILES['video_file'];
    $original_name = basename($file['name']);
    $file_size = $file['size'];

    // Validate file size
    if ($file_size > MAX_VIDEO_SIZE) {
        throw new Exception('File exceeds the maximum allowed size of 2GB.');
    }

    // Validate file extension
    $ext = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
    if (!in_array($ext, ALLOWED_VIDEO_FORMATS, true)) {
        throw new Exception('Invalid video format. Allowed: ' . implode(', ', ALLOWED_VIDEO_FORMATS));
    }

    // Sanitize form fields
    $title = sanitizeInput($_POST['title'] ?? $original_name);
    $description = sanitizeInput($_POST['description'] ?? '');
    $camera_angle = sanitizeInput($_POST['camera_angle'] ?? 'wide');
    $game_schedule_id = !empty($_POST['game_schedule_id']) ? (int)$_POST['game_schedule_id'] : null;
    $recorded_at = !empty($_POST['recorded_at']) ? sanitizeInput($_POST['recorded_at']) : null;

    // Determine source type (upload, recording, or ndi)
    $source_type = 'upload';
    $ndi_camera_id = null;
    if (!empty($_POST['source_type']) && in_array($_POST['source_type'], ['upload', 'recording', 'stream', 'ndi'], true)) {
        $source_type = $_POST['source_type'];
    }
    if ($source_type === 'ndi' && !empty($_POST['ndi_camera_id'])) {
        $ndi_camera_id = (int)$_POST['ndi_camera_id'];
    }

    // Generate unique filename
    $safe_name = preg_replace('/[^a-zA-Z0-9_-]/', '_', pathinfo($original_name, PATHINFO_FILENAME));
    $unique_name = $safe_name . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;

    // Move uploaded file
    $dest_path = VIDEO_DIR . $unique_name;
    if (!move_uploaded_file($file['tmp_name'], $dest_path)) {
        throw new Exception('Failed to save uploaded file.');
    }

    $relative_path = 'uploads/videos/' . $unique_name;

    // Insert into database
    $stmt = $pdo->prepare(
        "INSERT INTO vr_video_sources
            (game_schedule_id, team_id, title, description, source_type, camera_angle,
             ndi_camera_id, file_path, file_size, format, uploaded_by, recorded_at, status)
         VALUES (:game_id, :team_id, :title, :desc, :source_type, :angle,
                 :ndi_cam_id, :path, :size, :format, :uid, :recorded, 'ready')"
    );
    $stmt->execute([
        ':game_id'     => $game_schedule_id,
        ':team_id'     => $team_id,
        ':title'       => $title,
        ':desc'        => $description,
        ':source_type' => $source_type,
        ':angle'       => $camera_angle,
        ':ndi_cam_id'  => $ndi_camera_id,
        ':path'        => $relative_path,
        ':size'        => $file_size,
        ':format'      => $ext,
        ':uid'         => $user_id,
        ':recorded'    => $recorded_at,
    ]);

    $_SESSION['success'] = 'Video uploaded successfully.';
} catch (Exception $e) {
    $_SESSION['error'] = $e->getMessage();
}

header('Location: ../dashboard.php?page=film_room');
exit;
