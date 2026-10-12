<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class TeacherLessonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('lessons.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'classroom_id' => ['required', 'integer', 'exists:classrooms,id'],
            'subject_id' => ['required', 'integer', 'exists:subjects,id'],
            'unit_title' => ['nullable', 'string', 'max:255'],
            'title' => ['required', 'string', 'max:255'],
            'content' => ['nullable', 'string'],
            'status' => ['required', 'in:draft,published'],
            'published_at' => ['nullable', 'date'],
            'submission_key' => ['nullable', 'uuid'],
            'attachments' => ['nullable', 'array', 'max:10'],
            'attachments.*' => ['file', 'mimes:pdf,doc,docx,ppt,pptx,xls,xlsx,jpg,jpeg,png,webp,heic,heif,mp4', 'max:20480'],
        ];
    }

    public function messages(): array
    {
        return [
            'attachments.max' => 'يمكن إرفاق 10 ملفات كحد أقصى.',
            'attachments.*.mimes' => 'نوع أحد المرفقات غير مدعوم. استخدم PDF أو مستندًا أو صورة JPG أو PNG أو WebP أو HEIC أو HEIF أو MP4.',
            'attachments.*.max' => 'يجب ألا يتجاوز حجم كل مرفق 20 ميجابايت.',
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $attachments = $this->file('attachments', []);
            $totalBytes = collect(is_array($attachments) ? $attachments : [$attachments])
                ->filter()
                ->sum(fn ($file) => (int) $file->getSize());

            if ($totalBytes > 22 * 1024 * 1024) {
                $validator->errors()->add('attachments', 'يجب ألا يتجاوز مجموع المرفقات 22 ميبيبايت في الطلب الواحد.');
            }
        }];
    }
}
