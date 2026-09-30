@extends('seo-layout')
@section('content')
<h1>Static pages</h1><p>Published pages automatically appear in the website footer. Drafts remain private.</p>
<div class="page-editor-layout"><section class="card"><h2>Your pages</h2><a href="{{ route('static-pages.index') }}">+ Create a page</a>@foreach(collect($all)->sortBy('order') as $key=>$item)<div class="row"><a href="{{ route('static-pages.index',['edit'=>$key]) }}">{{ $item['title'] }}</a><small class="muted">{{ $item['published']?'Published':'Draft' }}</small></div>@endforeach</section>
<section class="card"><h2>{{ $page?'Edit page':'New page' }}</h2><form method="post" action="{{ route('static-pages.save') }}">@csrf<input type="hidden" name="expected" value="{{ hash('sha256',json_encode($page)) }}">
<label>Title<input name="title" value="{{ old('title',$page['title']??'') }}" required maxlength="160"></label>
<label>URL name<input name="slug" value="{{ old('slug',$slug) }}" pattern="[a-z0-9]+(-[a-z0-9]+)*" required maxlength="100" @readonly($page)></label><p class="muted">Lowercase words separated by hyphens. Published at /pages/your-url-name.</p>
<label>Short introduction<textarea name="summary" rows="2" required maxlength="320">{{ old('summary',$page['summary']??'') }}</textarea></label>
<label>Page content<textarea name="content" rows="20" required maxlength="50000">{{ old('content',$page['content']??'') }}</textarea></label><p class="muted">Markdown supported: ## Heading, **bold**, and [link text](https://example.com). HTML is removed.</p>
<div class="grid"><label>Visibility<select name="published"><option value="0" @selected(!old('published',$page['published']??false))>Draft — hidden</option><option value="1" @selected(old('published',$page['published']??false))>Published — show in footer</option></select></label><label>Footer order<input name="order" type="number" min="0" max="9999" value="{{ old('order',$page['order']??100) }}" required></label></div>
<button>Save page</button>@if($page && $page['published']) <a href="{{ route('static-pages.show',$slug) }}">View public page ↗</a>@endif
</form></section></div>
@endsection
