<?php

namespace App\Http\Controllers\TeacherPortal;

use App\Http\Controllers\Controller;
use App\Http\Controllers\TeacherPortal\Concerns\InteractsWithTeacherScope;
use App\Http\Requests\TeacherPortal\AssignmentRequest;
use App\Models\Assignment;
use App\Models\AssignmentAttachment;
use App\Models\AssignmentSubmission;
use App\Models\Classroom;
use App\Models\Student;
use App\Models\Subject;
use App\Services\AuditService;
use App\Services\LessonPublicationTime;
use App\Services\ParentNotificationService;
use App\Services\TeacherCreationIntent;
use App\Support\AssignmentDeadline;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
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
            // Filter to only assigned classrooms and subjects
            ->whereExists(function ($query) use ($teacher) {
                $query->selectRaw('1')
                    ->from('teacher_assignments')
                    ->whereColumn('teacher_assignments.classroom_id', 'assignments.classroom_id')
                    ->whereColumn('teacher_assignments.subject_id', 'assignments.subject_id')
                    ->where('teacher_assignments.teacher_id', $teacher->id);
            })
            ->when(! empty($filters['classroom_id']), fn ($query) => $query->where(fn ($q) => $q->where('assignments.classroom_id', $filters['classroom_id'])
                ->orWhereExists(fn ($pivot) => $pivot->selectRaw('1')->from('assignment_classroom')->whereColumn('assignment_classroom.assignment_id', 'assignments.id')->where('assignment_classroom.classroom_id', $filters['classroom_id']))))
            ->when(! empty($filters['due_date']), function ($query) use ($filters): void {
                $start = Carbon::parse($filters['due_date'], LessonPublicationTime::timezone())->startOfDay()->utc();
                $end = Carbon::parse($filters['due_date'], LessonPublicationTime::timezone())->addDay()->startOfDay()->utc();
                $query->where(fn ($date) => $date->whereDate('assignments.due_date', $filters['due_date'])
                    ->orWhere(fn ($exact) => $exact->whereNull('assignments.due_date')
                        ->where('assignments.due_at', '>=', $start)->where('assignments.due_at', '<', $end)));
            })
            ->when(! empty($filters['status']), function ($query) use ($filters) {
                if ($filters['status'] === 'cancelled') {
                    $query->where('assignments.status', 'cancelled');
                } elseif ($filters['status'] === 'completed') {
                    $query->where('assignments.status', '!=', 'cancelled')
                        ->whereRaw($this->assignmentCompletionExpression());
                } elseif ($filters['status'] === 'closed') {
                    $query->where('assignments.status', '!=', 'cancelled')
                        ->whereRaw('not '.$this->assignmentCompletionExpression());
                    $this->whereDeadlineState($query, closed: true);
                } else {
                    $query->whereIn('assignments.status', ['active', 'published'])
                        ->whereRaw('not '.$this->assignmentCompletionExpression());
                    $this->whereDeadlineState($query, closed: false);
                }
            })
            ->select('assignments.*', 'subjects.name as subject', 'classrooms.name as classroom')
            ->selectSub(function ($query) {
                $query->from('students')->selectRaw('count(*)')->where('students.status', 'active')->where(fn ($q) => $q->whereColumn('students.classroom_id', 'assignments.classroom_id')->orWhereExists(fn ($pivot) => $pivot->selectRaw('1')->from('assignment_classroom')->whereColumn('assignment_classroom.assignment_id', 'assignments.id')->whereColumn('assignment_classroom.classroom_id', 'students.classroom_id')));
            }, 'students_total')
            ->selectSub(function ($query) {
                $query->from('assignment_submissions')
                    ->join('students', 'students.id', '=', 'assignment_submissions.student_id')
                    ->selectRaw('count(distinct assignment_submissions.student_id)')
                    ->whereColumn('assignment_submissions.assignment_id', 'assignments.id')
                    ->where(fn ($q) => $q->whereColumn('students.classroom_id', 'assignments.classroom_id')->orWhereExists(fn ($pivot) => $pivot->selectRaw('1')->from('assignment_classroom')->whereColumn('assignment_classroom.assignment_id', 'assignments.id')->whereColumn('assignment_classroom.classroom_id', 'students.classroom_id')))
                    ->where('students.status', 'active')
                    ->whereNotNull('assignment_submissions.submitted_at');
            }, 'submissions_count')
            ->latest('assignments.due_date')
            ->paginate(20)
            ->withQueryString()
            ->through(function ($assignment) {
                $deadline = AssignmentDeadline::for(new Assignment([
                    'due_date' => $assignment->due_date,
                    'due_at' => $assignment->due_at,
                ]));
                $assignment->is_closed = $deadline !== null && now()->gte($deadline);
                $assignment->display_deadline = $deadline?->setTimezone(LessonPublicationTime::timezone())->format('Y-m-d H:i');

                return $assignment;
            });

        $assignmentSummary = ['active' => 0, 'closed' => 0, 'completed' => 0, 'cancelled' => 0];
        DB::table('assignments')
            ->where('teacher_id', $teacher->id)
            // Filter to only assigned classrooms and subjects
            ->whereExists(function ($query) use ($teacher) {
                $query->selectRaw('1')
                    ->from('teacher_assignments')
                    ->whereColumn('teacher_assignments.classroom_id', 'assignments.classroom_id')
                    ->whereColumn('teacher_assignments.subject_id', 'assignments.subject_id')
                    ->where('teacher_assignments.teacher_id', $teacher->id);
            })
            ->get(['id', 'classroom_id', 'status', 'due_date', 'due_at'])
            ->each(function ($assignment) use (&$assignmentSummary) {
                if ($assignment->status === 'cancelled') {
                    $assignmentSummary['cancelled']++;

                    return;
                }
                $classroomIds = DB::table('assignment_classroom')->where('assignment_id', $assignment->id)->pluck('classroom_id');
                if ($classroomIds->isEmpty()) {
                    $classroomIds = collect([$assignment->classroom_id]);
                }
                $studentsTotal = DB::table('students')->whereIn('classroom_id', $classroomIds)->where('status', 'active')->count();
                $submissions = DB::table('assignment_submissions')
                    ->join('students', 'students.id', '=', 'assignment_submissions.student_id')
                    ->where('assignment_submissions.assignment_id', $assignment->id)
                    ->whereIn('students.classroom_id', $classroomIds)
                    ->where('students.status', 'active')
                    ->whereNotNull('assignment_submissions.submitted_at')
                    ->distinct('assignment_submissions.student_id')
                    ->count('assignment_submissions.student_id');
                if ($studentsTotal > 0 && $submissions >= $studentsTotal) {
                    $assignmentSummary['completed']++;

                    return;
                }
                $deadline = AssignmentDeadline::for(new Assignment([
                    'due_date' => $assignment->due_date,
                    'due_at' => $assignment->due_at,
                ]));
                $isClosed = $deadline !== null && now()->gte($deadline);
                $assignmentSummary[$isClosed ? 'closed' : 'active']++;
            });
        $assignmentSummary = collect($assignmentSummary);

        $classrooms = Classroom::whereIn('id', $this->assignedClassroomIds($teacher))->orderBy('name')->get();

        return view('teacher.assignments.index', compact('rows', 'classrooms', 'filters', 'assignmentSummary'));
    }

    private function whereDeadlineState(Builder $query, bool $closed): void
    {
        AssignmentDeadline::whereState($query, $closed);
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
            'selectedClassroomIds' => $assignment ? DB::table('assignment_classroom')->where('assignment_id', $assignment->id)->pluck('classroom_id')->all() : [],
            'subjects' => Subject::whereIn('id', $pairs->pluck('subject_id')->unique())->get(),
            'pairs' => $pairs,
            'creationToken' => $assignment ? null : app(TeacherCreationIntent::class)->issue($teacher->id, 'assignments', $request->old('creation_token')),
        ]);
    }

    public function store(AssignmentRequest $request): RedirectResponse
    {
        $teacher = $this->teacher($request);
        $data = $request->validated();
        $classroomIds = $this->validatedClassroomIds($data, $teacher);

        app(TeacherCreationIntent::class)->once($data['creation_token'], $teacher->id, 'assignments', function () use ($data, $classroomIds, $teacher, $request): int {
            $path = null;
            $files = $this->uploadedFiles($request);
            if ($files !== []) {
                $path = $files[0]->store('assignments', 'local');
            }

            $assignmentId = DB::transaction(function () use ($data, $classroomIds, $teacher, $path): int {
                $assignmentId = DB::table('assignments')->insertGetId([
                    'title' => $data['title'],
                    'classroom_id' => $classroomIds[0],
                    'subject_id' => $data['subject_id'],
                    'teacher_id' => $teacher->id,
                    'description' => $data['description'] ?? null,
                    'instructions' => $data['description'] ?? null,
                    'due_date' => $data['due_date'],
                    'due_at' => AssignmentDeadline::fromDate($data['due_date']),
                    'max_score' => $data['max_score'],
                    'attachment_path' => $path,
                    'status' => 'published',
                    'published_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                DB::table('assignment_classroom')->insert(collect($classroomIds)->map(fn ($classroomId) => ['assignment_id' => $assignmentId, 'classroom_id' => $classroomId])->all());

                return $assignmentId;
            });
            $this->storeAttachments($files, $assignmentId);
            AuditService::record('created', 'assignments');
            $this->notifyAssignmentAvailability($classroomIds, $assignmentId, $data['title']);

            return $assignmentId;
        });

        return redirect()->route('teacher.assignments.index')->with('success', 'تم إنشاء الواجب.');
    }

    public function update(AssignmentRequest $request, int $assignment): RedirectResponse
    {
        $teacher = $this->teacher($request);
        $row = DB::table('assignments')->where('id', $assignment)->first();
        abort_unless($row && (int) $row->teacher_id === $teacher->id, 404);

        $data = $request->validated();
        $classroomIds = $this->validatedClassroomIds($data, $teacher);

        $existingDeadline = AssignmentDeadline::for(new Assignment(['due_date' => $row->due_date, 'due_at' => $row->due_at]));
        $sameDueDay = $existingDeadline?->setTimezone(LessonPublicationTime::timezone())->toDateString() === $data['due_date'];

        $attributes = [
            'title' => $data['title'],
            'subject_id' => $data['subject_id'],
            'description' => $data['description'] ?? null,
            'instructions' => $data['description'] ?? null,
            'due_date' => $data['due_date'],
            'due_at' => $sameDueDay ? $existingDeadline : AssignmentDeadline::fromDate($data['due_date']),
            'max_score' => $data['max_score'],
            'updated_at' => now(),
        ];

        $files = $this->uploadedFiles($request);
        if ($files !== []) {
            // New uploads supplement existing files; the old private file may still
            // be referenced by legacy assignments or attachment metadata.
            if (! $row->attachment_path) {
                $attributes['attachment_path'] = $files[0]->store('assignments', 'local');
            }
        }

        DB::transaction(function () use ($assignment, $attributes, $classroomIds): void {
            DB::table('assignments')->where('id', $assignment)->update($attributes + ['classroom_id' => $classroomIds[0]]);
            DB::table('assignment_classroom')->where('assignment_id', $assignment)->delete();
            DB::table('assignment_classroom')->insert(collect($classroomIds)->map(fn ($classroomId) => ['assignment_id' => $assignment, 'classroom_id' => $classroomId])->all());
        });
        $this->storeAttachments($files, $assignment);
        AuditService::record('updated', 'assignments');
        if (in_array($row->status, ['active', 'published'], true)
            && (! $row->published_at || Carbon::parse($row->published_at)->lte(now()))) {
            $this->notifyAssignmentAvailability($classroomIds, $assignment, $data['title']);
        }

        return redirect()->route('teacher.assignments.index')->with('success', 'تم تحديث الواجب.');
    }

    public function cancel(Request $request, int $assignment): RedirectResponse
    {
        $teacher = $this->teacher($request);
        $row = DB::table('assignments')->where('id', $assignment)->first();
        abort_unless($row && (int) $row->teacher_id === $teacher->id, 404);
        $deadline = AssignmentDeadline::for(new Assignment(['due_date' => $row->due_date, 'due_at' => $row->due_at]));
        abort_unless($deadline !== null && now()->gte($deadline), 422, 'لا يمكن إلغاء واجب قبل موعد تسليمه.');
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

    private function notifyAssignmentAvailability(array $classroomIds, int $assignmentId, string $title): void
    {
        $service = app(ParentNotificationService::class);
        $eventKey = 'assignment:'.$assignmentId.':available';
        $service->sendToClassrooms($classroomIds, [
            'title' => 'واجب جديد',
            'body' => 'نُشر واجب جديد: '.$title,
            'url' => route('parent.assignments'),
            'category' => 'assignment',
            'assignment_id' => $assignmentId,
        ], $eventKey);
        $service->sendToStudents($classroomIds, [
            'title' => 'واجب جديد',
            'body' => 'نُشر واجب جديد: '.$title,
            'category' => 'assignment',
            'assignment_id' => $assignmentId,
        ], $eventKey, route('student.assignments.show', $assignmentId));
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

    private function validatedClassroomIds(array $data, $teacher): array
    {
        $ids = array_values(array_unique(array_map('intval', $data['classroom_ids'] ?? [])));
        abort_if($ids === [], 422, 'اختر فصلًا واحدًا على الأقل.');
        foreach ($ids as $id) {
            abort_unless($this->ownsPair($teacher, $id, (int) $data['subject_id']), 403, 'أنت غير مكلف بهذا الفصل/المادة.');
        }

        return $ids;
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
        abort_unless($row && ((int) $row->teacher_id === (int) $teacher->id || config('nasbia.shared_assignment_grading', false))
            && $this->ownsPair($teacher, (int) $row->classroom_id, (int) $row->subject_id), 404);

        $classroomIds = DB::table('assignment_classroom')->where('assignment_id', $assignment)->pluck('classroom_id');
        if ($classroomIds->isEmpty()) {
            $classroomIds = collect([$row->classroom_id]);
        }
        $students = Student::with(['user', 'classroom'])->whereIn('classroom_id', $classroomIds)->where('status', 'active')->orderBy('student_number')->get();
        $submissions = AssignmentSubmission::with('fileAttachment')->where('assignment_id', $assignment)->get()->keyBy('student_id');

        return view('teacher.assignments.submissions', ['assignment' => $row, 'students' => $students, 'submissions' => $submissions]);
    }

    public function submissionsStore(Request $request, int $assignment): RedirectResponse
    {
        $teacher = $this->teacher($request);
        $row = DB::table('assignments')->where('id', $assignment)->first();
        abort_unless($row && ((int) $row->teacher_id === (int) $teacher->id || config('nasbia.shared_assignment_grading', false))
            && $this->ownsPair($teacher, (int) $row->classroom_id, (int) $row->subject_id), 404);

        $data = $request->validate([
            'submitted' => ['nullable', 'array'],
            'submitted.*' => ['nullable'],
            'scores' => ['nullable', 'array'],
            'scores.*' => ['nullable', 'numeric', 'min:0'],
        ]);

        DB::transaction(function () use ($data, $row, $request) {
            $classroomIds = DB::table('assignment_classroom')->where('assignment_id', $row->id)->pluck('classroom_id');
            if ($classroomIds->isEmpty()) {
                $classroomIds = collect([$row->classroom_id]);
            }
            $studentIds = Student::whereIn('classroom_id', $classroomIds)->where('status', 'active')->pluck('id');

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
