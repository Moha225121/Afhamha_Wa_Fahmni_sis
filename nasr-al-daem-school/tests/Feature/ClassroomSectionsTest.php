<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Classroom;
use App\Models\Subject;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClassroomSectionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_add_branches_through_sixth_grade_and_inherit_its_subjects(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $year = AcademicYear::create([
            'name' => '2026/2027',
            'starts_at' => '2026-09-01',
            'ends_at' => '2027-06-30',
            'is_current' => true,
        ]);
        $grade = Classroom::create([
            'name' => 'الصف السادس',
            'stage' => 'ابتدائي',
            'section' => 'أ',
            'academic_year_id' => $year->id,
        ]);
        $subject = Subject::create([
            'name' => 'اللغة العربية',
            'code' => 'AR-1',
            'stage' => 'ابتدائي',
            'status' => 'active',
        ]);
        $grade->subjects()->attach($subject);
        Classroom::create([
            'name' => 'الصف السادس ',
            'stage' => 'ابتدائي',
            'section' => 'B',
            'academic_year_id' => $year->id,
        ]);

        $this->actingAs($admin)->post(route('admin.classes.sections.store'), [
            'source_classroom_id' => $grade->id,
            'sections' => ['B', 'C'],
        ])->assertRedirect(route('admin.classes.sections'));

        $branchC = Classroom::where('name', $grade->name)->where('section', 'C')->firstOrFail();
        $this->assertSame(1, Classroom::where('academic_year_id', $year->id)->where('section', 'B')->count());
        $this->assertSame([$subject->id], $branchC->subjects()->pluck('subjects.id')->all());

        $this->actingAs($admin)->post(route('admin.classes.sections.store'), [
            'source_classroom_id' => $grade->id,
            'sections' => ['A', 'B'],
        ])->assertRedirect(route('admin.classes.sections'));

        $this->assertSame(3, Classroom::where('academic_year_id', $year->id)->count());
    }

    public function test_branch_tool_rejects_other_grades_and_literary_classrooms(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $year = AcademicYear::create([
            'name' => '2026/2027',
            'starts_at' => '2026-09-01',
            'ends_at' => '2027-06-30',
            'is_current' => true,
        ]);
        $grade = Classroom::create([
            'name' => 'الثالث ثانوي',
            'stage' => 'ثانوي',
            'section' => 'أدبي',
            'academic_year_id' => $year->id,
        ]);

        $this->actingAs($admin)->post(route('admin.classes.sections.store'), [
            'source_classroom_id' => $grade->id,
            'sections' => ['B'],
        ])->assertStatus(422);

        $this->assertDatabaseMissing('classrooms', ['name' => $grade->name, 'section' => 'B']);
    }

    public function test_admin_can_move_a_selected_group_of_students_to_a_branch(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $year = AcademicYear::create(['name' => '2026/2027', 'starts_at' => '2026-09-01', 'ends_at' => '2027-06-30', 'is_current' => true]);
        $source = Classroom::create(['name' => 'الصف الرابع', 'stage' => 'ابتدائي', 'section' => 'A', 'academic_year_id' => $year->id]);
        $target = Classroom::create(['name' => 'الصف الرابع', 'stage' => 'ابتدائي', 'section' => 'B', 'academic_year_id' => $year->id]);
        $first = Student::create(['user_id' => User::factory()->create(['role' => 'student'])->id, 'student_number' => 'S-401', 'classroom_id' => $source->id, 'status' => 'active']);
        $second = Student::create(['user_id' => User::factory()->create(['role' => 'student'])->id, 'student_number' => 'S-402', 'classroom_id' => $source->id, 'status' => 'active']);

        $this->actingAs($admin)->put(route('admin.classes.update', $target), [
            'name' => $target->name, 'stage' => $target->stage, 'section' => $target->section,
            'academic_year_id' => $year->id, 'student_ids' => [$first->id],
        ])->assertRedirect(route('admin.classes.show', $target));

        $this->assertDatabaseHas('students', ['id' => $first->id, 'classroom_id' => $target->id]);
        $this->assertDatabaseHas('students', ['id' => $second->id, 'classroom_id' => $source->id]);
    }

    public function test_branch_transfer_rejects_students_from_other_grades_or_years(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $year = AcademicYear::create(['name' => '2026/2027', 'starts_at' => '2026-09-01', 'ends_at' => '2027-06-30', 'is_current' => true]);
        $previousYear = AcademicYear::create(['name' => '2025/2026', 'starts_at' => '2025-09-01', 'ends_at' => '2026-06-30']);
        $target = Classroom::create(['name' => 'الصف الرابع', 'stage' => 'ابتدائي', 'section' => 'B', 'academic_year_id' => $year->id]);

        foreach ([['الصف الخامس', $year->id], ['الصف الرابع', $previousYear->id]] as $index => [$grade, $yearId]) {
            $source = Classroom::create(['name' => $grade, 'stage' => 'ابتدائي', 'section' => 'A', 'academic_year_id' => $yearId]);
            $student = Student::create(['user_id' => User::factory()->create(['role' => 'student'])->id, 'student_number' => 'TRANSFER-'.$index, 'classroom_id' => $source->id, 'status' => 'active']);
            $this->actingAs($admin)->put(route('admin.classes.update', $target), [
                'name' => $target->name, 'stage' => $target->stage, 'section' => $target->section,
                'academic_year_id' => $year->id, 'student_ids' => [$student->id],
            ])->assertStatus(422);
            $this->assertDatabaseHas('students', ['id' => $student->id, 'classroom_id' => $source->id]);
        }
    }
}
