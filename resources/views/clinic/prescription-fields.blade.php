<h3>Prescription & dispensing</h3>
<x-clinic.field name="instructions" label="General prescription instructions" type="textarea" maxlength="5000" />
<div class="wide">
    <p class="helper">Choose whether each medicine is being dispensed now or prescribed for purchase elsewhere. Only dispensed medicines reduce stock and add invoice charges.</p>
    <div @if(!($reactive ?? false)) data-prescription-items @endif>
    @foreach(($formInput['items'] ?? [['medicine_id'=>'','quantity'=>'','dosage'=>'','dispense_now'=>1]]) as $i=>$item)
        <div class="rx-row" data-prescription-row wire:key="rx-{{ $i }}">
            <label>Medicine<select class="form-select" name="items[{{ $i }}][medicine_id]" @if($reactive ?? false) wire:model="data.items.{{ $i }}.medicine_id" @endif>
                <option value="">No medicine</option>
                @foreach($medicines->where('active',true) as $m)
                <option value="{{ $m->id }}" @selected(($item['medicine_id'] ?? '') == $m->id)>{{ $m->name }} · {{ $m->quantity }} {{ $m->unit }} · ₱{{ number_format($m->unit_price,2) }}{{ $m->expires_at->lt(today()) || $m->quantity < 1 ? ' · prescription only' : '' }}</option>
                @endforeach
            </select></label>
            <label>Quantity prescribed<input class="form-control" type="number" name="items[{{ $i }}][quantity]" @if($reactive ?? false) wire:model="data.items.{{ $i }}.quantity" @endif min="1" max="1000000" value="{{ $item['quantity'] ?? '' }}"></label>
            <label>Dosage & duration<input class="form-control" name="items[{{ $i }}][dosage]" @if($reactive ?? false) wire:model="data.items.{{ $i }}.dosage" @endif maxlength="255" value="{{ $item['dosage'] ?? '' }}" placeholder="As clinically prescribed"></label>
            <label>Supply<select class="form-select" name="items[{{ $i }}][dispense_now]" @if($reactive ?? false) wire:model="data.items.{{ $i }}.dispense_now" @endif><option value="1" @selected(($item['dispense_now'] ?? 1) == 1)>Dispense now</option><option value="0" @selected(($item['dispense_now'] ?? 1) == 0)>Prescription only</option></select></label>
            <button class="secondary" type="button" @if($reactive ?? false) wire:click="removeRow('items', {{ $i }})" @else data-remove-item @endif aria-label="Remove medicine">×</button>
        </div>
    @endforeach
    </div>
    <button class="secondary small" type="button" @if($reactive ?? false) wire:click="addRow('items')" @else data-add-item @endif>+ Add medicine</button>
</div>
