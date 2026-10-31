@if ($paginator->hasPages())
    <nav class="pagination nasbia-pagination" role="navigation" aria-label="تنقل الصفحات" dir="rtl">
        @if ($paginator->onFirstPage())
            <span class="pagination-button is-disabled" aria-disabled="true">السابق</span>
        @else
            <a class="pagination-button" href="{{ $paginator->previousPageUrl() }}" rel="prev">السابق</a>
        @endif

        <span class="pagination-summary">صفحة {{ $paginator->currentPage() }}</span>

        @if ($paginator->hasMorePages())
            <a class="pagination-button" href="{{ $paginator->nextPageUrl() }}" rel="next">التالي</a>
        @else
            <span class="pagination-button is-disabled" aria-disabled="true">التالي</span>
        @endif
    </nav>
@endif
