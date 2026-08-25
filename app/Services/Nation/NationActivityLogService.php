<?php

namespace App\Services\Nation;

use App\Models\Character;
use App\Models\Nation;
use App\Models\NationActivityLog;

final class NationActivityLogService
{
    public function record(
        Nation $nation,
        string $eventType,
        ?Character $actor = null,
        ?Character $target = null,
        array $metadata = [],
    ): NationActivityLog {
        return NationActivityLog::create([
            'nation_id' => $nation->id,
            'actor_character_id' => $actor?->id,
            'target_character_id' => $target?->id,
            'event_type' => $eventType,
            'metadata' => $metadata === [] ? null : $metadata,
            'created_at' => now(),
        ]);
    }

    public function description(NationActivityLog $log): string
    {
        $actor = $log->actor?->name ?? '国家運営';
        $target = $log->target?->name ?? '対象者';
        $role = (string) ($log->metadata['role_label'] ?? '役職');

        return match ($log->event_type) {
            'nation_created' => "{$actor}が国家を建国した。",
            'join_application_submitted' => "{$actor}から加入申請が届いた。",
            'join_application_canceled' => "{$actor}の加入申請が取り消された。",
            'join_application_approved' => "{$actor}が{$target}の加入申請を承認した。",
            'join_application_rejected' => "{$actor}が{$target}の加入申請を却下した。",
            'member_joined' => "{$target}が国民になった。",
            'member_left' => "{$actor}が国家を脱退した。",
            'member_expelled' => "{$actor}が{$target}を追放した。",
            'role_assigned' => "{$actor}が{$target}を{$role}に任命した。",
            'role_removed' => "{$actor}が{$target}の役職を解除した。",
            'description_changed' => "{$actor}が国家紹介を変更した。",
            'recruitment_enabled' => "{$actor}が国民募集を開始した。",
            'recruitment_disabled' => "{$actor}が国民募集を停止した。",
            'recruitment_message_changed' => "{$actor}が募集文を変更した。",
            'emblem_changed' => "{$actor}が国家紋章を変更した。",
            'header_background_changed' => "{$actor}が国家ヘッダ背景を変更した。",
            'ruler_transferred' => "{$actor}が{$target}へ統治者の地位を譲った。",
            'dissolution_requested' => "{$actor}が国家解散を申請した。",
            'dissolution_canceled' => "{$actor}が国家解散を取り消した。",
            'nation_disbanded' => '国家が解散した。',
            default => '国家の状態が更新された。',
        };
    }
}
