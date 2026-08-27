<?php

namespace App\Http\Requests;

use App\Models\ExamAnswer;
use App\Models\ExamAttempt;
use App\Models\ExamQuestion;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class SaveExamAnswerRequest extends FormRequest
{
    public function authorize(): bool
    {
        $attempt = $this->route('attempt');
        $question = $this->route('question');
        $student = $this->user()?->student;

        return ($this->user()?->isStudent() ?? false)
            && $attempt instanceof ExamAttempt
            && $question instanceof ExamQuestion
            && $student
            && $attempt->student_id === $student->id
            && $question->exam_id === $attempt->exam_id;
    }

    protected function failedAuthorization()
    {
        throw new NotFoundHttpException;
    }

    public function rules(): array
    {
        return ['answer' => ['nullable', 'string', 'max:10000']];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $question = $this->route('question');
            $attempt = $this->route('attempt');
            $student = $this->user()?->student;
            if (! $question instanceof ExamQuestion || ! $attempt instanceof ExamAttempt || ! $student
                || $attempt->student_id !== $student->id || $question->exam_id !== $attempt->exam_id) {
                return;
            }

            $response = ExamAnswer::query()->where('exam_attempt_id', $attempt->id)
                ->where('exam_question_id', $question->id)->first();
            if (! $response || ! in_array($response->question_type_snapshot, ['multiple_choice', 'true_false'], true)) {
                return;
            }

            $answer = $this->input('answer');
            $allowed = array_map('strval', array_keys($response->answerOptions()));
            if ($answer !== null && ! in_array((string) $answer, $allowed, true)) {
                $validator->errors()->add('answer', 'الخيار المحدد لا يتبع هذا السؤال.');
            }
        }];
    }
}
