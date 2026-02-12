<?php
/**
 * Arctic Wolves - Cross-Subdomain Session Bootstrap
 *
 * Drop this file into the Arctic_Wolves root and require it at the TOP of every
 * file that currently calls session_start() directly (login.php, process_login.php,
 * index.php, dashboard.php, etc.).
 *
 * Replace:
 *   session_start();
 *
 * With:
 *   require_once __DIR__ . '/session_boot.php';
 *
 * This sets the session cookie domain to ".arcticwolves.ca" so the session is
 * visible to both arcticwolves.ca and review.arcticwolves.ca (or any subdomain).
 *
 * IMPORTANT: This file MUST be included in process_login.php BEFORE session_start().
 * process_login.php calls session_regenerate_id(true) after login, which sends a
 * new Set-Cookie header. Without session_set_cookie_params() beforehand the new
 * cookie defaults to the exact host ("arcticwolves.ca") and subdomains cannot see
 * the session. If config/session.php already exists and does the same thing, you
 * can require that file instead — but process_login.php must include it.
 */

if (session_status() === PHP_SESSION_ACTIVE) {
    return;
}

// Derive the parent domain for the session cookie.
// Default to .arcticwolves.ca; override via SESSION_COOKIE_DOMAIN env var for
// multi-level TLDs (e.g. .co.uk).
$cookieDomain = $_ENV['SESSION_COOKIE_DOMAIN'] ?? null;
if (!$cookieDomain) {
    $host = $_SERVER['HTTP_HOST'] ?? 'arcticwolves.ca';
    $parts = explode('.', $host);
    $cookieDomain = (count($parts) > 2)
        ? '.' . implode('.', array_slice($parts, -2))
        : '.' . $host;
}

session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'domain'   => $cookieDomain,
    'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    'httponly'  => true,
    'samesite'  => 'Lax',
]);

session_start();
