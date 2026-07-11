<?php

declare(strict_types=1);

// Xserver cron はこのファイルを実行する。開始時に current の実体を固定するため、
// 実行途中でリリース切替が起きても同じリリースの Artisan だけを使う。
$releaseRoot = realpath(dirname(__DIR__, 2) . '/valzeria_current');
if ($releaseRoot === false || !is_file($releaseRoot . '/artisan')) {
    fwrite(STDERR, "current リリースを解決できません。\n");
    exit(1);
}

$command = [PHP_BINARY, $releaseRoot . '/artisan', 'schedule:run', '--no-interaction'];
$process = proc_open($command, [STDIN, STDOUT, STDERR], $pipes, $releaseRoot);
if (!is_resource($process)) {
    fwrite(STDERR, "schedule:run を開始できません。\n");
    exit(1);
}

exit(proc_close($process));
