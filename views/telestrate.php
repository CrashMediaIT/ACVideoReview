<?php
/**
 * Telestration View
 * Full-screen video player with canvas drawing overlay
 * Supports both viewer and controller modes
 * Variables available: $pdo, $user_id, $user_role, $team_id, $user_name, $isCoach, $isAdmin, $csrf_token
 */

$role = isset($_GET['role']) ? sanitizeInput($_GET['role']) : 'viewer';
if (!in_array($role, ['viewer', 'controller'], true)) {
    $role = 'viewer';
}

$sessionId = isset($_GET['session']) ? (int)$_GET['session'] : 0;
$clipId = isset($_GET['clip_id']) ? (int)$_GET['clip_id'] : 0;
$sourceId = isset($_GET['source_id']) ? (int)$_GET['source_id'] : 0;

$videoSrc = '';
$videoTitle = 'Telestration Session';
$sessionCode = '';

if (defined('DB_CONNECTED') && DB_CONNECTED && $pdo) {
    // Load video from clip or source
    if ($clipId) {
        try {
            $stmt = dbQuery($pdo,
                "SELECT vc.title, vc.clip_file_path,
                        vs.file_path AS source_path, vs.file_url AS source_url
                 FROM vr_video_clips vc
                 LEFT JOIN vr_video_sources vs ON vs.id = vc.source_video_id
                 WHERE vc.id = :cid",
                [':cid' => $clipId]
            );
            $clip = $stmt->fetch();
            if ($clip) {
                $videoTitle = $clip['title'];
                $videoSrc = $clip['clip_file_path'] ?: $clip['source_url'] ?: $clip['source_path'] ?: '';
            }
        } catch (PDOException $e) {
            error_log('Telestrate - clip error: ' . $e->getMessage());
        }
    } elseif ($sourceId) {
        try {
            $stmt = dbQuery($pdo,
                "SELECT title, file_path, file_url FROM vr_video_sources WHERE id = :sid",
                [':sid' => $sourceId]
            );
            $source = $stmt->fetch();
            if ($source) {
                $videoTitle = $source['title'];
                $videoSrc = $source['file_url'] ?: $source['file_path'] ?: '';
            }
        } catch (PDOException $e) {
            error_log('Telestrate - source error: ' . $e->getMessage());
        }
    }

    // Load session code
    if ($sessionId) {
        try {
            $stmt = dbQuery($pdo,
                "SELECT session_code FROM vr_device_sessions WHERE id = :sid AND user_id = :uid",
                [':sid' => $sessionId, ':uid' => $user_id]
            );
            $session = $stmt->fetch();
            if ($session) {
                $sessionCode = $session['session_code'];
            }
        } catch (PDOException $e) {
            error_log('Telestrate - session error: ' . $e->getMessage());
        }
    }
}

$toolOptions = [
    'freehand'      => ['icon' => 'fa-pencil-alt', 'label' => 'Freehand'],
    'arrow'         => ['icon' => 'fa-long-arrow-alt-right', 'label' => 'Arrow'],
    'line'          => ['icon' => 'fa-minus', 'label' => 'Line'],
    'rectangle'     => ['icon' => 'fa-square', 'label' => 'Rectangle'],
    'circle'        => ['icon' => 'fa-circle', 'label' => 'Circle'],
    'text'          => ['icon' => 'fa-font', 'label' => 'Text'],
    'player_marker' => ['icon' => 'fa-user', 'label' => 'Player'],
];

$colorOptions = [
    '#EF4444' => 'Red',
    '#F59E0B' => 'Yellow',
    '#10B981' => 'Green',
    '#3B82F6' => 'Blue',
    '#8B5CF6' => 'Purple',
    '#FFFFFF' => 'White',
];
?>

<style>
    .telestrate-wrapper {
        position: relative;
        width: 100%;
        max-width: 1200px;
        margin: 0 auto;
    }
    .telestrate-video-container {
        position: relative;
        width: 100%;
        background: #000;
        border-radius: var(--radius-lg);
        overflow: hidden;
    }
    .telestrate-video-container video {
        width: 100%;
        display: block;
    }
    .telestrate-canvas-overlay {
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        pointer-events: none;
        z-index: 10;
    }
    .telestrate-canvas-overlay.drawing-active {
        pointer-events: auto;
        cursor: crosshair;
    }
    .telestrate-toolbar {
        display: flex;
        gap: 8px;
        padding: 12px;
        background: var(--bg-secondary);
        border-radius: var(--radius-md);
        margin-top: 12px;
        flex-wrap: wrap;
        align-items: center;
    }
    .tool-btn {
        width: 36px;
        height: 36px;
        border-radius: var(--radius-sm);
        border: 1px solid var(--border);
        background: var(--bg-card);
        color: var(--text-secondary);
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        transition: all var(--transition);
        font-size: 14px;
    }
    .tool-btn:hover {
        border-color: var(--primary);
        color: var(--text-white);
    }
    .tool-btn.active {
        background: var(--primary);
        border-color: var(--primary);
        color: #fff;
    }
    .color-swatch {
        width: 24px;
        height: 24px;
        border-radius: 50%;
        border: 2px solid transparent;
        cursor: pointer;
        transition: border-color var(--transition);
    }
    .color-swatch:hover,
    .color-swatch.active {
        border-color: var(--text-white);
    }
    .toolbar-separator {
        width: 1px;
        height: 28px;
        background: var(--border);
        margin: 0 4px;
    }
    .playback-controls {
        display: flex;
        gap: 8px;
        padding: 12px;
        background: var(--bg-secondary);
        border-radius: var(--radius-md);
        margin-top: 8px;
        align-items: center;
    }
    .playback-controls .time-display {
        font-family: monospace;
        font-size: 13px;
        color: var(--text-secondary);
        min-width: 100px;
        text-align: center;
    }
    .playback-controls .timeline-bar {
        flex: 1;
        height: 6px;
        background: var(--border);
        border-radius: 3px;
        position: relative;
        cursor: pointer;
    }
    .playback-controls .timeline-progress {
        height: 100%;
        background: var(--primary);
        border-radius: 3px;
        width: 0%;
        transition: width 0.1s linear;
    }
</style>

<!-- Page Header -->
<div class="page-header">
    <div class="page-header-icon"><i class="fas fa-pencil-alt"></i></div>
    <div class="page-header-info">
        <h1 class="page-title">Telestration</h1>
        <p class="page-description"><?= htmlspecialchars($videoTitle) ?></p>
    </div>
    <div style="display:flex;gap:8px;margin-left:auto;">
        <?php if ($sessionCode): ?>
            <span class="badge badge-info" style="font-size:14px;padding:8px 16px;font-family:monospace;letter-spacing:2px;">
                <i class="fas fa-link"></i> <?= htmlspecialchars($sessionCode) ?>
            </span>
        <?php endif; ?>
        <span class="badge <?= $role === 'viewer' ? 'badge-primary' : 'badge-success' ?>" style="font-size:12px;padding:8px 12px;">
            <i class="fas fa-<?= $role === 'viewer' ? 'tv' : 'gamepad' ?>"></i>
            <?= ucfirst($role) ?>
        </span>
    </div>
</div>

<!-- Video + Canvas -->
<div class="telestrate-wrapper">
    <div class="telestrate-video-container" id="telestrateVideoContainer">
        <video class="video-player" id="telestrateVideo" controls <?= $videoSrc ? 'src="' . htmlspecialchars($videoSrc) . '"' : '' ?>>
            Your browser does not support HTML5 video.
        </video>
        <canvas class="telestrate-canvas-overlay" id="telestrateCanvas"></canvas>
    </div>

    <!-- Drawing Toolbar (Controller or combined) -->
    <?php if ($role === 'controller' || $role === 'viewer'): ?>
    <div class="telestrate-toolbar" id="drawingToolbar">
        <!-- Drawing toggle -->
        <button class="tool-btn" id="toggleDrawingBtn" title="Toggle Drawing Mode" data-action="toggle-drawing">
            <i class="fas fa-pencil-alt"></i>
        </button>

        <div class="toolbar-separator"></div>

        <!-- Drawing tools -->
        <?php foreach ($toolOptions as $toolId => $tool): ?>
            <button class="tool-btn <?= $toolId === 'freehand' ? 'active' : '' ?>"
                    data-tool="<?= $toolId ?>" title="<?= htmlspecialchars($tool['label']) ?>">
                <i class="fas <?= $tool['icon'] ?>"></i>
            </button>
        <?php endforeach; ?>

        <div class="toolbar-separator"></div>

        <!-- Colors -->
        <?php foreach ($colorOptions as $hex => $name): ?>
            <div class="color-swatch <?= $hex === '#EF4444' ? 'active' : '' ?>"
                 data-color="<?= $hex ?>"
                 title="<?= htmlspecialchars($name) ?>"
                 style="background:<?= $hex ?>;"></div>
        <?php endforeach; ?>

        <div class="toolbar-separator"></div>

        <!-- Line width -->
        <select id="lineWidthSelect" class="form-select" style="width:70px;padding:4px 8px;font-size:12px;" data-field="line-width">
            <option value="2">Thin</option>
            <option value="3" selected>Medium</option>
            <option value="5">Thick</option>
            <option value="8">Extra</option>
        </select>

        <div class="toolbar-separator"></div>

        <!-- Actions -->
        <button class="tool-btn" id="undoBtn" title="Undo" data-action="undo">
            <i class="fas fa-undo"></i>
        </button>
        <button class="tool-btn" id="redoBtn" title="Redo" data-action="redo">
            <i class="fas fa-redo"></i>
        </button>
        <button class="tool-btn" id="clearBtn" title="Clear All" data-action="clear" style="color:var(--error);">
            <i class="fas fa-trash"></i>
        </button>
        <button class="tool-btn" id="saveAnnotationBtn" title="Save Annotation" data-action="save-annotation" style="color:var(--success);">
            <i class="fas fa-save"></i>
        </button>
    </div>
    <?php endif; ?>

    <!-- Playback Controls -->
    <div class="playback-controls">
        <button class="tool-btn" data-player-play data-action="play-pause" id="playPauseBtn">
            <i class="fas fa-play"></i>
        </button>
        <button class="tool-btn" title="Frame Back (,)" data-action="frame-back" id="frameBackBtn">
            <i class="fas fa-step-backward"></i>
        </button>
        <button class="tool-btn" title="Frame Forward (.)" data-action="frame-forward" id="frameForwardBtn">
            <i class="fas fa-step-forward"></i>
        </button>

        <span class="time-display" id="currentTimeDisplay">00:00:00.000</span>

        <div class="timeline-bar" id="timelineBar">
            <div class="timeline-progress" id="timelineProgress"></div>
        </div>

        <span class="time-display" data-video-duration>00:00:00.000</span>

        <select class="form-select" id="speedSelect" style="width:70px;padding:4px 8px;font-size:12px;" data-field="speed">
            <option value="0.25">0.25x</option>
            <option value="0.5">0.5x</option>
            <option value="1" selected>1x</option>
            <option value="1.5">1.5x</option>
            <option value="2">2x</option>
        </select>

        <button class="tool-btn" title="Fullscreen (F)" data-action="fullscreen" id="fullscreenBtn">
            <i class="fas fa-expand"></i>
        </button>
    </div>
</div>

<!-- Back to Device Pairing -->
<div style="margin-top:16px;">
    <a href="?page=pair_device" class="btn btn-secondary btn-sm">
        <i class="fas fa-arrow-left"></i> Back to Device Pairing
    </a>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var video = document.getElementById('telestrateVideo');
    var canvas = document.getElementById('telestrateCanvas');
    var telestration = null;
    var drawingActive = false;

    // Initialize Telestration if available
    if (typeof Telestration !== 'undefined' && canvas) {
        telestration = new Telestration(canvas, {
            referenceWidth: 1920,
            referenceHeight: 1080
        });
    }

    // Toggle drawing mode
    var toggleBtn = document.getElementById('toggleDrawingBtn');
    if (toggleBtn) {
        toggleBtn.addEventListener('click', function() {
            drawingActive = !drawingActive;
            canvas.classList.toggle('drawing-active', drawingActive);
            this.classList.toggle('active', drawingActive);
        });
    }

    // Tool selection
    document.querySelectorAll('[data-tool]').forEach(function(btn) {
        btn.addEventListener('click', function() {
            document.querySelectorAll('[data-tool]').forEach(function(b) { b.classList.remove('active'); });
            this.classList.add('active');
            if (telestration) telestration.setTool(this.dataset.tool);

            // Activate drawing mode when selecting a tool
            if (!drawingActive) {
                drawingActive = true;
                canvas.classList.add('drawing-active');
                if (toggleBtn) toggleBtn.classList.add('active');
            }
        });
    });

    // Color selection
    document.querySelectorAll('[data-color]').forEach(function(swatch) {
        swatch.addEventListener('click', function() {
            document.querySelectorAll('[data-color]').forEach(function(s) { s.classList.remove('active'); });
            this.classList.add('active');
            if (telestration) telestration.setColor(this.dataset.color);
        });
    });

    // Line width
    var lineWidthSelect = document.getElementById('lineWidthSelect');
    if (lineWidthSelect) {
        lineWidthSelect.addEventListener('change', function() {
            if (telestration) telestration.setLineWidth(parseInt(this.value));
        });
    }

    // Undo / Redo / Clear
    var undoBtn = document.getElementById('undoBtn');
    var redoBtn = document.getElementById('redoBtn');
    var clearBtn = document.getElementById('clearBtn');
    if (undoBtn) undoBtn.addEventListener('click', function() { if (telestration) telestration.undo(); });
    if (redoBtn) redoBtn.addEventListener('click', function() { if (telestration) telestration.redo(); });
    if (clearBtn) clearBtn.addEventListener('click', function() { if (telestration) telestration.clear(); });

    // Save annotation
    var saveBtn = document.getElementById('saveAnnotationBtn');
    if (saveBtn) {
        saveBtn.addEventListener('click', function() {
            if (!telestration) return;
            var data = telestration.exportData();
            var currentTime = video ? video.currentTime : 0;

            var formData = new FormData();
            formData.append('csrf_token', '<?= htmlspecialchars($csrf_token) ?>');
            formData.append('canvas_data', data);
            formData.append('video_time', currentTime);
            <?php if ($clipId): ?>
            formData.append('clip_id', '<?= $clipId ?>');
            <?php endif; ?>
            <?php if ($sourceId): ?>
            formData.append('source_video_id', '<?= $sourceId ?>');
            <?php endif; ?>

            fetch('api/telestration_save.php', { method: 'POST', body: formData })
                .then(function(r) { return r.json(); })
                .then(function(res) {
                    if (res.success) {
                        saveBtn.innerHTML = '<i class="fas fa-check"></i>';
                        setTimeout(function() {
                            saveBtn.innerHTML = '<i class="fas fa-save"></i>';
                        }, 2000);
                    }
                })
                .catch(function() { /* silent */ });
        });
    }

    // Video Player Controls
    var player = video && video._vrPlayer ? video._vrPlayer : null;

    var playPauseBtn = document.getElementById('playPauseBtn');
    if (playPauseBtn && video) {
        playPauseBtn.addEventListener('click', function() {
            if (player) { player.togglePlay(); } else { video.paused ? video.play() : video.pause(); }
        });
    }

    var frameBackBtn = document.getElementById('frameBackBtn');
    if (frameBackBtn) frameBackBtn.addEventListener('click', function() {
        if (player) { player.frameStep(false); } else if (video) { video.pause(); video.currentTime = Math.max(0, video.currentTime - (1/30)); }
    });

    var frameForwardBtn = document.getElementById('frameForwardBtn');
    if (frameForwardBtn) frameForwardBtn.addEventListener('click', function() {
        if (player) { player.frameStep(true); } else if (video) { video.pause(); video.currentTime = Math.min(video.duration, video.currentTime + (1/30)); }
    });

    var speedSelect = document.getElementById('speedSelect');
    if (speedSelect && video) {
        speedSelect.addEventListener('change', function() {
            var rate = parseFloat(this.value);
            if (player) { player.setPlaybackRate(rate); } else { video.playbackRate = rate; }
        });
    }

    var fullscreenBtn = document.getElementById('fullscreenBtn');
    if (fullscreenBtn) {
        fullscreenBtn.addEventListener('click', function() {
            var container = document.getElementById('telestrateVideoContainer');
            if (document.fullscreenElement) {
                document.exitFullscreen();
            } else if (container) {
                container.requestFullscreen();
            }
        });
    }

    // Timeline bar click to seek
    var timelineBar = document.getElementById('timelineBar');
    if (timelineBar && video) {
        timelineBar.addEventListener('click', function(e) {
            var rect = this.getBoundingClientRect();
            var pct = (e.clientX - rect.left) / rect.width;
            video.currentTime = pct * (video.duration || 0);
        });
    }

    // Time update display
    if (video) {
        video.addEventListener('timeupdate', function() {
            var currentEl = document.getElementById('currentTimeDisplay');
            if (currentEl && typeof window.formatTimecode === 'function') {
                currentEl.textContent = window.formatTimecode(video.currentTime);
            }
            var progressEl = document.getElementById('timelineProgress');
            if (progressEl && video.duration) {
                progressEl.style.width = ((video.currentTime / video.duration) * 100) + '%';
            }
        });

        video.addEventListener('play', function() {
            var icon = playPauseBtn ? playPauseBtn.querySelector('i') : null;
            if (icon) icon.className = 'fas fa-pause';
        });

        video.addEventListener('pause', function() {
            var icon = playPauseBtn ? playPauseBtn.querySelector('i') : null;
            if (icon) icon.className = 'fas fa-play';
        });
    }
});
</script>
