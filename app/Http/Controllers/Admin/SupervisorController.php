<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\SupervisorRequest;
use App\Models\Classroom;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class SupervisorController extends Controller
{
    public function index(Request $request): View
    {
        $supervisors = User::query()->with('supervisedClassrooms')->where('role','supervisor')
            ->when($request->q, fn ($q,$v) => $q->where(fn ($x) => $x->where('name','like',"%$v%")->orWhere('email','like',"%$v%")))
            ->when($request->status, fn ($q,$v) => $q->where('status',$v))->latest()->paginate(15)->withQueryString();
        return view('admin.supervisors.index', compact('supervisors'));
    }

    public function create(): View { return $this->form(new User(['role'=>'supervisor','status'=>'active'])); }

    public function edit(User $supervisor): View
    {
        $this->assertSupervisor($supervisor);
        return $this->form($supervisor->load('supervisedClassrooms'));
    }

    public function store(SupervisorRequest $request): RedirectResponse
    {
        $supervisor = DB::transaction(function () use ($request): User {
            $supervisor = User::create($request->only('name','email','phone','password','status') + ['role'=>'supervisor']);
            $supervisor->supervisedClassrooms()->sync($request->validated('classroom_ids'));
            AuditService::record('created','supervisors',$supervisor);
            return $supervisor;
        });
        return redirect()->route('admin.supervisors.edit',$supervisor)->with('success','تمت إضافة المشرف وتحديد فصوله.');
    }

    public function update(SupervisorRequest $request, User $supervisor): RedirectResponse
    {
        $this->assertSupervisor($supervisor);
        DB::transaction(function () use ($request,$supervisor): void {
            $old=$supervisor->getAttributes(); $data=$request->only('name','email','phone','status');
            if($request->filled('password')) $data['password']=$request->password;
            $supervisor->update($data); $supervisor->supervisedClassrooms()->sync($request->validated('classroom_ids'));
            AuditService::record('updated','supervisors',$supervisor,$old);
        });
        return redirect()->route('admin.supervisors.index')->with('success','تم تحديث بيانات المشرف.');
    }

    private function form(User $supervisor): View { return view('admin.supervisors.form',['supervisor'=>$supervisor,'classrooms'=>Classroom::with('academicYear')->orderBy('name')->get()]); }
    private function assertSupervisor(User $supervisor): void { abort_unless($supervisor->role==='supervisor',404); }
}
