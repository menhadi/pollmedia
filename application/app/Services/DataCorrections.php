<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class DataCorrections
{
    public const FIELDS = ['places' => ['name'], 'census_catalogue_rows' => ['name', 'values'], 'observations' => ['value', 'status'], 'election_candidate_results' => ['candidate_name', 'party_at_election', 'general_votes', 'postal_votes', 'votes'], 'election_contests' => ['electors', 'votes_polled', 'valid_candidate_votes']];

    public function record(string $table, int $id): object
    {
        abort_unless(isset(self::FIELDS[$table]), 404);

        return DB::table($table)->find($id) ?? abort(404);
    }

    public function save(string $table, int $id, array $values, string $expected, string $reason): void
    {
        DB::transaction(function () use ($table, $id, $values, $expected, $reason) {
            $this->record($table, $id);
            $row = DB::table($table)->where('id', $id)->lockForUpdate()->first();
            abort_unless(hash_equals(hash('sha256', json_encode($row)), $expected), 409, 'Record changed. Reload before saving.');
            $before = array_intersect_key((array) $row, array_flip(self::FIELDS[$table]));
            $after = array_intersect_key($values, $before);
            DB::table('site_changes')->insert(['target' => $table.':'.$id, 'before_value' => json_encode($before), 'after_value' => json_encode($after), 'reason' => $reason, 'user_id' => auth()->id(), 'created_at' => now()]);
            DB::table($table)->where('id', $id)->update($after);
        });
    }
}
