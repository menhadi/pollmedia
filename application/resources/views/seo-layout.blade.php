<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Administration | Pollmedia</title>
<style>
*{box-sizing:border-box}body{margin:0;background:#f5f6f0;color:#173d37;font:16px/1.6 system-ui,sans-serif}header{background:#123d35;color:white;padding:18px 5vw;display:flex;justify-content:space-between;gap:20px}header a{color:white}a{color:#176c55}main{max-width:1120px;margin:auto;padding:32px 22px}h1{font-size:38px;line-height:1.2;margin:12px 0}h2{font-size:24px}p{max-width:850px}.muted{color:#60736b;font-size:14px}.card{padding:24px;background:white;border:1px solid #d5dfd5;border-radius:12px;margin:20px 0}.filters,.actions{display:flex;gap:18px;align-items:end;flex-wrap:wrap}label{display:block;font-weight:600}input:not([type=checkbox]),textarea,select{font:inherit;padding:10px;border:1px solid #9caea1;border-radius:6px;max-width:100%}input[type=text],textarea{width:100%}input[type=checkbox]{width:19px;height:19px;margin-right:12px;vertical-align:middle}button,.button{font:inherit;background:#176c55;color:white;border:0;border-radius:6px;padding:11px 17px;cursor:pointer;text-decoration:none;display:inline-block}button.secondary{background:#e7efe4;color:#173d37}button:disabled{opacity:.5;cursor:not-allowed}.notice{padding:14px 20px;background:#e2efdf;border-left:4px solid #176c55}.error{background:#fff2df}.row{padding:12px 0;border-bottom:1px solid #e4e9e0}.url{font-size:13px;overflow-wrap:anywhere}.preview{padding:20px;background:#f5f7f1;border-radius:8px;margin:16px 0}.preview strong{display:block;color:#234da0;font-size:21px;overflow-wrap:anywhere}.preview p{margin:4px 0;overflow-wrap:anywhere}.field{margin:16px 0}.history{overflow-wrap:anywhere}nav[role=navigation]{display:flex;gap:15px;align-items:center}nav[role=navigation] svg{width:20px}summary{cursor:pointer}a:focus-visible,button:focus-visible,input:focus-visible,textarea:focus-visible,select:focus-visible{outline:3px solid #c99225;outline-offset:3px}@media(max-width:600px){h1{font-size:30px}.card{padding:17px}header{font-size:14px}.actions>*{width:100%}}
</style></head><body><header><strong>pollmedia. / Administration</strong><div class="actions"><a href="{{ route('home') }}">View website</a>
@auth
<a href="{{ route('imports.index') }}">Data imports</a><a href="{{ route('seo.index') }}">SEO</a><a href="{{ route('admin.account') }}">Your account</a><span>{{ auth()->user()->name }}</span><form method="post" action="{{ route('admin.logout') }}">@csrf<button class="secondary">Sign out</button></form>
@endauth
</div></header><main>
@if(session('status'))
<p class="notice" role="status">{{ session('status') }}</p>
@endif
@if($errors->any())
<div class="notice error" role="alert"><strong>Please check the form</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
@endif
@yield('content')
</main></body></html>
