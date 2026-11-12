<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use App\Support\DisplayTimezone;
use Illuminate\Support\Carbon;

class AnnouncementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    public function rules(): array
    {
        return ['title' => ['required', 'string', 'max:255'], 'content' => ['required', 'string'], 'audience' => ['required', Rule::in(['all', 'students', 'teachers', 'parents', 'supervisors', 'staff', 'classroom'])], 'classroom_id' => ['nullable', 'required_if:audience,classroom', 'exists:classrooms,id'], 'published_at' => ['nullable', 'date'], 'expires_at' => ['nullable', 'date', 'after:published_at'], 'status' => ['required', Rule::in(['draft', 'published', 'archived'])], 'schedule_timezone' => ['nullable', Rule::in([DisplayTimezone::name()])]];
    }

    public function announcementAttributes(): array
    {
        $data = $this->validated();
        // Browser datetime-local values carry no offset. The form explicitly
        // declares the school zone; older API requests retain their UTC contract.
        // Convert after validation so failed forms retain the original local inputs.
        $zone = $data['schedule_timezone'] ?? config('app.timezone');
        unset($data['schedule_timezone']);
        foreach (['published_at', 'expires_at'] as $field) {
            if (! empty($data[$field])) {
                $data[$field] = Carbon::parse($data[$field], $zone)->utc()->format('Y-m-d H:i:s');
            }
        }

        return $data;
    }
}
