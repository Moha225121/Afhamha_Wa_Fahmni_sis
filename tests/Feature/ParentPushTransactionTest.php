<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ParentNotificationService;
use App\Services\PushNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class ParentPushTransactionTest extends TestCase
{
    use RefreshDatabase;

    public function test_rolled_back_notifications_never_contact_a_push_transport(): void
    {
        $parent = User::factory()->create(['role' => 'parent', 'status' => 'active']);
        $this->mock(PushNotificationService::class)->shouldNotReceive('send');
        try {
            DB::transaction(function () use ($parent): void {
                $result = app(ParentNotificationService::class)->send($parent, ['title' => 'QA rollback', 'body' => 'Synthetic only']);
                $this->assertTrue($result['deferred']);
                throw new RuntimeException('QA intentional rollback');
            });
        } catch (RuntimeException $exception) {
            $this->assertSame('QA intentional rollback', $exception->getMessage());
        }
        $this->assertSame(0, $parent->notifications()->count());
    }

    public function test_transport_runs_after_commit_and_its_failure_does_not_lose_the_inbox(): void
    {
        $parent = User::factory()->create(['role' => 'parent', 'status' => 'active']);
        $committed = false;
        $this->mock(PushNotificationService::class)->shouldReceive('send')->once()
            ->andReturnUsing(function () use ($parent, &$committed): array {
                $this->assertTrue($committed);
                $this->assertSame(1, $parent->notifications()->count());
                throw new RuntimeException('QA transport unavailable');
            });
        DB::transaction(function () use ($parent, &$committed): void {
            app(ParentNotificationService::class)->send($parent, ['title' => 'QA committed', 'body' => 'Synthetic only']);
            $committed = true;
        });
        $this->assertSame(1, $parent->notifications()->count());
    }
}
