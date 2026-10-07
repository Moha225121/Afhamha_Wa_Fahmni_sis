<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\SchoolAccountService;
use App\Services\FinancialPdfService;
use App\Support\SchoolBranding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SchoolBrandingTest extends TestCase
{
    use RefreshDatabase;

    public function test_old_settings_cannot_replace_the_approved_login_identity(): void
    {
        $this->obsoleteBranding();
        $response = $this->get('/login')->assertOk()
            ->assertSee('icons/nasr-daem-logo.jpg')
            ->assertSee('css/school-branding.css')
            ->assertSee('content="#101632"', false)
            ->assertDontSee('other-school.jpg')
            ->assertDontSee('#6D4AFF');

        $this->assertSame(1, substr_count($response->getContent(), 'rel="manifest"'));
        $this->assertSame(1, substr_count($response->getContent(), 'rel="icon"'));
    }

    public function test_installation_icons_have_real_declared_dimensions_and_the_approved_artwork(): void
    {
        foreach ([180, 192, 512] as $size) {
            $response = $this->get('/school-branding/icon-'.$size.'.png')->assertOk()->assertHeader('Content-Type', 'image/png');
            $info = getimagesizefromstring($response->getContent());
            $this->assertSame([$size, $size], [$info[0], $info[1]]);
            $image = imagecreatefromstring($response->getContent());
            $this->assertSame(0xFFFFFF, imagecolorat($image, 0, 0));
            $this->assertNotSame(0xFFFFFF, imagecolorat($image, (int) ($size * .5), (int) ($size * .45)));
            imagedestroy($image);
        }
        $this->get('/school-branding/icon-999.png')->assertNotFound();
        $this->get('/favicon.ico')->assertRedirect('/icons/nasr-daem-logo.jpg');
    }

    public function test_student_cards_and_pdf_use_the_original_logo_despite_old_settings(): void
    {
        $this->obsoleteBranding();
        $this->actingAs(User::factory()->create(['role' => 'admin', 'status' => 'active']));
        $student = app(SchoolAccountService::class)->create('student', ['name' => 'طالب النصر', 'first_name_en' => 'Ali', 'password' => 'password123'], ['status' => 'active']);
        $url = route('admin.students.account-card', $student);
        $this->get($url)->assertOk()->assertSee(SchoolBranding::LOGO)->assertDontSee('other-school.jpg');
        $response = $this->get($url.'?format=pdf')->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $response->getContent());
        $this->assertStringContainsString(file_get_contents(public_path(SchoolBranding::LOGO)), $response->getContent());
        $this->get(route('admin.students.cards'))->assertOk()->assertSee('طالب النصر')->assertSee(SchoolBranding::LOGO);
    }

    public function test_saving_settings_keeps_the_school_identity_fixed(): void
    {
        $this->obsoleteBranding();
        $this->actingAs(User::factory()->create(['role' => 'admin', 'status' => 'active']));
        $this->put(route('admin.settings.update'), $this->settingsPayload() + ['theme_color' => '#6D4AFF'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('settings', ['key' => 'school_logo', 'value' => SchoolBranding::LOGO]);
        $this->assertDatabaseHas('settings', ['key' => 'theme_color', 'value' => SchoolBranding::NAVY]);
        $this->get(route('admin.settings.index'))->assertOk()->assertDontSee('name="school_logo"', false)->assertDontSee('name="theme_color"', false);
    }

    public function test_financial_pdf_uses_the_approved_logo_even_if_the_caller_supplies_another(): void
    {
        $this->obsoleteBranding();
        $response = app(FinancialPdfService::class)->download('finance.receipt', [
            'receipt' => (object) ['number' => 'QA-1', 'status' => 'paid'],
            'snapshot' => ['school' => 'مدرسة النصر الدائم', 'amount_millimes' => 1000, 'remaining_millimes' => 0],
            'pdfLogo' => 'data:image/jpeg;base64,invalid-replacement',
        ], 'receipt.pdf');

        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringContainsString(file_get_contents(public_path(SchoolBranding::LOGO)), $response->getContent());
    }

    public function test_settings_reject_replacement_logo_uploads(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin', 'status' => 'active']));
        $this->put(route('admin.settings.update'), $this->settingsPayload() + ['school_logo' => UploadedFile::fake()->image('replacement.jpg')])->assertSessionHasErrors('school_logo');
    }

    private function obsoleteBranding(): void
    {
        foreach (['school_logo' => 'school-branding/other-school.jpg', 'theme_color' => '#6D4AFF'] as $key => $value) {
            DB::table('settings')->updateOrInsert(['key' => $key], ['value' => $value, 'group' => 'school']);
        }
    }

    private function settingsPayload(): array
    {
        $times = [];
        for ($day = 0; $day <= 4; $day++) {
            for ($period = 1; $period <= 6; $period++) {
                $minutes = 510 + ($period - 1) * 45 + ($period > 3 ? 30 : 0);
                $times[$day][$period] = ['starts_at' => sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60), 'ends_at' => sprintf('%02d:%02d', intdiv($minutes + 45, 60), ($minutes + 45) % 60)];
            }
        }

        return ['school_name' => 'مدرسة النصر الدائم', 'timezone' => 'Africa/Tripoli', 'academic_year_name' => '2026/2027', 'academic_year_starts_at' => '2026-09-01', 'academic_year_ends_at' => '2027-06-30', 'periods_sunday' => 6, 'periods_monday' => 6, 'periods_tuesday' => 6, 'periods_wednesday' => 6, 'periods_thursday' => 6, 'break_after_period' => 3, 'break_duration_minutes' => 30, 'period_times' => $times];
    }
}
