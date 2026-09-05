@if(($coordination['steps'][0]['active'] ?? false))
    <span data-raid-coordination-badge
        x-data="{
            steps: @js($coordination['steps']), elapsed: 0, started: 0, timer: null,
            init() {
                this.started = performance.now();
                this.timer = setInterval(() => {
                    this.elapsed = performance.now() - this.started;
                    if (this.elapsed >= this.steps[this.steps.length - 1].after_ms) clearInterval(this.timer);
                }, 1000);
            },
            destroy() { clearInterval(this.timer); },
            get current() { return this.steps.filter(step => step.after_ms <= this.elapsed).slice(-1)[0]; }
        }"
        x-show="current.active" x-text="current.label"
        class="inline-flex max-w-full rounded-md bg-sky-100 px-2 py-1 text-[11px] font-bold leading-relaxed text-sky-900"
    >{{ $coordination['steps'][0]['label'] }}</span>
@endif
