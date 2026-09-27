<?php
namespace App\Http\Controllers\SupervisorPortal;
use App\Enums\AttendanceStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Supervisor\StoreDailyAttendanceRequest;
use App\Models\Attendance;
use App\Models\Student;
use App\Services\DailyAttendanceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
class AttendanceController extends Controller {
 public function index(Request $r): View { $classes=$r->user()->supervisedClassrooms()->orderBy('name')->get(); $classId=(int)($r->query('classroom_id')?:$classes->first()?->id); abort_if($classId&&!$classes->contains('id',$classId),403); $date=$r->date('date',today())?->toDateString()?:today()->toDateString(); $students=Student::with('user')->where('classroom_id',$classId)->where('status','active')->get(); $records=Attendance::whereDate('date',$date)->whereIn('student_id',$students->pluck('id'))->get()->keyBy('student_id'); return view('supervisor.attendance.index',compact('classes','classId','date','students','records')); }
 public function store(StoreDailyAttendanceRequest $r, DailyAttendanceService $service): RedirectResponse { $d=$r->validated(); $service->save($r->user(),(int)$d['classroom_id'],$d['date'],$d['records']); return back()->with('success','تم حفظ حضور اليوم بنجاح.'); }
 public function history(Request $r): View { $ids=$r->user()->supervisedClassrooms()->pluck('classrooms.id'); $q=Attendance::with(['student.user','student.classroom'])->whereIn('classroom_id',$ids)->when($r->date_from,fn($x,$v)=>$x->whereDate('date','>=',$v))->when($r->date_to,fn($x,$v)=>$x->whereDate('date','<=',$v))->when($r->status,fn($x,$v)=>$x->where('status',$v))->when($r->student,fn($x,$v)=>$x->where('student_id',$v)); return view('supervisor.attendance.history',['records'=>$q->latest('date')->paginate(30)->withQueryString(),'statuses'=>AttendanceStatus::cases()]); }
}
