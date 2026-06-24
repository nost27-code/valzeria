<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // 開発・本番環境問わず、character_jobsテーブルにis_masteredカラムがない場合は
        // 強制的にマイグレーションを実行してテーブルを最新化する
        try {
            if (\Illuminate\Support\Facades\Schema::hasTable('character_jobs') &&
                !\Illuminate\Support\Facades\Schema::hasColumn('character_jobs', 'is_mastered')) {
                \Illuminate\Support\Facades\Artisan::call('migrate', ['--force' => true]);
            }

            // 経験値テーブルが空の場合はSeederを強制実行（本番反映漏れ対策）
            if (\Illuminate\Support\Facades\Schema::hasTable('job_exp_tables')) {
                if (\App\Models\JobExpTable::count() === 0) {
                    \Illuminate\Support\Facades\Artisan::call('db:seed', ['--class' => 'JobSystemSeeder', '--force' => true]);
                }
            }
            if (\Illuminate\Support\Facades\Schema::hasTable('cities')) {
                if (\App\Models\City::count() === 0) {
                    \Illuminate\Support\Facades\Artisan::call('db:seed', ['--class' => 'CitySeeder', '--force' => true]);
                }
            }

            // 誤ってマスターになったジョブデータの自動修復
            if (\Illuminate\Support\Facades\Schema::hasTable('character_jobs')) {
                $wrongMastered = \App\Models\CharacterJob::where('is_mastered', true)->get();
                foreach ($wrongMastered as $job) {
                    if ($job->jobClass && $job->job_level < $job->jobClass->max_job_level) {
                        $job->is_mastered = false;
                        $job->mastered_at = null;
                        $job->save();
                    }
                }
            }

            // 画像が表示されない問題（imagesディレクトリがシンボリックリンク化されていない）の強制修正
            $publicHtmlImages = dirname(base_path()) . '/public_html/images';
            $targetImages = base_path('public/images');
            if (file_exists($publicHtmlImages) && !is_link($publicHtmlImages) && is_dir($publicHtmlImages)) {
                // ディレクトリが存在する場合はバックアップ名に変更して、シンボリックリンクを張り直す
                rename($publicHtmlImages, $publicHtmlImages . '_backup_' . time());
                symlink($targetImages, $publicHtmlImages);
            }
            
            // buildディレクトリも念のため
            $publicHtmlBuild = dirname(base_path()) . '/public_html/build';
            $targetBuild = base_path('public/build');
            if (file_exists($publicHtmlBuild) && !is_link($publicHtmlBuild) && is_dir($publicHtmlBuild)) {
                rename($publicHtmlBuild, $publicHtmlBuild . '_backup_' . time());
                symlink($targetBuild, $publicHtmlBuild);
            }

            // 指定されたキャラクター「やみなべ」のデータ削除（一時的）
            if (\Illuminate\Support\Facades\Schema::hasTable('characters')) {
                $yaminabe = \App\Models\Character::where('name', 'やみなべ')->first();
                if ($yaminabe) {
                    $yaminabe->delete();
                }
            }
        } catch (\Exception $e) {
            // DB未接続時などは無視
        }
    }
}
