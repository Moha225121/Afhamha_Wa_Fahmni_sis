<?php

namespace App\Http\Controllers\StaffPortal;

use App\Http\Controllers\Controller;
use App\Http\Requests\StudentRequest;
use App\Models\Announcement;
use App\Models\Assignment;
use App\Models\Attendance;
use App\Models\Classroom;
use App\Models\Guardian;
use App\Models\GuardianCall;
use App\Models\Lesson;
use App\Models\SchoolActivity;
use App\Models\Student;
use App\Models\Teacher;
use App\Services\AccountPasswordService;
use App\Services\AuditService;
use App\Services\ParentNotificationService;
use App\Services\PortalAnnouncementFeed;
use App\Services\SchoolAccountService;
use App\Services\StaffAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PortalController extends Controller
{
    public function __construct(private StaffAccess $access) {}

    public function dashboard(Request $request)
    {
        return view('staff.dashboard', [
            'modules' => $this->access->modules($request->user()),
            'announcements' => app(PortalAnnouncementFeed::class)->for($request, 5),
        ]);
    }

    private function anyScope(Builder $query, Request $request, array $permissions, string $classroomColumn, ?string $studentColumn = null): Builder
    {
        return $query->where(function ($outer) use ($request, $permissions, $classroomColumn, $studentColumn) {
            $outer->whereRaw('1 = 0');
            foreach ($permissions as $permission) {
                $outer->orWhere(fn ($branch) => $this->access->constrain($branch, $request->user(), $permission, $classroomColumn, $studentColumn));
            }
        });
    }

    public function module(Request $request, string $module)
    {
        $modules = $this->access->modules($request->user());
        abort_unless(isset($modules[$module]), 403);
        $permissions = $modules[$module]['permissions'];
        $user = $request->user();
        $permission = $permissions[0];
        $data = ['module' => $module, 'title' => $modules[$module]['label']];
        $data['classrooms'] = $this->anyScope(Classroom::query(), $request, $permissions, 'classrooms.id')->orderBy('name')->get();

        if ($module === 'students') {
            $data['rows'] = $this->anyScope(Student::with(['user', 'classroom']), $request, $permissions, 'students.classroom_id', 'students.id')
                ->when($request->q, fn ($q, $v) => $q->where(fn ($search) => $search->whereHas('user', fn ($u) => $u->where('name', 'like', "%$v%"))->orWhere('student_number', 'like', "%$v%")))
                ->when($request->classroom_id, fn ($q, $v) => $q->where('classroom_id', $v))->orderBy('id')->paginate(20)->withQueryString();
        } elseif ($module === 'academic') {
            $studentIds = $this->access->students($user, $permission)->select('students.id');
            $data['rows'] = DB::table('grades')->join('students', 'students.id', '=', 'grades.student_id')->join('users', 'users.id', '=', 'students.user_id')
                ->join('exams', 'exams.id', '=', 'grades.exam_id')->join('subjects', 'subjects.id', '=', 'exams.subject_id')
                ->whereIn('students.id', $studentIds)->select('users.name as student_name', 'subjects.name as subject_name', 'exams.title', 'exams.total_score', 'grades.score', 'grades.published_at')
                ->orderByDesc('grades.id')->paginate(25);
        } elseif ($module === 'attendance') {
            $data['rows'] = Attendance::with('student.user')->whereIn('student_id', $this->access->students($user, $permission)->select('students.id'))
                ->when($request->date, fn ($q, $v) => $q->whereDate('date', $v))->orderByDesc('date')->orderByDesc('id')->paginate(25)->withQueryString();
        } elseif ($module === 'assignments') {
            $classIds = $data['classrooms']->modelKeys();
            $data['rows'] = $this->access->learningResources(Assignment::with(['teacher.user', 'classroom', 'subject',
                'classrooms' => fn ($rooms) => $rooms->whereIn('classrooms.id', $classIds),
            ]), $user, $permission)->latest()->paginate(20);
        } elseif ($module === 'teachers') {
            $classIds = $data['classrooms']->modelKeys();
            $data['rows'] = Teacher::with(['user', 'classrooms' => fn ($q) => $q->whereIn('classrooms.id', $classIds)])
                ->whereHas('classrooms', fn ($q) => $q->whereIn('classrooms.id', $classIds))->orderBy('id')->paginate(20);
            $teacherIds = $data['rows']->getCollection()->modelKeys();
            $data['lessons'] = $this->access->learningResources(Lesson::query(), $user, $permission)->whereIn('teacher_id', $teacherIds)->selectRaw('teacher_id, COUNT(*) as total')->groupBy('teacher_id')->pluck('total', 'teacher_id');
            $data['assignments'] = $this->access->learningResources(Assignment::query(), $user, $permission)->whereIn('teacher_id', $teacherIds)->selectRaw('teacher_id, COUNT(*) as total')->groupBy('teacher_id')->pluck('total', 'teacher_id');
        } elseif ($module === 'calls') {
            $students = $this->access->students($user, $permission);
            $data['rows'] = GuardianCall::with('student.user')->whereIn('student_id', (clone $students)->select('students.id'))->latest()->paginate(20);
            $data['students'] = $students->with('user')->orderBy('id')->get();
        } elseif ($module === 'announcements') {
            $data['rows'] = $this->anyScope(Announcement::query(), $request, $permissions, 'classroom_id')->latest()->paginate(20);
        } elseif ($module === 'activities') {
            $data['rows'] = $this->access->constrain(SchoolActivity::with('classroom'), $user, $permission)->orderByDesc('activity_date')->paginate(20);
        }

        return view('staff.modules.'.$module, $data);
    }

    public function studentCreate(Request $request)
    {
        return view('staff.students.form', ['student' => new Student, 'classrooms' => $this->access->classrooms($request->user(), 'students.create')]);
    }

    public function studentStore(StudentRequest $request, AccountPasswordService $passwords, SchoolAccountService $accounts)
    {
        $data = $request->validated();
        abort_unless($this->access->allows($request->user(), 'students.create', isset($data['classroom_id']) ? (int) $data['classroom_id'] : null), 403);
        $student = DB::transaction(function () use ($data, $accounts, $passwords) {
            $accounts->settings(true);
            $student = $accounts->create('student', array_intersect_key($data, array_flip(['name', 'email', 'phone', 'first_name_en', 'last_name_en', 'status'])) + ['password' => $passwords->forNewAccount(null)], array_intersect_key($data, array_flip(['student_number', 'classroom_id', 'birth_date', 'gender', 'address', 'status'])));
            AuditService::record('created', 'students', $student);

            return $student;
        });

        return redirect()->route('staff.module', 'students')->with('success', 'تمت إضافة الطالب.');
    }

    public function studentEdit(Request $request, Student $student)
    {
        abort_unless($this->access->allows($request->user(), 'students.update', $student->classroom_id, $student->id), 403);

        return view('staff.students.form', ['student' => $student->load('user'), 'classrooms' => $this->access->classrooms($request->user(), 'students.update')]);
    }

    public function studentUpdate(StudentRequest $request, Student $student)
    {
        $data = $request->validated();
        abort_unless($this->access->allows($request->user(), 'students.update', $student->classroom_id, $student->id), 403);
        abort_unless($this->access->allows($request->user(), 'students.update', isset($data['classroom_id']) ? (int) $data['classroom_id'] : null, $student->id), 403);
        DB::transaction(function () use ($student, $data) {
            $old = $student->getAttributes();
            $oldUser = $student->user->getAttributes();
            $student->user->update(array_intersect_key($data, array_flip(['name', 'email', 'phone', 'status'])));
            $student->update(array_intersect_key($data, array_flip(['student_number', 'classroom_id', 'birth_date', 'gender', 'address', 'status'])));
            AuditService::record('updated', 'students', $student, $old);
            AuditService::record('updated', 'student_accounts', $student->user, $oldUser);
        });

        return back()->with('success', 'تم تحديث بيانات الطالب.');
    }

    private function availableGuardians(Request $request)
    {
        if ($this->access->allows($request->user(), 'student-guardians.manage')) {
            return Guardian::with('user')->get();
        }

        return Guardian::with('user')->whereHas('students', fn ($q) => $q->whereIn('students.id', $this->access->students($request->user(), 'student-guardians.manage')->select('students.id')))->get();
    }

    public function guardians(Request $request, Student $student)
    {
        abort_unless($this->access->allows($request->user(), 'student-guardians.manage', $student->classroom_id, $student->id), 403);

        return view('staff.students.guardians', ['student' => $student->load(['user', 'guardians']), 'guardians' => $this->availableGuardians($request)]);
    }

    public function guardiansUpdate(Request $request, Student $student)
    {
        abort_unless($this->access->allows($request->user(), 'student-guardians.manage', $student->classroom_id, $student->id), 403);
        $data = $request->validate(['guardian_ids' => ['nullable', 'array'], 'guardian_ids.*' => ['integer', Rule::in($this->availableGuardians($request)->modelKeys())]]);
        $old = $student->guardians()->pluck('guardians.id')->all();
        $student->guardians()->sync($data['guardian_ids'] ?? []);
        AuditService::record('updated', 'student_guardians', $student, ['guardian_ids' => $old], ['guardian_ids' => $data['guardian_ids'] ?? []]);

        return back()->with('success', 'تم تحديث روابط أولياء الأمور.');
    }

    public function callStore(Request $request, ParentNotificationService $notifications)
    {
        $data = $request->validate(['student_id' => ['required', 'integer', 'exists:students,id'], 'reason' => ['required', 'string', 'max:255'], 'guardian_visible_details' => ['nullable', 'string', 'max:5000'], 'meeting_date' => ['nullable', 'date', 'after_or_equal:today']]);
        $student = $this->access->students($request->user(), 'guardian-calls.manage')->findOrFail($data['student_id']);
        $call = GuardianCall::create($data + ['supervisor_id' => $request->user()->id, 'requested_date' => today(), 'category' => 'administrative', 'status' => 'pending']);
        AuditService::record('created', 'guardian_calls', $call);
        $notifications->sendToGuardians($student, ['title' => 'استدعاء ولي الأمر', 'body' => 'تم طلب حضور ولي الأمر للطالب '.$student->user->name.'.', 'url' => route('parent.guardian-calls', ['student' => $student->id]), 'category' => 'guardian_call']);

        return back()->with('success', 'تم تسجيل الاستدعاء وإبلاغ ولي الأمر.');
    }

    public function callUpdate(Request $request, GuardianCall $guardianCall)
    {
        $student = $guardianCall->student;
        abort_unless($student && $this->access->allows($request->user(), 'guardian-calls.manage', $student->classroom_id, $student->id), 403);
        $data = $request->validate(['status' => ['required', Rule::in(['pending', 'notified', 'scheduled', 'completed', 'cancelled'])]]);
        $old = $guardianCall->getAttributes();
        $guardianCall->update($data + ['resolved_at' => in_array($data['status'], ['completed', 'cancelled']) ? now() : null]);
        AuditService::record('updated', 'guardian_calls', $guardianCall, $old);

        return back()->with('success', 'تم تحديث حالة الاستدعاء.');
    }

    private function authorizeTarget(Request $request, string $permission, ?int $classroomId): void
    {
        // A student-only scope cannot publish a class-wide announcement or activity.
        abort_unless($this->access->allows($request->user(), $permission, $classroomId), 403);
    }

    public function announcementStore(Request $request)
    {
        $data = $request->validate(['title' => ['required', 'string', 'max:255'], 'content' => ['required', 'string', 'max:10000'], 'classroom_id' => ['nullable', 'integer', 'exists:classrooms,id']]);
        $this->authorizeTarget($request, 'announcements.create', isset($data['classroom_id']) ? (int) $data['classroom_id'] : null);
        $announcement = Announcement::create($data + ['audience' => 'parents', 'status' => 'draft', 'created_by' => $request->user()->id]);
        AuditService::record('created', 'announcements', $announcement);

        return back()->with('success', 'تم حفظ مسودة الإعلان.');
    }

    public function announcementPublish(Request $request, Announcement $announcement)
    {
        $this->authorizeTarget($request, 'announcements.publish', $announcement->classroom_id);
        $old = $announcement->getAttributes();
        $announcement->update(['status' => 'published', 'published_at' => now()]);
        AuditService::record('updated', 'announcements', $announcement, $old);

        return back()->with('success', 'تم نشر الإعلان.');
    }

    public function announcementBroadcast(Request $request, Announcement $announcement, ParentNotificationService $notifications)
    {
        $this->authorizeTarget($request, 'announcements.broadcast', $announcement->classroom_id);
        abort_unless($announcement->status === 'published' && $announcement->audience === 'parents' && ! $announcement->published_at?->isFuture() && (! $announcement->expires_at || ! $announcement->expires_at->isPast()), 422, 'يمكن بث إعلان منشور لأولياء الأمور ضمن مدة نشره فقط.');
        $notifications->sendAnnouncement($announcement);
        AuditService::record('broadcast', 'announcements', $announcement);

        return back()->with('success', 'تم بث إشعار الإعلان لأولياء الأمور.');
    }

    public function activityStore(Request $request)
    {
        $data = $request->validate(['title' => ['required', 'string', 'max:255'], 'description' => ['nullable', 'string', 'max:5000'], 'classroom_id' => ['nullable', 'integer', 'exists:classrooms,id'], 'activity_date' => ['required', 'date']]);
        $this->authorizeTarget($request, 'activities.manage', isset($data['classroom_id']) ? (int) $data['classroom_id'] : null);
        $activity = SchoolActivity::create($data + ['status' => 'scheduled', 'created_by' => $request->user()->id]);
        AuditService::record('created', 'school_activities', $activity);

        return back()->with('success', 'تمت إضافة النشاط.');
    }

    public function activityUpdate(Request $request, SchoolActivity $activity)
    {
        $this->authorizeTarget($request, 'activities.manage', $activity->classroom_id);
        $data = $request->validate(['status' => ['required', Rule::in(['scheduled', 'completed', 'cancelled'])]]);
        $old = $activity->getAttributes();
        $activity->update($data);
        AuditService::record('updated', 'school_activities', $activity, $old);

        return back()->with('success', 'تم تحديث النشاط.');
    }
}
