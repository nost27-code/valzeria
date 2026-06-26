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
    
    // 【2】公開領域(public_html)にLaravelへ繋ぐ中継用 index.php を自動生成
    $indexCode = <<<PHP
<?php
define('LARAVEL_START', microtime(true));
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
