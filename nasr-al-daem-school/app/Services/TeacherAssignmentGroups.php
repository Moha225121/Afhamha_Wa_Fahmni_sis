<?php

namespace App\Services;

use App\Models\Classroom;
use Illuminate\Support\Collection;

class TeacherAssignmentGroups
{
    public static function gradeKey(string $name): string
    {
        return preg_replace('/[\s\p{Z}_\-\x{064B}-\x{065F}\x{0670}\x{0640}]+/u', '', strtr(trim($name), ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ى' => 'ي']));
    }

    public static function groups(Collection $classrooms): array
    {
        $groups = [];
        foreach ($classrooms->reject(fn (Classroom $room) => self::gradeKey((string) $room->section) === 'ادبي')
            ->groupBy(fn (Classroom $room) => $room->academic_year_id.':'.self::gradeKey($room->name)) as $rooms) {
            if ($rooms->count() > 1) {
                $groups['all:'.$rooms->min('id')] = $rooms->pluck('id')->map(fn ($id) => (int) $id)->all();
            }
        }

        return $groups;
    }

    public static function resolve(string $selection): array
    {
        if (ctype_digit($selection)) {
            return Classroom::whereKey((int) $selection)->exists() ? [(int) $selection] : [];
        }

        if (!preg_match('/^all:[1-9][0-9]*$/', $selection)) {
            return [];
        }

        return self::groups(Classroom::all())[$selection] ?? [];
    }
}
