<?php
// ACVideoReview - Application Configuration
// This application runs on review.arcticwolves.ca as a subdomain of the Arctic Wolves platform

define('APP_NAME', 'Arctic Wolves Video Review');
define('APP_VERSION', '1.0.0');
define('APP_URL', 'https://review.arcticwolves.ca');
define('MAIN_APP_URL', 'https://arcticwolves.ca');
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
