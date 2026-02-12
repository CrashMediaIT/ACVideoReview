<?php
/**
 * Review Sessions View (Coach Only)
 * Variables available: $pdo, $user_id, $user_role, $team_id, $user_name, $isCoach, $isAdmin, $csrf_token
 */

$sessions = [];
$availableClips = [];
$games = [];
$filterStatus = isset($_GET['status']) ? sanitizeInput($_GET['status']) : '';

if (defined('DB_CONNECTED') && DB_CONNECTED && $pdo) {
    // Fetch review sessions
    try {
        $params = [':uid' => $user_id];
        $statusWhere = '';
        if ($filterStatus === 'upcoming') {
            $statusWhere = 'AND rs.scheduled_at >= NOW() AND rs.completed_at IS NULL';
        } elseif ($filterStatus === 'completed') {
            $statusWhere = 'AND rs.completed_at IS NOT NULL';
        } elseif ($filterStatus === 'past') {
            $statusWhere = 'AND rs.scheduled_at < NOW() AND rs.completed_at IS NULL';
        }

        $stmt = dbQuery($pdo,
            "SELECT rs.id, rs.title, rs.description, rs.session_type, rs.scheduled_at,
                    rs.completed_at, rs.created_at, rs.game_schedule_id,
                    gs.game_date, gs.location,
                    t.team_name AS game_team,
                    t2.team_name AS opponent_name,
                    (SELECT COUNT(*) FROM vr_review_session_clips rsc WHERE rsc.review_session_id = rs.id) AS clip_count
             FROM vr_review_sessions rs
             LEFT JOIN game_schedules gs ON gs.id = rs.game_schedule_id
             LEFT JOIN teams t ON t.id = gs.team_id
             LEFT JOIN teams t2 ON t2.id = gs.opponent_team_id
             WHERE rs.created_by = :uid
             {$statusWhere}
             ORDER BY rs.scheduled_at DESC",
            $params
        );
        $sessions = $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log('Review sessions - list error: ' . $e->getMessage());
    }

    // Fetch available clips for adding to sessions
    try {
        $stmt = dbQuery($pdo,
            "SELECT vc.id, vc.title, vc.start_time, vc.end_time, vc.duration,
                    vc.created_at, vs.camera_angle, vs.title AS source_title
             FROM vr_video_clips vc
             LEFT JOIN vr_video_sources vs ON vs.id = vc.source_video_id
             ORDER BY vc.created_at DESC
             LIMIT 100",
            []
        );
        $availableClips = $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log('Review sessions - clips error: ' . $e->getMessage());
    }

    // Fetch upcoming games for linking
    try {
        $stmt = dbQuery($pdo,
            "SELECT gs.id, gs.game_date, gs.location,
                    t.team_name, t2.team_name AS opponent_name
             FROM game_schedules gs
             LEFT JOIN teams t ON t.id = gs.team_id
             LEFT JOIN teams t2 ON t2.id = gs.opponent_team_id
             ORDER BY gs.game_date DESC
             LIMIT 50",
            []
        );
        $games = $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log('Review sessions - games error: ' . $e->getMessage());
    }
}

// Helpers (check if already defined from other views)
if (!function_exists('formatDuration')) {
    function formatDuration($seconds) {
        if (!$seconds) return '0:00';
        $m = floor($seconds / 60);
        $s = $seconds % 60;
        return sprintf('%d:%02d', $m, $s);
    }
}
if (!function_exists('timeAgo')) {
    function timeAgo($datetime) {
        $now = new DateTime();
        $ago = new DateTime($datetime);
        $diff = $now->diff($ago);
        if ($diff->d > 0) return $diff->d . 'd ago';
        if ($diff->h > 0) return $diff->h . 'h ago';
        if ($diff->i > 0) return $diff->i . 'm ago';
        return 'just now';
    }
}

$sessionTypeLabels = [
    'team'         => 'Team',
    'individual'   => 'Individual',
    'coaches_only' => 'Coaches Only',
];

$sessionTypeIcons = [
    'team'         => 'fa-solid fa-users',
    'individual'   => 'fa-solid fa-user',
    'coaches_only' => 'fa-solid fa-user-shield',
];
?>

<!-- Page Header -->
<div class="page-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
    <div class="page-header-content">
        <h1 class="page-title"><i class="fa-solid fa-chalkboard-user"></i> Review Sessions</h1>
        <p class="page-description">Schedule and manage video review sessions for your team.</p>
    </div>
    <button class="btn btn-primary" onclick="document.getElementById('createSessionModal').style.display='flex'">
        <i class="fa-solid fa-plus"></i> New Session
    </button>
</div>

<!-- Flash Messages -->
<?php if (!empty($_SESSION['success'])): ?>
    <div class="alert alert-success" style="margin-bottom:16px;padding:12px 16px;background:rgba(39,174,96,0.15);border:1px solid var(--success);border-radius:8px;color:var(--success);">
        <i class="fa-solid fa-circle-check"></i> <?= htmlspecialchars($_SESSION['success']) ?>
    </div>
    <?php unset($_SESSION['success']); ?>
<?php endif; ?>
<?php if (!empty($_SESSION['error'])): ?>
    <div class="alert alert-error" style="margin-bottom:16px;padding:12px 16px;background:rgba(231,76,60,0.15);border:1px solid var(--error);border-radius:8px;color:var(--error);">
        <i class="fa-solid fa-exclamation-circle"></i> <?= htmlspecialchars($_SESSION['error']) ?>
    </div>
    <?php unset($_SESSION['error']); ?>
<?php endif; ?>

<!-- Filter Tabs -->
<div style="display:flex;gap:8px;margin-bottom:20px;flex-wrap:wrap;">
    <a href="?page=review_sessions" class="btn <?= empty($filterStatus) ? 'btn-primary' : 'btn-outline' ?> btn-sm">All</a>
    <a href="?page=review_sessions&status=upcoming" class="btn <?= $filterStatus === 'upcoming' ? 'btn-primary' : 'btn-outline' ?> btn-sm">Upcoming</a>
    <a href="?page=review_sessions&status=completed" class="btn <?= $filterStatus === 'completed' ? 'btn-primary' : 'btn-outline' ?> btn-sm">Completed</a>
    <a href="?page=review_sessions&status=past" class="btn <?= $filterStatus === 'past' ? 'btn-primary' : 'btn-outline' ?> btn-sm">Past Due</a>
</div>

<!-- Sessions List -->
<div class="card">
    <div class="card-header">
        <h3><i class="fa-solid fa-list"></i> Sessions (<?= count($sessions) ?>)</h3>
    </div>
    <div class="card-body">
        <?php if (empty($sessions)): ?>
            <div class="empty-state-card">
                <div class="empty-icon"><i class="fa-solid fa-chalkboard-user"></i></div>
                <p>No review sessions found. Create your first session to get started.</p>
            </div>
        <?php else: ?>
            <?php foreach ($sessions as $session): ?>
                <?php
                    $isUpcoming = strtotime($session['scheduled_at']) >= time() && empty($session['completed_at']);
                    $isCompleted = !empty($session['completed_at']);
                    $isPastDue = strtotime($session['scheduled_at']) < time() && empty($session['completed_at']);
                ?>
                <div class="session-list-card" style="display:flex;align-items:center;gap:16px;padding:16px;border-bottom:1px solid var(--border);">
                    <div class="session-date-column" style="text-align:center;min-width:60px;">
                        <div style="font-size:24px;font-weight:700;color:<?= $isCompleted ? 'var(--success)' : ($isPastDue ? 'var(--error)' : 'var(--primary-light)') ?>;">
                            <?= date('d', strtotime($session['scheduled_at'])) ?>
                        </div>
                        <div style="font-size:12px;color:var(--text-muted);text-transform:uppercase;">
                            <?= date('M', strtotime($session['scheduled_at'])) ?>
                        </div>
                        <div style="font-size:11px;color:var(--text-muted);">
                            <?= date('g:i A', strtotime($session['scheduled_at'])) ?>
                        </div>
                    </div>
                    <div style="flex:1;">
                        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                            <span style="font-weight:600;color:var(--text-white);font-size:15px;">
                                <?= htmlspecialchars($session['title']) ?>
                            </span>
                            <span class="badge <?= $isCompleted ? 'badge-success' : ($isPastDue ? 'badge-danger' : 'badge-info') ?>" style="font-size:10px;">
                                <?= $isCompleted ? 'Completed' : ($isPastDue ? 'Past Due' : 'Upcoming') ?>
                            </span>
                            <span class="badge badge-primary" style="font-size:10px;">
                                <i class="<?= $sessionTypeIcons[$session['session_type']] ?? 'fa-solid fa-users' ?>" style="margin-right:3px;"></i>
                                <?= $sessionTypeLabels[$session['session_type']] ?? htmlspecialchars($session['session_type']) ?>
                            </span>
                        </div>
                        <?php if (!empty($session['description'])): ?>
                            <div style="font-size:13px;color:var(--text-secondary);margin-top:4px;">
                                <?= htmlspecialchars($session['description']) ?>
                            </div>
                        <?php endif; ?>
                        <div style="font-size:12px;color:var(--text-muted);margin-top:6px;display:flex;gap:12px;flex-wrap:wrap;">
                            <?php if (!empty($session['game_team'])): ?>
                                <span><i class="fa-solid fa-hockey-puck" style="margin-right:3px;"></i>
                                    <?= htmlspecialchars($session['game_team']) ?>
                                    <?php if (!empty($session['opponent_name'])): ?>
                                        vs <?= htmlspecialchars($session['opponent_name']) ?>
                                    <?php endif; ?>
                                </span>
                            <?php endif; ?>
                            <span><i class="fa-solid fa-film" style="margin-right:3px;"></i> <?= (int)$session['clip_count'] ?> clips</span>
                            <span><i class="fa-solid fa-clock" style="margin-right:3px;"></i> Created <?= timeAgo($session['created_at']) ?></span>
                        </div>
                    </div>
                    <div style="display:flex;gap:8px;">
                        <form action="api/review_session_delete.php" method="POST" style="display:inline;"
                              onsubmit="return confirm('Delete this review session?');">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                            <input type="hidden" name="session_id" value="<?= (int)$session['id'] ?>">
                            <button type="submit" class="btn btn-sm" style="color:var(--error);border-color:var(--error);" title="Delete">
                                <i class="fa-solid fa-trash"></i>
                            </button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<!-- Create Session Modal -->
<div id="createSessionModal" class="modal-overlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.6);z-index:200;align-items:center;justify-content:center;padding:20px;">
    <div class="card" style="max-width:600px;width:100%;max-height:90vh;overflow-y:auto;">
        <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;">
            <h3><i class="fa-solid fa-plus-circle"></i> New Review Session</h3>
            <button class="btn btn-sm" onclick="document.getElementById('createSessionModal').style.display='none'" style="background:none;border:none;color:var(--text-muted);font-size:18px;cursor:pointer;">
                <i class="fa-solid fa-times"></i>
            </button>
        </div>
        <div class="card-body">
            <form action="api/review_session_save.php" method="POST" id="createSessionForm">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">

                <div style="margin-bottom:16px;">
                    <label style="display:block;font-size:13px;font-weight:500;color:var(--text-secondary);margin-bottom:6px;">
                        Session Title *
                    </label>
                    <input type="text" name="title" required placeholder="e.g. Game Film Review - vs Eagles"
                           style="width:100%;padding:10px 12px;background:var(--bg-main);border:1px solid var(--border);border-radius:8px;color:var(--text-white);font-size:14px;">
                </div>

                <div style="margin-bottom:16px;">
                    <label style="display:block;font-size:13px;font-weight:500;color:var(--text-secondary);margin-bottom:6px;">
                        Description
                    </label>
                    <textarea name="description" rows="3" placeholder="Optional notes for this session..."
                              style="width:100%;padding:10px 12px;background:var(--bg-main);border:1px solid var(--border);border-radius:8px;color:var(--text-white);font-size:14px;resize:vertical;"></textarea>
                </div>

                <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px;">
                    <div>
                        <label style="display:block;font-size:13px;font-weight:500;color:var(--text-secondary);margin-bottom:6px;">
                            Session Type *
                        </label>
                        <select name="session_type" required
                                style="width:100%;padding:10px 12px;background:var(--bg-main);border:1px solid var(--border);border-radius:8px;color:var(--text-white);font-size:14px;">
                            <option value="team">Team</option>
                            <option value="individual">Individual</option>
                            <option value="coaches_only">Coaches Only</option>
                        </select>
                    </div>
                    <div>
                        <label style="display:block;font-size:13px;font-weight:500;color:var(--text-secondary);margin-bottom:6px;">
                            Scheduled Date/Time *
                        </label>
                        <input type="datetime-local" name="scheduled_at" required
                               style="width:100%;padding:10px 12px;background:var(--bg-main);border:1px solid var(--border);border-radius:8px;color:var(--text-white);font-size:14px;">
                    </div>
                </div>

                <div style="margin-bottom:16px;">
                    <label style="display:block;font-size:13px;font-weight:500;color:var(--text-secondary);margin-bottom:6px;">
                        Link to Game (optional)
                    </label>
                    <select name="game_schedule_id"
                            style="width:100%;padding:10px 12px;background:var(--bg-main);border:1px solid var(--border);border-radius:8px;color:var(--text-white);font-size:14px;">
                        <option value="">-- No game linked --</option>
                        <?php foreach ($games as $game): ?>
                            <option value="<?= (int)$game['id'] ?>">
                                <?= date('M j, Y', strtotime($game['game_date'])) ?>
                                — <?= htmlspecialchars($game['team_name'] ?? 'Team') ?>
                                vs <?= htmlspecialchars($game['opponent_name'] ?? 'Opponent') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <?php if (!empty($availableClips)): ?>
                <div style="margin-bottom:16px;">
                    <label style="display:block;font-size:13px;font-weight:500;color:var(--text-secondary);margin-bottom:6px;">
                        Add Clips to Session
                    </label>
                    <div style="max-height:200px;overflow-y:auto;border:1px solid var(--border);border-radius:8px;padding:8px;">
                        <?php foreach ($availableClips as $idx => $clip): ?>
                            <label style="display:flex;align-items:center;gap:8px;padding:6px 8px;cursor:pointer;border-radius:4px;"
                                   onmouseover="this.style.background='rgba(107,70,193,0.1)'" onmouseout="this.style.background='transparent'">
                                <input type="checkbox" name="clips[<?= $idx ?>][id]" value="<?= (int)$clip['id'] ?>"
                                       style="accent-color:var(--primary);">
                                <span style="font-size:13px;color:var(--text-white);flex:1;">
                                    <?= htmlspecialchars($clip['title']) ?>
                                </span>
                                <?php if (!empty($clip['camera_angle'])): ?>
                                    <span style="font-size:11px;color:var(--text-muted);"><?= htmlspecialchars($clip['camera_angle']) ?></span>
                                <?php endif; ?>
                                <?php if (!empty($clip['duration'])): ?>
                                    <span style="font-size:11px;color:var(--text-muted);"><?= formatDuration((float)$clip['duration']) ?></span>
                                <?php endif; ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <div style="display:flex;gap:12px;justify-content:flex-end;padding-top:12px;border-top:1px solid var(--border);">
                    <button type="button" class="btn btn-outline" onclick="document.getElementById('createSessionModal').style.display='none'">
                        Cancel
                    </button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fa-solid fa-floppy-disk"></i> Create Session
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Close modal on backdrop click
document.getElementById('createSessionModal').addEventListener('click', function(e) {
    if (e.target === this) {
        this.style.display = 'none';
    }
});

// Close modal on Escape
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        document.getElementById('createSessionModal').style.display = 'none';
    }
});
</script>
