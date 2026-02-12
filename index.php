<?php
// ACVideoReview - Entry Point / Login Check
require_once __DIR__ . '/config/app.php';
initSession();

require_once __DIR__ . '/db_config.php';

// If user is logged in, go to dashboard
if (isset($_SESSION['user_id']) && isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
    header('Location: dashboard.php');
    exit;
}

// If redirected back from login but session still not valid, the PHP session
// is not shared between the main app and this subdomain — show an error page
// instead of redirecting again (which would create an infinite loop).
if (isset($_GET['from_login'])) {
    require_once __DIR__ . '/security.php';
    setSecurityHeaders();
    http_response_code(403);
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Session Error — <?= htmlspecialchars(APP_NAME) ?></title>
        <link rel="stylesheet" href="css/style-guide.css">
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;900&display=swap" rel="stylesheet">
        <style>
            body { display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;background:#0A0A0F;color:#fff;font-family:'Inter',sans-serif; }
            .error-card { text-align:center;max-width:480px;padding:48px 32px;background:#0d1116;border:1px solid #1e293b;border-radius:12px; }
            .error-card h1 { font-size:1.5rem;margin:0 0 12px; }
            .error-card p { color:#94a3b8;font-size:0.9rem;line-height:1.6;margin:0 0 24px; }
            .error-card .icon { font-size:48px;color:#f59e0b;margin-bottom:16px; }
            .btn-group { display:flex;gap:12px;justify-content:center;flex-wrap:wrap; }
            .btn { display:inline-flex;align-items:center;gap:8px;padding:10px 20px;border-radius:8px;text-decoration:none;font-weight:600;font-size:0.85rem;border:none;cursor:pointer; }
            .btn-primary { background:#6B46C1;color:#fff; }
            .btn-secondary { background:#1e293b;color:#94a3b8;border:1px solid #334155; }
            .btn:hover { opacity:0.9; }
        </style>
    </head>
    <body>
        <div class="error-card">
            <div class="icon"><i class="fa-solid fa-link-slash"></i></div>
            <h1>Session Not Available</h1>
            <p>
                You are logged in on the main Arctic Wolves site, but the session could not be
                shared with the Video Review subdomain. Please ensure that both applications use
                a shared PHP session store (e.g. the same <code>session.save_path</code>).
            </p>
            <div class="btn-group">
                <a href="<?= htmlspecialchars(MAIN_APP_URL) ?>/login.php" class="btn btn-primary">
                    <i class="fa-solid fa-arrow-right-to-bracket"></i> Main App Login
                </a>
                <a href="<?= htmlspecialchars(APP_URL) ?>" class="btn btn-secondary">
                    <i class="fa-solid fa-rotate"></i> Try Again
                </a>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// Not logged in — redirect to main app login with return URL.
// Append from_login flag so we can detect a redirect loop on return.
$returnUrl = APP_URL . '?from_login=1';
header('Location: ' . MAIN_APP_URL . '/login.php?redirect=' . urlencode($returnUrl));
exit;
