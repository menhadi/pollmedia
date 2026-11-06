@php($sample = in_array($post->id, ['01M5T4FFN9DYE9RE24H7P6AZ61','01K8A000000000000000000001'], true))
@php($authorName = $sample ? 'Pollmedia' : ($post->displayName ?? 'Anonymous resident'))
<div class="post-author"><span class="post-avatar" aria-hidden="true">{{ Str::upper(Str::substr($authorName,0,1)) }}</span><div><strong dir="auto">{{ $authorName }}</strong> <span class="post-author-date">· <time>{{ \Carbon\Carbon::parse($post->created_at)->format('j M') }}</time></span><p>{{ ucfirst($post->category) }} · {{ Str::headline($post->status) }}</p></div></div>
