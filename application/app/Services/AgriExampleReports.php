<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;

class AgriExampleReports
{
    public function catalogue(): array
    {
        return json_decode(file_get_contents(resource_path('agri-examples.json')), true, flags: JSON_THROW_ON_ERROR)['reports'];
    }

    public function find(string $report, string $language): array
    {
        $reports = $this->catalogue();
        abort_unless(isset($reports[$report]) && in_array($language, ['en', 'hi'], true), 404);

        return ['id' => $report, 'language' => $language, 'document' => $reports[$report]['documents'][$language], 'shared' => $reports[$report]['shared']];
    }

    public function pdf(string $report, string $language): array
    {
        $this->find($report, $language);
        $asset = $this->catalogue()[$report]['pdfs'][$language];
        abort_unless(preg_match('/^[A-Za-z0-9-]+\.pdf$/D', $asset['file']) === 1, 404);
        $path = Storage::disk('local')->path('agri-examples/'.$asset['file']);
        abort_unless(is_file($path) && hash_equals($asset['sha256'], hash_file('sha256', $path)), 404);

        return ['path' => $path, 'filename' => $asset['file']];
    }
}
