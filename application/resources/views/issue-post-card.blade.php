@php($sample = in_array($post->id, ['01M5T4FFN9DYE9RE24H7P6AZ61','01K8A000000000000000000001'], true))
<article class="issue-post" data-issue-id="{{ $post->id }}">
@include('issue-post-author')
<div class="post-places" aria-label="Problem places">@foreach($post->placeLinks as $link)<a href="{{ $link['url'] }}">#{{ ucfirst($link['kind']) }}_{{ preg_replace('/\s+/u', '', $link['name']) }}</a>@endforeach</div>
<h3><a href="{{ route('issues.show',$post->id) }}" dir="auto">{{ $post->id === '01M5T4FFN9DYE9RE24H7P6AZ61' ? 'Repair the damaged village road' : $post->title }}</a></h3>

<p class="post-description" dir="auto">{{ $post->id === '01M5T4FFN9DYE9RE24H7P6AZ61' ? 'The damaged road makes daily travel difficult, especially for children and older residents. Please repair the surface and improve drainage before the next rains.' : Str::limit($post->description,320) }}</p>
@if($post->id === '01M5T4FFN9DYE9RE24H7P6AZ61' && is_file(public_path('media/road-preview.mp4')))<div class="post-media" data-count="1"><video controls muted playsinline preload="metadata" aria-label="Road improvement animation" src="{{ asset('media/road-preview.mp4') }}"></video></div>
@elseif($post->media->isNotEmpty())<div class="post-media" data-count="{{ $post->media->count() }}">
@foreach($post->media as $media)
@if(str_starts_with($media->mime,'image/'))<a href="{{ route('issues.media',[$post->id,$media->id]) }}" target="_blank" rel="noopener" aria-label="Open full photo"><img src="{{ route('issues.media',[$post->id,$media->id]) }}" alt="{{ $sample ? 'Illustrative generated sample, not location evidence' : 'Photo supplied with this report' }}" loading="lazy"></a>
@else<video controls preload="metadata" aria-label="Video supplied with this report" src="{{ route('issues.media',[$post->id,$media->id]) }}"></video>@endif
@endforeach
</div>@endif
@include('issue-post-actions')

<details class="post-disclaimer"><summary>About this report</summary><p>Endorse an existing report instead of posting the same problem again. Reactions are counted once per browser session.</p>@if($sample)<p>Layout test only, not a real complaint. Media is illustrative and does not depict or document this location.</p>@endif<p>Citizen reports and listed contacts are not confirmation of responsibility. Publication and reactions do not send a government complaint.</p></details>
</article>
