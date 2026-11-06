<?php

namespace App\Http\Controllers;

use App\Services\MyPlaceDirectory;
use App\Services\MyPlacePanchayat;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MyPlacePanchayatController extends Controller
{
    public function index(Request $request, MyPlaceDirectory $directory, MyPlacePanchayat $panchayats): View
    {
        $input = $request->validate(['state' => 'required|regex:/^[0-9]{1,3}$/', 'district' => 'required|regex:/^[0-9]{1,8}$/', 'village' => 'nullable|regex:/^[0-9]{1,8}$/', 'panchayat' => 'nullable|regex:/^[0-9]{1,10}$/', 'lang' => 'nullable|in:en,hi']);
        $context = $directory->context($input['state'], $input['district'], $input['village'] ?? null);
        $language = ($input['lang'] ?? 'en') === 'hi' ? 'hi' : 'en';
        $panchayat = $panchayats->data($context, $input['panchayat'] ?? null);
        abort_if(! empty($input['panchayat']) && ! $panchayat['selected'], 404);

        return view('my-place-gram-panchayat', compact('panchayat', 'context', 'language') + ['t' => fn ($en, $hi) => $language === 'hi' ? $hi : $en]);
    }
}
