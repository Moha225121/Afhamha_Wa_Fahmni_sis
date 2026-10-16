<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SchoolChatService
{
    public function inbox(User $user): BelongsToMany
    {
        $query = $user->conversations()->with(['student.user', 'participants', 'latestMessage.sender'])
            ->withCount(['messages as unread_count' => fn ($messages) => $messages->where('sender_id', '!=', $user->id)
                ->where(fn ($unread) => $unread->whereNull('conversation_participants.last_read_at')
                    ->orWhereColumn('messages.created_at', '>', 'conversation_participants.last_read_at'))]);
        if ($user->isParent()) {
            $studentIds = $user->guardian?->students()->pluck('students.id') ?? collect();
            $query->where(fn ($items) => $items->whereNull('conversations.student_id')->orWhereIn('conversations.student_id', $studentIds));
        } elseif ($user->isTeacher()) {
            $classIds = $user->teacher?->classrooms()->pluck('classrooms.id') ?? collect();
            $query->whereHas('student', fn ($students) => $students->whereIn('classroom_id', $classIds));
        }

        return $query->orderByDesc('conversations.last_message_at')->orderByDesc('conversations.id');
    }

    public function authorize(User $user, Conversation $conversation): void
    {
        abort_unless($conversation->participants()->whereKey($user->id)->exists(), 404);
        if ($user->isParent() && $conversation->student_id) {
            app(ParentPortalContext::class)->assertChild($user->guardian, $conversation->student);
        } elseif ($user->isTeacher()) {
            abort_unless($conversation->student?->classroom_id && $user->teacher?->classrooms()->whereKey($conversation->student->classroom_id)->exists(), 404);
        }
    }

    public function destination(User $user, Conversation $conversation): string
    {
        return route($user->role.'.conversations.show', $conversation);
    }

    public function markRead(User $user, Conversation $conversation): void
    {
        $conversation->participants()->updateExistingPivot($user->id, ['last_read_at' => now()]);
        $user->notifications()->where('data->category', 'message')->where(function ($query) use ($conversation, $user): void {
            $query->where('data->conversation_id', $conversation->id)
                ->orWhere('data->url', $this->destination($user, $conversation));
        })->whereNull('read_at')->update(['read_at' => now()]);
    }

    public function send(User $sender, Conversation $conversation, string $body): Message
    {
        $this->authorize($sender, $conversation);
        abort_unless($conversation->status === 'open', 403);
        $recipients = $conversation->participants()->where('users.id', '!=', $sender->id)->where('users.status', 'active')->get()
            ->filter(function (User $recipient) use ($conversation): bool {
                if ($recipient->isAdmin()) {
                    return true;
                }
                if ($recipient->isTeacher()) {
                    return (bool) ($conversation->student?->classroom_id && $recipient->teacher?->classrooms()->whereKey($conversation->student->classroom_id)->exists());
                }

                return $recipient->isParent() && $recipient->guardian?->status === 'active'
                    && (! $conversation->student_id || $recipient->guardian->students()->whereKey($conversation->student_id)->exists());
            });
        abort_unless($recipients->isNotEmpty(), 403);

        return DB::transaction(function () use ($sender, $conversation, $body, $recipients): Message {
            $message = $conversation->messages()->create(['sender_id' => $sender->id, 'body' => $body]);
            $conversation->update(['last_message_at' => $message->created_at]);
            $recipients->each(function (User $recipient) use ($conversation, $body): void {
                app(ParentNotificationService::class)->send($recipient, [
                    'title' => 'رسالة جديدة', 'body' => Str::limit($body, 140), 'category' => 'message',
                    'student_id' => $conversation->student_id, 'conversation_id' => $conversation->id,
                    'url' => $this->destination($recipient, $conversation),
                ]);
            });

            return $message->load('sender');
        });
    }

    public function serialize(Message $message, User $viewer): array
    {
        return ['id' => $message->id, 'body' => $message->body, 'sender' => $message->sender?->name ?? 'حساب المدرسة',
            'is_mine' => $message->sender_id === $viewer->id, 'time' => $message->created_at->format('H:i'),
            'day' => $message->created_at->format('Y-m-d'), 'created_at' => $message->created_at->toIso8601String()];
    }
}
