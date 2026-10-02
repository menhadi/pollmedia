<?php

namespace App\Console\Commands;

use App\Services\OriginalNationalEducationExpansionPackage;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('census:recover-national-original-education-expansion {receipt} {sha256}')]
#[Description('Restore the saved national education publication pointer after verifying a committed receipt; preserve all rows')]
class RecoverOriginalNationalEducationExpansionPublication extends Command
{
    public function handle(OriginalNationalEducationExpansionPackage $packages): int
    {
        try {
            $result = $packages->recoverPublication($this->argument('receipt'), $this->argument('sha256'));
            $this->line(json_encode($result, JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (Throwable $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }
    }
}
