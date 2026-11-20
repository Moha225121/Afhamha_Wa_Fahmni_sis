<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\TeacherRequest;
use App\Models\Classroom;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use App\Services\AuditService;
use App\Services\AccountPasswordService;
use App\Services\TeacherAssignmentGroups;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class TeacherController extends Controller
{
    public function index(Request $r): View
    {
        $filters = $r->validate(['q' => 'nullable|string|max:150', 'status' => 'nullable|in:active,inactive']);
        $term = trim($filters['q'] ?? '');
        $teachers = Teacher::with(['user', 'subjects', 'classrooms'])
            ->when($term !== '', fn ($q) => $q->whereHas('user', fn ($u) => $u->where(fn ($match) => $match
                ->whereLike('name', "%{$term}%")->orWhereLike('email', "%{$term}%")->orWhereLike('phone', "%{$term}%"))))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))->latest()->orderByDesc('id')->paginate(15)->withQueryString();

        return view('admin.teachers.index', compact('teachers'));
    }

    public function create(): View
    {
        return $this->form(new Teacher);
    }

    public function edit(Teacher $teacher): View
    {
        return $this->form($teacher->load('classrooms'));
    }

    private function form(Teacher $teacher): View
    {
        $assignments = DB::table('teacher_assignments')->where('teacher_id', $teacher->id)->get();
        $assignedSubjectIds = $assignments->pluck('subject_id')->unique();
        $subjects = Subject::query()
            ->where(function ($query) use ($teacher, $assignedSubjectIds) {
                $query->where('status', 'active');

                if ($teacher->exists && $assignedSubjectIds->isNotEmpty()) {
                    $query->orWhereIn('id', $assignedSubjectIds);
                }
            })
            ->orderBy('name')
            ->get();
        $gradeOrder = [
            'الصفالاول' => 1,
            'الصفالثاني' => 2,
            'الصفالثالث' => 3,
            'الصفالرابع' => 4,
            'الصفالخامس' => 5,
            'الصفالسادس' => 6,
            'الصفالسابع' => 7,
            'الصفالثامن' => 8,
            'الصفالتاسع' => 9,
            'الاولثانوي' => 10,
            'الثانيثانوي' => 11,
            'الثالثثانوي' => 12,
        ];
        $classrooms = Classroom::with([
            'academicYear',
            'subjects' => fn ($query) => $query->where('status', 'active')->orderBy('name'),
        ])->get();
        $assignedClassroomIds = $assignments->pluck('classroom_id')->map(fn ($id) => (int) $id)->all();
        $classrooms = $classrooms->reject(fn (Classroom $classroom) =>
            TeacherAssignmentGroups::gradeKey((string) $classroom->section) === 'ادبي'
            && !in_array((int) $classroom->id, $assignedClassroomIds, true)
        )->values();
        $classrooms = $classrooms->sortBy(function (Classroom $classroom) use ($gradeOrder): array {
            $normalizedName = TeacherAssignmentGroups::gradeKey($classroom->name);
            $section = TeacherAssignmentGroups::canonicalPrimarySection($classroom->section) ?? $classroom->section;

            return [
                $gradeOrder[$normalizedName] ?? 99,
                $normalizedName,
                $section,
                $classroom->academicYear?->name ?? '',
                $classroom->id,
            ];
        })->values();
        $gradesInMultipleYears = $classrooms->groupBy(fn (Classroom $room) => TeacherAssignmentGroups::gradeKey($room->name))
            ->filter(fn ($rooms) => $rooms->pluck('academic_year_id')->unique()->count() > 1);
        $classroomLabels = [];
        $gradeLabels = [];
        foreach ($classrooms as $classroom) {
            $normalizedName = TeacherAssignmentGroups::gradeKey($classroom->name);
            $primaryGrade = TeacherAssignmentGroups::PRIMARY_GRADE_LABELS[$normalizedName] ?? null;
            $gradeLabel = $primaryGrade ?? match ($normalizedName) {
                'الصفالسابع' => 'الصف السابع',
                'الصفالثامن' => 'الصف الثامن',
                'الصفالتاسع' => 'الصف التاسع',
                'الاولثانوي' => 'الأول ثانوي',
                'الثانيثانوي' => 'الثاني ثانوي',
                'الثالثثانوي' => 'الثالث ثانوي',
                default => $classroom->name,
            };

            $section = trim((string) $classroom->section);
            $latinSection = $primaryGrade ? TeacherAssignmentGroups::canonicalPrimarySection($section) : null;
            $label = $gradeLabel;
            if ($latinSection !== null) {
                $label .= ' '.$latinSection;
            } elseif ($section !== '') {
                $label .= ' — الشعبة '.$section;
            }
            $classroomLabels[$classroom->id] = $label;
            $gradeLabels[$classroom->id] = $gradeLabel;
        }
        $classroomSubjects = $classrooms->mapWithKeys(fn (Classroom $classroom) => [
            $classroom->id => TeacherAssignmentGroups::gradeKey((string) $classroom->section) === 'ادبي'
                ? []
                : $classroom->subjects->pluck('id')->all(),
        ])->all();

        // Keep existing assignments visible when editing, including assignments
        // to subjects that are now inactive or are missing a classroom pivot.
        foreach ($assignments as $assignment) {
            $classroomSubjects[$assignment->classroom_id] ??= [];
            $classroomSubjects[$assignment->classroom_id][] = $assignment->subject_id;
            $classroomSubjects[$assignment->classroom_id] = array_values(array_unique($classroomSubjects[$assignment->classroom_id]));
        }

        $classroomGroups = TeacherAssignmentGroups::groups($classrooms);
        $classroomOptions = [];
        foreach ($classrooms as $classroom) {
            $yearSuffix = $gradesInMultipleYears->has(TeacherAssignmentGroups::gradeKey($classroom->name))
                ? ' · '.$classroom->academicYear?->name : '';
            $groupKey = 'all:'.$classroom->id;
            if (isset($classroomGroups[$groupKey])) {
                $memberIds = $classroomGroups[$groupKey];
                $commonSubjects = $classroomSubjects[$memberIds[0]] ?? [];
                foreach ($memberIds as $memberId) {
                    $commonSubjects = array_intersect($commonSubjects, $classroomSubjects[$memberId] ?? []);
                }
                $classroomSubjects[$groupKey] = array_values($commonSubjects);
                $classroomOptions[] = ['id' => $groupKey, 'label' => $gradeLabels[$classroom->id].' — جميع الشعب'.$yearSuffix];
            }
            $classroomOptions[] = ['id' => (string) $classroom->id, 'label' => $classroomLabels[$classroom->id].$yearSuffix];
        }

        // Display a complete subject assignment across the grade as one row on edit.
        $displayAssignments = $assignments->map(fn ($assignment) => (object) [
            'classroom_id' => (string) $assignment->classroom_id,
            'subject_id' => $assignment->subject_id,
        ]);
        foreach ($classroomGroups as $groupKey => $memberIds) {
            foreach ($classroomSubjects[$groupKey] as $subjectId) {
                $matching = $displayAssignments->filter(fn ($assignment) =>
                    (int) $assignment->subject_id === (int) $subjectId
                    && in_array((int) $assignment->classroom_id, $memberIds, true)
                );
                if ($matching->pluck('classroom_id')->unique()->count() === count($memberIds)) {
                    $displayAssignments = $displayAssignments->reject(fn ($assignment) =>
                        (int) $assignment->subject_id === (int) $subjectId
                        && in_array((int) $assignment->classroom_id, $memberIds, true)
                    )->push((object) ['classroom_id' => $groupKey, 'subject_id' => $subjectId]);
                }
            }
        }
        $displayAssignments = $displayAssignments->values();

        return view('admin.teachers.form', compact('teacher', 'assignments', 'displayAssignments', 'subjects', 'classrooms', 'classroomSubjects', 'classroomLabels', 'classroomOptions', 'classroomGroups'));
    }

    public function store(TeacherRequest $r, AccountPasswordService $passwords): RedirectResponse
    {
        $t = DB::transaction(function () use ($r, $passwords) {
            app(\App\Services\SchoolAccountService::class)->settings(true);
            $t = app(\App\Services\SchoolAccountService::class)->create('teacher', $r->safe()->only(['name', 'email', 'phone', 'first_name_en', 'last_name_en']) + ['password' => $passwords->forNewAccount($r->validated('password')), 'status' => $r->status], $r->only('specialization', 'status'));
            $this->sync($t, $r->expandedAssignments());
            AuditService::record('created', 'teachers', $t);

            return $t;
        });

        return redirect()->route('admin.teachers.show', $t)->with('success', 'تمت إضافة المعلم.');
    }

    public function update(TeacherRequest $r, Teacher $teacher): RedirectResponse
    {
        DB::transaction(function () use ($r, $teacher) {
            app(\App\Services\SchoolAccountService::class)->settings(true);
            $oldUser = $teacher->user->getAttributes();
            $old = $teacher->getAttributes();
            $d = $r->only('name', 'email', 'phone', 'status', 'first_name_en', 'last_name_en');
            if ($r->filled('password')) {
                $d['password'] = $r->password;
            }$teacher->user->update($d);
            $teacher->update($r->only('specialization', 'status'));
            $this->sync($teacher, $r->expandedAssignments());
            AuditService::record('updated', 'teachers', $teacher, $old);
            AuditService::record('updated', 'teacher_accounts', $teacher->user, $oldUser);
        });

        return redirect()->route('admin.teachers.show', $teacher)->with('success', 'تم تحديث المعلم.');
    }

    public function destroy(Teacher $teacher): RedirectResponse
    {
        DB::transaction(function () use ($teacher): void {
            $teacher = Teacher::whereKey($teacher->id)->lockForUpdate()->firstOrFail();
            foreach (['teacher_assignments', 'schedules', 'exams', 'assignments', 'lessons', 'grade_sheets', 'teacher_student_notes'] as $table) {
                if (DB::table($table)->where('teacher_id', $teacher->id)->exists()) {
                    throw \Illuminate\Validation\ValidationException::withMessages(['teacher' => 'لا يمكن حذف معلم مرتبط بتكليفات أو سجل تعليمي. تُحفظ العلاقات والسجلات الحالية.']);
                }
            }
            $user = $teacher->user()->lockForUpdate()->firstOrFail();
            if ($user->conversations()->exists() || $user->sentMessages()->exists()) {
                throw \Illuminate\Validation\ValidationException::withMessages(['teacher' => 'لا يمكن حذف معلم مرتبط بسجل مراسلات.']);
            }
            // The profile already uses SoftDeletes. Its user FK intentionally
            // prevents hard deletion, so retain the identity and revoke login.
            $old = $teacher->getAttributes();
            $user->update(['status' => 'inactive']);
            $teacher->update(['status' => 'inactive']);
            $teacher->delete();
            AuditService::record('deleted', 'teachers', $teacher, $old);
        });

        return redirect()->route('admin.teachers.index')->with('success', 'تم حذف المعلم.');
    }

    private function sync(Teacher $t, array $items): void
    {
        DB::table('teacher_assignments')->where('teacher_id', $t->id)->delete();
        foreach ($items as $a) {
            DB::table('teacher_assignments')->insertOrIgnore(['teacher_id' => $t->id] + $a);
        }
    }

    public function show(Teacher $teacher): View
    {
        return view('admin.teachers.show',['teacher' => $teacher->load(['user', 'subjects', 'classrooms'])]);
    }
}
