<?php

namespace App\Http\Controllers;

use App\Models\Classroom;
use App\Models\User;
use App\Services\FcmNotificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class NotificationComposerController extends Controller
{
    public function adminForm(): View
    {
        return view('notifications.composer', [
            'classrooms' => Classroom::orderBy('name')->get(),
            'audiences' => ['all', 'students', 'parents', 'teachers'],
            'teacherMode' => false,
            'submissionKey' => (string) Str::uuid(),
        ]);
    }

    public function teacherForm(Request $request): View
    {
        $classrooms = $request->user()->teacher?->classrooms()->orderBy('name')->get() ?? collect();

        return view('notifications.composer', [
            'classrooms' => $classrooms,
            'audiences' => ['students', 'parents'],
            'teacherMode' => true,
            'submissionKey' => (string) Str::uuid(),
        ]);
    }

    public function adminStore(Request $request, FcmNotificationService $notifications): RedirectResponse
    {
        $data = $request->validate([
            'submission_key' => ['required', 'uuid'],
            'title' => ['required', 'string', 'max:150'],
            'body' => ['required', 'string', 'max:600'],
            'audience' => ['required', Rule::in(['all', 'students', 'parents', 'teachers'])],
            'classroom_id' => ['nullable', 'integer', 'exists:classrooms,id'],
        ]);
        $users = $this->adminRecipients($data['audience'], isset($data['classroom_id']) ? (int) $data['classroom_id'] : null);
        $count = $notifications->recordForUsers($users, 'manual:'.$data['submission_key'], fn (User $user) => $this->payloadFor($user, $data['title'], $data['body']));

        return back()->with('success', "تم تسجيل الإشعار لـ {$count} حسابًا.");
    }

    public function teacherStore(Request $request, FcmNotificationService $notifications): RedirectResponse
    {
        $data = $request->validate([
            'submission_key' => ['required', 'uuid'],
            'title' => ['required', 'string', 'max:150'],
            'body' => ['required', 'string', 'max:600'],
            'audience' => ['required', Rule::in(['students', 'parents'])],
            'classroom_id' => ['required', 'integer', 'exists:classrooms,id'],
        ]);
        $teacher = $request->user()->teacher;
        abort_unless($teacher && DB::table('teacher_assignments')->where('teacher_id', $teacher->id)->where('classroom_id', $data['classroom_id'])->exists(), 403);

        $users = $this->classroomRecipients($data['audience'], (int) $data['classroom_id']);
        $count = $notifications->recordForUsers($users, 'teacher:'.$request->user()->id.':'.$data['submission_key'], fn (User $user) => $this->payloadFor($user, $data['title'], $data['body']));

        return back()->with('success', "تم تسجيل الإشعار لـ {$count} حسابًا.");
    }

    private function adminRecipients(string $audience, ?int $classroomId): Collection
    {
        $roles = $audience === 'all' ? ['students', 'parents', 'teachers'] : [$audience];
        $users = collect();
        foreach ($roles as $role) {
            if ($role === 'students' || $role === 'parents') {
                $users = $users->concat($this->classroomRecipients($role, $classroomId));
                continue;
            }
            $users = $users->concat(User::query()->where('role', 'teacher')->where('status', 'active')
                ->when($classroomId, fn ($query) => $query->whereHas('teacher.classrooms', fn ($classrooms) => $classrooms->where('classrooms.id', $classroomId)))
                ->get());
        }

        return $users->unique('id')->values();
    }

    private function classroomRecipients(string $audience, ?int $classroomId): Collection
    {
        $query = User::query()->where('status', 'active');
        if ($audience === 'students') {
            return $query->where('role', 'student')->whereHas('student', fn ($students) => $students->where('status', 'active')->when($classroomId, fn ($items) => $items->where('classroom_id', $classroomId)))->get();
        }

        return $query->where('role', 'parent')->whereHas('guardian', fn ($guardians) => $guardians->where('status', 'active')->whereHas('students', fn ($students) => $students->where('students.status', 'active')->when($classroomId, fn ($items) => $items->where('students.classroom_id', $classroomId))))->get();
    }

    private function payloadFor(User $user, string $title, string $body): array
    {
        $url = match ($user->role) {
            'parent' => route('parent.notifications', [], false),
            'student' => route('student.notifications', [], false),
            'teacher' => route('teacher.dashboard', [], false),
            default => route('admin.notifications.index', [], false),
        };

        return ['title' => $title, 'body' => $body, 'url' => $url, 'category' => 'school_notice'];
    }
}
