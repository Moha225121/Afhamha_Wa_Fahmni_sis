<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\SendAdminNotificationRequest;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\ParentNotificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Throwable;

class SendNotificationController extends Controller
{
    public function create(Request $request): View
    {
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'recipient_id' => ['nullable', 'integer']]);
        $query = User::query()->where('status', 'active')->select(['id', 'name', 'email', 'role']);
        $search = $filters['q'] ?? '';
        $users = (clone $query)->when($search !== '', fn ($users) => $users->where(fn ($users) => $users
            ->where('name', 'like', '%'.$search.'%')->orWhere('email', 'like', '%'.$search.'%')))
            ->orderBy('name')->limit(50)->get();
        $selected = filter_var($request->old('recipient_id', $filters['recipient_id'] ?? null), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
        if ($selected && ! $users->contains('id', $selected)) {
            if ($recipient = (clone $query)->find($selected)) {
                $users->prepend($recipient);
            }
        }

        $previousSubmission = $request->old('submission_id');

        return view('admin.notifications.create', [
            'users' => $users, 'search' => $search, 'selected' => $selected,
            'submissionId' => is_string($previousSubmission) && Str::isUuid($previousSubmission) ? $previousSubmission : (string) Str::uuid(),
            'draftTitle' => is_string($request->old('title')) ? $request->old('title') : '',
            'draftMessage' => is_string($request->old('message')) ? $request->old('message') : '',
        ]);
    }

    public function store(SendAdminNotificationRequest $request, ParentNotificationService $notifications): RedirectResponse
    {
        $data = $request->validated();
        $recipient = User::query()->where('status', 'active')->findOrFail($data['recipient_id']);
        $key = 'admin-notification:'.$request->user()->id.':'.$data['submission_id'];
        if (! Cache::add($key, true, now()->addDay())) {
            return redirect()->route('admin.notifications.create')->with('notification_result', [
                'status' => 'warning', 'message' => 'سبق استلام هذا الطلب؛ لم يتم إرسال إشعار إضافي. راجع النتيجة السابقة قبل إنشاء طلب جديد.',
            ]);
        }

        try {
            // Record the actor and recipient, without copying message contents or device secrets into logs.
            AuditLog::create([
                'user_id' => $request->user()->id, 'action' => 'requested', 'module' => 'manual_notifications',
                'record_id' => $recipient->id, 'ip_address' => $request->ip(),
                'new_values' => ['recipient_id' => $recipient->id, 'submission_id' => $data['submission_id']],
            ]);
            $result = $notifications->send($recipient, [
                'title' => $data['title'], 'body' => $data['message'],
                'category' => 'admin_notification', 'url' => $this->destination($recipient),
            ]);
        } catch (Throwable) {
            Log::warning('Manual notification could not be completed.', ['admin_id' => $request->user()->id, 'recipient_id' => $recipient->id]);

            return redirect()->route('admin.notifications.create')->with('notification_result', [
                'status' => 'error', 'message' => 'تعذّر تأكيد إرسال الإشعار. راجع سجل إشعارات المستلم قبل المحاولة مجدداً.',
            ]);
        }

        $accepted = $result['fcm_accepted'] + $result['web_push_accepted'];

        return redirect()->route('admin.notifications.create')->with('notification_result', [
            'status' => $accepted > 0 ? 'success' : 'warning',
            'message' => $accepted > 0
                ? 'تم حفظ الإشعار للمستلم وقبلت خدمة إشعارات الأجهزة الإرسال. قبول الإرسال لا يؤكد ظهور الإشعار على الشاشة.'
                : 'تم حفظ الإشعار في حساب المستلم، لكن لم يُقبل أي إرسال لإشعار الجهاز. قد لا تكون إشعارات جهازه مفعّلة أو قد تكون خدمة الإرسال غير متاحة. لا حاجة لإعادة الإرسال لحفظه داخل الموقع.',
        ]);
    }

    private function destination(User $recipient): string
    {
        return route(match ($recipient->role) {
            'student' => 'student.notifications',
            'parent' => 'parent.notifications',
            'admin' => 'admin.notifications.index',
            'teacher' => 'teacher.dashboard',
            'supervisor' => 'supervisor.dashboard',
            'staff' => 'staff.dashboard',
            'financial_officer' => 'finance.parent-notifications.create',
            default => 'login',
        }, [], false);
    }
}
