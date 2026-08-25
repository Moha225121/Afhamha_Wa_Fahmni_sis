<?php

namespace App\Providers;

use App\Contracts\SmartTutorGateway;
use App\Services\UnavailableSmartTutorGateway;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(SmartTutorGateway::class, UnavailableSmartTutorGateway::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
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
