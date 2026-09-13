<?php
/**
 * src/netbox/reservation.php
 * Reservation create, get and delete. Create validates region,
 * environment and size against the allowlists, returns the existing
 * reservation when a prefix with the container description already exists,
 * otherwise asks NetBox for a sub-prefix of the requested size, inherits
 * VRF, tenant, role and scope from the parent pool, tags it with the
 * environment, region and account, and records the account in description
 * and comments.
 */

/**
 * Create a reservation in NetBox.
 * Finds the best available free sub-prefix under the parent and allocates it.
 *
 * @param array $params Request parameters
 * @param array $config Configuration array
 * @return array Response array
 */
function create_reservation(array $params, array $config): array {
    $required   = ['region', 'environment', 'account', 'account_id', 'size'];
    $validation = validate_params($params, $required);
    if (!$validation['is_valid']) {
        return format_response(
            false, null,
            'Missing required parameters: ' . implode(', ', $validation['missing']),
            400
        );
    }

    $valid_regions = ['eu-west-1', 'eu-west-2', 'us-east-1', 'us-west-2'];
    if (!in_array($params['region'], $valid_regions)) {
        return format_response(
            false, null,
            'Invalid region. Must be one of: ' . implode(', ', $valid_regions),
            400
        );
    }

    $valid_environments = ['aws-production', 'aws-development'];
    if (!in_array($params['environment'], $valid_environments)) {
        return format_response(
            false, null,
            'Invalid environment. Must be one of: ' . implode(', ', $valid_environments),
            400
        );
    }

    $valid_sizes = ['21', '22', '23', '24'];
    if (!in_array((string)$params['size'], $valid_sizes)) {
        return format_response(
            false, null,
            'Invalid size. Must be one of: ' . implode(', ', $valid_sizes),
            400
        );
    }

    if (!is_numeric($params['account_id']) || $params['account_id'] != (int)$params['account_id']) {
        return format_response(false, null, 'account_id must be an integer', 400);
    }

    $container_name = format_container_name($params['account'], $params['region']);

    // Check if reservation already exists
    $existing = get_prefix_by_description($container_name, $config);
    if ($existing) {
        [$blockAddr, $blockSize] = explode('/', $existing['prefix']);
        return format_response(
            true,
            [
                'account'      => $params['account'],
                'account_id'   => (int)$params['account_id'],
                'environment'  => $params['environment'],
                'region'       => $params['region'],
                'container'    => $container_name,
                'block_result' => [[
                    'blockAddr' => $blockAddr,
                    'blockSize' => $blockSize
                ]]
            ],
            'Retrieved existing reservation',
            200
        );
    }

    // Find parent prefix
    $parent_prefix = get_parent_prefix($params['environment'], $params['region'], $config);
    if (!$parent_prefix) {
        return format_response(
            false, null,
            "No parent prefix found for environment '{$params['environment']}' and region '{$params['region']}'",
            404
        );
    }

    // Find an available sub-prefix of the requested size
    $available = netbox_get_available_prefix($parent_prefix[0]['id'], (int)$params['size'], $config);
    if (!$available) {
        return format_response(
            false, null,
            "No available /{$params['size']} prefix found under {$parent_prefix[0]['prefix']}",
            404
        );
    }

    // Inherit VRF, tenant, scope and role from parent prefix
    $parent     = $parent_prefix[0];
    $vrf        = !empty($parent['vrf']['id'])    ? ['id' => $parent['vrf']['id']]    : null;
    $tenant     = !empty($parent['tenant']['id']) ? ['id' => $parent['tenant']['id']] : null;
    $role       = !empty($parent['role']['id'])   ? ['id' => $parent['role']['id']]   : null;
    $scope_type = $parent['scope_type'] ?? null;
    $scope_id   = $parent['scope_id']   ?? null;
    $env_value  = $params['environment'] === 'aws-production' ? 'prod' : 'dev';

    // Get or create account tag
    $account_tag = get_or_create_tag($params['account'], $config);

    $tags = [
        ['name' => strtoupper($params['environment'])],
        ['name' => strtoupper($params['region'])]
    ];

    // Add account tag if successfully retrieved or created
    if ($account_tag) {
        $tags[] = ['name' => $account_tag['name']];
    }

    // Build payload after all parent info is gathered
    $payload = [
        'prefix'        => $available,
        'description'   => $container_name,
        'status'        => 'active',
        'tags'          => $tags,
        'vrf'           => $vrf,
        'tenant'        => $tenant,
        'role'          => $role,
        'scope_type'    => $scope_type,
        'scope_id'      => $scope_id,
        'comments'      => sprintf(
            'Reserved for account %s (%s) in %s',
            $params['account'],
            $params['account_id'],
            $params['region']
        ),
        'custom_fields' => [
            'firewall_zone' => 'black',
            'environment'   => $env_value
        ]
    ];

    // Remove null values
    $payload = array_filter($payload, fn($v) => $v !== null);

    $result = netbox_request('POST', '/ipam/prefixes/', $payload, $config);
    if (!$result['success']) {
        return format_response(
            false, null,
            'Failed to create prefix in NetBox: ' . $result['message'],
            500
        );
    }

    $created = $result['data'];
    [$blockAddr, $blockSize] = explode('/', $created['prefix']);

    return format_response(
        true,
        [
            'account'      => $params['account'],
            'account_id'   => (int)$params['account_id'],
            'environment'  => $params['environment'],
            'region'       => $params['region'],
            'container'    => $container_name,
            'block_result' => [[
                'blockAddr' => $blockAddr,
                'blockSize' => $blockSize
            ]]
        ],
        'Reserved successfully',
        201
    );
}

/**
 * Get reservation details from NetBox.
 *
 * @param array $data   Request data containing account and region
 * @param array $config Configuration array
 * @return array Response array with reservation details
 */
function get_reservation(array $data, array $config): array {
    $account = $data['account'] ?? '';
    $region  = $data['region']  ?? '';

    if (empty($account) || empty($region)) {
        return format_response(false, null, 'Account and region are required', 400);
    }

    $container_name = format_container_name($account, $region);
    $prefix         = get_prefix_by_description($container_name, $config);

    if (!$prefix) {
        return format_response(
            false, null,
            "Reservation '$container_name' not found",
            404
        );
    }

    [$blockAddr, $blockSize] = explode('/', $prefix['prefix']);

    return format_response(
        true,
        [
            'account'           => $account,
            'region'            => $region,
            'container'         => $container_name,
            'container_details' => $prefix,
            'block_result'      => [[
                'blockAddr' => $blockAddr,
                'blockSize' => $blockSize
            ]]
        ],
        'Reservation details retrieved successfully',
        200
    );
}

/**
 * Delete a reservation (prefix) from NetBox.
 *
 * @param array $data   Request data containing account and region
 * @param array $config Configuration array
 * @return array Response array with deletion status
 */
function delete_reservation(array $data, array $config): array {
    $account = $data['account'] ?? '';
    $region  = $data['region']  ?? '';

    if (empty($account) || empty($region)) {
        return format_response(false, null, 'Account and region are required', 400);
    }

    $container_name = format_container_name($account, $region);
    $prefix         = get_prefix_by_description($container_name, $config);

    if (!$prefix) {
        return format_response(
            false, null,
            "Reservation '$container_name' not found",
            404
        );
    }

    $result = netbox_request('DELETE', '/ipam/prefixes/' . $prefix['id'] . '/', null, $config);

    // NetBox returns 204 No Content on successful DELETE
    if (!$result['success']) {
        return format_response(
            false, null,
            "Failed to delete reservation '$container_name'",
            500
        );
    }

    return format_response(
        true, null,
        "Successfully deleted reservation for account '$account' in region '$region'",
        200
    );
}
