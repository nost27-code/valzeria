@foreach(($equipmentFilterQuery ?? []) as $key => $value)
    <input type="hidden" name="{{ $key }}" value="{{ $value }}">
@endforeach
