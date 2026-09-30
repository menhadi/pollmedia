<?php

namespace App\Http\Controllers;

use App\Services\SiteSettings;
use App\Services\StaticPages;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class TranslationController extends Controller
{
    public function index(SiteSettings $settings): View
    {
        $entries = config('hindi');
        foreach (app(StaticPages::class)->all() as $page) {
            foreach (['title', 'summary', 'content'] as $field) {
                $entries[$page[$field]] ??= '';
            }
        }

        return view('translations-admin', ['entries' => $entries, 'saved' => $settings->get('translations.hi', [])]);
    }

    public function save(Request $request, SiteSettings $settings): RedirectResponse
    {
        $data = $request->validate(['source' => 'required|string|max:50000', 'translation' => 'nullable|string|max:50000']);
        DB::transaction(function () use ($settings, $data) {
            DB::table('site_settings')->insertOrIgnore(['key' => 'translations.hi', 'value' => '{}', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('site_settings')->where('key', 'translations.hi')->lockForUpdate()->first();
            $saved = $settings->get('translations.hi', []);
            if (trim($data['translation'] ?? '') === '') {
                unset($saved[$data['source']]);
            } else {
                $saved[$data['source']] = $data['translation'];
            }
            $settings->save('translations.hi', $saved, 'Updated Hindi translation');
        });

        return back()->with('status', 'Hindi translation saved. Empty translations use the default or English.');
    }
}
