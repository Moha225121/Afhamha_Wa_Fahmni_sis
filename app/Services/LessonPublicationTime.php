<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class LessonPublicationTime
{
    public static function timezone(): string
    {
        $timezone = DB::table('settings')->where('key', 'timezone')->value('value');

        return is_string($timezone) && in_array($timezone, timezone_identifiers_list(), true)
            ? $timezone
            : 'Africa/Tripoli';
    }

    public static function parse(string $value): Carbon
    {
        return Carbon::parse($value, self::timezone())->setTimezone(config('app.timezone'));
    }
}
