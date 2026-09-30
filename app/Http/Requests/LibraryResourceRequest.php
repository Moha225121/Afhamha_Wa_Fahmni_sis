<?php

namespace App\Http\Requests;

use App\Services\LibraryBookNaming;
use App\Services\LibraryUploadService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;

class LibraryResourceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    public function rules(): array
    {
        $fileRules = ['required', 'file', 'mimes:'.implode(',', config('library.extensions'))];
        if ((int) config('library.max_upload_mb') > 0) {
            $fileRules[] = 'max:'.((int) config('library.max_upload_mb') * 1024);
        }

        return [
            'title' => ['nullable', 'string', 'max:255'], 'category' => ['nullable', 'string', 'max:100'],
            'subject_id' => ['nullable', 'exists:subjects,id'], 'classroom_id' => ['nullable', 'exists:classrooms,id'],
            'subject_name' => ['nullable', 'string', 'max:100'], 'audience' => ['nullable', Rule::in(['all', 'teachers'])],
            'grade_levels' => ['nullable', 'array', 'max:12'], 'grade_levels.*' => ['integer', 'between:1,12', 'distinct'],
            'book_type' => ['nullable', Rule::in(array_keys(config('library.book_types')))],
            'education_stage' => ['nullable', Rule::in(['أساسي', 'ثانوي'])],
            'track' => ['nullable', Rule::in(['علمي', 'أدبي', 'مشترك'])], 'term' => ['nullable', 'string', 'max:100'],
            'file' => $fileRules, 'is_public' => ['nullable', 'boolean'], 'upload_id' => ['nullable', 'uuid'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->hasFile('file') && $this->filled('upload_id')) {
            throw ValidationException::withMessages(['file' => 'أرسل ملفًا مباشرًا أو معرّف رفع مكتملًا، وليس كليهما.']);
        }
        if ($this->user()?->isAdmin() && ! $this->hasFile('file') && is_string($this->input('upload_id')) && Str::isUuid($this->input('upload_id'))) {
            $this->files->set('file', app(LibraryUploadService::class)->file($this->input('upload_id'), $this->user()->id));
            $this->convertedFiles = null;
        }
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $metadata = app(LibraryBookNaming::class)->metadata($this->file('file')->getClientOriginalName(), $validator->validated());
            if (! $this->boolean('is_public') && ! $this->filled('subject_id') && ! $this->filled('classroom_id') && $metadata['audience'] !== 'teachers') {
                $validator->errors()->add('subject_id', 'Choose a subject or classroom for private resources.');
            }
        }];
    }
}
