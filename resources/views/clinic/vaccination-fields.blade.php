<h3>Vaccinations (optional)</h3>
<div class="wide">
    <p class="helper">Record each vaccine given, its administration date, and any follow-up date.</p>
    <div @if(!($reactive ?? false)) data-repeatable="vaccinations" data-limit="20" @endif>
        @foreach(($formInput['vaccinations'] ?? [['vaccine_name'=>'','date_given'=>'','next_due_date'=>'','batch_number'=>'']]) as $i=>$vaccination)
        <div class="vaccine-row" data-repeatable-row wire:key="vaccine-{{ $i }}">
            <label>Vaccine<input name="vaccinations[{{ $i }}][vaccine_name]" @if($reactive ?? false) wire:model="data.vaccinations.{{ $i }}.vaccine_name" @endif maxlength="200" value="{{ $vaccination['vaccine_name'] ?? '' }}"></label>
            <label>Administered on<input type="date" name="vaccinations[{{ $i }}][date_given]" @if($reactive ?? false) wire:model="data.vaccinations.{{ $i }}.date_given" @endif max="{{ today()->toDateString() }}" value="{{ $vaccination['date_given'] ?? '' }}"></label>
            <label>Next due<input type="date" name="vaccinations[{{ $i }}][next_due_date]" @if($reactive ?? false) wire:model="data.vaccinations.{{ $i }}.next_due_date" @endif value="{{ $vaccination['next_due_date'] ?? '' }}"></label>
            <label>Batch / lot<input name="vaccinations[{{ $i }}][batch_number]" @if($reactive ?? false) wire:model="data.vaccinations.{{ $i }}.batch_number" @endif maxlength="100" value="{{ $vaccination['batch_number'] ?? '' }}"></label>
            <button class="secondary" type="button" @if($reactive ?? false) wire:click="removeRow('vaccinations', {{ $i }})" @else data-repeatable-remove @endif aria-label="Remove vaccination">×</button>
        </div>
        @endforeach
    </div>
    <button class="secondary small" type="button" @if($reactive ?? false) wire:click="addRow('vaccinations')" @else data-repeatable-add="vaccinations" @endif>+ Add vaccination</button>
</div>
