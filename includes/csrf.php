<?php
/**
 * /includes/csrf.php
 *
 * Per-session CSRF token issuance & verification.
 *  - csrf_token()      → returns the current token (creates one if needed)
 *  - csrf_field()      → echoes a hidden <input> for forms
 *  - csrf_check()      → verifies request, aborts with 403 on failure
 */

declare(strict_types=1);

if (!defined('AUDITBOS_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('Forbidden');
}

function csrf_token(): string
{
    if (empty($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES) . '">';
}

/**
 * Verify a POST request carries a valid CSRF token. Aborts on failure.
 */
function csrf_check(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        return;
    }
    $provided = $_POST['_csrf'] ?? '';
    $expected = $_SESSION['_csrf'] ?? '';
    if (!$expected || !is_string($provided) || !hash_equals($expected, $provided)) {
        http_response_code(403);
        exit('Invalid CSRF token. Please reload and try again.');
    }
}
