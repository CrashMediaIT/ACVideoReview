<?php
/**
 * Pair Device View (Coach Only)
 * Allows pairing a viewer device (TV/projector) with a controller (tablet/phone)
 * for live telestration sessions
 * Variables available: $pdo, $user_id, $user_role, $team_id, $user_name, $isCoach, $isAdmin, $csrf_token
 */

$existingSessions = [];

if (defined('DB_CONNECTED') && DB_CONNECTED && $pdo) {
    try {
        $stmt = dbQuery($pdo,
            "SELECT id, session_code, status, paired_at, last_heartbeat, expires_at, created_at
             FROM vr_device_sessions
             WHERE user_id = :uid AND status IN ('waiting', 'paired', 'active')
               AND expires_at > NOW()
             ORDER BY created_at DESC
             LIMIT 5",
            [':uid' => $user_id]
        );
        $existingSessions = $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log('Pair device - sessions error: ' . $e->getMessage());
    }
}

$statusLabels = [
    'waiting' => 'Waiting for Controller',
    'paired'  => 'Paired',
    'active'  => 'Active Session',
    'expired' => 'Expired',
];
$statusColors = [
    'waiting' => 'var(--warning)',
    'paired'  => 'var(--info)',
    'active'  => 'var(--success)',
    'expired' => 'var(--text-muted)',
];
?>

<!-- Page Header -->
<div class="page-header">
    <div class="page-header-content">
        <h1 class="page-title"><i class="fa-solid fa-link"></i> Pair Device</h1>
        <p class="page-description">Connect a viewer display with a controller for live telestration</p>
    </div>
</div>

<!-- How It Works -->
<div class="card" style="margin-bottom:24px;">
    <div class="card-header">
        <h3><i class="fa-solid fa-circle-info"></i> How It Works</h3>
    </div>
    <div class="card-body">
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:24px;text-align:center;">
            <div>
                <div style="font-size:36px;color:var(--primary-light);margin-bottom:12px;">
                    <i class="fa-solid fa-tv"></i>
                </div>
                <div style="font-weight:600;color:var(--text-white);margin-bottom:4px;">1. Start Viewer</div>
                <div style="font-size:13px;color:var(--text-secondary);">Open this page on the display device (TV, projector, or large screen)</div>
            </div>
            <div>
                <div style="font-size:36px;color:var(--warning);margin-bottom:12px;">
                    <i class="fa-solid fa-mobile-alt"></i>
                </div>
                <div style="font-weight:600;color:var(--text-white);margin-bottom:4px;">2. Enter Code</div>
                <div style="font-size:13px;color:var(--text-secondary);">Enter the pairing code on your controller device (tablet or phone)</div>
            </div>
            <div>
                <div style="font-size:36px;color:var(--success);margin-bottom:12px;">
                    <i class="fa-solid fa-pen"></i>
                </div>
                <div style="font-weight:600;color:var(--text-white);margin-bottom:4px;">3. Draw Live</div>
                <div style="font-size:13px;color:var(--text-secondary);">Control playback and draw annotations from your controller</div>
            </div>
        </div>
    </div>
</div>

<!-- Start New Session -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;margin-bottom:24px;" data-csrf-token="<?= htmlspecialchars($csrf_token) ?>" id="pairDeviceContainer">
    <!-- Viewer Mode -->
    <div class="card">
        <div class="card-header">
            <h3><i class="fa-solid fa-tv"></i> Start as Viewer</h3>
        </div>
        <div class="card-body" style="text-align:center;padding:32px 20px;">
            <p style="font-size:14px;color:var(--text-secondary);margin-bottom:20px;">
                This device will display the video and telestration overlay
            </p>
            <div id="viewerPairingCode" style="display:none;margin-bottom:20px;">
                <div style="font-size:14px;color:var(--text-muted);margin-bottom:8px;">Pairing Code</div>
                <div id="pairingCodeDisplay" style="font-size:48px;font-weight:700;color:var(--primary-light);letter-spacing:8px;font-family:monospace;">
                    ------
                </div>
                <div id="viewerStatusText" style="font-size:13px;color:var(--warning);margin-top:12px;">
                    <i class="fa-solid fa-spinner fa-spin"></i> Waiting for controller...
                </div>
            </div>
            <button class="btn btn-primary" id="startViewerBtn" data-action="start-viewer">
                <i class="fa-solid fa-tv"></i> Start Viewer
            </button>
        </div>
    </div>

    <!-- Controller Mode -->
    <div class="card">
        <div class="card-header">
            <h3><i class="fa-solid fa-gamepad"></i> Join as Controller</h3>
        </div>
        <div class="card-body" style="text-align:center;padding:32px 20px;">
            <p style="font-size:14px;color:var(--text-secondary);margin-bottom:20px;">
                This device will control playback and drawing tools
            </p>
            <div style="margin-bottom:16px;">
                <label class="form-label" style="font-size:12px;color:var(--text-muted);display:block;margin-bottom:4px;">Enter Pairing Code</label>
                <input type="text" id="controllerCodeInput" class="form-input" placeholder="Enter 6-digit code"
                       maxlength="6" style="text-align:center;font-size:24px;letter-spacing:6px;font-family:monospace;max-width:280px;margin:0 auto;"
                       data-field="pairing_code">
            </div>
            <div id="controllerStatusText" style="display:none;font-size:13px;margin-bottom:12px;"></div>
            <button class="btn btn-primary" id="joinControllerBtn" data-action="join-controller">
                <i class="fa-solid fa-link"></i> Connect
            </button>
        </div>
    </div>
</div>

<!-- Active Sessions -->
<?php if (!empty($existingSessions)): ?>
<div class="card">
    <div class="card-header">
        <h3><i class="fa-solid fa-broadcast-tower"></i> Active Sessions</h3>
    </div>
    <div class="card-body" style="padding:0;">
        <?php foreach ($existingSessions as $session): ?>
            <div class="session-list-card" data-session-id="<?= (int)$session['id'] ?>">
                <div class="session-date-column" style="background:<?= $statusColors[$session['status']] ?? 'var(--text-muted)' ?>22;">
                    <div style="font-size:20px;font-weight:700;font-family:monospace;color:var(--text-white);letter-spacing:2px;">
                        <?= htmlspecialchars($session['session_code']) ?>
                    </div>
                </div>
                <div class="session-details-column" style="flex:1;">
                    <div style="display:flex;align-items:center;gap:8px;">
                        <span class="badge" style="background:<?= $statusColors[$session['status']] ?? 'var(--text-muted)' ?>33;color:<?= $statusColors[$session['status']] ?? 'var(--text-muted)' ?>;font-size:11px;">
                            <?= htmlspecialchars($statusLabels[$session['status']] ?? $session['status']) ?>
                        </span>
                    </div>
                    <div style="font-size:12px;color:var(--text-muted);margin-top:4px;">
                        Created: <?= date('M j, g:i A', strtotime($session['created_at'])) ?>
                        &middot; Expires: <?= date('g:i A', strtotime($session['expires_at'])) ?>
                    </div>
                </div>
                <div style="display:flex;gap:8px;align-items:center;">
                    <a href="?page=telestrate&session=<?= (int)$session['id'] ?>&role=viewer"
                       class="btn btn-sm btn-primary" data-action="resume-viewer">
                        <i class="fa-solid fa-tv"></i> Viewer
                    </a>
                    <a href="?page=telestrate&session=<?= (int)$session['id'] ?>&role=controller"
                       class="btn btn-sm btn-outline" data-action="resume-controller">
                        <i class="fa-solid fa-gamepad"></i> Controller
                    </a>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var csrfToken = document.getElementById('pairDeviceContainer').dataset.csrfToken || '';

    // Start Viewer
    var startViewerBtn = document.getElementById('startViewerBtn');
    if (startViewerBtn) {
        startViewerBtn.addEventListener('click', function() {
            this.disabled = true;
            this.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Starting...';

            if (typeof DeviceSync !== 'undefined') {
                var sync = new DeviceSync({
                    role: 'viewer',
                    csrfToken: csrfToken,
                    onStatusChange: function(status, code) {
                        document.getElementById('viewerPairingCode').style.display = 'block';
                        document.getElementById('pairingCodeDisplay').textContent = code || '------';
                        var statusEl = document.getElementById('viewerStatusText');

                        if (status === 'paired' || status === 'active') {
                            statusEl.innerHTML = '<i class="fa-solid fa-circle-check" style="color:var(--success);"></i> Controller connected!';
                            statusEl.style.color = 'var(--success)';
                            setTimeout(function() {
                                window.location.href = '?page=telestrate&role=viewer';
                            }, 1000);
                        } else if (status === 'waiting') {
                            statusEl.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Waiting for controller...';
                            statusEl.style.color = 'var(--warning)';
                        }
                    },
                    onError: function(err) {
                        startViewerBtn.disabled = false;
                        startViewerBtn.innerHTML = '<i class="fa-solid fa-tv"></i> Start Viewer';
                        alert('Error: ' + err);
                    }
                });
                sync.start();
            } else {
                // Fallback: redirect to telestrate page directly
                window.location.href = '?page=telestrate&role=viewer';
            }
        });
    }

    // Join as Controller
    var joinBtn = document.getElementById('joinControllerBtn');
    if (joinBtn) {
        joinBtn.addEventListener('click', function() {
            var code = document.getElementById('controllerCodeInput').value.trim();
            if (!code || code.length !== 6) {
                alert('Please enter a valid 6-digit pairing code.');
                return;
            }

            this.disabled = true;
            this.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Connecting...';
            var statusEl = document.getElementById('controllerStatusText');
            statusEl.style.display = 'block';
            statusEl.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Connecting...';
            statusEl.style.color = 'var(--info)';

            if (typeof DeviceSync !== 'undefined') {
                var sync = new DeviceSync({
                    role: 'controller',
                    sessionCode: code,
                    csrfToken: csrfToken,
                    onStatusChange: function(status) {
                        if (status === 'paired' || status === 'active') {
                            statusEl.innerHTML = '<i class="fa-solid fa-circle-check"></i> Connected!';
                            statusEl.style.color = 'var(--success)';
                            setTimeout(function() {
                                window.location.href = '?page=telestrate&role=controller';
                            }, 1000);
                        }
                    },
                    onError: function(err) {
                        joinBtn.disabled = false;
                        joinBtn.innerHTML = '<i class="fa-solid fa-link"></i> Connect';
                        statusEl.innerHTML = '<i class="fa-solid fa-times-circle"></i> ' + err;
                        statusEl.style.color = 'var(--error)';
                    }
                });
                sync.start();
            } else {
                window.location.href = '?page=telestrate&role=controller';
            }
        });
    }
});
</script>
