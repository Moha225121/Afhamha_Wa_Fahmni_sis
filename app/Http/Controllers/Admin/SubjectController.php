<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\SubjectRequest;
use App\Models\Classroom;
use App\Models\Subject;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class SubjectController extends Controller
{
    public function index(Request $r): View
    {
        return view('admin.subjects.index', ['subjects' => Subject::withCount(['classrooms', 'teachers'])->when($r->q, fn ($q, $v) => $q->where('name', 'like', "%$v%")->orWhere('code', 'like', "%$v%"))->paginate(15)->withQueryString()]);
    }

    public function create(): View
    {
        return $this->form(new Subject);
    }

    public function edit(Subject $subject): View
    {
        return $this->form($subject);
    }

    private function form(Subject $subject): View
    {
        return view('admin.subjects.form', compact('subject') + ['classrooms' => Classroom::all()]);
    }

    public function store(SubjectRequest $r): RedirectResponse
    {
        $s = Subject::create($r->safe()->except('classroom_ids'));
        $s->classrooms()->sync($r->input('classroom_ids', []));
        AuditService::record('created', 'subjects', $s);

        return redirect()->route('admin.subjects.show', $s)->with('success', 'تم إنشاء المادة.');
    }

    public function update(SubjectRequest $r, Subject $subject): RedirectResponse
    {
        $old = $subject->getAttributes();
        $subject->update($r->safe()->except('classroom_ids'));
        $subject->classrooms()->sync($r->input('classroom_ids', []));
        AuditService::record('updated', 'subjects', $subject, $old);

        return redirect()->route('admin.subjects.show', $subject)->with('success', 'تم التحديث.');
    }

    public function show(Subject $subject): View
    {
        return view('admin.subjects.show', ['subject' => $subject->load(['classrooms', 'teachers.user'])]);
    }

    public function destroy(Subject $subject): RedirectResponse
    {
        DB::transaction(function () use ($subject): void {
            $subject = Subject::whereKey($subject->id)->lockForUpdate()->firstOrFail();
            // Include historical rows, not only visible model scopes. Never cascade-delete school work.
            foreach (['classroom_subject', 'teacher_assignments', 'schedules', 'exams', 'library_resources', 'units', 'lessons', 'assignments', 'teacher_report_sources', 'result_marks'] as $table) {
                if (Schema::hasTable($table) && DB::table($table)->where('subject_id', $subject->id)->exists()) {
                    throw ValidationException::withMessages(['subject' => 'لا يمكن حذف مادة مرتبطة بصفوف أو سجلات دراسية. يمكنك تعطيلها للحفاظ على البيانات.']);
                }
            }
            $subject->delete();
            AuditService::record('deleted', 'subjects', $subject);
        });

        return redirect()->route('admin.subjects.index')->with('success', 'تم حذف المادة.');
    }
}
