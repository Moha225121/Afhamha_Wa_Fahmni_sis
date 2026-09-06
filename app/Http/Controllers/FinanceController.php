<?php

namespace App\Http\Controllers;

use App\Models\AcademicYear;
use App\Models\Classroom;
use App\Models\Student;
use App\Models\User;
use App\Services\AccountPasswordService;
use App\Services\AuditService;
use App\Services\FinancialPdfService;
use App\Services\ParentPortalContext;
use App\Services\SchoolAccountService;
use App\Services\StudentFinanceService;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class FinanceController extends Controller
{
    private function access(Request $request, string $permission = 'finance.view'): void
    {
        abort_unless($request->user()?->status === 'active' && $request->user()->hasPermission($permission), 403);
    }

    public function index(Request $request, StudentFinanceService $finance)
    {
        $this->access($request);
        $request->validate(['academic_year_id' => 'nullable|integer|exists:academic_years,id', 'classroom_id' => 'nullable|integer|exists:classrooms,id', 'q' => 'nullable|string|max:100', 'grade' => 'nullable|string|max:255', 'status' => ['nullable', Rule::in(array_keys(StudentFinanceService::STATUSES))]]);
        $year = $request->integer('academic_year_id') ?: null;
        $rows = $finance->installments(null, $year);
        $students = Student::with('user', 'classroom')->when($request->q, fn ($q, $v) => $q->where(fn ($q) => $q->where('student_number', 'like', "%$v%")
            ->orWhereHas('user', fn ($u) => $u->where('name', 'like', "%$v%")->orWhere('email', 'like', "%$v%")->orWhere('phone', 'like', "%$v%"))
            ->orWhereHas('guardians.user', fn ($u) => $u->where('name', 'like', "%$v%")->orWhere('phone', 'like', "%$v%"))))
            ->when($request->classroom_id, fn ($q, $id) => $q->where('classroom_id', $id))
            ->when($request->grade, fn ($q, $grade) => $q->whereHas('classroom', fn ($c) => $c->where('name', $grade)))
            ->get()->map(function ($student) use ($finance, $rows) {
                $student->financial_summary = $finance->totals($rows->where('student_id', $student->id));

                return $student;
            })
            ->when($request->status, fn ($items, $status) => $items->filter(fn ($s) => $s->financial_summary['status'] === $status));
        $studentIds = $students->pluck('id');
        $rows = $rows->whereIn('student_id', $studentIds);
        $dashboard = $finance->totals($rows);
        $counts = $students->countBy(fn ($s) => $s->financial_summary['status']);
        $charts = [];
        $payments = DB::table('student_payments as p')->join('student_installments as i', 'i.id', '=', 'p.student_installment_id')->join('student_fees as f', 'f.id', '=', 'i.student_fee_id')->join('fee_templates as t', 't.id', '=', 'f.fee_template_id')->join('fee_types as ft', 'ft.id', '=', 't.fee_type_id')->join('academic_years as y', 'y.id', '=', 'f.academic_year_id')->where('p.status', 'posted')->whereIn('p.student_id', $studentIds)->when($year, fn ($q) => $q->where('f.academic_year_id', $year))->select('p.student_id', 'p.paid_on', 'p.amount_millimes', 'ft.name as fee_type', 'y.name as academic_year')->get();
        $byId = $students->keyBy('id');
        foreach (['الشهر' => fn ($p) => substr($p->paid_on, 0, 7), 'الصف' => fn ($p) => $byId[$p->student_id]->classroom?->name ?? 'بدون صف', 'السنة الدراسية' => fn ($p) => $p->academic_year, 'نوع الرسوم' => fn ($p) => $p->fee_type] as $label => $group) {
            $charts[$label] = $payments->groupBy($group)->map(fn ($items) => (int) $items->sum('amount_millimes'));
        }
        $page = max(1, $request->integer('page', 1));
        $students = new LengthAwarePaginator($students->forPage($page, 20)->values(), $students->count(), 20, $page, ['path' => $request->url(), 'query' => $request->query()]);

        return view('finance.index', compact('students', 'dashboard', 'counts', 'charts') + ['years' => AcademicYear::all(), 'classrooms' => Classroom::all(), 'upcoming' => $rows->filter(fn ($i) => $i->remaining_millimes > 0 && $i->due_date >= now()->toDateString())->take(10), 'statuses' => StudentFinanceService::STATUSES]);
    }

    public function show(Request $request, Student $student, StudentFinanceService $finance)
    {
        $this->access($request);

        return $this->statement($request, $student, $finance, 'admin.layout', true);
    }

    public function parent(Request $request, ParentPortalContext $context, StudentFinanceService $finance)
    {
        $guardian = $context->guardian($request);
        $children = $context->children($guardian);
        $student = $context->selectedStudent($guardian, $children, $request->query('student'));
        if (! $student) {
            return view('finance.no-children');
        }

        return $this->statement($request, $student, $finance, 'parent.layout', false, compact('children') + ['selectedStudent' => $student]);
    }

    public function student(Request $request, SchoolAccountService $accounts, StudentFinanceService $finance)
    {
        abort_unless($accounts->settings()['student_finance_visible'], 403);

        return $this->statement($request, $request->user()->student()->firstOrFail(), $finance, 'student.layout', false);
    }

    private function statement(Request $request, Student $student, StudentFinanceService $finance, string $layout, bool $manage, array $extra = [])
    {
        $request->validate(['academic_year_id' => 'nullable|integer|exists:academic_years,id']);
        $data = $finance->statement($student, $request->integer('academic_year_id') ?: null) + compact('layout', 'manage') + $extra + ['student' => $student->load('user', 'classroom.academicYear', 'guardians.user'), 'years' => AcademicYear::all(), 'methods' => DB::table('payment_methods')->where('active', true)->get(), 'statuses' => StudentFinanceService::STATUSES];
        if ($request->query('format') === 'pdf') {
            return app(FinancialPdfService::class)->download('finance.print-statement', $data, 'statement-'.$student->id.'.pdf');
        }

        return view('finance.statement', $data);
    }

    public function templates(Request $request, StudentFinanceService $finance)
    {
        abort_unless($request->user()->isAdmin(), 403);
        $templates = DB::table('fee_templates')->orderByDesc('id')->get()->map(function ($t) use ($finance) {
            $t->target_count = $finance->targets($t)->count();

            return $t;
        });

        return view('finance.templates', compact('templates') + ['types' => DB::table('fee_types')->where('active', true)->get(), 'years' => AcademicYear::all(), 'classrooms' => Classroom::all(), 'students' => Student::with('user')->get()]);
    }

    public function templateStore(Request $request, StudentFinanceService $finance)
    {
        abort_unless($request->user()->isAdmin(), 403);
        $data = $request->validate(['name' => 'required|string|max:255', 'description' => 'nullable|string|max:3000', 'amount' => 'required', 'fee_type_id' => ['required', Rule::exists('fee_types', 'id')->where('active', true)], 'academic_year_id' => 'required|exists:academic_years,id', 'grade' => 'nullable|string|max:255', 'classroom_id' => 'nullable|exists:classrooms,id', 'student_id' => 'nullable|exists:students,id', 'due_date' => 'required|date_format:Y-m-d', 'required' => 'required|boolean', 'active' => 'required|boolean', 'installments' => 'nullable|array|max:36', 'installments.*.name' => 'required|string|max:100', 'installments.*.amount' => 'required', 'installments.*.due_date' => 'required|date_format:Y-m-d']);
        $finance->createTemplate($data);

        return back()->with('success', 'تم إنشاء الرسوم. راجع عدد الطلاب ثم اضغط تطبيق الرسوم لإسنادها.');
    }

    public function apply(Request $request, int $template, StudentFinanceService $finance)
    {
        abort_unless($request->user()->isAdmin(), 403);
        $count = $finance->apply($template);

        return back()->with('success', 'تم تطبيق الرسوم على '.$count.' طالبًا دون تكرار.');
    }

    public function templateToggle(Request $request, int $template)
    {
        abort_unless($request->user()->isAdmin(), 403);
        $data = $request->validate(['active' => 'required|boolean']);
        DB::transaction(function () use ($template, $data): void {
            $old = DB::table('fee_templates')->where('id', $template)->lockForUpdate()->first();
            abort_unless($old, 404);
            DB::table('fee_templates')->where('id', $template)->update($data + ['updated_at' => now()]);
            app(SchoolAccountService::class)->audit('fee_status_changed', 'fee_templates', $template, ['active' => $old->active], $data);
        });

        return back()->with('success', 'تم تحديث حالة الرسوم.');
    }

    public function pay(Request $request, Student $student, StudentFinanceService $finance)
    {
        $this->access($request, 'finance.pay');
        $data = $request->validate(['student_installment_id' => 'required|integer', 'amount' => 'required', 'paid_on' => 'required|date_format:Y-m-d|before_or_equal:today', 'payment_method_id' => ['required', Rule::exists('payment_methods', 'id')->where('active', true)], 'reference' => 'nullable|string|max:255', 'notes' => 'nullable|string|max:2000', 'request_key' => 'required|uuid']);
        $receipt = $finance->pay($student, $data);

        return redirect()->route('finance.receipts.show', $receipt)->with('success', 'تم تسجيل الدفعة وإصدار الإيصال.');
    }

    public function void(Request $request, Student $student, int $payment, StudentFinanceService $finance)
    {
        abort_unless($request->user()->isAdmin(), 403);
        $data = $request->validate(['reason' => 'required|string|min:3|max:2000']);
        $finance->void($student, $payment, $data['reason']);

        return back()->with('success', 'تم إلغاء الدفعة وحفظ أثرها في سجل المعاملات.');
    }

    public function discount(Request $request, Student $student, StudentFinanceService $finance)
    {
        abort_unless($request->user()->isAdmin(), 403);
        $data = $request->validate(['student_fee_id' => 'required|integer', 'type' => ['required', Rule::in(['fixed', 'percentage', 'scholarship', 'waiver'])], 'value' => 'nullable|required_unless:type,waiver', 'reason' => 'required|string|min:3|max:2000']);
        $finance->discount($student, $data);

        return back()->with('success', 'تم اعتماد الخصم وتحديث الرصيد.');
    }

    public function receipt(Request $request, int $receipt, ParentPortalContext $context)
    {
        $record = DB::table('receipts as r')->join('student_payments as p', 'p.id', '=', 'r.student_payment_id')->where('r.id', $receipt)->select('r.*', 'p.student_id', 'p.status', 'p.void_reason')->first();
        abort_unless($record, 404);
        $student = Student::findOrFail($record->student_id);
        if ($request->user()->isParent()) {
            $context->assertChild($context->guardian($request), $student);
        } elseif ($request->user()->isStudent()) {
            abort_unless($request->user()->student?->id === $student->id && app(SchoolAccountService::class)->settings()['student_finance_visible'], 404);
        } else {
            $this->access($request);
        }
        $data = ['receipt' => $record, 'snapshot' => json_decode($record->snapshot, true)];
        if ($request->query('format') === 'pdf') {
            return app(FinancialPdfService::class)->download('finance.receipt', $data + ['pdf' => true], $record->number.'.pdf');
        }

        return view('finance.receipt', $data);
    }

    public function officerStore(Request $request, AccountPasswordService $passwords)
    {
        abort_unless($request->user()->isAdmin(), 403);
        $data = $request->validate(['name' => 'required|string|max:255', 'email' => 'required|email|unique:users', 'password' => 'nullable|string|min:8|confirmed']);
        DB::transaction(function () use ($data, $passwords): void {
            app(SchoolAccountService::class)->settings(true);
            $user = User::create(array_replace($data, ['password' => $passwords->forNewAccount($data['password'] ?? null), 'role' => 'financial_officer', 'status' => 'active']));
            AuditService::record('created', 'financial_officers', $user);
        });

        return back()->with('success', 'تم إنشاء حساب الموظف المالي.');
    }
}
