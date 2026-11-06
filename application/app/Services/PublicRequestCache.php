<?php

namespace App\Services;

use Closure;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Support\Facades\DB;

class PublicRequestCache
{
    private const GENERATION = '_pollmedia.public_reads.generation';

    public static function remember(string $key, Closure $read): mixed
    {
        $request = app()->bound('request') ? app('request') : null;
        $connection = DB::connection();
        if (! $request || ! $request->isMethodSafe() || $request->route() === null
            || $request->is('admin', 'admin/*') || $connection->transactionLevel() !== 0
            || ! app()->bound('events')) {
            return $read();
        }

        self::listen();
        $generation = $request->attributes->get(self::GENERATION, 0);
        $attribute = '_pollmedia.public_reads.'.hash('sha256', serialize([
            spl_object_id($connection), $connection->getName(), $connection->getDatabaseName(),
            $connection->getTablePrefix(), $generation, $key,
        ]));
        if ($request->attributes->has($attribute)) {
            return $request->attributes->get($attribute);
        }

        $value = $read();
        if ($generation === $request->attributes->get(self::GENERATION, 0)) {
            $request->attributes->set($attribute, $value);
        }

        return $value;
    }

    public static function setting(string $key, int $flags = 0): array
    {
        return self::remember('setting:'.serialize([$key, $flags]), function () use ($key, $flags): array {
            $value = DB::table('site_settings')->where('key', $key)->value('value');

            return ['present' => $value !== null, 'value' => $value === null ? null : json_decode($value, true, 512, $flags)];
        });
    }

    private static function invalidate(): void
    {
        if (app()->bound('request')) {
            $attributes = app('request')->attributes;
            $attributes->set(self::GENERATION, $attributes->get(self::GENERATION, 0) + 1);
        }
    }

    private static function listen(): void
    {
        if (app()->bound('pollmedia.public-read-listener')) {
            return;
        }
        app()->instance('pollmedia.public-read-listener', true);
        app('events')->listen(QueryExecuted::class, static function (QueryExecuted $event): void {
            $table = preg_quote($event->connection->getTablePrefix().'site_settings', '~');
            if (preg_match('~\b(?:insert|update|delete|replace|truncate|alter|drop|create)\b~i', $event->sql)
                && preg_match('~\b'.$table.'\b~i', $event->sql)) {
                self::invalidate();
            }
        });
        foreach ([TransactionBeginning::class, TransactionCommitted::class, TransactionRolledBack::class] as $event) {
            app('events')->listen($event, static fn () => self::invalidate());
        }
    }
}
