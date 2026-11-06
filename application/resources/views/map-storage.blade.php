@extends('seo-layout')
@section('content')
<h1>Storage &amp; archives</h1>
<p>See what uses server space and move eligible files to a private R2/S3 bucket or back. Village IDs, source IDs, checksums, polygons and existing page links remain unchanged.</p>
<div class="dashboard-grid">
<section class="card"><h2>On this server</h2><p>{{ number_format($localBytes / 1048576, 1) }} MB registered</p></section>
<section class="card"><h2>In R2 / S3</h2><p>{{ number_format($cloudBytes / 1048576, 1) }} MB registered</p></section>
<section class="card"><h2>Server space remaining</h2><p>{{ number_format($freeBytes / 1073741824, 2) }} GB</p></section>
</div>
<section class="card"><h2>Server disk usage</h2>
<form method="post" action="{{ route('map-storage.scan') }}">@csrf<button>Refresh disk audit and file inventory</button></form>
@if($audit)<p class="muted">Checked {{ $audit['checked_at'] }}. Disk: {{ number_format(($audit['total_bytes'] - $audit['free_bytes']) / 1073741824, 1) }} GB used of {{ number_format($audit['total_bytes'] / 1073741824, 1) }} GB.</p>
<div style="overflow-x:auto"><table style="width:100%;text-align:left"><thead><tr><th>Category</th><th>Local size</th><th>Move policy</th></tr></thead><tbody>@foreach($audit['rows'] as $row)<tr><td>{{ $row['label'] }}</td><td>{{ number_format($row['bytes']/1073741824, 2) }} GB{{ $row['complete'] ? '' : ' (partial)' }}</td><td>{{ $row['note'] }}</td></tr>@endforeach</tbody></table></div>
<p class="muted">Category sizes measure file contents in selected Pollmedia folders; they do not add up to whole-server disk usage. Other accounts, system files and filesystem overhead are outside this inventory. A refresh only measures files.</p>
@else<p>Run the audit to see the breakdown.</p>@endif
<p><strong>Protected:</strong> application code, credentials, active databases, original polygons, indexed Census tables, sessions, caches and logs cannot be moved with these controls. Worker and temporary folders require separate review.</p></section>
<section class="card"><h2>Eligible file categories</h2>
@foreach($categories as $key => $category)<div class="row"><strong><a href="{{ route('map-storage.index',['category'=>$key]) }}">{{ $category['label'] }}</a></strong><p>{{ number_format($category['count']) }} registered files · Server {{ number_format($category['local']/1048576,1) }} MB · Cloud {{ number_format($category['cloud']/1048576,1) }} MB</p></div>@endforeach
<p><a href="{{ route('pdf-storage.index') }}">Manage archived PDFs →</a> — uses the same encrypted bucket connections and existing document links.</p>
<details><summary><strong>Move a whole registered category</strong></summary><form method="post" action="{{ route('map-storage.category-move') }}">@csrf
<div class="field"><label for="category-bulk">Category</label><select name="category" id="category-bulk">@foreach($categories as $key=>$category)<option value="{{ $key }}">{{ $category['label'] }} ({{ number_format($category['count']) }} files total)</option>@endforeach</select></div>
<div class="field"><label for="category-location">Current location</label><select name="location" id="category-location"><option value="local">Files currently on this server</option><option value="cloud">Files currently in cloud buckets</option></select></div>
<div class="field"><label for="category-target">Move to</label><select name="target" id="category-target"><option value="0">This server</option>@foreach($profiles as $profile)<option value="{{ $profile->id }}" @disabled(!$profile->tested_at)>{{ $profile->name }}{{ $profile->tested_at ? '' : ' — test first' }}</option>@endforeach</select></div>
<p>Files already at the destination are skipped. New files registered after submission are excluded. Moves run one file at a time; failed files retain their original.</p>
<label><input type="checkbox" name="confirm_move" value="1" required>Move the eligible files in this category and remove each verified original.</label><button>Queue category move</button></form></details>
@if(\Illuminate\Support\Facades\Cache::get('map-category-status'))<p role="status">{{ \Illuminate\Support\Facades\Cache::get('map-category-status') }}</p>@endif</section>
<section class="card"><h2>Where should new satellite files be saved?</h2>
<form method="post" action="{{ route('map-storage.save') }}">@csrf
<label for="new-target">New satellite publications</label><select id="new-target" name="target"><option value="0">This server</option>@foreach($profiles as $profile)<option value="{{ $profile->id }}" @selected($policy['profile_id']===$profile->id) @disabled(!$profile->tested_at)>{{ $profile->name }} ({{ strtoupper($profile->provider) }}){{ $profile->tested_at ? '' : ' — test first' }}</option>@endforeach</select>
<div class="field"><label for="delivery">How should cloud images reach visitors?</label><select id="delivery" name="delivery"><option value="stream" @selected($policy['delivery']==='stream')>Stream from the bucket through this website</option><option value="direct" @selected($policy['delivery']==='direct')>Direct from R2/S3 using temporary image links</option></select><p class="muted">Streaming does not save a local image copy. Direct delivery reduces server traffic and requires bucket CORS settings. Comparison JSON always uses the website URL.</p><label><input type="checkbox" name="cors_confirmed" value="1">For direct delivery, I have enabled browser GET/HEAD requests from {{ config('app.url') }} in the bucket CORS policy.</label></div>
<button>Save storage preference</button><p class="muted">This applies to new publications after processing. A small temporary upload may pass through the server. Existing files move only when selected below. This setting does not restart the paused national uploader.</p></form></section>
<details class="card"><summary><strong>R2 / S3 credentials and connections</strong></summary><p>These encrypted bucket profiles are shared with PDF storage. Credentials are never shown back. A bucket connection must pass read, write and delete checks before use.</p>@include('map-storage-profiles')
<p><a href="https://developers.cloudflare.com/r2/buckets/cors/" target="_blank" rel="noopener">Cloudflare R2 CORS setup</a>. For direct image delivery allow this website's origin, GET/HEAD methods and required request headers. Keep the bucket private; image links expire after ten minutes.</p></details>
<section class="card"><h2>Move existing files</h2>
<form method="post" action="{{ route('map-storage.scan') }}">@csrf<button class="secondary">Find eligible files</button></form>
@if(\Illuminate\Support\Facades\Cache::get('map-inventory-status'))<p role="status">{{ \Illuminate\Support\Facades\Cache::get('map-inventory-status') }}</p>@endif
<p><a href="{{ route('map-storage.index') }}">All locations</a> · <a href="{{ route('map-storage.index', ['location'=>'local']) }}">Server only</a> · <a href="{{ route('map-storage.index', ['location'=>'cloud']) }}">R2 / S3 only</a></p>
<form method="post" action="{{ route('map-storage.move') }}">@csrf
<label for="move-target">Move selected files to</label><select id="move-target" name="target"><option value="0">This server</option>@foreach($profiles as $profile)<option value="{{ $profile->id }}" @disabled(!$profile->tested_at)>{{ $profile->name }} ({{ strtoupper($profile->provider) }}){{ $profile->tested_at ? '' : ' — test first' }}</option>@endforeach</select>
<p>Destination size and SHA-256 are verified before the active location changes. Then the old file is removed. A failed deletion appears as <strong>cleanup pending</strong>, never as a completed move. Moving back requires enough space plus a 1 GB reserve.</p>
<label><input type="checkbox" id="select-assets">Select all files on this page</label>
@forelse($files as $file)<div class="row"><label><input type="checkbox" name="files[]" value="{{ $file->id }}"><span class="url">{{ $file->path }}</span></label><a href="{{ route('map-storage.download', $file->id) }}">Download original</a><p class="muted">#{{ $file->id }} · {{ number_format($file->bytes / 1048576, 2) }} MB · {{ $file->profile_id ? ($profiles->firstWhere('id',$file->profile_id)?->name ?? 'Cloud bucket') : 'This server' }}</p></div>@empty<p>No files registered yet. Use “Find eligible files”.</p>@endforelse
<button @disabled($files->isEmpty())>Move selected files and remove verified originals</button></form>{{ $files->links() }}
<p class="muted">Inventory covers satellite assets, JJM source snapshots and soil archive packages. Source archives stay private and are downloadable here by administrators. Map geometry, active databases, indexed tables and temporary upload packages are excluded. Retained cleanup copies and staging packages are not included in the active-location totals.</p></section>
<section class="card"><h2>Move progress</h2><p>Refresh to see progress. To retry a failed move or pending cleanup, select its file again and submit Move.</p>@forelse($transfers as $transfer)<div class="row">Move #{{ $transfer->id }} · File #{{ $transfer->file_id }} · <strong>{{ str_replace('_',' ',$transfer->status) }}</strong><p>{{ $transfer->message }}</p></div>@empty<p>No moves requested.</p>@endforelse</section>
<script>document.getElementById('select-assets').addEventListener('change',event=>document.querySelectorAll('input[name="files[]"]').forEach(box=>box.checked=event.target.checked));</script>
@endsection
