<?php
// =========================================================
// ACVideoReview - SYSTEM SETUP WIZARD
// =========================================================
// This wizard helps configure the system for first-time setup
// It should be removed or restricted in production

session_start();

// =========================================================
// AUTOMATIC PERMISSION SETUP FOR DOCKER ENVIRONMENTS
// =========================================================
function setupPermissions() {
    $base_dir = __DIR__;
    $required_dirs = [
        'uploads',
        'uploads/videos',
        'uploads/thumbnails',
        'uploads/imports',
        'logs',
        'tmp'
    ];

    $permission_issues = [];

    foreach ($required_dirs as $dir) {
        $full_path = $base_dir . '/' . $dir;
        if (!file_exists($full_path)) {
            if (!@mkdir($full_path, 0775, true)) {
                $last_error = error_get_last();
                $error_msg = $last_error ? $last_error['message'] : 'unknown error';
                $permission_issues[] = "Failed to create directory: $dir - $error_msg";
                continue;
            }
        }

        if (file_exists($full_path)) {
            if (!@chmod($full_path, 0775)) {
                $permission_issues[] = "Failed to set permissions on directory: $dir";
            }
        }
    }

    // Ensure root directory is writable (775)
    if (!@chmod($base_dir, 0775)) {
        $permission_issues[] = "Failed to set permissions on root directory";
    }

    return $permission_issues;
}

// Run permission setup automatically on first load
$permissions_flag_file = __DIR__ . '/.permissions_setup_done';
if (!file_exists($permissions_flag_file)) {
    $permission_issues = setupPermissions();
    @file_put_contents($permissions_flag_file, date('Y-m-d H:i:s'));

    if (!empty($permission_issues)) {
        $_SESSION['permission_warnings'] = $permission_issues;
    }
}

// Check if setup is already completed
$setup_complete_file = __DIR__ . '/.setup_complete';
if (file_exists($setup_complete_file) && !isset($_GET['force'])) {
    header("Location: index.php");
    exit();
}

$step = isset($_GET['step']) ? (int)$_GET['step'] : 1;
$error = '';
$success = '';

// Initialize session data if not exists
if (!isset($_SESSION['setup'])) {
    $_SESSION['setup'] = [
        'database' => false,
        'admin' => false,
        'config' => false
    ];
}

// Process form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($step == 1) {
        // Database Configuration
        $host = trim($_POST['db_host'] ?? '');
        $port = trim($_POST['db_port'] ?? '3306');
        $name = trim($_POST['db_name'] ?? '');
        $user = trim($_POST['db_user'] ?? '');
        $pass = $_POST['db_pass'] ?? '';

        try {
            $pdo = new PDO("mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4", $user, $pass);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            // Save to .env file
            $env_content = "DB_HOST=$host\nDB_PORT=$port\nDB_NAME=$name\nDB_USER=$user\nDB_PASS=$pass\n";
            $env_file = __DIR__ . '/video_review.env';

            if (file_put_contents($env_file, $env_content) === false) {
                throw new Exception("Failed to write configuration file. Please check directory permissions.");
            }

            if (!file_exists($env_file) || !is_readable($env_file)) {
                throw new Exception("Configuration file was created but is not readable.");
            }

            $_SESSION['setup']['database'] = true;
            $_SESSION['db_credentials'] = ['host' => $host, 'port' => $port, 'name' => $name, 'user' => $user, 'pass' => $pass];

            // Import video review schema
            $schema_file = __DIR__ . '/database_schema.sql';
            if (file_exists($schema_file)) {
                $schema = file_get_contents($schema_file);
                $pdo->exec($schema);
            }

            header("Location: setup.php?step=2");
            exit();
        } catch (PDOException $e) {
            $error = "Database connection failed: " . $e->getMessage();
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    } elseif ($step == 2) {
        // Application Configuration
        $app_url = trim($_POST['app_url'] ?? 'https://review.arcticwolves.ca');
        $main_app_url = trim($_POST['main_app_url'] ?? 'https://arcticwolves.ca');

        // Save config to env file
        $env_file = __DIR__ . '/video_review.env';
        $env_content = file_exists($env_file) ? file_get_contents($env_file) : '';

        // Add application config
        $env_content = rtrim($env_content) . "\nAPP_URL=" . $app_url . "\nMAIN_APP_URL=" . $main_app_url . "\n";

        if (file_put_contents($env_file, $env_content) === false) {
            $error = "Failed to write configuration file.";
        } else {
            $_SESSION['setup']['config'] = true;
            header("Location: setup.php?step=3");
            exit();
        }
    } elseif ($step == 3) {
        // Finalize Setup
        try {
            // Verify environment file exists and is valid
            $env_file = __DIR__ . '/video_review.env';
            if (!file_exists($env_file)) {
                throw new Exception("Configuration file not found. Please restart setup.");
            }

            $env_lines = file($env_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            $env_vars = [];
            foreach ($env_lines as $line) {
                if (strpos($line, '=') !== false) {
                    list($key, $value) = explode('=', $line, 2);
                    $env_vars[trim($key)] = trim($value);
                }
            }

            if (empty($env_vars['DB_HOST']) || empty($env_vars['DB_NAME']) || empty($env_vars['DB_USER'])) {
                throw new Exception("Configuration file is incomplete. Please restart setup.");
            }

            // Test database connection
            $test_pdo = new PDO(
                "mysql:host={$env_vars['DB_HOST']};port=" . ($env_vars['DB_PORT'] ?? '3306') . ";dbname={$env_vars['DB_NAME']};charset=utf8mb4",
                $env_vars['DB_USER'],
                isset($env_vars['DB_PASS']) ? $env_vars['DB_PASS'] : ''
            );
            $test_pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $test_pdo->query("SELECT 1");

            // Mark setup as complete
            file_put_contents($setup_complete_file, date('Y-m-d H:i:s'));

            // Clear setup session
            unset($_SESSION['setup']);
            unset($_SESSION['db_credentials']);

            $_SESSION['setup_success'] = true;
            header("Location: index.php");
            exit();
        } catch (PDOException $e) {
            $error = "Database connection test failed: " . $e->getMessage();
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Setup Wizard | Arctic Wolves Video Review</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;900&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #6B46C1;
            --primary-hover: #7C3AED;
            --bg: #0A0A0F;
            --card-bg: #16161F;
            --border: #2D2D3F;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { background: var(--bg); color: #fff; font-family: 'Inter', sans-serif; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 20px; }
        .setup-container { max-width: 600px; width: 100%; background: var(--card-bg); border: 1px solid var(--border); border-radius: 12px; padding: 40px; }
        .logo { text-align: center; margin-bottom: 30px; }
        .logo h1 { font-size: 28px; font-weight: 900; letter-spacing: -1px; }
        .logo h1 span { color: var(--primary); }
        .logo p { color: #94a3b8; font-size: 14px; margin-top: 10px; }
        .progress-bar { display: flex; gap: 10px; margin-bottom: 40px; }
        .progress-step { flex: 1; height: 4px; background: var(--border); border-radius: 2px; }
        .progress-step.active { background: var(--primary); }
        h2 { font-size: 22px; margin-bottom: 10px; }
        p.desc { color: #94a3b8; margin-bottom: 30px; font-size: 14px; }
        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; font-size: 13px; font-weight: 600; margin-bottom: 8px; color: #cbd5e1; }
        .form-group input { width: 100%; height: 45px; background: var(--bg); border: 1px solid var(--border); border-radius: 6px; padding: 0 15px; color: #fff; font-size: 14px; font-family: 'Inter', sans-serif; }
        .form-group input:focus { outline: none; border-color: var(--primary); }
        .btn-primary { width: 100%; height: 45px; background: var(--primary); color: #fff; border: none; border-radius: 6px; font-size: 14px; font-weight: 700; cursor: pointer; font-family: 'Inter', sans-serif; }
        .btn-primary:hover { background: var(--primary-hover); }
        .alert { padding: 15px; border-radius: 6px; margin-bottom: 20px; font-size: 13px; }
        .alert-error { background: rgba(239, 68, 68, 0.1); border: 1px solid #ef4444; color: #ef4444; }
        .alert-success { background: rgba(0, 255, 136, 0.1); border: 1px solid #00ff88; color: #00ff88; }
        .alert-warning { background: rgba(251, 191, 36, 0.1); border: 1px solid #fbbf24; color: #fbbf24; }
        .step-info { background: rgba(107, 70, 193, 0.05); border-left: 3px solid var(--primary); padding: 15px; margin-bottom: 20px; font-size: 13px; color: #94a3b8; }
        .back-link { text-align: center; margin-top: 20px; }
        .back-link a { color: var(--primary); text-decoration: none; font-size: 13px; }
        .back-link a:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <div class="setup-container">
        <div class="logo">
            <h1><i class="fas fa-play-circle" style="color:var(--primary);"></i> VIDEO <span>REVIEW</span></h1>
            <p>System Setup Wizard</p>
        </div>

        <div class="progress-bar">
            <div class="progress-step <?= $step >= 1 ? 'active' : '' ?>"></div>
            <div class="progress-step <?= $step >= 2 ? 'active' : '' ?>"></div>
            <div class="progress-step <?= $step >= 3 ? 'active' : '' ?>"></div>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-error">
                <i class="fa-solid fa-circle-exclamation"></i> <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['permission_warnings']) && !empty($_SESSION['permission_warnings'])): ?>
            <div class="alert alert-warning">
                <i class="fa-solid fa-exclamation-triangle"></i> <strong>Permission Warnings:</strong><br/>
                <?php foreach ($_SESSION['permission_warnings'] as $warning): ?>
                    &bull; <?= htmlspecialchars($warning) ?><br/>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($step == 1): ?>
            <h2>Step 1: Database Configuration</h2>
            <p class="desc">Enter your database connection details. The Video Review system uses the same database as the main Arctic Wolves application.</p>
            <div class="step-info">
                <i class="fa-solid fa-info-circle"></i> If you are using Docker with linuxserver/mariadb, set the host to your MariaDB container name (e.g. <code>mariadb</code>).
            </div>
            <form method="POST">
                <div class="form-group">
                    <label>Database Host</label>
                    <input type="text" name="db_host" value="mariadb" required>
                </div>
                <div class="form-group">
                    <label>Database Port</label>
                    <input type="text" name="db_port" value="3306" required>
                </div>
                <div class="form-group">
                    <label>Database Name</label>
                    <input type="text" name="db_name" value="arctic_wolves" required>
                </div>
                <div class="form-group">
                    <label>Database User</label>
                    <input type="text" name="db_user" required>
                </div>
                <div class="form-group">
                    <label>Database Password</label>
                    <div style="position: relative; display: flex; align-items: center;">
                        <input type="password" name="db_pass" id="db_pass" style="flex: 1; padding-right: 40px;">
                        <button type="button" onclick="togglePasswordVisibility('db_pass', this)" aria-label="Toggle password visibility" style="position: absolute; right: 10px; background: none; border: none; cursor: pointer; color: #64748b; padding: 5px;">
                            <i class="fa-solid fa-eye"></i>
                        </button>
                    </div>
                </div>
                <button type="submit" class="btn-primary">Connect &amp; Import Schema</button>
            </form>

        <?php elseif ($step == 2): ?>
            <h2>Step 2: Application Configuration</h2>
            <p class="desc">Configure the application URLs for the Video Review subdomain and the main Arctic Wolves application.</p>
            <div class="step-info">
                <i class="fa-solid fa-info-circle"></i> The Video Review app runs on a subdomain and authenticates users through the main Arctic Wolves login.
            </div>
            <form method="POST">
                <div class="form-group">
                    <label>Video Review URL (this subdomain)</label>
                    <input type="url" name="app_url" value="https://review.arcticwolves.ca" required>
                </div>
                <div class="form-group">
                    <label>Main Arctic Wolves App URL</label>
                    <input type="url" name="main_app_url" value="https://arcticwolves.ca" required>
                </div>
                <button type="submit" class="btn-primary">Continue to Step 3</button>
            </form>

        <?php elseif ($step == 3): ?>
            <h2>Step 3: Complete Setup</h2>
            <p class="desc">Everything is configured. Click below to finalize and start using Video Review.</p>
            <div class="step-info">
                <i class="fa-solid fa-check-circle" style="color:#10B981;"></i> Database connected and schema imported.<br>
                <i class="fa-solid fa-check-circle" style="color:#10B981;"></i> Application URLs configured.<br>
                <i class="fa-solid fa-check-circle" style="color:#10B981;"></i> Directory permissions verified.
            </div>
            <div class="alert alert-warning">
                <i class="fa-solid fa-exclamation-triangle"></i> <strong>Important:</strong> After setup, restrict access to <code>setup.php</code> by uncommenting the deny block in your Nginx configuration.
            </div>
            <form method="POST">
                <button type="submit" class="btn-primary">Complete Setup &amp; Launch</button>
            </form>
        <?php endif; ?>

        <?php if ($step > 1): ?>
            <div class="back-link">
                <a href="setup.php?step=<?= $step - 1 ?>">
                    <i class="fa-solid fa-arrow-left"></i> Back to Previous Step
                </a>
            </div>
        <?php endif; ?>
    </div>

<script>
function togglePasswordVisibility(inputId, button) {
    var input = document.getElementById(inputId);
    var icon = button.querySelector('i');
    if (input.type === 'password') {
        input.type = 'text';
        icon.classList.remove('fa-eye');
        icon.classList.add('fa-eye-slash');
    } else {
        input.type = 'password';
        icon.classList.remove('fa-eye-slash');
        icon.classList.add('fa-eye');
    }
}
</script>
</body>
</html>
