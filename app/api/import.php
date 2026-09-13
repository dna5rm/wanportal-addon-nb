<?php
/**
 * api/import.php - POST /api/import
 *
 * Stores a CA-signed certificate in NetBox. The certificate PEM is passed
 * to the Ansible wrapper src/ansible/import_ssl_cert.sh, which validates
 * it against the private key already on record (the certificate CN and
 * public key must match) before writing it into the DNS record's custom
 * fields.
 *
 * Auth: Bearer token in the Authorization header. The token IS the
 * caller's NetBox API token - validated live against NetBox and handed to
 * the wrapper as NETBOX_TOKEN.
 *
 * Body (JSON):
 *   common_name   required, FQDN, lowercased
 *   certificate   required, PEM text of the signed certificate
 *
 * JSON consumers get a compact summary (action, common_name, exit_code,
 * success); text/plain consumers get the raw script output.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/utils.php';
require_once __DIR__ . '/../src/netbox/helpers.php';

header('Content-Type: application/json');

$token = authenticate();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed'], 405);
}

$data        = json_decode(file_get_contents('php://input'), true);
$common_name = strtolower(trim($data['common_name'] ?? ''));
$certificate = trim($data['certificate'] ?? '');

if (empty($common_name)) {
    json_response(['error' => 'common_name is required'], 400);
}

if (empty($certificate)) {
    json_response(['error' => 'certificate is required'], 400);
}

$payload = json_encode([
    'common_name' => $common_name,
    'certificate' => $certificate,
]);

$result = run_script('import_ssl_cert.sh', $payload, $token);

$accept  = $_SERVER['HTTP_ACCEPT'] ?? 'application/json';
$is_json = strpos($accept, 'application/json') !== false || $accept === '*/*';

if ($is_json) {
    json_response([
        'action'      => 'import',
        'common_name' => $common_name,
        'exit_code'   => $result['exit_code'],
        'success'     => $result['success'],
    ]);
}

header('Content-Type: text/plain');
echo implode("\n", $result['output']);
exit;
?>
