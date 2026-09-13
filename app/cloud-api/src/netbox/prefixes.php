<?php
/**
 * src/netbox/prefixes.php
 * NetBox prefix lookup and query functions. Tag creation for accounts,
 * parent pool discovery by tag (automation + environment + region), free
 * block listing in the legacy blockAddr/blockSize/blockStatus shape, a
 * next-fit prefix suggestion via /available-prefixes/, and lookup of a
 * prefix by its description (the reservation container name).
 */

/**
 * Get or create a NetBox tag by name.
 *
 * @param string $name   Tag name (will be uppercased)
 * @param array  $config Configuration array
 * @return array|null    NetBox tag object or null on failure
 */
function get_or_create_tag(string $name, array $config): ?array {
    $tag_name = strtoupper($name);
    $tag_slug = strtolower($name);

    // Check if tag already exists
    $result = netbox_request(
        'GET',
        '/extras/tags/?name=' . urlencode($tag_name),
        null,
        $config
    );

    if ($result['success'] && !empty($result['data']['results'])) {
        foreach ($result['data']['results'] as $tag) {
            if ($tag['name'] === $tag_name) {
                debug_log("Tag '$tag_name' already exists", $config);
                return $tag;
            }
        }
    }

    // Create the tag if it does not exist
    $result = netbox_request(
        'POST',
        '/extras/tags/',
        [
            'name'  => $tag_name,
            'slug'  => $tag_slug,
            'color' => 'ffffff',
            'description' => 'Account tag for ' . $tag_name
        ],
        $config
    );

    if (!$result['success']) {
        debug_log("Failed to create tag '$tag_name': " . $result['message'], $config);
        return null;
    }

    debug_log("Created tag '$tag_name'", $config);
    return $result['data'];
}

/**
 * Find the parent prefix for a given environment + region using tags.
 * Expects a prefix tagged with AUTOMATION + environment + region
 * that serves as the allocatable pool (no account-specific description).
 *
 * @param string $environment Environment name (e.g. aws-development)
 * @param string $region      Region name (e.g. eu-west-1)
 * @param array  $config      Configuration array
 * @return array|null NetBox prefix object or null
 */
function get_parent_prefix(string $environment, string $region, array $config) {
    $env_tag    = strtolower($environment);
    $region_tag = strtolower($region);

    $result = netbox_request(
        'GET',
        '/ipam/prefixes/?tag=automation'
            . '&tag=' . urlencode($env_tag)
            . '&tag=' . urlencode($region_tag),
        null,
        $config
    );

    if (!$result['success'] || empty($result['data']['results'])) {
        debug_log("No parent prefix found for $environment / $region", $config);
        return null;
    }

    // Return all matching prefixes to support multiple ranges per environment/region
    return array_values($result['data']['results']);
}

/**
 * Get all child prefixes within a parent prefix, formatted to match the
 * legacy blockAddr / blockSize / blockStatus structure used by the API responses.
 *
 * Prefixes that have an account description are marked "Allocated";
 * everything else within the parent is marked "Free".
 *
 * @param array $parent_prefix NetBox prefix object (must contain 'prefix' key)
 * @param array $config        Configuration array
 * @return array|false Array of formatted blocks, or false on failure
 */
function get_available_prefixes(array $parent_prefix, array $config) {
    // get_parent_prefix() returns a single prefix, but we need to handle
    // multiple parent prefixes for the same environment/region in the future.
    // Accept either a single prefix object or an array of prefix objects.
    $parents = isset($parent_prefix['id']) ? [$parent_prefix] : $parent_prefix;

    $formatted = [];
    foreach ($parents as $parent) {
        $result = netbox_request(
            'GET',
            '/ipam/prefixes/' . $parent['id'] . '/available-prefixes/',
            null,
            $config
        );

        if (!$result['success']) {
            debug_log("Failed to retrieve available prefixes for {$parent['prefix']}", $config);
            continue;
        }

        foreach ($result['data'] as $p) {
            [$addr, $size] = explode('/', $p['prefix']);
            $formatted[] = [
                'blockAddr'   => $addr,
                'blockSize'   => $size,
                'blockStatus' => 'Free',
                'blockName'   => $p['prefix']
            ];
        }
    }

    return $formatted;
}

/**
 * Ask NetBox to suggest the next available prefix of a given size
 * within a parent prefix (uses the /available-prefixes/ endpoint).
 *
 * @param int   $parent_id NetBox ID of the parent prefix
 * @param int   $size      Desired prefix length (e.g. 24)
 * @param array $config    Configuration array
 * @return string|null     CIDR string (e.g. "198.51.100.0/24") or null if none available
 */
function netbox_get_available_prefix(int $parent_id, int $size, array $config): ?string {
    // GET available prefixes - does not create anything
    $result = netbox_request(
        'GET',
        '/ipam/prefixes/' . $parent_id . '/available-prefixes/',
        null,
        $config
    );

    if (!$result['success'] || empty($result['data'])) {
        debug_log("available-prefixes request failed for parent ID $parent_id", $config);
        return null;
    }

    // Find the first available prefix that fits the requested size
    foreach ($result['data'] as $prefix) {
        [$addr, $prefix_size] = explode('/', $prefix['prefix']);
        if ((int)$prefix_size <= $size) {
            // Return the address with the requested size
            return $addr . '/' . $size;
        }
    }

    debug_log("No available /$size prefix under parent ID $parent_id", $config);
    return null;
}

/**
 * Look up a prefix in NetBox by its description field.
 * Used to find account reservations by their container name.
 *
 * @param string $description Description to search for (e.g. "example01-eu-west-1")
 * @param array  $config      Configuration array
 * @return array|null NetBox prefix object or null if not found
 */
function get_prefix_by_description(string $description, array $config): ?array {
    if (empty($description)) {
        debug_log("Description cannot be empty", $config);
        return null;
    }

    $result = netbox_request(
        'GET',
        '/ipam/prefixes/?description=' . urlencode($description),
        null,
        $config
    );

    if (!$result['success'] || empty($result['data']['results'])) {
        debug_log("No prefix found with description '$description'", $config);
        return null;
    }

    // Return exact match only
    foreach ($result['data']['results'] as $prefix) {
        if (($prefix['description'] ?? '') === $description) {
            return $prefix;
        }
    }

    return null;
}
