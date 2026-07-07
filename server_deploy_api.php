<?php
/**
 * サーバー側受信スクリプト (Xserver等へ配置)
 * 【バージョン2: セキュアな非公開領域分離パターン】
 * 
 * 展開先を public_html の一つ上の非公開領域(valzeria_project)とし、
 * public_html 内には中継用の index.php とアセットへのシンボリックリンクのみを生成します。
 */

// --- 設定 ---
$secretToken = "nostalgia0905"; // ローカル側と合わせたトークン

// Xserverの public_html ディレクトリ（このファイルが置かれている場所）
$publicHtmlDir = __DIR__;

// プロジェクトの展開先（public_html の一つ上の階層にある valzeria_project フォルダ）
$projectDir = realpath(__DIR__ . '/..') . '/valzeria_project';

// --- 処理開始 ---
header("Content-Type: text/plain; charset=UTF-8");

// POSTメソッドのみ許可
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die("エラー: Method Not Allowed");
}

// トークン認証
$postToken = $_POST['token'] ?? '';
if ($postToken !== $secretToken) {
    http_response_code(403);
    die("エラー: トークンが一致しません。認証に失敗しました。");
}

// ファイルのアップロード確認
if (!isset($_FILES['deploy_zip'])) {
    http_response_code(400);
    die("エラー: ZIPファイルがアップロードされていません。");
}

$file = $_FILES['deploy_zip'];
if ($file['error'] !== UPLOAD_ERR_OK) {
    http_response_code(500);
    die("エラー: ファイルのアップロードに失敗しました (Error Code: " . $file['error'] . ")");
}

$zipPath = $file['tmp_name'];

// プロジェクトディレクトリの作成（存在しない場合）
if (!file_exists($projectDir)) {
    if (!mkdir($projectDir, 0755, true)) {
        http_response_code(500);
        die("エラー: プロジェクトディレクトリの作成に失敗しました ({$projectDir})");
    }
}

// --- メンテナンスモード ここから ---
// ZIP展開中はファイルが新旧混在し、マイグレーション未実行のままDBアクセスするとエラー画面になりうる。
// Laravelの `php artisan down --render` 相当を、フレームワークを起動せず素のPHPファイル操作で再現する。
// （storage/framework/down が存在する間、public_html/index.php が事前描画済みHTMLを即返す）
function valzeria_deploy_maintenance_html(): string
{
    return <<<'HTML'
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<title>メンテナンス中 - ヴァルゼリアの冒険者</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
  body { margin:0; min-height:100vh; display:flex; align-items:center; justify-content:center; background:#0f172a; color:#e2e8f0; font-family: -apple-system, BlinkMacSystemFont, "Hiragino Kaku Gothic ProN", "Yu Gothic", sans-serif; }
  .card { max-width: 420px; margin: 16px; padding: 32px 28px; background:#1e293b; border:1px solid #334155; border-radius:16px; text-align:center; box-shadow:0 10px 30px rgba(0,0,0,.4); }
  .icon { font-size:40px; margin-bottom:12px; }
  h1 { font-size:18px; margin:0 0 12px; color:#fff; }
  p { font-size:14px; line-height:1.7; color:#cbd5e1; margin:0 0 20px; }
  button { appearance:none; border:none; cursor:pointer; background:#f59e0b; color:#1e293b; font-weight:700; font-size:14px; padding:12px 28px; border-radius:9999px; transition: transform .15s, background .15s; }
  button:hover { background:#fbbf24; transform:translateY(-1px); }
  button:active { transform:translateY(0); }
  .hint { margin-top:14px; font-size:12px; color:#64748b; }
</style>
</head>
<body>
  <div class="card">
    <div class="icon">&#9875;&#65039;</div>
    <h1>ただいまメンテナンス中です</h1>
    <p>裏側でゲームデータの更新作業を行っています。<br>少し待ってから、下のボタンで画面を更新してください。</p>
    <button onclick="location.reload()">画面を更新する</button>
    <div class="hint" id="auto-hint"></div>
  </div>
  <script>
    (function () {
      var seconds = 10;
      var hint = document.getElementById('auto-hint');
      var timer = setInterval(function () {
        seconds -= 1;
        if (hint) { hint.textContent = seconds + '秒後に自動で更新します…'; }
        if (seconds <= 0) {
          clearInterval(timer);
          location.reload();
        }
      }, 1000);
    })();
  </script>
</body>
</html>
HTML;
}

function valzeria_deploy_maintenance_stub(): string
{
    // Laravel標準の storage/framework/maintenance.php スタブと同等の内容。
    // public_html/index.php から Composer/フレームワークを読み込む前に呼ばれ、
    // storage/framework/down が存在する間は事前描画済みHTMLだけを返して即終了する。
    return <<<'PHP'
<?php

if (! file_exists($down = __DIR__.'/down')) {
    return;
}

$data = json_decode(file_get_contents($down), true);

if (! isset($data['template'])) {
    return;
}

http_response_code($data['status'] ?? 503);

if (isset($data['retry'])) {
    header('Retry-After: '.$data['retry']);
}

echo $data['template'];

exit;
PHP;
}

function valzeria_deploy_maintenance_paths(string $projectDir): array
{
    $frameworkDir = $projectDir . '/storage/framework';
    return [$frameworkDir, $frameworkDir . '/maintenance.php', $frameworkDir . '/down'];
}

function valzeria_deploy_enter_maintenance(string $projectDir): void
{
    [$frameworkDir, $stubPath, $downPath] = valzeria_deploy_maintenance_paths($projectDir);

    if (!is_dir($frameworkDir)) {
        mkdir($frameworkDir, 0755, true);
    }

    file_put_contents($stubPath, valzeria_deploy_maintenance_stub());

    file_put_contents($downPath, json_encode([
        'except' => [],
        'redirect' => null,
        'retry' => 15,
        'refresh' => null,
        'secret' => null,
        'status' => 503,
        'template' => valzeria_deploy_maintenance_html(),
    ], JSON_PRETTY_PRINT));
}

function valzeria_deploy_exit_maintenance(string $projectDir): void
{
    [, , $downPath] = valzeria_deploy_maintenance_paths($projectDir);

    if (file_exists($downPath)) {
        unlink($downPath);
    }
}

valzeria_deploy_enter_maintenance($projectDir);
echo "・メンテナンスモードを開始しました（展開完了まで一時的にメンテナンス画面を表示します）。\n";

// die()/exit() や致命的エラーで途中終了した場合でもメンテナンスモードの解除漏れが起きないよう、
// finally ではなくシャットダウン関数で確実に解除する。
register_shutdown_function(function () use ($projectDir) {
    valzeria_deploy_exit_maintenance($projectDir);
});
// --- メンテナンスモード ここまで（実際の解除は上記シャットダウン関数が保証する） ---

// ZIP展開処理
$zip = new ZipArchive();
if ($zip->open($zipPath) === true) {
    // 【1】非公開領域(valzeria_project)へファイルを上書き展開
    if (!$zip->extractTo($projectDir)) {
        http_response_code(500);
        $zip->close();
        die("エラー: ZIPファイルの展開に失敗しました。ディレクトリの書き込み権限を確認してください。");
    }
    $zip->close();

    // ローカル開発サーバー用の Vite hot ファイルが本番に残ると CSS/JS が localhost 参照になるため削除
    $viteHotFile = $projectDir . '/public/hot';
    if (is_file($viteHotFile) || is_link($viteHotFile)) {
        unlink($viteHotFile);
        echo "・Vite hot ファイルを削除しました。\n";
    }

    // 内部ドキュメント（仕様書・更新ログ等）を本番から除去
    $docsDir = $projectDir . '/docs';
    if (is_dir($docsDir)) {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($docsDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $file) {
            $file->isDir() ? rmdir($file->getRealPath()) : unlink($file->getRealPath());
        }
        rmdir($docsDir);
        echo "・docs/ ディレクトリを削除しました。\n";
    }
    
    // 【2】公開領域(public_html)にLaravelへ繋ぐ中継用 index.php を自動生成
    $indexCode = <<<PHP
<?php
define('LARAVEL_START', microtime(true));

// メンテナンスモード確認（デプロイ中の一時的なファイル不整合でエラー画面が出ないようにする）
if (file_exists(\$maintenance = __DIR__.'/../valzeria_project/storage/framework/maintenance.php')) {
    require \$maintenance;
}

require __DIR__.'/../valzeria_project/vendor/autoload.php';
\$app = require_once __DIR__.'/../valzeria_project/bootstrap/app.php';
\$app->handleRequest(Illuminate\Http\Request::capture());
PHP;
    file_put_contents($publicHtmlDir . '/index.php', $indexCode);

    // 【3】公開領域に静的アセット（画像やCSS/JS）へのシンボリックリンクを作成
    // ※ 展開された valzeria_project/public の中身を参照するショートカット
    // ※ manifest.json は Laravel ルート経由で配信するためここには含めない
    // ※ sw.js はシンボリックリンクではなく実ファイルとしてコピーする（MIME type の確実な配信のため）
    $obsoleteAssetLinks = ['admin'];
    foreach ($obsoleteAssetLinks as $asset) {
        $link = $publicHtmlDir . '/' . $asset;
        if (is_link($link)) {
            unlink($link);
        }
    }

    // storage シンボリックリンク（public_html/storage → valzeria_project/storage/app/public）
    $storageTarget = $projectDir . '/storage/app/public';
    $storageLink   = $publicHtmlDir . '/storage';
    if (!file_exists($storageTarget)) {
        mkdir($storageTarget, 0755, true);
    }
    // 既存のリンクまたはディレクトリを除去してから再作成
    if (is_link($storageLink)) {
        unlink($storageLink);
    } elseif (is_dir($storageLink)) {
        // 空ディレクトリなら削除、中身がある場合はスキップ
        @rmdir($storageLink);
    }
    if (!file_exists($storageLink) && !is_link($storageLink)) {
        symlink($storageTarget, $storageLink);
        echo "・storage シンボリックリンク作成: {$storageLink} → {$storageTarget}\n";
    } else {
        echo "・storage シンボリックリンク: スキップ（既存ディレクトリが存在します）\n";
    }

    $symlinkAssets = ['build', 'images', 'tools', 'contact_images', 'favicon.ico', 'robots.txt'];
    foreach ($symlinkAssets as $asset) {
        $target = $projectDir . '/public/' . $asset;
        $link = $publicHtmlDir . '/' . $asset;
        
        if (file_exists($target)) {
            // 既にリンクやファイルがあれば削除して上書き
            if (file_exists($link) || is_link($link)) {
                if (is_dir($link) && !is_link($link)) {
                    continue;
                }
                unlink($link);
            }
            symlink($target, $link);
        }
    }

    // sw.js は実ファイルとしてコピー（シンボリックリンクだとMIMEタイプが不安定なため）
    $swSource = $projectDir . '/public/sw.js';
    $swDest = $publicHtmlDir . '/sw.js';
    if (file_exists($swSource)) {
        if (file_exists($swDest) || is_link($swDest)) {
            unlink($swDest);
        }
        copy($swSource, $swDest);
    }

    // manifest.json のシンボリックリンクが残っていれば削除
    // (Laravel ルート /manifest.json で正しいContent-Typeで配信するため)
    $manifestLink = $publicHtmlDir . '/manifest.json';
    if (file_exists($manifestLink) || is_link($manifestLink)) {
        unlink($manifestLink);
    }

    // 【4】server_deploy_api.php 自身を最新版に自己更新
    // デプロイされたプロジェクト内の最新版で public_html 側のスクリプトを上書きする
    $selfSource = $projectDir . '/server_deploy_api.php';
    // Xserverの構成: /home/nos27/valzeria.com/public_html/ ← このスクリプトの場所
    //                /home/nos27/valzeria.com/valzeria_project/ ← $projectDir
    // $publicHtmlDir = __DIR__ (このスクリプト自身が public_html/ にある)
    $selfDest = $publicHtmlDir . '/server_deploy_api.php';
    if (file_exists($selfSource)) {
        if (copy($selfSource, $selfDest)) {
            echo "・server_deploy_api.php を最新版に自己更新しました。\n";
        } else {
            echo "・警告: server_deploy_api.php の自己更新に失敗しました。\n";
        }
    }

    $adminDeploySource = $projectDir . '/server_admin_deploy_api.php';
    $adminDeployDest = $publicHtmlDir . '/server_admin_deploy_api.php';
    if (file_exists($adminDeploySource)) {
        if (copy($adminDeploySource, $adminDeployDest)) {
            echo "・server_admin_deploy_api.php を最新版に配置しました。\n";
        } else {
            echo "・警告: server_admin_deploy_api.php の配置に失敗しました。\n";
        }
    }

    // 【5】マイグレーションの自動実行 (CLIのPHPバージョン問題を回避するためWebプロセス内で実行)
    try {
        require $projectDir . '/vendor/autoload.php';
        $app = require_once $projectDir . '/bootstrap/app.php';
        $kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
        $kernel->bootstrap();

        \Illuminate\Support\Facades\Artisan::call('config:clear');
        echo "・config:clear 実行完了\n";

        \Illuminate\Support\Facades\Artisan::call('cache:clear');
        echo "・cache:clear 実行完了\n";

        \Illuminate\Support\Facades\Artisan::call('view:clear');
        echo "・view:clear 実行完了\n";

        \Illuminate\Support\Facades\Artisan::call('route:clear');
        echo "・route:clear 実行完了\n";

        \Illuminate\Support\Facades\Artisan::call('event:clear');
        echo "・event:clear 実行完了\n";

        \Illuminate\Support\Facades\Artisan::call('migrate', ['--force' => true]);
        echo "・マイグレーション実行結果:\n" . \Illuminate\Support\Facades\Artisan::output() . "\n";

        \Illuminate\Support\Facades\Artisan::call('db:seed', ['--class' => 'RouteAreaSeeder', '--force' => true]);
        echo "・シーダー(RouteAreaSeeder)実行結果:\n" . \Illuminate\Support\Facades\Artisan::output() . "\n";

        \Illuminate\Support\Facades\Artisan::call('db:seed', ['--class' => 'AreaDiscoveryLinkSeeder', '--force' => true]);
        echo "・シーダー(AreaDiscoveryLinkSeeder)実行結果:\n" . \Illuminate\Support\Facades\Artisan::output() . "\n";

        \Illuminate\Support\Facades\Artisan::call('valzeria:rebuild-discovery-progress');
        echo "・発見進行再構築結果:\n" . \Illuminate\Support\Facades\Artisan::output() . "\n";

        \Illuminate\Support\Facades\Artisan::call('db:seed', ['--class' => 'JobSystemSeeder', '--force' => true]);
        echo "・シーダー(JobSystemSeeder)実行結果:\n" . \Illuminate\Support\Facades\Artisan::output() . "\n";

        \Illuminate\Support\Facades\Artisan::call('db:seed', ['--class' => 'SkillSeeder', '--force' => true]);
        echo "・シーダー(SkillSeeder)実行結果:\n" . \Illuminate\Support\Facades\Artisan::output() . "\n";

        \Illuminate\Support\Facades\Artisan::call('db:seed', ['--class' => 'JobArtSeeder', '--force' => true]);
        echo "・シーダー(JobArtSeeder)実行結果:\n" . \Illuminate\Support\Facades\Artisan::output() . "\n";

        \Illuminate\Support\Facades\Artisan::call('db:seed', ['--class' => 'EnemySeeder', '--force' => true]);
        echo "・シーダー(EnemySeeder)実行結果:\n" . \Illuminate\Support\Facades\Artisan::output() . "\n";

        \Illuminate\Support\Facades\Artisan::call('db:seed', ['--class' => 'TopUpdateSeeder', '--force' => true]);
        echo "・シーダー(TopUpdateSeeder)実行結果:\n" . \Illuminate\Support\Facades\Artisan::output() . "\n";

        \Illuminate\Support\Facades\Artisan::call('enemy:stats:apply', [
            '--all' => true,
            '--include-locked' => true,
            '--backup-key' => 'pre_enemy_curve_v1_9_1_2026_06',
            '--force' => true,
        ]);
        echo "・敵ステータス自動生成(v1.9.1)反映結果:\n" . \Illuminate\Support\Facades\Artisan::output() . "\n";

        \Illuminate\Support\Facades\Artisan::call('db:seed', ['--class' => 'EnemyDropsSeeder', '--force' => true]);
        echo "・シーダー(EnemyDropsSeeder)実行結果:\n" . \Illuminate\Support\Facades\Artisan::output() . "\n";

        \Illuminate\Support\Facades\Artisan::call('db:seed', ['--class' => 'ArmorsSeeder', '--force' => true]);
        echo "・シーダー(ArmorsSeeder)実行結果:\n" . \Illuminate\Support\Facades\Artisan::output() . "\n";

        \Illuminate\Support\Facades\Artisan::call('db:seed', ['--class' => 'DropEquipmentAdditionsSeeder', '--force' => true]);
        echo "・シーダー(DropEquipmentAdditionsSeeder)実行結果:\n" . \Illuminate\Support\Facades\Artisan::output() . "\n";

        \Illuminate\Support\Facades\Artisan::call('db:seed', ['--class' => 'ValmonSeeder', '--force' => true]);
        echo "・シーダー(ValmonSeeder)実行結果:\n" . \Illuminate\Support\Facades\Artisan::output() . "\n";

        \Illuminate\Support\Facades\Artisan::call('valzeria:cleanup-duplicate-valmons', ['--delete' => true]);
        echo "・重複ヴァルモン削除結果:\n" . \Illuminate\Support\Facades\Artisan::output() . "\n";

        \Illuminate\Support\Facades\Artisan::call('db:seed', ['--class' => 'NpcProcurementRequestSeeder', '--force' => true]);
        echo "・シーダー(NpcProcurementRequestSeeder)実行結果:\n" . \Illuminate\Support\Facades\Artisan::output() . "\n";

        \Illuminate\Support\Facades\Artisan::call('config:cache');
        echo "・config:cache 実行完了\n";

        \Illuminate\Support\Facades\Artisan::call('event:cache');
        echo "・event:cache 実行完了\n";

        \Illuminate\Support\Facades\Artisan::call('view:cache');
        echo "・view:cache 実行完了\n";

        echo "・route:cache はクロージャルートがあるため未実行（route:clear のみ）\n";
    } catch (\Exception $e) {
        echo "・Artisan実行エラー: " . $e->getMessage() . "\n";
    }

    echo "デプロイが正常に完了しました！\n";
    echo "・本体展開先: {$projectDir}\n";
    echo "・中継ファイル生成: {$publicHtmlDir}/index.php\n";
    echo "・アセットリンク作成完了\n";

} else {
    http_response_code(500);
    die("エラー: ZIPファイルを開けませんでした。");
}
