@extends('geography-layout')
@section('title','Report a problem')
@section('content')<h1>Report a problem</h1><p>Tell us about a data value, source, page or accessibility problem. Reports are private to administrators. Do not include personal or sensitive information.</p>
@if(session('status'))<p role="status">{{ session('status') }}</p>@endif
@if($errors->any())<ul role="alert">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>@endif
<form method="post" action="{{ route('feedback.store') }}">@csrf<label>Problem type<select name="category">@foreach(['data'=>'Data or calculation','source'=>'Source or document','navigation'=>'Link or navigation','accessibility'=>'Accessibility or display','other'=>'Other'] as $key=>$label)<option value="{{ $key }}" @selected(old('category')===$key)>{{ $label }}</option>@endforeach</select></label><label>Page path<input name="path" value="{{ old('path',$path) }}" maxlength="2000" required></label><label>What needs correcting?<textarea name="details" rows="7" minlength="10" maxlength="5000" required>{{ old('details') }}</textarea></label><button>Send report</button></form>@endsection
