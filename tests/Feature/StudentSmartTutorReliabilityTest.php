<?php

namespace Tests\Feature;

use App\Contracts\SmartTutorGateway;
use App\Data\SmartTutorPrompt;
use App\Data\SmartTutorReply;
use App\Exceptions\SmartTutorGatewayException;
use App\Models\TutorMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\CreatesStudentEducationFixtures;
use Tests\TestCase;

class StudentSmartTutorReliabilityTest extends TestCase
{
    use CreatesStudentEducationFixtures;
    use RefreshDatabase;

    public function test_repeated_client_request_is_idempotent_after_success(): void
    {
        $student = $this->createStudentFixture('A');
        $conversation = $student['student']->tutorConversations()->create(['title' => 'محادثة جديدة']);
        $gateway = new class implements SmartTutorGateway
        {
            public int $calls = 0;

            public function reply(SmartTutorPrompt $prompt): SmartTutorReply
            {
                $this->calls++;

                return new SmartTutorReply('إجابة واحدة');
            }
        };
        $this->app->instance(SmartTutorGateway::class, $gateway);
        $requestId = strtoupper((string) Str::uuid());

        $this->actingAs($student['user'])->post(route('student.tutor.messages.store', $conversation), [
            'message' => 'السؤال الأصلي',
            'request_id' => $requestId,
        ])->assertRedirect(route('student.tutor.show', $conversation));
        $this->actingAs($student['user'])->post(route('student.tutor.messages.store', $conversation), [
            'message' => 'السؤال الأصلي',
            'request_id' => strtolower($requestId),
        ])->assertRedirect(route('student.tutor.show', $conversation));
        $this->actingAs($student['user'])->post(route('student.tutor.messages.store', $conversation), [
            'message' => 'نص مختلف في إعادة الطلب',
            'request_id' => $requestId,
        ])->assertSessionHasErrors('tutor');

        $this->assertSame(1, $gateway->calls);
        $this->assertDatabaseCount('tutor_messages', 2);
        $this->assertDatabaseHas('tutor_messages', [
            'tutor_conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => 'السؤال الأصلي',
            'client_request_id' => strtolower($requestId),
            'delivery_status' => 'answered',
        ]);
        $this->assertDatabaseMissing('tutor_messages', ['content' => 'نص مختلف في إعادة الطلب']);

        $question = $conversation->messages()->where('role', 'user')->firstOrFail();
        $this->assertDatabaseHas('tutor_messages', [
            'role' => 'assistant',
            'content' => 'إجابة واحدة',
            'in_reply_to_message_id' => $question->id,
        ]);
    }

    public function test_repeated_failed_request_does_not_duplicate_message_or_gateway_call(): void
    {
        $student = $this->createStudentFixture('A');
        $conversation = $student['student']->tutorConversations()->create(['title' => 'محادثة جديدة']);
        $gateway = new class implements SmartTutorGateway
        {
            public int $calls = 0;

            public function reply(SmartTutorPrompt $prompt): SmartTutorReply
            {
                $this->calls++;

                throw SmartTutorGatewayException::timeout();
            }
        };
        $this->app->instance(SmartTutorGateway::class, $gateway);
        $requestId = (string) Str::uuid();
        $payload = ['message' => 'سؤال لا يتكرر', 'request_id' => $requestId];

        $this->actingAs($student['user'])->post(route('student.tutor.messages.store', $conversation), $payload)
            ->assertSessionHasErrors('tutor');
        $this->actingAs($student['user'])->post(route('student.tutor.messages.store', $conversation), $payload)
            ->assertSessionHasErrors('tutor');

        $this->assertSame(1, $gateway->calls);
        $this->assertDatabaseCount('tutor_messages', 1);
        $this->assertDatabaseHas('tutor_messages', [
            'role' => 'user',
            'content' => 'سؤال لا يتكرر',
            'delivery_status' => 'failed',
            'failure_reason' => SmartTutorGatewayException::TIMEOUT,
        ]);
    }

    public function test_message_validation_is_strict_and_gateway_is_not_called(): void
    {
        $student = $this->createStudentFixture('A');
        $conversation = $student['student']->tutorConversations()->create(['title' => 'محادثة جديدة']);
        $gateway = new class implements SmartTutorGateway
        {
            public int $calls = 0;

            public function reply(SmartTutorPrompt $prompt): SmartTutorReply
            {
                $this->calls++;

                return new SmartTutorReply('لن يصل الطلب إلى هنا');
            }
        };
        $this->app->instance(SmartTutorGateway::class, $gateway);

        $invalidPayloads = [
            [['request_id' => (string) Str::uuid()], 'message'],
            [['message' => 'سؤال بلا معرّف'], 'request_id'],
            [['message' => '   ', 'request_id' => (string) Str::uuid()], 'message'],
            [['message' => ['ليس نصًا'], 'request_id' => (string) Str::uuid()], 'message'],
            [['message' => 'س', 'request_id' => (string) Str::uuid()], 'message'],
            [['message' => ' س ', 'request_id' => (string) Str::uuid()], 'message'],
            [['message' => str_repeat('س', 4001), 'request_id' => (string) Str::uuid()], 'message'],
            [['message' => "سؤال\x00غير صالح", 'request_id' => (string) Str::uuid()], 'message'],
            [['message' => "\xB1\x31", 'request_id' => (string) Str::uuid()], 'message'],
            [['message' => "\u{200B}", 'request_id' => (string) Str::uuid()], 'message'],
            [['message' => "س\u{200B}", 'request_id' => (string) Str::uuid()], 'message'],
            [['message' => 'سؤال صالح', 'request_id' => 'not-a-uuid'], 'request_id'],
            [[
                'message' => 'سؤال صالح',
                'request_id' => (string) Str::uuid(),
                'unexpected' => 'blocked',
            ], 'payload'],
        ];

        foreach ($invalidPayloads as [$payload, $errorKey]) {
            $this->actingAs($student['user'])
                ->from(route('student.tutor.show', $conversation))
                ->post(route('student.tutor.messages.store', $conversation), $payload)
                ->assertRedirect(route('student.tutor.show', $conversation))
                ->assertSessionHasErrors($errorKey);
        }

        $this->assertSame(0, $gateway->calls);
        $this->assertDatabaseCount('tutor_messages', 0);
    }

    #[DataProvider('providerFailureCases')]
    public function test_provider_failures_are_safe_and_preserve_the_student_message(
        string $reason,
        string $safeMessage,
    ): void {
        $student = $this->createStudentFixture(Str::random(6));
        $conversation = $student['student']->tutorConversations()->create(['title' => 'محادثة جديدة']);
        $gateway = new class($reason) implements SmartTutorGateway
        {
            public function __construct(private readonly string $reason) {}

            public function reply(SmartTutorPrompt $prompt): SmartTutorReply
            {
                throw SmartTutorGatewayException::fromReason(
                    $this->reason,
                    new RuntimeException('raw-provider-secret-and-stack-detail'),
                    $this->reason === SmartTutorGatewayException::RATE_LIMITED ? 45 : null,
                );
            }
        };
        $this->app->instance(SmartTutorGateway::class, $gateway);

        $response = $this->actingAs($student['user'])
            ->followingRedirects()
            ->post(route('student.tutor.messages.store', $conversation), [
                'message' => 'سؤال محفوظ عند الفشل',
                'request_id' => (string) Str::uuid(),
            ]);

        $response->assertOk()
            ->assertSeeText($safeMessage)
            ->assertDontSeeText('raw-provider-secret-and-stack-detail');
        $this->assertDatabaseHas('tutor_messages', [
            'tutor_conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => 'سؤال محفوظ عند الفشل',
            'delivery_status' => 'failed',
            'failure_reason' => $reason,
        ]);
        $this->assertDatabaseMissing('tutor_messages', ['role' => 'assistant']);
        $this->assertDatabaseMissing('tutor_messages', ['content' => 'raw-provider-secret-and-stack-detail']);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function providerFailureCases(): array
    {
        return [
            'timeout' => [
                SmartTutorGatewayException::TIMEOUT,
                'انتهت مهلة اتصال المعلّم الذكي. حُفظ سؤالك ولم تُحفظ إجابة فارغة.',
            ],
            'HTTP 429' => [
                SmartTutorGatewayException::RATE_LIMITED,
                'مزود المعلّم الذكي مشغول حاليًا. حُفظ سؤالك، ويرجى المحاولة لاحقًا.',
            ],
            'HTTP 500' => [
                SmartTutorGatewayException::UPSTREAM_FAILURE,
                'تعذر الحصول على رد من المعلّم الذكي. حُفظ سؤالك بأمان.',
            ],
            'invalid provider response' => [
                SmartTutorGatewayException::INVALID_RESPONSE,
                'وصل رد غير صالح من المعلّم الذكي. حُفظ سؤالك ولم يُحفظ الرد.',
            ],
        ];
    }

    #[DataProvider('invalidReplyCases')]
    public function test_empty_or_invalid_gateway_reply_is_not_saved(string $replyContent): void
    {
        $student = $this->createStudentFixture(Str::random(6));
        $conversation = $student['student']->tutorConversations()->create(['title' => 'محادثة جديدة']);
        $this->app->instance(SmartTutorGateway::class, new class($replyContent) implements SmartTutorGateway
        {
            public function __construct(private readonly string $replyContent) {}

            public function reply(SmartTutorPrompt $prompt): SmartTutorReply
            {
                return new SmartTutorReply($this->replyContent);
            }
        });

        $this->actingAs($student['user'])->post(route('student.tutor.messages.store', $conversation), [
            'message' => 'سؤال يبقى محفوظًا',
            'request_id' => (string) Str::uuid(),
        ])->assertSessionHasErrors('tutor');

        $this->assertDatabaseHas('tutor_messages', [
            'role' => 'user',
            'delivery_status' => 'failed',
            'failure_reason' => SmartTutorGatewayException::INVALID_RESPONSE,
        ]);
        $this->assertDatabaseMissing('tutor_messages', ['role' => 'assistant']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidReplyCases(): array
    {
        return [
            'empty reply' => [" \n\t "],
            'invalid UTF-8 reply' => ["\xB1\x31"],
            'control-character reply' => ["رد\x00غير صالح"],
            'invisible Unicode reply' => ["\u{200B}"],
            'overlong reply' => [str_repeat('ر', 12001)],
        ];
    }

    public function test_prompt_message_limit_keeps_recent_answered_pairs_in_chronological_order(): void
    {
        config([
            'smart_tutor.history.max_messages' => 5,
            'smart_tutor.history.max_characters' => 1000,
        ]);
        $student = $this->createStudentFixture('A');
        $otherStudent = $this->createStudentFixture('B');
        $conversation = $student['student']->tutorConversations()->create(['title' => 'السياق']);
        $otherConversation = $otherStudent['student']->tutorConversations()->create(['title' => 'سري']);

        foreach (range(1, 4) as $index) {
            $question = $conversation->messages()->create([
                'role' => 'user',
                'content' => "q{$index}",
                'client_request_id' => (string) Str::uuid(),
                'delivery_status' => 'answered',
            ]);
            $conversation->messages()->create([
                'role' => 'assistant',
                'content' => "a{$index}",
                'delivery_status' => 'answered',
                'in_reply_to_message_id' => $question->id,
            ]);
        }

        $conversation->messages()->create([
            'role' => 'user',
            'content' => 'failed-secret',
            'client_request_id' => (string) Str::uuid(),
            'delivery_status' => 'failed',
            'failure_reason' => SmartTutorGatewayException::TIMEOUT,
        ]);
        $otherQuestion = $otherConversation->messages()->create([
            'role' => 'user',
            'content' => 'other-student-secret',
            'client_request_id' => (string) Str::uuid(),
            'delivery_status' => 'answered',
        ]);
        $otherConversation->messages()->create([
            'role' => 'assistant',
            'content' => 'other-student-answer',
            'delivery_status' => 'answered',
            'in_reply_to_message_id' => $otherQuestion->id,
        ]);
        $crossConversationQuestion = $otherConversation->messages()->create([
            'role' => 'user',
            'content' => 'cross-student-secret',
            'client_request_id' => (string) Str::uuid(),
            'delivery_status' => 'answered',
        ]);
        $conversation->messages()->create([
            'role' => 'assistant',
            'content' => 'malformed-cross-answer',
            'delivery_status' => 'answered',
            'in_reply_to_message_id' => $crossConversationQuestion->id,
        ]);
        $corruptQuestion = $conversation->messages()->create([
            'role' => 'user',
            'content' => 'corrupt-question',
            'client_request_id' => (string) Str::uuid(),
            'delivery_status' => 'answered',
        ]);
        $conversation->messages()->create([
            'role' => 'assistant',
            'content' => 'failed-assistant-secret',
            'delivery_status' => 'failed',
            'in_reply_to_message_id' => $corruptQuestion->id,
        ]);

        $gateway = new class implements SmartTutorGateway
        {
            public ?SmartTutorPrompt $prompt = null;

            public function reply(SmartTutorPrompt $prompt): SmartTutorReply
            {
                $this->prompt = $prompt;

                return new SmartTutorReply('done');
            }
        };
        $this->app->instance(SmartTutorGateway::class, $gateway);

        $this->actingAs($student['user'])->post(route('student.tutor.messages.store', $conversation), [
            'message' => 'current',
            'request_id' => (string) Str::uuid(),
        ])->assertRedirect(route('student.tutor.show', $conversation));

        $this->assertNotNull($gateway->prompt);
        $roles = array_map(fn ($turn) => $turn->role, $gateway->prompt->turns);
        $contents = array_map(fn ($turn) => $turn->content, $gateway->prompt->turns);

        $this->assertSame(['system', 'user', 'assistant', 'user', 'assistant', 'user'], $roles);
        $this->assertSame(['q3', 'a3', 'q4', 'a4', 'current'], array_slice($contents, 1));
        $this->assertNotContains('failed-secret', $contents);
        $this->assertNotContains('other-student-secret', $contents);
        $this->assertNotContains('cross-student-secret', $contents);
        $this->assertNotContains('malformed-cross-answer', $contents);
        $this->assertNotContains('failed-assistant-secret', $contents);
        $this->assertSame([
            'classroom' => $student['classroom']->name,
            'stage' => $student['classroom']->stage,
        ], $gateway->prompt->context);
    }

    public function test_prompt_character_budget_is_enforced_independently_of_message_limit(): void
    {
        config([
            'smart_tutor.history.max_messages' => 20,
            'smart_tutor.history.max_characters' => 11,
        ]);
        $student = $this->createStudentFixture('A');
        $conversation = $student['student']->tutorConversations()->create(['title' => 'حد الحروف']);

        foreach (range(1, 2) as $index) {
            $question = $conversation->messages()->create([
                'role' => 'user',
                'content' => "q{$index}",
                'client_request_id' => (string) Str::uuid(),
                'delivery_status' => 'answered',
            ]);
            $conversation->messages()->create([
                'role' => 'assistant',
                'content' => "a{$index}",
                'delivery_status' => 'answered',
                'in_reply_to_message_id' => $question->id,
            ]);
        }

        $gateway = new class implements SmartTutorGateway
        {
            public ?SmartTutorPrompt $prompt = null;

            public function reply(SmartTutorPrompt $prompt): SmartTutorReply
            {
                $this->prompt = $prompt;

                return new SmartTutorReply('done');
            }
        };
        $this->app->instance(SmartTutorGateway::class, $gateway);

        $this->actingAs($student['user'])->post(route('student.tutor.messages.store', $conversation), [
            'message' => 'current',
            'request_id' => (string) Str::uuid(),
        ])->assertRedirect(route('student.tutor.show', $conversation));

        $this->assertNotNull($gateway->prompt);
        $contents = array_map(fn ($turn) => $turn->content, $gateway->prompt->turns);
        $this->assertSame(['q2', 'a2', 'current'], array_slice($contents, 1));
        $this->assertLessThanOrEqual(
            11,
            array_sum(array_map('mb_strlen', array_slice($contents, 1))),
        );
    }

    public function test_recent_pending_replay_is_not_sent_again_or_reported_as_success(): void
    {
        $student = $this->createStudentFixture('A');
        $conversation = $student['student']->tutorConversations()->create(['title' => 'قيد المعالجة']);
        $requestId = (string) Str::uuid();
        $conversation->messages()->create([
            'role' => 'user',
            'content' => 'سؤال قيد المعالجة',
            'client_request_id' => $requestId,
            'delivery_status' => 'pending',
        ]);
        $gateway = new class implements SmartTutorGateway
        {
            public int $calls = 0;

            public function reply(SmartTutorPrompt $prompt): SmartTutorReply
            {
                $this->calls++;

                return new SmartTutorReply('لا يجب استدعائي');
            }
        };
        $this->app->instance(SmartTutorGateway::class, $gateway);

        $this->actingAs($student['user'])->post(route('student.tutor.messages.store', $conversation), [
            'message' => 'سؤال قيد المعالجة',
            'request_id' => $requestId,
        ])->assertSessionHasErrors('tutor')
            ->assertSessionHasInput('message', 'سؤال قيد المعالجة')
            ->assertSessionHasInput('request_id', $requestId);

        $this->assertSame(0, $gateway->calls);
        $this->assertDatabaseHas('tutor_messages', [
            'client_request_id' => $requestId,
            'delivery_status' => 'pending',
        ]);
        $this->actingAs($student['user'])->get(route('student.tutor.show', $conversation))
            ->assertOk()
            ->assertSee('data-tutor-request-id="'.$requestId.'"', false)
            ->assertSeeText('متابعة الطلب نفسه');
    }

    public function test_stale_pending_replay_is_marked_failed_without_resending(): void
    {
        config(['smart_tutor.idempotency.pending_timeout_seconds' => 60]);
        $student = $this->createStudentFixture('A');
        $conversation = $student['student']->tutorConversations()->create(['title' => 'طلب قديم']);
        $requestId = (string) Str::uuid();
        $pendingMessage = $conversation->messages()->create([
            'role' => 'user',
            'content' => 'سؤال قديم',
            'client_request_id' => $requestId,
            'delivery_status' => 'pending',
        ]);
        $pendingMessage->forceFill(['created_at' => now()->subMinutes(2)])->save();
        $gateway = new class implements SmartTutorGateway
        {
            public int $calls = 0;

            public function reply(SmartTutorPrompt $prompt): SmartTutorReply
            {
                $this->calls++;

                return new SmartTutorReply('لا يجب استدعائي');
            }
        };
        $this->app->instance(SmartTutorGateway::class, $gateway);

        $this->actingAs($student['user'])->post(route('student.tutor.messages.store', $conversation), [
            'message' => 'سؤال قديم',
            'request_id' => $requestId,
        ])->assertSessionHasErrors('tutor');

        $this->assertSame(0, $gateway->calls);
        $this->assertDatabaseHas('tutor_messages', [
            'client_request_id' => $requestId,
            'delivery_status' => 'failed',
            'failure_reason' => SmartTutorGatewayException::STALE_PENDING,
        ]);
    }

    public function test_stale_recovery_winning_before_a_late_reply_keeps_one_terminal_state(): void
    {
        $student = $this->createStudentFixture('A');
        $conversation = $student['student']->tutorConversations()->create(['title' => 'سباق الحالات']);
        $gateway = new class($conversation->id) implements SmartTutorGateway
        {
            public int $calls = 0;

            public function __construct(private readonly int $conversationId) {}

            public function reply(SmartTutorPrompt $prompt): SmartTutorReply
            {
                $this->calls++;
                TutorMessage::query()
                    ->where('tutor_conversation_id', $this->conversationId)
                    ->where('role', 'user')
                    ->where('delivery_status', 'pending')
                    ->update([
                        'delivery_status' => 'failed',
                        'failure_reason' => SmartTutorGatewayException::STALE_PENDING,
                    ]);

                return new SmartTutorReply('إجابة وصلت بعد حسم الحالة');
            }
        };
        $this->app->instance(SmartTutorGateway::class, $gateway);

        $this->actingAs($student['user'])->post(route('student.tutor.messages.store', $conversation), [
            'message' => 'اختبر السباق الذري',
            'request_id' => (string) Str::uuid(),
        ])->assertSessionHasErrors('tutor');

        $this->assertSame(1, $gateway->calls);
        $this->assertDatabaseCount('tutor_messages', 1);
        $this->assertDatabaseHas('tutor_messages', [
            'tutor_conversation_id' => $conversation->id,
            'role' => 'user',
            'delivery_status' => 'failed',
            'failure_reason' => SmartTutorGatewayException::STALE_PENDING,
        ]);
        $this->assertDatabaseMissing('tutor_messages', ['role' => 'assistant']);
    }

    public function test_get_recovers_only_stale_pending_messages_and_preserves_completed_success(): void
    {
        config(['smart_tutor.idempotency.pending_timeout_seconds' => 60]);
        $student = $this->createStudentFixture('A');
        $conversation = $student['student']->tutorConversations()->create(['title' => 'استرداد آمن']);
        $staleRequestId = (string) Str::uuid();
        $stale = $conversation->messages()->create([
            'role' => 'user',
            'content' => 'سؤال انقطعت معالجته',
            'client_request_id' => $staleRequestId,
            'delivery_status' => 'pending',
        ]);
        $stale->forceFill(['created_at' => now()->subMinutes(2)])->save();
        $recent = $conversation->messages()->create([
            'role' => 'user',
            'content' => 'سؤال حديث قيد المعالجة',
            'client_request_id' => (string) Str::uuid(),
            'delivery_status' => 'pending',
        ]);
        $answered = $conversation->messages()->create([
            'role' => 'user',
            'content' => 'سؤال مكتمل',
            'client_request_id' => (string) Str::uuid(),
            'delivery_status' => 'answered',
        ]);
        $answered->forceFill(['created_at' => now()->subMinutes(3)])->save();
        $reply = $conversation->messages()->create([
            'role' => 'assistant',
            'content' => 'إجابة مكتملة',
            'delivery_status' => 'answered',
            'in_reply_to_message_id' => $answered->id,
        ]);
        $gateway = new class implements SmartTutorGateway
        {
            public int $calls = 0;

            public function reply(SmartTutorPrompt $prompt): SmartTutorReply
            {
                $this->calls++;

                return new SmartTutorReply('لا يجب استدعائي');
            }
        };
        $this->app->instance(SmartTutorGateway::class, $gateway);

        $response = $this->actingAs($student['user'])->get(route('student.tutor.show', $conversation));

        $response->assertOk()
            ->assertSeeText('لم يكتمل الطلب')
            ->assertSeeText('إعادة المحاولة كطلب جديد')
            ->assertDontSee($staleRequestId);
        $this->assertSame(0, $gateway->calls);
        $this->assertSame('failed', $stale->refresh()->delivery_status);
        $this->assertSame(SmartTutorGatewayException::STALE_PENDING, $stale->failure_reason);
        $this->assertSame('pending', $recent->refresh()->delivery_status);
        $this->assertSame('answered', $answered->refresh()->delivery_status);
        $this->assertSame($answered->id, $reply->refresh()->in_reply_to_message_id);
    }

    #[DataProvider('malformedReplayCases')]
    public function test_malformed_linked_reply_is_rejected_without_a_gateway_call(
        string $replyRole,
        string $replyStatus,
    ): void {
        $student = $this->createStudentFixture(Str::random(6));
        $conversation = $student['student']->tutorConversations()->create(['title' => 'بيانات غير متسقة']);
        $requestId = (string) Str::uuid();
        $question = $conversation->messages()->create([
            'role' => 'user',
            'content' => 'سؤال ثابت',
            'client_request_id' => $requestId,
            'delivery_status' => 'answered',
        ]);
        $conversation->messages()->create([
            'role' => $replyRole,
            'content' => 'صف مرتبط غير صالح',
            'delivery_status' => $replyStatus,
            'in_reply_to_message_id' => $question->id,
        ]);
        $gateway = new class implements SmartTutorGateway
        {
            public int $calls = 0;

            public function reply(SmartTutorPrompt $prompt): SmartTutorReply
            {
                $this->calls++;

                return new SmartTutorReply('لا يجب استدعائي');
            }
        };
        $this->app->instance(SmartTutorGateway::class, $gateway);

        $this->actingAs($student['user'])->post(route('student.tutor.messages.store', $conversation), [
            'message' => 'سؤال ثابت',
            'request_id' => $requestId,
        ])->assertSessionHasErrors('tutor');

        $this->assertSame(0, $gateway->calls);
        $this->assertDatabaseCount('tutor_messages', 2);
        $this->assertDatabaseMissing('tutor_messages', [
            'role' => 'assistant',
            'delivery_status' => 'answered',
            'content' => 'لا يجب استدعائي',
        ]);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function malformedReplayCases(): array
    {
        return [
            'linked row has the user role' => ['user', 'answered'],
            'linked assistant is not answered' => ['assistant', 'failed'],
        ];
    }

    public function test_answered_replay_with_invalid_assistant_content_is_rejected_without_gateway_call(): void
    {
        $student = $this->createStudentFixture('invalid-replay');
        $conversation = $student['student']->tutorConversations()->create(['title' => 'رد قديم غير صالح']);
        $requestId = (string) Str::uuid();
        $question = $conversation->messages()->create([
            'role' => 'user',
            'content' => 'سؤال محفوظ',
            'client_request_id' => $requestId,
            'delivery_status' => 'answered',
        ]);
        $conversation->messages()->create([
            'role' => 'assistant',
            'content' => "\u{200B}",
            'delivery_status' => 'answered',
            'in_reply_to_message_id' => $question->id,
        ]);
        $gateway = new class implements SmartTutorGateway
        {
            public int $calls = 0;

            public function reply(SmartTutorPrompt $prompt): SmartTutorReply
            {
                $this->calls++;

                return new SmartTutorReply('لا يجب استدعائي');
            }
        };
        $this->app->instance(SmartTutorGateway::class, $gateway);

        $this->actingAs($student['user'])->post(route('student.tutor.messages.store', $conversation), [
            'message' => 'سؤال محفوظ',
            'request_id' => $requestId,
        ])->assertSessionHasErrors('tutor');

        $this->assertSame(0, $gateway->calls);
        $this->assertDatabaseCount('tutor_messages', 2);
        $this->assertDatabaseMissing('tutor_messages', ['content' => 'لا يجب استدعائي']);
    }

    #[DataProvider('existingInvalidReplyCases')]
    public function test_existing_invalid_reply_cannot_promote_a_pending_question_to_answered(
        ?string $replyStatus,
        string $replyContent,
    ): void {
        $student = $this->createStudentFixture('invalid-existing-reply');
        $conversation = $student['student']->tutorConversations()->create(['title' => 'سباق رد غير صالح']);
        $gateway = new class($conversation->id, $replyStatus, $replyContent) implements SmartTutorGateway
        {
            public function __construct(
                private readonly int $conversationId,
                private readonly ?string $replyStatus,
                private readonly string $replyContent,
            ) {}

            public function reply(SmartTutorPrompt $prompt): SmartTutorReply
            {
                $question = TutorMessage::query()
                    ->where('tutor_conversation_id', $this->conversationId)
                    ->where('role', 'user')
                    ->where('delivery_status', 'pending')
                    ->firstOrFail();
                TutorMessage::query()->create([
                    'tutor_conversation_id' => $this->conversationId,
                    'role' => 'assistant',
                    'content' => $this->replyContent,
                    'delivery_status' => $this->replyStatus,
                    'in_reply_to_message_id' => $question->id,
                ]);

                return new SmartTutorReply('رد صالح وصل لاحقًا');
            }
        };
        $this->app->instance(SmartTutorGateway::class, $gateway);

        $this->actingAs($student['user'])->post(route('student.tutor.messages.store', $conversation), [
            'message' => 'لا ترقّ السؤال برد فاسد',
            'request_id' => (string) Str::uuid(),
        ])->assertSessionHasErrors('tutor');

        $this->assertDatabaseHas('tutor_messages', [
            'tutor_conversation_id' => $conversation->id,
            'role' => 'user',
            'delivery_status' => 'failed',
            'failure_reason' => SmartTutorGatewayException::INVALID_RESPONSE,
        ]);
        $this->assertDatabaseMissing('tutor_messages', [
            'tutor_conversation_id' => $conversation->id,
            'role' => 'user',
            'delivery_status' => 'answered',
        ]);
        $this->assertDatabaseHas('tutor_messages', [
            'tutor_conversation_id' => $conversation->id,
            'role' => 'assistant',
            'delivery_status' => 'failed',
            'failure_reason' => SmartTutorGatewayException::INVALID_RESPONSE,
        ]);
        $this->assertDatabaseMissing('tutor_messages', [
            'tutor_conversation_id' => $conversation->id,
            'role' => 'assistant',
            'delivery_status' => 'answered',
        ]);
    }

    /**
     * @return array<string, array{string|null, string}>
     */
    public static function existingInvalidReplyCases(): array
    {
        return [
            'invalid answered content' => ['answered', "\u{200B}"],
            'pending assistant state' => ['pending', 'رد صالح لكن حالته غير نهائية'],
            'missing assistant state' => [null, 'رد صالح بلا حالة نهائية'],
        ];
    }

    public function test_concurrent_valid_reply_wins_over_a_late_gateway_failure(): void
    {
        $student = $this->createStudentFixture('valid-concurrent-reply');
        $conversation = $student['student']->tutorConversations()->create(['title' => 'رد متزامن صالح']);
        $gateway = new class($conversation->id) implements SmartTutorGateway
        {
            public int $calls = 0;

            public function __construct(private readonly int $conversationId) {}

            public function reply(SmartTutorPrompt $prompt): SmartTutorReply
            {
                $this->calls++;
                $question = TutorMessage::query()
                    ->where('tutor_conversation_id', $this->conversationId)
                    ->where('role', 'user')
                    ->where('delivery_status', 'pending')
                    ->firstOrFail();
                TutorMessage::query()->create([
                    'tutor_conversation_id' => $this->conversationId,
                    'role' => 'assistant',
                    'content' => 'إجابة متزامنة مكتملة',
                    'delivery_status' => 'answered',
                    'in_reply_to_message_id' => $question->id,
                ]);

                throw SmartTutorGatewayException::timeout();
            }
        };
        $this->app->instance(SmartTutorGateway::class, $gateway);

        $this->actingAs($student['user'])->post(route('student.tutor.messages.store', $conversation), [
            'message' => 'اعتمد النتيجة النهائية الصحيحة',
            'request_id' => (string) Str::uuid(),
        ])->assertRedirect(route('student.tutor.show', $conversation))
            ->assertSessionDoesntHaveErrors();

        $this->assertSame(1, $gateway->calls);
        $this->assertDatabaseHas('tutor_messages', [
            'tutor_conversation_id' => $conversation->id,
            'role' => 'user',
            'delivery_status' => 'answered',
            'failure_reason' => null,
        ]);
        $this->assertDatabaseHas('tutor_messages', [
            'tutor_conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => 'إجابة متزامنة مكتملة',
            'delivery_status' => 'answered',
        ]);
    }

    public function test_conversation_rate_limit_is_per_student_and_separate_from_message_limit(): void
    {
        config(['smart_tutor.rate_limits.conversations_per_minute' => 10]);
        $studentA = $this->createStudentFixture('A');
        $studentB = $this->createStudentFixture('B');

        foreach (range(1, 10) as $index) {
            $this->actingAs($studentA['user'])
                ->post(route('student.tutor.conversations.store'))
                ->assertRedirect();
        }

        $this->actingAs($studentA['user'])
            ->post(route('student.tutor.conversations.store'))
            ->assertStatus(429)
            ->assertSeeText('تم تجاوز الحد المؤقت لإنشاء المحادثات.');
        $this->assertSame(10, $studentA['student']->tutorConversations()->count());

        $this->actingAs($studentB['user'])
            ->post(route('student.tutor.conversations.store'))
            ->assertRedirect();
        $this->assertSame(1, $studentB['student']->tutorConversations()->count());

        $gateway = new class implements SmartTutorGateway
        {
            public int $calls = 0;

            public function reply(SmartTutorPrompt $prompt): SmartTutorReply
            {
                $this->calls++;

                return new SmartTutorReply('إجابة مستقلة');
            }
        };
        $this->app->instance(SmartTutorGateway::class, $gateway);
        $conversation = $studentA['student']->tutorConversations()->firstOrFail();

        $this->actingAs($studentA['user'])->post(route('student.tutor.messages.store', $conversation), [
            'message' => 'حصة الرسائل مستقلة',
            'request_id' => (string) Str::uuid(),
        ])->assertRedirect(route('student.tutor.show', $conversation));
        $this->assertSame(1, $gateway->calls);
    }

    public function test_tutor_history_and_conversation_list_pagination_keep_old_records_reachable(): void
    {
        $student = $this->createStudentFixture('A');
        $oldestConversation = null;

        foreach (range(1, 31) as $index) {
            $conversation = $student['student']->tutorConversations()->create([
                'title' => $index === 1 ? 'أقدم محادثة قابلة للوصول' : "محادثة {$index}",
            ]);
            $conversation->forceFill(['updated_at' => now()->subMinutes(32 - $index)])->save();
            $oldestConversation ??= $conversation;
        }

        $this->actingAs($student['user'])->get(route('student.tutor.index', ['page' => 2]))
            ->assertOk()
            ->assertSeeText('أقدم محادثة قابلة للوصول');

        foreach (range(1, 51) as $index) {
            $oldestConversation->messages()->create([
                'role' => 'user',
                'content' => match ($index) {
                    1 => 'OLDEST-TUTOR-MESSAGE-UNIQUE',
                    51 => 'NEWEST-TUTOR-MESSAGE-UNIQUE',
                    default => "رسالة محفوظة {$index}",
                },
                'client_request_id' => (string) Str::uuid(),
                'delivery_status' => 'failed',
                'failure_reason' => SmartTutorGatewayException::TIMEOUT,
            ]);
        }

        $this->actingAs($student['user'])->get(route('student.tutor.show', $oldestConversation))
            ->assertOk()
            ->assertSeeText('NEWEST-TUTOR-MESSAGE-UNIQUE')
            ->assertDontSeeText('OLDEST-TUTOR-MESSAGE-UNIQUE')
            ->assertSeeText('الأقدم')
            ->assertSeeText('كل المحادثات');
        $this->actingAs($student['user'])->get(route('student.tutor.show', [
            'conversation' => $oldestConversation,
            'messages' => 2,
        ]))
            ->assertOk()
            ->assertSeeText('OLDEST-TUTOR-MESSAGE-UNIQUE');
    }

    public function test_message_rate_limit_is_per_student_and_does_not_call_gateway_after_limit(): void
    {
        config(['smart_tutor.rate_limits.messages_per_minute' => 20]);
        $studentA = $this->createStudentFixture('A');
        $studentB = $this->createStudentFixture('B');
        $conversationA = $studentA['student']->tutorConversations()->create(['title' => 'أ']);
        $conversationB = $studentB['student']->tutorConversations()->create(['title' => 'ب']);
        $gateway = new class implements SmartTutorGateway
        {
            public int $calls = 0;

            public function reply(SmartTutorPrompt $prompt): SmartTutorReply
            {
                $this->calls++;

                return new SmartTutorReply('إجابة');
            }
        };
        $this->app->instance(SmartTutorGateway::class, $gateway);

        foreach (range(1, 20) as $index) {
            $this->actingAs($studentA['user'])->post(route('student.tutor.messages.store', $conversationA), [
                'message' => "سؤال {$index}",
                'request_id' => (string) Str::uuid(),
            ])->assertRedirect(route('student.tutor.show', $conversationA));
        }

        $this->actingAs($studentA['user'])->post(route('student.tutor.messages.store', $conversationA), [
            'message' => 'سؤال زائد',
            'request_id' => (string) Str::uuid(),
        ])->assertStatus(429)
            ->assertSeeText('تم تجاوز الحد المؤقت لرسائل المعلّم الذكي.');

        $this->assertSame(20, $gateway->calls);
        $this->assertDatabaseCount('tutor_messages', 40);

        $this->actingAs($studentB['user'])->post(route('student.tutor.messages.store', $conversationB), [
            'message' => 'سؤال مستقل',
            'request_id' => (string) Str::uuid(),
        ])->assertRedirect(route('student.tutor.show', $conversationB));

        $this->assertSame(21, $gateway->calls);
        $this->assertDatabaseCount('tutor_messages', 42);
    }

    public function test_server_configuration_is_not_rendered_in_tutor_html_or_javascript(): void
    {
        config(['services.smart_tutor.api_key' => 'frontend-secret-must-not-appear']);
        $student = $this->createStudentFixture('A');
        $conversation = $student['student']->tutorConversations()->create(['title' => 'آمنة']);

        $this->actingAs($student['user'])->get(route('student.tutor.show', $conversation))
            ->assertOk()
            ->assertDontSee('frontend-secret-must-not-appear');
        $script = file_get_contents(public_path('js/student-tutor.js'));
        $this->assertStringNotContainsString(
            'frontend-secret-must-not-appear',
            $script,
        );
        $this->assertStringContainsString("aria-busy') === 'true'", $script);
        $this->assertStringContainsString("addEventListener('pageshow'", $script);
        $this->assertStringContainsString('event.persisted', $script);
        $this->assertStringContainsString('window.location.reload()', $script);
        $this->assertStringContainsString('[data-tutor-retry]', $script);
        $this->assertStringNotContainsString('innerHTML', $script);
    }
}
