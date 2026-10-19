@if($paginator->hasPages())
<nav class="pagination-bar" aria-label="{{ $label ?? 'Pagination' }}">
    <span role="status">Showing {{ $paginator->firstItem() }}–{{ $paginator->lastItem() }} of {{ $paginator->total() }}</span>
    <div>
        <button type="button" class="small secondary btn btn-outline-secondary btn-sm" wire:click="previousPage('{{ $paginator->getPageName() }}')" wire:loading.attr="disabled" @disabled($paginator->onFirstPage())>← Previous</button>
        <span>{{ $paginator->currentPage() }} / {{ $paginator->lastPage() }}</span>
        <button type="button" class="small secondary btn btn-outline-secondary btn-sm" wire:click="nextPage('{{ $paginator->getPageName() }}')" wire:loading.attr="disabled" @disabled(!$paginator->hasMorePages())>Next →</button>
    </div>
</nav>
@endif
