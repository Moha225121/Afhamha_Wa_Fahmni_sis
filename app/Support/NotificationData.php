<?php

namespace App\Support;

use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class NotificationData
{
    /** The notifications data column is text, so PostgreSQL must cast it before JSON access. */
    public static function field(string $key): string|Expression
    {
        if (! preg_match('/\A[a-z][a-z0-9_]*\z/', $key)) {
            throw new InvalidArgumentException('Invalid notification data key.');
        }

        return DB::connection()->getDriverName() === 'pgsql'
            ? DB::raw('("notifications"."data"::jsonb ->> \''.$key.'\')')
            : 'data->'.$key;
    }

    public static function id(int|string $id): int|string
    {
        return DB::connection()->getDriverName() === 'pgsql' ? (string) $id : $id;
    }
}
