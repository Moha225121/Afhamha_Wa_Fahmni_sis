<?php

namespace App\Services;

use App\Models\Classroom;
use Illuminate\Support\Collection;

class TeacherLessonTargets
{
    public static function gradeKey(Classroom $classroom): string
    {
        $track = TeacherAssignmentGroups::gradeKey((string) $classroom->section);
        $track = in_array($track, ['علمي', 'ادبي'], true) ? $track : '';

        return $classroom->academic_year_id.':'.self::stageKey($classroom).':'.TeacherAssignmentGroups::gradeKey(self::gradeName($classroom)).':'.$track;
    }

    public static function branchLabel(Classroom $classroom): string
    {
        $section = trim((string) $classroom->section);
        if ($section === '' && preg_match('/\s+([ABCabcأابج])$/u', trim($classroom->name), $match)) {
            $section = $match[1];
        }

        return match (mb_strtoupper($section)) {
            'أ', 'ا', 'A' => 'A',
            'ب', 'B' => 'B',
            'ج', 'C' => 'C',
            default => $section,
        };
    }

    private static function gradeName(Classroom $classroom): string
    {
        $name = trim($classroom->name);
        if (preg_match('/\s+([ABCabcأابج])$/u', $name, $match)) {
            $section = trim((string) $classroom->section);
            if ($section === '' || self::branchLabel($classroom) === mb_strtoupper(strtr($match[1], ['أ' => 'A', 'ا' => 'A', 'ب' => 'B', 'ج' => 'C']))) {
                $name = preg_replace('/\s+([ABCabcأابج])$/u', '', $name);
            }
        }

        return in_array(mb_strtolower($name), ['rec', 'reception', 'reciption'], true) ? 'التمهيدي' : $name;
    }

    private static function stageKey(Classroom $classroom): string
    {
        $stage = mb_strtolower(TeacherAssignmentGroups::gradeKey($classroom->stage));

        return match ($stage) {
            'ابتدائي', 'ابتدائية', 'الابتدائي', 'الابتدائية', 'المرحلةالابتدائية', 'primary' => 'primary',
            'اعدادي', 'اعدادية', 'الاعدادي', 'الاعدادية', 'المرحلةالاعدادية', 'preparatory' => 'preparatory',
            'ثانوي', 'ثانوية', 'الثانوي', 'الثانوية', 'المرحلةالثانوية', 'secondary' => 'secondary',
            'rec', 'reciption', 'reception', 'تمهيدي', 'التمهيدي' => 'reception',
            default => $stage,
        };
    }

    public static function ordered(Collection $classrooms): Collection
    {
        $ordinals = ['الاول' => 1, 'الثاني' => 2, 'الثالث' => 3, 'الرابع' => 4, 'الخامس' => 5, 'السادس' => 6, 'السابع' => 7, 'الثامن' => 8, 'التاسع' => 9];

        return $classrooms->sortBy(function (Classroom $room) use ($ordinals): array {
            $name = TeacherAssignmentGroups::gradeKey(self::gradeName($room));
            $rank = $name === 'التمهيدي' ? 0 : 99;
            foreach ($ordinals as $ordinal => $number) {
                if ($name === 'الصف'.$ordinal || $name === $ordinal || $name === $ordinal.'ثانوي') {
                    $rank = str_contains($name, 'ثانوي') ? 9 + $number : $number;
                    break;
                }
            }

            return [$room->academicYear?->name ?? '', $rank, self::gradeKey($room), self::branchLabel($room), $room->id];
        })->values();
    }

    /** Group existing classrooms only; each room remains an individual lesson recipient. */
    public static function groups(Collection $classrooms, Collection $pairs): array
    {
        $byRoom = $pairs->groupBy('classroom_id');

        return $classrooms->groupBy(fn (Classroom $room) => self::gradeKey($room))
            ->map(function (Collection $rooms, string $key) use ($byRoom): array {
                $first = $rooms->first();
                $name = self::gradeName($first);
                $gradeName = TeacherAssignmentGroups::PRIMARY_GRADE_LABELS[TeacherAssignmentGroups::gradeKey($name)] ?? $name;

                return [
                    'key' => $key,
                    'label' => $gradeName,
                    'year' => $first->academicYear?->name ?? '',
                    'stage' => $first->stage,
                    'classroom_ids' => $rooms->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
                    'subject_ids' => $rooms->flatMap(fn ($room) => $byRoom->get($room->id, collect())->pluck('subject_id'))
                        ->map(fn ($id) => (int) $id)->unique()->values()->all(),
                ];
            })->values()->all();
    }
}
