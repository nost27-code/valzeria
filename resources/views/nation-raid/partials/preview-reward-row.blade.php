<tr data-raid-preview-reward="{{ $row['key'] }}">
    <th scope="row" class="px-4 py-5 text-left align-top font-normal sm:px-5">
        <h3 class="font-bold text-slate-900">{{ $row['display_label'] }}</h3>
        <ul class="mt-3 space-y-2 text-xs text-slate-700 sm:text-sm">
            @foreach($row['items'] as $item)
                <li class="flex items-center gap-2">
                    <img src="{{ asset($item['icon']) }}" alt="" width="24" height="24" loading="lazy" class="h-6 w-6 shrink-0 object-contain">
                    <span class="min-w-0 break-words">{{ $item['label'] }}</span>
                </li>
            @endforeach
        </ul>
        <p class="mt-3 text-xs leading-relaxed text-slate-500">{{ $row['condition'] }}</p>
    </th>
    <td class="px-2 py-5 text-center align-middle sm:px-4">
        <span class="inline-block whitespace-nowrap rounded-full bg-slate-100 px-2 py-1 text-xs font-bold text-slate-600">開催前</span>
    </td>
</tr>
