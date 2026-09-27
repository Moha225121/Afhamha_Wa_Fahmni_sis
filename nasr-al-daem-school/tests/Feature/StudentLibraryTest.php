<?php

namespace Tests\Feature;

use App\Models\LibraryResource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesStudentEducationFixtures;
use Tests\TestCase;

class StudentLibraryTest extends TestCase
{
    use CreatesStudentEducationFixtures;
    use RefreshDatabase;

    public function test_library_search_and_filters_remain_inside_student_scope(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        $studentA = $this->createStudentFixture('A', 'أساسي');
        $studentB = $this->createStudentFixture('B', 'ثانوي');
        $subjectA = $this->createSubjectFor($studentA['classroom'], 'MATH');
        $subjectA2 = $this->createSubjectFor($studentA['classroom'], 'SCIENCE');
        $subjectB = $this->createSubjectFor($studentB['classroom'], 'OTHER');
        $admin = $this->createAdmin();

        $this->resource('كتاب الحساب', 'كتاب', $subjectA->id, $studentA['classroom']->id, $admin->id);
        $this->resource('فيديو العلوم', 'فيديو', $subjectA2->id, null, $admin->id);
        $this->resource('مورد عام داخلي', 'عام', null, null, $admin->id, true);
        $privateUnscoped = $this->resource('مورد خاص بلا نطاق', 'عام', null, null, $admin->id);
        $this->resource('مورد صف آخر', 'كتاب', $subjectB->id, $studentB['classroom']->id, $admin->id);
        $this->resource('مورد عام لصف آخر', 'كتاب', $subjectB->id, $studentB['classroom']->id, $admin->id, true);
        $this->resource('علاقة متناقضة', 'كتاب', $subjectB->id, $studentA['classroom']->id, $admin->id);
        $this->resource('مورد غير نشط', 'كتاب', $subjectA->id, $studentA['classroom']->id, $admin->id, false, 'inactive');

        $index = $this->actingAs($studentA['user'])->get(route('student.library.index'));
        $index->assertOk()
            ->assertSeeText('كتاب الحساب')
            ->assertSeeText('فيديو العلوم')
            ->assertSeeText('مورد عام داخلي')
            ->assertDontSeeText('مورد صف آخر')
            ->assertDontSeeText('مورد عام لصف آخر')
            ->assertDontSeeText('علاقة متناقضة')
            ->assertDontSeeText('مورد خاص بلا نطاق')
            ->assertDontSeeText('مورد غير نشط');
        $this->actingAs($studentA['user'])->get(route('student.library.download', $privateUnscoped))->assertNotFound();

        $this->actingAs($studentA['user'])->get(route('student.library.index', ['q' => 'الحساب']))
            ->assertOk()->assertSeeText('كتاب الحساب')->assertDontSeeText('فيديو العلوم');
        $this->actingAs($studentA['user'])->get(route('student.library.index', ['category' => 'فيديو']))
            ->assertOk()->assertSeeText('فيديو العلوم')->assertDontSeeText('كتاب الحساب');
        $this->actingAs($studentA['user'])->get(route('student.library.index', ['subject_id' => $subjectA->id]))
            ->assertOk()->assertSeeText('كتاب الحساب')->assertDontSeeText('فيديو العلوم');
        $this->actingAs($studentA['user'])->get(route('student.library.index', ['stage' => 'أساسي']))
            ->assertOk()->assertSeeText('كتاب الحساب')->assertSeeText('فيديو العلوم')->assertDontSeeText('مورد عام داخلي');
        $this->actingAs($studentA['user'])->get(route('student.library.index', [
            'q' => 'الحساب',
            'category' => 'كتاب',
            'subject_id' => $subjectA->id,
            'stage' => 'أساسي',
        ]))->assertOk()->assertSeeText('كتاب الحساب')->assertDontSeeText('فيديو العلوم');
        $this->actingAs($studentA['user'])->get(route('student.library.index', ['subject_id' => $subjectB->id]))
            ->assertOk()->assertDontSeeText('مورد صف آخر')->assertDontSeeText('مورد عام لصف آخر');
    }

    public function test_private_resource_download_requires_an_authorized_student_scope(): void
    {
        Storage::fake('local');
        $studentA = $this->createStudentFixture('A');
        $studentB = $this->createStudentFixture('B');
        $subjectA = $this->createSubjectFor($studentA['classroom'], 'DOWNLOAD');
        $admin = $this->createAdmin();
        Storage::disk('local')->put('library/private-book.pdf', 'private-content');
        $resource = LibraryResource::create([
            'title' => 'الكتاب الخاص',
            'category' => 'كتاب',
            'subject_id' => $subjectA->id,
            'classroom_id' => $studentA['classroom']->id,
            'file_path' => 'library/private-book.pdf',
            'disk' => 'local',
            'is_public' => false,
            'status' => 'active',
            'created_by' => $admin->id,
        ]);

        $this->get(route('student.library.download', $resource))->assertRedirect('/login');
        $this->actingAs($studentB['user'])->get(route('student.library.download', $resource))->assertNotFound();
        $this->actingAs($studentA['user'])->get(route('student.library.download', $resource))
            ->assertOk()
            ->assertDownload();
        $this->actingAs($studentA['user'])->get(route('student.library.index'))
            ->assertOk()
            ->assertDontSee('/storage/library/private-book.pdf', false);

        $resource->update(['file_path' => 'library/missing.pdf']);
        $this->actingAs($studentA['user'])->get(route('student.library.download', $resource))->assertNotFound();
        $this->actingAs($studentA['user'])->get('/student/library/not-a-number/download')->assertNotFound();
    }

    public function test_private_admin_upload_uses_local_storage(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        $student = $this->createStudentFixture('A');
        $subject = $this->createSubjectFor($student['classroom'], 'UPLOAD');
        $admin = $this->createAdmin();

        $this->actingAs($admin)->post(route('admin.library.store'), [
            'title' => 'ملف خاص',
            'subject_id' => $subject->id,
            'file' => UploadedFile::fake()->create('private.pdf', 20, 'application/pdf'),
        ])->assertSessionHasNoErrors();

        $resource = LibraryResource::query()->where('title', 'ملف خاص')->firstOrFail();
        $this->assertSame('local', $resource->disk);
        $this->assertFalse($resource->is_public);
        Storage::disk('local')->assertExists($resource->file_path);
        Storage::disk('public')->assertMissing($resource->file_path);

        $this->actingAs($admin)->post(route('admin.library.store'), [
            'title' => 'ملف خاص بلا نطاق',
            'is_public' => '0',
            'file' => UploadedFile::fake()->create('invalid.pdf', 20, 'application/pdf'),
        ])->assertSessionHasErrors('subject_id');
        $this->assertDatabaseMissing('library_resources', ['title' => 'ملف خاص بلا نطاق']);

        $this->actingAs($admin)->delete(route('admin.library.destroy', $resource->id))->assertRedirect();
        Storage::disk('local')->assertMissing($resource->file_path);
    }

    public function test_legacy_private_files_are_moved_to_local_storage_idempotently(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        $student = $this->createStudentFixture('A');
        $subject = $this->createSubjectFor($student['classroom'], 'LEGACY');
        $admin = $this->createAdmin();
        Storage::disk('public')->put('library/legacy.pdf', 'legacy-private');
        $resource = LibraryResource::create([
            'title' => 'ملف قديم',
            'subject_id' => $subject->id,
            'classroom_id' => $student['classroom']->id,
            'file_path' => 'library/legacy.pdf',
            'disk' => 'public',
            'is_public' => false,
            'status' => 'active',
            'created_by' => $admin->id,
        ]);
        $migration = require database_path('migrations/2026_08_23_000004_add_disk_to_library_resources.php');

        $migration->down();
        $migration->up();
        $migration->up();

        $this->assertSame('local', DB::table('library_resources')->where('id', $resource->id)->value('disk'));
        Storage::disk('local')->assertExists('library/legacy.pdf');
        Storage::disk('public')->assertMissing('library/legacy.pdf');
    }

    public function test_library_uses_its_own_responsive_pagination(): void
    {
        $student = $this->createStudentFixture('A');
        $subject = $this->createSubjectFor($student['classroom'], 'PAGES');
        $admin = $this->createAdmin();

        foreach (range(1, 13) as $number) {
            $this->resource('مورد صفحة '.str_pad((string) $number, 2, '0', STR_PAD_LEFT), 'كتاب', $subject->id, $student['classroom']->id, $admin->id);
        }

        $this->actingAs($student['user'])->get(route('student.library.index'))
            ->assertOk()
            ->assertSee('student-pagination', false)
            ->assertSeeText('التالي');
    }

    private function resource(
        string $title,
        string $category,
        ?int $subjectId,
        ?int $classroomId,
        int $creatorId,
        bool $public = false,
        string $status = 'active',
    ): LibraryResource {
        return LibraryResource::create([
            'title' => $title,
            'category' => $category,
            'subject_id' => $subjectId,
            'classroom_id' => $classroomId,
            'file_path' => 'library/'.md5($title).'.pdf',
            'disk' => $public ? 'public' : 'local',
            'is_public' => $public,
            'status' => $status,
            'created_by' => $creatorId,
        ]);
    }
}
