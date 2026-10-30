<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\ClassroomRequest;
use App\Models\AcademicYear;
use App\Models\Classroom;
use App\Models\Student;
use App\Models\Subject;
use App\Services\AuditService;
use App\Services\TeacherAssignmentGroups;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ClassroomController extends Controller
{
    public function index(): View
    {
        return view('admin.classes.index', [
            'classes' => Classroom::with('academicYear')->withCount(['students', 'teachers', 'subjects'])->paginate(15),
            'deletedClasses' => Classroom::onlyTrashed()->with('academicYear')->latest('deleted_at')->limit(10)->get(),
        ]);
    }

    public function sections(): View
    {
        $classrooms = Classroom::with('academicYear')->withCount('students')->get()
            ->filter(fn (Classroom $classroom) => isset(TeacherAssignmentGroups::SECTION_GRADE_LABELS[$this->normalizedGradeName($classroom->name)])
                && $this->normalizedGradeName((string) $classroom->section) !== 'ادبي')
            ->sortBy(fn (Classroom $classroom) => [
                array_search($this->normalizedGradeName($classroom->name), array_keys(TeacherAssignmentGroups::SECTION_GRADE_LABELS), true),
                $classroom->academicYear?->name ?? '',
                $this->canonicalSection($classroom->section) ?? '',
            ])
            ->values()
            ->each(function (Classroom $classroom): void {
                $classroom->setAttribute('display_grade', TeacherAssignmentGroups::SECTION_GRADE_LABELS[$this->normalizedGradeName($classroom->name)]);
                $classroom->setAttribute('display_section', $this->canonicalSection($classroom->section) ?? '—');
            });

        return view('admin.classes.sections', compact('classrooms'));
    }

    public function storeSections(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'source_classroom_id' => ['required', 'integer', 'exists:classrooms,id'],
            'sections' => ['required', 'array', 'min:1'],
            'sections.*' => ['required', 'string', Rule::in(['A', 'B', 'C'])],
        ]);

        $source = Classroom::with('subjects')->findOrFail($data['source_classroom_id']);
        abort_unless(isset(TeacherAssignmentGroups::SECTION_GRADE_LABELS[$this->normalizedGradeName($source->name)]), 422);
        abort_if($this->normalizedGradeName((string) $source->section) === 'ادبي', 422);

        $created = 0;
        DB::transaction(function () use ($source, $data, &$created): void {
            $existingSections = Classroom::query()
                ->where('academic_year_id', $source->academic_year_id)
                ->get(['name', 'section'])
                ->filter(fn (Classroom $classroom) => $this->normalizedGradeName($classroom->name) === $this->normalizedGradeName($source->name))
                ->map(fn (Classroom $classroom) => $this->canonicalSection($classroom->section))
                ->all();

            foreach (array_unique($data['sections']) as $section) {
                if (in_array($section, $existingSections, true)) {
                    continue;
                }

                $classroom = Classroom::firstOrCreate(
                    ['name' => $source->name, 'section' => $section, 'academic_year_id' => $source->academic_year_id],
                    ['stage' => $source->stage],
                );

                if ($classroom->wasRecentlyCreated) {
                    $classroom->subjects()->sync($source->subjects->modelKeys());
                    AuditService::record('created', 'classes', $classroom);
                    $existingSections[] = $section;
                    $created++;
                }
            }
        });

        return redirect()->route('admin.classes.sections')
            ->with('success', $created ? "تم إنشاء {$created} شعبة وربطها بمواد الصف المختار." : 'الشعب المحددة موجودة مسبقًا.');
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
            ->filter(fn (Classroom $room): bool => $this->gradeKey($room->name) === $this->gradeKey($data['name']))
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
            ->filter(fn (Classroom $room): bool => $this->gradeKey($room->name) === $this->gradeKey($class->name))
            ->modelKeys();

        return Student::with(['user', 'classroom'])
            ->whereIn('classroom_id', $classroomIds)
            ->orderBy('student_number')
            ->get();
    }

    private function gradeKey(string $name): string
    {
        return preg_replace('/[\s\p{Z}_\-\x{064B}-\x{065F}\x{0670}\x{0640}]+/u', '', strtr(trim($name), ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ى' => 'ي']));
    }

    public function show(Classroom $class): View
    {
        return view('admin.classes.show', ['class' => $class->load(['academicYear', 'students.user', 'subjects', 'teachers.user'])]);
    }

    public function destroy(Classroom $class): RedirectResponse
    {
        abort_if($class->students()->exists(), 422, 'لا يمكن حذف صف يحتوي على طلاب.');

        $dependents = [
            'schedules' => 'schedules',
            'attendance' => 'attendance_records',
            'teacher_assignments' => 'teacher_assignments',
            'exams' => 'exams',
            'lessons' => 'lessons',
            'assignments' => 'assignments',
        ];

        $blockingDependency = collect($dependents)
            ->filter(fn (string $table) => Schema::hasTable($table) && DB::table($table)->where('classroom_id', $class->id)->exists())
            ->keys()
            ->first();

        abort_if($blockingDependency !== null, 422, 'لا يمكن حذف الصف لأنه مرتبط بسجلات تعليمية أو حضور أو إسنادات.');

        DB::transaction(function () use ($class): void {
            $class->delete();
        });

        AuditService::record('deleted', 'classes', $class);

        return redirect()->route('admin.classes.index')
            ->with('success', 'تم نقل الصف إلى المحذوفات مؤقتًا.')
            ->with('undo_class_id', $class->id);
    }

    public function restore(int $class): RedirectResponse
    {
        $deletedClass = Classroom::onlyTrashed()->findOrFail($class);
        $deletedClass->restore();
        AuditService::record('restored', 'classes', $deletedClass);

        return redirect()->route('admin.classes.index')->with('success', 'تمت استعادة الصف وروابط مواده.');
    }

    private function normalizedGradeName(string $name): string
    {
        return TeacherAssignmentGroups::gradeKey($name);
    }

    private function canonicalSection(?string $section): ?string
    {
        return match (trim((string) $section)) {
            '', 'أ', 'ا', 'A', 'a' => 'A',
            'ب', 'B', 'b' => 'B',
            'ج', 'C', 'c' => 'C',
            default => null,
        };
    }
}
