<?php

declare(strict_types=1);

/**
 * Release-based deploy endpoint.
 *
 * Initial setup (performed by an operator during a maintenance window):
 * - Create ../valzeria_shared/.deploy_secret (random secret, mode 0600)
 * - Create ../valzeria_shared/.deploy_allowed_ips (one trusted IP per line)
 * - Take and verify a database backup.
 *
 * This endpoint never overwrites the active release. A failed preparation
 * leaves ../valzeria_current unchanged.
 */

const VALZERIA_MAX_ZIP_FILES = 16000;
const VALZERIA_MAX_ZIP_BYTES = 220000000;
const VALZERIA_MAX_ZIP_RATIO = 100;
const VALZERIA_SIGNATURE_TTL_SECONDS = 300;

$publicHtmlDir = __DIR__;
// A staging endpoint has an explicit marker in its public directory and keeps
// every release/shared path outside public_html. Production has no marker and
// retains the existing layout.
$isIsolatedStaging = is_file($publicHtmlDir . '/.deploy_staging');
$baseDir = $isIsolatedStaging ? dirname(__DIR__, 2) : dirname(__DIR__);
$deploymentPrefix = $isIsolatedStaging ? 'staging_valzeria' : 'valzeria';
$releasesDir = $baseDir . '/' . $deploymentPrefix . '_releases';
$sharedDir = $baseDir . '/' . $deploymentPrefix . '_shared';
$legacyProjectDir = $baseDir . '/' . $deploymentPrefix . '_project';
$currentLink = $baseDir . '/' . $deploymentPrefix . '_current';
$auditLog = $sharedDir . '/deployments.log';

header('Content-Type: text/plain; charset=UTF-8');

function deploy_fail(int $status, string $message): never
{
    http_response_code($status);
    echo $message . "\n";
    exit;
}

function deploy_log(string $path, array $data): void
{
    $data['at'] = gmdate('c');
    @file_put_contents($path, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
}

function deploy_header(string $name): string
{
    $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));

    return trim((string) ($_SERVER[$key] ?? ''));
}

function deploy_safe_zip_path(string $path): ?string
{
    $path = str_replace('\\', '/', $path);
    if ($path === '' || str_contains($path, "\0") || str_starts_with($path, '/') || preg_match('/^[A-Za-z]:/', $path)) {
        return null;
    }

    $parts = explode('/', $path);
    foreach ($parts as $part) {
        if ($part === '' || $part === '.' || $part === '..') {
            return null;
        }
    }

    return $path;
}

function deploy_claim_nonce(string $sharedDir, string $nonce): void
{
    if (!preg_match('/^[a-f0-9]{32,128}$/', $nonce)) {
        throw new RuntimeException('nonce の形式が不正です。');
    }
    $nonceDir = $sharedDir . '/nonces';
    if (!is_dir($nonceDir) && !mkdir($nonceDir, 0700, true)) {
        throw new RuntimeException('nonce 保管領域を作成できません。');
    }
    $path = $nonceDir . '/' . hash('sha256', $nonce);
    $handle = @fopen($path, 'x');
    if ($handle === false) {
        throw new RuntimeException('同じ署名リクエストは再利用できません。');
    }
    fwrite($handle, (string) time());
    fclose($handle);
    @chmod($path, 0600);
}

function deploy_is_symlink_entry(array $stat): bool
{
    $mode = ((int) ($stat['external_attributes'] ?? 0) >> 16) & 0xF000;

    return $mode === 0xA000;
}

function deploy_atomic_link(string $target, string $link): void
{
    $temporary = $link . '.next-' . bin2hex(random_bytes(6));
    if (!symlink($target, $temporary)) {
        throw new RuntimeException("一時シンボリックリンクを作成できません: {$link}");
    }
    if (!rename($temporary, $link)) {
        @unlink($temporary);
        throw new RuntimeException("シンボリックリンクを切り替えできません: {$link}");
    }
}

function deploy_link_if_missing(string $target, string $link): void
{
    if (is_link($link) || file_exists($link)) {
        return;
    }
    if (!symlink($target, $link)) {
        throw new RuntimeException("共有リンクを作成できません: {$link}");
    }
}

function deploy_remove_new_release_path(string $path): void
{
    if (is_link($path) || is_file($path)) {
        if (!unlink($path)) {
            throw new RuntimeException("新規リリースの一時パスを削除できません: {$path}");
        }
        return;
    }
    if (!is_dir($path)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    if (!rmdir($path)) {
        throw new RuntimeException("新規リリースの一時ディレクトリを削除できません: {$path}");
    }
}

function deploy_copy_missing(string $source, string $destination): void
{
    if (!is_dir($source)) {
        return;
    }
    if (!is_dir($destination) && !mkdir($destination, 0755, true)) {
        throw new RuntimeException("アセット用ディレクトリを作成できません: {$destination}");
    }
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($iterator as $file) {
        $relative = substr($file->getPathname(), strlen($source) + 1);
        $target = $destination . '/' . $relative;
        if ($file->isDir()) {
            if (!is_dir($target) && !mkdir($target, 0755, true)) {
                throw new RuntimeException("アセット用ディレクトリを作成できません: {$target}");
            }
            continue;
        }
        if (!file_exists($target) && !copy($file->getPathname(), $target)) {
            throw new RuntimeException("旧アセットを保持できません: {$relative}");
        }
    }
}

function deploy_prepare_shared_storage(string $storage): void
{
    foreach (['app/public', 'framework/cache/data', 'framework/sessions', 'framework/views', 'logs'] as $relativePath) {
        $path = $storage . '/' . $relativePath;
        if (!is_dir($path) && !mkdir($path, 0755, true)) {
            throw new RuntimeException("共有 storage の初期化に失敗しました: {$relativePath}");
        }
    }
}

function deploy_assert_shared_app_key(string $sharedEnv): void
{
    $contents = @file_get_contents($sharedEnv);
    if ($contents === false || !preg_match('/^APP_KEY\\s*=\\s*(.*?)\\s*$/m', $contents, $matches)) {
        throw new RuntimeException('共有 .env に APP_KEY がありません。ステージング用のアプリケーションキーを設定してください。');
    }

    $key = trim($matches[1], " \t\n\r\0\x0B\"'");
    if ($key === '') {
        throw new RuntimeException('共有 .env の APP_KEY が空です。ステージング用のアプリケーションキーを設定してください。');
    }
}

function deploy_release_health_check(string $releaseDir, string $currentLink): void
{
    if (realpath($currentLink) !== realpath($releaseDir)) {
        throw new RuntimeException('current リリースの切替確認に失敗しました。');
    }
    foreach (['public/index.php', 'artisan', 'vendor/autoload.php', 'bootstrap/app.php'] as $required) {
        if (!is_file($releaseDir . '/' . $required)) {
            throw new RuntimeException("ヘルスチェックで必須ファイルが見つかりません: {$required}");
        }
    }
    Illuminate\Support\Facades\DB::connection()->getPdo();
}

function deploy_write_public_entry(string $publicHtmlDir, string $currentLink): void
{
    $currentLinkLiteral = var_export($currentLink, true);
    $entry = <<<PHP
<?php
declare(strict_types=1);

\$releaseRoot = realpath({$currentLinkLiteral});
if (\$releaseRoot === false || !is_file(\$releaseRoot . '/public/index.php')) {
    http_response_code(503);
    exit('Service temporarily unavailable.');
}

require \$releaseRoot . '/public/index.php';
PHP;
    $temporary = $publicHtmlDir . '/index.php.next';
    if (file_put_contents($temporary, $entry, LOCK_EX) === false || !rename($temporary, $publicHtmlDir . '/index.php')) {
        @unlink($temporary);
        throw new RuntimeException('公開エントリポイントを更新できません。');
    }
}

function deploy_write_public_htaccess(string $publicHtmlDir, string $currentLink): void
{
    $source = $currentLink . '/public/.htaccess';
    if (!is_file($source)) {
        throw new RuntimeException('公開用 .htaccess がリリース内にありません。');
    }

    $temporary = $publicHtmlDir . '/.htaccess.next';
    if (!copy($source, $temporary) || !rename($temporary, $publicHtmlDir . '/.htaccess')) {
        @unlink($temporary);
        throw new RuntimeException('公開用 .htaccess を更新できません。');
    }
}

function deploy_enable_maintenance(string $storage): void
{
    $framework = $storage . '/framework';
    if (!is_dir($framework) && !mkdir($framework, 0755, true)) {
        throw new RuntimeException('メンテナンス用ディレクトリを作成できません。');
    }
    $stub = "<?php\nif (file_exists(\$down = __DIR__ . '/down')) { \$data = json_decode(file_get_contents(\$down), true); http_response_code(503); echo \$data['template'] ?? 'Maintenance'; exit; }\n";
    file_put_contents($framework . '/maintenance.php', $stub, LOCK_EX);
    file_put_contents($framework . '/down', json_encode(['template' => 'ただいまメンテナンス中です。しばらくしてから再度お試しください。'], JSON_UNESCAPED_UNICODE), LOCK_EX);
}

function deploy_disable_maintenance(string $storage): void
{
    @unlink($storage . '/framework/down');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    deploy_fail(405, 'POSTメソッドのみ利用できます。');
}
if (!is_dir($sharedDir) || !is_file($sharedDir . '/.deploy_secret') || !is_file($sharedDir . '/.deploy_allowed_ips')) {
    deploy_fail(503, '安全なデプロイ共有領域が未初期化です。初回移行手順を完了してください。');
}

$allowedIps = array_filter(array_map('trim', file($sharedDir . '/.deploy_allowed_ips', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []));
$remoteIp = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
if ($allowedIps === [] || !in_array($remoteIp, $allowedIps, true)) {
    deploy_log($auditLog, ['event' => 'denied_ip', 'ip' => $remoteIp]);
    deploy_fail(403, '許可されていない送信元です。');
}
$useServerStagedZip = (string) ($_POST['server_staged_zip'] ?? '0') === '1';
$serverStagedZipPath = null;
if ($useServerStagedZip) {
    if (!$isIsolatedStaging) {
        deploy_fail(403, '共有領域へ置いたZIPからのデプロイはステージング専用です。');
    }
    $serverStagedZipPath = $sharedDir . '/manual_upload/staging_deploy.zip';
    if (!is_file($serverStagedZipPath) || !is_readable($serverStagedZipPath)) {
        deploy_fail(400, '共有領域の staging_deploy.zip を確認できません。');
    }
    $zipPath = $serverStagedZipPath;
} elseif (!isset($_FILES['deploy_zip']) || $_FILES['deploy_zip']['error'] !== UPLOAD_ERR_OK) {
    $uploadError = isset($_FILES['deploy_zip'])
        ? (int) $_FILES['deploy_zip']['error']
        : UPLOAD_ERR_NO_FILE;
    $contentLength = (string) ($_SERVER['CONTENT_LENGTH'] ?? 'unknown');
    deploy_fail(
        400,
        'デプロイZIPを受信できませんでした。'
        . " upload_error={$uploadError}; content_length={$contentLength};"
        . ' post_max_size=' . ini_get('post_max_size')
        . '; upload_max_filesize=' . ini_get('upload_max_filesize')
    );
} else {
    $zipPath = (string) $_FILES['deploy_zip']['tmp_name'];
}

$timestamp = deploy_header('X-Deploy-Timestamp');
$nonce = deploy_header('X-Deploy-Nonce');
$signature = deploy_header('X-Deploy-Signature');
$secret = trim((string) file_get_contents($sharedDir . '/.deploy_secret'));
if (!ctype_digit($timestamp) || abs(time() - (int) $timestamp) > VALZERIA_SIGNATURE_TTL_SECONDS || $secret === '') {
    deploy_fail(401, 'デプロイ署名の前提条件が不正です。');
}
$payloadHash = hash_file('sha256', $zipPath);
$expected = hash_hmac('sha256', $timestamp . "\n" . $nonce . "\n" . $payloadHash, $secret);
if ($payloadHash === false || !hash_equals($expected, $signature)) {
    deploy_log($auditLog, ['event' => 'denied_signature', 'ip' => $remoteIp]);
    deploy_fail(403, 'デプロイ署名が一致しません。');
}
try {
    deploy_claim_nonce($sharedDir, $nonce);
} catch (RuntimeException $error) {
    deploy_log($auditLog, ['event' => 'denied_replay', 'ip' => $remoteIp, 'error' => $error->getMessage()]);
    deploy_fail(409, $error->getMessage());
}

if (!is_dir($releasesDir) && !mkdir($releasesDir, 0755, true)) {
    deploy_fail(500, 'リリース領域を作成できません。');
}
$lock = fopen($sharedDir . '/deploy.lock', 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    deploy_fail(409, '別のデプロイ処理が実行中です。');
}

$maintenanceEnabled = false;
$releaseDir = null;
try {
    $mode = (string) ($_POST['migration_mode'] ?? 'none');
    $bootstrapEmpty = (string) ($_POST['bootstrap_empty'] ?? '0') === '1';
    $resetStagingDatabase = (string) ($_POST['reset_staging_database'] ?? '0') === '1';
    if (!in_array($mode, ['none', 'backward_compatible', 'maintenance_required'], true)) {
        throw new RuntimeException('migration_mode は none / backward_compatible / maintenance_required を指定してください。');
    }
    $initialMigration = !is_link($currentLink);
    if ($initialMigration && !$bootstrapEmpty && !is_dir($legacyProjectDir)) {
        throw new RuntimeException('初回移行元の valzeria_project が見つかりません。');
    }
    if ($bootstrapEmpty && !$isIsolatedStaging) {
        throw new RuntimeException('空DB初期化はステージング専用です。');
    }
    if ($resetStagingDatabase && !$isIsolatedStaging) {
        throw new RuntimeException('ステージングDBの初期化はステージング専用です。');
    }
    $sharedStorage = $sharedDir . '/storage';
    $sharedEnv = $sharedDir . '/.env';
    $sharedAssets = $sharedDir . '/assets';
    if (!is_dir($sharedAssets) && !mkdir($sharedAssets, 0755, true)) {
        throw new RuntimeException('共有アセット領域を作成できません。');
    }
    if (!$bootstrapEmpty) {
        deploy_link_if_missing($legacyProjectDir . '/storage', $sharedStorage);
        deploy_link_if_missing($legacyProjectDir . '/.env', $sharedEnv);
        deploy_link_if_missing($legacyProjectDir . '/public/build', $sharedAssets . '/build');
    }
    if (!is_dir($sharedStorage) || !is_file($sharedEnv)) {
        throw new RuntimeException('共有 storage または .env を確認できません。');
    }
    deploy_assert_shared_app_key($sharedEnv);
    deploy_prepare_shared_storage($sharedStorage);
    if ($initialMigration || $mode === 'maintenance_required' || $resetStagingDatabase) {
        deploy_enable_maintenance($sharedStorage);
        $maintenanceEnabled = true;
    }

    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) {
        throw new RuntimeException('ZIPを開けません。');
    }
    if ($zip->numFiles > VALZERIA_MAX_ZIP_FILES) {
        throw new RuntimeException('ZIP内のファイル数が上限を超えています。');
    }
    $releaseId = gmdate('Ymd_His') . '_' . bin2hex(random_bytes(4));
    $releaseDir = $releasesDir . '/' . $releaseId;
    if (!mkdir($releaseDir, 0755, true)) {
        throw new RuntimeException('新規リリース領域を作成できません。');
    }
    $totalBytes = 0;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $stat = $zip->statIndex($i);
        $name = (string) ($stat['name'] ?? '');
        $path = deploy_safe_zip_path($name);
        if ($path === null || deploy_is_symlink_entry($stat ?: [])) {
            throw new RuntimeException("不正なZIPエントリを検出しました: {$name}");
        }
        if (str_ends_with($path, '/')) {
            continue;
        }
        $totalBytes += (int) ($stat['size'] ?? 0);
        if ($totalBytes > VALZERIA_MAX_ZIP_BYTES) {
            throw new RuntimeException('展開後のZIPサイズが上限を超えています。');
        }
        $compressedBytes = (int) ($stat['comp_size'] ?? 0);
        if (((int) ($stat['size'] ?? 0) > 0) && ($compressedBytes === 0 || ((int) ($stat['size'] ?? 0) / $compressedBytes) > VALZERIA_MAX_ZIP_RATIO)) {
            throw new RuntimeException("ZIP圧縮率が上限を超えています: {$path}");
        }
        $target = $releaseDir . '/' . $path;
        $parent = dirname($target);
        if (!is_dir($parent) && !mkdir($parent, 0755, true)) {
            throw new RuntimeException("展開先を作成できません: {$path}");
        }
        $input = $zip->getStream($name);
        if ($input === false || ($output = fopen($target, 'xb')) === false) {
            throw new RuntimeException("ZIPを安全に展開できません: {$path}");
        }
        $written = stream_copy_to_stream($input, $output);
        fclose($input);
        fclose($output);
        if ($written !== (int) ($stat['size'] ?? 0) || !is_file($target) || filesize($target) !== (int) ($stat['size'] ?? 0)) {
            throw new RuntimeException("ZIP展開後の検証に失敗しました: {$path}");
        }
    }
    $zip->close();

    @unlink($releaseDir . '/public/hot');
    if (!is_file($releaseDir . '/artisan') || !is_file($releaseDir . '/vendor/autoload.php')) {
        throw new RuntimeException('リリースの必須ファイルが不足しています。');
    }
    deploy_remove_new_release_path($releaseDir . '/storage');
    deploy_remove_new_release_path($releaseDir . '/.env');
    deploy_atomic_link($sharedStorage, $releaseDir . '/storage');
    deploy_atomic_link($sharedEnv, $releaseDir . '/.env');

    $oldRelease = is_link($currentLink)
        ? realpath($currentLink)
        : ($initialMigration ? realpath($legacyProjectDir) : null);
    if ($oldRelease !== false && $oldRelease !== null) {
        deploy_copy_missing($oldRelease . '/public/images', $releaseDir . '/public/images');
    }
    deploy_copy_missing($releaseDir . '/public/build', $sharedAssets . '/build');

    require $releaseDir . '/vendor/autoload.php';
    $app = require $releaseDir . '/bootstrap/app.php';
    $kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
    $kernel->bootstrap();
    Illuminate\Support\Facades\Artisan::call('config:clear');
    if (!$resetStagingDatabase) {
        Illuminate\Support\Facades\Artisan::call('cache:clear');
    }
    Illuminate\Support\Facades\Artisan::call('view:clear');
    if ($mode !== 'none') {
        if ($resetStagingDatabase) {
            $wipeStatus = Illuminate\Support\Facades\Artisan::call('db:wipe', ['--force' => true]);
            if ($wipeStatus !== 0) {
                throw new RuntimeException('ステージングDBの初期化に失敗しました。');
            }
        }
        $migrationCommand = 'migrate';
        $disableForeignKeyChecks = $resetStagingDatabase;
        if ($disableForeignKeyChecks) {
            Illuminate\Support\Facades\DB::statement('SET FOREIGN_KEY_CHECKS=0');
        }
        try {
            $migrationStatus = Illuminate\Support\Facades\Artisan::call($migrationCommand, ['--force' => true]);
        } finally {
            if ($disableForeignKeyChecks) {
                Illuminate\Support\Facades\DB::statement('SET FOREIGN_KEY_CHECKS=1');
            }
        }
        if ($migrationStatus !== 0) {
            throw new RuntimeException('migration に失敗しました。');
        }
    }
    if (($initialMigration && $bootstrapEmpty) || $resetStagingDatabase) {
        $seedStatus = Illuminate\Support\Facades\Artisan::call('db:seed', ['--force' => true]);
        if ($seedStatus !== 0) {
            throw new RuntimeException('初回マスタデータ投入に失敗しました。');
        }
    }
    if (($initialMigration && $bootstrapEmpty) || $resetStagingDatabase) {
        Illuminate\Support\Facades\Artisan::call('cache:clear');
    }
    Illuminate\Support\Facades\Artisan::call('config:cache');
    Illuminate\Support\Facades\Artisan::call('event:cache');
    Illuminate\Support\Facades\Artisan::call('view:cache');

    if ($initialMigration) {
        deploy_atomic_link($bootstrapEmpty ? $releaseDir : $legacyProjectDir, $currentLink);
        deploy_write_public_entry($publicHtmlDir, $currentLink);
        foreach (['build', 'images', 'tools', 'contact_images', 'favicon.ico', 'robots.txt'] as $asset) {
            $target = $asset === 'build'
                ? $sharedAssets . '/build'
                : $currentLink . '/public/' . $asset;
            $link = $publicHtmlDir . '/' . $asset;
            if (is_link($link) || is_file($link)) {
                @unlink($link);
            }
            if (!file_exists($link) && !is_link($link)) {
                symlink($target, $link);
            }
        }
        deploy_atomic_link($sharedStorage . '/app/public', $publicHtmlDir . '/storage');
    }
    $previousRelease = $oldRelease !== false ? $oldRelease : null;
    deploy_atomic_link($releaseDir, $currentLink);
    try {
        deploy_release_health_check($releaseDir, $currentLink);
    } catch (Throwable $healthError) {
        if (is_string($previousRelease) && is_dir($previousRelease)) {
            deploy_atomic_link($previousRelease, $currentLink);
            if (function_exists('opcache_reset')) {
                @opcache_reset();
            }
            deploy_log($auditLog, ['event' => 'rolled_back', 'ip' => $remoteIp, 'release' => $releaseId, 'reason' => $healthError->getMessage()]);
        }
        throw new RuntimeException('切替後ヘルスチェックに失敗し、直前リリースへ戻しました: ' . $healthError->getMessage());
    }
    $userIni = $releaseDir . '/public/.user.ini';
    if (is_file($userIni)) {
        @copy($userIni, $publicHtmlDir . '/.user.ini');
    }
    $serviceWorker = $releaseDir . '/public/sw.js';
    if (is_file($serviceWorker)) {
        $temporaryWorker = $publicHtmlDir . '/sw.js.next';
        if (copy($serviceWorker, $temporaryWorker)) {
            @rename($temporaryWorker, $publicHtmlDir . '/sw.js');
        }
    }
    if (function_exists('opcache_reset')) {
        @opcache_reset();
    }
    deploy_write_public_htaccess($publicHtmlDir, $currentLink);
    $source = $releaseDir . '/server_deploy_api.php';
    if (is_file($source)) {
        @copy($source, $publicHtmlDir . '/server_deploy_api.php');
    }
    $adminSource = $releaseDir . '/server_admin_deploy_api.php';
    if (is_file($adminSource)) {
        @copy($adminSource, $publicHtmlDir . '/server_admin_deploy_api.php');
    }
    deploy_log($auditLog, ['event' => 'success', 'ip' => $remoteIp, 'release' => $releaseId, 'migration_mode' => $mode, 'bootstrap_empty' => $bootstrapEmpty, 'reset_staging_database' => $resetStagingDatabase]);
    echo "デプロイが正常に完了しました。\nrelease: {$releaseId}\n";
} catch (Throwable $error) {
    deploy_log($auditLog, ['event' => 'failed', 'ip' => $remoteIp, 'release' => $releaseDir, 'error' => $error->getMessage()]);
    deploy_fail(500, 'デプロイを中止しました。公開中リリースは切り替えていません: ' . $error->getMessage());
} finally {
    if ($maintenanceEnabled) {
        deploy_disable_maintenance($sharedDir . '/storage');
    }
    if ($serverStagedZipPath !== null && is_file($serverStagedZipPath)) {
        @unlink($serverStagedZipPath);
    }
    flock($lock, LOCK_UN);
    fclose($lock);
}
