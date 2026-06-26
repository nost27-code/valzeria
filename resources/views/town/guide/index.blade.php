<x-layouts.facility title="案内所" headerIconImage="images/icon/icon_013.webp" bgImage="images/bg-castle.webp">
    <div class="mx-auto w-full max-w-[600px] px-3 pb-6 space-y-3">
        @include('help._sections', ['helpContent' => $helpContent])
    </div>
</x-layouts.facility>
