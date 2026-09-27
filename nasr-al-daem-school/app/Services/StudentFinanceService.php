<?php

namespace App\Services;

use App\Models\Student;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StudentFinanceService
{
    public const STATUSES = ['unpaid' => 'غير مدفوع', 'partially_paid' => 'مدفوع جزئيًا', 'paid' => 'مدفوع بالكامل', 'overdue' => 'متأخر', 'no_fees' => 'بدون رسوم'];

    public function installments(?Student $student = null, ?int $year = null): Collection
    {
        $payments = DB::table('student_payments')->where('status', 'posted')->select('student_installment_id')->selectRaw('SUM(amount_millimes) as paid_millimes')->groupBy('student_installment_id');

        return DB::table('student_installments as i')->join('student_fees as f', 'i.student_fee_id', '=', 'f.id')
            ->leftJoinSub($payments, 'p', 'i.id', '=', 'p.student_installment_id')
            ->when($student, fn ($q) => $q->where('f.student_id', $student->id))
            ->when($year, fn ($q) => $q->where('f.academic_year_id', $year))
            ->select('i.*', 'f.student_id', 'f.academic_year_id', 'f.name as fee_name', 'f.fee_template_id')->selectRaw('COALESCE(p.paid_millimes, 0) as paid_millimes')
            ->orderBy('i.due_date')->orderBy('i.id')->get()->map(function ($row) {
                $row->net_millimes = (int) $row->amount_millimes - (int) $row->discount_millimes;
                $row->remaining_millimes = $row->net_millimes - (int) $row->paid_millimes;
                $row->status = $row->remaining_millimes <= 0 ? 'paid' : ($row->due_date < now()->toDateString() ? 'overdue' : ((int) $row->paid_millimes > 0 ? 'partially_paid' : 'unpaid'));

                return $row;
            });
    }

    public function totals(Collection $rows): array
    {
        $total = (int) $rows->sum('amount_millimes');
        $discount = (int) $rows->sum('discount_millimes');
        $paid = (int) $rows->sum('paid_millimes');
        $remaining = $total - $discount - $paid;
        $overdue = (int) $rows->where('status', 'overdue')->sum('remaining_millimes');

        return ['total' => $total, 'discount' => $discount, 'net' => $total - $discount, 'paid' => $paid, 'remaining' => $remaining, 'overdue' => $overdue, 'rate' => ($total - $discount) > 0 ? round($paid * 100 / ($total - $discount), 1) : 0, 'status' => $rows->isEmpty() ? 'no_fees' : ($remaining <= 0 ? 'paid' : ($overdue > 0 ? 'overdue' : ($paid > 0 ? 'partially_paid' : 'unpaid')))];
    }

    public function statement(Student $student, ?int $year = null): array
    {
        $installments = $this->installments($student, $year);
        $fees = DB::table('student_fees')->where('student_id', $student->id)->when($year, fn ($q) => $q->where('academic_year_id', $year))->get();
        $payments = DB::table('student_payments as p')->join('student_installments as i', 'i.id', '=', 'p.student_installment_id')->join('payment_methods as m', 'm.id', '=', 'p.payment_method_id')->leftJoin('receipts as r', 'r.student_payment_id', '=', 'p.id')->where('p.student_id', $student->id)->whereIn('i.student_fee_id', $fees->pluck('id'))->select('p.*', 'm.name as method', 'r.id as receipt_id', 'r.number as receipt_number')->orderByDesc('p.paid_on')->orderByDesc('p.id')->get();
        $transactions = DB::table('financial_transactions')->where('student_id', $student->id)->whereIn('student_fee_id', $fees->pluck('id'))->orderByDesc('id')->get();
        $discounts = DB::table('discounts')->whereIn('student_fee_id', $fees->pluck('id'))->get();

        return compact('installments', 'fees', 'payments', 'transactions', 'discounts') + ['totals' => $this->totals($installments)];
    }

    public function createTemplate(array $data): int
    {
        return DB::transaction(function () use ($data) {
            $amount = Money::parse($data['amount']);
            $this->ensure($amount > 0, 'amount', 'يجب أن يكون مبلغ الرسوم أكبر من صفر.');
            $plan = [];
            foreach ($data['installments'] ?? [] as $item) {
                $part = Money::parse($item['amount'], 'installments');
                $this->ensure($part > 0, 'installments', 'كل قسط يجب أن يكون أكبر من صفر.');
                $plan[] = ['name' => $item['name'], 'amount_millimes' => $part, 'due_date' => $item['due_date']];
            }
            if ($plan === []) {
                $plan[] = ['name' => 'الدفعة الكاملة', 'amount_millimes' => $amount, 'due_date' => $data['due_date']];
            }
            $this->ensure(array_sum(array_column($plan, 'amount_millimes')) === $amount, 'installments', 'مجموع الأقساط يجب أن يساوي مبلغ الرسوم بالضبط.');
            unset($data['amount'], $data['installments']);
            $id = DB::table('fee_templates')->insertGetId($data + ['amount_millimes' => $amount, 'installment_plan' => json_encode($plan), 'created_by' => auth()->id(), 'created_at' => now(), 'updated_at' => now()]);
            $this->audit('fee_created', 'fee_templates', $id, [], $data + ['amount_millimes' => $amount, 'installment_plan' => $plan]);

            return $id;
        });
    }

    public function targets(object $template)
    {
        return Student::where('status', 'active')->whereHas('classroom', fn ($q) => $q->where('academic_year_id', $template->academic_year_id))
            ->when($template->grade, fn ($q) => $q->whereHas('classroom', fn ($c) => $c->where('name', $template->grade)))
            ->when($template->classroom_id, fn ($q) => $q->where('classroom_id', $template->classroom_id))
            ->when($template->student_id, fn ($q) => $q->whereKey($template->student_id));
    }

    public function apply(int $templateId): int
    {
        return DB::transaction(function () use ($templateId) {
            if (DB::connection()->getDriverName() === 'sqlite') {
                DB::table('fee_templates')->where('id', $templateId)->update(['id' => $templateId]);
            }
            $template = DB::table('fee_templates')->where('id', $templateId)->lockForUpdate()->first();
            abort_unless($template, 404);
            $this->ensure((bool) $template->active, 'fee', 'لا يمكن تطبيق رسوم غير نشطة.');
            $count = 0;
            foreach ($this->targets($template)->orderBy('id')->lockForUpdate()->get() as $student) {
                if (DB::table('student_fees')->where('student_id', $student->id)->where('fee_template_id', $templateId)->exists()) {
                    continue;
                }
                $feeId = DB::table('student_fees')->insertGetId(['student_id' => $student->id, 'fee_template_id' => $templateId, 'academic_year_id' => $template->academic_year_id, 'name' => $template->name, 'amount_millimes' => $template->amount_millimes, 'created_at' => now(), 'updated_at' => now()]);
                foreach (json_decode($template->installment_plan, true) as $item) {
                    DB::table('student_installments')->insert($item + ['student_fee_id' => $feeId, 'created_at' => now(), 'updated_at' => now()]);
                }
                $this->ledger($student->id, $feeId, 'charge', (int) $template->amount_millimes, $template->name);
                $this->audit('fee_assigned', 'student_fees', $feeId, [], ['student_id' => $student->id, 'amount_millimes' => $template->amount_millimes]);
                $count++;
            }

            return $count;
        }, 3);
    }

    public function pay(Student $student, array $data): int
    {
        return DB::transaction(function () use ($student, $data) {
            $this->lockStudent($student);
            $amount = Money::parse($data['amount']);
            $existing = DB::table('student_payments')->where('request_key', $data['request_key'])->first();
            if ($existing) {
                $this->ensure((int) $existing->student_id === $student->id && (int) $existing->amount_millimes === $amount && (int) $existing->student_installment_id === (int) $data['student_installment_id'] && (int) $existing->payment_method_id === (int) $data['payment_method_id'] && $existing->paid_on === $data['paid_on'], 'request_key', 'استُخدم طلب الدفع هذا لعملية أخرى. حدّث الصفحة.');

                return (int) DB::table('receipts')->where('student_payment_id', $existing->id)->value('id');
            }
            $installment = $this->installments($student)->firstWhere('id', (int) $data['student_installment_id']);
            abort_unless($installment, 404);
            $this->ensure($amount > 0 && $amount <= $installment->remaining_millimes, 'amount', 'المبلغ يجب أن يكون أكبر من صفر وألا يتجاوز المتبقي على القسط.');
            $paymentId = DB::table('student_payments')->insertGetId(['student_id' => $student->id, 'student_installment_id' => $installment->id, 'payment_method_id' => $data['payment_method_id'], 'amount_millimes' => $amount, 'paid_on' => $data['paid_on'], 'reference' => $data['reference'] ?? null, 'notes' => $data['notes'] ?? null, 'request_key' => $data['request_key'], 'recorded_by' => auth()->id(), 'created_at' => now(), 'updated_at' => now()]);
            $number = 'REC-'.substr($data['paid_on'], 0, 4).'-'.str_pad((string) $paymentId, 6, '0', STR_PAD_LEFT);
            $totals = $this->totals($this->installments($student, (int) $installment->academic_year_id));
            $student->loadMissing('user', 'classroom.academicYear', 'guardians.user');
            $settings = app(SchoolAccountService::class)->settings();
            $snapshot = ['school' => $settings['school_name'], 'student' => $student->user->name, 'student_number' => $student->student_number, 'email' => $student->user->email, 'classroom' => $student->classroom?->name, 'academic_year' => DB::table('academic_years')->where('id', $installment->academic_year_id)->value('name'), 'guardians' => $student->guardians->pluck('user.name')->join('، '), 'fee' => $installment->fee_name, 'installment' => $installment->name, 'amount_millimes' => $amount, 'remaining_millimes' => $totals['remaining'], 'paid_on' => $data['paid_on'], 'method' => DB::table('payment_methods')->where('id', $data['payment_method_id'])->value('name'), 'employee' => auth()->user()?->name, 'reference' => $data['reference'] ?? null];
            $receiptId = DB::table('receipts')->insertGetId(['student_payment_id' => $paymentId, 'number' => $number, 'snapshot' => json_encode($snapshot, JSON_UNESCAPED_UNICODE), 'created_at' => now(), 'updated_at' => now()]);
            $this->ledger($student->id, $installment->student_fee_id, 'payment', -$amount, 'إيصال '.$number, $paymentId);
            $this->audit('payment_recorded', 'student_payments', $paymentId, [], $snapshot + ['receipt_number' => $number]);
            app(PortalEventService::class)->notify($student, 'payment:'.$paymentId, ['title' => 'تسجيل دفعة', 'body' => 'تم تسجيل دفعة بقيمة '.Money::format($amount).' للطالب '.$student->user->name.'. المتبقي '.Money::format($totals['remaining']).'.', 'url' => route('parent.finance', ['student' => $student->id]), 'category' => 'finance']);

            return $receiptId;
        }, 3);
    }

    public function void(Student $student, int $paymentId, string $reason): void
    {
        DB::transaction(function () use ($student, $paymentId, $reason): void {
            $this->lockStudent($student);
            $payment = DB::table('student_payments')->where('student_id', $student->id)->where('id', $paymentId)->lockForUpdate()->first();
            abort_unless($payment, 404);
            if ($payment->status === 'void') {
                return;
            }
            DB::table('student_payments')->where('id', $paymentId)->update(['status' => 'void', 'void_reason' => $reason, 'voided_by' => auth()->id(), 'voided_at' => now(), 'updated_at' => now()]);
            $feeId = DB::table('student_installments')->where('id', $payment->student_installment_id)->value('student_fee_id');
            $this->ledger($student->id, $feeId, 'payment_void', (int) $payment->amount_millimes, $reason, $paymentId);
            $this->audit('payment_voided', 'student_payments', $paymentId, ['status' => $payment->status], ['status' => 'void', 'reason' => $reason]);
            app(PortalEventService::class)->notify($student, 'payment-void:'.$paymentId, ['title' => 'إلغاء دفعة', 'body' => 'تم إلغاء دفعة بقيمة '.Money::format((int) $payment->amount_millimes).' للطالب '.$student->user->name.'. راجع كشف الحساب.', 'url' => route('parent.finance', ['student' => $student->id]), 'category' => 'finance']);
        }, 3);
    }

    public function discount(Student $student, array $data): void
    {
        DB::transaction(function () use ($student, $data): void {
            $this->lockStudent($student);
            $fee = DB::table('student_fees')->where('student_id', $student->id)->where('id', $data['student_fee_id'])->first();
            abort_unless($fee, 404);
            $rows = $this->installments($student)->where('student_fee_id', $fee->id);
            $remaining = (int) $rows->sum('remaining_millimes');
            if ($data['type'] === 'percentage') {
                $this->ensure(Money::parse($data['value'], 'value') <= 100000, 'value', 'النسبة يجب ألا تتجاوز 100%.');
            }
            $amount = match ($data['type']) {
                'waiver' => $remaining,
                'percentage' => intdiv((int) $fee->amount_millimes * Money::parse($data['value'], 'value') + 50000, 100000),
                default => Money::parse($data['value'], 'value'),
            };
            $this->ensure($amount > 0 && $amount <= $remaining, 'value', 'الخصم يجب أن يكون أكبر من صفر وألا يتجاوز المبلغ المتبقي.');
            $discountId = DB::table('discounts')->insertGetId(['student_fee_id' => $fee->id, 'type' => $data['type'], 'value' => $data['value'] ?? '0', 'amount_millimes' => $amount, 'reason' => $data['reason'], 'created_by' => auth()->id(), 'approved_by' => auth()->id(), 'created_at' => now(), 'updated_at' => now()]);
            $unallocated = $amount;
            foreach ($rows->sortByDesc('due_date') as $row) {
                $part = min($unallocated, $row->remaining_millimes);
                if ($part > 0) {
                    DB::table('student_installments')->where('id', $row->id)->increment('discount_millimes', $part, ['updated_at' => now()]);
                }
                $unallocated -= $part;
            }
            $this->ledger($student->id, $fee->id, 'discount', -$amount, $data['reason'], null, $discountId);
            $this->audit('discount_approved', 'discounts', $discountId, [], $data + ['amount_millimes' => $amount, 'approved_by' => auth()->id()]);
        }, 3);
    }

    public function remind(): int
    {
        $count = 0;
        foreach ($this->installments()->filter(fn ($i) => $i->remaining_millimes > 0 && $i->due_date <= now()->addDays(7)->toDateString()) as $row) {
            $student = Student::find($row->student_id);
            if (! $student) {
                continue;
            }
            DB::transaction(function () use ($student, $row, &$count): void {
                $event = 'installment:'.$row->id.':'.$row->due_date.':'.($row->status === 'overdue' ? 'overdue' : 'upcoming');
                if (! DB::table('financial_notifications')->insertOrIgnore(['event_key' => $event, 'student_id' => $student->id, 'created_at' => now()])) {
                    return;
                }
                app(PortalEventService::class)->notify($student, $event, ['title' => 'تذكير بالقسط', 'body' => 'تذكير: '.$row->name.' للطالب '.$student->user->name.' بقيمة متبقية '.Money::format($row->remaining_millimes).' وتاريخ استحقاق '.$row->due_date.'.', 'url' => route('parent.finance', ['student' => $student->id]), 'category' => 'finance']);
                $count++;
            });
        }

        return $count;
    }

    private function ledger(int $studentId, int $feeId, string $type, int $amount, string $description, ?int $paymentId = null, ?int $discountId = null): void
    {
        DB::table('financial_transactions')->insert(['student_id' => $studentId, 'student_fee_id' => $feeId, 'student_payment_id' => $paymentId, 'discount_id' => $discountId, 'type' => $type, 'amount_millimes' => $amount, 'description' => $description, 'user_id' => auth()->id(), 'created_at' => now()]);
    }

    private function audit(string $action, string $module, ?int $id, array $old, array $new): void
    {
        app(SchoolAccountService::class)->audit($action, $module, $id, $old, $new);
    }

    private function ensure(bool $condition, string $field, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages([$field => $message]);
        }
    }

    private function lockStudent(Student $student): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::table('students')->where('id', $student->id)->update(['id' => $student->id]);
        }
        Student::whereKey($student->id)->lockForUpdate()->firstOrFail();
    }
}
