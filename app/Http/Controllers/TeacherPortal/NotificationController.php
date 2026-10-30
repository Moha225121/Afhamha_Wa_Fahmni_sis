<?php

namespace App\Http\Controllers\TeacherPortal;

use App\Http\Controllers\Controller;
use App\Http\Requests\TeacherNotificationRequest;
use App\Jobs\SendTeacherNotification;
use App\Models\AuditLog;
use App\Models\Classroom;
use App\Models\Subject;
use App\Models\TeacherMessageReceipt;
use App\Services\ParentNotificationService;
use App\Services\TeacherMessageReceiptService;
use App\Services\TeacherNotificationAudience;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

class NotificationController extends Controller
{
    public function create(Request $request, TeacherNotificationAudience $audience): View
    {
        $validation = new TeacherNotificationRequest;
        $filters = $request->validate(TeacherNotificationRequest::filterRules(), $validation->messages(), $validation->attributes());

        return $this->form($request, $audience, $filters);
    }

    private function form(Request $request, TeacherNotificationAudience $audience, array $input): View
    {
        $filters = array_replace(['audience' => 'students', 'subject_id' => null, 'classroom_id' => null, 'recipient_id' => null, 'q' => ''], $input);
        $teacher = $request->user()->teacher;
        $query = $audience->query($teacher, $filters, false);
        $pairs = $audience->pairs($teacher)->select(['a.classroom_id', 'a.subject_id'])->get();
        $individual = in_array($filters['audience'], ['student', 'guardian'], true);
        $choices = $individual ? (clone $query)->select(['users.id', 'users.name', 'users.role'])
            ->when($filters['q'], fn ($q, $name) => $q->where('name', 'like', '%'.$name.'%'))
            ->orderBy('name')->limit(100)->get() : collect();
        $initialFilters = $filters;
        foreach (['audience' => ['families', 'parents'], 'selection_mode' => ['all', 'grade', 'branches', 'people']] as $field => $allowed) {
            $old = $request->old($field);
            if (is_string($old) && in_array($old, $allowed, true)) {
                $initialFilters[$field] = $old;
            }
        }
        foreach (['classroom_ids', 'student_ids', 'guardian_ids'] as $field) {
            $old = $request->old($field);
            if (is_array($old)) {
                $initialFilters[$field] = array_values(array_filter($old, fn ($id) => is_scalar($id) && filter_var($id, FILTER_VALIDATE_INT) !== false && (int) $id > 0));
            }
        }

        return view('teacher.notifications.create', [
            'filters' => $filters, 'individual' => $individual, 'choices' => $choices,
            'eligibleCount' => $query->count(), 'audienceLabels' => TeacherNotificationAudience::LABELS,
            'subjects' => Subject::whereIn('id', $pairs->pluck('subject_id'))->orderBy('name')->get(['id', 'name']),
            'classrooms' => Classroom::whereIn('id', $pairs->pluck('classroom_id'))->with('academicYear')->orderBy('name')->get(),
            'pairs' => $pairs, 'limit' => TeacherNotificationAudience::MAX_RECIPIENTS,
            'selectedRecipient' => filter_var($request->old('recipient_id', $filters['recipient_id']), FILTER_VALIDATE_INT) ?: null,
            'draftTitle' => is_string($request->old('title')) ? $request->old('title') : ($input['title'] ?? ''),
            'draftMessage' => is_string($request->old('message')) ? $request->old('message') : ($input['message'] ?? ''),
            'messagingData' => $audience->messagingData($teacher, $initialFilters),
        ]);
    }

    public function preview(TeacherNotificationRequest $request, TeacherNotificationAudience $audience): View
    {
        try {
            return $this->review($request, $audience);
        } catch (ValidationException $exception) {
            $exception->redirectTo(route('teacher.notifications.index'));

            throw $exception;
        }
    }

    private function review(TeacherNotificationRequest $request, TeacherNotificationAudience $audience): View
    {
        $data = $request->validated();
        if ($request->boolean('edit')) {
            return $this->form($request, $audience, $data);
        }
        $filters = array_intersect_key($data, array_flip(['audience', 'subject_id', 'classroom_id', 'recipient_id', 'selection_mode', 'classroom_ids', 'student_ids', 'guardian_ids']));
        $filters['audience'] ??= 'students';
        $recipients = $audience->resolve($request->user()->teacher, $filters);
        $contexts = $audience->contexts($request->user()->teacher, $filters, $recipients);
        $recipients->loadCount(['fcmTokens', 'pushSubscriptions']);
        $previewId = (string) Str::uuid();
        Cache::put('teacher-notification-preview:'.$previewId, [
            'actor_id' => $request->user()->id, 'filters' => $filters, 'title' => $data['title'], 'message' => $data['message'],
            'recipient_ids' => $recipients->pluck('id')->all(),
            'contexts' => $contexts,
        ], now()->addMinutes(10));
        $students = $recipients->where('role', 'student');
        $parents = $recipients->where('role', 'parent');
        $linkedStudentIds = $parents->flatMap(fn ($parent) => $contexts[$parent->id]['student_ids'] ?? [])->unique();

        return view('teacher.notifications.preview', [
            'previewId' => $previewId, 'data' => $data, 'count' => $recipients->count(),
            'registeredCount' => $recipients->filter(fn ($user) => $user->fcm_tokens_count + $user->push_subscriptions_count > 0)->count(),
            'names' => $recipients->take(10)->pluck('name'),
            'audienceLabel' => TeacherNotificationAudience::LABELS[$filters['audience']],
            'recipientGroups' => [
                'students' => $students->map(fn ($student) => ['name' => $student->name, 'context' => $contexts[$student->id]['classroom_name'] ?? ''])->values(),
                'guardians' => $parents->map(fn ($parent) => [
                    'name' => $parent->name, 'context' => empty($contexts[$parent->id]['student_names']) ? '' : 'بخصوص: '.implode('، ', $contexts[$parent->id]['student_names']),
                ])->values(),
            ],
            'studentCount' => $students->count(), 'guardianCount' => $parents->count(),
            'missingGuardianCount' => ($filters['audience'] ?? '') === 'families'
                ? $students->filter(fn ($student) => ! $linkedStudentIds->contains($contexts[$student->id]['student_ids'][0] ?? null))->count() : 0,
        ]);
    }

    public function store(Request $request, TeacherNotificationAudience $audience): RedirectResponse
    {
        try {
            return $this->queue($request, $audience);
        } catch (ValidationException $exception) {
            $previewId = $request->input('preview_id');
            $preview = is_string($previewId) && Str::isUuid($previewId)
                ? Cache::get('teacher-notification-preview:'.$previewId) : null;
            if (is_array($preview) && ($preview['actor_id'] ?? null) === $request->user()->id) {
                $request->replace($this->draft($preview));
            }
            $exception->redirectTo(route('teacher.notifications.index'));

            throw $exception;
        }
    }

    private function draft(array $preview): array
    {
        return array_intersect_key($preview['filters'] ?? [], array_flip([
            'audience', 'subject_id', 'classroom_id', 'recipient_id', 'selection_mode', 'classroom_ids', 'student_ids', 'guardian_ids',
        ])) + ['title' => $preview['title'] ?? '', 'message' => $preview['message'] ?? ''];
    }

    private function queue(Request $request, TeacherNotificationAudience $audience): RedirectResponse
    {
        $data = $request->validate([
            'preview_id' => ['required', 'uuid'], 'confirmed' => ['required', 'accepted'],
            'teacher_id' => ['prohibited'], 'user_id' => ['prohibited'], 'recipient_count' => ['prohibited'],
        ], [
            'confirmed.required' => 'يجب تأكيد نطاق المستلمين قبل الإرسال.',
            'confirmed.accepted' => 'يجب تأكيد نطاق المستلمين قبل الإرسال.',
            'preview_id.required' => 'أعد معاينة الإشعار قبل الإرسال.',
            'preview_id.uuid' => 'معرّف المعاينة غير صالح.',
            'prohibited' => 'يحتوي الطلب على حقل غير مسموح به.',
        ]);
        $preview = Cache::get('teacher-notification-preview:'.$data['preview_id']);
        if (! is_array($preview) || $preview['actor_id'] !== $request->user()->id) {
            throw ValidationException::withMessages(['preview_id' => 'انتهت صلاحية المعاينة أو أنها غير متاحة. أعد المعاينة من حسابك.']);
        }
        $recipients = $audience->resolve($request->user()->teacher, $preview['filters']);
        $contexts = $audience->contexts($request->user()->teacher, $preview['filters'], $recipients);
        if ($recipients->pluck('id')->all() !== $preview['recipient_ids'] || $contexts !== ($preview['contexts'] ?? [])) {
            throw ValidationException::withMessages(['preview_id' => 'تغيّر نطاق المستلمين بعد المعاينة. أعد المعاينة قبل الإرسال.']);
        }
        if ($audience->isMessaging($preview['filters'])) {
            app(TeacherMessageReceiptService::class)->requireAvailable();
        }
        $connection = config('database.default');
        if (config('queue.connections.database.driver') !== 'database'
            || (config('queue.connections.database.connection') ?: $connection) !== $connection
            || (config('queue.batching.database') ?: $connection) !== $connection) {
            throw ValidationException::withMessages(['send' => 'إعداد معالجة الإشعارات غير متاح حالياً. تواصل مع مسؤول النظام.']);
        }
        if (AuditLog::where('user_id', $request->user()->id)->where('module', 'teacher_notifications')->where('action', 'queued')
            ->where('new_values->dispatch_id', $data['preview_id'])->exists()
            || ! Cache::add('teacher-notification-submitted:'.$data['preview_id'], true, now()->addDay())) {
            return redirect()->route('teacher.notifications.show', $data['preview_id']);
        }
        try {
            DB::transaction(function () use ($request, $preview, $data, $audience, $recipients): void {
                if ($audience->isMessaging($preview['filters'])) {
                    $timestamp = now();
                    collect($preview['recipient_ids'])->map(fn ($id) => [
                        'notification_id' => SendTeacherNotification::notificationId($data['preview_id'], $id),
                        'teacher_user_id' => $request->user()->id, 'recipient_user_id' => $id, 'dispatch_id' => $data['preview_id'],
                        'created_at' => $timestamp, 'updated_at' => $timestamp,
                    ])->chunk(500)->each(fn ($rows) => TeacherMessageReceipt::insertOrIgnore($rows->values()->all()));
                }
                $jobs = collect($preview['recipient_ids'])->map(fn ($id) => new SendTeacherNotification(
                    $request->user()->id, $data['preview_id'], $id, $preview['filters'], $preview['title'], $preview['message'],
                    $preview['contexts'][$id] ?? null,
                ));
                if ($audience->isMessaging($preview['filters'])) {
                    $recipientsById = $recipients->keyBy('id');
                    $notifications = app(ParentNotificationService::class);
                    $inboxRows = [];
                    $deliveryRows = [];
                    foreach ($jobs as $job) {
                        $recipient = $recipientsById->get($job->recipientId);
                        $inboxRows[] = $notifications->queuedMessageRecord($recipient, $job->inboxPayload($recipient, $audience),
                            SendTeacherNotification::notificationId($job->dispatchId, $job->recipientId));
                        $deliveryRows[] = [
                            'user_id' => $job->actorId, 'action' => 'stored', 'module' => 'teacher_notification_delivery',
                            'record_id' => $job->recipientId,
                            'new_values' => json_encode(['dispatch_id' => $job->dispatchId, 'recipient_id' => $job->recipientId, 'push_accepted' => 0], JSON_THROW_ON_ERROR),
                            'created_at' => $timestamp,
                        ];
                    }
                    foreach (array_chunk($inboxRows, 50) as $rows) {
                        DB::table('notifications')->insert($rows);
                    }
                    foreach (array_chunk($deliveryRows, 50) as $rows) {
                        AuditLog::insert($rows);
                    }
                }
                // Bound encryption/payload memory for the largest permitted recipient snapshot.
                $batch = Bus::batch($jobs->take(50))->name('teacher-notification:'.$data['preview_id'])
                    ->onConnection('database')->onQueue('notifications')->allowFailures()->dispatch();
                foreach ($jobs->slice(50)->chunk(50) as $chunk) {
                    $batch->add($chunk);
                }
                AuditLog::create([
                    'user_id' => $request->user()->id, 'action' => 'queued', 'module' => 'teacher_notifications',
                    'record_id' => $request->user()->teacher->id, 'ip_address' => $request->ip(),
                    'new_values' => ['dispatch_id' => $data['preview_id'], 'batch_id' => $batch->id, 'recipient_count' => count($preview['recipient_ids']), 'title' => $preview['title']],
                ]);
            });
        } catch (Throwable) {
            Log::warning('Teacher notification could not be queued.', ['teacher_user_id' => $request->user()->id]);

            return redirect()->route('teacher.notifications.index')
                ->withErrors(['send' => 'تعذّر تأكيد حفظ طلب الإرسال. راجع نتيجة الطلب قبل إنشاء طلب جديد.'])
                ->withInput($this->draft($preview));
        }

        return redirect()->route('teacher.notifications.show', $data['preview_id']);
    }

    public function sent(Request $request): View
    {
        return view('teacher.notifications.sent', [
            'dispatches' => AuditLog::where('user_id', $request->user()->id)->where('module', 'teacher_notifications')
                ->where('action', 'queued')->latest()->orderByDesc('id')->paginate(20),
        ]);
    }

    public function show(Request $request, string $dispatch): View
    {
        $record = AuditLog::where('user_id', $request->user()->id)->where('module', 'teacher_notifications')
            ->where('new_values->dispatch_id', $dispatch)->firstOrFail();
        $batch = Bus::findBatch($record->new_values['batch_id']);
        $outcomes = AuditLog::where('user_id', $request->user()->id)->where('module', 'teacher_notification_delivery')
            ->where('new_values->dispatch_id', $dispatch)->orderBy('id')->get()->keyBy('record_id');
        $receipts = app(TeacherMessageReceiptService::class)->available()
            ? TeacherMessageReceipt::where('teacher_user_id', $request->user()->id)->where('dispatch_id', $dispatch)
                ->with('recipient:id,name,role')->orderBy('recipient_user_id')->get()
            : (new TeacherMessageReceipt)->newCollection();
        $notifications = DB::table('notifications')->whereIn('id', $receipts->modelKeys())->get(['id', 'created_at'])->keyBy('id');
        foreach ($receipts as $receipt) {
            $stored = $notifications->get($receipt->notification_id);
            $outcome = $outcomes->get($receipt->recipient_user_id)?->action;
            $status = $receipt->viewed_at ? 'viewed' : ($receipt->delivered_at ? 'delivered' : ($stored ? 'sent' : (in_array($outcome, ['failed', 'skipped'], true) ? $outcome : 'queued')));
            $receipt->setAttribute('status', $status);
            $receipt->setAttribute('status_label', match ($status) {
                'viewed' => 'تمت المشاهدة', 'delivered' => 'تم الاستلام', 'sent' => 'تم الإرسال',
                'failed' => 'تعذّر الإرسال', 'skipped' => 'لم يُرسل', default => 'قيد الإرسال',
            });
            $receipt->setAttribute('sent_at', $stored?->created_at);
        }
        $sentCount = $receipts->isNotEmpty()
            ? $receipts->whereIn('status', ['sent', 'delivered', 'viewed'])->count()
            : $outcomes->where('action', 'stored')->count();

        return view('teacher.notifications.show', [
            'dispatch' => $dispatch, 'batch' => $batch, 'count' => $record->new_values['recipient_count'],
            'stored' => $sentCount, 'skipped' => $outcomes->where('action', 'skipped')->count(),
            // A device job can fail after its inbox copy was saved successfully.
            'failed' => min($batch?->failedJobs ?? $outcomes->where('action', 'failed')->count(),
                max(0, $record->new_values['recipient_count'] - $sentCount - $outcomes->where('action', 'skipped')->count())),
            'accepted' => $outcomes->sum(fn ($row) => $row->new_values['push_accepted'] ?? 0),
            'perRecipientReceipts' => $receipts,
            'deliveredCount' => $receipts->whereNotNull('delivered_at')->count(),
            'viewedCount' => $receipts->whereNotNull('viewed_at')->count(),
        ]);
    }
}
