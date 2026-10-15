<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\ClassroomRequest;
use App\Models\AcademicYear;
use App\Models\Classroom;
use App\Models\Subject;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ClassroomController extends Controller
{
    public function index(): View
    {
        return view('admin.classes.index', ['classes' => Classroom::with('academicYear')->withCount(['students', 'teachers', 'subjects'])->paginate(15)]);
    }

    public function sections(): View
    {
        $classrooms = Classroom::with(['academicYear', 'subjects'])->get()
            ->filter(fn (Classroom $classroom) => in_array(
                $this->normalizedGradeName($classroom->name),
                ['الصفالاول', 'الصفالثاني'],
                true,
            ) && trim((string) $classroom->section) !== 'أدبي')
            ->sortBy(fn (Classroom $classroom) => [
                $this->normalizedGradeName($classroom->name) === 'الصفالاول' ? 1 : 2,
                $classroom->academicYear?->name ?? '',
                $classroom->section ?? '',
            ])
            ->values()
            ->each(function (Classroom $classroom): void {
                $classroom->setAttribute('display_grade', $this->normalizedGradeName($classroom->name) === 'الصفالاول' ? 'الصف الأول' : 'الصف الثاني');
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
        abort_unless(in_array($this->normalizedGradeName($source->name), ['الصفالاول', 'الصفالثاني'], true), 422);
        abort_if(trim((string) $source->section) === 'أدبي', 422);

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
        return view('admin.classes.form', compact('class') + ['years' => AcademicYear::all(), 'subjects' => Subject::all()]);
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
        $class->update($r->safe()->except('subject_ids'));
        $class->subjects()->sync($r->input('subject_ids', []));
        AuditService::record('updated', 'classes', $class, $old);

        return redirect()->route('admin.classes.show', $class)->with('success', 'تم تحديث الصف.');
    }

    public function show(Classroom $class): View
    {
        return view('admin.classes.show', ['class' => $class->load(['academicYear', 'students.user', 'subjects', 'teachers.user'])]);
    }

    private function normalizedGradeName(string $name): string
    {
        $name = preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}\s]+/u', '', trim($name)) ?? trim($name);

        return strtr($name, ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ى' => 'ي']);
    }

    private function canonicalSection(?string $section): ?string
    {
        return match (trim((string) $section)) {
            '', 'أ', 'ا', 'A', 'a', 'علمي' => 'A',
            'ب', 'B', 'b' => 'B',
            'ج', 'C', 'c' => 'C',
            default => null,
        };
    }
}
