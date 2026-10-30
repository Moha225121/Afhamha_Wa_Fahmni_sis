<?php

namespace Tests\Feature;

use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class LessonUploadDeploymentRecoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_finishing_a_pending_upload_migration_recovers_session_creation_without_losing_existing_data(): void
    {
        Storage::fake('local');
        config(['app.debug' => false]);
        $teacher = User::factory()->create(['role' => 'teacher', 'status' => 'active']);
        Teacher::create(['user_id' => $teacher->id, 'status' => 'active']);
        $this->actingAs($teacher);
        $migration = '2026_10_24_000001_create_lesson_uploads_table';

        // Reproduce code deployed before its additive database migration finished.
        // TestCase only permits an isolated test database; no application data is used.
        Schema::drop('lesson_uploads');
        DB::table('migrations')->where('migration', $migration)->delete();
        $payload = ['name' => 'recovery.mp4', 'size' => 20736163];
        $this->postJson(route('teacher.lessons.uploads.start'), $payload)
            ->assertStatus(503)->assertJsonPath('code', 'UPLOAD_DATABASE_SCHEMA')
            ->assertJsonPath('retryable', false)->assertJsonPath('stage', 'start')
            ->assertJsonMissingPath('exception')->assertDontSee('SQLSTATE')->assertDontSee('select ');

        $this->artisan('migrate', [
            '--path' => 'database/migrations/'.$migration.'.php',
            '--force' => true,
        ])->assertSuccessful();

        $upload = $this->postJson(route('teacher.lessons.uploads.start'), $payload)
            ->assertCreated()->assertJsonPath('received_bytes', 0)->json();
        $this->assertDatabaseHas('users', ['id' => $teacher->id]);
        $this->assertDatabaseHas('teachers', ['user_id' => $teacher->id]);

        // A second deployment is a no-op for this migration and preserves sessions.
        $this->artisan('migrate', [
            '--path' => 'database/migrations/'.$migration.'.php',
            '--force' => true,
        ])->assertSuccessful();
        $this->getJson(route('teacher.lessons.uploads.status', $upload['upload_id']))
            ->assertOk()->assertJsonPath('status', 'uploading');
        $this->deleteJson(route('teacher.lessons.uploads.cancel', $upload['upload_id']))
            ->assertOk()->assertJsonPath('status', 'cancelled');
    }
}
