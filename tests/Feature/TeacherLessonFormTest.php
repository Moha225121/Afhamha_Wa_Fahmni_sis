<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Classroom;
use App\Models\Lesson;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use App\Services\TeacherLessonTargets;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TeacherLessonFormTest extends TestCase
{
    use RefreshDatabase;

    private User $teacherUser;

    private Teacher $teacher;

    private Subject $subject;

    private array $rooms;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 13)->setTime(8, 0));
        $year = AcademicYear::create(['name' => '2026/2027', 'starts_at' => '2026-09-01', 'ends_at' => '2027-06-30']);
        $this->teacherUser = User::factory()->create(['role' => 'teacher', 'status' => 'active']);
        $this->teacher = Teacher::create(['user_id' => $this->teacherUser->id, 'status' => 'active']);
        $this->subject = Subject::create(['name' => 'اللغة العربية', 'code' => 'ARABIC-TEST', 'stage' => 'ابتدائي', 'status' => 'active']);
        foreach (['A', 'B', 'C'] as $section) {
            $room = Classroom::create(['name' => $section === 'B' ? 'الصف الاول' : 'الصف الأول', 'section' => $section, 'stage' => 'ابتدائي', 'academic_year_id' => $year->id]);
            $room->subjects()->attach($this->subject);
            $this->rooms[$section] = $room;
            DB::table('teacher_assignments')->insert(['teacher_id' => $this->teacher->id, 'classroom_id' => $room->id, 'subject_id' => $this->subject->id]);
        }
        DB::table('settings')->insert(['key' => 'timezone', 'value' => 'Africa/Tripoli', 'group' => 'system']);
    }

    private function payload(array $overrides = []): array
    {
        return array_replace([
            'grade_key' => TeacherLessonTargets::gradeKey($this->rooms['A']),
            'branch_scope' => 'selected',
            'classroom_ids' => [$this->rooms['A']->id],
            'subject_id' => $this->subject->id,
            'title' => 'قراءة النص',
            'status' => 'published',
            'publish_mode' => 'now',
        ], $overrides);
    }

    public function test_form_groups_only_existing_assigned_branches_and_keeps_years_separate(): void
    {
        $otherYear = AcademicYear::create(['name' => '2027/2028', 'starts_at' => '2027-09-01', 'ends_at' => '2028-06-30']);
        $room = Classroom::create(['name' => 'الصف الأول', 'section' => 'A', 'stage' => 'ابتدائي', 'academic_year_id' => $otherYear->id]);
        DB::table('teacher_assignments')->insert(['teacher_id' => $this->teacher->id, 'classroom_id' => $room->id, 'subject_id' => $this->subject->id]);
        $count = Classroom::count();
        $response = $this->actingAs($this->teacherUser)->get(route('teacher.lessons.create'))->assertOk();
        $groups = $response->viewData('lessonGrades');
        $this->assertCount(2, $groups);
        $this->assertEqualsCanonicalizing(array_values(array_map(fn ($room) => $room->id, $this->rooms)), $groups[0]['classroom_ids']);
        $this->assertSame($count, Classroom::count());
    }

    public function test_all_mode_resolves_every_assigned_branch_of_that_grade_on_the_server(): void
    {
        $this->actingAs($this->teacherUser)->post(route('teacher.lessons.store'), $this->payload(['branch_scope' => 'all', 'classroom_ids' => null]))
            ->assertRedirect(route('teacher.lessons.index'))->assertSessionHasNoErrors();
        $lesson = Lesson::firstOrFail();
        $this->assertEqualsCanonicalizing(array_values(array_map(fn ($room) => $room->id, $this->rooms)), $lesson->classrooms()->pluck('classrooms.id')->all());
        $this->assertSame($lesson->classrooms()->orderBy('classrooms.id')->first()->id, $lesson->classroom_id);
        $this->assertSame(3, Classroom::count());
    }

    public function test_unbranched_grade_can_receive_a_lesson_without_branch_selection(): void
    {
        $room = Classroom::create(['name' => 'الصف الثالث', 'section' => null, 'stage' => 'ابتدائي', 'academic_year_id' => $this->rooms['A']->academic_year_id]);
        DB::table('teacher_assignments')->insert(['teacher_id' => $this->teacher->id, 'classroom_id' => $room->id, 'subject_id' => $this->subject->id]);

        $this->actingAs($this->teacherUser)->post(route('teacher.lessons.store'), $this->payload([
            'grade_key' => TeacherLessonTargets::gradeKey($room),
            'branch_scope' => 'all',
            'classroom_ids' => null,
        ]))->assertRedirect(route('teacher.lessons.index'))->assertSessionHasNoErrors();

        $lesson = Lesson::firstOrFail();
        $this->assertSame($room->id, $lesson->classroom_id);
        $this->assertSame([$room->id], $lesson->classrooms()->pluck('classrooms.id')->all());
        $this->assertSame(4, Classroom::count());
    }

    public function test_selected_branch_recipients_are_visible_only_to_their_students(): void
    {
        $this->actingAs($this->teacherUser)->post(route('teacher.lessons.store'), $this->payload(['classroom_ids' => [$this->rooms['A']->id, $this->rooms['C']->id]]))
            ->assertRedirect()->assertSessionHasNoErrors();
        $lesson = Lesson::firstOrFail();
        foreach ($this->rooms as $section => $room) {
            $studentUser = User::factory()->create(['role' => 'student', 'status' => 'active']);
            Student::create(['user_id' => $studentUser->id, 'student_number' => 'S-'.$section, 'classroom_id' => $room->id, 'status' => 'active']);
            $response = $this->actingAs($studentUser)->get(route('student.lessons.index', $this->subject))->assertOk();
            $section === 'B' ? $response->assertDontSeeText($lesson->title) : $response->assertSeeText($lesson->title);
        }
    }

    public function test_all_mode_does_not_include_an_unassigned_subject_pair(): void
    {
        DB::table('teacher_assignments')->where('classroom_id', $this->rooms['B']->id)->delete();
        $this->actingAs($this->teacherUser)->post(route('teacher.lessons.store'), $this->payload(['branch_scope' => 'all']))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertEqualsCanonicalizing([$this->rooms['A']->id, $this->rooms['C']->id], Lesson::firstOrFail()->classrooms()->pluck('classrooms.id')->all());
        $this->post(route('teacher.lessons.store'), $this->payload(['classroom_ids' => [$this->rooms['B']->id]]))->assertForbidden();
    }

    public function test_grade_selection_cannot_mix_branches_from_different_grades(): void
    {
        $other = Classroom::create(['name' => 'الصف الثاني', 'section' => 'A', 'stage' => 'ابتدائي', 'academic_year_id' => $this->rooms['A']->academic_year_id]);
        DB::table('teacher_assignments')->insert(['teacher_id' => $this->teacher->id, 'classroom_id' => $other->id, 'subject_id' => $this->subject->id]);
        $this->actingAs($this->teacherUser)->post(route('teacher.lessons.store'), $this->payload(['classroom_ids' => [$this->rooms['A']->id, $other->id]]))->assertForbidden();
        $this->assertDatabaseCount('lessons', 0);
    }

    public function test_future_time_later_today_is_kept_and_shown_as_scheduled(): void
    {
        $this->actingAs($this->teacherUser)->post(route('teacher.lessons.store'), $this->payload(['publish_mode' => 'scheduled', 'published_at' => '2026-10-13T14:30']))
            ->assertRedirect()->assertSessionHasNoErrors();
        $lesson = Lesson::firstOrFail();
        $this->assertSame('2026-10-13 12:30', $lesson->published_at->format('Y-m-d H:i'));
        $this->assertSame(0, Lesson::published()->count());
        $this->get(route('teacher.lessons.index', ['status' => 'scheduled']))->assertOk()->assertSeeText('مجدول');
        $this->get(route('teacher.lessons.edit', $lesson))->assertOk()->assertSee('2026-10-13T14:30', false);
        $this->travelTo($lesson->published_at->copy()->addMinute());
        $this->assertSame(1, Lesson::published()->count());
    }

    public function test_publish_now_ignores_stale_date_and_scheduling_requires_a_future_time(): void
    {
        $this->actingAs($this->teacherUser)->post(route('teacher.lessons.store'), $this->payload(['published_at' => '2030-01-01T10:00']))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertTrue(Lesson::firstOrFail()->published_at->equalTo(now()));
        $this->post(route('teacher.lessons.store'), $this->payload(['publish_mode' => 'scheduled', 'published_at' => null]))->assertSessionHasErrors('published_at');
        $this->post(route('teacher.lessons.store'), $this->payload(['publish_mode' => 'scheduled', 'published_at' => '2026-10-13T09:00']))->assertSessionHasErrors('published_at');
        $this->post(route('teacher.lessons.store'), $this->payload(['publish_mode' => 'preserve']))->assertSessionHasErrors('publish_mode');
    }

    public function test_editing_published_content_can_keep_its_original_publication_time(): void
    {
        $lesson = Lesson::create(['teacher_id' => $this->teacher->id, 'classroom_id' => $this->rooms['A']->id, 'subject_id' => $this->subject->id, 'title' => 'قديم', 'status' => 'published', 'published_at' => now()->subDay()]);
        $lesson->classrooms()->attach($this->rooms['A']);
        $originalTime = $lesson->published_at->copy();
        $this->actingAs($this->teacherUser)->put(route('teacher.lessons.update', $lesson), $this->payload(['publish_mode' => 'preserve', 'title' => 'محدث']))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertTrue($lesson->fresh()->published_at->equalTo($originalTime));
    }

    public function test_stage_spelling_and_reception_branch_names_group_without_changing_classes(): void
    {
        $this->rooms['B']->update(['stage' => 'المرحلة الابتدائية']);
        $this->assertSame(TeacherLessonTargets::gradeKey($this->rooms['A']), TeacherLessonTargets::gradeKey($this->rooms['B']));
        $recA = new Classroom(['name' => 'REC A', 'stage' => 'Reciption', 'section' => '', 'academic_year_id' => 1]);
        $recB = new Classroom(['name' => 'REC B', 'stage' => 'Reciption', 'section' => 'B', 'academic_year_id' => 1]);
        $this->assertSame(TeacherLessonTargets::gradeKey($recA), TeacherLessonTargets::gradeKey($recB));
        $this->assertSame('A', TeacherLessonTargets::branchLabel($recA));
        $this->assertSame('B', TeacherLessonTargets::branchLabel($recB));
    }

    public function test_legacy_cross_grade_lesson_can_keep_all_existing_recipients_on_edit(): void
    {
        $other = Classroom::create(['name' => 'الصف الثاني', 'section' => 'A', 'stage' => 'ابتدائي', 'academic_year_id' => $this->rooms['A']->academic_year_id]);
        DB::table('teacher_assignments')->insert(['teacher_id' => $this->teacher->id, 'classroom_id' => $other->id, 'subject_id' => $this->subject->id]);
        $lesson = Lesson::create(['teacher_id' => $this->teacher->id, 'classroom_id' => $this->rooms['A']->id, 'subject_id' => $this->subject->id, 'title' => 'درس مشترك سابق', 'status' => 'draft']);
        $lesson->classrooms()->attach([$this->rooms['A']->id, $other->id]);
        $this->actingAs($this->teacherUser)->get(route('teacher.lessons.edit', $lesson))->assertOk()->assertSeeText('الصفوف الحالية للدرس');
        $this->put(route('teacher.lessons.update', $lesson), $this->payload(['grade_key' => null, 'classroom_ids' => [$this->rooms['A']->id, $other->id]]))
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertEqualsCanonicalizing([$this->rooms['A']->id, $other->id], $lesson->fresh()->classrooms()->pluck('classrooms.id')->all());
    }
}
