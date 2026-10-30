<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Assignment;
use App\Models\AssignmentAttachment;
use App\Models\Classroom;
use App\Models\Exam;
use App\Models\Lesson;
use App\Models\LessonAttachment;
use App\Models\LessonView;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use App\Services\TeacherLessonTargets;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class AdminPublicationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Teacher $teacher;

    private Subject $subject;

    private Classroom $first;

    private Classroom $second;

    private Student $student;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo('2026-10-24 08:00:00');
        Http::preventStrayRequests();
        Notification::fake();
        config(['fcm.enabled' => false, 'services.web_push.public_key' => null, 'services.web_push.private_key' => null]);
        Storage::fake('local');
        Storage::fake('public');
        $this->admin = User::factory()->create(['name' => 'مدير تجريبي', 'role' => 'admin', 'status' => 'active']);
        $teacherUser = User::factory()->create(['name' => 'المعلمة مريم', 'role' => 'teacher', 'status' => 'active']);
        $this->teacher = Teacher::create(['user_id' => $teacherUser->id, 'status' => 'active']);
        $year = AcademicYear::create(['name' => '2026/2027', 'starts_at' => '2026-09-01', 'ends_at' => '2027-06-30', 'is_current' => true]);
        $this->first = Classroom::create(['name' => 'الصف الأول', 'section' => 'A', 'stage' => 'أساسي', 'academic_year_id' => $year->id]);
        // Existing unbranched classes must remain unbranched throughout this read-only feature.
        $this->second = Classroom::create(['name' => 'الثالث ثانوي', 'section' => null, 'stage' => 'ثانوي', 'academic_year_id' => $year->id]);
        $this->subject = Subject::create(['name' => 'اللغة العربية', 'code' => 'ADMIN-PUB-AR', 'stage' => 'أساسي', 'status' => 'active']);
        foreach ([$this->first, $this->second] as $classroom) {
            $classroom->subjects()->attach($this->subject);
            $this->teacher->classrooms()->attach($classroom, ['subject_id' => $this->subject->id]);
        }
        $studentUser = User::factory()->create(['name' => 'أحمد طالب التقرير', 'role' => 'student', 'status' => 'active']);
        $this->student = Student::create(['user_id' => $studentUser->id, 'student_number' => 'ADMIN-PUB-1', 'classroom_id' => $this->first->id, 'status' => 'active']);
    }

    private function lesson(array $attributes = []): Lesson
    {
        return Lesson::create(array_replace([
            'teacher_id' => $this->teacher->id, 'subject_id' => $this->subject->id, 'classroom_id' => $this->first->id,
            'title' => 'درس منشور للمتابعة', 'content' => 'محتوى درس كامل', 'status' => 'published', 'published_at' => now()->subHour(),
        ], $attributes));
    }

    private function assignment(array $attributes = []): Assignment
    {
        return Assignment::create(array_replace([
            'teacher_id' => $this->teacher->id, 'subject_id' => $this->subject->id, 'classroom_id' => $this->first->id,
            'title' => 'واجب منشور للمتابعة', 'description' => 'تعليمات الواجب كاملة', 'status' => 'active',
            'published_at' => now()->subHour(), 'due_at' => now()->addDays(2),
        ], $attributes));
    }

    private function exam(array $attributes = []): Exam
    {
        return Exam::create(array_replace([
            'teacher_id' => $this->teacher->id, 'subject_id' => $this->subject->id, 'classroom_id' => $this->first->id,
            'title' => 'اختبار منشور للمتابعة', 'status' => 'published', 'starts_at' => now()->subHour(), 'duration_minutes' => 40, 'total_score' => 20,
        ], $attributes));
    }

    private function sheet(?array $columns = null, ?array $scores = null, array $attributes = []): int
    {
        return DB::table('grade_sheets')->insertGetId(array_replace([
            'teacher_id' => $this->teacher->id, 'classroom_id' => $this->first->id,
            'sheet_data' => json_encode($columns ?? [['key' => 'monthly', 'title' => 'اختبار شهر أكتوبر', 'max_score' => 20]]),
            'column_scores' => json_encode($scores ?? ['monthly' => [$this->student->id => 16.5]]),
            'created_at' => now()->subHour(), 'updated_at' => now()->subHour(),
        ], $attributes));
    }

    private function index(array $filters = []): TestResponse
    {
        return $this->actingAs($this->admin)->get('/admin/publications?'.http_build_query(array_replace(['view' => 'feed'], $filters)))->assertOk();
    }

    private function keys(TestResponse $response): array
    {
        return collect($response->viewData('publication')['activities']->items())->pluck('key')->all();
    }

    private function refresh(array $filters = []): TestResponse
    {
        return $this->actingAs($this->admin)->getJson('/admin/publications/refresh?'.http_build_query(array_replace(['view' => 'feed'], $filters)))->assertOk();
    }

    public function test_publication_routes_require_an_active_admin_even_when_the_id_is_known(): void
    {
        $lesson = $this->lesson();
        $attachment = LessonAttachment::create(['lesson_id' => $lesson->id, 'title' => 'ملف', 'disk' => 'local', 'file_path' => 'lessons/admin-private.pdf']);
        $paths = ['/admin/publications', '/admin/publications/refresh', '/admin/publications/lesson/'.$lesson->id, '/admin/publications/lesson/'.$lesson->id.'/attachments/'.$attachment->id];
        foreach ($paths as $path) {
            $this->get($path)->assertRedirect('/login');
        }
        foreach (['teacher', 'parent', 'student', 'supervisor', 'staff', 'financial_officer'] as $role) {
            $user = User::factory()->create(['role' => $role, 'status' => 'active']);
            foreach ($paths as $path) {
                $this->actingAs($user)->get($path)->assertForbidden();
            }
        }
        $this->admin->update(['status' => 'inactive']);
        $this->actingAs($this->admin)->get('/admin/publications')->assertForbidden();
    }

    public function test_index_unifies_all_four_types_and_keeps_real_unbranched_classes_without_creating_records(): void
    {
        $lesson = $this->lesson();
        $assignment = $this->assignment(['classroom_id' => $this->second->id]);
        $exam = $this->exam();
        $grade = $this->sheet();
        $before = DB::table('classrooms')->orderBy('id')->get()->toJson();
        $response = $this->index();
        $this->assertEqualsCanonicalizing(['lesson:'.$lesson->id, 'assignment:'.$assignment->id, 'exam:'.$exam->id, 'grade:'.$grade], $this->keys($response));
        $response->assertSeeText($this->first->name)->assertSeeText($this->second->name)->assertSeeText('المعلمة مريم');
        $classroom = collect($response->viewData('publication')['classrooms'])->firstWhere('id', $this->second->id);
        $this->assertNull($classroom['section']);
        $this->assertStringNotContainsString(' A', $classroom['label']);
        $this->assertSame($before, DB::table('classrooms')->orderBy('id')->get()->toJson());
    }

    public function test_default_feed_excludes_drafts_future_publications_and_cancelled_records_but_admin_can_inspect_them(): void
    {
        $published = $this->lesson();
        $draft = $this->lesson(['title' => 'مسودة خاصة', 'status' => 'draft', 'published_at' => null]);
        $missingTime = $this->lesson(['title' => 'غير منشور دون تاريخ', 'published_at' => null]);
        $scheduled = $this->lesson(['title' => 'موعد مستقبلي', 'published_at' => now()->addMinute()]);
        $cancelled = $this->assignment(['title' => 'واجب ملغى', 'status' => 'cancelled']);
        $scheduledExam = $this->exam(['title' => 'اختبار مجدول', 'status' => 'scheduled', 'starts_at' => now()->addDay()]);
        $response = $this->index();
        $this->assertSame(['lesson:'.$published->id], $this->keys($response));
        $this->assertEqualsCanonicalizing(['lesson:'.$draft->id, 'lesson:'.$missingTime->id], $this->keys($this->index(['status' => 'draft'])));
        $this->assertEqualsCanonicalizing(['lesson:'.$scheduled->id, 'exam:'.$scheduledExam->id], $this->keys($this->index(['status' => 'scheduled'])));
        $this->assertSame(['assignment:'.$cancelled->id], $this->keys($this->index(['status' => 'archived'])));
        $this->assertCount(6, $this->keys($this->index(['status' => 'all'])));
        $this->actingAs($this->admin)->get('/admin/publications/lesson/'.$draft->id)->assertOk()->assertSeeText($draft->title);
    }

    public function test_multiple_classroom_pivots_are_filterable_and_count_each_publication_once(): void
    {
        $lesson = $this->lesson();
        $assignment = $this->assignment();
        $lesson->classrooms()->attach([$this->first->id, $this->second->id]);
        $assignment->classrooms()->attach([$this->first->id, $this->second->id]);
        $expected = ['lesson:'.$lesson->id, 'assignment:'.$assignment->id];
        $this->assertEqualsCanonicalizing($expected, $this->keys($this->index()));
        foreach ([$this->first, $this->second] as $room) {
            $response = $this->index(['classroom_id' => $room->id]);
            $this->assertEqualsCanonicalizing($expected, $this->keys($response));
            $this->assertSame(2, $response->viewData('publication')['activities']->total());
        }
        $detail = $this->actingAs($this->admin)->get('/admin/publications/lesson/'.$lesson->id)->assertOk();
        $detail->assertSeeText($this->first->name)->assertSeeText($this->second->name);
    }

    public function test_class_cards_count_each_target_once_and_filters_work_before_choosing_a_class(): void
    {
        $lesson = $this->lesson();
        $lesson->classrooms()->attach([$this->first->id, $this->second->id]);
        $this->assignment();
        $response = $this->index(['view' => 'classes', 'type' => 'lesson']);
        $classes = collect($response->viewData('publication')['classrooms'])->keyBy('id');
        foreach ([$this->first->id, $this->second->id] as $classroomId) {
            $this->assertSame(1, $classes[$classroomId]['counts']['lesson']);
            $this->assertSame(0, $classes[$classroomId]['counts']['assignment']);
            $this->assertSame(1, $classes[$classroomId]['counts']['total']);
        }
        $this->assertSame(1, $response->viewData('publication')['totals']['total']);
    }

    public function test_empty_status_is_the_default_public_feed_and_a_to_date_can_be_used_without_a_from_date(): void
    {
        $published = $this->lesson();
        $this->lesson(['title' => 'مسودة لا تظهر ضمن المنشور', 'status' => 'draft', 'published_at' => null]);
        $this->assertSame(['lesson:'.$published->id], $this->keys($this->index(['status' => ''])));
        $this->assertSame(['lesson:'.$published->id], $this->keys($this->index(['to' => '2026-10-24'])));
    }

    public function test_search_and_type_teacher_subject_class_and_dates_are_combined_as_and_filters(): void
    {
        $target = $this->lesson(['title' => 'مراجعة الهمزة المطلوبة']);
        $this->lesson(['title' => 'مراجعة الهمزة خارج الصف', 'classroom_id' => $this->second->id]);
        $this->assignment(['title' => 'مراجعة الهمزة واجب']);
        $this->lesson(['title' => 'مراجعة الهمزة قديم', 'published_at' => now()->subDays(10)]);
        $otherUser = User::factory()->create(['name' => 'معلم آخر', 'role' => 'teacher', 'status' => 'active']);
        $otherTeacher = Teacher::create(['user_id' => $otherUser->id, 'status' => 'active']);
        $this->lesson(['title' => 'مراجعة الهمزة معلم آخر', 'teacher_id' => $otherTeacher->id]);
        $filters = ['q' => 'الهمزة', 'type' => 'lesson', 'teacher_id' => $this->teacher->id, 'subject_id' => $this->subject->id, 'classroom_id' => $this->first->id, 'from' => '2026-10-24', 'to' => '2026-10-24'];
        $this->assertSame(['lesson:'.$target->id], $this->keys($this->index($filters)));
        $this->assertCount(5, $this->keys($this->index(['q' => 'الهمزة'])));
        $this->assertCount(4, $this->keys($this->index(['q' => 'مريم'])));
        $this->assertSame([], $this->keys($this->index(['q' => 'عبارة غير موجودة إطلاقاً'])));
    }

    public function test_grade_sheet_shows_actual_zero_and_fractional_marks_without_inventing_a_subject_or_percentage(): void
    {
        $secondSubject = Subject::create(['name' => 'الرياضيات', 'code' => 'ADMIN-PUB-MATH', 'stage' => 'أساسي', 'status' => 'active']);
        $this->first->subjects()->attach($secondSubject);
        $this->teacher->classrooms()->attach($this->first, ['subject_id' => $secondSubject->id]);
        $id = $this->sheet([
            ['key' => 'zero', 'title' => 'اختبار الصفر', 'max_score' => 20],
            ['key' => 'fraction', 'title' => 'اختبار الكسور', 'max_score' => 25],
        ], ['zero' => [$this->student->id => 0], 'fraction' => [$this->student->id => 18.5]]);
        $response = $this->index(['type' => 'grade']);
        $row = $response->viewData('publication')['activities']->items()[0];
        $this->assertNull($row['subject']);
        $this->assertSame('grade:'.$id, $row['key']);
        $detail = $this->actingAs($this->admin)->get('/admin/publications/grade/'.$id)->assertOk();
        $publication = $detail->viewData('post');
        $this->assertEquals(0, $publication['grade_rows'][0]['scores']['zero']['score']);
        $this->assertEquals(18.5, $publication['grade_rows'][0]['scores']['fraction']['score']);
        $this->assertEquals(25, $publication['grade_rows'][0]['scores']['fraction']['max_score']);
        $detail->assertSeeText('اختبار الصفر')->assertSeeText('اختبار الكسور')->assertSeeText('أحمد طالب التقرير');
    }

    public function test_unknown_students_invalid_marks_and_malformed_legacy_grade_json_do_not_break_details_or_leak_marks(): void
    {
        $otherUser = User::factory()->create(['role' => 'student', 'status' => 'active', 'name' => 'طالب صف آخر خاص']);
        $outside = Student::create(['user_id' => $otherUser->id, 'student_number' => 'ADMIN-PUB-OTHER', 'classroom_id' => $this->second->id, 'status' => 'active']);
        $id = $this->sheet([
            ['key' => 'valid', 'title' => '<script>عمود خاص</script>', 'max_score' => 20],
            ['key' => 'over', 'title' => 'درجة غير صالحة', 'max_score' => 20],
        ], ['valid' => [$this->student->id => 18, $outside->id => 19, 999999 => 12], 'over' => [$this->student->id => 21]]);
        $response = $this->actingAs($this->admin)->get('/admin/publications/grade/'.$id)->assertOk();
        $rows = $response->viewData('post')['grade_rows'];
        $this->assertCount(1, $rows);
        $this->assertArrayNotHasKey('over', $rows[0]['scores']);
        $response->assertDontSeeText('طالب صف آخر خاص')->assertDontSee('<script>عمود خاص</script>', false);
        foreach (['42', 'null', '"legacy scalar"', '{}'] as $json) {
            DB::table('grade_sheets')->where('id', $id)->update(['sheet_data' => $json, 'column_scores' => $json]);
            $this->actingAs($this->admin)->get('/admin/publications/grade/'.$id)->assertOk();
            $this->assertSame([], $this->keys($this->index(['type' => 'grade'])));
        }
    }

    public function test_grade_feed_classification_matches_detail_for_malformed_and_duplicate_column_keys(): void
    {
        $id = $this->sheet();
        foreach ([
            [[['key' => 'bad key', 'title' => 'مفتاح قديم غير صالح', 'max_score' => 20]], ['bad key' => [$this->student->id => 10]]],
            [[['key' => 1, 'title' => 'مفتاح رقمي غير صالح', 'max_score' => 20]], [1 => [$this->student->id => 10]]],
            [[['key' => 'same', 'title' => 'التعريف الأول', 'max_score' => 5], ['key' => 'same', 'title' => 'تعريف مكرر', 'max_score' => 20]], ['same' => [$this->student->id => 10]]],
        ] as [$columns, $scores]) {
            DB::table('grade_sheets')->where('id', $id)->update(['sheet_data' => json_encode($columns), 'column_scores' => json_encode($scores)]);
            $this->assertSame([], $this->keys($this->index(['type' => 'grade'])));
            $response = $this->actingAs($this->admin)->get('/admin/publications/grade/'.$id)->assertOk();
            $this->assertSame([], $response->viewData('post')['grade_rows']);
        }

        DB::table('grade_sheets')->where('id', $id)->update([
            'sheet_data' => json_encode([['key' => 'same', 'title' => 'التعريف الأول الصحيح', 'max_score' => 20], ['key' => 'same', 'title' => 'تعريف مكرر', 'max_score' => 5]]),
            'column_scores' => json_encode(['same' => [$this->student->id => 10]]),
        ]);
        $this->assertSame(['grade:'.$id], $this->keys($this->index(['type' => 'grade'])));
        $detail = $this->get('/admin/publications/grade/'.$id)->assertOk()->viewData('post');
        $this->assertCount(1, $detail['grade_columns']);
        $this->assertSame('التعريف الأول الصحيح', $detail['grade_columns'][0]['label']);
        $this->assertEquals(20, $detail['grade_rows'][0]['scores']['same']['max_score']);
    }

    public function test_refresh_is_stable_without_changes_and_reflects_inserts_updates_and_a_scheduled_time_boundary(): void
    {
        $lesson = $this->lesson();
        $before = $this->refresh()->json();
        $this->assertNotEmpty($before['version']);
        $this->assertIsString($before['html']);
        $this->assertSame($before['version'], $this->refresh()->json('version'));
        $immediate = $this->assignment(['title' => 'واجب أضيف الآن']);
        $inserted = $this->refresh()->json();
        $this->assertNotSame($before['version'], $inserted['version']);
        $this->assertStringContainsString($immediate->title, $inserted['html']);
        $scheduled = $this->lesson(['title' => 'درس ينتظر موعده', 'published_at' => now()->addSeconds(15)]);
        $afterInsert = $this->refresh()->json();
        // A published-only result need not change for an invisible future draft.
        $this->travel(16)->seconds();
        $due = $this->refresh()->json();
        $this->assertNotSame($afterInsert['version'], $due['version']);
        $this->assertStringContainsString($scheduled->title, $due['html']);
        $lesson->update(['title' => 'محتوى محدث فوراً']);
        $updated = $this->refresh()->json();
        $this->assertNotSame($due['version'], $updated['version']);
        $this->assertStringContainsString('محتوى محدث فوراً', $updated['html']);
        $this->assertSame($updated['version'], $this->refresh()->json('version'));
    }

    public function test_admin_refresh_sees_a_lesson_saved_through_the_real_teacher_publication_workflow(): void
    {
        $filters = ['view' => 'feed', 'classroom_id' => $this->first->id, 'type' => 'lesson'];
        $before = $this->refresh($filters)->json('version');
        $classCount = Classroom::count();
        $this->actingAs($this->teacher->user)->post(route('teacher.lessons.store'), [
            'grade_key' => TeacherLessonTargets::gradeKey($this->first),
            'branch_scope' => 'all', 'subject_id' => $this->subject->id,
            'title' => 'درس أرسلته المعلمة الآن من واجهتها', 'content' => 'شرح جديد من معلم فعلي',
            'status' => 'published', 'publish_mode' => 'now',
        ])->assertRedirect(route('teacher.lessons.index'))->assertSessionHasNoErrors();

        $lesson = Lesson::sole();
        $this->assertSame($this->teacher->id, $lesson->teacher_id);
        $this->assertTrue($lesson->published_at->equalTo(now()));
        $response = $this->refresh($filters + ['version' => $before]);
        $response->assertJson(['changed' => true]);
        $this->assertStringContainsString($lesson->title, $response->json('html'));
        $this->assertStringContainsString('المعلمة مريم', $response->json('html'));
        $this->assertSame($classCount, Classroom::count());
    }

    public function test_duplicate_timestamps_have_stable_non_overlapping_pagination_and_refresh_keeps_the_requested_page(): void
    {
        for ($i = 0; $i < 31; $i++) {
            $this->lesson(['title' => 'درس الترتيب '.$i]);
        }
        $first = $this->index(['type' => 'lesson', 'view' => 'feed']);
        $second = $this->index(['type' => 'lesson', 'view' => 'feed', 'page' => 2]);
        $firstKeys = $this->keys($first);
        $secondKeys = $this->keys($second);
        $this->assertNotEmpty($secondKeys);
        $this->assertSame([], array_intersect($firstKeys, $secondKeys));
        $this->assertSame($firstKeys, $this->keys($this->index(['type' => 'lesson', 'view' => 'feed'])));
        $refresh = $this->refresh(['type' => 'lesson', 'view' => 'feed', 'page' => 2]);
        foreach ($second->viewData('publication')['activities']->items() as $row) {
            $this->assertStringContainsString($row['title'], $refresh->json('html'));
        }
    }

    public function test_known_versions_avoid_resending_html_and_detail_versions_track_same_second_content_edits(): void
    {
        $lesson = $this->lesson();
        $index = $this->refresh();
        $this->refresh(['version' => $index->json('version')])->assertJson(['changed' => false, 'html' => null]);
        $url = '/admin/publications/lesson/'.$lesson->id;
        $initial = $this->actingAs($this->admin)->getJson($url)->assertOk()->json('version');
        $this->assertSame($initial, $this->getJson($url)->assertOk()->json('version'));
        $lesson->update(['content' => 'تعديل المحتوى في نفس الثانية']);
        $this->assertNotSame($initial, $this->getJson($url)->assertOk()->json('version'));
    }

    public function test_refreshed_pagination_links_open_the_normal_index_and_never_leak_polling_version_parameters(): void
    {
        for ($i = 0; $i < 25; $i++) {
            $this->lesson(['title' => 'درس روابط التصفح '.$i]);
        }
        $html = $this->refresh(['type' => 'lesson', 'classroom_id' => $this->first->id, 'version' => 'stale-browser-version'])->json('html');
        $this->assertStringContainsString('page=2', $html);
        $this->assertStringContainsString('/admin/publications?', $html);
        $this->assertStringNotContainsString('/admin/publications/refresh', $html);
        $this->assertStringNotContainsString('version=', $html);
        $this->assertStringNotContainsString('stale-browser-version', $html);
    }

    public function test_reading_list_details_refresh_and_attachments_never_changes_publication_or_delivery_state(): void
    {
        $lesson = $this->lesson();
        $assignment = $this->assignment();
        $exam = $this->exam(['status' => 'scheduled', 'starts_at' => now()->subMinute()]);
        $sheet = $this->sheet();
        LessonView::create(['lesson_id' => $lesson->id, 'student_id' => $this->student->id, 'first_viewed_at' => now()->subHour(), 'last_viewed_at' => now()->subHour()]);
        Storage::disk('local')->put('lessons/read-only.pdf', "%PDF-1.4\nTest data");
        $attachment = LessonAttachment::create(['lesson_id' => $lesson->id, 'title' => 'مرفق للقراءة', 'disk' => 'local', 'file_path' => 'lessons/read-only.pdf', 'original_name' => 'read-only.pdf']);
        $tables = ['lessons', 'assignments', 'exams', 'grade_sheets', 'lesson_views', 'notifications', 'exam_attempts', 'assignment_submissions', 'classrooms'];
        $snapshot = fn () => collect($tables)->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()])->all();
        $before = $snapshot();
        $this->travel(5)->minutes();
        $this->index(['status' => 'all']);
        $this->refresh(['status' => 'all']);
        foreach (['lesson' => $lesson->id, 'assignment' => $assignment->id, 'exam' => $exam->id, 'grade' => $sheet] as $type => $id) {
            $this->actingAs($this->admin)->get('/admin/publications/'.$type.'/'.$id)->assertOk();
        }
        $this->get('/admin/publications/lesson/'.$lesson->id.'/attachments/'.$attachment->id)->assertOk();
        $this->assertSame($before, $snapshot());
        Notification::assertNothingSent();
        Http::assertNothingSent();
    }

    public function test_owned_attachments_download_while_cross_publication_ids_and_unsafe_paths_are_rejected(): void
    {
        $lesson = $this->lesson();
        $otherLesson = $this->lesson(['title' => 'درس آخر']);
        Storage::disk('local')->put('lessons/admin-safe.pdf', "%PDF-1.4\nAdmin attachment");
        $attachment = LessonAttachment::create(['lesson_id' => $lesson->id, 'title' => 'مرفق آمن', 'disk' => 'local', 'file_path' => 'lessons/admin-safe.pdf', 'original_name' => 'admin-safe.pdf']);
        $this->actingAs($this->admin)->get('/admin/publications/lesson/'.$lesson->id.'/attachments/'.$attachment->id)->assertOk();
        $this->get('/admin/publications/lesson/'.$otherLesson->id.'/attachments/'.$attachment->id)->assertNotFound();
        foreach (['../.env', '/etc/passwd', 'C:/private.env', 'lessons/../../.env', 'https://outside.test/file.pdf'] as $path) {
            $attachment->update(['file_path' => $path]);
            $this->get('/admin/publications/lesson/'.$lesson->id.'/attachments/'.$attachment->id)->assertNotFound();
        }
        $attachment->update(['disk' => 's3', 'file_path' => 'lessons/admin-safe.pdf']);
        $this->get('/admin/publications/lesson/'.$lesson->id.'/attachments/'.$attachment->id)->assertNotFound();
    }

    public function test_legacy_assignment_attachment_path_is_owned_by_the_requested_assignment(): void
    {
        $assignment = $this->assignment();
        $other = $this->assignment(['title' => 'واجب آخر']);
        Storage::disk('local')->put('assignments/legacy-owned.pdf', "%PDF-1.4\nLegacy assignment file");
        $attachment = AssignmentAttachment::create(['assignment_id' => $assignment->id, 'path' => 'assignments/legacy-owned.pdf', 'disk' => null, 'original_name' => 'legacy-owned.pdf', 'mime_type' => 'application/pdf']);
        $this->actingAs($this->admin)->get('/admin/publications/assignment/'.$assignment->id.'/attachments/'.$attachment->id)->assertOk();
        $this->get('/admin/publications/assignment/'.$other->id.'/attachments/'.$attachment->id)->assertNotFound();
    }

    public function test_legacy_primary_assignment_file_uses_the_requested_owner_and_rejects_path_traversal(): void
    {
        Storage::disk('local')->put('assignments/legacy-primary.pdf', "%PDF-1.4\nLegacy primary file");
        $assignment = $this->assignment(['attachment_path' => 'assignments/legacy-primary.pdf']);
        $other = $this->assignment(['title' => 'واجب بلا مرفق']);
        $this->actingAs($this->admin)->get('/admin/publications/assignment/'.$assignment->id.'/attachments/legacy')->assertOk();
        $this->get('/admin/publications/assignment/'.$other->id.'/attachments/legacy')->assertNotFound();
        $assignment->update(['attachment_path' => '../.env']);
        $this->get('/admin/publications/assignment/'.$assignment->id.'/attachments/legacy')->assertNotFound();
    }

    public function test_arabic_attachment_names_work_and_active_html_is_downloaded_with_browser_sandbox_headers(): void
    {
        $lesson = $this->lesson();
        Storage::disk('local')->put('lessons/page.html', '<html><script>alert("must not run")</script></html>');
        $attachment = LessonAttachment::create(['lesson_id' => $lesson->id, 'title' => 'صفحة مرفقة', 'disk' => 'local', 'file_path' => 'lessons/page.html', 'original_name' => 'شرح الدرس.html']);
        $response = $this->actingAs($this->admin)->get('/admin/publications/lesson/'.$lesson->id.'/attachments/'.$attachment->id)
            ->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('Content-Security-Policy', "default-src 'none'; sandbox");
        $this->assertStringStartsWith('attachment;', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString("filename*=utf-8''", $response->headers->get('Content-Disposition'));
    }

    public function test_pdf_preview_uses_the_authenticated_owned_file_and_raw_or_forced_download_remains_available(): void
    {
        $lesson = $this->lesson();
        $other = $this->lesson(['title' => 'درس آخر ليس مالك المرفق']);
        $path = 'lessons/admin-preview.pdf';
        Storage::disk('local')->put($path, "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF");
        $attachment = LessonAttachment::create(['lesson_id' => $lesson->id, 'title' => 'معاينة الكتاب', 'disk' => 'local', 'file_path' => $path, 'original_name' => 'شرح الطالب.pdf', 'mime_type' => 'application/pdf']);
        $url = '/admin/publications/lesson/'.$lesson->id.'/attachments/'.$attachment->id;
        $preview = $this->actingAs($this->admin)->get($url.'?preview=1')->assertOk()
            ->assertSee('data-library-reader-canvas', false)->assertSee('js/library-reader.js', false)
            ->assertDontSee($path, false)->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertSame(url($url), $preview->viewData('fileUrl'));
        $this->assertSame('شرح الطالب.pdf', $preview->viewData('name'));
        $raw = $this->get($url)->assertOk()->assertHeader('Content-Type', 'application/pdf')->assertHeader('Content-Security-Policy', "frame-ancestors 'self'");
        $this->assertStringStartsWith('inline;', $raw->headers->get('Content-Disposition'));
        $download = $this->get($url.'?preview=1&download=1')->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('attachment;', $download->headers->get('Content-Disposition'));
        $this->get('/admin/publications/lesson/'.$other->id.'/attachments/'.$attachment->id.'?preview=1')->assertNotFound();
        $this->actingAs($this->teacher->user)->get($url.'?preview=1')->assertForbidden();

        // A claimed PDF MIME type must never turn active HTML into a preview page.
        Storage::disk('local')->put($path, '<html><script>alert("active document")</script></html>');
        $html = $this->actingAs($this->admin)->get($url.'?preview=1')->assertOk()
            ->assertHeader('Content-Security-Policy', "default-src 'none'; sandbox");
        $this->assertStringStartsWith('attachment;', $html->headers->get('Content-Disposition'));
    }

    public function test_ownerless_curriculum_content_is_not_misattributed_to_a_teacher(): void
    {
        $this->lesson(['teacher_id' => null, 'title' => 'محتوى عام ليس من معلم']);
        $this->assertSame([], $this->keys($this->index()));
    }

    public function test_historical_details_preserve_a_deleted_class_name_without_restoring_or_inventing_a_branch(): void
    {
        $lesson = $this->lesson(['classroom_id' => $this->second->id]);
        $this->second->delete();
        $before = DB::table('classrooms')->where('id', $this->second->id)->first();
        $response = $this->actingAs($this->admin)->get('/admin/publications/lesson/'.$lesson->id)->assertOk();
        $this->assertSame('الثالث ثانوي', $response->viewData('post')['classrooms'][0]['label']);
        $this->assertSame((array) $before, (array) DB::table('classrooms')->where('id', $this->second->id)->first());
        $classIds = collect($this->index(['view' => 'classes'])->viewData('publication')['classrooms'])->pluck('id')->all();
        $this->assertNotContains($this->second->id, $classIds);
    }

    public function test_detail_escapes_script_content_and_supports_safe_rich_text_without_executables(): void
    {
        $lesson = $this->lesson(['title' => '<script>alert("title")</script>', 'content' => '<p>نص درس آمن</p><script>alert("body")</script><img src=x onerror="alert(1)"><a href="javascript:alert(2)">رابط</a>']);
        $response = $this->actingAs($this->admin)->get('/admin/publications/lesson/'.$lesson->id)->assertOk();
        $response->assertSeeText('نص درس آمن')->assertDontSee('<script>alert(', false)->assertDontSee('onerror="alert(1)"', false)->assertDontSee('href="javascript:', false);
        $this->index()->assertDontSee('<script>alert("title")</script>', false);
    }

    public function test_oversized_publication_and_attachment_ids_return_not_found_instead_of_server_errors(): void
    {
        $lesson = $this->lesson();
        $this->actingAs($this->admin);

        // A digit-only route constraint alone accepts values that cannot be passed to an int argument.
        foreach (['9223372036854775808', str_repeat('9', 80)] as $oversizedId) {
            $this->get('/admin/publications/lesson/'.$oversizedId)->assertNotFound();
            $this->get('/admin/publications/lesson/'.$oversizedId.'/attachments/1')->assertNotFound();
        }

        $this->get('/admin/publications/lesson/'.$lesson->id.'/attachments/'.str_repeat('9', 80))->assertNotFound();
    }

    public function test_missing_ids_unsupported_types_and_invalid_filters_fail_cleanly(): void
    {
        $this->actingAs($this->admin);
        foreach (['lesson', 'assignment', 'grade', 'exam', 'teacher', 'notifications'] as $type) {
            $this->get('/admin/publications/'.$type.'/999999')->assertNotFound();
        }
        foreach ([['type' => 'notifications'], ['status' => 'invented'], ['classroom_id' => -1], ['from' => 'not-a-date'], ['from' => '2026-10-25', 'to' => '2026-10-24'], ['page' => -2], ['version' => ['bad']], ['version' => str_repeat('x', 129)]] as $filters) {
            $this->getJson('/admin/publications/refresh?'.http_build_query($filters))->assertUnprocessable();
        }
    }
}
