<?php
/**
 * api/build.php - POST /api/build
 *
 * Builds a new SSL certificate: generates (or reuses) a private key,
 * creates the DNS record in NetBox when it does not exist yet, and
 * produces a CSR to submit to a CA. The heavy lifting is delegated to the
 * Ansible wrapper src/ansible/build_ssl_cert.sh via run_script().
 *
 * Auth: Bearer token in the Authorization header. The token IS the
 * caller's NetBox API token - it is validated live against NetBox and
 * then handed to the wrapper as NETBOX_TOKEN.
 *
 * Body (JSON):
 *   common_name        required, FQDN, lowercased
 *   owner_group        required, stored with the cert metadata in NetBox
 *   reference_number   optional
 *   subject_alt_names  optional array of extra SANs
 *
 * Builds are serialized per common_name with a lock file: a second build
 * for the same FQDN while one is in flight gets 409. The response is the
 * raw script output - JSON or plain text depending on the Accept header.
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
$reference_number  = trim($data['reference_number'] ?? '');
$subject_alt_names = $data['subject_alt_names'] ?? [];

if (empty($common_name)) {
    json_response(['error' => 'common_name is required'], 400);
}

if (empty($owner_group)) {
    json_response(['error' => 'owner_group is required'], 400);
}

// Lock per common_name to prevent concurrent builds
$lock_file = sys_get_temp_dir() . '/certmgr_' . md5($common_name) . '.lock';
$lock      = fopen($lock_file, 'w');

if (!flock($lock, LOCK_EX | LOCK_NB)) {
    fclose($lock);
    json_response(['error' => 'A build is already in progress for: ' . $common_name], 409);
}

// Build payload for the script
$payload = json_encode([
    'common_name'       => $common_name,
    'owner_group'       => $owner_group,
    'reference_number'  => $reference_number ?: null,
    'subject_alt_names' => $subject_alt_names,
]);

$result = run_script('build_ssl_cert.sh', $payload, $token);

// Release lock
flock($lock, LOCK_UN);
fclose($lock);

$accept  = $_SERVER['HTTP_ACCEPT'] ?? 'application/json';
$is_json = strpos($accept, 'application/json') !== false || $accept === '*/*';

header('Content-Type: ' . ($is_json ? 'application/json' : 'text/plain'));
echo implode("\n", $result['output']);
exit;
?>
