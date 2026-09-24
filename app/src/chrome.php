<?php
/**
 * chrome.php — shared page chrome for the wanportal-addon-nb sidecar.
 *
 * Used by every sidecar page:
 *   - certs frontend      /nb/                  (frontend.php)
 *   - NetBox sites report /nb/reports/sites.php
 *
 * Contract (all printers, all guarded against redeclaration):
 *   head(string $title = 'wanportal', array $extraHead = [], string $pageStyle = '')
 *   topnav(string $active = '')
 *   foot(string $extraHtml = '')
 *
 * Available as wanportal_addon_head() / nb_chrome_head() / chrome_head() /
 * head() (and topnav / foot).
 *
 * Rules baked in:
 *   - light/dark theme: tokens copied from ui/src/styles/base.css; the
 *     visitor's choice rides the SPA's 'wanportal-theme' localStorage key,
 *     applied pre-paint to data-theme AND data-bs-theme on <html>, and
 *     followed live via postMessage and the storage event
 *   - logo /assets/logo.png
 *   - SPA .btn/.bar chrome: one uniform button face for .btn and every
 *     Bootstrap variant used in sidecar markup (a.btn is a button, never a
 *     green underlined link), .bar / .bar-title h1 header from base.css,
 *     no underline on any anchor
 *   - all navigation links same-tab (no target="_blank"), hrefs:
 *     /  /nb/  /nb/vip.php  /nb/reports/sites.php
 *   - embed mode (?embed or X-Wanportal-Embed header): topnav prints nothing
 *     (the SPA owns the bar), body gets padding-top 0; head tokens unchanged
 */

if (defined('NB_CHROME_LOADED')) {
    return;
}
define('NB_CHROME_LOADED', '1');

/**
 * Embed mode: the page renders inside the wanportal SPA view, which already
 * provides its own top navigation bar. Triggered by ?embed (any value) or an
 * X-Wanportal-Embed request header. In this mode nb_chrome_topnav() prints
 * nothing (no duplicate brand/bar) and the body gets no top padding.
 */
if (!defined('NB_CHROME_EMBED')) {
    define('NB_CHROME_EMBED', isset($_GET['embed']) || !empty($_SERVER['HTTP_X_WANPORTAL_EMBED']));
}

if (!function_exists('nb_chrome_head')) {

    /**
     * True when the page renders in embed mode: the SPA owns the top bar, so
     * the sidecar chrome must not print a second one.
     */
    function nb_chrome_embed(): bool
    {
        return defined('NB_CHROME_EMBED') && NB_CHROME_EMBED;
    }

    /**
     * Pre-paint theme bootstrap. The <html> tag ships the dark default
     * (data-theme="dark" + data-bs-theme="dark"); this script — first thing
     * in the head, before any CSS — flips both to light before first paint
     * when localStorage['wanportal-theme'] (the key the SPA ThemeToggle and
     * the classic console write) says 'light'. Every storage access is
     * wrapped: Safari private mode and some embedded WebViews throw.
     *
     * Live follow-up, so an open sidecar page tracks the SPA toggle without
     * a reload:
     *   - message: { type: 'wanportal-theme', theme: 'light' | 'dark' }
     *   - storage: same-origin sibling windows/frames writing the key
     */
    function nb_chrome_theme_js(): string {
        return <<<'JS'
(function () {
    var KEY = 'wanportal-theme';
    var root = document.documentElement;

    function apply(theme) {
        var light = (theme === 'light');
        root.setAttribute('data-theme', light ? 'light' : 'dark');
        root.setAttribute('data-bs-theme', light ? 'light' : 'dark');
    }

    var stored = null;
    try { stored = localStorage.getItem(KEY); } catch (e) { stored = null; }
    apply(stored === 'light' ? 'light' : 'dark');

    /* Explicit notify from the embed host (the SPA shell). */
    window.addEventListener('message', function (ev) {
        var d = ev && ev.data;
        if (d && d.type === 'wanportal-theme' &&
            (d.theme === 'light' || d.theme === 'dark')) {
            apply(d.theme);
        }
    });

    /* Same-origin company: the SPA (or classic console) writing the key in
     * another tab, or the parent document of this frame, fires storage here. */
    window.addEventListener('storage', function (ev) {
        if (ev && ev.key === KEY &&
            (ev.newValue === 'light' || ev.newValue === 'dark')) {
            apply(ev.newValue);
        }
    });
})();
JS;
    }

    /**
     * Open the document: DOCTYPE, <html data-theme="dark"
     * data-bs-theme="dark">, <head> with the pre-paint theme script, the
     * shared tokens/base CSS, then </head><body>.
     *
     * Always emits the shared tokens/base CSS (SPA tokens, dark default +
     * light override, bootstrap surface overrides), plus a zero-top-padding
     * body rule in embed mode.
     *
     * @param string $title      Page title.
     * @param array  $extraHead  Raw tags (e.g. CDN <link> elements) printed
     *                           before the chrome style so chrome overrides win.
     * @param string $pageStyle  Optional raw CSS printed AFTER the chrome style
     *                           so page-specific rules win over chrome.
     */
    function nb_chrome_head(string $title = 'wanportal', array $extraHead = [], string $pageStyle = ''): void {
        if (!headers_sent()) {
            header('Content-Type: text/html; charset=UTF-8');
        }
        $t = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');

        echo "<!DOCTYPE html>\n";
        echo "<html lang=\"en\" data-theme=\"dark\" data-bs-theme=\"dark\">\n<head>\n";
        /* Pre-paint theme script: first child of <head>, before any CSS, so
         * a light visitor never flashes the dark default. */
        echo "<script>\n", nb_chrome_theme_js(), "</script>\n";
        echo "<meta charset=\"UTF-8\">\n";
        echo "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n";
        echo "<title>{$t}</title>\n";
        echo "<link rel=\"icon\" href=\"/favicon.ico\">\n";
        foreach ($extraHead as $tag) {
            if (is_string($tag) && $tag !== '') {
                echo $tag, "\n";
            }
        }
        echo '<style>', "\n", nb_chrome_css(), "\n</style>\n";
        if (nb_chrome_embed()) {
            echo "<style>body { padding-top: 0; }</style>\n";
        }
        if ($pageStyle !== '') {
            echo "<style>\n", $pageStyle, "\n</style>\n";
        }
        echo "</head>\n<body>\n";
    }

    /**
     * Shared top navigation. $active may be a shorthand key
     * (home|portal|latency|api|certs|sites) or an exact href.
     *
     * In embed mode this prints nothing: the SPA already renders its own
     * top bar, so a second brand/nav would be a duplicate.
     */
    function nb_chrome_topnav(string $active = ''): void {
        if (nb_chrome_embed()) {
            return;
        }
        $links = [
            'dashboard' => ['/',                     'Dashboard'],
            'certs'     => ['/nb/',                  'CertMgr'],
            'vips'      => ['/nb/vip.php',           'VIP Builder'],
            'sites'     => ['/nb/reports/sites.php', 'Sites'],
        ];

        echo "<nav class=\"topnav\">\n";
        echo "  <a class=\"brand\" href=\"/\"><img class=\"brand-logo\" src=\"/assets/logo.png\" alt=\"wanportal\"></a>\n";
        foreach ($links as $key => [$href, $label]) {
            $isActive = ($active === $key || $active === $href);
            $cls      = $isActive ? 'nav-link active' : 'nav-link';
            $aria     = $isActive ? ' aria-current="page"' : '';
            echo "  <a class=\"{$cls}\" href=\"{$href}\"{$aria}>{$label}</a>\n";
        }
        echo "</nav>\n";
    }

    /**
     * Close the document: shared footer, optional raw HTML (e.g. <script>
     * tags), then </body></html>.
     */
    function nb_chrome_foot(string $extraHtml = ''): void {
        echo "<footer class=\"nb-foot\">wanportal &middot; NetBox sidecar &middot;</footer>\n";
        if ($extraHtml !== '') {
            echo $extraHtml, "\n";
        }
        echo "</body>\n</html>\n";
    }

    /**
     * Shared CSS: SPA color tokens (dark :root default + light override,
     * verbatim from ui/src/styles/base.css) + base layout + topnav +
     * Bootstrap surface overrides that ride the tokens so both themes work.
     */
    function nb_chrome_css(): string {
        return <<<'CSS'
/* Theme tokens, copied from ui/src/styles/base.css — do not drift.
 * Dark stays the :root default; light re-points the neutral tokens only.
 * The status hues (--up/--warn/--danger and their translucent fills) are
 * declared once and never overridden, so status colors read identically
 * on both themes. --stale deepens on light for contrast on white. */
:root {
    --bg: #0e1319;
    --panel: #161d26;
    --panel-edge: #232c38;
    --text: #cdd6e0;
    --muted: #7c8b9c;
    --up: #4cc38a;
    --warn: #d9a13b;
    --danger: #e5534b;
    --up-bg: rgba(76, 195, 138, .12);
    --warn-bg: rgba(217, 161, 59, .14);
    --danger-bg: rgba(229, 83, 75, .14);
    --stale: #b085f5;
    --hover: rgba(255, 255, 255, .04);
    --shadow: 0 6px 18px rgba(0, 0, 0, .35);
}

html[data-theme="light"] {
    --bg: #eef1f5;
    --panel: #ffffff;
    --panel-edge: #d3dae3;
    --text: #24303e;
    --muted: #5d6b7d;
    --stale: #7c52c7;
    --hover: rgba(23, 43, 66, .06);
    --shadow: 0 6px 18px rgba(31, 45, 62, .14);
}

/* color-scheme rides the attribute (never :root) so native form controls,
 * scrollbars and the btn-close glyph flip with the theme instead of being
 * pinned to dark. */
html[data-theme="dark"] { color-scheme: dark; }
html[data-theme="light"] { color-scheme: light; }

body {
    margin: 0;
    padding: 0 18px 30px;
    background: var(--bg);
    color: var(--text);
    font: 13px/1.45 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
}
a { color: var(--up); text-decoration: none; }
a:hover { text-decoration: none; }

/* --- top navigation (mirrors the SPA topnav) --- */
.topnav {
    display: flex;
    align-items: center;
    gap: 14px;
    flex-wrap: wrap;
    padding: 12px 0 10px;
    border-bottom: 1px solid var(--panel-edge);
    margin-bottom: 14px;
}
.topnav .brand {
    font-size: 15px;
    font-weight: 600;
    letter-spacing: .3px;
    margin-right: 6px;
    color: var(--text);
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 8px;
}
.brand-logo { display: block; height: 22px; width: auto; }
.nav-link {
    color: var(--muted);
    text-decoration: none;
    font-size: 13px;
    padding: 2px;
    border-bottom: 2px solid transparent;
}
.nav-link:hover { color: var(--text); }
.nav-link.active { color: var(--text); border-bottom-color: var(--up); }

/* --- header bar (copied from the SPA's .bar / .bar-title) --- */
.bar {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    gap: 12px;
    flex-wrap: wrap;
    margin-bottom: 12px;
}
.bar-title h1 {
    font-size: 17px;
    margin: 0;
    letter-spacing: .3px;
    font-weight: 600;
}

/* --- panes --- */
.panel {
    background: var(--panel);
    border: 1px solid var(--panel-edge);
    border-radius: 8px;
    padding: 14px;
}

/* --- footer --- */
.nb-foot {
    margin-top: 22px;
    padding-top: 10px;
    border-top: 1px solid var(--panel-edge);
    color: var(--muted);
    font-size: 12px;
}

/* --- Bootstrap surface overrides (token-driven, theme-aware) --- */
h1, h2, h3, h4, h5, h6 { color: var(--text); }
.table {
    --bs-table-bg: var(--panel);
    --bs-table-color: var(--text);
    /* Stripes and hover ride the shared hover tint (or a mix over it) so
     * they work on both themes; hover reads a touch stronger than the
     * stripe, matching the SPA's row-hover tint. */
    --bs-table-striped-bg: var(--hover);
    --bs-table-striped-color: var(--text);
    --bs-table-hover-bg: color-mix(in srgb, var(--text) 7%, var(--panel));
    --bs-table-hover-color: var(--text);
    border-color: var(--panel-edge);
}
.table > :not(caption) > * > * { border-color: var(--panel-edge); }
.modal-content {
    background: var(--panel);
    color: var(--text);
    border: 1px solid var(--panel-edge);
}
.modal-header, .modal-footer { border-color: var(--panel-edge); }
.form-control, .form-select, input, select, textarea {
    background: var(--bg);
    color: var(--text);
    border-color: var(--panel-edge);
}
.form-control:focus, .form-select:focus {
    background: var(--bg);
    color: var(--text);
    border-color: var(--up);
    box-shadow: 0 0 0 .2rem var(--up-bg);
}
.form-control::placeholder { color: var(--muted); opacity: 1; }
.form-label { color: var(--text); }
/* --- Buttons: one uniform SPA .btn face ---
 * Bootstrap loads before the chrome style, but its variant classes still
 * win in practice: a.btn.btn-info rendered as a green underlined link
 * (chrome's `a` color + Bootstrap's underline + the variant override).
 * Everything in sidecar markup that is a .btn — plain, sm, info, primary,
 * outline-*, on <button> or <a> — maps to the same face as
 * ui/src/styles/base.css; danger keeps a --danger border so destructive
 * controls still read. */
.btn,
a.btn, a.btn.btn-info, a.btn.btn-sm,
.btn-info, .btn-outline-secondary, .btn-primary,
.btn-outline-primary, .btn-outline-danger {
    background: var(--panel);
    color: var(--text);
    border: 1px solid var(--panel-edge);
    border-radius: 6px;
    padding: 3px 10px;
    font-size: 12px;
    cursor: pointer;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 4px;
    font-family: inherit;
}
/* Hover/focus/pressed must map too, or Bootstrap's variant hover colors
 * (and active shapes like .btn:first-child:active) win while clicking. */
.btn:hover, .btn:focus, .btn:first-child:active, .btn.active, .btn.show,
:not(.btn-check) + .btn:active,
a.btn:hover, a.btn:focus, a.btn.btn-info:hover, a.btn.btn-sm:hover,
.btn-info:hover, .btn-info:focus, .btn-info:first-child:active,
.btn-outline-secondary:hover, .btn-outline-secondary:focus, .btn-outline-secondary:first-child:active,
.btn-primary:hover, .btn-primary:focus, .btn-primary:first-child:active,
.btn-outline-primary:hover, .btn-outline-primary:focus, .btn-outline-primary:first-child:active,
.btn-outline-danger:hover, .btn-outline-danger:focus, .btn-outline-danger:first-child:active {
    background: var(--panel);
    border-color: var(--muted);
    color: var(--text);
    text-decoration: none;
}
a.btn, a.btn:hover, a.btn:visited { color: var(--text); text-decoration: none; }
.btn-outline-danger, .btn-outline-danger:hover, .btn-outline-danger:focus { border-color: var(--danger); }
.btn-secondary { background: var(--panel-edge); border-color: var(--panel-edge); color: var(--text); }
.btn-secondary:hover {
    background: color-mix(in srgb, var(--text) 8%, var(--panel-edge));
    border-color: var(--panel-edge);
    color: var(--text);
}
/* The close glyph is painted for light surfaces; invert it only on dark. */
html[data-theme="dark"] .btn-close { filter: invert(1) grayscale(100%) brightness(200%); }
.alert-danger  { background: var(--danger-bg); color: var(--danger);  border-color: var(--danger); }
.alert-warning { background: var(--warn-bg);   color: var(--warn);    border-color: var(--warn); }
.alert-info    { background: var(--up-bg);     color: var(--text);    border-color: var(--up); }
.toast {
    --bs-toast-bg: var(--panel);
    background: var(--panel);
    border: 1px solid var(--panel-edge);
    color: var(--text);
}
.toast-header { background: var(--panel-edge); color: var(--text); border-color: var(--panel-edge); }
.badge.bg-secondary { background: var(--panel-edge) !important; color: var(--text) !important; }
.text-muted, .small { color: var(--muted) !important; }
hr { border-color: var(--panel-edge); opacity: 1; }
.card { background: var(--panel); border-color: var(--panel-edge); color: var(--text); }
.list-group-item { background: var(--panel); border-color: var(--panel-edge); color: var(--text); }
.tooltip-inner { background: var(--panel-edge); color: var(--text); }

/* --- DataTables (bootstrap5 skin) --- */
div.dataTables_wrapper div.dataTables_filter input,
div.dataTables_wrapper div.dataTables_length select {
    background: var(--bg);
    color: var(--text);
    border-color: var(--panel-edge);
}
.dataTables_info, .dataTables_length, .dataTables_filter { color: var(--muted); }
.page-link {
    background: var(--panel);
    border-color: var(--panel-edge);
    color: var(--text);
}
.page-item.active .page-link { background: var(--up); border-color: var(--up); color: #0e1319; }
.page-item.disabled .page-link { background: var(--panel); color: var(--muted); }

/* --- Leaflet popup --- */
.leaflet-popup-content-wrapper, .leaflet-popup-tip { background: var(--panel); color: var(--text); }
.leaflet-container a { color: var(--up); }
CSS;
    }

    /* --- aliases so callers can use nb_chrome_*, wanportal_addon_*,
     *     chrome_*, or bare names --- */
    if (!function_exists('wanportal_addon_head')) {
        function wanportal_addon_head(string $title = 'wanportal', array $extraHead = [], string $pageStyle = ''): void {
            nb_chrome_head($title, $extraHead, $pageStyle);
        }
    }
    if (!function_exists('wanportal_addon_topnav')) {
        function wanportal_addon_topnav(string $active = ''): void { nb_chrome_topnav($active); }
    }
    if (!function_exists('wanportal_addon_foot')) {
        function wanportal_addon_foot(string $extraHtml = ''): void { nb_chrome_foot($extraHtml); }
    }
    if (!function_exists('wanportal_addon_embed')) {
        function wanportal_addon_embed(): bool { return nb_chrome_embed(); }
    }
    if (!function_exists('chrome_head')) {
        function chrome_head(string $title = 'wanportal', array $extraHead = [], string $pageStyle = ''): void {
            nb_chrome_head($title, $extraHead, $pageStyle);
        }
    }
    if (!function_exists('chrome_topnav')) {
        function chrome_topnav(string $active = ''): void { nb_chrome_topnav($active); }
    }
    if (!function_exists('chrome_foot')) {
        function chrome_foot(string $extraHtml = ''): void { nb_chrome_foot($extraHtml); }
    }
    if (!function_exists('head')) {
        function head(string $title = 'wanportal', array $extraHead = [], string $pageStyle = ''): void {
            nb_chrome_head($title, $extraHead, $pageStyle);
        }
    }
    if (!function_exists('topnav')) {
        function topnav(string $active = ''): void { nb_chrome_topnav($active); }
    }
    if (!function_exists('foot')) {
        function foot(string $extraHtml = ''): void { nb_chrome_foot($extraHtml); }
    }
}