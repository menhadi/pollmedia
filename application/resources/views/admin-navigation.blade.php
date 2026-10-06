<aside id="admin-sidebar" class="admin-sidebar">
<a class="admin-brand" href="{{ route('admin.dashboard') }}"><span class="admin-mark">P</span><span>{{ app(\App\Services\SiteSettings::class)->appearance()['name'] }}<small>ADMINISTRATION</small></span></a>
<nav aria-label="Administration">
<p class="admin-nav-caption">Manage your workspace</p>
@php
$groups = [
    ['Workspace','WS',[['Overview','admin.dashboard'],['Your account','admin.account']]],
    ['Published listings','LS',[['Election listings','listings.index','elections'],['Census listings','listings.index','census'],['SIR listings','listings.index','sir']]],
    ['Sources & imports','SI',[['Data imports','imports.index'],['Census archives','census.archive'],['Census publication','census-catalogue.review'],['Election archives','election-imports.index'],['Election batches','election-batches.index'],['Manual data corrections','site.editor'],['PDF storage','pdf-storage.index']]],
    ['Review & community','RV',[['SIR extraction review','sir.review'],['Constituency map checks','elections.maps.review'],['Data and website reports','feedback.queue'],['Citizen issues','issues.queue'],['Representatives & authorities','authorities.index'],['Report drafts','reports.archive']]],
    ['Website & SEO','SE',[['Page SEO','seo.index'],['Static pages','static-pages.index'],['Hindi translations','translations.index']]],
    ['Settings & operations','ST',[['Appearance, APIs & monitoring','site.manage'],['AI providers','ai.settings']]],
];
@endphp
@foreach($groups as [$group,$symbol,$items])
@php
$groupActive=collect($items)->contains(fn($item)=>request()->routeIs($item[1]) || ($item[1]==='seo.index' && request()->is('admin/seo/*')));
@endphp
<details class="admin-nav-group" @if($groupActive || in_array($group,['Workspace','Published listings'])) open @endif>
<summary><span class="admin-nav-icon" aria-hidden="true">{{ $symbol }}</span><span>{{ $group }}</span><span class="admin-nav-chevron" aria-hidden="true">›</span></summary>
<div class="admin-nav-submenu">
@foreach($items as $item)
@php
[$label,$destination]=$item;
$section=$item[2]??null;
$active=request()->routeIs($destination) && (!$section || request()->route('section')===$section);
@endphp
<a href="{{ route($destination,$section?['section'=>$section]:[]) }}" @if($active) aria-current="page" @endif><span class="admin-nav-dot" aria-hidden="true"></span><span>{{ $label }}</span>@if($active)<span class="admin-current-marker" aria-hidden="true">●</span>@endif</a>
@if($section==='elections')<div class="admin-nav-nested"><a href="{{ route('listings.index',['section'=>'elections','kind'=>'pc']) }}" @if($active && request('kind','pc')==='pc') aria-current="page" @endif>Lok Sabha · PC</a><a href="{{ route('listings.index',['section'=>'elections','kind'=>'ac']) }}" @if($active && request('kind')==='ac') aria-current="page" @endif>Assembly · AC</a></div>@endif
@endforeach
</div></details>
@endforeach
</nav><div class="admin-sidebar-footer"><span class="admin-status-dot" aria-hidden="true"></span> Admin workspace <small>Data, evidence & publishing</small></div>
</aside>
