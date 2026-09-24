<?php
/**
 * vip.php - VIPBUILDER :: VIP builder (wanportal /nb/ sidecar)
 *
 * Certmgr-style page: the operator types an optional SSL Common Name and a
 * VIP address, adds listeners and members, watches the live diagram, and
 * submits one build to /nb/api/vip.php. This file is ONLY the page: every
 * lookup, validation and write goes through that API with the caller's
 * NetBox token as Bearer. The API stores the build as the vip_build JSON
 * object on the existing NetBox IP address and, when an SSL Common Name is
 * set, links the DNS record both ways: vip_ssl on the IP (the DNS-record
 * object link) and vip_address on the DNS record (the IP object link);
 * without a name, vip_ssl is stored null and the DNS record is not
 * touched. The address lookup's vip_fqdn response field (the hostname
 * behind the vip_ssl link) prefills an empty SSL Common Name field, and
 * the FQDN lookup's address response field (the bare address behind the
 * record's vip_address link) prefills an empty VIP address field and
 * loads the stored build through the address path. The
 * browser never talks to NetBox directly, never calls Tower, and never
 * stores the token server-side.
 *
 * Page chrome (head, topnav, foot) comes from src/chrome.php; the SPA menu
 * child (#/addons/vips) embeds this page with ?embed=1, where the topnav
 * prints nothing (the SPA owns the bar) - exactly one header either way.
 *
 * Layout contract (mirrors frontend.php):
 *   - ONE .bar header with h1 "vip"; the diagram pane adds no second h1
 *   - embed: html/body fill the iframe (height 100%, overflow hidden);
 *     the form pane is the ONE scroll surface, the diagram never scrolls
 *   - standalone: .page-col is calc(100vh - 60px) under the chrome topnav
 *   - token colors only (--bg/--panel/--panel-edge/--text/--muted and the
 *     status hues); no literal page backgrounds in this file
 *
 * Stored vip_build custom field: a JSON object ({"build":{...}}), not a
 * string. The read-only line under the Save button shows its compact
 * one-line JSON, built live, and doubles as the paste-in import box:
 *   {"build":{"limit":[...],"ssl_key_cert":[{"name":"<fqdn>"}]?,"virtual":[...]}}
 *   - no irule key; irules only on a port-80 SSL Redirect listener
 *     (["/Common/_sys_https_redirect"], no clientssl profile on it),
 *     no analytics profile, stock monitors only
 *   - pool object on the owning listener; string pool name on a sibling
 *     that shares the pool (identical member set); mixed member ports on
 *     one listener are rejected
 *   - the virtual/pool name base is the SSL Common Name, or the VIP
 *     address when it is empty (the name is optional unless a listener
 *     carries Client SSL on a non-80 port; cookie persistence is offered
 *     only on a listener with its HTTP box checked)
 *   - F5 object names (profiles, monitors, persistence, the redirect
 *     iRule) come from vip_profile_map() in src/vip_profiles.php -
 *     builtin Common defaults, site-overridable in config.php
 */

require_once __DIR__ . '/src/chrome.php';

if (file_exists(__DIR__ . '/config.php')) {
    require_once __DIR__ . '/config.php';
}

// Site-overridable F5 object names from vip_profile_map() (builtin Common
// defaults): the page script emits and compares exactly these values.
require_once __DIR__ . '/src/vip_profiles.php';

// Configured Tower limit choices (VIP_LIMIT_HOSTS): an empty list keeps
// the free-text textarea; a non-empty list renders the choice multi-select.
// Hostnames live only in config.php / the environment, never here.
$vipLimitOptions = vip_limit_options();

$pageTitle = 'VIP Builder';
$vipProfilesJson = json_encode(vip_profile_map(), JSON_UNESCAPED_SLASHES);

nb_chrome_head($pageTitle);
nb_chrome_topnav('vips');
?>
    <style>
        /* Page frame: the bar header sits on top, the two panes share the
         * rest of the frame. Embed fills the iframe (the SPA shell already
         * subtracted its 56px topnav); standalone subtracts the chrome
         * topnav (~60px) instead. */
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

        /* Page header bar - the SPA .bar look; ONE h1 on this page. */
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

        /* Buttons: the chrome .btn face (this page ships no bootstrap css). */
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

        .btn:hover:not(:disabled) { border-color: var(--muted); color: var(--text); }
        .btn:disabled { opacity: .65; cursor: not-allowed; }

        /* Status badge in the bar-right (token faces, both themes). */
        .badge {
            display: inline-block;
            border-radius: 6px;
            padding: 3px 10px;
            font-size: 12px;
            border: 1px solid var(--panel-edge);
            background: var(--panel);
            color: var(--muted);
        }

        .badge.b-ok     { background: var(--up-bg);     color: var(--up);     border-color: var(--up); }
        .badge.b-warn   { background: var(--warn-bg);   color: var(--warn);   border-color: var(--warn); }
        .badge.b-danger { background: var(--danger-bg); color: var(--danger); border-color: var(--danger); }

        /* Left pane: the ONE scroll surface (embed). Fixed 380px form
         * column like certmgr; rows scroll internally, the page never
         * scrolls. Both panes share the same 12px padding so tops align. */
        #form-pane {
            width: 380px;
            min-width: 340px;
            flex-shrink: 0;
            background: var(--panel);
            border-right: 1px solid var(--panel-edge);
            color: var(--text);
            display: flex;
            flex-direction: column;
            min-height: 0;
            padding: 12px;
            overflow-y: auto;
        }

        #diagram-pane {
            flex: 1;
            min-width: 0;
            min-height: 0;
            padding: 12px;
            overflow: hidden; /* the diagram never gets its own scrollbar */
        }

        /* 8px vertical rhythm across the form. */
        .form-group { margin-bottom: 8px; }

        #token-group {
            margin-bottom: 8px;
            padding-bottom: 8px;
            border-bottom: 1px solid var(--panel-edge);
        }

        .form-group label {
            display: block;
            font-weight: 500;
            font-size: 13px;
            margin-bottom: 4px;
        }

        /* Field face: token colors, no bootstrap css on this page. */
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

        .form-control:focus {
            border-color: var(--up);
            outline: none;
            box-shadow: 0 0 0 .2rem var(--up-bg);
        }

        input[type="checkbox"] { accent-color: var(--up); }

        .mono { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; }

        /* Lookup status rows: the checkbox is a status indicator (never a
         * control) + the short state line under each field. Member rows in
         * a listener reuse the same indicator checkbox (no state line). */
        .check-row {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .check-row input[type="checkbox"],
        .m-row input[type="checkbox"] {
            flex: none;
            width: 15px;
            height: 15px;
            margin: 0;
            cursor: default;
        }

        .check-row .form-control { flex: 1; min-width: 0; }

        .check-line {
            font-size: 12px;
            margin-top: 3px;
            min-height: 16px;
            color: var(--muted);
        }

        .check-line.ok   { color: var(--up); }
        .check-line.miss { color: var(--muted); }
        .check-line.amb  { color: var(--warn); }
        .check-line.err  { color: var(--danger); }

        /* Limit textarea (no configured choices): one hostname per line,
         * nothing prefilled; the configured-choice multi-select is plain
         * .form-control, so the sizing rule stays textarea-only. */
        textarea#limit { resize: vertical; min-height: 54px; }

        /* Listener rows: a compact box per listener with a members list
         * underneath. */
        .row-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            margin-bottom: 4px;
        }

        .row-head label { margin-bottom: 0; }

        .listener-box {
            background: var(--bg);
            border: 1px solid var(--panel-edge);
            border-radius: 6px;
            padding: 8px;
            margin-bottom: 8px;
        }

        .l-row {
            display: flex;
            align-items: flex-end;
            gap: 6px;
            flex-wrap: wrap;
            margin-bottom: 6px;
        }

        .l-field { display: flex; flex-direction: column; min-width: 0; }

        .l-lab {
            font-size: 11px;
            color: var(--muted);
            margin-bottom: 2px;
        }

        .l-w-port { width: 78px; }
        .l-w-mon  { width: 106px; }
        .l-w-pers { width: 116px; }

        .l-check {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            font-size: 12px;
            color: var(--text);
            cursor: pointer;
            padding-bottom: 5px;
        }

        .l-check input { margin: 0; }
        .l-del { margin-left: auto; }

        .l-members-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            margin: 6px 0 4px;
        }

        .m-row {
            display: flex;
            align-items: center;
            gap: 6px;
            margin-bottom: 4px;
        }

        .m-addr { flex: 1; min-width: 0; }
        .m-port { width: 64px; }
        .m-del { padding: 3px 8px; }

        .form-msg {
            font-size: 12px;
            color: var(--danger);
            margin-top: 6px;
        }

        .action-msg {
            font-size: 12px;
            margin-top: 6px;
            min-height: 16px;
            color: var(--muted);
        }

        .action-msg.ok     { color: var(--up); }
        .action-msg.warn   { color: var(--warn); }
        .action-msg.danger { color: var(--danger); }

        /* Submit pins to the bottom of the left pane; the read-only stored
         * string line sits under it (spec order: submit, then the line). */
        .submit-row {
            margin-top: auto;
            padding-top: 8px;
        }

        #submit-btn {
            background: var(--up);
            border-color: var(--up);
            /* Ink on the theme-invariant --up fill stays literal (chrome css
             * convention, the dark --bg value) so it reads on green in both
             * themes. */
            color: #0e1319;
            width: 100%;
            padding: 7px 10px;
            font-size: 13px;
        }

        #submit-btn:hover:not(:disabled) {
            border-color: var(--text);
        }

        #stored-line {
            font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
            font-size: 11px;
            white-space: pre-wrap;
            word-break: break-all;
            resize: vertical;
        }

        /* Right pane: the live diagram. Plain HTML/CSS boxes and connector
         * rails; token colors only, no library, no second heading. */
        #diagram {
            font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
            font-size: 12px;
            line-height: 1.6;
            color: var(--text);
        }

        .dg-empty { color: var(--muted); }

        .dg-node {
            display: inline-block;
            position: relative;
            background: var(--panel);
            color: var(--text);
            border: 1px solid var(--panel-edge);
            border-radius: 6px;
            padding: 2px 8px;
        }

        .dg-limits { display: flex; flex-direction: column; align-items: flex-start; gap: 4px; margin-bottom: 12px; }
        .dg-client { background: var(--up-bg); border-color: var(--up); color: var(--up); max-width: 280px; overflow-wrap: anywhere; }
        .dg-vip    { border-color: var(--up); }
        .dg-strong { font-weight: 600; }
        .dg-none   { color: var(--muted); border-style: dashed; }

        .chip {
            display: inline-block;
            margin-left: 6px;
            padding: 0 6px;
            border: 1px solid var(--panel-edge);
            border-radius: 5px;
            font-size: 11px;
            color: var(--muted);
        }

        .chip-up     { color: var(--up); border-color: var(--up); }
        .chip-shared { color: var(--warn); border-color: var(--warn); }

        /* Connector rails: each nesting level draws a vertical rail; every
         * node hangs off it with a short horizontal tick. */
        .dg-branch { margin-left: 12px; padding-left: 16px; border-left: 1px solid var(--panel-edge); }
        .dg-lists  { margin-top: 6px; padding-left: 16px; border-left: 1px solid var(--panel-edge); }
        .dg-l      { margin: 8px 0; padding-left: 16px; display: flex; flex-direction: column; align-items: flex-start; gap: 4px; }
        .dg-poolw  { margin-left: 10px; margin-top: 2px; padding-left: 16px; border-left: 1px solid var(--panel-edge); }
        .dg-mems   { margin-left: 10px; margin-top: 2px; padding-left: 16px; border-left: 1px solid var(--panel-edge); display: flex; flex-direction: column; align-items: flex-start; gap: 4px; }

        .dg-branch > .dg-node::before,
        .dg-l > .dg-node::before,
        .dg-poolw > .dg-node::before,
        .dg-mems > .dg-node::before {
            content: '';
            position: absolute;
            left: -16px;
            top: 50%;
            width: 12px;
            border-top: 1px solid var(--panel-edge);
        }
    </style>

    <div class="page-col">

        <header class="bar">
            <div class="bar-title">
                <h1>VIP Builder</h1>
            </div>
            <div class="bar-right">
                <span id="status-badge" class="badge">Idle</span>
                <a class="btn" href="/nb/vip-swagger">API</a>
                <button id="clear-btn" class="btn" type="button">Clear</button>
            </div>
        </header>

        <div class="app-body">

        <!-- Left pane: the form (the one scroll surface) -->
        <div id="form-pane">

            <!-- NetBox token (same storage key as certmgr) -->
            <div id="token-group" class="form-group">
                <label for="netbox-token">NetBox API token</label>
                <input type="password" class="form-control" id="netbox-token" placeholder="Enter your NetBox API token" required>
            </div>

            <!-- FQDN with background lookup -->
            <div class="form-group">
                <label for="fqdn">SSL Common Name</label>
                <div class="check-row">
                    <input type="checkbox" id="fqdn-ok" class="lookup-check" disabled>
                    <input type="text" class="form-control" id="fqdn" placeholder="optional, required for Client SSL" autocomplete="off" spellcheck="false">
                </div>
                <div class="check-line" id="fqdn-line">&nbsp;</div>
            </div>

            <!-- VIP address with background lookup -->
            <div class="form-group">
                <label for="vip-address">VIP address</label>
                <div class="check-row">
                    <input type="checkbox" id="addr-ok" class="lookup-check" disabled>
                    <input type="text" class="form-control" id="vip-address" placeholder="192.0.2.10" autocomplete="off" spellcheck="false">
                </div>
                <div class="check-line" id="addr-line">&nbsp;</div>
            </div>

            <!-- Limit: required. With VIP_LIMIT_HOSTS configured the field
                 is a multi-select of the configured choices (option value =
                 the host list, one host per line; a pair stores both hosts;
                 several choices may be selected at once and the stored list
                 is the union of the selected options' hosts); without it, a
                 textarea with one hostname per line. The hostnames
                 themselves stay in config.php / the environment. -->
            <div class="form-group">
                <label for="limit">Limit</label>
<?php if ($vipLimitOptions): ?>
                <select id="limit" multiple size="<?php echo min(4, count($vipLimitOptions)); ?>" class="form-control">
<?php foreach ($vipLimitOptions as $vipLimitOption): ?>
                    <option value="<?php echo str_replace("\n", '&#10;', htmlspecialchars(implode("\n", $vipLimitOption['hosts']), ENT_QUOTES)); ?>"><?php echo htmlspecialchars($vipLimitOption['label'], ENT_QUOTES); ?></option>
<?php endforeach; ?>
                </select>
                <div class="check-line">select one or more pairs.</div>
<?php else: ?>
                <textarea id="limit" class="form-control mono" rows="3" placeholder="one hostname per line"></textarea>
<?php endif; ?>
            </div>

            <!-- Listeners and their members -->
            <div class="form-group">
                <div class="row-head">
                    <label>Listeners</label>
                    <button id="listener-add" class="btn" type="button">+ listener</button>
                </div>
                <div id="listeners"></div>
            </div>

            <div class="form-group">
                <button id="load-btn" class="btn" type="button">Load existing</button>
            </div>

            <div id="form-msg" class="form-msg" hidden></div>
            <div id="action-msg" class="action-msg" hidden></div>

            <!-- Submit + the live compact string (spec order: submit, line) -->
            <div class="submit-row">
                <button id="submit-btn" class="btn" type="button" disabled>Save VIP</button>
                <div class="form-group" style="margin-top: 8px;">
                    <div class="row-head">
                        <label for="stored-line">stored string (vip_build)</label>
                        <button id="import-btn" class="btn" type="button">Import</button>
                    </div>
                    <textarea class="form-control mono" id="stored-line" rows="4" placeholder="paste a build string to import, or this shows the string that will be stored"></textarea>
                </div>
            </div>

        </div>

        <!-- Right pane: the live diagram (never scrolls) -->
        <div id="diagram-pane">
            <div id="diagram"></div>
        </div>

    </div>

    </div>

    <script>
    (function () {
        'use strict';

        var TOKEN_KEY = 'wanportal-nb-token';   // same key as certmgr
        var API = '/nb/api/vip.php';

        // F5 object names from vip_profile_map() in src/vip_profiles.php
        // (builtin Common defaults; a deployment overrides them in
        // config.php). The page emits and compares only these values, so a
        // config override round-trips through load/import unchanged.
        var VIP_PROFILES = <?php echo $vipProfilesJson; ?>;

        // Port 80 + a checked SSL box is the stock SSL Redirect iRule
        // listener, not a clientssl listener.
        function isPort80(port) { return parseInt(port, 10) === 80; }

        function sslLabelFor(port) { return isPort80(port) ? 'SSL Redirect' : 'Client SSL'; }

        // Pool monitor object for the row's monitor selection.
        function monitorNameFor(mon) {
            return (mon === 'ping' ? VIP_PROFILES.monitor_ping : VIP_PROFILES.monitor_tcp) || VIP_PROFILES.monitor_tcp;
        }

        function $(id) { return document.getElementById(id); }

        var tokenInput   = $('netbox-token');
        var fqdnInput    = $('fqdn');
        var addrInput    = $('vip-address');
        var fqdnOk       = $('fqdn-ok');
        var addrOk       = $('addr-ok');
        var fqdnLine     = $('fqdn-line');
        var addrLine     = $('addr-line');
        var limitEl      = $('limit');
        var listenersBox = $('listeners');
        var listenerAdd  = $('listener-add');
        var loadBtn      = $('load-btn');
        var importBtn    = $('import-btn');
        var submitBtn    = $('submit-btn');
        var storedLine   = $('stored-line');
        var formMsg      = $('form-msg');
        var actionMsg    = $('action-msg');
        var badge        = $('status-badge');
        var clearBtn     = $('clear-btn');
        var diagram      = $('diagram');

        // Lookup state: idle | checking | found | missing | ambiguous | error.
        // The VIP address adds mismatch: the IP exists in NetBox but its
        // role/status do not qualify (needs role vip + status active).
        var fqdnState = 'idle', fqdnCount = 0, lastFqdn = null;
        var addrState = 'idle', addrHasBuild = false, lastAddr = null, addrErr = '', addrDetail = '';
        var rawUnparsed = null;   // a loaded vip_build that failed to parse
        var busyFlag = false;
        var fqdnTimer = null, addrTimer = null;

        function norm(s) { return String(s || '').trim().toLowerCase(); }
        function trim(s) { return String(s || '').trim(); }

        function el(tag, cls, text) {
            var n = document.createElement(tag);
            if (cls) n.className = cls;
            if (text !== undefined && text !== null) n.textContent = String(text);
            return n;
        }

        // ---------- limit field (textarea, or multi-select of choices) ----------
        // The PHP page renders the free-text textarea when the deployment
        // configures no VIP_LIMIT_HOSTS choices, else a <select id="limit"
        // multiple> whose option values are the host lists (one host per
        // line). Several options can be selected at once.
        // readLimit()/writeLimit() are the only code that touches the field,
        // so both faces behave identically and build.limit stays an array
        // of hostname strings.
        var limitMode = limitEl && limitEl.tagName === 'SELECT' ? 'select' : 'textarea';

        // The hostnames the limit field currently holds ([] when empty):
        // textarea lines, or the union of the selected options' hosts in
        // config order, de-duplicated (first occurrence wins).
        function readLimit() {
            if (limitMode !== 'select') {
                var raw = String(limitEl.value || '');
                return raw.split('\n').map(trim).filter(function (s) { return s !== ''; });
            }
            var seen = {}, out = [];
            Array.prototype.forEach.call(limitEl.options, function (o) {
                if (!o.selected) return;
                o.value.split('\n').map(trim).filter(function (s) { return s !== ''; })
                    .forEach(function (h) {
                        var k = h.toLowerCase();
                        if (!seen[k]) { seen[k] = true; out.push(h); }
                    });
            });
            return out;
        }

        // Fill the limit field from a host list: textarea text, or — in the
        // multi-select — every option whose hosts are ALL present in the
        // list (an option with a missing host is not selected; an option is
        // never invented). Hosts matching no option queue a short warning
        // naming them for the next status message; the matched selections
        // are not cleared.
        var limitWarnPending = null;

        function writeLimit(hosts) {
            var list = (hosts || []).map(trim).filter(function (s) { return s !== ''; });
            if (limitMode !== 'select') {
                limitEl.value = list.join('\n');
                return;
            }
            var low = function (s) { return s.toLowerCase(); };
            var have = {}, covered = {};
            list.forEach(function (h) { have[low(h)] = true; });
            Array.prototype.forEach.call(limitEl.options, function (o) {
                var oHosts = o.value.split('\n').map(trim).filter(function (s) { return s !== ''; });
                var all = oHosts.length > 0 && oHosts.every(function (h) { return have[low(h)]; });
                o.selected = all;
                if (all) oHosts.forEach(function (h) { covered[low(h)] = true; });
            });
            var unmatched = list.filter(function (h) { return !covered[low(h)]; });
            if (unmatched.length) {
                limitWarnPending = 'loaded limit (' + unmatched.join(', ') + ') not covered by the configured limit choices';
            }
        }

        // ---------- API client (Bearer token, JSON; never NetBox direct) ----------

        function apiCall(method, qs, body) {
            var headers = {
                'Authorization': 'Bearer ' + trim(tokenInput.value),
                'Accept': 'application/json'
            };
            if (body) headers['Content-Type'] = 'application/json';
            return fetch(API + '?' + qs, {
                method: method,
                headers: headers,
                body: body ? JSON.stringify(body) : undefined
            }).then(function (r) {
                return r.text().then(function (t) {
                    var j = null;
                    try { j = t ? JSON.parse(t) : null; } catch (e) { j = null; }
                    return { status: r.status, body: j, text: t };
                });
            });
        }

        function errText(r) {
            if (r && r.body && r.body.error) return String(r.body.error);
            if (r && r.text) return String(r.text).slice(0, 200);
            return 'unknown error';
        }

        // ---------- token persistence (client-side only) ----------

        function restoreToken() {
            var v = null;
            try { v = localStorage.getItem(TOKEN_KEY); } catch (e) { v = null; }
            if (v) tokenInput.value = v;
        }

        function saveToken() {
            var v = trim(tokenInput.value);
            try {
                if (v) localStorage.setItem(TOKEN_KEY, v);
                else localStorage.removeItem(TOKEN_KEY);
            } catch (e) { /* storage may throw; the field still works */ }
            // a new token invalidates previous lookup results
            lastFqdn = null; lastAddr = null;
            scheduleFqdn(); scheduleAddr();
            recheckMembers();
            refreshUI();
        }

        // ---------- background lookups (debounced, never block typing) ----------

        function scheduleFqdn() {
            window.clearTimeout(fqdnTimer);
            fqdnTimer = window.setTimeout(checkFqdn, 450);
        }

        function scheduleAddr() {
            window.clearTimeout(addrTimer);
            addrTimer = window.setTimeout(checkAddress, 450);
        }

        function checkFqdn() {
            var v = norm(fqdnInput.value);
            if (!v || !trim(tokenInput.value)) {
                fqdnState = 'idle'; fqdnCount = 0; lastFqdn = null;
                paintFqdn(); refreshUI();
                return;
            }
            if (v === lastFqdn && fqdnState !== 'checking' && fqdnState !== 'error') return;
            lastFqdn = v;
            fqdnState = 'checking'; paintFqdn();
            apiCall('GET', 'action=fqdn&fqdn=' + encodeURIComponent(v)).then(function (r) {
                if (norm(fqdnInput.value) !== v) return; // stale response
                if (r.status === 200) {
                    fqdnState = 'found'; fqdnCount = 1;
                    // The record's vip_address link names the VIP: hand the
                    // bare address to the existing address path (role/status
                    // gate + fillFromStored) when the address field is empty
                    // or already that address. A focused field holding a
                    // different address is the operator typing - never
                    // clobbered, and the name still reads found. No loop:
                    // checkAddress backfills this name field only while it
                    // is empty, and it is not.
                    var linked = trim((r.body && r.body.address) || '');
                    if (linked && isIp(linked)) {
                        var current = trim(addrInput.value);
                        if (current === '' || current === linked) {
                            addrInput.value = linked;
                            lastAddr = null; // checkAddress must not no-op
                            paintFqdn(); refreshUI();
                            checkAddress();
                            return;
                        }
                    }
                }
                else if (r.status === 404) { fqdnState = 'missing'; fqdnCount = 0; }
                else if (r.status === 409) { fqdnState = 'ambiguous'; fqdnCount = (r.body && r.body.count) || '>1'; }
                else { fqdnState = 'error'; fqdnCount = 0; }
                paintFqdn(); refreshUI();
            }).catch(function () {
                if (norm(fqdnInput.value) !== v) return;
                fqdnState = 'error'; fqdnCount = 0;
                paintFqdn(); refreshUI();
            });
        }

        // Role/status contract of a 200 from GET action=address: role and
        // status arrive as {value, label} objects (null when unset). The
        // VIP address checkbox checks only when role.value is vip AND
        // status.value is active (case-insensitive); an existing IP with
        // the wrong role or status is reported with its actual values,
        // never as a lookup failure.
        function fieldValue(obj) {
            if (!obj || typeof obj !== 'object') return '';
            var v = obj.value;
            return (v === undefined || v === null) ? '' : String(v).trim();
        }

        // The label an actual value gets on the address line: the API's
        // label when present, else the raw value, else "missing".
        function fieldShown(obj) {
            if (!obj || typeof obj !== 'object') return 'missing';
            var l = obj.label, v = obj.value;
            l = (l === undefined || l === null) ? '' : String(l).trim();
            v = (v === undefined || v === null) ? '' : String(v).trim();
            if (!l && !v) return 'missing';
            return l || v;
        }

        function isField(obj, want) {
            return fieldValue(obj).toLowerCase() === want;
        }

        function checkAddress() {
            var v = trim(addrInput.value);
            if (!v || !isIp(v)) {
                addrState = 'idle'; addrHasBuild = false; lastAddr = null; addrDetail = '';
                paintAddr(); refreshUI();
                return;
            }
            if (!trim(tokenInput.value)) {
                addrState = 'error'; addrErr = 'NetBox token required'; addrDetail = '';
                paintAddr(); refreshUI();
                return;
            }
            if (v === lastAddr && addrState !== 'checking' && addrState !== 'error') return;
            lastAddr = v;
            addrState = 'checking'; paintAddr();
            apiCall('GET', 'action=address&address=' + encodeURIComponent(v)).then(function (r) {
                if (trim(addrInput.value) !== v) return;
                if (r.status === 200) {
                    // A 200 alone is no longer enough: the address qualifies
                    // only with role vip AND status active (case-insensitive
                    // on the value). An existing IP that fails either stays
                    // unchecked and the line shows the actual values (e.g.
                    // "found, role VIP, status Reserved"), never a failure.
                    addrErr = ''; addrDetail = '';
                    if (isField(r.body && r.body.role, 'vip') &&
                        isField(r.body && r.body.status, 'active')) {
                        addrState = 'found';
                        addrHasBuild = !!(r.body && r.body.vip_build);
                        if (r.body && r.body.vip_fqdn && !norm(fqdnInput.value)) {
                            fqdnInput.value = r.body.vip_fqdn;
                            fqdnState = 'found';
                            paintFqdn();
                        }
                        if (r.body && r.body.vip_build) {
                            var parsed = parseStored(r.body.vip_build);
                            if (parsed) {
                                fillFromStored(parsed);
                                rawUnparsed = null;
                                setMsg('Loaded ' + (r.body.vip_fqdn || v), 'ok');
                            }
                        }
                    } else {
                        // Exists, but not a usable VIP. No form fill, no
                        // stored-build load: the box stays unchecked and
                        // the line carries the actual role/status values.
                        addrState = 'mismatch';
                        addrHasBuild = false;
                        addrDetail = 'found, role ' + fieldShown(r.body && r.body.role) +
                            ', status ' + fieldShown(r.body && r.body.status);
                    }
                }
                else if (r.status === 404) { addrState = 'missing'; addrHasBuild = false; }
                else if (r.status === 409) { addrState = 'ambiguous'; addrHasBuild = false; }
                else { addrState = 'error'; addrErr = errText(r) || ('HTTP ' + r.status); addrHasBuild = false; }
                paintAddr();
                try { refreshUI(); } catch (e) { setMsg(String(e && e.message || e), 'err'); }
            }).catch(function (e) {
                if (trim(addrInput.value) !== v) return;
                addrState = 'error'; addrErr = (e && e.message) ? e.message : 'lookup failed'; addrHasBuild = false; addrDetail = '';
                paintAddr(); refreshUI();
            });
        }

        // Member rows: the checkbox is the whole indicator - checked only
        // when the address lookup answers 200 (exists in NetBox); anything
        // else (404, 409, 401, network error, blank) leaves it unchecked.
        // Existence only: members never require role VIP / status Active -
        // that gate applies to the VIP address alone.
        // No state line under the row; the checkbox is the signal and the
        // form error list carries the message.
        function checkMemberAddress(row, ok) {
            var a = row.querySelector('.m-addr');
            var v = trim(a ? a.value : '');
            if (!v) { ok.checked = false; refreshUI(); return; }
            apiCall('GET', 'action=address&address=' + encodeURIComponent(v)).then(function (r) {
                if (trim(a.value) !== v) return; // stale response
                ok.checked = (r.status === 200);
                refreshUI();
            }).catch(function () {
                if (trim(a.value) !== v) return;
                ok.checked = false;
                refreshUI();
            });
        }

        // Token change: previous member results were unverified - re-run
        // them (debounced; saveToken fires on every keystroke).
        var memberRecheckTimer = null;
        function recheckMembers() {
            window.clearTimeout(memberRecheckTimer);
            memberRecheckTimer = window.setTimeout(function () {
                Array.prototype.forEach.call(listenersBox.querySelectorAll('.m-row'), function (row) {
                    var ok = row.querySelector('.lookup-check');
                    var a = row.querySelector('.m-addr');
                    if (ok && a && trim(a.value)) checkMemberAddress(row, ok);
                });
            }, 400);
        }

        function paintFqdn() {
            fqdnOk.checked = (fqdnState === 'found');
            var cls = 'check-line', txt = '';
            if (fqdnState === 'checking') { cls += ' miss'; txt = 'checking\u2026'; }
            else if (fqdnState === 'found') { cls += ' ok'; txt = 'found'; }
            else if (fqdnState === 'missing') { cls += ' miss'; txt = 'not found'; }
            else if (fqdnState === 'ambiguous') { cls += ' amb'; txt = 'ambiguous (' + fqdnCount + ' records)'; }
            else if (fqdnState === 'error') { cls += ' err'; txt = 'lookup failed'; }
            fqdnLine.className = cls;
            fqdnLine.textContent = txt || '\u00a0';
        }

        function paintAddr() {
            addrOk.checked = (addrState === 'found');
            var cls = 'check-line', txt = '';
            if (addrState === 'checking') { cls += ' miss'; txt = 'checking\u2026'; }
            else if (addrState === 'found') {
                cls += ' ok';
                txt = addrHasBuild ? 'found \u00b7 stored build present \u2014 Load restores it' : 'found';
            }
            else if (addrState === 'mismatch') {
                // IP exists in NetBox but role/status do not qualify
                // (needs role vip + status active): the line carries the
                // actual values, never a lookup-failure label.
                cls += ' amb';
                txt = addrDetail || 'found, role/status not VIP + Active';
            }
            else if (addrState === 'missing') { cls += ' miss'; txt = 'not found'; }
            else if (addrState === 'ambiguous') { cls += ' amb'; txt = 'ambiguous'; }
            else if (addrState === 'error') { cls += ' err'; txt = addrErr || 'lookup failed'; }
            addrLine.className = cls;
            addrLine.textContent = txt || '\u00a0';
        }

        // ---------- form model ----------

        function isPort(s) {
            return /^\d+$/.test(s) && +s >= 1 && +s <= 65535;
        }

        function isIp(s) {
            var m = /^(\d{1,3})\.(\d{1,3})\.(\d{1,3})\.(\d{1,3})$/.exec(s);
            if (m) return +m[1] <= 255 && +m[2] <= 255 && +m[3] <= 255 && +m[4] <= 255;
            if (s.indexOf(':') !== -1) return /^[0-9A-Fa-f:.]{2,45}$/.test(s); // loose IPv6
            return false;
        }

        // Read the listener rows into a plain model. Completely blank member
        // rows are ignored (an empty members list is valid); a half-filled
        // row is an error.
        function currentShape() {
            var errs = [];
            var rows = [];
            var boxes = listenersBox.querySelectorAll('.listener-box');
            Array.prototype.forEach.call(boxes, function (box, i) {
                var n = i + 1;
                var port = trim(box.querySelector('.l-port').value);
                var mon = box.querySelector('.l-mon').value;
                var pers = box.querySelector('.l-pers').value;
                var http = box.querySelector('.l-http').checked;
                var ssl = box.querySelector('.l-ssl').checked;
                var members = [];
                Array.prototype.forEach.call(box.querySelectorAll('.m-row'), function (row, j) {
                    var a = trim(row.querySelector('.m-addr').value);
                    var p = trim(row.querySelector('.m-port').value);
                    if (!a && !p) return; // blank row: not a member yet
                    if (!isIp(a)) errs.push('listener ' + n + ': member ' + (j + 1) + ' address must be an IP address');
                    if (!isPort(p)) errs.push('listener ' + n + ': member ' + (j + 1) + ' port must be an integer 1-65535');
                    // NetBox existence: the row's lookup checkbox (checked
                    // only by a 200 from action=address) gates a non-blank
                    // member address.
                    var okBox = row.querySelector('.lookup-check');
                    if (!okBox || !okBox.checked) errs.push('listener ' + n + ': member ' + (j + 1) + ' address is not in NetBox');
                    members.push({ address: a, port: p });
                });
                if (!isPort(port)) errs.push('listener ' + n + ': port must be an integer 1-65535');
                var ports = {};
                members.forEach(function (m) { ports[m.port] = true; });
                var mixed = members.length > 1 && Object.keys(ports).length > 1;
                if (mixed) errs.push('listener ' + n + ': mixed member ports \u2014 move them to another listener');
                rows.push({ port: port, mon: mon, pers: pers, http: http, ssl: ssl, members: members, mixed: mixed });
            });
            return { errs: errs, fqdn: norm(fqdnInput.value), addr: trim(addrInput.value), listeners: rows };
        }

        function memberSig(members) {
            return members.map(function (m) { return m.address + ':' + m.port; }).sort().join('|');
        }

        // Build the stored object {"build":{...}}. Returns {errs, build}.
        function buildObject() {
            var shape = currentShape();
            var errs = shape.errs.slice();
            var fqdn = shape.fqdn, addr = shape.addr;

            // The SSL Common Name is optional: the virtual and pool object
            // names fall back to the VIP address when it is empty. It is
            // required only when a listener terminates TLS - Client SSL on
            // a non-80 port (a port-80 SSL Redirect needs no name); Client
            // SSL checked with an empty name is an error and blocks submit.
            var anySsl = shape.listeners.some(function (l) { return l.ssl && !isPort80(l.port); });
            var nameBase = fqdn || addr;

            if (!fqdn && anySsl) errs.push('SSL Common Name is required');
            if (!addr) errs.push('vip address is required');
            else if (!isIp(addr)) errs.push('vip address must be an IP address');

            var limit = readLimit();
            if (!limit.length) errs.push('limit needs at least one hostname');

            // Pool plan: listeners with an identical member set share one
            // pool; the first of them owns it. Pool name is
            // <namebase>_<nodeport>_pool (the SSL Common Name, or the VIP
            // address when it is empty) with nodeport = first member's port.
            var ownerBySig = {}, nameTaken = {};
            shape.listeners.forEach(function (l, i) {
                if (l.mixed || !l.members.length) return;
                var sig = memberSig(l.members);
                if (sig in ownerBySig) return;
                var name = nameBase + '_' + l.members[0].port + '_pool';
                if (name in nameTaken) {
                    errs.push('pool name collision: ' + name + ' (listeners ' + (nameTaken[name] + 1) + ' and ' + (i + 1) + ' \u2014 use different member ports)');
                } else {
                    nameTaken[name] = i;
                }
                ownerBySig[sig] = { index: i, name: name };
            });

            if (errs.length) return { errs: errs, build: null };

            // ssl_key_cert only when a NON-redirect listener has client SSL
            // (a port-80 redirect check alone must not add ssl_key_cert);
            // anySsl is computed at the top, with the common-name rule.
            var virtual = [];
            shape.listeners.forEach(function (l, i) {
                var v = {
                    name: nameBase,
                    address: addr,
                    port: parseInt(l.port, 10),
                    ip_protocol: 'tcp',
                    source: '0.0.0.0/0',
                    snat: 'automap',
                    profiles: [VIP_PROFILES.tcp]
                };
                var redirect = l.ssl && isPort80(l.port);
                if (l.http || redirect) v.profiles.push(VIP_PROFILES.http);
                if (redirect) {
                    // The redirect iRule fires on HTTP_REQUEST, so the
                    // virtual must carry an HTTP profile or the F5 rejects it.
                    v.irules = [VIP_PROFILES.https_redirect];
                } else if (l.ssl) {
                    v.profiles.push({ name: '/Common/' + nameBase + '_clientssl', context: 'client-side' });
                }
                // Cookie persistence is only offered on a listener whose
                // HTTP box is checked; never emit it without that.
                if (l.pers === 'cookie' && l.http) v.default_persistence_profile = VIP_PROFILES.persist_cookie;
                if (l.pers === 'source') v.default_persistence_profile = VIP_PROFILES.persist_source;

                if (l.members.length) {
                    var own = ownerBySig[memberSig(l.members)];
                    if (own.index === i) {
                        // The owning listener stores the pool as an object.
                        v.pool = {
                            name: own.name,
                            lb_method: 'least-connections-node',
                            monitors: [monitorNameFor(l.mon)]
                        };
                        v.nodes = l.members.map(function (m) {
                            return { address: m.address, port: parseInt(m.port, 10) };
                        });
                    } else {
                        // A sibling sharing the pool stores its name as a
                        // string and omits nodes.
                        v.pool = own.name;
                    }
                } else {
                    v.nodes = [];
                }
                virtual.push(v);
            });

            // Key order: limit, ssl_key_cert (only when a non-redirect
            // listener has client SSL), virtual.
            var build = { limit: limit };
            if (anySsl) build.ssl_key_cert = [{ name: nameBase }];
            build.virtual = virtual;

            return { errs: [], build: { build: build } };
        }

        // ---------- rendering ----------

        function setBadge(text, cls) {
            badge.textContent = text;
            badge.className = 'badge' + (cls ? ' ' + cls : '');
        }

        function setMsg(text, kind) {
            var t = String(text || '');
            var k = kind || '';
            // Unmatched hosts from a loaded limit are reported by
            // writeLimit; carry them on the next status line so the
            // operator actually sees them.
            if (limitWarnPending) {
                var w = limitWarnPending;
                limitWarnPending = null;
                t = t ? (t === w ? t : t + ' \u2014 ' + w) : w;
                if (k !== 'danger') k = 'warn';
            }
            actionMsg.textContent = t;
            actionMsg.className = 'action-msg' + (k ? ' ' + k : '');
            actionMsg.hidden = !t;
        }

        function refreshUI() {
            var res = buildObject();
            if (res.errs.length) {
                formMsg.textContent = '';
                res.errs.slice(0, 6).forEach(function (e) {
                    formMsg.appendChild(el('div', '', '\u2022 ' + e));
                });
                formMsg.hidden = false;
            } else {
                formMsg.textContent = '';
                formMsg.hidden = true;
            }
            var ok = res.errs.length === 0;
            // The stored string doubles as a paste-in import box: while the
            // user is focused in it, refreshUI must not clobber the text.
            if (document.activeElement !== storedLine) {
                if (rawUnparsed) storedLine.value = rawUnparsed;
                else if (ok) storedLine.value = JSON.stringify(res.build);
                // form invalid and nothing raw: keep the previous display
            }
            // The common name is optional: an empty field needs no DNS hit
            // before save; a non-empty field still must be a unique hit.
            var fqdnOkToSave = !trim(fqdnInput.value) || fqdnState === 'found';
            submitBtn.disabled = busyFlag || !ok ||
                !readLimit().length ||
                !trim(tokenInput.value) ||
                !fqdnOkToSave || addrState !== 'found';
            loadBtn.disabled = busyFlag;
            drawDiagram();
        }

        // Live diagram: client -> VIP -> :port -> pool -> members. Built
        // from the typed form (structural validity only); kept as-is while
        // a row is structurally broken so the picture never flickers away.
        function drawDiagram() {
            var shape = currentShape();
            // The common name is optional: an empty field still draws, with
            // the VIP address standing in for the object names.
            var bad = shape.errs.length > 0 ||
                !shape.addr || !isIp(shape.addr);
            if (bad) {
                if (!diagram.childElementCount) {
                    diagram.textContent = '';
                    diagram.appendChild(el('div', 'dg-empty', 'fill the form \u2014 the diagram draws here'));
                }
                return;
            }
            var fqdn = shape.fqdn, addr = shape.addr;

            var ownerBySig = {};
            shape.listeners.forEach(function (l, i) {
                if (l.mixed || !l.members.length) return;
                var sig = memberSig(l.members);
                if (!(sig in ownerBySig)) {
                    ownerBySig[sig] = { index: i, name: (fqdn || addr) + '_' + l.members[0].port + '_pool' };
                }
            });

            diagram.textContent = '';
            var root = el('div', 'dg');
            var limits = readLimit();
            var limitCol = el('div', 'dg-limits');
            if (!limits.length) {
                limitCol.appendChild(el('div', 'dg-node dg-client', '(no limit)'));
            } else {
                limits.forEach(function (host) {
                    limitCol.appendChild(el('div', 'dg-node dg-client', host));
                });
            }
            root.appendChild(limitCol);
            var branch = el('div', 'dg-branch');
            var vipNode = el('div', 'dg-node dg-vip');
            // 'VIP <address> (<name>)' with a name filled; with the optional
            // name empty the node is just 'VIP <address>'.
            vipNode.appendChild(el('div', 'dg-strong',
                'VIP ' + addr + (fqdn ? ' (' + fqdn + ')' : '')));
            branch.appendChild(vipNode);
            var lists = el('div', 'dg-lists');

            if (!shape.listeners.length) {
                lists.appendChild(el('div', 'dg-node dg-none', '(no listeners)'));
            }

            shape.listeners.forEach(function (l, i) {
                var lw = el('div', 'dg-l');
                var port = el('div', 'dg-node dg-port');
                port.appendChild(el('span', 'dg-strong', ':' + (isPort(l.port) ? l.port : '?')));
                if (l.http) port.appendChild(el('span', 'chip chip-up', 'http'));
                if (l.ssl) port.appendChild(el('span', 'chip chip-up', isPort80(l.port) ? 'redirect' : 'ssl'));
                lw.appendChild(port);

                if (l.members.length && !l.mixed) {
                    var own = ownerBySig[memberSig(l.members)];
                    var pw = el('div', 'dg-poolw');
                    var pool = el('div', 'dg-node dg-pool');
                    pool.appendChild(el('span', 'dg-strong', 'pool ' + own.name));
                    if (own.index !== i) pool.appendChild(el('span', 'chip chip-shared', 'shared'));
                    pool.appendChild(el('span', 'chip', 'monitor ' + l.mon));
                    pw.appendChild(pool);
                    var mems = el('div', 'dg-mems');
                    l.members.forEach(function (m) {
                        mems.appendChild(el('div', 'dg-node dg-member', m.address + ':' + m.port));
                    });
                    pw.appendChild(mems);
                    lw.appendChild(pw);
                } else {
                    var mems2 = el('div', 'dg-mems');
                    mems2.appendChild(el('div', 'dg-node dg-none', '(no members)'));
                    lw.appendChild(mems2);
                }
                lists.appendChild(lw);
            });

            branch.appendChild(lists);
            root.appendChild(branch);
            diagram.appendChild(root);
        }

        // ---------- listener / member rows ----------

        function addMemberRow(list, m) {
            var row = el('div', 'm-row');
            var ok = el('input', 'lookup-check');   // NetBox existence indicator
            ok.type = 'checkbox';
            ok.disabled = true;
            var a = el('input', 'form-control m-addr');
            a.type = 'text';
            a.placeholder = 'member IP';
            a.autocomplete = 'off';
            a.spellcheck = false;
            a.value = (m && m.address) ? String(m.address) : '';
            var p = el('input', 'form-control m-port');
            p.type = 'number';
            p.placeholder = 'port';
            p.min = '1'; p.max = '65535';
            p.value = (m && m.port !== undefined && m.port !== null && m.port !== '') ? String(m.port) : '';
            var del = el('button', 'btn m-del', '\u00d7');
            del.type = 'button';
            del.title = 'remove member';
            row.appendChild(ok); row.appendChild(a); row.appendChild(p); row.appendChild(del);
            list.appendChild(row);
            // Debounced existence lookup on input: the checkbox is the only
            // indicator (no state line under the row).
            var mTimer = null;
            a.addEventListener('input', function () {
                window.clearTimeout(mTimer);
                mTimer = window.setTimeout(function () { checkMemberAddress(row, ok); }, 400);
            });
            // A row filled from a stored build (load/import) re-checks so a
            // stored build re-verifies its member addresses.
            if (trim(a.value)) checkMemberAddress(row, ok);
        }

        function addListenerRow(data) {
            data = data || {};
            var box = el('div', 'listener-box');

            var r1 = el('div', 'l-row');
            var portField = el('div', 'l-field l-w-port');
            portField.appendChild(el('span', 'l-lab', 'port'));
            var port = el('input', 'form-control l-port');
            port.type = 'number'; port.min = '1'; port.max = '65535';
            port.placeholder = '443';
            port.value = (data.port !== undefined && data.port !== null) ? String(data.port) : '';
            portField.appendChild(port);
            r1.appendChild(portField);

            var monField = el('div', 'l-field l-w-mon');
            monField.appendChild(el('span', 'l-lab', 'monitor'));
            var mon = el('select', 'form-control l-mon');
            ['tcp', 'ping'].forEach(function (v) {
                var o = el('option', '', v); o.value = v; mon.appendChild(o);
            });
            mon.value = data.mon || 'tcp';
            monField.appendChild(mon);
            r1.appendChild(monField);

            var persField = el('div', 'l-field l-w-pers');
            persField.appendChild(el('span', 'l-lab', 'persistence'));
            var pers = el('select', 'form-control l-pers');
            [['none', 'none'], ['cookie', 'cookie'], ['source', 'source']].forEach(function (pair) {
                var o = el('option', '', pair[1]); o.value = pair[0]; pers.appendChild(o);
            });
            pers.value = data.pers || 'none';
            persField.appendChild(pers);
            r1.appendChild(persField);
            box.appendChild(r1);

            var r2 = el('div', 'l-row');
            var httpLab = el('label', 'l-check');
            var http = el('input', 'l-http'); http.type = 'checkbox';
            http.checked = !!data.http;
            httpLab.appendChild(http); httpLab.appendChild(el('span', '', 'HTTP'));
            // Cookie persistence rides on this listener's HTTP box: the
            // cookie option is only offered while HTTP is checked, and a
            // cookie value with HTTP unchecked drops to none. The options
            // rebuild when the box changes and when a stored build fills
            // the row (fillFromStored constructs every row through here).
            var fillPers = function () {
                var prev = pers.value;
                pers.textContent = '';
                [['none', 'none'], ['source', 'source']].forEach(function (pair) {
                    var o = el('option', '', pair[1]); o.value = pair[0]; pers.appendChild(o);
                });
                if (http.checked) {
                    var c = el('option', '', 'cookie'); c.value = 'cookie';
                    pers.insertBefore(c, pers.options[1]);
                }
                if (prev === 'cookie' && !http.checked) prev = 'none';
                pers.value = prev || 'none';
            };
            http.addEventListener('change', fillPers);
            fillPers();
            var sslLab = el('label', 'l-check');
            var ssl = el('input', 'l-ssl'); ssl.type = 'checkbox';
            ssl.checked = !!data.ssl;
            var sslLabSpan = el('span', '', sslLabelFor(port.value));
            sslLab.appendChild(ssl); sslLab.appendChild(sslLabSpan);
            // The label follows the port: "Client SSL", but "SSL Redirect"
            // while the port is 80 (also when a stored build fills it).
            var syncSslLabel = function () { sslLabSpan.textContent = sslLabelFor(port.value); };
            port.addEventListener('input', syncSslLabel);
            port.addEventListener('change', syncSslLabel);
            var del = el('button', 'btn l-del', 'remove');
            del.type = 'button';
            r2.appendChild(httpLab); r2.appendChild(sslLab); r2.appendChild(del);
            box.appendChild(r2);

            var mem = el('div', 'l-members');
            var mhead = el('div', 'l-members-head');
            mhead.appendChild(el('span', 'l-lab', 'members'));
            var madd = el('button', 'btn m-add', '+ member');
            madd.type = 'button';
            mhead.appendChild(madd);
            mem.appendChild(mhead);
            var mlist = el('div', 'm-list');
            mem.appendChild(mlist);
            box.appendChild(mem);

            if (data.members) {
                data.members.forEach(function (m) { addMemberRow(mlist, m); });
            }
            listenersBox.appendChild(box);
        }

        function resetRows() {
            listenersBox.textContent = '';
            addListenerRow({});
        }

        // ---------- load ----------

        function parseStored(raw) {
            var obj = null;
            try { obj = JSON.parse(raw); } catch (e) { obj = null; }
            if (!obj || typeof obj !== 'object' || Array.isArray(obj)) return null;
            // accept the {"build":{...}} wrapper or a bare build object
            var b = (obj.build && typeof obj.build === 'object' && !Array.isArray(obj.build)) ? obj.build : obj;
            if (!Array.isArray(b.limit) || !Array.isArray(b.virtual)) return null;
            return b;
        }

        function fillFromStored(b) {
            writeLimit(b.limit);
            listenersBox.textContent = '';
            var vs = Array.isArray(b.virtual) ? b.virtual : [];

            // pool-name -> owner nodes, so a pool-sibling listener (stored
            // pool as a string) gets its shared member list back
            var ownerNodes = {};
            vs.forEach(function (v) {
                if (v && typeof v.pool === 'object' && v.pool && v.pool.name) {
                    ownerNodes[v.pool.name] = Array.isArray(v.nodes) ? v.nodes : [];
                }
            });

            vs.forEach(function (v) {
                v = v || {};
                var profiles = Array.isArray(v.profiles) ? v.profiles : [];
                var http = profiles.indexOf(VIP_PROFILES.http) !== -1;
                var ssl = profiles.some(function (p) {
                    var nm = (typeof p === 'string') ? p : ((p && p.name) || '');
                    return /_clientssl$/.test(nm);
                });
                // A port-80 listener carrying the stock redirect iRule is
                // the SSL Redirect box (checked); on any other port that
                // iRule must not be read as client SSL.
                var irules = Array.isArray(v.irules) ? v.irules : [];
                if (isPort80(v.port) && irules.indexOf(VIP_PROFILES.https_redirect) !== -1) ssl = true;
                var mon = 'tcp';
                if (v.pool && typeof v.pool === 'object' &&
                    Array.isArray(v.pool.monitors) && v.pool.monitors[0] === VIP_PROFILES.monitor_ping) {
                    mon = 'ping';
                }
                var pers = 'none';
                if (v.default_persistence_profile === VIP_PROFILES.persist_cookie) pers = 'cookie';
                else if (v.default_persistence_profile === VIP_PROFILES.persist_source) pers = 'source';

                var nodes;
                if (v.pool && typeof v.pool === 'object') nodes = Array.isArray(v.nodes) ? v.nodes : [];
                else if (typeof v.pool === 'string') nodes = ownerNodes[v.pool] || [];
                else nodes = [];

                addListenerRow({
                    port: v.port,
                    mon: mon,
                    pers: pers,
                    http: http,
                    ssl: ssl,
                    members: nodes.map(function (n) {
                        return { address: n.address, port: n.port };
                    })
                });
            });
            if (!vs.length) addListenerRow({});
        }

        function doLoad() {
            if (busyFlag) return;
            var fqdn = norm(fqdnInput.value);
            if (!fqdn) {
                setMsg('type the SSL Common Name to load, then press Load', 'warn');
                return;
            }
            busy(true, 'Loading');
            apiCall('GET', 'action=load&fqdn=' + encodeURIComponent(fqdn)).then(function (r) {
                busy(false);
                if (r.status !== 200) {
                    setMsg('load: HTTP ' + r.status + (r.body && r.body.error ? ' \u2014 ' + r.body.error : ''), 'danger');
                    return;
                }
                var b = r.body || {};
                fqdnInput.value = b.fqdn ? norm(b.fqdn) : fqdn;
                addrInput.value = trim(b.address || '');
                var raw = trim(b.vip_build || '');
                rawUnparsed = null;
                if (!raw) {
                    // no stored build: the form stays blank aside from the
                    // FQDN and the address
                    resetRows();
                    writeLimit([]);
                    storedLine.value = '';
                    setMsg('no stored build on this IP \u2014 form is blank except SSL Common Name and address', 'muted');
                } else {
                    var parsed = parseStored(raw);
                    if (parsed) {
                        fillFromStored(parsed);
                        setMsg('loaded ' + parsed.virtual.length + ' listener(s) from the stored build', 'ok');
                    } else {
                        rawUnparsed = raw;
                        resetRows();
                        writeLimit([]);
                        setMsg('stored string did not parse \u2014 raw value shown below; Save overwrites it only after you confirm', 'warn');
                    }
                    storedLine.value = raw;
                }
                lastFqdn = null; lastAddr = null;
                checkFqdn(); checkAddress();
                refreshUI();
            }).catch(function (e) {
                busy(false);
                setMsg('load failed: ' + (e && e.message ? e.message : 'network error'), 'danger');
            });
        }

        // The stored string doubles as an import box: paste a build string,
        // press Import, and the form is refilled from it. An unparseable
        // string only warns \u2014 the textarea and the form stay untouched.
        function doImport() {
            var b = parseStored(storedLine.value);
            if (!b) {
                setMsg('stored string did not parse \u2014 paste a valid build string to import; the form is unchanged', 'warn');
                return;
            }
            fillFromStored(b);
            var vs = b.virtual;
            var first = vs[0] || null;
            // A build saved with an empty common name carries the VIP
            // address as the virtual name: importing it leaves the optional
            // name field empty instead of pasting the address into it.
            if (first && first.name && norm(first.name) !== norm(first.address)) {
                fqdnInput.value = norm(first.name);
            }
            if (first && first.address) addrInput.value = trim(first.address);
            rawUnparsed = null;
            setMsg('imported ' + vs.length + ' listener(s) from the stored string', 'ok');
            checkFqdn(); checkAddress();
            refreshUI();
        }

        // ---------- save ----------

        function doSave() {
            if (busyFlag) return;
            var res = buildObject();
            if (!res.build) {
                setMsg('cannot save yet: ' + (res.errs[0] || 'form incomplete'), 'danger');
                return;
            }
            if (rawUnparsed) {
                if (!window.confirm('The existing stored string did not parse and will be overwritten by this save. Continue?')) {
                    return;
                }
            }
            busy(true, 'Saving');
            apiCall('POST', 'action=validate', res.build).then(function (v) {
                if (v.status !== 200) {
                    busy(false);
                    setMsg('validate rejected the build (nothing saved): ' + errText(v), 'danger');
                    return;
                }
                if (v.body && v.body.vip_build) storedLine.value = v.body.vip_build;
                return apiCall('POST', 'action=save', res.build).then(function (s) {
                    busy(false);
                    if (s.status !== 200) {
                        setMsg('save failed (validate passed): ' + errText(s), 'danger');
                        return;
                    }
                    var b = s.body || {};
                    rawUnparsed = null;
                    storedLine.value = b.vip_build || JSON.stringify(res.build);
                    setMsg('saved \u2014 IP id ' + (b.id === undefined ? '?' : b.id) +
                        ', DNS record id ' + (b.dns_id === undefined ? '?' : b.dns_id), 'ok');
                    lastFqdn = null; lastAddr = null;
                    checkFqdn(); checkAddress();
                    refreshUI();
                });
            }).catch(function (e) {
                busy(false);
                setMsg('save failed: ' + (e && e.message ? e.message : 'network error'), 'danger');
            });
        }

        // ---------- misc ----------

        function busy(flag, label) {
            busyFlag = flag;
            if (flag) setBadge(label || 'Running', 'b-warn');
            else setBadge('Idle', '');
            refreshUI();
        }

        function doClear() {
            fqdnInput.value = '';
            addrInput.value = '';
            writeLimit([]);
            resetRows();
            rawUnparsed = null;
            fqdnState = 'idle'; fqdnCount = 0; lastFqdn = null;
            addrState = 'idle'; addrHasBuild = false; lastAddr = null; addrDetail = '';
            fqdnOk.checked = false; addrOk.checked = false;
            paintFqdn(); paintAddr();
            setMsg('', 'muted');
            storedLine.value = '';
            setBadge('Idle', '');
            refreshUI();
        }

        // ---------- events ----------

        var formPane = $('form-pane');

        formPane.addEventListener('input', function (e) {
            if (e.target === tokenInput) { saveToken(); return; }
            if (e.target === fqdnInput) { scheduleFqdn(); }
            if (e.target === addrInput) { scheduleAddr(); }
            refreshUI();
        });

        formPane.addEventListener('change', function (e) {
            if (e.target === tokenInput) { saveToken(); return; }
            refreshUI();
        });

        formPane.addEventListener('click', function (e) {
            var t = e.target;
            if (!(t instanceof Element)) return;
            if (t.classList.contains('l-del')) {
                var b = t.closest('.listener-box');
                if (b) b.remove();
                refreshUI();
            } else if (t.classList.contains('m-add')) {
                var wrap = t.closest('.l-members');
                if (wrap) addMemberRow(wrap.querySelector('.m-list'), {});
                refreshUI();
            } else if (t.classList.contains('m-del')) {
                var row = t.closest('.m-row');
                if (row) row.remove();
                refreshUI();
            }
        });

        listenerAdd.addEventListener('click', function () {
            addListenerRow({});
            refreshUI();
        });

        loadBtn.addEventListener('click', doLoad);
        importBtn.addEventListener('click', doImport);
        submitBtn.addEventListener('click', doSave);
        clearBtn.addEventListener('click', doClear);

        // ---------- init ----------

        restoreToken();
        resetRows();
        paintFqdn();
        paintAddr();
        setBadge('Idle', '');
        refreshUI();
    })();
    </script>
<?php nb_chrome_foot(); ?>