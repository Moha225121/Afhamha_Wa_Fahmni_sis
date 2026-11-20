<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Classroom;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TeacherAssignmentFormTest extends TestCase
{
    use RefreshDatabase;

    public function test_six_classrooms_can_be_assigned_and_edited_without_losing_the_last_three(): void
    {
        [$admin, $subject, $rooms] = $this->assignmentFixture();
        $data = $this->teacherData($subject, $rooms->take(6));

        $this->actingAs($admin)->get(route('admin.teachers.create'))
            ->assertOk()->assertSee('name="assignments[5][classroom_id]"', false)
            ->assertDontSee('name="assignments[6][classroom_id]"', false);
        $this->post(route('admin.teachers.store'), $data)->assertSessionHasNoErrors()->assertRedirect();
        $teacher = Teacher::whereHas('user', fn ($query) => $query->where('name', $data['name']))->firstOrFail();
        $this->assertSame(6, $teacher->classrooms()->count());

        $this->get(route('admin.teachers.edit', $teacher))->assertOk()
            ->assertSee('name="assignments[5][classroom_id]"', false)
            ->assertViewHas('displayAssignments', fn ($rows) => $rows->count() === 6);
        $data['email'] = $teacher->user->email;
        $data['name'] = 'معلم ستة صفوف بعد التعديل';
        $this->put(route('admin.teachers.update', $teacher), $data)->assertSessionHasNoErrors()->assertRedirect();
        $this->assertEqualsCanonicalizing($rooms->take(6)->pluck('id')->all(), $teacher->classrooms()->pluck('classrooms.id')->all());
    }

    public function test_new_seventh_assignment_is_rejected_on_create_and_update(): void
    {
        [$admin, $subject, $rooms] = $this->assignmentFixture();
        $data = $this->teacherData($subject, $rooms);
        $this->actingAs($admin)->post(route('admin.teachers.store'), $data)->assertSessionHasErrors('assignments');
        $this->assertDatabaseCount('teachers', 0);

        $this->post(route('admin.teachers.store'), $this->teacherData($subject, $rooms->take(6)))
            ->assertSessionHasNoErrors()->assertRedirect();
        $teacher = Teacher::firstOrFail();
        $data['email'] = $teacher->user->email;
        $this->put(route('admin.teachers.update', $teacher), $data)->assertSessionHasErrors('assignments');
        $this->assertSame(6, $teacher->classrooms()->count());
    }

    public function test_teacher_can_switch_from_a_b_c_to_e_f_with_a_subject_only_offered_in_those_sections(): void
    {
        [$admin, $arabic, $rooms] = $this->assignmentFixture(1);
        $grade = $rooms->first();
        $grade->update(['name' => 'الصف الأول']);
        $this->actingAs($admin)->post(route('admin.classes.sections.store'), [
            'source_classroom_id' => $grade->id,
            'sections' => ['B', 'C', 'E', 'F'],
        ])->assertSessionHasNoErrors()->assertRedirect();

        $sections = Classroom::where('name', $grade->name)->get();
        $this->post(route('admin.teachers.store'), $this->teacherData($arabic, $sections->whereIn('section', ['A', 'B', 'C'])))
            ->assertSessionHasNoErrors()->assertRedirect();
        $teacher = Teacher::firstOrFail();
        $science = Subject::create(['name' => 'العلوم', 'code' => 'SCI', 'stage' => $grade->stage, 'status' => 'active']);
        $targets = $sections->whereIn('section', ['E', 'F'])->values();
        foreach ($targets as $section) {
            $section->subjects()->attach($science);
        }

        $edit = $this->get(route('admin.teachers.edit', $teacher))->assertOk();
        foreach ($targets as $section) {
            $option = collect($edit->viewData('classroomOptions'))->firstWhere('id', (string) $section->id);
            $this->assertStringContainsString($grade->name, $option['label']);
            $this->assertStringContainsString($section->section, $option['label']);
            $edit->assertSeeText($option['label']);
            $this->assertContains($science->id, $edit->viewData('classroomSubjects')[$section->id]);
        }
        $this->assertNotContains($science->id, $edit->viewData('classroomSubjects')['all:'.$grade->id]);

        $data = $this->teacherData($science, $targets) + ['email' => $teacher->user->email];
        $this->put(route('admin.teachers.update', $teacher), $data)->assertSessionHasNoErrors()->assertRedirect();
        $this->assertEqualsCanonicalizing($targets->modelKeys(), $teacher->classrooms()->pluck('classrooms.id')->all());
        $this->assertSame(2, $teacher->subjects()->where('subjects.id', $science->id)->count());
        $edit = $this->get(route('admin.teachers.edit', $teacher))->assertOk();
        $this->assertEqualsCanonicalizing($targets->modelKeys(), $edit->viewData('displayAssignments')->pluck('classroom_id')->all());

        foreach ([route('admin.teachers.show', $teacher), route('admin.teachers.index')] as $url) {
            $this->get($url)->assertOk()
                ->assertSeeText($grade->name.' E')->assertSeeText($grade->name.' F')
                ->assertDontSeeText($grade->name.' A');
        }
        $this->actingAs($teacher->user)->get(route('teacher.classes.index'))->assertOk()
            ->assertSeeText('الفصل E')->assertSeeText('الفصل F')
            ->assertDontSeeText('الفصل A')->assertDontSeeText('الفصل B')->assertDontSeeText('الفصل C');
    }

    public function test_changing_to_a_new_section_without_a_subject_keeps_the_saved_assignment(): void
    {
        [$admin, $subject, $rooms] = $this->assignmentFixture(1);
        $grade = $rooms->first();
        $sectionE = Classroom::create(['name' => $grade->name, 'stage' => $grade->stage, 'section' => 'E', 'academic_year_id' => $grade->academic_year_id]);
        $data = $this->teacherData($subject, $rooms);
        $this->actingAs($admin)->post(route('admin.teachers.store'), $data)->assertSessionHasNoErrors();
        $teacher = Teacher::firstOrFail();
        $data['email'] = $teacher->user->email;
        $data['assignments'] = [['classroom_id' => $sectionE->id]];
        $editUrl = route('admin.teachers.edit', $teacher);

        $this->from($editUrl)->put(route('admin.teachers.update', $teacher), $data)
            ->assertRedirect($editUrl)->assertSessionHasErrors('assignments.0.subject_id')
            ->assertSessionHasInput('assignments.0.classroom_id', $sectionE->id);
        $this->get($editUrl)->assertOk()->assertSee('value="'.$sectionE->id.'" selected', false);
        $this->assertDatabaseCount('teacher_assignments', 1);
        $this->assertDatabaseHas('teacher_assignments', ['teacher_id' => $teacher->id, 'classroom_id' => $grade->id, 'subject_id' => $subject->id]);
    }

    public function test_invalid_subject_messages_identify_the_row_and_distinguish_a_section_from_the_whole_grade(): void
    {
        [$admin, $subject, $rooms] = $this->assignmentFixture(1);
        $grade = $rooms->first();
        $sectionE = Classroom::create(['name' => $grade->name, 'stage' => $grade->stage, 'section' => 'E', 'academic_year_id' => $grade->academic_year_id]);
        $data = $this->teacherData($subject, $rooms);
        $data['assignments'] = [
            ['classroom_id' => $sectionE->id, 'subject_id' => $subject->id],
            ['classroom_id' => 'all:'.$grade->id, 'subject_id' => $subject->id],
        ];

        $this->actingAs($admin)->post(route('admin.teachers.store'), $data)
            ->assertSessionHasErrors(['assignments.0.subject_id', 'assignments.1.subject_id']);
        $errors = session('errors');
        $individualError = $errors->first('assignments.0.subject_id');
        $groupError = $errors->first('assignments.1.subject_id');
        $this->assertStringContainsString('1', $individualError);
        $this->assertStringContainsString('E', $individualError);
        $this->assertStringNotContainsString('جميع', $individualError);
        $this->assertStringContainsString('2', $groupError);
        $this->assertStringContainsString('جميع', $groupError);
        $this->assertDatabaseCount('teachers', 0);
    }

    public function test_six_all_section_selections_expand_and_round_trip_without_using_extra_slots(): void
    {
        [$admin, $subject, $rooms] = $this->assignmentFixture(6);
        foreach ($rooms as $room) {
            foreach (['B', 'C'] as $section) {
                $branch = Classroom::create(['name' => $room->name, 'section' => $section, 'stage' => $room->stage, 'academic_year_id' => $room->academic_year_id]);
                $branch->subjects()->attach($subject);
            }
        }
        $data = $this->teacherData($subject, $rooms);
        foreach ($data['assignments'] as &$assignment) {
            $assignment['classroom_id'] = 'all:'.$assignment['classroom_id'];
        }
        unset($assignment);

        $this->actingAs($admin)->post(route('admin.teachers.store'), $data)->assertSessionHasNoErrors()->assertRedirect();
        $teacher = Teacher::firstOrFail();
        $this->assertSame(18, $teacher->classrooms()->count());
        $this->get(route('admin.teachers.edit', $teacher))->assertOk()
            ->assertViewHas('displayAssignments', fn ($rows) => $rows->count() === 6);
        $data['email'] = $teacher->user->email;
        $this->put(route('admin.teachers.update', $teacher), $data)->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame(18, $teacher->classrooms()->count());
    }

    public function test_existing_assignments_above_six_remain_visible_and_can_be_preserved(): void
    {
        [$admin, $subject, $rooms] = $this->assignmentFixture(8);
        $teacher = Teacher::create(['user_id' => User::factory()->create(['role' => 'teacher'])->id, 'status' => 'active']);
        foreach ($rooms->take(7) as $room) {
            DB::table('teacher_assignments')->insert(['teacher_id' => $teacher->id, 'classroom_id' => $room->id, 'subject_id' => $subject->id]);
        }
        $this->actingAs($admin)->get(route('admin.teachers.edit', $teacher))->assertOk()
            ->assertSee('name="assignments[6][classroom_id]"', false);
        $data = $this->teacherData($subject, $rooms->take(7)) + ['email' => $teacher->user->email];
        $this->put(route('admin.teachers.update', $teacher), $data)->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame(7, $teacher->classrooms()->count());

        $data['assignments'][6]['classroom_id'] = $rooms->last()->id;
        $this->put(route('admin.teachers.update', $teacher), $data)->assertSessionHasErrors('assignments');
        $this->assertEqualsCanonicalizing($rooms->take(7)->pluck('id')->all(), $teacher->classrooms()->pluck('classrooms.id')->all());
    }

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
            ->assertOk()->assertViewHas('displayAssignments', fn ($rows) => $rows->count() === 1 && $rows->first()->classroom_id === $groupKey)
            ->assertSee('value="'.$groupKey.'" selected', false);

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

    private function assignmentFixture(int $count = 7): array
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $year = AcademicYear::create(['name' => '2026/2027', 'starts_at' => '2026-09-01', 'ends_at' => '2027-06-30', 'is_current' => true]);
        $subject = Subject::create(['name' => 'اللغة العربية', 'code' => 'AR-1', 'stage' => 'أساسي', 'status' => 'active']);
        $rooms = collect(range(1, $count))->map(function ($grade) use ($year, $subject) {
            $room = Classroom::create(['name' => 'صف '.$grade, 'section' => 'A', 'stage' => 'أساسي', 'academic_year_id' => $year->id]);
            $room->subjects()->attach($subject);
            return $room;
        });

        return [$admin, $subject, $rooms];
    }

    private function teacherData(Subject $subject, $rooms): array
    {
        return ['name' => 'معلم ستة صفوف', 'first_name_en' => 'Six', 'last_name_en' => 'Teacher', 'status' => 'active',
            'assignments' => $rooms->map(fn ($room) => ['classroom_id' => $room->id, 'subject_id' => $subject->id])->values()->all()];
    }
}
