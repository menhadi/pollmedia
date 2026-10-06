<?php

namespace App\Http\Controllers;

use App\Services\SirPartUpload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SirUploadController extends Controller
{
    public function start(Request $request, SirPartUpload $uploads): JsonResponse
    {
        $input = $request->validate(['kind' => 'required|in:records,pdf', 'size' => 'required|integer|min:1|max:100000000', 'sha256' => 'required|regex:/^[a-f0-9]{64}$/']);
        abort_if($input['kind'] === 'records' && $input['size'] > 50000000, 422, 'Records JSON exceeds 50 MB.');

        return response()->json(['token' => $uploads->start($request->user()->id, $input['kind'], $input['size'], $input['sha256'])])->header('Cache-Control', 'no-store');
    }

    public function chunk(Request $request, string $token, SirPartUpload $uploads): JsonResponse
    {
        $input = $request->validate(['offset' => 'required|integer|min:0', 'chunk' => 'required|file|max:512']);

        return response()->json(['offset' => $uploads->append($request->user()->id, $token, $input['offset'], $request->file('chunk'))])->header('Cache-Control', 'no-store');
    }
}
