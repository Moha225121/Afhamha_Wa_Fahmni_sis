<?php

namespace Tests\Feature;

use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class NisbiyaStudentCardPrivacyTest extends TestCase
{
    use RefreshDatabase;

    private function student(): Student
    {
        return Student::create(['user_id' => User::factory()->create(['role' => 'student', 'status' => 'active'])->id, 'student_number' => 'QA-PRIVATE-CARD', 'status' => 'active']);
    }

    public function test_signed_card_does_not_automatically_make_student_information_public(): void
    {
        $student = $this->student();
        $url = URL::temporarySignedRoute('student.card.verify', now()->addHour(), ['student' => $student]);
        $this->get($url)->assertNotFound()->assertDontSee($student->user->name)->assertDontSee($student->user->email);
        $this->actingAs(User::factory()->create(['role' => 'admin', 'status' => 'active']))->get($url)->assertOk()
            ->assertSeeText($student->student_number)->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->actingAs(User::factory()->create(['role' => 'teacher', 'status' => 'active']))->get($url)->assertNotFound();
        $this->actingAs(User::factory()->create(['role' => 'admin', 'status' => 'inactive']))->get($url)->assertNotFound();
    }

    public function test_explicitly_approved_public_verification_still_requires_a_current_signature_and_active_student(): void
    {
        config(['nasbia.public_student_card_verification' => true]);
        $student = $this->student();
        $url = URL::temporarySignedRoute('student.card.verify', now()->addHour(), ['student' => $student]);
        $this->get($url)->assertOk()->assertSeeText($student->student_number)->assertDontSee($student->user->email);
        $this->get(route('student.card.verify', $student))->assertForbidden();
        $student->update(['status' => 'inactive']);
        $this->get($url)->assertNotFound();
    }
}
