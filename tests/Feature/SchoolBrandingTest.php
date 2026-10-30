<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\FinancialPdfService;
use App\Support\SchoolBranding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SchoolBrandingTest extends TestCase
{
    use RefreshDatabase;

    private function branding(): string
    {
        Storage::fake('public');
        $logo = UploadedFile::fake()->image('nisbia-test-logo.png', 80, 60);
        Storage::disk('public')->put('school-branding/nisbia-test.png', file_get_contents($logo->getRealPath()));
        foreach (['school_name' => 'مدرسة النسبية', 'school_logo' => 'school-branding/nisbia-test.png', 'theme_color' => '#008C95'] as $key => $value) {
            DB::table('settings')->updateOrInsert(['key' => $key], ['value' => $value, 'group' => 'school']);
        }

        return 'school-branding/nisbia-test.png';
    }

    public function test_configured_nisbiya_identity_is_preserved_instead_of_source_school_defaults(): void
    {
        $path = $this->branding();
        $this->get('/login')->assertOk()->assertSee('مدرسة النسبية')
            ->assertSee('storage/'.$path)->assertSee('#008C95')
            ->assertDontSeeText('النصر الدائم');
        $this->assertSame('data:image/png;base64,'.base64_encode(Storage::disk('public')->get($path)), SchoolBranding::logoDataUri());
    }

    public function test_installation_icons_have_real_declared_dimensions(): void
    {
        foreach ([180, 192, 512] as $size) {
            $response = $this->get('/school-branding/icon-'.$size.'.png')->assertOk()->assertHeader('Content-Type', 'image/png');
            $image = getimagesizefromstring($response->getContent());
            $this->assertSame([$size, $size], [$image[0], $image[1]]);
        }
        $this->get('/school-branding/icon-999.png')->assertNotFound();
    }

    public function test_missing_or_traversal_logo_settings_fall_back_without_rewriting_settings(): void
    {
        foreach (['missing-school-logo.png', '../outside.png', 'C:/outside.png'] as $value) {
            DB::table('settings')->updateOrInsert(['key' => 'school_logo'], ['value' => $value, 'group' => 'school']);
            $this->assertSame(asset(SchoolBranding::LOGO), SchoolBranding::logoUrl());
            $this->assertDatabaseHas('settings', ['key' => 'school_logo', 'value' => $value]);
        }
    }

    public function test_saving_school_settings_preserves_the_existing_logo_and_palette(): void
    {
        $path = $this->branding();
        $this->actingAs(User::factory()->create(['role' => 'admin', 'status' => 'active']))
            ->put(route('admin.settings.update'), $this->settingsPayload())->assertSessionHasNoErrors();
        $this->assertDatabaseHas('settings', ['key' => 'school_logo', 'value' => $path]);
        $this->assertDatabaseHas('settings', ['key' => 'theme_color', 'value' => '#008C95']);
        Storage::disk('public')->assertExists($path);
        $this->get(route('admin.settings.index'))->assertOk()->assertSee('name="school_logo"', false)->assertSee('name="theme_color"', false);
    }

    public function test_admin_can_still_upload_its_own_school_logo(): void
    {
        Storage::fake('public');
        $this->actingAs(User::factory()->create(['role' => 'admin', 'status' => 'active']))
            ->put(route('admin.settings.update'), $this->settingsPayload() + ['school_logo' => UploadedFile::fake()->image('nisbia.png')])
            ->assertSessionHasNoErrors();
        Storage::disk('public')->assertExists(DB::table('settings')->where('key', 'school_logo')->value('value'));
    }

    public function test_financial_pdf_uses_configured_school_logo_and_ignores_an_untrusted_caller_override(): void
    {
        $this->branding();
        $response = app(FinancialPdfService::class)->download('finance.receipt', [
            'receipt' => (object) ['number' => 'QA-ONLY', 'status' => 'paid'],
            'snapshot' => ['school' => 'مدرسة النسبية', 'amount_millimes' => 1000, 'remaining_millimes' => 0],
            'pdfLogo' => 'data:image/jpeg;base64,invalid-override',
        ], 'qa-receipt.pdf');
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF-', $response->getContent());
        $this->assertStringContainsString('/Subtype /Image', $response->getContent());
    }

    private function settingsPayload(): array
    {
        $times = [];
        for ($day = 0; $day <= 4; $day++) {
            for ($period = 1; $period <= 6; $period++) {
                $minutes = 510 + ($period - 1) * 45 + ($period > 3 ? 30 : 0);
                $times[$day][$period] = ['starts_at' => sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60),
                    'ends_at' => sprintf('%02d:%02d', intdiv($minutes + 45, 60), ($minutes + 45) % 60)];
            }
        }

        return ['school_name' => 'مدرسة النسبية', 'theme_color' => '#008C95', 'timezone' => 'Africa/Tripoli', 'academic_year_name' => '2026/2027',
            'academic_year_starts_at' => '2026-09-01', 'academic_year_ends_at' => '2027-06-30',
            'periods_sunday' => 6, 'periods_monday' => 6, 'periods_tuesday' => 6, 'periods_wednesday' => 6,
            'periods_thursday' => 6, 'break_after_period' => 3, 'break_duration_minutes' => 30, 'period_times' => $times];
    }
}
