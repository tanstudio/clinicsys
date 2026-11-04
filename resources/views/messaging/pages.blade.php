@if($page->hasPages())<nav aria-label="頁碼">
@if($page->previousPageUrl())<a href="{{ $page->previousPageUrl() }}">上一頁</a>@endif
{{ $page->currentPage() }} / {{ $page->lastPage() }}
@if($page->nextPageUrl())<a href="{{ $page->nextPageUrl() }}">下一頁</a>@endif
</nav>@endif
