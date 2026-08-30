<?php

namespace App\Http\Requests\Supervisor;

use App\Enums\AttendanceStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDailyAttendanceRequest extends FormRequest
{
    public function authorize(): bool { return $this->user()?->isSupervisor() ?? false; }
    public function rules(): array
    {
        return ['date'=>['required','date'],'classroom_id'=>['required','integer','exists:classrooms,id'],'records'=>['required','array','min:1'],'records.*.status'=>['required',Rule::enum(AttendanceStatus::class)],'records.*.arrival_time'=>['nullable','date_format:H:i'],'records.*.late_minutes'=>['nullable','integer','min:0','max:1440'],'records.*.excuse_reason'=>['nullable','string','max:2000'],'records.*.notes'=>['nullable','string','max:2000'],'records.*.excuse_document'=>['nullable','file','mimes:pdf,jpg,jpeg,png','max:5120']];
    }
}
