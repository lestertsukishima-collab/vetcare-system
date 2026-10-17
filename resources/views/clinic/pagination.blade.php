@if($paginator->hasPages())
<div class="pagination-bar"><span>Showing {{ $paginator->firstItem() }}–{{ $paginator->lastItem() }} of {{ $paginator->total() }}</span><div><button class="small secondary" wire:click="previousPage" @disabled($paginator->onFirstPage())>← Previous</button><span>{{ $paginator->currentPage() }} / {{ $paginator->lastPage() }}</span><button class="small secondary" wire:click="nextPage" @disabled(!$paginator->hasMorePages())>Next →</button></div></div>
@endif
