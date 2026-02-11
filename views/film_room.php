<?php
/**
 * Film Room - Video Upload & Clip Editor
 * Variables available: $pdo, $user_id, $user_role, $team_id, $user_name, $isCoach, $isAdmin, $csrf_token
 */

$activeTab = isset($_GET['tab']) ? sanitizeInput($_GET['tab']) : 'upload';
$validTabs = ['upload', 'clip_editor', 'multi_camera'];
if (!in_array($activeTab, $validTabs, true)) {
    $activeTab = 'upload';
}

$videos = [];
$teams = [];
$upcomingGames = [];
$selectedSourceId = isset($_GET['source_id']) ? (int)$_GET['source_id'] : 0;
$selectedGameFilter = isset($_GET['game_filter']) ? (int)$_GET['game_filter'] : 0;
$sourceVideo = null;
$sourceClips = [];
$allTags = [];
$tagCategories = [];
$rosterPlayers = [];
$multiCameraVideos = [];

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

$videoStatusBadge = [
    'uploading'  => 'badge-warning',
    'processing' => 'badge-info',
    'ready'      => 'badge-success',
    'error'      => 'badge-danger',
];

$cameraAngleLabels = [
    'wide'       => 'Wide',
    'tactical'   => 'Tactical',
    'behind_net' => 'Behind Net',
    'bench'      => 'Bench',
    'ice_level'  => 'Ice Level',
];

$categoryLabels = [
    'zone'      => 'Zone',
    'skill'     => 'Skill',
    'situation' => 'Situation',
    'custom'    => 'Custom',
];

if (defined('DB_CONNECTED') && DB_CONNECTED && $pdo) {
    // Fetch teams
    try {
        $stmt = dbQuery($pdo,
            "SELECT t.id, t.team_name
             FROM teams t
             INNER JOIN team_coach_assignments tca ON tca.team_id = t.id
             WHERE tca.coach_id = :uid
             ORDER BY t.team_name",
            [':uid' => $user_id]
        );
        $teams = $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log('Film room - teams error: ' . $e->getMessage());
    }

    // Fetch games for dropdown
    try {
        $stmt = dbQuery($pdo,
            "SELECT gs.id, gs.game_date, gs.location,
                    t.team_name, t2.team_name AS opponent_name
             FROM game_schedules gs
             LEFT JOIN teams t ON t.id = gs.team_id
             LEFT JOIN teams t2 ON t2.id = gs.opponent_team_id
             WHERE gs.team_id IN (SELECT team_id FROM team_coach_assignments WHERE coach_id = :uid)
             ORDER BY gs.game_date DESC
             LIMIT 100",
            [':uid' => $user_id]
        );
        $upcomingGames = $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log('Film room - games error: ' . $e->getMessage());
    }

    // Fetch uploaded videos (with NDI camera name if ndi_cameras table exists)
    try {
        $stmt = dbQuery($pdo,
            "SELECT vs.id, vs.title, vs.description, vs.camera_angle, vs.duration_seconds,
                    vs.thumbnail_path, vs.status, vs.file_path, vs.file_url, vs.file_size,
                    vs.format, vs.resolution, vs.source_type, vs.ndi_camera_id, vs.recorded_at,
                    vs.created_at, vs.game_schedule_id, vs.team_id,
                    t.team_name,
                    gs.game_date, t2.team_name AS opponent_name,
                    u.first_name AS uploader_first, u.last_name AS uploader_last,
                    nc.name AS ndi_camera_name
             FROM vr_video_sources vs
             LEFT JOIN teams t ON t.id = vs.team_id
             LEFT JOIN game_schedules gs ON gs.id = vs.game_schedule_id
             LEFT JOIN teams t2 ON t2.id = gs.opponent_team_id
             LEFT JOIN users u ON u.id = vs.uploaded_by
             LEFT JOIN ndi_cameras nc ON nc.id = vs.ndi_camera_id
             WHERE vs.team_id IN (SELECT team_id FROM team_coach_assignments WHERE coach_id = :uid)
             ORDER BY vs.created_at DESC
             LIMIT 100",
            [':uid' => $user_id]
        );
        $videos = $stmt->fetchAll();
    } catch (PDOException $e) {
        // Fallback: ndi_cameras table may not exist yet; query without the JOIN
        try {
            $stmt = dbQuery($pdo,
                "SELECT vs.id, vs.title, vs.description, vs.camera_angle, vs.duration_seconds,
                        vs.thumbnail_path, vs.status, vs.file_path, vs.file_url, vs.file_size,
                        vs.format, vs.resolution, vs.source_type, vs.recorded_at,
                        vs.created_at, vs.game_schedule_id, vs.team_id,
                        t.team_name,
                        gs.game_date, t2.team_name AS opponent_name,
                        u.first_name AS uploader_first, u.last_name AS uploader_last
                 FROM vr_video_sources vs
                 LEFT JOIN teams t ON t.id = vs.team_id
                 LEFT JOIN game_schedules gs ON gs.id = vs.game_schedule_id
                 LEFT JOIN teams t2 ON t2.id = gs.opponent_team_id
                 LEFT JOIN users u ON u.id = vs.uploaded_by
                 WHERE vs.team_id IN (SELECT team_id FROM team_coach_assignments WHERE coach_id = :uid)
                 ORDER BY vs.created_at DESC
                 LIMIT 100",
                [':uid' => $user_id]
            );
            $videos = $stmt->fetchAll();
        } catch (PDOException $e2) {
            error_log('Film room - videos error: ' . $e2->getMessage());
        }
    }

    // Fetch tags
    try {
        $stmt = dbQuery($pdo, "SELECT id, name, category, color, icon FROM vr_tags WHERE is_active = 1 ORDER BY category, name", []);
        $allTags = $stmt->fetchAll();
        foreach ($allTags as $tag) {
            $tagCategories[$tag['category']][] = $tag;
        }
    } catch (PDOException $e) {
        error_log('Film room - tags error: ' . $e->getMessage());
    }

    // Fetch roster for athlete tagging
    try {
        $stmt = dbQuery($pdo,
            "SELECT DISTINCT u.id, u.first_name, u.last_name
             FROM users u
             INNER JOIN team_roster tr ON tr.user_id = u.id
             WHERE tr.team_id IN (SELECT team_id FROM team_coach_assignments WHERE coach_id = :uid)
             ORDER BY u.last_name, u.first_name",
            [':uid' => $user_id]
        );
        $rosterPlayers = $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log('Film room - roster error: ' . $e->getMessage());
    }

    // If on clip editor with a selected source, fetch it and its clips
    if ($activeTab === 'clip_editor' && $selectedSourceId) {
        try {
            $stmt = dbQuery($pdo,
                "SELECT vs.*, t.team_name, gs.game_date, t2.team_name AS opponent_name
                 FROM vr_video_sources vs
                 LEFT JOIN teams t ON t.id = vs.team_id
                 LEFT JOIN game_schedules gs ON gs.id = vs.game_schedule_id
                 LEFT JOIN teams t2 ON t2.id = gs.opponent_team_id
                 WHERE vs.id = :id",
                [':id' => $selectedSourceId]
            );
            $sourceVideo = $stmt->fetch();
        } catch (PDOException $e) {
            error_log('Film room - source video error: ' . $e->getMessage());
        }

        if ($sourceVideo) {
            try {
                $stmt = dbQuery($pdo,
                    "SELECT vc.id, vc.title, vc.description, vc.start_time, vc.end_time, vc.duration,
                            vc.created_at, vc.is_published,
                            GROUP_CONCAT(DISTINCT vt.name ORDER BY vt.category SEPARATOR ', ') AS tag_names,
                            GROUP_CONCAT(DISTINCT vt.color SEPARATOR ',') AS tag_colors,
                            GROUP_CONCAT(DISTINCT CONCAT(u2.first_name, ' ', u2.last_name) SEPARATOR ', ') AS athlete_names
                     FROM vr_video_clips vc
                     LEFT JOIN vr_clip_tags ct ON ct.clip_id = vc.id
                     LEFT JOIN vr_tags vt ON vt.id = ct.tag_id
                     LEFT JOIN vr_clip_athletes ca ON ca.clip_id = vc.id
                     LEFT JOIN users u2 ON u2.id = ca.athlete_id
                     WHERE vc.source_video_id = :source_id
                     GROUP BY vc.id
                     ORDER BY vc.start_time ASC",
                    [':source_id' => $selectedSourceId]
                );
                $sourceClips = $stmt->fetchAll();
            } catch (PDOException $e) {
                error_log('Film room - source clips error: ' . $e->getMessage());
            }
        }
    }

    // Multi-camera: fetch videos for selected game
    if ($activeTab === 'multi_camera' && $selectedGameFilter) {
        try {
            $stmt = dbQuery($pdo,
                "SELECT vs.id, vs.title, vs.camera_angle, vs.file_path, vs.file_url,
                        vs.duration_seconds, vs.thumbnail_path, vs.status
                 FROM vr_video_sources vs
                 WHERE vs.game_schedule_id = :game_id AND vs.status = 'ready'
                 ORDER BY vs.camera_angle ASC",
                [':game_id' => $selectedGameFilter]
            );
            $multiCameraVideos = $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log('Film room - multi-camera error: ' . $e->getMessage());
        }
    }
}

// Ready videos for clip editor dropdown
$readyVideos = array_filter($videos, function($v) { return $v['status'] === 'ready'; });
?>

<!-- Page Header -->
<div class="page-header">
    <div class="page-header-icon"><i class="fas fa-video"></i></div>
    <div class="page-header-info">
        <h1 class="page-title">Film Room</h1>
        <p class="page-description">Upload, review, and break down game footage</p>
    </div>
</div>

<!-- Tabs -->
<div class="page-tabs-wrapper" style="margin-bottom:24px;">
    <div class="page-tabs">
        <a href="?page=film_room&tab=upload"
           class="page-tab <?= $activeTab === 'upload' ? 'active' : '' ?>">
            <i class="fas fa-upload"></i> Upload &amp; Manage
        </a>
        <a href="?page=film_room&tab=clip_editor"
           class="page-tab <?= $activeTab === 'clip_editor' ? 'active' : '' ?>">
            <i class="fas fa-cut"></i> Clip Editor
        </a>
        <a href="?page=film_room&tab=multi_camera"
           class="page-tab <?= $activeTab === 'multi_camera' ? 'active' : '' ?>">
            <i class="fas fa-th-large"></i> Multi-Camera
        </a>
    </div>
</div>

<?php
// ============================================================
// TAB 1: UPLOAD & MANAGE
// ============================================================
if ($activeTab === 'upload'):
?>

<!-- Upload Form -->
<div class="card" style="margin-bottom:24px;">
    <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;">
        <h3><i class="fas fa-cloud-upload-alt"></i> Upload Video</h3>
        <div style="display:flex;gap:8px;">
            <button type="button" class="btn btn-sm btn-outline" data-action="record-ndi" onclick="openNdiRecordPanel()">
                <i class="fas fa-broadcast-tower"></i> Record from NDI Camera
            </button>
            <button type="button" class="btn btn-sm btn-outline" data-action="record-device" onclick="startDeviceCapture()">
                <i class="fas fa-camera"></i> Record from Device
            </button>
        </div>
    </div>
    <div class="card-body">
        <form id="videoUploadForm" method="POST" action="api/video_upload.php" enctype="multipart/form-data" data-form="video-upload">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">

            <!-- Drag & Drop Zone -->
            <div id="dropZone" data-drop-zone
                 style="border:2px dashed var(--border);border-radius:var(--radius-lg);padding:40px;text-align:center;margin-bottom:16px;cursor:pointer;transition:all 0.2s;"
                 onclick="document.getElementById('videoFileInput').click()">
                <div style="font-size:40px;color:var(--primary-light);margin-bottom:12px;">
                    <i class="fas fa-cloud-upload-alt"></i>
                </div>
                <div style="font-size:15px;color:var(--text-white);font-weight:600;margin-bottom:4px;">
                    Drag &amp; drop video files here
                </div>
                <div style="font-size:13px;color:var(--text-muted);">
                    or click to browse &middot; MP4, MOV, MKV &middot; Multiple files supported
                </div>
                <input type="file" id="videoFileInput" name="video_files[]" multiple accept="video/*"
                       style="display:none;" data-field="video_files" onchange="handleFileSelect(this)">
            </div>

            <!-- Selected files preview -->
            <div id="selectedFiles" style="display:none;margin-bottom:16px;" data-selected-files></div>

            <!-- Upload progress -->
            <div id="uploadProgress" style="display:none;margin-bottom:16px;" data-upload-progress>
                <div style="font-size:13px;color:var(--text-secondary);margin-bottom:6px;">
                    Uploading... <span id="uploadPercent">0</span>%
                </div>
                <div style="height:6px;background:var(--bg-secondary);border-radius:3px;overflow:hidden;">
                    <div id="uploadBar" style="height:100%;background:var(--primary);width:0%;transition:width 0.3s;border-radius:3px;"></div>
                </div>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                <div>
                    <label class="form-label" style="font-size:12px;color:var(--text-muted);display:block;margin-bottom:4px;">Title</label>
                    <input type="text" name="title" class="form-input" placeholder="Video title" data-field="title" required>
                </div>
                <div>
                    <label class="form-label" style="font-size:12px;color:var(--text-muted);display:block;margin-bottom:4px;">Camera Angle</label>
                    <select name="camera_angle" class="form-select" data-field="camera_angle">
                        <option value="">Select angle...</option>
                        <?php foreach ($cameraAngleLabels as $angleKey => $angleLabel): ?>
                            <option value="<?= $angleKey ?>"><?= $angleLabel ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="form-label" style="font-size:12px;color:var(--text-muted);display:block;margin-bottom:4px;">Game Assignment</label>
                    <select name="game_schedule_id" class="form-select" data-field="game_schedule_id">
                        <option value="">No game (general footage)</option>
                        <?php foreach ($upcomingGames as $game): ?>
                            <option value="<?= (int)$game['id'] ?>">
                                <?= date('M j, Y', strtotime($game['game_date'])) ?>
                                <?php if (!empty($game['opponent_name'])): ?>
                                    — vs <?= htmlspecialchars($game['opponent_name']) ?>
                                <?php endif; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="form-label" style="font-size:12px;color:var(--text-muted);display:block;margin-bottom:4px;">Team</label>
                    <select name="team_id" class="form-select" data-field="team_id" required>
                        <option value="">Select team...</option>
                        <?php foreach ($teams as $team): ?>
                            <option value="<?= (int)$team['id'] ?>"><?= htmlspecialchars($team['team_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div style="margin-top:16px;">
                <button type="submit" class="btn btn-primary" data-action="upload-video">
                    <i class="fas fa-upload"></i> Upload
                </button>
            </div>
        </form>
    </div>
</div>

<!-- NDI Camera Recording Panel (hidden by default) -->
<div id="ndiRecordPanel" class="card" style="margin-bottom:24px;display:none;">
    <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;">
        <h3><i class="fas fa-broadcast-tower"></i> Record from NDI Camera</h3>
        <button type="button" class="btn btn-sm btn-outline" onclick="closeNdiRecordPanel()">
            <i class="fas fa-times"></i> Close
        </button>
    </div>
    <div class="card-body">
        <div id="ndiCameraList" style="margin-bottom:16px;">
            <div style="text-align:center;padding:20px;color:var(--text-muted);">
                <i class="fas fa-spinner fa-spin"></i> Loading NDI cameras...
            </div>
        </div>

        <div id="ndiRecordingArea" style="display:none;">
            <div style="position:relative;width:100%;aspect-ratio:16/9;background:#000;border-radius:var(--radius-md);overflow:hidden;margin-bottom:16px;">
                <video id="ndiPreview" style="width:100%;height:100%;object-fit:contain;" autoplay muted playsinline></video>
                <div id="ndiRecordingIndicator" style="display:none;position:absolute;top:12px;left:12px;background:rgba(239,68,68,0.9);color:#fff;padding:6px 14px;border-radius:16px;font-size:13px;font-weight:600;">
                    <span style="display:inline-block;width:8px;height:8px;background:#fff;border-radius:50%;margin-right:6px;animation:pulse 1s infinite;"></span>REC
                </div>
            </div>

            <div style="display:flex;align-items:center;gap:12px;margin-bottom:16px;">
                <span id="ndiSelectedCameraName" style="font-size:14px;font-weight:600;color:var(--text-white);"></span>
                <span id="ndiRecordTimer" style="font-family:monospace;font-size:14px;color:var(--text-secondary);">0:00</span>
            </div>

            <div style="display:flex;gap:8px;margin-bottom:16px;">
                <button id="ndiStartRecordBtn" class="btn btn-primary" onclick="ndiStartRecording()">
                    <i class="fas fa-circle" style="color:#ef4444;"></i> Start Recording
                </button>
                <button id="ndiStopRecordBtn" class="btn btn-secondary" onclick="ndiStopRecording()" disabled>
                    <i class="fas fa-stop"></i> Stop Recording
                </button>
            </div>

            <!-- NDI upload form (populated after recording) -->
            <form id="ndiUploadForm" method="POST" action="api/video_upload.php" enctype="multipart/form-data" style="display:none;">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                <input type="hidden" name="source_type" value="ndi">
                <input type="hidden" id="ndiCameraIdInput" name="ndi_camera_id" value="">
                <input type="file" id="ndiFileInput" name="video_file" style="display:none;" accept="video/*">

                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                    <div>
                        <label class="form-label" style="font-size:12px;color:var(--text-muted);display:block;margin-bottom:4px;">Title</label>
                        <input type="text" name="title" id="ndiTitle" class="form-input" placeholder="NDI recording title" required>
                    </div>
                    <div>
                        <label class="form-label" style="font-size:12px;color:var(--text-muted);display:block;margin-bottom:4px;">Camera Angle</label>
                        <select name="camera_angle" class="form-select">
                            <option value="">Select angle...</option>
                            <?php foreach ($cameraAngleLabels as $angleKey => $angleLabel): ?>
                                <option value="<?= $angleKey ?>"><?= $angleLabel ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="form-label" style="font-size:12px;color:var(--text-muted);display:block;margin-bottom:4px;">Game Assignment</label>
                        <select name="game_schedule_id" class="form-select">
                            <option value="">No game (general footage)</option>
                            <?php foreach ($upcomingGames as $game): ?>
                                <option value="<?= (int)$game['id'] ?>">
                                    <?= date('M j, Y', strtotime($game['game_date'])) ?>
                                    <?php if (!empty($game['opponent_name'])): ?>
                                        — vs <?= htmlspecialchars($game['opponent_name']) ?>
                                    <?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="form-label" style="font-size:12px;color:var(--text-muted);display:block;margin-bottom:4px;">Team</label>
                        <select name="team_id" class="form-select" required>
                            <option value="">Select team...</option>
                            <?php foreach ($teams as $team): ?>
                                <option value="<?= (int)$team['id'] ?>"><?= htmlspecialchars($team['team_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div style="margin-top:16px;">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-upload"></i> Save NDI Recording
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Video List -->
<div class="card">
    <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;">
        <h3><i class="fas fa-film"></i> Uploaded Videos (<?= count($videos) ?>)</h3>
    </div>
    <div class="card-body" style="padding:0;">
        <?php if (empty($videos)): ?>
            <div class="empty-state-card">
                <div class="empty-icon"><i class="fas fa-video"></i></div>
                <p>No videos uploaded yet. Use the form above to upload your first game footage.</p>
            </div>
        <?php else: ?>
            <div class="data-table" style="overflow-x:auto;">
                <table style="width:100%;border-collapse:collapse;">
                    <thead>
                        <tr style="border-bottom:1px solid var(--border);">
                            <th style="padding:10px 12px;text-align:left;font-size:12px;color:var(--text-muted);font-weight:600;">Video</th>
                            <th style="padding:10px 12px;text-align:left;font-size:12px;color:var(--text-muted);font-weight:600;">Game</th>
                            <th style="padding:10px 12px;text-align:left;font-size:12px;color:var(--text-muted);font-weight:600;">Angle</th>
                            <th style="padding:10px 12px;text-align:left;font-size:12px;color:var(--text-muted);font-weight:600;">Duration</th>
                            <th style="padding:10px 12px;text-align:left;font-size:12px;color:var(--text-muted);font-weight:600;">Status</th>
                            <th style="padding:10px 12px;text-align:left;font-size:12px;color:var(--text-muted);font-weight:600;">Date</th>
                            <th style="padding:10px 12px;text-align:right;font-size:12px;color:var(--text-muted);font-weight:600;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($videos as $video): ?>
                            <tr style="border-bottom:1px solid var(--border);transition:background 0.15s;" data-video-id="<?= (int)$video['id'] ?>">
                                <td style="padding:10px 12px;">
                                    <div style="display:flex;align-items:center;gap:10px;">
                                        <div style="width:64px;height:36px;border-radius:4px;overflow:hidden;flex-shrink:0;background:var(--bg-secondary);display:flex;align-items:center;justify-content:center;">
                                            <?php if (!empty($video['thumbnail_path'])): ?>
                                                <img src="<?= htmlspecialchars($video['thumbnail_path']) ?>" alt="" style="width:100%;height:100%;object-fit:cover;" loading="lazy">
                                            <?php else: ?>
                                                <i class="fas fa-video" style="color:var(--primary-light);font-size:14px;"></i>
                                            <?php endif; ?>
                                        </div>
                                        <div>
                                            <div style="font-weight:600;font-size:13px;color:var(--text-white);" class="truncate">
                                                <?= htmlspecialchars($video['title']) ?>
                                                <?php if ($video['source_type'] === 'ndi'): ?>
                                                    <span class="badge badge-info" style="font-size:9px;margin-left:4px;">NDI</span>
                                                <?php endif; ?>
                                            </div>
                                            <?php if (!empty($video['team_name'])): ?>
                                                <div style="font-size:11px;color:var(--text-muted);">
                                                    <?= htmlspecialchars($video['team_name']) ?>
                                                    <?php if (!empty($video['ndi_camera_name'])): ?>
                                                        &middot; <?= htmlspecialchars($video['ndi_camera_name']) ?>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </td>
                                <td style="padding:10px 12px;font-size:12px;color:var(--text-secondary);">
                                    <?php if (!empty($video['game_date'])): ?>
                                        <?= date('M j', strtotime($video['game_date'])) ?>
                                        <?php if (!empty($video['opponent_name'])): ?>
                                            vs <?= htmlspecialchars($video['opponent_name']) ?>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span style="color:var(--text-muted);">—</span>
                                    <?php endif; ?>
                                </td>
                                <td style="padding:10px 12px;font-size:12px;color:var(--text-secondary);">
                                    <?= htmlspecialchars($cameraAngleLabels[$video['camera_angle']] ?? $video['camera_angle'] ?? '—') ?>
                                </td>
                                <td style="padding:10px 12px;font-size:12px;color:var(--text-secondary);">
                                    <?= $video['duration_seconds'] ? formatDuration((int)$video['duration_seconds']) : '—' ?>
                                </td>
                                <td style="padding:10px 12px;">
                                    <span class="badge <?= $videoStatusBadge[$video['status']] ?? 'badge-secondary' ?>" style="font-size:10px;">
                                        <?= ucfirst(htmlspecialchars($video['status'])) ?>
                                    </span>
                                </td>
                                <td style="padding:10px 12px;font-size:12px;color:var(--text-muted);">
                                    <?= timeAgo($video['created_at']) ?>
                                </td>
                                <td style="padding:10px 12px;text-align:right;">
                                    <div style="display:flex;gap:4px;justify-content:flex-end;">
                                        <?php if ($video['status'] === 'ready'): ?>
                                            <a href="?page=film_room&tab=clip_editor&source_id=<?= (int)$video['id'] ?>"
                                               class="btn btn-sm btn-primary" data-action="open-editor" title="Open in Editor">
                                                <i class="fas fa-cut"></i>
                                            </a>
                                        <?php endif; ?>
                                        <button class="btn btn-sm btn-outline" data-action="edit-details" title="Edit Details"
                                                onclick="openEditVideoModal(<?= (int)$video['id'] ?>, '<?= htmlspecialchars(addslashes($video['title'])) ?>')">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <form method="POST" action="api/video_delete.php" style="display:inline;" onsubmit="return confirm('Delete this video?');">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                                            <input type="hidden" name="video_id" value="<?= (int)$video['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-outline" style="color:var(--error);border-color:var(--error);" data-action="delete" title="Delete">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
function handleFileSelect(input) {
    var container = document.getElementById('selectedFiles');
    if (!input.files.length) { container.style.display = 'none'; return; }
    container.style.display = 'block';
    var html = '';
    for (var i = 0; i < input.files.length; i++) {
        var f = input.files[i];
        var sizeMB = (f.size / 1048576).toFixed(1);
        html += '<div style="display:flex;align-items:center;gap:8px;padding:6px 10px;background:var(--bg-secondary);border-radius:6px;margin-bottom:4px;">';
        html += '<i class="fas fa-file-video" style="color:var(--primary-light);"></i>';
        html += '<span style="font-size:13px;color:var(--text-white);">' + f.name + '</span>';
        html += '<span style="font-size:11px;color:var(--text-muted);">' + sizeMB + ' MB</span>';
        html += '</div>';
    }
    container.innerHTML = html;
}

// Drag & drop
var dz = document.getElementById('dropZone');
if (dz) {
    dz.addEventListener('dragover', function(e) { e.preventDefault(); dz.style.borderColor = 'var(--primary)'; dz.style.background = 'rgba(107,70,193,0.05)'; });
    dz.addEventListener('dragleave', function() { dz.style.borderColor = 'var(--border)'; dz.style.background = 'none'; });
    dz.addEventListener('drop', function(e) {
        e.preventDefault();
        dz.style.borderColor = 'var(--border)';
        dz.style.background = 'none';
        document.getElementById('videoFileInput').files = e.dataTransfer.files;
        handleFileSelect(document.getElementById('videoFileInput'));
    });
}

function startDeviceCapture() {
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
        alert('Device capture is not supported in this browser.');
        return;
    }
    alert('Device capture will open your camera/screen. This feature requires HTTPS.');
}

function openEditVideoModal(id, title) {
    alert('Edit details for: ' + title + ' (ID: ' + id + ')');
}

/* -------------------------------------------------------
   NDI Camera Recording
------------------------------------------------------- */
var ndiMediaStream = null;
var ndiMediaRecorder = null;
var ndiRecordedChunks = [];
var ndiRecordStartTime = null;
var ndiTimerInterval = null;

function openNdiRecordPanel() {
    var panel = document.getElementById('ndiRecordPanel');
    panel.style.display = 'block';
    panel.scrollIntoView({ behavior: 'smooth' });
    loadNdiCameras();
}

function closeNdiRecordPanel() {
    ndiStopStream();
    document.getElementById('ndiRecordPanel').style.display = 'none';
    document.getElementById('ndiRecordingArea').style.display = 'none';
    document.getElementById('ndiUploadForm').style.display = 'none';
}

function loadNdiCameras() {
    var listEl = document.getElementById('ndiCameraList');
    listEl.innerHTML = '<div style="text-align:center;padding:20px;color:var(--text-muted);"><i class="fas fa-spinner fa-spin"></i> Loading NDI cameras...</div>';

    fetch('api/ndi_sources.php', { credentials: 'same-origin' })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (!data.success || !data.cameras || data.cameras.length === 0) {
                listEl.innerHTML = '<div style="text-align:center;padding:20px;">'
                    + '<div style="color:var(--text-muted);margin-bottom:8px;"><i class="fas fa-video-slash" style="font-size:24px;"></i></div>'
                    + '<div style="color:var(--text-secondary);font-size:14px;">No NDI cameras configured.</div>'
                    + '<div style="color:var(--text-muted);font-size:12px;margin-top:4px;">Add cameras in Arctic Wolves &rarr; System Tools &rarr; NDI Cameras</div>'
                    + '</div>';
                return;
            }
            var html = '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:12px;">';
            data.cameras.forEach(function(cam) {
                html += '<div class="card" style="cursor:pointer;transition:border-color 0.2s;" onclick="selectNdiCamera('
                    + cam.id + ',' + JSON.stringify(cam.name) + ',' + JSON.stringify(cam.ip_address) + ',' + cam.port + ',' + JSON.stringify(cam.ndi_name || '') + ')">';
                html += '<div class="card-body" style="padding:16px;display:flex;align-items:center;gap:12px;">';
                html += '<div style="width:40px;height:40px;border-radius:50%;background:rgba(16,185,129,0.15);display:flex;align-items:center;justify-content:center;flex-shrink:0;">';
                html += '<i class="fas fa-broadcast-tower" style="color:#10b981;"></i></div>';
                html += '<div>';
                html += '<div style="font-weight:600;font-size:14px;color:var(--text-white);">' + cam.name + '</div>';
                html += '<div style="font-size:12px;color:var(--text-muted);">' + cam.ip_address + ':' + cam.port;
                if (cam.location) html += ' &middot; ' + cam.location;
                html += '</div></div></div></div>';
            });
            html += '</div>';
            listEl.innerHTML = html;
        })
        .catch(function() {
            listEl.innerHTML = '<div style="text-align:center;padding:20px;color:var(--text-muted);">Failed to load NDI cameras.</div>';
        });
}

function selectNdiCamera(id, name, ip, port, ndiName) {
    document.getElementById('ndiCameraIdInput').value = id;
    document.getElementById('ndiSelectedCameraName').textContent = name + ' (' + ip + ':' + port + ')';
    document.getElementById('ndiTitle').value = 'NDI Recording - ' + name + ' - ' + new Date().toISOString().slice(0, 10);
    document.getElementById('ndiCameraList').style.display = 'none';
    document.getElementById('ndiRecordingArea').style.display = 'block';

    // NDI streams are network video sources accessible via IP.
    // The browser connects to the NDI source URL to preview/record the stream.
    // For environments without direct NDI-to-browser bridge, fallback to screen capture
    // which allows the user to select the NDI virtual output window.
    startNdiStream(ip, port, ndiName);
}

function startNdiStream(ip, port, ndiName) {
    // NDI streams require a bridge (e.g., NDI-to-WebRTC or NDI-to-HLS) to be consumed by browsers.
    // We attempt to connect to a local NDI-to-web bridge first, then fall back to screen/window capture
    // so the user can select the NDI virtual display output.
    var preview = document.getElementById('ndiPreview');

    // Try screen capture as a reliable fallback — user can select the NDI Tools monitor/output window
    if (navigator.mediaDevices && navigator.mediaDevices.getDisplayMedia) {
        navigator.mediaDevices.getDisplayMedia({ video: true, audio: true })
            .then(function(stream) {
                ndiMediaStream = stream;
                preview.srcObject = stream;
                stream.getVideoTracks()[0].addEventListener('ended', function() {
                    ndiStopStream();
                });
            })
            .catch(function(err) {
                console.error('NDI capture error:', err);
                alert('Could not start capture. Please ensure NDI Tools Monitor or Studio Monitor is open, then try again.');
                document.getElementById('ndiRecordingArea').style.display = 'none';
                document.getElementById('ndiCameraList').style.display = 'block';
            });
    } else {
        alert('Screen/display capture is not supported in this browser. Please use a modern browser with HTTPS.');
    }
}

function ndiStopStream() {
    if (ndiMediaStream) {
        ndiMediaStream.getTracks().forEach(function(t) { t.stop(); });
        ndiMediaStream = null;
    }
    var preview = document.getElementById('ndiPreview');
    if (preview) preview.srcObject = null;
    if (ndiTimerInterval) {
        clearInterval(ndiTimerInterval);
        ndiTimerInterval = null;
    }
}

function ndiStartRecording() {
    if (!ndiMediaStream) {
        alert('No NDI stream connected. Please select a camera first.');
        return;
    }
    ndiRecordedChunks = [];
    ndiMediaRecorder = new MediaRecorder(ndiMediaStream, { mimeType: 'video/webm' });

    ndiMediaRecorder.ondataavailable = function(e) {
        if (e.data.size > 0) ndiRecordedChunks.push(e.data);
    };

    ndiMediaRecorder.onstop = function() {
        var blob = new Blob(ndiRecordedChunks, { type: 'video/webm' });
        var timestamp = new Date().toISOString().replace(/[:.]/g, '-').slice(0, 19);
        var file = new File([blob], 'ndi-recording-' + timestamp + '.webm', { type: 'video/webm' });

        var dt = new DataTransfer();
        dt.items.add(file);
        document.getElementById('ndiFileInput').files = dt.files;

        // Show upload form
        document.getElementById('ndiUploadForm').style.display = 'block';
        document.getElementById('ndiRecordingIndicator').style.display = 'none';
    };

    ndiMediaRecorder.start();
    ndiRecordStartTime = Date.now();
    document.getElementById('ndiRecordingIndicator').style.display = 'block';
    document.getElementById('ndiStartRecordBtn').disabled = true;
    document.getElementById('ndiStopRecordBtn').disabled = false;

    ndiTimerInterval = setInterval(function() {
        var elapsed = Math.floor((Date.now() - ndiRecordStartTime) / 1000);
        var m = Math.floor(elapsed / 60);
        var s = elapsed % 60;
        document.getElementById('ndiRecordTimer').textContent = m + ':' + (s < 10 ? '0' : '') + s;
    }, 1000);
}

function ndiStopRecording() {
    if (ndiMediaRecorder && ndiMediaRecorder.state !== 'inactive') {
        ndiMediaRecorder.stop();
    }
    if (ndiTimerInterval) {
        clearInterval(ndiTimerInterval);
        ndiTimerInterval = null;
    }
    document.getElementById('ndiStartRecordBtn').disabled = false;
    document.getElementById('ndiStopRecordBtn').disabled = true;
    ndiStopStream();
}
</script>

<?php
// ============================================================
// TAB 2: CLIP EDITOR
// ============================================================
elseif ($activeTab === 'clip_editor'):
?>

<div style="display:flex;gap:16px;flex-wrap:wrap;">
    <!-- Left Panel: Source Selector -->
    <div style="width:220px;flex-shrink:0;">
        <div class="card" style="position:sticky;top:80px;">
            <div class="card-header">
                <h3 style="font-size:13px;"><i class="fas fa-film"></i> Source Video</h3>
            </div>
            <div class="card-body">
                <form method="GET" action="">
                    <input type="hidden" name="page" value="film_room">
                    <input type="hidden" name="tab" value="clip_editor">
                    <div style="margin-bottom:8px;">
                        <label class="form-label" style="font-size:11px;color:var(--text-muted);display:block;margin-bottom:4px;">Filter by Game</label>
                        <select name="game_filter" class="form-select" data-filter="game" onchange="this.form.submit()" style="font-size:12px;">
                            <option value="0">All Games</option>
                            <?php foreach ($upcomingGames as $game): ?>
                                <option value="<?= (int)$game['id'] ?>" <?= $selectedGameFilter === (int)$game['id'] ? 'selected' : '' ?>>
                                    <?= date('M j', strtotime($game['game_date'])) ?>
                                    <?php if (!empty($game['opponent_name'])): ?>
                                        vs <?= htmlspecialchars($game['opponent_name']) ?>
                                    <?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="form-label" style="font-size:11px;color:var(--text-muted);display:block;margin-bottom:4px;">Video</label>
                        <select name="source_id" class="form-select" data-filter="source" onchange="this.form.submit()" style="font-size:12px;">
                            <option value="0">Select video...</option>
                            <?php foreach ($readyVideos as $v):
                                if ($selectedGameFilter && (int)($v['game_schedule_id'] ?? 0) !== $selectedGameFilter) continue;
                            ?>
                                <option value="<?= (int)$v['id'] ?>" <?= $selectedSourceId === (int)$v['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($v['title']) ?>
                                    <?php if (!empty($v['camera_angle'])): ?>
                                        (<?= htmlspecialchars($cameraAngleLabels[$v['camera_angle']] ?? $v['camera_angle']) ?>)
                                    <?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Center + Right: Player + Tag Panel -->
    <div style="flex:1;min-width:0;">
        <?php if (!$sourceVideo): ?>
            <div class="empty-state-card">
                <div class="empty-icon"><i class="fas fa-cut"></i></div>
                <p>Select a source video from the left panel to begin clipping.</p>
                <?php if (empty($readyVideos)): ?>
                    <p style="font-size:13px;color:var(--text-muted);margin-top:8px;">No ready videos found. Upload videos in the Upload tab first.</p>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <!-- Video Player Area -->
            <div class="card" style="margin-bottom:16px;">
                <div class="card-body" style="padding:0;">
                    <div style="position:relative;width:100%;aspect-ratio:16/9;background:#000;border-radius:var(--radius-lg) var(--radius-lg) 0 0;overflow:hidden;">
                        <video id="clipEditorVideo" data-video-player
                               style="width:100%;height:100%;object-fit:contain;"
                               src="<?= htmlspecialchars($sourceVideo['file_url'] ?? $sourceVideo['file_path'] ?? '') ?>"
                               preload="metadata">
                            Your browser does not support video playback.
                        </video>
                    </div>

                    <!-- Custom Controls -->
                    <div style="padding:10px 16px;background:var(--bg-secondary);border-top:1px solid var(--border);display:flex;align-items:center;gap:8px;flex-wrap:wrap;" data-video-controls>
                        <button class="btn btn-sm btn-secondary" data-action="play-pause" onclick="togglePlay()" title="Play/Pause">
                            <i class="fas fa-play" id="playPauseIcon"></i>
                        </button>
                        <button class="btn btn-sm btn-secondary" data-action="skip-back-5" onclick="skipTime(-5)" title="Back 5s">
                            <i class="fas fa-backward"></i> 5s
                        </button>
                        <button class="btn btn-sm btn-secondary" data-action="skip-forward-5" onclick="skipTime(5)" title="Forward 5s">
                            5s <i class="fas fa-forward"></i>
                        </button>
                        <button class="btn btn-sm btn-secondary" data-action="prev-frame" onclick="skipTime(-0.033)" title="Previous Frame">
                            <i class="fas fa-step-backward"></i>
                        </button>
                        <button class="btn btn-sm btn-secondary" data-action="next-frame" onclick="skipTime(0.033)" title="Next Frame">
                            <i class="fas fa-step-forward"></i>
                        </button>
                        <div style="width:1px;height:20px;background:var(--border);"></div>
                        <select id="playbackSpeed" class="form-select" data-control="speed" onchange="setPlaybackSpeed(this.value)" style="width:80px;font-size:12px;">
                            <option value="0.25">0.25x</option>
                            <option value="0.5">0.5x</option>
                            <option value="1" selected>1x</option>
                            <option value="1.5">1.5x</option>
                            <option value="2">2x</option>
                        </select>
                        <div style="flex:1;"></div>
                        <span id="currentTimeDisplay" style="font-size:12px;color:var(--text-secondary);font-family:monospace;" data-display="time">0:00 / 0:00</span>
                        <button class="btn btn-sm btn-secondary" data-action="fullscreen" onclick="toggleFullscreen()" title="Fullscreen">
                            <i class="fas fa-expand"></i>
                        </button>
                    </div>

                    <!-- Timeline -->
                    <div class="timeline-container" style="padding:12px 16px;background:var(--bg-card);border-top:1px solid var(--border);" data-timeline>
                        <div class="timeline-bar" style="position:relative;height:32px;background:var(--bg-secondary);border-radius:4px;cursor:pointer;overflow:hidden;"
                             onclick="seekTimeline(event)" data-timeline-bar>
                            <!-- Playhead -->
                            <div id="playhead" style="position:absolute;top:0;left:0;width:2px;height:100%;background:var(--primary-light);z-index:10;pointer-events:none;" data-playhead></div>

                            <!-- Existing clip regions -->
                            <?php foreach ($sourceClips as $clip):
                                $totalDur = max(1, (int)($sourceVideo['duration_seconds'] ?? 1));
                                $leftPct = ((float)$clip['start_time'] / $totalDur) * 100;
                                $widthPct = (((float)$clip['end_time'] - (float)$clip['start_time']) / $totalDur) * 100;
                            ?>
                                <div class="clip-region" data-clip-id="<?= (int)$clip['id'] ?>"
                                     style="position:absolute;top:0;left:<?= number_format($leftPct, 2) ?>%;width:<?= number_format($widthPct, 2) ?>%;height:100%;background:rgba(107,70,193,0.3);border:1px solid var(--primary);pointer-events:none;"
                                     title="<?= htmlspecialchars($clip['title']) ?>"></div>
                            <?php endforeach; ?>
                        </div>

                        <!-- Mark In / Out Controls -->
                        <div style="display:flex;align-items:center;gap:12px;margin-top:10px;">
                            <button class="btn btn-sm btn-primary" data-action="mark-in" onclick="markIn()" title="Mark In (I)">
                                <i class="fas fa-sign-in-alt"></i> In
                            </button>
                            <span id="markInTime" style="font-size:12px;color:var(--text-secondary);font-family:monospace;" data-display="mark-in">—</span>
                            <button class="btn btn-sm btn-primary" data-action="mark-out" onclick="markOut()" title="Mark Out (O)">
                                Out <i class="fas fa-sign-out-alt"></i>
                            </button>
                            <span id="markOutTime" style="font-size:12px;color:var(--text-secondary);font-family:monospace;" data-display="mark-out">—</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tag & Save Panel -->
            <div class="card" style="margin-bottom:16px;">
                <div class="card-header">
                    <h3 style="font-size:14px;"><i class="fas fa-tag"></i> Tag &amp; Save Clip</h3>
                </div>
                <div class="card-body">
                    <form id="saveClipForm" method="POST" action="api/clip_save.php" data-form="save-clip">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                        <input type="hidden" name="source_video_id" value="<?= (int)$sourceVideo['id'] ?>">
                        <input type="hidden" name="game_schedule_id" value="<?= (int)($sourceVideo['game_schedule_id'] ?? 0) ?>">

                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;">
                            <div>
                                <label class="form-label" style="font-size:12px;color:var(--text-muted);display:block;margin-bottom:4px;">In Time (s)</label>
                                <input type="number" name="start_time" id="clipStartTime" class="form-input" step="0.001" min="0" value="0" data-field="start_time" required>
                            </div>
                            <div>
                                <label class="form-label" style="font-size:12px;color:var(--text-muted);display:block;margin-bottom:4px;">Out Time (s)</label>
                                <input type="number" name="end_time" id="clipEndTime" class="form-input" step="0.001" min="0" value="0" data-field="end_time" required>
                            </div>
                        </div>

                        <div style="margin-bottom:12px;">
                            <label class="form-label" style="font-size:12px;color:var(--text-muted);display:block;margin-bottom:4px;">Clip Title</label>
                            <input type="text" name="title" class="form-input" placeholder="Clip title..." data-field="clip_title" required>
                        </div>

                        <div style="margin-bottom:12px;">
                            <label class="form-label" style="font-size:12px;color:var(--text-muted);display:block;margin-bottom:4px;">Description</label>
                            <textarea name="description" class="form-textarea" rows="2" placeholder="Optional notes..." data-field="clip_description"></textarea>
                        </div>

                        <!-- Tags by Category -->
                        <div style="margin-bottom:12px;">
                            <label class="form-label" style="font-size:12px;color:var(--text-muted);display:block;margin-bottom:8px;">Tags</label>
                            <?php foreach ($tagCategories as $cat => $catTags): ?>
                                <div style="margin-bottom:8px;">
                                    <div style="font-size:11px;font-weight:600;color:var(--text-muted);margin-bottom:4px;text-transform:uppercase;">
                                        <?= htmlspecialchars($categoryLabels[$cat] ?? ucfirst($cat)) ?>
                                    </div>
                                    <div style="display:flex;flex-wrap:wrap;gap:6px;">
                                        <?php foreach ($catTags as $tag): ?>
                                            <label style="display:flex;align-items:center;gap:4px;font-size:12px;color:var(--text-secondary);cursor:pointer;padding:4px 8px;border-radius:4px;border:1px solid var(--border);transition:all 0.15s;"
                                                   data-tag-label>
                                                <input type="checkbox" name="tags[]" value="<?= (int)$tag['id'] ?>" data-tag-id="<?= (int)$tag['id'] ?>">
                                                <span style="width:8px;height:8px;border-radius:50%;background:<?= htmlspecialchars($tag['color'] ?? 'var(--primary)') ?>;flex-shrink:0;"></span>
                                                <?= htmlspecialchars($tag['name']) ?>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <!-- Athlete Tagger -->
                        <div style="margin-bottom:16px;">
                            <label class="form-label" style="font-size:12px;color:var(--text-muted);display:block;margin-bottom:4px;">Tag Athletes</label>
                            <div style="display:flex;flex-wrap:wrap;gap:6px;max-height:120px;overflow-y:auto;padding:8px;background:var(--bg-secondary);border-radius:var(--radius-md);border:1px solid var(--border);">
                                <?php foreach ($rosterPlayers as $player): ?>
                                    <label style="display:flex;align-items:center;gap:4px;font-size:12px;color:var(--text-secondary);cursor:pointer;" data-athlete-label>
                                        <input type="checkbox" name="athletes[]" value="<?= (int)$player['id'] ?>" data-athlete-id="<?= (int)$player['id'] ?>">
                                        <?= htmlspecialchars($player['last_name'] . ', ' . $player['first_name']) ?>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <div style="display:flex;gap:8px;">
                            <button type="submit" class="btn btn-primary" data-action="save-clip">
                                <i class="fas fa-save"></i> Save Clip
                            </button>
                            <a href="?page=pair_device" class="btn btn-outline" data-action="start-telestration">
                                <i class="fas fa-pencil-alt"></i> Start Telestration
                            </a>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Clips List for Current Source -->
            <div class="card">
                <div class="card-header">
                    <h3 style="font-size:14px;"><i class="fas fa-list"></i> Clips from this Video (<?= count($sourceClips) ?>)</h3>
                </div>
                <div class="card-body" style="padding:0;">
                    <?php if (empty($sourceClips)): ?>
                        <div style="padding:20px;text-align:center;font-size:13px;color:var(--text-muted);">
                            No clips created yet. Use the Mark In/Out controls above to create your first clip.
                        </div>
                    <?php else: ?>
                        <?php foreach ($sourceClips as $clip): ?>
                            <div class="session-list-card" data-clip-id="<?= (int)$clip['id'] ?>"
                                 data-clip-start="<?= (float)$clip['start_time'] ?>"
                                 data-clip-end="<?= (float)$clip['end_time'] ?>"
                                 style="cursor:pointer;"
                                 onclick="seekToClip(<?= (float)$clip['start_time'] ?>, <?= (float)$clip['end_time'] ?>)">
                                <div style="flex:1;padding:10px 12px;">
                                    <div style="display:flex;align-items:center;justify-content:space-between;">
                                        <div>
                                            <div style="font-weight:600;font-size:13px;color:var(--text-white);">
                                                <?= htmlspecialchars($clip['title']) ?>
                                            </div>
                                            <div style="font-size:12px;color:var(--text-muted);margin-top:2px;display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
                                                <span style="font-family:monospace;"><?= formatDuration((float)$clip['start_time']) ?> → <?= formatDuration((float)$clip['end_time']) ?></span>
                                                <?php if (!empty($clip['tag_names'])): ?>
                                                    <?php foreach (explode(', ', $clip['tag_names']) as $i => $tagName): ?>
                                                        <?php $colors = explode(',', $clip['tag_colors'] ?? ''); ?>
                                                        <span class="badge" style="font-size:10px;background:<?= htmlspecialchars($colors[$i] ?? 'var(--primary)') ?>;color:#fff;">
                                                            <?= htmlspecialchars($tagName) ?>
                                                        </span>
                                                    <?php endforeach; ?>
                                                <?php endif; ?>
                                            </div>
                                            <?php if (!empty($clip['athlete_names'])): ?>
                                                <div style="font-size:11px;color:var(--text-muted);margin-top:2px;">
                                                    <i class="fas fa-user"></i> <?= htmlspecialchars($clip['athlete_names']) ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        <div style="display:flex;gap:4px;">
                                            <button class="btn btn-sm btn-outline" data-action="edit-clip" title="Edit" onclick="event.stopPropagation();">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <form method="POST" action="api/clip_delete.php" style="display:inline;" onsubmit="event.stopPropagation();return confirm('Delete this clip?');">
                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                                                <input type="hidden" name="clip_id" value="<?= (int)$clip['id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-outline" style="color:var(--error);border-color:var(--error);" data-action="delete-clip" title="Delete" onclick="event.stopPropagation();">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
var video = document.getElementById('clipEditorVideo');
var markInVal = 0, markOutVal = 0;

function togglePlay() {
    if (!video) return;
    if (video.paused) { video.play(); } else { video.pause(); }
}

function skipTime(s) {
    if (!video) return;
    video.currentTime = Math.max(0, video.currentTime + s);
}

function setPlaybackSpeed(rate) {
    if (video) video.playbackRate = parseFloat(rate);
}

function toggleFullscreen() {
    if (!video) return;
    if (document.fullscreenElement) { document.exitFullscreen(); }
    else { video.parentElement.requestFullscreen(); }
}

function seekTimeline(e) {
    if (!video) return;
    var bar = e.currentTarget;
    var rect = bar.getBoundingClientRect();
    var pct = (e.clientX - rect.left) / rect.width;
    video.currentTime = pct * video.duration;
}

function markIn() {
    if (!video) return;
    markInVal = video.currentTime;
    document.getElementById('markInTime').textContent = formatTime(markInVal);
    document.getElementById('clipStartTime').value = markInVal.toFixed(3);
}

function markOut() {
    if (!video) return;
    markOutVal = video.currentTime;
    document.getElementById('markOutTime').textContent = formatTime(markOutVal);
    document.getElementById('clipEndTime').value = markOutVal.toFixed(3);
}

function seekToClip(start, end) {
    if (!video) return;
    video.currentTime = start;
    markInVal = start;
    markOutVal = end;
    document.getElementById('markInTime').textContent = formatTime(start);
    document.getElementById('markOutTime').textContent = formatTime(end);
    document.getElementById('clipStartTime').value = start.toFixed(3);
    document.getElementById('clipEndTime').value = end.toFixed(3);
}

function formatTime(s) {
    var m = Math.floor(s / 60);
    var sec = Math.floor(s % 60);
    return m + ':' + (sec < 10 ? '0' : '') + sec;
}

if (video) {
    video.addEventListener('timeupdate', function() {
        var ph = document.getElementById('playhead');
        if (ph && video.duration) {
            ph.style.left = ((video.currentTime / video.duration) * 100) + '%';
        }
        var disp = document.getElementById('currentTimeDisplay');
        if (disp) {
            disp.textContent = formatTime(video.currentTime) + ' / ' + formatTime(video.duration || 0);
        }
    });
    video.addEventListener('play', function() {
        document.getElementById('playPauseIcon').className = 'fas fa-pause';
    });
    video.addEventListener('pause', function() {
        document.getElementById('playPauseIcon').className = 'fas fa-play';
    });
}

// Keyboard shortcuts
document.addEventListener('keydown', function(e) {
    if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA' || e.target.tagName === 'SELECT') return;
    if (e.key === 'i' || e.key === 'I') { markIn(); e.preventDefault(); }
    if (e.key === 'o' || e.key === 'O') { markOut(); e.preventDefault(); }
    if (e.key === ' ') { togglePlay(); e.preventDefault(); }
});
</script>

<?php
// ============================================================
// TAB 3: MULTI-CAMERA
// ============================================================
elseif ($activeTab === 'multi_camera'):
?>

<div class="card" style="margin-bottom:16px;">
    <div class="card-body">
        <form method="GET" action="" style="display:flex;align-items:center;gap:12px;">
            <input type="hidden" name="page" value="film_room">
            <input type="hidden" name="tab" value="multi_camera">
            <label class="form-label" style="font-size:12px;color:var(--text-muted);white-space:nowrap;">Select Game:</label>
            <select name="game_filter" class="form-select" data-filter="game" onchange="this.form.submit()" style="max-width:300px;">
                <option value="0">Choose a game...</option>
                <?php foreach ($upcomingGames as $game): ?>
                    <option value="<?= (int)$game['id'] ?>" <?= $selectedGameFilter === (int)$game['id'] ? 'selected' : '' ?>>
                        <?= date('M j, Y', strtotime($game['game_date'])) ?>
                        <?php if (!empty($game['opponent_name'])): ?>
                            — vs <?= htmlspecialchars($game['opponent_name']) ?>
                        <?php endif; ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>
</div>

<?php if (!$selectedGameFilter): ?>
    <div class="empty-state-card">
        <div class="empty-icon"><i class="fas fa-th-large"></i></div>
        <p>Select a game above to view multi-camera footage side by side.</p>
    </div>
<?php elseif (empty($multiCameraVideos)): ?>
    <div class="empty-state-card">
        <div class="empty-icon"><i class="fas fa-video-slash"></i></div>
        <p>No ready videos found for this game. Upload multiple camera angles in the Upload tab.</p>
    </div>
<?php else: ?>
    <div class="camera-grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(400px,1fr));gap:16px;margin-bottom:16px;" data-camera-grid>
        <?php foreach ($multiCameraVideos as $idx => $cam): ?>
            <div class="card camera-panel" data-camera-id="<?= (int)$cam['id'] ?>" data-camera-index="<?= $idx ?>"
                 onclick="selectPrimaryCamera(<?= $idx ?>)" style="cursor:pointer;">
                <div style="position:relative;width:100%;aspect-ratio:16/9;background:#000;border-radius:var(--radius-md) var(--radius-md) 0 0;overflow:hidden;">
                    <video class="multi-cam-video" data-camera-video="<?= $idx ?>"
                           style="width:100%;height:100%;object-fit:contain;"
                           src="<?= htmlspecialchars($cam['file_url'] ?? $cam['file_path'] ?? '') ?>"
                           preload="metadata" muted>
                    </video>
                </div>
                <div style="padding:10px;display:flex;align-items:center;justify-content:space-between;">
                    <div>
                        <div style="font-weight:600;font-size:13px;color:var(--text-white);" class="truncate">
                            <?= htmlspecialchars($cam['title']) ?>
                        </div>
                        <div style="font-size:11px;color:var(--text-muted);">
                            <?= htmlspecialchars($cameraAngleLabels[$cam['camera_angle']] ?? $cam['camera_angle'] ?? 'Unknown') ?>
                            <?php if ($cam['duration_seconds']): ?>
                                &middot; <?= formatDuration((int)$cam['duration_seconds']) ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <span class="badge badge-info" style="font-size:10px;">Cam <?= $idx + 1 ?></span>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- Shared Controls -->
    <div class="card">
        <div class="card-body" style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
            <button class="btn btn-sm btn-primary" data-action="sync-play" onclick="syncPlayAll()">
                <i class="fas fa-play"></i> Play All
            </button>
            <button class="btn btn-sm btn-secondary" data-action="sync-pause" onclick="syncPauseAll()">
                <i class="fas fa-pause"></i> Pause All
            </button>
            <button class="btn btn-sm btn-secondary" data-action="sync-back" onclick="syncSkipAll(-5)">
                <i class="fas fa-backward"></i> -5s
            </button>
            <button class="btn btn-sm btn-secondary" data-action="sync-forward" onclick="syncSkipAll(5)">
                <i class="fas fa-forward"></i> +5s
            </button>
            <div style="flex:1;"></div>
            <span id="multiCamTime" style="font-size:12px;color:var(--text-secondary);font-family:monospace;">0:00</span>
        </div>
    </div>

    <script>
    var multiVids = document.querySelectorAll('.multi-cam-video');
    var primaryCam = 0;

    function selectPrimaryCamera(idx) {
        var panels = document.querySelectorAll('.camera-panel');
        panels.forEach(function(p, i) {
            p.style.gridColumn = '';
            p.style.gridRow = '';
        });
        if (panels[idx]) {
            panels[idx].style.gridColumn = '1 / -1';
        }
        primaryCam = idx;
    }

    function syncPlayAll() {
        multiVids.forEach(function(v) { v.play(); });
    }

    function syncPauseAll() {
        multiVids.forEach(function(v) { v.pause(); });
    }

    function syncSkipAll(s) {
        if (multiVids.length === 0) return;
        var t = Math.max(0, multiVids[0].currentTime + s);
        multiVids.forEach(function(v) { v.currentTime = t; });
    }

    // Sync time display
    if (multiVids.length > 0) {
        multiVids[0].addEventListener('timeupdate', function() {
            var disp = document.getElementById('multiCamTime');
            if (disp) {
                var s = multiVids[0].currentTime;
                var m = Math.floor(s / 60);
                var sec = Math.floor(s % 60);
                disp.textContent = m + ':' + (sec < 10 ? '0' : '') + sec;
            }
        });
    }
    </script>
<?php endif; ?>

<?php endif; ?>
