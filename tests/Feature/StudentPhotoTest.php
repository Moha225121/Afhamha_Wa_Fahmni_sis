<?php

namespace Tests\Feature;

use App\Models\Student;
use App\Models\User;
use App\Services\SchoolAccountService;
use App\Services\StudentCardPdfService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StudentPhotoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->actingAs(User::factory()->create(['role' => 'admin', 'status' => 'active']));
    }

    public function test_create_and_edit_forms_allow_photo_upload_and_show_the_saved_photo(): void
    {
        $this->get(route('admin.students.create'))->assertOk()
            ->assertSee('enctype="multipart/form-data"', false)
            ->assertSee('name="photo"', false);

        $student = $this->studentWithPhoto();
        $this->get(route('admin.students.edit', $student))->assertOk()
            ->assertSee('enctype="multipart/form-data"', false)
            ->assertSee('name="photo"', false)
            ->assertSee(Storage::disk('public')->url($student->user->avatar_path));
    }

    public function test_creating_student_stores_an_optional_photo(): void
    {
        $this->post(route('admin.students.store'), [
            'name' => 'طالب بصورة', 'first_name_en' => 'Ali', 'status' => 'active',
            'photo' => UploadedFile::fake()->image('portrait.jpg', 120, 150),
        ])->assertSessionHasNoErrors()->assertRedirect();

        $student = Student::firstOrFail();
        $this->assertStringStartsWith('student-photos/', $student->user->avatar_path);
        Storage::disk('public')->assertExists($student->user->avatar_path);

        $this->post(route('admin.students.store'), [
            'name' => 'طالب بدون صورة', 'first_name_en' => 'Omar', 'status' => 'active',
        ])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertNull(Student::latest('id')->firstOrFail()->user->avatar_path);
    }

    public function test_editing_without_a_photo_preserves_it_and_replacing_it_removes_the_old_file(): void
    {
        $student = $this->studentWithPhoto();
        $original = $student->user->avatar_path;
        $this->put(route('admin.students.update', $student), $this->payload($student))
            ->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame($original, $student->fresh()->user->avatar_path);
        Storage::disk('public')->assertExists($original);

        $this->put(route('admin.students.update', $student), $this->payload($student) + [
            'photo' => UploadedFile::fake()->image('replacement.png', 160, 200),
        ])->assertSessionHasNoErrors()->assertRedirect();
        $replacement = $student->fresh()->user->avatar_path;
        $this->assertNotSame($original, $replacement);
        Storage::disk('public')->assertExists($replacement);
        Storage::disk('public')->assertMissing($original);
    }

    public function test_invalid_or_oversized_uploads_do_not_replace_the_saved_photo(): void
    {
        $student = $this->studentWithPhoto();
        $original = $student->user->avatar_path;
        foreach ([
            UploadedFile::fake()->createWithContent('fake.jpg', 'This is not an image.')->mimeType('text/plain'),
            UploadedFile::fake()->image('large.jpg')->size(4097),
            UploadedFile::fake()->image('unsupported.gif'),
        ] as $photo) {
            $this->put(route('admin.students.update', $student), $this->payload($student) + ['photo' => $photo])
                ->assertSessionHasErrors('photo');
            $this->assertSame($original, $student->fresh()->user->avatar_path);
            Storage::disk('public')->assertExists($original);
        }
        $this->assertCount(1, Storage::disk('public')->allFiles());
    }

    public function test_single_and_class_print_views_show_the_saved_photo(): void
    {
        $student = $this->studentWithPhoto();
        $image = Storage::disk('public')->url($student->user->avatar_path);
        $this->get(route('admin.students.account-card', $student))->assertOk()
            ->assertSee($image, false)->assertSee('alt="QR"', false);
        $this->get(route('admin.students.cards'))->assertOk()->assertSee($image, false);
    }

    public function test_single_and_bulk_pdf_embed_photo_bytes_without_fetching_a_public_url(): void
    {
        $student = $this->studentWithPhoto();
        $bytes = Storage::disk('public')->get($student->user->avatar_path);
        $response = $this->get(route('admin.students.account-card', $student, false).'?format=pdf')
            ->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $response->getContent());
        $this->assertStringContainsString($bytes, $response->getContent());
        $this->assertSame(1, preg_match_all('/\/Type\s*\/Page\b/', $response->getContent()), 'A student card must fit on one PDF page.');

        $student->setAttribute('verification_url', 'https://example.test/verify/student');
        $bulk = app(StudentCardPdfService::class)->downloadBulk(collect([$student]), 'cards.pdf');
        $this->assertStringStartsWith('%PDF-', $bulk->getContent());
        $this->assertStringContainsString($bytes, $bulk->getContent());
    }

    public function test_webp_upload_is_included_in_print_and_pdf(): void
    {
        $student = $this->studentWithPhoto();
        $this->put(route('admin.students.update', $student), $this->payload($student) + [
            'photo' => UploadedFile::fake()->image('portrait.webp', 120, 150),
        ])->assertSessionHasNoErrors();
        $this->get(route('admin.students.account-card', $student))->assertOk()
            ->assertSee(Storage::disk('public')->url($student->fresh()->user->avatar_path), false);
        $this->get(route('admin.students.account-card', $student, false).'?format=pdf')
            ->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_missing_photo_uses_placeholder_and_does_not_break_pdf(): void
    {
        $student = $this->studentWithPhoto();
        Storage::disk('public')->delete($student->user->avatar_path);
        $this->get(route('admin.students.account-card', $student))->assertOk()
            ->assertSee('<span>صورة الطالب</span>', false)->assertDontSee('alt="صورة الطالب"', false);
        $this->get(route('admin.students.cards'))->assertOk()->assertDontSee('alt="صورة الطالب"', false);
        $this->get(route('admin.students.account-card', $student, false).'?format=pdf')
            ->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    private function studentWithPhoto(): Student
    {
        $path = UploadedFile::fake()->image('portrait.jpg', 120, 150)->store('student-photos', 'public');

        return app(SchoolAccountService::class)->create('student', [
            'name' => 'طالب تجريبي', 'first_name_en' => 'Ali', 'password' => 'password123',
            'status' => 'active', 'avatar_path' => $path,
        ], ['status' => 'active']);
    }

    private function payload(Student $student): array
    {
        return [
            'name' => $student->user->name, 'email' => $student->user->email,
            'student_number' => $student->student_number, 'status' => 'active',
        ];
    }
}
