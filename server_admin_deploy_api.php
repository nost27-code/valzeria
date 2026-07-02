<?php
/**
 * 管理画面限定デプロイAPI。
 *
 * 管理画面に関係する allowlist 内のファイルだけを valzeria_project へ上書きし、
 * migrate / seed / npm build は実行しない。
 */

$secretToken = 'nostalgia0905';
$publicHtmlDir = __DIR__;
$projectDir = realpath(__DIR__ . '/..') . '/valzeria_project';

header('Content-Type: text/plain; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die("エラー: POSTメソッドのみ許可されています。");
}

if (!isset($_POST['token']) || $_POST['token'] !== $secretToken) {
    http_response_code(403);
    die("エラー: 認証トークンが不正です。");
}

if (!isset($_FILES['admin_deploy_zip']) || $_FILES['admin_deploy_zip']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    die("エラー: 管理画面限定ZIPファイルがアップロードされていません。");
}

if (!class_exists(ZipArchive::class)) {
    http_response_code(500);
    die("エラー: サーバー側PHPでzip拡張が有効ではありません。");
}

if (!file_exists($projectDir) && !mkdir($projectDir, 0755, true)) {
    http_response_code(500);
    die("エラー: プロジェクトディレクトリの作成に失敗しました ({$projectDir})");
}

$allowedPrefixes = [
    'app/Livewire/Admin/',
    'app/Http/Controllers/Admin/',
    'app/Services/Admin/',
    'resources/views/livewire/admin/',
    'resources/views/admin/',
    'public/admin/',
];

$allowedFiles = [
    'resources/views/components/layouts/admin.blade.php',
    'config/admin_update_summaries.php',
    'routes/web.php',
    'server_admin_deploy_api.php',
];

$normalizeZipPath = function (string $path): ?string {
    $path = str_replace('\\', '/', $path);
    $path = ltrim($path, '/');

    if ($path === '' || str_contains($path, "\0")) {
        return null;
    }

    $parts = explode('/', $path);
    foreach ($parts as $part) {
        if ($part === '' || $part === '.' || $part === '..') {
            return null;
        }
    }

    return $path;
};

$isAllowed = function (string $path) use ($allowedPrefixes, $allowedFiles): bool {
    if (in_array($path, $allowedFiles, true)) {
        return true;
    }

    foreach ($allowedPrefixes as $prefix) {
        if (str_starts_with($path, $prefix)) {
            return true;
        }
    }

    return false;
};

$zip = new ZipArchive();
if ($zip->open($_FILES['admin_deploy_zip']['tmp_name']) !== true) {
    http_response_code(500);
    die("エラー: ZIPファイルを開けませんでした。");
}

$entries = [];
$containsRoutes = false;

for ($i = 0; $i < $zip->numFiles; $i++) {
    $rawName = $zip->getNameIndex($i);
    $path = $normalizeZipPath($rawName);

    if ($path === null) {
        http_response_code(400);
        $zip->close();
        die("エラー: 不正なZIPエントリを検出しました ({$rawName})");
    }

    if (str_ends_with($path, '/')) {
        continue;
    }

    if (!$isAllowed($path)) {
        http_response_code(400);
        $zip->close();
        die("エラー: 管理画面限定デプロイで許可されていないファイルです ({$path})");
    }

    if ($path === 'routes/web.php') {
        $containsRoutes = true;
    }

    $entries[] = $path;
}

if ($entries === []) {
    http_response_code(400);
    $zip->close();
    die("エラー: 展開対象ファイルがありません。");
}

$deployed = 0;

foreach ($entries as $path) {
    $targetPath = $projectDir . '/' . $path;
    $targetDir = dirname($targetPath);

    if (!is_dir($targetDir) && !mkdir($targetDir, 0755, true)) {
        http_response_code(500);
        $zip->close();
        die("エラー: 展開先ディレクトリを作成できません ({$targetDir})");
    }

    $stream = $zip->getStream($path);
    if ($stream === false) {
        http_response_code(500);
        $zip->close();
        die("エラー: ZIP内ファイルを読み込めません ({$path})");
    }

    $contents = stream_get_contents($stream);
    fclose($stream);

    if ($contents === false || file_put_contents($targetPath, $contents) === false) {
        http_response_code(500);
        $zip->close();
        die("エラー: ファイルを書き込めません ({$path})");
    }

    $deployed++;
}

$zip->close();

echo "管理画面限定ファイルを展開しました。対象ファイル数: {$deployed}\n";

$selfSource = $projectDir . '/server_admin_deploy_api.php';
$selfDest = $publicHtmlDir . '/server_admin_deploy_api.php';
if (is_file($selfSource)) {
    if (copy($selfSource, $selfDest)) {
        echo "・server_admin_deploy_api.php を最新版に自己更新しました。\n";
    } else {
        echo "・警告: server_admin_deploy_api.php の自己更新に失敗しました。\n";
    }
}

try {
    require $projectDir . '/vendor/autoload.php';
    $app = require_once $projectDir . '/bootstrap/app.php';
    $kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
    $kernel->bootstrap();

    \Illuminate\Support\Facades\Artisan::call('config:clear');
    echo "・config:clear 実行完了\n";

    \Illuminate\Support\Facades\Artisan::call('view:clear');
    echo "・view:clear 実行完了\n";

    if ($containsRoutes) {
        \Illuminate\Support\Facades\Artisan::call('route:clear');
        echo "・route:clear 実行完了（routes/web.php を含むため）\n";
    }

    \Illuminate\Support\Facades\Artisan::call('config:cache');
    echo "・config:cache 実行完了\n";

    \Illuminate\Support\Facades\Artisan::call('view:cache');
    echo "・view:cache 実行完了\n";
} catch (\Throwable $e) {
    echo "・Artisan実行エラー: " . $e->getMessage() . "\n";
}

echo "管理画面限定デプロイが正常に完了しました。\n";
