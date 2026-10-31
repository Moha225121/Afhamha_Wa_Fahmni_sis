<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $gradeLabels = [
            'الصفالاول' => 'الصف الأول',
            'الصفالثاني' => 'الصف الثاني',
            'الصفالثالث' => 'الصف الثالث',
            'الصفالرابع' => 'الصف الرابع',
            'الصفالخامس' => 'الصف الخامس',
            'الصفالسادس' => 'الصف السادس',
        ];
        $normalize = static fn (?string $value): string => preg_replace(
            '/[\s\p{Z}_\-\x{064B}-\x{065F}\x{0670}\x{0640}]+/u',
            '',
            strtr(trim((string) $value), ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ى' => 'ي']),
        );
        $sectionKey = static fn (?string $section): ?string => match ($normalize($section)) {
            '', 'ا', 'A', 'a' => 'A',
            'ب', 'B', 'b' => 'B',
            'ج', 'C', 'c' => 'C',
            default => null,
        };

        DB::transaction(function () use ($gradeLabels, $normalize, $sectionKey): void {
            foreach (DB::table('academic_years')->where('is_current', true)->get() as $year) {
                $rooms = DB::table('classrooms')->where('academic_year_id', $year->id)->get();
                $primaryRoom = $rooms->first(fn ($room) => isset($gradeLabels[$normalize($room->name)]));

                foreach ($gradeLabels as $gradeKey => $label) {
                    $gradeRooms = $rooms->filter(fn ($room) => $normalize($room->name) === $gradeKey && $normalize($room->section) !== 'ادبي');
                    $source = $gradeRooms->first(fn ($room) => $sectionKey($room->section) === 'A') ?? $gradeRooms->first();
                    $subjectIds = $source ? DB::table('classroom_subject')->where('classroom_id', $source->id)->pluck('subject_id') : collect();

                    foreach (['A', 'B', 'C'] as $section) {
                        $room = $gradeRooms->first(fn ($room) => $sectionKey($room->section) === $section);
                        if (!$room) {
                            $id = DB::table('classrooms')->insertGetId([
                                'name' => $label,
                                'stage' => $source?->stage ?? $primaryRoom?->stage ?? 'أساسي',
                                'section' => $section,
                                'academic_year_id' => $year->id,
                                'created_at' => now(),
                                'updated_at' => now(),
                            ]);
                        } else {
                            $id = $room->id;
                        }
                        foreach ($subjectIds as $subjectId) {
                            DB::table('classroom_subject')->insertOrIgnore(['classroom_id' => $id, 'subject_id' => $subjectId]);
                        }
                    }
                }
            }
        });
    }

    public function down(): void
    {
        // Retain classrooms and their relationships; students may already use them.
    }
};
