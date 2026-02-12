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
        .sidebar-header-text { display: flex; flex-direction: column; }
        .sidebar-header h2 { font-size: 16px; font-weight: 600; white-space: nowrap; line-height: 1.2; }
        .sidebar-header h2 .header-separator { color: var(--primary-light); }
        .sidebar-subheader { font-size: 11px; color: var(--text-muted); text-transform: uppercase; letter-spacing: 1px; line-height: 1.4; }
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

        /* Top bar icon button */
        .top-bar-icon-btn {
            background: none; border: 1px solid var(--border); color: var(--text-secondary);
            width: 36px; height: 36px; border-radius: var(--radius-md); cursor: pointer;
            display: flex; align-items: center; justify-content: center; font-size: 15px;
            transition: all 0.2s ease;
        }
        .top-bar-icon-btn:hover { background: rgba(107,70,193,0.12); color: var(--text-white); border-color: var(--primary); }
    </style>
</head>
<body>

<!-- Sidebar overlay (mobile) -->
<div class="sidebar-overlay" id="sidebarOverlay"></div>

<!-- Sidebar -->
<aside class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <img src="css/arctic-wolves-logo.svg" alt="Arctic Wolves" width="36" height="36">
        <div class="sidebar-header-text">
            <h2>Arctic<span class="header-separator">_</span>Wolves</h2>
            <span class="sidebar-subheader">Game Plan</span>
        </div>
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
            <button class="top-bar-icon-btn" id="hwSettingsBtn" title="Hardware Acceleration Settings" aria-label="Hardware acceleration settings">
                <i class="fas fa-microchip"></i>
            </button>
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

<!-- Hardware Acceleration Settings Modal -->
<div class="modal-overlay" id="hwSettingsModal">
    <div class="modal" style="max-width:620px;">
        <div class="modal-header">
            <h3><i class="fas fa-microchip" style="color:var(--primary-light);margin-right:8px;"></i> Hardware Acceleration Settings</h3>
            <button class="modal-close" data-modal-close aria-label="Close"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body" id="hwSettingsBody">
            <!-- Populated by JS -->
            <div style="text-align:center;padding:32px;">
                <div class="spinner" style="margin:0 auto 12px;"></div>
                <p style="color:var(--text-muted);font-size:0.85rem;">Detecting hardware capabilities…</p>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" data-modal-close>Cancel</button>
            <button class="btn btn-primary" id="hwSettingsSave"><i class="fas fa-save"></i> Save Settings</button>
        </div>
    </div>
</div>

<script>
(function() {
    'use strict';

    var STORAGE_KEY = 'vr_hw_accel_settings';

    /* ── Default settings ─────────────────────────────────── */
    var defaults = {
        decodeMode: 'auto',       // auto | prefer-hardware | prefer-software
        renderMode: 'gpu',        // gpu | cpu
        gpuDevice: '',            // WebGPU adapter name or empty for default
        deinterlace: true,
        powerPreference: 'high-performance', // high-performance | low-power
        frameDropThreshold: 5,    // max % dropped frames before switching to SW
        videoSuperResolution: false,
        preferredCodec: 'auto'    // auto | h264 | h265 | vp9 | av1
    };

    /* ── Load / save ──────────────────────────────────────── */
    function loadSettings() {
        try {
            var raw = localStorage.getItem(STORAGE_KEY);
            if (raw) {
                var saved = JSON.parse(raw);
                var merged = {};
                for (var k in defaults) {
                    if (defaults.hasOwnProperty(k)) {
                        merged[k] = saved.hasOwnProperty(k) ? saved[k] : defaults[k];
                    }
                }
                return merged;
            }
        } catch (e) { /* ignore */ }
        return JSON.parse(JSON.stringify(defaults));
    }

    function saveSettings(settings) {
        try {
            localStorage.setItem(STORAGE_KEY, JSON.stringify(settings));
        } catch (e) { /* ignore */ }
    }

    /* ── Capability detection ─────────────────────────────── */
    function detectCapabilities(callback) {
        var caps = {
            webCodecs: typeof VideoDecoder !== 'undefined',
            webGPU: 'gpu' in navigator,
            requestVideoFrame: typeof HTMLVideoElement !== 'undefined' &&
                'requestVideoFrameCallback' in HTMLVideoElement.prototype,
            mediaCapabilities: 'mediaCapabilities' in navigator,
            gpuAdapters: [],
            supportedCodecs: [],
            renderer: '',
            vendor: ''
        };

        // GL renderer info
        try {
            var c = document.createElement('canvas');
            var gl = c.getContext('webgl2') || c.getContext('webgl');
            if (gl) {
                var dbg = gl.getExtension('WEBGL_debug_renderer_info');
                if (dbg) {
                    caps.renderer = gl.getParameter(dbg.UNMASKED_RENDERER_WEBGL) || '';
                    caps.vendor = gl.getParameter(dbg.UNMASKED_VENDOR_WEBGL) || '';
                }
            }
        } catch (e) { /* ignore */ }

        // Check codec support via MediaCapabilities
        var codecTests = [
            { label: 'H.264 (AVC)', codec: 'avc1.640033', type: 'video/mp4; codecs="avc1.640033"' },
            { label: 'H.265 (HEVC)', codec: 'hev1.1.6.L153.B0', type: 'video/mp4; codecs="hev1.1.6.L153.B0"' },
            { label: 'VP9', codec: 'vp09.00.10.08', type: 'video/webm; codecs="vp09.00.10.08"' },
            { label: 'AV1', codec: 'av01.0.08M.08', type: 'video/mp4; codecs="av01.0.08M.08"' }
        ];

        var pending = codecTests.length;
        var done = false;

        function finish() {
            if (done) return;
            done = true;
            callback(caps);
        }

        if (!caps.mediaCapabilities) {
            // Fallback: just check canPlayType
            codecTests.forEach(function(ct) {
                var v = document.createElement('video');
                var result = v.canPlayType(ct.type);
                if (result === 'probably' || result === 'maybe') {
                    caps.supportedCodecs.push({ label: ct.label, codec: ct.codec, hwAccel: 'unknown' });
                }
            });
            finish();
            return;
        }

        codecTests.forEach(function(ct) {
            navigator.mediaCapabilities.decodingInfo({
                type: 'media-source',
                video: {
                    contentType: ct.type,
                    width: 1920, height: 1080, bitrate: 20000000, framerate: 60
                }
            }).then(function(info) {
                if (info.supported) {
                    caps.supportedCodecs.push({
                        label: ct.label,
                        codec: ct.codec,
                        hwAccel: info.powerEfficient ? 'yes' : 'software',
                        smooth: info.smooth
                    });
                }
            }).catch(function() {
                // Fallback to canPlayType
                var v = document.createElement('video');
                if (v.canPlayType(ct.type)) {
                    caps.supportedCodecs.push({ label: ct.label, codec: ct.codec, hwAccel: 'unknown' });
                }
            }).finally(function() {
                pending--;
                if (pending <= 0) finish();
            });
        });

        // Timeout
        setTimeout(finish, 3000);
    }

    /* ── Render the settings panel ────────────────────────── */
    function renderSettings(container, caps, settings) {
        var isHW = caps.renderer && (
            /nvidia|geforce|rtx|gtx/i.test(caps.renderer) ||
            /radeon|amd/i.test(caps.renderer) ||
            /intel.*iris|intel.*uhd|intel.*hd/i.test(caps.renderer) ||
            /apple.*gpu|apple.*m[0-9]/i.test(caps.renderer)
        );

        var deviceName = caps.renderer || 'Unknown GPU';
        var vendorName = caps.vendor || '';

        var hwBadgeClass = isHW ? '' : 'unavailable';
        var hwLabel = isHW ? 'GPU Detected' : 'Software Rendering';
        var hwIcon = isHW ? 'fa-check-circle' : 'fa-exclamation-triangle';

        var html = '';

        /* ── Device info ── */
        html += '<div class="card" style="margin-bottom:16px;">';
        html += '<div class="card-header"><h4 style="font-size:0.9rem;"><i class="fas fa-desktop" style="color:var(--primary-light);margin-right:8px;"></i>Detected Device</h4>';
        html += '<span class="hw-accel-badge ' + hwBadgeClass + '"><i class="fas ' + hwIcon + '"></i> ' + escHtml(hwLabel) + '</span></div>';
        html += '<div class="card-body" style="padding:14px 18px;">';
        html += '<div class="analysis-metric"><span class="metric-label">GPU</span><span class="metric-value">' + escHtml(deviceName) + '</span></div>';
        if (vendorName) {
            html += '<div class="analysis-metric"><span class="metric-label">Vendor</span><span class="metric-value">' + escHtml(vendorName) + '</span></div>';
        }
        html += '<div class="analysis-metric"><span class="metric-label">WebCodecs API</span><span class="metric-value">' + (caps.webCodecs ? '<span style="color:var(--success);">Available</span>' : '<span style="color:var(--text-muted);">Not available</span>') + '</span></div>';
        html += '<div class="analysis-metric"><span class="metric-label">WebGPU</span><span class="metric-value">' + (caps.webGPU ? '<span style="color:var(--success);">Available</span>' : '<span style="color:var(--text-muted);">Not available</span>') + '</span></div>';
        html += '<div class="analysis-metric"><span class="metric-label">Frame Callback API</span><span class="metric-value">' + (caps.requestVideoFrame ? '<span style="color:var(--success);">Available</span>' : '<span style="color:var(--text-muted);">Not available</span>') + '</span></div>';
        html += '</div></div>';

        /* ── Decode mode ── */
        html += '<div style="margin-bottom:16px;">';
        html += '<label style="display:block;font-size:0.8rem;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.04em;margin-bottom:8px;">Video Decode Mode</label>';
        html += buildRadioGroup('decodeMode', [
            { value: 'auto', label: 'Auto', desc: 'Let the browser choose the best decoder' },
            { value: 'prefer-hardware', label: 'Prefer Hardware', desc: 'Use GPU decoding when available (lower CPU)' },
            { value: 'prefer-software', label: 'Prefer Software', desc: 'Force CPU decoding (more compatible)' }
        ], settings.decodeMode);
        html += '</div>';

        /* ── Render mode ── */
        html += '<div style="margin-bottom:16px;">';
        html += '<label style="display:block;font-size:0.8rem;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.04em;margin-bottom:8px;">Render Mode</label>';
        html += buildRadioGroup('renderMode', [
            { value: 'gpu', label: 'GPU Compositing', desc: 'Hardware-accelerated rendering (recommended)' },
            { value: 'cpu', label: 'CPU Rendering', desc: 'Software rendering (use if video glitches occur)' }
        ], settings.renderMode);
        html += '</div>';

        /* ── Power preference ── */
        html += '<div style="margin-bottom:16px;">';
        html += '<label style="display:block;font-size:0.8rem;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.04em;margin-bottom:8px;">Power Preference</label>';
        html += buildRadioGroup('powerPreference', [
            { value: 'high-performance', label: 'High Performance', desc: 'Use discrete GPU for best quality' },
            { value: 'low-power', label: 'Low Power', desc: 'Use integrated GPU to save battery' }
        ], settings.powerPreference);
        html += '</div>';

        /* ── Preferred codec ── */
        html += '<div style="margin-bottom:16px;">';
        html += '<label style="display:block;font-size:0.8rem;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.04em;margin-bottom:8px;">Preferred Codec</label>';
        html += '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:8px;">';
        var codecOpts = [{ value: 'auto', label: 'Auto', desc: 'Best available' }];
        caps.supportedCodecs.forEach(function(c) {
            var val = c.codec.split('.')[0].replace('avc1', 'h264').replace('hev1', 'h265').replace('vp09', 'vp9').replace('av01', 'av1');
            var accelTag = c.hwAccel === 'yes' ? ' <span class="hw-accel-badge" style="font-size:0.6rem;padding:1px 5px;"><i class="fas fa-bolt"></i> HW</span>'
                         : c.hwAccel === 'software' ? ' <span class="hw-accel-badge unavailable" style="font-size:0.6rem;padding:1px 5px;">SW</span>' : '';
            codecOpts.push({ value: val, label: c.label + accelTag, desc: c.smooth ? 'Smooth playback' : '' });
        });
        codecOpts.forEach(function(opt) {
            var active = settings.preferredCodec === opt.value ? ' active' : '';
            html += '<div class="codec-card' + active + '" data-setting="preferredCodec" data-value="' + escAttr(opt.value) + '">';
            html += '<div class="codec-icon"><i class="fas fa-film"></i></div>';
            html += '<div class="codec-info"><div class="codec-name">' + opt.label + '</div>';
            if (opt.desc) html += '<div class="codec-desc">' + escHtml(opt.desc) + '</div>';
            html += '</div></div>';
        });
        html += '</div></div>';

        /* ── Toggles ── */
        html += '<div style="margin-bottom:16px;">';
        html += '<label style="display:block;font-size:0.8rem;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.04em;margin-bottom:8px;">Advanced Options</label>';
        html += buildToggle('deinterlace', 'Deinterlace Video', 'Apply deinterlacing for interlaced sources (MTS/M2TS)', settings.deinterlace);
        html += buildToggle('videoSuperResolution', 'Video Super Resolution', 'Use GPU upscaling for low-res footage (experimental)', settings.videoSuperResolution);
        html += '</div>';

        /* ── Drop threshold ── */
        html += '<div style="margin-bottom:8px;">';
        html += '<label style="display:block;font-size:0.8rem;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.04em;margin-bottom:8px;">Frame Drop Threshold</label>';
        html += '<div style="display:flex;align-items:center;gap:12px;">';
        html += '<input type="range" class="form-input" id="hwFrameDropThreshold" min="1" max="20" value="' + settings.frameDropThreshold + '" style="flex:1;padding:0;height:6px;-webkit-appearance:none;appearance:none;background:var(--border);border-radius:3px;border:none;cursor:pointer;">';
        html += '<span id="hwFrameDropValue" style="font-size:0.85rem;font-weight:600;color:var(--text-white);min-width:32px;text-align:right;">' + settings.frameDropThreshold + '%</span>';
        html += '</div>';
        html += '<p style="font-size:0.75rem;color:var(--text-muted);margin-top:4px;">Auto-switch to software decode if dropped frames exceed this percentage.</p>';
        html += '</div>';

        container.innerHTML = html;

        /* ── Wire up interactions ── */
        // Radio groups
        container.querySelectorAll('[data-radio-name]').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var name = btn.getAttribute('data-radio-name');
                container.querySelectorAll('[data-radio-name="' + name + '"]').forEach(function(b) { b.classList.remove('active'); });
                btn.classList.add('active');
            });
        });

        // Codec cards
        container.querySelectorAll('.codec-card[data-setting]').forEach(function(card) {
            card.addEventListener('click', function() {
                container.querySelectorAll('.codec-card[data-setting="preferredCodec"]').forEach(function(c) { c.classList.remove('active'); });
                card.classList.add('active');
            });
        });

        // Toggles
        container.querySelectorAll('.hw-toggle').forEach(function(toggle) {
            toggle.addEventListener('click', function() {
                toggle.classList.toggle('active');
            });
        });

        // Range slider
        var slider = document.getElementById('hwFrameDropThreshold');
        var sliderVal = document.getElementById('hwFrameDropValue');
        if (slider && sliderVal) {
            slider.addEventListener('input', function() {
                sliderVal.textContent = slider.value + '%';
            });
        }
    }

    /* ── Collect settings from UI ─────────────────────────── */
    function collectSettings(container) {
        var s = {};
        ['decodeMode', 'renderMode', 'powerPreference'].forEach(function(name) {
            var active = container.querySelector('[data-radio-name="' + name + '"].active');
            s[name] = active ? active.getAttribute('data-radio-value') : defaults[name];
        });

        var codecCard = container.querySelector('.codec-card[data-setting="preferredCodec"].active');
        s.preferredCodec = codecCard ? codecCard.getAttribute('data-value') : 'auto';

        s.deinterlace = !!container.querySelector('.hw-toggle[data-toggle="deinterlace"].active');
        s.videoSuperResolution = !!container.querySelector('.hw-toggle[data-toggle="videoSuperResolution"].active');

        var slider = document.getElementById('hwFrameDropThreshold');
        s.frameDropThreshold = slider ? parseInt(slider.value, 10) : defaults.frameDropThreshold;

        s.gpuDevice = '';
        return s;
    }

    /* ── Apply settings to active video players ───────────── */
    function applySettings(settings) {
        document.querySelectorAll('.video-player').forEach(function(el) {
            if (!el._vrPlayer) return;
            var v = el._vrPlayer.video;
            if (settings.renderMode === 'gpu') {
                v.style.transform = 'translateZ(0)';
                v.style.willChange = 'transform';
            } else {
                v.style.transform = '';
                v.style.willChange = '';
            }
        });
    }

    /* ── HTML helpers ─────────────────────────────────────── */
    function escHtml(s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }
    function escAttr(s) { return String(s).replace(/"/g, '&quot;').replace(/'/g, '&#39;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); }

    function buildRadioGroup(name, options, currentValue) {
        var html = '<div style="display:flex;flex-direction:column;gap:6px;">';
        options.forEach(function(opt) {
            var active = currentValue === opt.value ? ' active' : '';
            html += '<div class="codec-card' + active + '" data-radio-name="' + escAttr(name) + '" data-radio-value="' + escAttr(opt.value) + '" style="cursor:pointer;">';
            html += '<div class="codec-icon"><i class="fas fa-' + (active ? 'dot-circle' : 'circle') + '"></i></div>';
            html += '<div class="codec-info"><div class="codec-name">' + escHtml(opt.label) + '</div>';
            if (opt.desc) html += '<div class="codec-desc">' + escHtml(opt.desc) + '</div>';
            html += '</div></div>';
        });
        html += '</div>';
        return html;
    }

    function buildToggle(key, label, desc, checked) {
        var active = checked ? ' active' : '';
        var html = '<div class="hw-toggle codec-card' + active + '" data-toggle="' + escAttr(key) + '" style="cursor:pointer;margin-bottom:6px;">';
        html += '<div class="codec-icon" style="background:' + (checked ? 'rgba(16,185,129,0.12)' : 'rgba(107,70,193,0.12)') + ';color:' + (checked ? 'var(--success)' : 'var(--text-muted)') + ';"><i class="fas fa-' + (checked ? 'toggle-on' : 'toggle-off') + '"></i></div>';
        html += '<div class="codec-info"><div class="codec-name">' + escHtml(label) + '</div>';
        if (desc) html += '<div class="codec-desc">' + escHtml(desc) + '</div>';
        html += '</div></div>';
        return html;
    }

    /* ── Wire up the modal ────────────────────────────────── */
    var settingsBtn = document.getElementById('hwSettingsBtn');
    var modalOverlay = document.getElementById('hwSettingsModal');
    var modalBody = document.getElementById('hwSettingsBody');
    var saveBtn = document.getElementById('hwSettingsSave');
    var detectedCaps = null;

    if (settingsBtn && modalOverlay) {
        settingsBtn.addEventListener('click', function() {
            modalOverlay.classList.add('active');
            if (!detectedCaps) {
                detectCapabilities(function(caps) {
                    detectedCaps = caps;
                    renderSettings(modalBody, caps, loadSettings());
                });
            } else {
                renderSettings(modalBody, detectedCaps, loadSettings());
            }
        });

        // Close handlers
        modalOverlay.addEventListener('click', function(e) {
            if (e.target === modalOverlay) modalOverlay.classList.remove('active');
        });
        modalOverlay.querySelectorAll('[data-modal-close]').forEach(function(btn) {
            btn.addEventListener('click', function() { modalOverlay.classList.remove('active'); });
        });

        // Save
        if (saveBtn) {
            saveBtn.addEventListener('click', function() {
                var newSettings = collectSettings(modalBody);
                saveSettings(newSettings);
                applySettings(newSettings);
                modalOverlay.classList.remove('active');
            });
        }
    }

    // Apply persisted settings on page load
    applySettings(loadSettings());

    // Expose for use by video-player.js
    window.HWAccelSettings = {
        load: loadSettings,
        save: saveSettings,
        apply: applySettings,
        detect: detectCapabilities
    };
})();
</script>
</body>
</html>
