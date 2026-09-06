<?php

namespace App\Services;

use App\Models\Student;
use App\Models\User;
use App\Notifications\ParentPortalNotification;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

class PortalEventService
{
    public function notify(Student $student, string $event, array $payload, bool $includeStudent = false): void
    {
        $users = $student->guardians()->with('user')->get()->pluck('user')->filter(fn ($user) => $user?->isParent());
        if ($includeStudent && $student->user?->isStudent()) {
            $users->push($student->user);
        }
        foreach ($users->unique('id') as $user) {
            $data = $payload + ['student_id' => $student->id];
            if ($user->role === 'student') {
                $data['url'] = route('student.results');
            }
            $id = Uuid::uuid5(Uuid::NAMESPACE_URL, 'awf:'.$event.':'.$user->id)->toString();
            $inserted = DB::table('notifications')->insertOrIgnore(['id' => $id, 'type' => ParentPortalNotification::class, 'notifiable_type' => User::class, 'notifiable_id' => $user->id, 'data' => json_encode($data, JSON_UNESCAPED_UNICODE), 'created_at' => now(), 'updated_at' => now()]);
            if ($inserted) {
                DB::afterCommit(fn () => app(WebPushService::class)->send($user, $data));
            }
        }
    }
}
