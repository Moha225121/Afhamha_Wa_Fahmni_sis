<?php

namespace App\Http\Requests;

class TeacherTutorMessageRequest extends TutorMessageRequest
{
    public function authorize(): bool
    {
        return ($this->user()?->isTeacher() ?? false)
            && $this->user()->teacher?->status === 'active';
    }
}
