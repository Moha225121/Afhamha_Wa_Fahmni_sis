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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

class TeacherLessonPreviewTest extends TestCase
{
    use RefreshDatabase;

    private User $teacherUser;

    private Teacher $teacher;

    private Subject $subject;

    private Classroom $classroom;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-13 08:00:00');
        Storage::fake('local');
        Storage::fake('public');
        $year = AcademicYear::create([
            'name' => '2026/2027', 'starts_at' => '2026-09-01', 'ends_at' => '2027-06-30',
        ]);
        $this->classroom = Classroom::create([
            'name' => 'الصف الأول', 'section' => 'A', 'stage' => 'ابتدائي', 'academic_year_id' => $year->id,
        ]);
        $this->subject = Subject::create([
            'name' => 'اللغة العربية', 'code' => 'ARABIC-PREVIEW', 'stage' => 'ابتدائي', 'status' => 'active',
        ]);
        $this->classroom->subjects()->attach($this->subject);
        $this->teacherUser = User::factory()->create(['role' => 'teacher', 'status' => 'active']);
        $this->teacher = Teacher::create(['user_id' => $this->teacherUser->id, 'status' => 'active']);
        DB::table('teacher_assignments')->insert([
            'teacher_id' => $this->teacher->id, 'classroom_id' => $this->classroom->id, 'subject_id' => $this->subject->id,
        ]);
    }

    public function test_owner_can_preview_every_publication_state_without_changing_lessons_or_student_views(): void
    {
        $unit = Unit::create(['subject_id' => $this->subject->id, 'title' => 'وحدة القراءة', 'position' => 1]);
        $lessons = [];
        foreach ([
            'Draft' => ['status' => 'draft', 'published_at' => null],
            'Scheduled' => ['status' => 'published', 'published_at' => now()->addDay()],
            'Published' => ['status' => 'published', 'published_at' => now()->subDay()],
            'Cancelled' => ['status' => 'cancelled', 'published_at' => now()->subDay()],
        ] as $label => $attributes) {
            $lessons[] = $this->lesson($attributes + ['title' => $label.' preview lesson', 'unit_id' => $unit->id]);
        }
        $attachment = $this->attachment($lessons[0], 'worksheet.pdf', "%PDF-1.4\nprivate worksheet");
        $student = $this->student();
        LessonView::create([
            'lesson_id' => $lessons[2]->id,
            'student_id' => $student->id,
            'first_viewed_at' => now()->subHour(),
            'last_viewed_at' => now()->subHour(),
        ]);
        $before = $this->lessonRecords();
        $this->travel(15)->minutes();
        $this->actingAs($this->teacherUser);

        foreach ($lessons as $lesson) {
            $this->get(route('teacher.lessons.show', $lesson))->assertOk()
                ->assertSeeText($lesson->title)
                ->assertSeeText('Private lesson content')
                ->assertSeeText($unit->title)
                ->assertSeeText($this->subject->name)
                ->assertSeeText($this->classroom->name);
        }
        $this->get(route('teacher.lessons.attachments.download', [$lessons[0], $attachment]))->assertOk();

        $this->assertSame($before, $this->lessonRecords());
        $this->assertDatabaseCount('lesson_views', 1);
    }

    public function test_another_assigned_teacher_cannot_preview_or_download_an_owners_lesson(): void
    {
        $lesson = $this->lesson(['title' => 'Confidential preview title']);
        $attachment = $this->attachment($lesson, 'private.pdf', "%PDF-1.4\nConfidential file");
        $otherUser = User::factory()->create(['role' => 'teacher', 'status' => 'active']);
        $otherTeacher = Teacher::create(['user_id' => $otherUser->id, 'status' => 'active']);
        DB::table('teacher_assignments')->insert([
            'teacher_id' => $otherTeacher->id, 'classroom_id' => $this->classroom->id, 'subject_id' => $this->subject->id,
        ]);

        $this->actingAs($otherUser)->get(route('teacher.lessons.show', $lesson))
            ->assertNotFound()->assertDontSeeText('Confidential preview title')->assertDontSeeText('Private lesson content');
        $this->get(route('teacher.lessons.attachments.download', [$lesson, $attachment]))
            ->assertNotFound()->assertDontSeeText('Confidential file');
        $this->actingAs($this->teacherUser)->get(route('teacher.lessons.show', 999999))->assertNotFound();
        $this->assertDatabaseCount('lesson_views', 0);
    }

    public function test_guests_and_students_cannot_use_teacher_preview_routes(): void
    {
        $lesson = $this->lesson();
        $attachment = $this->attachment($lesson, 'private.pdf', "%PDF-1.4\nprivate worksheet");
        $this->get(route('teacher.lessons.show', $lesson))->assertRedirect(route('login'));
        $this->get(route('teacher.lessons.attachments.download', [$lesson, $attachment]))->assertRedirect(route('login'));

        $this->actingAs($this->student()->user)->get(route('teacher.lessons.show', $lesson))->assertForbidden();
        $this->get(route('teacher.lessons.attachments.download', [$lesson, $attachment]))->assertForbidden();
        $this->assertDatabaseCount('lesson_views', 0);
    }

    public function test_attachment_ids_are_scoped_to_the_requested_lesson_even_for_the_same_owner(): void
    {
        $lesson = $this->lesson();
        $otherLesson = $this->lesson();
        $attachment = $this->attachment($otherLesson, 'other-lesson.pdf', "%PDF-1.4\nOther lesson attachment");
        $this->actingAs($this->teacherUser);

        $this->get(route('teacher.lessons.attachments.download', [$lesson, $attachment]))
            ->assertNotFound()->assertDontSeeText('Other lesson attachment');
        $this->get(route('teacher.lessons.attachments.download', [$lesson, 999999]))->assertNotFound();
        $this->get(route('teacher.lessons.attachments.download', [$otherLesson, $attachment]))->assertOk();
        $this->assertDatabaseCount('lesson_views', 0);
    }

    public function test_pdf_raster_images_and_mp4_are_served_inline_with_detected_mime_and_nosniff(): void
    {
        $lesson = $this->lesson();
        $image = UploadedFile::fake()->image('page.jpg');
        $files = [
            ['worksheet.pdf', "%PDF-1.4\nprivate worksheet", 'application/pdf'],
            ['page.jpg', file_get_contents($image->getRealPath()), 'image/jpeg'],
            ['lesson.mp4', pack('N', 24).'ftypisom'.pack('N', 512).'isomiso2', 'video/mp4'],
        ];
        $this->actingAs($this->teacherUser);

        foreach ($files as [$name, $contents, $mime]) {
            $attachment = $this->attachment($lesson, $name, $contents, ['mime_type' => 'application/octet-stream']);
            $before = $attachment->fresh()->getRawOriginal();
            $response = $this->get(route('teacher.lessons.attachments.download', [$lesson, $attachment]))
                ->assertOk()->assertHeader('Content-Type', $mime)->assertHeader('X-Content-Type-Options', 'nosniff');
            $this->assertStringStartsWith('inline', $response->headers->get('Content-Disposition'));
            $this->assertSame($before, $attachment->fresh()->getRawOriginal());
        }
        $this->assertDatabaseCount('lesson_views', 0);
    }

    public function test_office_documents_and_mislabelled_html_are_downloaded_instead_of_rendered_inline(): void
    {
        $lesson = $this->lesson();
        $office = $this->attachment($lesson, 'notes.docx', '');
        $zip = new ZipArchive;
        $this->assertTrue($zip->open(Storage::disk('local')->path($office->file_path), ZipArchive::CREATE | ZipArchive::OVERWRITE));
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>');
        $zip->addFromString('word/document.xml', '<?xml version="1.0"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>Lesson notes</w:t></w:r></w:p></w:body></w:document>');
        $zip->close();
        $disguised = $this->attachment($lesson, 'pretend.pdf', '<!DOCTYPE html><html><body><script>alert("preview")</script></body></html>', ['mime_type' => 'application/pdf']);
        $this->actingAs($this->teacherUser);

        foreach ([$office, $disguised] as $attachment) {
            $response = $this->get(route('teacher.lessons.attachments.download', [$lesson, $attachment]))
                ->assertOk()->assertDownload()->assertHeader('X-Content-Type-Options', 'nosniff');
            $this->assertStringContainsString("filename*=utf-8''".rawurlencode($attachment->original_name), $response->headers->get('Content-Disposition'));
        }
        $this->assertDatabaseCount('lesson_views', 0);
    }

    public function test_inline_attachments_support_explicit_download_and_private_byte_range_responses(): void
    {
        $lesson = $this->lesson();
        $attachment = $this->attachment($lesson, 'worksheet.pdf', "%PDF-1.4\nprivate worksheet");
        $this->actingAs($this->teacherUser);
        $download = $this->get(route('teacher.lessons.attachments.download', [
            'lesson' => $lesson->id, 'attachment' => $attachment->id, 'download' => 1,
        ]))->assertOk()->assertDownload();
        $this->assertStringContainsString("filename*=utf-8''worksheet.pdf", $download->headers->get('Content-Disposition'));
        $this->assertStringContainsString('private', $download->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', $download->headers->get('Cache-Control'));

        $range = $this->withHeader('Range', 'bytes=0-7')
            ->get(route('teacher.lessons.attachments.download', [$lesson, $attachment]))
            ->assertStatus(206)->assertHeader('Accept-Ranges', 'bytes')->assertHeader('Content-Length', '8');
        $this->assertStringStartsWith('bytes 0-7/', $range->headers->get('Content-Range'));
        $this->assertStringStartsWith('inline', $range->headers->get('Content-Disposition'));
        $this->assertDatabaseCount('lesson_views', 0);
    }

    public function test_attachment_requests_reject_missing_files_unknown_disks_and_unsafe_paths(): void
    {
        $lesson = $this->lesson();
        $attachment = $this->attachment($lesson, 'safe.pdf', "%PDF-1.4\nprivate worksheet");
        $validPath = $attachment->file_path;
        $this->actingAs($this->teacherUser);

        foreach ([
            ['disk' => 's3', 'file_path' => $validPath],
            ['disk' => 'local', 'file_path' => 'lesson-attachments/missing.pdf'],
            ['disk' => 'local', 'file_path' => '../public/outside.pdf'],
            ['disk' => 'local', 'file_path' => '/outside.pdf'],
            ['disk' => 'local', 'file_path' => 'C:\\outside.pdf'],
            ['disk' => 'local', 'file_path' => 'lesson-attachments\\safe.pdf'],
            ['disk' => 'local', 'file_path' => 'lesson-attachments/./safe.pdf'],
        ] as $invalid) {
            $attachment->update($invalid);
            $this->get(route('teacher.lessons.attachments.download', [$lesson, $attachment]))->assertNotFound();
        }
        $this->assertDatabaseCount('lesson_views', 0);
    }

    public function test_symlink_to_a_file_outside_the_attachment_disk_is_rejected(): void
    {
        $lesson = $this->lesson();
        Storage::disk('public')->put('outside.pdf', "%PDF-1.4\nOutside private storage");
        Storage::disk('local')->makeDirectory('lesson-attachments');
        $relativeLink = 'lesson-attachments/escape.pdf';
        $link = Storage::disk('local')->path($relativeLink);
        if (! @symlink(Storage::disk('public')->path('outside.pdf'), $link)) {
            $this->markTestSkipped('Creating filesystem symlinks is unavailable on this platform.');
        }

        try {
            $attachment = LessonAttachment::create([
                'lesson_id' => $lesson->id, 'title' => 'escape.pdf', 'original_name' => 'escape.pdf',
                'disk' => 'local', 'file_path' => $relativeLink,
            ]);
            $this->actingAs($this->teacherUser)->get(route('teacher.lessons.attachments.download', [$lesson, $attachment]))
                ->assertNotFound()->assertDontSeeText('Outside private storage');
            $this->assertDatabaseCount('lesson_views', 0);
        } finally {
            unlink($link);
        }
    }

    public function test_lesson_cards_keep_combined_filters_and_existing_action_links(): void
    {
        $included = $this->lesson(['title' => 'Included published lesson']);
        $this->lesson(['title' => 'Excluded draft', 'status' => 'draft', 'published_at' => null]);
        $this->lesson(['title' => 'Excluded previous date', 'published_at' => now()->subDay()]);
        $otherRoom = Classroom::create([
            'name' => 'الصف الثاني', 'section' => 'A', 'stage' => 'ابتدائي', 'academic_year_id' => $this->classroom->academic_year_id,
        ]);
        DB::table('teacher_assignments')->insert([
            'teacher_id' => $this->teacher->id, 'classroom_id' => $otherRoom->id, 'subject_id' => $this->subject->id,
        ]);
        $otherClassLesson = $this->lesson(['title' => 'Excluded other classroom', 'classroom_id' => $otherRoom->id]);
        $otherClassLesson->classrooms()->sync([$otherRoom->id]);

        $response = $this->actingAs($this->teacherUser)->get(route('teacher.lessons.index', [
            'classroom_id' => $this->classroom->id, 'published_at' => '2026-10-13', 'status' => 'published',
        ]))->assertOk();

        $this->assertSame([$included->id], $response->viewData('lessons')->pluck('id')->all());
        $response->assertSeeText('Included published lesson')->assertDontSeeText('Excluded draft')
            ->assertDontSeeText('Excluded previous date')->assertDontSeeText('Excluded other classroom');
        foreach (['show', 'edit', 'views', 'cancel', 'destroy'] as $action) {
            $response->assertSee(route('teacher.lessons.'.$action, $included), false);
        }
        foreach (['classroom_id', 'published_at', 'status'] as $field) {
            $response->assertSee('name="'.$field.'"', false);
        }
        $this->assertDatabaseCount('lesson_views', 0);
    }

    private function lesson(array $attributes = []): Lesson
    {
        $lesson = Lesson::create(array_replace([
            'teacher_id' => $this->teacher->id,
            'classroom_id' => $this->classroom->id,
            'subject_id' => $this->subject->id,
            'title' => 'Lesson preview '.(Lesson::count() + 1),
            'content' => 'Private lesson content',
            'status' => 'published',
            'published_at' => now()->subMinute(),
        ], $attributes));
        $lesson->classrooms()->attach($lesson->classroom_id);

        return $lesson;
    }

    private function attachment(Lesson $lesson, string $name, string $contents, array $attributes = []): LessonAttachment
    {
        $path = 'lesson-attachments/'.$lesson->id.'/'.$name;
        Storage::disk('local')->put($path, $contents);

        return LessonAttachment::create(array_replace([
            'lesson_id' => $lesson->id, 'title' => $name, 'original_name' => $name,
            'disk' => 'local', 'file_path' => $path, 'size' => strlen($contents),
        ], $attributes));
    }

    private function student(): Student
    {
        $user = User::factory()->create(['role' => 'student', 'status' => 'active']);

        return Student::create([
            'user_id' => $user->id, 'student_number' => 'ST-PREVIEW-'.$user->id,
            'classroom_id' => $this->classroom->id, 'status' => 'active',
        ]);
    }

    private function lessonRecords(): array
    {
        return [
            'lessons' => DB::table('lessons')->orderBy('id')->get()->toJson(),
            'classrooms' => DB::table('lesson_classroom')->orderBy('lesson_id')->orderBy('classroom_id')->get()->toJson(),
            'attachments' => DB::table('lesson_attachments')->orderBy('id')->get()->toJson(),
            'views' => DB::table('lesson_views')->orderBy('id')->get()->toJson(),
        ];
    }
}
