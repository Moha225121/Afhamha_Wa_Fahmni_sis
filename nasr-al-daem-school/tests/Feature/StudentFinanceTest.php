<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Classroom;
use App\Models\Guardian;
use App\Models\Student;
use App\Models\User;
use App\Services\StudentFinanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class StudentFinanceTest extends TestCase
{
    use RefreshDatabase;

    private Student $student;

    private User $admin;

    private User $parent;

    private int $year;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $this->parent = User::factory()->create(['role' => 'parent', 'status' => 'active']);
        $guardian = Guardian::create(['user_id' => $this->parent->id, 'status' => 'active']);
        $year = AcademicYear::create(['name' => '2026/2027', 'starts_at' => '2026-09-01', 'ends_at' => '2027-06-01']);
        $this->year = $year->id;
        $classroom = Classroom::create(['name' => 'الأول', 'stage' => 'أساسي', 'section' => 'أ', 'academic_year_id' => $year->id]);
        $user = User::factory()->create(['name' => 'محمد أحمد', 'role' => 'student', 'status' => 'active']);
        $this->student = Student::create(['user_id' => $user->id, 'student_number' => 'S-FIN', 'classroom_id' => $classroom->id, 'status' => 'active']);
        $guardian->students()->attach($this->student);
        $this->actingAs($this->admin);
    }

    private function fee(): int
    {
        $id = app(StudentFinanceService::class)->createTemplate(['name' => 'دراسة', 'amount' => '6000', 'fee_type_id' => DB::table('fee_types')->value('id'), 'academic_year_id' => $this->year, 'due_date' => now()->addMonth()->toDateString(), 'required' => true, 'active' => true, 'installments' => [['name' => 'الأول', 'amount' => '2000', 'due_date' => now()->subDay()->toDateString()], ['name' => 'الثاني', 'amount' => '2000', 'due_date' => now()->addDays(4)->toDateString()], ['name' => 'الثالث', 'amount' => '2000', 'due_date' => now()->addMonths(2)->toDateString()]]]);
        $this->assertSame(1, app(StudentFinanceService::class)->apply($id));
        $this->assertSame(0, app(StudentFinanceService::class)->apply($id));

        return DB::table('student_installments')->min('id');
    }

    private function payment(int $installment, string $amount): array
    {
        return ['student_installment_id' => $installment, 'amount' => $amount, 'paid_on' => now()->toDateString(), 'payment_method_id' => DB::table('payment_methods')->value('id'), 'request_key' => (string) Str::uuid()];
    }

    public function test_partial_payment_full_payment_idempotency_receipt_and_notification(): void
    {
        $installment = $this->fee();
        $data = $this->payment($installment, '1500');
        $response = $this->post(route('finance.students.pay', $this->student), $data)->assertSessionHasNoErrors();
        $this->post(route('finance.students.pay', $this->student), $data)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('student_payments', 1);
        $this->assertDatabaseCount('receipts', 1);
        $response->assertRedirect(route('finance.receipts.show', 1));
        $this->get(route('finance.receipts.show', 1))->assertOk()->assertSee('REC-')->assertSee('محمد أحمد');
        $this->assertSame(4500000, app(StudentFinanceService::class)->statement($this->student)['totals']['remaining']);
        $this->assertSame(1, $this->parent->notifications()->count());
        $this->post(route('finance.students.pay', $this->student), $this->payment($installment, '500'))->assertSessionHasNoErrors();
        $this->assertSame('paid', app(StudentFinanceService::class)->installments($this->student)->first()->status);
        $this->post(route('finance.students.pay', $this->student), $this->payment($installment, '0.001'))->assertSessionHasErrors('amount');
    }

    public function test_void_preserves_receipt_and_restores_balance_once(): void
    {
        $id = $this->fee();
        $this->post(route('finance.students.pay', $this->student), $this->payment($id, '1000'))->assertSessionHasNoErrors();
        foreach ([1, 2] as $i) {
            $this->patch(route('finance.students.void', [$this->student, 1]), ['reason' => 'تسجيل غير صحيح'])->assertSessionHasNoErrors();
        }
        $this->assertDatabaseCount('receipts', 1);
        $this->assertSame(6000000, app(StudentFinanceService::class)->statement($this->student)['totals']['remaining']);
        $this->assertSame(1, DB::table('financial_transactions')->where('type', 'payment_void')->count());
        $this->get(route('finance.receipts.show', 1))->assertOk()->assertSee('إيصال ملغى');
    }

    public function test_discounts_cannot_exceed_balance_and_waiver_is_exact(): void
    {
        $this->fee();
        $fee = DB::table('student_fees')->value('id');
        $this->post(route('finance.students.discount', $this->student), ['student_fee_id' => $fee, 'type' => 'percentage', 'value' => '10', 'reason' => 'خصم إخوة'])->assertSessionHasNoErrors();
        $totals = app(StudentFinanceService::class)->statement($this->student)['totals'];
        $this->assertSame(600000, $totals['discount']);
        $this->assertSame(5400000, $totals['remaining']);
        $this->post(route('finance.students.discount', $this->student), ['student_fee_id' => $fee, 'type' => 'fixed', 'value' => '6000', 'reason' => 'خصم كبير'])->assertSessionHasErrors('value');
        $this->post(route('finance.students.discount', $this->student), ['student_fee_id' => $fee, 'type' => 'waiver', 'reason' => 'إعفاء من الإدارة'])->assertSessionHasNoErrors();
        $this->assertSame(0, app(StudentFinanceService::class)->statement($this->student)['totals']['remaining']);
    }

    public function test_parent_student_teacher_and_officer_permissions_and_pdf(): void
    {
        $id = $this->fee();
        $this->post(route('finance.students.pay', $this->student), $this->payment($id, '100'))->assertSessionHasNoErrors();
        $this->get(route('finance.index'))->assertOk();
        $this->get(route('finance.templates'))->assertOk();
        $this->actingAs($this->parent)->get(route('parent.finance', ['student' => $this->student->id]))->assertOk()->assertSee('S-FIN');
        $pdf = $this->get(route('finance.receipts.show', [1, 'format' => 'pdf']))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $pdf->getContent());
        $this->get(route('parent.finance', ['student' => $this->student->id, 'format' => 'pdf']))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $outsider = User::factory()->create(['role' => 'parent', 'status' => 'active']);
        Guardian::create(['user_id' => $outsider->id, 'status' => 'active']);
        $this->actingAs($outsider)->get(route('parent.finance', ['student' => $this->student->id]))->assertNotFound();
        $this->get(route('finance.receipts.show', 1))->assertNotFound();
        $this->actingAs($this->student->user)->get(route('student.finance'))->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'teacher', 'status' => 'active']))->get(route('finance.students.show', $this->student))->assertForbidden();
        $this->get(route('finance.receipts.show', [1, 'format' => 'pdf']))->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'financial_officer', 'status' => 'active']))->get(route('finance.index'))->assertOk();
        $this->post(route('finance.students.discount', $this->student), [])->assertForbidden();
        $this->patch(route('finance.students.void', [$this->student, 1]), ['reason' => 'غير مخول'])->assertForbidden();
    }

    public function test_installment_reminders_are_deduplicated(): void
    {
        $this->fee();
        $finance = app(StudentFinanceService::class);
        $this->assertSame(2, $finance->remind());
        $this->assertSame(0, $finance->remind());
        $this->assertSame(2, $this->parent->notifications()->count());
    }

    public function test_supervisor_financial_grants_control_payments_and_reports(): void
    {
        $id = $this->fee();
        $supervisor = User::factory()->create(['role' => 'supervisor', 'status' => 'active', 'financial_permissions' => ['finance.view']]);
        $this->actingAs($supervisor)->get(route('finance.index'))->assertOk()->assertDontSee('التحصيل حسب الشهر');
        $this->post(route('finance.students.pay', $this->student), $this->payment($id, '100'))->assertForbidden();
        $supervisor->update(['financial_permissions' => ['finance.view', 'finance.pay', 'finance.reports']]);
        $this->get(route('finance.index'))->assertOk()->assertSee('التحصيل حسب الشهر');
        $this->post(route('finance.students.pay', $this->student), $this->payment($id, '100'))->assertSessionHasNoErrors();
        $this->assertDatabaseCount('student_payments', 1);
    }
}
