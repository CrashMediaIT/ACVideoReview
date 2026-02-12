<?php
// ACVideoReview API - Review Session Save
require_once __DIR__ . '/../config/app.php';
initSession();

require_once __DIR__ . '/../db_config.php';
require_once __DIR__ . '/../security.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../dashboard.php?page=review_sessions');
    exit;
}

requireAuth();

if (!checkCsrfToken()) {
    $_SESSION['error'] = 'Invalid security token.';
    header('Location: ../dashboard.php?page=review_sessions');
    exit;
}

if (!DB_CONNECTED || !$pdo) {
    $_SESSION['error'] = 'Database unavailable.';
    header('Location: ../dashboard.php?page=review_sessions');
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$user_role = $_SESSION['role'] ?? 'athlete';

// Only coaches can manage review sessions
if (!in_array($user_role, COACH_ROLES, true)) {
    $_SESSION['error'] = 'You do not have permission to manage review sessions.';
    header('Location: ../dashboard.php?page=review_sessions');
    exit;
}

try {
    $session_id = !empty($_POST['session_id']) ? (int)$_POST['session_id'] : null;
    $title = sanitizeInput($_POST['title'] ?? '');
    $description = sanitizeInput($_POST['description'] ?? '');
    $session_type = sanitizeInput($_POST['session_type'] ?? 'team');
    $scheduled_at = sanitizeInput($_POST['scheduled_at'] ?? '');
    $game_schedule_id = !empty($_POST['game_schedule_id']) ? (int)$_POST['game_schedule_id'] : null;

    // Validate session type
    $valid_types = ['team', 'individual', 'coaches_only'];
    if (!in_array($session_type, $valid_types, true)) {
        throw new Exception('Invalid session type.');
    }

    if (empty($title)) {
        throw new Exception('Session title is required.');
    }

    if (empty($scheduled_at)) {
        throw new Exception('Scheduled date/time is required.');
    }

    // Validate datetime format
    $dt = DateTime::createFromFormat('Y-m-d\TH:i', $scheduled_at);
    if (!$dt) {
        $dt = DateTime::createFromFormat('Y-m-d H:i:s', $scheduled_at);
    }
    if (!$dt) {
        throw new Exception('Invalid date/time format.');
    }
    $scheduled_at_db = $dt->format('Y-m-d H:i:s');

    if ($session_id) {
        // Update existing session
        $stmt = $pdo->prepare(
            "UPDATE vr_review_sessions SET
                game_schedule_id = :game_id, title = :title, description = :desc,
                session_type = :type, scheduled_at = :scheduled
             WHERE id = :id AND created_by = :uid"
        );
        $stmt->execute([
            ':game_id'   => $game_schedule_id,
            ':title'     => $title,
            ':desc'      => $description,
            ':type'      => $session_type,
            ':scheduled' => $scheduled_at_db,
            ':id'        => $session_id,
            ':uid'       => $user_id,
        ]);
        $target_id = $session_id;
        $_SESSION['success'] = 'Review session updated successfully.';
    } else {
        // Create new session
        $stmt = $pdo->prepare(
            "INSERT INTO vr_review_sessions
                (game_schedule_id, title, description, session_type, scheduled_at, created_by)
             VALUES (:game_id, :title, :desc, :type, :scheduled, :uid)"
        );
        $stmt->execute([
            ':game_id'   => $game_schedule_id,
            ':title'     => $title,
            ':desc'      => $description,
            ':type'      => $session_type,
            ':scheduled' => $scheduled_at_db,
            ':uid'       => $user_id,
        ]);
        $target_id = $pdo->lastInsertId();
        $_SESSION['success'] = 'Review session created successfully.';
    }

    // Save clip associations if provided
    if (isset($_POST['clips']) && is_array($_POST['clips'])) {
        // Clear existing clip associations
        $stmt = $pdo->prepare("DELETE FROM vr_review_session_clips WHERE review_session_id = :sid");
        $stmt->execute([':sid' => $target_id]);

        $clip_stmt = $pdo->prepare(
            "INSERT INTO vr_review_session_clips (review_session_id, clip_id, display_order, notes)
             VALUES (:sid, :clip_id, :ord, :notes)"
        );

        $order = 0;
        foreach ($_POST['clips'] as $clip) {
            $clip_id = (int)($clip['id'] ?? 0);
            $clip_notes = sanitizeInput($clip['notes'] ?? '');
            if ($clip_id > 0) {
                $clip_stmt->execute([
                    ':sid'     => $target_id,
                    ':clip_id' => $clip_id,
                    ':ord'     => $order++,
                    ':notes'   => $clip_notes,
                ]);
            }
        }
    }
} catch (Exception $e) {
    $_SESSION['error'] = $e->getMessage();
}

header('Location: ../dashboard.php?page=review_sessions');
exit;
