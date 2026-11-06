@auth
@include('community-inline-field',['field'=>$field,'value'=>$value,'expected'=>$data->fingerprint($record),'action'=>route('community.propose',$record->id)])
@else
<a class="cell-sign-in" href="{{ route('community.login',['next'=>request()->getPathInfo().'?'.http_build_query(array_merge(request()->query(),['edit'=>'1']))]) }}">Edit</a>
@endauth
