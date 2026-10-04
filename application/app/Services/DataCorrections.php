<?php

namespace App\Services;

use App\Http\Controllers\PlaceController;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DataCorrections
{
    public const FIELDS = ['historical_constituency_index' => [], 'sir_parts' => ['name', 'listed_records'], 'places' => ['name'], 'census_catalogue_rows' => ['name', 'values'], 'observations' => ['value', 'status'], 'election_candidate_results' => ['candidate_name', 'party_at_election', 'general_votes', 'postal_votes', 'votes'], 'election_contests' => ['electors', 'votes_polled', 'valid_candidate_votes']];

    public function record(string $table, int $id): object
    {
        abort_unless(isset(self::FIELDS[$table]), 404);

        if ($table === 'sir_parts') {
            return $this->sirRows()->firstWhere('id', $id) ?? abort(404);
        }

        return DB::table($table)->find($id) ?? abort(404);
    }

    public function save(string $table, int $id, array $values, string $expected, string $reason): void
    {
        DB::transaction(function () use ($table, $id, $values, $expected, $reason) {
            $this->record($table, $id);
            if ($table === 'sir_parts') {
                $this->lockSir();
                $row = $this->record($table, $id);
            } else {
                $row = DB::table($table)->where('id', $id)->lockForUpdate()->first();
            }
            abort_unless(hash_equals(hash('sha256', json_encode($row)), $expected), 409, 'Record changed. Reload before saving.');
            $before = array_intersect_key((array) $row, array_flip(self::FIELDS[$table]));
            $after = array_intersect_key($values, $before);
            DB::table('site_changes')->insert(['target' => $table.':'.$id, 'before_value' => json_encode($before), 'after_value' => json_encode($after), 'reason' => $reason, 'user_id' => auth()->id(), 'created_at' => now()]);
            if ($table === 'sir_parts') {
                $this->storeSir($id, $after);
            } else {
                DB::table($table)->where('id', $id)->update($after);
            }
        });
    }

    public function sirRows(): Collection
    {
        $data = app(PlaceController::class)->payload('sir-pilibhit');
        $overrides = DB::table('site_settings')->where('key', 'like', 'sir-part:%')->get()->keyBy('key');

        return collect($data['parts'])->map(function (array $row) use ($overrides): ?object {
            $override = $overrides->get('sir-part:'.$row['part']);
            $fields = $override ? json_decode($override->value, true, 512, JSON_THROW_ON_ERROR) : [];
            if ($fields['removed'] ?? false) {
                return null;
            }

            return (object) (array_replace($row, $fields) + ['id' => (int) $row['part']]);
        })->filter()->values();
    }

    private function lockSir(): void
    {
        DB::table('site_settings')->insertOrIgnore(['key' => 'sir-edit-lock', 'value' => 'null', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('site_settings')->where('key', 'sir-edit-lock')->lockForUpdate()->first();
    }

    private function storeSir(int $id, array $fields): void
    {
        DB::table('site_settings')->updateOrInsert(['key' => 'sir-part:'.$id], ['value' => json_encode($fields, JSON_THROW_ON_ERROR), 'updated_at' => now(), 'created_at' => now()]);
    }

    public function remove(string $table, int $id, string $expected, string $reason): void
    {
        DB::transaction(function () use ($table, $id, $expected, $reason): void {
            if ($table === 'sir_parts') {
                $this->lockSir();
                $row = $this->record($table, $id);
            } else {
                $this->record($table, $id);
                $row = DB::table($table)->where('id', $id)->lockForUpdate()->first();
            }
            abort_unless(hash_equals(hash('sha256', json_encode($row)), $expected), 409, 'Record changed. Reload before removing.');
            if ($table === 'election_contests' && DB::table('election_candidate_results')->where('election_contest_id', $id)->exists()) {
                throw ValidationException::withMessages(['id' => 'Remove the candidate rows before removing this contest.']);
            }
            DB::table('site_changes')->insert(['target' => $table.':'.$id, 'before_value' => json_encode($row), 'after_value' => 'null', 'reason' => 'Removed: '.$reason, 'user_id' => auth()->id(), 'created_at' => now()]);
            if ($table === 'sir_parts') {
                $this->storeSir($id, ['removed' => true]);
            } else {
                try {
                    DB::table($table)->where('id', $id)->delete();
                } catch (QueryException $exception) {
                    if (str_starts_with((string) $exception->getCode(), '23')) {
                        throw ValidationException::withMessages(['id' => 'This record is referenced by other data. Remove those references first.']);
                    }
                    throw $exception;
                }
            }
        });
    }
}
