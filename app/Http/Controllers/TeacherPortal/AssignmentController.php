<?php

namespace App\Http\Controllers\TeacherPortal;

use App\Http\Controllers\Controller;
use App\Http\Controllers\TeacherPortal\Concerns\InteractsWithTeacherScope;
use App\Http\Requests\TeacherPortal\AssignmentRequest;
use App\Models\Classroom;
use App\Models\Student;
use App\Models\Subject;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class AssignmentController extends Controller
{
    use InteractsWithTeacherScope;

    public function index(Request $request): View
    {
        $teacher = $this->teacher($request);

        $rows = DB::table('assignments')
            ->join('subjects', 'assignments.subject_id', '=', 'subjects.id')
            ->join('classrooms', 'assignments.classroom_id', '=', 'classrooms.id')
            ->where('assignments.teacher_id', $teacher->id)
            ->select('assignments.*', 'subjects.name as subject', 'classrooms.name as classroom')
            ->selectSub(function ($query) {
                $query->from('students')->selectRaw('count(*)')->whereColumn('students.classroom_id', 'assignments.classroom_id')->where('students.status', 'active');
            }, 'students_total')
            ->selectSub(function ($query) {
                $query->from('assignment_submissions')->selectRaw('count(*)')->whereColumn('assignment_submissions.assignment_id', 'assignments.id')->whereNotNull('submitted_at');
            }, 'submissions_count')
            ->latest('assignments.due_date')
            ->paginate(20);

        return view('teacher.assignments.index', compact('rows'));
    }

    public function create(Request $request): View
    {
        return $this->form($request);
    }

    public function edit(Request $request, int $assignment): View
    {
        $teacher = $this->teacher($request);
        $row = DB::table('assignments')->where('id', $assignment)->first();
        abort_unless($row && (int) $row->teacher_id === $teacher->id, 404);

        return $this->form($request, $row);
    }

    private function form(Request $request, ?object $assignment = null): View
    {
        $teacher = $this->teacher($request);
        $classroomIds = $this->assignedClassroomIds($teacher);
        $pairs = $this->assignmentPairs($teacher);

        return view('teacher.assignments.form', [
            'assignment' => $assignment,
            'classrooms' => Classroom::whereIn('id', $classroomIds)->get(),
            'subjects' => Subject::whereIn('id', $pairs->pluck('subject_id')->unique())->get(),
            'pairs' => $pairs,
        ]);
    }

    public function store(AssignmentRequest $request): RedirectResponse
    {
        $teacher = $this->teacher($request);
        $data = $request->validated();
        abort_unless($this->ownsPair($teacher, (int) $data['classroom_id'], (int) $data['subject_id']), 403, 'أنت غير مكلف بهذا الصف/المادة.');

        $path = $request->hasFile('attachment') ? $request->file('attachment')->store('assignments', 'public') : null;

        DB::table('assignments')->insert([
            'title' => $data['title'],
            'classroom_id' => $data['classroom_id'],
            'subject_id' => $data['subject_id'],
            'teacher_id' => $teacher->id,
            'description' => $data['description'] ?? null,
            'due_date' => $data['due_date'],
            'max_score' => $data['max_score'],
            'attachment_path' => $path,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        AuditService::record('created', 'assignments');

        return redirect()->route('teacher.assignments.index')->with('success', 'تم إنشاء الواجب.');
    }

    public function update(AssignmentRequest $request, int $assignment): RedirectResponse
    {
        $teacher = $this->teacher($request);
        $row = DB::table('assignments')->where('id', $assignment)->first();
        abort_unless($row && (int) $row->teacher_id === $teacher->id, 404);

        $data = $request->validated();
        abort_unless($this->ownsPair($teacher, (int) $data['classroom_id'], (int) $data['subject_id']), 403, 'أنت غير مكلف بهذا الصف/المادة.');

        $attributes = [
            'title' => $data['title'],
            'classroom_id' => $data['classroom_id'],
            'subject_id' => $data['subject_id'],
            'description' => $data['description'] ?? null,
            'due_date' => $data['due_date'],
            'max_score' => $data['max_score'],
            'updated_at' => now(),
        ];

        if ($request->hasFile('attachment')) {
            if ($row->attachment_path) {
                Storage::disk('public')->delete($row->attachment_path);
            }
            $attributes['attachment_path'] = $request->file('attachment')->store('assignments', 'public');
        }

        DB::table('assignments')->where('id', $assignment)->update($attributes);
        AuditService::record('updated', 'assignments');

        return redirect()->route('teacher.assignments.index')->with('success', 'تم تحديث الواجب.');
    }

    public function submissions(Request $request, int $assignment): View
    {
        $teacher = $this->teacher($request);
        $row = DB::table('assignments')->where('id', $assignment)->first();
        abort_unless($row && (int) $row->teacher_id === $teacher->id, 404);

        $students = Student::with('user')->where('classroom_id', $row->classroom_id)->where('status', 'active')->orderBy('student_number')->get();
        $submissions = DB::table('assignment_submissions')->where('assignment_id', $assignment)->get()->keyBy('student_id');

        return view('teacher.assignments.submissions', ['assignment' => $row, 'students' => $students, 'submissions' => $submissions]);
    }

    public function submissionsStore(Request $request, int $assignment): RedirectResponse
    {
        $teacher = $this->teacher($request);
        $row = DB::table('assignments')->where('id', $assignment)->first();
        abort_unless($row && (int) $row->teacher_id === $teacher->id, 404);

        $data = $request->validate([
            'submitted' => ['nullable', 'array'],
            'submitted.*' => ['nullable'],
            'scores' => ['nullable', 'array'],
            'scores.*' => ['nullable', 'numeric', 'min:0'],
        ]);

        DB::transaction(function () use ($data, $row, $request) {
            $studentIds = Student::where('classroom_id', $row->classroom_id)->pluck('id');

            foreach ($studentIds as $studentId) {
                $submitted = ! empty($data['submitted'][$studentId] ?? null);
                $score = $data['scores'][$studentId] ?? null;

                if ($score !== null) {
                    abort_if((float) $score > (float) $row->max_score, 422, 'الدرجة تتجاوز الدرجة الكلية للواجب.');
                }

                if (! $submitted && $score === null) {
                    continue;
                }

                $existing = DB::table('assignment_submissions')->where('assignment_id', $row->id)->where('student_id', $studentId)->first();

                DB::table('assignment_submissions')->updateOrInsert(
                    ['assignment_id' => $row->id, 'student_id' => $studentId],
                    [
                        'submitted_at' => $submitted ? ($existing->submitted_at ?? now()) : null,
                        'score' => $score,
                        'graded_by' => $score !== null ? $request->user()->id : null,
                        'updated_at' => now(),
                        'created_at' => $existing->created_at ?? now(),
                    ]
                );
            }
            AuditService::record('graded', 'assignments');
        });

        return back()->with('success', 'تم حفظ حالة التسليمات والدرجات.');
    }
}
