<?php

namespace Tests\Feature;

use App\Models\Message;
use App\Models\User;
use App\Services\SchoolChatService;
use Illuminate\Support\Carbon;
use App\Support\DisplayTimezone;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CloseoutDisplayTimezoneTest extends TestCase
{
    public static function settings(): array
    {
        return ['missing' => [null], 'empty' => [''], 'invalid' => ['Invalid/QA-Zone'], 'valid' => ['Africa/Tripoli']];
    }

    #[DataProvider('settings')]
    public function test_display_timezone_falls_back_safely_and_never_mutates_stored_instant(?string $zone): void
    {
        config(['app.display_timezone' => $zone]);
        $message = new Message(['body' => 'QA time', 'sender_id' => 9]);
        $message->id = 1;
        $message->created_at = Carbon::parse('2020-01-01 23:30:00', 'UTC');
        $message->setRelation('sender', new User(['name' => 'QA Sender']));
        $viewer = new User;
        $viewer->id = 9;
        $actual = app(SchoolChatService::class)->serialize($message, $viewer);
        $this->assertSame('01:30', $actual['time']);
        $this->assertSame('2020-01-02', $actual['day']);
        $this->assertSame('2020-01-01T23:30:00+00:00', $actual['created_at']);
        $this->assertSame('UTC', $message->created_at->timezoneName);
        $this->assertSame('UTC', config('app.timezone'));
    }

    public function test_configuration_cache_preserves_normalized_display_timezone_without_changing_app_clock(): void
    {
        $this->assertSame('Africa/Tripoli', config('app.display_timezone'));
        $this->assertSame('UTC', config('app.timezone'));
        $path = $this->app->getCachedConfigPath();
        $this->assertFileDoesNotExist($path);
        $values = config()->all();
        $values['app']['display_timezone'] = DisplayTimezone::normalize('Invalid/QA');
        file_put_contents($path, '<?php return '.var_export($values, true).';');
        try {
            $cached = new Application(base_path());
            (new LoadConfiguration)->bootstrap($cached);
            $this->assertTrue($cached->configurationIsCached());
            $this->assertSame('Africa/Tripoli', $cached['config']['app.display_timezone']);
            $this->assertSame('UTC', $cached['config']['app.timezone']);
        } finally {
            unlink($path);
        }
        $this->assertSame('UTC', date_default_timezone_get());
    }
}
