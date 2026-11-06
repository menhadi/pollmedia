<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class SiteSettings
{
    public function get(string $key, mixed $default = null): mixed
    {
        if (! RequestSchema::hasTable('site_settings')) {
            return $default;
        }
        $value = PublicRequestCache::setting($key);

        return $value['present'] ? $value['value'] : $default;
    }

    public function save(string $key, mixed $value, string $reason = 'Administrative settings update'): void
    {
        DB::transaction(function () use ($key, $value, $reason) {
            $before = DB::table('site_settings')->where('key', $key)->lockForUpdate()->value('value');
            DB::table('site_changes')->insert(['target' => 'settings:'.$key, 'before_value' => $before, 'after_value' => json_encode($value), 'reason' => $reason, 'user_id' => auth()->id(), 'created_at' => now()]);
            DB::table('site_settings')->updateOrInsert(['key' => $key], ['value' => json_encode($value), 'created_at' => now(), 'updated_at' => now()]);
        });
    }

    public function appearance(): array
    {
        return array_replace(config('site.appearance'), $this->get('appearance', []));
    }
}
