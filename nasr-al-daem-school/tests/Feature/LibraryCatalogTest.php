<?php

namespace Tests\Feature;

use App\Models\Lesson;
use App\Models\LibraryResource;
use App\Models\Teacher;
use App\Models\User;
use App\Services\LibraryCatalogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Tests\Concerns\CreatesStudentEducationFixtures;
use Tests\TestCase;

class LibraryCatalogTest extends TestCase
{
    use CreatesStudentEducationFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('public');
    }

    public function test_teacher_only_audience_is_absolute_for_student_index_reader_file_download_and_subject_lessons(): void
    {
        $student = $this->createStudentFixture('CAT');
        $subject = $this->createSubjectFor($student['classroom'], 'CAT');
        $admin = $this->createAdmin();
        $guide = $this->book($admin, ['title' => 'دليل سري للمعلمين', 'audience' => 'teachers', 'subject_name' => 'مادة خاصة بالمعلمين', 'subject_id' => $subject->id, 'classroom_id' => $student['classroom']->id, 'is_public' => true]);
        $book = $this->book($admin, ['title' => 'الكتاب المتاح للجميع']);
        $lesson = Lesson::create(['subject_id' => $subject->id, 'classroom_id' => $student['classroom']->id, 'title' => 'درس تجريبي', 'content' => 'الدرس', 'status' => 'published', 'published_at' => now()->subMinute()]);

        $this->actingAs($student['user'])->get(route('student.library.index'))
            ->assertOk()->assertSeeText($book->title)->assertDontSeeText($guide->title)->assertDontSeeText($guide->subject_name);
        foreach (['read', 'file', 'download'] as $action) {
            $this->get(route('student.library.'.$action, $guide))->assertNotFound();
        }
        $this->get(route('student.subjects.show', $subject))->assertOk()->assertDontSeeText($guide->title);
        $this->get(route('student.lessons.show', [$subject, $lesson]))->assertOk()->assertDontSeeText($guide->title);
        $data = $this->data($student['user']);
        $this->assertSame(0, $data['stats']['teacher_only']);
        $this->assertFalse($data['catalogSubjects']->contains('name', $guide->subject_name));
    }

    public function test_national_books_are_available_across_grades_and_arabic_multitoken_metadata_search_is_normalized(): void
    {
        $student = $this->createStudentFixture('SEARCH', 'ثانوي');
        $student['classroom']->update(['name' => 'الصف الثالث الثانوي']);
        $admin = $this->createAdmin();
        $target = $this->book($admin, [
            'title' => 'إِثْرَاءُ الرِّيَاضِيَّاتِ', 'subject_name' => 'الرياضيات', 'grade_levels' => [11, 12],
            'education_stage' => 'ثانوي', 'track' => 'علمي', 'book_type' => 'workbook', 'term' => 'الفصل الأول',
            'extracted_text' => 'نظرية الإحتمال والتكامل',
        ]);
        $other = $this->book($admin, ['title' => 'إثراء العلوم', 'subject_name' => 'العلوم', 'grade_levels' => [2], 'book_type' => 'textbook']);
        $data = $this->data($student['user'], ['q' => 'اثراء رياضيات', 'subject' => 'الرياضيات', 'grade' => '12', 'book_type' => 'workbook', 'track' => 'علمي', 'term' => 'الفصل الأول', 'stage' => 'ثانوي']);
        $this->assertSame([$target->id], $data['resources']->modelKeys());
        $this->assertSame(18, $data['resources']->perPage());
        $this->assertSame(1, $data['stats']['total']);
        $this->assertSame(['الفصل الأول'], $data['catalogTerms']->all());
        $this->assertSame([$target->id], $this->data($student['user'], ['q' => 'احتمال تكامل'])['resources']->modelKeys());
        $target->update(['title' => 'عنوان محدث']);
        $this->assertSame([$target->id], $this->data($student['user'], ['q' => 'احتمال تكامل'])['resources']->modelKeys());
        $this->assertSame([$target->id], $this->data($student['user'], ['grade' => 2])['resources']->modelKeys());
    }

    public function test_student_library_uses_saved_class_grade_and_cannot_override_it_in_the_url_or_direct_links(): void
    {
        $student = $this->createStudentFixture('AUTO');
        $student['classroom']->update(['name' => 'الصف الأول']);
        $admin = $this->createAdmin();
        $firstGradeBook = $this->book($admin, ['title' => 'كتاب الصف المسجل', 'grade_levels' => [1]]);
        $otherGradeBook = $this->book($admin, ['title' => 'كتاب صف آخر', 'grade_levels' => [2]]);

        $this->actingAs($student['user'])->get(route('student.library.index'))
            ->assertOk()->assertSeeText($firstGradeBook->title)->assertDontSeeText($otherGradeBook->title)
            ->assertDontSee('name="grade"', false)->assertSeeText('كتب الصف الأول تظهر تلقائيًا');
        $this->get(route('student.library.index', ['grade' => 2]))
            ->assertOk()->assertSeeText($firstGradeBook->title)->assertDontSeeText($otherGradeBook->title);
        $this->get(route('student.library.read', $otherGradeBook))->assertNotFound();
        $this->get(route('student.library.file', $otherGradeBook))->assertNotFound();
        $this->get(route('student.library.download', $otherGradeBook))->assertNotFound();
        $this->get(route('student.library.read', $firstGradeBook))->assertOk();
    }

    public function test_teacher_has_national_guides_for_assigned_subjects_and_legacy_private_resources_require_the_exact_assignment_pair(): void
    {
        $one = $this->createStudentFixture('T1');
        $two = $this->createStudentFixture('T2');
        $subjectOne = $this->createSubjectFor($one['classroom'], 'T1');
        $subjectTwo = $this->createSubjectFor($two['classroom'], 'T2');
        $teacherUser = User::factory()->create(['role' => 'teacher', 'status' => 'active']);
        $teacher = Teacher::create(['user_id' => $teacherUser->id, 'specialization' => 'علوم', 'status' => 'active']);
        $teacher->classrooms()->attach($one['classroom']->id, ['subject_id' => $subjectOne->id]);
        $teacher->classrooms()->attach($two['classroom']->id, ['subject_id' => $subjectTwo->id]);
        $admin = $this->createAdmin();
        $guide = $this->book($admin, ['title' => 'دليل معلم المادة الأولى', 'audience' => 'teachers', 'book_type' => 'teacher_guide', 'subject_name' => $subjectOne->name, 'is_public' => false]);
        $otherSubjectGuide = $this->book($admin, ['title' => 'دليل مادة غير مسندة', 'audience' => 'teachers', 'book_type' => 'teacher_guide', 'subject_name' => 'مادة غير مسندة', 'is_public' => true]);
        $own = $this->book($admin, ['title' => 'ملف الصف الخاص', 'is_public' => false, 'subject_id' => $subjectOne->id, 'classroom_id' => $one['classroom']->id]);
        $mismatched = $this->book($admin, ['title' => 'زوج تكليف غير مسند', 'is_public' => false, 'subject_id' => $subjectOne->id, 'classroom_id' => $two['classroom']->id]);
        $unscopedPrivate = $this->book($admin, ['title' => 'ملف غير منشور', 'is_public' => false]);

        $this->actingAs($teacherUser)->get(route('teacher.library.index'))->assertOk()->assertSeeText($guide->title)->assertSeeText($own->title)->assertDontSeeText($otherSubjectGuide->title)->assertDontSeeText($mismatched->title)->assertDontSeeText($unscopedPrivate->title);
        foreach (['read', 'file', 'download'] as $action) {
            $this->get(route('teacher.library.'.$action, $guide))->assertOk();
            $this->get(route('teacher.library.'.$action, $own))->assertOk();
            $this->get(route('teacher.library.'.$action, $otherSubjectGuide))->assertNotFound();
            $this->get(route('teacher.library.'.$action, $mismatched))->assertNotFound();
            $this->get(route('teacher.library.'.$action, $unscopedPrivate))->assertNotFound();
        }
    }

    public function test_authenticated_pdf_responses_support_ranges_private_cache_and_arabic_names(): void
    {
        $student = $this->createStudentFixture('FILE');
        $resource = $this->book($this->createAdmin(), ['title' => 'كتاب اللغة العربية', 'original_name' => 'نسخة طويلة جدا من اسم الملف الأصلي للصف الثالث الفصل الأول الطبعة الجديدة.pdf']);
        $this->get(route('student.library.file', $resource))->assertRedirect('/login');
        $inline = $this->actingAs($student['user'])->get(route('student.library.file', $resource));
        $inline->assertOk()->assertHeader('Content-Type', 'application/pdf')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertInstanceOf(BinaryFileResponse::class, $inline->baseResponse);
        $this->assertStringContainsString('private', $inline->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', $inline->headers->get('Cache-Control'));
        $this->assertStringStartsWith('inline;', $inline->headers->get('Content-Disposition'));
        $this->assertStringContainsString("filename*=utf-8''", $inline->headers->get('Content-Disposition'));
        $this->assertStringContainsString(rawurlencode('كتاب اللغة العربية.pdf'), $inline->headers->get('Content-Disposition'));
        $this->assertStringNotContainsString(rawurlencode($resource->original_name), $inline->headers->get('Content-Disposition'));
        $download = $this->get(route('student.library.download', $resource))->assertOk()->assertDownload();
        $this->assertStringContainsString(rawurlencode('كتاب اللغة العربية.pdf'), $download->headers->get('Content-Disposition'));
        $this->assertStringContainsString('resource-'.$resource->id.'.pdf', $download->headers->get('Content-Disposition'));
        $this->get(route('student.library.read', $resource))->assertOk()->assertDontSee('/storage/library/', false);

        $range = $this->withHeader('Range', 'bytes=0-8')->get(route('student.library.file', $resource));
        $range->assertStatus(206)->assertHeader('Accept-Ranges', 'bytes')->assertHeader('Content-Length', '9');
        $this->assertStringStartsWith('bytes 0-8/', $range->headers->get('Content-Range'));
    }

    public function test_parent_inactive_users_and_inactive_domain_profiles_cannot_access_catalog_files(): void
    {
        $resource = $this->book($this->createAdmin());
        $parent = User::factory()->create(['role' => 'parent', 'status' => 'active']);
        foreach (['student', 'teacher', 'admin'] as $role) {
            foreach (['index', 'read', 'file', 'download'] as $action) {
                $this->actingAs($parent)->get(route($role.'.library.'.$action, $action === 'index' ? [] : [$resource]))->assertForbidden();
            }
        }
        $student = $this->createStudentFixture('INACTIVE');
        $student['user']->update(['status' => 'inactive']);
        $this->actingAs($student['user'])->get(route('student.library.file', $resource))->assertForbidden();
        $student['user']->update(['status' => 'active']);
        $student['student']->update(['status' => 'inactive']);
        $this->actingAs($student['user']->fresh())->get(route('student.library.file', $resource))->assertForbidden();
        $teacherUser = User::factory()->create(['role' => 'teacher', 'status' => 'active']);
        Teacher::create(['user_id' => $teacherUser->id, 'status' => 'inactive']);
        $this->actingAs($teacherUser)->get(route('teacher.library.file', $resource))->assertForbidden();
    }

    public function test_file_serving_rejects_unknown_disks_traversal_and_missing_files_but_supports_public_legacy(): void
    {
        $student = $this->createStudentFixture('PATH');
        $resource = $this->book($this->createAdmin());
        $this->actingAs($student['user']);
        foreach ([['disk' => 's3'], ['disk' => 'local', 'file_path' => '../outside.pdf'], ['file_path' => 'C:\\outside.pdf'], ['file_path' => '/outside.pdf'], ['file_path' => 'library/missing.pdf']] as $bad) {
            $resource->update($bad);
            $this->get(route('student.library.file', $resource))->assertNotFound();
        }
        Storage::disk('public')->put('library/legacy.pdf', '%PDF-1.4 legacy');
        $resource->update(['disk' => 'public', 'file_path' => 'library/legacy.pdf']);
        $this->get(route('student.library.file', $resource))->assertOk();
        $resource->update(['status' => 'inactive']);
        $this->get(route('student.library.file', $resource))->assertNotFound();
    }

    private function data(User $user, array $filters = []): array
    {
        return app(LibraryCatalogService::class)->data(Request::create('/library', 'GET', $filters), $user);
    }

    private function book(User $admin, array $attributes = []): LibraryResource
    {
        $path = 'library/'.bin2hex(random_bytes(8)).'.pdf';
        Storage::disk('local')->put($path, "%PDF-1.4\n".str_repeat('catalog-book ', 50));

        return LibraryResource::create(array_replace([
            'title' => 'كتاب عام', 'category' => 'كتاب', 'file_path' => $path, 'disk' => 'local',
            'is_public' => true, 'status' => 'active', 'created_by' => $admin->id, 'audience' => 'all',
        ], $attributes));
    }
}
