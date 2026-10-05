<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Classroom;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TeacherAssignmentFormTest extends TestCase
{
    use RefreshDatabase;

    public function test_one_grade_assignment_covers_every_branch_and_reopens_as_one_row(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $year = AcademicYear::create(['name' => '2026/2027', 'starts_at' => '2026-09-01', 'ends_at' => '2027-06-30', 'is_current' => true]);
        $subject = Subject::create(['name' => 'اللغة العربية', 'code' => 'AR-1', 'stage' => 'أساسي', 'status' => 'active']);
        $rooms = collect(['A', 'B', 'C'])->map(function ($section) use ($year, $subject) {
            $room = Classroom::create(['name' => 'الصف الأول', 'section' => $section, 'stage' => 'أساسي', 'academic_year_id' => $year->id]);
            $room->subjects()->attach($subject);
            return $room;
        });
        $groupKey = 'all:'.$rooms->first()->id;
        $data = ['name' => 'معلم لجميع الشعب', 'first_name_en' => 'Group', 'last_name_en' => 'Teacher', 'status' => 'active', 'assignments' => [['classroom_id' => $groupKey, 'subject_id' => $subject->id]]];

        $this->actingAs($admin)->post(route('admin.teachers.store'), $data)->assertSessionHasNoErrors()->assertRedirect();
        $teacher = \App\Models\Teacher::whereHas('user', fn ($query) => $query->where('name', $data['name']))->firstOrFail();
        $this->assertEqualsCanonicalizing($rooms->pluck('id')->all(), $teacher->classrooms()->pluck('classrooms.id')->all());
        $this->actingAs($admin)->get(route('admin.teachers.edit', $teacher))
            ->assertOk()->assertViewHas('displayAssignments', fn ($rows) => $rows->count() === 1 && $rows->first()->classroom_id === $groupKey);

        $rooms->last()->subjects()->detach($subject);
        $data['name'] = 'إسناد غير صالح لجميع الشعب';
        $this->actingAs($admin)->post(route('admin.teachers.store'), $data)->assertSessionHasErrors('assignments.0.subject_id');
        $this->assertDatabaseMissing('users', ['name' => $data['name']]);
    }

    public function test_teacher_assignment_rejects_a_subject_not_linked_to_the_selected_classroom(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $year = AcademicYear::create([
            'name' => '2026/2027',
            'starts_at' => '2026-09-01',
            'ends_at' => '2027-06-30',
            'is_current' => true,
        ]);
        $firstClass = Classroom::create([
            'name' => 'الصف السابع',
            'stage' => 'أساسي',
            'section' => 'أ',
            'academic_year_id' => $year->id,
        ]);
        $secondClass = Classroom::create([
            'name' => 'الصف الثامن',
            'stage' => 'أساسي',
            'section' => 'أ',
            'academic_year_id' => $year->id,
        ]);
        $subject = Subject::create([
            'name' => 'مادة الصف الثامن',
            'code' => 'GRADE-8-ONLY',
            'stage' => 'أساسي',
            'status' => 'active',
        ]);
        $secondClass->subjects()->attach($subject);

        $this->actingAs($admin)->post(route('admin.teachers.store'), [
            'name' => 'معلم تجريبي',
            'first_name_en' => 'Test',
            'last_name_en' => 'Teacher',
            'status' => 'active',
            'assignments' => [[
                'classroom_id' => $firstClass->id,
                'subject_id' => $subject->id,
            ]],
        ])->assertSessionHasErrors('assignments.0.subject_id');

        $this->assertDatabaseMissing('users', ['name' => 'معلم تجريبي']);
    }
}
