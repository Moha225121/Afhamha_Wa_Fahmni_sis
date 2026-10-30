<?php

namespace App\Support;

use App\Models\Assignment;
use App\Services\LessonPublicationTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class AssignmentDeadline
{
    public static function for(Assignment $assignment): ?CarbonImmutable
    {
        $dueAt = $assignment->due_at;
        $dueDate = $assignment->due_date?->toDateString();

        // The teacher's date-only form historically saved the same date in both
        // columns, making midnight look like an explicit deadline. Preserve all
        // other stored times rather than moving genuine timed deadlines.
        if ($dueDate !== null && ($dueAt === null || (
            $dueAt->toDateString() === $dueDate
            && $dueAt->format('H:i:s.u') === '00:00:00.000000'
        ))) {
            return self::fromDate($dueDate);
        }

        return $dueAt === null ? null : CarbonImmutable::instance($dueAt)->utc();
    }

    public static function fromDate(string $date): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', $date, LessonPublicationTime::timezone())
            ->endOfDay()
            ->utc();
    }

    /** Keep teacher filters and dashboard aligned with the student's server deadline. */
    public static function whereState(Builder $query, bool $closed): void
    {
        $schoolToday = now(LessonPublicationTime::timezone())->toDateString();
        $now = now();
        $query->where(function (Builder $deadline) use ($closed, $schoolToday, $now): void {
            $deadline->where(function (Builder $dateOnly) use ($closed, $schoolToday): void {
                self::whereLegacyDateOnly($dateOnly);
                $dateOnly->whereDate('assignments.due_date', $closed ? '<' : '>=', $schoolToday);
            })->orWhere(function (Builder $exact) use ($closed, $now): void {
                $exact->whereNot(fn (Builder $legacy) => self::whereLegacyDateOnly($legacy));
                if ($closed) {
                    $exact->where('assignments.due_at', '<=', $now);
                } else {
                    $exact->where(fn (Builder $available) => $available
                        ->whereNull('assignments.due_at')->orWhere('assignments.due_at', '>', $now));
                }
            });
        });
    }

    private static function whereLegacyDateOnly(Builder $query): void
    {
        $query->whereNotNull('assignments.due_date')->where(function (Builder $legacy): void {
            $legacy->whereNull('assignments.due_at')->orWhere(function (Builder $midnight): void {
                $midnight->whereDate('assignments.due_at', '=', DB::raw('date(assignments.due_date)'))
                    ->whereTime('assignments.due_at', '=', '00:00:00');
            });
        });
    }
}
