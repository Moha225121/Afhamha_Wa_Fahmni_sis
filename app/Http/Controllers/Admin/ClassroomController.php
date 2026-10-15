<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\ClassroomRequest;
use App\Models\AcademicYear;
use App\Models\Classroom;
use App\Models\Student;
use App\Models\Subject;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use Illuminate\Support\Collection;

class ClassroomController extends Controller
{
    public function index(): View
    {
        return view('admin.classes.index', ['classes' => Classroom::with('academicYear')->withCount(['students', 'teachers', 'subjects'])->paginate(15)]);
    }

    public function create(): View
    {
        return $this->form(new Classroom);
    }

    public function edit(Classroom $class): View
    {
        return $this->form($class);
    }

    private function form(Classroom $class): View
    {
        $students = $class->exists ? $this->studentsAvailableFor($class) : collect();

        return view('admin.classes.form', compact('class', 'students') + ['years' => AcademicYear::all(), 'subjects' => Subject::all()]);
    }

    public function store(ClassroomRequest $r): RedirectResponse
    {
        $c = Classroom::create($r->safe()->except('subject_ids'));
        $c->subjects()->sync($r->input('subject_ids', []));
        AuditService::record('created', 'classes', $c);

        return redirect()->route('admin.classes.show', $c)->with('success', 'تم إنشاء الصف.');
    }

    public function update(ClassroomRequest $r, Classroom $class): RedirectResponse
    {
        $old = $class->getAttributes();
        $data = $r->safe()->except(['subject_ids', 'student_ids']);
        $eligibleClassroomIds = Classroom::query()
            ->where('academic_year_id', $data['academic_year_id'])
            ->get(['id', 'name'])
            ->filter(fn (Classroom $room): bool => TeacherAssignmentGroups::gradeKey($room->name) === TeacherAssignmentGroups::gradeKey($data['name']))
            ->modelKeys();
        $studentIds = collect($r->input('student_ids', []))->map(fn ($id): int => (int) $id)->unique()->values();
        $eligibleStudentIds = Student::query()->whereIn('id', $studentIds)->whereIn('classroom_id', $eligibleClassroomIds)->pluck('id');
        abort_if($eligibleStudentIds->count() !== $studentIds->count(), 422, 'لا يمكن نقل طالب من صف أو سنة دراسية مختلفة.');
        $movedCount = 0;

        DB::transaction(function () use ($class, $data, $r, $eligibleStudentIds, $old, &$movedCount): void {
            $class->update($data);
            $class->subjects()->sync($r->input('subject_ids', []));
            if ($eligibleStudentIds->isNotEmpty()) {
                $movedCount = Student::whereIn('id', $eligibleStudentIds)->where('classroom_id', '!=', $class->id)->update(['classroom_id' => $class->id]);
            }
            AuditService::record('updated', 'classes', $class, $old);
        });

        $message = $movedCount ? "تم تحديث الصف ونقل {$movedCount} من الطلبة إلى الشعبة." : 'تم تحديث الصف.';
        return redirect()->route('admin.classes.show', $class)->with('success', $message);
    }

    /** @return Collection<int, Student> */
    private function studentsAvailableFor(Classroom $class): Collection
    {
        $classroomIds = Classroom::query()
            ->where('academic_year_id', $class->academic_year_id)
            ->get(['id', 'name'])
            ->filter(fn (Classroom $room): bool => TeacherAssignmentGroups::gradeKey($room->name) === TeacherAssignmentGroups::gradeKey($class->name))
            ->modelKeys();

        return Student::with(['user', 'classroom'])
            ->whereIn('classroom_id', $classroomIds)
            ->orderBy('student_number')
            ->get();
    }

    public function show(Classroom $class): View
    {
        return view('admin.classes.show', ['class' => $class->load(['academicYear', 'students.user', 'subjects', 'teachers.user'])]);
    }
}
