@if($areaOptions->isNotEmpty())<section class="panel"><div class="filters">
@foreach(['block'=>'Block / subdistrict','village'=>'Village / town'] as $areaKind=>$areaLabel)
@php($navigationRows=$areaOptions->filter(fn($row)=>$areaKind==='block'?in_array($row->level,['SUB-DISTRICT','SUBDISTRICT','TEHSIL'],true):in_array($row->level,['VILLAGE','TOWN'],true)))
@if($navigationRows->isNotEmpty())<div><label for="{{ $areaKind }}-navigation">{{ $areaLabel }}</label><select id="{{ $areaKind }}-navigation" onchange="if(this.value) window.location.assign(this.value)"><option value="">Choose {{ strtolower($areaLabel) }}</option>@foreach($navigationRows as $option)<option value="{{ route('civic.place',['record'=>$option->id,'group'=>$group]) }}">{{ $option->name }}</option>@endforeach</select></div>@endif
@endforeach</div></section>@endif
