<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Classroom;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use App\Services\TeacherAssignmentGroups;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PrimaryClassBranchesMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_completes_six_grades_without_duplicates_and_preserves_existing_students(): void
    {
        $year = AcademicYear::create(['name' => '2026/2027', 'starts_at' => '2026-09-01', 'ends_at' => '2027-06-30', 'is_current' => true]);
        $history = AcademicYear::create(['name' => '2025/2026', 'starts_at' => '2025-09-01', 'ends_at' => '2026-06-30', 'is_current' => false]);
        $sources = collect();
        foreach (TeacherAssignmentGroups::PRIMARY_GRADE_LABELS as $key => $name) {
            $room = Classroom::create(['name' => $name, 'stage' => 'أساسي', 'section' => 'أ', 'academic_year_id' => $year->id]);
            $subject = Subject::create(['name' => 'مادة '.$name, 'code' => 'GRADE-'.$room->id, 'stage' => 'أساسي', 'status' => 'active']);
            $room->subjects()->attach($subject);
            $sources[$key] = $room;
        }
        Classroom::create(['name' => 'الصف الاول', 'stage' => 'أساسي', 'section' => 'ب', 'academic_year_id' => $year->id]);
        $seventh = Classroom::create(['name' => 'الصف السابع', 'stage' => 'أساسي', 'section' => 'أ', 'academic_year_id' => $year->id]);
        Classroom::create(['name' => 'الصف الأول', 'stage' => 'أساسي', 'section' => 'أ', 'academic_year_id' => $history->id]);
        $student = Student::create(['user_id' => User::factory()->create(['role' => 'student'])->id, 'student_number' => 'st-branch-check', 'classroom_id' => $sources->first()->id, 'status' => 'active']);

        $migration = require database_path('migrations/2026_10_05_000001_complete_primary_classroom_branches.php');
        $migration->up();
        $rooms = Classroom::with('subjects')->where('academic_year_id', $year->id)->get();
        foreach ($sources as $key => $source) {
            $branches = $rooms->filter(fn ($room) => TeacherAssignmentGroups::gradeKey($room->name) === $key);
            $this->assertCount(3, $branches);
            foreach ($branches as $branch) {
                $this->assertEqualsCanonicalizing($source->subjects->modelKeys(), $branch->subjects->modelKeys());
            }
        }
        $this->assertSame($sources->first()->id, $student->fresh()->classroom_id);
        $this->assertSame('أ', $seventh->fresh()->section);
        $this->assertSame(1, Classroom::where('academic_year_id', $history->id)->count());

        $before = [Classroom::count(), DB::table('classroom_subject')->count()];
        $migration->up();
        $this->assertSame($before, [Classroom::count(), DB::table('classroom_subject')->count()]);

        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $this->actingAs($admin)->get(route('admin.teachers.create'))->assertOk()
            ->assertViewHas('classroomLabels', function ($labels) {
                foreach (TeacherAssignmentGroups::PRIMARY_GRADE_LABELS as $name) {
                    foreach (['A', 'B', 'C'] as $section) {
                        if (!in_array($name.' '.$section, $labels, true)) {
                            return false;
                        }
                    }
                }
                return in_array('الصف السابع', $labels, true) && !in_array('الصف السابع A', $labels, true);
            })
            ->assertViewHas('classroomGroups', fn ($groups) => count($groups) === 6);
    }
}
