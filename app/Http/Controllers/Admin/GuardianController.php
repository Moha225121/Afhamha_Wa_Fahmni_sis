<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\GuardianRequest;
use App\Models\Guardian;
use App\Models\Student;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class GuardianController extends Controller
{
    public function index(Request $r): View
    {
        $parents = Guardian::with('user')->withCount('students')->when($r->q, fn ($q, $v) => $q->whereHas('user', fn ($u) => $u->where('name', 'like', "%$v%")->orWhere('email', 'like', "%$v%")))->paginate(15)->withQueryString();

        return view('admin.parents.index', compact('parents'));
    }

    public function create(): View
    {
        return $this->form(new Guardian);
    }

    public function edit(Guardian $parent): View
    {
        return $this->form($parent);
    }

    private function form(Guardian $parent): View
    {
        return view('admin.parents.form', compact('parent') + ['students' => Student::with('user')->get()]);
    }

    public function store(GuardianRequest $r): RedirectResponse
    {
        $g = DB::transaction(function () use ($r) {
            $u = User::create($r->only('name', 'email', 'phone', 'password') + ['role' => 'parent', 'status' => $r->status]);
            $g = $u->guardian()->create($r->only('relationship', 'status'));
            $g->students()->sync($r->input('student_ids', []));
            AuditService::record('created', 'parents', $g);

            return $g;
        });

        return redirect()->route('admin.parents.show', $g)->with('success', 'تمت إضافة ولي الأمر.');
    }

    public function update(GuardianRequest $r, Guardian $parent): RedirectResponse
    {
        DB::transaction(function () use ($r, $parent) {
            $old = $parent->getAttributes();
            $d = $r->only('name', 'email', 'phone', 'status');
            if ($r->filled('password')) {
                $d['password'] = $r->password;
            }$parent->user->update($d);
            $parent->update($r->only('relationship', 'status'));
            $parent->students()->sync($r->input('student_ids', []));
            AuditService::record('updated', 'parents', $parent, $old);
        });

        return redirect()->route('admin.parents.show', $parent)->with('success', 'تم التحديث.');
    }

    public function show(Guardian $parent): View
    {
        return view('admin.parents.show',['parent' => $parent->load(['user', 'students.user'])]);
    }
}
