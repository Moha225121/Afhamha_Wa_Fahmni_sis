<?php

namespace App\Providers;

use App\Contracts\SmartTutorGateway;
use App\Services\GeminiSmartTutorGateway;
use App\Support\SchoolBranding;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(SmartTutorGateway::class, GeminiSmartTutorGateway::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        View::composer('parent.layout', function ($view): void {
            $parent = request()->user();
            if ($parent?->isParent()) {
                $view->with('parentNotificationSummary', app(\App\Services\ParentAnnouncementNotificationService::class)->summary($parent));
            }
        });

        Paginator::defaultView('vendor.pagination.nasbia');
        Paginator::defaultSimpleView('vendor.pagination.nasbia-simple');

        View::composer('*', function ($view): void {
            $settings = Schema::hasTable('settings') ? DB::table('settings')->pluck('value', 'key') : collect();
            $view->with('schoolBranding', [
                'platform_name' => 'مدرسة النسبية',
                'school_name' => $settings['school_name'] ?? 'مدرسة النسبية',
                'logo' => SchoolBranding::LOGO,
                'theme_color' => SchoolBranding::NAVY,
            ]);
        });
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
