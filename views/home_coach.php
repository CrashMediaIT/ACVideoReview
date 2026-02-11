<?php
/**
 * Coach Dashboard Home
 * Variables available: $pdo, $user_id, $user_role, $team_id, $user_name, $isCoach, $isAdmin, $csrf_token
 */

$statVideosNeedProcessing = 0;
$statTotalClips = 0;
$statUpcomingGames = 0;
$statActiveGamePlans = 0;
$videosNeedProcessing = [];
$recentGamePlanActivity = [];
$notifications = [];
$recentVideos = [];

if (defined('DB_CONNECTED') && DB_CONNECTED && $pdo) {
    try {
        // Videos needing processing (ready but no clips created yet)
        $stmt = dbQuery($pdo,
            "SELECT vs.id, vs.title, vs.camera_angle, vs.duration_seconds,
                    vs.thumbnail_path, vs.status, vs.created_at, vs.recorded_at,
                    t.team_name,
                    gs.game_date, gs.location
             FROM vr_video_sources vs
             LEFT JOIN teams t ON t.id = vs.team_id
             LEFT JOIN game_schedules gs ON gs.id = vs.game_schedule_id
             WHERE vs.status = 'ready'
               AND NOT EXISTS (
                   SELECT 1 FROM vr_video_clips vc WHERE vc.source_video_id = vs.id
               )
             ORDER BY vs.created_at DESC
             LIMIT 10",
            []
        );
        $videosNeedProcessing = $stmt->fetchAll();
        $statVideosNeedProcessing = count($videosNeedProcessing);
    } catch (PDOException $e) {
        error_log('Coach dashboard - videos needing processing error: ' . $e->getMessage());
    }

    try {
        // Total clips (last 6 months)
        $stmt = dbQuery($pdo,
            "SELECT COUNT(*) AS cnt FROM vr_video_clips
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)",
            []
        );
        $statTotalClips = (int)($stmt->fetch()['cnt'] ?? 0);
    } catch (PDOException $e) {
        error_log('Coach dashboard - total clips error: ' . $e->getMessage());
    }

    try {
        // Upcoming games
        $stmt = dbQuery($pdo,
            "SELECT COUNT(*) AS cnt FROM game_schedules
             WHERE game_date >= CURDATE()
             AND game_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)",
            []
        );
        $statUpcomingGames = (int)($stmt->fetch()['cnt'] ?? 0);
    } catch (PDOException $e) {
        error_log('Coach dashboard - upcoming games error: ' . $e->getMessage());
    }

    try {
        // Active game plans
        $stmt = dbQuery($pdo,
            "SELECT COUNT(*) AS cnt FROM vr_game_plans
             WHERE status IN ('draft','published')",
            []
        );
        $statActiveGamePlans = (int)($stmt->fetch()['cnt'] ?? 0);
    } catch (PDOException $e) {
        error_log('Coach dashboard - active game plans error: ' . $e->getMessage());
    }

    try {
        // Recent game plan activity
        $stmt = dbQuery($pdo,
            "SELECT gp.id, gp.title, gp.plan_type, gp.status, gp.updated_at,
                    t.team_name,
                    gs.game_date
             FROM vr_game_plans gp
             LEFT JOIN teams t ON t.id = gp.team_id
             LEFT JOIN game_schedules gs ON gs.id = gp.game_schedule_id
             ORDER BY gp.updated_at DESC
             LIMIT 5",
            []
        );
        $recentGamePlanActivity = $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log('Coach dashboard - game plan activity error: ' . $e->getMessage());
    }

    try {
        // Notifications
        $stmt = dbQuery($pdo,
            "SELECT id, notification_type, title, message, link_url, is_read, created_at
             FROM vr_notifications
             WHERE user_id = :uid
             ORDER BY created_at DESC
             LIMIT 10",
            [':uid' => $user_id]
        );
        $notifications = $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log('Coach dashboard - notifications error: ' . $e->getMessage());
    }

    try {
        // Recent videos uploaded
        $stmt = dbQuery($pdo,
            "SELECT vs.id, vs.title, vs.camera_angle, vs.duration_seconds,
                    vs.thumbnail_path, vs.status, vs.created_at, vs.recorded_at,
                    t.team_name,
                    u.first_name, u.last_name
             FROM vr_video_sources vs
             LEFT JOIN teams t ON t.id = vs.team_id
             LEFT JOIN users u ON u.id = vs.uploaded_by
             ORDER BY vs.created_at DESC
             LIMIT 6",
            []
        );
        $recentVideos = $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log('Coach dashboard - recent videos error: ' . $e->getMessage());
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
if (!function_exists('notificationIcon')) {
    function notificationIcon($type) {
        $icons = [
            'new_video'            => 'fas fa-video',
            'clip_tagged'          => 'fas fa-tag',
            'game_plan_published'  => 'fas fa-chess',
            'review_session'       => 'fas fa-users',
            'video_ready'          => 'fas fa-check-circle',
            'calendar_update'      => 'fas fa-calendar',
        ];
        return $icons[$type] ?? 'fas fa-bell';
    }
}

$planTypeLabels = [
    'pre_game' => 'Pre-Game',
    'post_game' => 'Post-Game',
    'practice' => 'Practice',
];

$statusBadge = [
    'draft'     => 'badge-warning',
    'published' => 'badge-success',
    'archived'  => 'badge-secondary',
];

$videoStatusBadge = [
    'uploading'  => 'badge-warning',
    'processing' => 'badge-info',
    'ready'      => 'badge-success',
    'error'      => 'badge-danger',
];
?>

<!-- Page Header -->
<div class="page-header">
    <div class="page-header-icon"><i class="fas fa-home"></i></div>
    <div class="page-header-info">
        <h1 class="page-title">Dashboard</h1>
        <p class="page-description">Welcome back, Coach <?= htmlspecialchars($user_name) ?>! Here's your team overview.</p>
    </div>
</div>

<!-- Stat Cards Row -->
<div class="stat-cards-row" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px;margin-bottom:24px;">
    <div class="stat-card">
        <div class="stat-icon" style="color:var(--warning);"><i class="fas fa-exclamation-triangle"></i></div>
        <div class="stat-number"><?= $statVideosNeedProcessing ?></div>
        <div class="stat-label">Videos Need Processing</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="color:var(--success);"><i class="fas fa-cut"></i></div>
        <div class="stat-number"><?= $statTotalClips ?></div>
        <div class="stat-label">Clips (6 Months)</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="color:var(--info);"><i class="fas fa-calendar-alt"></i></div>
        <div class="stat-number"><?= $statUpcomingGames ?></div>
        <div class="stat-label">Upcoming Games</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="color:var(--primary-light);"><i class="fas fa-chess"></i></div>
        <div class="stat-number"><?= $statActiveGamePlans ?></div>
        <div class="stat-label">Active Game Plans</div>
    </div>
</div>

<!-- Videos Needing Processing Alert -->
<?php if (!empty($videosNeedProcessing)): ?>
<div class="card" style="margin-bottom:24px;border-left:3px solid var(--warning);">
    <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;">
        <h3><i class="fas fa-exclamation-triangle" style="color:var(--warning);"></i> Videos Needing Processing</h3>
        <a href="?page=film_room" class="btn btn-sm btn-primary">Go to Film Room</a>
    </div>
    <div class="card-body">
        <?php foreach ($videosNeedProcessing as $video): ?>
            <div class="session-list-card" data-video-id="<?= (int)$video['id'] ?>">
                <div class="session-date-column">
                    <div style="font-size:20px;font-weight:700;color:var(--warning);">
                        <i class="fas fa-video"></i>
                    </div>
                </div>
                <div class="session-details-column" style="flex:1;">
                    <div style="font-weight:600;color:var(--text-white);">
                        <?= htmlspecialchars($video['title']) ?>
                    </div>
                    <div style="font-size:13px;color:var(--text-secondary);margin-top:4px;">
                        <?php if (!empty($video['team_name'])): ?>
                            <span><?= htmlspecialchars($video['team_name']) ?></span> &middot;
                        <?php endif; ?>
                        <?php if (!empty($video['camera_angle'])): ?>
                            <span><?= htmlspecialchars($video['camera_angle']) ?></span> &middot;
                        <?php endif; ?>
                        <?php if (!empty($video['duration_seconds'])): ?>
                            <span><?= formatDuration((int)$video['duration_seconds']) ?></span> &middot;
                        <?php endif; ?>
                        <span><?= timeAgo($video['created_at']) ?></span>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- Quick Actions -->
<div class="card" style="margin-bottom:24px;">
    <div class="card-header">
        <h3><i class="fas fa-bolt"></i> Quick Actions</h3>
    </div>
    <div class="card-body">
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:16px;">
            <a href="?page=film_room" class="card" style="text-decoration:none;text-align:center;padding:20px;transition:all 0.2s;cursor:pointer;" data-action="upload_video">
                <div style="font-size:28px;color:var(--primary-light);margin-bottom:8px;"><i class="fas fa-upload"></i></div>
                <div style="font-weight:600;color:var(--text-white);font-size:14px;">Upload Video</div>
                <div style="font-size:12px;color:var(--text-muted);margin-top:4px;">Add new game footage</div>
            </a>
            <a href="?page=game_plan" class="card" style="text-decoration:none;text-align:center;padding:20px;transition:all 0.2s;cursor:pointer;" data-action="create_game_plan">
                <div style="font-size:28px;color:var(--success);margin-bottom:8px;"><i class="fas fa-chess"></i></div>
                <div style="font-weight:600;color:var(--text-white);font-size:14px;">Create Game Plan</div>
                <div style="font-size:12px;color:var(--text-muted);margin-top:4px;">Set up lines & strategy</div>
            </a>
            <a href="?page=pair_device" class="card" style="text-decoration:none;text-align:center;padding:20px;transition:all 0.2s;cursor:pointer;" data-action="start_telestration">
                <div style="font-size:28px;color:var(--warning);margin-bottom:8px;"><i class="fas fa-pencil-alt"></i></div>
                <div style="font-weight:600;color:var(--text-white);font-size:14px;">Start Telestration</div>
                <div style="font-size:12px;color:var(--text-muted);margin-top:4px;">Draw on video live</div>
            </a>
            <a href="?page=calendar" class="card" style="text-decoration:none;text-align:center;padding:20px;transition:all 0.2s;cursor:pointer;" data-action="view_calendar">
                <div style="font-size:28px;color:var(--info);margin-bottom:8px;"><i class="fas fa-calendar-alt"></i></div>
                <div style="font-weight:600;color:var(--text-white);font-size:14px;">View Calendar</div>
                <div style="font-size:12px;color:var(--text-muted);margin-top:4px;">Game schedule & events</div>
            </a>
        </div>
    </div>
</div>

<!-- Recent Game Plan Activity -->
<div class="card" style="margin-bottom:24px;">
    <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;">
        <h3><i class="fas fa-chess"></i> Recent Game Plan Activity</h3>
        <a href="?page=game_plan" class="btn btn-sm btn-outline">View All</a>
    </div>
    <div class="card-body">
        <?php if (empty($recentGamePlanActivity)): ?>
            <div class="empty-state-card">
                <div class="empty-icon"><i class="fas fa-chess"></i></div>
                <p>No game plans yet. Create your first game plan to get started.</p>
            </div>
        <?php else: ?>
            <?php foreach ($recentGamePlanActivity as $plan): ?>
                <div class="session-list-card" data-plan-id="<?= (int)$plan['id'] ?>">
                    <div class="session-date-column">
                        <div style="font-size:20px;color:var(--primary-light);">
                            <i class="fas fa-clipboard-list"></i>
                        </div>
                    </div>
                    <div class="session-details-column" style="flex:1;">
                        <div style="display:flex;align-items:center;gap:8px;">
                            <span style="font-weight:600;color:var(--text-white);">
                                <?= htmlspecialchars($plan['title']) ?>
                            </span>
                            <span class="badge <?= $statusBadge[$plan['status']] ?? 'badge-secondary' ?>">
                                <?= htmlspecialchars(ucfirst($plan['status'])) ?>
                            </span>
                            <span class="badge badge-primary" style="font-size:10px;">
                                <?= $planTypeLabels[$plan['plan_type']] ?? htmlspecialchars($plan['plan_type']) ?>
                            </span>
                        </div>
                        <div style="font-size:13px;color:var(--text-secondary);margin-top:4px;">
                            <?php if (!empty($plan['team_name'])): ?>
                                <span><?= htmlspecialchars($plan['team_name']) ?></span> &middot;
                            <?php endif; ?>
                            <?php if (!empty($plan['game_date'])): ?>
                                <span>Game: <?= date('M j, Y', strtotime($plan['game_date'])) ?></span> &middot;
                            <?php endif; ?>
                            <span>Updated <?= timeAgo($plan['updated_at']) ?></span>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<!-- Recent Videos Uploaded -->
<div class="card" style="margin-bottom:24px;">
    <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;">
        <h3><i class="fas fa-video"></i> Recent Videos Uploaded</h3>
        <a href="?page=film_room" class="btn btn-sm btn-outline">Film Room</a>
    </div>
    <div class="card-body">
        <?php if (empty($recentVideos)): ?>
            <div class="empty-state-card">
                <div class="empty-icon"><i class="fas fa-video-slash"></i></div>
                <p>No videos uploaded yet. Head to the Film Room to upload game footage.</p>
            </div>
        <?php else: ?>
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:16px;">
                <?php foreach ($recentVideos as $video): ?>
                    <div class="clip-card" data-video-id="<?= (int)$video['id'] ?>">
                        <div class="clip-thumbnail">
                            <?php if (!empty($video['thumbnail_path'])): ?>
                                <img src="<?= htmlspecialchars($video['thumbnail_path']) ?>" alt="Video thumbnail" loading="lazy">
                            <?php else: ?>
                                <div style="display:flex;align-items:center;justify-content:center;height:100%;background:var(--bg-secondary);">
                                    <i class="fas fa-video" style="font-size:32px;color:var(--primary-light);"></i>
                                </div>
                            <?php endif; ?>
                            <?php if (!empty($video['duration_seconds'])): ?>
                                <span class="clip-duration"><?= formatDuration((int)$video['duration_seconds']) ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="clip-meta">
                            <div style="font-weight:600;font-size:14px;color:var(--text-white);" class="truncate">
                                <?= htmlspecialchars($video['title']) ?>
                            </div>
                            <div style="font-size:12px;color:var(--text-muted);margin-top:4px;">
                                <span class="badge <?= $videoStatusBadge[$video['status']] ?? '' ?>" style="font-size:10px;">
                                    <?= htmlspecialchars(ucfirst($video['status'])) ?>
                                </span>
                                <?php if (!empty($video['camera_angle'])): ?>
                                    &middot; <span><?= htmlspecialchars($video['camera_angle']) ?></span>
                                <?php endif; ?>
                                &middot; <span><?= timeAgo($video['created_at']) ?></span>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Notifications -->
<div class="card">
    <div class="card-header">
        <h3><i class="fas fa-bell"></i> Notifications</h3>
    </div>
    <div class="card-body">
        <?php if (empty($notifications)): ?>
            <div class="empty-state-card">
                <div class="empty-icon"><i class="fas fa-bell-slash"></i></div>
                <p>No notifications right now. You're all caught up!</p>
            </div>
        <?php else: ?>
            <?php foreach ($notifications as $notif): ?>
                <div class="notification-item <?= !$notif['is_read'] ? 'notification-unread' : '' ?>"
                     data-notification-id="<?= (int)$notif['id'] ?>"
                     <?php if (!empty($notif['link_url'])): ?>data-link="<?= htmlspecialchars($notif['link_url']) ?>"<?php endif; ?>>
                    <div class="notification-icon">
                        <i class="<?= notificationIcon($notif['notification_type']) ?>"></i>
                    </div>
                    <div class="notification-body">
                        <div style="font-weight:500;color:var(--text-white);font-size:14px;">
                            <?= htmlspecialchars($notif['title']) ?>
                        </div>
                        <?php if (!empty($notif['message'])): ?>
                            <div style="font-size:13px;color:var(--text-secondary);margin-top:2px;">
                                <?= htmlspecialchars($notif['message']) ?>
                            </div>
                        <?php endif; ?>
                        <div style="font-size:11px;color:var(--text-muted);margin-top:4px;">
                            <?= timeAgo($notif['created_at']) ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>
