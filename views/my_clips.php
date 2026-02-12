<?php
/**
 * My Clips - Athlete's personal clips view
 * Variables available: $pdo, $user_id, $user_role, $team_id, $user_name, $isCoach, $csrf_token
 */

$clips = [];
$clipCount = 0;
$allTags = [];
$games = [];

$filterTag = isset($_GET['tag']) ? (int)$_GET['tag'] : 0;
$filterGame = isset($_GET['game_id']) ? (int)$_GET['game_id'] : 0;
$filterDateFrom = isset($_GET['date_from']) ? sanitizeInput($_GET['date_from']) : '';
$filterDateTo = isset($_GET['date_to']) ? sanitizeInput($_GET['date_to']) : '';
$filterSearch = isset($_GET['search']) ? sanitizeInput($_GET['search']) : '';

// Helpers
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

if (defined('DB_CONNECTED') && DB_CONNECTED && $pdo) {
    // Load available tags for filter
    try {
        $stmt = dbQuery($pdo,
            "SELECT id, name, category, color FROM vr_tags WHERE is_active = 1 ORDER BY category, name",
            []
        );
        $allTags = $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log('My clips - tags error: ' . $e->getMessage());
    }

    // Load games for filter
    try {
        $stmt = dbQuery($pdo,
            "SELECT DISTINCT gs.id, gs.game_date,
                    t.team_name, t2.team_name AS opponent_name
             FROM game_schedules gs
             INNER JOIN team_roster tr ON tr.team_id = gs.team_id
             LEFT JOIN teams t ON t.id = gs.team_id
             LEFT JOIN teams t2 ON t2.id = gs.opponent_team_id
             INNER JOIN vr_video_clips vc ON vc.game_schedule_id = gs.id
             INNER JOIN vr_clip_athletes ca ON ca.clip_id = vc.id AND ca.athlete_id = :uid
             WHERE tr.user_id = :uid2
             ORDER BY gs.game_date DESC",
            [':uid' => $user_id, ':uid2' => $user_id]
        );
        $games = $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log('My clips - games error: ' . $e->getMessage());
    }

    // Load clips
    try {
        $params = [':uid' => $user_id];
        $where = [];

        $sql = "SELECT vc.id, vc.title, vc.description, vc.thumbnail_path, vc.duration,
                       vc.created_at, vc.game_schedule_id, vc.clip_file_path,
                       vs.camera_angle, vs.title AS source_title,
                       ca.role_in_clip,
                       gs.game_date,
                       t2.team_name AS opponent_name,
                       GROUP_CONCAT(DISTINCT vt.name ORDER BY vt.category SEPARATOR ', ') AS tag_names,
                       GROUP_CONCAT(DISTINCT vt.color SEPARATOR ',') AS tag_colors,
                       GROUP_CONCAT(DISTINCT vt.id SEPARATOR ',') AS tag_ids
                FROM vr_clip_athletes ca
                INNER JOIN vr_video_clips vc ON vc.id = ca.clip_id
                LEFT JOIN vr_video_sources vs ON vs.id = vc.source_video_id
                LEFT JOIN game_schedules gs ON gs.id = vc.game_schedule_id
                LEFT JOIN teams t2 ON t2.id = gs.opponent_team_id
                LEFT JOIN vr_clip_tags ct ON ct.clip_id = vc.id
                LEFT JOIN vr_tags vt ON vt.id = ct.tag_id
                WHERE ca.athlete_id = :uid";

        if ($filterTag) {
            $where[] = "ct.tag_id = :tag_id";
            $params[':tag_id'] = $filterTag;
        }
        if ($filterGame) {
            $where[] = "vc.game_schedule_id = :game_id";
            $params[':game_id'] = $filterGame;
        }
        if ($filterDateFrom) {
            $where[] = "vc.created_at >= :date_from";
            $params[':date_from'] = $filterDateFrom . ' 00:00:00';
        }
        if ($filterDateTo) {
            $where[] = "vc.created_at <= :date_to";
            $params[':date_to'] = $filterDateTo . ' 23:59:59';
        }
        if ($filterSearch) {
            $where[] = "(vc.title LIKE :search OR vc.description LIKE :search2)";
            $params[':search'] = "%$filterSearch%";
            $params[':search2'] = "%$filterSearch%";
        }

        if (!empty($where)) {
            $sql .= " AND " . implode(' AND ', $where);
        }

        $sql .= " GROUP BY vc.id ORDER BY vc.created_at DESC LIMIT 100";

        $stmt = dbQuery($pdo, $sql, $params);
        $clips = $stmt->fetchAll();
        $clipCount = count($clips);
    } catch (PDOException $e) {
        error_log('My clips - clips query error: ' . $e->getMessage());
    }
}

$categoryLabels = [
    'zone'      => 'Zone',
    'skill'     => 'Skill',
    'situation' => 'Situation',
    'custom'    => 'Custom',
];
?>

<!-- Page Header -->
<div class="page-header">
    <div class="page-header-content">
        <h1 class="page-title"><i class="fa-solid fa-scissors"></i> My Clips</h1>
        <p class="page-description">All video clips you've been tagged in</p>
    </div>
</div>

<!-- Filters -->
<div class="card" style="margin-bottom:16px;">
    <div class="card-body">
        <form method="GET" action="" style="display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;">
            <input type="hidden" name="page" value="my_clips">

            <div class="form-group" style="flex:1;min-width:160px;">
                <label class="form-label" style="font-size:12px;color:var(--text-muted);display:block;margin-bottom:4px;">Tag</label>
                <select name="tag" class="form-select" data-filter="tag">
                    <option value="">All Tags</option>
                    <?php foreach ($allTags as $tag): ?>
                        <option value="<?= (int)$tag['id'] ?>" <?= $filterTag === (int)$tag['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($tag['name']) ?> (<?= $categoryLabels[$tag['category']] ?? $tag['category'] ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group" style="flex:1;min-width:180px;">
                <label class="form-label" style="font-size:12px;color:var(--text-muted);display:block;margin-bottom:4px;">Game</label>
                <select name="game_id" class="form-select" data-filter="game">
                    <option value="">All Games</option>
                    <?php foreach ($games as $g): ?>
                        <option value="<?= (int)$g['id'] ?>" <?= $filterGame === (int)$g['id'] ? 'selected' : '' ?>>
                            <?= !empty($g['game_date']) ? date('M j, Y', strtotime($g['game_date'])) : 'Unknown' ?>
                            — vs <?= htmlspecialchars($g['opponent_name'] ?? 'Opponent') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group" style="min-width:130px;">
                <label class="form-label" style="font-size:12px;color:var(--text-muted);display:block;margin-bottom:4px;">Date From</label>
                <input type="date" name="date_from" class="form-input" value="<?= htmlspecialchars($filterDateFrom) ?>">
            </div>

            <div class="form-group" style="min-width:130px;">
                <label class="form-label" style="font-size:12px;color:var(--text-muted);display:block;margin-bottom:4px;">Date To</label>
                <input type="date" name="date_to" class="form-input" value="<?= htmlspecialchars($filterDateTo) ?>">
            </div>

            <div class="form-group" style="flex:1;min-width:160px;">
                <label class="form-label" style="font-size:12px;color:var(--text-muted);display:block;margin-bottom:4px;">Search</label>
                <input type="text" name="search" class="form-input" placeholder="Search clips..." value="<?= htmlspecialchars($filterSearch) ?>">
            </div>

            <div class="form-group">
                <button type="submit" class="btn btn-primary btn-sm"><i class="fa-solid fa-search"></i> Filter</button>
                <a href="?page=my_clips" class="btn btn-secondary btn-sm"><i class="fa-solid fa-times"></i> Clear</a>
            </div>
        </form>
    </div>
</div>

<!-- Results Count -->
<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;">
    <div style="font-size:14px;color:var(--text-secondary);">
        <strong><?= $clipCount ?></strong> clip<?= $clipCount !== 1 ? 's' : '' ?>
    </div>
</div>

<!-- Clips Grid -->
<?php if (empty($clips)): ?>
    <div class="empty-state-card">
        <div class="empty-icon"><i class="fa-solid fa-film"></i></div>
        <p>No clips found. You'll see clips here once a coach tags you in game footage.</p>
        <?php if ($filterTag || $filterGame || $filterDateFrom || $filterDateTo || $filterSearch): ?>
            <a href="?page=my_clips" class="btn btn-secondary" style="margin-top:12px;">Clear Filters</a>
        <?php endif; ?>
    </div>
<?php else: ?>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:16px;">
        <?php foreach ($clips as $clip): ?>
            <div class="clip-card" data-clip-id="<?= (int)$clip['id'] ?>"
                 data-clip-file="<?= htmlspecialchars($clip['clip_file_path'] ?? '') ?>"
                 style="cursor:pointer;">
                <div class="clip-thumbnail">
                    <?php if (!empty($clip['thumbnail_path'])): ?>
                        <img src="<?= htmlspecialchars($clip['thumbnail_path']) ?>" alt="Clip thumbnail" loading="lazy">
                    <?php else: ?>
                        <div style="display:flex;align-items:center;justify-content:center;height:100%;background:var(--bg-secondary);">
                            <i class="fa-solid fa-play-circle" style="font-size:36px;color:var(--primary-light);"></i>
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

                    <?php if (!empty($clip['tag_names'])): ?>
                        <div style="margin-top:6px;display:flex;flex-wrap:wrap;gap:4px;">
                            <?php
                            $tagNames = explode(', ', $clip['tag_names']);
                            $tagColors = explode(',', $clip['tag_colors'] ?? '');
                            ?>
                            <?php foreach ($tagNames as $i => $tagName): ?>
                                <span class="badge" style="font-size:10px;background:<?= htmlspecialchars($tagColors[$i] ?? 'var(--primary)') ?>;color:#fff;">
                                    <?= htmlspecialchars($tagName) ?>
                                </span>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <div style="font-size:12px;color:var(--text-muted);margin-top:6px;display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
                        <?php if (!empty($clip['role_in_clip'])): ?>
                            <span class="badge badge-primary" style="font-size:10px;"><?= htmlspecialchars($clip['role_in_clip']) ?></span>
                        <?php endif; ?>
                        <?php if (!empty($clip['camera_angle'])): ?>
                            <span><i class="fa-solid fa-video" style="margin-right:2px;"></i><?= htmlspecialchars($clip['camera_angle']) ?></span>
                        <?php endif; ?>
                        <?php if (!empty($clip['opponent_name'])): ?>
                            <span>vs <?= htmlspecialchars($clip['opponent_name']) ?></span>
                        <?php endif; ?>
                        <?php if (!empty($clip['game_date'])): ?>
                            <span><?= date('M j', strtotime($clip['game_date'])) ?></span>
                        <?php endif; ?>
                        <span><?= timeAgo($clip['created_at']) ?></span>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<!-- Video Player Overlay -->
<div class="modal-overlay" id="myClipsPlayerOverlay" style="display:none;" data-action="close-video">
    <div class="modal" style="max-width:900px;width:95%;padding:0;overflow:hidden;">
        <div style="display:flex;align-items:center;justify-content:space-between;padding:12px 16px;border-bottom:1px solid var(--border);">
            <h3 id="myClipsPlayerTitle" style="font-size:15px;font-weight:600;color:var(--text-white);margin:0;">Clip</h3>
            <button class="btn btn-sm btn-secondary" id="closeMyClipsPlayer" data-action="close-video">
                <i class="fa-solid fa-times"></i>
            </button>
        </div>
        <div class="video-player-container" style="background:#000;">
            <video class="video-player" id="myClipsVideoPlayer" controls style="width:100%;max-height:500px;"></video>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Clip card click → open video player
    document.querySelectorAll('.clip-card[data-clip-id]').forEach(function(card) {
        card.addEventListener('click', function() {
            var clipId = this.dataset.clipId;
            var clipFile = this.dataset.clipFile;
            var overlay = document.getElementById('myClipsPlayerOverlay');
            var player = document.getElementById('myClipsVideoPlayer');
            var titleEl = document.getElementById('myClipsPlayerTitle');
            var titleText = this.querySelector('.truncate');

            if (titleText) titleEl.textContent = titleText.textContent.trim();
            if (clipFile) {
                player.src = clipFile;
                player.load();
            }
            overlay.style.display = 'flex';
        });
    });

    function closePlayer() {
        var overlay = document.getElementById('myClipsPlayerOverlay');
        var player = document.getElementById('myClipsVideoPlayer');
        overlay.style.display = 'none';
        player.pause();
        player.removeAttribute('src');
    }

    document.getElementById('closeMyClipsPlayer')?.addEventListener('click', closePlayer);
    document.getElementById('myClipsPlayerOverlay')?.addEventListener('click', function(e) {
        if (e.target === this) closePlayer();
    });
});
</script>
