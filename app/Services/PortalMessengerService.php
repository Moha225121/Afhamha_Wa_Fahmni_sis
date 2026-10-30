<?php

namespace App\Services;

use App\Jobs\SendConversationMessage;
use App\Models\Conversation;
use App\Models\ConversationMessageReceipt;
use App\Models\Message;
use App\Models\Student;
use App\Models\TeacherMessageReceipt;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;

class PortalMessengerService
{
    private ?bool $available = null;

    private ?string $timezone = null;

    private function timezone(): string
    {
        return $this->timezone ??= LessonPublicationTime::timezone();
    }

    public function available(): bool
    {
        return $this->available ??= Schema::hasTable('conversation_message_receipts') && Schema::hasColumn('messages', 'client_request_id');
    }

    public function teacherKey(int $id): string
    {
        return hash('sha256', 'messenger:teacher:'.$id);
    }

    public function familyKey(int $parentId, int $studentId): string
    {
        return hash('sha256', 'messenger:parent:'.$parentId.':student:'.$studentId);
    }

    public function studentKey(int $studentUserId, int $recipientId): string
    {
        return hash('sha256', 'messenger:student:'.$studentUserId.':'.$recipientId);
    }

    /** A participant is insufficient: both profiles, the child link and exact current curriculum pair must still exist. */
    public function conversations(User $actor): Builder
    {
        $query = Conversation::query()->whereHas('participants', fn ($q) => $q->whereKey($actor->id))
            ->has('participants', '=', 2)
            ->whereExists(function ($q): void {
                $q->selectRaw('1')->from('students as ms')
                    ->join('users as su', 'su.id', '=', 'ms.user_id')
                    ->join('classrooms as mc', 'mc.id', '=', 'ms.classroom_id')
                    ->join('guardian_student as gs', 'gs.student_id', '=', 'ms.id')
                    ->join('guardians as mg', 'mg.id', '=', 'gs.guardian_id')
                    ->join('users as pu', 'pu.id', '=', 'mg.user_id')
                    ->join('conversation_participants as pp', 'pp.user_id', '=', 'pu.id')
                    ->join('conversation_participants as tp', 'tp.conversation_id', '=', 'pp.conversation_id')
                    ->join('users as tu', 'tu.id', '=', 'tp.user_id')
                    ->join('teachers as mt', 'mt.user_id', '=', 'tu.id')
                    ->join('teacher_assignments as ma', function ($join): void {
                        $join->on('ma.teacher_id', '=', 'mt.id')->on('ma.classroom_id', '=', 'ms.classroom_id');
                    })
                    ->join('subjects as sb', 'sb.id', '=', 'ma.subject_id')
                    ->join('classroom_subject as cs', fn ($join) => $join->on('cs.classroom_id', '=', 'ma.classroom_id')->on('cs.subject_id', '=', 'ma.subject_id'))
                    ->whereColumn('ms.id', 'conversations.student_id')->whereColumn('pp.conversation_id', 'conversations.id')
                    ->whereNull('ms.deleted_at')->whereNull('mc.deleted_at')->whereNull('mg.deleted_at')->whereNull('mt.deleted_at')
                    ->where('ms.status', 'active')->where('su.role', 'student')->where('su.status', 'active')
                    ->where('mg.status', 'active')->where('pu.role', 'parent')->where('pu.status', 'active')
                    ->where('mt.status', 'active')->where('tu.role', 'teacher')->where('tu.status', 'active')->where('sb.status', 'active');
            });

        if ($actor->role === 'student') {
            return Conversation::query()->whereHas('participants', fn ($q) => $q->whereKey($actor->id))
                ->has('participants', '=', 2)->where('student_id', $actor->student?->id ?? 0)
                ->where(function ($scope) use ($actor): void {
                    $scope->whereExists(function ($q) use ($actor): void {
                        $q->selectRaw('1')->from('conversation_participants as cp')->join('users as u', 'u.id', '=', 'cp.user_id')->join('teachers as t', 't.user_id', '=', 'u.id')->join('teacher_assignments as ta', 'ta.teacher_id', '=', 't.id')->whereColumn('cp.conversation_id', 'conversations.id')->where('cp.user_id', '<>', $actor->id)->where('u.role', 'teacher')->where('u.status', 'active')->where('t.status', 'active')->where('ta.classroom_id', $actor->student?->classroom_id);
                    })->orWhereExists(function ($q) use ($actor): void {
                        $q->selectRaw('1')->from('conversation_participants as cp')->join('users as u', 'u.id', '=', 'cp.user_id')->join('supervisor_class_assignments as sca', 'sca.supervisor_id', '=', 'u.id')->whereColumn('cp.conversation_id', 'conversations.id')->where('cp.user_id', '<>', $actor->id)->where('u.role', 'supervisor')->where('u.status', 'active')->where('sca.classroom_id', $actor->student?->classroom_id);
                    });
                });
        }

        if ($actor->role === 'supervisor') {
            return Conversation::query()->whereHas('participants', fn ($q) => $q->whereKey($actor->id))
                ->has('participants', '=', 2)->whereExists(function ($q) use ($actor): void {
                    $q->selectRaw('1')->from('students as s')->join('conversation_participants as sp', 'sp.user_id', '=', 's.user_id')->join('supervisor_class_assignments as sca', 'sca.classroom_id', '=', 's.classroom_id')->whereColumn('sp.conversation_id', 'conversations.id')->where('sca.supervisor_id', $actor->id)->whereColumn('s.id', 'conversations.student_id');
                });
        }

        if ($actor->role === 'teacher') {
            $query->orWhere(function ($scope) use ($actor): void {
                $scope->whereHas('participants', fn ($q) => $q->whereKey($actor->id))
                    ->has('participants', '=', 2)
                    ->whereExists(function ($q) use ($actor): void {
                        $q->selectRaw('1')->from('students as s')
                            ->join('conversation_participants as sp', 'sp.user_id', '=', 's.user_id')
                            ->join('teacher_assignments as ta', 'ta.classroom_id', '=', 's.classroom_id')
                            ->join('teachers as t', 't.id', '=', 'ta.teacher_id')
                            ->whereColumn('sp.conversation_id', 'conversations.id')
                            ->where('t.user_id', $actor->id)
                            ->whereColumn('s.id', 'conversations.student_id')
                            ->where('s.status', 'active');
                    });
            });
        }

        // Group alternative participant scopes before callers add an ID or child filter.
        // Otherwise SQL's OR precedence can select a different authorized conversation.
        return Conversation::query()->where(fn (Builder $scope) => $scope->addNestedWhereQuery($query->getQuery()));
    }

    public function authorizedConversation(User $actor, int $id): ?Conversation
    {
        $profileStatus = match ($actor->role) {
            'parent' => $actor->guardian?->status,
            'teacher' => $actor->teacher?->status,
            'student' => $actor->student?->status,
            'supervisor' => $actor->status,
            default => null,
        };
        if ($actor->status !== 'active' || ! in_array($actor->role, ['parent', 'teacher', 'student', 'supervisor'], true) || $profileStatus !== 'active') {
            return null;
        }

        return $this->conversations($actor)->with(['participants', 'student.user'])->find($id);
    }

    public function conversationUrl(User $actor, Conversation $conversation, bool $absolute = true): ?string
    {
        $conversation = $this->authorizedConversation($actor, $conversation->id);
        if (! $conversation) {
            return null;
        }
        $parent = $conversation->participants->firstWhere('role', 'parent');
        $teacher = $conversation->participants->firstWhere('role', 'teacher');
        if ($actor->role === 'student') {
            $recipient = $conversation->participants->firstWhere('id', '!=', $actor->id);

            return route('student.messages', ['thread' => $this->studentKey($actor->id, $recipient->id)], $absolute);
        }
        if ($actor->role === 'teacher' && ! $parent) {
            $studentUser = $conversation->participants->firstWhere('role', 'student');

            return route('teacher.messages', ['thread' => $this->studentKey($studentUser->id, $actor->id)], $absolute);
        }
        $key = $actor->role === 'parent' ? $this->teacherKey($teacher->id) : $this->familyKey($parent->id, $conversation->student_id);

        $parameters = ['thread' => $key];
        if ($actor->role === 'parent') {
            $parameters['student'] = $conversation->student_id;
        }

        return route($actor->role.'.messages', $parameters, $absolute);
    }

    private function children(User $parent): Collection
    {
        return $parent->guardian?->students()->where('students.status', 'active')
            ->whereHas('user', fn ($q) => $q->where('role', 'student')->where('status', 'active'))
            ->whereHas('classroom')->with(['user', 'classroom'])->get() ?? collect();
    }

    private function contacts(User $parent, Student $selectedStudent): Collection
    {
        $contacts = collect();
        foreach (collect([$selectedStudent]) as $child) {
            foreach (app(TeacherContactScope::class)->teachersFor($child) as $teacher) {
                if (! $contacts->has($teacher->id)) {
                    $contacts->put($teacher->id, ['user' => $teacher, 'children' => collect()]);
                }
                $contact = $contacts->get($teacher->id);
                $contact['children']->put($child->id, $child);
            }
        }

        return $contacts;
    }

    public function sendParent(User $parent, int $studentId, int $teacherUserId, string $body, string $clientId): Message
    {
        $child = $this->children($parent)->firstWhere('id', $studentId);
        $teacher = User::find($teacherUserId);
        abort_unless($parent->role === 'parent' && $parent->status === 'active' && $parent->guardian?->status === 'active'
            && $child && $teacher && app(TeacherContactScope::class)->allows($child, $teacher), 404);

        return $this->save($parent, $teacher, $child, $body, $clientId);
    }

    public function sendReply(User $teacher, int $conversationId, string $body, string $clientId): Message
    {
        $conversation = $this->authorizedConversation($teacher, $conversationId);
        abort_unless($teacher->role === 'teacher' && $conversation && $conversation->status === 'open', 404);
        $parent = $conversation->participants->firstWhere('role', 'parent');
        if ($teacher->role === 'teacher' && ! $parent) {
            $studentUser = $conversation->participants->firstWhere('role', 'student');
            abort_unless($studentUser, 404);

            return $this->save($teacher, $studentUser, $conversation->student, $body, $clientId, $conversation);
        }

        return $this->save($teacher, $parent, $conversation->student, $body, $clientId, $conversation);
    }

    public function sendStudent(User $studentUser, int $recipientId, string $body, string $clientId): Message
    {
        $student = $studentUser->student;
        $recipient = User::query()->whereKey($recipientId)->where('status', 'active')->first();
        $allowedTeacher = $student && $recipient?->role === 'teacher' && app(TeacherContactScope::class)->allows($student, $recipient);
        $allowedSupervisor = $student && $recipient?->role === 'supervisor' && $student->classroom_id && $recipient->supervisedClassrooms()->whereKey($student->classroom_id)->exists();
        abort_unless($studentUser->isStudent() && $student?->status === 'active' && ($allowedTeacher || $allowedSupervisor), 404);

        return $this->save($studentUser, $recipient, $student, $body, $clientId);
    }

    private function save(User $sender, User $recipient, Student $child, string $body, string $clientId, ?Conversation $conversation = null): Message
    {
        if (! $this->available()) {
            throw ValidationException::withMessages(['body' => 'يتطلب تحديث الرسائل إكمال التهيئة. تواصل مع مسؤول النظام.']);
        }
        if (! Str::isUuid($clientId) || trim($body) === '') {
            throw ValidationException::withMessages(['body' => 'اكتب رسالة وأعد المحاولة.']);
        }
        // Chat delivery explicitly uses the database queue, independently of the application's default queue.
        if (config('queue.connections.database.driver') !== 'database' || (config('queue.connections.database.connection') ?: config('database.default')) !== config('database.default')) {
            throw ValidationException::withMessages(['body' => 'خدمة إرسال الرسائل غير جاهزة حالياً. تواصل مع مسؤول النظام.']);
        }

        return DB::transaction(function () use ($sender, $recipient, $child, $body, $clientId, $conversation): Message {
            User::whereKey($sender->id)->lockForUpdate()->firstOrFail();
            $existing = Message::where('sender_id', $sender->id)->where('client_request_id', $clientId)->first();
            if ($existing) {
                $valid = $this->authorizedConversation($sender, $existing->conversation_id);
                if (! $valid || (int) $valid->student_id !== (int) $child->id || $existing->body !== $body
                    || ! $valid->participants->contains('id', $recipient->id)) {
                    throw ValidationException::withMessages(['body' => 'سبق استخدام طلب الإرسال هذا لرسالة أخرى. حدّث الصفحة وحاول مجدداً.']);
                }

                return $existing;
            }
            if ($conversation) {
                $conversation = $this->authorizedConversation($sender, $conversation->id);
                abort_unless($conversation && $conversation->status === 'open', 404);
            } else {
                $freshChild = $sender->role === 'student'
                    ? ($sender->student?->id === $child->id && $sender->student->status === 'active' ? $sender->student : null)
                    : $this->children($sender)->firstWhere('id', $child->id);
                abort_unless($freshChild && app(TeacherContactScope::class)->allows($freshChild, $recipient), 404);
                $conversation = $this->conversations($sender)->where('student_id', $child->id)->where('status', 'open')
                    ->whereHas('participants', fn ($q) => $q->whereKey($recipient->id))->latest('id')->first();
                if (! $conversation) {
                    $conversation = Conversation::create(['student_id' => $child->id, 'created_by' => $sender->id, 'subject' => null, 'status' => 'open']);
                    $conversation->participants()->attach([$sender->id, $recipient->id]);
                }
            }
            $message = $conversation->messages()->create(['sender_id' => $sender->id, 'body' => $body, 'client_request_id' => $clientId]);
            $conversation->update(['last_message_at' => now()]);
            $notificationId = Uuid::uuid5(Uuid::NAMESPACE_URL, 'awf:conversation:'.$message->id.':'.$recipient->id)->toString();
            ConversationMessageReceipt::create(['notification_id' => $notificationId, 'message_id' => $message->id, 'recipient_user_id' => $recipient->id]);
            SendConversationMessage::dispatch($message->id, $recipient->id, $notificationId)->onConnection('database')->onQueue('notifications');

            return $message;
        });
    }

    public function deliveryUrl(string $notificationId): ?string
    {
        if (! Str::isUuid($notificationId) || ! $this->available() || ! ConversationMessageReceipt::whereKey($notificationId)->exists()) {
            return null;
        }

        return URL::temporarySignedRoute('teacher-message-receipts.delivered', now()->addDays(2), ['notification' => $notificationId], false);
    }

    public function delivered(ConversationMessageReceipt $receipt): void
    {
        ConversationMessageReceipt::whereKey($receipt->notification_id)->whereNull('delivered_at')->update(['delivered_at' => now(), 'updated_at' => now()]);
    }

    public function acknowledgeDelivery(string $id): bool
    {
        if (! $this->available()) {
            return false;
        }
        $receipt = ConversationMessageReceipt::with(['recipient', 'message'])->find($id);
        $user = $receipt?->recipient;
        if (! $user || ! $receipt->message || ! $this->authorizedConversation($user, $receipt->message->conversation_id)) {
            return false;
        }
        $notification = $user->notifications()->whereKey($id)->where('data->category', 'conversation_message')
            ->where('data->conversation_id', $receipt->message->conversation_id)->first();
        if (! $notification) {
            return false;
        }
        $this->delivered($receipt);

        return true;
    }

    public function inbox(Request $request): array
    {
        $actor = $request->user();
        $role = $actor->role;
        $selectedStudent = $role === 'parent' ? app(ParentMessageScope::class)->selected($request) : null;
        $contacts = $role === 'parent' && $selectedStudent ? $this->contacts($actor, $selectedStudent) : collect();
        $studentContacts = $role === 'student' && $actor->student ? $this->studentContacts($actor->student) : collect();
        $contacts = $role === 'student' ? $studentContacts : ($role === 'parent' && $selectedStudent ? $this->contacts($actor, $selectedStudent) : collect());
        $conversations = in_array($role, ['student', 'parent', 'teacher', 'supervisor'], true) ? $this->conversations($actor)
            ->when($role === 'parent', fn ($query) => $query->where('student_id', $selectedStudent?->id ?? 0))
            ->with(['participants:id,name,role', 'student.user:id,name', 'latestMessage' => fn ($q) => $q->select(['messages.id', 'messages.conversation_id', 'messages.sender_id', 'messages.created_at'])->selectRaw('SUBSTR(messages.body, 1, 100) as body')])
            ->orderByDesc('last_message_at')->get() : collect();
        $threads = collect();
        foreach ($contacts as $id => $contact) {
            if ($role === 'student') {
                $threads->put($this->studentKey($actor->id, $id), $this->thread($role, $this->studentKey($actor->id, $id), $contact['user']->name, $contact['type'] === 'supervisor' ? 'المشرف الخاص بك' : 'معلمك', null) + ['recipient_id' => (int) $id] + ($contact['type'] === 'teacher' ? ['teacher_id' => (int) $id] : []));

                continue;
            }
            $threads->put($this->teacherKey($id), $this->thread($role, $this->teacherKey($id), $contact['user']->name, 'معلم ابنك', $selectedStudent?->id) + ['teacher_id' => (int) $id]);
        }
        foreach ($conversations as $conversation) {
            $parent = $conversation->participants->firstWhere('role', 'parent');
            $teacher = $conversation->participants->firstWhere('role', 'teacher');
            if ($role === 'teacher' && ! $parent) {
                $studentUser = $conversation->participants->firstWhere('role', 'student');
                $key = $this->studentKey($studentUser->id, $actor->id);
                $row = $threads->get($key) ?? ($this->thread($role, $key, $studentUser->name, 'الطالب') + ['student_id' => (int) $conversation->student_id]);
                $row['conversation_ids'][] = $conversation->id;
                if ($conversation->status === 'open') {
                    $row['reply_conversation_id'] ??= $conversation->id;
                }
                if ($conversation->latestMessage && (! isset($row['sort_at']) || $conversation->latestMessage->created_at->gt($row['sort_at']))) {
                    $row['preview'] = (string) Str::of($conversation->latestMessage->body)->limit(70);
                    $row['sort_at'] = $conversation->latestMessage->created_at;
                    $row['time'] = $conversation->latestMessage->created_at->copy()->setTimezone($this->timezone())->format('H:i');
                }
                $threads->put($key, $row);

                continue;
            }
            $key = $role === 'teacher' ? $this->familyKey($parent->id, $conversation->student_id) : ($role === 'student' ? $this->studentKey($actor->id, $conversation->participants->firstWhere('id', '!=', $actor->id)->id) : $this->teacherKey($teacher->id));
            if ($role === 'student') {
                $recipient = $conversation->participants->firstWhere('id', '!=', $actor->id);
                $row = $threads->get($key) ?? ($this->thread($role, $key, $recipient->name, $recipient->role === 'supervisor' ? 'المشرف الخاص بك' : 'معلمك') + ['recipient_id' => (int) $recipient->id] + ($recipient->role === 'teacher' ? ['teacher_id' => (int) $recipient->id] : []));
                $row['conversation_ids'][] = $conversation->id;
                if ($conversation->status === 'open') {
                    $row['reply_conversation_id'] ??= $conversation->id;
                }
                if ($conversation->latestMessage && (! isset($row['sort_at']) || $conversation->latestMessage->created_at->gt($row['sort_at']))) {
                    $row['preview'] = (string) Str::of($conversation->latestMessage->body)->limit(70);
                    $row['sort_at'] = $conversation->latestMessage->created_at;
                    $row['time'] = $conversation->latestMessage->created_at->copy()->setTimezone($this->timezone())->format('H:i');
                }
                $threads->put($key, $row);

                continue;
            }
            $row = $threads->get($key) ?? ($this->thread($role, $key, $role === 'teacher' ? $parent->name : $teacher->name,
                $role === 'teacher' ? 'بخصوص: '.$conversation->student->user->name : 'معلم ابنك', $selectedStudent?->id)
                + ['teacher_id' => (int) $teacher->id, 'parent_id' => (int) $parent->id, 'student_id' => (int) $conversation->student_id]);
            $row['conversation_ids'][] = $conversation->id;
            if ($conversation->status === 'open') {
                $row['reply_conversation_id'] ??= $conversation->id;
            }
            if ($conversation->latestMessage && (! isset($row['sort_at']) || $conversation->latestMessage->created_at->gt($row['sort_at']))) {
                $row['preview'] = (string) Str::of($conversation->latestMessage->body)->limit(70);
                $row['sort_at'] = $conversation->latestMessage->created_at;
                $row['time'] = $conversation->latestMessage->created_at->copy()->setTimezone($this->timezone())->format('H:i');
            }
            $threads->put($key, $row);
        }
        if (in_array($role, ['parent', 'student'], true) && ($role !== 'parent' || $selectedStudent) && app(TeacherMessageReceiptService::class)->available()) {
            $broadcasts = TeacherMessageReceipt::query()->where('recipient_user_id', $actor->id)
                ->whereExists(function ($query) use ($actor, $selectedStudent): void {
                    $query->selectRaw('1')->from('notifications as n')->whereColumn('n.id', 'teacher_message_receipts.notification_id')
                        ->where('n.notifiable_id', $actor->id)->where('n.notifiable_type', $actor->getMorphClass())->where('n.data->category', 'teacher_notification');
                    if ($actor->role === 'parent') {
                        app(ParentMessageScope::class)->forStudent($query, $selectedStudent->id, 'n.data');
                    }
                })
                ->selectRaw('teacher_user_id, MAX(created_at) as latest_at, SUM(CASE WHEN viewed_at IS NULL THEN 1 ELSE 0 END) as unread_count')
                ->groupBy('teacher_user_id')->with('teacher:id,name')->get();
            foreach ($broadcasts as $broadcast) {
                $key = $this->teacherKey($broadcast->teacher_user_id);
                $row = $threads->get($key) ?? ($this->thread($role, $key, $broadcast->teacher?->name ?? 'المعلم', 'رسائل المعلم', $selectedStudent?->id) + ['teacher_id' => (int) $broadcast->teacher_user_id]);
                $row['unread'] += (int) $broadcast->unread_count;
                $at = Carbon::parse($broadcast->latest_at);
                if (! isset($row['sort_at']) || $at->gt($row['sort_at'])) {
                    $row['preview'] = 'رسالة من المعلم';
                    $row['sort_at'] = $at;
                    $row['time'] = $at->copy()->setTimezone($this->timezone())->format('H:i');
                }
                $threads->put($key, $row);
            }
        }
        if ($this->available() && $conversations->isNotEmpty()) {
            $counts = ConversationMessageReceipt::query()->join('messages as m', 'm.id', '=', 'conversation_message_receipts.message_id')
                ->where('recipient_user_id', $actor->id)->whereNull('viewed_at')->whereIn('m.conversation_id', $conversations->modelKeys())
                ->groupBy('m.conversation_id')->selectRaw('m.conversation_id, COUNT(*) as unread_count')->pluck('unread_count', 'm.conversation_id');
            $threads = $threads->map(function ($thread) use ($counts): array {
                $thread['unread'] += collect($thread['conversation_ids'] ?? [])->sum(fn ($id) => (int) ($counts[$id] ?? 0));

                return $thread;
            });
        }
        $threads = $threads->sortByDesc(fn ($row) => $row['sort_at'] ?? '')->values();
        $key = $request->query('thread');
        $selected = is_string($key) ? $threads->firstWhere('key', $key) : null;
        if ($key !== null) {
            abort_unless($selected, 404);
        }
        $messages = collect();
        $moreUrl = null;
        $readAction = null;
        if ($selected) {
            [$messages, $moreUrl] = $this->stream($request, $selected);
            $incoming = $messages->where('mine', false);
            $receiptIds = $incoming->pluck('_chat_receipt')->filter()->values()->all();
            if ($receiptIds !== []) {
                $token = hash_hmac('sha256', $actor->id.':'.$selected['key'].':'.($selectedStudent?->id ?? '').':'.implode(',', $receiptIds), config('app.key'));
                Cache::put('portal-message-read:'.$token, ['actor' => $actor->id, 'thread' => $selected['key'], 'student_id' => $selectedStudent?->id, 'chat' => $receiptIds], now()->addMinutes(10));
                $parameters = ['thread' => $selected['key'], 'read_token' => $token];
                if ($selectedStudent) {
                    $parameters['student'] = $selectedStudent->id;
                }
                $readAction = route($role.'.messages.read', $parameters);
            }
        }
        $children = $role === 'parent' && $selected ? ($contacts->get($selected['teacher_id'])['children'] ?? collect()) : collect();
        $selectedChild = $selectedStudent;
        $canSend = $this->available() && $selected && (($role === 'parent' && $children->isNotEmpty()) || ($role === 'student' && ! empty($selected['recipient_id'])) || ($role === 'teacher' && ! empty($selected['reply_conversation_id'])));

        return ['role' => $role, 'title' => 'الرسائل', 'threads' => $threads, 'selectedThread' => $selected, 'messages' => $messages,
            'canSend' => (bool) $canSend, 'sendAction' => $canSend ? route($role.'.messages.store', $selectedStudent ? ['student' => $selectedStudent->id] : []) : null,
            'sendFields' => ($role === 'parent' ? ['teacher_id' => $selected['teacher_id'] ?? null] : ($role === 'student' ? ['recipient_id' => $selected['recipient_id'] ?? null] : ['conversation_id' => $selected['reply_conversation_id'] ?? null]))
                + ['client_request_id' => $request->old('client_request_id', (string) Str::uuid())],
            'childChoices' => $children->map(fn ($child) => ['id' => $child->id, 'name' => $child->user->name])->values()->all(),
            'selectedChildId' => $selectedChild?->id, 'readAction' => $readAction, 'moreUrl' => $moreUrl,
            'childUnreadCounts' => $role === 'parent' ? app(ParentMessageScope::class)->unreadCounts($actor) : [],
            'emptyText' => $role === 'parent' ? 'لا يوجد معلمون مرتبطون بصفوف أبنائك حالياً.' : ($role === 'student' ? 'لا يوجد معلمون أو مشرف مرتبط بك حالياً.' : 'ستظهر الرسائل هنا عند وصولها.')];
    }

    private function studentContacts(Student $student): Collection
    {
        $contacts = app(TeacherContactScope::class)->teachersFor($student)->mapWithKeys(fn ($user) => [$user->id => ['user' => $user, 'type' => 'teacher']]);
        $supervisors = User::query()->where('role', 'supervisor')->where('status', 'active')->whereHas('supervisedClassrooms', fn ($q) => $q->whereKey($student->classroom_id))->get(['id', 'name', 'role']);
        foreach ($supervisors as $user) {
            $contacts->put($user->id, ['user' => $user, 'type' => 'supervisor']);
        }

        return $contacts->sortBy(fn ($c) => $c['user']->name);
    }

    private function thread(string $role, string $key, string $name, string $subtitle, ?int $studentId = null): array
    {
        $parameters = ['thread' => $key];
        if ($role === 'parent' && $studentId) {
            $parameters['student'] = $studentId;
        }

        return ['key' => $key, 'name' => $name, 'subtitle' => $subtitle, 'unread' => 0, 'preview' => 'ابدأ محادثة', 'time' => '',
            'url' => route($role.'.messages', $parameters), 'conversation_ids' => []] + ($role === 'parent' && $studentId ? ['student_id' => $studentId] : []);
    }

    /** Only this page's bodies are loaded; the union preserves ordering across private messages and broadcasts. */
    private function stream(Request $request, array $thread): array
    {
        $actor = $request->user();
        $sources = DB::table('messages')->whereIn('conversation_id', $thread['conversation_ids'] ?? [])
            ->selectRaw("id as source_id, created_at, 'chat' as source_type, id as source_order");
        $broadcastAvailable = in_array($actor->role, ['parent', 'student'], true) && ! empty($thread['teacher_id']) && app(TeacherMessageReceiptService::class)->available();
        if ($broadcastAvailable) {
            // Keep both IDs textual for PostgreSQL's strict UNION typing.
            $cast = DB::connection()->getDriverName() === 'mysql' || DB::connection()->getDriverName() === 'mariadb' ? 'CHAR' : 'TEXT';
            $broadcastQuery = DB::table('teacher_message_receipts as r')->join('notifications as n', 'n.id', '=', 'r.notification_id')
                ->where('r.recipient_user_id', $actor->id)->where('r.teacher_user_id', $thread['teacher_id'])
                ->where('n.notifiable_id', $actor->id)->where('n.notifiable_type', $actor->getMorphClass())->where('n.data->category', 'teacher_notification');
            if ($actor->role === 'parent') {
                app(ParentMessageScope::class)->forStudent($broadcastQuery, $thread['student_id'], 'n.data');
            }
            $sources = DB::table('messages')->whereIn('conversation_id', $thread['conversation_ids'] ?? [])
                ->selectRaw("CAST(id AS {$cast}) as source_id, created_at, 'chat' as source_type, id as source_order")
                ->unionAll($broadcastQuery->selectRaw("CAST(r.notification_id AS {$cast}) as source_id, n.created_at, 'broadcast' as source_type, 0 as source_order"));
        }
        $page = DB::query()->fromSub($sources, 'stream')->orderByDesc('created_at')->orderByDesc('source_type')->orderByDesc('source_order')->orderByDesc('source_id')
            ->simplePaginate(50, ['*'], 'message_page')->withQueryString();
        if ($actor->role === 'parent') {
            $page->appends(['student' => $thread['student_id']]);
        }
        $chat = Message::whereIn('id', collect($page->items())->where('source_type', 'chat')->pluck('source_id'))
            ->with(['sender:id,name', 'conversation.student.user:id,name'])->get()->keyBy('id');
        $receipts = $this->available() ? ConversationMessageReceipt::whereIn('message_id', $chat->modelKeys())->get()->keyBy('message_id') : collect();
        $broadcasts = $actor->notifications()->whereIn('id', collect($page->items())->where('source_type', 'broadcast')->pluck('source_id'))->get()->keyBy('id');
        $broadcastReceipts = $broadcastAvailable ? TeacherMessageReceipt::whereIn('notification_id', $broadcasts->modelKeys())
            ->where('recipient_user_id', $actor->id)->with('teacher:id,name')->get()->keyBy('notification_id') : collect();
        if ($broadcastReceipts->isNotEmpty()) {
            TeacherMessageReceipt::whereIn('notification_id', $broadcastReceipts->modelKeys())->whereNull('delivered_at')
                ->update(['delivered_at' => now(), 'updated_at' => now()]);
        }
        if ($receipts->isNotEmpty()) {
            ConversationMessageReceipt::whereIn('notification_id', $receipts->where('recipient_user_id', $actor->id)->modelKeys())->whereNull('delivered_at')
                ->update(['delivered_at' => now(), 'updated_at' => now()]);
        }
        $rows = collect($page->items())->reverse()->map(function ($item) use ($actor, $chat, $receipts, $broadcasts, $broadcastReceipts, $thread): array {
            $at = Carbon::parse($item->created_at)->setTimezone($this->timezone());
            if ($item->source_type === 'broadcast') {
                $notification = $broadcasts->get($item->source_id);
                $receipt = $broadcastReceipts->get($item->source_id);

                return ['key' => 'broadcast:'.$item->source_id, 'body' => $notification->data['body'] ?? '', 'title' => $notification->data['title'] ?? '',
                    'mine' => false, 'sender' => $receipt->teacher?->name ?? 'المعلم', 'is_broadcast' => true, 'time' => $at->format('H:i'), 'date' => $at->format('Y/m/d'),
                    'receipt_url' => null,
                    'viewed_url' => route('teacher-messages.viewed', ['notification' => $item->source_id] + ($actor->role === 'parent' ? ['student' => $thread['student_id']] : [])), '_broadcast_receipt' => $item->source_id];
            }
            $message = $chat->get($item->source_id);
            $receipt = $receipts->get($message->id);
            $mine = (int) $message->sender_id === (int) $actor->id;
            $status = $receipt?->viewed_at ? 'viewed' : ($receipt?->delivered_at ? 'delivered' : 'sent');

            return ['key' => 'chat:'.$message->id, 'body' => $message->body, 'mine' => $mine, 'sender' => $message->sender?->name ?? '',
                'is_broadcast' => false, 'time' => $at->format('H:i'), 'date' => $at->format('Y/m/d'),
                'child_label' => 'بخصوص: '.($message->conversation->student?->user?->name ?? ''),
                'status' => $status, 'status_label' => match ($status) {
                    'viewed' => 'تمت المشاهدة', 'delivered' => 'تم الاستلام', default => 'تم الإرسال'
                },
                'receipt_url' => null,
                '_chat_receipt' => ! $mine && $receipt && (int) $receipt->recipient_user_id === (int) $actor->id ? $receipt->notification_id : null];
        })->values();

        return [$rows, $page->hasMorePages() ? $page->nextPageUrl() : null];
    }

    public function read(Request $request): void
    {
        $actor = $request->user();
        $token = $request->query('read_token');
        $thread = $request->query('thread');
        abort_unless(is_string($token) && preg_match('/^[a-f0-9]{64}$/D', $token) && is_string($thread), 404);
        $snapshot = Cache::get('portal-message-read:'.$token);
        abort_unless(is_array($snapshot) && (int) $snapshot['actor'] === (int) $actor->id && hash_equals($snapshot['thread'], $thread), 404);
        $selectedStudent = $actor->role === 'parent' ? app(ParentMessageScope::class)->selected($request) : null;
        if ($actor->role === 'parent') {
            abort_unless($selectedStudent && (int) ($snapshot['student_id'] ?? 0) === (int) $selectedStudent->id, 404);
        }
        if ($this->available()) {
            $receipts = ConversationMessageReceipt::whereIn('notification_id', $snapshot['chat'])->where('recipient_user_id', $actor->id)->with('message')->get();
            foreach ($receipts as $receipt) {
                $conversation = $receipt->message ? $this->authorizedConversation($actor, $receipt->message->conversation_id) : null;
                abort_unless($conversation && str_contains($this->conversationUrl($actor, $conversation), $thread), 404);
                if ($selectedStudent) {
                    abort_unless((int) $conversation->student_id === (int) $selectedStudent->id, 404);
                }
                DB::transaction(function () use ($receipt, $actor): void {
                    $this->delivered($receipt);
                    ConversationMessageReceipt::whereKey($receipt->notification_id)->whereNull('viewed_at')->update(['viewed_at' => now(), 'updated_at' => now()]);
                    $actor->notifications()->whereKey($receipt->notification_id)->where('data->category', 'conversation_message')->whereNull('read_at')->update(['read_at' => now()]);
                });
            }
        }
    }
}
