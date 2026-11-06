<?php

namespace App\Http\Controllers;

use App\Services\PublicLanguage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HindiPlaceNameController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $request->validate(['names' => ['required', 'json', 'max:12000']]);
        $names = json_decode($request->query('names'), true, flags: JSON_THROW_ON_ERROR);
        abort_unless(is_array($names) && array_is_list($names) && count($names) <= 80, 422);
        $result = [];
        foreach ($names as $name) {
            abort_unless(is_string($name) && mb_strlen($name) <= 255, 422);
            $result[$name] = PublicLanguage::placeText($name);
        }

        return response()->json(['names' => $result])->header('Cache-Control', 'private, max-age=300');
    }
}
