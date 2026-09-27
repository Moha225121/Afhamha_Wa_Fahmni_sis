<?php

namespace Tests\Feature;

use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use App\Services\SchoolAccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SchoolAccountNumberingTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_and_teacher_receive_numbers_and_lowercase_school_emails(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin', 'status' => 'active']));
        foreach (['students', 'teachers'] as $resource) {
            $this->post('/admin/'.$resource, ['name' => 'محمد أحمد', 'first_name_en' => 'Mohamed', 'last_name_en' => 'Ahmed', 'status' => 'active'])->assertSessionHasNoErrors();
        }
        $student = Student::firstOrFail();
        $teacher = Teacher::firstOrFail();
        $this->assertSame('ST-'.now()->year.'-0001', $student->student_number);
        $this->assertSame('TCH-'.now()->year.'-0001', $teacher->teacher_number);
        $this->assertSame('mohamed_'.strtolower($student->student_number).'@awf_alnisbiya.com', $student->user->email);
        $this->assertTrue($student->user->school_email_generated);
        auth()->logout();
        $this->post('/login', ['email' => $student->user->email, 'password' => 'AWFN#26'])->assertRedirect('/student/dashboard');
    }

    public function test_generated_email_collision_has_a_unique_suffix(): void
    {
        $accounts = app(SchoolAccountService::class);
        $settings = $accounts->settings();
        $settings['student_email_pattern'] = '{FIRST_NAME}@awf_{SCHOOL_NAME}.com';
        DB::table('account_number_settings')->where('id', 1)->update(['configuration' => json_encode($settings)]);
        $a = $accounts->create('student', ['name' => 'أ', 'first_name_en' => 'Mohamed', 'password' => 'password123', 'status' => 'active'], ['status' => 'active']);
        $b = $accounts->create('student', ['name' => 'ب', 'first_name_en' => 'Mohamed', 'password' => 'password123', 'status' => 'active'], ['status' => 'active']);
        $this->assertSame('mohamed@awf_alnisbiya.com', $a->user->email);
        $this->assertSame('mohamed_2@awf_alnisbiya.com', $b->user->email);
        $this->assertNotSame($a->student_number, $b->student_number);
    }

    public function test_preview_renumbers_without_changing_ids_relations_or_passwords(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin', 'status' => 'active']));
        $accounts = app(SchoolAccountService::class);
        $student = $accounts->create('student', ['name' => 'طالب', 'first_name_en' => 'Ali', 'password' => 'password123', 'status' => 'active'], ['status' => 'active']);
        $oldNumber = $student->student_number;
        $userId = $student->user_id;
        $hash = $student->user->password;
        $settings = $accounts->settings();
        $settings['student_number_pattern'] = 'NEW-{YEAR}-{00001}';
        $settings['school_english_name'] = 'New School';
        $response = $this->post(route('admin.accounts.preview'), $settings + ['update_student_emails' => 1]);
        $response->assertOk()->assertSee('NEW-'.now()->year.'-00001');
        $this->assertSame($oldNumber, $student->fresh()->student_number);
        $this->put(route('admin.accounts.update'), ['revision' => session('account_numbering_preview.revision')])->assertSessionHasNoErrors();
        $student->refresh();
        $this->assertSame($userId, $student->user_id);
        $this->assertSame($hash, $student->user->password);
        $this->assertSame('NEW-'.now()->year.'-00001', $student->student_number);
        $this->assertStringEndsWith('@awf_newschool.com', $student->user->email);
        $this->assertDatabaseHas('audit_logs', ['action' => 'account_regenerated', 'record_id' => $student->id]);
        $this->get(route('admin.students.account-card', $student))->assertOk()->assertSee($student->user->email)->assertDontSee('password123');
    }

    public function test_stale_preview_and_unknown_tokens_are_rejected_without_changes(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin', 'status' => 'active']));
        $accounts = app(SchoolAccountService::class);
        $settings = $accounts->settings();
        $this->post(route('admin.accounts.preview'), $settings)->assertOk();
        $revision = session('account_numbering_preview.revision');
        User::factory()->create();
        $this->put(route('admin.accounts.update'), ['revision' => $revision])->assertSessionHasErrors('preview');
        $this->assertEquals($settings, $accounts->settings());
        $settings['student_number_pattern'] = 'X-{UNKNOWN}-{0001}';
        $this->post(route('admin.accounts.preview'), $settings)->assertSessionHasErrors('student_number_pattern');
    }

    public function test_renumber_can_keep_existing_email_and_missing_english_name_blocks_email_changes(): void
    {
        $accounts = app(SchoolAccountService::class);
        $s = $accounts->create('student', ['name' => 'قديم', 'email' => 'old@example.test', 'password' => 'password123'], ['status' => 'active']);
        $settings = $accounts->settings();
        $settings['student_number_pattern'] = 'N-{0001}';
        $options = ['renumber_student' => true];
        $plan = $accounts->preview($settings, $options);
        $accounts->save($settings, $options, $plan['revision']);
        $this->assertSame('old@example.test', $s->fresh()->user->email);
        $this->expectException(ValidationException::class);
        $accounts->preview($settings, ['update_student_emails' => true]);
    }

    public function test_failed_audit_rolls_back_all_regeneration_changes(): void
    {
        $accounts = app(SchoolAccountService::class);
        $student = $accounts->create('student', ['name' => 'طالب', 'first_name_en' => 'Ali', 'password' => 'password123'], ['status' => 'active']);
        $number = $student->student_number;
        $email = $student->user->email;
        $settings = $accounts->settings();
        $settings['student_number_pattern'] = 'R-{0001}';
        $options = ['renumber_student' => true, 'update_student_emails' => true];
        $plan = $accounts->preview($settings, $options);
        Event::listen('eloquent.creating: App\\Models\\AuditLog', function ($audit): void {
            if ($audit->action === 'account_regenerated') {
                throw new \RuntimeException('Simulated audit failure');
            }
        });
        try {
            $accounts->save($settings, $options, $plan['revision']);
            $this->fail('Expected rollback');
        } catch (\RuntimeException $e) {
            $this->assertSame('Simulated audit failure', $e->getMessage());
        }
        $this->assertSame($number, $student->fresh()->student_number);
        $this->assertSame($email, $student->fresh()->user->email);
        $this->assertNotSame('R-{0001}', $accounts->settings()['student_number_pattern']);
        $this->assertDatabaseMissing('students', ['student_number' => 'R-0001']);
    }
}
