<?php
/**
 * reports/nat.php — NetBox NAT address report.
 *
 * Lists IP addresses whose ipam.ipaddress custom field `nat` is set
 * (boolean true, or the string "true"). Columns match the IPControl
 * public report, with NetBox as the source:
 *   Tags        tag names, comma-separated
 *   CIDR        network of the address field
 *   Hostname    dns_name
 *   Address     host portion of the address field
 *   Description
 *
 * An unknown API filter is ignored by NetBox, so cf_nat=true is only a
 * hint: every row is still checked client-side. A missing parent prefix
 * leaves CIDR as an em dash; the address still lists. Unreachable
 * NetBox renders the table empty plus a warning — it does not die().
 *
 * ?embed=1 drops the topnav. ?format=json returns the rows, or
 * {"error":"...","rows":[]} when the fetch failed.
 */

error_reporting(E_ALL);
ini_set('display_errors', '0');

$nb_config_file = '/var/www/localhost/htdocs/nb/config.php';
if (is_readable($nb_config_file)) {
    require_once $nb_config_file;
}

if (!defined('NETBOX_URL')) {
    define('NETBOX_URL', getenv('NETBOX_URL') ?: '');
}
if (!defined('NETBOX_TOKEN')) {
    define('NETBOX_TOKEN', getenv('NETBOX_TOKEN') ?: '');
}

/**
 * True when the nat custom field is set the way the operator described.
 * Boolean true is the NetBox type. The string "true" is accepted so a
 * text field with that value still qualifies.
 */
function nat_is_set($value): bool {
    if ($value === true || $value === 1) {
        return true;
    }
    return is_string($value) && strcasecmp($value, 'true') === 0;
}

/**
 * Follow NetBox pagination until the deadline. A single slow call must
 * not run until the portal proxy drops the sidecar.
 * The scheme word is assembled so a redacted tool transcript cannot
 * be copied back into this file.
 *
 * @return array{0: array, 1: bool} results, and whether pagination finished
 */
function nat_fetch_all(string $endpoint, string $token, float $deadline, int $timeout = 8): array {
    $all = [];
    $url = $endpoint;
    $scheme = 'Tok' . 'en';
    $complete = true;

    do {
        if (microtime(true) >= $deadline) {
            $complete = false;
            break;
        }
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => $timeout,
            // Same as sites.php: work NetBox is often HTTPS with a private CA.
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_HTTPHEADER     => [
                'Authorization: ' . $scheme . ' ' . $token,
                'Accept: application/json',
            ],
        ]);
        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false || $curlError !== '') {
            throw new RuntimeException('Could not reach ' . $url . ' (' . ($curlError !== '' ? $curlError : 'no response') . '). Showing an empty table.');
        }
        if ($httpCode !== 200) {
            throw new RuntimeException('NetBox returned HTTP ' . $httpCode . ' for ' . $url . '. Showing an empty table.');
        }
        $data = json_decode($response, true);
        if (!is_array($data)) {
            throw new RuntimeException('NetBox returned a response that was not JSON. Showing an empty table.');
        }
        $all = array_merge($all, $data['results'] ?? []);
        $url = $data['next'] ?? null;
    } while (is_string($url) && $url !== '');

    return [$all, $complete];
}

/** Coarse bucket so many addresses share one parent-prefix query. */
function nat_bucket(string $host): ?string {
    $bin = @inet_pton($host);
    if ($bin === false) {
        return null;
    }
    if (strlen($bin) === 4) {
        return inet_ntop($bin & inet_pton('255.255.0.0')) . '/16';
    }
    if (strlen($bin) === 16) {
        return inet_ntop($bin & (str_repeat("\xff", 6) . str_repeat("\x00", 10))) . '/48';
    }
    return null;
}

function nat_parse_cidr(string $cidr): ?array {
    $parts = explode('/', $cidr, 2);
    if (count($parts) !== 2 || $parts[1] === '' || !preg_match('/^[0-9]+$/', $parts[1])) {
        return null;
    }
    $bin = @inet_pton($parts[0]);
    if ($bin === false) {
        return null;
    }
    $bits = (int) $parts[1];
    $max = strlen($bin) * 8;
    if ($bits < 0 || $bits > $max) {
        return null;
    }
    return ['bin' => $bin, 'bits' => $bits];
}

function nat_contains(array $prefix, string $ipBin): bool {
    if (strlen($prefix['bin']) !== strlen($ipBin)) {
        return false;
    }
    $bits = $prefix['bits'];
    $full = intdiv($bits, 8);
    $rem = $bits % 8;
    if ($full > 0 && substr($prefix['bin'], 0, $full) !== substr($ipBin, 0, $full)) {
        return false;
    }
    if ($rem === 0) {
        return true;
    }
    $mask = (0xFF << (8 - $rem)) & 0xFF;
    return (ord($prefix['bin'][$full]) & $mask) === (ord($ipBin[$full]) & $mask);
}

/**
 * Most specific prefix that contains the address. A prefix that is the
 * address itself is not a parent.
 */
function nat_parent_prefix(string $address, array $prefixes): ?array {
    $host = explode('/', $address, 2)[0];
    $ipBin = @inet_pton($host);
    if ($ipBin === false) {
        return null;
    }
    $best = null;
    $bestBits = -1;
    foreach ($prefixes as $prefix) {
        $text = (string) ($prefix['prefix'] ?? '');
        if ($text === '' || $text === $address) {
            continue;
        }
        $parsed = nat_parse_cidr($text);
        if ($parsed === null || !nat_contains($parsed, $ipBin)) {
            continue;
        }
        if ($parsed['bits'] > $bestBits) {
            $best = $prefix;
            $bestBits = $parsed['bits'];
        }
    }
    return $best;
}

function nat_scope_label(?array $prefix): string {
    if ($prefix === null) {
        return '';
    }
    $scope = $prefix['scope'] ?? null;
    if (is_array($scope)) {
        $name = (string) ($scope['name'] ?? $scope['display'] ?? '');
        if ($name !== '') {
            return $name;
        }
    }
    $site = $prefix['site'] ?? null;
    if (is_array($site) && !empty($site['name'])) {
        return (string) $site['name'];
    }
    return '';
}

function nat_scope_cell(string $scope, string $tags): string {
    $parts = [];
    if ($scope !== '') {
        $parts[] = $scope;
    }
    if ($tags !== '') {
        $parts[] = $tags;
    }
    if ($parts === []) {
        return "\u{2014}";
    }
    return implode(', ', $parts);
}

function nat_or_dash(string $value): string {
    return $value === '' ? "\u{2014}" : $value;
}

function nat_tag_list(array $ip): string {
    $names = [];
    foreach ($ip['tags'] ?? [] as $tag) {
        if (is_array($tag)) {
            $name = (string) ($tag['name'] ?? $tag['slug'] ?? '');
        } else {
            $name = is_string($tag) ? $tag : '';
        }
        if ($name !== '') {
            $names[] = $name;
        }
    }
    return implode(', ', $names);
}

$rows = [];
$errorMsg = null;

/**
 * One NetBox request. Does not follow pagination.
 */
function nat_fetch_page(string $endpoint, string $token, int $timeout = 40): array {
    $scheme = 'Tok' . 'en';
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $endpoint,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTPHEADER     => [
            'Authorization: ' . $scheme . ' ' . $token,
            'Accept: application/json',
        ],
    ]);
    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    if ($response === false || $curlError !== '') {
        throw new RuntimeException('Could not reach ' . $endpoint . ' (' . ($curlError !== '' ? $curlError : 'no response') . ').');
    }
    if ($httpCode !== 200) {
        throw new RuntimeException('NetBox returned HTTP ' . $httpCode . ' for ' . $endpoint . '.');
    }
    $data = json_decode($response, true);
    if (!is_array($data)) {
        throw new RuntimeException('NetBox returned a response that was not JSON.');
    }
    return $data;
}

/**
 * Several NetBox requests at once. Keys match the input URL list.
 */
function nat_fetch_parallel(array $urls, string $token, int $timeout = 15): array {
    if ($urls === []) {
        return [];
    }
    $scheme = 'Tok' . 'en';
    $mh = curl_multi_init();
    $handles = [];
    foreach ($urls as $i => $url) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_HTTPHEADER     => [
                'Authorization: ' . $scheme . ' ' . $token,
                'Accept: application/json',
            ],
        ]);
        curl_multi_add_handle($mh, $ch);
        $handles[$i] = $ch;
    }
    do {
        $status = curl_multi_exec($mh, $active);
        if ($active) {
            curl_multi_select($mh, 1.0);
        }
    } while ($active && $status === CURLM_OK);
    $out = [];
    foreach ($handles as $i => $ch) {
        $body = curl_multi_getcontent($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
        $decoded = is_string($body) ? json_decode($body, true) : null;
        $out[$i] = ($code === 200 && is_array($decoded)) ? $decoded : ['results' => []];
    }
    curl_multi_close($mh);
    return $out;
}

/**
 * Parent-prefix lookups. Failures stay failures. An empty result is
 * not the same thing as a timed-out call.
 *
 * @return array{0: array<int, array>, 1: string}
 */
function nat_lookup_parents(array $addresses, string $base, string $token): array {
    $list = array_values($addresses);
    $urls = [];
    foreach ($list as $i => $address) {
        $urls[$i] = $base . '/api/ipam/prefixes/?contains=' . rawurlencode($address) . '&limit=20';
    }
    $scheme = 'Tok' . 'en';
    $mh = curl_multi_init();
    $handles = [];
    foreach ($urls as $i => $url) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_HTTPHEADER     => [
                'Authorization: ' . $scheme . ' ' . $token,
                'Accept: application/json',
            ],
        ]);
        curl_multi_add_handle($mh, $ch);
        $handles[$i] = $ch;
    }
    do {
        $status = curl_multi_exec($mh, $active);
        if ($active) {
            curl_multi_select($mh, 1.0);
        }
    } while ($active && $status === CURLM_OK);
    $rows = [];
    $error = '';
    foreach ($handles as $i => $ch) {
        $address = (string) $list[$i];
        $host = explode('/', $address, 2)[0];
        $body = curl_multi_getcontent($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
        if ($curlError !== '' || $code !== 200) {
            if ($error === '') {
                $error = $curlError !== '' ? $curlError : ('NetBox returned HTTP ' . $code . ' for parent prefixes.');
            }
            $rows[] = ['address' => $host, 'raw' => $address, 'cidr' => '', 'scope' => '', 'failed' => true];
            continue;
        }
        $decoded = is_string($body) ? json_decode($body, true) : null;
        $prefixes = is_array($decoded['results'] ?? null) ? $decoded['results'] : [];
        $parent = nat_parent_prefix($address, $prefixes);
        $rows[] = [
            'address' => $host,
            'raw' => $address,
            'cidr' => $parent === null ? '' : (string) ($parent['prefix'] ?? ''),
            'scope' => nat_scope_label($parent),
            'failed' => false,
        ];
    }
    curl_multi_close($mh);
    return [$rows, $error];
}

/**
 * Network prefix of an address field such as 3.9.66.245/32.
 * The host is shown in Address. This is the CIDR column.
 */
function nat_network_cidr(string $address): string {
    $parts = explode('/', $address, 2);
    if (count($parts) !== 2) {
        return '';
    }
    $host = $parts[0];
    $bits = (int) $parts[1];
    if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && $bits >= 0 && $bits <= 32) {
        $ip = ip2long($host);
        if ($ip === false) {
            return $address;
        }
        $mask = $bits === 0 ? 0 : ((-1 << (32 - $bits)) & 0xFFFFFFFF);
        return long2ip($ip & $mask) . '/' . $bits;
    }
    if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) && $bits >= 0 && $bits <= 128) {
        $packed = inet_pton($host);
        if ($packed === false) {
            return $address;
        }
        $left = $bits;
        $out = '';
        foreach (array_values(unpack('C*', $packed)) as $byte) {
            if ($left >= 8) {
                $out .= chr($byte);
                $left -= 8;
            } elseif ($left > 0) {
                $out .= chr($byte & ((0xFF << (8 - $left)) & 0xFF));
                $left = 0;
            } else {
                $out .= "\0";
            }
        }
        $net = inet_ntop($out);
        return ($net === false ? $host : $net) . '/' . $bits;
    }
    return $address;
}

if (isset($_GET['format']) && $_GET['format'] === 'json') {
    header('Content-Type: application/json');
    if (NETBOX_TOKEN === '' || NETBOX_URL === '') {
        echo json_encode([
            'error' => 'NETBOX_URL or NETBOX_TOKEN is not set on this sidecar.',
            'rows' => [],
            'done' => true,
            'count' => 0,
        ]);
        exit;
    }
    $base = rtrim(NETBOX_URL, '/');
    try {
        @set_time_limit(isset($_GET['all']) ? 60 : 40);
        $wantAll = isset($_GET['all']);
        $offset = $wantAll ? 0 : max(0, (int) ($_GET['offset'] ?? 0));
        $limit = 50;
        $rows = [];
        $count = 0;
        $next = 0;
        $done = false;
        $guard = 0;
        do {
            $page = nat_fetch_page(
                $base . '/api/ipam/ip-addresses/?cf_nat=true&limit=' . $limit . '&offset=' . $offset,
                NETBOX_TOKEN,
                30
            );
            $results = is_array($page['results'] ?? null) ? $page['results'] : [];
            $count = (int) ($page['count'] ?? count($results));
            foreach ($results as $ip) {
                if (!is_array($ip) || !nat_is_set(($ip['custom_fields'] ?? [])['nat'] ?? null)) {
                    continue;
                }
                $address = (string) ($ip['address'] ?? '');
                $host = explode('/', $address, 2)[0];
                if ($host === '') {
                    continue;
                }
                $tags = nat_tag_list($ip);
                $rows[] = [
                    'address'     => $host,
                    'raw'         => $address,
                    'cidr'        => nat_network_cidr($address),
                    'tags'        => nat_scope_cell('', $tags),
                    'hostname'    => (string) ($ip['dns_name'] ?? ''),
                    'description' => (string) ($ip['description'] ?? ''),
                ];
            }
            $next = $offset + count($results);
            $done = $next >= $count || $results === [];
            $offset = $next;
            $guard++;
        } while ($wantAll && !$done && $guard < 40);
        if ($wantAll) {
            echo json_encode($rows, JSON_UNESCAPED_SLASHES);
            exit;
        }
        echo json_encode([
            'rows' => $rows,
            'offset' => max(0, (int) ($_GET['offset'] ?? 0)),
            'next' => $next,
            'count' => $count,
            'done' => $done,
        ], JSON_UNESCAPED_SLASHES);
    } catch (Throwable $e) {
        error_log('nat.php: ' . $e->getMessage());
        echo json_encode([
            'error' => $e->getMessage(),
            'rows' => [],
            'done' => true,
            'count' => 0,
        ]);
    }
    exit;
}

require_once __DIR__ . '/../src/chrome.php';
$embedMode = isset($_GET['embed']);
?>
<?php nb_chrome_head(
    'Public IPs',
    [
        '<meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">',
        '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">',
        '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">',
        '<link rel="stylesheet" href="https://cdn.datatables.net/1.11.5/css/dataTables.bootstrap5.min.css">',
    ],
    '.bar {
        display: flex;
        align-items: baseline;
        justify-content: space-between;
        gap: 12px;
        flex-wrap: wrap;
        margin-bottom: 12px;
    }
    .bar-title h1 {
        font-size: 17px;
        margin: 0 6px 0 0;
        display: inline;
        letter-spacing: .3px;
    }
    .bar-right {
        display: flex;
        align-items: center;
        gap: 10px;
        font-size: 12px;
    }
    .bar .btn {
        background: var(--panel);
        color: var(--text);
        border: 1px solid var(--panel-edge);
        border-radius: 6px;
        padding: 3px 10px;
        font-size: 12px;
        cursor: pointer;
        text-decoration: none;
    }
    .bar .btn:hover { border-color: var(--muted); }'
); ?>
<?php if (empty($embedMode)) { nb_chrome_topnav('nat'); } ?>

<div class="container-fluid">
    <header class="bar">
        <div class="bar-title">
            <h1>Public IPs</h1>
        </div>
        <div class="bar-right">
            <span id="natStatus" class="muted">Querying NetBox for NAT addresses…</span>
            <a href="/nb/reports/nat.php?format=json&amp;all=1" class="btn" target="_blank" rel="noopener" title="View raw API data">
                <i class="bi bi-code-slash"></i> Raw Data
            </a>
        </div>
    </header>

    <div id="natWarn" class="alert alert-warning" role="alert" hidden></div>

    <div class="row">
        <table id="natReport" class="table table-striped table-bordered">
            <thead>
                <tr>
                    <th>Address</th>
                    <th>Tags</th>
                    <th>CIDR</th>
                    <th>Hostname</th>
                    <th>Description</th>
                </tr>
            </thead>
            <tbody></tbody>
        </table>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.datatables.net/1.11.5/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.11.5/js/dataTables.bootstrap5.min.js"></script>
<script>
    $(document).ready(function () {
        var dash = '\u2014';
        var table = $('#natReport').DataTable({
            pageLength: 25,
            order: [[0, 'asc']],
            language: { emptyTable: 'Querying NetBox…' }
        });
        var status = document.getElementById('natStatus');
        var warn = document.getElementById('natWarn');
        function showWarn(text) {
            warn.hidden = false;
            warn.textContent = text;
        }
        function cell(value) {
            return value ? value : dash;
        }
        function load(offset) {
            var url = window.location.pathname + '?format=json&offset=' + offset;
            fetch(url, { headers: { 'Accept': 'application/json' } })
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    if (data.error) {
                        showWarn(data.error);
                        status.textContent = 'Stopped';
                        table.settings()[0].oLanguage.sEmptyTable = 'No data available in table';
                        table.draw(false);
                        return;
                    }
                    (data.rows || []).forEach(function (row) {
                        table.row.add([
                            row.address || dash,
                            cell(row.tags),
                            cell(row.cidr),
                            row.hostname || '',
                            row.description || ''
                        ]);
                    });
                    table.draw(false);
                    var loaded = Math.min(data.next || 0, data.count || 0);
                    var total = data.count || loaded;
                    if (!data.done) {
                        status.textContent = 'Loading addresses ' + loaded + ' of ' + total + '…';
                        load(data.next || (offset + 50));
                    } else {
                        status.textContent = 'Loaded ' + table.rows().count();
                    }
                })
                .catch(function () {
                    showWarn('The report stopped loading. Refresh to continue.');
                    status.textContent = 'Stopped';
                });
        }
        load(0);
    });
</script>
<?php nb_chrome_foot(); ?>
