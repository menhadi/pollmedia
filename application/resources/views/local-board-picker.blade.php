<fieldset class="board-picker" data-board-picker data-endpoint="{{ route('local-board.places') }}" data-initial-state="{{ $area['state'] ?? '' }}" data-initial-district="{{ $area['district'] ?? '' }}" data-initial-kind="{{ $area['kind'] ?? 'village' }}">
<legend>Location</legend>
<input type="hidden" name="area" value="{{ old('area', $area['key'] ?? '') }}" data-board-area>
<div class="board-fields">
<label>State<select data-board-state><option value="">Choose a state</option>@foreach($states as $state)<option value="{{ $state['code'] }}" @selected((string)($area['state'] ?? '') === (string)$state['code'])>{{ $state['name'] }}</option>@endforeach</select></label>
<label>District<select data-board-district><option value="">Choose a state first</option></select></label>
<label>Place type<select data-board-kind>@foreach(['village' => 'Village', 'gp' => 'Gram Panchayat', 'city' => 'City / town', 'district' => 'Whole district'] as $value => $label)<option value="{{ $value }}" @selected(($area['kind'] ?? 'village') === $value)>{{ $label }}</option>@endforeach</select></label>
<label>Find place<input data-board-search type="search" maxlength="100" placeholder="Enter a place name"></label>
</div>
<div class="board-picker-results"><button type="button" class="board-secondary" data-board-find>Find places</button><label>Place<select data-board-place><option value="{{ $area['key'] ?? '' }}">{{ $area['name'] ?? 'Choose state and district' }}</option></select></label><button type="button" class="board-secondary" data-board-more hidden>More places</button></div>
<p data-board-location-status role="status">{{ !empty($area) ? 'Selected: '.$area['name'] : 'Choose a location to see nearby listings.' }}</p>
<noscript>Open your village, Panchayat, district or city page and follow its Jobs & services link to use that location.</noscript>
</fieldset>
