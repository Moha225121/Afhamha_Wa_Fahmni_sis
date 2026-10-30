<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SendAdminNotificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    public function rules(): array
    {
        // These character limits also fit FCM's byte limits, including emoji.
        return [
            'recipient_id' => ['required', 'integer', Rule::exists('users', 'id')->where('status', 'active')],
            'title' => ['required', 'string', 'max:60'],
            'message' => ['required', 'string', 'max:300'],
            'submission_id' => ['required', 'uuid'],
        ];
    }

    public function attributes(): array
    {
        return ['recipient_id' => 'المستلم', 'title' => 'العنوان', 'message' => 'الرسالة'];
    }
}
