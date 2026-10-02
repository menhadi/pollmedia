<?php

namespace App\Console\Commands;

use App\Services\OriginalNationalEducationPackage;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('census:recover-national-original-education {receipt} {sha256}')]
#[Description('Restore the saved national education publication pointer after verifying a committed receipt; preserve all rows')]
class RecoverOriginalNationalEducationPublication extends Command
{
    public function handle(OriginalNationalEducationPackage $packages): int
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
