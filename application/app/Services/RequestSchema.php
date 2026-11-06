<?php

namespace App\Services;

use Illuminate\Support\Facades\Schema;

class RequestSchema
{
    public static function hasTable(string $table): bool
    {
        $builder = Schema::getFacadeRoot();
        $request = app()->bound('request') ? app('request') : null;
        $connection = $builder->getConnection();
        if (! $request || ! $request->isMethodSafe() || $request->route() === null || $connection->transactionLevel() !== 0) {
            return $builder->hasTable($table);
        }

        $key = '_pollmedia.schema.exists.'.hash('sha256', serialize([
            spl_object_id($connection), $connection->getName(), $connection->getDatabaseName(),
            $connection->getTablePrefix(), $table,
        ]));
        if ($request->attributes->get($key) === true) {
            return true;
        }

        $exists = $builder->hasTable($table);
        if ($exists) {
            $request->attributes->set($key, true);
        }

        return $exists;
    }
}
