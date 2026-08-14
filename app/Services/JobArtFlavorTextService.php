<?php

namespace App\Services;

use App\Models\Skill;
use RuntimeException;
use Throwable;

class JobArtFlavorTextService
{
    /**
     * @var array<string, array{
     *     job_id: int,
     *     learn_rank: int,
     *     name: string,
     *     activation_phrase: string,
     *     activation_description: string
     * }>|null
     */
    private ?array $rewritesByKey = null;

    public function enabled(): bool
    {
        return (bool) config('battle.job_art_v2.flavor_rewrite', false);
    }

    /**
     * @return array{activation_phrase: ?string, activation_description: ?string}
     */
    public function resolve(Skill $skill): array
    {
        $current = [
            'activation_phrase' => $skill->activation_phrase !== null
                ? (string) $skill->activation_phrase
                : null,
            'activation_description' => $skill->activation_description !== null
                ? (string) $skill->activation_description
                : null,
        ];

        if (! $this->enabled() || ! $skill->isJobArt()) {
            return $current;
        }

        try {
            $rewrite = $this->rewritesByKey()[$this->keyForSkill($skill)] ?? null;
        } catch (Throwable $exception) {
            report($exception);

            return $current;
        }

        if ($rewrite === null) {
            return $current;
        }

        return [
            'activation_phrase' => $rewrite['activation_phrase'],
            'activation_description' => $rewrite['activation_description'],
        ];
    }

    /**
     * @return list<array{
     *     job_id: int,
     *     learn_rank: int,
     *     name: string,
     *     activation_phrase: string,
     *     activation_description: string
     * }>
     */
    public function allRewrites(): array
    {
        return array_values($this->rewritesByKey());
    }

    /**
     * @return array<string, array{
     *     job_id: int,
     *     learn_rank: int,
     *     name: string,
     *     activation_phrase: string,
     *     activation_description: string
     * }>
     */
    private function rewritesByKey(): array
    {
        if ($this->rewritesByKey !== null) {
            return $this->rewritesByKey;
        }

        $path = database_path('data/job_art_flavor_rewrites.json');
        $json = @file_get_contents($path);
        if ($json === false) {
            throw new RuntimeException("Job-art flavor rewrite catalog could not be read: {$path}");
        }

        try {
            $rows = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            throw new RuntimeException("Job-art flavor rewrite catalog is invalid JSON: {$path}", 0, $exception);
        }

        if (! is_array($rows) || ! array_is_list($rows)) {
            throw new RuntimeException('Job-art flavor rewrite catalog must be a JSON list.');
        }

        $catalog = [];
        foreach ($rows as $index => $row) {
            if (! is_array($row)
                || ! is_int($row['job_id'] ?? null)
                || ! is_int($row['learn_rank'] ?? null)
                || ! is_string($row['name'] ?? null)
                || ! is_string($row['activation_phrase'] ?? null)
                || ! is_string($row['activation_description'] ?? null)
                || trim($row['name']) === ''
                || trim($row['activation_phrase']) === ''
                || trim($row['activation_description']) === ''
            ) {
                throw new RuntimeException("Job-art flavor rewrite catalog row {$index} is malformed.");
            }

            $rewrite = [
                'job_id' => $row['job_id'],
                'learn_rank' => $row['learn_rank'],
                'name' => $row['name'],
                'activation_phrase' => $row['activation_phrase'],
                'activation_description' => $row['activation_description'],
            ];
            $key = $this->key($rewrite['job_id'], $rewrite['learn_rank'], $rewrite['name']);
            if (isset($catalog[$key])) {
                throw new RuntimeException("Duplicate job-art flavor rewrite identity: {$key}");
            }

            $catalog[$key] = $rewrite;
        }

        return $this->rewritesByKey = $catalog;
    }

    private function keyForSkill(Skill $skill): string
    {
        return $this->key(
            (int) $skill->job_id,
            (int) $skill->learn_rank,
            (string) $skill->name,
        );
    }

    private function key(int $jobId, int $learnRank, string $name): string
    {
        return $jobId.':'.$learnRank.':'.$name;
    }
}
