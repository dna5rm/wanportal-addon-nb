<?php
/**
 * src/netbox/client.php
 * Core HTTP client for NetBox API. netbox_request() sends GET/POST/PATCH/
 * DELETE with the config token and returns a success/data/message
 * envelope. A 204 counts as success with no body, and NetBox field errors
 * ({"field": ["message"]} or {"detail": ...}) are flattened into one
 * readable message.
 */

/**
 * Make an authenticated request to the NetBox API.
 *
 * @param string     $method  HTTP method (GET, POST, DELETE, PATCH)
 * @param string     $path    API path, e.g. "/ipam/prefixes/"
 * @param array|null $payload Request body (will be JSON-encoded), or null
 * @param array      $config  Configuration array
 * @return array ['success' => bool, 'data' => mixed, 'message' => string]
 */
function netbox_request(string $method, string $path, ?array $payload, array $config): array {
    $url     = rtrim($config['api']['url'], '/') . $path;
    $headers = [
        'Authorization: Token ' . $config['api']['token'],
        'Content-Type: application/json',
        'Accept: application/json'
    ];

    $curl      = curl_init();
    $curl_opts = [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0
    ];

    switch (strtoupper($method)) {
        case 'GET':
            $curl_opts[CURLOPT_HTTPGET] = true;
            break;

        case 'POST':
            $curl_opts[CURLOPT_POST]       = true;
            $curl_opts[CURLOPT_POSTFIELDS] = $payload ? json_encode($payload) : '{}';
            break;

        case 'PATCH':
            $curl_opts[CURLOPT_CUSTOMREQUEST] = 'PATCH';
            $curl_opts[CURLOPT_POSTFIELDS]    = $payload ? json_encode($payload) : '{}';
            break;

        case 'DELETE':
            $curl_opts[CURLOPT_CUSTOMREQUEST] = 'DELETE';
            if ($payload) {
                $curl_opts[CURLOPT_POSTFIELDS] = json_encode($payload);
            }
            break;
    }

    curl_setopt_array($curl, $curl_opts);

    $response  = curl_exec($curl);
    $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $curl_err  = curl_error($curl);
    curl_close($curl);

    debug_log("$method $url → HTTP $http_code", $config);

    if ($curl_err) {
        debug_log("cURL error: $curl_err", $config);
        return [
            'success' => false,
            'data'    => null,
            'message' => "cURL error: $curl_err"
        ];
    }

    // 204 No Content (successful DELETE) has no body
    if ($http_code === 204) {
        return [
            'success' => true,
            'data'    => null,
            'message' => 'Deleted successfully'
        ];
    }

    $decoded = json_decode($response, true);

    // 2xx = success
    if ($http_code >= 200 && $http_code < 300) {
        return [
            'success' => true,
            'data'    => $decoded,
            'message' => ''
        ];
    }

    // Extract a meaningful error message from NetBox error responses
    // NetBox returns errors as {"field": ["message"]} or {"detail": "message"}
    $error_message = "HTTP $http_code";
    if (is_array($decoded)) {
        if (isset($decoded['detail'])) {
            $error_message = $decoded['detail'];
        } else {
            $messages = [];
            array_walk_recursive($decoded, function($val) use (&$messages) {
                if (is_string($val)) {
                    $messages[] = $val;
                }
            });
            if (!empty($messages)) {
                $error_message = implode('; ', $messages);
            }
        }
    }

    debug_log("NetBox API error on $method $path: $error_message", $config);

    return [
        'success' => false,
        'data'    => $decoded,
        'message' => $error_message
    ];
}
