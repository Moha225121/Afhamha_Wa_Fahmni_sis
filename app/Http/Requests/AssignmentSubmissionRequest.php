<?php

namespace App\Http\Requests;

use App\Models\Assignment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\File;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class AssignmentSubmissionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $assignment = $this->route('assignment');
        $student = $this->user()?->student;

        return ($this->user()?->isStudent() ?? false)
            && $assignment instanceof Assignment
            && $student
            && $assignment->classroom_id === $student->classroom_id
            && $assignment->status === 'published'
            && (! $assignment->published_at || $assignment->published_at->lte(now()));
    }

    protected function failedAuthorization()
    {
        throw new NotFoundHttpException;
    }

    public function rules(): array
    {
        $extensions = config('student_academic.private_files.allowed_extensions', []);

        return [
            'file' => [
                'required',
                File::types($extensions)->max((int) config('student_academic.private_files.max_kilobytes', 10 * 1024)),
                'extensions:'.implode(',', $extensions),
            ],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
