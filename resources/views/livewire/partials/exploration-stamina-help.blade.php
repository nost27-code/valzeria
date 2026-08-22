<div class="overflow-hidden rounded-2xl border border-blue-100 bg-white text-slate-700 shadow-2xl">
    <div class="flex items-center justify-between gap-3 border-b border-blue-100 bg-gradient-to-r from-blue-50 to-cyan-50 px-4 py-3">
        <div class="flex items-center gap-2">
            <img src="{{ asset('images/icon/icon_082.webp') }}" alt="" class="h-6 w-6 object-contain">
            <div>
                <div class="text-sm font-black leading-tight text-blue-950">探索力</div>
                <div class="mt-0.5 text-[10px] font-bold leading-tight text-blue-700">探索へ出るための力</div>
            </div>
        </div>
        @if($mobile)
            <button type="button"
                    @click="closeHelp()"
                    class="flex h-8 w-8 items-center justify-center rounded-full border border-blue-100 bg-white text-lg font-black text-slate-500 shadow-sm"
                    aria-label="閉じる">×</button>
        @endif
    </div>

    <dl class="divide-y divide-slate-100 px-4 text-xs font-bold leading-relaxed">
        <div class="flex items-center justify-between gap-3 py-2.5">
            <dt class="text-slate-500">現在の探索力</dt>
            <dd class="font-black text-blue-950">
                <span x-text="current.toLocaleString()">{{ number_format((int) $stamina['current']) }}</span>
                <span class="text-slate-400">/</span>
                <span x-text="max.toLocaleString()">{{ number_format((int) $stamina['max']) }}</span>
            </dd>
        </div>
        <div class="flex items-center justify-between gap-3 py-2.5">
            <dt class="text-slate-500">次の自然回復</dt>
            <dd class="font-black text-blue-950">
                <span x-show="current < max" x-cloak>あと <span x-text="secondsUntilRecovery.toLocaleString()">{{ number_format((int) ($stamina['next_recovery_seconds'] ?? 0)) }}</span>秒</span>
                <span x-show="current === max" x-cloak>満タン</span>
                <span x-show="current > max" x-cloak>上限超過中</span>
            </dd>
        </div>
        <div class="flex items-center justify-between gap-3 py-2.5">
            <dt class="text-slate-500">自然回復</dt>
            <dd class="font-black text-slate-800"><span x-text="recoverySeconds.toLocaleString()">{{ number_format((int) ($stamina['recovery_seconds'] ?? 60)) }}</span>秒ごとに1</dd>
        </div>
        <div class="flex items-center justify-between gap-3 py-2.5">
            <dt class="text-slate-500">勝利数による上限</dt>
            <dd class="font-black text-slate-800">
                {{ number_format((int) ($staminaGrowth['base_max'] ?? $stamina['base_max'] ?? 250)) }}
                <span class="text-slate-400">/</span>
                {{ number_format((int) ($staminaGrowth['cap'] ?? 500)) }}
            </dd>
        </div>
        <div class="flex items-start justify-between gap-3 py-2.5">
            <dt class="shrink-0 text-slate-500">次の上限アップ</dt>
            <dd class="text-right font-black text-slate-800">
                @if(!empty($staminaGrowth['at_cap']))
                    最大まで成長済み
                @else
                    あと{{ number_format((int) ($staminaGrowth['wins_to_next'] ?? 0)) }}勝で
                    <span class="text-emerald-700">+{{ number_format((int) ($staminaGrowth['next_increase'] ?? 0)) }}</span>
                @endif
            </dd>
        </div>
        @if((int) ($stamina['bonus_max'] ?? 0) > 0)
            <div class="flex items-center justify-between gap-3 py-2.5 text-amber-700">
                <dt>支援パス効果</dt>
                <dd class="font-black">上限 +{{ number_format((int) $stamina['bonus_max']) }}</dd>
            </div>
        @endif
    </dl>

    <p class="border-t border-blue-100 bg-blue-50/70 px-4 py-3 text-[11px] font-bold leading-relaxed text-blue-900">
        アイテムで増えた探索力は上限を超えて保有できます。上限以上の間は自然回復しません。
    </p>
</div>
