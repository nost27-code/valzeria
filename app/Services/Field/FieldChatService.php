<?php

namespace App\Services\Field;

use App\Models\Character;
use App\Models\FieldChatMessage;
use Illuminate\Support\Facades\Cache;

/**
 * フィールドの近くの人とのチャット。
 * 周り宛ては一定距離内、街の中宛ては同じ既存都市内にいる人へ届ける。
 */
class FieldChatService
{
    public const SCOPE_AREA = 'area';

    public const SCOPE_TOWN = 'town';

    public function __construct(private readonly ValzeriaFieldService $field) {}

    /** @return array{key: string, name: string}|null */
    public function zoneAt(string $plane, int $x, int $y): ?array
    {
        $tile = $this->field->tilePx();
        $tx = intdiv($x, $tile);
        $ty = intdiv($y, $tile);

        foreach ($this->field->cityDefinitions() as $city) {
            if ($city['plane'] === $plane
                && $tx >= $city['tx'] && $tx < $city['tx'] + $city['w']
                && $ty >= $city['ty'] && $ty < $city['ty'] + $city['h']) {
                return ['key' => "city:{$city['id']}", 'name' => $city['name']];
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    public function say(Character $character, string $plane, int $x, int $y, string $body, string $scope = self::SCOPE_AREA): array
    {
        $cfg = config('valzeria_field.chat');
        $text = $this->clean($body);
        if ($text === '') {
            return ['error' => 'メッセージを入力してください。'];
        }
        if (mb_strlen($text) > (int) $cfg['max_length']) {
            return ['error' => "メッセージは{$cfg['max_length']}文字以内で入力してください。"];
        }
        if ($character->is_frozen) {
            return ['error' => 'このアカウントは現在発言できません。'];
        }

        $zone = $scope === self::SCOPE_TOWN ? $this->zoneAt($plane, $x, $y) : null;
        if ($scope === self::SCOPE_TOWN && ! $zone) {
            return ['error' => '街の外にいるので、「街の中」には話せません。'];
        }
        if (! Cache::add("field_chat:{$character->id}", true, now()->addSeconds((int) $cfg['cooldown_seconds']))) {
            return ['error' => '少し間をあけてから話そう。'];
        }

        $message = FieldChatMessage::query()->create([
            'character_id' => $character->id,
            'plane' => $plane,
            'x' => $x,
            'y' => $y,
            'body' => $text,
            'zone' => $zone['key'] ?? null,
        ]);
        $this->pruneSometimes();

        return $this->present($message->setRelation('character', $character), $zone['name'] ?? null);
    }

    /**
     * @param  array{key: string, name: string}|null  $zone
     * @return list<array<string, mixed>>
     */
    public function heardAt(string $plane, int $x, int $y, int $sinceId = 0, ?array $zone = null): array
    {
        $cfg = config('valzeria_field.chat');
        $radius = (int) $cfg['hearing_tiles'] * $this->field->tilePx();
        $zoneKey = $zone['key'] ?? null;

        return FieldChatMessage::query()
            ->with('character:id,name')
            ->where('plane', $plane)
            ->where('id', '>', $sinceId)
            ->where('created_at', '>=', now()->subSeconds((int) $cfg['history_seconds']))
            ->where(function ($query) use ($x, $y, $radius, $zoneKey): void {
                $query->where(fn ($near) => $near->whereNull('zone')
                    ->whereBetween('x', [max(0, $x - $radius), $x + $radius])
                    ->whereBetween('y', [max(0, $y - $radius), $y + $radius]));
                if ($zoneKey) {
                    $query->orWhere('zone', $zoneKey);
                }
            })
            ->orderByDesc('id')
            ->limit(30)
            ->get()
            ->filter(fn (FieldChatMessage $message): bool => $message->character !== null
                && ($message->zone !== null || hypot($message->x - $x, $message->y - $y) <= $radius))
            ->sortBy('id')
            ->map(fn (FieldChatMessage $message): array => $this->present($message, $message->zone ? ($zone['name'] ?? null) : null))
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    private function present(FieldChatMessage $message, ?string $zoneName = null): array
    {
        return [
            'id' => (int) $message->id,
            'character_id' => (int) $message->character_id,
            'name' => (string) ($message->character?->name ?? '冒険者'),
            'body' => (string) $message->body,
            'scope' => $message->zone ? self::SCOPE_TOWN : self::SCOPE_AREA,
            'zone_name' => $zoneName,
            'x' => $message->x,
            'y' => $message->y,
        ];
    }

    private function clean(string $body): string
    {
        $text = preg_replace('/[\p{Cc}\p{Cf}]+/u', ' ', $body) ?? '';

        return trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    }

    private function pruneSometimes(): void
    {
        if (random_int(1, 50) !== 1) {
            return;
        }
        FieldChatMessage::query()
            ->where('created_at', '<', now()->subDays((int) config('valzeria_field.chat.keep_days')))
            ->delete();
    }
}
