<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tutor_messages', function (Blueprint $table): void {
            $table->uuid('client_request_id')->nullable();
            $table->string('delivery_status', 20)->nullable();
            $table->string('failure_reason', 32)->nullable();
            $table->unsignedBigInteger('in_reply_to_message_id')->nullable();

            $table->foreign('in_reply_to_message_id')
                ->references('id')
                ->on('tutor_messages')
                ->nullOnDelete();
            $table->unique(
                ['tutor_conversation_id', 'client_request_id'],
                'tutor_message_request_unique',
            );
            $table->unique('in_reply_to_message_id', 'tutor_message_reply_unique');
            $table->index(
                ['tutor_conversation_id', 'role', 'delivery_status'],
                'tutor_message_history_index',
            );
        });

        $this->backfillExistingMessages();
    }

    public function down(): void
    {
        Schema::table('tutor_messages', function (Blueprint $table): void {
            $table->dropForeign(['in_reply_to_message_id']);
            $table->dropUnique('tutor_message_request_unique');
            $table->dropUnique('tutor_message_reply_unique');
            $table->dropIndex('tutor_message_history_index');
            $table->dropColumn([
                'client_request_id',
                'delivery_status',
                'failure_reason',
                'in_reply_to_message_id',
            ]);
        });
    }

    private function backfillExistingMessages(): void
    {
        DB::table('tutor_conversations')
            ->select('id')
            ->chunkById(100, function ($conversations): void {
                foreach ($conversations as $conversation) {
                    $messages = DB::table('tutor_messages')
                        ->where('tutor_conversation_id', $conversation->id)
                        ->orderBy('id')
                        ->get(['id', 'role', 'content']);
                    $expectedRole = 'user';
                    $isUnambiguous = true;

                    foreach ($messages as $message) {
                        if ($message->role !== $expectedRole) {
                            $isUnambiguous = false;

                            break;
                        }

                        $expectedRole = $expectedRole === 'user' ? 'assistant' : 'user';
                    }

                    if (! $isUnambiguous) {
                        foreach ($messages->where('role', 'user')->pluck('id')->chunk(500) as $questionIds) {
                            DB::table('tutor_messages')->whereIn('id', $questionIds)->update([
                                'delivery_status' => 'failed',
                                'failure_reason' => 'legacy_unanswered',
                            ]);
                        }

                        foreach ($messages->where('role', 'assistant')->pluck('id')->chunk(500) as $answerIds) {
                            DB::table('tutor_messages')->whereIn('id', $answerIds)->update([
                                'delivery_status' => 'failed',
                                'failure_reason' => 'legacy_unanswered',
                            ]);
                        }

                        continue;
                    }

                    for ($index = 0; $index < $messages->count(); $index += 2) {
                        $question = $messages[$index];
                        $answer = $messages[$index + 1] ?? null;

                        if ($answer === null) {
                            DB::table('tutor_messages')->where('id', $question->id)->update([
                                'delivery_status' => 'failed',
                                'failure_reason' => 'legacy_unanswered',
                            ]);

                            continue;
                        }

                        if (
                            ! $this->isValidLegacyContent((string) $question->content, 'user')
                            || ! $this->isValidLegacyContent((string) $answer->content, 'assistant')
                        ) {
                            DB::table('tutor_messages')->where('id', $question->id)->update([
                                'delivery_status' => 'failed',
                                'failure_reason' => 'invalid_response',
                            ]);
                            DB::table('tutor_messages')->where('id', $answer->id)->update([
                                'delivery_status' => 'failed',
                                'failure_reason' => 'invalid_response',
                            ]);

                            continue;
                        }

                        DB::table('tutor_messages')->where('id', $question->id)->update([
                            'delivery_status' => 'answered',
                        ]);
                        DB::table('tutor_messages')->where('id', $answer->id)->update([
                            'delivery_status' => 'answered',
                            'in_reply_to_message_id' => $question->id,
                        ]);
                    }
                }
            });

        DB::table('tutor_messages')
            ->whereNull('delivery_status')
            ->update([
                'delivery_status' => 'failed',
                'failure_reason' => 'legacy_unanswered',
            ]);
    }

    private function isValidLegacyContent(string $content, string $role): bool
    {
        if (! mb_check_encoding($content, 'UTF-8')) {
            return false;
        }

        $content = trim($content);
        $visibleContent = preg_replace('/[\pZ\pC]+/u', '', $content);
        $minimum = $role === 'user'
            ? max(1, (int) config('smart_tutor.input.min_characters', 2))
            : 1;
        $maximum = $role === 'user'
            ? max($minimum, (int) config('smart_tutor.input.max_characters', 4000))
            : max(1, (int) config('smart_tutor.reply.max_characters', 12000));

        return $content !== ''
            && $visibleContent !== null
            && mb_strlen($visibleContent) >= $minimum
            && preg_match('/\S/u', $content) === 1
            && preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $content) !== 1
            && mb_strlen($content) <= $maximum;
    }
};
