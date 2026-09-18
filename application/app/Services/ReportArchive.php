<?php

namespace App\Services;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class ReportArchive
{
    public function save(View $view): string
    {
        $data = $view->getData();
        $id = (string) Str::ulid();
        $path = 'report-drafts/'.$id.'.html';
        $html = $view->with('standalone', true)->render();
        $sourceIds = collect($data['references'])->pluck('id')->merge($data['contests']->map(fn ($entry) => $entry['result']->source_release_id))->unique()->values();
        if (! app(ArchiveFiles::class)->put($path, $html)) {
            throw new RuntimeException('Report snapshot could not be stored.');
        }
        try {
            DB::table('report_drafts')->insert([
                'id' => $id, 'edition' => $data['edition'], 'period' => $data['period'],
                'scope' => $data['scope'] ?? 'pilibhit',
                'generated_at' => $data['generatedAt']->copy()->utc(), 'created_at' => now(),
                'path' => $path, 'sha256' => hash('sha256', $html), 'source_release_ids' => $sourceIds->toJson(),
            ]);
        } catch (Throwable $error) {
            app(ArchiveFiles::class)->delete($path);
            throw $error;
        }

        return $id;
    }
}
