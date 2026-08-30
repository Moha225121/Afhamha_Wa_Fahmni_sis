<?php

namespace App\Enums;

enum AttendanceStatus: string
{
    case Present = 'present';
    case Absent = 'absent';
    case Late = 'late';
    case ExcusedAbsence = 'excused_absence';
    case ExcusedLate = 'excused_late';

    public function label(): string
    {
        return match ($this) { self::Present => 'حاضر', self::Absent => 'غائب', self::Late => 'متأخر', self::ExcusedAbsence => 'غياب بعذر', self::ExcusedLate => 'تأخير بعذر' };
    }

    public function needsExcuse(): bool { return in_array($this, [self::ExcusedAbsence, self::ExcusedLate], true); }
    public function isLate(): bool { return in_array($this, [self::Late, self::ExcusedLate], true); }
}
