<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class LocalBoard
{
    public const CATEGORIES = ['agriculture' => 'Agriculture', 'construction' => 'Construction', 'repairs' => 'Repairs & maintenance', 'transport' => 'Transport', 'retail' => 'Shops & retail', 'domestic' => 'Household work', 'education' => 'Education & training', 'health' => 'Health & care', 'professional' => 'Professional services', 'other' => 'Other'];

    public const METHODS = ['phone' => 'Contact checked by phone', 'official_record' => 'Official record checked', 'in_person' => 'Checked in person', 'document' => 'Supporting evidence checked'];

    public function active(): Builder
    {
        return DB::table('local_board_listings')->where('status', 'approved')->whereNotNull('reviewed_at')->where('expires_at', '>', now())->whereExists(fn (Builder $query) => $query->selectRaw('1')->from('users')->whereColumn('users.id', 'local_board_listings.user_id')->whereNotNull('email_verified_at'));
    }

    public function forArea(Builder $query, ?array $area): Builder
    {
        if (! $area) {
            return $query;
        }
        $parents = array_column($area['parents'], 'key');
        if ($area['kind'] === 'village') {
            return $query->whereIn('primary_area_key', [$area['key'], ...$parents]);
        }

        return $query->where(function (Builder $query) use ($area, $parents): void {
            $query->whereIn('id', DB::table('local_board_scopes')->where('area_key', $area['key'])->select('listing_id'));
            if ($area['kind'] !== 'district' && $parents !== []) {
                $query->orWhereIn('primary_area_key', $parents);
            }
        });
    }

    public function visible(object $listing): bool
    {
        return $listing->status === 'approved' && $listing->reviewed_at !== null && Carbon::parse($listing->expires_at)->isFuture() && DB::table('users')->where('id', $listing->user_id)->whereNotNull('email_verified_at')->exists();
    }

    public function saveScopes(string $id, array $area): void
    {
        DB::table('local_board_scopes')->where('listing_id', $id)->delete();
        foreach ([$area, ...$area['parents']] as $scope) {
            DB::table('local_board_scopes')->insert(['listing_id' => $id, 'area_key' => $scope['key'], 'name' => $scope['name'], 'kind' => $scope['kind']]);
        }
    }

    public function event(string $id, int $actor, string $action, ?string $from, string $to, ?string $note = null, ?string $method = null): void
    {
        DB::table('local_board_events')->insert(['listing_id' => $id, 'actor_id' => $actor, 'action' => $action, 'from_status' => $from, 'to_status' => $to, 'note' => $note, 'verification_method' => $method, 'created_at' => now()]);
    }
}
