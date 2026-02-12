<?php
// ACVideoReview - Application Configuration
// This application runs on review.arcticwolves.ca as a subdomain of the Arctic Wolves platform

// Load environment variables early so URL configuration is available
// (db_config.php also loads these for database credentials)
foreach (['/config/video_review.env', dirname(__DIR__) . '/.env', '/config/arctic_wolves.env'] as $_envPath) {
    if (file_exists($_envPath)) {
        $_lines = file($_envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($_lines as $_line) {
            $_line = trim($_line);
            if ($_line === '' || $_line[0] === '#' || !str_contains($_line, '=')) continue;
            [$_key, $_val] = explode('=', $_line, 2);
            $_key = trim($_key);
            $_val = trim($_val);
            if (preg_match('/^"(.*)"$/', $_val, $_m)) $_val = $_m[1];
            elseif (preg_match("/^'(.*)'$/", $_val, $_m)) $_val = $_m[1];
            if (!getenv($_key)) {
                putenv("$_key=$_val");
                $_ENV[$_key] = $_val;
                $_SERVER[$_key] = $_val;
            }
        }
        break;
    }
}
unset($_envPath, $_lines, $_line, $_key, $_val, $_m);

define('APP_NAME', 'Arctic Wolves Video Review');
define('APP_VERSION', '1.0.0');
define('APP_URL', getenv('APP_URL') ?: 'https://review.arcticwolves.ca');
define('MAIN_APP_URL', getenv('MAIN_APP_URL') ?: 'https://arcticwolves.ca');
define('APP_ROOT', dirname(__DIR__));

// File paths
define('UPLOAD_DIR', APP_ROOT . '/uploads/');
define('VIDEO_DIR', UPLOAD_DIR . 'videos/');
define('THUMB_DIR', UPLOAD_DIR . 'thumbnails/');
define('IMPORT_DIR', UPLOAD_DIR . 'imports/');
define('LOG_DIR', APP_ROOT . '/logs/');
define('TMP_DIR', APP_ROOT . '/tmp/');

// Upload limits
define('MAX_VIDEO_SIZE', 2 * 1024 * 1024 * 1024); // 2GB
define('MAX_THUMB_SIZE', 5 * 1024 * 1024); // 5MB
define('ALLOWED_VIDEO_FORMATS', ['mp4', 'mov', 'avi', 'mkv', 'webm', 'mts', 'm2ts']);
define('ALLOWED_IMAGE_FORMATS', ['jpg', 'jpeg', 'png', 'webp']);

// Device session config
define('DEVICE_SESSION_TTL', 4 * 60 * 60); // 4 hours
define('DEVICE_HEARTBEAT_INTERVAL', 10); // 10 seconds
define('PAIRING_CODE_LENGTH', 6);

// Roles that can access coach features
define('COACH_ROLES', ['coach', 'coach_plus', 'health_coach', 'team_coach', 'admin']);
define('ADMIN_ROLES', ['admin']);

/**
 * Configure session cookie for cross-subdomain sharing and start the session.
 * Derives parent domain from APP_URL (e.g. ".arcticwolves.ca" from "review.arcticwolves.ca")
 * so the session cookie set by the main app is accessible on the subdomain.
 */
function initSession(): void {
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $host = parse_url(APP_URL, PHP_URL_HOST);
    if ($host) {
        $parts = explode('.', $host);
        if (count($parts) > 2) {
            $cookieDomain = '.' . implode('.', array_slice($parts, -2));
        } else {
            $cookieDomain = '.' . $host;
        }

        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'domain'   => $cookieDomain,
            'secure'   => (parse_url(APP_URL, PHP_URL_SCHEME) === 'https'),
            'httponly'  => true,
            'samesite'  => 'Lax',
        ]);
    }

    session_start();
}
