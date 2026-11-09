<?php

namespace App\Support;

use App\Models\Classroom;
use App\Models\Teacher;

class NotificationContext
{
    public static function academic(int $classroomId, ?int $teacherId = null): array
    {
        $classroom = Classroom::find($classroomId);
        $teacher = $teacherId ? Teacher::with('user')->find($teacherId) : null;

        return array_filter([
            'classroom_id' => $classroom?->id,
            'classroom_name' => $classroom ? trim($classroom->name.' — '.$classroom->section, ' —') : null,
            'teacher_id' => $teacher?->id,
            'teacher_name' => $teacher?->user?->name,
        ], fn ($value) => $value !== null && $value !== '');
    }

    public static function label(array $data): string
    {
        // Historical notifications have no context. Do not infer a sender from current assignments.
        return implode(' · ', array_filter([
            is_string($data['teacher_name'] ?? null) ? $data['teacher_name'] : '',
            is_string($data['classroom_name'] ?? null) ? $data['classroom_name'] : '',
        ]));
    }
}
