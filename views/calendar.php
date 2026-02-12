<?php
/**
 * Calendar & Schedule View (Coach Only)
 * Variables available: $pdo, $user_id, $user_role, $team_id, $user_name, $isCoach, $isAdmin, $csrf_token
 */

$calView = isset($_GET['view']) ? sanitizeInput($_GET['view']) : 'calendar';
if (!in_array($calView, ['calendar', 'list'], true)) {
    $calView = 'calendar';
}

$filterTeam = isset($_GET['team_filter']) ? (int)$_GET['team_filter'] : 0;
$filterType = isset($_GET['type']) ? sanitizeInput($_GET['type']) : '';
$filterTime = isset($_GET['time_filter']) ? sanitizeInput($_GET['time_filter']) : '';

// Calendar month/year navigation
$calMonth = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('n');
$calYear  = isset($_GET['year'])  ? (int)$_GET['year']  : (int)date('Y');
if ($calMonth < 1)  { $calMonth = 12; $calYear--; }
if ($calMonth > 12) { $calMonth = 1;  $calYear++; }

$teams = [];
$games = [];
$calendarImports = [];

if (defined('DB_CONNECTED') && DB_CONNECTED && $pdo) {
    // Fetch teams the coach is assigned to
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
        error_log('Calendar - teams error: ' . $e->getMessage());
    }

    // Fetch games for calendar
    try {
        $params = [];
        $where = [];

        if ($filterTeam) {
            $where[] = "gs.team_id = :team_id";
            $params[':team_id'] = $filterTeam;
        } else {
            // Only games for coach's teams
            $where[] = "gs.team_id IN (SELECT team_id FROM team_coach_assignments WHERE coach_id = :uid)";
            $params[':uid'] = $user_id;
        }

        if ($calView === 'calendar') {
            $startDate = sprintf('%04d-%02d-01', $calYear, $calMonth);
            $endDate = date('Y-m-t', strtotime($startDate));
            $where[] = "gs.game_date >= :start_date AND gs.game_date <= :end_date";
            $params[':start_date'] = $startDate;
            $params[':end_date'] = $endDate . ' 23:59:59';
        }

        if ($filterTime === 'upcoming') {
            $where[] = "gs.game_date >= CURDATE()";
        } elseif ($filterTime === 'past') {
            $where[] = "gs.game_date < CURDATE()";
        }

        $sql = "SELECT gs.id, gs.game_date, gs.location, gs.home_score, gs.away_score,
                       gs.team_id, gs.opponent_team_id, gs.game_type,
                       t.team_name, t2.team_name AS opponent_name,
                       (SELECT COUNT(*) FROM vr_video_sources vs WHERE vs.game_schedule_id = gs.id) AS video_count
                FROM game_schedules gs
                LEFT JOIN teams t ON t.id = gs.team_id
                LEFT JOIN teams t2 ON t2.id = gs.opponent_team_id";

        if (!empty($where)) {
            $sql .= " WHERE " . implode(' AND ', $where);
        }

        $sql .= " ORDER BY gs.game_date " . ($calView === 'list' ? 'DESC' : 'ASC') . " LIMIT 200";

        $stmt = dbQuery($pdo, $sql, $params);
        $games = $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log('Calendar - games error: ' . $e->getMessage());
    }

    // Fetch calendar imports
    try {
        $stmt = dbQuery($pdo,
            "SELECT ci.id, ci.source_name, ci.source_type, ci.import_url, ci.last_synced_at,
                    ci.auto_sync, ci.sync_interval_hours, ci.created_at,
                    t.team_name
             FROM vr_calendar_imports ci
             LEFT JOIN teams t ON t.id = ci.team_id
             WHERE ci.imported_by = :uid
             ORDER BY ci.created_at DESC",
            [':uid' => $user_id]
        );
        $calendarImports = $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log('Calendar - imports error: ' . $e->getMessage());
    }
}

// Index games by day for calendar view
$gamesByDay = [];
foreach ($games as $game) {
    $day = (int)date('j', strtotime($game['game_date']));
    $gamesByDay[$day][] = $game;
}

// Helpers
if (!function_exists('getGameStatus')) {
    function getGameStatus($game) {
        $gameDate = strtotime($game['game_date']);
        if ($game['home_score'] !== null && $game['away_score'] !== null) {
            return ((int)$game['home_score'] > (int)$game['away_score']) ? 'won' : (((int)$game['home_score'] < (int)$game['away_score']) ? 'lost' : 'tied');
        }
        if ($gameDate >= strtotime('today')) {
            return 'upcoming';
        }
        return 'upcoming';
    }
}

if (!function_exists('gameStatusColor')) {
    function gameStatusColor($status) {
        $colors = [
            'won'       => 'var(--success)',
            'lost'      => 'var(--error)',
            'upcoming'  => 'var(--info)',
            'tied'      => 'var(--warning)',
            'cancelled' => 'var(--text-muted)',
        ];
        return $colors[$status] ?? 'var(--text-muted)';
    }
}

$prevMonth = $calMonth - 1;
$prevYear  = $calYear;
if ($prevMonth < 1) { $prevMonth = 12; $prevYear--; }
$nextMonth = $calMonth + 1;
$nextYear  = $calYear;
if ($nextMonth > 12) { $nextMonth = 1; $nextYear++; }

$monthName = date('F', mktime(0, 0, 0, $calMonth, 1, $calYear));
$daysInMonth = (int)date('t', mktime(0, 0, 0, $calMonth, 1, $calYear));
$firstDayOfWeek = (int)date('w', mktime(0, 0, 0, $calMonth, 1, $calYear));
$today = (int)date('j');
$todayMonth = (int)date('n');
$todayYear  = (int)date('Y');

$sourceTypeLabels = [
    'teamlinkt' => 'TeamLinkt',
    'ical'      => 'iCal',
    'csv'       => 'CSV',
    'manual'    => 'Manual',
];
?>

<!-- Page Header -->
<div class="page-header">
    <div class="page-header-content">
        <h1 class="page-title"><i class="fa-solid fa-calendar"></i> Schedule</h1>
        <p class="page-description">Manage your team's game schedule and calendar imports</p>
    </div>
</div>

<!-- Action Bar -->
<div class="card" style="margin-bottom:16px;">
    <div class="card-body" style="display:flex;flex-wrap:wrap;gap:12px;align-items:center;justify-content:space-between;">
        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
            <!-- View Toggle -->
            <a href="?page=calendar&view=calendar&team_filter=<?= $filterTeam ?>&month=<?= $calMonth ?>&year=<?= $calYear ?>"
               class="btn btn-sm <?= $calView === 'calendar' ? 'btn-primary' : 'btn-secondary' ?>" data-view="calendar">
                <i class="fa-solid fa-calendar"></i> Calendar
            </a>
            <a href="?page=calendar&view=list&team_filter=<?= $filterTeam ?>"
               class="btn btn-sm <?= $calView === 'list' ? 'btn-primary' : 'btn-secondary' ?>" data-view="list">
                <i class="fa-solid fa-list"></i> List
            </a>

            <!-- Team Filter -->
            <form method="GET" action="" style="display:inline-flex;align-items:center;gap:8px;">
                <input type="hidden" name="page" value="calendar">
                <input type="hidden" name="view" value="<?= htmlspecialchars($calView) ?>">
                <input type="hidden" name="month" value="<?= $calMonth ?>">
                <input type="hidden" name="year" value="<?= $calYear ?>">
                <select name="team_filter" class="form-select" data-filter="team" onchange="this.form.submit()" style="min-width:150px;">
                    <option value="0">All Teams</option>
                    <?php foreach ($teams as $team): ?>
                        <option value="<?= (int)$team['id'] ?>" <?= $filterTeam === (int)$team['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($team['team_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>

            <?php if ($calView === 'list'): ?>
                <form method="GET" action="" style="display:inline-flex;align-items:center;gap:8px;">
                    <input type="hidden" name="page" value="calendar">
                    <input type="hidden" name="view" value="list">
                    <input type="hidden" name="team_filter" value="<?= $filterTeam ?>">
                    <select name="time_filter" class="form-select" data-filter="time" onchange="this.form.submit()" style="min-width:130px;">
                        <option value="">All Games</option>
                        <option value="upcoming" <?= $filterTime === 'upcoming' ? 'selected' : '' ?>>Upcoming</option>
                        <option value="past" <?= $filterTime === 'past' ? 'selected' : '' ?>>Past</option>
                    </select>
                </form>
            <?php endif; ?>
        </div>

        <button class="btn btn-primary btn-sm" data-action="open-import-modal" onclick="document.getElementById('importCalendarModal').classList.add('active')">
            <i class="fa-solid fa-file-import"></i> Import Calendar
        </button>
    </div>
</div>

<?php if ($calView === 'calendar'): ?>
<!-- Calendar View -->
<div class="card">
    <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;">
        <a href="?page=calendar&view=calendar&team_filter=<?= $filterTeam ?>&month=<?= $prevMonth ?>&year=<?= $prevYear ?>"
           class="btn btn-sm btn-secondary" data-action="prev-month">
            <i class="fa-solid fa-chevron-left"></i>
        </a>
        <div style="display:flex;align-items:center;gap:12px;">
            <h3 style="margin:0;"><?= $monthName ?> <?= $calYear ?></h3>
            <?php if ($calMonth !== $todayMonth || $calYear !== $todayYear): ?>
                <a href="?page=calendar&view=calendar&team_filter=<?= $filterTeam ?>&month=<?= $todayMonth ?>&year=<?= $todayYear ?>"
                   class="btn btn-sm btn-outline" data-action="today">Today</a>
            <?php endif; ?>
        </div>
        <a href="?page=calendar&view=calendar&team_filter=<?= $filterTeam ?>&month=<?= $nextMonth ?>&year=<?= $nextYear ?>"
           class="btn btn-sm btn-secondary" data-action="next-month">
            <i class="fa-solid fa-chevron-right"></i>
        </a>
    </div>
    <div class="card-body" style="padding:0;">
        <!-- Day Headers -->
        <div style="display:grid;grid-template-columns:repeat(7,1fr);border-bottom:1px solid var(--border);">
            <?php foreach (['Sun','Mon','Tue','Wed','Thu','Fri','Sat'] as $dayName): ?>
                <div style="padding:10px;text-align:center;font-size:12px;font-weight:600;color:var(--text-muted);text-transform:uppercase;">
                    <?= $dayName ?>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Calendar Grid -->
        <div style="display:grid;grid-template-columns:repeat(7,1fr);" data-calendar-grid>
            <?php
            // Empty cells before first day
            for ($i = 0; $i < $firstDayOfWeek; $i++): ?>
                <div style="min-height:100px;border-right:1px solid var(--border);border-bottom:1px solid var(--border);padding:8px;background:rgba(0,0,0,0.1);"></div>
            <?php endfor; ?>

            <?php for ($day = 1; $day <= $daysInMonth; $day++):
                $isToday = ($day === $today && $calMonth === $todayMonth && $calYear === $todayYear);
                $dayGames = $gamesByDay[$day] ?? [];
            ?>
                <div style="min-height:100px;border-right:1px solid var(--border);border-bottom:1px solid var(--border);padding:8px;<?= $isToday ? 'background:rgba(107,70,193,0.08);' : '' ?>"
                     data-calendar-day="<?= $day ?>">
                    <div style="font-size:13px;font-weight:<?= $isToday ? '700' : '500' ?>;color:<?= $isToday ? 'var(--primary-light)' : 'var(--text-secondary)' ?>;margin-bottom:4px;">
                        <?= $day ?>
                    </div>
                    <?php foreach ($dayGames as $game):
                        $status = getGameStatus($game);
                        $statusColor = gameStatusColor($status);
                    ?>
                        <div class="calendar-game-event"
                             data-game-id="<?= (int)$game['id'] ?>"
                             data-game-status="<?= $status ?>"
                             style="font-size:11px;padding:3px 6px;margin-bottom:3px;border-radius:4px;background:<?= $statusColor ?>22;border-left:3px solid <?= $statusColor ?>;color:var(--text-white);cursor:pointer;overflow:hidden;white-space:nowrap;text-overflow:ellipsis;"
                             onclick="toggleGameDetail(this)">
                            <span style="font-weight:600;">
                                <?php if (!empty($game['opponent_name'])): ?>
                                    vs <?= htmlspecialchars($game['opponent_name']) ?>
                                <?php else: ?>
                                    Game
                                <?php endif; ?>
                            </span>
                            <?php if ($game['home_score'] !== null && $game['away_score'] !== null): ?>
                                <span style="margin-left:4px;"><?= (int)$game['home_score'] ?>-<?= (int)$game['away_score'] ?></span>
                            <?php endif; ?>
                            <!-- Expanded details (hidden by default) -->
                            <div class="game-detail-expanded" style="display:none;margin-top:4px;white-space:normal;font-size:10px;color:var(--text-secondary);">
                                <?php if (!empty($game['location'])): ?>
                                    <div><i class="fa-solid fa-location-dot"></i> <?= htmlspecialchars($game['location']) ?></div>
                                <?php endif; ?>
                                <div><i class="fa-solid fa-clock"></i> <?= date('g:i A', strtotime($game['game_date'])) ?></div>
                                <?php if ((int)$game['video_count'] > 0): ?>
                                    <div><i class="fa-solid fa-video"></i> <?= (int)$game['video_count'] ?> video<?= (int)$game['video_count'] !== 1 ? 's' : '' ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endfor; ?>

            <?php
            // Empty cells after last day
            $totalCells = $firstDayOfWeek + $daysInMonth;
            $remaining = (7 - ($totalCells % 7)) % 7;
            for ($i = 0; $i < $remaining; $i++): ?>
                <div style="min-height:100px;border-right:1px solid var(--border);border-bottom:1px solid var(--border);padding:8px;background:rgba(0,0,0,0.1);"></div>
            <?php endfor; ?>
        </div>
    </div>
</div>

<!-- Legend -->
<div style="display:flex;gap:16px;margin-top:12px;flex-wrap:wrap;">
    <div style="display:flex;align-items:center;gap:6px;font-size:12px;color:var(--text-secondary);">
        <span style="width:12px;height:12px;border-radius:3px;background:var(--success);display:inline-block;"></span> Won
    </div>
    <div style="display:flex;align-items:center;gap:6px;font-size:12px;color:var(--text-secondary);">
        <span style="width:12px;height:12px;border-radius:3px;background:var(--error);display:inline-block;"></span> Lost
    </div>
    <div style="display:flex;align-items:center;gap:6px;font-size:12px;color:var(--text-secondary);">
        <span style="width:12px;height:12px;border-radius:3px;background:var(--info);display:inline-block;"></span> Upcoming
    </div>
    <div style="display:flex;align-items:center;gap:6px;font-size:12px;color:var(--text-secondary);">
        <span style="width:12px;height:12px;border-radius:3px;background:var(--text-muted);display:inline-block;"></span> Cancelled
    </div>
</div>

<?php else: ?>
<!-- List View -->
<?php if (empty($games)): ?>
    <div class="empty-state-card">
        <div class="empty-icon"><i class="fa-solid fa-calendar"></i></div>
        <p>No games found. Import a calendar or add games to get started.</p>
    </div>
<?php else: ?>
    <div class="card">
        <div class="card-body" style="padding:0;">
            <?php foreach ($games as $game):
                $status = getGameStatus($game);
                $statusColor = gameStatusColor($status);
                $gameDate = strtotime($game['game_date']);
            ?>
                <div class="session-list-card" data-game-id="<?= (int)$game['id'] ?>" data-game-status="<?= $status ?>">
                    <div class="session-date-column" style="background:<?= $statusColor ?>22;border-left:3px solid <?= $statusColor ?>;">
                        <div style="font-size:11px;color:var(--text-muted);text-transform:uppercase;"><?= date('D', $gameDate) ?></div>
                        <div style="font-size:22px;font-weight:700;color:var(--text-white);"><?= date('j', $gameDate) ?></div>
                        <div style="font-size:11px;color:var(--text-muted);"><?= date('M Y', $gameDate) ?></div>
                    </div>
                    <div class="session-details-column" style="flex:1;">
                        <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;">
                            <div>
                                <div style="font-weight:600;color:var(--text-white);font-size:15px;">
                                    <?php if (!empty($game['team_name'])): ?>
                                        <?= htmlspecialchars($game['team_name']) ?>
                                    <?php endif; ?>
                                    <?php if (!empty($game['opponent_name'])): ?>
                                        vs <?= htmlspecialchars($game['opponent_name']) ?>
                                    <?php endif; ?>
                                </div>
                                <div style="font-size:13px;color:var(--text-secondary);margin-top:4px;display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                                    <span><i class="fa-solid fa-clock"></i> <?= date('g:i A', $gameDate) ?></span>
                                    <?php if (!empty($game['location'])): ?>
                                        <span><i class="fa-solid fa-location-dot"></i> <?= htmlspecialchars($game['location']) ?></span>
                                    <?php endif; ?>
                                    <?php if (!empty($game['game_type'])): ?>
                                        <span class="badge badge-info" style="font-size:10px;"><?= htmlspecialchars($game['game_type']) ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div style="display:flex;align-items:center;gap:12px;">
                                <?php if ($game['home_score'] !== null && $game['away_score'] !== null): ?>
                                    <div style="font-size:18px;font-weight:700;color:<?= $statusColor ?>;">
                                        <?= (int)$game['home_score'] ?> - <?= (int)$game['away_score'] ?>
                                    </div>
                                <?php else: ?>
                                    <span class="badge" style="background:<?= $statusColor ?>33;color:<?= $statusColor ?>;font-size:11px;">
                                        <?= ucfirst($status) ?>
                                    </span>
                                <?php endif; ?>
                                <?php if ((int)$game['video_count'] > 0): ?>
                                    <span class="badge badge-info" style="font-size:11px;">
                                        <i class="fa-solid fa-video"></i> <?= (int)$game['video_count'] ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>
<?php endif; ?>

<!-- Existing Calendar Imports -->
<?php if (!empty($calendarImports)): ?>
<div class="card" style="margin-top:24px;">
    <div class="card-header">
        <h3><i class="fa-solid fa-sync-alt"></i> Calendar Imports</h3>
    </div>
    <div class="card-body" style="padding:0;">
        <?php foreach ($calendarImports as $import): ?>
            <div class="session-list-card" data-import-id="<?= (int)$import['id'] ?>">
                <div class="session-date-column" style="background:rgba(107,70,193,0.1);">
                    <div style="font-size:20px;color:var(--primary-light);">
                        <i class="fa-solid fa-<?= $import['source_type'] === 'ical' ? 'link' : ($import['source_type'] === 'csv' ? 'file-csv' : 'hockey-puck') ?>"></i>
                    </div>
                </div>
                <div class="session-details-column" style="flex:1;">
                    <div style="font-weight:600;color:var(--text-white);"><?= htmlspecialchars($import['source_name']) ?></div>
                    <div style="font-size:12px;color:var(--text-muted);margin-top:4px;">
                        <span class="badge badge-info" style="font-size:10px;"><?= htmlspecialchars($sourceTypeLabels[$import['source_type']] ?? $import['source_type']) ?></span>
                        <?php if (!empty($import['team_name'])): ?>
                            &middot; <?= htmlspecialchars($import['team_name']) ?>
                        <?php endif; ?>
                        <?php if ($import['auto_sync']): ?>
                            &middot; <span style="color:var(--success);"><i class="fa-solid fa-sync"></i> Auto-sync</span>
                        <?php endif; ?>
                        <?php if (!empty($import['last_synced_at'])): ?>
                            &middot; Last synced: <?= date('M j, Y g:i A', strtotime($import['last_synced_at'])) ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- Import Calendar Modal -->
<div class="modal-overlay" id="importCalendarModal">
    <div class="modal" style="max-width:520px;">
        <div class="modal-header" style="display:flex;align-items:center;justify-content:space-between;">
            <h3><i class="fa-solid fa-file-import"></i> Import Calendar</h3>
            <button class="modal-close" onclick="document.getElementById('importCalendarModal').classList.remove('active')" style="background:none;border:none;color:var(--text-muted);font-size:20px;cursor:pointer;">&times;</button>
        </div>
        <div class="modal-body">
            <form id="importCalendarForm" method="POST" action="api/calendar_import.php" enctype="multipart/form-data" data-form="import-calendar">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">

                <div style="margin-bottom:16px;">
                    <label class="form-label" style="font-size:12px;color:var(--text-muted);display:block;margin-bottom:4px;">Source Type</label>
                    <select name="source_type" class="form-select" data-field="source_type" id="importSourceType" onchange="toggleImportFields()" required>
                        <option value="">Select source...</option>
                        <option value="teamlinkt">TeamLinkt</option>
                        <option value="ical">iCal URL</option>
                        <option value="csv">CSV Upload</option>
                    </select>
                </div>

                <div style="margin-bottom:16px;">
                    <label class="form-label" style="font-size:12px;color:var(--text-muted);display:block;margin-bottom:4px;">Source Name</label>
                    <input type="text" name="source_name" class="form-input" placeholder="e.g. League Schedule 2024-25" data-field="source_name" required>
                </div>

                <!-- TeamLinkt fields -->
                <div id="importFieldTeamLinkt" style="display:none;margin-bottom:16px;">
                    <label class="form-label" style="font-size:12px;color:var(--text-muted);display:block;margin-bottom:4px;">TeamLinkt API Key / URL</label>
                    <input type="text" name="teamlinkt_url" class="form-input" placeholder="Enter TeamLinkt API key or schedule URL" data-field="teamlinkt_url">
                </div>

                <!-- iCal fields -->
                <div id="importFieldIcal" style="display:none;">
                    <div style="margin-bottom:16px;">
                        <label class="form-label" style="font-size:12px;color:var(--text-muted);display:block;margin-bottom:4px;">iCal Feed URL</label>
                        <input type="url" name="ical_url" class="form-input" placeholder="https://example.com/calendar.ics" data-field="ical_url">
                    </div>
                    <div style="margin-bottom:16px;">
                        <label style="display:flex;align-items:center;gap:8px;font-size:13px;color:var(--text-secondary);cursor:pointer;">
                            <input type="checkbox" name="auto_sync" value="1" data-field="auto_sync"> Auto-sync daily
                        </label>
                    </div>
                </div>

                <!-- CSV fields -->
                <div id="importFieldCsv" style="display:none;margin-bottom:16px;">
                    <label class="form-label" style="font-size:12px;color:var(--text-muted);display:block;margin-bottom:4px;">CSV File</label>
                    <input type="file" name="csv_file" class="form-input" accept=".csv" data-field="csv_file">
                    <div style="font-size:11px;color:var(--text-muted);margin-top:4px;">
                        Columns: Date, Time, Opponent, Location, Game Type
                    </div>
                </div>

                <div style="margin-bottom:16px;">
                    <label class="form-label" style="font-size:12px;color:var(--text-muted);display:block;margin-bottom:4px;">Assign to Team</label>
                    <select name="team_id" class="form-select" data-field="team_id" required>
                        <option value="">Select team...</option>
                        <?php foreach ($teams as $team): ?>
                            <option value="<?= (int)$team['id'] ?>"><?= htmlspecialchars($team['team_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </form>
        </div>
        <div class="modal-footer" style="display:flex;gap:8px;justify-content:flex-end;padding:16px 20px;border-top:1px solid var(--border);">
            <button class="btn btn-secondary" onclick="document.getElementById('importCalendarModal').classList.remove('active')">Cancel</button>
            <button class="btn btn-primary" data-action="submit-import" onclick="document.getElementById('importCalendarForm').submit()">
                <i class="fa-solid fa-file-import"></i> Import
            </button>
        </div>
    </div>
</div>

<script>
function toggleImportFields() {
    var type = document.getElementById('importSourceType').value;
    document.getElementById('importFieldTeamLinkt').style.display = (type === 'teamlinkt') ? 'block' : 'none';
    document.getElementById('importFieldIcal').style.display = (type === 'ical') ? 'block' : 'none';
    document.getElementById('importFieldCsv').style.display = (type === 'csv') ? 'block' : 'none';
}

function toggleGameDetail(el) {
    var detail = el.querySelector('.game-detail-expanded');
    if (detail) {
        detail.style.display = detail.style.display === 'none' ? 'block' : 'none';
        el.style.whiteSpace = detail.style.display === 'none' ? 'nowrap' : 'normal';
    }
}
</script>
