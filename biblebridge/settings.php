<?php
/**
 * BibleBridge Standalone — Settings Page
 * Shows API key, tier info, and upgrade link.
 */

require_once __DIR__ . '/config.php';

$configFile = __DIR__ . '/config.local.php';
if (!file_exists($configFile)) {
    header('Location: ' . $bbBaseUrl . '/setup');
    exit;
}

$localConfig = require $configFile;
$adminToken  = $localConfig['admin_token'] ?? '';

// Require admin token — via query param (starts session) or existing session
// Some shared hosts (x10, LiteSpeed) have broken session save paths — catch and fall back to cookie auth
$sessionOk = false;
try {
    if (session_status() === PHP_SESSION_NONE) {
        $sessionOk = @session_start();
    } else {
        $sessionOk = true;
    }
} catch (\Throwable $e) {
    $sessionOk = false;
}

// If sessions don't work, fall through to token-only auth (token stays in URL)
if (!empty($_GET['token']) && hash_equals($adminToken, $_GET['token'])) {
    if ($sessionOk) {
        $_SESSION['bb_admin'] = true;
        // Redirect to clean URL (remove token from address bar)
        header('Location: ' . $bbBaseUrl . '/settings');
        exit;
    }
    // Session broken — allow access with token in URL (don't redirect)
    $bbSettingsAuthed = true;
}

$bbSettingsAuthed = $bbSettingsAuthed ?? ($sessionOk && !empty($_SESSION['bb_admin']));

if (!$bbSettingsAuthed || $adminToken === '') {
    http_response_code(403);
    echo '<!DOCTYPE html><html><head><title>Access Denied</title></head><body style="font-family:sans-serif;text-align:center;padding:4rem;color:#555;">';
    echo '<h1 style="font-size:1.3rem;">Settings — Access Denied</h1>';
    echo '<p>Use the admin link from your setup page or <code>config.local.php</code> to access settings.</p>';
    echo '</body></html>';
    exit;
}

$apiKey      = $localConfig['api_key'] ?? '';
$currentName = $localConfig['site_name'] ?? '';

$message = '';
$messageType = '';

// Handle translations update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_versions'])) {
    $allVersions = ['kjv','asv','web','ylt','rvr','lsg','lut','ara','cuv','krv','adb'];
    $allLabels = [
        'kjv' => 'KJV', 'asv' => 'ASV', 'web' => 'WEB', 'ylt' => 'YLT',
        'rvr' => 'RVR (Spanish)', 'lsg' => 'LSG (French)', 'lut' => 'Luther (German)',
        'ara' => 'ARA (Portuguese)', 'cuv' => 'CUV (Chinese)', 'krv' => 'KRV (Korean)',
        'adb' => 'ADB (Tagalog)',
    ];
    $selected = isset($_POST['enabled_versions']) && is_array($_POST['enabled_versions'])
        ? array_values(array_intersect($_POST['enabled_versions'], $allVersions))
        : ['kjv'];
    if (!in_array('kjv', $selected)) $selected = array_merge(['kjv'], $selected);
    $newVersions = [];
    foreach ($selected as $vk) $newVersions[$vk] = $allLabels[$vk];
    $localConfig['versions'] = $newVersions;
    $export = var_export($localConfig, true);
    if (@file_put_contents($configFile, "<?php\nreturn " . $export . ";\n")) {
        $message = 'Translations updated.';
        $messageType = 'success';
    } else {
        $message = 'Could not write config file. Check folder permissions.';
        $messageType = 'error';
    }
}

// Handle site name update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['site_name'])) {
    $newName = trim($_POST['site_name'] ?? '');
    if ($newName !== '' && $newName !== $currentName) {
        $localConfig['site_name'] = $newName;
        $export = var_export($localConfig, true);
        if (@file_put_contents($configFile, "<?php\nreturn " . $export . ";\n")) {
            $currentName = $newName;
            $message = 'Site name updated.';
            $messageType = 'success';

            // Sync name back to BibleBridge API
            $syncData = json_encode(['site_name' => $newName]);
            @file_get_contents(
                rtrim($localConfig['api_url'] ?? 'https://holybible.dev/api', '/') . '/update-site',
                false,
                stream_context_create([
                    'http' => [
                        'method'  => 'POST',
                        'header'  => "Content-Type: application/json\r\nX-API-Key: {$localConfig['api_key']}\r\n",
                        'content' => $syncData,
                        'timeout' => 5,
                        'ignore_errors' => true,
                    ],
                ])
            );
        } else {
            $message = 'Could not write config file. Check folder permissions.';
            $messageType = 'error';
        }
    }
}

$pageTitle = 'Settings — ' . htmlspecialchars($siteName);
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?></title>
    <link rel="stylesheet" href="<?= $bbBaseUrl ?>/assets/fonts/fonts.css">
    <link rel="stylesheet" href="<?= $bbBaseUrl ?>/assets/reader.min.css?v=20260401">
    <link rel="icon" type="image/svg+xml" href="<?= $bbBaseUrl ?>/favicon.svg">
    <script>
        (function () {
            var t = localStorage.getItem('bb_theme');
            if (t === 'dark' || (!t && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
                document.documentElement.setAttribute('data-theme', 'dark');
            }
        })();
    </script>
    <style>
        .settings-main { max-width: 620px; margin: 0 auto; padding: calc(var(--header-height) + 2.5rem) 1.5rem 5rem; }
        .settings-title { font-family: 'Lora', Georgia, serif; font-size: 1.75rem; font-weight: 500; color: var(--text-primary); margin-bottom: 2rem; }
        .settings-section { background: var(--bg-card); border: 1px solid var(--border-light); border-radius: 8px; padding: 1.5rem; margin-bottom: 1.5rem; }
        .settings-section-title { font-family: 'Inter', sans-serif; font-size: 0.7rem; font-weight: 700; letter-spacing: 0.1em; text-transform: uppercase; color: var(--text-muted); margin-bottom: 1rem; }
        .settings-row { margin-bottom: 1rem; }
        .settings-row:last-child { margin-bottom: 0; }
        .settings-label { font-family: 'Inter', sans-serif; font-size: 0.78rem; font-weight: 600; color: var(--text-muted); margin-bottom: 0.3rem; }
        .settings-input { width: 100%; padding: 0.55rem 0.85rem; font-size: 0.9rem; font-family: inherit; border: 1px solid var(--border-light); border-radius: 5px; background: var(--bg-subtle); color: var(--text-primary); }
        .settings-input:focus { outline: none; border-color: var(--accent); }
        .btn-save { font-family: 'Inter', sans-serif; font-size: 0.82rem; font-weight: 600; background: var(--accent); color: #fff; border: none; border-radius: 5px; padding: 0.45rem 1rem; cursor: pointer; margin-top: 0.5rem; transition: background 0.15s; }
        .btn-save:hover { background: var(--accent-hover); }
        .btn-action { display: block; width: 100%; font-family: 'Inter', sans-serif; font-size: 0.85rem; font-weight: 600; background: var(--accent); color: #fff; border: none; border-radius: 6px; padding: 0.65rem 1.2rem; cursor: pointer; text-decoration: none; text-align: center; transition: background 0.15s; margin-top: 0.6rem; }
        .btn-action:hover { background: var(--accent-hover); }
        .btn-action.btn-outline { background: transparent; color: var(--accent); border: 1px solid var(--accent); }
        .btn-action.btn-outline:hover { background: var(--accent); color: #fff; }
        .msg-success { background: #f0fdf4; border: 1px solid #bbf7d0; color: #166534; padding: 0.6rem 1rem; border-radius: 6px; font-size: 0.85rem; margin-bottom: 1.5rem; }
        .msg-error { background: #fef2f2; border: 1px solid #fecaca; color: #b91c1c; padding: 0.6rem 1rem; border-radius: 6px; font-size: 0.85rem; margin-bottom: 1.5rem; }

        /* Update section */
        .update-badge { display: inline-block; font-family: 'Inter', sans-serif; font-size: 0.72rem; font-weight: 700; background: #f59e0b; color: #fff; padding: 0.15rem 0.5rem; border-radius: 99px; vertical-align: middle; margin-left: 0.5rem; }
        .update-current { font-family: 'Inter', sans-serif; font-size: 0.85rem; color: var(--text-muted); }
        .update-current strong { color: var(--text-primary); font-weight: 600; }
        .update-changelog { font-family: 'Inter', sans-serif; font-size: 0.82rem; color: var(--text-muted); line-height: 1.5; margin: 0.75rem 0; padding: 0.6rem 0.85rem; background: var(--bg-subtle); border-radius: 5px; border-left: 3px solid var(--accent); }
        .btn-update { display: block; width: 100%; font-family: 'Inter', sans-serif; font-size: 0.85rem; font-weight: 600; background: #16a34a; color: #fff; border: none; border-radius: 6px; padding: 0.65rem 1.2rem; cursor: pointer; text-align: center; transition: background 0.15s; }
        .btn-update:hover { background: #15803d; }
        .btn-update:disabled { background: #9ca3af; cursor: not-allowed; }
        .update-progress { font-family: 'Inter', sans-serif; font-size: 0.82rem; color: var(--text-muted); margin-top: 0.5rem; }

        /* Usage bar */
        .usage-bar-wrap { margin-top: 0.75rem; }
        .usage-bar-bg { height: 8px; background: var(--bg-subtle); border: 1px solid var(--border-light); border-radius: 4px; overflow: hidden; }
        .usage-bar-fill { height: 100%; border-radius: 4px; background: var(--accent); transition: width 0.6s ease; }
        .usage-bar-fill.warn { background: #e67e22; }
        .usage-bar-fill.critical { background: #c0392b; }
        .usage-stats { display: flex; justify-content: space-between; font-family: 'Inter', sans-serif; font-size: 0.78rem; color: var(--text-muted); margin-top: 0.4rem; }
        .usage-count { font-size: 1.5rem; font-weight: 600; font-family: 'Inter', sans-serif; color: var(--text-primary); }
        .usage-limit { font-size: 0.85rem; color: var(--text-muted); }
        .usage-loading { font-family: 'Inter', sans-serif; font-size: 0.85rem; color: var(--text-muted); }
        .usage-reset { font-family: 'Inter', sans-serif; font-size: 0.75rem; color: var(--text-muted); margin-top: 0.5rem; }
    </style>
</head>
<body class="reader-index-page">

<header class="reader-header">
    <div class="reader-header-left">
        <a href="<?= $bbBaseUrl ?>/read" class="reader-logo"><?= htmlspecialchars($siteName) ?></a>
        <nav class="reader-header-nav">
            <a href="<?= $bbBaseUrl ?>/read" class="reader-header-nav-link">Read</a>
            <a href="<?= $bbBaseUrl ?>/plans" class="reader-header-nav-link">Plans</a>
            <a href="<?= $bbBaseUrl ?>/topics" class="reader-header-nav-link">Topics</a>
        </nav>
    </div>
    <div class="reader-header-center"></div>
    <div class="reader-header-right">
        <button class="theme-toggle" id="themeToggle" aria-label="Toggle dark mode">
            <svg class="theme-icon-moon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>
            <svg class="theme-icon-sun" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>
        </button>
    </div>
</header>

<main class="settings-main">
    <h1 class="settings-title">Settings</h1>

    <?php if ($message): ?>
    <div class="msg-<?= $messageType ?>"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <!-- Software Updates -->
    <div class="settings-section" id="update-section" style="display:none;">
        <div class="settings-section-title">Software Update</div>
        <div id="update-area"></div>
    </div>

    <!-- Maintenance (rollback + repair) -->
    <div class="settings-section" id="maintenance-section">
        <div class="settings-section-title">Maintenance</div>

        <!-- Phase B: rollback to previous version -->
        <div id="maintenance-area">
            <div class="update-current" style="color:var(--text-muted);">Checking restore availability&hellip;</div>
        </div>

        <!-- Phase C: repair current version (always available) -->
        <div id="repair-area" style="margin-top:1rem; padding-top:1rem; border-top:1px solid var(--border-light);">
            <div class="update-current">Re-download the current version and replace install files. Fixes local file corruption without changing your version.</div>
            <button type="button" class="btn-action btn-outline" id="btn-do-repair" onclick="applyRepair()">Repair install</button>
            <div class="update-progress" id="repair-progress"></div>
            <div style="font-size:0.75rem; color:var(--text-muted); margin-top:0.5rem;">Your settings and reading progress are preserved. Your version will not change.</div>
        </div>
    </div>

    <!-- Today's Usage -->
    <div class="settings-section">
        <div class="settings-section-title">Today's Usage</div>
        <div id="usage-area">
            <div class="usage-loading">Loading usage data...</div>
        </div>
    </div>

    <!-- Your Plan -->
    <div class="settings-section">
        <div class="settings-section-title">Your Plan</div>
        <div class="settings-row" id="plan-area">
            <div class="usage-loading">Loading...</div>
        </div>
    </div>

    <!-- Site Name -->
    <div class="settings-section">
        <div class="settings-section-title">Site Name</div>
        <form method="post">
            <div class="settings-row">
                <input type="text" name="site_name" class="settings-input" value="<?= htmlspecialchars($currentName) ?>" required>
                <button type="submit" class="btn-save">Save</button>
            </div>
        </form>
    </div>

    <!-- Translations -->
    <div class="settings-section">
        <div class="settings-section-title">Translations</div>
        <form method="post">
            <input type="hidden" name="update_versions" value="1">
            <?php
            $allLabels = [
                'kjv' => 'KJV', 'asv' => 'ASV', 'web' => 'WEB', 'ylt' => 'YLT',
                'rvr' => 'RVR (Spanish)', 'lsg' => 'LSG (French)', 'lut' => 'Luther (German)',
                'ara' => 'ARA (Portuguese)', 'cuv' => 'CUV (Chinese)', 'krv' => 'KRV (Korean)',
                'adb' => 'ADB (Tagalog)',
            ];
            $enabledKeys = array_keys($localConfig['versions'] ?? $allLabels);
            foreach ($allLabels as $vk => $vl):
                $checked = in_array($vk, $enabledKeys) ? 'checked' : '';
                $isKjv = $vk === 'kjv';
            ?>
            <label style="display:inline-block; min-width:170px; margin:3px 0; font-size:0.88rem; font-weight:400; color:var(--text-primary);">
                <input type="checkbox" name="enabled_versions[]" value="<?= htmlspecialchars($vk) ?>" <?= $checked ?><?= $isKjv ? ' checked disabled' : '' ?>>
                <?= htmlspecialchars($vl) ?><?= $isKjv ? ' <em style="opacity:.5">(always on)</em>' : '' ?>
            </label>
            <?php if ($isKjv): ?><input type="hidden" name="enabled_versions[]" value="kjv"><?php endif; ?>
            <?php endforeach; ?>
            <div style="font-size:0.78rem; color:var(--text-muted); margin-top:0.5rem;">Uncheck translations you don't need. KJV is always included.</div>
            <button type="submit" class="btn-save" style="margin-top:0.75rem;">Save Translations</button>
        </form>
    </div>

    <!-- Account -->
    <div class="settings-section">
        <div class="settings-section-title">Account</div>
        <div class="settings-row">
            <div class="settings-label">Connect an email to manage billing and get usage alerts.</div>
            <button type="button" class="btn-action" onclick="claimRedirect('signup')">Claim Your Account</button>
            <div style="font-size:0.75rem; color:var(--text-muted); margin-top:0.5rem;">Opens a secure link that expires in 15 minutes. Complete signup before closing the page.</div>
        </div>
    </div>

    <!-- Admin Access -->
    <div class="settings-section">
        <div class="settings-section-title">Admin Access</div>
        <div class="settings-row">
            <div class="settings-label">Bookmark this link to access settings anytime:</div>
            <div style="font-family:'Courier New',monospace; font-size:0.78rem; background:var(--bg-subtle); border:1px solid var(--border-light); border-radius:5px; padding:0.6rem 0.85rem; word-break:break-all; color:var(--text-primary); user-select:all; cursor:text; margin-top:0.4rem;"><?= htmlspecialchars($bbBaseUrl) ?>/settings?token=<?= htmlspecialchars($adminToken) ?></div>
            <div style="font-size:0.75rem; color:var(--text-muted); margin-top:0.4rem;">Keep this private. Anyone with this link can access your settings.</div>
        </div>
    </div>
</main>

<script>
var apiUrl = <?= json_encode($localConfig['api_url'] ?? 'https://holybible.dev/api') ?>;
var apiKey = <?= json_encode($apiKey) ?>;
var bbVersion = <?= json_encode(BB_VERSION) ?>;
var adminToken = <?= json_encode($adminToken) ?>;
var baseUrl = <?= json_encode($bbBaseUrl) ?>;

// --- Update checker ---
(function() {
    fetch(apiUrl + '/standalone-version')
        .then(function(r) { return r.json(); })
        .then(function(d) {
            if (d.status !== 'success') return;

            var section = document.getElementById('update-section');
            var area = document.getElementById('update-area');
            var latest = d.stable;
            var isNewer = compareVersions(latest, bbVersion) > 0;

            section.style.display = '';

            if (!isNewer) {
                area.innerHTML = '<div class="update-current">You\'re running <strong>v' + bbVersion + '</strong> — the latest version.</div>';
                return;
            }

            window._bbZipUrl = d.zip_url || '';
            var html = '<div class="update-current">Installed: <strong>v' + bbVersion + '</strong><span class="update-badge">v' + latest + ' available</span></div>';
            if (d.changelog) {
                html += '<div class="update-changelog">' + escHtml(d.changelog) + '</div>';
            }
            html += '<button class="btn-update" id="btn-do-update" onclick="applyUpdate()">Update to v' + escHtml(latest) + '</button>';
            html += '<div class="update-progress" id="update-progress"></div>';
            area.innerHTML = html;
        })
        .catch(function() {
            // Silent — don't clutter settings if version check fails
        });

    function compareVersions(a, b) {
        var pa = a.split('.').map(Number);
        var pb = b.split('.').map(Number);
        for (var i = 0; i < 3; i++) {
            if ((pa[i] || 0) > (pb[i] || 0)) return 1;
            if ((pa[i] || 0) < (pb[i] || 0)) return -1;
        }
        return 0;
    }

    function escHtml(s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }
    function escAttr(s) { return s.replace(/'/g, "\\'").replace(/"/g, '&quot;'); }
})();

function applyUpdate() {
    var btn = document.getElementById('btn-do-update');
    var progress = document.getElementById('update-progress');
    btn.disabled = true;
    btn.textContent = 'Updating...';
    progress.textContent = 'Downloading and applying update — do not close this page.';

    var body = new FormData();
    body.append('action', 'update');

    fetch(baseUrl + '/update.php?token=' + encodeURIComponent(adminToken), {
        method: 'POST',
        body: body
    })
    .then(function(r) {
        if (!r.ok && r.status === 0) throw new Error('Network request failed');
        return r.text().then(function(txt) {
            try { return JSON.parse(txt); }
            catch(e) { return { status: 'error', message: 'Server returned invalid response (HTTP ' + r.status + '). The update may have timed out — check if your version changed.' }; }
        });
    })
    .then(function(d) {
        if (d.status === 'success') {
            progress.innerHTML = '<span style="color:#16a34a;font-weight:600;">' + d.message + '</span><br>Reloading...';
            setTimeout(function() { location.reload(); }, 1500);
        } else {
            btn.disabled = false;
            btn.textContent = 'Retry Update';
            progress.innerHTML = '<span style="color:#b91c1c;">' + (d.message || 'Update failed.') + '</span>';
        }
    })
    .catch(function(e) {
        btn.disabled = false;
        btn.textContent = 'Retry Update';
        progress.innerHTML = '<span style="color:#b91c1c;">Network error: your server may have killed the request (timeout or WAF). Check if the version changed — if not, try manual upload.</span>';
    });
}

(function() {

    fetch(apiUrl + '/usage', { headers: { 'X-API-Key': apiKey } })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            if (d.status !== 'success') throw new Error(d.message);

            var used = d.quota_used;
            var limit = d.daily_limit;
            var pct = Math.min(Math.round(used / limit * 100), 100);
            var tier = d.tier;

            // Usage bar
            var cls = pct >= 90 ? 'critical' : pct >= 70 ? 'warn' : '';
            var resetTime = new Date(d.resets_at);
            var hours = Math.max(0, Math.ceil((resetTime - new Date()) / 3600000));

            document.getElementById('usage-area').innerHTML =
                '<div class="usage-count">' + used.toLocaleString() + ' <span class="usage-limit">/ ' + limit.toLocaleString() + ' requests</span></div>' +
                '<div class="usage-bar-wrap"><div class="usage-bar-bg"><div class="usage-bar-fill ' + cls + '" style="width:' + pct + '%"></div></div></div>' +
                '<div class="usage-stats"><span>' + pct + '% used</span><span>Resets in ' + hours + 'h</span></div>';

            // Plan section
            var tierName = tier.charAt(0).toUpperCase() + tier.slice(1);
            var planHtml = '<div style="margin-bottom:0.75rem;">' +
                '<span style="font-family:Inter,sans-serif; font-size:1.1rem; font-weight:600; color:var(--text-primary);">' + tierName + '</span>' +
                '<span style="font-family:Inter,sans-serif; font-size:0.82rem; color:var(--text-muted); margin-left:0.5rem;">' + limit.toLocaleString() + ' requests/day</span>' +
                '</div>';

            if (tier === 'free') {
                planHtml += '<div style="font-family:Inter,sans-serif; font-size:0.82rem; color:var(--text-muted); margin-bottom:0.75rem; line-height:1.5;">' +
                    'Your readers share 250 daily requests. When the limit is reached, visitors see a friendly notice until midnight UTC.' +
                    '</div>' +
                    '<button type="button" class="btn-action" onclick="claimRedirect(\'pricing\')">View Plans & Pricing</button>';
            }

            document.getElementById('plan-area').innerHTML = planHtml;
        })
        .catch(function() {
            document.getElementById('usage-area').innerHTML =
                '<div class="usage-loading" style="color:var(--text-muted);">Could not load usage data. Your reader is still working.</div>';
            document.getElementById('plan-area').innerHTML =
                '<div class="usage-loading" style="color:var(--text-muted);">Could not load plan info.</div>';
        });
})();

function claimRedirect(page) {
    fetch(apiUrl + '/claim-token', {
        method: 'POST',
        headers: { 'X-API-Key': apiKey }
    })
    .then(function(r) { return r.json(); })
    .then(function(d) {
        if (d.status === 'success') {
            window.open('https://holybible.dev/' + page + '?claim=' + d.claim_token, '_blank');
        } else {
            window.open('https://holybible.dev/' + page, '_blank');
        }
    })
    .catch(function() {
        window.open('https://holybible.dev/' + page, '_blank');
    });
}

// --- Maintenance: rollback status check on page load ---
(function() {
    var area = document.getElementById('maintenance-area');
    if (!area) return;

    fetch(baseUrl + '/update.php?action=rollback_status&token=' + encodeURIComponent(adminToken))
        .then(function(r) { return r.json(); })
        .then(function(d) {
            if (!d || d.status !== 'success') {
                renderDisabled(area, 'Restore info unavailable.');
                return;
            }
            if (d.available) {
                renderAvailable(area, d);
            } else {
                renderDisabled(area, d.message || 'Restore not available.');
            }
        })
        .catch(function() {
            renderDisabled(area, 'Could not check restore availability.');
        });

    function renderAvailable(container, d) {
        container.innerHTML = '';

        var line = document.createElement('div');
        line.className = 'update-current';
        line.appendChild(document.createTextNode('Restore to '));
        var strong = document.createElement('strong');
        strong.textContent = 'v' + d.backup_version;
        line.appendChild(strong);
        line.appendChild(document.createTextNode(' (backed up ' + d.backup_time + ')'));

        var btn = document.createElement('button');
        btn.className = 'btn-action btn-outline';
        btn.id = 'btn-do-rollback';
        btn.type = 'button';
        btn.textContent = 'Restore previous version';
        btn.onclick = function() {
            applyRollback(d.backup_version, d.current_version, d.backup_time);
        };

        var progress = document.createElement('div');
        progress.className = 'update-progress';
        progress.id = 'rollback-progress';

        var helper = document.createElement('div');
        helper.style.fontSize = '0.75rem';
        helper.style.color = 'var(--text-muted)';
        helper.style.marginTop = '0.5rem';
        helper.textContent = 'Your settings and reading progress are preserved. A safety snapshot is kept so you can undo.';

        container.appendChild(line);
        container.appendChild(btn);
        container.appendChild(progress);
        container.appendChild(helper);
    }

    function renderDisabled(container, reasonText) {
        container.innerHTML = '';

        var line = document.createElement('div');
        line.className = 'update-current';
        line.style.color = 'var(--text-muted)';
        line.textContent = reasonText;

        var btn = document.createElement('button');
        btn.className = 'btn-action btn-outline';
        btn.type = 'button';
        btn.textContent = 'Restore previous version';
        btn.disabled = true;
        btn.style.opacity = '0.5';
        btn.style.cursor = 'not-allowed';

        container.appendChild(line);
        container.appendChild(btn);
    }
})();

// Global — called from the Repair button onclick handler in the Maintenance section.
function applyRepair() {
    var msg = 'Repair install?\n\n'
            + 'This will re-download v' + bbVersion + ' and replace your install files.\n\n'
            + 'Your version will not change.\n'
            + 'Your settings and reading progress will be preserved.\n\n'
            + 'Use this if files on disk are corrupt or missing — not to downgrade.';
    if (!confirm(msg)) return;

    var btn = document.getElementById('btn-do-repair');
    var progress = document.getElementById('repair-progress');
    if (btn) { btn.disabled = true; btn.textContent = 'Repairing...'; }
    if (progress) { progress.textContent = 'Re-downloading and replacing install files — do not close this page.'; }

    var body = new FormData();
    body.append('action', 'repair');

    fetch(baseUrl + '/update.php?token=' + encodeURIComponent(adminToken), {
        method: 'POST',
        body: body
    })
    .then(function(r) {
        return r.text().then(function(txt) {
            try { return JSON.parse(txt); }
            catch (e) { return { status: 'error', message: 'Server returned invalid response (HTTP ' + r.status + ').' }; }
        });
    })
    .then(function(d) {
        if (d && d.status === 'success') {
            if (progress) {
                progress.innerHTML = '';
                var ok = document.createElement('span');
                ok.style.color = '#16a34a';
                ok.style.fontWeight = '600';
                ok.textContent = d.message || 'Repaired.';
                progress.appendChild(ok);
                progress.appendChild(document.createElement('br'));
                progress.appendChild(document.createTextNode('Reloading...'));
            }
            setTimeout(function() { location.reload(); }, 1500);
        } else {
            if (btn) { btn.disabled = false; btn.textContent = 'Retry Repair'; }
            if (progress) {
                progress.innerHTML = '';
                var err = document.createElement('span');
                err.style.color = '#b91c1c';
                err.textContent = (d && d.message) || 'Repair failed.';
                progress.appendChild(err);
            }
        }
    })
    .catch(function() {
        if (btn) { btn.disabled = false; btn.textContent = 'Retry Repair'; }
        if (progress) {
            progress.innerHTML = '';
            var err = document.createElement('span');
            err.style.color = '#b91c1c';
            err.textContent = 'Network error. Try again or check server logs.';
            progress.appendChild(err);
        }
    });
}

// Global — called from the Maintenance button onclick handler set up above.
function applyRollback(targetVersion, currentVersion, backupTime) {
    var msg = 'Restore previous version?\n\n'
            + 'Current:  v' + currentVersion + '\n'
            + 'Restore:  v' + targetVersion + ' (from ' + backupTime + ')\n\n'
            + 'This will replace the current install with the backup.\n'
            + 'Your settings and reading progress will be preserved.\n'
            + 'A safety snapshot of the current version will be kept so you can undo.';
    if (!confirm(msg)) return;

    var btn = document.getElementById('btn-do-rollback');
    var progress = document.getElementById('rollback-progress');
    if (btn) { btn.disabled = true; btn.textContent = 'Restoring...'; }
    if (progress) { progress.textContent = 'Restoring previous version — do not close this page.'; }

    var body = new FormData();
    body.append('action', 'rollback');

    fetch(baseUrl + '/update.php?token=' + encodeURIComponent(adminToken), {
        method: 'POST',
        body: body
    })
    .then(function(r) {
        return r.text().then(function(txt) {
            try { return JSON.parse(txt); }
            catch (e) { return { status: 'error', message: 'Server returned invalid response (HTTP ' + r.status + ').' }; }
        });
    })
    .then(function(d) {
        if (d && d.status === 'success') {
            if (progress) {
                progress.innerHTML = '';
                var ok = document.createElement('span');
                ok.style.color = '#16a34a';
                ok.style.fontWeight = '600';
                ok.textContent = d.message || 'Restored.';
                progress.appendChild(ok);
                progress.appendChild(document.createElement('br'));
                progress.appendChild(document.createTextNode('Reloading...'));
            }
            setTimeout(function() { location.reload(); }, 1500);
        } else {
            if (btn) { btn.disabled = false; btn.textContent = 'Retry Restore'; }
            if (progress) {
                progress.innerHTML = '';
                var err = document.createElement('span');
                err.style.color = '#b91c1c';
                err.textContent = (d && d.message) || 'Restore failed.';
                progress.appendChild(err);
            }
        }
    })
    .catch(function() {
        if (btn) { btn.disabled = false; btn.textContent = 'Retry Restore'; }
        if (progress) {
            progress.innerHTML = '';
            var err = document.createElement('span');
            err.style.color = '#b91c1c';
            err.textContent = 'Network error. Try again or check server logs.';
            progress.appendChild(err);
        }
    });
}
</script>

<?php $bottomNavActive = ''; include __DIR__ . '/bottom-nav.php'; ?>
<script src="<?= $bbBaseUrl ?>/assets/reader.min.js?v=20260401"></script>
</body>
</html>
