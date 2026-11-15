@if($detailPage['total']>50)
<nav class="gp-pagination" aria-label="{{ $detailParameter==='voucherPage'?'Voucher pages':'Activity pages' }}">
@if($detailPage['page']>1)<a rel="prev" href="{{ request()->fullUrlWithQuery([$detailParameter=>$detailPage['page']-1]) }}#{{ $detailAnchor }}">Previous</a>@endif
<span>Page {{ $detailPage['page'] }} · {{ number_format($detailPage['total']) }} records</span>
@if($detailPage['more'])<a rel="next" href="{{ request()->fullUrlWithQuery([$detailParameter=>$detailPage['page']+1]) }}#{{ $detailAnchor }}">Next</a>@endif
</nav>
@endif
