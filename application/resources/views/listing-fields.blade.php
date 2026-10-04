<div class="admin-field-grid">
@foreach($fields as $field=>$value)
@php($fieldPath=[...$path,$field])
@if(is_array($value))<fieldset class="admin-field-section"><legend>{{ is_int($field)?'Row '.($field+1):(['values'=>'Census measures','geography'=>'Geographic details','flags'=>'Data notes','candidates'=>'Candidate results','counts'=>'SIR reason counts'][$field] ?? \Illuminate\Support\Str::headline($field)) }}</legend>@include('listing-fields',['fields'=>$value,'path'=>$fieldPath,'readonly'=>$readonly])</fieldset>
@else
<label class="admin-field-label">{{ ['TOT_P'=>'Total population (TOT_P)','TOT_M'=>'Male population (TOT_M)','TOT_F'=>'Female population (TOT_F)','No_HH'=>'Households (No_HH)','P_LIT'=>'Literate persons (P_LIT)','M_LIT'=>'Literate males (M_LIT)','F_LIT'=>'Literate females (F_LIT)','P_06'=>'Children aged 0–6 (P_06)','M_06'=>'Male children aged 0–6 (M_06)','F_06'=>'Female children aged 0–6 (F_06)','listed_records'=>'Listed records','electors'=>'Registered electors','votes_polled'=>'Votes polled','valid_candidate_votes'=>'Valid candidate votes','candidate_name'=>'Candidate / representative name','party_at_election'=>'Party at election','general_votes'=>'General votes','postal_votes'=>'Postal votes','votes'=>'Total votes','winner'=>'MP / MLA election winner (calculated)','margin'=>'Winning margin (calculated)'][$field] ?? \Illuminate\Support\Str::headline((string)$field) }}
@if(count($path)===0 && in_array($field,$readonly,true))<output class="admin-field-output">{{ is_bool($value)?($value?'Yes':'No'):($value??'Not recorded') }}</output>
@elseif(is_bool($value))<select data-field-path="{{ json_encode($fieldPath) }}" data-type="boolean"><option value="true" @selected($value)>Yes</option><option value="false" @selected(!$value)>No</option></select>
@else<input data-field-path="{{ json_encode($fieldPath) }}" data-type="{{ $value===null?'null':(is_numeric($value)&&!is_string($value)?'number':'string') }}" type="{{ is_int($value)||is_float($value)?'number':'text' }}" @if(is_int($value)||is_float($value)) step="any" @endif value="{{ $value }}">@endif</label>
@endif
@endforeach

</div>
