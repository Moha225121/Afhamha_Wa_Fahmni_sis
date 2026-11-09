<?php

namespace App\Support;

use App\Models\Classroom;
use App\Services\LibraryCatalogService;
use Illuminate\Support\Collection;

final class AcademicClassroomOrder
{
    public static function sort(Collection $classrooms): Collection
    {
        return $classrooms->sortBy(fn (Classroom $room) => [
            LibraryCatalogService::gradeForClassroom($room) ?? 99,
            strtr(mb_strtoupper(trim((string) $room->section)), ['أ' => 'A', 'ا' => 'A', 'ب' => 'B', 'ج' => 'C']),
            $room->name,
            $room->academicYear?->name ?? '',
            $room->id,
        ])->values();
    }
}
