@php
$locationRow = \Illuminate\Support\Facades\DB::table('citizen_issue_locations')->where('issue_id', $record->id)->first();
$locationSnapshot = $locationRow ? json_decode($locationRow->snapshot, true) : null;
$issueMedia = \Illuminate\Support\Facades\DB::table('citizen_issue_media')->where('issue_id', $record->id)->get();
@endphp
@if($locationSnapshot)
<section class="card"><h2>Reported from</h2>
@if(!empty($locationSnapshot['reported_from']))<p>{{ $locationSnapshot['reported_from']['name'] }} · {{ collect($locationSnapshot['reported_from']['scopes'])->whereIn('kind', ['district', 'state'])->pluck('name')->join(' · ') }}</p><p>Device location confirmed by the reporter; not independently verified.</p>
@else<p>Reporting location was not available or was not shared.</p>@endif
<h2>Problem location</h2><p>Selected or confirmed by the reporter. Photos and videos belong to this problem location. This report appears in these area feeds:</p>
@foreach($locationSnapshot['scopes'] as $scope)<p><a href="{{ route('issues.index', ['area' => $scope['key']]) }}">{{ strtoupper($scope['kind']) }} · {{ $scope['name'] }}</a></p>@endforeach
@foreach($locationSnapshot['notes'] as $note)<p>{{ $note }}</p>@endforeach
</section>
@endif
@if($issueMedia->isNotEmpty())<section class="card"><h2>Reporter’s photos and videos</h2>@foreach($issueMedia as $media)
@if(str_starts_with($media->mime, 'image/'))<img src="{{ route('issues.media', [$record->id, $media->id]) }}" alt="Photo supplied by the reporter" loading="lazy" style="max-width:100%;max-height:500px">@else<video controls preload="metadata" style="max-width:100%;max-height:500px" src="{{ route('issues.media', [$record->id, $media->id]) }}"></video>@endif
@endforeach</section>@endif
@if(!$admin)<section class="card"><h2>Submit to the government</h2><p>Copy the complaint below and submit it on the appropriate official portal. Pollmedia publication alone does not lodge a government grievance. Return here to add the official reference and updates using “Official update”. References stay private.</p>
<textarea readonly rows="6" aria-label="Complaint text for government portal" dir="auto">{{ $record->title }}
{{ $record->description }}
@foreach($locationSnapshot['scopes'] ?? [] as $scope){{ strtoupper($scope['kind']) }}: {{ $scope['name'] }}
@endforeach
Public report: {{ route('issues.show', $record->id) }}</textarea>
<p><a href="https://pgportal.gov.in/" target="_blank" rel="noopener noreferrer">Open CPGRAMS</a> · <a href="https://jansunwai.up.nic.in/" target="_blank" rel="noopener noreferrer">UP Jansunwai (Uttar Pradesh)</a></p><p>Government submission and updates are not automatically connected yet. Government disposal and local resolution are tracked separately.</p></section>@endif
