<?php

namespace App\Services;

use App\Models\Announcement;
use App\Models\Assignment;
use App\Models\Conversation;
use App\Models\Exam;
use App\Models\Lesson;
use App\Models\Student;
use App\Models\TeacherMessageReceipt;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** Notification data is a navigation hint, never an authorization grant. */
class NotificationDestination
{
    public function for(User $user, array $data, bool $absolute = true): string
    {
        $fallback = $this->fallback($user, $absolute);
        $url = $data['url'] ?? null;
        if ($url !== null && ! is_string($url)) {
            return $fallback;
        }
        $legacy = null;
        $query = [];
        if (is_string($url) && $url !== '') {
            $parts = parse_url($url);
            $origin = parse_url(url('/'));
            if ($parts === false || isset($parts['user']) || isset($parts['pass'])
                || (isset($parts['scheme']) && ($parts['scheme'] !== $origin['scheme']))
                || (isset($parts['host']) && (strtolower($parts['host']) !== strtolower($origin['host'])
                    || ($parts['port'] ?? null) !== ($origin['port'] ?? null)))
                || str_starts_with($url, '//') || preg_match('/[\\\\\x00-\x20]/', $url)) {
                return $fallback;
            }
            $path = $parts['path'] ?? '';
            if (! str_starts_with($path, '/') || str_contains($path, '%') || str_contains($path, '..')) {
                return $fallback;
            }
            parse_str($parts['query'] ?? '', $query);
            try {
                $legacy = Route::getRoutes()->match(Request::create($path, 'GET'));
            } catch (HttpExceptionInterface) {
                return $fallback;
            }
        }

        $name = $legacy?->getName();
        $parameters = $legacy?->parameters() ?? [];
        if (($data['category'] ?? null) === 'teacher_report') {
            $deliveryId = $data['delivery_id'] ?? ($name === 'received-reports.show' ? ($parameters['delivery'] ?? null) : null);

            return is_string($deliveryId) && Str::isUuid($deliveryId)
                && app(TeacherReportDeliveryService::class)->canReceive($user, $deliveryId)
                    ? route('received-reports.show', $deliveryId, $absolute) : $fallback;
        }
        if (($data['category'] ?? null) === 'conversation_message' && in_array($user->role, ['parent', 'teacher'], true)) {
            $id = $this->id($data['conversation_id'] ?? null) ? (int) $data['conversation_id'] : null;
            $conversation = $id ? app(PortalMessengerService::class)->authorizedConversation($user, $id) : null;

            return $conversation ? (app(PortalMessengerService::class)->conversationUrl($user, $conversation, $absolute) ?? $fallback) : $fallback;
        }
        if (($data['category'] ?? null) === 'teacher_notification' && in_array($user->role, ['student', 'parent'], true)) {
            $messageId = $data['teacher_message_id'] ?? ($name === 'teacher-messages.show' ? ($parameters['notification'] ?? null) : null);
            if (is_string($messageId) && Str::isUuid($messageId)
                && app(TeacherMessageReceiptService::class)->available()
                && TeacherMessageReceipt::whereKey($messageId)->where('recipient_user_id', $user->id)->exists()
                && ($record = app(TeacherMessageReceiptService::class)->ownedNotification($user, $messageId))) {
                $receipt = TeacherMessageReceipt::whereKey($messageId)->where('recipient_user_id', $user->id)->first();
                $parameters = ['thread' => app(PortalMessengerService::class)->teacherKey($receipt->teacher_user_id)];
                if ($user->role === 'parent') {
                    $scope = app(ParentMessageScope::class);
                    $preferred = filter_var(request()->query('student'), FILTER_VALIDATE_INT);
                    if (! $preferred && request()->hasSession() && request()->user()?->id === $user->id) {
                        $preferred = app(ParentPortalContext::class)->selectedStudent($user->guardian, $scope->children($user), null)?->id;
                    }
                    $child = $scope->notificationStudent($user, $record->data, $preferred ?: null);
                    if (! $child) {
                        return $fallback;
                    }
                    $parameters['student'] = $child->id;
                }

                return route($user->role.'.messages', $parameters, $absolute);
            }

            return $fallback;
        }
        // Recover IDs from older canonical links; never infer a resource from message text.
        foreach (['lesson', 'assignment', 'exam', 'announcement', 'conversation'] as $resource) {
            if (! array_key_exists($resource.'_id', $data)) {
                $data[$resource.'_id'] = $parameters[$resource] ?? $query[$resource] ?? null;
            }
        }
        $studentId = $data['student_id'] ?? $parameters['student'] ?? $query['student'] ?? null;
        $students = match ($user->role) {
            'student' => $user->student()->where('status', 'active')->get(),
            'parent' => $user->guardian?->students()->where('students.status', 'active')->orderBy('students.id')->get() ?? collect(),
            default => collect(),
        };
        if ($studentId !== null) {
            if (! $this->id($studentId)) {
                return $fallback;
            }
            if (in_array($user->role, ['student', 'parent'], true)) {
                $students = $students->where('id', (int) $studentId);
                if ($students->isEmpty()) {
                    return $fallback;
                }
            }
        }
        if ($user->role === 'student' && $students->isEmpty()) {
            return $fallback;
        }
        $category = $data['category'] ?? 'general';
        $category = match ($name) {
            'student.results', 'parent.results' => $category === 'general' ? 'grade' : $category,
            'student.attendance', 'parent.attendance' => $category === 'general' ? 'attendance' : $category,
            'student.messages', 'parent.messages', 'parent.conversations.show' => $category === 'general' ? 'message' : $category,
            default => $category,
        };
        foreach (['lesson', 'assignment', 'exam', 'announcement', 'conversation'] as $resource) {
            if ($data[$resource.'_id'] !== null) {
                if (! $this->id($data[$resource.'_id'])) {
                    return $fallback;
                }
                // A persisted resource ID takes priority over an obsolete dashboard/list URL.
                if ($category === 'general') {
                    $category = $resource === 'conversation' ? 'message' : $resource;
                }
            }
        }

        if (in_array($category, ['lesson', 'assignment', 'exam'], true)) {
            $id = $data[$category.'_id'];
            if ($id === null) {
                $list = match ($category) {
                    'lesson' => $user->role === 'parent' ? 'parent.children.index' : 'student.subjects.index',
                    'assignment' => $user->role.'.'.($user->role === 'parent' ? 'assignments' : 'assignments.index'),
                    'exam' => $user->role.'.'.($user->role === 'parent' ? 'exams' : 'exams.index'),
                };

                return in_array($user->role, ['student', 'parent'], true) ? route($list, $user->role === 'parent' && $studentId ? ['student' => $studentId] : [], $absolute) : $fallback;
            }
            $resource = match ($category) {
                'lesson' => Lesson::find($id), 'assignment' => Assignment::find($id), 'exam' => Exam::find($id),
            };
            if (! $resource) {
                return $fallback;
            }
            if ($user->role === 'teacher') {
                $teacher = $user->teacher;
                if (! $teacher || $teacher->status !== 'active' || (int) $resource->teacher_id !== (int) $teacher->id) {
                    return $fallback;
                }
                $classroomIds = collect([$resource->classroom_id])->filter();
                if ($resource instanceof Lesson || ($resource instanceof Assignment && Schema::hasTable('assignment_classroom'))) {
                    $classroomIds = $classroomIds->merge($resource->classrooms()->pluck('classrooms.id'));
                }
                if (! DB::table('teacher_assignments')->where('teacher_id', $teacher->id)->where('subject_id', $resource->subject_id)
                    ->when($classroomIds->isNotEmpty(), fn ($q) => $q->whereIn('classroom_id', $classroomIds))->exists()) {
                    return $fallback;
                }
                $route = $category === 'lesson' ? 'teacher.lessons.show' : 'teacher.'.$category.'s.edit';

                return Route::has($route) ? route($route, $id, $absolute) : $fallback;
            }
            $student = $students->first(fn (Student $student) => $this->visibleLearning($category, $resource, $student));
            if (! $student) {
                return $fallback;
            }
            if ($user->role === 'parent') {
                return $category === 'lesson'
                    ? route('parent.lessons.show', ['student' => $student->id, 'lesson' => $id], $absolute)
                    : route('parent.'.$category.'s', ['student' => $student->id, $category => $id], $absolute);
            }

            return match ($category) {
                'lesson' => route('student.lessons.show', ['subject' => $resource->subject_id, 'lesson' => $id], $absolute),
                'assignment' => route('student.assignments.show', $id, $absolute),
                // Starting an attempt is a POST. A notification must never start it implicitly.
                'exam' => route('student.exams.index', ['exam' => $id], $absolute),
            };
        }

        if ($category === 'announcement') {
            $id = $data['announcement_id'];
            if ($user->role === 'student') {
                if ($id === null) {
                    return route('student.ads.index', [], $absolute);
                }

                return Announcement::visibleToStudent($students->first())->whereKey($id)->exists()
                    ? route('student.announcements.show', $id, $absolute) : $fallback;
            }
            $route = $user->role.'.ads.index';
            if (! Route::has($route)) {
                return $fallback;
            }
            if ($id !== null) {
                $classrooms = match ($user->role) {
                    'parent' => $students->pluck('classroom_id'),
                    'teacher' => $user->teacher?->classrooms()->pluck('classrooms.id') ?? collect(),
                    'supervisor' => $user->supervisedClassrooms()->pluck('classrooms.id'),
                    default => collect(),
                };
                $audiences = ['all', 'classroom', match ($user->role) {
                    'parent' => 'parents', 'teacher' => 'teachers', 'supervisor' => 'supervisors', default => 'staff',
                }, $user->role];
                if (! Announcement::currentlyPublished()->whereKey($id)->whereIn('audience', $audiences)
                    ->where(fn ($q) => $q->whereNull('classroom_id')->orWhereIn('classroom_id', $classrooms))->exists()) {
                    return $fallback;
                }
            }

            return route($route, [], $absolute);
        }

        if ($category === 'message' && $data['conversation_id'] !== null) {
            $conversation = Conversation::find($data['conversation_id']);
            if (! $conversation || ! $conversation->participants()->whereKey($user->id)->exists()) {
                return $fallback;
            }
            if ($user->role === 'parent') {
                if ($conversation->student_id !== null && ! $students->contains('id', $conversation->student_id)) {
                    return $fallback;
                }

                return route('parent.conversations.show', $conversation->id, $absolute);
            }

            if ($user->role === 'teacher') {
                return app(PortalMessengerService::class)->conversationUrl($user, $conversation, $absolute) ?? $fallback;
            }

            return $fallback;
        }
        if (in_array($user->role, ['student', 'parent'], true)) {
            if (in_array($category, ['grade', 'result'], true) && isset($data['exam_period_id'])) {
                if (! $this->id($data['exam_period_id']) || ! DB::table('result_publications as p')->join('exam_periods as e', 'e.id', '=', 'p.exam_period_id')
                    ->where('e.id', $data['exam_period_id'])->where('e.status', 'published')->where('e.published_at', '<=', now())
                    ->whereIn('p.student_id', $students->modelKeys())->exists()) {
                    return $fallback;
                }
            }
            $page = match ($category) {
                'grade', 'result' => 'results', 'attendance' => 'attendance', 'message' => 'messages',
                'guardian_call' => $user->role === 'parent' ? 'guardian-calls' : null,
                // Retain the existing finance destination; no finance behavior is changed.
                'finance' => $user->role === 'parent' && $name === 'parent.finance' ? 'finance' : null,
                default => null,
            };
            if ($page && Route::has($user->role.'.'.$page)) {
                return route($user->role.'.'.$page, $user->role === 'parent' && $studentId ? ['student' => $studentId] : [], $absolute);
            }
        }

        return $fallback;
    }

    public function visibleLearning(string $type, Lesson|Assignment|Exam $resource, Student $student): bool
    {
        if (! $student->classroom_id) {
            return false;
        }
        if ($type === 'lesson') {
            return Lesson::published()->forClassroom($student->classroom_id)->whereKey($resource->id)
                ->whereHas('subject', fn ($q) => $q->where('status', 'active')->whereHas('classrooms', fn ($c) => $c->whereKey($student->classroom_id)))
                ->where(fn ($q) => $q->whereNull('unit_id')->orWhereHas('unit', fn ($u) => $u->where('subject_id', $resource->subject_id)))->exists();
        }
        $inClass = (int) $resource->classroom_id === (int) $student->classroom_id;
        if ($type === 'assignment') {
            $inClass = $inClass || (Schema::hasTable('assignment_classroom') && $resource->classrooms()->whereKey($student->classroom_id)->exists());

            return $inClass && in_array($resource->status, ['published', 'active'], true)
                && (! $resource->published_at || $resource->published_at->lte(now()));
        }

        return $inClass && in_array($resource->status, ['published', 'scheduled'], true);
    }

    private function id(mixed $value): bool
    {
        return (is_int($value) || is_string($value)) && preg_match('/^[1-9][0-9]*$/D', (string) $value) && filter_var($value, FILTER_VALIDATE_INT) !== false;
    }

    private function fallback(User $user, bool $absolute): string
    {
        return route(match ($user->role) {
            'student' => 'student.notifications', 'parent' => 'parent.notifications',
            'admin' => 'admin.notifications.index', 'teacher' => 'teacher.dashboard',
            'supervisor' => 'supervisor.dashboard', 'staff' => 'staff.dashboard',
            'financial_officer' => 'finance.parent-notifications.create', default => 'login',
        }, [], $absolute);
    }
}
