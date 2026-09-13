<?php
/**
 * src/netbox/client.php - Low level NetBox API curl wrapper
 *
 * One function, netbox_request(), that talks JSON to the NetBox REST API
 * for GET / POST / PATCH / PUT / DELETE. Every call authenticates with
 * the token handed in by the caller - which is always the request's own
 * Bearer token (the user's NetBox API token), never a server-wide
 * credential.
 *
 * Returns an array:
 *   http_code   HTTP status (0 when the transfer itself failed)
 *   error       curl error string, or null
 *   data        decoded JSON body, or null
 *
 * Note: TLS peer verification is disabled here (CURLOPT_SSL_VERIFYPEER /
 * CURLOPT_SSL_VERIFYHOST). Tighten this if your NetBox serves a
 * certificate your clients trust.
 */

require_once __DIR__ . '/../../config.php';

/**
 * Perform a NetBox API request.
 *
 * $endpoint is the API path, e.g. '/api/plugins/netbox-dns/records/'.
 * $data is encoded to JSON for POST / PATCH / PUT bodies. Unsupported
 * methods return an error array instead of calling out.
 */

function netbox_request($method, $endpoint, $token, $data = null) {
    $url = rtrim(NETBOX_URL, '/') . $endpoint;

    $headers = [
        'Authorization: Token ' . $token,
        'Content-Type: application/json',
        'Accept: application/json',
    ];

    $curl_opts = [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
    ];

    switch (strtoupper($method)) {
        case 'GET':
            break;
        case 'POST':
            $curl_opts[CURLOPT_POST]       = true;
            $curl_opts[CURLOPT_POSTFIELDS] = json_encode($data);
            break;
        case 'PATCH':
            $curl_opts[CURLOPT_CUSTOMREQUEST] = 'PATCH';
            $curl_opts[CURLOPT_POSTFIELDS]    = json_encode($data);
            break;
        case 'PUT':
            $curl_opts[CURLOPT_CUSTOMREQUEST] = 'PUT';
            $curl_opts[CURLOPT_POSTFIELDS]    = json_encode($data);
            break;
        case 'DELETE':
            $curl_opts[CURLOPT_CUSTOMREQUEST] = 'DELETE';
            break;
        default:
            return ['error' => 'Unsupported HTTP method: ' . $method];
    }

    $ch = curl_init();
    curl_setopt_array($ch, $curl_opts);

    $response  = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    if ($curl_error) {
        return [
            'http_code' => 0,
            'error'     => $curl_error,
            'data'      => null,
        ];
    }

    return [
        'http_code' => $http_code,
        'error'     => null,
        'data'      => json_decode($response, true),
    ];
}
?>
