<div class="history-prescription">
    <h4><x-clinic.icon name="prescriptions" />Prescription #{{ $record->prescription->id }}</h4>
    <p>{{ $record->prescription->instructions ?: 'Follow the recorded dosage instructions.' }}</p>
    <div class="scroll"><table><thead><tr><th>Medicine</th><th>Prescribed</th><th>Supplied by clinic</th><th>Dosage / instructions</th></tr></thead><tbody>
        @foreach($record->prescription->items as $item)<tr><td>{{ $item->medicine_name ?? $item->medicine->name }}</td><td>{{ $item->quantity }} {{ $item->medicine_unit ?? $item->medicine->unit }}</td><td>{{ $item->dispensed_quantity > 0 ? $item->dispensed_quantity : 'Not dispensed' }}</td><td class="wrap">{{ $item->dosage }}</td></tr>@endforeach
    </tbody></table></div>
</div>
