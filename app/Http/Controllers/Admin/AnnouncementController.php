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
use Illuminate\View\View;

class AnnouncementController extends Controller
{
    public function index(): View
    {
        return view('admin.announcements.index', ['announcements' => Announcement::latest()->paginate(15)]);
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
        $a = Announcement::create($r->validated() + ['created_by' => $r->user()->id]);
        AuditService::record('created', 'announcements', $a);
        $studentNotifications->sendAnnouncement($a);
        $notifications->sendAnnouncement($a);

        return redirect()->route('admin.announcements.index')->with('success', 'تم حفظ الإعلان.');
    }

    public function update(AnnouncementRequest $r, Announcement $announcement, ParentNotificationService $notifications, StudentNotificationService $studentNotifications): RedirectResponse
    {
        $old = $announcement->getAttributes();
        $announcement->update($r->validated());
        AuditService::record('updated', 'announcements', $announcement, $old);
        $studentNotifications->sendAnnouncement($announcement);

        $notifications->sendAnnouncement($announcement);

        return redirect()->route('admin.announcements.index')->with('success', 'تم تحديث الإعلان.');
    }

    public function show(Announcement $announcement): View
    {
        return view('admin.announcements.show', compact('announcement'));
    }
}
