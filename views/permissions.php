<?php
/**
 * Admin Video Permissions
 * Variables available: $pdo, $user_id, $user_role, $team_id, $user_name, $isCoach, $isAdmin, $csrf_token
 */

$selectedTeam = isset($_GET['team_id']) ? (int)$_GET['team_id'] : 0;
$filterRole = isset($_GET['role_filter']) ? sanitizeInput($_GET['role_filter']) : '';
$filterSearch = isset($_GET['search']) ? sanitizeInput($_GET['search']) : '';

$teams = [];
$teamUsers = [];
$permissionsMap = [];

if (defined('DB_CONNECTED') && DB_CONNECTED && $pdo) {
    // Fetch all teams (admin sees all)
    try {
        $stmt = dbQuery($pdo,
            "SELECT id, team_name FROM teams ORDER BY team_name",
            []
        );
        $teams = $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log('Permissions - teams error: ' . $e->getMessage());
    }

    // Default to first team if none selected
    if (!$selectedTeam && !empty($teams)) {
        $selectedTeam = (int)$teams[0]['id'];
    }

    if ($selectedTeam) {
        // Fetch users in the selected team
        try {
            $params = [':team_id' => $selectedTeam];
            $where = [];

            $sql = "SELECT DISTINCT u.id, u.first_name, u.last_name, u.email,
                           COALESCE(
                               (SELECT 'coach' FROM team_coach_assignments WHERE coach_id = u.id AND team_id = :team_id2 LIMIT 1),
                               u.role
                           ) AS effective_role
                    FROM users u
                    LEFT JOIN team_roster tr ON tr.user_id = u.id AND tr.team_id = :team_id
                    LEFT JOIN team_coach_assignments tca ON tca.coach_id = u.id AND tca.team_id = :team_id3
                    WHERE (tr.team_id = :team_id4 OR tca.team_id = :team_id5)";

            $params[':team_id2'] = $selectedTeam;
            $params[':team_id3'] = $selectedTeam;
            $params[':team_id4'] = $selectedTeam;
            $params[':team_id5'] = $selectedTeam;

            if ($filterRole) {
                $where[] = "u.role = :role";
                $params[':role'] = $filterRole;
            }
            if ($filterSearch) {
                $where[] = "(u.first_name LIKE :search OR u.last_name LIKE :search2 OR u.email LIKE :search3)";
                $params[':search'] = "%$filterSearch%";
                $params[':search2'] = "%$filterSearch%";
                $params[':search3'] = "%$filterSearch%";
            }

            if (!empty($where)) {
                $sql .= " AND " . implode(' AND ', $where);
            }

            $sql .= " ORDER BY u.last_name, u.first_name";

            $stmt = dbQuery($pdo, $sql, $params);
            $teamUsers = $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log('Permissions - users error: ' . $e->getMessage());
        }

        // Fetch existing permissions for this team
        try {
            $stmt = dbQuery($pdo,
                "SELECT user_id, can_upload, can_clip, can_tag, can_publish, can_delete
                 FROM vr_video_permissions
                 WHERE team_id = :team_id",
                [':team_id' => $selectedTeam]
            );
            $perms = $stmt->fetchAll();
            foreach ($perms as $p) {
                $permissionsMap[(int)$p['user_id']] = $p;
            }
        } catch (PDOException $e) {
            error_log('Permissions - permissions error: ' . $e->getMessage());
        }
    }
}

$permissionColumns = ['can_upload', 'can_clip', 'can_tag', 'can_publish', 'can_delete'];
$permissionLabels = [
    'can_upload'  => 'Upload',
    'can_clip'    => 'Clip',
    'can_tag'     => 'Tag',
    'can_publish' => 'Publish',
    'can_delete'  => 'Delete',
];
$permissionIcons = [
    'can_upload'  => 'fa-upload',
    'can_clip'    => 'fa-scissors',
    'can_tag'     => 'fa-tag',
    'can_publish' => 'fa-paper-plane',
    'can_delete'  => 'fa-trash',
];
?>

<!-- Page Header -->
<div class="page-header">
    <div class="page-header-content">
        <h1 class="page-title"><i class="fa-solid fa-user-shield"></i> Video Permissions</h1>
        <p class="page-description">Manage who can upload, edit, and publish video content</p>
    </div>
</div>

<!-- Filters -->
<div class="card" style="margin-bottom:16px;">
    <div class="card-body">
        <form method="GET" action="" style="display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;">
            <input type="hidden" name="page" value="permissions">

            <div class="form-group" style="min-width:180px;">
                <label class="form-label" style="font-size:12px;color:var(--text-muted);display:block;margin-bottom:4px;">Team</label>
                <select name="team_id" class="form-select" data-filter="team" onchange="this.form.submit()">
                    <?php foreach ($teams as $team): ?>
                        <option value="<?= (int)$team['id'] ?>" <?= $selectedTeam === (int)$team['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($team['team_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group" style="min-width:140px;">
                <label class="form-label" style="font-size:12px;color:var(--text-muted);display:block;margin-bottom:4px;">Role</label>
                <select name="role_filter" class="form-select" data-filter="role">
                    <option value="">All Roles</option>
                    <option value="coach" <?= $filterRole === 'coach' ? 'selected' : '' ?>>Coach</option>
                    <option value="team_coach" <?= $filterRole === 'team_coach' ? 'selected' : '' ?>>Team Coach</option>
                    <option value="athlete" <?= $filterRole === 'athlete' ? 'selected' : '' ?>>Athlete</option>
                    <option value="admin" <?= $filterRole === 'admin' ? 'selected' : '' ?>>Admin</option>
                </select>
            </div>

            <div class="form-group" style="flex:1;min-width:180px;">
                <label class="form-label" style="font-size:12px;color:var(--text-muted);display:block;margin-bottom:4px;">Search</label>
                <input type="text" name="search" class="form-input" placeholder="Search by name or email..."
                       value="<?= htmlspecialchars($filterSearch) ?>" data-filter="search">
            </div>

            <div class="form-group">
                <button type="submit" class="btn btn-primary btn-sm"><i class="fa-solid fa-search"></i> Filter</button>
                <a href="?page=permissions&team_id=<?= $selectedTeam ?>" class="btn btn-secondary btn-sm"><i class="fa-solid fa-times"></i> Clear</a>
            </div>
        </form>
    </div>
</div>

<!-- Permissions Table -->
<div class="card">
    <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;">
        <h3><i class="fa-solid fa-users-cog"></i> Team Members (<?= count($teamUsers) ?>)</h3>
    </div>
    <div class="card-body" style="padding:0;">
        <?php if (empty($teamUsers)): ?>
            <div class="empty-state-card">
                <div class="empty-icon"><i class="fa-solid fa-users"></i></div>
                <p>No team members found. <?= empty($teams) ? 'No teams exist yet.' : 'Select a team above or adjust your filters.' ?></p>
            </div>
        <?php else: ?>
            <div class="data-table" style="overflow-x:auto;">
                <table style="width:100%;border-collapse:collapse;">
                    <thead>
                        <tr style="border-bottom:1px solid var(--border);">
                            <th style="padding:12px;text-align:left;font-size:12px;color:var(--text-muted);font-weight:600;white-space:nowrap;">Name</th>
                            <th style="padding:12px;text-align:left;font-size:12px;color:var(--text-muted);font-weight:600;white-space:nowrap;">Role</th>
                            <?php foreach ($permissionLabels as $permKey => $permLabel): ?>
                                <th style="padding:12px;text-align:center;font-size:12px;color:var(--text-muted);font-weight:600;white-space:nowrap;">
                                    <i class="fa-solid <?= $permissionIcons[$permKey] ?>" title="<?= $permLabel ?>"></i><br>
                                    <?= $permLabel ?>
                                </th>
                            <?php endforeach; ?>
                            <th style="padding:12px;text-align:center;font-size:12px;color:var(--text-muted);font-weight:600;white-space:nowrap;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($teamUsers as $member):
                            $uid = (int)$member['id'];
                            $userPerms = $permissionsMap[$uid] ?? [];
                        ?>
                            <tr style="border-bottom:1px solid var(--border);transition:background 0.15s;" data-user-id="<?= $uid ?>">
                                <td style="padding:12px;">
                                    <div style="display:flex;align-items:center;gap:10px;">
                                        <div style="width:32px;height:32px;border-radius:50%;background:var(--primary);display:flex;align-items:center;justify-content:center;font-weight:600;font-size:13px;color:#fff;flex-shrink:0;">
                                            <?= strtoupper(substr($member['first_name'] ?? '', 0, 1)) ?>
                                        </div>
                                        <div>
                                            <div style="font-weight:600;font-size:13px;color:var(--text-white);">
                                                <?= htmlspecialchars(($member['first_name'] ?? '') . ' ' . ($member['last_name'] ?? '')) ?>
                                            </div>
                                            <div style="font-size:11px;color:var(--text-muted);">
                                                <?= htmlspecialchars($member['email'] ?? '') ?>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td style="padding:12px;">
                                    <span class="badge badge-info" style="font-size:10px;">
                                        <?= htmlspecialchars(str_replace('_', ' ', ucfirst($member['effective_role'] ?? $member['role'] ?? 'unknown'))) ?>
                                    </span>
                                </td>
                                <?php foreach ($permissionColumns as $permCol): ?>
                                    <td style="padding:12px;text-align:center;">
                                        <label style="cursor:pointer;display:inline-flex;align-items:center;justify-content:center;">
                                            <input type="checkbox"
                                                   data-user="<?= $uid ?>"
                                                   data-team="<?= $selectedTeam ?>"
                                                   data-permission="<?= $permCol ?>"
                                                   <?= !empty($userPerms[$permCol]) ? 'checked' : '' ?>
                                                   onchange="togglePermission(this)"
                                                   style="width:18px;height:18px;cursor:pointer;accent-color:var(--primary);">
                                        </label>
                                    </td>
                                <?php endforeach; ?>
                                <td style="padding:12px;text-align:center;">
                                    <div style="display:flex;gap:4px;justify-content:center;">
                                        <button class="btn btn-sm btn-outline" style="font-size:10px;padding:4px 8px;"
                                                data-action="grant-all" onclick="setAllPermissions(<?= $uid ?>, true)" title="Grant All">
                                            <i class="fa-solid fa-check-double"></i>
                                        </button>
                                        <button class="btn btn-sm btn-outline" style="font-size:10px;padding:4px 8px;color:var(--error);border-color:var(--error);"
                                                data-action="revoke-all" onclick="setAllPermissions(<?= $uid ?>, false)" title="Revoke All">
                                            <i class="fa-solid fa-times"></i>
                                        </button>
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
function togglePermission(checkbox) {
    var userId = checkbox.getAttribute('data-user');
    var teamId = checkbox.getAttribute('data-team');
    var permission = checkbox.getAttribute('data-permission');
    var value = checkbox.checked ? 1 : 0;

    var formData = new FormData();
    formData.append('csrf_token', '<?= htmlspecialchars($csrf_token) ?>');
    formData.append('user_id', userId);
    formData.append('team_id', teamId);
    formData.append('permission', permission);
    formData.append('value', value);

    fetch('api/permission_toggle.php', { method: 'POST', body: formData })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (!data.success) {
                checkbox.checked = !checkbox.checked;
                alert(data.error || 'Failed to update permission.');
            }
        })
        .catch(function() {
            checkbox.checked = !checkbox.checked;
            alert('Network error updating permission.');
        });
}

function setAllPermissions(userId, grant) {
    var row = document.querySelector('tr[data-user-id="' + userId + '"]');
    if (!row) return;
    var checkboxes = row.querySelectorAll('input[type="checkbox"][data-permission]');
    checkboxes.forEach(function(cb) {
        if (cb.checked !== grant) {
            cb.checked = grant;
            togglePermission(cb);
        }
    });
}
</script>
