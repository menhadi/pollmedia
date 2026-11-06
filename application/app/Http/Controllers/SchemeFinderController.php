<?php

namespace App\Http\Controllers;

use App\Services\AadhaarAutofill;
use App\Services\PostalResidence;
use App\Services\SchemeCatalogue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SchemeFinderController extends Controller
{
    public function index(Request $request): View
    {
        $initial = $request->validate(['state' => 'nullable|regex:/^[0-9]{1,3}$/', 'district' => 'nullable|regex:/^[0-9]{1,8}$/']);

        return view('scheme-finder', ['catalogue' => app(SchemeCatalogue::class)->all(), 'initial' => $initial, 'language' => app()->getLocale() === 'hi' ? 'hi' : 'en']);
    }

    public function pincode(Request $request, PostalResidence $postal): JsonResponse
    {
        $data = $request->validate(['pincode' => ['required', 'regex:/^[1-9][0-9]{5}$/']]);

        return response()->json($postal->lookup($data['pincode']))->header('Cache-Control', 'private, no-store');
    }

    public function autofill(Request $request, AadhaarAutofill $reader): JsonResponse
    {
        $request->validate(['consent' => 'accepted', 'photo' => 'required|file|mimetypes:image/jpeg,image/png,image/webp|max:5120|dimensions:max_width=8000,max_height=8000']);
        $fields = $reader->read($request->file('photo'));

        return response()->json(['fields' => $fields, 'identity_verified' => false])->header('Cache-Control', 'private, no-store')->header('X-Content-Type-Options', 'nosniff');
    }
}
