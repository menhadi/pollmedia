<?php

namespace App\Http\Controllers;

use App\Services\AiProviders;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class AiSettingsController extends Controller
{
    public function index(AiProviders $providers): View
    {
        return view('ai-settings', ['providers' => $providers->options()]);
    }

    public function save(Request $request, string $provider): RedirectResponse
    {
        abort_unless(isset(AiProviders::LABELS[$provider]), 404);
        $input = $request->validate(['model' => ['required', 'string', 'max:120', 'regex:/^[a-zA-Z0-9][a-zA-Z0-9._-]*$/'],
            'api_key' => ['nullable', 'string', 'max:4096', 'regex:/^\\S+$/']]);
        $values = ['model' => $input['model'], 'updated_by' => $request->user()->id, 'updated_at' => now()];
        if (filled($input['api_key'] ?? null)) {
            $values['encrypted_key'] = Crypt::encryptString($input['api_key']);
        }
        DB::table('ai_provider_settings')->updateOrInsert(['provider' => $provider], $values);

        return redirect()->route('ai.settings')->with('status', AiProviders::LABELS[$provider].' settings saved. No API request was made.');
    }
}
