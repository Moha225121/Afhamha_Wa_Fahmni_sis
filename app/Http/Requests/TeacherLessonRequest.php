<?php

namespace App\Http\Requests;

use App\Models\Lesson;
use App\Rules\LessonAttachmentSize;
use App\Services\LessonPublicationTime;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;

class TeacherLessonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('lessons.manage') ?? false;
    }

    protected function prepareForValidation(): void
    {
        // Optional lists may be absent or null; unsupported types must reach the array rule unchanged.
        foreach (['upload_ids', 'attachment_tokens', 'attachment_names'] as $field) {
            if (! $this->exists($field) || $this->input($field) === null) {
                $this->merge([$field => []]);
            }
        }
    }

    public function uploadIds(): array
    {
        $ids = $this->validated('upload_ids', []);
        if (! is_array($ids)) {
            throw ValidationException::withMessages(['upload_ids' => 'أرسل معرفات الرفع في مصفوفة صحيحة.']);
        }

        return $ids;
    }

    public function rules(): array
    {
        return [
            'classroom_id' => ['nullable', Rule::requiredIf(fn () => $this->input('branch_scope') !== 'all' && ! $this->filled('classroom_ids')), 'integer', 'exists:classrooms,id'],
            'classroom_ids' => ['nullable', Rule::requiredIf(fn () => $this->input('branch_scope') !== 'all' && ! $this->filled('classroom_id')), 'array', 'min:1', 'max:50'],
            'classroom_ids.*' => ['required', 'integer', 'distinct', 'exists:classrooms,id'],
            'grade_key' => ['nullable', Rule::requiredIf(fn () => $this->input('branch_scope') === 'all'), 'string', 'max:255'],
            'branch_scope' => ['nullable', 'in:all,selected'],
            'subject_id' => ['required', 'integer', 'exists:subjects,id'],
            'unit_title' => ['nullable', 'string', 'max:255'],
            'title' => ['required', 'string', 'max:255'],
            'content' => ['nullable', 'string'],
            'status' => ['required', 'in:draft,published'],
            'publish_mode' => ['nullable', 'in:now,scheduled,preserve'],
            'published_at' => [Rule::excludeIf(fn () => in_array($this->input('publish_mode'), ['now', 'preserve'], true)), Rule::requiredIf(fn () => $this->input('status') === 'published' && $this->input('publish_mode') === 'scheduled'), 'nullable', 'date'],
            'submission_key' => ['nullable', 'uuid'],
            'attachments' => ['nullable', 'array', 'max:10'],
            'attachments.*' => ['file', 'mimes:'.config('lesson_uploads.mimes'), new LessonAttachmentSize],
            'upload_ids' => ['nullable', 'array', 'max:10'],
            'upload_ids.*' => ['required', 'uuid', 'distinct'],
            'attachment_tokens' => ['nullable', 'array', 'max:10'],
            'attachment_tokens.*' => ['required', 'uuid', 'distinct'],
            'attachment_names' => ['nullable', 'array', 'max:10'],
            'attachment_names.*' => ['required', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'classroom_ids.required' => 'اختر شعبة واحدة على الأقل للدرس.',
            'grade_key.required' => 'اختر الصف أولًا.',
            'published_at.required' => 'حدد موعد النشر أو اختر النشر الآن.',
            'published_at.date' => 'أدخل تاريخًا ووقتًا صحيحين للنشر.',
            'attachments.max' => 'يمكن إرفاق 10 ملفات كحد أقصى.',
            'attachments.*.mimes' => 'نوع أحد المرفقات غير مدعوم. استخدم PDF أو مستندًا أو صورة JPG أو PNG أو WebP أو HEIC أو HEIF أو MP4.',
            'attachments.*.max' => 'يجب ألا يتجاوز حجم كل مرفق 512 MiB.',
            'upload_ids.array' => 'أرسل معرفات الرفع في مصفوفة صحيحة.',
            'upload_ids.*.uuid' => 'معرف أحد المرفقات غير صالح.',
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($this->isMethod('post') && is_string($this->input('submission_key')) && Str::isUuid($this->input('submission_key'))
                && Lesson::where('submission_key', $this->input('submission_key'))->where('teacher_id', $this->user()->teacher?->id)->exists()) {
                return;
            }
            if ($this->input('publish_mode') === 'preserve') {
                $lesson = $this->route('lesson');
                if (! $lesson instanceof Lesson || $lesson->status !== 'published' || ! $lesson->published_at) {
                    $validator->errors()->add('publish_mode', 'اختر النشر الآن أو موعدًا لاحقًا.');
                }
            }
            if ($this->input('status') === 'published' && $this->input('publish_mode') === 'scheduled'
                && $this->filled('published_at') && ! $validator->errors()->has('published_at')) {
                if (LessonPublicationTime::parse($this->input('published_at'))->lte(now())) {
                    $validator->errors()->add('published_at', 'اختر موعدًا لاحقًا أو اختر النشر الآن.');
                }
            }
            $attachments = $this->file('attachments', []);
            $totalBytes = collect(is_array($attachments) ? $attachments : [$attachments])
                ->filter()
                ->sum(fn ($file) => (int) $file->getSize());

            $ids = $this->input('upload_ids', []);
            if (is_array($ids) && ! $validator->errors()->has('upload_ids') && ! $validator->errors()->has('upload_ids.*')) {
                foreach ($ids as $id) {
                    if (! is_string($id) || ! Str::isUuid($id)) {
                        continue;
                    }
                    $upload = DB::table('lesson_uploads')->where('id', $id)->where('user_id', $this->user()->id)->where('status', 'ready')->where('expires_at', '>', now())->first();
                    if (! $upload) {
                        $validator->errors()->add('upload_ids', 'أحد المرفقات لم يكتمل أو انتهت صلاحيته. أعد رفعه.');
                    } else {
                        if ($upload->size > LessonAttachmentSize::maximumForName($upload->name)) {
                            $validator->errors()->add('upload_ids', LessonAttachmentSize::messageForName($upload->name));
                        }
                        $totalBytes += $upload->size;
                    }
                }
            }
            // Include old staging tokens while pages opened before deployment are still active.
            $tokens = $this->input('attachment_tokens', []);
            if (is_array($tokens)) {
                foreach ($tokens as $token) {
                    if (! is_string($token) || ! Str::isUuid($token)) {
                        continue;
                    }
                    foreach (Storage::disk('local')->files('lesson-staging/'.$this->user()->id) as $path) {
                        if (str_starts_with(basename($path), $token.'.')) {
                            $totalBytes += Storage::disk('local')->size($path);
                        }
                    }
                }
            }
            if (count((array) $attachments) + count(is_array($ids) ? $ids : []) + count(is_array($tokens) ? $tokens : []) > 10) {
                $validator->errors()->add('attachments', 'يمكن إرفاق 10 ملفات كحد أقصى.');
            }

            if ($totalBytes > config('lesson_uploads.total_bytes')) {
                $validator->errors()->add('attachments', 'يجب ألا يتجاوز مجموع المرفقات 1100 ميبيبايت في الطلب الواحد.');
            }
        }];
    }
}
