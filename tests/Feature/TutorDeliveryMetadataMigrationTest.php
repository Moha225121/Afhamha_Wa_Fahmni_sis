<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesStudentEducationFixtures;
use Tests\TestCase;

class TutorDeliveryMetadataMigrationTest extends TestCase
{
    use CreatesStudentEducationFixtures;
    use RefreshDatabase;

    #[DataProvider('uniqueConstraintCases')]
    public function test_database_uniqueness_is_the_final_race_defence(string $constraint): void
    {
        $student = $this->createStudentFixture(Str::random(6));
        $conversation = $student['student']->tutorConversations()->create(['title' => 'قيود التكرار']);
        $now = now();

        if ($constraint === 'client_request_id') {
            $requestId = (string) Str::uuid();
            DB::table('tutor_messages')->insert([
                'tutor_conversation_id' => $conversation->id,
                'role' => 'user',
                'content' => 'السؤال الأول',
                'client_request_id' => $requestId,
                'delivery_status' => 'pending',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $otherConversation = $student['student']->tutorConversations()->create(['title' => 'نطاق UUID مستقل']);
            DB::table('tutor_messages')->insert([
                'tutor_conversation_id' => $otherConversation->id,
                'role' => 'user',
                'content' => 'UUID نفسه في محادثة أخرى',
                'client_request_id' => $requestId,
                'delivery_status' => 'pending',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->assertSame(2, DB::table('tutor_messages')->where('client_request_id', $requestId)->count());

            $this->expectException(QueryException::class);
            DB::table('tutor_messages')->insert([
                'tutor_conversation_id' => $conversation->id,
                'role' => 'user',
                'content' => 'طلب متزامن',
                'client_request_id' => $requestId,
                'delivery_status' => 'pending',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return;
        }

        $questionId = DB::table('tutor_messages')->insertGetId([
            'tutor_conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => 'السؤال',
            'client_request_id' => (string) Str::uuid(),
            'delivery_status' => 'answered',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('tutor_messages')->insert([
            'tutor_conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => 'الإجابة الأولى',
            'delivery_status' => 'answered',
            'in_reply_to_message_id' => $questionId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->expectException(QueryException::class);
        DB::table('tutor_messages')->insert([
            'tutor_conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => 'إجابة متزامنة ثانية',
            'delivery_status' => 'answered',
            'in_reply_to_message_id' => $questionId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function uniqueConstraintCases(): array
    {
        return [
            'conversation-scoped request UUID' => ['client_request_id'],
            'one assistant reply per question' => ['in_reply_to_message_id'],
        ];
    }

    public function test_delivery_metadata_migration_backfills_legacy_sequences_and_down_preserves_base_messages(): void
    {
        $student = $this->createStudentFixture('migration');
        $clearConversation = $student['student']->tutorConversations()->create(['title' => 'تسلسل واضح']);
        $ambiguousConversation = $student['student']->tutorConversations()->create(['title' => 'تسلسل ملتبس']);
        $invalidConversation = $student['student']->tutorConversations()->create(['title' => 'رد قديم غير صالح']);
        $unknownRoleConversation = $student['student']->tutorConversations()->create(['title' => 'دور قديم غير معروف']);
        $migration = require database_path('migrations/2026_08_23_000006_add_delivery_metadata_to_tutor_messages.php');
        $migration->down();

        try {
            $clearQuestion = $this->insertLegacyMessage($clearConversation->id, 'user', 'clear-question');
            $clearAnswer = $this->insertLegacyMessage($clearConversation->id, 'assistant', 'clear-answer');
            $trailingQuestion = $this->insertLegacyMessage($clearConversation->id, 'user', 'trailing-question');
            $ambiguousQuestionA = $this->insertLegacyMessage($ambiguousConversation->id, 'user', 'ambiguous-question-a');
            $ambiguousQuestionB = $this->insertLegacyMessage($ambiguousConversation->id, 'user', 'ambiguous-question-b');
            $ambiguousAnswer = $this->insertLegacyMessage($ambiguousConversation->id, 'assistant', 'ambiguous-answer');
            $invalidQuestion = $this->insertLegacyMessage($invalidConversation->id, 'user', 'invalid-answer-question');
            $invalidAnswer = $this->insertLegacyMessage($invalidConversation->id, 'assistant', "\u{200B}");
            $unknownRoleMessage = $this->insertLegacyMessage($unknownRoleConversation->id, 'system', 'legacy-unknown-role');

            $migration->up();

            $this->assertDatabaseHas('tutor_messages', [
                'id' => $clearQuestion,
                'delivery_status' => 'answered',
            ]);
            $this->assertDatabaseHas('tutor_messages', [
                'id' => $clearAnswer,
                'delivery_status' => 'answered',
                'in_reply_to_message_id' => $clearQuestion,
            ]);
            $this->assertDatabaseHas('tutor_messages', [
                'id' => $trailingQuestion,
                'delivery_status' => 'failed',
                'failure_reason' => 'legacy_unanswered',
            ]);
            foreach ([$ambiguousQuestionA, $ambiguousQuestionB] as $questionId) {
                $this->assertDatabaseHas('tutor_messages', [
                    'id' => $questionId,
                    'delivery_status' => 'failed',
                    'failure_reason' => 'legacy_unanswered',
                ]);
            }
            $this->assertDatabaseHas('tutor_messages', [
                'id' => $ambiguousAnswer,
                'delivery_status' => 'failed',
                'failure_reason' => 'legacy_unanswered',
                'in_reply_to_message_id' => null,
            ]);
            $this->assertDatabaseHas('tutor_messages', [
                'id' => $invalidQuestion,
                'delivery_status' => 'failed',
                'failure_reason' => 'invalid_response',
            ]);
            $this->assertDatabaseHas('tutor_messages', [
                'id' => $invalidAnswer,
                'delivery_status' => 'failed',
                'failure_reason' => 'invalid_response',
                'in_reply_to_message_id' => null,
            ]);
            $this->assertDatabaseHas('tutor_messages', [
                'id' => $unknownRoleMessage,
                'delivery_status' => 'failed',
                'failure_reason' => 'legacy_unanswered',
            ]);

            $migration->down();

            $this->assertFalse(Schema::hasColumn('tutor_messages', 'client_request_id'));
            $this->assertFalse(Schema::hasColumn('tutor_messages', 'delivery_status'));
            $this->assertFalse(Schema::hasColumn('tutor_messages', 'failure_reason'));
            $this->assertFalse(Schema::hasColumn('tutor_messages', 'in_reply_to_message_id'));
            $this->assertSame(9, DB::table('tutor_messages')->count());
            $this->assertSame('clear-answer', DB::table('tutor_messages')->where('id', $clearAnswer)->value('content'));
        } finally {
            if (! Schema::hasColumn('tutor_messages', 'delivery_status')) {
                $migration->up();
            }
        }
    }

    private function insertLegacyMessage(int $conversationId, string $role, string $content): int
    {
        return DB::table('tutor_messages')->insertGetId([
            'tutor_conversation_id' => $conversationId,
            'role' => $role,
            'content' => $content,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
