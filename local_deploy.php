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
$deployTarget = (string) (getenv('VALZERIA_DEPLOY_TARGET') ?: 'production');
if (!in_array($deployTarget, ['production', 'staging'], true)) {
    fwrite(STDERR, "エラー: VALZERIA_DEPLOY_TARGET は production / staging を指定してください。\n");
    exit(1);
}

$defaultServerApiUrl = $deployTarget === 'staging'
    ? 'https://staging.valzeria.com/server_deploy_api.php'
    : 'https://valzeria.com/server_deploy_api.php';
$expectedDeployHost = $deployTarget === 'staging' ? 'staging.valzeria.com' : 'valzeria.com';
$serverApiUrl = (string) (getenv('VALZERIA_DEPLOY_API_URL') ?: $defaultServerApiUrl);
$serverApiParts = parse_url($serverApiUrl);
if (($serverApiParts['scheme'] ?? '') !== 'https'
    || strcasecmp((string) ($serverApiParts['host'] ?? ''), $expectedDeployHost) !== 0) {
    fwrite(STDERR, "エラー: {$deployTarget} デプロイの送信先は https://{$expectedDeployHost}/server_deploy_api.php に限定されています。\n");
    exit(1);
}

$deploySecret = trim((string) getenv('VALZERIA_DEPLOY_SECRET'));
$localSecretFile = __DIR__ . ($deployTarget === 'staging' ? '/.env.staging.local' : '/.env.production.local');
if ($deploySecret === '' && is_file($localSecretFile)) {
    foreach (file($localSecretFile, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        if (!str_starts_with(trim($line), 'VALZERIA_DEPLOY_SECRET=')) {
            continue;
        }

        [, $value] = explode('=', trim($line), 2);
        $deploySecret = trim($value, " \t\n\r\0\x0B\"'");
        break;
    }
}
$migrationMode = (string) (getenv('DEPLOY_MIGRATION_MODE') ?: 'none');
$bootstrapEmpty = getenv('DEPLOY_BOOTSTRAP_EMPTY') === '1';
$resetStagingDatabase = $deployTarget === 'staging' && getenv('DEPLOY_RESET_STAGING_DATABASE') === '1';

if ($deploySecret === '') {
    fwrite(STDERR, "エラー: VALZERIA_DEPLOY_SECRET を設定するか、{$localSecretFile} に同名の値を保存してください。\n");
    exit(1);
}
if (!in_array($migrationMode, ['none', 'backward_compatible', 'maintenance_required'], true)) {
    fwrite(STDERR, "エラー: DEPLOY_MIGRATION_MODE は none / backward_compatible / maintenance_required を指定してください。\n");
    exit(1);
}

$sourceDir = __DIR__;
$zipFilePath = __DIR__ . "/deploy_temp.zip";
$phpBinary = escapeshellarg(PHP_BINARY);

// 除外するファイル・ディレクトリ
// Composer vendor はサーバーの直前リリースから引き継ぐ。
$excludes = [
    '.git',
    '.claude',
    '.codex',
    '.codex-remote-attachments',
    'node_modules',
    'vendor',
    'outputs',
    'storage',
    'scratch',
    '.gemini',
    'backups',
    '.env',
    '.deploy_secret',
    '.deploy_allowed_ips',
    'database/database.sqlite',
    'docs',
    'deploy_',
    'ffa_backup',
    'render_test.php',
    'scratch_',
    'local_deploy.php',
    'local_deploy_staging.php',
    'public/hot',
    // サーバー側の vendor と一致しない Composer パッケージ検出キャッシュを持ち込まない。
    'bootstrap/cache/packages.php',
    'bootstrap/cache/services.php',
    'bootstrap/cache/views',
];

// 既存リリースから画像を引き継ぐ軽量デプロイ用。画像を変更したリリースでは指定しない。
if (getenv('DEPLOY_REUSE_EXISTING_IMAGES') === '1') {
    $excludes[] = 'public/images';
}

echo "== {$deployTarget} デプロイ処理を開始します ==\n";

// マスタデータの説明文と実装の食い違いを事前チェック
echo "[0] マスタデータの整合性チェック中...\n";
$validateOutput = [];
$validateReturnVar = 0;
exec($phpBinary . ' artisan valzeria:validate-master-data 2>&1', $validateOutput, $validateReturnVar);
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

    if (!$skip && $file->isFile()) {
        $zip->addFile($filePath, $relativePath);
        $count++;
    }
}
$zip->close();

// .env / storage はサーバー側の共有領域を使う。ZIPへ本番設定を含めない。
$zip->open($zipFilePath);
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
$timestamp = (string) time();
$nonce = bin2hex(random_bytes(16));
$payloadHash = hash_file('sha256', $zipFilePath);
$signature = hash_hmac('sha256', $timestamp . "\n" . $nonce . "\n" . $payloadHash, $deploySecret);
$postData = [
    'deploy_zip' => $cfile,
    'migration_mode' => $migrationMode,
    'bootstrap_empty' => $bootstrapEmpty ? '1' : '0',
    'reset_staging_database' => $resetStagingDatabase ? '1' : '0',
];

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $serverApiUrl);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'X-Deploy-Timestamp: ' . $timestamp,
    'X-Deploy-Nonce: ' . $nonce,
    'X-Deploy-Signature: ' . $signature,
]);
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
        . '-H "X-Deploy-Timestamp: ' . addcslashes($timestamp, '\\"') . '" '
        . '-H "X-Deploy-Nonce: ' . addcslashes($nonce, '\\"') . '" '
        . '-H "X-Deploy-Signature: ' . addcslashes($signature, '\\"') . '" '
        . '-F "migration_mode=' . addcslashes($migrationMode, '\\"') . '" '
        . '-F "reset_staging_database=' . ($resetStagingDatabase ? '1' : '0') . '" '
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
