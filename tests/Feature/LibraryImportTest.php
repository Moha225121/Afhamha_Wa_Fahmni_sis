<?php

namespace Tests\Feature;

use App\Models\LibraryResource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesStudentEducationFixtures;
use Tests\TestCase;
use Throwable;

class LibraryImportTest extends TestCase
{
    use CreatesStudentEducationFixtures;
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('catalog_source');
        Storage::disk('catalog_source')->makeDirectory('books');
        $this->admin = $this->createAdmin();
    }

    public function test_import_copies_verified_pdfs_to_private_storage_without_changing_source_bytes(): void
    {
        $entry = $this->entry('العربية/الكتاب.pdf');
        $sourcePath = Storage::disk('catalog_source')->path('books/'.$entry['source_path']);
        $sourceBytes = file_get_contents($sourcePath);
        $sourceModified = filemtime($sourcePath);

        $this->assertSame(0, $this->import([$entry]));

        $book = LibraryResource::sole();
        $this->assertSame('local', $book->disk);
        $this->assertSame('library/curriculum/'.$entry['sha256'].'.pdf', $book->file_path);
        $this->assertSame($entry['sha256'], hash_file('sha256', Storage::disk('local')->path($book->file_path)));
        $this->assertSame($sourceBytes, Storage::disk('local')->get($book->file_path));
        $this->assertSame($sourceBytes, file_get_contents($sourcePath));
        $this->assertSame($sourceModified, filemtime($sourcePath));
        $this->assertNull($book->subject_id);
        $this->assertNull($book->classroom_id);
        $this->assertTrue($book->is_public);
        $this->assertSame([1], $book->grade_levels);
        $this->assertSame($entry['search_text'], $book->extracted_text);
        $this->assertStringContainsString('القراءه', $book->search_text);
    }

    public function test_repeated_import_is_idempotent_and_merges_grades_without_extra_copies(): void
    {
        $entry = $this->entry('book.pdf');
        $this->assertSame(0, $this->import([$entry]));
        $id = LibraryResource::sole()->id;

        $entry['grade_levels'] = [2, 1];
        $this->assertSame(0, $this->import([$entry, $entry]));
        $this->assertDatabaseCount('library_resources', 1);
        $book = LibraryResource::sole();
        $this->assertSame($id, $book->id);
        $this->assertSame([1, 2], $book->grade_levels);
        $this->assertCount(1, Storage::disk('local')->allFiles());
        $this->assertSame($entry['sha256'], hash_file('sha256', Storage::disk('local')->path($book->file_path)));
    }

    public function test_unreviewed_hash_is_rejected_without_indexing_or_leaving_partial_files(): void
    {
        $entry = $this->entry('wrong-hash.pdf');
        $originalHash = $entry['sha256'];
        $entry['sha256'] = str_repeat('0', 64);

        $this->assertImportRejected([$entry]);

        $this->assertDatabaseCount('library_resources', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertSame($originalHash, hash_file('sha256', Storage::disk('catalog_source')->path('books/'.$entry['source_path'])));
    }

    public function test_same_size_source_changes_are_rejected_even_when_destination_already_exists(): void
    {
        $entry = $this->entry('changed-source.pdf');
        $this->assertSame(0, $this->import([$entry]));
        $book = LibraryResource::sole();
        $disk = Storage::disk('catalog_source');
        $bytes = $disk->get('books/'.$entry['source_path']);
        $changedBytes = substr($bytes, 0, -1).($bytes[-1] === 'A' ? 'B' : 'A');
        $disk->put('books/'.$entry['source_path'], $changedBytes);
        $entry['title'] = 'عنوان لا يجب اعتماده';

        $this->assertImportRejected([$entry]);

        $this->assertSame($book->title, $book->fresh()->title);
        $this->assertSame($changedBytes, $disk->get('books/'.$entry['source_path']));
        $this->assertSame($entry['sha256'], hash_file('sha256', Storage::disk('local')->path($book->file_path)));
    }

    public function test_same_size_destination_corruption_is_rejected_instead_of_trusting_its_filename(): void
    {
        $entry = $this->entry('changed-destination.pdf');
        $this->assertSame(0, $this->import([$entry]));
        $book = LibraryResource::sole();
        $bytes = Storage::disk('local')->get($book->file_path);
        Storage::disk('local')->put($book->file_path, substr($bytes, 0, -1).($bytes[-1] === 'A' ? 'B' : 'A'));
        $entry['title'] = 'عنوان غير معتمد';

        $this->assertImportRejected([$entry]);

        $this->assertSame($book->title, $book->fresh()->title);
        $this->assertSame($entry['sha256'], hash_file('sha256', Storage::disk('catalog_source')->path('books/'.$entry['source_path'])));
        $this->assertDatabaseCount('library_resources', 1);
    }

    public function test_manifest_cannot_import_files_outside_the_selected_source_folder(): void
    {
        $entry = $this->entry('../outside.pdf');
        $outside = Storage::disk('catalog_source')->path('outside.pdf');
        $original = file_get_contents($outside);

        $this->assertImportRejected([$entry]);

        $this->assertDatabaseCount('library_resources', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertSame($original, file_get_contents($outside));
    }

    public function test_teacher_guides_and_assessments_force_teacher_audience_even_if_manifest_says_all(): void
    {
        $guide = $this->entry('guide.pdf', ['book_type' => 'teacher_guide', 'audience' => 'all']);
        $assessment = $this->entry('assessment.pdf', ['book_type' => 'assessment', 'audience' => 'all']);

        $this->assertSame(0, $this->import([$guide, $assessment]));

        $this->assertSame(2, LibraryResource::where('audience', 'teachers')->count());
        $student = $this->createStudentFixture('IMPORT');
        $this->assertSame(0, LibraryResource::visibleTo($student['student'])->count());
        foreach (LibraryResource::all() as $book) {
            $this->assertTrue($book->is_public);
            $this->assertSame('local', $book->disk);
        }
    }

    public function test_existing_scoped_copy_becomes_national_without_downgrading_teacher_audience(): void
    {
        $student = $this->createStudentFixture('DUP');
        $subject = $this->createSubjectFor($student['classroom'], 'DUP');
        $entry = $this->entry('existing.pdf', ['grade_levels' => [2]]);
        $legacy = LibraryResource::create([
            'title' => 'نسخة سابقة', 'file_path' => 'library/old-copy.pdf', 'disk' => 'local',
            'created_by' => $this->admin->id, 'status' => 'active', 'is_public' => false,
            'sha256' => $entry['sha256'], 'audience' => 'teachers', 'grade_levels' => [1],
            'subject_id' => $subject->id, 'classroom_id' => $student['classroom']->id,
        ]);

        $this->assertSame(0, $this->import([$entry]));

        $this->assertDatabaseCount('library_resources', 1);
        $book = $legacy->fresh();
        $this->assertNull($book->subject_id);
        $this->assertNull($book->classroom_id);
        $this->assertSame('teachers', $book->audience);
        $this->assertSame([1, 2], $book->grade_levels);
        $this->assertSame('library/curriculum/'.$entry['sha256'].'.pdf', $book->file_path);
    }

    private function entry(string $relativePath, array $overrides = []): array
    {
        $bytes = "%PDF-1.4\nBook source ".$relativePath."\n%%EOF\n";
        $sourceRelative = 'books/'.$relativePath;
        // The escape fixture deliberately lives beside, rather than inside, the source folder.
        if ($relativePath === '../outside.pdf') {
            $sourceRelative = 'outside.pdf';
        }
        Storage::disk('catalog_source')->put($sourceRelative, $bytes);

        return array_replace([
            'source_path' => $relativePath, 'title' => 'اللغة العربية — كتاب الطالب', 'subject_name' => 'اللغة العربية',
            'grade_levels' => [1], 'education_stage' => 'أساسي', 'track' => null, 'book_type' => 'textbook',
            'audience' => 'all', 'sha256' => hash('sha256', $bytes), 'file_size' => strlen($bytes),
            'original_name' => basename($relativePath), 'page_count' => 1, 'term' => 'الفصل الأول',
            'search_text' => 'مهارات القراءة والكتابة',
        ], $overrides);
    }

    private function import(array $entries): int
    {
        $disk = Storage::disk('catalog_source');
        $disk->put('manifest.json', json_encode($entries, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

        return Artisan::call('library:import', [
            'source' => $disk->path('books'), 'manifest' => $disk->path('manifest.json'), '--admin' => $this->admin->id,
        ]);
    }

    private function assertImportRejected(array $entries): void
    {
        $failure = null;
        try {
            $this->import($entries);
        } catch (Throwable $error) {
            $failure = $error;
        }
        $this->assertNotNull($failure, 'Import must reject an unverified or out-of-scope PDF.');
        $this->assertInstanceOf(\RuntimeException::class, $failure);
    }
}
