<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class QaTestIsolationTest extends TestCase
{
    public function test_application_uses_no_real_environment_file_and_keeps_database_queue_isolated(): void
    {
        $this->assertStringEndsWith('tests/Fixtures/no-environment', str_replace('\\', '/', $this->app->environmentPath()));
        $this->assertSame('.env.disabled-for-tests', $this->app->environmentFile());
        $this->assertSame(config('database.default'), config('queue.connections.database.connection'));
        $this->assertNull(config('services.firebase.credentials'));
        $this->assertNull(config('services.firebase.project_id'));
        $this->assertNull(config('services.web_push.private_key'));
        $this->assertSame('array', config('mail.default'));
    }

    public function test_unmocked_external_request_is_rejected_before_network_io(): void
    {
        $this->expectException(\Illuminate\Http\Client\StrayRequestException::class);
        Http::get('https://example.test/qa-no-network');
    }
}
