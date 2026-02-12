<?php
/**
 * API: Calendar Import
 * POST endpoint for importing external calendars (TeamLinkt, iCal, CSV)
 * Called from views/calendar.php import modal
 */
require_once __DIR__ . '/../config/app.php';
initSession();

require_once __DIR__ . '/../db_config.php';
require_once __DIR__ . '/../security.php';

// Must be authenticated
if (!isset($_SESSION['user_id']) || !isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: ' . MAIN_APP_URL . '/login.php?redirect=' . urlencode(APP_URL));
    exit;
}

// Must be coach
$user_role = $_SESSION['role'] ?? '';
if (!in_array($user_role, COACH_ROLES, true)) {
    header('Location: ' . APP_URL . '/dashboard.php?page=home&error=access_denied');
    exit;
}

// Must be POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . APP_URL . '/dashboard.php?page=calendar');
    exit;
}

// CSRF check
if (!checkCsrfToken()) {
    header('Location: ' . APP_URL . '/dashboard.php?page=calendar&error=csrf');
    exit;
}

$source_type = isset($_POST['source_type']) ? sanitizeInput($_POST['source_type']) : '';
$source_name = isset($_POST['source_name']) ? sanitizeInput($_POST['source_name']) : '';
$team_id = isset($_POST['team_id']) ? (int)$_POST['team_id'] : 0;
$auto_sync = isset($_POST['auto_sync']) ? 1 : 0;

$validTypes = ['teamlinkt', 'ical', 'csv'];

if (!in_array($source_type, $validTypes, true) || !$source_name || !$team_id) {
    header('Location: ' . APP_URL . '/dashboard.php?page=calendar&error=missing_fields');
    exit;
}

if (!defined('DB_CONNECTED') || !DB_CONNECTED || !$pdo) {
    header('Location: ' . APP_URL . '/dashboard.php?page=calendar&error=database');
    exit;
}

try {
    $import_url = null;

    if ($source_type === 'teamlinkt') {
        $import_url = isset($_POST['teamlinkt_url']) ? sanitizeInput($_POST['teamlinkt_url']) : null;
    } elseif ($source_type === 'ical') {
        $import_url = isset($_POST['ical_url']) ? filter_var($_POST['ical_url'], FILTER_SANITIZE_URL) : null;
        if ($import_url && !filter_var($import_url, FILTER_VALIDATE_URL)) {
            header('Location: ' . APP_URL . '/dashboard.php?page=calendar&error=invalid_url');
            exit;
        }
    } elseif ($source_type === 'csv') {
        // Handle CSV file upload
        if (isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] === UPLOAD_ERR_OK) {
            $uploadedFile = $_FILES['csv_file'];
            $ext = strtolower(pathinfo($uploadedFile['name'], PATHINFO_EXTENSION));

            if ($ext !== 'csv') {
                header('Location: ' . APP_URL . '/dashboard.php?page=calendar&error=invalid_file_type');
                exit;
            }

            // Move to imports directory
            $importDir = IMPORT_DIR;
            if (!is_dir($importDir)) {
                mkdir($importDir, 0755, true);
            }

            $filename = 'calendar_' . $team_id . '_' . time() . '.csv';
            $destPath = $importDir . $filename;

            if (!move_uploaded_file($uploadedFile['tmp_name'], $destPath)) {
                header('Location: ' . APP_URL . '/dashboard.php?page=calendar&error=upload_failed');
                exit;
            }

            $import_url = 'imports/' . $filename;
        } else {
            header('Location: ' . APP_URL . '/dashboard.php?page=calendar&error=no_file');
            exit;
        }
    }

    // Insert calendar import record
    $stmt = dbQuery($pdo,
        "INSERT INTO vr_calendar_imports (team_id, source_name, source_type, import_url, auto_sync, sync_interval_hours, imported_by, created_at, updated_at)
         VALUES (:team_id, :source_name, :source_type, :import_url, :auto_sync, :sync_interval, :imported_by, NOW(), NOW())",
        [
            ':team_id' => $team_id,
            ':source_name' => $source_name,
            ':source_type' => $source_type,
            ':import_url' => $import_url,
            ':auto_sync' => $auto_sync,
            ':sync_interval' => 24,
            ':imported_by' => $_SESSION['user_id'],
        ]
    );

    // If iCal or TeamLinkt, record last synced time
    if (in_array($source_type, ['ical', 'teamlinkt'], true) && $import_url) {
        $importId = $pdo->lastInsertId();
        dbQuery($pdo,
            "UPDATE vr_calendar_imports SET last_synced_at = NOW() WHERE id = :id",
            [':id' => $importId]
        );
    }

    header('Location: ' . APP_URL . '/dashboard.php?page=calendar&success=imported');
    exit;

} catch (PDOException $e) {
    error_log('Calendar import error: ' . $e->getMessage());
    header('Location: ' . APP_URL . '/dashboard.php?page=calendar&error=database');
    exit;
}
