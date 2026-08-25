<?php

namespace App\Services\Nation;

use App\Enums\NationType;
use App\Models\Character;
use App\Models\Nation;
use App\Models\NationFacility;
use App\Models\NationJoinApplication;
use App\Models\NationMembership;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

final class NationService
{
    public function __construct(
        private readonly NationMembershipCooldownService $cooldowns,
        private readonly NationActivityLogService $activityLogs,
        private readonly NationEmblemCatalog $emblems,
    ) {}

    public function create(
        Character $character,
        string $name,
        ?string $description = null,
        string $nationType = 'kingdom',
        ?string $emblemKey = null,
    ): Nation {
        $name = trim($name);
        $description = trim((string) $description);
        $emblemKey ??= NationEmblemCatalog::DEFAULT_KEY;

        throw_if($name === '' || mb_strlen($name) > 40, \DomainException::class, '国家名は1〜40文字で入力してください。');
        throw_if(mb_strlen($description) > 200, \DomainException::class, '国家紹介は200文字以内で入力してください。');
        throw_unless(NationType::tryFrom($nationType), \DomainException::class, '選択した国号は使用できません。');
        throw_unless($this->emblems->exists($emblemKey), \DomainException::class, '選択した国家紋章は使用できません。');

        return DB::transaction(function () use ($character, $name, $description, $nationType, $emblemKey): Nation {
            $lockedCharacter = Character::whereKey($character->id)->lockForUpdate()->firstOrFail();
            throw_if(NationMembership::where('character_id', $lockedCharacter->id)->exists(), \DomainException::class, 'すでに国家へ所属しています。');
            throw_if(
                NationJoinApplication::where('character_id', $lockedCharacter->id)
                    ->where('status', NationJoinApplication::STATUS_PENDING)
                    ->exists(),
                \DomainException::class,
                '加入申請中は建国できません。先に申請を取り消してください。',
            );
            $this->cooldowns->assertCanFound($lockedCharacter);
            throw_if(Nation::where('name', $name)->exists(), \DomainException::class, 'その国家名はすでに使われています。');

            try {
                $nation = Nation::create([
                    'name' => $name,
                    'nation_type' => $nationType,
                    'description' => $description !== '' ? $description : null,
                    'recruitment_enabled' => true,
                    'recruitment_message' => null,
                    'emblem_key' => $emblemKey,
                    'status' => Nation::STATUS_ACTIVE,
                    'founded_at' => now(),
                ]);
            } catch (UniqueConstraintViolationException $exception) {
                if ($this->isNationNameConflict($exception)) {
                    throw new \DomainException('その国家名はすでに使われています。', 0, $exception);
                }

                throw $exception;
            }
            NationMembership::create([
                'nation_id' => $nation->id,
                'character_id' => $lockedCharacter->id,
                'role' => 'ruler',
                'joined_at' => now(),
            ]);
            foreach (NationFacility::TYPES as $type) {
                NationFacility::create([
                    'nation_id' => $nation->id,
                    'facility_type' => $type,
                    'level' => 1,
                    'condition_bps' => 10000,
                ]);
            }
            $this->activityLogs->record($nation, 'nation_created', $lockedCharacter);

            return $nation->load(['memberships.character', 'facilities']);
        }, 3);
    }

    private function isNationNameConflict(UniqueConstraintViolationException $exception): bool
    {
        $message = mb_strtolower($exception->getMessage());

        return str_contains($message, 'nations_name_unique')
            || str_contains($message, 'nations.name');
    }
}
