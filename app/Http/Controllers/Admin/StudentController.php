<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StudentRequest;
use App\Models\Classroom;
use App\Models\Guardian;
use App\Models\Student;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class StudentController extends Controller
{
    public function index(Request $r): View
    {
        $students = Student::with(['user', 'classroom.academicYear', 'guardians.user'])->when($r->q, fn ($q, $v) => $q->whereHas('user', fn ($u) => $u->where('name', 'like', "%$v%"))->orWhere('student_number', 'like', "%$v%"))->when($r->classroom_id, fn ($q, $v) => $q->where('classroom_id', $v))->when($r->status, fn ($q, $v) => $q->where('status', $v))->latest()->paginate(15)->withQueryString();

        return view('admin.students.index', compact('students') + ['classrooms' => Classroom::orderBy('name')->get()]);
    }

    public function create(): View
    {
        return view('admin.students.form', ['student' => new Student, 'classrooms' => Classroom::all(), 'guardians' => Guardian::with('user')->get()]);
    }

    public function store(StudentRequest $r): RedirectResponse
    {
        $s = DB::transaction(function () use ($r) {
            $u = User::create($r->only('name', 'email', 'phone', 'password') + ['role' => 'student', 'status' => $r->status]);
            $s = $u->student()->create($r->safe()->only(['student_number', 'classroom_id', 'birth_date', 'gender', 'address', 'status']));
            $s->guardians()->sync($r->input('guardian_ids', []));
            AuditService::record('created', 'students', $s);

            return $s;
        });

        return redirect()->route('admin.students.show', $s)->with('success', 'تمت إضافة الطالب بنجاح.');
    }

    public function show(Student $student): View
    {
        return view('admin.students.show', ['student' => $student->load(['user', 'classroom.academicYear', 'guardians.user'])]);
    }

    public function edit(Student $student): View
    {
        return view('admin.students.form', compact('student') + ['classrooms' => Classroom::all(), 'guardians' => Guardian::with('user')->get()]);
    }

    public function update(StudentRequest $r, Student $student): RedirectResponse
    {
        DB::transaction(function () use ($r, $student) {
            $old = $student->getAttributes();
            $data = $r->safe()->only(['name', 'email', 'phone', 'status']);
            if ($r->filled('password')) {
                $data['password'] = $r->password;
            }$student->user->update($data);
            $student->update($r->safe()->only(['student_number', 'classroom_id', 'birth_date', 'gender', 'address', 'status']));
            $student->guardians()->sync($r->input('guardian_ids', []));
            AuditService::record('updated', 'students', $student, $old);
        });

        return redirect()->route('admin.students.show',$student)->with('success','تم تحديث بيانات الطالب.');
    }
}
