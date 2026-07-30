<?php

namespace App\Console\Commands;

use App\Models\Character;
use App\Models\CharacterIconDesignRequest;
use App\Services\CharacterIconDesignService;
use App\Services\CharacterIconSetService;
use Illuminate\Console\Command;

class GrantCharacterIconSet extends Command
{
    protected $signature = 'character-icon:grant
                            {character : Character ID or exact character name}
                            {set_key : Configured icon set key}
                            {--request= : Character icon design request ID}
                            {--complete-request : Mark the linked design request as completed}';

    protected $description = 'Grant an exclusive character icon set to one character';

    public function handle(
        CharacterIconSetService $iconSetService,
        CharacterIconDesignService $designService,
    ): int {
        $characterInput = (string) $this->argument('character');
        $characterQuery = Character::query();
        if (ctype_digit($characterInput)) {
            $characterQuery->whereKey((int) $characterInput);
        } else {
            $characterQuery->where('name', $characterInput);
        }

        $characters = $characterQuery->limit(2)->get();
        if ($characters->isEmpty()) {
            $this->error('対象プレイヤーが見つかりません。');

            return self::FAILURE;
        }
        if ($characters->count() > 1) {
            $this->error('同名のプレイヤーが複数います。キャラクターIDを指定してください。');

            return self::FAILURE;
        }

        $character = $characters->firstOrFail();
        $designRequest = null;
        if ($this->option('request') !== null) {
            $designRequest = CharacterIconDesignRequest::query()->find((int) $this->option('request'));
            if ($designRequest === null) {
                $this->error('指定された制作依頼が見つかりません。');

                return self::FAILURE;
            }
        }
        if ($this->option('complete-request') && $designRequest === null) {
            $this->error('--complete-requestを使用する場合は--requestを指定してください。');

            return self::FAILURE;
        }

        try {
            $entitlement = $iconSetService->grant(
                $character,
                (string) $this->argument('set_key'),
                $designRequest,
            );
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($this->option('complete-request')) {
            $statusResult = $designService->updateStatus($designRequest, 'completed');
            if (! $statusResult['success']) {
                $this->error($statusResult['message']);

                return self::FAILURE;
            }
        }

        $this->info(
            "{$character->name}（ID: {$character->id}）へ"
            .$entitlement->icon_set_key
            .'を付与し、通常アイコンへ設定しました。'
        );

        return self::SUCCESS;
    }
}
