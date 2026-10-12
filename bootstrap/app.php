<?php

use App\Http\Middleware\EnsureUserHasPermission;
use App\Http\Middleware\EnsureUserIsAdmin;
use App\Http\Middleware\EnsureUserIsParent;
use App\Http\Middleware\EnsureUserIsStudent;
use App\Http\Middleware\EnsureUserIsTeacher;
use App\Http\Middleware\EnsureUserIsSupervisor;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Http\Exceptions\PostTooLargeException;

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
            'staff' => \App\Http\Middleware\EnsureUserIsStaff::class,
        ]);

        $middleware->redirectUsersTo(function (Request $request): string {
            $user = $request->user();

            return route(match (true) {
                $user?->isParent() => 'parent.dashboard',
                $user?->isStudent() => 'student.dashboard',
                $user?->isTeacher() => 'teacher.assignments.index',
                $user?->isSupervisor() => 'supervisor.dashboard',
                $user?->role === 'staff' => 'staff.dashboard',
                $user?->role === 'financial_officer' => 'finance.parent-notifications.create',
                default => 'admin.dashboard',
            });
        });
    })
    ->withExceptions(function (Exceptions $exceptions): void {
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
