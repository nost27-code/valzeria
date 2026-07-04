<?php
/**
 * ローカル側送信スクリプト
 *
 * 【使い方】
 * 1. $serverApiUrl をXserverに配置した deploy_api.php のURLに変更します。
 * 2. $secretToken をサーバー側と同じ文字列に変更します。
 * 3. コマンドプロンプトやPowerShellで `php local_deploy.php` と実行します。
 */

// --- 設定 ---
$serverApiUrl = "https://valzeria.com/server_deploy_api.php"; // XserverのURL
$secretToken = "nostalgia0905"; // サーバー側と合わせる

$sourceDir = __DIR__;
$zipFilePath = __DIR__ . "/deploy_temp.zip";

// 除外するファイル・ディレクトリ
// vendor は全体除外するが、Stripe SDK とオートローダーだけは含める（$vendorIncludes 参照）
$excludes = [
    '.git',
    '.claude',
    '.codex',
    '.codex-remote-attachments',
    'node_modules',
    'outputs',
    'storage',
    '.gemini',
    'backups',
    '.env',
    'docs',
    'deploy_temp.zip',
    'ffa_backup',
    'local_deploy.php',
    'public/hot',
];

// vendor 配下でデプロイに含めるパス（Stripe SDK + composer オートローダー）
$vendorIncludes = [
    'vendor/stripe',
    'vendor/autoload.php',
    'vendor/composer',
];

echo "== デプロイ処理を開始します ==\n";

// マスタデータの説明文と実装の食い違いを事前チェック
echo "[0] マスタデータの整合性チェック中...\n";
$validateOutput = [];
$validateReturnVar = 0;
exec('php artisan valzeria:validate-master-data 2>&1', $validateOutput, $validateReturnVar);
echo implode("\n", $validateOutput) . "\n";
if ($validateReturnVar !== 0) {
    die("エラー: マスタデータに不整合があります。デプロイを中止します。\n");
}

// Viteのビルドを実行 (必要な場合)
echo "[1] npm run build を実行中...\n";
exec("npm run build", $output, $returnVar);
if ($returnVar !== 0) {
    echo "警告: npm run build に失敗したか、環境がありません。\n";
} else {
    echo "ビルド完了。\n";
}

// ZIPファイルの作成
echo "[2] プロジェクトをZIP圧縮中...\n";
$zip = new ZipArchive();
if ($zip->open($zipFilePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    die("エラー: ZIPファイルの作成に失敗しました。\n");
}

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($sourceDir, RecursiveDirectoryIterator::SKIP_DOTS)
);

$count = 0;
foreach ($iterator as $file) {
    $filePath = $file->getPathname();
    $relativePath = str_replace($sourceDir . DIRECTORY_SEPARATOR, '', $filePath);
    $relativePath = str_replace('\\', '/', $relativePath);

    // 除外チェック
    $skip = false;
    foreach ($excludes as $exclude) {
        if (strpos($relativePath, $exclude) === 0) {
            $skip = true;
            break;
        }
    }

    // vendor 配下は許可リストに一致するものだけ含める
    if (!$skip && strpos($relativePath, 'vendor/') === 0) {
        $allowed = false;
        foreach ($vendorIncludes as $vi) {
            if (strpos($relativePath, $vi) === 0) {
                $allowed = true;
                break;
            }
        }
        if (!$allowed) {
            $skip = true;
        }
    }

    if (!$skip && $file->isFile()) {
        $zip->addFile($filePath, $relativePath);
        $count++;
    }
}
$zip->close();

// ★初回用の特別処理：本番用.envと空のstorageフォルダ構造をZIPに直接追加する
$zip->open($zipFilePath);
if (file_exists(__DIR__ . '/production.env')) {
    $zip->addFile(__DIR__ . '/production.env', '.env');
}
$zip->addEmptyDir('storage');
$zip->addEmptyDir('storage/app');
$zip->addEmptyDir('storage/app/public');
$zip->addEmptyDir('storage/framework');
$zip->addEmptyDir('storage/framework/cache');
$zip->addEmptyDir('storage/framework/cache/data');
$zip->addEmptyDir('storage/framework/sessions');
$zip->addEmptyDir('storage/framework/testing');
$zip->addEmptyDir('storage/framework/views');
$zip->addEmptyDir('storage/logs');
$zip->close();

echo "圧縮完了。対象ファイル数: {$count}\n";

// ファイルサイズチェック
$filesizeMb = round(filesize($zipFilePath) / 1024 / 1024, 2);
echo "ZIPファイルサイズ: {$filesizeMb} MB\n";
if ($filesizeMb > 50) {
    echo "警告: ZIPサイズが大きすぎます。XserverのPHPアップロード制限 (upload_max_filesize) を超過する可能性があります。\n";
}

if (getenv('DEPLOY_BUILD_ONLY') === '1') {
    echo "DEPLOY_BUILD_ONLY=1 のため、アップロードせずZIPを残して終了します。\n";
    echo "ZIP: {$zipFilePath}\n";
    exit(0);
}

// サーバーへPOST送信
echo "[3] サーバーへファイルを送信中... ({$serverApiUrl})\n";

$cfile = new CURLFile($zipFilePath, 'application/zip', 'deploy_temp.zip');
$postData = [
    'token' => $secretToken,
    'deploy_zip' => $cfile
];

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $serverApiUrl);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // ローカルテスト等でSSLエラーになる場合の回避
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
        . '-F "deploy_zip=@' . addcslashes($zipFilePath, '\\"') . ';type=application/zip;filename=deploy_temp.zip" '
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

// 後片付け
if (file_exists($zipFilePath)) {
    unlink($zipFilePath);
    echo "一時ZIPファイルを削除しました。\n";
}

echo "処理が終了しました。\n";
