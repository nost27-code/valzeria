<?php

namespace App\Livewire;

use App\Models\ArenaRanking;
use App\Models\ArenaNpcRanking;
use App\Models\Character;
use App\Services\ArenaNpcRankingService;
use App\Services\CharacterPowerService;
use App\Services\CharacterStatusService;
use App\Services\EquipmentService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.facility', [
    'title' => '闘技場ランキング',
    'headerIconImage' => 'images/icon/icon_010.webp',
    'bgImage' => 'images/facilities/02_闘技場.webp',
])]
class ColosseumRanking extends Component
{
    public ?array $selectedPlayer = null;
    public ?array $selectedNpc = null;

    public function mount(): void
    {
        session(['current_location' => 'colosseum']);
    }

    public function openPlayerModal(int $characterId): void
    {
        $character = Character::visibleToPublic()
            ->with(['jobClass', 'arenaRanking'])
            ->find($characterId);

        if (!$character) {
            $this->selectedPlayer = null;
            return;
        }

        $this->selectedNpc = null;

        $statusService = app(CharacterStatusService::class);
        $equipmentService = app(EquipmentService::class);
        $stats = $statusService->getFinalStats($character);
        $equippedItems = $equipmentService->getEquippedItems($character);
        $weapon = $equippedItems['weapon'] ?? null;
        $armor = $equippedItems['armor'] ?? null;
        $accessory = $equippedItems['accessory'] ?? null;

        $this->selectedPlayer = [
            'id' => $character->id,
            'name' => $character->name,
            'icon' => $character->icon_path,
            'level' => (int) $character->level,
            'job' => $character->jobClass?->name ?? '冒険者',
            'rank' => $character->arenaRanking?->rank,
            'power' => app(CharacterPowerService::class)->fromFinalStats($stats),
            'hp' => (int) $character->current_hp,
            'max_hp' => (int) $stats['max_hp'],
            'mp' => (int) ($character->current_mp ?? 0),
            'max_mp' => (int) ($stats['max_mp'] ?? 0),
            'str' => (int) $stats['str'],
            'def' => (int) $stats['def'],
            'mag' => (int) $stats['mag'],
            'spr' => (int) $stats['spr'],
            'agi' => (int) $stats['agi'],
            'luk' => (int) $stats['luk'],
            'weapon' => $weapon ? $weapon->displayName() : 'なし',
            'armor' => $armor ? $armor->displayName() : 'なし',
            'accessory' => $accessory ? $accessory->displayName() : 'なし',
        ];
    }

    public function closePlayerModal(): void
    {
        $this->selectedPlayer = null;
    }

    public function openNpcModal(int $rankingId): void
    {
        $ranking = ArenaNpcRanking::with('npc')->find($rankingId);

        if (!$ranking || !$ranking->npc) {
            $this->selectedNpc = null;
            return;
        }

        $rankingService = app(ArenaNpcRankingService::class);
        $npc = $ranking->npc;

        $this->selectedPlayer = null;
        $this->selectedNpc = [
            'id' => (int) $ranking->id,
            'name' => $rankingService->npcDisplayName($npc),
            'full_name' => (string) $npc->npc_name,
            'icon' => asset($npc->image_path),
            'level' => (int) $ranking->level,
            'job' => $rankingService->npcJobLabel($npc),
            'rank' => (int) $ranking->rank,
            'power' => $rankingService->npcPowerForDisplay($ranking),
            'wins' => (int) $ranking->wins,
            'losses' => (int) $ranking->losses,
            'weapon' => $this->npcEquipmentName($npc, 'weapon'),
            'armor' => $this->npcEquipmentName($npc, 'armor'),
            'accessory' => $this->npcEquipmentName($npc, 'accessory'),
        ];
    }

    public function closeNpcModal(): void
    {
        $this->selectedNpc = null;
    }

    public function render()
    {
        $myCharacter = Auth::user()->currentCharacter();

        return view('livewire.colosseum-ranking', [
            'rankings' => app(ArenaNpcRankingService::class)->rankingEntries(100),
            'myCharacterId' => $myCharacter?->id,
        ]);
    }

    private function npcEquipmentName(object $npc, string $slot): string
    {
        $sets = [
            1 => ['風裂きの小太刀', '疾風鼠の羽織', '青紐の早駆け鈴'],
            2 => ['岩砕きの大鉈', '赤銅腹巻', '荒縄の力結び'],
            3 => ['流浪刀あけぼの', '旅雲の外套', '道標の勾玉'],
            4 => ['銅貨詰めの投げ袋', '隠し底の革ベスト', '拾運の古財布'],
            5 => ['草露の短槍', '草編みの胸当て', '野花の耳飾り'],
            6 => ['梢鳴りの弓', '木漏れ日のケープ', '若葉石の首飾り'],
            7 => ['石灯りの槌', '洞窟苔の肩当て', '蓄光石の腕輪'],
            9 => ['白樫の訓練剣', '新米騎士の胸当て', 'はじまりの剣帯'],
            10 => ['涙星の小杖', '泣き虫星のローブ', 'しずく硝子の指輪'],
            11 => ['潮切りの舶刀', '港風の短外套', '羅針盤の古針'],
            13 => ['森笛の短弓', '音葉の旅衣', '鳥呼びの笛飾り'],
            14 => ['炉床割りの鉄槌', '黒煤の鍛冶前掛け', '火花石の腕輪'],
            15 => ['雪明かりの錫杖', '白息の法衣', '凍星のロザリオ'],
            16 => ['砂紋の曲刀', '砂読みの薄衣', '真鍮の砂時計'],
            18 => ['墓標打ちの片手斧', '墓守りの黒外套', '鎮魂の鈴鍵'],
            19 => ['空渡りの細剣', '雲縫いのマント', '浮羽根のアンクレット'],
            20 => ['熔岩見張りの鉈', '火山灰の革鎧', '噴石の護符'],
            21 => ['隻眼狼の曲剣', '片目隠しの傭兵鎧', '傷跡の銀貨'],
            22 => ['黒猫爪のダガー', '夜路の盗衣', '月鈴のチョーカー'],
            23 => ['銀弦の長弓', '梟羽の軽鎧', '遠目石のブローチ'],
            24 => ['堅盾剣グランベル', '青鋼の盾鎧', '守勢の紋章'],
            25 => ['双月の細剣', '二影の戦衣', '交差刃の耳飾り'],
            27 => ['紅蓮杖イグニス', '火竜布のローブ', '燠火の指輪'],
            28 => ['氷華杖ノルン', '霜結いの法衣', '氷涙の髪飾り'],
            30 => ['無音刃しじま', '静寂織りの外套', '消音石の耳飾り'],
            31 => ['黒鉄弩クロウ', '狙撃手の煤革鎧', '照星の片眼鏡'],
            32 => ['影縫い苦無', '忍装束・夜走り', '忍び火の護符'],
            33 => ['聖鐘の司祭杖', '巡礼銀糸の法衣', '小聖堂の鍵飾り'],
            34 => ['紫電の魔剣', '魔紋入りの戦装束', '薄紫の封印環'],
            35 => ['白銀盾リュミエール', '聖盾騎士の甲冑', '誓約の青宝珠'],
            36 => ['狂牙斧バルバロイ', '獣骨の重鎧', '牙痕の首輪'],
            40 => ['無言剣グレイブ', '無口な傭兵の黒鎧', '黙契の鉄票'],
        ];

        $equipment = $sets[(int) ($npc->npc_id ?? 0)] ?? ['名もなき古剣', '色褪せた冒険衣', '小さな旅守り'];

        $index = match ($slot) {
            'weapon' => 0,
            'armor' => 1,
            default => 2,
        };

        return $equipment[$index];
    }
}
