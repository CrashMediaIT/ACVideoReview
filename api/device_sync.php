<?php
// ACVideoReview API - Device Sync (Viewer/Controller Pairing)
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
$action = $_POST['action'] ?? '';

try {
    switch ($action) {
        case 'create_session':
            // Viewer creates a new session and gets a pairing code
            $code = str_pad(random_int(0, 999999), PAIRING_CODE_LENGTH, '0', STR_PAD_LEFT);
            $device_id = sanitizeInput($_POST['device_id'] ?? '');
            $expires_at = date('Y-m-d H:i:s', time() + DEVICE_SESSION_TTL);

            // Expire any old sessions for this user
            $stmt = $pdo->prepare(
                "UPDATE vr_device_sessions SET status = 'expired' WHERE user_id = :uid AND status IN ('waiting', 'paired', 'active')"
            );
            $stmt->execute([':uid' => $user_id]);

            $stmt = $pdo->prepare(
                "INSERT INTO vr_device_sessions (session_code, user_id, viewer_device_id, status, expires_at, last_heartbeat)
                 VALUES (:code, :uid, :device_id, 'waiting', :expires, NOW())"
            );
            $stmt->execute([
                ':code' => $code,
                ':uid' => $user_id,
                ':device_id' => $device_id,
                ':expires' => $expires_at,
            ]);

            echo json_encode([
                'success' => true,
                'session_id' => $pdo->lastInsertId(),
                'session_code' => $code,
                'expires_at' => $expires_at,
            ]);
            break;

        case 'join_session':
            // Controller joins with a pairing code
            $code = sanitizeInput($_POST['session_code'] ?? '');
            $device_id = sanitizeInput($_POST['device_id'] ?? '');

            $stmt = $pdo->prepare(
                "SELECT id, user_id, status FROM vr_device_sessions
                 WHERE session_code = :code AND status = 'waiting' AND expires_at > NOW()
                 LIMIT 1"
            );
            $stmt->execute([':code' => $code]);
            $session = $stmt->fetch();

            if (!$session) {
                echo json_encode(['success' => false, 'error' => 'Invalid or expired session code']);
                break;
            }

            $stmt = $pdo->prepare(
                "UPDATE vr_device_sessions SET controller_device_id = :device_id, status = 'paired', paired_at = NOW(), last_heartbeat = NOW()
                 WHERE id = :id"
            );
            $stmt->execute([':device_id' => $device_id, ':id' => $session['id']]);

            echo json_encode([
                'success' => true,
                'session_id' => $session['id'],
                'status' => 'paired',
            ]);
            break;

        case 'poll':
            // Both devices poll for status updates
            $session_id = (int)($_POST['session_id'] ?? 0);

            $stmt = $pdo->prepare(
                "SELECT id, status, current_video_id, current_clip_id, playback_time, is_playing
                 FROM vr_device_sessions WHERE id = :id AND user_id = :uid AND expires_at > NOW()"
            );
            $stmt->execute([':id' => $session_id, ':uid' => $user_id]);
            $session = $stmt->fetch();

            if (!$session) {
                echo json_encode(['success' => false, 'error' => 'Session not found or expired']);
                break;
            }

            echo json_encode([
                'success' => true,
                'status' => $session['status'],
                'current_video_id' => $session['current_video_id'],
                'current_clip_id' => $session['current_clip_id'],
                'playback_time' => $session['playback_time'],
                'is_playing' => (bool)$session['is_playing'],
            ]);
            break;

        case 'heartbeat':
            // Keep session alive
            $session_id = (int)($_POST['session_id'] ?? 0);

            $stmt = $pdo->prepare(
                "UPDATE vr_device_sessions SET last_heartbeat = NOW() WHERE id = :id AND user_id = :uid"
            );
            $stmt->execute([':id' => $session_id, ':uid' => $user_id]);

            echo json_encode(['success' => true]);
            break;

        case 'send_command':
            // Controller sends a command to the viewer
            $session_id = (int)($_POST['session_id'] ?? 0);
            $command = sanitizeInput($_POST['command'] ?? '');

            $allowed_commands = ['play', 'pause', 'seek', 'load_video', 'load_clip'];
            if (!in_array($command, $allowed_commands, true)) {
                echo json_encode(['success' => false, 'error' => 'Invalid command']);
                break;
            }

            $updates = ['last_heartbeat = NOW()'];
            $params = [':id' => $session_id, ':uid' => $user_id];

            if ($command === 'play') {
                $updates[] = "is_playing = 1";
            } elseif ($command === 'pause') {
                $updates[] = "is_playing = 0";
            } elseif ($command === 'seek') {
                $time = (float)($_POST['time'] ?? 0);
                $updates[] = "playback_time = :time";
                $params[':time'] = $time;
            } elseif ($command === 'load_video') {
                $video_id = (int)($_POST['video_id'] ?? 0);
                $updates[] = "current_video_id = :vid";
                $updates[] = "current_clip_id = NULL";
                $updates[] = "playback_time = 0";
                $params[':vid'] = $video_id;
            } elseif ($command === 'load_clip') {
                $clip_id = (int)($_POST['clip_id'] ?? 0);
                $updates[] = "current_clip_id = :cid";
                $updates[] = "playback_time = 0";
                $params[':cid'] = $clip_id;
            }

            $stmt = $pdo->prepare(
                "UPDATE vr_device_sessions SET " . implode(', ', $updates) . " WHERE id = :id AND user_id = :uid"
            );
            $stmt->execute($params);

            echo json_encode(['success' => true]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid action']);
    }
} catch (PDOException $e) {
    error_log('Device sync error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error']);
}
