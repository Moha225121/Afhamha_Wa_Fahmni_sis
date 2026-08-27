<?php

namespace App\Http\Controllers\TeacherPortal;

use App\Http\Controllers\Controller;
use App\Http\Controllers\TeacherPortal\Concerns\InteractsWithTeacherScope;
use App\Http\Requests\TeacherPortal\ExamRequest;
use App\Models\Classroom;
use App\Models\Subject;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ExamController extends Controller
{
    use InteractsWithTeacherScope;

    public function index(Request $request): View
    {
        $teacher = $this->teacher($request);

        $rows = DB::table('exams')
            ->join('subjects', 'exams.subject_id', '=', 'subjects.id')
            ->join('classrooms', 'exams.classroom_id', '=', 'classrooms.id')
            ->where('exams.teacher_id', $teacher->id)
            ->select('exams.*', 'subjects.name as subject', 'classrooms.name as classroom')
            ->selectSub(function ($query) {
                $query->from('exam_questions')->selectRaw('count(*)')->whereColumn('exam_questions.exam_id', 'exams.id');
            }, 'questions_count')
            ->latest('exams.starts_at')
            ->paginate(20);

        return view('teacher.exams.index', compact('rows'));
    }

    public function create(Request $request): View
    {
        return $this->form($request);
    }

    public function edit(Request $request, int $exam): View
    {
        $teacher = $this->teacher($request);
        $examRow = DB::table('exams')->where('id', $exam)->first();
        abort_unless($examRow && (int) $examRow->teacher_id === $teacher->id, 404);

        $canEditBeforeSchedule = in_array($examRow->status, ['draft', 'scheduled'], true)
            && (
                $examRow->status === 'draft'
                || \Illuminate\Support\Carbon::parse($examRow->starts_at)->isFuture()
            );

        abort_unless($canEditBeforeSchedule, 403, 'لا يمكن تعديل اختبار بعد بدء اليوم المحدد له.');

        $questions = DB::table('exam_questions')->where('exam_id', $exam)->orderBy('order')->get();
        $choices = DB::table('exam_choices')->whereIn('exam_question_id', $questions->pluck('id'))->orderBy('order')->get()->groupBy('exam_question_id');

        $questions = $questions->map(function ($q) use ($choices) {
            $q->choices = ($choices[$q->id] ?? collect())->values();

            return $q;
        })->values();

        return $this->form($request, $examRow, $questions);
    }

    private function form(Request $request, ?object $exam = null, $questions = null): View
    {
        $teacher = $this->teacher($request);
        $classroomIds = $this->assignedClassroomIds($teacher);
        $pairs = $this->assignmentPairs($teacher);

        return view('teacher.exams.form', [
            'exam' => $exam,
            'questions' => $questions ?? collect(),
            'classrooms' => Classroom::whereIn('id', $classroomIds)->get(),
            'subjects' => Subject::whereIn('id', $pairs->pluck('subject_id')->unique())->get(),
            'pairs' => $pairs,
        ]);
    }

    public function store(ExamRequest $request): RedirectResponse
    {
        $teacher = $this->teacher($request);

        return $this->saveExam($request, $teacher, null);
    }

    public function update(ExamRequest $request, int $exam): RedirectResponse
    {
        $teacher = $this->teacher($request);
        $examRow = DB::table('exams')->where('id', $exam)->first();
        abort_unless($examRow && (int) $examRow->teacher_id === $teacher->id, 404);

        $canEditBeforeSchedule = in_array($examRow->status, ['draft', 'scheduled'], true)
            && (
                $examRow->status === 'draft'
                || \Illuminate\Support\Carbon::parse($examRow->starts_at)->isFuture()
            );

        abort_unless($canEditBeforeSchedule, 403, 'لا يمكن تعديل اختبار بعد بدء اليوم المحدد له.');

        return $this->saveExam($request, $teacher, $exam);
    }

    private function saveExam(ExamRequest $request, $teacher, ?int $examId): RedirectResponse
    {
        $data = $request->validated();

        abort_unless($this->ownsPair($teacher, (int) $data['classroom_id'], (int) $data['subject_id']), 403, 'أنت غير مكلف بهذا الصف/المادة.');

        $questions = collect($data['questions'] ?? [])->values();

        $normalizedStatus = in_array($data['status'] ?? 'draft', ['draft', 'scheduled', 'published'], true)
            ? ($data['status'] ?? 'draft')
            : 'draft';

        if (in_array($normalizedStatus, ['scheduled', 'published'], true)) {
            $this->assertPublishable($questions);
        }

        $totalScore = max($questions->sum('score'), 0.25);
        $startsAt = \Illuminate\Support\Carbon::parse($data['starts_at'])->format('Y-m-d H:i:s');

        DB::transaction(function () use ($data, $teacher, $questions, $totalScore, $startsAt, &$examId, $normalizedStatus) {
            $examAttributes = [
                'title' => $data['title'],
                'subject_id' => $data['subject_id'],
                'classroom_id' => $data['classroom_id'],
                'teacher_id' => $teacher->id,
                'starts_at' => $startsAt,
                'duration_minutes' => $data['duration_minutes'],
                'total_score' => $totalScore,
                'status' => $normalizedStatus,
                'updated_at' => now(),
            ];

            if ($examId) {
                DB::table('exams')->where('id', $examId)->update($examAttributes);
                $oldQuestionIds = DB::table('exam_questions')->where('exam_id', $examId)->pluck('id');
                DB::table('exam_choices')->whereIn('exam_question_id', $oldQuestionIds)->delete();
                DB::table('exam_questions')->where('exam_id', $examId)->delete();
            } else {
                $examId = DB::table('exams')->insertGetId($examAttributes + ['created_at' => now()]);
            }

            foreach ($questions as $index => $question) {
                $questionId = DB::table('exam_questions')->insertGetId([
                    'exam_id' => $examId,
                    'type' => $question['type'],
                    'question_text' => $question['text'],
                    'text' => $question['text'],
                    'score' => $question['score'],
                    'position' => $index,
                    'order' => $index,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                foreach (($question['choices'] ?? []) as $choiceIndex => $choice) {
                    if (($choice['text'] ?? '') === '') {
                        continue;
                    }
                    DB::table('exam_choices')->insert([
                        'exam_question_id' => $questionId,
                        'text' => $choice['text'],
                        'is_correct' => (bool) ($choice['is_correct'] ?? false),
                        'order' => $choiceIndex,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }

            AuditService::record($examId ? 'updated' : 'created', 'exams');
        });

        return redirect()->route('teacher.exams.index')->with('success', $normalizedStatus === 'scheduled' ? 'تم جدولة الاختبار.' : 'تم حفظ الاختبار كمسودة.');
    }

    private function assertPublishable($questions): void
    {
        if ($questions->isEmpty()) {
            throw ValidationException::withMessages(['questions' => 'أضف سؤالًا واحدًا على الأقل قبل النشر.']);
        }

        foreach ($questions as $i => $question) {
            if (in_array($question['type'], ['mcq', 'true_false'], true)) {
                $choices = collect($question['choices'] ?? [])->filter(fn ($c) => ($c['text'] ?? '') !== '');
                $correct = $choices->filter(fn ($c) => (bool) ($c['is_correct'] ?? false));

                if ($choices->count() < 2) {
                    throw ValidationException::withMessages(["questions.$i.choices" => 'يجب إضافة خيارين على الأقل.']);
                }
                if ($correct->count() !== 1) {
                    throw ValidationException::withMessages(["questions.$i.choices" => 'حدد إجابة صحيحة واحدة فقط لكل سؤال.']);
                }
            }
        }
    }

    public function status(Request $request, int $exam): RedirectResponse
    {
        $teacher = $this->teacher($request);
        $examRow = DB::table('exams')->where('id', $exam)->first();
        abort_unless($examRow && (int) $examRow->teacher_id === $teacher->id, 404);

        $data = $request->validate(['status' => ['required', Rule::in(['draft', 'scheduled', 'published', 'completed'])]]);

        $normalizedStatus = $data['status'] === 'published' ? 'published' : $data['status'];

        if (($normalizedStatus === 'scheduled' || $normalizedStatus === 'published') && in_array($examRow->status, ['draft', 'scheduled', 'published'], true)) {
            $questions = DB::table('exam_questions')->where('exam_id', $exam)->get();
            abort_if($questions->isEmpty(), 422, 'لا يمكن جدولة اختبار بدون أسئلة.');
        }

        DB::table('exams')->where('id', $exam)->update(['status' => $normalizedStatus, 'updated_at' => now()]);
        AuditService::record('status_changed', 'exams');

        return back()->with('success', 'تم تحديث حالة الاختبار.');
    }

    public function destroy(Request $request, int $exam): RedirectResponse
    {
        $teacher = $this->teacher($request);
        $examRow = DB::table('exams')->where('id', $exam)->first();
        abort_unless($examRow && (int) $examRow->teacher_id === $teacher->id, 404);

        DB::transaction(function () use ($exam) {
            $questionIds = DB::table('exam_questions')->where('exam_id', $exam)->pluck('id');
            DB::table('exam_choices')->whereIn('exam_question_id', $questionIds)->delete();
            DB::table('exam_questions')->where('exam_id', $exam)->delete();
            DB::table('exams')->where('id', $exam)->delete();
        });

        AuditService::record('deleted', 'exams');

        return redirect()->route('teacher.exams.index')->with('success', 'تم حذف الاختبار بنجاح.');
    }
}
