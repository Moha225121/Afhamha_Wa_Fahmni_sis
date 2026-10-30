<?php

namespace Tests\Feature;

use App\Models\Teacher;
use App\Models\User;
use App\Services\LessonUploadService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class LessonUploadFailureResponseTest extends TestCase
{
    use RefreshDatabase;

    public function test_missing_video_runtime_rejects_new_video_before_creating_a_session_or_accepting_bytes(): void
    {
        Storage::fake('local');
        $teacher = User::factory()->create(['role' => 'teacher', 'status' => 'active']);
        Teacher::create(['user_id' => $teacher->id, 'status' => 'active']);
        $this->actingAs($teacher);
        config(['lesson_uploads.video.binary' => 'missing-video-runtime-'.Str::uuid()]);

        $this->postJson(route('teacher.lessons.uploads.start'), ['name' => 'sample.mp4', 'size' => 200 * 1024 * 1024])
            ->assertStatus(503)->assertJsonPath('code', 'UPLOAD_VIDEO_SERVICE_UNAVAILABLE')
            ->assertJsonPath('reason', 'executable_unavailable')->assertJsonPath('stage', 'start')
            ->assertJsonPath('retryable', false);
        $this->assertDatabaseCount('lesson_uploads', 0);
        $this->assertSame([], Storage::disk('local')->allDirectories());

        $this->postJson(route('teacher.lessons.uploads.start'), ['name' => 'sample.pdf', 'size' => 100])
            ->assertCreated();
        $this->assertDatabaseCount('lesson_uploads', 1);
    }

    public function test_existing_video_session_can_still_be_resumed_if_runtime_becomes_unavailable(): void
    {
        Storage::fake('local');
        $teacher = User::factory()->create(['role' => 'teacher', 'status' => 'active']);
        Teacher::create(['user_id' => $teacher->id, 'status' => 'active']);
        $this->actingAs($teacher);
        $payload = ['upload_id' => (string) Str::uuid(), 'name' => 'sample.mp4', 'size' => 100];
        $this->postJson(route('teacher.lessons.uploads.start'), $payload)->assertCreated();

        config(['lesson_uploads.video.binary' => 'missing-video-runtime-'.Str::uuid()]);
        $this->postJson(route('teacher.lessons.uploads.start'), $payload)->assertCreated()
            ->assertJsonPath('upload_id', $payload['upload_id'])->assertJsonPath('received_bytes', 0);
        $this->assertDatabaseCount('lesson_uploads', 1);
    }

    public static function failures(): array
    {
        return [
            ['Unable to create lesson upload root.', 503, 'UPLOAD_STORAGE_UNAVAILABLE'],
            ['Unable to lock lesson upload.', 503, 'UPLOAD_STORAGE_UNAVAILABLE'],
            ['Unexpected SQL password=secret /private/server/path', 500, 'UPLOAD_SERVER_ERROR'],
        ];
    }

    #[DataProvider('failures')]
    public function test_server_failures_are_safe_identifiable_and_not_automatically_retried(string $message, int $status, string $code): void
    {
        $teacher = User::factory()->create(['role' => 'teacher', 'status' => 'active']);
        Teacher::create(['user_id' => $teacher->id, 'status' => 'active']);
        $this->actingAs($teacher);
        $this->mock(LessonUploadService::class, fn ($mock) => $mock->shouldReceive('start')->once()->andThrow(new RuntimeException($message)));
        $response = $this->postJson(route('teacher.lessons.uploads.start'), ['name' => 'sample.mp4', 'size' => 1024])
            ->assertStatus($status)->assertJsonPath('code', $code)->assertJsonPath('stage', 'start')
            ->assertJsonPath('retryable', false)->assertDontSee('password')->assertDontSee('secret')->assertDontSee('/private/');
        $this->assertTrue(Str::isUuid($response->json('reference')));
    }

    public static function databaseStates(): array
    {
        return [
            ['42P01', 'UPLOAD_DATABASE_SCHEMA'], ['42703', 'UPLOAD_DATABASE_SCHEMA'],
            ['42S02', 'UPLOAD_DATABASE_SCHEMA'], ['42S22', 'UPLOAD_DATABASE_SCHEMA'],
            ['42501', 'UPLOAD_DATABASE_PERMISSION'],
        ];
    }

    #[DataProvider('databaseStates')]
    public function test_database_setup_errors_have_a_specific_safe_code(string $state, string $code): void
    {
        $teacher = User::factory()->create(['role' => 'teacher', 'status' => 'active']);
        Teacher::create(['user_id' => $teacher->id, 'status' => 'active']);
        $this->actingAs($teacher);
        $previous = new PDOException('private database details password=secret');
        $previous->errorInfo = [$state, 7, $previous->getMessage()];
        $failure = new QueryException('pgsql', 'select * from private_table', [], $previous);
        $this->mock(LessonUploadService::class, fn ($mock) => $mock->shouldReceive('start')->once()->andThrow($failure));
        $this->postJson(route('teacher.lessons.uploads.start'), ['name' => 'sample.mp4', 'size' => 1024])
            ->assertStatus(503)->assertJsonPath('code', $code)->assertJsonPath('retryable', false)
            ->assertDontSee('password')->assertDontSee('secret')->assertDontSee('private_table');
    }
}
