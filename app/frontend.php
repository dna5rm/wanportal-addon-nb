<?php
/**
 * frontend.php - CERTMGR :: SSL Certificate Manager (wanportal /nb/ sidecar)
 *
 * Page chrome (head, topnav, foot) comes from src/chrome.php: the SPA dark
 * tokens + topnav, with same-tab internal links. This file owns the page
 * bar, the cert form pane and the output console.
 *
 * Layout contract (mirrors the SPA listings in ui/src/styles/base.css):
 *   - ONE .bar header; the output pane adds no second h1
 *   - .bar aligns center so the h1 and the bar-right buttons share a line
 *   - embed (?embed=1): the SPA shell (AddonFrame) sizes this iframe at
 *     calc(100vh - 56px), so the page fills exactly 100% of the frame —
 *     html/body height:100%, zero body padding (chrome's 0 18px 30px is
 *     overridden here), overflow:hidden; .page-col is height:100% with
 *     min-height:0 so the panes scroll internally, never the document
 *   - standalone: the chrome topnav takes ~60px, so .page-col is
 *     calc(100vh - 60px)
 *   - .form-control carries its own token face (this page loads no
 *     bootstrap css): --bg fill, --text ink, --panel-edge border
 *   - action tabs are real buttons carrying data-action in a flex row;
 *     the active tab is bordered --up — no <a> nav-links, no link styling
 *   - 8px vertical rhythm throughout the form so every field plus the Run
 *     submit fit a ~520px embed frame; the submit row pins to the bottom of
 *     the left pane via margin-top:auto and #form-pane is overflow:hidden
 *     (fields must fit — no inner scrollbar)
 */

require_once __DIR__ . '/src/chrome.php';

if (file_exists(__DIR__ . '/config.php')) {
    require_once __DIR__ . '/config.php';
}

$host  = explode('.', (string) ($_SERVER['SERVER_NAME'] ?? ''))[0];
$label = preg_match('/^[A-Za-z]+$/', $host) ? strtoupper($host) : 'CERTMGR';
$pageTitle = $label . ' :: SSL Certificate Manager';

wanportal_addon_head($pageTitle);
wanportal_addon_topnav('certs');
?>
    <style>
        /* Page frame: the bar header sits on top, the two panes share the
         * rest of the frame. Embed fills the iframe (the SPA shell already
         * subtracted its 56px topnav — 100vh here would double-count it and
         * chrome's body padding would spill past the frame); standalone
         * subtracts the chrome topnav (~60px) instead. */
<?php if (nb_chrome_embed()): ?>
        html, body {
            height: 100%;
            margin: 0;
            padding: 0;
            overflow: hidden;
        }

        .page-col {
            height: 100%;
            min-height: 0;
        }
<?php else: ?>
        .page-col {
            height: calc(100vh - 60px);
        }
<?php endif; ?>

        .page-col {
            display: flex;
            flex-direction: column;
            min-height: 0;
        }

        .app-body {
            display: flex;
            flex: 1;
            min-height: 0;
        }

        /* Page header bar — mirrors the SPA .bar in ui/src/styles/base.css,
         * center-aligned so h1 and the right-hand controls share one line. */
        .bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 8px;
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

        /* Buttons (this page ships no bootstrap css): the SPA .btn look.
         * text-decoration:none keeps anchor buttons from reading as links. */
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            background: var(--panel);
            color: var(--text);
            border: 1px solid var(--panel-edge);
            border-radius: 6px;
            padding: 3px 10px;
            font-family: inherit;
            font-size: 12px;
            line-height: 1.45;
            cursor: pointer;
            text-decoration: none;
        }

        .btn:hover { border-color: var(--muted); color: var(--text); }

        /* Status badge: setStatus() emits the bootstrap subtle-family classes;
         * token them here so the badge reads on both themes. */
        .badge {
            display: inline-block;
            border-radius: 6px;
            padding: 3px 10px;
            font-size: 12px;
            background: var(--panel);
            color: var(--muted);
            border: 1px solid var(--panel-edge);
        }

        .badge.bg-secondary-subtle { background: var(--panel); color: var(--muted); border-color: var(--panel-edge); }
        .badge.bg-success-subtle   { background: var(--up-bg); color: var(--up); border-color: var(--up); }
        .badge.bg-warning-subtle   { background: var(--warn-bg); color: var(--warn); border-color: var(--warn); }
        .badge.bg-danger-subtle    { background: var(--danger-bg); color: var(--danger); border-color: var(--danger); }

        /* Left pane: fixed 380px form column; flex column with min-height:0.
         * overflow:hidden — fields are compact (8px rhythm) so the Run submit
         * fits a ~520px embed frame with NO inner scrollbar; the pane never
         * scrolls. Both panes share the same 12px padding so tops align. */
        #form-pane {
            width: 380px;
            min-width: 320px;
            flex-shrink: 0;
            background: var(--panel);
            border-right: 1px solid var(--panel-edge);
            color: var(--text);
            display: flex;
            flex-direction: column;
            min-height: 0;
            padding: 12px;
            overflow: hidden;
            position: relative;
        }

        #form-overlay {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: color-mix(in srgb, var(--bg) 82%, transparent);
            display: none;
            z-index: 1000;
            align-items: center;
            justify-content: center;
            flex-direction: column;
        }

        #form-overlay.active {
            display: flex;
        }

        .spinner-border {
            width: 3rem;
            height: 3rem;
            box-sizing: border-box;
            border: 3px solid var(--panel-edge);
            border-top-color: var(--up);
            border-radius: 50%;
            animation: cert-spin 0.9s linear infinite;
        }

        @keyframes cert-spin { to { transform: rotate(360deg); } }

        #form-overlay p {
            margin-top: 15px;
            font-weight: bold;
            color: var(--text);
        }

        /* Action tabs: real buttons in a flex row (data-action carries the
         * switch); the active tab reads with the --up border. No nav-links,
         * no link styling — these never navigate. */
        .action-tabs {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 8px;
        }

        .action-tabs .btn { padding: 4px 8px; }
        .action-tabs .btn.active { border-color: var(--up); }

        /* 8px vertical rhythm across the form — compact enough that the
         * submit row fits the ~520px embed frame without scrolling. */
        .form-group {
            margin-bottom: 8px;
        }

        #token-group .form-group {
            margin-bottom: 0;
        }

        .form-group label {
            display: block;
            font-weight: 500;
            font-size: 13px;
            margin-bottom: 4px;
        }

        /* Field face: this page loads no bootstrap css, so .form-control
         * must paint itself with the SPA tokens. */
        .form-control {
            display: block;
            width: 100%;
            box-sizing: border-box;
            padding: 5px 10px;
            font-family: inherit;
            font-size: 13px;
            background: var(--bg);
            color: var(--text);
            border: 1px solid var(--panel-edge);
            border-radius: 6px;
        }

        .alert-info {
            margin-bottom: 8px;
            padding: 6px 10px;
            border: 1px solid var(--up);
            border-radius: 6px;
            font-size: 12px;
        }

        #token-group {
            margin-bottom: 8px;
            padding-bottom: 8px;
            border-bottom: 1px solid var(--panel-edge);
        }

        /* Submit pins to the bottom of the left pane. */
        .submit-row {
            margin-top: auto;
            padding-top: 8px;
        }

        #submit-btn {
            background: var(--up);
            border-color: var(--up);
            /* Ink on the theme-invariant --up fill stays literal (chrome css
             * convention) so it reads on green in both themes. */
            color: #0e1319;
            width: 100%;
            padding: 7px 10px;
            font-size: 13px;
        }

        #submit-btn:hover:not(:disabled) {
            background: var(--up);
            border-color: var(--text);
        }

        #submit-btn:disabled {
            opacity: 0.65;
            cursor: not-allowed;
        }

        /* Right pane: the console fills every pixel under the bar; no second
         * heading here — the page bar is the only h1. */
        #output-pane {
            flex: 1;
            min-width: 0;
            min-height: 0;
            display: flex;
            flex-direction: column;
            padding: 12px;
            overflow: hidden;
        }

        #output-area {
            flex: 1;
            min-height: 0;
            width: 100%;
            box-sizing: border-box;
            font-family: monospace;
            font-size: 12px;
            resize: none;
            background-color: var(--panel);
            color: var(--text);
            border: 1px solid var(--panel-edge);
            border-radius: 4px;
            padding: 15px;
            overflow-y: auto;
            white-space: pre-wrap;
        }

        #output-area::placeholder {
            color: var(--muted);
            opacity: 1;
        }
    </style>

    <div class="page-col">

        <header class="bar">
            <div class="bar-title">
                <h1>SSL certificates</h1>
            </div>
            <div class="bar-right">
                <span id="status-badge" class="badge bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle">Idle</span>
                <a href="./swagger" class="btn" title="API Documentation" data-bs-toggle="tooltip">API</a>
                <button id="clear-btn" class="btn" type="button">Clear</button>
            </div>
        </header>

        <div class="app-body">

        <!-- Left Pane: Form -->
        <div id="form-pane">
            <div id="form-overlay">
                <div class="spinner-border" role="status"></div>
                <p id="overlay-message">Running...</p>
            </div>

            <!-- NetBox Token -->
            <div id="token-group">
                <div class="form-group">
                    <label for="netbox-token">NetBox API Token</label>
                    <input type="password" class="form-control" id="netbox-token" placeholder="Enter your NetBox API token" required>
                </div>
            </div>

            <!-- Action Tabs -->
            <div class="action-tabs" id="action-tabs">
                <button type="button" class="btn active" data-action="build">CSR</button>
                <button type="button" class="btn" data-action="show">Show</button>
                <button type="button" class="btn" data-action="import">Import</button>
                <button type="button" class="btn" data-action="selfsign">Self-Sign</button>
            </div>

            <!-- Common Name (all actions) -->
            <div class="form-group">
                <label for="common-name" data-bs-toggle="tooltip" title="The FQDN for the SSL certificate e.g. myapp.example.com">Common Name</label>
                <input type="text" class="form-control" id="common-name" placeholder="myapp.example.com" required>
            </div>

            <!-- Build Fields -->
            <div id="build-fields">
                <div class="form-group">
                    <label for="owner-group" data-bs-toggle="tooltip" title="Team or department responsible for this certificate.">Owner Group</label>
                    <input type="text" class="form-control" id="owner-group" placeholder="IT-OPS">
                </div>
                <div class="form-group">
                    <label for="reference-number" data-bs-toggle="tooltip" title="Optional IDM reference number.">Reference Number</label>
                    <input type="text" class="form-control" id="reference-number" placeholder="Optional">
                </div>
                <div class="form-group">
                    <label for="subject-alt-names" data-bs-toggle="tooltip" title="Additional domains space separated.">Subject Alt Names</label>
                    <input type="text" class="form-control" id="subject-alt-names" placeholder="myapp1.example.com *.myapp.example.com">
                </div>
                <div class="alert alert-info">
                    Once you receive your signed certificate from the CA, use the <strong>Import</strong> tab to import it.
                </div>
            </div>

            <!-- Import Fields -->
            <div id="import-fields" style="display: none;">
                <div class="form-group">
                    <label for="certificate" data-bs-toggle="tooltip" title="Paste your signed PEM certificate here.">Certificate</label>
                    <textarea class="form-control" id="certificate" rows="8" placeholder="-----BEGIN CERTIFICATE-----"></textarea>
                </div>
            </div>

            <!-- Submit -->
            <div class="submit-row">
                <button id="submit-btn" class="btn" type="button">Run: Build</button>
            </div>

        </div>

        <!-- Right Pane: Output -->
        <div id="output-pane">
            <textarea id="output-area" readonly placeholder="Output will appear here..."></textarea>
        </div>

    </div>

    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        $(document).ready(function () {

            const TIMEOUT_MS = 300000; // 5 minutes
            let currentAction = 'build';
            let requestTimeout = null;

            // --- Token persistence (client-side only) ---
            // The API credential lives in this page's localStorage under the
            // SPA's own origin (the sidecar is reverse-proxied from the same
            // host as the SPA, so /nb/ inside the AddonFrame iframe shares
            // that store) — the value therefore survives iframe reloads. It
            // is never written by PHP, never logged, and only sent
            // per-request in the Authorization header.
            const TOKEN_STORAGE_KEY = 'wanportal-nb-token';
            const storedToken = localStorage.getItem(TOKEN_STORAGE_KEY);
            if (storedToken) {
                $('#netbox-token').val(storedToken);
            }
            $('#netbox-token').on('input change blur', function () {
                const value = $(this).val().trim();
                if (value) {
                    localStorage.setItem(TOKEN_STORAGE_KEY, value);
                } else {
                    localStorage.removeItem(TOKEN_STORAGE_KEY);
                }
            });

            // Init tooltips (labels carry title= — no icons on labels)
            $('[data-bs-toggle="tooltip"]').each(function () {
                new bootstrap.Tooltip(this);
            });

            // --- Tab switching (buttons carry data-action) ---
            $('#action-tabs button[data-action]').on('click', function () {
                $('#action-tabs button[data-action]').removeClass('active');
                $(this).addClass('active');

                currentAction = $(this).data('action');
                $('#submit-btn').text('Run: ' + capitalise(currentAction));

                $('#build-fields').toggle(currentAction === 'build' || currentAction === 'selfsign');
                $('#reference-number').closest('.form-group').toggle(currentAction === 'build');
                $('#import-fields').toggle(currentAction === 'import');

                clearOutput();
            });

            // --- Submit ---
            $('#submit-btn').on('click', function () {
                const token       = $('#netbox-token').val().trim();
                const common_name = $('#common-name').val().trim().toLowerCase();

                if (!token) {
                    showOutput('ERROR: NetBox API token is required.');
                    return;
                }

                if (!common_name) {
                    showOutput('ERROR: Common Name is required.');
                    return;
                }

                let payload  = { common_name: common_name };
                let endpoint = './api/' + currentAction;

                if (currentAction === 'build' || currentAction === 'selfsign') {
                    const owner_group = $('#owner-group').val().trim();
                    if (!owner_group) {
                        showOutput('ERROR: Owner Group is required.');
                        return;
                    }
                    payload.owner_group = owner_group;

                    if (currentAction === 'build') {
                        const ref = $('#reference-number').val().trim();
                        if (ref) payload.reference_number = ref;
                    }

                    const sans = $('#subject-alt-names').val().trim();
                    if (sans) payload.subject_alt_names = sans.split(/\s+/);
                }

                if (currentAction === 'import') {
                    const cert = $('#certificate').val().trim();
                    if (!cert) {
                        showOutput('ERROR: Certificate is required.');
                        return;
                    }
                    payload.certificate = cert;
                }

                runRequest(endpoint, token, payload);
            });

            // --- Clear button ---
            $('#clear-btn').on('click', function () {
                clearOutput();
            });

            // --- API Request ---
            function runRequest(endpoint, token, payload) {
                setLoading(true, 'Running ' + capitalise(currentAction) + '...');
                clearOutput();
                setStatus('Running', 'warning');

                requestTimeout = setTimeout(function () {
                    setLoading(false);
                    setStatus('Timeout', 'danger');
                    showOutput('ERROR: Request timed out after 5 minutes.');
                }, TIMEOUT_MS);

                fetch(endpoint, {
                    method: 'POST',
                    headers: {
                        'Content-Type':  'application/json',
                        'Authorization': 'Bearer ' + token,
                        'Accept':        'text/plain',
                    },
                    body: JSON.stringify(payload),
                })
                .then(function (response) {
                    return response.text().then(function (data) {
                        return { status: response.status, data: data };
                    });
                })
                .then(function (result) {
                    clearTimeout(requestTimeout);
                    setLoading(false);

                    if (result.status === 200) {
                        setStatus('Success', 'success');
                    } else {
                        setStatus('Error ' + result.status, 'danger');
                    }

                    $('#output-area').val(result.data);
                })
                .catch(function (error) {
                    clearTimeout(requestTimeout);
                    setLoading(false);
                    setStatus('Error', 'danger');
                    showOutput('ERROR: ' + error.message);
                });
            }

            // --- Helpers ---
            function setLoading(active, message) {
                if (active) {
                    $('#overlay-message').text(message || 'Running...');
                    $('#form-overlay').addClass('active');
                    $('#submit-btn').prop('disabled', true);
                } else {
                    $('#form-overlay').removeClass('active');
                    $('#submit-btn').prop('disabled', false);
                }
            }

            function setStatus(text, type) {
                const badge = $('#status-badge');
                badge.text(text);
                // Dark-mode-aware badge family: fixed bg-{color} does not
                // flip with data-bs-theme; the subtle family does.
                badge.attr('class', 'badge bg-' + type + '-subtle text-' + type + '-emphasis border border-' + type + '-subtle');
            }

            function showOutput(text) {
                $('#output-area').val(text);
            }

            function clearOutput() {
                $('#output-area').val('');
                setStatus('Idle', 'secondary');
            }

            function capitalise(str) {
                return str.charAt(0).toUpperCase() + str.slice(1);
            }

        });
    </script>
<?php wanportal_addon_foot(); ?>