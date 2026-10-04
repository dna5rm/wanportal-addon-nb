<?php
/**
 * reports/zones.php — NetBox prefix zones report.
 *
 * Lists prefixes whose mask is shorter than $zonesMaskLengthLt. The NetBox
 * filter is mask_length__lt. Columns follow the IPControl
 * zones report, with NetBox fields in place of the old site/type columns:
 *   Scope         scope name, then tag names, comma-separated
 *   CIDR          the prefix as stored
 *   Network Type  role.name
 *   Zone          custom field firewall_zone, drawn as a color chicklet
 *
 * ?embed=1 drops the topnav. ?format=json pages the rows. ?format=json&all=1
 * returns the full set as a bare array. Unreachable NetBox renders the
 * table empty plus a warning — it does not die().
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

// Prefixes with a mask shorter than this length. Change this one value
// to move the cut. NetBox filter: mask_length__lt.
$zonesMaskLengthLt = 24;

function zones_fetch_page(string $endpoint, string $token, int $timeout = 30): array {
    $scheme = 'Tok' . 'en';
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $endpoint,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
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
        throw new RuntimeException('NetBox returned a response that was not JSON for ' . $endpoint . '.');
    }
    return $data;
}

function zones_scope_name($scope): string {
    if (!is_array($scope)) {
        return '';
    }
    return trim((string) ($scope['name'] ?? $scope['display'] ?? ''));
}

function zones_tag_list(array $prefix): string {
    $names = [];
    foreach ($prefix['tags'] ?? [] as $tag) {
        if (!is_array($tag)) {
            continue;
        }
        $name = trim((string) ($tag['name'] ?? ''));
        if ($name !== '') {
            $names[] = $name;
        }
    }
    return implode(', ', $names);
}

function zones_scope_cell(string $scope, string $tags): string {
    $parts = [];
    if ($scope !== '') {
        $parts[] = $scope;
    }
    if ($tags !== '') {
        $parts[] = $tags;
    }
    return implode(', ', $parts);
}

function zones_role_name($role): string {
    if (!is_array($role)) {
        return '';
    }
    return trim((string) ($role['name'] ?? $role['display'] ?? ''));
}

/**
 * firewall_zone is a color word, sometimes with a suffix (GREEN-dmz).
 * The chicklet uses the color only.
 */
function zones_color($value): string {
    if (!is_scalar($value)) {
        return '';
    }
    $raw = trim((string) $value);
    if ($raw === '') {
        return '';
    }
    $part = strtoupper(explode('-', $raw, 2)[0]);
    $part = preg_replace('/[^A-Z]/', '', $part);
    return is_string($part) ? $part : '';
}

function zones_row(array $prefix): ?array {
    $cidr = trim((string) ($prefix['prefix'] ?? ''));
    if ($cidr === '') {
        return null;
    }
    $tags = zones_tag_list($prefix);
    return [
        'scope'        => zones_scope_cell(zones_scope_name($prefix['scope'] ?? null), $tags),
        'tags'         => $tags,
        'cidr'         => $cidr,
        'network_type' => zones_role_name($prefix['role'] ?? null),
        'zone'         => zones_color(($prefix['custom_fields'] ?? [])['firewall_zone'] ?? null),
    ];
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
            $page = zones_fetch_page(
                $base . '/api/ipam/prefixes/?mask_length__lt=' . (int) $zonesMaskLengthLt . '&limit=' . $limit . '&offset=' . $offset,
                NETBOX_TOKEN,
                30
            );
            $results = is_array($page['results'] ?? null) ? $page['results'] : [];
            $count = (int) ($page['count'] ?? count($results));
            foreach ($results as $prefix) {
                if (!is_array($prefix)) {
                    continue;
                }
                $row = zones_row($prefix);
                if ($row !== null) {
                    $rows[] = $row;
                }
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
        error_log('zones.php: ' . $e->getMessage());
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
    'Zones',
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
    .bar .btn:hover { border-color: var(--muted); }
    .badge.bg-orange {
        background-color: #fd7e14 !important;
        color: #212529 !important;
    }
    .badge.bg-black {
        background-color: #000 !important;
        color: #f8f9fa !important;
        box-shadow: inset 0 0 0 1px #8b95a1;
    }'
); ?>
<?php if (empty($embedMode)) { nb_chrome_topnav('zones'); } ?>

<div class="container-fluid">
    <header class="bar">
        <div class="bar-title">
            <h1>Zones</h1>
        </div>
        <div class="bar-right">
            <span id="zoneStatus" class="muted">Querying NetBox for prefixes…</span>
            <a href="/nb/reports/zones.php?format=json&amp;all=1" class="btn" target="_blank" rel="noopener" title="View raw API data">
                <i class="bi bi-code-slash"></i> Raw Data
            </a>
        </div>
    </header>

    <div id="zoneWarn" class="alert alert-warning" role="alert" hidden></div>

    <div class="row">
        <table id="zoneReport" class="table table-striped table-bordered">
            <thead>
                <tr>
                    <th>Scope</th>
                    <th>CIDR</th>
                    <th>Network Type</th>
                    <th class="text-center">Zone</th>
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
        var badges = {
            GREEN: 'bg-success',
            YELLOW: 'bg-warning text-dark',
            ORANGE: 'bg-orange text-dark',
            RED: 'bg-danger',
            BLACK: 'bg-black'
        };
        function cell(value) {
            return value ? value : dash;
        }
        function badge(color) {
            if (!color) {
                return dash;
            }
            var cls = badges[color] || 'bg-secondary';
            return '<span class="badge ' + cls + '">' + color + '</span>';
        }
        var table = $('#zoneReport').DataTable({
            pageLength: 25,
            order: [[0, 'asc']],
            columnDefs: [{
                targets: 3,
                className: 'text-center',
                render: function (data, type) {
                    if (type !== 'display') {
                        return data || '';
                    }
                    return badge(data);
                }
            }],
            language: { emptyTable: 'Querying NetBox…' }
        });
        var status = document.getElementById('zoneStatus');
        var warn = document.getElementById('zoneWarn');
        function showWarn(text) {
            warn.hidden = false;
            warn.textContent = text;
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
                            cell(row.scope),
                            cell(row.cidr),
                            cell(row.network_type),
                            row.zone || ''
                        ]);
                    });
                    table.draw(false);
                    var loaded = Math.min(data.next || 0, data.count || 0);
                    var total = data.count || loaded;
                    if (!data.done) {
                        status.textContent = 'Loading prefixes ' + loaded + ' of ' + total + '…';
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
