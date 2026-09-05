<tr @if($row['reward_id']) id="raid-reward-{{ $row['reward_id'] }}" @endif
    @class(['scroll-mt-4 align-top', 'bg-emerald-50/40' => $row['state'] === 'claimable'])
    data-raid-reward="{{ $row['key'] }}" data-reward-state="{{ $row['state'] }}">
    <th scope="row" class="break-words py-5 pl-4 pr-2 font-normal sm:pl-5 sm:pr-4">
        <p class="text-base font-black leading-relaxed text-slate-900">{{ $row['display_label'] }}</p>
        <p @class(['mt-1 text-xs leading-relaxed text-slate-600', 'sr-only' => $row['meter'] !== null])>{{ $row['condition'] }}</p>
        @if(isset($row['payload']['choices']))<p class="mt-2 text-xs text-slate-600">次のうち一つ</p>@endif
        <ul class="mt-3 space-y-2 text-[13px] leading-relaxed text-slate-700 sm:text-sm">
            @foreach($row['items'] as $item)
                <li class="flex items-start gap-2">
                    @if($item['icon'])<img src="{{ asset($item['icon']) }}" alt="" width="24" height="24" loading="lazy" class="h-6 w-6 shrink-0 object-contain" data-raid-reward-icon>@endif
                    <span class="min-w-0 pt-0.5">{{ $item['label'] }}</span>
                </li>
            @endforeach
        </ul>
        @if($row['progress'] || $row['meter'])
        <div class="mt-4">
            <p class="text-xs leading-relaxed text-slate-600">{{ $row['progress'] }}</p>
            @if($row['meter'])
                <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-slate-100" role="progressbar"
                    aria-label="{{ $row['meter']['label'] }}" aria-valuemin="0" aria-valuemax="{{ $row['meter']['max'] }}" aria-valuenow="{{ $row['meter']['value'] }}">
                    <div class="h-full rounded-full bg-sky-600" style="width: {{ $row['meter']['percent'] }}%"></div>
                </div>
            @endif
        </div>
        @endif
        @if($row['state'] === 'claimable')
            <form id="raid-claim-{{ $row['reward_id'] }}" method="POST" action="{{ route('nation-raid.rewards.claim', ['event' => $event, 'reward' => $row['reward_id']]) }}">
                @csrf
                @if(isset($row['payload']['choices']))
                    <label class="mt-3 block text-xs font-bold">戦利品を選ぶ
                        <select name="selection" required class="mt-1 min-h-11 w-full min-w-0 max-w-full rounded-lg border-slate-300 text-xs font-normal">
                            <option value="">どれか一つを選択</option>
                            @foreach($row['payload']['choices'] as $key => $choice)<option value="{{ $key }}">{{ $choice['label'] }}</option>@endforeach
                        </select>
                    </label>
                @endif
            </form>
        @elseif($row['selected_label'])
            <p class="mt-2 text-xs text-slate-600">受取：{{ $row['selected_label'] }}</p>
        @endif
    </th>
    <td class="px-2 py-5 text-center align-middle sm:px-4">
        <p @class(['inline-block whitespace-nowrap rounded-full px-2 py-1 text-[11px] font-bold sm:text-xs',
            'bg-emerald-100 text-emerald-800' => $row['state'] === 'claimable',
            'bg-sky-50 text-sky-800' => $row['state'] === 'awaiting',
            'bg-slate-100 text-slate-600' => ! in_array($row['state'], ['claimable', 'awaiting'], true)])>{{ $row['status_label'] }}</p>
        @if($row['state'] === 'claimable')
            <button type="submit" form="raid-claim-{{ $row['reward_id'] }}" class="mt-3 min-h-11 w-full rounded-lg bg-emerald-700 px-2 py-2 text-sm font-bold text-white hover:bg-emerald-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-700" aria-label="{{ $row['label'] }}を入手" data-raid-claim-button>入手</button>
        @elseif($row['state'] === 'claimed' && $row['claimed_at'])
            <p class="mt-2 break-words text-[11px] leading-relaxed text-slate-500">{{ $row['claimed_at'] }}</p>
        @endif
    </td>
</tr>
