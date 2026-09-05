<?php

namespace App\Services\Nation\Raid;

use App\Models\NationRaidEvent;
use DomainException;
use Illuminate\Support\Facades\Schema;

/** 公開導線だけを判定する。描画・クリックでは開催開始や出撃の書込みを行わない。 */
final class NationRaidEntryService
{
    public function isPublished(): bool
    {
        return (bool) config('features.nation_competitive_raid_enabled', false) || $this->isPreviewPublished();
    }

    public function isPreviewPublished(): bool
    {
        return (bool) config('features.nation_competitive_raid_preview_enabled', false);
    }

    public function activeEvent(): ?NationRaidEvent
    {
        // OFFではschema確認も含め、レイドDBへ触れない。
        if (! config('features.nation_competitive_raid_enabled', false) || ! Schema::hasTable('nation_raid_events')) {
            return null;
        }

        $event = NationRaidEvent::query()
            ->where('status', NationRaidEvent::STATUS_ACTIVE)
            ->whereNull('sorties_paused_at')
            ->where('starts_at', '<=', now())
            ->where('ends_at', '>', now())
            ->orderByDesc('starts_at')
            ->first();
        if ($event === null) {
            return null;
        }

        try {
            // 正式出撃と同じ、書込みを伴わない開催条件の検査を使う。
            // balance未承認・ruleset不一致・必須flag不足を「開催中」と表示しない。
            app(NationRaidSortieService::class)->assertAdmission($event);
        } catch (DomainException) {
            return null;
        }

        return $event;
    }
}
