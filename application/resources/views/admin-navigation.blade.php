<aside id="admin-sidebar" class="admin-sidebar"><a class="admin-brand" href="{{ route('admin.dashboard') }}"><span class="admin-mark">P</span><span>{{ app(\App\Services\SiteSettings::class)->appearance()['name'] }}<small>CIVIC DATA & PUBLICATION</small></span></a><nav aria-label="Administration">
@foreach([
'Workspace'=>[['Overview','admin.dashboard'],['Your account','admin.account']],
'Data & evidence'=>[['Data imports','imports.index'],['Census archives','census.archive'],['Census publication','census-catalogue.review'],['Election archives','election-imports.index'],['Election batches','election-batches.index'],['Manual data corrections','site.editor'],['PDF storage','pdf-storage.index']],
'Review & community'=>[['Constituency map checks','elections.maps.review'],['Data and website reports','feedback.queue'],['Citizen issues','issues.queue'],['Representatives & authorities','authorities.index'],['Report drafts','reports.archive']],
'Website & operations'=>[['Hindi translations','translations.index'],['Static pages','static-pages.index'],['Appearance, APIs & monitoring','site.manage'],['Page SEO','seo.index'],['AI providers','ai.settings']]
] as $group=>$items)<h2>{{ $group }}</h2>@foreach($items as [$label,$destination])<a href="{{ route($destination) }}" @if(request()->routeIs($destination)) aria-current="page" @endif>{{ $label }}</a>@endforeach @endforeach
<h2>Published listings</h2>@foreach(['elections'=>'Election listings','census'=>'Census listings','sir'=>'SIR listings'] as $section=>$label)<a href="{{ route('listings.index',['section'=>$section]) }}" @if(request()->routeIs('listings.index') && request()->route('section')===$section) aria-current="page" @endif>{{ $label }}</a>@endforeach
</nav></aside>
