<?php

namespace App\Console\Commands;

use App\Models\Area;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class PublishDungeon extends Command
{
    protected $signature = 'dungeon:publish {area_ids* : 公開するエリアID} {--confirm : 検証済みとして実際に公開する}';

    protected $description = '確認済みの未公開ダンジョンを公開します。';

    public function handle(): int
    {
        if (!Schema::hasColumn('areas', 'is_published')) {
            $this->error('areas.is_published がありません。migration 後に実行してください。');
            return self::FAILURE;
        }

        $areaIds = collect($this->argument('area_ids'))->map(fn ($id): int => (int) $id)->unique()->values();
        $areas = Area::whereIn('id', $areaIds)->orderBy('id')->get();
        if ($areas->count() !== $areaIds->count()) {
            $this->error('指定したエリアIDに存在しないものがあります。');
            return self::FAILURE;
        }

        $this->table(['ID', 'ダンジョン', '現在の公開状態'], $areas->map(fn (Area $area): array => [
            $area->id, $area->name, $area->is_published ? '公開中' : '非公開',
        ])->all());
        if (!$this->option('confirm')) {
            $this->warn('表示内容を確認後、--confirm を付けて再実行すると公開します。');
            return self::SUCCESS;
        }

        Area::whereIn('id', $areaIds)->update(['is_published' => true]);
        $this->info('指定したダンジョンを公開しました。');
        return self::SUCCESS;
    }
}
