<?php

namespace Tests\Feature;

use App\Contracts\SmartTutorGateway;
use App\Data\SmartTutorPrompt;
use App\Data\SmartTutorReply;
use App\Exceptions\SmartTutorGatewayException;
use App\Models\Teacher;
use App\Models\TutorConversation;
use App\Models\User;
use App\Services\SmartTutorConversationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Concerns\CreatesStudentEducationFixtures;
use Tests\TestCase;

class TeacherSmartTutorTest extends TestCase
{
    use CreatesStudentEducationFixtures, RefreshDatabase;

    private function teacher(): User
    {
        $user = User::factory()->create(['role' => 'teacher', 'status' => 'active']);
        Teacher::create(['user_id' => $user->id, 'status' => 'active']);

        return $user;
    }

    private function createConversation(User $user, ?string $key = null): TutorConversation
    {
        $this->actingAs($user)->post('/teacher/tutor/conversations', [
            'request_id' => $key ?? (string) Str::uuid(),
        ])->assertRedirect();

        return TutorConversation::where('teacher_user_id', $user->id)->latest('id')->firstOrFail();
    }

    public function test_teacher_creates_private_conversation_and_duplicate_create_and_message_are_idempotent(): void
    {
        $teacher = $this->teacher();
        $gateway = new class implements SmartTutorGateway {
            public array $prompts = [];
            public function reply(SmartTutorPrompt $prompt): SmartTutorReply
            {
                $this->prompts[] = $prompt;
                return new SmartTutorReply('خطة درس عامة');
            }
        };
        $this->app->instance(SmartTutorGateway::class, $gateway);
        $this->actingAs($teacher)->get('/teacher/tutor')->assertOk()->assertSeeText('المساعد التعليمي');
        $key = (string) Str::uuid();
        $conversation = $this->createConversation($teacher, $key);
        $duplicate = $this->createConversation($teacher, $key);
        $this->assertSame($conversation->id, $duplicate->id);
        $this->assertNull($conversation->student_id);
        $this->assertSame($teacher->id, $conversation->teacher_user_id);
        $this->assertDatabaseCount('students', 0);
        $this->assertDatabaseCount('tutor_conversations', 1);
        $payload = ['message' => 'أعد خطة درس عامة عن الكسور', 'request_id' => (string) Str::uuid()];
        foreach ([1, 2] as $_) {
            $this->post('/teacher/tutor/conversations/'.$conversation->id.'/messages', $payload)
                ->assertRedirect('/teacher/tutor/conversations/'.$conversation->id)->assertSessionHasNoErrors();
        }
        $this->assertCount(1, $gateway->prompts);
        $this->assertSame([], $gateway->prompts[0]->context);
        $this->assertStringContainsString('مساعد تعليمي للمعلم', $gateway->prompts[0]->turns[0]->content);
        $this->assertStringNotContainsString('مساعد للطالب', $gateway->prompts[0]->turns[0]->content);
        $this->assertDatabaseCount('tutor_messages', 2);
        $this->post('/teacher/tutor/conversations/'.$conversation->id.'/messages', array_replace($payload, ['message' => 'سؤال مختلف بنفس المعرف']))
            ->assertRedirect()->assertSessionHasErrors('tutor');
        $this->assertCount(1, $gateway->prompts);
        $this->assertDatabaseCount('tutor_messages', 2);
        $this->get('/teacher/tutor/conversations/'.$conversation->id)->assertOk()->assertSeeText($payload['message'])->assertSeeText('خطة درس عامة');
        Http::assertNothingSent();
    }

    public function test_roles_and_conversation_owners_cannot_cross_access_even_with_forged_context(): void
    {
        $teacher = $this->teacher();
        $other = $this->teacher();
        $student = $this->createStudentFixture('TEACHER-TUTOR');
        $studentConversation = $student['student']->tutorConversations()->create(['title' => 'PRIVATE STUDENT']);
        $conversation = $this->createConversation($teacher);
        $payload = ['message' => 'محاولة وصول', 'request_id' => (string) Str::uuid()];
        $url = '/teacher/tutor/conversations/'.$conversation->id;
        $this->actingAs($other)->get($url)->assertNotFound();
        $this->post($url.'/messages', $payload)->assertNotFound();
        $this->actingAs($teacher)->get('/teacher/tutor/conversations/'.$studentConversation->id)->assertNotFound();
        $this->post('/teacher/tutor/conversations/'.$studentConversation->id.'/messages', $payload)->assertNotFound();
        $this->get('/teacher/tutor')->assertOk()->assertDontSeeText('PRIVATE STUDENT');
        $this->post($url.'/messages', $payload + ['student_id' => $student['student']->id, 'role' => 'student', 'context' => ['stage' => 'SECRET']])
            ->assertSessionHasErrors('payload');
        $this->post('/teacher/tutor/conversations', ['request_id' => (string) Str::uuid(), 'teacher_user_id' => $other->id])
            ->assertSessionHasErrors('payload');
        foreach ([$student['user'], User::factory()->create(['role' => 'parent', 'status' => 'active']), User::factory()->create(['role' => 'admin', 'status' => 'active'])] as $outsider) {
            $this->actingAs($outsider)->get('/teacher/tutor')->assertForbidden();
            $this->get($url)->assertForbidden();
            $this->post($url.'/messages', $payload)->assertForbidden();
            $this->post('/teacher/tutor/conversations', ['request_id' => (string) Str::uuid()])->assertForbidden();
        }
        $this->actingAs($student['user'])->get('/student/tutor/conversations/'.$conversation->id)->assertNotFound();
        $this->post('/student/tutor/conversations/'.$conversation->id.'/messages', $payload)->assertNotFound();
        $this->actingAs($teacher)->get('/student/tutor')->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'teacher', 'status' => 'active']))->get('/teacher/tutor')->assertForbidden();
        $teacher->teacher->update(['status' => 'inactive']);
        $teacher->unsetRelation('teacher');
        $this->actingAs($teacher)->get('/teacher/tutor')->assertForbidden();
        $this->assertDatabaseCount('tutor_messages', 0);
        Http::assertNothingSent();
    }

    public function test_service_uses_persisted_owner_to_exclude_student_context_and_rejects_ambiguous_ownership(): void
    {
        $teacher = $this->teacher();
        $conversation = $this->createConversation($teacher);
        $gateway = new class implements SmartTutorGateway {
            public array $prompts = [];
            public function reply(SmartTutorPrompt $prompt): SmartTutorReply
            {
                $this->prompts[] = $prompt;
                return new SmartTutorReply('شرح عام');
            }
        };
        $this->app->instance(SmartTutorGateway::class, $gateway);
        $service = app(SmartTutorConversationService::class);
        $service->send($conversation, 'شرح تعليمي', (string) Str::uuid(), ['classroom' => 'PRIVATE CLASS', 'stage' => 'PRIVATE STAGE']);
        $this->assertSame([], $gateway->prompts[0]->context);
        $student = $this->createStudentFixture('AMBIGUOUS');
        DB::table('tutor_conversations')->where('id', $conversation->id)->update(['student_id' => $student['student']->id]);
        $this->actingAs($teacher)->get('/teacher/tutor/conversations/'.$conversation->id)->assertNotFound();
        $this->actingAs($student['user'])->get('/student/tutor/conversations/'.$conversation->id)->assertNotFound();
        try {
            $service->send($conversation->fresh(), 'لا ترسل', (string) Str::uuid());
            $this->fail('An ambiguous owner must never reach the gateway.');
        } catch (SmartTutorGatewayException $exception) {
            $this->assertSame(SmartTutorGatewayException::REQUEST_CONFLICT, $exception->reason);
        }
        $this->assertCount(1, $gateway->prompts);
        $this->assertDatabaseCount('tutor_messages', 2);
        Http::assertNothingSent();
    }

    public function test_missing_credentials_and_provider_failure_preserve_question_without_500_or_secret(): void
    {
        config(['smart_tutor.gemini.api_key' => '']);
        $teacher = $this->teacher();
        $conversation = $this->createConversation($teacher);
        $url = '/teacher/tutor/conversations/'.$conversation->id;
        $payload = ['message' => 'سؤال دون مزود', 'request_id' => (string) Str::uuid()];
        foreach ([1, 2] as $_) {
            $this->post($url.'/messages', $payload)->assertRedirect($url)->assertSessionHasErrors('tutor')
                ->assertSessionHas('errors', fn ($errors) => str_contains($errors->first('tutor'), 'غير مهيأة'));
        }
        $this->get($url)->assertOk()->assertSeeText($payload['message']);
        $this->assertDatabaseCount('tutor_messages', 1);
        $gateway = new class implements SmartTutorGateway {
            public int $calls = 0;
            public function reply(SmartTutorPrompt $prompt): SmartTutorReply
            {
                $this->calls++;
                throw new RuntimeException('SECRET-UPSTREAM-DETAIL');
            }
        };
        $this->app->instance(SmartTutorGateway::class, $gateway);
        $failed = ['message' => 'سؤال عند عطل المزود', 'request_id' => (string) Str::uuid()];
        foreach ([1, 2] as $_) {
            $this->post($url.'/messages', $failed)->assertRedirect($url)->assertSessionHasErrors('tutor')
                ->assertSessionHas('errors', fn ($errors) => str_contains($errors->first('tutor'), 'حُفظ سؤالك بأمان'));
        }
        $this->get($url)->assertOk()->assertSeeText($failed['message'])->assertDontSeeText('SECRET-UPSTREAM-DETAIL');
        $this->assertSame(1, $gateway->calls);
        $this->assertDatabaseCount('tutor_messages', 2);
        $this->assertDatabaseMissing('tutor_messages', ['role' => 'assistant']);
        Http::assertNothingSent();
    }

    public function test_teacher_output_is_sanitized_and_student_prompt_and_data_remain_unchanged(): void
    {
        $teacher = $this->teacher();
        $student = $this->createStudentFixture('TUTOR-LEGACY');
        $studentConversation = $student['student']->tutorConversations()->create(['title' => 'طالب محفوظ']);
        $gateway = new class implements SmartTutorGateway {
            public array $prompts = [];
            public function reply(SmartTutorPrompt $prompt): SmartTutorReply
            {
                $this->prompts[] = $prompt;
                return new SmartTutorReply("**شرح آمن**\n<script>alert('teacher-xss')</script>\n[رابط](javascript:alert(1))");
            }
        };
        $this->app->instance(SmartTutorGateway::class, $gateway);
        $conversation = $this->createConversation($teacher);
        $message = '<img src=x onerror=alert(1)> سؤال تعليمي';
        $this->post('/teacher/tutor/conversations/'.$conversation->id.'/messages', ['message' => $message, 'request_id' => (string) Str::uuid()])->assertRedirect();
        $this->get('/teacher/tutor/conversations/'.$conversation->id)->assertOk()
            ->assertSee(e($message), false)->assertSee('<strong>شرح آمن</strong>', false)
            ->assertDontSee("<script>alert('teacher-xss')</script>", false)->assertDontSee('href="javascript:', false);
        $this->actingAs($student['user'])->post('/student/tutor/conversations/'.$studentConversation->id.'/messages', [
            'message' => 'اشرح الكسور للطالب', 'request_id' => (string) Str::uuid(),
        ])->assertRedirect();
        $this->assertStringContainsString('مساعد للطالب', $gateway->prompts[1]->turns[0]->content);
        $this->assertSame(['classroom' => $student['classroom']->name, 'stage' => $student['classroom']->stage], $gateway->prompts[1]->context);
        $this->assertCount(2, $gateway->prompts[1]->turns);
        $this->assertNull($studentConversation->fresh()->teacher_user_id);
        $this->get('/student/tutor/conversations/'.$studentConversation->id)->assertOk()->assertDontSeeText($message);
        Http::assertNothingSent();
    }

}
