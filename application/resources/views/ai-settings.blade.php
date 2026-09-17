@extends('seo-layout')
@section('content')
<a href="{{ route('seo.index') }}">Back to SEO editor</a><h1>AI provider settings</h1>
<p>Configure any provider now or later, then select it when creating an AI draft. Saving settings makes no API call.</p>
<p class="notice">API keys entered here are encrypted in the database and never shown again. Leave the key field empty to keep the current key. Server environment settings are used when no saved override exists.</p>
@foreach($providers as $id=>$provider)
<section class="card"><h2>{{ $provider['label'] }}</h2><p class="muted">{{ $provider['has_key'] ? 'API key configured' : 'API key not configured' }} / {{ $provider['configured'] ? 'Configuration complete; API access not verified' : 'Setup needed' }}</p>
<form method="post" action="{{ route('ai.settings.save', $id) }}">@csrf
<div class="field"><label for="model-{{ $id }}">{{ $provider['label'] }} model ID</label><input id="model-{{ $id }}" type="text" name="model" value="{{ $provider['model'] }}" maxlength="120" required placeholder="Enter a model ID available to your API account"><small class="muted">Choose a text model that supports {{ $id==='deepseek' ? 'JSON output' : 'structured JSON output' }}.</small></div>
<div class="field"><label for="key-{{ $id }}">{{ $provider['label'] }} API key</label><input style="width:100%" id="key-{{ $id }}" name="api_key" type="password" autocomplete="new-password" maxlength="4096" placeholder="{{ $provider['has_key'] ? 'Leave blank to keep current key' : 'Enter this provider API key' }}"></div>
<button>Save {{ $provider['label'] }} settings</button></form></section>
@endforeach
@endsection
