<?php

use App\Http\Middleware\EnsureUserHasPermission;
use App\Http\Middleware\EnsureUserIsAdmin;
use App\Http\Middleware\EnsureUserIsParent;
use App\Http\Middleware\EnsureUserIsStaff;
use App\Http\Middleware\EnsureUserIsStudent;
use App\Http\Middleware\EnsureUserIsSupervisor;
use App\Http\Middleware\EnsureUserIsTeacher;
use App\Http\Middleware\NoStoreForSensitivePages;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withCommands([__DIR__.'/../app/Console/Commands'])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'admin' => EnsureUserIsAdmin::class,
            'parent' => EnsureUserIsParent::class,
            'student' => EnsureUserIsStudent::class,
            'teacher' => EnsureUserIsTeacher::class,
            'supervisor' => EnsureUserIsSupervisor::class,
            'permission' => EnsureUserHasPermission::class,
            'staff' => EnsureUserIsStaff::class,
            'no-cache' => NoStoreForSensitivePages::class,
        ]);

        $middleware->redirectUsersTo(function (Request $request): string {
            $user = $request->user();

            return route(match (true) {
                $user?->isParent() => 'parent.dashboard',
                $user?->isStudent() => 'student.dashboard',
                $user?->isTeacher() => 'teacher.assignments.index',
                $user?->isSupervisor() => 'supervisor.dashboard',
                $user?->role === 'staff' => 'staff.dashboard',
                $user?->role === 'financial_officer' => 'finance.index',
                default => 'admin.dashboard',
            });
        });
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->respond(function (Response $response) {
            $request = request();
            if ($response->getStatusCode() >= 400 && $request->is('teacher/lessons/uploads*')) {
                Log::warning('lesson_upload.http_failed', [
                    'stage' => $request->route()?->getAction('upload_stage'),
                    'http_status' => $response->getStatusCode(),
                    'request_bytes' => (int) $request->header('Content-Length', 0),
                    'duration_ms' => defined('LARAVEL_START') ? round((microtime(true) - LARAVEL_START) * 1000) : null,
                ]);
            }

            return $response;
        });
        $exceptions->render(function (PostTooLargeException $exception, Request $request) {
            $message = 'حجم الملفات المرفقة أكبر من الحد المسموح. قلل حجم الملفات أو أرسل عددًا أقل منها.';
            if ($request->expectsJson()) {
                return response()->json(['message' => $message, 'errors' => ['attachments' => [$message]]], 413);
            }

            $referer = $request->headers->get('referer');
            $refererHost = is_string($referer) ? parse_url($referer, PHP_URL_HOST) : null;
            $refererPath = is_string($referer) ? parse_url($referer, PHP_URL_PATH) : null;
            if ($refererHost === $request->getHost()
                && is_string($refererPath)
                && (str_starts_with($refererPath, '/teacher/lessons') || str_starts_with($refererPath, '/teacher/assignments'))) {
                return redirect()->to($referer)->withErrors(['attachments' => $message]);
            }

            $dashboardRoute = match (true) {
                $request->user()?->isStudent() => 'student.dashboard',
                $request->user()?->isParent() => 'parent.dashboard',
                $request->user()?->isTeacher() => 'teacher.dashboard',
                default => 'admin.dashboard',
            };

            return redirect()->route($dashboardRoute)->withErrors(['attachments' => $message]);
        });
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
