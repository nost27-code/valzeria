@php
    $cumulativeRateBps = 0;
    $performanceBands = config('equipment_enhancement.performance_bands', []);
@endphp

<section class="mt-3 rounded-lg border border-amber-200 bg-white p-3">
    <h4 class="font-black text-amber-950">武器・防具の性能上昇</h4>
    <p class="mt-1.5 text-sm leading-relaxed text-slate-700">基礎性能に対する上昇率です。低い基礎性能では、この割合より高い最低保証が適用される場合があります。装飾品は能力値を合計で配分する別の仕組みです。</p>
    <div class="mt-3 overflow-x-auto">
        <table class="min-w-full text-left text-xs text-slate-700">
            <thead class="border-b border-amber-200 text-amber-950">
                <tr>
                    <th class="px-2 py-1.5 font-black">強化先</th>
                    <th class="px-2 py-1.5 text-right font-black">その段階</th>
                    <th class="px-2 py-1.5 text-right font-black">累計</th>
                </tr>
            </thead>
            <tbody>
                @foreach($performanceBands as $band)
                    @php
                        $perLevelBps = (int) ($band['rate_bps_per_level'] ?? 0);
                        $cumulativeRateBps += ((int) $band['to'] - (int) $band['from'] + 1) * $perLevelBps;
                    @endphp
                    <tr class="border-b border-slate-100 last:border-0">
                        <td class="px-2 py-1.5 font-bold">+{{ $band['from'] }}〜+{{ $band['to'] }}</td>
                        <td class="px-2 py-1.5 text-right">各{{ number_format($perLevelBps / 100, 1) }}%</td>
                        <td class="px-2 py-1.5 text-right font-bold">+{{ number_format($cumulativeRateBps / 100, 1) }}%</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</section>
