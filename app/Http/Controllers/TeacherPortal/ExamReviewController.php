<?php

namespace App\Http\Controllers\TeacherPortal;

use App\Http\Controllers\Controller;
use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ExamReviewController extends Controller
{
    public function index(Request $request, Exam $exam): View
    {
        $this->authorizeExam($request, $exam);
        $attempts = $exam->attempts()->whereIn('status', ['pending_review', 'submitted'])
            ->with(['student.user', 'answers'])->orderByDesc('id')->paginate(15);
        $lockedForPublication = $this->lockedForPublication($exam);

        return view('teacher.exams.attempts', compact('exam', 'attempts', 'lockedForPublication'));
    }

    public function store(Request $request, Exam $exam, ExamAttempt $attempt): RedirectResponse
    {
        $this->authorizeExam($request, $exam);
        abort_unless($attempt->exam_id === $exam->id, 404);
        $data = $request->validate(['scores' => ['required', 'array'], 'scores.*' => ['required', 'numeric', 'min:0', 'decimal:0,2']]);

        DB::transaction(function () use ($request, $exam, $attempt, $data): void {
            // Publication/import lock the period first. Share that order so review cannot race
            // an administrator moving the period out of draft.
            $periodId = $exam->exam_period_id;
            $period = $periodId ? DB::table('exam_periods')->where('id', $periodId)->lockForUpdate()->first() : null;
            $exam = Exam::query()->lockForUpdate()->findOrFail($exam->id);
            $this->authorizeExam($request, $exam);
            abort_if($exam->exam_period_id != $periodId, 409, 'تغير ارتباط الاختبار بفترة النتائج. أعد تحميل الصفحة.');
            abort_if($exam->legacy_results_published || ($periodId && (! $period || $period->status !== 'draft')), 422,
                'أعد فترة النتائج إلى المسودة قبل تعديل التصحيح.');
            $attempt = ExamAttempt::query()->lockForUpdate()->findOrFail($attempt->id);
            abort_unless(in_array($attempt->status, ['pending_review', 'submitted'], true), 422, 'لم يسلم الطالب المحاولة بعد.');
            $answers = $attempt->answers()->lockForUpdate()->get();
            $manual = $answers->whereNotIn('question_type_snapshot', ['multiple_choice', 'true_false']);
            $expected = $manual->pluck('id')->map(fn ($id) => (string) $id)->sort()->values()->all();
            $provided = collect(array_keys($data['scores']))->map(fn ($id) => (string) $id)->sort()->values()->all();
            if ($expected === [] || $expected !== $provided) {
                throw ValidationException::withMessages(['scores' => 'أدخل درجات الأسئلة اليدوية لهذه المحاولة فقط، كاملة.']);
            }
            foreach ($manual as $answer) {
                $score = (float) $data['scores'][$answer->id];
                if ($score > (float) $answer->max_score) {
                    throw ValidationException::withMessages(['scores.'.$answer->id => 'الدرجة تتجاوز الدرجة القصوى للسؤال.']);
                }
                $answer->update(['awarded_score' => $score]);
            }
            $before = $attempt->getAttributes();
            $score = round((float) $answers->sum('awarded_score'), 2);
            $attempt->update(['score' => $score, 'percentage' => (float) $attempt->maximum_score > 0
                ? round($score * 100 / (float) $attempt->maximum_score, 2) : 0,
                'status' => 'submitted', 'graded_at' => now()]);
            AuditService::record('exam_attempt_graded', 'exams', $attempt, $before);
        });

        return back()->with('success', 'تم حفظ التصحيح. تعتمد الإدارة النتيجة وتنشرها من إدارة النتائج.');
    }

    private function authorizeExam(Request $request, Exam $exam): void
    {
        abort_unless($request->user()->teacher && (int) $exam->teacher_id === $request->user()->teacher->id, 404);
    }

    private function lockedForPublication(Exam $exam): bool
    {
        return (bool) $exam->legacy_results_published || ($exam->exam_period_id
            && DB::table('exam_periods')->where('id', $exam->exam_period_id)->where('status', '!=', 'draft')->exists());
    }
}
