@extends('geography-layout')
@section('title','Report a problem')
@section('content')
@if(session('status'))<div class="card" role="status"><h1>Report submitted</h1><p>{{ session('status') }}</p><a href="{{ route('feedback.create',['path'=>$path,'category'=>$category]) }}">Report another problem →</a></div>@else
<h1>Report a problem</h1><p>Choose the problem type. The affected page is attached automatically. Your email is used only to follow up on this report.</p>
@if($errors->any())<ul role="alert">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>@endif
<form method="post" action="{{ route('feedback.store') }}">@csrf<label>Problem type<select name="category">@foreach(['data'=>'Data or calculation','source'=>'Source or document','navigation'=>'Link or navigation','accessibility'=>'Accessibility or display','other'=>'Other'] as $key=>$label)<option value="{{ $key }}" @selected(old('category',$category)===$key)>{{ $label }}</option>@endforeach</select></label><input type="hidden" name="path" value="{{ old('path',$path) }}"><p class="muted">Page and result: <strong>{{ $path === '/' ? 'General website report' : $path }}</strong></p><label>Email address (required)<input type="email" name="email" value="{{ old('email',$email) }}" maxlength="254" autocomplete="email" required></label><label>Details (optional)<textarea name="details" rows="4" maxlength="5000">{{ old('details') }}</textarea></label><button>Send report</button></form>@endif
@endsection
