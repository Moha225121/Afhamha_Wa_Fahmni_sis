<?php

namespace App\Http\Controllers\GuardianPortal;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Models\Conversation;
use App\Models\Student;
use App\Models\User;
use App\Services\ParentPortalContext;
use App\Services\SchoolChatService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

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
    private function recipientsFor(Student $student): Collection
    {
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
