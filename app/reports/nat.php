<?php
/**
 * reports/nat.php — NetBox NAT address report.
 *
 * Lists IP addresses whose ipam.ipaddress custom field `nat` is set
 * (boolean true, or the string "true"). Columns match the IPControl
 * public report, with NetBox as the source:
 *   Scope       scope name and tag names, comma-separated
 *   CIDR        that parent prefix, or an em dash when there is none
 *   Hostname    dns_name
 *   Address     the IP as stored
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
 * Follow NetBox pagination. Throws on transport or HTTP failure.
 * The scheme word is assembled so a redacted tool transcript cannot
 * be copied back into this file.
 */
function nat_fetch_all(string $endpoint, string $token): array {
    $all = [];
    $url = $endpoint;
    $scheme = 'Tok' . 'en';

    do {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 30,
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
            throw new RuntimeException('NetBox is not reachable. Showing an empty table.');
        }
        if ($httpCode !== 200) {
            throw new RuntimeException('NetBox is not reachable. Showing an empty table.');
        }
        $data = json_decode($response, true);
        if (!is_array($data)) {
            throw new RuntimeException('NetBox is not reachable. Showing an empty table.');
        }
        $all = array_merge($all, $data['results'] ?? []);
        $url = $data['next'] ?? null;
    } while (is_string($url) && $url !== '');

    return $all;
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

if (NETBOX_TOKEN === '' || NETBOX_URL === '') {
    $errorMsg = 'NetBox is not configured. Showing an empty table.';
} else {
    try {
        $base = rtrim(NETBOX_URL, '/');
        $ips = nat_fetch_all($base . '/api/ipam/ip-addresses/?cf_nat=true&limit=500', NETBOX_TOKEN);
        $prefixes = nat_fetch_all($base . '/api/ipam/prefixes/?limit=500', NETBOX_TOKEN);
        foreach ($ips as $ip) {
            if (!is_array($ip)) {
                continue;
            }
            $cf = is_array($ip['custom_fields'] ?? null) ? $ip['custom_fields'] : [];
            if (!nat_is_set($cf['nat'] ?? null)) {
                continue;
            }
            $address = (string) ($ip['address'] ?? '');
            $parent = nat_parent_prefix($address, $prefixes);
            $rows[] = [
                'site'        => nat_scope_label($parent),
                'cidr'        => $parent === null ? '' : (string) ($parent['prefix'] ?? ''),
                'hostname'    => (string) ($ip['dns_name'] ?? ''),
                'address'     => $address,
                'description' => (string) ($ip['description'] ?? ''),
                'tags'        => nat_tag_list($ip),
            ];
        }
        usort($rows, function (array $a, array $b): int {
            return [$a['site'], $a['cidr'], $a['address']]
                <=> [$b['site'], $b['cidr'], $b['address']];
        });
    } catch (Throwable $e) {
        error_log('nat.php: ' . $e->getMessage());
        $rows = [];
        $errorMsg = 'NetBox is not reachable. Showing an empty table.';
    }
}

if (isset($_GET['format']) && $_GET['format'] === 'json') {
    header('Content-Type: application/json');
    if ($errorMsg !== null) {
        echo json_encode(['error' => $errorMsg, 'rows' => []], JSON_UNESCAPED_SLASHES);
    } else {
        echo json_encode($rows, JSON_UNESCAPED_SLASHES);
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
            <a href="?format=json" class="btn" title="View raw API data">
                <i class="bi bi-code-slash"></i> Raw Data
            </a>
        </div>
    </header>

    <?php if ($errorMsg !== null): ?>
    <div class="alert alert-warning" role="alert"><?= htmlspecialchars($errorMsg) ?></div>
    <?php endif; ?>

    <div class="row">
        <table id="natReport" class="table table-striped table-bordered">
            <thead>
                <tr>
                    <th>Address</th>
                    <th>Scope</th>
                    <th>CIDR</th>
                    <th>Hostname</th>
                    <th>Description</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $row): ?>
                <tr>
                    <td><?= htmlspecialchars($row['address']) ?></td>
                    <td><?= htmlspecialchars(nat_scope_cell($row['site'], $row['tags'])) ?></td>
                    <td><?= htmlspecialchars(nat_or_dash($row['cidr'])) ?></td>
                    <td><?= htmlspecialchars($row['hostname']) ?></td>
                    <td><?= htmlspecialchars($row['description']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.datatables.net/1.11.5/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.11.5/js/dataTables.bootstrap5.min.js"></script>
<script>
    $(document).ready(function () {
        $('#natReport').DataTable({
            pageLength: 25,
            order: [[0, 'asc']]
        });
    });
</script>
<?php nb_chrome_foot(); ?>
