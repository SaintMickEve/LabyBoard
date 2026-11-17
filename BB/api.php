<?php

declare(strict_types=1);

/**
 * JSON API. One action:
 *   POST api.php?action=sanitise   body: character JSON (or an exported file)
 *   → { ok: true, data: {...clean character...}, warnings: [...] }
 *
 * Nothing is stored on the server; characters live in the user's browser and
 * in the JSON files they export.
 *
 * HOW AN IMPORT REACHES THIS FILE
 * When someone imports a .json file, assets/js/app.js reads it in the browser, then POSTs it here.
 * This script checks that the request is genuine and not too big, runs it through the Sanitiser
 * (src/Sanitiser.php), and sends back a clean copy plus a list of anything it had to fix.
 * If this endpoint can't be reached, the browser runs an identical copy of the same rules itself,
 * so importing still works offline.
 *
 * The checks below run in order, cheapest first, and each one stops the request with an HTTP error
 * code and a short message the browser can show:
 *   405 wrong method · 404 unknown action · 415 not JSON · 403 other website or expired session
 *   429 too many requests · 413 too big · 400 broken JSON · 500 unexpected server error
 */

namespace Battleboard;

require __DIR__ . '/src/bootstrap.php';

/** Largest request body accepted (128 KB). The browser checks the same limit before sending. */
const MAX_BODY_BYTES = 131072;
const RATE_LIMIT = 60; // requests per minute per session

// Security headers (see src/bootstrap.php), and "this answer is JSON and must never be cached".
sendSecurityHeaders();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

/**
 * Sends a JSON reply with the given HTTP status and stops the script.
 * The JSON_HEX_* flags turn <, >, &, ' and " into \u escapes, so even if a reply were ever shown as
 * HTML by mistake, nothing in it could act as markup.
 *
 * @param array<string, mixed> $body
 */
function respond(int $status, array $body): never
{
    http_response_code($status);
    echo json_encode(
        $body,
        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
    );
    exit;
}

try {
    // 1. Only "POST api.php?action=sanitise" with a JSON body is accepted.
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        header('Allow: POST');
        respond(405, ['ok' => false, 'error' => 'Method not allowed.']);
    }
    if (($_GET['action'] ?? '') !== 'sanitise') {
        respond(404, ['ok' => false, 'error' => 'Unknown action.']);
    }
    if (!str_starts_with(strtolower($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')) {
        respond(415, ['ok' => false, 'error' => 'Expected JSON.']);
    }

    // 2. Same-origin check (browsers always send Origin on POST fetches).
    //    The Origin header's host (and port, if any) must match the host this site is served on,
    //    so another website can't use a visitor's browser to call this API.
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    $host = $_SERVER['HTTP_HOST'] ?? '';
    if ($origin !== '' && parse_url($origin, PHP_URL_HOST) . (parse_url($origin, PHP_URL_PORT) ? ':' . parse_url($origin, PHP_URL_PORT) : '') !== $host) {
        respond(403, ['ok' => false, 'error' => 'Cross-origin request refused.']);
    }

    // 3. CSRF check. index.php puts a random token in the page (a <meta> tag); app.js sends it back
    //    in the X-CSRF-Token header. It must match the token stored in this visitor's session.
    //    hash_equals() compares in constant time, so the token can't be guessed by timing replies.
    startSession();
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!is_string($token) || empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $token)) {
        respond(403, ['ok' => false, 'error' => 'Your session has expired. Please reload the page.']);
    }

    // 4. Simple per-session rate limit.
    //    The session remembers when the current one-minute window started and how many requests
    //    have been made in it; a new window starts after 60 seconds.
    $now = time();
    $window = $_SESSION['rate'] ?? ['start' => $now, 'count' => 0];
    if ($now - $window['start'] >= 60) {
        $window = ['start' => $now, 'count' => 0];
    }
    $window['count']++;
    $_SESSION['rate'] = $window;
    // Save and unlock the session now: nothing below changes it, and other requests can carry on.
    session_write_close();
    if ($window['count'] > RATE_LIMIT) {
        respond(429, ['ok' => false, 'error' => 'Too many requests. Please wait a minute.']);
    }

    // 5. Size limit. The declared length is checked first; then at most one byte more than the limit
    //    is read, so an oversized body is detected without ever loading all of it into memory.
    $length = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($length > MAX_BODY_BYTES) {
        respond(413, ['ok' => false, 'error' => 'File too large (128 KB maximum).']);
    }
    $raw = file_get_contents('php://input', false, null, 0, MAX_BODY_BYTES + 1);
    if ($raw === false || strlen($raw) > MAX_BODY_BYTES) {
        respond(413, ['ok' => false, 'error' => 'File too large (128 KB maximum).']);
    }

    // 6. Parse the JSON. Nesting deeper than 64 levels is refused, which stops deliberately
    //    pathological files from using up memory.
    try {
        $input = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
    } catch (\JsonException) {
        respond(400, ['ok' => false, 'error' => 'That file is not valid JSON.']);
    }

    // 7. Clean it up. The Sanitiser never rejects valid JSON: anything wrong is fixed or dropped
    //    and listed in "warnings", which the browser shows to the user after the import.
    $sanitiser = new Sanitiser(Sanitiser::loadSchema(SCHEMA_PATH));
    $result = $sanitiser->sanitise($input);
    respond(200, ['ok' => true, 'data' => $result['data'], 'warnings' => $result['warnings']]);
} catch (\Throwable $e) {
    // Anything unexpected: log the detail for the site owner, but show the user only a generic message.
    error_log('battleboard api: ' . $e->getMessage());
    respond(500, ['ok' => false, 'error' => 'Something went wrong on the server.']);
}
