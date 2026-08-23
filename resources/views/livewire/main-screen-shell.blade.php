<div
    x-data="{ currentLocation: @js($currentLocation) }"
    @main-tab-selected.window="currentLocation = ($event.detail.location === 'job' ? 'town' : $event.detail.location)"
>
    <livewire:nav-menu />

    @foreach($cachedTabLocations as $location)
        <section
            x-cloak
            x-show="currentLocation === @js($location)"
            style="{{ $currentLocation === $location ? '' : 'display: none;' }}"
            data-main-tab-panel="{{ $location }}"
        >
            @if($location === 'nation')
                @if(config('features.nation_war_enabled', false))
                    <livewire:nation-screen :key="'main-tab-panel-nation'" />
                @else
                    @include('livewire.nation-preparation')
                @endif
            @elseif(in_array($location, $loadedTabLocations, true))
                <livewire:main-screen
                    :fixed-location="$location"
                    :key="'main-tab-panel-'.$location"
                />
            @else
                @include('livewire.main-screen-placeholder', ['placeholderLocation' => $location])
            @endif
        </section>
    @endforeach

    <section
        x-show="@js($utilityTabLocations).includes(currentLocation)"
        style="{{ in_array($currentLocation, $utilityTabLocations, true) ? '' : 'display: none;' }}"
        data-main-tab-utility
    >
        @if(in_array($currentLocation, $utilityTabLocations, true))
            <livewire:main-screen
                :fixed-location="$currentLocation"
                :key="'main-tab-utility-'.$currentLocation"
            />
        @else
            @include('livewire.main-screen-placeholder', ['placeholderLocation' => null])
        @endif
    </section>
</div>
