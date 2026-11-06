<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

#[Signature('farmers:prune')]
#[Description('Remove expired private farmer assessments and their photos')]
class PruneFarmerAssessments extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $count = 0;
        DB::table('farmer_assessments')->where('expires_at', '<=', now())->orderBy('id')->chunkById(100, function ($rows) use (&$count): void {
            foreach ($rows as $row) {
                if (! preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/i', $row->id)) {
                    continue;
                }
                $path = 'farmer-assessments/'.$row->id;
                $disk = Storage::disk('local');
                if ($disk->exists($path) && ! $disk->deleteDirectory($path)) {
                    $this->warn('Could not remove expired photos: '.$row->id);

                    continue;
                }
                DB::table('farmer_assessments')->where('id', $row->id)->where('expires_at', '<=', now())->delete();
                $count++;
            }
        });
        $this->info('Expired assessments removed: '.$count);

        return self::SUCCESS;
    }
}
