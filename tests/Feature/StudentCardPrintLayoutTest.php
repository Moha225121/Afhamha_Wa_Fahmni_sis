<?php

namespace Tests\Feature;

use App\Models\Student;
use App\Models\User;
use App\Services\StudentCardPdfService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class StudentCardPrintLayoutTest extends TestCase
{
    use RefreshDatabase;

    public static function cardCounts(): array
    {
        return [[1, 1], [4, 1], [8, 1], [10, 1], [11, 2], [20, 2], [25, 3]];
    }

    public function test_admin_preview_places_all_students_in_ten_card_a4_pages_with_fixed_card_dimensions(): void
    {
        $this->actingAs($this->admin());
        $this->createStudents(25);

        $response = $this->get(route('admin.students.cards'));

        $response->assertOk()
            ->assertSee('class="page preview-page"', false)
            ->assertSee('width:84mm;height:53mm;min-width:84mm;min-height:53mm;max-width:84mm;max-height:53mm;box-sizing:border-box', false)
            ->assertSee('طباعة المحدد', false)
            ->assertSee('تنزيل PDF للمحدد', false)
            ->assertSee('student-card-pagination.js', false);

        $this->assertSame(3, substr_count($response->getContent(), 'class="page preview-page"'));
        $this->assertSame(25, substr_count($response->getContent(), 'class="card-checkbox"'));
    }

    #[DataProvider('cardCounts')]
    public function test_selected_pdf_has_exact_page_count_and_a4_portrait_geometry(int $cardCount, int $expectedPages): void
    {
        $this->actingAs($this->admin());
        $students = $this->createStudents($cardCount);

        $response = $this->get(route('admin.students.cards', [
            'format' => 'pdf',
            'student_ids' => $students,
        ]));

        $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $pdf = $response->getContent();
        preg_match_all('/\/Type\s*\/Page\b/', $pdf, $pageObjects);
        preg_match('/\/Type\s*\/Pages\b[\s\S]*?\/Count\s+(\d+)/', $pdf, $pageTreeCount);
        preg_match('/\/MediaBox\s*\[\s*0\s+0\s+([\d.]+)\s+([\d.]+)\s*\]/', $pdf, $pageBox);

        $this->assertCount($expectedPages, $pageObjects[0], 'The PDF must contain exactly the expected number of page objects.');
        $this->assertSame($expectedPages, (int) ($pageTreeCount[1] ?? 0), 'The PDF page tree must not contain blank or missing pages.');
        $this->assertEqualsWithDelta(595.28, (float) ($pageBox[1] ?? 0), 0.1, 'A4 width in PDF points');
        $this->assertEqualsWithDelta(841.89, (float) ($pageBox[2] ?? 0), 0.1, 'A4 height in PDF points');
    }

    public function test_pdf_export_includes_only_selected_students_and_rejects_an_empty_selection(): void
    {
        $this->actingAs($this->admin());
        $students = $this->createStudents(4);
        $selectedIds = [$students[1], $students[3]];
        $pdf = \Mockery::mock(StudentCardPdfService::class);
        $pdf->shouldReceive('downloadBulk')
            ->once()
            ->withArgs(fn ($selected, $filename) => $selected->pluck('id')->values()->all() === $selectedIds && $filename === 'student-cards.pdf')
            ->andReturn(response('selected-only-pdf', 200, ['Content-Type' => 'application/pdf']));
        $this->app->instance(StudentCardPdfService::class, $pdf);

        $this->get(route('admin.students.cards', ['format' => 'pdf', 'student_ids' => $selectedIds]))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertContent('selected-only-pdf');

        $this->get(route('admin.students.cards', ['format' => 'pdf']))
            ->assertSessionHasErrors('student_ids');
    }

    public function test_single_student_pdf_returns_one_a4_portrait_page(): void
    {
        $this->actingAs($this->admin());
        $studentId = $this->createStudents(1)[0];

        $response = $this->get(route('admin.students.account-card', [
            'student' => $studentId,
            'format' => 'pdf',
        ]));

        $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $pdf = $response->getContent();
        preg_match_all('/\/Type\s*\/Page\b/', $pdf, $pageObjects);
        preg_match('/\/MediaBox\s*\[\s*0\s+0\s+([\d.]+)\s+([\d.]+)\s*\]/', $pdf, $pageBox);

        $this->assertCount(1, $pageObjects[0]);
        $this->assertEqualsWithDelta(595.28, (float) ($pageBox[1] ?? 0), 0.1);
        $this->assertEqualsWithDelta(841.89, (float) ($pageBox[2] ?? 0), 0.1);
    }

    public function test_long_arabic_names_and_missing_optional_data_render_without_changing_card_size(): void
    {
        $this->actingAs($this->admin());
        $name = 'عبد الرحمن محمد عبد الرحمن الشريف الطويل';
        $this->createStudents(1, [$name]);

        $response = $this->get(route('admin.students.cards'));

        $response->assertOk()
            ->assertSee($name)
            ->assertSee('صورة الطالب')
            ->assertSee('الصف: —')
            ->assertSee('width:84mm;height:53mm', false);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'status' => 'active']);
    }

    /** @return list<int> */
    private function createStudents(int $count, array $names = []): array
    {
        $ids = [];
        for ($index = 0; $index < $count; $index++) {
            $user = User::factory()->create([
                'name' => $names[$index] ?? sprintf('طالب اختبار %02d', $index + 1),
                'email' => sprintf('card-%02d-%s@example.test', $index + 1, uniqid()),
                'role' => 'student',
                'status' => 'active',
            ]);
            $student = Student::create([
                'user_id' => $user->id,
                'student_number' => sprintf('QA-%04d', $index + 1),
                'status' => 'active',
            ]);
            $ids[] = $student->id;
        }

        return $ids;
    }
}
