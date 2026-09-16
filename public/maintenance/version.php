<?php
// version.php — returns a simple version string that changes when code/assets change
// Used by live-reload.js to detect updates and reload the page.

// Disable output buffering issues
if (function_exists('ob_end_clean')) { @ob_end_clean(); }

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$rootPublic   = dirname(__DIR__);
$rootIncludes = realpath(dirname(__DIR__, 2) . '/includes');
$rootConfig   = realpath(dirname(__DIR__, 2) . '/config');

// Directories to scan for changes
$roots = array_filter([$rootPublic, $rootIncludes, $rootConfig]);

// Exclusions (directories)
$excludeDirs = ['.git', 'vendor', 'uploads', 'reports', 'backups', '.idea', '.vscode', 'node_modules'];

// File extensions to consider for reload signals
$exts = ['php','js','css','html','htm','json','svg','png','jpg','jpeg','gif','webp'];

$latest = 0;
$count  = 0;

foreach ($roots as $root) {
    if (!is_dir($root)) { continue; }
    $it = new RecursiveIteratorIterator(
        new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            function ($current, $key, $iterator) use ($excludeDirs) {
                if ($current->isDir()) {
                    $name = $current->getFilename();
                    if (in_array($name, $excludeDirs, true)) {
                        return false; // skip this dir
                    }
                }
                return true;
            }
        ),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($it as $fileInfo) {
        if (!$fileInfo->isFile()) { continue; }
        $ext = strtolower(pathinfo($fileInfo->getFilename(), PATHINFO_EXTENSION));
        if (!in_array($ext, $exts, true)) { continue; }
        $mtime = $fileInfo->getMTime();
        if ($mtime > $latest) { $latest = $mtime; }
        $count++;
    }
}

// Compose a compact version string
$version = dechex($latest) . '-' . base_convert(max($count,1), 10, 36);

echo json_encode([
    'version' => $version,
    'time'    => $latest,
]);
exit;
?>
