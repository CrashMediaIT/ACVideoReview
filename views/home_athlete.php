<?php
/**
 * Athlete Dashboard Home
 * Variables available: $pdo, $user_id, $user_role, $team_id, $user_name, $isCoach, $csrf_token
 */

$upcomingGames = [];
$recentClips = [];
$notifications = [];
$statUpcomingGames = 0;
$statNewClips = 0;
$statPendingReviews = 0;
$statTotalClips = 0;

if (defined('DB_CONNECTED') && DB_CONNECTED && $pdo) {
    try {
        // Upcoming games for athlete's teams
        $stmt = dbQuery($pdo,
            "SELECT gs.id, gs.game_date, gs.location, gs.home_score, gs.away_score,
                    gs.team_id, gs.opponent_team_id,
                    t.team_name AS team_name,
                    t2.team_name AS opponent_name
             FROM game_schedules gs
             INNER JOIN team_roster tr ON tr.team_id = gs.team_id
             LEFT JOIN teams t ON t.id = gs.team_id
             LEFT JOIN teams t2 ON t2.id = gs.opponent_team_id
             WHERE tr.user_id = :uid
               AND gs.game_date >= CURDATE()
             ORDER BY gs.game_date ASC
             LIMIT 5",
            [':uid' => $user_id]
        );
        $upcomingGames = $stmt->fetchAll();
        $statUpcomingGames = count($upcomingGames);
    } catch (PDOException $e) {
        error_log('Athlete dashboard - upcoming games error: ' . $e->getMessage());
    }

    try {
        // Recent clips athlete is tagged in
        $stmt = dbQuery($pdo,
            "SELECT vc.id, vc.title, vc.thumbnail_path, vc.duration, vc.created_at,
                    vc.game_schedule_id, ca.role_in_clip,
                    vs.camera_angle
             FROM vr_clip_athletes ca
             INNER JOIN vr_video_clips vc ON vc.id = ca.clip_id
             LEFT JOIN vr_video_sources vs ON vs.id = vc.source_video_id
             WHERE ca.athlete_id = :uid
             ORDER BY vc.created_at DESC
             LIMIT 8",
            [':uid' => $user_id]
        );
        $recentClips = $stmt->fetchAll();
        $statTotalClips = count($recentClips);
    } catch (PDOException $e) {
        error_log('Athlete dashboard - recent clips error: ' . $e->getMessage());
    }

    try {
        // Count new clips (last 7 days)
        $stmt = dbQuery($pdo,
            "SELECT COUNT(*) AS cnt
             FROM vr_clip_athletes ca
             INNER JOIN vr_video_clips vc ON vc.id = ca.clip_id
             WHERE ca.athlete_id = :uid
               AND vc.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)",
            [':uid' => $user_id]
        );
        $statNewClips = (int)($stmt->fetch()['cnt'] ?? 0);
    } catch (PDOException $e) {
        error_log('Athlete dashboard - new clips count error: ' . $e->getMessage());
    }

    try {
        // Total clips count
        $stmt = dbQuery($pdo,
            "SELECT COUNT(*) AS cnt FROM vr_clip_athletes WHERE athlete_id = :uid",
            [':uid' => $user_id]
        );
        $statTotalClips = (int)($stmt->fetch()['cnt'] ?? 0);
    } catch (PDOException $e) {
        error_log('Athlete dashboard - total clips error: ' . $e->getMessage());
    }

    try {
        // Pending review sessions
        $stmt = dbQuery($pdo,
            "SELECT COUNT(*) AS cnt
             FROM vr_review_sessions rs
             WHERE rs.session_type IN ('team','individual')
               AND rs.scheduled_at >= NOW()
               AND rs.completed_at IS NULL",
            []
        );
        $statPendingReviews = (int)($stmt->fetch()['cnt'] ?? 0);
    } catch (PDOException $e) {
        error_log('Athlete dashboard - pending reviews error: ' . $e->getMessage());
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
        error_log('Athlete dashboard - notifications error: ' . $e->getMessage());
    }
}

// Helper to format duration
function formatDuration($seconds) {
    if (!$seconds) return '0:00';
    $m = floor($seconds / 60);
    $s = $seconds % 60;
    return sprintf('%d:%02d', $m, $s);
}

// Helper for relative time
function timeAgo($datetime) {
    $now = new DateTime();
    $ago = new DateTime($datetime);
    $diff = $now->diff($ago);
    if ($diff->d > 0) return $diff->d . 'd ago';
    if ($diff->h > 0) return $diff->h . 'h ago';
    if ($diff->i > 0) return $diff->i . 'm ago';
    return 'just now';
}

// Notification icon mapping
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
?>

<!-- Page Header -->
<div class="page-header">
    <div class="page-header-icon"><i class="fas fa-home"></i></div>
    <div class="page-header-info">
        <h1 class="page-title">Dashboard</h1>
        <p class="page-description">Welcome back, <?= htmlspecialchars($user_name) ?>! Here's your latest updates.</p>
    </div>
</div>

<!-- Stat Cards Row -->
<div class="stat-cards-row" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px;margin-bottom:24px;">
    <div class="stat-card">
        <div class="stat-icon" style="color:var(--info);"><i class="fas fa-calendar-alt"></i></div>
        <div class="stat-number"><?= $statUpcomingGames ?></div>
        <div class="stat-label">Upcoming Games</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="color:var(--success);"><i class="fas fa-film"></i></div>
        <div class="stat-number"><?= $statNewClips ?></div>
        <div class="stat-label">New Clips (7 days)</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="color:var(--warning);"><i class="fas fa-clock"></i></div>
        <div class="stat-number"><?= $statPendingReviews ?></div>
        <div class="stat-label">Pending Reviews</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="color:var(--primary-light);"><i class="fas fa-cut"></i></div>
        <div class="stat-number"><?= $statTotalClips ?></div>
        <div class="stat-label">Total Clips</div>
    </div>
</div>

<!-- Upcoming Games -->
<div class="card" style="margin-bottom:24px;">
    <div class="card-header">
        <h3><i class="fas fa-hockey-puck"></i> Upcoming Games</h3>
    </div>
    <div class="card-body">
        <?php if (empty($upcomingGames)): ?>
            <div class="empty-state-card">
                <div class="empty-icon"><i class="fas fa-calendar-times"></i></div>
                <p>No upcoming games scheduled.</p>
            </div>
        <?php else: ?>
            <?php foreach ($upcomingGames as $game): ?>
                <div class="session-list-card" data-game-id="<?= (int)$game['id'] ?>">
                    <div class="session-date-column">
                        <div style="font-size:24px;font-weight:700;color:var(--primary-light);">
                            <?= date('d', strtotime($game['game_date'])) ?>
                        </div>
                        <div style="font-size:12px;color:var(--text-muted);text-transform:uppercase;">
                            <?= date('M', strtotime($game['game_date'])) ?>
                        </div>
                        <div style="font-size:11px;color:var(--text-muted);">
                            <?= date('g:i A', strtotime($game['game_date'])) ?>
                        </div>
                    </div>
                    <div class="session-details-column" style="flex:1;">
                        <div style="font-weight:600;color:var(--text-white);">
                            <?= htmlspecialchars($game['team_name'] ?? 'Our Team') ?>
                            vs
                            <?= htmlspecialchars($game['opponent_name'] ?? 'Opponent') ?>
                        </div>
                        <?php if (!empty($game['location'])): ?>
                            <div style="font-size:13px;color:var(--text-secondary);margin-top:4px;">
                                <i class="fas fa-map-marker-alt" style="margin-right:4px;"></i>
                                <?= htmlspecialchars($game['location']) ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<!-- Recent Clips Tagged In -->
<div class="card" style="margin-bottom:24px;">
    <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;">
        <h3><i class="fas fa-film"></i> Recent Clips You're Tagged In</h3>
        <?php if (!empty($recentClips)): ?>
            <a href="?page=my_clips" class="btn btn-sm btn-outline">View All</a>
        <?php endif; ?>
    </div>
    <div class="card-body">
        <?php if (empty($recentClips)): ?>
            <div class="empty-state-card">
                <div class="empty-icon"><i class="fas fa-film"></i></div>
                <p>No clips yet. You'll see clips here once a coach tags you in game footage.</p>
            </div>
        <?php else: ?>
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:16px;">
                <?php foreach ($recentClips as $clip): ?>
                    <div class="clip-card" data-clip-id="<?= (int)$clip['id'] ?>">
                        <div class="clip-thumbnail">
                            <?php if (!empty($clip['thumbnail_path'])): ?>
                                <img src="<?= htmlspecialchars($clip['thumbnail_path']) ?>" alt="Clip thumbnail" loading="lazy">
                            <?php else: ?>
                                <div style="display:flex;align-items:center;justify-content:center;height:100%;background:var(--bg-secondary);">
                                    <i class="fas fa-play-circle" style="font-size:32px;color:var(--primary-light);"></i>
                                </div>
                            <?php endif; ?>
                            <?php if (!empty($clip['duration'])): ?>
                                <span class="clip-duration"><?= formatDuration((int)$clip['duration']) ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="clip-meta">
                            <div style="font-weight:600;font-size:14px;color:var(--text-white);" class="truncate">
                                <?= htmlspecialchars($clip['title']) ?>
                            </div>
                            <div style="font-size:12px;color:var(--text-muted);margin-top:4px;">
                                <?php if (!empty($clip['camera_angle'])): ?>
                                    <span><i class="fas fa-video" style="margin-right:3px;"></i><?= htmlspecialchars($clip['camera_angle']) ?></span>
                                    &middot;
                                <?php endif; ?>
                                <?php if (!empty($clip['role_in_clip'])): ?>
                                    <span class="badge badge-primary" style="font-size:10px;"><?= htmlspecialchars($clip['role_in_clip']) ?></span>
                                    &middot;
                                <?php endif; ?>
                                <span><?= timeAgo($clip['created_at']) ?></span>
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
