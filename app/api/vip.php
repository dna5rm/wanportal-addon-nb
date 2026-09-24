<?php
/**
 * api/vip.php - VIP builder API (SPEC-vipbuilder.md, section "API")
 *
 * One file, dispatched on method plus ?action=. The browser never talks
 * to NetBox directly: every page action is an API call here, and other
 * teams call the same routes with the same Bearer token. No session
 * cookie, no GUI-only path.
 *
 * Auth: the Bearer token in the Authorization header IS the caller's
 * NetBox API token (src/auth.php strips the scheme and validates it live
 * against NetBox). Every NetBox read/write goes through netbox_request(),
 * which builds its own Authorization header - this file never writes one.
 *
 * GET actions:
 *   action=fqdn&fqdn=<name>       DNS lookup only. 200 unique, 404
 *                                 missing, 409 ambiguous (+count). The
 *                                 200 body adds address (bare) and
 *                                 address_id from the record's
 *                                 vip_address link - '' / null when the
 *                                 link is unset or its IP GET failed.
 *   action=address&address=<ip>   IP lookup only. Same three statuses;
 *                                 when the IP exists the response carries
 *                                 vip_build (the stored build as one
 *                                 compact JSON line, or empty), vip_fqdn
 *                                 (the hostname behind the vip_ssl link,
 *                                 empty when unresolvable), and the IP's
 *                                 role and status as {value,label}
 *                                 objects (null when unset). Existence
 *                                 alone drives the 200/404/409 split: a
 *                                 reserved VIP still answers 200 so the
 *                                 page can explain why the box stays
 *                                 unchecked.
 *   action=load&fqdn=<name>       Resolve the unique DNS name, then the
 *                                 linked IP: the record's vip_address
 *                                 link when set (that IPAM object loaded
 *                                 by id - a failed load is an error, not
 *                                 a fallback), else the A/AAAA value.
 *                                 200 with fqdn, address, id, dns_id,
 *                                 vip_build (same compact line).
 *                                 404 if either side
 *                                 is missing, 409 if either is ambiguous.
 *   action=list                   IP addresses whose vip_build custom
 *                                 field is non-empty (stored object or
 *                                 legacy string; null, {}, '', and
 *                                 missing are empty). 200
 *                                 {"vips":[{id,address,fqdn,vip_build}]}.
 *                                 No secrets.
 *   action=build&fqdn=|&address=  The playbook fetch. 200 body is the
 *                                 stored object itself ({"build":{...}}),
 *                                 raw compact JSON, Content-Type
 *                                 application/json, no wrapper. 404 when
 *                                 the IP exists but vip_build is empty,
 *                                 409 when the lookup is ambiguous.
 *                                 load stays the metadata call; build
 *                                 is the document.
 *
 * POST actions, body {"build":{...}}:
 *   action=validate               Run every save check, write nothing.
 *                                 200 {"ok":true,"vip_build":"<line>"}
 *                                 or 400 {"error":...}.
 *   action=save                   Same checks, then PATCH the custom
 *                                 fields: on the IP, vip_build (the
 *                                 {"build":...} object itself) and
 *                                 vip_ssl (the netbox-dns record id, or
 *                                 null); on the DNS record, vip_address
 *                                 (the IP address id). 200
 *                                 {"ok":true,"id","dns_id",
 *                                 "vip_build":"<stored line>"}. A failed
 *                                 link write answers 500 naming the
 *                                 object ("failed": "ip_address" or
 *                                 "dns_record"); ok is never reported
 *                                 when a link write fails. Nothing is
 *                                 created: the IP address and the DNS
 *                                 record must already exist. When the
 *                                 virtuals are named after the VIP
 *                                 address (no SSL Common Name), the DNS
 *                                 record is not touched: vip_ssl is
 *                                 stored null and dns_id is null.
 *
 * A missing or unknown action is 400.
 *
 * Lookups: netbox-dns materializes Record.fqdn as an indexed field that
 * is spelled with a trailing root dot, so the exact-name DNS query is
 * records/?fqdn=<fqdn>. (tried again without the dot as a fallback for
 * other plugin versions); the relative label alone would never match an
 * FQDN query. The IP query is ipam/ip-addresses/?address=<exact>, which
 * NetBox matches on the host part, so a bare address and the stored
 * address-with-mask both hit. Both use limit=0 so the full result set is
 * what gets counted for the unique/missing/ambiguous decision.
 *
 * Storage: vip_build is a JSON-object custom field holding the build
 * object under its "build" wrapper - the object itself, not a string.
 * Reads accept the legacy form too (the same document stored as one
 * compact line), so a half-migrated record still loads; page-facing
 * responses always carry it as one compact line (JSON_UNESCAPED_SLASHES,
 * so profile names like /Common/tcp stay readable). The links are
 * object-type custom fields: vip_ssl on the IP points at the netbox-dns
 * record, vip_address on the record points at the IP address - each
 * stores the linked object's id, and each reads back as a bare id or a
 * nested object carrying id. The checks reject irule anywhere, any
 * irules besides the port-80 SSL-redirect list holding exactly the
 * configured redirect irule (that listener must also include the
 * configured HTTP profile and must not carry a clientssl profile),
 * analytics profiles, any monitor besides the two configured stock
 * monitors, a default_persistence_profile besides the two configured
 * persistence profiles, and cookie persistence on a listener without
 * the configured HTTP profile (source persistence needs no HTTP). The
 * listener name doubles as the SSL Common Name and is optional: with no
 * clientssl profile anywhere it may be the VIP address itself, which
 * skips netbox-dns entirely, while a clientssl profile requires the
 * name to be a hostname that exists in netbox-dns. The names come from
 * vip_profile_map() in src/vip_profiles.php, overridable per site;
 * shipped defaults are the builtin Common objects.
 *
 * Public tree: placeholders use example.com and 192.0.2.0/24 only.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../src/vip_profiles.php';
require_once __DIR__ . '/../src/auth.php';
require_once __DIR__ . '/../src/utils.php';
require_once __DIR__ . '/../src/netbox/helpers.php';

/**
 * Normalize a hostname for lookup and for the vip_fqdn response key:
 * trimmed, lowercased, without any trailing root dot.
 */
function vip_normalize_fqdn($raw) {
    return rtrim(strtolower(trim((string)$raw)), '.');
}

/**
 * Normalize an address for lookup (the vip_address link stores the IP
 * id, not this string): the bare host part, without any mask.
 */
function vip_normalize_address($raw) {
    $address = trim((string)$raw);

    if ($address === '') {
        return '';
    }

    return explode('/', $address)[0];
}

/**
 * Normalize a NetBox role or status object to {value, label}, or null
 * when it is absent or carries no usable value. The API sends
 * {"value":"vip","label":"VIP"} for roles and
 * {"value":"active","label":"Active"} for statuses; an IP address
 * without a role answers role: null.
 */
function vip_role_status($raw) {
    if (!is_array($raw)) {
        return null;
    }

    $value = $raw['value'] ?? null;

    if (!is_string($value) || trim($value) === '') {
        return null;
    }

    $label = $raw['label'] ?? null;

    return [
        'value' => trim($value),
        'label' => (is_string($label) && trim($label) !== '') ? trim($label) : trim($value),
    ];
}

/**
 * Look up netbox-dns records by exact FQDN.
 *
 * Returns ['state' => 'ok'|'missing'|'error', 'records' => array,
 * 'count' => int, 'error' => string|null]. 'ok' requires at least one
 * hit; the caller decides what more than one means (ambiguous).
 */
function vip_dns_lookup($fqdn, $token) {
    // The plugin stores the materialized fqdn with a trailing root dot;
    // try that spelling first, then the bare one.
    foreach ([$fqdn . '.', $fqdn] as $candidate) {
        $response = netbox_request(
            'GET',
            '/api/plugins/netbox-dns/records/?fqdn=' . urlencode($candidate) . '&limit=0',
            $token
        );

        if ($response['http_code'] !== 200) {
            return [
                'state'   => 'error',
                'error'   => 'DNS record lookup failed (HTTP ' . $response['http_code'] . ')',
                'records' => [],
                'count'   => 0,
            ];
        }

        $results = $response['data']['results'] ?? [];

        if (!is_array($results)) {
            $results = [];
        }

        if (count($results) > 0) {
            return [
                'state'   => count($results) > 1 ? 'ambiguous' : 'ok',
                'records' => $results,
                'count'   => count($results),
                'error'   => null,
            ];
        }
    }

    return ['state' => 'missing', 'records' => [], 'count' => 0, 'error' => null];
}

/**
 * Look up IP addresses by exact address. NetBox matches the host part,
 * so the bare address is the right query form.
 */
function vip_ip_lookup($address, $token) {
    $response = netbox_request(
        'GET',
        '/api/ipam/ip-addresses/?address=' . urlencode($address) . '&limit=0',
        $token
    );

    if ($response['http_code'] !== 200) {
        return [
            'state'   => 'error',
            'error'   => 'IP address lookup failed (HTTP ' . $response['http_code'] . ')',
            'records' => [],
            'count'   => 0,
        ];
    }

    $results = $response['data']['results'] ?? [];

    if (!is_array($results)) {
        $results = [];
    }

    if (count($results) === 0) {
        return ['state' => 'missing', 'records' => [], 'count' => 0, 'error' => null];
    }

    return [
        'state'   => count($results) > 1 ? 'ambiguous' : 'ok',
        'records' => $results,
        'count'   => count($results),
        'error'   => null,
    ];
}

/**
 * Emit the 404 / 409 / 500 answer for a lookup that did not come back
 * with a usable unique hit, and exit. 409 carries the record count.
 */
function vip_lookup_fail($lookup, $label, $value) {
    if ($lookup['state'] === 'error') {
        json_response(['error' => $lookup['error']], 500);
    }

    if ($lookup['state'] === 'missing') {
        json_response(['error' => $label . ' not found: ' . $value], 404);
    }

    json_response(
        ['error' => $label . ' is ambiguous: ' . $value, 'count' => $lookup['count']],
        409
    );
}

/**
 * The object stored as the vip_build custom field: the build object
 * under its "build" wrapper.
 */
function vip_build_object($build) {
    return ['build' => $build];
}

/**
 * The one-line compact JSON string that mirrors the stored vip_build
 * object - what the page and action=build see. Null when the object
 * cannot be encoded (invalid UTF-8, deep recursion).
 */
function vip_build_line($build) {
    $line = json_encode(vip_build_object($build), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    return $line === false ? null : $line;
}

/**
 * The stored vip_build value from an IP object's custom fields, whatever
 * its shape: the {"build":...} object (current storage), the same
 * document as one compact string (legacy storage), or null when empty -
 * null, '', {}, or missing.
 */
function vip_build_stored($custom_fields) {
    $raw = is_array($custom_fields) ? ($custom_fields['vip_build'] ?? null) : null;

    if (is_array($raw)) {
        return $raw === [] ? null : $raw;
    }

    if (is_string($raw)) {
        $raw = trim($raw);

        return $raw === '' ? null : $raw;
    }

    return null;
}

/**
 * The compact one-line JSON string for a stored vip_build value: legacy
 * string storage passes through unchanged, object storage re-encodes
 * compactly. Null when the value is empty or cannot be encoded.
 */
function vip_build_compact($stored) {
    if (is_array($stored)) {
        $line = json_encode($stored, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $line === false ? null : $line;
    }

    if (is_string($stored)) {
        return $stored === '' ? null : $stored;
    }

    return null;
}

/**
 * The linked object's id from an object-type custom field value: a bare
 * integer id or a nested object carrying id. Null when unset or
 * unusable.
 */
function vip_object_id($raw) {
    if (is_int($raw)) {
        return $raw;
    }

    if (is_array($raw)) {
        $id = $raw['id'] ?? null;

        if (is_int($id)) {
            return $id;
        }

        if (is_string($id) && preg_match('/^\d+$/', trim($id))) {
            return (int)trim($id);
        }
    }

    return null;
}

/**
 * The first usable hostname field of a netbox-dns record object: fqdn,
 * then display. '' when neither is a non-empty string.
 */
function vip_record_hostname($record) {
    if (!is_array($record)) {
        return '';
    }

    foreach (['fqdn', 'display'] as $key) {
        $value = $record[$key] ?? null;

        if (is_string($value) && trim($value) !== '') {
            return vip_normalize_fqdn($value);
        }
    }

    return '';
}

/**
 * The hostname string behind a vip_ssl value, for the vip_fqdn response
 * key. The nested netbox-dns record carries it when the API serializes
 * the object with its fields (fqdn, then display); otherwise one lookup
 * of the record by id recovers it, when $allow_lookup permits. '' when
 * the field is unset, unresolvable, or the lookup fails - this link is
 * response sugar, never a hard dependency.
 */
function vip_ssl_hostname($raw, $token, $allow_lookup = true) {
    $hostname = vip_record_hostname($raw);

    if ($hostname !== '' || !$allow_lookup) {
        return $hostname;
    }

    $id = vip_object_id($raw);

    if ($id === null) {
        return '';
    }

    $response = netbox_request(
        'GET',
        '/api/plugins/netbox-dns/records/' . $id . '/',
        $token
    );

    if ($response['http_code'] !== 200) {
        return '';
    }

    return vip_record_hostname($response['data'] ?? null);
}

/**
 * The IP address linked on a netbox-dns record's vip_address custom
 * field. vip_address is an object-type custom field pointing at
 * ipam.ipaddress: writers PATCH the integer id, readers get a bare id or
 * a nested object carrying id plus the address/display fields. Returns:
 *
 *   ['state' => 'unset']                          - the field has no link
 *   ['state' => 'ok', 'id' => int,
 *    'address' => bare address string,
 *    'ip' => fetched object or null]              - resolved link ('ip'
 *                                                 is set only when this
 *                                                 helper did the GET)
 *   ['state' => 'error', 'http_code' => int,
 *    'error' => string]                           - the id-only GET failed
 *
 * The bare address prefers the address/display the nested object already
 * carries (vip_normalize_address strips the mask); an id-only link is
 * resolved with one GET of the linked ipam object, which the caller may
 * reuse. action=fqdn treats 'error' as unset - a found DNS record must
 * never turn into a 500 - while action=load treats it as a hard error: a
 * set link loads its own IP and never falls back to the A record value.
 */
function vip_record_vip($record, $token) {
    $custom_fields = is_array($record['custom_fields'] ?? null)
        ? $record['custom_fields']
        : [];
    $raw = $custom_fields['vip_address'] ?? null;

    if ($raw === null || $raw === '') {
        return ['state' => 'unset'];
    }

    $id = vip_object_id($raw);

    if ($id === null) {
        return ['state' => 'unset'];
    }

    $address = '';

    if (is_array($raw)) {
        foreach (['address', 'display'] as $key) {
            $value = $raw[$key] ?? null;

            if (is_string($value) && trim($value) !== '') {
                $address = vip_normalize_address($value);
                break;
            }
        }
    }

    $ip = null;

    if ($address === '') {
        // Only an id is present: recover the bare address from IPAM.
        $response = netbox_request(
            'GET',
            '/api/ipam/ip-addresses/' . $id . '/',
            $token
        );

        if ($response['http_code'] !== 200) {
            return [
                'state'     => 'error',
                'http_code' => $response['http_code'],
                'error'     => 'Linked VIP address lookup failed (HTTP '
                    . $response['http_code'] . ')',
            ];
        }

        $ip      = is_array($response['data'] ?? null) ? $response['data'] : [];
        $address = vip_normalize_address((string)($ip['address'] ?? ''));
    }

    return ['state' => 'ok', 'id' => $id, 'address' => $address, 'ip' => $ip];
}

/**
 * Emit the raw stored document for action=build. 200 with the stored
 * vip_build object itself ({"build":{...}}), Content-Type
 * application/json, compact, no wrapper - legacy string storage echoes
 * the stored line as-is. 404 when the IP exists but vip_build is empty
 * (null, '', {}, or missing).
 */
function vip_emit_build($ip, $label) {
    $custom_fields = is_array($ip['custom_fields'] ?? null) ? $ip['custom_fields'] : [];
    $stored = vip_build_stored($custom_fields);

    if ($stored === null) {
        json_response(['error' => 'IP address exists but vip_build is empty: ' . $label], 404);
    }

    $line = vip_build_compact($stored);

    if ($line === null) {
        json_response(
            ['error' => 'IP address exists but vip_build is not encodable JSON: ' . $label],
            500
        );
    }

    http_response_code(200);
    header('Content-Type: application/json');
    echo $line;
    exit;
}

/**
 * True when any listener in the build carries a *_clientssl profile.
 * Profiles may be plain strings or {"name": ...} objects - the page
 * emits its clientssl profile as an object - the same two shapes the
 * SSL-redirect check accepts.
 */
function vip_build_has_clientssl($virtuals) {
    foreach (is_array($virtuals) ? $virtuals : [] as $virtual) {
        if (!is_array($virtual)) {
            continue;
        }

        $profiles = $virtual['profiles'] ?? null;

        if (!is_array($profiles)) {
            continue;
        }

        foreach ($profiles as $profile) {
            $profile_name = '';

            if (is_string($profile)) {
                $profile_name = $profile;
            } elseif (is_array($profile)
                && is_string($profile['name'] ?? null)
            ) {
                $profile_name = $profile['name'];
            }

            if ($profile_name !== ''
                && preg_match('/_clientssl$/i', $profile_name)
            ) {
                return true;
            }
        }
    }

    return false;
}

/**
 * Run every save check against a {"build":{...}} body.
 *
 * Checks: limit is a non-empty array of non-empty strings; exactly one
 * listener name and one VIP address across the virtuals; the VIP
 * address must exist and be unique in NetBox and carry role vip and
 * status active (compared on the value, case-insensitively; member node
 * addresses stay existence-only). The listener name is the SSL Common
 * Name and is optional: with no *_clientssl profile anywhere the page
 * names every virtual after the VIP address itself, and a name that
 * normalizes to that same address skips the netbox-dns lookup entirely
 * (no FQDN-not-found error; save stores vip_ssl null and links no DNS
 * record). With a *_clientssl profile anywhere the name must be a
 * hostname - an IP or empty name is rejected as 'SSL Common Name is
 * required' - that exists and is unique in netbox-dns. No
 * irule/irules key on the build; a listener may carry irules only on
 * port 80 and only the exact one-element list
 * holding the configured redirect irule (that listener must include the
 * configured HTTP profile and must not carry a *_clientssl profile); no
 * analytics profile; pool monitors are a one-element list of the
 * configured tcp or ping monitor; a listener that sets
 * default_persistence_profile must name one of the two configured
 * persistence profiles (a missing key stays allowed), and the cookie
 * profile additionally requires the configured HTTP profile on the same
 * listener (source persistence stays allowed without HTTP); no mixed
 * member ports on one listener; every non-empty member node address
 * must also
 * exist and be unique in NetBox IPAM (member addresses are deduplicated
 * so the same IP is looked up once per request, a lookup transport
 * failure is returned as its own error rather than as missing, and the
 * member IP itself needs no vip_build). Every allowed
 * object name comes from vip_profile_map() in src/vip_profiles.php.
 *
 * Writes nothing. Returns ['ok' => true, 'line', 'object', 'fqdn',
 * 'address', 'ip', 'dns'] on success - 'line' is the compact one-line
 * JSON of 'object' (the {"build":...} wrapper that save stores), and
 * 'fqdn' is '' and 'dns' is null when the
 * virtuals carry no SSL Common Name - or ['error' => message] on any
 * failure.
 */
function vip_check_build($body, $token) {
    if (!is_array($body)) {
        return ['error' => 'Request body must be a JSON object'];
    }

    $build = $body['build'] ?? null;

    if ($build === null && isset($body['virtual'])) {
        // Tolerate a bare build object as well as the {"build":{...}} wrapper.
        $build = $body;
    }

    if (!is_array($build) || $build === []) {
        return ['error' => 'Body must be {"build":{...}} with a non-empty build object'];
    }

    // The allowed object names are site-overridable; shipped defaults are
    // the builtin Common objects.
    $map = vip_profile_map();

    foreach (['irule', 'irules'] as $key) {
        if (array_key_exists($key, $build)) {
            return ['error' => 'The build must not contain an "' . $key . '" key'];
        }
    }

    $virtuals = $build['virtual'] ?? null;

    if (!is_array($virtuals) || $virtuals === []) {
        return ['error' => 'build.virtual must be a non-empty list of listeners'];
    }

    $names          = [];
    $addresses      = [];
    $node_addresses = [];

    // A *_clientssl profile anywhere in the build makes the SSL Common
    // Name mandatory: TLS termination needs a certificate, and the
    // certificate subject is that name. Detected in a pre-pass so an
    // empty listener name is rejected with the right message even when
    // the clientssl profile sits on a later listener.
    $has_clientssl = vip_build_has_clientssl($virtuals);

    foreach (array_values($virtuals) as $i => $virtual) {
        if (!is_array($virtual)) {
            return ['error' => 'Listener #' . ($i + 1) . ' must be an object'];
        }

        if (array_key_exists('irule', $virtual)) {
            return ['error' => 'Listener #' . ($i + 1) . ' must not contain an "irule" key'];
        }

        if (array_key_exists('irules', $virtual)) {
            $port = $virtual['port'] ?? null;

            if (!is_numeric($port) || (int)$port !== 80) {
                return ['error' => 'Listener #' . ($i + 1)
                    . ': irules is only allowed on a port-80 listener'];
            }

            if ($virtual['irules'] !== [$map['https_redirect']]) {
                return ['error' => 'Listener #' . ($i + 1)
                    . ': the only allowed irules value is ["' . $map['https_redirect'] . '"]'];
            }

            // The SSL-redirect listener hands every request to HTTPS: a
            // clientssl profile on the same listener would terminate TLS
            // here instead, so the pair is rejected.
            $redirect_profiles = is_array($virtual['profiles'] ?? null)
                ? $virtual['profiles']
                : [];

            foreach ($redirect_profiles as $redirect_profile) {
                $redirect_profile_name = '';

                if (is_string($redirect_profile)) {
                    $redirect_profile_name = $redirect_profile;
                } elseif (is_array($redirect_profile)
                    && is_string($redirect_profile['name'] ?? null)
                ) {
                    $redirect_profile_name = $redirect_profile['name'];
                }

                if ($redirect_profile_name !== ''
                    && preg_match('/_clientssl$/i', $redirect_profile_name)
                ) {
                    return ['error' => 'Listener #' . ($i + 1)
                        . ': a port-80 SSL-redirect listener must not also carry a '
                        . 'clientssl profile: ' . $redirect_profile_name];
                }
            }

            if (!in_array($map['http'], $redirect_profiles, true)) {
                return ['error' => 'Listener #' . ($i + 1)
                    . ': a port-80 SSL-redirect listener must include ' . $map['http'] . '. '
                    . $map['https_redirect'] . ' requires an HTTP profile'];
            }
        }

        $name    = $virtual['name'] ?? null;
        $address = $virtual['address'] ?? null;

        if (!is_string($name) || trim($name) === '') {
            if ($has_clientssl) {
                return ['error' => 'SSL Common Name is required'];
            }

            return ['error' => 'Listener #' . ($i + 1) . ' is missing a name'];
        }

        if (!is_string($address) || trim($address) === '') {
            return ['error' => 'Listener ' . $name . ' is missing an address'];
        }

        $names[strtolower(trim($name))]         = true;
        $addresses[vip_normalize_address($address)] = true;

        $profiles = $virtual['profiles'] ?? null;

        if (is_array($profiles)) {
            foreach ($profiles as $profile) {
                if (is_string($profile) && stripos($profile, 'analytics') !== false) {
                    return ['error' => 'Analytics profiles are not allowed: ' . $profile];
                }
            }
        }

        // A listener that names a persistence profile must name one of the
        // two configured stock ones. An absent key stays allowed; an
        // explicit null counts as absent.
        $persistence = $virtual['default_persistence_profile'] ?? null;

        if ($persistence !== null
            && !in_array($persistence, [$map['persist_cookie'], $map['persist_source']], true)
        ) {
            return ['error' => 'Listener ' . $name . ': default_persistence_profile must be '
                . $map['persist_cookie'] . ' or ' . $map['persist_source']];
        }

        // Cookie persistence writes its cookie inside HTTP responses, so
        // the listener must also carry the configured HTTP profile.
        // Source-address persistence works below HTTP and stays allowed
        // without one.
        if ($persistence === $map['persist_cookie']
            && !in_array($map['http'], is_array($profiles) ? $profiles : [], true)
        ) {
            return ['error' => 'Listener ' . $name . ': ' . $map['persist_cookie']
                . ' persistence requires the ' . $map['http'] . ' profile on the same listener'];
        }

        $pool = $virtual['pool'] ?? null;

        if (is_array($pool)) {
            $monitors = $pool['monitors'] ?? null;

            if (!is_array($monitors)
                || count($monitors) !== 1
                || !in_array($monitors[0], [$map['monitor_tcp'], $map['monitor_ping']], true)
            ) {
                return ['error' => 'Pool monitors must be a one-element list of '
                    . $map['monitor_tcp'] . ' or ' . $map['monitor_ping']];
            }
        }

        $nodes = $virtual['nodes'] ?? null;

        if (is_array($nodes) && $nodes !== []) {
            $ports = [];

            foreach (array_values($nodes) as $node) {
                if (!is_array($node)
                    || !array_key_exists('port', $node)
                    || !is_numeric($node['port'])
                ) {
                    return ['error' => 'Listener ' . $name . ' has a member without a valid port'];
                }

                $ports[] = (int)$node['port'];

                // Member addresses are only collected here, after the
                // member's own checks; the NetBox lookups run once per
                // unique address after every listener check has passed.
                $node_address = $node['address'] ?? null;

                if (is_string($node_address)) {
                    $node_address = vip_normalize_address($node_address);

                    if ($node_address !== '') {
                        $node_addresses[$node_address] = true;
                    }
                }
            }

            if (count(array_unique($ports)) > 1) {
                return ['error' => 'Listener ' . $name . ' has mixed member ports ('
                    . implode(', ', $ports) . '); split them onto separate listeners'];
            }
        }
    }

    if (count($names) > 1) {
        return ['error' => 'The build must use exactly one FQDN across virtuals, found '
            . count($names)];
    }

    if (count($addresses) > 1) {
        return ['error' => 'The build must use exactly one VIP address across virtuals, found '
            . count($addresses)];
    }

    $limit = $build['limit'] ?? null;

    if (!is_array($limit) || $limit === []) {
        return ['error' => 'build.limit must be a non-empty array of hostnames'];
    }

    foreach ($limit as $hostname) {
        if (!is_string($hostname) || trim($hostname) === '') {
            return ['error' => 'build.limit entries must be non-empty strings'];
        }
    }

    $fqdn         = vip_normalize_fqdn($name);
    $address_bare = vip_normalize_address($address);

    if ($address_bare === '') {
        return ['error' => 'The VIP address is empty'];
    }

    // The SSL Common Name is optional. With no clientssl profile
    // anywhere the page names every virtual after the VIP address
    // itself; a name that normalizes to that same address has no
    // netbox-dns record to find, so the DNS lookup is skipped and save
    // stores vip_ssl null and links no DNS record. A clientssl
    // profile anywhere still demands a real hostname.
    $name_address    = vip_normalize_address($name);
    $name_is_address = $name_address !== ''
        && filter_var($name_address, FILTER_VALIDATE_IP) !== false;

    if ($has_clientssl && ($name_is_address || $fqdn === '')) {
        return ['error' => 'SSL Common Name is required'];
    }

    if ($name_is_address) {
        if (strcasecmp($name_address, $address_bare) !== 0) {
            return ['error' => 'Listener name ' . $name_address
                . ' is an address; name the virtuals after the VIP address '
                . $address_bare . ' or after a hostname'];
        }

        $dns = null;
    } else {
        if ($fqdn === '') {
            return ['error' => 'The listener FQDN is empty'];
        }

        // Both sides must exist and be unique before anything is accepted.
        $dns = vip_dns_lookup($fqdn, $token);

        if ($dns['state'] === 'error') {
            return ['error' => $dns['error']];
        }

        if ($dns['state'] === 'missing') {
            return ['error' => 'FQDN not found in netbox-dns: ' . $fqdn];
        }

        if ($dns['state'] === 'ambiguous') {
            return ['error' => 'FQDN is ambiguous in netbox-dns: ' . $fqdn
                . ' (' . $dns['count'] . ' records)'];
        }
    }

    $ip = vip_ip_lookup($address_bare, $token);

    if ($ip['state'] === 'error') {
        return ['error' => $ip['error']];
    }

    if ($ip['state'] === 'missing') {
        return ['error' => 'IP address not found in IPAM: ' . $address_bare];
    }

    if ($ip['state'] === 'ambiguous') {
        return ['error' => 'IP address is ambiguous in IPAM: ' . $address_bare
            . ' (' . $ip['count'] . ' objects)'];
    }

    // The VIP address itself must be role VIP and status Active in
    // NetBox, compared on the value case-insensitively. Member node
    // addresses stay existence-only: their role and status are never
    // checked here.
    $vip_record = $ip['records'][0];
    $vip_role   = vip_role_status($vip_record['role'] ?? null);
    $vip_status = vip_role_status($vip_record['status'] ?? null);

    if ($vip_role === null
        || strtolower($vip_role['value']) !== 'vip'
        || $vip_status === null
        || strtolower($vip_status['value']) !== 'active'
    ) {
        return ['error' => 'IP address ' . $address_bare
            . ' must be role VIP and status Active (found role '
            . ($vip_role['label'] ?? 'none')
            . ', status '
            . ($vip_status['label'] ?? 'none') . ')'];
    }

    // Every non-empty member node address must itself be a unique IPAM
    // address. The set is deduplicated, so the same member IP is looked
    // up once per request no matter how many listeners or members share
    // it. A member IP needs no vip_build of its own, and an
    // empty or absent member address stays allowed. A lookup transport
    // failure returns its own error - it is not a missing address.
    foreach (array_keys($node_addresses) as $node_address) {
        $node_lookup = vip_ip_lookup($node_address, $token);

        if ($node_lookup['state'] === 'error') {
            return ['error' => $node_lookup['error']];
        }

        if ($node_lookup['state'] === 'missing') {
            return ['error' => 'Member node address ' . $node_address
                . ' is not in NetBox'];
        }

        if ($node_lookup['state'] === 'ambiguous') {
            return ['error' => 'Member node address ' . $node_address
                . ' is ambiguous in NetBox (' . $node_lookup['count'] . ' objects)'];
        }
    }

    $line = vip_build_line($build);

    if ($line === null) {
        return ['error' => 'The build object could not be encoded as JSON'];
    }

    return [
        'ok'      => true,
        'line'    => $line,
        'object'  => vip_build_object($build),
        'fqdn'    => $name_is_address ? '' : $fqdn,
        'address' => $address_bare,
        'ip'      => $ip['records'][0],
        'dns'     => $dns === null ? null : $dns['records'][0],
    ];
}

// Browser calls with Authorization are non-simple, so they preflight.
// Answer OPTIONS here. Do not let a rewrite [R=200] swallow it: that
// status bypasses Header set and the browser rejects the real GET.
if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
    header('Access-Control-Max-Age: 600');
    http_response_code(204);
    exit;
}

$token  = authenticate();
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$action = strtolower(trim($_GET['action'] ?? ''));

switch ($action) {
    case 'fqdn':
        if ($method !== 'GET') {
            json_response(['error' => 'Action fqdn expects GET'], 405);
        }

        $fqdn = vip_normalize_fqdn($_GET['fqdn'] ?? '');

        if ($fqdn === '') {
            json_response(['error' => 'fqdn is required'], 400);
        }

        $lookup = vip_dns_lookup($fqdn, $token);

        if ($lookup['state'] !== 'ok') {
            vip_lookup_fail($lookup, 'FQDN', $fqdn);
        }

        $record = $lookup['records'][0];

        // The record's vip_address link names the VIP the page loads
        // through the address path. Unset or unresolvable is '' / null:
        // a found DNS record never turns into a 500 because its link is
        // broken, and the page keeps the "found" state without loading.
        $vip = vip_record_vip($record, $token);

        json_response([
            'fqdn'       => $fqdn,
            'id'         => $record['id'],
            'dns_id'     => $record['id'],
            'name'       => (string)($record['name'] ?? ''),
            'zone'       => (string)($record['zone']['name'] ?? ''),
            'type'       => (string)($record['type'] ?? ''),
            'value'      => (string)($record['value'] ?? ''),
            'address'    => $vip['state'] === 'ok' ? $vip['address'] : '',
            'address_id' => $vip['state'] === 'ok' ? $vip['id'] : null,
        ]);
        break;

    case 'address':
        if ($method !== 'GET') {
            json_response(['error' => 'Action address expects GET'], 405);
        }

        $address = vip_normalize_address($_GET['address'] ?? '');

        if ($address === '') {
            json_response(['error' => 'address is required'], 400);
        }

        $lookup = vip_ip_lookup($address, $token);

        if ($lookup['state'] !== 'ok') {
            vip_lookup_fail($lookup, 'IP address', $address);
        }

        $ip            = $lookup['records'][0];
        $custom_fields = is_array($ip['custom_fields'] ?? null) ? $ip['custom_fields'] : [];
        $stored        = vip_build_stored($custom_fields);

        json_response([
            'address'   => (string)($ip['address'] ?? $address),
            'id'        => $ip['id'],
            'vip_build' => vip_build_compact($stored) ?? '',
            'vip_fqdn'  => vip_ssl_hostname($custom_fields['vip_ssl'] ?? null, $token),
            'role'      => vip_role_status($ip['role'] ?? null),
            'status'    => vip_role_status($ip['status'] ?? null),
        ]);
        break;

    case 'load':
        if ($method !== 'GET') {
            json_response(['error' => 'Action load expects GET'], 405);
        }

        $fqdn = vip_normalize_fqdn($_GET['fqdn'] ?? '');

        if ($fqdn === '') {
            json_response(['error' => 'fqdn is required'], 400);
        }

        $dns = vip_dns_lookup($fqdn, $token);

        if ($dns['state'] !== 'ok') {
            vip_lookup_fail($dns, 'FQDN', $fqdn);
        }

        $record = $dns['records'][0];

        // The vip_address link wins when it is set: load THAT ipam object
        // by id - a second address= search can 409, and a set link must
        // never silently fall back to the A record value. The DNS value
        // stays the fallback so records saved before the link existed
        // still load.
        $vip = vip_record_vip($record, $token);

        if ($vip['state'] === 'error') {
            json_response(
                ['error' => $vip['error'] . ': ' . $fqdn],
                $vip['http_code'] === 404 ? 404 : 500
            );
        }

        $fallback_address = '';

        if ($vip['state'] === 'ok') {
            // The helper already fetched the IP object when the link
            // carried only an id; otherwise one id-GET recovers it.
            $ip               = is_array($vip['ip'] ?? null) ? $vip['ip'] : null;
            $fallback_address = $vip['address'];

            if (!is_array($ip)) {
                $response = netbox_request(
                    'GET',
                    '/api/ipam/ip-addresses/' . $vip['id'] . '/',
                    $token
                );

                if ($response['http_code'] !== 200) {
                    json_response(
                        ['error' => 'Linked VIP address lookup failed (HTTP '
                            . $response['http_code'] . '): ' . $fqdn],
                        $response['http_code'] === 404 ? 404 : 500
                    );
                }

                $ip = is_array($response['data'] ?? null) ? $response['data'] : [];
            }
        } else {
            $record_value     = trim((string)($record['value'] ?? ''));
            $fallback_address = $record_value;

            if ($record_value === '') {
                json_response(['error' => 'DNS record has no value: ' . $fqdn], 404);
            }

            $ip_lookup = vip_ip_lookup(vip_normalize_address($record_value), $token);

            if ($ip_lookup['state'] !== 'ok') {
                vip_lookup_fail($ip_lookup, 'IP address', $record_value);
            }

            $ip = $ip_lookup['records'][0];
        }

        $custom_fields = is_array($ip['custom_fields'] ?? null) ? $ip['custom_fields'] : [];
        $stored        = vip_build_stored($custom_fields);

        json_response([
            'fqdn'      => $fqdn,
            'address'   => (string)($ip['address'] ?? $fallback_address),
            'id'        => $ip['id'],
            'dns_id'    => $record['id'],
            'vip_build' => vip_build_compact($stored) ?? '',
        ]);
        break;

    case 'list':
        if ($method !== 'GET') {
            json_response(['error' => 'Action list expects GET'], 405);
        }

        $response = netbox_request('GET', '/api/ipam/ip-addresses/?limit=0', $token);

        if ($response['http_code'] !== 200) {
            json_response(
                ['error' => 'IP address listing failed (HTTP ' . $response['http_code'] . ')'],
                500
            );
        }

        $results = $response['data']['results'] ?? [];

        if (!is_array($results)) {
            $results = [];
        }

        // Filtered here rather than server-side: a custom-field query
        // cannot reliably express "non-empty" across null, {}, empty
        // string, and missing, and this instance is small enough for
        // one fetch. fqdn comes from the vip_ssl link's own fields when
        // the API serializes them; no per-row record lookups here.
        $vips = [];

        foreach ($results as $ip) {
            $custom_fields = is_array($ip['custom_fields'] ?? null) ? $ip['custom_fields'] : [];
            $stored        = vip_build_stored($custom_fields);

            if ($stored === null) {
                continue;
            }

            $vips[] = [
                'id'        => $ip['id'],
                'address'   => (string)($ip['address'] ?? ''),
                'fqdn'      => vip_ssl_hostname($custom_fields['vip_ssl'] ?? null, $token, false),
                'vip_build' => vip_build_compact($stored) ?? '',
            ];
        }

        usort($vips, function ($a, $b) {
            return strcmp($a['address'], $b['address']);
        });

        json_response(['vips' => $vips]);
        break;

    case 'build':
        if ($method !== 'GET') {
            json_response(['error' => 'Action build expects GET'], 405);
        }

        $fqdn_param    = trim((string)($_GET['fqdn'] ?? ''));
        $address_param = trim((string)($_GET['address'] ?? ''));

        if ($fqdn_param !== '' && $address_param !== '') {
            json_response(['error' => 'Pass either fqdn or address, not both'], 400);
        }

        if ($fqdn_param === '' && $address_param === '') {
            json_response(['error' => 'Pass fqdn or address'], 400);
        }

        if ($address_param !== '') {
            $address   = vip_normalize_address($address_param);
            $ip_lookup = vip_ip_lookup($address, $token);

            if ($ip_lookup['state'] !== 'ok') {
                vip_lookup_fail($ip_lookup, 'IP address', $address);
            }

            vip_emit_build($ip_lookup['records'][0], $address);
        }

        $fqdn = vip_normalize_fqdn($fqdn_param);
        $dns  = vip_dns_lookup($fqdn, $token);

        if ($dns['state'] !== 'ok') {
            vip_lookup_fail($dns, 'FQDN', $fqdn);
        }

        $record       = $dns['records'][0];
        $record_value = trim((string)($record['value'] ?? ''));

        if ($record_value === '') {
            json_response(['error' => 'DNS record has no value: ' . $fqdn], 404);
        }

        $ip_lookup = vip_ip_lookup(vip_normalize_address($record_value), $token);

        if ($ip_lookup['state'] !== 'ok') {
            vip_lookup_fail($ip_lookup, 'IP address', $record_value);
        }

        vip_emit_build($ip_lookup['records'][0], $record_value);
        break;

    case 'validate':
        if ($method !== 'POST') {
            json_response(['error' => 'Action validate expects POST'], 405);
        }

        $checked = vip_check_build(json_decode(file_get_contents('php://input'), true), $token);

        if (isset($checked['error'])) {
            json_response(['error' => $checked['error']], 400);
        }

        json_response(['ok' => true, 'vip_build' => $checked['line']]);
        break;

    case 'save':
        if ($method !== 'POST') {
            json_response(['error' => 'Action save expects POST'], 405);
        }

        $checked = vip_check_build(json_decode(file_get_contents('php://input'), true), $token);

        if (isset($checked['error'])) {
            json_response(['error' => $checked['error']], 400);
        }

        // Link write 1 of 2: the build object and the SSL link on the
        // IP. vip_ssl carries the netbox-dns record id, or null when the
        // build has no SSL Common Name - which also clears any previous
        // link.
        $ip_patch = netbox_request(
            'PATCH',
            '/api/ipam/ip-addresses/' . $checked['ip']['id'] . '/',
            $token,
            [
                'custom_fields' => [
                    'vip_build' => $checked['object'],
                    'vip_ssl'   => is_array($checked['dns']) ? (int)$checked['dns']['id'] : null,
                ],
            ]
        );

        if ($ip_patch['http_code'] !== 200) {
            json_response([
                'error'  => 'Failed to update IP address ' . $checked['ip']['id']
                    . ' (HTTP ' . $ip_patch['http_code'] . '): '
                    . ($ip_patch['data'] === null
                        ? 'no response body'
                        : (string)json_encode($ip_patch['data'])),
                'failed' => 'ip_address',
            ], 500);
        }

        // Link write 2 of 2: the IP address on the DNS record. Skipped
        // when the build carries no SSL Common Name (the virtuals are
        // named after the VIP address): there is no netbox-dns record to
        // link, vip_ssl went out null on the IP PATCH above, and
        // dns_id comes back null.
        $dns_id = null;

        if (is_array($checked['dns'])) {
            $dns_patch = netbox_request(
                'PATCH',
                '/api/plugins/netbox-dns/records/' . $checked['dns']['id'] . '/',
                $token,
                [
                    'custom_fields' => [
                        'vip_address' => (int)$checked['ip']['id'],
                    ],
                ]
            );

            if ($dns_patch['http_code'] !== 200) {
                json_response([
                    'error'  => 'Failed to update DNS record ' . $checked['dns']['id']
                        . ' (HTTP ' . $dns_patch['http_code'] . '): '
                        . ($dns_patch['data'] === null
                            ? 'no response body'
                            : (string)json_encode($dns_patch['data'])),
                    'failed' => 'dns_record',
                ], 500);
            }

            $dns_id = $checked['dns']['id'];
        }

        json_response([
            'ok'        => true,
            'id'        => $checked['ip']['id'],
            'dns_id'    => $dns_id,
            'vip_build' => $checked['line'],
        ]);
        break;

    default:
        json_response(
            ['error' => $action === '' ? 'Missing action' : 'Unknown action: ' . $action],
            400
        );
}
?>