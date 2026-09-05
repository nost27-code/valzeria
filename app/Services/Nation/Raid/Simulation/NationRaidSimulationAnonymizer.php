<?php

namespace App\Services\Nation\Raid\Simulation;

use RuntimeException;

/** Phase 2成果物へDB IDや表示名を出さないための安定匿名key生成器。 */
final class NationRaidSimulationAnonymizer
{
    private string $secret;

    public function __construct(?string $secret = null)
    {
        $this->secret = trim((string) ($secret ?? config('app.key')));
        if ($this->secret === '') {
            throw new RuntimeException('APP_KEY is required to build stable raid simulation keys.');
        }
    }

    public function participantKey(int|string $accountId): string
    {
        return $this->key('participant', $accountId, 'nrp2');
    }

    public function characterKey(int|string $characterId): string
    {
        return $this->key('character', $characterId, 'nrc2');
    }

    public function nationKey(int|string $nationId): string
    {
        return $this->key('nation', $nationId, 'nrn2');
    }

    /** APP_KEY自体を開示せず、同じ匿名化domainかだけを成果物間で確認する。 */
    public function keyId(): string
    {
        return substr(hash('sha256', 'nation-raid-phase2-key-id|'.$this->secret), 0, 16);
    }

    private function key(string $scope, int|string $value, string $prefix): string
    {
        return $prefix.'_'.substr(hash_hmac(
            'sha256',
            'nation-raid-phase2|'.$scope.'|'.(string) $value,
            $this->secret,
        ), 0, 32);
    }
}
