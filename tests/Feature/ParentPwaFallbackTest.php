<?php

namespace Tests\Feature;

use App\Support\SchoolBranding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ParentPwaFallbackTest extends TestCase
{
    use RefreshDatabase;

    public static function unusableLogos(): array
    {
        return [
            'not configured' => [null],
            'missing historical upload' => ['school-branding/missing-logo.png'],
            'unsupported stored file' => ['school-branding/unsupported.txt'],
        ];
    }

    #[DataProvider('unusableLogos')]
    public function test_icon_falls_back_to_approved_png_when_configured_logo_is_unavailable(?string $logo): void
    {
        $this->configureLogo($logo);
        $response = $this->get('/school-app-icon')->assertOk()
            ->assertHeader('Content-Type', 'image/png')->assertHeader('Cache-Control', 'no-store, private');
        $dimensions = getimagesizefromstring($response->getContent());
        $this->assertSame([192, 192], [$dimensions[0], $dimensions[1]]);
        $this->assertSame(SchoolBranding::iconPng(192), $response->getContent());
    }

    #[DataProvider('unusableLogos')]
    public function test_manifest_shortcut_icons_resolve_without_changing_the_configured_name(?string $logo): void
    {
        $this->configureLogo($logo);
        DB::table('settings')->updateOrInsert(['key' => 'school_name'], ['value' => 'مدرسة اختبار مخصصة', 'group' => 'school']);
        $manifest = $this->get('/parent-app.webmanifest')->assertOk()
            ->assertJsonPath('name', 'مدرسة اختبار مخصصة - بوابة ولي الأمر')
            ->assertJsonPath('short_name', 'مدرسة اختبار مخصصة')->json();

        foreach ($manifest['shortcuts'] as $shortcut) {
            $this->get($shortcut['icons'][0]['src'])->assertOk()->assertHeader('Content-Type', 'image/png');
        }
    }

    public function test_every_parent_and_student_precache_asset_exists_or_resolves_publicly(): void
    {
        $this->configureLogo('school-branding/missing-logo.png');
        foreach (['parent-sw.js', 'student-sw.js'] as $worker) {
            $script = file_get_contents(public_path($worker));
            preg_match('/const STATIC_ASSETS = \[(.*?)\];/s', $script, $list);
            preg_match_all("~['\"](/[^'\"]+)['\"]~", $list[1], $assets);
            preg_match("~const OFFLINE_URL = ['\"]([^'\"]+)['\"]~", $script, $offline);
            $this->assertNotEmpty($assets[1], $worker);
            foreach ([...$assets[1], $offline[1]] as $asset) {
                $path = parse_url($asset, PHP_URL_PATH);
                if (is_file(public_path(ltrim($path, '/')))) {
                    $this->assertIsReadable(public_path(ltrim($path, '/')));
                    continue;
                }
                $response = $this->get($asset);
                if ($response->isRedirect()) {
                    $target = parse_url($response->headers->get('Location'), PHP_URL_PATH);
                    $this->assertFileExists(public_path(ltrim($target, '/')), $worker.' redirect '.$asset);
                } else {
                    $response->assertOk();
                }
            }
        }
    }

    private function configureLogo(?string $logo): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('school-branding/unsupported.txt', 'Not an image.');
        if ($logo !== null) {
            DB::table('settings')->updateOrInsert(['key' => 'school_logo'], ['value' => $logo, 'group' => 'school']);
        }
    }
}
