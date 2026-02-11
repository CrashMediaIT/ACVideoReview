<?php
// ACVideoReview - Database Configuration
// Uses same loadEnv pattern as Arctic_Wolves main application

/**
 * Load environment variables from a .env file
 */
function loadEnv(string $path): bool {
    if (!file_exists($path)) {
        return false;
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        if (!str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key   = trim($key);
        $value = trim($value);
        // Strip surrounding quotes
        if (preg_match('/^"(.*)"$/', $value, $m)) {
            $value = $m[1];
        } elseif (preg_match("/^'(.*)'$/", $value, $m)) {
            $value = $m[1];
        }
        $_ENV[$key]    = $value;
        $_SERVER[$key] = $value;
        putenv("$key=$value");
    }
    return true;
}

// Try env file locations in priority order
$envLoaded = loadEnv('/config/video_review.env')
          || loadEnv(__DIR__ . '/.env')
          || loadEnv('/config/arctic_wolves.env');

if (!$envLoaded) {
    error_log('ACVideoReview: No environment file found');
}

// Database credentials from environment
$dbHost = getenv('DB_HOST') ?: '127.0.0.1';
$dbPort = getenv('DB_PORT') ?: '3306';
$dbName = getenv('DB_NAME') ?: 'arctic_wolves';
$dbUser = getenv('DB_USER') ?: '';
$dbPass = getenv('DB_PASS') ?: '';

$pdo = null;

try {
    $dsn = "mysql:host=$dbHost;port=$dbPort;dbname=$dbName;charset=utf8mb4";
    $pdo = new PDO($dsn, $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    define('DB_CONNECTED', true);
} catch (PDOException $e) {
    error_log('ACVideoReview DB connection failed: ' . $e->getMessage());
    define('DB_CONNECTED', false);
}

/**
 * Execute a prepared query and return the statement
 */
function dbQuery(PDO $pdo, string $sql, array $params = []): PDOStatement {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}
