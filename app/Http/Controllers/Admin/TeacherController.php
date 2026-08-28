<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\TeacherRequest;
use App\Models\Classroom;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class TeacherController extends Controller
{
    public function index(Request $r): View
    {
        $teachers = Teacher::with(['user', 'subjects', 'classrooms'])->when($r->q, fn ($q, $v) => $q->whereHas('user', fn ($u) => $u->where('name', 'like', "%$v%")->orWhere('email', 'like', "%$v%")))->when($r->status, fn ($q, $v) => $q->where('status', $v))->latest()->paginate(15)->withQueryString();

        return view('admin.teachers.index', compact('teachers'));
    }

    public function create(): View
    {
        return $this->form(new Teacher);
    }

    public function edit(Teacher $teacher): View
    {
        return $this->form($teacher->load('classrooms'));
    }

    private function form(Teacher $teacher): View
    {
        $assignments = DB::table('teacher_assignments')->where('teacher_id', $teacher->id)->get();

        return view('admin.teachers.form', compact('teacher', 'assignments') + ['classrooms' => Classroom::all(), 'subjects' => Subject::all()]);
    }

    public function store(TeacherRequest $r): RedirectResponse
    {
        $t = DB::transaction(function () use ($r) {
            $u = User::create($r->only('name', 'email', 'phone', 'password') + ['role' => 'teacher', 'status' => $r->status]);
            $t = $u->teacher()->create($r->only('specialization', 'status'));
            $this->sync($t, $r->input('assignments', []));
            AuditService::record('created', 'teachers', $t);

            return $t;
        });

        return redirect()->route('admin.teachers.show', $t)->with('success', 'تمت إضافة المعلم.');
    }

    public function update(TeacherRequest $r, Teacher $teacher): RedirectResponse
    {
        DB::transaction(function () use ($r, $teacher) {
            $old = $teacher->getAttributes();
            $d = $r->only('name', 'email', 'phone', 'status');
            if ($r->filled('password')) {
                $d['password'] = $r->password;
            }$teacher->user->update($d);
            $teacher->update($r->only('specialization', 'status'));
            $this->sync($teacher, $r->input('assignments', []));
            AuditService::record('updated', 'teachers', $teacher, $old);
        });

        return redirect()->route('admin.teachers.show', $teacher)->with('success', 'تم تحديث المعلم.');
    }

    private function sync(Teacher $t, array $items): void
    {
        DB::table('teacher_assignments')->where('teacher_id', $t->id)->delete();
        foreach ($items as $a) {
            DB::table('teacher_assignments')->insertOrIgnore(['teacher_id' => $t->id] + $a);
        }
    }

    public function show(Teacher $teacher): View
    {
        return view('admin.teachers.show',['teacher' => $teacher->load(['user', 'subjects', 'classrooms'])]);
    }
}
