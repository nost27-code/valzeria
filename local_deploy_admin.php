<?php
/**
 * 管理画面だけを反映するローカル側送信スクリプト。
 *
 * 使い方:
 * - php local_deploy_admin.php
 * - ADMIN_DEPLOY_INCLUDE_ROUTES=1 php local_deploy_admin.php
 * - DEPLOY_BUILD_ONLY=1 php local_deploy_admin.php
 *
 * 注意:
 * - npm run build は実行しません。CSS/JSビルドが必要な変更は通常の local_deploy.php を使ってください。
 * - routes/web.php はプレイヤー側ルートも含むため、既定では送信しません。
 */

$serverApiUrl = 'https://valzeria.com/server_admin_deploy_api.php';
$secretToken = 'nostalgia0905';

$sourceDir = __DIR__;
$zipFilePath = __DIR__ . '/deploy_admin_temp.zip';
$includeRoutes = getenv('ADMIN_DEPLOY_INCLUDE_ROUTES') === '1';

$includePaths = [
    'app/Livewire/Admin',
    'app/Http/Controllers/Admin',
    'app/Services/Admin',
    'resources/views/livewire/admin',
    'resources/views/admin',
    'resources/views/components/layouts/admin.blade.php',
    'config/admin_update_summaries.php',
    'public/admin',
    'server_admin_deploy_api.php',
];

if ($includeRoutes) {
    $includePaths[] = 'routes/web.php';
}

echo "== 管理画面限定デプロイを開始します ==\n";
echo "・npm run build / migrate / seed は実行しません。\n";
if ($includeRoutes) {
    echo "・ADMIN_DEPLOY_INCLUDE_ROUTES=1 のため routes/web.php も含めます。\n";
}

if (!class_exists(ZipArchive::class)) {
    die("エラー: PHP zip拡張が有効ではありません。php -d extension=zip local_deploy_admin.php で実行してください。\n");
}

$zip = new ZipArchive();
if ($zip->open($zipFilePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    die("エラー: ZIPファイルの作成に失敗しました。\n");
}

$count = 0;
$skipped = [];

$addFile = function (string $absolutePath, string $relativePath) use ($zip, &$count): void {
    $normalized = str_replace('\\', '/', $relativePath);
    if ($zip->addFile($absolutePath, $normalized)) {
        $count++;
    }
};

foreach ($includePaths as $relativePath) {
    $absolutePath = $sourceDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);

    if (is_file($absolutePath)) {
        $addFile($absolutePath, $relativePath);
        continue;
    }

    if (is_dir($absolutePath)) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($absolutePath, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }

            $filePath = $file->getPathname();
            $entryName = substr($filePath, strlen($sourceDir) + 1);
            $addFile($filePath, $entryName);
        }
        continue;
    }

    $skipped[] = $relativePath;
}

$zip->close();

echo "ZIP作成完了。対象ファイル数: {$count}\n";
if ($skipped !== []) {
    echo "存在しないためスキップ: " . implode(', ', $skipped) . "\n";
}

if ($count === 0) {
    if (file_exists($zipFilePath)) {
        unlink($zipFilePath);
    }
    die("エラー: デプロイ対象ファイルがありません。\n");
}

$filesizeMb = round(filesize($zipFilePath) / 1024 / 1024, 2);
echo "ZIPファイルサイズ: {$filesizeMb} MB\n";

if (getenv('DEPLOY_BUILD_ONLY') === '1') {
    echo "DEPLOY_BUILD_ONLY=1 のため、アップロードせずZIPを残して終了します。\n";
    echo "ZIP: {$zipFilePath}\n";
    exit(0);
}

echo "サーバーへ管理画面限定ZIPを送信中... ({$serverApiUrl})\n";

$cfile = new CURLFile($zipFilePath, 'application/zip', 'deploy_admin_temp.zip');
$postData = [
    'token' => $secretToken,
    'admin_deploy_zip' => $cfile,
];

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $serverApiUrl);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ((int) $httpCode === 0 && $curlError !== '') {
    echo "PHP cURLエラー: {$curlError}\n";
}

if ((int) $httpCode === 0 && stripos(PHP_OS_FAMILY, 'Windows') !== false) {
    echo "PHP cURLで応答を取得できなかったため、curl.exe で再送します...\n";
    $curlCommand = 'curl.exe -s -w "\nHTTP_CODE:%{http_code}\n" '
        . '-X POST '
        . '-F "token=' . addcslashes($secretToken, '\\"') . '" '
        . '-F "admin_deploy_zip=@' . addcslashes($zipFilePath, '\\"') . ';type=application/zip;filename=deploy_admin_temp.zip" '
        . '"' . addcslashes($serverApiUrl, '\\"') . '"';
    $fallbackOutput = [];
    $fallbackReturn = 0;
    exec($curlCommand, $fallbackOutput, $fallbackReturn);
    $fallbackResponse = implode("\n", $fallbackOutput);
    if (preg_match('/HTTP_CODE:(\d+)/', $fallbackResponse, $matches)) {
        $httpCode = (int) $matches[1];
        $response = trim(preg_replace('/\n?HTTP_CODE:\d+\s*$/', '', $fallbackResponse));
    } else {
        $response = $fallbackResponse;
    }
}

echo "\n== 送信結果 ==\n";
echo "HTTPステータス: {$httpCode}\n";
echo "サーバーからの応答:\n{$response}\n";

if (file_exists($zipFilePath)) {
    unlink($zipFilePath);
    echo "一時ZIPファイルを削除しました。\n";
}

echo "管理画面限定デプロイ処理が終了しました。\n";
