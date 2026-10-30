<?php

namespace App\Http\Requests;

use App\Rules\PushEndpoint;
use App\Services\ParentStudentSession;
use Illuminate\Foundation\Http\FormRequest;

class RegisterFcmTokenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->status === 'active' && ! $this->session()->has(ParentStudentSession::PARENT_ID);
    }

    public function rules(): array
    {
        return [
            'token' => ['required', 'string', 'min:20', 'max:4096', 'regex:/\A[A-Za-z0-9_:.-]+\z/'],
            'device_id' => ['required', 'uuid'],
            'endpoint' => ['required', 'url:https', 'max:2000', new PushEndpoint],
            'legacy_endpoints' => ['sometimes', 'array', 'max:5'],
            'legacy_endpoints.*' => ['required', 'url:https', 'max:2000', new PushEndpoint],
            'user_id' => ['prohibited'],
        ];
    }
}
