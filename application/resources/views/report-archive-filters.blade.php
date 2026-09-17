<form method="get" class="card">
<label for="report-scope">Area</label><select id="report-scope" name="scope"><option value="">All areas</option>
@foreach($scopes as $scope)<option value="{{ $scope->key }}" @selected(request('scope') === $scope->key)>{{ $scope->label }}</option>@endforeach</select>
<label for="report-edition">Edition</label><select id="report-edition" name="edition"><option value="">All editions</option><option value="quarterly" @selected(request('edition') === 'quarterly')>Quarterly</option><option value="annual" @selected(request('edition') === 'annual')>Annual</option></select>
<button>Filter saved drafts</button><a href="{{ route('reports.archive') }}">Clear</a>
</form>
