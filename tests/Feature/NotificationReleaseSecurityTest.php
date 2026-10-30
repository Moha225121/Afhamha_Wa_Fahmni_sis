<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Guardian;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesStudentEducationFixtures;
use Tests\TestCase;

class NotificationReleaseSecurityTest extends TestCase
{
    use CreatesStudentEducationFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['fcm.enabled' => false, 'services.web_push.public_key' => null, 'services.web_push.private_key' => null]);
        $this->withoutVite();
    }

    public function test_failed_login_attempts_are_limited_without_storing_the_password_in_the_response(): void
    {
        $password = Str::random(32);
        $user = User::factory()->create(['role' => 'admin', 'status' => 'active', 'password' => $password]);
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/login', ['email' => $user->email, 'password' => $password.'wrong'])->assertRedirect();
        }
        $this->postJson('/login', ['email' => $user->email, 'password' => $password.'wrong'])
            ->assertTooManyRequests()->assertHeader('Retry-After')->assertDontSee($password);
        $this->assertGuest();
        $this->travel(61)->seconds();
        $this->post('/login', ['email' => $user->email, 'password' => $password])->assertRedirect('/admin/dashboard');
        $this->assertAuthenticatedAs($user);
    }

    public function test_login_limit_cannot_be_bypassed_with_email_case_but_does_not_lock_another_ip(): void
    {
        $password = Str::random(32);
        $user = User::factory()->create(['role' => 'admin', 'status' => 'active', 'password' => $password]);
        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['email' => $user->email, 'password' => $password.'wrong'])->assertRedirect();
        }
        $this->postJson('/login', ['email' => strtoupper($user->email), 'password' => $password])->assertTooManyRequests();
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.20'])->post('/login', ['email' => $user->email, 'password' => $password])
            ->assertRedirect('/admin/dashboard');
        $this->assertAuthenticatedAs($user);
    }

    public function test_successful_login_clears_only_its_failed_identity_attempts(): void
    {
        $password = Str::random(32);
        $user = User::factory()->create(['role' => 'admin', 'status' => 'active', 'password' => $password]);
        for ($i = 0; $i < 4; $i++) {
            $this->post('/login', ['email' => $user->email, 'password' => $password.'wrong'])->assertRedirect();
        }
        $this->post('/login', ['email' => $user->email, 'password' => $password])->assertRedirect('/admin/dashboard');
        $this->post('/logout')->assertRedirect('/login');
        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['email' => $user->email, 'password' => $password.'wrong'])->assertRedirect();
        }
        $this->assertGuest();
    }

    public function test_unlinked_guardian_cannot_reply_to_a_conversation_they_used_to_access(): void
    {
        $fixture = $this->createStudentFixture('conversation');
        $parent = User::factory()->create(['role' => 'parent', 'status' => 'active']);
        $guardian = Guardian::create(['user_id' => $parent->id, 'status' => 'active']);
        $guardian->students()->attach($fixture['student']);
        $subject = $this->createSubjectFor($fixture['classroom'], 'conversation');
        $recipient = User::factory()->create(['role' => 'teacher', 'status' => 'active']);
        $teacher = Teacher::create(['user_id' => $recipient->id, 'status' => 'active']);
        $teacher->classrooms()->attach($fixture['classroom'], ['subject_id' => $subject->id]);
        $conversation = Conversation::create(['student_id' => $fixture['student']->id, 'created_by' => $parent->id, 'status' => 'open', 'subject' => 'رسالة طفل أزيل ربطه']);
        $conversation->participants()->attach([$parent->id, $recipient->id]);
        $this->actingAs($parent)->post('/parent/conversations/'.$conversation->id.'/messages', ['body' => 'قبل إزالة الربط'])->assertRedirect();
        $this->assertDatabaseCount('messages', 1);
        $other = $this->createStudentFixture('still-linked');
        $guardian->students()->attach($other['student']);
        $guardian->students()->detach($fixture['student']);
        $this->get('/parent/conversations')->assertOk()->assertDontSee('قبل إزالة الربط');
        $this->get('/parent/conversations/'.$conversation->id)->assertNotFound();
        $this->post('/parent/conversations/'.$conversation->id.'/messages', ['body' => 'لا يجوز إرسالها'])->assertNotFound();
        $this->assertDatabaseCount('messages', 1);
        $this->assertDatabaseCount('notifications', 1);
    }

    public function test_guardian_message_targets_an_active_teacher_and_links_to_the_teacher_portal(): void
    {
        [$teacher, $fixture] = $this->teacherFixture();
        $parent = User::factory()->create(['role' => 'parent', 'status' => 'active']);
        $guardian = Guardian::create(['user_id' => $parent->id, 'status' => 'active']);
        $guardian->students()->attach($fixture['student']);
        $data = ['student_id' => $fixture['student']->id, 'recipient_id' => $teacher->user_id, 'subject' => 'متابعة', 'body' => 'رسالة اختبار محلية'];
        $this->actingAs($parent)->post('/parent/conversations', $data)->assertRedirect();
        $this->assertSame(route('teacher.conversations.show', Conversation::sole()), $teacher->user->notifications()->sole()->data['url']);
        $teacher->update(['status' => 'inactive']);
        $this->post('/parent/conversations', $data)->assertNotFound();
        $this->assertDatabaseCount('conversations', 1);
        $this->assertDatabaseCount('notifications', 1);
    }

    public function test_assignment_editor_sanitizes_stored_and_flashed_rich_text_without_removing_formatting(): void
    {
        [$teacher, $fixture, $subject] = $this->teacherFixture();
        $id = DB::table('assignments')->insertGetId([
            'teacher_id' => $teacher->id, 'classroom_id' => $fixture['classroom']->id, 'subject_id' => $subject->id,
            'title' => 'واجب آمن', 'due_date' => today()->addDay(), 'max_score' => 10, 'status' => 'active',
            'description' => '<p style="text-align:center"><b>شرح عربي</b><img src=x onerror="auditAttack()"></p>',
        ]);
        $this->actingAs($teacher->user)->get('/teacher/assignments/'.$id.'/edit')->assertOk()
            ->assertDontSee('onerror')->assertSee('<b>شرح عربي</b>', false)->assertSee('text-align:center', false)
            ->assertSee('aria-label="وصف الواجب"', false);
        $this->withSession(['_old_input' => ['description' => '<svg onload="auditAttack()"></svg><i>مسودة</i>']])
            ->get('/teacher/assignments/create')->assertOk()->assertDontSee('onload')->assertSee('<i>مسودة</i>', false);
    }

    public function test_exam_editor_bootstrap_sanitizes_question_html_before_javascript_uses_it(): void
    {
        [$teacher, $fixture, $subject] = $this->teacherFixture();
        $id = DB::table('exams')->insertGetId([
            'teacher_id' => $teacher->id, 'classroom_id' => $fixture['classroom']->id, 'subject_id' => $subject->id,
            'title' => 'اختبار آمن', 'starts_at' => now()->addDay(), 'duration_minutes' => 30, 'total_score' => 1, 'status' => 'draft',
        ]);
        DB::table('exam_questions')->insert([
            'exam_id' => $id, 'type' => 'short_answer', 'question_text' => 'سؤال',
            'text' => '<b>سؤال</b><img src=x onerror="auditAttack()">', 'score' => 1, 'order' => 1,
        ]);
        $this->actingAs($teacher->user)->get('/teacher/exams/'.$id.'/edit')->assertOk()->assertDontSee('onerror');
    }

    public function test_exam_validation_preserves_unsaved_questions_safely_instead_of_replacing_them(): void
    {
        [$teacher, $fixture, $subject] = $this->teacherFixture();
        $response = $this->actingAs($teacher->user)->from('/teacher/exams/create')->post('/teacher/exams', [
            'title' => '', 'classroom_id' => $fixture['classroom']->id, 'subject_id' => $subject->id,
            'starts_at' => now()->addDay()->format('Y-m-d H:i:s'), 'duration_minutes' => 30, 'status' => 'draft',
            'questions' => [['type' => 'short_answer', 'text' => '<b>مسودة لم تحفظ</b><img src=x onerror="auditAttack()">', 'score' => 2]],
        ])->assertSessionHasErrors('title');
        $page = $this->withCookie(config('session.cookie'), $response->getCookie(config('session.cookie'))->getValue())
            ->get('/teacher/exams/create')->assertOk()->assertDontSee('onerror');
        preg_match('/id="existing-questions-data"[^>]*>(.*?)<\/script>/s', $page->getContent(), $match);
        $questions = json_decode($match[1], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('short_answer', $questions[0]['type']);
        $this->assertSame('<b>مسودة لم تحفظ</b>', $questions[0]['text']);
        $this->assertSame(2, $questions[0]['score']);
        $this->assertDatabaseCount('exams', 0);
    }

    public function test_malformed_exam_old_input_does_not_create_a_rendering_exception(): void
    {
        [$teacher] = $this->teacherFixture();
        $this->actingAs($teacher->user)->withSession(['_old_input' => [
            'questions' => ['invalid', ['type' => ['invalid'], 'text' => ['invalid'], 'score' => ['invalid'], 'choices' => 'invalid']],
        ]])->get('/teacher/exams/create')->assertOk();
    }

    private function teacherFixture(): array
    {
        $fixture = $this->createStudentFixture('editor');
        $subject = $this->createSubjectFor($fixture['classroom'], 'editor');
        $user = User::factory()->create(['role' => 'teacher', 'status' => 'active']);
        $teacher = Teacher::create(['user_id' => $user->id, 'status' => 'active']);
        $teacher->classrooms()->attach($fixture['classroom'], ['subject_id' => $subject->id]);

        return [$teacher, $fixture, $subject];
    }
}
