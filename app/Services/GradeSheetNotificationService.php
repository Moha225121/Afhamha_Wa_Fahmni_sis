<?php

namespace App\Services;

use App\Jobs\SendGradeSheetPush;
use App\Models\Student;
use App\Models\Teacher;
use App\Notifications\ParentPortalNotification;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class GradeSheetNotificationService
{
    /** Called inside the grade transaction so grades and both inboxes commit together. */
    public function publish(Teacher $teacher, int $classroomId, array $columns, array $scores, array $previousColumns, array $previousScores): void
    {
        if (! config('nasbia.teacher_sheet_notifications', false)) {
            return;
        }

        $previousColumns = collect($previousColumns)->keyBy('key');
        $changedStudents = [];
        foreach ($columns as $column) {
            $key = $column['key'];
            $oldColumn = $previousColumns->get($key);
            foreach ($scores[$key] ?? [] as $studentId => $score) {
                $oldScore = $previousScores[$key][$studentId] ?? null;
                if (! is_numeric($score) || ! is_finite((float) $score) || $score < 0 || $score > $column['max_score']) {
                    continue;
                }
                if (! is_numeric($oldScore) || (float) $score !== (float) $oldScore
                    || ! $oldColumn || $oldColumn['title'] !== $column['title']
                    || (float) ($oldColumn['max_score'] ?? 100) !== (float) $column['max_score']) {
                    $changedStudents[(int) $studentId] = true;
                }
            }
        }
        if (! $changedStudents) {
            return;
        }

        $students = Student::where('classroom_id', $classroomId)->where('status', 'active')
            ->whereHas('user', fn ($users) => $users->where('role', 'student')->where('status', 'active'))
            ->whereIn('id', array_keys($changedStudents))->with(['user', 'guardians.user'])->get();
        foreach ($students as $student) {
            $grades = [];
            foreach ($columns as $column) {
                $score = $scores[$column['key']][$student->id] ?? null;
                if (is_numeric($score) && is_finite((float) $score) && $score >= 0 && $score <= $column['max_score']) {
                    $grades[] = ['key' => $column['key'], 'title' => $column['title'],
                        'score' => (float) $score, 'maximum_score' => (float) $column['max_score']];
                }
            }
            if (! $grades) {
                continue;
            }
            $recipients = $student->guardians->where('status', 'active')->pluck('user')
                ->filter(fn ($user) => $user?->isParent());
            if ($student->user?->isStudent()) {
                $recipients->push($student->user);
            }
            $eventId = (string) Str::uuid();
            $body = 'تم رصد درجات الطالب '.($student->user?->name ?? 'الطالب').' بواسطة '.($teacher->user?->name ?? 'المعلم').': '
                .collect($grades)->map(fn ($grade) => $grade['title'].': '.$this->number($grade['score']).' من '.$this->number($grade['maximum_score']))->implode('، ');
            foreach ($recipients->unique('id') as $recipient) {
                $id = (string) Str::uuid();
                $payload = [
                    'title' => 'درجات الاختبارات', 'body' => $body, 'category' => 'grade',
                    'student_id' => $student->id, 'teacher_id' => $teacher->id,
                    'classroom_id' => $classroomId, 'event_id' => $eventId, 'grades' => $grades,
                    'url' => route($recipient->isStudent() ? 'student.results' : 'parent.results',
                        $recipient->isStudent() ? [] : ['student' => $student->id], false),
                ];
                DB::table('notifications')->insert([
                    'id' => $id, 'type' => ParentPortalNotification::class,
                    'notifiable_type' => $recipient->getMorphClass(), 'notifiable_id' => $recipient->id,
                    'data' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                DB::afterCommit(function () use ($id): void {
                    try {
                        Bus::dispatch(new SendGradeSheetPush($id));
                    } catch (Throwable) {
                        // The inbox entry is already committed; transport failure must not fail the save.
                        Log::warning('Grade push dispatch failed; inbox notification retained.', ['notification_id' => $id]);
                    }
                });
            }
        }
    }

    private function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
