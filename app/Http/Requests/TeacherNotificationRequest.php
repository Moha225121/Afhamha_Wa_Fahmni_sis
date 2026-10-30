<?php

namespace App\Http\Requests;

use App\Rules\NoNullBytes;
use App\Services\TeacherNotificationAudience;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TeacherNotificationRequest extends FormRequest
{
    protected $redirectRoute = 'teacher.notifications.index';

    public static function filterRules(): array
    {
        return [
            'audience' => ['sometimes', 'required', Rule::in(array_keys(TeacherNotificationAudience::LABELS))],
            'subject_id' => ['nullable', 'integer', 'min:1'],
            'classroom_id' => ['nullable', 'integer', 'min:1'],
            'recipient_id' => ['nullable', 'integer', 'min:1'],
            'selection_mode' => ['nullable', Rule::in(['all', 'grade', 'branches', 'people'])],
            'classroom_ids' => ['sometimes', 'array', 'min:1', 'max:'.TeacherNotificationAudience::MAX_MESSAGING_RECIPIENTS],
            'classroom_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            'student_ids' => ['sometimes', 'array', 'min:1', 'max:'.TeacherNotificationAudience::MAX_MESSAGING_RECIPIENTS],
            'student_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            'guardian_ids' => ['sometimes', 'array', 'min:1', 'max:'.TeacherNotificationAudience::MAX_MESSAGING_RECIPIENTS],
            'guardian_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            'q' => ['nullable', 'string', 'max:100'],
            'teacher_id' => ['prohibited'], 'user_id' => ['prohibited'], 'recipient_count' => ['prohibited'],
        ];
    }

    public function authorize(): bool
    {
        return ($this->user()?->isTeacher() ?? false) && $this->user()->teacher?->status === 'active';
    }

    public function rules(): array
    {
        return self::filterRules() + [
            'title' => ['required', 'string', 'max:60', new NoNullBytes],
            'message' => ['required', 'string', new NoNullBytes],
        ];
    }

    public function messages(): array
    {
        return [
            'required' => 'حقل :attribute مطلوب.', 'string' => 'حقل :attribute يجب أن يكون نصاً.',
            'integer' => 'اختيار :attribute غير صالح.', 'min' => 'اختيار :attribute غير صالح.',
            'max.string' => 'حقل :attribute يجب ألا يتجاوز :max حرفاً.',
            'in' => 'اختيار :attribute غير صالح.', 'prohibited' => 'لا يُسمح بإرسال حقل :attribute.',
            'array' => 'اختيار :attribute غير صالح.', 'distinct' => 'لا تكرر الاسم أو الصف في القائمة.',
            'min.array' => 'اختر اسماً أو صفاً واحداً على الأقل.', 'max.array' => 'قائمة المستلمين أكبر من الحد المسموح به.',
        ];
    }

    public function attributes(): array
    {
        return [
            'audience' => 'الجمهور', 'subject_id' => 'المادة', 'classroom_id' => 'الصف', 'recipient_id' => 'المستلم',
            'title' => 'العنوان', 'message' => 'الرسالة', 'selection_mode' => 'طريقة اختيار المستلمين',
            'classroom_ids' => 'الصفوف', 'student_ids' => 'الطلاب', 'guardian_ids' => 'أولياء الأمور',
            'classroom_ids.*' => 'الصف', 'student_ids.*' => 'الطالب', 'guardian_ids.*' => 'ولي الأمر',
        ];
    }
}
