<?php

namespace Tests\Feature;

use App\Models\Announcement;
use App\Models\Lesson;
use App\Models\LessonAttachment;
use App\Models\LibraryResource;
use App\Models\Teacher;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesStudentEducationFixtures;
use Tests\TestCase;

class StudentEducationTest extends TestCase
{
    use CreatesStudentEducationFixtures;
    use RefreshDatabase;

    public function test_student_sees_only_active_subjects_attached_to_their_classroom(): void
    {
        $studentA = $this->createStudentFixture('A');
        $studentB = $this->createStudentFixture('B');
        $own = $this->createSubjectFor($studentA['classroom'], 'OWN');
        $inactive = $this->createSubjectFor($studentA['classroom'], 'INACTIVE', 'inactive');
        $other = $this->createSubjectFor($studentB['classroom'], 'OTHER');

        $response = $this->actingAs($studentA['user'])->get(route('student.subjects.index'));

        $response->assertOk()
            ->assertSeeText($own->name)
            ->assertDontSeeText($inactive->name)
            ->assertDontSeeText($other->name);
        $this->actingAs($studentA['user'])->get(route('student.subjects.show', $own))->assertOk();
        $this->actingAs($studentA['user'])->get(route('student.subjects.show', $inactive))->assertNotFound();
        $this->actingAs($studentA['user'])->get(route('student.subjects.show', $other))->assertNotFound();
        $this->actingAs($studentA['user'])->get('/student/subjects/not-a-number')->assertNotFound();
    }

    public function test_only_published_lessons_from_the_authorized_subject_are_visible(): void
    {
        Storage::fake('local');
        $this->travelTo('2026-08-23 09:00:00');
        $studentA = $this->createStudentFixture('A');
        $studentB = $this->createStudentFixture('B');
        $subjectA = $this->createSubjectFor($studentA['classroom'], 'A');
        $subjectB = $this->createSubjectFor($studentB['classroom'], 'B');
        $unitA = Unit::create(['subject_id' => $subjectA->id, 'title' => 'وحدة أ', 'position' => 1]);
        $unitB = Unit::create(['subject_id' => $subjectB->id, 'title' => 'وحدة ب', 'position' => 1]);
        $published = Lesson::create([
            'subject_id' => $subjectA->id,
            'unit_id' => $unitA->id,
            'title' => 'الدرس المنشور',
            'content' => 'محتوى آمن',
            'status' => 'published',
            'published_at' => now()->subMinute(),
        ]);
        $draft = Lesson::create([
            'subject_id' => $subjectA->id,
            'unit_id' => $unitA->id,
            'title' => 'الدرس المسودة',
            'content' => 'مسودة',
            'status' => 'draft',
        ]);
        $future = Lesson::create([
            'subject_id' => $subjectA->id,
            'unit_id' => $unitA->id,
            'title' => 'الدرس المستقبلي',
            'content' => 'مستقبلي',
            'status' => 'published',
            'published_at' => now()->addDay(),
        ]);
        $other = Lesson::create([
            'subject_id' => $subjectB->id,
            'unit_id' => $unitB->id,
            'title' => 'درس صف آخر',
            'content' => 'غير مسموح',
            'status' => 'published',
            'published_at' => now()->subMinute(),
        ]);
        $malformedId = DB::table('lessons')->insertGetId([
            'subject_id' => $subjectA->id,
            'unit_id' => $unitB->id,
            'title' => 'درس بعلاقة غير متطابقة',
            'content' => 'غير مسموح',
            'status' => 'published',
            'published_at' => now()->subMinute(),
            'position' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        Storage::disk('local')->put('lessons/worksheet.pdf', 'worksheet');
        $attachment = LessonAttachment::create([
            'lesson_id' => $published->id,
            'title' => 'ورقة عمل',
            'disk' => 'local',
            'file_path' => 'lessons/worksheet.pdf',
            'original_name' => 'worksheet.pdf',
        ]);

        $response = $this->actingAs($studentA['user'])->get(route('student.lessons.index', $subjectA));

        $response->assertOk()
            ->assertSeeText($published->title)
            ->assertDontSeeText($draft->title)
            ->assertDontSeeText($future->title)
            ->assertDontSeeText($other->title)
            ->assertDontSeeText('درس بعلاقة غير متطابقة');
        $this->actingAs($studentA['user'])->get(route('student.lessons.show', [$subjectA, $published]))->assertOk();
        $this->actingAs($studentA['user'])->get(route('student.lessons.show', [$subjectA, $draft]))->assertNotFound();
        $this->actingAs($studentA['user'])->get(route('student.lessons.show', [$subjectA, $future]))->assertNotFound();
        $this->actingAs($studentA['user'])->get(route('student.lessons.show', [$subjectA, $other]))->assertNotFound();
        $this->actingAs($studentA['user'])->get(route('student.lessons.show', [$subjectA, $malformedId]))->assertNotFound();
        $this->actingAs($studentA['user'])->get(route('student.lessons.attachments.download', [$subjectA, $published, $attachment]))
            ->assertOk()
            ->assertDownload('worksheet.pdf');
        $this->actingAs($studentB['user'])->get(route('student.lessons.attachments.download', [$subjectA, $published, $attachment]))
            ->assertNotFound();
    }

    public function test_dashboard_uses_todays_class_data_and_filters_announcement_audience(): void
    {
        $this->travelTo('2026-08-23 09:00:00');
        $studentA = $this->createStudentFixture('A');
        $studentB = $this->createStudentFixture('B');
        $subjectA = $this->createSubjectFor($studentA['classroom'], 'DASH');
        $subjectB = $this->createSubjectFor($studentB['classroom'], 'OTHER-DASH');
        $teacherUser = User::factory()->create(['role' => 'teacher', 'status' => 'active']);
        $teacher = Teacher::create(['user_id' => $teacherUser->id, 'status' => 'active']);
        DB::table('schedules')->insert([
            'classroom_id' => $studentA['classroom']->id,
            'teacher_id' => $teacher->id,
            'subject_id' => $subjectA->id,
            'day_of_week' => now()->dayOfWeek,
            'starts_at' => '08:00',
            'ends_at' => '09:00',
            'room' => 'قاعة 1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('schedules')->insert([
            'classroom_id' => $studentB['classroom']->id,
            'teacher_id' => $teacher->id,
            'subject_id' => $subjectB->id,
            'day_of_week' => now()->dayOfWeek,
            'starts_at' => '10:00',
            'ends_at' => '11:00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $admin = $this->createAdmin();
        Announcement::create(['title' => 'تنبيه الصف المسموح', 'content' => 'مسموح', 'audience' => 'classroom', 'classroom_id' => $studentA['classroom']->id, 'status' => 'published', 'published_at' => now(), 'created_by' => $admin->id]);
        Announcement::create(['title' => 'تنبيه المعلمين المخفي', 'content' => 'مخفي', 'audience' => 'teachers', 'classroom_id' => $studentA['classroom']->id, 'status' => 'published', 'published_at' => now(), 'created_by' => $admin->id]);
        Announcement::create(['title' => 'تنبيه صف آخر', 'content' => 'مخفي', 'audience' => 'classroom', 'classroom_id' => $studentB['classroom']->id, 'status' => 'published', 'published_at' => now(), 'created_by' => $admin->id]);

        $this->actingAs($studentA['user'])->get(route('student.dashboard'))
            ->assertOk()
            ->assertSeeText('الخدمات الأكاديمية')
            ->assertSeeText('آخر النتائج')
            ->assertSeeText($subjectA->name)
            ->assertDontSeeText($subjectB->name)
            ->assertSeeText('قاعة 1')
            ->assertSeeText('تنبيه الصف المسموح')
            ->assertDontSeeText('تنبيه المعلمين المخفي')
            ->assertDontSeeText('تنبيه صف آخر');
    }

    public function test_missing_subject_assignments_have_clear_empty_states_and_dashboard_links(): void
    {
        $student = $this->createStudentFixture('EMPTY');
        $this->actingAs($student['user'])->get(route('student.subjects.index'))
            ->assertOk()->assertSeeText('لم تُربط مواد نشطة بصفك حتى الآن.');
        $this->get(route('student.dashboard'))->assertOk()
            ->assertSeeText($student['user']->name)->assertSeeText($student['classroom']->name)
            ->assertSeeText('لا توجد مواد نشطة مرتبطة بصفك حاليًا.')
            ->assertSee(route('student.subjects.index'), false)
            ->assertSee(route('student.library.index'), false)
            ->assertSee(route('student.tutor.index'), false);
        $subject = $this->createSubjectFor($student['classroom'], 'NOW-LINKED');
        $this->get(route('student.subjects.index'))->assertOk()->assertSeeText($subject->name);
        $this->get(route('student.dashboard'))->assertOk()->assertSeeText($subject->name);
        $student['student']->update(['classroom_id' => null]);
        $this->get(route('student.subjects.index'))->assertOk()->assertSeeText('بدون صف')->assertDontSeeText($subject->name);
        $this->get(route('student.subjects.show', $subject))->assertNotFound();
    }

    public function test_attachments_obey_the_same_class_and_publication_restrictions_as_the_lesson(): void
    {
        Storage::fake('local');
        $student = $this->createStudentFixture('SAME-SUBJECT');
        $other = $this->createStudentFixture('OTHER-CLASS');
        $subject = $this->createSubjectFor($student['classroom'], 'SHARED');
        $subject->classrooms()->attach($other['classroom']);
        Storage::disk('local')->put('lessons/class-only.pdf', 'class-only');

        foreach ([
            ['classroom_id' => $other['classroom']->id, 'status' => 'published', 'published_at' => now()->subMinute()],
            ['classroom_id' => $student['classroom']->id, 'status' => 'draft', 'published_at' => null],
            ['classroom_id' => $student['classroom']->id, 'status' => 'published', 'published_at' => now()->addDay()],
        ] as $attributes) {
            $lesson = Lesson::create($attributes + ['subject_id' => $subject->id, 'title' => 'درس محظور', 'content' => 'خاص']);
            $attachment = $lesson->attachments()->create(['title' => 'خاص', 'disk' => 'local', 'file_path' => 'lessons/class-only.pdf']);
            $this->actingAs($student['user'])->get(route('student.lessons.show', [$subject, $lesson]))->assertNotFound();
            $this->get(route('student.lessons.attachments.download', [$subject, $lesson, $attachment]))->assertNotFound();
            $this->get(route('student.lessons.index', $subject))->assertOk()->assertDontSeeText('درس محظور');
        }
    }

    public function test_missing_or_invalid_lesson_files_have_a_useful_student_error(): void
    {
        Storage::fake('local');
        $student = $this->createStudentFixture('FILES');
        $subject = $this->createSubjectFor($student['classroom'], 'FILES');
        $lesson = Lesson::create(['subject_id' => $subject->id, 'classroom_id' => $student['classroom']->id,
            'title' => 'درس الملفات', 'content' => 'محتوى الدرس', 'status' => 'published', 'published_at' => now()->subMinute()]);
        $attachment = $lesson->attachments()->create(['title' => 'ملف', 'disk' => 'local', 'file_path' => 'lessons/missing.pdf']);
        $this->actingAs($student['user']);
        foreach (['lessons/missing.pdf', '../outside.pdf', 'C:\\outside.pdf'] as $path) {
            $attachment->update(['file_path' => $path]);
            $this->get(route('student.lessons.attachments.download', [$subject, $lesson, $attachment]))
                ->assertNotFound()->assertSeeText('ملف الدرس غير متاح حاليًا')
                ->assertSee(route('student.lessons.show', [$subject, $lesson]), false);
        }
        Storage::disk('local')->put('lessons/valid.pdf', 'safe worksheet');
        $attachment->update(['file_path' => 'lessons/valid.pdf', 'original_name' => 'worksheet.pdf']);
        $this->get(route('student.lessons.attachments.download', [$subject, $lesson, $attachment]))
            ->assertOk()->assertDownload('worksheet.pdf')->assertStreamedContent('safe worksheet');
        $otherLesson = Lesson::create(['subject_id' => $subject->id, 'title' => 'آخر', 'status' => 'published', 'published_at' => now()->subMinute()]);
        $this->get(route('student.lessons.attachments.download', [$subject, $otherLesson, $attachment]))->assertNotFound();
    }

    public function test_related_resources_follow_the_library_grade_access_rules(): void
    {
        $student = $this->createStudentFixture('RESOURCE-GRADE');
        $student['classroom']->update(['name' => 'الصف الأول']);
        $subject = $this->createSubjectFor($student['classroom'], 'RESOURCE-GRADE');
        $lesson = Lesson::create(['subject_id' => $subject->id, 'title' => 'درس الموارد', 'status' => 'published', 'published_at' => now()->subMinute()]);
        foreach ([1 => 'مورد صفي', 2 => 'مورد صف آخر غير متاح'] as $grade => $title) {
            LibraryResource::create(['title' => $title, 'subject_id' => $subject->id, 'grade_levels' => [$grade],
                'disk' => 'local', 'file_path' => 'library/grade-'.$grade.'.pdf', 'is_public' => true,
                'status' => 'active', 'created_by' => $student['user']->id]);
        }
        $this->actingAs($student['user']);
        foreach ([route('student.subjects.show', $subject), route('student.lessons.show', [$subject, $lesson]), route('student.library.index')] as $url) {
            $this->get($url)->assertOk()->assertSeeText('مورد صفي')->assertDontSeeText('مورد صف آخر غير متاح');
        }
    }
}
