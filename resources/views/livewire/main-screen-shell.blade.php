<div
    x-data="{ currentLocation: @js($currentLocation) }"
    @main-tab-selected.window="currentLocation = ($event.detail.location === 'job' ? 'town' : $event.detail.location)"
>
    <livewire:nav-menu />

    @foreach($tabLocations as $location)
        <section
            x-cloak
            x-show="currentLocation === @js($location)"
            style="{{ $currentLocation === $location ? '' : 'display: none;' }}"
            data-main-tab-panel="{{ $location }}"
        >
            @if($initialLocation === $location)
                <livewire:main-screen
                    :fixed-location="$location"
                    :key="'main-tab-panel-'.$location"
                />
            @else
                <livewire:main-screen
                    :fixed-location="$location"
                    :key="'main-tab-panel-'.$location"
                    lazy
                />
            @endif
        </section>
    @endforeach
</div>
