<div
    x-data="{
        currentLocation: @js($currentLocation),
        preloadPaused: false,
        preloadStarted: false,
        waitForPreload(ms) {
            return new Promise(resolve => window.setTimeout(resolve, ms));
        },
        async preloadCachedTabs() {
            if (this.preloadStarted) return;
            this.preloadStarted = true;

            await new Promise(resolve => {
                if ('requestIdleCallback' in window) {
                    window.requestIdleCallback(resolve, { timeout: 1500 });
                    return;
                }

                window.setTimeout(resolve, 800);
            });

            for (const location of @js(array_values(array_diff($cachedTabLocations, $loadedTabLocations)))) {
                while (this.preloadPaused) {
                    await this.waitForPreload(150);
                }

                await this.$wire.preloadCachedTab(location);
                await this.waitForPreload(150);
            }
        },
    }"
    x-init="preloadCachedTabs()"
    @main-tab-selected.window="currentLocation = ($event.detail.location === 'job' ? 'town' : $event.detail.location)"
    @adventurer-card-loading.window="preloadPaused = true"
    @adventurer-card-loaded.window="preloadPaused = false"
>
    <livewire:nav-menu />

    @foreach($cachedTabLocations as $location)
        <section
            x-cloak
            x-show="currentLocation === @js($location)"
            style="{{ $currentLocation === $location ? '' : 'display: none;' }}"
            data-main-tab-panel="{{ $location }}"
        >
            @if(in_array($location, $loadedTabLocations, true))
                <livewire:main-screen
                    :fixed-location="$location"
                    :key="'main-tab-panel-'.$location"
                />
            @else
                @include('livewire.main-screen-placeholder')
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
            @include('livewire.main-screen-placeholder')
        @endif
    </section>
</div>
