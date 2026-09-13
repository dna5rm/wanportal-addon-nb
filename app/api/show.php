<?php
/**
 * api/show.php - POST /api/show
 *
 * Retrieves an existing SSL certificate for an FQDN. The DNS record is
 * looked up in NetBox first (404 when there is none); its ssl_certificate
 * / ssl_privkey custom fields are then handed to the Ansible wrapper
 * src/ansible/show_ssl_cert.sh, which decrypts the vaulted private key
 * and prints the certificate, key and passphrase.
 *
 * Auth: Bearer token in the Authorization header. The token IS the
 * caller's NetBox API token - validated live against NetBox and reused
 * for the lookup.
 *
 * Body (JSON):
 *   common_name   required, FQDN, lowercased
 *
 * The response is the raw script output - JSON or plain text depending
 * on the Accept header.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/utils.php';
require_once __DIR__ . '/../src/netbox/helpers.php';

$token = authenticate();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed'], 405);
}

$data        = json_decode(file_get_contents('php://input'), true);
$common_name = strtolower(trim($data['common_name'] ?? ''));

if (empty($common_name)) {
    json_response(['error' => 'common_name is required'], 400);
}

// Look up the DNS record in NetBox
$lookup = netbox_get_dns_record($common_name, $token);

if (isset($lookup['error'])) {
    json_response(['error' => $lookup['error']], 404);
}

if ($lookup['record'] === null) {
    json_response(['error' => 'No DNS record found for: ' . $common_name], 404);
}

$record        = $lookup['record'];
$custom_fields = $record['custom_fields'] ?? [];

// Build payload for the script including NetBox data
$payload = json_encode([
    'common_name'     => $common_name,
    'ssl_certificate' => $custom_fields['ssl_certificate'] ?? null,
    'ssl_privkey'     => $custom_fields['ssl_privkey'] ?? null,
]);

$result = run_script('show_ssl_cert.sh', $payload, $token);

$accept  = $_SERVER['HTTP_ACCEPT'] ?? 'application/json';
$is_json = strpos($accept, 'application/json') !== false;

header('Content-Type: ' . ($is_json ? 'application/json' : 'text/plain'));
echo implode("\n", $result['output']);
exit;
?>
