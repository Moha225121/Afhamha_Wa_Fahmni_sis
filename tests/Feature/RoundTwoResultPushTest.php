<?php

namespace Tests\Feature;

use App\Jobs\SendFcmNotification;
use App\Models\Guardian;
use App\Models\User;
use App\Services\FcmNotificationService;
use App\Services\FcmService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesStudentEducationFixtures;
use Tests\TestCase;

class RoundTwoResultPushTest extends TestCase
{
    use CreatesStudentEducationFixtures, RefreshDatabase;

    public static function eligibility(): array
    {
        return ['student published' => ['student', 'current', true], 'parent published' => ['parent', 'current', true],
            'hidden' => ['student', 'hidden', false], 'old revision' => ['student', 'revision', false],
            'withdrawn student' => ['student', 'withdrawn', false], 'unlinked parent' => ['parent', 'unlinked', false]];
    }

    #[DataProvider('eligibility')]
    public function test_result_push_requires_current_publication_and_recipient_access(string $role, string $change, bool $send): void
    {
        Queue::fake();
        $fixture = $this->createStudentFixture('R2-RESULT-PUSH');
        $user = $fixture['user'];
        if ($role === 'parent') {
            $user = User::factory()->create(['role' => 'parent', 'status' => 'active']);
            $guardian = Guardian::create(['user_id' => $user->id, 'relationship' => 'parent', 'status' => 'active']);
            $guardian->students()->attach($fixture['student']->id);
        }
        $token = 'QA-result-push-device';
        $user->fcmDevices()->create(['token' => $token, 'token_hash' => hash('sha256', $token)]);
        $period = DB::table('exam_periods')->insertGetId([
            'name' => 'QA result', 'academic_year_id' => $fixture['classroom']->academic_year_id, 'term' => 'الأول',
            'exam_type' => 'شهري', 'status' => 'published', 'revision' => 1, 'published_at' => now()->subMinute(),
            'created_by' => $this->createAdmin()->id,
        ]);
        DB::table('result_publications')->insert(['exam_period_id' => $period, 'student_id' => $fixture['student']->id, 'payload' => '{}']);
        $payload = ['title' => 'QA result', 'body' => 'Synthetic', 'student_id' => $fixture['student']->id];
        app(FcmNotificationService::class)->dispatch($user, 'results:'.$period.':1', $payload);
        $event = DB::table('fcm_notification_events')->sole();
        if ($change === 'hidden') DB::table('exam_periods')->where('id', $period)->update(['status' => 'hidden']);
        if ($change === 'revision') DB::table('exam_periods')->where('id', $period)->update(['revision' => 2]);
        if ($change === 'withdrawn') DB::table('result_publications')->where('exam_period_id', $period)->delete();
        if ($change === 'unlinked') $user->guardian->students()->detach();
        $fcm = $this->mock(FcmService::class);
        if ($send) $fcm->shouldReceive('sendToUser')->once();
        else $fcm->shouldNotReceive('sendToUser');
        (new SendFcmNotification($user->id, $event->event_key, $event->id, $payload))->handle($fcm);
        $after = DB::table('fcm_notification_events')->where('id', $event->id)->first();
        if ($send) {
            $this->assertNotNull($after->sent_at);
            $this->assertNull($after->cancelled_at);
        } else {
            $this->assertNull($after->sent_at);
            $this->assertNotNull($after->cancelled_at);
            $this->assertSame('result_unavailable', $after->cancellation_reason);
        }
    }
}
