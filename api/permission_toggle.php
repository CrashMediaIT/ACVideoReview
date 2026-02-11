<?php
/**
 * API: Toggle Video Permission
 * POST endpoint for AJAX permission checkbox toggles
 * Called from views/permissions.php
 */
session_start();

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../db_config.php';
require_once __DIR__ . '/../security.php';

header('Content-Type: application/json');

// Must be authenticated
if (!isset($_SESSION['user_id']) || !isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

// Must be admin
$user_role = $_SESSION['role'] ?? '';
if (!in_array($user_role, ADMIN_ROLES, true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Admin access required']);
    exit;
}

// Must be POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// CSRF check
if (!checkCsrfToken()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

// Validate inputs
$target_user_id = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;
$team_id = isset($_POST['team_id']) ? (int)$_POST['team_id'] : 0;
$permission = isset($_POST['permission']) ? sanitizeInput($_POST['permission']) : '';
$value = isset($_POST['value']) ? (int)$_POST['value'] : 0;

$allowedPermissions = ['can_upload', 'can_clip', 'can_tag', 'can_publish', 'can_delete'];

if (!$target_user_id || !$team_id || !$permission) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing required fields']);
    exit;
}

if (!in_array($permission, $allowedPermissions, true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid permission field']);
    exit;
}

$value = $value ? 1 : 0;

if (!defined('DB_CONNECTED') || !DB_CONNECTED || !$pdo) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database not available']);
    exit;
}

try {
    // Check if permission record exists
    $stmt = dbQuery($pdo,
        "SELECT id FROM vr_video_permissions WHERE user_id = :uid AND team_id = :tid",
        [':uid' => $target_user_id, ':tid' => $team_id]
    );
    $existing = $stmt->fetch();

    if ($existing) {
        // Update existing permission
        $sql = "UPDATE vr_video_permissions SET `$permission` = :val, updated_at = NOW() WHERE user_id = :uid AND team_id = :tid";
        dbQuery($pdo, $sql, [':val' => $value, ':uid' => $target_user_id, ':tid' => $team_id]);
    } else {
        // Insert new permission record with all defaults
        $defaults = [
            'can_upload' => 0,
            'can_clip' => 0,
            'can_tag' => 0,
            'can_publish' => 0,
            'can_delete' => 0,
        ];
        $defaults[$permission] = $value;

        $sql = "INSERT INTO vr_video_permissions (user_id, team_id, can_upload, can_clip, can_tag, can_publish, can_delete, granted_by, created_at, updated_at)
                VALUES (:uid, :tid, :can_upload, :can_clip, :can_tag, :can_publish, :can_delete, :granted_by, NOW(), NOW())";
        dbQuery($pdo, $sql, [
            ':uid' => $target_user_id,
            ':tid' => $team_id,
            ':can_upload' => $defaults['can_upload'],
            ':can_clip' => $defaults['can_clip'],
            ':can_tag' => $defaults['can_tag'],
            ':can_publish' => $defaults['can_publish'],
            ':can_delete' => $defaults['can_delete'],
            ':granted_by' => $_SESSION['user_id'],
        ]);
    }

    echo json_encode(['success' => true, 'permission' => $permission, 'value' => $value]);
} catch (PDOException $e) {
    error_log('Permission toggle error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error']);
}
