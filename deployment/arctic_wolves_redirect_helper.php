<?php
/**
 * Arctic Wolves - Safe Redirect Helper for Subdomain Login Flow
 *
 * Include this file in the Arctic_Wolves root and call safeRedirectAfterLogin()
 * in login.php / process_login.php instead of the hard-coded:
 *
 *   header("Location: dashboard.php");
 *   exit();
 *
 * Replace with:
 *
 *   require_once __DIR__ . '/redirect_helper.php';
 *   safeRedirectAfterLogin();
 *
 * This checks for a ?redirect= query parameter (set by ACVideoReview's index.php)
 * and sends the user back to the subdomain app after login, provided the URL is a
 * trusted arcticwolves.ca subdomain.
 */

/**
 * Redirect the user after a successful login.
 *
 * If a 'redirect' query-string parameter is present and points to a trusted
 * *.arcticwolves.ca host, the user is sent there. Otherwise they go to the
 * default destination (dashboard.php).
 *
 * The allow-list is intentionally strict: scheme must be https and the host
 * must end with ".arcticwolves.ca" (or be exactly "arcticwolves.ca").
 *
 * @param string $default Fallback URL when no valid redirect is provided.
 */
function safeRedirectAfterLogin(string $default = 'dashboard.php'): void {
    $redirect = $_GET['redirect'] ?? $_SESSION['login_redirect'] ?? null;

    if ($redirect) {
        $parsed = parse_url($redirect);

        // Validate: must be HTTPS and a *.arcticwolves.ca host
        $host = $parsed['host'] ?? '';
        $scheme = $parsed['scheme'] ?? '';
        $isTrusted = (
            $scheme === 'https'
            && $host !== ''
            && (
                strcasecmp($host, 'arcticwolves.ca') === 0
                || str_ends_with(strtolower($host), '.arcticwolves.ca')
            )
        );

        if ($isTrusted) {
            unset($_SESSION['login_redirect']);
            header('Location: ' . $redirect);
            exit();
        }
    }

    // Fallback to default dashboard
    header('Location: ' . $default);
    exit();
}

/**
 * Persist the redirect parameter into the session so it survives a POST form
 * submission (login.php POSTs to itself or to process_login.php).
 *
 * Call this early in login.php, before the login form is rendered:
 *
 *   require_once __DIR__ . '/redirect_helper.php';
 *   captureRedirectParam();
 */
function captureRedirectParam(): void {
    if (isset($_GET['redirect']) && !empty($_GET['redirect'])) {
        $_SESSION['login_redirect'] = $_GET['redirect'];
    }
}
