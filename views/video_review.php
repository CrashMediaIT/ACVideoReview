<?php
/**
 * Video Review Page (Athletes & Coaches)
 * Variables available: $pdo, $user_id, $user_role, $team_id, $user_name, $isCoach, $isAdmin, $csrf_token
 */

$activeTab = isset($_GET['tab']) ? sanitizeInput($_GET['tab']) : 'clips';
$validTabs = ['clips', 'by_game', 'opponents'];
if (!in_array($activeTab, $validTabs, true)) {
    $activeTab = 'clips';
}

// Shared data
$tagCategories = [];
$allTags = [];

if (defined('DB_CONNECTED') && DB_CONNECTED && $pdo) {
    try {
        $stmt = dbQuery($pdo, "SELECT id, name, category, color, icon FROM vr_tags WHERE is_active = 1 ORDER BY category, name", []);
        $allTags = $stmt->fetchAll();
        foreach ($allTags as $tag) {
            $tagCategories[$tag['category']][] = $tag;
        }
    } catch (PDOException $e) {
        error_log('Video review - tags error: ' . $e->getMessage());
    }
}

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

$categoryLabels = [
    'zone'      => 'Zone',
    'skill'     => 'Skill',
    'situation' => 'Situation',
    'custom'    => 'Custom',
];
?>

<!-- Page Header -->
<div class="page-header">
    <div class="page-header-icon"><i class="fas fa-film"></i></div>
    <div class="page-header-info">
        <h1 class="page-title">Video Review</h1>
        <p class="page-description">Review tagged game footage and clips</p>
    </div>
</div>

<!-- Tabs -->
<div class="page-tabs-wrapper" style="margin-bottom:24px;">
    <div class="page-tabs">
        <a href="?page=video_review&tab=clips"
           class="page-tab <?= $activeTab === 'clips' ? 'active' : '' ?>">
            <i class="fas fa-film"></i> <?= $isCoach ? 'All Clips' : 'My Clips' ?>
        </a>
        <a href="?page=video_review&tab=by_game"
           class="page-tab <?= $activeTab === 'by_game' ? 'active' : '' ?>">
            <i class="fas fa-hockey-puck"></i> By Game
        </a>
        <a href="?page=video_review&tab=opponents"
           class="page-tab <?= $activeTab === 'opponents' ? 'active' : '' ?>">
            <i class="fas fa-binoculars"></i> Opponent Scouting
        </a>
    </div>
</div>

<?php
// ============================================================
// TAB 1: CLIPS
// ============================================================
if ($activeTab === 'clips'):

$clips = [];
$clipCount = 0;
$filterCategory = isset($_GET['category']) ? sanitizeInput($_GET['category']) : '';
$filterTag = isset($_GET['tag']) ? (int)$_GET['tag'] : 0;
$filterSearch = isset($_GET['search']) ? sanitizeInput($_GET['search']) : '';
$filterDateFrom = isset($_GET['date_from']) ? sanitizeInput($_GET['date_from']) : '';
$filterDateTo = isset($_GET['date_to']) ? sanitizeInput($_GET['date_to']) : '';
$viewMode = isset($_GET['view']) ? sanitizeInput($_GET['view']) : 'grid';

if (defined('DB_CONNECTED') && DB_CONNECTED && $pdo) {
    try {
        $params = [];
        $where = [];

        if ($isCoach) {
            // Coaches see all clips
            $sql = "SELECT vc.id, vc.title, vc.description, vc.thumbnail_path, vc.duration,
                           vc.created_at, vc.game_schedule_id, vc.is_published,
                           vs.camera_angle, vs.title AS source_title,
                           GROUP_CONCAT(DISTINCT vt.name ORDER BY vt.category SEPARATOR ', ') AS tag_names,
                           GROUP_CONCAT(DISTINCT vt.color SEPARATOR ',') AS tag_colors,
                           GROUP_CONCAT(DISTINCT vt.category SEPARATOR ',') AS tag_cats
                    FROM vr_video_clips vc
                    LEFT JOIN vr_video_sources vs ON vs.id = vc.source_video_id
                    LEFT JOIN vr_clip_tags ct ON ct.clip_id = vc.id
                    LEFT JOIN vr_tags vt ON vt.id = ct.tag_id";
        } else {
            // Athletes only see clips they're tagged in
            $sql = "SELECT vc.id, vc.title, vc.description, vc.thumbnail_path, vc.duration,
                           vc.created_at, vc.game_schedule_id, vc.is_published,
                           vs.camera_angle, vs.title AS source_title,
                           ca.role_in_clip,
                           GROUP_CONCAT(DISTINCT vt.name ORDER BY vt.category SEPARATOR ', ') AS tag_names,
                           GROUP_CONCAT(DISTINCT vt.color SEPARATOR ',') AS tag_colors,
                           GROUP_CONCAT(DISTINCT vt.category SEPARATOR ',') AS tag_cats
                    FROM vr_video_clips vc
                    INNER JOIN vr_clip_athletes ca ON ca.clip_id = vc.id AND ca.athlete_id = :uid
                    LEFT JOIN vr_video_sources vs ON vs.id = vc.source_video_id
                    LEFT JOIN vr_clip_tags ct ON ct.clip_id = vc.id
                    LEFT JOIN vr_tags vt ON vt.id = ct.tag_id";
            $params[':uid'] = $user_id;
        }

        // Filters
        if ($filterCategory) {
            $where[] = "vt.category = :cat";
            $params[':cat'] = $filterCategory;
        }
        if ($filterTag) {
            $where[] = "ct.tag_id = :tag_id";
            $params[':tag_id'] = $filterTag;
        }
        if ($filterSearch) {
            $where[] = "(vc.title LIKE :search OR vc.description LIKE :search2)";
            $params[':search'] = "%$filterSearch%";
            $params[':search2'] = "%$filterSearch%";
        }
        if ($filterDateFrom) {
            $where[] = "vc.created_at >= :date_from";
            $params[':date_from'] = $filterDateFrom . ' 00:00:00';
        }
        if ($filterDateTo) {
            $where[] = "vc.created_at <= :date_to";
            $params[':date_to'] = $filterDateTo . ' 23:59:59';
        }

        if (!empty($where)) {
            $sql .= " WHERE " . implode(' AND ', $where);
        }

        $sql .= " GROUP BY vc.id ORDER BY vc.created_at DESC LIMIT 50";

        $stmt = dbQuery($pdo, $sql, $params);
        $clips = $stmt->fetchAll();
        $clipCount = count($clips);
    } catch (PDOException $e) {
        error_log('Video review - clips query error: ' . $e->getMessage());
    }
}
?>

<!-- Filters -->
<div class="card" style="margin-bottom:16px;">
    <div class="card-body">
        <form method="GET" action="" style="display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;">
            <input type="hidden" name="page" value="video_review">
            <input type="hidden" name="tab" value="clips">

            <div class="form-group" style="flex:1;min-width:150px;">
                <label class="form-label" style="font-size:12px;color:var(--text-muted);display:block;margin-bottom:4px;">Tag Category</label>
                <select name="category" class="form-select" data-filter="category">
                    <option value="">All Categories</option>
                    <?php foreach ($categoryLabels as $catKey => $catLabel): ?>
                        <option value="<?= $catKey ?>" <?= $filterCategory === $catKey ? 'selected' : '' ?>>
                            <?= $catLabel ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group" style="flex:1;min-width:150px;">
                <label class="form-label" style="font-size:12px;color:var(--text-muted);display:block;margin-bottom:4px;">Specific Tag</label>
                <select name="tag" class="form-select" data-filter="tag">
                    <option value="">All Tags</option>
                    <?php foreach ($allTags as $tag): ?>
                        <option value="<?= (int)$tag['id'] ?>" <?= $filterTag === (int)$tag['id'] ? 'selected' : '' ?>
                                data-category="<?= htmlspecialchars($tag['category']) ?>">
                            <?= htmlspecialchars($tag['name']) ?> (<?= $categoryLabels[$tag['category']] ?? $tag['category'] ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group" style="min-width:130px;">
                <label class="form-label" style="font-size:12px;color:var(--text-muted);display:block;margin-bottom:4px;">Date From</label>
                <input type="date" name="date_from" class="form-input" value="<?= htmlspecialchars($filterDateFrom) ?>" data-filter="date_from">
            </div>

            <div class="form-group" style="min-width:130px;">
                <label class="form-label" style="font-size:12px;color:var(--text-muted);display:block;margin-bottom:4px;">Date To</label>
                <input type="date" name="date_to" class="form-input" value="<?= htmlspecialchars($filterDateTo) ?>" data-filter="date_to">
            </div>

            <div class="form-group" style="flex:1;min-width:180px;">
                <label class="form-label" style="font-size:12px;color:var(--text-muted);display:block;margin-bottom:4px;">Search</label>
                <input type="text" name="search" class="form-input" placeholder="Search clips..." value="<?= htmlspecialchars($filterSearch) ?>" data-filter="search">
            </div>

            <div class="form-group">
                <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-search"></i> Filter</button>
                <a href="?page=video_review&tab=clips" class="btn btn-secondary btn-sm"><i class="fas fa-times"></i> Clear</a>
            </div>
        </form>
    </div>
</div>

<!-- Action Bar -->
<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;">
    <div style="font-size:14px;color:var(--text-secondary);">
        <strong><?= $clipCount ?></strong> clip<?= $clipCount !== 1 ? 's' : '' ?> found
    </div>
    <div style="display:flex;gap:8px;">
        <a href="?page=video_review&tab=clips&view=grid<?= $filterCategory ? '&category=' . urlencode($filterCategory) : '' ?><?= $filterTag ? '&tag=' . $filterTag : '' ?>"
           class="btn btn-sm <?= $viewMode === 'grid' ? 'btn-primary' : 'btn-secondary' ?>" data-view="grid">
            <i class="fas fa-th"></i>
        </a>
        <a href="?page=video_review&tab=clips&view=list<?= $filterCategory ? '&category=' . urlencode($filterCategory) : '' ?><?= $filterTag ? '&tag=' . $filterTag : '' ?>"
           class="btn btn-sm <?= $viewMode === 'list' ? 'btn-primary' : 'btn-secondary' ?>" data-view="list">
            <i class="fas fa-list"></i>
        </a>
    </div>
</div>

<!-- Clips Display -->
<?php if (empty($clips)): ?>
    <div class="empty-state-card">
        <div class="empty-icon"><i class="fas fa-film"></i></div>
        <p><?= $isCoach ? 'No clips found. Head to the Film Room to create clips from game footage.' : 'No clips found. Check back after your coach tags you in game footage.' ?></p>
        <?php if ($isCoach): ?>
            <a href="?page=film_room" class="btn btn-primary" style="margin-top:12px;">Go to Film Room</a>
        <?php endif; ?>
    </div>
<?php elseif ($viewMode === 'list'): ?>
    <!-- List View -->
    <div class="card">
        <div class="card-body" style="padding:0;">
            <?php foreach ($clips as $clip): ?>
                <div class="session-list-card" data-clip-id="<?= (int)$clip['id'] ?>" style="cursor:pointer;">
                    <div style="width:80px;height:50px;border-radius:6px;overflow:hidden;flex-shrink:0;background:var(--bg-secondary);">
                        <?php if (!empty($clip['thumbnail_path'])): ?>
                            <img src="<?= htmlspecialchars($clip['thumbnail_path']) ?>" alt="" style="width:100%;height:100%;object-fit:cover;" loading="lazy">
                        <?php else: ?>
                            <div style="display:flex;align-items:center;justify-content:center;height:100%;">
                                <i class="fas fa-play" style="color:var(--primary-light);"></i>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="session-details-column" style="flex:1;">
                        <div style="font-weight:600;color:var(--text-white);">
                            <?= htmlspecialchars($clip['title']) ?>
                        </div>
                        <div style="font-size:12px;color:var(--text-muted);margin-top:2px;display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                            <?php if (!empty($clip['duration'])): ?>
                                <span><i class="fas fa-clock"></i> <?= formatDuration((int)$clip['duration']) ?></span>
                            <?php endif; ?>
                            <?php if (!empty($clip['camera_angle'])): ?>
                                <span><i class="fas fa-video"></i> <?= htmlspecialchars($clip['camera_angle']) ?></span>
                            <?php endif; ?>
                            <span><?= timeAgo($clip['created_at']) ?></span>
                            <?php if (!empty($clip['tag_names'])): ?>
                                <?php foreach (explode(', ', $clip['tag_names']) as $i => $tagName): ?>
                                    <?php $colors = explode(',', $clip['tag_colors'] ?? ''); ?>
                                    <span class="badge" style="font-size:10px;background:<?= htmlspecialchars($colors[$i] ?? 'var(--primary)') ?>;color:#fff;">
                                        <?= htmlspecialchars($tagName) ?>
                                    </span>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
<?php else: ?>
    <!-- Grid View -->
    <?php
    // Group clips by tag category
    $groupedClips = [];
    foreach ($clips as $clip) {
        $cats = !empty($clip['tag_cats']) ? array_unique(explode(',', $clip['tag_cats'])) : ['uncategorized'];
        foreach ($cats as $cat) {
            $groupedClips[trim($cat)][] = $clip;
        }
    }
    ?>
    <?php foreach ($groupedClips as $catKey => $catClips): ?>
        <div style="margin-bottom:24px;">
            <h3 style="font-size:16px;font-weight:600;color:var(--text-white);margin-bottom:12px;">
                <i class="fas fa-tag" style="color:var(--primary-light);margin-right:6px;"></i>
                <?= htmlspecialchars($categoryLabels[$catKey] ?? ucfirst($catKey)) ?>
                <span style="font-size:13px;color:var(--text-muted);font-weight:400;">(<?= count($catClips) ?>)</span>
            </h3>
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:16px;">
                <?php foreach ($catClips as $clip): ?>
                    <div class="clip-card" data-clip-id="<?= (int)$clip['id'] ?>" style="cursor:pointer;">
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
                            <div style="font-size:12px;color:var(--text-muted);margin-top:4px;display:flex;flex-wrap:wrap;gap:4px;align-items:center;">
                                <?php if (!empty($clip['tag_names'])): ?>
                                    <?php foreach (explode(', ', $clip['tag_names']) as $i => $tagName): ?>
                                        <?php $colors = explode(',', $clip['tag_colors'] ?? ''); ?>
                                        <span class="badge" style="font-size:10px;background:<?= htmlspecialchars($colors[$i] ?? 'var(--primary)') ?>;color:#fff;">
                                            <?= htmlspecialchars($tagName) ?>
                                        </span>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                            <div style="font-size:11px;color:var(--text-muted);margin-top:4px;">
                                <?php if (!empty($clip['camera_angle'])): ?>
                                    <?= htmlspecialchars($clip['camera_angle']) ?> &middot;
                                <?php endif; ?>
                                <?= timeAgo($clip['created_at']) ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<?php
// ============================================================
// TAB 2: BY GAME
// ============================================================
elseif ($activeTab === 'by_game'):

$games = [];
$selectedGameId = isset($_GET['game_id']) ? (int)$_GET['game_id'] : 0;
$gameClips = [];
$byGameView = isset($_GET['by_game_view']) ? sanitizeInput($_GET['by_game_view']) : 'list';

if (defined('DB_CONNECTED') && DB_CONNECTED && $pdo) {
    try {
        if ($isCoach) {
            $stmt = dbQuery($pdo,
                "SELECT gs.id, gs.game_date, gs.location, gs.home_score, gs.away_score,
                        gs.team_id, gs.opponent_team_id,
                        t.team_name, t2.team_name AS opponent_name,
                        (SELECT COUNT(*) FROM vr_video_clips vc2 WHERE vc2.game_schedule_id = gs.id) AS clip_count
                 FROM game_schedules gs
                 LEFT JOIN teams t ON t.id = gs.team_id
                 LEFT JOIN teams t2 ON t2.id = gs.opponent_team_id
                 ORDER BY gs.game_date DESC
                 LIMIT 50",
                []
            );
        } else {
            $stmt = dbQuery($pdo,
                "SELECT gs.id, gs.game_date, gs.location, gs.home_score, gs.away_score,
                        gs.team_id, gs.opponent_team_id,
                        t.team_name, t2.team_name AS opponent_name,
                        (SELECT COUNT(*) FROM vr_video_clips vc2
                         INNER JOIN vr_clip_athletes ca2 ON ca2.clip_id = vc2.id AND ca2.athlete_id = :uid2
                         WHERE vc2.game_schedule_id = gs.id) AS clip_count
                 FROM game_schedules gs
                 INNER JOIN team_roster tr ON tr.team_id = gs.team_id
                 LEFT JOIN teams t ON t.id = gs.team_id
                 LEFT JOIN teams t2 ON t2.id = gs.opponent_team_id
                 WHERE tr.user_id = :uid
                 ORDER BY gs.game_date DESC
                 LIMIT 50",
                [':uid' => $user_id, ':uid2' => $user_id]
            );
        }
        $games = $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log('Video review - games query error: ' . $e->getMessage());
    }

    // If a game is selected, load its clips
    if ($selectedGameId > 0) {
        try {
            $params = [':gid' => $selectedGameId];
            if ($isCoach) {
                $sql = "SELECT vc.id, vc.title, vc.thumbnail_path, vc.duration, vc.created_at,
                               vs.camera_angle,
                               GROUP_CONCAT(DISTINCT vt.name SEPARATOR ', ') AS tag_names,
                               GROUP_CONCAT(DISTINCT vt.color SEPARATOR ',') AS tag_colors
                        FROM vr_video_clips vc
                        LEFT JOIN vr_video_sources vs ON vs.id = vc.source_video_id
                        LEFT JOIN vr_clip_tags ct ON ct.clip_id = vc.id
                        LEFT JOIN vr_tags vt ON vt.id = ct.tag_id
                        WHERE vc.game_schedule_id = :gid
                        GROUP BY vc.id
                        ORDER BY vc.start_time ASC";
            } else {
                $sql = "SELECT vc.id, vc.title, vc.thumbnail_path, vc.duration, vc.created_at,
                               vs.camera_angle, ca.role_in_clip,
                               GROUP_CONCAT(DISTINCT vt.name SEPARATOR ', ') AS tag_names,
                               GROUP_CONCAT(DISTINCT vt.color SEPARATOR ',') AS tag_colors
                        FROM vr_video_clips vc
                        INNER JOIN vr_clip_athletes ca ON ca.clip_id = vc.id AND ca.athlete_id = :uid
                        LEFT JOIN vr_video_sources vs ON vs.id = vc.source_video_id
                        LEFT JOIN vr_clip_tags ct ON ct.clip_id = vc.id
                        LEFT JOIN vr_tags vt ON vt.id = ct.tag_id
                        WHERE vc.game_schedule_id = :gid
                        GROUP BY vc.id
                        ORDER BY vc.start_time ASC";
                $params[':uid'] = $user_id;
            }
            $stmt = dbQuery($pdo, $sql, $params);
            $gameClips = $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log('Video review - game clips query error: ' . $e->getMessage());
        }
    }
}
?>

<!-- View Toggle -->
<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;">
    <div style="font-size:14px;color:var(--text-secondary);">
        <strong><?= count($games) ?></strong> game<?= count($games) !== 1 ? 's' : '' ?>
    </div>
    <div style="display:flex;gap:8px;">
        <a href="?page=video_review&tab=by_game&by_game_view=list"
           class="btn btn-sm <?= $byGameView === 'list' ? 'btn-primary' : 'btn-secondary' ?>">
            <i class="fas fa-list"></i> List
        </a>
        <a href="?page=video_review&tab=by_game&by_game_view=calendar"
           class="btn btn-sm <?= $byGameView === 'calendar' ? 'btn-primary' : 'btn-secondary' ?>">
            <i class="fas fa-calendar"></i> Calendar
        </a>
    </div>
</div>

<?php if ($byGameView === 'calendar'): ?>
    <!-- Calendar View -->
    <?php
    $currentMonth = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('n');
    $currentYear = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
    $daysInMonth = cal_days_in_month(CAL_GREGORIAN, $currentMonth, $currentYear);
    $firstDay = (int)date('w', mktime(0, 0, 0, $currentMonth, 1, $currentYear));
    $monthName = date('F Y', mktime(0, 0, 0, $currentMonth, 1, $currentYear));

    // Index games by day
    $gamesByDay = [];
    foreach ($games as $game) {
        if (!empty($game['game_date'])) {
            $gDate = date('Y-n-j', strtotime($game['game_date']));
            $gMonth = (int)date('n', strtotime($game['game_date']));
            $gYear = (int)date('Y', strtotime($game['game_date']));
            if ($gMonth === $currentMonth && $gYear === $currentYear) {
                $day = (int)date('j', strtotime($game['game_date']));
                $gamesByDay[$day][] = $game;
            }
        }
    }

    $prevMonth = $currentMonth - 1;
    $prevYear = $currentYear;
    if ($prevMonth < 1) { $prevMonth = 12; $prevYear--; }
    $nextMonth = $currentMonth + 1;
    $nextYear = $currentYear;
    if ($nextMonth > 12) { $nextMonth = 1; $nextYear++; }
    ?>
    <div class="card">
        <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;">
            <a href="?page=video_review&tab=by_game&by_game_view=calendar&month=<?= $prevMonth ?>&year=<?= $prevYear ?>" class="btn btn-sm btn-secondary">
                <i class="fas fa-chevron-left"></i>
            </a>
            <h3><?= $monthName ?></h3>
            <a href="?page=video_review&tab=by_game&by_game_view=calendar&month=<?= $nextMonth ?>&year=<?= $nextYear ?>" class="btn btn-sm btn-secondary">
                <i class="fas fa-chevron-right"></i>
            </a>
        </div>
        <div class="card-body">
            <div class="calendar-view">
                <div class="calendar-grid" style="display:grid;grid-template-columns:repeat(7,1fr);gap:2px;">
                    <?php $dayNames = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat']; ?>
                    <?php foreach ($dayNames as $dn): ?>
                        <div style="text-align:center;font-size:12px;font-weight:600;color:var(--text-muted);padding:8px;">
                            <?= $dn ?>
                        </div>
                    <?php endforeach; ?>

                    <?php for ($i = 0; $i < $firstDay; $i++): ?>
                        <div class="calendar-day" style="min-height:80px;background:var(--bg-main);border-radius:4px;padding:4px;"></div>
                    <?php endfor; ?>

                    <?php for ($d = 1; $d <= $daysInMonth; $d++): ?>
                        <?php $isToday = ($d === (int)date('j') && $currentMonth === (int)date('n') && $currentYear === (int)date('Y')); ?>
                        <div class="calendar-day" style="min-height:80px;background:var(--bg-secondary);border-radius:4px;padding:4px;<?= $isToday ? 'border:1px solid var(--primary);' : '' ?>">
                            <div style="font-size:12px;font-weight:<?= $isToday ? '700' : '400' ?>;color:<?= $isToday ? 'var(--primary-light)' : 'var(--text-secondary)' ?>;margin-bottom:4px;">
                                <?= $d ?>
                            </div>
                            <?php if (isset($gamesByDay[$d])): ?>
                                <?php foreach ($gamesByDay[$d] as $dayGame): ?>
                                    <a href="?page=video_review&tab=by_game&by_game_view=list&game_id=<?= (int)$dayGame['id'] ?>"
                                       class="calendar-event" data-game-id="<?= (int)$dayGame['id'] ?>"
                                       style="display:block;background:var(--primary);color:#fff;font-size:10px;padding:2px 4px;border-radius:3px;margin-bottom:2px;text-decoration:none;overflow:hidden;white-space:nowrap;text-overflow:ellipsis;">
                                        vs <?= htmlspecialchars($dayGame['opponent_name'] ?? 'TBD') ?>
                                        <?php if ($dayGame['clip_count'] > 0): ?>
                                            <span style="opacity:0.7;">(<?= $dayGame['clip_count'] ?>)</span>
                                        <?php endif; ?>
                                    </a>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    <?php endfor; ?>
                </div>
            </div>
        </div>
    </div>

<?php else: ?>
    <!-- List View -->
    <?php if (empty($games)): ?>
        <div class="empty-state-card">
            <div class="empty-icon"><i class="fas fa-hockey-puck"></i></div>
            <p>No games found.</p>
        </div>
    <?php else: ?>
        <?php foreach ($games as $game): ?>
            <?php $isSelected = ($selectedGameId === (int)$game['id']); ?>
            <div class="card" style="margin-bottom:12px;">
                <a href="?page=video_review&tab=by_game&game_id=<?= $isSelected ? 0 : (int)$game['id'] ?>"
                   class="card-header" style="display:flex;align-items:center;justify-content:space-between;text-decoration:none;cursor:pointer;"
                   data-game-id="<?= (int)$game['id'] ?>">
                    <div style="display:flex;align-items:center;gap:12px;">
                        <div style="text-align:center;min-width:50px;">
                            <div style="font-size:20px;font-weight:700;color:var(--primary-light);">
                                <?= !empty($game['game_date']) ? date('d', strtotime($game['game_date'])) : '--' ?>
                            </div>
                            <div style="font-size:11px;color:var(--text-muted);text-transform:uppercase;">
                                <?= !empty($game['game_date']) ? date('M Y', strtotime($game['game_date'])) : '' ?>
                            </div>
                        </div>
                        <div>
                            <div style="font-weight:600;color:var(--text-white);">
                                <?= htmlspecialchars($game['team_name'] ?? 'Team') ?>
                                vs
                                <?= htmlspecialchars($game['opponent_name'] ?? 'Opponent') ?>
                            </div>
                            <div style="font-size:13px;color:var(--text-secondary);margin-top:2px;">
                                <?php if ($game['home_score'] !== null && $game['away_score'] !== null): ?>
                                    <span>Score: <?= (int)$game['home_score'] ?> - <?= (int)$game['away_score'] ?></span> &middot;
                                <?php endif; ?>
                                <?php if (!empty($game['location'])): ?>
                                    <span><i class="fas fa-map-marker-alt"></i> <?= htmlspecialchars($game['location']) ?></span> &middot;
                                <?php endif; ?>
                                <span><?= (int)$game['clip_count'] ?> clip<?= (int)$game['clip_count'] !== 1 ? 's' : '' ?></span>
                            </div>
                        </div>
                    </div>
                    <i class="fas fa-chevron-<?= $isSelected ? 'up' : 'down' ?>" style="color:var(--text-muted);"></i>
                </a>
                <?php if ($isSelected): ?>
                    <div class="card-body">
                        <?php if (empty($gameClips)): ?>
                            <div class="empty-state-card">
                                <div class="empty-icon"><i class="fas fa-film"></i></div>
                                <p>No clips for this game yet.</p>
                            </div>
                        <?php else: ?>
                            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:12px;">
                                <?php foreach ($gameClips as $gc): ?>
                                    <div class="clip-card" data-clip-id="<?= (int)$gc['id'] ?>" style="cursor:pointer;">
                                        <div class="clip-thumbnail">
                                            <?php if (!empty($gc['thumbnail_path'])): ?>
                                                <img src="<?= htmlspecialchars($gc['thumbnail_path']) ?>" alt="" loading="lazy">
                                            <?php else: ?>
                                                <div style="display:flex;align-items:center;justify-content:center;height:100%;background:var(--bg-main);">
                                                    <i class="fas fa-play-circle" style="font-size:24px;color:var(--primary-light);"></i>
                                                </div>
                                            <?php endif; ?>
                                            <?php if (!empty($gc['duration'])): ?>
                                                <span class="clip-duration"><?= formatDuration((int)$gc['duration']) ?></span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="clip-meta">
                                            <div class="truncate" style="font-weight:500;font-size:13px;color:var(--text-white);">
                                                <?= htmlspecialchars($gc['title']) ?>
                                            </div>
                                            <?php if (!empty($gc['tag_names'])): ?>
                                                <div style="margin-top:4px;display:flex;flex-wrap:wrap;gap:3px;">
                                                    <?php foreach (explode(', ', $gc['tag_names']) as $i => $tn): ?>
                                                        <?php $tc = explode(',', $gc['tag_colors'] ?? ''); ?>
                                                        <span class="badge" style="font-size:9px;background:<?= htmlspecialchars($tc[$i] ?? 'var(--primary)') ?>;color:#fff;">
                                                            <?= htmlspecialchars($tn) ?>
                                                        </span>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
<?php endif; ?>

<?php
// ============================================================
// TAB 3: OPPONENT SCOUTING
// ============================================================
elseif ($activeTab === 'opponents'):

$opponentTeams = [];
$selectedOpponent = isset($_GET['opponent_id']) ? (int)$_GET['opponent_id'] : 0;
$opponentClips = [];
$filterSeason = isset($_GET['season']) ? sanitizeInput($_GET['season']) : 'current';

if (defined('DB_CONNECTED') && DB_CONNECTED && $pdo) {
    try {
        if ($isCoach) {
            // Coaches see all opponent teams
            $stmt = dbQuery($pdo,
                "SELECT DISTINCT t.id, t.team_name
                 FROM teams t
                 INNER JOIN game_schedules gs ON gs.opponent_team_id = t.id
                 ORDER BY t.team_name ASC",
                []
            );
        } else {
            // Athletes see only teams in their age group, current + 1 past year
            $stmt = dbQuery($pdo,
                "SELECT DISTINCT t.id, t.team_name
                 FROM teams t
                 INNER JOIN game_schedules gs ON gs.opponent_team_id = t.id
                 INNER JOIN team_roster tr ON tr.team_id = gs.team_id
                 WHERE tr.user_id = :uid
                   AND gs.game_date >= DATE_SUB(CURDATE(), INTERVAL 1 YEAR)
                 ORDER BY t.team_name ASC",
                [':uid' => $user_id]
            );
        }
        $opponentTeams = $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log('Video review - opponent teams error: ' . $e->getMessage());
    }

    // Load clips for selected opponent
    if ($selectedOpponent > 0) {
        try {
            $params = [':opp_id' => $selectedOpponent];
            $dateFilter = '';

            if (!$isCoach) {
                // Athletes: current year + 1 past year
                $dateFilter = " AND gs.game_date >= DATE_SUB(CURDATE(), INTERVAL 1 YEAR)";
            } elseif ($filterSeason === 'past_year') {
                $dateFilter = " AND gs.game_date >= DATE_SUB(CURDATE(), INTERVAL 1 YEAR)";
            }

            $sql = "SELECT vc.id, vc.title, vc.thumbnail_path, vc.duration, vc.created_at,
                           vc.game_schedule_id,
                           vs.camera_angle,
                           gs.game_date, gs.location, gs.home_score, gs.away_score,
                           t_home.team_name AS home_team,
                           GROUP_CONCAT(DISTINCT vt.name SEPARATOR ', ') AS tag_names,
                           GROUP_CONCAT(DISTINCT vt.color SEPARATOR ',') AS tag_colors
                    FROM vr_video_clips vc
                    INNER JOIN game_schedules gs ON gs.id = vc.game_schedule_id
                    LEFT JOIN teams t_home ON t_home.id = gs.team_id
                    LEFT JOIN vr_video_sources vs ON vs.id = vc.source_video_id
                    LEFT JOIN vr_clip_tags ct ON ct.clip_id = vc.id
                    LEFT JOIN vr_tags vt ON vt.id = ct.tag_id
                    WHERE gs.opponent_team_id = :opp_id
                    {$dateFilter}
                    GROUP BY vc.id
                    ORDER BY gs.game_date DESC, vc.start_time ASC
                    LIMIT 50";

            $stmt = dbQuery($pdo, $sql, $params);
            $opponentClips = $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log('Video review - opponent clips error: ' . $e->getMessage());
        }
    }
}
?>

<!-- Team Selector & Season Filter -->
<div class="card" style="margin-bottom:16px;">
    <div class="card-body">
        <form method="GET" action="" style="display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;">
            <input type="hidden" name="page" value="video_review">
            <input type="hidden" name="tab" value="opponents">

            <div class="form-group" style="flex:1;min-width:200px;">
                <label class="form-label" style="font-size:12px;color:var(--text-muted);display:block;margin-bottom:4px;">Opponent Team</label>
                <select name="opponent_id" class="form-select" data-filter="opponent">
                    <option value="">Select a team...</option>
                    <?php foreach ($opponentTeams as $opp): ?>
                        <option value="<?= (int)$opp['id'] ?>" <?= $selectedOpponent === (int)$opp['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($opp['team_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <?php if ($isCoach): ?>
                <div class="form-group" style="min-width:160px;">
                    <label class="form-label" style="font-size:12px;color:var(--text-muted);display:block;margin-bottom:4px;">Season</label>
                    <select name="season" class="form-select" data-filter="season">
                        <option value="current" <?= $filterSeason === 'current' ? 'selected' : '' ?>>All Time</option>
                        <option value="past_year" <?= $filterSeason === 'past_year' ? 'selected' : '' ?>>Past Year</option>
                    </select>
                </div>
            <?php endif; ?>

            <div class="form-group">
                <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-search"></i> Scout</button>
            </div>
        </form>
    </div>
</div>

<?php if (!$selectedOpponent): ?>
    <div class="empty-state-card">
        <div class="empty-icon"><i class="fas fa-binoculars"></i></div>
        <p>Select an opponent team above to view their game footage and scouting clips.</p>
    </div>
<?php elseif (empty($opponentClips)): ?>
    <div class="empty-state-card">
        <div class="empty-icon"><i class="fas fa-video-slash"></i></div>
        <p>No clips found for this opponent. Clips will appear here once game footage is tagged.</p>
    </div>
<?php else: ?>
    <?php
    // Group clips by game
    $clipsByGame = [];
    foreach ($opponentClips as $oc) {
        $gameKey = $oc['game_schedule_id'] ?? 'unknown';
        $clipsByGame[$gameKey][] = $oc;
    }
    ?>
    <?php foreach ($clipsByGame as $gameId => $gClips): ?>
        <?php $firstClip = $gClips[0]; ?>
        <div class="card" style="margin-bottom:16px;">
            <div class="card-header">
                <h3 style="font-size:15px;">
                    <i class="fas fa-hockey-puck" style="color:var(--primary-light);margin-right:6px;"></i>
                    <?= htmlspecialchars($firstClip['home_team'] ?? 'Team') ?> vs Opponent
                    <?php if (!empty($firstClip['game_date'])): ?>
                        &mdash; <?= date('M j, Y', strtotime($firstClip['game_date'])) ?>
                    <?php endif; ?>
                    <?php if ($firstClip['home_score'] !== null && $firstClip['away_score'] !== null): ?>
                        <span style="color:var(--text-muted);font-size:13px;font-weight:400;">
                            (<?= (int)$firstClip['home_score'] ?> - <?= (int)$firstClip['away_score'] ?>)
                        </span>
                    <?php endif; ?>
                </h3>
            </div>
            <div class="card-body">
                <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:12px;">
                    <?php foreach ($gClips as $oc): ?>
                        <div class="clip-card" data-clip-id="<?= (int)$oc['id'] ?>" style="cursor:pointer;">
                            <div class="clip-thumbnail">
                                <?php if (!empty($oc['thumbnail_path'])): ?>
                                    <img src="<?= htmlspecialchars($oc['thumbnail_path']) ?>" alt="" loading="lazy">
                                <?php else: ?>
                                    <div style="display:flex;align-items:center;justify-content:center;height:100%;background:var(--bg-main);">
                                        <i class="fas fa-play-circle" style="font-size:24px;color:var(--primary-light);"></i>
                                    </div>
                                <?php endif; ?>
                                <?php if (!empty($oc['duration'])): ?>
                                    <span class="clip-duration"><?= formatDuration((int)$oc['duration']) ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="clip-meta">
                                <div class="truncate" style="font-weight:500;font-size:13px;color:var(--text-white);">
                                    <?= htmlspecialchars($oc['title']) ?>
                                </div>
                                <?php if (!empty($oc['tag_names'])): ?>
                                    <div style="margin-top:4px;display:flex;flex-wrap:wrap;gap:3px;">
                                        <?php foreach (explode(', ', $oc['tag_names']) as $i => $tn): ?>
                                            <?php $tc = explode(',', $oc['tag_colors'] ?? ''); ?>
                                            <span class="badge" style="font-size:9px;background:<?= htmlspecialchars($tc[$i] ?? 'var(--primary)') ?>;color:#fff;">
                                                <?= htmlspecialchars($tn) ?>
                                            </span>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<?php endif; // end tab switch ?>

<!-- Video Player Overlay -->
<div class="modal-overlay" id="videoPlayerOverlay" style="display:none;" data-action="close-video">
    <div class="modal" style="max-width:900px;width:95%;padding:0;overflow:hidden;">
        <div style="display:flex;align-items:center;justify-content:space-between;padding:12px 16px;border-bottom:1px solid var(--border);">
            <h3 id="videoPlayerTitle" style="font-size:15px;font-weight:600;color:var(--text-white);margin:0;">Clip</h3>
            <button class="btn btn-sm btn-secondary" id="closeVideoPlayer" data-action="close-video">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="video-player-container" style="background:#000;">
            <video class="video-player" id="clipVideoPlayer" controls style="width:100%;max-height:500px;"></video>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Clip card click → open video player
    document.querySelectorAll('.clip-card[data-clip-id]').forEach(function(card) {
        card.addEventListener('click', function() {
            const clipId = this.dataset.clipId;
            const overlay = document.getElementById('videoPlayerOverlay');
            const titleEl = document.getElementById('videoPlayerTitle');
            const titleText = this.querySelector('.truncate');
            if (titleText) titleEl.textContent = titleText.textContent.trim();
            overlay.style.display = 'flex';
        });
    });

    // Close video overlay
    document.getElementById('closeVideoPlayer')?.addEventListener('click', function() {
        const overlay = document.getElementById('videoPlayerOverlay');
        overlay.style.display = 'none';
        document.getElementById('clipVideoPlayer').pause();
    });
    document.getElementById('videoPlayerOverlay')?.addEventListener('click', function(e) {
        if (e.target === this) {
            this.style.display = 'none';
            document.getElementById('clipVideoPlayer').pause();
        }
    });
});
</script>
