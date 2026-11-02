<?php

namespace Tests\Feature;

use App\Models\Guardian;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Vite;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesStudentEducationFixtures;
use Tests\TestCase;

class PortalAssetAvailabilityTest extends TestCase
{
    use CreatesStudentEducationFixtures;
    use RefreshDatabase;

    private string $originalPublicPath;

    private string $assetPublicPath;

    protected function setUp(): void
    {
        parent::setUp();

        // Isolate manifests and the hot file from the developer's real build.
        // Unique paths also avoid Laravel's static manifest cache between tests.
        $this->originalPublicPath = public_path();
        $this->assetPublicPath = storage_path('framework/testing/portal-assets-'.Str::uuid());
        File::makeDirectory($this->assetPublicPath.'/build/assets', 0755, true);
        $this->app->usePublicPath($this->assetPublicPath);
        $this->app->make(Vite::class)
            ->useBuildDirectory('build')
            ->useHotFile($this->assetPublicPath.'/hot');
    }

    protected function tearDown(): void
    {
        try {
            if (isset($this->originalPublicPath)) {
                $this->app->usePublicPath($this->originalPublicPath);
            }
            if (isset($this->assetPublicPath)) {
                File::deleteDirectory($this->assetPublicPath);
            }
        } finally {
            parent::tearDown();
        }
    }

    public static function portalRoles(): array
    {
        return [
            'parent' => ['parent', 'لوحة ولي الأمر'],
            'teacher' => ['teacher', 'لوحة تحكم المعلم'],
            'student' => ['student', 'لوحة الطالب'],
            'admin' => ['admin', 'لوحة تحكم الإدارة'],
        ];
    }

    #[DataProvider('portalRoles')]
    public function test_dashboard_remains_available_without_a_build_manifest(string $role, string $title): void
    {
        $this->actingAs($this->portalUser($role))
            ->get(route($role.'.dashboard'))
            ->assertOk()
            ->assertSeeText($title)
            ->assertDontSee('resources/js/firebase-messaging.js', false)
            ->assertDontSee('Vite manifest', false);
    }

    #[DataProvider('portalRoles')]
    public function test_dashboard_remains_available_with_a_manifest_from_before_firebase(string $role, string $title): void
    {
        $this->writeManifest([
            'resources/js/app.js' => [
                'file' => 'assets/app-legacy.js',
                'src' => 'resources/js/app.js',
                'isEntry' => true,
            ],
        ]);

        $this->actingAs($this->portalUser($role))
            ->get(route($role.'.dashboard'))
            ->assertOk()
            ->assertSeeText($title)
            ->assertDontSee('resources/js/firebase-messaging.js', false)
            ->assertDontSee('Vite manifest', false);
    }

    #[DataProvider('portalRoles')]
    public function test_dashboard_loads_firebase_when_the_built_entry_is_available(string $role, string $title): void
    {
        $this->writeManifest([
            'resources/js/firebase-messaging.js' => [
                'file' => 'assets/firebase-messaging-current.js',
                'src' => 'resources/js/firebase-messaging.js',
                'isEntry' => true,
            ],
        ]);
        File::put($this->assetPublicPath.'/build/assets/firebase-messaging-current.js', '// Test fixture.');

        $this->actingAs($this->portalUser($role))
            ->get(route($role.'.dashboard'))
            ->assertOk()
            ->assertSeeText($title)
            ->assertSee('<script type="module"', false)
            ->assertSee('src="'.asset('build/assets/firebase-messaging-current.js').'"', false);
    }

    #[DataProvider('portalRoles')]
    public function test_dashboard_loads_firebase_from_the_vite_development_server(string $role, string $title): void
    {
        File::put($this->assetPublicPath.'/hot', 'http://localhost:5173');

        $this->actingAs($this->portalUser($role))
            ->get(route($role.'.dashboard'))
            ->assertOk()
            ->assertSeeText($title)
            ->assertSee('src="http://localhost:5173/@vite/client"', false)
            ->assertSee('src="http://localhost:5173/resources/js/firebase-messaging.js"', false);
    }

    private function writeManifest(array $manifest): void
    {
        File::put($this->assetPublicPath.'/build/manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));
    }

    private function portalUser(string $role): User
    {
        if ($role === 'student') {
            return $this->createStudentFixture('ASSETS')['user'];
        }

        $user = User::factory()->create(['role' => $role, 'status' => 'active']);

        if ($role === 'parent') {
            Guardian::create(['user_id' => $user->id, 'relationship' => 'father', 'status' => 'active']);
        } elseif ($role === 'teacher') {
            Teacher::create(['user_id' => $user->id, 'specialization' => 'رياضيات', 'status' => 'active']);
        }

        return $user;
    }
}
