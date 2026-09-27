<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\ClassroomRequest;
use App\Models\AcademicYear;
use App\Models\Classroom;
use App\Models\Subject;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

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
}
