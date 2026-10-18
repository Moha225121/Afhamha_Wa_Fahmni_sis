<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Classroom;
use App\Models\Guardian;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupervisorGuardianCallNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_guardian_call_notifies_the_linked_parent_and_is_visible_in_both_parent_views(): void
    {
        $year = AcademicYear::create(['name' => '2026', 'starts_at' => '2026-09-01', 'ends_at' => '2027-06-01', 'is_current' => true]);
        $classroom = Classroom::create(['name' => 'الأول', 'stage' => 'أساسي', 'section' => 'أ', 'academic_year_id' => $year->id]);
        $supervisor = User::factory()->create(['role' => 'supervisor', 'status' => 'active']);
        $parent = User::factory()->create(['role' => 'parent', 'status' => 'active']);
        $studentUser = User::factory()->create(['name' => 'الطالب التجريبي', 'role' => 'student', 'status' => 'active']);
        $student = Student::create(['user_id' => $studentUser->id, 'student_number' => 'S-9001', 'classroom_id' => $classroom->id, 'status' => 'active']);
        $guardian = Guardian::create(['user_id' => $parent->id, 'status' => 'active']);
        $guardian->students()->attach($student);
        $supervisor->supervisedClassrooms()->attach($classroom);

        $this->actingAs($supervisor)->post('/supervisor/guardian-calls', [
            'student_id' => $student->id,
            'category' => 'attendance',
            'reason' => 'تكرار الغياب',
            'guardian_visible_details' => 'يرجى التواصل مع المدرسة.',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('guardian_calls', ['student_id' => $student->id, 'status' => 'notified']);
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $parent->id]);
        $this->actingAs($parent)->get('/parent/notifications')->assertOk()->assertSeeText('استدعاء ولي الأمر')->assertSeeText('الطالب التجريبي')->assertSeeText('عرض التفاصيل');
        $this->actingAs($parent)->get('/parent/guardian-calls?student='.$student->id)->assertOk()->assertSeeText('تكرار الغياب')->assertSeeText('تم الإبلاغ')->assertSeeText('يرجى التواصل مع المدرسة.');
    }
}
