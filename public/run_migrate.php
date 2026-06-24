<?php
$secretToken = "nostalgia0905";
if (!isset($_GET['token']) || $_GET['token'] !== $secretToken) {
    die("Unauthorized");
}

$artisanPath = realpath(__DIR__ . '/../artisan');
if (file_exists($artisanPath)) {
    exec("php {$artisanPath} migrate --force 2>&1", $out, $ret);
    echo "・マイグレーション実行結果 (ret:{$ret}):\n" . implode("\n", $out) . "\n";
} else {
    echo "artisan not found at {$artisanPath}";
}
