<?php

namespace Tests\Feature;

use App\Models\TutorConversation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Concerns\CreatesStudentEducationFixtures;
use Tests\TestCase;

class TeacherTutorMigrationTest extends TestCase
{
    use CreatesStudentEducationFixtures;

    private ?string $postgresSchema = null;

    protected function setUp(): void
    {
        parent::setUp();
        // DDL must run outside a test transaction. PostgreSQL persists across
        // application instances, so use an exclusively created schema per test.
        if (DB::getDriverName() === 'pgsql') {
            $schema = 'qa_tutor_'.bin2hex(random_bytes(12));
            DB::statement('CREATE SCHEMA "'.$schema.'"');
            $this->postgresSchema = $schema;
            config(['database.connections.pgsql.search_path' => $schema]);
            DB::purge('pgsql');
        }
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
    }

    protected function tearDown(): void
    {
        try {
            if ($this->postgresSchema !== null) {
                $schema = $this->postgresSchema;
                if (! preg_match('/\Aqa_tutor_[a-f0-9]{24}\z/', $schema)) {
                    throw new RuntimeException('Invalid isolated test schema.');
                }
                config(['database.connections.pgsql.search_path' => 'public']);
                DB::purge('pgsql');
                DB::statement('DROP SCHEMA "'.$schema.'" CASCADE');
            }
        } finally {
            parent::tearDown();
        }
    }

    public function test_migration_preserves_legacy_students_and_messages_and_refuses_destructive_down(): void
    {
        $migration = require database_path('migrations/2026_11_08_000002_add_teacher_owner_to_tutor_conversations.php');
        $migration->down();
        $student = $this->createStudentFixture('TUTOR-UPGRADE');
        $id = DB::table('tutor_conversations')->insertGetId(['student_id' => $student['student']->id, 'title' => 'محادثة قديمة', 'created_at' => now(), 'updated_at' => now()]);
        $messageId = DB::table('tutor_messages')->insertGetId(['tutor_conversation_id' => $id, 'role' => 'user', 'content' => 'رسالة قديمة', 'delivery_status' => 'failed', 'created_at' => now(), 'updated_at' => now()]);
        $legacy = DB::table('tutor_conversations')->find($id);
        DB::table('migrations')->where('migration', '2026_11_08_000002_add_teacher_owner_to_tutor_conversations')->delete();
        $this->artisan('migrate', [
            '--path' => 'database/migrations/2026_11_08_000002_add_teacher_owner_to_tutor_conversations.php',
            '--force' => true,
        ])->assertExitCode(0);
        $upgraded = DB::table('tutor_conversations')->find($id);
        foreach ((array) $legacy as $column => $value) $this->assertSame($value, $upgraded->$column);
        $this->assertNull($upgraded->teacher_user_id);
        $this->assertNull($upgraded->creation_request_id);
        $this->assertDatabaseHas('tutor_messages', ['id' => $messageId, 'content' => 'رسالة قديمة']);
        $migration->down();
        $this->assertFalse(Schema::hasColumn('tutor_conversations', 'teacher_user_id'));
        $this->assertDatabaseHas('tutor_messages', ['id' => $messageId]);
        $migration->up();
        $user = User::factory()->create(['role' => 'teacher', 'status' => 'active']);
        $conversation = TutorConversation::create(['teacher_user_id' => $user->id, 'creation_request_id' => (string) Str::uuid(), 'title' => 'محادثة معلم']);
        try {
            $migration->down();
            $this->fail('Rollback must refuse to discard teacher ownership or conversations.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('teacher conversations', $exception->getMessage());
        }
        $this->assertDatabaseHas('tutor_conversations', ['id' => $conversation->id, 'teacher_user_id' => $conversation->teacher_user_id]);
        $this->assertDatabaseHas('tutor_messages', ['id' => $messageId]);
    }

    public function test_sqlite_migration_refuses_transaction_before_altering_existing_schema(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('SQLite-specific DDL transaction guard.');
        }
        $migration = require database_path('migrations/2026_11_08_000002_add_teacher_owner_to_tutor_conversations.php');
        $migration->down();
        DB::beginTransaction();
        try {
            $migration->up();
            $this->fail('SQLite transaction must be rejected before table rebuild.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('outside a transaction', $exception->getMessage());
            $this->assertFalse(Schema::hasColumn('tutor_conversations', 'teacher_user_id'));
        } finally {
            DB::rollBack();
        }
        $migration->up();
        $this->assertTrue(Schema::hasColumn('tutor_conversations', 'teacher_user_id'));
    }
}
