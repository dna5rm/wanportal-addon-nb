<?php
/**
 * src/utils.php - Shared helper functions
 *
 * The plumbing between the HTTP endpoints and the Ansible wrappers:
 * content negotiation, JSON replies, FQDN resolution, and the
 * run_script() executor that shells out to src/ansible. Authentication
 * lives in src/auth.php, NetBox access in src/netbox/.
 */

/**
 * Output script results based on the Accept header.
 *
 * text/plain          - raw script output, for the frontend console
 * application/json    - structured response for API consumers (also the
 *                       default when no Accept header is sent, and for the
 *                       wildcard Accept value)
 * an explicit text/plain request always wins over JSON.
 */
function script_response($action, $common_name, $result) {
    $accept  = $_SERVER['HTTP_ACCEPT'] ?? 'application/json';
    $is_json = strpos($accept, 'application/json') !== false || $accept === '*/*';

    if (strpos($accept, 'text/plain') !== false) {
        header('Content-Type: text/plain');
        echo implode("\n", $result['output']);
        exit;
    }

    json_response([
        'action'      => $action,
        'common_name' => $common_name,
        'output'      => $result['output'],
        'exit_code'   => $result['exit_code'],
        'success'     => $result['success'],
    ]);
}

/**
 * Output a JSON response with the appropriate headers.
 */
function json_response($data, $http_code = 200) {
    http_response_code($http_code);
    header('Content-Type: application/json');
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Resolve an FQDN to an IP address.
 *
 * Used to fill in the A-record value when a build has to create a new
 * DNS record. Falls back to the loopback address (127.0.0.1) when the
 * name does not resolve yet, so the record can still be created and the
 * CSR issued; the address can be corrected in NetBox afterwards.
 */
function resolve_hostname($fqdn) {
    $ip = gethostbyname($fqdn);

    // gethostbyname() returns the hostname unchanged if it cannot be resolved
    if ($ip === $fqdn) {
        return '127.0.0.1';
    }

    return $ip;
}

/**
 * Execute an Ansible wrapper script with a JSON payload as its single
 * argument.
 *
 * Path resolution: ANSIBLE_PATH is used as-is when absolute, otherwise it
 * is resolved relative to the app root (the directory that holds
 * index.php). The script must exist AND be executable - deploy the
 * src/ansible/*.sh wrappers with 755, because requests fail with 500
 * here otherwise.
 *
 * The script runs with a minimal environment containing only what it
 * needs: VAULT_PASS and NETBOX_URL from config, NETBOX_TOKEN set to the
 * request's Bearer token, the certificate subject values (SSL_CSR_*, see
 * config.php - the playbooks read them back with lookup('env', ...)),
 * HTTP_ACCEPT mirrored so the script can do its own content negotiation,
 * HOME, and PATH with VENV_PATH/bin first so an Ansible virtualenv wins
 * over the system binaries. Payload and script path are escapeshellarg'd;
 * output is collected line by line with stderr merged in, and returned
 * together with the exit code.
 *
 * Returns ['output' => string[], 'exit_code' => int, 'success' => bool].
 */
function run_script($script, $payload, $token = '') {
    $ansible_dir = ANSIBLE_PATH;
    if ($ansible_dir === '' || $ansible_dir[0] !== '/') {
        $ansible_dir = dirname(__DIR__) . '/' . ltrim($ansible_dir, '/');
    }
    $script_path = rtrim($ansible_dir, '/') . '/' . $script;

    if (!file_exists($script_path) || !is_executable($script_path)) {
        json_response(['error' => 'Script not found or not executable: ' . $script_path], 500);
    }

    $env = implode(' ', [
        'VAULT_PASS='   . escapeshellarg(VAULT_PASS),
        'NETBOX_URL='   . escapeshellarg(NETBOX_URL),
        'NETBOX_TOKEN=' . escapeshellarg($token),
        // Certificate subject values (see config.php). defined() guards keep
        // this working with an older config.php that predates the defines.
        'SSL_CSR_COUNTRY='   . escapeshellarg(defined('SSL_CSR_COUNTRY')  ? SSL_CSR_COUNTRY  : 'US'),
        'SSL_CSR_STATE='     . escapeshellarg(defined('SSL_CSR_STATE')    && SSL_CSR_STATE    !== '' ? SSL_CSR_STATE    : 'California'),
        'SSL_CSR_LOCALITY='  . escapeshellarg(defined('SSL_CSR_LOCALITY') && SSL_CSR_LOCALITY !== '' ? SSL_CSR_LOCALITY : 'Example City'),
        'SSL_CSR_ORG='       . escapeshellarg(defined('SSL_CSR_ORG')      ? SSL_CSR_ORG      : 'Example Org'),
        'SSL_CSR_OU='        . escapeshellarg(defined('SSL_CSR_OU')       ? SSL_CSR_OU       : 'IT'),
        'SSL_KEY_SIZE='      . escapeshellarg(defined('SSL_KEY_SIZE')     ? SSL_KEY_SIZE     : '4096'),
        'SSL_VAULT_ID='      . escapeshellarg(defined('SSL_VAULT_ID')     ? SSL_VAULT_ID     : 'netops'),
        'HTTP_ACCEPT='  . escapeshellarg($_SERVER['HTTP_ACCEPT'] ?? 'application/json'),
        'HOME='         . escapeshellarg(getenv('HOME') ?: posix_getpwuid(posix_getuid())['dir']),
        'PATH='         . VENV_PATH . '/bin:/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
    ]);

    $cmd    = $env . ' ' . escapeshellarg($script_path) . ' ' . escapeshellarg($payload) . ' 2>&1';
    $lines  = [];
    $handle = popen($cmd, 'r');

    if ($handle === false) {
        json_response(['error' => 'Failed to execute script: ' . $script], 500);
    }

    while (!feof($handle)) {
        $line = fgets($handle);
        if ($line !== false) {
            $lines[] = rtrim($line);
        }
    }

    $exit_code = pclose($handle);

    return [
        'output'    => $lines,
        'exit_code' => $exit_code,
        'success'   => $exit_code === 0,
    ];
}
?>
