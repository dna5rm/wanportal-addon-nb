<?php
/**
 * reports/sites.php - NetBox sites table for the wanportal-addon-nb sidecar.
 * Lists every active NetBox site (dcim/sites) as a searchable DataTable:
 * ID, site name, description, physical and shipping addresses. Each row has
 * an info icon that opens a modal with the remaining fields (ASN, facility,
 * contact, time zone) and a Leaflet map when coordinates exist. Config
 * comes from the sidecar config.php when present, else NETBOX_URL and
 * NETBOX_TOKEN from the environment; without them the page renders a
 * standalone error page instead of the table. Rendered through the shared
 * sidecar chrome (src/chrome.php): ?embed=1 drops the topnav for iframe
 * use, ?format=json returns the raw rows.
 */

error_reporting(E_ALL);
ini_set('display_errors', '0');

// -------------------------------------------------------------------------
// Configuration
// -------------------------------------------------------------------------
// Sidecar-generated config (defines NETBOX_URL / VAULT_PASS via getenv).
$nb_config_file = '/var/www/localhost/htdocs/nb/config.php';
if (is_readable($nb_config_file)) {
    require_once $nb_config_file;
}

// NetBox settings: prefer existing defines, else environment.
if (!defined('NETBOX_URL')) {
    define('NETBOX_URL', getenv('NETBOX_URL') ?: '');
}
if (!defined('NETBOX_TOKEN')) {
    define('NETBOX_TOKEN', getenv('NETBOX_TOKEN') ?: '');
}

/**
 * Emit a clean standalone HTML error page (no PHP fatal output).
 */
function renderHtmlError(string $title, string $message): void {
    $title = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $message = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
    header('Content-Type: text/html; charset=UTF-8');
    http_response_code(500);
    echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>{$title}</title>
<style>
/* Theme tokens duplicated from the wanportal SPA (both light and dark) so
   this standalone error page follows the SPA palette in either theme.
   No page-level scheme override — the chrome/host owns the theme. */
:root {
    --bg: #0e1319; --panel: #161d26; --panel-edge: #232c38;
    --text: #cdd6e0; --muted: #7c8b9c; --up: #4cc38a; --warn: #d9a13b;
    --danger: #e5534b; --up-bg: rgba(76, 195, 138, .12);
    --warn-bg: rgba(217, 161, 59, .14); --danger-bg: rgba(229, 83, 75, .14);
    --stale: #b085f5; --hover: rgba(255, 255, 255, .04);
    --shadow: 0 6px 18px rgba(0, 0, 0, .35);
}
html[data-theme="light"] {
    --bg: #eef1f5; --panel: #ffffff; --panel-edge: #d3dae3;
    --text: #24303e; --muted: #5d6b7d; --stale: #7c52c7;
    --hover: rgba(23, 43, 66, .06); --shadow: 0 6px 18px rgba(31, 45, 62, .14);
}
body { margin:0; padding:2rem; background:var(--bg); color:var(--text);
       font:13px/1.45 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif; }
.alert-danger { background:var(--danger-bg); color:var(--danger);
                border:1px solid var(--danger); border-radius:8px; padding:1rem; }
h4 { color:var(--text); }
</style>
</head>
<body>
<div style="max-width:640px;margin-top:4rem;">
    <div class="alert-danger" role="alert">
        <h4 class="alert-heading">{$title}</h4>
        <p class="mb-0">{$message}</p>
    </div>
</div>
</body>
</html>
HTML;
    exit;
}

// Hard requirement: a NetBox API token.
if (NETBOX_TOKEN === '') {
    renderHtmlError(
        'NetBox sites report unavailable',
        'NETBOX_TOKEN is not configured for this report. Please contact the administrator.'
    );
}
if (NETBOX_URL === '') {
    renderHtmlError(
        'NetBox sites report unavailable',
        'NETBOX_URL is not configured for this report. Please contact the administrator.'
    );
}

/**
 * Fetch all paginated results from a NetBox API endpoint.
 *
 * @param string $endpoint  Full URL to the API endpoint (with any query params)
 * @param string $token     NetBox API token
 * @return array            Flat array of all result objects
 * @throws Exception        On cURL or HTTP errors
 */
function fetchNetboxData(string $endpoint, string $token): array {
    $allResults = [];
    $url = $endpoint;

    do {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Token ' . $token,
                'Content-Type: application/json',
                'Accept: application/json'
            ],
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        ]);

        $response  = curl_exec($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            throw new Exception("cURL error: {$curlError}");
        }
        if ($httpCode !== 200) {
            throw new Exception("NetBox API returned HTTP {$httpCode}");
        }

        $data = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("Failed to parse NetBox API response: " . json_last_error_msg());
        }

        $allResults = array_merge($allResults, $data['results'] ?? []);

        // Follow pagination
        $url = $data['next'] ?? null;

    } while ($url !== null);

    return $allResults;
}

/**
 * Normalise a raw NetBox site record into a flat associative array
 * matching the field names used in the original IPControl report.
 */
function normaliseSite(array $site): array {
    $cf = $site['custom_fields'] ?? [];

    // ASN: NetBox stores a list of ASN objects
    $asn = '';
    if (!empty($site['asns']) && is_array($site['asns'])) {
        $asn = implode(', ', array_column($site['asns'], 'asn'));
    }

    // Legacy custom site_id if present; otherwise fall back to name/slug
    // so OSPREY sites without a site_id custom field still appear.
    $id = $cf['site_id'] ?? '';
    if ((string)$id === '') {
        $id = $site['name'] ?? $site['slug'] ?? '';
    }

    return [
        'ID'          => $id,
        'SITE'        => $site['name']           ?? '',
        'DESCRIPTION' => $site['description']    ?? '',
        'CONTACT'     => $cf['contact']          ?? '',
        'ASN'         => $asn,
        'FACILITY'    => $site['facility']       ?? '',
        'LATITUDE'    => $site['latitude']       ?? '',
        'LONGITUDE'   => $site['longitude']      ?? '',
        'PHYSICAL'    => $site['physical_address']  ?? '',
        'SHIPPING'    => $site['shipping_address']  ?? '',
        'TZ'          => $site['time_zone']      ?? '',
    ];
}

// -------------------------------------------------------------------------
// Main logic
// -------------------------------------------------------------------------
$sites    = [];
$errorMsg = null;

try {
    $apiUrl   = rtrim(NETBOX_URL, '/') . '/api/dcim/sites/?limit=500&status=active';
    $rawSites = fetchNetboxData($apiUrl, NETBOX_TOKEN);

    // Include ALL active sites — no site_id filter for OSPREY NetBox.
    foreach ($rawSites as $site) {
        $sites[] = normaliseSite($site);
    }

    // Sort by ID ascending (site_id where set, else name/slug)
    usort($sites, fn($a, $b) => strnatcasecmp($a['ID'], $b['ID']));

} catch (Exception $e) {
    $errorMsg = $e->getMessage();
}

// Handle JSON / raw-data request
if (isset($_GET['format']) && $_GET['format'] === 'json') {
    header('Content-Type: application/json');
    if ($errorMsg) {
        echo json_encode(['error' => $errorMsg], JSON_PRETTY_PRINT);
    } else {
        echo json_encode($sites, JSON_PRETTY_PRINT);
    }
    exit;
}

// NetBox UI link — derived from the configured NetBox URL, not hardcoded.
$netboxUiUrl = rtrim(NETBOX_URL, '/') . '/dcim/sites/';

// Shared sidecar chrome (same chrome.php as the certs frontend):
// head/topnav/foot, SPA color tokens, dark theme, same-tab links.
// Embed mode (?embed): same head/foot, no topnav — the embedding host
// already renders its own header, so don't render a second one.
require_once __DIR__ . '/../src/chrome.php';
$embedMode = isset($_GET['embed']);
?>
<?php nb_chrome_head(
    strtoupper(explode('.', $_SERVER['SERVER_NAME'] ?? 'NETPING')[0] ?? 'NETPING') . ' :: Network Sites',
    [
        '<meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">',
        '<meta http-equiv="Pragma" content="no-cache">',
        '<meta http-equiv="Expires" content="0">',
        '<meta http-equiv="refresh" content="300">',
        '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">',
        '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">',
        '<link rel="stylesheet" href="https://cdn.datatables.net/1.11.5/css/dataTables.bootstrap5.min.css">',
        '<link rel="stylesheet" href="https://unpkg.com/leaflet@1.7.1/dist/leaflet.css">',
    ],
    // SPA listing header bar (ui/src/styles/base.css): 17px h1 + flat .btn
    // header buttons, same treatment as the certs page. Scoped to .bar so
    // DataTables / modal controls keep their bootstrap styling.
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
<?php if (empty($embedMode)) { nb_chrome_topnav('sites'); } ?>

<div class="container-fluid">

    <!-- Header Row: SPA listing bar (17px h1, flat .btn actions, same tab) -->
    <header class="bar">
        <div class="bar-title">
            <h1>sites</h1>
        </div>
        <div class="bar-right">
            <?php if (isset($_SERVER['HTTP_REFERER'])): ?>
                <a href="<?= htmlspecialchars($_SERVER['HTTP_REFERER']) ?>" class="btn">
                    <i class="bi bi-arrow-left"></i> Back
                </a>
            <?php endif; ?>
            <a href="/index.php" class="btn d-none">
                <i class="bi bi-house-door"></i> Home
            </a>
            <a href="<?= htmlspecialchars($netboxUiUrl) ?>" class="btn"
               title="Open in NetBox" data-bs-toggle="tooltip">
                <i class="bi bi-box-arrow-up-right"></i> NetBox
            </a>
        </div>
    </header>

    <!-- Error Alert -->
    <?php if ($errorMsg): ?>
        <div class="alert alert-danger" role="alert">
            <i class="bi bi-exclamation-triangle-fill"></i>
            <strong>Error fetching data from NetBox:</strong>
            <?= htmlspecialchars($errorMsg) ?>
        </div>
    <?php endif; ?>

    <!-- Data Table -->
    <div class="row">
        <?php if (!empty($sites)): ?>
            <table id="siteReport" class="table table-striped table-bordered">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Site</th>
                        <th>Description</th>
                        <th>Physical Address</th>
                        <th>Shipping Address</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($sites as $idx => $row): ?>
                        <tr data-idx="<?= (int)$idx ?>">
                            <td><?= htmlspecialchars($row['ID']) ?></td>
                            <td>
                                <?= htmlspecialchars($row['SITE']) ?>
                                <i class="bi bi-info-circle text-info site-info"
                                   data-site-info='<?= htmlspecialchars(json_encode($row), ENT_QUOTES, 'UTF-8') ?>'
                                   style="cursor:pointer;"></i>
                            </td>
                            <td><?= htmlspecialchars($row['DESCRIPTION']) ?></td>
                            <td><?= nl2br(htmlspecialchars($row['PHYSICAL'])) ?></td>
                            <td><?= nl2br(htmlspecialchars($row['SHIPPING'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php elseif (!$errorMsg): ?>
            <p class="text-muted">No sites found.</p>
        <?php endif; ?>
    </div>

    <!-- Site Detail Modal -->
    <div class="modal fade" id="siteModal" tabindex="-1" aria-labelledby="siteModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="siteModalLabel">Site Information</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <!-- Dynamically populated -->
                </div>
            </div>
        </div>
    </div>

</div><!-- /.container-fluid -->

<!-- JS dependencies -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.datatables.net/1.11.5/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.11.5/js/dataTables.bootstrap5.min.js"></script>
<script src="https://unpkg.com/leaflet@1.7.1/dist/leaflet.js"></script>

<script>
$(document).ready(function () {

    // Initialise DataTable
    $('#siteReport').DataTable({
        pageLength: 25,
        order: [[0, 'asc']],
        dom: '<"row"<"col-sm-6"l><"col-sm-6"f>>rtip'
    });

    // Initialise Bootstrap tooltips
    $('[data-bs-toggle="tooltip"]').tooltip();

    // Site info icon click → populate and show modal
    $(document).on('click', '.site-info', function () {
        var siteData = JSON.parse($(this).attr('data-site-info'));
        var rowIdx   = $(this).closest('tr').data('idx');
        var mapId    = 'map-' + rowIdx;   // row index — safe even if the site ID has spaces

        var modalContent = `
            <h5>${escHtml(siteData.SITE)}
                <small class="text-muted">${escHtml(siteData.DESCRIPTION)}</small>
            </h5>
            <hr>
            <dl class="row">
                <dt class="col-sm-4">Site ID</dt>
                <dd class="col-sm-8">${escHtml(siteData.ID)}</dd>

                <dt class="col-sm-4">BGP AS</dt>
                <dd class="col-sm-8">${escHtml(siteData.ASN || '—')}</dd>

                <dt class="col-sm-4">Facility</dt>
                <dd class="col-sm-8">${escHtml(siteData.FACILITY || '—')}</dd>

                <dt class="col-sm-4">Time Zone</dt>
                <dd class="col-sm-8">${escHtml(siteData.TZ || '—')}</dd>

                <dt class="col-sm-4">Contact</dt>
                <dd class="col-sm-8">${escHtml(siteData.CONTACT || '—')}</dd>

                <dt class="col-sm-4">Physical Address</dt>
                <dd class="col-sm-8"><address>${escHtml(siteData.PHYSICAL || '—')}</address></dd>

                <dt class="col-sm-4">Shipping Address</dt>
                <dd class="col-sm-8"><address>${escHtml(siteData.SHIPPING || '—')}</address></dd>
            </dl>
            <div id="${mapId}" style="height:220px; width:100%; margin-top:10px;"></div>
        `;

        $('#siteModal .modal-body').html(modalContent);
        $('#siteModalLabel').text(siteData.SITE + ' — ' + siteData.ID);

        var $modal = $('#siteModal');
        $modal.off('shown.bs.modal').on('shown.bs.modal', function () {
            var lat = parseFloat(siteData.LATITUDE);
            var lng = parseFloat(siteData.LONGITUDE);
            var mapDiv = document.getElementById(mapId);

            if (mapDiv && !isNaN(lat) && !isNaN(lng) && lat !== 0 && lng !== 0) {
                // Destroy previous Leaflet instance if present
                if (mapDiv._leaflet_id) {
                    mapDiv._leaflet_map && mapDiv._leaflet_map.remove();
                }
                var map = L.map(mapDiv, { center: [lat, lng], zoom: 13 });
                mapDiv._leaflet_map = map;

                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
                }).addTo(map);

                L.marker([lat, lng])
                    .addTo(map)
                    .bindPopup(escHtml(siteData.SITE))
                    .openPopup();

                setTimeout(function () { map.invalidateSize(true); }, 150);
            } else {
                $('#' + mapId).html(
                    '<div class="alert alert-warning mb-0">No location data available for this site.</div>'
                );
            }
        });

        $modal.modal('show');
    });

    // Simple HTML escaping helper
    function escHtml(str) {
        if (str === null || str === undefined) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }
});
</script>
<?php nb_chrome_foot(); ?>