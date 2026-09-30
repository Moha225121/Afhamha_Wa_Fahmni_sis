<?php

namespace Tests\Feature;

use App\Models\LibraryResource;
use App\Models\User;
use App\Services\LibraryUploadService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class LibraryUploadTest extends TestCase
{
    use RefreshDatabase;

    private array $temporaryFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('public');
        config(['library.max_upload_mb' => 0, 'library.chunk_bytes' => 1024]);
        $this->actingAs($this->admin());
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        parent::tearDown();
    }

    public function test_pdf_larger_than_twenty_megabytes_uploads_in_chunks_to_private_storage(): void
    {
        config(['library.chunk_bytes' => 8 * 1024 * 1024]);
        $content = $this->pdf('large-book', 21 * 1024 * 1024);
        $id = $this->upload($content, 'العلوم.pdf');

        $this->finish($id, ['subject_name' => 'العلوم', 'grade_levels' => ['7']])
            ->assertRedirect()->assertSessionHasNoErrors();

        $book = LibraryResource::sole();
        $this->assertSame('العلوم — كتاب الطالب', $book->title);
        $this->assertSame([7], $book->grade_levels);
        $this->assertSame('أساسي', $book->education_stage);
        $this->assertSame(1, LibraryResource::whereJsonContains('grade_levels', 7)->count());
        $this->assertSame(21 * 1024 * 1024, $book->file_size);
        $this->assertSame('application/pdf', $book->mime_type);
        $this->assertSame('local', $book->disk);
        $this->assertSame(hash('sha256', $content), $book->sha256);
        $this->assertSame($book->sha256, hash_file('sha256', Storage::disk('local')->path($book->file_path)));
        Storage::disk('local')->assertDirectoryEmpty('library-uploads');
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_retrying_identical_chunk_is_idempotent_and_different_content_is_rejected(): void
    {
        $content = $this->pdf('retry', 2048);
        $id = $this->start('retry.pdf', strlen($content));
        $first = substr($content, 0, 1024);

        $this->chunk($id, 0, $first)->assertOk()->assertJsonPath('received_bytes', 1024);
        $this->chunk($id, 0, $first)->assertOk()->assertJsonPath('received_bytes', 1024);
        $this->chunk($id, 0, str_repeat('x', 1024))->assertUnprocessable()->assertJsonValidationErrors('chunk');
        $this->assertSame(1024, Storage::disk('local')->size('library-uploads/'.$id.'/body.part'));
        $this->chunk($id, 1, substr($content, 1024))->assertOk()->assertJsonPath('received_bytes', 2048);
        $this->finish($id)->assertSessionHasNoErrors();
        $this->assertSame(hash('sha256', $content), LibraryResource::sole()->sha256);
    }

    public function test_out_of_order_short_and_oversized_chunks_do_not_advance_upload(): void
    {
        $id = $this->start('ordered.pdf', 1500);
        foreach ([[1, 476], [0, 1000], [0, 1025]] as [$index, $size]) {
            $this->chunk($id, $index, str_repeat('a', $size))
                ->assertUnprocessable()->assertJsonValidationErrors('chunk');
        }
        Storage::disk('local')->assertMissing('library-uploads/'.$id.'/body.part');
        $this->assertSame(0, $this->state($id)['received_bytes']);

        $this->chunk($id, 0, str_repeat('a', 1024))->assertOk();
        $this->chunk($id, 1, str_repeat('a', 475))->assertUnprocessable();
        $this->assertSame(1024, $this->state($id)['received_bytes']);
        $this->chunk($id, 1, str_repeat('a', 476))->assertOk()->assertJsonPath('received_bytes', 1500);
        $this->chunk($id, 2, 'a')->assertUnprocessable();
    }

    public function test_incomplete_upload_cannot_be_finalized_or_create_a_library_record(): void
    {
        $id = $this->start('unfinished.pdf', 2048);
        $this->finish($id)->assertSessionHasErrors('file');
        $this->chunk($id, 0, substr($this->pdf('partial', 2048), 0, 1024))->assertOk();
        $this->finish($id)->assertSessionHasErrors('file');

        $this->assertDatabaseCount('library_resources', 0);
        $this->assertSame([], Storage::disk('local')->allFiles('library'));
    }

    public function test_finalization_checks_actual_assembled_size(): void
    {
        $id = $this->upload($this->pdf('truncated', 2048));
        // Simulate an interrupted or externally truncated staging file after accepted chunks.
        Storage::disk('local')->put('library-uploads/'.$id.'/body.part', '%PDF-1.7');

        $this->finish($id)->assertSessionHasErrors('file');
        $this->assertDatabaseCount('library_resources', 0);
    }

    public function test_another_admin_cannot_append_finalize_or_cancel_an_upload(): void
    {
        $content = $this->pdf('owned', 2048);
        $id = $this->upload($content);
        $this->actingAs($this->admin());

        $this->chunk($id, 0, substr($content, 0, 1024))->assertNotFound();
        $this->finish($id)->assertNotFound();
        $this->deleteJson(route('admin.library.uploads.cancel', $id))->assertNotFound();
        Storage::disk('local')->assertExists('library-uploads/'.$id.'/body.part');
        $this->assertDatabaseCount('library_resources', 0);
    }

    public function test_direct_file_cannot_bypass_upload_ownership_or_write_before_rejection(): void
    {
        $id = $this->upload($this->pdf('owner-upload', 2048));
        $this->actingAs($this->admin());

        $this->finish($id, ['file' => $this->file('replacement.pdf', $this->pdf('replacement'))])
            ->assertRedirect()->assertSessionHasErrors('file');
        $this->assertDatabaseCount('library_resources', 0);
        $this->assertSame([], Storage::disk('local')->allFiles('library'));
        Storage::disk('local')->assertExists('library-uploads/'.$id.'/body.part');
    }

    public function test_students_cannot_start_send_cancel_or_finalize_admin_uploads(): void
    {
        $id = $this->upload($this->pdf('admin-owned', 2048));
        $this->actingAs(User::factory()->create(['role' => 'student', 'status' => 'active']));

        $this->postJson(route('admin.library.uploads.start'), ['name' => 'student.pdf', 'size' => 1024])->assertForbidden();
        $this->chunk($id, 0, str_repeat('x', 1024))->assertForbidden();
        $this->deleteJson(route('admin.library.uploads.cancel', $id))->assertForbidden();
        $this->finish($id)->assertForbidden();
        $this->assertDatabaseCount('library_resources', 0);
    }

    public function test_plain_text_renamed_as_pdf_is_rejected_on_finalization(): void
    {
        $id = $this->upload(str_repeat('This is ordinary text, not a PDF. ', 80), 'disguised.pdf');

        $this->finish($id)->assertSessionHasErrors('file');
        $this->assertDatabaseCount('library_resources', 0);
        $this->assertSame([], Storage::disk('local')->allFiles('library'));
        $this->post(route('admin.library.store'), [
            'is_public' => 1,
            'file' => $this->file('also-disguised.pdf', 'ordinary text'),
        ])->assertSessionHasErrors('file');
    }

    public function test_teacher_guides_and_assessment_books_cannot_be_made_student_accessible(): void
    {
        foreach ([
            ['G8 Preparatory 2 TB_PRINT_2022.pdf', 'teacher_guide'],
            ['دليل تقويم الطالب رياضيات.pdf', 'assessment'],
        ] as [$filename, $type]) {
            $id = $this->upload($this->pdf($type), $filename);
            $this->finish($id, ['audience' => 'all', 'book_type' => 'textbook'])
                ->assertSessionHasNoErrors();
            $book = LibraryResource::where('original_name', $filename)->sole();
            $this->assertSame($type, $book->book_type);
            $this->assertSame('teachers', $book->audience);
            $this->assertSame('local', $book->disk);
            if ($type === 'teacher_guide') {
                $this->assertSame('اللغة الإنجليزية', $book->subject_name);
                $this->assertSame('اللغة الإنجليزية — دليل المعلم', $book->title);
            }
        }
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_duplicate_pdf_does_not_create_extra_record_or_file_and_can_tighten_audience(): void
    {
        $content = $this->pdf('duplicate');
        $this->finish($this->upload($content, 'book.pdf'))->assertSessionHasNoErrors();
        $book = LibraryResource::sole();
        $this->finish($this->upload($content, 'دليل المعلم.pdf'), ['audience' => 'all'])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('library_resources', 1);
        $this->assertSame('teachers', $book->fresh()->audience);
        $this->assertSame('teacher_guide', $book->fresh()->book_type);
        $this->assertCount(1, Storage::disk('local')->allFiles('library'));
        Storage::disk('local')->assertDirectoryEmpty('library-uploads');
    }

    public function test_optional_server_size_limit_applies_to_chunk_start_and_direct_upload(): void
    {
        config(['library.max_upload_mb' => 1]);
        $this->postJson(route('admin.library.uploads.start'), ['name' => 'too-large.pdf', 'size' => 1024 * 1024 + 1])
            ->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->postJson(route('admin.library.uploads.start'), ['name' => 'at-limit.pdf', 'size' => 1024 * 1024])
            ->assertCreated();
        $this->post(route('admin.library.store'), [
            'is_public' => 1,
            'file' => $this->file('too-large.pdf', $this->pdf('over-limit', 1024 * 1024 + 1)),
        ])->assertSessionHasErrors('file');
        $this->assertDatabaseCount('library_resources', 0);
    }

    public function test_direct_upload_gets_concise_arabic_subject_and_term_without_a_manual_title(): void
    {
        $this->post(route('admin.library.store'), [
            'is_public' => 1,
            'file' => $this->file('تقنية المعلومات الفصل الأول.pdf', $this->pdf('arabic-name')),
        ])->assertRedirect()->assertSessionHasNoErrors();

        $book = LibraryResource::sole();
        $this->assertSame('تقنية المعلومات', $book->subject_name);
        $this->assertSame('الفصل الأول', $book->term);
        $this->assertSame('تقنية المعلومات — كتاب الطالب — الفصل الأول', $book->title);
        $this->assertSame('local', $book->disk);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_daily_cleanup_removes_only_expired_uuid_staging_uploads(): void
    {
        $expired = $this->upload($this->pdf('expired'));
        $active = $this->upload($this->pdf('active'));
        $this->setCreatedAt($expired, time() - 25 * 3600);
        Storage::disk('local')->put('library/published.pdf', 'keep the completed library book');
        Storage::disk('local')->put('library-uploads/not-an-upload/state.json', json_encode(['created_at' => time() - 25 * 3600]));
        Storage::disk('local')->put('library-uploads/not-an-upload/body.part', 'unrelated directory');

        $this->artisan('library:prune-uploads')
            ->expectsOutput('1 expired library upload(s) removed.')->assertSuccessful();

        $this->assertFalse(Storage::disk('local')->directoryExists('library-uploads/'.$expired));
        Storage::disk('local')->assertExists([
            'library-uploads/'.$active.'/state.json',
            'library-uploads/'.$active.'/body.part',
            'library-uploads/not-an-upload/body.part',
            'library/published.pdf',
        ]);
        $event = collect(app(Schedule::class)->events())->first(fn ($event) => str_contains($event->command ?? '', 'library:prune-uploads'));
        $this->assertNotNull($event);
        $this->assertSame('0 0 * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }

    public function test_cleanup_skips_an_upload_while_a_chunk_request_holds_its_lock(): void
    {
        $id = $this->upload($this->pdf('busy-upload'));
        $this->setCreatedAt($id, time() - 25 * 3600);
        $lock = fopen(Storage::disk('local')->path('library-uploads/'.$id.'/.lock'), 'c');
        $this->assertTrue(flock($lock, LOCK_EX | LOCK_NB));

        try {
            $this->assertSame(0, app(LibraryUploadService::class)->pruneExpired());
            Storage::disk('local')->assertExists('library-uploads/'.$id.'/body.part');
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        $this->assertSame(1, app(LibraryUploadService::class)->pruneExpired());
        $this->assertFalse(Storage::disk('local')->directoryExists('library-uploads/'.$id));
    }

    public function test_cleanup_preserves_malformed_states_and_unexpected_directory_contents(): void
    {
        $malformed = (string) Str::uuid();
        Storage::disk('local')->put('library-uploads/'.$malformed.'/state.json', '{interrupted JSON');
        Storage::disk('local')->put('library-uploads/'.$malformed.'/body.part', 'keep');
        $unexpected = $this->upload($this->pdf('unexpected-entry'));
        $this->setCreatedAt($unexpected, time() - 25 * 3600);
        Storage::disk('local')->put('library-uploads/'.$unexpected.'/other-folder/keep.txt', 'not an upload component');

        $this->assertSame(0, app(LibraryUploadService::class)->pruneExpired());
        Storage::disk('local')->assertExists([
            'library-uploads/'.$malformed.'/body.part',
            'library-uploads/'.$unexpected.'/state.json',
            'library-uploads/'.$unexpected.'/body.part',
            'library-uploads/'.$unexpected.'/other-folder/keep.txt',
        ]);
    }

    public function test_cleanup_and_expiry_validation_honor_configured_lifetime(): void
    {
        config(['library.upload_ttl_hours' => 1]);
        $expired = $this->upload($this->pdf('one-hour-limit'));
        $active = $this->upload($this->pdf('still-current'));
        $this->setCreatedAt($expired, time() - 3601);
        $this->setCreatedAt($active, time() - 3500);

        $this->finish($expired)->assertSessionHasErrors('file');
        $this->assertSame(1, app(LibraryUploadService::class)->pruneExpired());
        $this->assertFalse(Storage::disk('local')->directoryExists('library-uploads/'.$expired));
        Storage::disk('local')->assertExists('library-uploads/'.$active.'/body.part');
        $this->assertDatabaseCount('library_resources', 0);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'status' => 'active']);
    }

    private function start(string $filename, int $size): string
    {
        return $this->postJson(route('admin.library.uploads.start'), ['name' => $filename, 'size' => $size])
            ->assertCreated()->assertJsonPath('chunk_size', config('library.chunk_bytes'))->json('upload_id');
    }

    private function chunk(string $id, int $index, string $content): TestResponse
    {
        return $this->postJson(route('admin.library.uploads.chunk', $id), [
            'index' => $index,
            'chunk' => $this->file('chunk.bin', $content),
        ]);
    }

    private function upload(string $content, string $filename = 'book.pdf'): string
    {
        $id = $this->start($filename, strlen($content));
        $chunkSize = config('library.chunk_bytes');
        for ($offset = 0, $index = 0; $offset < strlen($content); $offset += $chunkSize, $index++) {
            $this->chunk($id, $index, substr($content, $offset, $chunkSize))->assertOk();
        }

        return $id;
    }

    private function finish(string $id, array $data = []): TestResponse
    {
        return $this->post(route('admin.library.store'), array_replace(['upload_id' => $id, 'is_public' => 1], $data));
    }

    private function state(string $id): array
    {
        return json_decode(Storage::disk('local')->get('library-uploads/'.$id.'/state.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    private function setCreatedAt(string $id, int $timestamp): void
    {
        $state = $this->state($id);
        $state['created_at'] = $timestamp;
        Storage::disk('local')->put('library-uploads/'.$id.'/state.json', json_encode($state, JSON_THROW_ON_ERROR));
    }

    private function file(string $name, string $content): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'library-upload-test-');
        $this->temporaryFiles[] = $path;
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, null, null, true);
    }

    private function pdf(string $marker, int $size = 2048): string
    {
        // A real single-page PDF with a harmless padded comment; no mocked MIME or size.
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 100 100] /Contents 4 0 R >>',
            "<< /Length 0 >>\nstream\n\nendstream",
        ];
        $body = "%PDF-1.4\n%{$marker}\n";
        $offsets = [0];
        foreach ($objects as $index => $object) {
            $offsets[] = strlen($body);
            $body .= ($index + 1)." 0 obj\n{$object}\nendobj\n";
        }
        $xref = "xref\n0 5\n0000000000 65535 f \n";
        foreach (array_slice($offsets, 1) as $offset) {
            $xref .= sprintf("%010d 00000 n \n", $offset);
        }
        $trailer = "trailer\n<< /Size 5 /Root 1 0 R >>\nstartxref\n";
        $footerLength = strlen($xref.$trailer) + 10 + strlen("\n%%EOF\n");
        $body .= '%'.str_repeat('p', $size - strlen($body) - $footerLength - 2)."\n";

        return $body.$xref.$trailer.sprintf('%010d', strlen($body))."\n%%EOF\n";
    }
}
