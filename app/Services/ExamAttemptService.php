<?php

namespace App\Services;

use App\Models\Exam;
use App\Models\ExamAnswer;
use App\Models\ExamAttempt;
use App\Models\ExamQuestion;
use App\Models\Student;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ExamAttemptService
{
    public function __construct(private readonly ExamAttemptPolicy $policy) {}

    public function start(Exam $exam, Student $student): ExamAttempt
    {
        return DB::transaction(function () use ($exam, $student): ExamAttempt {
            // A student row lock serializes two start requests from that student
            // without blocking every student starting the same exam.
            $lockedStudent = Student::query()->lockForUpdate()->findOrFail($student->id);
            $lockedExam = Exam::query()->findOrFail($exam->id);
            $scheduledEnd = $lockedExam->starts_at->copy()->addMinutes($lockedExam->duration_minutes);
            if ($lockedExam->status !== 'published' || $lockedExam->classroom_id !== $lockedStudent->classroom_id) {
                throw ValidationException::withMessages(['exam' => 'الاختبار غير متاح لهذا الطالب.']);
            }
            if (now()->lt($lockedExam->starts_at) || now()->gte($scheduledEnd)) {
                throw ValidationException::withMessages(['exam' => 'الاختبار خارج نافذة الوقت المتاحة.']);
            }
            $existingAttempts = ExamAttempt::query()
                ->where('exam_id', $lockedExam->id)
                ->where('student_id', $student->id)
                ->orderBy('attempt_number')
                ->lockForUpdate()
                ->get();

            $activeAttempt = $existingAttempts->firstWhere('status', 'in_progress');
            if ($activeAttempt) {
                return $activeAttempt;
            }

            if (! $this->policy->allowsAnotherAttempt($lockedExam, $student, $existingAttempts->count())) {
                return $existingAttempts->last() ?? throw ValidationException::withMessages([
                    'exam' => 'لا توجد محاولة متاحة لهذا الاختبار.',
                ]);
            }

            $questions = $this->validatedQuestions($lockedExam);
            $startedAt = now();
            $expiresAt = $startedAt->copy()->addMinutes($lockedExam->duration_minutes)->min($scheduledEnd);

            $attempt = ExamAttempt::query()->create([
                'exam_id' => $lockedExam->id,
                'student_id' => $student->id,
                'attempt_number' => ($existingAttempts->max('attempt_number') ?? 0) + 1,
                'started_at' => $startedAt,
                'expires_at' => $expiresAt,
                'status' => 'in_progress',
                'maximum_score' => $lockedExam->total_score,
            ]);

            foreach ($questions as $question) {
                ExamAnswer::query()->create([
                    'exam_attempt_id' => $attempt->id,
                    'exam_question_id' => $question->id,
                    'question_text_snapshot' => $question->question_text,
                    'question_type_snapshot' => $question->type,
                    'options_snapshot' => $question->options,
                    'correct_answer_snapshot' => $question->correct_answer,
                    'max_score' => $question->score,
                ]);
            }

            return $attempt;
        });
    }

    public function saveAnswer(ExamAttempt $attempt, ExamQuestion $question, ?string $answer): bool
    {
        return DB::transaction(function () use ($attempt, $question, $answer): bool {
            $lockedAttempt = ExamAttempt::query()->lockForUpdate()->findOrFail($attempt->id);

            if ($lockedAttempt->status !== 'in_progress') {
                return false;
            }

            if (now()->gte($lockedAttempt->expires_at)) {
                $this->finalizeLocked($lockedAttempt);

                return false;
            }

            $response = ExamAnswer::query()
                ->where('exam_attempt_id', $lockedAttempt->id)
                ->where('exam_question_id', $question->id)
                ->firstOrFail();
            $response->update(['answer' => $answer, 'answered_at' => now(), 'is_correct' => null, 'awarded_score' => null]);

            return true;
        });
    }

    public function finalize(ExamAttempt $attempt): ExamAttempt
    {
        return DB::transaction(function () use ($attempt): ExamAttempt {
            $lockedAttempt = ExamAttempt::query()->lockForUpdate()->findOrFail($attempt->id);
            if ($lockedAttempt->status === 'in_progress') {
                $this->finalizeLocked($lockedAttempt);
            }

            return $lockedAttempt->fresh();
        });
    }

    public function finalizeExpired(): int
    {
        $finalized = 0;
        ExamAttempt::query()->where('status', 'in_progress')->where('expires_at', '<=', now())
            ->select('id')->orderBy('id')->chunkById(100, function ($attempts) use (&$finalized): void {
                foreach ($attempts as $attempt) {
                    if ($this->finalizeExpiredAttempt($attempt->id)) {
                        $finalized++;
                    }
                }
            });

        return $finalized;
    }

    private function finalizeExpiredAttempt(int $attemptId): bool
    {
        return DB::transaction(function () use ($attemptId): bool {
            $attempt = ExamAttempt::query()->lockForUpdate()->findOrFail($attemptId);
            if ($attempt->status !== 'in_progress' || now()->lt($attempt->expires_at)) {
                return false;
            }

            $this->finalizeLocked($attempt);

            return true;
        });
    }

    private function validatedQuestions(Exam $exam): Collection
    {
        $questions = $exam->questions()->orderBy('position')->get();
        if ($questions->isEmpty()) {
            throw ValidationException::withMessages(['exam' => 'الاختبار غير مكتمل ولا يحتوي على أسئلة.']);
        }

        if (abs((float) $questions->sum('score') - (float) $exam->total_score) > 0.001) {
            throw ValidationException::withMessages(['exam' => 'مجموع درجات الأسئلة لا يطابق الدرجة الكلية للاختبار.']);
        }

        foreach ($questions->whereIn('type', ['multiple_choice', 'true_false']) as $question) {
            $allowed = array_map('strval', array_keys($question->answerOptions()));
            if ($question->correct_answer === null || ! in_array((string) $question->correct_answer, $allowed, true)) {
                throw ValidationException::withMessages(['exam' => 'أحد أسئلة الاختبار لا يملك إجابة صحيحة معتمدة.']);
            }
        }

        return $questions;
    }

    private function finalizeLocked(ExamAttempt $attempt): void
    {
        $attempt->load(['exam', 'answers']);
        $score = 0.0;
        $requiresManualReview = false;

        foreach ($attempt->answers as $answer) {
            $isObjective = in_array($answer->question_type_snapshot, ['multiple_choice', 'true_false'], true);
            $requiresManualReview = $requiresManualReview || ! $isObjective;
            $isCorrect = $isObjective
                && $answer->answer !== null
                && $answer->correct_answer_snapshot !== null
                && trim((string) $answer->answer) === trim((string) $answer->correct_answer_snapshot);
            $awardedScore = $isCorrect ? (float) $answer->max_score : 0.0;

            if ($isObjective) {
                $answer->update(['is_correct' => $isCorrect, 'awarded_score' => $awardedScore]);
            }

            $score += $awardedScore;
        }

        $maximumScore = (float) $attempt->maximum_score;
        $finalizedAt = now();
        $attempt->update([
            'status' => $requiresManualReview ? 'pending_review' : 'submitted',
            'submitted_at' => $finalizedAt,
            'graded_at' => $requiresManualReview ? null : $finalizedAt,
            // For pending review this is an internal objective subtotal only;
            // percentage and graded_at remain null and the UI does not publish it.
            'score' => $score,
            'percentage' => $requiresManualReview ? null : ($maximumScore > 0 ? round($score * 100 / $maximumScore, 2) : 0),
        ]);

        // The shared grades table has no source/attempt reference. The authoritative
        // automatic result therefore remains on this attempt until that shared
        // schema is approved for traceable automated-grade sources.
    }
}
