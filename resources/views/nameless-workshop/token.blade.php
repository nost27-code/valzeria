@csrf
<input type="hidden" name="request_uuid" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
<input type="hidden" name="workshop_tab" value="{{ $tab ?? 'workshop' }}">
@include('nameless-workshop.equipment-context')
@if(isset($selectedOrdinaryEquipment) && $selectedOrdinaryEquipment)<input type="hidden" name="ordinary_context" value="{{ $selectedOrdinaryEquipment->id }}">@endif
