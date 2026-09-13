<?php
/**
 * src/netbox/helpers.php - Higher level NetBox helper functions
 *
 * Built on top of client.php. Everything here works against the
 * netbox-dns plugin: token validation, paginated zone listing, FQDN ->
 * zone matching (longest zone-name suffix wins), DNS record lookup,
 * create, update, and an upsert that ties those together.
 *
 * Certificates live in the DNS record's custom fields:
 *   ssl_certificate  PEM text of the certificate
 *   ssl_privkey      JSON blob with the vault-encrypted private key and
 *                    its passphrase
 *
 * Every function takes the request's Bearer token - the caller's own
 * NetBox API token - and uses it for each call it makes.
 */

require_once __DIR__ . '/client.php';

/**
 * Validate a NetBox API token by probing the API root with it.
 * Returns true when NetBox answers 200, false otherwise.
 */
function netbox_validate_token($token) {
    $response = netbox_request('GET', '/api/', $token);
    return $response['http_code'] === 200;
}

/**
 * Fetch all DNS zones from NetBox.
 * Returns an array of zone objects.
 */
function netbox_get_zones($token) {
    $zones = [];
    $endpoint = '/api/plugins/netbox-dns/zones/?limit=1000';

    while ($endpoint) {
        $response = netbox_request('GET', $endpoint, $token);
        if ($response['http_code'] !== 200) {
            return ['error' => 'Failed to fetch zones', 'http_code' => $response['http_code']];
        }
        $zones = array_merge($zones, $response['data']['results']);
        // Handle pagination
        $next = $response['data']['next'] ?? null;
        $endpoint = $next ? parse_url($next, PHP_URL_PATH) . '?' . parse_url($next, PHP_URL_QUERY) : null;
    }

    return $zones;
}

/**
 * Match an FQDN to the most specific zone in NetBox.
 * Returns the zone array or null if no match found.
 */
function netbox_match_zone($fqdn, $zones) {
    $best_match = null;
    $best_len   = 0;

    foreach ($zones as $zone) {
        $zone_name = $zone['name'];
        if (substr($fqdn, -strlen('.' . $zone_name)) === '.' . $zone_name && strlen($zone_name) > $best_len) {
            $best_match = $zone;
            $best_len   = strlen($zone_name);
        }
    }

    return $best_match;
}

/**
 * Look up a DNS record in NetBox by FQDN.
 * Returns the record array or null if not found.
 */
function netbox_get_dns_record($fqdn, $token) {
    $zones = netbox_get_zones($token);
    if (isset($zones['error'])) {
        return $zones;
    }

    $zone = netbox_match_zone($fqdn, $zones);
    if (!$zone) {
        return ['error' => 'No matching zone found for: ' . $fqdn];
    }

    $zone_id     = $zone['id'];
    $zone_name   = $zone['name'];
    $record_name = substr($fqdn, 0, -(strlen($zone_name) + 1));

    $response = netbox_request('GET', '/api/plugins/netbox-dns/records/?name=' . urlencode($record_name) . '&zone_id=' . $zone_id, $token);

    if ($response['http_code'] !== 200) {
        return ['error' => 'Failed to fetch DNS record', 'http_code' => $response['http_code']];
    }

    $results = $response['data']['results'] ?? [];

    return [
        'record'      => !empty($results) ? $results[0] : null,
        'record_name' => $record_name,
        'zone'        => $zone,
    ];
}

/**
 * Create a new DNS record in NetBox.
 * Returns the created record or an error array.
 */
function netbox_create_dns_record($fqdn, $token, $ip_address, $custom_fields = []) {
    $zones = netbox_get_zones($token);
    if (isset($zones['error'])) {
        return $zones;
    }

    $zone = netbox_match_zone($fqdn, $zones);
    if (!$zone) {
        return ['error' => 'No matching zone found for: ' . $fqdn];
    }

    $record_name = substr($fqdn, 0, -(strlen($zone['name']) + 1));

    $payload = [
        'name'          => $record_name,
        'zone'          => $zone['id'],
        'type'          => 'A',
        'value'         => $ip_address,
        'status'        => 'active',
        'custom_fields' => $custom_fields,
    ];

    $response = netbox_request('POST', '/api/plugins/netbox-dns/records/', $token, $payload);

    if ($response['http_code'] !== 201) {
        return ['error' => 'Failed to create DNS record', 'http_code' => $response['http_code'], 'data' => $response['data']];
    }

    return $response['data'];
}

/**
 * Update custom fields on an existing DNS record in NetBox.
 * Returns the updated record or an error array.
 */
function netbox_update_dns_record($record_id, $token, $custom_fields = []) {
    $payload = ['custom_fields' => $custom_fields];

    $response = netbox_request('PATCH', '/api/plugins/netbox-dns/records/' . $record_id . '/', $token, $payload);

    if ($response['http_code'] !== 200) {
        return ['error' => 'Failed to update DNS record', 'http_code' => $response['http_code'], 'data' => $response['data']];
    }

    return $response['data'];
}

/**
 * Upsert a DNS record in NetBox.
 * Creates the record if it does not exist, updates custom fields if it does.
 * Returns the created or updated record, or an error array.
 */
function netbox_upsert_dns_record($fqdn, $token, $ip_address, $custom_fields = []) {
    $lookup = netbox_get_dns_record($fqdn, $token);

    if (isset($lookup['error'])) {
        return $lookup;
    }

    if ($lookup['record'] === null) {
        // Record does not exist, create it
        return netbox_create_dns_record($fqdn, $token, $ip_address, $custom_fields);
    }

    // Record exists, update custom fields
    return netbox_update_dns_record($lookup['record']['id'], $token, $custom_fields);
}
?>
