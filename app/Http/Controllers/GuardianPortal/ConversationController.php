<?php

namespace App\Http\Controllers\GuardianPortal;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Student;
use App\Models\User;
use App\Services\ParentNotificationService;
use App\Services\ParentPortalContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class ConversationController extends Controller
{
    public function __construct(
        private readonly ParentPortalContext $context,
        private readonly ParentNotificationService $notifications,
    ) {}

    public function index(Request $request): View
    {
        $guardian = $this->context->guardian($request);
        $children = $this->context->children($guardian);
        $selectedStudent = $this->context->selectedStudent($guardian, $children, $request->query('student'));

        return view('parent.conversations.index', [
            'children' => $children,
            'selectedStudent' => $selectedStudent,
            'conversations' => $request->user()->conversations()
                ->with(['student.user', 'participants', 'latestMessage.sender'])
                ->orderByDesc('conversations.last_message_at')
                ->get(),
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
            'body' => ['required', 'string', 'max:3000'],
        ]);

        $guardian = $this->context->guardian($request);
        $student = Student::findOrFail($validated['student_id']);
        $this->context->assertChild($guardian, $student);
        $recipient = $this->recipientsFor($student)->firstWhere('id', (int) $validated['recipient_id']);
        abort_unless($recipient, 404);

        $conversation = DB::transaction(function () use ($request, $student, $recipient, $validated): Conversation {
            $conversation = Conversation::create([
                'student_id' => $student->id,
                'created_by' => $request->user()->id,
                'subject' => $validated['subject'] ?: null,
                'status' => 'open',
                'last_message_at' => now(),
            ]);

            $conversation->participants()->attach([$request->user()->id, $recipient->id]);
            $conversation->messages()->create(['sender_id' => $request->user()->id, 'body' => $validated['body']]);

            return $conversation;
        });

        $this->notifications->send($recipient, [
            'title' => 'رسالة جديدة من ولي أمر',
            'body' => (string) str($validated['body'])->limit(140),
            'url' => $recipient->isParent() ? route('parent.conversations.show', $conversation) : route('admin.dashboard'),
            'category' => 'message',
            'student_id' => $student->id,
        ]);

        return redirect()->route('parent.conversations.show', $conversation)->with('success', 'تم إرسال الرسالة.');
    }

    public function show(Request $request, Conversation $conversation): View
    {
        $this->assertParticipant($request, $conversation);

        if ($conversation->student) {
            $this->context->assertChild($this->context->guardian($request), $conversation->student);
        }

        $conversation->participants()->updateExistingPivot($request->user()->id, ['last_read_at' => now()]);
        $conversation->load(['student.user', 'participants', 'messages.sender']);

        return view('parent.conversations.show', compact('conversation'));
    }

    public function storeMessage(Request $request, Conversation $conversation): RedirectResponse
    {
        $this->assertParticipant($request, $conversation);
        abort_unless($conversation->status === 'open', 403);

        $validated = $request->validate(['body' => ['required', 'string', 'max:3000']]);
        $conversation->messages()->create(['sender_id' => $request->user()->id, 'body' => $validated['body']]);
        $conversation->update(['last_message_at' => now()]);

        $conversation->participants()->where('users.id', '!=', $request->user()->id)->get()->each(function (User $recipient) use ($conversation, $validated): void {
            $this->notifications->send($recipient, [
                'title' => 'رسالة جديدة',
                'body' => (string) str($validated['body'])->limit(140),
                'url' => $recipient->isParent() ? route('parent.conversations.show', $conversation) : route('admin.dashboard'),
                'category' => 'message',
                'student_id' => $conversation->student_id,
            ]);
        });

        return back()->with('success', 'تم إرسال الرسالة.');
    }

    private function assertParticipant(Request $request, Conversation $conversation): void
    {
        abort_unless($conversation->participants()->whereKey($request->user()->id)->exists(), 404);
    }

    /** @return Collection<int, User> */
    private function recipientsFor(Student $student): Collection
    {
        if (! $student->classroom_id) {
            return collect();
        }

        return User::query()
            ->where('status', 'active')
            ->where(function ($query) use ($student): void {
                $query->where('role', 'admin')->orWhere(function ($teachers) use ($student): void {
                    $teachers->where('role', 'teacher')->whereHas('teacher.classrooms', fn ($classes) => $classes->whereKey($student->classroom_id));
                });
            })
            ->orderBy('role')
            ->orderBy('name')
            ->get(['id', 'name', 'role']);
    }
}
