<?php

namespace Tests\Feature;

use App\Contracts\SmartTutorGateway;
use App\Data\SmartTutorPrompt;
use App\Data\SmartTutorReply;
use App\Models\TutorConversation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Concerns\CreatesStudentEducationFixtures;
use Tests\TestCase;

class StudentSmartTutorTest extends TestCase
{
    use CreatesStudentEducationFixtures;
    use RefreshDatabase;

    public function test_student_can_create_conversation_and_persist_user_and_assistant_messages(): void
    {
        $student = $this->createStudentFixture('A');
        $gateway = new class implements SmartTutorGateway
        {
            /** @var list<SmartTutorPrompt> */
            public array $prompts = [];

            public function reply(SmartTutorPrompt $prompt): SmartTutorReply
            {
                $this->prompts[] = $prompt;

                return new SmartTutorReply('الإجابة التعليمية');
            }
        };
        $this->app->instance(SmartTutorGateway::class, $gateway);

        $create = $this->actingAs($student['user'])->post(route('student.tutor.conversations.store'));
        $conversation = TutorConversation::query()->where('student_id', $student['student']->id)->firstOrFail();
        $create->assertRedirect(route('student.tutor.show', $conversation));
        $this->actingAs($student['user'])->post(route('student.tutor.messages.store', $conversation), [
            'message' => 'اشرح الكسور',
            'request_id' => (string) Str::uuid(),
        ])->assertRedirect(route('student.tutor.show', $conversation));

        $this->assertDatabaseHas('tutor_messages', ['tutor_conversation_id' => $conversation->id, 'role' => 'user', 'content' => 'اشرح الكسور']);
        $this->assertDatabaseHas('tutor_messages', ['tutor_conversation_id' => $conversation->id, 'role' => 'assistant', 'content' => 'الإجابة التعليمية']);
        $this->assertCount(1, $gateway->prompts);
        $this->actingAs($student['user'])->get(route('student.tutor.show', $conversation))
            ->assertOk()
            ->assertSeeText('اشرح الكسور')
            ->assertSeeText('الإجابة التعليمية');

    }

    public function test_student_cannot_read_or_post_to_another_students_conversation(): void
    {
        $studentA = $this->createStudentFixture('A');
        $studentB = $this->createStudentFixture('B');
        $conversationB = $studentB['student']->tutorConversations()->create(['title' => 'محادثة خاصة']);
        $gateway = new class implements SmartTutorGateway
        {
            public int $calls = 0;

            public function reply(SmartTutorPrompt $prompt): SmartTutorReply
            {
                $this->calls++;

                return new SmartTutorReply('لن يستدعى');
            }
        };
        $this->app->instance(SmartTutorGateway::class, $gateway);

        $this->actingAs($studentA['user'])->get(route('student.tutor.index'))
            ->assertOk()
            ->assertDontSeeText('محادثة خاصة');
        $this->actingAs($studentA['user'])->get(route('student.tutor.show', $conversationB))->assertNotFound();
        $this->actingAs($studentA['user'])->get('/student/tutor/conversations/not-a-number')->assertNotFound();
        $this->actingAs($studentA['user'])->post(route('student.tutor.messages.store', $conversationB), [
            'message' => 'محاولة وصول',
            'request_id' => (string) Str::uuid(),
        ])->assertNotFound();
        $this->actingAs($studentA['user'])->post('/student/tutor/conversations/not-a-number/messages', [
            'message' => 'محاولة وصول',
            'request_id' => (string) Str::uuid(),
        ])->assertNotFound();
        $this->assertDatabaseCount('tutor_messages', 0);
        $this->assertSame(0, $gateway->calls);
    }

    public function test_missing_provider_fails_safely_after_saving_the_student_message(): void
    {
        $student = $this->createStudentFixture('A');
        $conversation = $student['student']->tutorConversations()->create(['title' => 'محادثة جديدة']);

        $this->actingAs($student['user'])->post(route('student.tutor.messages.store', $conversation), [
            'message' => 'سؤال محفوظ',
            'request_id' => (string) Str::uuid(),
        ])->assertRedirect(route('student.tutor.show', $conversation))->assertSessionHasErrors('tutor');

        $this->assertDatabaseHas('tutor_messages', ['tutor_conversation_id' => $conversation->id, 'role' => 'user', 'content' => 'سؤال محفوظ']);
        $this->assertDatabaseMissing('tutor_messages', ['tutor_conversation_id' => $conversation->id, 'role' => 'assistant']);
    }

    public function test_unexpected_gateway_failure_is_translated_to_a_safe_domain_error(): void
    {
        $student = $this->createStudentFixture('A');
        $conversation = $student['student']->tutorConversations()->create(['title' => 'محادثة جديدة']);
        $this->app->instance(SmartTutorGateway::class, new class implements SmartTutorGateway
        {
            public function reply(SmartTutorPrompt $prompt): SmartTutorReply
            {
                throw new RuntimeException('provider detail that must not reach the browser');
            }
        });

        $response = $this->actingAs($student['user'])
            ->followingRedirects()
            ->post(route('student.tutor.messages.store', $conversation), [
                'message' => 'سؤال عند فشل الشبكة',
                'request_id' => (string) Str::uuid(),
            ]);

        $response->assertOk()
            ->assertSeeText('تعذر الحصول على رد من المعلّم الذكي. حُفظ سؤالك بأمان.')
            ->assertDontSeeText('provider detail that must not reach the browser');
        $this->assertDatabaseHas('tutor_messages', ['tutor_conversation_id' => $conversation->id, 'role' => 'user']);
        $this->assertDatabaseMissing('tutor_messages', ['tutor_conversation_id' => $conversation->id, 'role' => 'assistant']);
        $this->assertDatabaseMissing('tutor_messages', ['content' => 'provider detail that must not reach the browser']);
    }
}
