<?php
// ACVideoReview API - NDI Camera Sources
// Fetches available NDI cameras from the shared Arctic Wolves database (ndi_cameras table)
require_once __DIR__ . '/../config/app.php';
initSession();

require_once __DIR__ . '/../db_config.php';
require_once __DIR__ . '/../security.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

requireAuth();

if (!DB_CONNECTED || !$pdo) {
    http_response_code(503);
    echo json_encode(['success' => false, 'error' => 'Database unavailable']);
    exit;
}

$user_role = $_SESSION['role'] ?? 'athlete';

// Only coaches can access NDI cameras
if (!in_array($user_role, COACH_ROLES, true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Permission denied']);
    exit;
}

try {
    // Query the ndi_cameras table from the shared Arctic Wolves database
    $stmt = dbQuery($pdo,
        "SELECT id, name, ip_address, port, ndi_name, location, is_active
         FROM ndi_cameras
         WHERE is_active = 1
         ORDER BY name ASC",
        []
    );
    $cameras = $stmt->fetchAll();

    echo json_encode([
        'success' => true,
        'cameras' => $cameras,
    ]);
} catch (PDOException $e) {
    error_log('NDI sources API error: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'cameras' => [],
        'error'   => 'NDI cameras table not available. Configure cameras in Arctic Wolves System Tools.',
    ]);
}
