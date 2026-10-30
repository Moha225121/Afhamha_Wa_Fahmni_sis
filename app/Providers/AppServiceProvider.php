<?php

namespace App\Providers;

use App\Contracts\SmartTutorGateway;
use App\Models\Student;
use App\Services\GeminiSmartTutorGateway;
use App\Services\ParentAnnouncementNotificationService;
use App\Services\ParentMessageScope;
use App\Services\ParentNotificationFeed;
use App\Services\ParentPortalContext;
use App\Services\StudentNotificationService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Kreait\Firebase\Factory;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(SmartTutorGateway::class, GeminiSmartTutorGateway::class);
        $this->app->extend(Factory::class, fn (Factory $factory) => filled(config('fcm.web.projectId'))
            ? $factory->withProjectId(config('fcm.web.projectId'))
            : $factory);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        View::composer('parent.layout', function ($view): void {
            $parent = request()->user();
            if ($parent?->isParent()) {
                $student = request()->filled('student') ? app(ParentMessageScope::class)->selected(request()) : null;
                $view->with('parentNotificationSummary', app(ParentAnnouncementNotificationService::class)->summary($parent, false, $student));
            }
        });
        View::composer('student.layout', function ($view): void {
            $student = request()->user()?->student;
            if ($student && request()->user()->isStudent()) {
                $view->with('studentNotificationSummary', app(StudentNotificationService::class)->summary($student));
            }
        });

        View::composer('*', function ($view): void {
            $settings = Schema::hasTable('settings') ? DB::table('settings')->pluck('value', 'key') : collect();
            $view->with('schoolBranding', [
                'platform_name' => 'افهمها وفهمني',
                'school_name' => $settings['school_name'] ?? 'مدرسة النسبية',
                'logo' => $settings['school_logo'] ?? null,
                'theme_color' => $settings['theme_color'] ?? '#008C95',
            ]);
        });
        View::composer(['parent.layout', 'parent.partials.child-switcher'], function ($view): void {
            $user = auth()->user();
            if ($user?->role !== 'parent' || ! $user->guardian) {
                return;
            }
            $context = app(ParentPortalContext::class);
            $children = $context->children($user->guardian);
            $data = $view->getData();
            $routeChild = $data['student'] ?? request()->route('student');
            $requested = $routeChild instanceof Student ? $routeChild->id : request()->query('student');
            $student = $context->selectedStudent($user->guardian, $children, $data['selectedStudent']->id ?? $requested);
            $countsKey = 'parent.message_unread.'.$user->id;
            if (! request()->attributes->has($countsKey)) {
                request()->attributes->set($countsKey, app(ParentMessageScope::class)->unreadCounts($user));
            }
            $view->with([
                'parentChildQuery' => $student ? ['student' => $student->id] : [],
                'childUnreadCounts' => request()->attributes->get($countsKey),
                'unreadNotifications' => app(ParentNotificationFeed::class)->forStudent($user, $student)->whereNull('read_at')->count(),
            ]);
            if ($view->name() === 'parent.partials.child-switcher') {
                $view->with(['children' => $children, 'selectedStudent' => $student]);
            }
        });
        RateLimiter::for('admin-notifications', fn (Request $request) => [
            Limit::perMinute(5)->by('admin-notifications:minute:'.$request->user()->id),
            Limit::perHour(30)->by('admin-notifications:hour:'.$request->user()->id),
        ]);
        RateLimiter::for('teacher-notification-preview', fn (Request $request) => Limit::perMinute(20)->by('teacher-preview:'.$request->user()->id));
        RateLimiter::for('teacher-notifications', fn (Request $request) => [
            Limit::perMinute(2)->by('teacher-notification:minute:'.$request->user()->id),
            Limit::perHour(10)->by('teacher-notification:hour:'.$request->user()->id),
        ]);

        RateLimiter::for('smart-tutor-conversations', function (Request $request): Limit {
            $maximum = max(1, (int) config('smart_tutor.rate_limits.conversations_per_minute', 10));

            return Limit::perMinute($maximum)
                ->by($this->rateLimitKey($request, 'conversations'))
                ->response(fn (Request $request, array $headers) => response(
                    'تم تجاوز الحد المؤقت لإنشاء المحادثات. يرجى المحاولة بعد قليل.',
                    429,
                    $headers,
                ));
        });

        RateLimiter::for('smart-tutor-messages', function (Request $request): Limit {
            $maximum = max(1, (int) config('smart_tutor.rate_limits.messages_per_minute', 20));

            return Limit::perMinute($maximum)
                ->by($this->rateLimitKey($request, 'messages'))
                ->response(fn (Request $request, array $headers) => response(
                    'تم تجاوز الحد المؤقت لرسائل المعلّم الذكي. يرجى المحاولة بعد قليل.',
                    429,
                    $headers,
                ));
        });
    }

    private function rateLimitKey(Request $request, string $scope): string
    {
        $actor = $request->user()?->getAuthIdentifier() ?: $request->ip();

        return "smart-tutor:{$scope}:{$actor}";
    }
}
