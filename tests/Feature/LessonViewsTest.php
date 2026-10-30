<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Classroom;
use App\Models\Lesson;
use App\Models\LessonAttachment;
use App\Models\LessonView;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class LessonViewsTest extends TestCase
{
    use RefreshDatabase;

    private User $teacherUser;

    private Teacher $teacher;

    private Subject $subject;

    private array $rooms = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-13 08:00:00');
        $year = AcademicYear::create([
            'name' => '2026/2027',
            'starts_at' => '2026-09-01',
            'ends_at' => '2027-06-30',
            'is_current' => true,
        ]);
        $this->teacherUser = User::factory()->create(['role' => 'teacher', 'status' => 'active']);
        $this->teacher = Teacher::create(['user_id' => $this->teacherUser->id, 'status' => 'active']);
        $this->subject = Subject::create([
            'name' => 'اللغة العربية',
            'code' => 'ARABIC-VIEWS',
            'stage' => 'ابتدائي',
            'status' => 'active',
        ]);
        foreach (['A', 'B', 'C'] as $section) {
            $room = Classroom::create([
                'name' => 'الصف الأول',
                'section' => $section,
                'stage' => 'ابتدائي',
                'academic_year_id' => $year->id,
            ]);
            $room->subjects()->attach($this->subject);
            DB::table('teacher_assignments')->insert([
                'teacher_id' => $this->teacher->id,
                'classroom_id' => $room->id,
                'subject_id' => $this->subject->id,
            ]);
            $this->rooms[$section] = $room;
        }
    }

    public function test_reopening_a_lesson_keeps_one_view_and_its_first_time_but_advances_last_time(): void
    {
        $student = $this->student('A', 'Reader');
        $lesson = $this->lesson(['A']);
        $firstOpened = now()->copy();

        $this->openLesson($student, $lesson);

        $view = LessonView::query()->sole();
        $this->assertSame($lesson->id, $view->lesson_id);
        $this->assertSame($student->id, $view->student_id);
        $this->assertTrue($view->first_viewed_at->equalTo($firstOpened));
        $this->assertTrue($view->last_viewed_at->equalTo($firstOpened));

        $this->travel(12)->minutes();
        $this->openLesson($student, $lesson);

        $this->assertDatabaseCount('lesson_views', 1);
        $view->refresh();
        $this->assertTrue($view->first_viewed_at->equalTo($firstOpened));
        $this->assertTrue($view->last_viewed_at->equalTo(now()));
    }

    public function test_unique_views_are_separate_for_each_student_and_lesson(): void
    {
        $studentA = $this->student('A', 'Reader A');
        $studentC = $this->student('C', 'Reader C');
        $lesson = $this->lesson(['A', 'C']);
        $otherLesson = $this->lesson(['A']);

        $this->openLesson($studentA, $lesson);
        $this->openLesson($studentC, $lesson);
        $this->openLesson($studentA, $lesson);
        $this->openLesson($studentA, $otherLesson);

        $this->assertDatabaseCount('lesson_views', 3);
        $this->assertSame(2, $lesson->views()->count());
        $this->assertSame(1, $otherLesson->views()->count());
    }

    public function test_lists_and_attachment_downloads_do_not_create_or_refresh_a_view(): void
    {
        Storage::fake('local');
        $student = $this->student('A', 'Reader');
        $lesson = $this->lesson(['A']);
        Storage::disk('local')->put('lesson-views/worksheet.pdf', '%PDF-1.4 test worksheet');
        $attachment = LessonAttachment::create([
            'lesson_id' => $lesson->id,
            'title' => 'ورقة عمل',
            'disk' => 'local',
            'file_path' => 'lesson-views/worksheet.pdf',
            'original_name' => 'worksheet.pdf',
        ]);
        $this->actingAs($student->user);

        $this->get(route('student.subjects.index'))->assertOk();
        $this->get(route('student.subjects.show', $this->subject))->assertOk();
        $this->get(route('student.lessons.index', $this->subject))->assertOk()->assertSeeText($lesson->title);
        $this->get(route('student.lessons.attachments.download', [$this->subject, $lesson, $attachment]))
            ->assertOk()->assertDownload('worksheet.pdf');
        $this->assertDatabaseCount('lesson_views', 0);

        $this->openLesson($student, $lesson);
        $opened = now()->copy();
        $this->travel(5)->minutes();
        $this->get(route('student.lessons.index', $this->subject))->assertOk();
        $this->get(route('student.lessons.attachments.download', [$this->subject, $lesson, $attachment]))->assertOk();

        $view = LessonView::query()->sole();
        $this->assertTrue($view->first_viewed_at->equalTo($opened));
        $this->assertTrue($view->last_viewed_at->equalTo($opened));
    }

    public function test_wrong_branch_and_subject_requests_do_not_record_views(): void
    {
        $student = $this->student('B', 'Excluded reader');
        $lesson = $this->lesson(['A', 'C']);
        $this->actingAs($student->user)
            ->get(route('student.lessons.show', [$this->subject, $lesson]))->assertNotFound();

        $otherSubject = Subject::create(['name' => 'الرياضيات', 'code' => 'MATH-VIEWS', 'stage' => 'ابتدائي', 'status' => 'active']);
        $otherSubject->classrooms()->attach($this->rooms['B']);
        $this->get(route('student.lessons.show', [$otherSubject, $lesson]))->assertNotFound();

        $targetedLesson = $this->lesson(['B']);
        $this->rooms['B']->subjects()->detach($this->subject);
        $this->get(route('student.lessons.show', [$this->subject, $targetedLesson]))->assertNotFound();
        $this->assertDatabaseCount('lesson_views', 0);
    }

    public function test_draft_future_cancelled_and_invalid_subject_lessons_never_record_views(): void
    {
        $student = $this->student('A', 'Reader');
        $this->actingAs($student->user);
        foreach ([
            ['status' => 'draft', 'published_at' => null],
            ['status' => 'published', 'published_at' => now()->addHour()],
            ['status' => 'cancelled'],
        ] as $attributes) {
            $lesson = $this->lesson(['A'], $attributes);
            $this->get(route('student.lessons.show', [$this->subject, $lesson]))->assertNotFound();
        }

        $otherSubject = Subject::create(['name' => 'العلوم', 'code' => 'SCIENCE-VIEWS', 'stage' => 'ابتدائي', 'status' => 'active']);
        $wrongUnit = Unit::create(['subject_id' => $otherSubject->id, 'title' => 'وحدة أخرى', 'position' => 1]);
        $malformedLesson = $this->lesson(['A'], ['unit_id' => $wrongUnit->id]);
        $this->get(route('student.lessons.show', [$this->subject, $malformedLesson]))->assertNotFound();

        $inactiveSubjectLesson = $this->lesson(['A']);
        $this->subject->update(['status' => 'inactive']);
        $this->get(route('student.lessons.show', [$this->subject, $inactiveSubjectLesson]))->assertNotFound();
        $this->assertDatabaseCount('lesson_views', 0);
    }

    public function test_guests_other_roles_and_deleted_students_cannot_record_views(): void
    {
        $lesson = $this->lesson(['A']);
        $url = route('student.lessons.show', [$this->subject, $lesson]);
        $this->get($url)->assertRedirect(route('login'));
        $this->actingAs($this->teacherUser)->get($url)->assertForbidden();

        $inactiveStudent = $this->student('A', 'Inactive reader');
        $inactiveStudent->update(['status' => 'inactive']);
        $this->actingAs($inactiveStudent->user)->get($url)->assertForbidden();

        $student = $this->student('A', 'Deleted reader');
        $student->delete();
        $this->actingAs($student->user)->get($url)->assertForbidden();
        $this->assertDatabaseCount('lesson_views', 0);
    }

    public function test_report_keeps_historical_viewers_after_class_transfer_or_soft_deletion(): void
    {
        $movedStudent = $this->student('A', 'Transferred reader');
        $deletedStudent = $this->student('C', 'Archived reader');
        $this->student('A', 'Has not opened');
        $lesson = $this->lesson(['A', 'C']);
        $this->openLesson($movedStudent, $lesson);
        $this->openLesson($deletedStudent, $lesson);
        $movedStudent->update(['classroom_id' => $this->rooms['B']->id]);
        $deletedStudent->delete();

        $response = $this->actingAs($this->teacherUser)
            ->get(route('teacher.lessons.views', $lesson))->assertOk();

        $this->assertSame(2, $response->viewData('summary')['viewed']);
        $viewers = $response->viewData('viewers');
        $this->assertSame(2, $viewers->total());
        $this->assertEqualsCanonicalizing([$movedStudent->id, $deletedStudent->id], $viewers->pluck('student_id')->all());
        $response->assertSeeText('Transferred reader')->assertSeeText('Archived reader')->assertDontSeeText('Has not opened');
        $this->assertNotNull($viewers->firstWhere('student_id', $deletedStudent->id)->student);

        $index = $this->get(route('teacher.lessons.index'))->assertOk();
        $this->assertSame(2, $index->viewData('lessons')->firstWhere('id', $lesson->id)->views_count);
        $this->assertDatabaseCount('lesson_views', 2);
    }

    public function test_report_search_matches_name_or_number_without_changing_summary_or_leaking_other_lessons(): void
    {
        $alice = $this->student('A', 'Alice Reader', 'ST-ALPHA-001');
        $bob = $this->student('C', 'Bob Reader', 'ST-BETA-002');
        $otherReader = $this->student('A', 'Alice Outside', 'ST-ALPHA-OUTSIDE');
        $lesson = $this->lesson(['A', 'C']);
        $otherLesson = $this->lesson(['A']);
        $this->openLesson($alice, $lesson);
        $this->openLesson($bob, $lesson);
        $this->openLesson($otherReader, $otherLesson);
        $this->actingAs($this->teacherUser);

        foreach (['Alice' => [$alice->id], 'BETA-002' => [$bob->id], 'Nobody matches' => []] as $query => $expectedIds) {
            $response = $this->get(route('teacher.lessons.views', ['lesson' => $lesson->id, 'q' => $query]))->assertOk();
            $this->assertSame(2, $response->viewData('summary')['viewed']);
            $this->assertSame($expectedIds, $response->viewData('viewers')->pluck('student_id')->all());
            $response->assertDontSeeText('Alice Outside');
        }
    }

    public function test_only_the_owning_teacher_can_see_a_lesson_view_report(): void
    {
        $student = $this->student('A', 'Private reader');
        $lesson = $this->lesson(['A']);
        $this->openLesson($student, $lesson);
        $otherTeacherUser = User::factory()->create(['role' => 'teacher', 'status' => 'active']);
        $otherTeacher = Teacher::create(['user_id' => $otherTeacherUser->id, 'status' => 'active']);
        DB::table('teacher_assignments')->insert([
            'teacher_id' => $otherTeacher->id,
            'classroom_id' => $this->rooms['A']->id,
            'subject_id' => $this->subject->id,
        ]);

        $this->actingAs($otherTeacherUser)->get(route('teacher.lessons.views', $lesson))
            ->assertNotFound()->assertDontSeeText('Private reader');
        $this->actingAs($student->user)->get(route('teacher.lessons.views', $lesson))->assertForbidden();
        $this->actingAs($this->teacherUser)->get(route('teacher.lessons.views', $lesson))
            ->assertOk()->assertSeeText('Private reader');
        $this->assertDatabaseCount('lesson_views', 1);
    }

    public function test_view_rows_are_removed_when_their_student_or_lesson_is_permanently_deleted(): void
    {
        $first = $this->student('A', 'First reader');
        $second = $this->student('C', 'Second reader');
        $lesson = $this->lesson(['A', 'C']);
        $this->openLesson($first, $lesson);
        $this->openLesson($second, $lesson);

        $first->forceDelete();
        $this->assertDatabaseMissing('lesson_views', ['lesson_id' => $lesson->id, 'student_id' => $first->id]);
        $this->assertDatabaseHas('lesson_views', ['lesson_id' => $lesson->id, 'student_id' => $second->id]);

        $lesson->delete();
        $this->assertDatabaseCount('lesson_views', 0);
    }

    private function student(string $section, string $name, ?string $number = null): Student
    {
        $user = User::factory()->create(['name' => $name, 'role' => 'student', 'status' => 'active']);

        return Student::create([
            'user_id' => $user->id,
            'student_number' => $number ?? 'ST-VIEW-'.$user->id,
            'classroom_id' => $this->rooms[$section]->id,
            'status' => 'active',
        ]);
    }

    private function lesson(array $sections, array $attributes = []): Lesson
    {
        $lesson = Lesson::create(array_replace([
            'teacher_id' => $this->teacher->id,
            'classroom_id' => $this->rooms[$sections[0]]->id,
            'subject_id' => $this->subject->id,
            'title' => 'درس القراءة '.(Lesson::count() + 1),
            'content' => 'محتوى الدرس',
            'status' => 'published',
            'published_at' => now()->subMinute(),
        ], $attributes));
        $lesson->classrooms()->attach(array_map(fn ($section) => $this->rooms[$section]->id, $sections));

        return $lesson;
    }

    private function openLesson(Student $student, Lesson $lesson): void
    {
        $this->actingAs($student->user)->get(route('student.lessons.show', [$this->subject, $lesson]))->assertOk();
    }
}
