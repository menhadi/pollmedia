<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\Foundation\MaintenanceMode;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\View\View;

class MaintenanceController extends Controller
{
    public function preview(MaintenanceMode $maintenance): RedirectResponse
    {
        if (! $maintenance->active()) {
            return redirect('/');
        }

        $data = $maintenance->data();
        if (empty($data['secret'])) {
            $data['secret'] = Str::random(64);
            $maintenance->activate($data);
        }

        return redirect('/'.$data['secret'])->header('Cache-Control', 'no-store, private');
    }

    public function index(MaintenanceMode $maintenance): View
    {
        return view('maintenance', ['enabled' => $maintenance->active()]);
    }

    public function update(Request $request, MaintenanceMode $maintenance): RedirectResponse
    {
        $request->validate(['enabled' => ['required', 'boolean']]);
        $enabled = $request->boolean('enabled');
        $before = $maintenance->active();

        if (Schema::hasTable('site_changes')) {
            DB::table('site_changes')->insert([
                'target' => 'settings:maintenance',
                'before_value' => json_encode(['enabled' => $before]),
                'after_value' => json_encode(['enabled' => $enabled]),
                'reason' => $enabled ? 'Public access paused for maintenance' : 'Public access restored',
                'user_id' => $request->user()->id,
                'created_at' => now(),
            ]);
        }

        if ($enabled) {
            $maintenance->activate(['status' => 503, 'retry' => 300, 'secret' => Str::random(64)]);
        } else {
            $maintenance->deactivate();
        }

        return to_route('maintenance.index')->with('status', $enabled
            ? 'Maintenance enabled. Public pages and APIs are temporarily unavailable.'
            : 'Maintenance disabled. Public access is restored.');
    }
}
