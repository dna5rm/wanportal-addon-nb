<?php
/**
 * index.php
 * Entrypoint for the cloud API (NetBox backend). Strips the /nb/cloud-api
 * base, then routes METHOD + path pairs: GET/POST/DELETE reservation and
 * GET free-blocks, with swagger and openapi.yaml public. Everything else
 * sits behind a single-operator static bearer from config.php
 * (require_auth gate) — the intended auth for this single-operator API,
 * not vip-api's live token validation against NetBox.
 */

header('Content-Type: application/json');

// CORS preflight: OPTIONS answers 204 with no auth check. The .htaccess
// normally intercepts preflights first ([R=204]); this covers requests
// that reach PHP directly (DirectoryIndex hits, rewrite bypasses).
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

ob_start();
$config = include __DIR__ . '/config.php';
ob_end_clean();

require __DIR__ . '/src/utils.php';
require __DIR__ . '/src/netbox.php';

$request_method = $_SERVER['REQUEST_METHOD'];
$request_uri    = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$request_path   = trim(str_replace('/nb/cloud-api', '', $request_uri), '/');

$public_paths = ['swagger', 'openapi.yaml'];

$headers = getallheaders();

// Header names are case-insensitive (RFC 9110): some proxies/HTTP2 hops
// forward the header lower-cased, so match the name insensitively.
$auth_header = null;
foreach ($headers as $header_name => $header_value) {
    if (strcasecmp((string)$header_name, 'Authorization') === 0) {
        $auth_header = trim((string)$header_value);
        break;
    }
}
if ($auth_header === null && isset($_SERVER['HTTP_AUTHORIZATION'])) {
    $auth_header = trim((string)$_SERVER['HTTP_AUTHORIZATION']);
}
if ($auth_header === null && isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
    $auth_header = trim((string)$_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
}

// Strip only a LEADING Bearer scheme (case-insensitive); a credential that
// merely contains the scheme word must not be mangled.
$auth_token = null;
if ($auth_header !== null) {
    if (preg_match('/^Bearer\s+(.+)$/i', $auth_header, $m)) {
        $auth_token = trim($m[1]);
    } else {
        $auth_token = $auth_header;
    }
}

if ($config['require_auth'] && !in_array($request_path, $public_paths)) {
    if (!validate_token($auth_token)) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'message' => 'Unauthorized - Invalid or missing token',
            'data'    => null
        ], JSON_PRETTY_PRINT);
        exit;
    }
}

if (in_array($request_path, ['swagger', 'openapi.yaml'])) {
    switch ($request_path) {
        case 'swagger':
            if (file_exists(__DIR__ . '/swagger.html')) {
                header('Content-Type: text/html');
                echo file_get_contents(__DIR__ . '/swagger.html');
            } else {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Swagger UI file not found', 'data' => null]);
            }
            exit;

        case 'openapi.yaml':
            if (file_exists(__DIR__ . '/openapi.yaml')) {
                header('Content-Type: application/yaml');
                echo file_get_contents(__DIR__ . '/openapi.yaml');
            } else {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'OpenAPI specification file not found', 'data' => null]);
            }
            exit;
    }
}

try {
    $response = null;

    switch ("$request_method $request_path") {

        case 'GET reservation':
            $body_data    = json_decode(file_get_contents('php://input'), true);
            $query_account = $_GET['account'] ?? '';
            $query_region  = $_GET['region']  ?? '';

            $data = [
                'account' => $body_data['account'] ?? $query_account,
                'region'  => $body_data['region']  ?? $query_region
            ];

            if (empty($data['account']) || empty($data['region'])) {
                $response = [
                    'success' => false,
                    'message' => 'Missing required parameters: account and region',
                    'data'    => null
                ];
                http_response_code(400);
                break;
            }

            $response = get_reservation($data, $config);
            break;

        case 'POST reservation':
            $data     = json_decode(file_get_contents('php://input'), true);
            $response = create_reservation($data, $config);
            break;

        case 'DELETE reservation':
            $data     = json_decode(file_get_contents('php://input'), true);
            $response = delete_reservation($data, $config);
            break;

        case 'GET free-blocks':
            $environment = $_GET['environment'] ?? '';
            $region      = $_GET['region']      ?? '';

            if (empty($environment) || empty($region)) {
                $response = format_response(false, null, 'Environment and region are required', 400);
                break;
            }

            $parent_prefix = get_parent_prefix($environment, $region, $config);
            if (!$parent_prefix) {
                $response = format_response(
                    false, null,
                    "No parent prefix found for environment '$environment' and region '$region'",
                    404
                );
                break;
            }

            $free_blocks = get_available_prefixes($parent_prefix, $config);
            if ($free_blocks === false) {
                $response = format_response(false, null, 'Failed to retrieve available prefixes', 500);
                break;
            }

            $response = format_response(
                true,
                [
                    'parent_container' => $parent_prefix[0]['prefix'],
                    'total_blocks'     => count($free_blocks),
                    'blocks'           => array_values($free_blocks)
                ],
                'Free blocks retrieved successfully',
                200
            );
            break;

        default:
            $response = ['success' => false, 'message' => 'Endpoint not found', 'data' => null];
            http_response_code(404);
    }

    echo json_encode($response, JSON_PRETTY_PRINT);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Internal Server Error: ' . $e->getMessage(),
        'data'    => null
    ], JSON_PRETTY_PRINT);
}
