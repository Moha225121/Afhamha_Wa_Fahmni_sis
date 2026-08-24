<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class LibraryResourceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    public function rules(): array
    {
        return ['title' => ['required', 'string', 'max:255'], 'category' => ['nullable', 'string', 'max:100'], 'subject_id' => ['nullable', 'exists:subjects,id'], 'classroom_id' => ['nullable', 'exists:classrooms,id'], 'file' => ['required', 'file', 'mimes:pdf,doc,docx,ppt,pptx,xls,xlsx,jpg,jpeg,png,mp4', 'max:20480'], 'is_public' => ['nullable', 'boolean']];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if (! $this->boolean('is_public') && ! $this->filled('subject_id') && ! $this->filled('classroom_id')) {
                $validator->errors()->add('subject_id', 'يجب ربط المورد الخاص بمادة أو صف.');
            }
        }];
    }
}
