<?php

declare(strict_types=1);

/**
 * Staging-only trigger for a ZIP that an operator has placed in the private
 * staging shared directory. The ZIP itself never passes through PHP upload.
 */

$zipFilePath = __DIR__ . '/deploy_temp.zip';
$serverApiUrl = 'https://staging.valzeria.com/server_deploy_api.php';
$localSecretFile = __DIR__ . '/.env.staging.local';
$deploySecret = trim((string) getenv('VALZERIA_STAGING_DEPLOY_SECRET'));

if ($deploySecret === '' && is_file($localSecretFile)) {
    foreach (file($localSecretFile, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        if (str_starts_with(trim($line), 'VALZERIA_STAGING_DEPLOY_SECRET=')) {
            [, $value] = explode('=', trim($line), 2);
            $deploySecret = trim($value, " \t\n\r\0\x0B\"'");
            break;
        }
    }
}

if (!is_file($zipFilePath) || filesize($zipFilePath) < 1) {
    fwrite(STDERR, "エラー: deploy_temp.zip がありません。先に DEPLOY_BUILD_ONLY=1 でステージングZIPを作成してください。\n");
    exit(1);
}
if ($deploySecret === '') {
    fwrite(STDERR, "エラー: VALZERIA_STAGING_DEPLOY_SECRET を設定してください。\n");
    exit(1);
}

$migrationMode = (string) (getenv('DEPLOY_MIGRATION_MODE') ?: 'backward_compatible');
if (!in_array($migrationMode, ['none', 'backward_compatible', 'maintenance_required'], true)) {
    fwrite(STDERR, "エラー: DEPLOY_MIGRATION_MODE は none / backward_compatible / maintenance_required を指定してください。\n");
    exit(1);
}

$timestamp = (string) time();
$nonce = bin2hex(random_bytes(16));
$payloadHash = hash_file('sha256', $zipFilePath);
$signature = hash_hmac('sha256', $timestamp . "\n" . $nonce . "\n" . $payloadHash, $deploySecret);
$postData = [
    'server_staged_zip' => '1',
    'migration_mode' => $migrationMode,
    'bootstrap_empty' => '0',
    'reset_staging_database' => getenv('STAGING_DEPLOY_RESET_DATABASE') === '1' ? '1' : '0',
];

echo "== 共有領域ZIPからステージングへ反映します ==\n";
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
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ((int) $httpCode === 0 && $curlError !== '') {
    fwrite(STDERR, "PHP cURLエラー: {$curlError}\n");
}
echo "HTTPステータス: {$httpCode}\n";
echo "サーバーからの応答:\n{$response}\n";
exit((int) $httpCode >= 200 && (int) $httpCode < 300 ? 0 : 1);
