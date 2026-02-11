<?php
// ACVideoReview - Security Helpers

/**
 * Set standard security headers for all responses
 */
function setSecurityHeaders(): void {
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('X-XSS-Protection: 1; mode=block');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header("Content-Security-Policy: default-src 'self'; script-src 'self' https://cdnjs.cloudflare.com https://cdn.jsdelivr.net 'unsafe-inline'; style-src 'self' https://cdnjs.cloudflare.com https://fonts.googleapis.com 'unsafe-inline'; font-src 'self' https://cdnjs.cloudflare.com https://fonts.gstatic.com; img-src 'self' data: blob:; media-src 'self' blob:; connect-src 'self';");
}

/**
 * Log a security event to the database
 */
function logSecurityEvent(PDO $pdo, string $event_type, string $description, ?int $user_id = null): void {
    try {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $stmt = $pdo->prepare(
            "INSERT INTO security_logs (event_type, description, user_id, ip_address, created_at)
             VALUES (:event_type, :description, :user_id, :ip_address, NOW())"
        );
        $stmt->execute([
            ':event_type'  => $event_type,
            ':description' => $description,
            ':user_id'     => $user_id,
            ':ip_address'  => $ip,
        ]);
    } catch (PDOException $e) {
        error_log('Failed to log security event: ' . $e->getMessage());
    }
}

/**
 * Sanitize user input
 */
function sanitizeInput(mixed $input): mixed {
    if (is_array($input)) {
        return array_map('sanitizeInput', $input);
    }
    $input = trim($input);
    $input = stripslashes($input);
    $input = htmlspecialchars($input, ENT_QUOTES, 'UTF-8');
    return $input;
}

/**
 * Check if the current user's role is in the list of allowed roles
 */
function validateRole(array $required_roles): bool {
    if (!isset($_SESSION['role'])) {
        return false;
    }
    return in_array($_SESSION['role'], $required_roles, true);
}

/**
 * Redirect to login if user is not authenticated
 */
function requireAuth(): void {
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
        header('Location: ' . MAIN_APP_URL . '/login.php?redirect=' . urlencode(APP_URL));
        exit;
    }
}

/**
 * Generate a CSRF token and store it in the session
 */
function generateCsrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Validate the submitted CSRF token against the session token
 */
function checkCsrfToken(): bool {
    $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (empty($token) || empty($_SESSION['csrf_token'])) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}
