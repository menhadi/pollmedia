@extends('seo-layout')
@section('content')
<h1>Hindi translations</h1><p>Edit public interface text and policy translations. Original source records remain unchanged. Blank entries use the built-in Hindi wording or English. Policy content supports Markdown.</p>
<label>Find wording<input type="search" id="translation-filter" placeholder="Search English or Hindi text"></label>
@foreach($entries as $source=>$default)<section class="card translation-entry"><form method="post" action="{{ route('translations.save') }}">@csrf<input type="hidden" name="source" value="{{ $source }}"><details><summary>{{ Str::limit($source,100) }}</summary><p style="white-space:pre-wrap">{{ $source }}</p></details><label>Hindi<textarea lang="hi" name="translation" rows="{{ strlen($source)>500?8:2 }}">{{ $saved[$source]??$default }}</textarea></label><button>Save translation</button></form></section>@endforeach
<script>document.getElementById('translation-filter').addEventListener('input',function(){document.querySelectorAll('.translation-entry').forEach(row=>row.hidden=!(row.textContent+' '+row.querySelector('textarea').value).toLowerCase().includes(this.value.toLowerCase()))})</script>
@endsection
