<?php

namespace App\Console\Commands;

use App\Models\Character;
use App\Models\Title;
use App\Services\TitleService;
use App\Services\TitleUnlockService;
use App\Support\MonsterMarkTitleCatalog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PDO;
use RuntimeException;
use Throwable;

final class BackfillNewAchievementTitles extends Command
{
    /** @var list<int> */
    private const EXISTING_TITLE_IDS = [
        112, 113, 114, 115, 116, 117, 118, 119, 120, 121,
        122, 123, 124, 125, 126, 127, 128, 129, 130, 131,
    ];

    protected $signature = 'titles:backfill-new-achievements
        {--apply : 条件達成済みの未獲得称号を実際に付与する}
        {--audit-schema : titleマスタ追加前に既存重複と複合UNIQUEの有無だけを監査する}
        {--chunk=100 : 1回に走査するキャラクター数}
        {--json : 結果をJSONで出力する}';

    protected $description = '保存済み実績から、新しい進行・装備・印実績称号を既存キャラクターへ冪等に一括付与する';

    public function handle(
        TitleUnlockService $titleUnlockService,
        TitleService $titleService,
    ): int {
        $auditSchema = (bool) $this->option('audit-schema');
        if ($auditSchema && $this->option('apply')) {
            return $this->failCommand('audit-schema and apply cannot be used together.');
        }

        $chunkSize = 100;
        if (! $auditSchema) {
            $chunkSize = filter_var($this->option('chunk'), FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 1, 'max_range' => 1000],
            ]);
            if ($chunkSize === false) {
                return $this->failCommand('chunk must be an integer from 1 to 1000.');
            }
        }

        $requiredTables = $auditSchema
            ? ['character_titles']
            : [
                'characters',
                'titles',
                'character_titles',
                'character_items',
                'items',
                'character_jobs',
                'job_classes',
                'monster_marks',
                'character_monster_marks',
                'enemies',
            ];
        foreach ($requiredTables as $table) {
            if (! Schema::hasTable($table)) {
                return $this->failCommand("Required table is missing: {$table}.");
            }
        }

        try {
            $databaseIdentity = $this->databaseIdentity();
            $duplicatePairs = $this->duplicatePairCount();
            $uniqueIndexPresent = $this->characterTitleUniqueIndexPresent();

            if ($auditSchema) {
                $summary = [
                    ...$databaseIdentity,
                    'mode' => 'schema-audit',
                    'duplicate_pairs_before' => $duplicatePairs,
                    'unique_index_present' => $uniqueIndexPresent,
                ];
                $this->outputSummary($summary);

                if ($duplicatePairs !== 0) {
                    $this->error('Existing duplicate character-title grants must be resolved before migration.');

                    return self::FAILURE;
                }

                return self::SUCCESS;
            }

            if ($duplicatePairs !== 0) {
                throw new RuntimeException('Existing duplicate character-title grants must be resolved before backfill.');
            }
            if (! $uniqueIndexPresent) {
                throw new RuntimeException('The character_titles character/title UNIQUE constraint is missing.');
            }

            $titleIds = $this->titleIds();
            $monsterMarkTitleIds = MonsterMarkTitleCatalog::titleIds();
            $titleDefinitions = Title::query()
                ->whereIn('id', $titleIds)
                ->orderBy('id')
                ->get(['id', 'name', 'category']);
            if ($titleDefinitions->pluck('id')->map(static fn ($id): int => (int) $id)->all() !== $titleIds
                || $titleDefinitions->whereBetween('id', [122, 131])
                    ->contains(static fn (Title $title): bool => $title->category !== 'equipment')
                || $titleDefinitions->whereIn('id', $monsterMarkTitleIds)
                    ->contains(static fn (Title $title): bool => $title->category !== 'monster_mark')) {
                throw new RuntimeException('New achievement title definitions 112-271 are incomplete or invalid.');
            }

            $apply = (bool) $this->option('apply');
            $summary = $apply
                ? DB::transaction(
                    fn (): array => $this->scanCharacters(
                        $titleUnlockService,
                        $titleService,
                        $chunkSize,
                        true,
                    ),
                    3,
                )
                : $this->scanCharacters(
                    $titleUnlockService,
                    $titleService,
                    $chunkSize,
                    false,
                );

            $summary = [
                ...$databaseIdentity,
                ...$summary,
                'mode' => $apply ? 'apply' : 'dry-run',
                'existing_grants_after' => DB::table('character_titles')
                    ->whereIn('title_id', $titleIds)
                    ->count(),
                'duplicate_pairs_after' => $this->duplicatePairCount(),
                'unique_index_present' => true,
            ];
        } catch (Throwable $exception) {
            report($exception);

            return $this->failCommand('New achievement title backfill failed: '.$exception->getMessage());
        }

        if ((int) $summary['duplicate_pairs_after'] !== 0) {
            $this->outputSummary($summary);

            return $this->failCommand('Duplicate new-title grants were detected.');
        }

        $this->outputSummary($summary);

        return self::SUCCESS;
    }

    /**
     * @return array{
     *     characters_scanned: int,
     *     characters_eligible: int,
     *     grants_missing_or_applied: int,
     *     per_title: array<int, int>
     * }
     */
    private function scanCharacters(
        TitleUnlockService $titleUnlockService,
        TitleService $titleService,
        int $chunkSize,
        bool $apply,
    ): array {
        $charactersScanned = 0;
        $charactersEligible = 0;
        $grants = 0;
        $titleIds = $this->titleIds();
        $perTitle = array_fill_keys($titleIds, 0);

        Character::query()
            ->select('characters.id', 'characters.level', 'characters.wins')
            ->orderBy('characters.id')
            ->chunkById($chunkSize, function ($characters) use (
                $titleUnlockService,
                $titleService,
                $apply,
                $titleIds,
                &$charactersScanned,
                &$charactersEligible,
                &$grants,
                &$perTitle,
            ): void {
                foreach ($characters as $character) {
                    $charactersScanned++;
                    $titles = $titleUnlockService->eligibleNewProgressionTitles($character)
                        ->concat($titleUnlockService->eligibleEquipmentTitles($character))
                        ->concat($titleUnlockService->eligibleMonsterMarkTitles($character))
                        ->filter(static fn (Title $title): bool => in_array((int) $title->id, $titleIds, true))
                        ->unique('id')
                        ->values();

                    if ($titles->isEmpty()) {
                        continue;
                    }

                    $charactersEligible++;
                    foreach ($titles as $title) {
                        $titleId = (int) $title->id;
                        if ($apply) {
                            $titleService->unlockTitle($character, $titleId);
                        }
                        $grants++;
                        $perTitle[$titleId]++;
                    }
                }
            });

        return [
            'characters_scanned' => $charactersScanned,
            'characters_eligible' => $charactersEligible,
            'grants_missing_or_applied' => $grants,
            'per_title' => $perTitle,
        ];
    }

    private function duplicatePairCount(): int
    {
        return DB::query()
            ->fromSub(
                DB::table('character_titles')
                    ->selectRaw('character_id, title_id, COUNT(*) AS total')
                    ->groupBy('character_id', 'title_id')
                    ->havingRaw('COUNT(*) > 1'),
                'duplicate_new_titles',
            )
            ->count();
    }

    /** @return list<int> */
    private function titleIds(): array
    {
        return array_merge(self::EXISTING_TITLE_IDS, MonsterMarkTitleCatalog::titleIds());
    }

    private function characterTitleUniqueIndexPresent(): bool
    {
        foreach (Schema::getIndexes('character_titles') as $index) {
            $columns = array_map(
                static fn ($column): string => strtolower((string) $column),
                $index['columns'] ?? [],
            );

            if (($index['unique'] ?? false) === true
                && $columns === ['character_id', 'title_id']) {
                return true;
            }
        }

        return false;
    }

    /** @return array{database_driver: string, database_version: string} */
    private function databaseIdentity(): array
    {
        $driver = DB::connection()->getDriverName();
        $version = (string) DB::connection()->getPdo()->getAttribute(PDO::ATTR_SERVER_VERSION);

        if ($driver === 'mysql') {
            if (! str_contains(strtolower($version), 'mariadb')) {
                throw new RuntimeException("Production-compatible MariaDB is required; connected server is {$version}.");
            }

            preg_match('/(\d+\.\d+\.\d+)(?=[^0-9]*-MariaDB)/i', $version, $matches);
            $semanticVersion = $matches[0] ?? null;
            if ($semanticVersion === null || version_compare($semanticVersion, '10.5.13', '<')) {
                throw new RuntimeException("MariaDB 10.5.13 or newer is required; connected server is {$version}.");
            }
        }

        return [
            'database_driver' => $driver,
            'database_version' => $version,
        ];
    }

    /** @param array<string, mixed> $summary */
    private function outputSummary(array $summary): void
    {
        if ($this->option('json')) {
            $this->line('NEW_ACHIEVEMENT_TITLE_BACKFILL='.json_encode(
                $summary,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            ));

            return;
        }

        if ($summary['mode'] === 'schema-audit') {
            $unique = $summary['unique_index_present'] ? 'あり' : 'なし';
            $this->info(
                "称号付与schema監査: 重複 {$summary['duplicate_pairs_before']}組 / 複合UNIQUE {$unique}"
            );

            return;
        }

        $mode = $summary['mode'] === 'apply' ? '付与' : 'dry-run';
        $this->info(
            "新称号一括処理（{$mode}）: 対象 {$summary['characters_scanned']}人 / "
            ."達成 {$summary['characters_eligible']}人 / "
            ."称号 {$summary['grants_missing_or_applied']}件 / "
            ."現在付与総数 {$summary['existing_grants_after']}件"
        );
    }

    private function failCommand(string $message): int
    {
        if ($this->option('json')) {
            $this->line('NEW_ACHIEVEMENT_TITLE_BACKFILL='.json_encode(
                ['error' => $message],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            ));
        } else {
            $this->error($message);
        }

        return self::FAILURE;
    }
}
