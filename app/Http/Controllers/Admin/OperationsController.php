<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\ExamRequest;
use App\Http\Requests\LibraryResourceRequest;
use App\Http\Requests\ScheduleRequest;
use App\Models\AcademicYear;
use App\Models\AuditLog;
use App\Models\Classroom;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use App\Services\AuditService;
use App\Services\ParentNotificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class OperationsController extends Controller
{
    public function schedules(Request $r): View
    {
        $rows = DB::table('schedules')->join('classrooms', 'schedules.classroom_id', '=', 'classrooms.id')->join('teachers', 'schedules.teacher_id', '=', 'teachers.id')->join('users', 'teachers.user_id', '=', 'users.id')->join('subjects', 'schedules.subject_id', '=', 'subjects.id')->select('schedules.*', 'classrooms.name as classroom', 'users.name as teacher', 'subjects.name as subject')->when($r->classroom_id, fn ($q, $v) => $q->where('schedules.classroom_id', $v))->orderBy('day_of_week')->orderBy('starts_at')->paginate(20)->withQueryString();

        return view('admin.operations.schedules', compact('rows') + ['classrooms' => Classroom::all()]);
    }

    public function scheduleCreate(): View
    {
        return view('admin.operations.schedule-form', ['classrooms' => Classroom::all(), 'teachers' => Teacher::with('user')->get(), 'subjects' => Subject::where('status', 'active')->get()]);
    }

    public function scheduleStore(ScheduleRequest $r): RedirectResponse
    {
        $d = $r->validated();
        $conflict = DB::table('schedules')->where('day_of_week', $d['day_of_week'])->where(fn ($q) => $q->where('teacher_id', $d['teacher_id'])->orWhere('classroom_id', $d['classroom_id']))->where('starts_at', '<', $d['ends_at'])->where('ends_at', '>', $d['starts_at'])->exists();
        if ($conflict) {
            return back()->withInput()->withErrors(['starts_at' => 'يوجد تعارض زمني للمعلم أو الصف.']);
        }DB::table('schedules')->insert($d + ['created_at' => now(), 'updated_at' => now()]);
        AuditService::record('created', 'schedules');

        return redirect()->route('admin.schedules.index')->with('success', 'تمت إضافة الحصة.');
    }

    public function scheduleDestroy(int $id): RedirectResponse
    {
        abort_unless(DB::table('schedules')->where('id', $id)->delete(), 404);
        AuditService::record('deleted', 'schedules');

        return back()->with('success', 'تم حذف الحصة.');
    }

    public function attendance(Request $r): View
    {
        $date = $r->date ?: now()->toDateString();
        $attendance = DB::table('classrooms')
            ->leftJoin('students', function ($join): void {
                $join->on('students.classroom_id', '=', 'classrooms.id')->where('students.status', 'active');
            })
            ->leftJoin('attendance_records', function ($join) use ($date): void {
                $join->on('attendance_records.student_id', '=', 'students.id')->whereDate('attendance_records.date', $date);
            })
            ->join('academic_years', 'academic_years.id', '=', 'classrooms.academic_year_id')
            ->select('classrooms.id', 'classrooms.name as classroom', 'classrooms.section', 'academic_years.name as academic_year')
            ->selectRaw('count(distinct students.id) as students_total')
            ->selectRaw("count(case when attendance_records.status = 'present' then 1 end) as present")
            ->selectRaw("count(case when attendance_records.status = 'absent' then 1 end) as absent")
            ->selectRaw("count(case when attendance_records.status = 'late' then 1 end) as late")
            ->selectRaw("count(case when attendance_records.status = 'excused' then 1 end) as excused")
            ->groupBy('classrooms.id', 'classrooms.name', 'classrooms.section', 'academic_years.name')
            ->orderBy('academic_years.name')
            ->orderBy('classrooms.name')
            ->get()
            ->map(function ($row) {
                $recorded = $row->present + $row->absent + $row->late + $row->excused;
                $row->rate = $recorded ? round($row->present / $recorded * 100, 1) : 0;

                return $row;
            });

        return view('admin.operations.attendance', compact('attendance', 'date'));
    }

    public function attendanceStore(Request $r, ParentNotificationService $notifications): RedirectResponse
    {
        $d = $r->validate(['date' => ['required', 'date'], 'records' => ['required', 'array'], 'records.*' => ['required', Rule::in(['present', 'absent', 'late', 'excused'])]]);
        $alerts = DB::transaction(function () use ($d, $r) {
            $alerts = [];
            foreach ($d['records'] as $id => $status) {
                $s = Student::findOrFail($id);
                DB::table('attendance_records')->updateOrInsert(['student_id' => $s->id, 'date' => $d['date']], ['classroom_id' => $s->classroom_id, 'status' => $status, 'recorded_by' => $r->user()->id, 'updated_at' => now(), 'created_at' => now()]);
                if ($status !== 'present') {
                    $alerts[] = [$s, $status];
                }
            }AuditService::record('recorded', 'attendance');

            return $alerts;
        });

        $labels = ['absent' => 'غياب', 'late' => 'تأخر', 'excused' => 'غياب بعذر'];
        foreach ($alerts as [$student, $status]) {
            $notifications->sendToGuardians($student, [
                'title' => 'تنبيه حضور',
                'body' => 'تم تسجيل '.$labels[$status].' للطالب '.$student->user->name.' بتاريخ '.$d['date'].'.',
                'url' => route('parent.attendance', ['student' => $student->id]),
                'category' => 'attendance',
            ]);
        }

        return back()->with('success', 'تم حفظ الحضور.');
    }

    public function exams(): View
    {
        $rows = DB::table('exams')->join('subjects', 'exams.subject_id', '=', 'subjects.id')->join('classrooms', 'exams.classroom_id', '=', 'classrooms.id')->join('teachers', 'exams.teacher_id', '=', 'teachers.id')->join('users', 'teachers.user_id', '=', 'users.id')->select('exams.*', 'subjects.name as subject', 'classrooms.name as classroom', 'users.name as teacher')->latest('starts_at')->paginate(20);
        $rows->getCollection()->transform(function ($exam) {
            $exam->effective_status = $this->effectiveExamStatus($exam);

            return $exam;
        });

        return view('admin.operations.exams', compact('rows'));
    }

    private function effectiveExamStatus(object $exam): string
    {
        if ($exam->status === 'completed') {
            return 'completed';
        }

        if (in_array($exam->status, ['scheduled', 'published'], true) && Carbon::parse($exam->starts_at)->isPast()) {
            return 'published';
        }

        return $exam->status;
    }

    public function examCreate(): View
    {
        return view('admin.operations.exam-form', ['classrooms' => Classroom::all(), 'teachers' => Teacher::with('user')->get(), 'subjects' => Subject::where('status', 'active')->get()]);
    }

    public function examStore(ExamRequest $r): RedirectResponse
    {
        DB::table('exams')->insert($r->validated() + ['created_at' => now(), 'updated_at' => now()]);
        AuditService::record('created', 'exams');

        return redirect()->route('admin.exams.index')->with('success', 'تم إنشاء الاختبار.');
    }

    public function examStatus(Request $r, int $id): RedirectResponse
    {
        $d = $r->validate(['status' => ['required', Rule::in(['draft', 'scheduled', 'published', 'completed', 'cancelled'])]]);
        abort_unless(DB::table('exams')->where('id', $id)->update($d + ['updated_at' => now()]), 404);
        AuditService::record('status_changed', 'exams');

        return back()->with('success', 'تم تحديث الحالة.');
    }

    public function grades(Request $r): View
    {
        $grades = Classroom::with('academicYear')->get()->map(function (Classroom $classroom) {
            $studentsTotal = Student::where('classroom_id', $classroom->id)->where('status', 'active')->count();
            $values = collect();

            DB::table('grade_sheets')->where('classroom_id', $classroom->id)->pluck('scores')->each(function ($scores) use ($values): void {
                $decoded = json_decode($scores ?? '{}', true);
                if (is_array($decoded)) {
                    collect($decoded)->each(function ($score) use ($values): void {
                        if (is_numeric($score)) {
                            $values->push((float) $score);
                        }
                    });
                }
            });

            return (object) [
                'classroom' => $classroom->name,
                'section' => $classroom->section,
                'academic_year' => $classroom->academicYear?->name,
                'students_total' => $studentsTotal,
                'graded_students' => $values->count(),
                'average' => $values->isEmpty() ? null : round((float) $values->average(), 1),
                'highest' => $values->max(),
                'lowest' => $values->min(),
            ];
        });

        return view('admin.operations.grades', compact('grades'));
    }

    public function gradesStore(Request $r, ParentNotificationService $notifications): RedirectResponse
    {
        $d = $r->validate(['exam_id' => ['required', 'exists:exams,id'], 'scores' => ['required', 'array'], 'scores.*' => ['nullable', 'numeric', 'min:0']]);
        $exam = DB::table('exams')
            ->join('subjects', 'exams.subject_id', '=', 'subjects.id')
            ->where('exams.id', $d['exam_id'])
            ->select('exams.*', 'subjects.name as subject')
            ->first();
        $publishedStudentIds = DB::transaction(function () use ($d, $exam) {
            $studentIds = [];
            foreach ($d['scores'] as $id => $score) {
                if ($score === null) {
                    continue;
                }abort_if((float) $score > (float) $exam->total_score, 422, 'الدرجة تتجاوز الدرجة الكلية.');
                DB::table('grades')->updateOrInsert(['exam_id' => $exam->id, 'student_id' => $id], ['score' => $score, 'published_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
                $studentIds[] = $id;
            }AuditService::record('published', 'grades');

            return $studentIds;
        });

        Student::with('user')->whereIn('id', $publishedStudentIds)->get()->each(function (Student $student) use ($notifications, $exam): void {
            $notifications->sendToGuardians($student, [
                'title' => 'تم نشر نتيجة جديدة',
                'body' => 'تم نشر نتيجة '.$exam->title.' في مادة '.$exam->subject.' للطالب '.$student->user->name.'.',
                'url' => route('parent.results', ['student' => $student->id]),
                'category' => 'grade',
            ]);
        });

        return back()->with('success', 'تم حفظ الدرجات.');
    }

    public function library(): View
    {
        return view('admin.operations.library', ['rows' => DB::table('library_resources')->latest()->paginate(15), 'subjects' => Subject::all(), 'classrooms' => Classroom::all()]);
    }

    public function libraryStore(LibraryResourceRequest $r): RedirectResponse
    {
        $isPublic = $r->boolean('is_public');
        $disk = $isPublic ? 'public' : 'local';
        $path = $r->file('file')->store('library', $disk);

        DB::table('library_resources')->insert($r->safe()->except(['file', 'is_public']) + ['file_path' => $path, 'disk' => $disk, 'is_public' => $isPublic, 'status' => 'active', 'created_by' => $r->user()->id, 'created_at' => now(), 'updated_at' => now()]);
        AuditService::record('created', 'library');

        return back()->with('success', 'تم رفع المورد.');
    }

    public function libraryDestroy(int $id): RedirectResponse
    {
        $row = DB::table('library_resources')->find($id);
        abort_unless($row, 404);
        $disk = in_array($row->disk ?? 'public', ['local', 'public'], true) ? ($row->disk ?? 'public') : 'public';
        Storage::disk($disk)->delete($row->file_path);
        DB::table('library_resources')->where('id', $id)->delete();
        AuditService::record('deleted', 'library');

        return back()->with('success', 'تم حذف المورد.');
    }

    public function users(Request $r): View
    {
        $users = User::when($r->q, fn ($q, $v) => $q->where('name', 'like', "%$v%")->orWhere('email', 'like', "%$v%"))->when($r->role, fn ($q, $v) => $q->where('role', $v))->latest()->paginate(20)->withQueryString();

        return view('admin.operations.users', compact('users'));
    }

    public function userUpdate(Request $r, User $user): RedirectResponse
    {
        $d = $r->validate(['role' => ['required', Rule::in(['admin', 'teacher', 'student', 'parent'])], 'status' => ['required', Rule::in(['active', 'inactive'])]]);
        abort_if($user->is($r->user()) && $d['status'] === 'inactive', 422, 'لا يمكنك تعطيل حسابك.');
        $old = $user->getAttributes();
        $user->update($d);
        AuditService::record('updated', 'users', $user, $old);

        return back()->with('success', 'تم تحديث المستخدم.');
    }

    public function reports(): View
    {
        return view('admin.operations.reports', ['students' => Student::selectRaw('status,count(*) total')->groupBy('status')->get(), 'teachers' => Teacher::selectRaw('status,count(*) total')->groupBy('status')->get(), 'attendance' => DB::table('attendance_records')->selectRaw('status,count(*) total')->groupBy('status')->get(), 'results' => DB::table('grades')->selectRaw('avg(score) average,max(score) highest,min(score) lowest,count(*) total')->first()]);
    }

    public function settings(): View
    {
        return view('admin.operations.settings', ['settings' => DB::table('settings')->pluck('value', 'key'), 'currentYear' => AcademicYear::where('is_current', true)->first()]);
    }

    public function settingsUpdate(Request $r): RedirectResponse
    {
        $d = $r->validate(['school_name' => ['required', 'string', 'max:255'], 'school_phone' => ['nullable', 'string', 'max:30'], 'school_email' => ['nullable', 'email'], 'school_address' => ['nullable', 'string'], 'platform_name' => ['required', 'string', 'max:255'], 'timezone' => ['required', 'timezone'], 'academic_year_name' => ['required', 'string', 'max:30'], 'academic_year_starts_at' => ['required', 'date'], 'academic_year_ends_at' => ['required', 'date', 'after:academic_year_starts_at']]);
        $year = ['name' => $d['academic_year_name'], 'starts_at' => $d['academic_year_starts_at'], 'ends_at' => $d['academic_year_ends_at']];
        unset($d['academic_year_name'], $d['academic_year_starts_at'], $d['academic_year_ends_at']);
        AcademicYear::query()->update(['is_current' => false]);
        AcademicYear::updateOrCreate(['name' => $year['name']], $year + ['is_current' => true]);
        foreach ($d as $key => $value) {
            DB::table('settings')->updateOrInsert(['key' => $key], ['value' => $value, 'group' => str_starts_with($key, 'school_') ? 'school' : 'system', 'created_at' => now(), 'updated_at' => now()]);
        }AuditService::record('updated', 'settings');

        return back()->with('success', 'تم حفظ الإعدادات.');
    }

    public function roles(): View
    {
        return view('admin.operations.roles', ['roles' => config('permissions.roles')]);
    }

    public function auditLogs(): View
    {
        return view('admin.operations.audit', ['rows' => AuditLog::latest('created_at')->paginate(25)]);
    }

    public function notifications(Request $r): View
    {
        return view('admin.operations.notifications', ['rows' => $r->user()->notifications()->paginate(20)]);
    }

    public function notificationsRead(Request $r): RedirectResponse
    {
        $r->user()->unreadNotifications->markAsRead();

        return back()->with('success', 'تم تعليم الإشعارات كمقروءة.');
    }
}
