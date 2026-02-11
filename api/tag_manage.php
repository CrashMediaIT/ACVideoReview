<?php
// ACVideoReview API - Tag Management (AJAX)
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

// Only coaches can manage tags
if (!in_array($user_role, COACH_ROLES, true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Permission denied']);
    exit;
}

$action = sanitizeInput($_POST['action'] ?? '');

try {
    switch ($action) {
        case 'create':
            $name = sanitizeInput($_POST['name'] ?? '');
            $category = sanitizeInput($_POST['category'] ?? 'custom');
            $color = sanitizeInput($_POST['color'] ?? '#6B46C1');
            $icon = sanitizeInput($_POST['icon'] ?? '');
            $description = sanitizeInput($_POST['description'] ?? '');

            if (empty($name)) {
                throw new Exception('Tag name is required.');
            }

            // Validate category
            $valid_categories = ['zone', 'skill', 'situation', 'custom'];
            if (!in_array($category, $valid_categories, true)) {
                throw new Exception('Invalid tag category.');
            }

            // Validate color format
            if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) {
                throw new Exception('Invalid color format.');
            }

            // Check for duplicate
            $stmt = $pdo->prepare("SELECT id FROM vr_tags WHERE name = :name AND category = :cat");
            $stmt->execute([':name' => $name, ':cat' => $category]);
            if ($stmt->fetch()) {
                throw new Exception('A tag with this name already exists in this category.');
            }

            $stmt = $pdo->prepare(
                "INSERT INTO vr_tags (name, category, description, color, icon, is_system, is_active)
                 VALUES (:name, :cat, :desc, :color, :icon, 0, 1)"
            );
            $stmt->execute([
                ':name'  => $name,
                ':cat'   => $category,
                ':desc'  => $description,
                ':color' => $color,
                ':icon'  => $icon,
            ]);

            echo json_encode([
                'success' => true,
                'tag_id'  => $pdo->lastInsertId(),
                'message' => 'Tag created successfully.',
            ]);
            break;

        case 'update':
            $tag_id = (int)($_POST['tag_id'] ?? 0);
            $name = sanitizeInput($_POST['name'] ?? '');
            $color = sanitizeInput($_POST['color'] ?? '');
            $icon = sanitizeInput($_POST['icon'] ?? '');
            $description = sanitizeInput($_POST['description'] ?? '');

            if ($tag_id <= 0) {
                throw new Exception('Invalid tag ID.');
            }

            // Cannot edit system tags
            $stmt = $pdo->prepare("SELECT is_system FROM vr_tags WHERE id = :id");
            $stmt->execute([':id' => $tag_id]);
            $tag = $stmt->fetch();
            if (!$tag) {
                throw new Exception('Tag not found.');
            }
            if ($tag['is_system']) {
                throw new Exception('System tags cannot be modified.');
            }

            if (empty($name)) {
                throw new Exception('Tag name is required.');
            }

            if (!empty($color) && !preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) {
                throw new Exception('Invalid color format.');
            }

            $stmt = $pdo->prepare(
                "UPDATE vr_tags SET name = :name, description = :desc, color = :color, icon = :icon
                 WHERE id = :id AND is_system = 0"
            );
            $stmt->execute([
                ':name'  => $name,
                ':desc'  => $description,
                ':color' => $color,
                ':icon'  => $icon,
                ':id'    => $tag_id,
            ]);

            echo json_encode(['success' => true, 'message' => 'Tag updated successfully.']);
            break;

        case 'delete':
            $tag_id = (int)($_POST['tag_id'] ?? 0);

            if ($tag_id <= 0) {
                throw new Exception('Invalid tag ID.');
            }

            // Cannot delete system tags
            $stmt = $pdo->prepare("SELECT is_system FROM vr_tags WHERE id = :id");
            $stmt->execute([':id' => $tag_id]);
            $tag = $stmt->fetch();
            if (!$tag) {
                throw new Exception('Tag not found.');
            }
            if ($tag['is_system']) {
                throw new Exception('System tags cannot be deleted.');
            }

            $stmt = $pdo->prepare("DELETE FROM vr_tags WHERE id = :id AND is_system = 0");
            $stmt->execute([':id' => $tag_id]);

            echo json_encode(['success' => true, 'message' => 'Tag deleted successfully.']);
            break;

        case 'toggle':
            $tag_id = (int)($_POST['tag_id'] ?? 0);

            if ($tag_id <= 0) {
                throw new Exception('Invalid tag ID.');
            }

            $stmt = $pdo->prepare(
                "UPDATE vr_tags SET is_active = NOT is_active WHERE id = :id"
            );
            $stmt->execute([':id' => $tag_id]);

            echo json_encode(['success' => true, 'message' => 'Tag status toggled.']);
            break;

        default:
            throw new Exception('Invalid action.');
    }
} catch (Exception $e) {
    error_log('Tag management error: ' . $e->getMessage());
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
