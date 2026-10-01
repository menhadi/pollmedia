<?php

namespace App\Console\Commands;

use App\Services\HistoricalCensusPackage;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Symfony\Component\HttpKernel\Exception\HttpException;

#[Signature('census:verify-historical {package} {sha256}')]
#[Description('Verify a historical Census package and report source coverage without database writes')]
class VerifyHistoricalCensusPackage extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(HistoricalCensusPackage $packages): int
    {
        try {
            $partitions = $packages->verify($this->argument('package'), $this->argument('sha256'));
        } catch (HttpException $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }
        $this->line(json_encode($packages->coverage($partitions), JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
