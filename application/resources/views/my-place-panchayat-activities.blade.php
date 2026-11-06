@php
    $detailLabels = ['Asset Type'=>'परिसंपत्ति प्रकार','Asset Category'=>'परिसंपत्ति श्रेणी','Asset Sub Category'=>'परिसंपत्ति उपश्रेणी','Coverage Area'=>'कवरेज क्षेत्र','Asset Unit Type'=>'परिसंपत्ति इकाई','Asset No. Of Unit'=>'इकाइयों की संख्या','Asset Unit Cost'=>'प्रति इकाई लागत','Asset Location'=>'परिसंपत्ति का स्थान','Training Category'=>'प्रशिक्षण श्रेणी','Organized By'=>'आयोजक','Subject of Training'=>'प्रशिक्षण का विषय','Total Trainees'=>'कुल प्रशिक्षु','Total Duration'=>'कुल अवधि'];
@endphp
@foreach($panchayat['reports'] as $report)
@if(!empty($report['activities']))
@php
    $columns = ['name'=>['Activity','गतिविधि'],'code'=>['Activity code','गतिविधि कोड'],'description'=>['Description','विवरण'],'focus_area'=>['Focus area','क्षेत्र'],'theme'=>['Theme','विषय'],'cost'=>['Reported cost','दर्ज लागत'],'status'=>['Source status','स्रोत की स्थिति'],'type'=>['Type','प्रकार'],'output_type'=>['Output','परिणाम प्रकार'],'plan_type'=>['Plan','योजना'],'plan_id'=>['Plan ID','योजना पहचान'],'retrieved_on'=>['Details checked','विवरण जाँचा गया'],'technical_status'=>['Technical approval','तकनीकी स्वीकृति'],'administrative_status'=>['Administrative approval','प्रशासनिक स्वीकृति'],'progress_status'=>['Physical progress','भौतिक प्रगति'],'expenditure_status'=>['Activity expenditure record','गतिविधि व्यय रिकॉर्ड']];
    $extraColumns = [];
    foreach ($report['activities'] as $activity) {
        foreach (['asset','training'] as $kind) {
            foreach (array_keys($activity['details'][$kind] ?? []) as $label) {
                $extraColumns[$kind.':'.$label] = [$kind, $label];
            }
        }
    }
@endphp
<section class="gp-activities" translate="no" aria-label="{{ $t('Development activities','विकास गतिविधियाँ') }}">
<h3>{{ $t('Development activities','विकास गतिविधियाँ') }}</h3>
<p><strong>{{ count($report['activities']) }} {{ $t('reported activities','दर्ज गतिविधियाँ') }}</strong> · {{ $report['name'] }} · {{ $report['financial_year'] }}</p>
<div class="gp-activity-scroll" tabindex="0" role="region" aria-label="{{ $t('Development activities table','विकास गतिविधियाँ तालिका') }}">
<table class="gp-activity-table">
<caption>{{ $t('Plan activities · cost unit not stated in the source','योजना गतिविधियाँ · स्रोत में लागत की इकाई नहीं दी गई है') }} · {{ $report['sources']['activities']['report_date'] }}</caption>
<thead><tr>@foreach($columns as $labels)<th scope="col">{{ $t(...$labels) }}</th>@endforeach
@foreach($extraColumns as [$kind,$label])<th scope="col">{{ $t($label,$detailLabels[$label] ?? $label) }}</th>@endforeach
@foreach([['Funding amount','आवंटित राशि'],['Funding unit','धन इकाई'],['Funding scheme','धन योजना'],['Funding component','धन घटक']] as $labels)<th scope="col">{{ $t(...$labels) }}</th>@endforeach
</tr></thead>
<tbody>@foreach($report['activities'] as $activity)
<tr data-activity-code="{{ $activity['code'] }}">
@foreach($columns as $field=>$labels)
@php($value=$activity[$field] ?? $activity['details'][$field] ?? null)
<td @if($field==='description' && $value!==null) data-activity-description @endif>{{ $value ?? ($field==='description'?$t('Further details unavailable','अतिरिक्त विवरण उपलब्ध नहीं'):$t('Unavailable','उपलब्ध नहीं')) }}</td>
@endforeach
@foreach($extraColumns as [$kind,$label])<td>{{ $activity['details'][$kind][$label] ?? $t('Unavailable','उपलब्ध नहीं') }}</td>@endforeach
@foreach(['amount','unit','scheme','component'] as $field)
<td>@forelse($activity['details']['funding'] ?? [] as $funding)<div @if($field==='amount') data-funding-allocation @endif>{{ $funding[$field] ?? ($field==='unit'?$t('unit not stated','इकाई नहीं दी गई'):$t('Unavailable','उपलब्ध नहीं')) }}</div>@empty{{ $t('Unavailable','उपलब्ध नहीं') }}@endforelse</td>
@endforeach
</tr>@endforeach</tbody>
</table></div></section>
@endif
@endforeach
