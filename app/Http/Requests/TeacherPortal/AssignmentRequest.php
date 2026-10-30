<?php

namespace App\Http\Requests\TeacherPortal;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class AssignmentRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        // Retain compatibility with the existing single-class form/API.
        if (! $this->has('classroom_ids') && $this->filled('classroom_id')) {
            $this->merge(['classroom_ids' => [$this->input('classroom_id')]]);
        }
    }

    public function authorize(): bool
    {
        return $this->user()?->isTeacher() && ($this->user()?->hasPermission('homework.manage') ?? false);
    }

    public function rules(): array
    {
        return [
            'creation_token' => [Rule::requiredIf($this->routeIs('teacher.assignments.store')), 'nullable', 'uuid'],
            'title' => ['required', 'string', 'max:255'],
            'classroom_ids' => ['required', 'array', 'min:1', 'max:50'],
            'classroom_ids.*' => ['required', 'integer', 'distinct', 'exists:classrooms,id'],
            'subject_id' => ['required', 'integer', 'exists:subjects,id'],
            'due_date' => ['required', 'date', 'date_format:Y-m-d'],
            'max_score' => ['required', 'numeric', 'min:0.5'],
            'description' => ['nullable', 'string'],
            'attachment' => ['nullable', 'file', 'mimes:pdf,doc,docx,ppt,pptx,jpg,jpeg,png,webp,heic,heif', 'max:20480'],
            'attachments' => ['nullable', 'array', 'max:10'],
            'attachments.*' => ['file', 'mimes:pdf,doc,docx,ppt,pptx,jpg,jpeg,png,webp,heic,heif', 'max:20480'],
        ];
    }

    public function messages(): array
    {
        return [
            'classroom_ids.required' => 'اختر فصلًا واحدًا على الأقل.',
            'classroom_ids.min' => 'اختر فصلًا واحدًا على الأقل.',
            'attachment.mimes' => 'نوع المرفق غير مدعوم. استخدم PDF أو مستندًا أو صورة JPG أو PNG أو WebP أو HEIC أو HEIF.',
            'attachment.max' => 'يجب ألا يتجاوز حجم المرفق 20 ميجابايت.',
            'attachments.max' => 'يمكن إرفاق 10 ملفات كحد أقصى.',
            'attachments.*.mimes' => 'نوع أحد المرفقات غير مدعوم. استخدم PDF أو مستندًا أو صورة JPG أو PNG أو WebP أو HEIC أو HEIF.',
            'attachments.*.max' => 'يجب ألا يتجاوز حجم كل مرفق 20 ميجابايت.',
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $files = array_merge(
                (array) $this->file('attachments', []),
                $this->file('attachment') ? [$this->file('attachment')] : [],
            );
            $totalBytes = collect($files)->filter()->sum(fn ($file) => (int) $file->getSize());

            if ($totalBytes > 22 * 1024 * 1024) {
                $validator->errors()->add('attachments', 'يجب ألا يتجاوز مجموع المرفقات 22 ميبيبايت في الطلب الواحد.');
            }
        }];
    }
}
