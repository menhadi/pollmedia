<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;

class SirPageImage
{
    public function prepare(string $pdf, int $page, ?UploadedFile $upload = null): array
    {
        $folder = storage_path('app/private/sir-vision-images');
        File::ensureDirectoryExists($folder);
        if ($upload) {
            $bytes = file_get_contents($upload->getRealPath());
            $source = 'admin_upload';
        } else {
            $prefix = $folder.'/render-'.Str::uuid();
            try {
                $process = new Process([config('seo-ai.sir_pdf_renderer', 'pdftoppm'), '-f', (string) $page, '-l', (string) $page, '-scale-to', '2400', '-singlefile', '-png', $pdf, $prefix]);
                $process->setTimeout(30)->run();
                if (! $process->isSuccessful() || ! is_file($prefix.'.png')) {
                    throw new RuntimeException('Rendering failed.');
                }
                $bytes = file_get_contents($prefix.'.png');
            } catch (\Throwable) {
                throw new RuntimeException('The server could not create a page image. Attach a PNG, JPEG or WebP image of this PDF page/card, or configure pdftoppm on the server. No API call was made.');
            } finally {
                if (is_file($prefix.'.png')) {
                    File::delete($prefix.'.png');
                }
            }
            $source = 'original_pdf';
        }
        $dimensions = @getimagesizefromstring($bytes);
        $mime = $dimensions['mime'] ?? '';
        if (strlen($bytes) > 8000000 || ! $dimensions || $dimensions[0] > 8192 || $dimensions[1] > 8192 || $dimensions[0] * $dimensions[1] > 16000000 || ! in_array($mime, ['image/png', 'image/jpeg', 'image/webp'], true)) {
            throw new RuntimeException('Use a valid PNG, JPEG or WebP image below 8 MB and 16 million pixels.');
        }
        $hash = hash('sha256', $bytes);
        $path = $folder.'/'.$hash.'.image';
        if (! is_file($path)) {
            File::put($path, $bytes);
        }

        return ['data_url' => 'data:'.$mime.';base64,'.base64_encode($bytes), 'image_sha256' => $hash, 'image_mime' => $mime, 'image_source' => $source];
    }
}
