@props(['id', 'label'])
<button type="button" class="small secondary" wire:click="deleteRecord({{ $id }})" wire:confirm="Delete {{ $label }} permanently? This cannot be undone. Records linked to clinic history will be retained." wire:loading.attr="disabled" wire:target="deleteRecord" aria-label="Delete {{ $label }}">Delete</button>
