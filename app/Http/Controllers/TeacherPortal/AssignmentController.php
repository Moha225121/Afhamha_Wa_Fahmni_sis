<?php

namespace App\Http\Controllers\TeacherPortal;

use App\Http\Controllers\Controller;
use App\Http\Controllers\TeacherPortal\Concerns\InteractsWithTeacherScope;
use App\Http\Requests\TeacherPortal\AssignmentRequest;
use App\Models\Classroom;
use App\Models\AssignmentAttachment;
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
        $filters = $request->validate([
            'classroom_id' => ['nullable', 'integer', 'exists:classrooms,id'],
            'due_date' => ['nullable', 'date', 'date_format:Y-m-d'],
            'status' => ['nullable', 'in:active,closed,completed,cancelled'],
        ]);

        $rows = DB::table('assignments')
            ->join('subjects', 'assignments.subject_id', '=', 'subjects.id')
            ->join('classrooms', 'assignments.classroom_id', '=', 'classrooms.id')
            ->where('assignments.teacher_id', $teacher->id)
            ->when(! empty($filters['classroom_id']), fn ($query) => $query->where('assignments.classroom_id', $filters['classroom_id']))
            ->when(! empty($filters['due_date']), fn ($query) => $query->whereDate('assignments.due_date', $filters['due_date']))
            ->when(! empty($filters['status']), function ($query) use ($filters) {
                if ($filters['status'] === 'cancelled') {
                    $query->where('assignments.status', 'cancelled');
                } elseif ($filters['status'] === 'completed') {
                    $query->where('assignments.status', '!=', 'cancelled')
                        ->whereRaw('(select count(distinct assignment_submissions.student_id) from assignment_submissions inner join students on students.id = assignment_submissions.student_id where assignment_submissions.assignment_id = assignments.id and assignment_submissions.submitted_at is not null and students.classroom_id = assignments.classroom_id and students.status = \'active\') >= (select count(*) from students where students.classroom_id = assignments.classroom_id and students.status = \'active\')');
                } elseif ($filters['status'] === 'closed') {
                    $query->where('assignments.status', '!=', 'cancelled')->whereDate('assignments.due_date', '<=', today());
                } else {
                    $query->where('assignments.status', '!=', 'cancelled')->whereDate('assignments.due_date', '>', today());
                }
            })
            ->select('assignments.*', 'subjects.name as subject', 'classrooms.name as classroom')
            ->selectSub(function ($query) {
                $query->from('students')->selectRaw('count(*)')->whereColumn('students.classroom_id', 'assignments.classroom_id')->where('students.status', 'active');
            }, 'students_total')
            ->selectSub(function ($query) {
                $query->from('assignment_submissions')
                    ->join('students', 'students.id', '=', 'assignment_submissions.student_id')
                    ->selectRaw('count(distinct assignment_submissions.student_id)')
                    ->whereColumn('assignment_submissions.assignment_id', 'assignments.id')
                    ->whereColumn('students.classroom_id', 'assignments.classroom_id')
                    ->where('students.status', 'active')
                    ->whereNotNull('assignment_submissions.submitted_at');
            }, 'submissions_count')
            ->latest('assignments.due_date')
            ->paginate(20)
            ->withQueryString();

        $assignmentSummary = ['active' => 0, 'closed' => 0, 'completed' => 0, 'cancelled' => 0];
        DB::table('assignments')->where('teacher_id', $teacher->id)->get(['id', 'classroom_id', 'status', 'due_date'])->each(function ($assignment) use (&$assignmentSummary) {
            if ($assignment->status === 'cancelled') {
                $assignmentSummary['cancelled']++;
                return;
            }
            $studentsTotal = DB::table('students')->where('classroom_id', $assignment->classroom_id)->where('status', 'active')->count();
            $submissions = DB::table('assignment_submissions')
                ->join('students', 'students.id', '=', 'assignment_submissions.student_id')
                ->where('assignment_submissions.assignment_id', $assignment->id)
                ->where('students.classroom_id', $assignment->classroom_id)
                ->where('students.status', 'active')
                ->whereNotNull('assignment_submissions.submitted_at')
                ->distinct('assignment_submissions.student_id')
                ->count('assignment_submissions.student_id');
            if ($studentsTotal > 0 && $submissions >= $studentsTotal) {
                $assignmentSummary['completed']++;
                return;
            }
            $isClosed = $assignment->due_date && \Illuminate\Support\Carbon::parse($assignment->due_date)->startOfDay()->lte(today());
            $assignmentSummary[$isClosed ? 'closed' : 'active']++;
        });
        $assignmentSummary = collect($assignmentSummary);

        $classrooms = Classroom::whereIn('id', $this->assignedClassroomIds($teacher))->orderBy('name')->get();

        return view('teacher.assignments.index', compact('rows', 'classrooms', 'filters', 'assignmentSummary'));
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

        $path = null;
        $files = $this->uploadedFiles($request);
        if ($files !== []) {
            $path = $files[0]->store('assignments', 'local');
        }

        $assignmentId = DB::table('assignments')->insertGetId([
            'title' => $data['title'],
            'classroom_id' => $data['classroom_id'],
            'subject_id' => $data['subject_id'],
            'teacher_id' => $teacher->id,
            'description' => $data['description'] ?? null,
            'instructions' => $data['description'] ?? null,
            'due_date' => $data['due_date'],
            'due_at' => $data['due_date'],
            'max_score' => $data['max_score'],
            'attachment_path' => $path,
            'status' => 'active',
            'published_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->storeAttachments($files, $assignmentId);
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
            'instructions' => $data['description'] ?? null,
            'due_date' => $data['due_date'],
            'due_at' => $data['due_date'],
            'max_score' => $data['max_score'],
            'updated_at' => now(),
        ];

        $files = $this->uploadedFiles($request);
        if ($files !== []) {
            if ($row->attachment_path) {
                Storage::disk('local')->delete($row->attachment_path);
            }
            $attributes['attachment_path'] = $files[0]->store('assignments', 'local');
        }

        DB::table('assignments')->where('id', $assignment)->update($attributes);
        $this->storeAttachments($files, $assignment);
        AuditService::record('updated', 'assignments');

        return redirect()->route('teacher.assignments.index')->with('success', 'تم تحديث الواجب.');
    }

    public function cancel(Request $request, int $assignment): RedirectResponse
    {
        $teacher = $this->teacher($request);
        $row = DB::table('assignments')->where('id', $assignment)->first();
        abort_unless($row && (int) $row->teacher_id === $teacher->id, 404);
        abort_unless($row->due_at && \Illuminate\Support\Carbon::parse($row->due_at)->isPast(), 422, 'لا يمكن إلغاء واجب قبل موعد تسليمه.');
        DB::table('assignments')->where('id', $assignment)->update(['status' => 'cancelled', 'updated_at' => now()]);
        AuditService::record('cancelled', 'assignments');

        return back()->with('success', 'تم إلغاء الواجب وإيقاف مشاركته.');
    }

    public function destroy(Request $request, int $assignment): RedirectResponse
    {
        $teacher = $this->teacher($request);
        $row = DB::table('assignments')->where('id', $assignment)->first();
        abort_unless($row && (int) $row->teacher_id === $teacher->id, 404);

        $attachments = AssignmentAttachment::where('assignment_id', $assignment)->get();
        foreach ($attachments as $attachment) {
            Storage::disk($attachment->disk ?: 'local')->delete($attachment->path ?: $attachment->file_path);
        }
        DB::transaction(function () use ($assignment): void {
            AssignmentAttachment::where('assignment_id', $assignment)->delete();
            DB::table('assignment_submissions')->where('assignment_id', $assignment)->delete();
            DB::table('assignments')->where('id', $assignment)->delete();
        });
        AuditService::record('deleted', 'assignments');

        return redirect()->route('teacher.assignments.index')->with('success', 'تم حذف الواجب.');
    }

    private function uploadedFiles(Request $request): array
    {
        $files = $request->file('attachments', []);
        if (! is_array($files)) {
            $files = [$files];
        }
        if ($request->hasFile('attachment')) {
            $files[] = $request->file('attachment');
        }

        return array_values(array_filter($files));
    }

    private function storeAttachments(array $files, int $assignmentId): void
    {
        foreach ($files as $index => $file) {
            $path = $file->store("assignment-attachments/{$assignmentId}", 'local');
            AssignmentAttachment::create([
                'assignment_id' => $assignmentId,
                'disk' => 'local',
                'file_path' => $path,
                'path' => $path,
                'original_name' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType(),
                'size' => $file->getSize(),
                'file_size' => $file->getSize(),
                'sort_order' => $index,
            ]);
        }
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
