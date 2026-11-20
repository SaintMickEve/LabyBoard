<?php

declare(strict_types=1);

/**
 * Shared set-up for every PHP entry point (index.php and api.php).
 *
 * It defines where the app lives on the web server, the site title, and a few small helpers:
 *   url()                  builds links inside the /BB/ folder
 *   e()                    escapes text before it is written into HTML
 *   startSession()         starts the visitor's session with safe cookie settings
 *   csrfToken()            the per-session secret that api.php checks on every import
 *   sendSecurityHeaders()  the browser security rules sent with every page and API reply
 *
 * This file is in src/, which .htaccess blocks from direct web access; it is only ever included.
 */

namespace Battleboard;

require_once __DIR__ . '/Sanitiser.php';

/** All limits and options (lengths, ranges, layouts...) live in this one JSON file. */
const SCHEMA_PATH = __DIR__ . '/../config/schema.json';

/**
 * Public URL path of the app on the web server (with leading and trailing slash).
 * The site is hosted at https://yourdomain/BB/ — change this if you move it.
 */
const BASE_PATH = '/BB/';

/** Shown in the browser tab, the page header and the "generator" line of exported files. */
const SITE_TITLE = 'Labyrinthe Battleboard Generator (RC 2.1.0)';

/** Build an absolute URL path inside the app, e.g. url('api.php') → /BB/api.php */
function url(string $path = ''): string
{
    return BASE_PATH . ltrim($path, '/');
}

/**
 * Escape a value for HTML text or attribute output.
 * Turns < > & " ' into HTML entities, so user text can never become markup or break out of an
 * attribute. ENT_SUBSTITUTE replaces invalid UTF-8 rather than returning an empty string.
 */
function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
}

/**
 * True when the visitor is on HTTPS. Also recognises HTTPS that ends at a proxy or load balancer
 * in front of the web server (X-Forwarded-Proto), as on some shared hosting.
 */
function isHttps(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || (($_SERVER['SERVER_PORT'] ?? '') === '443');
}

/**
 * Starts the PHP session (only once per request). The session holds just the CSRF token and the
 * rate-limit counter; no character data is ever stored on the server.
 *
 * Cookie settings:
 *   lifetime 0       the cookie is deleted when the browser closes
 *   path /BB/        it is only sent to this app, not the rest of the domain
 *   secure           HTTPS-only when the site is on HTTPS
 *   httponly         page scripts can't read it
 *   samesite Strict  other websites can't make the browser send it
 * strict_mode refuses session IDs the server didn't create (protects against session fixation).
 */
function startSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_name('bbsid');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => BASE_PATH,
        'secure'   => isHttps(),
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
}

/**
 * Returns this session's CSRF token, creating a random 64-character one the first time.
 * index.php puts it in the page; app.js sends it back with every import; api.php compares the two.
 */
function csrfToken(): string
{
    startSession();
    if (empty($_SESSION['csrf']) || !is_string($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

/**
 * Browser security rules sent with every response.
 *
 * Content-Security-Policy: the page may only load scripts, styles, fonts and images from this
 * site, and may only talk to this site. Inline <script> and style="" attributes are not allowed,
 * which is why app.js sets styles through JavaScript (element.style) instead of in the HTML.
 * The other headers stop other sites framing the page (X-Frame-Options, frame-ancestors), stop
 * the browser guessing file types (nosniff), keep the full URL out of referrers, turn off camera,
 * microphone, location and payment access, and (on HTTPS) tell browsers to always use HTTPS.
 */
function sendSecurityHeaders(): void
{
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; "
        . "font-src 'self'; img-src 'self' data:; connect-src 'self'; object-src 'none'; "
        . "base-uri 'none'; form-action 'none'; frame-ancestors 'none'");
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header('X-Frame-Options: DENY');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()');
    header('Cross-Origin-Opener-Policy: same-origin');
    if (isHttps()) {
        header('Strict-Transport-Security: max-age=31536000');
    }
}
