<?php

namespace App\Services;

use App\Models\Skill;
use App\Support\JobArtEffectCatalog;

/** Approved Rank5 v6.1 metadata. Runtime use is always feature-gated. */
final class JobArtV2Rank5V6Catalog
{
    public function __construct(
        private readonly ?JobArtV2RoleEffectCatalog $roleEffectCatalog = null,
        private readonly ?JobArtV2DamageSemanticsCatalog $damageSemanticsCatalog = null,
    ) {}

    /** @var array<int, array{name:string,power:?int,trigger_mode:string,effect_text:string}> */
    private const SPECS = [
        1 => ['name' => '受け返し', 'power' => 100, 'trigger_mode' => 'scheduled', 'effect_text' => '威力100%の物理ダメージ。直前の自分の行動後に受け流しへ成功していた場合、最終ダメージを×1.35する'],
        2 => ['name' => '二段穿ち', 'power' => 100, 'trigger_mode' => 'scheduled', 'effect_text' => '2Hitとも相手の防御を25%無視。会心判定は各HIT'],
        3 => ['name' => '急所狙い', 'power' => 100, 'trigger_mode' => 'scheduled', 'effect_text' => '会心率+15pt。標的印を1段階付与'],
        4 => ['name' => '狙い撃ち', 'power' => 100, 'trigger_mode' => 'scheduled', 'effect_text' => '命中率を最大+12pt。対奥義/大技予告中の相手にHITでSPを最大SPの3%削る'],
        5 => ['name' => '連環崩打', 'power' => 100, 'trigger_mode' => 'scheduled', 'effect_text' => '3Hit。HITで崩し印を1段階付与（1行動につき最大1段階）'],
        6 => ['name' => '天測の陣', 'power' => 100, 'trigger_mode' => 'scheduled', 'effect_text' => '天測の場を5ラウンド展開（この攻撃には非適用）'],
        7 => ['name' => '癒しの祈り', 'power' => null, 'trigger_mode' => 'scheduled', 'effect_text' => '攻撃なし。精神150%分、自分のHPを回復する。その後、次の自分の行動開始まで、次に受ける直接攻撃のダメージを20%軽減する（1回）'],
        8 => ['name' => '幸運の一手', 'power' => 100, 'trigger_mode' => 'scheduled', 'effect_text' => '通常探索勝利時のGold+7%'],
        9 => ['name' => '魔法剣', 'power' => 100, 'trigger_mode' => 'scheduled', 'effect_text' => '攻撃/魔力の高い方を参照する複合ダメージ。最大HP3%を非致死消費し最終ダメージ×1.15'],
        10 => ['name' => 'ホーリーブレイド', 'power' => 100, 'trigger_mode' => 'scheduled', 'effect_text' => '威力100%の物理ダメージ。最大HP7%分、自分のHPを回復する。その後、次の自分の行動開始まで、次に受ける直接攻撃のダメージを20%軽減する（1回）'],
        11 => ['name' => '居合斬り', 'power' => 100, 'trigger_mode' => 'scheduled', 'effect_text' => '受け流し率+20%。受け流しに成功した場合、次の自分の行動開始まで、次に受ける直接攻撃のダメージを20%軽減する（1回）'],
        12 => ['name' => '勝利の采配', 'power' => null, 'trigger_mode' => 'scheduled', 'effect_text' => '攻撃なし。次に使用する戦技の発動率+20pt。その戦技を、直前に成功した戦技と異なる区分（始動／連携／奥義）から優先して選ぶ'],
        13 => ['name' => '闘技連斬', 'power' => 100, 'trigger_mode' => 'scheduled', 'effect_text' => '3Hit。直前の自分の行動後に物理攻撃を受けていた場合、最終ダメージ×1.20'],
        14 => ['name' => '暴走撃', 'power' => 100, 'trigger_mode' => 'scheduled', 'effect_text' => '反動で最大HPの8%のダメージを受ける。行動開始時のHPが50%以下なら最終ダメージを×1.25する'],
        15 => ['name' => 'ガーディアンブロウ', 'power' => 100, 'trigger_mode' => 'reactive', 'effect_text' => '次の自分の行動開始まで、受けるダメージを20%軽減する。奥義または大技の予告中に発動した場合は、20%軽減の代わりに、その予告行動のダメージを35%軽減する'],
        16 => ['name' => '戦利の一撃', 'power' => 100, 'trigger_mode' => 'scheduled', 'effect_text' => '探索報酬に小補正。相手の防御を20%無視'],
        17 => ['name' => '影縫い', 'power' => 100, 'trigger_mode' => 'scheduled', 'effect_text' => '相手敏捷-15%(3T)。標的印を1段階付与'],
        18 => ['name' => 'クリティカルショット', 'power' => 100, 'trigger_mode' => 'scheduled', 'effect_text' => '命中率+10pt、会心率+10pt', 'accuracy_bonus_points' => 10, 'critical_bonus_points' => 10],
        19 => ['name' => 'スピリットスティール', 'power' => 100, 'trigger_mode' => 'scheduled', 'effect_text' => '与ダメージの30%を吸収。相手精神-12%(3T)。相手SPを最大SPの3%削る'],
        20 => ['name' => '掘り出し物', 'power' => 100, 'trigger_mode' => 'scheduled', 'effect_text' => '通常素材枠の抽選率+6pt'],
        21 => ['name' => '破邪拳', 'power' => 100, 'trigger_mode' => 'scheduled', 'effect_text' => '相手の精神参照。魔法型/不死系に最終ダメージ×1.20'],
        22 => ['name' => 'エレメントアロー', 'power' => 100, 'trigger_mode' => 'scheduled', 'effect_text' => '物理経路と魔法経路を比較し高い方で1回与える'],
        23 => ['name' => '勇気の旋律', 'power' => null, 'trigger_mode' => 'scheduled', 'effect_text' => '攻撃なし。現在の場を3ターン延長（上限8）'],
        24 => ['name' => 'セイクリッドライト', 'power' => 100, 'trigger_mode' => 'scheduled', 'effect_text' => '最大HP6%分HP回復。現在の場を2ラウンド延長（上限8）'],
        25 => ['name' => '秘薬調合', 'power' => null, 'trigger_mode' => 'scheduled', 'effect_text' => '精神110%分HP回復。最大SP10%回復。有害状態を優先順に最大1種浄化'],
        26 => ['name' => '錬成爆弾', 'power' => 100, 'trigger_mode' => 'scheduled', 'effect_text' => '相手防御/精神-15%(3T)。相手の強化のうち残り最長の1件を2ターン短縮'],
        27 => ['name' => 'ブレイブヒール', 'power' => 100, 'trigger_mode' => 'scheduled', 'effect_text' => '最大HP9%分HP回復。次に使用する戦技の発動率+15pt'],
        28 => ['name' => '無拍子', 'power' => 102, 'trigger_mode' => 'reactive', 'effect_text' => '対奥義/大技を20%軽減。1以上軽減した場合、次の反撃系戦技を×1.20'],
        29 => ['name' => '賢者の結界', 'power' => 100, 'trigger_mode' => 'scheduled', 'effect_text' => '次の自分の行動開始まで、受けるダメージを20%軽減する'],
        30 => ['name' => '暗黒剣', 'power' => 100, 'trigger_mode' => 'scheduled', 'effect_text' => '与ダメージの35%を吸収。自傷5%。対奥義予告中にHITで冥蝕反噬を付与'],
        31 => ['name' => 'ゴールドラッシュ', 'power' => 104, 'trigger_mode' => 'scheduled', 'effect_text' => '4Hit。探索報酬に小補正'],
        32 => ['name' => 'ドラゴンダイブ', 'power' => 100, 'trigger_mode' => 'scheduled', 'effect_text' => '相手の防御を30%無視。対奥義/大技予告中は×1.15かつ防御50%無視'],
        33 => ['name' => '羅刹連撃', 'power' => 102, 'trigger_mode' => 'scheduled', 'effect_text' => '5Hit。対奥義予告中にHITかつ崩し印があれば準備効果を1件解除'],
        34 => ['name' => '夢幻殺', 'power' => 100, 'trigger_mode' => 'scheduled', 'effect_text' => '相手防御/精神-15%(3T)。標的印を1段階付与'],
        35 => ['name' => '魔導砲', 'power' => 105, 'trigger_mode' => 'scheduled', 'effect_text' => '相手の精神を15%無視'],
        36 => ['name' => '神罰の槌', 'power' => 100, 'trigger_mode' => 'scheduled', 'effect_text' => '相手の魔力を−15%する（3ターン）。次の自分の行動開始まで、受けるダメージを20%軽減する'],
        37 => ['name' => 'シャドウスナイプ', 'power' => 105, 'trigger_mode' => 'scheduled', 'effect_text' => '2Hit。標的印を1段階付与'],
        38 => ['name' => '王者の秘薬', 'power' => null, 'trigger_mode' => 'scheduled', 'effect_text' => '精神110%分HP回復＋最大SP10%回復。残存割合が低い側を×1.5'],
        44 => ['name' => '聖盾裁き', 'power' => 155, 'trigger_mode' => 'scheduled', 'effect_text' => '次の自分の行動開始まで被ダメージ25%軽減。実際に1以上軽減した場合、有害状態を1種浄化する'],
        45 => ['name' => '魔弓連星', 'power' => 165, 'trigger_mode' => 'scheduled', 'effect_text' => '魔力参照。相手の防御を25%無視'],
        46 => ['name' => '祝福の大旋律', 'power' => 158, 'trigger_mode' => 'scheduled', 'effect_text' => '現在の場を3ラウンド延長（上限8）。不変律を得る（次にターン制効果が短縮・解除される時、1回だけ無効にする）'],
        47 => ['name' => '霊薬の加護', 'power' => null, 'trigger_mode' => 'scheduled', 'effect_text' => '攻撃なし。精神120%分、自分のHPを回復し、最大SP8%分、自分のSPを回復する。通常探索勝利時のGold獲得量を10%増やし、通常素材枠の抽選率を8ポイント、レア素材枠の抽選率を5ポイント上げる'],
        48 => ['name' => '王戦の号令', 'power' => 164, 'trigger_mode' => 'reactive', 'effect_text' => '相手の奥義/大技が発動可能になる時点を1行動分遅らせる（1予告1回）'],
        49 => ['name' => '大錬成爆装', 'power' => 167, 'trigger_mode' => 'reactive', 'effect_text' => '対奥義予告中にHITで、相手の次2回の資源獲得を各-1。探索報酬に小補正'],
        50 => ['name' => '聖剣烈破', 'power' => 185, 'trigger_mode' => 'scheduled', 'effect_text' => '受け流し率+20%。受け流しに成功した場合、次に使用する反撃系戦技の最終ダメージを×1.20する'],
        51 => ['name' => '黒炎斬', 'power' => 196, 'trigger_mode' => 'scheduled', 'effect_text' => '最大HP5%を非致死消費して最終ダメージ×1.15。獄炎ナイトメアの自傷履歴に加算'],
        52 => ['name' => '蒼天竜槍', 'power' => 182, 'trigger_mode' => 'scheduled', 'effect_text' => '蒼天構え中なら相手の防御を35%無視し、蒼天構えを1ターン延長'],
        53 => ['name' => '星詠みの光', 'power' => 176, 'trigger_mode' => 'scheduled', 'effect_text' => '現在の場を2ターン延長。対奥義予告中なら封式の場を展開'],
        54 => ['name' => '影縫い乱舞', 'power' => 184, 'trigger_mode' => 'reactive', 'effect_text' => '標的印1段階以上で発動可。標的印を1段階消費。直前の行動種類を3ターン封じる'],
        55 => ['name' => '鋼機魔導砲', 'power' => 200, 'trigger_mode' => 'scheduled', 'effect_text' => '照準8pt以上でのみ発動可。命中率+5pt', 'accuracy_bonus_points' => 5],
        56 => ['name' => '聖域結界', 'power' => 182, 'trigger_mode' => 'scheduled', 'effect_text' => '次の自分の行動開始まで被ダメージ25%軽減。実際に1以上軽減した場合、有害状態を1種浄化する'],
        57 => ['name' => '黄金転化', 'power' => 194, 'trigger_mode' => 'scheduled', 'effect_text' => '探索報酬に小補正'],
        58 => ['name' => '雷拳乱舞', 'power' => 196, 'trigger_mode' => 'scheduled', 'effect_text' => '3Hit。崩し印は1行動につき最大1段階まで'],
        59 => ['name' => '勝機の戦陣', 'power' => 191, 'trigger_mode' => 'scheduled', 'effect_text' => '次の指揮系戦技を直前と異なる区分から優先し、発動率+20pt'],
        60 => ['name' => '剣冠裁断', 'power' => 200, 'trigger_mode' => 'scheduled', 'effect_text' => '受け流しの構え中なら最終ダメージ×1.20。受け流し率+20%'],
        61 => ['name' => '黒冠魔剣', 'power' => 203, 'trigger_mode' => 'scheduled', 'effect_text' => '最大HP3%を非致死消費。行動開始時HP50%以下なら最終ダメージ×1.25'],
        62 => ['name' => '竜冠穿槍', 'power' => 200, 'trigger_mode' => 'scheduled', 'effect_text' => '貫通構え中なら相手の防御を35%無視し、貫通構えを再形成'],
        63 => ['name' => '星冠天導', 'power' => 209, 'trigger_mode' => 'scheduled', 'effect_text' => '現在の場を2ラウンド延長（上限8）。場がない場合は星光の場を4ラウンド展開'],
        64 => ['name' => '影冠狙撃', 'power' => 220, 'trigger_mode' => 'reactive', 'effect_text' => '標的印1段階以上で発動可。標的印を1段階消費。封じ対象の読み替えを1回'],
        65 => ['name' => '鋼冠機砲', 'power' => 209, 'trigger_mode' => 'scheduled', 'effect_text' => '命中率+10pt。HITで相手の最大SP3%分、現在SPを削る', 'accuracy_bonus_points' => 10],
        66 => ['name' => '聖冠大結界', 'power' => 181, 'trigger_mode' => 'scheduled', 'effect_text' => '有害状態を全浄化。浄化成功で聖護+1。次の直接攻撃を35%軽減'],
        67 => ['name' => '金冠錬成', 'power' => 212, 'trigger_mode' => 'scheduled', 'effect_text' => '相手が次の2回の行動で系譜資源を獲得しなかった場合、触媒+2'],
        68 => ['name' => '雷冠閃拳', 'power' => 212, 'trigger_mode' => 'scheduled', 'effect_text' => 'この戦技で付与した崩し印が浄化された場合、残心として保持'],
        69 => ['name' => '戦冠総攻令', 'power' => 212, 'trigger_mode' => 'scheduled', 'effect_text' => '次に使用する指揮系戦技の発動率+20pt'],
        70 => ['name' => '暁光ブレイク', 'power' => 218, 'trigger_mode' => 'scheduled', 'effect_text' => '複合ダメージ。受け流し率+15%。受け流しに成功した場合、次の自分の行動開始まで被ダメージ20%軽減'],
        71 => ['name' => '黒月執行', 'power' => 221, 'trigger_mode' => 'scheduled', 'effect_text' => '最大HP3%を非致死消費。この戦闘の累計自傷が最大HP15%を超えていれば最終ダメージ×1.25'],
        72 => ['name' => '星天裁光', 'power' => 221, 'trigger_mode' => 'scheduled', 'effect_text' => '現在の場を2ラウンド延長。場が天測なら、この攻撃の命中率+15pt・会心率+10pt'],
        73 => ['name' => '蒼竜覇撃', 'power' => 218, 'trigger_mode' => 'scheduled', 'effect_text' => '相手の防御を20%無視。貫通構え/蒼天構え中なら40%無視へ引き上げ、構えを1ターン延長'],
        74 => ['name' => '天機戦術', 'power' => 218, 'trigger_mode' => 'scheduled', 'effect_text' => '命中+10pt/会心+10pt。HITで相手の最大SP4%分、現在SPを削る。探索報酬に小補正', 'accuracy_bonus_points' => 10, 'critical_bonus_points' => 10],
        75 => ['name' => '聖域審判', 'power' => 214, 'trigger_mode' => 'scheduled', 'effect_text' => '次に使用する戦技の発動率+15pt。その戦技が奥義なら、その奥義の最終ダメージを×1.15'],
        76 => ['name' => '幻葬魔葬', 'power' => 221, 'trigger_mode' => 'scheduled', 'effect_text' => '標的印を1段階付与。標的印が2段階以上なら相手の敏捷と運を-15%(3T)'],
        77 => ['name' => '時詠み渡り', 'power' => 231, 'trigger_mode' => 'scheduled', 'effect_text' => '自分のターン制強化のうち残り最短の1件を2ターン延長'],
        78 => ['name' => '荒天覇撃', 'power' => 218, 'trigger_mode' => 'scheduled', 'effect_text' => '3Hit。崩し印を1段階付与。崩し印が2段階以上なら相手の解除可能な強化を1件解除'],
        79 => ['name' => '白銀王盾', 'power' => 221, 'trigger_mode' => 'scheduled', 'effect_text' => '被ダメージ15%軽減。1以上軽減した場合、3ターンの間 防御と精神を+20%'],
        80 => ['name' => '天翔剣皇斬', 'power' => 248, 'trigger_mode' => 'scheduled', 'effect_text' => '次に使用する戦技の発動率+15pt。その戦技が始動なら、その始動の最終ダメージを×1.25'],
        81 => ['name' => '黒焔魔皇破', 'power' => 248, 'trigger_mode' => 'scheduled', 'effect_text' => '命中+10pt/会心+10pt。MISSした場合、次に使用する照準系戦技の命中率を+25pt', 'accuracy_bonus_points' => 10, 'critical_bonus_points' => 10, 'miss_next_aim_accuracy_bonus_points' => 25],
        82 => ['name' => '世界樹の祝福', 'power' => 229, 'trigger_mode' => 'scheduled', 'effect_text' => '精神150%分HP回復。有害状態を最大2種浄化し、浄化に成功した場合 3ターンの間 精神を+25%'],
        83 => ['name' => '影葬王刃', 'power' => 244, 'trigger_mode' => 'scheduled', 'effect_text' => '標的印を1段階付与。HITで相手が直前に使用した行動の種類を2ターン封じる'],
        84 => ['name' => '星海羅針', 'power' => 244, 'trigger_mode' => 'scheduled', 'effect_text' => '威力244%の魔法ダメージ。直前に上書きされた自分の場を5ラウンドで再展開する。通常探索勝利時のGold獲得量を2%増やし、通常素材枠の抽選率を2ポイント上げる'],
        95 => ['name' => '六星救世陣', 'power' => 240, 'trigger_mode' => 'scheduled', 'effect_text' => '複合ダメージ。受け流し率+15%。この戦闘で受け流しに成功していれば最終ダメージ×1.25'],
        96 => ['name' => '影界侵食', 'power' => 244, 'trigger_mode' => 'scheduled', 'effect_text' => '最大HP3%を非致死消費。与えたダメージの30%分HPを回復'],
        97 => ['name' => '神代防壁', 'power' => 229, 'trigger_mode' => 'scheduled', 'effect_text' => '最大SP5%分SPを回復。次に受ける直接攻撃のダメージを30%軽減'],
        98 => ['name' => '蒼竜王牙', 'power' => 248, 'trigger_mode' => 'scheduled', 'effect_text' => '2Hit。相手の防御を20%無視。会心が1回でも発生した場合、貫通構えを形成する'],
        99 => ['name' => 'クロノシフト', 'power' => 244, 'trigger_mode' => 'scheduled', 'effect_text' => '崩し印を1段階付与。相手のターン制強化のうち残り最長の1件を3ターン短縮'],
        85 => ['name' => '星律神裁', 'power' => 295, 'trigger_mode' => 'reactive', 'effect_text' => '主場がある時のみ発動可。現在の場を2ラウンドロックする'],
        86 => ['name' => '深淵覇王撃', 'power' => 271, 'trigger_mode' => 'scheduled', 'effect_text' => '最大HP3%を非致死消費。行動開始時HP40%以下なら最終ダメージ×1.30'],
        87 => ['name' => '時環支配', 'power' => 267, 'trigger_mode' => 'scheduled', 'effect_text' => '次に使用する戦技の発動率を+25ptし、その戦技の最終ダメージを×1.10する'],
        88 => ['name' => '天竜神牙', 'power' => 267, 'trigger_mode' => 'scheduled', 'effect_text' => '相手の防御を30%無視。貫通構えを形成。既に構え中なら50%無視へ引き上げ'],
        89 => ['name' => '魔王神滅', 'power' => 262, 'trigger_mode' => 'scheduled', 'effect_text' => '標的印を2段階付与。標的印が3段階なら相手の全能力を-10%(3T)'],
        90 => ['name' => '雷霆神拳', 'power' => 267, 'trigger_mode' => 'scheduled', 'effect_text' => '4Hit。崩し印を1段階付与。崩し印が3段階なら相手の強化をすべて解除し崩し印を3段階消費'],
        91 => ['name' => '虚空導光', 'power' => 288, 'trigger_mode' => 'scheduled', 'effect_text' => '《金蝕》を1回付与'],
        92 => ['name' => '世界樹神歌', 'power' => 249, 'trigger_mode' => 'scheduled', 'effect_text' => '精神180%分HP回復。被ダメージ20%軽減。1以上軽減した場合、4ターンの間 防御と精神を+25%'],
        93 => ['name' => '終焉聖裁', 'power' => 278, 'trigger_mode' => 'reactive', 'effect_text' => '受け流し率+20%。相手が奥義/大技予告中なら、その行動を25%軽減する準備を得る'],
        94 => ['name' => '天命改変', 'power' => 262, 'trigger_mode' => 'scheduled', 'effect_text' => '命中+15pt/会心+15pt。HITで相手の最大SP5%分、現在SPを削る。探索報酬に小補正', 'accuracy_bonus_points' => 15, 'critical_bonus_points' => 15],
    ];

    /** @var list<int> */
    private const ATTACKLESS_JOB_IDS = [7, 12, 23, 25, 38, 47];

    /** @return array{name:string,power:?int,trigger_mode:string,effect_text:string,accuracy_bonus_points?:int,critical_bonus_points?:int,miss_next_aim_accuracy_bonus_points?:int}|null */
    public function forSkill(Skill $skill): ?array
    {
        if (! $skill->isJobArt() || (int) $skill->learn_rank !== 5) {
            return null;
        }

        $spec = self::SPECS[(int) $skill->job_id] ?? null;
        return $spec !== null && $spec['name'] === (string) $skill->name ? $spec : null;
    }

    public function powerFor(Skill $skill): ?int
    {
        return $this->forSkill($skill)['power'] ?? null;
    }

    public function triggerMode(Skill $skill): ?string
    {
        return $this->forSkill($skill)['trigger_mode'] ?? null;
    }

    public function isReactive(Skill $skill): bool
    {
        return $this->triggerMode($skill) === 'reactive';
    }

    public function isAttackless(Skill $skill): bool
    {
        return $this->forSkill($skill) !== null
            && in_array((int) $skill->job_id, self::ATTACKLESS_JOB_IDS, true);
    }

    public function effectText(Skill $skill): ?string
    {
        return $this->forSkill($skill)['effect_text'] ?? null;
    }

    public function descriptionFor(Skill $skill): ?string
    {
        $spec = $this->forSkill($skill);
        if ($spec === null) {
            return null;
        }

        $effectText = trim((string) $spec['effect_text']);
        if ($spec['power'] === null || $this->isAttackless($skill)) {
            $supportEffectText = trim((string) preg_replace('/^攻撃なし。?/u', '', $effectText));

            return '攻撃なし。'.$this->remainingEffectText($supportEffectText);
        }

        $roleMetadata = ($this->roleEffectCatalog ?? app(JobArtV2RoleEffectCatalog::class))->forArt($skill) ?? [];
        $damageSentence = $this->damageSentence(
            $skill,
            (int) $spec['power'],
            $this->hitCountForDescription($skill, $effectText),
            $roleMetadata,
        );
        $remainingEffectText = $this->remainingEffectText($effectText, $roleMetadata);

        return $damageSentence.$remainingEffectText;
    }

    public function accuracyBonusPoints(Skill $skill): float
    {
        return max(0.0, (float) ($this->forSkill($skill)['accuracy_bonus_points'] ?? 0.0));
    }

    public function criticalBonusPoints(Skill $skill): float
    {
        return max(0.0, (float) ($this->forSkill($skill)['critical_bonus_points'] ?? 0.0));
    }

    public function missNextAimAccuracyBonusPoints(Skill $skill): float
    {
        return max(0.0, (float) ($this->forSkill($skill)['miss_next_aim_accuracy_bonus_points'] ?? 0.0));
    }

    /** @return array<int, array{name:string,power:?int,trigger_mode:string,effect_text:string,accuracy_bonus_points?:int,critical_bonus_points?:int,miss_next_aim_accuracy_bonus_points?:int}> */
    public function all(): array
    {
        return self::SPECS;
    }

    /** @param array<string, mixed> $roleMetadata */
    private function damageSentence(Skill $skill, int $power, int $hitCount, array $roleMetadata): string
    {
        $route = $this->damageRoute($skill, $roleMetadata);
        if ($route === 'adaptive') {
            $damage = $hitCount > 1
                ? "合計威力{$power}%のダメージを{$hitCount}回に分けて与える"
                : "威力{$power}%のダメージを与える";

            return '自分の攻撃と相手の防御を使う物理経路と、自分の魔力と相手の精神を使う魔力経路を比較し、'
                ."高い方で相手に{$damage}。";
        }

        if ($route === 'normal_attack') {
            return $hitCount > 1
                ? "相手に通常攻撃と同じ種類（物理／魔力）で、合計威力{$power}%のダメージを{$hitCount}回に分けて与える。"
                : "相手に通常攻撃と同じ種類（物理／魔力）で、威力{$power}%のダメージを与える。";
        }

        $statRouteSentence = $this->damageStatRouteSentence($roleMetadata, $route, $power, $hitCount);
        if ($statRouteSentence !== null) {
            return $statRouteSentence;
        }

        $label = match ($route) {
            'magical' => '魔力',
            'hybrid' => '複合',
            default => '物理',
        };

        return $hitCount > 1
            ? "相手に合計威力{$power}%の{$label}ダメージを{$hitCount}回に分けて与える。"
            : "相手に威力{$power}%の{$label}ダメージを与える。";
    }

    /** @param array<string, mixed> $roleMetadata */
    private function damageRoute(Skill $skill, array $roleMetadata): string
    {
        $semantics = ($this->damageSemanticsCatalog ?? app(JobArtV2DamageSemanticsCatalog::class))
            ->overrideFor($skill);
        if (is_string($semantics['damage_category'] ?? null)) {
            return (string) $semantics['damage_category'];
        }

        if (is_array($roleMetadata['adaptive_route'] ?? null)) {
            return 'adaptive';
        }
        if ((bool) ($roleMetadata['use_normal_attack_damage_type'] ?? false)) {
            return 'normal_attack';
        }
        if (is_string($roleMetadata['damage_stat_route']['damage_category'] ?? null)) {
            return (string) $roleMetadata['damage_stat_route']['damage_category'];
        }

        $replacementTemplate = $roleMetadata['replacement_template'] ?? null;
        $template = is_string($replacementTemplate) && $replacementTemplate !== ''
            ? $replacementTemplate
            : (string) $skill->effect_template;
        if (JobArtEffectCatalog::usesNormalAttackDamageType($template)) {
            return 'normal_attack';
        }
        if ($template === 'DRAIN') {
            return JobArtEffectCatalog::drainDamageType($skill->damage_type);
        }

        return JobArtEffectCatalog::damageType($template);
    }

    /** @param array<string, mixed> $roleMetadata */
    private function damageStatRouteSentence(array $roleMetadata, string $route, int $power, int $hitCount): ?string
    {
        $statRoute = $roleMetadata['damage_stat_route'] ?? null;
        if (! is_array($statRoute)) {
            return null;
        }

        $attackLabel = $this->playerStatLabel($statRoute['attack_stat'] ?? null);
        $defenseLabel = $this->playerStatLabel($statRoute['defense_stat'] ?? null);
        $damageLabel = match ($route) {
            'physical' => '物理',
            'magical' => '魔力',
            default => null,
        };
        if ($attackLabel === null || $defenseLabel === null || $damageLabel === null) {
            return null;
        }

        $defenseIgnorePercent = max(0, (int) ($statRoute['defense_ignore_percent'] ?? 0));
        $defenseReference = $defenseIgnorePercent > 0
            ? "、{$defenseIgnorePercent}%無視した相手の{$defenseLabel}"
            : "相手の{$defenseLabel}";
        $damage = $hitCount > 1
            ? "合計威力{$power}%の{$damageLabel}ダメージを{$hitCount}回に分けて与える"
            : "威力{$power}%の{$damageLabel}ダメージを与える";

        return "自分の{$attackLabel}と{$defenseReference}を使い、相手に{$damage}。";
    }

    private function playerStatLabel(mixed $stat): ?string
    {
        return match ($stat) {
            'str' => '攻撃',
            'def' => '防御',
            'mag' => '魔力',
            'spr' => '精神',
            default => null,
        };
    }

    private function hitCountForDescription(Skill $skill, string $effectText): int
    {
        if (preg_match('/(\d+)Hit/u', $effectText, $matches) === 1) {
            return max(1, (int) $matches[1]);
        }

        $configuredHitCount = (int) $skill->hit_count;
        if ($configuredHitCount > 0) {
            return $configuredHitCount;
        }

        return max(1, JobArtEffectCatalog::hitCount((string) $skill->effect_template));
    }

    /** @param array<string, mixed> $roleMetadata */
    private function remainingEffectText(string $effectText, array $roleMetadata = []): string
    {
        $replacements = [
            '/^威力\d+(?:\.\d+)?%の(?:物理|魔法|魔力)ダメージ。/u' => '',
            '/^攻撃\/魔力の高い方を参照する複合ダメージ。/u' => '自分の攻撃と魔力の高い方を参照する。',
            '/^物理経路と魔法経路を比較し高い方で1回与える。?/u' => '',
            '/^複合ダメージ。/u' => '',
            '/^(\d+)Hitとも/u' => '$1回とも',
            '/^\d+Hit。/u' => '',
        ];

        $statRoute = $roleMetadata['damage_stat_route'] ?? null;
        if (is_array($statRoute)) {
            $attackLabel = $this->playerStatLabel($statRoute['attack_stat'] ?? null);
            $defenseLabel = $this->playerStatLabel($statRoute['defense_stat'] ?? null);
            $defenseIgnorePercent = max(0, (int) ($statRoute['defense_ignore_percent'] ?? 0));
            if ($attackLabel !== null) {
                $replacements['/^(?:自分の)?'.preg_quote($attackLabel, '/').'参照。?/u'] = '';
            }
            if ($defenseLabel !== null) {
                $replacements['/^相手の'.preg_quote($defenseLabel, '/').'参照。?/u'] = '';
            }
            if ($defenseIgnorePercent > 0 && $defenseLabel !== null) {
                $replacements['/^相手の'.preg_quote($defenseLabel, '/').'を'.$defenseIgnorePercent.'%無視。?/u'] = '';
            }
        }

        $remaining = trim((string) preg_replace(
            array_keys($replacements),
            array_values($replacements),
            $effectText,
        ));
        if ($remaining === '') {
            return '';
        }

        return str_ends_with($remaining, '。') ? $remaining : $remaining.'。';
    }
}
