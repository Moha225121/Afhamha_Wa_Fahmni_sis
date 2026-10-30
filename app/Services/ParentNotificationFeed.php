<?php

namespace App\Services;

use App\Models\Student;
use App\Models\User;

class ParentNotificationFeed
{
    public function forStudent(User $parent, ?Student $student)
    {
        return app(ParentAnnouncementNotificationService::class)->queryFor($parent)->where(function ($visible) use ($student): void {
            $visible->where(function ($general): void {
                $general->whereNull('data->student_id')->whereNull('data->student_ids')
                    ->whereNull('data->conversation_id')
                    ->where(fn ($category) => $category->whereNull('data->category')->orWhereNotIn('data->category', ['teacher_notification', 'conversation_message', 'message']));
            });
            if ($student) {
                $visible->orWhere(fn ($child) => app(ParentMessageScope::class)->forStudent($child, (int) $student->id));
            }
        });
    }
}
