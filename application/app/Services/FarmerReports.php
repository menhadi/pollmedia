<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class FarmerReports
{
    public function owner(Request $request): string
    {
        if (! $request->session()->has('farmer_owner')) {
            $request->session()->put('farmer_owner', bin2hex(random_bytes(32)));
        }

        return hash_hmac('sha256', $request->session()->get('farmer_owner'), config('app.key'));
    }

    public function find(string $id, Request $request): array
    {
        $row = DB::table('farmer_assessments')->where('id', $id)->where('owner_hash', $this->owner($request))->where('expires_at', '>', now())->first();
        abort_unless($row, 404);

        return ['record' => $row, 'data' => json_decode(Crypt::decryptString($row->private_payload), true, flags: JSON_THROW_ON_ERROR)];
    }

    public function save(string $id, array $data): void
    {
        DB::table('farmer_assessments')->where('id', $id)->update(['private_payload' => Crypt::encryptString(json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)), 'updated_at' => now()]);
    }

    public function photo(UploadedFile $file, string $id, string $kind = 'field'): array
    {
        $size = @getimagesize($file->getRealPath());
        if (! $size || $size[0] * $size[1] > 12000000) {
            throw ValidationException::withMessages(['photos' => 'Use a photo smaller than 12 megapixels.']);
        }
        $image = @imagecreatefromstring(file_get_contents($file->getRealPath()));
        if (! $image) {
            throw ValidationException::withMessages(['photos' => 'This photo could not be read.']);
        }
        $ratio = min(1, 1600 / max($size[0], $size[1]));
        $clean = imagescale($image, max(1, (int) ($size[0] * $ratio)), max(1, (int) ($size[1] * $ratio)));
        ob_start();
        imagejpeg($clean, null, 85);
        $bytes = ob_get_clean();
        imagedestroy($image);
        imagedestroy($clean);
        $path = 'farmer-assessments/'.$id.'/'.bin2hex(random_bytes(16)).'.jpg';
        abort_unless(Storage::disk('local')->put($path, $bytes), 503, 'Photo could not be saved.');

        return ['path' => $path, 'mime' => 'image/jpeg', 'sha256' => hash('sha256', $bytes), 'kind' => $kind];
    }
}
