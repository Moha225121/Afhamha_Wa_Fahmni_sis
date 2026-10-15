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
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class TeacherController extends Controller
{
    public function index(Request $r): View
    {
        $teachers = Teacher::with(['user', 'subjects', 'classrooms'])->when($r->q, fn ($q, $v) => $q->whereHas('user', fn ($u) => $u->where('name', 'like', "%$v%")->orWhere('email', 'like', "%$v%")))->when($r->status, fn ($q, $v) => $q->where('status', $v))->latest()->paginate(15)->withQueryString();

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
        $normalizeClassroomName = static fn (string $name): string => preg_replace(
            '/[\s\p{Z}_-]+/u',
            '',
            strtr(trim($name), ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا'])
        );
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
            trim((string) $classroom->section) === 'أدبي'
            && !in_array((int) $classroom->id, $assignedClassroomIds, true)
        )->values();
        $classrooms = $classrooms->sortBy(function (Classroom $classroom) use ($gradeOrder, $normalizeClassroomName): array {
            $normalizedName = $normalizeClassroomName($classroom->name);
            $section = strtr(trim((string) $classroom->section), ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا']);
            $sectionOrder = ['علمي' => 0, 'ا' => 0, 'A' => 0, 'a' => 0, 'ادبي' => 1, 'ب' => 1, 'B' => 1, 'b' => 1, 'ج' => 2, 'C' => 2, 'c' => 2];

            return [
                $gradeOrder[$normalizedName] ?? 99,
                $sectionOrder[$section] ?? 50,
                $section,
                $classroom->academicYear?->name ?? '',
                $classroom->id,
            ];
        })->values();
        $duplicateClassroomNames = $classrooms
            ->countBy(fn (Classroom $classroom) => $normalizeClassroomName($classroom->name))
            ->filter(fn (int $count) => $count > 1);
        $classroomLabels = [];
        foreach ($classrooms as $classroom) {
            $normalizedName = $normalizeClassroomName($classroom->name);
            $label = match ($normalizedName) {
                'الصفالاول' => 'الصف الأول',
                'الصفالثاني' => 'الصف الثاني',
                'الصفالثالث' => 'الصف الثالث',
                'الصفالرابع' => 'الصف الرابع',
                'الصفالخامس' => 'الصف الخامس',
                'الصفالسادس' => 'الصف السادس',
                'الصفالسابع' => 'الصف السابع',
                'الصفالثامن' => 'الصف الثامن',
                'الصفالتاسع' => 'الصف التاسع',
                'الاولثانوي' => 'الأول ثانوي',
                'الثانيثانوي' => 'الثاني ثانوي',
                'الثالثثانوي' => 'الثالث ثانوي',
                default => $classroom->name,
            };

            $section = trim((string) $classroom->section);
            $latinSection = match ($section) {
                '', 'أ', 'ا', 'A', 'a' => 'A',
                'ب', 'B', 'b' => 'B',
                'ج', 'C', 'c' => 'C',
                default => null,
            };

            if (isset(\App\Services\TeacherAssignmentGroups::PRIMARY_GRADE_LABELS[$normalizedName]) && $latinSection !== null) {
                $label .= ' '.$latinSection;
            } elseif ($duplicateClassroomNames->has($normalizedName)) {
                $label .= ' — '.($section ?: 'صف إضافي');

                $sameSectionAndYear = $classrooms->filter(fn (Classroom $other) =>
                    $other->id !== $classroom->id
                    && $normalizeClassroomName($other->name) === $normalizedName
                    && $other->section === $classroom->section
                    && $other->academic_year_id === $classroom->academic_year_id
                )->isNotEmpty();
                if ($sameSectionAndYear) {
                    $label .= ' · '.($classroom->academicYear?->name ?? 'سنة دراسية أخرى');
                }
            }

            $classroomLabels[$classroom->id] = $label;
        }
        $classroomSubjects = $classrooms->mapWithKeys(fn (Classroom $classroom) => [
            $classroom->id => $classroom->subjects->pluck('id')->all(),
        ])->all();

        // Keep existing assignments visible when editing, including assignments
        // to subjects that are now inactive or are missing a classroom pivot.
        foreach ($assignments as $assignment) {
            $classroomSubjects[$assignment->classroom_id] ??= [];
            $classroomSubjects[$assignment->classroom_id][] = $assignment->subject_id;
            $classroomSubjects[$assignment->classroom_id] = array_values(array_unique($classroomSubjects[$assignment->classroom_id]));
        }

        $classroomGroups = \App\Services\TeacherAssignmentGroups::groups($classrooms);
        $classroomOptions = [];
        foreach ($classrooms as $classroom) {
            $groupKey = 'all:'.$classroom->id;
            if (isset($classroomGroups[$groupKey])) {
                $memberIds = $classroomGroups[$groupKey];
                $commonSubjects = $classroomSubjects[$memberIds[0]] ?? [];
                foreach ($memberIds as $memberId) {
                    $commonSubjects = array_intersect($commonSubjects, $classroomSubjects[$memberId] ?? []);
                }
                $classroomSubjects[$groupKey] = array_values($commonSubjects);
                $gradeLabel = preg_replace('/ [ABC]$/', '', $classroomLabels[$classroom->id]);
                $classroomOptions[] = ['id' => $groupKey, 'label' => $gradeLabel.' — جميع الشعب'];
            }
            $classroomOptions[] = ['id' => (string) $classroom->id, 'label' => $classroomLabels[$classroom->id]];
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
