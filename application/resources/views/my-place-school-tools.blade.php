@php
 $districtTools=$context['state']['code']==='29' && $context['district']['code']==='548';
@endphp
<div data-local-data-browser data-url="{{ route('my-place.school-records',$moduleParams) }}">@include('my-place-school-records',['schools'=>$hub['schools']])</div><p data-local-data-status role="status"></p>
@if($districtTools)
<h3>{{ $t('Tumakuru district services','तुमकुरु जिले की सेवाएँ') }}</h3>
<p class="place-note">{{ $t('District directories · confirm location and availability before visiting.','जिला निर्देशिकाएँ · जाने से पहले स्थान और सेवा की पुष्टि करें।') }}</p>
<div class="district-service-links">
<a href="https://tumkur.nic.in/en/public-utility-category/schools/" target="_blank" rel="noopener noreferrer">{{ $t('Schools','स्कूल') }} &rarr;</a>
<a href="https://tumkur.nic.in/en/public-utility-category/hospitals/" target="_blank" rel="noopener noreferrer">{{ $t('Hospitals','अस्पताल') }} &rarr;</a>
<a href="https://tumkur.nic.in/en/public-utility-category/electricity/" target="_blank" rel="noopener noreferrer">{{ $t('Electricity offices','बिजली कार्यालय') }} &rarr;</a>
<a href="https://tumkur.nic.in/en/education/" target="_blank" rel="noopener noreferrer">{{ $t('Education department','शिक्षा विभाग') }} &rarr;</a>
</div>
@endif
