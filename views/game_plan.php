<?php
/**
 * Game Plan Builder (Coach Only)
 * Variables available: $pdo, $user_id, $user_role, $team_id, $user_name, $isCoach, $isAdmin, $csrf_token
 */

$activeTab = isset($_GET['tab']) ? sanitizeInput($_GET['tab']) : 'pre_game';
$validTabs = ['pre_game', 'post_game', 'practice'];
if (!in_array($activeTab, $validTabs, true)) {
    $activeTab = 'pre_game';
}

$editPlanId = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$newPlan = isset($_GET['new']) && $_GET['new'] === '1';

$plans = [];
$editPlan = null;
$editLineAssignments = [];
$editDrawPlays = [];
$upcomingGames = [];
$rosterPlayers = [];
$teams = [];

$planTypeLabels = [
    'pre_game'  => 'Pre-Game',
    'post_game' => 'Post-Game',
    'practice'  => 'Practice',
];

$statusBadge = [
    'draft'     => 'badge-warning',
    'published' => 'badge-success',
    'archived'  => 'badge-secondary',
];

$statusLabels = [
    'draft'     => 'Draft',
    'published' => 'Published',
    'archived'  => 'Archived',
];

$playTypeLabels = [
    'offensive'    => 'Offensive',
    'defensive'    => 'Defensive',
    'breakout'     => 'Breakout',
    'forecheck'    => 'Forecheck',
    'power_play'   => 'Power Play',
    'penalty_kill' => 'Penalty Kill',
    'faceoff'      => 'Faceoff',
];

if (defined('DB_CONNECTED') && DB_CONNECTED && $pdo) {
    // Fetch coach's teams
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
        error_log('Game plan - teams error: ' . $e->getMessage());
    }

    // Fetch plans for current tab
    try {
        $stmt = dbQuery($pdo,
            "SELECT gp.id, gp.title, gp.plan_type, gp.status, gp.description,
                    gp.created_at, gp.updated_at, gp.published_at,
                    gp.game_schedule_id, gp.team_id,
                    t.team_name,
                    gs.game_date, gs.location,
                    t2.team_name AS opponent_name
             FROM vr_game_plans gp
             LEFT JOIN teams t ON t.id = gp.team_id
             LEFT JOIN game_schedules gs ON gs.id = gp.game_schedule_id
             LEFT JOIN teams t2 ON t2.id = gs.opponent_team_id
             WHERE gp.plan_type = :plan_type AND gp.created_by = :uid
             ORDER BY gp.updated_at DESC",
            [':plan_type' => $activeTab, ':uid' => $user_id]
        );
        $plans = $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log('Game plan - plans error: ' . $e->getMessage());
    }

    // Fetch upcoming games for dropdown
    try {
        $stmt = dbQuery($pdo,
            "SELECT gs.id, gs.game_date, gs.location,
                    t.team_name, t2.team_name AS opponent_name
             FROM game_schedules gs
             LEFT JOIN teams t ON t.id = gs.team_id
             LEFT JOIN teams t2 ON t2.id = gs.opponent_team_id
             WHERE gs.team_id IN (SELECT team_id FROM team_coach_assignments WHERE coach_id = :uid)
             ORDER BY gs.game_date ASC
             LIMIT 50",
            [':uid' => $user_id]
        );
        $upcomingGames = $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log('Game plan - upcoming games error: ' . $e->getMessage());
    }

    // If editing, fetch plan details
    if ($editPlanId) {
        try {
            $stmt = dbQuery($pdo,
                "SELECT gp.*
                 FROM vr_game_plans gp
                 WHERE gp.id = :id AND gp.created_by = :uid",
                [':id' => $editPlanId, ':uid' => $user_id]
            );
            $editPlan = $stmt->fetch();

            if ($editPlan) {
                // Fetch line assignments
                $stmt = dbQuery($pdo,
                    "SELECT la.id, la.line_type, la.line_number, la.position, la.athlete_id, la.notes,
                            u.first_name, u.last_name
                     FROM vr_line_assignments la
                     LEFT JOIN users u ON u.id = la.athlete_id
                     WHERE la.game_plan_id = :plan_id
                     ORDER BY la.line_type, la.line_number, la.position",
                    [':plan_id' => $editPlanId]
                );
                $editLineAssignments = $stmt->fetchAll();

                // Fetch draw plays
                $stmt = dbQuery($pdo,
                    "SELECT dp.id, dp.title, dp.description, dp.play_type,
                            dp.canvas_data, dp.thumbnail_path, dp.display_order
                     FROM vr_draw_plays dp
                     WHERE dp.game_plan_id = :plan_id
                     ORDER BY dp.display_order ASC",
                    [':plan_id' => $editPlanId]
                );
                $editDrawPlays = $stmt->fetchAll();
            }
        } catch (PDOException $e) {
            error_log('Game plan - edit fetch error: ' . $e->getMessage());
        }
    }

    // Fetch roster players for line assignment dropdowns
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
        error_log('Game plan - roster error: ' . $e->getMessage());
    }
}

// Index line assignments for easy access
$lineMap = [];
foreach ($editLineAssignments as $la) {
    $key = $la['line_type'] . '_' . $la['line_number'] . '_' . $la['position'];
    $lineMap[$key] = $la;
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
?>

<!-- Page Header -->
<div class="page-header">
    <div class="page-header-content">
        <h1 class="page-title"><i class="fa-solid fa-chess-board"></i> Game Plan</h1>
        <p class="page-description">Create pre-game and post-game plans with line assignments</p>
    </div>
</div>

<!-- Tabs -->
<div class="page-tabs-wrapper" style="margin-bottom:24px;">
    <div class="page-tabs">
        <a href="?page=game_plan&tab=pre_game"
           class="page-tab <?= $activeTab === 'pre_game' ? 'active' : '' ?>">
            <i class="fa-solid fa-clipboard-list"></i> Pre-Game Plans
        </a>
        <a href="?page=game_plan&tab=post_game"
           class="page-tab <?= $activeTab === 'post_game' ? 'active' : '' ?>">
            <i class="fa-solid fa-chart-bar"></i> Post-Game Plans
        </a>
        <a href="?page=game_plan&tab=practice"
           class="page-tab <?= $activeTab === 'practice' ? 'active' : '' ?>">
            <i class="fa-solid fa-person-running"></i> Practice Plans
        </a>
    </div>
</div>

<?php if ($editPlanId || $newPlan): ?>
<!-- ============================================================ -->
<!-- CREATE / EDIT PLAN FORM -->
<!-- ============================================================ -->
<form method="POST" action="api/game_plan_save.php" id="gamePlanForm" data-form="game-plan">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
    <input type="hidden" name="plan_id" value="<?= $editPlanId ?>">
    <input type="hidden" name="plan_type" value="<?= htmlspecialchars($activeTab) ?>">

    <!-- Plan Details -->
    <div class="card" style="margin-bottom:24px;">
        <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;">
            <h3><i class="fa-solid fa-pen-to-square"></i> <?= $editPlanId ? 'Edit' : 'New' ?> <?= $planTypeLabels[$activeTab] ?? '' ?> Plan</h3>
            <a href="?page=game_plan&tab=<?= htmlspecialchars($activeTab) ?>" class="btn btn-sm btn-secondary">
                <i class="fa-solid fa-arrow-left"></i> Back to List
            </a>
        </div>
        <div class="card-body">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
                <div style="grid-column:1/-1;">
                    <label class="form-label" style="font-size:12px;color:var(--text-muted);display:block;margin-bottom:4px;">Title</label>
                    <input type="text" name="title" class="form-input" placeholder="Enter plan title..."
                           value="<?= htmlspecialchars($editPlan['title'] ?? '') ?>" data-field="title" required>
                </div>
                <div>
                    <label class="form-label" style="font-size:12px;color:var(--text-muted);display:block;margin-bottom:4px;">Linked Game</label>
                    <select name="game_schedule_id" class="form-select" data-field="game_schedule_id">
                        <option value="">No linked game</option>
                        <?php foreach ($upcomingGames as $game): ?>
                            <option value="<?= (int)$game['id'] ?>"
                                    <?= ($editPlan && (int)($editPlan['game_schedule_id'] ?? 0) === (int)$game['id']) ? 'selected' : '' ?>>
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
                            <option value="<?= (int)$team['id'] ?>"
                                    <?= ($editPlan && (int)($editPlan['team_id'] ?? 0) === (int)$team['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($team['team_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div style="grid-column:1/-1;">
                    <label class="form-label" style="font-size:12px;color:var(--text-muted);display:block;margin-bottom:4px;">Description</label>
                    <textarea name="description" class="form-textarea" rows="3" placeholder="Plan overview..."
                              data-field="description"><?= htmlspecialchars($editPlan['description'] ?? '') ?></textarea>
                </div>
            </div>
        </div>
    </div>

    <!-- Line Assignments -->
    <div class="card" style="margin-bottom:24px;">
        <div class="card-header">
            <h3><i class="fa-solid fa-users"></i> Line Assignments</h3>
        </div>
        <div class="card-body">
            <!-- Forward Lines -->
            <h4 style="font-size:14px;font-weight:600;color:var(--primary-light);margin-bottom:12px;">
                <i class="fa-solid fa-person-skating"></i> Forward Lines
            </h4>
            <?php for ($line = 1; $line <= 4; $line++): ?>
                <div style="margin-bottom:16px;padding:12px;background:var(--bg-secondary);border-radius:var(--radius-md);border:1px solid var(--border);">
                    <div style="font-size:12px;font-weight:600;color:var(--text-muted);margin-bottom:8px;">
                        <?= ['1st','2nd','3rd','4th'][$line-1] ?> Line
                    </div>
                    <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px;">
                        <?php foreach (['LW', 'C', 'RW'] as $pos): ?>
                            <?php $key = 'forward_' . $line . '_' . $pos; ?>
                            <div>
                                <label class="form-label" style="font-size:11px;color:var(--text-muted);display:block;margin-bottom:2px;"><?= $pos ?></label>
                                <select name="lines[forward][<?= $line ?>][<?= $pos ?>]" class="form-select" data-line="forward-<?= $line ?>-<?= $pos ?>">
                                    <option value="">—</option>
                                    <?php foreach ($rosterPlayers as $player): ?>
                                        <option value="<?= (int)$player['id'] ?>"
                                                <?= isset($lineMap[$key]) && (int)$lineMap[$key]['athlete_id'] === (int)$player['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($player['last_name'] . ', ' . $player['first_name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endfor; ?>

            <!-- Defense Pairs -->
            <h4 style="font-size:14px;font-weight:600;color:var(--primary-light);margin-bottom:12px;margin-top:24px;">
                <i class="fa-solid fa-shield-halved"></i> Defense Pairs
            </h4>
            <?php for ($pair = 1; $pair <= 3; $pair++): ?>
                <div style="margin-bottom:16px;padding:12px;background:var(--bg-secondary);border-radius:var(--radius-md);border:1px solid var(--border);">
                    <div style="font-size:12px;font-weight:600;color:var(--text-muted);margin-bottom:8px;">
                        <?= ['1st','2nd','3rd'][$pair-1] ?> Pair
                    </div>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">
                        <?php foreach (['LD', 'RD'] as $pos): ?>
                            <?php $key = 'defense_' . $pair . '_' . $pos; ?>
                            <div>
                                <label class="form-label" style="font-size:11px;color:var(--text-muted);display:block;margin-bottom:2px;"><?= $pos ?></label>
                                <select name="lines[defense][<?= $pair ?>][<?= $pos ?>]" class="form-select" data-line="defense-<?= $pair ?>-<?= $pos ?>">
                                    <option value="">—</option>
                                    <?php foreach ($rosterPlayers as $player): ?>
                                        <option value="<?= (int)$player['id'] ?>"
                                                <?= isset($lineMap[$key]) && (int)$lineMap[$key]['athlete_id'] === (int)$player['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($player['last_name'] . ', ' . $player['first_name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endfor; ?>

            <!-- Goalies -->
            <h4 style="font-size:14px;font-weight:600;color:var(--primary-light);margin-bottom:12px;margin-top:24px;">
                <i class="fa-solid fa-user-shield"></i> Goalies
            </h4>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:24px;">
                <?php foreach (['Starter' => 1, 'Backup' => 2] as $label => $num): ?>
                    <?php $key = 'forward_' . $num . '_G'; // goalies stored as unique key ?>
                    <div style="padding:12px;background:var(--bg-secondary);border-radius:var(--radius-md);border:1px solid var(--border);">
                        <label class="form-label" style="font-size:12px;color:var(--text-muted);display:block;margin-bottom:4px;"><?= $label ?></label>
                        <select name="goalies[<?= $num ?>]" class="form-select" data-line="goalie-<?= $num ?>">
                            <option value="">—</option>
                            <?php foreach ($rosterPlayers as $player): ?>
                                <option value="<?= (int)$player['id'] ?>">
                                    <?= htmlspecialchars($player['last_name'] . ', ' . $player['first_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Power Play -->
            <h4 style="font-size:14px;font-weight:600;color:var(--warning);margin-bottom:12px;margin-top:24px;">
                <i class="fa-solid fa-bolt"></i> Power Play Units
            </h4>
            <?php for ($unit = 1; $unit <= 2; $unit++): ?>
                <div style="margin-bottom:16px;padding:12px;background:var(--bg-secondary);border-radius:var(--radius-md);border:1px solid var(--border);">
                    <div style="font-size:12px;font-weight:600;color:var(--text-muted);margin-bottom:8px;">PP<?= $unit ?></div>
                    <div style="display:grid;grid-template-columns:repeat(5,1fr);gap:8px;">
                        <?php foreach (['LW','C','RW','LD','RD'] as $pos): ?>
                            <?php $key = 'power_play_' . $unit . '_' . $pos; ?>
                            <div>
                                <label class="form-label" style="font-size:11px;color:var(--text-muted);display:block;margin-bottom:2px;"><?= $pos ?></label>
                                <select name="lines[power_play][<?= $unit ?>][<?= $pos ?>]" class="form-select" data-line="pp-<?= $unit ?>-<?= $pos ?>">
                                    <option value="">—</option>
                                    <?php foreach ($rosterPlayers as $player): ?>
                                        <option value="<?= (int)$player['id'] ?>"
                                                <?= isset($lineMap[$key]) && (int)$lineMap[$key]['athlete_id'] === (int)$player['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($player['last_name'] . ', ' . $player['first_name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endfor; ?>

            <!-- Penalty Kill -->
            <h4 style="font-size:14px;font-weight:600;color:var(--info);margin-bottom:12px;margin-top:24px;">
                <i class="fa-solid fa-hand"></i> Penalty Kill Units
            </h4>
            <?php for ($unit = 1; $unit <= 2; $unit++): ?>
                <div style="margin-bottom:16px;padding:12px;background:var(--bg-secondary);border-radius:var(--radius-md);border:1px solid var(--border);">
                    <div style="font-size:12px;font-weight:600;color:var(--text-muted);margin-bottom:8px;">PK<?= $unit ?></div>
                    <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:8px;">
                        <?php foreach (['LW','RW','LD','RD'] as $pos): ?>
                            <?php $key = 'penalty_kill_' . $unit . '_' . $pos; ?>
                            <div>
                                <label class="form-label" style="font-size:11px;color:var(--text-muted);display:block;margin-bottom:2px;"><?= $pos ?></label>
                                <select name="lines[penalty_kill][<?= $unit ?>][<?= $pos ?>]" class="form-select" data-line="pk-<?= $unit ?>-<?= $pos ?>">
                                    <option value="">—</option>
                                    <?php foreach ($rosterPlayers as $player): ?>
                                        <option value="<?= (int)$player['id'] ?>"
                                                <?= isset($lineMap[$key]) && (int)$lineMap[$key]['athlete_id'] === (int)$player['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($player['last_name'] . ', ' . $player['first_name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endfor; ?>
        </div>
    </div>

    <!-- Strategy Section -->
    <div class="card" style="margin-bottom:24px;">
        <div class="card-header">
            <h3><i class="fa-solid fa-lightbulb"></i> Strategy</h3>
        </div>
        <div class="card-body">
            <div style="margin-bottom:16px;">
                <label class="form-label" style="font-size:12px;color:var(--text-muted);display:block;margin-bottom:4px;">Offensive Strategy</label>
                <textarea name="offensive_strategy" class="form-textarea" rows="4" placeholder="Describe offensive strategy, breakout patterns, zone entry..."
                          data-field="offensive_strategy"><?= htmlspecialchars($editPlan['offensive_strategy'] ?? '') ?></textarea>
            </div>
            <div style="margin-bottom:16px;">
                <label class="form-label" style="font-size:12px;color:var(--text-muted);display:block;margin-bottom:4px;">Defensive Strategy</label>
                <textarea name="defensive_strategy" class="form-textarea" rows="4" placeholder="Describe defensive structure, gap control, coverage..."
                          data-field="defensive_strategy"><?= htmlspecialchars($editPlan['defensive_strategy'] ?? '') ?></textarea>
            </div>
            <div>
                <label class="form-label" style="font-size:12px;color:var(--text-muted);display:block;margin-bottom:4px;">Special Teams Notes</label>
                <textarea name="special_teams_notes" class="form-textarea" rows="3" placeholder="Power play setup, penalty kill notes..."
                          data-field="special_teams_notes"><?= htmlspecialchars($editPlan['special_teams_notes'] ?? '') ?></textarea>
            </div>
        </div>
    </div>

    <!-- Play Drawings Section -->
    <div class="card" style="margin-bottom:24px;">
        <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;">
            <h3><i class="fa-solid fa-pen"></i> Play Drawings</h3>
            <?php if ($editPlanId): ?>
                <button type="button" class="btn btn-sm btn-primary" data-action="add-play" onclick="document.getElementById('drawPlayModal').classList.add('active')">
                    <i class="fa-solid fa-plus"></i> Add Play
                </button>
            <?php endif; ?>
        </div>
        <div class="card-body">
            <?php if (!$editPlanId): ?>
                <div style="font-size:13px;color:var(--text-muted);text-align:center;padding:20px;">
                    <i class="fa-solid fa-circle-info"></i> Save the plan first, then you can add play drawings.
                </div>
            <?php elseif (empty($editDrawPlays)): ?>
                <div class="empty-state-card">
                    <div class="empty-icon"><i class="fa-solid fa-pen"></i></div>
                    <p>No play drawings yet. Click "Add Play" to create your first play diagram.</p>
                </div>
            <?php else: ?>
                <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:16px;">
                    <?php foreach ($editDrawPlays as $play): ?>
                        <div class="card" data-play-id="<?= (int)$play['id'] ?>" style="cursor:pointer;">
                            <div style="height:120px;background:var(--bg-secondary);border-radius:var(--radius-md) var(--radius-md) 0 0;display:flex;align-items:center;justify-content:center;overflow:hidden;">
                                <?php if (!empty($play['thumbnail_path'])): ?>
                                    <img src="<?= htmlspecialchars($play['thumbnail_path']) ?>" alt="" style="width:100%;height:100%;object-fit:cover;">
                                <?php else: ?>
                                    <i class="fa-solid fa-hockey-puck" style="font-size:32px;color:var(--primary-light);opacity:0.5;"></i>
                                <?php endif; ?>
                            </div>
                            <div style="padding:10px;">
                                <div style="font-weight:600;font-size:13px;color:var(--text-white);" class="truncate">
                                    <?= htmlspecialchars($play['title']) ?>
                                </div>
                                <div style="margin-top:4px;">
                                    <span class="badge badge-info" style="font-size:10px;">
                                        <?= htmlspecialchars($playTypeLabels[$play['play_type']] ?? $play['play_type']) ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Action Buttons -->
    <div style="display:flex;gap:12px;justify-content:flex-end;margin-bottom:24px;">
        <a href="?page=game_plan&tab=<?= htmlspecialchars($activeTab) ?>" class="btn btn-secondary">Cancel</a>
        <button type="submit" name="action" value="save" class="btn btn-primary" data-action="save-plan">
            <i class="fa-solid fa-floppy-disk"></i> Save as Draft
        </button>
        <button type="submit" name="action" value="publish" class="btn btn-primary" style="background:var(--success);" data-action="publish-plan">
            <i class="fa-solid fa-paper-plane"></i> Publish
        </button>
    </div>
</form>

<!-- Draw Play Modal -->
<?php if ($editPlanId): ?>
<div class="modal-overlay" id="drawPlayModal">
    <div class="modal" style="max-width:900px;max-height:90vh;overflow-y:auto;">
        <div class="modal-header" style="display:flex;align-items:center;justify-content:space-between;">
            <h3><i class="fa-solid fa-pen"></i> Draw Play</h3>
            <button class="modal-close" onclick="document.getElementById('drawPlayModal').classList.remove('active')" style="background:none;border:none;color:var(--text-muted);font-size:20px;cursor:pointer;">&times;</button>
        </div>
        <div class="modal-body">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:16px;">
                <div>
                    <label class="form-label" style="font-size:12px;color:var(--text-muted);display:block;margin-bottom:4px;">Play Title</label>
                    <input type="text" id="playTitle" class="form-input" placeholder="e.g. Breakout Pattern 1" data-field="play_title">
                </div>
                <div>
                    <label class="form-label" style="font-size:12px;color:var(--text-muted);display:block;margin-bottom:4px;">Play Type</label>
                    <select id="playType" class="form-select" data-field="play_type">
                        <?php foreach ($playTypeLabels as $typeKey => $typeLabel): ?>
                            <option value="<?= $typeKey ?>"><?= $typeLabel ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <!-- Drawing Tools Toolbar -->
            <div style="display:flex;gap:6px;margin-bottom:12px;flex-wrap:wrap;padding:8px;background:var(--bg-secondary);border-radius:var(--radius-md);border:1px solid var(--border);">
                <button type="button" class="btn btn-sm btn-secondary" data-tool="pen" onclick="setDrawTool('pen')" title="Pen"><i class="fa-solid fa-pen"></i></button>
                <button type="button" class="btn btn-sm btn-secondary" data-tool="arrow" onclick="setDrawTool('arrow')" title="Arrow"><i class="fa-solid fa-arrow-right-long"></i></button>
                <button type="button" class="btn btn-sm btn-secondary" data-tool="circle" onclick="setDrawTool('circle')" title="Circle"><i class="fa-regular fa-circle"></i></button>
                <button type="button" class="btn btn-sm btn-secondary" data-tool="line" onclick="setDrawTool('line')" title="Line"><i class="fa-solid fa-minus"></i></button>
                <button type="button" class="btn btn-sm btn-secondary" data-tool="text" onclick="setDrawTool('text')" title="Text"><i class="fa-solid fa-font"></i></button>
                <button type="button" class="btn btn-sm btn-secondary" data-tool="player" onclick="setDrawTool('player')" title="Player Marker"><i class="fa-solid fa-circle-user"></i></button>
                <div style="width:1px;background:var(--border);margin:0 4px;"></div>
                <button type="button" class="btn btn-sm btn-secondary" data-tool="eraser" onclick="setDrawTool('eraser')" title="Eraser"><i class="fa-solid fa-eraser"></i></button>
                <button type="button" class="btn btn-sm btn-secondary" data-action="undo" onclick="undoDraw()" title="Undo"><i class="fa-solid fa-undo"></i></button>
                <button type="button" class="btn btn-sm btn-secondary" data-action="clear" onclick="clearCanvas()" title="Clear All"><i class="fa-solid fa-trash-can"></i></button>
                <div style="width:1px;background:var(--border);margin:0 4px;"></div>
                <input type="color" id="drawColor" value="#6B46C1" title="Draw Color" style="width:32px;height:32px;border:none;border-radius:4px;cursor:pointer;background:none;">
            </div>

            <!-- Rink Canvas -->
            <div class="rink-canvas" style="position:relative;width:100%;aspect-ratio:2/1;background:var(--bg-main);border:1px solid var(--border);border-radius:var(--radius-md);overflow:hidden;" data-canvas="rink">
                <!-- Hockey Rink SVG Background -->
                <svg viewBox="0 0 800 400" style="position:absolute;inset:0;width:100%;height:100%;pointer-events:none;" data-rink-svg>
                    <rect x="10" y="10" width="780" height="380" rx="80" fill="none" stroke="#2D2D3F" stroke-width="2"/>
                    <!-- Center line -->
                    <line x1="400" y1="10" x2="400" y2="390" stroke="#EF4444" stroke-width="2"/>
                    <!-- Center circle -->
                    <circle cx="400" cy="200" r="50" fill="none" stroke="#3B82F6" stroke-width="2"/>
                    <circle cx="400" cy="200" r="4" fill="#3B82F6"/>
                    <!-- Blue lines -->
                    <line x1="275" y1="10" x2="275" y2="390" stroke="#3B82F6" stroke-width="3"/>
                    <line x1="525" y1="10" x2="525" y2="390" stroke="#3B82F6" stroke-width="3"/>
                    <!-- Goal lines -->
                    <line x1="70" y1="90" x2="70" y2="310" stroke="#EF4444" stroke-width="2"/>
                    <line x1="730" y1="90" x2="730" y2="310" stroke="#EF4444" stroke-width="2"/>
                    <!-- Goal creases -->
                    <path d="M 70 170 A 30 30 0 0 0 70 230" fill="none" stroke="#3B82F6" stroke-width="2"/>
                    <path d="M 730 170 A 30 30 0 0 1 730 230" fill="none" stroke="#3B82F6" stroke-width="2"/>
                    <!-- Faceoff circles -->
                    <circle cx="175" cy="130" r="30" fill="none" stroke="#EF4444" stroke-width="1.5"/>
                    <circle cx="175" cy="270" r="30" fill="none" stroke="#EF4444" stroke-width="1.5"/>
                    <circle cx="625" cy="130" r="30" fill="none" stroke="#EF4444" stroke-width="1.5"/>
                    <circle cx="625" cy="270" r="30" fill="none" stroke="#EF4444" stroke-width="1.5"/>
                    <!-- Faceoff dots -->
                    <circle cx="175" cy="130" r="4" fill="#EF4444"/>
                    <circle cx="175" cy="270" r="4" fill="#EF4444"/>
                    <circle cx="625" cy="130" r="4" fill="#EF4444"/>
                    <circle cx="625" cy="270" r="4" fill="#EF4444"/>
                    <!-- Neutral zone dots -->
                    <circle cx="340" cy="130" r="3" fill="#EF4444"/>
                    <circle cx="340" cy="270" r="3" fill="#EF4444"/>
                    <circle cx="460" cy="130" r="3" fill="#EF4444"/>
                    <circle cx="460" cy="270" r="3" fill="#EF4444"/>
                </svg>
                <canvas id="drawCanvas" style="position:absolute;inset:0;width:100%;height:100%;cursor:crosshair;" data-draw-canvas></canvas>
            </div>

            <input type="hidden" id="canvasData" data-field="canvas_data">
        </div>
        <div class="modal-footer" style="display:flex;gap:8px;justify-content:flex-end;padding:16px 20px;border-top:1px solid var(--border);">
            <button class="btn btn-secondary" onclick="document.getElementById('drawPlayModal').classList.remove('active')">Cancel</button>
            <button class="btn btn-primary" data-action="save-play" onclick="savePlay()">
                <i class="fa-solid fa-floppy-disk"></i> Save Play
            </button>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
var currentTool = 'pen';
var drawHistory = [];

function setDrawTool(tool) {
    currentTool = tool;
    document.querySelectorAll('[data-tool]').forEach(function(btn) {
        btn.classList.remove('btn-primary');
        btn.classList.add('btn-secondary');
    });
    var active = document.querySelector('[data-tool="' + tool + '"]');
    if (active) {
        active.classList.remove('btn-secondary');
        active.classList.add('btn-primary');
    }
}

function undoDraw() {
    var canvas = document.getElementById('drawCanvas');
    if (!canvas) return;
    drawHistory.pop();
    var ctx = canvas.getContext('2d');
    ctx.clearRect(0, 0, canvas.width, canvas.height);
}

function clearCanvas() {
    var canvas = document.getElementById('drawCanvas');
    if (!canvas) return;
    var ctx = canvas.getContext('2d');
    ctx.clearRect(0, 0, canvas.width, canvas.height);
    drawHistory = [];
}

function savePlay() {
    var title = document.getElementById('playTitle');
    var playType = document.getElementById('playType');
    var canvas = document.getElementById('drawCanvas');
    if (!title || !title.value.trim()) {
        alert('Please enter a play title.');
        return;
    }
    var canvasData = canvas ? canvas.toDataURL() : '';
    var formData = new FormData();
    formData.append('csrf_token', '<?= htmlspecialchars($csrf_token) ?>');
    formData.append('game_plan_id', '<?= $editPlanId ?>');
    formData.append('title', title.value.trim());
    formData.append('play_type', playType ? playType.value : 'offensive');
    formData.append('canvas_data', canvasData);

    fetch('api/draw_play_save.php', { method: 'POST', body: formData })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                window.location.reload();
            } else {
                alert(data.error || 'Failed to save play.');
            }
        })
        .catch(function() { alert('Network error saving play.'); });
}

// Initialize canvas drawing
document.addEventListener('DOMContentLoaded', function() {
    var canvas = document.getElementById('drawCanvas');
    if (!canvas) return;
    var rect = canvas.parentElement.getBoundingClientRect();
    canvas.width = rect.width;
    canvas.height = rect.height;

    var ctx = canvas.getContext('2d');
    var drawing = false;
    var lastX = 0, lastY = 0;

    canvas.addEventListener('mousedown', function(e) {
        drawing = true;
        var r = canvas.getBoundingClientRect();
        lastX = e.clientX - r.left;
        lastY = e.clientY - r.top;
    });

    canvas.addEventListener('mousemove', function(e) {
        if (!drawing || currentTool === 'eraser') return;
        var r = canvas.getBoundingClientRect();
        var x = e.clientX - r.left;
        var y = e.clientY - r.top;
        var color = document.getElementById('drawColor');
        ctx.strokeStyle = color ? color.value : '#6B46C1';
        ctx.lineWidth = 2;
        ctx.lineCap = 'round';
        ctx.beginPath();
        ctx.moveTo(lastX, lastY);
        ctx.lineTo(x, y);
        ctx.stroke();
        lastX = x;
        lastY = y;
    });

    canvas.addEventListener('mouseup', function() { drawing = false; });
    canvas.addEventListener('mouseleave', function() { drawing = false; });
});
</script>

<?php else: ?>
<!-- ============================================================ -->
<!-- PLAN LIST -->
<!-- ============================================================ -->

<!-- Action Bar -->
<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;">
    <div style="font-size:14px;color:var(--text-secondary);">
        <strong><?= count($plans) ?></strong> plan<?= count($plans) !== 1 ? 's' : '' ?>
    </div>
    <a href="?page=game_plan&tab=<?= htmlspecialchars($activeTab) ?>&new=1" class="btn btn-primary btn-sm" data-action="new-plan">
        <i class="fa-solid fa-plus"></i> New <?= $planTypeLabels[$activeTab] ?? '' ?> Plan
    </a>
</div>

<?php if (empty($plans)): ?>
    <div class="empty-state-card">
        <div class="empty-icon"><i class="fa-solid fa-chess"></i></div>
        <p>No <?= strtolower($planTypeLabels[$activeTab] ?? '') ?> plans yet. Create your first plan to get started.</p>
        <a href="?page=game_plan&tab=<?= htmlspecialchars($activeTab) ?>&new=1" class="btn btn-primary" style="margin-top:12px;">
            <i class="fa-solid fa-plus"></i> Create Plan
        </a>
    </div>
<?php else: ?>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:16px;">
        <?php foreach ($plans as $plan): ?>
            <div class="card" data-plan-id="<?= (int)$plan['id'] ?>" data-plan-status="<?= htmlspecialchars($plan['status']) ?>">
                <div class="card-body">
                    <div style="display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:8px;">
                        <div style="font-weight:600;font-size:15px;color:var(--text-white);flex:1;" class="truncate">
                            <?= htmlspecialchars($plan['title']) ?>
                        </div>
                        <span class="badge <?= $statusBadge[$plan['status']] ?? 'badge-secondary' ?>" style="font-size:10px;margin-left:8px;">
                            <?= $statusLabels[$plan['status']] ?? ucfirst($plan['status']) ?>
                        </span>
                    </div>
                    <?php if (!empty($plan['opponent_name'])): ?>
                        <div style="font-size:13px;color:var(--text-secondary);margin-bottom:4px;">
                            <i class="fa-solid fa-hockey-puck" style="color:var(--primary-light);"></i>
                            vs <?= htmlspecialchars($plan['opponent_name']) ?>
                            <?php if (!empty($plan['game_date'])): ?>
                                — <?= date('M j, Y', strtotime($plan['game_date'])) ?>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($plan['team_name'])): ?>
                        <div style="font-size:12px;color:var(--text-muted);margin-bottom:8px;">
                            <i class="fa-solid fa-users"></i> <?= htmlspecialchars($plan['team_name']) ?>
                        </div>
                    <?php endif; ?>
                    <div style="font-size:11px;color:var(--text-muted);margin-bottom:12px;">
                        Updated <?= timeAgo($plan['updated_at']) ?>
                    </div>
                    <div style="display:flex;gap:6px;flex-wrap:wrap;">
                        <a href="?page=game_plan&tab=<?= htmlspecialchars($activeTab) ?>&edit=<?= (int)$plan['id'] ?>"
                           class="btn btn-sm btn-primary" data-action="edit-plan">
                            <i class="fa-solid fa-pen-to-square"></i> Edit
                        </a>
                        <?php if ($plan['status'] === 'draft'): ?>
                            <form method="POST" action="api/game_plan_status.php" style="display:inline;">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                                <input type="hidden" name="plan_id" value="<?= (int)$plan['id'] ?>">
                                <input type="hidden" name="status" value="published">
                                <button type="submit" class="btn btn-sm btn-outline" style="color:var(--success);border-color:var(--success);" data-action="publish">
                                    <i class="fa-solid fa-paper-plane"></i> Publish
                                </button>
                            </form>
                        <?php endif; ?>
                        <?php if ($plan['status'] !== 'archived'): ?>
                            <form method="POST" action="api/game_plan_status.php" style="display:inline;">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                                <input type="hidden" name="plan_id" value="<?= (int)$plan['id'] ?>">
                                <input type="hidden" name="status" value="archived">
                                <button type="submit" class="btn btn-sm btn-outline" data-action="archive">
                                    <i class="fa-solid fa-box-archive"></i> Archive
                                </button>
                            </form>
                        <?php endif; ?>
                        <form method="POST" action="api/game_plan_delete.php" style="display:inline;" onsubmit="return confirm('Delete this plan?');">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                            <input type="hidden" name="plan_id" value="<?= (int)$plan['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline" style="color:var(--error);border-color:var(--error);" data-action="delete">
                                <i class="fa-solid fa-trash"></i>
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
<?php endif; ?>
