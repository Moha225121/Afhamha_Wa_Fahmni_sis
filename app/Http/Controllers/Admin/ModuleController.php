<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class ModuleController extends Controller
{
    public function index(string $module, Request $r): View
    {
        $data = match ($module) {
            'schedules' => DB::table('schedules')->join('classrooms', 'schedules.classroom_id', '=', 'classrooms.id')->join('subjects', 'schedules.subject_id', '=', 'subjects.id')->select('schedules.*', 'classrooms.name as classroom', 'subjects.name as subject')->paginate(15),'attendance' => DB::table('attendance_records')->join('students', 'attendance_records.student_id', '=', 'students.id')->join('users', 'students.user_id', '=', 'users.id')->select('attendance_records.*', 'users.name')->latest('date')->paginate(15),'exams' => DB::table('exams')->join('subjects', 'exams.subject_id', '=', 'subjects.id')->select('exams.*', 'subjects.name as subject')->latest('starts_at')->paginate(15),'grades' => DB::table('grades')->join('students', 'grades.student_id', '=', 'students.id')->join('users', 'students.user_id', '=', 'users.id')->join('exams', 'grades.exam_id', '=', 'exams.id')->select('grades.*', 'users.name', 'exams.title')->paginate(15),'users' => User::latest()->paginate(15),'audit-logs' => AuditLog::latest('created_at')->paginate(20),default => collect()
        };

        return view('admin.modules.index', compact('module', 'data'));
    }

    public function toggle(string $module, int $id): RedirectResponse
    {
        $map = ['students' => 'students', 'teachers' => 'teachers', 'parents' => 'guardians', 'subjects' => 'subjects', 'users' => 'users', 'library' => 'library_resources'];
        abort_unless(isset($map[$module]), 404);
        $row = DB::table($map[$module])->find($id);
        abort_unless($row, 404);
        $status = $row->status === 'active' ? 'inactive' : 'active';
        DB::table($map[$module])->where('id', $id)->update(['status' => $status, 'updated_at' => now()]);
        AuditService::record('status_changed',$module);

        return back()->with('success','تم تغيير الحالة.');
    }
}
