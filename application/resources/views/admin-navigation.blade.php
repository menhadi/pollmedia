<aside id="admin-sidebar" class="admin-sidebar"><a class="admin-brand" href="{{ route('admin.dashboard') }}"><span class="admin-mark">P</span><span>Pollmedia<small>DATA & PUBLICATION</small></span></a><nav aria-label="Administration">
@foreach([
['Overview','admin.dashboard','admin.dashboard'],
['Data imports','imports.index','imports.*'],
['Election archives','election-imports.index','election-imports.*'],
['Election batches','election-batches.index','election-batches.*'],
['Census archives','census.archive','census.*'],
['National Census publication','census-catalogue.review','census-catalogue.*'],
['Representatives & authorities','authorities.index','authorities.*'],
['Citizen issue moderation','issues.queue','issues.*'],
['Report drafts','reports.archive','reports.*'],
['PDF storage','pdf-storage.index','pdf-storage.*'],
['Page SEO','seo.index','seo.*'],
['AI settings','ai.settings','ai.*'],
['Your account','admin.account','admin.account']
] as [$label,$destination,$pattern])<a href="{{ route($destination) }}" @if(request()->routeIs($pattern)) aria-current="page" @endif><span class="nav-symbol" aria-hidden="true">{{ mb_substr($label,0,1) }}</span>{{ $label }}<span class="nav-arrow" aria-hidden="true">›</span></a>@endforeach
</nav></aside>
