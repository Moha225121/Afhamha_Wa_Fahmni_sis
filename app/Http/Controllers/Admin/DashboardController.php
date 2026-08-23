<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Models\AuditLog;
use App\Models\Classroom;
use App\Models\Guardian;
use App\Models\Student;
use App\Models\Teacher;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(): View
    {
        $today = now()->toDateString();
        $attendance = DB::table('attendance_records')->whereDate('date', $today);
        $total = (clone $attendance)->count();
        $present = (clone $attendance)->where('status', 'present')->count();

        return view('admin.dashboard', ['stats' => ['students' => Student::where('status', 'active')->count(), 'teachers' => Teacher::where('status', 'active')->count(), 'parents' => Guardian::where('status', 'active')->count(), 'classes' => Classroom::count(), 'attendance_rate' => $total ? round($present / $total * 100, 1) : 0, 'absent' => (clone $attendance)->where('status', 'absent')->count(), 'upcoming_exams' => DB::table('exams')->where('starts_at', '>=', now())->whereIn('status', ['scheduled', 'published'])->count(), 'announcements' => Announcement::where('status', 'published')->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))->count()], 'activities' => AuditLog::latest('created_at')->limit(8)->get()]);
    }
}
