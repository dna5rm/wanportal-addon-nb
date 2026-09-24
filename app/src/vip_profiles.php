<?php
/**
 * vip_profiles.php — site-overridable F5 object names for the VIP builder.
 *
 * Shipped defaults are builtin Common objects. A deployment overrides a name
 * by defining the matching constant in config.php, or by setting the
 * environment variable. Empty values fall through to the builtin name.
 * Do not put site-specific names in this file.
 */

function vip_profile_map(): array
{
    $spec = [
        'http'            => ['VIP_PROFILE_HTTP',            '/Common/http'],
        'tcp'             => ['VIP_PROFILE_TCP',             '/Common/tcp'],
        'monitor_tcp'     => ['VIP_MONITOR_TCP',             '/Common/tcp'],
        'monitor_ping'    => ['VIP_MONITOR_PING',            '/Common/gateway_icmp'],
        'persist_cookie'  => ['VIP_PERSIST_COOKIE',          '/Common/cookie'],
        'persist_source'  => ['VIP_PERSIST_SOURCE',          '/Common/source_addr'],
        'https_redirect'  => ['VIP_IRULE_HTTPS_REDIRECT',    '/Common/_sys_https_redirect'],
    ];

    $map = [];
    foreach ($spec as $key => $pair) {
        [$const, $builtin] = $pair;
        $value = '';
        if (defined($const)) {
            $defined = constant($const);
            if (is_string($defined)) {
                $value = trim($defined);
            }
        }
        if ($value === '') {
            $fromEnv = getenv($const);
            if (is_string($fromEnv)) {
                $value = trim($fromEnv);
            }
        }
        $map[$key] = $value !== '' ? $value : $builtin;
    }

    return $map;
}

/**
 * Configured Tower limit choices. Empty means the page keeps a free-text box.
 *
 * VIP_LIMIT_HOSTS is one option per line. A line is a single host, or a pair
 * (or more) separated by commas. Semicolons also separate options, so a
 * one-line env value can hold several choices. Site names stay in config.php
 * or the environment, never in this file.
 *
 * @return list<array{label: string, hosts: list<string>}>
 */
function vip_limit_options(): array
{
    $raw = '';
    if (defined('VIP_LIMIT_HOSTS') && is_string(constant('VIP_LIMIT_HOSTS'))) {
        $raw = constant('VIP_LIMIT_HOSTS');
    }
    if (trim($raw) === '') {
        $fromEnv = getenv('VIP_LIMIT_HOSTS');
        if (is_string($fromEnv)) {
            $raw = $fromEnv;
        }
    }

    $options = [];
    foreach (preg_split('/\r\n|\n|;/', $raw) as $line) {
        $hosts = [];
        foreach (explode(',', $line) as $host) {
            $host = trim($host);
            if ($host !== '') {
                $hosts[] = $host;
            }
        }
        if ($hosts === []) {
            continue;
        }
        $options[] = [
            'label' => implode(', ', $hosts),
            'hosts' => $hosts,
        ];
    }

    return $options;
}
