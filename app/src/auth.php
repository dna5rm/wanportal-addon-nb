<?php
/**
 * src/auth.php - Bearer token authentication
 *
 * Auth model: there is no app password and no app-level secret. The
 * Bearer token in the Authorization header IS the caller's NetBox API
 * token. It is validated live against the NetBox API root, and the same
 * token is then reused for every NetBox read/write the request performs
 * - it is also handed to the Ansible wrapper scripts as NETBOX_TOKEN.
 * Callers can therefore only ever do through certmgr what their NetBox
 * token already allows them to do directly.
 */

require_once __DIR__ . '/../src/netbox/helpers.php';

/**
 * Extract and validate the Bearer token from the Authorization header.
 *
 * Sends a 401 and exits when the header is missing or malformed, or when
 * NetBox rejects the token. On success returns the token itself so the
 * endpoint can pass it on to NetBox calls and scripts.
 */
function authenticate() {
    $headers = getallheaders();
    $auth    = $headers['Authorization'] ?? $headers['authorization'] ?? '';

    if (!preg_match('/^Bearer\s+(.+)$/i', $auth, $matches)) {
        http_response_code(401);
        echo json_encode(['error' => 'Missing or invalid Authorization header']);
        exit;
    }

    $token = trim($matches[1]);

    if (!netbox_validate_token($token)) {
        http_response_code(401);
        echo json_encode(['error' => 'Invalid or expired NetBox token']);
        exit;
    }

    return $token;
}
?>
