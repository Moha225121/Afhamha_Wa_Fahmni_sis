<?php

namespace Tests\Feature;

use App\Models\Student;
use App\Models\User;
use App\Services\StudentCardPdfService;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StudentCardPhotoTest extends TestCase
{
    public function test_printable_cards_use_small_cached_photo_without_changing_the_original(): void
    {
        Storage::fake('public');
        $original = file_get_contents(public_path('icons/nasr-logo.jpg'));
        Storage::disk('public')->put('student-photos/example.jpg', $original);

        $student = new Student();
        $student->setRelation('user', new User(['avatar_path' => 'student-photos/example.jpg']));
        $service = app(StudentCardPdfService::class);

        $service->preparePrintableCards(collect([$student]));
        $thumbnail = substr(ltrim(parse_url($student->photo_image, PHP_URL_PATH), '/'), strlen('storage/'));

        $this->assertStringStartsWith('student-card-thumbnails/', $thumbnail);
        $this->assertTrue(Storage::disk('public')->exists($thumbnail));
        $this->assertSame([260, 300], array_slice(getimagesize(Storage::disk('public')->path($thumbnail)), 0, 2));
        $this->assertSame($original, Storage::disk('public')->get('student-photos/example.jpg'));

        $service->preparePrintableCards(collect([$student]));
        $this->assertCount(1, Storage::disk('public')->files('student-card-thumbnails'));
    }
}
