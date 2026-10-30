<?php

namespace App\Http\Controllers\GuardianPortal;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Models\Conversation;
use App\Models\Student;
use App\Models\User;
use App\Services\ParentPortalContext;
use App\Services\SchoolChatService;
use App\Services\TeacherContactScope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Throwable;

class ConversationController extends Controller
{
    public function __construct(
        private readonly ParentPortalContext $context,
        private readonly SchoolChatService $chat,
    ) {}

    public function index(Request $request): View
    {
        $guardian = $this->context->guardian($request);
        $children = $this->context->children($guardian);
        $selectedStudent = $this->context->selectedStudent($guardian, $children, $request->query('student'));

        return view('parent.messages', [
            'children' => $children,
            'selectedStudent' => $selectedStudent,
            'conversations' => $this->chat->inbox($request->user())
                ->when($request->filled('student'), fn ($items) => $items->where('conversations.student_id', $selectedStudent->id))->get(),
            'announcements' => Announcement::visibleToParent($guardian)->orderByRaw('COALESCE(published_at, created_at) DESC')->orderByDesc('id')->paginate(20)->withQueryString(),
            'activeTab' => $request->query('tab') === 'announcements' ? 'announcements' : 'chats',
        ]);
    }

    public function create(Request $request): View
    {
        $guardian = $this->context->guardian($request);
        $children = $this->context->children($guardian);
        $selectedStudent = $this->context->selectedStudent($guardian, $children, $request->query('student'));

        return view('parent.conversations.create', [
            'children' => $children,
            'selectedStudent' => $selectedStudent,
            'recipients' => $selectedStudent ? $this->recipientsFor($selectedStudent) : collect(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'student_id' => ['required', 'integer', 'exists:students,id'],
            'recipient_id' => ['required', 'integer', 'exists:users,id'],
            'subject' => ['nullable', 'string', 'max:180'],
            'body' => ['nullable', 'string', 'max:3000'],
        ]);

        $guardian = $this->context->guardian($request);
        $student = Student::findOrFail($validated['student_id']);
        $this->context->assertChild($guardian, $student);
        $recipient = $this->recipientsFor($student)->firstWhere('id', (int) $validated['recipient_id']);
        abort_unless($recipient, 404);

        $conversation = DB::transaction(function () use ($request, $student, $recipient, $validated): Conversation {
            User::whereKey($request->user()->id)->lockForUpdate()->first();
            $conversation = $request->user()->conversations()->where('student_id', $student->id)->where('conversations.status', 'open')
                ->whereHas('participants', fn ($users) => $users->whereKey($recipient->id))->has('participants', '=', 2)->latest('conversations.id')->first();
            if (! $conversation) {
                $conversation = Conversation::create([
                    'student_id' => $student->id,
                    'created_by' => $request->user()->id,
                    'subject' => $validated['subject'] ?? null,
                    'status' => 'open',
                    'last_message_at' => now(),
                ]);
                $conversation->participants()->attach([$request->user()->id, $recipient->id]);
            }
            if (! empty($validated['body'])) {
                $this->chat->send($request->user(), $conversation, $validated['body']);
            }

            return $conversation;
        });

        return redirect()->route('parent.conversations.show', $conversation);
    }

    /** @return Collection<int, User> */
    public function show(Request $request, Conversation $conversation): View
    {
        $this->assertParticipant($request, $conversation);

        $guardian = $this->context->guardian($request);
        $children = $this->context->children($guardian);
        $selectedStudent = $this->context->selectedStudent($guardian, $children, $request->query('student', $conversation->student_id));
        abort_if($conversation->student_id && (int) $conversation->student_id !== (int) $selectedStudent?->id, 404);

        $conversation->participants()->updateExistingPivot($request->user()->id, ['last_read_at' => now()]);
        $conversation->load(['student.user', 'participants', 'messages.sender']);

        return view('parent.conversations.show', compact('conversation', 'selectedStudent'));
    }

    public function storeMessage(Request $request, Conversation $conversation): RedirectResponse
    {
        $this->assertParticipant($request, $conversation);
        if ($conversation->student) {
            $this->context->assertChild($this->context->guardian($request), $conversation->student);
        }
        abort_unless($conversation->status === 'open', 403);
        $recipient = $this->currentTeacher($request, $conversation);

        $validated = $request->validate(['body' => ['required', 'string', 'max:3000']]);
        $conversation->messages()->create(['sender_id' => $request->user()->id, 'body' => $validated['body']]);
        $conversation->update(['last_message_at' => now()]);

        $this->notify($recipient, [
            'title' => 'رسالة جديدة',
            'body' => (string) str($validated['body'])->limit(140),
            'url' => match ($recipient->role) {
                'parent' => route('parent.conversations.show', $conversation),
                'teacher' => route('teacher.dashboard'),
                default => route('admin.dashboard'),
            },
            'category' => 'message',
            'conversation_id' => $conversation->id,
            'student_id' => $conversation->student_id,
        ]);

        return back()->with('success', 'تم إرسال الرسالة.');
    }

    private function assertParticipant(Request $request, Conversation $conversation): void
    {
        abort_unless($conversation->participants()->whereKey($request->user()->id)->exists(), 404);
    }

    /** @return Collection<int, User> */
    private function recipientsFor(Student $student): Collection
    {
        return app(TeacherContactScope::class)->teachersFor($student);
    }

    private function currentTeacher(Request $request, Conversation $conversation): User
    {
        $guardian = $this->context->guardian($request);
        abort_unless($guardian->status === 'active' && $conversation->student, 404);
        $this->context->assertChild($guardian, $conversation->student);
        $participants = $conversation->participants()->get();
        $recipient = $participants->firstWhere('id', '!=', $request->user()->id);
        abort_unless($participants->count() === 2 && $recipient
            && app(TeacherContactScope::class)->allows($conversation->student, $recipient), 404);

        return $recipient;
    }

    private function notify(User $recipient, array $payload): void
    {
        try {
            $this->notifications->send($recipient, $payload);
        } catch (Throwable) {
            Log::warning('A saved parent conversation message could not send its device notification.', ['recipient_id' => $recipient->id]);
        }
    }
}
