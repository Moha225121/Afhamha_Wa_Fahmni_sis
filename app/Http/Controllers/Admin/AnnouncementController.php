<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\AnnouncementRequest;
use App\Models\Announcement;
use App\Models\Classroom;
use App\Services\AuditService;
use App\Services\ParentNotificationService;
use App\Services\StudentNotificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use App\Support\NotificationData;
use Illuminate\View\View;

class AnnouncementController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'audience' => ['nullable', Rule::in(['all', 'students', 'teachers', 'parents', 'supervisors', 'staff', 'classroom'])],
            'status' => ['nullable', Rule::in(['draft', 'published', 'archived'])],
            'classroom_id' => ['nullable', 'integer', 'exists:classrooms,id'],
        ]);
        $query = Announcement::query()
            ->when($filters['search'] ?? null, fn ($q, $search) => $q->where(fn ($q) => $q->where('title', 'like', '%'.$search.'%')->orWhere('content', 'like', '%'.$search.'%')))
            ->when($filters['audience'] ?? null, fn ($q, $audience) => $q->where('audience', $audience))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['classroom_id'] ?? null, fn ($q, $classroom) => $q->where('classroom_id', $classroom));

        return view('admin.announcements.index', [
            'announcements' => $query->latest('id')->paginate(15)->withQueryString(),
            'classrooms' => Classroom::orderBy('name')->get(),
        ]);
    }

    public function create(): View
    {
        return $this->form(new Announcement);
    }

    public function edit(Announcement $announcement): View
    {
        return $this->form($announcement);
    }

    private function form(Announcement $announcement): View
    {
        return view('admin.announcements.form', compact('announcement') + ['classrooms' => Classroom::all()]);
    }

    public function store(AnnouncementRequest $r, ParentNotificationService $notifications, StudentNotificationService $studentNotifications): RedirectResponse
    {
        DB::transaction(function () use ($r, $studentNotifications, $notifications): void {
            $a = Announcement::create($r->announcementAttributes() + ['created_by' => $r->user()->id]);
            AuditService::record('created', 'announcements', $a);
            $studentNotifications->sendAnnouncement($a);
            $notifications->sendAnnouncement($a);
        });

        return redirect()->route('admin.announcements.index')->with('success', 'تم حفظ الإعلان.');
    }

    public function update(AnnouncementRequest $r, Announcement $announcement, ParentNotificationService $notifications, StudentNotificationService $studentNotifications): RedirectResponse
    {
        DB::transaction(function () use ($r, $announcement, $studentNotifications, $notifications): void {
            $announcement = Announcement::whereKey($announcement->id)->lockForUpdate()->firstOrFail();
            $old = $announcement->getAttributes();
            $announcement->update($r->announcementAttributes());
            AuditService::record('updated', 'announcements', $announcement, $old);
            if ($announcement->status !== 'published') {
                $this->cancelPendingDelivery($announcement);
            }
            $studentNotifications->sendAnnouncement($announcement);
            $notifications->sendAnnouncement($announcement);
        });

        return redirect()->route('admin.announcements.index')->with('success', 'تم تحديث الإعلان.');
    }

    public function show(Announcement $announcement): View
    {
        return view('admin.announcements.show', compact('announcement'));
    }

    public function archive(Announcement $announcement): RedirectResponse
    {
        DB::transaction(function () use ($announcement): void {
            $announcement = Announcement::whereKey($announcement->id)->lockForUpdate()->firstOrFail();
            if ($announcement->status !== 'archived') {
                $old = $announcement->getAttributes();
                $announcement->update(['status' => 'archived']);
                AuditService::record('archived', 'announcements', $announcement, $old);
            }
            $this->cancelPendingDelivery($announcement);
        });

        return back()->with('success', 'تمت أرشفة الإعلان وإلغاء التنبيهات التي لم تُرسل بعد. حُفظ السجل السابق.');
    }

    public function destroy(Announcement $announcement): RedirectResponse
    {
        DB::transaction(function () use ($announcement): void {
            $announcement = Announcement::whereKey($announcement->id)->lockForUpdate()->firstOrFail();
            $hasHistory = DB::table('notifications')->where(NotificationData::field('announcement_id'), NotificationData::id($announcement->id))->exists()
                || DB::table('fcm_notification_events')->where('event_key', 'announcement:'.$announcement->id)->exists();
            if ($announcement->status !== 'draft' || $hasHistory) {
                throw ValidationException::withMessages(['announcement' => 'يُحذف الإعلان المسودة الذي لا يملك سجل إشعارات فقط. استخدم الأرشفة لحفظ السجل وإيقاف النشر.']);
            }
            AuditService::record('deleted', 'announcements', $announcement);
            $announcement->delete();
        });

        return redirect()->route('admin.announcements.index')->with('success', 'تم حذف مسودة الإعلان.');
    }

    private function cancelPendingDelivery(Announcement $announcement): void
    {
        DB::table('fcm_notification_events')->where('event_key', 'announcement:'.$announcement->id)
            ->whereNull('sent_at')->whereNull('cancelled_at')->update([
                'cancelled_at' => now(), 'cancellation_reason' => 'announcement_withdrawn', 'updated_at' => now(),
            ]);
    }
}
