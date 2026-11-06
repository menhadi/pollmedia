@foreach($officePeople as $officePerson)
<p class="authority-person"><strong>{{ $officePerson->display_name }}</strong><br><small>Last recorded{{ !empty($officePerson->verified_at) ? ' '.substr($officePerson->verified_at, 0, 10) : '' }} · Posting may have changed. <a href="{{ $officePerson->url }}" target="_blank" rel="noopener">Official source ↗</a></small></p>
@endforeach
