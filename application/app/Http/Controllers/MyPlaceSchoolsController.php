<?php

namespace App\Http\Controllers;

use App\Services\MyPlaceDirectory;
use App\Services\MyPlaceSchools;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MyPlaceSchoolsController extends Controller
{
    public function index(Request $request, MyPlaceDirectory $directory, MyPlaceSchools $schools): View
    {
        $input = $request->validate(['state' => 'required|regex:/^[0-9]{1,3}$/', 'district' => 'required|regex:/^[0-9]{1,8}$/', 'village' => 'nullable|regex:/^[0-9]{1,8}$/', 'browse' => 'nullable|in:district', 'block' => 'nullable|string|max:200', 'source_village' => 'nullable|string|max:200', 'pin' => 'nullable|regex:/^[1-9][0-9]{5}$/', 'page' => 'nullable|integer|min:1|max:10000', 'lang' => 'nullable|in:en,hi']);
        $context = $directory->context($input['state'], $input['district'], $input['village'] ?? null);
        $language = ($input['lang'] ?? 'en') === 'hi' ? 'hi' : 'en';

        return view('my-place-school-records', ['schools' => $schools->data($context, $input), 'context' => $context, 'language' => $language, 't' => fn ($en, $hi) => $language === 'hi' ? $hi : $en]);
    }
}
