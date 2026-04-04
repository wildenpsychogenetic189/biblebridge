<?php
/**
 * BibleBridge Standalone — One-Click Updater
 * Downloads the latest zip from holybible.dev and extracts it,
 * preserving config.local.php, plans/progress/*, and admin state.
 *
 * Safety: backs up the current install before applying. If anything
 * fails mid-update, the backup is restored automatically.
 */

// Try to extend execution time — x10 and similar hosts often kill at 30s
@set_time_limit(120);
@ini_set('max_execution_time', '120');

// Catch fatal errors (memory, timeout) and return JSON instead of dying silently
register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR])) {
        // Clear any partial output
        if (ob_get_level()) ob_end_clean();
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        $msg = 'Server fatal error: ' . $err['message'] . ' in ' . basename($err['file']) . ':' . $err['line'];
        if (stripos($err['message'], 'time') !== false || stripos($err['message'], 'timeout') !== false) {
            $msg = 'PHP execution timed out. Your host may have a very short time limit. Try updating via manual upload instead.';
        } elseif (stripos($err['message'], 'memory') !== false) {
            $msg = 'PHP ran out of memory (limit: ' . ini_get('memory_limit') . '). Try updating via manual upload.';
        }
        echo json_encode(['status' => 'error', 'message' => $msg]);
    }
});

require_once __DIR__ . '/config.php';

// Admin auth — same as settings.php
$configFile = __DIR__ . '/config.local.php';
if (!file_exists($configFile)) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Not installed.']);
    exit;
}

$localConfig = require $configFile;
$adminToken  = $localConfig['admin_token'] ?? '';

// Accept token from POST body or query string
$token = $_POST['token'] ?? $_GET['token'] ?? '';
if ($adminToken === '' || !hash_equals($adminToken, $token)) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Invalid admin token.']);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'message' => 'POST required.']);
    exit;
}

$zipUrl = 'https://holybible.dev/standalone-build/download.php';

// Preflight checks — need ZipArchive, shell unzip, or pure PHP extraction
$hasZipArchive = class_exists('ZipArchive');
$shellDisabled = array_map('trim', explode(',', ini_get('disable_functions') ?: ''));
$hasUnzip = false;
if (!$hasZipArchive && !in_array('shell_exec', $shellDisabled) && strtoupper(substr(PHP_OS, 0, 3)) !== 'WIN') {
    $hasUnzip = (bool) @shell_exec('which unzip 2>/dev/null');
}
// Pure PHP zip extraction is always available as last resort

if (!is_writable(__DIR__)) {
    echo json_encode(['status' => 'error', 'message' => 'Install directory is not writable. Check file permissions.']);
    exit;
}

// Check disk space — need room for backup + extraction (~3x current size)
$installSize = dirSize(__DIR__);
$freeSpace   = @disk_free_space(__DIR__);
if ($freeSpace !== false && $freeSpace < $installSize * 3) {
    echo json_encode(['status' => 'error', 'message' => 'Not enough disk space for safe update.']);
    exit;
}

// ── Step 1: Download zip ──────────────────────────────────────

$tmpDir = sys_get_temp_dir();
if (!is_writable($tmpDir)) $tmpDir = __DIR__;
$tmpZip = @tempnam($tmpDir, 'bb_update_');
if ($tmpZip === false && $tmpDir !== __DIR__) {
    $tmpZip = @tempnam(__DIR__, 'bb_update_');
}
if ($tmpZip === false) {
    // tempnam blocked entirely — construct path manually
    $tmpZip = __DIR__ . '/bb_update_' . bin2hex(random_bytes(8)) . '.zip';
}

$zipData = false;

// Try cURL first (works on most restrictive hosts including x10)
if (function_exists('curl_init')) {
    $ch = curl_init($zipUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_USERAGENT      => 'BibleBridge-Updater/' . BB_VERSION,
    ]);
    $zipData = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);
    if ($httpCode !== 200 || $zipData === false) {
        $zipData = false;
    }
}

// Fallback: file_get_contents (needs allow_url_fopen)
if ($zipData === false && ini_get('allow_url_fopen')) {
    $ctx = stream_context_create([
        'http' => [
            'method'  => 'GET',
            'timeout' => 30,
            'header'  => "User-Agent: BibleBridge-Updater/" . BB_VERSION . "\r\n",
        ],
    ]);
    $zipData = @file_get_contents($zipUrl, false, $ctx);
}

if ($zipData === false || strlen($zipData) < 1000) {
    @unlink($tmpZip);
    $detail = '';
    if (!function_exists('curl_init') && !ini_get('allow_url_fopen')) {
        $detail = ' Your server has both cURL and allow_url_fopen disabled.';
    }
    echo json_encode(['status' => 'error', 'message' => 'Could not download update.' . $detail]);
    exit;
}
file_put_contents($tmpZip, $zipData);

// ── Step 2: Validate & extract zip ──────────────────────────────

$parentDir  = dirname(__DIR__);
$tmpExtract = $parentDir . '/bb_update_new_' . time();
if (!@mkdir($tmpExtract, 0755, true)) {
    // Fallback: try inside install dir (some hosts restrict parent writes)
    $tmpExtract = __DIR__ . '/bb_update_new_' . time();
    if (!@mkdir($tmpExtract, 0755, true)) {
        @unlink($tmpZip);
        echo json_encode(['status' => 'error', 'message' => 'Could not create temp directory for extraction.']);
        exit;
    }
}

$extractOk = false;

if ($hasZipArchive) {
    // Method 1: ZipArchive extension
    $zip = new ZipArchive();
    $res = $zip->open($tmpZip);
    if ($res !== true) {
        @unlink($tmpZip);
        removeDir($tmpExtract);
        echo json_encode(['status' => 'error', 'message' => 'Downloaded file is corrupt or not a valid zip.']);
        exit;
    }
    $hasConfig = false;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        if ($zip->getNameIndex($i) === 'biblebridge/config.php') {
            $hasConfig = true;
            break;
        }
    }
    if (!$hasConfig) {
        $zip->close();
        @unlink($tmpZip);
        removeDir($tmpExtract);
        echo json_encode(['status' => 'error', 'message' => 'Zip does not contain a valid BibleBridge package.']);
        exit;
    }
    $zip->extractTo($tmpExtract);
    $zip->close();
    $extractOk = true;

} elseif ($hasUnzip) {
    // Method 2: shell unzip
    $escapedZip = escapeshellarg($tmpZip);
    $escapedDst = escapeshellarg($tmpExtract);
    @exec("unzip -o {$escapedZip} -d {$escapedDst} 2>&1", $out, $code);
    $extractOk = ($code === 0);

} else {
    // Method 3: Pure PHP zip extraction (no extensions, no shell)
    $extractOk = purePhpUnzip($tmpZip, $tmpExtract);
}

if (!$extractOk || !file_exists($tmpExtract . '/biblebridge/config.php')) {
    @unlink($tmpZip);
    removeDir($tmpExtract);
    echo json_encode(['status' => 'error', 'message' => 'Failed to extract update package.']);
    exit;
}
@unlink($tmpZip);

$extractedSource = $tmpExtract . '/biblebridge';
if (!is_dir($extractedSource)) {
    removeDir($tmpExtract);
    echo json_encode(['status' => 'error', 'message' => 'Unexpected zip structure.']);
    exit;
}

// ── Step 4: Back up current install ───────────────────────────

$backupDir = $parentDir . '/bb_backup_' . BB_VERSION . '_' . date('Ymd_His');
if (!is_writable($parentDir)) {
    $backupDir = __DIR__ . '/bb_backup_' . BB_VERSION . '_' . date('Ymd_His');
}
$backedUp  = copyDir(__DIR__, $backupDir, ['cache']);
if (!$backedUp) {
    removeDir($tmpExtract);
    removeDir($backupDir);
    echo json_encode(['status' => 'error', 'message' => 'Could not create backup. Update aborted — nothing changed.']);
    exit;
}

// ── Step 5: Copy new files over current install ───────────────

$ok = copyDir($extractedSource, __DIR__);
removeDir($tmpExtract);

if (!$ok) {
    // Restore from backup
    copyDir($backupDir, __DIR__);
    removeDir($backupDir);
    echo json_encode(['status' => 'error', 'message' => 'Update failed mid-copy. Rolled back to previous version.']);
    exit;
}

// ── Step 6: Restore preserved files ───────────────────────────

// config.local.php — always keep the user's config
$backupConfig = $backupDir . '/config.local.php';
if (file_exists($backupConfig)) {
    copy($backupConfig, __DIR__ . '/config.local.php');
}

// .installed sentinel — preserve or create to prevent accidental re-provisioning
$backupInstalled = $backupDir . '/.installed';
if (file_exists($backupInstalled)) {
    copy($backupInstalled, __DIR__ . '/.installed');
} elseif (!file_exists(__DIR__ . '/.installed')) {
    @file_put_contents(__DIR__ . '/.installed', date('c') . " (created by updater)\n");
}

// plans/progress — reading plan progress
$backupProgress = $backupDir . '/plans/progress';
if (is_dir($backupProgress)) {
    $progressDir = __DIR__ . '/plans/progress';
    if (!is_dir($progressDir)) @mkdir($progressDir, 0755, true);
    foreach (glob($backupProgress . '/*') as $f) {
        if (is_file($f)) copy($f, $progressDir . '/' . basename($f));
    }
}

// cache/ — page cache is regenerated automatically, but preserve the directory
// so permissions are retained on restrictive hosts
$backupCache = $backupDir . '/cache';
if (is_dir($backupCache)) {
    $cacheDir = __DIR__ . '/cache';
    if (!is_dir($cacheDir)) @mkdir($cacheDir, 0755, true);
    // Don't copy cached files — they'll regenerate. Just ensure dir exists.
}

// ── Step 7: Verify the update didn't break config.php ─────────

$newConfigContent = @file_get_contents(__DIR__ . '/config.php');
if ($newConfigContent === false || strpos($newConfigContent, 'BB_VERSION') === false) {
    // Critical file missing or broken — rollback
    copyDir($backupDir, __DIR__);
    if (file_exists($backupConfig)) copy($backupConfig, __DIR__ . '/config.local.php');
    removeDir($backupDir);
    echo json_encode(['status' => 'error', 'message' => 'Updated config.php appears broken. Rolled back to previous version.']);
    exit;
}

// Read new version from the freshly updated config.php
$newVersion = BB_VERSION;
if (preg_match("/define\('BB_VERSION',\s*'([^']+)'\)/", $newConfigContent, $m)) {
    $newVersion = $m[1];
}

// ── Step 8: Clean up old backups (keep only most recent) ──────

// Check both parent dir and install dir for old backups
$backupSearchDirs = array_unique([$parentDir, __DIR__]);
foreach ($backupSearchDirs as $searchDir) {
    foreach (glob($searchDir . '/bb_backup_*') as $oldBackup) {
        if (is_dir($oldBackup) && $oldBackup !== $backupDir) {
            removeDir($oldBackup);
        }
    }
}

echo json_encode([
    'status'  => 'success',
    'message' => 'Updated to v' . $newVersion . '. Previous version backed up.',
    'version' => $newVersion,
]);

// --- Helper functions ---

function copyDir(string $src, string $dst, array $skipDirs = []): bool
{
    $dir = opendir($src);
    if (!$dir) return false;
    if (!is_dir($dst)) @mkdir($dst, 0755, true);
    while (($file = readdir($dir)) !== false) {
        if ($file === '.' || $file === '..' || $file === '.git' || str_starts_with($file, 'bb_update_new_') || str_starts_with($file, 'bb_backup_')) continue;
        if (in_array($file, $skipDirs, true)) continue;
        $srcPath = $src . '/' . $file;
        $dstPath = $dst . '/' . $file;
        if (is_dir($srcPath)) {
            if (!copyDir($srcPath, $dstPath)) {
                closedir($dir);
                return false;
            }
        } else {
            if (!@copy($srcPath, $dstPath)) {
                closedir($dir);
                return false;
            }
        }
    }
    closedir($dir);
    return true;
}

function removeDir(string $dir): void
{
    if (!is_dir($dir)) return;
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($items as $item) {
        if ($item->isDir()) @rmdir($item->getRealPath());
        else @unlink($item->getRealPath());
    }
    @rmdir($dir);
}

function dirSize(string $dir): int
{
    $size = 0;
    foreach (new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)
    ) as $file) {
        $size += $file->getSize();
    }
    return $size;
}

/**
 * Pure PHP zip extraction — no ZipArchive extension, no shell commands.
 * Reads the zip central directory and extracts stored/deflated entries.
 * Handles the BibleBridge zip structure (small files, no encryption).
 */
function purePhpUnzip(string $zipPath, string $destDir): bool
{
    $data = file_get_contents($zipPath);
    if ($data === false) return false;

    $len = strlen($data);

    // Find End of Central Directory record (scan backwards)
    $eocdPos = false;
    for ($i = $len - 22; $i >= max(0, $len - 65557); $i--) {
        if (substr($data, $i, 4) === "\x50\x4b\x05\x06") {
            $eocdPos = $i;
            break;
        }
    }
    if ($eocdPos === false) return false;

    $cdOffset = unpack('V', substr($data, $eocdPos + 16, 4))[1];
    $cdEntries = unpack('v', substr($data, $eocdPos + 10, 2))[1];

    $pos = $cdOffset;
    for ($e = 0; $e < $cdEntries; $e++) {
        if (substr($data, $pos, 4) !== "\x50\x4b\x01\x02") return false;

        $method    = unpack('v', substr($data, $pos + 10, 2))[1];
        $cSize     = unpack('V', substr($data, $pos + 20, 4))[1];
        $uSize     = unpack('V', substr($data, $pos + 24, 4))[1];
        $nameLen   = unpack('v', substr($data, $pos + 28, 2))[1];
        $extraLen  = unpack('v', substr($data, $pos + 30, 2))[1];
        $commentLen= unpack('v', substr($data, $pos + 32, 2))[1];
        $localOff  = unpack('V', substr($data, $pos + 42, 4))[1];
        $name      = substr($data, $pos + 46, $nameLen);

        $pos += 46 + $nameLen + $extraLen + $commentLen;

        // Security: skip entries with path traversal
        if (str_contains($name, '..') || str_starts_with($name, '/')) continue;

        $outPath = $destDir . '/' . $name;

        // Directory entry
        if (substr($name, -1) === '/') {
            if (!is_dir($outPath)) @mkdir($outPath, 0755, true);
            continue;
        }

        // Ensure parent directory exists
        $parentDir = dirname($outPath);
        if (!is_dir($parentDir)) @mkdir($parentDir, 0755, true);

        // Read from local file header
        $localNameLen  = unpack('v', substr($data, $localOff + 26, 2))[1];
        $localExtraLen = unpack('v', substr($data, $localOff + 28, 2))[1];
        $dataStart     = $localOff + 30 + $localNameLen + $localExtraLen;
        $compressed    = substr($data, $dataStart, $cSize);

        if ($method === 0) {
            // Stored
            file_put_contents($outPath, $compressed);
        } elseif ($method === 8) {
            // Deflated
            $inflated = @gzinflate($compressed);
            if ($inflated === false) return false;
            file_put_contents($outPath, $inflated);
        } else {
            // Unsupported method — skip
            continue;
        }
    }

    return true;
}
