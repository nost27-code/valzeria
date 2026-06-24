<?php
$sourceDir = __DIR__;
$backupDir = __DIR__ . '/backups';
if (!is_dir($backupDir)) mkdir($backupDir);

$timestamp = date('Ymd_His');
$zipFile = $backupDir . '/ffa_backup_' . $timestamp . '.zip';

$zip = new ZipArchive();
if ($zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($sourceDir),
        RecursiveIteratorIterator::LEAVES_ONLY
    );

    foreach ($files as $name => $file) {
        if (!$file->isDir()) {
            $filePath = $file->getRealPath();
            $relativePath = substr($filePath, strlen($sourceDir) + 1);
            
            // 除外リスト
            if (strpos($relativePath, 'vendor\\') === 0 || strpos($relativePath, 'vendor/') === 0) continue;
            if (strpos($relativePath, 'node_modules\\') === 0 || strpos($relativePath, 'node_modules/') === 0) continue;
            if (strpos($relativePath, '.git\\') === 0 || strpos($relativePath, '.git/') === 0) continue;
            if (strpos($relativePath, 'backups\\') === 0 || strpos($relativePath, 'backups/') === 0) continue;

            $zip->addFile($filePath, $relativePath);
        }
    }
    $zip->close();
    echo "Backup created successfully at: " . $zipFile . "\n";
} else {
    echo "Failed to create backup.\n";
}
