<?php

namespace App\Services;

use App\Jobs\SendTeacherReportPush;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\TeacherReport;
use App\Models\TeacherReportDelivery;
use App\Models\TeacherReportDispatch;
use App\Models\User;
use App\Notifications\TeacherReportNotification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

/** A delivery records a private, immutable copy of an explicitly saved report revision. */
class TeacherReportDeliveryService
{
    public function __construct(private readonly TeacherReportService $reports) {}

    public function ready(): bool
    {
        return $this->reports->ready() && Schema::hasTable('teacher_report_dispatches')
            && Schema::hasTable('teacher_report_deliveries') && Schema::hasTable('notifications');
    }

    public function recipients(Teacher $teacher, int $reportId): array
    {
        $this->requireReady();
        $builder = $this->saved($teacher, $reportId);
        $students = $this->currentStudents($builder);

        return ['students' => $students->map(fn (Student $student) => ['id' => (int) $student->id,
            'name' => $student->user->name, 'parents_count' => $this->parents($student)->count()])->values()->all(),
            'admins_count' => User::where('role', 'admin')->where('status', 'active')->count()];
    }

    public function preview(Teacher $teacher, int $reportId, array $payload): array
    {
        $this->requireReady();
        $payload = $this->validatePayload($payload);
        $builder = $this->saved($teacher, $reportId, (int) $payload['revision']);
        $audience = $this->audience($builder, $payload);

        return $audience['summary'];
    }

    public function send(Teacher $teacher, int $reportId, array $payload): array
    {
        $this->requireReady();
        $payload = $this->validatePayload($payload, true);
        $fingerprint = hash('sha256', json_encode(['report_id' => $reportId, 'revision' => (int) $payload['revision'],
            'audiences' => $payload['audiences'], 'student_ids' => $payload['student_ids']], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($teacher, $reportId, $payload, $fingerprint): array {
            // Serialize this teacher's request IDs as well as edits to the chosen report.
            Teacher::whereKey($teacher->id)->lockForUpdate()->firstOrFail();
            TeacherReport::where('teacher_id', $teacher->id)->whereKey($reportId)->lockForUpdate()->firstOrFail();
            $builder = $this->saved($teacher, $reportId, (int) $payload['revision']);
            $existing = TeacherReportDispatch::whereKey($payload['request_id'])->lockForUpdate()->first();
            if ($existing) {
                abort_unless((int) $existing->teacher_id === (int) $teacher->id
                    && hash_equals($existing->fingerprint, $fingerprint), 409, 'استُخدم معرّف الإرسال لطلب مختلف. أعد مراجعة المستلمين.');

                return $existing->response;
            }
            $audience = $this->audience($builder, $payload);
            if (! $audience['summary']['counts']['total']) {
                $this->invalid('audiences', 'لا يوجد مستلم نشط ضمن الاختيار الحالي.');
            }
            $response = ['message' => 'تم إرسال التقرير إلى المستلمين المحددين.', 'dispatch_id' => $payload['request_id']] + $audience['summary'];
            $dispatch = new TeacherReportDispatch(['id' => $payload['request_id'], 'teacher_id' => $teacher->id,
                'report_id' => $reportId, 'revision' => $payload['revision'], 'fingerprint' => $fingerprint, 'response' => $response]);
            $dispatch->updateTimestamps();
            if (! TeacherReportDispatch::query()->insertOrIgnore($dispatch->getAttributes())) {
                // A request ID reused concurrently by another teacher must produce a conflict, not a unique-key server error.
                $existing = TeacherReportDispatch::whereKey($payload['request_id'])->lockForUpdate()->firstOrFail();
                abort_unless((int) $existing->teacher_id === (int) $teacher->id
                    && hash_equals($existing->fingerprint, $fingerprint), 409, 'استُخدم معرّف الإرسال لطلب مختلف. أعد مراجعة المستلمين.');

                return $existing->response;
            }
            foreach ($audience['recipients'] as $recipient) {
                $studentId = $recipient['student_id'];
                $selectedIds = $studentId !== null ? [$studentId] : $payload['student_ids'];
                $delivery = TeacherReportDelivery::create(['id' => (string) Str::uuid(), 'dispatch_id' => $dispatch->id,
                    'report_id' => $reportId, 'report_revision' => $payload['revision'], 'recipient_user_id' => $recipient['user']->id,
                    'student_id' => $studentId, 'audience' => $recipient['audience'],
                    'snapshot' => $this->snapshot($teacher, $builder, $selectedIds)]);
                $this->notify($recipient['user'], $delivery);
            }

            return $response;
        });
    }

    public function inbox(User $user, ?int $studentId = null): LengthAwarePaginator
    {
        $this->requireReady();
        $query = $this->authorizedQuery($user);
        if ($studentId !== null) {
            abort_unless($this->allowedStudentIds($user)->contains($studentId), 404);
            $query->where('student_id', $studentId);
        }

        return $query->latest('created_at')->latest('id')->paginate(15)->withQueryString();
    }

    public function authorized(User $user, string $id): ?TeacherReportDelivery
    {
        if (! Str::isUuid($id) || ! $this->ready()) {
            return null;
        }

        return $this->authorizedQuery($user)->whereKey($id)->first();
    }

    public function canReceive(User $user, string $id): bool
    {
        return $this->authorized($user, $id) !== null;
    }

    public function received(User $user, string $id): array
    {
        $this->requireReady();
        $delivery = $this->authorized($user, $id);
        abort_unless($delivery, 404);
        if ($delivery->read_at === null) {
            TeacherReportDelivery::whereKey($id)->whereNull('read_at')->update(['read_at' => now()]);
            $delivery->refresh();
            $user->unreadNotifications()->whereKey($id)->update(['read_at' => now()]);
        }

        return ['delivery' => $delivery, 'builder' => $delivery->snapshot];
    }

    private function saved(Teacher $teacher, int $reportId, ?int $revision = null): array
    {
        $teacher->loadMissing('user');
        abort_unless($teacher->status === 'active' && $teacher->user?->isTeacher(), 403);
        $builder = $this->reports->load($teacher, $reportId);
        if ($revision !== null && (int) $builder['report']['revision'] !== $revision) {
            abort(409, 'عُدّل التقرير بعد فتحه. احفظ أحدث نسخة وأعد مراجعة الإرسال.');
        }

        return $builder;
    }

    private function currentStudents(array $builder): Collection
    {
        $savedIds = array_values(array_unique(array_column($builder['entries'], 'student_id')));

        return Student::where('classroom_id', $builder['classroom']['id'])->where('status', 'active')->whereIn('id', $savedIds)
            ->whereHas('user', fn ($users) => $users->where('role', 'student')->where('status', 'active'))
            ->with(['user', 'guardians.user'])->get()->sortBy(fn (Student $student) => $student->user->name, SORT_NATURAL)->values();
    }

    private function parents(Student $student): Collection
    {
        return $student->guardians->where('status', 'active')->pluck('user')->filter(fn ($user) => $user?->isParent())->unique('id')->values();
    }

    private function audience(array $builder, array $payload): array
    {
        if (! config('nasbia.external_teacher_reports', false)
            && array_intersect($payload['audiences'], ['student', 'parent'])) {
            $this->invalid('audiences', 'مشاركة تقارير الدرجات مع الطالب وولي الأمر تتطلب اعتماد سياسة المدرسة أولًا.');
        }

        $students = $this->currentStudents($builder)->keyBy('id');
        if (array_diff($payload['student_ids'], $students->keys()->all())) {
            $this->invalid('student_ids', 'اختر طلابًا من التقرير المحفوظ وما زالوا نشطين في هذا الصف. حدّث قائمة المستلمين.');
        }
        $selected = $students->only($payload['student_ids']);
        $recipients = [];
        $unlinked = [];
        foreach ($selected as $student) {
            if (in_array('student', $payload['audiences'], true)) {
                $recipients[] = ['user' => $student->user, 'student_id' => (int) $student->id, 'audience' => 'student'];
            }
            if (in_array('parent', $payload['audiences'], true)) {
                $parents = $this->parents($student);
                if ($parents->isEmpty()) {
                    $unlinked[] = ['id' => (int) $student->id, 'name' => $student->user->name];
                }
                foreach ($parents as $parent) {
                    $recipients[] = ['user' => $parent, 'student_id' => (int) $student->id, 'audience' => 'parent'];
                }
            }
        }
        if (in_array('admin', $payload['audiences'], true)) {
            foreach (User::where('role', 'admin')->where('status', 'active')->get() as $admin) {
                $recipients[] = ['user' => $admin, 'student_id' => null, 'audience' => 'admin'];
            }
        }
        $counts = ['student' => 0, 'parent' => 0, 'admin' => 0, 'total' => count($recipients)];
        foreach ($recipients as $recipient) {
            $counts[$recipient['audience']]++;
        }

        return ['recipients' => $recipients, 'summary' => ['counts' => $counts, 'unlinked_students' => $unlinked, 'students_count' => $selected->count()]];
    }

    private function snapshot(Teacher $teacher, array $builder, array $studentIds): array
    {
        $entries = array_values(array_filter($builder['entries'], fn ($entry) => in_array((int) $entry['student_id'], $studentIds, true)));

        return ['report' => $builder['report'], 'teacher_name' => $teacher->user->name,
            'classroom' => $builder['classroom'], 'config' => $builder['config'], 'entries' => $entries,
            'counts' => ['students' => count(array_unique(array_column($entries, 'student_id'))),
                'subjects' => count(array_unique(array_column($entries, 'subject_id')))], 'warnings' => []];
    }

    private function notify(User $user, TeacherReportDelivery $delivery): void
    {
        $payload = ['title' => 'تقرير دراسي جديد', 'body' => 'تمت مشاركة تقرير دراسي معك. افتح التقرير للاطلاع عليه.',
            'category' => 'teacher_report', 'delivery_id' => $delivery->id, 'student_id' => $delivery->student_id,
            'url' => route('received-reports.show', $delivery->id, false)];
        DB::table('notifications')->insert(['id' => $delivery->id, 'type' => TeacherReportNotification::class,
            'notifiable_type' => $user->getMorphClass(), 'notifiable_id' => $user->id,
            'data' => json_encode((new TeacherReportNotification($payload))->toDatabase($user), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'created_at' => now(), 'updated_at' => now()]);
        DB::afterCommit(function () use ($delivery): void {
            try {
                Bus::dispatch(new SendTeacherReportPush($delivery->id));
            } catch (Throwable) {
                Log::warning('Report push dispatch failed; private inbox copy retained.', ['delivery_id' => $delivery->id]);
            }
        });
    }

    private function authorizedQuery(User $user): Builder
    {
        $query = TeacherReportDelivery::where('recipient_user_id', $user->id);
        if ($user->status !== 'active' || ! in_array($user->role, ['student', 'parent', 'admin'], true)) {
            return $query->whereRaw('1 = 0');
        }
        $query->where('audience', $user->role);
        if (! config('nasbia.external_teacher_reports', false) && ! $user->isAdmin()) {
            return $query->whereRaw('1 = 0');
        }
        if ($user->isAdmin()) {
            return $query->whereNull('student_id');
        }

        return $query->whereIn('student_id', $this->allowedStudentIds($user)->all());
    }

    private function allowedStudentIds(User $user): Collection
    {
        if ($user->isStudent()) {
            return $user->student()->where('status', 'active')->pluck('id')->map(fn ($id) => (int) $id);
        }
        if ($user->isParent()) {
            $guardian = $user->guardian()->where('status', 'active')->first();

            return $guardian?->students()->where('students.status', 'active')
                ->whereHas('user', fn ($users) => $users->where('role', 'student')->where('status', 'active'))
                ->pluck('students.id')->map(fn ($id) => (int) $id) ?? collect();
        }

        return collect();
    }

    private function validatePayload(array $payload, bool $sending = false): array
    {
        $data = Validator::make($payload, [
            'revision' => ['required', 'integer', 'min:1'],
            'audiences' => ['required', 'array', 'min:1', 'max:3'],
            'audiences.*' => ['required', Rule::in(['student', 'parent', 'admin']), 'distinct'],
            'student_ids' => ['required', 'array', 'min:1', 'max:5000'],
            'student_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            'request_id' => [$sending ? 'required' : 'sometimes', 'uuid'],
        ])->validate();
        $data['audiences'] = array_values($data['audiences']);
        sort($data['audiences']);
        $data['student_ids'] = array_map('intval', $data['student_ids']);
        sort($data['student_ids']);
        if (isset($data['request_id'])) {
            $data['request_id'] = strtolower($data['request_id']);
        }

        return $data;
    }

    private function requireReady(): void
    {
        if (! $this->ready()) {
            $this->invalid('reports', 'يلزم استكمال تهيئة مشاركة التقارير. تواصل مع مسؤول النظام.');
        }
    }

    private function invalid(string $key, string $message): never
    {
        throw ValidationException::withMessages([$key => $message]);
    }
}
