<?php
/**
 * index.php - Application router
 *
 * Single entry point for certmgr. Maps URI paths onto the endpoint files
 * under api/, serves the web UI at /, and exposes the OpenAPI spec:
 *
 *   /                   Web UI - the certificate console (frontend.php)
 *   /api/build          Build a new private key + CSR      (api/build.php)
 *   /api/show           Retrieve a stored certificate      (api/show.php)
 *   /api/import         Store a CA-signed certificate      (api/import.php)
 *   /api/selfsign       Mint a self-signed certificate     (api/selfsign.php)
 *   /swagger            Swagger UI for the OpenAPI spec
 *   /openapi.yaml       The OpenAPI 3.0 spec itself
 *   anything else       404 JSON
 *
 * Notes:
 * - config.php must exist next to this file (copy it from
 *   config.php.example).
 * - Under Apache, .htaccess rewrites every non-file request here and the
 *   RewriteBase prefix is stripped below; the PHP built-in server
 *   (php -S ... index.php) needs no stripping.
 * - Authentication is NOT done here: each endpoint calls authenticate()
 *   itself. The Bearer token in the Authorization header is the caller's
 *   NetBox API token, validated live against NetBox (src/auth.php) and
 *   then reused for every NetBox operation the request performs.
 */

require_once __DIR__ . '/config.php';

// Get the request URI and strip query string
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$uri = rtrim($uri, '/');

// Only strip RewriteBase prefix when running under Apache
// PHP built-in server does not need this
if (php_sapi_name() !== 'cli-server') {
    $base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
    if ($base && strpos($uri, $base) === 0) {
        $uri = substr($uri, strlen($base));
    }
}

// Normalize empty URI to root
if (empty($uri)) {
    $uri = '/';
}

// Handle CORS preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Route the request
switch ($uri) {
    case '/':
        // Web UI. The console is frontend.php; page chrome (theme, topnav,
        // footer) comes from src/chrome.php.
        require __DIR__ . '/frontend.php';
        break;

    case '/api/build':
        require __DIR__ . '/api/build.php';
        break;

    case '/api/show':
        require __DIR__ . '/api/show.php';
        break;

    case '/api/import':
        require __DIR__ . '/api/import.php';
        break;

    case '/api/selfsign':
        require __DIR__ . '/api/selfsign.php';
        break;

    case '/swagger':
        header('Content-Type: text/html');
        readfile(__DIR__ . '/swagger.html');
        break;

    case '/openapi.yaml':
        header('Content-Type: application/yaml');
        readfile(__DIR__ . '/openapi.yaml');
        break;

    default:
        http_response_code(404);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Not found']);
        break;
}
?>
