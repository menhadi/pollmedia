<?php

namespace App\Console\Commands;

use App\Services\SirNameSearch;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RebuildSirSearchAliases extends Command
{
    protected $signature = 'sir:rebuild-search-aliases';

    protected $description = 'Rebuild hidden English name aliases without reimporting SIR PDFs';

    public function handle(): int
    {
        $count = 0;
        DB::table('sir_records')->where('document_type', 'electoral_roll')->orderBy('id')->chunkById(100, function ($rows) use (&$count): void {
            foreach ($rows as $row) {
                $name = SirNameSearch::latin($row->name);
                $relative = SirNameSearch::latin($row->relative_name);
                DB::table('sir_records')->where('id', $row->id)->update(['name_latin' => $name, 'relative_name_latin' => $relative, 'name_latin_key' => SirNameSearch::key($name), 'relative_name_latin_key' => SirNameSearch::key($relative)]);
                $count++;
            }
        });
        $this->info('Rebuilt English search aliases for '.$count.' records.');

        return self::SUCCESS;
    }
}
