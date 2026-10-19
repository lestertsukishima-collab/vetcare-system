@props(['id', 'label'])
<button type="button" class="small secondary btn btn-outline-secondary btn-sm" data-bs-toggle="modal" data-bs-target="#clinic-delete-modal" data-delete-id="{{ $id }}" data-delete-label="{{ $label }}" aria-label="Delete {{ $label }}">Delete</button>
