<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ApplicationRouteSecurityTest extends TestCase
{
    use RefreshDatabase;

    private function protectedRoutes(): array
    {
        return array_values(array_filter(iterator_to_array(Route::getRoutes()), fn ($route) => preg_match('~^(admin|teacher|student|parent|supervisor|finance|staff)(/|$)~', $route->uri())
            || in_array($route->uri(), ['logout', 'fcm-tokens', 'push-subscriptions', 'push-subscriptions/{subscription}'], true)));
    }

    private function path($route): string
    {
        return '/'.preg_replace_callback('/\{([^}?]+)\??\}/', function ($match) use ($route) {
            $pattern = $route->wheres[$match[1]] ?? '';
            if (str_contains($pattern, '|') && preg_match('/^\(?([a-z-]+)\|/', $pattern, $first)) {
                return $first[1];
            }
            if ($match[1] === 'dispatch' || str_contains($pattern, '{8}')) {
                return '00000000-0000-4000-8000-000000000000';
            }
            if ($match[1] === 'type') {
                return 'students';
            }
            if ($match[1] === 'module') {
                return 'students';
            }

            return '999999';
        }, $route->uri());
    }

    public function test_every_registered_portal_action_and_token_endpoint_requires_authentication(): void
    {
        $rows = [];
        foreach ($this->protectedRoutes() as $route) {
            $this->assertContains('auth', $route->gatherMiddleware(), $route->uri());
            $method = $route->methods()[0];
            $response = $this->json($method, $this->path($route));
            $this->assertSame(401, $response->status(), $method.' '.$route->uri());
            $rows[] = ['method' => $method, 'uri' => $route->uri(), 'name' => $route->getName(), 'actor' => 'guest', 'expected' => 401, 'actual' => $response->status(), 'outcome' => 'pass'];
        }
        file_put_contents(sys_get_temp_dir().'/nasr-fullqa-guest-route-results.json', json_encode($rows, JSON_PRETTY_PRINT));
        $this->assertGreaterThan(250, count($rows));
    }

    public function test_an_authenticated_unprivileged_role_cannot_use_any_portal_action(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'unprivileged_auditor', 'status' => 'active']));
        $rows = [];
        foreach ($this->protectedRoutes() as $route) {
            if (! preg_match('~^(admin|teacher|student|parent|supervisor|finance|staff)(/|$)~', $route->uri())) {
                continue;
            }
            // These are independent authorization probes, not a burst/rate-limit scenario.
            $this->assertSame('array', config('cache.default'));
            Cache::flush();
            $response = $this->json($route->methods()[0], $this->path($route));
            $this->assertContains($response->status(), [403, 404], $route->uri().' accepted an unprivileged role');
            $rows[] = ['method' => $route->methods()[0], 'uri' => $route->uri(), 'actor' => 'active unprivileged role', 'expected' => '403 or absent-record 404', 'actual' => $response->status(), 'outcome' => 'pass'];
        }
        file_put_contents(sys_get_temp_dir().'/nasr-fullqa-unprivileged-route-results.json', json_encode($rows, JSON_PRETTY_PRINT));
    }

    public function test_inactive_sessions_are_denied_by_each_primary_portal(): void
    {
        foreach (['admin', 'teacher', 'student', 'parent', 'supervisor', 'staff'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role, 'status' => 'inactive']))
                ->get('/'.$role.'/dashboard')->assertForbidden();
        }
    }

    public function test_private_local_storage_cannot_be_read_without_a_signature(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('qa-private.txt', 'private-local-fixture');
        $this->get('/storage/qa-private.txt')->assertForbidden()->assertDontSee('private-local-fixture');
    }

    public function test_registered_controller_actions_exist_and_are_public(): void
    {
        foreach (Route::getRoutes() as $route) {
            $action = $route->getActionName();
            if ($action === 'Closure') {
                continue;
            }
            [$class, $method] = str_contains($action, '@') ? explode('@', $action, 2) : [$action, '__invoke'];
            $this->assertTrue(method_exists($class, $method), $route->uri().' '.$action);
            $this->assertTrue((new \ReflectionMethod($class, $method))->isPublic(), $action);
        }
    }
}
