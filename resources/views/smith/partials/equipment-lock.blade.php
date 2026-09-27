<form action="{{ route('equipment.lock', $sourceOption['id']) }}" method="POST" class="shrink-0" data-smith-lock-form data-source-item-id="{{ (int) $sourceOption['id'] }}">
    @csrf
    <input type="hidden" name="return_to_smith" value="1">
    <button type="submit" data-smith-lock-submit
        aria-pressed="{{ !empty($sourceOption['is_locked']) ? 'true' : 'false' }}"
        aria-label="{{ $sourceOption['display_name'] }}の保護を切り替える"
        class="min-h-10 rounded border border-amber-300 bg-amber-50 px-3 py-2 text-xs font-bold text-amber-900 disabled:opacity-50">
        {{ !empty($sourceOption['is_locked']) ? '★ 保護中' : '☆ 保護する' }}
    </button>
</form>
