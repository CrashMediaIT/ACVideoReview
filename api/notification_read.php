<?php
// ACVideoReview API - Mark Notification as Read
require_once __DIR__ . '/../config/app.php';
initSession();

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

// Read JSON body
$input = json_decode(file_get_contents('php://input'), true);
$notification_id = isset($input['notification_id']) ? (int)$input['notification_id'] : 0;

try {
    if ($notification_id > 0) {
        // Mark specific notification as read
        $stmt = $pdo->prepare(
            "UPDATE vr_notifications SET is_read = 1 WHERE id = :id AND user_id = :user_id"
        );
        $stmt->execute([':id' => $notification_id, ':user_id' => $user_id]);
    } else {
        // Mark all notifications as read
        $stmt = $pdo->prepare(
            "UPDATE vr_notifications SET is_read = 1 WHERE user_id = :user_id AND is_read = 0"
        );
        $stmt->execute([':user_id' => $user_id]);
    }

    echo json_encode(['success' => true]);
} catch (PDOException $e) {
    error_log('Notification read error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error']);
}
