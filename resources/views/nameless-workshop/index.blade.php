<x-layouts.facility title="名もなき鍛冶屋" subtitle="拾う・組む・育てる" :compact-header="true" main-content-class="py-2" :exit-url="route('nameless-workshop.return', ['tab' => 'town'])" exit-label="街へ戻る">
    @include('nameless-workshop.style')
    <div class="nw" x-data="{ relicDescriptions: @js($relicDescriptions) }">
        <nav aria-label="名もなき鍛冶屋メニュー">
            @foreach(['workshop' => '武具強化する', 'sets' => '武具に遺物をセットする'] as $key => $label)
                <a class="button {{ $tab === $key ? '' : 'secondary' }}" href="{{ route('nameless-workshop.index', $equipmentFilterQuery + ['tab' => $key] + ($key === 'sets' && $selectedOrdinaryEquipment ? ['character_item' => $selectedOrdinaryEquipment->id] : []) + ($selectedEquipment ? ['equipment' => $selectedEquipment->id] : [])) }}" @if($tab === $key) aria-current="page" @endif>{{ $label }}</a>
            @endforeach
        </nav>
        @if(session('status'))<div class="card notice" role="status">{{ session('status') }}</div>@endif
        @if(session('error'))<div class="card error" role="alert">{{ session('error') }}</div>@endif
        @if($errors->any())<div class="card error" role="alert">{{ $errors->first() }}</div>@endif
        @include('nameless-workshop.workshop')
    </div>
</x-layouts.facility>
