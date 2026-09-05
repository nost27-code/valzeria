<?php

namespace App\Services\Nation\Raid\Simulation;

use App\Services\Nation\Raid\NationRaidJson;
use App\Services\Nation\Raid\NationRaidRules;
use InvalidArgumentException;

/** 事前解決済みの1出撃profileを一意に識別する、匿名・機械的なcontext。 */
final readonly class NationRaidResolvedProfileContext
{
    public const CONTRACT_VERSION = 'nation-raid-resolved-profile-context-v3-staged-hp';

    public function __construct(
        public int $stage,
        public string $startingForm,
        public string $strategy,
        public ?string $dominantLineage,
        public int $profileNo,
        public int $sortieSeed,
    ) {
        if ($stage < 1 || $stage > NationRaidRules::MAX_STAGES) {
            throw new InvalidArgumentException('Resolved raid profile stage is outside 1..20.');
        }
        if (! in_array($startingForm, self::formKeys(), true)) {
            throw new InvalidArgumentException('Resolved raid profile has an unknown starting form.');
        }
        if (! in_array($strategy, self::strategyKeys(), true)) {
            throw new InvalidArgumentException('Resolved raid profile has an unknown strategy.');
        }
        if ($dominantLineage !== null && ! in_array($dominantLineage, self::lineageKeys(), true)) {
            throw new InvalidArgumentException('Resolved raid profile has an unknown dominant lineage.');
        }
        if ($profileNo < 1 || $profileNo > 25) {
            throw new InvalidArgumentException('Resolved raid profile number is outside 1..25.');
        }
        if ($sortieSeed < 1) {
            throw new InvalidArgumentException('Resolved raid profile sortie seed must be positive.');
        }
    }

    public static function forProfile(
        string $characterKey,
        int $stage,
        string $startingForm,
        string $strategy,
        ?string $dominantLineage,
        int $profileNo,
    ): self {
        if (trim($characterKey) === '') {
            throw new InvalidArgumentException('Resolved raid profile requires an anonymous character key.');
        }

        $scope = implode('|', [
            self::CONTRACT_VERSION,
            $characterKey,
            (string) $stage,
            $startingForm,
            $strategy,
            $dominantLineage ?? 'none',
            (string) $profileNo,
        ]);
        $seed = max(1, (int) hexdec(substr(hash('sha256', $scope), 0, 7)));

        return new self($stage, $startingForm, $strategy, $dominantLineage, $profileNo, $seed);
    }

    /** @param array<string, mixed> $payload */
    public static function fromArray(array $payload, string $characterKey): self
    {
        $requiredKeys = ['dominant_lineage', 'profile_no', 'sortie_seed', 'stage', 'starting_form', 'strategy'];
        $actualKeys = array_keys($payload);
        sort($actualKeys, SORT_STRING);
        if ($actualKeys !== $requiredKeys
            || ! is_int($payload['stage'])
            || ! is_string($payload['starting_form'])
            || ! is_string($payload['strategy'])
            || (! is_null($payload['dominant_lineage']) && ! is_string($payload['dominant_lineage']))
            || ! is_int($payload['profile_no'])
            || ! is_int($payload['sortie_seed'])
        ) {
            throw new InvalidArgumentException('Resolved raid profile context has an invalid shape.');
        }

        $context = new self(
            stage: $payload['stage'],
            startingForm: $payload['starting_form'],
            strategy: $payload['strategy'],
            dominantLineage: $payload['dominant_lineage'],
            profileNo: $payload['profile_no'],
            sortieSeed: $payload['sortie_seed'],
        );
        $expected = self::forProfile(
            characterKey: $characterKey,
            stage: $context->stage,
            startingForm: $context->startingForm,
            strategy: $context->strategy,
            dominantLineage: $context->dominantLineage,
            profileNo: $context->profileNo,
        );
        if ($context->sortieSeed !== $expected->sortieSeed) {
            throw new InvalidArgumentException('Resolved raid profile sortie seed does not match its context.');
        }

        return $context;
    }

    /** @return array{stage:int,starting_form:string,strategy:string,dominant_lineage:?string,profile_no:int,sortie_seed:int} */
    public function toArray(): array
    {
        return [
            'stage' => $this->stage,
            'starting_form' => $this->startingForm,
            'strategy' => $this->strategy,
            'dominant_lineage' => $this->dominantLineage,
            'profile_no' => $this->profileNo,
            'sortie_seed' => $this->sortieSeed,
        ];
    }

    public function key(): string
    {
        return hash('sha256', NationRaidJson::encode($this->toArray(), JSON_UNESCAPED_SLASHES));
    }

    public function baseKey(): string
    {
        return implode('|', [
            sprintf('stage:%02d', $this->stage),
            'form:'.$this->startingForm,
            'strategy:'.$this->strategy,
            'lineage:'.($this->dominantLineage ?? 'none'),
        ]);
    }

    /** 現行engineは開始HPから形態だけをsnapshotするため、各形態の境界値を正規化入力に使う。 */
    public function canonicalCycleCurrentHp(): int
    {
        return (new NationRaidRules)->canonicalCycleCurrentHpForForm($this->startingForm, $this->stage);
    }

    /** @return array<string, mixed> */
    public static function contract(): array
    {
        return [
            'version' => self::CONTRACT_VERSION,
            'dimensions' => ['stage', 'starting_form', 'strategy', 'dominant_lineage', 'profile_no'],
            'stages' => range(1, NationRaidRules::MAX_STAGES),
            'starting_forms' => self::formKeys(),
            'strategies' => self::strategyKeys(),
            'dominant_lineages' => [null, ...self::lineageKeys()],
            'profile_no_range' => [1, 25],
            'seed_derivation' => 'sha256(context_version|anonymous_character_key|stage|form|strategy|lineage_or_none|profile_no):first_7_hex',
            'cycle_hp_projection' => 'form_boundary_only_v1',
        ];
    }

    public static function contractHash(): string
    {
        return hash('sha256', NationRaidJson::encode(self::contract(), JSON_UNESCAPED_UNICODE));
    }

    /** @return list<string> */
    private static function formKeys(): array
    {
        return [
            NationRaidRules::FORM_SEALED_SCALE,
            NationRaidRules::FORM_SPLIT_WING,
            NationRaidRules::FORM_LINEAGE_INVASION,
            NationRaidRules::FORM_EXPOSED_CORE,
        ];
    }

    /** @return list<string> */
    private static function strategyKeys(): array
    {
        return [
            NationRaidRules::STRATEGY_BOSS_SET,
            NationRaidRules::STRATEGY_ASSAULT,
            NationRaidRules::STRATEGY_INTERCEPT,
            NationRaidRules::STRATEGY_FORTIFY,
        ];
    }

    /** @return list<string> */
    private static function lineageKeys(): array
    {
        return ['field', 'counter', 'dark', 'pierce', 'hunt', 'aim', 'guardian', 'transmute', 'break', 'command'];
    }
}
