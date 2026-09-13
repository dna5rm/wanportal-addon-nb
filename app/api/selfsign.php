<?php
/**
 * api/selfsign.php - POST /api/selfsign
 *
 * Mints a self-signed certificate (valid 365 days) from the private key
 * already stored in NetBox. Useful for smoke-testing an endpoint while
 * waiting for the real certificate from the CA. The self-signed
 * certificate is never written back to NetBox - it only appears in the
 * response.
 *
 * Auth: Bearer token in the Authorization header. The token IS the
 * caller's NetBox API token - validated live against NetBox and handed to
 * the wrapper (src/ansible/selfsign_ssl_cert.sh) as NETBOX_TOKEN.
 *
 * Body (JSON):
 *   common_name        required, FQDN, lowercased
 *   owner_group        required, stored with the cert metadata in NetBox
 *   subject_alt_names  optional array of extra SANs
 *
 * The response is the raw script output - JSON or plain text depending
 * on the Accept header.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/utils.php';

header('Content-Type: application/json');

$token = authenticate();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed'], 405);
}

$data = json_decode(file_get_contents('php://input'), true);

$common_name       = strtolower(trim($data['common_name'] ?? ''));
$owner_group       = trim($data['owner_group'] ?? '');
$subject_alt_names = $data['subject_alt_names'] ?? [];

if (empty($common_name)) {
    json_response(['error' => 'common_name is required'], 400);
}

if (empty($owner_group)) {
    json_response(['error' => 'owner_group is required'], 400);
}

$payload = json_encode([
    'common_name'       => $common_name,
    'owner_group'       => $owner_group,
    'subject_alt_names' => $subject_alt_names,
]);

$result = run_script('selfsign_ssl_cert.sh', $payload, $token);

$accept  = $_SERVER['HTTP_ACCEPT'] ?? 'application/json';
$is_json = strpos($accept, 'application/json') !== false || $accept === '*/*';

header('Content-Type: ' . ($is_json ? 'application/json' : 'text/plain'));
echo implode("\n", $result['output']);
exit;
?>
