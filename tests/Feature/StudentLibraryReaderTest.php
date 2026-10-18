<?php

namespace Tests\Feature;

use App\Models\LibraryResource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesStudentEducationFixtures;
use Tests\TestCase;

class StudentLibraryReaderTest extends TestCase
{
    use CreatesStudentEducationFixtures;
    use RefreshDatabase;

    public function test_student_reader_uses_authorized_file_url_and_matching_compatibility_assets(): void
    {
        Storage::fake('local');
        $student = $this->createStudentFixture('READER');
        $other = $this->createStudentFixture('PRIVATE');
        Storage::disk('local')->put('library/book.pdf', "%PDF-1.4\nreader-content");
        $book = LibraryResource::create(['title' => 'كتاب خاص', 'disk' => 'local', 'file_path' => 'library/book.pdf',
            'classroom_id' => $student['classroom']->id, 'is_public' => false, 'status' => 'active', 'created_by' => $student['user']->id]);

        foreach (['read', 'file', 'download'] as $action) {
            $this->get(route('student.library.'.$action, $book))->assertRedirect('/login');
            $this->actingAs($other['user'])->get(route('student.library.'.$action, $book))->assertNotFound();
            auth()->forgetGuards();
        }
        $this->actingAs($student['user'])->get(route('student.library.read', $book))->assertOk()
            ->assertSee('data-pdf-url="'.route('student.library.file', $book).'"', false)
            ->assertSee('vendor/pdfjs/legacy/student.mjs', false)
            ->assertSee('vendor/pdfjs/legacy/student.worker.mjs', false)
            ->assertSeeText('فتح مستقل')->assertSeeText('تنزيل الكتاب')
            ->assertDontSee('/storage/library/book.pdf', false);
        $file = $this->get(route('student.library.file', $book))->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringStartsWith('inline;', $file->headers->get('Content-Disposition'));
        $this->assertStringContainsString('private', $file->headers->get('Cache-Control'));
        $this->assertSame(realpath(Storage::disk('local')->path('library/book.pdf')), $file->baseResponse->getFile()->getPathname());
        $this->get(route('student.library.download', $book))->assertOk()->assertDownload();
        $this->withHeader('Range', 'bytes=0-7')->get(route('student.library.file', $book))
            ->assertStatus(206)->assertHeader('Content-Length', '8')->assertHeader('Accept-Ranges', 'bytes');
        foreach (['legacy/student.mjs', 'legacy/student.worker.mjs', 'legacy/compat.js', 'legacy/pdf.mjs', 'legacy/pdf.worker.mjs'] as $asset) {
            $this->assertFileExists(public_path('vendor/pdfjs/'.$asset));
        }
    }

    public function test_missing_book_has_an_explanation_and_unsupported_formats_offer_downloads(): void
    {
        Storage::fake('local');
        $student = $this->createStudentFixture('FALLBACK');
        $book = LibraryResource::create(['title' => 'كتاب مفقود', 'disk' => 'local', 'file_path' => 'library/missing.pdf',
            'is_public' => true, 'status' => 'active', 'created_by' => $student['user']->id]);
        $this->actingAs($student['user'])->get(route('student.library.read', $book))->assertNotFound()
            ->assertSeeText('ملف الكتاب غير متاح حاليًا')->assertSee(route('student.library.index'), false);
        $this->get(route('student.library.file', $book))->assertNotFound();
        $this->get(route('student.library.download', $book))->assertNotFound();
        Storage::disk('local')->put('library/notes.txt', 'notes');
        $book->update(['file_path' => 'library/notes.txt']);
        $this->get(route('student.library.read', $book))->assertOk()
            ->assertSeeText('هذا المورد متاح للتنزيل')->assertDontSee('data-library-reader-canvas', false);
        $this->get(route('student.library.file', $book))->assertOk()->assertDownload()
            ->assertHeader('Content-Type', 'application/octet-stream');
    }
}
