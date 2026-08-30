<?php

namespace App\Services;

use App\Enums\AttendanceStatus;
use App\Models\Attendance;
use App\Models\Student;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DailyAttendanceService
{
    public function save(User $supervisor, int $classroomId, string $date, array $records): void
    {
        abort_unless($supervisor->supervisedClassrooms()->whereKey($classroomId)->exists(), 403, 'هذا الفصل خارج نطاق إشرافك.');
        $students = Student::where('classroom_id', $classroomId)->whereIn('id', array_keys($records))->get()->keyBy('id');
        if ($students->count() !== count($records)) abort(403, 'تتضمن البيانات طالباً خارج الفصل المكلف به.');
        DB::transaction(function () use ($supervisor, $classroomId, $date, $records, $students): void {
            foreach ($records as $studentId => $data) {
                $status = AttendanceStatus::from($data['status']);
                if ($status->needsExcuse() && blank($data['excuse_reason'] ?? null)) throw ValidationException::withMessages(["records.$studentId.excuse_reason"=>'سبب العذر مطلوب لهذه الحالة.']);
                $existing = Attendance::where('student_id', $studentId)->whereDate('date', $date)->first();
                $old = $existing?->getAttributes() ?? [];
                $document = $data['excuse_document'] ?? null;
                $path = $document instanceof UploadedFile ? $document->store('attendance-excuses', 'private') : $existing?->excuse_document;
                $attendance = Attendance::updateOrCreate(['student_id'=>$studentId,'date'=>$date], ['classroom_id'=>$classroomId,'status'=>$status,'arrival_time'=>$status->isLate()?($data['arrival_time']??null):null,'late_minutes'=>$status->isLate()?($data['late_minutes']??null):null,'excuse_reason'=>$status->needsExcuse()?($data['excuse_reason']??null):null,'excuse_document'=>$status->needsExcuse()?$path:null,'notes'=>$data['notes']??null,'recorded_by'=>$existing?->recorded_by ?: $supervisor->id,'updated_by'=>$supervisor->id]);
                AuditService::record($existing?'updated':'created', 'attendance', $attendance, $old);
            }
        });
    }
}
