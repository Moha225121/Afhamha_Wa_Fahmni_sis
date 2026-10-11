<?php

namespace Tests\Feature;

use App\Models\LibraryResource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesStudentEducationFixtures;
use Tests\TestCase;

class LibraryFileRecoveryTest extends TestCase
{
    use CreatesStudentEducationFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('public');
        Storage::fake('originals');
    }

    public function test_missing_catalog_file_is_restored_without_changing_records_and_all_student_links_work(): void
    {
        $student = $this->createStudentFixture('RECOVERY');
        $student['classroom']->update(['name' => 'الصف الثاني']);
        $book = $this->book(['grade_levels' => [2]]);
        $attributes = $book->fresh()->getAttributes();
        $this->actingAs($student['user']);
        foreach (['read', 'file', 'download'] as $action) {
            $this->get(route('student.library.'.$action, $book))->assertNotFound();
        }
        $this->assertSame(1, Artisan::call('library:verify-files'));
        Storage::disk('local')->assertMissing($book->file_path);
        $this->assertSame(0, $this->recover());
        $this->assertSame($attributes, $book->fresh()->getAttributes());
        $this->assertSame(Storage::disk('originals')->get($book->source_path), Storage::disk('local')->get($book->file_path));
        foreach (['read', 'file', 'download'] as $action) {
            $this->get(route('student.library.'.$action, $book))->assertOk();
        }
        $this->withHeader('Range', 'bytes=0-8')->get(route('student.library.file', $book))->assertStatus(206);
        $this->assertSame(0, $this->recover());
        $this->assertStringContainsString('0 restored', Artisan::output());
        Storage::disk('public')->assertMissing($book->file_path);
    }

    public function test_recovery_preserves_grade_and_teacher_guide_access_restrictions(): void
    {
        $student = $this->createStudentFixture('PRIVATE');
        $student['classroom']->update(['name' => 'الصف الثاني']);
        $wrongGrade = $this->book(['grade_levels' => [3], 'source_path' => 'third.pdf']);
        $guide = $this->book(['grade_levels' => [2], 'audience' => 'teachers', 'source_path' => 'guide.pdf']);
        $this->assertSame(0, $this->recover());
        $this->actingAs($student['user']);
        foreach ([$wrongGrade, $guide] as $book) {
            foreach (['read', 'file', 'download'] as $action) {
                $this->get(route('student.library.'.$action, $book))->assertNotFound();
            }
            $this->actingAs($this->createAdmin())->get(route('admin.library.file', $book))->assertOk();
            $this->actingAs($student['user']);
        }
    }

    public function test_original_with_wrong_hash_or_size_is_not_restored(): void
    {
        $book = $this->book();
        $source = Storage::disk('originals');
        $bytes = $source->get($book->source_path);
        $source->put($book->source_path, str_repeat('x', strlen($bytes)));
        $this->assertSame(1, $this->recover());
        Storage::disk('local')->assertMissing($book->file_path);
        $source->put($book->source_path, $bytes.'extra');
        $this->assertSame(1, $this->recover());
        Storage::disk('local')->assertMissing($book->file_path);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_recovery_rejects_paths_outside_source_or_storage_and_unknown_disks(): void
    {
        $book = $this->book();
        $original = $book->getAttributes();
        foreach ([['source_path' => '../outside.pdf'], ['source_path' => '/outside.pdf'],
            ['file_path' => '../outside.pdf'], ['file_path' => 'C:\\outside.pdf'], ['disk' => 's3']] as $bad) {
            $book->forceFill(array_replace($original, $bad))->save();
            $this->assertSame(1, $this->recover());
            $this->assertSame([], Storage::disk('local')->allFiles());
        }
    }

    public function test_present_corrupt_file_fails_the_deployment_check_and_is_not_silently_overwritten(): void
    {
        $book = $this->book();
        Storage::disk('local')->put($book->file_path, 'corrupt bytes');
        $this->assertSame(1, $this->recover());
        $this->assertSame('corrupt bytes', Storage::disk('local')->get($book->file_path));
    }

    public function test_missing_original_or_missing_fingerprint_fails_without_changing_catalog(): void
    {
        $book = $this->book();
        Storage::disk('originals')->delete($book->source_path);
        $attributes = $book->fresh()->getAttributes();
        $this->assertSame(1, $this->recover());
        $this->assertSame($attributes, $book->fresh()->getAttributes());
        $book->update(['sha256' => null]);
        $this->assertSame(1, $this->recover());
        Storage::disk('local')->assertMissing($book->file_path);
    }

    public function test_hard_link_recovery_handles_limited_disk_space_without_a_symlink_or_source_changes(): void
    {
        $book = $this->book();
        $source = Storage::disk('originals')->path($book->source_path);
        $bytes = file_get_contents($source);
        $modified = filemtime($source);
        $this->assertSame(0, Artisan::call('library:verify-files', [
            '--restore' => true, '--hard-link' => true, '--source' => [Storage::disk('originals')->path('')],
        ]));
        $target = Storage::disk('local')->path($book->file_path);
        $this->assertFalse(is_link($target));
        $this->assertSame($bytes, file_get_contents($target));
        $this->assertSame($bytes, file_get_contents($source));
        $this->assertSame($modified, filemtime($source));
        // Removing the recovered directory entry must retain the original bytes.
        Storage::disk('local')->delete($book->file_path);
        $this->assertSame($bytes, file_get_contents($source));
    }

    private function book(array $overrides = []): LibraryResource
    {
        $source = $overrides['source_path'] ?? 'الصف الثاني/كتاب اللغة العربية.pdf';
        $bytes = "%PDF-1.4\n".$source."\n%%EOF\n";
        Storage::disk('originals')->put($source, $bytes);

        return LibraryResource::create(array_replace([
            'title' => 'كتاب اللغة العربية', 'category' => 'كتاب الطالب', 'status' => 'active',
            'disk' => 'local', 'file_path' => 'library/curriculum/'.hash('sha256', $bytes).'.pdf',
            'source_path' => $source, 'sha256' => hash('sha256', $bytes), 'file_size' => strlen($bytes),
            'is_public' => true, 'audience' => 'all', 'created_by' => $this->createAdmin()->id,
        ], $overrides));
    }

    private function recover(): int
    {
        return Artisan::call('library:verify-files', ['--restore' => true, '--source' => [Storage::disk('originals')->path('')]]);
    }
}
