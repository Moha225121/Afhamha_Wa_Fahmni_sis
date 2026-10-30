<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StudentRequest;
use App\Models\Classroom;
use App\Models\Guardian;
use App\Models\Student;
use App\Services\AccountPasswordService;
use App\Services\AuditService;
use App\Services\SchoolAccountService;
use App\Services\TeacherLessonTargets;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class StudentController extends Controller
{
    public function index(Request $r): View
    {
        $students = Student::with(['user', 'classroom.academicYear', 'guardians.user'])->when($r->q, fn ($q, $v) => $q->whereHas('user', fn ($u) => $u->where('name', 'like', "%$v%"))->orWhere('student_number', 'like', "%$v%"))->when($r->classroom_id, fn ($q, $v) => $q->where('classroom_id', $v))->when($r->status, fn ($q, $v) => $q->where('status', $v))->latest()->paginate(15)->withQueryString();

        $classrooms = $this->classroomChoices()['classrooms'];
        $classroomLabels = $classrooms->pluck('selection_display_label', 'id');

        return view('admin.students.index', compact('students', 'classrooms', 'classroomLabels'));
    }

    public function create(): View
    {
        return $this->form(new Student);
    }

    public function store(StudentRequest $r, AccountPasswordService $passwords): RedirectResponse
    {
        $s = DB::transaction(function () use ($r, $passwords) {
            app(SchoolAccountService::class)->settings(true);
            $userData = $r->safe()->only(['name', 'email', 'phone', 'first_name_en', 'last_name_en']) + ['password' => $passwords->forNewAccount($r->validated('password')), 'status' => $r->status];
            if ($r->hasFile('photo')) {
                $userData['avatar_path'] = $r->file('photo')->store('student-photos', 'public');
            }
            $s = app(SchoolAccountService::class)->create('student', $userData, $r->safe()->only(['student_number', 'classroom_id', 'birth_date', 'gender', 'address', 'status']));
            $s->guardians()->sync($r->input('guardian_ids', []));
            AuditService::record('created', 'students', $s);

            return $s;
        });

        return redirect()->route('admin.students.show', $s)->with('success', 'تمت إضافة الطالب بنجاح.');
    }

    public function show(Student $student): View
    {
        return view('admin.students.show', [
            'student' => $student->load(['user', 'classroom.academicYear', 'guardians.user']),
            'classroomLabels' => $this->classroomChoices()['classrooms']->pluck('selection_display_label', 'id'),
        ]);
    }

    public function edit(Student $student): View
    {
        return $this->form($student);
    }

    private function form(Student $student): View
    {
        return view('admin.students.form', [
            'student' => $student,
            'guardians' => Guardian::with('user')->get(),
        ] + $this->classroomChoices());
    }

    private function classroomChoices(): array
    {
        $classrooms = TeacherLessonTargets::ordered(Classroom::with('academicYear')->get());
        $classrooms->each(function (Classroom $classroom): void {
            $classroom->setAttribute('selection_grade_key', TeacherLessonTargets::gradeKey($classroom));
            $classroom->setAttribute('selection_branch_label', TeacherLessonTargets::branchLabel($classroom));
        });
        $classroomGrades = TeacherLessonTargets::groups($classrooms, collect());
        $classroomsById = $classrooms->keyBy('id');
        $storedGradeLabel = static function (Classroom $classroom): string {
            $label = trim($classroom->name);
            $section = trim((string) $classroom->selection_branch_label);

            if (in_array($section, ['A', 'B', 'C'], true)
                && preg_match('/(?:\s*[—–-]\s*|\s+)([ABCabcأابج])$/u', $label, $match)
                && mb_strtoupper(strtr($match[1], ['أ' => 'A', 'ا' => 'A', 'ب' => 'B', 'ج' => 'C'])) === $section) {
                $label = trim(preg_replace('/(?:\s*[—–-]\s*|\s+)([ABCabcأابج])$/u', '', $label));
            } elseif ($section !== '' && ! in_array($section, ['A', 'B', 'C'], true)) {
                $normalize = static fn (string $value): string => mb_strtolower(strtr(trim($value), ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا']));
                if (! preg_match('/(?:^|[\s—–-])'.preg_quote($normalize($section), '/').'$/u', $normalize($label))) {
                    $label .= ' — '.$section;
                }
            }

            return $label;
        };
        $classroomGrades = array_map(function (array $grade) use ($classroomsById, $storedGradeLabel): array {
            $grade['label'] = $storedGradeLabel($classroomsById->get($grade['classroom_ids'][0]));

            return $grade;
        }, $classroomGrades);
        $gradesByKey = collect($classroomGrades)->keyBy('key');
        $classrooms->each(function (Classroom $classroom) use ($gradesByKey, $storedGradeLabel): void {
            $grade = $gradesByKey->get($classroom->selection_grade_key);
            $section = trim((string) $classroom->selection_branch_label);
            $physicalBranch = in_array($section, ['A', 'B', 'C'], true);
            $label = $physicalBranch ? $grade['label'] : $storedGradeLabel($classroom);
            if (count($grade['classroom_ids']) > 1) {
                if ($physicalBranch) {
                    $label .= ' — الشعبة '.$section;
                } elseif ($section === '') {
                    $label .= ' — بدون شعبة';
                }
            }
            $displaySection = trim((string) $classroom->section);
            $label = trim($classroom->name).($displaySection !== '' ? ' — الشعبة '.$displaySection : '').' ('.$classroom->stage.')';
            $classroom->setAttribute('selection_display_label', $label);
        });

        return compact('classrooms', 'classroomGrades');
    }

    public function update(StudentRequest $r, Student $student): RedirectResponse
    {
        DB::transaction(function () use ($r, $student) {
            app(SchoolAccountService::class)->settings(true);
            $oldUser = $student->user->getAttributes();
            $old = $student->getAttributes();
            $data = $r->safe()->only(['name', 'email', 'phone', 'status', 'first_name_en', 'last_name_en']);
            $oldPhoto = $student->user->avatar_path;
            if ($r->hasFile('photo')) {
                $data['avatar_path'] = $r->file('photo')->store('student-photos', 'public');
            }
            if ($r->filled('password')) {
                $data['password'] = $r->password;
            }$student->user->update($data);
            $student->update($r->safe()->only(['student_number', 'classroom_id', 'birth_date', 'gender', 'address', 'status']));
            $student->guardians()->sync($r->input('guardian_ids', []));
            if ($r->hasFile('photo') && $oldPhoto) {
                Storage::disk('public')->delete($oldPhoto);
            }
            AuditService::record('updated', 'students', $student, $old);
            AuditService::record('updated', 'student_accounts', $student->user, $oldUser);
        });

        return redirect()->route('admin.students.show', $student)->with('success', 'تم تحديث بيانات الطالب.');
    }

    public function destroy(Student $student): RedirectResponse
    {
        abort_if($student->user_id === request()->user()->id, 422, 'لا يمكنك حذف حسابك.');

        DB::transaction(function () use ($student): void {
            $student->guardians()->detach();
            $student->update(['status' => 'inactive']);
            $student->user()->update(['status' => 'inactive']);
            $student->delete();
            AuditService::record('deleted', 'students', $student);
        });

        return redirect()->route('admin.students.index')->with('success', 'تم حذف الطالب.');
    }
}
