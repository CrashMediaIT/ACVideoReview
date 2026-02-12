<?php
// ACVideoReview - Dashboard Controller
require_once __DIR__ . '/config/app.php';
initSession();

require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/security.php';

requireAuth();
setSecurityHeaders();

// User info from session
$user_id    = $_SESSION['user_id'];
$user_name  = $_SESSION['user_name']  ?? 'User';
$user_role  = $_SESSION['role']       ?? 'athlete';
$user_avatar = $_SESSION['avatar']    ?? '';
$team_id    = $_SESSION['team_id']    ?? null;

$isCoach = in_array($user_role, COACH_ROLES, true);
$isAdmin = in_array($user_role, ADMIN_ROLES, true);

// Page routing
$page = isset($_GET['page']) ? sanitizeInput($_GET['page']) : 'home';

// Notification count
$unreadNotifications = 0;
if (DB_CONNECTED && $pdo) {
    try {
        $stmt = dbQuery($pdo, "SELECT COUNT(*) as unread_count FROM vr_notifications WHERE user_id = :uid AND is_read = 0", [':uid' => $user_id]);
        $unreadNotifications = (int)($stmt->fetch()['unread_count'] ?? 0);
    } catch (PDOException $e) {
        error_log('Notification count error: ' . $e->getMessage());
    }
}

$csrf_token = generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?= htmlspecialchars(APP_NAME) ?></title>

    <!-- PWA -->
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="theme-color" content="#0A0A0F">
    <link rel="manifest" href="manifest.json">

    <!-- Styles -->
    <link rel="stylesheet" href="css/style-guide.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <style>
        /* Layout */
        body { display: flex; min-height: 100vh; background: var(--bg-main); color: var(--text-white); font-family: var(--font-family); }

        /* Sidebar */
        .sidebar {
            width: 260px; min-height: 100vh; background: var(--bg-secondary);
            border-right: 1px solid var(--border); display: flex; flex-direction: column;
            position: fixed; top: 0; left: 0; z-index: 100; transition: transform var(--transition);
        }
        .sidebar-header { padding: 20px; border-bottom: 1px solid var(--border); display: flex; align-items: center; gap: 12px; }
        .sidebar-header img { width: 36px; height: 36px; }
        .sidebar-header h2 { font-size: 16px; font-weight: 600; white-space: nowrap; }
        .sidebar-nav { flex: 1; padding: 12px 0; overflow-y: auto; }
        .sidebar-nav a {
            display: flex; align-items: center; gap: 12px; padding: 12px 20px;
            color: var(--text-secondary); text-decoration: none; font-size: 14px;
            transition: all var(--transition); border-left: 3px solid transparent;
        }
        .sidebar-nav a:hover, .sidebar-nav a.active { color: var(--text-white); background: rgba(107,70,193,0.1); border-left-color: var(--primary); }
        .sidebar-nav a i { width: 20px; text-align: center; font-size: 16px; }
        .sidebar-nav .nav-divider { height: 1px; background: var(--border); margin: 8px 20px; }
        .sidebar-footer { padding: 16px 20px; border-top: 1px solid var(--border); }
        .sidebar-footer a { display: flex; align-items: center; gap: 10px; color: var(--text-muted); text-decoration: none; font-size: 13px; }
        .sidebar-footer a:hover { color: var(--text-white); }

        /* Top bar */
        .main-wrapper { flex: 1; margin-left: 260px; display: flex; flex-direction: column; }
        .top-bar {
            height: 60px; background: var(--bg-secondary); border-bottom: 1px solid var(--border);
            display: flex; align-items: center; justify-content: space-between; padding: 0 24px;
            position: sticky; top: 0; z-index: 90;
        }
        .top-bar-left { display: flex; align-items: center; gap: 12px; }
        .hamburger { display: none; background: none; border: none; color: var(--text-white); font-size: 20px; cursor: pointer; }
        .top-bar-right { display: flex; align-items: center; gap: 16px; }
        .notification-bell { position: relative; color: var(--text-secondary); font-size: 18px; cursor: pointer; }
        .notification-bell .badge {
            position: absolute; top: -6px; right: -8px; background: var(--error);
            color: #fff; font-size: 10px; padding: 1px 5px; border-radius: 10px;
        }
        .user-info { display: flex; align-items: center; gap: 10px; }
        .user-info .avatar {
            width: 32px; height: 32px; border-radius: 50%; background: var(--primary);
            display: flex; align-items: center; justify-content: center; font-weight: 600; font-size: 14px;
            overflow: hidden;
        }
        .user-info .avatar img { width: 100%; height: 100%; object-fit: cover; }
        .user-info .name { font-size: 14px; font-weight: 500; }
        .user-info .role { font-size: 11px; color: var(--text-muted); text-transform: capitalize; }

        /* Content */
        .content { flex: 1; padding: 24px; }

        /* Mobile bottom nav */
        .bottom-nav {
            display: none; position: fixed; bottom: 0; left: 0; right: 0;
            background: var(--bg-secondary); border-top: 1px solid var(--border);
            z-index: 100; padding: 6px 0; padding-bottom: env(safe-area-inset-bottom);
        }
        .bottom-nav-inner { display: flex; justify-content: space-around; }
        .bottom-nav a {
            display: flex; flex-direction: column; align-items: center; gap: 2px;
            color: var(--text-muted); text-decoration: none; font-size: 10px; padding: 4px 8px;
        }
        .bottom-nav a.active { color: var(--primary-light); }
        .bottom-nav a i { font-size: 18px; }

        /* Responsive */
        @media (max-width: 768px) {
            .sidebar { transform: translateX(-100%); }
            .sidebar.open { transform: translateX(0); }
            .main-wrapper { margin-left: 0; }
            .hamburger { display: block; }
            .bottom-nav { display: block; }
            .content { padding: 16px; padding-bottom: 80px; }
        }

        /* Sidebar overlay on mobile */
        .sidebar-overlay {
            display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 99;
        }
        .sidebar-overlay.active { display: block; }
    </style>
</head>
<body>

<!-- Sidebar overlay (mobile) -->
<div class="sidebar-overlay" id="sidebarOverlay"></div>

<!-- Sidebar -->
<aside class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <i class="fas fa-play-circle" style="font-size:28px;color:var(--primary-light);"></i>
        <h2>Video Review</h2>
    </div>

    <nav class="sidebar-nav">
        <?php if (!$isCoach): // Athlete navigation ?>
            <a href="?page=home" class="<?= $page === 'home' ? 'active' : '' ?>">
                <i class="fas fa-home"></i> Dashboard
            </a>
            <a href="?page=video_review" class="<?= $page === 'video_review' ? 'active' : '' ?>">
                <i class="fas fa-film"></i> Video Review
            </a>
            <a href="?page=my_clips" class="<?= $page === 'my_clips' ? 'active' : '' ?>">
                <i class="fas fa-cut"></i> My Clips
            </a>
        <?php else: // Coach / Admin navigation ?>
            <a href="?page=home" class="<?= $page === 'home' ? 'active' : '' ?>">
                <i class="fas fa-home"></i> Dashboard
            </a>
            <a href="?page=calendar" class="<?= $page === 'calendar' ? 'active' : '' ?>">
                <i class="fas fa-calendar-alt"></i> Calendar
            </a>
            <a href="?page=video_review" class="<?= $page === 'video_review' ? 'active' : '' ?>">
                <i class="fas fa-film"></i> Video Review
            </a>
            <a href="?page=game_plan" class="<?= $page === 'game_plan' ? 'active' : '' ?>">
                <i class="fas fa-chess"></i> Game Plan
            </a>
            <a href="?page=film_room" class="<?= $page === 'film_room' ? 'active' : '' ?>">
                <i class="fas fa-video"></i> Film Room
            </a>
            <a href="?page=review_sessions" class="<?= $page === 'review_sessions' ? 'active' : '' ?>">
                <i class="fas fa-chalkboard-teacher"></i> Review Sessions
            </a>
            <?php if ($isAdmin): ?>
                <div class="nav-divider"></div>
                <a href="?page=permissions" class="<?= $page === 'permissions' ? 'active' : '' ?>">
                    <i class="fas fa-user-shield"></i> Permissions
                </a>
            <?php endif; ?>
        <?php endif; ?>
    </nav>

    <div class="sidebar-footer">
        <a href="<?= htmlspecialchars(MAIN_APP_URL) ?>">
            <i class="fas fa-arrow-left"></i> Back to Main App
        </a>
    </div>
</aside>

<!-- Main content wrapper -->
<div class="main-wrapper">

    <!-- Top bar -->
    <header class="top-bar">
        <div class="top-bar-left">
            <button class="hamburger" id="hamburgerBtn" aria-label="Toggle menu">
                <i class="fas fa-bars"></i>
            </button>
            <span style="font-size:14px;color:var(--text-secondary);">
                <?= htmlspecialchars(APP_NAME) ?>
            </span>
        </div>
        <div class="top-bar-right">
            <div class="notification-bell" title="Notifications">
                <i class="fas fa-bell"></i>
                <?php if ($unreadNotifications > 0): ?>
                    <span class="badge"><?= $unreadNotifications ?></span>
                <?php endif; ?>
            </div>
            <div class="user-info">
                <div class="avatar">
                    <?php if ($user_avatar): ?>
                        <img src="<?= htmlspecialchars($user_avatar) ?>" alt="Avatar">
                    <?php else: ?>
                        <?= strtoupper(substr($user_name, 0, 1)) ?>
                    <?php endif; ?>
                </div>
                <div>
                    <div class="name"><?= htmlspecialchars($user_name) ?></div>
                    <div class="role"><?= htmlspecialchars(str_replace('_', ' ', $user_role)) ?></div>
                </div>
            </div>
        </div>
    </header>

    <!-- Page content -->
    <main class="content">
        <?php
        switch ($page) {
            case 'home':
                if ($isCoach) {
                    include __DIR__ . '/views/home_coach.php';
                } else {
                    include __DIR__ . '/views/home_athlete.php';
                }
                break;
            case 'video_review':
                include __DIR__ . '/views/video_review.php';
                break;
            case 'my_clips':
                include __DIR__ . '/views/my_clips.php';
                break;
            case 'calendar':
                include __DIR__ . '/views/calendar.php';
                break;
            case 'game_plan':
                include __DIR__ . '/views/game_plan.php';
                break;
            case 'film_room':
                include __DIR__ . '/views/film_room.php';
                break;
            case 'review_sessions':
                include __DIR__ . '/views/review_sessions.php';
                break;
            case 'permissions':
                include __DIR__ . '/views/permissions.php';
                break;
            case 'telestrate':
                include __DIR__ . '/views/telestrate.php';
                break;
            case 'pair_device':
                include __DIR__ . '/views/pair_device.php';
                break;
            default:
                if ($isCoach) {
                    include __DIR__ . '/views/home_coach.php';
                } else {
                    include __DIR__ . '/views/home_athlete.php';
                }
                break;
        }
        ?>
    </main>

</div><!-- /.main-wrapper -->

<!-- Mobile bottom navigation -->
<nav class="bottom-nav">
    <div class="bottom-nav-inner">
        <a href="?page=home" class="<?= $page === 'home' ? 'active' : '' ?>">
            <i class="fas fa-home"></i> Home
        </a>
        <?php if ($isCoach): ?>
            <a href="?page=calendar" class="<?= $page === 'calendar' ? 'active' : '' ?>">
                <i class="fas fa-calendar-alt"></i> Calendar
            </a>
        <?php endif; ?>
        <a href="?page=video_review" class="<?= $page === 'video_review' ? 'active' : '' ?>">
            <i class="fas fa-film"></i> Video
        </a>
        <?php if ($isCoach): ?>
            <a href="?page=game_plan" class="<?= $page === 'game_plan' ? 'active' : '' ?>">
                <i class="fas fa-chess"></i> Plan
            </a>
        <?php else: ?>
            <a href="?page=my_clips" class="<?= $page === 'my_clips' ? 'active' : '' ?>">
                <i class="fas fa-cut"></i> Clips
            </a>
        <?php endif; ?>
    </div>
</nav>

<!-- JavaScript -->
<script src="js/app.js"></script>
<?php if (in_array($page, ['video_review', 'film_room', 'my_clips'], true)): ?>
    <script src="js/video-player.js"></script>
<?php endif; ?>
<?php if ($page === 'telestrate'): ?>
    <script src="js/telestration.js"></script>
<?php endif; ?>
<?php if (in_array($page, ['pair_device', 'telestrate'], true)): ?>
    <script src="js/device-sync.js"></script>
<?php endif; ?>

<script>
// Sidebar toggle for mobile
const hamburgerBtn = document.getElementById('hamburgerBtn');
const sidebar = document.getElementById('sidebar');
const overlay = document.getElementById('sidebarOverlay');

hamburgerBtn.addEventListener('click', () => {
    sidebar.classList.toggle('open');
    overlay.classList.toggle('active');
});
overlay.addEventListener('click', () => {
    sidebar.classList.remove('open');
    overlay.classList.remove('active');
});
</script>
</body>
</html>
