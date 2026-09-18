@extends('geography-layout')
@section('title', $edition['state'].' '.$edition['label'].' official reports')
@section('content')
<h1>{{ $edition['state'] }} &middot; {{ $edition['label'] }}</h1>
<p><a href="{{ $edition['url'] }}">Official ECI report edition</a> &middot; <a href="{{ route('elections.assembly-sources') }}">All Assembly source editions</a></p>
<p>Original archived source files, with SHA-256 checksums for later comparison. Historical boundaries and source notes belong to this edition.</p>
@if($collection['has_extraction'] ?? false)<p><a href="{{ route('elections.assembly', ['edition' => $archive]) }}">Browse extracted results</a></p>@endif
<section class="card"><h2>{{ count($collection['files']) }} archived files</h2>
@forelse($collection['files'] as $file)<article><h3><a href="{{ route('elections.assembly-source-files', ['archive' => $archive, 'file' => $file['file']]) }}">{{ $file['name'] }}</a></h3><p><a href="{{ $file['source_url'] ?? $file['source_page'] ?? $edition['url'] }}">Official reference</a></p><details><summary>File verification</summary><code style="overflow-wrap:anywhere">{{ $file['sha256'] }}</code></details></article>@empty<p>No source files are archived on this installation yet. The official reference remains available above.</p>@endforelse
</section><p>These ECI statistical reports are research references. Returning Officers' statutory forms remain the final authority, as stated in the official reports. A source download is not a claim that every result has been extracted or reconciled.</p>
@endsection
