<?php

declare(strict_types=1);

/**
 * Staging-only deploy entry point.
 *
 * Required environment variables (never commit their values):
 * - VALZERIA_STAGING_DEPLOY_SECRET
 * Optional:
 * - VALZERIA_STAGING_DEPLOY_API_URL (must remain staging.valzeria.com)
 * - STAGING_DEPLOY_BOOTSTRAP_EMPTY=1 (first empty staging release only)
 * - STAGING_DEPLOY_RESET_DATABASE=1 (explicitly wipe and reseed the staging DB)
 * - STAGING_DEPLOY_ALLOW_DIRTY=1 (send the current uncommitted worktree snapshot)
 */

$stagingApiUrl = (string) (getenv('VALZERIA_STAGING_DEPLOY_API_URL')
    ?: 'https://staging.valzeria.com/server_deploy_api.php');
$stagingSecret = trim((string) getenv('VALZERIA_STAGING_DEPLOY_SECRET'));

if ($stagingSecret === '') {
    $localSecretFile = __DIR__ . '/.env.staging.local';
    if (is_file($localSecretFile)) {
        foreach (file($localSecretFile, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if (!str_starts_with(trim($line), 'VALZERIA_STAGING_DEPLOY_SECRET=')) {
                continue;
            }

            [, $value] = explode('=', trim($line), 2);
            $stagingSecret = trim($value, " \t\n\r\0\x0B\"'");
            break;
        }
    }
}

if ($stagingSecret === '') {
    fwrite(STDERR, "エラー: VALZERIA_STAGING_DEPLOY_SECRET を設定するか、.env.staging.local に同名の値を保存してください。\n");
    exit(1);
}

$statusOutput = [];
$statusExitCode = 0;
exec('git status --porcelain --untracked-files=all', $statusOutput, $statusExitCode);
if ($statusExitCode !== 0) {
    fwrite(STDERR, "エラー: Gitの作業状態を確認できません。\n");
    exit(1);
}
if ($statusOutput !== [] && getenv('STAGING_DEPLOY_ALLOW_DIRTY') !== '1') {
    fwrite(STDERR, "エラー: 未コミットの変更があります。\n");
    fwrite(STDERR, "未コミットのファイル:\n" . implode("\n", $statusOutput) . "\n");
    fwrite(STDERR, "作業中スナップショットをステージングへ送る場合だけ、STAGING_DEPLOY_ALLOW_DIRTY=1 を設定してください。\n");
    exit(1);
}
if ($statusOutput !== []) {
    fwrite(STDERR, "警告: 未コミットの作業中スナップショットをステージングへ送信します。\n");
}

putenv('VALZERIA_DEPLOY_TARGET=staging');
putenv('VALZERIA_DEPLOY_API_URL=' . $stagingApiUrl);
putenv('VALZERIA_DEPLOY_SECRET=' . $stagingSecret);
if (getenv('DEPLOY_MIGRATION_MODE') === false || getenv('DEPLOY_MIGRATION_MODE') === '') {
    putenv('DEPLOY_MIGRATION_MODE=backward_compatible');
}
putenv('DEPLOY_BOOTSTRAP_EMPTY=' . (getenv('STAGING_DEPLOY_BOOTSTRAP_EMPTY') === '1' ? '1' : '0'));
putenv('DEPLOY_RESET_STAGING_DATABASE=' . (getenv('STAGING_DEPLOY_RESET_DATABASE') === '1' ? '1' : '0'));

require __DIR__ . '/local_deploy.php';
