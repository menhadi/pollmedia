<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class StaticPages
{
    public function all(): array
    {
        return array_replace(config('static-pages'), app(SiteSettings::class)->get('static-pages', []));
    }

    public function published(): array
    {
        return collect($this->all())->filter(fn ($page) => $page['published'])->sortBy('order')->all();
    }

    public function save(string $slug, array $page, string $expected): void
    {
        DB::transaction(function () use ($slug, $page, $expected) {
            DB::table('site_settings')->insertOrIgnore(['key' => 'static-pages', 'value' => '{}', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('site_settings')->where('key', 'static-pages')->lockForUpdate()->first();
            $all = $this->all();
            abort_unless(hash_equals(hash('sha256', json_encode($all[$slug] ?? null)), $expected), 409, 'This page changed. Reload it before saving.');
            $all[$slug] = $page + ['updated_at' => now()->toIso8601String()];
            app(SiteSettings::class)->save('static-pages', $all, 'Updated static page: '.$slug);
        });
    }
}
