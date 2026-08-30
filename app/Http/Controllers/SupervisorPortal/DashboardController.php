<?php
namespace App\Http\Controllers\SupervisorPortal;
use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\GuardianCall;
use App\Models\Student;
use App\Models\StudentNote;
use Illuminate\Http\Request;
use Illuminate\View\View;
class DashboardController extends Controller { public function __invoke(Request $r): View { $ids=$r->user()->supervisedClassrooms()->pluck('classrooms.id'); $students=Student::whereIn('classroom_id',$ids); $today=Attendance::whereDate('date',today())->whereIn('classroom_id',$ids); return view('supervisor.dashboard',['stats'=>['students'=>(clone $students)->count(),'present'=>(clone $today)->where('status','present')->count(),'absent'=>(clone $today)->whereIn('status',['absent','excused_absence'])->count(),'late'=>(clone $today)->whereIn('status',['late','excused_late'])->count(),'excused'=>(clone $today)->whereIn('status',['excused_absence','excused_late'])->count(),'calls'=>GuardianCall::whereIn('student_id',(clone $students)->pluck('id'))->whereNotIn('status',['completed','cancelled'])->count()],'absentStudents'=>(clone $today)->with('student.user')->whereIn('status',['absent','excused_absence'])->get(),'lateStudents'=>(clone $today)->with('student.user')->whereIn('status',['late','excused_late'])->get(),'recentNotes'=>StudentNote::with('student.user')->whereIn('student_id',(clone $students)->pluck('id'))->latest()->limit(5)->get()]); } }
