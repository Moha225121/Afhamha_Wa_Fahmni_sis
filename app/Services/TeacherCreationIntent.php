<?php

namespace App\Services;

use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TeacherCreationIntent
{
    public function issue(int $teacherId, string $kind, mixed $previousToken = null): string
    {
        if (is_string($previousToken) && $previousToken !== '' && DB::table('teacher_creation_intents')
            ->where('token', $previousToken)->where('teacher_id', $teacherId)->where('kind', $kind)->exists()) {
            return $previousToken;
        }

        $token = (string) Str::uuid();
        DB::table('teacher_creation_intents')->insert([
            'token' => $token, 'teacher_id' => $teacherId, 'kind' => $kind,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $token;
    }

    public function once(string $token, int $teacherId, string $kind, Closure $create): int
    {
        return DB::transaction(function () use ($token, $teacherId, $kind, $create): int {
            $query = DB::table('teacher_creation_intents')
                ->where('token', $token)->where('teacher_id', $teacherId)->where('kind', $kind);
            // Lock before reading: SQLite cannot upgrade two concurrent read transactions.
            // This UPDATE also serializes requests on databases with row-level locks.
            (clone $query)->update(['updated_at' => now()]);
            $intent = (clone $query)->lockForUpdate()->first();
            if (! $intent) {
                throw ValidationException::withMessages(['creation_token' => 'افتح نموذج إنشاء جديد ثم أعد المحاولة.']);
            }
            if ($intent->resource_id !== null) {
                return (int) $intent->resource_id;
            }

            // The intent and resource commit together; failed transactions leave the intent reusable.
            $resourceId = $create();
            (clone $query)->update(['resource_id' => $resourceId, 'updated_at' => now()]);

            return $resourceId;
        }, 5);
    }
}
