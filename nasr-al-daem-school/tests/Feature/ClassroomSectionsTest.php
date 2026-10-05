<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Classroom;
use App\Models\Subject;
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
}
